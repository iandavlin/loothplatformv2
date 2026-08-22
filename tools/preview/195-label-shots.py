#!/usr/bin/env python3
"""#195 — photograph the View-as switcher wherever a member can actually reach it.

THREE surfaces carry a Public/Member/Me switcher; only two of them are reachable:

  /u/<slug>?view=me   the privacy panel  — the control Ian named
  /p/<slug>?view=me   the practice page  — same three positions, different panel
  /profile/edit       the SSR editor     — UNREACHABLE (edit.php 302s any member
                      with a slug to /u/<slug>, and every slug-less row on dev2
                      AND live is unclaimed). Not shot; measured instead.

⚠️ THE LABEL IS NOT THE VALUE. Every consumer keys on the string "me" — ?view=me,
$role==='me', data-role="me", BOOT.role||'me'. Nothing anywhere reads the visible
text. So this run records the seg's textContent AND its hrefs together: a shot
that shows "Edit" over a href that still says ?view=me is the pass, and one where
the href moved is the regression.

⚠️ THE SHARED PROFILE IS RESTORED AT THE END (trap-chrome-dev-profile-persists-
dark-theme). chrome-dev has one user-data-dir for the whole box.

⚠️ LIVENESS, not just presence. chrome-dev resolves dev2 to the INTERNAL ip and
the box-local gate allows loopback only, so a lost cookie serves a styled 403
that is identical in both themes at every width and photographs as a clean pass
having measured nothing (trap-locked-out-browser-goes-vacuously-green).

Usage:  195-label-shots.py before|after [--base URL]
"""
import base64, json, os, subprocess, sys, time
import urllib.request
import websocket

PHASE = (sys.argv[1] if len(sys.argv) > 1 else "before").strip()
assert PHASE in ("before", "after"), "usage: 195-label-shots.py before|after [--base URL]"

BASE = "https://dev2.loothgroup.com"
if "--base" in sys.argv:
    BASE = sys.argv[sys.argv.index("--base") + 1].rstrip("/")

OUT  = f"/home/ubuntu/projects/footer-mockups/195-edit-label/{PHASE}"
CDP  = "http://127.0.0.1:9222"
UID  = 1                                   # WP iandavlin -> profile user 4
U_SLUG = "ian-davlin-the-looth-group"
P_SLUG = "the-looth-group"                 # the practice he owns

# What the seg should read in each phase. The VALUES never move.
WANT = {"before": ["Public", "Member", "Me"],
        "after":  ["Public", "Member", "Edit"]}[PHASE]
WANT_HREFS = ["view=public", "view=member", "view=me"]

os.makedirs(OUT, exist_ok=True)
HERE = os.path.dirname(os.path.abspath(__file__))

# ── cookies: a REAL session, minted through WP_Session_Tokens ────────────────
r = subprocess.run(["sudo", "-n", "-u", "looth-dev", "env", f"LG_SHOT_UID={UID}",
                    "wp", "eval-file", os.path.join(HERE, "mint-wp-session.php"),
                    "--skip-themes", "--path=/var/www/dev"],
                   capture_output=True, text=True)
line = [l for l in r.stdout.splitlines() if l.strip().startswith("{")]
assert line, f"session mint failed: {r.stdout[-300:]} {r.stderr[-300:]}"
sess = json.loads(line[0])
g = subprocess.run(["sudo", "-n", "grep", "-oP", r'loothdev_token\s+"\K[^"]+',
                    "/etc/nginx/snippets/loothdev-tokens.conf"], capture_output=True, text=True)
tok = (g.stdout.strip().splitlines() or [""])[0]
assert tok, "no dev gate token"
print(f"phase={PHASE}  base={BASE}  session: {sess['user']} ({sess['name']})")

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

# ⚠️ CLEAR FIRST — Network.setCookies ADDS beside a dotted duplicate and the run
# then executes as a different member (trap-shared-chrome-profile-duplicate-
# session-cookies). Gate cookie DOTTED, WP cookie host-only.
send("Network.clearBrowserCookies")
send("Network.setCookies", {"cookies": [
    {"name": sess["cookie"], "value": sess["value"], "domain": "dev2.loothgroup.com", "path": "/"},
    {"name": "loothdev_auth", "value": tok, "domain": ".dev2.loothgroup.com", "path": "/"},
]})

