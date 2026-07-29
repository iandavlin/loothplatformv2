<?php

declare(strict_types=1);

namespace LGMS;

use LGMS\Wp\RestController;
use Throwable;

/**
 * Canonical user teardown — the ONE path that removes a member across every
 * store that knows about them. Keyed on wp_user_id; the lg_membership
 * customer_id is resolved internally via wp_user_bridge (falling back to
 * email).
 *
 * Folds the three pre-existing partial teardown tools into a single
 * implementation so they can no longer drift:
 *   - MemberTools::doNuke              (Stripe cancel + lg_membership + WP delete)
 *   - TestChecklist wipeQueries        (BP + email-keyed rows)
 *   - RestController::eraseBuddypressFootprint (BuddyPress social rows)
 * …and ADDS the two systems none of them touched: profile-app (Postgres
 * identity + media, via the internal erase-user endpoint) and discovery.
 *
 * Two modes (Ian, 2026-06-04):
 *   - nuke      — erase the member AND everything they authored (posts,
 *                 comments, forum topics/replies, discovery rows, media).
 *                 The test path. Refuses administrators and user 1.
 *   - tombstone — erase the member's identity everywhere, but REASSIGN their
 *                 authored content to a stable "[deleted member]" sentinel so
 *                 forum threads / comments still render. Billing cancelled,
 *                 media + identity rows gone.
 *
 * dry_run returns the cross-store counts that WOULD be removed (and asks
 * profile-app for its own preview) so the dash can show the admin a preview
 * before they confirm. No mutations happen on a dry run.
 *
 * Cross-store calls that cannot share a transaction (Stripe API, the WP DB,
 * the lg_membership DB, profile-app over HTTP) run sequentially and collect
 * their own failures into result['errors'] — the contract is FAIL LOUD, never
 * silently leave a store orphaned.
 */
final class UserLifecycle
{
    public const MODE_NUKE      = 'nuke';
    public const MODE_TOMBSTONE = 'tombstone';

    private const SENTINEL_OPTION = 'lgms_sentinel_user_id';
    private const SENTINEL_LOGIN  = 'deleted-member';
    private const SENTINEL_NAME   = '[deleted member]';

    private const ERASE_PATH = '/profile-api/v0/internal/erase-user';

    /** wp_user_ids currently being torn down — lets the deleted_user safety
     *  net skip the user we are already handling, avoiding re-entrancy when
     *  teardown() itself calls wp_delete_user(). */
    private static array $handling = [];

    public static function isHandling( int $wpUserId ): bool
    {
        return isset( self::$handling[ $wpUserId ] );
    }

    /**
     * @return array{
     *   mode:string, dry_run:bool, wp_user_id:int, customer_id:?int, email:?string,
     *   counts:array<string,int>, stripe_cancelled:array<int,string>,
     *   profile_app:array<string,mixed>, sentinel_id:?int, errors:array<int,string>
     * }
     */
    public static function teardown( int $wpUserId, string $mode, bool $dryRun = false ): array
    {
        if ( ! in_array( $mode, [ self::MODE_NUKE, self::MODE_TOMBSTONE ], true ) ) {
            throw new \InvalidArgumentException( "Unknown teardown mode: {$mode}" );
        }
        if ( $wpUserId <= 0 ) {
            throw new \InvalidArgumentException( 'teardown requires a positive wp_user_id.' );
        }

        $result = [
            'mode'             => $mode,
            'dry_run'          => $dryRun,
            'wp_user_id'       => $wpUserId,
            'customer_id'      => null,
            'email'            => null,
            'counts'           => [],
            'stripe_cancelled' => [],
            'profile_app'      => [],
            'sentinel_id'      => null,
            'errors'           => [],
        ];

        $wpUser = get_userdata( $wpUserId ) ?: null;
        $email  = $wpUser ? (string) $wpUser->user_email : null;

        // Refuse the destructive nuke on protected accounts — but only while
        // the WP user still exists. The deleted_user safety net calls us AFTER
        // wp_delete_user, by which point $wpUser is null; that orphan fan-out
        // must always be allowed to finish, never refused.
        if ( $mode === self::MODE_NUKE && $wpUser !== null ) {
            if ( $wpUserId === 1 ) {
                throw new \RuntimeException( 'Refusing to nuke user 1 (primary administrator).' );
            }
            if ( in_array( 'administrator', (array) $wpUser->roles, true ) ) {
                throw new \RuntimeException( 'Refusing to nuke an administrator account. Demote first.' );
            }
        }

        // Resolve the lg_membership customer (bridge first, then email).
        $customerId = self::resolveCustomerId( $wpUserId, $email );
        if ( $customerId !== null && $email === null ) {
            // WP user already gone (deleted_user path) — recover the email from
            // the customer row so email-keyed cleanup still fires.
            $email = self::customerEmail( $customerId );
        }
        $result['customer_id'] = $customerId;
        $result['email']       = $email;

        // ---- 1. Stripe: cancel active subscriptions (both modes) -----------
        if ( $customerId !== null ) {
            $result['stripe_cancelled'] = self::cancelStripeSubs( $customerId, $dryRun, $result['errors'] );
        }

        // ---- 2. Authored content (WP posts/comments/forum) -----------------
        // nuke deletes it; tombstone reassigns it to the sentinel. Both are
        // driven through wp_delete_user's own reassign machinery below (step 6)
        // for posts; comments are handled explicitly here so we can relabel
        // them "[deleted member]" on tombstone.
        if ( $wpUser !== null ) {
            $result['counts'] += self::handleContent( $wpUserId, $mode, $dryRun, $result );
        }

        // ---- 3. lg_membership + role/patreon/session rows ------------------
        $result['counts'] += self::runOps(
            self::membershipOps( $customerId, $wpUserId, $email ),
            $dryRun,
            $result['errors']
        );

        // ---- 4. BuddyPress social footprint (both modes) -------------------
        if ( $wpUser !== null ) {
            if ( $dryRun ) {
                $result['counts']['bp_social_rows'] = self::countBuddypress( $wpUserId );
            } else {
                try {
                    RestController::eraseBuddypressFootprint( $wpUserId );
                } catch ( Throwable $e ) {
                    $result['errors'][] = 'buddypress: ' . $e->getMessage();
                }
            }
        }

        // ---- 5. profile-app: identity (CASCADE) + media (both modes) -------
        $result['profile_app'] = self::callProfileAppErase( $wpUserId, $mode, $dryRun, $result['errors'] );

        // ---- 6. discovery: person + content_item ---------------------------
        $result['counts'] += self::handleDiscovery( $wpUserId, $email, $mode, $dryRun, $result['errors'] );

        // ---- 7. WP user delete (both modes; reassign posts on tombstone) ---
        if ( ! $dryRun && $wpUser !== null ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $reassign = $mode === self::MODE_TOMBSTONE ? self::sentinelId() : null;
            if ( $mode === self::MODE_TOMBSTONE ) {
                $result['sentinel_id'] = $reassign;
            }
            self::$handling[ $wpUserId ] = true;
            try {
                wp_delete_user( $wpUserId, $reassign ?? '' );
            } catch ( Throwable $e ) {
                $result['errors'][] = 'wp_delete_user: ' . $e->getMessage();
            } finally {
                unset( self::$handling[ $wpUserId ] );
            }
        }

        return $result;
    }

