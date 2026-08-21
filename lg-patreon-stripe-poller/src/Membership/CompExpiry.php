<?php

declare(strict_types=1);

namespace LGMS\Membership;

use LGMS\Arbiter;
use LGMS\Log;
use Throwable;

/**
 * Comp timers, re-armed (#183). Ian, 2026-08-21: *"comp timers need to work."*
 *
 * They have not worked for at least 41 days, and the cause is an absence rather
 * than a bug: `lg-looth4-expiry 1.0.0` belonged to the pre-cutover platform and
 * did not survive the cut. Measured both sides on 2026-08-21 — keeper on live's
 * filesystem (no file under wp-content, absent from `active_plugins`,
 * `recently_activated` empty), this lane on the database (no cron event in the
 * 13,182-byte `cron` option, no ACF field, no snippet, no option naming the
 * key). The two dates on live lapsed in July with nothing watching.
 *
 * ── THIS CLASS DECIDES; IT DOES NOT WRITE ───────────────────────────────────
 * ⚠️ NOTHING HERE CALLS add_role() OR remove_role(). `Arbiter::sync` is the
 * only writer of `wp_capabilities` — lane 181 proved it, gate 86 §I asserts it,
 * and gate 89 asserts this file contains neither call. The sweep works out WHO
 * has lapsed and hands each member to the Arbiter; the Arbiter works out WHAT
 * they become.
 *
 * That separation is the whole reason the old plugin is not being resurrected
 * even if it were recovered. It wrote roles directly, and the Arbiter's own
 * looth4 comment still records the bill for that: the old comp timer "stripped
 * looth4 and left looth1 behind (and a later Patreon sub then added looth3 on
 * top) — the root of the double-role bug". A second writer is how two systems
 * end up disagreeing about one member's tier.
 *
 * ── WHY A SWEEP AND NOT JUST THE ARBITER ────────────────────────────────────
 * `Arbiter::sync` only ever runs for members something has an opinion about — a
 * Patreon sweep, a Stripe webhook, a provisioning call. A pure comp holder has
 * no payment source at all, so nothing would ever visit them and the timer
 * would never fire. Same shape as the defect `RetractionSweep` exists for: 41
 * orphaned opinions survived because nothing revisited their subjects. Iterate
 * the timers, not the payers.
 *
 * ── TWO FENCES, BOTH FAILING CLOSED ─────────────────────────────────────────
 * `enabled` (default false) and `effective_from` (default empty). An
 * unreadable config, a malformed config, an empty cutover or an unparseable one
 * all mean the same thing: demote nobody. `enabled => true` with an empty
 * cutover is therefore a real detect-and-report mode — the sweep runs, journals
 * and logs every lapsed comp, and touches no one — with no third knob to get
 * wrong.
 */
final class CompExpiry
{
    /** Journalled findings, autoload off. Operator-readable, never authoritative. */
    public const FINDINGS = 'lgms_comp_expiry_findings';

    /* ── states a comp holder can be in, as the dash and the log name them ── */
    public const STATE_NO_TIMER = 'no_timer';   // holds looth4, no expiry set — 12 of 14 on live
    public const STATE_RUNNING  = 'running';    // timer set, still in the future
    public const STATE_HELD     = 'held';       // timer ran out BEFORE the cutover — protected
    public const STATE_DUE      = 'due';        // timer ran out at/after the cutover — will be enforced
    public const STATE_DISARMED = 'disarmed';   // timer ran out, but enforcement is off entirely
    public const STATE_LAPSED   = 'lapsed';     // carries a timer but no longer holds looth4 (already enforced, or comp removed by hand)

    /**
     * TEST SEAM, and the only way this class's config can be varied in-process.
     *
     * Production NEVER assigns this — gate 89 §A asserts that no file outside
     * tools/gates/ writes to it, so the guarantee is mechanical rather than a
     * convention somebody has to remember. It exists because the config is a
     * tracked FILE (see below) and a file cannot be varied inside one PHP
     * process, while the states that matter here — off, on-with-no-cutover,
     * on-with-a-cutover — must all be exercised in one run to be worth
     * anything. There is deliberately no env or $_SERVER path: this flag takes
     * access away from real people, and a request-settable override on that is
     * a hole, not a convenience.
     *
     * @var array<string,mixed>|null
     */
    public static ?array $override = null;

