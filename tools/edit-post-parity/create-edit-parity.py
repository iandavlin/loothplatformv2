#!/usr/bin/env python3
"""create-edit-parity.py — EDIT reuses the composer CREATE uses, ON THE SAME VIEWPORT.

Ian's definitive ruling 2026-07-30. The desktop/mobile split stays — it exists because
one design could not serve both, and that is fine. The rule is only that on each
viewport, editing a discussion opens the SAME composer, by the SAME code path, that
creating one opens. Not the reply composer, and not a parallel copy.

So this does not assert "it looks right". For each viewport it opens CREATE through the
control a member actually taps, records WHICH composer container appeared, then opens
EDIT through the control a member actually taps, and asserts it is the SAME container —
and that the reply composers are absent from both.

  CREATE control, desktop : [data-ntm-open]  ("+ New post")
  CREATE control, mobile  : the bottom-nav "+" (.lt-post). bottom-nav.js openComposer()
                            fires [data-ntm-open] itself, so both resolve to #ntm-form;
                            the real button is clicked here rather than assumed.
  EDIT control, desktop   : .lg-dmodal__edit   (discussion modal)
  EDIT control, mobile    : .lrs-op__edit      (replies sheet)

Assets come from the branch and the bb-mirror API is proxied to the branch's php -S
pool, because the serve answers from main for both.

  python3 tools/edit-post-parity/create-edit-parity.py
"""
import asyncio, json, os, sys, urllib.request, importlib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
bv = importlib.import_module("browser-verify")

TOPIC, HOST, CDP_HTTP = bv.TOPIC, bv.HOST, bv.CDP_HTTP
SLUG = "general/zz-test-edit-post-parity-delete-me"
DESKTOP = {"width": 1280, "height": 1000, "deviceScaleFactor": 1, "mobile": False,
           "screenWidth": 1280, "screenHeight": 1000}
UA_DESK = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
           "Chrome/151.0.0.0 Safari/537.36")

results = []
def check(name, ok, detail=""):
    results.append((name, bool(ok), detail))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + (f"   [{detail}]" if detail else ""))

# Which composer is on screen? Reported as an identity, so create and edit can be
# compared as the SAME THING rather than as two lists of features that look alike.
WHICH = """(function(){
  var ntm = document.getElementById('ntm-overlay');
  var sheet = document.getElementById('looth-comp-sheet');
  var frm = document.getElementById('frm-overlay');
  var open = [];
  if (ntm && !ntm.hidden) open.push('ntm-form');
  if (sheet) open.push('reply-composer-sheet');
  if (frm && !frm.hidden) open.push('frm-reply-composer');
  var c = document.querySelector('input[name="forum_id"]:checked');
  var q = document.querySelector('#ntm-editor .ql-editor');
  var cur = document.querySelector('.lgw-step.is-active');
  return {
    composer: open.join('+') || 'none',
    heading : (document.getElementById('ntm-heading')||{}).textContent || '',
    steps   : document.querySelectorAll('.lgw-rail__step').length,
    step    : cur ? (cur.dataset.step || null) : null,
    title   : (document.getElementById('ntm-title-in')||{}).value || '',
    forum   : c ? c.value : null,
    tags    : (document.getElementById('ntm-tags')||{}).value || '',
    body    : q ? q.innerHTML : ''
  };})()"""


async def fresh(metrics, ua, url):
    """A tab per observation: composers persist once opened, so reusing one tab would
    let CREATE's container still be on screen while EDIT is measured, and the parity
    assertion would pass for the wrong reason."""
    import websockets
    req = urllib.request.Request(CDP_HTTP + "/json/new?about:blank", method="PUT")
    tab = json.load(urllib.request.urlopen(req, timeout=10))
    ws = await websockets.connect(tab["webSocketDebuggerUrl"], max_size=None)
    s = bv.Session(ws, overrides=bv.OVERRIDES)
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
    await s.send("Page.navigate", {"url": url})
    await s.wait_for("document.readyState === 'complete'")
    return tab, ws, s


async def close(tab, ws):
    try:
        await ws.close()
    except Exception:
        pass
    try:
        urllib.request.urlopen(CDP_HTTP + "/json/close/" + tab["id"], timeout=10).read()
    except Exception:
        pass


