#!/usr/bin/env python3
"""category-option-shots.py — draw BOTH answers to Ian's category-page question,
from the REAL running system.

Ian has to rule one thing before the rail rebuild can start:

    does a category page show CONTENT ITEMS alongside discussions,
    or discussions only?

NOT A MOCK, AND THAT IS THE POINT. Both options already render today — the hub
rail has a category dimension its own UI drives, so the two answers are two URLs,
not two drawings:

    A  UNIFIED          /hub/?leaf=<forum_id>                    12 topics + 6 content
    B  DISCUSSIONS ONLY /hub/?leaf=<forum_id>&type=discussions   18 topics + 0 content

Both already carry the new hub rail and neither carries the legacy tree, so what
Ian sees differs in exactly ONE dimension — the thing he is being asked about.
Same category both ways, so nothing else moves between the pictures. A drawn mock
would have put my guesses about spacing and card mix in front of him instead.

(Today's /hub/<category>/ is shot too, as the BEFORE: 18 topics, no rail, legacy
tree. Without it the two options are a choice with no baseline.)

One persistent CDP session, its own tab, cookies re-declared per shot — same
discipline as hub-landing-shots.py, and for the same reasons written up there.
Serial by construction: this box has 2 cores.
"""

import base64
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
SLUG = os.environ.get("LG_CAT_SLUG", "acoustic")
DESKTOP = {"width": 1440, "height": 1600, "mobile": False, "deviceScaleFactor": 1}
# Tall on purpose — see shot(). 390 wide keeps it a genuine phone render; the
# height is what makes the two options visibly different in one image.
PHONE = {"width": 390, "height": 2400, "mobile": True, "deviceScaleFactor": 2}


def sh(cmd):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True).stdout.strip()


def gate_token():
    for line in sh("bash tools/gates/gate-env.sh").split("\n"):
        if line.startswith("LG_GATE_TOKEN="):
            return line.split("=", 1)[1]
    raise SystemExit("CANNOT RUN  no gate token")


