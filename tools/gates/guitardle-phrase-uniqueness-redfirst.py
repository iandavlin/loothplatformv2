#!/usr/bin/env python3
r"""
RED-FIRST for gate 60 (guitardle-phrase-uniqueness-gate.py).

Every assertion in that gate is broken here, one at a time, against a SNAPSHOT
COPY of the assets -- never the working tree. (A harness that restores with
`git checkout --` wipes uncommitted work under test; that mistake once turned
one harness bug into ten false "the assertion is decoration" verdicts.)

Two properties this harness enforces on itself, because a red-first that stays
green is the finding rather than a pass:
  * every mutation must actually CHANGE the file -- a no-op mutation fails loud
    instead of quietly "biting";
  * the unmutated control must go GREEN, so a gate that is red for an unrelated
    reason cannot make the whole sweep look alive.

Exit: 0 every assertion bites, 1 one or more are decoration, 2 could-not-run.
"""

import json
import os
import re
import shutil
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SRC = os.path.join(ROOT, 'archive-poc', 'web', 'guitardle', 'assets')
GATE = os.path.join(ROOT, 'tools', 'gates', 'guitardle-phrase-uniqueness-gate.py')

if not os.path.isdir(SRC) or not os.path.exists(GATE):
    print('CANNOT RUN: missing assets dir or gate')
    sys.exit(2)


def run_gate(assets):
    env = dict(os.environ, LG_GDLE_ASSETS=assets)
    r = subprocess.run([sys.executable, GATE], capture_output=True, text=True, env=env)
    return r.returncode, r.stdout + r.stderr


# ── mutations ────────────────────────────────────────────────────────────────
def mut_dup_text(d):
    """Two ids, same phrase -- the defect Ian actually hit."""
    p = os.path.join(d, 'guitardle_phrases.csv')
    b = open(p, 'rb').read()
    return p, b, b.replace(b'\r\n231,radius block,1\r\n', b'\r\n231,leveling beam,1\r\n')


def mut_dup_text_case(d):
    """Same phrase, different case -- must still count as the same puzzle."""
    p = os.path.join(d, 'guitardle_phrases.csv')
    b = open(p, 'rb').read()
    return p, b, b.replace(b'\r\n231,radius block,1\r\n', b'\r\n231,LEVELING BEAM,1\r\n')


def mut_dup_text_hyphen(d):
    """Same letters, hyphen instead of space -- same answer, same win compare."""
    p = os.path.join(d, 'guitardle_phrases.csv')
    b = open(p, 'rb').read()
    return p, b, b.replace(b'\r\n231,radius block,1\r\n', b'\r\n231,leveling-beam,1\r\n')


def mut_dup_id(d):
    p = os.path.join(d, 'guitardle_phrases.csv')
    b = open(p, 'rb').read()
    return p, b, b.replace(b'\r\n231,radius block,1\r\n', b'\r\n232,radius block,1\r\n')


def mut_non_ascii(d):
    """A curly apostrophe -- byte 0x99 inside a 3-byte char; \\R eats 0x85 kin."""
    p = os.path.join(d, 'guitardle_phrases.csv')
    b = open(p, 'rb').read()
    return p, b, b.replace(b'\r\n231,radius block,1\r\n',
                           '\r\n231,luthier’s radius block,1\r\n'.encode('utf-8'))


def mut_bare_cr(d):
    """Bare CR: PHP \\R splits it, JS split('\\n') does not."""
    p = os.path.join(d, 'guitardle_phrases.csv')
    b = open(p, 'rb').read()
    return p, b, b.replace(b'\r\n231,radius block,1\r\n', b'\r\n231,radius\rblock,1\r\n')


def mut_vtab(d):
    """Vertical tab: plain ASCII, so ONLY the two-parsers assertion can see it."""
    p = os.path.join(d, 'guitardle_phrases.csv')
    b = open(p, 'rb').read()
    return p, b, b.replace(b'\r\n231,radius block,1\r\n', b'\r\n231,radius\x0bblock,1\r\n')


def mut_empty_phrase(d):
    p = os.path.join(d, 'guitardle_phrases.csv')
    b = open(p, 'rb').read()
    return p, b, b.replace(b'\r\n231,radius block,1\r\n', b'\r\n231,,1\r\n')


def mut_bad_active(d):
    p = os.path.join(d, 'guitardle_phrases.csv')
    b = open(p, 'rb').read()
    return p, b, b.replace(b'\r\n231,radius block,1\r\n', b'\r\n231,radius block,yes\r\n')


