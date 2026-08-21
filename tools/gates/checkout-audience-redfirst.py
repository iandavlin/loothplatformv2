#!/usr/bin/env python3
"""
RED-FIRST for GATE 86 (#181) — prove every assertion can actually fail.

    python3 tools/gates/checkout-audience-redfirst.py [--only M7]

Each mutation is applied ALONE to correct code, the gate is run, and the
mutation is reverted. A mutation that leaves the gate GREEN is a finding: it
means the gate is not measuring what its name claims.

⚠️ IT SNAPSHOTS AND RESTORES BY BYTES, never `git checkout --`. A harness that
restores from HEAD wipes uncommitted work under test, and this repo has already
paid for that once: one harness bug turned into ten false "the assertion is
decoration" verdicts. Everything here is copied to a temp dir first and copied
back in a finally block, so an interrupted run cannot lose the tree.

⚠️ MUTATIONS ARE VALID CODE, NOT GARBAGE. A syntax error would make the gate
exit non-zero for the wrong reason and count as a false RED. Every mutation
below still parses (checked with `php -l` before the gate runs) and expresses a
plausible wrong decision — the kind a tidy-up or a copy-paste would produce.

⚠️ THE NO-OP CONTROLS MUST STAY GREEN. If a comment reword turns the gate red,
the gate is keying on prose and every RED above it is suspect.
"""

import argparse
import pathlib
import shutil
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
GATE = ROOT / "tools/gates/checkout-audience-gate.php"

AUD = ROOT / "lg-patreon-stripe-poller/src/Membership/CheckoutAudience.php"
REST = ROOT / "lg-patreon-stripe-poller/src/Wp/CheckoutAudienceRestController.php"
PROV = ROOT / "lg-patreon-stripe-poller/src/Wp/UserProvisioner.php"
PLUG = ROOT / "lg-patreon-stripe-poller/src/Plugin.php"
GUARD = ROOT / "lg-stripe-billing/src/Core/CheckoutAudienceGuard.php"
PROBE = ROOT / "lg-stripe-billing/src/Adapters/HttpCheckoutAudienceProbe.php"
SLIM = ROOT / "lg-stripe-billing/src/Http/Controllers/CheckoutController.php"
ENVS = ROOT / "lg-stripe-billing/src/Adapters/EnvSettingsStore.php"

TOUCHED = [AUD, REST, PROV, PLUG, GUARD, PROBE, SLIM, ENVS, GATE]


def sub(path, old, new, count=1):
    """One textual replacement. Raises if the anchor is not unique/present."""
    def apply():
        s = path.read_text()
        if s.count(old) < count:
            raise AssertionError(f"anchor not found in {path.name}: {old[:70]!r}")
        path.write_text(s.replace(old, new, count))
    return apply


def compose(*fns):
    def apply():
        for f in fns:
            f()
    return apply


# ── the fence block in UserProvisioner, addressed as one unit ────────────────
FENCE_START = "        if ( ! \\LGMS\\Membership\\CheckoutAudience::allowsEmail( $email ) ) {"
FENCE_END = "            throw new RuntimeException( $detail );\n        }\n"


def fence_block():
    s = PROV.read_text()
    i = s.index(FENCE_START)
    j = s.index(FENCE_END, i) + len(FENCE_END)
    return s[i:j], i, j


def move_fence_above_bridge():
    s = PROV.read_text()
    block, i, j = fence_block()
    s = s[:i] + s[j:]
    anchor = "        // Already bridged?"
    s = s.replace(anchor, block + "\n" + anchor, 1)
    PROV.write_text(s)


def delete_fence():
    s = PROV.read_text()
    block, i, j = fence_block()
    PROV.write_text(s[:i] + s[j:])


def move_slim_call_below_doublepay():
    s = SLIM.read_text()
    start = s.index("        $audienceRefusal = $this->audience->refusalFor($emailArg);")
    end = s.index("\n\n", s.index("        }", start))
    block = s[start:end]
    s = s[:start] + s[end:].lstrip("\n")
    anchor = "        $giftDeferDays = 0;"
    s = s.replace(anchor, block + "\n\n" + anchor, 1)
    SLIM.write_text(s)


