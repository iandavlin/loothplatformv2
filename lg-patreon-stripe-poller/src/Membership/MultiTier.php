<?php

declare(strict_types=1);

namespace LGMS\Membership;

/**
 * Does the Stripe rail sell MORE THAN ONE tier?
 *
 * Ian, 2026-08-19: *"I've decided I want to be able to have multiple tiers."*
 *
 * That ruling REPLACES the one it was built under. From the 8/08 handoff,
 * quote-grade and still quoted verbatim in StripeLifecycle's docblock:
 *
 *   "move to ONE tier for the stripe memberships and have ALL tiered content
 *    open to the one tier through stripe."
 *
 * The old ruling was implemented faithfully — `StripeLifecycle::TIER` is the
 * literal string 'looth3' and no price map is consulted anywhere on that path.
 * So this is not a bug being fixed; it is a ruling being retired, and this flag
 * is the seam between the two so that retirement can be switched on, looked at,
 * and switched back off without a deploy.
 *
 * WHAT TURNING IT ON ACTUALLY CHANGES — one sentence, because it is the whole
 * feature: the tier a Stripe member receives stops being a constant and becomes
 * the tier attached to the price they paid.
 *
 * WHY THAT IS A GRANT CHANGE AND NOT A COSMETIC ONE. The constant can only ever
 * say looth3, so under the old ruling a member buying Looth LITE at $5 was
 * granted looth3 — Pro. Nobody was under-granted; everybody on the cheap tier
 * was OVER-granted. And it is not additive:
 * `EntitlementRepo::grantMembershipFromSubscription` revokes by source and
 * re-inserts whenever the ref changes, so the constant OVERWRITES a
 * correctly-resolved looth2 entitlement written by the Slim return path. Turning
 * this flag on is therefore the thing that makes the two Stripe code paths agree
 * with each other, which is the founding law of this domain: *"they both need to
 * work together to produce a logical result."*
 *
 * DEFAULT OFF, and OFF is a proven byte-identical no-op — the constant is
 * applied and no price lookup happens at all. Gate 76 §3 proves the absence
 * rather than asserting it, by running the OFF leg against a database with no
 * products or prices tables: any lookup is a hard SQL error, never a quiet pass.
 * (feedback-absence-assertion-needs-liveness: the OFF assertion sits beside the
 * ON legs, so it cannot go green by testing a dead code path.)
 *
 * A wp_option, NOT an FPM pool env, for the same two reasons as every other flag
 * in this family: the readers span three FPM pools plus WP-cron — and
 * `lg-wp-cron.service` carries no `Environment=`, so a pool variable would arm a
 * flag that then no-ops forever in the cron sweep, which is precisely where the
 * Arbiter runs. Dev2's pool files are also symlinks into the serving checkout,
 * so flipping one there dirties a tree that must only ever pull.
 *
 * This is deliberately a SECOND switch inside the already-off
 * `lgms_stripe_lifecycle`. They answer different questions — "is the webhook
 * lifecycle live?" and "does it sell more than one thing?" — and folding them
 * into one would mean multi-tier could only ever be tested by turning the whole
 * money path on at the same time.
 */
final class MultiTier
{
    /** The switch, and the only one. Absent or anything but a literal on-value reads OFF. */
    public const FLAG = 'lgms_multi_tier';

    /**
     * Strict on purpose, matching PatreonStanding::flagOn(). A plain `(bool)`
     * would read the strings 'no' and 'off' as ON, which is the wrong direction
     * to be wrong in for a flag that governs what a member is granted.
     */
    public static function flagOn(): bool
    {
        $v = get_option( self::FLAG, false );
        return $v === true || $v === 1 || $v === '1';
    }
}
