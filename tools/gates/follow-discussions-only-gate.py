#!/usr/bin/env python3
"""
follow-discussions-only-gate — FOLLOW MUST NOT EXIST ON NON-DISCUSSION POST TYPES.

WHY THIS GATE EXISTS, and why the existing follow gates could not see the defect.

Ian, 2026-07-30, having clicked the shipped build: "We are getting the follow on all
post types. It should be discusions only. On cpts we should get a save button. We dont'
have robust comments on the cpts."

That last sentence is the design rule, not a preference. FOLLOW MEANS "tell me about new
replies". A post type with no real reply stream cannot honour that, so a follow control
there is a promise the product cannot keep.

THE LESSON THIS FILE ENCODES — and it is the third time in one day that a green gate
missed something Ian saw immediately:

  follow-visible-gate asserts what should be PRESENT (the pill paints, the modal opens,
  Save survives on content cards) and it even proved the Save negative RED-FIRST. All of
  it passed while follow was rendering on articles and events, because NOTHING ASSERTED
  WHAT SHOULD BE ABSENT. A gate that only checks presence is structurally blind to a
  control appearing where it has no meaning.

So every assertion here is an ABSENCE assertion, and absence is asserted on the surfaces
that OPEN FROM a card — not just the card row. The row was already correct when Ian
reported this; the defect lives one click deeper:

  forums.js  lg-dmodal header      builds lg-dmodal__notify / lg-dmodal__email
  hub-polish.js  #looth-rep-sheet  builds lrs-notify / lrs-email

Both are UNCONDITIONAL and both PREDATE variant A (§2.3 era), which is why the
consolidation work neither introduced this nor could see it.

⚠️ IT IS WORSE THAN COSMETIC. dmodal's open(card) resolves its topic id from
card.getAttribute('data-topic-id'), which a CONTENT card does not carry, so the toggles
render stamped with an empty id and toggle() bails at its own guard. They are VISIBLE AND
DEAD. The sheet is worse still: its chrome persists across opens and only RETARGETS when
there is a topic id, so an article can display the PREVIOUS discussion's follow state.

Run:   python3 tools/gates/follow-discussions-only-gate.py [--url http://127.0.0.1:8896]
Needs: chrome-dev on 127.0.0.1:9222, a proxy/harness serving the build under test,
       /tmp/tf-gate/cookies.txt.

Exit:  0 green, 1 RED (real findings), 2 CANNOT RUN (no verdict) — run-all.sh convention.
"""
import argparse, json, subprocess, sys, time, urllib.request

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
    log("  (exit 2: no verdict. This is NOT a pass and NOT a finding.)")
    sys.exit(NO_VERDICT)

try:
    import websocket
except ImportError:
    cannot_run("python3 websocket-client is not installed")


class Page:
    def __init__(self, ws_url):
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


def set_cookies(p, base):
    host = base.split("//", 1)[-1].split("/")[0].split(":")[0]
    try: raw = open(COOKIES).read()
    except Exception as e: cannot_run(f"{COOKIES} missing ({e})")
    cks = [{"name": k, "value": v, "domain": host, "path": "/"}
           for k, v in (l.split("=", 1) for l in raw.splitlines() if "=" in l.strip())]
    if not cks: cannot_run(f"{COOKIES} held no name=value pairs")
    p.send("Network.enable"); p.send("Network.setCookies", {"cookies": cks})


def emulate(p, w, h, mobile):
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": w, "height": h, "deviceScaleFactor": 3 if mobile else 1, "mobile": mobile})
    p.send("Emulation.setTouchEmulationEnabled",
           {"enabled": mobile, "maxTouchPoints": 5 if mobile else 1})
    p.send("Emulation.setUserAgentOverride", {"userAgent":
        ("Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 "
         "(KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1") if mobile else
        ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
         "Chrome/151.0.0.0 Safari/537.36")})


def goto(p, url, tries=3):
    for attempt in range(tries):
        p.send("Page.navigate", {"url": url})
        for _ in range(240):
            time.sleep(0.25)
            try:
                if p.ev("document.readyState") == "complete": break
            except Exception: pass
        for _ in range(240):
            try:
                if p.ev("document.body.classList.contains('lg-follow-authed')"): return True
            except Exception: pass
            time.sleep(0.25)
        if attempt + 1 < tries:
            log(f"  (hydration slow — reloading, attempt {attempt + 2}/{tries})")
    return False


