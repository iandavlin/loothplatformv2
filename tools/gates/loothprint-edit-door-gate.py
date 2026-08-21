#!/usr/bin/env python3
"""
loothprint-edit-door-gate.py — GATE 69 — the way IN to editing your own Loothprint.

Number minted by keeper 2026-08-16. Lanes never self-number. REWRITTEN IN PLACE
for #179 rather than minting a second number: the control is the same control,
it moved and it lost a menu, so its gate moves with it (the #172 precedent).

══ WHAT CHANGED, AND WHY THE OLD ASSERTIONS ARE GONE ═══════════════════════════

Ian, 2026-08-21, superseding his own Option A of 8/16: "I don't think we need the
option for text and data. It can be one button that kicks to the form they filled
out. All data is on the form." And, earlier the same night, on placement: "could
we put it in the bottom stack of sticky buttons ?"

So the two-line choice menu is deleted and the control lives in the dock. Every
assertion below that used to say "the menu has two items" now says something
about ONE pill in the dock — the ruling did not weaken the gate, it re-aimed it.

══ THE RULING THAT NEEDS THREE VIEWERS, NOT TWO ════════════════════════════════

Ian ruled "members one, admins two": collapsing to a single door would have taken
away the only click-path to the page-text editor, and the header's Edit button is
NOT that path (measured 8/21 — it goes to /wp-admin/). So a plain author gets ONE
pill and a cap-holder gets TWO.

That is only testable with a viewer who is an author and NOT a cap-holder. The old
gate's "entitled" user was claude_admin, who holds edit_archive_poc — against that
account alone, "members get one door" is unfalsifiable. Hence three probes:

    patreon_77159883  id 109   AUTHOR of the probe post, no cap   -> exactly 1
    claude_admin      id 1912  edit_archive_poc                   -> exactly 2
    erin.vogel        id 1767  neither                            -> exactly 0

══ WHAT IT ASSERTS, AND WHY EACH IS HERE RATHER THAN THE CHEAPER VERSION ═══════

  1. LIVENESS, AND SIGNED-IN LIVENESS. Identity comes from a server-side /whoami
     loopback which is INTERMITTENT for a minted cookie — measured on the old
     gate: the same tree gave items=2 on one run and items=0 on the next. Without
     it, "no pill" and "not recognised" are one observation and every assertion
     below becomes a coin toss reported as a verdict. Signed-out => exit 2.

  2. THE CONTROL IS IN THE DOCK. Not "an Edit exists" — it existed before, in the
     opposite corner. Ian moved it, so where it lives IS the assertion: the pill
     must be a DESCENDANT of .lg-standalone-dock.

  3. THE OLD CONTROL IS GONE. .lg-standalone-editwrap / .lg-standalone-edit must
     not survive anywhere on the page. An absence, so it is paired with 1 and 2 —
     "the old menu is gone" is trivially true of a page that rendered nothing.

  4. EDIT POINTS AT A REAL POST. A POSITIVE id. The first build of this feature
     read $postContext['id'], a key that does not exist in that context, and would
     have shipped a link to id=0 — a compose form for nothing.

  5. PAGE TEXT SURVIVES FOR CAP-HOLDERS ONLY. ?lg_edit=1 must still be one click
     away for edit_archive_poc, and must NOT be rendered for a plain author. Both
     directions, because "members one" and "admins two" are two different claims.

  6. IT READS THE FLAG RATHER THAN HARDCODING A STATE. The Edit pill points at
     /compose/ only when compose is switched on; with the flag off it falls back
     to ?lg_edit=1 rather than pointing at a switched-off route (the UI-lies
     class). The gate resolves the same pair the app does and asserts they AGREE,
     so flipping the default needs no edit here.

  7. THE PILL FAMILY (#179 deliverable 2). Ian: the floating buttons must read as
     one family at every width. Measured before the build, at 900 and 1280: four
     controls 45 / 64 / 45 / 47 px wide, the react wrapper square-cornered, versus
     one labelled pill family at 1600. So: every DIRECT CHILD of the dock has the
     same height, a pill radius, and a shared edge on the axis it is laid out on
     (a row shares tops, a column shares lefts). Direct children on purpose
     — the react control is a wrapper div whose inner button carried the pill,
     which is exactly how it drifted out of the family in the first place.
     ⚠️ WIDTH IS DELIBERATELY NOT ASSERTED, and that is a correction rather than a
     softening: the wide look Ian is matching runs 83 / 105 / 84 / 126 px, so equal
     width was never the rule. Forcing it would mean dropping the reaction and
     comment COUNTS at middle widths, i.e. removing information to satisfy a gate.
     The heights (34 / 35 / 44) and the square-cornered react wrapper are the
     measured defect, and those are what this asserts.

  8. IT STAYS OFF THE ARTICLE (GH #53 / HK-027, and this is the one that is law).
     Asserted as the real thing — the dock's right edge against the leftmost
     RENDERED BODY TEXT, scrolled to where the two actually share a band — not
     against the article wrapper box, which has padding and would have passed a
     dock sitting on the words. Asserted at 900 and 1280.
     ⚠️ DELIBERATELY NOT ASSERTED AT 641-700, WHERE MAIN IS ALREADY RED: measured
     on main 2026-08-21, leftmost body text x = 41.6 at 641 and 44.0 at 700
     against a dock right edge of 82, i.e. the compact stack already covers ~40px
     of body copy at the bottom of its own media query. That is a pre-existing
     defect, reported not fixed by #179, and asserting it here would make this
     gate red for somebody else's bug and block every lane.

  9. THE PHONE ROW FITS, AND IS STILL ONE ROW. At 390 the dock is a horizontal row
     and the fifth pill is what breaks it: measured 343px of row in a ~354px budget
     BEFORE Edit was added, which is why #179 hides the Save/Comments words on a
     phone as they already are at middle widths (the counts stay). Asserted as
     "fits the viewport" AND "the dock is no taller than its tallest child", so a
     row that silently wrapped onto two lines cannot read as a pass.

 10. BOTH THEMES. The dock is member-facing furniture and the pills must not be a
     bright slab in dark.

Exit: 0 green, 1 a real defect, 2 CANNOT RUN.
⚠️ CANNOT RUN IS 2, NOT 3 — run-all.sh reads anything else as RED.
"""
import argparse, json, os, subprocess, sys, time

