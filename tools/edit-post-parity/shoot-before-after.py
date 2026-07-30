#!/usr/bin/env python3
"""shoot-before-after.py — the two edit doors, same post, same engine, both viewports.

Ian decides from pictures, so this produces them from the real Hub on dev2 rather than
from a mock-up: same discussion (72306), same login, differing only in which code
answers the page.

FOUR shots, because the composer genuinely differs by viewport and hiding that would be
misleading:

  desktop-before  main today  — the flattened reply-composer sheet
  desktop-after   this branch — the 4-step wizard, pre-filled, open on Write
  mobile-before   main today  — the same flattened sheet on a phone
  mobile-after    this branch — the SAME flat #ntm-form that CREATING a post opens
                                at this width; there is no 4-step wizard below 641px
                                and there never has been, for create either.

BEFORE is not a reconstruction: it loads with NO injection so main's own hub-polish.js
runs, and opens the door through main's own call with main's own flatten expression
(origin/main:3588-3590, verbatim). AFTER injects this branch's hub-polish.js AND
forums.js and proxies the bb-mirror API to the branch's php -S pool, because the serve
answers from main for the assets and the endpoints alike.

  python3 tools/edit-post-parity/shoot-before-after.py [-o OUTDIR]
"""
import argparse, asyncio, base64, json, os, sys, urllib.request, importlib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
bv = importlib.import_module("browser-verify")

TOPIC, HOST, CDP_HTTP = bv.TOPIC, bv.HOST, bv.CDP_HTTP
DESKTOP = {"width": 1280, "height": 1000, "deviceScaleFactor": 1, "mobile": False,
           "screenWidth": 1280, "screenHeight": 1000}
UA_DESK = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
           "Chrome/151.0.0.0 Safari/537.36")

FLATTEN_JS = """
(function(html){
  return (html || '')
    .replace(/<img[^>]*>/gi, '').replace(/<br\\s*\\/?>/gi, '\\n')
    .replace(/<\\/p>\\s*<p[^>]*>/gi, '\\n\\n').replace(/<[^>]+>/g, '').trim();
})(%s)
"""


async def shoot(inject, metrics, ua, out_png, label):
    import websockets
    req = urllib.request.Request(CDP_HTTP + "/json/new?about:blank", method="PUT")
    tab = json.load(urllib.request.urlopen(req, timeout=10))
    try:
        async with websockets.connect(tab["webSocketDebuggerUrl"], max_size=None) as ws:
            s = bv.Session(ws, overrides=(bv.OVERRIDES if inject else {}))
            for m in ("Network.enable", "Page.enable", "Runtime.enable"):
                await s.send(m)
            await s.send("Network.setCacheDisabled", {"cacheDisabled": True})
            pats = [{"urlPattern": "*bb-mirror-api/v0/reply*", "requestStage": "Request"},
                    {"urlPattern": "*bb-mirror-api/v0/topic-media*", "requestStage": "Request"}]
            if inject:
                pats += [{"urlPattern": "*hub-polish.js*", "requestStage": "Request"},
                         {"urlPattern": "*forums.js*", "requestStage": "Request"}]
            await s.send("Fetch.enable", {"patterns": pats})
            await s.send("Network.clearBrowserCookies")
            for c in bv.mint_cookies():
                await s.send("Network.setCookie", c)
            await s.send("Emulation.setDeviceMetricsOverride", metrics)
            await s.send("Emulation.setUserAgentOverride", {"userAgent": ua})
            await s.send("Page.navigate", {"url": f"https://{HOST}/hub/"})
            await s.wait_for("document.readyState === 'complete'")
            await s.wait_for("typeof window.lgOpenComposer === 'function'")

            if inject:
                # The branch's door: the add-discussion wizard, pre-filled, on Write.
                await s.ev(f"window.lgNtmEditTopic({TOPIC},3837,'','')")
                ok = await s.wait_for(
                    "(function(){var t=document.getElementById('ntm-title-in');"
                    "return !!(t&&t.value&&t.value.indexOf('ZZ TEST')===0);})()")
            else:
                # Main's door: the composer sheet, seeded with FLATTENED text.
                raw = bv.sh("sudo -u looth-dev wp --path=/var/www/dev post get "
                            f"{TOPIC} --field=post_content 2>/dev/null")
                flat = await s.ev(FLATTEN_JS % json.dumps(raw))
                await s.ev("window.lgOpenComposer(%s)" % json.dumps({
                    "editTopicId": TOPIC, "tid": TOPIC, "fid": 3837,
                    "title": "ZZ TEST edit-post-parity (delete me)",
                    "bodyText": flat, "focus": False}))
                ok = await s.wait_for("!!document.getElementById('looth-comp-sheet')")
            if not ok:
                print(f"  {label}: composer did not populate"); return False

            # Let the identity header, the forum list and any image settle, so the pair
            # differs only where the CODE differs and not by how fast each shot was taken.
            await s.wait_for("(function(){var i=document.querySelector('#ntm-editor img,"
                             "#lgc-editor img');return !i || i.complete;})()")
            await asyncio.sleep(3)
            shot = await s.send("Page.captureScreenshot", {"format": "png"})
            data = shot.get("result", {}).get("data")
            if not data:
                print(f"  {label}: no screenshot data"); return False
            open(out_png, "wb").write(base64.b64decode(data))
            print(f"  {label}: {os.path.basename(out_png)} ({os.path.getsize(out_png)//1024}KB)")
            await s.send("Emulation.clearDeviceMetricsOverride")
            return True
    finally:
        try:
            urllib.request.urlopen(CDP_HTTP + "/json/close/" + tab["id"], timeout=10).read()
        except Exception as e:
            print(f"  WARNING: could not close tab {tab['id']}: {e}")


async def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("-o", "--outdir",
                    default="/home/ubuntu/projects/footer-mockups/edit-post-parity")
    a = ap.parse_args()
    os.makedirs(a.outdir, exist_ok=True)
    p = lambda n: os.path.join(a.outdir, n)
    r = []
    r.append(await shoot(False, DESKTOP, UA_DESK, p("desktop-before.png"), "desktop BEFORE (main)"))
    r.append(await shoot(True,  DESKTOP, UA_DESK, p("desktop-after.png"),  "desktop AFTER (wizard)"))
    r.append(await shoot(False, bv.IPHONE, bv.UA_IOS, p("mobile-before.png"), "mobile BEFORE (main)"))
    r.append(await shoot(True,  bv.IPHONE, bv.UA_IOS, p("mobile-after.png"),  "mobile AFTER (branch)"))
    sys.exit(0 if all(r) else 1)


if __name__ == "__main__":
    try:
        import websockets  # noqa: F401
    except ImportError:
        print("pip install websockets", file=sys.stderr); sys.exit(2)
    asyncio.run(main())
