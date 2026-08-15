#!/usr/bin/env python3
"""
draft-first-loop.py — drive the WHOLE compose media loop in a real browser.

Gate 46 asserts the draft-first contract from PHP: it calls lg_fc_working_draft()
directly and inserts an attachment the way the picker would. That is the right
shape for a gate, and it is not evidence about the thing a member touches. This
drives the actual controls, in Chrome, at both widths:

    open compose  ->  the form binds a NUMERIC draft, never 'new_post'
                  ->  the real media picker opens
                  ->  its library is scoped to THAT draft
                  ->  a REAL upload through the picker lands parented to it
                  ->  the picker lists only this post's media
    abandon       ->  zero unparented rows, site-wide

Ian's two rules, checked through the controls rather than around them: no
orphans, and each post has its own library.

WHY BOTH WIDTHS. The modal work earlier on this branch shipped a desktop-only
pass twice, and the phone was where the defect was both times. The picker is a
WordPress media modal, which has its own responsive behaviour we do not control.

TRAPS ENCODED (each one has produced a wrong answer on this box before):

  1. ONE PERSISTENT CDP CONNECTION. Device emulation is per-session, so the
     per-command socket style drops the override and a "390px" run silently
     measures desktop.
  2. CLEAR COOKIES FIRST, then set the gate cookie DOTTED and the WP cookies
     HOST-ONLY with sameSite=Lax. The shared profile already holds WP cookies;
     setCookie ADDS rather than replaces when the flavour differs, so a run can
     execute as a different member and go green for the wrong reason.
  3. --resolve / host-resolver to the INTERNAL address. A plain public request
     is Cloudflare-challenged into a 403 that reads as an outage.
  4. LIVENESS BEFORE ABSENCE. Every "nothing was orphaned" claim here is paired
     with proof that an upload actually happened — an absence assertion over a
     loop that never ran is vacuous, and this one would be trivially green if
     the picker never opened.
  5. THE UNATTACHED COUNT IS SITE-WIDE, not "our rows". A count of rows the
     probe made cannot see an orphan produced by a path the probe did not model.

SELF-CONTAINED: makes its own draft and its own upload, and force-deletes both
on the way out including on failure, then prints what remains so the cleanup
proves itself rather than being asserted.

Exit: 0 green, 1 a real defect, 3 CANNOT RUN.
"""
import argparse
import base64
import json
import os
import subprocess
import sys
import time

import websocket  # websocket-client, as tools/cdp.py and shots.py use

CDP = "http://127.0.0.1:9222"
REPO = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
PROBE_LOGIN = "claude_admin"

# An admin is the STRONGER subject for the scoping half: with no scoping an
# admin's picker shows the whole site's library, so "only this post's media" is
# a real claim about the code rather than about what this user could see anyway.
VIEWPORTS = [
    # label     width height mobile
    ("desktop", 1280, 1400, False),
    ("phone",    390, 1500, True),
]

# 1x1 transparent PNG — small enough that the upload is never the slow part,
# real enough that WordPress runs its true image pipeline over it.
PNG = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk"
    "+M9QzwAEYwOAKAWZ2AAAAABJRU5ErkJggg==")


class CannotRun(Exception):
    pass


def sh(cmd):
    return subprocess.run(cmd, capture_output=True, text=True)


def gate_env():
    r = sh(["bash", os.path.join(REPO, "tools", "gates", "gate-env.sh")])
    if r.returncode != 0:
        raise CannotRun("gate-env.sh failed: " + r.stderr[:200])
    return dict(l.partition("=")[::2] for l in r.stdout.splitlines() if "=" in l)


