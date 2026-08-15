#!/usr/bin/env python3
"""
browser-verify.py — tap-to-reply, driven in a real Chrome, BEFORE the merge.

WHY THIS EXISTS IN THIS SHAPE — READ BEFORE "FIXING" IT
------------------------------------------------------
This feature cannot be previewed on an unmerged branch, and that is a property of
where it lives rather than of the preview mechanism. Its own conf says so:
platform/nginx/lane-preview-notif-quickreply.conf. The modal is driven by WEBROOT
js — notif-reply.js, the #lgc-quote slot in hub-polish.js, the row handlers in
bottom-nav.js — and pwa.js injects those at ABSOLUTE paths that resolve against
the document root, where each is an individual symlink into the SERVING CHECKOUT.
So a lane-path preview would serve the branch's HTML while running MAIN's entire
overlay layer: a page that looks right and does nothing. Pointing vhost-wide
locations at a worktree would change the real /hub/ for everyone.

So this substitutes those files IN THE BROWSER, per CDP request interception, and
changes nothing on the box. No nginx conf, no symlink, no sudo beyond the gate's
own database reads.

WHAT IT THEREFORE PROVES, AND WHAT IT DOES NOT — stated because a verification
that overclaims is worse than none:

  PROVES   the branch's real client files, running in a real logged-in /hub/ page
           served by the real nginx (sub_filter and all), open a real composer
           with a real quote whose HTML came from executing the BRANCH's
           topic.php against the real database.
  DOES NOT the nginx routing of the preview path, or that pwa.js requests
  PROVE    notif-reply.js in production. Those are asserted by gate 52 instead
           (the attribute is emitted only when the flag is on, and pwa.js
           requests the file only behind that attribute), and they are what the
           post-merge preview is for.

THE INTERCEPTION IS NARROW ON PURPOSE. Only the four client files and the quote
fetch are answered locally; everything else goes to the real origin untouched.
A broad swap is a recorded trap on this box — a swapped HTML route bypasses
nginx and therefore ships without /pwa.js, taking the whole mobile overlay layer
with it, and the page still looks perfect.

Exit: 0 green, 1 a real defect, 3 CANNOT RUN.
"""
import base64
import importlib.util
import json
import os
import subprocess
import sys
import time
from pathlib import Path

import websocket

CDP = "http://127.0.0.1:9222"
REPO = Path(__file__).resolve().parents[2]
GATE = REPO / "tools" / "gates" / "notif-quickreply-gate.py"
PROBE_LOGIN = "claude_admin"

VIEWPORTS = [
    ("desktop", 1280, 1400, False),
    ("phone",    390, 1500, True),
]
THEMES = ["light", "dark"]

# Served from the branch instead of the docroot. Keyed by the BASENAME, because
# the two apps reach the same files by different paths and version queries — the
# same reason the modal's own script-dedupe is by filename and not by URL.
SUBS = {
    "notif-reply.js":   REPO / "webroot" / "notif-reply.js",
    "hub-polish.js":    REPO / "webroot" / "hub-polish.js",
    "bottom-nav.js":    REPO / "webroot" / "bottom-nav.js",
    "social-modals.js": REPO / "lg-shared" / "social-modals.js",
}


class CannotRun(Exception):
    pass


def sh(cmd):
    return subprocess.run(cmd, capture_output=True, text=True)


def load_gate():
    """Reuse the gate's fixtures() and run_topic_php() rather than re-deriving
    them: two definitions of 'which reply do we quote' would drift, and the gate's
    is the one that has been red-first proven."""
    spec = importlib.util.spec_from_file_location("nqrgate", GATE)
    m = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    return m


def gate_env():
    r = sh(["bash", str(REPO / "tools" / "gates" / "gate-env.sh")])
    if r.returncode != 0:
        raise CannotRun("gate-env.sh failed: " + r.stderr[:200])
    return dict(l.partition("=")[::2] for l in r.stdout.splitlines() if "=" in l)


