#!/usr/bin/env python3
"""
capture.py — shoot the Membership Guide's PROFILE frames off the dev2 serve.

Pairs with docs/atlas/PROFILE-GUIDE-SHOTLIST.md. Everything in here that looks
fussy is a trap someone already paid for; the comments say which.

RUN:  python3 tools/guide-shots/capture.py --out ~/projects/footer-mockups/profile-guide-shots
      python3 tools/guide-shots/capture.py --only a3,b7 --viewport phone

THE FIVE THINGS THAT MAKE THIS DIFFERENT FROM "JUST SCREENSHOT IT"

1. HOST = dev2.loothgroup.com, NEVER dev.loothgroup.com.
   No nginx server_name matches `dev`, so it falls through to the default vhost
   (buck-dev2, alphabetically first) and serves /home/buck/loothplatformv2 --
   a tree from 2026-07-22, i.e. the pre-option-A build. It answers 200 on every
   path we use, so nothing 404s to warn you; the app is just a week old.
   Chrome needs --host-resolver-rules to reach it (see ensure_resolver()).

2. NO captureBeyondViewport. Full-page capture renders position:fixed chrome at
   its VIEWPORT offset, so the mobile tab bar lands mid-page across the footer.
   It looks like a broken site and is purely a capture artifact. 5 of the 8
   frames the 7/28 pass shipped have it. For content below the fold we scroll
   and take another viewport-only frame -- we never grow the capture.

3. ONE PERSISTENT WEBSOCKET for the whole run. A per-command CDP socket drops
   the device-metrics override, so a "phone" shot silently comes back as
   desktop and the mobile frames false-PASS.

4. THE GATE COOKIE NEEDS A LEADING DOT on the domain. This is the opposite of
   what the chrome-dev-login skill says; the skill is stale on dev2.

5. THREE VIEWPORTS, and the middle one is not optional. The 1380px breakpoint
   makes "desktop" two different UIs -- below it the sections picker is a
   drawer, at or above it a permanent sidebar. Shooting only 1440 hides the
   drawer behaviour from most real laptops.
"""

import argparse, base64, json, os, subprocess, sys, time
import urllib.request
import websocket  # websocket-client 1.7.0

CDP_HTTP = "http://127.0.0.1:9222"
DOMAIN   = "dev2.loothgroup.com"
ADDR     = "172.31.78.94"
BASE     = f"https://{DOMAIN}"

# Fixture ids from the shot list. 1849/visibility-matrix-qa is the visibility
# matrix's PERMANENT fixture -- read it, never leave it in a non-default state.
OWNER_WP, MEMBER_WP = 1910, 7
OWNER_SLUG = "visibility-matrix-qa"

VIEWPORTS = {
    # name             w     h    dpr  mobile
    "phone":          (390,  844, 2,   True),
    "desktop-narrow": (1280, 900, 2,   False),   # <1380 -> picker is a DRAWER
    "desktop-wide":   (1440, 900, 2,   False),   # >=1380 -> permanent SIDEBAR
}


# ---------------------------------------------------------------- chrome setup

def ensure_resolver():
    """The shared chrome-dev.service is launched WITHOUT --host-resolver-rules,
    which is also why the craft gate cannot run. Install a systemd drop-in so
    the fix is permanent and box-wide, not a private relaunch that the next
    Restart= throws away."""
    drop = "/etc/systemd/system/chrome-dev.service.d/10-resolver.conf"
    if os.path.exists(drop):
        return "already installed"
    body = (
        "# Added by profile-guide 2026-07-30. Without this, Chrome resolves\n"
        "# dev2.loothgroup.com publicly and gets Cloudflare's challenge page --\n"
        "# which is how a gate once reported PASS while auditing a challenge.\n"
        "# Value is the canonical one from tools/gates/gate-env.sh.\n"
        "[Service]\n"
        "ExecStart=\n"
        "ExecStart=/usr/bin/google-chrome-stable \\\n"
        "  --headless=new \\\n"
        "  --remote-debugging-port=9222 \\\n"
        "  --remote-debugging-address=127.0.0.1 \\\n"
        "  --user-data-dir=/var/lib/chrome-dev/profile \\\n"
        "  --no-first-run --no-default-browser-check \\\n"
        "  --disable-gpu --disable-dev-shm-usage \\\n"
        "  --hide-scrollbars --mute-audio \\\n"
        f"  --host-resolver-rules=\"MAP {DOMAIN} {ADDR}\" \\\n"
        "  --ignore-certificate-errors \\\n"
        "  about:blank\n"
    )
    subprocess.run(["sudo", "-n", "mkdir", "-p", os.path.dirname(drop)], check=True)
    subprocess.run(["sudo", "-n", "tee", drop], input=body.encode(),
                   stdout=subprocess.DEVNULL, check=True)
    subprocess.run(["sudo", "-n", "systemctl", "daemon-reload"], check=True)
    subprocess.run(["sudo", "-n", "systemctl", "restart", "chrome-dev"], check=True)
    time.sleep(3)
    return "installed + service restarted"


