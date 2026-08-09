#!/usr/bin/env python3
"""Backlog 3.8 — render the two back-nav proposals INTO the real page and capture.

Ian decides from pictures, so these are not drawings over a screenshot: each option
is injected into the live 390px page and photographed, which means what he sees is
really that size, really that colour, really in that place, against the real content.

  BEFORE   the page as it ships today — no exposed way back to the hub
  OPTION A the breadcrumb card that is already there becomes the back control
  OPTION B a Back item in the bottom bar, left of Nav

WHY THOSE TWO. Ian: "there is one in the nav tab but it should be exposed." The
existing Back is inside the Nav tray (bottom-nav.js) — two taps and invisible until
the first. A is the smallest honest change: the top card ALREADY says THE HUB (twice,
which is its own small bug) and is already on every hub content page; it is context
pretending to be nothing, and turning it into an action adds no new chrome. B puts it
where a thumb actually reaches, which A does not — the top of a 844px-tall phone is
the one place a one-handed grip cannot get to.

Nothing here is a code change: the injections live only in the captured frame.
"""
import json, sys, time, base64, os, subprocess, urllib.request, websocket

CDP = "http://127.0.0.1:9222"
BASE = "https://dev2.loothgroup.com"
COOKIES = "/tmp/mobile-bugs-exercise/cookies.txt"
OUT = os.environ.get("LG_OUT", "/tmp/mobile-bugs-exercise/backnav")
POST = os.environ.get("LG_POST", "/hub/touring-tech/test-3/")

DROP_NOISE = """(function(){
  // The frame must show the SUBJECT. Two unrelated things are hidden, both declared
  // on the mockup page so the picture is not read as the whole truth:
  //  · the "Install Looth" PWA banner
  //  · the post overflow menus, which render OPEN on a fresh load at 390px for anyone
  //    who can edit (measured: visibility:visible, zero interaction) — backlog 3.9
  // Hide the BANNER'S OWN BOX, not the text node's parent: hiding the inner text left
  // a logo and an X floating in an empty card, which looks like a defect I introduced.
  var P='Add it to your home', deepest=null;
  document.querySelectorAll('*').forEach(function(e){
    if((e.textContent||'').indexOf(P)===-1) return;
    if(!deepest || e.compareDocumentPosition(deepest) & Node.DOCUMENT_POSITION_CONTAINS) deepest=e;});
  if(deepest){
    var box=deepest;
    while(box && box.parentElement){
      var r=box.getBoundingClientRect();
      if(r.height>44 && r.height<220 && r.width>200) break;
      box=box.parentElement;}
    if(box) box.style.display='none';
  }
  document.querySelectorAll('.post__menu').forEach(function(e){ e.style.display='none'; });
  window.scrollTo(0,0);
})()"""

# ── OPTION A — the card that is already there becomes the back control ──────
OPTION_A = """(function(){
  // The real node is header.forum-header--post. The first attempt matched on
  // /^THE HUB/ and failed: the markup says "The Hub" and CSS uppercases it, so the
  // regex was testing the rendered look rather than the text. Select the element.
  var card = document.querySelector('header.forum-header, .forum-header');
  if(!card) return 'NO CARD';
  var s=document.createElement('style');
  s.textContent = [
    '.lgmock-back{display:flex;align-items:center;gap:10px;width:100%;',
      'min-height:48px;padding:12px 14px;margin:0 0 10px;box-sizing:border-box;',
      'background:var(--lg-sage-tint,#eef2e3);border:1px solid var(--lg-sage-3,#d4e0b8);',
      'border-radius:12px;color:var(--lg-sage-d,#4d5f35);',
      'font:700 15px/1 var(--lg-font-sans,system-ui);text-decoration:none;cursor:pointer}',
    '.lgmock-back svg{flex:0 0 auto;width:20px;height:20px}',
    '.lgmock-back .lgmock-sub{margin-left:auto;font-weight:600;font-size:12.5px;',
      'color:var(--lg-mute,#6b7362);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:52%}'
  ].join('');
  document.head.appendChild(s);
  var a=document.createElement('a');
  a.className='lgmock-back'; a.href='/hub/';
  a.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"'+
    ' stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>'+
    '<span>The Hub</span><span class="lgmock-sub">Touring Tech</span>';
  card.parentNode.insertBefore(a, card);
  card.style.display='none';           // the old card is REPLACED, not stacked on
  return 'ok';
})()"""

