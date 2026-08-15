#!/usr/bin/env python3
"""
anon-dark-contrast-gate — GATE 36/36 — does a LOGGED-OUT
visitor in DARK MODE get text and form fields they can actually read?

WHY THIS GATE EXISTS (CRAFT-STANDARD: a defect class found TWICE becomes a gate,
and this one is 3+). Backlog 21, Ian 2026-08-14, verbatim: "the dark mode needs
some love for the login stuff" and "we have a ton of instructions and fields not
ready for primetime in dark for logged out." dark-anon-sweep's before-gallery
(tools/preview/dark-anon-sweep.py) found it live: /wp-login.php's card stays
WHITE while the page goes near-black around it (lg-snippets/snippets/86.php has
no dark-mode styling at all — the whole three-card login skin predates dark mode
entirely); the mobile compose "+" is a white icon on a fill that REPOINTS TO A
LIGHT COLOUR in dark (--lg-sage-d: #586b3f -> #b0c693) while the icon stays
hardcoded white — 1.85:1, confirmed by hand and reproduced by this exact probe.
KEEPER GATE NUMBER: 36, assigned by keeper 2026-08-15 (roster at assignment:
34 stripe, 35 compose/v2, 36 dark-anon). Requested per charter ("gate number
FROM KEEPER — ask; never mint") — two lanes have collided minting 9/9
independently before. Do not renumber without asking.

WHAT IT ASSERTS. On every anon-reachable surface a visitor meets in the sign-in
/ join / sign-up path — /wp-login.php, its lostpassword and bpnoaccess (failed
sign-up bounce) variants, /join, /lgjoin, and the front page — measured under
BOTH dark paths (app-dark: visitor picked Dark in the gear; os-dark: visitor
picked nothing and their OS is dark) at desktop AND mobile, per surface: NO
MORE findings below WCAG AA than the recorded BASELINE (see that constant's
own comment for the full reasoning and how it was captured; short version —
this backlog item names a genuinely large surface that will take several more
waves to fully clear, and a zero-findings assertion merged before that would
block every OTHER lane's train for debt this one is still working through in
separate commits). The bar being measured, unchanged from the original design:

  every piece of TEXT clears 4.5:1 (WCAG AA normal text) or 3.0:1 if it
    qualifies as large text
  every FORM FIELD's typed-in text and PLACEHOLDER clear the same bar — on a
    sign-up form the placeholder often IS the instruction, which is the exact
    mess this backlog item was chartered to fix
  every FORM FIELD's edge (border, or fill-vs-page if borderless) clears 3:1
    (WCAG 1.4.11), because an invisible box is not a findable box
  every filled circular icon CONTROL (the "+" compose button and its siblings)
    clears 3:1 between its icon and its own fill — CONFIRMED live, 2026-08-14:
    .lt-post > .lt-post-ico is #ffffff icon on #b0c693 fill, 1.85:1, exactly the
    charter's named seed. --lg-sage-d REPOINTS to a light colour under
    html[data-lguser-theme="dark"] (app-settings.js's boot vars: #586b3f ->
    #b0c693) while the icon's stroke stays hardcoded #fff — a fill token that
    flips brightness with NOTHING on the icon side to follow it. The probe
    checks the FILLED SHAPE, not the tag: .lt-post-ico is a <span> nested one
    level inside the actual <button>, which itself has no background of its
    own — gating the check on tag name (button/a only) missed this silently
    on the first pass and had to be widened to any element.

THE TWO DARK PATHS DO NOT AGREE, and asserting only one would leave the other
half of visitors unmeasured. app-dark gets a nginx-injected boot script that
force-paints body{background:#15171a!important} PRE-PAINT, including on
/wp-login.php, which has none of this project's CSS to match it. os-dark gets
no boot script at all — dark only ever arrives (if it arrives) from
app-settings.js's matchMedia check running client-side. See
tools/preview/dark-anon-sweep.py's module docstring for the full trace.

SAME PROBE AS THE SWEEP, ON PURPOSE — tools/gates/lib/contrast-probe.js. If this
gate carried its own copy of the contrast math, the gate could go green on a
surface the sweep had photographed as the worst one, and nobody could tell which
number was lying. Run tools/gates/lib/contrast-probe-selftest.js before trusting
either.

RUNS AGAINST THE REAL ANON STATE — clears cookies and re-arms ONLY the dev gate
cookie (never a WP session), because this whole gate is about what a visitor
with NO session sees. Never hits /gatetest for this reason (Ian/keeper,
2026-08-14): it is URI-exempt and always answers auth=1, which would silently
convert this into a gate that tests nothing.

RED-FIRST TWICE OVER, in two different senses, both real. (1) The gate itself
was written and run BEFORE any fix landed (charter METHOD step 2: gate before
fixing), so its ORIGINAL all-findings-red run was the true pre-fix baseline,
not a regression — that run is recorded in the fix commits' own messages. (2)
The RATCHET MECHANISM below (ratchet_verdict / BASELINE) is itself red-fired
on every run via _ratchet_selftest() before any browser opens — pure logic,
no CDP needed, proving a genuine regression reddens and holding steady or
improving does not, so the comparison this gate now runs on is trusted before
it is used to gate anything.

BASELINE IS A NOISE-SAFE FLOOR, NOT A TIGHT ONE — see that constant's own
comment. Two independent live captures under this box's real 2026-08-15
contention (load 0.4-7.1, several other lanes' gates running concurrently)
disagreed by 2-8x on some surfaces measuring the SAME code, because the
probe's settle delays are wall-clock, not event-based, and CPU contention
delays when a page's JS actually finishes painting dark. BASELINE is the
per-surface max of both captures for this reason — a tighter single-run
baseline would flap this gate red on measurement noise, not real
regressions, which teaches everyone to ignore it. Re-baseline tighter once
the probe waits on an explicit signal instead of a timer, or once it can run
on a quiet box.

Usage:  python3 tools/gates/anon-dark-contrast-gate.py
          Default mode — per surface, asserts no MORE findings than BASELINE
          (see that constant's own comment). This is what run-all.sh runs.
        python3 tools/gates/anon-dark-contrast-gate.py --verify-fixes
          Injects the queued fixes' CSS (icon + border-token; see
          FIX_VERIFY_CSS below) as an extra layer on top of the normally-
          served page and asserts THOSE specific classes clear full AA (not
          just "no worse than baseline") — proves the fix VALUES are correct
          before they are ever flipped on, without needing a merge or a
          lane-preview route. Out-of-scope findings this wave does not touch
          are reported, not failed. Manual verification step, not run by
          run-all.sh.
Needs:  chrome-dev on 127.0.0.1:9222, tools/gates/gate-env.sh resolving a token.
Exit 0 = GREEN (no surface exceeds its baseline). Exit 1 = RED, one summary
line per regressed surface plus its individual findings. Exit 2 = CANNOT RUN
(never silently exit 0 on a broken harness, and never on a ratchet-logic
self-test failure either — see trap-gate-exit-code-3-blocks-every-lane in
keeper memory: an open defect is exit 1, never a code that run-all.sh reads
as "could not run").
"""

