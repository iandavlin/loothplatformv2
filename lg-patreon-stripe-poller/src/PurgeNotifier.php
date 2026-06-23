<?php

declare(strict_types=1);

namespace LGMS;

/**
 * Cache-invalidation subscriber for the strangler coordination.
 *
 * Listens to the do_action('looth_tier_changed', $uid, $old, $new, $prov)
 * fired by every role writer (Arbiter, UserProvisioner, admin grants) and
 * fires a fire-and-forget POST to profile-app's purge endpoint so the
 * /whoami cache entry for that user is invalidated.
 *
 * Transport: wp_remote_post with blocking=false + 1s timeout + no retry.
 * profile-app's endpoint is idempotent; if it's down, the worst case is
 * a stale /whoami value until the 30s consumer-side cache TTL expires.
 *
 * Burst-write safety: a poll tick can fire N action calls back-to-back
 * across distinct users; each becomes one non-blocking POST. PHP-FPM
 * worker pressure is bounded by the strict timeout — no retry queue,
 * no fallback persistence.
 */
final class PurgeNotifier
{
    private const ENDPOINT_PATH = '/profile-api/v0/internal/purge-whoami';

    public static function register(): void
    {
        add_action( 'looth_tier_changed', [ self::class, 'onTierChanged' ], 10, 4 );
    }

    public static function onTierChanged( int $wpUserId, ?string $oldRole, ?string $newRole, string $provenance ): void
    {
        $secret = defined( 'LG_INTERNAL_SECRET' ) ? (string) LG_INTERNAL_SECRET : '';
        $base   = defined( 'LG_PROFILE_APP_URL' ) ? rtrim( (string) LG_PROFILE_APP_URL, '/' ) : '';
        if ( $secret === '' || $base === '' || $wpUserId <= 0 ) {
            return;
        }

        // On dev LG_PROFILE_APP_URL points at https://127.0.0.1 to satisfy
        // profile-app's `allow 127.0.0.1; deny all` exempt. nginx selects
        // its server block via Host header / TLS SNI, so we must override
        // both — otherwise the request falls into the default server and
        // the cookie gate fires. Parse the public hostname from
        // LG_PROFILE_APP_PUBLIC_HOST, falling back to the box public host
        // from /etc/looth/env via lg_env() (dev2.loothgroup.com on dev,
        // loothgroup.com on live) so each box points at its own cert/host.
        if ( ! function_exists( 'lg_env' ) && is_readable( '/srv/lg-shared/lg-env.php' ) ) {
            require_once '/srv/lg-shared/lg-env.php';
        }
        $publicHost = defined( 'LG_PROFILE_APP_PUBLIC_HOST' )
            ? (string) LG_PROFILE_APP_PUBLIC_HOST
            : ( ( function_exists( 'lg_env' ) ? lg_env() : [] )['host'] ?? 'dev2.loothgroup.com' );

        wp_remote_post( $base . self::ENDPOINT_PATH, [
            'blocking'  => false,
            'timeout'   => 1,
            // sslverify=false because the internal channel is loopback +
            // shared-secret authed. The site cert is for the public host,
            // not 127.0.0.1; cert verification would fail with no added
            // security — hash_equals() on X-LG-Internal-Auth is the trust
            // boundary.
            'sslverify' => false,
            'headers'   => [
                'Content-Type'        => 'application/json',
                'X-LG-Internal-Auth'  => $secret,
                'Host'                => $publicHost,
            ],
            'body'      => wp_json_encode( [ 'wp_user_id' => $wpUserId ] ),
        ] );
    }
}
