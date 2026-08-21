#!/usr/bin/env python3
"""#189 — drive the form's own uploader in a real browser, assert it, and shoot it.

Everything here runs against the BRANCH, through nginx and FPM, as a REAL member
(`looth1`, per-run, deleted at the end) — not `qa-disposable`, which is an
administrator and would measure the admin path.

WHAT IT PROVES, beyond taking pictures:
  · the media modal is ABSENT from the page, not hidden — no wp.media object,
    no media-* script tags, no media templates;
  · a photo uploads through the #186 chunker and a tile appears;
  · × unlinks and Undo restores the SAME attachment id, with no second upload;
  · at the limit a further file offers a SWAP, and taking it removes exactly one;
  · an over-size file is refused in the form's voice, naming the number.

⚠️ LIVENESS FIRST. A browser that lost the dev-gate cookie gets a styled 403 that
looks identical in both themes at every width, so a visual suite passes having
measured nothing. Every run asserts the control is on the page before it asserts
anything about the control.

⚠️ DRAG-AND-DROP IS NOT SYNTHESIZED, and that is stated rather than claimed.
CDP cannot fabricate a DataTransfer with real files. The drop handler and the
file input call the SAME accept() one line apart, so it is the same code path —
but only the input path is exercised here.
"""
import base64, json, os, subprocess, sys, time
import urllib.request
import websocket

LANE = "189-form-uploader"
OUT  = "/home/ubuntu/projects/footer-mockups/189-uploader"
URL  = "https://dev2.loothgroup.com/preview/%s/compose/?type=loothprint" % LANE
CDP  = "http://127.0.0.1:9222"
WP   = "/var/www/dev"
TAG  = str(os.getpid())

os.makedirs(OUT, exist_ok=True)
FAILS, PASSES = [], []

def R(name, ok, why=""):
    (PASSES if ok else FAILS).append(name)
    print("R|%s|%s|%s" % (name, "PASS" if ok else "FAIL", why), flush=True)

def wpcli(php, env=None):
    e = dict(os.environ)
    e.update(env or {})
    cmd = ["sudo", "-n", "-u", "looth-dev", "env"]
    cmd += ["%s=%s" % (k, v) for k, v in (env or {}).items()]
    cmd += ["wp", "--path=" + WP, "eval", php]
    return subprocess.run(cmd, capture_output=True, text=True, env=e)

# ── a REAL member, made here and destroyed at the end ────────────────────────
mk = wpcli(
    '$l="lg189shot-' + TAG + '";'
    '$id=wp_insert_user(["user_login"=>$l,"user_pass"=>wp_generate_password(24),'
    '"user_email"=>$l."@example.invalid","role"=>"looth1"]);'
    'if(is_wp_error($id)){fwrite(STDERR,$id->get_error_message());exit(1);}'
    '$e=time()+3600;$t=WP_Session_Tokens::get_instance($id)->create($e);'
    'echo json_encode(["id"=>$id,"roles"=>get_userdata($id)->roles,'
    '"cookie"=>LOGGED_IN_COOKIE,"value"=>wp_generate_auth_cookie($id,$e,"logged_in",$t)]);')
if mk.returncode != 0:
    sys.exit("could not create the probe member: " + (mk.stderr or "")[:300])
M = json.loads(mk.stdout.strip().splitlines()[-1])
R("member.is.looth1", M["roles"] == ["looth1"],
  "uid=%s roles=%s — qa-disposable is an administrator and would measure the admin path"
  % (M["id"], M["roles"]))

def cleanup():
    wpcli('require_once ABSPATH."wp-admin/includes/user.php"; wp_delete_user(%d);' % M["id"])

gate = subprocess.run(["sudo", "-n", "grep", "-oP", r'loothdev_token\s+"\K[^"]+',
                       "/etc/nginx/snippets/loothdev-tokens.conf"],
                      capture_output=True, text=True).stdout.strip().splitlines()
GATE = gate[0] if gate else ""

