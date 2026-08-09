#!/usr/bin/env python3
"""
emoji-picker-gate — THE DM COMPOSER'S EMOJI PICKER: OFF IS A NO-OP, AND THE FOUR
COMPOSERS CANNOT DISAGREE.

Ruling: docs/IAN-RULINGS-2026-08-03.md §2 — "DM emoji picker — Variant 1".

WHY THIS GATE, AND WHAT THE EXISTING ONES CANNOT SEE
────────────────────────────────────────────────────
messages-longpress-react-gate and react-controls-reachable-gate both cover the
REACTION surface, and both are held out of run-all because they need a
cookie-carrying browser proxy. Neither can see the COMPOSER, and neither asserts
anything about a flag. This gate is deliberately browser-free so it runs in the
sequence rather than joining the held-out pile — everything it checks is
decidable from the served bytes, the rendered PHP, and the database.

The browser half (390px, keyboard up, both themes) is NOT here and is NOT
claimed. It is Ian-facing verification on a lane preview, because a synthetic
click cannot summon a soft keyboard and a gate that pretended otherwise would be
asserting the one thing it cannot measure.

EVERY ASSERTION IS PER-STATE, NEVER HARDCODED TO TODAY'S DEFAULT
────────────────────────────────────────────────────────────────
The feature ships OFF. A gate that asserted "the button is absent" would go RED
the day Ian flips it on and would block every lane until someone edited this
file. So each assertion reads the flag and checks the state it implies:
absent/OFF => no nodes anywhere; ON => the nodes exist on all four composers.
Flipping the default needs no edit here.

THE ABSENCE HALF IS PAIRED WITH A LIVENESS HALF, EVERY TIME
────────────────────────────────────────────────────────────
"No emoji button when off" is trivially true on a box where the composer never
rendered at all. So every OFF assertion is run against an ON render of the same
code in the same process, and the gate reports DEAD rather than green if the ON
state fails to produce what OFF is being congratulated for lacking.

THE DEFECT CLASSES IT PINS
──────────────────────────
 1. OFF LEAKING BYTES. An indented `<?php if ?>` emits its own leading whitespace
    even when the branch is skipped — the OFF composer differed from pre-feature
    main by 24 stray spaces until this was caught. Asserted by rendering the file
    with the emoji block MECHANICALLY STRIPPED and comparing to the OFF render,
    so the check maintains itself instead of pinning a snapshot that rots.
 2. VOCABULARY DRIFT. The emoji list is duplicated in social-modals.js and
    messenger-sheet.js on purpose (no shared module, and a third file would add a
    webroot symlink — a deploy coupling a plain pull does not handle). Asserted
    identical glyph-for-glyph, so drift is a red gate and not a phone offering
    emoji the desktop does not.
 3. THE DEAD SEND BUTTON. `.value = x` does not fire `input`, and both composers
    compute Send-enabled AND auto-grow height in that handler. An insert that
    skips the event leaves Send disabled on a composer that visibly has text in
    it. Asserted by EXECUTING the shipped insert functions against a fake
    textarea — not by grepping for "InputEvent", because a stale citation reads
    as working code.
 4. REACTIONS DRAGGED ALONG. Ian ruled against a picker on the reaction surface
    (2026-07-13). The composer's "Frequently used" row must equal the reaction
    set, and the reaction set must still equal Messaging::REACTION_EMOJI.
 5. EMOJI NOT SURVIVING STORAGE. Round-tripped through the app's own PDO
    construction against the real table, in a rolled-back transaction.

Run:   python3 tools/gates/emoji-picker-gate.py [--verbose]
Needs: php CLI; sudo -n -u profile-app psql/php for the storage phase (skipped
       with an explicit note, never silently, if unavailable).

Exit:  0 green, 1 RED (real findings), 2 CANNOT RUN (no verdict) — run-all.sh
       convention. An open defect is exit 1; a missing environment is exit 2.
"""
import json, os, re, subprocess, sys, tempfile

