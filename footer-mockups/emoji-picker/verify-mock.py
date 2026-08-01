#!/usr/bin/env python3
"""Drive the emoji-picker mock in a real browser before handing Ian the URL.

Not the shipping gate — this proves the MOCK demonstrates what I claim it does,
and it is the skeleton the real gate will grow from.

chrome-dev maps dev2 -> 172.31.78.94 (not 127.0.0.1), so $loothdev_src_local is 0
and the dev gate cookie is REQUIRED. Gate cookie takes a LEADING DOT.
"""
import json, sys, urllib.request, time

CDP = "http://127.0.0.1:9222"
URL = "https://dev2.loothgroup.com/footer-mockups/emoji-picker/"
GATE = "qShCjBdCVXLie7wcQddsprkYj4SuaXu7UJeYAHHG"

FAIL, OKS = [], []
def ok(m):  OKS.append(m);  print("  \033[32mPASS\033[0m %s" % m)
def bad(m, d=""): FAIL.append(m); print("  \033[31mFAIL\033[0m %s %s" % (m, d))

import websocket
req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
tgt = json.loads(urllib.request.urlopen(req, timeout=10).read())
ws = websocket.create_connection(tgt["webSocketDebuggerUrl"], timeout=30,
                                 suppress_origin=True)
n = [0]
def send(method, params=None):
    n[0] += 1
    ws.send(json.dumps({"id": n[0], "method": method, "params": params or {}}))
    while True:
        m = json.loads(ws.recv())
        if m.get("id") == n[0]:
            if "error" in m: raise RuntimeError("%s: %s" % (method, m["error"]))
            return m.get("result", {})
def ev(expr):
    r = send("Runtime.evaluate", {"expression": expr, "returnByValue": True})
    return r.get("result", {}).get("value")

send("Page.enable"); send("Runtime.enable"); send("Network.enable")
send("Network.clearBrowserCookies")          # shared profile — clear first
send("Network.setCookie", {"name": "loothdev_auth", "value": GATE,
                           "domain": ".dev2.loothgroup.com", "path": "/", "secure": True})
send("Page.navigate", {"url": URL})
time.sleep(2.5)

title = ev("document.title") or ""
if "just a moment" in title.lower() or "attention required" in title.lower():
    bad("Cloudflare challenge, not the mock", title); sys.exit(1)
if "Emoji picker" not in title:
    bad("wrong page", "title=%r" % title[:80]); sys.exit(1)
ok("served the mock, not a challenge or the gate 403 (title=%r)" % title)

# 0. no JS errors
errs = ev("window.__errs__ === undefined ? 'nohook' : 'x'")
nvar = ev("typeof CATS !== 'undefined' && CATS.length")
if nvar: ok("script ran, %d categories built" % nvar)
else:    bad("CATS never built — the mock's script threw")

# 1. four composers rendered
cnt = ev("document.querySelectorAll('.dev').length")
if cnt == 4: ok("all 4 demo composers rendered (A/B x desk/phone)")
else:        bad("expected 4 composers, got %r" % cnt)

# 2. picker is CLOSED at rest
closed = ev("document.querySelector('#A-desk .pan').hidden === true")
if closed: ok("panel starts closed")
else:      bad("panel is open at rest")

# 3. open it via a real click on the ☺, hit-tested first
#    (blind clicks landing on fixed furniture is a known false-PASS here)
hit = ev("""(function(){
  var b = document.querySelector('#A-desk .emo-btn');
  var r = b.getBoundingClientRect();
  b.scrollIntoView({block:'center'});
  r = b.getBoundingClientRect();
  var el = document.elementFromPoint(r.left + r.width/2, r.top + r.height/2);
  return el === b || b.contains(el) ? 'hit' : 'blocked:' + (el && el.className);
})()""")
if hit == "hit": ok("the ☺ button is hit-testable, nothing covers it")
else:            bad("☺ is covered", str(hit))

ev("document.querySelector('#A-desk .emo-btn').click()")
if ev("document.querySelector('#A-desk .pan').hidden === false"):
    ok("clicking ☺ opens the panel")
else:
    bad("panel did not open on click")

