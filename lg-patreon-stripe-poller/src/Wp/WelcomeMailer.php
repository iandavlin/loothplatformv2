<?php

declare(strict_types=1);

namespace LGMS\Wp;

/**
 * Sends the post-upgrade welcome email exactly once per WP user.
 *
 * Triggered from Arbiter::sync at the same point that sets the
 * _lg_pending_welcome meta (the "user just upgraded into a paid tier"
 * transition). The modal handles users who return to the site; this
 * email handles users who don't — the typical path when they backed
 * out of the Stripe modal mid-charge and the cron-driven reconcile
 * sweep (or the checkout.session.completed webhook) provisions their
 * account server-side without them being present.
 *
 * Idempotency: a separate `_lg_welcome_email_sent_at` user meta acts
 * as a delivered-at sentinel. If it's already set we silently bail.
 * Resetting that meta is the supported way to re-fire the email
 * (e.g. for support recoveries).
 */
final class WelcomeMailer
{
    /**
     * Send the welcome email if we haven't already for this user.
     * Returns true if a message was dispatched, false if skipped.
     */
    public static function sendIfNeeded(int $wpUserId, string $tier): bool
    {
        if ( $wpUserId <= 0 ) {
            return false;
        }

        $alreadySent = (string) get_user_meta( $wpUserId, '_lg_welcome_email_sent_at', true );
        if ( $alreadySent !== '' ) {
            return false;
        }

        $user = get_user_by( 'id', $wpUserId );
        if ( ! $user || empty( $user->user_email ) ) {
            return false;
        }

        // Guard against re-welcoming ESTABLISHED members. The welcome is for
        // people who just joined. If a role-state perturbation — a DB reload that
        // wiped roles, an admin role edit (AdminRoleCapture), or a reconcile sweep
        // recomputing tiers — makes a long-standing member momentarily look "newly
        // upgraded", Arbiter::isUpgradeToPaid() returns true and we land here for an
        // account that is months or years old. Real new members are welcomed within
        // days of registering; anything older is a glitch, not an upgrade. Skip it,
        // and stamp the sentinel so the next sweep does not re-evaluate (self-healing:
        // a fresh box with no backfill still converges to no-resend after one sweep).
        // Threshold is filterable; raise it for support recoveries if ever needed.
        $maxAgeDays = (int) apply_filters( 'lgms_welcome_max_account_age_days', 14 );
        $registeredTs = strtotime( (string) $user->user_registered );
        if ( $maxAgeDays > 0 && $registeredTs !== false
            && ( time() - $registeredTs ) > $maxAgeDays * DAY_IN_SECONDS ) {
            error_log( sprintf(
                'LGMS WelcomeMailer: skipped established account %d (registered %s) — not a new upgrade, no welcome sent',
                $wpUserId, (string) $user->user_registered
            ) );
            update_user_meta( $wpUserId, '_lg_welcome_email_sent_at', 'skipped-established:' . gmdate( 'c' ) );
            return false;
        }

        $name = trim( (string) ( $user->display_name ?: $user->first_name ?: $user->user_login ) );
        $body = self::renderBody( $name, $tier, $user );
        if ( $body === null ) {
            return false;
        }

        $subject = sprintf( 'Welcome to %s — your membership is active', self::tierLabel( $tier ) );

        $sent = wp_mail( $user->user_email, $subject, $body, self::headers() );
        if ( $sent ) {
            update_user_meta( $wpUserId, '_lg_welcome_email_sent_at', gmdate( 'c' ) );
        } else {
            error_log( "LGMS WelcomeMailer: wp_mail returned false for user {$wpUserId}" );
        }
        return (bool) $sent;
    }

