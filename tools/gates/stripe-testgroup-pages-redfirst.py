#!/usr/bin/env python3
"""RED-FIRST for GATE 34b's #193 legs — the page door knows an ADDRESS

    python3 tools/gates/stripe-testgroup-pages-redfirst.py

Measured 2026-08-22: 6/6 caught, 1/1 no-op inert, baseline 136 assertions.

TWO of these were BLIND on the first run and needed new helpers in the gate,
both because the behavioural runs structurally cannot see them: M11 (dropping
the reader's validation stayed green because the invented junk entries were not
addresses any test viewer carried — emailsFor() added) and M12 (the anon guard
could not be exercised through the gate at all, because the gate refuses an
unauthenticated ctx on its own `authenticated` clause — inGroupFor() added,
which answers with the listed address for EVERY id so only the guard can
refuse).

⚠️  SNAPSHOTS AND RESTORES BY BYTES, never `git checkout --`: a harness that
restores from HEAD wipes uncommitted work under test, and this repo has already
paid for that once. Everything is copied to a temp dir first and copied back in
a finally block.
"""
import pathlib, shutil, subprocess, tempfile, re, sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
CFG  = ROOT / 'membership-pages/config.php'
GATE = ROOT / 'tools/gates/stripe-testgroup-pages-gate.php'

MUTS = {
 "M7": ("the address leg is removed — a listed tester is refused the join page",
        ("    $listed = lg_membership_stripe_test_group_emails();\n    if ($listed === []) return false;",
         "    $listed = [];\n    if ($listed === []) return false;")),
 "M8": ("the compare stops normalizing — a listed address stops matching how it is cased",
        ("    $email = strtolower(trim(lg_membership_user_email($wpUserId)));",
         "    $email = lg_membership_user_email($wpUserId);")),
 "M9": ("an unreadable address ADMITS instead of refusing — a DB error opens the door",
        ("    return $email !== '' && in_array($email, $listed, true);",
         "    return in_array($email, $listed, true) || $email === '';")),
 "M10": ("lock 1 stops outranking the address leg — the pages flag OFF still lets a listed address in",
        ("    if (!lg_membership_stripe_testgroup_pages()) return false;   // lock 1\n    if (in_array($wpUserId, lg_membership_stripe_test_group_ids(), true)) return true;  // lock 2",
         "    if (in_array($wpUserId, lg_membership_stripe_test_group_ids(), true)) return true;  // lock 2\n    if (!lg_membership_stripe_testgroup_pages()) { if (lg_membership_stripe_test_group_emails() === []) return false; }")),
 "M11": ("the address reader stops validating — a junk entry becomes a listed address",
        ("        if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) continue;",
         "        if ($e === '') continue;")),
 "M12": ("anon stops being refused before the address leg",
        ("    if ($wpUserId <= 0) return false;                        // anon is never listed",
         "    if (false) return false;                        // anon is never listed")),
}
NOOPS = {
 "N1": ("a comment reword in the address reader",
        (" * THE SAME OPTION, READ FOR ITS ADDRESS ENTRIES (#193).",
         " * The same option, read for its address entries (#193).")),
}

def run():
    p = subprocess.run(['php', str(GATE)], capture_output=True, text=True, timeout=900, cwd=str(ROOT))
    return p.returncode, p.stdout

snap = pathlib.Path(tempfile.mkdtemp(prefix='lg-34b-'))
shutil.copy2(CFG, snap / CFG.name)
try:
    code, out = run()
    if code != 0:
        print("CANNOT RUN: gate not green at baseline"); sys.exit(3)
    print("baseline GREEN:", out.strip().splitlines()[-2])
    caught = 0
    for mid, (desc, (old, new)) in {**MUTS, **NOOPS}.items():
        s = CFG.read_text()
        if old not in s:
            print(f"  {mid}  ANCHOR LOST"); shutil.copy2(snap / CFG.name, CFG); continue
        CFG.write_text(s.replace(old, new, 1))
        lint = subprocess.run(['php','-l',str(CFG)], capture_output=True, text=True)
        if lint.returncode != 0:
            print(f"  {mid}  INVALID MUTATION — {lint.stdout.strip()}")
            shutil.copy2(snap / CFG.name, CFG); continue
        code, out = run()
        shutil.copy2(snap / CFG.name, CFG)
        reds = re.findall(r'FAIL\s+(.*)', out)
        n = len(reds)
        expect_red = mid in MUTS
        if expect_red and code != 0:
            caught += 1
            print(f"  RED   {mid}  ({n} assertions) {desc}")
        elif expect_red:
            print(f"  !! GREEN {mid}  {desc}   <-- BLIND SPOT")
        elif code == 0:
            print(f"  ok    {mid}  no-op stayed green — {desc}")
        else:
            print(f"  !! RED {mid}  A NO-OP TURNED IT RED — keying on prose")
    print(f"\n{caught}/{len(MUTS)} caught")
finally:
    shutil.copy2(snap / CFG.name, CFG)
    shutil.rmtree(snap, ignore_errors=True)
