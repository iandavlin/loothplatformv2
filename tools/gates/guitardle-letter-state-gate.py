#!/usr/bin/env python3
"""
GATE 57 — a guessed letter is a HIT or a MISS, and a resumed board PAINTS.

KEEPER GATE NUMBER: 56 — requested 2026-08-16. Enumerated across every branch
before asking (50-55 taken); lanes never mint. Do not renumber without asking.

Ian, playing on dev2 2026-08-16: "On desktop it's keeping track of letters that
are in the puzzle, but not the guesses that were misses. The letter only stays
lit for a correct letter." Then, cross-device: "refreshing the page on dt lights
all letters, but the correct letter just selected from mobile isn't there, or
the correct letter previously selected from DT."

── THE INVARIANT ────────────────────────────────────────────────────────────
After any guess, EVERY guessed letter carries exactly ONE of hit/miss on the
picker, AND every revealed letter is painted in the word display — in live
play, after a refresh, and across two devices.

── WHY IT IS KEYED TO STATE, NOT WIDTH ──────────────────────────────────────
The obvious gate would run desktop-vs-mobile, because that is how the report
arrived. It would be the WRONG AXIS and would pass: there is no width branching
anywhere in this code, and both widths measure identically. What actually
differs is STATE — live play shows one defect, a refresh or a second device
shows the others. Ian met one on each device and reasonably read it as a device
difference. Widths are still exercised, but the assertions key on state.

── WHY IT ASSERTS THE BRANCH, NOT THE SERVE ─────────────────────────────────
dev2 serves loothplatformv2-clean (main), so a browser test here measures MAIN
and would call this fix broken. The gate substitutes THIS TREE's game.js and
style.css over CDP, so what is asserted is the code about to be merged, against
the real server. Set LG_GDLE_SERVED=1 to measure what dev2 actually serves —
that is the red-first direction, and it is how this gate was proven.

── WHAT IT CANNOT CLAIM ─────────────────────────────────────────────────────
It exercises the SERVER-PLAY path (?sp=1) and the LEGACY path separately. It
does not assert the finished-game recap, hardcore caps, or the guess flow —
gates 37/40/41 own those.

Exit: 0 green, 1 red, 2 cannot run.
"""
import base64, json, os, shlex, subprocess, sys, time, urllib.request

CDP   = "http://127.0.0.1:9222"
HOST  = os.environ.get("LG_GDLE_HOST", "https://dev2.loothgroup.com")
ROOT  = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ASSETS = os.path.join(ROOT, "archive-poc", "web", "guitardle")
WPDIR = os.environ.get("LG_GDLE_WPDIR", "/var/www/dev")
PROBE_PREFIX = "gdle_ls_gate_"
PROBE_LOGIN  = PROBE_PREFIX + str(os.getpid())
# LG_GDLE_SERVED=1 measures dev2's served code (main) — the red-first direction.
LOCAL = "" if os.environ.get("LG_GDLE_SERVED") == "1" else ASSETS

fails = []
def check(ok, label, detail=""):
    print(("  PASS  " if ok else "  FAIL  ") + label + (("\n          " + detail) if detail and not ok else ""))
    if not ok:
        fails.append(label)
    return ok

def cannot_run(why):
    print("CANNOT RUN: " + why)
    sys.exit(2)

def sh(c):
    return subprocess.run(c, shell=True, capture_output=True, text=True)

def wp(php):
    r = sh("sudo -u looth-dev wp --path=%s eval %s" % (WPDIR, shlex.quote(php)))
    if r.returncode != 0:
        cannot_run("wp eval failed: " + r.stderr.strip()[:200])
    return r.stdout.strip().splitlines()[-1].strip() if r.stdout.strip() else ""

def psql(sql):
    return sh("sudo -u postgres psql -tAd looth -c %s" % shlex.quote(sql)).stdout.strip()

try:
    import websocket
except ImportError:
    cannot_run("python3 websocket-client is required")

for f in ("game.js", "style.css", "index.html"):
    if not os.path.exists(os.path.join(ASSETS, f)):
        cannot_run("missing " + os.path.join(ASSETS, f))
if sh("sudo -n -u looth-dev true").returncode != 0:
    cannot_run("no passwordless sudo to looth-dev")
if sh("sudo -n -u postgres true").returncode != 0:
    cannot_run("no passwordless sudo to postgres")

