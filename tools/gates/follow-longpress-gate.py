#!/usr/bin/env python3
"""
follow-longpress-gate — a REAL, TIMED finger press on the 🔔/✉ toggles.

WHY THIS GATE EXISTS (CRAFT-STANDARD: a defect class found TWICE becomes a gate).

Ian, from his phone 2026-07-30: "I see the buttons on mobile" + "they don't seem to
stay on when pushed." The store was fine, the endpoint was fine, the row wrote fine.
What was broken is that THE CLICK NEVER ARRIVED:

  mobile-hub.js's long-press-to-react trigger matched el.closest('.fc-actions'), and
  the mobile follow pair is nested INSIDE it —
     div.fc-actions > div.feed-card__actions.lg-card-actions
       > span.lg-act-follow > button.fc-notify[data-follow]
  so holding the bell for >=380ms (HOLD_MS) set longPressed, opened the reaction
  palette, and the capture-phase swallower killed the release click with
  stopImmediatePropagation(). forums.js's [data-follow] delegate is on the BUBBLE
  phase, so it never ran.

THE REASON A 25/25 GREEN SUITE MISSED IT — and the reason this file dispatches its
own events instead of calling .click():

  Synthetic clicks (CDP Input.dispatchMouseEvent as a pair, Playwright .click(),
  el.click()) land in single-digit milliseconds. They CANNOT cross a 380ms threshold.
  Every automated tap ever run against this control took a path a human finger cannot
  take, so the bug was structurally invisible to automation. This gate therefore holds
  the press for PRESS_SLOW_MS with a real wait between touchStart and touchEnd.

It also asserts the SERVER, not just the pixel: an optimistic UI that flips and then
silently reverts must read as a FAIL, and only the store can tell us which happened.

Run:  python3 tools/gates/follow-longpress-gate.py [--hub URL]
Needs: chrome-dev on 127.0.0.1:9222, the exercise harness on :8791/:8792,
       /tmp/tf-gate/cookies.txt (WP auth cookies for the acting member).
       Bring the harness up with the recipe in run-all.sh's held-out block.

Exit:  0 green, 1 RED (real findings), 2 CANNOT RUN (no verdict).
       The 0/1/2 split is run-all.sh's convention and it matters here: this gate
       needs an engine AND a loopback harness AND mintable cookies, so most of its
       failure modes are ENVIRONMENTAL. Reporting those as red would be
       indistinguishable from a real regression — which is exactly how craft gate 2
       sat "red" for weeks while it was in fact dead.
"""
import json, sys, time, urllib.request, subprocess, argparse

CDP = "http://127.0.0.1:9222"
DEFAULT_HUB = "http://127.0.0.1:8791/hub/"
COOKIES = "/tmp/tf-gate/cookies.txt"
UID = 1912

# A deliberate press on a 38px phone target. HOLD_MS in mobile-hub.js is 380.
PRESS_SLOW_MS = 600     # unambiguously past the long-press threshold
PRESS_FAST_MS = 80      # a flick — below the threshold

passes = failures = 0
def log(*a): print(" ".join(str(x) for x in a), flush=True)
def check(label, got, want):
    global passes, failures
    ok = got == want
    if ok: passes += 1
    else:  failures += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + ("" if ok else f"   got={got!r} want={want!r}"))
    return ok

def db_notify(topic_id):
    """Ground truth straight out of Postgres — never the UI's opinion of itself."""
    r = subprocess.run(
        ["sudo", "-u", "bb-mirror", "psql", "-d", "looth", "-Atc",
         f"select count(*) from forums.topic_follow where user_id={UID} and topic_id={topic_id};"],
        capture_output=True, text=True)
    return r.stdout.strip() == "1"

def db_clear(topic_id):
    subprocess.run(["sudo", "-u", "bb-mirror", "psql", "-d", "looth", "-qc",
                    f"delete from forums.topic_follow where user_id={UID} and topic_id={topic_id};"],
                   capture_output=True, text=True)

NO_VERDICT = 2

_open = {"page": None, "tid": None}

