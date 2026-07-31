#!/usr/bin/env python3
"""
card-v3-gate — Ian's approved card changes, asserted as ABSENCES as well as presences.

Ian, 2026-07-30, on the threadfollow-card-v3 mock: "love those changes."
Earlier, on the modal rework: "It's fixed." (approving the recommended variant A).

WHAT THIS GATE IS FOR. Every gate this lane wrote before today asserted what should be
PRESENT. Three defects reached Ian through green suites because nothing asserted what
should be ABSENT — a control rendering where it has no meaning is invisible to a
presence check. So the assertions here are mostly negative, and each one is required to
FAIL against the pre-change build before it passes against this one.

  DESKTOP  follow is in the card's TOP-RIGHT corner  ...and NOT in the action row
  MOBILE   replies appears EXACTLY ONCE              ...and is NOT a link
                                                     ...and is NOT top or bottom
  MODAL    names the discussion prominently          (title larger than the eyebrow)

Run:   python3 tools/gates/card-v3-gate.py --url http://127.0.0.1:PORT
Needs: chrome-dev on 9222, a harness serving the build under test with the follow flag
       ON (LG_BB_MIRROR_FOLLOW=1), /tmp/tf-gate/cookies.txt.
Exit:  0 green, 1 RED, 2 CANNOT RUN — run-all.sh's convention.
"""
import argparse, json, sys, time, urllib.request

