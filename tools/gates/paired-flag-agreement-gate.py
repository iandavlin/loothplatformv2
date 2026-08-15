#!/usr/bin/env python3
"""
paired-flag-agreement-gate — GATE 49 — every copy of a paired feature flag
holds the SAME value.

WHY THIS GATE EXISTS, and it is a defect I designed in myself. The dark-anon
wave ships its flags as per-file module-local vars rather than one shared
window global. That choice is correct and stays: pwa.js documents that a
dynamically-injected `defer` script has no guaranteed order relative to the
sync-injected ones, so a shared `window.LG_*` flag would be read before it was
written on some loads — a race that produces a half-styled page intermittently
and is near-impossible to reproduce. Local copies have no such race.

What local copies DO have is the failure this gate exists to stop. I defended
the pattern in a code comment saying it was "one grep away from flipping every
copy at once". On 2026-08-15 the flip happened without the grep:
LG_DARK_BORDER_FIX and LG_DARK_SEARCH_WRAPPER_FIX went true in app-settings.js
and stayed false in hub-polish.js, hub-infinite.js, privacy-sheet.js,
sponsor-sheet.js and the shop page. Dark mode then served TWO DIFFERENT BORDER
COLOURS at once — #767c76 from one file and #333833 from the others — and on
hub surfaces both files style the same page, so the mismatch was visible
side by side. That is worse than either whole state, and it is exactly the
class of thing Ian asked us to stop shipping.

THE LESSON, stated plainly because it generalises past this flag: a design
whose safety depends on a comment being obeyed is not safe. The comment was
true and it was not enough. This gate is that comment turned into a mechanical
check, which is what the craft law asks for.

WHAT IT ASSERTS. Every `var LG_<NAME> = true|false;` declaration across the
shipped front-end sources is collected and grouped by NAME. A name declared in
more than one file must carry the SAME value in all of them. A name declared
once is fine — that is a single-file flag, not a paired one, and this gate has
no opinion about its value. It never asserts a flag is ON or OFF; only that
copies agree. That matters: it stays correct as flags flip in either direction,
so nobody has to edit this gate to turn a feature on (feedback-gate-reads-the-
flag-not-a-hardcoded-state).

NO BROWSER, NO NETWORK, NO DB. It reads the shipped source. That makes it fast
enough to be free in every suite run, and it cannot flake — which matters
because the noisier gates in this lane taught me what a flaky gate costs.

RED-FIRST, AGAINST THE REAL DEFECT, NOT A SYNTHETIC ONE. This gate was written
while the half-state was still live on main and run BEFORE the fix: it reddened
naming LG_DARK_BORDER_FIX (1 file true, 4 false) and LG_DARK_SEARCH_WRAPPER_FIX
(1 true, 2 false). Then the five copies were flipped and it went green. The red
run is recorded in the commit that introduces it. `--selftest` additionally
red-fires the comparison itself on synthetic input, with no files involved.

Usage:  python3 tools/gates/paired-flag-agreement-gate.py
        python3 tools/gates/paired-flag-agreement-gate.py --selftest
Exit 0 = GREEN. 1 = RED (a paired flag disagrees). 2 = CANNOT RUN.
"""

import os
import pathlib
import re
import sys

HERE = pathlib.Path(os.path.dirname(os.path.abspath(__file__)))
ROOT = HERE.parent.parent

# Where shipped front-end flags live. Kept explicit rather than scanning the
# whole repo: a match inside a handoff, a test fixture or a vendored bundle is
# not a shipped declaration, and a gate that reddens on documentation would be
# trained-away noise within a week.
SEARCH = [
    ("webroot", ("*.js",)),
    ("fast-follow", ("*.html", "*.js")),
    ("membership-pages/web", ("*.js", "*.php")),
    ("bb-mirror/web", ("*.js",)),
]

DECL = re.compile(r"var\s+(LG_[A-Z0-9_]+)\s*=\s*(true|false)\s*;")

