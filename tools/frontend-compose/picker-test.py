#!/usr/bin/env python3
"""
picker-test.py — click the layout-v2 licence picker for real, without needing
the branch symlinked over the dev2 serve.

WHY THIS EXISTS. The serve symlinks lg-layout-v2 into loothplatformv2-clean, so
a branch's editor JS is invisible on dev2 and "click the picker" would otherwise
need a maintenance window. This builds a harness page from the REAL artefacts —
the renderer's own editor-mode output (block + <lg-edit> marker), the real
lg-fe-editor.js, the real lg-fe-editor.css, and the real
Licenses::picker_choices() — and drives it with CDP. Only the REST call is
stubbed, so the SAVE PAYLOAD is observable, which is the part that matters.

THREE TRAPS THIS FILE ENCODES, each of which produced a wrong answer first:

 1. REAL POINTER INPUT, not element.click(). The edit pill is opacity:0 /
    pointer-events:none until :hover, and CSS :hover does NOT respond to a
    dispatched MouseEvent — only to actual pointer movement. Hit-testing the
    un-hovered pill says "BLOCKED" and reads as a defect that is not there.
    Same class as the recorded "synthetic clicks cannot long-press".
 2. LET THE TRANSITION SETTLE. The pill fades in over 0.12s; sampling opacity
    immediately reads 0 and looks like an invisible-but-clickable control.
 3. RECORD THE SAVE OUTSIDE THE PAGE. On success the picker calls reload(),
    which wipes an in-page array — the first run reported "no save" when the
    save had in fact happened. sessionStorage survives. And do NOT try to
    override location.reload: it is non-configurable, the defineProperty
    throws, and the editor never boots — every assertion then goes falsely
    negative.

Usage:  python3 tools/frontend-compose/picker-test.py [--keep]
Exit:   0 all assertions pass, 1 a real failure, 3 CANNOT RUN.
"""
import json
import os
import re
import subprocess
import sys
import time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__))))
from shots import Tab, gate_env  # noqa: E402

REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
V = os.path.join(REPO, "lg-layout-v2")
PUB = "/home/ubuntu/projects/footer-mockups/frontend-compose-build"
NAME = "_harness-picker.html"


class CannotRun(Exception):
    pass


def build_harness() -> None:
    """Render the fixture in EDITOR mode and assemble the page from real parts."""
    r = subprocess.run(["php", "bin/render-test.php", "--fixture=license-minimal", "--editor"],
                       cwd=V, capture_output=True, text=True)
    out = os.path.join(V, "tests/output/license-minimal/rendered.html")
    if not os.path.isfile(out):
        raise CannotRun(f"editor-mode render produced nothing: {r.stderr[:200]}")
    html = open(out).read()
    m = re.search(r'(<lg-edit[^>]*data-lg-block-type="license"[^>]*></lg-edit>)\s*'
                  r'(<aside class="lg-license.*?</aside>)', html, re.S)
    if not m:
        raise CannotRun("no license block + marker in the editor-mode render")
    marker, block = m.group(1), m.group(2)
    bundle = open(os.path.join(V, "tests/output/license-minimal/bundle.css")).read()
    man = json.load(open(os.path.join(V, "blocks/license/manifest.json")))
    choices = subprocess.run(
        ["php", "-r", f'require_once "{V}/src/Licenses.php";'
                      r' echo json_encode(\LG\LayoutV2\Licenses::picker_choices());'],
        capture_output=True, text=True).stdout
    cfg = {
        "rest_root": "https://dev2.loothgroup.com/wp-json/lg-layout-v2/v1/",
        "nonce": "harness", "post_id": 1,
        "manifests": {"license": {"editor": man["editor"], "schema": {"props": {}},
                                  "variants": ["note", "compact"],
                                  "variant_labels": {"note": "note", "compact": "compact"}}},
        "icons": {}, "licenses": json.loads(choices or "[]"),
    }
    os.makedirs(PUB, exist_ok=True)
    for src, dst in (("assets/lg-fe-editor.js", "_h-editor.js"),
                     ("assets/lg-fe-editor.css", "_h-editor.css")):
        open(os.path.join(PUB, dst), "w").write(open(os.path.join(V, src)).read())
    page = f"""<!doctype html><meta charset="utf-8"><title>picker harness</title>
<style>{bundle}</style>
<link rel="stylesheet" href="_h-editor.css">
<body id="h" class="lg-can-edit" style="padding:40px;background:#fbfbf8">
<article class="lg-article" data-lg-v2="1">
{marker}
{block}
</article>
<script>
/* sessionStorage, not a page variable: the picker reloads on success. */
window.__saves = JSON.parse(sessionStorage.getItem('lgSaves') || '[]');
window.fetch = function(url, opts) {{
  try {{
    window.__saves.push({{url:String(url), body: opts && opts.body ? JSON.parse(opts.body) : null}});
    sessionStorage.setItem('lgSaves', JSON.stringify(window.__saves));
  }} catch(e) {{}}
  return Promise.resolve({{ok:true, json:function(){{return Promise.resolve({{ok:true}});}} }});
}};
window.LG_FE_EDITOR = {json.dumps(cfg)};
</script>
<script src="_h-editor.js"></script>
</body>"""
    open(os.path.join(PUB, NAME), "w").write(page)


