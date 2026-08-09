#!/usr/bin/env python3
"""Backlog 4.4 + 4.3 — reproduce the dead controls in the MOBILE PROFILE TRAY.

Ian 8/8: "we have a problem with dms on mobile when accessing from the user's
profile tray" (4.4) and "The 3 dots for the profile menu don't work either" (4.3).

HYPOTHESIS UNDER TEST — one cause, both tickets:
  profile-app/src/Social.php server-renders the whole social widget (Connect /
  Message / Accept / Decline / the "..." menu) and prints its behaviour ONCE, as an
  inline <script> that installs a single DELEGATED document click listener behind
  window.__lgSocialWired.
  webroot/profile-sheet.js:343 strips <script> out of the fetched /u/ markup before
  injecting it, so on the tray the BUTTONS arrive and the LISTENER never does.

So this run measures the same buttons TWICE at the SAME 390px as the SAME member:
  DEFECT  — opened in the tray  (profile sheet over a host page)
  CONTROL — the full /u/ page    (where the inline script really ran)
A control that PASSES is what makes the defect a defect and not a viewport story.

Traps this driver is built around (each has cost this fleet hours before):
  * ONE persistent CDP websocket. Per-command sockets drop the device emulation and
    the run then false-PASSes as DESKTOP while the log says mobile.
  * Cookies are CLEARED first. The shared chrome-dev profile otherwise keeps a second
    host-only-vs-dotted WP cookie and the run executes as a DIFFERENT member.
  * Every tap is HIT-TESTED with elementFromPoint before dispatch. A blind click that
    lands on the fixed tabbar still "succeeds".
  * Viewport-only screenshots. captureBeyondViewport renders fixed chrome at the
    wrong offset and breaks mobile frames.
  * Real touchStart/touchEnd, not Input.dispatchMouseEvent.
"""
import json, sys, time, base64, os, urllib.request, websocket

CDP = "http://127.0.0.1:9222"
BASE = os.environ.get("LG_BASE", "https://dev2.loothgroup.com")
COOKIES = os.environ.get("LG_COOKIES", "/tmp/mobile-bugs-exercise/cookies.txt")
SHOTS = os.environ.get("LG_SHOTS", "/tmp/mobile-bugs-exercise/shots")
# Any logged-in non-owner profile shows the "..." menu; Message needs an ACCEPTED edge.
SUBJECT = os.environ.get("LG_SUBJECT", "the-guitar-specialist")
HOST_PAGE = os.environ.get("LG_HOST_PAGE", BASE + "/hub/")


def gate_token():
    """The dev-gate token is a BOX-LOCAL secret and must never enter the repo, so it
    is read from the box at runtime (or handed in via LG_GATE_COOKIE)."""
    t = os.environ.get("LG_GATE_COOKIE")
    if t:
        return t
    import subprocess, re
    out = subprocess.run(
        ["sudo", "grep", "-h", "loothdev_dev_ok", "/etc/nginx/conf.d/loothdev-auth.conf"],
        capture_output=True, text=True).stdout
    m = re.search(r'"([A-Za-z0-9]{16,})"\s+1', out)
    if not m:
        raise SystemExit("could not read the dev-gate token; set LG_GATE_COOKIE")
    return m.group(1)


GATE_TOKEN = gate_token()
WP_UID = os.environ.get("LG_UID", "1912")


def jwt_token():
    """profile-app's `looth_id` JWT for the test member, minted by the real mu-plugin
    (same code path as a browser login) rather than faked."""
    t = os.environ.get("LG_LOOTH_ID")
    if t:
        return t
    import subprocess
    out = subprocess.run(
        ["sudo", "-u", "looth-dev", "wp", "--path=/var/www/dev", "eval",
         f'echo looth_auth_mint_jwt(get_user_by("id", {int(WP_UID)}));'],
        capture_output=True, text=True).stdout.strip().splitlines()
    tok = [l for l in out if l.count(".") == 2 and l.startswith("eyJ")]
    if not tok:
        raise SystemExit("could not mint looth_id; set LG_LOOTH_ID")
    return tok[-1]