import base64
import json
import os
import pathlib
import subprocess
import sys
import time
import urllib.request

try:
    import websocket
except ImportError:
    print("CANNOT RUN  python3-websocket-client required")
    sys.exit(2)

CDP = "http://127.0.0.1:9222"
HERE = os.path.dirname(os.path.abspath(__file__))
PROBE = os.path.join(HERE, "lib", "contrast-probe.js")

DESKTOP = {"width": 1440, "height": 900, "mobile": False, "deviceScaleFactor": 1}
PHONE = {"width": 390, "height": 844, "mobile": True, "deviceScaleFactor": 2}

# The sign-in / join / sign-up path. NOT the whole sweep (that is
# dark-anon-sweep.py's job, for ranking); this is the subset the charter names
# as the mess ("the login stuff", "instructions and fields... for logged out")
# plus the front page as the highest-traffic anon entry point.
GATED_SURFACES = [
    ("signin",       "/wp-login.php"),
    ("lostpassword", "/wp-login.php?action=lostpassword"),
    ("bpnoaccess",   "/wp-login.php?redirect_to=https%3A%2F%2F{host}%2Fregister%2F&bp-auth=1&action=bpnoaccess"),
    ("join",         "/join"),
    ("lgjoin",       "/lgjoin"),
    ("front",        "/"),
]


def gate_env():
    out = subprocess.run(["bash", os.path.join(HERE, "gate-env.sh")],
                         capture_output=True, text=True, timeout=30)
    env = {}
    for line in out.stdout.splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            env[k] = v
    if "LG_GATE_TOKEN" not in env or "LG_GATE_HOST" not in env:
        print("CANNOT RUN  gate-env.sh did not resolve a host/token:\n" + out.stdout + out.stderr)
        sys.exit(2)
    return env


class Session:
    def __init__(self):
        req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
        t = json.load(urllib.request.urlopen(req, timeout=15))
        self.target_id = t["id"]
        self.ws = websocket.create_connection(t["webSocketDebuggerUrl"], max_size=None,
                                              timeout=15, suppress_origin=True)
        self._id = 0

    def finish(self):
        for fn in (lambda: self.ws.close(),
                   lambda: urllib.request.urlopen(CDP + "/json/close/" + self.target_id, timeout=10).read()):
            try:
                fn()
            except Exception:                                  # noqa: BLE001
                pass

    def call(self, method, **params):
        self._id += 1
        self.ws.send(json.dumps({"id": self._id, "method": method, "params": params}))
        while True:
            m = json.loads(self.ws.recv())
            if m.get("id") == self._id:
                if "error" in m:
                    raise RuntimeError(f"{method}: {m['error']}")
                return m.get("result", {})

    def js(self, expr, quiet=False):
        r = self.call("Runtime.evaluate", expression=expr, returnByValue=True, awaitPromise=True)
        if r.get("exceptionDetails"):
            if quiet:
                return None
            raise RuntimeError("JS threw: " + str(r["exceptionDetails"].get("text"))[:160])
        return r.get("result", {}).get("value")

    def goto(self, url, settle=2.2, deadline=25.0):
        self.call("Page.navigate", url=url)
        start = time.monotonic()
        while time.monotonic() - start < deadline:
            time.sleep(0.15)
            try:
                if self.js("document.readyState", quiet=True) == "complete":
                    break
            except Exception:                                  # noqa: BLE001
                continue
        time.sleep(settle)


def arm_anon(s, tok):
    s.call("Network.clearBrowserCookies")
    s.call("Network.setCookie", name="loothdev_auth", value=tok,
           domain=".dev2.loothgroup.com", path="/", secure=True)


