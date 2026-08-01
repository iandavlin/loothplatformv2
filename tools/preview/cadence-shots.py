#!/usr/bin/env python3
"""Screenshot the cadence control in both themes at phone + desktop.

The control is HIDDEN on the real preview, correctly: lg_fd_cadence_state 404s
because LG_FOLLOW_DIGEST_ENABLED is false in the tracked config. So the endpoint
RESPONSE is stubbed here, and nothing else is. The markup, the CSS, the JS that
builds the pills from `options` and paints from `cadence`, the layout, both
themes — all real, all served off the branch through nginx and FPM.
"""
import base64, json, os, subprocess, ssl, sys, time
import urllib.request
import websocket

OUT = "/home/ubuntu/projects/footer-mockups/account-following/cadence"
URL = "https://dev2.loothgroup.com/preview/account-following/manage-subscription/"
CDP = "http://127.0.0.1:9222"

os.makedirs(OUT, exist_ok=True)

# ── cookies ──────────────────────────────────────────────────────────────────
uid = 1
r = subprocess.run(["sudo", "-n", "-u", "looth-dev", "wp", "eval",
    f'$e=time()+3600; echo LOGGED_IN_COOKIE."=".wp_generate_auth_cookie({uid},$e,"logged_in")."\\n";'
    f'echo SECURE_AUTH_COOKIE."=".wp_generate_auth_cookie({uid},$e,"secure_auth")."\\n";',
    "--skip-themes", "--path=/var/www/dev"], capture_output=True, text=True)
pairs = [l for l in r.stdout.splitlines() if l.startswith("wordpress")]
g = subprocess.run(["sudo", "-n", "grep", "-oP", r'loothdev_token\s+"\K[^"]+',
    "/etc/nginx/snippets/loothdev-tokens.conf"], capture_output=True, text=True)
tok = (g.stdout.strip().splitlines() or [""])[0]
assert len(pairs) >= 2 and tok, "cookie mint failed"

# ── one persistent connection (per-command sockets drop device emulation) ────
req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
tgt = json.loads(urllib.request.urlopen(req, timeout=10).read())
ws = websocket.create_connection(tgt["webSocketDebuggerUrl"], timeout=30, suppress_origin=True)
N = [0]

def send(method, params=None):
    N[0] += 1
    ws.send(json.dumps({"id": N[0], "method": method, "params": params or {}}))
    while True:
        m = json.loads(ws.recv())
        if m.get("id") == N[0]:
            if "error" in m:
                raise RuntimeError(f"{method}: {m['error']}")
            return m.get("result", {})

def ev(expr, awaitp=False):
    r = send("Runtime.evaluate", {"expression": expr, "returnByValue": True,
                                  "awaitPromise": awaitp})
    return r.get("result", {}).get("value")

send("Page.enable"); send("Runtime.enable"); send("Network.enable")
send("Security.setIgnoreCertificateErrors", {"ignore": True})

cookies = [{"name": k, "value": v, "domain": ".dev2.loothgroup.com", "path": "/"}
           for k, v in (c.split("=", 1) for c in pairs)]
cookies.append({"name": "loothdev_auth", "value": tok,
                "domain": ".dev2.loothgroup.com", "path": "/"})
send("Network.setCookies", {"cookies": cookies})

# ── stub ONLY the cadence state endpoint, before any page script runs ────────
STUB = r"""
(() => {
  const real = window.fetch;
  window.fetch = function (input, init) {
    const url  = (typeof input === 'string') ? input : (input && input.url) || '';
    const body = (init && init.body) ? String(init.body) : '';
    if (url.indexOf('admin-ajax.php') >= 0 && body.indexOf('lg_fd_cadence_state') >= 0) {
      return Promise.resolve(new Response(JSON.stringify({
        ok: true, cadence: 'daily',
        options: ['instant', 'daily', 'weekly'], nonce: 'stub-nonce'
      }), {status: 200, headers: {'Content-Type': 'application/json'}}));
    }
    if (url.indexOf('admin-ajax.php') >= 0 && body.indexOf('lg_fd_cadence_set') >= 0) {
      const m = /cadence=([a-z]+)/.exec(body);
      return Promise.resolve(new Response(JSON.stringify({
        ok: true, cadence: m ? m[1] : 'daily'
      }), {status: 200, headers: {'Content-Type': 'application/json'}}));
    }
    return real.apply(this, arguments);
  };
})();
"""
send("Page.addScriptToEvaluateOnNewDocument", {"source": STUB})

