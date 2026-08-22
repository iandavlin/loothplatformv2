#!/usr/bin/env python3
"""#200-B — photograph the featured band BEFORE and AFTER Ian's ruling.

Ian, 2026-08-22, shown both empty states drawn side by side: "B is fine for
featured. We haven't even announced it as a feature." B is the 'invite' shape.
This run is the evidence that B is what the front page now actually draws.

WHAT IT SHOOTS, AND WHY IT IS NOT A MOCK. #202's lane learned it the expensive
way and #172 before it: only the picture catches some defects. So this does not
draw a picture of the card — it RENDERS THIS BRANCH'S REAL index.php in the
no-pick state, twice, once per shape, and photographs the actual band inside the
actual front page. The only input that differs between BEFORE and AFTER is the
one word Ian ruled on.

⚠️ THE SERVE IS MAIN. /srv/archive-poc symlinks into the serving checkout, so a
plain fetch of dev2's front page photographs main, and main also has a real pick
so it would never draw a fallback at all. Both pages here are rendered by
php-cli against this worktree and published as static HTML — the stylesheet they
load is the serve's, which was verified byte-identical to this branch's for
every .lg-fm rule, so the picture is faithful and nothing on the serve is
touched.

⚠️ THEME, THE FOUR TRAPS THIS BOX HAS ALREADY PAID FOR:
  1. NEVER write lg-set-theme to localStorage — the chrome profile is SHARED and
     that takes every other lane's browser dark. This run reads it only, and
     asserts on exit that it did not change.
  2. Stamping data-lguser-theme is enough HERE and is not in general: archive.css
     defines its own dark block under html[data-lguser-theme="dark"] with literal
     fallbacks, so the tokens really do move. On a surface whose dark values come
     from app-settings.js re-pointing --lg-* inline, it would photograph a light
     page wearing a dark attribute.
  3. ASSERT THE DELTA. Light and dark must compute DIFFERENT card backgrounds, or
     it is one theme photographed twice.
  4. LIVENESS. A locked-out browser serves a styled 403 that is identical in both
     themes at every width and photographs perfectly clean.

⚠️ CLIP TO THE BAND, not the page. A full-page phone capture here is ~4,900px
tall and is unreadable as a before/after (#202 shipped one and had to redo it).
The clip is the band's own rect plus context, in PAGE coordinates.
"""
import base64, io, json, os, shutil, subprocess, sys, time
import urllib.request
import websocket

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFS = os.path.join(REPO, "archive-poc", "web", "defaults.php")
IDX  = os.path.join(REPO, "archive-poc", "web", "index.php")
PUB  = "/home/ubuntu/projects/footer-mockups/200-featured-b"
REPO_COPY = os.path.join(REPO, "footer-mockups", "200-featured-b")
BASE = "https://dev2.loothgroup.com/footer-mockups/200-featured-b"
CDP  = "http://127.0.0.1:9222"
RED  = []

os.makedirs(PUB, exist_ok=True)
os.makedirs(REPO_COPY, exist_ok=True)


# ── 1. RENDER BOTH SHAPES FROM THE REAL BRANCH CODE ─────────────────────────
def render_to(path, kind):
    """Render the no-pick front page with the fallback forced to `kind`.

    ⚠️ SNAPSHOT AND RESTORE BY COPY, NEVER `git checkout --`, which would wipe
    uncommitted work in the same file (feedback-mutation-harness-must-snapshot).
    defaults.php is put back in a finally whatever happens.
    """
    original = io.open(DEFS, encoding="utf-8").read()
    want = "'kind'      => '%s'," % kind
    have = "'kind'      => 'invite',"
    try:
        if kind != "invite":
            assert original.count(have) == 1, "the shipped kind is not where this expects it"
            io.open(DEFS, "w", encoding="utf-8").write(original.replace(have, want, 1))
        tmp = "/tmp/fm200b-%s" % kind
        os.makedirs(tmp, exist_ok=True)
        os.chmod(tmp, 0o755)
        cfg = json.load(open("/home/ubuntu/projects/archive-poc/config.json"))
        # {enabled:true} with no member_uuid IS the no-pick state — exactly what
        # the dash's "Clear this pick" writes, and the state Ian's front page was
        # in when he reported the hole.
        cfg["featured_member"] = {"enabled": True}
        json.dump(cfg, open(os.path.join(tmp, "config.json"), "w"))
        open(os.path.join(tmp, "render.php"), "w").write(
            "<?php define('LG_ARCHIVE_POC_CONFIG_JSON', %r);\nrequire %r;\n"
            % (os.path.join(tmp, "config.json"), IDX))
        os.chmod(os.path.join(tmp, "config.json"), 0o644)
        os.chmod(os.path.join(tmp, "render.php"), 0o644)
        p = subprocess.run(["sudo", "-n", "-u", "archive-poc", "php",
                            os.path.join(tmp, "render.php")],
                           capture_output=True, text=True, timeout=120)
        html = p.stdout
        if len(html) < 20000:
            RED.append("[render] the %s page came back %d bytes — a short render is the "
                       "vacuous-green case, not a page" % (kind, len(html)))
        io.open(path, "w", encoding="utf-8").write(html)
        return html
    finally:
        io.open(DEFS, "w", encoding="utf-8").write(original)


