#!/usr/bin/env python3
"""
GATE 41 — the Guitardle leaderboard's inputs are what the SERVER watched, and
the answer never reaches the client.

Backlog 25, option A (keeper 2026-08-15). Two facts drove the shape of this:

1. moves / won / hardcore all came out of the POST body, and hardcore DOUBLES
   points, so anyone with their own nonce could post a 20-point day.
2. Server-side scoring alone would NOT have fixed it, because the answer was
   public. Not merely "the CSV is on a public URL" -- measured in a browser, the
   legacy board put the phrase in the DOM: 18 tiles, all 18 carrying
   data-letter, so "POLYURETHANEFINISH" reads straight off the BLANK tiles.
   A player who reads it solves in one move and an honest server scores it 20.

So the phrase stops at the server, and this gate asserts both halves: the
forgery doors are shut, AND the client is not handed the answer.

── THE ASSERTION MOST LIKELY TO SAVE SOMEONE ────────────────────────────────
Phase 2. The server now has its own copy of loadPhrase(), and if it ever
disagrees with game.js the server judges a DIFFERENT PUZZLE than the player
saw -- every honest player loses, which is far worse than the hole being
closed. So the phrase id and letters are recomputed here INDEPENDENTLY, in
Python, straight from the raw assets, and compared with what the PHP resolver
says. Two implementations, one answer, on both audience tracks.

Exit: 0 green, 1 defect, 2 could-not-run.
"""

import csv
import datetime
import json
import os
import shlex
import subprocess
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PROBE = os.path.join(ROOT, 'tools', 'gates', 'guitardle-claim-probe.php')
ENDPOINT = os.environ.get(
    'LG_GDLE_ENDPOINT',
    os.path.join(ROOT, 'archive-poc', 'api', 'v0', 'guitardle-score.php'))
PUZZLE_PHP = os.path.join(ROOT, 'archive-poc', 'api', 'v0', '_guitardle-puzzle.php')
GAME_JS = os.path.join(ROOT, 'archive-poc', 'web', 'guitardle', 'game.js')
ASSETS = os.path.join(ROOT, 'archive-poc', 'web', 'guitardle', 'assets')

WP_PATH = '/var/www/dev'
PROBE_PREFIX = 'gdle_sp_probe_'
PROBE_LOGIN = PROBE_PREFIX + str(os.getpid())
PLAY_DATE = time.strftime('%Y-%m-%d', time.gmtime())
VOWELS = set('AEIOU')

fails = []
def check(ok, label, detail=''):
    print(('  PASS  ' if ok else '  FAIL  ') + label + (('\n          ' + detail) if detail and not ok else ''))
    if not ok:
        fails.append(label)
    return ok

def cannot_run(why):
    print('CANNOT RUN: ' + why)
    sys.exit(2)

def sh(cmd):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True)

def wp(php):
    r = sh("sudo -u looth-dev wp --path=%s eval %s" % (WP_PATH, shlex.quote(php)))
    if r.returncode != 0:
        cannot_run('wp-cli failed: ' + (r.stderr or r.stdout).strip()[:300])
    lines = [l for l in r.stdout.splitlines()
             if not l.startswith(('PHP Warning', 'Warning', 'Notice', 'Deprecated'))]
    if not lines:
        cannot_run('wp-cli returned nothing for %s: %r' % (php[:80], r.stdout[-200:]))
    return lines[-1].strip()

def psql(sql):
    r = sh("sudo -u postgres psql -tAd looth -c %s" % shlex.quote(sql))
    if r.returncode != 0:
        cannot_run('psql failed: ' + r.stderr.strip()[:300])
    return r.stdout.strip()

for f in (PROBE, ENDPOINT, PUZZLE_PHP, GAME_JS):
    if not os.path.exists(f):
        cannot_run('missing ' + f)
if sh('sudo -n -u looth-dev true').returncode != 0:
    cannot_run('no passwordless sudo to looth-dev')
if sh('sudo -n -u postgres true').returncode != 0:
    cannot_run('no passwordless sudo to postgres')
