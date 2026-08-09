<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\StripeLifecycle;

/**
 * The Stripe webhook front door (lifecycle ingest).
 *
 *   POST /wp-json/lg-member-sync/v1/stripe-webhook
 *
 * Registered ONLY while lgms_stripe_lifecycle is ON — flag absent/false
 * means the route does not exist and WP answers the exact rest_no_route
 * 404 it answers today (the byte-identical OFF no-op, gated by
 * deploy/remediation/test-stripe-lifecycle.php §1).
 *
 * permission_callback is __return_true because the SIGNATURE is the auth —
 * Stripe's model, and the one property of lg-stripe-billing's
 * WebhookController the audit marked unambiguously correct. Verification
 * happens in StripeLifecycle::ingest via \Stripe\Webhook::constructEvent;
 * a bad signature is a hard 400 before anything is read or written.
 *
 * DEPLOY SHAPE NOTE: this endpoint rides the existing WP REST route — no
 * new nginx location is needed on either box. What live DOES need before
 * the flag ever flips there: a Stripe dashboard webhook endpoint pointed
 * at this URL, and a Cloudflare exception if CF challenges Stripe's POSTs
 * (the same concern the audit filed for /billing/v1/webhook). Flagged in
 * the build report — not built here.
 */
final class WebhookRestController
{
    public const NAMESPACE = 'lg-member-sync/v1';
    public const ROUTE     = '/stripe-webhook';

    public static function maybeRegister(): void
    {
        if ( ! StripeLifecycle::flagOn() ) {
            return;   // OFF: no route, no trace.
        }

        register_rest_route( self::NAMESPACE, self::ROUTE, [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'handle' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * @param \WP_REST_Request $req
     * @return \WP_REST_Response
     */
    public static function handle( $req )
    {
        $res = StripeLifecycle::ingest(
            (string) $req->get_body(),
            (string) $req->get_header( 'stripe-signature' ),
        );
        return new \WP_REST_Response( $res['body'], $res['status'] );
    }
}
