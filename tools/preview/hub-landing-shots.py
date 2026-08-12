#!/usr/bin/env python3
"""hub-landing-shots.py — behavioural verification + screenshots for the
server-rendered hub landing (/hub/<forum>/<topic>/).

Ian decides from pictures, so this produces them. It also asserts the three
things a picture CANNOT show — that the modal is really open, that Back returns
to the feed instead of leaving the site, and that Back does not reload the page —
because "it looked right in the screenshot" is how a dead end ships.

ONE PERSISTENT CDP SESSION, and that is not a style choice. tools/cdp-drive.py
opens a NEW websocket per command; device-metric overrides are scoped to the
session that set them, so a per-command driver silently drops the phone
emulation and every "mobile" shot is really a desktop shot that PASSES. Same
session here from setup to screenshot.

Three more traps this box has already paid for, all handled below:
  · the shared chrome profile persists lg-set-theme, so a "light" run renders
    DARK unless the theme is written explicitly — and a localStorage write on
    about:blank is a no-op, so it must happen AFTER a navigation to the origin;
  · Network.setCookie ADDS rather than replaces, so a stale WP session cookie in
    the shared profile makes the run execute as a DIFFERENT member. Clear first.
    The dev gate cookie is DOTTED (.dev2.loothgroup.com); WP cookies are
    host-only, which is why they are never mixed here;
  · the public host is Cloudflare-proxied and challenges CDP into a 403 that a
    naive check happily screenshots. chrome-dev carries
    --host-resolver-rules=MAP dev2.loothgroup.com <lan-ip>, and the assertions
    below fail loudly on a challenge page rather than photographing it.

Usage:  python3 tools/preview/hub-landing-shots.py <out-dir>
"""

import base64
import json
import os
import pathlib
import subprocess
import sys
import time
import urllib.request

import websocket  # websocket-client — synchronous, one connection, kept open

CDP = "http://127.0.0.1:9222"
HOST = "https://dev2.loothgroup.com"
PREVIEW = "/hub/preview-seo"
LIVE = "/hub"
SLUG = os.environ.get("LG_TL_SLUG",
                      "acoustic/old-yamaha-classical-guitar-top-crack-repair-top-crack-repair-repair")

# LG_TL_WPCOOKIE="<name>=<value>" adds a real WordPress session, so the run
# captures the MEMBER view (Reply composer, follow controls, owner Edit/Delete)
# alongside the anonymous one. Mint it with:
#
#   sudo -u looth-dev LG_SHOT_UID=<id> wp --path=/var/www/dev \
#        --skip-plugins --skip-themes eval-file tools/preview/mint-wp-session.php
#
# wp_generate_auth_cookie ALONE is not enough — WP validates the session token
# against user meta, so it has to be registered through WP_Session_Tokens or
# every request still reads as logged out.
#
# ⚠️ HOST-ONLY, no leading dot. The dev gate cookie is DOTTED and the WP cookie is
# not; setting the WP one on the dotted domain leaves the profile holding TWO
# cookies of the same name, and the run then executes as whichever the browser
# sends — a different member than the one under test, with an absence assertion
# going green for the wrong reason.
WP_COOKIE = os.environ.get("LG_TL_WPCOOKIE", "")

DESKTOP = {"width": 1440, "height": 900, "mobile": False, "deviceScaleFactor": 1}
PHONE = {"width": 390, "height": 844, "mobile": True, "deviceScaleFactor": 2}


