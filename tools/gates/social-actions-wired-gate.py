#!/usr/bin/env python3
"""
social-actions-wired-gate — a rendered control must carry its BEHAVIOUR, not just
its markup. Backlog 4.4 + 4.3 (Ian 8/8).

THE DEFECT CLASS, which is why this is a gate and not a test:

    profile-app renders Connect / Message / Accept / Decline / Cancel and the "..."
    Mute-Unmute-Remove menu, and shipped their behaviour as an inline <script> in
    the same markup. The mobile profile tray RELOCATES that markup — it fetches
    /u/<slug>, lifts .lg-profile out with DOMParser and injects it into whatever
    page you were on, where an inline script can never run. Seven controls
    rendered; none of them did anything. Ian found two of them from a phone in one
    sitting.

    Ian has ruled against this class repeatedly and it keeps coming back, because
    every assertion anyone writes is about PRESENCE and the defect is in ABSENCE:
    the buttons are all there, in the DOM, correctly styled, hit-testable. A
    presence gate is green on the broken state. So this gate asserts the pairing —
    markup present IMPLIES its wiring is present and reachable — and asserts it
    per flag state.

WHAT IT ASSERTS, and why each one is here rather than assumed:

  A. NO DRIFT. While the flag lives there are two copies of the wiring: the inline
     heredoc (OFF) and webroot/lg-social-actions.js (ON). Two copies are only safe
     if something compares them. This does, byte for byte. It is the assertion that
     makes the whole flagged migration honest.

  B. FLAG OFF is a real no-op — no stamp, inline wiring present, and (the liveness
     half) the widget actually rendered with its buttons. "No stamp" is trivially
     true of an empty string, which is what an anonymous viewer gets.

  C. FLAG ON swaps the source rather than adding one: stamp present, script src
     present, inline ABSENT. Never both, or a page gets the listener twice and
     __lgSocialWired silently decides which copy wins.

  D. THE STAMP RESOLVES. A stamp pointing at a file that is not deployed is worse
     than the bug — the tray would try, 404, and stay dead with the flag on. This
     is the deploy coupling: webroot/lg-social-actions.js is a NEW webroot file and
     the symlink set is not in the repo.

  E. THE CLIENT HALF. profile-sheet.js must actually call the loader after it
     injects, the loader must be gated on the stamp (so no stamp = no-op), and it
     must refuse a cross-origin src.

  F. THE DEFAULT IS OFF, read from the config rather than hardcoded here — flipping
     the default must not require editing this gate.

Per-state, off the RENDERED widget, so this gate keeps working after Ian switches
the flag on. It reads the config for the CURRENT state and asserts that state's
contract, and asserts BOTH states explicitly via the override.

Exit codes follow run-all.sh: 0 green, 1 RED (real findings), 2 CANNOT RUN.
"""

import os
import re
import subprocess
import sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PROBE = os.path.join(REPO, "tools", "gates", "social-actions-wired-probe.php")
SOCIAL = os.path.join(REPO, "profile-app", "src", "Social.php")
ASSET = os.path.join(REPO, "webroot", "lg-social-actions.js")
CONFIG = os.path.join(REPO, "platform", "config", "social-actions.php")
SHEET = os.path.join(REPO, "webroot", "profile-sheet.js")
PAUSER = os.environ.get("LG_SA_PAUSER", "profile-app")

# A viewer/subject pair that yields a NON-EMPTY widget: logged-in, not the owner.
VIEWER = os.environ.get("LG_SA_VIEWER", "4e9620c9-42eb-59ca-b350-85dceca5e801")
SUBJECT = os.environ.get("LG_SA_SUBJECT", "502849b6-cccb-5e29-b1b2-1691436e3c4d")

RED, DEAD, OK = [], [], []


def ok(msg):
    OK.append(msg)


def render(flag):
    """Render the widget once, with the flag forced. Returns dict or None."""
    env = ["env", f"LG_SA_ROOT={REPO}", f"LG_SA_VIEWER={VIEWER}", f"LG_SA_SUBJECT={SUBJECT}"]
    if flag is not None:
        env.append(f"LG_SOCIAL_ACTIONS_SRC={'1' if flag else '0'}")
    try:
        p = subprocess.run(["sudo", "-n", "-u", PAUSER] + env + ["php", PROBE],
                           capture_output=True, text=True, timeout=120)
    except Exception as e:
        DEAD.append(f"probe(flag={flag}) could not run: {e}")
        return None
    d = {}
    for line in p.stdout.splitlines():
        if "=" in line:
            k, _, v = line.partition("=")
            d[k] = v
    if "ERROR" in d:
        DEAD.append(f"probe(flag={flag}) errored: {d['ERROR']}")
        return None
    if not d.get("OK"):
        DEAD.append(f"probe(flag={flag}) did not complete (rc={p.returncode}): "
                    f"{(p.stderr or p.stdout)[:200]}")
        return None
    return d


