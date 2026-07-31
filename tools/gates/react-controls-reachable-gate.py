#!/usr/bin/env python3
"""
react-controls-reachable — the react UI must be HIT-TESTABLE, not merely present.

Two live defects, one class. Both were in the DOM, correctly styled, with sane z-index,
and both were impossible to use. A presence assertion passes on BOTH broken builds, which
is why every check before this one said the react UI was fine.

  A. THE CARD PALETTE WAS CLIPPED BY ITS OWN PARENT (forums.css:4639, added by 765dbc3
     2026-07-30, shipped to live). .fcr carried overflow:hidden so the row could shrink;
     .fcr-palette is position:absolute / bottom:calc(100% - 2px), i.e. it opens upward
     entirely OUTSIDE .fcr. Its offset parent clipped it away. Ian reported this as
     "the react button modal on all cards has a bad z" — a clipped popover and one
     painted behind something look identical from the outside, and z-index:20 was
     honoured the whole time. Assert with elementFromPoint, which is the only check that
     can tell "painted and reachable" from "present".

  B. THE MESSAGES REACT CONTROL NEEDED HOVER (site-header.css:1206, 0c7dd09 2026-07-13).
     .lg-msg__acts was revealed by :hover alone. On a touchscreen at >=641px the DESKTOP
     messages modal renders and no hover event ever fires, so the only path to reacting
     to a message was unreachable. Emulated here with hover:none + pointer:coarse rather
     than a narrow viewport, because absence of hover is the actual cause — a large
     touchscreen is exactly what a width-based test would miss.

Run:
    python3 tools/gates/react-controls-reachable-gate.py --url http://127.0.0.1:8899
Needs: chrome-dev on 9222, and a proxy serving the build under test with member cookies
       (tools/exercise-harness/endpoint-swap-proxy.py --rewrite-origin). Leg B needs that
       member to be a participant in a thread that has messages.
Exit: 0 green, 1 RED, 2 CANNOT RUN.
"""
import argparse, json, sys, time, urllib.request

CDP = "http://127.0.0.1:9222"
NO_VERDICT = 2
passes = failures = 0


def log(*a): print(" ".join(str(x) for x in a), flush=True)


def check(label, got, want):
    global passes, failures
    ok = got == want
    if ok: passes += 1
    else:  failures += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + ("" if ok else f"   got={got!r} want={want!r}"))
    return ok


_open = {"page": None, "tid": None}


def cannot_run(why):
    try:
        if _open["page"]: _open["page"].close()
        if _open["tid"]:  close_page(_open["tid"])
    except Exception: pass
    log(f"  CANNOT RUN — {why}")
    log("  (exit 2: no verdict. NOT a pass and NOT a finding.)")
    sys.exit(NO_VERDICT)


try:
    import websocket
except ImportError:
    cannot_run("python3 websocket-client is not installed")