# Every VISIBLE follow affordance anywhere in the document, with enough context to name
# the offending surface. Visibility is what matters: a hidden node in persistent modal
# chrome is not what Ian saw, and failing on it would be noise.
VISIBLE_FOLLOW = r"""
(() => {
  const out = [];
  document.querySelectorAll('[data-follow], [data-follow-open], .fc-follow').forEach(b => {
    const cs = getComputedStyle(b), r = b.getBoundingClientRect();
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) <= 0.01) return;
    if (!(r.width > 0 && r.height > 0)) return;
    // Which surface is it in? That is what a reader of the failure needs.
    let where = 'page';
    if (b.closest('#lg-follow-modal')) where = 'follow-settings-modal';
    else if (b.closest('#lg-dmodal')) where = 'lg-dmodal (desktop reader)';
    else if (b.closest('#looth-rep-sheet')) where = 'looth-rep-sheet (mobile)';
    else if (b.closest('.feed-card')) where = 'feed-card row';
    out.push({ where, cls: b.className, topic: b.getAttribute('data-topic-id'),
               w: Math.round(r.width), h: Math.round(r.height) });
  });
  return out;
})()
"""

def visible_follow(p):
    return p.ev(VISIBLE_FOLLOW) or []


def content_cards(p):
    """Content cards = feed cards with NO follow trigger of their own.

    Classed by STRUCTURE, deliberately: it is the same condition hub-polish.js keys its
    Save guard on, and it cannot be changed by whatever regression we are hunting.
    """
    return p.ev("""[...document.querySelectorAll('.feed-card')]
      .filter(c => !c.querySelector('[data-follow-open]') && !c.querySelector('[data-follow]'))
      .map((c,i) => { c.setAttribute('data-gate-idx', 'c'+i);
        const pt = c.querySelector('[data-post-type]');
        return { idx: 'c'+i, pt: pt ? pt.getAttribute('data-post-type') : '?' }; })""")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default="http://127.0.0.1:8896")
    ap.add_argument("--max-cards", type=int, default=3)
    # Real CPT single pages. Defaulted to ones that exist on dev2 today; override
    # when the fixtures rot. Empty list = skip phase C rather than fake it.
    ap.add_argument("--cpt", action="append",
                    default=["/post-type-videos/council-of-elders-june-2026/"])
    a = ap.parse_args()
    base = a.url.rstrip("/")

    try: urllib.request.urlopen(CDP + "/json/version", timeout=5).read()
    except Exception as e: cannot_run(f"chrome-dev not reachable: {e}")
    try: urllib.request.urlopen(base + "/hub/", timeout=15).read(200)
    except Exception as e: cannot_run(f"{base} not reachable: {e}")

    tid, p = new_page()
    _open["tid"], _open["page"] = tid, p
    try:
        p.send("Page.enable"); p.send("Runtime.enable")
        set_cookies(p, base)

        for label, w, h, mob in (("desktop 1280", 1280, 900, False),
                                 ("mobile 390", 390, 844, True)):
            emulate(p, w, h, mob)
            if not goto(p, base + "/hub/"):
                cannot_run(f"hydration never completed for the mixed feed at {label}")

            cards = content_cards(p)
            if not cards:
                cannot_run(f"no content cards in the {label} feed — nothing to assert absence on")
            log(f"\n  [{label}] {len(cards)} content cards: "
                f"{', '.join(c['pt'] for c in cards[:6])}")

            # ── A. the ROW itself must carry Save and no follow ──────────────
            row_follow = [f for f in visible_follow(p) if f["where"] == "feed-card row"
                          and p.ev(f"!!document.querySelector('[data-gate-idx]')")]
            # Count follow affordances that sit inside a CONTENT card specifically.
            in_content = p.ev("""[...document.querySelectorAll('.feed-card[data-gate-idx]')]
              .flatMap(c => [...c.querySelectorAll('[data-follow], [data-follow-open], .fc-follow')])
              .filter(b => { const cs=getComputedStyle(b), r=b.getBoundingClientRect();
                return cs.display!=='none' && r.width>0 && r.height>0; }).length""")
            check(f"{label}: NO follow affordance in any content-card row", in_content, 0)

            saves = p.ev("""[...document.querySelectorAll('.feed-card[data-gate-idx]')]
              .filter(c => [...c.querySelectorAll('.fc-save, .lg-act-save')].some(b => {
                const cs=getComputedStyle(b), r=b.getBoundingClientRect();
                return cs.display!=='none' && r.width>0 && r.height>0; })).length""")
            log(f"      {saves}/{len(cards)} content cards show a visible Save")

            # ── B. THE SURFACE THAT OPENS FROM THE CARD — the actual defect ──
            # This is the part follow-visible-gate never looked at.
            for c in cards[:a.max_cards]:
                if not goto(p, base + "/hub/"):
                    cannot_run(f"hydration never completed re-opening the feed at {label}")
                content_cards(p)     # re-stamp indices after the reload
                opened = p.ev(f"""(() => {{
                  const c = document.querySelector('.feed-card[data-gate-idx="{c['idx']}"]');
                  if (!c) return 'no-card';
                  const t = c.querySelector('.fc-title a, .feed-card__title a, .fc-title, .feed-card__title');
                  if (!t) return 'no-title';
                  t.click(); return 'clicked';
                }})()""")
                time.sleep(1.4)
                found = visible_follow(p)
                offend = [f for f in found if f["where"] != "feed-card row"]
                ok = check(f"{label}: opening a {c['pt']} card exposes NO follow control",
                           len(offend), 0)
                if not ok:
                    for f in offend[:4]:
                        log(f"        -> {f['where']}: .{f['cls']} "
                            f"topic-id={f['topic']!r} {f['w']}x{f['h']}")
                check(f"{label}: the follow SETTINGS MODAL is unreachable from a {c['pt']} card",
                      p.ev("!!document.querySelector('#lg-follow-modal:not([hidden])')"), False)
        # ── C. CPT SINGLE PAGES — follow ABSENT, but Save must be PRESENT ────
        # Ian: "On cpts we should get a save button." Measured 2026-07-30 as a
        # logged-in member in a real engine: a post-type-videos single page shows
        # ZERO follow (correct) and ZERO SAVE (a defect) at both widths, while the
        # hub in the same session shows 6 follow / 12 save — so the session is
        # authenticated and the absence is real, not the logged-out mask.
        #
        # The react dock DOES render on these pages, so they are not overlay-less;
        # Save specifically is missing. This phase is expected to FAIL until that is
        # fixed — it reproduces the defect rather than describing it.
        for label, w, h, mob in (("desktop 1280", 1280, 900, False),
                                 ("mobile 390", 390, 844, True)):
            emulate(p, w, h, mob)
            for path in (a.cpt or []):
                p.send("Page.navigate", {"url": base + path})
                for _ in range(240):
                    time.sleep(0.25)
                    try:
                        if p.ev("document.readyState") == "complete": break
                    except Exception: pass
                time.sleep(5)          # overlays inject after paint
                vis = ("(sel)=>[...document.querySelectorAll(sel)].filter(b=>{"
                       "const cs=getComputedStyle(b),r=b.getBoundingClientRect();"
                       "return cs.display!=='none'&&cs.visibility!=='hidden'&&r.width>0&&r.height>0;}).length")
                p.ev("window.__vis=" + vis)
                # Sanity: the page must have loaded its overlay layer at all, or an
                # absence here means nothing. The react dock is the marker.
                if not p.ev("!!document.querySelector('.fcr, .lg-react-bar, [data-pt]')"):
                    cannot_run(f"{path} loaded no overlay layer — absence proves nothing")
                nf = p.ev("window.__vis('[data-follow], [data-follow-open], .fc-follow')")
                ns = p.ev("window.__vis('.fc-save, .lg-act-save, [data-save]')")
                log(f"\n  [{label}] CPT single {path}")
                check(f"{label}: CPT single page shows NO follow control", nf, 0)
                check(f"{label}: CPT single page SHOWS a Save control", ns > 0, True)
    finally:
        try: p.close()
        except Exception: pass
        close_page(tid)

    log("")
    log(f"follow-discussions-only-gate: {passes} pass / {failures} fail")
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