# ── STABILISE INJECTED CHROME BEFORE MEASURING ──────────────────────────────
# Root-caused by the featured-members seat, 2026-08-15, with the evidence I had
# been circling all night without naming: 10 of 24 surfaces changed counts on
# the SAME tree minutes apart, BIDIRECTIONALLY. It was never render timing. Two
# pieces of injected chrome move underneath the probe:
#
#   1. the PWA install banner — it has its own injection schedule (pv>=2 or a
#      scroll), so whether it EXISTS to be measured depends on navigation
#      history, not on the page under test
#   2. the notification badges — LIVE counts fetched at runtime, so their text
#      and their visibility both change between runs
#
# An instrument that reds at random blocks every train, so both are pinned here.
# This is a DISCLOSED exclusion, never a silent cap — see the gate's exit
# message, which says green is a floor.
#
# The banner is dismissed the same way tools/preview/category-door-shots.py
# already does it (click .lpw-x, else remove the node) rather than inventing a
# second mechanism for the same job.
#
# THE BADGES ARE PINNED TO THEIR TRUE ANON STATE, WHICH IS HIDDEN, and that is
# the honest choice rather than a convenient one: site-header.php renders
# `<span class="lg-chrome__badge" ... hidden>0</span>` whenever a viewer has no
# counts, so a logged-out visitor never sees a pip. The pips only appeared here
# because JS populates them at runtime using the dev-gate cookie. Forcing them
# VISIBLE to keep measuring them would have been measuring a fiction no anon
# visitor can see; hiding them measures what is actually served.
#
# CONSEQUENCE, STATED PLAINLY: the badge contrast defect (1.51:1, fixed behind
# LG_DARK_BADGE_INK_FIX) is no longer covered by THIS gate, and it should not
# be — it is a signed-in surface and this gate is the anon one. It needs a
# member-surface gate to own it. Recorded in the handoff so it is not lost.
STABILISE_JS = """(function(){
  // FREEZE TRANSITIONS BEFORE MEASURING. Resolution is not settlement, and the
  // precondition above returns the INSTANT the attribute appears — which made
  // this worse, not better, by probing earlier. 86.php animates .lg-acc's
  // background over 250ms (`transition: all .25s`) while its label colour snaps
  // instantly, and the login links interpolate colour over 50ms. Read inside
  // either window and you photograph an interpolated colour that no settled
  // page ever shows: the accordion reads 1.21:1 and the ToS/Privacy links read
  // 3.48:1, both of which are mid-animation frames, not states.
  //
  // Setting transition/animation to none snaps every in-flight property to its
  // final computed value, so the probe reads the state a visitor actually ends
  // up looking at. The USER-VISIBLE flash is a separate, real question — the
  // accordion one is a genuine 250ms defect and is fixed at source in 86.php —
  // but an instrument must measure a settled page or it cannot tell a defect
  // from a frame.
  try {
    var _t = document.createElement('style');
    _t.textContent = '*,*::before,*::after{transition:none !important;animation:none !important}';
    document.head.appendChild(_t);
  } catch (e) {}
  try {
    var x = document.querySelector('.lpw-x');
    if (x) { x.click(); }
    var b = document.getElementById('looth-pwa-banner');
    if (b) { b.remove(); }
  } catch (e) {}
  try {
    var pips = document.querySelectorAll('.lg-chrome__badge');
    for (var i = 0; i < pips.length; i++) {
      pips[i].hidden = true;
      pips[i].style.display = 'none';
    }
  } catch (e) {}
  return {banner: !document.getElementById('looth-pwa-banner'),
          pips: document.querySelectorAll('.lg-chrome__badge:not([hidden])').length};
})()"""


# ── DO NOT MEASURE AN UNRESOLVED PAGE ───────────────────────────────────────
# The gate used to probe on a WALL-CLOCK settle and then notice afterwards, and
# only on the app-dark path, that the theme had never resolved. That ordering is
# the bug: a page whose theme has not resolved yet is still showing its LIGHT
# paint, so the probe reads light ink on light backgrounds and reports enormous
# ratios-of-nothing — 1.2:1 findings against hex backgrounds that only exist in
# the light palette. Those are not defects on the page, they are photographs of
# a page mid-boot.
#
# Measured on this box, 2026-08-15, while verifying the .lgpo-subtext fix: two
# of four app-dark runs came back theme='default' and reported 3 findings each
# on a surface whose real dark count is 0, and one app-dark/desktop needed FOUR
# attempts before the theme resolved. A "0 findings, CLEARED" from one of those
# runs is not a pass — it measured the light page, where the dark rule under
# test does not even apply.
#
# So resolution is now a PRECONDITION, asserted before the probe walks anything,
# on BOTH dark paths rather than only app-dark. Polls the attribute app-settings.js
# actually sets instead of sleeping longer and hoping, so it is also faster on
# the common case: it returns the moment the page is ready.
def wait_dark_resolved(s, deadline=8.0):
    """True once <html data-lguser-theme='dark'> is set. Polls, never sleeps blind."""
    start = time.monotonic()
    while time.monotonic() - start < deadline:
        try:
            if s.js("document.documentElement.getAttribute('data-lguser-theme')",
                    quiet=True) == "dark":
                return True
        except Exception:                                      # noqa: BLE001
            pass
        time.sleep(0.2)
    return False


