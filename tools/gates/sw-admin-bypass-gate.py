#!/usr/bin/env python3
"""
GATE 43 — wp-admin must NEVER render the PWA offline shell.

Backlog 28. Ian, 2026-08-15: a slow admin click showed him the game-like
"You're offline" page on a dashboard URL. He was not offline, and it is not our
surface — wp-admin should fail the way wp-admin fails, with the browser's own
network error, so the symptom names the real problem instead of blaming the
network.

── WHY THE WORKER SEES ADMIN URLS AT ALL ────────────────────────────────────
pwa.js registers with `scope: '/'`, so the worker controls EVERY same-origin
navigation. wp-admin never loads pwa.js and does not need to: a registration
made on a member page covers the whole origin. Both navigation branches in
sw.js — the RESILIENT one and the legacy one — end at
caches.match('/offline.html').

── THE ASSERTION THAT EARNS THIS GATE ───────────────────────────────────────
Phase 2 runs every check on the LIVE hostname as well as dev2. sw.js already
had a bypass list (BYPASS_PREFIXES), and the obvious fix was to add '/wp-admin'
to it. That fix would have been WRONG AND WOULD HAVE PASSED a dev2-only test:
isBypassed() opens with `if (!RESILIENT || !IS_DEV2) return false`, so it is
inert on loothgroup.com and inert whenever the pwa-sw flag is off. A gate that
only exercised dev2 would have blessed a change that does nothing where Ian's
members are.

Driven through tools/gates/lib/sw-handler-harness.js, which loads the REAL
sw.js under a stubbed worker global — so this tests the shipped file, not a
paraphrase, and no browser is involved (a service worker is its own target, and
a CDP network stall lands on the page's).

Exit: 0 green, 1 defect, 2 could-not-run.
"""

import json
import os
import shlex
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
HARNESS = os.path.join(ROOT, 'tools', 'gates', 'lib', 'sw-handler-harness.js')
SW = os.environ.get('LG_SW_PATH', os.path.join(ROOT, 'webroot', 'sw.js'))

HOSTS = ['dev2.loothgroup.com', 'loothgroup.com']
FLAG_STATES = [('', 'flag OFF'), ('f=resilient', 'flag ON')]

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

for f in (HARNESS, SW):
    if not os.path.exists(f):
        cannot_run('missing ' + f)
if sh('node --version').returncode != 0:
    cannot_run('node is not available (the sw harness needs it)')

def run(case, host, flags='', url=None, budget=9000):
    cmd = 'node %s %s --sw %s --host %s --budget %d' % (
        shlex.quote(HARNESS), case, shlex.quote(SW), shlex.quote(host), budget)
    if flags:
        cmd += ' --flags ' + shlex.quote(flags)
    if url:
        cmd += ' --url ' + shlex.quote(url)
    r = sh(cmd)
    if r.returncode != 0 or not r.stdout.strip():
        cannot_run('harness failed for %s/%s: %s' % (case, host, (r.stderr or '')[:200]))
    try:
        return json.loads(r.stdout.strip().splitlines()[-1])
    except ValueError:
        cannot_run('harness did not return JSON: ' + r.stdout[:200])

print('=== GATE 43: wp-admin never renders the PWA offline shell ===')
print('sw.js under test: %s\n' % SW)

# ── PHASE 0 — liveness ───────────────────────────────────────────────────────
print('PHASE 0 — liveness (a harness that answers nothing would pass every absence)')
live = run('ok', 'dev2.loothgroup.com')
check(live.get('handled') is True and 'REAL PAGE' in str(live.get('served')),
      'the worker still handles an ordinary navigation and serves the real page',
      repr(live))
print()

# ── PHASE 1 — the defect itself ──────────────────────────────────────────────
print("PHASE 1 — an admin URL under origin failure shows the BROWSER's error")
for host in HOSTS:
    for flags, label in FLAG_STATES:
        r = run('admin-path', host, flags)
        check(r.get('handled') is False,
              '*** %-20s %-9s: /wp-admin is NOT intercepted ***' % (host, label),
              'handled=%r served=%r' % (r.get('handled'), r.get('served')))
print()

# ── PHASE 2 — the member surface must be UNTOUCHED ───────────────────────────
print('PHASE 2 — a member page under the SAME failure still gets the shell')
# Without this, "nothing is intercepted" would pass phase 1 perfectly — by
# breaking offline support for everybody.
for host in HOSTS:
    for flags, label in FLAG_STATES:
        r = run('reject', host, flags)
        check(r.get('handled') is True and 'OFFLINE' in str(r.get('served')).upper(),
              '%-20s %-9s: /hub/ still falls back to the offline shell' % (host, label),
              'handled=%r served=%r' % (r.get('handled'), r.get('served')))
print()

# ── PHASE 3 — the prefix must not over-reach ─────────────────────────────────
print('PHASE 3 — only the admin surfaces, matched precisely')
BYPASS = ['/wp-admin', '/wp-admin/', '/wp-admin/index.php', '/wp-admin/edit.php?post=1',
          '/wp-login.php']
KEEP = ['/wp-adminfoo', '/wp-admin-ish/', '/hub/wp-admin/', '/hub/', '/members/']
host = 'loothgroup.com'
for u in BYPASS:
    r = run('admin-path', host, url='https://%s%s' % (host, u))
    check(r.get('handled') is False, 'bypassed: %-26s' % u, repr(r.get('handled')))
for u in KEEP:
    r = run('admin-path', host, url='https://%s%s' % (host, u))
    check(r.get('handled') is True,
          'NOT bypassed: %-22s (a loose indexOf prefix swallows these — measured)' % u,
          repr(r.get('handled')))
print()

# ── PHASE 4 — the admin rule is ORIGIN-SCOPED ────────────────────────────────
print('PHASE 4 — the admin rule does not fire on a foreign origin')
# I first asserted the opposite here and the gate caught me. A cross-origin
# '/wp-admin/' is still HANDLED, and that is correct: the admin bypass is
# deliberately origin-scoped, so it declines to claim a path it does not own, and
# the ordinary navigation branch then behaves as it always has. If this ever
# reported handled=False it would mean isAdminSurface() had started matching on
# PATH ALONE — deciding what /wp-admin means on somebody else's domain.
#
# Worth saying plainly: in a real browser this case cannot arise at all. A
# service worker only receives fetch events for its own scope, so a top-level
# navigation to another origin never reaches this code. The harness fires the
# handler directly, which is what makes the origin check observable.
r = run('admin-path', 'dev2.loothgroup.com', url='https://example.org/wp-admin/')
check(r.get('handled') is True,
      'a foreign-origin /wp-admin/ is NOT claimed by the admin rule '
      '(origin-scoped, not path-matched)', repr(r))

print()
if fails:
    print('############ GATE 43 RED — %d assertion(s) failed ############' % len(fails))
    for f in fails:
        print('  - ' + f)
    sys.exit(1)
print('############ GATE 43 GREEN ############')
sys.exit(0)
