<?php

declare(strict_types=1);

namespace LGMS;

use LGMS\Repos\CustomerRepo;
use LGMS\Repos\EntitlementRepo;
use LGMS\Wp\UserProvisioner;
use Throwable;

/**
 * Per-customer sync orchestrator. Idempotent.
 *
 *   1. Find/provision WP user (writes wp_user_bridge)
 *   2. Compute current active tier from entitlements
 *   3. Report (wp_user_id, 'stripe', tier) to lg_role_sources
 *   4. Run arbiter to write wp_capabilities
 *
 * Steps 3-4 are FENCED by the soft-launch cohort while the lifecycle flag is
 * on — this is the grant path a redeemed gift takes, and it is not the webhook.
 *
 * Called from:
 *   - Tick::run() pass 2 (cron)
 *   - REST endpoint /sync-customer (Slim post-checkout)
 *   - REST endpoint /run-now (admin)
 */
final class Sync
{
    /** @return array{ok:bool, message?:string, wp_user_id?:int, tier?:?string} */
    public static function customer(int $customerId): array
    {
        $customer = CustomerRepo::findById( $customerId );
        if ( ! $customer ) {
            return [ 'ok' => false, 'message' => "customer {$customerId} not found" ];
        }

        // ⚠️ THE CHECKOUT AUDIENCE FENCE IS INSIDE THIS CALL, not beside it
        // (#181). The soft-launch fence further down guards the GRANT, and it
        // has never guarded the account: by the time control reaches it a
        // stranger who paid already had a WordPress user, a bridge row and a
        // welcome. `UserProvisioner::findOrProvision` now refuses to provision
        // anyone outside the cohort and throws, which the catch below turns
        // into `provision failed` — so no opinion is reported and the Arbiter
        // never runs. Already-bridged members return before the fence and are
        // untouched in every state.
        try {
            $wpUserId = UserProvisioner::findOrProvision(
                $customerId,
                (string) $customer['email'],
                $customer['name'] !== null ? (string) $customer['name'] : null,
            );
        } catch ( Throwable $e ) {
            return [ 'ok' => false, 'message' => 'provision failed: ' . $e->getMessage() ];
        }

        $tier = EntitlementRepo::activeTier( $customerId );

        // SOFT-LAUNCH FENCE (docs/STRIPE-SOFT-LAUNCH-ALLOWLIST.md: "Every grant
        // path keeps the allowlist check — join, gift redemption, regional").
        //
        // This is the SECOND grant path and it was never fenced. StripeLifecycle
        // gates the webhook; everything below gates the ENTITLEMENT sweep, which
        // is how a redeemed gift becomes a role: the billing app writes an
        // entitlement, pings /sync-customer (registered unconditionally, shared
        // secret only), and this method reports a stripe opinion and runs the
        // Arbiter. The five-minute cron reaches the same place on its own via
        // Sync::all() — and lgms_stripe_frozen does NOT stop it, because that
        // switch guards Tick's Stripe POLL, a different pass. So without this,
        // a gift redeemed by somebody not on the list grants them the
        // membership anyway, within minutes, and the soft launch is not a soft
        // launch at all.
        //
        // OFF IS TODAY, EXACTLY. The fence only exists while the lifecycle flag
        // is on, which is the documented interlock (identity gate on ->
        // lifecycle on -> the list governs WHO). With the flag off — every box,
        // right now — this branch cannot be entered and the sweep behaves as it
        // always has.
        //
        // Out-of-cohort is FROZEN, not retracted, matching the webhook's
        // semantics: no opinion is written and the Arbiter is not run, so a
        // member pulled from the list keeps whatever they already had rather
        // than being half-demoted by a sweep.
        if ( StripeLifecycle::flagOn() && ! StripeLifecycle::inCohort( $wpUserId ) ) {
            if ( $tier !== null ) {
                // Only worth a line when something would ACTUALLY have been
                // granted — the sweep visits every customer every five minutes
                // and logging the empty-handed ones would bury the real ones.
                Log::line( sprintf(
                    "[%s] sync customer %d (wp #%d): SKIPPED %s — not in soft-launch cohort\n",
                    gmdate( 'c' ), $customerId, $wpUserId, $tier,
                ) );
            }
            return [
                'ok'         => true,
                'wp_user_id' => $wpUserId,
                'tier'       => null,
                'skipped'    => 'not in soft-launch cohort',
            ];
        }

        RoleSourceWriter::report( $wpUserId, 'stripe', $tier );
        $arb = Arbiter::sync( $wpUserId );

        return [
            'ok'           => true,
            'wp_user_id'   => $wpUserId,
            'tier'         => $tier,
            'arbiter'      => $arb,
        ];
    }

    /** Sweep every active customer. Called from cron. */
    public static function all(): array
    {
        $stmt    = Db::pdo()->query( 'SELECT id FROM customers WHERE deleted_at IS NULL' );
        $results = [];
        foreach ( $stmt->fetchAll( \PDO::FETCH_COLUMN ) as $cid ) {
            $results[ (int) $cid ] = self::customer( (int) $cid );
        }
        return $results;
    }
}
