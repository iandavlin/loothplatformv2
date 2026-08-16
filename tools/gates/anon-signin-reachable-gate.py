#!/usr/bin/env python3
"""
anon-signin-reachable-gate — can a SIGNED-OUT visitor get back in, at every width?

WHY THIS GATE EXISTS (CRAFT-STANDARD: a defect class found twice becomes a gate).

Ian, 2026-07-31, from an incognito window on the logged-out front page:

    "add to backlog as mission critical that this page break has no sign in option."

He was right, and every check we had was green. The "Sign in" anchor was in the
served HTML the whole time — twice — so a curl assertion, a DOM count, and a
`querySelector('.lg-chrome__signin') !== null` all passed while a returning member
on a 768px-wide window had NO WAY BACK IN. The control was rendered and then
`display:none`-d by a stylesheet whose breakpoint disagreed with the stylesheet
that was supposed to provide the replacement:

    archive-poc/web/archive.css  @media (max-width: 820px) { .lg-chrome__signin  { display:none } }
    lg-shared/site-header.css    @media (max-width: 640px) { .lg-chrome__menu-signin { display:block } }

Between those two numbers — 641px to 820px — the button was hidden and its
stand-in had not yet appeared. Opening the hamburger showed a white panel holding
Hub / Events / Map / Sponsors / Loothtool and no sign-in at all. That is the
picture Ian sent.

This is the SECOND time a control Ian could not see was "present in the DOM" the
whole time (the first: the 🔔/✉ toggles, tools/gates/follow-visible-gate.py). The
shared lesson is that PRESENCE IS NOT REACHABILITY, so nothing here counts an
element. Every assertion is about what a person can actually do.

WHAT IT ASSERTS — the user's goals, not elements. Two halves, and the second was
missing for the whole life of this gate:

  (A) REACHABLE. At every tested viewport width, an anonymous visitor on / must be
      able to reach a sign-in control in AT MOST ONE deliberate tap, and that
      control must be VISIBLE (styled, sized, in the viewport) and HIT-TESTABLE
      (elementFromPoint at its centre resolves to the link itself, not to
      something painted over it).

  (B) NOT LIED TO. At phone widths, the mobile bottom dash must NOT offer an
      anonymous visitor a control that claims they can post. (Ian 2026-08-05:
      "It's currently showing the + icon for adding a post and it's not entirely
      clear that you cant post logged out.")

WHY (B) HAD TO BE ADDED HERE RATHER THAN AS ITS OWN GATE. (A) and (B) are the
same surface and the same visitor, and they PULL AGAINST EACH OTHER: the cheap way
to make sign-in obvious is to wall off browsing, and the cheap way to keep browsing
open is to leave a compose button that lies. A single gate that must satisfy both
at once cannot be quietly half-satisfied. Splitting them into two files is how one
gets relaxed to make the other go green.

Measured BEFORE the fix (2026-08-07, anon, /hub/, 390/430/360/640 — all four): the
dash renders Nav | + | You. The "+" is a 64x53 filled circle, fully hit-testable,
the single most prominent control on the screen. Tapping it navigates to
/hub/?compose=1 (forums.js opens the composer on that param with NO auth check),
and the modal that appears says "Sign in to post to the forums." while ALSO
painting the live post form under it — Title field, Forum picker, "Next", "STEP 1
OF 4". So the anon is told they cannot post and invited to start typing, on one
screen. That is the defect class this half of the gate now holds shut.

THE ABSENCE HALF NEEDS A LIVENESS HALF, or it is vacuous. "No compose control in
the dash" is trivially TRUE on a page where the dash never rendered, or where JS
died, or where we accidentally measured a desktop width. So (B) refuses to return
a verdict unless it can first prove the dash is ALIVE: rendered, displayed, and
carrying its other slots. An absence that cannot show the machinery around it is
not evidence. (See the 2026-08-01 false green: an assertion came back TRUE because
the run was executing as a different member entirely.)

Deliberately ROUTE-AGNOSTIC. The gate tries, in order: already visible in the
header → after one tap on the hamburger → after one tap on the bottom-bar account
tab. Any route counts. A future redesign that moves sign-in from the header pill
into the drawer, or into the tab bar, still passes — because the thing being
protected is the member's ability to get back in, not a particular div. A gate
written as "`.lg-chrome__signin` must be visible" would have to be edited (and
could be edited away) by the very change that breaks the user.

WHY IT IS A BROWSER GATE AND NOT A GREP. `display:none` from a media query in a
DIFFERENT stylesheet than the one that declares the element is not visible to any
static check of either file. Only a real engine, at a real width, resolves the
cascade across both. And only elementFromPoint can tell "hidden" from "covered" —
an overlay with `pointer-events:none` would paint over the link while leaving it
clickable, and the reverse (`opacity:0` but hit-testable) is just as invisible to
a person. Both are failures here.

WIDTHS. The two bracketing pairs are the point: 821/820 straddles archive.css's
breakpoint, 641/640 straddles site-header.css's and the bottom bar's. A gate that
sampled only "desktop and phone" would have passed on the day it was written —
1440 and 390 were both FINE while the entire tablet-and-narrow-window band was
dead. If a future change moves a breakpoint, move these brackets with it.

RUNS AS A TRUE INCOGNITO. Each width gets its own CDP BrowserContext, which is a
real private window: empty localStorage, empty cookies, no WP session, and — the
part that has burned two lanes — none of the shared chrome-dev profile's persisted
`lg-set-theme=dark`. The first run of this investigation rendered the DARK header
and measured 12.4:1 contrast on controls Ian was seeing grey-on-white, because the
shared profile carried his opposite. Disposing the context also means the gate
never mutates state another lane is using; it must NOT call
Network.clearBrowserCookies, which is browser-wide.

RUNS AGAINST THE REAL ORIGIN via tools/exercise-harness/real-origin-proxy.py with
NO cookie file, so the viewer is genuinely anonymous and the response is the real
vhost's — sub_filter, theme-boot script and all. The loopback exercise harness
does not reproduce those and would measure a page nobody is served.

Run:   python3 tools/gates/anon-signin-reachable-gate.py [--url http://127.0.0.1:PORT]
       (with no --url it starts, and cleans up, its own anon proxy on a free port)
Needs: chrome-dev on 127.0.0.1:9222, python3-websocket, the dev gate token.

Exit:  0 green, 1 RED (a width with no way in), 2 CANNOT RUN (no verdict).
"""
import argparse, json, re, socket, subprocess, sys, time, urllib.request