try:
    import websocket
except Exception as e:
    print(f"CANNOT RUN: websocket-client unavailable: {e}")
    sys.exit(2)

CDP  = "http://127.0.0.1:9222"
REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))


class CannotRun(Exception):
    pass


def sh(c):
    return subprocess.run(c, capture_output=True, text=True)


def gate_env():
    r = sh(["bash", os.path.join(REPO, "tools", "gates", "gate-env.sh")])
    if r.returncode != 0:
        raise CannotRun("gate-env.sh failed: " + r.stderr.strip()[:200])
    return dict(l.partition("=")[::2] for l in r.stdout.splitlines())


def wp_cookies(login):
    r = sh(["sudo", "-n", "-u", "looth-dev", "wp", "--path=/var/www/dev", "eval",
            f"$u=get_user_by('login','{login}');if(!$u){{echo 'NOUSER';exit;}}$e=time()+3600;"
            "echo LOGGED_IN_COOKIE.'|'.wp_generate_auth_cookie($u->ID,$e,'logged_in').\"\\n\";"
            "echo SECURE_AUTH_COOKIE.'|'.wp_generate_auth_cookie($u->ID,$e,'secure_auth');"])
    out = [l for l in r.stdout.splitlines() if "|" in l and not l.startswith(("PHP ", "Warning"))]
    if not out or "NOUSER" in r.stdout:
        raise CannotRun(f"could not mint a session for {login}")
    return [l.split("|", 1) for l in out]


def compose_flag_on():
    """The SAME pair the RUNNING APP reads — tracked config, then the per-box override.

    ⚠️ RESOLVED FROM THE SERVING CHECKOUT, NOT FROM THIS REPO, and the difference is
    the whole point. The override is gitignored and box-local: it exists ONLY beside
    the deployed app, never in a lane's worktree. Reading REPO/platform/config from a
    worktree therefore always answers OFF — the tracked default — while the app the
    gate is measuring answers ON. The first run of the old gate did exactly that and
    reported a flag/link disagreement that was entirely its own.
    """
    base = "/srv/archive-poc/../platform/config"
    if not os.path.isdir(base):
        base = os.path.join(REPO, "platform", "config")
    php = ("$on=false;$b=%s;"
           "if(is_readable($b.'/frontend-compose.php')){$r=include $b.'/frontend-compose.php';"
           "$on=(is_array($r)&&($r['enabled']??false)===true);}"
           "if(is_readable($b.'/frontend-compose.local.php')){$l=include $b.'/frontend-compose.local.php';"
           "if(is_array($l)&&array_key_exists('enabled',$l)){$on=($l['enabled']===true);}}"
           "echo $on?'ON':'OFF';") % json.dumps(base)
    r = sh(["php", "-r", php])
    if r.stdout.strip() not in ("ON", "OFF"):
        raise CannotRun("could not resolve the compose flag: " + (r.stderr or r.stdout)[:160])
    return r.stdout.strip() == "ON"


