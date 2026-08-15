#!/usr/bin/env python3
"""
GATE NN — a finished Guitardle result must not be LOST to an expired nonce.

Backlog 24. Measured on live over 7 days: 101 finished games POSTed, **8 came
back 403**, from 8 distinct IPs across 6 days. A WP nonce lives ~12h and the
game sits in a front-page iframe people leave open, so a tab opened last night
and played this morning carries a dead one. Every call ended in
`.catch(() => {})`, so the player saw their win card, believed it counted, and
it never reached the board. Roughly **1 game in 12**, landing hardest on the
members who play most -- the opposite fairness failure to the one that started
this lane.

── WHY THIS IS NOT A BROWSER TEST ────────────────────────────────────────────
The retry is client-side, so the obvious gate drives a browser. A browser
dependency would make this flake on a 2-core box, and a gate that goes DEAD
blocks every lane -- a worse outcome than the coverage it buys. Instead
guitardle-retry-harness.js SLICES the shipped refreshNonce()/postWithNonce() out
of game.js and evaluates that source against a stubbed network. It is the real
code, not a re-implementation, so the harness cannot drift from what ships; if
the functions are ever renamed or moved it reports CANNOT RUN rather than
passing vacuously.

── THE TWO HALVES, AND WHY BOTH ARE NEEDED ───────────────────────────────────
Server: a stale nonce really is answered 403 (so 403 is the right trigger), and
a fresh one really does record. Client: on 403 it re-fetches a nonce and resends
the SAME body ONCE, carrying the NEW nonce. Either half alone proves nothing --
"the client retries" is worthless if the server does not answer 403, and
"the server records" is worthless if nothing ever resends.

Exit: 0 green, 1 defect, 2 could-not-run.
"""

import json
import os
import shlex
import subprocess
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PROBE = os.path.join(ROOT, 'tools', 'gates', 'guitardle-claim-probe.php')
HARNESS = os.path.join(ROOT, 'tools', 'gates', 'guitardle-retry-harness.js')
ENDPOINT = os.environ.get(
    'LG_GDLE_ENDPOINT',
    os.path.join(ROOT, 'archive-poc', 'api', 'v0', 'guitardle-score.php'))
GAME_JS = os.environ.get(
    'LG_GDLE_GAME_JS',
    os.path.join(ROOT, 'archive-poc', 'web', 'guitardle', 'game.js'))

WP_PATH = '/var/www/dev'
PROBE_LOGIN = 'gdle_gate_probe'
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

def sh(cmd):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True)

def wp(php):
    r = sh("sudo -u looth-dev wp --path=%s eval %s" % (WP_PATH, shlex.quote(php)))
    if r.returncode != 0:
        cannot_run('wp-cli failed: ' + (r.stderr or r.stdout).strip()[:300])
    return [l for l in r.stdout.splitlines() if not l.startswith(('PHP Warning', 'Warning'))][-1].strip()

def psql(sql):
    r = sh("sudo -u postgres psql -tAd looth -c %s" % shlex.quote(sql))
    if r.returncode != 0:
        cannot_run('psql failed: ' + r.stderr.strip()[:300])
    return r.stdout.strip()

for f in (PROBE, HARNESS, ENDPOINT, GAME_JS):
    if not os.path.exists(f):
        cannot_run('missing ' + f)
if sh('sudo -n -u looth-dev true').returncode != 0:
    cannot_run('no passwordless sudo to looth-dev')
if sh('sudo -n -u postgres true').returncode != 0:
    cannot_run('no passwordless sudo to postgres')
if sh('node --version').returncode != 0:
    cannot_run('node is not available (the retry harness needs it)')

uid = wp('$u = get_user_by("login","%s");'
         'if (!$u) { $id = wp_insert_user(["user_login"=>"%s","user_pass"=>wp_generate_password(24),'
         '"user_email"=>"gdle-gate-probe@invalid.local","role"=>"subscriber"]); $u = get_user_by("id",$id); }'
         'echo $u->ID;' % (PROBE_LOGIN, PROBE_LOGIN))
if not uid.isdigit():
    cannot_run('could not resolve the probe user: ' + uid)
UID = int(uid)
cookie_name = wp('echo LOGGED_IN_COOKIE;')
cookie = wp('$t = WP_Session_Tokens::get_instance(%d)->create(time()+3600);'
            'echo wp_generate_auth_cookie(%d, time()+3600, "logged_in", $t);' % (UID, UID))

