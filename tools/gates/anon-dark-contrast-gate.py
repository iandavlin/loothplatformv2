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
picked nothing and their OS is dark) at desktop AND mobile:

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

RED-FIRST, TODAY, ON PURPOSE. This gate is written and run BEFORE any fix lands
(charter METHOD step 2: gate before fixing) so its current failure is the
baseline, not a regression. It has no flag to read yet because the fix has not
been designed — when a fix ships flagged member-visible-off-by-default, extend
this gate to assert per-state (flag OFF == today's numbers, byte-identical
no-op; flag ON == the AA bar met) rather than replacing the OFF-state assertion.

Usage:  python3 tools/gates/anon-dark-contrast-gate.py
          Default mode — the red-first baseline, against the REAL served page
          (main), unconditionally. This is what run-all.sh runs.
        python3 tools/gates/anon-dark-contrast-gate.py --verify-fixes
          Injects the queued fixes' CSS (icon + border-token; see
          FIX_VERIFY_CSS below) as an extra layer on top of the normally-
          served page and asserts THOSE specific classes clear AA — proves
          the fix values are correct without needing a merge or a lane-
          preview route. Out-of-scope findings this wave does not touch are
          reported, not failed. Manual verification step, not run by
          run-all.sh (that always wants the true default-state baseline).
Needs:  chrome-dev on 127.0.0.1:9222, tools/gates/gate-env.sh resolving a token.
Exit 0 = GREEN (nothing below AA on the named surfaces). Exit 1 = RED, one line
per finding. Exit 2 = CANNOT RUN (never silently exit 0 on a broken harness —
see trap-gate-exit-code-3-blocks-every-lane in keeper memory: an open defect is
exit 1, never a code that run-all.sh reads as "could not run").
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


def measure(s, tok, host, probe_js, key, path_tpl, mode, device, metrics, extra_css=None):
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
    if mode == "app-dark":
        s.js("try{localStorage.setItem('lg-set-theme','dark')}catch(e){}")
    else:
        s.js("try{localStorage.clear()}catch(e){}")
    s.goto(url, settle=1.6)
    if mode == "app-dark":
        s.goto(url, settle=2.0)
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
    return s.js(probe_js)


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

    if other_findings:
        print(f"\n{len(other_findings)} out-of-scope finding(s) remain (not this wave's job, reported not failed):")
        for line in other_findings:
            print("  ", line)

    if wave_red:
        print(f"\n{len(wave_red)} finding(s) THIS WAVE CLAIMS TO FIX are still red with the fix CSS active:\n")
        for line in wave_red:
            print(line)
        sys.exit(1)

    print("\nGREEN over the fixed set — every finding this wave (icon + border-token) targets clears AA "
          "on gate 36's own surfaces once the queued fixes are active.")
    sys.exit(0)


def main():
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

                    # LIVENESS, not just absence. A page that silently stayed
                    # LIGHT would report zero findings and this gate would go
                    # green having measured nothing — the exact class this
                    # whole backlog item is about. One retry with more settle
                    # time first (this box's contention produces real,
                    # transient timing misses — confirmed on the sweep's
                    # first-ever run, 2/48 rows, both cleared on a retry); if
                    # it still won't resolve dark, that IS a finding, not a
                    # thing to shrug past.
                    if mode == "app-dark" and data.get("theme") != "dark":
                        try:
                            data2 = measure(s, tok, host, probe_js, key, path_tpl, mode, device, metrics)
                            if data2.get("theme") == "dark":
                                data = data2
                            else:
                                red.append(f"RED  {label}  DARK NEVER RESOLVED (theme={data2.get('theme')}) "
                                          f"— every 'clean' finding below this surface is unearned, "
                                          f"it was measured in LIGHT")
                        except Exception:                       # noqa: BLE001
                            red.append(f"RED  {label}  DARK NEVER RESOLVED and retry errored")

                    for f in data.get("findings", []):
                        red.append(
                            f"RED  {label}  {f['kind']} {f['ratio']}:1 (need {f['need']}:1)  "
                            f"{f['fg']} on {f['bg']}  [{f['sel'][-70:]}]  \"{f['sample'][:50]}\"")
                    if data.get("truncated"):
                        print(f"  WARN  {label}  scan truncated ({data['truncReason']}, "
                              f"{data['scannedElements']}/{data['totalElements']} elements) — "
                              f"findings below are a LOWER BOUND, not exhaustive")
                    print(f"  ok   {label}  {len(data.get('findings', []))} finding(s), "
                          f"theme={data.get('theme')}")
    finally:
        s.finish()

    # RED beats CANNOT RUN beats GREEN — same priority run-all.sh itself uses
    # (it checks $red before $dead). A real, confirmed finding on one surface
    # must not be swallowed by an unrelated connection failure on another; the
    # inverse (reporting only "cannot run" while hiding 5 confirmed defects)
    # would understate a genuinely broken state.
    if cannot_run:
        print(f"\n{len(cannot_run)} surface(s) COULD NOT BE MEASURED (connection failed twice each):")
        for line in cannot_run:
            print(" ", line)

    if red:
        print(f"\n{len(red)} finding(s) below WCAG AA on anon dark sign-in/join surfaces:\n")
        for line in red:
            print(line)
        sys.exit(1)

    if cannot_run:
        print("\nCANNOT RUN — no verdict on the surfaces above. Not a pass.")
        sys.exit(2)

    print("\nGREEN — every anon sign-in/join surface clears AA contrast in both dark paths.")
    sys.exit(0)


if __name__ == "__main__":
    if "--verify-fixes" in sys.argv:
        _env = gate_env()
        verify_fixes(_env["LG_GATE_HOST"], _env["LG_GATE_TOKEN"], pathlib.Path(PROBE).read_text())
    else:
        main()
