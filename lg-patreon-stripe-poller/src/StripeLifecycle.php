<?php

declare(strict_types=1);

namespace LGMS;

use LGMS\Repos\CustomerRepo;
use LGMS\Repos\EntitlementRepo;
use LGMS\Repos\SubscriptionRepo;
use LGMS\Wp\IdentityMatcher;
use PDO;
use Throwable;

/**
 * Webhook-driven Stripe membership lifecycle (charter Phase 1 item 1;
 * design doc STRIPE-IDENTITY-AND-LIFECYCLE-DESIGN.md §3–§4). This is the
 * monorepo replacement for lg-stripe-billing's lifecycle role — that Slim
 * app is a separate dead repo with no nginx route on live, and per the
 * audit's recommendation its lifecycle half is folded in HERE, traceable
 * to a commit. Its signature-verification property (audited CORRECT) is
 * kept: \Stripe\Webhook::constructEvent, 400 on SignatureVerificationException.
 *
 * THE TIER RULING (Ian, quote-grade, 8/08 handoff):
 *
 *   "move to ONE tier for the stripe memberships and have ALL tiered
 *    content open to the one tier through stripe."
 *
 * Stripe's single membership ALWAYS resolves looth3 (Pro). There is no
 * price→tier mapping in this class and there must never be a second Stripe
 * tier code path. Patreon's two tiers and login are untouched; the Arbiter's
 * max-of-sources already gives dual-holders the right answer.
 *
 * THE CONFIRM PATTERN (design doc §4): the mirror cannot be trusted — live
 * proved it (customer 7 'active' with current_period_end a month past) —
 * and an event snapshot can be stale too (out-of-order redelivery). So
 * state is applied from a FRESH Stripe API read of the subscription, or
 * not at all: confirm unreachable ⇒ defer (503, event NOT marked
 * processed) and Stripe redelivers with backoff for ~3 days. The webhook
 * leg is fast-but-lossy; the RetractionSweep reconcile leg (already
 * shipped, lgms_retraction_sweep) is the backstop that iterates the
 * opinions.
 *
 * IDENTITY: customer → WP user resolution is the bridge, then
 * IdentityMatcher, then REFUSE. Never email-first, never wp_insert_user.
 * A refusal defers (503) so that once an operator bridges the member, the
 * next redelivery of the SAME event applies cleanly.
 *
 * INTERLOCK: this class refuses to process while lgms_identity_gate is
 * dark. Sync::all() still sweeps every active customer every 5 minutes
 * through UserProvisioner, whose flag-OFF path is the email-keyed minter —
 * so a customer row created here while the gate is dark would be handed to
 * the minter within 5 minutes. Lifecycle ON therefore REQUIRES identity
 * gate ON (launch checklist §5 already ordered it first).
 *
 * FLAG: lgms_stripe_lifecycle — an option, not an env var (WP-Cron and
 * PHP-FPM carry different environments). DEFAULT ABSENT = OFF. OFF is a
 * total no-op: the webhook route is never registered (WP answers the same
 * rest_no_route 404 as today), no DB read, no option write, no log line.
 * Proven byte-identical AND gated by
 * deploy/remediation/test-stripe-lifecycle.php §1.
 *
 * JOURNAL: every applied role-source change writes lg_lifecycle_journal
 * BEFORE the mutation and verifies the row persisted (the ross journal
 * property — see revoke-orphan-stripe-sources.php). had_row distinguishes
 * a tier=NULL row from an absent row: array_key_exists is TRUE for NULL,
 * and conflating the two nearly demoted four paying members (8/08 handoff
 * §6).
 *
 * COEXISTENCE SEAM — deliberately NOT built: the three guards keyed on the
 * single-valued `payment_source` user meta (design doc §2) are a known
 * defect in the dual-wield world Ian ruled for — a member paying both gets
 * payment_source=stripe, the Patreon sweep skips them forever, and a later
 * Stripe cancel drops a still-paying patron to looth1. That change needs
 * Ian's ruling first. This class therefore writes NO payment_source meta:
 * writing 'stripe' here would ARM guard 1 and 2 against dual-holders. When
 * the §2 ruling lands, the guards become source-shaped and this seam
 * closes.
 */
