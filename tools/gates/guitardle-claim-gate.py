#!/usr/bin/env python3
"""
GATE 37 — the daily Guitardle attempt is ONE ALLOWANCE PER MEMBER ACCOUNT,
claimed at the START of a game, and the flag-OFF path is a proven no-op.

Backlog 22, Ian 2026-08-14: "fixing the guitardle giving more chances on
different devices".

── WHY THE OBVIOUS GATE WOULD HAVE BEEN GREEN ON THE BROKEN STATE ────────────
"A member cannot record two results in one day" was ALREADY TRUE before this
work: guitardle_results has carried UNIQUE (wp_user_id, play_date) with
ON CONFLICT DO NOTHING since June, and live measurement over 7 days found 93
successful POSTs producing exactly 93 rows — zero duplicate writes. A gate
asserting that would pass on the defect.

The leak was never the RECORDING, it was ABANDONING. Nothing was written until
handleWin/handleLoss, and the mid-game snapshot lived in localStorage, i.e. per
DEVICE. So a player could reveal letters until the phrase was readable, close
the tab — leaving no row, no lock and no trace — reopen in incognito, and solve
it cold in one move for 10 points, 20 with hardcore. That emitted ONE POST and
ONE row, indistinguishable from honest play. Live evidence: WP 197 was 27 plays
/ 27 wins / every win <=4 moves / 7 of them in a single move, against a field
whose best average is 4.1.

So the assertion that actually bites is the one below in phase 2: a SECOND
start-claim for the same (member, day) must claim NOTHING. That is the only
check in this file that was red before the fix.

── IT DRIVES THE WORKING TREE, NOT THE SERVE ─────────────────────────────────
curl would reach /srv/archive-poc, which symlinks to the SERVING CHECKOUT
(main), so a lane would be testing main's endpoint and calling it evidence.
Instead guitardle-claim-probe.php requires THIS branch's endpoint directly with
a real WP session cookie, so what is asserted is the code about to be merged.
LG_GDLE_ENDPOINT overrides the file under test — that is how the red-first run
is reproduced (see docs/CRAFT-STANDARD.md row 37).

── PHASE 0 IS NOT DECORATION ─────────────────────────────────────────────────
Every "no row was written" claim below is vacuously true on a box with no
WordPress, no Postgres or a dead cookie. Phase 0 proves the door is open and
answering as a known member BEFORE any absence is trusted.

Exit: 0 green, 1 defect, 2 could-not-run.
"""

import json
import os
import shlex
import re
import subprocess
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PROBE = os.path.join(ROOT, 'tools', 'gates', 'guitardle-claim-probe.php')
ENDPOINT = os.environ.get(
    'LG_GDLE_ENDPOINT',
    os.path.join(ROOT, 'archive-poc', 'api', 'v0', 'guitardle-score.php'))
PROMO = os.path.join(ROOT, 'archive-poc', 'web', '_gdle-promo.php')
GAME_JS = os.path.join(ROOT, 'archive-poc', 'web', 'guitardle', 'game.js')
GAME_HTML = os.path.join(ROOT, 'archive-poc', 'web', 'guitardle', 'index.html')
FLAGS = os.path.join(ROOT, 'archive-poc', 'api', 'v0', '_flags.php')

WP_PATH = '/var/www/dev'
# A PER-RUN probe identity. The shared `gdle_gate_probe` account was a real
# defect, not a tidiness issue: any other process touching it -- a second gate
# run, or a lane hand-testing the feature -- lands rows inside this run and the
# gate reports them as FAILURES. That happened on 2026-08-15 and produced five
# false reds on a healthy feature, blocking keeper's merge train. A false red
# blocks every lane, which is strictly worse than the coverage a gate buys.
#
# So each run gets its own account, keyed to the PID, created on demand and
# DELETED at the end. Two runs can now overlap without seeing each other.
PROBE_PREFIX = 'gdle_gate_probe_'
PROBE_LOGIN = PROBE_PREFIX + str(os.getpid())
PLAY_DATE = time.strftime('%Y-%m-%d', time.gmtime())

