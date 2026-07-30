#!/usr/bin/env python3
"""wizard-verify.py — prove a DISCUSSION edits through the ADD-DISCUSSION wizard.

Ian's corrected spec, 2026-07-30: editing a discussion must open the 4-step "New post"
wizard (1 Where, 2 Write, 3 Photos, 4 Review) PRE-FILLED with the existing discussion's
forum, body and photos; it must OPEN ON STEP 2 "Write"; and the member must still be
able to reach the other steps to change them.

This replaces the composer-sheet assertions in browser-verify.py, which tested the wrong
door. It reuses that file's machinery — one persistent CDP session, branch-asset
injection, API proxying to the branch's php -S pool — because the serve answers from main
for the asset AND the endpoint, so without both the run silently measures somebody
else's code.

  DESKTOP 1280 is where the wizard exists (buildNtmWizard returns null below 641px).
  MOBILE 390 is asserted too, but for the honest thing: that it opens the SAME flat
  #ntm-form that CREATING a post opens there — parity per viewport, not a wizard.

  python3 tools/edit-post-parity/wizard-verify.py            # read-only
  python3 tools/edit-post-parity/wizard-verify.py --save     # also drive a real save
"""
import argparse, asyncio, json, os, sys, urllib.request, importlib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
bv = importlib.import_module("browser-verify")

TOPIC, HOST, CDP_HTTP = bv.TOPIC, bv.HOST, bv.CDP_HTTP
DESKTOP = {"width": 1280, "height": 900, "deviceScaleFactor": 1, "mobile": False,
           "screenWidth": 1280, "screenHeight": 900}
UA_DESK = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
           "Chrome/151.0.0.0 Safari/537.36")

results = []
def check(name, ok, detail=""):
    results.append((name, bool(ok), detail))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + (f"   [{detail}]" if detail else ""))

STATE = """(function(){
  var cur = document.querySelector('.lgw-step.is-active') ||
            document.querySelector('.lgw-step:not([hidden])');
  var c   = document.querySelector('input[name="forum_id"]:checked');
  var q   = document.querySelector('#ntm-editor .ql-editor');
  var ov  = document.getElementById('ntm-overlay');
  var lbl = [];
  document.querySelectorAll('.lgw-rail__step').forEach(function(d){
    lbl.push((d.textContent||'').trim().replace(/\\s+/g,' ')); });
  return {
    open   : !!(ov && !ov.hidden),
    heading: (document.getElementById('ntm-heading')||{}).textContent || '',
    steps  : document.querySelectorAll('.lgw-rail__step').length,
    labels : lbl,
    step   : cur ? (cur.dataset.step || null) : null,
    title  : (document.getElementById('ntm-title-in')||{}).value || '',
    forum  : c ? c.value : null,
    tags   : (document.getElementById('ntm-tags')||{}).value || '',
    body   : q ? q.innerHTML : '',
    saveOff: !!(document.getElementById('ntm-submit')||{}).disabled,
    thumbs : document.querySelectorAll('.lgc-pv, .ntm-tray__thumb, [data-media-id]').length
  };})()"""


async def open_session(s, metrics, ua):
    for m in ("Network.enable", "Page.enable", "Runtime.enable"):
        await s.send(m)
    await s.send("Network.setCacheDisabled", {"cacheDisabled": True})
    await s.send("Fetch.enable", {"patterns": [
        {"urlPattern": "*hub-polish.js*", "requestStage": "Request"},
        {"urlPattern": "*forums.js*", "requestStage": "Request"},
        {"urlPattern": "*bb-mirror-api/v0/reply*", "requestStage": "Request"},
        {"urlPattern": "*bb-mirror-api/v0/topic-media*", "requestStage": "Request"}]})
    await s.send("Network.clearBrowserCookies")
    for c in bv.mint_cookies():
        await s.send("Network.setCookie", c)
    await s.send("Emulation.setDeviceMetricsOverride", metrics)
    await s.send("Emulation.setUserAgentOverride", {"userAgent": ua})
    await s.send("Page.navigate", {"url": f"https://{HOST}/hub/"})
    await s.wait_for("document.readyState === 'complete'")


