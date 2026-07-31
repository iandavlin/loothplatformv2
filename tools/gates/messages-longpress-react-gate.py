#!/usr/bin/env python3
"""
messages-longpress-react — reacting to a MESSAGE on a phone must actually be possible.

Ian, 2026-07-31: "reacting in the messages seems to be broken." It was. The React row
lives inside an action sheet that only a PRESS-AND-HOLD opens, and the sheet was closing
itself ~200ms after it appeared: at 480ms, with the finger still down, #mg-acts (a
full-screen backdrop) painted UNDER the finger, so the trailing click on release targeted
the backdrop and the dismiss handler read it as "tap to dismiss". The lpAt guard exists to
eat exactly that click but was ordered BELOW the dismiss check.

WHY THIS IS A GATE AND NOT JUST A FIX (docs/CRAFT-STANDARD.md: a defect class found TWICE
gets encoded). This is the THIRD long-press miss to reach Ian through a green suite:
  1. mobile-hub.js holdTargetFrom() claiming the 🔔/✉ follow toggles  (8405055)
  2. the same shape again on the consolidated Follow pill             (mobile-hub.js note)
  3. this one — the messages action sheet closing on its own trailing click
All three are STRUCTURALLY INVISIBLE to a synthetic click: CDP/Playwright .click()
dispatches in single-digit milliseconds and can never cross a 380/480ms hold threshold.
So this gate presses with a REAL touch sequence — touchStart, wall-clock sleep, touchEnd —
and asserts the goal (a reaction was WRITTEN), not the pixel.

IT ASSERTS AN ABSENCE AS WELL AS A PRESENCE, because "the sheet opened" was true even on
the broken build — it opened and then closed. The load-bearing assertion is that it is
STILL open after the finger lifts, and that the emoji is what is actually under the
tap coordinates (hit-test), not the backdrop that was covering it.

Run:
    python3 tools/gates/messages-longpress-react-gate.py --url http://127.0.0.1:8899
Needs:
    - chrome-dev on 9222
    - a proxy/origin serving the build under test, with member cookies injected
      (tools/exercise-harness/endpoint-swap-proxy.py --rewrite-origin), where the
      member is a participant in at least one thread that has messages
Exit: 0 green, 1 RED, 2 CANNOT RUN — run-all.sh's convention.
"""
import argparse, json, sys, time, urllib.request

