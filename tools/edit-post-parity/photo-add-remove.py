#!/usr/bin/env python3
"""photo-add-remove.py — the last unproven acceptance line: ADD and REMOVE a photo on EDIT.

Ian's acceptance for this lane names "images/embeds" among the controls editing must
expose. Preservation was already proven — an inline <img> survives open and save, and a
forum-move save left content_md5 byte-identical. What was NOT proven is the Photos step
doing its actual job: attaching a NEW photo to an existing post, and taking one away.

That gap matters because the two halves have DIFFERENT owners on the server. The
BuddyBoss `PUT /topics/<id>` deliberately does not touch forum media on edit, so
topic-media.php owns add/remove entirely (its own header says so). A green save proves
nothing about photos; only the media rows do.

  ADD    : park the composer's file input, set a real file on it, fire change, save.
  REMOVE : reopen, click the tray's ✕, save.
  Both verified in the DB — bp_media rows and the topic's bp_media_ids — never the UI.

THE FILE INPUT IS NOT IN THE DOM. forums.js builds it on demand
(`input.type='file'; input.click()`) and never appends it, so there is no selector to
target and no static node for DOM.setFileInputFiles. Rather than start a SECOND browser
engine to get Playwright's filechooser helper — box law is one engine — this patches
HTMLInputElement.prototype.click so a file input parks itself in the document under a
known id instead of opening a chooser. The app's own onchange handler is untouched and
runs exactly as it would for a member.

STATUS: PROVEN on both viewports, and now deterministic — but read the next paragraph
before trusting a red run.

  DESKTOP 1280 : the wizard's Photos step, control .lgw-addphoto
  MOBILE  390  : no wizard by design (buildNtmWizard returns null below 641px), so the
                 tray sits inline under the editor and the control is the fbStyleComposer
                 Photo button [data-fbc-photo], which calls lgNtmPhoto() directly — the
                 iOS-gesture-safe path (hub-polish.js:1284). Same tray helper underneath.
  Select with EPP_VIEWPORT=mobile.

THIS TOOL ONCE FAILED ~1/3 OF RUNS FOR A REASON THAT WAS NOT THE PHOTO CODE, and the
avoidance is deliberate rather than cosmetic. open_edit() passes a NON-EMPTY bodyHtml to
lgNtmEditTopic. With an EMPTY one, the wizard's two seed loops race (forums.js:1937
optimistic vs :1979 authoritative, no generation guard) and a late empty optimistic seed
clears the editor; the submit then omits `content` entirely (forums.js:2560) and bbPress
rejects the save with "Sorry, Your discussion cannot be empty." That is a real
PRE-EXISTING bug on main — measured equal on main's own assets and on this branch — and
it is written up as its own backlog item; it is NOT this lane's and NOT this tool's
subject. Passing a non-empty body is what a real Edit button does anyway (both call
sites pass the scraped OP: hub-polish.js:3624, forums.js:4777), so this is the member's
path, not a workaround that hides anything.

  If a run still dies on "cannot be empty", it is that bug, not the photo code — the save
  never reached the media endpoint. EPP_PHOTO_CLEAN=1 strips photos a failed run left
  behind.

  EPP_API_ORIGIN=http://127.0.0.1:8797 python3 tools/edit-post-parity/photo-add-remove.py
  EPP_API_ORIGIN=http://127.0.0.1:8797 EPP_PHOTO_CLEAN=1 python3 …/photo-add-remove.py
"""
import asyncio, json, os, subprocess, sys, urllib.request, importlib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
bv = importlib.import_module("browser-verify")

TOPIC, HOST, CDP_HTTP = bv.TOPIC, bv.HOST, bv.CDP_HTTP
DESKTOP = {"width": 1280, "height": 1000, "deviceScaleFactor": 1, "mobile": False,
           "screenWidth": 1280, "screenHeight": 1000}
UA_DESK = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
           "Chrome/151.0.0.0 Safari/537.36")
