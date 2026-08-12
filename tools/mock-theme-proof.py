#!/usr/bin/env python3
"""Prove a set of dev-gated MOCK pages is theme-PROOF. Reusable by any UI lane.

    MOCK_BASE=https://dev2.loothgroup.com/footer-mockups/<lane>/ \
    MOCK_PAGES=index.html,foo.html python3 tools/mock-theme-proof.py

Written by the featured-members lane 2026-08-12 after the body-pin documented in
memory turned out to be only half the defence — see the header of
footer-mockups/featured-members/_mock.css for the full story.

The dev2 docroot injects the platform boot script into every page it serves,
including static mocks. Under a dark profile that script appends
`html[data-lguser-theme="dark"] body{background:#15171a!important;color:#e5e7e1!important}`.
A mock is a fixed artifact, not a themed surface — so the SAME page under
light and dark must come back with IDENTICAL computed colours.

Asserts, per page, in BOTH themes:
  · body background + colour
  · every light-background component's own text colour (inheritance routes
    around a body-only fix the moment anything re-specifies it)
Fails loud on any mismatch, and loudly on the dark render matching the
injected #15171a (which would mean the pin lost).
"""
import asyncio, json, os, sys, urllib.request
import websockets

CDP = "http://127.0.0.1:9222"
BASE = os.environ.get("MOCK_BASE", "https://dev2.loothgroup.com/footer-mockups/featured-members/")
PAGES = (os.environ.get("MOCK_PAGES") or "index.html,tickbox.html,dash.html,frontpage.html").split(",")
INJECTED_DARK = "rgb(21, 23, 26)"

# Probes: every element that paints a light background must be read, not just body.
PROBE = r"""
(() => {
  const g = (sel, prop) => {
    const el = document.querySelector(sel);
    return el ? getComputedStyle(el)[prop] : null;
  };
  return JSON.stringify({
    theme_attr : document.documentElement.getAttribute('data-lguser-theme'),
    boot_crit  : !!document.getElementById('lg-boot-crit'),
    body_bg    : getComputedStyle(document.body).backgroundColor,
    body_fg    : getComputedStyle(document.body).color,
    note_bg    : g('.note', 'backgroundColor'),
    note_fg    : g('.note', 'color'),
    stage_bg   : g('.stage', 'backgroundColor'),
    stage_fg   : g('.stage', 'color'),
    fm_bg      : g('.lg-fm', 'backgroundColor'),
    fm_bio_fg  : g('.lg-fm__bio', 'color'),
    fm_name_fg : g('.lg-fm__name', 'color'),
    slab_bg    : g('.lg-viewas', 'backgroundColor'),
    slab_fg    : g('.lg-viewas', 'color'),
    card_bg    : g('.lg-featcard', 'backgroundColor'),
    card_fg    : g('.lg-featcard__lbl', 'color'),
    wp_bg      : g('.wpwrap', 'backgroundColor'),
    wp_fg      : g('.wpwrap', 'color'),
    wpcard_bg  : g('.wpcard', 'backgroundColor'),
    td_fg      : g('table.wpt td', 'color'),
    step_bg    : g('.step', 'backgroundColor'),
    step_fg    : g('.step__p', 'color'),
    map_fg     : g('table.map td', 'color'),
    tok_charcoal: getComputedStyle(document.body).getPropertyValue('--lg-charcoal').trim(),
    tok_cardbg : getComputedStyle(document.body).getPropertyValue('--lg-card-bg').trim(),
  });
})()
"""


def http_get(path):
    return json.load(urllib.request.urlopen(CDP + path))


def new_tab():
    req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
    return json.load(urllib.request.urlopen(req))


def close_tab(tid):
    try:
        urllib.request.urlopen(CDP + "/json/close/" + tid).read()
    except Exception:
        pass


async def rpc(ws, method, params=None, _id=[0]):
    _id[0] += 1
    mid = _id[0]
    await ws.send(json.dumps({"id": mid, "method": method, "params": params or {}}))
    while True:
        msg = json.loads(await ws.recv())
        if msg.get("id") == mid:
            return msg


async def evaluate(ws, expr):
    r = await rpc(ws, "Runtime.evaluate",
                  {"expression": expr, "returnByValue": True, "awaitPromise": True})
    res = r.get("result", {}).get("result", {})
    if r.get("result", {}).get("exceptionDetails"):
        raise RuntimeError(json.dumps(r["result"]["exceptionDetails"])[:400])
    return res.get("value")


async def load(ws, url):
    await rpc(ws, "Page.navigate", {"url": url})
    for _ in range(120):
        await asyncio.sleep(0.25)
        state = await evaluate(ws, "document.readyState")
        href = await evaluate(ws, "location.href")
        if state == "complete" and href.startswith(url.split("?")[0][:40]):
            await asyncio.sleep(0.4)   # let the injected boot script run
            return
    raise RuntimeError("page never completed: " + url)


async def sample(ws, url, theme):
    await load(ws, url)                       # navigate FIRST — localStorage on about:blank is a no-op
    await evaluate(ws, f"localStorage.setItem('lg-set-theme','{theme}')")
    await load(ws, url)                       # reload so the boot script reads the theme
    raw = await evaluate(ws, PROBE)
    return json.loads(raw)


async def main():
    tab = new_tab()
    ws_url = tab["webSocketDebuggerUrl"]
    failures, checked = [], 0
    try:
        async with websockets.connect(ws_url, max_size=None, origin=None) as ws:
            await rpc(ws, "Page.enable")
            await rpc(ws, "Runtime.enable")
            await rpc(ws, "Network.enable")
            await rpc(ws, "Network.setCacheDisabled", {"cacheDisabled": True})
            for page in PAGES:
                url = BASE + page
                dark = await sample(ws, url, "dark")
                light = await sample(ws, url, "light")

                # 1. the hostile condition must actually be present, else this proves nothing
                if not dark["boot_crit"] and dark["theme_attr"] != "dark":
                    failures.append(f"{page}: LIVENESS — neither #lg-boot-crit nor "
                                    f"data-lguser-theme=dark present; the dark path was never exercised")

                # 2. dark must not have repainted to the injected slate
                if dark["body_bg"] == INJECTED_DARK:
                    failures.append(f"{page}: body repainted to injected dark {INJECTED_DARK}")

                # 3. every probe identical across themes
                for k in dark:
                    if k in ("theme_attr", "boot_crit"):
                        continue
                    checked += 1
                    if dark[k] != light[k]:
                        failures.append(f"{page}: {k} dark={dark[k]!r} light={light[k]!r}")

                mark = "dark-injected" if (dark["boot_crit"] or dark["theme_attr"] == "dark") else "no-injection"
                print(f"  {page:16s} body={dark['body_bg']:18s} ({mark})")
    finally:
        close_tab(tab["id"])

    print(f"\n{checked} computed values compared across light/dark.")
    if failures:
        print("\nFAIL:")
        for f in failures:
            print("  ✗ " + f)
        sys.exit(1)
    print("PASS — every probed colour is identical in both themes.")


asyncio.run(main())
