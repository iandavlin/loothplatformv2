#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""#202 — photograph both proposal options, both themes, phone + desktop.

Shaped around the traps this box has already paid for:

 · ONE PERSISTENT CDP SESSION. A per-command socket drops the device metrics
   override, so a phone run photographs a DESKTOP page and false-PASSes as
   mobile (trap-chrome-dev-login-skill-stale-on-dev2).
 · THE GATE COOKIE NEEDS A LEADING DOT, and cookies are CLEARED first —
   Network.setCookies ADDS a second host-only-vs-dotted twin rather than
   replacing it (trap-shared-chrome-profile-duplicate-session-cookies).
 · LIVENESS BEFORE PIXELS. A locked-out browser serves a styled 403 that is
   identical in light and dark at every width, so a visual run passes having
   measured nothing (trap-locked-out-browser-goes-vacuously-green). Every shot
   is gated on the mock's own content being present and correctly counted.
 · ASSERT THE DELTA. The two themes must render DIFFERENT surfaces or the run
   photographed one theme twice (trap-mock-theme-stamp-poisons-shared-chrome).
 · THIS RUN NEVER WRITES localStorage. It reads `lg-set-theme` only to prove the
   shared chrome profile is unchanged when it finishes.
 · The tab it opens is closed on the way out.
