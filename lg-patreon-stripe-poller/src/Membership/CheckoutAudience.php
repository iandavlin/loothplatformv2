<?php

declare(strict_types=1);

namespace LGMS\Membership;

use LGMS\Log;
use LGMS\StripeLifecycle;

/**
 * WHO MAY BUY, AND WHO MAY BE PROVISIONED. The one answer, for every door.
 *
 * Issue #181, ruled FIX BEFORE GO-LIVE by Ian 2026-08-21. The soft-launch
 * cohort was never a fact about the checkout path. Lane 180 measured it and
 * three unrelated accidents were doing the refusing:
 *
 *   1. page gating — which #180's unlock link deliberately opens;
 *   2. BuddyBoss's global `bb-enable-private-rest-apis`, which 401s the
 *      logged-out sign-in call the join page's JS insists on — a setting
 *      re-armed by every DB reload, not a membership control;
 *   3. LIVE ONLY, an EMPTY Stripe catalogue, so every checkout refused
 *      "not mapped to a membership tier" — AND THAT PROP IS REMOVED ON
 *      PURPOSE AT GO-LIVE.
 *
 * None of the three is a whitelist. `POST /billing/v1/checkout` carries no
 * auth at all, `/billing/v1/products` hands out the real price ids to anyone,
 * and a real price id minted a Stripe session with no account and no list.
 * Paying it ran Sync::customer -> UserProvisioner::findOrProvision, which
 * CREATES A WORDPRESS USER BY EMAIL and grants the tier.
 *
 * THE SHAPE IS AN AUDIENCE, NOT A SWITCH, and it is #170's shape on purpose —
 * that pattern is already proven on this rail:
 *
 *   'off'        nobody is asked about. Today's behaviour, exactly.
 *   'allowlist'  the soft-launch cohort, and nobody else. THE DEFAULT.
 *   'on'         everybody. The switch Ian throws when the test phase ends.
 *
 * ⚠️ THE DEFAULT IS `allowlist` — ENFORCING — AND THAT IS DELIBERATE (keeper,
 * ruling (a), 2026-08-21). Every other flag on this rail defaults to the inert
 * state so a merge lands dark. This one does the opposite, for the reason that
 * makes it worth the exception: **the enforcing state must be the state the
 * boxes actually run**, or it is never exercised before the one night it has
 * to work. A default of 'off' would ship a fence that nobody had ever walked
 * into. It costs nothing on live, whose catalogue is empty; on dev2 it closes
 * checkout for everyone outside the cohort, which is precisely the point.
 *
 * FAIL-SAFE IS CLOSED, matching StripeLifecycle::allowlist()'s own discipline:
 * an absent option, a typo, a stray array, a value from the future all read
 * `allowlist`. The one value that opens the doors wide is the literal string
 * 'on', typed on purpose.
 *
 * ONE LIST, AND THERE MUST NEVER BE A SECOND. The cohort is
 * `lgms_stripe_lifecycle_allowlist`, read through StripeLifecycle::inCohort()
 * — the same rows the webhook fence, the entitlement sweep and the header pill
 * already key on. This class owns NO list of its own, no option name of its
 * own, and no normalizer of its own; gate 86 §D asserts that by reading the
 * source.
 *
 * ⚠️ NO ADMIN BYPASS, and this is the difference from the HEADER's predicate
 * (keeper, ruling (b), 2026-08-21). `$caps['stripe_testgroup']` is
 * `manage_options || inCohort()`, which is right for a button — Ian should see
 * his own pill without adding himself to a list. It is WRONG for a fence: an
 * administrator who passes it cannot see the failure, and he is the one person
 * most likely to check. Three separate traps in this repo's memory are that
 * exact shape. So this class asks `inCohort()` and nothing else, and gate 86
 * keeps an `manage_options` mutation red.
 *
 * A WP OPTION, not a config file and not an FPM pool env. The readers span the
 * poller, WP-Cron (which carries no environment at all) and — over the
 * shared-secret REST channel — the Slim billing app, which cannot read
 * WordPress: its DB user holds `ALL ON lg_membership` and `USAGE ON *.*`, so
 * `wp_options` is closed to it. Same reasoning PatreonStanding wrote down, same
 * conclusion.
 */
final class CheckoutAudience
{
    /** The switch, and the only one. */
    public const OPT = 'lgms_checkout_audience';

    public const OFF       = 'off';
    public const ALLOWLIST = 'allowlist';
    public const ON        = 'on';

    /**
     * ENFORCING BY DEFAULT. See the class docblock — this is the deliberate
     * exception to the flags-default-dark rule, ruled 2026-08-21.
     */
    public const DEFAULT_STATE = self::ALLOWLIST;

    /** Where a refusal happened. Only ever used to make a log line readable. */
    public const D_SLIM_CHECKOUT = 'billing/v1/checkout';
    public const D_WP_CHECKOUT   = 'wp/me/checkout-session';
    public const D_PROVISION     = 'provision';

