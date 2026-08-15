#!/usr/bin/env python3
"""
craft-gate.py — the mechanical web-craft checkpoint (docs/CRAFT-STANDARD.md).

Loads the key surfaces in the REAL browser (chrome-dev CDP) as anon AND
member, and FAILS on the 90s-basics this project has re-discovered for
weeks:

  IMG-OVERSIZE   image ships >1.7x its rendered pixels (and wastes >40KB)
  IMG-RAW        same-origin content image bypasses /img.php (raw uploads)
  IMG-NODIMS     cover/card image without width+height attrs (layout shift)
  PAGE-IMG-BUDGET image transfer for the page exceeds 1.5 MB
  PAGE-BUDGET    total transfer exceeds 2.5 MB
  EAGER-EDITOR   editor/composer JS (quill) loaded for a viewer who can't post

Usage:  python3 tools/gates/craft-gate.py            (all pages, both viewers)
        python3 tools/gates/craft-gate.py --page hub  (substring filter)
Exit 0 = GREEN. Non-zero = violations (one line each) — fix or justify
in docs/CRAFT-STANDARD.md before pushing. Run via tools/gates/run-all.sh.

Run as ubuntu on dev (mints viewer cookies via sudo, drives chrome-dev:9222).
"""

import asyncio
import json, subprocess, sys, urllib.request, urllib.parse

try:
    import websockets
except ImportError:
    sys.exit("python3-websockets required (it's installed system-wide on dev)")

import os


def gate_env():
    """Host / domain / token from the ONE resolver (tools/gates/gate-env.sh).

    Never hardcode them here again: this module's `dev2.loothgroup.com` pair is
    what took gates 1/2/3/5 down box-wide when the box became dev2 and the
    tokens moved into a box-local nginx include.
    """
    script = os.path.join(os.path.dirname(os.path.abspath(__file__)), "gate-env.sh")
    p = subprocess.run(["bash", script], capture_output=True, text=True)
    if p.returncode != 0:
        sys.exit(p.stderr.strip() or f"gate-env.sh failed ({script})")
    return dict(l.split("=", 1) for l in p.stdout.splitlines() if "=" in l)


ENV  = gate_env()
HOST = ENV["LG_GATE_HOST"]
DOMAIN = ENV["LG_GATE_DOMAIN"]
CDP  = "http://127.0.0.1:9222"
# Chrome resolves its own navigations: the browser on :9222 must have been
# launched with ENV["LG_GATE_CHROME_RESOLVER"] or every page below lands on the
# Cloudflare challenge instead of our origin.

# page → (path, [viewers]) ; add new user-facing surfaces HERE when built.
PAGES = {
    "front":   ("/front-page/",        ["anon", "member"]),
    "hub":     ("/hub/",               ["anon", "member"]),
    "finder":  ("/directory/members",  ["anon", "member"]),
    "weekly":  ("/weekly/",            ["anon"]),
    # The public weekly-email signup page. ANON ONLY on purpose — its entire
    # audience is people without an account. It could only be added here once
    # /weekly-email-sign-up/ was in bp-enable-private-network-public-content:
    # before that it 302'd anonymous visitors to wp-login, and this gate audits
    # an anon viewer, so it would have audited the LOGIN page and reported green.
    # A pass over the wrong document is worse than no gate.
    "wdsignup": ("/weekly-email-sign-up/", ["anon"]),
    "events":  ("/events/",            ["anon"]),
    "profile": ("/u/iandavlin",        ["member"]),
    # Manage Account. MEMBER ONLY on purpose — anon gets a sign-in card, so an
    # anon audit here would measure a different page and report green for it.
    # Added when "Discussions you're following" landed (account-following,
    # 2026-07-30): the section is a list that grows with what a member follows,
    # which is exactly the shape that quietly gains weight over time. Measured at
    # 178KB total / 40KB images with twelve rows rendered.
    "account": ("/manage-subscription/", ["member"]),
}

# Surfaces that only EXIST when a flag is on. CLAUDE.md requires every new content
# surface to be registered here, but a flagged-off route is a 404 — and a 404 is
# light, fast and image-free, so auditing it would PASS and register as coverage
# this gate does not have. That is the same "pass over the wrong document" the
# wdsignup note above was written about, so these are declared separately and
# SKIPPED WITH A REASON until their flag is on, rather than quietly audited.
#
# The flag is READ off the box via the feature's own reader function, never
# assumed, so flipping the default needs no edit here — the surface simply starts
# being audited.
FLAGGED_PAGES = {
    # front-end compose (backlog 6, Ian's Option A). Member-only by construction:
    # anon gets the branded 404, so an anon audit would measure that instead.
    "compose": ("/compose/?type=loothprint", ["member"], "lg_fc_enabled"),
}