"""
import base64
import json
import subprocess
import sys
import time
import urllib.request

import websocket

OUT = "/home/ubuntu/worktrees/202-todo-proposal/footer-mockups/202-todo-proposal"
BASE = "https://dev2.loothgroup.com/footer-mockups/202-todo-proposal"
CDP = "http://127.0.0.1:9222"

# what each page must show before its picture is allowed to count
EXPECT = {
    "a": dict(rows=20, hero=1, badges=9, needle="Pick your window for the live billing swap"),
    "b": dict(rows=0, hero=0, badges=0, needle="Pick your window for the live billing swap"),
}

g = subprocess.run(["sudo", "-n", "grep", "-oP", r'loothdev_token\s+"\K[^"]+',
                    "/etc/nginx/snippets/loothdev-tokens.conf"],
                   capture_output=True, text=True)
tok = (g.stdout.strip().splitlines() or [""])[0]
assert tok, "no dev gate token — cannot reach the mock through the gate"

req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
tgt = json.loads(urllib.request.urlopen(req, timeout=10).read())
TID = tgt["id"]
ws = websocket.create_connection(tgt["webSocketDebuggerUrl"], timeout=40, suppress_origin=True)
N = [0]


def send(method, params=None):
    N[0] += 1
    ws.send(json.dumps({"id": N[0], "method": method, "params": params or {}}))
    while True:
        m = json.loads(ws.recv())
        if m.get("id") == N[0]:
            if "error" in m:
                raise RuntimeError(f"{method}: {m['error']}")
            return m.get("result", {})


def ev(expr):
    return send("Runtime.evaluate", {"expression": expr, "returnByValue": True}) \
        .get("result", {}).get("value")


send("Page.enable"); send("Runtime.enable"); send("Network.enable")
send("Security.setIgnoreCertificateErrors", {"ignore": True})
send("Network.setCacheDisabled", {"cacheDisabled": True})   # stale bytes have faked two readings
send("Network.clearBrowserCookies")
send("Network.setCookies", {"cookies": [
    {"name": "loothdev_auth", "value": tok, "domain": ".dev2.loothgroup.com", "path": "/"},
]})

send("Page.navigate", {"url": BASE + "/index.html"}); time.sleep(1.5)
PRIOR = ev("localStorage.getItem('lg-set-theme')")
print(f"shared chrome profile lg-set-theme = {PRIOR!r} — this run does not write it")

RED, SHOTS, seen_bg = [], [], {}

for wname, w, h, mobile in (("desktop", 1440, 1000, False), ("phone", 390, 844, True)):
    send("Emulation.setDeviceMetricsOverride",
         {"width": w, "height": h, "deviceScaleFactor": 2 if mobile else 1,
          "mobile": mobile, "screenWidth": w, "screenHeight": h})
    for opt in ("a", "b"):
        for theme in ("light", "dark"):
            url = f"{BASE}/option-{opt}.html?theme={theme}"
            send("Page.navigate", {"url": url})
            time.sleep(1.8)
            tag = f"{opt}-{wname}-{theme}"
            exp = EXPECT[opt]

            probe = ev("""(() => {
              const p = document.querySelector('.page');
              if (!p) return JSON.stringify({state:'NO-MOCK', text:document.body.innerText.slice(0,90)});
              const tb = document.querySelector('#looth-tabbar,nav#looth-tabbar');
              return JSON.stringify({
                state:'OK',
                theme: p.getAttribute('data-t'),
                bg: getComputedStyle(p).backgroundColor,
                ink: getComputedStyle(p).color,
                rows: document.querySelectorAll('.row').length,
                hero: document.querySelectorAll('.hero').length,
                badges: document.querySelectorAll('.newbadge').length,
                doors: document.querySelectorAll('a.door').length,
                needle: document.body.innerText,
                tabbar: tb ? getComputedStyle(tb).display : 'absent',
                docH: document.documentElement.scrollHeight
              });
            })()""")
            d = json.loads(probe)

            if d.get("state") != "OK":
                RED.append(f"[{tag}] the mock did not render — {d}")
                continue
            if d["theme"] != theme:
                RED.append(f"[{tag}] asked for {theme}, page stamped {d['theme']!r}")
            if exp["needle"] not in (d.get("needle") or ""):
                RED.append(f"[{tag}] liveness: the hero action text is not on the page")
            for k in ("rows", "hero", "badges"):
                if d[k] != exp[k]:
                    RED.append(f"[{tag}] {k} = {d[k]}, expected {exp[k]}")
            if d["tabbar"] not in ("none", "absent"):
                RED.append(f"[{tag}] the injected app tabbar is VISIBLE ({d['tabbar']}) "
                           f"— it is covering the mockup")
            seen_bg[tag] = d["bg"]

            png = send("Page.captureScreenshot",
                       {"format": "png", "captureBeyondViewport": True})["data"]
            path = f"{OUT}/{opt}-{wname}-{theme}.png"
            with open(path, "wb") as fh:
                fh.write(base64.b64decode(png))
            SHOTS.append((tag, path, d["docH"], d["bg"], d["doors"]))
            print(f"  {tag:22} {d['bg']:<22} rows={d['rows']:<3} doors={d['doors']:<3} "
                  f"page {d['docH']}px  ->  {path.rsplit('/', 1)[-1]}")

# THE DELTA — light and dark must be different surfaces, per width and option
for opt in ("a", "b"):
    for wname in ("desktop", "phone"):
        lt, dk = seen_bg.get(f"{opt}-{wname}-light"), seen_bg.get(f"{opt}-{wname}-dark")
        if lt and dk and lt == dk:
            RED.append(f"[{opt}-{wname}] light and dark render the SAME background {lt} "
                       f"— one theme was photographed twice")

AFTER = ev("localStorage.getItem('lg-set-theme')")
if AFTER != PRIOR:
    RED.append(f"this run CHANGED the shared profile theme {PRIOR!r} -> {AFTER!r}")

try:
    urllib.request.urlopen(f"{CDP}/json/close/{TID}", timeout=5).read()
    print("closed the tab this run opened")
except Exception as e:                                   # noqa: BLE001
    print(f"could not close tab {TID}: {e}")
ws.close()

print()
if RED:
    print(f"RED — {len(RED)} problem(s):")
    for r in RED:
        print("  " + r)
    sys.exit(1)
print(f"GREEN — {len(SHOTS)} shots, both options x both themes x phone+desktop, "
       f"each gated on liveness + a light/dark delta.")