# ── one persistent socket: per-command sockets drop device emulation ─────────
req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
tgt = json.loads(urllib.request.urlopen(req, timeout=15).read())
ws  = websocket.create_connection(tgt["webSocketDebuggerUrl"], timeout=60, suppress_origin=True)
N = [0]

def send(method, params=None):
    N[0] += 1
    ws.send(json.dumps({"id": N[0], "method": method, "params": params or {}}))
    while True:
        m = json.loads(ws.recv())
        if m.get("id") == N[0]:
            if "error" in m:
                raise RuntimeError("%s: %s" % (method, m["error"]))
            return m.get("result", {})

def ev(expr):
    r = send("Runtime.evaluate", {"expression": expr, "returnByValue": True, "awaitPromise": True})
    if "exceptionDetails" in r:
        raise RuntimeError("JS: %s" % json.dumps(r["exceptionDetails"])[:300])
    return r.get("result", {}).get("value")

def wait(expr, secs=45, why=""):
    end = time.time() + secs
    while time.time() < end:
        if ev(expr):
            return True
        time.sleep(0.25)
    return False

send("Page.enable"); send("Runtime.enable"); send("Network.enable"); send("DOM.enable")
send("Security.setIgnoreCertificateErrors", {"ignore": True})

# ⚠️ CLEAR FIRST. The chrome profile is shared: Network.setCookies ADDS a second
# host-only-vs-dotted WP cookie rather than replacing one, and the run then
# executes as a DIFFERENT member.
send("Network.clearBrowserCookies")
send("Network.setCookies", {"cookies": [
    {"name": "loothdev_auth", "value": GATE, "domain": ".dev2.loothgroup.com", "path": "/"},
    {"name": M["cookie"], "value": M["value"], "url": "https://dev2.loothgroup.com/", "path": "/"},
]})

def go():
    send("Page.navigate", {"url": URL})
    time.sleep(2.2)

def shot(name, width=1280, height=900, theme="light"):
    send("Emulation.setDeviceMetricsOverride",
         {"width": width, "height": height, "deviceScaleFactor": 2, "mobile": width < 700})
    ev("document.documentElement.setAttribute('data-lguser-theme', %r)" % theme)
    time.sleep(0.45)
    png = send("Page.captureScreenshot", {"format": "png", "captureBeyondViewport": True})
    p = os.path.join(OUT, "%s-%s-%dw.png" % (name, theme, width))
    with open(p, "wb") as fh:
        fh.write(base64.b64decode(png["data"]))
    return p

# ── files the run uploads ────────────────────────────────────────────────────
DIR = "/tmp/lg189-shots-%s" % TAG
os.makedirs(DIR, exist_ok=True)
os.chmod(DIR, 0o755)

def png(path, w, h, label, pad=0):
    subprocess.run(["php", "-r",
        '$im=imagecreatetruecolor(%d,%d);' % (w, h) +
        '$c=imagecolorallocate($im,%d,%d,%d);' % ((hash(label) % 90) + 60, 130, 95) +
        'imagefilledrectangle($im,0,0,%d,%d,$c);' % (w, h) +
        '$t=imagecolorallocate($im,255,255,255);' +
        'imagestring($im,5,20,20,%r,$t);' % label +
        'imagepng($im,%r);' % path], check=True)
    if pad:
        with open(path, "ab") as fh:
            fh.write(b"\0" * pad)

SHOTS = []
for i in range(11):
    p = os.path.join(DIR, "photo-%02d.png" % (i + 1))
    png(p, 900, 640, "photo %d" % (i + 1))
    SHOTS.append(p)
BIG = os.path.join(DIR, "far-too-big.png")
png(BIG, 900, 640, "too big", pad=12 * 1024 * 1024)
SLOW = os.path.join(DIR, "slow-and-large.png")
png(SLOW, 1600, 1200, "in flight", pad=9 * 1024 * 1024)

