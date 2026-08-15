#!/usr/bin/env python3
"""Arm headless chrome-dev with the dev2 gate cookie. Run after ANY box reboot.

WHY THIS EXISTS (measured 2026-08-14, after a 2-day shutdown)
------------------------------------------------------------
chrome-dev launches with `--host-resolver-rules=MAP dev2.loothgroup.com
172.31.78.94`, so its requests arrive on the box's INTERNAL address. The dev2
gate is armed box-locally (/etc/nginx/conf.d/loothdev-auth.conf — note the REPO
copy is deliberately gate-free, "live posture", so reading the repo tells you
nothing about the running gate) and authorizes:

    geo $loothdev_src_local { default 0; 127.0.0.1 1; ::1 1; }

i.e. LOOPBACK ONLY. A `curl --resolve …:127.0.0.1` therefore sails through while
the browser gets a bare nginx 403 — the two disagree by design.

The trap that makes this expensive: a 403 page renders IDENTICALLY in light and
dark, at every viewport, with no horizontal scroll. So a visual/theme suite run
against a locked-out browser comes back GREEN on every assertion while having
measured nothing at all. Only a liveness assertion catches it (here: the theme
proof requires #lg-boot-crit or data-lguser-theme to be present).

And do NOT check the gate with /gatetest: it is on the exempt list
(`map $request_uri $loothdev_exempt { … ~^/gatetest 1; … }`), so it answers
auth=1 from any address and tells you the opposite of the truth. Probe a real
page instead — this script does.

    python3 tools/chrome-arm-dev-gate.py

Reads the token from /etc/nginx/snippets/loothdev-tokens.conf (never hardcode it).
"""
import asyncio, json, re, subprocess, sys, urllib.request
import websockets

CDP = "http://127.0.0.1:9222"
HOST = "dev2.loothgroup.com"
TOKENS = "/etc/nginx/snippets/loothdev-tokens.conf"
# A real gated page — NOT /gatetest, which is exempt and always says yes.
PROBE = f"https://{HOST}/hub/"


def token() -> str:
    try:
        conf = subprocess.run(["sudo", "-n", "cat", TOKENS], capture_output=True, text=True).stdout \
               or open(TOKENS).read()
    except Exception as e:
        sys.exit(f"cannot read {TOKENS}: {e}")
    m = re.search(r'set\s+\$loothdev_token\s+"([^"]+)"', conf)
    if not m:
        sys.exit(f"no $loothdev_token in {TOKENS}")
    return m.group(1)


def new_tab():
    return json.load(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")))


async def rpc(ws, method, params=None, _id=[0]):
    _id[0] += 1; mid = _id[0]
    await ws.send(json.dumps({"id": mid, "method": method, "params": params or {}}))
    while True:
        m = json.loads(await ws.recv())
        if m.get("id") == mid:
            if "error" in m:
                raise RuntimeError(f"{method}: {m['error']}")
            return m.get("result", {})


async def ev(ws, expr):
    r = await rpc(ws, "Runtime.evaluate", {"expression": expr, "returnByValue": True})
    return r.get("result", {}).get("value")


async def main():
    tok = token()
    tab = new_tab()
    try:
        async with websockets.connect(tab["webSocketDebuggerUrl"], max_size=None, origin=None) as ws:
            await rpc(ws, "Page.enable"); await rpc(ws, "Runtime.enable"); await rpc(ws, "Network.enable")
            await rpc(ws, "Network.setCacheDisabled", {"cacheDisabled": True})

            # Clear first. Network.setCookie ADDS a second host-only-vs-dotted cookie
            # rather than replacing one, and the duplicate has bitten this box before.
            await rpc(ws, "Network.deleteCookies", {"name": "loothdev_auth", "domain": HOST})
            await rpc(ws, "Network.deleteCookies", {"name": "loothdev_auth", "domain": "." + HOST})
            # The gate cookie is DOTTED (the chrome-dev-login skill says host-only; it is
            # wrong for this cookie on this box).
            await rpc(ws, "Network.setCookie", {
                "name": "loothdev_auth", "value": tok, "domain": "." + HOST,
                "path": "/", "secure": True, "httpOnly": False, "sameSite": "Lax"})

            await rpc(ws, "Page.navigate", {"url": PROBE})
            for _ in range(60):
                await asyncio.sleep(0.25)
                if await ev(ws, "document.readyState") == "complete":
                    break
            await asyncio.sleep(0.6)
            title = await ev(ws, "document.title") or ""
            body = (await ev(ws, "document.body.innerText.slice(0,60)")) or ""
            booted = await ev(ws, "!!document.getElementById('lg-boot-crit')")

            if "403" in title or "403" in body:
                sys.exit(f"STILL GATED: {PROBE} -> {title!r}. Token may have rotated in {TOKENS}.")
            print(f"gate OK  probe={PROBE}  title={title[:52]!r}")
            print(f"boot script present: {booted}"
                  + ("" if booted else "   <- platform injection missing; theme runs would be vacuous"))
    finally:
        try:
            urllib.request.urlopen(CDP + "/json/close/" + tab["id"]).read()
        except Exception:
            pass


asyncio.run(main())
