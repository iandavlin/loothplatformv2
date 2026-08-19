#!/usr/bin/env python3
"""
GATE 74 — #129 categorize-last: all THREE flag states, and the couplings that
would make a green flag a lie.

WHY THIS GATE EXISTS
--------------------
The composer's required "Where" step is gone and an optional tag step arrives at the
end (Ian, 8/16 ruled, refined to Option C on 8/19). That is a member-facing change
to the single most-used write surface on the platform, so it merges behind a flag
defaulted OFF — and the recorded failure class here is not the ON path, it is that
"flag OFF is a no-op" gets asserted by *nobody* and drifts.

So this reads the flag rather than hardcoding a state (a gate that hardcodes OFF
goes red the moment Ian flips the default and blocks every other lane), and it
asserts per state:

    absent  the tracked config is missing            -> fail closed, OFF
    OFF     the Where step renders EXACTLY as before  -> byte-identical, and the
                                                        taxonomy is NOT extended
    ON      the pre-made forum choice, the tag field, and the mobile guard all exist

Pure source + config analysis: no DB, no browser, no network. It therefore cannot
flake under load, and it cannot go vacuously green behind a locked-out browser the
way a visual suite can.

FIVE COUPLINGS IT ALSO PINS, each of which has a scar somewhere in this repo:
  1. the two non-postable forum lists (bb-mirror's constant and the WP-side literal)
     must AGREE — when only the picker knew the list, a hand-built request could file
     a post into a forum that was merely "not offered"
  2. the landing forum must be CONFIG, never a constant in code — dev2 and live are
     different WordPress installs, so the same forum has a different post ID on each
  3. the reply loop in the applier must bump post_modified_gmt AND dispatch, because
     a change that does not bump it never reaches the forum mirror — confirmed before
     precisely for the replies of a topic moved between forums
  4. both flag readers must consult getenv() AND $_SERVER, or a lane-preview
     fastcgi_param serves the OFF path on the very URL built for Ian to click
  5. the MOBILE composer must be guarded too. "mobile is the flat form served
     unchanged" is a comment in forums.js that is true about forums.js and false
     about what a phone renders: hub-polish.js builds its own 4-step wizard whose
     step 1 was "Title & forum". Unguarded, the pain point survives on the platform
     most members post from.

exit 0 green · exit 1 a real finding · exit 2 CANNOT RUN (run-all's no-verdict code)
"""
import json
import os
import re
import subprocess
import sys

ROOT = os.path.realpath(os.path.join(os.path.dirname(__file__), '..', '..'))

CFG      = os.path.join(ROOT, 'platform/config/composer-categorize-last.php')
MU       = os.path.join(ROOT, 'platform/mu-plugins/lg-composer-categorize-last.php')
BBCFG    = os.path.join(ROOT, 'bb-mirror/config.php')
CHROME   = os.path.join(ROOT, 'bb-mirror/web/_chrome.php')
FORUMSJS = os.path.join(ROOT, 'bb-mirror/web/forums.js')
FORUMSCSS= os.path.join(ROOT, 'bb-mirror/web/forums.css')
HUBPOL   = os.path.join(ROOT, 'webroot/hub-polish.js')
FLAGS    = os.path.join(ROOT, 'docs/FLAGS.md')

findings = []


def strip_comments(src):
    """Blank out /* */ and // and # comments, PRESERVING newlines so line numbers
    reported afterwards still line up with the real file."""
    out = []
    i, n = 0, len(src)
    while i < n:
        two = src[i:i + 2]
        if two == '/*':
            j = src.find('*/', i + 2)
            j = n if j == -1 else j + 2
            out.append(re.sub(r'[^\n]', ' ', src[i:j]))
            i = j
        elif two == '//' or src[i] == '#':
            j = src.find('\n', i)
            j = n if j == -1 else j
            out.append(' ' * (j - i))
            i = j
        else:
            out.append(src[i])
            i += 1
    return ''.join(out)


def fail(msg):
    findings.append(msg)


def read(path):
    with open(path, encoding='utf-8') as fh:
        return fh.read()


def cannot_run(msg):
    # EXIT 2, NOT 3. run-all.sh reads 0 = green, 2 = "produced NO VERDICT", and
    # ANYTHING ELSE non-zero as RED. A gate that exits 3 for a missing environment
    # reports it as a finding and turns the whole suite red, which blocks every other
    # lane on the box.
    print('CANNOT RUN: ' + msg)
    sys.exit(2)


for p in (MU, BBCFG, CHROME, FORUMSJS, FORUMSCSS, HUBPOL, FLAGS):
    if not os.path.exists(p):
        cannot_run('missing ' + os.path.relpath(p, ROOT))

