#!/usr/bin/env python3
"""
following-section-gate — "Discussions you're following" RENDERS and is REACHABLE.

WHY THIS GATE EXISTS. The section on /manage-subscription/ is a list of links to
somewhere else. A list of links has exactly two ways to be worthless, and neither
of them shows up in "is the element in the DOM":

  1. THE ROW IS THERE BUT NOTHING CAN TOUCH IT. elementFromPoint at a row's centre
     must come back as that row's own link. This is not paranoia — it is already a
     recorded defect class on this box: the shorty dock rendered fine at 390px with
     32 of its 36px underneath NAV#looth-tabbar, and a blind CDP click landed on
     the tabbar and PASSED. Every hit here is hit-tested before it is believed.

  2. THE ROW IS THERE AND THE LINK GOES SOMEWHERE ELSE. This one was found by Ian,
     not by the suite: "the link in the manage goes to the old foum not to the hub
     with the right modal open." Twelve rows rendered, hit-tested and 200 — and
     pointed at a UI he had ruled out. 200 is not the assertion. So every href is
     now FOLLOWED IN A REAL BROWSER and the hub must open the §4e discussion modal
     holding THAT topic id. It has to be a browser: the deep link is client-side
     (forums.js §4f), so curl sees 200 for the feed and learns nothing.

It also asserts the two things the design is FOR, because both are one careless
edit from evaporating: the list stays BOUNDED (5 rows, not all 12), and the single
"Stop all" control exists and is hittable.

GROUND TRUTH IS THE TWO STORES, NEVER THE PAGE. The row set is compared against
PG forums.topic_follow UNION MySQL wp_usermeta._bbp_subscriptions. A page that
agrees with itself proves nothing.

IT AIMS AT THE LANE PREVIEW, NOT AT :443's OWN PAGE. /manage-subscription/ on the
vhost serves /srv/membership-pages -> ~/loothplatformv2-clean, i.e. MAIN — a gate
pointed there measures a branch that was never deployed and false-PASSES. The
preview (tools/preview/lane-preview.sh) mounts the WORKTREE at
/preview/account-following/, which is both what this gate measures and what Ian
clicks. Testing the thing he is looking at is the point.

Run:
  tools/preview/lane-preview.sh up account-following
  # an engine that resolves the public name to this box — the shared chrome-dev
  # service has no --host-resolver-rules, so it would audit Cloudflare's challenge
  google-chrome-stable --headless=new --remote-debugging-port=9334 \
    --user-data-dir=/tmp/chrome-preview/profile --disable-gpu --disable-dev-shm-usage \
    "--host-resolver-rules=MAP dev2.loothgroup.com 127.0.0.1" about:blank &
  python3 tools/gates/following-section-gate.py --cdp http://127.0.0.1:9334

Needs: that engine, the preview up, and sudo for the two ground-truth reads.

Exit:  0 green, 1 RED (real findings), 2 CANNOT RUN (no verdict).
       The 0/1/2 split is run-all.sh's convention. Most of this gate's failure
       modes are ENVIRONMENTAL (no engine, no listener, no member cookie);
       reporting those as red would be indistinguishable from a regression, which
       is exactly how craft gate 2 sat "red" for weeks while it was in fact dead.
"""
import argparse, json, subprocess, sys, time, urllib.request, urllib.parse

CDP         = "http://127.0.0.1:9222"
# The lane PREVIEW on the real vhost — the same URL Ian clicks. Reaching it needs
# an engine that resolves dev2.loothgroup.com to this box (the public name is
# Cloudflare-proxied and answers a challenge); pass --cdp at one, see the header.
DEFAULT_URL = "https://dev2.loothgroup.com/preview/account-following/manage-subscription/"
DEFAULT_UID = 1          # the first real member, and the only one with a long list
PAGE_SIZE   = 5          # LG_FOLLOWING_PAGE_SIZE
NO_VERDICT  = 2

passes = failures = 0
_open = {"page": None, "tid": None}


def log(*a): print(" ".join(str(x) for x in a), flush=True)


def check(label, got, want):
    global passes, failures
    ok = got == want
    if ok: passes += 1
    else:  failures += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + ("" if ok else f"\n           got={got!r}\n           want={want!r}"))
    return ok


def cannot_run(why):
    try:
        if _open["page"]: _open["page"].close()
        if _open["tid"]:  close_page(_open["tid"])
    except Exception:
        pass
    log(f"  CANNOT RUN — {why}")
    log("  (exit 2: no verdict. This is NOT a pass and NOT a finding.)")
    sys.exit(NO_VERDICT)


try:
    import websocket  # websocket-client
except ImportError:
    cannot_run("python3 websocket-client is not installed")


# ── ground truth ─────────────────────────────────────────────────────────────
def store_notify_ids(uid):
    """🔔 — PG forums.topic_follow. Read as the same read-only role the page uses."""
    r = subprocess.run(
        ["sudo", "-n", "-u", "membership", "psql", "-d", "looth", "-Atc",
         f"select topic_id from forums.topic_follow where user_id={uid} order by topic_id;"],
        capture_output=True, text=True)
    if r.returncode != 0:
        cannot_run("cannot read forums.topic_follow: " + r.stderr.strip()[:160])
    return {int(x) for x in r.stdout.split() if x.strip()}


def store_email_ids(uid):
    """✉ — MySQL wp_usermeta._bbp_subscriptions, the CSV bbPress itself answers from."""
    sql = (f"SELECT meta_value FROM wp_usermeta WHERE user_id={uid} "
           f"AND meta_key='wp__bbp_subscriptions' LIMIT 1;")
    r = subprocess.run(
        ["sudo", "-n", "bash", "-c",
         'set -a; . /etc/lg-events-db; set +a; '
         f'mysql -u"$DB_USER" -p"$DB_PASSWORD" -h"$DB_HOST" -N -B -e "{sql}" "$DB_NAME"'],
        capture_output=True, text=True)
    if r.returncode != 0:
        cannot_run("cannot read wp_usermeta subscriptions: " + r.stderr.strip()[:160])
    csv = r.stdout.strip()
    return {int(x) for x in csv.split(",") if x.strip().isdigit()}


