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

import asyncio, json, subprocess, sys, urllib.request, urllib.parse

try:
    import websockets
except ImportError:
    sys.exit("python3-websockets required (it's installed system-wide on dev)")

HOST = "https://dev.loothgroup.com"
CDP  = "http://127.0.0.1:9222"

# page → (path, [viewers]) ; add new user-facing surfaces HERE when built.
PAGES = {
    "front":   ("/front-page/",        ["anon", "member"]),
    "hub":     ("/hub/",               ["anon", "member"]),
    "finder":  ("/directory/members",  ["anon", "member"]),
    "weekly":  ("/weekly/",            ["anon"]),
    "events":  ("/events/",            ["anon"]),
    "profile": ("/u/iandavlin",        ["member"]),
}

OVERSIZE_RATIO   = 1.7        # natural px vs rendered px * dpr
OVERSIZE_MIN_KB  = 40         # ignore tiny offenders
IMG_BUDGET_KB    = 1500
PAGE_BUDGET_KB   = 2500
EDITOR_MARKERS   = ["quill"]  # script src substrings that are edit-intent only
DIMS_REQUIRED_RE = ["feed-card__cover", "dir-card", "lg-evland__thumb"]  # class hints


def sh(cmd):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True).stdout.strip()


def gate_token():
    for line in open("/etc/nginx/sites-available/dev.loothgroup.com.conf"):
        if "$loothdev_token" in line and '"' in line:
            return line.split('"')[1]
    sys.exit("cannot read dev gate token")


def member_cookies():
    wpc = sh("sudo -u www-data wp --path=/var/www/dev eval "
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
  res(JSON.stringify({ imgs, res: res2, navKb: Math.round(((nav && nav.transferSize) || 0) / 1024) }));
}, 6000))
"""


async def cdp(ws_url, method, params=None, _id=[0]):
    raise RuntimeError  # replaced below; kept simple via session class


class Tab:
    def __init__(self, ws):
        self.ws = ws
        self.n = 0

    async def send(self, method, params=None):
        self.n += 1
        await self.ws.send(json.dumps({"id": self.n, "method": method, "params": params or {}}))
        while True:
            msg = json.loads(await self.ws.recv())
            if msg.get("id") == self.n:
                return msg.get("result", {})


async def audit(path, viewer, gate, member):
    pages = json.load(urllib.request.urlopen(CDP + "/json"))
    page = next(p for p in pages if p["type"] == "page")
    async with __import__("websockets").connect(page["webSocketDebuggerUrl"], max_size=None) as ws:
        t = Tab(ws)
        await t.send("Network.enable")
        await t.send("Network.setCacheDisabled", {"cacheDisabled": True})   # cold-load truth: a cached run undercounts KB
        await t.send("Network.clearBrowserCookies")
        cookies = [("loothdev_auth", gate)] + (member if viewer == "member" else [])
        for n, v in cookies:
            await t.send("Network.setCookie", {"domain": "dev.loothgroup.com", "name": n,
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
    for name, (path, viewers) in PAGES.items():
        if flt and flt not in name:
            continue
        for viewer in viewers:
            label = f"{name}/{viewer}"
            try:
                data = asyncio.run(audit(path, viewer, gate, member))
            except Exception as e:
                fails.append(f"GATE-ERROR     {label}  {e}")
                continue
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
