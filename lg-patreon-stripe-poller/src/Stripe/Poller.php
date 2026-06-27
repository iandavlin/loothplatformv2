<?php

declare(strict_types=1);

/*
 * ===========================================================================
 * STRIPE R&D PAUSED — resume here when the Stripe system restarts.
 * ---------------------------------------------------------------------------
 * The Stripe alpha is decommissioned behind a real off-switch at the cron
 * entrypoint. LGMS\Tick::run() skips Pass 1 (the Stripe poll) whenever
 * get_option('lgms_stripe_frozen') is truthy, and that option DEFAULTS TRUE.
 * While frozen NOTHING in src/Stripe/ executes on the tick — no API call, no
 * EventHandler wp_mail(). To resume: set lgms_stripe_frozen to a falsey value,
 * then re-validate this path before relying on it. The companion repo
 * lg-stripe-billing carries the same pause note.
 * ===========================================================================
 */

namespace LGMS\Stripe;

use LGMS\Db;
use Throwable;

/**
 * Time-windowed Stripe events poller.
 *
 * On first run (no cursor in lg_event_cursor): stamps the current unix
 * timestamp and stops — does not back-process historical events. This
 * avoids replaying everything that's already been provisioned by Slim's
 * /v1/return.
 *
 * On subsequent runs: fetches events with created >= (cursor - SAFETY),
 * paginates within the tick, processes oldest-first, advances the cursor
 * to the newest event's created timestamp.
 *
 * Why timestamp-based instead of ending_before=<event_id>:
 *   Stripe events created within the same second have arbitrary suffix
 *   order in their IDs. The previous ending_before=<cursor_id> strategy
 *   could leapfrog sibling events — observed in dev where evt_1TY8AC…
 *   (an invoice.paid) was permanently skipped because the cursor advanced
 *   to evt_1TY8Ax… in the same second. The SAFETY overlap window + the
 *   existing lg_processed_events dedup table together guarantee no event
 *   is silently lost: any event in the window gets fetched at least once,
 *   and per-event re-handling is idempotent (the dup_count column tracks
 *   how often this overlap kicks in).
 */
final class Poller
{
    private const SOURCE                 = 'stripe';
    private const PAGE_LIMIT             = 100;
    private const SAFETY_WINDOW_SECONDS  = 60;   // overlap; absorbs same-second cursor leapfrog
    private const MAX_PAGES_PER_TICK     = 3;    // 3 × 100 events max per tick; remainder via status=partial

    public function __construct(
        private readonly Client       $stripe,
        private readonly EventHandler $handler,
    ) {}

    /**
     * @return array{processed:int, cursor:?string, status:string, log:string[]}
     */
    public function poll(): array
    {
        $log    = [];
        $stored = $this->loadCursor();

        // First run — stamp current time and stop.
        if ( $stored === null ) {
            $now = (string) time();
            $this->saveCursor( $now, 'first_run', null );
            return [
                'processed' => 0,
                'cursor'    => $now,
                'status'    => 'first_run',
                'log'       => [ "first_run: cursor stamped to {$now}" ],
            ];
        }

        // Decode cursor. Accept both new (numeric timestamp) and legacy
        // ("evt_…" event id) formats so an in-place deploy survives without
        // a migration step. Legacy cursors fall back to "1 hour ago" — the
        // dedup table absorbs the re-processing.
        if ( preg_match( '/^\d+$/', $stored ) ) {
            $cursorTs = (int) $stored;
        } else {
            $cursorTs = time() - 3600;
            $log[]    = "legacy cursor {$stored} detected; falling back to created>=" . ( $cursorTs - self::SAFETY_WINDOW_SECONDS );
        }
        $sinceTs = max( 0, $cursorTs - self::SAFETY_WINDOW_SECONDS );

        // Fetch events with created>=sinceTs. Paginate up to MAX_PAGES_PER_TICK
        // pages within this tick; if more events remain, status=partial and
        // the next tick will resume from the advanced cursor.
        $allEvents     = [];
        $startingAfter = null;
        $hasMore       = false;
        $lastListObj   = null;
        try {
            for ( $i = 0; $i < self::MAX_PAGES_PER_TICK; $i++ ) {
                $params = [
                    'limit'   => self::PAGE_LIMIT,
                    'created' => [ 'gte' => $sinceTs ],
                ];
                if ( $startingAfter !== null ) {
                    $params['starting_after'] = $startingAfter;
                }
                $list        = $this->stripe->listEvents( $params );
                $lastListObj = $list;
                $events      = $list->data ?? [];
                if ( ! $events ) {
                    break;
                }
                $allEvents    = array_merge( $allEvents, $events );
                $startingAfter = (string) end( $events )->id;
                $hasMore       = (bool) ( $list->has_more ?? false );
                if ( ! $hasMore ) {
                    break;
                }
            }
        } catch ( Throwable $e ) {
            $this->saveCursor( $stored, 'error', $e->getMessage() );
            throw $e;
        }

        // Stripe returns newest-first; reverse so we process chronologically.
        $events    = array_reverse( $allEvents );
        $processed = 0;
        $newestTs  = $cursorTs;

        foreach ( $events as $event ) {
            $log[]    = sprintf( '%s %s', $event->id, $this->handler->handle( $event ) );
            $newestTs = max( $newestTs, (int) ( $event->created ?? 0 ) );
            $processed++;
        }

        $status = $hasMore ? 'partial' : 'ok';
        $this->saveCursor( (string) $newestTs, $status, null );

        return [
            'processed' => $processed,
            'cursor'    => (string) $newestTs,
            'status'    => $status,
            'log'       => $log,
        ];
    }

    private function loadCursor(): ?string
    {
        $stmt = Db::pdo()->prepare(
            'SELECT cursor_id FROM lg_event_cursor WHERE source = ? LIMIT 1'
        );
        $stmt->execute( [ self::SOURCE ] );
        $val = $stmt->fetchColumn();
        return ( $val !== false && $val !== null && $val !== '' ) ? (string) $val : null;
    }

    private function saveCursor(?string $cursor, string $status, ?string $error): void
    {
        Db::pdo()->prepare(
            'INSERT INTO lg_event_cursor (source, cursor_id, last_polled, last_status, last_error)
             VALUES (?, ?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE
                 cursor_id   = VALUES(cursor_id),
                 last_polled = VALUES(last_polled),
                 last_status = VALUES(last_status),
                 last_error  = VALUES(last_error)'
        )->execute( [ self::SOURCE, $cursor, $status, $error ] );
    }
}
