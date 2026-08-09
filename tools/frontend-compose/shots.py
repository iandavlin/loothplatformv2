#!/usr/bin/env python3
"""
shots.py — photograph a dev2 URL at desktop and phone widths, in both themes.

Ian decides from pictures, so the pictures have to be honest. Five recorded traps
on this box each produce a screenshot that looks fine and shows the wrong thing;
every one of them is handled here rather than left to the operator to remember.

  1. ONE PERSISTENT CDP CONNECTION. Device emulation is per-session. The old
     per-command socket style (tools/cdp.py) drops the override between calls, so
     a "390px" run renders at desktop width and a mobile defect is invisible.

  2. CLEAR COOKIES FIRST. The shared /var/lib/chrome-dev/profile already holds WP
     session cookies. Network.setCookie ADDS rather than replaces when the
     host-only/dotted flavour differs, so the run can execute as a DIFFERENT
     member than the one asked for — and an absence assertion then goes green for
     entirely the wrong reason.

  3. THE GATE COOKIE NEEDS A LEADING DOT; THE WP COOKIES MUST NOT HAVE ONE. The
     chrome-dev-login skill states the opposite rule and is stale for this box.

  4. NAVIGATE BEFORE TOUCHING localStorage. A write on about:blank is a silent
     no-op, and the shared profile persists lg-set-theme="dark" — so a run that
     sets "light" too early photographs the dark theme and labels it light.

  5. CLOUDFLARE CHALLENGES THE PUBLIC HOST. Chrome must be started with
     --host-resolver-rules (gate-env.sh prints the exact flag as
     LG_GATE_CHROME_RESOLVER); it already is on this box. A naive check PASSES on
     the challenge page.

Usage:
  python3 tools/frontend-compose/shots.py --url /compose/?type=loothprint \
      --as claude_admin --out /path/to/dir --tag after
"""
import argparse, base64, json, os, subprocess, sys, time
import websocket  # websocket-client, already used by tools/cdp.py

CDP = "http://127.0.0.1:9222"
REPO = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")

VIEWPORTS = [
    # label      width height mobile scale
    ("desktop",  1280,  1400, False, 1),
    ("phone",     390,  1500, True,  2),
]
THEMES = ["light", "dark"]


def sh(cmd):
    return subprocess.run(cmd, capture_output=True, text=True)


def gate_env():
    r = sh(["bash", os.path.join(REPO, "tools", "gates", "gate-env.sh")])
    if r.returncode != 0:
        sys.exit("gate-env.sh failed:\n" + r.stderr)
    return dict(l.partition("=")[::2] for l in r.stdout.splitlines())


def wp_eval(php):
    r = sh(["sudo", "-n", "wp", "--allow-root", "--path=/var/www/dev", "eval", php])
    if r.returncode != 0:
        sys.exit("wp eval failed: " + r.stderr[:300])
    return "\n".join(l for l in r.stdout.splitlines()
                     if not l.startswith(("PHP Warning:", "PHP Deprecated:", "Warning:")))


def wp_cookies(login):
    """The two WP auth cookies, as name/value pairs. HOST-ONLY (no leading dot)."""
    out = wp_eval(
        f"$u = get_user_by('login', '{login}');"
        "if (!$u) { echo 'NOUSER'; exit; }"
        "$e = time() + 3600;"
        "echo LOGGED_IN_COOKIE . '|' . wp_generate_auth_cookie($u->ID, $e, 'logged_in') . \"\\n\";"
        "echo SECURE_AUTH_COOKIE . '|' . wp_generate_auth_cookie($u->ID, $e, 'secure_auth');"
    ).strip()
    if out == "NOUSER" or not out:
        sys.exit(f"no such user: {login}")
    return [l.split("|", 1) for l in out.splitlines() if "|" in l]


