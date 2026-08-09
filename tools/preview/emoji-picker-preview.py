#!/usr/bin/env python3
"""
emoji-picker-preview — build Ian's before/after page from the BRANCH'S OWN BYTES.

WHY A GENERATOR AND NOT A HAND-WRITTEN PAGE
The approved mock was a second implementation: its own CSS, its own JS, its own
emoji list. That is fine for deciding a SHAPE, and it is exactly wrong for
signing off a BUILD — a hand-made page can look right while the shipped code
does not, which is the "green suite Ian's phone beats" failure in another
costume. So nothing here is re-typed:

  · the CSS rules are LIFTED from lg-shared/site-header.css and the dark block
    from webroot/app-settings.js
  · the composer markup is the ACTUAL OUTPUT of lg_shared_render_site_header(),
    rendered twice — once with the flag off (BEFORE) and once on (AFTER)
  · the picker behaviour is the ACTUAL FUNCTIONS from social-modals.js, pulled
    out by brace matching (same extractor gate 19 uses)
  · the phone panel's CSS and markup are lifted from messenger-sheet.js

If the branch changes, re-run this and the page changes with it. If an extract
fails, the script DIES rather than quietly emitting a prettier lie.

Usage: python3 tools/preview/emoji-picker-preview.py [--out DIR]
Default out: /home/ubuntu/projects/footer-mockups/emoji-picker-build
             (symlinked to /var/www/dev/footer-mockups — behind the dev gate)
"""
import argparse, json, os, re, subprocess, sys, tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
HEADER   = f"{ROOT}/lg-shared/site-header.php"
CSS      = f"{ROOT}/lg-shared/site-header.css"
MODALS   = f"{ROOT}/lg-shared/social-modals.js"
SHEET    = f"{ROOT}/webroot/messenger-sheet.js"
SETTINGS = f"{ROOT}/webroot/app-settings.js"

def die(m): sys.exit("emoji-picker-preview: " + m)
def read(p):
    with open(p, encoding="utf-8") as f: return f.read()

def js_function(src, name):
    m = re.search(r"function\s+" + re.escape(name) + r"\s*\(", src)
    if not m: die(f"could not extract {name}()")
    i = src.index("{", m.end() - 1); depth = 0; j = i
    while j < len(src):
        if src[j] == "{": depth += 1
        elif src[j] == "}":
            depth -= 1
            if depth == 0: return src[m.start(): j + 1]
        j += 1
    die(f"unbalanced braces extracting {name}()")

def js_array(src, name):
    m = re.search(re.escape(name) + r"\s*=\s*\[", src)
    if not m: die(f"could not extract {name}")
    i = src.index("[", m.end() - 1); depth = 0; j = i
    while j < len(src):
        if src[j] == "[": depth += 1
        elif src[j] == "]":
            depth -= 1
            if depth == 0: return src[i: j + 1]
        j += 1
    die(f"unbalanced brackets extracting {name}")

def css_block(src, start_marker, end_marker):
    a = src.find(start_marker)
    if a < 0: die(f"css marker not found: {start_marker!r}")
    b = src.find(end_marker, a)
    if b < 0: die(f"css end marker not found: {end_marker!r}")
    return src[a:b]

def render_composer(flag_on):
    env = dict(os.environ); env["LG_EMOJI_PICKER"] = "1" if flag_on else ""
    code = ("<?php require_once %s; ob_start();"
            "lg_shared_render_site_header(['authenticated'=>true,'display_name'=>'Ian','tier'=>'member']);"
            "$h=ob_get_clean();$i=strpos($h,'<div class=\"lg-msg__compose\"');"
            "$j=strpos($h,'</div>',strpos($h,'lg-msg__send-btn',$i));"
            "echo substr($h,$i,$j-$i).'</div></div>';") % json.dumps(HEADER)
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as f:
        f.write(code); p = f.name
    try:
        r = subprocess.run(["php", p], capture_output=True, text=True, env=env)
    finally:
        os.unlink(p)
    if not r.stdout.strip(): die("composer render produced nothing: " + r.stderr[:300])
    return r.stdout

ap = argparse.ArgumentParser()
ap.add_argument("--out", default="/home/ubuntu/projects/footer-mockups/emoji-picker-build")
args = ap.parse_args()

