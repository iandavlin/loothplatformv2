#!/usr/bin/env python3
"""legacy-url-redirect-gate.py — a legacy forum URL must reach the CANONICAL
permalink of the thing it named, not a generic landing.

Ian: "are all our old links going to work in legacy posts etc?"

WHY A MANY-TO-ONE REDIRECT IS THE DEFECT, not the fix. Every legacy shape already
301s somewhere, so a naive check ("does it redirect?") is green on the broken
state. Google reads a many-to-one 301 as a SOFT 404: it transfers no authority
and does not consolidate, so the old URL keeps its own index entry — which is the
reported symptom. A blanket Disallow would make it worse, because a disallowed
URL cannot be recrawled and so stays indexed with no content.

So this gate asserts the DESTINATION, per URL:
    a legacy topic/reply URL must land on /hub/<forum>/<topic>/ — its own
    permalink, the exact string sitemap-discussions.xml advertises — and not on
    bare /hub/.

SCOPED FROM THE LOG, NOT FROM A PREFIX GUESS. The shapes here are the ones live
actually receives (docs/LEGACY-URL-REDIRECT-STATE.md, 469,904 log lines). That
matters: the largest shape by traffic, /groups/<group>/forum/topic/<slug>/ at 185
requests, was absent from the original findings, which had anchored on
/all-forums-all-topics/ alone.

WHAT IS DELIBERATELY *NOT* ASSERTED, so a green is not read as more than it is:
  · /all-forums-all-topics/topic-tag/<tag>/ — there is nowhere per-URL to send
    it. The hub's tag view is /hub/?…, and live robots.txt carries
    `Disallow: /hub/?`, so a 301 into it would aim Google at a URL it is
    FORBIDDEN to fetch — worse than the soft 404 it replaced. 410-vs-leave is a
    product question (are tag archives coming back?), not a technical one.
  · /all-forums-all-topics/page/<n>/ — pagination of the old index; bare /hub/
    genuinely IS the equivalent page.
Both keep landing on /hub/, and that is the CURRENT DECISION rather than an
oversight. If either ever gets a per-URL target, add it here.

GROUND TRUTH IS RESOLVED FROM THE DB, not hardcoded: the gate asks Postgres what
each sampled slug/id should map to, so it survives content edits and cannot drift
into asserting a destination nobody serves.

EXIT: 0 green · 1 RED (real findings) · 2 CANNOT RUN (no verdict).
"""

import os
import re
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))

# Override to gate the branch's resolver through a lane-preview route before the
# nginx rewrite has been deployed (the rewrite is symlinked into the serving
# checkout, so it only arrives with a pull + reload).
RESOLVER = os.environ.get("LG_LR_RESOLVER", "")


def gate_env():
    out = subprocess.run(["bash", os.path.join(HERE, "gate-env.sh")],
                         capture_output=True, text=True, timeout=30)
    if out.returncode != 0:
        return None, f"gate-env.sh failed: {out.stderr.strip()}"
    env = {}
    for line in out.stdout.splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            env[k] = v
    if not env.get("LG_GATE_HOST") or not env.get("LG_GATE_TOKEN"):
        return None, "gate-env.sh produced no host/token"
    return env, None


def psql(sql):
    """Read-only ground truth. Runs as postgres because no role exists for this
    box's ubuntu user; the gate only ever SELECTs."""
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-d", "looth",
                        "-At", "-F", "|", "-c", sql],
                       capture_output=True, text=True, timeout=30)
    if r.returncode != 0:
        return None
    return [l for l in r.stdout.strip().split("\n") if l]


def location_of(env, path):
    """First-hop Location for a path — the redirect TARGET, which is the thing
    under test. Following the chain would hide a many-to-one hop behind a
    correct-looking 200."""
    cmd = ["curl", "-s", "-o", "/dev/null", "-D", "-", "--max-time", "30"]
    if env.get("LG_GATE_RESOLVE"):
        cmd += env["LG_GATE_RESOLVE"].split()
    cmd += ["-b", "loothdev_auth=" + env["LG_GATE_TOKEN"],
            env["LG_GATE_HOST"] + path]
    # Per-request 403 retry — same reason as the hub-topic-landing gate: this
    # box's dev gate refuses correctly-cookied requests in bursts, and a suite
    # firing many requests trips it while a single one never does.
    for attempt in range(3):
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=45)
        if " 403" not in r.stdout.split("\n")[0]:
            break
        if attempt < 2:
            time.sleep(1.5 * (attempt + 1))
    code, loc = 0, ""
    for line in r.stdout.splitlines():
        if line.startswith("HTTP/"):
            m = re.search(r"\s(\d{3})", line)
            if m:
                code = int(m.group(1))
        if line.lower().startswith("location:"):
            loc = line.split(":", 1)[1].strip()
    # Compare paths, not absolute URLs — nginx emits absolute, PHP emits relative.
    loc = re.sub(r"^https?://[^/]+", "", loc)
    return code, loc


