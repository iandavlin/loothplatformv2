-- profile-app — NOTIFICATIONS: DELETE BECOMES DISMISS.
--
-- Lane: notif-bridge (2026-08-08). Ian's ruling, recorded in the lane charter and
-- cited by docs/IAN-RULINGS-2026-08-03.md §7: "the delete=dismiss ruling ... it
-- counts unread AND undismissed".
--
-- ── WHY ──────────────────────────────────────────────────────────────────────
-- `notifications` does two incompatible jobs: it is (i) a transient inbox the
-- member is invited to empty, and (ii) the weekly recap's only record of the week.
-- Emptying (i) destroys (ii). Measured, not theorised — recap-notif-bridge's trace
-- (docs/atlas/RECAP-NOTIF-BRIDGE-TRACE.md §1d) reconciled 17 internal/notify calls
-- on 2026-07-31, all of which returned raised:true, against the 12 rows of that day
-- that still exist. Five were destroyed within hours, four of them Ian's, and his
-- recap came up empty as a direct result.
--
-- After this: dismissing keeps the row and stamps `dismissed_at`. The bell hides it
-- immediately and permanently. The recap still counts it while it is unread AND
-- undismissed.
--
-- ── ⚠️ THE TRAP THIS MIGRATION EXISTS TO DEFUSE ──────────────────────────────
-- `uq_notifications_target_unread` is the coalescing index, scoped `WHERE
-- target_kind IS NOT NULL AND is_read = false`. TODAY a dismissal is a DELETE, so
-- the row leaves the index and a later reply to the same target rings a fresh row.
--
-- Keep the row instead and it STAYS in that index — dismissed but still unread. The
-- next reply to that target would then ON CONFLICT DO UPDATE the row the member has
-- already dismissed and hidden, and they would never be told. A UI dismissal would
-- silently become PERMANENT DEAFNESS for that discussion.
--
-- That is a worse defect than the one being fixed, and it would ship green: rows are
-- raised, `raised:true` is returned, and nothing is visibly missing. So the index
-- predicate gains `AND dismissed_at IS NULL` — a dismissed row stops arbitrating,
-- and the next event rings a NEW row. Asserted red-first by
-- profile-app/bin/notif-dismiss-proof.php phase 4.
--
-- ── ⚠️ DEPLOY ORDER — AND THE TRAP IN IT THAT NEARLY SHIPPED ────────────────
-- The first cut of this feature gated the SQL TEXT on the flag, so that "flag OFF
-- emits the pre-existing statements and is therefore safe to deploy before the
-- migration". The red-first refuted that on its FIRST RUN:
--
--     SQLSTATE[42P10]: there is no unique or exclusion constraint matching
--     the ON CONFLICT specification
--
-- Postgres infers an arbiter index whose predicate is IMPLIED BY the ON CONFLICT
-- WHERE clause. Implication runs ONE WAY:
--
--     three-term arbiter (A∧B∧C)  =>  two-term index   (A∧B)      matches, fine
--     two-term  arbiter (A∧B)     =>  three-term index (A∧B∧C)    NO MATCH, throws
--
-- So once this migration narrows the index, ANY code still emitting the old
-- two-term clause throws on every hub push and every notification is silently lost.
-- Flag-OFF was not the safe state — on a migrated box it was the BROKEN one, and
-- running this file on live would have taken the bell out on the spot.
--
-- ⚠️⚠️ AND THE ORDER IS NOT FREE. I WROTE "EITHER ORDER IS SAFE" HERE AND IT WAS
-- WRONG — PROVEN ON DEV2, 2026-08-09, BY BREAKING IT.
--
-- The code fix (Notifications::schemaHasDismiss(), a cached catalog lookup) makes
-- THIS LANE'S code work against either index shape. It does nothing whatever for the
-- code already deployed. I applied this migration to dev2 while the serving checkout
-- was still on `main`, and every hub notification on the box died instantly:
--
--     POST /profile-api/v0/internal/notify  ->  500  {"ok":false,"error":"db_error"}
--
-- Silently, because lg_notify_push() swallows its own errors by contract. It stayed
-- broken until the index was reverted, and nothing anywhere would have told anyone.
--
--   ✅ SAFE ORDER:   deploy the code, THEN apply this migration, THEN flip the flag.
--   ❌ WHAT I DID:   migration first, against old code. Bell dead, no alarm.
--
-- So: **DO NOT RUN THIS ON LIVE UNTIL THE CODE IS DEPLOYED THERE.** Live pulls all of
-- main, so the check is `grep -c schemaHasDismiss /srv/profile-app/src/Notifications.php`
-- on the live box — 0 means STOP.
--
-- The transitional state is genuinely safe in the other direction, which is what
-- dev2 sits in now: COLUMN present, index still two-term. Old code emits two-term
-- against a two-term index and works; new code emits three-term against a two-term
-- index and ALSO works, because A^B^C implies A^B. Only the narrowed index is
-- exclusive. That is why the column and the index are separable, and why reverting
-- just the index was enough to bring dev2's bell straight back.
--
-- All four pairings are asserted in profile-app/bin/notif-dismiss-proof.php phase 5,
-- including the failing one — because only proving the working direction is exactly
-- how this got reasoned about wrongly the first time, twice.
--
-- APPLY (dev2 — the table is owned by the `profile-app` role, peer auth):
--     sudo -u profile-app psql -d profile_app -f 2026-08-08-notification-dismiss.sql
--
-- APPLY (LIVE — Ian runs this; all live writes are his):
--     sudo -u profile-app psql -d profile_app -f \
--       /home/ubuntu/loothplatformv2-clean/profile-app/sql/2026-08-08-notification-dismiss.sql
--
-- Idempotent: re-running is a no-op. Reversible: DOWN block at the foot.