# VIEWPORT. Desktop drives the WIZARD's Photos step; mobile has no wizard by design
# (buildNtmWizard returns null below 641px), so its tray is inline under the editor and
# its photo control is the fbStyleComposer's own button, which calls lgNtmPhoto() direct
# — the iOS-gesture-safe path (hub-polish.js:1284). Same tray helper underneath, so this
# proves the SAME add/remove on the surface a phone actually uses.
MOBILE  = os.environ.get("EPP_VIEWPORT", "desktop").lower().startswith("m")
ADD_SEL = "[data-fbc-photo]" if MOBILE else ".lgw-addphoto"
VP      = "MOBILE 390" if MOBILE else "DESKTOP 1280"
PHOTO = os.environ.get("EPP_PHOTO", "/tmp/claude-1000/-home-ubuntu-worktrees-edit-post-parity/"
                                    "55596bd1-abe0-471f-ad25-20d285d0b750/scratchpad/reprove/"
                                    "zz-edit-parity-photo.png")

results = []
def check(name, ok, detail=""):
    results.append((name, bool(ok), detail))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + (f"   [{detail}]" if detail else ""))


def db(sql):
    out = subprocess.run(
        ["sudo", "-u", "looth-dev", "wp", "--path=/var/www/dev", "db", "query", sql,
         "--skip-column-names"], capture_output=True, text=True).stdout
    return [l for l in out.splitlines() if l.strip() and "PHP Warning" not in l]


def media_rows():
    """The topic's photo set, straight from the store."""
    ids = db(f"SELECT meta_value FROM wp_postmeta WHERE post_id={TOPIC} "
             f"AND meta_key='bp_media_ids'")
    raw = (ids[0].strip() if ids else "")
    if not raw:
        return []
    safe = ",".join(x for x in raw.split(",") if x.strip().isdigit())
    if not safe:
        return []
    return db(f"SELECT id, attachment_id, title FROM wp_bp_media WHERE id IN ({safe}) ORDER BY id")


# Park file inputs instead of opening a chooser. Installed BEFORE the add button is
# clicked; the app's own onchange is left alone so the upload path is the member's.
PARK = """(function(){
  if (window.__lgParked) return 'already';
  var _c = HTMLInputElement.prototype.click;
  HTMLInputElement.prototype.click = function () {
    if (this.type === 'file') {
      this.id = '__lgfile';
      this.style.position='fixed'; this.style.left='-9999px';
      if (!this.isConnected) document.body.appendChild(this);
      window.__lgFileReady = true;
      return;                       // no chooser — the node is now addressable
    }
    return _c.apply(this, arguments);
  };
  window.__lgParked = true; return 'ok';
})()"""

TRAY = ("(function(){return {items:document.querySelectorAll('.lg-mtray__item').length,"
        "uploading:!!document.querySelector('.lg-mtray.is-uploading'),"
        "status:(document.getElementById('ntm-status')||{}).textContent||''};})()")


# The rendered-OP pre-fill a real Edit button passes. NOT cosmetic — see the header:
# an EMPTY bodyHtml trips the pre-existing seed race and the save is refused, which has
# nothing to do with photos and would make this tool flaky about a third of the time.
SEED_BODY = "<p>optimistic pre-fill, as the real Edit control passes</p>"


async def open_edit(s):
    await s.ev("window.lgNtmEditTopic(%d,3837,'',%s)" % (TOPIC, json.dumps(SEED_BODY)))
    for _ in range(80):
        if any("/reply" in c for c in s.api_served):
            break
        await asyncio.sleep(0.25)
    await s.wait_for("(function(){var q=document.querySelector('#ntm-editor .ql-editor');"
                     "return !!(q && q.textContent.trim().length>0);})()", tries=120)
    return await s.wait_for("(function(){var b=document.getElementById('ntm-submit');"
                            "return !!(b && !b.disabled);})()", tries=120)


async def goto_step(s, n):
    await s.ev("(function(){var r=document.querySelectorAll('.lgw-rail__step');"
               "if(r[%d])r[%d].click();})()" % (n - 1, n - 1))
    await asyncio.sleep(1)
    return await s.ev("(function(){var c=document.querySelector('.lgw-step.is-active');"
                      "return c?c.dataset.step:null})()")


async def to_step(s, n):
    """Desktop: move the wizard. Mobile: nothing to move — the flat form shows the
    editor, the inline tray and Post all at once, which is exactly why parity here is
    per-viewport rather than one composer everywhere."""
    if MOBILE:
        return "flat"
    return await goto_step(s, n)


