#!/usr/bin/env python3
"""
sw-no-offline-shell-gate — while the origin is UP, a navigation through the service
worker must render THE REAL PAGE and never the offline shell.

WHY THIS GATE EXISTS (CRAFT-STANDARD: bitten three times, so it is encoded before the
next fix). Ian, 2026-06-25 / 2026-08-09 / 2026-08-11: blank spin, then the
"You're offline" shell, on a URL that answers 200 server-side.

This is the companion to sw-fetch-bounded-gate, and the split is deliberate:

  sw-fetch-bounded-gate   node, stubbed worker scope. Asks "does the handler SETTLE when
                          the network never answers?" — a hung fetch is a thing a browser
                          cannot stage, so it needs a stub.
  THIS gate               a REAL browser, a REAL registered service worker, a REAL page
                          over the REAL origin. Asks the question a stub cannot ask:
                          "with the server plainly reachable, does the worker put the
                          real page on screen?" A stub cannot answer that, because a stub
                          IS the thing that decides what the network returns.

⚠️ IT ASSERTS THE PAGE, NOT THE PLUMBING. The offline shell carries the string
"You're offline" and an #lo-retry button; the real page carries its own <title> and a
body of content. So the check is: the worker is CONTROLLING the page (else we proved
nothing about the worker), the shell markers are ABSENT, and the real content is PRESENT.
Both halves matter — "shell absent" alone passes on a blank page, which is the other
half of what Ian saw.

⚠️ AND IT MUST NOT BE POINTED THROUGH real-origin-proxy.py. That forwards to
127.0.0.1:443, and the dev gate exempts loopback outright
(geo $loothdev_src_local in /etc/nginx/conf.d/loothdev-auth.conf), so a cookie-less
request answers 200 through the proxy and any gate-related assertion passes vacuously.
chrome-dev carries --host-resolver-rules=MAP dev2.loothgroup.com 172.31.78.94, so we
drive the real vhost directly and Cloudflare is never in the path.

⚠️ IT AUDITS WHATEVER NGINX SERVES, WHICH IS THE SERVING CHECKOUT (main) — NOT your
branch. /sw.js is fetched from the origin, and the origin symlinks into
~/loothplatformv2-clean, so from a lane worktree this gate is a statement about main's
worker. That is the right DEFAULT for a regression tripwire (it guards the thing real
users get), but it means a green here is NOT evidence about an unmerged fix. To exercise
a branch's worker, swap /sw.js and /pwa.js through
tools/exercise-harness/endpoint-swap-proxy.py --no-route-strip and point --url at the
proxy. Same family as the "a lane verifying on dev2 is usually testing MAIN" trap.

RUNS AS A REAL USER, per the standing rule: it mints the dev-gate cookie and drives an
ordinary navigation in a throwaway BrowserContext, so it never touches the shared
profile's workers or cookies (a leaked registration there would silently change every
later run on this box).

Run:   python3 tools/gates/sw-no-offline-shell-gate.py [--url PATH] [--prove]
       --prove is the RED-FIRST: it registers a deliberately BROKEN worker that always
       serves the shell, and asserts this gate CATCHES it. A gate for a thrice-seen
       defect that has never been shown to fail is decoration.
Needs: chrome-dev on 127.0.0.1:9222 and the dev-gate token. ONE browser at a time — the
       box is 2-core, so this gate never opens contexts in parallel.

Exit:  0 green, 1 RED (real findings), 2 CANNOT RUN (no verdict).
"""
import argparse
import asyncio
import json
import subprocess
import sys
import urllib.request

import websockets

CDP = "http://127.0.0.1:9222"
ORIGIN = "https://dev2.loothgroup.com"
DEFAULT_PATH = "/hub/touring-tech/test-3/"     # the page Ian was denied
SHELL_MARKERS = ["You're offline", "You’re offline", "lo-retry"]

passes = failures = 0
notes = []


def log(*a):
    print(" ".join(str(x) for x in a), flush=True)


def check(label, got, want):
    global passes, failures
    ok = got == want
    if ok:
        passes += 1
    else:
        failures += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + ("" if ok else f"   got={got!r} want={want!r}"))
    return ok


def cannot_run(why):
    log(f"  CANNOT RUN — {why}")
    log("  (exit 2: no verdict. This is NOT a pass and NOT a finding.)")
    sys.exit(2)