final class StripeLifecycle
{
    public const FLAG             = 'lgms_stripe_lifecycle';
    public const SECRET_OPT       = 'lgms_stripe_webhook_secret';
    public const PRICE_OPT        = 'lgms_stripe_price_id';
    public const IDENTITY_GATE_OPT = 'lgms_identity_gate';
    public const ALLOWLIST_OPT    = 'lgms_stripe_lifecycle_allowlist';

    /**
     * THE single Stripe tier. A ruling, not a lookup — no ProductRepo, no
     * price map, no second code path. (The frozen poll leg's EventHandler
     * still carries the old multi-tier mapping; it is unreachable behind
     * lgms_stripe_frozen and is flagged for retirement, not extended.)
     */
    public const TIER = 'looth3';

    /** Statuses that hold the membership up (trialing counts — Stripe bills at trial end). */
    private const STATUS_ACTIVE = [ 'active', 'trialing' ];
    /** The retry/grace window: access retained while Stripe retries the card. */
    private const STATUS_GRACE  = [ 'past_due' ];
    /** Past grace / dead: the opinion is retracted. */
    private const STATUS_DEAD   = [ 'canceled', 'incomplete_expired', 'unpaid' ];
    // Anything else (incomplete, paused) is limbo: mirror only, no grant,
    // no retraction — a checkout that never paid grants nothing, and a
    // pause is not a death (the reconcile leg revisits it regardless).

    /**
     * Test seam (mirrors Log::reset): gates and the dev2 e2e harness inject
     * a confirm client here. Production path constructs LGMS\Stripe\Client,
     * which reads lgms_stripe_secret_key.
     *
     * @var null|callable():object
     */
    public static $confirmFactory = null;

    public static function _resetForTests(): void
    {
        self::$confirmFactory = null;
    }

    /** The raw flag. Route registration keys on this and nothing else. */
    public static function flagOn(): bool
    {
        return (bool) get_option( self::FLAG, false );
    }

