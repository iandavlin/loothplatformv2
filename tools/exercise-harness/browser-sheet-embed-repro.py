#!/usr/bin/env python3
"""Backlog 3.7 — discussion EMBEDS do not render in the mobile reader sheet.

Ian 7/31: on mobile the embed isn't working for discussions. First reproduced by the
mobile-embed lane (branch origin/mobile-embed, docs/atlas/MOBILE-EMBED-FINDINGS.md);
this re-measures on THIS branch and adds the after-state, rather than re-deriving.

THE SURFACE IS THE SHEET, not the card and not the standalone page. On a phone every
discussion tap opens #looth-rep-sheet — it is the only way to read a discussion from
the hub. The sheet assigns server HTML straight into the DOM at three places in
webroot/hub-polish.js (resolve them by SYMBOL; they drifted +2 since the last lane
cited them):

    the OP body            body.innerHTML = html
    the pre-paint excerpt  body.innerHTML = ex ? ex.innerHTML : ''
    the replies            full.innerHTML = html

None of them calls bbProcessEmbeds, which is what turns a bare provider link into a
player. The desktop reader modal calls it on the identical data path
(bb-mirror/web/forums.js, the dmB/ex/full assignments around 2982-2993).

SAME DEFECT CLASS AS 4.4/4.3 — server markup relocated into a page without the code
that animates it. Different mechanism: here the function is globally exported and
already loaded, and the sheet simply never calls it.

CONTROL: the standalone topic page at the SAME 390px width, where forums.js sweeps
.post__body on load. If that renders a player and the sheet does not, it is not the
viewport, not CSS and not the provider regex.
"""
import json, sys, time, base64, os, urllib.request, websocket

CDP = "http://127.0.0.1:9222"
BASE = os.environ.get("LG_BASE", "https://dev2.loothgroup.com")
COOKIES = os.environ.get("LG_COOKIES", "/tmp/mobile-bugs-exercise/cookies.txt")
SHOTS = os.environ.get("LG_SHOTS", "/tmp/mobile-bugs-exercise/shots-embed")
# The only YouTube-facade discussion on page 1 of /hub/?type=discussions.
TOPIC_PATH = os.environ.get("LG_TOPIC", "/hub/touring-tech/test-3/")
TOPIC_ID = os.environ.get("LG_TID", "72375")



def gate_token():
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


def jwt_token():
    t = os.environ.get("LG_LOOTH_ID")
    if t:
        return t
    import subprocess
    out = subprocess.run(
        ["sudo", "-u", "looth-dev", "wp", "--path=/var/www/dev", "eval",
         'echo looth_auth_mint_jwt(get_user_by("id", 1912));'],
        capture_output=True, text=True).stdout.strip().splitlines()
    tok = [l for l in out if l.count(".") == 2 and l.startswith("eyJ")]
    if not tok:
        raise SystemExit("could not mint looth_id")
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
    p.send("Network.enable")
    p.send("Network.setCacheDisabled", {"cacheDisabled": True})
    p.send("Network.clearBrowserCookies")
    if not BASE.startswith("https://dev2."):
        # Loopback harness (hub-router on php -S): no dev gate to satisfy — the gate
        # authorizes 127.0.0.1 by IP — but profile-app/WP identity still has to be
        # real, so the same cookies go on, non-secure for the plain-http origin.
        cks = [{"name": "looth_id", "value": jwt_token(), "url": BASE, "path": "/"}]
        cks += [{"name": k.strip(), "value": v.strip(), "url": BASE, "path": "/"}
                for k, v in (l.split("=", 1) for l in open(COOKIES) if "=" in l)]
        p.send("Network.setCookies", {"cookies": cks})
        return len(cks)
    cks = [{"name": "loothdev_auth", "value": gate_token(),
            "domain": ".dev2.loothgroup.com", "path": "/", "secure": True},
           {"name": "looth_id", "value": jwt_token(),
            "domain": ".dev2.loothgroup.com", "path": "/", "secure": True}]
    cks += [{"name": k.strip(), "value": v.strip(), "url": BASE, "path": "/"}
            for k, v in (l.split("=", 1) for l in open(COOKIES) if "=" in l)]
    p.send("Network.setCookies", {"cookies": cks})
    return len(cks)


