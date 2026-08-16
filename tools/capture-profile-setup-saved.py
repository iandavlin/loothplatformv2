#!/usr/bin/env python3
"""Rebuild built/saved.html — the post-save door — from the RUNNING page.

saved.html cannot come from the static builder: the panel is constructed by the
page's own JavaScript, so the only honest way to picture it is to run the page and
photograph what it produced. That is why it is captured separately — and it is
also why it went STALE when the dark fix landed: the other two snapshots were
rebuilt by build-profile-setup-snapshots.py and this one was not, so it went on
showing 'Saved.' at 1.25:1 on a white panel in dark mode. Gate 51 §K now measures
all three screens precisely so that cannot happen quietly again.

NOTHING IS EVER WRITTEN. window.fetch is replaced before any page script runs, so
every save call resolves locally and nothing reaches the profile-api. The captured
HTML is then neutralised the same way the static builder neutralises the others:
scripts stripped, the member renamed, live hrefs made inert.

  python3 tools/capture-profile-setup-saved.py
"""
import base64
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.request

import websocket

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
CDP = "http://127.0.0.1:9222"
UID = "1881"
WP = "/var/www/dev"
# Staged inside footer-mockups/profiles-alive, which is ALREADY symlinked into
# the docroot — so this needs no write to the shared serve at all.
STAGE_DIR = os.path.join(ROOT, "footer-mockups", "profiles-alive", "_tmp")
STAGE_URL = "https://dev2.loothgroup.com/footer-mockups/profiles-alive/_tmp/live.html"
OUT = os.path.join(ROOT, "footer-mockups", "profiles-alive", "built", "saved.html")

BANNER = """<div style="background:#1A1E12;color:#fff;font:600 13px/1.6 system-ui,sans-serif;
 padding:11px 16px;text-align:center">
 THE REAL PAGE, CAPTURED — this is the actual HTML the built step served for a real
 signed-in member, not a drawing. Every control is <b>inert here</b>: the scripts are
 stripped, so nothing on this snapshot can save, upload or change anything.
 <a href="../" style="color:#c9dd9e">&larr; back to the decision page</a>
</div>"""
PAD = """  /* The docroot injects /pwa.js here, which mounts the fixed bottom tabbar; it has
     previously landed on the exact control a mock was built to show. Reserve room. */
  body{padding-bottom:96px}
</style>"""

STUB = """
window.__calls = [];
window.fetch = function(u, o){
  window.__calls.push((o&&o.method||'GET') + ' ' + u);
  return Promise.resolve({ ok:true, status:200, json:function(){
    return Promise.resolve({ ok:true, slug:'marty2' });
  }});
};
"""


def http(p, method="GET"):
    return json.load(urllib.request.urlopen(urllib.request.Request(CDP + p, method=method), timeout=15))


def gate_token():
    r = subprocess.run(["bash", os.path.join(ROOT, "tools", "gates", "gate-env.sh")],
                       capture_output=True, text=True, timeout=30)
    for line in r.stdout.splitlines():
        if line.startswith("LG_GATE_TOKEN="):
            return line.split("=", 1)[1]
    sys.exit("CANNOT RUN — no gate token from gate-env.sh")


def render_live():
    """Render the real template (WITH its scripts) into the staged docroot path."""
    tmp = tempfile.mkdtemp(prefix="ps-saved-")
    r = subprocess.run(["sudo", "-n", "wp", "--allow-root", f"--path={WP}", "eval-file",
                        os.path.join(ROOT, "tools", "render-profile-setup.php"), UID, tmp],
                       capture_output=True, text=True, timeout=300)
    src = os.path.join(tmp, "rendered.html")
    if not os.path.isfile(src) or os.path.getsize(src) < 5000:
        sys.exit(f"render failed: {(r.stderr or r.stdout)[-400:]}")
    os.makedirs(STAGE_DIR, exist_ok=True)
    shutil.copyfile(src, os.path.join(STAGE_DIR, "live.html"))
    shutil.rmtree(tmp, ignore_errors=True)


