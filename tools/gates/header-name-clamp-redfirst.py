#!/usr/bin/env python3
"""RED-FIRST for GATE 87 (#173) — every assertion, broken on purpose.

⚠️ SNAPSHOTS, NEVER `git checkout --`. Restoring from HEAD wipes uncommitted
work under test and turns one harness bug into a run of false "the assertion is
decoration" verdicts (feedback-mutation-harness-must-snapshot-not-checkout).
Every file is copied out first, mutated in place, and copied back — including on
Ctrl-C, because a mutation left on disk poisons every later measurement.

A mutation must redden the assertion NAMED beside it. "Something went red" is
not evidence: gate 87's legs overlap on purpose (source AND browser see most of
these), so each expectation below names the leg that must catch it, and two
mutations are recorded as SOURCE-ONLY because the browser structurally cannot
see them. And two no-ops must leave the gate GREEN — a harness that reddens on
everything proves nothing at all.
"""
import os, shutil, signal, subprocess, sys, tempfile

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
GATE = f"{REPO}/tools/gates/header-name-clamp-gate.py"
CSS  = f"{REPO}/lg-shared/site-header.css"
PHP  = f"{REPO}/lg-shared/site-header.php"
ACSS = f"{REPO}/archive-poc/web/archive.css"
FILES = [CSS, PHP, ACSS]

snap = tempfile.mkdtemp(prefix="lg-g87-snap-")
for f in FILES: shutil.copy2(f, os.path.join(snap, os.path.basename(f) + os.path.dirname(f).replace("/", "_")))
def restore():
    for f in FILES:
        shutil.copy2(os.path.join(snap, os.path.basename(f) + os.path.dirname(f).replace("/", "_")), f)
signal.signal(signal.SIGINT,  lambda *a: (restore(), sys.exit(130)))
signal.signal(signal.SIGTERM, lambda *a: (restore(), sys.exit(143)))

def sub(path, old, new):
    s = open(path, encoding="utf-8").read()
    if s.count(old) != 1:
        raise SystemExit(f"MUTATION ANCHOR MISSING in {path} ({s.count(old)} hits) — "
                         "the harness is broken, not the code")
    open(path, "w", encoding="utf-8").write(s.replace(old, new, 1))

def run_gate():
    p = subprocess.run([sys.executable, GATE], capture_output=True, text=True)
    if p.returncode == 2:
        raise SystemExit("gate 87 CANNOT RUN — red-first proves nothing:\n" + p.stdout[-800:])
    fails = [l.strip()[6:].strip() for l in p.stdout.splitlines() if l.strip().startswith("FAIL")]
    return p.returncode, fails

CLAMP = "max-width: clamp(0px, calc(100vw - 934px), 220px);"

