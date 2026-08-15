#!/usr/bin/env python3
"""
GATE 42 — the puzzle LIBRARY and the SEQUENCE never reach a browser.

Backlog 26 (keeper 2026-08-15; number pre-assigned). Gate 41 stopped the phrase
reaching server-driven MEMBERS, but it could not remove
assets/guitardle_phrases.csv and assets/sequence.json, because the logged-out
game still fetches them to draw its board and judge its own guess. Those two
files are 285 phrases plus the FIXED order, so today AND every future day AND
the member track are all computable by anyone who opens them. Quantified on
live: a member who does that banks ~140 points a week against a real weekly
leader on 62 -- permanent first place on a board with claimable spots.

WHAT THIS DOES AND DOES NOT CLAIM. It does not stop a logged-out board holding
its OWN day's phrase -- it must, because it judges its own guess, and an anon
player learns that phrase by playing anyway (their result is never recorded;
guitardle-score.php rejects uid<=0). What it removes is the LIBRARY and the
ORDER: no other day, no other track, nothing to compute forward from.

── THE ASSERTION THAT MATTERS MOST ──────────────────────────────────────────
Phase 3: there is deliberately NO aud= parameter on the day endpoint, and the
gate tries to find one anyway. Serving the member track here would restore the
exact hole gate 41 closed, through a door that needs no login at all.

── AND THE ONE THAT WILL SAVE A DEPLOY ──────────────────────────────────────
Phase 5: this is stage ONE of two. The assets must STILL BE PRESENT and still
served, because a member on the legacy path needs their own track's letters to
draw a board at all. Pulling them before both flags are on everywhere is a
blank game, not a degraded one. The gate asserts they are still there, so
"tidying them away" fails here rather than on Ian's phone.

Exit: 0 green, 1 defect, 2 could-not-run.
"""

import json
import os
import shlex
import subprocess
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ENDPOINT = os.environ.get(
    'LG_GDLE_DAY_ENDPOINT',
    os.path.join(ROOT, 'archive-poc', 'api', 'v0', 'guitardle-puzzle.php'))
PUZZLE_PHP = os.path.join(ROOT, 'archive-poc', 'api', 'v0', '_guitardle-puzzle.php')
PROMO = os.path.join(ROOT, 'archive-poc', 'web', '_gdle-promo.php')
GAME_JS = os.path.join(ROOT, 'archive-poc', 'web', 'guitardle', 'game.js')
NGINX = os.path.join(ROOT, 'platform', 'nginx', 'strangler-archive-poc.conf')
ASSETS = os.path.join(ROOT, 'archive-poc', 'web', 'guitardle', 'assets')
PORT = int(os.environ.get('LG_GDLE_DAY_PORT', '8099'))

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

for f in (ENDPOINT, PUZZLE_PHP, PROMO, GAME_JS, NGINX):
    if not os.path.exists(f):
        cannot_run('missing ' + f)
if sh('php --version').returncode != 0:
    cannot_run('php is not available')

print('=== GATE 42: the puzzle LIBRARY and SEQUENCE never reach a browser ===')
print('endpoint: %s\n' % ENDPOINT)