def cleanup() -> None:
    for f in (NAME, "_h-editor.js", "_h-editor.css"):
        try:
            os.remove(os.path.join(PUB, f))
        except FileNotFoundError:
            pass


def ev(tab, js):
    r = tab.call("Runtime.evaluate", expression=js, returnByValue=True)
    if "exceptionDetails" in r:
        return None
    return r["result"].get("value")


def main() -> int:
    keep = "--keep" in sys.argv
    print("picker-test — the licence picker, driven with REAL pointer input")
    try:
        build_harness()
    except CannotRun as e:
        print(f"  CANNOT RUN: {e}")
        return 3

    env = gate_env()
    url = env["LG_GATE_HOST"] + f"/footer-mockups/frontend-compose-build/{NAME}"
    fails = []
    tab = Tab()
    try:
        tab.call("Page.enable"); tab.call("Network.enable")
        # Chrome caches _h-editor.css/.js by URL. Without this, editing the real
        # stylesheet and re-running silently re-tests the OLD one — a red-first
        # then "stays green" and reads as a decorative assertion when in fact the
        # harness never saw the change. Recorded trap on this box.
        tab.call("Network.setCacheDisabled", cacheDisabled=True)
        tab.call("Network.clearBrowserCookies")
        tab.call("Network.setCookies", cookies=[{
            "name": "loothdev_auth", "value": env["LG_GATE_TOKEN"],
            "domain": "." + env["LG_GATE_DOMAIN"], "path": "/", "secure": True}])
        tab.call("Emulation.setDeviceMetricsOverride", width=1280, height=1200,
                 deviceScaleFactor=1, mobile=False, screenWidth=1280, screenHeight=1200)
        tab.call("Page.navigate", url=url); time.sleep(2.5)
        ev(tab, "sessionStorage.removeItem('lgSaves')")

        if not ev(tab, "!!window.LG_FE_EDITOR_API"):
            print("  CANNOT RUN: the editor JS did not boot in the harness")
            return 3

        # [1] real hover reveals the pill
        host = ev(tab, "(() => { const h=document.querySelector('.lg-edit-host')"
                       ".getBoundingClientRect(); return {x:Math.round(h.left+h.width/2),"
                       "y:Math.round(h.top+20)}; })()")
        tab.call("Input.dispatchMouseEvent", type="mouseMoved", x=host["x"], y=host["y"], buttons=0)
        time.sleep(1.5)  # trap 2: let the 0.12s fade finish
        st = ev(tab, "(() => { const cs=getComputedStyle(document.querySelector('.lg-edit-pill'));"
                     "return {o:cs.opacity, pe:cs.pointerEvents}; })()")
        if st and st["o"] == "1" and st["pe"] == "auto":
            print("  [1] real hover reveals the pill (opacity 1, pointer-events auto)")
        else:
            fails.append(f"[1] pill not usable after hover: {st}")

        # [2] the edit button is genuinely on top where it is drawn
        btn = ev(tab, "(() => { const b=[...document.querySelectorAll('.lg-edit-pill__btn')]"
                      ".find(x=>x.className.includes('--edit')); const r=b.getBoundingClientRect();"
                      "return {x:Math.round(r.left+r.width/2), y:Math.round(r.top+r.height/2)}; })()")
        tab.call("Input.dispatchMouseEvent", type="mouseMoved", x=btn["x"], y=btn["y"], buttons=0)
        time.sleep(0.3)
        # ONE f-string: splitting it left the closing `}}` in a plain string, where
        # it stayed two literal braces and made the JS a syntax error — which
        # returns None and reads as "the button is covered". A harness bug that
        # looked exactly like a product defect.
        hit = ev(tab, f"""(() => {{
          const el = document.elementFromPoint({btn['x']}, {btn['y']});
          const b = [...document.querySelectorAll('.lg-edit-pill__btn')]
                      .find(x => x.className.includes('--edit'));
          return el === b || b.contains(el);
        }})()""")
        if hit:
            print("  [2] edit button is topmost at its own centre")
        else:
            fails.append("[2] edit button is NOT reachable — something covers it")

        # [3] a real click opens the picker with the four choices + follow
        for t in ("mousePressed", "mouseReleased"):
            tab.call("Input.dispatchMouseEvent", type=t, x=btn["x"], y=btn["y"],
                     button="left", clickCount=1, buttons=1 if t == "mousePressed" else 0)
        time.sleep(0.8)
        opts = ev(tab, "[...document.querySelectorAll('.lg-license-pop .lg-tier-pop__opt strong')]"
                       ".map(e=>e.textContent)")
        if opts and len(opts) == 5 and "Follow" in opts[0]:
            print(f"  [3] real click opens the picker: {len(opts)} choices, 'follow' first")
        else:
            fails.append(f"[3] picker did not open with the expected choices: {opts}")

        # [4] the popover stays inside the viewport
        inview = ev(tab, "(() => { const p=document.querySelector('.lg-license-pop');"
                         "if(!p) return false; const r=p.getBoundingClientRect();"
                         "return r.left>=0 && r.right<=innerWidth && r.width>0; })()")
        if inview:
            print("  [4] popover sits inside the viewport")
        else:
            fails.append("[4] popover overflows the viewport")

        # [5] the current-choice dot does not sit on the hint text
        gap = ev(tab, "(() => { const c=document.querySelector('.lg-license-pop .is-current');"
                      "if(!c) return null; const s=c.querySelector('span');"
                      "return Math.round(c.getBoundingClientRect().right - s.getBoundingClientRect().right); })()")
        if gap is not None and gap >= 20:
            print(f"  [5] current-choice dot clears the hint text ({gap}px)")
        else:
            fails.append(f"[5] the ● overlaps the hint text (clearance {gap}px)")

        # [6] a real click on a choice saves the RIGHT payload
        opt = ev(tab, "(() => { const o=[...document.querySelectorAll('.lg-license-pop .lg-tier-pop__opt')]"
                      ".find(b=>b.textContent.includes('Follow the post')); if(!o) return null;"
                      "const r=o.getBoundingClientRect();"
                      "return {x:Math.round(r.left+r.width/2), y:Math.round(r.top+r.height/2)}; })()")
        if not opt:
            fails.append("[6] could not find the 'follow the post' choice")
        else:
            tab.call("Input.dispatchMouseEvent", type="mouseMoved", x=opt["x"], y=opt["y"], buttons=0)
            for t in ("mousePressed", "mouseReleased"):
                tab.call("Input.dispatchMouseEvent", type=t, x=opt["x"], y=opt["y"],
                         button="left", clickCount=1, buttons=1 if t == "mousePressed" else 0)
            time.sleep(1.0)
            saved = ev(tab, "JSON.parse(sessionStorage.getItem('lgSaves')||'[]')"
                            ".map(s=>s.body&&s.body.props)")
            if saved and saved[-1] == {"code": ""}:
                print("  [6] a real click saves {'code': ''} — 'follow the post' clears the pin")
            else:
                fails.append(f"[6] wrong or missing save payload: {saved}")
    finally:
        tab.close()
        if not keep:
            cleanup()

    print()
    if fails:
        print("RED — the picker does not behave as claimed:")
        for f in fails:
            print(f"  ✗ {f}")
        return 1
    print("GREEN — the licence picker opens, offers the four choices, and saves correctly.")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except CannotRun as e:
        print(f"CANNOT RUN: {e}")
        sys.exit(3)