# A paired flag that is INTENTIONALLY allowed to differ would go here, with the
# reason. Empty on purpose — nothing has earned an exemption, and an allowlist
# that fills up quietly is how a gate stops meaning anything.
ALLOWED_TO_DIFFER = {}


def collect(root=None):
    """{flag_name: {value: [ "path:line", ... ]}} across the shipped sources."""
    root = pathlib.Path(root) if root else ROOT
    found = {}
    for sub, globs in SEARCH:
        base = root / sub
        if not base.is_dir():
            continue
        for g in globs:
            for path in base.rglob(g):
                if any(p in ("node_modules", "vendor", "recycle") for p in path.parts):
                    continue
                try:
                    text = path.read_text(errors="replace")
                except Exception:                                   # noqa: BLE001
                    continue
                for i, line in enumerate(text.splitlines(), 1):
                    m = DECL.search(line)
                    if not m:
                        continue
                    name, val = m.group(1), m.group(2)
                    rel = str(path.relative_to(root))
                    found.setdefault(name, {}).setdefault(val, []).append(f"{rel}:{i}")
    return found


def disagreements(found):
    """Names declared in >1 file with more than one distinct value."""
    out = []
    for name, byval in sorted(found.items()):
        if name in ALLOWED_TO_DIFFER:
            continue
        sites = sum(len(v) for v in byval.values())
        if sites > 1 and len(byval) > 1:
            out.append((name, byval))
    return out


def _selftest():
    """Red-fire the comparison with no files involved. A gate whose core
    predicate has never been observed to fail is not evidence of anything."""
    cases = [
        ("agreeing pair is clean",
         {"LG_A": {"true": ["a.js:1", "b.js:2"]}}, 0),
        ("THE REAL DEFECT: 1 true / 4 false",
         {"LG_A": {"true": ["app.js:1"],
                   "false": ["hub.js:1", "inf.js:1", "priv.js:1", "spon.js:1"]}}, 1),
        ("single-file flag is not a pair",
         {"LG_A": {"true": ["only.js:1"]}}, 0),
        ("two flags, one broken",
         {"LG_A": {"true": ["a.js:1", "b.js:1"]},
          "LG_B": {"true": ["a.js:2"], "false": ["b.js:2"]}}, 1),
        ("three-way split still caught",
         {"LG_A": {"true": ["a.js:1"], "false": ["b.js:1", "c.js:1"]}}, 1),
    ]
    bad = []
    for name, found, want in cases:
        got = len(disagreements(found))
        ok = (got > 0) == (want > 0)
        print(f"  {'ok  ' if ok else 'FAIL'} self-test: {name}")
        if not ok:
            bad.append(name)
    if bad:
        print(f"  self-test FAILED: {bad}")
        return 2
    print("  self-test: all cases correct\n")
    return 0


def main(argv):
    if "--selftest" in argv:
        return _selftest()
    rc = _selftest()
    if rc:
        return rc

    found = collect()
    if not found:
        print("CANNOT RUN: no LG_* flag declarations found at all — the search "
              "paths are wrong or the tree is not where this gate thinks it is. "
              "Refusing to report green on an empty scan.")
        return 2

    paired = {n: b for n, b in found.items() if sum(len(v) for v in b.values()) > 1}
    print(f"  scanned {sum(sum(len(v) for v in b.values()) for b in found.values())} "
          f"declaration(s), {len(found)} distinct flag(s), {len(paired)} paired")

    bad = disagreements(found)
    if not bad:
        for n, b in sorted(paired.items()):
            val = next(iter(b))
            print(f"  ok   {n}  all {len(b[val])} copies = {val}")
        print("\nGREEN — every paired flag agrees across all its copies.")
        return 0

    print(f"\n{len(bad)} paired flag(s) DISAGREE — a half-flipped feature is live:\n")
    for name, byval in bad:
        print(f"RED  {name}")
        for val in sorted(byval):
            for site in byval[val]:
                print(f"       {val:5s}  {site}")
        print("       ^ every copy of a paired flag must hold the same value; a "
              "half-state ships two behaviours at once.")
    return 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