def wp_eval(php):
    r = sh(["sudo", "-n", "wp", "--allow-root", "--path=/var/www/dev",
            "--skip-themes", "eval", php])
    if r.returncode != 0:
        raise CannotRun("wp eval failed: " + (r.stderr or "")[:200])
    return "\n".join(l for l in r.stdout.splitlines()
                     if not l.startswith(("PHP Warning:", "PHP Deprecated:",
                                          "Warning:", "Deprecated:"))).strip()


class Tab:
    """Persistent CDP session with an event pump (interception needs one)."""

    def __init__(self):
        t = json.loads(subprocess.check_output(
            ["curl", "-s", "-X", "PUT", f"{CDP}/json/new?about:blank"]))
        self.id = t["id"]
        self.ws = websocket.create_connection(
            t["webSocketDebuggerUrl"], suppress_origin=True, timeout=60)
        self.n = 0
        self.on_paused = None
        self.served = []
        self.pending = {}

    def _handle(self, msg):
        if msg.get("method") == "Fetch.requestPaused" and self.on_paused:
            self.on_paused(msg["params"])

    def call(self, method, **params):
        """Send one command and wait for ITS reply.

        ⚠️ RE-ENTRANT ON PURPOSE, and the pending dict is what makes that safe.
        The interception handler answers a paused request by calling
        Fetch.fulfillRequest — from INSIDE this receive loop. A naive
        "recv until I see my own id" then runs nested, and the inner loop
        consumes the OUTER command's response and throws it away, so the outer
        call blocks until its socket timeout and the whole run hangs with no
        output. Measured: the first viewport never completed.

        Any reply that is not mine is parked in self.pending for whoever is
        waiting on it, instead of being dropped.
        """
        self.n += 1
        mine = self.n
        self.ws.send(json.dumps({"id": mine, "method": method, "params": params}))
        while True:
            if mine in self.pending:
                msg = self.pending.pop(mine)
                if "error" in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get("result", {})
            msg = json.loads(self.ws.recv())
            mid = msg.get("id")
            if mid == mine:
                if "error" in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get("result", {})
            if mid is not None:
                self.pending[mid] = msg
            else:
                self._handle(msg)

    def pump(self, seconds):
        """Service interception events for a while. Used INSTEAD of sleep — a
        plain sleep here deadlocks the page: every paused request stays paused."""
        end = time.time() + seconds
        self.ws.settimeout(0.4)
        try:
            while time.time() < end:
                try:
                    m = json.loads(self.ws.recv())
                    if m.get("id") is not None:
                        self.pending[m["id"]] = m
                    else:
                        self._handle(m)
                except websocket.WebSocketTimeoutException:
                    pass
        finally:
            self.ws.settimeout(60)

    def js(self, expr):
        r = self.call("Runtime.evaluate", expression=expr,
                      returnByValue=True, awaitPromise=True)
        if "exceptionDetails" in r:
            d = (r["exceptionDetails"].get("exception") or {}).get("description", "")
            raise RuntimeError("JS threw: " + str(d)[:250])
        return r["result"].get("value")

    def wait_for(self, expr, seconds=20):
        end = time.time() + seconds
        while time.time() < end:
            try:
                if self.js(expr):
                    return True
            except RuntimeError:
                pass
            self.pump(0.5)
        return False

    def close(self):
        try:
            self.ws.close()
        finally:
            sh(["curl", "-s", f"{CDP}/json/close/{self.id}"])


def cookies_for(env, login):
    domain = env["LG_GATE_DOMAIN"]
    out = [{"name": "loothdev_auth", "value": env["LG_GATE_TOKEN"],
            "domain": "." + domain, "path": "/", "secure": True}]
    raw = wp_eval(
        f"$u = get_user_by('login', '{login}');"
        "if (!$u) { echo 'NOUSER'; exit; }"
        "$e = time() + 3600;"
        "echo LOGGED_IN_COOKIE . '|' . wp_generate_auth_cookie($u->ID, $e, 'logged_in') . \"\\n\";"
        "echo SECURE_AUTH_COOKIE . '|' . wp_generate_auth_cookie($u->ID, $e, 'secure_auth');")
    if raw == "NOUSER" or not raw:
        raise CannotRun(f"no such user: {login}")
    for line in raw.splitlines():
        if "|" in line:
            n, v = line.split("|", 1)
            out.append({"name": n, "value": v, "domain": domain, "path": "/",
                        "secure": True, "httpOnly": True, "sameSite": "Lax"})
    return out