if psql("SELECT count(*) FROM information_schema.columns WHERE table_schema='discovery' "
        "AND table_name='guitardle_results' AND column_name='resume_state';") != '1':
    cannot_run('migration not applied — run archive-poc/sql/guitardle-claim.pg.sql')

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


uid = wp('$login = "%s"; $u = get_user_by("login",$login);'
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

def call(sp, method='GET', body=None, nonce=''):
    env = {'GDLE_FLAG': '1', 'GDLE_HELP': '0', 'GDLE_RETRY': '0',
           'GDLE_SERVERPLAY': '1' if sp else '0',
           'GDLE_METHOD': method, 'GDLE_QS': 'local_date=' + PLAY_DATE,
           'GDLE_BODY': json.dumps(body) if body else '', 'GDLE_NONCE': nonce,
           'GDLE_ENDPOINT': ENDPOINT, 'GDLE_COOKIE_NAME': cookie_name,
           'GDLE_COOKIE': cookie}
    r = sh('sudo -u looth-dev env %s php %s' % (
        ' '.join('%s=%s' % (k, shlex.quote(v)) for k, v in env.items()), PROBE))
    out = (r.stdout or '').strip().splitlines()
    if not out:
        cannot_run('probe produced no output: ' + (r.stderr or '')[:300])
    try:
        return json.loads(out[-1])
    except ValueError:
        cannot_run('probe did not return JSON: ' + out[-1][:300])

def php_puzzle(expr):
    r = sh('php -r %s' % shlex.quote('require %s; %s' % (json.dumps(PUZZLE_PHP), expr)))
    if r.returncode != 0:
        cannot_run('puzzle resolver failed: ' + (r.stderr or '')[:300])
    return r.stdout.strip()

def call_many(bodies, nonce):
    """Fire several POSTs AT ONCE and wait for all of them.

    Needed because the bug this covers only appears under concurrency: a
    sequential loop can never reproduce it."""
    procs = []
    for body in bodies:
        env = {'GDLE_FLAG': '1', 'GDLE_HELP': '0', 'GDLE_RETRY': '0',
               'GDLE_SERVERPLAY': '1', 'GDLE_METHOD': 'POST',
               'GDLE_QS': 'local_date=' + PLAY_DATE, 'GDLE_BODY': json.dumps(body),
               'GDLE_NONCE': nonce, 'GDLE_ENDPOINT': ENDPOINT,
               'GDLE_COOKIE_NAME': cookie_name, 'GDLE_COOKIE': cookie}
        procs.append(subprocess.Popen(
            'sudo -u looth-dev env %s php %s' % (
                ' '.join('%s=%s' % (k, shlex.quote(v)) for k, v in env.items()), PROBE),
            shell=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True))
    out = []
    for pr in procs:
        so, _ = pr.communicate()
        lines = (so or '').strip().splitlines()
        try:
            out.append(json.loads(lines[-1]) if lines else None)
        except ValueError:
            out.append(None)
    return out


src = open(ENDPOINT).read()

print('=== GATE 41: the board is scored on what the SERVER watched ===')
print('endpoint: %s' % ENDPOINT)
print('probe member: wp_user_id=%d   play_date=%s\n' % (UID, PLAY_DATE))

# ── PHASE 0 — liveness ───────────────────────────────────────────────────────
print('PHASE 0 — liveness')
wipe()
live = call(True, 'GET')
check(live.get('authenticated') is True and live.get('wp_user_id') == UID,
      'the endpoint answers as the probe MEMBER', repr(live)[:180])
NONCE = live.get('nonce', '')
check(len(NONCE) >= 8, 'it issues a usable nonce')
print()

# ── PHASE 1 — flag OFF is a no-op ────────────────────────────────────────────
print('PHASE 1 — flag OFF: no server play, and the old game is untouched')
off = call(False, 'GET')
check('serverplay' not in off and 'puzzle' not in off,
      'OFF: the client is told nothing about server play', repr(list(off.keys())))
check(list(off.keys()) == ['authenticated', 'wp_user_id', 'nonce', 'today', 'claim', 'pending'],
      'OFF: the payload is otherwise unchanged', repr(list(off.keys())))
r = call(False, 'POST', body={'action': 'reveal', 'letter': 'E', 'local_date': PLAY_DATE}, nonce=NONCE)
check(r.get('error') == 'not_enabled', 'OFF: reveal does not exist', repr(r))
r = call(False, 'POST', body={'action': 'guess', 'guess': 'X', 'local_date': PLAY_DATE}, nonce=NONCE)
check(r.get('error') == 'not_enabled', 'OFF: guess does not exist', repr(r))
check(psql("SELECT count(*) FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID) == '0',
      'OFF: neither wrote a row')
r = call(False, 'POST', body={'phrase_id': 1, 'won': True, 'moves': 5, 'streak': 0,
                              'local_date': PLAY_DATE}, nonce=NONCE)
check(r.get('recorded') is True, 'OFF: the legacy finish still works', repr(r))
wipe()
print()

# ── PHASE 2 — the two implementations must agree ─────────────────────────────
print('PHASE 2 — the server resolves the SAME puzzle the client would')
try:
    seq = json.load(open(os.path.join(ASSETS, 'sequence.json')))
    rows = list(csv.reader(open(os.path.join(ASSETS, 'guitardle_phrases.csv'))))
except Exception as e:
    cannot_run('could not read the puzzle assets: %s' % e)
pmap = {int(r[0]): ','.join(r[1:-1]).strip() for r in rows[1:] if r and r[-1].strip() == '1'}
start = datetime.date.fromisoformat(seq['startDate'])
today = datetime.date.fromisoformat(PLAY_DATE)
n = len(seq['sequence'])
for member, label, shift in ((True, 'member', 0), (False, 'logged-out', n // 2)):
    want_id = seq['sequence'][(((today - start).days + shift) % n + n) % n]
    want = (pmap.get(want_id) or '').upper()
    got_id = php_puzzle('echo lg_gdle_phrase_id(%s, %s);'
                        % (json.dumps(PLAY_DATE), 'true' if member else 'false'))
    got = php_puzzle('echo lg_gdle_phrase(lg_gdle_phrase_id(%s, %s));'
                     % (json.dumps(PLAY_DATE), 'true' if member else 'false'))
    check(str(want_id) == got_id and want == got,
          '*** %s track: PHP and the client arithmetic agree (id %s) ***' % (label, want_id),
          'python says %s/%r, php says %s/%r' % (want_id, want, got_id, got))
letters = php_puzzle('echo lg_gdle_letters(lg_gdle_phrase(lg_gdle_phrase_id(%s,true)));'
                     % json.dumps(PLAY_DATE))
check(letters.isalpha() and len(letters) > 2,
      'the letter run is what a guess is compared against', repr(letters))
mv = php_puzzle('echo lg_gdle_moves(["P","L","U"],["O"]);')
check(mv == '5',
      'the move formula matches MEASURED gameplay (2 consonants + a bought vowel '
      '+ a placed vowel = 5)', repr(mv))
print()

# ── PHASE 3 — a real server-driven game ──────────────────────────────────────
print('PHASE 3 — the server counts the moves and judges the guess')
wipe()
target = letters[0]
is_vowel = target in VOWELS
rv = call(True, 'POST', body={'action': 'reveal', 'letter': target,
                              'local_date': PLAY_DATE}, nonce=NONCE)
check(rv.get('ok') is True, 'a reveal is accepted', repr(rv))
if is_vowel:
    check(rv.get('positions') == [] and target in (rv.get('purchased') or []),
          'a vowel first tap BUYS (no positions yet) and costs 1', repr(rv))
    rv = call(True, 'POST', body={'action': 'reveal', 'letter': target,
                                  'local_date': PLAY_DATE}, nonce=NONCE)
want_pos = [i for i, c in enumerate(letters) if c == target]
check(rv.get('positions') == want_pos,
      'it returns EVERY position of that letter, computed server-side',
      'want %r got %r' % (want_pos, rv.get('positions')))
again = call(True, 'POST', body={'action': 'reveal', 'letter': target,
                                 'local_date': PLAY_DATE}, nonce=NONCE)
check(again.get('error') == 'already_revealed', 'the same letter cannot be bought twice',
      repr(again))
moves_before = rv.get('moves')
g = call(True, 'POST', body={'action': 'guess', 'guess': 'DEFINITELY NOT IT',
                             'moves': 1, 'won': True, 'hardcore': True,
                             'local_date': PLAY_DATE}, nonce=NONCE)
check(g.get('won') is False,
      'a wrong guess is judged WRONG by the server, whatever the body claimed',
      repr(g))
check(g.get('moves') == moves_before + 1,
      '*** moves are what the SERVER watched (%s), not what the client sent (1) ***'
      % (moves_before + 1), repr(g))
check(g.get('hardcore') is False,
      'hardcore comes from the claim row, so the body cannot buy the 2x', repr(g))
row = psql("SELECT won||'|'||moves||'|'||hardcore FROM discovery.guitardle_results "
           "WHERE wp_user_id=%d;" % UID)
check(row == 'false|%d|false' % (moves_before + 1),
      'and that is what landed in the table', row)
replay = call(True, 'POST', body={'action': 'guess', 'guess': letters,
                                  'local_date': PLAY_DATE}, nonce=NONCE)
check(replay.get('error') == 'already_played', 'the day cannot be replayed', repr(replay))
print()

# ── PHASE 3b — the reveal critical section is SERIALISED ────────────────────
print('PHASE 3b — concurrent reveals cannot be had for the price of one')
# The bug, found by self-review: reveal was a read-modify-write on resume_state,
# so N simultaneous reveals each read the same state and each returned THEIR OWN
# positions -- the player saw N letters -- while only the last write survived and
# the server charged for ONE move. Fixed with SELECT ... FOR UPDATE.
#
# ⚠️ MY FIRST VERSION OF THIS PHASE WAS DECORATION, and the red-first is the only
# reason I know. It fired four reveals through the probe and asserted the move
# arithmetic -- and it PASSED with the FOR UPDATE stripped back out. Measured
# why: each probe boots WordPress and takes ~2.5s, while the critical section is
# sub-millisecond, so four processes launched in a loop never overlap. Collision
# probability ≈ 0. A burst test cannot reach this bug, so the burst is kept only
# as a smoke check and the REAL assertions are the two below it.
src_rev = src[src.index("if ($action === 'reveal' || $action === 'guess')"):]
src_rev = src_rev[:src_rev.index("// The legacy finish")]
sel_at = src_rev.find('SELECT moves, hardcore, resume_state')
txn_at = src_rev.find('beginTransaction')
check(sel_at > 0 and 'FOR UPDATE' in src_rev[sel_at:sel_at + 400],
      '*** the reveal SELECT takes the row lock (FOR UPDATE) ***')
check(0 < txn_at < sel_at,
      'and it is inside a transaction opened BEFORE the read — a lock outside '
      'one is released immediately and serialises nothing')
check(src_rev.count('commit()') >= 2,
      'both success paths commit, so the lock is not held past the response')
check(src_rev.count('rollBack()') >= 4,
      'and every early exit inside the transaction releases it first')

# Behavioural proof that the lock really serialises on THIS table: hold the row
# in one session and prove a second session cannot take it. Deterministic, unlike
# a burst.
wipe()
call(True, 'POST', body={'action': 'reveal', 'letter': letters[0],
                         'local_date': PLAY_DATE}, nonce=NONCE)
holder = subprocess.Popen(
    'sudo -u postgres psql -tAd looth -c %s' % shlex.quote(
        "BEGIN; SELECT 1 FROM discovery.guitardle_results WHERE wp_user_id=%d "
        "FOR UPDATE; SELECT pg_sleep(4); COMMIT;" % UID),
    shell=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1.5)
r = sh("sudo -u postgres psql -tAd looth -c %s" % shlex.quote(
    "SELECT 1 FROM discovery.guitardle_results WHERE wp_user_id=%d FOR UPDATE NOWAIT;" % UID))
blocked = 'could not obtain lock' in (r.stderr or '').lower() or r.returncode != 0
check(blocked,
      '*** a second session CANNOT take the row while the first holds it — the '
      'lock genuinely serialises the critical section ***',
      'psql rc=%s stderr=%r' % (r.returncode, (r.stderr or '')[:120]))
holder.wait(timeout=15)
free = sh("sudo -u postgres psql -tAd looth -c %s" % shlex.quote(
    "SELECT 1 FROM discovery.guitardle_results WHERE wp_user_id=%d FOR UPDATE NOWAIT;" % UID))
check(free.returncode == 0,
      'and the row is takeable again once that transaction ends (no lock leak)')

# Smoke only — see the note above: this CANNOT catch the race.
burst = [c for c in dict.fromkeys(letters) if c not in VOWELS][:4]
wipe()
if len(burst) >= 2:
    res = call_many([{'action': 'reveal', 'letter': c, 'local_date': PLAY_DATE}
                     for c in burst], NONCE)
    final = psql("SELECT (resume_state->>'moves') FROM discovery.guitardle_results "
                 "WHERE wp_user_id=%d;" % UID)
    want = sum(2 if c in VOWELS else 1 for c in burst)
    check(len([r for r in res if r and r.get('ok')]) == len(burst) and final == str(want),
          'smoke: %d reveals in flight together still bill %d moves (evidence, '
          'NOT proof — see the note above)' % (len(burst), want),
          'moves=%s want=%d' % (final, want))
wipe()
print()

# ── PHASE 4 — every other door into the score ────────────────────────────────
print('PHASE 4 — the doors that would make all of the above pointless')
wipe()
call(True, 'POST', body={'action': 'reveal', 'letter': target, 'local_date': PLAY_DATE}, nonce=NONCE)
forged = call(True, 'POST', body={'phrase_id': 1, 'won': True, 'moves': 1,
                                  'hardcore': True, 'streak': 99,
                                  'local_date': PLAY_DATE}, nonce=NONCE)
check(forged.get('error') == 'use_guess_action',
      '*** the legacy finish — a forged 1-move hardcore win — is REFUSED ***', repr(forged))
saved = call(True, 'POST', body={'action': 'save', 'local_date': PLAY_DATE,
                                 'state': {'moves': 1, 'revealed': [], 'purchased': []}},
             nonce=NONCE)
check(saved.get('error') == 'server_owns_state',
      "*** 'save' is REFUSED — it wrote the very sets moves are counted from ***",
      repr(saved))
still = psql("SELECT moves IS NULL FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID)
check(still == 't', 'neither door touched the in-progress row', still)
wipe()
print()

# ── PHASE 5 — the answer must not reach the client ───────────────────────────
print('PHASE 5 — the client is never handed the phrase')
sp = call(True, 'GET')
blob = json.dumps(sp).upper()
check('puzzle' in sp and 'shape' in (sp.get('puzzle') or {}),
      'ON: the client gets a board SHAPE')
check(letters.upper() not in blob,
      '*** the phrase is NOT anywhere in the pre-game payload ***',
      'found %r in the GET response' % letters)
shape = sp['puzzle']['shape']
check(sum(1 for w in shape for s in w if s == 'letter') == len(letters),
      'the shape has exactly as many letter slots as the phrase has letters',
      '%d vs %d' % (sum(1 for w in shape for s in w if s == 'letter'), len(letters)))
js = open(GAME_JS).read()
srv = js[js.find('function renderPhraseFromShape'):]
srv = srv[:srv.find('\nfunction renderPhrase(')]
check('dataset.letter' not in srv,
      '*** the server-driven renderer never writes data-letter — measured in a '
      'browser, the legacy board leaked all 18 tiles ***')
check('dataset.i' in srv, 'it indexes tiles instead, which is what positions address')
wipe()
check(psql("SELECT count(*) FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID) == '0',
      'the gate cleaned up every row it wrote')
wp('$u = get_user_by("login","%s"); if ($u) { require_once ABSPATH."wp-admin/includes/user.php"; '
   'wp_delete_user($u->ID); } echo "gone";' % PROBE_LOGIN)
check(wp('$u = get_user_by("login","%s"); echo $u ? "STILL-THERE" : "gone";' % PROBE_LOGIN) == 'gone',
      'and removed its own per-run probe account')

print()
if fails:
    print('############ GATE 41 RED — %d assertion(s) failed ############' % len(fails))
    for f in fails:
        print('  - ' + f)
    sys.exit(1)
print('############ GATE 41 GREEN ############')
sys.exit(0)
