#!/usr/bin/env python3
"""Screenshot the events proof frames at a real 390x780 device viewport.

Uses the box's ONE managed engine (chrome-dev.service) via CDP, in a single
short-lived tab that is closed again on the way out. Does not touch any tab it
did not create.
"""
import asyncio, json, sys, urllib.request, base64, os
import websockets

CDP = "http://127.0.0.1:9222"
OUT = sys.argv[1]
URLS = sys.argv[2:]


def http(path, method="GET"):
    req = urllib.request.Request(CDP + path, method=method)
    return urllib.request.urlopen(req, timeout=10).read()


async def main():
    # our own tab, so keeper's craft-gate targets are untouched
    tab = json.loads(http("/json/new?about:blank", method="PUT"))
    tid, ws_url = tab["id"], tab["webSocketDebuggerUrl"]
    n = 0
    results = []
    try:
        async with websockets.connect(ws_url, max_size=80 * 1024 * 1024) as ws:
            async def cmd(method, params=None, timeout=30):
                nonlocal n
                n += 1
                await ws.send(json.dumps({"id": n, "method": method, "params": params or {}}))
                while True:                      # bounded, unlike craft-gate.py's untimed recv
                    msg = json.loads(await asyncio.wait_for(ws.recv(), timeout=timeout))
                    if msg.get("id") == n:
                        return msg

            await cmd("Page.enable")
            await cmd("Runtime.enable")
            await cmd("Emulation.setDeviceMetricsOverride", {
                "width": 390, "height": 780, "deviceScaleFactor": 2, "mobile": True})
            for url in URLS:
                name = os.path.basename(url).replace(".html", "")
                print("  navigating", name, flush=True)
                await cmd("Page.navigate", {"url": url}, timeout=60)
                await asyncio.sleep(2.0)          # let webfonts + the poster paint
                metrics = await cmd("Runtime.evaluate", {"expression": """
                    (function(){
                      var c = document.querySelector('.lev-cover');
                      var p = document.querySelector('.lg-post-header__photo');
                      var t = document.body.innerText;
                      var n = (t.match(/Sunday, August 2, 2026/g)||[]).length;
                      var r = function(e){ if(!e) return null; var b=e.getBoundingClientRect();
                        return {w:Math.round(b.width), h:Math.round(b.height)}; };
                      return JSON.stringify({datelines:n, cover:r(c), photo:r(p),
                        sheet: !!document.querySelector('.lev-card'),
                        scrollW: document.documentElement.scrollWidth});
                    })()"""})
                info = json.loads(metrics["result"]["result"]["value"])
                shot = await cmd("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": True}, timeout=60)
                data = base64.b64decode(shot["result"]["data"])
                p = os.path.join(OUT, name + ".png")
                open(p, "wb").write(data)
                info["png"] = p
                info["bytes"] = len(data)
                results.append((name, info))
                print(f"{name}: {info}")
    finally:
        try:
            http("/json/close/" + tid)            # always give the slot back
            print("closed our tab", tid)
        except Exception as e:
            print("tab close failed:", e)
    # verdicts
    bad = []
    for name, i in results:
        if i["scrollW"] > 392:
            bad.append(f"{name}: horizontal overflow ({i['scrollW']}px > 390)")
        if "after" in name:
            if i["sheet"]:
                bad.append(f"{name}: sheet present in an AFTER frame")
            if i["datelines"] != 1:
                bad.append(f"{name}: {i['datelines']} date lines, expected 1")
            if not i["photo"]:
                bad.append(f"{name}: destination hero missing")
        if "before" in name:
            if not i["sheet"]:
                bad.append(f"{name}: BEFORE frame has no sheet (control broken)")
            if i["datelines"] != 2:
                bad.append(f"{name}: {i['datelines']} date lines, expected 2 (the bug)")
            if i["cover"] and i["cover"]["h"] != 170:
                bad.append(f"{name}: cover is {i['cover']['h']}px, expected the 170px crop")
    print("\n" + ("BROWSER CHECKS FAILED:\n  " + "\n  ".join(bad) if bad else "BROWSER CHECKS PASSED"))
    sys.exit(1 if bad else 0)

asyncio.run(main())
