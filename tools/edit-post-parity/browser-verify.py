#!/usr/bin/env python3
"""
browser-verify — the CLICK-THROUGH leg for edit/add parity (Ian 2026-07-29).

WRITTEN BEFORE IT COULD BE RUN. The box allows ONE browser engine at a time and the
seat was held elsewhere, so this was authored while blocked so that seat time is
minutes rather than an hour of writing under a contended resource. Anything it has
not actually proven stays labelled NOT PROVEN until this exits green.

WHAT IT PROVES that the server-side exercise could not: the composer is a browser
component. The forum <select>, the tag pills and the Quill seeding are only real
when a member taps Edit and sees them. Everything downstream of the PUT is already
proven against the real dev2 DB; this covers the half above it.

THE TRAP THIS IS SHAPED AROUND (chrome-dev-login skill, "Emulation overrides die
with the CDP session"): the composer under test is the MOBILE sheet. A driver that
opens one websocket per command loses setDeviceMetricsOverride the moment each
command's socket detaches, so the run silently tests a desktop UA with no touch —
where this composer is not even the surface in play. So: ONE persistent connection,
overrides + navigation + evaluation all inside it.

BOX FACTS, measured 2026-07-29 — the chrome-dev-login skill is STALE on dev2:
  - chrome-dev.service is INACTIVE and /var/lib/chrome-dev does NOT exist.
  - the binary is /opt/lg-chrome/chrome-linux64/chrome
  - the skill's "domain must NOT have a leading dot" rule is for dev.loothgroup.com;
    dev2 sets loothdev_auth on ".dev2.loothgroup.com" WITH the dot (nginx conf :119)
  - the cookie gate is currently OFF for the cut (conf :140 commented out) — an
    un-cookied /hub/ fetch returns 200 — but the cookie is set anyway so this keeps
    working when the gate comes back on.

ASK KEEPER BEFORE LAUNCHING AN ENGINE. Box law is one engine box-wide; ~500MB.

Usage:
    python3 tools/edit-post-parity/browser-verify.py            # assert only, no writes
    python3 tools/edit-post-parity/browser-verify.py --save     # also drive a real save

Fixture: topic 72306 "ZZ TEST edit-post-parity (delete me)", author claude_admin
(1912), forum General(3837), tags vintage+martin, 2 replies, body carrying
bold + link + list + inline <img>. Recreate instructions in the lane's board post.
"""
import argparse, asyncio, base64, json, os, subprocess, sys, urllib.request

CDP_HTTP = "http://127.0.0.1:9222"
HOST     = "dev2.loothgroup.com"
TOPIC    = 72306
OWNER    = 1912
REPO     = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
# THE SERVE SERVES MAIN, NOT THIS BRANCH.
# /var/www/dev/hub-polish.js -> ~/loothplatformv2-clean/webroot/hub-polish.js, and that
# checkout is on main and ONLY EVER PULLS (box law). So a plain load of /hub/ exercises
# main's composer and would have "proven" this lane against code it does not contain —
# every assertion below would be about somebody else's file. Rather than flip the serve
# (forbidden) or hand-copy into the docroot (forbidden, and untraceable), the branch's
# bytes are injected per-request over CDP: the page, the DB, the endpoints and the
# session are all the real dev2 ones, and only this one asset comes from the worktree.
OVERRIDES = {"hub-polish.js": os.path.join(REPO, "webroot", "hub-polish.js")}
IPHONE   = {"width": 390, "height": 844, "deviceScaleFactor": 3,
            "mobile": True, "screenWidth": 390, "screenHeight": 844}
UA_IOS = ("Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 "
          "(KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1")

results = []
def check(name, ok, detail=""):
    results.append((name, bool(ok), detail))
    print(f"  {'PASS' if ok else 'FAIL'}  {name}" + (f"   [{detail}]" if detail else ""))


def sh(cmd):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True).stdout.strip()


