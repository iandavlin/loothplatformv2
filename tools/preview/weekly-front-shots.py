#!/usr/bin/env python3
"""
weekly-front-shots — render the backlog-8 mock panel in a real browser at both
widths x both themes, screenshot each, and measure the contrast of the ONE new
component in every state.

WHY A SCRIPT AND NOT A LOOK. Two lane memories say a look is not evidence here:
dark contrast on logged-out surfaces has bitten 3+ times (backlog 21), and a
mock that renders in the docroot gets app chrome injected on top of the very
control it was drawn to show. So: measure, then publish.

WHY IT CAN TRUST WHAT IT SEES. The panel is loaded DIRECTLY (top-level) here,
which is the harshest case — /pwa.js runs. In the decision page it is framed,
where pwa.js line 13 (`if (window.top !== window.self) return;`) makes it bail.
If the numbers are clean top-level, they are clean framed.

Usage:  python3 tools/preview/weekly-front-shots.py [--out DIR]
Needs:  chrome-dev on 127.0.0.1:9222, tools/gates/gate-env.sh resolving a token.
Exit:   0 all states clear AA, 1 a finding, 2 cannot run.
"""
import argparse
import base64
import json
import os
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
REPO = os.path.dirname(os.path.dirname(HERE))
PROBE = os.path.join(REPO, "tools", "gates", "lib", "contrast-probe.js")

DESKTOP = {"width": 1280, "height": 900, "mobile": False, "deviceScaleFactor": 1}
PHONE = {"width": 390, "height": 844, "mobile": True, "deviceScaleFactor": 2}

# The new component only. The surrounding rows are already-shipping front-page
# furniture measured by gate 36; re-asserting them here would just re-report
# somebody else's baseline as this lane's finding.
NEW_COMPONENT = ".wkiss, .wkfr, .nowline"
# cssPath() strings the probe emits are class-bearing, so match on the class
# names the new component owns.
NEW_CLASSES = ("wkiss", "wkfr", "nowline")

STATES = [(o, t, d)
          for o in ("now", "a", "b")
          for t in ("light", "dark")
          for d in ("desktop", "phone")]


def gate_env():
    out = subprocess.run(["bash", os.path.join(REPO, "tools", "gates", "gate-env.sh")],
                         capture_output=True, text=True, timeout=30)
    env = dict(line.split("=", 1) for line in out.stdout.splitlines() if "=" in line)
    if "LG_GATE_TOKEN" not in env or "LG_GATE_HOST" not in env:
        print("CANNOT RUN  gate-env.sh did not resolve a host/token")
        sys.exit(2)
    return env