async def viewport(metrics, ua, label, create_sel, edit_sel):
    print(f"\n===== {label} =====")

    # ── CREATE, through the control a member taps ──────────────────────────────
    tab, ws, s = await fresh(metrics, ua, f"https://{HOST}/hub/")
    try:
        await s.wait_for("typeof window.lgOpenComposer === 'function'")
        tapped = await s.ev("(function(){var b=document.querySelector(%s);"
                            "if(!b)return false;b.click();return true;})()" % json.dumps(create_sel))
        check(f"CREATE control exists and is tappable ({create_sel})", tapped is True)
        await asyncio.sleep(3)
        created = await s.ev(WHICH)
    finally:
        await close(tab, ws)
    print(f"    create -> composer={created.get('composer')} heading={created.get('heading')!r} "
          f"steps={created.get('steps')}")

    # ── EDIT, through the control a member taps ────────────────────────────────
    tab, ws, s = await fresh(metrics, ua, f"https://{HOST}/hub/?topic={SLUG}")
    try:
        # Wait for VISIBLE, not merely present. Both Edit buttons are rendered hidden
        # and are unhidden inside the auth callback — the same callback that attaches
        # the click listener. Waiting only for existence clicks a button that has no
        # handler yet, nothing opens, and it reads exactly like a broken feature.
        # 30s, not the default 10. The deep link has to fetch the discussion, render the
        # thread, then resolve auth before the button is unhidden; at 10s the mobile
        # sheet lost that race intermittently and reported a missing control, which
        # reads as "edit is broken on phones" and is only ever a slow box.
        ok = await s.wait_for("(function(){var b=document.querySelector(%s);"
                              "return !!(b && !b.hidden);})()" % json.dumps(edit_sel),
                              tries=120)
        check(f"EDIT control appears for the author ({edit_sel})", ok)
        await s.ev("(function(){var b=document.querySelector(%s);if(b)b.click();})()"
                   % json.dumps(edit_sel))
        await s.wait_for("(function(){var t=document.getElementById('ntm-title-in');"
                         "return !!(t&&t.value&&t.value.indexOf('ZZ TEST')===0);})()")
        # The title alone is NOT the signal: it is pre-filled optimistically from the
        # page while the authoritative payload is still in flight, so measuring here
        # caught the desktop run mid-fetch and read tags as empty. Wait for the payload
        # request to actually complete — it is what carries the tags — then measure.
        for _ in range(60):
            if any("/reply" in c for c in s.api_served):
                break
            await asyncio.sleep(0.25)
        await asyncio.sleep(1.5)
        edited = await s.ev(WHICH)
        api = list(s.api_served)
    finally:
        await close(tab, ws)
    print(f"    edit   -> composer={edited.get('composer')} heading={edited.get('heading')!r} "
          f"steps={edited.get('steps')} step={edited.get('step')}")

    # ── THE RULING ─────────────────────────────────────────────────────────────
    check("EDIT opens the SAME composer as CREATE on this viewport",
          created.get("composer") == edited.get("composer") != "none",
          f"create={created.get('composer')} edit={edited.get('composer')}")
    check("that composer is the create form (#ntm-form), not a copy",
          edited.get("composer") == "ntm-form", edited.get("composer"))
    check("the REPLY composer is not involved in either",
          "reply" not in (created.get("composer") or "")
          and "reply" not in (edited.get("composer") or ""))
    check("same step structure as create on this viewport",
          created.get("steps") == edited.get("steps"),
          f"create={created.get('steps')} edit={edited.get('steps')}")
    check("heading distinguishes the two modes",
          created.get("heading") == "New post" and edited.get("heading") == "Edit post",
          f"{created.get('heading')!r} vs {edited.get('heading')!r}")

    # Pre-filled, which is the other half of what Ian asked for.
    check("EDIT is pre-filled: title", edited.get("title", "").startswith("ZZ TEST"))
    check("EDIT is pre-filled: forum", str(edited.get("forum")) == "3837", str(edited.get("forum")))
    check("EDIT is pre-filled: tags", "martin" in (edited.get("tags") or ""),
          repr(edited.get("tags")))
    body = edited.get("body") or ""
    check("EDIT is pre-filled: body keeps bold/link/list/image",
          ("<strong>" in body or "<b>" in body) and "example.com" in body
          and body.count("<li") == 2 and "<img" in body,
          f'{body.count("<li")} list items, img={"<img" in body}')
    check("payload came from THIS BRANCH's API", any("/reply" in c for c in api),
          "; ".join(api) or "NOTHING PROXIED")
    if edited.get("steps"):
        check("wizard opens on Write (step 2)", str(edited.get("step")) == "2",
              f"step {edited.get('step')}")


REPLY_STATE = """(function(){
  var sh = document.getElementById('looth-comp-sheet');
  var fo = document.getElementById('frm-overlay');
  var q  = document.querySelector('#lgc-editor .ql-editor')
        || document.querySelector('#frm-editor .ql-editor');
  return {
    open : !!sh || !!(fo && !fo.hidden),
    which: sh ? 'sheet' : ((fo && !fo.hidden) ? 'frm' : 'none'),
    ctx  : (document.getElementById('lgc-ctx')||{}).textContent
        || (document.getElementById('frm-heading')||{}).textContent || '',
    body : q ? q.innerHTML : '(no editor)'
  };})()"""


