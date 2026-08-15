#!/usr/bin/env python3
"""
file-picker-test.py — click the download block's FILE picker on the real serve.

Block 2 shipped this picker (the control a member needs to swap a print file,
which the block previously had NO way to reach) but only the licence picker had
ever been driven. This closes that.

SELF-CONTAINED: creates its own PRIVATE probe loothprint carrying a download
block, drives it, and force-deletes the probe on the way out — including on
failure. It must leave zero probe rows; the lane rule is that every probe proves
its own cleanup.

Why a probe and not a real post: the flags are OFF, and a member-facing flag is
not something a test flips. The `download` block itself is unflagged, so a
stored download block renders regardless — which is what makes this testable
with everything off.

TRAPS ENCODED (each gave a wrong answer first, on the licence picker):
  · REAL pointer input — the edit pill is opacity:0/pointer-events:none until
    :hover, and CSS :hover ignores dispatched MouseEvents.
  · SCOPE the pill to the block host — the page carries three edit pills, and
    taking the first --edit button in the document clicks post-header's.
  · scrollIntoView first — getBoundingClientRect is viewport-relative.
  · sameSite on the WP cookies — see tools/frontend-compose/shots.py trap 6.

Exit: 0 pass, 1 a real failure, 3 CANNOT RUN.
"""
import subprocess

WP = ["sudo", "-n", "wp", "--allow-root", "--path=/var/www/dev", "--skip-themes"]


def wp_eval(php: str) -> str:
    r = subprocess.run(WP + ["eval", php], capture_output=True, text=True)
    return "\n".join(l for l in r.stdout.splitlines()
                      if not l.startswith(("PHP Warning:", "PHP Deprecated:", "Warning:")))


def make_probe() -> int:
    out = wp_eval(
        '$att = get_posts(["post_type"=>"attachment","numberposts"=>1,"fields"=>"ids","post_status"=>"inherit"]);'
        '$fid = $att ? (int)$att[0] : 0;'
        '$id = wp_insert_post(["post_type"=>"loothprint","post_status"=>"private",'
        '  "post_title"=>"ZZ PROBE file picker (delete me)","post_name"=>"zz-probe-file-picker",'
        '  "post_content"=>"probe"], true);'
        'if (is_wp_error($id)) { echo 0; return; }'
        'update_post_meta($id,"_lg_layout_v2",["schema"=>1,"_meta"=>["title"=>"probe"],'
        '  "blocks"=>[["type"=>"post-header","id"=>"h"],'
        '             ["type"=>"download","id"=>"probe_dl","file_id"=>$fid,"title"=>"Download"],'
        '             ["type"=>"post-footer","id"=>"f"]]]);'
        'update_post_meta($id,"loothprint_3d_file",$fid); echo $id;'
    ).strip()
    return int(out or 0)


def kill_probe(pid: int) -> str:
    if pid:
        subprocess.run(WP + ["post", "delete", str(pid), "--force"], capture_output=True, text=True)
    return wp_eval('global $wpdb; echo (int) $wpdb->get_var('
                   '"SELECT COUNT(*) FROM $wpdb->posts WHERE post_title LIKE \'ZZ PROBE%\'");').strip()

import sys, time, json
sys.path.insert(0, "tools/frontend-compose")
from shots import Tab, gate_env, wp_cookies
env = gate_env(); domain = env["LG_GATE_DOMAIN"]
URL = env["LG_GATE_HOST"] + "/loothprint/zz-probe-file-picker/?lg_edit=1"
PROBE = make_probe()
if not PROBE:
    print("CANNOT RUN: could not create the probe post"); raise SystemExit(3)
print(f"probe post {PROBE} created")
def ev(t, js):
    r = t.call("Runtime.evaluate", expression=js, returnByValue=True)
    return r["result"].get("value") if "exceptionDetails" not in r else {"__err": str(r["exceptionDetails"])[:180]}
