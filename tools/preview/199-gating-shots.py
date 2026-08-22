#!/usr/bin/env python3
"""#199 — photograph the Loothprint gate: before, after, and after-as-a-member.

Ian, 2026-08-22, on a member's live Loothprint viewed logged OUT: "The gating is
off too. We only need to gate the file download and it shouldn't look like the
video gate. I think there is a block for that already available."

THREE STATES, and each is a real page over real nginx through the real
archive-poc FPM pool. Nothing is stubbed and nothing is a mock.

  before   /loothprint/lane199-cleanup-stik-recreation/     main's code, main's blob
  after    /preview/199-loothprint-gating/…/lane199-after/  this branch, flag ON
  member   the same after page, signed in as looth2

⚠️ TWO POSTS, NOT ONE. The standalone renderer reads a MATERIALIZED BLOB baked by
whichever synthesizer ran at save time, so the flag alone cannot turn one page
from before into after — the before blob says `callout`, the after blob says
`download`. The two posts carry byte-identical inputs (same photos, same ZIP,
same video, same tier) so the only difference between the pages is code + flag.

⚠️ looth1 IS NOT A PAYING TIER. TierResolver::ROLE_TIERS maps looth1 -> 'public';
looth2 is looth-lite. Signing in as a looth1 member and finding the gate still up
is the feature working, not a defect — and it is the obvious mistake to make,
which is why the uid here is asserted to resolve to looth2. #186 already found a
gate whose "member" was really an administrator.

⚠️ EVERY SHOT IS ASSERTED, NOT JUST TAKEN. A styled 403 from a lost dev-gate
cookie is identical at every width in both themes, so a visual run can pass
having photographed nothing (trap-locked-out-browser-goes-vacuously-green). Each
shot re-reads the gate-card count, the eyebrow and the tile count out of the live
DOM and the run fails loudly if a page did not arrive.

⚠️ THE SHARED PROFILE IS RESTORED AT THE END. chrome-dev has one user-data-dir
for the whole box and lg-set-theme persists in it, so a run that leaves 'dark'
behind turns every other lane's "light" screenshot dark.
"""
import base64, json, os, subprocess, sys, time
import urllib.request
import websocket

OUT  = "/home/ubuntu/projects/footer-mockups/199-loothprint-gating"
HOST = "https://dev2.loothgroup.com"
BEFORE = HOST + "/loothprint/lane199-cleanup-stik-recreation/"
AFTER  = HOST + "/preview/199-loothprint-gating/loothprint/lane199-after/"
CDP  = "http://127.0.0.1:9222"
UID  = 1819          # a real looth2 (= looth-lite) member, NOT an admin

os.makedirs(OUT, exist_ok=True)
HERE = os.path.dirname(os.path.abspath(__file__))


def wp(*args):
    return subprocess.run(["sudo", "-n", "-u", "looth-dev"] + list(args),
                          capture_output=True, text=True)


# ── the member must really be looth2, asserted rather than assumed ───────────
r = wp("wp", "--path=/var/www/dev", "eval",
       f"$u=get_userdata({UID}); echo implode(',', $u->roles);")
roles = (r.stdout or "").strip().splitlines()[-1] if r.stdout else ""
assert "looth2" in roles, f"uid {UID} roles are {roles!r} — need looth2 (looth-lite)"
assert "administrator" not in roles, f"uid {UID} is an ADMIN; that measures the bypass"
print(f"member uid {UID}: roles={roles}")

r = wp("env", f"LG_SHOT_UID={UID}", "wp", "--path=/var/www/dev", "--skip-themes",
       "eval-file", os.path.join(HERE, "mint-wp-session.php"))
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
ws = websocket.create_connection(tgt["webSocketDebuggerUrl"], timeout=45, suppress_origin=True)
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


def ev(expr):
    return send("Runtime.evaluate", {"expression": expr, "returnByValue": True}) \
             .get("result", {}).get("value")


send("Page.enable"); send("Runtime.enable"); send("Network.enable")
send("Security.setIgnoreCertificateErrors", {"ignore": True})

# ⚠️ CLEAR FIRST. Network.setCookies ADDS a host-only cookie beside any dotted one
# already in the shared profile, and WordPress reads whichever it likes — which
# has already produced a run executing as a DIFFERENT member. The dev gate cookie
# is DOTTED, the WP cookie is host-only; that pairing is not interchangeable.
GATE = {"name": "loothdev_auth", "value": tok, "domain": ".dev2.loothgroup.com", "path": "/"}
WPC = {"name": sess["cookie"], "value": sess["value"], "domain": "dev2.loothgroup.com", "path": "/"}


def signed_in(yes):
    send("Network.clearBrowserCookies")
    send("Network.setCookies", {"cookies": [GATE] + ([WPC] if yes else [])})


signed_in(False)
send("Page.navigate", {"url": BEFORE}); time.sleep(2.5)
PRIOR = ev("localStorage.getItem('lg-set-theme')")
print(f"shared profile theme was: {PRIOR!r} (will be restored)")

VIEWS = [("1280", 1280, 900, False), ("390", 390, 844, True)]
STATES = [("1-before-anon", BEFORE, False),
          ("2-after-anon", AFTER, False),
          ("3-after-member", AFTER, True)]

