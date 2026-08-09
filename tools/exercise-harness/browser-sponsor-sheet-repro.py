#!/usr/bin/env python3
"""Backlog 4.6 — do the sponsor sheet's carousels and buttons actually work?

Found by sweeping the class behind 4.4/4.3/3.7 ("relocated markup arrives without its
behaviour"). The static case:

  webroot/sponsor-sheet.js intercepts /sponsors/ and /sponsors/<slug>/, lifts <main>
  out with DOMParser, strips <script>, injects it — and re-inits NOTHING.
  profile-sheet.js and practice-sheet.js both re-implement initCarousels precisely
  because the page's own script cannot come across.

  The node carries 12 carousel/gallery elements and 7-8 buttons. Their wiring is
  /archive-poc/assets/lg-v2-front.<hash>.js — 4 DOMContentLoaded inits, 9 document
  delegates, 0 MutationObserver, no exported re-init.
  /sponsors/<slug>/ loads that bundle. /sponsors/ — the list you tap FROM — does not.

So the prediction is: opened from the LIST, the sheet's controls are inert; the same
controls on the standalone page work. This run either shows that or kills it.

WHAT COUNTS AS THE DEFECT, decided before measuring so the result cannot be talked
into existence: tap a carousel arrow in the sheet and the track does not move, while
the same arrow on /sponsors/<slug>/ at the same width does move it. Position is read
from the DOM (scrollLeft / transform / aria-current), never from a screenshot.

Every absence here is paired with a liveness assertion, because "the track did not
move" is vacuously true of a sheet that never opened or a carousel that has one slide.
"""
import json, sys, time, base64, os, subprocess, urllib.request, websocket

CDP = "http://127.0.0.1:9222"
BASE = "https://dev2.loothgroup.com"
COOKIES = "/tmp/mobile-bugs-exercise/cookies.txt"
SHOTS = os.environ.get("LG_SHOTS", "/tmp/mobile-bugs-exercise/sponsor")
SLUG = os.environ.get("LG_SPONSOR", "stewmac")


def gate(): return open("/tmp/mobile-bugs-exercise/gate.txt").read().strip()


