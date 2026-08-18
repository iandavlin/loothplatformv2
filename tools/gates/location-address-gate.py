#!/usr/bin/env python3
"""
GATE 73 — the profile prints the address the MEMBER TYPED, and the privacy ladder
does not move when it does.

THE DEFECT (member report, 47 days old when fixed; John Wilmink / Thomas Muse
Guitars). He retyped his location to his shop and dragged the pin. The pin moved
and was correct. The address TEXT kept showing his HOME address.

His typed address was stored correctly all along, in users.location_text. The
block printed a DIFFERENT column: at street precision Block::locationDisplay()
read users.location_address first. That column's ONLY writer in the whole repo is
the one-time BuddyBoss import (profile-app/bin/snapshot-location-from-bb.php) —
no editor endpoint maintained it, so it froze on the pre-import address the moment
a member edited, and being first it beat the value they had just typed. Measured
on dev2 AND live for user 190; four live members were mis-rendering (190, 590,
598, 1323).

WHAT THIS ASSERTS, and why each leg exists:

  1. The flag is READ, never hardcoded. The gate reads the tracked default and
     reports it, then exercises all three states (absent / OFF / ON) regardless.
     Flipping the default therefore needs no edit here — the failure class from
     feedback-gate-reads-the-flag-not-a-hardcoded-state, where a defect fix
     defaulted OFF made the obvious assertion RED on merge and blocked every lane.

  2. OFF (and ABSENT) are a genuine no-op. A box that has not flipped this prints
     exactly the string it printed before — asserted as an EQUALITY against the
     stale column, not as "not the new value", so a function that returned empty
     could not pass it.

  3. ⚠️ THE LEG THIS GATE MOSTLY EXISTS FOR: the privacy ladder does not move.
     "Print what the member typed" is one careless edit away from leaking a street
     address to an audience who chose City. So in EVERY state, city/state must
     print the STRUCTURED label, and the typed street line must appear NOWHERE in
     their output. An absence assertion is vacuous without a liveness one
     (feedback-absence-assertion-needs-liveness), so each state also asserts the
     coarse text is the exact label it should be — proving the branch ran at all.

  4. The write half is gated with the read half. If ON wrote users.location_address
     while OFF only changed rendering, flipping back would strand members who saved
     during ON with data the OFF path never meant to create.

Pure function under test — NO DB, NO browser, NO network, so it cannot flake under
load and cannot go vacuously green behind a locked-out browser
(trap-locked-out-browser-goes-vacuously-green).

Exit: 0 green · 1 open defect · 2 CANNOT RUN (never 3 — a gate exiting 3 blocks
every lane, trap-gate-exit-code-3-blocks-every-lane).

  --tree PATH   audit a different checkout (used for the red-first mutation proof,
                which snapshots into a COPY rather than touching the working tree —
                feedback-mutation-harness-must-snapshot-not-checkout).
"""
import json
import os
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
if "--tree" in sys.argv:
    ROOT = os.path.abspath(sys.argv[sys.argv.index("--tree") + 1])

APP = os.path.join(ROOT, "profile-app")
CFG = os.path.join(APP, "config", "location-address.php")
BLOCK = os.path.join(APP, "src", "Block.php")
FLAGS = os.path.join(APP, "src", "Flags.php")
SAVE = os.path.join(APP, "api", "v0", "me-location.php")

# The real row, from dev2/live user 190. address = the frozen import value,
# text = what he typed. A fixture that did not differ could not detect anything.
STALE = "4706 Pershing Ave, Parma, OH 44134, USA"
TYPED = "5425 Warner Rd. #4 Valley View, Ohio 44125"