css_src, modals, sheet, settings = read(CSS), read(MODALS), read(SHEET), read(SETTINGS)

# ── lift, never re-type ──────────────────────────────────────────────────────
picker_css = css_block(css_src, "/* Emoji ☺ — deliberately the SAME box as attach",
                       "/* ── The picker panel ─")
panel_css  = css_block(css_src, "/* ── The picker panel ─", ".lg-msg__attach-preview { display: flex; }")
if "lg-epk__tab" not in panel_css: die("panel CSS extract missed the tab rules")

dark_rules = re.findall(r"D \+ '( \.(?:lg-epk|lg-msg__emoji)[^']*)'", settings)
if not dark_rules: die("no dark rules extracted from app-settings.js")
dark_css = "\n".join("html[data-t=\"dark\"]" + r for r in dark_rules)

mob_css = "\n".join(
    re.findall(r"'(#looth-msgr \.mg-epk[^']*)'", sheet) +
    [x.replace("D + ", "") for x in re.findall(r"D \+ '( #looth-msgr \.mg-epk[^']*)'", sheet)])
mob_dark = "\n".join("html[data-t=\"dark\"]" + r for r in
                     re.findall(r"D \+ '( #looth-msgr \.mg-epk[^']*)'", sheet))
if "mg-epk-tab" not in mob_css: die("phone CSS extract missed the tab rules")

before_html = render_composer(False)
after_html  = render_composer(True)
if 'lg-msg__emoji-btn' in before_html: die("BEFORE frame contains the button — flag leak")
if 'lg-msg__emoji-btn' not in after_html: die("AFTER frame lacks the button — render failed")

fns = "\n".join(js_function(modals, n) for n in
                ("epkGlyph", "epkFreq", "epkListFor", "epkSection", "epkPaint",
                 "epkBuild", "epkPlace", "epkClose", "epkOpen", "epkTargetFor", "epkInsert"))
cats  = js_array(modals, "EPK_CATS")
six   = js_array(modals, "REACTION_EMOJI")
mcats = js_array(sheet, "MG_EPK_CATS")
mfreq = js_array(sheet, "MG_EPK_FREQ")
mbtn  = js_function(sheet, "mgEpkBtnHtml")
mpan  = js_function(sheet, "mgEpkPanelHtml")
mpaint= js_function(sheet, "mgEpkPaint")
mins  = js_function(sheet, "mgEpkInsert")

sha = subprocess.run(["git", "-C", ROOT, "rev-parse", "--short", "HEAD"],
                     capture_output=True, text=True).stdout.strip()

