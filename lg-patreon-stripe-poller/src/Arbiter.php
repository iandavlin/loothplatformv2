<?php

declare(strict_types=1);

namespace LGMS;

/**
 * Sole writer of wp_capabilities for looth1..4 tiers.
 *
 * Reads all source rows for a WP user, computes the winning tier
 * (highest across active sources), and writes wp_capabilities
 * preserving every non-tier role (administrator, bbp_participant, etc.).
 *
 * looth4 (comp/staff) users are protected — with ONE exception, added by #183:
 * a comp whose timer has run out is no longer a protected comp. The decision is
 * CompExpiry's (flag, enforcement cutover, timezone); the write is still only
 * ever this file's. See the looth4 block in sync() for the split and why it
 * matters.
 */
final class Arbiter
{
    private const TIER_ROLES = [ 'looth1', 'looth2', 'looth3', 'looth4' ];

    public static function sync(int $wpUserId): array
    {
        $user = get_user_by( 'id', $wpUserId );
        if ( ! $user ) {
            return [ 'ok' => false, 'reason' => 'no such WP user' ];
        }

        // Protected — never downgrade a looth4 (comp/manual) user from a source.
        // But STILL enforce single-tier: a looth4 holder must not also carry a
        // lower looth1..3 role. The looth4 comp-timer historically stripped
        // looth4 and left looth1 behind (and a later Patreon sub then added
        // looth3 on top) — the root of the double-role bug. De-dupe down to
        // looth4 here, then leave looth4 itself untouched.
        //
        // ── #183: PROTECTED MEANS *UNEXPIRED* ───────────────────────────────
        // Ian, 2026-08-21: "comp timers need to work." The one thing that
        // changes here is that this early-return now asks whether the comp is
        // still running. CompExpiry owns that decision and every fence in it —
        // the flag, the enforcement cutover holding the already-overdue, and
        // the timezone. This file stays the ONLY writer of wp_capabilities:
        // the sweep decides WHO has lapsed, and the code below decides WHAT
        // they become. That is exactly the split the old lg-looth4-expiry
        // plugin did not have, and the comment above is the bill for it.
        $compExpired = false;
        if ( in_array( 'looth4', $user->roles, true ) ) {
            $lapsed = \LGMS\Membership\CompExpiry::shouldExpire( $wpUserId );

            // ⚠️ A LAPSED COMP WHO LOOKS LIKE A PAYER IS HELD, NOT DEMOTED.
            // The guard below this block exists because a member who owns
            // their tier through Stripe but carries no lg_role_sources row
            // would arbitrate to null and be silently downgraded. An expired
            // comp in that same shape would be downgraded by US, which is the
            // one outcome decision #2 forbids: an expiry returns a member to
            // their real tier, it never flattens a payer. So we hold, and say
            // so out loud rather than skipping quietly.
            if ( $lapsed
                 && get_user_meta( $wpUserId, 'payment_source', true ) === 'stripe'
                 && RoleSourceWriter::readAllForUser( $wpUserId ) === [] ) {
                return [
                    'ok'     => true,
                    'reason' => 'comp timer lapsed, but payment_source=stripe with NO source row — HELD, never demoted',
                ];
            }

            if ( $lapsed ) {
                // The comp comes off, and then this member is arbitrated like
                // anybody else. No special demotion path, no flat looth1.
                $user->remove_role( 'looth4' );
                $compExpired = true;
            } else {
                $deduped = false;
                foreach ( [ 'looth1', 'looth2', 'looth3' ] as $lower ) {
                    if ( in_array( $lower, $user->roles, true ) ) {
                        $user->remove_role( $lower );
                        $deduped = true;
                    }
                }
                return [ 'ok' => true, 'reason' => $deduped ? 'looth4 protected, deduped lower tiers' : 'looth4 protected, skipped' ];
            }
        }

        // Stripe-source coexistence guard (mirrors LGPO's existing skip):
        // a user with payment_source=stripe and a non-looth1 tier role
        // owns their own role via the Stripe pipeline. If they don't have
        // a current lg_role_sources.stripe row (legacy users, pre-source-
        // writer-system carryover, or replay edge cases), the Arbiter
        // would otherwise compute winning_tier=null from empty sources
        // and silently downgrade them. Skip instead.
        //
        // ⚠️ #183: `! $compExpired` is load-bearing. A comp whose role we just
        // removed holds no tier at all for a moment, so `empty(intersect
        // looth1)` is TRUE for them and this guard would return early and
        // leave them with NO looth role whatsoever — worse than any demotion.
        // The genuinely ambiguous version of that case was already held above,
        // before the role came off.
        if ( ! $compExpired
             && get_user_meta( $wpUserId, 'payment_source', true ) === 'stripe'
             && empty( array_intersect( $user->roles, [ 'looth1' ] ) ) ) {
            return [ 'ok' => true, 'reason' => 'stripe-source w/o source row, skipped' ];
        }

        // The transition is reported from where the member STARTED. A comp that
        // just lapsed started at looth4, and reading the roles back now would
        // say looth1-or-nothing — so looth_tier_changed would fire with the
        // wrong `from`, and profile-app's cache would purge against a tier the
        // member never held.
        $oldTier = $compExpired ? 'looth4' : self::currentTier( (array) $user->roles );
        $sources = RoleSourceWriter::readAllForUser( $wpUserId );
        $winning = self::computeWinningTier( $sources );

        // THE FLOOR, and it is the whole of "what an expired comp becomes".
        // Arbitration answers first: a comp who also pays on Patreon or Stripe
        // lands on their real tier, because their source rows say so and this
        // code never looked at the comp at all. Only when there is no paying
        // opinion anywhere does the floor apply — looth1, the starter tier,
        // which is also what the old lg-looth4-expiry plugin documented
        // ("Expired users are demoted to looth1"). Never no tier at all: a
        // member stripped to nothing is not a lapsed comp, it is a broken
        // account.
        if ( $compExpired && $winning === null ) {
            $winning = 'looth1';
        }

        // SINGLE-TIER ENFORCEMENT (de-dupe). A user must never hold two or more
        // looth1..4 roles at once. Remove every tier role that isn't the winner.
        //
        // The fix for the double-role bug is that looth1 is NO LONGER skipped
        // here when a real winner exists: previously looth1 was treated as a
        // sticky starter tier and left in place, which let a paid tier (looth3)
        // coexist with looth1. All four looth roles carry IDENTICAL caps —
        // including manage_gift_codes (Plugin.php grants GIFT_CAP to every tier)
        // — so stripping looth1 off an upgraded user loses no capability; the
        // old "would strip gift-management" concern no longer holds.
        //
        // Starter-tier protection is preserved for the no-source case: when
        // $winning === null the user has ZERO role-source rows (e.g. a
        // standalone gift buyer who only ever had the UserProvisioner-granted
        // looth1), so we keep looth1 and only shed stale paid roles — never
        // strip the user down to no tier at all.
        foreach ( self::TIER_ROLES as $role ) {
            if ( $role === $winning ) {
                continue;
            }
            if ( $role === 'looth1' && $winning === null ) {
                continue;
            }
            if ( in_array( $role, $user->roles, true ) ) {
                $user->remove_role( $role );
            }
        }

        // Add the winning role if not already present.
        if ( $winning !== null && ! in_array( $winning, $user->roles, true ) ) {
            $user->add_role( $winning );
        }

        // BB Profile Type sync: looth1-or-nothing → 'starter' (hidden from
        // member directory / network search via the BB type's visibility
        // flags). looth2+ → clear so they reappear. Mirrors role transitions
        // so directory visibility tracks paid status without a custom filter.
        if ( function_exists( 'bp_set_member_type' ) ) {
            if ( $winning === null || $winning === 'looth1' ) {
                bp_set_member_type( $wpUserId, 'starter' );
            } else {
                bp_set_member_type( $wpUserId, '' );
            }
        }

        // Welcome trigger: stamp a one-shot user meta whenever the user is
        // upgraded INTO a paid tier (looth2+) from a lower/null state.
        // The wp_footer modal hook reads this meta on the next page load
        // and shows a "your membership is active" celebration. The flag
        // is consumed (deleted) by the dismiss-welcome REST endpoint.
        // Idempotent: re-running Arbiter on a stable looth2 user does NOT
        // re-set the flag (oldTier === winning).
        // FIRST ACTIVATION (backlog: the welcome one-shot, 2026-08-16).
        //
        // isUpgradeToPaid() asks whether a TRANSITION happened, which is really a
        // question about role ORDERING: the Patreon path creates the account with
        // the paid role already on it, so $oldTier already equals $winning and that
        // rail can never present a transition. The email-first minter creates with
        // no role, so it can. Same money, same tier, different product by rail.
        //
        // So we additionally ask a question about the MEMBER: have they ever been
        // marked activated? That is rail-agnostic by construction.
        //
        // FLAG OFF ⇒ nothing below runs and NOTHING is written — not even the
        // marker. That is what keeps the off state a byte-identical no-op rather
        // than "the old behaviour plus a harmless write".
        $welcomeCfg   = self::welcomeActivationCfg();
        $upgrade      = self::isUpgradeToPaid( $oldTier, $winning );
        $shouldWelcome = $upgrade;

        if ( ! empty( $welcomeCfg['enabled'] ) ) {
            $isPaid = in_array( $winning, [ 'looth2', 'looth3', 'looth4' ], true );
            $marker = (string) get_user_meta( $wpUserId, '_lg_membership_activated_at', true );
            $firstActivation = ( $isPaid && $marker === '' );

            // PROVENANCE IS PART OF THE VALUE, because one write serves two very
            // different cases. A member activating NOW gets a true activation date.
            // One of the 1,225 who activated months or years ago and is merely being
            // SWEPT past the fence must NOT get today's date under a field called
            // _lg_membership_activated_at — that field would then be confidently
            // wrong for every one of them, and WHICH members it lied about would
            // depend on whether anyone remembered to run the backfill first, which
            // is exactly the kind of dependency the fence exists to remove.
            //
            // Three provenances, all non-empty, so the first-activation test itself
            // is unchanged: a bare ISO date (observed live), 'pre-cutover:' (swept),
            // and 'backfill:' (tools/welcome-activation-backfill.php).
            $afterCutover = $firstActivation && self::registeredAfterCutover( $user, $welcomeCfg );

            if ( $firstActivation ) {
                update_user_meta(
                    $wpUserId,
                    '_lg_membership_activated_at',
                    ( $afterCutover ? '' : 'pre-cutover:' ) . gmdate( 'c' )
                );
            }

            // The fence guards FIRST ACTIVATION ONLY. A genuine looth2 → looth3
            // upgrade is welcomed today and stays welcomed: fencing it would remove
            // a working behaviour while fixing a broken one.
            $shouldWelcome = $upgrade || $afterCutover;
        }

        if ( $shouldWelcome ) {
            update_user_meta( $wpUserId, '_lg_pending_welcome', (string) $winning );
            // Fire the welcome email once. WelcomeMailer is idempotent —
            // it tracks delivery via _lg_welcome_email_sent_at user meta
            // and silently bails on repeat calls. The modal handles
            // returning users; this email handles users who don't return
            // on their own (e.g. because they backed out of Stripe and
            // the cron sweep / webhook provisioned silently).
            \LGMS\Wp\WelcomeMailer::sendIfNeeded( $wpUserId, (string) $winning );
        }

        // Cache-invalidation hook for profile-app + any other subscriber.
        // Fires only on actual tier transitions, not no-ops. Provenance
        // mirrors what the /user-context endpoint would compute, so a
        // subscriber re-fetching after the purge gets the same value.
        if ( $oldTier !== $winning ) {
            $provenance = \LGMS\Wp\InternalRestController::deriveProvenance( $winning, $sources );
            do_action( 'looth_tier_changed', $wpUserId, $oldTier, $winning, $provenance );
        }

        return [ 'ok' => true, 'winning_tier' => $winning, 'sources' => $sources, 'old_tier' => $oldTier ];
    }