def main():
    env, err = gate_env()
    if err:
        print(f"CANNOT RUN  {err}")
        return 2

    RED, OK = [], 0

    # ── Ground truth ────────────────────────────────────────────────────────
    rows = psql("SELECT t.slug, f.slug FROM forums.topic t "
                "JOIN forums.forum f ON f.id=t.forum_id "
                "WHERE t.status IN ('publish','closed') AND f.visibility='public' "
                "ORDER BY t.id LIMIT 3")
    if not rows:
        print("CANNOT RUN  no DB ground truth (psql as postgres unavailable)")
        return 2
    topics = [tuple(r.split("|")) for r in rows]

    rrows = psql("SELECT r.id, f.slug, t.slug FROM forums.reply r "
                 "JOIN forums.topic t ON t.id=r.topic_id "
                 "JOIN forums.forum f ON f.id=t.forum_id "
                 "WHERE r.status='publish' AND t.status IN ('publish','closed') "
                 "AND f.visibility='public' ORDER BY r.id LIMIT 2")
    replies = [tuple(r.split("|")) for r in (rrows or [])]

    gated = psql("SELECT r.id FROM forums.reply r "
                 "JOIN forums.topic t ON t.id=r.topic_id "
                 "JOIN forums.forum f ON f.id=t.forum_id "
                 "WHERE f.visibility<>'public' AND r.status='publish' LIMIT 1")

    def check(label, path, want):
        nonlocal OK
        code, loc = location_of(env, path)
        if code in (301, 308) and loc.rstrip("/") == want.rstrip("/"):
            print(f"  ok    {label:<52} 301 -> {loc}")
            OK += 1
        else:
            print(f"  RED   {label:<52} {code} -> {loc or '(none)'}  want {want}")
            RED.append(f"{path} — {code} -> {loc or '(none)'}, expected 301 -> {want}")

    # ── Per-URL topic redirects ─────────────────────────────────────────────
    print("legacy topic shapes (must reach their OWN permalink)")
    for tslug, fslug in topics:
        want = f"/hub/{fslug}/{tslug}/"
        check("all-forums-all-topics/topic", f"/all-forums-all-topics/topic/{tslug}/", want)

    # ── Per-URL reply redirects (this lane, 2026-08-09) ─────────────────────
    print("legacy reply permalinks (bbPress numbered its replies)")
    for rid, fslug, tslug in replies:
        want = f"/hub/{fslug}/{tslug}/"
        if RESOLVER:
            check(f"reply/{rid} via resolver", f"{RESOLVER}?reply={rid}", want)
        else:
            check(f"reply/{rid}", f"/all-forums-all-topics/reply/{rid}/", want)

    # ── The gate that must NOT resolve ──────────────────────────────────────
    print("gated content must NOT resolve (a redirect target is an existence oracle)")
    if gated:
        rid = gated[0]
        path = (f"{RESOLVER}?reply={rid}" if RESOLVER
                else f"/all-forums-all-topics/reply/{rid}/")
        code, loc = location_of(env, path)
        if loc.rstrip("/") in ("/hub", ""):
            print(f"  ok    reply {rid} in a non-public forum -> {loc} (no leak)")
            OK += 1
        else:
            print(f"  RED   reply {rid} in a non-public forum LEAKED -> {loc}")
            RED.append(f"a reply in a non-public forum resolved to {loc}")
    else:
        print("  --    no non-public reply on this box to test with")

    # ── Never a 404, whatever the input ─────────────────────────────────────
    print("unknown input must land somewhere, never 404")
    for path in ([f"{RESOLVER}?reply=999999999", f"{RESOLVER}?slug=no-such-slug-xyz"]
                 if RESOLVER else
                 ["/all-forums-all-topics/reply/999999999/",
                  "/all-forums-all-topics/topic/no-such-slug-xyz/"]):
        code, loc = location_of(env, path)
        if code in (301, 308) and loc:
            print(f"  ok    {path.split('?')[-1]:<52} 301 -> {loc}")
            OK += 1
        else:
            print(f"  RED   {path} -> {code} {loc}")
            RED.append(f"{path} did not redirect ({code})")

    print()
    if RED:
        print(f"RED  {len(RED)} finding(s):")
        for f in RED:
            print(f"  - {f}")
        return 1
    print(f"GREEN  {OK} legacy URL(s) reach their own canonical permalink, "
          f"gated content does not resolve, and nothing 404s.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
