#!/usr/bin/env python3
"""category-door-shots.py — the BUILT Google door, next to what it replaces.

Ian ruled the shape (IAN-RULINGS 2026-08-12, items 7-8) from the option mock; he
has not yet seen the thing that was built from that ruling. This draws it.

TWO CATEGORIES ON PURPOSE, and this is the point of the script rather than an
incidental choice:

    a CONTENT-RICH one   — option A as Ian pictured it: discussions and related
                           content mixed
    a THIN one           — a door where the hub's own category filter associates
                           NO content, so it is discussions only

Only 15 of the 45 public forums have any content at all, and for at least one
category the hub's existing ?leaf= filter returns zero content even though rows
carry a matching-ish label. Showing only the rich case would let Ian approve a
picture that two thirds of the doors will never look like. Both realities, or the
approval is not informed.

Door lives on the lane preview (flag armed there and nowhere else); "today" is
the real /hub/<category>/ on the same box, so the pair is a genuine before/after
of the same category rather than a memory.

Carries the lessons from the option-mock round:
  · a TALL BOUNDED viewport, never captureBeyondViewport — the hub feed is
    effectively endless and CDP returns an error with no data;
  · the PWA install banner is dismissed through its own control before capture,
    because it lands over the cards these shots exist to show;
  · every pair is asserted to DIFFER by md5, because two identical images are not
    a comparison — that check is the only reason the last round's phone pair was
    caught;
  · one browser, one tab, serial. This is a 2-core box.
"""

import base64
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import time
import urllib.request

import websocket

CDP = "http://127.0.0.1:9222"
HOST = "https://dev2.loothgroup.com"
DOOR_PREFIX = os.environ.get("LG_DOOR_PREFIX", "/hub/preview-seo")
LIVE_PREFIX = "/hub"
DESKTOP = {"width": 1440, "height": 1600, "mobile": False, "deviceScaleFactor": 1}
PHONE = {"width": 390, "height": 2400, "mobile": True, "deviceScaleFactor": 2}


def sh(c):
    return subprocess.run(c, shell=True, capture_output=True, text=True).stdout.strip()


def gate_token():
    for l in sh("bash tools/gates/gate-env.sh").split("\n"):
        if l.startswith("LG_GATE_TOKEN="):
            return l.split("=", 1)[1]
    raise SystemExit("CANNOT RUN  no gate token")


class S:
    def __init__(self):
        req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
        t = json.load(urllib.request.urlopen(req))
        self.tid = t["id"]
        self.ws = websocket.create_connection(t["webSocketDebuggerUrl"], max_size=None,
                                              timeout=60, suppress_origin=True)
        self.i = 0

    def call(self, m, **p):
        self.i += 1
        self.ws.send(json.dumps({"id": self.i, "method": m, "params": p}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("id") == self.i:
                return r.get("result", {})

    def js(self, e):
        return self.call("Runtime.evaluate", expression=e, returnByValue=True,
                         awaitPromise=True).get("result", {}).get("value")

    def goto(self, url, settle=3.2):
        self.call("Page.navigate", url=url)
        for _ in range(80):
            time.sleep(0.15)
            try:
                if self.js("document.readyState") == "complete":
                    break
            except Exception:                                  # noqa: BLE001
                continue
        time.sleep(settle)

    def shot(self, path):
        r = self.call("Page.captureScreenshot", format="png")
        if "data" not in r:
            raise RuntimeError(f"captureScreenshot returned no data: {str(r)[:180]}")
        pathlib.Path(path).write_bytes(base64.b64decode(r["data"]))

    def finish(self):
        for fn in (lambda: self.ws.close(),
                   lambda: urllib.request.urlopen(CDP + "/json/close/" + self.tid).read()):
            try:
                fn()
            except Exception:                                  # noqa: BLE001
                pass


def md5(p):
    return hashlib.md5(pathlib.Path(p).read_bytes()).hexdigest()


def main():
    out = pathlib.Path(sys.argv[1])
    out.mkdir(parents=True, exist_ok=True)
    tok = gate_token()

    cats = [("acoustic", "rich"), (os.environ.get("LG_DOOR_THIN", "amps-pickups-and-pedals"), "thin")]
    s = S()
    s.call("Page.enable"); s.call("Runtime.enable"); s.call("Network.enable")
    rows = []
    for surface, metrics in (("desktop", DESKTOP), ("phone", PHONE)):
        s.call("Emulation.setDeviceMetricsOverride", **metrics)
        s.call("Emulation.setTouchEmulationEnabled", enabled=metrics["mobile"])
        for slug, kind in cats:
            for which, prefix in (("door", DOOR_PREFIX), ("today", LIVE_PREFIX)):
                s.call("Network.clearBrowserCookies")
                s.call("Network.setCookie", name="loothdev_auth", value=tok,
                       domain=".dev2.loothgroup.com", path="/", secure=True)
                s.goto(f"{HOST}{prefix}/{slug}/")
                s.js("""(function(){var x=document.querySelector('.lpw-x');
                        if(x){x.click();return;}
                        var b=document.getElementById('looth-pwa-banner');
                        if(b) b.remove();})()""")
                time.sleep(0.6)
                st = s.js("""({t:document.querySelectorAll('.feed-card--topic').length,
                               c:document.querySelectorAll('.feed-card--content').length,
                               legacy:!!document.querySelector('.nav-tree'),
                               rail:!!document.querySelector('.hub-rail,.hub-frail')})""")
                f = out / f"{kind}-{which}-{surface}.png"
                s.shot(f)
                rows.append((surface, kind, which, st))
                print(f"  {surface:<7} {kind:<4} {which:<5} {slug:<26} "
                      f"topics={st['t']:<3} content={st['c']:<3} "
                      f"legacy={st['legacy']} rail={st['rail']}")
    s.finish()

    bad = []
    for surface in ("desktop", "phone"):
        for kind, _ in [(k, v) for k, v in [(c[1], c[0]) for c in cats]]:
            a, b = out / f"{kind}-door-{surface}.png", out / f"{kind}-today-{surface}.png"
            if md5(a) == md5(b):
                bad.append(f"{surface}/{kind}: door and today are BYTE-IDENTICAL — "
                           f"that is not a before/after")
    d = {(r[0], r[1], r[2]): r[3] for r in rows}
    for surface in ("desktop", "phone"):
        for kind in ("rich", "thin"):
            door = d[(surface, kind, "door")]
            if door["legacy"]:
                bad.append(f"{surface}/{kind}: the DOOR still shows the legacy tree")
            if door["rail"]:
                bad.append(f"{surface}/{kind}: the DOOR shows the hub rail — that is "
                           f"member nav, which ruling 7 forbids")
        if d[(surface, "rich", "door")]["c"] == 0:
            bad.append(f"{surface}: the RICH door shows no content — it is not "
                       f"demonstrating option A")
        if d[(surface, "thin", "door")]["c"] != 0:
            bad.append(f"{surface}: the THIN door shows content — it is not "
                       f"demonstrating the thin reality")
    print()
    if bad:
        print("RED  these shots do not show what they claim:")
        for b in bad:
            print(f"  - {b}")
        return 1
    print(f"GREEN  {len(rows)} shots in {out}; door/today differ on every pair, "
          f"rich shows content, thin shows none, no door carries member nav.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