class Tab:
    def __init__(self):
        try:
            t = json.loads(subprocess.check_output(
                ["curl", "-s", "-X", "PUT", f"{CDP}/json/new?about:blank"]))
        except Exception as e:
            raise CannotRun(f"no CDP browser on {CDP}: {e}")
        self.id = t["id"]
        self.ws = websocket.create_connection(t["webSocketDebuggerUrl"],
                                              suppress_origin=True, timeout=60)
        self.n = 0

    def call(self, m, **p):
        self.n += 1
        self.ws.send(json.dumps({"id": self.n, "method": m, "params": p}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("id") == self.n:
                if "error" in r:
                    raise CannotRun(f"{m}: {r['error']}")
                return r.get("result", {})

    def js(self, expr):
        for _ in range(10):
            time.sleep(0.6)
            v = self.call("Runtime.evaluate", expression=expr,
                          returnByValue=True).get("result", {}).get("value")
            if v:
                return v
        return None

    def close(self):
        try:
            self.ws.close()
        finally:
            sh(["curl", "-s", f"{CDP}/json/close/{self.id}"])


# Scrolled BEFORE measuring: at the top of the page the article has not reached
# the bottom-fixed dock, so "no overlap" would be true of every build. The
# clearance question is only meaningful where the two share a horizontal band.
PROBE = r"""(() => {
  window.scrollTo(0, 900);
  const srgb=c=>{c/=255;return c<=0.04045?c/12.92:Math.pow((c+0.055)/1.055,2.4)};
  const px=s=>{const m=s.match(/rgba?\(([^)]+)\)/);if(!m)return null;
    const p=m[1].split(',').map(Number);return {r:p[0],g:p[1],b:p[2]}};
  const lum=c=>0.2126*srgb(c.r)+0.7152*srgb(c.g)+0.0722*srgb(c.b);
  const R=n=>{const b=n.getBoundingClientRect();
    return {x:+b.x.toFixed(1),y:+b.y.toFixed(1),w:+b.width.toFixed(1),h:+b.height.toFixed(1)}};

  const dock  = document.querySelector('.lg-standalone-dock');
  const kids  = dock ? [...dock.children] : [];
  const dr    = dock ? R(dock) : null;

  /* The leftmost RENDERED body text that shares a horizontal band with the dock.
     Range rects, not element boxes: an element box includes padding, and a dock
     sitting inside that padding is clear of the words. */
  let textLeft = null;
  const art = document.querySelector('.lg-article');
  if (art && dr) {
    const top = dr.y, bot = dr.y + dr.h;
    const w = document.createTreeWalker(art, NodeFilter.SHOW_TEXT);
    for (let n = w.nextNode(); n; n = w.nextNode()) {
      if (!n.nodeValue || !n.nodeValue.trim()) continue;
      const rg = document.createRange(); rg.selectNodeContents(n);
      for (const r of rg.getClientRects()) {
        if (r.width < 1 || r.height < 1) continue;
        if (r.bottom < top || r.top > bot) continue;      /* not in the dock's band */
        if (textLeft === null || r.left < textLeft) textLeft = +r.left.toFixed(1);
      }
    }
  }

  const edit = document.querySelector('[data-lg-edit]');
  const page = document.querySelector('[data-lg-pagetext]');
  const dockBg = kids.length ? px(getComputedStyle(kids[0]).backgroundColor) : null;

  return JSON.stringify({
    live:      !!document.querySelector('.lg-standalone-main, article, main'),
    signedOut: !!document.querySelector('.lg-chrome__signin'),
    dock:      dr,
    kids: kids.map(k => { const b = R(k); const cs = getComputedStyle(k);
      return {cls:k.className, w:b.w, h:b.h, top:b.y, left:b.x, radius:cs.borderRadius}; }),
    textLeft,
    /* every edit affordance anywhere on the page, however it is classed */
    /* counted by the NEW data hooks ONLY. Counting the old classes too let
       "a cap-holder gets exactly two" pass on main, where the two are the old
       wrap and the button inside it — a vacuous green on the very claim. */
    editN:     document.querySelectorAll('[data-lg-edit],[data-lg-pagetext]').length,
    editHref:  edit ? (edit.getAttribute('href') || '') : null,
    editInDock: !!(edit && dock && dock.contains(edit)),
    pageHref:  page ? (page.getAttribute('href') || '') : null,
    pageInDock: !!(page && dock && dock.contains(page)),
    oldControl: !!document.querySelector('.lg-standalone-editwrap, .lg-standalone-edit'),
    pillLum:   dockBg ? +lum(dockBg).toFixed(3) : null,
  });
})()"""


def look(tab, url, cookies, theme, width):
    tab.call("Emulation.setDeviceMetricsOverride", width=width, height=1400,
             deviceScaleFactor=1, mobile=width < 641, screenWidth=width, screenHeight=1400)
    tab.call("Network.setCookies", cookies=cookies)
    tab.call("Page.navigate", url=url); time.sleep(2.5)
    tab.call("Runtime.evaluate", expression=(
        f"localStorage.setItem('lg-set-theme','{theme}');"
        f"document.documentElement.setAttribute('data-lguser-theme','{theme}');"))
    tab.call("Page.navigate", url=url); time.sleep(3.2)
    raw = tab.js(PROBE)
    if not raw:
        raise CannotRun(f"page never answered at {url}")
    return json.loads(raw)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--slug", default="fret-sander-v2")
    ap.add_argument("--path", default="/loothprint/{slug}/",
                    help="the path to measure; '{slug}' is substituted. Point it at a lane "
                         "preview to gate a BRANCH: /srv/archive-poc is a symlink into the "
                         "serving checkout, so the default path always measures MAIN and a "
                         "lane 'verifying on dev2' would really be reading its own diff.")
    ap.add_argument("--post", type=int, default=72155)
    ap.add_argument("--author", default="patreon_77159883",
                    help="the post's OWN author, WITHOUT edit_archive_poc — gets exactly ONE pill")
    ap.add_argument("--capholder", default="claude_admin",
                    help="an edit_archive_poc holder — gets Edit AND Page")
    ap.add_argument("--stranger", default="erin.vogel",
                    help="a member who should get NO edit control at all")
    a = ap.parse_args()

    env  = gate_env(); dom, base = env["LG_GATE_DOMAIN"], env["LG_GATE_HOST"]
    url  = base + a.path.replace("{slug}", a.slug)
    flag = compose_flag_on()
    print(f"  compose flag reads {'ON' if flag else 'OFF'} "
          f"(from the serving checkout, the same pair the app reads)")

    def jar(login):
        c = [{"name": "loothdev_auth", "value": env["LG_GATE_TOKEN"],
              "domain": "." + dom, "path": "/", "secure": True}]
        for n, v in wp_cookies(login):
            c.append({"name": n, "value": v, "domain": dom, "path": "/",
                      "secure": True, "httpOnly": True, "sameSite": "Lax"})
        return c

    fails, checked = [], 0

    def chk(label, ok, detail=""):
        nonlocal checked
        checked += 1
        print(f"    {'ok  ' if ok else 'RED '} {label} {detail}")
        if not ok:
            fails.append(f"{label} {detail}")

    tab = Tab()
    try:
        tab.call("Page.enable"); tab.call("Network.enable")

        # ── the AUTHOR: one pill, in the dock, pointing at a real post ────────
        for theme, width in (("light", 1280), ("dark", 1280), ("light", 900), ("light", 390)):
            tab.call("Network.clearBrowserCookies")
            d = look(tab, url, jar(a.author), theme, width)
            if not d["live"]:
                raise CannotRun(f"the loothprint page did not render at {width} {theme}")
            if d["signedOut"]:
                print(f"  CANNOT RUN: page renders us signed OUT at {width}/{theme}, so the "
                      f"door is correctly absent and proves nothing")
                return 2
            print(f"  author @{width} {theme}: liveness ok (page rendered, viewer signed in)")

            if not d["dock"]:
                chk("the sticky dock renders", False, "no .lg-standalone-dock")
                continue

            # 2/3/4 — the control moved, and the old one is gone
            chk("Edit is IN the dock", d["editInDock"], str(d["editHref"])[:56])
            chk("the old corner control is gone", not d["oldControl"])
            chk("the author gets EXACTLY ONE edit control", d["editN"] == 1, f"n={d['editN']}")
            chk("no Page-text control for a plain author", d["pageHref"] is None,
                str(d["pageHref"])[:40])

            # 4/6 — a real post, and the flag and the door AGREE either way
            href = d["editHref"] or ""
            if flag:
                chk("Edit goes to the compose form", "/compose/?type=" in href, href[:56])
                chk(f"…with a real post id ({a.post})", f"id={a.post}" in href, href[:56])
            else:
                chk("flag OFF: Edit falls back to the page editor, not a dead route",
                    "lg_edit=1" in href and "/compose/" not in href, href[:56])

            # 7 — the pill family
            hs = sorted({k["h"] for k in d["kids"]})
            radii = {k["radius"] for k in d["kids"]}
            tops = sorted({k["top"] for k in d["kids"]})
            chk("every dock child is the same height", len(hs) == 1, f"heights={hs}")
            chk("every dock child wears a pill radius",
                all("999" in r or "50%" in r for r in radii), f"radii={sorted(radii)}")
            # ALIGNMENT, asked of the axis the dock is actually laid out on. The
            # first cut asked for a shared TOP at every width and went red on a
            # healthy build: between 641 and 1500 the dock is a COLUMN, where
            # different tops are the layout working. Row => shared top; column =>
            # shared left. Found by this gate on its own first near-green run,
            # which is the cheapest possible place to find an over-specified
            # assertion — before it becomes somebody else's mystery red.
            lefts = sorted({k["left"] for k in d["kids"]})
            is_row = d["dock"]["h"] <= max((k["h"] for k in d["kids"]), default=0) + 2
            if is_row:
                chk("the dock children share a top edge (row)",
                    bool(tops) and (max(tops) - min(tops)) <= 2, f"tops={tops}")
            else:
                chk("the dock children share a left edge (column)",
                    bool(lefts) and (max(lefts) - min(lefts)) <= 2, f"lefts={lefts}")

            if 641 <= width <= 1500:
                # 8 — HK-027: off the WORDS, not off the wrapper
                if d["textLeft"] is None:
                    chk("body text shares a band with the dock (so clearance is measurable)",
                        False, "no text rect in the dock's band")
                else:
                    right = d["dock"]["x"] + d["dock"]["w"]
                    chk("the dock clears the body text (GH #53 / HK-027)",
                        right < d["textLeft"],
                        f"dock right={right} textLeft={d['textLeft']} clearance={round(d['textLeft']-right,1)}")
                # the compact stack must not grow back over the column
                chk("the compact stack is no wider than it was (<=64px)",
                    d["dock"]["w"] <= 64, f"w={d['dock']['w']}")

            if width <= 640:
                # 9 — the phone row fits, and is ONE row
                right = d["dock"]["x"] + d["dock"]["w"]
                chk("the phone row fits the viewport", right <= width - 8,
                    f"right={right} viewport={width}")
                tall = max((k["h"] for k in d["kids"]), default=0)
                chk("the phone dock is a single row",
                    d["dock"]["h"] <= tall + 2, f"dock h={d['dock']['h']} tallest child={tall}")

            if theme == "dark":
                # 10 — not a bright slab in dark
                chk("the dock pills are dark in dark",
                    d["pillLum"] is not None and d["pillLum"] < 0.35, f"lum={d['pillLum']}")

        # ── the CAP-HOLDER: two controls, and Page text still works ───────────
        tab.call("Network.clearBrowserCookies")
        c = look(tab, url, jar(a.capholder), "light", 1280)
        if not c["live"] or c["signedOut"]:
            print("  CANNOT RUN: the cap-holder leg did not render signed in")
            return 2
        print("  cap-holder @1280 light: liveness ok")
        chk("a cap-holder gets EXACTLY TWO edit controls", c["editN"] == 2, f"n={c['editN']}")
        chk("…and the second one is Page text", bool(c["pageHref"]) and "lg_edit=1" in (c["pageHref"] or ""),
            str(c["pageHref"])[:46])
        chk("…in the dock too", c["pageInDock"])

        # ── the STRANGER: nothing at all, on a page that did render ───────────
        tab.call("Network.clearBrowserCookies")
        s = look(tab, url, jar(a.stranger), "light", 1280)
        if not s["live"]:
            print("    SKIP  stranger: page did not render, so 'no door' proves nothing")
        else:
            chk("a non-entitled member gets NO edit control (page rendered, so not vacuous)",
                s["editN"] == 0, f"n={s['editN']}")
    except CannotRun as e:
        print(f"CANNOT RUN: {e}")
        return 2
    finally:
        tab.close()

    if fails:
        print(f"\nRED — {len(fails)} of {checked}:")
        for f in fails:
            print(f"  - {f}")
        return 1
    print(f"\nGREEN — one Edit pill in the dock carrying a real post, the old corner menu "
          f"gone, members one and admins two, one pill family that clears the body text and "
          f"fits a phone, dark in dark, and a stranger gets nothing ({checked} checks).")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except CannotRun as e:
        print(f"CANNOT RUN: {e}")
        sys.exit(2)
    except Exception as e:
        print(f"CANNOT RUN: {e}")
        sys.exit(2)
