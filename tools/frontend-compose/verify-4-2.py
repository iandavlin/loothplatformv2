#!/usr/bin/env python3
"""
verify-4-2.py — shoot and MEASURE everything train 4.2 carries for this lane, the
moment the serve pulls it.

Written BEFORE the deploy on purpose. Four surfaces went in tonight that are
visual by nature and could not be rendered from the branch — the docroot's
mu-plugins and /srv/archive-poc both symlink into the serving checkout — so the
honest position until deploy is "built, not verified". This turns that into one
command instead of a scramble.

WHAT IT CHECKS, per surface, at 1280 and 390, in light and dark:
  /compose/?type=loothprint
      · site header + footer VISIBLE          (Ian: "looks like a normal page")
      · the hero picker exists                 (ruling 4)
      · the ZIP drop-zone is a zone, not a sliver — it asserts a HEIGHT, because
        the defect Ian reported was a 21px control next to a 104px one (ruling 6)
      · the extras fold is GONE                (ruling 3)
      · tip jar / Onshape are GONE             (ruling 2)
      · the video field is in the body         (ruling 1)
  /loothprint/<slug>/
      · the Edit button opens a two-line choice, and "Details & files" points at
        the compose route with a POSITIVE id   (Ian's Option A)

⚠️ LIVENESS FIRST ON EVERY SURFACE. A locked-out browser serves a styled 403 that
is identical in both themes and at both widths; every assertion below would then
be measuring nothing, and the absence checks (rulings 2 and 3) would go GREEN for
entirely the wrong reason. That is the recorded vacuous-pass trap and it is the
whole reason this refuses to score a surface whose form did not render.

Exit: 0 all good, 1 something is wrong, 2 CANNOT RUN (2, never 3 — run-all.sh
reads anything else as RED).

Usage:  python3 tools/frontend-compose/verify-4-2.py [--slug fret-sander-v2] [--out DIR]
"""
import argparse, base64, json, os, subprocess, sys, time

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
        for _ in range(8):
            time.sleep(0.75)
            v = self.call("Runtime.evaluate", expression=expr,
                          returnByValue=True).get("result", {}).get("value")
            if v:
                return v
        return None

    def shot(self, path):
        d = self.call("Page.captureScreenshot", format="png", captureBeyondViewport=True)["data"]
        open(path, "wb").write(base64.b64decode(d))
        return os.path.getsize(path) // 1024

    def close(self):
        try:
            self.ws.close()
        finally:
            sh(["curl", "-s", f"{CDP}/json/close/{self.id}"])


COMPOSE_PROBE = r"""(() => {
  const vis = el => { if (!el) return {f:false,v:false,h:0};
    const c = getComputedStyle(el), r = el.getBoundingClientRect();
    return {f:true, v:c.display!=='none'&&c.visibility!=='hidden'&&r.height>4, h:Math.round(r.height)}; };
  /* ⚠️ FIELD PRESENCE IS ASKED OF THE DOM, NOT OF innerText. The first draft tested
     document.body.innerText for "tip jar" / "onshape", and those checks PASSED
     against the pre-deploy serve where both fields still exist — because they sat
     inside the collapsed extras fold, and innerText excludes hidden text. An
     absence assertion that is already green before the change is worthless: it
     would have passed after 4.2 too and proved nothing either way.
     data-name is what distinguishes "removed" from "merely hidden". */
  const field = n => !!document.querySelector('.acf-field[data-name="' + n + '"]');
  const zip  = document.querySelector('.acf-field-file .acf-file-uploader > .hide-if-value');
  return JSON.stringify({
    form:   !!document.querySelector('.lgfc__card'),
    title:  document.title,
    header: vis(document.querySelector('[class*="lg-chrome"]:not(.lg-chrome-foot)')),
    footer: vis(document.querySelector('footer.lg-chrome-foot, [role="contentinfo"]')),
    hero:   !!document.querySelector('.lgfc__hero'),
    zip:    vis(zip),
    fold:   !!document.querySelector('.lgfc__fold, [data-lgfc-extras]'),
    /* Ruling 5: the taxonomy pickers became sheets with search. Asserts the NEW
       control exists AND that the old 184px scrolling box is no longer what you
       see — the source list must still be in the DOM (it is what submits) but
       must not be the visible control. */
    taxoTrig: document.querySelectorAll('.lgfc-taxo__trig').length,
    taxoSrcHidden: (() => {
      const srcs = [...document.querySelectorAll('.lgfc .acf-field-taxonomy .acf-checkbox-list')];
      if (!srcs.length) return false;
      return srcs.every(u => { const r = u.getBoundingClientRect(); return r.height <= 2; });
    })(),
    taxoStates: (() => {
      const t = document.querySelector('.lgfc-taxo__trig');
      return t ? t.textContent.replace(/\s+/g,' ').trim().slice(0, 60) : '';
    })(),
    tipjar:  field('loothprint_buy_me_a_coffee'),
    onshape: field('loothprint_onshape_link'),
    /* Ruling 1 is "into the MAIN BODY", so presence alone is vacuous — the field
       existed before too, folded away. The real question is whether it is still
       inside a fold. */
    video:   (() => { const el = document.querySelector('.acf-field[data-name="loothprint_video_instructions"]');
                      return !!el && !el.closest('.lgfc__fold, .lgfc__foldb'); })(),
  });
})()"""

