#!/usr/bin/env python3
"""#196 — photograph the account menu and the switch page, both themes, four widths.

Everything here is REAL: the branch's own shared-header partial, served through
real nginx and the real membership FPM pool, with the vhost's own sub_filter
injecting /pwa.js and app-settings.js exactly as it does on every other page.
Nothing is stubbed.

WIDTHS. 1440 and 390 are the ordinary two. 821 and 640 are there because this
surface has produced a lockout at each: gate 12's sign-in dead band at 641-820,
and #165's Join dead band at 821-904, both still open. A control that is in the
DOM and off the right-hand edge is the trap this repo has now paid for three
times, so every shot is HIT-TESTED with elementFromPoint rather than found.

⚠️ THE SHARED PROFILE IS RESTORED AT THE END. chrome-dev has one user-data-dir
for the whole box and lg-set-theme persists in it, so a run that leaves 'dark'
behind turns every other lane's "light" screenshot dark
(trap-chrome-dev-profile-persists-dark-theme, trap-mock-theme-stamp-poisons-
shared-chrome). The prior value is read first and put back.
"""
import base64, json, os, subprocess, sys, time
import urllib.request
import websocket

OUT  = "/home/ubuntu/projects/footer-mockups/196-switch-menu"
BASE = "https://dev2.loothgroup.com/preview/196-switch-menu"
CDP  = "http://127.0.0.1:9222"
UID  = 1953          # mikelle.davlin — the real listed tester Patreon is charging

os.makedirs(OUT, exist_ok=True)

# ── cookies: a REAL session, minted through WP_Session_Tokens ────────────────
r = subprocess.run(["sudo", "-n", "-u", "looth-dev", "env", f"LG_SHOT_UID={UID}",
                    "wp", "eval-file", os.path.join(os.path.dirname(os.path.abspath(__file__)),
                                                    "mint-wp-session.php"),
                    "--skip-themes", "--path=/var/www/dev"],
                   capture_output=True, text=True)
line = [l for l in r.stdout.splitlines() if l.strip().startswith("{")]
assert line, f"session mint failed: {r.stdout[-300:]} {r.stderr[-300:]}"
sess = json.loads(line[0])
g = subprocess.run(["sudo", "-n", "grep", "-oP", r'loothdev_token\s+"\K[^"]+',
                    "/etc/nginx/snippets/loothdev-tokens.conf"], capture_output=True, text=True)
tok = (g.stdout.strip().splitlines() or [""])[0]
assert tok, "no dev gate token"
print(f"session: {sess['user']} ({sess['name']})")

# ── one persistent connection (per-command sockets drop device emulation) ────
req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
tgt = json.loads(urllib.request.urlopen(req, timeout=10).read())
ws  = websocket.create_connection(tgt["webSocketDebuggerUrl"], timeout=30, suppress_origin=True)
N = [0]

def send(method, params=None):
    N[0] += 1
    ws.send(json.dumps({"id": N[0], "method": method, "params": params or {}}))
    while True:
        m = json.loads(ws.recv())
        if m.get("id") == N[0]:
            if "error" in m: raise RuntimeError(f"{method}: {m['error']}")
            return m.get("result", {})

def ev(expr):
    return send("Runtime.evaluate", {"expression": expr, "returnByValue": True}) \
             .get("result", {}).get("value")

send("Page.enable"); send("Runtime.enable"); send("Network.enable")
send("Security.setIgnoreCertificateErrors", {"ignore": True})

# ⚠️ CLEAR FIRST. Network.setCookies ADDS a host-only cookie beside any dotted
# one already in the shared profile, and WordPress then reads whichever it likes
# — which has already produced a run executing as a DIFFERENT member
# (trap-shared-chrome-profile-duplicate-session-cookies).
send("Network.clearBrowserCookies")
send("Network.setCookies", {"cookies": [
    {"name": sess["cookie"], "value": sess["value"], "domain": "dev2.loothgroup.com", "path": "/"},
    {"name": "loothdev_auth", "value": tok, "domain": ".dev2.loothgroup.com", "path": "/"},
]})

# ── remember the shared profile's theme so this run can put it back ──────────
send("Page.navigate", {"url": BASE + "/header/"}); time.sleep(2.0)
PRIOR = ev("localStorage.getItem('lg-set-theme')")
print(f"shared profile theme was: {PRIOR!r} (will be restored)")

VIEWS  = [("1440", 1440, 900, False), ("821", 821, 900, False),
          ("640", 640, 900, True),    ("390", 390, 844, True)]
THEMES = ["light", "dark"]

