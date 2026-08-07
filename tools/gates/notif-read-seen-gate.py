#!/usr/bin/env python3
"""
notif-read-seen-gate — marking notifications read must be scoped to what was SEEN.

WHY THIS GATE EXISTS (CRAFT-STANDARD: a defect class found TWICE becomes a gate).

The class is "a read that was never a read", and it has now cost a member their
digest twice in this store:

  1. 2026-07-29 — the recap's two registers disagreed about what "still outstanding"
     meant. A member who had merely LOOKED at a connection request vanished from the
     named register while still appearing in the counted one, and since empty means
     no email, someone with exactly one unanswered request received nothing.
     Fixed by Recap::OUTSTANDING; that comment names the timer below.
  2. 2026-08-07 — webroot/bottom-nav.js fired {action:'read_all'} 700ms after the
     mobile notification sheet rendered EIGHT rows, marking the member's ENTIRE
     store read. The weekly recap is "what you missed", unread only (Ian,
     docs/IAN-RULINGS-2026-08-03.md §1), so one glance at the bell emptied the
     recap and cancelled the digest. Backlog 4.1.

THE ASSERTION THAT WAS MISSING BOTH TIMES IS AN ABSENT-HALF ONE: not "the rows the
member saw are read" — that was always true and always green — but "the rows the
member did NOT see are STILL UNREAD". A gate can only see what is PRESENT unless
somebody writes down what must be ABSENT, and all of Ian's misses lived in that
blind spot. So the decisive checks here are the `unseen_still_unread` ones.

WHAT IT ASSERTS, and in which direction

  STORE     markReadMany marks exactly the named rows; the complement stays unread;
            a foreign id marks nothing; markAllRead still sweeps (so the OFF state
            is intact); and the consequence — the recap keeps the unseen rows and
            is emptied by a sweep. Real model, real database, one transaction that
            is NEVER committed, so it mutates no member data.
  FLAG      applySeenRead measured under BOTH values of read_seen_only, via a
            config the gate supplies. This is the check that a master flag would
            otherwise neuter: an early-return under OFF once turned two recipient
            tests into tests of the pass-through, and they stayed red so they
            looked alive while the real logic went untested for the flag's whole life.
  DEFAULT   the shipped flag is OFF, unless docs/RECAP-READ-TIMER.md records a
            decision to arm it. Same tripwire shape docs/IAN-RULINGS-2026-08-03.md
            §4 asks for: ON requires a recorded decision, and the correct response
            to it going red is to record the decision, NOT to delete the check.
  CLIENT    source-shape tripwires on webroot/bottom-nav.js — the dwell posts
            read_seen and not read_all, closing the sheet CANCELS the dwell, and the
            dwell re-checks the sheet is still open when it fires. These are
            labelled STRUCTURAL because that is what they are: they cannot prove the
            browser behaviour, and they are not offered as if they could. The
            end-to-end proof is a real headless-Chrome control on the real origin,
            recorded in docs/RECAP-READ-TIMER.md; it needs a bespoke three-server
            harness and so is not run from here.

Run:   python3 tools/gates/notif-read-seen-gate.py
Needs: sudo -u profile-app php (the WP pool has no grants on profile_app), and the
       profile_app database with at least two bridged members.

Exit:  0 green, 1 RED (real findings), 2 CANNOT RUN (no verdict).
       The split matters: this gate needs a specific pool user and a database, so
       most of its failure modes are ENVIRONMENTAL, and reporting those as red is
       indistinguishable from a regression — which is how craft gate 2 sat "red"
       for weeks while it was in fact dead. Note run-all.sh reads ONLY 0/1/2: an
       exit of 3 or 70 is counted as a finding, so never invent a third code here.
"""
import json
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
LIB = os.path.join(ROOT, "tools", "gates", "lib")
BOTTOM_NAV = os.path.join(ROOT, "webroot", "bottom-nav.js")
DECISION_DOC = os.path.join(ROOT, "docs", "RECAP-READ-TIMER.md")

passes = failures = 0


def log(*a):
    print(" ".join(str(x) for x in a), flush=True)


def check(label, got, want):
    global passes, failures
    ok = got == want
    if ok:
        passes += 1
    else:
        failures += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + ("" if ok else f"   got={got!r} want={want!r}"))
    return ok


def cannot_run(why):
    log(f"  CANNOT RUN — {why}")
    log("  (exit 2: no verdict. This is NOT a pass and NOT a finding.)")
    sys.exit(2)


def run_php(script, *args):
    """Run a helper as the profile-app pool user. Returns its parsed JSON."""
    cmd = ["sudo", "-n", "-u", "profile-app", "php", os.path.join(LIB, script), ROOT, *args]
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0 and not p.stdout.strip():
        cannot_run(f"{script} did not run: rc={p.returncode} {p.stderr.strip()[:400]}")
    try:
        return json.loads(p.stdout.strip().splitlines()[-1])
    except Exception:
        cannot_run(f"{script} produced no JSON: {p.stdout[:300]!r} {p.stderr[:300]!r}")