def wipe():
    psql("DELETE FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID)

def call(retry_flag, method='GET', body=None, nonce='', claim=True):
    env = {'GDLE_FLAG': '1' if claim else '0', 'GDLE_HELP': '0',
           'GDLE_RETRY': '1' if retry_flag else '0',
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

print('=== GATE NN: a finished result survives an EXPIRED NONCE ===')
print('endpoint: %s' % ENDPOINT)
print('game.js : %s' % GAME_JS)
print('probe member: wp_user_id=%d\n' % UID)

# ── PHASE 0 — liveness ───────────────────────────────────────────────────────
print('PHASE 0 — liveness')
wipe()
live = call(True, 'GET')
check(live.get('authenticated') is True and live.get('wp_user_id') == UID,
      'the endpoint answers as the probe MEMBER', repr(live)[:200])
NONCE = live.get('nonce', '')
check(len(NONCE) >= 8, 'it issues a usable nonce', repr(NONCE))
print()

# ── PHASE 1 — the server half ────────────────────────────────────────────────
print('PHASE 1 — the server: a stale nonce IS a 403, and a fresh one records')
off = call(False, 'GET')
check('retry' not in off, 'flag OFF: the client is never told it may retry',
      repr(list(off.keys())))
check(list(off.keys()) == ['authenticated', 'wp_user_id', 'nonce', 'today', 'claim', 'pending'],
      'flag OFF: the payload is otherwise unchanged', repr(list(off.keys())))
on = call(True, 'GET')
check(on.get('retry') is True, 'flag ON: the client is told it may retry', repr(on))

stale = call(True, 'POST', body={'phrase_id': 42, 'won': True, 'moves': 6,
                                 'streak': 1, 'local_date': PLAY_DATE},
             nonce='DEADNONCE00')
check(stale.get('error') == 'bad_csrf',
      '*** a STALE nonce is answered bad_csrf — this is the 403 that binned 8 of '
      '101 live results ***', repr(stale))
check(psql("SELECT count(*) FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID) == '0',
      'and it recorded NOTHING (so the loss was real, not cosmetic)')

fresh = call(True, 'POST', body={'phrase_id': 42, 'won': True, 'moves': 6,
                                 'streak': 1, 'local_date': PLAY_DATE},
             nonce=NONCE)
check(fresh.get('recorded') is True,
      'the SAME result, resent with a fresh nonce, records', repr(fresh))
check(psql("SELECT moves FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID) == '6',
      'the recovered row carries the real score, not a placeholder')
wipe()
print()

# ── PHASE 2 — the client half, on the shipped source ─────────────────────────
print('PHASE 2 — the client: it re-fetches and resends ONCE, and only on a 403')
r = sh('node %s %s' % (shlex.quote(HARNESS), shlex.quote(GAME_JS)))
if r.returncode == 2 or 'CANNOT-EXTRACT' in (r.stderr or ''):
    cannot_run('the harness could not find the shipped retry functions in game.js '
               '(renamed or moved?): ' + (r.stderr or '').strip()[:200])
if r.returncode != 0:
    cannot_run('retry harness failed: ' + (r.stderr or '').strip()[:300])
try:
    scen = {s['name']: s for s in json.loads(r.stdout)}
except ValueError:
    cannot_run('retry harness did not return JSON: ' + r.stdout[:200])

def posts(name):
    return [c for c in scen[name]['calls'] if c['method'] == 'POST']
def gets(name):
    return [c for c in scen[name]['calls'] if c['method'] == 'GET']

check(len(posts('off_403_is_lost')) == 1 and len(gets('off_403_is_lost')) == 0,
      'flag OFF: a 403 is NOT retried — the old behaviour is preserved exactly')
check(len(posts('off_200_single_send')) == 1,
      'flag OFF: a good send is still a single request')
check(len(posts('on_403_then_retry_ok')) == 2 and len(gets('on_403_then_retry_ok')) == 1,
      '*** flag ON: a 403 buys ONE fresh nonce and ONE resend — the fix ***',
      repr(scen['on_403_then_retry_ok']['calls']))
check([p['nonce'] for p in posts('on_403_then_retry_ok')] == ['STALE-NONCE', 'FRESH-NONCE'],
      'and the resend carries the NEW nonce, not the dead one again',
      repr([p['nonce'] for p in posts('on_403_then_retry_ok')]))
check(posts('on_403_then_retry_ok')[0]['body'] == posts('on_403_then_retry_ok')[1]['body'],
      'the resent body is the SAME result — a retry, not a second game')
check(len(posts('on_200_no_extra_work')) == 1 and len(gets('on_200_no_extra_work')) == 0,
      'flag ON: a successful send costs no extra request')
check(len(posts('on_403_twice_stops')) == 2,
      'a still-403 after the retry STOPS — one retry, never a loop',
      repr(scen['on_403_twice_stops']['calls']))
check(len(posts('on_403_refresh_fails')) == 1 and 'threw' not in scen['on_403_refresh_fails'],
      'if the nonce re-fetch itself fails it gives up quietly, no throw')
check(len(posts('on_network_error')) == 1 and 'threw' not in scen['on_network_error'],
      'a network error is still swallowed — offline play never breaks')
print()

wipe()
check(psql("SELECT count(*) FROM discovery.guitardle_results WHERE wp_user_id=%d;" % UID) == '0',
      'the gate cleaned up every row it wrote')

print()
if fails:
    print('############ GATE NN RED — %d assertion(s) failed ############' % len(fails))
    for f in fails:
        print('  - ' + f)
    sys.exit(1)
print('############ GATE NN GREEN ############')
sys.exit(0)