NO_VERDICT = 2
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))

CONFIG   = os.path.join(ROOT, "platform", "config", "emoji-picker.php")
LOADER   = os.path.join(ROOT, "webroot", "pwa-loader.php")
HEADER   = os.path.join(ROOT, "lg-shared", "site-header.php")
MODALS   = os.path.join(ROOT, "lg-shared", "social-modals.js")
SHEET    = os.path.join(ROOT, "webroot", "messenger-sheet.js")
SETTINGS = os.path.join(ROOT, "webroot", "app-settings.js")
MESSAGING= os.path.join(ROOT, "profile-app", "src", "Messaging.php")

passes = failures = 0
notes  = []

def log(*a): print(" ".join(str(x) for x in a), flush=True)

def check(label, got, want):
    global passes, failures
    ok = got == want
    if ok: passes += 1
    else:  failures += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + ("" if ok else f"\n           got={got!r}\n          want={want!r}"))
    return ok

def dead(why):
    log(f"\n  CANNOT RUN: {why}")
    log(f"\n  {passes} passed, {failures} failed before the environment gave out.")
    sys.exit(NO_VERDICT)

def read(p):
    with open(p, encoding="utf-8") as f: return f.read()

# ── tiny JS surgery: pull a function's SOURCE out by brace matching ───────────
# Executing the shipped text is the point. A grep for "InputEvent" would pass on
# a comment, and a file:line citation goes stale the moment the file moves.
def js_function(src, name):
    m = re.search(r"function\s+" + re.escape(name) + r"\s*\(", src)
    if not m: return None
    i = src.index("{", m.end() - 1)
    depth, j = 0, i
    while j < len(src):
        c = src[j]
        if c == "{": depth += 1
        elif c == "}":
            depth -= 1
            if depth == 0: return src[m.start(): j + 1]
        j += 1
    return None

def node_eval(script):
    r = subprocess.run(["node", "-e", script], capture_output=True, text=True)
    if r.returncode != 0:
        return None, (r.stderr or "").strip()
    return r.stdout.strip(), None

def php_render(code, env=None):
    e = dict(os.environ); e.update(env or {})
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as f:
        f.write(code); path = f.name
    try:
        r = subprocess.run(["php", path], capture_output=True, text=True, env=e)
        return r.stdout, r.stderr
    finally:
        os.unlink(path)

# ═════════════════════════════════════════════════════════════════════════════
log("=== emoji-picker gate — composer picker, flag discipline, no drift ===\n")

for p in (CONFIG, LOADER, HEADER, MODALS, SHEET, SETTINGS):
    if not os.path.exists(p): dead(f"missing {os.path.relpath(p, ROOT)}")
if subprocess.run(["which", "php"], capture_output=True).returncode != 0:
    dead("php CLI not on PATH")
if subprocess.run(["which", "node"], capture_output=True).returncode != 0:
    dead("node not on PATH")

# ── phase 1: the flag itself ─────────────────────────────────────────────────
log("[1] the tracked config is the single source of truth")

cfg_out, _ = php_render(f"<?php $c = require {json.dumps(CONFIG)}; var_export($c);")
if "enabled" not in cfg_out: dead("config did not return an array with 'enabled'")
FLAG_ON = "'enabled' => true" in cfg_out
log(f"       shipped state: enabled={'true' if FLAG_ON else 'false'}  (assertions follow this, not a hardcode)")

check("config returns a bool 'enabled'", ("true" in cfg_out or "false" in cfg_out), True)

# pwa-loader: the browser-facing half.
loader_probe = (
    "<?php $_SERVER['HTTP_IF_NONE_MATCH']='';ob_start();"
    f"include {json.dumps(LOADER)};$o=ob_get_clean();"
    "echo substr_count($o,'window.LG_EMOJI_PICKER=1;');"
)
off_env = {"LG_EMOJI_PICKER": ""}
n_default, _ = php_render(loader_probe)
n_on_env, _  = php_render(loader_probe, {"LG_EMOJI_PICKER": "1"})

