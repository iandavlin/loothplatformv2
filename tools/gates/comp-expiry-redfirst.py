#!/usr/bin/env python3
"""
RED-FIRST for gate 89 (comp expiry, #183).

    python3 tools/gates/comp-expiry-redfirst.py

Proves each assertion in tools/gates/comp-expiry-gate.php actually BITES, by
breaking the behaviour it claims to watch and requiring the gate to go RED.

Two rules this harness follows, both bought with somebody else's time:

  * IT SNAPSHOTS, IT NEVER `git checkout --`. Restoring from HEAD wipes
    uncommitted work under test and once turned one harness bug into ten false
    "the assertion is decoration" verdicts.
  * NO-OP CONTROLS MUST STAY GREEN, and a mutation whose search string is not
    found is a HARD ERROR rather than a silent skip — otherwise a typo in a
    mutation reads as "the gate caught it".

Every mutation is VALID PHP. A mutation that merely fails to parse would redden
the gate for the wrong reason and prove nothing.
"""
import subprocess, sys, os

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
GATE = os.path.join(ROOT, "tools/gates/comp-expiry-gate.php")

F = {
    "standing": "lg-patreon-stripe-poller/src/Membership/CompStanding.php",
    "expiry":   "lg-patreon-stripe-poller/src/Membership/CompExpiry.php",
    "arbiter":  "lg-patreon-stripe-poller/src/Arbiter.php",
    "tick":     "lg-patreon-stripe-poller/src/Tick.php",
    "admin":    "lg-patreon-stripe-poller/src/Admin.php",
    "cfg":      "platform/config/comp-expiry.php",
}