CDP = "http://127.0.0.1:9222"
COOKIES = "/tmp/tf-gate/cookies.txt"
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
        self.ws = websocket.create_connection(ws, timeout=30, suppress_origin=True); self.n = 0
    def send(self, m, p=None):
        self.n += 1; i = self.n
        self.ws.send(json.dumps({"id": i, "method": m, "params": p or {}}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("id") == i:
                if "error" in r: raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})
    def ev(self, e):
        r = self.send("Runtime.evaluate", {"expression": e, "returnByValue": True, "awaitPromise": True})
        if r.get("exceptionDetails"): raise RuntimeError("JS: " + str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")
    def close(self):
        try: self.ws.close()
        except Exception: pass

def new_page():
    t = json.load(urllib.request.urlopen(urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")))
    return t["id"], Page(t["webSocketDebuggerUrl"])
def close_page(tid):
    try: urllib.request.urlopen(CDP + f"/json/close/{tid}").read()
    except Exception: pass

def set_cookies(p, base):
    host = base.split("//", 1)[-1].split("/")[0].split(":")[0]
    try: raw = open(COOKIES).read()
    except Exception as e: cannot_run(f"{COOKIES} missing ({e})")
    cks = [{"name": k, "value": v, "domain": host, "path": "/"}
           for k, v in (l.split("=", 1) for l in raw.splitlines() if "=" in l.strip())]
    p.send("Network.enable"); p.send("Network.setCookies", {"cookies": cks})

def emulate(p, w, h, mob):
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": w, "height": h, "deviceScaleFactor": 3 if mob else 1, "mobile": mob})
    p.send("Emulation.setTouchEmulationEnabled", {"enabled": mob, "maxTouchPoints": 5 if mob else 1})

def goto(p, url, tries=3):
    for a in range(tries):
        p.send("Page.navigate", {"url": url})
        for _ in range(240):
            time.sleep(0.25)
            try:
                if p.ev("document.readyState") == "complete": break
            except Exception: pass
        for _ in range(240):
            try:
                if p.ev("document.body.classList.contains('lg-follow-authed')"): 
                    time.sleep(3)   # let the overlays finish injecting
                    return True
            except Exception: pass
            time.sleep(0.25)
        if a + 1 < tries: log(f"  (hydration slow — retry {a+2}/{tries})")
    return False

# The card under test: a DISCUSSION card that actually has replies, or the assertions
# about a reply count are vacuous.
PICK = """(() => {
  const cards = [...document.querySelectorAll('.feed-card')]
    .filter(c => c.querySelector('[data-follow-open]'));
  const c = cards.find(x => /[0-9]+\\s*repl/i.test(x.textContent)) || cards[0];
  if (!c) return null;
  c.setAttribute('data-gate-card', '1');
  return { replies: parseInt(c.getAttribute('data-reply-count') || '0', 10) };
})()"""

VIS = """(sel) => [...document.querySelectorAll(sel)].filter(b => {
  const cs = getComputedStyle(b), r = b.getBoundingClientRect();
  return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0;
})"""

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default="http://127.0.0.1:8791")
    a = ap.parse_args()
    base = a.url.rstrip("/")
    try: urllib.request.urlopen(CDP + "/json/version", timeout=5).read()
    except Exception as e: cannot_run(f"no CDP engine: {e}")

    tid, p = new_page()
    _open["tid"], _open["page"] = tid, p
    try:
        p.send("Page.enable"); p.send("Runtime.enable")
        set_cookies(p, base)
        p.ev("window.__vis=" + VIS) if False else None

        # ── DESKTOP ────────────────────────────────────────────────────────────
        emulate(p, 1280, 900, False)
        if not goto(p, base + "/hub/?type=discussions"):
            cannot_run("hydration never completed at desktop 1280")
        p.ev("window.__vis=" + VIS)
        if not p.ev(PICK): cannot_run("no discussion card on the desktop feed")

        check("desktop: follow control is in the card's TOP-RIGHT corner",
              p.ev("""(() => { const c = document.querySelector('[data-gate-card]');
                const f = c && c.querySelector('.fc-follow-corner .fc-follow');
                if (!f) return 'absent';
                const cr = c.getBoundingClientRect(), fr = f.getBoundingClientRect();
                const nearTop = (fr.top - cr.top) < 70, nearRight = (cr.right - fr.right) < 70;
                return (nearTop && nearRight) ? 'corner' : 'elsewhere'; })()"""), "corner")
        # THE ABSENCE: it must have LEFT the action row — asserted on what is VISIBLE,
        # not on DOM presence. The MOBILE copy legitimately lives inside .fc-actions
        # (nested in .lg-card-actions) and is display:none at this width by design; a
        # presence check flags it and would push someone into "fixing" correct markup.
        check("desktop: follow is NO LONGER visible in the action row",
              p.ev("""(() => { const c = document.querySelector('[data-gate-card]');
                if (!c) return -1;
                return window.__vis('.fc-actions .fc-follow').filter(e => c.contains(e)).length; })()"""), 0)

        # ── MOBILE ─────────────────────────────────────────────────────────────
        emulate(p, 390, 844, True)
        if not goto(p, base + "/hub/?type=discussions"):
            cannot_run("hydration never completed at mobile 390")
        p.ev("window.__vis=" + VIS)
        picked = p.ev(PICK)
        if not picked: cannot_run("no discussion card on the mobile feed")
        if not picked.get("replies"):
            cannot_run("the chosen discussion card has 0 replies — the count assertions "
                       "would pass vacuously")

        # ⚠️ COUNT THE COUNT-AFFORDANCES, NOT ANYTHING THAT SAYS "REPLIES".
        # This assertion has now been wrong twice, in opposite directions, and both
        # versions still produced the "right" verdict — which is exactly why it needed
        # fixing rather than accepting:
        #   v1 skipped any element with a child, and BOTH pre-change carriers wrap an
        #      <svg>, so it counted ZERO on a card that visibly showed the count twice.
        #   v2 counted innermost text matches, which swept in .fc-replies — the reply
        #      PREVIEW block (real reply content, with its own view-all control). That
        #      is card content, not a count affordance, and it is present on BOTH
        #      builds; counting it made the assertion fail on the fixed build too.
        # Scoped now to the three elements that have ever CARRIED the count:
        #   .fc-replycount   the old top stat      (must be gone)
        #   .lg-act-replies  the old bottom button (must be gone on topic cards)
        #   .fc-replies-stat the new inline stat   (must be the only one left)
        check("mobile: exactly ONE replies-count affordance on the card",
              p.ev("""(() => { const c = document.querySelector('[data-gate-card]');
                return window.__vis('.fc-replycount, .lg-act-replies, .fc-replies-stat')
                  .filter(e => c.contains(e)).length; })()"""), 1)
        check("mobile: the replies count is NOT interactive (no button/link/handler)",
              p.ev("""(() => { const c = document.querySelector('[data-gate-card]');
                const s = c.querySelector('.fc-replies-stat');
                if (!s) return 'missing';
                if (s.tagName === 'A' || s.tagName === 'BUTTON') return 'is-'+s.tagName;
                if (s.getAttribute('role') === 'button') return 'role-button';
                if (s.hasAttribute('tabindex')) return 'focusable';
                return 'inert'; })()"""), "inert")
        check("mobile: NO replies button at the bottom of the card",
              p.ev("""(() => { const c = document.querySelector('[data-gate-card]');
                return window.__vis('.lg-act-replies').filter(e => c.contains(e)).length; })()"""), 0)
        check("mobile: NO replies stat at the top of the card",
              p.ev("""(() => { const c = document.querySelector('[data-gate-card]');
                return window.__vis('.fc-replycount').filter(e => c.contains(e)).length; })()"""), 0)
        check("mobile: the stat sits WITH the reactions, not in the action row",
              p.ev("""(() => { const c = document.querySelector('[data-gate-card]');
                const s = c.querySelector('.fc-replies-stat');
                return !!s && !s.closest('.lg-card-actions'); })()"""), True)
        # Content cards must KEEP their comments affordance — feed_action_bar() is SHARED,
        # and dropping .lg-act-replies unconditionally would strand every article and
        # event with no way into its comments. Asserted on the MIXED feed, because
        # ?type=discussions contains no content cards at all and the check would report
        # "no-content-cards" — a vacuous result dressed as a failure.
        if not goto(p, base + "/hub/"):
            cannot_run("hydration never completed for the mixed feed at mobile 390")
        p.ev("window.__vis=" + VIS)
        n_content = p.ev("""[...document.querySelectorAll('.feed-card')]
            .filter(c => !c.querySelector('[data-follow-open]')).length""")
        if not n_content:
            cannot_run("no content cards in the mixed feed — cannot test the shared-helper scoping")
        check("mobile: CONTENT cards keep their replies/comments control",
              p.ev("""(() => { const cc = [...document.querySelectorAll('.feed-card')]
                  .filter(c => !c.querySelector('[data-follow-open]'));
                return cc.some(c => window.__vis('.lg-act-replies').some(e => c.contains(e))); })()"""), True)

        # ── BEHAVIOUR: the control must be CLICKABLE, not merely present ───────
        # Ian, 2026-07-31: "Still no modal from follow button just opens the modal on
        # dt." The card-v3 gate already asserted the pill was IN THE CORNER and passed
        # while it was unclickable — being in the corner and being clickable are
        # different claims, and the gap between them is what reached him. This asserts
        # the OUTCOME of a real click: the follow modal opens, the READER does not, and
        # the URL does not become a ?topic= deep link.
        for lbl, w, h, mob in (("desktop 1280", 1280, 900, False),
                               ("mobile 390", 390, 844, True)):
            emulate(p, w, h, mob)
            if not goto(p, base + "/hub/?type=discussions"):
                cannot_run(f"hydration never completed for the click test at {lbl}")
            before = p.ev("location.href")
            # ⚠️ SCROLL IT INTO A SAFE BAND AND VERIFY THE HIT, don't just take a rect.
            # First version picked any pill with 0 < top < 800 and clicked its centre.
            # At 390x844 that chose one at y=774, under the sticky bottom tab bar, so
            # the click landed on the tabbar and the gate reported "the follow modal did
            # not open" — a FALSE FAILURE against a build where the control works (the
            # same click passes when aimed properly). A gate that clicks the wrong pixel
            # invents defects, which is how the last three hours were spent.
            box = p.ev("""(() => {
              const b=[...document.querySelectorAll('[data-follow-open]')]
                .find(e => { const r=e.getBoundingClientRect(); return r.width>0 && r.height>0; });
              if (!b) return null;
              b.scrollIntoView({block:'center'});
              const r=b.getBoundingClientRect();
              const x=Math.round(r.left+r.width/2), y=Math.round(r.top+r.height/2);
              // elementFromPoint is the ground truth for "what will receive this click".
              const hit=document.elementFromPoint(x,y);
              const onTarget=!!(hit && (hit===b || b.contains(hit) || hit.closest('[data-follow-open]')===b));
              return {x, y, onTarget, hit:(hit&&hit.className||'').toString().slice(0,40)}; })()""")
            if not box:
                cannot_run(f"no follow control in the DOM at {lbl}")
            if not box.get("onTarget"):
                cannot_run(f"at {lbl} the follow control is covered at its own centre by "
                           f"{box.get('hit')!r} — cannot click it, so the behaviour is untestable "
                           f"(this is a finding about reachability, report it rather than pass)")
            # A REAL mouse event at the pixel, not el.click() — el.click() bypasses the
            # capture-phase card handler that caused this defect, so it would have
            # passed against the broken build.
            p.send("Input.dispatchMouseEvent", {"type":"mousePressed","x":box["x"],"y":box["y"],
                                                "button":"left","clickCount":1})
            p.send("Input.dispatchMouseEvent", {"type":"mouseReleased","x":box["x"],"y":box["y"],
                                                "button":"left","clickCount":1})
            time.sleep(2)
            check(f"{lbl}: clicking follow opens the FOLLOW settings modal",
                  p.ev("!!document.querySelector('#lg-follow-modal:not([hidden])')"), True)
            check(f"{lbl}: clicking follow does NOT open the reader/thread",
                  p.ev("!!document.querySelector('#lg-dmodal:not([hidden])') "
                       "|| !!document.querySelector('#looth-rep-sheet.is-open')"), False)
            check(f"{lbl}: clicking follow does NOT navigate to the topic deep link",
                  p.ev("location.href") == before, True)
            p.ev("document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape'}))")
            time.sleep(0.4)

        # ── THE MODAL names the discussion ─────────────────────────────────────
        # The mixed feed is still loaded and carries discussion cards too, so a trigger
        # is present; assert that rather than assume it.
        if not p.ev("!!document.querySelector('[data-follow-open]')"):
            cannot_run("no follow trigger on the mixed feed to open the modal with")
        p.ev("document.querySelector('[data-follow-open]').click()")
        time.sleep(1.2)
        check("modal: it opened", p.ev("!!document.querySelector('#lg-follow-modal:not([hidden])')"), True)
        check("modal: the TITLE is the discussion, not boilerplate",
              p.ev("""(() => { const t = document.querySelector('.lg-fm__title');
                const e = document.querySelector('.lg-fm__eyebrow');
                if (!t || !e) return 'missing';
                const tx = (t.textContent||'').trim();
                if (!tx || /^follow this discussion$/i.test(tx)) return 'boilerplate';
                return parseFloat(getComputedStyle(t).fontSize) >
                       parseFloat(getComputedStyle(e).fontSize) ? 'prominent' : 'not-prominent'; })()"""),
              "prominent")
    finally:
        try: p.close()
        except Exception: pass
        close_page(tid)

    log("")
    log(f"card-v3-gate: {passes} pass / {failures} fail")
    sys.exit(1 if failures else 0)

if __name__ == "__main__":
    main()