MUTATIONS = {
    # ── the decision class ───────────────────────────────────────────────────
    "M1": ("default state becomes `on` — the soft launch is open to the internet",
           sub(AUD, "public const DEFAULT_STATE = self::ALLOWLIST;",
                    "public const DEFAULT_STATE = self::ON;")),
    "M2": ("default state becomes `off` — the fence ships dark and is never exercised",
           sub(AUD, "public const DEFAULT_STATE = self::ALLOWLIST;",
                    "public const DEFAULT_STATE = self::OFF;")),
    "M3": ("a junk option value falls through to `on` instead of the safe default",
           sub(AUD, "            ? $raw\n            : self::DEFAULT_STATE;",
                    "            ? $raw\n            : self::ON;")),
    "M4": ("allowsEmail('') returns true — the DoublePayGuard copy-paste, and the whole hole",
           sub(AUD, "        $email = trim( (string) $email );\n        if ( $email === '' ) {\n            return false;\n        }",
                    "        $email = trim( (string) $email );\n        if ( $email === '' ) {\n            return true;\n        }")),
    # ⚠️ NOTE THE SHAPE OF THIS ONE. The first draft flipped the `if ( ! $user )`
    # guard to `if ( false )` and the gate stayed GREEN — not a blind spot: with
    # no user, `$user->ID` on a bool yields null, (int)null is 0, and
    # allowsUser(0) refuses anyway. The code was right twice over and the
    # "mutation" changed no decision. A mutation that expresses the actual wrong
    # decision is the only kind worth counting.
    "M5": ("an email with no WordPress account falls through to ALLOWED",
           sub(AUD, "        if ( ! $user ) {\n            // No WordPress account",
                    "        if ( ! $user ) {\n            return true;\n            // No WordPress account")),
    "M6": ("allowsUser() gains an administrator bypass (keeper ruling (b) reversed)",
           sub(AUD, "        return $wpUserId > 0 && StripeLifecycle::inCohort( $wpUserId );",
                    "        return $wpUserId > 0 && ( StripeLifecycle::inCohort( $wpUserId )\n            || ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) );")),
    "M19": ("notifyRefusalOnce loses its transient guard — the 5-min sweep floods the alerts",
            sub(AUD, "        if ( function_exists( 'get_transient' ) && get_transient( $key ) ) {\n            return false;\n        }",
                     "        if ( false ) {\n            return false;\n        }")),

    # ── the Slim guard ───────────────────────────────────────────────────────
    "M7": ("the guard proceeds on an unknown answer — fail-open, the sibling's rule",
           sub(GUARD, "        if ($decision === null) {\n            return [", "        if ($decision === null) {\n            return null;\n            return [")),
    "M8": ("the 503 reuses the 403 sentence — two opposite fixes, one message",
           sub(GUARD, "    private const UNKNOWN_MESSAGE = 'We could not verify access to checkout just now. '\n        . 'Please try again in a moment.';",
                      "    private const UNKNOWN_MESSAGE = self::FALLBACK_REFUSAL;")),

    # ── the provision fence ──────────────────────────────────────────────────
    "M9": ("the fence moves ABOVE the existing-bridge return — freezes real members",
           move_fence_above_bridge),
    "M10": ("the provision fence is deleted — the backstop is gone",
            delete_fence),
    "M11": ("the fence logs but does not throw — a stranger is provisioned anyway",
            sub(PROV, "            throw new RuntimeException( $detail );",
                      "            /* throw disabled */")),

    # ── the Slim controller wiring ───────────────────────────────────────────
    "M12": ("the guard call is removed from CheckoutController",
            sub(SLIM, "        $audienceRefusal = $this->audience->refusalFor($emailArg);",
                      "        $audienceRefusal = null;")),
    "M13": ("the guard call moves BELOW the ban and double-pay lookups",
            move_slim_call_below_doublepay),

    # ── the route ────────────────────────────────────────────────────────────
    "M14": ("the route becomes flag-conditional — a 404 becomes mistakable for `off`",
            sub(REST, "    public static function register(): void\n    {\n",
                      "    public static function register(): void\n    {\n        if ( CheckoutAudience::state() === CheckoutAudience::OFF ) { return; }\n")),
    "M17": ("the BuddyBoss exemption is removed — the probe 401s and refuses everyone",
            sub(PLUG, "        add_filter(\n            'bb_exclude_endpoints_from_restriction',\n            [ Wp\\CheckoutAudienceRestController::class, 'exemptFromBuddyBossRestriction' ]\n        );",
                      "        /* exemption removed */")),
    "M18": ("the exemption is widened from one route to the whole namespace",
            sub(REST, "        if ( ! in_array( self::FULL_ROUTE, $endpoints, true ) ) {\n            $endpoints[] = self::FULL_ROUTE;\n        }",
                      "        $endpoints[] = self::FULL_ROUTE;\n        $endpoints[] = '/' . self::NAMESPACE . '/sync-customer';\n        $endpoints[] = '/' . self::NAMESPACE . '/run-now';")),

    # ── the HTTP probe ───────────────────────────────────────────────────────
    "M15": ("the probe treats a 404 as `off` — a flushed rewrite reads as permission",
            sub(PROBE, "        if ($code !== 200 || !is_string($body)) {\n            return null;\n        }",
                       "        if ($code === 404) {\n            return ['state' => 'off', 'allowed' => true, 'message' => null];\n        }\n        if ($code !== 200 || !is_string($body)) {\n            return null;\n        }")),
    "M16": ("the probe accepts an unrecognised state as `on`",
            sub(PROBE, "        if (!in_array($state, ['off', 'allowlist', 'on'], true)) {\n            return null;\n        }",
                       "        if (!in_array($state, ['off', 'allowlist', 'on'], true)) {\n            $state = 'on';\n        }")),

    # ── the settings ─────────────────────────────────────────────────────────
    "M20": ("the audience URL gains an `off` valve — blanking it opens the doors",
            sub(ENVS, "    public function getCheckoutAudienceUrl(): string\n    {\n        $explicit = trim(self::env('LGMS_CHECKOUT_AUDIENCE_URL'));\n        if ($explicit !== '') {",
                      "    public function getCheckoutAudienceUrl(): string\n    {\n        $explicit = trim(self::env('LGMS_CHECKOUT_AUDIENCE_URL'));\n        if (strcasecmp($explicit, 'off') === 0) {\n            return '';\n        }\n        if ($explicit !== '') {")),
}