def _empty(html):
    """Quill's empty document is '<p><br></p>', not ''."""
    return html.replace("<p><br></p>", "").replace("<br>", "").strip() in ("", "<p></p>")


async def reply_reset(metrics, ua, label, fresh_sel, edit_pair):
    """Ian 2026-07-30: NEW (reply or post) starts blank; EDIT is pre-filled. Asserted in
    ONE tab, in order, because the bug reported is precisely that state SURVIVES between
    uses — a fresh tab per step would reset it and prove nothing."""
    print(f"\n===== {label} — new-vs-edit reply state =====")
    tab, ws, s = await fresh(metrics, ua, f"https://{HOST}/hub/?topic={SLUG}")
    try:
        await s.wait_for("!!document.querySelector(%s)" % json.dumps(edit_pair[0]), tries=120)
        # 1. EDIT a reply first, so there IS stale state to leak.
        for sel in edit_pair:
            await s.ev("(function(){var b=document.querySelector(%s);if(b)b.click();})()"
                       % json.dumps(sel))
            await asyncio.sleep(1.2)
        await asyncio.sleep(2)
        ed = await s.ev(REPLY_STATE)
        check("EDIT reply opens PRE-FILLED", ed.get("open") and not _empty(ed.get("body", "")),
              f"{ed.get('which')}: {ed.get('body','')[:44]}")
        # 2. Close it, then start a FRESH reply in the same tab.
        await s.ev("(function(){var c=document.getElementById('frm-cancel');if(c&&!c.offsetParent===false)c.click();"
                   "if(window.LgSheets&&window.LgSheets.close)window.LgSheets.close('lcp');"
                   "var o=document.getElementById('frm-overlay');if(o&&!o.hidden){var b=document.getElementById('frm-cancel');if(b)b.click();}})()")
        await asyncio.sleep(2)
        await s.ev("(function(){var b=document.querySelector(%s);if(b)b.click();})()"
                   % json.dumps(fresh_sel))
        await asyncio.sleep(3)
        fr = await s.ev(REPLY_STATE)
        check("FRESH reply opens EMPTY (no leftover from the edit)",
              fr.get("open") and _empty(fr.get("body", "")),
              f"{fr.get('which')}: {fr.get('body','')[:44]}")
        # 3. And the closed composer must not be sitting on the old text either.
        await s.ev("(function(){if(window.LgSheets&&window.LgSheets.close)window.LgSheets.close('lcp');"
                   "var c=document.getElementById('frm-cancel');if(c)c.click();})()")
        await asyncio.sleep(1.5)
        st = await s.ev("(function(){var q=document.querySelector('#frm-editor .ql-editor');"
                        "return q?q.innerHTML:'(none)';})()")
        check("a CLOSED reply composer holds no stale text", st == "(none)" or _empty(st),
              st[:44])
    finally:
        await close(tab, ws)


async def main():
    await viewport(DESKTOP, UA_DESK, "DESKTOP 1280 — create wizard vs edit wizard",
                   "[data-ntm-open]", ".lg-dmodal__edit")
    await viewport(bv.IPHONE, bv.UA_IOS, "MOBILE 390 — create form vs edit form",
                   ".lt-post", ".lrs-op__edit")
    await reply_reset(DESKTOP, UA_DESK, "DESKTOP 1280",
                      ".lg-dmodal__act[data-frm-open], .lg-dmodal .feed-card__reply-cta[data-frm-open]",
                      ['.reply-stub[data-reply-id="72307"] .dm-rs-edit'])
    await reply_reset(bv.IPHONE, bv.UA_IOS, "MOBILE 390", "#lrs-replybtn",
                      ['.reply-stub[data-reply-id="72307"] .lg-fb-more', '.lg-fb-menu__edit'])
    print("\n" + "=" * 64)
    bad = [r for r in results if not r[1]]
    print(f"create-edit-parity: pass={len(results)-len(bad)} fail={len(bad)}")
    if bad:
        print("============ PER-VIEWPORT REUSE NOT PROVEN ============")
        return 1
    print("============ CONFIRMED PER-VIEWPORT REUSE ============")
    return 0


if __name__ == "__main__":
    try:
        import websockets  # noqa: F401
    except ImportError:
        print("pip install websockets", file=sys.stderr); sys.exit(2)
    sys.exit(asyncio.run(main()))
