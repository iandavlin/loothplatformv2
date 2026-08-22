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

# ⚠️ TWO GATES, AND POINTING A MUTATION AT THE WRONG ONE IS A FALSE GREEN.
# Gate 86 STUBS LGMS\StripeLifecycle on purpose (its own docblock says so: what
# it measures is whether the checkout path ASKS, not whether the option
# normalizer works). So a mutation to the real StripeLifecycle cannot move gate
# 86 by even one assertion — the first #193 run produced six "blind spots" that
# were nothing of the kind, they were mutations aimed at a class the gate does
# not load. The real normalizer and the real read-side union belong to gate 34,
# which drives them. Each mutation names the gate that can actually see it.
GATE34 = ROOT / "lg-patreon-stripe-poller/deploy/remediation/test-soft-launch-allowlist.php"

AUD = ROOT / "lg-patreon-stripe-poller/src/Membership/CheckoutAudience.php"
REST = ROOT / "lg-patreon-stripe-poller/src/Wp/CheckoutAudienceRestController.php"
PROV = ROOT / "lg-patreon-stripe-poller/src/Wp/UserProvisioner.php"
PLUG = ROOT / "lg-patreon-stripe-poller/src/Plugin.php"
GUARD = ROOT / "lg-stripe-billing/src/Core/CheckoutAudienceGuard.php"
PROBE = ROOT / "lg-stripe-billing/src/Adapters/HttpCheckoutAudienceProbe.php"
SLIM = ROOT / "lg-stripe-billing/src/Http/Controllers/CheckoutController.php"
ENVS = ROOT / "lg-stripe-billing/src/Adapters/EnvSettingsStore.php"
COMP = ROOT / "lg-patreon-stripe-poller/src/Membership/CompStanding.php"
# #193 — the address half
SL = ROOT / "lg-patreon-stripe-poller/src/StripeLifecycle.php"
RESTCTL = ROOT / "lg-patreon-stripe-poller/src/Wp/RestController.php"
COHORT = ROOT / "lg-patreon-stripe-poller/src/CohortAllowlist.php"
# #203 — the three server-to-server exemptions, and the panel that reports them.
# GATE91 is named here for the same reason GATE34 is: a mutation aimed at a gate
# that does not load the file it changes is a FALSE GREEN, not a blind spot.
GATE91 = ROOT / "tools/gates/membership-health-gate.php"
HEALTH = ROOT / "lg-patreon-stripe-poller/src/Membership/Health.php"

TOUCHED = [AUD, REST, PROV, PLUG, GUARD, PROBE, SLIM, ENVS, COMP, SL, RESTCTL, COHORT,
           HEALTH, GATE, GATE91]