def cannot_run(why):
    # Tidy the CDP page first: a leaked about:blank target accumulates in the
    # shared engine and the NEXT run inherits a slower, more memory-starved browser.
    try:
        if _open["page"]: _open["page"].close()
        if _open["tid"]:  close_page(_open["tid"])
    except Exception: pass
    log(f"  CANNOT RUN — {why}")
    log("  (exit 2: no verdict. This is NOT a pass and NOT a finding.)")
    sys.exit(NO_VERDICT)


def must_goto(p, url, what):
    """Navigate, or declare NO VERDICT.

    Every phase below re-navigates, and hydration can lose its race at ANY of them
    on a loaded box — not just the first. A mid-run hydration miss means the press
    was never exercised, so calling it red would invent a finding; and letting it
    raise (as it did once) reports a Python traceback as a gate result. Both are
    worse than saying plainly that we got no verdict."""
    if not goto(p, url):
        cannot_run(f"hydration never completed for: {what}")


def must_trigger(p, what):
    b = pick_follow_trigger(p)
    if not b:
        cannot_run(f"no visible [data-follow-open] at 390px for: {what}")
    return b


def modal_open(p):
    return p.ev("!!document.querySelector('#lg-follow-modal:not([hidden])')")


def modal_switch(p, ch):
    """A VISIBLE switch inside the OPEN modal, with its centre point.

    Scoped to #lg-follow-modal on purpose. A bare [data-follow] lookup would also
    match the topic page's inline pair and — more dangerously — would return a
    zero-box node from a CLOSED modal, so a swallowed press would look like a
    missing element rather than the defect it is.
    """
    return p.ev("""(() => {
      const m = document.querySelector('#lg-follow-modal:not([hidden])');
      if (!m) return null;
      const b = m.querySelector('[data-follow="%s"]');
      if (!b) return null;
      const r = b.getBoundingClientRect();
      if (!(r.width > 0 && r.height > 0)) return null;
      return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
    })()""" % ch)


def sw_pressed(p, ch):
    return p.ev("""(() => {
      const m = document.querySelector('#lg-follow-modal:not([hidden])');
      if (!m) return null;
      const b = m.querySelector('[data-follow="%s"]');
      return b ? b.getAttribute('aria-pressed') : null;
    })()""" % ch)


def pill_on(p, topic):
    """Does the CARD's pill show following state, without the modal being opened?

    §15.2 is the reason this is asserted separately from the store: consolidation is
    only acceptable while the control still carries visible state. A pill that reads
    "Follow" over a live subscription is the §8.1.3 lie, whatever the DB says.
    """
    return p.ev("""(() => {
      for (const c of document.querySelectorAll('[data-follow-open][data-topic-id="%s"]')) {
        const r = c.getBoundingClientRect();
        if (r.width > 0 && r.height > 0) return c.classList.contains('is-on');
      }
      return null;
    })()""" % topic)

try:
    import websocket  # websocket-client
except ImportError:
    cannot_run("python3 websocket-client is not installed")