def _flag_is_on(reader):
    """
    Ask the feature's own READER FUNCTION off the box. Absent or unreadable =>
    treat as OFF.

    A function, not a constant: front-end compose moved its flag into a shared
    tracked file so bb-mirror's toggle and the WordPress form cannot disagree, and
    a constant check would have reported OFF forever — the page would have skipped
    on every run and this entry would have been pure decoration while looking
    like coverage.
    """
    try:
        r = subprocess.run(
            ["sudo", "-n", "wp", "--allow-root", "--path=/var/www/dev", "eval",
             f"echo function_exists('{reader}') && {reader}() ? 'on' : 'off';"],
            capture_output=True, text=True, timeout=60)
        return r.returncode == 0 and r.stdout.strip().endswith("on")
    except (OSError, subprocess.SubprocessError):
        return False


def active_pages():
    """PAGES, plus any FLAGGED_PAGES whose flag is currently on."""
    out = {k: v for k, v in PAGES.items()}
    for name, (path, viewers, reader) in FLAGGED_PAGES.items():
        if _flag_is_on(reader):
            out[name] = (path, viewers)
        else:
            print(f"  SKIP  {name}: {reader}() is off — surface does not exist yet "
                  f"(auditing its 404 would pass and mean nothing)")
    return out

OVERSIZE_RATIO   = 1.7        # natural px vs rendered px * dpr
OVERSIZE_MIN_KB  = 40         # ignore tiny offenders
IMG_BUDGET_KB    = 1500
PAGE_BUDGET_KB   = 2500
EDITOR_MARKERS   = ["quill"]  # script src substrings that are edit-intent only
DIMS_REQUIRED_RE = ["feed-card__cover", "dir-card", "lg-evland__thumb"]  # class hints


def sh(cmd):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True).stdout.strip()


def gate_token():
    return ENV["LG_GATE_TOKEN"]


def member_cookies():
    # wp-cli as looth-dev, NOT www-data: /etc/looth/live-wp-keys.php is
    # root:looth-dev 0640, so www-data cannot bootstrap WP and this returned ''.
    wpc = sh("sudo -u looth-dev wp --path=/var/www/dev eval "
             "'$e=time()+3600; echo LOGGED_IN_COOKIE.\"=\".urlencode(wp_generate_auth_cookie(1912,$e,\"logged_in\"));'")
    jwt = sh("sudo -u profile-app php /srv/profile-app/bin/mint-dev-token.php 1 | tail -1")
    n, v = wpc.split("=", 1)
    return [(n, urllib.parse.unquote(v)), ("looth_id", jwt)]


COLLECT_JS = """
new Promise(res => setTimeout(() => {
  const dpr = window.devicePixelRatio || 1;
  const imgs = [...document.querySelectorAll('img')].map(i => {
    const r = i.getBoundingClientRect();
    return { src: (i.currentSrc || i.src || '').slice(0, 300),
             nw: i.naturalWidth, rw: Math.round(r.width), dpr,
             cls: (i.className || '').toString().slice(0, 120),
             dims: i.hasAttribute('width') && i.hasAttribute('height'),
             visible: r.width > 20 && r.height > 20 };
  }).filter(i => i.src.startsWith(location.origin) || i.src.startsWith('/'));
  const res2 = performance.getEntriesByType('resource').map(e =>
    ({ url: e.name.slice(0, 300), type: e.initiatorType, kb: Math.round((e.transferSize || 0) / 1024) }));
  const nav = performance.getEntriesByType('navigation')[0];
  res(JSON.stringify({ imgs, res: res2, navKb: Math.round(((nav && nav.transferSize) || 0) / 1024),
                       title: (document.title || '').slice(0, 200),
                       href: (location.href || '').slice(0, 300),
                       bodyLen: (document.body ? document.body.innerHTML.length : 0) }));
}, 6000))
"""


# ── THE GATE MUST NOT AUDIT A DOCUMENT IT DID NOT ASK FOR ────────────────────
# Without --host-resolver-rules the browser resolves our domain over the public
# edge and Cloudflare answers with an interstitial. That page is tiny, carries no
# images and loads no eager scripts, so EVERY check below passes and the gate
# reports GREEN — across every surface at once. A green over Cloudflare is worse
# than a red: it is the gate confidently certifying a page it never saw.
#
# MEASURED 2026-07-30 against the box's managed chrome-dev.service, which does NOT
# carry the resolver flag: /weekly-email-sign-up/ loaded as
# <title>Just a moment...</title> with 0 of our form elements present.
#
# Third occurrence of this class in one day (a ?page_id= probe that served the
# front page; the signup page's iframe framing the front page; now the gate
# itself), so per CRAFT-STANDARD it is encoded rather than remembered.
CHALLENGE_TITLES = ("just a moment", "attention required", "access denied", "checking your browser")