send("Page.navigate", {"url": BASE + "/u/" + U_SLUG + "?view=me"}); time.sleep(2.5)
PRIOR = ev("localStorage.getItem('lg-set-theme')")
print(f"shared profile theme was: {PRIOR!r} (will be restored)")

VIEWS  = [("desktop-1440", 1440, 900, False), ("phone-390", 390, 844, True)]
THEMES = ["light", "dark"]

PROBE = """(() => {
  const box = document.querySelector('.lg-viewas');
  if (!box) return JSON.stringify({state:'NO PANEL'});
  const seg = box.querySelector('.lg-viewas__seg');
  if (!seg) return JSON.stringify({state:'NO SEG'});
  const links = [...seg.querySelectorAll('a')];
  const me = links.find(a => (a.getAttribute('href')||'').includes('view=me'));
  seg.scrollIntoView({block:'center'});
  const r = me ? me.getBoundingClientRect() : null;
  let hit = null, inView = null;
  if (r) {
    const el = document.elementFromPoint(r.left + r.width/2, r.top + r.height/2);
    hit = !el ? 'nothing-at-point'
          : (me.contains(el) || el === me) ? 'REACHABLE'
          : 'BLOCKED by ' + (el.id || el.className || el.tagName);
    inView = r.left >= 0 && r.top >= 0
          && r.right  <= (window.innerWidth  || document.documentElement.clientWidth)
          && r.bottom <= (window.innerHeight || document.documentElement.clientHeight);
  }
  const bb = box.getBoundingClientRect();
  return JSON.stringify({
    state:'FOUND',
    texts: links.map(a => (a.textContent||'').trim()),
    hrefs: links.map(a => (a.getAttribute('href')||'').split('?')[1] || ''),
    current: links.filter(a => a.getAttribute('aria-current') === 'true')
                  .map(a => (a.textContent||'').trim()),
    groupLabel: box.getAttribute('aria-label'),
    rowLabel: (box.querySelector('.lg-viewas__lbl, .lg-viewas__label')||{}).textContent || null,
    /* the /p/ row carries a SECOND control already called "Edit profile" — record
       it so a collision is visible in the data, not only in the picture. */
    neighbours: [...box.querySelectorAll('a,button')]
                  .map(n => (n.textContent||'').trim())
                  .filter(t => t && !links.some(a => (a.textContent||'').trim() === t)),
    segW: Math.round(seg.getBoundingClientRect().width),
    meW: r ? Math.round(r.width) : null,
    hit, inView,
    docScrollW: document.documentElement.scrollWidth,
    clip: {x: Math.max(0, Math.round(bb.left) - 12), y: Math.max(0, Math.round(bb.top) - 12),
           w: Math.round(bb.width) + 24, h: Math.round(bb.height) + 24}
  });
})()"""