MUTATIONS = [
    ("M1  the name is allowed to wrap again (the defect itself)",
     "the name is ONE LINE in all",
     lambda: sub(CSS, "  white-space: nowrap;\n  overflow: hidden;",
                      "  overflow: hidden;")),

    ("M2  text-overflow is dropped — the name is CLIPPED mid-letter, no ellipsis",
     "the truncation is a VISIBLE ellipsis",
     lambda: sub(CSS, "  text-overflow: ellipsis;\n}", "}")),

    ("M3  the clamp becomes a FLAT max-width — right at 1440, wrong at 1024",
     "adds NO horizontal scroll",
     lambda: sub(CSS, CLAMP, "max-width: 220px;")),

    ("M4  the chip loses its title attribute",
     "carries its own full name in title",
     lambda: sub(PHP, ' class="lg-chrome__account-name" title="<?= $h($display_name) ?>"',
                      ' class="lg-chrome__account-name"')),

    ("M5  the account menu loses the full-name row",
     "the opened menu carries the FULL name",
     lambda: sub(PHP, '            <li role="presentation" class="lg-chrome__account-menu-name">'
                      '<?= $h($display_name) ?></li>\n', "")),

    ("M6  the name row becomes a MENUITEM — a label turned into a door",
     "presentational, not a menuitem",
     lambda: sub(PHP, '<li role="presentation" class="lg-chrome__account-menu-name">',
                      '<li role="menuitem" class="lg-chrome__account-menu-name">')),

    ("M7  the menu's rule moves INTO the anon-visible inline block (+bytes for anon)",
     "byte-identical to origin/main",
     lambda: sub(PHP, ".lg-chrome ul.lg-chrome__account-menu[hidden] { display: none !important; }",
                      ".lg-chrome ul.lg-chrome__account-menu[hidden] { display: none !important; }\n"
                      ".lg-chrome ul.lg-chrome__account-menu .lg-chrome__account-menu-name "
                      "{ padding: 8px 12px 10px !important; }")),

    ("M8  the ≤1000 hide is deleted — a chip that says only …",
     "both sides of the breakpoint",
     lambda: sub(CSS, "@media (max-width: 1000px) {\n  .lg-chrome__account-name { display: none; }\n}",
                      "")),

    ("M9  archive.css's mirror is reverted while site-header.css stays fixed",
     "archive.css: the name never wraps",
     lambda: sub(ACSS, CLAMP + "\n  white-space: nowrap;\n  overflow: hidden;\n"
                       "  text-overflow: ellipsis;\n", "")),

    # ── SOURCE-ONLY, and said so rather than dressed up as behavioural ──────
    # The ≤820 rule is SUBSUMED by the ≤1000 one: deleting it changes no pixel
    # at any width, so only §A can see it go. It is still worth asserting —
    # archive.css and forums.css mirror that rule and a future edit that moves
    # the 1000 boundary would need it back — but a mutation the browser cannot
    # see must be labelled, not counted as behavioural coverage.
    ("M10 the ≤820 phone hide is deleted (SOURCE-ONLY — subsumed by ≤1000)",
     "the ≤820 hide is still there",
     lambda: sub(CSS, "  .lg-chrome__wordmark { white-space: nowrap; }\n"
                      "  .lg-chrome__account-name { display: none; }\n",
                      "  .lg-chrome__wordmark { white-space: nowrap; }\n")),
]

NOOPS = [
    ("N1  a word changed inside a CSS COMMENT",
     lambda: sub(CSS, "hidden but never lost (#173)", "hidden but never mislaid (#173)")),
    ("N2  two declarations in the clamp rule swap order",
     lambda: sub(CSS, "  white-space: nowrap;\n  overflow: hidden;",
                      "  overflow: hidden;\n  white-space: nowrap;")),
]

def main():
    print("=== RED-FIRST: gate 87 (#173) ===\n")
    rc, fails = run_gate()
    if rc != 0:
        restore()
        raise SystemExit("BASELINE IS NOT GREEN — every 'mutation reddened it' below would be a "
                         "lie. Fix the branch first.\n  " + "\n  ".join(fails))
    print("  baseline: GREEN\n")

    scored, bad = 0, []
    for label, expect, mutate in MUTATIONS:
        restore(); mutate()
        rc, fails = run_gate(); restore()
        hit = [f for f in fails if expect in f]
        ok = rc != 0 and bool(hit)
        print(f"  {'RED ' if ok else 'MISS'}  {label}")
        print(f"          expected: {expect}")
        if ok:
            scored += 1
            print(f"          got:      {hit[0][:110]}")
        else:
            bad.append(label)
            print(f"          got:      {len(fails)} failure(s): {[f[:60] for f in fails[:3]]}")

    for label, mutate in NOOPS:
        restore(); mutate()
        rc, fails = run_gate(); restore()
        ok = rc == 0
        print(f"  {'INERT' if ok else 'LOUD '}  {label}")
        if not ok:
            bad.append(label)
            print(f"          a no-op reddened the gate: {[f[:60] for f in fails[:3]]}")

    print(f"\n  {scored}/{len(MUTATIONS)} mutations reddened their OWN named assertion; "
          f"{len(NOOPS)} no-ops proven inert")
    sys.exit(1 if bad else 0)

try:
    main()
finally:
    restore()
    shutil.rmtree(snap, ignore_errors=True)
