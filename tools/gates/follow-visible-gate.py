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

Run:   python3 tools/gates/follow-visible-gate.py [--url http://127.0.0.1:8896]
Needs: chrome-dev on 127.0.0.1:9222, real-origin-proxy.py on :8896,
       /tmp/tf-gate/cookies.txt (gate + WP auth cookies for the acting member).

       ⚠️ PORT: default is 8896, NOT the 8899 this lane used on 2026-07-30.
       8899 is the number every exercise-harness recipe in the repo reaches for
       first, and it was already held by another lane's endpoint-swap-proxy when
       this gate was written. Binding it would either fail or, worse, silently
       measure SOMEONE ELSE'S branch through their proxy and report it as ours.
       Check `ss -ltnp | grep 889` before picking a port on a shared box.

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


def set_cookies(p, base):
    """Carry the member's WP auth cookies into the page.

    ⚠️ NOT OPTIONAL, and easy to omit because it is invisible on the other harness.
    real-origin-proxy.py injects auth SERVER-SIDE, so a run against it authenticates
    with no cookie work at all — and the same gate pointed at the loopback exercise
    harness then resolves ANON, never gets body.lg-follow-authed, and dies at
    hydration as CANNOT RUN. That is a no-verdict caused entirely by the gate, which
    is worse than a red because it looks like the environment's fault.
    """
    host = base.split("//", 1)[-1].split("/")[0].split(":")[0]
    try:
        raw = open(COOKIES).read()
    except Exception as e:
        cannot_run(f"{COOKIES} missing — cannot authenticate as a member ({e})")
    cks = []
    for line in raw.splitlines():
        line = line.strip()
        if not line or "=" not in line: continue
        k, v = line.split("=", 1)
        cks.append({"name": k, "value": v, "domain": host, "path": "/"})
    if not cks:
        cannot_run(f"{COOKIES} held no name=value pairs")
    p.send("Network.enable")
    p.send("Network.setCookies", {"cookies": cks})


def emulate(p, width, height, mobile):
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": width, "height": height,
            "deviceScaleFactor": 3 if mobile else 1, "mobile": mobile})
    p.send("Emulation.setTouchEmulationEnabled",
           {"enabled": mobile, "maxTouchPoints": 5 if mobile else 1})
    p.send("Emulation.setUserAgentOverride", {"userAgent":
        ("Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 "
         "(KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1") if mobile else
        ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
         "Chrome/151.0.0.0 Safari/537.36")})