class Session:
    """One websocket, one target, for the whole run."""

    def __init__(self):
        # OUR OWN TAB, not whatever is on screen: this browser is shared with the
        # other lanes and driving someone else's tab would wreck their run as
        # surely as starting a second browser would. Closed again in finish().
        # /json/new takes PUT on Chrome 151 — GET is refused.
        req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
        target = json.load(urllib.request.urlopen(req))
        self.target_id = target["id"]
        self.ws = websocket.create_connection(
            target["webSocketDebuggerUrl"], max_size=None, timeout=60,
            # Chrome 151 rejects a WebSocket whose Origin it did not whitelist
            # (--remote-allow-origins). websocket-client sends one derived from
            # the URL; suppressing it is what the DevTools frontend effectively
            # does, and it is why this must not be "fixed" by relaunching the
            # shared browser with a looser flag.
            suppress_origin=True,
        )
        self._id = 0

    def finish(self):
        try:
            self.ws.close()
        except Exception:                                      # noqa: BLE001
            pass
        try:
            urllib.request.urlopen(CDP + "/json/close/" + self.target_id).read()
        except Exception:                                      # noqa: BLE001
            pass

    def call(self, method, **params):
        self._id += 1
        self.ws.send(json.dumps({"id": self._id, "method": method, "params": params}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get("id") == self._id:
                if "error" in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get("result", {})

    def js(self, expr):
        r = self.call("Runtime.evaluate", expression=expr,
                      returnByValue=True, awaitPromise=True)
        if r.get("exceptionDetails"):
            raise RuntimeError(f"JS threw: {r['exceptionDetails'].get('text')} :: {expr[:80]}")
        return r.get("result", {}).get("value")

    def goto(self, url, settle=2.4):
        self.call("Page.navigate", url=url)
        # Poll readyState rather than sleeping a fixed amount, then still give
        # the deferred scripts a beat: forums.js is `defer`, and hub-polish.js is
        # injected by pwa.js AFTER that, which is the whole reason §4f polls.
        for _ in range(80):
            time.sleep(0.15)
            try:
                if self.js("document.readyState") == "complete":
                    break
            except Exception:                                  # mid-navigation
                continue
        time.sleep(settle)

    def shot(self, path):
        r = self.call("Page.captureScreenshot", format="png", captureBeyondViewport=False)
        pathlib.Path(path).write_bytes(base64.b64decode(r["data"]))
        return os.path.getsize(path)


def gate_token():
    out = subprocess.run(["bash", os.path.join(
        os.path.dirname(os.path.abspath(__file__)), "..", "gates", "gate-env.sh")],
        capture_output=True, text=True, timeout=30)
    for line in out.stdout.splitlines():
        if line.startswith("LG_GATE_TOKEN="):
            return line.split("=", 1)[1]
    raise SystemExit("CANNOT RUN  no gate token from gate-env.sh")


def main():
    outdir = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else ".")
    outdir.mkdir(parents=True, exist_ok=True)
    tok = gate_token()

    s = Session()
    s.call("Page.enable")
    s.call("Runtime.enable")
    s.call("Network.enable")

    # Clear FIRST — see the header note on setCookie adding rather than replacing.
    s.call("Network.clearBrowserCookies")
    s.call("Network.setCookie", name="loothdev_auth", value=tok,
           domain=".dev2.loothgroup.com", path="/", secure=True)
    viewer = "anon"
    if WP_COOKIE and "=" in WP_COOKIE:
        cname, cval = WP_COOKIE.split("=", 1)
        s.call("Network.setCookie", name=cname, value=cval,
               domain="dev2.loothgroup.com", path="/", secure=True)   # host-only
        viewer = "member"
    print(f"  viewer      {viewer}")

    results = []
    failures = []

    def check(label, ok, detail=""):
        results.append((label, ok, detail))
        print(f"  {'ok  ' if ok else 'FAIL'}  {label}" + (f"  — {detail}" if detail else ""))
        if not ok:
            failures.append(f"{label}: {detail}")

    for surface, metrics in (("desktop", DESKTOP), ("mobile", PHONE)):
        s.call("Emulation.setDeviceMetricsOverride", **metrics)
        s.call("Emulation.setTouchEmulationEnabled", enabled=metrics["mobile"])

        for theme in ("light", "dark"):
            # RE-ESTABLISH THE VIEWER FROM SCRATCH EVERY ITERATION — clear, then
            # set exactly the cookies this viewer should have. Setting only the
            # member cookie was not enough, and the failure ran BOTH ways on this
            # shared browser:
            #   · a member iteration rendered as ANON (its session wiped by some
            #     other lane's Network.clearBrowserCookies);
            #   · an anon iteration rendered as a MEMBER (a WP session appearing
            #     mid-run, so the server sent data-lg-can-post="1" and the sheet
            #     correctly built a composer).
            # The second one is the dangerous shape: it looks exactly like "anon
            # gets a composer", which is a defect worth stopping a release for.
            # Pure curl with no session says data-lg-can-post="0" and ships
            # "Sign in to reply", so the product was never wrong — the harness was
            # inheriting an identity instead of declaring one.
            s.call("Network.clearBrowserCookies")
            s.call("Network.setCookie", name="loothdev_auth", value=tok,
                   domain=".dev2.loothgroup.com", path="/", secure=True)
            if WP_COOKIE and "=" in WP_COOKIE:
                cn, cv = WP_COOKIE.split("=", 1)
                s.call("Network.setCookie", name=cn, value=cv,
                       domain="dev2.loothgroup.com", path="/", secure=True)   # host-only
            # Navigate to the origin BEFORE writing localStorage — a write on
            # about:blank lands nowhere and the shared profile's stored theme
            # then wins, which is how "light" shots come out dark.
            s.goto(HOST + PREVIEW + "/", settle=0.6)
            s.js(f"localStorage.setItem('lg-set-theme','{theme}');"
                 f"localStorage.setItem('lg-set-boot',JSON.stringify("
                 f"{{theme:'{theme}',dark:{'true' if theme=='dark' else 'false'}}}));1")

            s.goto(f"{HOST}{PREVIEW}/{SLUG}/", settle=4.5)

            title = s.js("document.title") or ""
            if "Just a moment" in title or "challenge" in title.lower():
                check(f"{surface}/{theme} not a Cloudflare challenge", False, title)
                continue

            state = s.js("""(function(){
              var m=document.getElementById('lg-dmodal');
              var sh=document.getElementById('looth-rep-sheet');
              var vis=function(el){ if(!el) return false;
                var c=getComputedStyle(el);
                return c.display!=='none'&&c.visibility!=='hidden'&&el.getBoundingClientRect().height>0; };
              var body=m&&m.querySelector('.lg-dmodal__body');
              var sheetBody=sh&&sh.querySelector('#lrs-op');
              return {
                title: document.title,
                modalInDom: !!m, modalVisible: vis(m),
                sheetInDom: !!sh, sheetOpen: !!(sh&&sh.classList.contains('is-open')),
                modalText: body?(body.innerText||'').trim().length:0,
                sheetText: sheetBody?(sheetBody.innerText||'').trim().length:0,
                replies: document.querySelectorAll('#lg-dmodal .reply-stub, #looth-rep-sheet .reply-stub').length,
                url: location.pathname+location.search,
                histLen: history.length,
                theme: document.documentElement.getAttribute('data-lguser-theme')||''
              };})()""")

            # The reading surface differs by viewport BY DESIGN: ≥641 the modal,
            # ≤640 the sheet (forums.css hides the modal there). Assert the one
            # that is supposed to be showing, not "something is showing".
            if surface == "desktop":
                check("desktop: modal is open and visible", state["modalVisible"],
                      json.dumps({k: state[k] for k in ("modalInDom", "modalVisible")}))
                check("desktop: OP text is on screen", state["modalText"] > 40,
                      f"{state['modalText']} chars")
            else:
                showing = state["sheetOpen"] or state["modalVisible"]
                check("mobile: a reading surface is open", showing,
                      json.dumps({k: state[k] for k in
                                  ("sheetInDom", "sheetOpen", "modalVisible")}))
                check("mobile: OP text is on screen",
                      max(state["sheetText"], state["modalText"]) > 40,
                      f"sheet={state['sheetText']} modal={state['modalText']} chars")

            check(f"{surface}/{theme}: replies rendered", state["replies"] > 0,
                  f"{state['replies']} stubs")
            check(f"{surface}/{theme}: history seated on ?topic=",
                  "topic=" in state["url"], state["url"])
            # 'default' IS the light theme's id (webroot/app-settings.js:32 —
            # {id:'default', name:'Light'}). Asserting 'light' reported a
            # correctly-themed page as unthemed: a probe bug in a finding's
            # clothes. Assert what the app actually stamps.
            want_attr = "dark" if theme == "dark" else "default"
            check(f"{surface}/{theme}: theme applied", state["theme"] == want_attr,
                  f"want {want_attr!r}, got {state['theme']!r}")

            composer = s.js("""(function(){
              var root=document.getElementById('looth-rep-sheet')||document.getElementById('lg-dmodal');
              if(!root) return null;
              return { reply: !!root.querySelector('[data-frm-open], #lrs-replybtn, .lrs-comp__input'),
                       signin: !!root.querySelector('.lg-dmodal__signin, .lrs-signin'),
                       // SERVER's verdict on this request. lgCanPost() reads this
                       // synchronously, so a mismatch here is not a client race —
                       // it means the request arrived without a valid session.
                       canPost: document.body.getAttribute('data-lg-can-post') };})()""")
            if composer:
                want_reply = viewer == "member"
                check(f"{surface}/{theme}/{viewer}: composer matches the viewer",
                      composer["reply"] == want_reply and composer["signin"] != want_reply,
                      json.dumps(composer))

            png = outdir / f"landing-{surface}-{theme}-{viewer}.png"
            check(f"{surface}/{theme}: screenshot", s.shot(png) > 20000, str(png))

            # ── BACK must return to the FEED, not off-site, and must not reload.
            # The marker is the reload detector: if the document were re-fetched,
            # __lgProbe would be gone. A dead end and a reload loop both LOOK
            # fine in a screenshot, which is why they are asserted here.
            s.js("window.__lgProbe='alive';1")
            s.js("history.back();1")
            time.sleep(1.6)
            after = s.js("""(function(){
              var m=document.getElementById('lg-dmodal');
              var sh=document.getElementById('looth-rep-sheet');
              var vis=function(el){ if(!el) return false; var c=getComputedStyle(el);
                return c.display!=='none'&&c.visibility!=='hidden'&&el.getBoundingClientRect().height>0; };
              return { url: location.pathname+location.search,
                       probe: window.__lgProbe||null,
                       modalVisible: vis(m),
                       sheetOpen: !!(sh&&sh.classList.contains('is-open')),
                       feedCards: document.querySelectorAll('.feed-card').length,
                       host: location.host };})()""")
            check(f"{surface}/{theme}: Back lands on the feed, not off-site",
                  after["url"].rstrip("/").endswith(PREVIEW.rstrip("/"))
                  and "topic=" not in after["url"], after["url"])
            check(f"{surface}/{theme}: Back did NOT reload the page",
                  after["probe"] == "alive",
                  "document was re-fetched" if after["probe"] != "alive" else "")
            check(f"{surface}/{theme}: reading surface closed on Back",
                  not after["modalVisible"] and not after["sheetOpen"],
                  json.dumps({k: after[k] for k in ("modalVisible", "sheetOpen")}))
            check(f"{surface}/{theme}: the hub feed is underneath",
                  after["feedCards"] > 0, f"{after['feedCards']} cards")
            s.shot(outdir / f"after-back-{surface}-{theme}-{viewer}.png")

    # ── The BEFORE picture, for the side-by-side Ian actually compares ─────────
    for surface, metrics in (("desktop", DESKTOP), ("mobile", PHONE)):
        s.call("Emulation.setDeviceMetricsOverride", **metrics)
        s.call("Emulation.setTouchEmulationEnabled", enabled=metrics["mobile"])
        for theme in ("light", "dark"):
            s.goto(HOST + LIVE + "/", settle=0.6)
            s.js(f"localStorage.setItem('lg-set-theme','{theme}');"
                 f"localStorage.setItem('lg-set-boot',JSON.stringify("
                 f"{{theme:'{theme}',dark:{'true' if theme=='dark' else 'false'}}}));1")
            s.goto(f"{HOST}{LIVE}/{SLUG}/", settle=3.0)
            s.shot(outdir / f"before-legacy-{surface}-{theme}-{viewer}.png")
            print(f"  ok    before/legacy {surface}/{theme} from {LIVE}/{SLUG}/")

    s.finish()

    print()
    if failures:
        print(f"RED  {len(failures)} behavioural failure(s):")
        for f in failures:
            print(f"  - {f}")
        return 1
    print(f"GREEN  {len(results)} checks passed; shots in {outdir}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
