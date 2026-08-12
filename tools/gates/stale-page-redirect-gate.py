#!/usr/bin/env python3
"""stale-page-redirect-gate.py — retired pages and dead Google sitelinks must
301 to the LIVE thing they stand for, and that destination must actually answer.

Ian's rulings, 2026-08-11 (docs/IAN-RULINGS-2026-08-11.md item 5):
    /shop-organisation/  -> /hub/shop-organisation/   (Google lists it; it 404s)
    /featured-content/   -> /hub/
    /merch/              -> loothtool.com
    /shop/               LEAVE — alive, linked from homepage/front/hub/events

WHY PER-URL AND NOT A BLANKET REDIRECT: Google reads a many-to-one 301 as a soft
404 — no authority transferred, no consolidation, and the old URL keeps its own
index entry. That is the same defect this lane fixed for the legacy forum URLs.

⚠️ THE PART THAT IS EASY TO SHIP BROKEN, and the reason this gate exists rather
than a one-line conf being "obviously fine": /merch/ points at a domain WE DO NOT
CONTROL. Measured 2026-08-12 before shipping it:

    https://loothtool.com/       200
    https://www.loothtool.com/   522   <-- Cloudflare origin timeout, DEAD
    http://loothtool.com/        301 -> https apex -> 200

So the target is the APEX. Had this shipped against the www form — the form most
people would type into a conf without checking — every visitor and Googlebot
following that 301 would have landed in a 522. The gate therefore asserts the
destination RESPONDS, not merely that we emit a Location header. An external
target can also rot later without anyone touching our code, which a conf comment
cannot catch and this can.

/shop/ is asserted to still be 200 and NOT redirected — it is alive and actively
maintained, and the standing risk here is someone "tidying" it away with its
retired-looking neighbours.

EXIT: 0 green · 1 RED (real findings) · 2 CANNOT RUN (no verdict).
"""

import os
import re
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))

# (path, expected Location, follow-and-check-the-destination?)
EXPECT = [
    ("/shop-organisation/", "/hub/shop-organisation/", True),
    ("/shop-organisation",  "/hub/shop-organisation/", False),
    ("/featured-content/",  "/hub/",                   True),
    ("/featured-content",   "/hub/",                   False),
    ("/merch/",             "https://loothtool.com/",  True),
    ("/merch",              "https://loothtool.com/",  False),
]
# Alive — must NOT redirect.
KEEP_200 = ["/shop/"]


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


# ── PACING, not just retrying ────────────────────────────────────────────────
# This box's dev gate refuses correctly-cookied requests in BURSTS. Measured
# repeatedly: a single request always succeeds, `auth=1` at /gatetest, the token
# is byte-identical to the snippet nginx reads — but a full run's ~20 requests
# over 170-200KB pages trips it, and retries alone only fight the symptom while
# still arriving as a burst. A small gap between requests addresses the cause,
# and on a 2-core box it is the polite thing regardless.
_LAST = [0.0]
_GAP = float(os.environ.get("LG_GATE_PACE", "0.45"))


def _pace():
    now = time.monotonic()
    wait = _GAP - (now - _LAST[0])
    if wait > 0:
        time.sleep(wait)
    _LAST[0] = time.monotonic()


def head(env, path):
    """First hop only — the redirect TARGET is what is under test. Following the
    chain would hide a many-to-one hop behind a correct-looking 200. Retries a
    403: this box's dev gate refuses correctly-cookied requests in bursts."""
    cmd = ["curl", "-s", "-o", "/dev/null", "-D", "-", "--max-time", "30"]
    if env.get("LG_GATE_RESOLVE"):
        cmd += env["LG_GATE_RESOLVE"].split()
    cmd += ["-b", "loothdev_auth=" + env["LG_GATE_TOKEN"], env["LG_GATE_HOST"] + path]
    out = ""
    for attempt in range(3):
        _pace()
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=45)
        out = r.stdout
        if " 403" not in out.split("\n")[0]:
            break
        if attempt < 2:
            time.sleep(1.5 * (attempt + 1))
    code, loc = 0, ""
    for line in out.splitlines():
        if line.startswith("HTTP/"):
            m = re.search(r"\s(\d{3})", line)
            if m:
                code = int(m.group(1))
        if line.lower().startswith("location:"):
            loc = line.split(":", 1)[1].strip()
    return code, loc


def destination_answers(url):
    """Does the redirect target actually respond? For an EXTERNAL target this is
    the whole point — we do not control it, and a conf comment cannot notice it
    going dark."""
    r = subprocess.run(["curl", "-s", "-o", "/dev/null", "--max-time", "25",
                        "-w", "%{http_code}", url],
                       capture_output=True, text=True, timeout=40)
    try:
        return int(r.stdout.strip() or 0)
    except ValueError:
        return 0


def main():
    env, err = gate_env()
    if err:
        print(f"CANNOT RUN  {err}")
        return 2

    # Liveness: an absence/redirect assertion on a dead box passes vacuously.
    code, _ = head(env, "/hub/")
    if code != 200:
        print(f"CANNOT RUN  /hub/ did not serve (HTTP {code}) — the box is not "
              f"in a state where redirect assertions mean anything")
        return 2
    print("liveness    /hub/ serves 200")

    RED, ok = [], 0

    for path, want, check_dest in EXPECT:
        code, loc = head(env, path)
        # NORMALISE BOTH SIDES TO ABSOLUTE before comparing. nginx emits an
        # ABSOLUTE Location for an internal redirect ("https://host/hub/") while
        # the expectation here is written as a path ("/hub/") — and an external
        # target is absolute on both sides. The first cut compared an absolute
        # actual against a relative expected and reported /featured-content/ as
        # broken while it was redirecting perfectly. Resolve, then compare.
        def _abs(u):
            return u if u.startswith("http") else env["LG_GATE_HOST"].rstrip("/") + u
        if code in (301, 308) and _abs(loc).rstrip("/") == _abs(want).rstrip("/"):
            print(f"  ok    {path:<22} 301 -> {loc}")
            ok += 1
        else:
            print(f"  RED   {path:<22} {code} -> {loc or '(none)'}   want 301 -> {want}")
            RED.append(f"{path} — {code} -> {loc or '(none)'}, expected 301 -> {want}")
            continue

        if check_dest:
            if want.startswith("http"):
                dcode = destination_answers(want)
                label = f"external destination {want}"
            else:
                dcode, _ = head(env, want)
                label = f"destination {want}"
            if dcode == 200:
                print(f"        └─ {label} answers 200")
                ok += 1
            else:
                print(f"  RED   └─ {label} answered {dcode} — the 301 would send "
                      f"visitors and Googlebot into a dead end")
                RED.append(f"{path} redirects to {want}, which answered {dcode}")

    for path in KEEP_200:
        code, loc = head(env, path)
        if code == 200:
            print(f"  ok    {path:<22} 200, not redirected (alive — leave it)")
            ok += 1
        else:
            print(f"  RED   {path:<22} {code} -> {loc or '(none)'}  — this page is "
                  f"ALIVE and linked from the homepage/front/hub/events")
            RED.append(f"{path} is alive but returned {code} -> {loc}")

    print()
    if RED:
        print(f"RED  {len(RED)} finding(s):")
        for f in RED:
            print(f"  - {f}")
        return 1
    print(f"GREEN  {ok} check(s): retired pages 301 per-URL to a destination that "
          f"answers, and /shop/ is left alone.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