NO_OPS = {
    "N1": ("reword a comment in CheckoutAudience.php",
           sub(AUD, "/** The switch, and the only one. */", "/** The one switch, and there is no second. */")),
    "N2": ("add a blank line to the guard",
           sub(GUARD, "final class CheckoutAudienceGuard\n{", "final class CheckoutAudienceGuard\n{\n")),
}


def run_gate():
    p = subprocess.run([sys.executable and "php", str(GATE)],
                       capture_output=True, text=True, timeout=300)
    return p.returncode, p.stdout


def lint_ok():
    for f in TOUCHED:
        p = subprocess.run(["php", "-l", str(f)], capture_output=True, text=True)
        if p.returncode != 0:
            return False, f"{f.name}: {p.stdout.strip()}"
    return True, ""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--only", help="run a single mutation id, e.g. M7")
    args = ap.parse_args()

    snap = pathlib.Path(tempfile.mkdtemp(prefix="lg-ca-redfirst-"))
    for f in TOUCHED:
        shutil.copy2(f, snap / f.name)

    def restore():
        for f in TOUCHED:
            shutil.copy2(snap / f.name, f)

    try:
        code, out = run_gate()
        if code != 0:
            print("CANNOT RUN: the gate is not green on unmutated code.")
            print(out[-2500:])
            return 3
        base_pass = out.strip().splitlines()[-3] if out.strip() else "?"
        print(f"baseline GREEN ({base_pass})\n")

        cases = {**MUTATIONS, **NO_OPS}
        if args.only:
            cases = {args.only: cases[args.only]}

        red = green = broken = 0
        for mid, (desc, apply) in cases.items():
            expect_red = mid in MUTATIONS
            try:
                apply()
            except Exception as e:                       # noqa: BLE001
                print(f"  {mid}  ANCHOR LOST — {e}")
                restore()
                broken += 1
                continue

            good, why = lint_ok()
            if not good:
                print(f"  {mid}  INVALID MUTATION (would be a false RED) — {why}")
                restore()
                broken += 1
                continue

            code, _ = run_gate()
            restore()

            got_red = code != 0
            if expect_red and got_red:
                red += 1
                print(f"  RED    {mid}  {desc}")
            elif expect_red and not got_red:
                green += 1
                print(f"  !! GREEN {mid}  {desc}")
                print(f"           ^ the gate did NOT catch this. Fix the gate, not the mutation.")
            elif not expect_red and not got_red:
                print(f"  ok     {mid}  no-op stayed green — {desc}")
            else:
                broken += 1
                print(f"  !! RED  {mid}  A NO-OP TURNED THE GATE RED — it is keying on prose. {desc}")

        total = len(MUTATIONS) if not args.only else len([c for c in cases if c in MUTATIONS])
        print(f"\nred-first: {red}/{total} mutations caught")
        if green or broken:
            print("RED-FIRST FAILED — the gate has blind spots or is keying on the wrong thing.")
            return 1
        print("RED-FIRST CLEAN — every mutation was caught and every no-op stayed green.")
        return 0
    finally:
        restore()
        shutil.rmtree(snap, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
