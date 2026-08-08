<?php
/**
 * follow-digest — THE TRACKED CONFIG. This file decides who can receive a digest.
 *
 * It is the whole recipient-safety story in one place, and it arrives on a box by
 * `git pull` and nothing else. Read platform/mu-plugins/lg-follow-digest.php for how
 * it is consumed, and tools/gates/follow-digest-gate.py for what is asserted about it.
 *
 * ── WHY A PHP FILE AND NOT AN ENV VAR ────────────────────────────────────────
 * The obvious home for a flag on this platform is the FPM pool conf
 * (platform/fpm/live/bb-mirror.conf carries LG_BB_MIRROR_FOLLOW that way). For THIS
 * feature that would be a silent-nothing bug, and it was measured, not guessed:
 *
 *     the digest is sent by CRON, and the cron context has NO ENVIRONMENT.
 *
 * lg-wp-cron.service is `ExecStart=/usr/local/bin/wp cron event run --due-now` with no
 * `Environment=` line at all — verified on dev2 with `systemd-run --uid=looth-dev
 * /usr/bin/env`, which prints PATH and nothing else. So getenv('LG_FOLLOW_DIGEST') is
 * FALSE inside the sender however the FPM pool is configured. An env-only flag would
 * let web requests arm the cron event (init runs in FPM, where the flag IS visible)
 * while the cron that fires it no-ops forever: the event exists, the log is silent,
 * and nobody ever gets mail. That is precisely the "silent nothing" this lane exists
 * to prevent, rebuilt in the deployment layer.
 *
 * A tracked PHP file read relative to __DIR__ is visible in EVERY context — FPM,
 * WP-CLI, cron, a one-shot — because it is the same file on disk. And because the
 * mu-plugin symlink already resolves into this repo, this file needs NO symlink of its
 * own and NO daemon-reload: it lands with the pull. (Contrast the systemd unit, which
 * is a real file at /etc/systemd/system/, so a tracked edit there would NOT arrive by
 * pull — it needs a root copy and a daemon-reload.)
 *
 * ── ⚠️ DO NOT KEY ANYTHING HERE ON LG_ENV ────────────────────────────────────
 * LIVE'S /etc/looth/env SAYS `LG_ENV=dev2`. Verified over ssh live-ro on 2026-07-31.
 * An env-keyed map would apply the dev2 row on live — the "verify the thing, not the
 * thing next to it" trap, with an unrecallable channel on the other end of it. The
 * honest tell for live is LG_PUBLIC_HOST=loothgroup.com (dev2 says dev2.loothgroup.com),
 * and this file deliberately does not branch on the environment at all: one value, the
 * same on every box, so what you read here is what every box does.
 */

return array(

	/**
	 * THE MASTER SWITCH for the sender. false ⇒ no cron event is registered, the
	 * suppression filter is the identity function, and the resolver returns nobody.
	 *
	 * ⚠️ THIS GOVERNS AN UNRECALLABLE CHANNEL. It is not flipped because the gates are
	 * green — gates assert what is PRESENT and are blind to what should be ABSENT, which
	 * is where all six of Ian's misses lived. It flips when Ian says so.
	 *
	 * Turning this on WITHOUT widening 'allowlist' below is the intended controlled
	 * state: the real cron, the real batching, the real data — mailing one person.
	 *
	 * ── ON, 2026-08-01, in exactly that controlled state ─────────────────────────
	 * Ian asked three times for the cadence control on live ("ship it", then "still no
	 * cadence" twice). LG_FOLLOWING_CADENCE (nginx, eec21c9) was only the FIRST of two
	 * gates: the picker also asks lg_fd_cadence_ui_enabled(), which returns false while
	 * this switch is off — by design, because a cadence picker that no sender honours is
	 * the UI-lies class. So the control could not appear until this flipped.
	 *
	 * 'allowlist' below is UNCHANGED and still pins user 1. Consequence to state plainly
	 * rather than discover: the cadence control is visible ONLY to Ian, because
	 * lg_fd_cadence_ui_enabled() gates on lg_fd_allowed() too. Any other member's
	 * Manage Account page is byte-identical to before. Widening to the membership is a
	 * separate one-line diff and a separate decision.
	 */
	'enabled' => true,

	/**
	 * THE RECIPIENT ALLOWLIST — the answer to the only question that matters here,
	 * which is "could this send to someone who is not on it?"
	 *
	 * GRAMMAR (parsed by lg_fd_allowlist(); every case below is in the gate's table):
	 *
	 *   ''                          NOBODY. The default, and the failure mode.
	 *   '1'                         WP user 1, whatever address they currently hold.
	 *   '1:ian.davlin@gmail.com'    WP user 1, and ONLY if their address is exactly
	 *                               that. The pinned form — prefer it.
	 *   '1,7'                       several members.
	 *   'all-members'               EVERYONE. See the warning below.
	 *
	 * ⚠️ IT FAILS CLOSED, AND "CLOSED" MEANS NOBODY — NEVER THE REAL LIST. An empty
	 * value, an unreadable file, a malformed token, a stray '*', a bare email address:
	 * every one of them resolves to ZERO recipients, not to "unfiltered". A malformed
	 * allowlist makes the digest send nothing and say so loudly in the log; it can never
	 * make it send to everyone. That asymmetry is the entire design.
	 *
	 * WHY THE PINNED FORM IS WORTH THE EXTRA TYPING: '1' alone trusts that user 1 is
	 * who you think. Pinning the address means that if the account's email is ever
	 * changed, the digest stops rather than mailing whoever holds it now. On live,
	 * user 1 is iandavlin <ian.davlin@gmail.com> — verified over ssh live-ro 2026-07-31,
	 * and on dev2 it is the same person, so the pinned value below is correct on both.
	 *
	 * ⚠️ 'all-members' IS THE ONLY WAY TO REACH THE MEMBERSHIP, and it is deliberately
	 * a word rather than a symbol so that it cannot be produced by a parsing accident —
	 * splitting '' on ',' yields array(''), and no amount of that becomes 'all-members'.
	 * The gate asserts this file does not contain it. Setting it is a separate, visible,
	 * reviewable one-line diff and is Ian's decision, not a lane's.
	 */
	/* ── GENERAL RELEASE, 2026-08-08 ──────────────────────────────────────────────
	 * Ian ruled "widen to all members" on 8/3 and confirmed the flip on 8/8 after the
	 * pre-flight lifecycle passed on dev2 under this exact value: a never-touched
	 * member resolves cadence 'daily' (his 8/8 ruling), their FIRST event is delivered
	 * instantly and enrols them, their second is suppressed into the daily roundup,
	 * and the resolver returns them due. docs/IAN-RULINGS-2026-08-03.md §4 is the
	 * recorded decision — the gate now REQUIRES that ruling to exist for this value
	 * to be green, so the licence and the config cannot drift apart.
	 *
	 * Blast radius at flip time, measured: 381 members hold topic subscriptions; each
	 * gets ≤1 roundup/day, ≤500/run, allowlist re-checked at the send layer. Kill is
	 * 'enabled' => false, arriving by pull. */
	'allowlist' => 'all-members',
);