class Page:
    def __init__(self, ws):
        self.ws = websocket.create_connection(ws, timeout=60, suppress_origin=True)
        self.n = 0; self.events = []

    def send(self, m, p=None):
        self.n += 1; i = self.n
        self.ws.send(json.dumps({"id": i, "method": m, "params": p or {}}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("id") == i:
                if "error" in r: raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})
            if "method" in r: self.events.append(r)

    def drain(self, s=1.2):
        end = time.time() + s; self.ws.settimeout(0.25)
        try:
            while time.time() < end:
                try: self.events.append(json.loads(self.ws.recv()))
                except Exception: pass
        finally: self.ws.settimeout(60)

    def ev(self, e):
        r = self.send("Runtime.evaluate",
                      {"expression": e, "returnByValue": True, "awaitPromise": True})
        if r.get("exceptionDetails"):
            raise RuntimeError("JS: " + str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")

    def tap(self, x, y):
        self.send("Input.dispatchTouchEvent", {"type": "touchStart",
                                               "touchPoints": [{"x": x, "y": y}]})
        time.sleep(0.06)
        self.send("Input.dispatchTouchEvent", {"type": "touchEnd", "touchPoints": []})

    def goto(self, url):
        self.send("Page.navigate", {"url": url})
        for _ in range(240):
            time.sleep(0.25)
            try:
                if self.ev("document.readyState") == "complete": break
            except Exception: pass
        time.sleep(5)

    def close(self):
        try: self.ws.close()
        except Exception: pass


def new_page():
    t = json.load(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")))
    return t["id"], Page(t["webSocketDebuggerUrl"])


def close_page(tid):
    try: urllib.request.urlopen(CDP + f"/json/close/{tid}").read()
    except Exception: pass


def leg_a_card_palette(p, base):
    log("\n  A. the card react palette is REACHABLE (not clipped by .fcr)")
    p.send("Emulation.setEmulatedMedia", {"features": []})
    p.send("Emulation.setTouchEmulationEnabled", {"enabled": False, "maxTouchPoints": 1})
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": 1280, "height": 900, "deviceScaleFactor": 1, "mobile": False})
    p.goto(base + "/hub/?type=discussions")

    picked = p.ev("""(() => {
      const c = [...document.querySelectorAll('.feed-card--topic')].find(x => x.querySelector('.fcr'));
      if (!c) return null;
      c.setAttribute('data-gate','1'); c.scrollIntoView({block:'center'});
      return c.getAttribute('data-topic-id');
    })()""")
    if not picked:
        cannot_run("no discussion card with a reaction bar on the feed")
    time.sleep(1.2)

    opened = p.ev("""(() => {
      const c = document.querySelector('[data-gate]');
      const vis = e => { if(!e) return false; const s=getComputedStyle(e), q=e.getBoundingClientRect();
                         return s.display!=='none' && s.visibility!=='hidden' && q.width>0 && q.height>0; };
      const add = [...c.querySelectorAll('.fcr-add')].find(vis);
      if (!add) return 'no visible .fcr-add';
      add.click(); return 'ok';
    })()""")
    if opened != "ok":
        cannot_run(f"could not open the palette ({opened}) — anon viewers have no add trigger")
    time.sleep(1.5)

    geo = p.ev("""(() => {
      const c = document.querySelector('[data-gate]');
      const pal = c.querySelector('.fcr-palette');
      if (!pal) return null;
      const r = pal.getBoundingClientRect();
      const pts = [[r.x+r.width*0.5, r.y+r.height*0.5], [r.x+8, r.y+r.height*0.5],
                   [r.right-8, r.y+r.height*0.5], [r.x+r.width*0.5, r.y+4]];
      return { visible: !pal.hasAttribute('hidden'),
               w: Math.round(r.width), h: Math.round(r.height),
               hits: pts.map(([x,y]) => {
                 const el = document.elementFromPoint(Math.round(x), Math.round(y));
                 return !!(el && el.closest('.fcr-palette'));
               }),
               parentOverflow: getComputedStyle(pal.parentElement).overflow };
    })()""")
    if not geo:
        cannot_run("the card has no .fcr-palette element")

    check("the palette is open and has a real box", bool(geo["visible"] and geo["w"] > 0), True)
    # THE LOAD-BEARING ONE. On the broken build the palette was open, styled and sized —
    # and none of it could be clicked, because its own parent clipped it.
    check("every sampled point on the palette hit-tests TO the palette",
          geo["hits"], [True, True, True, True])
    check("the palette's offset parent does not clip it",
          geo["parentOverflow"] in ("visible", "visible visible"), True)


def leg_b_messages_touch(p, base):
    log("\n  B. the messages React control is REACHABLE WITHOUT HOVER (touchscreen)")
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": 1024, "height": 900, "deviceScaleFactor": 2, "mobile": False})
    p.send("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})
    # >=641px so the DESKTOP modal renders, but with NO hover capability.
    p.send("Emulation.setEmulatedMedia", {"features": [
        {"name": "hover", "value": "none"},
        {"name": "any-hover", "value": "none"},
        {"name": "pointer", "value": "coarse"}]})
    p.goto(base + "/hub/")

    if not p.ev("matchMedia('(hover: none)').matches"):
        cannot_run("hover:none was not applied — the emulation did not take, so a pass "
                   "here would only prove the hover path works")
    if not p.ev("!!document.querySelector('[data-lg-msg-link]')"):
        cannot_run("no messages entry point in the header (not signed in?)")
    p.ev("document.querySelector('[data-lg-msg-link]').click()"); time.sleep(3)
    if not p.ev("document.querySelectorAll('[data-thread-uuid]').length"):
        cannot_run("this member has no message threads — leg B would pass vacuously")
    p.ev("document.querySelector('[data-thread-uuid]').click()"); time.sleep(3.5)
    if not p.ev("document.querySelectorAll('[data-lg-msg-id]').length"):
        cannot_run("the opened thread has no messages — nothing to react to")

    p.ev("""(() => { const b=[...document.querySelectorAll('[data-lg-msg-id]')].pop();
             b.scrollIntoView({block:'center'}); window.__rb = b.querySelector('[data-lg-react]'); })()""")
    time.sleep(1.0)
    box = p.ev("""(() => { const b = window.__rb; if (!b) return null;
      const q = b.getBoundingClientRect();
      return { x: Math.round(q.x+q.width/2), y: Math.round(q.y+q.height/2),
               w: Math.round(q.width), h: Math.round(q.height),
               inView: q.top>=0 && q.bottom<=window.innerHeight }; })()""")
    if not box:
        check("a React control exists on the message bubble", "absent", "present"); return

    check("the React control has a real box with no hover available",
          bool(box["w"] > 0 and box["h"] > 0), True)
    if not (box["w"] > 0 and box["inView"]):
        check("the React control is hit-testable", False, True); return

    under = p.ev(f"""(() => {{ const e = document.elementFromPoint({box['x']}, {box['y']});
             return e ? (e.tagName + '.' + String(e.className)) : 'nothing'; }})()""")
    check("the React control is what is ACTUALLY under its own coordinates",
          bool(isinstance(under, str) and "lg-msg__act" in under), True)
    if not (isinstance(under, str) and "lg-msg__act" in under):
        log(f"        (elementFromPoint returned {under!r})"); return

    p.events = []
    p.tap(box["x"], box["y"]); time.sleep(1.5)
    opt = p.ev("""(() => { const b=[...document.querySelectorAll('[data-lg-msg-id]')].pop();
      const o=b&&b.querySelector('.lg-msg__rx-pick [data-lg-rx]'); if(!o) return null;
      const q=o.getBoundingClientRect();
      return {x:Math.round(q.x+q.width/2), y:Math.round(q.y+q.height/2), w:Math.round(q.width)}; })()""")
    check("tapping it opens the emoji picker", bool(opt and opt["w"] > 0), True)
    if not (opt and opt["w"] > 0): return
    p.tap(opt["x"], opt["y"]); time.sleep(3); p.drain(1.5)
    status = None
    for e in p.events:
        if (e.get("method") == "Network.responseReceived"
                and "/entries/" in e["params"]["response"]["url"]):
            status = e["params"]["response"]["status"]
    check("the reaction is WRITTEN (server accepts the POST)", status, 200)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default="http://127.0.0.1:8899")
    ap.add_argument("--leg", choices=["a", "b", "both"], default="both")
    a = ap.parse_args()
    base = a.url.rstrip("/")
    try:
        urllib.request.urlopen(CDP + "/json/version", timeout=5).read()
    except Exception as e:
        cannot_run(f"no CDP engine: {e}")

    tid, p = new_page()
    _open["tid"], _open["page"] = tid, p
    try:
        p.send("Page.enable"); p.send("Runtime.enable"); p.send("Network.enable")
        # Otherwise Chrome regrades a cached stylesheet and the verdict is about main.
        p.send("Network.setCacheDisabled", {"cacheDisabled": True})
        p.send("Network.clearBrowserCache")
        if a.leg in ("a", "both"): leg_a_card_palette(p, base)
        if a.leg in ("b", "both"): leg_b_messages_touch(p, base)
    finally:
        p.close(); close_page(tid)
    log(f"\n  {passes} passed, {failures} failed")
    sys.exit(1 if failures else 0)


main()