async def await_seeded(s):
    """Wait until the editor has actually been SEEDED, not merely until it looks open.

    THE PROBE BUG THIS REPLACES, measured 2026-07-30 on the merged tree. This file used
    to wait for the title and then sleep a flat 1.5s. The title is NOT the signal: it is
    pre-filled optimistically from the page while the authoritative payload is still in
    flight. On desktop the wizard has four steps to build, so 1.5s landed mid-seed and
    FIVE body assertions went red — bold, link, list, image, and the <ul> submit check —
    on a wizard whose content was entirely fine. That reads exactly like "editing a post
    destroys its formatting", which is the most alarming defect this lane has, and it
    was the instrument every time.

    Worse, the run then CLICKED SAVE on that half-built editor. Nothing was written,
    because this branch refuses to arm Save until the body is in — but a harness must
    not depend on the product's guard to avoid corrupting its own fixture.

    Save is the honest ready signal precisely BECAUSE of that guard: this branch releases
    Save from the seed callback rather than on payload arrival, so "Save is enabled"
    means "the body is in the editor". Waiting on it is not circular — if Save ever armed
    over an empty editor, the body assertions downstream would still catch it, and that
    is the very defect this lane fixed.

    Bounded and non-fatal: if the seed never completes, this returns anyway and the
    assertions report what is actually on screen rather than hanging the run.

    THREE SIGNALS, because each alone can be satisfied too early:

      1. the payload landed. Recorded when the PROXY FULFILS the request, which is
         strictly before the page's own .then() has run, so it is a floor and never a
         completion.
      2. the editor is non-empty. This is what actually rules out the mobile failure
         seen here: mobile is opened via lgNtmEditTopic(TOPIC,3837,'',''), passing an
         EMPTY body, so anything measured before the fetch seeds reads 0 list items and
         reports "editing destroys your bullets" against a post that still has them.
         The same run read the forum as 3837 seconds after a save had moved it to 3823 —
         it was reading the CALLER's optimistic argument, not the stored post.
      3. Save is armed — the seed callback's own signal (see the branch's ntmSeedBody).

    Not circular with the body assertions below. This waits for "something arrived";
    those assert WHICH something — that the bold, the link, both bullets and the inline
    image are all still in it. A seed that silently dropped the list would satisfy this
    and still go red down there, which is exactly the defect this lane fixed.
    """
    for _ in range(80):                       # payload first — it carries forum and tags
        if any("/reply" in c for c in s.api_served):
            break
        await asyncio.sleep(0.25)
    await s.wait_for("(function(){var q=document.querySelector('#ntm-editor .ql-editor');"
                     "return !!(q && q.textContent.trim().length > 0);})()", tries=120)
    return await s.wait_for("(function(){var b=document.getElementById('ntm-submit');"
                            "return !!(b && !b.disabled);})()", tries=120)