def write_cfg(tmpdir, read_seen_only, max_ids=200):
    """A throwaway config, so the tracked file is never edited to run a test."""
    path = os.path.join(tmpdir, f"cfg-{str(read_seen_only).lower()}-{max_ids}.php")
    with open(path, "w") as f:
        f.write("<?php return array('read_seen_only' => %s, 'max_ids' => %d);\n"
                % ("true" if read_seen_only else "false", max_ids))
    os.chmod(path, 0o644)
    return path


# ── STORE ────────────────────────────────────────────────────────────────────
log("=== STORE: the named rows, and only the named rows ===")
core = run_php("notif-read-seen-core.php")
if core.get("cannot_run"):
    cannot_run(core["cannot_run"])
if core.get("error"):
    cannot_run(f"core helper raised: {core['error']}")

log(f"  (acting as wp:{core.get('acting_wp_user_id')}, in a transaction that is never committed)")
check("fixture really started with 12 unread", core.get("seeded_unread"), 12)
check("markReadMany(8 ids) marked exactly 8", core.get("marked_by_seen_call"), 8)
check("the 8 SEEN rows are now read", core.get("seen_now_read"), 8)
# THE ABSENT HALF. Everything above was green before the fix too.
check("the 4 UNSEEN rows are STILL UNREAD", core.get("unseen_still_unread"), 4)
check("unreadCount agrees (badge tells the truth, not zero)", core.get("unread_count_after_seen"), 4)
check("RECAP still holds the unseen rows -> a digest is sent", core.get("recap_rows_with_unseen"), 4)

log("=== STORE: owner scoping — ids arrive from a client and are not trusted ===")
check("a FOREIGN id marks nothing", core.get("marked_by_foreign_call"), 0)
check("the foreign row is still unread", core.get("foreign_row_still_unread"), True)

log("=== STORE: the OFF behaviour is intact (a sweep must still sweep) ===")
check("markAllRead read all 12", core.get("all_read_after_sweep"), 12)
check("unreadCount is 0 after a sweep", core.get("unread_count_after_sweep"), 0)
check("RECAP is EMPTY after a sweep -> no email at all", core.get("recap_rows_after_sweep"), 0)

# ── FLAG: both directions, against the real decision code ────────────────────
log("=== FLAG: applySeenRead measured under BOTH values of read_seen_only ===")
with tempfile.TemporaryDirectory(prefix="notif-gate-") as tmp:
    os.chmod(tmp, 0o755)

    off = run_php("notif-read-seen-policy.php", write_cfg(tmp, False))
    if off.get("error"):
        cannot_run(f"policy helper (OFF) raised: {off['error']}")
    check("OFF: flag reads false", off.get("flag_read_seen_only"), False)
    check("OFF: policy is 'all'", (off.get("result") or {}).get("policy"), "all")
    check("OFF: the 8 seen rows are read", off.get("seen_read"), 8)
    # The no-op proof: OFF must sweep the unseen rows too, exactly as read_all did.
    check("OFF: the 4 unseen rows are ALSO read (no-op vs today)", off.get("unseen_read"), 4)
    check("OFF: nothing left unread", off.get("unread_total"), 0)

    on = run_php("notif-read-seen-policy.php", write_cfg(tmp, True))
    if on.get("error"):
        cannot_run(f"policy helper (ON) raised: {on['error']}")
    check("ON: flag reads true", on.get("flag_read_seen_only"), True)
    check("ON: policy is 'seen'", (on.get("result") or {}).get("policy"), "seen")
    check("ON: the 8 seen rows are read", on.get("seen_read"), 8)
    check("ON: NO unseen row was read", on.get("unseen_read"), 0)
    check("ON: the 4 unseen rows are STILL UNREAD", on.get("unseen_still_unread"), 4)
    check("ON: 4 left unread", on.get("unread_total"), 4)

    # The cap is a bound on what a client can ask for, so measure it rather than
    # trust it: max_ids=3 against a list of 8 must mark at most 3.
    cap = run_php("notif-read-seen-policy.php", write_cfg(tmp, True, 3))
    check("cap: max_ids is honoured", cap.get("cap_respected"), True)
    check("cap: max_ids=3 marked at most 3", (cap.get("result") or {}).get("marked", 99) <= 3, True)

# ── DEFAULT: OFF unless a decision is recorded ───────────────────────────────
log("=== DEFAULT: member-facing flags ship OFF unless a decision is recorded ===")
armed = bool(core.get("read_seen_only"))
if not armed:
    check("shipped flag read_seen_only is OFF", armed, False)
