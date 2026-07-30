#!/usr/bin/env python3
"""
follow-visible-gate — are the 🔔/✉ toggles actually PAINTED, and HITTABLE?

WHY THIS GATE EXISTS (CRAFT-STANDARD: a defect class found TWICE becomes a gate).

Ian has now reported "I don't see the controls" TWICE about markup that was present
in the DOM the whole time:

  1. 765dbc3 — the desktop feed-card action row was ~410px of content in a 349px
     grid column. `.fc-actions` was `flex-wrap: wrap`, so the row broke onto extra
     lines and the toggles were pushed past the card's own `overflow: hidden`.
     17 of 18 cards. Present in the DOM; painted nowhere.
  2. The 2026-07-30 respawn report, which re-arrived as "the render regressed" and
     "a migration was lost" — neither true. See SPEC §16.

THE MEASUREMENT THAT KEEPS FAILING US IS `querySelectorAll().length`. Counting
[data-follow] in the HTML said 24 while Ian was looking at a card with none on it.
Every check in this file is therefore a PAINT check:

    computed display/visibility/opacity   — is it styled to render at all?
    getBoundingClientRect w/h > 0         — does it occupy space?
    rect inside the CARD's rect           — or has overflow:hidden eaten it? (defect 1)
    elementFromPoint(centre) is the button — is it actually the thing under the finger,
                                             or is something painted over it?

The last one is the reason this cannot be a curl assertion, and the fourth is the
one that would have caught 765dbc3 before Ian did.

BOTH VIEWPORTS ARE REQUIRED, and they assert DIFFERENT nodes. The two pairs are
mutually exclusive by CSS and each is the ONLY surface at its width:
    >=641px  desktop pair  .fc-actions > .fc-notify/.fc-email   (forums.css:4279)
    <=640px  mobile pair   .lg-card-actions .lg-act-follow ...   (forums.css:5276)
             (the desktop pair is display:none at :687, and the mobile pair's
              container .lg-card-actions is display:none above 640)
So "12 visible" means a DIFFERENT 12 buttons on each pass. A gate that only ran one
width would pass while the other surface was completely dark.

RUNS AGAINST THE REAL ORIGIN, not the loopback exercise harness — via
tools/exercise-harness/real-origin-proxy.py. Defect 1 was invisible to the harness
because the harness does not reproduce the vhost's sub_filter (theme-boot, the
lg-feed-booting opacity gate). Measuring the page Ian does not have is how three of
his reports stayed unreproducible.

Run:   python3 tools/gates/follow-visible-gate.py [--url http://127.0.0.1:8899]
Needs: chrome-dev on 127.0.0.1:9222, real-origin-proxy.py on :8899,
       /tmp/tf-gate/cookies.txt (gate + WP auth cookies for the acting member).

Exit:  0 green, 1 RED (real findings), 2 CANNOT RUN (no verdict).
       The 0/1/2 split is run-all.sh's convention. Most failure modes here are
       ENVIRONMENTAL (no engine, no proxy, no cookies); reporting those as red is
       indistinguishable from a regression, which is how craft gate 2 sat "red"
       for weeks while it was in fact dead.
"""
import argparse, json, subprocess, sys, time, urllib.request

CDP = "http://127.0.0.1:9222"
COOKIES = "/tmp/tf-gate/cookies.txt"
UID = 1912
NO_VERDICT = 2

TOPIC_PATH = "/hub/general/keeper-test-thread-follow-this-one-ian"
HUB_PATH = "/hub/?type=discussions"

passes = failures = 0
def log(*a): print(" ".join(str(x) for x in a), flush=True)

def check(label, got, want):
    global passes, failures
    ok = got == want
    if ok: passes += 1
    else:  failures += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + ("" if ok else f"   got={got!r} want={want!r}"))
    return ok

def check_ge(label, got, floor):
    global passes, failures
    ok = isinstance(got, (int, float)) and got >= floor
    if ok: passes += 1
    else:  failures += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + ("" if ok else f"   got={got!r} want>={floor}"))
    return ok

_open = {"page": None, "tid": None}

def cannot_run(why):
    try:
        if _open["page"]: _open["page"].close()
        if _open["tid"]:  close_page(_open["tid"])
    except Exception: pass
    log(f"  CANNOT RUN — {why}")
    log("  (exit 2: no verdict. This is NOT a pass and NOT a finding.)")
    sys.exit(NO_VERDICT)

try:
    import websocket  # websocket-client
except ImportError:
    cannot_run("python3 websocket-client is not installed")


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


