#!/usr/bin/env python3
"""202-decision-shots — photograph the decision box on the branch preview.

    python3 tools/preview/202-decision-shots.py

Writes into footer-mockups/202-decision-box/ (committed — a lane lost a set of
shots to a loose-file sweep on 2026-08-22, so they live in the repo).

⚠️ ONE PERSISTENT CDP SESSION, not a socket per command. A per-command
connection drops the device-metrics override, so a phone shot comes back as a
desktop page and the run false-PASSES having photographed the wrong thing.

⚠️ THE GATE COOKIE NEEDS A LEADING DOT on this box (the chrome-dev skill says
the opposite and is stale). Without it every page is a styled 403 — which is
IDENTICAL at every width, so a visual suite passes having measured nothing
(trap-locked-out-browser-goes-vacuously-green). Hence the liveness assertion
below: no shot counts until the page proves it is the page.

⚠️ NOTHING IS WRITTEN TO localStorage. The chrome profile is shared with every
other lane's browser, and a theme key written here goes dark for all of them.
"""
import base64
import json
import pathlib
import re
import subprocess
import sys
import time
import urllib.request

try:
    import websocket  # type: ignore
except ImportError:
    sys.exit("python3-websocket is not installed — cannot drive CDP")

HOST = "dev2.loothgroup.com"
URL = f"https://{HOST}/preview/202-web-decision-box/"
OUT = pathlib.Path(__file__).resolve().parents[2] / "footer-mockups" / "202-decision-box"
SHOTS = [("desktop", 1280, 900), ("phone", 390, 844)]


def gate_token():
    """⚠ THE GATE VALUE IS IN THE NGINX MAP, NOT IN /etc/looth/env.

    `LG_GATE_COOKIE` there holds the cookie's NAME (`loothdev_auth`), not its
    value — and reading it as a token costs an hour, because the mistake hides
    behind a second one: a curl sent with `--resolve …:127.0.0.1` passes the
    gate on `$loothdev_src_local` whatever cookie it carries. So a bogus token
    over loopback returns 200 and looks exactly like a working cookie, while a
    real browser (which is NOT loopback) gets the claim page.

    The map is the only source of truth: `/etc/nginx/conf.d/loothdev-auth.conf`.
    """
    conf = subprocess.run(["sudo", "cat", "/etc/nginx/conf.d/loothdev-auth.conf"],
                          capture_output=True, text=True).stdout
    m = re.search(r'map \$cookie_loothdev_auth [^{]*\{[^}]*"([^"]+)"\s+1', conf)
    if not m:
        sys.exit("could not read the gate value out of the nginx map")
    return m.group(1)


class CDP:
    def __init__(self, ws_url):
        # ⚠ suppress_origin: Chrome 151 rejects a CDP websocket carrying an
        # Origin header with 403 unless it was started with --remote-allow-origins.
        self.ws = websocket.create_connection(ws_url, timeout=30,
                                            suppress_origin=True)
        self.n = 0

    def send(self, method, **params):
        self.n += 1
        self.ws.send(json.dumps({"id": self.n, "method": method,
                                 "params": params}))
        while True:
            m = json.loads(self.ws.recv())
            if m.get("id") == self.n:
                if "error" in m:
                    raise RuntimeError(f"{method}: {m['error']}")
                return m.get("result", {})

    def eval(self, expr):
        r = self.send("Runtime.evaluate", expression=expr, returnByValue=True,
                      awaitPromise=True)
        return r.get("result", {}).get("value")


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    tok = gate_token()

    # A dedicated tab, closed at the end — never the shared about:blank.
    tgt = json.loads(urllib.request.urlopen(urllib.request.Request(
        "http://127.0.0.1:9222/json/new?about:blank", method="PUT"),
        timeout=15).read())
    tid = tgt["id"]
    c = CDP(tgt["webSocketDebuggerUrl"])
    failures = []
    try:
        c.send("Network.enable")
        c.send("Page.enable")
        # Clear first: the shared profile accumulates duplicate host-only vs
        # dotted cookies and the run then executes as somebody else.
        c.send("Network.clearBrowserCookies")
        c.send("Network.setCookie", name="loothdev_auth", value=tok,
               domain="." + HOST, path="/", secure=True)

        for name, w, h in SHOTS:
            c.send("Emulation.setDeviceMetricsOverride", width=w, height=h,
                   deviceScaleFactor=2, mobile=(name == "phone"))
            c.send("Page.navigate", url=URL)
            time.sleep(2.5)

            # LIVENESS, before anything is counted or photographed.
            title = c.eval("document.querySelector('h1') && "
                           "document.querySelector('h1').textContent")
            if title != "lanes":
                failures.append(f"{name}: not the lanes page (h1 = {title!r}) "
                                "— probably a styled 403")
                continue

            btn = c.eval("!!document.getElementById('lg-decide-open')")
            if not btn:
                failures.append(f"{name}: the decisions button is not on the page")
                continue

            shot(c, OUT / f"{name}-1-closed.png")

            # Hit-test before clicking: a blind click lands on fixed furniture
            # (the injected tabbar sits at z-index 2147481000+) and still passes.
            hit = c.eval("""(function(){
              var b=document.getElementById('lg-decide-open');
              var r=b.getBoundingClientRect();
              var e=document.elementFromPoint(r.left+r.width/2, r.top+r.height/2);
              return e===b || b.contains(e) ? 'ok' : (e?e.tagName+'.'+e.className:'nothing');
            })()""")
            if hit != "ok":
                failures.append(f"{name}: the button is covered by {hit}")
                continue

            c.eval("document.getElementById('lg-decide-open').click()")
            time.sleep(2.0)
            n_cards = c.eval("document.querySelectorAll('#lg-decide-body .qcard').length")
            n_opts = c.eval("document.querySelectorAll('#lg-decide-body .optbtn').length")
            if not n_cards:
                body = c.eval("document.getElementById('lg-decide-body').textContent")
                failures.append(f"{name}: the box opened empty — it said {body!r}")
                continue
            shot(c, OUT / f"{name}-2-open.png")
            print(f"  {name}: {n_cards} question(s), {n_opts} option(s) drawn")

        # The profile must come back as we found it.
        leaked = c.eval("(function(){try{return localStorage.getItem('lg-set-theme')}"
                        "catch(e){return 'unreadable'}})()")
        if leaked not in (None, "unreadable"):
            print(f"  note: lg-set-theme was already {leaked!r} — not written by this run")
    finally:
        c.ws.close()
        urllib.request.urlopen(f"http://127.0.0.1:9222/json/close/{tid}",
                               timeout=10).read()

    if failures:
        print("\nFAILED:")
        for f in failures:
            print("  ·", f)
        sys.exit(1)
    print(f"\nshots in {OUT}")


def shot(c, path):
    d = c.send("Page.captureScreenshot", format="png", captureBeyondViewport=False)
    path.write_bytes(base64.b64decode(d["data"]))
    print(f"  wrote {path.name} ({path.stat().st_size // 1024}KB)")


if __name__ == "__main__":
    main()