else:
    doc = ""
    if os.path.isfile(DECISION_DOC):
        doc = open(DECISION_DOC, encoding="utf-8").read()
    recorded = re.search(r"^\s*#{1,6}\s*Decision to arm\b", doc, re.M | re.I) is not None
    log("  flag is ARMED (read_seen_only = true).")
    log("  That is allowed, but only with a recorded decision: a '## Decision to arm'")
    log(f"  section in {os.path.relpath(DECISION_DOC, ROOT)} naming who decided and when.")
    log("  If this is red, RECORD THE DECISION — do not delete this check.")
    check("arming the flag is recorded in the decision doc", recorded, True)

# ── CLIENT: structural tripwires (they cannot prove the browser) ─────────────
log("=== CLIENT (STRUCTURAL — source shape, not behaviour) ===")
if not os.path.isfile(BOTTOM_NAV):
    cannot_run(f"{BOTTOM_NAV} is missing")
js = open(BOTTOM_NAV, encoding="utf-8").read()


def strip_js_comments(src):
    """Remove // and /* */ comments, leaving string literals intact.

    NOT cosmetic. Red-firsting this gate caught it asserting on PROSE: the
    "See all asks for the whole store" check looked for `limit=200`, and that string
    ALSO appears in the comment a dozen lines below explaining the change. Deleting
    the actual fetch left the comment behind and the assertion stayed GREEN — the
    same failure as a guard-check that matched the file's own error text instead of
    its code. A structural gate must read code only.
    """
    out = []
    i, n = 0, len(src)
    quote = None
    while i < n:
        c = src[i]
        if quote:
            out.append(c)
            if c == "\\" and i + 1 < n:
                out.append(src[i + 1]); i += 2; continue
            if c == quote:
                quote = None
            i += 1
            continue
        if c == "/" and i + 1 < n and src[i + 1] == "/":
            j = src.find("\n", i)
            i = n if j < 0 else j
            continue
        if c == "/" and i + 1 < n and src[i + 1] == "*":
            j = src.find("*/", i + 2)
            i = n if j < 0 else j + 2
            out.append(" ")
            continue
        if c in "'\"`":
            quote = c
        out.append(c); i += 1
    return "".join(out)


def body_of(fn_name):
    """The source of one top-level `function name(...) { ... }`, brace-matched.

    Brace-matched rather than regex-sliced because the interesting question is
    always "is this call inside THIS function", and a regex that stops at the first
    '}' answers it wrongly — the stale-file:line trap in a different costume.
    """
    m = re.search(r"\bfunction\s+" + re.escape(fn_name) + r"\s*\([^)]*\)\s*\{", js)
    if not m:
        return None
    i = m.end() - 1
    depth = 0
    for j in range(i, len(js)):
        if js[j] == "{":
            depth += 1
        elif js[j] == "}":
            depth -= 1
            if depth == 0:
                return js[i:j + 1]
    return None


# Every check below reads CODE, never comments — see strip_js_comments.
close_fn = body_of("closeNotifSheet")
load_fn = body_of("loadSheetNotifs")
seen_fn = body_of("markNotifsSeenRead")

check("closeNotifSheet() exists", close_fn is not None, True)
check("loadSheetNotifs() exists", load_fn is not None, True)
check("markNotifsSeenRead() exists", seen_fn is not None, True)

if close_fn:
    # RED-B: the dwell used to be unclearable, so a sheet dismissed at 300ms still
    # marked its rows read 700ms after it was gone.
    code = strip_js_comments(close_fn).replace(" ", "")
    check("closing the sheet CANCELS the dwell timer",
          "clearTimeout(notifDwellTimer)" in code, True)
if load_fn:
    code = strip_js_comments(load_fn)
    compact = code.replace(" ", "").replace("\n", "")
    check("the dwell path posts read_seen, not read_all",
          "markNotifsSeenRead(" in compact, True)
    check("the dwell re-checks the sheet is OPEN when it fires",
          ".is-open')" in code and "return;" in code, True)
    check("'See all' asks for the whole store so the badge stays reachable",
          "limit=200" in compact, True)
if seen_fn:
    code = strip_js_comments(seen_fn).replace(" ", "")
    check("markNotifsSeenRead sends action read_seen", "'read_seen'" in code, True)
    check("markNotifsSeenRead sends the ids", "ids:ids" in code, True)

# read_all must survive as the EXPLICIT verb, but nothing may schedule it on a timer.
timer_read_all = re.search(r"setTimeout\([^)]*markAllNotifsRead",
                           strip_js_comments(js)) is not None
check("no timer schedules markAllNotifsRead directly", timer_read_all, False)

log("")
log(f"  {passes} passed, {failures} failed")
if failures:
    log("  RED — a read that was never a read. See docs/RECAP-READ-TIMER.md.")
    sys.exit(1)
log("  GREEN — what gets marked read is what was seen, in both flag directions.")
sys.exit(0)