def measure(s, tok, host, probe_js, key, path_tpl, mode, device, metrics, extra_css=None,
            patience=1.0):
    """Arm anon, navigate into the requested dark state, and probe. Returns
    the raw probe result (theme, findings, truncated, ...). extra_css, when
    given, is appended as one more <style> tag right before probing — see
    FIX_VERIFY_CSS below for why and what it contains."""
    path = path_tpl.replace("{host}", host.replace("https://", "").replace("http://", ""))
    url = host + path
    arm_anon(s, tok)
    s.call("Emulation.setDeviceMetricsOverride", **metrics)
    s.call("Emulation.setTouchEmulationEnabled", enabled=metrics["mobile"])
    s.call("Emulation.setEmulatedMedia", features=[
        {"name": "prefers-color-scheme", "value": "dark" if mode == "os-dark" else "light"}])
    s.goto(url, settle=0.8)
    # ALWAYS clear first, then set — app-dark used to skip the clear, so a
    # prior os-dark surface's client-resolved dark write (app-settings.js's
    # apply() persists lg-set-boot after ANY successful dark resolution, not
    # just an explicit choice) leaked into the next app-dark test and made
    # it silently take the boot-script-pre-paint code path instead of the
    # cold one — order-dependent, same code, different measured findings
    # (caught 2026-08-15: two live runs of the same 24 surfaces disagreed by
    # several findings on repeat surfaces, off by more than render jitter
    # should explain). Every surface now starts from the SAME clean slate a
    # genuine first-time anon visitor has, matching the charter.
    s.js("try{localStorage.clear()}catch(e){}")
    if mode == "app-dark":
        s.js("try{localStorage.setItem('lg-set-theme','dark')}catch(e){}")
    s.goto(url, settle=1.6)
    if mode == "app-dark":
        s.goto(url, settle=2.0)

    # PRECONDITION: the theme must have resolved before anything is measured.
    # One extra navigation is allowed (this box's contention produces real,
    # transient misses); after that the surface is reported as unresolved and
    # its numbers are discarded rather than believed.
    resolved = wait_dark_resolved(s, deadline=8.0 * patience)
    if not resolved:
        s.goto(url, settle=2.4 * patience)
        resolved = wait_dark_resolved(s, deadline=8.0 * patience)

    if extra_css:
        s.js("""(function(css){
            var st = document.createElement('style');
            st.textContent = css;
            document.head.appendChild(st);
        })(arguments[0])""".replace("arguments[0]", json.dumps(extra_css)))
        time.sleep(0.6)   # let any CSS transition triggered by the late style
                           # tag settle — measured live, 2026-08-15: reading
                           # immediately after a style swap can catch a mid-
                           # transition interpolated colour and misreport a
                           # real fix as still broken (see the 86.php commit).
    s.js(STABILISE_JS)
    time.sleep(0.4)          # let the removal settle before the probe walks the DOM
    out = s.js(probe_js)
    # Carried so the caller can tell "this surface is genuinely clean" from
    # "this surface was never dark when we looked at it".
    if isinstance(out, dict):
        out["resolved"] = resolved
    return out


# ---- --verify-fixes: does gate 36's scope actually clear AA once the queued
# fixes are ON? ---------------------------------------------------------------
#
# WHY THIS EXISTS, AND WHY IT DOESN'T JUST RE-RUN THE NORMAL PASS. The default
# gate run above fetches the REAL SERVED PAGE — which is main, not this
# worktree, until merged (trap-harness-and-serve-answer-from-main). A fix that
# lands only in this branch can NEVER show green through that path; the gate
# would keep reporting the pre-fix baseline forever, regardless of how correct
# the fix is, because it is quite literally testing different code. So this
# mode does not wait for a merge or a lane-preview route (v2's own lane found
# that WordPress cannot serve a different plugin directory per-URL the way a
# static file can — not worth that machinery for a CSS-only change): it
# injects the SAME CSS these flags would add, as an EXTRA <style> tag layered
# on top of the normally-served page, using !important-strength selectors so
# it wins the cascade the identical way the real flagged code would once
# merged and flipped.
#
# THE VALUES ARE NOT HAND-COPIED HERE A SECOND TIME BLIND — every one below
# was independently verified twice already, in the commits that introduced
# it: once against the WCAG formula (node, matching contrast-probe.js's own
# math), and once by extracting each file's actual conditional expression and
# eval()-ing it for both flag states. This block is the union of what those
# already-verified expressions produce when every flag relevant to gate 36's
# OWN surfaces (signin/lostpassword/bpnoaccess/join/lgjoin/front) is ON.
#
# SCOPE NOTE: the search-wrapper fix (.hub-tsearch, forums.css/hub-polish.js)
# and the shop page's own --hair fix do NOT appear here — neither surface
# (/hub/, /shop/) is in GATED_SURFACES, so verifying them is the broader
# sweep's job (tools/preview/dark-anon-sweep.py), not this narrower gate.
FIX_VERIFY_CSS = """
html[data-lguser-theme="dark"] input,
html[data-lguser-theme="dark"] textarea,
html[data-lguser-theme="dark"] select { border-color: #767c76 !important; }
html[data-lguser-theme="dark"] .lg-set-opt { border-color: #767c76 !important; }
html[data-lguser-theme="dark"] .feed-sort-bar a,
html[data-lguser-theme="dark"] .feed-sort-bar button { border-color: #767c76 !important; }
html[data-lguser-theme="dark"] .lg-conn__search,
html[data-lguser-theme="dark"] .lg-msg__reply-input { border-color: #767c76 !important; }
html[data-lguser-theme="dark"] .lg-hub-search .ubar,
html[data-lguser-theme="dark"] .lg-hub-search input,
html[data-lguser-theme="dark"] .lgdm-ubar,
html[data-lguser-theme="dark"] .lgev-ubar { border-color: #767c76 !important; }
html[data-lguser-theme="dark"] #looth-tabbar .lt-post-ico svg { stroke: #15171a !important; }
"""

