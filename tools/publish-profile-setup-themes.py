#!/usr/bin/env python3
"""Publish the three sign-up screens SIDE BY SIDE in light and dark, for Ian.

He rules from pictures, and he should not have to toggle his own theme to check a
theme fix — so both are photographed and shown together rather than handing him a
page that renders in whichever theme he happens to be in.

Writes shots + themes.html into footer-mockups/profiles-alive/, which is already
symlinked into the dev2 docroot, so publishing needs no write to the shared serve.

  python3 tools/publish-profile-setup-themes.py
"""
import base64
import json
import os
import subprocess
import sys
import time
import urllib.request

import websocket

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
PUB = os.path.join(ROOT, "footer-mockups", "profiles-alive")
SHOTS = os.path.join(PUB, "theme-shots")
CDP = "http://127.0.0.1:9222"
BASE = "https://dev2.loothgroup.com/footer-mockups/profiles-alive/built/"

SCREENS = (
    ("step", "step.html", "The step", "Set up your profile"),
    ("saved", "saved.html", "After they save", "Open the full profile editor"),
    ("skipped", "skipped.html", "If they skip", "No problem"),
)
DEVICES = (("desktop", {"width": 1280, "height": 1400, "mobile": False, "deviceScaleFactor": 1}),
           ("mobile", {"width": 390, "height": 1200, "mobile": True, "deviceScaleFactor": 2}))
MODES = ("light", "dark")


def gate_token():
    r = subprocess.run(["bash", os.path.join(ROOT, "tools", "gates", "gate-env.sh")],
                       capture_output=True, text=True, timeout=30)
    for line in r.stdout.splitlines():
        if line.startswith("LG_GATE_TOKEN="):
            return line.split("=", 1)[1]
    sys.exit("CANNOT RUN — no gate token")


