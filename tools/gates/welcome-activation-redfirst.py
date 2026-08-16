#!/usr/bin/env python3
"""
RED-FIRST PROOF for welcome-activation-gate.py — every assertion is shown to FAIL
when the property it guards is broken, and to stay GREEN when nothing is broken.

WHY THIS EXISTS AS ITS OWN FILE. A gate that can never go red is decoration, and
this repo has produced several: assertions that matched a string which also lived
in prose, a CSS rule or a JS comment; an absence check that passed because the
probe never returned the field it read. Both classes are GREEN and worthless, and
neither is visible by reading the gate. The only way to know an assertion works is
to break the thing and watch it fail, naming the failure it produced.

⚠️ NOT REGISTERED IN run-all.sh AND CARRIES NO NUMBER. It mutates tracked source
files in place, so it must never run inside a shared suite; it is a proof you run
by hand when the gate changes. It is also SLOW — each mutation is a full gate run,
and each gate run drives WordPress twice.

HOW IT MUTATES, and the two rules that make the result mean anything:

  SNAPSHOT, NEVER `git checkout --`. Restoring from HEAD would wipe uncommitted
  work under test — that mistake once turned one harness bug into ten false "the
  assertion is decoration" verdicts. Exact bytes are captured up front and written
  back in a finally block, and the harness refuses to report a result it could not
  restore from.

  MUTATIONS MUST BE VALID, NOT MERELY WRONG. Every mutated PHP file is php -l'd
  before the gate runs. A syntax error would redden the gate for a reason that has
  nothing to do with the property, which reads as proof and is not.

And three controls, because a harness that reddens everything proves nothing:

  · a NO-OP mutation must leave the gate GREEN (if this fails, every red below is
    suspect and the harness says so and stops)
  · a COMMENT that merely mentions the Stripe call must leave §E GREEN — the
    prose-reading control for the one assertion that greps source
  · every mutation must be found EXACTLY ONCE in its file, or it is reported as
    NOT APPLIED rather than silently passing

EXIT: 0 all proofs held, 1 at least one assertion could not be shown to fail.
"""
import os
import re
import shutil
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
GATE = os.path.join(ROOT, "tools", "gates", "welcome-activation-gate.py")
ARBITER = os.path.join(ROOT, "lg-patreon-stripe-poller", "src", "Arbiter.php")
STRIPE_LC = os.path.join(ROOT, "lg-patreon-stripe-poller", "src", "StripeLifecycle.php")
CFG = os.path.join(ROOT, "platform", "config", "welcome-activation.php")

# Every file this harness can touch. The config is included NOT because we mutate
# it but because the gate arms it transiently — if a gate run is killed mid-arm it
# leaves the tracked config switched ON, and restoring it here is the net.
TOUCHED = [GATE, ARBITER, STRIPE_LC, CFG]

RESULTS = []


def run_gate():
    r = subprocess.run([sys.executable, GATE], capture_output=True, text=True, cwd=ROOT, timeout=900)
    return r.returncode, (r.stdout or "") + (r.stderr or "")


def php_lint(path):
    r = subprocess.run(["php", "-l", path], capture_output=True, text=True)
    return r.returncode == 0, (r.stdout or r.stderr).strip()[:200]


def apply(path, old, new):
    """Returns None on success, or a reason string. Refuses an ambiguous anchor."""
    txt = open(path, encoding="utf-8").read()
    n = txt.count(old)
    if n != 1:
        return f"anchor found {n} times (need exactly 1) in {os.path.basename(path)}"
    open(path, "w", encoding="utf-8").write(txt.replace(old, new, 1))
    if path.endswith(".php"):
        good, msg = php_lint(path)
        if not good:
            return f"mutation produced INVALID php — a syntax error is not a proof: {msg}"
    return None


def restore(snap):
    for p, b in snap.items():
        with open(p, "wb") as fh:
            fh.write(b)


def proof(name, path, old, new, want_exit, want_text, section):
    """Mutate, run the gate, assert it fails the way we claim, restore."""
    snap = {p: open(p, "rb").read() for p in TOUCHED if os.path.isfile(p)}
    try:
        why = apply(path, old, new)
        if why:
            RESULTS.append((False, section, name, f"NOT APPLIED — {why}"))
            return
        code, out = run_gate()
        if code != want_exit:
            RESULTS.append((False, section, name,
                            f"gate exited {code}, expected {want_exit} — the assertion did not fire"))
            return
        if want_text not in out:
            RESULTS.append((False, section, name,
                            f"gate exited {code} as expected but never said {want_text!r} — "
                            "it may have reddened for an unrelated reason"))
            return
        RESULTS.append((True, section, name, f"exit {code}, named it: {want_text!r}"))
    finally:
        restore(snap)
        for p, b in snap.items():
            if open(p, "rb").read() != b:
                print(f"\nFATAL: could not restore {p} — refusing to continue")
                sys.exit(1)



# ── the mutations ─────────────────────────────────────────────────────────────
# Each one breaks exactly ONE property and names the assertion that must catch it.