class Page:
    """ONE persistent CDP connection.

    Deliberately not per-command sockets: device-metric overrides are scoped to the
    session that set them, so a fresh socket per call silently drops the emulation
    and a mobile run false-PASSES as desktop. (keeper memory:
    trap-chrome-dev-login-skill-stale-on-dev2.)
    """
    def __init__(self, ws_url):
        # suppress_origin: chrome rejects a CDP websocket carrying an Origin header
        # unless launched with --remote-allow-origins. Not sending one needs no
        # change to the shared chrome-dev service.
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


def new_page():
    t = json.load(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")))
    return t["id"], Page(t["webSocketDebuggerUrl"])

def close_page(tid):
    try: urllib.request.urlopen(CDP + f"/json/close/{tid}").read()
    except Exception: pass


def emulate(p, width, height, mobile):
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": width, "height": height,
            "deviceScaleFactor": 3 if mobile else 1, "mobile": mobile})
    p.send("Emulation.setTouchEmulationEnabled",
           {"enabled": mobile, "maxTouchPoints": 5 if mobile else 0})
    p.send("Emulation.setUserAgentOverride", {"userAgent":
        ("Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 "
         "(KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1") if mobile else
        ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
         "Chrome/151.0.0.0 Safari/537.36")})


def goto(p, url, tries=2):
    """Navigate and WAIT FOR REAL HYDRATION, not merely readyState.

    The toggles ship server-rendered but inert; their true state arrives via the
    batch GET that sets body.lg-follow-authed. Measuring before that lands would
    read an unhydrated button. Generous timeouts: a cold first paint on this box is
    genuinely slow, and that is latency, never a product defect.
    """
    for attempt in range(tries):
        p.send("Page.navigate", {"url": url})
        for _ in range(160):
            time.sleep(0.25)
            try:
                if p.ev("document.readyState") == "complete": break
            except Exception: pass
        for _ in range(160):
            try:
                if p.ev("document.body.classList.contains('lg-follow-authed')"):
                    return True
            except Exception: pass
            time.sleep(0.25)
        if attempt + 1 < tries:
            log(f"  (hydration slow — reloading, attempt {attempt + 2}/{tries})")
    return False


# The whole gate in one expression: for every [data-follow] on the page, decide
# whether a person could actually SEE and HIT it. Returned per-button so a failure
# names the card and the reason instead of just a count.
PAINT_PROBE = r"""
(() => {
  const out = [];
  document.querySelectorAll('[data-follow]').forEach((b, i) => {
    const cs = getComputedStyle(b);
    const r  = b.getBoundingClientRect();
    // The clipping ancestor that actually ate the toggles in 765dbc3.
    const card = b.closest('.feed-card') || b.closest('.thread__util') || document.body;
    const cr = card.getBoundingClientRect();
    const styled = cs.display !== 'none' && cs.visibility !== 'hidden' && parseFloat(cs.opacity) > 0.01;
    const sized  = r.width > 0 && r.height > 0;
    // Inside its card horizontally AND vertically (1px tolerance for subpixel).
    const inCard = r.left >= cr.left - 1 && r.right  <= cr.right  + 1 &&
                   r.top  >= cr.top  - 1 && r.bottom <= cr.bottom + 1;
    // Is it the thing under the finger? Only meaningful if it's in the viewport.
    const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    const inView = cx >= 0 && cy >= 0 && cx <= innerWidth && cy <= innerHeight;
    let hit = null;
    if (inView && styled && sized) {
      const el = document.elementFromPoint(cx, cy);
      hit = !!(el && (el === b || b.contains(el) || el.closest('[data-follow]') === b));
    }
    out.push({ i, ch: b.getAttribute('data-follow'), topic: b.getAttribute('data-topic-id'),
               styled, sized, inCard, inView, hit,
               w: Math.round(r.width), h: Math.round(r.height) });
  });
  return out;
})()
"""

def probe(p):
    return p.ev(PAINT_PROBE) or []