check("pwa-loader emits the global iff the config says so",
      n_default.strip(), "1" if FLAG_ON else "0")
check("getenv override turns it on (CLI harness / pool path)", n_on_env.strip(), "1")

srv_probe = (
    "<?php $_SERVER['HTTP_IF_NONE_MATCH']='';$_SERVER['LG_EMOJI_PICKER']='1';ob_start();"
    f"include {json.dumps(LOADER)};$o=ob_get_clean();"
    "echo substr_count($o,'window.LG_EMOJI_PICKER=1;');"
)
n_srv, _ = php_render(srv_probe)
# A fastcgi_param lands in $_SERVER but NOT reliably in getenv(). Reading only
# getenv() serves the OFF path on the very preview URL built for Ian to click.
check("$_SERVER override turns it on (nginx lane-preview path)", n_srv.strip(), "1")

# ── phase 2: OFF adds no bytes to the served asset ───────────────────────────
log("\n[2] flag OFF adds ZERO bytes to /pwa.js  (byte-identity, not vibes)")

len_probe = (
    "<?php $_SERVER['HTTP_IF_NONE_MATCH']='';ob_start();"
    f"include {json.dumps(LOADER)};echo strlen(ob_get_clean());"
)
len_off, _ = php_render(len_probe, off_env)
len_on, _  = php_render(len_probe, {"LG_EMOJI_PICKER": "1"})
try:
    d = int(len_on) - int(len_off)
except ValueError:
    dead("could not measure /pwa.js length")
check("ON is exactly the global longer than OFF", d, len("window.LG_EMOJI_PICKER=1;\n"))
# Liveness: if the ON/OFF lengths matched, the comparison above would be vacuous.
check("…and that difference is non-zero (comparison is live)", d > 0, True)

# ── phase 3: the OFF render leaks no whitespace ──────────────────────────────
log("\n[3] the OFF composer is byte-identical to the feature not existing")

render = (
    "<?php require_once %s; ob_start();"
    "lg_shared_render_site_header(['authenticated'=>true,'display_name'=>'G','tier'=>'member']);"
    "$h=ob_get_clean();"
    "$i=strpos($h,'id=\"lg-msg-compose\"');"
    "$j=strpos($h,'</div>',strpos($h,'lg-msg__send-btn',$i));"
    "echo substr($h,$i,$j-$i);"
)
off_html, err = php_render(render % json.dumps(HEADER), off_env)
on_html,  _   = php_render(render % json.dumps(HEADER), {"LG_EMOJI_PICKER": "1"})
if not off_html.strip(): dead("site-header.php rendered no composer: " + (err or "")[:200])

# Mechanically strip the emoji block and render THAT — the "feature does not
# exist" baseline, regenerated every run so it cannot rot like a snapshot.
hdr = read(HEADER)
# ⚠️ The [ \t]* on BOTH tags is load-bearing, and red-first is what proved it. A
# regex anchored at `<?php` leaves the tag's own LEADING INDENTATION in the
# baseline — so the baseline reproduces the whitespace leak this assertion exists
# to catch, and the comparison passes on the defect. Mutation "indented <?php if"
# was MISSED until this ate the indentation too.
stripped = re.sub(r"[ \t]*<\?php if \(\$emoji_picker\).*?[ \t]*<\?php endif; \?>[ \t]*\n",
                  "", hdr, flags=re.S)
if stripped == hdr: dead("could not locate the emoji block to strip — gate cannot form a baseline")
# ⚠️ The baseline copy MUST live beside the real file, not in /tmp: site-header.php
# does `require_once __DIR__ . '/impact-tag.php'`, so a copy in /tmp fatals and
# renders a truncated page. The first draft of this gate did exactly that and the
# resulting "OFF differs from baseline" was the GATE's bug, not the feature's —
# the same __DIR__-through-a-symlink trap that has bitten the docroot before.
base_path = os.path.join(os.path.dirname(HEADER), ".emoji-picker-gate-baseline.php")
with open(base_path, "w", encoding="utf-8") as f:
    f.write(stripped.replace("lg_shared_render_site_header", "lg_shared_render_site_header_baseline")
                    .replace("lg_shared_emoji_picker_enabled", "lg_shared_emoji_picker_enabled_baseline"))