def http(p):
    return json.load(urllib.request.urlopen(CDP + p, timeout=8))


def gate_token():
    r = subprocess.run(
        ["bash", "-lc", '. "$(git rev-parse --show-toplevel)"/tools/gates/lib/gate-token.sh && gate_token'],
        capture_output=True, text=True)
    return r.stdout.strip()


class Sess:
    def __init__(self, ws):
        self.ws, self.n = ws, 0

    async def send(self, m, p=None, sid=None):
        self.n += 1
        i = self.n
        msg = {"id": i, "method": m, "params": p or {}}
        if sid:
            msg["sessionId"] = sid
        await self.ws.send(json.dumps(msg))
        while True:
            r = json.loads(await asyncio.wait_for(self.ws.recv(), timeout=60))
            if r.get("id") == i:
                if "error" in r:
                    raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})

    async def ev(self, expr, sid=None):
        r = await self.send("Runtime.evaluate",
                            {"expression": expr, "returnByValue": True,
                             "awaitPromise": True}, sid)
        if r.get("exceptionDetails"):
            return {"__throw": str(r["exceptionDetails"].get("text"))}
        return r["result"].get("value")


# The RED-FIRST fixture lives under /footer-mockups/ (dev-gated: 200 with the cookie,
# 403 without — verified). A service worker cannot claim a scope ABOVE its own path, so a
# broken worker served from that directory can only ever control that directory. It can
# never touch /hub/ or anything a member sees, which is what makes staging it safe.
PROVE_DIR = "/footer-mockups/pwa-sw-3.10/redfirst/"
PROVE_PAGE = PROVE_DIR + "page.html"
PROVE_SW = PROVE_DIR + "broken-sw.js"