php = None
for cand in ('php', '/usr/bin/php'):
    try:
        subprocess.run([cand, '-v'], capture_output=True, check=True)
        php = cand
        break
    except Exception:
        continue
if php is None:
    cannot_run('no php binary, and the tracked config is a PHP file')

mu       = read(MU)
bbcfg    = read(BBCFG)
chrome   = read(CHROME)
forumsjs = read(FORUMSJS)
css      = read(FORUMSCSS)
hubpol   = read(HUBPOL)
flags    = read(FLAGS)


# ── STATE: absent ───────────────────────────────────────────────────────────────
# Both readers must fail CLOSED when the tracked config cannot be read. A reader
# that defaults to ON when its config vanishes turns a deploy hiccup into a member
# launch.
for label, src in (('WP mu-plugin', mu), ('bb-mirror config', bbcfg)):
    blk = src[src.find('lg_ccl_config'):]
    blk = blk[:4000]
    if 'is_readable' not in blk or "'enabled' => false" not in blk:
        fail('STATE absent: %s does not fail closed when the tracked config is '
             'unreadable (needs an is_readable check returning enabled=false)' % label)


# ── the flag itself ─────────────────────────────────────────────────────────────
if not os.path.exists(CFG):
    fail('tracked config platform/config/composer-categorize-last.php is MISSING — '
         'the flag has no home, and FLAGS.md points at it')
    cfg = None
else:
    out = subprocess.run(
        [php, '-r', 'echo json_encode(require $argv[1]);', CFG],
        capture_output=True, text=True)
    try:
        cfg = json.loads(out.stdout)
    except Exception:
        fail('tracked config does not evaluate to an array: ' + (out.stderr or out.stdout)[:200])
        cfg = None

state = 'ON' if (cfg and cfg.get('enabled') is True) else 'OFF'
print('flag state read from the tracked config: %s' % state)

if cfg is not None:
    # Coupling 2 — the landing forum is CONFIG, not a constant.
    if not isinstance(cfg.get('default_forum_id'), int) or cfg['default_forum_id'] <= 0:
        fail('default_forum_id must be a positive int in the tracked config '
             '(dev2 and live give the same forum different post IDs)')
    # ...and nothing in CODE may hardcode it, or live inherits dev2's ID.
    #
    # Comments are stripped FIRST rather than sniffed per-line. The earlier version
    # only understood `//` and `#`, so a forum id named in the middle of a /* */
    # block — which is exactly where the measurement note about 73564 lives — read
    # as a hardcode. A false RED on a correct file is as expensive as a miss.
    fid = str(cfg.get('default_forum_id', 0))
    for label, src in (('_chrome.php', chrome), ('forums.js', forumsjs),
                       ('mu-plugin', mu), ('hub-polish.js', hubpol)):
        code = strip_comments(src)
        for m in re.finditer(r'(?<![0-9])' + re.escape(fid) + r'(?![0-9])', code):
            line = code[:m.start()].count('\n') + 1
            fail('%s: line ~%d of the comment-stripped source hardcodes the landing '
                 'forum id %s — it must come from lg_ccl_default_forum_id() so live '
                 'can differ' % (label, line, fid))

    # The map is the measured one, keyed on forum ID.
    m = cfg.get('taxo_forum_map')
    if not isinstance(m, dict) or not m:
        fail('taxo_forum_map is empty or not a map')
    else:
        for slug, forum in m.items():
            if not isinstance(forum, int) or forum <= 0:
                fail('taxo_forum_map["%s"] is not a positive forum ID — names and '
                     'slugs are NOT unique across the forum tree (4 duplicate slugs, '
                     '2 identical titles), so only IDs disambiguate' % slug)


# ── STATE: OFF is byte-identical ────────────────────────────────────────────────
# The original Where-step markup must still be present VERBATIM and reachable, and
# the ON-path additions must all sit behind `if ($ccl)`. This is what makes OFF a
# no-op rather than "nothing visibly changed".
off_markers = [
    '<span class="ntm-label" id="ntm-forum-label">Forum <span class="ntm-label__opt">(pick one)</span></span>',
    'role="radiogroup" aria-labelledby="ntm-forum-label"',
    "'<div class=\"ntm-fl__cat\">'",
]
for mk in off_markers:
    if mk not in chrome:
        fail('STATE OFF: the original Where-step markup is no longer verbatim in '
             '_chrome.php (missing: %s...) — OFF is only a no-op if the old path is '
             'untouched, not merely unreached' % mk[:60])

if '<?php if ($ccl): ?>' not in chrome or '<?php else: ?>' not in chrome:
    fail('STATE OFF: _chrome.php has no if($ccl)/else split, so the two states are '
         'not separated at build time')