    /**
     * Canonical user CREATE front-door — the mirror of teardown(). The only
     * code that should make a Looth user. Every creator (Patreon onboard,
     * gift-auth, Stripe, sweep-match, admin, affiliate, native) routes through
     * here so they stop each keeping a different subset of the create promises
     * (USER-LIFECYCLE-AUDIT §2). Idempotent on the PATREON USER ID first and email
     * second: an existing account is found + reconciled, never duplicated.
     *
     * Promises kept, in order:
     *   1. WP account — find by Patreon id, then by email, else create. On an id
     *      match the incoming email is written ONTO that account (logged, previous
     *      value kept in `lgpo_previous_user_email`). Refuses to adopt an admin, or
     *      to move an email onto an account linked to a different Patreon id / owned
     *      by someone else; those route to the pending human-review queue.
     *   2. user_meta from $opts['meta'] + display/first/last name.
     *   3. Tier role via the Arbiter source pipeline (never a raw set_role) —
     *      $opts['tier'] reported under $opts['source'] (default manual_admin).
     *   4. profile-app identity + bridge, **blocking with retry** (fixes G7's
     *      fire-and-forget miss). Fail-loud into result['errors'] — never
     *      silently leave a logged-in user with no /whoami identity.
     *   5. Optional auth cookie + wp_login so they land authenticated and the
     *      looth_id JWT mints ($opts['login']).
     *
     * @param array{
     *   display_name?:string, first_name?:string, last_name?:string,
     *   patreon_user_id?:string, tier?:?string, source?:string, login?:bool,
     *   meta?:array<string,mixed>
     * } $opts
     * @return array{
     *   ok:bool, wp_user_id:int, created:bool, email:string, role:?string,
     *   matched_by?:string, email_changed_from?:string,
     *   profile_identity:array<string,mixed>, logged_in:bool, errors:array<int,string>
     * }
     */
    public static function provision( string $email, array $opts = [] ): array
    {
        $email = sanitize_email( $email );
        if ( $email === '' || ! is_email( $email ) ) {
            throw new \InvalidArgumentException( 'provision requires a valid email.' );
        }

        $result = [
            'ok'               => true,
            'wp_user_id'       => 0,
            'created'          => false,
            'email'            => $email,
            'role'             => $opts['tier'] ?? null,
            'profile_identity' => [],
            'logged_in'        => false,
            'errors'           => [],
        ];

        $displayName = isset( $opts['display_name'] ) ? (string) $opts['display_name'] : '';

        // ---- 1. WP account: PATREON ID FIRST, then email, else create ------
        //
        // Email is NOT an identity. It is a mutable credential a member can change
        // on Patreon at any time, and keying on it is what splits one human into two
        // accounts: the patron comes back with a new address, no account matches, and
        // a second one is minted. The Patreon user id is the stable key, so it is
        // consulted FIRST and the incoming email is written ONTO the account it finds.
        //
        // Mirrors what class-lgpo-sync-engine.php already does on the roster path
        // (a6af334) — same key, same fallback order, same refusals — so the two
        // creators cannot drift into disagreeing about who a member is.
        //
        // Two things it will NOT do, both of which are real-world harm:
        //   - adopt a privileged account (never hand an admin identity to a poller)
        //   - move an email onto an account already linked to a DIFFERENT Patreon id,
        //     or onto one whose address belongs to somebody else
        // Those route to the same pending/human-review path onboard.php uses for the
        // mikelle.davlin case, and the caller gets ok=false rather than a silent skip.
        $patreonId = '';
        if ( isset( $opts['patreon_user_id'] ) ) {
            $patreonId = trim( (string) $opts['patreon_user_id'] );
        } elseif ( isset( $opts['meta']['lgpo_patreon_user_id'] ) ) {
            $patreonId = trim( (string) $opts['meta']['lgpo_patreon_user_id'] );
        }

        $user       = null;
        $matchedBy  = '';
        if ( $patreonId !== '' ) {
            $user = self::userByPatreonId( $patreonId );
            if ( $user ) { $matchedBy = 'patreon_id'; }
        }

        if ( $user && user_can( $user, 'manage_options' ) ) {
            // Never adopt an admin over this path. Same refusal as onboard.php.
            self::flagForReview( $patreonId, $email, (int) $user->ID, 'admin_collision' );
            $result['ok']     = false;
            $result['errors'][] = 'refused: Patreon id ' . $patreonId
                . ' resolves to a privileged account (#' . (int) $user->ID . ') — routed to human review';
            $result['wp_user_id'] = (int) $user->ID;
            return $result;
        }

        if ( $user ) {
            // THE STEP THAT WAS MISSING ENTIRELY: update the account's email to the
            // incoming one. Changing an email is a real-world action — it moves where
            // their mail goes and what they log in with — so it is logged, the previous
            // value is recorded for reversal, and it is refused if anyone else holds it.
            $current = strtolower( trim( (string) $user->user_email ) );
            if ( $current !== strtolower( $email ) ) {
                $owner = get_user_by( 'email', $email );
                if ( $owner && (int) $owner->ID !== (int) $user->ID ) {
                    self::flagForReview( $patreonId, $email, (int) $user->ID, 'email_owned_by_other' );
                    $result['errors'][] = 'email ' . $email . ' already belongs to WP #'
                        . (int) $owner->ID . ' — NOT changed, routed to human review';
                } else {
                    update_user_meta( $user->ID, 'lgpo_previous_user_email', $current );
                    $upd = wp_update_user( [ 'ID' => $user->ID, 'user_email' => $email ] );
                    if ( is_wp_error( $upd ) ) {
                        $result['errors'][] = 'email update failed for #' . (int) $user->ID
                            . ': ' . $upd->get_error_message();
                    } else {
                        error_log( sprintf(
                            'LGMS UserLifecycle: patreon_id=%s matched WP #%d — email %s -> %s (previous recorded in lgpo_previous_user_email)',
                            $patreonId, (int) $user->ID, $current !== '' ? $current : '(blank)', $email
                        ) );
                        $result['email_changed_from'] = $current;
                        $user = get_user_by( 'id', (int) $user->ID );
                    }
                }
            }
        }

        if ( ! $user ) {
            $byEmail = get_user_by( 'email', $email );
            if ( $byEmail ) {
                $linked = (string) get_user_meta( $byEmail->ID, 'lgpo_patreon_user_id', true );
                if ( $patreonId !== '' && $linked !== '' && $linked !== $patreonId ) {
                    // Two Patreon accounts, one email — a genuine conflict, not a match.
                    self::flagForReview( $patreonId, $email, (int) $byEmail->ID, 'different_patreon_id' );
                    $result['ok'] = false;
                    $result['errors'][] = 'refused: ' . $email . ' belongs to WP #' . (int) $byEmail->ID
                        . ' which is linked to Patreon id ' . $linked . ' — routed to human review';
                    $result['wp_user_id'] = (int) $byEmail->ID;
                    return $result;
                }
                $user      = $byEmail;
                $matchedBy = 'email';
            }
        }

        $result['matched_by'] = $matchedBy;

        if ( ! $user ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $username = self::uniqueUsername( $displayName !== '' ? $displayName : $email );
            $newId = wp_insert_user( [
                'user_login'   => $username,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password( 24, true, true ),
                'display_name' => $displayName !== '' ? $displayName : $username,
            ] );
            if ( is_wp_error( $newId ) ) {
                $result['ok'] = false;
                $result['errors'][] = 'wp_insert_user: ' . $newId->get_error_message();
                return $result;
            }
            $user = get_user_by( 'id', (int) $newId );
            $result['created'] = true;
        }
        $wpUserId = (int) $user->ID;
        $result['wp_user_id'] = $wpUserId;

        // Backfill the stable key on an email match (or a fresh mint) so every later
        // pass keys on the id and the email is free to drift — the same backfill the
        // roster path does. Without it, an account matched once by email stays
        // matchable only by email, which is the defect this whole block exists to end.
        if ( $patreonId !== '' && (string) get_user_meta( $wpUserId, 'lgpo_patreon_user_id', true ) !== $patreonId ) {
            update_user_meta( $wpUserId, 'lgpo_patreon_user_id', sanitize_text_field( $patreonId ) );
        }

        // ---- 2. names ------------------------------------------------------
        $update = [ 'ID' => $wpUserId ];
        if ( $displayName !== '' && ( empty( $user->display_name ) || $user->display_name === $user->user_login ) ) {
            $update['display_name'] = $displayName;
        }
        if ( isset( $opts['first_name'] ) ) { $update['first_name'] = (string) $opts['first_name']; }
        if ( isset( $opts['last_name'] ) )  { $update['last_name']  = (string) $opts['last_name']; }
        if ( count( $update ) > 1 ) {
            wp_update_user( $update );
        }

        // A freshly created account may land on a RECYCLED wp_user_id that still
        // carries stale lg_role_sources / lg_patreon_members from a long-deleted
        // user (the G5 orphan family). Left in place, the Arbiter would merge
        // those into the new member's tier. Clear them so a new user starts clean.
        if ( $result['created'] ) {
            try {
                Db::pdo()->prepare( 'DELETE FROM lg_role_sources WHERE wp_user_id = ?' )->execute( [ $wpUserId ] );
                Db::pdo()->prepare( 'DELETE FROM lg_patreon_members WHERE wp_user_id = ?' )->execute( [ $wpUserId ] );
            } catch ( Throwable $_ ) {}
        }

        // ---- 3. tier role via Arbiter source pipeline ----------------------
        // Granted BEFORE caller meta is written: Arbiter's stripe-coexistence
        // guard skips a payment_source=stripe user that lacks a looth1 role, so
        // setting that meta first would block the initial grant.
        if ( array_key_exists( 'tier', $opts ) ) {
            $tier   = $opts['tier'];
            $source = isset( $opts['source'] ) ? (string) $opts['source'] : 'manual_admin';
            // looth1 is the sourceless starter tier — store it as a null source
            // opinion, matching lgpo_apply_role_via_arbiter / UserProvisioner.
            $reportTier = ( $tier === 'looth1' ) ? null : $tier;
            try {
                RoleSourceWriter::report( $wpUserId, $source, $reportTier );
                Arbiter::sync( $wpUserId );
            } catch ( Throwable $e ) {
                $result['errors'][] = 'role(' . $source . '): ' . $e->getMessage();
            }
        }

        // ---- 3b. caller meta (after the grant; see note above) -------------
        foreach ( (array) ( $opts['meta'] ?? [] ) as $k => $v ) {
            update_user_meta( $wpUserId, (string) $k, $v );
        }

        // ---- 4. profile-app identity + bridge (blocking, retried) ----------
        $result['profile_identity'] = self::ensureProfileIdentity(
            $wpUserId, $email, $displayName !== '' ? $displayName : $user->display_name, $result['errors']
        );

        // ---- 5. optional login (auth cookie + JWT mint) --------------------
        if ( ! empty( $opts['login'] ) ) {
            $result['logged_in'] = self::loginUser( $user, $result['errors'] );
        }

        $result['ok'] = $result['errors'] === [];
        return $result;
    }

