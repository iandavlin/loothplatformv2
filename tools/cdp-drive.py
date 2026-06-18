#!/usr/bin/env python3
# CDP driver for headless chrome-dev.service (127.0.0.1:9222).
# Canonical copy — /tmp/cdp-drive.py keeps getting cleared; copy from here.
import asyncio, json, sys, urllib.request
import websockets
CDP_HTTP = "http://127.0.0.1:9222"
def http_get(path): return json.load(urllib.request.urlopen(CDP_HTTP + path))
async def send(ws_url, method, params=None):
    async with websockets.connect(ws_url, max_size=None) as ws:
        await ws.send(json.dumps({"id": 1, "method": method, "params": params or {}}))
        return json.loads(await ws.recv())
def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else "list"
    if cmd == "list":
        print(json.dumps(http_get("/json"), indent=2)); return
    pages = http_get("/json"); page = next(p for p in pages if p["id"] == sys.argv[2]); ws = page["webSocketDebuggerUrl"]
    if cmd == "navigate":
        print(json.dumps(asyncio.run(send(ws, "Page.navigate", {"url": sys.argv[3]})), indent=2))
    elif cmd == "eval":
        print(json.dumps(asyncio.run(send(ws, "Runtime.evaluate", {"expression": sys.argv[3], "returnByValue": True, "awaitPromise": True})), indent=2))
if __name__ == "__main__": main()