    /**
     * Send a test copy of the welcome email to an arbitrary recipient.
     * Driven by the Membership Guide admin preview bar so admins can dry-run
     * the email visually. Subject is prefixed [TEST] so it's distinguishable
     * in the inbox. Does NOT touch _lg_welcome_email_sent_at — calling this
     * against your own account does not block the eventual production send.
     *
     * @return array{ok:bool, message:string}
     */
    public static function sendTest( string $recipientEmail, string $tier = 'looth2' ): array
    {
        $recipientEmail = trim( $recipientEmail );
        if ( $recipientEmail === '' || ! is_email( $recipientEmail ) ) {
            return [ 'ok' => false, 'message' => 'A valid recipient email is required.' ];
        }

        // Use the recipient's own WP display name when they have an account,
        // otherwise fall back to the local-part of the email so the greeting
        // looks personalised. Production sends always use the upgraded user's
        // own name; the test just needs something readable.
        $existing = get_user_by( 'email', $recipientEmail );
        if ( $existing ) {
            $name = trim( (string) ( $existing->display_name ?: $existing->first_name ?: $existing->user_login ) );
        } else {
            $name = ucfirst( (string) ( strstr( $recipientEmail, '@', true ) ?: 'there' ) );
        }

        $body = self::renderBody( $name, $tier, $existing ?: null );
        if ( $body === null ) {
            return [ 'ok' => false, 'message' => 'Email template missing on the server.' ];
        }

        $subject = sprintf( '[TEST] Welcome to %s — your membership is active', self::tierLabel( $tier ) );
        $sent    = wp_mail( $recipientEmail, $subject, $body, self::headers() );

        if ( ! $sent ) {
            error_log( "LGMS WelcomeMailer test send: wp_mail returned false for {$recipientEmail}" );
            return [ 'ok' => false, 'message' => 'wp_mail() returned false. Check FluentSMTP / mail logs.' ];
        }
        return [ 'ok' => true, 'message' => 'Test email sent to ' . $recipientEmail ];
    }

    private static function renderBody( string $name, string $tier, ?\WP_User $user = null ): ?string
    {
        $template = LGMS_PLUGIN_DIR . 'templates/email/welcome-membership.html.php';
        if ( ! file_exists( $template ) ) {
            error_log( "LGMS WelcomeMailer: template missing at {$template}" );
            return null;
        }

        $tierLabel     = self::tierLabel( $tier );
        $loginUrl      = wp_login_url( home_url( '/activity/' ) );
        $manageUrl     = home_url( '/manage-subscription/' );
        $homeUrl       = home_url( '/' );
        $guideUrl      = home_url( '/membership-guide/' );
        $loothalongUrl = (string) get_option( 'lgms_guide_loothalong_url', '' );
        $mosaicImages  = self::loadMosaicImages();

        // Password-reset URL — folded in from UserProvisioner's legacy
        // "set your password" email so the user gets a single welcome
        // message instead of two. Empty when $user is null (test sends
        // against an unknown address) — template hides the button then.
        $passwordResetUrl = '';
        if ( $user instanceof \WP_User ) {
            $key = get_password_reset_key( $user );
            if ( ! is_wp_error( $key ) ) {
                $passwordResetUrl = network_site_url(
                    'wp-login.php?action=rp&key=' . rawurlencode( $key )
                        . '&login=' . rawurlencode( $user->user_login ),
                    'login'
                );
            }
        }

        // Page-mirroring data sets — same sources the /membership-guide/
        // page reads, so a new elder / show / event surfaces in newly-sent
        // welcome emails as soon as the admin saves it on the page editor.
        // Existing emails already in inboxes are immutable (standard email
        // semantics — see PICKUP). Limited to 3 items each so the email
        // stays compact and reliable in narrow Outlook columns.
        $upcomingEvents  = \LGMS\Wp\UpcomingEvents::nextN( 3 );
        $recurringShows  = array_slice( \LGMS\Wp\MembershipGuide::getRecurringShows(), 0, 3 );
        $eldersForEmail  = array_map(
            fn( array $e ) => [
                'name'   => (string) ( $e['name'] ?? '' ),
                'avatar' => \LGMS\Wp\MembershipGuide::getElderAvatar( $e, 'thumb' ),
            ],
            array_slice( \LGMS\Wp\MembershipGuide::getElders(), 0, 3 )
        );

        ob_start();
        // Variables in scope for the template:
        //   $name, $tierLabel, $loginUrl, $manageUrl, $homeUrl, $guideUrl,
        //   $loothalongUrl, $mosaicImages, $upcomingEvents, $recurringShows,
        //   $eldersForEmail, $passwordResetUrl
        require $template;
        return (string) ob_get_clean();
    }

    private static function headers(): array
    {
        return [
            'Content-Type: text/html; charset=UTF-8',
            'From: The Looth Group <noreply@loothgroup.com>',
        ];
    }

    private static function loadMosaicImages(): array
    {
        $saved = json_decode( (string) get_option( 'lgms_welcome_mosaic_ids', '[]' ), true );
        if ( ! is_array( $saved ) ) {
            return [];
        }
        $urls = [];
        foreach ( $saved as $id ) {
            $id = (int) $id;
            if ( $id <= 0 ) {
                continue;
            }
            $url = get_the_post_thumbnail_url( $id, 'medium' );
            if ( $url ) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    private static function tierLabel( string $tier ): string
    {
        return [
            'looth2' => 'Looth LITE',
            'looth3' => 'Looth PRO',
            'looth4' => 'Looth Premium Plus',
        ][ $tier ] ?? 'Looth';
    }
}
