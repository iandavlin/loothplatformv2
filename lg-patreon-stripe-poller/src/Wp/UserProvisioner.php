<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\Db;
use RuntimeException;

/**
 * Find or create a WP user for an lg_membership customer.
 * Always inserts wp_user_bridge on success.
 *
 * Lookup priority: existing bridge row > WP user by email > create new.
 */
final class UserProvisioner
{
    public static function findOrProvision(int $customerId, string $email, ?string $name): int
    {
        // Already bridged?
        $stmt = Db::pdo()->prepare(
            'SELECT wp_user_id FROM wp_user_bridge WHERE customer_id = ? LIMIT 1'
        );
        $stmt->execute( [ $customerId ] );
        $bridged = $stmt->fetchColumn();
        if ( $bridged !== false ) {
            return (int) $bridged;
        }

        // WHO MAY BE PROVISIONED (#181, option `lgms_checkout_audience`,
        // default `allowlist`). THE BACKSTOP, and the half that actually holds.
        //
        // The checkout doors refuse early and honestly, but they refuse a
        // REQUEST. This refuses an OUTCOME, and it is the only one of the two
        // that cannot be routed around: a session minted before the cohort
        // changed, a session minted while the Slim probe could not reach
        // WordPress, a gift redeemed by a stranger, a customer the five-minute
        // `Sync::all()` sweep finds on its own — every one of them arrives
        // here, and here there is no network between the question and the
        // answer. The option is read straight out of `wp_options` in the same
        // process, so this check FAILS CLOSED where the Slim probe cannot.
        //
        // ⚠️ PLACEMENT IS THE WHOLE DESIGN, and it is one line below where the
        // obvious reading would put it. It sits AFTER the existing-bridge
        // early return, so a member who is already linked is never touched in
        // any state — their sweeps keep running, their grants keep landing and,
        // just as importantly, their RETRACTIONS keep landing. A fence placed
        // above that return would freeze existing members the moment the
        // cohort narrowed, and would do it silently.
        //
        // It sits ABOVE the identity gate and the mint, which is the other
        // half: `Sync::customer` calls this method BEFORE it reaches
        // StripeLifecycle's cohort fence, so that fence has only ever withheld
        // the ROLE. The account, the bridge row, the welcome mail and the
        // `looth_tier_changed` action all fired for a stranger who paid. This
        // is where that stops.
        //
        // THE REFUSAL IS A THROW because `Sync::customer` already catches one
        // into `provision failed` — no RoleSourceWriter::report, no Arbiter, no
        // tier — which is exactly the shape the identity gate below chose for
        // the same reason. Nothing is half-done: no user, no bridge, no grant.
        if ( ! \LGMS\Membership\CheckoutAudience::allowsEmail( $email ) ) {
            // WHO WAS REFUSED, not just that somebody was. An operator reading
            // this needs to tell a stranger apart from a member we already comp
            // — they are opposite support actions (refund and ignore, versus
            // "why is a staff member being asked to pay"). Ian 2026-08-21:
            // looth4 is the everything bypass and the Stripe side must respect
            // it. UNEXPIRED looth4, per keeper's sharpening the same day —
            // LGMS\Membership\CompStanding is that predicate, and it is
            // deliberately read-only: re-arming comp expiry is #183.
            $refusedUser = get_user_by( 'email', $email );
            $who         = $refusedUser
                ? \LGMS\Membership\CompStanding::describe( (int) $refusedUser->ID )
                : 'no WordPress account';

            $detail = sprintf(
                'Stripe customer %d (%s — %s) is outside the soft-launch cohort, and the checkout '
                . 'audience is `%s`, so no WordPress account was created and no membership was '
                . 'granted. If this purchase is genuine it needs a refund, or the buyer needs '
                . 'adding to the cohort (LG Member Sync -> Test Group).',
                $customerId,
                $email,
                $who,
                \LGMS\Membership\CheckoutAudience::state(),
            );

            \LGMS\Membership\CheckoutAudience::logRefusal(
                \LGMS\Membership\CheckoutAudience::D_PROVISION,
                $email,
                0,
                sprintf( 'customer %d — nothing minted, nothing granted (%s)', $customerId, $who ),
            );
            \LGMS\Membership\CheckoutAudience::notifyRefusalOnce( $email, $detail );

            throw new RuntimeException( $detail );
        }

        // IDENTITY GATE (audit R1). Flag OFF (the default, and the state of
        // live today) leaves everything below byte-identical to the behaviour
        // that has always shipped. Flag ON routes the lookup through
        // IdentityMatcher and REFUSES TO MINT rather than creating a duplicate
        // account for a member we merely failed to recognise.
        //
        // This flag MUST be ON before any member can reach Stripe onboarding.
        // It is item 1 on the launch checklist, ahead of unfreezing ingest,
        // because the window between "we started building" and "identity is
        // safe" must never be open.
        if ( self::identityGateOn() ) {
            return self::findOrProvisionGated( $customerId, $email, $name );
        }

        // WP user exists by email? Bridge and return.
        $existing = get_user_by( 'email', $email );
        if ( $existing ) {
            self::writeBridge( $customerId, (int) $existing->ID );
            return (int) $existing->ID;
        }

        // Create a fresh WP user. role=looth1; arbiter will upgrade if entitled.
        $username = self::generateUsername( $email );
        $userId   = wp_insert_user( [
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password( 24, true, true ),
            'display_name' => $name ?: $username,
            'first_name'   => self::firstName( $name ),
            'last_name'    => self::lastName( $name ),
            'role'         => 'looth1',
        ] );

        if ( is_wp_error( $userId ) ) {
            throw new RuntimeException( 'wp_insert_user failed: ' . $userId->get_error_message() );
        }

        // Tag as Starter BB Profile Type — pairs with the
        // 'hide from Members Directory' + 'hide from Network Search'
        // flags on the type so looth1-only users don't show up in
        // member listings until Arbiter promotes them (Arbiter clears
        // the type at the same moment it grants looth2+).
        if ( function_exists( 'bp_set_member_type' ) ) {
            bp_set_member_type( (int) $userId, 'starter' );
        }

        self::writeBridge( $customerId, (int) $userId );
        self::sendWelcomeEmail( (int) $userId );

        // Operator notice (Ian only): a brand-new member account was minted by
        // the Stripe pipeline. Tier is unknown at provision time — the caller
        // (Sync::customer) reports the entitlement and runs the Arbiter right
        // after this returns — so pass null ("looth1 initial"). function_exists
        // guard matches the engine's lgpo_alert_failure call style.
        if ( function_exists( 'lgpo_notify_onboard' ) ) {
            lgpo_notify_onboard( (int) $userId, (string) ( $name ?: $username ), $email, null, 'stripe (provisioner)' );
        }

        // Initial tier grant. Fire so the cache-invalidation hook can
        // primes profile-app's /whoami cache for the new account.
        do_action( 'looth_tier_changed', (int) $userId, null, 'looth1', 'new' );

        return (int) $userId;
    }