# A finding belongs to THIS wave (and must be gone once FIX_VERIFY_CSS is
# active) if it is the icon-control class, or a field-border/borderless
# reading whose broken colour was the OLD #333833/#2c312d token — matched by
# the fg (the ink actually rendered) rather than the selector, so this stays
# correct even if a selector gets refactored later. Anything else (WP-core's
# own Terms/Privacy links, .lgpo-subtext — both already disclosed as OUT OF
# SCOPE for this wave in the fix commits) is reported but does not fail this
# specific check; conflating "not everything is fixed yet" with "this wave's
# own fixes don't work" would make the assertion useless the moment a NEW,
# unrelated defect is found on the same page.
OLD_TOKEN_INKS = {"#333833", "#2c312d"}


def belongs_to_this_wave(finding):
    if finding["kind"] == "icon-control":
        return True
    if finding["kind"] in ("field-border", "field-borderless") and finding["fg"] in OLD_TOKEN_INKS:
        return True
    return False


def verify_fixes(host, tok, probe_js):
    print("\n=== --verify-fixes: injecting the queued fixes' CSS and re-measuring gate 36's own surfaces ===\n")
    s = Session()
    wave_red = []
    other_findings = []
    unresolved = []
    try:
        s.call("Page.enable"); s.call("Runtime.enable"); s.call("Network.enable")
        for device, metrics in (("desktop", DESKTOP), ("mobile", PHONE)):
            for mode in ("app-dark", "os-dark"):
                for key, path_tpl in GATED_SURFACES:
                    label = f"{key}/{mode}/{device}"
                    try:
                        data = measure(s, tok, host, probe_js, key, path_tpl, mode, device, metrics,
                                       extra_css=FIX_VERIFY_CSS)
                    except Exception as e:                     # noqa: BLE001
                        print(f"  WARN  {label}: {str(e)[:100]} — reconnecting")
                        try:
                            s.finish()
                        except Exception:                       # noqa: BLE001
                            pass
                        s = Session()
                        s.call("Page.enable"); s.call("Runtime.enable"); s.call("Network.enable")
                        data = measure(s, tok, host, probe_js, key, path_tpl, mode, device, metrics,
                                       extra_css=FIX_VERIFY_CSS)

                    # THE VACUOUS-CLEARED GUARD, and this mode needs it MOST.
                    # verify_fixes exists to answer "does the fix actually clear
                    # this?", and an unresolved page answers YES for free: the
                    # dark rule under test does not apply to a light page, so it
                    # reports zero this-wave findings having proven nothing. That
                    # is not hypothetical — it is exactly the false pass hit by
                    # hand on 2026-08-15 while verifying the .lgpo-subtext fix,
                    # where two of four ON-runs came back theme='default' and
                    # read "CLEARED". A fix-verification that cannot fail is
                    # worse than no verification, because it is believed.
                    if not data.get("resolved") or data.get("theme") != "dark":
                        unresolved.append(f"{label}  theme={data.get('theme')}")
                        print(f"  UNRESOLVED  {label}  theme={data.get('theme')} — "
                              f"NOT counted as cleared; the fix CSS was never exercised here")
                        continue

                    wave = [f for f in data.get("findings", []) if belongs_to_this_wave(f)]
                    rest = [f for f in data.get("findings", []) if not belongs_to_this_wave(f)]
                    for f in wave:
                        wave_red.append(f"RED  {label}  {f['kind']} {f['ratio']}:1  "
                                        f"{f['fg']} on {f['bg']}  [{f['sel'][-70:]}]  still broken with the fix ON")
                    for f in rest:
                        other_findings.append(f"{label}  {f['kind']} {f['ratio']}:1  "
                                              f"{f['fg']} on {f['bg']}  [{f['sel'][-60:]}]")
                    print(f"  ok   {label}  {len(wave)} this-wave finding(s), "
                          f"{len(rest)} other (out-of-scope) finding(s)")
    finally:
        s.finish()

    if unresolved:
        print(f"\n⚠ {len(unresolved)} surface(s) NEVER RESOLVED DARK — the fix CSS was not "
              f"exercised on them, so this run does NOT clear them. Re-run before believing "
              f"any 'cleared' verdict below:")
        for line in unresolved:
            print("    " + line)

    if other_findings:
        print(f"\n{len(other_findings)} out-of-scope finding(s) remain (not this wave's job, reported not failed):")
        for line in other_findings:
            print("  ", line)

    if wave_red:
        print(f"\n{len(wave_red)} finding(s) THIS WAVE CLAIMS TO FIX are still red with the fix CSS active:\n")
        for line in wave_red:
            print(line)
        sys.exit(1)

    # A run with unresolved surfaces has not verified them, so it must not claim
    # GREEN — "no findings" from a page the fix CSS never applied to is the
    # vacuous pass this whole change exists to stop, and printing GREEN under it
    # is how it would get believed.
    if unresolved:
        # Same doctrine as main(): no verdict is exit 2, never red. Nothing this
        # wave targets is failing — some surfaces simply were not measured.
        print(f"\nCANNOT RUN — nothing this wave targets is still red, but {len(unresolved)} "
              f"surface(s) never resolved dark and were NOT verified. This is not a GREEN, "
              f"and it is not a RED either: it is the absence of a measurement.")
        sys.exit(2)

    print("\nGREEN over the fixed set — every finding this wave (icon + border-token) targets clears AA "
          "on gate 36's own surfaces once the queued fixes are active.")
    sys.exit(0)