tab = Tab(); out = {}
try:
    tab.call("Page.enable"); tab.call("Network.enable")
    tab.call("Network.setCacheDisabled", cacheDisabled=True); tab.call("Network.clearBrowserCookies")
    cookies = [{"name":"loothdev_auth","value":env["LG_GATE_TOKEN"],
                "domain":"."+domain,"path":"/","secure":True}]
    for n,v in wp_cookies("claude_admin"):
        cookies.append({"name":n,"value":v,"domain":domain,"path":"/","secure":True,
                        "httpOnly":True,"sameSite":"Lax"})
    tab.call("Network.setCookies", cookies=cookies)
    tab.call("Emulation.setDeviceMetricsOverride", width=1280, height=1400,
             deviceScaleFactor=1, mobile=False, screenWidth=1280, screenHeight=1400)
    tab.call("Page.navigate", url=URL); time.sleep(5)

    out["title"]        = ev(tab, "document.title")
    out["editor_boot"]  = ev(tab, "!!window.LG_FE_EDITOR_API")
    out["block_shown"]  = ev(tab, "!!document.querySelector('.lg-download')")
    out["wp_media"]     = ev(tab, "!!(window.wp && wp.media)")
    if not out["block_shown"]:
        print(json.dumps(out, indent=2)); raise SystemExit(0)

    ev(tab, "document.querySelector('.lg-download').scrollIntoView({block:'center'})"); time.sleep(1)
    host = ev(tab, "(() => {const h=document.querySelector('.lg-download.lg-edit-host')||document.querySelector('.lg-download').closest('.lg-edit-host'); if(!h) return null; const r=h.getBoundingClientRect(); return {x:Math.round(r.left+r.width/2), y:Math.round(r.top+15)};})()")
    if not host:
        out["host"]="no lg-edit-host on the download block"; print(json.dumps(out,indent=2)); raise SystemExit(0)
    tab.call("Input.dispatchMouseEvent", type="mouseMoved", x=host["x"], y=host["y"], buttons=0); time.sleep(1.5)
    # SCOPED to the download host — the page has three pills
    btn = ev(tab, """(() => {
      const h=document.querySelector('.lg-download.lg-edit-host')||document.querySelector('.lg-download').closest('.lg-edit-host');
      const b=[...h.querySelectorAll(':scope > .lg-edit-pill .lg-edit-pill__btn')].find(x=>x.className.includes('--edit'));
      if(!b) return null; const r=b.getBoundingClientRect();
      const el=document.elementFromPoint(r.left+r.width/2, r.top+r.height/2);
      return {x:Math.round(r.left+r.width/2), y:Math.round(r.top+r.height/2), topmost:(el===b||b.contains(el))};})()""")
    out["btn"] = btn
    if not btn: print(json.dumps(out,indent=2)); raise SystemExit(0)
    tab.call("Input.dispatchMouseEvent", type="mouseMoved", x=btn["x"], y=btn["y"], buttons=0); time.sleep(0.3)
    for t in ("mousePressed","mouseReleased"):
        tab.call("Input.dispatchMouseEvent", type=t, x=btn["x"], y=btn["y"], button="left",
                 clickCount=1, buttons=1 if t=="mousePressed" else 0)
    time.sleep(2.5)
    out["media_modal_open"] = ev(tab, "!!document.querySelector('.media-modal:not([style*=\"display: none\"])')")
    out["modal_title"]      = ev(tab, "(document.querySelector('.media-frame-title h1, .media-modal h1')||{}).textContent")
    out["button_label"]     = ev(tab, "(document.querySelector('.media-toolbar-primary .button-primary')||{}).textContent")
    # The real claim under test is "any mime, not just images". mode-select is
    # the SELECTION mode, not a type filter — measuring it proved nothing.
    # Read the frame's actual library query instead, and count non-image items.
    out["library_type_filter"] = ev(tab, """(() => {
      try { const st = wp.media.frame.state(); const lib = st.get('library');
            const p = lib && lib.props ? lib.props.toJSON() : {};
            return {type: p.type === undefined ? 'ANY (no type filter)' : p.type}; }
      catch(e){ return 'could not read'; } })()""")
    time.sleep(2)
    out["items_by_kind"] = ev(tab, """(() => {
      const els=[...document.querySelectorAll('.attachments .attachment')];
      let img=0, other=0;
      els.forEach(e=>{ (e.className.includes('type-image')? img++ : other++); });
      return {total:els.length, image:img, non_image:other};
    })()""")
    print(json.dumps(out, indent=2))
finally:
    tab.close()
    left = kill_probe(PROBE)
    print(f"probe deleted; ZZ PROBE rows remaining: {left}")
