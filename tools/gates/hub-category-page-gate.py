#!/usr/bin/env python3
"""hub-category-page-gate.py — /hub/<category>/ must look like THE HUB and tell
Google which URL it is.

Ian, 2026-08-11: the category pages were never rebuilt. They still render the
legacy forum-style view, carry no canonical and no robots meta, and Google lists
them.

ONE PREMISE CORRECTED BEFORE THIS WAS WRITTEN, because it changes what to assert.
The category page is NOT a wholesale legacy view — it already renders the new
feed cards. What it also renders, and neither /hub/ nor a topic landing does, is
the legacy LEFT CATEGORY TREE. Measured on dev2:

    /hub/            nav-tree=0  hub-fmodal-page=1  hub-rail=231  zones=1
    /hub/general/    nav-tree=1  hub-fmodal-page=0  hub-rail=0    zones=0
    /hub/<f>/<topic>/ nav-tree=0 (the landing already takes the unscoped branch)

So "category page" and "legacy rail" are one condition: _chrome.php renders the
legacy nav only when $GLOBALS['__bb_hub_rail'] is empty, and _feed.php sets that
rail ONLY on the unscoped branch.

PRESENCE *AND* ABSENCE, DELIBERATELY. "no nav-tree" alone is satisfied by a blank
page, a 500, or a redirect — the classic vacuous green. Every rail check here
pairs "the legacy tree is gone" with "the hub rail is actually there", so the
only way to pass is to render the right thing.

THE TARGET SHAPE, per Ian's ruling of 2026-08-12 (IAN-RULINGS items 7-8):
a category page is a GOOGLE DOOR. It exists so Google can rank a topic area —
"There's currently no nav to the category page... We can already filter for the
categories in the hub page", and that stays true. So it is a THIRD shape, not
either of the two that exist today:

    legacy tree   NO  — it is member nav, and it is what is being replaced
    hub rail      NO  — adding it would ADD member nav, which the ruling forbids
    hub cards     YES — "rebuilt in the hub look"
    content items YES — ruling 8, option A: discussions + related content mixed

That middle line is the one worth writing down, because the obvious
implementation — route the page through the hub's existing category filter — hands
it the rail for free and would sail past a gate that only checked "is the legacy
tree gone". Absence of the OLD nav is not the goal; absence of ANY member nav is.

TWO HALVES WITH DIFFERENT LIFECYCLES, and they are gated differently on purpose:

  A. CANONICAL — not member-visible, so it ships unflagged exactly as the topic
     landing's did. Asserted unconditionally: RED until it lands.
  B. THE RAIL — member-visible, so it ships behind LG_HUB_CATEGORY_RAIL,
     defaulted OFF. The gate READS which rail is being served and reports OFF as
     a state, not a finding, so an OFF default does not redden every other lane.
     LG_HC_REQUIRE_RAIL=1 demands the new rail — the red-first lever now, and the
     switch to throw when the default flips.

EXIT: 0 green · 1 RED (real findings) · 2 CANNOT RUN (no verdict).
"""

import os
import re
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
SAMPLE = int(os.environ.get("LG_HC_SAMPLE", "4"))
REQUIRE_RAIL = os.environ.get("LG_HC_REQUIRE_RAIL", "0") == "1"
PREFIX = os.environ.get("LG_HC_PREFIX", "/hub").rstrip("/")


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


def fetch(env, path):
    """Anonymous GET behind the edge-bypass pin. Retries a 403 per request: this
    box's dev gate refuses correctly-cookied requests in BURSTS (a single request
    always succeeds; a full suite trips it), so without this a whole run reports
    CANNOT RUN for a reason that has nothing to do with the code under test."""
    cmd = ["curl", "-s", "--max-time", "45"]
    if env.get("LG_GATE_RESOLVE"):
        cmd += env["LG_GATE_RESOLVE"].split()
    cmd += ["-b", "loothdev_auth=" + env["LG_GATE_TOKEN"],
            "-w", "\n__HTTP__%{http_code}", env["LG_GATE_HOST"] + path]
    body, code = "", 0
    for attempt in range(3):
        try:
            _pace()
            r = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
        except Exception:                                     # noqa: BLE001
            return "", 0
        body = r.stdout
        m = re.search(r"\n__HTTP__(\d+)$", body)
        code = int(m.group(1)) if m else 0
        if m:
            body = body[: m.start()]
        if code != 403:
            break
        if attempt < 2:
            time.sleep(1.5 * (attempt + 1))
    return body, code


def psql(sql):
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-d", "looth",
                        "-At", "-F", "|", "-c", sql],
                       capture_output=True, text=True, timeout=30)
    if r.returncode != 0:
        return None
    return [l for l in r.stdout.strip().split("\n") if l]