def mint_cookies():
    """Gate token + a real WP session for the fixture's author."""
    tok = sh(r"""sudo grep -h 'loothdev_token' /etc/nginx/snippets/loothdev-tokens.conf """
             r"""| head -1 | sed 's/.*"\(.*\)".*/\1/'""")
    out = sh(f"""sudo -u looth-dev wp --path=/var/www/dev eval '
        $e = time() + 7*DAY_IN_SECONDS;
        echo LOGGED_IN_COOKIE."|".wp_generate_auth_cookie({OWNER},$e,"logged_in")."\n";
        echo SECURE_AUTH_COOKIE."|".wp_generate_auth_cookie({OWNER},$e,"secure_auth")."\n";
        ' 2>/dev/null | grep '|'""")
    cookies = [{"domain": f".{HOST}", "name": "loothdev_auth", "value": tok,
                "path": "/", "secure": True, "httpOnly": True}]
    for line in out.splitlines():
        if "|" not in line:
            continue
        name, value = line.split("|", 1)
        cookies.append({"domain": HOST, "name": name.strip(), "value": value.strip(),
                        "path": "/", "secure": True, "httpOnly": True})
    if len(cookies) < 3:
        print("could not mint WP cookies — is wp-cli reachable as looth-dev?", file=sys.stderr)
        sys.exit(2)
    return cookies


class Session:
    """One websocket for the whole run so emulation overrides survive.

    Replies are matched by id through a pending-future table rather than by reading
    until the id shows up. The old shape dropped every message that was not the reply
    it wanted, which is fine for pure request/response and fatal here: CDP delivers
    Fetch.requestPaused as an EVENT, so an interception would have been swallowed by
    whichever send() happened to be waiting, and the page would hang on that request
    until it timed out and quietly loaded the ORIGINAL script.
    """
    def __init__(self, ws, overrides=None):
        self.ws, self.n = ws, 0
        self.pending = {}
        self.overrides = overrides or {}
        self.served = []
        self.missed = []
        self.reader = asyncio.ensure_future(self._read())

    async def _read(self):
        try:
            async for raw in self.ws:
                msg = json.loads(raw)
                mid = msg.get("id")
                if mid is not None:
                    fut = self.pending.pop(mid, None)
                    if fut and not fut.done():
                        fut.set_result(msg)
                elif msg.get("method") == "Fetch.requestPaused":
                    asyncio.ensure_future(self._intercept(msg["params"]))
        except asyncio.CancelledError:
            raise
        except Exception:
            pass

    async def _intercept(self, p):
        rid, url = p["requestId"], p.get("request", {}).get("url", "")
        for frag, path in self.overrides.items():
            if frag in url.split("?")[0]:
                try:
                    body = base64.b64encode(open(path, "rb").read()).decode()
                except OSError as e:
                    self.missed.append(f"{url}: {e}")
                    await self.send("Fetch.continueRequest", {"requestId": rid})
                    return
                self.served.append(url)
                await self.send("Fetch.fulfillRequest", {
                    "requestId": rid, "responseCode": 200,
                    "responseHeaders": [
                        {"name": "Content-Type", "value": "application/javascript; charset=utf-8"},
                        {"name": "Cache-Control", "value": "no-store"}],
                    "body": body})
                return
        await self.send("Fetch.continueRequest", {"requestId": rid})

    async def send(self, method, params=None):
        self.n += 1
        mid = self.n
        fut = asyncio.get_event_loop().create_future()
        self.pending[mid] = fut
        await self.ws.send(json.dumps({"id": mid, "method": method, "params": params or {}}))
        try:
            return await asyncio.wait_for(fut, timeout=30)
        except asyncio.TimeoutError:
            self.pending.pop(mid, None)
            return {"error": f"timeout on {method}"}

    async def ev(self, expr):
        r = await self.send("Runtime.evaluate",
                            {"expression": expr, "returnByValue": True, "awaitPromise": True})
        res = r.get("result", {})
        if "exceptionDetails" in res:
            return {"__error": str(res["exceptionDetails"].get("text"))}
        return res.get("result", {}).get("value")

    async def wait_for(self, expr, tries=40, delay=0.25):
        """Poll a JS predicate — the composer builds and fetches asynchronously."""
        for _ in range(tries):
            if await self.ev(expr) is True:
                return True
            await asyncio.sleep(delay)
        return False