class Tab:
    def __init__(self):
        t = json.loads(subprocess.check_output(
            ["curl", "-s", "-X", "PUT", f"{CDP}/json/new?about:blank"]))
        self.id = t["id"]
        self.ws = websocket.create_connection(
            t["webSocketDebuggerUrl"], suppress_origin=True, timeout=60)
        self.n = 0

    def call(self, method, **params):
        self.n += 1
        self.ws.send(json.dumps({"id": self.n, "method": method, "params": params}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get("id") == self.n:
                if "error" in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get("result", {})

    def close(self):
        try:
            self.ws.close()
        finally:
            sh(["curl", "-s", f"{CDP}/json/close/{self.id}"])


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", required=True, help="path, e.g. /compose/?type=loothprint")
    ap.add_argument("--as", dest="login", default="", help="WP login to shoot as (blank = anon)")
    ap.add_argument("--out", required=True)
    ap.add_argument("--tag", default="shot")
    ap.add_argument("--full", action="store_true", help="full-page rather than viewport")
    args = ap.parse_args()

    env = gate_env()
    domain = env["LG_GATE_DOMAIN"]
    url = env["LG_GATE_HOST"] + args.url
    os.makedirs(args.out, exist_ok=True)

    cookies = [{
        # LEADING DOT — trap 3. The dev gate is checked against the dotted domain.
        "name": "loothdev_auth", "value": env["LG_GATE_TOKEN"],
        "domain": "." + domain, "path": "/", "secure": True,
    }]
    for name, value in (wp_cookies(args.login) if args.login else []):
        cookies.append({
            # HOST-ONLY — trap 3 again, and trap 2: a dotted WP cookie would sit
            # ALONGSIDE the profile's existing host-only one, not replace it.
            "name": name, "value": value,
            "domain": domain, "path": "/", "secure": True, "httpOnly": True,
        })

    made = []
    tab = Tab()
    try:
        tab.call("Page.enable")
        tab.call("Network.enable")
        tab.call("Network.clearBrowserCookies")   # trap 2
        tab.call("Network.setCookies", cookies=cookies)

        for theme in THEMES:
            for label, w, h, mobile, scale in VIEWPORTS:
                tab.call("Emulation.setDeviceMetricsOverride",
                         width=w, height=h, deviceScaleFactor=scale,
                         mobile=mobile, screenWidth=w, screenHeight=h)
                if mobile:
                    tab.call("Emulation.setTouchEmulationEnabled", enabled=True,
                             maxTouchPoints=5)
                else:
                    tab.call("Emulation.setTouchEmulationEnabled", enabled=False)

                # trap 4: navigate BEFORE writing the theme, then re-navigate so
                # the page boots with it already set.
                tab.call("Page.navigate", url=url)
                time.sleep(2.5)
                tab.call("Runtime.evaluate", expression=(
                    f"localStorage.setItem('lg-set-theme','{theme}');"
                    f"document.documentElement.setAttribute('data-lguser-theme','{theme}');"
                ))
                tab.call("Page.navigate", url=url)
                time.sleep(3.5)

                # Prove what we actually photographed rather than trusting the set.
                got = tab.call("Runtime.evaluate", expression=(
                    "JSON.stringify({"
                    "theme: document.documentElement.getAttribute('data-lguser-theme'),"
                    "w: innerWidth,"
                    "form: !!document.querySelector('.lgfc__card'),"
                    "title: (document.title||'').slice(0,60)})"
                ), returnByValue=True)["result"].get("value", "{}")

                shot = tab.call("Page.captureScreenshot", format="png",
                                captureBeyondViewport=bool(args.full))
                path = os.path.join(args.out, f"{args.tag}-{theme}-{label}.png")
                with open(path, "wb") as fh:
                    fh.write(base64.b64decode(shot["data"]))
                made.append(path)
                print(f"{os.path.basename(path):<34} {got}")
    finally:
        tab.close()

    print(f"\n{len(made)} shot(s) -> {args.out}")


if __name__ == "__main__":
    main()