def wrong_document(data, path):
    """Return a reason string if this is not our page, else ''."""
    title = (data.get("title") or "").strip().lower()
    if any(c in title for c in CHALLENGE_TITLES):
        return f"edge challenge page (title {data.get('title')!r}) — chrome resolved {DOMAIN} publicly"
    if data.get("bodyLen", 0) < 500:
        return f"near-empty document ({data.get('bodyLen')} bytes of body) at {data.get('href')}"
    return ""


async def cdp(ws_url, method, params=None, _id=[0]):
    raise RuntimeError  # replaced below; kept simple via session class


# Every CDP round trip is bounded. An untimed recv is why this gate could hang
# FOREVER instead of failing: measured three times on 2026-07-29 (PIDs 168255 and
# 185868 sat at 243s+ with 0s CPU, and a third run produced nothing in 120s) while
# dev2 answered /front-page/ in 0.042s. A hang is worse than a red gate — it is no
# verdict at all, and it holds the box's ONE governed engine until a human notices.
CDP_TIMEOUT = 45


class Tab:
    def __init__(self, ws):
        self.ws = ws
        self.n = 0

    async def send(self, method, params=None):
        self.n += 1
        await self.ws.send(json.dumps({"id": self.n, "method": method, "params": params or {}}))
        while True:
            try:
                msg = json.loads(await asyncio.wait_for(self.ws.recv(), timeout=CDP_TIMEOUT))
            except asyncio.TimeoutError:
                raise RuntimeError(
                    f"CDP timeout after {CDP_TIMEOUT}s waiting for {method}. The engine is up but "
                    "not answering — treat as CANNOT RUN, not as a craft violation.")
            if msg.get("id") == self.n:
                return msg.get("result", {})