fails = []
def check(ok, label, detail=''):
    print(('  PASS  ' if ok else '  FAIL  ') + label + (('\n          ' + detail) if detail and not ok else ''))
    if not ok:
        fails.append(label)
    return ok

def cannot_run(why):
    print('CANNOT RUN: ' + why)
    sys.exit(2)

def sh(cmd, **kw):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True, **kw)

def wp(php):
    r = sh("sudo -u looth-dev wp --path=%s eval %s" % (WP_PATH, shlex.quote(php)))
    if r.returncode != 0:
        cannot_run('wp-cli failed: ' + (r.stderr or r.stdout).strip()[:300])
    lines = [l for l in r.stdout.splitlines()
             if not l.startswith(('PHP Warning', 'Warning', 'Notice', 'Deprecated'))]
    if not lines:
        cannot_run('wp-cli returned nothing for: %s\n  stdout=%r\n  stderr=%r'
                   % (php[:120], r.stdout[-200:], (r.stderr or '')[-200:]))
    return lines[-1].strip()

def psql(sql):
    r = sh("sudo -u postgres psql -tAd looth -c %s" % shlex.quote(sql))
    if r.returncode != 0:
        cannot_run('psql failed: ' + r.stderr.strip()[:300])
    return r.stdout.strip()

# ── environment ──────────────────────────────────────────────────────────────
for f in (PROBE, ENDPOINT, PROMO, GAME_JS, GAME_HTML, FLAGS):
    if not os.path.exists(f):
        cannot_run('missing ' + f)
if sh('sudo -n -u looth-dev true').returncode != 0:
    cannot_run('no passwordless sudo to looth-dev')
if sh('sudo -n -u postgres true').returncode != 0:
    cannot_run('no passwordless sudo to postgres')

cols = psql("SELECT string_agg(column_name, ',' ORDER BY column_name) "
            "FROM information_schema.columns "
            "WHERE table_schema='discovery' AND table_name='guitardle_results' "
            "AND column_name IN ('claimed_at','resume_state');")
if cols != 'claimed_at,resume_state':
    cannot_run('migration not applied — run archive-poc/sql/guitardle-claim.pg.sql '
               '(found: %r)' % cols)

# ── SWEEP STALE PROBES FIRST ────────────────────────────────────────────────
# A per-run identity is cleaned up at the END of a run -- which does nothing if
# the run is KILLED. On 2026-08-15 a run of mine was killed mid-flight and left
# both its account and a row behind, and that row was a 1-move hardcore WIN: it
# became the ONLY entry on dev2's weekly board, at 20 points. A test fixture had
# quietly installed itself as the leaderboard champion.
#
# So each run sweeps older leftovers before starting. The 30-minute floor is what
# makes this safe to run alongside another gate: a live concurrent run's account
# is minutes old and is never touched.
def sweep_stale_probes():
    php = ('$ids = []; $cut = time() - 1800;'
           'foreach (get_users(["search"=>"PREFIX*","search_columns"=>["user_login"]]) as $u) {'
           '  if ($u->user_login === "SELFLOGIN") continue;'
           '  if (strtotime($u->user_registered) > $cut) continue;'
           '  $ids[] = (int) $u->ID;'
           '}'
           'echo $ids ? implode(",", $ids) : "none";')
    php = php.replace('PREFIX', PROBE_PREFIX).replace('SELFLOGIN', PROBE_LOGIN)
    stale = wp(php)
    ids = [i for i in stale.split(',') if i.strip().isdigit()] if stale != 'none' else []
    if ids:
        psql("DELETE FROM discovery.guitardle_results WHERE wp_user_id IN (%s);"
             % ','.join(ids))
        wp('require_once ABSPATH."wp-admin/includes/user.php";'
           'foreach ([%s] as $id) wp_delete_user($id); echo "swept";' % ','.join(ids))
        print('  (swept %d stale probe account(s) left by a killed run)' % len(ids))