async def save(s):
    await s.ev("(function(){var b=document.getElementById('ntm-submit');"
               "if(b)b.click();return true})()")
    return await s.wait_for("(function(){var o=document.getElementById('ntm-overlay');"
                            "return !!(o && o.hidden);})()", tries=160)


async def session():
    import websockets
    req = urllib.request.Request(CDP_HTTP + "/json/new?about:blank", method="PUT")
    tab = json.load(urllib.request.urlopen(req, timeout=10))
    ws = await websockets.connect(tab["webSocketDebuggerUrl"], max_size=None)
    s = bv.Session(ws, overrides=bv.OVERRIDES)
    for m in ("Network.enable", "Page.enable", "Runtime.enable", "DOM.enable"):
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
    await s.send("Emulation.setDeviceMetricsOverride", bv.IPHONE if MOBILE else DESKTOP)
    if MOBILE:
        await s.send("Emulation.setTouchEmulationEnabled",
                     {"enabled": True, "maxTouchPoints": 5})
    await s.send("Emulation.setUserAgentOverride",
                 {"userAgent": bv.UA_IOS if MOBILE else UA_DESK})
    await s.send("Page.navigate", {"url": f"https://{HOST}/hub/"})
    await s.wait_for("document.readyState === 'complete'")
    return tab, ws, s


async def clean_only():
    """Strip every photo off the fixture through the real tray — used to recover from a
    run that left some attached (the double-fire above did exactly that)."""
    print("===== CLEAN: remove every photo from the fixture =====")
    print(f"  before: {media_rows() or '(none)'}")
    tab, ws, s = await session()
    try:
        await open_edit(s)
        await to_step(s, 3)
        await s.wait_for("document.querySelectorAll('.lg-mtray__item').length > 0", tries=120)
        n = await s.ev("(function(){var xs=document.querySelectorAll('.lg-mtray__rm');"
                       "Array.prototype.forEach.call(xs,function(x){x.click();});"
                       "return xs.length;})()")
        print(f"  cleared {n} thumb(s)")
        await asyncio.sleep(1.5)
        left = await s.ev("document.querySelectorAll('.lg-mtray__item').length")
        step = await to_step(s, 4)
        closed = await save(s)
        status = await s.ev("(document.getElementById('ntm-status')||{}).textContent||''")
        print(f"  thumbs left={left} step={step} closed={closed} status={status!r}")
    finally:
        try:
            await ws.close()
            urllib.request.urlopen(CDP_HTTP + "/json/close/" + tab["id"], timeout=10).read()
        except Exception:
            pass
    await asyncio.sleep(2)
    rows = media_rows()
    print(f"  after : {rows or '(none)'}")
    return 0 if not rows else 1


