#!/usr/bin/env python3
r"""
GATE 55 — no player is ever served the same puzzle twice in a cycle.

Ian caught this on LIVE on 2026-08-15: members got DAN ERLEWINE on day 12
(23 June) and again on day 65 (15 August). The obvious diagnosis -- "the
shuffle repeats" -- was WRONG, and that matters, because the wrong fix here is
destructive. sequence.json is a clean no-repeat permutation of all 285 ids, and
the no-repeat-until-all-played mechanism works exactly as designed. What
defeated it was one PHRASE TEXT living under TWO IDS: id 180 and id 233 were
both "Dan Erlewine". The sequence never repeated an id; it served two different
ids that read the same to a player.

So the property worth gating is not "the sequence has no repeated id" -- that
was true the whole time this bug was live. It is the thing a player actually
experiences: WALK THE FULL CYCLE ON BOTH TRACKS AND SEE NO TEXT TWICE.
Phase 3 does that, and it is the assertion that would have caught this.

── WHY BOTH TRACKS ──────────────────────────────────────────────────────────
Logged-out players run half a sequence ahead of members (Ian's 6/11 ruling,
intdiv(len,2) = 142), so the two audiences never share a day's phrase. That
shift means the two tracks consume the sequence at different offsets, and a
duplicate can be a fairness bug on one track years before it surfaces on the
other -- the 180/233 pair had already bitten members twice while both entries
were still in the logged-out track's future. Gating one track would have called
this library clean.

── THE ASCII ASSERTION IS NOT HOUSEKEEPING ──────────────────────────────────
Phase 1 requires the library to stay pure ASCII, and that is load-bearing.
_guitardle-puzzle.php splits the CSV with preg_split('/\R/') with NO /u flag.
\R matches the bare byte 0x85, which is the third byte of many ordinary UTF-8
characters -- so a phrase containing a curly quote or an accented name would be
silently CUT IN HALF by the server parser while game.js's split('\n') parses it
whole. The server would then judge a different puzzle than the player saw,
which is worse than the duplicate this gate exists to stop, and it would fail
silently. Pure ASCII is what makes the two parsers agree.

── WHAT THIS DOES NOT CLAIM ─────────────────────────────────────────────────
It does not check that the client and the server resolve the same day -- gate
42 owns that cross-check. It reads the assets only; no browser, no WordPress,
no network.

Exit: 0 green, 1 defect, 2 could-not-run.
"""

import collections
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ASSETS = os.environ.get(
    'LG_GDLE_ASSETS',
    os.path.join(ROOT, 'archive-poc', 'web', 'guitardle', 'assets'))
CSV = os.path.join(ASSETS, 'guitardle_phrases.csv')
SEQ = os.path.join(ASSETS, 'sequence.json')

fails = []


def check(ok, label, detail=''):
    print(('  PASS  ' if ok else '  FAIL  ') + label + (('\n          ' + detail) if detail and not ok else ''))
    if not ok:
        fails.append(label)
    return ok


def cannot_run(why):
    print('CANNOT RUN: ' + why)
    sys.exit(2)


for f in (CSV, SEQ):
    if not os.path.exists(f):
        cannot_run('missing ' + f)

# ── Parse the library EXACTLY as both readers do ─────────────────────────────
# game.js and _guitardle-puzzle.php agree on this shape and both comment on the
# reason: a phrase may contain a comma, so id is the FIRST field, active is the
# LAST, and the phrase is everything between, re-joined.
raw = open(CSV, 'rb').read()
try:
    text = raw.decode('utf-8')
except UnicodeDecodeError as e:
    cannot_run('phrase CSV is not valid UTF-8: %s' % e)

# The file is CRLF. game.js splits on '\n' and trims; _guitardle-puzzle.php
# splits on \R, which matches \r\n as one unit -- so CRLF is fine for both. But
# \R ALSO matches characters split('\n') does not, and that is a real hazard:
# see the two-parsers assertion in phase 1.
lines = re.split(r'\r\n|\r|\n', text.strip())
if len(lines) < 2:
    cannot_run('phrase CSV has no rows')

rows = []            # (id, phrase, active, lineno)
for n, line in enumerate(lines[1:], start=2):
    line = line.strip()
    if not line:
        continue
    parts = line.split(',')
    if len(parts) < 3:
        continue
    rows.append((int(parts[0].strip()),
                 ','.join(parts[1:-1]).strip(),
                 parts[-1].strip(),
                 n))

active = [r for r in rows if r[2] == '1']

try:
    seqdata = json.load(open(SEQ))
    sequence = seqdata['sequence']
    start_date = seqdata['startDate']
except Exception as e:
    cannot_run('sequence.json unreadable: %s' % e)


def letters(t):
    """Exactly game.js PHRASE_LETTERS -- what a guess is compared against."""
    return re.sub(r'[-\s]', '', t.upper())


def norm(t):
    """Same text to a reader: case and word-spacing folded."""
    return re.sub(r'[\s-]+', ' ', t.upper()).strip()


print('GATE 55 — guitardle phrase uniqueness / no repeated puzzle')
print('  library: %s (%d rows, %d active)' % (CSV, len(rows), len(active)))
print('  sequence: %d entries, startDate %s' % (len(sequence), start_date))