def sub(path, old, new, count=1):
    """One textual replacement.

    ⚠️ IT REFUSES AN AMBIGUOUS ANCHOR, and that is not pedantry — the docstring
    used to CLAIM uniqueness and never check it. `str.replace(..., 1)` silently
    takes the FIRST match, so a mutation whose anchor appears twice edits
    whichever function happens to come first in the file and reports RED under
    a label naming the other one. Found on #203: M47 said "appendExemption
    turns a non-array into an array" and was mutating
    exemptAuthFromBuddyBossRestriction, because the two share a guard clause
    character for character. It went RED, so nothing complained — a false RED
    attributed to the wrong assertion, which is the same family as this repo's
    false GREENs and just as expensive. Pass count>1 to mean it deliberately.
    """
    def apply():
        s = path.read_text()
        found = s.count(old)
        if found < count:
            raise AssertionError(
                f"anchor not found in {path.name} ({found} of {count}): {old[:70]!r}")
        if found > count:
            raise AssertionError(
                f"AMBIGUOUS anchor in {path.name}: {found} matches, expected {count}. "
                f"Widen it until it is unique — the first match is not necessarily "
                f"the function this mutation names: {old[:70]!r}")
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
           sub(AUD, "        if ( ! $user ) {\n            // No account AND the address is not listed.",
                    "        if ( ! $user ) {\n            return true;\n            // No account AND the address is not listed.")),
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
    # ⚠️ RE-TARGETED BY #203. This used to widen the audience filter with
    # /sync-customer, which was then a route nobody had opened. It is opened now
    # — by its OWN filter — so naming it here stopped modelling a wrong decision
    # and started modelling a differently-spelled right one. /run-now and
    # /refund-request are what a careless widening would actually reach today.
    "M18": ("the exemption is widened from one route to the whole namespace",
            sub(REST, "        if ( ! in_array( self::FULL_ROUTE, $endpoints, true ) ) {\n            $endpoints[] = self::FULL_ROUTE;\n        }",
                      "        $endpoints[] = self::FULL_ROUTE;\n        $endpoints[] = '/' . self::NAMESPACE . '/run-now';\n        $endpoints[] = '/' . self::NAMESPACE . '/refund-request';")),

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
    # ── the comp predicate (Ian 8/21, keeper's sharpening) ───────────────────
    "M21": ("a missing expiry reads EXPIRED — lapses the 12 of 14 holders who have no date",
            sub(COMP, "        return $exp === null || $exp > time();",
                      "        return $exp !== null && $exp > time();")),
    "M22": ("an expiry in the PAST still reads as an active comp — 'comped forever'",
            sub(COMP, "        return $exp === null || $exp > time();",
                      "        return true;")),
    "M23": ("an unparseable date lapses the member instead of being ignored",
            sub(COMP, "            // An unparseable date is NOT an expiry.", "            return 0;\n            // An unparseable date is NOT an expiry.")),

    # ── #193: THE LIST TAKES ADDRESSES ──────────────────────────────────────
    # Every one of these expresses a wrong DECISION, not a wrong shape. The
    # ones that matter most are M24 and M28: M24 is the whole feature reverting
    # to #181's behaviour, and M28 is the write-side promotion this design was
    # chosen over — it would pass "a listed address provisions" and fail only
    # the removal proof, which is exactly why that proof exists.
    "M24": ("allowsEmail() stops asking the list about the ADDRESS — #193 reverts to #181",
            sub(AUD, "        if ( StripeLifecycle::inCohortEmail( $email ) ) {\n            return true;\n        }",
                     "        if ( false ) {\n            return true;\n        }")),
    "M25": ("the address check is moved BELOW the resolve-to-user refusal, where it can never run",
            compose(
                sub(AUD, "        if ( StripeLifecycle::inCohortEmail( $email ) ) {\n            return true;\n        }\n\n", ""),
                sub(AUD, "        return self::allowsUser( (int) $user->ID );",
                         "        if ( StripeLifecycle::inCohortEmail( $email ) ) {\n            return true;\n        }\n\n        return self::allowsUser( (int) $user->ID );"))),
    "M26": ("allowlistEmails() stops validating — 'not-an-email' becomes a listed entry",
            sub(SL, "            if ( $e === '' || ! self::looksLikeEmail( $e ) ) {",
                    "            if ( $e === '' ) {"), GATE34),
    "M27": ("allowlistEmails() stops lower-casing — a listed address stops matching how it is typed",
            sub(SL, "            $e = strtolower( trim( $v ) );", "            $e = trim( $v );"), GATE34),
    "M28": ("inCohort() loses the read-side union — a listed address pays and the GRANT never lands",
            sub(SL, "        if ( $wpUserId <= 0 || self::allowlistEmails() === [] ) {\n            return false;\n        }",
                    "        if ( true ) {\n            return false;\n        }"), GATE34),
    "M29": ("inCohortEmail() matches on a PREFIX — newtester@example.com.evil.test gets in",
            sub(SL, "        return isset( self::allowlistEmails()[ $email ] );",
                    "        foreach ( array_keys( self::allowlistEmails() ) as $k ) {\n            if ( str_starts_with( $email, $k ) ) { return true; }\n        }\n        return false;"), GATE34),
    "M30": ("inCohortEmail('') returns true — the anonymous hole, reopened one function over",
            sub(SL, "        $email = strtolower( trim( (string) $email ) );\n        if ( $email === '' ) {\n            return false;\n        }",
                    "        $email = strtolower( trim( (string) $email ) );\n        if ( $email === '' ) {\n            return true;\n        }"), GATE34),
    "M31": ("inCohort() resolves the user even with NO addresses listed — the empty state stops being the off state",
            sub(SL, "        if ( $wpUserId <= 0 || self::allowlistEmails() === [] ) {",
                    "        if ( $wpUserId <= 0 || false ) {"), GATE34),
    "M32": ("the provision refusal goes back to blaming the missing account",
            sub(PROV, "'Stripe customer %d (%s — %s) is outside the soft-launch cohort: neither the '\n                . 'address nor any account it belongs to is on the list, and the checkout '",
                      "'Stripe customer %d (%s — %s) is outside the soft-launch cohort, and the checkout '")),

    # ── #193 / D3: the /auth exemption must stay SURGICAL ───────────────────
    # ⚠️ RE-TARGETED BY #203, same reason as M18, and keeper named the
    # replacement: /run-now is the one shared-secret route still behind the
    # wall, so appending it to a neighbour's list is exactly the widening
    # condition 3 forbids — an exemption that never got written down.
    "M33": ("the /auth exemption is widened by tacking another route onto its list (keeper condition 3)",
            sub(RESTCTL, "        '/' . self::NAMESPACE . '/auth',\n        '/' . self::NAMESPACE . '/gift-auth',",
                         "        '/' . self::NAMESPACE . '/auth',\n        '/' . self::NAMESPACE . '/gift-auth',\n        '/' . self::NAMESPACE . '/run-now',")),
    "M34": ("the /auth exemption is removed — a listed tester still cannot make an account",
            sub(RESTCTL, "        foreach ( self::AUTH_ROUTES as $route ) {", "        foreach ( [] as $route ) {")),
    "M35": ("the exemption replaces another plugin's entries instead of appending",
            sub(RESTCTL, "        if ( ! is_array( $endpoints ) ) {\n            return $endpoints;   // never replace another plugin's shape\n        }\n        foreach ( self::AUTH_ROUTES as $route ) {",
                         "        if ( ! is_array( $endpoints ) ) {\n            return $endpoints;\n        }\n        $endpoints = [];\n        foreach ( self::AUTH_ROUTES as $route ) {")),
    "M36": ("the /auth route loses its per-IP throttle (keeper condition 1)",
            sub(RESTCTL, "            if ( $ipHits >= 20 ) {", "            if ( false ) {")),
    "M37": ("the /auth route stops checking the password at all (keeper condition 1)",
            sub(RESTCTL, "                if ( ! wp_check_password( $password, $existing->user_pass, $existing->ID ) ) {",
                         "                if ( ! true ) {")),
    "M38": ("Plugin.php stops registering the exemption — the filter becomes a comment",
            sub(PLUG, "            [ Wp\\RestController::class, 'exemptAuthFromBuddyBossRestriction' ]",
                      "            [ Wp\\RestController::class, 'exemptFromNothing' ]")),

    # ── #193: the dash store's silent-loss trap, against gate 34 ────────────
    # This is the mutation that models what the code ACTUALLY did before #193:
    # write() rebuilt the option from the ids alone. It is here rather than in a
    # comment because "adding one member deletes every tester address" fails
    # silently, and a silent failure is exactly what a red-first run is for.
    "M39": ("CohortAllowlist::write() drops the addresses — one member edit eats the whole tester list",
            sub(COHORT, "        update_option( self::OPT, array_merge( $ids, $clean ), false );",
                        "        update_option( self::OPT, $ids, false );"), GATE34),
    "M40": ("addedMap() casts every key to int — every ADDRESS loses its date-added row",
            sub(COHORT, "            $e = self::normalizeEmail( (string) $k );\n            if ( $e !== '' ) {\n                $out[ $e ] = $v;\n            }",
                        "            $e = '';\n            if ( $e !== '' ) {\n                $out[ $e ] = $v;\n            }"), GATE34),
    "M41": ("addEmail() stores the raw typed value — the reader then silently ignores what the dash shows",
            sub(COHORT, "        $email = self::normalizeEmail( $email );\n        if ( $email === '' ) {\n            return false;\n        }\n        if ( in_array( $email, self::emails(), true ) ) {",
                        "        if ( $email === '' ) {\n            return false;\n        }\n        if ( in_array( $email, self::emails(), true ) ) {"), GATE34),
    "M42": ("removeEmail() stops normalizing — an address typed in caps cannot be removed",
            sub(COHORT, "    public static function removeEmail( string $email ): bool\n    {\n        $email = self::normalizeEmail( $email );",
                        "    public static function removeEmail( string $email ): bool\n    {\n        $email = trim( $email );"), GATE34),

    # ── #203: THE THREE DEAD SERVER-TO-SERVER ROUTES ────────────────────────
    # Each of these expresses a wrong DECISION about a route the billing app
    # calls with the correct secret, and each names the ONE assertion that must
    # catch it. The ones that matter most are M49 (the exemption quietly
    # becoming the authentication — the only way this change could be an auth
    # bypass rather than a repair) and M55 (a secret route added and never
    # named, which is the pre-#203 health-panel defect one indirection along).
    "M43": ("/sync-customer's exemption is a no-op — a paid tester waits for the sweep again",
            sub(RESTCTL, "        return self::appendExemption( $endpoints, self::SYNC_CUSTOMER_ROUTE );",
                         "        return $endpoints;")),
    "M44": ("/send-gift-codes' exemption is a no-op — a paid-for gift never arrives",
            sub(RESTCTL, "        return self::appendExemption( $endpoints, self::GIFT_CODES_ROUTE );",
                         "        return $endpoints;")),
    "M45": ("/send-gift-recipient's exemption is a no-op — Resend says fine and mails nobody",
            sub(RESTCTL, "        return self::appendExemption( $endpoints, self::GIFT_RECIPIENT_ROUTE );",
                         "        return $endpoints;")),
    "M46": ("appendExemption REPLACES the list — every other plugin's exemption is deleted",
            sub(RESTCTL, "        if ( ! in_array( $route, $endpoints, true ) ) {\n            $endpoints[] = $route;\n        }\n        return $endpoints;",
                         "        return [ $route ];")),
    "M47": ("appendExemption turns a non-array into an array — another plugin's shape is replaced",
            sub(RESTCTL, "        if ( ! is_array( $endpoints ) ) {\n            return $endpoints;   // never replace another plugin's shape\n        }\n        if ( ! in_array( $route, $endpoints, true ) ) {",
                         "        if ( ! is_array( $endpoints ) ) {\n            $endpoints = [];\n        }\n        if ( ! in_array( $route, $endpoints, true ) ) {")),
    "M48": ("appendExemption stops being idempotent — a double-registered filter duplicates",
            sub(RESTCTL, "        if ( ! in_array( $route, $endpoints, true ) ) {\n            $endpoints[] = $route;\n        }",
                         "        $endpoints[] = $route;")),
    # ⚠️ THE ONE THAT WOULD MAKE THIS AN AUTH BYPASS. The exemption lifts a
    # blanket pre-emption; the route's own shared-secret check is what must
    # still refuse. Relaxing one route to __return_true alongside the exemption
    # is the single edit that turns the repair into the thing it is not.
    "M49": ("/sync-customer becomes public in the same edit — the exemption becomes the auth",
            sub(RESTCTL, "        register_rest_route( self::NAMESPACE, '/sync-customer', [\n            'methods'             => 'POST',\n            'callback'            => [ self::class, 'syncCustomer' ],\n            'permission_callback' => [ self::class, 'auth' ],",
                         "        register_rest_route( self::NAMESPACE, '/sync-customer', [\n            'methods'             => 'POST',\n            'callback'            => [ self::class, 'syncCustomer' ],\n            'permission_callback' => '__return_true',")),
    "M50": ("auth() compares with == — a timing-attackable secret on three now-reachable routes",
            sub(RESTCTL, "        return $given !== '' && hash_equals( $expected, $given );\n    }\n\n    // Real client IP behind Cloudflare.",
                         "        return $given !== '' && $given == $expected;\n    }\n\n    // Real client IP behind Cloudflare.")),
    "M51": ("auth() opens when no secret is configured — an unconfigured box waves everyone through",
            sub(RESTCTL, "        $expected = (string) get_option( 'lgms_shared_secret', '' );\n        if ( $expected === '' ) {\n            return false;\n        }\n        $given = (string) $req->get_header( 'x-lgms-token' );",
                         "        $expected = (string) get_option( 'lgms_shared_secret', '' );\n        if ( $expected === '' ) {\n            return true;\n        }\n        $given = (string) $req->get_header( 'x-lgms-token' );")),
    "M52": ("Plugin.php drops the /send-gift-recipient registration — the filter becomes a comment",
            sub(PLUG, "        add_filter(\n            'bb_exclude_endpoints_from_restriction',\n            [ Wp\\RestController::class, 'exemptGiftRecipientFromBuddyBossRestriction' ]\n        );",
                      "        /* registration removed */")),
    "M53": ("SECRET_ROUTES forgets /run-now — the health panel stops reporting the one still-shut route",
            sub(RESTCTL, "        '/' . self::NAMESPACE . '/run-now',\n        self::SYNC_CUSTOMER_ROUTE,",
                         "        self::SYNC_CUSTOMER_ROUTE,")),
    "M54": ("SECRET_ROUTES bakes in the issue's wrong count — /send-gift-recipient is dropped",
            sub(RESTCTL, "        self::GIFT_CODES_ROUTE,\n        self::GIFT_RECIPIENT_ROUTE,\n    ];",
                         "        self::GIFT_CODES_ROUTE,\n    ];")),
    # ⚠️ THE DRIFT MUTATION, AND THE WHOLE REASON §K11 EXISTS. A new
    # shared-secret route added to register() and never named in SECRET_ROUTES
    # is invisible to the health panel in exactly the way the pre-#203 sentence
    # was — the same defect, one indirection along, and nothing else can see it.
    "M55": ("a new shared-secret route is registered and never added to SECRET_ROUTES",
            sub(RESTCTL, "        register_rest_route( self::NAMESPACE, '/refund-request', [",
                         "        register_rest_route( self::NAMESPACE, '/reconcile-now', [\n            'methods'             => 'POST',\n            'callback'            => [ self::class, 'runNow' ],\n            'permission_callback' => [ self::class, 'auth' ],\n        ] );\n\n        register_rest_route( self::NAMESPACE, '/refund-request', [")),
    # ── #203: the health panel's roll-call, against GATE 91 ─────────────────
    "M56": ("Health stops running the hook and hardcodes the one route it remembers (the pre-#203 defect)",
            sub(HEALTH, "        $all = apply_filters( 'bb_exclude_endpoints_from_restriction', [] );\n        if ( ! is_array( $all ) ) {\n            return [];\n        }",
                        "        $all = [ '/lg-member-sync/v1/checkout-audience' ];\n        if ( ! is_array( $all ) ) {\n            return [];\n        }"), GATE91),
    "M57": ("Health's still-shut line is a hand-kept list again instead of the difference",
            sub(HEALTH, "            $shut = array_values( array_diff( \\LGMS\\Wp\\RestController::SECRET_ROUTES, $open ) );",
                        "            $shut = [ '/lg-member-sync/v1/run-now' ];"), GATE91),
    "M58": ("Health reports every secret route as still shut — the warn line stops meaning anything",
            sub(HEALTH, "            $shut = array_values( array_diff( \\LGMS\\Wp\\RestController::SECRET_ROUTES, $open ) );",
                        "            $shut = \\LGMS\\Wp\\RestController::SECRET_ROUTES;"), GATE91),
}