def main():
    env, err = gate_env()
    if err:
        print(f"CANNOT RUN  {err}")
        return 2

    # ── Liveness first: a presence check on a dead box is vacuously red, and an
    #    absence check on one is vacuously GREEN, which is worse.
    # Bounded liveness retry, same as the hub-topic-landing gate. fetch()'s own
    # per-request 403 retry (~4.5s) is not always enough: this box's dev gate
    # refuses in WINDOWS that can outlast it, and the liveness probe is the first
    # request a run makes — so a window here costs the whole run for a reason
    # unrelated to the code under test. A run that needed a retry SAYS SO.
    hub, code, tries = "", 0, 0
    for tries in range(1, 6):
        hub, code = fetch(env, PREFIX + "/")
        if code == 200 and "feed-card" in hub:
            break
        if tries < 5:
            time.sleep(3 * tries)          # 3+6+9+12 = up to 30s of window
    if code != 200 or "feed-card" not in hub:
        print(f"CANNOT RUN  {PREFIX}/ did not serve a feed after {tries} "
              f"attempt(s) (HTTP {code}, {len(hub)}b)")
        return 2
    if tries > 1:
        print(f"liveness    NOTE: needed {tries} attempts — the dev gate blipped")
    if 'class="nav-tree"' in hub:
        print(f"CANNOT RUN  {PREFIX}/ itself renders the legacy nav-tree — the "
              f"control this gate measures categories against is not valid")
        return 2
    print(f"liveness    {PREFIX}/ serves the hub rail and no legacy tree "
          f"(the control)")

    cats = psql("SELECT slug FROM forums.forum WHERE visibility='public' "
                "AND slug <> '' ORDER BY id LIMIT %d" % SAMPLE)
    if not cats:
        print("CANNOT RUN  no public categories from the DB")
        return 2
    hidden = psql("SELECT slug FROM forums.forum WHERE visibility<>'public' LIMIT 1")

    findings, states, ok = [], [], 0

    for slug in cats:
        path = f"{PREFIX}/{slug}/"
        page, code = fetch(env, path)
        if code != 200:
            findings.append(f"{path} — HTTP {code}, a public category must serve 200")
            print(f"  RED   {path:<44} HTTP {code}")
            continue

        legacy = 'class="nav-tree"' in page
        railed = ("hub-rail" in page or "hub-frail" in page
                  or "hub-chipbar" in page)
        topics = len(re.findall(r"feed-card--topic", page))
        content = len(re.findall(r"feed-card--content", page))
        # DOOR = the shape Ian ruled: hub cards, both kinds, and NO member nav of
        # either sort. Anything else is named so a failure says which.
        if not legacy and not railed and topics and content:
            state = "DOOR"
        elif legacy:
            state = "LEGACY"
        elif railed:
            state = "RAIL"
        else:
            state = "?"
        states.append(state)

        problems = []

        # ── A. CANONICAL — unflagged, asserted always ─────────────────────────
        want = env["LG_GATE_HOST"].rstrip("/") + path
        m = re.search(r'<link[^>]+rel=["\']canonical["\'][^>]*>', page, re.I)
        if not m:
            problems.append("no <link rel=canonical> — nothing tells Google which "
                            "URL owns this listing")
        else:
            h = re.search(r'href=["\']([^"\']+)["\']', m.group(0), re.I)
            got = (h.group(1) if h else "").strip()
            if got.rstrip("/") != want.rstrip("/"):
                problems.append(f"canonical not self-referencing: {got!r} != {want!r}")

        # ── The category's own content must be in the SERVER html ────────────
        if len(re.findall(r"feed-card feed-card--", page)) == 0:
            problems.append("no feed cards in the served HTML — a category listing "
                            "whose content only arrives via JS is not indexable")

        # ── B. THE DOOR SHAPE — flag-state aware ─────────────────────────────
        if state == "DOOR":
            pass                                   # asserted below via problems
        elif REQUIRE_RAIL:
            if legacy:
                problems.append("renders the LEGACY category tree — member nav "
                                "that the rebuild replaces")
            if railed:
                problems.append("renders the HUB RAIL/chipbar — that ADDS member "
                                "nav, which Ian's ruling 7 forbids on a door")
            if not content:
                problems.append("no content items — ruling 8 is option A, "
                                "discussions AND related content mixed")
            if not topics:
                problems.append("no discussions on a category door")
        else:
            print(f"  OFF   {path:<44} not rebuilt yet "
                  f"(legacy={legacy} rail={railed} topics={topics} content={content})"
                  f" — door shape not asserted")

        for p in problems:
            findings.append(f"{path} — {p}")
            print(f"  RED   {path:<44} {p}")
        if not problems:
            ok += 1
            print(f"  ok    {path:<44} state={state} topics={topics} "
                  f"content={content}, canonical self-referencing")

    # ── A hidden category must not become a public listing ───────────────────
    if hidden:
        hpath = f"{PREFIX}/{hidden[0]}/"
        hbody, hcode = fetch(env, hpath)
        if hcode == 200 and "feed-card" in hbody:
            findings.append(f"{hpath} — a NON-PUBLIC category rendered a listing "
                            f"(HTTP 200 with feed cards)")
            print(f"  RED   {hpath:<44} non-public category rendered a listing")
        else:
            print(f"  ok    {hpath:<44} non-public category does not list (HTTP {hcode})")
            ok += 1

    print()
    if findings:
        print(f"RED  {len(findings)} finding(s):")
        for f in findings:
            print(f"  - {f}")
        return 1
    if states and all(s != "DOOR" for s in states):
        print(f"GREEN (door not built yet)  canonical + content intact on {ok} "
              f"check(s); the Google-door rebuild is not armed.")
    else:
        print(f"GREEN  {ok} check(s): category pages render the hub, carry a "
              f"self-referencing canonical, and keep their content server-side.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
