#!/usr/bin/env python3
"""
RED-FIRST proof for GATE 51 (profile-setup, backlog 19).

A gate is worth nothing until it has been broken on purpose. This mutates the
shipped source one defect at a time, runs the gate, and requires a RED each
time — then restores. If any mutation leaves the gate GREEN, the corresponding
assertion is decoration and this script says so loudly.

TWO RULES BAKED IN, both from lane memory paid for the hard way:

  · SNAPSHOT AND RESTORE, never `git checkout --`. A checkout-from-HEAD restore
    wipes uncommitted work under test and turned one harness bug into ten false
    "the assertion is decoration" verdicts. Files are read into memory first and
    written back in a finally block.

  · A NO-OP MUTATION MUST FAIL LOUD. If a mutation's search text is not found,
    the file is unchanged and the gate would stay green for the most boring
    possible reason — a stale string in this harness, not a healthy product. So
    every mutation asserts it actually changed the bytes before the gate runs.

Exit 0 = every assertion bites. Exit 1 = at least one is decoration.
"""
import os
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
GATE = os.path.join(ROOT, "tools", "gates", "profile-setup-gate.py")

CFG = os.path.join(ROOT, "platform", "config", "profile-setup.php")
MU = os.path.join(ROOT, "platform", "mu-plugins", "lg-profile-setup.php")
PW = os.path.join(ROOT, "platform", "mu-plugins", "lgpo-set-password.php")
WELCOME = os.path.join(ROOT, "membership-pages", "web", "welcome.php")
NUDGE_VICTIM = os.path.join(ROOT, "webroot", "app-settings.js")
NUDGE_ANCHOR = "/* Looth app settings"

# (label, file, find, replace) — each is ONE realistic regression.
MUTATIONS = [
    ("flag ships ON instead of OFF",
     CFG, "'enabled' => false,", "'enabled' => true,"),

    ("route registered even when the step is not live",
     MU, "if (!lg_profile_setup_live()) return;", "if (false) return;"),

    # Ian's testers allowlist (8/15). The negative half is the one that matters:
    # a member who is not on the list must get the byte-identical OFF experience.
    ("the testers allowlist stops discriminating (everyone gets in)",
     MU, "return in_array($userId, lg_profile_setup_testers(), true);", "return true;"),

    # NOTE: weakening the `<= 0` guard alone is a NO-OP, and the harness caught it
    # as decoration. lg_profile_setup_testers() already strips every non-positive
    # id, so 0 can never be in the list and the guard is defence in depth on top of
    # a filter that has already done the work. Kept as belt-and-braces; mutated
    # here in the form that actually admits an anonymous visitor.
    ("a logged-out visitor is admitted as a tester",
     MU, "if ($userId <= 0) return false;", "if ($userId === 0) return true;"),

    # Ian's three additions of 8/15.
    ("the name field is gone (no handle would be generated)",
     MU, 'id="ps-name"', 'id="ps-nameX"'),

    ("privacy is written unconditionally — Save rewrites untouched settings",
     MU, "if (visChanged) {", "if (true) {"),

    ("the privacy dials stop being pre-filled from current values",
     MU, "visWas = j.vis", "visWasX = j.vis"),

    ("the full-profile door is gone",
     MU, "Open the full profile editor", "Go to my profile"),

    ("Patreon OFF path no longer lands on the front page",
     PW, "$onboardDone = $psOn ? home_url($psPath) : home_url('/');",
     "$onboardDone = home_url($psPath);"),

    ("Patreon OFF skip no longer lands on the member's profile",
     PW, "$onboardSkip = $psOn ? $psPath : $cont;", "$onboardSkip = $psPath;"),

    ("Stripe rail unwired (Patreon-only build — the dual-rail regression)",
     WELCOME, "$profileSetupOn   = !empty($psCfg['enabled']);",
     "$profileSetupOn   = false; // unwired"),

    ("Stripe OFF path no longer renders the original CTA",
     WELCOME, '<a class="lg-success__cta is-primary" href="/">Head to the community</a>\n                <?php endif; ?>',
     '<a class="lg-success__cta is-primary" href="/welcome-elsewhere/">Head to the community</a>\n                <?php endif; ?>'),

    ("the step stops saying it is optional",
     MU, "<strong>This is optional</strong>", "<strong>Required</strong>"),

    ("Skip demoted from a button to a bare text link",
     MU, '<a class="btn btn--skip" id="ps-skip"', '<a class="quietlink" id="ps-skip"'),

    ("skippers no longer get instructions",
     MU, "Where to find it later", "Bye"),

    ("?change=1 exclusion dropped (password-changers sent to profile setup)",
     PW, "&& !isset($_GET['change'])", ""),

    ("gift purchases no longer excluded",
     WELCOME, "if ($kind !== 'gift') {\n    $psFile", "if (true) {\n    $psFile"),

    ("a profile-completeness NUDGE is planted on a member-facing surface",
     NUDGE_VICTIM, NUDGE_ANCHOR,
     "/* <div class='x'>Finish your profile</div> */\n/* Looth app settings"),
]


def run_gate():
    r = subprocess.run([sys.executable, GATE], capture_output=True, text=True)
    return r.returncode


def main():
    if not os.path.isfile(GATE):
        print("CANNOT RUN: gate 51 not found")
        return 2

    baseline = run_gate()
    if baseline != 0:
        print(f"CANNOT RUN: gate 51 is not GREEN before mutating (exit {baseline}). "
              "Fix the gate or the product first — a red-first run against an "
              "already-red gate proves nothing.")
        return 2
    print("baseline: gate 51 GREEN\n")

    decoration, missing = [], []

    for label, path, find, repl in MUTATIONS:
        if not os.path.isfile(path):
            missing.append(f"{label} (file absent: {os.path.relpath(path, ROOT)})")
            print(f"  SKIP  {label} — file absent")
            continue

        original = open(path, encoding="utf-8").read()
        if find not in original:
            # A no-op mutation would leave the gate green for the most boring
            # reason imaginable. Never let that read as a pass.
            missing.append(f"{label} (search text not found in "
                           f"{os.path.relpath(path, ROOT)})")
            print(f"  STALE {label} — search text not found; mutation would be a NO-OP")
            continue

        try:
            open(path, "w", encoding="utf-8").write(original.replace(find, repl, 1))
            assert open(path, encoding="utf-8").read() != original, "mutation changed nothing"
            code = run_gate()
        finally:
            open(path, "w", encoding="utf-8").write(original)   # snapshot restore

        if code == 1:
            print(f"  bites {label}")
        else:
            decoration.append(f"{label} (gate exited {code}, expected 1)")
            print(f"  DECOR {label} — gate stayed non-RED (exit {code})")

    after = run_gate()
    if after != 0:
        print(f"\nFAIL: gate is {after} after restore — the harness did not put "
              "the tree back. Investigate before trusting anything above.")
        return 1

    print(f"\nrestored cleanly; gate 51 GREEN again ({len(MUTATIONS)} mutations)")

    if missing or decoration:
        print("\nRED-FIRST FAILED:")
        for m in missing:
            print("  ✗ STALE/ABSENT — " + m)
        for d in decoration:
            print("  ✗ DECORATION  — " + d)
        return 1

    print("RED-FIRST PASS — every gate 51 assertion bites when broken.")
    return 0


sys.exit(main())