VIEWS = [("desktop", 1280, 900), ("phone", 390, 844)]
THEMES = ["light", "dark"]
results = []

for vname, w, h in VIEWS:
    for theme in THEMES:
        send("Emulation.setDeviceMetricsOverride", {
            "width": w, "height": h, "deviceScaleFactor": 2,
            "mobile": vname == "phone"})
        # navigate FIRST — a localStorage write on about:blank is a no-op, and the
        # shared chrome-dev profile persists lg-set-theme from whoever ran last.
        send("Page.navigate", {"url": URL}); time.sleep(2.5)
        ev(f"localStorage.setItem('lg-set-theme', '{theme}'); true")
        send("Page.navigate", {"url": URL})

        shown = False
        for _ in range(60):
            time.sleep(0.4)
            st = ev("""(() => {
              const b = document.getElementById('lg-fol-freq');
              if (!b) return 'gone';
              const cs = getComputedStyle(b);
              if (cs.display === 'none' || b.hidden) return 'hidden';
              const on = [...b.querySelectorAll('[data-cadence]')]
                          .filter(o => o.getAttribute('aria-checked') === 'true')
                          .map(o => o.getAttribute('data-cadence'));
              return 'shown:' + on.join(',') + ':' + b.querySelectorAll('[data-cadence]').length;
            })()""")
            if st and st.startswith("shown"):
                shown = True
                break
        applied = ev("document.documentElement.getAttribute('data-lguser-theme') || 'default'")
        want = "dark" if theme == "dark" else "default"
        # HIT-TEST: in the DOM is not the same as reachable. The shorty dock and the
        # fixed tabbar have each stolen a click on this box.
        hit = ev("""(() => {
          const b = document.querySelector('#lg-fol-freq [data-cadence="weekly"]');
          if (!b) return 'no-button';
          b.scrollIntoView({block:'center'});
          const r = b.getBoundingClientRect();
          const el = document.elementFromPoint(r.left + r.width/2, r.top + r.height/2);
          if (!el) return 'nothing-at-point';
          return b.contains(el) || el === b ? 'REACHABLE' : 'BLOCKED by ' +
                 (el.id || el.className || el.tagName);
        })()""")
        ev("(()=>{const b=document.getElementById('lg-fol-freq'); if(b) b.scrollIntoView({block:'center'}); return true;})()")
        time.sleep(0.5)
        # ⚠️ Page.captureScreenshot clips in PAGE coordinates; getBoundingClientRect
        # returns VIEWPORT ones. Without the scroll offset the clip lands above the
        # control and writes a blank white PNG that looks like a broken render.
        clip = ev("""(() => {
          const b = document.getElementById('lg-fol-freq');
          if (!b) return null;
          const r = b.getBoundingClientRect();
          const x = r.left + window.scrollX, y = r.top + window.scrollY;
          return JSON.stringify({x:Math.max(0,x-20), y:Math.max(0,y-28),
                                 width:Math.min(r.width+40, document.documentElement.scrollWidth),
                                 height:r.height+56});
        })()""")
        name = f"{vname}-{theme}"
        params = {"format": "png", "captureBeyondViewport": False}
        if clip:
            c = json.loads(clip); c["scale"] = 2
            params["clip"] = c
        img = send("Page.captureScreenshot", params)
        open(f"{OUT}/{name}.png", "wb").write(base64.b64decode(img["data"]))
        full = send("Page.captureScreenshot", {"format": "png"})
        open(f"{OUT}/{name}-full.png", "wb").write(base64.b64decode(full["data"]))
        results.append((name, shown, applied == want, applied, st, hit))
        print(f"{name:16} shown={shown} theme={applied:8} state={st} hit={hit}")

print("\n--- summary ---")
for n, s, t, a, st, hit in results:
    print(f"{n:16} control={'SHOWN' if s else 'MISSING'}  theme_ok={t} ({a})  {st}  {hit}")
ws.close()