# (label, file-key, find, replace, expect_red)
MUTATIONS = [
    # ── the timezone ────────────────────────────────────────────────────────
    ("parse drops the explicit UTC zone (ambient tz wins)", "standing",
     "$dt = new \\DateTimeImmutable( $raw, new \\DateTimeZone( 'UTC' ) );",
     "$dt = new \\DateTimeImmutable( $raw );", True),
    ("parse reads in the SITE zone — the four-hour bug, restored", "standing",
     "new \\DateTimeZone( 'UTC' ) );",
     "new \\DateTimeZone( 'America/New_York' ) );", True),
    ("garbage parses to 0 instead of null (a fat-fingered field demotes)", "standing",
     "            // An unparseable date is NOT an expiry. Treating garbage as \"lapsed\"\n            // would demote a comp member because somebody fat-fingered a field.\n            return null;",
     "            return 0;", True),
    ("isActiveComp always true", "standing",
     "        return $exp === null || $exp > time();",
     "        return true;", True),

    # ── the cutover fence, which is what protects 1829 and 1865 ─────────────
    ("the cutover fence is removed entirely", "expiry",
     "        return $exp >= $cutover;",
     "        return true;", True),
    ("the cutover comparison is inverted", "expiry",
     "        return $exp >= $cutover;",
     "        return $exp < $cutover;", True),
    ("a missing cutover fails OPEN instead of closed", "expiry",
     "        if ( $cutover === null ) {\n            return false;                              // fence unset/broken — fail closed\n        }",
     "        if ( $cutover === null ) {\n            return true;\n        }", True),
    ("an unparseable cutover resolves to now() instead of null", "expiry",
     "        } catch ( Throwable $e ) {\n            return null;\n        }\n        return $dt->getTimestamp();",
     "        } catch ( Throwable $e ) {\n            return time();\n        }\n        return $dt->getTimestamp();", True),
    ("the cutover is read in the site zone, not UTC", "expiry",
     "            $dt = new \\DateTimeImmutable( $raw, new \\DateTimeZone( 'UTC' ) );",
     "            $dt = new \\DateTimeImmutable( $raw, new \\DateTimeZone( 'America/New_York' ) );", True),

    # ── the flag ────────────────────────────────────────────────────────────
    ("shouldExpire ignores the flag", "expiry",
     "        if ( ! self::enabled() ) {\n            return false;                              // flag off — total no-op\n        }",
     "        if ( false ) {\n            return false;\n        }", True),
    ("the sweep runs even when the flag is off", "expiry",
     "        if ( ! self::enabled() ) {\n            return;   // OFF = total no-op: no query, no option write, no log line\n        }",
     "        if ( false ) {\n            return;\n        }", True),
    ("a running timer expires anyway", "expiry",
     "        if ( $exp > time() ) {\n            return false;                              // timer still running\n        }",
     "        if ( false ) {\n            return false;\n        }", True),
    ("a comp with NO timer is treated as lapsed", "expiry",
     "        if ( $exp === null ) {\n            return false;                              // no timer = never expires (12 of 14 live holders)\n        }",
     "        if ( $exp === null ) {\n            return true;\n        }", True),
    ("tracked default flips to enabled => true", "cfg",
     "\t'enabled' => false,", "\t'enabled' => true,", True),
    ("tracked default ships a cutover", "cfg",
     "\t'effective_from' => '',", "\t'effective_from' => '2026-08-21',", True),

    # ── what an expired comp becomes ────────────────────────────────────────
    ("the looth1 floor is removed (a lapsed comp lands on NOTHING)", "arbiter",
     "        if ( $compExpired && $winning === null ) {\n            $winning = 'looth1';\n        }",
     "        if ( false ) {\n            $winning = 'looth1';\n        }", True),
    ("the floor is applied ALWAYS, flattening a paying patron to looth1", "arbiter",
     "        if ( $compExpired && $winning === null ) {\n            $winning = 'looth1';\n        }",
     "        if ( $compExpired ) {\n            $winning = 'looth1';\n        }", True),
    ("old_tier is read back after the role came off", "arbiter",
     "        $oldTier = $compExpired ? 'looth4' : self::currentTier( (array) $user->roles );",
     "        $oldTier = self::currentTier( (array) $user->roles );", True),
    ("the stripe guard swallows a just-expired comp", "arbiter",
     "        if ( ! $compExpired\n             && get_user_meta( $wpUserId, 'payment_source', true ) === 'stripe'",
     "        if ( get_user_meta( $wpUserId, 'payment_source', true ) === 'stripe'", True),
    ("the ambiguous-payer HOLD is removed", "arbiter",
     "            if ( $lapsed\n                 && get_user_meta( $wpUserId, 'payment_source', true ) === 'stripe'\n                 && RoleSourceWriter::readAllForUser( $wpUserId ) === [] ) {",
     "            if ( false ) {", True),
    ("every comp is treated as lapsed", "arbiter",
     "            $lapsed = \\LGMS\\Membership\\CompExpiry::shouldExpire( $wpUserId );",
     "            $lapsed = true;", True),
    ("no comp is ever treated as lapsed (todays broken behaviour)", "arbiter",
     "            $lapsed = \\LGMS\\Membership\\CompExpiry::shouldExpire( $wpUserId );",
     "            $lapsed = false;", True),

    # ── the sweep and its wiring ────────────────────────────────────────────
    ("the sweep arbitrates everyone it enumerates", "expiry",
     "            if ( self::shouldExpire( $uid ) ) {", "            if ( true ) {", True),
    ("subjects() forgets the comp holders with no timer", "expiry",
     "            $ids = array_merge( (array) $timers, (array) $comps );",
     "            $ids = (array) $timers;", True),
    ("the tick no longer calls the sweep", "tick",
     "            \\LGMS\\Membership\\CompExpiry::tick();",
     "            $noop = true;", True),
    ("CompExpiry writes a role itself (a second writer)", "expiry",
     "                    $res = Arbiter::sync( $uid );",
     "                    $u = get_user_by( 'id', $uid ); $u->remove_role( 'looth4' ); $res = Arbiter::sync( $uid );", True),

    # ── the setter ──────────────────────────────────────────────────────────
    ("the save form loses its nonce", "admin",
     "                            <?php wp_nonce_field( 'lgms_comp_timer_set' ); ?>\n", ""  , True),
    ("the field stops saying UTC", "admin",
     "                        <span class=\"description\">UTC. Empty = never expires.</span>",
     "                        <span class=\"description\">Empty = never expires.</span>", True),
    ("the handler stores raw input instead of the parsed UTC value", "admin",
     "        $normalised = gmdate( 'Y-m-d H:i:s', $ts );",
     "        $normalised = $raw;", True),
    ("the Comp Timers screen writes a role", "admin",
     "        $subjects = \\LGMS\\Membership\\CompExpiry::subjects();",
     "        $subjects = \\LGMS\\Membership\\CompExpiry::subjects();\n        if ( false ) { get_user_by( 'id', 1 )->remove_role( 'looth4' ); }", True),

    # ── NO-OP CONTROLS: these must stay GREEN ───────────────────────────────
    ("NO-OP: a comment is reworded", "expiry",
     "     * Reset the memoised config" if False else "    /** Journalled findings, autoload off. Operator-readable, never authoritative. */",
     "    /** Journalled findings. Autoload off. Operator readable, never authoritative. */", False),
    ("NO-OP: a local variable is renamed", "expiry",
     "        $now      = gmdate( 'c' );", "        $nowIso   = gmdate( 'c' );", None),
]


