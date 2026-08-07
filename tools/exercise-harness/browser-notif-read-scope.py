#!/usr/bin/env python3
"""
control.py — open the mobile notification sheet as a real member, on a real page.

Drives the taps a phone user makes (You tab -> Notifications row), records WHICH
rows actually entered the DOM, waits, closes the sheet. It asserts nothing about
the store: the caller queries Postgres, because a UI that sanitises on read
cannot audit the store it wrote to.

Every tap is HIT-TESTED with elementFromPoint before dispatch. #looth-tabbar is
fixed at z-index 2147481000+, so a blind CDP tap can land on the tabbar and the
run still "passes" having exercised nothing.

  --dwell MS      wait this long after the rows render before closing (default 1500)
  --close-at MS   close the sheet this long after render (default: = dwell)
  --port          the harness proxy port
Prints one JSON object on stdout.
"""
import argparse, asyncio, base64, json, sys, urllib.request
import websockets

CDP = "http://127.0.0.1:9222"


def http(p):
    return json.load(urllib.request.urlopen(CDP + p))


class Page:
    def __init__(self, ws):
        self.ws, self.n = ws, 0

    async def send(self, m, p=None):
        self.n += 1
        i = self.n
        await self.ws.send(json.dumps({"id": i, "method": m, "params": p or {}}))
        while True:
            r = json.loads(await self.ws.recv())
            if r.get("id") == i:
                if "error" in r:
                    raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})

    async def ev(self, e):
        r = await self.send("Runtime.evaluate",
                            {"expression": e, "returnByValue": True, "awaitPromise": True})
        if r.get("exceptionDetails"):
            raise RuntimeError("JS: " + str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")

    async def shot(self, path):
        r = await self.send("Page.captureScreenshot", {"format": "png"})
        open(path, "wb").write(base64.b64decode(r["data"]))

    async def tap_point(self, x, y):
        """Tap absolute coords. For the sheet backdrop, whose CENTRE is behind the
        sheet itself — a real thumb dismisses it by hitting the strip ABOVE."""
        top = await self.ev(f"""(() => {{const e=document.elementFromPoint({x},{y});
            return e ? (e.id || e.tagName) : null;}})()""")
        await self.send("Input.dispatchTouchEvent",
                        {"type": "touchStart", "touchPoints": [{"x": x, "y": y}]})
        await self.send("Input.dispatchTouchEvent", {"type": "touchEnd", "touchPoints": []})
        return {"ok": True, "hit": top}

    async def tap(self, sel):
        """Hit-tested touch tap on the centre of sel. Returns what actually got hit."""
        box = await self.ev(f"""(() => {{
            const e = document.querySelector({json.dumps(sel)});
            if (!e) return null;
            // Only scroll if it is actually OFF-viewport. scrollIntoView on an
            // element inside a fixed bottom sheet can shove it out of the viewport,
            // after which elementFromPoint returns null and the run reports the
            // control "covered by None" — a harness artefact that reads as a finding.
            let r = e.getBoundingClientRect();
            if (r.bottom < 0 || r.top > window.innerHeight) {{
                e.scrollIntoView({{block:'center'}});
                r = e.getBoundingClientRect();
            }}
            if (r.width === 0 || r.height === 0) return {{hidden:true}};
            const x = r.x + r.width/2, y = r.y + r.height/2;
            const top = document.elementFromPoint(x, y);
            return {{x, y, hit: e.contains(top) || e === top,
                     topTag: top ? top.tagName : null,
                     topId: top ? (top.id || top.className || '') : null}};
        }})()""")
        if not box or box.get("hidden"):
            return {"ok": False, "why": f"{sel} absent or zero-size"}
        if not box.get("hit"):
            return {"ok": False, "why": f"{sel} is covered by "
                                       f"{box.get('topTag')}#{box.get('topId')}"}
        await self.send("Input.dispatchTouchEvent",
                        {"type": "touchStart",
                         "touchPoints": [{"x": box["x"], "y": box["y"]}]})
        await self.send("Input.dispatchTouchEvent", {"type": "touchEnd", "touchPoints": []})
        return {"ok": True}


async def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=8881)
    ap.add_argument("--dwell", type=int, default=1500)
    ap.add_argument("--close-at", type=int, default=None)
    ap.add_argument("--shot", default=None)
    ap.add_argument("--expand-all", action="store_true",
                    help='also tap "See all notifications" before dwelling')
    a = ap.parse_args()
    close_at = a.dwell if a.close_at is None else a.close_at
    res = {"steps": [], "rendered_ids": [], "ok": False}

    def step(name, detail=None):
        res["steps"].append({name: detail} if detail is not None else name)

    bws = http("/json/version")["webSocketDebuggerUrl"]
    async with websockets.connect(bws, max_size=None) as b:
        tgt = (await Page(b).send("Target.createTarget", {"url": "about:blank"}))["targetId"]
    pw = next(t["webSocketDebuggerUrl"] for t in http("/json") if t["id"] == tgt)
    try:
        async with websockets.connect(pw, max_size=None) as ws:
            p = Page(ws)
            await p.send("Page.enable")
            await p.send("Runtime.enable")
            # Device emulation must be set on THIS session (a per-command socket
            # drops it and the run silently becomes a desktop run).
            await p.send("Emulation.setDeviceMetricsOverride",
                         {"width": 390, "height": 844, "deviceScaleFactor": 3, "mobile": True})
            await p.send("Emulation.setTouchEmulationEnabled",
                         {"enabled": True, "maxTouchPoints": 5})
            await p.send("Emulation.setUserAgentOverride", {"userAgent":
                "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 "
                "(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1"})

            await p.send("Page.navigate", {"url": f"http://127.0.0.1:{a.port}/hub/"})
            for _ in range(80):
                await asyncio.sleep(0.25)
                if await p.ev("document.readyState==='complete'"):
                    break
            # pwa.js injects bottom-nav.js async; the bar is the tell it arrived.
            bar = False
            for _ in range(60):
                await asyncio.sleep(0.25)
                if await p.ev("!!document.getElementById('looth-tabbar')"):
                    bar = True
                    break
            step("tabbar_present", bar)
            if not bar:
                res["why"] = "bottom-nav.js never built #looth-tabbar"
                print(json.dumps(res)); return
            res["width"] = await p.ev("window.innerWidth")
            res["bottom_nav_src"] = await p.ev(
                "(document.querySelector('script[src*=\"bottom-nav\"]')||{}).src||null")

            await asyncio.sleep(1.2)   # let refreshNotifBadge land
            res["badge_before"] = await p.ev(
                """(() => {const b=document.querySelector('#looth-tabbar .lt-badge');
                     return b ? (b.hidden ? 'hidden' : b.textContent) : 'absent';})()""")

            t = await p.tap('#looth-tabbar [aria-label="You"]')
            step("tap_you", t)
            if not t["ok"]:
                res["why"] = t["why"]; print(json.dumps(res)); return
            await asyncio.sleep(0.8)

            t = await p.tap('.lt-sheet__row--notifs')
            step("tap_notifs_row", t)
            if not t["ok"]:
                res["why"] = t["why"]; print(json.dumps(res)); return

            # Wait for the rows to actually render (the fetch is async). This is
            # t=0 for the dwell: the timer under test starts when they appear.
            rendered = None
            for _ in range(40):
                await asyncio.sleep(0.1)
                rendered = await p.ev(
                    """(() => {const b=document.getElementById('lt-notifs');
                         if(!b) return null;
                         const r=[...b.querySelectorAll('.lt-notif[data-notif-id]')];
                         if(!r.length) return b.textContent.indexOf('Loading')>=0 ? null : [];
                         return r.map(e=>+e.dataset.notifId);})()""")
                if rendered is not None:
                    break
            res["rendered_ids"] = rendered or []
            step("rows_rendered", len(res["rendered_ids"]))
            res["sheet_open"] = await p.ev(
                "!!document.querySelector('#looth-notifsheet.is-open')")
            res["see_all_offered"] = await p.ev(
                "!!document.querySelector('#lt-notifs [data-notif-all]')")

            if a.expand_all and res["see_all_offered"]:
                # The sheet slides up under a transform transition. Tapping mid-slide
                # aims at where the control WILL be, and elementFromPoint on a point
                # still below the fold returns null — which reports as "covered by
                # None" and looks like an unreachable control. Let it settle first.
                await asyncio.sleep(1.2)
                t = await p.tap('#lt-notifs [data-notif-all]')
                step("tap_see_all", t)
                await asyncio.sleep(1.5)
                res["rendered_ids"] = await p.ev(
                    """[...document.querySelectorAll('#lt-notifs .lt-notif[data-notif-id]')]
                         .map(e=>+e.dataset.notifId)""")
                step("rows_rendered_after_see_all", len(res["rendered_ids"]))

            # Dwell, then close. close_at < dwell exercises the dismissed-early case.
            await asyncio.sleep(close_at / 1000.0)
            t = await p.tap_point(195, 40)   # backdrop strip above the sheet
            step("tap_backdrop_close", t)
            await asyncio.sleep(0.4)
            res["sheet_open_after_close"] = await p.ev(
                "!!document.querySelector('#looth-notifsheet.is-open')")
            if a.dwell > close_at:
                await asyncio.sleep((a.dwell - close_at) / 1000.0)
            await asyncio.sleep(1.0)    # let any in-flight POST land

            res["badge_after"] = await p.ev(
                """(() => {const b=document.querySelector('#looth-tabbar .lt-badge');
                     return b ? (b.hidden ? 'hidden' : b.textContent) : 'absent';})()""")
            if a.shot:
                await p.shot(a.shot)
            res["ok"] = True
            print(json.dumps(res))
    finally:
        try:
            urllib.request.urlopen(CDP + f"/json/close/{tgt}").read()
        except Exception:
            pass


if __name__ == "__main__":
    asyncio.run(main())