    /**
     * Is the identity gate armed? An OPTION, not an env var or a
     * fastcgi_param: WP-Cron runs with no environment at all, and the tick is
     * the main caller of this code, so an env-based flag would read as unset
     * in exactly the context that matters.
     */
    public static function identityGateOn(): bool
    {
        return (bool) get_option( 'lgms_identity_gate', false );
    }

    /**
     * Gated provisioning: match, or refuse. Never mints.
     *
     * The refusal is deliberately loud and deliberately terminal. A Stripe
     * customer we cannot confidently tie to an existing account is a support
     * ticket, not a new row — minting is what produced the duplicate accounts
     * in the first place. Sync::customer catches the throw and reports
     * 'provision failed', which now reaches an operator because the tick has
     * a real log again (LGMS\Log).
     */
    private static function findOrProvisionGated(int $customerId, string $email, ?string $name): int
    {
        $match = IdentityMatcher::match( $customerId, $email );

        if ( $match !== null ) {
            self::writeBridge( $customerId, $match['wp_user_id'] );
            \LGMS\Log::line( sprintf(
                "[%s] identity gate: customer %d -> WP #%d via %s\n",
                gmdate( 'c' ), $customerId, $match['wp_user_id'], $match['via']
            ) );
            return $match['wp_user_id'];
        }

        $detail = sprintf(
            'Stripe customer %d (%s) could not be matched to a WP account, and the identity gate '
            . 'forbids minting one. %s',
            $customerId, $email, IdentityMatcher::describeConflict( $customerId, $email )
        );

        \LGMS\Log::line( sprintf( "[%s] provision REFUSED: %s\n", gmdate( 'c' ), $detail ) );

        if ( function_exists( 'lgpo_notify_failure' ) ) {
            lgpo_notify_failure( $email, (string) ( $name ?? '' ), 'stripe.identity_unmatched', $detail );
        }

        throw new RuntimeException( $detail );
    }

    private static function writeBridge(int $customerId, int $wpUserId): void
    {
        Db::pdo()->prepare(
            'INSERT INTO wp_user_bridge (customer_id, wp_user_id, synced_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE wp_user_id = VALUES(wp_user_id), synced_at = NOW()'
        )->execute( [ $customerId, $wpUserId ] );
    }

    private static function generateUsername(string $email): string
    {
        $base = sanitize_user( strstr( $email, '@', true ) ?: 'member', true );
        if ( ! $base ) {
            $base = 'member';
        }
        $candidate = $base;
        $n         = 1;
        while ( username_exists( $candidate ) ) {
            $candidate = $base . '_' . ++$n;
            if ( $n > 100 ) {
                $candidate = $base . '_' . wp_generate_password( 6, false );
                break;
            }
        }
        return $candidate;
    }

    private static function firstName(?string $full): string
    {
        if ( ! $full ) return '';
        $parts = preg_split( '/\s+/', trim( $full ), 2 );
        return $parts[0] ?? '';
    }

    private static function lastName(?string $full): string
    {
        if ( ! $full ) return '';
        $parts = preg_split( '/\s+/', trim( $full ), 2 );
        return $parts[1] ?? '';
    }

    /**
     * Legacy plain-text "set your password" welcome email. Now a no-op —
     * the password-reset URL is folded into the pretty WelcomeMailer HTML
     * so each new member gets exactly one welcome email. Kept as a no-op
     * (rather than deleted) so any in-flight callers (legacy hooks, future
     * findOrProvision call sites) don't fatal until cleaned up.
     */
    private static function sendWelcomeEmail(int $userId): void
    {
        // intentionally empty
    }
}
