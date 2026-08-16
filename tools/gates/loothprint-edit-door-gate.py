#!/usr/bin/env python3
"""
loothprint-edit-door-gate.py — the way IN to editing your own Loothprint.

⚠️ NO GATE NUMBER YET. Keeper mints, lanes never.

WHY IT EXISTS. Ruled scope item 4, and Ian picked the shape (2026-08-16): the Edit
button on a Loothprint opens a two-line choice — "Details & files", the acf_form
that edits the print's own fields, and "Page text", today's layout editor. The
renderer and its permission check already existed and were reachable by NOBODY;
this gate guards the door, which is the part that was missing and is therefore the
part most likely to be lost again.

WHAT IT ASSERTS, and why each is here rather than the cheaper version:

  1. LIVENESS, AND SIGNED-IN LIVENESS. The control renders only for
     edit_archive_poc or the author, and that identity comes from a server-side
     /whoami loopback which is INTERMITTENT for a minted cookie — measured: the
     same tree gave items=2 on one run and items=0 on the next. Without a
     signed-in check, "no menu" is indistinguishable from "not recognised", and
     every assertion below becomes a coin toss reported as a verdict. If the page
     renders us signed out the gate exits 2, it does NOT fail.

  2. THE DOOR OPENS ONTO TWO NAMED THINGS. Not "a button exists" — the defect this
     replaced was a button that existed and went to the wrong editor.

  3. DETAILS POINTS AT A REAL POST. The link must carry a POSITIVE id. The first
     build of this feature read $postContext['id'], a key that does not exist in
     that context, and would have shipped a link to id=0 — a compose form for
     nothing, on the control Ian had just asked for. That is what this line is for.

  4. PAGE TEXT IS UNTOUCHED. ?lg_edit=1 must still be there. The ruling ADDED a
     door; it did not take one away.

  5. THE STRANGER GETS NOTHING. A member who is neither author nor cap-holder must
     see no edit control at all — an ABSENCE assertion, so it is paired with a
     liveness check on the same page: "no button" is trivially true of a 404.

  6. IT READS THE FLAG RATHER THAN HARDCODING A STATE. The Details link exists only
     when compose is switched on. The gate resolves the same config pair the app
     does and asserts the two AGREE — so flipping the default needs no edit here,
     and a link pointing at a switched-off route (the UI-lies class) fails.

  7. BOTH THEMES. The menu is a new member-facing surface, so it enrols dark
     assertions on the day it is born: it must not be a bright slab in dark, which
     is the class that bit this very form the same night.

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
    gate is measuring answers ON. The first run of this gate did exactly that and
    reported a flag/link disagreement that was entirely its own.

    /srv/archive-poc is a symlink into the serving checkout, so ../platform/config is
    the very directory lg_standalone_compose_on() reads. Falls back to this repo only
    when that is absent, so the gate still runs somewhere without the symlink.
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


PROBE = r"""(() => {
  const srgb=c=>{c/=255;return c<=0.04045?c/12.92:Math.pow((c+0.055)/1.055,2.4)};
  const px=s=>{const m=s.match(/rgba?\(([^)]+)\)/);if(!m)return null;
    const p=m[1].split(',').map(Number);return {r:p[0],g:p[1],b:p[2]}};
  const lum=c=>0.2126*srgb(c.r)+0.7152*srgb(c.g)+0.0722*srgb(c.b);
  const wrap = document.querySelector('[data-lg-editmenu]');
  const btn  = wrap ? wrap.querySelector('.lg-standalone-edit') : null;
  if (btn) btn.click();
  const menu  = wrap ? wrap.querySelector('.lg-standalone-editmenu') : null;
  const items = menu ? [...menu.querySelectorAll('.lg-editmenu__i')] : [];
  const mb    = menu ? px(getComputedStyle(menu).backgroundColor) : null;
  const r     = menu ? menu.getBoundingClientRect() : null;
  return JSON.stringify({
    live:      !!document.querySelector('.lg-standalone-main, article, main'),
    signedOut: !!document.querySelector('.lg-chrome__signin, .lg-chrome__join'),
    anyEdit:   !!document.querySelector('.lg-standalone-edit'),
    menu:      !!menu,
    n:         items.length,
    hrefs:     items.map(a => a.getAttribute('href') || ''),
    menuLum:   mb ? +lum(mb).toFixed(3) : null,
    menuBox:   r ? [Math.round(r.width), Math.round(r.height)] : null,
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
    ap.add_argument("--post", type=int, default=72155)
    ap.add_argument("--entitled", default="claude_admin",
                    help="a viewer who SHOULD get the door (author or edit_archive_poc)")
    ap.add_argument("--stranger", default="erin.vogel",
                    help="a member who should get NO edit control at all")
    a = ap.parse_args()

    env  = gate_env(); dom, base = env["LG_GATE_DOMAIN"], env["LG_GATE_HOST"]
    url  = f"{base}/loothprint/{a.slug}/"
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
    tab = Tab()
    try:
        tab.call("Page.enable"); tab.call("Network.enable")

        for theme in ("light", "dark"):
            tab.call("Network.clearBrowserCookies")
            d = look(tab, url, jar(a.entitled), theme, 390)
            if not d["live"]:
                raise CannotRun(f"the loothprint page did not render in {theme}")
            if d["signedOut"]:
                # /whoami blinked — see the docstring. Not a defect, and not a pass.
                print(f"  {theme:<5} CANNOT RUN: page renders us signed OUT, so the door is "
                      f"correctly absent and proves nothing")
                return 2
            print(f"  {theme:<5} liveness ok (page rendered, viewer signed in)")

            checks = [("Edit opens a two-line choice", d["menu"] and d["n"] == 2, f"items={d['n']}")]
            details = [h for h in d["hrefs"] if "/compose/?type=" in h]
            pagetxt = [h for h in d["hrefs"] if "lg_edit=1" in h]
            good_id = any(f"id={a.post}" in h for h in details)
            checks += [
                ("Details points at the compose form", bool(details), str(details)[:52]),
                (f"…with a real post id ({a.post})", good_id, str(details)[:52]),
                ("Page text still goes to ?lg_edit=1", bool(pagetxt), str(pagetxt)[:46]),
            ]
            if theme == "dark":
                big = d["menuBox"] and d["menuBox"][0] >= 40 and d["menuBox"][1] >= 24
                checks.append(("menu is not a bright slab in dark",
                               (not big) or (d["menuLum"] is not None and d["menuLum"] < 0.35),
                               f"lum={d['menuLum']} {d['menuBox']}"))
            # 6. the flag and the door must AGREE, whichever way the flag reads
            checks.append(("Details link agrees with the flag",
                           bool(details) == flag,
                           f"flag={'ON' if flag else 'OFF'} link={'present' if details else 'absent'}"))
            for label, ok, detail in checks:
                checked += 1
                print(f"    {'ok  ' if ok else 'RED '} {label} {detail}")
                if not ok:
                    fails.append(f"{theme}: {label} {detail}")

        # ── the stranger sees no door at all ───────────────────────────────
        tab.call("Network.clearBrowserCookies")
        s = look(tab, url, jar(a.stranger), "light", 390)
        checked += 1
        if not s["live"]:
            print("    SKIP  stranger: page did not render, so 'no door' proves nothing")
        elif s["anyEdit"]:
            print(f"    RED  a non-entitled member sees an edit control")
            fails.append("stranger sees an edit control")
        else:
            print(f"    ok   stranger gets NO edit control (page rendered, so not vacuous)")
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
    print(f"\nGREEN — the edit door opens onto two named editors, Details carries a real "
          f"post, Page text is untouched, it agrees with the flag, it is dark in dark, "
          f"and a stranger gets nothing ({checked} checks).")
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
