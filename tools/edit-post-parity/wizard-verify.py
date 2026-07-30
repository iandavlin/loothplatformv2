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
            await asyncio.sleep(1.5)
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
                await s.ev("(function(){var t=document.getElementById('ntm-tags');"
                           "t.value='vintage, martin, wizardleg';"
                           "t.dispatchEvent(new Event('input',{bubbles:true}));"
                           "var r=document.querySelector('input[name=\"forum_id\"][value=\"3823\"]');"
                           "if(r){r.checked=true;r.dispatchEvent(new Event('change',{bubbles:true}));}"
                           "return true})()")
                await s.ev("(function(){var rail=document.querySelectorAll('.lgw-rail__step');"
                           "if(rail[3])rail[3].click();})()")
                await asyncio.sleep(1)
                check("Review step reachable before saving",
                      str(await s.ev("(function(){var c=document.querySelector('.lgw-step.is-active');"
                                     "return c?c.dataset.step:null})()")) == "4")
                await s.ev("(function(){var b=document.getElementById('ntm-submit');"
                           "if(b)b.click();return true})()")
                await asyncio.sleep(5)
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