async def run(save):
    import websockets
    req = urllib.request.Request(CDP_HTTP + "/json/new?about:blank", method="PUT")
    tab = json.load(urllib.request.urlopen(req, timeout=10))
    print(f"  (opened own tab {tab['id'][:12]}…)")
    try:
        # ── DESKTOP: the wizard ────────────────────────────────────────────────
        async with websockets.connect(tab["webSocketDebuggerUrl"], max_size=None) as ws:
            s = bv.Session(ws, overrides=bv.OVERRIDES)
            await open_session(s, DESKTOP, UA_DESK)
            print("\n== desktop 1280 — the add-discussion wizard, in edit mode ==")
            check("THIS BRANCH's assets are what the page ran",
                  len(s.served) >= 2 and not s.missed,
                  f"{len(s.served)} injected" + (f", MISSED {s.missed}" if s.missed else ""))
            check("wizard exists at this width",
                  await s.ev("document.querySelectorAll('.lgw-rail__step').length") == 4)

            # Drive the documented seam the Edit button calls.
            await s.ev(f"window.lgNtmEditTopic({TOPIC},3837,'',''))"
                       .replace("))", ")"))
            await s.wait_for("(function(){var t=document.getElementById('ntm-title-in');"
                             "return !!(t&&t.value&&t.value.indexOf('ZZ TEST')===0);})()")
            seeded = await await_seeded(s)
            # Named apart from the body checks below on purpose. "The editor never
            # finished seeding" and "the editor seeded and lost your bold" are different
            # failures with different owners, and reporting the first as the second is
            # what sent five assertions red against healthy code.
            check("the editor finished seeding (Save released)", seeded,
                  "Save still disabled — body assertions below measure a half-built editor"
                  if not seeded else "")
            st = await s.ev(STATE)

            check("bb-mirror API answered by THIS BRANCH, not main",
                  any("/reply" in c for c in s.api_served) and not s.missed,
                  "; ".join(s.api_served) or "NOTHING PROXIED")
            check("the wizard opened", st.get("open"))
            check("heading says Edit post", st.get("heading") == "Edit post", st.get("heading"))
            check("all 4 steps present", st.get("steps") == 4, str(st.get("labels")))
            # THE correction Ian asked for.
            check("OPENS ON STEP 2 (Write), not Where", str(st.get("step")) == "2",
                  f"landed on step {st.get('step')}")
            check("forum PRE-FILLED to the post's own", str(st.get("forum")) == "3837",
                  str(st.get("forum")))
            check("title pre-filled", st.get("title", "").startswith("ZZ TEST"))
            # Asserted as a SUPERSET, not equality: --save deliberately adds a tag, so an
            # equality test would go red on the next run against a fixture the previous
            # run legitimately changed — a false red that hides real ones.
            tagset = set(t.strip() for t in st.get("tags", "").split(",") if t.strip())
            check("tags PRE-FILLED", {"martin", "vintage"} <= tagset, repr(st.get("tags")))

            body = st.get("body") or ""
            check("BOLD survives into the wizard", "<strong>" in body or "<b>" in body)
            check("LINK survives into the wizard", "example.com" in body)
            # The one that was silently deleted before: root.innerHTML seeding dropped it.
            check("LIST survives into the wizard", body.count("<li") == 2,
                  f'{body.count("<li")} items')
            # The wizard used to strip every image out of the body on open AND on save,
            # so a legacy post's inline image was destroyed by editing it at all.
            check("INLINE IMAGE survives into the wizard", "<img" in body,
                  "50 dev2 topics depend on this")
            # What gets SUBMITTED is the only thing that reaches the database, and the
            # wizard used to submit Quill's internal DOM: <ol data-list> + ql-ui spans,
            # which turned a member's bullets into numbers in the stored post.
            submit = await s.ev(
                "(function(){var el=document.getElementById('ntm-editor');"
                "if(!el) return 'ERR:no-editor';"
                "if(typeof Quill==='undefined'||!Quill.find) return 'ERR:no-Quill';"
                "var q=Quill.find(el)||Quill.find(el.querySelector('.ql-editor'));"
                "if(!q||!q.getSemanticHTML) return 'ERR:no-instance';"
                "return q.getSemanticHTML();})()") or ""
            check("submits a real <ul> bullet list (not <ol>, no ql-ui)",
                  not submit.startswith("ERR:") and "<ul>" in submit
                  and "ql-ui" not in submit and "data-list" not in submit,
                  submit if submit.startswith("ERR:")
                  else (submit[submit.find("<ul"):][:52] if "<ul" in submit else "no <ul>"))

            # Every other step must stay reachable — that is the rest of Ian's sentence.
            for n, label in ((1, "Where"), (3, "Photos"), (4, "Review")):
                await s.ev("(function(){var r=document.querySelectorAll('.lgw-rail__step')[%d];"
                           "if(r)r.click();})()" % (n - 1))
                await asyncio.sleep(0.6)
                got = await s.ev("(function(){var c=document.querySelector('.lgw-step.is-active');"
                                 "return c?c.dataset.step:null})()")
                check(f"can navigate to step {n} ({label})", str(got) == str(n), f"got {got}")
            await s.ev("(function(){var r=document.querySelectorAll('.lgw-rail__step')[1];"
                       "if(r)r.click();})()")
            await asyncio.sleep(0.6)
            check("and back to Write",
                  str(await s.ev("(function(){var c=document.querySelector('.lgw-step.is-active');"
                                 "return c?c.dataset.step:null})()")) == "2")

            if save:
                print("\n== drive a real save through the wizard ==")
                # Parameterised so the SAME real path can put the fixture back where it
                # started. A restore done in SQL would leave the postgres mirror and the
                # two forums' counters stating the old forum, so the next run would begin
                # from a subtly corrupt fixture — and re-saving through the wizard proves
                # the move works in BOTH directions rather than only away from home.
                forum = os.environ.get("EPP_SAVE_FORUM", "3823")
                tags  = os.environ.get("EPP_SAVE_TAGS", "vintage, martin, wizardleg")
                print(f"  (saving: forum={forum} tags={tags!r})")
                # The selector is built HERE and quoted as one string. Building it in JS
                # as [value='+forum+'] emits [value=3823] — an unquoted attribute value
                # starting with a digit, which is invalid CSS. querySelector THROWS, the
                # throw kills the rest of the function, and because the tags line has
                # already run you get a save that applies the tags and silently ignores
                # the forum. Measured: the DB showed wizardleg added and the topic still
                # in 3837, which reads as "the forum picker is broken" and was this bug.
                sel = 'input[name="forum_id"][value="%s"]' % forum
                applied = await s.ev(
                    "(function(){var t=document.getElementById('ntm-tags');"
                    "t.value=%s;"
                    "t.dispatchEvent(new Event('input',{bubbles:true}));"
                    "var r=document.querySelector(%s);"
                    "if(!r) return 'NO-RADIO';"
                    "r.checked=true;r.dispatchEvent(new Event('change',{bubbles:true}));"
                    "return 'ok';})()" % (json.dumps(tags), json.dumps(sel)))
                # Assert the intent LANDED before saving. Without this, a selector that
                # matches nothing produces a save that quietly changes less than the run
                # claims, and the DB diff downstream gets read as the feature's verdict.
                check("forum radio actually selected before saving", applied == "ok",
                      str(applied))
                await s.ev("(function(){var rail=document.querySelectorAll('.lgw-rail__step');"
                           "if(rail[3])rail[3].click();})()")
                await asyncio.sleep(1)
                check("Review step reachable before saving",
                      str(await s.ev("(function(){var c=document.querySelector('.lgw-step.is-active');"
                                     "return c?c.dataset.step:null})()")) == "4")
                # ASSERT SAVE IS ARMED BEFORE CLICKING IT. Without this the run clicks a
                # DISABLED button, the click is a silent no-op, and the script cheerfully
                # prints "(saved)" — so a save that never happened reads identically to
                # one that did, and the DB diff that follows shows "nothing moved", which
                # is then reported as "edit preserved everything". That is a false GREEN
                # on the lane's central claim. Measured on this box: the click really did
                # nothing, and only the byte-identical DB diff gave it away.
                armed = await s.ev("(function(){var b=document.getElementById('ntm-submit');"
                                   "return !!(b && !b.disabled);})()")
                check("Save is armed before we click it", armed is True,
                      "" if armed is True else "disabled — the click below is a silent no-op")
                await s.ev("(function(){var b=document.getElementById('ntm-submit');"
                           "if(b)b.click();return true})()")
                # POLLED, not slept. A flat 5s was not enough for a save that MOVES a
                # forum: it rewrites both replies' _bbp_forum_id, rebalances two forums'
                # counters and syncs topic + replies to the postgres mirror, and the run
                # caught it still showing "Saving…". A fixed sleep turns a slow box into
                # a red assertion about the feature.
                closed = await s.wait_for(
                    "(function(){var o=document.getElementById('ntm-overlay');"
                    "return !!(o && o.hidden);})()", tries=120)
                check("the composer closed after saving", closed is True,
                      await s.ev("(document.getElementById('ntm-status')||{}).textContent || ''"))
                print("  (saved — verify in the DB, NOT in the UI)")
            await s.send("Emulation.clearDeviceMetricsOverride")
    finally:
        try:
            urllib.request.urlopen(CDP_HTTP + "/json/close/" + tab["id"], timeout=10).read()
            print("  (closed own tab)")
        except Exception as e:
            print(f"  WARNING: could not close tab: {e}")

    # ── MOBILE: no wizard, and that is TRUE OF CREATE TOO ──────────────────────
    req = urllib.request.Request(CDP_HTTP + "/json/new?about:blank", method="PUT")
    tab2 = json.load(urllib.request.urlopen(req, timeout=10))
    try:
        async with websockets.connect(tab2["webSocketDebuggerUrl"], max_size=None) as ws:
            s = bv.Session(ws, overrides=bv.OVERRIDES)
            await open_session(s, bv.IPHONE, bv.UA_IOS)
            print("\n== mobile 390 — the SAME form create uses there (no wizard by design) ==")
            await s.ev(f"window.lgNtmEditTopic({TOPIC},3837,'','')")
            await await_seeded(s)   # same probe fix as desktop — see await_seeded()
            await s.wait_for("(function(){var t=document.getElementById('ntm-title-in');"
                             "return !!(t&&t.value&&t.value.indexOf('ZZ TEST')===0);})()")
            await asyncio.sleep(1.5)
            st = await s.ev(STATE)
            check("the edit form opens on mobile", st.get("open"))
            check("no wizard rail here — same as CREATE at this width",
                  st.get("steps") == 0, f"{st.get('steps')} steps")
            check("forum still pre-filled on mobile", str(st.get("forum")) == "3837",
                  str(st.get("forum")))
            check("tags still pre-filled on mobile", "martin" in (st.get("tags") or ""),
                  repr(st.get("tags")))
            check("LIST survives on mobile too", (st.get("body") or "").count("<li") == 2,
                  f'{(st.get("body") or "").count("<li")} items')
            await s.send("Emulation.clearDeviceMetricsOverride")
    finally:
        try:
            urllib.request.urlopen(CDP_HTTP + "/json/close/" + tab2["id"], timeout=10).read()
        except Exception as e:
            print(f"  WARNING: could not close tab: {e}")

    print("\n" + "=" * 62)
    bad = [r for r in results if not r[1]]
    print(f"wizard-verify: pass={len(results)-len(bad)} fail={len(bad)}")
    if bad:
        print("==================== WIZARD VERIFY RED ====================")
        return 1
    print("==================== WIZARD VERIFY GREEN ====================")
    return 0


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--save", action="store_true", help="also drive a real save")
    a = ap.parse_args()
    try:
        import websockets  # noqa: F401
    except ImportError:
        print("pip install websockets", file=sys.stderr); sys.exit(2)
    sys.exit(asyncio.run(run(a.save)))