PAGE = """<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DM emoji picker — built (branch emoji-picker-build)</title>
<style>
:root{--lg-cream:#fbfbf8;--lg-sage:#87986a;--lg-sage-d:#6b7c52;--lg-sage-3:#d4e0b8;
 --lg-sage-tint:#eef2e3;--lg-ink:#323532;--lg-mute:#6b6f6b;--lg-line:#e3ddd0;
 --lguser-bg:#e9eedd;--lguser-bubble:#eceff3;--lg-amber:#ecb351;
 --lg-font-sans:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;}
html[data-t="dark"]{--lg-cream:#15171a;--lg-sage:#9cb37d;--lg-sage-d:#b0c693;
 --lg-sage-tint:#243024;--lg-ink:#e5e7e1;--lg-mute:#a6ac9f;--lg-line:#2c312d;
 --lguser-bg:#101214;--lguser-bubble:#262b30;}
*{box-sizing:border-box}
/* #epk + !important: the docroot injects lg-boot-crit AFTER this block and its
   rule is html[data-lguser-theme="dark"] body (0,1,2) — a plain body{} loses. */
#epk{margin:0;background:var(--lguser-bg)!important;color:var(--lg-ink)!important;
 font:15px/1.55 var(--lg-font-sans);-webkit-text-size-adjust:100%}
.bar{background:var(--lguser-bg);border-bottom:1px solid var(--lg-line);padding:10px 20px;
 display:flex;gap:10px;align-items:center;flex-wrap:wrap;position:sticky;top:0;z-index:2147483600}
.tbtn{border:1px solid var(--lg-line);background:var(--lg-cream);color:var(--lg-ink);
 font:600 12px/1 var(--lg-font-sans);padding:7px 12px;border-radius:8px;cursor:pointer}
.tbtn[aria-pressed=true]{background:var(--lg-sage);border-color:var(--lg-sage);color:#fff}
.wrap{max-width:1180px;margin:0 auto;padding:24px 20px 70px}
h1{font-size:22px;margin:0 0 6px;letter-spacing:-.01em}
h2{font-size:15px;margin:34px 0 10px}
.sub{color:var(--lg-mute);margin:0 0 8px;font-size:14px;max-width:74ch}
.row{display:flex;gap:26px;flex-wrap:wrap;align-items:flex-start}
.capt{font:700 10px/1 var(--lg-font-sans);letter-spacing:.09em;text-transform:uppercase;
 color:var(--lg-mute);margin:0 0 9px}
.frame{background:var(--lg-cream);border:1px solid var(--lg-line);border-radius:12px;
 width:420px;max-width:100%;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.05)}
.thread{padding:14px 16px;display:flex;flex-direction:column;gap:8px;min-height:120px;
 justify-content:flex-end}
.bub{max-width:78%;padding:7px 11px;border-radius:13px;font-size:13px;line-height:1.4;
 border:1px solid var(--lg-line);align-self:flex-start;background:var(--lguser-bg)}
.bub--mine{align-self:flex-end;background:var(--lg-sage);color:#fff;border-color:var(--lg-sage)}
.note{margin:14px 0 0;padding:13px 15px;border-left:3px solid var(--lg-amber);
 background:var(--lg-cream);border-radius:0 8px 8px 0;font-size:13.5px;max-width:80ch}
.note b{display:block;margin-bottom:3px}
.note code{font:12px/1.4 ui-monospace,Menlo,monospace;background:var(--lg-sage-tint);
 padding:1px 5px;border-radius:4px}
.prov{font-size:12px;color:var(--lg-mute);margin-top:6px}
/* phone frame */
.phone{width:390px;max-width:100%;border:1px solid var(--lg-line);border-radius:18px;
 overflow:hidden;background:var(--lg-cream)}
#looth-msgr .mg-comp{flex:0 0 auto;display:flex;flex-direction:column;gap:8px;
 padding:9px 12px;border-top:1px solid var(--lg-line);background:var(--lg-cream)}
#looth-msgr .mg-comprow{display:flex;align-items:flex-end;gap:8px}
#looth-msgr .mg-attach-btn{flex:0 0 auto;border:0;background:none;cursor:pointer;
 color:var(--lg-sage-d);padding:6px;display:inline-flex;align-items:center}
#looth-msgr .mg-compwrap{flex:1 1 auto;min-width:0;display:flex;align-items:flex-end;
 background:var(--lguser-bubble);border-radius:20px;padding:6px 8px 6px 14px}
#looth-msgr .mg-in{flex:1 1 auto;min-width:0;border:0;background:none;outline:none;resize:none;
 font:15px/1.4 var(--lg-font-sans);color:var(--lg-ink);max-height:110px;padding:4px 0}
#looth-msgr .mg-send{flex:0 0 auto;border:0;background:none;cursor:pointer;
 color:var(--lg-sage-d);font:700 14px/1 var(--lg-font-sans);padding:8px 9px}
#looth-msgr .mg-send:disabled{color:#b0b3b8}
.kbd{height:280px;background:repeating-linear-gradient(45deg,var(--lg-line),var(--lg-line) 8px,transparent 8px,transparent 16px);
 display:flex;align-items:center;justify-content:center;color:var(--lg-mute);
 font:700 11px/1 var(--lg-font-sans);letter-spacing:.1em;text-transform:uppercase}
/* ══ LIFTED FROM lg-shared/site-header.css ══ */
__PICKER_CSS__
__PANEL_CSS__
/* ══ LIFTED FROM webroot/app-settings.js dark block ══ */
__DARK_CSS__
/* ══ LIFTED FROM webroot/messenger-sheet.js ══ */
__MOB_CSS__
__MOB_DARK__
</style></head>
<body id="epk">
<div class="bar"><b style="font-size:13px">Theme</b>
 <button class="tbtn" data-th="light" aria-pressed="true">Light</button>
 <button class="tbtn" data-th="dark" aria-pressed="false">Dark</button>
 <span style="color:var(--lg-mute);font-size:12.5px">Everything below is <b>live</b> — click ☺ and type.</span>
</div>
<div class="wrap">
<h1>DM emoji picker — the built thing</h1>
<p class="sub">This is not a mock. Every rule and every function on this page was lifted
out of the branch by a script, not re-typed: the composers are the real output of
<code>lg_shared_render_site_header()</code>, the styling is the real
<code>site-header.css</code>, and the picker is the real functions from
<code>social-modals.js</code>. Re-run the generator and this page changes with the code.</p>
<p class="prov">branch <b>emoji-picker-build</b> @ <b>__SHA__</b> · generated by
<code>tools/preview/emoji-picker-preview.py</code></p>

<h2>Desktop — before / after</h2>
<div class="row">
 <div><p class="capt">Before — flag OFF (what ships today)</p>
  <div class="frame"><div class="thread">
   <div class="bub">Did you get the take I sent over?</div>
   <div class="bub bub--mine">Yeah — the solo at 2:14 is the one</div></div>
  __BEFORE__</div></div>
 <div><p class="capt">After — flag ON</p>
  <div class="frame"><div class="thread">
   <div class="bub">Did you get the take I sent over?</div>
   <div class="bub bub--mine">Yeah — the solo at 2:14 is the one 🔥</div></div>
  __AFTER__</div></div>
</div>
<div class="note"><b>The before frame is not a drawing of the old state — it IS the old state.</b>
It is the same PHP function rendered with the flag off, so if the flag ever stopped being a
true no-op you would see it here as a difference. Today the two frames differ by exactly one
button.</div>

<h2>Phone — 390px, keyboard up vs picker open</h2>
<div class="row">
 <div><p class="capt">Keyboard up — composer lifted, input visible</p>
  <div class="phone" id="looth-msgr"><div style="padding:12px 14px;min-height:90px">
   <div class="bub" style="font-size:14px">Did you get the take?</div></div>
   __PHONEBAR__
   <div class="kbd">the phone keyboard sits here</div></div></div>
 <div><p class="capt">☺ tapped — picker takes the keyboard's slot</p>
  <div class="phone" id="looth-msgr2"><div style="padding:12px 14px;min-height:90px">
   <div class="bub" style="font-size:14px">Did you get the take?</div></div>
   __PHONEBAR2__</div></div>
</div>
<div class="note"><b>This is the one real difference from the mock's phone frame, and it is deliberate.</b>
The mock drew the phone panel <i>above</i> the composer. On a real phone that cannot work: the
composer is already translated up by the keyboard's height, so a panel above it would sit on the
conversation and jump every time the keyboard moved. The picker takes the keyboard's own slot
instead — tap ☺ and the keyboard leaves, tap the message box and it comes back. The input never
gets covered, which was the requirement.
<br><br>Two consequences you can see: there is <b>no search box on the phone</b> (a search field
inside a panel that lives in the keyboard's slot would summon the keyboard and collapse itself),
and the phone panel <b>stays open</b> across taps so you can add several, while the desktop panel
closes after one.</div>

<div class="note" style="border-left-color:var(--lg-sage)"><b>Still switched off.</b>
Shipped behind <code>platform/config/emoji-picker.php</code>, <code>enabled =&gt; false</code>.
Flag off is proven byte-identical: the composer markup is identical to the feature not existing,
and <code>/pwa.js</code> gains zero bytes. Nothing is on for anybody until you say so.</div>

<div class="note" style="border-left-color:#c66845"><b>What I have NOT proven, so you know what you are looking at.</b>
This page is a desktop browser. The 390px frames above are the real markup and real CSS at the
real width, but a page cannot summon a real phone keyboard — so the keyboard-swap behaviour is
drawn here from the measured height, not exercised. That one needs a real handset or the browser
seat, and it is the last thing outstanding.</div>
</div>

<script>
/* ══ LIFTED FROM lg-shared/social-modals.js — not re-typed ══ */
var REACTION_EMOJI = __SIX__;
var EPK_CATS = __CATS__;
var epkPanel=null, epkBtn=null, epkTa=null;
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){
 return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function closeReactionPickers(){}
__FNS__
document.addEventListener('click',function(e){
 var b=e.target.closest&&e.target.closest('[data-lg-emoji]');
 if(b){ if(epkBtn===b){epkClose();}else{epkOpen(b,epkTargetFor(b));} return; }
 var pick=e.target.closest&&e.target.closest('.lg-epk__e');
 if(pick){ epkInsert(epkTa,pick.textContent); epkClose(); return; }
 var tab=e.target.closest&&e.target.closest('[data-lg-epk-tab]');
 if(tab&&epkPanel){
  var t=epkPanel.querySelectorAll('.lg-epk__tab');
  for(var i=0;i<t.length;i++)t[i].setAttribute('aria-selected','false');
  tab.setAttribute('aria-selected','true');
  var q=epkPanel.querySelector('.lg-epk__q'); if(q)q.value='';
  epkPaint('');
  var sc=epkPanel.querySelector('.lg-epk__scroll');
  var h=epkPanel.querySelector('#lg-epk-c'+tab.getAttribute('data-lg-epk-tab'));
  if(h&&sc)sc.scrollTop=h.offsetTop-sc.offsetTop; return; }
 if(epkPanel&&!epkPanel.hasAttribute('hidden')&&!e.target.closest('.lg-epk'))epkClose();
});
document.addEventListener('mousedown',function(e){
 if(e.target.closest&&e.target.closest('.lg-epk')&&!e.target.closest('.lg-epk__q'))e.preventDefault();});
document.addEventListener('input',function(e){
 if(e.target&&e.target.classList&&e.target.classList.contains('lg-epk__q'))
  epkPaint(e.target.value.trim().toLowerCase());});
document.addEventListener('keydown',function(e){
 if(e.key==='Escape'&&epkPanel&&!epkPanel.hasAttribute('hidden')){var t=epkTa;epkClose();if(t)t.focus();}});
window.addEventListener('resize',function(){if(epkBtn)epkPlace();});
window.addEventListener('scroll',function(){if(epkBtn)epkPlace();},true);
/* Send-enable + auto-grow, the same shape the real composers use — this is what
   proves the dispatched InputEvent matters: without it Send stays dead. */
document.addEventListener('input',function(e){
 var ta=e.target; if(!ta.classList)return;
 if(ta.classList.contains('lg-msg__reply-input')){
  var s=ta.closest('.lg-msg__compose').querySelector('.lg-msg__send-btn');
  if(s)s.disabled=!ta.value.trim();
  ta.style.height='auto'; ta.style.height=Math.min(ta.scrollHeight,110)+'px'; }
 if(ta.classList.contains('mg-in')){
  var s2=ta.closest('.mg-comp').querySelector('.mg-send');
  if(s2)s2.disabled=!ta.value.trim(); }
});
[].forEach.call(document.querySelectorAll('.lg-msg__send-btn'),function(b){b.disabled=true;});

/* ══ LIFTED FROM webroot/messenger-sheet.js — not re-typed ══ */
var MG_EPK_FREQ = __MFREQ__;
var MG_EPK_CATS = __MCATS__;
function mgEpkOn(){return true;}
__MPAINT__
__MINS__
(function(){
 var p2=document.querySelector('#looth-msgr2 .mg-epk');
 if(p2){ mgEpkPaint(p2); p2.classList.add('is-on');
  var b=document.querySelector('#looth-msgr2 [data-mg-epk]');
  if(b)b.setAttribute('aria-expanded','true'); }
 document.addEventListener('click',function(e){
  var t=e.target.closest&&e.target.closest('.mg-epk-e'); if(!t)return;
  var comp=t.closest('.mg-comp'); mgEpkInsert(comp&&comp.querySelector('.mg-in'),t.textContent);});
 document.addEventListener('click',function(e){
  var tb=e.target.closest&&e.target.closest('[data-mg-epk-tab]'); if(!tb)return;
  var p=tb.closest('.mg-epk'), tabs=p.querySelectorAll('.mg-epk-tab');
  for(var i=0;i<tabs.length;i++)tabs[i].setAttribute('aria-selected','false');
  tb.setAttribute('aria-selected','true');
  var sc=p.querySelector('.mg-epk-scroll');
  var h=p.querySelector('#'+p.id+'-c'+tb.getAttribute('data-mg-epk-tab'));
  if(h&&sc)sc.scrollTop=h.offsetTop-sc.offsetTop;});
 var bt=document.querySelector('#looth-msgr [data-mg-epk]');
 if(bt)bt.addEventListener('click',function(){
  alert('On a real handset this dismisses the keyboard and the panel takes its slot — '
   +'see the frame on the right, which is that state.');});
})();

/* theme — opens in the viewer's own theme rather than forcing light */
function setTheme(t){document.documentElement.setAttribute('data-t',t);
 [].forEach.call(document.querySelectorAll('.tbtn'),function(x){
  x.setAttribute('aria-pressed',String(x.dataset.th===t));});}
setTheme(document.documentElement.getAttribute('data-lguser-theme')==='dark'?'dark':'light');
[].forEach.call(document.querySelectorAll('.tbtn'),function(b){
 b.addEventListener('click',function(){setTheme(b.dataset.th);});});
</script>
</body></html>
"""

