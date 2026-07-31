#!/usr/bin/env python3
"""Reproduce the two mobile bugs on the exercise harness, as a logged-in member at 390px.

ONE persistent CDP websocket for the whole run — per-command sockets drop the device
emulation, and the page then behaves as DESKTOP while the log says "mobile".
"""
import json, sys, time, urllib.request, websocket

CDP = "http://127.0.0.1:9222"
BASE = "http://127.0.0.1:8891"
COOKIES = "/tmp/mobile-embed-exercise/cookies.txt"


class Page:
    def __init__(self, ws):
        self.ws = websocket.create_connection(ws, timeout=40, suppress_origin=True)
        self.n = 0

    def send(self, m, p=None):
        self.n += 1
        i = self.n
        self.ws.send(json.dumps({"id": i, "method": m, "params": p or {}}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("id") == i:
                if "error" in r:
                    raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})

    def ev(self, e):
        r = self.send("Runtime.evaluate",
                      {"expression": e, "returnByValue": True, "awaitPromise": True})
        if r.get("exceptionDetails"):
            raise RuntimeError("JS: " + str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")

    def close(self):
        try:
            self.ws.close()
        except Exception:
            pass


def new_page():
    t = json.load(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")))
    return t["id"], Page(t["webSocketDebuggerUrl"])


def close_page(tid):
    try:
        urllib.request.urlopen(CDP + f"/json/close/{tid}").read()
    except Exception:
        pass


def cookies(p, host="127.0.0.1"):
    cks = [{"name": k.strip(), "value": v.strip(), "url": BASE, "path": "/"}
           for k, v in (l.split("=", 1) for l in open(COOKIES) if "=" in l)]
    p.send("Network.enable")
    p.send("Network.setCookies", {"cookies": cks})


def mobile(p, w=390, h=844):
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": w, "height": h, "deviceScaleFactor": 3, "mobile": True})
    p.send("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})
    p.send("Emulation.setEmitTouchEventsForMouse", {"enabled": True, "configuration": "mobile"})


def goto(p, url, settle=4.0):
    p.send("Page.navigate", {"url": url})
    for _ in range(160):
        time.sleep(0.25)
        try:
            if p.ev("document.readyState") == "complete":
                break
        except Exception:
            pass
    time.sleep(settle)


def tap(p, sel):
    """Real touch tap at the element's centre, hit-tested first (blind clicks land on
    fixed furniture — the tabbar sits at z-index 2147481000+)."""
    box = p.ev("""(() => { const e = document.querySelector(%s); if (!e) return null;
      e.scrollIntoView({block:'center'}); const r = e.getBoundingClientRect();
      const x = Math.round(r.left + r.width/2), y = Math.round(r.top + r.height/2);
      const hit = document.elementFromPoint(x, y);
      return {x, y, w: r.width, h: r.height,
              onTarget: !!(hit && (hit === e || e.contains(hit) || hit.contains(e))),
              hit: hit ? (hit.tagName + '.' + (hit.className||'').toString().slice(0,60)) : null};
    })()""" % json.dumps(sel))
    if not box:
        return {"error": "no element " + sel}
    time.sleep(0.4)
    box = p.ev("""(() => { const e = document.querySelector(%s); const r = e.getBoundingClientRect();
      const x = Math.round(r.left + r.width/2), y = Math.round(r.top + r.height/2);
      const hit = document.elementFromPoint(x, y);
      return {x, y, onTarget: !!(hit && (hit === e || e.contains(hit) || hit.contains(e))),
              hit: hit ? (hit.tagName + '.' + (hit.className||'').toString().slice(0,60)) : null};
    })()""" % json.dumps(sel))
    pt = [{"x": box["x"], "y": box["y"], "radiusX": 12, "radiusY": 12, "force": 1}]
    p.send("Input.dispatchTouchEvent", {"type": "touchStart", "touchPoints": pt})
    time.sleep(0.06)
    p.send("Input.dispatchTouchEvent", {"type": "touchEnd", "touchPoints": []})
    return box


def shot(p, path):
    r = p.send("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": False})
    import base64
    open(path, "wb").write(base64.b64decode(r["data"]))


def main():
    tid, p = new_page()
    try:
        p.send("Page.enable"); p.send("Runtime.enable")
        cookies(p)
        mobile(p)
        goto(p, BASE + "/hub/?type=discussions")
        # force light theme for legible evidence shots (the shared chrome-dev profile
        # persists lg-set-theme=dark; localStorage writes only stick after a navigation)
        p.ev("try{localStorage.setItem('lg-set-theme','light')}catch(e){}")
        goto(p, BASE + "/hub/?type=discussions")

        print("viewport      :", p.ev("innerWidth + 'x' + innerHeight"))
        print("matches ≤640  :", p.ev("matchMedia('(max-width:640px)').matches"))
        print("touch points  :", p.ev("navigator.maxTouchPoints"))
        print("signed in as  :", p.ev("(document.querySelector('.lg-hdr-avatar img,.lg-user-name')||{}).alt "
                                      "|| (document.body.className.match(/lg-[a-z-]*auth[a-z-]*/)||[''])[0] || '?'"))
        print("yt facade card:", p.ev("!!document.querySelector('.feed-card--topic .fc-cover--video[data-yt-play]')"))
        print("fc-play shown :", p.ev("""(() => { const b = document.querySelector('.feed-card--topic .fc-cover--video .fc-play');
            if (!b) return 'absent-from-dom';
            const cs = getComputedStyle(b), r = b.getBoundingClientRect();
            return (cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0) ? 'visible' : 'hidden:'+cs.display; })()"""))
        print("autoplay wired:", p.ev("!!document.body.getAttribute('data-lg-vidauto')"))

        # ── #7: tap the video cover on the card ──────────────────────────────
        print("\n--- #7  tap the discussion card's video cover ---")
        b = tap(p, '.feed-card--topic .fc-cover--video')
        print("  hit-test    :", b)
        time.sleep(3.5)
        print("  iframe on card:", p.ev("document.querySelectorAll('.fc-cover--video iframe').length"))
        print("  sheet open  :", p.ev("!!document.querySelector('#looth-rep-sheet.is-open')"))
        shot(p, "/tmp/mobile-embed-exercise/red-7-tap-cover.png")

        # ── #3.7: the OP body inside the mobile sheet ────────────────────────
        print("\n--- #3.7  embed inside the mobile discussion sheet ---")
        time.sleep(2.0)
        print("  op body html:", (p.ev("(document.querySelector('#lrs-op .lrs-op__body')||{}).innerHTML || '(none)'") or "")[:260])
        print("  players     :", p.ev("document.querySelectorAll('#looth-rep-sheet iframe').length"))
        print("  .bb-embed   :", p.ev("document.querySelectorAll('#looth-rep-sheet .bb-embed').length"))
        print("  bare yt link:", p.ev("""document.querySelectorAll('#looth-rep-sheet .lrs-op__body a[href*="youtu"]').length"""))
        shot(p, "/tmp/mobile-embed-exercise/red-37-sheet.png")

        # ── control: the standalone topic page at the same width ─────────────
        print("\n--- control  standalone topic page at 390 ---")
        goto(p, BASE + "/hub/touring-tech/test-3/")
        print("  .post__body players:", p.ev("document.querySelectorAll('.post__body iframe').length"))
        print("  .bb-embed          :", p.ev("document.querySelectorAll('.post__body .bb-embed').length"))
        print("  bare yt link       :", p.ev("""document.querySelectorAll('.post__body a[href*="youtu"]').length"""))
        shot(p, "/tmp/mobile-embed-exercise/control-standalone.png")
    finally:
        p.close(); close_page(tid)


main()