try:
    base_html, berr = php_render(
        render.replace("lg_shared_render_site_header", "lg_shared_render_site_header_baseline")
        % json.dumps(base_path), off_env)
finally:
    os.unlink(base_path)

if not base_html.strip(): dead("baseline render failed: " + (berr or "")[:200])
check("OFF composer == composer with the feature code removed", off_html, base_html)
check("…and ON differs from that baseline (not vacuous)", on_html != base_html, True)

n_off = off_html.count('class="lg-msg__emoji-btn"')
n_on  = on_html.count('class="lg-msg__emoji-btn"')
check("OFF renders no emoji button", n_off, 0)
check("ON renders exactly one on the modal composer", n_on, 1)

# The shipped state must match the shipped flag — this is the assertion that
# would catch someone flipping the config without looking at the surface.
shipped_html, _ = php_render(render % json.dumps(HEADER))
check("shipped render agrees with the shipped flag",
      shipped_html.count('class="lg-msg__emoji-btn"'), 1 if FLAG_ON else 0)

# ── phase 4: anon never receives a composer at all ───────────────────────────
log("\n[4] anon never receives the composer (craft: composers are never for anon)")
anon_render = (
    "<?php require_once %s; ob_start();"
    "lg_shared_render_site_header(['authenticated'=>false]);"
    "$h=ob_get_clean();"
    "echo substr_count($h,'id=\"lg-msg-compose\"'),'|',substr_count($h,'lg-msg__emoji-btn');"
) % json.dumps(HEADER)
anon_on, _ = php_render(anon_render, {"LG_EMOJI_PICKER": "1"})
check("anon gets no composer and no emoji button even with the flag ON", anon_on.strip(), "0|0")

# ── phase 5: the four composers cannot disagree ──────────────────────────────
log("\n[5] one vocabulary, four composers — no drift between the two files")

modals, sheet = read(MODALS), read(SHEET)

def glyphs_from(src, varname):
    m = re.search(re.escape(varname) + r"\s*=\s*\[", src)
    if not m: return None
    i = src.index("[", m.end() - 1)
    depth, j = 0, i
    while j < len(src):
        if src[j] == "[": depth += 1
        elif src[j] == "]":
            depth -= 1
            if depth == 0: break
        j += 1
    block = src[i:j + 1]
    # first token of every quoted "<emoji> kw kw" entry
    return [s.split(" ")[0] for s in re.findall(r"'([^']*?)'", block) if " " in s]

d_glyphs = glyphs_from(modals, "EPK_CATS")
m_glyphs = glyphs_from(sheet, "MG_EPK_CATS")
if d_glyphs is None or m_glyphs is None:
    dead("could not parse the emoji vocabulary out of one of the two files")
check("desktop vocabulary is non-trivial", len(d_glyphs) > 150, True)
check("phone vocabulary == desktop vocabulary, glyph for glyph", m_glyphs, d_glyphs)

# The six, in three places, must be one set.
rx_js = re.search(r"REACTION_EMOJI\s*=\s*\[([^\]]*)\]", modals)
rx_mo = re.search(r"MG_EPK_FREQ\s*=\s*\[([^\]]*)\]", sheet)
rx_ph = re.search(r"REACTION_EMOJI\s*=\s*\[([^\]]*)\]", read(MESSAGING)) if os.path.exists(MESSAGING) else None
def six(m): return re.findall(r"'([^']+)'", m.group(1)) if m else None
check("phone 'Frequently used' == the reaction six", six(rx_mo), six(rx_js))
if rx_ph:
    check("…and the reaction six still == Messaging::REACTION_EMOJI (server)", six(rx_js), six(rx_ph))