class P:
    def __init__(s, w):
        s.ws = websocket.create_connection(w, timeout=45, suppress_origin=True); s.n = 0
    def send(s, m, p=None):
        s.n += 1; i = s.n
        s.ws.send(json.dumps({"id": i, "method": m, "params": p or {}}))
        while True:
            r = json.loads(s.ws.recv())
            if r.get("id") == i:
                if "error" in r: raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})
    def ev(s, e):
        r = s.send("Runtime.evaluate", {"expression": e, "returnByValue": True, "awaitPromise": True})
        if r.get("exceptionDetails"): raise RuntimeError("JS: " + str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")


def shot(p, n):
    os.makedirs(SHOTS, exist_ok=True)
    r = p.send("Page.captureScreenshot", {"format": "png"})
    open(f"{SHOTS}/{n}.png", "wb").write(base64.b64decode(r["data"]))


def line(k, v): print(f"  {k:<26}: {v}")


# Read the carousel's POSITION from the DOM. A screenshot cannot tell "did not move"
# from "moved and moved back", and a transform is not always the mechanism.
STATE = """(function(){
  var root = document.querySelector(%s); if(!root) return {missing:true};
  // The class is NOT .lg-carousel — the first pass looked for that, found 0 on the
  // CONTROL too, and would have been read as "no carousels here". These are driven by
  // data-lg-carousel-* attributes on .lg-feat-products / .lg-recent-posts.
  var navs = [].slice.call(root.querySelectorAll('[data-lg-carousel-next]'));
  var cars = navs.map(function(n){
    return n.closest('[class*="lg-feat-products"],[class*="lg-recent-posts"],section,div');});
  var live = cars.filter(function(c){
    var t=c && c.querySelector('[data-lg-carousel-track]');
    return t && t.children.length > 1;               // a 1-slide carousel proves nothing
  });
  var c = live[0];
  if(!c) return {carousels:navs.length, usable:0};
  var t = c.querySelector('[data-lg-carousel-track]');
  var nav = c.querySelector('[data-lg-carousel-next]');
  var dots = c.querySelectorAll('[class*=dot]');
  return {
    carousels: cars.length, usable: live.length,
    slides: t.children.length,
    scrollLeft: Math.round(t.scrollLeft),
    transform: (getComputedStyle(t).transform||'none'),
    firstSlideX: Math.round(t.children[0].getBoundingClientRect().left),
    dotCurrent: dots.length ? [].slice.call(dots).map(function(d){return d.getAttribute('aria-current');}).join(',') : null,
    hasNav: !!nav,
    buttons: root.querySelectorAll('button').length
  };
})()"""


def tap_next(p, scope):
    """Hit-test then a real touch on the carousel's next control."""
    b = p.ev("""(function(){
      var root=document.querySelector(%s); if(!root) return null;
      var cars=[].slice.call(root.querySelectorAll('[data-lg-carousel-next]')).map(function(n){
        return n.closest('[class*="lg-feat-products"],[class*="lg-recent-posts"],section,div');})
        .filter(function(c){var t=c&&c.querySelector('[data-lg-carousel-track]');
          return t && t.children.length>1;});
      var c=cars[0]; if(!c) return null;
      var el=c.querySelector('[data-lg-carousel-next]');
      if(!el) return {nonav:1};
      el.scrollIntoView({block:'center'});
      var r=el.getBoundingClientRect(); if(!r.width||!r.height) return {dead:1};
      var x=r.left+r.width/2, y=r.top+r.height/2, h=document.elementFromPoint(x,y);
      return {x:x,y:y,inside:!!(h&&(el.contains(h)||h.contains(el))),
              hit:h?(h.tagName+'.'+String(h.className).slice(0,40)):null};})()""" % json.dumps(scope))
    if not b: return False, "no carousel"
    if b.get("nonav"): return False, "carousel has no next control"
    if b.get("dead"): return False, "next control has zero size"
    if not b.get("inside"): return False, f"OCCLUDED by {b.get('hit')}"
    for t in ("touchStart", "touchEnd"):
        p.send("Input.dispatchTouchEvent", {"type": t, "touchPoints": []
               if t == "touchEnd" else [{"x": b["x"], "y": b["y"], "radiusX": 10, "radiusY": 10}]})
        time.sleep(0.05)
    time.sleep(1.2)
    return True, b["hit"]


def main():
    t = json.load(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")))
    p = P(t["webSocketDebuggerUrl"])
    try:
        p.send("Network.enable"); p.send("Page.enable")
        p.send("Network.setCacheDisabled", {"cacheDisabled": True})
        p.send("Network.clearBrowserCookies")
        cks = [{"name": "loothdev_auth", "value": gate(), "domain": ".dev2.loothgroup.com",
                "path": "/", "secure": True}]
        cks += [{"name": k.strip(), "value": v.strip(), "url": BASE, "path": "/"}
                for k, v in (l.split("=", 1) for l in open(COOKIES) if "=" in l)]
        p.send("Network.setCookies", {"cookies": cks})
        p.send("Emulation.setDeviceMetricsOverride",
               {"width": 390, "height": 844, "deviceScaleFactor": 3, "mobile": True})
        p.send("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})
        p.send("Emulation.setEmitTouchEventsForMouse", {"enabled": True, "configuration": "mobile"})

        def go(path, settle=5.5):
            p.send("Page.navigate", {"url": BASE + path})
            for _ in range(160):
                time.sleep(0.25)
                try:
                    if p.ev("document.readyState") == "complete": break
                except Exception: pass
            time.sleep(settle)

        def live(where):
            st = p.ev("[innerWidth, matchMedia('(max-width:640px)').matches, document.title]")
            if st[0] > 640 or not st[1]:
                raise SystemExit(f"!! ABORT {where}: innerWidth={st[0]} phone={st[1]}")
            return f"390px phone, {st[2]!r}"

        # ── CONTROL: the standalone sponsor page, where the v2 bundle loads ──
        print(f"\n=== CONTROL — /sponsors/{SLUG}/ standalone at 390px ===")
        go(f"/sponsors/{SLUG}/")
        line("liveness", live("control"))
        line("v2 bundle loaded", p.ev("[...document.scripts].some(s=>/lg-v2-front/.test(s.src||''))"))
        c0 = p.ev(STATE % json.dumps("main, body"))
        for k in ("carousels", "usable", "slides", "scrollLeft", "firstSlideX", "dotCurrent", "hasNav", "buttons"):
            line(k, c0.get(k))
        shot(p, "control-before")
        ok, hit = tap_next(p, "main, body")
        line("tap next", f"{ok} ({hit})")
        c1 = p.ev(STATE % json.dumps("main, body"))
        line("scrollLeft AFTER", c1.get("scrollLeft"))
        line("firstSlideX AFTER", c1.get("firstSlideX"))
        line("dotCurrent AFTER", c1.get("dotCurrent"))
        shot(p, "control-after")
        moved_ctrl = (c0.get("scrollLeft") != c1.get("scrollLeft")
                      or c0.get("firstSlideX") != c1.get("firstSlideX")
                      or c0.get("dotCurrent") != c1.get("dotCurrent"))
        print(f"  >> CONTROL {'PASS — the carousel MOVES on the standalone page' if moved_ctrl else 'DID NOT MOVE — no verdict is possible below'}")

        # ── DEFECT: the same sponsor opened as a SHEET from the list ─────────
        print(f"\n=== DEFECT — the same sponsor opened from /sponsors/ as a sheet ===")
        go("/sponsors/")
        line("liveness", live("list"))
        line("v2 bundle on the LIST", p.ev("[...document.scripts].some(s=>/lg-v2-front/.test(s.src||''))"))
        line("sponsor-sheet loaded", p.ev("!!document.querySelector('script[src*=sponsor-sheet]') || !!window.__lgSponSheet"))
        opened = p.ev(f"""(function(){{
          var a=document.querySelector('a[href*="/sponsors/{SLUG}/"]');
          if(!a) return 'no link';
          a.scrollIntoView({{block:'center'}});
          a.click(); return 'clicked';
        }})()""")
        line("opened via", opened)
        time.sleep(6.0)
        sheet = p.ev("""(function(){var s=document.getElementById('looth-spon-sheet');
          return s ? {open:/is-open|display:\\s*block/.test(s.className+';'+(s.getAttribute('style')||'')),
                      id:s.id} : null;})()""")
        line("sheet", sheet)
        scope = "#looth-spon-sheet"      # NOT [id*=spon-sheet] — that also matches
                                         # pwa.js's <script id="looth-spon-sheet-js">
        d0 = p.ev(STATE % json.dumps(scope))
        for k in ("missing", "carousels", "usable", "slides", "scrollLeft", "firstSlideX", "dotCurrent", "hasNav", "buttons"):
            if d0.get(k) is not None: line(k, d0.get(k))
        shot(p, "sheet-before")

        if d0.get("missing") or not d0.get("usable"):
            print("\n  !! NO VERDICT: the sheet has no multi-slide carousel to test.")
            print("     An 'it did not move' here would be vacuous. Kill or refixture.")
            return 2

        ok, hit = tap_next(p, scope)
        line("tap next", f"{ok} ({hit})")
        d1 = p.ev(STATE % json.dumps(scope))
        line("scrollLeft AFTER", d1.get("scrollLeft"))
        line("firstSlideX AFTER", d1.get("firstSlideX"))
        line("dotCurrent AFTER", d1.get("dotCurrent"))
        shot(p, "sheet-after")
        moved_sheet = (d0.get("scrollLeft") != d1.get("scrollLeft")
                       or d0.get("firstSlideX") != d1.get("firstSlideX")
                       or d0.get("dotCurrent") != d1.get("dotCurrent"))

        print("\n=== VERDICT ===")
        print(f"  standalone carousel moves : {moved_ctrl}")
        print(f"  sheet carousel moves      : {moved_sheet}")
        if moved_ctrl and not moved_sheet:
            print("  4.6 REPRODUCED — same control, same width, dead only in the sheet")
        elif moved_ctrl and moved_sheet:
            print("  4.6 NOT REPRODUCED — the sheet's carousel works; the static read was wrong")
        else:
            print("  NO VERDICT — the control did not move either, so nothing is isolated")
        print(f"\n  shots -> {SHOTS}")
    finally:
        try: p.ws.close()
        except Exception: pass
        try: urllib.request.urlopen(CDP + f"/json/close/{t['id']}").read()
        except Exception: pass


if __name__ == "__main__":
    sys.exit(main())
