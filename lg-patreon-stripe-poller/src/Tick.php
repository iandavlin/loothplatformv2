<?php

declare(strict_types=1);

namespace LGMS;

use LGMS\Repos\EntitlementRepo;
use LGMS\Stripe\Client as StripeClient;
use LGMS\Stripe\EventHandler as StripeEventHandler;
use LGMS\Stripe\Poller as StripePoller;
use Throwable;

/**
 * Cron entrypoint. Runs EVERY 5 MINUTES via WP cron on live — verified twice
 * against live's `cron` option (the schedule advanced 1786226777 -> 1786227077,
 * exactly +300s, which only happens when the event fires). The old "hourly"
 * here was wrong by 12x and is the sort of thing that makes an operator
 * mis-judge how fast a bad deploy spreads.
 * Also callable on demand via REST /run-now.
 *
 * Logging goes through LGMS\Log, never file_put_contents to the plugin dir:
 * that path is inside the serving checkout and the web user cannot write it,
 * so the previous `@`-silenced writes produced no log on live at all.
 *
 * Two passes:
 *   1. Pull new Stripe events → update lg_membership state.
 *   2. Sync lg_membership → WP (provisioning + role arbitration).
 *
 * Pass 2 also runs synchronously from Slim's /v1/return via the
 * /sync-customer REST endpoint, so on-checkout provisioning is instant.
 */
final class Tick
{
    public static function run(): void
    {
        // Non-blocking advisory lock: prevents WP-Cron + manual /run-now from
        // racing on the Stripe cursor and entitlement state. Auto-released
        // when the PDO connection ends (non-persistent), so a fatal mid-tick
        // can't leave the lock held.
        $pdo  = Db::pdo();
        $got  = $pdo->query( "SELECT GET_LOCK('lgms_tick_lock', 0)" )->fetchColumn();
        if ( (int) $got !== 1 ) {
            Log::line( sprintf(
                "[%s] tick SKIPPED: another tick is already running\n",
                gmdate( 'c' )
            ) );
            return;
        }

        Log::line( sprintf( "[%s] tick start\n", gmdate( 'c' ) ) );

        try {

        // Pass 1: Stripe poll
        //
        // Stripe R&D paused — resume here when the Stripe system restarts.
        // The Stripe alpha is decommissioned behind a real off-switch:
        // get_option('lgms_stripe_frozen') DEFAULTS TRUE (frozen). While frozen
        // the Stripe poll is skipped entirely — no StripeClient is constructed,
        // no Stripe API call is made, and EventHandler's wp_mail() can never
        // fire. Set the option to a falsey value to resume Stripe polling.
        if ( (bool) get_option( 'lgms_stripe_frozen', true ) ) {
            Log::line( sprintf(
                "[%s] stripe poll SKIPPED: lgms_stripe_frozen (Stripe R&D paused)\n",
                gmdate( 'c' )
            ) );
        } else {
            try {
                $client  = new StripeClient();
                $handler = new StripeEventHandler( $client );
                $poller  = new StripePoller( $client, $handler );
                $result  = $poller->poll();
                Log::line( sprintf(
                    "[%s] stripe poll: status=%s processed=%d cursor=%s\n",
                    gmdate( 'c' ),
                    $result['status'],
                    $result['processed'],
                    $result['cursor'] ?? '(none)',
                ) );
                foreach ( $result['log'] as $entry ) {
                    Log::line( "  {$entry}\n" );
                }
            } catch ( Throwable $e ) {
                Log::line( sprintf(
                    "[%s] stripe poll FAILED: %s\n",
                    gmdate( 'c' ),
                    $e->getMessage(),
                ) );
            }
        }

        // Pass 1.5: expiry sweep — revoke elapsed gift-code entitlements
        try {
            $expired = EntitlementRepo::sweepExpiredGiftEntitlements();
            if ( $expired !== [] ) {
                Log::line( sprintf(
                    "[%s] expiry sweep: revoked gift entitlements for customer_ids=%s\n",
                    gmdate( 'c' ),
                    implode( ',', $expired ),
                ) );
            }
        } catch ( Throwable $e ) {
            Log::line( sprintf(
                "[%s] expiry sweep FAILED: %s\n",
                gmdate( 'c' ),
                $e->getMessage(),
            ) );
        }

        // Pass 1.7: reconcile orphaned Stripe Checkout sessions.
        // Catches cases where the customer paid but their browser never
        // hit /v1/return (modal close, browser crash, network drop).
        // Slim is authoritative — we just trigger the sweep.
        //
        // CF bot-challenge bypass: Cloudflare intercepts wp_remote_post
        // when we call our own host from PHP-cURL (the request looks like
        // a server-side bot). Pin resolution to 127.0.0.1 to hit origin
        // nginx directly — same trick Slim's WpSync uses in reverse.
        try {
            $base   = (string) get_option( 'lgms_billing_base_url', home_url( '/billing' ) );
            $secret = (string) get_option( 'lgms_shared_secret', '' );
            if ( $secret === '' ) {
                Log::line( sprintf(
                    "[%s] reconcile-pending SKIPPED: no shared secret configured
",
                    gmdate( 'c' )
                ) );
            } else {
                $url   = rtrim( $base, '/' ) . '/v1/reconcile-pending';
                $parts = parse_url( $url );
                $host  = $parts['host'] ?? '';
                $ch    = curl_init( $url );
                curl_setopt_array( $ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'X-LGMS-Token: ' . $secret,
                    ],
                    CURLOPT_POSTFIELDS     => '{}',
                ] );
                if ( $host !== '' ) {
                    $scheme = $parts['scheme'] ?? 'https';
                    $port   = $parts['port'] ?? ( $scheme === 'https' ? 443 : 80 );
                    curl_setopt( $ch, CURLOPT_RESOLVE, [ "{$host}:{$port}:127.0.0.1" ] );
                }
                $body = (string) curl_exec( $ch );
                $err  = curl_error( $ch );
                $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                curl_close( $ch );
                if ( $err !== '' ) {
                    Log::line( sprintf(
                        "[%s] reconcile-pending FAILED: %s
",
                        gmdate( 'c' ),
                        $err
                    ) );
                } else {
                    Log::line( sprintf(
                        "[%s] reconcile-pending: HTTP %d %s
",
                        gmdate( 'c' ),
                        $code,
                        substr( $body, 0, 400 )
                    ) );
                }
            }
        } catch ( Throwable $e ) {
            Log::line( sprintf(
                "[%s] reconcile-pending threw: %s
",
                gmdate( 'c' ),
                $e->getMessage()
            ) );
        }

        // Pass 2: sync sweep
        try {
            $results = Sync::all();
            $ok      = 0;
            $errs    = 0;
            foreach ( $results as $cid => $r ) {
                if ( ! empty( $r['ok'] ) ) {
                    $ok++;
                } else {
                    $errs++;
                    Log::line( sprintf(
                        "  sync customer %d: %s\n",
                        $cid,
                        $r['message'] ?? 'unknown error',
                    ) );
                }
            }
            Log::line( sprintf(
                "[%s] sync sweep: ok=%d errors=%d\n",
                gmdate( 'c' ),
                $ok,
                $errs,
            ) );
        } catch ( Throwable $e ) {
            Log::line( sprintf(
                "[%s] sync sweep FAILED: %s\n",
                gmdate( 'c' ),
                $e->getMessage(),
            ) );
        }

        } finally {
            try {
                $pdo->query( "SELECT RELEASE_LOCK('lgms_tick_lock')" );
            } catch ( Throwable $e ) {
                // Connection may already be torn down; auto-release covers it.
            }
        }
    }
}