    /**
     * Highest looth* role currently on the user (lookup, no DB write).
     * Returns null if none of the tier roles are present.
     */
    private static function currentTier( array $roles ): ?string
    {
        $best = null;
        foreach ( self::TIER_ROLES as $role ) {
            if ( in_array( $role, $roles, true ) ) {
                if ( $best === null || strcmp( $role, $best ) > 0 ) {
                    $best = $role;
                }
            }
        }
        return $best;
    }

    /**
     * The tracked first-activation config, read through __DIR__.
     *
     * NOT an env var: the arbiter runs inside cron sweeps and lg-wp-cron.service
     * carries no Environment=, so a pool variable would arm a flag that then
     * no-ops forever in the context that matters most. __DIR__ resolves through
     * the mu-plugin symlink into the serving checkout.
     *
     * Unreadable or malformed FAILS CLOSED — the failure mode of a broken fence
     * is a mass mail, so "we could not read the config" must mean "do nothing".
     */
    private static function welcomeActivationCfg(): array
    {
        static $cfg = null;
        if ( $cfg !== null ) {
            return $cfg;
        }
        $cfg  = [ 'enabled' => false, 'cutover' => '' ];
        $file = __DIR__ . '/../../platform/config/welcome-activation.php';
        if ( is_readable( $file ) ) {
            $loaded = require $file;
            if ( is_array( $loaded ) ) {
                $cfg = $loaded + $cfg;
            }
        }
        return $cfg;
    }

