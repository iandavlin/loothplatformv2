#!/usr/bin/env python3
"""
anon-dark-contrast-gate — GATE <<NUMBER-TBD-FROM-KEEPER>>/33 — does a LOGGED-OUT
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
KEEPER GATE NUMBER: per charter ("gate number FROM KEEPER — ask; never mint") —
requested 2026-08-14, filled in here once assigned. Do not renumber without
asking; two lanes have collided minting 9/9 independently before.

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

Usage:  python3 tools/gates/anon-dark-contrast-gate.py [--url https://dev2.loothgroup.com]
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
        t = json.load(urllib.request.urlopen(req))
        self.target_id = t["id"]
        self.ws = websocket.create_connection(t["webSocketDebuggerUrl"], max_size=None,
                                              timeout=15, suppress_origin=True)
        self._id = 0

    def finish(self):
        for fn in (lambda: self.ws.close(),
                   lambda: urllib.request.urlopen(CDP + "/json/close/" + self.target_id).read()):
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


def main():
    env = gate_env()
    host = env["LG_GATE_HOST"]
    tok = env["LG_GATE_TOKEN"]
    probe_js = pathlib.Path(PROBE).read_text()

    s = Session()
    red = []
    try:
        s.call("Page.enable"); s.call("Runtime.enable"); s.call("Network.enable")
        for device, metrics in (("desktop", DESKTOP), ("mobile", PHONE)):
            s.call("Emulation.setDeviceMetricsOverride", **metrics)
            s.call("Emulation.setTouchEmulationEnabled", enabled=metrics["mobile"])
            for mode in ("app-dark", "os-dark"):
                s.call("Emulation.setEmulatedMedia", features=[
                    {"name": "prefers-color-scheme", "value": "dark" if mode == "os-dark" else "light"}])
                for key, path_tpl in GATED_SURFACES:
                    path = path_tpl.replace("{host}", host.replace("https://", "").replace("http://", ""))
                    url = host + path
                    arm_anon(s, tok)
                    s.goto(url, settle=0.8)
                    if mode == "app-dark":
                        s.js("try{localStorage.setItem('lg-set-theme','dark')}catch(e){}")
                    else:
                        s.js("try{localStorage.clear()}catch(e){}")
                    s.goto(url, settle=1.6)
                    if mode == "app-dark":
                        s.goto(url, settle=2.0)

                    try:
                        data = s.js(probe_js)
                    except Exception as e:                     # noqa: BLE001
                        print(f"CANNOT RUN  {key}/{mode}/{device}: probe threw: {str(e)[:160]}")
                        sys.exit(2)

                    label = f"{key}/{mode}/{device}"
                    for f in data.get("findings", []):
                        red.append(
                            f"RED  {label}  {f['kind']} {f['ratio']}:1 (need {f['need']}:1)  "
                            f"{f['fg']} on {f['bg']}  [{f['sel'][-70:]}]  \"{f['sample'][:50]}\"")
                    if data.get("truncated"):
                        print(f"WARN  {label}  scan truncated ({data['truncReason']}, "
                              f"{data['scannedElements']}/{data['totalElements']} elements) — "
                              f"findings below are a LOWER BOUND, not exhaustive")
                    print(f"  ok   {label}  {len(data.get('findings', []))} finding(s), "
                          f"theme={data.get('resolvedTheme')}")
    finally:
        s.finish()

    if red:
        print(f"\n{len(red)} finding(s) below WCAG AA on anon dark sign-in/join surfaces:\n")
        for line in red:
            print(line)
        sys.exit(1)

    print("\nGREEN — every anon sign-in/join surface clears AA contrast in both dark paths.")
    sys.exit(0)


if __name__ == "__main__":
    main()