BEGIN;

-- ---------- before ----------
-- Printed so the apply has a receipt. `dismissed` is expected to ERROR as an
-- unknown column on a first run; that is the point of running the after-block too.
\echo '--- BEFORE ---'
SELECT count(*) AS total_rows,
       count(*) FILTER (WHERE is_read = false)        AS unread,
       count(*) FILTER (WHERE target_kind IS NOT NULL) AS hub_rows
  FROM notifications;

-- ---------- the column ----------
-- A TIMESTAMP, not a boolean. "When did they dismiss it" is free here and is the
-- difference between being able to answer "did the recap drop this because the
-- member dismissed it after the window closed?" and guessing. NULL = not dismissed,
-- and NULL is the overwhelmingly common state, so the partial indexes below stay
-- small.
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS dismissed_at timestamptz;

-- ---------- the coalescing index, made dismissal-aware ----------
-- See "THE TRAP" above. Dropping and recreating rather than editing, because a
-- partial index's predicate cannot be altered in place.
DROP INDEX IF EXISTS uq_notifications_target_unread;
CREATE UNIQUE INDEX IF NOT EXISTS uq_notifications_target_unread
    ON notifications (user_uuid, type, target_kind, target_id, COALESCE(anchor_id, 0))
    WHERE target_kind IS NOT NULL AND is_read = false AND dismissed_at IS NULL;

-- ---------- the bell's own read path ----------
-- listFor()/unreadCount() filter on `dismissed_at IS NULL`; the existing
-- idx_notifications_unread does not carry it. Same shape, dismissal-aware, so the
-- badge count stays an index-only scan.
CREATE INDEX IF NOT EXISTS idx_notifications_live
    ON notifications (user_uuid)
    WHERE is_read = false AND dismissed_at IS NULL;

-- ---------- after ----------
\echo '--- AFTER (dismissed must be 0: this migration dismisses nothing) ---'
SELECT count(*) AS total_rows,
       count(*) FILTER (WHERE is_read = false)          AS unread,
       count(*) FILTER (WHERE dismissed_at IS NOT NULL) AS dismissed,
       count(*) FILTER (WHERE target_kind IS NOT NULL)  AS hub_rows
  FROM notifications;

COMMIT;

-- ---------------------------------------------------------------------------
-- ACCEPTANCE — total_rows and unread must be IDENTICAL before and after, and
-- dismissed must be 0. This migration adds a column and reshapes two indexes; it
-- must not move a single row. If total_rows changed, something else was writing
-- concurrently — that is expected on a live box and is not a failure of this file,
-- but the delta should be small and explainable from the access log.
--
-- ---------------------------------------------------------------------------
-- DOWN (reversible; loses only WHICH rows were dismissed, never the rows):
--
--   BEGIN;
--   DROP INDEX IF EXISTS idx_notifications_live;
--   DROP INDEX IF EXISTS uq_notifications_target_unread;
--   -- ⚠️ REQUIRED, and not optional — see the hazard note below.
--   DELETE FROM notifications WHERE dismissed_at IS NOT NULL AND is_read = false;
--   CREATE UNIQUE INDEX uq_notifications_target_unread
--       ON notifications (user_uuid, type, target_kind, target_id, COALESCE(anchor_id, 0))
--       WHERE target_kind IS NOT NULL AND is_read = false;
--   ALTER TABLE notifications DROP COLUMN IF EXISTS dismissed_at;
--   COMMIT;
--
-- ⚠️ THE ROLLBACK IS NOT SYMMETRIC, AND THE DELETE ABOVE IS WHY. Found by the proof
-- script failing on this exact step. Under the three-term index a target may hold
-- TWO unread rows — one dismissed, one fresh — which is the whole point of phase 4
-- (a dismissal must not deafen you to the next reply). The two-term index forbids
-- that pair, so recreating it raises:
--
--     SQLSTATE[23505]: could not create unique index … Key (…)=(…) is duplicated
--
-- and the DOWN block fails half-applied. Dismissed-and-unread rows must go first.
-- They are precisely the rows the member already swept out of their bell, so
-- deleting them restores the pre-migration meaning of "dismissed" — gone — but SAY
-- SO to Ian before running it, because it IS a destructive step and the rest of this
-- file is not.
--
-- ⚠️ SET `dismiss_instead_of_delete => false` BEFORE ROLLING BACK. The SQL text
-- follows the schema automatically, but the ENDPOINT still routes DELETE to
-- dismiss() while the flag is true, and dismiss() writes a column that would no
-- longer exist. Flag first, then this.
--
-- ⚠️ AND NOTE WHAT ELSE ROLLING BACK MEANS: any row dismissed while the flag was on
-- that has since been READ becomes visible in the bell again — "hidden" was only ever
-- `dismissed_at IS NULL`. Some old rows reappear. Expected, not a fault.
-- ---------------------------------------------------------------------------