else:
    notes.append("Messaging.php not present in this tree — server-side six not cross-checked")

# ── phase 6: the insert contract, EXECUTED ───────────────────────────────────
log("\n[6] insert lands at the caret AND fires input (the dead-Send class)")

for label, path, fn in (("desktop", MODALS, "epkInsert"), ("phone", SHEET, "mgEpkInsert")):
    src = js_function(read(path), fn)
    if not src:
        failures += 1; log(f"  FAIL  {label}: could not extract {fn}()"); continue
    harness = src + """
var fired = 0, ta = {
  value: 'ab', selectionStart: 1, selectionEnd: 1,
  setRangeText: function (t, s, e, mode) {
    this.value = this.value.slice(0, s) + t + this.value.slice(e);
    this.selectionStart = this.selectionEnd = s + t.length;
  },
  dispatchEvent: function (ev) { if (ev && ev.type === 'input') fired++; return true; },
  focus: function () {}
};
global.InputEvent = function (type, o) { this.type = type; this.bubbles = !!(o && o.bubbles); };
""" + fn + """(ta, '\\u{1F3B8}');
console.log(JSON.stringify({ value: ta.value, fired: fired, caret: ta.selectionStart }));
"""
    out, err = node_eval(harness)
    if out is None:
        failures += 1; log(f"  FAIL  {label}: harness threw: {err[:160]}"); continue
    r = json.loads(out)
    check(f"{label}: glyph lands AT THE CARET, not at the end", r["value"], "a\U0001F3B8b")
    check(f"{label}: an 'input' event is dispatched (Send re-enables)", r["fired"], 1)
    check(f"{label}: caret ends AFTER the inserted glyph", r["caret"], 3)

# ── phase 7: the phone markup helpers are flag-gated, EXECUTED ───────────────
log("\n[7] phone markup helpers emit nothing when the flag is off")

on_fn  = js_function(sheet, "mgEpkOn")
btn_fn = js_function(sheet, "mgEpkBtnHtml")
pan_fn = js_function(sheet, "mgEpkPanelHtml")
if not (on_fn and btn_fn and pan_fn):
    dead("could not extract the phone markup helpers")
harness = f"""
global.window = {{}};
{on_fn}
{btn_fn}
{pan_fn}
var off = [mgEpkBtnHtml(), mgEpkPanelHtml('x')];
window.LG_EMOJI_PICKER = 1;
var on = [mgEpkBtnHtml(), mgEpkPanelHtml('x')];
window.LG_EMOJI_PICKER = true;           // a truthy NON-1 must still be off
var loose = [mgEpkBtnHtml(), mgEpkPanelHtml('x')];
console.log(JSON.stringify({{off: off, on: on, loose: loose}}));
"""
out, err = node_eval(harness)
if out is None: dead("phone helper harness threw: " + (err or "")[:160])
r = json.loads(out)
check("flag off  => button html is empty", r["off"][0], "")
check("flag off  => panel html is empty", r["off"][1], "")
check("flag on   => button html exists (liveness)", "data-mg-epk" in r["on"][0], True)
check("flag on   => panel html exists (liveness)", "mg-epk" in r["on"][1], True)
# Fail-closed: anything but an explicit 1 is off, so a stray truthy cannot expose it.
check("a truthy non-1 flag stays OFF (fail-closed)", r["loose"], ["", ""])

# ── phase 8: the dark pass reaches the new classes ───────────────────────────
log("\n[8] the dark pass reaches every NEW class (the sage-tint blind spot)")

settings = read(SETTINGS)
dark_desktop = ["lg-epk", "lg-epk__q", "lg-epk__h", "lg-epk__tabs", "lg-epk__e:hover"]
for cls in dark_desktop:
    check(f"app-settings.js dark block covers .{cls}", f".{cls}" in settings, True)
# The phone sheet carries its own dark block inside messenger-sheet.js.
for cls in ["mg-epk", "mg-epk-h", "mg-epk-tabs"]:
    check(f"messenger-sheet.js dark block covers .{cls}",
          bool(re.search(r"D \+ '[^']*\." + re.escape(cls) + r"[,{ ]", sheet)), True)