class S:
    def __init__(self):
        req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
        t = json.load(urllib.request.urlopen(req))
        self.tid = t["id"]
        self.ws = websocket.create_connection(t["webSocketDebuggerUrl"],
                                              max_size=None, timeout=60,
                                              suppress_origin=True)
        self.i = 0

    def call(self, m, **p):
        self.i += 1
        self.ws.send(json.dumps({"id": self.i, "method": m, "params": p}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("id") == self.i:
                return r.get("result", {})

    def js(self, e):
        return self.call("Runtime.evaluate", expression=e,
                         returnByValue=True, awaitPromise=True).get("result", {}).get("value")

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
        # A TALL BOUNDED VIEWPORT, not captureBeyondViewport.
        #
        # Viewport-only at 390x844 caught header + filter strip + the top of one
        # card — identical between the two options, which diverge further down.
        # That produced two BYTE-IDENTICAL phone PNGs: Ian asked to choose
        # between the same picture twice.
        #
        # captureBeyondViewport was the obvious fix and it FAILED: the hub feed
        # is effectively endless, so CDP returned an error with no data at all
        # (a bare KeyError here). The viewport height is set tall per surface
        # instead — enough feed to show the card mix, still a real 390px-wide
        # mobile render with touch emulation on.
        r = self.call("Page.captureScreenshot", format="png")
        if "data" not in r:
            raise RuntimeError(f"captureScreenshot returned no data: "
                               f"{str(r)[:200]}")
        pathlib.Path(path).write_bytes(base64.b64decode(r["data"]))
        return os.path.getsize(path)

    def finish(self):
        try:
            self.ws.close()
        except Exception:                                      # noqa: BLE001
            pass
        try:
            urllib.request.urlopen(CDP + "/json/close/" + self.tid).read()
        except Exception:                                      # noqa: BLE001
            pass


def main():
    out = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else ".")
    out.mkdir(parents=True, exist_ok=True)
    tok = gate_token()
    fid = sh("sudo -u postgres psql -d looth -At -c \"SELECT id FROM forums.forum "
             f"WHERE slug='{SLUG}' AND visibility='public' LIMIT 1\"")
    if not fid:
        print(f"CANNOT RUN  no public forum with slug {SLUG!r}")
        return 2
    title = sh("sudo -u postgres psql -d looth -At -c \"SELECT title FROM forums.forum "
               f"WHERE id={fid}\"")
    print(f"category: {title} (slug={SLUG}, id={fid})")

    VIEWS = [
        ("before", f"/hub/{SLUG}/",                       "Today — legacy category rail"),
        ("a-unified", f"/hub/?leaf={fid}",                "Option A — discussions + content"),
        ("b-discussions", f"/hub/?leaf={fid}&type=discussions", "Option B — discussions only"),
    ]

    s = S()
    s.call("Page.enable"); s.call("Runtime.enable"); s.call("Network.enable")
    rows = []
    for surface, metrics in (("desktop", DESKTOP), ("phone", PHONE)):
        s.call("Emulation.setDeviceMetricsOverride", **metrics)
        s.call("Emulation.setTouchEmulationEnabled", enabled=metrics["mobile"])
        for key, path, label in VIEWS:
            # Re-declare the viewer every shot: the browser profile is shared with
            # the other lanes and drifts. Anonymous is deliberate — this is the
            # logged-out view Google and a first-time visitor get.
            s.call("Network.clearBrowserCookies")
            s.call("Network.setCookie", name="loothdev_auth", value=tok,
                   domain=".dev2.loothgroup.com", path="/", secure=True)
            s.goto(HOST + path)
            # DISMISS THE PWA INSTALL PROMPT before capturing. pwa.js appends an
            # .lpw-* banner that sits over the bottom of the feed — and the whole
            # point of these shots is the CARD MIX, so a promo covering a card is
            # noise in the one thing Ian is being asked to judge. (The standing
            # note records it landing on the very control a previous mock was
            # showing.) Clicked, not CSS-hidden, so it goes away the way it does
            # for a real visitor; the bottom nav STAYS, because that is genuinely
            # part of the phone experience.
            s.js("""(function(){var x=document.querySelector('.lpw-x');
                     if(x){x.click();return 'clicked';}
                     var b=document.querySelector('[class^=lpw-],[class*=" lpw-"]');
                     if(b){b.remove();return 'removed';} return 'absent';})()""")
            time.sleep(0.6)
            st = s.js("""({topics: document.querySelectorAll('.feed-card--topic').length,
                           content: document.querySelectorAll('.feed-card--content').length,
                           rail: !!document.querySelector('.hub-rail, .hub-frail'),
                           legacy: !!document.querySelector('.nav-tree')})""")
            f = out / f"cat-{key}-{surface}.png"
            size = s.shot(f)
            rows.append((surface, key, st, size))
            print(f"  {surface:<7} {label:<34} topics={st['topics']:<3} "
                  f"content={st['content']:<3} rail={st['rail']} legacy={st['legacy']}")
    s.finish()

    # ── THE PICTURES THEMSELVES MUST DIFFER ─────────────────────────────────
    # The DOM assertions below check the PAGE; this checks the ARTEFACT. They are
    # not the same claim, and the gap between them shipped: the DOM counts were
    # correct per view (12/6 vs 18/0) while the two phone PNGs came out
    # byte-identical, because a viewport-only capture at 390px caught nothing but
    # the shared header. A picture-based decision is only as good as the picture.
    import hashlib
    def _md5(p):
        return hashlib.md5(pathlib.Path(p).read_bytes()).hexdigest()
    for sf in ("desktop", "phone"):
        a, b = out / f"cat-a-unified-{sf}.png", out / f"cat-b-discussions-{sf}.png"
        if _md5(a) == _md5(b):
            print(f"RED  {sf}: the two option images are BYTE-IDENTICAL — this is "
                  f"not a choice, it is the same picture twice")
            return 1

    d = {(sf, k): st for sf, k, st, _ in rows}
    bad = []
    for sf in ("desktop", "phone"):
        if d[(sf, "a-unified")]["content"] == 0:
            bad.append(f"{sf}: option A shows NO content items — it is not the unified view")
        if d[(sf, "b-discussions")]["content"] != 0:
            bad.append(f"{sf}: option B shows content items — it is not discussions-only")
        if not d[(sf, "a-unified")]["rail"] or not d[(sf, "b-discussions")]["rail"]:
            bad.append(f"{sf}: an option is missing the hub rail")
    print()
    if bad:
        print("RED  the shots do not show the choice they claim:")
        for b in bad:
            print(f"  - {b}")
        return 1
    print(f"GREEN  both options differ in exactly the dimension under question; "
          f"{len(rows)} shots in {out}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
