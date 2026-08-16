#!/usr/bin/env python3
"""
compose-chrome-gate.py — the standalone compose page wears the site chrome, in
BOTH themes, and the embed variant does not.

⚠️ NO GATE NUMBER YET. Keeper mints, lanes never. The ledger says 65 is next free;
this file deliberately claims nothing until keeper says so.

WHY IT EXISTS. Ian, 2026-08-16, testing /compose/ live: "can we get the header and
footer so it looks like a normal page?" Keeper's ruling with it: standalone pages
MIMIC the chrome (nothing renders from the WP theme), and the chrome presence gets
gated in BOTH THEMES from birth.

WHAT IT ASSERTS, and why each one is here rather than the obvious cheaper version:

  1. LIVENESS FIRST. The form itself must render. A locked-out browser serves a
     styled 403 that is identical at every width and in every theme, and a chrome
     assertion against it would fail for entirely the wrong reason — or, worse, a
     PRESENCE assertion could pass on it. Recorded trap; the liveness check is what
     makes every line below mean anything.

  2. VISIBLE, NOT MERELY PRESENT. Header and footer are checked with
     getBoundingClientRect + computed display, not by grepping for a class name.
     "Presence is not reachability" has bitten this box twice, and a chrome that is
     in the DOM at height 0 is exactly the shape of that bug.

  3. BOTH THEMES. The whole point of the ruling. A chrome that resolves its colours
     from tokens can be perfectly present and invisible in one theme — and the
     compose form has ALREADY produced one instance of that this week (white ink on
     a token that flips lightness in dark). So each assertion runs twice.

  4. THE EMBED VARIANT MUST NOT WEAR IT. That is an ABSENCE assertion, so it is
     paired with a liveness assertion on the same request: "no chrome" is trivially
     true of a 404, and an absence check without a liveness check is vacuous. If
     the embed route is not reachable at all the gate SKIPS that leg rather than
     scoring it — a missing route is not evidence about chrome.

Exit: 0 green, 1 a real defect, 2 CANNOT RUN.

⚠️ CANNOT RUN IS 2, NOT 3 — run-all.sh reads 0 green, 2 no-verdict and ANYTHING
ELSE as RED, so a gate exiting 3 where it merely could not run turns the whole
suite red for every lane. That trap has been live in this repo twice this month.
"""
import json, os, subprocess, sys, time

try:
    import websocket  # websocket-client
except Exception as e:  # pragma: no cover
    print(f"CANNOT RUN: websocket-client unavailable: {e}")
    sys.exit(2)

CDP  = "http://127.0.0.1:9222"
REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
LOGIN = os.environ.get("LG_CHROME_GATE_LOGIN", "claude_admin")


def sh(c):
    return subprocess.run(c, capture_output=True, text=True)


class CannotRun(Exception):
    pass


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

    def call(self, method, **params):
        self.n += 1
        self.ws.send(json.dumps({"id": self.n, "method": method, "params": params}))
        while True:
            m = json.loads(self.ws.recv())
            if m.get("id") == self.n:
                if "error" in m:
                    raise CannotRun(f"{method}: {m['error']}")
                return m.get("result", {})

    def js(self, expr):
        for _ in range(8):
            time.sleep(0.75)
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


# Visible = has real layout box AND is not display:none. Presence alone is the trap.
PROBE = """(() => {
  const vis = el => {
    if (!el) return {found:false, visible:false, h:0};
    const c = getComputedStyle(el), r = el.getBoundingClientRect();
    return {found:true,
            visible: c.display !== 'none' && c.visibility !== 'hidden' && r.height > 4,
            h: Math.round(r.height)};
  };
  const head = document.querySelector('.lg-chrome, header.lg-chrome, [class*="lg-chrome"]:not(.lg-chrome-foot)');
  const foot = document.querySelector('footer.lg-chrome-foot, [role="contentinfo"]');
  return JSON.stringify({
    theme: document.documentElement.getAttribute('data-lguser-theme') || 'default',
    form:  !!document.querySelector('.lgfc__card'),
    title: document.title,
    header: vis(head),
    footer: vis(foot),
  });
})()"""


def look(tab, url, cookies, theme):
    tab.call("Network.setCookies", cookies=cookies)
    tab.call("Page.navigate", url=url)
    time.sleep(2.5)
    if theme:
        tab.call("Runtime.evaluate", expression=(
            f"localStorage.setItem('lg-set-theme','{theme}');"
            f"localStorage.setItem('lg-set-boot',JSON.stringify({{theme:'{theme}',dark:{str(theme=='dark').lower()}}}));"
            f"document.documentElement.setAttribute('data-lguser-theme','{theme}');"))
        tab.call("Page.navigate", url=url)
        time.sleep(2.5)
    raw = tab.js(PROBE)
    if not raw:
        raise CannotRun(f"page never answered at {url}")
    return json.loads(raw)


def main() -> int:
    env = gate_env()
    dom, base = env["LG_GATE_DOMAIN"], env["LG_GATE_HOST"]
    cookies = [{"name": "loothdev_auth", "value": env["LG_GATE_TOKEN"],
                "domain": "." + dom, "path": "/", "secure": True}]
    for n, v in wp_cookies(LOGIN):
        cookies.append({"name": n, "value": v, "domain": dom, "path": "/",
                        "secure": True, "httpOnly": True, "sameSite": "Lax"})

    url  = base + "/compose/?type=loothprint"
    emb  = base + "/compose/?type=loothprint&embed=1"
    fails, checked = [], 0
    tab = Tab()
    try:
        tab.call("Page.enable"); tab.call("Network.enable")
        tab.call("Network.clearBrowserCookies")
        tab.call("Emulation.setDeviceMetricsOverride", width=1280, height=1400,
                 deviceScaleFactor=1, mobile=False, screenWidth=1280, screenHeight=1400)

        for theme in ("light", "dark"):
            d = look(tab, url, cookies, theme)
            if not d["form"]:
                raise CannotRun(
                    f"no .lgfc__card at {url} in {theme} — the form did not render. "
                    "Either compose is flagged OFF, this member is refused, or the URL "
                    "moved. Every assertion below would be vacuous.")
            print(f"  {theme:<5} liveness ok (form present, title {d['title']!r})")
            for part in ("header", "footer"):
                checked += 1
                p = d[part]
                if p["found"] and p["visible"]:
                    print(f"    ok   site {part} visible, {p['h']}px")
                else:
                    why = "not in the DOM" if not p["found"] else f"present but not visible (h={p['h']})"
                    print(f"    RED  site {part} {why}")
                    fails.append(f"{theme}: site {part} {why}")

        # ── the embed variant must NOT wear the chrome ──────────────────────
        try:
            e = look(tab, emb, cookies, "light")
        except CannotRun as ex:
            print(f"  embed  SKIPPED — {ex}")
            e = None
        if e is not None:
            if not e["form"]:
                print("  embed  SKIPPED — no form there, so 'no chrome' would be vacuous")
            else:
                checked += 1
                if e["header"]["visible"] or e["footer"]["visible"]:
                    print("    RED  embed variant renders site chrome — two headers on one screen")
                    fails.append("embed: chrome present in the framed variant")
                else:
                    print("    ok   embed carries no site chrome (form present, so not vacuous)")

    except CannotRun as ex:
        print(f"CANNOT RUN: {ex}")
        return 2
    finally:
        tab.close()

    if fails:
        print(f"\nRED — {len(fails)} of {checked} check(s) failed:")
        for f in fails:
            print(f"  - {f}")
        return 1
    print(f"\nGREEN — the compose page wears the site chrome in both themes "
          f"({checked} checks), and the embed variant does not.")
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