MUTATIONS = [
    # §B — OFF is a contract: today's behaviour, and not one byte of new state.
    ("B", "OFF writes the activation marker (hoisted out of the enabled check)",
     ARBITER,
     "        if ( ! empty( $welcomeCfg['enabled'] ) ) {",
     "        update_user_meta( $wpUserId, '_lg_membership_activated_at', gmdate( 'c' ) );\n"
     "        if ( ! empty( $welcomeCfg['enabled'] ) ) {",
     1, "OFF still writes the activation marker"),

    ("B", "OFF welcomes everybody (existing behaviour changed)",
     ARBITER,
     "        $shouldWelcome = $upgrade;\n",
     "        $shouldWelcome = true;\n",
     1, "OFF changed the existing welcome behaviour"),

    # §C — the headline. Reverting the ON condition to upgrade-only restores the
    # exact defect this lane exists to fix, so the headline must catch it.
    ("C", "armed — ON condition reverted to upgrade-only (the original defect)",
     ARBITER,
     "            $shouldWelcome = $upgrade || $afterCutover;",
     "            $shouldWelcome = $upgrade;",
     1, "still depends on WHICH RAIL"),

    # §D — the dangerous half, in both of its directions.
    ("D", "the cutover fence always passes (mass-mail shape)",
     ARBITER,
     "        return $regTs >= $cutTs;",
     "        return true;",
     1, "RETRO-FIRE"),

    ("D", "the marker is never written, so arming cannot self-backfill",
     ARBITER,
     "            if ( $firstActivation ) {\n"
     "                update_user_meta(\n"
     "                    $wpUserId,\n"
     "                    '_lg_membership_activated_at',\n"
     "                    ( $afterCutover ? '' : 'pre-cutover:' ) . gmdate( 'c' )\n"
     "                );\n"
     "            }\n",
     "",
     1, "never self-backfills"),

    # §D — the marker must not LIE about when. Stripping the provenance prefix is
    # the shape where every one of the 1,225 swept members ends up recorded as
    # having activated on the day the flag was flipped.
    ("D", "the sweep stamps a bare timestamp (marker lies about the activation date)",
     ARBITER,
     "                    ( $afterCutover ? '' : 'pre-cutover:' ) . gmdate( 'c' )",
     "                    gmdate( 'c' )",
     1, "would be wrong for 1,225 members"),

    # §E — gate 34d's rule: the Stripe leg may not mail or stamp member data.
    ("E", "a real WelcomeMailer call wired into StripeLifecycle",
     STRIPE_LC,
     "        self::$confirmFactory = null;",
     "        self::$confirmFactory = null;\n"
     "        \\LGMS\\Wp\\WelcomeMailer::sendIfNeeded( 1, 'looth3' );",
     1, "gate 34d forbids it"),

    # §A — schema liveness. Mutating the GATE, not the code: drop a field from the
    # probe and the absence assertions that read it must refuse to run rather than
    # pass vacuously. This is the exact bug that once made §B green for the wrong
    # reason, so it is the assertion most worth proving.
    ("A", "the probe stops returning 'marker' (vacuous-absence shape)",
     GATE,
     "        'marker'  => (string) get_user_meta((int)$id, '_lg_membership_activated_at', true),\n",
     "",
     2, "missing field(s)"),
]

# Controls — these must stay GREEN. A harness that reddens everything proves nothing.
CONTROLS = [
    ("—", "NO-OP: a comment added to the arbiter",
     ARBITER,
     "        $welcomeCfg   = self::welcomeActivationCfg();",
     "        // no-op control for the red-first harness\n"
     "        $welcomeCfg   = self::welcomeActivationCfg();",
     0, "GATE GREEN"),

    ("E", "PROSE CONTROL: a comment merely NAMING WelcomeMailer in StripeLifecycle",
     STRIPE_LC,
     "        self::$confirmFactory = null;",
     "        self::$confirmFactory = null;\n"
     "        // Deliberately NOT calling WelcomeMailer::sendIfNeeded or stamping\n"
     "        // _lg_pending_welcome here — gate 34d forbids this leg from mailing.",
     0, "GATE GREEN"),
]

print("RED-FIRST PROOF — welcome-activation-gate.py")
print("=" * 78)

# The no-op control runs FIRST and is a hard stop. If a no-op reddens the gate,
# every red below would be meaningless and reporting them would be a lie.
print("\nCONTROL 0 — the harness itself (a no-op must stay green)")
sec, name, path, old, new, we, wt = CONTROLS[0]
proof(name, path, old, new, we, wt, sec)
okc, _, _, detail = RESULTS[0]
print(f"  {'ok  ' if okc else 'FAIL'} {name} — {detail}")
if not okc:
    print("\nHARNESS UNSOUND: a no-op mutation did not leave the gate green.")
    print("Every 'the assertion fired' result below would be unattributable. Stopping.")
    sys.exit(1)

print("\nMUTATIONS — each must turn the gate RED (or CANNOT RUN) and name the reason")
for sec, name, path, old, new, we, wt in MUTATIONS:
    proof(name, path, old, new, we, wt, sec)
    okm, s, n, d = RESULTS[-1]
    print(f"  {'ok  ' if okm else 'FAIL'} §{s} {n}\n         {d}")

print("\nCONTROLS — these must stay GREEN")
for sec, name, path, old, new, we, wt in CONTROLS[1:]:
    proof(name, path, old, new, we, wt, sec)
    okm, s, n, d = RESULTS[-1]
    print(f"  {'ok  ' if okm else 'FAIL'} §{s} {n}\n         {d}")

print("\n" + "=" * 78)
bad = [r for r in RESULTS if not r[0]]
print(f"{len(RESULTS)} proofs run, {len(bad)} failed.")
if bad:
    print("\nRED-FIRST PROOF FAILED — these assertions were NOT shown to work:")
    for _, s, n, d in bad:
        print(f"  ✗ §{s} {n} — {d}")
    sys.exit(1)
print("RED-FIRST PROOF HELD — every assertion fires on its own defect, "
      "and neither a no-op nor a comment can redden the gate.")
