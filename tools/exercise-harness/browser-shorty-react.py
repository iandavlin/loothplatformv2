#!/usr/bin/env python3
"""
shorty-react BROWSER LEG — Ian's defect, driven through the REAL react button.

Ian, live, 2026-07-29: tapping a reaction emoji on /shorty/<slug>/ does nothing;
console shows POST /archive-api/v0/card-react -> 400, twice.

This does not curl the endpoint. It loads the real dev2 shorty page through
endpoint-swap-proxy.py (real nginx, real sub_filter overlays, real WP auth),
taps the REAL react control, then taps a REAL emoji option -- the exact
`button.lg-pf-react__opt > span.lg-pf-react__em` from Ian's screenshot -- and
records what the page's own fetch got back.

Run it twice against the two proxy configurations:
    RED    proxy started with NO --route      -> serving checkout (== main == live)
    GREEN  proxy started WITH --route         -> branch's card-react.php

Every UI assertion is re-checked against POSTGRES, because a react strip that
paints optimistically and silently reverts would otherwise read as a pass.

Usage: browser-shorty-react.py <red|green> <slug-to-tap>
"""
import asyncio, json, sys, urllib.request, subprocess

CDP  = "http://127.0.0.1:9222"
PAGE = "http://127.0.0.1:8899/shorty/david-collins-tuner-cover-popper/"
ITEM = 70218
PT   = "shorty"

MODE = sys.argv[1] if len(sys.argv) > 1 else "red"
WANT_SLUG = sys.argv[2] if len(sys.argv) > 2 else "wow"
# Viewport. Desktop is the default because at phone widths the dock sits inside
# the fixed tabbar's band on this page (see click()); that is a separate issue
# from the 400 under test, and it is measured and reported, not silently dodged.
VIEW = sys.argv[3] if len(sys.argv) > 3 else "desktop"
VW, VH, VM = (390, 844, True) if VIEW == "mobile" else (1280, 900, False)

# slug -> the button's title, from standalone/render.php:670-674. Kept here
# because the option buttons expose no data-slug to select on.
PALETTE_LABEL = {"like": "Like", "ouch": "Ouch", "wow": "Wow", "lol": "LOL",
                 "shop": "Optimum", "take-my-money": "Take my money", "brain": "Brain"}

out, fails, passes = [], 0, 0


def log(*a):
    s = " ".join(str(x) for x in a)
    out.append(s)
    print(s, flush=True)


def check(label, got, want):
    global fails, passes
    ok = got == want
    if ok:
        passes += 1
    else:
        fails += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + ("" if ok else f"   got={got!r} want={want!r}"))
    return ok


def http(p, method="GET"):
    # Chrome >=111 requires PUT on /json/new (GET returns 405 Method Not Allowed).
    req = urllib.request.Request(CDP + p, method=method)
    return json.load(urllib.request.urlopen(req))


def db_rows():
    """The store is the authority, not the pixel."""
    sql = (f"select slug, coalesce(user_uuid::text,'(anon)') "
           f"from discovery.card_reactions where post_type='{PT}' and item_id={ITEM} order by slug;")
    r = subprocess.run(["sudo", "-u", "archive-poc", "psql", "-d", "looth", "-A", "-t", "-F", "|", "-c", sql],
                       capture_output=True, text=True)
    return [l for l in r.stdout.strip().split("\n") if l]