# The ☺ button must NOT get its own dark override: it rides the same two tokens as
# its sibling attach button, and an override would make one row's buttons diverge.
# ⚠️ Matched as a SELECTOR, not as a substring. The first draft tested
# `"lg-msg__emoji-btn" in settings` and went RED against the COMMENT that explains
# why the rule is absent — a guard-check matching the file's own prose instead of
# its code, which is a known way to manufacture a finding out of nothing.
check("the ☺ button has no dark override (matches its sibling attach button)",
      bool(re.search(r"D \+ '[^']*\.lg-msg__emoji-btn", settings)), False)

# ── phase 9: emoji survive storage ───────────────────────────────────────────
log("\n[9] emoji survive the real store (Postgres, app's own PDO construction)")

probe = r"""<?php
$pdo = new PDO('pgsql:host=/var/run/postgresql;dbname=profile_app', null, null, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false]);
$cases = ["\u{1F3B8}", "\u{2764}\u{FE0F}", "\u{1F44D}\u{1F3FD}",
          "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}",
          "\u{1F1EC}\u{1F1E7}", "1\u{FE0F}\u{20E3}", "solo at 2:14 \u{1F525} is the one"];
$row = $pdo->query('SELECT thread_id, sender_uuid FROM messages ORDER BY id DESC LIMIT 1')->fetch();
if (!$row) { echo "NOSEED"; exit; }
$pdo->beginTransaction();
$ins = $pdo->prepare('INSERT INTO messages (thread_id,sender_uuid,body) VALUES (:t,:s,:b) RETURNING id');
$sel = $pdo->prepare('SELECT body FROM messages WHERE id=:i');
$bad = 0; $last = 0;
foreach ($cases as $b) {
  $ins->execute([':t'=>$row['thread_id'], ':s'=>$row['sender_uuid'], ':b'=>$b]);
  $last = (int)$ins->fetchColumn();
  $sel->execute([':i'=>$last]);
  if ($sel->fetchColumn() !== $b) $bad++;
}
$pdo->prepare('UPDATE messages SET body=:b WHERE id=:i')->execute([':b'=>'?', ':i'=>$last]);
$sel->execute([':i'=>$last]);
$caught = ($sel->fetchColumn() !== $cases[count($cases)-1]) ? 1 : 0;
$pdo->rollBack();
$chk = $pdo->prepare('SELECT count(*) FROM messages WHERE id=:i'); $chk->execute([':i'=>$last]);
echo json_encode(['bad'=>$bad,'n'=>count($cases),'caught'=>$caught,'residue'=>(int)$chk->fetchColumn()]);
"""
with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as f:
    f.write(probe); probe_path = f.name
os.chmod(probe_path, 0o644)
try:
    r = subprocess.run(["sudo", "-n", "-u", "profile-app", "php", probe_path],
                       capture_output=True, text=True)
    out = r.stdout.strip()
finally:
    os.unlink(probe_path)

if r.returncode != 0 or not out or out == "NOSEED":
    notes.append("storage phase SKIPPED — could not run as the profile-app role "
                 f"({(r.stderr or out or 'no output').strip()[:120]}). Not counted as a pass.")
    log("  SKIP  storage round-trip — see the note at the end (NOT counted green)")
else:
    st = json.loads(out)
    check("every emoji case round-trips byte-identical", st["bad"], 0)
    check("…and the comparison catches deliberate corruption (live)", st["caught"], 1)
    check("the probe leaves no residue on dev2", st["residue"], 0)

# ═════════════════════════════════════════════════════════════════════════════
log("")
for n in notes: log("  NOTE: " + n)
log(f"\n  {passes} passed, {failures} failed")
if failures:
    log("\n  ############ EMOJI PICKER GATE RED ############")
    sys.exit(1)
log("\n  ############ emoji picker gate GREEN ############")
sys.exit(0)