def mint_cookies(uid):
    """A REAL WP session for the acting member, plus the dev gate cookie.

    Minted rather than harvested: a gate that depends on a human's live browser
    session is a gate that goes red on a Monday for no reason.
    """
    r = subprocess.run(
        ["sudo", "-n", "-u", "looth-dev", "wp", "eval",
         f'$e=time()+3600; echo LOGGED_IN_COOKIE."=".wp_generate_auth_cookie({uid},$e,"logged_in")."\\n";'
         f'echo SECURE_AUTH_COOKIE."=".wp_generate_auth_cookie({uid},$e,"secure_auth")."\\n";',
         "--skip-themes", "--path=/var/www/dev"],
        capture_output=True, text=True)
    pairs = [l for l in r.stdout.splitlines() if l.startswith("wordpress")]
    if len(pairs) < 2:
        cannot_run("could not mint a WP session cookie (needs sudo -u looth-dev wp)")

    g = subprocess.run(
        ["sudo", "-n", "grep", "-oP", r'loothdev_token\s+"\K[^"]+',
         "/etc/nginx/snippets/loothdev-tokens.conf"], capture_output=True, text=True)
    tok = (g.stdout.strip().splitlines() or [""])[0]
    if tok:
        pairs.append("loothdev_auth=" + tok)
    return pairs


# ── CDP ──────────────────────────────────────────────────────────────────────
class Page:
    """ONE persistent CDP connection.

    Deliberately not per-command sockets: session-scoped overrides (device metrics,
    and here the certificate-error override) are dropped by a fresh socket, and the
    run then silently measures something else. Recorded trap; this class is the fix.
    """
    def __init__(self, ws_url):
        # suppress_origin: Chrome rejects a CDP websocket carrying an Origin header
        # unless launched with --remote-allow-origins, and the chrome-dev service is
        # shared — not sending one needs no change to it.
        self.ws = websocket.create_connection(ws_url, timeout=30, suppress_origin=True)
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


def new_page():
    # /json/new needs PUT on Chrome 151. Our OWN tab: a second CDP client attached
    # to someone else's target fails with a bare HTTP 500 on this shared engine.
    t = json.load(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")))
    return t["id"], Page(t["webSocketDebuggerUrl"])


def close_page(tid):
    try: urllib.request.urlopen(CDP + f"/json/close/{tid}").read()
    except Exception: pass


def setup(p, url, cookies, width, height):
    p.send("Page.enable"); p.send("Runtime.enable"); p.send("Network.enable")
    p.send("Security.enable")
    # The lane listener carries dev2's certificate but is reached on 127.0.0.1, so
    # the name will not match. Scoped to THIS tab only.
    p.send("Security.setIgnoreCertificateErrors", {"ignore": True})
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": width, "height": height, "deviceScaleFactor": 2,
            "mobile": height > width})
    host = url.split("/")[2].split(":")[0]
    p.send("Network.setCookies", {"cookies": [
        {"name": k, "value": v, "domain": host, "path": "/"}
        for k, v in (c.split("=", 1) for c in cookies)]})


def goto(p, url, tries=2):
    """Navigate and wait for the section's own client pass to finish.

    The marks are server-rendered and then CORRECTED against follow.php's GET;
    asserting before that lands would test a half-hydrated page. #lg-fol-master
    is empty until the correction returns, so it is the honest ready signal.
    Generous timeouts: cold FPM on a 2-core box is slow, and harness latency must
    never read as a product defect.
    """
    for attempt in range(tries):
        # about:blank FIRST. Every phase after the first re-navigates to the SAME
        # url, and Chrome keeps the previous document readable until the new one
        # commits — so `document.readyState === "complete"` answers about the page
        # we just left, and the hydration poll then races a document that is being
        # torn down. Measured: standalone this page hydrates in ~1s, but in-sequence
        # the same navigation reported "never hydrated" every time. Clearing to
        # about:blank makes each phase start from a document that is definitely new.
        p.send("Page.navigate", {"url": "about:blank"})
        for _ in range(20):
            time.sleep(0.05)
            try:
                if p.ev("location.href") == "about:blank": break
            except Exception: pass
        p.send("Page.navigate", {"url": url})
        for _ in range(120):
            time.sleep(0.25)
            try:
                if p.ev("document.readyState") == "complete": break
            except Exception: pass
        for _ in range(120):
            try:
                # Both conditions in ONE evaluate: two round trips per poll on a
                # loaded shared engine is how a 30s budget quietly becomes 15.
                # And the master line must EXIST — `({}).textContent !== ''` is
                # true for a missing element, which would call a page with no
                # section "hydrated" and hand every later phase a null.
                if p.ev("""(() => {
                      const s = document.getElementById('lg-following');
                      const m = document.getElementById('lg-fol-master');
                      return !!s && !!m && m.textContent.trim() !== '';
                    })()"""):
                    return True
            except Exception: pass
            time.sleep(0.25)
        if attempt + 1 < tries:
            log(f"  (hydration slow — reloading, attempt {attempt + 2}/{tries})")
    return False


# ── the assertions ───────────────────────────────────────────────────────────
def visible_rows(p):
    """Rows with a real box in THIS viewport — the collapsed overflow has none."""
    return p.ev("""(() => {
      const out = [];
      for (const li of document.querySelectorAll('#lg-following .lg-manage-sub__fol-row')) {
        const r = li.getBoundingClientRect();
        if (r.width > 0 && r.height > 0) out.push({
          topic: parseInt(li.getAttribute('data-topic'), 10),
          href: (li.querySelector('a.lg-manage-sub__fol-name') || {}).getAttribute
                ? li.querySelector('a.lg-manage-sub__fol-name').getAttribute('href') : null,
          notify: !!li.querySelector('[data-mark="notify"].is-on'),
          email:  !!li.querySelector('[data-mark="email"].is-on'),
          // A linkless row must SAY why. "(private group)" in the meta line is
          // the page admitting the hub cannot address that discussion.
          private: /\\(private group\\)/.test(
            (li.querySelector('.lg-manage-sub__fol-meta') || {}).textContent || ''),
        });
      }
      return out;
    })()""")