uid = wp(
    '$login = "%s"; $u = get_user_by("login",$login);'
    'if (!$u) { $id = wp_insert_user(["user_login"=>$login,"user_pass"=>wp_generate_password(24),'
    '"user_email"=>$login."@invalid.local","role"=>"subscriber"]);'
    ' if (is_wp_error($id)) { echo "ERR:".$id->get_error_message(); return; }'
    ' $u = get_user_by("id",$id); }'
    'echo $u ? $u->ID : "ERR:no-user";' % PROBE_LOGIN)
if not uid.isdigit():
    cannot_run('could not create the per-run probe user %s: %s' % (PROBE_LOGIN, uid))
UID = int(uid)
sweep_stale_probes()

cookie_name = wp('echo LOGGED_IN_COOKIE;')
cookie = wp('$t = WP_Session_Tokens::get_instance(%d)->create(time()+3600);'
            'echo wp_generate_auth_cookie(%d, time()+3600, "logged_in", $t);' % (UID, UID))

def wipe():
    psql("DELETE FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID)

def call(flag, method='GET', qs='', body=None, nonce='', help_flag=False):
    env = dict(os.environ,
               GDLE_FLAG='1' if flag else '0',
               GDLE_HELP='1' if help_flag else '0',
               GDLE_METHOD=method, GDLE_QS=qs,
               GDLE_BODY=json.dumps(body) if body else '',
               GDLE_NONCE=nonce, GDLE_ENDPOINT=ENDPOINT)
    if flag is not None:
        env['GDLE_COOKIE_NAME'] = cookie_name
        env['GDLE_COOKIE'] = cookie
    keys = ('GDLE_FLAG GDLE_HELP GDLE_METHOD GDLE_QS GDLE_BODY GDLE_NONCE '
            'GDLE_ENDPOINT GDLE_COOKIE_NAME GDLE_COOKIE').split()
    r = sh('sudo -u looth-dev env %s php %s' % (
        ' '.join('%s=%s' % (k, shlex.quote(env.get(k, ''))) for k in keys), PROBE))
    out = (r.stdout or '').strip().splitlines()
    if not out:
        cannot_run('probe produced no output: ' + (r.stderr or '')[:300])
    try:
        return json.loads(out[-1])
    except ValueError:
        cannot_run('probe did not return JSON: ' + out[-1][:300])

def anon_call(flag, help_flag=False):
    env_bits = 'GDLE_FLAG=%s GDLE_HELP=%s GDLE_METHOD=GET GDLE_ENDPOINT=%s' % (
        '1' if flag else '0', '1' if help_flag else '0', shlex.quote(ENDPOINT))
    r = sh('sudo -u looth-dev env %s php %s' % (env_bits, PROBE))
    return (r.stdout or '').strip().splitlines()[-1]

def render_promo(flag, is_member):
    return render_promo_path(PROMO, flag, is_member)


def render_promo_path(path, flag, is_member):
    """Render the real partial. h() is the front page's escaper (index.php:270);
    the partial expects it in scope, so the shim is copied verbatim rather than
    approximated -- a looser shim would change the bytes being compared."""
    php = ('define("LG_GUITARDLE_DAILY_CLAIM", %s); $is_member = %s; '
           '$gdle_compact = true; '
           'function h(string $s): string { return htmlspecialchars($s, '
           'ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, "UTF-8"); } '
           'ob_start(); include %s; echo ob_get_clean();'
           % ('true' if flag else 'false',
              'true' if is_member else 'false', var_export_path(path)))
    r = sh('php -r %s' % shlex.quote(php))
    if 'Fatal error' in (r.stderr or '') or not r.stdout.strip():
        cannot_run('could not render %s: ' % path
                   + (r.stderr or 'empty output').strip()[:300])
    return r.stdout


def var_export_path(p):
    return "'" + p.replace("\\", "\\\\").replace("'", "\\'") + "'"

print('=== GATE 37: one daily Guitardle allowance per MEMBER, claimed at START ===')
print('endpoint under test: %s' % ENDPOINT)
print('probe member: wp_user_id=%d   play_date=%s\n' % (UID, PLAY_DATE))

# ── PHASE 0 — LIVENESS ───────────────────────────────────────────────────────
print('PHASE 0 — liveness (without this every absence below is vacuous)')
wipe()
live = call(True, 'GET', 'local_date=' + PLAY_DATE)
check(live.get('authenticated') is True and live.get('wp_user_id') == UID,
      'the endpoint answers as the probe MEMBER (not anon, not a 403 page)',
      repr(live)[:200])
check(isinstance(live.get('nonce'), str) and len(live['nonce']) >= 8,
      'it issues a usable CSRF nonce', repr(live.get('nonce')))
NONCE = live.get('nonce', '')
check(psql("SELECT count(*) FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID) == '0',
      'the probe member starts the run with no row')
print()

# ── PHASE 1 — FLAG OFF IS A NO-OP ────────────────────────────────────────────
print('PHASE 1 — flag OFF: byte-identical, and writes nothing new')
wipe()
off_anon = anon_call(False)
check(off_anon == '{"authenticated":false}',
      'OFF anon GET is byte-identical to the legacy payload', off_anon)
off_get = call(False, 'GET', 'local_date=' + PLAY_DATE)
check(list(off_get.keys()) == ['authenticated', 'wp_user_id', 'nonce', 'today'],
      'OFF member GET carries EXACTLY the four legacy keys (no claim, no pending)',
      repr(list(off_get.keys())))
off_start = call(False, 'POST', body={'action': 'start', 'phrase_id': 42,
                                      'local_date': PLAY_DATE}, nonce=NONCE)
check(off_start.get('error') == 'not_enabled', 'OFF refuses a start-claim', repr(off_start))
check(psql("SELECT count(*) FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID) == '0',
      'OFF start-claim wrote NO ROW (the absence that phase 0 makes meaningful)')
off_fin = call(False, 'POST', body={'phrase_id': 42, 'won': True, 'moves': 5,
                                    'streak': 1, 'local_date': PLAY_DATE}, nonce=NONCE)
check(off_fin.get('recorded') is True, 'OFF still records a finished game', repr(off_fin))
check(psql("SELECT claimed_at IS NULL FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID) == 't',
      'OFF took the original INSERT path (claimed_at stays NULL)')

# the mixed state the rollback note warns about
wipe()
call(True, 'POST', body={'action': 'start', 'phrase_id': 42, 'local_date': PLAY_DATE}, nonce=NONCE)
mixed = call(False, 'GET', 'local_date=' + PLAY_DATE)
check(mixed.get('today') is None,
      'OFF does not mistake an outstanding claim row for a finished result',
      repr(mixed))
print()

# ── PHASE 2 — THE FAIRNESS INVARIANT ─────────────────────────────────────────
print('PHASE 2 — flag ON: one allowance per member, per day, across devices')
wipe()
a = call(True, 'POST', body={'action': 'start', 'phrase_id': 42, 'hardcore': True,
                             'local_date': PLAY_DATE}, nonce=NONCE)
check(a.get('claimed') is True, 'device A claims the day', repr(a))
b = call(True, 'POST', body={'action': 'start', 'phrase_id': 42, 'hardcore': False,
                             'local_date': PLAY_DATE}, nonce=NONCE)
check(b.get('claimed') is False,
      '*** device B (or incognito) gets NO fresh allowance — THE FIX ***', repr(b))
check(psql("SELECT count(*) FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID) == '1',
      'two devices produced exactly ONE row')

sv = call(True, 'POST', body={'action': 'save', 'hardcore': True, 'local_date': PLAY_DATE,
                              'state': {'moves': 4, 'revealed': ['S', 'T'],
                                        'purchased': ['E']}}, nonce=NONCE)
check(sv.get('saved') is True, 'the mid-game position is held server-side', repr(sv))
res = call(True, 'GET', 'local_date=' + PLAY_DATE)
pend = res.get('pending') or {}
check(pend.get('phrase_id') == 42 and (pend.get('state') or {}).get('moves') == 4,
      'device B RESUMES that position rather than being locked out (the phone-dies case)',
      repr(res))
check(res.get('today') is None,
      'an unfinished claim never reads as "already played" (that would reveal the phrase)')

f1 = call(True, 'POST', body={'phrase_id': 42, 'won': True, 'moves': 6, 'streak': 3,
                              'hardcore': True, 'local_date': PLAY_DATE}, nonce=NONCE)
check(f1.get('recorded') is True, 'finishing fills the claim row', repr(f1))
f2 = call(True, 'POST', body={'phrase_id': 42, 'won': True, 'moves': 1, 'streak': 9,
                              'hardcore': True, 'local_date': PLAY_DATE}, nonce=NONCE)
check(f2.get('recorded') is False, 'a second finish is refused', repr(f2))
row = psql("SELECT moves||'|'||streak||'|'||(resume_state IS NULL) "
           "FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID)
check(row == '6|3|true',
      'the FIRST finished result stands, and the resume snapshot is cleared', row)
print()

# ── PHASE 3 — THE BOARD MUST NOT NOTICE ──────────────────────────────────────
print('PHASE 3 — claim rows are invisible to the weekly board')
wipe()
call(True, 'POST', body={'action': 'start', 'phrase_id': 42, 'local_date': PLAY_DATE}, nonce=NONCE)
seen = psql(
    "SELECT count(*) FROM ("
    "  SELECT r.wp_user_id FROM discovery.guitardle_results r"
    "  WHERE r.play_date >= '%s'::date - 7 AND r.play_date < '%s'::date + 1"
    "  GROUP BY r.wp_user_id"
    "  HAVING COUNT(*) FILTER (WHERE r.won) > 0"
    ") s WHERE s.wp_user_id = %d;" % (PLAY_DATE, PLAY_DATE, UID))
check(seen == '0',
      'a member who has only CLAIMED does not appear on the leaderboard', seen)
nulls = psql("SELECT count(*) FROM discovery.guitardle_results "
             "WHERE wp_user_id=%d AND won IS NULL;" % UID)
check(nulls == '1', 'the claim row really is unfinished (won IS NULL)', nulls)
print()

# ── PHASE 4 — MEMBER-VISIBLE COPY, PER STATE ─────────────────────────────────
print('PHASE 4 — the logged-out surfaces say something true, per flag state')
off_anon_html = render_promo(False, False)
on_anon_html = render_promo(True, False)
on_mem_html = render_promo(True, True)
check('play to claim' in off_anon_html and 'sign in to claim' not in off_anon_html,
      'OFF: the promo card is unchanged for a logged-out visitor')

# THE byte-identical proof the house rule asks for. Comparing member-vs-anon
# would be wrong (they legitimately differ: the iframe carries aud=m / aud=p, a
# 6/11 ruling that predates this work), and comparing them was a VACUOUS PASS
# while the renderer was fataling and returning two empty strings. So the
# baseline is origin/main's own copy of the partial, rendered flag-OFF.
# It is written into the SAME directory so __DIR__ resolves identically --
# otherwise @filemtime() misses the game assets and the ?v= cache-bust differs,
# which would fail for a reason that has nothing to do with the diff.
base_src = sh('git -C %s show origin/main:archive-poc/web/_gdle-promo.php' % shlex.quote(ROOT))
if base_src.returncode != 0:
    cannot_run('no origin/main copy of _gdle-promo.php to compare against — '
               'fetch origin first')
base_path = os.path.join(os.path.dirname(PROMO), '_gdle-promo.gate37-baseline.php')
try:
    with open(base_path, 'w') as fh:
        fh.write(base_src.stdout)
    for is_member, who in ((True, 'member'), (False, 'logged-out')):
        cur = render_promo(False, is_member)
        was = render_promo_path(base_path, False, is_member)
        check(cur == was,
              'OFF: the promo card is BYTE-IDENTICAL to origin/main (%s)' % who,
              'lengths %d vs %d' % (len(cur), len(was)))
finally:
    if os.path.exists(base_path):
        os.remove(base_path)
check(not os.path.exists(base_path), 'the baseline render file was cleaned up')
check('sign in to claim' in on_anon_html,
      'ON: a logged-out visitor is told the spots need a sign-in, not "play to claim"')
check('play to claim' in on_mem_html and 'sign in to claim' not in on_mem_html,
      'ON: a signed-in member still sees "play to claim"')
check(on_anon_html.count('sign in to claim') >= 6,
      'ON: the JS repaint carries the same words as the SSR (all five slots + the constant)',
      'occurrences=%d' % on_anon_html.count('sign in to claim'))

# The split keeper asked for on 2026-08-15: the rules must be able to ship
# WITHOUT the fairness change. "Independent" is only a word until both crossed
# states are driven, so all four combinations are exercised here.
both_off_anon = anon_call(False, False)
check(both_off_anon == '{"authenticated":false}',
      'BOTH flags OFF: anon payload is byte-identical to the legacy one', both_off_anon)
claim_only = call(True, 'GET', 'local_date=' + PLAY_DATE, help_flag=False)
check('claim' in claim_only and 'help' not in claim_only,
      'claim ON / help OFF: the rules stay dark (the fairness flag does not drag them in)',
      repr(list(claim_only.keys())))
help_only = call(False, 'GET', 'local_date=' + PLAY_DATE, help_flag=True)
check(list(help_only.keys()) == ['authenticated', 'wp_user_id', 'nonce', 'today', 'help'],
      '*** help ON / claim OFF: the rules can ship ALONE — the split works ***',
      repr(list(help_only.keys())))
check(anon_call(False, True) == '{"authenticated":false,"help":true}',
      'help ON reaches LOGGED-OUT players too (they are who the rules are for)',
      anon_call(False, True))

html = open(GAME_HTML).read()
js = open(GAME_JS).read()
check(re.search(r'id="btn-help"[^>]*style="display:none"', html) is not None,
      'the How-to-Play button ships hidden, so OFF adds nothing to the chrome')
check(re.search(r'id="anon-note"[^>]*style="display:none"', html) is not None,
      'the logged-out game line ships hidden')
reveal = js[js.find('claimEnabled = !!'):]
reveal = reveal[:reveal.find('} catch')]
check("getElementById('btn-help')" in reveal and "getElementById('anon-note')" in reveal,
      'both are revealed only inside the flag-driven handshake block')
help_branch = reveal[reveal.find('if (helpEnabled)'):]
help_branch = help_branch[:help_branch.find('if (claimEnabled')]
check("getElementById('btn-help')" in help_branch
      and "getElementById('anon-note')" not in help_branch,
      'the rules button hangs off helpEnabled, NOT claimEnabled')
anon_branch = reveal[reveal.find('if (claimEnabled'):]
check("getElementById('anon-note')" in anon_branch
      and "getElementById('btn-help')" not in anon_branch,
      'the logged-out line hangs off claimEnabled, NOT helpEnabled')
check('Playing for fun' in html and 'sign in to compete for the Weekly Top 5' in html,
      "the game carries Ian's logged-out line verbatim")

# THE RESUME REPLAY. Everything above proves the SERVER hands back a position;
# nothing above proves the CLIENT puts it on the board correctly, and that is
# the whole point of the feature. Verified in a real browser on 2026-08-15: a
# server-held snapshot replayed onto a fresh board revealed the most-REPEATED
# letter in every one of its positions (2 of 2), disabled its key, marked the
# purchased vowel, showed 4 moves, and locked hardcore.
#
# That check needs a browser, which would make this gate flaky on a 2-core box
# and a DEAD gate blocks every lane — so what is gated here is the structural
# guarantee underneath it: BOTH restores must replay through the ONE shared
# primitive that reveals a letter in ALL its positions. A hand-rolled loop that
# set a single tile would pass every server-side assertion in this file and
# still lose letters.
#
# ⚠️ THE PRIMITIVE MOVED 2026-08-16, and this gate was RED on the fix that
# repaired the very defect it exists to prevent. It named revealTiles(letter)
# directly. That was correct while there was one board shape, and became WRONG
# when server-driven play arrived: server play draws tiles with data-i only (the
# client is never told the phrase — backlog 25) while legacy uses data-letter,
# and revealTiles() selects [data-letter]. So the assertion was SATISFIED by a
# call that matched ZERO tiles and painted nothing — it passed while a resumed
# board came back blank, which is exactly what Ian reported.
#
# Both restores now go through replayPosition(), which dispatches to
# revealTiles() for legacy and revealTilesAt() for server play. The property is
# unchanged and the assertion is stronger: it requires the shared entry point
# AND that the entry point still reveals ALL positions on BOTH board shapes.
# Gate 57 asserts the same property BEHAVIOURALLY in a real browser, at both
# widths and across two devices; this stays as the cheap structural backstop.
remote = js[js.find('function restoreRemoteGame'):]
remote = remote[:remote.find('\n}\n')]
check('replayPosition(' in remote,
      'the cross-device resume replays through replayPosition() — a letter comes '
      'back in EVERY position, not just the first')
check('state.revealedLetters' in remote and 'state.purchasedVowels' in remote
      and 'lockHardcoreToggle()' in remote,
      'it restores the full position: revealed letters, purchased vowels, and '
      'the hardcore lock (mode you started in is the mode you resume in)')
local = js[js.find('function restoreSavedGame'):]
local = local[:local.find('\n}\n')]
check('replayPosition(' in local,
      'and the local restore still uses it too (the two paths have not drifted)')
# The shared primitive must itself still reveal ALL positions, on BOTH board
# shapes — otherwise "they both call replayPosition" is satisfied by a
# replayPosition that sets one tile, and the guarantee is gone.
replay = js[js.find('function replayPosition'):]
replay = replay[:replay.find('\n}\n')]
check('revealTilesAt(letter, positions)' in replay,
      'replayPosition reveals ALL server-given positions (server play draws '
      'tiles with data-i, so the data-letter painter matches nothing there)')
check('revealTiles(letter)' in replay,
      'replayPosition still uses the data-letter painter for the LEGACY board '
      '(the shape live runs today)')
check('Hardcore' in html and 'locks at your first move' in html.lower(),
      'How-to-Play explains Hardcore in plain English (it had NO player-visible '
      'copy at all — only a title= tooltip, invisible on touch)')
print()

# ── cleanup, asserted ────────────────────────────────────────────────────────
wipe()
check(psql("SELECT count(*) FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID) == '0',
      'the gate cleaned up every row it wrote')
wp('$u = get_user_by("login","%s"); if ($u) { require_once ABSPATH."wp-admin/includes/user.php"; '
   'wp_delete_user($u->ID); } echo "gone";' % PROBE_LOGIN)
check(wp('$u = get_user_by("login","%s"); echo $u ? "STILL-THERE" : "gone";' % PROBE_LOGIN) == 'gone',
      'and removed its own per-run probe account')

print()
if fails:
    print('############ GATE 37 RED — %d assertion(s) failed ############' % len(fails))
    for f in fails:
        print('  - ' + f)
    sys.exit(1)
print('############ GATE 37 GREEN ############')
sys.exit(0)
