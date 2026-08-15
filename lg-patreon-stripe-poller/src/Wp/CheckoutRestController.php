<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\Db;
use LGMS\Log;
use LGMS\StripeLifecycle;
use Throwable;

/**
 * Checkout-session creation for the single Stripe membership (design doc
 * STRIPE-IDENTITY-AND-LIFECYCLE-DESIGN.md §3.2 — the step that makes
 * IdentityMatcher branch 2 the normal case).
 *
 *   POST /wp-json/lg-member-sync/v1/me/checkout-session
 *
 * Logged-in members only (cookie + REST nonce, the same auth as every
 * /me/* endpoint). The identity stamped into the session metadata comes
 * from the MEMBER'S OWN WP SESSION — never from the request body — so a
 * completed checkout carries an identity the member asserted, not one
 * guessed later from a string they typed into Stripe. The lifecycle ingest
 * absorbs it into customers.metadata, where IdentityMatcher reads it.
 *
 * SINGLE TIER, TWO CADENCES: the session sells the configured monthly or
 * yearly price for the one membership. The body may name a CADENCE and
 * nothing else — never a price id, which is still resolved server-side from
 * what an admin configured. Same tier either way; the choice is how often
 * they pay.
 *
 * A logged-OUT visitor never reaches this endpoint (REST auth fails).
 * That is design §3.2's other half: they must land on create-account /
 * sign-in BEFORE payment. The join/pricing page that fronts this endpoint
 * is charter item 2 and is deliberately NOT built here.
 *
 * Registered ONLY while lgms_stripe_lifecycle is ON — same OFF discipline
 * as the webhook route, gated by test-checkout-session-metadata.php §1.
 */
final class CheckoutRestController
{
    public const NAMESPACE = 'lg-member-sync/v1';
    public const ROUTE     = '/me/checkout-session';

    /**
     * Test seam: gates inject a session-creating client. Production
     * constructs LGMS\Stripe\Client (lgms_stripe_secret_key).
     *
     * @var null|callable():object
     */
    public static $clientFactory = null;

    public static function maybeRegister(): void
    {
        if ( ! StripeLifecycle::flagOn() ) {
            return;   // OFF: no route, no trace.
        }

        register_rest_route( self::NAMESPACE, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'createSession' ],
            'permission_callback' => [ RestController::class, 'authLoggedInUser' ],
        ] );
    }

    /**
     * @param \WP_REST_Request $req
     * @return \WP_REST_Response
     */
    public static function createSession( $req )
    {
        // ONE TIER, TWO CADENCES (Ian 2026-08-15: "We need a monthly and a
        // yearly price etc."). The body may choose HOW OFTEN they pay — and
        // nothing else.
        //
        // NOTE WHAT IS STILL REFUSED: the body cannot name a price id. It names
        // a cadence, which is looked up against the prices an admin actually
        // configured. A member who posts a price id gets nothing, because the
        // id never comes from them — that was the point of the original
        // "the request body chooses nothing" rule and it survives intact.
        $cadence = strtolower( trim( (string) ( $req->get_param( 'cadence' ) ?? '' ) ) );
        if ( $cadence === '' ) {
            $configured = \LGMS\StripePrice::configuredCadences();
            $cadence    = $configured[0] ?? 'month';   // one configured = no choice to make
        }
        if ( ! array_key_exists( $cadence, \LGMS\StripePrice::CADENCES ) ) {
            return new \WP_REST_Response( [ 'error' => 'Unknown billing cadence.' ], 400 );
        }

        $price = \LGMS\StripePrice::currentPriceId( $cadence );
        if ( $price === '' ) {
            return new \WP_REST_Response(
                [ 'error' => 'Stripe membership price not configured for that cadence.' ],
                503,
            );
        }

        $uid  = (int) get_current_user_id();
        $user = $uid > 0 ? get_user_by( 'id', $uid ) : false;
        if ( ! $user ) {
            return new \WP_REST_Response( [ 'error' => 'not logged in' ], 401 );
        }

        // The stamp. From the session, never the body (the body is ignored
        // entirely — gated). patreon_user_id rides along when the member has
        // a Patreon linkage, as the chain-3 backstop; the key is ABSENT when
        // they don't, never an empty string that would read as a claim.
        $meta = [ 'wp_user_id' => (string) $uid ];
        $pat  = trim( (string) get_user_meta( $uid, 'lgpo_patreon_user_id', true ) );
        if ( $pat !== '' ) {
            $meta['patreon_user_id'] = $pat;
        }

        $params = [
            'mode'       => 'subscription',
            'ui_mode'    => 'custom',
            'line_items' => [ [ 'price' => $price, 'quantity' => 1 ] ],
            'return_url' => home_url( '/manage-subscription/?session_id={CHECKOUT_SESSION_ID}' ),
            'metadata'   => $meta,
            // Stripe copies subscription_data.metadata onto the subscription
            // object, so the identity survives on BOTH the session (absorbed
            // by the completed-session ingest) and the subscription itself.
            'subscription_data' => [ 'metadata' => $meta ],
        ];

        // A bridged member checks out as their existing Stripe customer, so
        // Stripe never mints a second customer for them. Stripe rejects
        // customer + customer_email together — exactly one is sent.
        $stripeCustomerId = self::bridgedStripeCustomerId( $uid );
        if ( $stripeCustomerId !== null ) {
            $params['customer'] = $stripeCustomerId;
        } else {
            $params['customer_email'] = (string) $user->user_email;
        }

        try {
            $client  = self::$clientFactory !== null
                ? ( self::$clientFactory )()
                : new \LGMS\Stripe\Client();
            $session = $client->createCheckoutSession( $params );
        } catch ( Throwable $e ) {
            Log::line( sprintf(
                "[%s] checkout-session FAILED for wp #%d: %s\n",
                gmdate( 'c' ), $uid, $e->getMessage(),
            ) );
            return new \WP_REST_Response( [ 'error' => 'could not create checkout session' ], 502 );
        }

        Log::line( sprintf(
            "[%s] checkout-session created for wp #%d: %s (customer=%s)\n",
            gmdate( 'c' ), $uid, (string) ( $session->id ?? '?' ), $stripeCustomerId ?? '(new)',
        ) );

        return new \WP_REST_Response( [
            'client_secret' => (string) ( $session->client_secret ?? '' ),
            'session_id'    => (string) ( $session->id ?? '' ),
        ], 200 );
    }

    /** The member's existing Stripe customer id via the bridge, or null. */
    private static function bridgedStripeCustomerId( int $wpUserId ): ?string
    {
        $st = Db::pdo()->prepare(
            'SELECT c.stripe_customer_id
               FROM wp_user_bridge b
               JOIN customers c ON c.id = b.customer_id
              WHERE b.wp_user_id = ?
                AND c.deleted_at IS NULL
                AND c.stripe_customer_id IS NOT NULL
              LIMIT 1'
        );
        $st->execute( [ $wpUserId ] );
        $sid = $st->fetchColumn();
        return $sid !== false && $sid !== null && $sid !== '' ? (string) $sid : null;
    }
}