PROBE = r"""<?php
require_once %(flags)s;
require_once %(block)s;
use Looth\ProfileApp\Block;
use Looth\ProfileApp\Flags;

$place = [
    'address'  => %(stale)s,
    'text'     => %(typed)s,
    'city'     => 'Valley View',
    'region'   => 'Ohio',
    'country'  => 'United States',
    'postcode' => null,
    'lat'      => 41.3878,
    'lng'      => -81.6151,
];
// Second fixture: the import column is all this member has. ON must still print
// it — "prefer the typed text" may not mean "blank the line when there is none".
$onlyStale = $place; $onlyStale['text'] = null;

$states = [
    'absent' => [],                                 // no config file at all
    'off'    => ['prefer_typed_address' => false],
    'on'     => ['prefer_typed_address' => true],
];
$out = [];
foreach ($states as $name => $vals) {
    Flags::resetCache();
    Flags::forTest('location-address', $vals);
    $out[$name] = [
        'street'      => Block::locationDisplay($place, 'street')['text'] ?? null,
        'city'        => Block::locationDisplay($place, 'city')['text'] ?? null,
        'state'       => Block::locationDisplay($place, 'state')['text'] ?? null,
        'street_kind' => Block::locationDisplay($place, 'street')['kind'] ?? null,
        'city_kind'   => Block::locationDisplay($place, 'city')['kind'] ?? null,
        'only_stale'  => Block::locationDisplay($onlyStale, 'street')['text'] ?? null,
    ];
}
// The tracked default, read not assumed.
Flags::resetCache();
$cfg = is_file(%(cfg)s) ? require %(cfg)s : null;
$out['_default'] = is_array($cfg) ? ($cfg['prefer_typed_address'] ?? '(key missing)') : '(file missing)';
echo json_encode($out);
""" % {
    "flags": json.dumps(FLAGS),
    "block": json.dumps(BLOCK),
    "cfg": json.dumps(CFG),
    "stale": json.dumps(STALE),
    "typed": json.dumps(TYPED),
}

fails = []


def check(ok, msg):
    if not ok:
        fails.append(msg)


for path in (CFG, BLOCK, FLAGS, SAVE):
    if not os.path.isfile(path):
        print(f"CANNOT RUN: missing {path}")
        sys.exit(2)

with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as fh:
    fh.write(PROBE)
    probe_path = fh.name
try:
    p = subprocess.run(["php", probe_path], capture_output=True, text=True, timeout=60)
finally:
    os.unlink(probe_path)

if p.returncode != 0:
    print("CANNOT RUN: probe failed to execute")
    print(p.stdout[-2000:])
    print(p.stderr[-2000:])
    sys.exit(2)
try:
    r = json.loads(p.stdout)
except Exception:
    print("CANNOT RUN: probe emitted no JSON")
    print(p.stdout[-2000:])
    sys.exit(2)

print(f"tracked default prefer_typed_address = {r['_default']!r}")
check(isinstance(r["_default"], bool), f"config default is {r['_default']!r}, not a boolean")

# ---- 1. OFF and ABSENT are the historical behaviour, asserted as an equality ----
for state in ("absent", "off"):
    check(
        r[state]["street"] == STALE,
        f"{state}: street should be the untouched historical value {STALE!r}, got {r[state]['street']!r}",
    )

# ---- 2. ON prints what the member typed ----
check(
    r["on"]["street"] == TYPED,
    f"on: street should be the member's typed address {TYPED!r}, got {r['on']['street']!r}",
)
check(
    r["on"]["only_stale"] == STALE,
    f"on: a member whose only stored address is the import column must still render it, got {r['on']['only_stale']!r}",
)

# ---- 3. The privacy ladder does not move, in ANY state ----
for state in ("absent", "off", "on"):
    check(
        r[state]["city"] == "Valley View, Ohio",
        f"{state}: city precision must print the structured label 'Valley View, Ohio', got {r[state]['city']!r}",
    )
    check(
        r[state]["state"] == "Ohio, United States",
        f"{state}: state precision must print 'Ohio, United States', got {r[state]['state']!r}",
    )
    for coarse in ("city", "state"):
        got = r[state][coarse] or ""
        check(
            TYPED not in got and STALE not in got,
            f"{state}: {coarse} precision LEAKED a street address: {got!r}",
        )
    check(r[state]["street_kind"] == "exact", f"{state}: street pin kind changed to {r[state]['street_kind']!r}")
    check(r[state]["city_kind"] == "coarse", f"{state}: city pin kind changed to {r[state]['city_kind']!r}")

# ---- 4. The write half is gated with the read half ----
save = open(SAVE, encoding="utf-8").read()
writes = save.count("location_address  = :addrcol")
guards = save.count("if ($syncAddressColumn) {")
check(writes > 0, "me-location.php never writes location_address — the column stays a fossil")
check(
    writes == guards,
    f"me-location.php has {writes} location_address writes but {guards} flag guards — an ungated write",
)
check(
    "Flags::bool('location-address', 'prefer_typed_address')" in save,
    "me-location.php does not read the location-address flag",
)
block_src = open(BLOCK, encoding="utf-8").read()
check(
    "Flags::bool('location-address', 'prefer_typed_address')" in block_src,
    "Block.php does not read the location-address flag",
)

if fails:
    print(f"FAIL ({len(fails)}):")
    for f in fails:
        print(f"  - {f}")
    sys.exit(1)
print("PASS: OFF/absent byte-identical, ON prints the typed address, ladder unmoved, write half gated")
sys.exit(0)