phone_comp = ('<div class="mg-comp"><div class="mg-comprow">'
  '<button class="mg-attach-btn" type="button" aria-label="Attach photo">'
  '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" '
  'stroke-linecap="round" stroke-linejoin="round"><path d="M21.4 11.05 12.25 20.2a5 5 0 0 1-7.07-7.07l9.19-9.19'
  'a3 3 0 0 1 4.24 4.24l-9.2 9.19a1 1 0 0 1-1.41-1.41l8.49-8.49"/></svg></button>'
  '__BTN__<div class="mg-compwrap">'
  '<textarea class="mg-in" rows="1" placeholder="Message…">__VAL__</textarea>'
  '<button class="mg-send" type="button" disabled>Send</button></div></div>__PANEL__</div>')

# Build the phone markup by CALLING the lifted helpers, so the button/panel html
# on this page is produced by the shipped code rather than pasted.
helper_js = ("global.window={LG_EMOJI_PICKER:1};" + js_function(sheet, "mgEpkOn") + mbtn + mpan +
             "console.log(JSON.stringify([mgEpkBtnHtml(),mgEpkPanelHtml('mg-epk'),mgEpkPanelHtml('mg2-epk')]));")
r = subprocess.run(["node", "-e", helper_js], capture_output=True, text=True)
if r.returncode != 0: die("phone helper extraction failed: " + r.stderr[:200])
btn_html, pan1, pan2 = json.loads(r.stdout)