def wp_eval(php):
    """One wp eval, retried once.

    RETRIED ON PURPOSE. wp-cli on this box exits non-zero intermittently under
    fleet load while still having produced correct stdout, and a run that dies
    on that reports a busy box as a missing environment — the exact shape of
    the "a gate exiting 3 blocks every lane" trap. The php snippet goes into the
    error so a real failure names the query that caused it instead of pointing
    at wp-cli's own DISABLE_WP_CRON warning, which is present on EVERY call and
    means nothing.
    """
    last = None
    for _ in range(2):
        r = sh(["sudo", "-n", "wp", "--allow-root", "--path=/var/www/dev",
                "--skip-themes", "eval", php])
        out = "\n".join(l for l in r.stdout.splitlines()
                         if not l.startswith(("PHP Warning:", "PHP Deprecated:",
                                              "Warning:", "Deprecated:"))).strip()
        if r.returncode == 0:
            return out
        last = (r.returncode, (r.stderr or "").strip().splitlines()[-1:] or [""])
        time.sleep(1.0)
    raise CannotRun(f"wp eval rc={last[0]} on `{php[:70]}...`: {last[1][0][:200]}")


def wp_json(php):
    return json.loads(wp_eval(php))


def unattached_count():
    return int(wp_eval(
        'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM '
        '$wpdb->posts WHERE post_type=\'attachment\' AND post_parent=0");'))


class Tab:
    """One persistent CDP session — trap 1."""

    def __init__(self):
        raw = subprocess.check_output(
            ["curl", "-s", "-X", "PUT", f"{CDP}/json/new?about:blank"])
        t = json.loads(raw)
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

    def js(self, expr, timeout_note=""):
        """Evaluate and return the value. Awaits promises so callers can sleep
        on the page's own signals rather than on a guessed wall-clock delay."""
        r = self.call("Runtime.evaluate", expression=expr,
                      returnByValue=True, awaitPromise=True)
        if "exceptionDetails" in r:
            exc = r["exceptionDetails"]
            desc = (exc.get("exception") or {}).get("description", json.dumps(exc)[:200])
            raise RuntimeError(f"JS threw{timeout_note}: {desc[:300]}")
        return r["result"].get("value")

    def js_obj(self, expr):
        """Evaluate and keep the remote object (for DOM.setFileInputFiles)."""
        r = self.call("Runtime.evaluate", expression=expr, returnByValue=False)
        if "exceptionDetails" in r:
            raise RuntimeError("JS threw: " + json.dumps(r["exceptionDetails"])[:200])
        return r["result"].get("objectId")

    def close(self):
        try:
            self.ws.close()
        finally:
            sh(["curl", "-s", f"{CDP}/json/close/{self.id}"])


def cookies_for(env, login):
    """Trap 2: gate cookie DOTTED, WP cookies HOST-ONLY, sameSite explicit."""
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
            name, value = line.split("|", 1)
            out.append({"name": name, "value": value, "domain": domain,
                        "path": "/", "secure": True, "httpOnly": True,
                        "sameSite": "Lax"})
    return out


def wait_for(tab, expr, seconds=15, step=0.4):
    """Poll a JS predicate. Returns True as soon as it holds."""
    deadline = time.time() + seconds
    while time.time() < deadline:
        try:
            if tab.js(expr):
                return True
        except RuntimeError:
            pass
        time.sleep(step)
    return False


def drop_existing_drafts(uid):
    """Start each viewport from a cold open so 'the draft was created' is a real
    observation rather than a leftover from the previous pass."""
    wp_eval(
        f'foreach (get_posts(["post_type"=>array_keys(lg_fc_types()),'
        f'"post_status"=>"auto-draft","author"=>{uid},"numberposts"=>-1,'
        f'"fields"=>"ids","meta_key"=>"_lg_fc_draft"]) as $id) {{'
        f'  foreach (get_children(["post_parent"=>$id,"post_type"=>"attachment",'
        f'"numberposts"=>-1,"fields"=>"ids"]) as $a) wp_delete_attachment($a, true);'
        f'  wp_delete_post($id, true); }}'
        f'echo "ok";')