CDP = "http://127.0.0.1:9222"
REPO = __file__.rsplit("/tools/", 1)[0]
PROXY = f"{REPO}/tools/exercise-harness/real-origin-proxy.py"
GATE_ENV = f"{REPO}/tools/gates/gate-env.sh"
NO_VERDICT = 2

# 821/820 straddle archive.css; 641/640 straddle site-header.css + the bottom bar.
# <=640 is the site-wide phone cutoff, so those run with touch + a phone UA.
WIDTHS = [1440, 1024, 821, 820, 768, 700, 641, 640, 480, 390]

PATH = "/"          # the anon front door: the one page a returning member lands on

# The preview leg CANNOT run on PATH. The front page's archive.js enterDiscover()
# calls history.replaceState(null, '', '/') during load and wipes every query
# param — measured 2026-08-07, and it takes ?lgdebug=1 with it. A preview override
# asserted on "/" would therefore always read as OFF and this leg would fail
# forever for a reason that has nothing to do with the dash. /hub/ keeps its query
# string, and is where the composer being replaced actually lived.
PREVIEW_PATH = "/hub/"

passes = failures = 0
def log(*a): print(" ".join(str(x) for x in a), flush=True)

def check(label, ok, detail=""):
    global passes, failures
    if ok: passes += 1
    else:  failures += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + (f"   {detail}" if detail and not ok else ""))
    return ok

def cannot_run(why):
    log(f"  CANNOT RUN — {why}")
    log("  (exit 2: no verdict. This is NOT a pass and NOT a finding.)")
    sys.exit(NO_VERDICT)

try:
    import websocket  # websocket-client
except ImportError:
    cannot_run("python3 websocket-client is not installed")


