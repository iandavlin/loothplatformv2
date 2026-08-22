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
 *
 * REFUSES A PAYING PATRON (#150, flag `lgms_double_pay_block`). Ian, 8/19:
 * "We should disallow double payment source for the same user." With the flag
 * off this route is byte-identical to what it was; gate 75 §6 asserts both
 * states here, and asserts that a LAPSED patron still buys — they are exactly
 * who the switch path is for.
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
        // WHAT THE BODY MAY CHOOSE: which tier, and how often they pay.
        //
        // Cadence arrived first (Ian 2026-08-15: "We need a monthly and a yearly
        // price etc."); tier followed (Ian 2026-08-19: "I've decided I want to
        // be able to have multiple tiers"), behind LGMS\Membership\MultiTier.
        //
        // NOTE WHAT IS STILL REFUSED, because widening this body is exactly the
        // change that could quietly undo it: the body cannot name a PRICE ID. It
        // names a tier and a cadence, both looked up against the prices an admin
        // actually configured. A member who posts a price id gets nothing —
        // the id never comes from them. That was the point of the original
        // "the request body chooses nothing" rule, and adding two named,
        // validated, looked-up choices does not weaken it: nothing the member
        // sends ever reaches Stripe, it only selects among what Ian has priced.
        // Gate 76 §7 asserts the price-id-in-the-body case directly.
        $cadence = strtolower( trim( (string) ( $req->get_param( 'cadence' ) ?? '' ) ) );

        // FLAG OFF ⇒ the tier parameter does not exist. Not "is ignored later":
        // it is never read, so the OFF path is the single-tier code exactly as
        // it was, and a body carrying a tier behaves identically to one without.
        $tier = null;
        if ( \LGMS\Membership\MultiTier::flagOn() ) {
            $raw = strtolower( trim( (string) ( $req->get_param( 'tier' ) ?? '' ) ) );
            if ( $raw !== '' ) {
                // Refused, never guessed. An unknown tier that fell through to
                // the default would sell the Pro price to somebody who asked
                // for Lite — a wrong charge, arriving as a success.
                if ( ! in_array( $raw, \LGMS\StripePrice::tiers(), true ) ) {
                    return new \WP_REST_Response( [ 'error' => 'Unknown membership tier.' ], 400 );
                }
                $tier = $raw;
            }
        }

        if ( $cadence === '' ) {
            $configured = \LGMS\StripePrice::configuredCadences( $tier );
            $cadence    = $configured[0] ?? 'month';   // one configured = no choice to make
        }
        if ( ! array_key_exists( $cadence, \LGMS\StripePrice::CADENCES ) ) {
            return new \WP_REST_Response( [ 'error' => 'Unknown billing cadence.' ], 400 );
        }

        $price = \LGMS\StripePrice::currentPriceId( $cadence, $tier );
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

        // WHO MAY BUY (#181, option `lgms_checkout_audience`, default
        // `allowlist`). Being signed in is not the same as being invited: this
        // route has always minted a subscription session for ANY logged-in
        // member, which during a soft launch is every one of the ~1,900
        // accounts on the box.
        //
        // Keyed on the member's SESSION user id, never on anything in the body
        // — the id cannot be typed in, so this door has none of the
        // email-substitution slack the Slim door has to live with.
        //
        // ⚠️ NO ADMIN BYPASS. An administrator sails through a gate he is
        // trying to test and concludes it works; this repo has paid for that
        // shape three times. CheckoutAudience asks the cohort and only the
        // cohort — keeper's ruling (b), 2026-08-21.
        //
        // Placed AFTER the session resolves (there is no audience question
        // about a caller with no identity — that is a 401) and BEFORE any
        // Stripe call, so a refusal costs nothing and creates nothing.
        if ( ! \LGMS\Membership\CheckoutAudience::allowsUser( $uid ) ) {
            \LGMS\Membership\CheckoutAudience::logRefusal(
                \LGMS\Membership\CheckoutAudience::D_WP_CHECKOUT,
                (string) $user->user_email,
                $uid,
                'signed in, not in the soft-launch cohort',
            );
            return new \WP_REST_Response( [
                'error'    => \LGMS\Membership\CheckoutAudience::refusalMessage(),
                'audience' => \LGMS\Membership\CheckoutAudience::state(),
            ], 403 );
        }

        // ONE PAYMENT SOURCE PER MEMBER (Ian 2026-08-19, #150): a member whose
        // membership is already being charged on Patreon does not get to buy it
        // a second time here. Behind `lgms_double_pay_block`; with the flag off
        // this block is not entered at all and the route behaves exactly as it
        // did before. Keyed on real Patreon standing, never on the one-slot
        // `payment_source` — see LGMS\Membership\PatreonStanding.
        //
        // This is the door the plan did not know about. The Slim API and
        // /lgjoin/ were the two named in #150; this one creates a subscription
        // session for a logged-in member and was just as Patreon-blind.
        if ( \LGMS\Membership\PatreonStanding::flagOn() ) {
            $standing = \LGMS\Membership\PatreonStanding::forUser( $uid );
            if ( ! empty( $standing['active'] ) ) {
                Log::line( sprintf(
                    "[%s] checkout-session REFUSED for wp #%d: already paying via Patreon (%s)\n",
                    gmdate( 'c' ), $uid, (string) $standing['reason'],
                ) );
                /* THE LINKED PATREON ADDRESS (Ian 2026-08-22): "its critical to
                   add to any double pay or switch surface the email associated
                   with their patreon account and that that is the email to use
                   when adjusting thier membership."

                   ⚠️ IT IS SAFE ON THIS DOOR AND NOT ON THE SLIM ONE, and the
                   difference is who is asking. This route's permission_callback
                   is authLoggedInUser: the caller IS the member, proven by a
                   session and a nonce, and `$uid` comes from that session and
                   never from the body — so the address can only ever be their
                   own. `POST /billing/v1/checkout` takes an arbitrary email
                   from an unauthenticated stranger, which is why it keeps the
                   plain sentence and why the poller's REST route hands that app
                   no address to add. Keeper's rail, 2026-08-22.

                   Empty when there is no linked address; nothing is invented. */
                $linked = \LGMS\Membership\PatreonStanding::linkedEmailSentence( $standing );

                return new \WP_REST_Response( [
                    'error'          => \LGMS\Membership\PatreonStanding::refusalMessage( $standing )
                                        . ( $linked !== '' ? ' ' . $linked : '' ),
                    'patreon_active' => true,
                    'manage_url'     => \LGMS\Membership\PatreonStanding::manageUrl(),
                ], 409 );
            }
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