class Session:
    def __init__(self):
        req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
        t = json.load(urllib.request.urlopen(req, timeout=15))
        self.target_id = t["id"]
        self.ws = websocket.create_connection(t["webSocketDebuggerUrl"], max_size=None,
                                              timeout=20, suppress_origin=True)
        self._id = 0

    def finish(self):
        for fn in (lambda: self.ws.close(),
                   lambda: urllib.request.urlopen(CDP + "/json/close/" + self.target_id, timeout=10).read()):
            try:
                fn()
            except Exception:                                   # noqa: BLE001
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
            raise RuntimeError("JS threw: " + str(r["exceptionDetails"].get("text"))[:200])
        return r.get("result", {}).get("value")

    def goto(self, url, settle=2.0, deadline=30.0):
        self.call("Page.navigate", url=url)
        start = time.monotonic()
        while time.monotonic() - start < deadline:
            time.sleep(0.15)
            if self.js("document.readyState", quiet=True) == "complete":
                break
        time.sleep(settle)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=os.path.join(REPO, "footer-mockups", "weekly-front", "shots"))
    # ── MEASURE THE REAL PAGE, NOT ONLY THE MOCK ───────────────────────────
    # The mock proved the component's colours before it existed. Once the block
    # is actually on, the thing that matters is the SHIPPED rendering, which
    # differs from the mock in ways that matter: it inherits the live token
    # values (the mock pins its own), it sits in the real page's cascade, and
    # its theme comes from the visitor's app-settings rather than a query
    # string. --url points this at the real front page instead.
    #   --url https://dev2.loothgroup.com/            (anon)
    #   --url 'https://dev2.loothgroup.com/?as=public' (logged-in, viewed as anon)
    ap.add_argument("--url", default=None,
                    help="measure this URL's .wkiss block instead of the mock panels")
    args = ap.parse_args()
    os.makedirs(args.out, exist_ok=True)

    env = gate_env()
    host, tok, domain = env["LG_GATE_HOST"], env["LG_GATE_TOKEN"], env["LG_GATE_DOMAIN"]
    probe_js = open(PROBE, encoding="utf-8").read()

    s = Session()
    findings, rows = [], []
    try:
        s.call("Page.enable")
        s.call("Runtime.enable")
        s.call("Network.enable")
        # Dotted domain: a host-only cookie is not sent for every path the page
        # pulls, and the shared profile then ADDS rather than replaces.
        s.call("Network.clearBrowserCookies")
        s.call("Network.setCookie", name="loothdev_auth", value=tok,
               domain="." + domain, path="/", secure=True)

        states = STATES
        if args.url:
            # The real page has no ?o= and no ?t=: it renders one state, the
            # one the visitor's own theme gives it. So both widths, once each,
            # and the theme is REPORTED rather than asserted — asserting a theme
            # this run does not control is how a suite goes green having
            # measured the wrong thing.
            states = [("live", "as-served", d) for d in ("desktop", "phone")]

        for opt, theme, dev in states:
            m = DESKTOP if dev == "desktop" else PHONE
            s.call("Emulation.setDeviceMetricsOverride", **m)
            url = args.url or f"{host}/footer-mockups/weekly-front/panel.html?o={opt}&t={theme}"
            boot_before = s.js("localStorage.getItem('lg-set-boot')", quiet=True)
            s.goto(url)

            # LIVENESS FIRST. A locked-out browser serves a styled 403 that is
            # identical in every state, and a visual run then passes having
            # measured nothing (lane memory, 2026-08-13).
            # Liveness, and on the real page it must be the BLOCK ITSELF: a
            # front page that renders fine but has the flag off would otherwise
            # pass having measured nothing at all, which is the whole vacuous-
            # green failure class this lane keeps meeting.
            alive = s.js("!!document.querySelector('.wkiss__mast')") if args.url \
                else s.js("!!document.querySelector('.row--featured-member .lg-fm__name')")
            if not alive:
                print(f"CANNOT RUN  {opt}/{theme}/{dev}: "
                      + ("the .wkiss block is not on this page — flag off, member "
                         "viewer, or no sent issue" if args.url
                         else "front-page chrome absent (gate cookie? 403 shell?)")
                      + f" at {url}")
                sys.exit(2)

            # Is the theme the one that was asked for? Assert BOTH directions:
            # the first pass of this script had every dark state silently render
            # light, and the second had every light state render dark after a
            # stray attribute stamp had flipped the shared browser profile.
            # Either way a one-sided check would have passed.
            bg = s.js("getComputedStyle(document.body).backgroundColor")
            is_dark = bg.replace(" ", "") == "rgb(21,23,26)"
            is_light = bg.replace(" ", "") == "rgb(252,252,249)"
            if args.url:
                pass                       # theme is reported, not asserted
            elif theme == "dark" and not is_dark:
                findings.append(f"{opt}/{dev}: ?t=dark did not render dark (body bg {bg})")
            elif theme == "light" and not is_light:
                findings.append(f"{opt}/{dev}: ?t=light did not render light (body bg {bg})")

            # AND that loading the panel did not CHANGE the shared browser's
            # stored theme. An early draft stamped data-lguser-theme, which
            # app-settings.js then persisted, and every lane's chrome went dark
            # for a run. A mock must leave the box as it found it.
            #
            # BEFORE-vs-AFTER, not an absolute value, and that distinction is
            # the whole assertion. The first version asserted "lg-set-boot must
            # not say dark" and reddened 9 of 12 states on a panel that no
            # longer touches the theme at all: this is a SHARED profile, and
            # other lanes' app-dark gate runs write that key legitimately while
            # this script is running. Testing the absolute value measured the
            # rest of the fleet; testing the delta measures the panel.
            boot_after = s.js("localStorage.getItem('lg-set-boot')", quiet=True)
            if not args.url and boot_before is not None and boot_after != boot_before:
                findings.append(f"{opt}/{theme}/{dev}: loading the panel CHANGED the shared chrome "
                                f"profile's stored theme (lg-set-boot) — it must touch nothing "
                                f"outside itself")

            # The probe is a self-executing expression that scans the WHOLE
            # document and returns {findings:[{sel,ratio,need,fg,bg,sample}]}.
            # It therefore also measures the front-page furniture lifted in for
            # context — which is gate 36's baseline, not this lane's diff. Keep
            # only what sits inside the one new component, and SAY how many were
            # dropped, so a filtered run can never read as a clean sweep.
            res = s.js(probe_js)
            if not isinstance(res, dict):
                print(f"CANNOT RUN  contrast probe returned {type(res).__name__} at {url}")
                sys.exit(2)
            allf = res.get("findings") or []
            # Inside the component but NOT inside an .rcard: the cards are the
            # front page's own, already in gate 36's baseline (their .author ink
            # is --lg-rust at 3.84:1 on white, everywhere on the site). Counting
            # them here would report the platform's debt as this lane's finding.
            mine = [f for f in allf
                    if any(c in (f.get("sel") or "") for c in NEW_CLASSES)
                    and "rcard" not in (f.get("sel") or "")]
            other = len(allf) - len(mine)
            for f in mine:
                findings.append(
                    f"{opt}/{theme}/{dev}: {f.get('kind')} {f.get('sel')} "
                    f"{f.get('ratio')}:1 (needs {f.get('need')}) {f.get('fg')} on {f.get('bg')} "
                    f"— {(f.get('sample') or '')[:48]!r}")
            bad = mine

            png = s.call("Page.captureScreenshot", format="png", captureBeyondViewport=True)
            path = os.path.join(args.out, f"{opt}-{theme}-{dev}.png")
            with open(path, "wb") as fh:
                fh.write(base64.b64decode(png["data"]))
            rows.append((opt, theme, dev, bg, len(bad), os.path.basename(path)))
            print(f"  {opt:<4} {theme:<5} {dev:<7} bg={bg:<18} new-component={len(bad)} "
                  f"(pre-existing elsewhere on the panel: {other}) -> {os.path.basename(path)}")
    finally:
        s.finish()

    print()
    if findings:
        print(f"RED  {len(findings)} finding(s):")
        for f in findings:
            print("  - " + f)
        return 1
    print(f"GREEN  {len(rows)} states rendered, new component clears AA in all of them.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