# ---- BASELINE / RATCHET ------------------------------------------------------
#
# WHY THIS EXISTS (keeper, 2026-08-15, catching it before merge): the default
# assertion below used to be "zero findings, full stop" — correct for the
# red-first PROOF this gate was built to give, but wrong for what run-all.sh
# needs from a MERGED gate. This backlog item names a genuinely large surface
# (every anon dark surface, site-wide) that will take multiple future waves to
# fully clear — the front page alone still carries 10 Guitardle-leaderboard
# findings that are a completely different lane's surface, not this one's to
# fix. A zero-findings assertion merged tonight would show RED forever, for
# reasons entirely outside this lane's remaining scope, and BLOCK EVERY OTHER
# LANE'S TRAIN — worse than not having the gate at all.
#
# THE FIX IS A RATCHET, NOT A RETREAT. Per surface, the assertion becomes
# "no MORE findings than the recorded baseline" rather than "none at all" —
# it still catches the thing a red-first gate exists to catch (a REGRESSION:
# someone introduces a NEW dark-contrast defect on one of these six surfaces),
# it just stops blocking on debt this lane already disclosed and is still
# working through in separate, explicitly-scoped commits. Considered splitting
# the wiring out of this merge instead (leave the gate script in the repo,
# register it in run-all.sh only once the whole wave lands) — rejected,
# because there is no clean "the wave is done" line to draw for a surface this
# size, and an unwired gate protects nothing in the meantime. Same failure
# class as "a gate that guards an unrecallable channel and is never promoted
# is worse than no gate" — a principle this codebase already holds elsewhere.
#
# THE RATCHET ONLY TIGHTENS. When a future commit fixes more findings on one
# of these surfaces, LOWER that surface's number in BASELINE in the SAME
# commit — a baseline that never comes down defeats the entire point and just
# freezes today's debt in amber forever. Raising a number here should need the
# same scrutiny as any other test getting weaker: a real, disclosed reason,
# never a quiet fix for a locally-annoying red.
#
# CAPTURED 2026-08-15, matching the TRUE post-merge state — not carried over
# from an earlier pre-fix run, and not the live page as it stands RIGHT NOW
# (pre-merge main still lacks even the unflagged fixes). Built the same way
# --verify-fixes works: inject exactly the CSS the ALREADY-LANDED, UNFLAGGED
# fixes add (lg-snippets/snippets/86.php's dark block for the wp-login.php
# surfaces, membership-pages/web/join.css's for /join, membership-pages/web/
# _admin-gate.php's for /lgjoin) on top of the normally-served page, and
# nothing for the three surfaces reached only through flags that stay OFF
# (icon/border-token/search-wrapper — /front carries no unflagged fix at all).
# Regenerate by re-running that same injection sweep if the unflagged fixes
# ever change shape.
# RE-BASELINED 2026-08-15 on the HARDENED instrument (the resolve precondition
# above). Two independent full runs, back to back on the same tree, agreed on
# ALL 24 SURFACES — zero disagreement, against 13-of-24 and then 4-of-24 with
# the old wall-clock method. That agreement is the evidence these numbers are
# measurements rather than samples of noise, which is what the old max-of-two
# padding existed to absorb.
#
# What came down, and why it is not a claim that anyone fixed anything:
#   signin/os-dark/*             7  ->  1
#   lostpassword/os-dark/*       3  ->  0
#   lostpassword/app-dark/*      1  ->  0
#   bpnoaccess/os-dark/desktop  10  ->  1
# Nothing on those pages changed. The old numbers were inflated by pre-resolve
# phantoms, so this is the instrument getting honest, not the product improving.
#
# UPDATE, later the same day: that happened, exactly as written. The
# .lgpo-subtext fix merged and reached the serve, and signin AND bpnoaccess both
# went 1 -> 0 on all four states. Lowered here in the same commit as the
# measurement, per the note that used to live in this paragraph.
#
# NOT lowered on one run alone. The zeros are CAUSALLY confirmed rather than
# merely repeated: the served bytes now contain
# `html[data-lguser-theme='dark'] .lgpo-subtext { color: #a8ada6; }`, checked by
# curling the real page. A cause beats a second correlated sample — and the same
# curl confirms the accordion's `transition: all 0.25s ease` is STILL on the
# serve, so LG_DARK_ACC_FLASH_FIX has not deployed yet and none of these zeros
# are borrowing credit from it.
#
# signin carried the identical finding because it renders the same
# [lg_patreon_onboard] shortcode — one fix, two surfaces, which is why both moved
# together.
#
# front/* 10 is the Guitardle leaderboard, explicitly another lane's surface.
BASELINE = {
    "signin/app-dark/desktop": 0, "signin/app-dark/mobile": 0,
    "signin/os-dark/desktop": 0, "signin/os-dark/mobile": 0,
    "lostpassword/app-dark/desktop": 0, "lostpassword/app-dark/mobile": 0,
    "lostpassword/os-dark/desktop": 0, "lostpassword/os-dark/mobile": 0,
    "bpnoaccess/app-dark/desktop": 0, "bpnoaccess/app-dark/mobile": 0,
    "bpnoaccess/os-dark/desktop": 0, "bpnoaccess/os-dark/mobile": 0,
    "join/app-dark/desktop": 0, "join/app-dark/mobile": 0,
    "join/os-dark/desktop": 0, "join/os-dark/mobile": 0,
    "lgjoin/app-dark/desktop": 0, "lgjoin/app-dark/mobile": 0,
    "lgjoin/os-dark/desktop": 0, "lgjoin/os-dark/mobile": 0,
    "front/app-dark/desktop": 10, "front/app-dark/mobile": 10,
    "front/os-dark/desktop": 10, "front/os-dark/mobile": 10,
}
# ── RE-BASELINED ON A FIXED INSTRUMENT, 2026-08-15 ──────────────────────────
# This replaces four stacked rationale blocks that had accreted through the day,
# each explaining a different pad added to absorb variance. They are gone because
# the variance was not real: the INSTRUMENT was photographing injected chrome.
# See STABILISE_JS above for the root cause, which the featured-members seat
# found, not me — I had been calling it "CPU-contention render timing" and
# padding around it for hours. Padding a noisy instrument treats the symptom.
#
# WHAT THE FIX WAS WORTH, measured rather than asserted:
#   run-to-run disagreement   13 of 24 surfaces  ->  4 of 24
#   floor                     135                ->  77
#   bpnoaccess/app-dark/desktop  12 findings     ->  1
# That last one is the headline: eleven of its twelve "defects" were a banner and
# a badge that move on their own schedule, not anything on the page.
#
# THE REMAINING 4 ARE ALL os-dark, WHICH IS A CAUSE AND NOT A SHRUG. app-dark
# gets the nginx boot script's pre-paint, so the theme is settled before first
# paint. os-dark has no boot script at all — dark is resolved CLIENT-SIDE by
# app-settings.js's matchMedia, so how much of the page has painted when the
# probe reads it genuinely varies. signin/os-dark/desktop read 1 then 7. The
# honest fix is to wait on an explicit signal from app-settings.js rather than a
# timer; until then this floor is the per-surface MAX of both runs.
#
# One run is still not a distribution. That rule did not stop being true because
# the instrument got better — it is why this is two runs and not one.
def ratchet_verdict(label, findings, baseline):
    """Pure decision, no I/O — the part that most needs to be provably
    correct before it gates every lane's merge train. Returns
    (status, detail) where status is 'RED' | 'IMPROVED' | 'OK', never
    raises, and treats a surface with NO baseline entry as baseline 0 (any
    finding on a surface nobody captured a baseline for is new by
    definition, not silently waved through)."""
    base = baseline.get(label, 0)
    n = len(findings)
    if n > base:
        return "RED", f"{n} finding(s), baseline was {base} — {n - base} NEW dark-contrast defect(s)"
    if n < base:
        return "IMPROVED", f"{n} finding(s), baseline was {base} — consider lowering BASELINE[{label!r}] to {n}"
    return "OK", f"{n} finding(s), matches baseline"