# 4. THE ONE THAT MATTERS — insert at the CURSOR, not append
res = ev("""(function(){
  var ta = document.querySelector('#A-desk .ta');
  ta.value = 'hello world';
  ta.dispatchEvent(new InputEvent('input',{bubbles:true}));
  ta.selectionStart = ta.selectionEnd = 5;          // between 'hello' and ' world'
  var btn = document.querySelector('#A-desk .pan .eo');   // first emoji (👍)
  var glyph = btn.textContent;
  btn.click();
  // ⚠️ expected caret computed IN JS. '👍'.length is 2 (UTF-16 surrogate pair) but
  // Python's len('👍') is 1 — computing this across the boundary is off by one per
  // astral emoji, and would have reported working code as broken.
  return JSON.stringify({v: ta.value, glyph: glyph, caret: ta.selectionStart,
                         wantCaret: 5 + glyph.length, jsLen: glyph.length});
})()""")
r = json.loads(res)
want = "hello" + r["glyph"] + " world"
if r["v"] == want:
    ok("inserted AT THE CURSOR: %r" % r["v"])
else:
    bad("did not insert at cursor", "got %r want %r" % (r["v"], want))
if r["caret"] == r["wantCaret"]:
    ok("caret sits after the emoji (%d; glyph is %d UTF-16 units, %d python chars)"
       % (r["caret"], r["jsLen"], len(r["glyph"])))
else:
    bad("caret misplaced", "at %r want %r" % (r["caret"], r["wantCaret"]))

# 5. the silent-no-op class: Send must be ENABLED after a picker-only insert
snd = ev("""(function(){
  var ta = document.querySelector('#B-desk .ta'), s = document.querySelector('#B-desk .send');
  ta.value = ''; ta.dispatchEvent(new InputEvent('input',{bubbles:true}));
  var before = s.disabled;
  document.querySelector('#B-desk .emo-btn').click();
  document.querySelector('#B-desk .strip .eo').click();
  return JSON.stringify({before: before, after: s.disabled, val: ta.value});
})()""")
s = json.loads(snd)
if s["before"] is True and s["after"] is False and s["val"]:
    ok("emoji-only message ENABLES Send (the dispatched input event works)")
else:
    bad("Send state wrong after emoji-only insert", json.dumps(s))

# 6. Enter still sends; Shift+Enter still newlines
enter = ev("""(function(){
  var ta = document.querySelector('#A-desk .ta');
  ta.value = 'ok'; ta.dispatchEvent(new InputEvent('input',{bubbles:true}));
  var e = new KeyboardEvent('keydown',{key:'Enter',shiftKey:false,bubbles:true,cancelable:true});
  ta.dispatchEvent(e);
  var sent = (ta.value === '') && e.defaultPrevented;
  ta.value = 'a'; ta.dispatchEvent(new InputEvent('input',{bubbles:true}));
  var e2 = new KeyboardEvent('keydown',{key:'Enter',shiftKey:true,bubbles:true,cancelable:true});
  ta.dispatchEvent(e2);
  return JSON.stringify({sent: sent, shiftNotPrevented: !e2.defaultPrevented, still: ta.value});
})()""")
en = json.loads(enter)
if en["sent"]:  ok("Enter still sends (and preventDefault fires)")
else:           bad("Enter no longer sends", json.dumps(en))
if en["shiftNotPrevented"] and en["still"] == "a":
    ok("Shift+Enter is left alone → newline behaviour intact")
else:
    bad("Shift+Enter was swallowed", json.dumps(en))

# 7. Escape closes and returns focus to the textarea
esc = ev("""(function(){
  document.querySelector('#A-desk .emo-btn').click();
  var open = document.querySelector('#A-desk .pan').hidden === false;
  document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
  return JSON.stringify({wasOpen: open,
    nowClosed: document.querySelector('#A-desk .pan').hidden === true,
    focused: document.activeElement === document.querySelector('#A-desk .ta')});
})()""")
e = json.loads(esc)
if e["wasOpen"] and e["nowClosed"] and e["focused"]:
    ok("Escape closes the panel and hands focus back to the composer")
else:
    bad("Escape behaviour wrong", json.dumps(e))