# ---------------------------------------------------------------- CDP plumbing
class Sock:
    """One persistent connection. Per-command sockets silently drop the device
    emulation (it is session-scoped), which makes a phone run false-PASS as
    desktop — the exact failure this gate exists to catch."""
    def __init__(self, url, timeout=60):
        # suppress_origin: chrome rejects a CDP socket carrying Origin unless it
        # was launched with --remote-allow-origins. Not sending one needs no
        # change to the shared chrome-dev service.
        self.ws = websocket.create_connection(url, timeout=timeout, suppress_origin=True)
        self.n = 0
    def send(self, method, params=None):
        self.n += 1; i = self.n
        self.ws.send(json.dumps({"id": i, "method": method, "params": params or {}}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("id") == i:
                if "error" in r: raise RuntimeError(f"{method}: {r['error']}")
                return r.get("result", {})
    def ev(self, expr):
        r = self.send("Runtime.evaluate",
                      {"expression": expr, "returnByValue": True, "awaitPromise": True})
        if r.get("exceptionDetails"):
            raise RuntimeError("JS: " + str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")
    def close(self):
        try: self.ws.close()
        except Exception: pass


class Incognito:
    """A real private window: its own cookie jar and its own localStorage, so the
    shared profile's persisted theme/feed prefs cannot leak in and our anon run
    cannot leak out onto another lane."""
    def __init__(self, browser):
        self.b = browser
        self.ctx = self.b.send("Target.createBrowserContext",
                               {"disposeOnDetach": False})["browserContextId"]
        self.tid = self.b.send("Target.createTarget",
                               {"url": "about:blank",
                                "browserContextId": self.ctx})["targetId"]
        self.page = Sock(f"ws://127.0.0.1:9222/devtools/page/{self.tid}")
    def close(self):
        self.page.close()
        for m, p in (("Target.closeTarget", {"targetId": self.tid}),
                     ("Target.disposeBrowserContext", {"browserContextId": self.ctx})):
            try: self.b.send(m, p)
            except Exception: pass


DESKTOP_UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
              "Chrome/151.0.0.0 Safari/537.36")
PHONE_UA = ("Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 "
            "(KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1")

def emulate(p, w, h, mobile):
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": w, "height": h, "deviceScaleFactor": 2 if mobile else 1, "mobile": mobile})
    p.send("Emulation.setTouchEmulationEnabled",
           {"enabled": mobile, "maxTouchPoints": 5 if mobile else 1})
    p.send("Emulation.setUserAgentOverride", {"userAgent": PHONE_UA if mobile else DESKTOP_UA})

def goto(p, url, secs=60):
    p.send("Page.navigate", {"url": url})
    for _ in range(secs * 4):
        time.sleep(0.25)
        try:
            if p.ev("document.readyState") == "complete": break
        except Exception: pass
    else:
        return False
    # The bottom bar and the account sheet are injected by bottom-nav.js after
    # load; measuring before it runs would report a phone with no controls at all.
    for _ in range(40):
        try:
            if p.ev("!!document.querySelector('.lg-chrome')"): break
        except Exception: pass
        time.sleep(0.25)
    time.sleep(1.5)
    return True


# ------------------------------------------------------------------ assertions
# "Can a person SEE and TOUCH a way to sign in, right now?" Presence is not
# reachability, so nothing here counts nodes: each candidate must be styled to
# render, occupy space, sit inside the viewport, AND be the element that
# elementFromPoint returns at its own centre. That last clause is what separates
# a covered control from a reachable one.
REACHABLE = r"""
(() => {
  const desc = e => e ? e.tagName.toLowerCase() + (e.id ? '#'+e.id : '') +
    (e.className && typeof e.className === 'string'
      ? '.' + e.className.trim().split(/\s+/).join('.') : '') : null;
  const out = [];
  document.querySelectorAll('a[href]').forEach(a => {
    const href = a.getAttribute('href') || '';
    // A sign-in door, not a password-reset or a join. Reset password is a real
    // affordance but it is not "getting back in", so it must not satisfy this.
    if (!/(^|\/)wp-login\.php(\?|$)/.test(href)) return;
    if (/action=/.test(href)) return;
    const cs = getComputedStyle(a), r = a.getBoundingClientRect();
    const styled = cs.display !== 'none' && cs.visibility !== 'hidden'
                   && parseFloat(cs.opacity) > 0.01;
    const sized  = r.width > 0 && r.height > 0;
    const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    const inView = cx >= 0 && cy >= 0 && cx <= innerWidth && cy <= innerHeight;
    let hit = null, top = null;
    if (styled && sized && inView) {
      const el = document.elementFromPoint(cx, cy);
      top = desc(el);
      hit = !!(el && (el === a || a.contains(el) || (el.closest && el.closest('a') === a)));
    }
    out.push({el: desc(a), text: (a.textContent || '').trim().slice(0, 24), href,
              styled, sized, inView, hit, top,
              x: Math.round(r.x), y: Math.round(r.y),
              w: Math.round(r.width), h: Math.round(r.height)});
  });
  return {all: out, ok: out.filter(o => o.styled && o.sized && o.inView && o.hit)};
})()
"""

# The one-tap openers, in the order a person would find them. Each is itself
# hit-tested before we dispatch at it — a blind click can land on fixed furniture
# (the tab bar sits at z-index 2147481200) and a gate that never notices would
# report "I opened the menu" about a tap that hit something else entirely.
OPENER = r"""
(() => {
  const sels = [%SELS%];
  for (const s of sels) {
    const e = document.querySelector(s);
    if (!e) continue;
    const cs = getComputedStyle(e), r = e.getBoundingClientRect();
    if (cs.display === 'none' || cs.visibility === 'hidden' || !r.width || !r.height) continue;
    const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    if (cx < 0 || cy < 0 || cx > innerWidth || cy > innerHeight) continue;
    const top = document.elementFromPoint(cx, cy);
    const mine = !!(top && (top === e || e.contains(top) || (top.closest && top.closest(s) === e)));
    if (!mine) continue;                       // something else owns that pixel
    return {sel: s, x: cx, y: cy};
  }
  return null;
})()
"""

# (B) The dash must not offer an anonymous visitor a way to "post".
#
# ROUTE-AGNOSTIC ON PURPOSE, in both directions. It does not look for "#looth-tabbar
# button.lt-post" — a redesign that renames the class, swaps the element, or moves
# the affordance to another slot must still be caught. It asks the question a person
# asks: is anything in this bar CLAIMING I can make a post? So it matches on what
# the control says about itself (aria-label / visible label / an href that lands in
# a composer), not on how it is built.
#
# It counts a control as "offered" only when it is VISIBLE and HIT-TESTABLE — the
# same standard as (A). A compose button left in the DOM at display:none is not
# offered to anyone, and failing on it would be counting nodes again, which is the
# habit this whole file exists to break.
NO_COMPOSE = r"""
(() => {
  const desc = e => e ? e.tagName.toLowerCase() + (e.id ? '#'+e.id : '') +
    (e.className && typeof e.className === 'string'
      ? '.' + e.className.trim().split(/\s+/).join('.') : '') : null;
  const bar = document.getElementById('looth-tabbar');
  if (!bar) return {alive: false, why: 'no #looth-tabbar in the document'};
  const barCs = getComputedStyle(bar), barR = bar.getBoundingClientRect();
  if (barCs.display === 'none' || !barR.height)
    return {alive: false, why: `#looth-tabbar is not displayed (display:${barCs.display}, h=${Math.round(barR.height)})`};

  const slots = [];
  bar.querySelectorAll(':scope > a, :scope > button').forEach(e => {
    const cs = getComputedStyle(e), r = e.getBoundingClientRect();
    const cx = r.left + r.width/2, cy = r.top + r.height/2;
    const styled = cs.display !== 'none' && cs.visibility !== 'hidden'
                   && parseFloat(cs.opacity) > 0.01;
    const sized = r.width > 0 && r.height > 0;
    const inView = cx >= 0 && cy >= 0 && cx <= innerWidth && cy <= innerHeight;
    let hit = null, top = null;
    if (styled && sized && inView) {
      const el = document.elementFromPoint(cx, cy);
      top = desc(el);
      hit = !!(el && (el === e || e.contains(el)));
    }
    const label = ((e.getAttribute('aria-label') || '') + ' ' + (e.textContent || '')).trim();
    const href = e.getAttribute('href') || '';
    slots.push({el: desc(e), label: label.replace(/\s+/g,' ').slice(0,30), href,
                styled, sized, inView, hit, top,
                w: Math.round(r.width), h: Math.round(r.height),
                x: Math.round(cx), y: Math.round(cy)});
  });

  // LIVENESS. Prove the bar actually built before believing anything is absent
  // from it. Two independent signs: it has more than one usable slot, and it has
  // the account/"You" door that every build of this bar has always carried.
  const usable = slots.filter(s => s.styled && s.sized);
  const hasYou = slots.some(s => /\byou\b|account|profile/i.test(s.label + ' ' + s.href));
  if (usable.length < 2)
    return {alive: false, why: `the dash rendered only ${usable.length} usable slot(s)`, slots};
  if (!hasYou)
    return {alive: false, why: 'the dash has no You/account slot — this is not the built bar', slots};

  // A control that CLAIMS you can post.
  const CLAIMS_POST = s =>
    /new post|^post$|\bcompose\b|create post|add post/i.test(s.label) ||
    /[?&]compose(=|&|$)/.test(s.href);
  const offered = slots.filter(s => CLAIMS_POST(s) && s.styled && s.sized && s.inView && s.hit);
  return {alive: true, slots, offered,
          present: slots.filter(CLAIMS_POST).map(s => s.el)};
})()
"""

HAMBURGER = "'.lg-chrome__hamburger', '[data-lg-mobile-toggle]'"
ACCOUNT_TAB = ("'#looth-tabbar a[aria-label=\"You\"]', '#looth-tabbar button[aria-label=\"You\"]', "
               "'#looth-tabbar [aria-label=\"Account\"]'")

def tap(p, x, y, mobile):
    if mobile:
        p.send("Input.dispatchTouchEvent", {"type": "touchStart", "touchPoints": [{"x": x, "y": y}]})
        time.sleep(0.08)
        p.send("Input.dispatchTouchEvent", {"type": "touchEnd", "touchPoints": []})
    else:
        for t in ("mousePressed", "mouseReleased"):
            p.send("Input.dispatchMouseEvent",
                   {"type": t, "x": x, "y": y, "button": "left", "clickCount": 1})
            time.sleep(0.06)
    time.sleep(1.2)


def read_flag(base):
    """Read LG_ANON_DASH_SIGNIN out of the SERVED bottom-nav.js.

    The gate must not hardcode which state is current, or flipping the flag turns
    it red and someone edits the assertion to make it green again — which is how a
    gate stops meaning anything. Instead it reads what is actually being served and
    holds THAT state to its own contract:

        flag OFF  -> the dash must still be the historic Nav | + | You, i.e. the
                     OFF path is the no-op it claims to be
                     AND ?lgdash=signin must already show the fixed shape
        flag ON   -> the dash must be the fixed shape by default

    So the day Ian flips the default, this gate starts demanding the fix without
    anyone touching this file.
    """
    for path in ("/bottom-nav.js", "/hub/bottom-nav.js"):
        try:
            src = urllib.request.urlopen(base + path, timeout=20).read().decode("utf-8", "replace")
        except Exception:
            continue
        m = re.search(r"var\s+LG_ANON_DASH_SIGNIN\s*=\s*(true|false)\s*;", src)
        if m:
            return m.group(1) == "true", path
        # served, but the flag is not in it — that is a real answer: this build
        # predates the flag entirely.
        if "looth-tabbar" in src:
            return None, path
    return None, None


# The fixed shape, measured in its own incognito so the (A) taps cannot bleed in.
def run_preview(browser, base, w, query):
    inc = Incognito(browser)
    p = inc.page
    try:
        emulate(p, w, 844, True)
        if not goto(p, base + PREVIEW_PATH + query):
            return None, "preview page never reached readyState complete"
        live = p.ev("(()=>({chrome: !!document.querySelector('.lg-chrome'),"
                    " authed: !!document.querySelector('.lg-chrome__account')}))()")
        if not live["chrome"]:
            return None, "preview: no .lg-chrome"
        if live["authed"]:
            return None, "preview: rendered an ACCOUNT cluster — not anonymous"
        return {"compose": p.ev(NO_COMPOSE), "reach": p.ev(REACHABLE)}, None
    finally:
        inc.close()


def run_width(browser, base, w, mobile):
    h = 844 if mobile else 900
    inc = Incognito(browser)
    p = inc.page
    try:
        emulate(p, w, h, mobile)
        if not goto(p, base + PATH):
            return None, "page never reached readyState complete"

        # ---- liveness: are we measuring the anon front page at all? ----
        # Without this the whole gate passes vacuously on an error page, and
        # "no sign-in needed" would be indistinguishable from "signed in".
        live = p.ev("(()=>({chrome: !!document.querySelector('.lg-chrome'),"
                    " anon: !!document.querySelector('.lg-chrome__join'),"
                    " authed: !!document.querySelector('.lg-chrome__account'),"
                    " title: document.title.slice(0,60)}))()")
        if not live["chrome"]:
            return None, f"no .lg-chrome on the page (title={live['title']!r})"
        if live["authed"]:
            return None, "the header rendered an ACCOUNT cluster — this run is not anonymous"
        if not live["anon"]:
            return None, "no anon CTA cluster — cannot confirm the logged-out header"

        # ---- (B) the dash must not claim an anon can post ----
        # Phone widths only: the bar is mobile-only (bottom-nav.js MOBILE_MQ is
        # max-width:640px), so asking this at 1440 would pass on an absence that
        # means nothing.
        compose = p.ev(NO_COMPOSE) if mobile else None

        # ---- route 1: already there, no interaction ----
        r = p.ev(REACHABLE)
        if r["ok"]:
            return {"route": "header (no tap)", "link": r["ok"][0], "all": r["all"],
                    "compose": compose}, None

        # ---- routes 2 & 3: exactly one deliberate tap ----
        for name, sels in (("hamburger", HAMBURGER), ("bottom-bar account tab", ACCOUNT_TAB)):
            op = p.ev(OPENER.replace("%SELS%", sels))
            if not op:
                continue
            tap(p, op["x"], op["y"], mobile)
            r = p.ev(REACHABLE)
            if r["ok"]:
                return {"route": f"one tap on {name} ({op['sel']})",
                        "link": r["ok"][0], "all": r["all"], "compose": compose}, None
            # leave it open; the next opener may still be reachable underneath

        r = p.ev(REACHABLE)
        return {"route": None, "link": None, "all": r["all"], "compose": compose}, None
    finally:
        inc.close()


# ----------------------------------------------------------------- the harness
def free_port():
    s = socket.socket(); s.bind(("127.0.0.1", 0)); port = s.getsockname()[1]; s.close()
    return port

def start_proxy():
    """Anonymous real-origin proxy: no cookie file, so the viewer is genuinely
    logged out and the bytes are the real vhost's."""
    tok = ""
    try:
        out = subprocess.run(["bash", GATE_ENV], capture_output=True, text=True, timeout=30).stdout
        for line in out.splitlines():
            if line.startswith("LG_GATE_TOKEN="):
                tok = line.split("=", 1)[1].strip()
    except Exception as e:
        cannot_run(f"gate-env.sh did not resolve the dev gate token ({e})")
    if not tok:
        cannot_run("gate-env.sh returned no LG_GATE_TOKEN")
    port = free_port()
    proc = subprocess.Popen(
        [sys.executable, PROXY, "--port", str(port), "--cookies", "/dev/null", "--gate", tok],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    base = f"http://127.0.0.1:{port}"
    for _ in range(40):
        time.sleep(0.25)
        try:
            if urllib.request.urlopen(base + "/", timeout=10).status == 200:
                return proc, base
        except Exception: pass
    proc.terminate()
    cannot_run(f"real-origin-proxy did not come up on {port}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default="", help="existing anon origin; otherwise one is started")
    ap.add_argument("--widths", default="", help="comma-separated override")
    a = ap.parse_args()

    widths = [int(x) for x in a.widths.split(",")] if a.widths else WIDTHS
    proc = None
    if a.url:
        base = a.url.rstrip("/")
    else:
        proc, base = start_proxy()

    log("anon-signin-reachable-gate — a signed-out visitor must be able to get back in")
    log(f"  origin {base}{PATH}   anonymous, one fresh incognito context per width")
    log("")
    try:
        bws = json.load(urllib.request.urlopen(CDP + "/json/version"))["webSocketDebuggerUrl"]
    except Exception as e:
        if proc: proc.terminate()
        cannot_run(f"chrome-dev is not answering on {CDP} ({e})")
    browser = Sock(bws)

    flag_on, flag_path = read_flag(base)
    # A build that predates the flag has no preview to exercise. Saying so and
    # skipping that leg keeps the gate honest during the window between a merge and
    # the serving checkout's pull, when the repo holds the new gate and the origin
    # is still serving the old asset — otherwise it reports a deploy lag as a
    # finding, and a gate that cries wolf gets ignored or edited away.
    flag_known = flag_on is not None
    if not flag_known:
        log("  note: LG_ANON_DASH_SIGNIN is not in the served bottom-nav.js"
            f" ({flag_path or 'not served'}) — pre-flag build.")
        log("  Asserting the historic bar only; the preview leg is SKIPPED, not passed.")
        flag_on = False
    else:
        log(f"  LG_ANON_DASH_SIGNIN = {str(flag_on).lower()}  (read from {flag_path})")
    log("")

    broken, dash_lies = [], []
    try:
        for w in widths:
            mobile = w <= 640
            log(f"— {w}px ({'phone/touch' if mobile else 'desktop'})")
            try:
                res, why = run_width(browser, base, w, mobile)
            except Exception as e:
                browser.close()
                if proc: proc.terminate()
                cannot_run(f"{w}px: {e}")
            if why:
                browser.close()
                if proc: proc.terminate()
                cannot_run(f"{w}px: {why}")
            ok = check(f"{w}px: an anonymous visitor can reach Sign in in <=1 tap",
                       bool(res["route"]),
                       "no visible, hit-testable wp-login link by any route")
            if ok:
                l = res["link"]
                log(f"        via {res['route']} -> {l['el']} {l['w']}x{l['h']} @{l['x']},{l['y']}")
            else:
                broken.append(w)
                for c in res["all"]:
                    reason = ("styled=false (display/visibility/opacity)" if not c["styled"]
                              else "sized=false (0x0)" if not c["sized"]
                              else "off-screen" if not c["inView"]
                              else f"COVERED by {c['top']}" if not c["hit"] else "?")
                    log(f"        candidate {c['el']} {c['href']} -> {reason}")
                if not res["all"]:
                    log("        no wp-login anchor in the DOM at all")

            # ---- (B) the dash must not claim an anonymous visitor can post ----
            comp = res.get("compose")
            if comp is not None:
                if not comp.get("alive"):
                    # Refuse to score an absence we cannot stand behind.
                    browser.close()
                    if proc: proc.terminate()
                    cannot_run(f"{w}px: dash liveness failed — {comp.get('why')}. "
                               "An absence assertion is vacuous without it.")
                slotlist = ", ".join(f"{s['label'] or s['el']}" for s in comp["slots"])

                # ---- ANON TRAY LABEL (Ian's pick, 2026-08-16) ----------------
                # The person slot must read "Account" to a logged-out visitor,
                # never "You" — "You" is wrong for someone with no account, and
                # the tray behind it offers Sign in / Join / Connect Patreon /
                # Reset password, of which reset and Connect Patreon are ACCOUNT
                # jobs. Asserted in BOTH flag states on purpose: this label is
                # independent of LG_ANON_DASH_SIGNIN, so it must hold whether or
                # not the centre slot has become Sign in.
                #
                # Asserted as the ABSENCE of "You" AND the presence of "Account",
                # not just one of them: absence alone passes on a bar that failed
                # to render at all, and this gate already carries a liveness
                # assertion above for exactly that reason.
                labels = [(sl.get("label") or "").strip() for sl in comp["slots"]]
                if not check(f"{w}px: the person slot reads 'Account' to an anon visitor",
                             "Account" in labels,
                             f"anon bar labels are {labels!r} — expected one to be 'Account' "
                             f"(Ian's pick; members keep 'You')"):
                    dash_lies.append(w)
                if not check(f"{w}px: no slot says 'You' to an anon visitor",
                             "You" not in labels,
                             f"anon bar still says 'You' — labels {labels!r}; 'You' is the "
                             f"MEMBER label and is wrong for a visitor with no account"):
                    dash_lies.append(w)

                if flag_on:
                    # The fix is live by default: nothing may claim "post".
                    if not check(f"{w}px: the dash offers an anonymous visitor NO way to 'post'",
                                 not comp["offered"],
                                 "a compose control is visible and tappable to a logged-out visitor"):
                        dash_lies.append(w)
                        for o in comp["offered"]:
                            log(f"        LIES: {o['el']} label={o['label']!r} "
                                f"{o['w']}x{o['h']} @{o['x']},{o['y']} — visible and hit-testable")
                    else:
                        log(f"        dash slots: {slotlist}")
                else:
                    # Flag OFF. The contract is that OFF is the HISTORIC bar,
                    # unchanged — so the "+" being here is what we assert, and a
                    # dash that has silently drifted is the finding.
                    if not check(f"{w}px: flag OFF is the historic bar (unchanged no-op)",
                                 bool(comp["offered"]),
                                 "flag is OFF but the compose control is already gone — "
                                 "the OFF path is not the no-op it claims to be"):
                        dash_lies.append(w)
                    log(f"        dash slots: {slotlist}   [LG_ANON_DASH_SIGNIN=false]")

                # Whichever way the flag is set, the FIXED shape must be correct
                # and reachable under the preview override — that is what Ian
                # clicks before the default flips, so it is gated too.
                if not flag_known:
                    log("        (preview leg skipped — this build has no flag)")
                    continue
                pv, pwhy = run_preview(browser, base, w, "?lgdash=signin")
                if pwhy:
                    browser.close()
                    if proc: proc.terminate()
                    cannot_run(f"{w}px preview: {pwhy}")
                pc = pv["compose"]
                if not pc.get("alive"):
                    browser.close()
                    if proc: proc.terminate()
                    cannot_run(f"{w}px preview: dash liveness failed — {pc.get('why')}")
                if not check(f"{w}px: with the fix ON, nothing in the dash claims 'post'",
                             not pc["offered"]):
                    dash_lies.append(w)
                    for o in pc["offered"]:
                        log(f"        LIES: {o['el']} label={o['label']!r}")
                # ...and the fix must not have cost the visitor anything.
                if not check(f"{w}px: with the fix ON, sign-in is reachable with NO tap at all",
                             bool(pv["reach"]["ok"]),
                             "the replacement slot is not a visible, hit-testable wp-login link"):
                    dash_lies.append(w)
                else:
                    l = pv["reach"]["ok"][0]
                    log(f"        -> {l['el']} {l['text']!r} {l['w']}x{l['h']} @{l['x']},{l['y']}")
                if not check(f"{w}px: with the fix ON, browsing is NOT reduced "
                             f"(dash keeps all its slots)",
                             len(pc["slots"]) >= 3, f"only {len(pc['slots'])} slots"):
                    dash_lies.append(w)
    finally:
        browser.close()
        if proc: proc.terminate()

    log("")
    log(f"  {passes} passed, {failures} failed")
    if failures:
        log("")
        if broken:
            log(f"  RED — a signed-out member is locked out at: "
                f"{', '.join(str(x) for x in broken)}px")
            log("  Presence in the HTML is not the question; reachability is.")
        if dash_lies:
            log(f"  RED — the mobile dash offers a logged-out visitor a compose control at: "
                f"{', '.join(str(x) for x in dash_lies)}px")
            log("  A control that cannot do what it claims is worse than a missing one:")
            log("  it spends the one tap a stranger was willing to give the site.")
        return 1
    log("  GREEN — every tested width offers a way back in, and none offers a post it cannot honour.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