    /**
     * The normalized state.
     *
     * Anything that is not one of the three literals is `allowlist`. A garbled
     * option must never be the thing that opens the doors — the failure that
     * matters here is a wrong PASS, not a wrong block, because a wrong pass is
     * somebody paying us with no account and a wrong block is a support note.
     */
    public static function state(): string
    {
        $raw = get_option( self::OPT, self::DEFAULT_STATE );
        if ( ! is_string( $raw ) ) {
            return self::DEFAULT_STATE;
        }
        $raw = strtolower( trim( $raw ) );

        return in_array( $raw, [ self::OFF, self::ALLOWLIST, self::ON ], true )
            ? $raw
            : self::DEFAULT_STATE;
    }

    /** Is the audience being consulted at all? False only in `off`. */
    public static function enforcing(): bool
    {
        return self::state() !== self::OFF;
    }

    /**
     * May this WP user proceed?
     *
     * `off` answers true because nothing is consulted — the caller's behaviour
     * must be indistinguishable from the day before this shipped.
     */
    public static function allowsUser( int $wpUserId ): bool
    {
        $state = self::state();
        if ( $state === self::OFF || $state === self::ON ) {
            return true;
        }
        // NO manage_options widening. See the class docblock, ruling (b).
        return $wpUserId > 0 && StripeLifecycle::inCohort( $wpUserId );
    }

    /**
     * May the holder of this email address proceed?
     *
     * ⚠️ AN ABSENT EMAIL IS A REFUSAL, and this is the single line where a
     * copy of DoublePayGuard would reopen the whole hole. That guard passes a
     * checkout with no email, correctly: it asks "is this person ALREADY
     * paying elsewhere", and with nobody named there is no double payment to
     * prevent. This class asks the opposite question — "is this person ON a
     * list of WordPress user ids" — and an anonymous poster who names nobody
     * is exactly the caller #181 exists to refuse. Gate 86 §B3 keeps it.
     */
    public static function allowsEmail( ?string $email ): bool
    {
        $state = self::state();
        if ( $state === self::OFF || $state === self::ON ) {
            return true;
        }

        $email = trim( (string) $email );
        if ( $email === '' ) {
            return false;
        }

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            // No WordPress account ⇒ cannot be on a list of WordPress user
            // ids. This is the anonymous purchase, refused by construction
            // rather than by a rule someone has to remember to write.
            return false;
        }

        return self::allowsUser( (int) $user->ID );
    }

    /**
     * The words a refused buyer sees. One sentence, no jargon, and it says
     * what would actually change their situation.
     *
     * It deliberately does NOT say "you are not on the allowlist" — that
     * invites a stranger to go hunting for the list. It says the shape of the
     * truth: this is not open yet.
     */
    public static function refusalMessage(): string
    {
        return 'Memberships are not open for sale yet. We are running a small, '
             . 'invited test group first — if you would like to be part of it, get in touch.';
    }

    /**
     * The words when we could not find out.
     *
     * ⚠️ THIS SENTENCE MUST NOT BE THE 403's SENTENCE, and that is the whole
     * point of having two (keeper, 2026-08-21: "the distinction is the part
     * that will save an hour later"). A tester who is genuinely outside the
     * cohort and a tester whose request never reached WordPress look identical
     * from the browser, and they need opposite fixes: add them to the list, or
     * go and find out why the loopback is failing. One shared sentence sends
     * whoever is debugging down the wrong one.
     */
    public static function unknownMessage(): string
    {
        return 'We could not verify access to checkout just now. Please try again in a moment.';
    }

    /**
     * A refused purchase attempt is exactly the signal Ian asked to see, so
     * every door logs through here and none of them invent their own wording.
     *
     * NOTIFY ONCE, LOG EVERY TIME. `Sync::all()` revisits every customer every
     * five minutes, so a single stranger sitting in the customers table would
     * otherwise mail an operator 288 times a day and the alert channel would be
     * dead inside a week (this repo has already killed one alert channel that
     * way — the outbox timer on dev2 is disabled for exactly this reason). The
     * log line is cheap and complete; the notice is rate-limited to one per
     * address per day.
     */
    public static function logRefusal( string $door, ?string $email, int $wpUserId = 0, string $note = '' ): void
    {
        $shown = trim( (string) $email );
        if ( $shown === '' ) {
            $shown = '(no email)';
        }

        Log::line( sprintf(
            "[%s] checkout audience REFUSED at %s: state=%s email=%s wp=%s%s\n",
            gmdate( 'c' ),
            $door,
            self::state(),
            $shown,
            $wpUserId > 0 ? '#' . $wpUserId : '-',
            $note !== '' ? ' — ' . $note : '',
        ) );
    }

    /**
     * The operator notice, at most once per address per day.
     *
     * Returns true when it actually notified, so the gate can assert the
     * rate-limit rather than trusting it.
     */
    public static function notifyRefusalOnce( string $email, string $detail ): bool
    {
        $key = 'lgms_ca_notified_' . md5( strtolower( trim( $email ) ) );

        if ( function_exists( 'get_transient' ) && get_transient( $key ) ) {
            return false;
        }
        if ( function_exists( 'set_transient' ) ) {
            set_transient( $key, 1, DAY_IN_SECONDS );
        }
        if ( function_exists( 'lgpo_notify_failure' ) ) {
            lgpo_notify_failure( $email, '', 'stripe.audience_refused', $detail );
        }
        return true;
    }
}