def liveness(d, label):
    """An absence claim on an empty widget is vacuous. Prove it rendered first."""
    if int(d.get("RENDERED", 0)) == 0 or d.get("HAS_WIDGET") != "1":
        DEAD.append(f"{label}: the widget rendered EMPTY — every assertion about it "
                    f"would be vacuously true. Check the viewer/subject fixture "
                    f"(anonymous viewer, own profile, or a blocked edge all yield '').")
        return False
    if d.get("HAS_MOREBTN") != "1" or int(d.get("N_ACTIONS", 0)) < 1:
        DEAD.append(f"{label}: widget rendered but carries no controls "
                    f"(morebtn={d.get('HAS_MOREBTN')} actions={d.get('N_ACTIONS')})")
        return False
    return True


# ── A. the two copies of the wiring must not drift ──────────────────────────
def check_drift():
    try:
        src = open(SOCIAL).read()
    except OSError as e:
        DEAD.append(f"cannot read {SOCIAL}: {e}")
        return
    try:
        asset = open(ASSET).read()
    except OSError:
        # A MISSING asset is a finding, not an inability to answer: the flag stamps a
        # url at it, so gone means the tray 404s and stays dead with the flag on.
        RED.append("webroot/lg-social-actions.js is MISSING — the flag stamps a url at "
                   "it, so ON would 404 and leave the tray dead while reporting fixed")
        return
    m = re.search(r"return <<<'JS'\n<script>\n(.*?)\n</script>\nJS;", src, re.S)
    if not m:
        DEAD.append("could not find the inline wiring heredoc in Social.php — if the "
                    "OFF path was removed, delete this check WITH it, do not let it "
                    "silently stop comparing.")
        return
    inline = m.group(1).strip()
    # The asset carries a file header comment the heredoc does not; compare the IIFE.
    i = asset.find("(function ()")
    if i < 0:
        RED.append("webroot/lg-social-actions.js does not contain the wiring IIFE")
        return
    shipped = asset[i:].strip()
    if inline != shipped:
        RED.append("DRIFT: Social.php's inline wiring and webroot/lg-social-actions.js "
                   "are no longer identical. While the flag exists both are live — OFF "
                   "serves the heredoc, ON serves the file — so a change to one is a "
                   "behaviour change for half the members.")
    else:
        ok(f"no drift: both copies of the wiring are the same {len(inline)} bytes")


# ── F. the default, read from the config ────────────────────────────────────
def check_default():
    try:
        txt = open(CONFIG).read()
    except OSError as e:
        DEAD.append(f"cannot read {CONFIG}: {e}")
        return None
    m = re.search(r"'enabled'\s*=>\s*(true|false)", txt)
    if not m:
        DEAD.append("could not read 'enabled' from the config — the gate must READ the "
                    "flag, never assume it")
        return None
    state = m.group(1) == "true"
    ok(f"config default read from disk: enabled={state}")
    return state


# ── D. the stamp must resolve to something deployed ─────────────────────────
def check_asset(stamp_src):
    if not os.path.isfile(ASSET):
        RED.append("webroot/lg-social-actions.js is MISSING — flag ON would stamp a "
                   "404 and leave the tray dead while claiming to be fixed")
        return
    if stamp_src and stamp_src.lstrip("/") != os.path.basename(ASSET):
        RED.append(f"the stamp points at {stamp_src!r} but the repo ships "
                   f"{os.path.basename(ASSET)} — flag ON would 404")
        return
    p = subprocess.run(["node", "--check", ASSET], capture_output=True, text=True)
    if p.returncode != 0:
        RED.append(f"webroot/lg-social-actions.js is not valid JS: {p.stderr.strip()[:160]}")
        return
    ok("the stamped asset exists in the repo and parses")
    # The deploy coupling is real and a plain pull does not handle it.
    docroot = os.environ.get("LG_SA_DOCROOT", "/var/www/dev")
    link = os.path.join(docroot, os.path.basename(ASSET))
    if not os.path.exists(link):
        ok(f"NOTE: {link} does not exist yet — a new webroot file needs "
           f"`webroot/install-symlinks.sh --new-only` in the same window as the pull. "
           f"Not RED: the flag is off, so nothing requests it.")