def set_files(sel, paths):
    doc = send("DOM.getDocument")["root"]["nodeId"]
    nid = send("DOM.querySelector", {"nodeId": doc, "selector": sel})["nodeId"]
    if not nid:
        raise RuntimeError("no element for %s" % sel)
    send("DOM.setFileInputFiles", {"files": paths, "nodeId": nid})

PHOTOS = '[data-lgfc-up="photos"]'
def tiles():   return ev("document.querySelectorAll('%s .lgfc-up__item').length" % PHOTOS)
def ids():     return ev("Array.from(document.querySelectorAll('%s [data-lgfc-att]'))"
                         ".map(function(e){return e.getAttribute('data-lgfc-att')})" % PHOTOS)
def inputs():  return ev("Array.from(document.querySelectorAll('%s input[type=hidden]'))"
                         ".map(function(e){return e.name+'='+e.value})" % PHOTOS)

try:
    go()

    # ── LIVENESS, before anything is believed ────────────────────────────────
    live = ev("!!document.querySelector('%s') && !!(window.LGFC_UP && window.LGFC_UP.post_id)" % PHOTOS)
    R("liveness.form.arrived", bool(live),
      "the uploader control and window.LGFC_UP.post_id are both on the page — "
      "without this a gate-403 would score every assertion below as a pass")
    if not live:
        raise SystemExit("the page did not arrive signed in; nothing below would mean anything")
    R("liveness.branch.served",
      bool(ev("!!document.querySelector('%s .lgfc-up__zone')" % PHOTOS)),
      "the drop-zone is this branch's markup; main renders ACF's gallery here")

    # ── THE MODAL IS ABSENT, NOT HIDDEN ──────────────────────────────────────
    R("modal.no.wp.media", ev("typeof window.wp==='undefined' || typeof window.wp.media==='undefined'"),
      "window.wp.media is undefined — there is no modal object to open")
    scripts = ev("Array.from(document.scripts).map(function(s){return s.src}).filter(function(s){"
                 "return /media-views|media-editor|media-models|plupload|moxie/.test(s)})")
    R("modal.no.media.scripts", scripts == [], "media/plupload script tags on the page: %s" % (scripts or "none"))
    R("modal.no.acf.gallery", ev("document.querySelectorAll('.acf-gallery,.acf-file-uploader').length") == 0,
      "ACF's own gallery/file controls render zero times")
    R("modal.no.hidden.picker",
      ev("Array.from(document.querySelectorAll('*')).filter(function(e){"
         "return /media-modal|media-frame/.test(e.className||'')}).length") == 0,
      "and nothing media-modal-shaped is merely CSS-hidden")

    # ── keyboard reach ───────────────────────────────────────────────────────
    R("a11y.file.input.visible",
      ev("(function(){var i=document.querySelector('%s .lgfc-up__file');"
         "var r=i.getBoundingClientRect();var s=getComputedStyle(i);"
         "return r.width>0&&r.height>0&&s.visibility!=='hidden'&&s.display!=='none'})()" % PHOTOS),
      "the fallback file input is really visible, not sr-only")
    R("a11y.focusable",
      ev("(function(){var i=document.querySelector('%s .lgfc-up__file');i.focus();"
         "return document.activeElement===i})()" % PHOTOS),
      "and it takes focus, so the keyboard path is the browser's own control")

    # ⚠️ THE WRITE-UP EDITOR. The first version of this suite went green with the
    # editor rendered as a bare textarea — the whole of #185, back — because it
    # asserted nothing about it. It was caught by looking at a screenshot. These
    # three are why it cannot happen silently again.
    R("editor.tinymce.booted",
      wait("!!document.querySelector('.lgfc .acf-field[data-name=\"_post_content\"] iframe')", 20),
      "TinyMCE's iframe is in the write-up field — the editor really booted")
    R("editor.toolbar.drawn",
      ev("document.querySelectorAll('.lgfc .acf-field[data-name=\"_post_content\"] "
         ".mce-toolbar button, .lgfc .acf-field[data-name=\"_post_content\"] .mce-btn').length") > 0,
      "and it has a toolbar: %s controls"
      % ev("document.querySelectorAll('.lgfc .acf-field[data-name=\"_post_content\"] .mce-btn').length"))
    R("editor.not.a.bare.textarea",
      not ev("(function(){var w=document.querySelector('.lgfc .acf-editor-wrap');"
             "return !!w && w.className.indexOf('html-active')>=0})()"),
      "the field is not in html-active mode, which is what a dead editor looks like")

    # The hero picker sits directly under the photo strip and must stay out of
    # the way until there is a choice to make.
    R("hero.hidden.when.empty",
      ev("(function(){var h=document.querySelector('.lgfc__hero');"
         "return !!h && getComputedStyle(h).display==='none'})()"),
      "\"Which photo leads?\" is really invisible on an empty form, not merely "
      "carrying a hidden attribute an author display rule overrides")

    SHOT_EMPTY = [shot("1-empty", 1280, 1000, "light"), shot("1-empty", 1280, 1000, "dark"),
                  shot("1-empty", 390, 900, "light"),  shot("1-empty", 390, 900, "dark")]

    # ── mid-upload ───────────────────────────────────────────────────────────
    send("Emulation.setDeviceMetricsOverride",
         {"width": 1280, "height": 1000, "deviceScaleFactor": 2, "mobile": False})
    ev("document.documentElement.setAttribute('data-lguser-theme','light')")
    send("Network.emulateNetworkConditions",
         {"offline": False, "latency": 40, "downloadThroughput": 5_000_000,
          "uploadThroughput": 900_000})
    set_files('%s .lgfc-up__file' % PHOTOS, [SLOW])
    caught = wait("document.querySelectorAll('%s .lgfc-up__progitem').length>0" % PHOTOS, 20)
    R("upload.progress.shown", caught, "a progress row with a bar and a percentage appears while sending")
    if caught:
        time.sleep(1.0)
        SHOT_SENDING = shot("2-sending", 1280, 1000, "light")
        shot("2-sending", 1280, 1000, "dark")
    send("Network.emulateNetworkConditions",
         {"offline": False, "latency": 0, "downloadThroughput": -1, "uploadThroughput": -1})
    R("upload.tile.appears", wait("document.querySelectorAll('%s .lgfc-up__item').length===1" % PHOTOS, 90),
      "the finished upload becomes a tile: %d tile(s)" % (tiles() or 0))

    first = (ids() or [None])[0]
    R("upload.hidden.input.shape",
      inputs() and inputs()[0].endswith("="), "first hidden input is ACF's empty sentinel: %r" % (inputs() or [None])[0])
    R("upload.hidden.input.id", any(i.endswith("=" + str(first)) and "[]" in i for i in (inputs() or [])),
      "the tile carries acf[key][]=%s" % first)

    # ── × is an UNLINK, and Undo restores the SAME id ────────────────────────
    ev("document.querySelector('%s .lgfc-up__x').click()" % PHOTOS)
    time.sleep(0.4)
    R("remove.unlinks", tiles() == 0 and ids() == [], "the tile and its hidden input are gone from the form")
    still = wpcli('echo get_post(%s) ? "yes":"no";' % first).stdout.strip()
    R("remove.does.not.delete", still.endswith("yes"),
      "attachment %s still exists on the server: %r — removal is an unlink; #186's stamped "
      "collector at publish is the only thing that deletes" % (first, still))
    R("undo.offered", ev("!!document.querySelector('%s .lgfc-up__undo')" % PHOTOS), "an Undo control is offered")
    ev("document.querySelector('%s .lgfc-up__undo').click()" % PHOTOS)
    time.sleep(0.4)
    back = ids() or []
    R("undo.same.id", back == [first],
      "Undo restored %r, the same attachment row — no second upload, so nothing can be double-stamped" % back)

    # ── fill to the limit ────────────────────────────────────────────────────
    set_files('%s .lgfc-up__file' % PHOTOS, SHOTS[:9])
    R("limit.fills.to.ten", wait("document.querySelectorAll('%s .lgfc-up__item').length===10" % PHOTOS, 180),
      "ten photos on the form: %d" % (tiles() or 0))
    at_limit = ids() or []
    R("hero.appears.with.a.choice",
      ev("(function(){var h=document.querySelector('.lgfc__hero');"
         "return !!h && getComputedStyle(h).display!=='none' && "
         "h.querySelectorAll('.lgfc__heroopt').length>1})()"),
      "with ten photos the lead-photo picker is on screen with %s options, rebuilt "
      "from OUR tiles (it used to mirror ACF's gallery)"
      % ev("document.querySelectorAll('.lgfc__heroopt').length"))

    # ── 1 IN, 1 OUT ──────────────────────────────────────────────────────────
    set_files('%s .lgfc-up__file' % PHOTOS, [SHOTS[10]])
    R("swap.offered", wait("!document.querySelector('%s .lgfc-up__swapbar').hidden" % PHOTOS, 15),
      "at the limit the form offers a swap instead of dead-ending: %r"
      % ev("(document.querySelector('%s .lgfc-up__swaptext')||{}).textContent" % PHOTOS))
    SHOT_LIMIT = [shot("3-at-the-limit", 1280, 1200, "light"), shot("3-at-the-limit", 1280, 1200, "dark"),
                  shot("3-at-the-limit", 390, 1100, "light")]

    victim = ev("document.querySelector('%s .lgfc-up__item').getAttribute('data-lgfc-att')" % PHOTOS)
    ev("document.querySelector('%s .lgfc-up__swap').click()" % PHOTOS)
    R("swap.completes", wait("document.querySelectorAll('%s .lgfc-up__item').length===10" % PHOTOS, 90),
      "still ten after the swap: %d" % (tiles() or 0))
    after = ids() or []
    R("swap.removed.exactly.one", len([i for i in at_limit if i not in after]) == 1,
      "exactly one id left the form: %s" % [i for i in at_limit if i not in after])
    R("swap.added.exactly.one", len([i for i in after if i not in at_limit]) == 1,
      "exactly one id joined it: %s" % [i for i in after if i not in at_limit])
    kept = wpcli('echo get_post(%s) ? "yes":"no";' % victim).stdout.strip()
    R("swap.did.not.delete", kept.endswith("yes"),
      "the swapped-out attachment %s still exists: %r — not deleted at that moment" % (victim, kept))

    # ── a refusal, in the form's voice ───────────────────────────────────────
    ev("document.querySelector('%s .lgfc-up__cancel') && "
       "document.querySelector('%s .lgfc-up__cancel').click()" % (PHOTOS, PHOTOS))
    time.sleep(0.3)
    set_files('%s .lgfc-up__file' % PHOTOS, [BIG])
    R("refusal.shown", wait("!!document.querySelector('%s .lgfc-up__err').textContent.trim()" % PHOTOS, 25),
      "the form refuses out loud")
    msg = ev("document.querySelector('%s .lgfc-up__err').textContent.trim()" % PHOTOS)
    R("refusal.names.the.number", "10MB" in (msg or ""), "it names the limit: %r" % msg)
    R("refusal.role.alert",
      ev("document.querySelector('%s .lgfc-up__err').getAttribute('role')==='alert'" % PHOTOS),
      "and it is announced, not only drawn")
    SHOT_ERR = [shot("4-refused", 1280, 1200, "light"), shot("4-refused", 1280, 1200, "dark"),
                shot("4-refused", 390, 1100, "light")]

    print("Z.end|reached")
finally:
    try:
        ws.close()
    except Exception:
        pass
    cleanup()

print("\n%d passed, %d failed" % (len(PASSES), len(FAILS)))
if FAILS:
    print("FAILED: " + ", ".join(FAILS))
print("shots in %s" % OUT)
sys.exit(1 if FAILS else 0)