def goto(p, url, tries=3):
    """Navigate and WAIT FOR REAL HYDRATION, not merely readyState.

    The toggles ship server-rendered but inert; their true state arrives via the
    batch GET that sets body.lg-follow-authed. Measuring before that lands would
    read an unhydrated button. Generous timeouts: a cold first paint on this box is
    genuinely slow, and that is latency, never a product defect.
    """
    for attempt in range(tries):
        p.send("Page.navigate", {"url": url})
        for _ in range(240):
            time.sleep(0.25)
            try:
                if p.ev("document.readyState") == "complete": break
            except Exception: pass
        for _ in range(240):
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
  document.querySelectorAll(%SEL%).forEach((b, i) => {
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
    out.push({ i, ch: b.getAttribute('data-follow') || b.className,
               topic: b.getAttribute('data-topic-id') || b.getAttribute('data-item-id'),
               styled, sized, inCard, inView, hit,
               w: Math.round(r.width), h: Math.round(r.height) });
  });
  return out;
})()
"""

def probe(p, selector="[data-follow]"):
    """Paint-probe every node matching `selector`.

    The selector is injected as a JS string literal via json.dumps — these come from
    this file only, but building JS by concatenation is how a stray quote turns a
    gate into a silent syntax error that reports zero findings and passes.
    """
    try:
        return p.ev(PAINT_PROBE.replace("%SEL%", json.dumps(selector))) or []
    except RuntimeError as e:
        # :has() is the one selector here a stale engine might not support. Saying so
        # beats returning [] and letting "0 clipped" read as a pass.
        cannot_run(f"paint probe failed for selector {selector!r}: {e}")

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
    ap.add_argument("--url", default="http://127.0.0.1:8896")  # NOT 8899 — see header
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
                   f"(bring it up: python3 tools/exercise-harness/real-origin-proxy.py --port 8896)")

    tid, p = new_page()
    _open["tid"], _open["page"] = tid, p
    try:
        p.send("Page.enable"); p.send("Runtime.enable")
        set_cookies(p, base)

        # ---- PHASE 1 — the CONSOLIDATED control on the topic card ----------
        # §15 variant A (Ian 2026-07-30): the card row is React/replies/Share/Follow,
        # and the 🔔/✉ pair now lives INSIDE the modal. So on the feed card the thing
        # to find is ONE .fc-follow per card per width, not a pair. Both widths still
        # assert different nodes — feed_follow_control() emits a desktop copy into
        # .fc-actions and a mobile copy into .lg-card-actions, and the mobile-first
        # hide (forums.css:697) is the only thing stopping BOTH painting on a phone.
        # That hide has now been needed three times (.fc-share, the pair, .fc-follow),
        # which is exactly why "exactly one per card" is asserted and not just ">=1".
        for label, w, h, mobile in (("desktop 1280", 1280, 900, False),
                                    ("mobile 390", 390, 844, True)):
            emulate(p, w, h, mobile)
            if not goto(p, base + HUB_PATH):
                cannot_run(f"hydration never completed for the hub at {label}")
            cards = p.ev("new Set([...document.querySelectorAll('.feed-card [data-follow-open]')]"
                         ".map(b => b.closest('.feed-card'))).size")
            rows = probe(p, "[data-follow-open]")
            vis, hittable, clipped = summarise(rows, f"hub {label}")
            log(f"      ({cards} discussion cards carry the consolidated control)")
            check(f"hub {label}: no control clipped outside its card", len(clipped), 0)
            check_ge(f"hub {label}: visible follow controls", len(vis), 1)
            check(f"hub {label}: every visible control is hittable",
                  len([r for r in vis if r["inView"]]) == len(hittable), True)
            # EXACTLY one per card — two means the mobile-first hide regressed and the
            # phone is showing an unstyled desktop copy, which is the defect Ian
            # reported as "two empty black squares below the action row".
            if cards:
                check(f"hub {label}: exactly ONE control per card (not two)", len(vis), cards)

        # ---- PHASE 1b — THE NEGATIVE CASE: Save must SURVIVE elsewhere ------
        # Ian, 2026-07-30, verbatim: "btw, we need to keep the save button on all
        # other post types."
        #
        # Variant A moves Save behind a modal that is FOLLOW-shaped, and follow only
        # exists for post_type 'topic' (follow.php:176). So on an article or an event
        # there is no modal to move Save INTO — move it unconditionally and Save does
        # not relocate, it DISAPPEARS, with no surface left to reach it from. That
        # would ship looking like a consolidation win.
        #
        # The structural defence is that topic and content cards render from two
        # SEPARATE .fc-actions blocks (_feed.php:1640 vs :1505) and only the topic one
        # was touched; the non-topic Save at _feed.php:1508 is untouched. On mobile the
        # same split is held by hub-polish.js:509, which skips its .lg-act-save append
        # ONLY when the card carries [data-follow-open].
        #
        # But structure is an argument, and this is a gate. A card is "content" here
        # precisely because it has no [data-follow-open] — the same condition the
        # mobile guard keys on — so if Save were ever moved unconditionally, these
        # cards would lose it and this phase goes red. That is the negative the topic
        # assertions above structurally cannot see.
        for label, w, h, mobile in (("desktop 1280", 1280, 900, False),
                                    ("mobile 390", 390, 844, True)):
            emulate(p, w, h, mobile)
            if not goto(p, base + "/hub/"):     # ALL types, not ?type=discussions
                cannot_run(f"hydration never completed for the mixed feed at {label}")
            # ⚠️ COUNTED IN TWO STEPS ON PURPOSE, and the order is the whole point.
            #
            # The obvious version — "count content cards that HAVE a save control,
            # then assert they are painted" — PASSES VACUOUSLY against the exact
            # build this phase exists to catch: move Save unconditionally, every
            # content card loses it, the count is 0, zero cards are asserted, green.
            # (Or worse, an emptiness guard turns it into CANNOT RUN, which is not a
            # pass but is not the red it should be either.) This is the same vacuous
            # trap the longpress gate's first draft fell into, one gate later.
            #
            # So: cards WITHOUT a follow control are "content" by structure alone —
            # the same condition hub-polish.js:509 keys its mobile guard on, and a
            # condition no Save-side regression can change. THEN require that at
            # least one of them still carries a save control. Zero is a FINDING.
            n_cards = p.ev("[...document.querySelectorAll('.feed-card')]"
                           ".filter(c => !c.querySelector('[data-follow-open]')).length")
            if not n_cards:
                # Genuinely environmental: a discussions-only feed has nothing to say
                # about non-topic Save either way.
                cannot_run(f"no content cards at all in the {label} feed "
                           f"— the negative case has nothing to test")
            n_content = p.ev(
                "[...document.querySelectorAll('.feed-card')]"
                ".filter(c => !c.querySelector('[data-follow-open]') && "
                "             c.querySelector('.fc-save, .lg-act-save')).length")
            log(f"      ({n_cards} content cards, {n_content} carrying a save control)")
            # THE red-first assertion. Save moved unconditionally => 0 => FAIL.
            # NOT "all n_cards": on main, 5 of 12 content cards ship no save control
            # at all (post-type-videos / loothprint, same on this branch — verified
            # against the serving checkout, so it is pre-existing and not variant A's
            # doing). Asserting all would red-flag a condition this lane did not cause
            # and cannot fix. Flagged in the report instead.
            check_ge(f"content cards {label}: SOME content card still has inline Save",
                     n_content, 1)
            rows = probe(p, ".feed-card:not(:has([data-follow-open])) :is(.fc-save, .lg-act-save)")
            vis, hittable, clipped = summarise(rows, f"content-card SAVE {label}")
            check(f"content cards {label}: no Save clipped out of its card", len(clipped), 0)
            # ⚠️ COUNTED PER CARD, NOT PER NODE. A card can ship BOTH a .fc-save
            # (desktop) and a .lg-act-save (mobile) and CSS shows one per width, so
            # "visible nodes == cards" is simply false arithmetic — it failed 12 vs 13
            # on the first run against a build where every card was in fact fine.
            # What matters to a member is that each card has AT LEAST ONE reachable
            # Save, which is what this asks.
            n_with_visible = p.ev(
                "[...document.querySelectorAll('.feed-card')]"
                ".filter(c => !c.querySelector('[data-follow-open]'))"
                ".filter(c => [...c.querySelectorAll('.fc-save, .lg-act-save')].some(b => {"
                "  const cs = getComputedStyle(b), r = b.getBoundingClientRect();"
                "  return cs.display !== 'none' && cs.visibility !== 'hidden' &&"
                "         parseFloat(cs.opacity) > 0.01 && r.width > 0 && r.height > 0; }))"
                ".length")
            # ⚠️ ASSERTED AS ">=1", NOT "== all", and the number is measured not chosen.
            # LIKE-FOR-LIKE on 2026-07-30 — the SERVING CHECKOUT run through this SAME
            # loopback harness gives IDENTICAL figures to this branch (desktop 13 ship
            # / 12 visible, mobile 15 ship / 12 visible, the same 'lg-act lg-act-save'
            # nodes dark). So the shortfall is PRE-EXISTING and not variant A's doing;
            # failing on it would red-flag a condition this lane did not cause and
            # cannot fix, which is how a gate stops being believed.
            # The regression this phase exists for still cannot hide: Save moved
            # unconditionally drives this to ZERO (proven red-first, SPEC §18.3).
            check_ge(f"content cards {label}: at least one content card has a VISIBLE Save",
                     n_with_visible, 1)
            # NO SILENT CAPS — a shortfall is printed loudly even though it is not a
            # finding, so "green" can never be mistaken for "every card is fine".
            if n_with_visible < n_content:
                log(f"      NOTE: {n_content - n_with_visible} of {n_content} content cards ship a save "
                    f"control with NO visible copy at this width. Pre-existing — identical on the "
                    f"serving checkout through this harness. Not this lane's; worth someone's attention.")
            check(f"content cards {label}: every visible Save is hittable",
                  len([r for r in vis if r["inView"]]) == len(hittable), True)

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

        # ---- PHASE 3 — THE MODAL: does the consolidated control open something
        # a member can actually use? (§15 variant A)
        # Consolidation trades one tap for reachability, so the modal's contents have
        # to clear the same bar the row did: painted, hittable, and inside the panel.
        # This is also the only place Save is reachable on a topic card now — if the
        # modal's Save is dark, variant A has REMOVED Save from discussions, which is
        # the same class of loss Ian's "keep the save button on all other post types"
        # is guarding against, one post type over.
        emulate(p, 1280, 900, False)
        if not goto(p, base + HUB_PATH):
            cannot_run("hydration never completed for the modal phase")
        opened = p.ev("(() => { const t = document.querySelector('.feed-card [data-follow-open]');"
                      "  if (!t) return null; t.click(); return t.getAttribute('data-topic-id'); })()")
        if not opened:
            cannot_run("no [data-follow-open] on the feed to open the modal with")
        time.sleep(0.6)
        check("modal: it opened", p.ev("!!document.querySelector('#lg-follow-modal:not([hidden])')"), True)
        check("modal: the trigger reports expanded",
              p.ev("document.querySelector('.feed-card [data-follow-open]').getAttribute('aria-expanded')"), "true")
        # Its rows are the REAL controls — two [data-follow] switches and one .fc-save.
        mrows = probe(p, "#lg-follow-modal :is([data-follow], .fc-save)")
        mvis, mhit, mclip = summarise(mrows, "modal rows")
        check("modal: all three rows present (notify, email, save)", len(mrows), 3)
        check("modal: all three painted", len(mvis), 3)
        check("modal: all three hittable", len(mhit), 3)
        check("modal: its controls target the opened topic",
              p.ev("[...document.querySelectorAll('#lg-follow-modal [data-follow]')]"
                   ".every(b => b.getAttribute('data-topic-id') === '" + str(opened) + "')"), True)
        # Frequency is built but flagged OFF (§15.4 — no sender exists). Assert it is
        # ABSENT, so nobody can enable a dead cadence control without this going red.
        check("modal: frequency row is NOT shipped (FREQ_ENABLED false)",
              p.ev("!!document.querySelector('#lg-follow-modal #lg-fm-freq')"), False)
        p.ev("document.dispatchEvent(new KeyboardEvent('keydown', {key:'Escape'}))")
        time.sleep(0.3)
        check("modal: Escape closes it",
              p.ev("!!document.querySelector('#lg-follow-modal[hidden]')"), True)

        # ---- PHASE 4 — visible is not the same as WORKING ------------------
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
        # ⚠️ POLL, DO NOT SLEEP. A fixed 1.5s wait reported "no row" against a build
        # whose POST demonstrably returned 200 and DID write — the round-trip is just
        # slower than that under harness load. A too-short sleep manufactures a
        # finding, and an over-long one hides a real hang; polling does neither. The
        # NEXT navigation would also cancel an in-flight fetch, so waiting for the
        # store here is what stops the reload racing the write.
        wrote = False
        for _ in range(40):                      # up to 10s
            if db_notify(topic_id): wrote = True; break
            time.sleep(0.25)
        check("after click: a row exists in forums.topic_follow", wrote, True)

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