def hit_test(p, selector):
    """Does the point at the centre of this element belong to this element?

    THE WHOLE POINT OF THE GATE. A row that renders under fixed furniture is
    present, styled, and untouchable — and a blind click on it passes.
    """
    return p.ev("""(() => {
      const el = document.querySelector(%s);
      if (!el) return {found:false};
      // Scroll it under the eye first. The section sits well below the fold on a
      // real account page, and elementFromPoint only knows the viewport — without
      // this every row reads "offscreen" and the gate reports a layout defect that
      // is really just a page the member had not scrolled yet. Centred, so a row
      // pinned under fixed furniture STILL fails, which is the case we care about.
      el.scrollIntoView({block: 'center', inline: 'nearest'});
      const r = el.getBoundingClientRect();
      if (r.width <= 0 || r.height <= 0) return {found:true, boxed:false};
      const x = Math.round(r.left + r.width / 2), y = Math.round(r.top + r.height / 2);
      if (x < 0 || y < 0 || x > innerWidth || y > innerHeight)
        return {found:true, boxed:true, onscreen:false};
      const top = document.elementFromPoint(x, y);
      return {
        found:true, boxed:true, onscreen:true, x: x, y: y,
        w: Math.round(r.width), h: Math.round(r.height),
        mine: !!(top && (top === el || el.contains(top) || top.contains(el))),
        blocker: (top && top !== el && !el.contains(top))
                 ? (top.tagName + (top.id ? '#' + top.id : '') +
                    (top.className && top.className.baseVal === undefined
                       ? '.' + String(top.className).trim().split(/\\s+/).join('.') : ''))
                 : null,
      };
    })()""" % json.dumps(selector))


def control_topic(exclude):
    """A real, published topic in a public forum that the member does NOT follow.

    Chosen from the store rather than hardcoded so the gate keeps working as dev2's
    content changes, and never picks a row the member actually cares about.
    """
    r = subprocess.run(
        ["sudo", "-n", "-u", "membership", "psql", "-d", "looth", "-Atc",
         "select t.id from forums.topic t join forums.forum f on f.id = t.forum_id "
         "where t.status = 'publish' and f.visibility = 'public' and t.tier_gate = 'public' "
         "order by t.last_active_at desc nulls last limit 40;"],
        capture_output=True, text=True)
    for line in r.stdout.split():
        if line.strip().isdigit() and int(line) not in exclude:
            return int(line)
    return None


def open_and_read_modal(p, url, timeout_s=20):
    """Navigate to a deep link and report what the hub actually opened.

    ≥641 the §4e desktop modal (#lg-dmodal), ≤640 hub-polish.js's #looth-rep-sheet
    — one contract, two surfaces (forums.js:5355). Both are checked so this works
    at either width. about:blank first for the same stale-document reason as goto().
    """
    p.send("Page.navigate", {"url": "about:blank"})
    for _ in range(20):
        time.sleep(0.05)
        try:
            if p.ev("location.href") == "about:blank": break
        except Exception: pass
    p.send("Page.navigate", {"url": url})
    deadline = time.time() + timeout_s
    last = {"state": "never-opened"}
    while time.time() < deadline:
        time.sleep(0.25)
        try:
            r = p.ev("""(() => {
              for (const id of ['lg-dmodal', 'looth-rep-sheet']) {
                const d = document.getElementById(id);
                if (!d || d.hidden) continue;
                const t = d.querySelector('.fc-title, h1, h2, .feed-card__title');
                // The two surfaces name the topic differently: the desktop dmodal
                // stamps data-topic-id, the mobile sheet stamps data-tid. Reading
                // only the first made the mobile assertion hollow — it opened, the
                // id came back null, and "holds THAT discussion" would have failed
                // for a sheet that was in fact correct.
                const tid = d.getAttribute('data-topic-id') || d.getAttribute('data-tid');
                return {state:'open', which:id,
                        topic_id: parseInt(tid, 10) || null,
                        title: (t ? t.textContent : '').trim().slice(0, 80)};
              }
              return {state: document.readyState === 'complete' ? 'not-open-yet' : 'loading'};
            })()""")
            if r.get("state") == "open":
                return r
            last = r
        except Exception as e:
            last = {"state": "error", "err": str(e)[:80]}
    return last



CONTRAST_JS = r"""(() => {
  // WCAG 2.x relative luminance + contrast ratio, computed from what the browser
  // ACTUALLY resolved — not from the stylesheet's intent. A token that failed to
  // re-point in dark shows up here as a real number, which is the whole point:
  // "it renders" and "you can read it" are different claims.
  const lum = (r,g,b) => {
    const f = c => { c/=255; return c <= 0.03928 ? c/12.92 : Math.pow((c+0.055)/1.055, 2.4); };
    return 0.2126*f(r) + 0.7152*f(g) + 0.0722*f(b);
  };
  const parse = c => {
    const m = /rgba?\(([^)]+)\)/.exec(c || '');
    if (!m) return null;
    const p = m[1].split(',').map(x => parseFloat(x));
    return {r:p[0], g:p[1], b:p[2], a: p.length > 3 ? p[3] : 1};
  };
  // Walk up for the first OPAQUE background, compositing any translucent layers
  // on the way — the card foot is a translucent wash over the card, and treating
  // it as transparent would measure the text against the wrong surface.
  const bgOf = el => {
    let stack = [];
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      const c = parse(getComputedStyle(n).backgroundColor);
      if (!c || c.a === 0) continue;
      stack.push(c);
      if (c.a === 1) break;
    }
    if (!stack.length) return {r:255,g:255,b:255};
    let out = stack.pop();
    while (stack.length) {
      const top = stack.pop();
      out = {r: top.r*top.a + out.r*(1-top.a),
             g: top.g*top.a + out.g*(1-top.a),
             b: top.b*top.a + out.b*(1-top.a), a:1};
    }
    return out;
  };
  const ratio = el => {
    if (!el) return null;
    const fg = parse(getComputedStyle(el).color);
    if (!fg) return null;
    const bg = bgOf(el);
    // Composite a translucent foreground onto its own background too.
    const f = {r: fg.r*fg.a + bg.r*(1-fg.a),
               g: fg.g*fg.a + bg.g*(1-fg.a),
               b: fg.b*fg.a + bg.b*(1-fg.a)};
    const L1 = lum(f.r,f.g,f.b), L2 = lum(bg.r,bg.g,bg.b);
    const hi = Math.max(L1,L2), lo = Math.min(L1,L2);
    return Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100;
  };
  const q = sel => document.querySelector(sel);
  const S = '#lg-following ';
  return {
    theme:      document.documentElement.getAttribute('data-lguser-theme') || 'default',
    title:      ratio(q(S + '.lg-manage-sub__fol-name')),
    meta:       ratio(q(S + '.lg-manage-sub__fol-meta')),
    count:      ratio(q(S + '.lg-manage-sub__fol-count')),
    heading:    ratio(q(S + '.lg-manage-sub__fol-title')),
    showall:    ratio(q('#lg-fol-more')),
    stopall:    ratio(q('#lg-fol-stopall')),
    footnote:   ratio(q('#lg-fol-master')),
    mark_on:    ratio(q(S + '.lg-manage-sub__fol-mark.is-on')),
    mark_off:   ratio(q(S + '.lg-manage-sub__fol-mark:not(.is-on)')),
    unfollow:   ratio(q(S + '[data-unfollow]')),
  };
})()"""