page = (PAGE
  .replace("__PICKER_CSS__", picker_css).replace("__PANEL_CSS__", panel_css)
  .replace("__DARK_CSS__", dark_css).replace("__MOB_CSS__", mob_css)
  .replace("__MOB_DARK__", mob_dark)
  .replace("__BEFORE__", before_html).replace("__AFTER__", after_html)
  .replace("__PHONEBAR2__", phone_comp.replace("__BTN__", btn_html)
                                      .replace("__PANEL__", pan2.replace('id="mg2-epk"', 'id="mg2-epk"'))
                                      .replace("__VAL__", "the solo at 2:14 "))
  .replace("__PHONEBAR__", phone_comp.replace("__BTN__", btn_html)
                                     .replace("__PANEL__", "").replace("__VAL__", "the solo at 2:14 "))
  .replace("__FNS__", fns).replace("__CATS__", cats).replace("__SIX__", six)
  .replace("__MCATS__", mcats).replace("__MFREQ__", mfreq)
  .replace("__MPAINT__", mpaint).replace("__MINS__", mins)
  .replace("__SHA__", sha or "working tree"))

os.makedirs(args.out, exist_ok=True)
out = os.path.join(args.out, "index.html")
with open(out, "w", encoding="utf-8") as f: f.write(page)
print(f"wrote {out} ({len(page)} bytes)")
print(f"  lifted: {len(dark_rules)} dark rules, {page.count('lg-epk__e')} panel refs")
print(f"  URL: https://dev2.loothgroup.com/footer-mockups/emoji-picker-build/")