class Page:
    def __init__(self, ws):
        self.ws = websocket.create_connection(ws, timeout=45, suppress_origin=True)
        self.n = 0

    def send(self, m, p=None):
        self.n += 1
        i = self.n
        self.ws.send(json.dumps({"id": i, "method": m, "params": p or {}}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("id") == i:
                if "error" in r:
                    raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})

    def ev(self, e):
        r = self.send("Runtime.evaluate",
                      {"expression": e, "returnByValue": True, "awaitPromise": True})
        if r.get("exceptionDetails"):
            raise RuntimeError("JS: " + str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")

    def close(self):
        try:
            self.ws.close()
        except Exception:
            pass


def new_page():
    t = json.load(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")))
    return t["id"], Page(t["webSocketDebuggerUrl"])


def close_page(tid):
    try:
        urllib.request.urlopen(CDP + f"/json/close/{tid}").read()
    except Exception:
        pass


def auth(p):
    """Two DIFFERENT cookie shapes, and mixing them up costs a whole run.

    The dev gate (nginx conf.d/loothdev-auth.conf, ARMED 2026-07-04) authorizes on
    dev cookie | tester cookie | LOOPBACK | exempt path. curl --resolve to 127.0.0.1
    is authorized by loopback and needs no cookie at all; chrome-dev is launched with
    --host-resolver-rules=MAP dev2.loothgroup.com 172.31.78.94, so the browser is NOT
    loopback and gets a bare nginx 403 unless it carries the gate cookie — issued by
    nginx with Domain=.dev2.loothgroup.com, so it must be set DOTTED.
    The WP auth cookies are the opposite: HOST-ONLY, or WP sees two of each and the
    run silently executes as a different member.
    Clear first for the same reason (shared chrome-dev profile).

    THIRD cookie, and it is the one that is easy to miss: /u/ is profile-app, NOT
    WordPress. It authenticates off the `looth_id` RS256 JWT that the WP mu-plugin
    profile-auth.php mints, issued with domain=.dev2.loothgroup.com — so it is DOTTED
    too. With only the WP cookies the gate lets you through and /u/ renders you as
    ANONYMOUS, which silently deletes the entire social widget and would have read as
    "the buttons are missing" instead of "the buttons are dead"."""
    p.send("Network.enable")
    p.send("Network.clearBrowserCookies")
    cks = [{"name": "loothdev_auth", "value": GATE_TOKEN,
            "domain": ".dev2.loothgroup.com", "path": "/", "secure": True},
           {"name": "looth_id", "value": jwt_token(),
            "domain": ".dev2.loothgroup.com", "path": "/", "secure": True}]
    cks += [{"name": k.strip(), "value": v.strip(), "url": BASE, "path": "/"}
            for k, v in (l.split("=", 1) for l in open(COOKIES) if "=" in l)]
    p.send("Network.setCookies", {"cookies": cks})
    return len(cks)


def assert_live(p, where):
    """Refuse to measure anything on a 403/challenge/desktop page.

    Every number below is an ABSENCE claim ("the menu did not open"), and an absence
    claim is vacuous on a page that has no menu because it has no site. This is the
    liveness half — it must run before any probe, at every navigation."""
    # The shared header (lg-shared/site-header.php) is the one identity marker that
    # spans BOTH runtimes here — WordPress/bb-mirror AND profile-app: .lg-chrome__avatar
    # for a member, .lg-chrome__signin for anon. A WP body class does not exist on /u/.
    st = p.ev("""[document.title, innerWidth, matchMedia('(max-width:640px)').matches,
                  !!document.querySelector('meta[name=viewport]'),
                  !!document.querySelector('.lg-chrome__avatar'),
                  !!document.querySelector('.lg-chrome__signin')]""")
    title, w, phone, hasvp, avatar, signin = st
    bad = []
    if "403" in str(title) or "Forbidden" in str(title): bad.append(f"GATE 403 ({title})")
    if not hasvp:  bad.append("no viewport meta — not a real site page")
    if w != 390:   bad.append(f"viewport {w}px, not 390 — emulation did NOT take")
    if not phone:  bad.append("(max-width:640px) is FALSE — running as DESKTOP")
    if signin:     bad.append("header shows Sign in — ANONYMOUS, not a member")
    if not avatar: bad.append("header has no member avatar — identity did not resolve")
    if bad:
        raise SystemExit(f"\n!! ABORT at {where}: " + "; ".join(bad) +
                         "\n   Refusing to report absences measured on the wrong page.")
    return f"390px phone, logged in, title={title!r}"


def mobile(p, w=390, h=844):
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": w, "height": h, "deviceScaleFactor": 3, "mobile": True})
    p.send("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})
    p.send("Emulation.setEmitTouchEventsForMouse",
           {"enabled": True, "configuration": "mobile"})


def goto(p, url, settle=4.5):
    p.send("Page.navigate", {"url": url})
    for _ in range(160):
        time.sleep(0.25)
        try:
            if p.ev("document.readyState") == "complete":
                break
        except Exception:
            pass
    time.sleep(settle)          # pwa.js loads the sheets on requestIdleCallback


def shot(p, name):
    os.makedirs(SHOTS, exist_ok=True)
    r = p.send("Page.captureScreenshot", {"format": "png"})   # viewport-only, on purpose
    path = os.path.join(SHOTS, name + ".png")
    open(path, "wb").write(base64.b64decode(r["data"]))
    return path


def tap(p, sel, label=""):
    """Hit-test, then a REAL touch. Returns (ok, what_is_actually_there)."""
    box = p.ev("""(function(){
      var el = document.querySelector(%s);
      if (!el) return null;
      var r = el.getBoundingClientRect();
      if (!r.width || !r.height) return {dead:true, w:r.width, h:r.height};
      var x = r.left + r.width/2, y = r.top + r.height/2;
      var hit = document.elementFromPoint(x, y);
      return {x:x, y:y, hit: hit ? (hit.tagName+'.'+(hit.className||'')).slice(0,80) : null,
              inside: !!(hit && (el.contains(hit) || hit.contains(el)))};
    })()""" % json.dumps(sel))
    if not box:
        return False, "NO SUCH ELEMENT"
    if box.get("dead"):
        return False, f"zero-size ({box['w']}x{box['h']})"
    if not box["inside"]:
        return False, f"OCCLUDED by {box['hit']}"
    for t in ("touchStart", "touchEnd"):
        p.send("Input.dispatchTouchEvent", {
            "type": t,
            "touchPoints": [] if t == "touchEnd" else
                           [{"x": box["x"], "y": box["y"], "radiusX": 12, "radiusY": 12}],
        })
        time.sleep(0.05)
    time.sleep(0.9)
    return True, box["hit"]


# ── the probes ──────────────────────────────────────────────────────────────
PROBE = """(function(){
  var scope = %s;
  var root = scope ? document.querySelector(scope) : document;
  if (!root) return {missing_scope:true};
  var more = root.querySelector('.lg-social-morebtn');
  var menu = root.querySelector('.lg-social-menu');
  var msg  = root.querySelector('[data-lg-social="message"]');
  var acts = root.querySelectorAll('[data-lg-social]');
  var names = []; for (var i=0;i<acts.length;i++) names.push(acts[i].getAttribute('data-lg-social'));
  return {
    wired:        !!window.__lgSocialWired,      // did Social.php's inline script ever run?
    widget:       !!root.querySelector('.lg-social-actions'),
    more_btn:     !!more,
    menu_present: !!menu,
    menu_hidden:  menu ? !!menu.hidden : null,   // the whole ballgame for 4.3
    msg_btn:      !!msg,
    actions:      names,
    messenger_open: !!(document.getElementById('looth-msgr') &&
                       document.getElementById('looth-msgr').classList.contains('is-open')),
    tray_open:    !!(document.getElementById('looth-prof-sheet') &&
                     document.getElementById('looth-prof-sheet').classList.contains('is-open'))
  };
})()"""


def probe(p, scope=None):
    return p.ev(PROBE % json.dumps(scope) if scope else PROBE % "null")


def line(k, v):
    print(f"  {k:<22}: {v}")


def main():
    tid, p = new_page()
    try:
        n = auth(p)
        mobile(p)
        p.send("Page.enable")
        # Count every lg:open-dm the page emits — 4.4 is "does the intent even fire".
        p.send("Page.addScriptToEvaluateOnNewDocument", {"source": """
          window.__dmEvents = [];
          document.addEventListener('lg:open-dm', function(e){
            window.__dmEvents.push((e.detail&&e.detail.uuid)||'?'); }, true);
          window.addEventListener('lg:open-dm', function(e){
            window.__dmEvents.push('w:'+((e.detail&&e.detail.uuid)||'?')); }, true);
        """})

        print(f"\n=== SETUP ===  {n} cookies, base {BASE}")

        # ---------------------------------------------------------------- CONTROL
        print(f"\n=== CONTROL — the FULL /u/ page at 390px (same member, same buttons) ===")
        goto(p, f"{BASE}/u/{SUBJECT}")
        line("liveness", assert_live(p, "control /u/ page"))
        line("viewport", p.ev("[innerWidth, innerHeight, navigator.maxTouchPoints, "
                              "matchMedia('(max-width:640px)').matches]"))
        line("acting as", p.ev("""(function(){var m=document.cookie.match(
              /wordpress_logged_in_[^=]+=([^%|]+)/); return m?decodeURIComponent(m[1]):'?';})()"""))
        c = probe(p)
        for k in ("wired", "widget", "more_btn", "menu_present", "menu_hidden",
                  "msg_btn", "actions"):
            line(k, c.get(k))
        shot(p, "control-1-page")
        ok, hit = tap(p, ".lg-social-morebtn")
        line("tap ... ", f"{ok} ({hit})")
        c2 = probe(p)
        line("menu_hidden AFTER", c2.get("menu_hidden"))
        shot(p, "control-2-menu-open")
        # 4.4's control: the SAME Message button on the full page must actually DM.
        p.ev("document.body.click()")          # dismiss the menu we just opened
        time.sleep(0.4)
        cdm_before = p.ev("(window.__dmEvents||[]).length")
        okc, hitc = tap(p, '[data-lg-social="message"]')
        line("tap Message", f"{okc} ({hitc})")
        time.sleep(1.8)
        c_dm = p.ev("(window.__dmEvents||[]).length") - cdm_before
        c_msgr = p.ev("!!(document.getElementById('looth-msgr')&&"
                      "document.getElementById('looth-msgr').classList.contains('is-open'))")
        line("lg:open-dm fired", c_dm)
        line("messenger opened", c_msgr)
        shot(p, "control-3-after-message-tap")
        control_pass = (c.get("more_btn") and c2.get("menu_hidden") is False)
        print(f"  >> CONTROL {'PASS — the 3-dots menu OPENS here' if control_pass else 'FAILED — cannot trust the defect below'}")
        print(f"  >> CONTROL {'PASS — Message OPENS the messenger here' if c_msgr else 'Message did NOT open the messenger here either'}")

        # ---------------------------------------------------------------- DEFECT
        print(f"\n=== DEFECT — the same profile opened in the MOBILE TRAY ===")
        goto(p, HOST_PAGE)
        line("liveness", assert_live(p, "host page"))
        line("host page", p.ev("location.pathname"))
        line("tray layer loaded", p.ev("!!window.openProfileSheet"))
        line("__lgSocialWired here", p.ev("!!window.__lgSocialWired"))

        # Open the tray the way a member does: tap a /u/ link if the page has one,
        # else call the layer's own entry point (what that click handler invokes).
        link = p.ev("""(function(){var a=document.querySelector('a[href*="/u/"]');
                        return a?a.getAttribute('href'):null;})()""")
        if link:
            ok, hit = tap(p, 'a[href*="/u/"]')
            line("tapped /u/ link", f"{link} -> {ok} ({hit})")
            time.sleep(2.5)
        if not p.ev("!!(document.getElementById('looth-prof-sheet')&&"
                    "document.getElementById('looth-prof-sheet').classList.contains('is-open'))"):
            line("fallback", f"openProfileSheet('{SUBJECT}')")
            p.ev(f"window.openProfileSheet && window.openProfileSheet('{SUBJECT}')")
            time.sleep(4.0)

        d = probe(p, "#looth-prof-sheet")
        for k in ("tray_open", "wired", "widget", "more_btn", "menu_present",
                  "menu_hidden", "msg_btn", "actions"):
            line(k, d.get(k))
        shot(p, "defect-1-tray")

        ok, hit = tap(p, "#looth-prof-sheet .lg-social-morebtn")
        line("tap ... ", f"{ok} ({hit})")
        d2 = probe(p, "#looth-prof-sheet")
        line("menu_hidden AFTER", d2.get("menu_hidden"))
        shot(p, "defect-2-after-dots-tap")

        dm_before = p.ev("(window.__dmEvents||[]).length")
        okm, hitm = tap(p, '#looth-prof-sheet [data-lg-social="message"]')
        line("tap Message", f"{okm} ({hitm})")
        time.sleep(1.5)
        d3 = probe(p, "#looth-prof-sheet")
        line("lg:open-dm fired", p.ev("(window.__dmEvents||[]).length") - dm_before)
        line("messenger opened", d3.get("messenger_open"))
        shot(p, "defect-3-after-message-tap")

        # ------------------------------------------------- the OTHER 4.4 reading
        print(f"\n=== 4.4 second reading — the YOU-SHEET 'Messages' row ===")
        p.ev("""(function(){var s=document.getElementById('looth-prof-sheet');
                 if(s){var x=s.querySelector('.lps-x'); if(x) x.click();}})()""")
        time.sleep(1.0)
        line("messenger layer loaded", p.ev("!!window.openMessenger"))
        ok, hit = tap(p, '#looth-tabbar a[href="/profile/edit"]')
        line("tap You tab", f"{ok} ({hit})")
        time.sleep(1.2)
        shot(p, "you-1-sheet")
        ok, hit = tap(p, ".lt-sheet__row--msgs")
        line("tap Messages row", f"{ok} ({hit})")
        time.sleep(1.8)
        line("messenger open", p.ev("!!(document.getElementById('looth-msgr')&&"
                                    "document.getElementById('looth-msgr')"
                                    ".classList.contains('is-open'))"))
        shot(p, "you-2-after-messages-tap")

        # ---------------------------------------------------------------- VERDICT
        print(f"\n=== VERDICT ===")
        print(f"  4.3  tray 3-dots  : markup {'PRESENT' if d.get('more_btn') else 'ABSENT'}, "
              f"after tap menu_hidden={d2.get('menu_hidden')} "
              f"({'DEFECT REPRODUCED' if d.get('more_btn') and d2.get('menu_hidden') is not False else 'not reproduced'})")
        print(f"  4.3  control      : after tap menu_hidden={c2.get('menu_hidden')} "
              f"({'control PASSES — entry-path specific' if c2.get('menu_hidden') is False else 'CONTROL BROKEN'})")
        print(f"  wiring            : full page __lgSocialWired={c.get('wired')} "
              f"vs tray host __lgSocialWired={d.get('wired')}")
        dm_fired = p.ev("(window.__dmEvents||[]).length") - dm_before
        print(f"  4.4  tray Message : btn {'PRESENT' if d.get('msg_btn') else 'ABSENT (needs an ACCEPTED connection)'}, "
              f"tap fired {dm_fired} lg:open-dm, messenger={d3.get('messenger_open')} "
              f"({'DEFECT REPRODUCED' if d.get('msg_btn') and not d3.get('messenger_open') else 'not reproduced'})")
        print(f"  4.4  control      : fired {c_dm} lg:open-dm, messenger={c_msgr} "
              f"({'control PASSES — entry-path specific' if c_msgr else 'CONTROL BROKEN'})")
        print(f"  4.4  You-sheet    : the OTHER reading of Ian's words — measured above; "
              f"if it opened, the You tray is NOT the bug and the profile sheet is")
        print(f"\n  shots -> {SHOTS}")
    finally:
        p.close()
        close_page(tid)


if __name__ == "__main__":
    sys.exit(main())