    /**
     * The tracked config, read through __DIR__, with a per-box overlay.
     *
     * NOT an env var: the sweep runs inside the 5-minute WP-Cron tick and
     * lg-wp-cron.service carries no Environment=, so a pool variable would arm
     * a flag that then no-ops forever in the context that matters most.
     * __DIR__ resolves through the mu-plugin symlink into the serving checkout.
     *
     * The gitignored `comp-expiry.local.php` beside the tracked file wins
     * per-key, which is how dev2 gets armed for Ian to look at without any lane
     * editing the serving checkout — dev2's pool files are symlinks into it, so
     * a tracked edit there can block a later `pull --ff-only`. LIVE IS
     * PROTECTED BY ABSENCE: it has no .local.php, so it takes the tracked
     * default until Ian says otherwise.
     *
     * ⚠️ `php -l` THE .local.php BEFORE PLACING IT. `@` suppresses warnings,
     * not parse errors, and this file is required from the cron tick.
     *
     * Unreadable or malformed FAILS CLOSED, because the failure mode here is
     * taking a real person's access away.
     */
    private static function cfg(): array
    {
        if ( self::$override !== null ) {
            return self::$override + [ 'enabled' => false, 'effective_from' => '' ];
        }

        static $cfg = null;
        if ( $cfg !== null ) {
            return $cfg;
        }

        $cfg = [ 'enabled' => false, 'effective_from' => '' ];
        $dir = __DIR__ . '/../../../platform/config/';

        $tracked = @include $dir . 'comp-expiry.php';
        if ( is_array( $tracked ) ) {
            $cfg = $tracked + $cfg;
        }

        // Per-box overlay, gitignored. Wins only where it actually says
        // something — a malformed or unreadable file leaves the tracked value
        // standing rather than blanking it.
        $local = @include $dir . 'comp-expiry.local.php';
        if ( is_array( $local ) ) {
            $cfg = $local + $cfg;
        }

        return $cfg;
    }

    public static function enabled(): bool
    {
        return ! empty( self::cfg()['enabled'] );
    }

    /**
     * The enforcement cutover as a timestamp, or null when enforcement is
     * fenced off entirely.
     *
     * ⚠️ NULL IS THE SAFE ANSWER AND IT IS THE DEFAULT. Empty, malformed, or a
     * date PHP cannot parse all return null, and null means nobody is ever
     * demoted. A broken fence must never fail open.
     */
    public static function effectiveFrom(): ?int
    {
        $raw = trim( (string) ( self::cfg()['effective_from'] ?? '' ) );
        if ( $raw === '' ) {
            return null;
        }
        try {
            // Y-m-d, read at midnight UTC — the same zone the timers are stored
            // in (CompStanding's docblock carries the two proofs).
            $dt = new \DateTimeImmutable( $raw, new \DateTimeZone( 'UTC' ) );
        } catch ( Throwable $e ) {
            return null;
        }
        return $dt->getTimestamp();
    }

    /**
     * THE POLICY, in one place: may this member's comp role come off right now?
     *
     * Every "no" below is a distinct reason a real person keeps their access,
     * and each is asserted separately in gate 89.
     */
    public static function shouldExpire( int $wpUserId ): bool
    {
        if ( ! self::enabled() ) {
            return false;                              // flag off — total no-op
        }
        if ( ! CompStanding::holdsComp( $wpUserId ) ) {
            return false;                              // not a comp member at all
        }
        $exp = CompStanding::expiresAt( $wpUserId );
        if ( $exp === null ) {
            return false;                              // no timer = never expires (12 of 14 live holders)
        }
        if ( $exp > time() ) {
            return false;                              // timer still running
        }
        $cutover = self::effectiveFrom();
        if ( $cutover === null ) {
            return false;                              // fence unset/broken — fail closed
        }

        // THE RULING, 2026-08-21: the two accounts whose timers were already
        // past when enforcement was re-armed are LEFT ALONE. A date rather than
        // a list of ids, so it cannot be defeated by a typo and it holds every
        // already-overdue account on every box — including any nobody measured.
        return $exp >= $cutover;
    }

    /**
     * What state is this member in, and why — for the dash and the log.
     *
     * @return array{state:string, expires_at:?int, expires_raw:string, reason:string}
     */
    public static function statusFor( int $wpUserId ): array
    {
        $raw  = trim( (string) get_user_meta( $wpUserId, CompStanding::META, true ) );
        $exp  = CompStanding::expiresAt( $wpUserId );
        $held = CompStanding::holdsComp( $wpUserId );

        $out = [ 'state' => self::STATE_NO_TIMER, 'expires_at' => $exp, 'expires_raw' => $raw, 'reason' => '' ];

        if ( ! $held ) {
            $out['state']  = $raw === '' ? self::STATE_NO_TIMER : self::STATE_LAPSED;
            $out['reason'] = $raw === ''
                ? 'not a comp member'
                : 'carries a timer but no longer holds looth4 — already enforced, or the comp was removed by hand';
            return $out;
        }
        if ( $exp === null ) {
            $out['reason'] = $raw === ''
                ? 'no expiry set — this comp does not run out'
                : 'expiry value is not a date, so it is ignored rather than treated as lapsed';
            return $out;
        }
        if ( $exp > time() ) {
            $out['state']  = self::STATE_RUNNING;
            $out['reason'] = 'timer running, expires ' . gmdate( 'Y-m-d H:i', $exp ) . ' UTC';
            return $out;
        }
        if ( ! self::enabled() ) {
            $out['state']  = self::STATE_DISARMED;
            $out['reason'] = 'timer ran out ' . gmdate( 'Y-m-d', $exp ) . ' UTC — enforcement is OFF, nobody is demoted';
            return $out;
        }
        $cutover = self::effectiveFrom();
        if ( $cutover === null ) {
            $out['state']  = self::STATE_HELD;
            $out['reason'] = 'timer ran out ' . gmdate( 'Y-m-d', $exp ) . ' UTC — no enforcement cutover set, so nobody is demoted';
            return $out;
        }
        if ( $exp < $cutover ) {
            $out['state']  = self::STATE_HELD;
            $out['reason'] = sprintf(
                'timer ran out %s UTC, BEFORE the %s cutover — held by ruling, decide case by case',
                gmdate( 'Y-m-d', $exp ),
                gmdate( 'Y-m-d', $cutover ),
            );
            return $out;
        }
        $out['state']  = self::STATE_DUE;
        $out['reason'] = 'timer ran out ' . gmdate( 'Y-m-d', $exp ) . ' UTC — due for expiry on the next sweep';
        return $out;
    }