GATE = os.environ.get("LG_GATE_COOKIE", "")
if not GATE:
    r = sh("sudo grep -ho '\"[A-Za-z0-9]\\{40\\}\"' /etc/nginx/conf.d/loothdev-auth.conf 2>/dev/null")
    # splitlines BEFORE stripping quotes. The other order strips one quote off
    # each END of the whole multi-line blob, so the first token keeps a trailing
    # quote (41 chars), every request is refused, and the run measures a styled
    # 403. Caught only because the liveness assertion refuses to judge a board
    # that never rendered -- it would otherwise have been a silent all-pass.
    toks = [l.strip().strip('"') for l in r.stdout.splitlines() if l.strip()]
    GATE = toks[0] if toks else ""
if GATE and len(GATE) != 40:
    cannot_run("dev-gate token is malformed (%r, len %d)" % (GATE, len(GATE)))
if not GATE:
    cannot_run("no dev-gate token — chrome reaches dev2 on the internal IP and "
               "every page would be a styled 403 that passes having measured nothing")

try:
    BROWSER_WS = json.load(urllib.request.urlopen(CDP + "/json/version", timeout=10))["webSocketDebuggerUrl"]
except Exception as e:
    cannot_run("chrome-dev not answering on 9222: %s" % e)


# ── today's puzzle, so we KNOW which letters are hits and misses ─────────────
def todays_phrase():
    seq = json.load(open(os.path.join(ASSETS, "assets", "sequence.json")))
    import csv, datetime
    rows = {int(r["id"]): r["phrase"] for r in
            csv.DictReader(open(os.path.join(ASSETS, "assets", "guitardle_phrases.csv"), encoding="utf-8"))}
    start = datetime.date(*[int(x) for x in seq["startDate"].split("-")])
    d = (datetime.date.today() - start).days
    s = seq["sequence"]
    return rows[s[d % len(s)]].upper()

PHRASE = todays_phrase()
LETTERS = {c for c in PHRASE if c.isalpha()}
HITS = [c for c in "BCDFGHJKLMNPQRSTVWXYZ" if c in LETTERS]
MISSES = [c for c in "BCDFGHJKLMNPQRSTVWXYZ" if c not in LETTERS]
if len(HITS) < 2 or not MISSES:
    cannot_run("today's phrase %r has too few consonant hits/misses to test" % PHRASE)
A_HIT, B_HIT, A_MISS = HITS[0], HITS[1], MISSES[0]


# ── per-run probe member ─────────────────────────────────────────────────────
uid = wp('$l="%s";$u=get_user_by("login",$l);'
         'if(!$u){$id=wp_insert_user(["user_login"=>$l,"user_pass"=>wp_generate_password(24),'
         '"user_email"=>$l."@invalid.local","role"=>"subscriber"]);'
         'if(is_wp_error($id)){echo "ERR";return;}$u=get_user_by("id",$id);}echo $u->ID;' % PROBE_LOGIN)
if not uid.isdigit():
    cannot_run("could not create the per-run probe member")
UID = int(uid)

# A killed run leaves an account AND a row behind, and such a row once installed
# itself as dev2's weekly champion. Sweep older ones; the 30-min floor keeps a
# concurrent run's account safe.
stale = wp('$ids=[];$cut=time()-1800;'
           'foreach(get_users(["search"=>"%s*","search_columns"=>["user_login"]]) as $u){'
           'if($u->user_login==="%s")continue;if(strtotime($u->user_registered)>$cut)continue;'
           '$ids[]=(int)$u->ID;}echo $ids?implode(",",$ids):"none";' % (PROBE_PREFIX, PROBE_LOGIN))
if stale not in ("none", ""):
    ids = ",".join(i for i in stale.split(",") if i.strip().isdigit())
    if ids:
        psql("DELETE FROM discovery.guitardle_results WHERE wp_user_id IN (%s);" % ids)
        wp('require_once ABSPATH."wp-admin/includes/user.php";'
           'foreach([%s] as $i) wp_delete_user($i); echo "ok";' % ids)

CK = wp("echo LOGGED_IN_COOKIE;")

def mint():
    """A FRESH token per browser context: one shared token did not survive a
    second context's reload, and the run then measured a signed-out page."""
    return wp('$t=WP_Session_Tokens::get_instance(%d)->create(time()+3600);'
              'echo wp_generate_auth_cookie(%d,time()+3600,"logged_in",$t);' % (UID, UID))