    /**
     * Was this account registered at or after the cutover?
     *
     * This is the guard that does not depend on anybody remembering to run a
     * backfill. Every one of the 1,225 members who already hold a paid tier
     * registered before any cutover we would set, so arming the flag cannot mail
     * an existing member even with no backfill at all.
     *
     * Returns FALSE for an empty or unparseable cutover, and false when the
     * registration date is missing — fail closed, always.
     */
    private static function registeredAfterCutover( $user, array $cfg ): bool
    {
        $cutover = trim( (string) ( $cfg['cutover'] ?? '' ) );
        if ( $cutover === '' ) {
            return false;
        }
        $cutTs = strtotime( $cutover . ' 00:00:00 UTC' );
        if ( $cutTs === false ) {
            return false;
        }
        $registered = trim( (string) ( $user->user_registered ?? '' ) );
        if ( $registered === '' ) {
            return false;
        }
        // wp_users.user_registered is stored UTC.
        $regTs = strtotime( $registered . ' UTC' );
        if ( $regTs === false ) {
            return false;
        }
        return $regTs >= $cutTs;
    }

    /**
     * True when the transition $old → $new represents a real upgrade INTO
     * a paid tier. looth1 is the starter (free) tier and does not trigger
     * the welcome modal; looth2/3/4 are paid and do.
     */
    private static function isUpgradeToPaid( ?string $old, ?string $new ): bool
    {
        if ( $new === null || $new === 'looth1' ) {
            return false;
        }
        if ( ! in_array( $new, [ 'looth2', 'looth3', 'looth4' ], true ) ) {
            return false;
        }
        if ( $old === null ) {
            return true;  // first-ever tier assignment, paid
        }
        return strcmp( $new, $old ) > 0;
    }

    /**
     * Highest of looth1..4 across sources reporting non-null tiers.
     * If we have any rows but none report a tier, fall back to looth1
     * (lapsed). If no rows at all, return null (don't touch the user).
     */
    private static function computeWinningTier(array $sources): ?string
    {
        if ( $sources === [] ) {
            return null;
        }
        $best = null;
        foreach ( $sources as $tier ) {
            if ( $tier === null ) {
                continue;
            }
            if ( ! in_array( $tier, self::TIER_ROLES, true ) ) {
                continue;
            }
            if ( $best === null || strcmp( $tier, $best ) > 0 ) {
                $best = $tier;
            }
        }
        return $best ?? 'looth1';
    }
}