MEASURE = """(() => {
  const q = s => Array.from(document.querySelectorAll(s));
  const gates = q('[data-lg-gate]');
  return {
    ok:          !!document.querySelector('.lg-gallery, .lg-article, article'),
    forbidden:   /403|Forbidden/i.test(document.title || ''),
    gates:       gates.map(g => g.getAttribute('data-lg-gate')),
    eyebrows:    q('.lg-gate-cta__eyebrow').map(e => e.textContent.trim()),
    headline:    (document.querySelector('.lg-gate-cta__headline') || {}).textContent || '',
    tiles:       q('.lg-gallery__tile:not(.lg-gallery__tile--placeholder)').length,
    placeholders:q('.lg-gallery__tile--placeholder').length,
    video:       q('[data-yt-id]').length,
    zipHref:     q('a[href$=".zip"]').length,
    theme:       document.documentElement.getAttribute('data-lguser-theme') || '(none)',
    // ⚠️ THE ATTRIBUTE IS NOT THE THEME. Dark is applied by app-settings.js
    // re-pointing the --lg-* tokens as inline style on <html>; a harness that
    // reads only the attribute photographs a LIGHT page wearing a dark one.
    // These two are the delta the run actually asserts.
    bodyBg:      getComputedStyle(document.body).backgroundColor,
    ink:         getComputedStyle(document.documentElement).getPropertyValue('--lg-ink').trim(),
  };
})()"""

rows, bad = [], []
for theme in ("light", "dark"):
    for vname, w, h, mobile in VIEWS:
        send("Emulation.setDeviceMetricsOverride",
             {"width": w, "height": h, "deviceScaleFactor": 2, "mobile": mobile})
        for sname, url, member in STATES:
            signed_in(member)
            # The theme is applied by app-settings.js re-pointing the --lg-* tokens
            # as inline style on <html>; stamping the attribute alone photographs a
            # LIGHT page wearing a dark attribute. So set the store the app reads
            # and reload, which is what the real toggle does.
            send("Page.navigate", {"url": url}); time.sleep(1.2)
            ev(f"localStorage.setItem('lg-set-theme', {theme!r})")
            send("Page.navigate", {"url": url}); time.sleep(2.6)

            m = ev(MEASURE) or {}
            name = f"{sname}-{theme}-{vname}.png"
            shot = send("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": True})
            with open(os.path.join(OUT, name), "wb") as fh:
                fh.write(base64.b64decode(shot["data"]))

            # Liveness before anything else: a styled 403 looks identical in both
            # themes at every width, so a run can pass having measured nothing.
            if m.get("forbidden") or not m.get("ok"):
                bad.append(f"{name}: page did not arrive (title={m.get('forbidden')})")
            rows.append((name, m))
            print(f"  {name:34s} gates={m.get('gates')} tiles={m.get('tiles')}"
                  f"/ph={m.get('placeholders')} video={m.get('video')} theme={m.get('theme')}")

# ── the claims Ian's ruling makes, asserted on the DOM that was photographed ──
def only(prefix):
    return [m for n, m in rows if n.startswith(prefix)]


# ⚠️ Assert the DELTA, never the absolute value of a shared key. If light and
# dark produced the same body background then app-settings.js did not run and
# every "dark" shot above is a light page — the whole set would be worthless and
# nothing else here would notice.
lights = {m["bodyBg"] for n, m in rows if "-light-" in n}
darks  = {m["bodyBg"] for n, m in rows if "-dark-" in n}
print(f"\ntheme delta: light body {lights} vs dark body {darks}")
if not lights or not darks or lights & darks:
    bad.append(f"the theme never actually moved: light={lights} dark={darks}")

print("\nassertions:")
for label, sel, want in [
    ("before: two gate panels, both the video face", "1-before",
     lambda m: len(m["gates"]) == 2 and set(m["gates"]) == {"embed"}),
    ("before: the ghost tile is there", "1-before", lambda m: m["placeholders"] == 1),
    ("after anon: exactly one gate, the download face", "2-after",
     lambda m: m["gates"] == ["download"]),
    ("after anon: download words", "2-after",
     lambda m: m["eyebrows"] == ["Members-only download"]),
    ("after anon: the video plays", "2-after", lambda m: m["video"] >= 1),
    ("after anon: no zip link", "2-after", lambda m: m["zipHref"] == 0),
    ("after anon: tiles == photos, no placeholder", "2-after",
     lambda m: m["tiles"] == 2 and m["placeholders"] == 0),
    ("member: no gate at all", "3-after", lambda m: m["gates"] == []),
    ("member: the real zip link", "3-after", lambda m: m["zipHref"] >= 1),
]:
    got = only(sel)
    okall = bool(got) and all(want(m) for m in got)
    print(f"  {'ok  ' if okall else 'FAIL'} {label}")
    if not okall:
        bad.append(label)

# ── put the shared profile back exactly as it was ────────────────────────────
send("Page.navigate", {"url": BEFORE}); time.sleep(1.5)
if PRIOR is None:
    ev("localStorage.removeItem('lg-set-theme')")
else:
    ev(f"localStorage.setItem('lg-set-theme', {PRIOR!r})")
print(f"shared profile theme restored to {PRIOR!r}")
send("Emulation.clearDeviceMetricsOverride")
signed_in(False)

print(f"\n{len(rows)-len(bad)}/{len(rows)} shots good; written to {OUT}")
if bad:
    print("PROBLEMS:")
    for b in bad:
        print("  -", b)
sys.exit(1 if bad else 0)