def wipe():
    psql("DELETE FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID)

def cleanup():
    wipe()
    wp('require_once ABSPATH."wp-admin/includes/user.php";wp_delete_user(%d);echo "ok";' % UID)


class Browser:
    def __init__(self):
        self.ws = websocket.create_connection(BROWSER_WS, timeout=45, suppress_origin=True)
        self.i = 0
    def cmd(self, m, **p):
        self.i += 1
        self.ws.send(json.dumps({"id": self.i, "method": m, "params": p}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("id") == self.i:
                if "error" in r:
                    raise RuntimeError("%s: %s" % (m, r["error"]))
                return r.get("result", {})


class Device:
    """One ISOLATED browser context == one device. Two tabs would share cookies
    AND localStorage, which hides the very cross-device bug under test."""
    def __init__(self, browser, metrics, sp=True):
        self.browser = browser
        self.sp = sp
        self.subbed = set()
        self.ctx = browser.cmd("Target.createBrowserContext")["browserContextId"]
        tid = browser.cmd("Target.createTarget", url="about:blank",
                          browserContextId=self.ctx)["targetId"]
        info = [t for t in json.load(urllib.request.urlopen(CDP + "/json/list", timeout=10))
                if t["id"] == tid]
        if not info:
            raise RuntimeError("target vanished")
        self.ws = websocket.create_connection(info[0]["webSocketDebuggerUrl"],
                                              timeout=45, suppress_origin=True)
        self.i = 0
        for d in ("Network", "Page", "Runtime"):
            self.cmd(d + ".enable")
        if LOCAL:
            self.cmd("Fetch.enable", patterns=[{"urlPattern": "*guitardle/game.js*"},
                                               {"urlPattern": "*guitardle/style.css*"}])
        self.cookies()
        self.cmd("Emulation.setDeviceMetricsOverride", **metrics)

    def cookies(self):
        self.cmd("Network.setCookie", name="loothdev_auth", value=GATE,
                 domain=".dev2.loothgroup.com", path="/", secure=True)
        self.cmd("Network.setCookie", name=CK, value=mint(),
                 domain="dev2.loothgroup.com", path="/", secure=True)

    def cmd(self, m, **p):
        self.i += 1
        self.ws.send(json.dumps({"id": self.i, "method": m, "params": p}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("method") == "Fetch.requestPaused":
                self._serve(r["params"]); continue
            if r.get("id") == self.i:
                if "error" in r:
                    raise RuntimeError("%s: %s" % (m, r["error"]))
                return r.get("result", {})

    def _send(self, m, **p):
        self.i += 1
        self.ws.send(json.dumps({"id": self.i, "method": m, "params": p}))

    def _serve(self, p):
        url, rid = p.get("request", {}).get("url", ""), p["requestId"]
        for name, mime in (("game.js", "application/javascript"), ("style.css", "text/css")):
            if ("/guitardle/" + name) in url.split("?")[0]:
                fp = os.path.join(LOCAL, name)
                if os.path.exists(fp):
                    self.subbed.add(name)
                    self._send("Fetch.fulfillRequest", requestId=rid, responseCode=200,
                               responseHeaders=[{"name": "Content-Type", "value": mime},
                                                {"name": "Cache-Control", "value": "no-store"}],
                               body=base64.b64encode(open(fp, "rb").read()).decode())
                    return
        self._send("Fetch.continueRequest", requestId=rid)

    def js(self, e):
        return self.cmd("Runtime.evaluate", expression=e, returnByValue=True,
                        awaitPromise=True).get("result", {}).get("value")

    def url(self):
        return HOST + "/archive-poc/guitardle/index.html?aud=m" + ("&sp=1" if self.sp else "")

    def open(self):
        self.cmd("Page.navigate", url=self.url())
        return self.wait()

    def reload(self):
        self.cookies()
        self.cmd("Page.reload", ignoreCache=True)
        return self.wait()

    def wait(self, timeout=90):
        """Poll for readiness. A wall-clock settle measures a half-built DOM on
        a loaded box and reports it as a defect."""
        end = time.time() + timeout
        need_auth = "true" if self.sp else "true"
        while time.time() < end:
            try:
                if self.js("(()=>{const t=document.querySelectorAll('.tile').length;"
                           "const k=document.querySelectorAll('.key[data-letter]').length;"
                           "const a=typeof scoreAuth!=='undefined'&&scoreAuth&&scoreAuth.authenticated===%s;"
                           "return t>0&&k>0&&a;})()" % need_auth):
                    time.sleep(0.8)
                    return True
            except Exception:
                pass
            time.sleep(0.5)
        return False

    def state(self):
        return json.loads(self.js(
            "JSON.stringify({"
            "keys:[...document.querySelectorAll('.key[data-letter]')]"
            ".filter(k=>k.classList.contains('used')||k.classList.contains('purchased'))"
            ".map(k=>k.dataset.letter+':'+[...k.classList]"
            ".filter(c=>c!=='key'&&c!=='consonant'&&c!=='vowel').sort().join('.')).sort(),"
            "painted:[...document.querySelectorAll('.tile.revealed')]"
            ".map(t=>(t.dataset.i??'?')+'='+t.textContent.trim()).sort(),"
            "look:Object.fromEntries([...document.querySelectorAll('.key[data-letter]')]"
            ".map(k=>[k.dataset.letter,getComputedStyle(k).backgroundColor+'|'"
            "+getComputedStyle(k).textDecorationLine])),"
            "serverPlay:(typeof serverPlay!=='undefined')?!!serverPlay:null})"))

    def tap(self, letter):
        before = self.state()
        self.js("document.querySelector('.key[data-letter=\"%s\"]').click()" % letter)
        end = time.time() + 25
        while time.time() < end:
            s = self.state()
            if s != before:
                time.sleep(0.5)
                return s
            time.sleep(0.4)
        return self.state()

    def close(self):
        try: self.ws.close()
        except Exception: pass
        try: self.browser.cmd("Target.disposeBrowserContext", browserContextId=self.ctx)
        except Exception: pass


def cls_for(snap, letter):
    for k in snap["keys"]:
        if k.startswith(letter + ":"):
            return k.split(":", 1)[1]
    return None


print("guitardle-letter-state — GATE 57")
print("  phrase today: %r   hits used: %s %s   miss used: %s" % (PHRASE, A_HIT, B_HIT, A_MISS))
print("  asserting: %s" % ("THIS TREE's client (substituted over CDP)" if LOCAL
                           else "dev2's SERVED client — LG_GDLE_SERVED=1"))
print("  probe member: wp_user_id=%d\n" % UID)

DESKTOP = {"width": 1280, "height": 900, "deviceScaleFactor": 1, "mobile": False}
PHONE   = {"width": 390, "height": 844, "deviceScaleFactor": 2, "mobile": True}

br = Browser()
try:
    # ── PHASE 1 — LIVE PLAY, both widths ────────────────────────────────────
    print("PHASE 1 — live play: a hit is a HIT, a miss is a MISS, the hit paints")
    for label, metrics in (("desktop", DESKTOP), ("phone", PHONE)):
        wipe()
        d = Device(br, metrics)
        try:
            if not d.open():
                check(False, "[1/%s] board reached an authenticated server-play state" % label,
                      "never became ready — nothing below could be believed")
                continue
            if LOCAL and not check(bool(d.subbed), "[1/%s] this tree's client was substituted" % label,
                                   "interception never fired — the run would have measured main"):
                continue
            check(d.state()["serverPlay"] is True,
                  "[1/%s] the page is really in SERVER-DRIVEN play" % label,
                  "serverPlay=%s — the rest would assert the legacy path" % d.state()["serverPlay"])
            s = d.tap(A_HIT)
            c = cls_for(s, A_HIT)
            check(c is not None and "hit" in c and "used" in c,
                  "[1/%s] a HIT is marked hit" % label, "got %r" % c)
            check(len(s["painted"]) >= 1,
                  "[1/%s] a HIT paints its letter in the word display" % label,
                  "painted=%s" % s["painted"])
            s = d.tap(A_MISS)
            c = cls_for(s, A_MISS)
            check(c is not None and "miss" in c,
                  "[1/%s] a MISS is marked miss" % label, "got %r" % c)
            check(c is not None and "purchased" not in c,
                  "[1/%s] a MISS is NOT filed as a vowel purchase" % label, "got %r" % c)
            check(cls_for(s, A_HIT) != cls_for(s, A_MISS),
                  "[1/%s] hit and miss carry different classes" % label,
                  "both %r" % cls_for(s, A_HIT))
            # A class-name difference is NOT a visual one. On main a miss got
            # 'purchased', which differs from 'used' as a STRING while having no
            # consonant rule at all — so it rendered exactly like an untouched
            # key, which is the actual defect Ian saw. Assert the pixels.
            untouched = next((c for c in "BCDFGHJKLMNPQRSTVWXYZ"
                              if c not in (A_HIT, A_MISS, B_HIT)), None)
            look = s.get("look") or {}
            check(untouched and look.get(A_MISS) and look.get(A_MISS) != look.get(untouched),
                  "[1/%s] a MISS RENDERS differently from an untouched key" % label,
                  "miss=%s  untouched(%s)=%s" % (look.get(A_MISS), untouched, look.get(untouched)))
            check(look.get(A_HIT) and look.get(A_MISS) and look.get(A_HIT) != look.get(A_MISS),
                  "[1/%s] a HIT and a MISS RENDER differently" % label,
                  "hit=%s miss=%s" % (look.get(A_HIT), look.get(A_MISS)))
        finally:
            d.close()
    print()

    # ── PHASE 2 — THE REFRESH, where two of the three defects lived ─────────
    print("PHASE 2 — a refresh keeps the paint AND the hit/miss distinction")
    wipe()
    d = Device(br, DESKTOP)
    try:
        if d.open():
            d.tap(A_HIT); s_before = d.tap(A_MISS)
            if check(d.reload(), "[2] the board comes back authenticated after a refresh"):
                s = d.state()
                check(len(s["painted"]) >= len(s_before["painted"]) and len(s["painted"]) >= 1,
                      "[2] the word display still shows the revealed letter after a refresh",
                      "painted %s before, %s after" % (s_before["painted"], s["painted"]))
                ch, cm = cls_for(s, A_HIT), cls_for(s, A_MISS)
                check(ch is not None and "hit" in ch,
                      "[2] the HIT is still marked hit after a refresh", "got %r" % ch)
                check(cm is not None and "miss" in cm,
                      "[2] the MISS is still marked miss after a refresh", "got %r" % cm)
                check(ch != cm,
                      "[2] a refresh does not light every letter the same",
                      "hit=%r miss=%r — this is Ian's 'lights all letters'" % (ch, cm))
        else:
            check(False, "[2] board reached an authenticated server-play state")
    finally:
        d.close()
    print()

    # ── PHASE 3 — TWO DEVICES, ONE MEMBER, INTERLEAVED ─────────────────────
    print("PHASE 3 — two devices, interleaved guesses: identical state, nothing lost")
    wipe()
    A = Device(br, DESKTOP)
    B = Device(br, PHONE)
    try:
        if A.open() and B.open():
            A.tap(A_HIT); B.tap(B_HIT); A.tap(A_MISS)
            row = psql("SELECT resume_state FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID)
            try:
                st = json.loads(row) if row.strip().startswith("{") else {}
            except Exception:
                st = {}
            check(st.get("moves") == 3,
                  "[3] the SERVER counted all three interleaved moves (scoring input)",
                  "resume_state=%s" % row)
            check(set(st.get("revealed") or []) == {A_HIT, B_HIT, A_MISS},
                  "[3] every interleaved guess is in the server's record",
                  "revealed=%s" % (st.get("revealed"),))
            ok = A.reload() and B.reload()
            if check(ok, "[3] both devices come back authenticated"):
                ka, kb = A.state(), B.state()
                check(ka["keys"] == kb["keys"] and ka["painted"] == kb["painted"],
                      "[3] BOTH devices refresh to the IDENTICAL complete state",
                      "A=%s / %s\n          B=%s / %s"
                      % (ka["keys"], ka["painted"], kb["keys"], kb["painted"]))
                for L, want in ((A_HIT, "hit"), (B_HIT, "hit"), (A_MISS, "miss")):
                    c = cls_for(ka, L)
                    check(c is not None and want in c,
                          "[3] %s reads %s on the other device too" % (L, want.upper()),
                          "got %r" % c)
                check(len(ka["painted"]) >= 2,
                      "[3] hits made on BOTH devices are painted", "painted=%s" % ka["painted"])
        else:
            check(False, "[3] both devices reached an authenticated board")
    finally:
        A.close(); B.close()
    print()

    # ── PHASE 4 — THE LEGACY PATH, which is what LIVE runs today ───────────
    print("PHASE 4 — legacy (no sp=1): hit and miss must differ THERE TOO")
    wipe()
    d = Device(br, DESKTOP, sp=False)
    try:
        if d.open():
            check(d.state()["serverPlay"] is False,
                  "[4] this leg really is the LEGACY path",
                  "serverPlay=%s" % d.state()["serverPlay"])
            d.tap(A_HIT)
            s = d.tap(A_MISS)
            ch, cm = cls_for(s, A_HIT), cls_for(s, A_MISS)
            check(ch is not None and "hit" in ch, "[4] legacy marks a HIT hit", "got %r" % ch)
            check(cm is not None and "miss" in cm, "[4] legacy marks a MISS miss", "got %r" % cm)
            check(ch != cm,
                  "[4] legacy hit and miss are DISTINGUISHABLE — live is not correct today either",
                  "both %r" % ch)
            check(len(s["painted"]) >= 1, "[4] legacy still paints the hit",
                  "painted=%s" % s["painted"])
        else:
            check(False, "[4] legacy board became ready")
    finally:
        d.close()
finally:
    cleanup()

print()
if fails:
    print("############ GATE 57 RED — %d assertion(s) failed ############" % len(fails))
    for f in fails:
        print("  - " + f)
    sys.exit(1)
print("############ GATE 57 GREEN ############")
sys.exit(0)