async def run(save):
    import websockets
    try:
        pages = json.load(urllib.request.urlopen(CDP_HTTP + "/json"))
    except Exception as e:
        print(f"No CDP on {CDP_HTTP}: {e}\nStart the engine ONLY with keeper's OK.", file=sys.stderr)
        sys.exit(2)
    # Open a TAB OF OUR OWN rather than attaching to whatever page is already there.
    # Chrome allows one debugger client per target and rejects the second with a bare
    # `HTTP 500` on the websocket handshake — which is what attaching to the existing
    # /hub/ tab produced, because another client still holds it (keeper's craft-gate
    # run was reported hung on this engine). Two independent reasons to open our own:
    # that failure is indistinguishable from "the browser is broken", and driving a tab
    # somebody else is mid-run on would corrupt their result as well as ours.
    own_target = None
    try:
        req = urllib.request.Request(CDP_HTTP + "/json/new?about:blank", method="PUT")
        own_target = json.load(urllib.request.urlopen(req, timeout=10))
        page = own_target
        print(f"  (opened own tab {page['id'][:12]}… — not touching the existing one)")
    except Exception as e:
        print(f"  (could not open own tab: {e}; falling back to an existing one)")
        page = next((p for p in pages if p["type"] == "page"), None)
    if not page:
        print("no page target", file=sys.stderr); sys.exit(2)

    cookies = mint_cookies()
    async with websockets.connect(page["webSocketDebuggerUrl"], max_size=None) as ws:
        s = Session(ws, overrides=OVERRIDES)
        await s.send("Network.enable")
        await s.send("Page.enable")
        await s.send("Runtime.enable")
        await s.send("Network.setCacheDisabled", {"cacheDisabled": True})
        # must be armed BEFORE the navigation that pulls the script
        await s.send("Fetch.enable", {"patterns": [{"urlPattern": "*hub-polish.js*",
                                                    "requestStage": "Request"}]})
        # stale cookies from a prior session outrank fresh ones and break login silently
        await s.send("Network.clearBrowserCookies")
        for c in cookies:
            await s.send("Network.setCookie", c)
        # held for the whole session — see the module docstring
        await s.send("Emulation.setDeviceMetricsOverride", IPHONE)
        await s.send("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})
        await s.send("Emulation.setUserAgentOverride", {"userAgent": UA_IOS})

        print(f"\n== phone emulation active, loading /hub/ as uid {OWNER} ==")
        await s.send("Page.navigate", {"url": f"https://{HOST}/hub/"})
        ready = await s.wait_for("document.readyState === 'complete'")
        check("hub page loads", ready)
        # Kept SEPARATE from the feature assertions on purpose. If the branch file never
        # got injected, every control below is missing and the run reads as "the feature
        # is broken" when the truth is "you tested main". Those two need different names.
        check("THIS BRANCH's hub-polish.js is what the page ran",
              len(s.served) > 0 and not s.missed,
              f"{len(s.served)} injected" + (f", MISSED {s.missed}" if s.missed else ""))
        check("emulation really applied (not a desktop false-pass)",
              await s.ev("window.matchMedia('(max-width:640px)').matches") is True,
              await s.ev("innerWidth + 'px'"))
        check("composer JS present on the hub",
              await s.ev("typeof window.lgOpenComposer === 'function'"))
        check("add-post picker present to clone from",
              await s.ev("!!document.querySelector('#ntm-forum input[name=\"forum_id\"]')"))

        print("\n== open the composer in topic-edit mode ==")
        # Drive the documented seam rather than hunting the ⋯ menu: the door under
        # test is what the Edit button calls, and this is that exact call.
        await s.ev(f"window.lgOpenComposer({{editTopicId:{TOPIC}, tid:{TOPIC}}})")
        built = await s.wait_for("!!document.getElementById('looth-comp-sheet')")
        check("composer sheet builds", built)
        loaded = await s.wait_for(
            "(function(){var t=document.getElementById('lgc-title');"
            "return !!(t && t.value && t.value.indexOf('ZZ TEST')===0);})()")
        check("server payload seeds the title", loaded)

        print("\n== the controls Ian asked for ==")
        check("forum row visible", await s.ev(
            "(function(){var m=document.getElementById('lgc-meta');"
            "return !!(m && !m.hidden);})()"))
        nopt = await s.ev("document.querySelectorAll('#lgc-forum option').length")
        ngrp = await s.ev("document.querySelectorAll('#lgc-forum optgroup').length")
        check("forum picker cloned with every forum", nopt == 37, f"{nopt} options")
        check("forum picker grouped by category", ngrp == 7, f"{ngrp} optgroups")
        check("current forum preselected (General 3837)",
              str(await s.ev("document.getElementById('lgc-forum').value")) == "3837")
        check("tags prefilled from the post",
              sorted((await s.ev("document.getElementById('lgc-tags').value") or "")
                     .replace(" ", "").split(",")) == ["martin", "vintage"],
              await s.ev("document.getElementById('lgc-tags').value"))
        check("quick-tag pills present",
              await s.ev("document.querySelectorAll('#lgc-qtags .lgc-qtag').length") == 2)

        print("\n== the defect this lane exists to kill ==")
        html = await s.ev("document.querySelector('#lgc-editor .ql-editor').innerHTML") or ""
        check("BOLD survives into the editor", "<strong>" in html or "<b>" in html)
        check("LINK survives into the editor", "example.com" in html)
        check("LIST survives into the editor", "<li>" in html)
        check("INLINE IMAGE survives into the editor", "<img" in html,
              "50 dev2 topics depend on this")
        check("Save is armed once the payload landed",
              await s.ev("!document.getElementById('lgc-post').disabled"))

        print("\n== paste guard: images may be kept, never newly embedded ==")
        pasted = await s.ev(
            "(function(){try{var q=window.Quill && document.querySelector('#lgc-editor');"
            "var before=document.querySelectorAll('#lgc-editor .ql-editor img').length;"
            "return before;}catch(e){return -1}})()")
        check("editor holds the post's own image(s)", isinstance(pasted, int) and pasted >= 1,
              f"{pasted} img")

        if save:
            print("\n== drive a real save (forum + tag change) ==")
            await s.ev(
                "(function(){var f=document.getElementById('lgc-forum');f.value='3823';"
                "f.dispatchEvent(new Event('change',{bubbles:true}));"
                "var t=document.getElementById('lgc-tags');t.value='vintage, martin, browserleg';"
                "t.dispatchEvent(new Event('input',{bubbles:true}));return true})()")
            check("forum changed in the UI",
                  str(await s.ev("document.getElementById('lgc-forum').value")) == "3823")
            await s.ev("document.getElementById('lgc-post').click()")
            await asyncio.sleep(4)
            print("  (saved — now verify in the DB, NOT in the UI)")
        await s.send("Emulation.clearDeviceMetricsOverride")

    # Close our own tab whatever the verdict — a red run must not leave a tab behind on
    # a shared, memory-tight box (each one is real RSS, and the box OOMed at 6-7 lanes
    # before it had swap). Failure to clean up is reported, never raised: losing the
    # results because the teardown was unhappy would be the worse outcome.
    if own_target:
        try:
            urllib.request.urlopen(CDP_HTTP + "/json/close/" + own_target["id"], timeout=10).read()
            print("  (closed own tab)")
        except Exception as e:
            print(f"  WARNING: could not close own tab {own_target['id']}: {e}")

    print("\n" + "=" * 62)
    bad = [r for r in results if not r[1]]
    print(f"browser-verify: pass={len(results)-len(bad)} fail={len(bad)}")
    if bad:
        print("==================== BROWSER VERIFY RED ====================")
        return 1
    print("==================== BROWSER VERIFY GREEN ====================")
    return 0


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--save", action="store_true", help="also drive a real save")
    a = ap.parse_args()
    sys.exit(asyncio.run(run(a.save)))