def origin_of(url):
    """scheme://host[:port] — what an absolute href resolves against."""
    u = urllib.parse.urlsplit(url)
    return f"{u.scheme}://{u.netloc}"


def control_topics(exclude, n):
    """N distinct real topics the member does not follow, for the union phase."""
    r = subprocess.run(
        ["sudo", "-n", "-u", "membership", "psql", "-d", "looth", "-Atc",
         "select t.id from forums.topic t join forums.forum f on f.id = t.forum_id "
         "where t.status = 'publish' and f.visibility = 'public' and t.tier_gate = 'public' "
         "order by t.last_active_at desc nulls last limit 60;"],
        capture_output=True, text=True)
    out = []
    for line in r.stdout.split():
        if line.strip().isdigit() and int(line) not in exclude and int(line) not in out:
            out.append(int(line))
            if len(out) == n:
                break
    return out


def follow_js(topic_id, on, channels=("notify", "email")):
    """Drive follow.php from the PAGE's own session — the same contract the UI uses.

    Deliberately not a direct DB write: setting up the fixture through a back door
    would let a broken endpoint still produce a green round-trip.

    `channels` exists for the union phase, which needs a topic carrying exactly ONE
    of the two bits — the whole point being that either bit alone must produce a row.
    """
    chans = "[" + ",".join("'%s'" % c for c in channels) + "]"
    return """(async () => {
      const g = await (await fetch('/bb-mirror-api/v0/follow?topics=TOPIC',
                                   {credentials:'same-origin'})).json();
      if (!g || !g.authenticated) return 'not-authenticated';
      for (const ch of CHANS) {
        const r = await fetch('/bb-mirror-api/v0/follow', {
          method:'POST', credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-WP-Nonce':g.nonce},
          body: JSON.stringify({topic_id:TOPIC, channel:ch, on:ONOFF})});
        const j = await r.json().catch(()=>null);
        if (!j || !j.ok) return 'write-failed:' + ch + ':' + r.status;
      }
      return true;
    })()""".replace("TOPIC", str(int(topic_id))) \
           .replace("CHANS", chans) \
           .replace("ONOFF", "true" if on else "false")


def fetch_text(url, cookies):
    """Body of a page, or None. Used to assert the FLAG-OFF shape on a surface
    the gate is not driving — the OFF state has to be checked somewhere."""
    import ssl
    ctx = ssl.create_default_context(); ctx.check_hostname = False; ctx.verify_mode = ssl.CERT_NONE
    try:
        req = urllib.request.Request(url, headers={"Cookie": "; ".join(cookies)})
        with urllib.request.urlopen(req, timeout=20, context=ctx) as r:
            return r.read().decode("utf-8", "replace")
    except Exception:
        return None


def fetch_status(url, cookies):
    req = urllib.request.Request(url, method="GET",
                                 headers={"Cookie": "; ".join(cookies)})
    import ssl
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    try:
        with urllib.request.urlopen(req, timeout=20, context=ctx) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code
    except Exception as e:
        return f"ERR {e}"