class Page:
    """ONE persistent CDP connection.

    Deliberately not per-command sockets: device-metric overrides are scoped to the
    session that set them, so a fresh socket per call silently drops the mobile
    emulation and the whole run false-PASSES as desktop. That trap is already
    recorded in the keeper memory; this class is the fix for it.
    """
    def __init__(self, ws_url):
        # suppress_origin: chrome rejects a CDP websocket that carries an Origin
        # header unless it was launched with --remote-allow-origins. Not sending one
        # is the fix that needs no change to the shared chrome-dev service.
        self.ws = websocket.create_connection(ws_url, timeout=30, suppress_origin=True)
        self.n = 0
    def send(self, method, params=None):
        self.n += 1; i = self.n
        self.ws.send(json.dumps({"id": i, "method": method, "params": params or {}}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("id") == i:
                if "error" in r: raise RuntimeError(f"{method}: {r['error']}")
                return r.get("result", {})
    def ev(self, expr):
        r = self.send("Runtime.evaluate",
                      {"expression": expr, "returnByValue": True, "awaitPromise": True})
        if r.get("exceptionDetails"):
            raise RuntimeError("JS: " + str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")
    def close(self):
        try: self.ws.close()
        except Exception: pass

    def press(self, x, y, hold_ms):
        """A REAL finger: touchStart, WAIT, touchEnd. The wait is the entire point."""
        pt = [{"x": x, "y": y, "radiusX": 12, "radiusY": 12, "force": 1.0, "id": 1}]
        self.send("Input.dispatchTouchEvent", {"type": "touchStart", "touchPoints": pt})
        time.sleep(hold_ms / 1000.0)
        self.send("Input.dispatchTouchEvent", {"type": "touchEnd", "touchPoints": []})


def new_page(hub):
    t = json.load(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")))
    return t["id"], Page(t["webSocketDebuggerUrl"])

def close_page(tid):
    try: urllib.request.urlopen(CDP + f"/json/close/{tid}").read()
    except Exception: pass


def setup(p, hub):
    p.send("Page.enable"); p.send("Runtime.enable"); p.send("Network.enable")
    # Mobile emulation on THIS session, before any navigation.
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": 390, "height": 844, "deviceScaleFactor": 3, "mobile": True})
    p.send("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})
    p.send("Emulation.setUserAgentOverride", {
        "userAgent": "Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) "
                     "AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1"})
    host = hub.split("/")[2].split(":")[0]
    cks = []
    for line in open(COOKIES):
        line = line.strip()
        if not line or "=" not in line: continue
        k, v = line.split("=", 1)
        cks.append({"name": k, "value": v, "domain": host, "path": "/"})
    p.send("Network.setCookies", {"cookies": cks})


def goto(p, url, tries=2):
    """Navigate and WAIT FOR REAL HYDRATION, not merely for readyState.

    The toggles are server-rendered inert; their true state arrives via the batch
    GET that sets body.lg-follow-authed. Pressing before that lands would test an
    unhydrated button and prove nothing. Generous timeouts on purpose: the loopback
    harness is a single-threaded `php -S` serving ~19 scripts serially, so a cold
    first paint is genuinely slow — that is harness latency, never a product defect,
    and it must not read as a failure.
    """
    for attempt in range(tries):
        p.send("Page.navigate", {"url": url})
        for _ in range(160):                       # up to 40s for document complete
            time.sleep(0.25)
            try:
                if p.ev("document.readyState") == "complete": break
            except Exception: pass
        for _ in range(160):                       # up to 40s for the batch GET
            try:
                if p.ev("document.body.classList.contains('lg-follow-authed')"):
                    return True
            except Exception: pass
            time.sleep(0.25)
        if attempt + 1 < tries:
            log(f"  (hydration slow — reloading, attempt {attempt + 2}/{tries})")
    return False


def pick_follow_trigger(p):
    """The mobile card's follow affordance that actually has a box in THIS viewport.

    ⚠️ §15 VARIANT A MOVED THIS TARGET. The mobile feed card no longer carries an
    inline [data-follow] pair — it carries ONE consolidated [data-follow-open] pill
    (Ian 2026-07-30), and the 🔔/✉ switches now live in a body-level modal. Left
    pointed at [data-follow] this gate finds nothing on the hub and exits 2 — not a
    false red, but a gate that silently stops proving anything, which is how craft
    gate 2 sat dead for weeks.

    THE DEFECT IS STILL REACHABLE, which is why this still matters: .fc-follow
    renders inside .lg-card-actions, inside .fc-actions, and mobile-hub.js's
    holdTargetFrom() matches .fc-actions. Without the bail covering
    [data-follow-open]/.fc-follow, a deliberate press opens the reaction palette and
    the capture-phase swallower eats the release click — the modal never opens. Same
    defect as §14, one control later.

    Resolving by rect keeps it honest about WHICH copy it is pressing: a desktop and
    a mobile copy both exist in the DOM and CSS shows one per breakpoint.
    """
    return p.ev("""(() => {
      for (const b of document.querySelectorAll('[data-follow-open]')) {
        const r = b.getBoundingClientRect();
        if (r.width > 0 && r.height > 0) {
          return { topic: b.getAttribute('data-topic-id'),
                   x: Math.round(r.left + r.width / 2),
                   y: Math.round(r.top + r.height / 2),
                   inMobileBar: !!b.closest('.lg-card-actions'),
                   // Whether the HOLD was swallowed is now read from the modal, not
                   // from this pill: variant A's trigger opens a dialog, it does not
                   // itself carry the bit. A hold that reaches the click opens the
                   // modal; a swallowed one leaves it shut and pops the palette.
                   expanded: b.getAttribute('aria-expanded') };
        }
      }
      return null;
    })()""")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--hub", default=DEFAULT_HUB)
    ap.add_argument("--label", default="")
    a = ap.parse_args()

    log(f"\n=== follow-longpress-gate {a.label} ===")
    log(f"    hub={a.hub}  slow={PRESS_SLOW_MS}ms  fast={PRESS_FAST_MS}ms\n")

    # ── Environment first, and every one of these is exit 2, never red ──────────
    try:
        urllib.request.urlopen(CDP + "/json/version", timeout=5).read()
    except Exception as e:
        cannot_run(f"no CDP engine on {CDP} ({e}). Is chrome-dev running?")
    try:
        open(COOKIES).read()
    except Exception:
        cannot_run(f"{COOKIES} missing — cannot authenticate as a member")
    try:
        urllib.request.urlopen(a.hub, timeout=20).read(64)
    except Exception as e:
        cannot_run(f"harness hub {a.hub} unreachable ({e})")

    tid, p = new_page(a.hub)
    _open["page"], _open["tid"] = p, tid
    try:
        setup(p, a.hub)
        if not goto(p, a.hub):
            cannot_run("page never reached lg-follow-authed on first load — "
                       "hydration did not complete, so nothing was exercised")

        b = pick_follow_trigger(p)
        if not b:
            log("  FAIL  no visible [data-follow-open] at 390px"); return 1
        topic = b["topic"]
        log(f"  topic under test: {topic}  at ({b['x']},{b['y']})")
        check("pressing the MOBILE copy (inside .lg-card-actions), not the desktop one",
              b["inMobileBar"], True)
        check("mobile-hub.js actually loaded (overlay stack present)",
              p.ev("!!window.__loothMobileHub"), True)

        # ⚠️ ORDER MATTERS, AND GETTING IT WRONG ONCE ALREADY COST ME A FALSE PASS.
        # The store must be cleared BEFORE the page loads. Clearing it after means the
        # control has already hydrated to ON, and then "it reads Following" passes
        # without the press having done anything. Every phase re-establishes its
        # precondition and ASSERTS it.
        db_clear(topic)
        must_goto(p, a.hub, "precondition reload")
        check("precondition: store really is empty", db_notify(topic), False)
        check("precondition: the pill reads Follow, not Following",
              pill_on(p, topic), False)

        # ── 1. THE GATE'S WHOLE POINT, on variant A's control ─────────────────────
        # A slow, deliberate press must REACH ITS CLICK and open the modal. .fc-follow
        # renders inside .lg-card-actions, inside .fc-actions, and holdTargetFrom()
        # matches .fc-actions — so without the bail covering [data-follow-open], the
        # hold opens the reaction palette and the capture-phase swallower eats the
        # release click. That is Ian's §14 defect on the control that REPLACED the one
        # it was fixed on, and this phase is the only thing that can see it: a
        # synthetic click cannot cross 380ms (§14.3).
        log(f"\n  [1] SLOW press ({PRESS_SLOW_MS}ms) on the Follow pill — must OPEN the modal")
        b = must_trigger(p, "slow press")
        p.press(b["x"], b["y"], PRESS_SLOW_MS)
        time.sleep(1.0)
        check("the settings modal OPENED", modal_open(p), True)
        check("reaction palette did NOT open over the follow control",
              p.ev("!!document.querySelector('.fcr-palette:not([hidden])')"), False)

        # ── 2. THE SWITCH INSIDE IT WRITES ───────────────────────────────────────
        # The modal is appended to <body>, outside every holdTargetFrom() selector, so
        # a hold here SHOULD be immune. Asserted rather than assumed — "should be
        # outside the selector" is exactly the reasoning that was wrong in §14.
        log(f"\n  [2] SLOW press ({PRESS_SLOW_MS}ms) on the modal's 🔔 switch")
        sw = modal_switch(p, "notify")
        if not sw:
            cannot_run("modal opened but carries no [data-follow=notify] switch")
        p.press(sw["x"], sw["y"], PRESS_SLOW_MS)
        time.sleep(1.5)
        check("row IS in forums.topic_follow (the write actually happened)",
              db_notify(topic), True)
        check("the switch reads ON", sw_pressed(p, "notify"), "true")

        # ── 3. IT SURVIVES A RELOAD, AND THE CARD SHOWS IT ───────────────────────
        # "Stay on" was the literal complaint. Under variant A the member reads state
        # off the PILL without opening anything (§15.2), so that is what is asserted.
        log("\n  [3] reload — does it STAY on, and does the CARD show it?")
        must_goto(p, a.hub, "persistence reload")
        check("row still in store", db_notify(topic), True)
        check("the pill hydrated to Following (state visible WITHOUT opening)",
              pill_on(p, topic), True)

        # ── 4. IT TURNS OFF AGAIN (the toggle is not one-way) ────────────────────
        log(f"\n  [4] SLOW press again — turns OFF")
        b2 = must_trigger(p, "slow press OFF")
        p.press(b2["x"], b2["y"], PRESS_SLOW_MS)
        time.sleep(1.0)
        check("modal reopened", modal_open(p), True)
        sw2 = modal_switch(p, "notify")
        if not sw2:
            cannot_run("modal reopened without a notify switch")
        p.press(sw2["x"], sw2["y"], PRESS_SLOW_MS)
        time.sleep(1.5)
        check("row removed from store", db_notify(topic), False)

        # ── 5. A FAST FLICK still works (we must not have broken the quick tap) ──
        log(f"\n  [5] FAST tap ({PRESS_FAST_MS}ms) — the short gesture must still work")
        db_clear(topic)
        must_goto(p, a.hub, "fast-tap reload")
        b3 = must_trigger(p, "fast tap")
        p.press(b3["x"], b3["y"], PRESS_FAST_MS)
        time.sleep(1.0)
        check("fast tap opened the modal", modal_open(p), True)
        sw3 = modal_switch(p, "notify")
        if sw3:
            p.press(sw3["x"], sw3["y"], PRESS_FAST_MS)
            time.sleep(1.5)
            check("fast tap wrote the row", db_notify(topic), True)
        db_clear(topic)

        # ── 6. THE ENVELOPE TOO — a different store, the same swallowed gesture ──
        # ✉ writes the bbPress subscription in MySQL, not forums.topic_follow, so the
        # DB probe cannot see it. Surviving a reload IS the server round-trip
        # assertion. Both bits ride the same [data-follow] delegate, so a regression
        # that eats one eats both — this keeps the gate honest about that.
        log(f"\n  [6] SLOW press ({PRESS_SLOW_MS}ms) on the modal's ✉ ENVELOPE")
        must_goto(p, a.hub, "envelope reload")
        b4 = must_trigger(p, "envelope")
        p.press(b4["x"], b4["y"], PRESS_FAST_MS)
        time.sleep(1.0)
        em = modal_switch(p, "email")
        if not em:
            check("the modal carries a [data-follow=email] switch", bool(em), True)
        else:
            start = sw_pressed(p, "email")
            want = "false" if start == "true" else "true"
            p.press(em["x"], em["y"], PRESS_SLOW_MS)
            time.sleep(1.5)
            check(f"envelope flipped {start} -> {want}", sw_pressed(p, "email"), want)
            must_goto(p, a.hub, "envelope persistence reload")
            b5 = must_trigger(p, "envelope re-open")
            p.press(b5["x"], b5["y"], PRESS_FAST_MS)
            time.sleep(1.0)
            check("envelope state SURVIVED reload (server round-trip)",
                  sw_pressed(p, "email"), want)
            # Put it back the way we found it — the gate leaves no state behind.
            em2 = modal_switch(p, "email")
            if em2:
                p.press(em2["x"], em2["y"], PRESS_FAST_MS)
                time.sleep(1.5)
    finally:
        p.close(); close_page(tid)

    log(f"\n  {passes} passed, {failures} failed\n")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