async def main():
    if os.environ.get("EPP_PHOTO_CLEAN"):
        return await clean_only()
    before = media_rows()
    print(f"  photo set BEFORE: {before or '(none)'}")
    if not os.path.exists(PHOTO):
        print(f"missing test photo {PHOTO}", file=sys.stderr)
        return 2

    # ── ADD ────────────────────────────────────────────────────────────────────
    print(f"\n===== {VP}: ADD a photo through the real photo control =====")
    tab, ws, s = await session()
    try:
        check("edit opens and seeds", await open_edit(s))
        st3 = await to_step(s, 3)
        check("photo control reachable", st3 in ("3", "flat"), str(st3))
        check("photo control parked (no OS chooser)", await s.ev(PARK) in ("ok", "already"))
        tapped = await s.ev("(function(){var b=document.querySelector(%s);"
                            "if(!b)return false;b.click();return true;})()" % json.dumps(ADD_SEL))
        check(f"the real photo control exists and is tappable ({ADD_SEL})", tapped is True)
        parked = await s.wait_for("window.__lgFileReady === true", tries=40)
        check("the composer asked for a file", parked)

        doc = await s.send("DOM.getDocument", {"depth": 1})
        root = doc.get("result", {}).get("root", {}).get("nodeId")
        node = await s.send("DOM.querySelector", {"nodeId": root, "selector": "#__lgfile"})
        nid = node.get("result", {}).get("nodeId")
        check("file input addressable", bool(nid), str(nid))
        # setFileInputFiles FIRES change ITSELF. Dispatching one as well ran the app's
        # onchange twice and uploaded the same file twice: one picked file, TWO thumbs,
        # TWO bp_media rows. That reads as "editing duplicates your photos" and was
        # entirely the harness. Set the files and let the browser deliver the event.
        await s.send("DOM.setFileInputFiles", {"files": [PHOTO], "nodeId": nid})

        BODY = ("(function(){var q=document.querySelector('#ntm-editor .ql-editor');"
                "return q?q.textContent.trim().length:-1;})()")
        print(f"    body len: after-open-and-step3 = {await s.ev(BODY)}")
        got = await s.wait_for("document.querySelectorAll('.lg-mtray__item').length > 0",
                               tries=160)
        print(f"    body len: after-upload = {await s.ev(BODY)}")
        check("a thumb appears in the tray after upload", got, json.dumps(await s.ev(TRAY)))
        # Upload must have SETTLED before saving, or keep/add ids are sent half-built.
        await s.wait_for("!document.querySelector('.lg-mtray.is-uploading')", tries=160)
        await to_step(s, 4)
        print(f"    body len: at-step4-before-save = {await s.ev(BODY)}")
        check("saved (composer closed)", await save(s),
              await s.ev("(document.getElementById('ntm-status')||{}).textContent||''"))
    finally:
        try:
            await ws.close()
            urllib.request.urlopen(CDP_HTTP + "/json/close/" + tab["id"], timeout=10).read()
        except Exception:
            pass

    await asyncio.sleep(2)
    after_add = media_rows()
    print(f"  photo set AFTER ADD: {after_add or '(none)'}")
    check("the DB gained exactly one photo", len(after_add) == len(before) + 1,
          f"{len(before)} -> {len(after_add)}")

    # ── REMOVE ─────────────────────────────────────────────────────────────────
    print(f"\n===== {VP}: REMOVE that photo through the same tray =====")
    tab, ws, s = await session()
    try:
        check("edit reopens and seeds", await open_edit(s))
        st3b = await to_step(s, 3)
        check("photo control reachable again", st3b in ("3", "flat"), str(st3b))
        # The stored photo must come back as a removable thumb, or there is nothing
        # to take away and "remove" would pass by having nothing to do.
        shown = await s.wait_for("document.querySelectorAll('.lg-mtray__item').length > 0",
                                 tries=160)
        check("the stored photo reopens as a removable thumb", shown,
              json.dumps(await s.ev(TRAY)))
        # Clear the WHOLE tray, not just the first thumb. Removing one of several leaves
        # the fixture holding photos it did not start with, so the next run begins from a
        # different baseline and its "back to the original set" assertion is meaningless.
        # Self-restoring is what makes this runnable twice.
        await s.ev("(function(){var xs=document.querySelectorAll('.lg-mtray__rm');"
                   "Array.prototype.forEach.call(xs,function(x){x.click();});"
                   "return xs.length;})()")
        await asyncio.sleep(1.5)
        check("thumb removed in the UI",
              await s.ev("document.querySelectorAll('.lg-mtray__item').length") == 0,
              json.dumps(await s.ev(TRAY)))
        await to_step(s, 4)
        check("saved (composer closed)", await save(s),
              await s.ev("(document.getElementById('ntm-status')||{}).textContent||''"))
    finally:
        try:
            await ws.close()
            urllib.request.urlopen(CDP_HTTP + "/json/close/" + tab["id"], timeout=10).read()
        except Exception:
            pass

    await asyncio.sleep(2)
    after_rm = media_rows()
    print(f"  photo set AFTER REMOVE: {after_rm or '(none)'}")
    check("the DB is back to the original photo set", len(after_rm) == len(before),
          f"{len(after_add)} -> {len(after_rm)}")

    print("\n" + "=" * 62)
    bad = [r for r in results if not r[1]]
    print(f"photo-add-remove: pass={len(results)-len(bad)} fail={len(bad)}")
    if bad:
        print("============ PHOTO ADD/REMOVE NOT PROVEN ============")
        return 1
    print("============ PHOTO ADD/REMOVE PROVEN ============")
    return 0


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
