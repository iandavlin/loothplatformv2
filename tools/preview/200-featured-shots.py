#!/usr/bin/env python3
"""#200 — photograph the featured-band empty-state options, both themes, two widths.

The mock pins the FULL --lg-*/--fp-* token set on its own panes, so both themes
appear in one shot and neither depends on the app's theme machinery. That is
deliberate: app-settings.js is injected into mocks and re-points those tokens as
inline style on <html>, which INVERTS a real-CSS mock, and stamping
data-lguser-theme persists on the SHARED chrome profile and takes every other
lane's browser dark (trap-docroot-injects-boot-script-into-mocks,
trap-mock-theme-stamp-poisons-shared-chrome). This run therefore NEVER writes
lg-set-theme and never stamps the attribute; it reads the shared value only to
prove it did not change.

⚠️ ASSERT THE DELTA, NOT THE ABSOLUTE. A light pane wearing a dark attribute
photographs as a defect in whatever is drawn on top, so the run measures the
card background of the light pane against the dark pane and fails if they match
— which is the only thing that tells "two themes rendered" from "one theme
twice". Paired with a liveness read, because a locked-out browser serves a
styled 403 that is identical in both themes at every width and passes a visual
suite having measured nothing.
"""
import base64, json, subprocess, sys, time
import urllib.request
import websocket

OUT  = "/home/ubuntu/worktrees/200-featured-override/footer-mockups/200-featured-override"
URL  = "https://dev2.loothgroup.com/footer-mockups/200-featured-override/"
CDP  = "http://127.0.0.1:9222"

g = subprocess.run(["sudo", "-n", "grep", "-oP", r'loothdev_token\s+"\K[^"]+',
                    "/etc/nginx/snippets/loothdev-tokens.conf"], capture_output=True, text=True)
tok = (g.stdout.strip().splitlines() or [""])[0]
assert tok, "no dev gate token"

req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
tgt = json.loads(urllib.request.urlopen(req, timeout=10).read())
ws  = websocket.create_connection(tgt["webSocketDebuggerUrl"], timeout=30, suppress_origin=True)
N = [0]

def send(method, params=None):
    N[0] += 1
    ws.send(json.dumps({"id": N[0], "method": method, "params": params or {}}))
    while True:
        m = json.loads(ws.recv())
        if m.get("id") == N[0]:
            if "error" in m: raise RuntimeError(f"{method}: {m['error']}")
            return m.get("result", {})

def ev(expr):
    return send("Runtime.evaluate", {"expression": expr, "returnByValue": True}) \
             .get("result", {}).get("value")

send("Page.enable"); send("Runtime.enable"); send("Network.enable")
send("Security.setIgnoreCertificateErrors", {"ignore": True})
# clear first — setCookies ADDS beside a dotted twin instead of replacing it
send("Network.clearBrowserCookies")
send("Network.setCookies", {"cookies": [
    {"name": "loothdev_auth", "value": tok, "domain": ".dev2.loothgroup.com", "path": "/"},
]})

send("Page.navigate", {"url": URL}); time.sleep(2.0)
PRIOR = ev("localStorage.getItem('lg-set-theme')")
print(f"shared profile theme is {PRIOR!r} — this run does not touch it")

RED = []
for name, w, h, mobile in [("1440", 1440, 1000, False), ("390", 390, 844, True)]:
    send("Emulation.setDeviceMetricsOverride",
         {"width": w, "height": h, "deviceScaleFactor": 2 if mobile else 1,
          "mobile": mobile, "screenWidth": w, "screenHeight": h})
    send("Page.navigate", {"url": URL}); time.sleep(2.4)

    # LIVENESS — a styled 403 is identical in both themes and photographs clean
    live = ev("!!document.querySelector('.pane--light .lg-fm') "
              "&& !!document.querySelector('.pane--dark .lg-fm') "
              "&& /This spot is open/.test(document.body.innerText)")
    # THE DELTA — the two panes must actually render different card surfaces
    delta = ev("""(() => {
      const l = document.querySelector('.pane--light .lg-fm');
      const d = document.querySelector('.pane--dark  .lg-fm');
      if (!l || !d) return JSON.stringify({state:'MISSING'});
      const cl = getComputedStyle(l).backgroundColor, cd = getComputedStyle(d).backgroundColor;
      const img = document.querySelector('.pane--light .lg-fm__avi img');
      return JSON.stringify({state:'OK', light: cl, dark: cd, differ: cl !== cd,
                             avatarLoaded: !!img && img.naturalWidth > 0});
    })()""")
    dj = json.loads(delta)
    if not live:               RED.append(f"[{name}] the mock did not render — liveness failed")
    if not dj.get("differ"):   RED.append(f"[{name}] both panes render the SAME card colour {dj} "
                                          f"— one theme photographed twice")
    if not dj.get("avatarLoaded"): RED.append(f"[{name}] the pinned-pick avatar did not load — "
                                              f"a hole in the very card under test")

    m = send("Page.getLayoutMetrics")
    full = int(m["cssContentSize"]["height"])
    img = send("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": True,
                                          "clip": {"x": 0, "y": 0, "width": w,
                                                   "height": full, "scale": 1}})
    open(f"{OUT}/mock-{name}.png", "wb").write(base64.b64decode(img["data"]))
    print(f"  mock-{name}.png  live={live} {dj} height={full}")

send("Emulation.clearDeviceMetricsOverride")
AFTER = ev("localStorage.getItem('lg-set-theme')")
if AFTER != PRIOR:
    RED.append(f"this run changed the SHARED profile theme {PRIOR!r} -> {AFTER!r}")
print(f"shared profile theme after: {AFTER!r}")
ws.close()

if RED:
    print("\nRED:"); [print("  " + r) for r in RED]; sys.exit(1)
print("\nGREEN — both themes rendered, both widths, shared profile untouched")