# Serve it for real rather than include()ing it: an endpoint that works as an
# include and 500s over HTTP is a class of bug this would otherwise miss.
srv = subprocess.Popen(['php', '-S', '127.0.0.1:%d' % PORT, '-t',
                        os.path.dirname(ENDPOINT)],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(2)
BASE = 'http://127.0.0.1:%d/%s' % (PORT, os.path.basename(ENDPOINT))

def get(qs='', method='GET'):
    r = sh('curl -s -X %s -w "\\n%%{http_code}" %s' % (method, shlex.quote(BASE + qs)))
    parts = (r.stdout or '').rsplit('\n', 1)
    body = parts[0] if len(parts) == 2 else ''
    code = parts[1].strip() if len(parts) == 2 else '000'
    try:
        return json.loads(body), code
    except ValueError:
        return None, code

def php_expr(expr):
    r = sh('php -r %s' % shlex.quote('require %s; %s' % (json.dumps(PUZZLE_PHP), expr)))
    return r.stdout.strip()

try:
    today = time.strftime('%Y-%m-%d', time.gmtime())

    # ── PHASE 0 — liveness ───────────────────────────────────────────────────
    print('PHASE 0 — liveness (an unreachable endpoint makes every check below vacuous)')
    body, code = get()
    if body is None:
        cannot_run('the day endpoint did not answer over HTTP (code %s)' % code)
    check(code == '200' and isinstance(body.get('phrase'), str) and body['phrase'],
          'it answers over real HTTP with a phrase', repr((code, body)))
    print()

    # ── PHASE 1 — it is the LOGGED-OUT track ────────────────────────────────
    print("PHASE 1 — it serves the logged-out track's phrase, correctly")
    want_id = php_expr('echo lg_gdle_phrase_id(%s, false);' % json.dumps(today))
    want    = php_expr('echo lg_gdle_phrase(lg_gdle_phrase_id(%s, false));' % json.dumps(today))
    check(str(body.get('phrase_id')) == want_id and body.get('phrase', '').upper() == want,
          "it matches the resolver's logged-out answer for the server's today",
          'want %s/%r got %s/%r' % (want_id, want, body.get('phrase_id'), body.get('phrase')))
    member_phrase = php_expr('echo lg_gdle_phrase(lg_gdle_phrase_id(%s, true));' % json.dumps(today))
    check(member_phrase and member_phrase != want,
          'the two tracks really are different today (else the next check proves nothing)',
          'member=%r logged-out=%r' % (member_phrase, want))
    print()

    # ── PHASE 2 — NO request shape can ask about another day ────────────────
    print('PHASE 2 — the calendar cannot be asked about at all (keeper 2026-08-15)')
    src0 = open(ENDPOINT).read()
    check('$_GET' not in src0 and '$_POST' not in src0 and '$_REQUEST' not in src0,
          '*** the endpoint reads NO superglobal — the server clock picks the day ***')
    today_id = body.get('phrase_id')
    probes = ['?local_date=' + time.strftime('%Y-%m-%d', time.gmtime(time.time() + 86400)),
              '?local_date=' + time.strftime('%Y-%m-%d', time.gmtime(time.time() - 86400)),
              '?local_date=2027-01-01', '?date=2027-01-01', '?day=2027-01-01',
              '?d=99', '?index=17', '?i=17', '?offset=17', '?n=17',
              '?phrase_id=1', '?id=1', '?seq=1']
    for qs in probes:
        b, _ = get(qs)
        if not check(b is not None and b.get('phrase_id') == today_id,
                     '*** %-26s cannot move the day off today ***' % qs, repr(b)):
            break
    # An earlier draft DID accept ?local_date with a +/-1 clamp, which felt
    # consistent with the score API. It was still an oracle: a read-only endpoint
    # that answers for a day you name rebuilds the key on a delay, one query at a
    # time. There is no window small enough to be safe, so there is no window.
    print()

    # ── PHASE 2b — it must not survive the day boundary ─────────────────────
    print('PHASE 2b — no cache may outlive the day (this sits behind Cloudflare)')
    hdr = sh('curl -s -D- -o /dev/null %s' % shlex.quote(BASE)).stdout.lower()
    check('no-store' in hdr,
          '*** Cache-Control says no-store — an edge that caches across midnight '
          'serves yesterday\'s phrase or pre-bakes today\'s ***',
          repr([l for l in hdr.splitlines() if 'cache' in l]))
    check('max-age=0' in hdr or 'no-cache' in hdr,
          'and it is belt-and-braces for an edge that only honours some of them')
    check('cdn-cache-control' in hdr,
          'CDN-Cache-Control is set too, since Cloudflare honours it over the '
          'generic header when both are present')
    print()

    # ── PHASE 3 — THE MEMBER TRACK IS NOT REACHABLE ─────────────────────────
    print('PHASE 3 — no parameter reaches the member track')
    src = open(ENDPOINT).read()
    check("'aud'" not in src and '"aud"' not in src and 'REQUEST[' not in src,
          'the endpoint reads no audience parameter at all')
    for qs in ('?aud=m', '?aud=member', '?member=1', '?track=member', '?is_member=true'):
        b, _ = get(qs)
        if not check(b is not None and b.get('phrase', '').upper() != member_phrase.upper(),
                     '*** %s does NOT return the member phrase ***' % qs,
                     repr(b)):
            break
    print()

    # ── PHASE 4 — the client stops fetching the library ─────────────────────
    print('PHASE 4 — the logged-out client asks for a day, not the library')
    js = open(GAME_JS).read()
    day = js[js.find('async function loadPhraseForDay'):]
    day = day[:day.find('\nasync function loadPhrase(')]
    check('guitardle_phrases.csv' not in day and 'sequence.json' not in day,
          '*** the day loader fetches NEITHER the library NOR the sequence ***')
    check('PUZZLE_API' in day, 'it asks the day endpoint instead')
    check("WANT_DAY_PUZZLE) return loadPhraseForDay()" in js,
          'and loadPhrase() routes to it when the page was built for it')
    promo = open(PROMO).read()
    check("LG_GUITARDLE_DAY_PUZZLE && !$is_member" in promo,
          '*** dp=1 is emitted for LOGGED-OUT visitors ONLY — a member sent here '
          'would get a different phrase than the one their day is scored on ***')
    check("LG_GUITARDLE_DAY_PUZZLE" in open(os.path.join(ROOT, 'archive-poc', 'api', 'v0', '_flags.php')).read(),
          'the flag exists and is the one the promo reads')
    print()

    # ── PHASE 5 — the two-stage deploy is not short-circuited ───────────────
    print('PHASE 5 — stage ONE only: the assets must still be there')
    for name in ('guitardle_phrases.csv', 'sequence.json'):
        check(os.path.exists(os.path.join(ASSETS, name)),
              'assets/%s is STILL PRESENT — removing it before BOTH flags are on '
              'everywhere is a blank board for legacy members, not a degraded one'
              % name)
    conf = open(NGINX).read()
    check('guitardle-puzzle/?$' in conf, 'the clean-URL rewrite for the endpoint is in the conf')
    check('guitardle-puzzle)' in conf or 'guitardle-puzzle|' in conf,
          '*** the .php is in the EXPLICIT location list — a .php with no location '
          'of its own falls through the parent alias and is served as READABLE '
          'SOURCE (trap-api-v0-php-source-disclosure) ***')
finally:
    srv.terminate()
    try:
        srv.wait(timeout=5)
    except Exception:
        srv.kill()

print()
if fails:
    print('############ GATE 42 RED — %d assertion(s) failed ############' % len(fails))
    for f in fails:
        print('  - ' + f)
    sys.exit(1)
print('############ GATE 42 GREEN ############')
sys.exit(0)