def mut_deactivate(d):
    """A row switched off is a day with no phrase -- a blank board."""
    p = os.path.join(d, 'guitardle_phrases.csv')
    b = open(p, 'rb').read()
    return p, b, b.replace(b'\r\n231,radius block,1\r\n', b'\r\n231,radius block,0\r\n')


def mut_seq_repeat(d):
    p = os.path.join(d, 'sequence.json')
    b = open(p, 'rb').read()
    j = json.loads(b)
    j['sequence'][40] = j['sequence'][10]
    return p, b, json.dumps(j, indent=2).encode('utf-8')


def mut_seq_unknown_id(d):
    p = os.path.join(d, 'sequence.json')
    b = open(p, 'rb').read()
    j = json.loads(b)
    j['sequence'][40] = 9999
    return p, b, json.dumps(j, indent=2).encode('utf-8')


def mut_seq_short(d):
    """Drop a day: some phrase is then never served at all."""
    p = os.path.join(d, 'sequence.json')
    b = open(p, 'rb').read()
    j = json.loads(b)
    j['sequence'] = j['sequence'][:-1]
    return p, b, json.dumps(j, indent=2).encode('utf-8')


MUTATIONS = [
    ('duplicate phrase text under two ids',   mut_dup_text,        'share a letter run'),
    ('same phrase, different CASE',           mut_dup_text_case,   'share a letter run'),
    ('same letters, hyphen for space',        mut_dup_text_hyphen, 'share a letter run'),
    ('duplicate id',                          mut_dup_id,          'unique id'),
    ('non-ASCII character in a phrase',       mut_non_ascii,       'pure ASCII'),
    ('bare CR inside a phrase',               mut_bare_cr,         'PHP'),
    ('vertical tab inside a phrase',          mut_vtab,            'PHP'),
    ('empty phrase',                          mut_empty_phrase,    'empty phrase'),
    ('non 0/1 active flag',                   mut_bad_active,      'active flag'),
    ('a phrase deactivated',                  mut_deactivate,      'resolves to an ACTIVE phrase'),
    ('sequence repeats an id',                mut_seq_repeat,      'repeats no id'),
    ('sequence names an unknown id',          mut_seq_unknown_id,  'resolves to an ACTIVE phrase'),
    ('sequence drops a day',                  mut_seq_short,       'equals the active-phrase count'),
]

bad = []
print('RED-FIRST — gate 60, %d mutations\n' % len(MUTATIONS))

work = tempfile.mkdtemp(prefix='gdle-redfirst-%d-' % os.getpid())
try:
    assets = os.path.join(work, 'assets')

    # Control: the snapshot itself must be GREEN, or nothing below means anything.
    shutil.copytree(SRC, assets)
    rc, out = run_gate(assets)
    if rc != 0:
        print('  CONTROL FAIL  unmutated snapshot is not green (exit %d)' % rc)
        print('\n'.join('      ' + l for l in out.splitlines() if 'FAIL' in l or 'CANNOT' in l))
        print('\nCANNOT RUN: every mutation below would be meaningless.')
        sys.exit(2)
    print('  CONTROL  unmutated snapshot is GREEN\n')

    for label, fn, expect in MUTATIONS:
        shutil.rmtree(assets)
        shutil.copytree(SRC, assets)
        path, before, after = fn(assets)

        if before == after:
            bad.append(label + ' (NO-OP MUTATION — harness bug, nothing was changed)')
            print('  NO-OP   %-38s mutation changed nothing' % label)
            continue

        open(path, 'wb').write(after)
        rc, out = run_gate(assets)

        if rc == 2:
            bad.append(label + ' (gate could not run)')
            print('  ERROR   %-38s gate exited 2' % label)
        elif rc != 1:
            bad.append(label + ' (assertion is DECORATION — gate stayed green)')
            print('  GREEN   %-38s <-- assertion is decoration' % label)
        elif expect not in out:
            bad.append(label + ' (red, but not on the expected assertion: %r)' % expect)
            print('  WRONG   %-38s red on something else, not %r' % (label, expect))
        else:
            named = [l.strip() for l in out.splitlines() if l.strip().startswith('- ')]
            print('  BITES   %-38s %s' % (label, named[0][2:] if named else ''))
finally:
    shutil.rmtree(work, ignore_errors=True)

print()
if bad:
    print('RED-FIRST FAILED — %d problem(s):' % len(bad))
    for b in bad:
        print('  - ' + b)
    sys.exit(1)
print('RED-FIRST PASSED — all %d mutations bite the assertion they should' % len(MUTATIONS))
sys.exit(0)
