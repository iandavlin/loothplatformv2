#!/usr/bin/env python3
"""Backlog 3.8 — capture the REAL 390px frames the back-nav mockups are drawn on.

Ian 8/9: "on mobile and pwa we need some kind of back nav to the hub once you click
through to the post. there is one in the nav tab but it should be exposed."

WHY THE PWA IS THE SHARP CASE, and it is measurable rather than a guess:
webroot/manifest.json is display:standalone with start_url:/hub/. A standalone PWA
has NO browser chrome — no back button, no address bar. So once a member navigates
off /hub/ the ONLY way back is bottom-nav.js's Back, which lives INSIDE the Nav tray
(open the tray, then hit Back — two taps, and invisible until the first one).

Headless Chrome renders with no browser UI either, so these captures are already
what the installed PWA looks like. That is the honest frame to draw on; a capture
with a browser back button in it would be arguing against the request.

Captures, all 390x844 as a logged-in member:
  hub           the origin
  post          the destination — what you land on, with no exposed way back
  tray-closed   the bottom bar as it sits
  tray-open     the Nav tray, with the Back that exists today buried inside it
"""
import json, sys, time, base64, os, urllib.request, websocket

CDP = "http://127.0.0.1:9222"
BASE = "https://dev2.loothgroup.com"
COOKIES = "/tmp/mobile-bugs-exercise/cookies.txt"
OUT = os.environ.get("LG_OUT", "/tmp/mobile-bugs-exercise/backnav")

# ⚠️ Match the DEEPEST node carrying the text, never any node that merely CONTAINS it.
# An unanchored textContent match walks all the way up: <html> contains the string too
# and has 2 children, so a naive "small element" guard removed the entire document and
# the run then aborted with "not a signed-in member".
DROP_INSTALL = """(function(){
  var P='Add it to your home', hits=[];
  document.querySelectorAll('div,section,aside').forEach(function(e){
    if((e.textContent||'').indexOf(P)===-1) return;
    var deeper=false;
    e.querySelectorAll('div,section,aside').forEach(function(c){
      if((c.textContent||'').indexOf(P)>-1) deeper=true;});
    if(!deeper) hits.push(e);});
  hits.forEach(function(e){var box=e.closest('[class*=install],[id*=install]')||e.parentElement||e;
    box.style.display='none';});
  return hits.length;})()"""


def gate():
    return open("/tmp/mobile-bugs-exercise/gate.txt").read().strip()


def jwt():
    import subprocess
    out = subprocess.run(["sudo", "-u", "looth-dev", "wp", "--path=/var/www/dev", "eval",
                          'echo looth_auth_mint_jwt(get_user_by("id", 1912));'],
                         capture_output=True, text=True).stdout.strip().splitlines()
    return [l for l in out if l.startswith("eyJ")][-1]