def run_gate():
    r = subprocess.run(["php", GATE], capture_output=True, text=True, timeout=300)
    return r.returncode, r.stdout


def main():
    # Snapshot every file we will touch. Never git checkout.
    snap = {}
    for k, rel in F.items():
        with open(os.path.join(ROOT, rel), encoding="utf-8") as fh:
            snap[k] = fh.read()

    code, out = run_gate()
    if code != 0:
        print("ABORT: the gate is not green before mutating. Fix that first.\n")
        print(out[-3000:])
        return 2
    print(f"baseline GREEN ({[l for l in out.splitlines() if 'passed' in l][-1].strip()})\n")

    caught = missed = errors = 0
    for label, key, find, repl, expect_red in MUTATIONS:
        if expect_red is None:      # renamed-variable control needs both sides renamed
            src = snap[key]
            if find not in src:
                print(f"  ERROR  mutation string not found: {label}")
                errors += 1
                continue
            mutated = src.replace(find, repl).replace("$now,", "$nowIso,").replace("$now\n", "$nowIso\n").replace("'updated_at'     => $now,", "'updated_at'     => $nowIso,").replace("$now, $uid", "$nowIso, $uid")
            expect_red = False
        else:
            src = snap[key]
            if find not in src:
                print(f"  ERROR  mutation string not found: {label}")
                errors += 1
                continue
            mutated = src.replace(find, repl, 1)

        path = os.path.join(ROOT, F[key])
        with open(path, "w", encoding="utf-8") as fh:
            fh.write(mutated)

        lint = subprocess.run(["php", "-l", path], capture_output=True, text=True)
        if lint.returncode != 0:
            print(f"  ERROR  mutation does not parse (invalid, not merely wrong): {label}")
            errors += 1
        else:
            code, out = run_gate()
            went_red = code == 1
            if expect_red and went_red:
                fails = [l.strip() for l in out.splitlines() if l.strip().startswith("FAIL")]
                print(f"  RED    {label}\n           caught by: {fails[0][:96] if fails else '(no FAIL line!)'}")
                if not fails:
                    print("           ERROR: red with no FAIL line — that is a gate error, not a catch")
                    errors += 1
                else:
                    caught += 1
            elif expect_red and not went_red:
                print(f"  MISSED {label}  <-- the gate does not watch this")
                missed += 1
            elif (not expect_red) and went_red:
                fails = [l.strip() for l in out.splitlines() if l.strip().startswith("FAIL")]
                print(f"  NOISY  {label}  <-- a NO-OP reddened the gate: {fails[0][:80] if fails else '?'}")
                errors += 1
            else:
                print(f"  GREEN  {label}  (no-op control held)")
                caught += 1

        with open(path, "w", encoding="utf-8") as fh:
            fh.write(snap[key])

    # Prove the restore actually restored.
    code, out = run_gate()
    print(f"\nrestored tree: {'GREEN' if code == 0 else 'NOT GREEN — RESTORE FAILED'}")
    total = len([m for m in MUTATIONS])
    print(f"{caught}/{total} accounted for, {missed} missed, {errors} errors")
    return 0 if (missed == 0 and errors == 0 and code == 0) else 1


if __name__ == "__main__":
    sys.exit(main())