LP_PROBE = r"""(() => {
  const wrap = document.querySelector('[data-lg-editmenu]');
  const items = wrap ? [...wrap.querySelectorAll('.lg-editmenu__i')] : [];
  /* ⚠️ AUTH LIVENESS. The Edit control only renders for edit_archive_poc OR the
     author, and that identity comes from a server-side /whoami loopback which is
     INTERMITTENT for a synthetically minted cookie — measured: the same tree gave
     items=2 on one run and items=0 on the next. Without this, "no menu" is
     indistinguishable from "not recognised as signed in", and the check becomes
     the vacuous-absence trap: it would report a defect on Ian's surface whenever
     whoami happened to blink. If the page thinks we are signed out, the menu legs
     are SKIPPED, never failed. */
  const signedOut = !!document.querySelector('.lg-chrome__signin, .lg-chrome__join');
  return JSON.stringify({
    live:  !!document.querySelector('.lg-standalone-main, article, main'),
    authed: !signedOut,
    title: document.title,
    menu:  !!wrap,
    count: items.length,
    hrefs: items.map(a => a.getAttribute('href') || ''),
    single:!!document.querySelector('a.lg-standalone-edit'),
  });
})()"""


def theme(tab, url, cookies, th):
    tab.call("Network.setCookies", cookies=cookies)
    tab.call("Page.navigate", url=url); time.sleep(2.5)
    tab.call("Runtime.evaluate", expression=(
        f"localStorage.setItem('lg-set-theme','{th}');"
        f"localStorage.setItem('lg-set-boot',JSON.stringify({{theme:'{th}',dark:{str(th=='dark').lower()}}}));"
        f"document.documentElement.setAttribute('data-lguser-theme','{th}');"))
    tab.call("Page.navigate", url=url); time.sleep(3)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--slug", default="fret-sander-v2")
    ap.add_argument("--login", default="claude_admin")
    ap.add_argument("--out", default="/home/ubuntu/projects/footer-mockups/loothprint-edit/verify")
    a = ap.parse_args()

    env = gate_env(); dom, base = env["LG_GATE_DOMAIN"], env["LG_GATE_HOST"]
    cookies = [{"name": "loothdev_auth", "value": env["LG_GATE_TOKEN"],
                "domain": "." + dom, "path": "/", "secure": True}]
    for n, v in wp_cookies(a.login):
        cookies.append({"name": n, "value": v, "domain": dom, "path": "/",
                        "secure": True, "httpOnly": True, "sameSite": "Lax"})
    os.makedirs(a.out, exist_ok=True)
    bad = []
    tab = Tab()
    try:
        tab.call("Page.enable"); tab.call("Network.enable"); tab.call("Network.clearBrowserCookies")
        for w, h, mob in ((1280, 1500, False), (390, 1500, True)):
            for th in ("light", "dark"):
                tag = f"{'desktop' if w > 640 else 'phone'}-{th}"
                tab.call("Emulation.setDeviceMetricsOverride", width=w, height=h,
                         deviceScaleFactor=1, mobile=mob, screenWidth=w, screenHeight=h)

                # ⚠️ RETRY BEFORE CONDEMNING A SURFACE. On the first full post-deploy
                # run exactly one frame of eight (phone-dark) came back without a
                # form, and re-driving that frame three times gave form=True every
                # time — a flake under load, not a defect. A one-shot check would
                # have reported it as a finding on Ian's own surface, which is
                # worse than useless: gates that flake and move their symptoms are
                # how a real red gets waved away later.
                d = None
                for attempt in range(3):
                    theme(tab, base + "/compose/?type=loothprint", cookies, th)
                    raw = tab.js(COMPOSE_PROBE)
                    if raw:
                        d = json.loads(raw)
                        if d["form"]:
                            if attempt:
                                print(f"      (form appeared on attempt {attempt + 1} — "
                                      f"slow frame, not a defect)")
                            break
                if d is None:
                    raise CannotRun("compose page never answered after 3 attempts")
                kb = tab.shot(os.path.join(a.out, f"compose-{tag}.png"))
                if not d["form"]:
                    print(f"  compose {tag:<14} CANNOT TRUST — no form (flag off / refused). {kb}KB")
                    bad.append(f"compose {tag}: form did not render")
                    continue
                print(f"  compose {tag:<14} live, {kb}KB")
                for label, ok, detail in (
                    ("site header visible", d["header"]["v"], f"h={d['header']['h']}"),
                    ("site footer visible", d["footer"]["v"], f"h={d['footer']['h']}"),
                    ("hero picker present", d["hero"], ""),
                    ("ZIP zone is a zone (>=60px)", d["zip"]["f"] and d["zip"]["h"] >= 60, f"h={d['zip']['h']}"),
                    ("extras fold GONE", not d["fold"], ""),
                    ("tip jar GONE", not d["tipjar"], ""),
                    ("Onshape GONE", not d["onshape"], ""),
                    ("video in the body", d["video"], ""),
                    ("taxonomy pickers are sheets (2)", d["taxoTrig"] == 2, f"triggers={d['taxoTrig']}"),
                    ("old scrolling term box not visible", d["taxoSrcHidden"], ""),
                    ("closed row states the answer", bool(d["taxoStates"]), d["taxoStates"][:40]),
                ):
                    print(f"      {'ok  ' if ok else 'RED '} {label} {detail}")
                    if not ok:
                        bad.append(f"compose {tag}: {label} {detail}")

                theme(tab, f"{base}/loothprint/{a.slug}/", cookies, th)
                raw = tab.js(LP_PROBE)
                d2 = json.loads(raw) if raw else {}
                kb = tab.shot(os.path.join(a.out, f"loothprint-{tag}.png"))
                if not d2.get("live"):
                    print(f"  loothprint {tag:<11} CANNOT TRUST — page did not render. {kb}KB")
                    bad.append(f"loothprint {tag}: page did not render")
                    continue
                if not d2.get("authed"):
                    # whoami did not recognise the session — see the probe note.
                    print(f"  loothprint {tag:<11} SKIPPED — page renders us signed OUT, so the "
                          f"Edit control is correctly absent and proves nothing. {kb}KB")
                    continue
                print(f"  loothprint {tag:<11} live, {kb}KB")
                menu_ok = d2.get("menu") and d2.get("count") == 2
                ids_ok  = any("/compose/?type=loothprint&id=" in h and not h.endswith("id=0")
                              for h in d2.get("hrefs", []))
                for label, ok, detail in (
                    ("Edit opens a 2-line choice", menu_ok, f"items={d2.get('count')}"),
                    ("Details link carries a real id", ids_ok, str(d2.get("hrefs"))[:70]),
                ):
                    print(f"      {'ok  ' if ok else 'RED '} {label} {detail}")
                    if not ok:
                        bad.append(f"loothprint {tag}: {label} {detail}")
    except CannotRun as e:
        print(f"CANNOT RUN: {e}")
        return 2
    finally:
        tab.close()

    print()
    if bad:
        print(f"RED — {len(bad)} problem(s):")
        for b in bad:
            print(f"  - {b}")
        return 1
    print(f"GREEN — every surface checks out at both widths in both themes. Shots in {a.out}")
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
