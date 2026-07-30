#!/usr/bin/env python3
"""wizard-probe.py — establish what the DISCUSSION wizard actually does today.

Ian corrected the target 2026-07-30: editing a discussion must open the 4-step
"New post" wizard (Where / Write / Photos / Review) pre-filled, landing on Write,
with the other steps reachable. Before building anything, measure what already
exists — `ntmOpenForEdit` (window.lgNtmEditTopic) is already in forums.js and the
wizard is explicitly DESKTOP-ONLY (buildNtmWizard returns null below 641px).

Facts this answers, each measured rather than read:
  A  desktop: does lgNtmEditTopic open the 4-step wizard, pre-filled, on which step?
  B  mobile:  what does the same call produce at 390px?
  C  reply edit: does it already activate the reply composer (Ian believes it does)?

No injection: forums.js is untouched by this branch, so this measures MAIN — which
is exactly what "what exists today" means. Runs against the real dev2 serve.
"""
import asyncio, json, sys, os, urllib.request, importlib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
bv = importlib.import_module("browser-verify")

DESKTOP = {"width": 1280, "height": 900, "deviceScaleFactor": 1, "mobile": False,
           "screenWidth": 1280, "screenHeight": 900}
UA_DESK = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
           "Chrome/151.0.0.0 Safari/537.36")

# What the wizard looks like from the outside, so the probe reports structure, not vibes.
PROBE_JS = """
(function(){
  var form = document.getElementById('ntm-form');
  var ov   = document.getElementById('ntm-overlay');
  var rail = document.querySelectorAll('.lgw-rail__step');
  var panes= document.querySelectorAll('.lgw-step');
  var cur  = document.querySelector('.lgw-step:not([hidden])');
  var hd   = document.getElementById('ntm-heading');
  var lbl  = [];
  rail.forEach(function(d){ lbl.push((d.textContent||'').trim().replace(/\\s+/g,' ')); });
  return {
    overlayOpen : !!(ov && !ov.hidden),
    heading     : hd ? (hd.textContent||'').trim() : null,
    railSteps   : rail.length,
    railLabels  : lbl,
    panes       : panes.length,
    currentStep : cur ? (cur.dataset.step || null) : null,
    titleVal    : (document.getElementById('ntm-title-in')||{}).value || '',
    forumVal    : (document.getElementById('ntm-forum')||{}).value || '',
    tagsVal     : (document.getElementById('ntm-tags')||{}).value || '',
    editorHtml  : (function(){ var q=document.querySelector('#ntm-editor .ql-editor');
                               return q ? q.innerHTML.slice(0,160) : null; })(),
    hasForm     : !!form
  };
})()
"""


async def probe(metrics, ua, label):
    import websockets
    req = urllib.request.Request(bv.CDP_HTTP + "/json/new?about:blank", method="PUT")
    tab = json.load(urllib.request.urlopen(req, timeout=10))
    try:
        async with websockets.connect(tab["webSocketDebuggerUrl"], max_size=None) as ws:
            s = bv.Session(ws)
            for m in ("Network.enable", "Page.enable", "Runtime.enable"):
                await s.send(m)
            await s.send("Network.clearBrowserCookies")
            for c in bv.mint_cookies():
                await s.send("Network.setCookie", c)
            await s.send("Emulation.setDeviceMetricsOverride", metrics)
            await s.send("Emulation.setUserAgentOverride", {"userAgent": ua})
            await s.send("Page.navigate", {"url": f"https://{bv.HOST}/hub/"})
            await s.wait_for("document.readyState === 'complete'")
            width = await s.ev("innerWidth")
            print(f"\n===== {label} (innerWidth={width}) =====")
            print(f"  lgNtmEditTopic present : {await s.ev('typeof window.lgNtmEditTopic')}")
            print(f"  lgFrmEditReply present : {await s.ev('typeof window.lgFrmEditReply')}")
            print(f"  lgFrmEditTopic present : {await s.ev('typeof window.lgFrmEditTopic')}")
            print(f"  openComposerSheet      : {await s.ev('typeof window.lgOpenComposer')}")

            body = ("<p>Original body with <strong>bold</strong>, a "
                    "<a href='https://example.com/x'>link</a> and a list:</p>"
                    "<ul><li>one</li><li>two</li></ul>")
            await s.ev("window.lgNtmEditTopic && window.lgNtmEditTopic(%d,%d,%s,%s)"
                       % (bv.TOPIC, 3837,
                          json.dumps("ZZ TEST edit-post-parity (delete me)"),
                          json.dumps(body)))
            await asyncio.sleep(2.5)
            info = await s.ev(PROBE_JS)
            for k in ("overlayOpen", "heading", "railSteps", "railLabels", "panes",
                      "currentStep", "titleVal", "forumVal", "tagsVal", "editorHtml"):
                print(f"    {k:12}: {info.get(k) if isinstance(info, dict) else info}")
            return info
    finally:
        try:
            urllib.request.urlopen(bv.CDP_HTTP + "/json/close/" + tab["id"], timeout=10).read()
        except Exception as e:
            print(f"  WARNING: could not close tab: {e}")


async def main():
    d = await probe(DESKTOP, UA_DESK, "A/C  DESKTOP 1280")
    m = await probe(bv.IPHONE, bv.UA_IOS, "B    MOBILE 390")
    print("\n===== VERDICT =====")
    dr = d.get("railSteps") if isinstance(d, dict) else 0
    mr = m.get("railSteps") if isinstance(m, dict) else 0
    print(f"  desktop wizard steps: {dr}   mobile wizard steps: {mr}")
    print("  -> wizard is desktop-only" if dr and not mr else "  -> check output above")


if __name__ == "__main__":
    try:
        import websockets  # noqa: F401
    except ImportError:
        print("pip install websockets", file=sys.stderr); sys.exit(2)
    asyncio.run(main())