class Page:
    def __init__(self, ws):
        self.ws = ws
        self.n = 0
        self.reacts = []          # (status, url) for every card-react request

    async def send(self, m, p=None):
        self.n += 1
        i = self.n
        await self.ws.send(json.dumps({"id": i, "method": m, "params": p or {}}))
        while True:
            r = json.loads(await self.ws.recv())
            if r.get("id") == i:
                if "error" in r:
                    raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})
            self._event(r)

    def _event(self, r):
        if r.get("method") == "Network.responseReceived":
            resp = r["params"]["response"]
            if "card-react" in resp["url"] and r["params"]["type"] in ("XHR", "Fetch"):
                self.reacts.append((resp["status"], resp["url"]))

    async def pump(self, seconds):
        """Drain events for a while (fetches complete on their own clock)."""
        end = asyncio.get_event_loop().time() + seconds
        while asyncio.get_event_loop().time() < end:
            try:
                r = json.loads(await asyncio.wait_for(self.ws.recv(), timeout=0.25))
                self._event(r)
            except asyncio.TimeoutError:
                pass

    async def ev(self, expr):
        r = await self.send("Runtime.evaluate",
                            {"expression": expr, "returnByValue": True, "awaitPromise": True})
        if r.get("exceptionDetails"):
            raise RuntimeError("JS: " + str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")

    async def click(self, sel, nth=0):
        """A REAL mouse click at a point that genuinely HIT-TESTS to the element.

        el.click() would always 'work' and prove nothing. A blind click at the
        centre proves the opposite of what it looks like: on this page the dock
        sits at the very bottom in normal flow, and the fixed NAV#looth-tabbar
        (bottom-nav.js, injected by pwa.js) covers it at phone widths — the
        click lands on the tabbar and the picker never opens. So: find a point
        inside the rect whose elementFromPoint really is our element, scrolling
        the page up a little if the first sample is occluded. If no such point
        exists, say so instead of clicking something else.
        """
        for attempt in range(4):
            pt = await self.ev(f"""(()=>{{
                const e=[...document.querySelectorAll({json.dumps(sel)})][{nth}];
                if(!e) return null;
                if(!{attempt}) e.scrollIntoView({{block:'center'}});
                const r=e.getBoundingClientRect();
                if(!r.width||!r.height) return null;
                // sample the centre, then inset points, before giving up
                const cands=[[.5,.5],[.5,.25],[.25,.5],[.75,.5],[.5,.75]];
                for(const [fx,fy] of cands){{
                    const x=r.x+r.width*fx, y=r.y+r.height*fy;
                    const el=document.elementFromPoint(x,y);
                    if(el && (el===e || e.contains(el) || el.closest?.({json.dumps(sel)})===e))
                        return {{x,y,ok:true}};
                }}
                const el=document.elementFromPoint(r.x+r.width/2, r.y+r.height/2);
                return {{x:r.x+r.width/2, y:r.y+r.height/2, ok:false,
                         blocker: el? el.tagName+(el.id?'#'+el.id:'') : 'none'}};}})()""")
            if not pt:
                return False
            if pt.get("ok"):
                for t in ("mousePressed", "mouseReleased"):
                    await self.send("Input.dispatchMouseEvent",
                                    {"type": t, "x": pt["x"], "y": pt["y"],
                                     "button": "left", "clickCount": 1})
                return True
            # occluded — lift the page out from under the fixed furniture and retry
            log(f"    (occluded by {pt.get('blocker')}; scrolling up and retrying)")
            await self.ev("window.scrollBy(0,-140)")
            await self.pump(0.4)
        return False


async def main():
    global fails
    import websockets

    log(f"=== shorty-react BROWSER LEG — {MODE.upper()} — tapping '{WANT_SLUG}' @ {VIEW} {VW}x{VH} ===")
    log(f"    page  {PAGE}")
    log(f"    store before: {db_rows()}")

    # OUR OWN TAB. One engine box-wide: never attach to a tab we did not create,
    # and close it on every exit path.
    tab = http("/json/new?about:blank", method="PUT")
    tid = tab["id"]
    log(f"    tab   {tid}")
    try:
        async with websockets.connect(tab["webSocketDebuggerUrl"],
                                      max_size=40_000_000, ping_interval=None) as ws:
            p = Page(ws)
            await p.send("Network.enable")
            await p.send("Page.enable")
            await p.send("Runtime.enable")
            await p.send("Emulation.setDeviceMetricsOverride",
                         {"width": VW, "height": VH, "deviceScaleFactor": 2, "mobile": VM})

            await p.send("Page.navigate", {"url": PAGE})
            await p.pump(6)

            # The strip Ian sees.
            has_box = await p.ev("!!document.querySelector('[data-lg-react]')")
            check("react widget present on the shorty page", has_box, True)
            pt = await p.ev("document.querySelector('[data-lg-react]')?.dataset.pt")
            idv = await p.ev("document.querySelector('[data-lg-react]')?.dataset.id")
            check("widget declares post_type", pt, PT)
            check("widget declares item id", idv, str(ITEM))

            before = len(p.reacts)

            # 1) open the picker with the real control
            opened = await p.click(".lg-pf-react__btn")
            check("tapped the real react control", opened, True)
            await p.pump(1.5)

            n_opts = await p.ev("document.querySelectorAll('button.lg-pf-react__opt').length")
            check("emoji picker opened with options", n_opts > 0, True)

            # 2) tap the REAL emoji option Ian tapped
            # The option buttons carry no data-slug — the renderer sets title=label
            # (standalone/render.php:693). Match on that, not on a wished-for attr.
            label = PALETTE_LABEL[WANT_SLUG]
            idx = await p.ev(f"""(()=>{{const o=[...document.querySelectorAll('button.lg-pf-react__opt')];
                const i=o.findIndex(b=>b.title==={json.dumps(label)});return i;}})()""")
            check(f"'{WANT_SLUG}' is offered in the picker", idx >= 0, True)
            if idx is None or idx < 0:
                idx = 0
            glyph = await p.ev(f"""(()=>{{const b=[...document.querySelectorAll('button.lg-pf-react__opt')][{idx}];
                return b? (b.querySelector('span.lg-pf-react__em')?.textContent||'').trim() : null;}})()""")
            log(f"    tapping option[{idx}] glyph={glyph!r}  (span.lg-pf-react__em — Ian's element)")
            tapped = await p.click("button.lg-pf-react__opt", idx)
            check("tapped a real emoji option", tapped, True)

            # 3) what did the PAGE'S OWN fetch get back?
            await p.pump(5)
            posts = [s for (s, u) in p.reacts[before:]]
            log(f"    card-react responses seen by the page: {posts}")

            want_status = 400 if MODE == "red" else 200
            got = posts[-1] if posts else None
            check(f"POST /archive-api/v0/card-react status", got, want_status)

            # 4) the store is the authority
            rows = db_rows()
            log(f"    store after: {rows}")
            mine = [r for r in rows if r.startswith(WANT_SLUG + "|")]
            check(f"'{WANT_SLUG}' row persisted in card_reactions", len(mine) > 0,
                  MODE == "green")

            # 5) and the count the user actually sees
            shown = await p.ev("""(()=>{const b=document.querySelector('[data-lg-react]');
                return b? b.innerText.replace(/\\s+/g,' ').trim().slice(0,120) : null;})()""")
            log(f"    strip reads: {shown!r}")

            import base64
            # Full viewport, plus a tight crop of the dock itself — the crop is
            # what actually shows Ian the before/after. clip is in PAGE
            # coordinates, not viewport, so the scroll offset has to go back in.
            shot = await p.send("Page.captureScreenshot", {"format": "png"})
            path = f"/tmp/shorty-react-exercise/shot-{MODE}-{VIEW}.png"
            open(path, "wb").write(base64.b64decode(shot["data"]))
            log(f"    screenshot {path}")

            clip = await p.ev("""(()=>{const d=document.querySelector('.lg-dock__react')
                    ||document.querySelector('[data-lg-react]');
                if(!d) return null; const r=d.getBoundingClientRect();
                const pad=14;
                return {x:Math.max(0,r.x+scrollX-pad), y:Math.max(0,r.y+scrollY-pad),
                        width:r.width+pad*2, height:r.height+pad*2};})()""")
            if clip:
                clip["scale"] = 3
                crop = await p.send("Page.captureScreenshot", {"format": "png", "clip": clip,
                                                              "captureBeyondViewport": True})
                cpath = f"/tmp/shorty-react-exercise/dock-{MODE}-{VIEW}.png"
                open(cpath, "wb").write(base64.b64decode(crop["data"]))
                log(f"    dock crop  {cpath}")
    finally:
        try:
            urllib.request.urlopen(f"{CDP}/json/close/{tid}").read()
            log(f"    tab {tid} CLOSED")
        except Exception as e:
            log(f"    !! could not close tab {tid}: {e}")

    log(f"=== {MODE.upper()}: {passes} passed, {fails} failed ===")
    open(f"/tmp/shorty-react-exercise/browser-{MODE}-{VIEW}.log", "w").write("\n".join(out) + "\n")
    sys.exit(1 if fails else 0)


asyncio.run(main())