def shoot(url, name):
    ev(f"localStorage.setItem('lg-set-theme','{theme}'); true")
    send("Page.navigate", {"url": url}); time.sleep(2.8)
    applied = ev("document.documentElement.getAttribute('data-lguser-theme') || 'default'")
    # LIVENESS — the owner's own editable page, not a styled 403 and not the
    # members gate. An absence assertion without one of these is vacuous.
    live = ev("!!document.querySelector('.lg-shell--owner') "
              "&& !document.querySelector('.lg-gate') "
              "&& !/isn.t available yet|Admin only|Forbidden/.test(document.body.innerText)")
    f = json.loads(ev(PROBE) or '{"state":"eval-failed"}')

    img = send("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": False})
    open(f"{OUT}/{name}.png", "wb").write(base64.b64decode(img["data"]))
    if f.get("state") == "FOUND":
        c = f["clip"]
        crop = send("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": True,
                    "clip": {"x": c["x"], "y": c["y"], "width": c["w"],
                             "height": c["h"], "scale": 2}})
        open(f"{OUT}/{name}-panel.png", "wb").write(base64.b64decode(crop["data"]))

        # ⚠️ THE ADJACENCY IS THE THING IAN IS RULING ON, so it gets its own
        # frame rather than being something he has to notice in a full page.
        # Renaming 'Me' to 'Edit' puts a SECOND Edit on each surface:
        #   /p/  the row's own "Edit profile" chip — EVERY practice owner
        #   /u/  the header's .lg-chrome__edit pill (aria-label "WP Admin",
        #        href /wp-admin/) — manage_options only, so ADMINS, Ian included
        # One crop from the top of the page through the bottom of the panel
        # holds both in a single picture. Keeper, 8/22: hiding the collision
        # would be worse than the collision.
        both = ev("""(() => {
          const box = document.querySelector('.lg-viewas');
          const other = document.querySelector('.lg-chrome__edit, .lg-viewas__edit');
          if (!box) return null;
          const b = box.getBoundingClientRect();
          const o = other ? other.getBoundingClientRect() : null;
          const top = o ? Math.min(b.top, o.top) : b.top;
          const bot = o ? Math.max(b.bottom, o.bottom) : b.bottom;
          return JSON.stringify({
            y: Math.max(0, Math.round(top + window.scrollY) - 14),
            h: Math.round(bot - top) + 28,
            other: other ? (other.textContent||'').trim() : null,
            otherCls: other ? other.className : null
          });
        })()""")
        if both:
            b = json.loads(both)
            shot = send("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": True,
                        "clip": {"x": 0, "y": b["y"],
                                 "width": max(1, f["clip"]["w"] + f["clip"]["x"] + 40),
                                 "height": b["h"], "scale": 2}})
            open(f"{OUT}/{name}-BOTH-EDITS.png", "wb").write(base64.b64decode(shot["data"]))
            f["other_edit"] = b.get("other")
            f["other_edit_cls"] = b.get("otherCls")

    ok = (f.get("state") == "FOUND"
          and f.get("texts") == WANT
          and all(want in got for want, got in zip(WANT_HREFS, f.get("hrefs", [])))
          and f.get("hit") == "REACHABLE" and f.get("inView") is True
          and live)
    print(f"  {name:34} theme={applied:8} live={live} ok={ok}")
    print(f"    texts={f.get('texts')} hrefs={f.get('hrefs')} current={f.get('current')}")
    print(f"    rowLabel={f.get('rowLabel')!r} group={f.get('groupLabel')!r} "
          f"neighbours={f.get('neighbours')} hit={f.get('hit')} inView={f.get('inView')} "
          f"segW={f.get('segW')} docScrollW={f.get('docScrollW')}")
    return {"name": name, "ok": ok, "theme": applied, "live": live, **f}

rows = []
try:
    for vname, w, h, mobile in VIEWS:
        for theme in THEMES:
            send("Emulation.setDeviceMetricsOverride",
                 {"width": w, "height": h, "deviceScaleFactor": 2, "mobile": mobile})
            rows.append(shoot(f"{BASE}/u/{U_SLUG}?view=me", f"u-{vname}-{theme}"))
            rows.append(shoot(f"{BASE}/p/{P_SLUG}?view=me", f"p-{vname}-{theme}"))
finally:
    send("Emulation.setDeviceMetricsOverride",
         {"width": 1440, "height": 900, "deviceScaleFactor": 1, "mobile": False})
    if PRIOR is None:
        ev("localStorage.removeItem('lg-set-theme'); true")
    else:
        ev(f"localStorage.setItem('lg-set-theme','{PRIOR}'); true")
    back = ev("localStorage.getItem('lg-set-theme')")
    print(f"shared profile theme restored to: {back!r} (was {PRIOR!r})")
    try:
        urllib.request.urlopen(CDP + "/json/close/" + tgt["id"], timeout=5).read()
    except Exception:
        pass
    ws.close()

json.dump(rows, open(f"{OUT}/findings.json", "w"), indent=1)
bad = [r["name"] for r in rows if not r["ok"]]
print(f"\n{PHASE}: {len(rows)-len(bad)}/{len(rows)} clean")
if bad: print("NOT CLEAN:", ", ".join(bad))
sys.exit(1 if bad else 0)