    /** Find-or-make a unique WP login from a name or email local-part. */
    /**
     * The account carrying this Patreon id, or null.
     *
     * Prefers onboard.php's helper so there is ONE definition of "who owns this
     * Patreon id"; the inline query is the identical lookup for contexts where that
     * plugin file is not loaded (cron, wp-cli). Also checks the LEGACY
     * `patreon_user_id` meta, because accounts linked before the `lgpo_` prefix
     * exist and are exactly the old records most likely to be duplicated.
     */
    private static function userByPatreonId( string $patreonId ): ?\WP_User
    {
        if ( $patreonId === '' ) { return null; }
        if ( function_exists( 'lgpo_get_user_by_patreon_id' ) ) {
            $u = \lgpo_get_user_by_patreon_id( $patreonId );
            if ( $u instanceof \WP_User ) { return $u; }
        }
        foreach ( [ 'lgpo_patreon_user_id', 'patreon_user_id' ] as $key ) {
            $users = get_users( [ 'meta_key' => $key, 'meta_value' => $patreonId, 'number' => 1 ] );
            if ( ! empty( $users ) ) { return $users[0]; }
        }
        return null;
    }

    /**
     * Route a conflict to the same human-review queue onboard.php uses, and emit the
     * decoupled blocked signal lg-login-monitor listens for. Never throws: a failure
     * to *report* a conflict must not also destroy the refusal that caused it.
     */
    private static function flagForReview( string $patreonId, string $email, int $wpUserId, string $reason ): void
    {
        try {
            if ( function_exists( 'lgpo_add_pending' ) ) {
                \lgpo_add_pending( [
                    'patreon_user_id' => $patreonId,
                    'patreon_email'   => $email,
                    'patreon_name'    => '',
                    'tier_id'         => '',
                    'wp_user_id'      => $wpUserId,
                    'reason'          => $reason,
                ] );
            }
            do_action( 'lg_login_blocked', [
                'surface'         => 'lifecycle_provision',
                'reason'          => $reason,
                'patreon_user_id' => $patreonId,
                'patreon_email'   => $email,
                'wp_user_id'      => $wpUserId,
            ] );
            error_log( sprintf(
                'LGMS UserLifecycle: REFUSED provision (%s) patreon_id=%s email=%s wp=#%d — human review',
                $reason, $patreonId, $email, $wpUserId
            ) );
        } catch ( Throwable $_ ) {}
    }