CDP = "http://127.0.0.1:9222"
NO_VERDICT = 2
HOLD_MS = 700          # comfortably past messenger-sheet.js's 480ms threshold
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

    def drain(self, secs=1.2):
        end = time.time() + secs; self.ws.settimeout(0.25)
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

    def hold(self, x, y, ms):
        """A REAL press-and-hold. The wall-clock sleep between the two dispatches is
           the entire point — .click() can never reproduce it."""
        self.send("Input.dispatchTouchEvent", {"type": "touchStart",
                                               "touchPoints": [{"x": x, "y": y}]})
        time.sleep(ms / 1000.0)
        self.send("Input.dispatchTouchEvent", {"type": "touchEnd", "touchPoints": []})

    def tap(self, x, y):
        self.send("Input.dispatchTouchEvent", {"type": "touchStart",
                                               "touchPoints": [{"x": x, "y": y}]})
        time.sleep(0.06)
        self.send("Input.dispatchTouchEvent", {"type": "touchEnd", "touchPoints": []})

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


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default="http://127.0.0.1:8899")
    ap.add_argument("--path", default="/hub/")
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
        # ⚠️ Chrome will otherwise reuse a cached messenger-sheet.js and this gate will
        # silently grade a build that is not the one under test.
        p.send("Network.setCacheDisabled", {"cacheDisabled": True})
        p.send("Network.clearBrowserCache")
        p.send("Emulation.setDeviceMetricsOverride",
               {"width": 390, "height": 844, "deviceScaleFactor": 3, "mobile": True})
        p.send("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})
        p.send("Emulation.setUserAgentOverride", {"userAgent":
            "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 "
            "(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1"})

        p.send("Page.navigate", {"url": base + a.path})
        for _ in range(240):
            time.sleep(0.25)
            try:
                if p.ev("document.readyState") == "complete": break
            except Exception: pass
        time.sleep(5)

        if p.ev("typeof window.openMessenger") != "function":
            cannot_run("messenger-sheet.js never loaded (no window.openMessenger) — "
                       "the page is not the mobile Hub, or pwa.js did not inject it")
        p.ev("window.openMessenger()")
        time.sleep(3)
        if not p.ev("!!document.querySelector('#mg-msgs')"):
            cannot_run("the messenger sheet did not open")
        if not p.ev("document.querySelectorAll('[data-mg-thread]').length"):
            cannot_run("this member has no message threads — every assertion below "
                       "would pass vacuously")

        p.ev("document.querySelector('[data-mg-thread]').click()")
        time.sleep(3.5)
        if not p.ev("document.querySelectorAll('[data-mg-msg-id]').length"):
            cannot_run("the opened thread has no messages — nothing to react to")

        # Scroll FIRST, settle, THEN measure. A rect read in the same tick as
        # scrollIntoView() can be pre-scroll, putting the touch outside the viewport —
        # which fails the gate for a reason that has nothing to do with the defect.
        p.ev("[...document.querySelectorAll('[data-mg-msg-id]')].pop()"
             ".scrollIntoView({block:'center'})")
        time.sleep(1.2)
        box = p.ev("""(() => {
          const b = [...document.querySelectorAll('[data-mg-msg-id]')].pop();
          const r = b.getBoundingClientRect();
          return { x: Math.round(r.x + r.width/2), y: Math.round(r.y + r.height/2),
                   id: b.getAttribute('data-mg-msg-id'),
                   inViewport: r.top >= 0 && r.bottom <= window.innerHeight };
        })()""")
        if not box or not box.get("inViewport"):
            cannot_run(f"the target bubble is not inside the viewport ({box}) — "
                       "the press would land on nothing")

        log(f"  (real {HOLD_MS}ms hold on message {box['id']} at "
            f"{box['x']},{box['y']})")
        p.hold(box["x"], box["y"], HOLD_MS)
        time.sleep(1.3)

        # THE LOAD-BEARING ASSERTION. On the broken build the sheet DID open — and then
        # closed itself on the trailing click. "Opened at some point" is therefore not
        # evidence; still open once the finger is up is.
        check("the action sheet is STILL OPEN after the finger lifts",
              p.ev("""(() => { const a = document.querySelector('#mg-acts');
                       return !!a && a.classList.contains('is-on'); })()"""), True)

        check("the React row rendered its emoji buttons",
              p.ev("document.querySelectorAll('#mg-acts [data-rx]').length"), 6)

        emoji = p.ev("""(() => {
          const b = document.querySelector('#mg-acts [data-rx]');
          if (!b) return null;
          const r = b.getBoundingClientRect();
          return { x: Math.round(r.x + r.width/2), y: Math.round(r.y + r.height/2),
                   e: b.getAttribute('data-rx'),
                   w: Math.round(r.width), h: Math.round(r.height) };
        })()""")
        if not emoji:
            check("an emoji button exists to tap", "absent", "present")
            return finish()

        # PRESENCE IS NOT REACHABILITY (CLAUDE.md). A hidden sheet still yields buttons
        # with a 0x0 rect at 0,0; tapping there hits whatever is really on top.
        check("the emoji button has a real tappable box",
              bool(emoji["w"] >= 24 and emoji["h"] >= 24), True)
        under = p.ev(f"""(() => {{
          const el = document.elementFromPoint({emoji['x']}, {emoji['y']});
          return el ? (el.tagName + '.' + String(el.className)) : 'nothing';
        }})()""")
        check("the emoji is what is ACTUALLY under those coordinates",
              bool(isinstance(under, str) and "mg-rxbtn" in under), True)
        if not (isinstance(under, str) and "mg-rxbtn" in under):
            log(f"        (elementFromPoint returned {under!r})")

        # And the goal: a reaction is actually WRITTEN.
        p.events = []
        p.tap(emoji["x"], emoji["y"])
        time.sleep(3); p.drain(1.5)
        status = None
        for e in p.events:
            if (e.get("method") == "Network.responseReceived"
                    and "/entries/" in e["params"]["response"]["url"]):
                status = e["params"]["response"]["status"]
        check("tapping the emoji POSTs the reaction and the server accepts it",
              status, 200)
    finally:
        p.close(); close_page(tid)
    return finish()


def finish():
    log(f"\n  {passes} passed, {failures} failed")
    sys.exit(1 if failures else 0)


main()