def _ratchet_selftest():
    """Red-first for the ratchet mechanism ITSELF, run before every real gate
    pass — no browser needed, pure logic. Proves: a genuine regression (MORE
    findings than baseline) reddens; holding steady stays green; improving
    stays green and says so; an unknown surface treats ANY finding as new
    rather than waving it through with an absent baseline."""
    mk = lambda n: [{"kind": "text", "ratio": 1, "need": 4.5, "fg": "#000", "bg": "#fff",
                     "sel": "x", "sample": "x"} for _ in range(n)]
    cases = [
        ("holds steady",        "join/app-dark/desktop", mk(0), {"join/app-dark/desktop": 0}, "OK"),
        ("regression",          "join/app-dark/desktop", mk(3), {"join/app-dark/desktop": 0}, "RED"),
        ("improvement",         "signin/app-dark/desktop", mk(1), {"signin/app-dark/desktop": 3}, "IMPROVED"),
        ("unknown surface, 0",  "new-page/app-dark/desktop", mk(0), {}, "OK"),
        ("unknown surface, >0", "new-page/app-dark/desktop", mk(1), {}, "RED"),
    ]
    failed = []
    for name, label, findings, baseline, expect in cases:
        status, detail = ratchet_verdict(label, findings, baseline)
        ok = status == expect
        print(f"  {'ok  ' if ok else 'FAIL'} ratchet self-test: {name} -> {status} ({detail})"
              + ("" if ok else f"  EXPECTED {expect}"))
        if not ok:
            failed.append(name)
    if failed:
        print(f"\nCANNOT RUN  ratchet self-test failed: {failed} — the comparison logic itself is "
              f"broken, not trusting it to gate anything until this is fixed")
        sys.exit(2)
    print("  ratchet self-test: all cases correct\n")