def gate_token():
    out = subprocess.check_output(
        ["grep", "-oP", r'map \$cookie_loothdev_auth.*?"\K[^"]+',
         "/etc/nginx/conf.d/loothdev-auth.conf"], text=True)
    return out.strip().splitlines()[0]


def mint(wp_id):
    out = subprocess.check_output(
        ["sudo", "-n", "-u", "profile-app", "php",
         "/srv/profile-app/bin/mint-dev-token.php", str(wp_id)], text=True)
    return out.strip().splitlines()[-1]


# ------------------------------------------------------------------ cdp client

class Tab:
    """Our OWN tab, held open on ONE websocket for the whole run.

    Own tab because a second CDP client attaching to a target someone else holds
    fails with a bare HTTP 500. One socket because device emulation is per
    connection."""

    def __init__(self):
        # /json/new needs PUT on Chrome 151+ (it 405s on GET).
        req = urllib.request.Request(f"{CDP_HTTP}/json/new?about:blank", method="PUT")
        self.info = json.loads(urllib.request.urlopen(req, timeout=10).read())
        self.id = self.info["id"]
        self.ws = websocket.create_connection(
            self.info["webSocketDebuggerUrl"], timeout=45,
            suppress_origin=True)          # Chrome rejects a browser-ish Origin
        self._n = 0
        self.send("Page.enable"); self.send("Network.enable"); self.send("Runtime.enable")

    def send(self, method, **params):
        self._n += 1
        self.ws.send(json.dumps({"id": self._n, "method": method, "params": params}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get("id") == self._n:
                if "error" in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get("result", {})

    def js(self, expr):
        r = self.send("Runtime.evaluate", expression=expr,
                      returnByValue=True, awaitPromise=True)
        return r.get("result", {}).get("value")

    def cookie(self, name, value):
        # LEADING DOT. Without it the dev gate does not match and you get the
        # gate's own login page instead of the app -- and it still screenshots
        # fine, so it looks like a real frame.
        self.send("Network.setCookie", name=name, value=value,
                  domain=f".{DOMAIN}", path="/", secure=True)

    def viewport(self, name):
        w, h, dpr, mobile = VIEWPORTS[name]
        self.send("Emulation.setDeviceMetricsOverride",
                  width=w, height=h, deviceScaleFactor=dpr, mobile=mobile)
        self.send("Emulation.setTouchEmulationEnabled", enabled=mobile)
        return w, h

    def goto(self, url, settle=2.2):
        self.send("Page.navigate", url=url)
        time.sleep(settle)                       # loadEventFired lies about maps/fonts
        self.js("document.fonts && document.fonts.ready")

    def set_theme(self, theme):
        """Force the user theme and PROVE it took.

        THE SHARED CHROME PROFILE IS NOT NEUTRAL. /var/lib/chrome-dev/profile
        persists lg-set-theme='dark' from whoever used it last, so a run that
        merely *intends* light silently renders dark and the frame ships
        mislabelled. Two further edges, both already paid for by someone:
          - a localStorage write on about:blank is a NO-OP (different origin),
            so the caller must already be on the site before this runs;
          - the light theme's id is 'default', NOT 'light'. Writing 'light'
            stores a value nothing matches, which falls back to... light, so it
            appears to work and breaks the day an explicit check is added.
        Returns the attribute actually applied; the caller asserts on it rather
        than trusting the write."""
        want = "dark" if theme == "dark" else "default"
        self.js(f"localStorage.setItem('lg-set-theme', {json.dumps(want)})")
        self.send("Page.reload")
        time.sleep(2.0)
        got = self.js("document.documentElement.getAttribute('data-lguser-theme')")
        # Light legitimately renders with the attribute absent or 'default'.
        ok = (got == "dark") if want == "dark" else (got in (None, "", "default"))
        if not ok:
            raise RuntimeError(
                f"theme did not apply: wanted {want!r}, page reports {got!r} — "
                f"refusing to shoot a frame that would be labelled wrong")
        return got

    def shot(self, path):
        # captureBeyondViewport is DELIBERATELY absent -- see module docstring.
        r = self.send("Page.captureScreenshot", format="png")
        data = base64.b64decode(r["data"])
        with open(path, "wb") as f:
            f.write(data)
        return len(data)

    def close(self):
        try:
            self.ws.close()
        finally:
            urllib.request.urlopen(f"{CDP_HTTP}/json/close/{self.id}", timeout=5).read()


# ---------------------------------------------------------------------- actions

def scroll_to(tab, sel):
    """Scroll a selector to a comfortable position and report whether it was
    actually found -- a silent miss would otherwise produce a top-of-page frame
    that looks deliberate."""
    ok = tab.js(f"""(()=>{{const e=document.querySelector({json.dumps(sel)});
        if(!e) return false; e.scrollIntoView({{block:'center'}}); return true;}})()""")
    time.sleep(0.6)
    return bool(ok)


def click(tab, sel):
    """Click via the element's own handler. NOTE: a synthetic click can never
    cross a long-press threshold, and a blind coordinate click can land on the
    fixed tab bar -- neither matters for opening a drawer, but do not reuse this
    for press-and-hold behaviour."""
    ok = tab.js(f"""(()=>{{const e=document.querySelector({json.dumps(sel)});
        if(!e) return false; e.click(); return true;}})()""")
    time.sleep(0.9)
    return bool(ok)


# ------------------------------------------------------------------ the shots
# Each entry: id -> (title, role, path, viewports, action, assert_sel)
# `assert_sel` is what must EXIST in the DOM for the frame to be worth keeping;
# a missing one is reported as a FINDING, not silently shot anyway.

def build_shots():
    u = f"/u/{OWNER_SLUG}"
    return {
      # --- A. owner / editor: the spine. Unblocked now option A is on the serve.
      "a1": ("Profile as owner, top of page", "owner", u,
             ["phone", "desktop-narrow", "desktop-wide"], None, ".lg-viewas"),
      "a2": ("Blocks with grips + per-section chips", "owner", u,
             ["phone", "desktop-narrow", "desktop-wide"],
             lambda t: scroll_to(t, ".lg-block:not(.lg-block--header)"), ".lg-block__grip"),
      "a3": ("Sections drawer OPEN (the money shot)", "owner", u,
             ["phone", "desktop-narrow"],
             lambda t: click(t, "[data-caddy-open]"), "#lg-caddy"),
      "a4": ("Sections as permanent sidebar", "owner", u,
             ["desktop-wide"], None, "#lg-caddy"),
      "a5": ("Avatar + banner affordances", "owner", u,
             ["phone", "desktop-wide"], None, ".lg-block--header"),
      "a6": ("Your layout row (option A)", "owner", u,
             ["phone", "desktop-narrow"],
             lambda t: scroll_to(t, ".lg-layoutrow"), ".lg-layoutrow"),
      "a7": ("Add-a-section card at end of list (option A)", "owner", u,
             ["phone", "desktop-narrow"],
             lambda t: scroll_to(t, ".lg-addsec"), ".lg-addsec"),

      # --- B. privacy
      # The master chip is data-pmp-block="header"; every other .lg-vchip is a
      # PER-SECTION chip. Targeting a bare .lg-vchip grabs whichever comes first
      # in the DOM and would quietly shoot a section chip as if it were the
      # profile-wide one -- the exact confusion B1 exists to clear up.
      "b1": ("Profile-visibility chip menu open", "owner", u,
             ["phone", "desktop-wide"],
             lambda t: click(t, '.lg-vchip[data-pmp-block="header"]'), ".lg-pmp-menu"),
      # B2 is BLOCKED on a decision, not on the engine -- fixture 1849 is
      # header=public so nothing can cap a chip on it. See the shot list. Left
      # here, ready, so it is one --only b2 away once a subject is agreed.
      "b2": ("A capped section chip", "owner", u,
             ["desktop-wide"],
             lambda t: scroll_to(t, ".lg-pmp--capped"), ".lg-pmp--capped"),
      "b3": ("Location: both audience dials", "owner", u,
             ["phone", "desktop-wide"],
             lambda t: scroll_to(t, ".lg-block--location"), ".lg-loc__audrow"),
      "b4": ("Location precision menu open", "owner", u,
             ["phone"],
             lambda t: (scroll_to(t, ".lg-block--location"), click(t, ".lg-loc__aud")),
             ".lg-pmp-menu, .lg-loc__aud[aria-expanded=true]"),
      "b5": ("Discussion-posts toggle", "owner", u,
             ["phone"], None, ".lg-disc-seg"),
      "b6": ("View as -> Member", "owner", u + "?view=member",
             ["phone", "desktop-wide"], None, ".lg-viewas"),
      "b7": ("View as -> Public (privacy anchor)", "owner", u + "?view=public",
             ["phone", "desktop-wide"], None, ".lg-viewas"),
      # B8 needs an ANON viewer on a members-only subject. Fixture 1849 is
      # parked header=public, so it can never show this -- but three real
      # subjects already sit at header=members, so nothing needs mutating.
      "b8": ("The members GATE screen", "anon", "/u/pilot_pro",
             ["phone", "desktop-wide"], None, ".lg-gate"),

      # --- C / D
      "c1": ("Someone else's profile", "member", "/u/steve-cantrell",
             ["phone", "desktop-wide"], None, None),
      "c2": ("Directory list", "member", "/directory/members",
             ["phone", "desktop-wide"], None, None),
      "d1": ("Desktop entry: account menu", "member", "/hub/",
             ["desktop-wide"], None, None),
      "d2": ("Mobile entry: bottom tab bar", "member", "/hub/",
             ["phone"], None, None),
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=os.path.expanduser(
        "~/projects/footer-mockups/profile-guide-shots"))
    ap.add_argument("--only", default="", help="comma list of shot ids")
    ap.add_argument("--viewport", default="", help="restrict to one viewport")
    ap.add_argument("--skip-resolver", action="store_true")
    ap.add_argument("--theme", default="light", choices=["light", "dark", "both"],
                    help="light|dark|both. The shared Chrome profile persists a "
                         "theme, so this is always set explicitly and asserted.")
    args = ap.parse_args()

    os.makedirs(args.out, exist_ok=True)
    if not args.skip_resolver:
        print(f"[resolver] {ensure_resolver()}")

    tok = gate_token()
    sessions = {"owner": mint(OWNER_WP), "member": mint(MEMBER_WP), "anon": None}

    shots = build_shots()
    want = [s.strip() for s in args.only.split(",") if s.strip()] or list(shots)

    tab = Tab()
    written, findings = [], []
    try:
        themes = ["light", "dark"] if args.theme == "both" else [args.theme]
        for sid in want:
            title, role, path, vps, action, need = shots[sid]
            if args.viewport:
                vps = [v for v in vps if v == args.viewport]
            for vp, theme in [(v, t) for v in vps for t in themes]:
                tab.viewport(vp)
                # Cookies are re-set per shot because the role changes between
                # shots and an anon frame must NOT inherit the owner session.
                tab.send("Network.clearBrowserCookies")
                tab.cookie("loothdev_auth", tok)
                if sessions[role]:
                    tab.cookie("looth_id", sessions[role])
                tab.goto(BASE + path)
                # Theme is forced AFTER navigation on purpose: a localStorage
                # write on about:blank is a no-op, so setting it before the
                # first real page silently does nothing.
                tab.set_theme(theme)
                if action:
                    action(tab)
                if need and not tab.js(
                        f"!!document.querySelector({json.dumps(need)})"):
                    findings.append(f"{sid}/{vp}: {need} NOT in DOM -- frame not shot")
                    continue
                name = f"{sid}-{title.split('(')[0].strip().lower().replace(' ','-')[:28]}-{vp}"
                name = name + ("-dark.png" if theme == "dark" else ".png")
                name = name.replace("/", "-")
                n = tab.shot(os.path.join(args.out, name))
                written.append(f"{name}  ({n//1024}kb)")
                print(f"  shot {name}  {n//1024}kb")
    finally:
        tab.close()

    print(f"\n{len(written)} frames -> {args.out}")
    for f in findings:
        print(f"  FINDING: {f}")


if __name__ == "__main__":
    main()