# ── PHASE 1 — the library is well-formed and parseable by BOTH readers ───────
print('\nPHASE 1 — library integrity')

ids = [r[0] for r in rows]
dup_ids = [i for i, c in collections.Counter(ids).items() if c > 1]
check(not dup_ids, 'every row has a unique id',
      'duplicate ids: %s' % dup_ids)

check(all(r[2] in ('0', '1') for r in rows), 'every row has a 0/1 active flag',
      'bad flags: %s' % [(r[0], r[2]) for r in rows if r[2] not in ('0', '1')][:5])

check(all(r[1] for r in rows), 'no row has an empty phrase',
      'empty at ids: %s' % [r[0] for r in rows if not r[1]][:5])

# The \R trap: a non-ASCII byte can be split mid-character by the PHP reader.
non_ascii = [(r[0], r[1], r[3]) for r in rows if any(ord(c) > 127 for c in r[1])]
check(not non_ascii,
      'library is pure ASCII (the server parser splits on \\R with no /u)',
      'non-ASCII rows would be CUT IN HALF by _guitardle-puzzle.php while '
      'game.js parses them whole: %s' % non_ascii[:5])

# The two readers must see the SAME ROWS. PCRE's \R matches bare CR, \v, \f,
# U+0085, U+2028 and U+2029; JavaScript's split('\n') matches none of them. So
# any one of those inside the file splits a row for the SERVER and not for the
# CLIENT -- the server would resolve a different phrase than the player's board
# was drawn from, silently. \v and \f are plain ASCII, so the check above
# cannot catch them.
body = text.replace('\r\n', '\n')
split_r_only = {'\r': 'bare CR', '\v': 'vertical tab (0x0B)', '\f': 'form feed (0x0C)',
                '\x85': 'NEL (U+0085)', '\u2028': 'line separator (U+2028)',
                '\u2029': 'paragraph separator (U+2029)'}
offenders = ['%s at offset %d' % (name, body.index(ch))
             for ch, name in split_r_only.items() if ch in body]
check(not offenders,
      'no character that PHP \\R splits on but JS split(\'\\n\') does not',
      'the server and the client would parse DIFFERENT rows: %s' % offenders)

# ── PHASE 2 — no two rows are the same puzzle ────────────────────────────────
print('\nPHASE 2 — no two library rows are the same puzzle')

by_letters = collections.defaultdict(list)
by_norm = collections.defaultdict(list)
for r in active:
    by_letters[letters(r[1])].append(r)
    by_norm[norm(r[1])].append(r)

dup_letters = {k: v for k, v in by_letters.items() if len(v) > 1}
check(not dup_letters,
      'no two active rows share a letter run (what judges the win)',
      '\n          '.join(
          '%s <- %s' % (k, ', '.join('id %d line %d "%s"' % (r[0], r[3], r[1]) for r in v))
          for k, v in dup_letters.items()))

dup_norm = {k: v for k, v in by_norm.items() if len(v) > 1}
check(not dup_norm,
      'no two active rows share a normalized text',
      '\n          '.join(
          '%s <- %s' % (k, ', '.join('id %d line %d "%s"' % (r[0], r[3], r[1]) for r in v))
          for k, v in dup_norm.items()))

# ── PHASE 3 — THE ASSERTION THAT MATTERS: walk both tracks, see no repeat ────
print('\nPHASE 3 — a full cycle on each track serves no phrase twice')

phrase_of = {r[0]: r[1] for r in active}
length = len(sequence)

seq_dups = [i for i, c in collections.Counter(sequence).items() if c > 1]
check(not seq_dups, 'the sequence itself repeats no id',
      'repeated ids: %s' % seq_dups[:10])

unresolved = sorted({i for i in sequence if i not in phrase_of})
check(not unresolved,
      'every sequence id resolves to an ACTIVE phrase',
      'these days would draw a blank board: %s' % unresolved[:10])

# The real property, measured the way a player experiences it.
if not unresolved:
    for track, shift in (('member', 0), ('logged-out', length // 2)):
        served = [phrase_of[sequence[((d + shift) % length + length) % length]]
                  for d in range(length)]
        seen = collections.defaultdict(list)
        for day, p in enumerate(served):
            seen[letters(p)].append(day)
        repeats = {k: v for k, v in seen.items() if len(v) > 1}
        check(not repeats,
              '%s track: %d days, no phrase served twice' % (track, length),
              '\n          '.join(
                  '"%s" served on days %s' % (k, v) for k, v in repeats.items()))

# ── PHASE 4 — the library still covers the sequence ──────────────────────────
print('\nPHASE 4 — library and sequence still match')

check(len(sequence) == len(active),
      'sequence length equals the active-phrase count',
      '%d sequence entries vs %d active phrases' % (len(sequence), len(active)))

unused = sorted(set(phrase_of) - set(sequence))
check(not unused, 'no active phrase is missing from the sequence',
      'never served: %s' % unused[:10])

print()
if fails:
    print('RED — %d failing assertion(s):' % len(fails))
    for f in fails:
        print('  - ' + f)
    sys.exit(1)
print('GREEN — all assertions pass')
sys.exit(0)