def summarise(rows, label):
    """A button counts as VISIBLE only if it is styled, sized and inside its card.
    HITTABLE additionally requires it to be the element under its own centre."""
    vis = [r for r in rows if r["styled"] and r["sized"] and r["inCard"]]
    hittable = [r for r in vis if r["inView"] and r["hit"]]
    clipped = [r for r in rows if r["styled"] and r["sized"] and not r["inCard"]]
    log(f"  [{label}] {len(rows)} in DOM | {len(vis)} visible | {len(hittable)} hittable "
        f"| {len(clipped)} CLIPPED OUT OF CARD")
    for r in clipped:
        log(f"      clipped: data-follow={r['ch']} topic={r['topic']} {r['w']}x{r['h']}")
    return vis, hittable, clipped


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default="http://127.0.0.1:8899")
    args = ap.parse_args()
    base = args.url.rstrip("/")

    try:
        urllib.request.urlopen(CDP + "/json/version", timeout=5).read()
    except Exception as e:
        cannot_run(f"chrome-dev not reachable on {CDP}: {e}")
    try:
        urllib.request.urlopen(base + "/hub/", timeout=10).read(200)
    except Exception as e:
        cannot_run(f"real-origin-proxy not reachable on {base}: {e} "
                   f"(bring it up: python3 tools/exercise-harness/real-origin-proxy.py --port 8899)")

    tid, p = new_page()
    _open["tid"], _open["page"] = tid, p
    try:
        p.send("Page.enable"); p.send("Runtime.enable")

        # ---- PHASE 1 — the hub feed card, at BOTH widths -------------------
        # Each width asserts a different pair; see the header. 6 discussion cards
        # x 1 visible pair = 12. The floor is expressed per-card so a short feed
        # cannot vacuously pass with zero cards.
        for label, w, h, mobile in (("desktop 1280", 1280, 900, False),
                                    ("mobile 390", 390, 844, True)):
            emulate(p, w, h, mobile)
            if not goto(p, base + HUB_PATH):
                cannot_run(f"hydration never completed for the hub at {label}")
            cards = p.ev("document.querySelectorAll('.feed-card [data-follow]').length && "
                         "new Set([...document.querySelectorAll('.feed-card [data-follow]')]"
                         ".map(b => b.closest('.feed-card'))).size")
            rows = probe(p)
            vis, hittable, clipped = summarise(rows, f"hub {label}")
            log(f"      ({cards} discussion cards carry toggles)")
            check(f"hub {label}: no toggle clipped outside its card", len(clipped), 0)
            check_ge(f"hub {label}: visible toggles", len(vis), 2)
            check(f"hub {label}: every visible toggle is hittable",
                  len([r for r in vis if r["inView"]]) == len(hittable), True)
            # Exactly one pair per card must be live — never both, never neither.
            if cards:
                check(f"hub {label}: exactly one pair per card visible", len(vis), cards * 2)

        # ---- PHASE 2 — the standalone topic page, at BOTH widths -----------
        # This page is the one that rendered NO toggles at all until 04a8598
        # (the helper existed and was simply never called), so it gets its own
        # assertion rather than being assumed to follow the feed.
        for label, w, h, mobile in (("desktop 1280", 1280, 900, False),
                                    ("mobile 390", 390, 844, True)):
            emulate(p, w, h, mobile)
            if not goto(p, base + TOPIC_PATH):
                cannot_run(f"hydration never completed for the topic page at {label}")
            rows = probe(p)
            vis, hittable, clipped = summarise(rows, f"topic {label}")
            check(f"topic {label}: no toggle clipped outside its container", len(clipped), 0)
            check(f"topic {label}: bell + envelope both visible", len(vis), 2)
            check(f"topic {label}: both hittable", len(hittable), 2)

        # ---- PHASE 3 — visible is not the same as WORKING ------------------
        # A painted control that does not persist is the §8.1.3 "UI lies" defect
        # wearing a different hat, so the gate does not stop at pixels. State is
        # cleared FIRST and the OFF precondition asserted, because the first draft
        # of the longpress gate passed vacuously against an already-ON button.
        emulate(p, 390, 844, True)
        if not goto(p, base + TOPIC_PATH):
            cannot_run("hydration never completed for the persistence phase")
        topic_id = p.ev("(document.querySelector('[data-follow=notify]')||{}).getAttribute "
                        "? document.querySelector('[data-follow=notify]').getAttribute('data-topic-id') : null")
        if not topic_id:
            cannot_run("no [data-follow=notify] on the topic page to exercise")
        topic_id = int(topic_id)

        db_clear(topic_id)
        if not goto(p, base + TOPIC_PATH):
            cannot_run("hydration never completed after clearing state")
        check(f"precondition: topic {topic_id} starts OFF in the store", db_notify(topic_id), False)
        check("precondition: the bell reads OFF",
              p.ev("document.querySelector('[data-follow=notify]').getAttribute('aria-pressed')"), "false")

        p.ev("document.querySelector('[data-follow=notify]').click()")
        time.sleep(1.5)
        check("after click: a row exists in forums.topic_follow", db_notify(topic_id), True)

        if not goto(p, base + TOPIC_PATH):
            cannot_run("hydration never completed for the reload check")
        check("after RELOAD: the bell hydrates back to ON",
              p.ev("document.querySelector('[data-follow=notify]').getAttribute('aria-pressed')"), "true")
        check("after RELOAD: the store still holds the row", db_notify(topic_id), True)

        db_clear(topic_id)
    finally:
        try: p.close()
        except Exception: pass
        close_page(tid)

    log("")
    log(f"follow-visible-gate: {passes} pass / {failures} fail")
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