def mobile(p):
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": 390, "height": 844, "deviceScaleFactor": 3, "mobile": True})
    p.send("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})
    p.send("Emulation.setEmitTouchEventsForMouse",
           {"enabled": True, "configuration": "mobile"})


def goto(p, url, settle=5.0):
    p.send("Page.navigate", {"url": url})
    for _ in range(160):
        time.sleep(0.25)
        try:
            if p.ev("document.readyState") == "complete":
                break
        except Exception:
            pass
    time.sleep(settle)


def assert_live(p, where):
    st = p.ev("""[document.title, innerWidth, matchMedia('(max-width:640px)').matches,
                  !!document.querySelector('meta[name=viewport]'),
                  !!document.querySelector('.lg-chrome__avatar'),
                  !!document.querySelector('.lg-chrome__signin')]""")
    title, w, phone, hasvp, avatar, signin = st
    bad = []
    if "403" in str(title): bad.append(f"GATE 403 ({title})")
    if not hasvp: bad.append("no viewport meta")
    if w > 640: bad.append(f"innerWidth {w} > 640 — not a phone layout")
    if not phone: bad.append("(max-width:640px) FALSE — DESKTOP")
    if signin: bad.append("header shows Sign in — ANONYMOUS")
    if not avatar: bad.append("no member avatar — identity did not resolve")
    if bad:
        raise SystemExit(f"\n!! ABORT at {where}: " + "; ".join(bad))
    return f"390px phone, member, {title!r}"


def shot(p, name):
    os.makedirs(SHOTS, exist_ok=True)
    r = p.send("Page.captureScreenshot", {"format": "png"})
    open(os.path.join(SHOTS, name + ".png"), "wb").write(base64.b64decode(r["data"]))


# Count the things that make an embed an embed, inside one scope.
EMBED = """(function(){
  var root = document.querySelector(%s);
  if (!root) return {missing:true};
  var html = root.innerHTML || '';
  return {
    players:   root.querySelectorAll('iframe').length,
    bb_embed:  root.querySelectorAll('.bb-embed').length,
    facades:   root.querySelectorAll('[data-yt-play], .fc-cover--video').length,
    bare_link: (html.match(/href="https?:\\/\\/(?:www\\.)?(?:youtu\\.be|youtube\\.com|vimeo\\.com|instagram\\.com|x\\.com|twitter\\.com)/g)||[]).length,
    text:      (root.textContent||'').trim().slice(0,60)
  };
})()"""


def line(k, v):
    print(f"  {k:<20}: {v}")


def main():
    tid, p = new_page()
    try:
        auth(p)
        mobile(p)
        p.send("Page.enable")

        print("\n=== CONTROL — the STANDALONE topic page at 390px ===")
        goto(p, BASE + TOPIC_PATH)
        line("liveness", assert_live(p, "standalone topic"))
        line("bbProcessEmbeds", p.ev("typeof window.bbProcessEmbeds"))
        c = p.ev(EMBED % json.dumps(".post__body"))
        for k in ("players", "bb_embed", "bare_link", "text"):
            line(k, c.get(k))
        shot(p, "control-standalone")
        control_ok = c.get("players", 0) >= 1
        print(f"  >> CONTROL {'PASS — the standalone page renders a player' if control_ok else 'FAILED — no player even here; do not trust the sheet result'}")

        print("\n=== DEFECT — the same discussion in the MOBILE READER SHEET ===")
        goto(p, BASE + "/hub/?type=discussions")
        line("liveness", assert_live(p, "hub"))
        line("bbProcessEmbeds", p.ev("typeof window.bbProcessEmbeds"))

        # Open the sheet the way the hub does it.
        opened = p.ev(f"""(function(){{
          var a = document.querySelector('a[href*="{TOPIC_PATH}"]');
          var card = a && a.closest('.feed-card, article, [data-topic-id]');
          if (window.lgOpenTopicMobile && card) {{ window.lgOpenTopicMobile(card); return 'lgOpenTopicMobile'; }}
          if (a) {{ a.click(); return 'link-click'; }}
          return null;
        }})()""")
        line("opened via", opened)
        time.sleep(6.0)
        line("sheet open", p.ev("!!document.querySelector('#looth-rep-sheet.is-open, "
                                "#looth-rep-sheet[style*=\"display: block\"]')"))
        d = p.ev(EMBED % json.dumps("#looth-rep-sheet"))
        for k in ("players", "bb_embed", "bare_link", "text"):
            line(k, d.get(k))
        shot(p, "defect-sheet")

        print("\n=== VERDICT ===")
        print(f"  standalone page : players={c.get('players')} bb-embed={c.get('bb_embed')} "
              f"bare={c.get('bare_link')}")
        print(f"  reader sheet    : players={d.get('players')} bb-embed={d.get('bb_embed')} "
              f"bare={d.get('bare_link')}")
        if d.get("missing"):
            print("  !! the sheet never opened — no verdict on the embed")
            return 2
        same = (d.get("players", 0) >= 1)
        print(f"  3.7 sheet == standalone : "
              f"{'YES — the sheet renders the player too' if same else 'NO — the sheet shows a bare link where the page shows a player'}")
        print(f"\n  shots -> {SHOTS}")
    finally:
        p.close()
        close_page(tid)


if __name__ == "__main__":
    sys.exit(main())