    /**
     * SOFT-LAUNCH COHORT (docs/STRIPE-SOFT-LAUNCH-ALLOWLIST.md): the option
     * holds an array of WP user IDs; only members on it transition. An
     * absent, empty, or malformed value is CLOSED FOR EVERYONE — flipping
     * the lifecycle ON grants nobody until the cohort is populated (the
     * fail-safe, gated by test-soft-launch-allowlist.php §1/§4). Numeric
     * strings are accepted because `wp option update ... --format=json`
     * legitimately lands them; everything else is dropped, never guessed.
     * The Admin dash writes this option through CohortAllowlist, whose
     * canonical shape is exactly what this reader accepts.
     *
     * @return array<int, true> normalized set of allowed WP user ids
     */
    public static function allowlist(): array
    {
        $raw = get_option( self::ALLOWLIST_OPT, [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $ids = [];
        foreach ( $raw as $v ) {
            if ( is_int( $v ) || ( is_string( $v ) && ctype_digit( trim( $v ) ) ) ) {
                $n = (int) trim( (string) $v );
                if ( $n > 0 ) {
                    $ids[ $n ] = true;
                }
            }
        }
        return $ids;
    }

    /**
     * PUBLIC because the cohort fences TWO grant paths, not one. This class
     * guards the webhook; LGMS\Sync guards the entitlement sweep, which is how
     * a redeemed gift and the five-minute cron reach the very same Arbiter
     * write. One predicate, one normalization — a second copy of
     * `isset( allowlist()[ $id ] )` elsewhere is exactly how the two ends of a
     * fence drift apart.
     */
    public static function inCohort( int $wpUserId ): bool
    {
        return isset( self::allowlist()[ $wpUserId ] );
    }

    /* ------------------------------------------------------------------ */
    /* Ingest                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Verify, dedupe, dispatch. Returns a transport-agnostic result the
     * REST controller maps 1:1 onto a WP_REST_Response.
     *
     * Status discipline (Stripe retries anything non-2xx with backoff):
     *   200  applied / no-op / duplicate / skipped type
     *   400  signature or payload failure — retrying cannot help
     *   500  our misconfiguration (no webhook secret)
     *   503  defer — confirm unreachable, identity unresolved, interlock
     *        dark, out-of-order delivery. NOT marked processed, so the
     *        redelivery gets a full second chance.
     *
     * @return array{status:int, body:array}
     */
    public static function ingest( string $payload, string $sigHeader ): array
    {
        if ( ! self::flagOn() ) {
            // Belt — the route is only registered when the flag is on.
            return [ 'status' => 404, 'body' => [ 'error' => 'not enabled' ] ];
        }

        $secret = (string) get_option( self::SECRET_OPT, '' );
        if ( $secret === '' ) {
            Log::line( sprintf( "[%s] stripe lifecycle: webhook secret not configured\n", gmdate( 'c' ) ) );
            return [ 'status' => 500, 'body' => [ 'error' => 'Webhook secret not configured.' ] ];
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $secret,
                \Stripe\Webhook::DEFAULT_TOLERANCE,
            );
        } catch ( \Stripe\Exception\SignatureVerificationException $e ) {
            // The audited-correct property: bad signature is a hard 400.
            return [ 'status' => 400, 'body' => [ 'error' => 'Invalid signature.' ] ];
        } catch ( \UnexpectedValueException $e ) {
            return [ 'status' => 400, 'body' => [ 'error' => 'Invalid payload.' ] ];
        }

        // Interlock — see the class doc. Signature came first (auth before
        // anything), but nothing processes while the identity gate is dark.
        if ( ! (bool) get_option( self::IDENTITY_GATE_OPT, false ) ) {
            Log::line( sprintf(
                "[%s] stripe lifecycle: REFUSED event %s — lgms_identity_gate is OFF. Lifecycle requires the identity gate armed (Sync::all still runs the email-first minter for every customer row).\n",
                gmdate( 'c' ),
                (string) ( $event->id ?? '?' ),
            ) );
            return [ 'status' => 503, 'body' => [ 'error' => 'identity gate not armed' ] ];
        }

        return self::handleEvent( $event );
    }

    /**
     * Dedupe + dispatch a verified event.
     *
     * @return array{status:int, body:array}
     */
    public static function handleEvent( object $event ): array
    {
        $eventId = (string) ( $event->id ?? '' );
        $type    = (string) ( $event->type ?? '' );
        $object  = $event->data->object ?? null;

        if ( $eventId === '' || $type === '' || $object === null ) {
            return [ 'status' => 400, 'body' => [ 'error' => 'malformed event' ] ];
        }

        // Dedupe GATES here (unlike the poll leg's observation-only stance):
        // webhook redelivery is routine and a replayed event must not re-run
        // the pipeline. Same table as the poll leg, so if ingest ever
        // unfreezes, a poll+webhook double-delivery of one Stripe event
        // dedupes across both legs.
        if ( self::alreadyProcessed( $eventId ) ) {
            self::markProcessed( $eventId );   // bumps dup_count for visibility
            return [ 'status' => 200, 'body' => [ 'ok' => true, 'result' => 'duplicate' ] ];
        }

        try {
            $note = match ( $type ) {
                'checkout.session.completed'           => self::onCheckoutCompleted( $object, $eventId, $type ),
                'customer.subscription.created',
                'customer.subscription.updated',
                'customer.subscription.deleted'        => self::onSubscriptionEvent( $object, $eventId, $type ),
                'invoice.payment_failed'               => self::onPaymentFailed( $object, $eventId, $type ),
                default                                => "skip {$type}",
            };
        } catch ( LifecycleDefer $e ) {
            Log::line( sprintf(
                "[%s] stripe lifecycle: DEFER event %s (%s): %s\n",
                gmdate( 'c' ), $eventId, $type, $e->getMessage(),
            ) );
            return [ 'status' => 503, 'body' => [ 'error' => $e->getMessage() ] ];
        } catch ( Throwable $e ) {
            // Unknown failure: defer rather than swallow — the event is not
            // marked processed, Stripe redelivers, and the log carries it.
            Log::line( sprintf(
                "[%s] stripe lifecycle: FAILED event %s (%s): %s\n",
                gmdate( 'c' ), $eventId, $type, $e->getMessage(),
            ) );
            return [ 'status' => 503, 'body' => [ 'error' => 'processing failed' ] ];
        }

        // Marked processed ONLY after success — a deferred/failed event must
        // be reprocessable in full on redelivery.
        self::markProcessed( $eventId );
        Log::line( sprintf( "[%s] stripe lifecycle: %s %s — %s\n", gmdate( 'c' ), $eventId, $type, $note ) );
        return [ 'status' => 200, 'body' => [ 'ok' => true, 'result' => $note ] ];
    }

    /* ------------------------------------------------------------------ */
    /* Event handlers                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Completed checkout: mirror the customer, ABSORB the identity the
     * member's own session asserted (design doc §3.2 — this is what makes
     * IdentityMatcher branch 2 the normal case), then confirm + apply.
     */
    private static function onCheckoutCompleted( object $session, string $eventId, string $type ): string
    {
        if ( (string) ( $session->mode ?? '' ) !== 'subscription' ) {
            return 'checkout.session.completed: not subscription mode';
        }

        $stripeCustomerId = (string) ( $session->customer ?? '' );
        $email            = (string) ( $session->customer_details->email ?? $session->customer_email ?? '' );
        $subId            = (string) ( $session->subscription ?? '' );
        $name             = trim( (string) ( $session->customer_details->name ?? '' ) );
        $country          = isset( $session->customer_details->address->country )
            ? (string) $session->customer_details->address->country : null;

        if ( $stripeCustomerId === '' || $subId === '' || $email === '' ) {
            return 'checkout.session.completed: missing required fields';
        }

        $customer = CustomerRepo::findOrCreate( $email, $stripeCustomerId, $name ?: null, $country );
        if ( $customer === [] ) {
            throw new LifecycleDefer( 'could not mirror customer' );
        }

        // Session metadata → customers.metadata. Stripe does NOT copy
        // Checkout Session metadata onto the customer object by itself; this
        // absorb step is what carries the member-asserted identity into the
        // store IdentityMatcher reads. Only the two identity keys, only when
        // non-empty — never arbitrary passthrough.
        $meta    = self::metaToArray( $session->metadata ?? null );
        $absorb  = [];
        foreach ( [ 'wp_user_id', 'patreon_user_id' ] as $k ) {
            $v = trim( (string) ( $meta[ $k ] ?? '' ) );
            if ( $v !== '' ) {
                $absorb[ $k ] = $v;
            }
        }
        if ( $absorb !== [] ) {
            CustomerRepo::mergeMetadata( (int) $customer['id'], $absorb );
        }

        $confirmed = self::confirmSubscription( $subId );
        return self::applyConfirmed( $customer, $confirmed, $eventId, $type );
    }

    private static function onSubscriptionEvent( object $sub, string $eventId, string $type ): string
    {
        $stripeCustomerId = (string) ( $sub->customer ?? '' );
        $subId            = (string) ( $sub->id ?? '' );
        if ( $stripeCustomerId === '' || $subId === '' ) {
            return "{$type}: missing fields";
        }

        $customer = CustomerRepo::findByStripeCustomerId( $stripeCustomerId );
        if ( ! $customer ) {
            // Out-of-order delivery: checkout.session.completed mirrors the
            // customer. Defer; the redelivery lands after it has.
            throw new LifecycleDefer( "no local customer for {$stripeCustomerId} yet" );
        }

        $confirmed = self::confirmSubscription( $subId );
        return self::applyConfirmed( $customer, $confirmed, $eventId, $type );
    }

    private static function onPaymentFailed( object $invoice, string $eventId, string $type ): string
    {
        $stripeCustomerId = (string) ( $invoice->customer ?? '' );
        $subId            = (string) ( $invoice->subscription ?? '' );
        if ( $stripeCustomerId === '' || $subId === '' ) {
            return 'invoice.payment_failed: not a subscription invoice';
        }

        $customer = CustomerRepo::findByStripeCustomerId( $stripeCustomerId );
        if ( ! $customer ) {
            throw new LifecycleDefer( "no local customer for {$stripeCustomerId} yet" );
        }

        // Same shape as every other transition: the CONFIRM decides. A
        // payment_failed inside the retry window confirms past_due (grace,
        // access retained); past grace Stripe has already moved the
        // subscription to unpaid/canceled and the confirm retracts.
        $confirmed = self::confirmSubscription( $subId );
        return self::applyConfirmed( $customer, $confirmed, $eventId, $type );
    }

    /* ------------------------------------------------------------------ */
    /* State application                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Mirror the confirmed subscription, then move the entitlement + the
     * lg_role_sources opinion to match. The state machine:
     *
     *   confirmed status              state     entitlement   stripe opinion
     *   active | trialing             active    grant looth3  looth3
     *   past_due                      grace     grant looth3  looth3 (kept)
     *   canceled|incomplete_expired|
     *   unpaid                        canceled  revoke        NULL (row kept)
     *   incomplete | paused | other   limbo     no change     no change
     */
    private static function applyConfirmed( array $customer, object $confirmed, string $eventId, string $type ): string
    {
        $customerId = (int) $customer['id'];
        $subId      = (string) ( $confirmed->id ?? '' );
        $status     = (string) ( $confirmed->status ?? '' );
        $priceId    = (string) ( $confirmed->items->data[0]->price->id ?? '' );

        $subRow = SubscriptionRepo::upsert(
            $customerId,
            $subId,
            $priceId,
            $status,
            (bool) ( $confirmed->cancel_at_period_end ?? false ),
            isset( $confirmed->current_period_start ) ? (int) $confirmed->current_period_start : null,
            isset( $confirmed->current_period_end )   ? (int) $confirmed->current_period_end   : null,
            isset( $confirmed->canceled_at ) && $confirmed->canceled_at !== null ? (int) $confirmed->canceled_at : null,
        );

        // SOFT-LAUNCH GATE (docs/STRIPE-SOFT-LAUNCH-ALLOWLIST.md): identity
        // is resolved HERE, before either mutation branch, because the gate
        // must sit after the resolved wp user id exists and before ANY
        // membership mutation — the EntitlementRepo write included (the
        // spec's "before the Arbiter/EntitlementRepo write"). Out-of-cohort
        // is a journaled, acknowledged 200 skip in BOTH directions: a
        // member pulled from the cohort is frozen, never half-retracted.
        // The limbo path below stays resolution-free — it mutates nothing,
        // so an unresolvable customer in limbo still answers 200 as before.

        if ( in_array( $status, self::STATUS_ACTIVE, true ) || in_array( $status, self::STATUS_GRACE, true ) ) {
            $state    = in_array( $status, self::STATUS_GRACE, true ) ? 'past_due' : 'active';
            $wpUserId = self::resolveMember( $customer );
            if ( ! self::inCohort( $wpUserId ) ) {
                return self::journalCohortSkip( $customer, $wpUserId, $state, $eventId, $type );
            }
            EntitlementRepo::grantMembershipFromSubscription( $customerId, self::TIER, (int) $subRow['id'] );
            $out = self::applyOpinion( $customer, $wpUserId, self::TIER, $state, $eventId, $type );
            return "{$type}: customer {$customerId} {$state} → " . self::TIER . " ({$out})";
        }

        if ( in_array( $status, self::STATUS_DEAD, true ) ) {
            $wpUserId = self::resolveMember( $customer );
            if ( ! self::inCohort( $wpUserId ) ) {
                return self::journalCohortSkip( $customer, $wpUserId, 'canceled', $eventId, $type );
            }
            EntitlementRepo::revokeBySource( EntitlementRepo::SOURCE_SUBSCRIPTION, (int) $subRow['id'] );
            $out = self::applyOpinion( $customer, $wpUserId, null, 'canceled', $eventId, $type );
            return "{$type}: customer {$customerId} retracted ({$status}; {$out})";
        }

        // Limbo — incomplete never paid, paused is not a death. Mirror only;
        // the reconcile leg revisits every opinion regardless.
        return "{$type}: customer {$customerId} status={$status}, mirror only";
    }

    /**
     * Journal a soft-launch cohort skip. NOT a membership mutation — tier
     * and had_row are recorded unchanged (tier_after = tier_before) so the
     * journal is an honest audit trail of every event the cohort turned
     * away, tied to the event id. The caller returns this note straight to
     * Stripe as a 200 result; handleEvent's standard log line carries it.
     */
    private static function journalCohortSkip( array $customer, int $wpUserId, string $state, string $eventId, string $type ): string
    {
        $st = Db::pdo()->prepare(
            "SELECT tier, COUNT(*) AS n FROM lg_role_sources WHERE wp_user_id = ? AND source = 'stripe'"
        );
        $st->execute( [ $wpUserId ] );
        $cur    = $st->fetch( PDO::FETCH_ASSOC );
        $hadRow = (int) ( $cur['n'] ?? 0 ) > 0;
        $before = $hadRow && $cur['tier'] !== null ? (string) $cur['tier'] : null;

        Db::pdo()->prepare(
            'INSERT INTO lg_lifecycle_journal
                (event_id, event_type, wp_user_id, customer_id, source, tier_before, had_row, tier_after, state, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute( [
            $eventId,
            $type,
            $wpUserId,
            (int) $customer['id'],
            'stripe',
            $before,
            $hadRow ? 1 : 0,
            $before,
            'skipped',
            "skipped: not in soft-launch cohort (uid={$wpUserId})",
            gmdate( 'Y-m-d H:i:s' ),
        ] );

        return "skipped: not in soft-launch cohort (uid={$wpUserId})";
    }

    /**
     * Move the stripe opinion for this customer's member to $tier, through
     * the journal and the Arbiter. tier=NULL is the retracted state the
     * RetractionSweep treats as correct — NEVER row deletion (deletion is
     * for debris, and only the remediation scripts do it). $wpUserId is
     * resolved by the caller (applyConfirmed), where the soft-launch cohort
     * gate needs it before the entitlement write.
     */
    private static function applyOpinion( array $customer, int $wpUserId, ?string $tier, string $state, string $eventId, string $type ): string
    {
        // Current opinion — tier AND row-existence. A NULL row and an absent
        // row are opposite situations (handoff §6): select the count alongside.
        $st = Db::pdo()->prepare(
            "SELECT tier, COUNT(*) AS n FROM lg_role_sources WHERE wp_user_id = ? AND source = 'stripe'"
        );
        $st->execute( [ $wpUserId ] );
        $cur    = $st->fetch( PDO::FETCH_ASSOC );
        $hadRow = (int) ( $cur['n'] ?? 0 ) > 0;
        $before = $hadRow && $cur['tier'] !== null ? (string) $cur['tier'] : null;

        if ( $hadRow && $before === $tier ) {
            // Idempotent redelivery under a new event id: dedupe can't catch
            // it, the no-op check must. No journal, no Arbiter churn.
            return "wp #{$wpUserId} no-op (already " . ( $tier ?? 'NULL' ) . ')';
        }

        // Journal BEFORE the mutation, and prove it persisted (the ross
        // property: if the journal is not durable, nothing else happens).
        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO lg_lifecycle_journal
                (event_id, event_type, wp_user_id, customer_id, source, tier_before, had_row, tier_after, state, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute( [
            $eventId,
            $type,
            $wpUserId,
            (int) $customer['id'],
            'stripe',
            $before,
            $hadRow ? 1 : 0,
            $tier,
            $state,
            null,
            gmdate( 'Y-m-d H:i:s' ),
        ] );
        $jid   = (int) $pdo->lastInsertId();
        $check = $pdo->prepare( 'SELECT tier_after, state FROM lg_lifecycle_journal WHERE id = ?' );
        $check->execute( [ $jid ] );
        $back = $check->fetch( PDO::FETCH_ASSOC );
        if ( ! $back || $back['tier_after'] !== $tier || (string) $back['state'] !== $state ) {
            throw new LifecycleDefer( 'journal did not persist — refusing to mutate' );
        }

        RoleSourceWriter::report( $wpUserId, 'stripe', $tier );
        Arbiter::sync( $wpUserId );

        // NOTE deliberately absent: no update_user_meta('payment_source').
        // See the COEXISTENCE SEAM block in the class doc — the guards keyed
        // on that single-valued meta are design doc §2's open defect and are
        // held for Ian's ruling.

        return "wp #{$wpUserId} " . ( $before ?? ( $hadRow ? 'NULL' : 'absent' ) ) . ' → ' . ( $tier ?? 'NULL' );
    }

    /**
     * customer → WP user: the bridge, then IdentityMatcher, then refuse.
     * The ONLY resolution path in this class. Never email-first (email is
     * IdentityMatcher's weakest signal, inside the matcher), never minting.
     */
    private static function resolveMember( array $customer ): int
    {
        $customerId = (int) $customer['id'];

        $st = Db::pdo()->prepare( 'SELECT wp_user_id FROM wp_user_bridge WHERE customer_id = ? LIMIT 1' );
        $st->execute( [ $customerId ] );
        $bridged = $st->fetchColumn();
        if ( $bridged !== false ) {
            return (int) $bridged;
        }

        $match = IdentityMatcher::match( $customerId, (string) ( $customer['email'] ?? '' ) );
        if ( $match !== null ) {
            // PLAIN insert, no ON DUPLICATE KEY: IdentityMatcher already
            // refused any user bridged to a different customer, so a
            // collision here is a real race and must surface loudly (the
            // write side of audit R3 — the ODKU clause that silently
            // updated the OTHER customer's row).
            Db::pdo()->prepare(
                'INSERT INTO wp_user_bridge (customer_id, wp_user_id, synced_at) VALUES (?, ?, ?)'
            )->execute( [ $customerId, $match['wp_user_id'], gmdate( 'Y-m-d H:i:s' ) ] );
            Log::line( sprintf(
                "[%s] stripe lifecycle: customer %d -> WP #%d via %s\n",
                gmdate( 'c' ), $customerId, $match['wp_user_id'], $match['via'],
            ) );
            return $match['wp_user_id'];
        }

        // Refuse. Notify an operator (throttled — Stripe redelivers for ~3
        // days and each retry lands here until the member is bridged), then
        // defer so the eventual fix makes the SAME event apply cleanly.
        $email  = (string) ( $customer['email'] ?? '' );
        $detail = sprintf(
            'Stripe lifecycle: customer %d (%s) could not be matched to a WP account and the lifecycle never mints. %s',
            $customerId, $email, IdentityMatcher::describeConflict( $customerId, $email ),
        );
        Log::line( sprintf( "[%s] stripe lifecycle: identity REFUSED — %s\n", gmdate( 'c' ), $detail ) );

        $throttleKey = 'lgms_lc_refused_cus_' . $customerId;
        if ( get_transient( $throttleKey ) === false ) {
            set_transient( $throttleKey, 1, 86400 );
            if ( function_exists( 'lgpo_notify_failure' ) ) {
                lgpo_notify_failure( $email, (string) ( $customer['name'] ?? '' ), 'stripe.lifecycle_unmatched', $detail );
            }
        }

        throw new LifecycleDefer( "identity unresolved for customer {$customerId}" );
    }

    /* ------------------------------------------------------------------ */
    /* Plumbing                                                            */
    /* ------------------------------------------------------------------ */

    /** Fresh API read of the subscription — the only admissible state source. */
    private static function confirmSubscription( string $subId ): object
    {
        try {
            $client = self::$confirmFactory !== null
                ? ( self::$confirmFactory )()
                : new \LGMS\Stripe\Client();
            return $client->retrieveSubscription( $subId, [ 'items.data.price' ] );
        } catch ( Throwable $e ) {
            throw new LifecycleDefer( "confirm failed for {$subId}: " . $e->getMessage() );
        }
    }

    private static function alreadyProcessed( string $eventId ): bool
    {
        $st = Db::pdo()->prepare( 'SELECT 1 FROM lg_processed_events WHERE event_id = ? LIMIT 1' );
        $st->execute( [ $eventId ] );
        return $st->fetchColumn() !== false;
    }

    private static function markProcessed( string $eventId ): void
    {
        $now = gmdate( 'Y-m-d H:i:s' );
        Db::pdo()->prepare(
            'INSERT INTO lg_processed_events (event_id, first_seen_at, last_seen_at)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 last_seen_at = VALUES(last_seen_at),
                 dup_count    = dup_count + 1'
        )->execute( [ $eventId, $now, $now ] );
    }

    /** StripeObject / stdClass / array → plain array, or [] for null. */
    private static function metaToArray( mixed $meta ): array
    {
        if ( $meta === null ) {
            return [];
        }
        $arr = json_decode( (string) json_encode( $meta ), true );
        return is_array( $arr ) ? $arr : [];
    }
}

/**
 * Internal control-flow exception: "do not apply, do not mark processed,
 * answer 503 so Stripe redelivers." Never leaves StripeLifecycle::handleEvent.
 */
final class LifecycleDefer extends \RuntimeException
{
}
