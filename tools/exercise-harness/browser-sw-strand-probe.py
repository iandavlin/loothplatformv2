#!/usr/bin/env python3
"""
browser-sw-strand-probe — the two service-worker defects of backlog 3.10, in a
REAL engine, on the REAL dev2 origin.

  A. COOKIE-JAR / GATE  With no dev-gate cookie (what the installed app's own cookie
     jar looks like), does registering /sw.js fail, does addAll(SHELL) reject, and
     does offline.html's recovery probe give a FALSE POSITIVE?
The STRAND half (a hung navigation fetch) is NOT here. A CDP network stall pauses
requests on the page target, and a service worker is its OWN target, so the stall does
not reliably reach the fetch the handler actually makes — a probe that looks like it is
testing the strand while stalling something else is worse than no probe. It is tested
deterministically instead in tools/gates/lib/sw-handler-harness.js, which stubs `fetch`
to never settle: that is exactly what a hung fetch IS, and a stub can express it without
a browser at all.

Prints one JSON object. Asserts nothing — the caller decides. Read-only apart from
service-worker/cache state inside the throwaway browser context it creates.

⚠️ IT GOES STRAIGHT AT https://dev2.loothgroup.com, NOT THROUGH real-origin-proxy.py,
and that is load-bearing. The proxy forwards to 127.0.0.1:443, and the dev gate exempts
loopback outright — `geo $loothdev_src_local { default 0; 127.0.0.1 1; ::1 1; }` in
/etc/nginx/conf.d/loothdev-auth.conf. Through the proxy a cookie-less /hub/ answers
**200**, so the whole gate half of this probe would pass vacuously while testing a
posture no real client can be in. chrome-dev carries
`--host-resolver-rules=MAP dev2.loothgroup.com 172.31.78.94`, the box's LAN address, so
the browser reaches the real vhost with the real gate and Cloudflare is never in the
path. Verified: LAN + no cookie => /hub/ 403, /manifest.json 200.

  --origin    origin to drive (default https://dev2.loothgroup.com)
  --gate      send the dev-gate cookie (default: do NOT — that is case A)
"""
import argparse, asyncio, json, sys, urllib.request
import websockets

CDP = "http://127.0.0.1:9222"


def http(p):
    return json.load(urllib.request.urlopen(CDP + p))


class Sess:
    def __init__(self, ws):
        self.ws, self.n = ws, 0
        self.events = []

    async def send(self, m, p=None, sid=None):
        self.n += 1
        i = self.n
        msg = {"id": i, "method": m, "params": p or {}}
        if sid:
            msg["sessionId"] = sid
        await self.ws.send(json.dumps(msg))
        while True:
            r = json.loads(await self.ws.recv())
            if r.get("id") == i:
                if "error" in r:
                    raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})
            if "method" in r:
                self.events.append(r)

    async def ev(self, expr, sid=None):
        r = await self.send("Runtime.evaluate",
                            {"expression": expr, "returnByValue": True,
                             "awaitPromise": True}, sid)
        if r.get("exceptionDetails"):
            return {"__throw": str(r["exceptionDetails"].get("text"))}
        return r["result"].get("value")

    async def drain(self, seconds):
        """Collect events for a while without blocking on a specific id."""
        end = asyncio.get_event_loop().time() + seconds
        while asyncio.get_event_loop().time() < end:
            try:
                r = json.loads(await asyncio.wait_for(self.ws.recv(), timeout=0.3))
                if "method" in r:
                    self.events.append(r)
            except asyncio.TimeoutError:
                pass


async def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--origin", default="https://dev2.loothgroup.com")
    ap.add_argument("--gate", action="store_true")
    a = ap.parse_args()
    origin = a.origin.rstrip("/")
    out = {"case": "gate", "gate_cookie": a.gate, "origin": origin}

    bws = http("/json/version")["webSocketDebuggerUrl"]
    async with websockets.connect(bws, max_size=None) as ws:
        s = Sess(ws)
        # A throwaway BrowserContext, so this never touches the shared profile's
        # service workers or cookies — a leaked SW registration in the shared
        # profile would silently change every later run on this box.
        ctx = (await s.send("Target.createBrowserContext"))["browserContextId"]
        out["context"] = ctx[:10]
        try:
            tgt = (await s.send("Target.createTarget",
                                {"url": "about:blank", "browserContextId": ctx}))["targetId"]
            sid = (await s.send("Target.attachToTarget",
                                {"targetId": tgt, "flatten": True}))["sessionId"]
            await s.send("Page.enable", {}, sid)
            await s.send("Runtime.enable", {}, sid)
            await s.send("Network.enable", {}, sid)
            await s.send("Network.clearBrowserCookies", {}, sid)
            if a.gate:
                import subprocess
                tok = subprocess.run(
                    ["bash", "-lc",
                     '. tools/gates/lib/gate-token.sh && gate_token'],
                    capture_output=True, text=True).stdout.strip()
                out["gate_token_len"] = len(tok)
                # LEADING DOT on the gate cookie domain — the host-only form does not
                # come back on every subresource here.
                await s.send("Network.setCookies", {"cookies": [{
                    "name": "loothdev_auth", "value": tok,
                    "domain": ".dev2.loothgroup.com", "path": "/", "secure": True}]}, sid)

            first = await s.ev("1", sid)  # warm the session
            out["session_ok"] = first == 1

            # Land on the origin. WITHOUT the gate cookie this is nginx's 403 page —
            # still same-origin, so script in it can exercise caches/SW for real.
            await s.send("Page.navigate", {"url": origin + "/hub/"}, sid)
            await asyncio.sleep(2.0)
            out["doc_status_text"] = await s.ev(
                "(document.body && document.body.innerText || '').slice(0,80)", sid)

            # ── The three measurements of case A ─────────────────────────────
            out["register_sw"] = await s.ev("""
                navigator.serviceWorker.register('/sw.js', {scope:'/'})
                  .then(r => 'REGISTERED scope=' + r.scope)
                  .catch(e => 'REJECTED ' + (e && e.name) + ': ' + (e && e.message))
            """, sid)

            out["addAll_shell"] = await s.ev("""
                caches.open('probe-shell')
                  .then(c => c.addAll(['/offline.html','/icons/icon-192.png']))
                  .then(() => 'RESOLVED — both shell assets cached')
                  .catch(e => 'REJECTED ' + (e && e.name) + ': ' + (e && e.message))
            """, sid)

            out["shell_paths_individually"] = await s.ev("""
                Promise.all(['/offline.html','/icons/icon-192.png','/sw.js','/manifest.json']
                  .map(u => fetch(u, {cache:'no-store'})
                        .then(r => u + ' -> ' + r.status)
                        .catch(() => u + ' -> NETWORK-ERROR')))
                  .then(a => a.join('  |  '))
            """, sid)

            # offline.html's own recovery probe, run verbatim.
            out["offline_recovery_probe"] = await s.ev("""
                fetch('/manifest.json', {cache:'no-store'})
                  .then(r => (r && r.ok)
                      ? 'PROBE SAYS ONLINE (would location.reload()) status=' + r.status
                      : 'probe says offline status=' + r.status)
                  .catch(() => 'probe says offline (network error)')
            """, sid)
            out["reload_would_land_on"] = await s.ev("""
                fetch('/hub/', {cache:'no-store', redirect:'follow'})
                  .then(r => 'the reload target answers ' + r.status)
                  .catch(() => 'the reload target NETWORK-ERROR')
            """, sid)
            print(json.dumps(out))
        finally:
            try:
                await s.send("Target.disposeBrowserContext", {"browserContextId": ctx})
            except Exception:
                pass


asyncio.run(main())