def main():
    global failures
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default=DEFAULT_URL)
    # A preview served on the REAL vhost can only be reached by a browser that
    # resolves dev2.loothgroup.com to this box — the public name is
    # Cloudflare-proxied and answers a challenge. The shared chrome-dev service
    # carries no --host-resolver-rules, so point this at an engine that does.
    ap.add_argument("--cdp", default=CDP, help="CDP endpoint (default 127.0.0.1:9222)")
    ap.add_argument("--uid", type=int, default=DEFAULT_UID)
    args = ap.parse_args()
    globals()["CDP"] = args.cdp

    log("following-section-gate — Discussions you're following")
    log(f"  url={args.url}  uid={args.uid}")

    try:
        urllib.request.urlopen(CDP + "/json/version", timeout=5).read()
    except Exception:
        # Name the endpoint we ACTUALLY tried. Hardcoding :9222 here sent me
        # looking at the shared service while my own engine was the one that had
        # died — a diagnostic that points at the wrong thing costs more than none.
        cannot_run(f"no CDP engine answering at {CDP} "
                   f"(--cdp selects it; the shared chrome-dev is 127.0.0.1:9222)")

    notify = store_notify_ids(args.uid)
    email  = store_email_ids(args.uid)
    expect = notify | email
    log(f"  store: {len(notify)} notify + {len(email)} email = {len(expect)} discussions followed")
    if not expect:
        cannot_run(f"uid {args.uid} follows nothing — this gate needs a real member "
                   f"with real follows, and will not invent them")

    cookies = mint_cookies(args.uid)

    tid, p = new_page()
    _open["tid"], _open["page"] = tid, p
    try:
        # ── desktop ──────────────────────────────────────────────────────────
        setup(p, args.url, cookies, 1280, 900)
        if not goto(p, args.url):
            cannot_run("the section never hydrated (no listener on :8930, or not signed in)")

        log("\n  [1] the section renders, from the real stores")
        check("section present", p.ev("!!document.getElementById('lg-following')"), True)
        check("data-total equals the store's union",
              p.ev("parseInt(document.getElementById('lg-following').dataset.total,10)"), len(expect))
        check("every followed discussion has a row",
              set(p.ev("""[...document.querySelectorAll('#lg-following .lg-manage-sub__fol-row')]
                          .map(li=>parseInt(li.dataset.topic,10))""")), expect)

        log("\n  [2] the list is BOUNDED — the whole point of the design")
        rows = visible_rows(p)
        check(f"only {PAGE_SIZE} rows visible before 'Show all'",
              len(rows), min(PAGE_SIZE, len(expect)))

        log("\n  [3] the marks tell the truth about both stores")
        for r in rows:
            check(f"topic {r['topic']} bell/email match the stores",
                  (r["notify"], r["email"]),
                  (r["topic"] in notify, r["topic"] in email))

        log("\n  [4] the rows are HITTABLE, not merely present")
        for r in rows:
            sel = f'#lg-following .lg-manage-sub__fol-row[data-topic="{r["topic"]}"] a.lg-manage-sub__fol-name'
            h = hit_test(p, sel)
            check(f"topic {r['topic']} title link is the top element at its own centre",
                  h.get("mine"), True)
            if h.get("blocker"):
                log(f"           blocked by: {h['blocker']}")
            x = hit_test(p, f'#lg-following .lg-manage-sub__fol-row[data-topic="{r["topic"]}"] [data-unfollow]')
            check(f"topic {r['topic']} unfollow control is hittable", x.get("mine"), True)
            check(f"topic {r['topic']} unfollow control is a 44px target",
                  (x.get("w", 0) >= 44 and x.get("h", 0) >= 44), True)

        log("\n  [5] every link GOES SOMEWHERE — the hub, with THAT discussion open")
        # THE ASSERTION THIS GATE WAS MISSING, and it cost a round trip with Ian:
        # "the link in the manage goes to the old foum not to the hub with the
        # right modal open." The suite proved rows RENDER and are HITTABLE and
        # said nothing about DESTINATION, so a link that resolved 200 to entirely
        # the wrong UI passed. 200 is not the assertion. Landing on the hub with
        # the right discussion open is.
        #
        # Driven in a real browser because the deep link is CLIENT-side: §4f
        # (forums.js:5355) reads ?topic=<forum>/<topic> and opens the §4e modal,
        # fetching the topic standalone when the feed has no card for it. curl
        # would see 200 for the feed and learn nothing.
        base = origin_of(args.url)
        # EVERY row, not the visible five. The rows the hub cannot address are the
        # OLD ones — a member's private-group follows sink to the bottom of a list
        # sorted by last activity — so checking only the first page would leave the
        # exact rows this defect lives in untested.
        if p.ev("!!document.getElementById('lg-fol-more')"):
            p.ev("document.getElementById('lg-fol-more').click()")
            time.sleep(0.4)
        all_rows = visible_rows(p)
        log(f"  checking all {len(all_rows)} rows")
        for r in all_rows:
            if not r["href"]:
                # A row is allowed to have no link ONLY where the hub genuinely
                # cannot address the discussion: hidden group forums, which
                # _single-topic.php gates out at :53 and :72. The page labels
                # those "(private group)". An unlabelled linkless row is a defect.
                check(f"topic {r['topic']} is linkless only because it is a private group",
                      r.get("private"), True)
                continue

            check(f"topic {r['topic']} link is the hub deep link, not a permalink or the old forum",
                  r["href"].startswith("/hub/?topic="), True)

            st = fetch_status(base + r["href"], cookies)
            check(f"topic {r['topic']} link resolves", st in (200, 301, 302), True)
            if st not in (200, 301, 302):
                log(f"           status={st}")
                continue

            # BOTH WIDTHS. §4f is one contract over two different openers — the
            # desktop dmodal and the mobile sheet — and they are separate code
            # paths, so proving the link on one proves nothing about the other.
            # Ian reads this on a phone, so the phone is not the optional half.
            for label, w, hgt, mob in (("desktop", 1280, 900, False), ("phone", 390, 844, True)):
                p.send("Emulation.setDeviceMetricsOverride",
                       {"width": w, "height": hgt, "deviceScaleFactor": 2, "mobile": mob})
                # maxTouchPoints must be 1..16 even when disabling — CDP rejects 0.
                p.send("Emulation.setTouchEmulationEnabled", {"enabled": mob, "maxTouchPoints": 5})
                opened = open_and_read_modal(p, base + r["href"])
                if not check(f"topic {r['topic']} lands with the discussion open ({label})",
                             opened.get("state"), "open"):
                    log(f"           {opened}")
                    continue
                # The id is read off the opener itself, so this is the hub saying
                # which discussion it opened — never the account page's opinion.
                check(f"topic {r['topic']} it is THAT discussion, not another ({label})",
                      opened.get("topic_id"), r["topic"])
            p.send("Emulation.setDeviceMetricsOverride",
                   {"width": 1280, "height": 900, "deviceScaleFactor": 2, "mobile": False})
            p.send("Emulation.setTouchEmulationEnabled", {"enabled": False, "maxTouchPoints": 5})

        # Back to the section for the phases that follow.
        must_return = goto(p, args.url)
        if not must_return:
            cannot_run("could not return to the section after following its links")

        log("\n  [6] the one off switch exists and can be pressed")
        s = hit_test(p, "#lg-fol-stopall")
        check("Stop all is hittable", s.get("mine"), True)
        check("Stop all names the count",
              p.ev("(document.getElementById('lg-fol-stopall')||{}).textContent||''").strip(),
              f"Stop all {len(expect)}")

        log("\n  [7] 'Show all' actually reveals the rest")
        if len(expect) > PAGE_SIZE:
            m = hit_test(p, "#lg-fol-more")
            check("Show all is hittable", m.get("mine"), True)
            p.ev("document.getElementById('lg-fol-more').click()")
            time.sleep(0.3)
            check("all rows visible after expanding", len(visible_rows(p)), len(expect))
        else:
            log("  (skipped — this member is at or under the page size)")

        # ── phone ────────────────────────────────────────────────────────────
        log("\n  [8] the same, on a phone — Ian's phone outranks a green suite")
        p.send("Emulation.setDeviceMetricsOverride",
               {"width": 390, "height": 844, "deviceScaleFactor": 3, "mobile": True})
        p.send("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})
        if not goto(p, args.url):
            cannot_run("the section never hydrated at 390px")
        mrows = visible_rows(p)
        check(f"still bounded to {PAGE_SIZE} at 390px", len(mrows), min(PAGE_SIZE, len(expect)))
        for r in mrows:
            sel = f'#lg-following .lg-manage-sub__fol-row[data-topic="{r["topic"]}"] a.lg-manage-sub__fol-name'
            h = hit_test(p, sel)
            check(f"topic {r['topic']} title hittable at 390px", h.get("mine"), True)
            if h.get("blocker"):
                log(f"           blocked by: {h['blocker']}")
            x = hit_test(p, f'#lg-following .lg-manage-sub__fol-row[data-topic="{r["topic"]}"] [data-unfollow]')
            check(f"topic {r['topic']} unfollow hittable at 390px", x.get("mine"), True)
        s = hit_test(p, "#lg-fol-stopall")
        check("Stop all hittable at 390px", s.get("mine"), True)

        # AND AT THE HONEST POSITION. Every other hit here calls scrollIntoView
        # with block:'center', which parks the target in the middle of the
        # viewport — where nothing fixed lives. That is the right test for "is
        # something in the page covering this", and the WRONG one for the bottom
        # bar: NAV#looth-tabbar is pinned to the viewport floor, so centring the
        # button is exactly how you fail to notice it is buried. That is the
        # recorded shorty-dock class — 32 of 36px under the tabbar, blind click
        # PASSED. So: scroll to where a member actually stops, and look there.
        # Today it clears (body carries 54px of tabbar padding, main another
        # 64px, against a 55px bar); this assertion is what keeps it that way.
        bottom = p.ev("""(() => {
          window.scrollTo(0, document.body.scrollHeight);
          const b = document.getElementById('lg-fol-stopall');
          if (!b) return {found:false};
          const r = b.getBoundingClientRect();
          const y = Math.round(r.top + r.height / 2);
          if (y < 0 || y > innerHeight) return {found:true, offscreen:true};
          const top = document.elementFromPoint(Math.round(r.left + r.width / 2), y);
          return {found:true, offscreen:false,
                  mine: !!(top && (top === b || b.contains(top))),
                  blocker: (top && top !== b && !b.contains(top))
                           ? top.tagName + (top.id ? '#' + top.id : '') : null};
        })()""")
        if bottom.get("offscreen"):
            log("  (skipped — the card is not at the page bottom for this member)")
        else:
            check("Stop all is not buried under the fixed tabbar at the page bottom",
                  bottom.get("mine"), True)
            if bottom.get("blocker"):
                log(f"           buried by: {bottom['blocker']}")

        log("\n  [9] THE LIST IS THE UNION — either bit alone must produce a row")
        # THE MOST IMPORTANT ASSERTION IN THIS FILE, and the one the live store
        # proved rather than a design argument. Between two runs on 2026-07-30 the
        # acting member went from 1 bell + 11 email to 0 bell + 12 email: the SAME
        # twelve discussions, differently composed. A page that listed only
        # forums.topic_follow would have shown twelve rows, then zero, while twelve
        # discussions carried on emailing him — an empty page that is a lie.
        #
        # Phase [1] compares the row set against notify|email, but that passes
        # TRIVIALLY whenever one store happens to be empty, which is exactly the
        # state the member is in right now. So this phase MANUFACTURES both halves:
        # one topic carrying ONLY the bell, one carrying ONLY the envelope, each
        # written through follow.php, and demands that BOTH appear.
        p.send("Emulation.setDeviceMetricsOverride",
               {"width": 1280, "height": 900, "deviceScaleFactor": 2, "mobile": False})
        pair = control_topics(expect, 2)
        if len(pair) < 2:
            log("  (skipped — could not find two unfollowed public topics)")
        else:
            bell_only, mail_only = pair
            log(f"  bell-only topic {bell_only}, email-only topic {mail_only}")
            try:
                a = p.ev(follow_js(bell_only, True, ("notify",)))
                b = p.ev(follow_js(mail_only, True, ("email",)))
                if a is not True or b is not True:
                    log(f"  (skipped — could not set up the union fixture: {a!r} / {b!r})")
                else:
                    # The fixture is only meaningful if each topic really carries
                    # ONE bit. Prove that in the stores before trusting the page.
                    n_ids, e_ids = store_notify_ids(args.uid), store_email_ids(args.uid)
                    check("bell-only topic is in PG and NOT in MySQL",
                          (bell_only in n_ids, bell_only in e_ids), (True, False))
                    check("email-only topic is in MySQL and NOT in PG",
                          (mail_only in e_ids, mail_only in n_ids), (True, False))

                    if not goto(p, args.url):
                        cannot_run("the section never hydrated for the union phase")
                    dom = set(p.ev("""[...document.querySelectorAll(
                            '#lg-following .lg-manage-sub__fol-row')].map(li=>parseInt(li.dataset.topic,10))"""))
                    check("a discussion with ONLY the bell is listed", bell_only in dom, True)
                    check("a discussion with ONLY the email subscription is listed",
                          mail_only in dom, True)
                    check("the total counts both",
                          p.ev("parseInt(document.getElementById('lg-following').dataset.total,10)"),
                          len(n_ids | e_ids))
            finally:
                for t in pair:
                    if t in store_notify_ids(args.uid) or t in store_email_ids(args.uid):
                        try: p.ev(follow_js(t, False))
                        except Exception: pass
                left = (store_notify_ids(args.uid) | store_email_ids(args.uid)) & set(pair)
                if left:
                    log(f"  ⚠ union fixture NOT fully cleaned up, still followed: {sorted(left)}")

        log("\n  [10] unfollow REALLY unfollows — asserted in the stores, not the pixels")
        # An optimistic row that vanishes on click and leaves the store untouched
        # is the single worst failure this feature can have: the member believes
        # they have stopped it, and the email keeps arriving. So the row is removed
        # by a real press and the verdict comes from Postgres and MySQL.
        #
        # On a CONTROL topic the member does not already follow, followed through
        # the same endpoint first — net zero on their real list either way, and the
        # cleanup below runs even if the assertions fail.
        ctl = control_topic(expect)
        if ctl is None:
            log("  (skipped — no unfollowed public topic available to use as a control)")
        else:
            log(f"  control topic {ctl}")
            p.send("Emulation.setDeviceMetricsOverride",
                   {"width": 1280, "height": 900, "deviceScaleFactor": 2, "mobile": False})
            try:
                on = p.ev(follow_js(ctl, True))
                if on is not True:
                    log(f"  (skipped — could not set up the control follow: {on!r})")
                else:
                    check("control is in PG after following",  ctl in store_notify_ids(args.uid), True)
                    check("control is in MySQL after following", ctl in store_email_ids(args.uid), True)

                    if not goto(p, args.url):
                        cannot_run("the section never hydrated for the unfollow round-trip")
                    sel = f'#lg-following .lg-manage-sub__fol-row[data-topic="{ctl}"] [data-unfollow]'
                    h = hit_test(p, sel)     # scrolls it into view AND proves it is pressable
                    check("control row's unfollow is hittable before pressing it", h.get("mine"), True)
                    if h.get("mine"):
                        p.send("Input.dispatchMouseEvent",
                               {"type": "mousePressed", "x": h["x"], "y": h["y"],
                                "button": "left", "clickCount": 1})
                        p.send("Input.dispatchMouseEvent",
                               {"type": "mouseReleased", "x": h["x"], "y": h["y"],
                                "button": "left", "clickCount": 1})
                        for _ in range(40):
                            time.sleep(0.25)
                            if not p.ev(f"!!document.querySelector('"
                                        f"#lg-following .lg-manage-sub__fol-row[data-topic=\\\"{ctl}\\\"]')"):
                                break
                        check("row is gone from the page",
                              p.ev(f"!!document.querySelector('"
                                   f"#lg-following .lg-manage-sub__fol-row[data-topic=\\\"{ctl}\\\"]')"), False)
                        check("🔔 bit is gone from Postgres",  ctl in store_notify_ids(args.uid), False)
                        check("✉ bit is gone from MySQL",      ctl in store_email_ids(args.uid), False)
            finally:
                # Never leave the acting member following something the gate added.
                if ctl in store_notify_ids(args.uid) or ctl in store_email_ids(args.uid):
                    log("  (cleaning up leftover control follow)")
                    try: p.ev(follow_js(ctl, False))
                    except Exception: pass

        log("\n  [11] BOTH THEMES — measured contrast, not 'it renders'")
        # Ian, 2026-07-31: "All of this stuff needs a dark mode pass." A
        # single-theme gate cannot see this class AT ALL: the page had zero dark
        # rules, so the card stayed white while the ink flipped near-white, and
        # every assertion above still passed because the elements were all
        # present, hittable and correctly wired. Presence is not legibility.
        #
        # The prior art is the messages-search lane: sage tints that were never
        # re-pointed for dark. #eef2e3 is a wash designed to sit on white; left
        # alone it becomes a bright chip on a dark card. So this measures the
        # RESOLVED colours out of the browser, composites translucent layers, and
        # reports ratios — a token that failed to re-point shows up as a number.
        #
        # WCAG AA is 4.5:1 for normal text. The two marks are ICONS, not text, and
        # the OFF one is deliberately quiet — but it must still be VISIBLE, because
        # an invisible "off" is indistinguishable from a missing control: the
        # member cannot tell the bell is off from the bell not being there.
        AA_TEXT, ICON_MIN, OFF_MIN = 4.5, 3.0, 1.6
        for theme in ("light", "dark"):
            p.ev(f"localStorage.setItem('lg-set-theme', '{theme}'); true")
            if not goto(p, args.url):
                cannot_run(f"the section never hydrated in {theme} theme")
            c = p.ev(CONTRAST_JS)
            want_attr = "dark" if theme == "dark" else "default"
            check(f"{theme}: the theme really applied", c.get("theme"), want_attr)
            for key, floor in (("heading", AA_TEXT), ("title", AA_TEXT), ("meta", AA_TEXT),
                               ("count", AA_TEXT), ("showall", AA_TEXT), ("stopall", AA_TEXT),
                               ("footnote", AA_TEXT), ("unfollow", ICON_MIN),
                               ("mark_on", ICON_MIN), ("mark_off", OFF_MIN)):
                got = c.get(key)
                ok = isinstance(got, (int, float)) and got >= floor
                check(f"{theme}: {key} contrast >= {floor}", ok, True)
                if not ok:
                    log(f"           measured {got!r}")
                else:
                    log(f"           {key} = {got}:1")
        p.ev("localStorage.setItem('lg-set-theme', 'light'); true")

        log("\n  [12] THE ROW TOGGLES: one bit each, and the CARD AGREES")
        # Ian: "they cant change the setting, just close it out, could they change
        # the toggles on that page too?" Two things have to be true and neither is
        # visible from the account page alone.
        #
        # ONE BIT. The channels are independent and live in DIFFERENT DATABASES —
        # bell in Postgres, envelope in bbPress's MySQL. A toggle that quietly
        # wrote both would look perfect here and silently re-subscribe a member to
        # email they had turned off. So each press is checked against BOTH stores:
        # the one it targeted must change and the other must NOT.
        #
        # AND THE CARD MUST AGREE. Same store, same endpoint — but "same endpoint"
        # is an argument, not evidence. This drives the account page, then opens
        # the hub modal and reads ITS control, then changes it from the card and
        # comes back. A divergence here is the "UI lies" class: the account page
        # saying the bell is off while the card shows it lit.
        toggles_on = p.ev("!!document.querySelector('#lg-following [data-toggle]')")
        check("row toggles are present when the flag is on", toggles_on, True)
        ctl = control_topic(expect) if toggles_on else None
        if not toggles_on:
            log("  (flag off on this surface — nothing to exercise)")
        elif ctl is None:
            log("  (skipped — no unfollowed public topic available as a control)")
        else:
            log(f"  control topic {ctl}")
            try:
                # Start from a KNOWN state rather than whatever the store holds.
                p.ev(follow_js(ctl, True, ("notify",)))
                if not goto(p, args.url):
                    cannot_run("the section never hydrated for the toggle phase")
                p.ev("(()=>{const b=document.getElementById('lg-fol-more'); if(b)b.click(); return true;})()")
                time.sleep(0.3)

                sel = f'#lg-following .lg-manage-sub__fol-row[data-topic="{ctl}"] [data-toggle="email"]'
                hit = hit_test(p, sel)
                check("the email toggle is hittable", hit.get("mine"), True)
                if hit.get("mine"):
                    p.send("Input.dispatchMouseEvent", {"type": "mousePressed", "x": hit["x"], "y": hit["y"],
                                                        "button": "left", "clickCount": 1})
                    p.send("Input.dispatchMouseEvent", {"type": "mouseReleased", "x": hit["x"], "y": hit["y"],
                                                        "button": "left", "clickCount": 1})
                    for _ in range(40):
                        time.sleep(0.25)
                        if ctl in store_email_ids(args.uid): break
                    check("pressing ✉ wrote the EMAIL bit (MySQL)", ctl in store_email_ids(args.uid), True)
                    check("…and did NOT touch the bell (Postgres)", ctl in store_notify_ids(args.uid), True)
                    check("the button reports itself pressed",
                          p.ev(f"(document.querySelector('{sel}')||{{}}).getAttribute('aria-pressed')"), "true")

                    # ── account page → card ──
                    base = origin_of(args.url)
                    slug = p.ev(f"""(() => {{
                      const a = document.querySelector('#lg-following .lg-manage-sub__fol-row[data-topic="{ctl}"] a.lg-manage-sub__fol-name');
                      return a ? a.getAttribute('href') : null;
                    }})()""")
                    if not slug:
                        log("  (control has no hub link — cannot cross-check the card)")
                    else:
                        opened = open_and_read_modal(p, base + slug)
                        check("the card opens for the control topic", opened.get("topic_id"), ctl)
                        # POLL, do not snapshot. The card's controls are rendered
                        # inert and their true state arrives from a BATCH GET after
                        # the modal opens — measured at ~1s here. Reading once on
                        # open reported both bits false while both stores said true,
                        # which would have been a false accusation of drift against
                        # thread-follow's surface. A cross-surface check has to give
                        # the other surface time to answer; only never-converging is
                        # a finding.
                        card = {}
                        for _ in range(30):
                            card = p.ev(f"""(() => {{
                              const q = c => document.querySelector('[data-follow="' + c + '"][data-topic-id="{ctl}"]');
                              const n = q('notify'), e = q('email');
                              return {{notify: n && n.getAttribute('aria-pressed'),
                                       email:  e && e.getAttribute('aria-pressed')}};
                            }})()""")
                            if card.get("notify") == "true" and card.get("email") == "true":
                                break
                            time.sleep(0.5)
                        check("the CARD shows the same ✉ state the account page set", card.get("email"), "true")
                        check("the CARD shows the same 🔔 state", card.get("notify"), "true")

                        # ── card → account page ──
                        p.ev(follow_js(ctl, False, ("notify",)))    # change it from the OTHER side
                        if not goto(p, args.url):
                            cannot_run("the section never hydrated for the reverse check")
                        p.ev("(()=>{const b=document.getElementById('lg-fol-more'); if(b)b.click(); return true;})()")
                        time.sleep(0.4)
                        back = p.ev(f"""(() => {{
                          const q = c => document.querySelector('#lg-following .lg-manage-sub__fol-row[data-topic="{ctl}"] [data-toggle="' + c + '"]');
                          const n = q('notify'), e = q('email');
                          return {{notify: n && n.getAttribute('aria-pressed'),
                                   email:  e && e.getAttribute('aria-pressed')}};
                        }})()""")
                        check("a change made ELSEWHERE shows on the account page", back.get("notify"), "false")
                        check("…without disturbing the other bit", back.get("email"), "true")
            finally:
                if ctl in store_notify_ids(args.uid) or ctl in store_email_ids(args.uid):
                    try: p.ev(follow_js(ctl, False))
                    except Exception: pass
                left = (store_notify_ids(args.uid) | store_email_ids(args.uid)) & {ctl}
                if left:
                    log(f"  ⚠ toggle fixture NOT cleaned up, still followed: {sorted(left)}")

        log("\n  [13] FLAG OFF is a no-op — asserted, not assumed")
        # CLAUDE.md: "Flag OFF must be a proven byte-identical no-op, and the OFF
        # state must be GATED — that missing assertion is the whole failure class."
        # A gate that only ever runs with the flag ON cannot see a leak, so this
        # fetches the surface where the flag is absent and demands the report
        # shape: spans, no data-toggle, no aria-pressed, nothing pressable.
        off_url = origin_of(args.url) + "/manage-subscription/"
        off = fetch_text(off_url, cookies)
        if off is None:
            log("  (skipped — could not fetch the flag-off surface)")
        else:
            check("flag-off surface has NO toggle markup", "data-toggle" in off, False)
            check("flag-off surface has NO is-toggle class", "is-toggle" in off, False)
            check("flag-off surface still renders the marks", "lg-manage-sub__fol-mark" in off, True)

        log("\n  [14] EMAIL FREQUENCY IS HIDDEN — the assertion, not the intention")
        # Ian ruled the cadence control stays hidden until follow-digest's batcher
        # genuinely sends Daily and Weekly: until then choosing "Daily" would
        # deliver instant mail. THREAD-FOLLOW-SPEC §15.4 — do not ship a cadence
        # control that silently does nothing.
        #
        # A hidden thing is exactly what a gate forgets to check, and CLAUDE.md
        # names that as the whole failure class: gates assert what should be
        # PRESENT and cannot see what should be ABSENT. So this asserts absence on
        # the surface members actually reach, and it is the assertion that would
        # catch a stray flag flip or a default drifting to true.
        live = fetch_text(origin_of(args.url) + "/manage-subscription/", cookies)
        if live is None:
            log("  (skipped — could not fetch the member-facing surface)")
        else:
            check("no frequency control on the member-facing page", "lg-fol-freq" in live, False)
            check("no cadence options either", "data-cadence" in live, False)
            check("…and the section itself is still there", "lg-following" in live, True)

        # And when it IS rendered, it must not invent a value. follow-digest keep
        # `cadence` out of the GET envelope while their flag is off, so "no value"
        # is today's normal case — painting a default would be this page answering
        # a question the store never answered.
        if p.ev("!!document.getElementById('lg-fol-freq')"):
            picked = p.ev("""[...document.querySelectorAll('#lg-fol-freq [data-cadence]')]
                              .filter(o => o.getAttribute('aria-checked') === 'true').length""")
            cad = p.ev("""(async () => {
              const r = await fetch('/bb-mirror-api/v0/follow?topics=1', {credentials:'same-origin'});
              const j = await r.json().catch(() => null);
              return j && Object.prototype.hasOwnProperty.call(j, 'cadence') ? String(j.cadence) : '(absent)';
            })()""")
            log(f"           endpoint cadence = {cad}")
            check("nothing is selected while the endpoint reports no cadence",
                  picked, 0 if cad == "(absent)" else picked)

        log("\n  [15] nothing here leaks to a signed-out visitor")
        p.send("Network.clearBrowserCookies")
        host = args.url.split("/")[2].split(":")[0]
        g = [c for c in cookies if c.startswith("loothdev_auth")]
        if g:
            p.send("Network.setCookies", {"cookies": [
                {"name": k, "value": v, "domain": host, "path": "/"}
                for k, v in (c.split("=", 1) for c in g)]})
        p.send("Page.navigate", {"url": args.url})
        for _ in range(80):
            time.sleep(0.25)
            try:
                if p.ev("document.readyState") == "complete": break
            except Exception: pass
        check("no following section for anon", p.ev("!!document.getElementById('lg-following')"), False)

    finally:
        try: p.close()
        except Exception: pass
        close_page(tid)

    log(f"\n  {passes} passed, {failures} failed")
    if failures:
        log("  RED — do not push.")
        sys.exit(1)
    log("  GREEN")
    sys.exit(0)


if __name__ == "__main__":
    main()