async def audit(path, viewer, gate, member):
    # CREATE OUR OWN TAB, and close it again. This gate used to grab the first
    # existing page target — `next(p for p in pages if p["type"] == "page")` —
    # which is whatever happened to be open. On a box where one engine is shared
    # by every lane that meant two craft-gate runs drove the SAME tab, and a
    # lane's own debugging tab could be hijacked mid-session; interleaved command
    # ids on one tab is exactly how the untimed recv above turned into a wedge.
    # A gate must not be able to disturb, or be disturbed by, another consumer.
    tab = json.loads(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT"), timeout=15).read())
    try:
        return await _audit_in(tab, path, viewer, gate, member)
    finally:
        try:
            urllib.request.urlopen(CDP + "/json/close/" + tab["id"], timeout=15).read()
        except Exception:
            pass                                   # never fail the gate on cleanup


async def _audit_in(page, path, viewer, gate, member):
    async with __import__("websockets").connect(page["webSocketDebuggerUrl"], max_size=None) as ws:
        t = Tab(ws)
        await t.send("Network.enable")
        await t.send("Network.setCacheDisabled", {"cacheDisabled": True})   # cold-load truth: a cached run undercounts KB
        await t.send("Network.clearBrowserCookies")
        cookies = [("loothdev_auth", gate)] + (member if viewer == "member" else [])
        for n, v in cookies:
            await t.send("Network.setCookie", {"domain": DOMAIN, "name": n,
                                               "value": v, "path": "/", "secure": True, "httpOnly": True})
        await t.send("Page.navigate", {"url": HOST + path + ("?craftgate=1" if "?" not in path else "&craftgate=1")})
        r = await t.send("Runtime.evaluate", {"expression": COLLECT_JS,
                                              "awaitPromise": True, "returnByValue": True})
        return json.loads(r["result"]["value"])


def check(label, data):
    v = []
    img_kb = sum(r["kb"] for r in data["res"] if r["type"] in ("img", "css") and any(
        s in r["url"] for s in ("/img.php", "/wp-content/uploads", "/thumb/", ".jpg", ".png", ".webp", ".avif", ".gif")))
    total_kb = data["navKb"] + sum(r["kb"] for r in data["res"])
    kb_by_url = {}
    for r in data["res"]:
        kb_by_url[r["url"].split("?")[0].split("/")[-1][:60] or r["url"][:60]] = r["kb"]

    for i in data["imgs"]:
        if not i["visible"] or not i["nw"] or not i["rw"]:
            continue
        # Small-asset floor: <=160px naturals (avatars at the resizer's
        # smallest bucket) are correct retina delivery, never meaningful KB.
        if i["nw"] <= 160:
            continue
        need = i["rw"] * i["dpr"]
        # match the FULL url (path-only matching pinned every /img.php?... to
        # the same — usually biggest — resource and invented 52KB avatars)
        kb = next((r["kb"] for r in data["res"] if i["src"] == r["url"]),
             next((r["kb"] for r in data["res"] if i["src"].split("?")[0] in r["url"] and "?" not in i["src"]), 0))
        if i["nw"] > need * OVERSIZE_RATIO and kb >= OVERSIZE_MIN_KB:
            v.append(f"IMG-OVERSIZE   {label}  {i['src'][-70:]}  natural={i['nw']}px rendered={i['rw']}px@{i['dpr']}x ({kb}KB)")
        if "/wp-content/uploads/" in i["src"] and "/img.php" not in i["src"] and kb >= OVERSIZE_MIN_KB:
            v.append(f"IMG-RAW        {label}  {i['src'][-70:]} ({kb}KB)")
        if not i["dims"] and any(c in i["cls"] for c in DIMS_REQUIRED_RE):
            v.append(f"IMG-NODIMS     {label}  {i['cls'][:40]}  {i['src'][-50:]}")
    if img_kb > IMG_BUDGET_KB:
        v.append(f"PAGE-IMG-BUDGET {label}  image transfer {img_kb}KB > {IMG_BUDGET_KB}KB")
    if total_kb > PAGE_BUDGET_KB:
        v.append(f"PAGE-BUDGET    {label}  total transfer {total_kb}KB > {PAGE_BUDGET_KB}KB")
    if label.endswith("anon"):
        for r in data["res"]:
            if any(m in r["url"].lower() for m in EDITOR_MARKERS):
                v.append(f"EAGER-EDITOR   {label}  {r['url'].split('/')[-1][:50]} loaded for anon ({r['kb']}KB)")
                break
    return v, img_kb, total_kb


def main():
    flt = sys.argv[sys.argv.index("--page") + 1] if "--page" in sys.argv else ""
    gate = gate_token()
    member = member_cookies()
    fails, ok = [], 0
    for name, (path, viewers) in active_pages().items():
        if flt and flt not in name:
            continue
        for viewer in viewers:
            label = f"{name}/{viewer}"
            try:
                data = asyncio.run(audit(path, viewer, gate, member))
            except (RuntimeError, OSError) as e:
                # An unreachable or unresponsive engine is a DEAD gate, not a
                # failing one, and the difference is the whole point: exiting 1
                # here is indistinguishable from finding real violations, which is
                # how this gate spent weeks looking red while it was never running.
                # Stop the whole run — the remaining pages will fail identically.
                print("==================== CRAFT GATE CANNOT RUN ====================")
                print(f"  {label}: {e}")
                print("  Nothing was audited, so this is NOT a pass and NOT a failure —")
                print("  there is no verdict at all. Check the engine, then re-run.")
                sys.exit(2)
            except Exception as e:
                fails.append(f"GATE-ERROR     {label}  {e}")
                continue

            # Refuse to grade a page we did not load. Same reasoning as the
            # CANNOT RUN above: a green earned over the edge challenge would
            # certify every surface at once without auditing any of them.
            why = wrong_document(data, path)
            if why:
                print("==================== CRAFT GATE CANNOT RUN ====================")
                print(f"  {label}: {why}")
                print("  This is NOT a pass and NOT a failure — the browser never reached")
                print("  our origin, so nothing below was audited. Launch chrome with")
                print("  $LG_GATE_CHROME_RESOLVER (see gate-env.sh) and re-run.")
                sys.exit(2)

            v, img_kb, total_kb = check(label, data)
            if v:
                fails.extend(v)
                print(f"  FAIL  {label}  ({len(v)} violation(s), imgs {img_kb}KB, total {total_kb}KB)")
            else:
                ok += 1
                print(f"  PASS  {label}  (imgs {img_kb}KB, total {total_kb}KB)")
    print()
    if fails:
        print(f"==================== CRAFT GATE RED ({len(fails)}) ====================")
        for f in fails:
            print("  " + f)
        sys.exit(1)
    print(f"==================== CRAFT GATE GREEN (pages={ok}) ====================")


if __name__ == "__main__":
    main()