class P:
    def __init__(s, ws):
        s.ws = websocket.create_connection(ws, timeout=45, suppress_origin=True); s.n = 0

    def send(s, m, p=None):
        s.n += 1; i = s.n
        s.ws.send(json.dumps({"id": i, "method": m, "params": p or {}}))
        while True:
            r = json.loads(s.ws.recv())
            if r.get("id") == i:
                if "error" in r: raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})

    def ev(s, e):
        r = s.send("Runtime.evaluate", {"expression": e, "returnByValue": True,
                                        "awaitPromise": True})
        if r.get("exceptionDetails"): raise RuntimeError("JS: " + str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")


def shot(p, name):
    os.makedirs(OUT, exist_ok=True)
    r = p.send("Page.captureScreenshot", {"format": "png"})   # viewport-only
    open(f"{OUT}/{name}.png", "wb").write(base64.b64decode(r["data"]))
    print(f"  captured {name}")


def live(p, where):
    st = p.ev("""[document.title, innerWidth, matchMedia('(max-width:640px)').matches,
                  !!document.querySelector('.lg-chrome__avatar'),
                  !!document.querySelector('.lg-chrome__signin')]""")
    t, w, phone, avatar, signin = st
    bad = []
    if "403" in str(t): bad.append("GATE 403")
    if w > 640: bad.append(f"innerWidth {w} — not a phone")
    if not phone: bad.append("not the phone media query")
    if signin or not avatar: bad.append("not a signed-in member")
    if bad: raise SystemExit(f"!! ABORT at {where}: {'; '.join(bad)}")
    return f"390px, member, {t!r}"


def tap(p, sel):
    b = p.ev("""(function(){var el=document.querySelector(%s);if(!el)return null;
      var r=el.getBoundingClientRect();if(!r.width||!r.height)return {dead:1};
      var x=r.left+r.width/2,y=r.top+r.height/2,h=document.elementFromPoint(x,y);
      return {x:x,y:y,inside:!!(h&&(el.contains(h)||h.contains(el))),
              hit:h?(h.tagName+'.'+(h.className||'')).slice(0,60):null};})()""" % json.dumps(sel))
    if not b or b.get("dead") or not b.get("inside"):
        return False, (b or {}).get("hit", "NO ELEMENT")
    for t in ("touchStart", "touchEnd"):
        p.send("Input.dispatchTouchEvent", {"type": t, "touchPoints": []
               if t == "touchEnd" else [{"x": b["x"], "y": b["y"]}]})
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
                "path": "/", "secure": True},
               {"name": "looth_id", "value": jwt(), "domain": ".dev2.loothgroup.com",
                "path": "/", "secure": True}]
        cks += [{"name": k.strip(), "value": v.strip(), "url": BASE, "path": "/"}
                for k, v in (l.split("=", 1) for l in open(COOKIES) if "=" in l)]
        p.send("Network.setCookies", {"cookies": cks})
        p.send("Emulation.setDeviceMetricsOverride",
               {"width": 390, "height": 844, "deviceScaleFactor": 3, "mobile": True})
        p.send("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})
        p.send("Emulation.setEmitTouchEventsForMouse",
               {"enabled": True, "configuration": "mobile"})

        def go(url, settle=5.0):
            p.send("Page.navigate", {"url": url})
            for _ in range(160):
                time.sleep(0.25)
                try:
                    if p.ev("document.readyState") == "complete": break
                except Exception: pass
            time.sleep(settle)

        # ⚠️ The shared chrome-dev profile PERSISTS the last theme anyone set, and a
        # localStorage write on about:blank is a no-op — so navigate first, force the
        # DEFAULT light theme, then reload. Without this the "before" frames come back
        # dark because of something another run did, and Ian is shown a state that is
        # not the default anyone lands in.
        print("\n=== the ORIGIN: /hub/ ===")
        go(BASE + "/hub/", 2.0)
        p.ev("localStorage.setItem('lg-set-boot', JSON.stringify("
             "{theme:'default',dark:false,vars:{},scale:1,feed:'immersive'}));"
             "localStorage.setItem('lg-set-theme','default');")
        go(BASE + "/hub/")
        # The install prompt is real but it is not the subject; dismiss it so the
        # frames show the page rather than a banner over the page.
        p.ev(DROP_INSTALL)
        time.sleep(0.6)
        print("  liveness:", live(p, "hub"))
        shot(p, "01-hub")

        # What actually NAVIGATES from a hub card on a phone? Discussions open the
        # reader sheet in place; this reports the real answer rather than assuming.
        print("\n=== what a hub card does on a phone ===")
        kinds = p.ev("""(function(){
          var out={}; var as=[].slice.call(document.querySelectorAll('a[href^="/hub/"]'));
          as.forEach(function(a){var m=(a.getAttribute('href')||'').match(/^\\/hub\\/[a-z0-9-]+\\/[a-z0-9-]+\\/?$/);
            if(m) out[a.getAttribute('href')]=(out[a.getAttribute('href')]||0)+1;});
          return {contentLinks:Object.keys(out).slice(0,6),
                  cards:document.querySelectorAll('.feed-card').length};})()""")
        print("  cards on the page :", kinds.get("cards"))
        print("  content links     :", kinds.get("contentLinks"))

        print("\n=== the DESTINATION: a full content page, PWA-style (no browser chrome) ===")
        target = (kinds.get("contentLinks") or ["/hub/touring-tech/test-3/"])[0]
        go(BASE + target)
        # Two things removed from the FRAME ONLY, both flagged on the mockup page so
        # nobody reads the picture as the whole truth:
        #  · the "Install Looth" banner — real, but not the subject
        #  · the post overflow menus, which render OPEN on a fresh load at 390px for
        #    anyone who can edit (measured: visibility:visible, display:flex, zero
        #    interaction). That is a separate defect, filed as backlog 3.9; leaving it
        #    in would make the back-nav mockup unreadable and look like my doing.
        p.ev(DROP_INSTALL)
        p.ev("""(function(){
          document.querySelectorAll('.post__menu,.post__menu-item').forEach(function(e){
            var m=e.closest('.post__menu')||e; m.style.display='none';});
          window.scrollTo(0,0);})()""")
        time.sleep(0.8)
        print("  liveness:", live(p, "post"))
        print("  theme    :", p.ev("document.documentElement.getAttribute('data-lguser-theme')||'default(light)'"))
        print("  landed on:", p.ev("location.pathname"))
        # The claim under the mockup, measured: nothing on this page says "back".
        back = p.ev("""(function(){
          var vis=[].slice.call(document.querySelectorAll('a,button')).filter(function(e){
            var r=e.getBoundingClientRect(); if(!r.width||!r.height) return false;
            var t=((e.getAttribute('aria-label')||'')+' '+(e.textContent||'')).toLowerCase();
            return /back|hub/.test(t);});
          return vis.map(function(e){return (e.getAttribute('aria-label')||e.textContent||'')
            .replace(/\\s+/g,' ').trim().slice(0,40);});})()""")
        print("  VISIBLE controls mentioning back/hub:", back or "NONE")
        shot(p, "02-post")

        print("\n=== the Back that EXISTS today, and how deep it is ===")
        shot(p, "03-tray-closed")          # the bar as it sits, nothing tapped yet
        ok, hit = tap(p, '#looth-tabbar button[aria-label="Menu"]')   # labelled Menu, shows "Nav"
        print("  opened Nav tray   :", ok, hit)
        time.sleep(1.0)
        vis = p.ev("""(function(){var b=document.querySelector('.lt-navback');
          if(!b) return 'no .lt-navback';var r=b.getBoundingClientRect();
          return {visible:!!(r.width&&r.height), y:Math.round(r.top), label:(b.textContent||'').trim()};})()""")
        print("  the buried Back   :", vis)
        shot(p, "04-tray-open-back-buried")

        print(f"\n  frames -> {OUT}")
    finally:
        try: p.ws.close()
        except Exception: pass
        try: urllib.request.urlopen(CDP + f"/json/close/{t['id']}").read()
        except Exception: pass


if __name__ == "__main__":
    sys.exit(main())