def neutralise(html):
    """The same transformations the static builder applies to the other two."""
    html = re.sub(r"<script\b.*?</script>", "", html, flags=re.S | re.I)
    html = re.sub(r"<script\b[^>]*/?>", "", html, flags=re.I)
    html = html.replace("alden", "marty")
    html = html.replace("<body>", "<body>" + BANNER, 1)
    if "<title>" in html:
        html = html.replace("<title>", "<title>After they save — ", 1)
    if "</style>" in html:
        html = html.replace("</style>", PAD, 1)
    html = html.replace('href="/profile-setup/?skipped=1"', 'href="./skipped.html"')
    html = re.sub(r'href="/u/marty[^"]*"', 'href="#" title="inert in this snapshot"', html)
    html = re.sub(r'href="https://dev2\.loothgroup\.com/"', 'href="#" title="inert in this snapshot"', html)
    return html


def main():
    tok = gate_token()
    render_live()
    tab = http("/json/new?about:blank", method="PUT")
    html = ""
    try:
        ws = websocket.create_connection(tab["webSocketDebuggerUrl"], max_size=None,
                                         timeout=30, suppress_origin=True)
        box = {"i": 0}

        def send(m, p=None):
            box["i"] += 1
            ws.send(json.dumps({"id": box["i"], "method": m, "params": p or {}}))
            while True:
                r = json.loads(ws.recv())
                if r.get("id") == box["i"]:
                    return r

        send("Page.enable"); send("Runtime.enable"); send("Network.enable")
        send("Emulation.setDeviceMetricsOverride",
             {"width": 1280, "height": 1000, "deviceScaleFactor": 1, "mobile": False})
        send("Network.clearBrowserCookies")
        send("Network.setCookie", {"name": "loothdev_auth", "value": tok,
                                   "domain": ".dev2.loothgroup.com", "path": "/", "secure": True})
        send("Page.addScriptToEvaluateOnNewDocument", {"source": STUB})   # BEFORE page scripts
        send("Page.navigate", {"url": STAGE_URL})
        time.sleep(4)
        send("Runtime.evaluate", {"expression":
             "document.getElementById('ps-city').value='Milwaukee, Wisconsin';"
             "document.getElementById('ps-save').click(); 'clicked'", "returnByValue": True})
        time.sleep(3)

        body = send("Runtime.evaluate", {"expression": "document.body.innerText.slice(0,1500)",
                                         "returnByValue": True}
                    ).get("result", {}).get("result", {}).get("value", "") or ""
        if "Open the full profile editor" not in body:
            sys.exit("LIVENESS FAIL — the done panel did not render. body: " + body[:250])
        calls = send("Runtime.evaluate", {"expression": "JSON.stringify(window.__calls)",
                                          "returnByValue": True}
                     ).get("result", {}).get("result", {}).get("value", "[]")
        print("  stubbed calls the page would have made:", calls)

        html = send("Runtime.evaluate",
                    {"expression": "'<!doctype html>'+document.documentElement.outerHTML",
                     "returnByValue": True}).get("result", {}).get("result", {}).get("value", "") or ""
    finally:
        # The staged copy is a LIVE page with real scripts sitting in the docroot.
        # It is dev-gated and exists for seconds, but it comes out again whatever
        # happens rather than being left behind for someone to find and click.
        shutil.rmtree(STAGE_DIR, ignore_errors=True)
        for fn in (lambda: ws.close(), lambda: http("/json/close/" + tab["id"])):
            try:
                fn()
            except Exception:
                pass

    if len(html) < 5000:
        sys.exit(f"capture produced only {len(html)} bytes — refusing to publish it")
    out = neutralise(html)
    for bad, why in ((r"<script", "a <script> survived"),
                     ("profile-api", "a real write endpoint survived")):
        if re.search(bad, out, re.I):
            sys.exit(f"REFUSING TO PUBLISH — {why}")
    if "Open the full profile editor" not in out:
        sys.exit("REFUSING TO PUBLISH — the door is not in the neutralised output")
    open(OUT, "w", encoding="utf-8").write(out)
    print(f"  saved.html: {len(out)} bytes, scripts=0, profile-api=0")


main()
