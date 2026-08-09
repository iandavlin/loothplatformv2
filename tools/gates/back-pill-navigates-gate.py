#!/usr/bin/env python3
"""
back-pill-navigates-gate — a rendered NAV control must actually navigate.
Backlog 3.8 (Ian ruled option D, 2026-08-09).

SAME CLASS AS GATE 19, one step further along. Gate 19 guards markup that arrives
without its behaviour. This guards the next failure: a control that is present, is
wired, and still takes you nowhere — a dead href, a wrong target, a listener that
preventDefaults and forgets to navigate. Ian's whole complaint in 3.8 was a back
button that existed and could not be reached; shipping one that is reachable and
inert would be the same sentence with a new subject.

WHAT IT ASSERTS, and why each is here:

  A. FLAG OFF IS A REAL NO-OP. _chrome.php must emit the bit ONLY when on — never
     as `= false`, which is a behavioural no-op but not a byte-identical one. And
     the client must gate on that bit, so OFF cannot be revealed by a stray rule.

  B. THE CONTROL NAVIGATES SOMEWHERE REAL. Its href must be the hub, root-relative
     and same-origin. A pill pointing at '#' or at a 404 is the defect this exists
     for, and "it renders" is exactly the assertion that would miss it.

  C. IT IS NOT OFFERED WHERE IT MAKES NO SENSE. "Back to the Hub" on the hub is a
     control that lies about where it takes you; the path guard must be present.

  D. IT CAN COME BACK. The hide-on-scroll behaviour is the whole reason Ian picked
     D over C, and a control that hides and never returns is strictly worse than
     one that never hides. Both directions must exist in the source.

  E. THE HIDDEN STATE IS NOT CLICKABLE. It slides out with opacity 0 — without
     pointer-events:none that leaves an invisible tap target over the page, which
     is the mirror image of the bug and just as hard to see.

  F. THE DEFAULT IS READ FROM THE CONFIG, never hardcoded here, so flipping it
     needs no gate edit.

Static by design: it reads the shipped source, so it runs in the numbered suite
with no browser. The BEHAVIOUR (does a real tap land on /hub/) is proven by
tools/exercise-harness/browser-backpill-verify.py against the dev2 serve, which
cannot live in the runner because it needs CDP.

Exit codes follow run-all.sh: 0 green, 1 RED (real findings), 2 CANNOT RUN.
"""
import os
import re
import sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
NAV = os.path.join(REPO, "webroot", "bottom-nav.js")
CHROME = os.path.join(REPO, "bb-mirror", "web", "_chrome.php")
CONFIG = os.path.join(REPO, "platform", "config", "back-pill.php")

RED, DEAD, OK = [], [], []


def main():
    print("=== back-pill: a rendered nav control must actually navigate ===")
    try:
        nav = open(NAV).read()
        chrome = open(CHROME).read()
        cfg = open(CONFIG).read()
    except OSError as e:
        print(f"  CANNOT RUN: {e}")
        return 2

    m = re.search(r"function buildBackPill\(\)\s*\{(.*?)\n  \}", nav, re.S)
    if not m:
        RED.append("webroot/bottom-nav.js has no buildBackPill — the control Ian ruled "
                   "for is not built at all")
        body = ""
    else:
        body = m.group(1)
        OK.append("the pill is built in bottom-nav.js, beside the tray Back it promotes")

    # ── F. the default, read not assumed ────────────────────────────────────
    d = re.search(r"'enabled'\s*=>\s*(true|false)", cfg)
    if not d:
        DEAD.append("could not read 'enabled' from back-pill.php — a gate must READ "
                    "the flag, never assume it")
    else:
        OK.append(f"config default read from disk: enabled={d.group(1)}")

    # ── A. OFF is byte-identical, and the client honours it ─────────────────
    if "LG_BACK_PILL" not in chrome:
        RED.append("_chrome.php never emits LG_BACK_PILL — flag ON could not reach the "
                   "client and the pill would never appear")
    elif re.search(r"LG_BACK_PILL\s*=\s*false", chrome):
        RED.append("_chrome.php emits LG_BACK_PILL = false — OFF must emit NOTHING or "
                   "the served page stops being byte-identical")
    else:
        OK.append("_chrome.php emits the bit only when on (OFF changes no bytes)")

    if body and not re.search(r"if\s*\(\s*!window\.LG_BACK_PILL\s*\)\s*return", body):
        RED.append("buildBackPill does not return early on !window.LG_BACK_PILL — with "
                   "the flag off it would build anyway, so OFF stops being a no-op")
    elif body:
        OK.append("the client returns before touching the DOM when the flag is off")

    # ── B. it navigates somewhere real ──────────────────────────────────────
    href = re.search(r"\.href\s*=\s*'([^']+)'", body or "")
    if not href:
        RED.append("the pill sets no href — a nav control that renders and navigates "
                   "nowhere is the exact defect this gate exists for")
    elif href.group(1) != "/hub/":
        RED.append(f"the pill points at {href.group(1)!r}, not the hub ('/hub/')")
    else:
        OK.append("the pill navigates to /hub/, root-relative and same-origin")
    if body and re.search(r"\.href\s*=\s*'#", body):
        RED.append("the pill's href is '#' — renders, does nothing")

    # ── C. never offered where it would lie ─────────────────────────────────
    # Assert the GUARD, not the presence of a string. The first version checked only
    # that "location.pathname" appeared somewhere in the body — which stayed GREEN
    # through the red-first mutation that deleted the actual return, because the
    # variable assignment still mentioned it. Same shape as gate 19's substring bug:
    # the check was reading the code NEXT TO the thing under test.
    # NB the nested parens: `.test(path))` has two, so a `[^)]*` class stops at the
    # first one and never matches. The first attempt did exactly that and was RED for
    # every input including correct code — and the red-first did not catch it, because
    # an inversion pass only proves an assertion CAN go red, never that it goes GREEN
    # on the real thing. Both halves have to be checked, every time.
    if body and not re.search(r"if\s*\(\s*!/\^\\/hub[^\n]*\.test\(path\)\)\s*return", body):
        RED.append("buildBackPill has no `if (!/^/hub/.../.test(path)) return` guard — "
                   "it would offer 'Back to the Hub' while you are already on the Hub")
    elif body:
        OK.append("scoped by path: offered on /hub/<...>, not on the hub itself")

    # ── D. it hides AND returns ─────────────────────────────────────────────
    adds = body.count("classList.add('is-away')") if body else 0
    rems = body.count("classList.remove('is-away')") if body else 0
    if body and not (adds and rems):
        RED.append(f"the hide/show pair is incomplete (add={adds} remove={rems}) — a "
                   f"control that hides and never returns is worse than one that never "
                   f"hides, and hide-on-scroll is why Ian chose D over C")
    elif body:
        OK.append("hides on scroll-down AND returns on scroll-up (both branches present)")

    # ── E. the hidden state must not be tappable ────────────────────────────
    if body and not re.search(r"is-away\{[^}]*pointer-events\s*:\s*none", body):
        RED.append("the .is-away state has no pointer-events:none — it slides out at "
                   "opacity 0 and would leave an INVISIBLE tap target over the page")
    elif body:
        OK.append("the hidden state is not clickable (pointer-events:none)")

    for m_ in OK:   print(f"  ok   {m_}")
    for m_ in RED:  print(f"  RED  {m_}")
    for m_ in DEAD: print(f"  DEAD {m_}")

    if DEAD:
        print(f"back-pill: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    if RED:
        print(f"back-pill: RED — {len(RED)} finding(s)")
        return 1
    print(f"back-pill: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