# ── OPTION B — a Back item in the bottom bar, where a thumb reaches ─────────
OPTION_B = """(function(){
  var bar=document.getElementById('looth-tabbar'); if(!bar) return 'NO TABBAR';
  var s=document.createElement('style');
  s.textContent = [
    '#looth-tabbar .lgmock-btab{display:flex;flex-direction:column;align-items:center;',
      'justify-content:center;gap:3px;text-decoration:none;color:var(--lg-sage-d,#4d5f35);',
      'font:700 11px/1 var(--lg-font-sans,system-ui);min-width:56px}',
    '#looth-tabbar .lgmock-btab svg{width:24px;height:24px}'
  ].join('');
  document.head.appendChild(s);
  var a=document.createElement('a');
  a.className='lgmock-btab'; a.href='/hub/';
  a.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'+
    ' stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>'+
    '<span>Hub</span>';
  bar.insertBefore(a, bar.firstChild);
  return 'ok';
})()"""


def gate(): return open("/tmp/mobile-bugs-exercise/gate.txt").read().strip()


def jwt():
    o = subprocess.run(["sudo", "-u", "looth-dev", "wp", "--path=/var/www/dev", "eval",
                        'echo looth_auth_mint_jwt(get_user_by("id", 1912));'],
                       capture_output=True, text=True).stdout.splitlines()
    return [l for l in o if l.startswith("eyJ")][-1]


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


def main():
    t = json.load(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")))
    p = P(t["webSocketDebuggerUrl"])
    os.makedirs(OUT, exist_ok=True)
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

        def go(settle=5.0):
            p.send("Page.navigate", {"url": BASE + POST})
            for _ in range(160):
                time.sleep(0.25)
                try:
                    if p.ev("document.readyState") == "complete": break
                except Exception: pass
            time.sleep(settle)
            p.ev(DROP_NOISE); time.sleep(0.5)

        def check(where):
            st = p.ev("""[innerWidth, matchMedia('(max-width:640px)').matches,
                          !!document.querySelector('.lg-chrome__avatar'),
                          !!document.querySelector('.lg-chrome__signin')]""")
            w, phone, av, si = st
            if w > 640 or not phone or si or not av:
                raise SystemExit(f"!! ABORT {where}: w={w} phone={phone} avatar={av} signin={si}")

        def shot(name):
            r = p.send("Page.captureScreenshot", {"format": "png"})
            open(f"{OUT}/{name}.png", "wb").write(base64.b64decode(r["data"]))
            print(f"  captured {name}")

        # force the DEFAULT theme once — the shared profile persists whatever ran last
        go(1.5)
        p.ev("localStorage.setItem('lg-set-boot',JSON.stringify({theme:'default',dark:false,"
             "vars:{},scale:1,feed:'immersive'}));localStorage.setItem('lg-set-theme','default');")

        print("=== BEFORE ===");  go(); check("before"); shot("10-before")
        print("=== OPTION A — the existing card becomes the back control ===")
        go(); check("A"); print("  inject:", p.ev(OPTION_A)); time.sleep(0.7); shot("11-option-a")
        print("=== OPTION B — Back in the bottom bar ===")
        go(); check("B"); print("  inject:", p.ev(OPTION_B)); time.sleep(0.7); shot("12-option-b")
        print(f"\n  frames -> {OUT}")
    finally:
        try: p.ws.close()
        except Exception: pass
        try: urllib.request.urlopen(CDP + f"/json/close/{t['id']}").read()
        except Exception: pass


if __name__ == "__main__":
    sys.exit(main())