def main():
    _ratchet_selftest()
    env = gate_env()
    host = env["LG_GATE_HOST"]
    tok = env["LG_GATE_TOKEN"]
    probe_js = pathlib.Path(PROBE).read_text()

    s = Session()
    red = []
    cannot_run = []
    try:
        s.call("Page.enable"); s.call("Runtime.enable"); s.call("Network.enable")
        for device, metrics in (("desktop", DESKTOP), ("mobile", PHONE)):
            for mode in ("app-dark", "os-dark"):
                for key, path_tpl in GATED_SURFACES:
                    label = f"{key}/{mode}/{device}"
                    data = None
                    # ONE reconnect on a dead/timed-out connection — same
                    # shape as tools/preview/dark-anon-sweep.py, and for the
                    # same reason: a single stuck CDP call otherwise takes
                    # down every remaining surface behind it, and this box's
                    # contention is real (measured live, 2026-08-14/15).
                    for attempt in range(2):
                        try:
                            data = measure(s, tok, host, probe_js, key, path_tpl, mode, device, metrics)
                            break
                        except Exception as e:                 # noqa: BLE001
                            print(f"  WARN  {label}: {str(e)[:100]}"
                                  + (" — reconnecting" if attempt == 0 else ""))
                            try:
                                s.finish()
                            except Exception:                   # noqa: BLE001
                                pass
                            s = Session()
                            s.call("Page.enable"); s.call("Runtime.enable"); s.call("Network.enable")
                    if data is None:
                        cannot_run.append(f"{label}: connection failed twice")
                        continue

                    # LIVENESS, not just absence, and now on BOTH dark paths
                    # rather than only app-dark. measure() has already asserted
                    # resolution as a precondition and re-navigated once; if the
                    # theme STILL never resolved, this surface was photographed
                    # mid-boot and its numbers are worthless in both directions:
                    # a 0 is unearned (the dark rules never applied) and a >0 is
                    # a phantom (light ink read against the light palette, the
                    # 1.2:1-on-a-light-hex signature).
                    #
                    # So the findings are DISCARDED, not ratcheted. Reporting
                    # them as regressions is precisely the false-red epidemic
                    # this change exists to end — but silently passing the
                    # surface would be worse, so it is called out by name.
                    if not data.get("resolved") or data.get("theme") != "dark":
                        # ONE MORE TRY WITH DOUBLED PATIENCE, then NO VERDICT.
                        # Keeper, 2026-08-15, after two surfaces reddened a whole
                        # train under suite load: discarding the findings was
                        # right, but exiting RED for it was not. run-all.sh reads
                        # 0 green / 2 CANNOT RUN / anything else RED, and "the
                        # page never got dark enough to measure" is not a defect
                        # in the page — it is the absence of a measurement. Red
                        # for it blocks every other lane for a box-load problem,
                        # which is the same false-red disease one layer up.
                        try:
                            data = measure(s, tok, host, probe_js, key, path_tpl,
                                           mode, device, metrics, patience=2.0)
                        except Exception as e:                  # noqa: BLE001
                            cannot_run.append(f"{label}: dark never resolved; retry errored ({str(e)[:60]})")
                            print(f"  CANNOT RUN  {label}  dark never resolved, retry errored")
                            continue
                    if not data.get("resolved") or data.get("theme") != "dark":
                        cannot_run.append(
                            f"{label}: DARK NEVER RESOLVED (theme={data.get('theme')}) even at "
                            f"doubled patience — findings DISCARDED, neither its zeros nor its "
                            f"findings are real. NO VERDICT on this surface; re-run on a quieter box.")
                        print(f"  CANNOT RUN  {label}  dark never resolved (theme={data.get('theme')}) "
                              f"— no verdict, findings discarded")
                        continue

                    # RATCHET, not "any finding at all" — see BASELINE's own
                    # comment for why. A REGRESSION (more findings than the
                    # recorded baseline) is red; holding steady or improving
                    # is not, even though findings remain on the page — this
                    # gate's job now is "did this branch make it WORSE",
                    # which is a different, narrower question than "is it
                    # perfect", and conflating them is exactly what would
                    # have blocked every other lane's train tonight.
                    findings = data.get("findings", [])
                    status, detail = ratchet_verdict(label, findings, BASELINE)
                    if status == "RED":
                        red.append(f"RED  {label}  {detail}")
                        for f in findings:
                            red.append(
                                f"     {label}  {f['kind']} {f['ratio']}:1 (need {f['need']}:1)  "
                                f"{f['fg']} on {f['bg']}  [{f['sel'][-70:]}]  \"{f['sample'][:50]}\"")
                    elif status == "IMPROVED":
                        print(f"  IMPROVED  {label}  {detail}")
                    if data.get("truncated"):
                        print(f"  WARN  {label}  scan truncated ({data['truncReason']}, "
                              f"{data['scannedElements']}/{data['totalElements']} elements) — "
                              f"findings below are a LOWER BOUND, not exhaustive")
                    print(f"  ok   {label}  {len(findings)} finding(s) (baseline {BASELINE.get(label, 0)}), "
                          f"theme={data.get('theme')}")
    finally:
        s.finish()

    # RED beats CANNOT RUN beats GREEN — same priority run-all.sh itself uses
    # (it checks $red before $dead). A real, confirmed finding on one surface
    # must not be swallowed by an unrelated connection failure on another; the
    # inverse (reporting only "cannot run" while hiding 5 confirmed defects)
    # would understate a genuinely broken state.
    if cannot_run:
        print(f"\n{len(cannot_run)} surface(s) COULD NOT BE MEASURED:")
        for line in cannot_run:
            print(" ", line)

    if red:
        print(f"\n{len(red)} line(s) — a surface regressed past its recorded BASELINE:\n")
        for line in red:
            print(line)
        sys.exit(1)

    if cannot_run:
        print("\nCANNOT RUN — no verdict on the surfaces above. Not a pass.")
        sys.exit(2)

    print("\nGREEN — no surface has MORE dark-contrast findings than its recorded baseline. "
          "This is a floor, not a finish line: run tools/preview/dark-anon-sweep.py for the full "
          "ranked picture, and see BASELINE's own comment before assuming 'green' means 'done'.")
    sys.exit(0)


if __name__ == "__main__":
    if "--verify-fixes" in sys.argv:
        _env = gate_env()
        verify_fixes(_env["LG_GATE_HOST"], _env["LG_GATE_TOKEN"], pathlib.Path(PROBE).read_text())
    else:
        main()