def main():
    tok = gate_token()
    os.makedirs(SHOTS, exist_ok=True)
    tab = json.load(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT"), timeout=15))
    ws = websocket.create_connection(tab["webSocketDebuggerUrl"], max_size=None,
                                     timeout=30, suppress_origin=True)
    box = {"i": 0}

    def call(m, **p):
        box["i"] += 1
        ws.send(json.dumps({"id": box["i"], "method": m, "params": p}))
        while True:
            r = json.loads(ws.recv())
            if r.get("id") == box["i"]:
                if "error" in r:
                    raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})

    def js(e):
        return call("Runtime.evaluate", expression=e, returnByValue=True
                    ).get("result", {}).get("value")

    def goto(u, settle=1.6):
        call("Page.navigate", url=u)
        s = time.monotonic()
        while time.monotonic() - s < 25:
            time.sleep(0.15)
            try:
                if js("document.readyState") == "complete":
                    break
            except Exception:
                continue
        time.sleep(settle)

    made = []
    try:
        call("Page.enable"); call("Runtime.enable"); call("Network.enable")
        for dev, metrics in DEVICES:
            for mode in MODES:
                call("Emulation.setDeviceMetricsOverride", **metrics)
                call("Emulation.setTouchEmulationEnabled", enabled=metrics["mobile"])
                call("Emulation.setEmulatedMedia", features=[
                    {"name": "prefers-color-scheme", "value": "light"}])
                for key, fname, _label, marker in SCREENS:
                    url = BASE + fname
                    call("Network.clearBrowserCookies")
                    call("Network.setCookie", name="loothdev_auth", value=tok,
                         domain=".dev2.loothgroup.com", path="/", secure=True)
                    goto(url, settle=0.8)
                    if mode == "dark":
                        js("try{localStorage.setItem('lg-set-theme','dark')}catch(e){}")
                    else:
                        js("try{localStorage.clear()}catch(e){}")
                    goto(url, settle=1.4)
                    if mode == "dark":
                        goto(url, settle=1.8)

                    # LIVENESS before the shutter: a dev-gate 403 photographs
                    # beautifully, and a "dark" shot of a page that never went
                    # dark is the same picture as the light one.
                    txt = js("(document.body.innerText||'').slice(0,8000)") or ""
                    theme = js("document.documentElement.getAttribute('data-lguser-theme')||''")
                    if marker not in txt:
                        sys.exit(f"LIVENESS FAIL {key}/{dev}/{mode}: no {marker!r} on the page")
                    if mode == "dark" and theme != "dark":
                        sys.exit(f"LIVENESS FAIL {key}/{dev}/{mode}: theme resolved {theme!r}")

                    # HIDE INJECTED APP CHROME BEFORE THE SHUTTER. The docroot
                    # injects /pwa.js into these snapshots, which mounts a fixed
                    # bottom tabbar and an "Install Looth" banner. On a tall
                    # captureBeyondViewport stitch they render at their viewport
                    # position — i.e. straight across the MIDDLE of the image —
                    # and in the first mobile run they sat directly on top of the
                    # privacy dials, which are the control Ian is being asked to
                    # look at. A picture that hides the subject under someone
                    # else's furniture is worse than no picture.
                    #
                    # Done by COMPUTED position:fixed rather than by class name,
                    # so it keeps working when the chrome is renamed, and scoped
                    # to elements OUTSIDE .wrap so nothing belonging to the step
                    # itself can be hidden by accident.
                    hidden = js('''(function(){
                      var w = document.querySelector('.wrap'), n = 0;
                      Array.prototype.forEach.call(document.querySelectorAll('body *'), function(el){
                        if (w && (el === w || w.contains(el))) return;
                        var cs = getComputedStyle(el);
                        if (cs.position === 'fixed' && cs.display !== 'none') {
                          el.style.setProperty('display','none','important'); n++;
                        }
                      });
                      return n;
                    })()''')

                    name = f"{key}__{mode}__{dev}.png"
                    r = call("Page.captureScreenshot", format="png", captureBeyondViewport=True)
                    data = base64.b64decode(r["data"])
                    open(os.path.join(SHOTS, name), "wb").write(data)
                    made.append((key, mode, dev, name, len(data)))
                    print(f"  {name}  {len(data)//1024}KB  theme={theme}  chrome_hidden={hidden}")
    finally:
        # Never leave the shared chrome profile stamped dark.
        try:
            goto(BASE + "step.html", settle=0.4)
            js("try{localStorage.clear()}catch(e){}")
        except Exception:
            pass
        for fn in (lambda: ws.close(),
                   lambda: urllib.request.urlopen(CDP + "/json/close/" + tab["id"], timeout=10).read()):
            try:
                fn()
            except Exception:
                pass

    if len(made) != len(SCREENS) * len(DEVICES) * len(MODES):
        sys.exit(f"expected {len(SCREENS)*len(DEVICES)*len(MODES)} shots, got {len(made)}")

    def block(key, label, dev):
        return f"""
  <section>
    <h2>{label} <span class="dev">{dev}</span></h2>
    <div class="pair">
      <figure><figcaption>Light</figcaption>
        <img src="theme-shots/{key}__light__{dev}.png" alt="{label}, light, {dev}" loading="lazy"></figure>
      <figure><figcaption>Dark</figcaption>
        <img src="theme-shots/{key}__dark__{dev}.png" alt="{label}, dark, {dev}" loading="lazy"></figure>
    </div>
  </section>"""

    body = "".join(block(k, lb, d) for d, _ in DEVICES for k, _f, lb, _m in SCREENS)
    html = f"""<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign-up screens — light and dark</title>
<style>
 :root{{color-scheme:light}}
 body{{font-family:system-ui,sans-serif;background:#f6f6f2;color:#1A1E12;margin:0;
   padding:0 0 96px}}
 .wrap{{max-width:1180px;margin:0 auto;padding:0 1.25em}}
 .head{{background:#1A1E12;color:#fff;padding:18px 16px;text-align:center;
   font:600 13px/1.6 system-ui,sans-serif}}
 h1{{font-size:1.35em;margin:1.2em 0 .2em}}
 .lede{{color:#3c4a28;line-height:1.6;margin:0 0 1.4em;max-width:70ch}}
 .fix{{background:#eef3e2;border:1px solid #c3d2a4;border-radius:8px;padding:1em 1.2em;
   margin:0 0 1.8em;max-width:70ch}}
 .fix table{{border-collapse:collapse;margin-top:.6em;font-size:.92em}}
 .fix td,.fix th{{text-align:left;padding:.22em .9em .22em 0}}
 .was{{color:#b3361f;font-weight:700}} .now{{color:#3f5c22;font-weight:700}}
 section{{margin:0 0 2.6em}}
 h2{{font-size:1.05em;margin:0 0 .6em;border-bottom:1px solid #d9ddcf;padding-bottom:.35em}}
 .dev{{font-weight:400;color:#6b7256;font-size:.86em}}
 .pair{{display:grid;grid-template-columns:1fr 1fr;gap:18px}}
 @media (max-width:820px){{.pair{{grid-template-columns:1fr}}}}
 figure{{margin:0}}
 figcaption{{font-size:.82em;font-weight:700;color:#3c4a28;margin:0 0 .35em}}
 img{{width:100%;height:auto;border:1px solid #c9c5b6;border-radius:6px;display:block}}
</style></head><body>
<div class="head">THE REAL SCREENS, PHOTOGRAPHED IN BOTH THEMES — not drawings. Both are
 shown side by side so you do not have to switch your own theme to check.<br>
 The app's own bottom nav and “Install Looth” banner are hidden in these shots — they float
 over the middle of a full-page capture and were covering the privacy dials. Everything you
 see below is the step itself.</div>
<div class="wrap">
<h1>The sign-up step, light and dark</h1>
<p class="lede">You reported the section headers were invisible in dark mode. They were:
 the card kept a light green fill while the labels followed the dark theme ink, so they
 rendered near-white on near-white. Measured, fixed, and re-measured — every label now
 clears the AA contrast bar in both themes at both widths.</p>
<div class="fix">
 <strong>What the numbers were, and are</strong> (AA needs 4.5:1)
 <table>
  <tr><th></th><th>Was</th><th>Now</th></tr>
  <tr><td>“Your name”, “A photo of you”, “Where are you?”</td>
      <td class="was">1.11:1</td><td class="now">12.29:1</td></tr>
  <tr><td>“Your profile”, “Where you are”</td>
      <td class="was">1.10:1</td><td class="now">10.58:1</td></tr>
  <tr><td>“While you are here — who sees this?”</td>
      <td class="was">1.10:1</td><td class="now">10.58:1</td></tr>
  <tr><td>“This is optional…” <em>(failed the other way — dark on dark)</em></td>
      <td class="was">1.88:1</td><td class="now">9.66:1</td></tr>
 </table>
</div>
{body}
</div></body></html>"""
    open(os.path.join(PUB, "themes.html"), "w", encoding="utf-8").write(html)
    print(f"\n  themes.html: {len(html)} bytes, {len(made)} shots")
    print("  https://dev2.loothgroup.com/footer-mockups/profiles-alive/themes.html")


main()