# 8. emoji actually RENDER here (dev2 has Noto Color Emoji; dev1 did not).
#    ⚠️ the first draft of this called ok() unconditionally and PRINTED "0x0" while
#    passing — the panel was closed, so nothing had a layout box. A check that cannot
#    fail is not a check. Open the panel first, then demand a non-zero box.
meas = ev("""(function(){
  document.querySelector('#A-desk .emo-btn').click();          // must be OPEN to have layout
  var b = document.querySelector('#A-desk .pan .eo');
  var r = b.getBoundingClientRect();
  return JSON.stringify({w: Math.round(r.width), h: Math.round(r.height),
                         font: (document.fonts && document.fonts.check('16px "Noto Color Emoji"')) || false,
                         glyph: b.textContent});
})()""")
m = json.loads(meas)
if m["w"] > 8 and m["h"] > 8:
    ok("emoji cell has a real painted box (%dx%d, glyph %s, NotoColorEmoji=%s)"
       % (m["w"], m["h"], m["glyph"], m["font"]))
else:
    bad("emoji cell has no box — glyphs may be tofu", json.dumps(m))

# 9. NOT CLIPPED BY AN ANCESTOR.
#    CRAFT-STANDARD: a defect class found twice gets a gate before its second fix.
#    This is the second time — @0cd0a72 the card react palette was clipped by its own
#    parent on LIVE, and the first draft of this mock did it again (panel 286px tall
#    above a 132px thread, sliced off by .dev{overflow:hidden}).
#    "Present in the DOM" and "visible to the member" are different claims.
clip = ev("""(function(){
  var out = [];
  ['#A-desk','#A-phone'].forEach(function(sel){
    var dev = document.querySelector(sel + ' .dev') || document.querySelector(sel + ' > .dev');
    var btn = document.querySelector(sel + ' .emo-btn');
    var pan = document.querySelector(sel + ' .pan');
    if (pan.hidden) btn.click();
    var p = pan.getBoundingClientRect(), d = dev.getBoundingClientRect();
    // walk up for any ancestor that actually clips
    var clipper = null, n = pan.parentElement;
    while (n && n !== document.body) {
      var ov = getComputedStyle(n).overflow;
      if (ov !== 'visible') { clipper = n; break; }
      n = n.parentElement;
    }
    var c = clipper ? clipper.getBoundingClientRect() : d;
    out.push({sel: sel, panTop: Math.round(p.top), clipTop: Math.round(c.top),
              cut: Math.round(c.top - p.top),
              clipper: clipper ? (clipper.className || clipper.tagName) : 'none'});
  });
  return JSON.stringify(out);
})()""")
for c in json.loads(clip):
    if c["cut"] <= 0:
        ok("%s panel is NOT clipped (top %d vs clipper %d, by .%s)"
           % (c["sel"], c["panTop"], c["clipTop"], c["clipper"]))
    else:
        bad("%s panel is CLIPPED — %dpx sliced off the top by .%s"
            % (c["sel"], c["cut"], c["clipper"]))

# screenshots, both themes
ev("""[].forEach.call(document.querySelectorAll('.ta'), function(t){
     t.value=''; t.dispatchEvent(new InputEvent('input',{bubbles:true})); });""")
for theme in ("light", "dark"):
    ev("setTheme('%s')" % theme)
    # open every picker so the shot shows the thing under review, not a closed composer
    ev("""[].forEach.call(document.querySelectorAll('.pan[hidden],.strip[hidden]'),
           function(p){ p.hidden = false; });""")
    time.sleep(0.4)
    send("Emulation.setDeviceMetricsOverride", {"width": 1200, "height": 1500,
         "deviceScaleFactor": 1, "mobile": False})
    time.sleep(0.3)
    d = send("Page.captureScreenshot", {"captureBeyondViewport": True})
    import base64
    p = "/home/ubuntu/projects/footer-mockups/emoji-picker/shot-%s.png" % theme
    open(p, "wb").write(base64.b64decode(d["data"]))
    print("  shot -> %s" % p)

print("\n%d passed, %d failed" % (len(OKS), len(FAIL)))
sys.exit(1 if FAIL else 0)