NO_OPS = {
    "N1": ("reword a comment in CheckoutAudience.php",
           sub(AUD, "/** The switch, and the only one. */", "/** The one switch, and there is no second. */")),
    "N2": ("add a blank line to the guard",
           sub(GUARD, "final class CheckoutAudienceGuard\n{", "final class CheckoutAudienceGuard\n{\n")),
    # #193 — the address half has its own no-op control, or a green run above
    # proves nothing about whether §J is keying on prose rather than code.
    # #203 — the exemption section needs its own no-op, or a green run above
    # proves nothing about whether §K10/§K11 are keying on prose rather than code.
    "N4": ("reword a comment in the shared exemption appender",
           sub(RESTCTL, "            return $endpoints;   // never replace another plugin's shape\n        }\n        if ( ! in_array( $route, $endpoints, true ) ) {",
                        "            return $endpoints;   // another plugin's shape is never replaced\n        }\n        if ( ! in_array( $route, $endpoints, true ) ) {")),
    "N5": ("reword a comment in the health panel's roll-call",
           sub(HEALTH, "               there for why the list lives beside the routes it describes. */",
                       "               there for why that list lives next to the routes it names. */"), GATE91),
    "N3": ("reword a comment in StripeLifecycle's address reader",
           sub(SL, "     * @return array<string, true> normalized set of allowed email addresses",
                   "     * @return array<string, true> the normalized set of allowed addresses")),
}


def run_gate(which=None):
    p = subprocess.run(["php", str(which or GATE)],
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
            print("CANNOT RUN: gate 86 is not green on unmutated code.")
            print(out[-2500:])
            return 3
        c34, o34 = run_gate(GATE34)
        if c34 != 0:
            print("CANNOT RUN: gate 34 is not green on unmutated code.")
            print(o34[-2500:])
            return 3
        # #203 — three mutations target gate 91. A run that never checks its
        # baseline would report a pre-existing RED as thirteen caught mutations.
        c91, o91 = run_gate(GATE91)
        if c91 != 0:
            print("CANNOT RUN: gate 91 is not green on unmutated code.")
            print(o91[-2500:])
            return 3
        base_pass = out.strip().splitlines()[-3] if out.strip() else "?"
        print(f"baseline GREEN ({base_pass})\n")

        cases = {**MUTATIONS, **NO_OPS}
        if args.only:
            cases = {args.only: cases[args.only]}

        red = green = broken = 0
        for mid, spec in cases.items():
            desc, apply = spec[0], spec[1]
            which = spec[2] if len(spec) > 2 else GATE
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

            code, _ = run_gate(which)
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