# ── E. the client half ──────────────────────────────────────────────────────
def check_sheet():
    try:
        js = open(SHEET).read()
    except OSError as e:
        DEAD.append(f"cannot read {SHEET}: {e}")
        return
    if "function initSocialActions" not in js:
        RED.append("profile-sheet.js has no initSocialActions — the injected widget "
                   "would have markup and no behaviour again")
        return
    body = js[js.index("function initSocialActions"):]
    body = body[:body.find("\n  function ", 10) if body.find("\n  function ", 10) > 0 else 4000]
    # Assert the SELECTOR, not the mere presence of the string. The red-first caught
    # this one: swapping the query to '.lg-social-actions' left "data-lg-social-src"
    # in the very next line (the getAttribute call), so a substring check stayed
    # green through the exact mutation it claimed to catch — the gate reading its
    # own neighbouring code instead of the thing under test.
    if not re.search(r"querySelector\(\s*'\[data-lg-social-src\]'\s*\)", body):
        RED.append("initSocialActions does not SELECT on [data-lg-social-src] — with "
                   "the flag OFF it would act on any widget it finds, so OFF stops "
                   "being a no-op")
    else:
        ok("the loader is gated on the server's stamp (no stamp => no-op)")
    if "__lgSocialWired" not in body:
        RED.append("initSocialActions does not check __lgSocialWired — it would load a "
                   "second copy of the wiring onto a page that already has it")
    else:
        ok("the loader is idempotent against a page that is already wired")
    if "charAt(0) !== '/'" not in body and "startsWith('/')" not in body:
        RED.append("initSocialActions does not constrain the src to same-origin — a "
                   "stamp is server-controlled, but this is the one place a bad value "
                   "becomes script execution")
    else:
        ok("the loader refuses a src that is not same-origin root-relative")
    # The call site matters as much as the function — and must be matched as a CALL.
    # Red-first caught this: `initSocialActions\(prof\)` also matches the string
    # "function initSocialActions(prof)", so deleting the only call site left the
    # gate green. The definition can never be evidence that the thing is invoked.
    if not re.search(r"(?<!function )\binitSocialActions\(prof\)\s*;", js):
        RED.append("initSocialActions is defined but never called on the injected node "
                   "— the widget would arrive with markup and no behaviour again")
    else:
        ok("the loader is called on the injected profile node")


def main():
    print("=== social-actions-wired: a rendered control must carry its behaviour ===")

    check_drift()
    default_on = check_default()
    check_sheet()

    off = render(False)
    on = render(True)
    if off is None or on is None:
        print("\n".join("  CANNOT RUN: " + d for d in DEAD))
        print("social-actions-wired: NO VERDICT")
        return 2

    # ── B. OFF is a real no-op ──────────────────────────────────────────────
    if liveness(off, "flag OFF"):
        if off.get("HAS_STAMP") == "1":
            RED.append("flag OFF still stamps data-lg-social-src — OFF is not a no-op")
        elif off.get("HAS_INLINE") != "1":
            RED.append("flag OFF emits NEITHER the stamp nor the inline wiring — the "
                       "widget would be dead on the full /u/ page too, which is worse "
                       "than the bug being fixed")
        else:
            ok("flag OFF: inline wiring present, no stamp — the pre-fix bytes")

    # ── C. ON swaps the source, never doubles it ────────────────────────────
    if liveness(on, "flag ON"):
        if on.get("HAS_STAMP") != "1":
            RED.append("flag ON does not stamp data-lg-social-src — the tray has no way "
                       "to find the wiring and 4.4/4.3 are still broken")
        if on.get("HAS_INLINE") == "1":
            RED.append("flag ON emits the stamp AND the inline wiring — two sources of "
                       "one delegated listener")
        if not on.get("SCRIPT_SRC"):
            RED.append("flag ON does not emit a <script src> — the full /u/ page would "
                       "lose its behaviour entirely")
        if on.get("STAMP_SRC") and on.get("SCRIPT_SRC") and \
           on["STAMP_SRC"] != on["SCRIPT_SRC"]:
            RED.append(f"the stamp ({on['STAMP_SRC']}) and the script src "
                       f"({on['SCRIPT_SRC']}) disagree — the tray would load a "
                       f"different file than the page does")
        if on.get("HAS_STAMP") == "1" and on.get("HAS_INLINE") != "1" and on.get("SCRIPT_SRC"):
            ok("flag ON: exactly one wiring source, and the tray is told where it is")

    check_asset(on.get("STAMP_SRC"))

    # ── the controls the tickets were actually about ────────────────────────
    for label, d in (("OFF", off), ("ON", on)):
        if d.get("HAS_MOREBTN") != "1":
            RED.append(f"flag {label}: the 3-dots control (4.3) is not rendered at all")
        if d.get("HAS_MENU") != "1":
            RED.append(f"flag {label}: the 3-dots MENU is not rendered at all")

    if default_on is not None and default_on:
        ok("NOTE: the default is now ON. That is a decision, not a defect — this gate "
           "asserts both states, so nothing here needed editing to allow it.")

    for m in OK:
        print(f"  ok   {m}")
    for m in RED:
        print(f"  RED  {m}")
    for m in DEAD:
        print(f"  DEAD {m}")

    if DEAD:
        print(f"social-actions-wired: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    if RED:
        print(f"social-actions-wired: RED — {len(RED)} finding(s)")
        return 1
    print(f"social-actions-wired: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