def shoot(url, name, opener, target_js, want_text):
    """opener: JS run before measuring (opens the account menu). target_js: the
    element under test. Returns a row of findings."""
    ev(f"localStorage.setItem('lg-set-theme','{'dark' if theme=='dark' else 'light'}'); true")
    send("Page.navigate", {"url": url}); time.sleep(2.6)
    # ⚠️ 0.5s WAS NOT ENOUGH AND THE SHORT READ INVENTED A DEFECT. The account
    # dropdown animates open, so a rect taken mid-transition reported the Switch
    # item at x=-1 and inView=false at 640 and 390 — a lockout that does not
    # exist (measured directly, settled, it is x=88 at every width). A visual
    # gate that reads a moving element manufactures findings; wait for it to
    # stop, then assert the position is stable across two reads.
    if opener:
        ev(opener); time.sleep(1.4)
    applied = ev("document.documentElement.getAttribute('data-lguser-theme') || 'default'")
    # LIVENESS: a locked-out browser serves a styled 403 that is identical in
    # both themes at every width and photographs as a clean pass having measured
    # nothing (trap-locked-out-browser-goes-vacuously-green).
    live = ev("!!document.querySelector('.lg-chrome__account, .lg-chrome__join') "
              "&& !/isn.t available yet|Admin only/.test(document.body.innerText)")
    found = ev(f"""(() => {{
      const t = {target_js};
      if (!t) return JSON.stringify({{state:'MISSING'}});
      t.scrollIntoView({{block:'center'}});
      const r = t.getBoundingClientRect();
      const cx = r.left + r.width/2, cy = r.top + r.height/2;
      const el = document.elementFromPoint(cx, cy);
      const inView = r.left >= 0 && r.top >= 0
                  && r.right  <= (window.innerWidth  || document.documentElement.clientWidth)
                  && r.bottom <= (window.innerHeight || document.documentElement.clientHeight);
      return JSON.stringify({{
        state: 'FOUND', text: (t.textContent||'').trim(), href: t.getAttribute('href'),
        w: Math.round(r.width), h: Math.round(r.height),
        x: Math.round(r.left), inView,
        scrollWidth: document.documentElement.scrollWidth,
        hit: !el ? 'nothing-at-point'
             : (t.contains(el) || el === t) ? 'REACHABLE'
             : 'BLOCKED by ' + (el.id || el.className || el.tagName)
      }});
    }})()""")
    f = json.loads(found) if found else {"state": "eval-failed"}
    if f.get("state") == "FOUND":
        time.sleep(0.4)
        again = ev(f"""(() => {{
          const t = {target_js}; if (!t) return -99999;
          return Math.round(t.getBoundingClientRect().left);
        }})()""")
        f["stable"] = (again == f.get("x"))
    time.sleep(0.3)
    img = send("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": False})
    open(f"{OUT}/{name}.png", "wb").write(base64.b64decode(img["data"]))
    ok = (f.get("state") == "FOUND" and f.get("text") == want_text
          and f.get("hit") == "REACHABLE" and f.get("inView") is True
          and f.get("stable") is True and live)
    print(f"  {name:28} theme={applied:8} live={live} {f}")
    return (name, ok, applied, live, f)

rows = []
try:
    for vname, w, h, mobile in VIEWS:
        for theme in THEMES:
            send("Emulation.setDeviceMetricsOverride",
                 {"width": w, "height": h, "deviceScaleFactor": 2, "mobile": mobile})
            # THE MENU — the issue's own control.
            rows.append(shoot(
                BASE + "/header/", f"menu-{vname}-{theme}",
                "(()=>{const b=document.querySelector('[data-lg-account-btn]');"
                " if(b) b.click(); return !!b;})()",
                "document.querySelector('#lg-account-menu a[href=\\\"/switch-billing/\\\"]')",
                "Switch"))
            # THE PILL — same control, drawn beside the chip in 'allowlist'.
            rows.append(shoot(
                BASE + "/header/", f"pill-{vname}-{theme}", None,
                "document.querySelector('.lg-chrome__join')", "Switch"))
            # THE PAGE — its first CTA is what the sequencing hangs on.
            rows.append(shoot(
                BASE + "/switch-billing/", f"page-{vname}-{theme}", None,
                "document.querySelector('.lg-switch__step .lg-switch__cta')",
                "Manage your pledge on Patreon →"))
finally:
    # Put the shared profile back exactly as it was found.
    send("Emulation.setDeviceMetricsOverride",
         {"width": 1440, "height": 900, "deviceScaleFactor": 1, "mobile": False})
    send("Page.navigate", {"url": BASE + "/header/"}); time.sleep(1.5)
    if PRIOR is None:
        ev("localStorage.removeItem('lg-set-theme'); localStorage.removeItem('lg-set-boot'); true")
    else:
        ev(f"localStorage.setItem('lg-set-theme', {json.dumps(PRIOR)}); true")
    print(f"shared profile theme restored to: {PRIOR!r}")
    ws.close()

print("\n--- summary ---")
bad = [r for r in rows if not r[1]]
for name, ok, applied, live, f in rows:
    print(f"{name:28} {'OK ' if ok else 'BAD'} theme={applied:8} live={live} "
          f"text={f.get('text')!r} href={f.get('href')} x={f.get('x')} "
          f"inView={f.get('inView')} hit={f.get('hit')}")
print(f"\n{len(rows)-len(bad)}/{len(rows)} good; shots in {OUT}")
sys.exit(1 if bad else 0)
