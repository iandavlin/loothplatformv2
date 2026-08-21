<?php

declare(strict_types=1);

namespace LGSB\Core;

use PDO;
use Throwable;

/**
 * A RECEIPT FOR EVERY WEBHOOK THAT LANDS. Issue #192.
 *
 * ⚠️ BEFORE THIS, QUESTION ONE HAD NO DATA SOURCE AT ALL. WebhookController
 * verified, dispatched and returned; nothing anywhere recorded that Stripe had
 * ever reached us. So "are webhooks arriving?" could not be answered by reading
 * anything — only by going to the Stripe dashboard, which is a different
 * company's screen and not where anyone looks when the site misbehaves.
 *
 * SILENCE IS THE FAILURE MODE. A webhook endpoint that has quietly stopped
 * working looks exactly like a webhook endpoint on a quiet day, and both look
 * like a blank cell. This class is what lets the dash tell those three apart.
 *
 * ---------------------------------------------------------------------------
 * WHY audit_log AND NOT A NEW TABLE
 * ---------------------------------------------------------------------------
 * audit_log is described in the schema as "anything that changes access or
 * money", already names 'webhook' in its own actor_type comment, has NO foreign
 * key on subject_id, and carries an index on (action, created_at) — which is
 * precisely the read "the most recent receipt" needs. Measured 2026-08-21 it
 * holds ZERO rows on dev2 and ZERO on live.
 *
 * The decisive argument is deployment: a new table means a migration, a
 * migration on live means a DDL statement that only Ian can run, and this panel
 * exists to be available BEFORE go-live, not after another hand-off. Reusing a
 * table that already exists on both boxes makes the whole feature land on a
 * plain `git pull`.
 *
 * ---------------------------------------------------------------------------
 * ⚠️ THE TWO KINDS ARE NOT SYMMETRICAL, AND CONFLATING THEM WOULD BE A BUG
 * ---------------------------------------------------------------------------
 * A VERIFIED event is Stripe-signed. It cannot be forged, its volume is bounded
 * by real activity, and every one of them is worth keeping — so those are
 * recorded unconditionally.
 *
 * A SIGNATURE FAILURE arrives at an UNAUTHENTICATED endpoint. Anyone on the
 * internet can POST rubbish at /billing/v1/webhook, and an unconditional insert
 * there is a table anyone can fill. So failures are RATE-LIMITED to one record
 * per five minutes, which bounds the worst case at 288 rows a day while still
 * making the signal impossible to miss — and the signal is the valuable half:
 * a rising failure count beside a silent success count IS a mismatched webhook
 * secret showing itself, and it is the only place that particular disagreement
 * is visible from outside Stripe.
 *
 * ---------------------------------------------------------------------------
 * ⚠️ IT CAN NEVER CHANGE WHAT STRIPE GETS BACK
 * ---------------------------------------------------------------------------
 * Every method swallows every Throwable. A missing table, a lost connection, a
 * revoked grant — none of them may turn a webhook Stripe considers delivered
 * into one it will retry for three days. Bookkeeping must never be able to take
 * down the thing it is bookkeeping, which is the discipline Log already follows
 * on the WordPress side.
 *
 * ⚠️ NO PAYLOAD IS STORED. The event id and type only. A webhook body carries
 * customer emails and card metadata, and a diagnostic table is the wrong home
 * for either.
 */
final class WebhookReceipts
{
    public const ACT_RECEIVED = 'webhook_received';
    public const ACT_SIG_FAIL = 'webhook_signature_failed';

    private const SUBJECT = 'webhook';

    /** One signature-failure record per this many seconds. See the docblock. */
    private const FAIL_THROTTLE_SECONDS = 300;

    /**
     * A Stripe-signed event arrived and verified.
     *
     * @param string $eventId Stripe's event id (evt_…), for cross-referencing
     *                        against their dashboard. Never a payload.
     */
    public static function recordVerified(PDO $pdo, string $eventType, string $eventId): void
    {
        self::insert($pdo, self::ACT_RECEIVED, [
            'type'     => $eventType !== '' ? $eventType : 'unknown',
            'event_id' => $eventId,
        ]);
    }

    /**
     * Something POSTed to the webhook endpoint and did not verify.
     *
     * Throttled, because the endpoint is unauthenticated. The throttle read is
     * itself wrapped: if it cannot be answered, the record is SKIPPED rather
     * than written, so a broken read can never become the unbounded insert the
     * throttle exists to prevent.
     */
    public static function recordSignatureFailure(PDO $pdo, bool $secretConfigured): void
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT created_at FROM audit_log
                  WHERE subject_type = :s AND action = :a
                  ORDER BY created_at DESC LIMIT 1'
            );
            $stmt->execute([':s' => self::SUBJECT, ':a' => self::ACT_SIG_FAIL]);
            $last = (string) ($stmt->fetchColumn() ?: '');

            if ($last !== '') {
                $t = strtotime($last . ' UTC');
                if ($t !== false && (time() - $t) < self::FAIL_THROTTLE_SECONDS) {
                    return;   // already recorded recently; the count is not the point, the signal is
                }
            }
        } catch (Throwable) {
            return;
        }

        self::insert($pdo, self::ACT_SIG_FAIL, [
            // Distinguishes "our secret is wrong" from "we have no secret at
            // all" — different sentences, different fixes.
            'secret_configured' => $secretConfigured,
        ]);
    }

    /**
     * @param array<string,mixed> $details
     */
    private static function insert(PDO $pdo, string $action, array $details): void
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_log (actor_type, actor_ref, subject_type, subject_id, action, details)
                 VALUES (:actor, :ref, :stype, 0, :action, :details)'
            );
            $stmt->execute([
                ':actor'   => 'webhook',
                ':ref'     => 'stripe',
                ':stype'   => self::SUBJECT,
                ':action'  => $action,
                ':details' => json_encode($details, JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            // Never propagate. Stripe must get the same response it would have
            // got if this class did not exist.
            error_log('LGSB webhook receipt failed: ' . $e->getMessage());
        }
    }
}