async def run_case(path, prove):
    """One navigation through one worker. Returns the measured facts."""
    out = {}
    tok = gate_token()
    if not tok:
        cannot_run("could not read the dev-gate token")

    bws = http("/json/version")["webSocketDebuggerUrl"]
    async with websockets.connect(bws, max_size=None, open_timeout=20) as ws:
        s = Sess(ws)
        ctx = (await s.send("Target.createBrowserContext"))["browserContextId"]
        try:
            tgt = (await s.send("Target.createTarget",
                                {"url": "about:blank", "browserContextId": ctx}))["targetId"]
            sid = (await s.send("Target.attachToTarget",
                                {"targetId": tgt, "flatten": True}))["sessionId"]
            await s.send("Page.enable", {}, sid)
            await s.send("Runtime.enable", {}, sid)
            await s.send("Network.enable", {}, sid)
            await s.send("Network.clearBrowserCookies", {}, sid)
            # LEADING DOT: the host-only form does not come back on every subresource.
            await s.send("Network.setCookies", {"cookies": [{
                "name": "loothdev_auth", "value": tok,
                "domain": ".dev2.loothgroup.com", "path": "/", "secure": True}]}, sid)

            # Land once so the origin has a document, then register the worker. In
            # --prove mode land INSIDE the fixture directory, because a worker cannot be
            # registered for a scope above its own path.
            await s.send("Page.navigate", {"url": ORIGIN + (PROVE_PAGE if prove else path)}, sid)
            for _ in range(80):
                await asyncio.sleep(0.25)
                if await s.ev("document.readyState==='complete'", sid):
                    break

            out["first_load_status_ok"] = await s.ev(
                "!!(document.title && document.body && document.body.innerText.length > 200)", sid)

            if prove:
                # Register the BROKEN worker at the fixture's own scope. Deliberately not
                # a cache hijack of the real worker: with the origin UP the shipped
                # handler never consults the cache, so a hijack would leave this gate
                # GREEN and "prove" nothing — a red-first that cannot go red is worse
                # than none.
                reg = await s.ev(f"""
                    navigator.serviceWorker.register('{PROVE_SW}', {{scope:'{PROVE_DIR}'}})
                      .then(() => navigator.serviceWorker.ready)
                      .then(r => 'ready(BROKEN): ' + ((r.active && r.active.scriptURL) || '?'))
                      .catch(e => 'ERR ' + e.message)
                """, sid)
                out["prove_setup"] = reg
            else:
                reg = await s.ev("""
                    navigator.serviceWorker.register('/sw.js', {scope:'/'})
                      .then(() => navigator.serviceWorker.ready)
                      .then(r => 'ready: ' + ((r.active && r.active.scriptURL) || '?'))
                      .catch(e => 'ERR ' + e.message)
                """, sid)
                out["registration"] = reg

            # Wait for the worker to CONTROL this client, else we prove nothing about it.
            controller = None
            for _ in range(40):
                await asyncio.sleep(0.5)
                controller = await s.ev(
                    "navigator.serviceWorker.controller && navigator.serviceWorker.controller.scriptURL", sid)
                if controller:
                    break
            out["controller"] = controller

            # THE MEASUREMENT: a real navigation, mediated by the worker. In --prove
            # mode that is the fixture page (the broken worker's scope); otherwise it is
            # the real path under the real worker.
            measure_path = PROVE_PAGE if prove else path
            out["measured_path"] = measure_path
            await s.send("Page.navigate", {"url": ORIGIN + measure_path}, sid)
            for _ in range(80):
                await asyncio.sleep(0.25)
                if await s.ev("document.readyState==='complete'", sid):
                    break
            await asyncio.sleep(1.0)

            seen = await s.ev("""(() => {
                const t = document.title || '';
                const b = (document.body && document.body.innerText) || '';
                return JSON.stringify({title: t, len: b.length,
                    head: b.replace(/\\s+/g,' ').trim().slice(0, 90),
                    hasRetry: !!document.getElementById('lo-retry')});
            })()""", sid)
            out["page"] = json.loads(seen) if isinstance(seen, str) else {"raw": seen}
            out["controlled_at_measure"] = await s.ev(
                "!!navigator.serviceWorker.controller", sid)
            return out
        finally:
            try:
                await s.send("Target.disposeBrowserContext", {"browserContextId": ctx})
            except Exception:
                pass


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default=DEFAULT_PATH)
    ap.add_argument("--prove", action="store_true")
    a = ap.parse_args()

    try:
        http("/json/version")
    except Exception as e:
        cannot_run(f"chrome-dev not answering on {CDP}: {e}")

    # The origin MUST be up, or "the shell did not render" proves nothing.
    log("=== 0. the origin is UP (else every assertion below is vacuous) ===")
    tok = gate_token()
    probe = subprocess.run(
        ["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}",
         "--resolve", "dev2.loothgroup.com:443:172.31.78.94",
         "-H", f"Cookie: loothdev_auth={tok}", ORIGIN + a.url],
        capture_output=True, text=True, timeout=40).stdout.strip()
    if probe != "200":
        cannot_run(f"{a.url} answers {probe} server-side — cannot test 'no shell while up'")
    check(f"{a.url} answers 200 server-side", probe, "200")

    log("=== 1. a real navigation through a real service worker ===")
    try:
        res = asyncio.run(run_case(a.url, a.prove))
    except Exception as e:
        cannot_run(f"browser run failed: {type(e).__name__}: {e}")

    log(f"  controller: {res.get('controller')}")
    log(f"  page: {json.dumps(res.get('page'))}")
    if res.get("prove_setup"):
        log(f"  prove setup: {res['prove_setup']}")

    check("the service worker CONTROLS the page (else we tested nothing)",
          bool(res.get("controlled_at_measure")), True)

    page = res.get("page") or {}
    body_head = str(page.get("head", ""))
    title = str(page.get("title", ""))
    shell = any(m in body_head or m in title for m in SHELL_MARKERS) or bool(page.get("hasRetry"))

    # THE DECISIVE ASSERTION, and its liveness twin.
    ok_shell = check("the OFFLINE SHELL does NOT render while the origin is up", shell, False)
    ok_real = check("the REAL page rendered (shell-absent alone would pass on a blank page)",
                    (page.get("len") or 0) > 200, True)

    if a.prove:
        log("")
        if ok_shell and ok_real:
            log("  *** PROVE FAILED: the gate stayed GREEN against a worker that serves")
            log("      the shell. The assertion is decoration. ***")
            sys.exit(1)
        log("  PROVE OK — the gate caught the broken worker (it went red above, as intended).")
        sys.exit(0)

    log("")
    log(f"  {passes} passed, {failures} failed")
    if failures:
        log("  RED — a reachable page rendered the offline shell. See docs/PWA-SW-AUDIT.md.")
        sys.exit(1)
    log("  GREEN — the worker put the real page on screen with the origin up.")
    sys.exit(0)


main()