    private static function uniqueUsername( string $seed ): string
    {
        $base = sanitize_user( strtolower( str_replace( ' ', '.', trim( $seed ) ) ), true );
        if ( strpos( $base, '@' ) !== false ) {
            $base = sanitize_user( strtolower( preg_replace( '/\+.*$/', '', strstr( $base, '@', true ) ) ), true );
        }
        if ( $base === '' ) {
            $base = 'looth-member';
        }
        $username = $base;
        $n = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $n;
            $n++;
        }
        return $username;
    }

    /**
     * POST the profile-app user-created hook, blocking, with a couple of
     * retries. Returns the decoded response or an error marker; pushes a
     * fail-loud line into $errors when the identity could not be created.
     *
     * @param array<int,string> $errors
     * @return array<string,mixed>
     */
    private static function ensureProfileIdentity( int $wpUserId, string $email, ?string $displayName, array &$errors ): array
    {
        $secret = (string) get_option( 'profile_hook_secret', '' );
        if ( $secret === '' ) {
            $errors[] = 'profile-identity: profile_hook_secret not set — bridge + /whoami identity NOT created.';
            return [ 'ok' => false, 'error' => 'no_secret' ];
        }
        if ( ! function_exists( 'lg_env' ) && is_readable( '/srv/lg-shared/lg-env.php' ) ) {
            require_once '/srv/lg-shared/lg-env.php';
        }
        $publicHost = defined( 'LG_PROFILE_APP_PUBLIC_HOST' )
            ? (string) LG_PROFILE_APP_PUBLIC_HOST
            : ( ( function_exists( 'lg_env' ) ? lg_env() : [] )['host'] ?? 'dev2.loothgroup.com' );

        $body = wp_json_encode( [
            'wp_user_id'   => $wpUserId,
            'email'        => $email,
            'display_name' => $displayName,
        ] );

        $lastErr = '';
        for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
            $resp = wp_remote_post( 'https://127.0.0.1/profile-api/v0/hooks/user-created', [
                'blocking'  => true,
                'timeout'   => 5,
                'sslverify' => false,
                'headers'   => [
                    'Host'          => $publicHost,
                    'Content-Type'  => 'application/json',
                    'X-Hook-Secret' => $secret,
                ],
                'body'      => $body,
            ] );
            if ( is_wp_error( $resp ) ) {
                $lastErr = $resp->get_error_message();
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code( $resp );
            $json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
            if ( $code >= 200 && $code < 300 && is_array( $json ) && ! empty( $json['ok'] ) ) {
                return $json;
            }
            $lastErr = is_array( $json ) && isset( $json['error'] ) ? (string) $json['error'] : "HTTP {$code}";
        }

        $errors[] = "profile-identity: {$lastErr} — bridge + /whoami identity NOT created (after 3 tries).";
        return [ 'ok' => false, 'error' => $lastErr ];
    }

    /**
     * Log a user in: current user + auth cookie + wp_login (which mints the
     * looth_id JWT in profile-auth.php). A bare auth cookie without wp_login
     * leaves the fast /whoami path anon (lifecycle G1).
     *
     * @param array<int,string> $errors
     */
    private static function loginUser( \WP_User $user, array &$errors ): bool
    {
        if ( headers_sent() ) {
            $errors[] = 'login: headers already sent, auth cookie not set.';
            return false;
        }
        wp_set_current_user( $user->ID, $user->user_login );
        wp_set_auth_cookie( $user->ID, true );
        do_action( 'wp_login', $user->user_login, $user );
        return true;
    }

    /**
     * Cancel Stripe + purge the lg_membership rows for a customer that has no
     * bridged WP user (orphan customer). Reuses the same membership ops as the
     * full teardown so there is no second cleanup path to drift. Used by the
     * legacy email-keyed MemberTools nuke when no WP account exists.
     *
     * @return array{customer_id:int, counts:array<string,int>, stripe_cancelled:array<int,string>, errors:array<int,string>}
     */
    public static function purgeOrphanCustomer( int $customerId, bool $dryRun = false ): array
    {
        $errors    = [];
        $email     = self::customerEmail( $customerId );
        $cancelled = self::cancelStripeSubs( $customerId, $dryRun, $errors );
        $counts    = self::runOps( self::membershipOps( $customerId, 0, $email ), $dryRun, $errors );
        return [
            'customer_id'      => $customerId,
            'counts'           => $counts,
            'stripe_cancelled' => $cancelled,
            'errors'           => $errors,
        ];
    }

    // -------------------------------------------------------------------------
    // Bridge resolution
    // -------------------------------------------------------------------------

    private static function resolveCustomerId( int $wpUserId, ?string $email ): ?int
    {
        try {
            $pdo  = Db::pdo();
            $stmt = $pdo->prepare( 'SELECT customer_id FROM wp_user_bridge WHERE wp_user_id = ? LIMIT 1' );
            $stmt->execute( [ $wpUserId ] );
            $cid = $stmt->fetchColumn();
            if ( $cid ) {
                return (int) $cid;
            }
            if ( $email !== null && $email !== '' ) {
                $stmt = $pdo->prepare( 'SELECT id FROM customers WHERE email = ? LIMIT 1' );
                $stmt->execute( [ $email ] );
                $cid = $stmt->fetchColumn();
                if ( $cid ) {
                    return (int) $cid;
                }
            }
        } catch ( Throwable $_ ) {}
        return null;
    }

    private static function customerEmail( int $customerId ): ?string
    {
        try {
            $stmt = Db::pdo()->prepare( 'SELECT email FROM customers WHERE id = ? LIMIT 1' );
            $stmt->execute( [ $customerId ] );
            $email = $stmt->fetchColumn();
            return $email !== false ? (string) $email : null;
        } catch ( Throwable $_ ) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Stripe
    // -------------------------------------------------------------------------

    /** @param array<int,string> $errors */
    private static function cancelStripeSubs( int $customerId, bool $dryRun, array &$errors ): array
    {
        $cancelled = [];
        try {
            $stmt = Db::pdo()->prepare(
                "SELECT stripe_subscription_id FROM subscriptions
                 WHERE customer_id = ? AND status IN ('active','trialing','past_due')"
            );
            $stmt->execute( [ $customerId ] );
            $ids = array_column( $stmt->fetchAll( \PDO::FETCH_ASSOC ), 'stripe_subscription_id' );
        } catch ( Throwable $e ) {
            $errors[] = 'stripe(list): ' . $e->getMessage();
            return [];
        }

        if ( $ids === [] ) {
            return [];
        }
        if ( $dryRun ) {
            // Report what we would cancel without touching Stripe.
            return array_values( array_filter( array_map( 'strval', $ids ) ) );
        }

        $client = new \LGMS\Stripe\Client();
        foreach ( $ids as $sid ) {
            if ( $sid === '' || $sid === null ) {
                continue;
            }
            try {
                $client->cancelSubscription( (string) $sid );
                $cancelled[] = (string) $sid;
            } catch ( Throwable $e ) {
                if ( stripos( $e->getMessage(), 'No such subscription' ) === false ) {
                    $errors[] = "stripe(cancel {$sid}): " . $e->getMessage();
                }
            }
        }
        return $cancelled;
    }

    // -------------------------------------------------------------------------
    // Authored content (WP posts + comments)
    // -------------------------------------------------------------------------

    /**
     * Posts (incl. bbPress forum topics/replies, which are wp_posts) are
     * handled by wp_delete_user in step 7: nuke deletes them, tombstone
     * reassigns post_author to the sentinel. Here we only deal with comments,
     * which wp_delete_user does NOT reassign, and gather counts.
     *
     * @return array<string,int>
     */
    private static function handleContent( int $wpUserId, string $mode, bool $dryRun, array &$result ): array
    {
        global $wpdb;
        $counts = [];

        $counts['wp_posts'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d", $wpUserId )
        );
        $counts['wp_comments'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d", $wpUserId )
        );

        if ( $dryRun ) {
            return $counts;
        }

        if ( $mode === self::MODE_NUKE ) {
            // Posts get deleted by wp_delete_user (no reassign). Comments are
            // not, so remove them explicitly.
            $wpdb->delete( $wpdb->comments, [ 'user_id' => $wpUserId ] );
        } else {
            // Tombstone: relabel + reassign the user's comments to the sentinel
            // so threads still render with "[deleted member]".
            $sentinel = self::sentinelId();
            $wpdb->update(
                $wpdb->comments,
                [ 'user_id' => $sentinel, 'comment_author' => self::SENTINEL_NAME ],
                [ 'user_id' => $wpUserId ]
            );
        }

        return $counts;
    }

    // -------------------------------------------------------------------------
    // lg_membership ops (symmetric count/delete, mirrors TestChecklist style)
    // -------------------------------------------------------------------------

    /**
     * Build the full list of lg_membership delete operations, each with a
     * symmetric COUNT query so dry-run and real teardown can never diverge.
     *
     * @return array<int,array{label:string,count:string,delete:string,params:array<int,mixed>}>
     */
    private static function membershipOps( ?int $cid, int $wpUserId, ?string $email ): array
    {
        $ops = [];

        if ( $cid !== null ) {
            $ops[] = self::op( 'gift_recipients_pending',
                'gift_recipients_pending WHERE stripe_checkout_session_id IN (SELECT stripe_session_id FROM gift_codes WHERE purchased_by = ?)',
                [ $cid ] );
            $ops[] = self::op( 'gift_codes',           'gift_codes WHERE purchased_by = ?',       [ $cid ] );
            $ops[] = self::op( 'admin_action_log',     'admin_action_log WHERE customer_id = ?',  [ $cid ] );
            $ops[] = self::op( 'audit_log',            "audit_log WHERE subject_type = 'customer' AND subject_id = ?", [ $cid ] );
            $ops[] = self::op( 'entitlements',         'entitlements WHERE customer_id = ?',      [ $cid ] );
            $ops[] = self::op( 'subscriptions',        'subscriptions WHERE customer_id = ?',     [ $cid ] );
            $ops[] = self::op( 'order_items',          'order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_id = ?)', [ $cid ] );
            $ops[] = self::op( 'orders',               'orders WHERE customer_id = ?',            [ $cid ] );
            $ops[] = self::op( 'wp_user_bridge',       'wp_user_bridge WHERE customer_id = ?',    [ $cid ] );
            $ops[] = self::op( 'customers',            'customers WHERE id = ?',                  [ $cid ] );
        }

        if ( $wpUserId > 0 ) {
            $ops[] = self::op( 'lg_role_sources',      'lg_role_sources WHERE wp_user_id = ?',    [ $wpUserId ] );
            $ops[] = self::op( 'lg_patreon_members',   'lg_patreon_members WHERE wp_user_id = ?', [ $wpUserId ] );
        }

        if ( $email !== null && $email !== '' ) {
            // Received (not purchased) gifts: blank the recipient binding so the
            // gift can be re-sent, rather than deleting the purchaser's row.
            $ops[] = [
                'label'  => 'gift_codes_received',
                'count'  => 'SELECT COUNT(*) FROM gift_codes WHERE recipient_email = ?',
                'delete' => 'UPDATE gift_codes SET recipient_email = NULL, recipient_name = NULL, gift_message = NULL, email_sent_at = NULL WHERE recipient_email = ?',
                'params' => [ $email ],
            ];
        }

        return $ops;
    }

    /** @return array{label:string,count:string,delete:string,params:array<int,mixed>} */
    private static function op( string $label, string $tableWhere, array $params ): array
    {
        return [
            'label'  => $label,
            'count'  => "SELECT COUNT(*) FROM {$tableWhere}",
            'delete' => "DELETE FROM {$tableWhere}",
            'params' => $params,
        ];
    }

    /**
     * @param array<int,array{label:string,count:string,delete:string,params:array<int,mixed>}> $ops
     * @param array<int,string> $errors
     * @return array<string,int>
     */
    private static function runOps( array $ops, bool $dryRun, array &$errors ): array
    {
        $counts = [];
        $pdo    = null;
        try {
            $pdo = Db::pdo();
        } catch ( Throwable $e ) {
            $errors[] = 'lg_membership(connect): ' . $e->getMessage();
            return $counts;
        }

        foreach ( $ops as $op ) {
            try {
                if ( $dryRun ) {
                    $stmt = $pdo->prepare( $op['count'] );
                    $stmt->execute( $op['params'] );
                    $counts[ $op['label'] ] = (int) $stmt->fetchColumn();
                } else {
                    $stmt = $pdo->prepare( $op['delete'] );
                    $stmt->execute( $op['params'] );
                    $counts[ $op['label'] ] = (int) $stmt->rowCount();
                }
            } catch ( Throwable $e ) {
                // A missing optional table (e.g. audit_log) is not fatal — note
                // it but keep going so one absent table can't strand the rest.
                $errors[] = "lg_membership({$op['label']}): " . $e->getMessage();
            }
        }
        return $counts;
    }

    // -------------------------------------------------------------------------
    // BuddyPress count (delete path reuses RestController::eraseBuddypressFootprint)
    // -------------------------------------------------------------------------

    private static function countBuddypress( int $wpUserId ): int
    {
        global $wpdb;
        $tables = [
            'bp_activity'             => 'user_id',
            'bp_friends'              => 'initiator_user_id',
            'bp_friends_initiated_by' => 'friend_user_id',
            'bp_groups_members'       => 'user_id',
            'bp_messages_recipients'  => 'user_id',
            'bp_notifications'        => 'user_id',
            'bp_xprofile_data'        => 'user_id',
            'bp_user_blogs'           => 'user_id',
            'bp_invitations'          => 'inviter_id',
        ];
        $total = 0;
        foreach ( $tables as $table => $col ) {
            $full = $wpdb->prefix . $table;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) {
                continue;
            }
            $total += (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$full} WHERE {$col} = %d", $wpUserId )
            );
        }
        return $total;
    }

    // -------------------------------------------------------------------------
    // profile-app erase (internal HTTP, mirrors PurgeNotifier's auth pattern)
    // -------------------------------------------------------------------------

    /**
     * @param array<int,string> $errors
     * @return array<string,mixed>
     */
    private static function callProfileAppErase( int $wpUserId, string $mode, bool $dryRun, array &$errors ): array
    {
        $secret = defined( 'LG_INTERNAL_SECRET' ) ? (string) LG_INTERNAL_SECRET : '';
        $base   = defined( 'LG_PROFILE_APP_URL' ) ? rtrim( (string) LG_PROFILE_APP_URL, '/' ) : '';
        if ( $secret === '' || $base === '' ) {
            $errors[] = 'profile-app: LG_INTERNAL_SECRET / LG_PROFILE_APP_URL not configured — identity NOT erased.';
            return [ 'ok' => false, 'error' => 'not_configured' ];
        }

        if ( ! function_exists( 'lg_env' ) && is_readable( '/srv/lg-shared/lg-env.php' ) ) {
            require_once '/srv/lg-shared/lg-env.php';
        }
        $publicHost = defined( 'LG_PROFILE_APP_PUBLIC_HOST' )
            ? (string) LG_PROFILE_APP_PUBLIC_HOST
            : ( ( function_exists( 'lg_env' ) ? lg_env() : [] )['host'] ?? 'dev2.loothgroup.com' );

        $resp = wp_remote_post( $base . self::ERASE_PATH, [
            'blocking'  => true,
            'timeout'   => 8,
            'sslverify' => false,
            'headers'   => [
                'Content-Type'       => 'application/json',
                'X-LG-Internal-Auth' => $secret,
                'Host'               => $publicHost,
            ],
            'body'      => wp_json_encode( [
                'wp_user_id' => $wpUserId,
                'mode'       => $mode,
                'dry_run'    => $dryRun,
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) {
            $errors[] = 'profile-app: ' . $resp->get_error_message() . ' — identity half did NOT complete.';
            return [ 'ok' => false, 'error' => $resp->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['ok'] ) ) {
            $msg = is_array( $body ) && isset( $body['error'] ) ? (string) $body['error'] : "HTTP {$code}";
            $errors[] = "profile-app: {$msg} — identity half did NOT complete.";
            return is_array( $body ) ? $body : [ 'ok' => false, 'error' => "HTTP {$code}" ];
        }

        return $body;
    }

    // -------------------------------------------------------------------------
    // discovery (Postgres) — config-gated; fail loud if unreachable
    // -------------------------------------------------------------------------

    /**
     * Discovery (`discovery.person`, `discovery.content_item`) lives in the
     * Postgres `looth` DB owned by the archive-poc/search stack. The poller
     * (WP, MySQL) has no standing connection there. We attempt cleanup ONLY
     * when an explicit DSN is wired via the `LG_DISCOVERY_DSN` constant; if it
     * is not set we surface a visible "skipped" marker rather than silently
     * orphaning the rows.
     *
     * NOTE (coordinator): discovery teardown may be better owned by the
     * profile-app erase endpoint (already a Postgres service). See the lane
     * report. Until decided, set LG_DISCOVERY_DSN to enable in-lane cleanup.
     *
     * @param array<int,string> $errors
     * @return array<string,int>
     */
    private static function handleDiscovery( int $wpUserId, ?string $email, string $mode, bool $dryRun, array &$errors ): array
    {
        $dsn = defined( 'LG_DISCOVERY_DSN' ) ? (string) LG_DISCOVERY_DSN : '';
        if ( $dsn === '' ) {
            $errors[] = 'discovery: LG_DISCOVERY_DSN not set — person/content_item rows NOT cleaned (out-of-lane; see report).';
            return [ 'discovery_person' => -1, 'discovery_content_item' => -1 ];
        }

        try {
            $pg = new \PDO( $dsn, null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
        } catch ( Throwable $e ) {
            $errors[] = 'discovery(connect): ' . $e->getMessage();
            return [ 'discovery_person' => -1, 'discovery_content_item' => -1 ];
        }

        $counts = [ 'discovery_person' => 0, 'discovery_content_item' => 0 ];
        try {
            // person keyed on wp_user_id; content_item.author_id is the same key.
            $cntPerson  = (int) $pg->query( "SELECT COUNT(*) FROM discovery.person WHERE wp_user_id = " . $wpUserId )->fetchColumn();
            $cntContent = (int) $pg->query( "SELECT COUNT(*) FROM discovery.content_item WHERE author_id = " . $wpUserId )->fetchColumn();
            $counts['discovery_person']       = $cntPerson;
            $counts['discovery_content_item'] = $cntContent;

            if ( ! $dryRun ) {
                if ( $mode === self::MODE_NUKE ) {
                    $pg->prepare( 'DELETE FROM discovery.content_item WHERE author_id = ?' )->execute( [ $wpUserId ] );
                    $pg->prepare( 'DELETE FROM discovery.person WHERE wp_user_id = ?' )->execute( [ $wpUserId ] );
                } else {
                    // Tombstone: blank the author so feed/search show no identity.
                    $pg->prepare( 'UPDATE discovery.content_item SET author_id = NULL WHERE author_id = ?' )->execute( [ $wpUserId ] );
                    $pg->prepare( 'DELETE FROM discovery.person WHERE wp_user_id = ?' )->execute( [ $wpUserId ] );
                }
            }
        } catch ( Throwable $e ) {
            $errors[] = 'discovery(op): ' . $e->getMessage();
        }
        return $counts;
    }

    // -------------------------------------------------------------------------
    // Sentinel "[deleted member]" user (tombstone reassign target)
    // -------------------------------------------------------------------------

    /** Create-on-first-use, then stable. Returns the sentinel WP user id. */
    public static function sentinelId(): int
    {
        $id = (int) get_option( self::SENTINEL_OPTION, 0 );
        if ( $id > 0 && get_userdata( $id ) ) {
            return $id;
        }

        $existing = get_user_by( 'login', self::SENTINEL_LOGIN );
        if ( $existing ) {
            update_option( self::SENTINEL_OPTION, (int) $existing->ID );
            return (int) $existing->ID;
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        $newId = wp_insert_user( [
            'user_login'   => self::SENTINEL_LOGIN,
            'user_pass'    => wp_generate_password( 32, true, true ),
            'display_name' => self::SENTINEL_NAME,
            'role'         => '',
        ] );
        if ( is_wp_error( $newId ) ) {
            throw new \RuntimeException( 'Could not create [deleted member] sentinel: ' . $newId->get_error_message() );
        }
        update_option( self::SENTINEL_OPTION, (int) $newId );
        return (int) $newId;
    }
}