    /**
     * Every WP user carrying a comp timer, plus every current comp holder.
     *
     * The union matters for the dash: a member demoted last night no longer
     * holds looth4, and dropping them from the list the moment it happens is
     * how an operator loses sight of what the sweep just did.
     *
     * @return int[] user ids, ascending
     */
    public static function subjects(): array
    {
        global $wpdb;

        $ids = [];
        if ( isset( $wpdb ) && is_object( $wpdb ) ) {
            $timers = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''",
                CompStanding::META
            ) );
            $comps = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
                $wpdb->get_blog_prefix() . 'capabilities',
                '%' . $wpdb->esc_like( CompStanding::ROLE ) . '%'
            ) );
            $ids = array_merge( (array) $timers, (array) $comps );
        }

        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        sort( $ids );
        return $ids;
    }

    /**
     * The tick pass: decide, journal, act through the Arbiter, notify.
     *
     * Own try/catch so a sweep failure can never take down the passes after it
     * (the tick wraps it again, belt and braces).
     */
    public static function tick(): void
    {
        if ( ! self::enabled() ) {
            return;   // OFF = total no-op: no query, no option write, no log line
        }

        try {
            $subjects = self::subjects();
        } catch ( Throwable $e ) {
            Log::line( sprintf(
                "[%s] comp expiry sweep FAILED to enumerate: %s\n",
                gmdate( 'c' ),
                $e->getMessage(),
            ) );
            return;
        }

        $now      = gmdate( 'c' );
        $cutover  = self::effectiveFrom();
        $rows     = [];
        $expired  = 0;
        $held     = 0;

        foreach ( $subjects as $uid ) {
            $status = self::statusFor( $uid );

            if ( $status['state'] === self::STATE_HELD ) {
                $held++;
                Log::line( sprintf(
                    "[%s] comp expiry: HELD — user %d %s\n",
                    $now, $uid, $status['reason'],
                ) );
            }

            if ( self::shouldExpire( $uid ) ) {
                // THE ONE WRITE, and it is not ours. The Arbiter removes looth4
                // and re-arbitrates, so the member lands on whatever their
                // payment sources actually say — looth3 for a comp who also
                // pays on Patreon, looth1 for one with no source at all. Never
                // a flat demotion, and never no tier at all.
                try {
                    $res = Arbiter::sync( $uid );
                    $status['became'] = $res['winning_tier'] ?? null;
                    $status['state']  = self::STATE_LAPSED;
                    $expired++;
                    Log::line( sprintf(
                        "[%s] comp expiry: EXPIRED — user %d looth4 -> %s (timer ran out %s UTC)\n",
                        $now,
                        $uid,
                        $status['became'] ?? '(no tier)',
                        $status['expires_at'] !== null ? gmdate( 'Y-m-d H:i', $status['expires_at'] ) : '?',
                    ) );
                } catch ( Throwable $e ) {
                    Log::line( sprintf(
                        "[%s] comp expiry: user %d FAILED to expire: %s\n",
                        $now, $uid, $e->getMessage(),
                    ) );
                    continue;
                }
            }

            $rows[ $uid ] = [ 'wp_user_id' => $uid ] + $status;
        }

        update_option( self::FINDINGS, [
            'updated_at'     => $now,
            'effective_from' => $cutover === null ? null : gmdate( 'Y-m-d', $cutover ),
            'count'          => count( $rows ),
            'expired'        => $expired,
            'held'           => $held,
            'findings'       => $rows,
        ], false );

        Log::line( sprintf(
            "[%s] comp expiry sweep: %d comp subjects, %d expired, %d HELD by the %s cutover\n",
            $now,
            count( $rows ),
            $expired,
            $held,
            $cutover === null ? '(unset)' : gmdate( 'Y-m-d', $cutover ),
        ) );
    }
}