# Both conditions, not just the flag: an unmirrored landing forum must keep the
# Where step rather than file posts at a forum row Postgres does not have.
if not re.search(r'\$ccl\s*=.*lg_ccl_enabled\(\)', chrome, re.S):
    fail('_chrome.php does not gate on lg_ccl_enabled()')
if 'lg_ccl_default_forum_ok()' not in chrome:
    fail('_chrome.php does not also gate on lg_ccl_default_forum_ok() — measured '
         '8/19, dev2 forum 73564 exists in WordPress and NOT in the Postgres mirror, '
         'and the picker/postable contract both read Postgres')

# The taxonomy extension must be flag-gated, or OFF quietly changes the schema.
tax = re.search(r"add_action\('init',\s*function\s*\(\)\s*\{(.{0,600}?)\}\s*,\s*20\)",
                mu, re.S)
if not tax:
    fail('no init:20 hook registering shared_category for topic (ACF registers the '
         'taxonomy at init:10, so an earlier hook attaches to nothing)')
elif 'lg_ccl_enabled()' not in tax.group(1):
    fail('STATE OFF: register_taxonomy_for_object_type is NOT behind lg_ccl_enabled() '
         '— OFF would silently extend the taxonomy to topics, which is a schema '
         'change, not a no-op')

# The CLI must refuse while OFF: terms written then are rows nothing reads.
cli = mu[mu.find("WP_CLI::add_command('lg-recat'"):]
if cli:
    guard = cli[:1500]
    if 'lg_ccl_enabled()' not in guard or 'WP_CLI::error' not in guard:
        fail('STATE OFF: wp lg-recat does not refuse while the flag is OFF — with the '
             'taxonomy unregistered for topic those term rows are unreadable')


# ── STATE: ON ──────────────────────────────────────────────────────────────────
on_markers = {
    'data-ccl="1"':                'the form does not advertise the ON state to its JS',
    'id="ntm-topics"':             'the tag field is not rendered',
    'ntm-topics-field':            'the tag field has no chip container',
    'lg-ccl/v1/topics':            'the picker has no term endpoint to load on intent',
}
for mk, why in on_markers.items():
    if mk not in chrome:
        fail('STATE ON: %s (missing %s in _chrome.php)' % (why, mk))

# The pre-made choice: ON must still satisfy the #ntm-forum radiogroup contract, or
# four separate readers (ntmGetForum, the review row, mobile step-1 Next, and
# hub-polish's pre-submit check) all break at once.
onblk = chrome[chrome.find('<?php if ($ccl): ?>'):chrome.find('<?php else: ?>')]
if 'name="forum_id"' not in onblk or 'checked' not in onblk:
    fail('STATE ON: no pre-checked forum_id radio in the ccl branch — ntmGetForum(), '
         'the review row and hub-polish\'s pre-submit check all read that contract')
if 'hidden' not in onblk:
    fail('STATE ON: the pre-made forum choice is not hidden, so the Where step is '
         'still visible and nothing was actually removed')

# Coupling 5 — the MOBILE composer.
if 'fbcCcl' not in hubpol:
    fail('STATE ON: hub-polish.js has no ccl guard. fbStyleComposer() is the MOBILE '
         'composer and its step 1 is "Title & forum" — unguarded, the removed Where '
         'step survives on the platform most members post from')
else:
    if not re.search(r"if\s*\(\s*!fbcCcl\s*&&\s*forumSel", hubpol):
        fail('hub-polish.js still builds the forum accordion unconditionally '
             '(expected `if (!fbcCcl && forumSel ...)`)')
    if "getElementById('ntm-topics')" not in hubpol:
        fail('hub-polish.js never places #ntm-topics, so a phone gets no tag field '
             'at all — categorize-last would be desktop-only')

# The desktop wizard: Topics must be a real, OPTIONAL, last-ish step.
if "label: 'Topics'" not in forumsjs:
    fail('STATE ON: forums.js has no Topics step in the wizard')
if 'opt: true' not in forumsjs:
    fail('STATE ON: the Topics step is not marked optional in STEPS')
if 'lgw-btn--link' not in forumsjs or 'Skip this' not in forumsjs:
    fail('STATE ON: no Skip control on the Topics step — "optional" needs an '
         'operable control, not just a rail label')
if 'footBtns.appendChild(btnSkip)' not in forumsjs:
    fail('STATE ON: btnSkip is never appended to the footer, so the Skip control is '
         'an element that never reaches the DOM')