before_html = render_to(os.path.join(PUB, "before.html"), "member")
after_html  = render_to(os.path.join(PUB, "after.html"),  "invite")

# Prove the swap actually restored — the whole run is worthless if it did not.
if "'kind'      => 'invite'," not in io.open(DEFS, encoding="utf-8").read():
    print("FATAL: defaults.php was not restored to the ruled shape"); sys.exit(2)

print("rendered  before=%d bytes  after=%d bytes" % (len(before_html), len(after_html)))
for label, html, want in (("before", before_html, "Dan Erlewine"),
                          ("after",  after_html,  "This spot is open")):
    if want not in html:
        RED.append("[render] the %s page does not contain %r — it is not the shape it claims"
                   % (label, want))


# ── 2. CDP ──────────────────────────────────────────────────────────────────
g = subprocess.run(["sudo", "-n", "grep", "-oP", r'loothdev_token\s+"\K[^"]+',
                    "/etc/nginx/snippets/loothdev-tokens.conf"], capture_output=True, text=True)
tok = (g.stdout.strip().splitlines() or [""])[0]
assert tok, "no dev gate token"

req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
tgt = json.loads(urllib.request.urlopen(req, timeout=10).read())
ws = websocket.create_connection(tgt["webSocketDebuggerUrl"], timeout=45, suppress_origin=True)
N = [0]


def send(method, params=None):
    N[0] += 1
    ws.send(json.dumps({"id": N[0], "method": method, "params": params or {}}))
    while True:
        m = json.loads(ws.recv())
        if m.get("id") == N[0]:
            if "error" in m:
                raise RuntimeError("%s: %s" % (method, m["error"]))
            return m.get("result", {})


def ev(expr):
    return send("Runtime.evaluate", {"expression": expr, "returnByValue": True}) \
             .get("result", {}).get("value")


send("Page.enable"); send("Runtime.enable"); send("Network.enable")
send("Security.setIgnoreCertificateErrors", {"ignore": True})
# Clear first: setCookies ADDS beside a dotted twin rather than replacing it, and
# the gate cookie needs the LEADING DOT on this box.
send("Network.clearBrowserCookies")
send("Network.setCookies", {"cookies": [
    {"name": "loothdev_auth", "value": tok, "domain": ".dev2.loothgroup.com", "path": "/"},
]})

send("Page.navigate", {"url": BASE + "/after.html"}); time.sleep(2.0)
PRIOR = ev("localStorage.getItem('lg-set-theme')")
print("shared profile theme is %r — this run only reads it" % (PRIOR,))

SHAPE = {"before": "Dan Erlewine", "after": "This spot is open"}
seen = {}

