<?php
/**
 * notifications — THE TRACKED CONFIG for what "read" means on the bell.
 *
 * It decides whether marking notifications read is scoped to the rows the member
 * actually SAW, or sweeps their whole store the way it does today. It arrives on a
 * box by `git pull` and nothing else. Consumed by
 * `profile-app/src/Notifications.php` (readSeenOnly()); asserted by
 * `tools/gates/notif-read-seen-gate.py`.
 *
 * ── WHY A PHP FILE AND NOT AN ENV VAR ────────────────────────────────────────
 * Same reasoning as platform/config/follow-digest.php, and the same three
 * measured traps behind it:
 *
 *   - `fastcgi_param` does NOT land in getenv() — only in $_SERVER. A flag set
 *     that way serves OFF to a getenv() reader on the very URL built to preview it.
 *   - WP cron carries NO environment at all (lg-wp-cron.service has no
 *     `Environment=`), so an FPM-pool flag is invisible to anything cron-driven.
 *   - LIVE'S /etc/looth/env SAYS `LG_ENV=dev2`, so nothing here may branch on the
 *     environment name. This file deliberately does not branch at all: one value,
 *     the same on every box, so what you read here is what every box does.
 *
 * `/srv/profile-app` is a symlink into the monorepo, so `__DIR__` resolves here in
 * EVERY context — FPM, CLI, cron, a one-shot — because it is the same file on disk.
 * It needs no symlink of its own and no reload: it lands with the pull.
 *
 * ── ⚠️ THIS GOVERNS WHAT THE WEEKLY RECAP CONTAINS ───────────────────────────
 * The recap is "what you missed — UNREAD ONLY" (Ian, docs/IAN-RULINGS-2026-08-03.md
 * §1). `is_read` is therefore not a display detail; it is the recap's entire input,
 * and under "empty means send no email" a spurious read CANCELS a member's digest.
 * Read docs/RECAP-READ-TIMER.md before changing anything here.
 */

return array(

	/**
	 * OFF: marking read sweeps the member's ENTIRE store (today's behaviour,
	 *      unchanged — `read_seen` is serviced by the same markAllRead() SQL that
	 *      `read_all` has always run, so OFF is a no-op on the store).
	 * ON:  only the rows the member actually SAW are marked read. "Saw" is defined
	 *      in docs/RECAP-READ-TIMER.md §Boundary and is asserted in both
	 *      directions by tools/gates/notif-read-seen-gate.py — including the
	 *      absent half, that rows the member did NOT see are STILL UNREAD.
	 *
	 * Defaulted OFF because this is member-facing and every member-facing change
	 * merges behind a flag defaulted OFF (CLAUDE.md). OFF lets the code reach the
	 * dev2 serve harmlessly so it can be verified on the running thing; it is
	 * flipped when Ian has looked at it, not when the gates are green.
	 */
	'read_seen_only' => false,

	/**
	 * Hard ceiling on how many ids one `read_seen` call may mark, and on the GET
	 * feed's `?limit=`. Bounds the query a client can ask for; it is NOT a policy
	 * knob. 200 sits well above any real store: retention is 30 days and enforced
	 * (prune-notifications.timer is live on this box), and the largest holding
	 * measured on dev2 was 4 rows.
	 *
	 * It does leave a residual, stated rather than hidden: a member carrying more
	 * than 200 notifications inside 30 days would have an unread tail the sheet
	 * cannot render, so their badge could not reach zero by reading alone. The
	 * existing "Clear" button (a real DELETE, member-initiated) is the escape.
	 */
	'max_ids' => 200,

	/* ── notif-bridge (E4), 2026-08-09 ─────────────────────────────────────
	   Added by a different lane than the two keys above. Notifications::cfg()
	   merges unknown keys through `$got + $defaults` and Flags::bool() reads the
	   whole array, so the three coexist — but DELETE NONE OF THEM on a future
	   conflict: each one silently disables a shipped, gated behaviour. */
    /**
     * DELETE = DISMISS (Ian, 2026-08-08).
     *
     * false  — TODAY'S BEHAVIOUR, unchanged and byte-identical. The bell's × and
     *          Clear-all really DELETE the row, and the weekly recap loses that
     *          event forever (leak A, docs/atlas/RECAP-NOTIF-BRIDGE-TRACE.md §1d).
     * true   — the row is KEPT and stamped `dismissed_at`. It leaves the bell
     *          immediately and permanently; the recap still counts it while it is
     *          unread AND undismissed.
     *
     * ⚠️ FLIP THIS ONLY AFTER THE MIGRATION HAS BEEN APPLIED.
     * `true` makes the endpoint call dismiss(), which writes the `dismissed_at`
     * column added by profile-app/sql/2026-08-08-notification-dismiss.sql (Ian runs
     * it on live). Before that migration, `true` means every dismissal throws.
     *
     * ⚠️ AND NOTE WHAT THIS FLAG DOES *NOT* CONTROL — this was got wrong once and
     * the red-first caught it. It does not decide the SQL. The statements follow the
     * DATABASE (Notifications::schemaHasDismiss()), because the ON CONFLICT arbiter
     * predicate must match the unique index that actually exists; gating the SQL on
     * this flag instead meant a migrated box running flag-OFF code threw
     * SQLSTATE[42P10] on every hub push and lost every notification. Behaviour here,
     * schema there, and the two deploy in either order.
     *
     * The OFF state is gated: tools/gates/notif-dismiss-gate.sh, and the full
     * red-first is profile-app/bin/notif-dismiss-proof.php (36 assertions, both flag
     * states, all four arbiter/index pairings including the failing one).
     */
    'dismiss_instead_of_delete' => false,

);