# ── Coupling 1 — the two non-postable lists must agree ─────────────────────────
m = re.search(r"define\('LG_BB_MIRROR_NONPOSTABLE_FORUM_IDS',\s*\[([^\]]*)\]", bbcfg)
bb_ids = sorted(int(x) for x in re.findall(r'\d+', m.group(1))) if m else None
m2 = re.search(r'function lg_ccl_nonpostable_forum_ids\(\).{0,400}?return \[([^\]]*)\]', mu, re.S)
wp_ids = sorted(int(x) for x in re.findall(r'\d+', m2.group(1))) if m2 else None
if bb_ids is None or wp_ids is None:
    fail('could not read both non-postable forum lists to compare them')
elif bb_ids != wp_ids:
    fail('the non-postable forum lists DISAGREE: bb-mirror has %s, the WP side has '
         '%s. Two pools must agree about this or a hand-built request files a post '
         'into a forum that is merely "not offered"' % (bb_ids, wp_ids))


# ── Coupling 3 — the reply loop must bump AND dispatch ─────────────────────────
ap = re.search(r'function lg_ccl_apply\(.*?\n\}', mu, re.S)
if not ap:
    fail('lg_ccl_apply() not found')
else:
    body = ap.group(0)
    loop = re.search(r'foreach \(\$replies as \$rid\) \{(.*?)\n        \}', body, re.S)
    if not loop:
        fail('lg_ccl_apply has no reply loop — a moved topic leaves its replies '
             'claiming a forum the topic left (5,128 of 5,130 replies carry '
             '_bbp_forum_id, and the mirror reply table has its own forum_id)')
    else:
        lb = loop.group(1)
        if 'lg_ccl_touch_modified' not in lb:
            fail('the reply loop does not bump post_modified_gmt — RECORDED TRAP: a '
                 'change that does not bump it never reaches the forum mirror, '
                 'confirmed for exactly this operation. MySQL would look perfect and '
                 'the Hub would show the old forum indefinitely')
        if 'sync_dispatch' not in lb:
            fail('the reply loop never dispatches a mirror sync for the moved replies')
    if 'clean_post_cache' not in mu:
        fail('lg_ccl_touch_modified does not clean_post_cache — the object cache '
             'hands the sync back the stale row it was told to re-read')


# ── Coupling 4 — both readers consult getenv() AND $_SERVER ───────────────────
for label, src in (('WP mu-plugin', mu), ('bb-mirror config', bbcfg)):
    blk = src[src.find('function lg_ccl_enabled'):]
    blk = blk[:900]
    if 'getenv(' not in blk or '$_SERVER' not in blk:
        fail('%s reads the preview override from only one place — a lane-preview '
             'fastcgi_param lands in $_SERVER but not reliably in the process '
             'environment, so a getenv-only read serves OFF on the very preview URL '
             'built for Ian to click' % label)


# ── the flag is registered, in the same commit ────────────────────────────────
if 'composer-categorize-last' not in flags:
    fail('docs/FLAGS.md has no row for this flag — the maintenance rule is that any '
         'merge adding a flag updates the register IN THE SAME COMMIT')
else:
    row = [l for l in flags.splitlines() if 'composer-categorize-last' in l]
    row = row[0] if row else ''
    if 'Gate 74' not in row and 'gate 74' not in row:
        fail('the FLAGS.md row does not name Gate 74, so a reader cannot find what '
             'asserts the three states')
    if state == 'OFF' and '**false**' not in row:
        fail('the FLAGS.md row disagrees with the tracked config about the repo '
             'default (config says OFF)')


# ── the picker must clear the mobile composer sheet ───────────────────────────
z = re.search(r'\.lgtp\s*\{[^}]*z-index:\s*(\d+)', css)
if not z:
    fail('the picker overlay .lgtp has no z-index — it is a modal ON a modal')
else:
    zi = int(z.group(1))
    if zi <= 2147483560:
        fail('the picker z-index (%d) does not clear the mobile composer sheet '
             '#looth-comp-sheet at 2147483560 — the @-mention dropdown in this same '
             'composer was invisible on phones at 100000 for exactly this reason '
             '(Ian, 2026-07-23)' % zi)
    if zi >= 2147483646:
        fail('the picker z-index (%d) is at or above .lg-lightbox (2147483646), '
             'which must stay on top' % zi)


# ── verdict ───────────────────────────────────────────────────────────────────
if findings:
    print('\nGATE 74 RED — %d finding%s\n' % (len(findings), '' if len(findings) == 1 else 's'))
    for f in findings:
        print('  FAIL: ' + f)
    sys.exit(1)

print('GATE 74 GREEN — flag %s; absent/OFF/ON all asserted, and the five couplings '
      '(shared non-postable list, config-not-constant forum id, reply bump+dispatch, '
      'dual-source flag read, mobile guard) all hold.' % state)
sys.exit(0)