def run_viewport(tab, env, cookies, label, w, h, mobile, findings, notes):
    host = env["LG_GATE_HOST"]
    url = host + "/preview/frontend-compose/compose/?type=loothprint"
    uid = int(wp_eval(f"echo (int) get_user_by('login','{PROBE_LOGIN}')->ID;"))

    drop_existing_drafts(uid)
    baseline = unattached_count()
    print(f"\n── {label} ({w}x{h}{', touch' if mobile else ''}) "
          f"unattached baseline {baseline}")

    tab.call("Emulation.setDeviceMetricsOverride", width=w, height=h,
             deviceScaleFactor=1, mobile=mobile, screenWidth=w, screenHeight=h)
    # maxTouchPoints must be 1..16 even when disabling — 0 is rejected outright,
    # and the raised error kills the run before a single assertion is made.
    tab.call("Emulation.setTouchEmulationEnabled", enabled=mobile,
             maxTouchPoints=5 if mobile else 1)

    # RE-ASSERT THE COOKIES AND RETRY A SIGNED-OUT FRAME. Setting them once at
    # the top of the run looked correct and is not: frames come back as the
    # branded 404 — the route's signed-out answer — while other frames in the
    # same run are fine. shots.py hit exactly this and handles it the same way.
    # It matters more here than for a screenshot: a signed-out frame does not
    # merely photograph the wrong thing, it makes the upload POST fail with no
    # visible error, and this run then reports "no row appeared" as if the
    # feature were broken. Measured: one run bound the draft and scoped the
    # picker correctly, and the upload silently did nothing.
    ok = False
    for attempt in range(3):
        tab.call("Network.setCookies", cookies=cookies)
        tab.call("Page.navigate", url=url)
        if wait_for(tab, "!!document.querySelector('.lgfc__card')", 30):
            ok = True
            break
        seen = tab.js("JSON.stringify({url:location.href, title:document.title, "
                      "bytes:document.documentElement.innerHTML.length, "
                      "text:(document.body?document.body.innerText:'').slice(0,120)})")
        print(f"   retry {attempt + 1}: no form yet — {seen}")
    if not ok:
        # SAY WHAT WAS ON SCREEN. "never rendered" is the same sentence whether
        # the route 404'd, the gate 403'd, the session dropped, or the page was
        # slow — and those need opposite responses.
        findings.append(f"[{label}] the compose form never rendered in 3 tries — "
                        f"the run below would have been vacuous. Page was: {seen}")
        return None
    time.sleep(1.5)   # let acf/wp.media boot

    # ---- 1. the form binds a real draft, not 'new_post'
    bound = tab.js("(document.querySelector('input[name=_acf_post_id]')||{}).value || ''")
    real_width = tab.js("innerWidth")
    print(f"   bound post_id: {bound!r}   innerWidth {real_width}")
    if real_width != w:
        notes.append(f"[{label}] innerWidth {real_width} != requested {w}")
    if not str(bound).isdigit():
        findings.append(f"[{label}] the form bound {bound!r} instead of a draft id — "
                        f"uploads have nothing to parent to, which is the orphan "
                        f"behaviour draft-first replaces")
        return None
    draft = int(bound)

    row = wp_json(f'$p = get_post({draft}); echo json_encode(['
                  f'"status"=>$p?$p->post_status:null,'
                  f'"author"=>$p?(int)$p->post_author:null,'
                  f'"marked"=>(bool)get_post_meta({draft},"_lg_fc_draft",true)]);')
    if row["status"] != "auto-draft" or not row["marked"] or row["author"] != uid:
        findings.append(f"[{label}] draft {draft} is not a marked auto-draft owned "
                        f"by the composer: {row}")

    # ---- 2. the real picker opens
    opened = tab.js("""(() => {
      const b = document.querySelector('.acf-field-gallery .acf-gallery-add');
      if (!b) return 'no-add-button';
      b.scrollIntoView({block:'center'});
      b.click();
      return 'clicked';
    })()""")
    if opened != "clicked":
        findings.append(f"[{label}] the gallery's Add button was not in the form "
                        f"({opened}) — the member has no way to reach the picker")
        return draft
    if not wait_for(tab, "!!document.querySelector('.media-modal')", 15):
        findings.append(f"[{label}] clicking Add did not open the media picker")
        return draft
    time.sleep(1.5)

    # ---- 3. the picker's library is scoped to THIS draft
    scoped = tab.js("""(() => {
      try {
        const f = wp.media.frame;
        if (!f) return {err:'no frame'};
        const lib = f.state().get('library');
        return {uploadedTo: lib.props.get('uploadedTo') ?? null,
                type: lib.props.get('type') ?? null};
      } catch (e) { return {err: String(e)}; }
    })()""")
    print(f"   picker library props: {scoped}")
    if scoped.get("uploadedTo") != draft:
        findings.append(f"[{label}] the picker is NOT scoped to this post — "
                        f"uploadedTo={scoped.get('uploadedTo')!r}, expected {draft}. "
                        f"Each post is supposed to keep its own library")

    # ---- 4. a REAL upload through the picker
    before_children = int(wp_eval(
        f'echo (int) count(get_children(["post_parent"=>{draft},'
        f'"post_type"=>"attachment","numberposts"=>-1,"fields"=>"ids"]));'))

    path = f"/tmp/claude-1000/draft-first-{label}-{os.getpid()}.png"
    with open(path, "wb") as fh:
        fh.write(PNG)
    os.chmod(path, 0o644)

    oid = tab.js_obj(
        "document.querySelector('.media-modal input[type=file]') || "
        "document.querySelector('.moxie-shim input[type=file]')")
    if not oid:
        findings.append(f"[{label}] the picker exposes no file input — an upload "
                        f"cannot be driven, so the parenting claim is untested")
    else:
        # AND AGAIN RIGHT BEFORE THE UPLOAD. The POST to async-upload.php carries
        # its own auth; a session dropped between page load and this click fails
        # it with nothing on screen, which is indistinguishable from "the feature
        # does not parent uploads" unless the cookies are known good here.
        tab.call("Network.setCookies", cookies=cookies)
        tab.call("DOM.setFileInputFiles", files=[path], objectId=oid)

        # WAIT ON THE STORE, NOT ON THE PICKER. wp.media inserts an attachment
        # model into the library the moment the upload STARTS, so a poll of
        # `library.length` goes true while the row does not exist yet — this run
        # reported "children unchanged at 0" on an upload that had in fact
        # landed a second later. Polling the database is the only signal here
        # that cannot be true early.
        got = False
        for _ in range(30):
            time.sleep(1.0)
            n = int(wp_eval(
                f'echo (int) count(get_children(["post_parent"=>{draft},'
                f'"post_type"=>"attachment","numberposts"=>-1,"fields"=>"ids"]));'))
            if n > before_children:
                got = True
                break
        after = wp_json(
            f'$ids = get_children(["post_parent"=>{draft},"post_type"=>"attachment",'
            f'"numberposts"=>-1,"fields"=>"ids"]);'
            f'echo json_encode(["children"=>array_values(array_map("intval",$ids))]);')
        kids = after["children"]
        print(f"   upload: row appeared={got}  children of draft: "
              f"{before_children} -> {len(kids)}")
        if len(kids) <= before_children:
            # Report what the PICKER thought, not just what the DB lacks. "no row
            # appeared" is the same sentence for a refused upload, a silently
            # errored one, and an input node that went stale before the file was
            # set — and only the picker's own state can tell them apart.
            diag = tab.js("""(() => {
              try {
                const inp = document.querySelector('.media-modal input[type=file]');
                return JSON.stringify({
                  queue: (wp.Uploader && wp.Uploader.queue) ? wp.Uploader.queue.length : -1,
                  lib: wp.media.frame.state().get('library').length,
                  errors: [...document.querySelectorAll('.upload-error,.upload-errors,.notice-error')]
                            .map(e => e.innerText.slice(0, 140)),
                  inputConnected: !!(inp && inp.isConnected),
                  inputFiles: inp ? inp.files.length : -1,
                  modalOpen: !!document.querySelector('.media-modal')});
              } catch (e) { return JSON.stringify({err: String(e)}); }
            })()""")
            findings.append(f"[{label}] the upload did not land parented to the "
                            f"draft — children unchanged at {before_children}. "
                            f"Picker state: {diag}")
        else:
            # LIVENESS for the absence check below: something really was uploaded.
            newest = max(kids)
            parent = int(wp_eval(f'echo (int) get_post({newest})->post_parent;'))
            if parent != draft:
                findings.append(f"[{label}] uploaded attachment {newest} has "
                                f"post_parent {parent}, not the draft {draft}")

            # ---- 5. the picker lists ONLY this post's media
            listed = tab.js("""(() => {
              try {
                const lib = wp.media.frame.state().get('library');
                return {n: lib.length, ids: lib.map(m => m.id)};
              } catch (e) { return {err: String(e)}; }
            })()""")
            db_ids = sorted(kids)
            shown = sorted(listed.get("ids") or [])
            print(f"   picker lists {listed.get('n')} item(s); draft owns {len(db_ids)}")
            if shown != db_ids:
                findings.append(f"[{label}] the picker lists {shown} but this post "
                                f"owns {db_ids} — the library is not this post's")
    try:
        os.unlink(path)
    except OSError:
        pass

    # ---- 6. ABANDON: leave without submitting
    tab.js("(() => { const b = document.querySelector('.media-modal-close'); "
           "if (b) b.click(); return 1; })()")
    time.sleep(0.5)
    tab.call("Page.navigate", url="about:blank")
    time.sleep(1.5)

    return draft


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--only", default="", help="desktop|phone (default: both)")
    args = ap.parse_args()

    print("draft-first-loop — the compose media loop, driven in a real browser")
    findings, notes = [], []
    drafts = []
    tab = None
    try:
        env = gate_env()
        if not wp_eval('echo function_exists("lg_fc_working_draft") ? "1" : "";'):
            print("  CANNOT RUN: lg_fc_working_draft() is not loaded in this "
                  "docroot — every assertion below would be vacuous")
            return 3
        try:
            subprocess.check_output(["curl", "-s", "--max-time", "3",
                                     f"{CDP}/json/version"])
        except Exception:
            print("  CANNOT RUN: no CDP on 127.0.0.1:9222")
            return 3

        cookies = cookies_for(env, PROBE_LOGIN)
        uid = int(wp_eval(f"echo (int) get_user_by('login','{PROBE_LOGIN}')->ID;"))
        start_unattached = unattached_count()

        tab = Tab()
        tab.call("Page.enable")
        tab.call("Network.enable")
        tab.call("Network.clearBrowserCookies")   # trap 2
        tab.call("Network.setCookies", cookies=cookies)

        for label, w, h, mobile in VIEWPORTS:
            if args.only and args.only != label:
                continue
            tab.call("Network.setCookies", cookies=cookies)
            d = run_viewport(tab, env, cookies, label, w, h, mobile, findings, notes)
            if d:
                drafts.append(d)
    except CannotRun as e:
        print(f"  CANNOT RUN: {e}")
        return 3
    finally:
        if tab:
            tab.close()
        # cleanup, and PROVE it rather than assert it
        try:
            drop_existing_drafts(uid)
            left = wp_eval(
                f'echo count(get_posts(["post_type"=>array_keys(lg_fc_types()),'
                f'"post_status"=>"auto-draft","author"=>{uid},"numberposts"=>-1,'
                f'"fields"=>"ids","meta_key"=>"_lg_fc_draft"]));')
            end_unattached = unattached_count()
            print(f"\ncleanup: {left} draft(s) left; unattached {end_unattached} "
                  f"(was {start_unattached})")
            # ---- the ABANDON assertion, site-wide (trap 5)
            if end_unattached != start_unattached:
                findings.append(
                    f"ABANDON LEFT ORPHANS: site-wide unattached went "
                    f"{start_unattached} -> {end_unattached}")
        except Exception as e:                      # noqa: BLE001
            print(f"  cleanup could not be verified: {e}")

    for n in notes:
        print(f"  note: {n}")
    if findings:
        print("\nRED — the compose media loop is broken in the browser:")
        for f in findings:
            print(f"  ✗ {f}")
        return 1
    print("\nGREEN — the loop holds through the real controls, at both widths.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