for variant in ("before", "after"):
    for wname, w, h, mobile in (("1440", 1440, 1000, False), ("390", 390, 844, True)):
        for theme in ("light", "dark"):
            send("Emulation.setDeviceMetricsOverride",
                 {"width": w, "height": h, "deviceScaleFactor": 2 if mobile else 1,
                  "mobile": mobile, "screenWidth": w, "screenHeight": h})
            send("Emulation.setEmulatedMedia",
                 {"features": [{"name": "prefers-color-scheme", "value": theme}]})
            send("Page.navigate", {"url": "%s/%s.html" % (BASE, variant)})
            time.sleep(2.6)
            # Force the attribute AFTER load: app-settings.js is injected into
            # every text/html response on this box and stamps from the shared
            # profile, so whatever it decided has to be overwritten here. In-page
            # only — localStorage is never written.
            ev("document.documentElement.setAttribute('data-lguser-theme', %r)" % theme)
            time.sleep(0.5)

            probe = ev("""(() => {
              const b = document.querySelector('.row--featured-member');
              if (!b) return JSON.stringify({state:'NO BAND'});
              const c = b.querySelector('.lg-fm');
              const cta = b.querySelector('.lg-fm__cta');
              const r = b.getBoundingClientRect();
              const img = b.querySelector('.lg-fm__avi img');
              return JSON.stringify({
                state:'OK',
                bg: getComputedStyle(c).backgroundColor,
                ink: getComputedStyle(b.querySelector('.lg-fm__name')).color,
                name: (b.querySelector('.lg-fm__name')||{}).innerText || '',
                cta: cta ? cta.getAttribute('href') : null,
                ctaText: cta ? cta.innerText : null,
                empty: c.classList.contains('lg-fm--empty'),
                brokenImg: !!img && img.naturalWidth === 0,
                x: r.x + scrollX, y: r.y + scrollY, w: r.width, h: r.height,
                theme: document.documentElement.getAttribute('data-lguser-theme'),
                live: /Looth/.test(document.body.innerText) && document.body.innerText.length > 400
              });
            })()""")
            d = json.loads(probe)
            key = "%s-%s-%s" % (variant, wname, theme)
            if d.get("state") != "OK":
                RED.append("[%s] no featured band in the page at all" % key)
                continue
            if not d.get("live"):
                RED.append("[%s] liveness failed — this is what a styled 403 looks like" % key)
            if SHAPE[variant] not in d.get("name", ""):
                RED.append("[%s] the band says %r, expected the %s shape (%r)"
                           % (key, d.get("name"), variant, SHAPE[variant]))
            if d.get("brokenImg"):
                RED.append("[%s] the card's avatar is a broken image — a hole in the card "
                           "under test, and the craft gate cannot see one" % key)
            seen[key] = d

            # PAGE-coordinate clip, with context above and below so the band is
            # shown in the page rather than floating.
            pad = 28
            clip = {"x": max(0, d["x"] - pad), "y": max(0, d["y"] - pad),
                    "width": min(w, d["w"] + pad * 2), "height": d["h"] + pad * 2,
                    "scale": 1}
            img = send("Page.captureScreenshot",
                       {"format": "png", "captureBeyondViewport": True, "clip": clip})
            out = os.path.join(PUB, "band-%s.png" % key)
            open(out, "wb").write(base64.b64decode(img["data"]))
            print("  band-%s.png  %s  cta=%s  bg=%s" % (key, d["name"][:28], d["cta"], d["bg"]))

# ── 3. THE ASSERTIONS THAT MAKE THE PICTURES MEAN SOMETHING ─────────────────
for variant in ("before", "after"):
    for wname in ("1440", "390"):
        l = seen.get("%s-%s-light" % (variant, wname))
        k = seen.get("%s-%s-dark" % (variant, wname))
        if not l or not k:
            continue
        if l["bg"] == k["bg"] and l["ink"] == k["ink"]:
            RED.append("[%s %s] light and dark computed the SAME card colours (%s) — one theme "
                       "photographed twice, which is how a dark-mode defect ships unseen"
                       % (variant, wname, l["bg"]))

for key, d in seen.items():
    if key.startswith("after") and not d.get("empty"):
        RED.append("[%s] the after card is missing .lg-fm--empty — it is not variant B" % key)
    if key.startswith("after") and d.get("cta") in (None, "", "#", "/u/"):
        RED.append("[%s] variant B's button points at %r, which is the dead one" % (key, d.get("cta")))

send("Emulation.clearDeviceMetricsOverride")
send("Emulation.setEmulatedMedia", {"features": []})
AFTER_THEME = ev("localStorage.getItem('lg-set-theme')")
if AFTER_THEME != PRIOR:
    RED.append("this run changed the SHARED profile theme %r -> %r" % (PRIOR, AFTER_THEME))
print("shared profile theme after: %r" % (AFTER_THEME,))
ws.close()

# ── 4. COMMIT A COPY TO THE BRANCH (charter) ────────────────────────────────
for f in os.listdir(PUB):
    if f.endswith(".png") or f.endswith(".html"):
        shutil.copy2(os.path.join(PUB, f), os.path.join(REPO_COPY, f))

if RED:
    print("\nRED:")
    for r in RED:
        print("  " + r)
    sys.exit(1)
print("\nGREEN — both shapes rendered, both themes differ, both widths, "
      "shared profile untouched")
