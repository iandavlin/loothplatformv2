#!/usr/bin/env python3
"""shoot-before-after.py — the two edit doors, same phone, same post, same engine.

Ian decides from pictures, so this produces the pair rather than prose: what the
edit modal offers on MAIN today, and what it offers on this branch. Both shots are
the real /hub/ on dev2, the real topic 72306, the real session — phone-emulated at
390x844 because that is the surface he judges on.

BEFORE is not a mock and not a reconstruction. It loads the page with NO injection,
so main's own hub-polish.js runs, and opens the composer through main's own call
with main's own flattening (the `bodyText` expression at origin/main:3588-3590,
copied verbatim below). That flatten is the bug: strip <img>, <br> to newline, then
every remaining tag to '' — so the title/body textarea is seeded with plain text and
pressing Save writes that back over a post that had bold, a link, a list and an
inline image.

AFTER injects this branch's hub-polish.js and proxies the bb-mirror API to the
branch's php -S pool, exactly as browser-verify.py does, because the serve serves
main for both the asset and the endpoint.

  python3 tools/edit-post-parity/shoot-before-after.py [-o OUTDIR]
"""
import argparse, asyncio, base64, json, os, sys, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import importlib
bv = importlib.import_module("browser-verify")

TOPIC, HOST, CDP_HTTP = bv.TOPIC, bv.HOST, bv.CDP_HTTP

# main's flatten, verbatim, applied to the branch's own raw body so BEFORE shows
# exactly what a member sees today when they tap Edit on this very post.
FLATTEN_JS = """
(function(html){
  return (html || '')
    .replace(/<img[^>]*>/gi, '').replace(/<br\\s*\\/?>/gi, '\\n')
    .replace(/<\\/p>\\s*<p[^>]*>/gi, '\\n\\n').replace(/<[^>]+>/g, '').trim();
})(%s)
"""


async def shoot(inject, out_png, label):
    import websockets
    pages = json.load(urllib.request.urlopen(CDP_HTTP + "/json", timeout=10))
    req = urllib.request.Request(CDP_HTTP + "/json/new?about:blank", method="PUT")
    tab = json.load(urllib.request.urlopen(req, timeout=10))
    try:
        async with websockets.connect(tab["webSocketDebuggerUrl"], max_size=None) as ws:
            s = bv.Session(ws, overrides=(bv.OVERRIDES if inject else {}))
            await s.send("Network.enable"); await s.send("Page.enable")
            await s.send("Runtime.enable")
            await s.send("Network.setCacheDisabled", {"cacheDisabled": True})
            pats = [{"urlPattern": "*bb-mirror-api/v0/reply*", "requestStage": "Request"},
                    {"urlPattern": "*bb-mirror-api/v0/topic-media*", "requestStage": "Request"}]
            if inject:
                pats.insert(0, {"urlPattern": "*hub-polish.js*", "requestStage": "Request"})
            await s.send("Fetch.enable", {"patterns": pats})
            await s.send("Network.clearBrowserCookies")
            for c in bv.mint_cookies():
                await s.send("Network.setCookie", c)
            await s.send("Emulation.setDeviceMetricsOverride", bv.IPHONE)
            await s.send("Emulation.setUserAgentOverride", {"userAgent": bv.UA_IOS})
            await s.send("Emulation.setTouchEmulationEnabled", {"enabled": True})
            await s.send("Page.navigate", {"url": f"https://{HOST}/hub/"})
            await s.wait_for("document.readyState === 'complete'")
            if not await s.wait_for("typeof window.lgOpenComposer === 'function'"):
                print(f"  {label}: composer never appeared"); return False

            if inject:
                await s.ev(f"window.lgOpenComposer({{editTopicId:{TOPIC}, tid:{TOPIC}}})")
                ok = await s.wait_for(
                    "(function(){var t=document.getElementById('lgc-title');"
                    "return !!(t&&t.value&&t.value.indexOf('ZZ TEST')===0);})()")
            else:
                # main's door: title + FLATTENED text, no forum, no tags.
                # Read the stored body straight from WP rather than over HTTP: main's
                # API has no topic payload endpoint (that is this branch's addition),
                # and the harness would need a nonce it has no reason to mint here.
                raw = bv.sh("sudo -u looth-dev wp --path=/var/www/dev post get "
                            f"{TOPIC} --field=post_content 2>/dev/null")
                flat = await s.ev(FLATTEN_JS % json.dumps(raw))
                await s.ev("window.lgOpenComposer(%s)" % json.dumps({
                    "editTopicId": TOPIC, "tid": TOPIC, "fid": 3837,
                    "title": "ZZ TEST edit-post-parity (delete me)",
                    "bodyText": flat, "focus": False}))
                ok = await s.wait_for("!!document.getElementById('looth-comp-sheet')")
            if not ok:
                print(f"  {label}: sheet did not populate"); return False
            # Let the sheet fully settle: the identity header and the avatar arrive from
            # an async auth call, and the inline image has to actually fetch. Shooting
            # early produced a pair whose headers disagreed ("You" vs the display name)
            # for no reason other than timing — a difference Ian would reasonably read
            # as a change this branch made.
            await s.wait_for("(function(){var i=document.querySelector('#lgc-editor img');"
                             "return !i || i.complete;})()")
            await asyncio.sleep(3)
            shot = await s.send("Page.captureScreenshot", {"format": "png"})
            data = shot.get("result", {}).get("data")
            if not data:
                print(f"  {label}: no screenshot data"); return False
            open(out_png, "wb").write(base64.b64decode(data))
            print(f"  {label}: {out_png} ({os.path.getsize(out_png)//1024}KB)")
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
    b = await shoot(False, os.path.join(a.outdir, "before.png"), "BEFORE (main)")
    a_ = await shoot(True, os.path.join(a.outdir, "after.png"), "AFTER (branch)")
    sys.exit(0 if (b and a_) else 1)


if __name__ == "__main__":
    try:
        import websockets  # noqa: F401
    except ImportError:
        print("pip install websockets", file=sys.stderr); sys.exit(2)
    asyncio.run(main())