def main():
    print("notif-quickreply browser-verify — the branch's client, in a real page")
    findings, notes = [], []
    tab = None
    try:
        env = gate_env()
        g = load_gate()
        try:
            subprocess.check_output(["curl", "-s", "--max-time", "3",
                                     f"{CDP}/json/version"])
        except Exception:
            print("  CANNOT RUN: no CDP on 127.0.0.1:9222")
            return 3

        fx = g.fixtures()
        if not fx:
            print("  CANNOT RUN: no fixture reply available from the mirror")
            return 3
        quote_html = g.run_topic_php(
            f"forum={fx['forum']}&topic={fx['topic']}&reply_context={fx['reply']}",
            flag_on=True)
        if not quote_html or "lg-nqr-quote" not in quote_html:
            print("  CANNOT RUN: the branch's topic.php produced no quote to serve "
                  "— the server half is gate 52's business, not this script's")
            return 3
        print(f"  fixture: forum={fx['forum']} topic={fx['topic']} "
              f"reply={fx['reply']}  quote {len(quote_html)}B from the branch's topic.php")

        bodies = {k: p.read_text(encoding="utf-8") for k, p in SUBS.items()}
        for k, v in bodies.items():
            print(f"  substituting {k:<18} {len(v):>6}B from the branch")

        cookies = cookies_for(env, PROBE_LOGIN)
        # ⚠️ ONE `topic` PARAM, "<forum-slug>/<topic-slug>", NOT separate forum= and
        # topic=. nqrParse() reads searchParams.get('topic') and splits it on the
        # first slash; a link without that slash returns null and lgOpenNotifReply
        # answers FALSE — which is its correct fail-open behaviour and reads
        # exactly like "the modal is broken". Measured: took=False at both widths
        # against a build whose modal was fine. This is the notification link
        # shape the bridge actually emits.
        link = (f"{env['LG_GATE_HOST']}/hub/?topic={fx['forum']}/{fx['topic']}"
                f"&reply={fx['reply']}")

        tab = Tab()
        tab.call("Page.enable")
        tab.call("Network.enable")
        tab.call("Runtime.enable")
        tab.call("Network.clearBrowserCookies")
        tab.call("Network.setCookies", cookies=cookies)

        def paused(p):
            rid, url = p["requestId"], p["request"]["url"]
            base = url.split("?")[0].rsplit("/", 1)[-1]
            try:
                if "reply_context=" in url:
                    tab.served.append("quote")
                    tab.call("Fetch.fulfillRequest", requestId=rid, responseCode=200,
                             responseHeaders=[{"name": "Content-Type",
                                               "value": "text/html; charset=utf-8"}],
                             body=base64.b64encode(quote_html.encode()).decode())
                    return
                if base in bodies:
                    tab.served.append(base)
                    tab.call("Fetch.fulfillRequest", requestId=rid, responseCode=200,
                             responseHeaders=[{"name": "Content-Type",
                                               "value": "application/javascript; charset=utf-8"},
                                              {"name": "Cache-Control", "value": "no-store"}],
                             body=base64.b64encode(bodies[base].encode()).decode())
                    return
                tab.call("Fetch.continueRequest", requestId=rid)
            except RuntimeError:
                pass          # the request went away; nothing to answer

        tab.on_paused = paused
        tab.call("Fetch.enable", patterns=[
            {"urlPattern": "*notif-reply.js*"}, {"urlPattern": "*hub-polish.js*"},
            {"urlPattern": "*bottom-nav.js*"},  {"urlPattern": "*social-modals.js*"},
            {"urlPattern": "*reply_context=*"},
        ])

        shots_dir = Path(os.environ.get("NQR_SHOTS", "/home/ubuntu/projects/footer-mockups/notif-quickreply/build-shots"))
        shots_dir.mkdir(parents=True, exist_ok=True)
        made = []

        only = os.environ.get("NQR_ONLY", "")   # e.g. "dark/phone"
        for theme in THEMES:
            for label, w, h, mobile in VIEWPORTS:
                if only and only != f"{theme}/{label}":
                    continue
                tab.call("Emulation.setDeviceMetricsOverride", width=w, height=h,
                         deviceScaleFactor=1, mobile=mobile,
                         screenWidth=w, screenHeight=h)
                tab.call("Emulation.setTouchEmulationEnabled", enabled=mobile,
                         maxTouchPoints=5 if mobile else 1)
                tab.call("Network.setCookies", cookies=cookies)

                tab.call("Page.navigate", url=env["LG_GATE_HOST"] + "/hub/")
                if not tab.wait_for("!!document.body", 25):
                    findings.append(f"[{theme}/{label}] /hub/ never rendered")
                    continue
                # theme BEFORE the run that is photographed: a localStorage write
                # on about:blank is a no-op and the shared profile persists dark.
                tab.js(f"localStorage.setItem('lg-set-theme','{theme}');"
                       f"document.documentElement.setAttribute('data-lguser-theme','{theme}');1")
                tab.call("Page.navigate", url=env["LG_GATE_HOST"] + "/hub/")
                tab.wait_for("!!document.body", 25)
                tab.pump(4)

                # The server would set this from the flag; here the flag is off on
                # the real vhost, so the attribute is set explicitly and the file
                # injected by hand. notif-reply.js reads the flag AT LOAD, so the
                # order matters: attribute first, script second.
                ready = tab.js("""(() => {
                  document.body.setAttribute('data-lg-notifreply','1');
                  return !!document.body.getAttribute('data-lg-notifreply');
                })()""")
                tab.js("""(() => {
                  if (document.querySelector('script[data-nqr]')) return 1;
                  var s = document.createElement('script');
                  s.src = '/notif-reply.js?nqr=1'; s.setAttribute('data-nqr','1');
                  document.head.appendChild(s); return 1;
                })()""")
                tab.wait_for("typeof window.lgOpenNotifReply === 'function'", 20)
                tab.pump(2)

                state = tab.js("""JSON.stringify({
                  open: typeof window.lgOpenNotifReply,
                  comp: typeof window.lgOpenComposer,
                  slot: !!document.getElementById('lgc-quote')})""")
                st = json.loads(state)
                if st["open"] != "function":
                    findings.append(f"[{theme}/{label}] notif-reply.js did not install "
                                    f"lgOpenNotifReply (flag attr={ready}) — the modal "
                                    f"cannot be opened at all")
                    continue
                if st["comp"] != "function":
                    findings.append(f"[{theme}/{label}] no lgOpenComposer on /hub/ — "
                                    f"hub-polish.js is the composer this modal drives")
                    continue

                took = tab.js("window.lgOpenNotifReply({link: %s, actors: '4'})"
                              % json.dumps(link))
                # WAIT FOR THE SHEET, do not just sleep on it. lgOpenNotifReply
                # returns as soon as it has handed off; the quote arrives after a
                # network round trip and the composer builds its editor async. A
                # fixed pump measured the LAST and slowest combo mid-build and
                # reported quote=False / editor=None on a modal that was fine in
                # the other three.
                for _ in range(20):
                    tab.pump(1.0)
                    try:
                        if tab.js("(() => { const q = document.getElementById('lgc-quote');"
                                  "return !!(q && !q.hidden && q.innerHTML.length > 0); })()"):
                            break
                    except RuntimeError:
                        pass
                tab.pump(1)

                seen = tab.js("""JSON.stringify({
                  took: null,
                  sheet: !!document.querySelector('#looth-comp-sheet'),
                  quoteShown: (() => { const q = document.getElementById('lgc-quote');
                     return !!(q && !q.hidden && q.innerHTML.length > 0); })(),
                  quoteText: (() => { const q = document.getElementById('lgc-quote');
                     return q ? q.innerText.slice(0,120) : ''; })(),
                  editor: (() => { const e = document.querySelector('#looth-comp-sheet .ql-editor');
                     return e ? e.textContent : null; })(),
                  openLink: !!document.querySelector('#lgc-quote .lgc-quote__open'),
                  multi: (document.querySelector('#lgc-quote .lgc-quote__multi')||{}).innerText || ''
                })""")
                sn = json.loads(seen)
                print(f"  [{theme}/{label}] took={took} quote={sn['quoteShown']} "
                      f"link={sn['openLink']} editor={sn['editor']!r} multi={sn['multi']!r}")

                if not took:
                    findings.append(f"[{theme}/{label}] lgOpenNotifReply returned false — "
                                    f"the row would have navigated instead of opening")
                if not sn["quoteShown"]:
                    findings.append(f"[{theme}/{label}] the quote slot is empty — the "
                                    f"modal opened without the reply that rang the member")
                if not sn["openLink"]:
                    findings.append(f"[{theme}/{label}] no full-post link beside the "
                                    f"composer — that link is half of what Ian ruled")
                if sn["editor"] not in (None, ""):
                    findings.append(f"[{theme}/{label}] the composer opened PREFILLED "
                                    f"({sn['editor']!r}) — the prefill defect Ian hit")
                if "most recent of 4" not in sn["multi"]:
                    notes.append(f"[{theme}/{label}] coalesced-reply line not shown "
                                 f"(actors=4 was passed): {sn['multi']!r}")

                shot = tab.call("Page.captureScreenshot", format="png")
                p = shots_dir / f"nqr-build-{theme}-{label}.png"
                p.write_bytes(base64.b64decode(shot["data"]))
                made.append(p)

                # THE SCRUB — the highest-risk part, and invisible to a single open:
                # close, then open an ORDINARY composer and check the quote is
                # CLEARED, not merely hidden. A stale quote over an unrelated reply
                # is its own small lie.
                tab.js("""(() => { const c = document.querySelector('#looth-comp-sheet .lgc-close, #looth-comp-sheet [data-lgc-close]');
                                   if (c) c.click(); return 1; })()""")
                tab.pump(1.5)
                if st["slot"] or True:
                    tab.js("try { window.lgOpenComposer({}); } catch(e) {} ; 1")
                    tab.pump(2)
                    scrub = tab.js("""(() => { const q = document.getElementById('lgc-quote');
                      return JSON.stringify({hidden: q ? !!q.hidden : null,
                                             len: q ? q.innerHTML.length : -1}); })()""")
                    sc = json.loads(scrub)
                    print(f"  [{theme}/{label}] scrub after reopen: {sc}")
                    if sc["len"] not in (0, -1):
                        findings.append(f"[{theme}/{label}] the quote was NOT cleared on "
                                        f"a fresh open ({sc['len']} bytes left) — an "
                                        f"ordinary reply would carry a stale quote")

        print(f"\n  served from the branch: "
              f"{ {k: tab.served.count(k) for k in set(tab.served)} }")
        for p in made:
            print(f"  shot {p}")
        if "quote" not in tab.served:
            findings.append("the quote fetch was never intercepted — the modal did not "
                            "ask the server for anything, so nothing above is evidence "
                            "about the real fetch path")
    except CannotRun as e:
        print(f"  CANNOT RUN: {e}")
        return 3
    finally:
        if tab:
            try:
                tab.call("Fetch.disable")
            except Exception:
                pass
            tab.close()

    for n in notes:
        print(f"  note: {n}")
    if findings:
        print("\nRED — tap-to-reply is broken in the browser:")
        for f in findings:
            print(f"  ✗ {f}")
        return 1
    print("\nGREEN — the modal opens with its quote, an empty composer and the "
          "full-post link, at both widths in both themes.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
