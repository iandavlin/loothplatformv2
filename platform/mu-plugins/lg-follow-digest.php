<?php
/**
 * Plugin Name: LG — Follow digest (Daily / Weekly discussion email)
 * Description: Batches replies in followed discussions into one Daily or Weekly email, and suppresses the native per-reply send for members who chose to be batched. Flagged OFF; the OFF state is a proven no-op.
 * Author: follow-digest lane
 * Version: 0.1
 *
 * DESIGN: docs/atlas/FOLLOW-DIGEST-DESIGN.md. GATE: tools/gates/follow-digest-gate.py.
 * SPEC context: docs/atlas/THREAD-FOLLOW-SPEC.md §15.3-15.5 (the questions this answers).
 *
 * ── WHAT THIS IS FOR ─────────────────────────────────────────────────────────
 * thread-follow built a frequency control and shipped it DARK (FREQ_ENABLED=false)
 * because "storing a cadence does nothing without a batcher, and there is no sender."
 * This is the sender. Ian, 2026-07-31: build the batcher so Daily/Weekly really work,
 * and cadence is ONE ACCOUNT-LEVEL setting, not per-thread.
 *
 * ── ⚠️ EMAIL IS UNRECALLABLE — read this before editing anything below ───────
 * A bug here does not render wrong. It lands in real members' inboxes and cannot be
 * taken back. Three rules govern every line:
 *
 *   1. FLAG OFF IS A PROVEN NO-OP. Not "should be" — proven. With the flag off the
 *      suppression filter is the IDENTITY function and no cron event is registered
 *      at all. tools/gates/follow-digest-gate.py asserts both, and asserts them
 *      NON-VACUOUSLY (it proves the machinery is live before asserting absence).
 *   2. PROVE THE RECIPIENT SET BEFORE THE CONTENT. Sending the right email to the
 *      wrong people is worse than sending nothing.
 *   3. A GREEN TEST ON DEV2 IS NOT EVIDENCE OF A SEND. lg-dev-mail-containment.php
 *      swallows wp_mail into mailpit and RETURNS TRUE. Assert on the recipient set
 *      and the store, never on "wp_mail returned true".
 *
 * ── THE ONE THING THAT WOULD DO REAL DAMAGE ──────────────────────────────────
 * A member turning Daily on has no watermark. Read naively that is epoch, and their
 * first digest is the ENTIRE reply history of every thread they follow — one live
 * account holds 335 subscriptions. So: the watermark is stamped to NOW at the moment
 * cadence is first written (lg_fd_set_cadence), and the collector REFUSES to run
 * without one. A digest is never a backfill. See §4.3 of the design note.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ── THE TRACKED CONFIG ────────────────────────────────────────────────────────
 * platform/config/follow-digest.php carries the master switch AND the recipient
 * allowlist. Read that file first — it explains why this is a PHP file and not an
 * FPM env var, and the reason is not taste:
 *
 *   ⚠️ THE DIGEST IS SENT BY CRON, AND THE CRON CONTEXT HAS NO ENVIRONMENT.
 *
 * lg-wp-cron.service carries no `Environment=` line; `systemd-run --uid=looth-dev
 * /usr/bin/env` on dev2 prints PATH and nothing else. So an env-only flag is visible
 * to FPM (which arms the cron event on `init`) and INVISIBLE to the cron that fires
 * it — the event exists, the log stays silent, and nobody is ever mailed. Measured on
 * 2026-07-31, and it is the reason this seam changed shape.
 *
 * __DIR__ resolves through the mu-plugin symlink into the REPO, so this lands with a
 * plain `git pull`: no symlink of its own, no daemon-reload, no root. Unreadable ⇒ the
 * defaults below, which are the SAFE ones (off, and nobody). */
function lg_fd_config(): array {
	static $cfg = null;
	if ( null !== $cfg ) { return $cfg; }

	// The safe defaults are the FAILURE defaults, deliberately: anything that goes
	// wrong reading the config leaves the sender off and the allowlist empty.
	$cfg  = array( 'enabled' => false, 'allowlist' => '' );
	$path = dirname( __DIR__ ) . '/config/follow-digest.php';
	if ( ! is_readable( $path ) ) {
		error_log( '[lg-fd] tracked config unreadable at ' . $path
			. ' — sender OFF and allowlist EMPTY (fail-closed default)' );
		return $cfg;
	}
	$raw = require $path;
	if ( ! is_array( $raw ) ) {
		error_log( '[lg-fd] tracked config did not return an array — fail-closed' );
		return $cfg;
	}
	$cfg = array(
		// Strictly boolean true, never a truthy string: a config that says "false"
		// (the string) must not read as on.
		'enabled'   => ( true === ( $raw['enabled'] ?? false ) ),
		'allowlist' => is_string( $raw['allowlist'] ?? null ) ? $raw['allowlist'] : '',
	);
	return $cfg;
}

/* ── THE FLAG ──────────────────────────────────────────────────────────────────
 * Defaulted OFF, copying LG_THREAD_FOLLOW_ENABLED (bb-mirror/config.php:461) and
 * LG_AUTHOR_SOCIALS_ALL_MEMBERS. Two ways on, both deliberate:
 *   - the tracked config above (the one that reaches CRON, so the one that matters)
 *   - LG_FOLLOW_DIGEST=1 in the environment, for gate runs and one-shots
 *
 * ⚠️ THIS FLAG GOVERNS AN UNRECALLABLE CHANNEL. It is not flipped by this lane and
 * not flipped because the gates are green. It flips when the batcher is proven to
 * deliver, keeper takes that to Ian, and Ian says so.
 *
 * ⚠️ AND THE FLAG IS NOT THE RECIPIENT CONTROL. Turning it on sends to whoever the
 * ALLOWLIST admits — which defaults to nobody. Two independent switches, because one
 * switch means "on" and "on to everyone" are the same act. */
if ( ! defined( 'LG_FOLLOW_DIGEST_ENABLED' ) ) {
	define( 'LG_FOLLOW_DIGEST_ENABLED',
		lg_fd_config()['enabled']
		|| getenv( 'LG_FOLLOW_DIGEST' ) === '1'
		|| ( ( $_SERVER['LG_FOLLOW_DIGEST'] ?? '' ) === '1' ) );
}

/* ── THE RECIPIENT ALLOWLIST ───────────────────────────────────────────────────
 * Ian, 2026-07-31: a CONTROLLED end-to-end test — the real cron, the real batching,
 * his real followed threads, with the recipient set hard-locked to himself before it
 * ever touches the membership.
 *
 * "Prove the recipient set before the content" is already this file's second rule.
 * The allowlist is what makes it enforceable rather than aspirational, and the only
 * question it has to answer is:
 *
 *     ⚠️ COULD THIS SEND TO SOMEONE WHO IS NOT ON THE LIST?
 *
 * It is answered structurally, in three places, so that no single mistake is enough:
 *
 *   1. lg_fd_due_recipients() drops non-allowlisted members, so no batch is even
 *      built for them and no work touches their rows.
 *   2. lg_fd_send_one() re-checks before rendering, and a blocked member's WATERMARK
 *      DOES NOT MOVE — see the note there, it is the subtle one.
 *   3. lg_fd_deliver() re-checks at the send layer and is the ONLY wp_mail() call
 *      site in this file. The gate asserts that uniqueness, so a future code path
 *      cannot route around the allowlist without the gate going red.
 *
 * IT FAILS CLOSED, AND CLOSED MEANS NOBODY — NEVER THE REAL LIST. Every degenerate
 * input (empty, unreadable, malformed, '*', a bare email, a negative id) resolves to
 * ZERO recipients. There is no input that turns a broken allowlist into an open one;
 * the only way to reach the membership is the literal word below. */

/** The one token that means everyone. A WORD, not a symbol: splitting '' on ',' gives
 *  array(''), and no parsing accident produces this string. The gate asserts the
 *  tracked config does not contain it. */
const LG_FD_ALLOW_ALL = 'all-members';

/**
 * Parse the allowlist into a decision.
 *
 * @return array{mode:string,uids:int[],emails:array<int,string>,raw:string,reason:string}
 *         mode is 'none' (nobody), 'list' (these uids) or 'all' (everyone).
 *
 * ⚠️ ANY MALFORMED TOKEN VOIDS THE WHOLE LIST rather than being skipped. Dropping a
 * bad token silently changes who gets mail; refusing sends nobody and logs why, and
 * for an unrecallable channel those two outcomes are not close.
 *
 * Empty tokens ARE tolerated ('1,' and '1,,2'), because skipping an empty token
 * cannot ADD a recipient. That is the test applied to every leniency here: leniency
 * is allowed only where it is incapable of widening the set.
 */
function lg_fd_allowlist(): array {
	return lg_fd_parse_allowlist( lg_fd_allowlist_raw() );
}

/**
 * WHERE the allowlist value comes from. Split out from the grammar below so the gate
 * can exercise every degenerate input exhaustively without depending on what the
 * tracked config happens to say today — a grammar test that silently reads the real
 * config would pass for the wrong reason the day someone edits it.
 */
function lg_fd_allowlist_raw(): string {
	// The environment may override the tracked config — for gate runs and one-shots.
	// It is not a live risk: the cron context (the only thing that actually sends on
	// a real box) has no environment at all, which is the whole reason the tracked
	// config exists. It can only be read where somebody set it on purpose.
	$raw = getenv( 'LG_FOLLOW_DIGEST_ALLOWLIST' );
	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		$raw = (string) ( $_SERVER['LG_FOLLOW_DIGEST_ALLOWLIST'] ?? '' );
	}
	if ( '' === trim( $raw ) ) { $raw = lg_fd_config()['allowlist']; }
	return (string) $raw;
}

/**
 * The GRAMMAR, pure: a string in, a decision out. No environment, no config, no I/O —
 * so the gate's adversarial table is a complete statement about what this accepts.
 *
 * @return array{mode:string,uids:int[],emails:array<int,string>,raw:string,reason:string}
 */
function lg_fd_parse_allowlist( string $raw ): array {
	$none = function ( string $r, string $why ) {
		return array( 'mode' => 'none', 'uids' => array(), 'emails' => array(),
		              'raw'  => $r,  'reason' => $why );
	};

	$t = trim( $raw );
	if ( '' === $t ) { return $none( $t, 'empty — the fail-closed default, NOBODY' ); }
	if ( 0 === strcasecmp( $t, LG_FD_ALLOW_ALL ) ) {
		return array( 'mode' => 'all', 'uids' => array(), 'emails' => array(),
		              'raw'  => $t, 'reason' => 'the literal ' . LG_FD_ALLOW_ALL . ' token' );
	}

	$uids = array();
	$mail = array();
	foreach ( explode( ',', $t ) as $tok ) {
		$tok = trim( $tok );
		if ( '' === $tok ) { continue; }          // cannot widen; see the docblock

		$email = '';
		if ( false !== strpos( $tok, ':' ) ) {
			$parts = explode( ':', $tok, 2 );
			$tok   = trim( $parts[0] );
			$email = trim( $parts[1] );
			if ( ! is_email( $email ) ) {
				return $none( $t, sprintf( 'token "%s" pins an address that is not an email — VOID', $tok ) );
			}
		}
		// Strictly digits. is_numeric() would accept '1e2', ' 1' and '0x1'; intval()
		// would turn 'abc' into 0 and '1abc' into 1 — both are how a typo becomes a
		// recipient. Neither is used here on purpose.
		if ( ! preg_match( '/^[0-9]+$/', $tok ) ) {
			return $none( $t, sprintf( 'token "%s" is not a user id — VOID (a bare email is not valid; use uid or uid:email)', $tok ) );
		}
		$uid = (int) $tok;
		if ( $uid <= 0 ) { return $none( $t, sprintf( 'token "%s" is not a positive user id — VOID', $tok ) ); }

		$uids[] = $uid;
		if ( '' !== $email ) { $mail[ $uid ] = $email; }
	}
	if ( ! $uids ) { return $none( $t, 'no usable user ids — NOBODY' ); }

	return array( 'mode' => 'list', 'uids' => array_values( array_unique( $uids ) ),
	              'emails' => $mail, 'raw' => $t,
	              'reason' => sprintf( '%d allowlisted user id(s)', count( array_unique( $uids ) ) ) );
}

/**
 * May this member, at this address, be mailed a digest?
 *
 * ⚠️ BOTH ARGUMENTS ARE CHECKED, and that is not belt-and-braces — it is the case
 * where a uid-only allowlist quietly mails a stranger. If user 1's address is ever
 * changed, '1' alone still says yes; '1:ian.davlin@gmail.com' says no and the digest
 * stops. The address is checked against the one the CALLER is about to mail, so a
 * caller that passes the wrong WP_User is caught here too.
 *
 * Every path that is not an explicit allow returns FALSE. There is no fall-through.
 */
function lg_fd_allowed( int $uid, string $email ): bool {
	/* ⚠️ THE SANITY CHECK COMES BEFORE THE MODE, and the first draft had it after —
	 * which meant 'all-members' mode returned TRUE for uid 0 and for an address that
	 * is not an address, because the 'all' branch answered before anything was
	 * validated. Caught by the gate's decision table, not by reading the code. A
	 * nonsense subject is not a member, whatever the allowlist says. */
	if ( $uid <= 0 || ! is_email( $email ) ) { return false; }

	$a = lg_fd_allowlist();

	if ( 'all' === $a['mode'] ) { return true; }
	if ( 'list' !== $a['mode'] ) { return false; }       // 'none', or anything unknown
	if ( ! in_array( $uid, $a['uids'], true ) ) { return false; }

	$pinned = (string) ( $a['emails'][ $uid ] ?? '' );
	if ( '' !== $pinned && 0 !== strcasecmp( $pinned, $email ) ) { return false; }

	return true;
}

const LG_FD_CADENCE_META   = 'lg_disc_email_cadence';
const LG_FD_WATERMARK_META = 'lg_disc_digest_watermark';
const LG_FD_CRON_HOOK      = 'lg_fd_send';

/** The only deliverable cadences. Hourly is out on measurement (no member on live has
 *  ever had two forum notifications in the same hour); "Off" is out because the ✉
 *  toggle owns on/off. Anything not in this list is not a cadence. */
function lg_fd_cadences(): array {
	return array( 'instant', 'daily', 'weekly' );
}

/**
 * A member's cadence. ABSENT MEANS INSTANT — deliberately, and it is what makes the
 * flag's OFF state a no-op for the whole membership on day one: nobody carries a
 * cadence, so nobody is suppressed and nobody is batched.
 */
function lg_fd_cadence( int $user_id ): string {
	if ( $user_id <= 0 ) { return 'instant'; }
	$c = (string) get_user_meta( $user_id, LG_FD_CADENCE_META, true );
	return in_array( $c, lg_fd_cadences(), true ) ? $c : 'instant';
}

/**
 * Write a member's cadence. THE WATERMARK STAMP HERE IS THE FLOOD GUARD — it is the
 * single most consequential line in this file.
 *
 * Stamped on any transition INTO a batched cadence, not only the first write: a
 * member who goes daily → instant → daily has a stale watermark from the first
 * period, and honouring it would deliver weeks of already-read replies in one email.
 *
 * @return bool false if the cadence is not one we can actually deliver.
 */
function lg_fd_set_cadence( int $user_id, string $cadence ): bool {
	if ( $user_id <= 0 || ! in_array( $cadence, lg_fd_cadences(), true ) ) { return false; }

	$was = lg_fd_cadence( $user_id );
	update_user_meta( $user_id, LG_FD_CADENCE_META, $cadence );

	if ( 'instant' !== $cadence && $cadence !== $was ) {
		// NOW, never epoch. A digest is never a backfill.
		update_user_meta( $user_id, LG_FD_WATERMARK_META, gmdate( 'Y-m-d H:i:s' ) );
	}
	if ( 'instant' === $cadence ) {
		// Nothing to flush; leaving a stale watermark behind is what makes the
		// re-entry case above dangerous, so it goes with the cadence.
		delete_user_meta( $user_id, LG_FD_WATERMARK_META );
	}
	return true;
}

/** The watermark, or '' when absent. '' is a REFUSAL signal, not "the beginning of time". */
function lg_fd_watermark( int $user_id ): string {
	return (string) get_user_meta( $user_id, LG_FD_WATERMARK_META, true );
}

/* ── ONE STORE, ONE WRITE PATH, THREE SURFACES ─────────────────────────────────
 * Ian, 2026-07-31: the cadence control goes on the MANAGE ACCOUNT page as well as in
 * the follow modal. So one account-level setting now has three lanes touching it:
 *
 *   account-following  the control on /manage-subscription/   ─┐
 *   thread-follow      the control in the follow modal         ├─ two UI surfaces
 *   follow-digest      this file: the store, the writer, the   ─┘  one store
 *                      transports, and the sender that reads it
 *
 * ⚠️ IF THE TWO SURFACES CAN EVER DISAGREE ABOUT ONE MEMBER'S CADENCE, that is the
 * "UI lies" class, and it is found on Ian's phone within a minute. The defence is not
 * discipline, it is that NEITHER SURFACE CAN REACH THE STORE DIRECTLY: both go through
 * lg_fd_set_cadence() / lg_fd_cadence() above, and both transports live here.
 *
 * AND THE REASON IS CONCRETE, NOT ARCHITECTURAL TASTE: the flood guard (§4.3 — stamp
 * the watermark to NOW on entry to a batched cadence) lives INSIDE lg_fd_set_cadence.
 * A surface that writes the usermeta key directly skips it, and that member either
 * gets nothing forever (no watermark ⇒ the collector refuses) or, if someone later
 * "fixes" absent-means-epoch, gets the entire reply history of every thread they
 * follow in one unrecallable email. One write path is what keeps that impossible.
 *
 * BOTH TRANSPORTS SHARE THIS SCOPE. follow.php full-bootstraps WP
 * (`require LG_BB_MIRROR_WP_LOAD`, :43), so mu-plugin functions are available there
 * exactly as they are to admin-ajax. Verified, not assumed.
 *
 * THE DELTA THREAD-FOLLOW OWES follow.php — offered so it is not re-derived, and it
 * must call the writer rather than touching usermeta:
 *
 *     // GET, beside email_master:
 *     if (lg_fd_cadence_ui_enabled()) $out['cadence'] = lg_fd_cadence($uid);
 *     // POST:
 *     if (isset($body['cadence'])) {
 *         if (!lg_fd_cadence_ui_enabled()) follow_out(404, ['error' => 'not_enabled']);
 *         if (!lg_fd_set_cadence($uid, (string) $body['cadence']))
 *             follow_out(400, ['error' => 'bad_cadence']);
 *     }
 */

/**
 * THE SINGLE SOURCE OF TRUTH FOR WHETHER ANY SURFACE SHOWS THE CONTROL.
 *
 * Ian chose "show it when the batcher lands" over showing it now, so nobody ever picks
 * Daily and receives instant mail. Three surfaces must agree about that, and if each
 * checks its own condition they will eventually disagree — so they all check this.
 *
 * It is deliberately the SAME flag as the sender: the control cannot become visible
 * while the thing that honours it is off, because there is only one switch.
 */
/**
 * @param int|null $uid the member being asked about; null means the current user.
 *
 * ⚠️ PER-MEMBER SINCE THE ALLOWLIST LANDED, AND THAT IS NOT A REFINEMENT — IT IS THE
 * THING THAT MAKES A CONTROLLED LIVE TEST POSSIBLE AT ALL.
 *
 * While this returned a bare flag, switching the sender on for a test of ONE person
 * also painted the Daily/Weekly control for the WHOLE MEMBERSHIP. Any member picking
 * Daily would have been written a cadence, had their instant mail suppressed, and
 * then been blocked at the send layer — receiving NOTHING, from a control they had
 * just used. That is precisely the "member sets a preference and gets nothing" lie
 * §15.4 forbids, and the allowlist would have CAUSED it rather than prevented it.
 *
 * So the control is visible exactly to the members the sender would really serve.
 * Same single source of truth as before — every surface still asks this one function
 * — but it now asks the question that actually matters: not "is the feature on?" but
 * "is it on FOR YOU?".
 */
function lg_fd_cadence_ui_enabled( ?int $uid = null ): bool {
	if ( ! LG_FOLLOW_DIGEST_ENABLED ) { return false; }
	if ( null === $uid ) { $uid = get_current_user_id(); }
	if ( $uid <= 0 ) { return false; }
	$u = get_userdata( $uid );
	if ( ! $u || ! is_email( $u->user_email ) ) { return false; }
	return lg_fd_allowed( (int) $u->ID, (string) $u->user_email );
}

/* ── THE ACCOUNT-PAGE TRANSPORT ────────────────────────────────────────────────
 * Matches the idiom already on that page: admin-ajax actions consumed by wire()
 * (manage-subscription.php:202-215), alongside lg_weekly_member_state/toggle and
 * lg_event_reminder_state/signup (registered the same way in lg-event-reminders.php).
 *
 * REGISTERED ONLY WHILE THE FLAG IS ON. While off the actions do not exist, so the
 * control cannot be made to work by a client that renders it anyway — the hidden
 * state is enforced server-side, not merely by not drawing it. That absence is
 * gateable, which is the point.
 *
 * ⚠️ account-following: the existing wire() drives a CHECKBOX and this is a SEGMENTED
 * control, so it needs its own small wiring — read the state, paint the active pill,
 * POST on click, revert on failure. Do NOT write the usermeta key from the page. */
/* Registered while the FLAG is on, but each handler re-checks the CALLER against the
 * allowlist below. The two questions are different: the flag decides whether the
 * endpoint exists at all, the allowlist decides whether this member may use it. */
if ( LG_FOLLOW_DIGEST_ENABLED ) {
	add_action( 'wp_ajax_lg_fd_cadence_state', 'lg_fd_ajax_state' );
	add_action( 'wp_ajax_lg_fd_cadence_set', 'lg_fd_ajax_set' );
}

function lg_fd_ajax_state(): void {
	$uid = get_current_user_id();
	if ( $uid < 1 ) { wp_send_json( array( 'ok' => false ), 401 ); }
	// A member the sender would not serve must not be told the control exists — the
	// endpoint answering is what turns "not drawn" into "one stray render from live".
	if ( ! lg_fd_cadence_ui_enabled( $uid ) ) {
		wp_send_json( array( 'ok' => false, 'error' => 'not_enabled' ), 404 );
	}
	/* THE NONCE RIDES THE STATE RESPONSE — account-following, 2026-08-01.
	 *
	 * lg_fd_ajax_set() checks_ajax_referer('lg_fd_cadence'), and /manage-subscription/
	 * is a STANDALONE front controller with NO WP BOOT (its :3 says so), so that page
	 * cannot call wp_create_nonce() at render time. Without this line the transport
	 * built for that surface is unusable FROM that surface — the write would 403 every
	 * time, which is the "control that does nothing" §15.4 forbids, arriving by a
	 * different door.
	 *
	 * Handing it out HERE and not on the page is also the stricter placement: this
	 * endpoint has already refused non-served members two lines up, so the nonce only
	 * ever reaches someone whose write would be accepted anyway. It cannot widen who
	 * may write; it only lets the one surface that may write actually reach the writer.
	 *
	 * Additive, and scoped to the flag being on (the action is only registered under
	 * LG_FOLLOW_DIGEST_ENABLED, :402), so the OFF state is untouched. */
	wp_send_json( array( 'ok' => true, 'cadence' => lg_fd_cadence( $uid ),
	                     'options' => lg_fd_cadences(),
	                     'nonce' => wp_create_nonce( 'lg_fd_cadence' ) ) );
}

function lg_fd_ajax_set(): void {
	// Self-scoped ALWAYS: the acting user is the session's, never the body's. Same
	// posture as follow.php — there is no shape of request that changes another
	// member's cadence.
	$uid = get_current_user_id();
	if ( $uid < 1 ) { wp_send_json( array( 'ok' => false ), 401 ); }
	/* ⚠️ REFUSE THE WRITE, not just the render. A member who is not served by the
	 * sender must not be able to STORE a cadence: storing one suppresses their instant
	 * mail (see lg_fd_suppress_instant) while the send layer blocks their digest, so
	 * they would receive nothing at all. Refusing here is what keeps that impossible. */
	if ( ! lg_fd_cadence_ui_enabled( $uid ) ) {
		wp_send_json( array( 'ok' => false, 'error' => 'not_enabled' ), 404 );
	}
	if ( ! check_ajax_referer( 'lg_fd_cadence', 'nonce', false ) ) {
		wp_send_json( array( 'ok' => false, 'error' => 'bad_nonce' ), 403 );
	}
	$want = isset( $_POST['cadence'] ) ? sanitize_key( wp_unslash( $_POST['cadence'] ) ) : '';
	if ( ! lg_fd_set_cadence( $uid, $want ) ) {
		wp_send_json( array( 'ok' => false, 'error' => 'bad_cadence',
		                     'options' => lg_fd_cadences() ), 400 );
	}
	// Echo the STORED value, never the requested one — the surface repaints from
	// what actually landed, so a rejected or coerced write can never leave the two
	// surfaces showing different things.
	wp_send_json( array( 'ok' => true, 'cadence' => lg_fd_cadence( $uid ) ) );
}

/* ── SUPPRESSION: the seam that makes Daily/Weekly honest ──────────────────────
 * You cannot build Daily/Weekly without touching the Instant path. A member on Daily
 * whose native per-reply mail keeps running gets instant mail AND a digest — the same
 * lie in a new costume.
 *
 * Hook verified in live's deployed BuddyBoss 2.20.0, inside
 * BP_Forums_Notification::bb_send_forums_subscribed_reply() (class-bp-forums-
 * notification.php:989), in its per-recipient loop at :1069. Three properties make it
 * the right seam rather than a hack:
 *   1. PER-RECIPIENT — $args['recipient_user_id'] is exactly the granularity needed.
 *   2. EMAIL ONLY — bb_send_forums_subscribed_reply_notifications is a SEPARATE
 *      filter, so a batched member keeps their real-time bell. Ian wanted the bell to
 *      be the real-time channel; this preserves that exactly.
 *   3. UNCLAIMED — verified across the monorepo. The one adjacent filter we own,
 *      lg-discussion-group-gate.php:112 (bbp_forum_subscription_user_ids), is the
 *      forum/new-discussion path and is disjoint.
 *
 * ⚠️ RUNS IN BUDDYBOSS'S BACKGROUND UPDATER, not the request: bb_send_notifications_
 * to_subscribers sets $background_process=true whenever total>1 and sends in chunks of
 * 20 (bb-core-subscriptions.php:1135-1163). So the suppression decision is made at
 * FLUSH time, not post time, and a member who changes cadence in between can have one
 * reply fall either way. Bounded and named: it cannot double-send, because the digest
 * is watermark-driven and the watermark only moves on a send.
 *
 * THE OFF PATH IS THE IDENTITY FUNCTION. That is the whole no-op claim, and it is
 * asserted by the gate rather than believed. */
add_filter( 'bb_send_forums_subscribed_reply_email_notifications', 'lg_fd_suppress_instant', 10, 2 );
function lg_fd_suppress_instant( $send_mail, $args ) {
	if ( ! LG_FOLLOW_DIGEST_ENABLED ) { return $send_mail; }          // ← proven no-op
	$uid = (int) ( is_array( $args ) ? ( $args['recipient_user_id'] ?? 0 ) : 0 );
	if ( $uid <= 0 ) { return $send_mail; }
	// Only ever REMOVES mail. This filter must never turn a false into a true — a
	// member BB already decided not to mail (blocked, opted out, moderated) stays
	// unmailed, and the digest must not become a backdoor around that decision.
	if ( ! $send_mail ) { return $send_mail; }

	/* ⚠️ SUPPRESSION MUST MIRROR DELIVERY EXACTLY, OR IT IS A MAIL BLACK HOLE.
	 * Suppressing a member's instant mail is only defensible because a digest replaces
	 * it. For a member the allowlist blocks, no digest is coming — so suppressing them
	 * would take away the mail they DO get and give nothing back, which is strictly
	 * worse than never having built this. The allowlist must never be able to make a
	 * member's notifications quieter; it may only ever withhold the digest.
	 *
	 * This is the same condition the send layer uses, asked here so the two cannot
	 * drift: suppressed ⟺ served. */
	$u = get_userdata( $uid );
	if ( ! $u || ! lg_fd_allowed( $uid, (string) $u->user_email ) ) { return $send_mail; }

	return 'instant' === lg_fd_cadence( $uid ) ? $send_mail : false;
}

/* ── THE RECIPIENT SET ─────────────────────────────────────────────────────────
 * "Prove the recipient set before the content." This is the function the gate calls
 * to prove the OFF state resolves NOBODY — the one negative dev2 can genuinely prove
 * about an unrecallable channel, since a swallowed send returns true and proves
 * nothing positive.
 *
 * @return int[] WP user ids due for a digest at this cadence, right now.
 */
function lg_fd_due_recipients( string $cadence ): array {
	if ( ! LG_FOLLOW_DIGEST_ENABLED ) { return array(); }             // ← the negative, asserted
	if ( ! in_array( $cadence, array( 'daily', 'weekly' ), true ) ) { return array(); }

	global $wpdb;
	if ( ! isset( $wpdb ) ) { return array(); }

	// A member is due only if they hold this cadence AND a watermark. No watermark
	// means the flood guard has not run for them, and the correct response is to send
	// them nothing rather than to invent a window.
	$sql = $wpdb->prepare(
		"SELECT c.user_id
		   FROM {$wpdb->usermeta} c
		   JOIN {$wpdb->usermeta} w
		     ON w.user_id = c.user_id AND w.meta_key = %s AND w.meta_value <> ''
		  WHERE c.meta_key = %s AND c.meta_value = %s",
		LG_FD_WATERMARK_META, LG_FD_CADENCE_META, $cadence
	);
	$due = array_map( 'intval', (array) $wpdb->get_col( $sql ) );

	/* ── THE ALLOWLIST, APPLIED FIRST ─────────────────────────────────────────
	 * Defence 1 of 3. Dropping non-allowlisted members here means no batch is built
	 * for them, no render runs, and nothing touches their rows — so a bug anywhere
	 * downstream has nobody to mail. It is NOT the authoritative check (that is
	 * lg_fd_deliver, at the send layer, which every future code path must also pass);
	 * it is the one that makes the blast radius empty rather than merely unused.
	 *
	 * Deliberately re-resolves the address per member rather than trusting the id:
	 * the pinned form of the allowlist is only meaningful against a real address. */
	$allowed = array();
	$blocked = 0;
	foreach ( $due as $uid ) {
		$u = get_userdata( $uid );
		if ( $u && lg_fd_allowed( $uid, (string) $u->user_email ) ) { $allowed[] = $uid; continue; }
		$blocked++;
	}
	if ( $blocked ) {
		// Never silently — a recipient set that shrank without saying so reads as
		// "nobody qualified" when the truth is "the allowlist held".
		$a = lg_fd_allowlist();
		error_log( sprintf( '[lg-fd] %s: allowlist admitted %d of %d due member(s), blocked %d (%s)',
			$cadence, count( $allowed ), count( $due ), $blocked, $a['reason'] ) );
	}
	return $allowed;
}

/* ── THE CONTENT ───────────────────────────────────────────────────────────────
 * Replies in topics this member holds the ✉ bit on, since their watermark, excluding
 * their own. Counts, thread titles and sender display names only — NEVER reply body
 * text. That is the standing privacy ruling (NOTIF-EMAIL-STATE §1), not a style
 * choice, and it is enforced here by simply never selecting post_content.
 *
 * post_status='publish' is doing real work: it keeps a reply that was trashed,
 * spammed or left pending between post time and flush time OUT of the digest.
 * Instant has no equivalent protection, so the batcher is strictly safer here.
 *
 * @return array{items:array,capped:bool}  items ordered oldest-first.
 */
function lg_fd_items_for( int $user_id, int $limit = 50 ): array {
	$empty = array( 'items' => array(), 'capped' => false );
	if ( ! LG_FOLLOW_DIGEST_ENABLED || $user_id <= 0 ) { return $empty; }

	$since = lg_fd_watermark( $user_id );
	if ( '' === $since ) { return $empty; }   // REFUSE rather than backfill. §4.3.

	global $wpdb;
	if ( ! isset( $wpdb ) ) { return $empty; }
	$subs = $wpdb->prefix . 'bb_notifications_subscriptions';

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT r.ID, r.post_parent AS topic_id, r.post_author, r.post_date_gmt,
		        t.post_title AS topic_title
		   FROM {$wpdb->posts} r
		   JOIN {$subs} s
		     ON s.item_id = r.post_parent AND s.type = 'topic' AND s.status = 1
		    AND s.user_id = %d
		   JOIN {$wpdb->posts} t ON t.ID = r.post_parent
		  WHERE r.post_type    = 'reply'
		    AND r.post_status  = 'publish'
		    AND r.post_date_gmt > %s
		    AND r.post_author <> %d
		  ORDER BY r.post_date_gmt ASC
		  LIMIT %d",
		$user_id, $since, $user_id, $limit + 1
	), ARRAY_A );

	$rows   = (array) $rows;
	$capped = count( $rows ) > $limit;
	if ( $capped ) { $rows = array_slice( $rows, 0, $limit ); }

	return array( 'items' => $rows, 'capped' => $capped );
}

/**
 * Advance the watermark after a send the sender believes succeeded.
 *
 * ⚠️ TO THE NEWEST ITEM INCLUDED, NEVER TO now(). A reply landing between the query
 * and the send would otherwise be consumed without ever being sent — silently, and
 * with no way to notice.
 */
function lg_fd_advance_watermark( int $user_id, array $items ): void {
	if ( $user_id <= 0 || ! $items ) { return; }
	$newest = '';
	foreach ( $items as $it ) {
		$d = (string) ( $it['post_date_gmt'] ?? '' );
		if ( $d > $newest ) { $newest = $d; }
	}
	if ( '' !== $newest ) { update_user_meta( $user_id, LG_FD_WATERMARK_META, $newest ); }
}

/* ── THE SCHEDULE ──────────────────────────────────────────────────────────────
 * When it exists it will be armed ONLY while the flag is on, and UNSCHEDULED on
 * flag-off. The precedent is mirror-dispatch's outbox timer: a sender armed ahead of
 * its code reddens `systemctl --failed` forever and kills the alert channel — and
 * here it would also mail real members from a feature nobody switched on.
 *
 * It will ride the existing lg-wp-cron.timer (1-minute tick, `wp cron event run
 * --due-now`, verified active on dev2 AND live). No new systemd unit. Nowhere near
 * lg_wd_send_digest (weekly-recap's editorial broadcast, Mondays 13:00 UTC).
 *
 * MISSED RUNS ROLL FORWARD. Because the query is watermark-driven rather than
 * window-driven, a box down for two days sends a two-day digest rather than silently
 * dropping everything in between. That is a decision, not an accident: for an
 * unrecallable channel, "silently drops" is the wrong failure.
 *
 * ONE HOURLY HOOK, FIXED SEND HOURS — not a per-member clock. A daily digest goes out
 * at the same local hour for everyone, so "who is due" is a property of the CLOCK, not
 * of each member, and no per-member last-sent state is needed. Times are site-local
 * (America/New_York) because 08:00 must mean breakfast, not 03:00. */
const LG_FD_DAILY_HOUR  = 8;   // 08:00 site-local
const LG_FD_WEEKLY_HOUR = 8;   // Sunday 08:00 site-local
const LG_FD_WEEKLY_DOW  = 0;   // Sunday
const LG_FD_MAX_PER_RUN = 500; // a ceiling, not an expectation — see the guard below

add_action( 'init', 'lg_fd_sync_schedule' );
function lg_fd_sync_schedule(): void {
	$next = wp_next_scheduled( LG_FD_CRON_HOOK );
	if ( ! LG_FOLLOW_DIGEST_ENABLED ) {
		// Unscheduled on flag-off. mirror-dispatch's outbox timer is the precedent for
		// what an armed-ahead-of-its-code sender does to the alert channel — and this
		// one would also mail real members from a feature nobody switched on.
		if ( $next ) { wp_unschedule_event( $next, LG_FD_CRON_HOOK ); }
		return;
	}
	if ( ! $next ) { wp_schedule_event( time() + 300, 'hourly', LG_FD_CRON_HOOK ); }
}

add_action( LG_FD_CRON_HOOK, 'lg_fd_tick' );

/**
 * One hourly tick. Decides whether anything is due, then flushes it.
 *
 * ⚠️ THE DOUBLE-SEND QUESTION, SETTLED TWO WAYS because this is unrecallable.
 *   1. STRUCTURALLY: the content query is watermark-driven and the watermark only
 *      moves on a send, so a second fire in the same hour finds zero new items for
 *      everyone already flushed and mails nobody. A repeat of the SAME content is
 *      impossible by construction, not by care.
 *   2. BY AN INTERVAL GUARD anyway: (1) still permits an unexpectedly-early SECOND
 *      digest if new replies land between two fires. Belt and braces are cheap here
 *      and the failure they prevent is not.
 */
/**
 * What the site clock says right now, as (hour, day-of-week), site-local.
 *
 * ⚠️ SPLIT OUT SO IT CAN BE GATED, BECAUSE THE FIRST VERSION APPLIED THE TIMEZONE
 * OFFSET TWICE and no test could see it. It read:
 *
 *     $now = (int) current_time( 'timestamp' );   // already site-local
 *     $h   = (int) wp_date( 'G', $now );          // ...shifted AGAIN
 *
 * current_time('timestamp') returns time()+offset — a "local" number that is no longer
 * a real UTC timestamp — and wp_date() then treats it as UTC and converts it to local
 * a second time. Measured on dev2 at 15:45 UTC / 11:45 New York: the tick computed
 * hour SEVEN. So a digest whose comment says "08:00 must mean breakfast, not 03:00"
 * would have gone out at NOON, every day, and the weekly on Sunday at noon.
 *
 * Nothing about that is visible in a green suite: the sender works, the batch is
 * right, the mail arrives — at the wrong time of day. It is Ian's-phone-shaped, which
 * is why the hour is now a value the gate reads rather than an argument nobody checks.
 *
 * @return array{h:int,dow:int}
 */
function lg_fd_local_now(): array {
	// No timestamp argument. wp_date() with none uses time() and converts once.
	return array( 'h' => (int) wp_date( 'G' ), 'dow' => (int) wp_date( 'w' ) );
}

function lg_fd_tick(): void {
	if ( ! LG_FOLLOW_DIGEST_ENABLED ) { return; }

	$t   = lg_fd_local_now();
	$h   = $t['h'];
	$dow = $t['dow'];

	if ( LG_FD_DAILY_HOUR === $h )                              { lg_fd_flush( 'daily', 20 * HOUR_IN_SECONDS ); }
	if ( LG_FD_WEEKLY_DOW === $dow && LG_FD_WEEKLY_HOUR === $h ) { lg_fd_flush( 'weekly', 6 * DAY_IN_SECONDS ); }
}

/**
 * Flush one cadence.
 *
 * MISSED RUNS ROLL FORWARD AND LOSE NOTHING. Because the content query is bounded by
 * the member's watermark rather than by a fixed window, a box that was down for two
 * days sends a two-day digest on the next tick. Only the TIMING slips; no reply is
 * ever silently dropped. That is the decision §4.3 of the design note records, and it
 * is the reason this sender carries per-member state at all.
 *
 * @param int $min_interval refuse to run again inside this many seconds.
 */
function lg_fd_flush( string $cadence, int $min_interval ): void {
	$opt  = 'lg_fd_last_flush_' . $cadence;
	$last = (int) get_option( $opt, 0 );
	if ( $last && ( time() - $last ) < $min_interval ) { return; }
	update_option( $opt, time(), false );   // claim the slot BEFORE sending, so a slow
	                                        // run overlapping the next tick cannot double up

	$sent = 0;
	foreach ( lg_fd_due_recipients( $cadence ) as $uid ) {
		if ( $sent >= LG_FD_MAX_PER_RUN ) {
			// Never truncate silently — a cap that says nothing reads as "covered
			// everyone" when it did not.
			error_log( sprintf( '[lg-fd] %s flush hit the %d cap; remainder rolls to the next run (watermarks unadvanced, so nothing is lost)',
				$cadence, LG_FD_MAX_PER_RUN ) );
			break;
		}
		if ( lg_fd_send_one( $uid, $cadence ) ) { $sent++; }
	}
	if ( $sent ) { error_log( sprintf( '[lg-fd] %s flush: %d sent', $cadence, $sent ) ); }
}

/**
 * Build and send one member's digest. Returns false when there was nothing to send —
 * EMPTY MEANS SEND NOTHING, never "the digest minus the section" (Ian, 2026-07-28,
 * ruled for the weekly recap and it governs here for the same reason).
 */
function lg_fd_send_one( int $uid, string $cadence ): bool {
	$batch = lg_fd_items_for( $uid );
	if ( ! $batch['items'] ) { return false; }

	$user = get_userdata( $uid );
	if ( ! $user || ! is_email( $user->user_email ) ) { return false; }

	/* ── THE ALLOWLIST, BEFORE ANY STATE MOVES ────────────────────────────────
	 * Defence 2 of 3. Checked here as well as in the resolver because this function
	 * is callable directly, and a caller that hand-picks a uid must not be a way
	 * around the list.
	 *
	 * ⚠️ AND IT RETURNS BEFORE THE WATERMARK, WHICH IS THE SUBTLE PART. A blocked
	 * member must be left EXACTLY as they were found. If the watermark advanced on a
	 * blocked send, their replies would be consumed without being delivered — and the
	 * damage would only surface later, when the allowlist is widened and their first
	 * real digest silently omits everything from the test window. Blocking is a
	 * no-op on the member, not a discarded send. */
	if ( ! lg_fd_allowed( $uid, (string) $user->user_email ) ) {
		$a = lg_fd_allowlist();
		error_log( sprintf( '[lg-fd] BLOCKED uid %d — not on the allowlist (%s); '
			. 'watermark NOT advanced, so nothing is lost when it is widened', $uid, $a['reason'] ) );
		return false;
	}

	$html = lg_fd_render( $user, $batch, $cadence );

	/* ⚠️ REFUSE TO SEND A LINKLESS DIGEST, and do NOT advance the watermark, so the
	 * next tick retries with the content intact.
	 *
	 * This is not hypothetical: the first rebuild after the deep-link fix emitted an
	 * email with ZERO discussion links, because the process ran as root and Postgres
	 * uses peer auth — role "root" does not exist, lg_following_pg() returned null, and
	 * every URL came back ''. Every row still rendered, so it looked like a normal
	 * digest and was one keystroke from being sent.
	 *
	 * "The hub cannot serve this topic" and "we could not reach the store that knows"
	 * are DIFFERENT FACTS, and an unrecallable channel must not collapse them — the
	 * same distinction account-following draws between `degraded` and `hydrated`.
	 * A genuinely unaddressable topic renders without a link, by design; a store we
	 * could not read means we do not know, and we wait. */
	if ( lg_fd_links_degraded() ) {
		error_log( sprintf( '[lg-fd] REFUSING to send uid %d — link store unreachable, '
			. 'watermark NOT advanced so the next run retries', $uid ) );
		return false;
	}
	$subj = lg_fd_subject( $batch );

	$verdict = lg_fd_deliver( $uid, (string) $user->user_email, $subj, $html );

	/* A blocked send is not a failed send. Nothing left the box and nothing about this
	 * member changed, so the watermark stays exactly where it was — same reasoning as
	 * the pre-render check above, restated here because this is the path a future
	 * caller would reach if it skipped that one. */
	if ( 'blocked' === $verdict ) { return false; }

	/* ⚠️ THE WATERMARK ADVANCES ON 'sent' AND ON 'failed', AND THAT IS DELIBERATE.
	 * Holding it back on a false return would re-send the same content on every
	 * subsequent tick — an unbounded retry loop on a channel that cannot be recalled.
	 * The asymmetry is the whole argument: under-sending one digest is recoverable,
	 * mailing the same member the same thing hourly is not. It is also the honest
	 * choice on this box, where containment makes wp_mail's return value meaningless
	 * in the first place (it swallows into mailpit and returns TRUE). Failures are
	 * logged rather than retried. */
	if ( 'failed' === $verdict ) { error_log( sprintf( '[lg-fd] wp_mail returned false for uid %d', $uid ) ); }
	lg_fd_advance_watermark( $uid, $batch['items'] );
	return true;
}

/**
 * THE SEND LAYER. Defence 3 of 3, and the authoritative one.
 *
 * ⚠️ THIS IS THE ONLY wp_mail() CALL SITE IN THIS FILE, AND THE GATE ASSERTS THAT.
 * The allowlist is not a policy that callers are trusted to consult — it is a wall
 * that every message must physically pass through, because the checks upstream can be
 * forgotten by code that does not exist yet. A future "send me a preview" button, a
 * one-shot, a retry path: all of them land here, or the gate goes red.
 *
 * @return string 'sent' | 'failed' | 'blocked' — three outcomes, never a bare bool.
 *                'blocked' and 'failed' are different facts and the caller must not
 *                collapse them: one means nothing happened, the other means we tried.
 *                (The same distinction lg_fd_links_degraded() draws, for the same
 *                reason — an unrecallable channel must not confuse "we didn't" with
 *                "we couldn't".)
 */
function lg_fd_deliver( int $uid, string $email, string $subject, string $html ): string {
	// The master switch is re-checked here too. This function must be safe to call
	// from anywhere, including code written by someone who has not read the flag.
	if ( ! LG_FOLLOW_DIGEST_ENABLED ) { return 'blocked'; }
	if ( $uid <= 0 || ! is_email( $email ) || '' === trim( $subject ) || '' === trim( $html ) ) {
		return 'blocked';
	}

	if ( ! lg_fd_allowed( $uid, $email ) ) {
		$a = lg_fd_allowlist();
		error_log( sprintf( '[lg-fd] SEND-LAYER BLOCK: uid %d <%s> is not on the allowlist (%s) '
			. '— no message was handed to wp_mail()', $uid, $email, $a['reason'] ) );
		return 'blocked';
	}

	add_filter( 'wp_mail_content_type', 'lg_fd_html_type' );
	$ok = wp_mail( $email, $subject, $html );
	remove_filter( 'wp_mail_content_type', 'lg_fd_html_type' );

	return $ok ? 'sent' : 'failed';
}

function lg_fd_html_type(): string { return 'text/html'; }

/** Subject. Reads correctly at n=1, which is the NORMAL case (design note §1.2). */
function lg_fd_subject( array $batch ): string {
	$n = count( $batch['items'] );
	$t = count( array_unique( wp_list_pluck( $batch['items'], 'topic_id' ) ) );
	if ( 1 === $n ) { return 'One new reply in a discussion you follow'; }
	if ( 1 === $t ) { return sprintf( '%d new replies in a discussion you follow', $n ); }
	return sprintf( '%d new replies across %d discussions you follow', $n, $t );
}

/* ── WHERE A LINK IN THIS EMAIL POINTS ────────────────────────────────────────
 * Ian, 2026-07-31, on the first real digest: "The link goes to the mirroring forum not
 * the hub with the modal open." He had already rejected that destination on another
 * surface the same night — "it has no bearing on actual ui."
 *
 * THE BUG WAS get_permalink($topic_id), which returns the MIRRORED LEGACY forum page.
 * Worth naming precisely because my own design note §4.1 specified the right contract
 * (/hub/?topic=<forum>/<topic>) and the code did not implement it — the spec was right
 * and the code drifted, which no gate caught because the gates asserted that a URL was
 * PRESENT, never where it POINTED.
 *
 * ⚠️ AND EMAIL RAISES THE STAKES: a link in a sent message cannot be edited afterwards.
 * A wrong link in a digest is permanent for that issue.
 *
 * REUSED, NOT REWRITTEN. account-following already solved this at
 * membership-pages/lib/following-data.php:218 (lg_following_topic_url, merged d7d78da).
 * This is the THIRD surface that needs the same answer, so it calls that function
 * rather than becoming a third URL builder that can drift.
 *
 * WHAT THAT FUNCTION KNOWS THAT A NAIVE FIX DOES NOT: topics in HIDDEN BuddyBoss group
 * forums cannot be opened in the hub at all — _single-topic.php gates both its lookups
 * on f.visibility='public', so the deep link 404s even for a signed-in admin. Their
 * first attempt fell back to the group-native URL for those, which is exactly the
 * mirrored page Ian rejected. So it returns '' and we render NO link.
 *
 * NEVER SILENTLY DEGRADE TO THE LEGACY PAGE. It is a rejected behaviour, not a
 * fallback. A row with no button and an honest line beats a link to a UI he has said
 * no to — and the report says what it would take to fix it properly (the hub serving
 * group forums to their own members, which is bb-mirror's gate, not this sender's).
 */
/** Did the last lg_fd_topic_urls() call fail to REACH the store, as opposed to
 *  legitimately finding topics the hub cannot serve? The two are different facts and a
 *  digest must never collapse them — see the guard in lg_fd_send_one(). */
function lg_fd_links_degraded( ?bool $set = null ): bool {
	static $degraded = false;
	if ( null !== $set ) { $degraded = $set; }
	return $degraded;
}

function lg_fd_topic_urls( array $topic_ids ): array {
	lg_fd_links_degraded( false );
	$out = array_fill_keys( array_map( 'intval', $topic_ids ), '' );
	if ( ! $out ) { return $out; }

	/* __DIR__ resolves through the mu-plugin symlink to the REPO, which is where
	 * membership-pages lives — the same resolution that breaks docroot scripts looking
	 * for wp-load.php works in our favour here. Guarded rather than assumed: if the
	 * file is not there we return no links at all, because the alternative is emitting
	 * the rejected legacy URL. */
	$lib = dirname( __DIR__, 2 ) . '/membership-pages/lib/following-data.php';
	if ( ! is_readable( $lib ) ) {
		error_log( '[lg-fd] following-data.php not readable at ' . $lib . ' — emitting NO links rather than legacy ones' );
		lg_fd_links_degraded( true );
		return $out;
	}
	require_once $lib;
	if ( ! function_exists( 'lg_following_topic_url' ) || ! function_exists( 'lg_following_pg' ) ) {
		error_log( '[lg-fd] lg_following_topic_url/lg_following_pg missing — emitting NO links' );
		lg_fd_links_degraded( true );
		return $out;
	}

	$pdo = lg_following_pg();
	if ( ! $pdo ) { lg_fd_links_degraded( true ); return $out; }

	try {
		$in = implode( ',', array_fill( 0, count( $out ), '?' ) );
		$st = $pdo->prepare(
			"SELECT t.id, t.slug, f.slug AS forum_slug, f.visibility AS forum_visibility, f.group_id
			   FROM topic t LEFT JOIN forum f ON f.id = t.forum_id
			  WHERE t.id IN ($in)"
		);
		$st->execute( array_keys( $out ) );
		foreach ( $st->fetchAll( PDO::FETCH_ASSOC ) as $r ) {
			$out[ (int) $r['id'] ] = lg_following_topic_url( $r, array() );
		}
	} catch ( Throwable $e ) {
		error_log( '[lg-fd] topic url hydrate failed: ' . $e->getMessage() );
		lg_fd_links_degraded( true );
	}
	return $out;
}

/**
 * Render. COUNTS, THREAD TITLES AND SENDER DISPLAY NAMES ONLY — never reply text.
 * That is the standing privacy ruling (NOTIF-EMAIL-STATE §1), and it is enforced by
 * lg_fd_items_for never selecting post_content, so there is nothing here to leak.
 *
 * Table-based with inline styles, matching lg-weekly-digest/templates/email.php's
 * posture — the brand palette and the constraints of real mail clients are the same
 * whoever is sending.
 */
function lg_fd_render( WP_User $user, array $batch, string $cadence ): string {
	$by_topic = array();
	foreach ( $batch['items'] as $it ) { $by_topic[ (int) $it['topic_id'] ][] = $it; }

	$name  = $user->display_name ? $user->display_name : $user->user_login;
	$every = 'daily' === $cadence ? 'once a day' : 'once a week';

	$urls = lg_fd_topic_urls( array_keys( $by_topic ) );

	$rows = '';
	foreach ( $by_topic as $tid => $items ) {
		$names = array();
		foreach ( $items as $i ) {
			$a = get_userdata( (int) $i['post_author'] );
			if ( $a ) { $names[ $a->ID ] = $a->display_name ? $a->display_name : $a->user_login; }
		}
		$names = array_values( $names );
		$who   = lg_fd_names_phrase( $names, count( $items ) );

		// The hub deep link, or '' for a topic the hub cannot serve. NEVER the legacy
		// forum permalink — that destination is ruled out, not a fallback.
		$path  = (string) ( $urls[ (int) $tid ] ?? '' );
		$url   = '' !== $path ? home_url( $path ) : '';
		$title = esc_html( get_the_title( $tid ) );

		$rows .= '<tr><td style="padding:0 0 16px;">'
			. '<div style="font:700 16px/1.3 Arial,Helvetica,sans-serif;color:#2B2318;margin:0 0 5px;">'
			. ( '' !== $url
				? '<a href="' . esc_url( $url ) . '" style="color:#2B2318;text-decoration:none;">' . $title . '</a>'
				: $title )
			. '</div>'
			. '<div style="font:13px/1.5 Arial,Helvetica,sans-serif;color:#6b6459;margin:0 0 9px;">'
			. $who . '</div>'
			. ( '' !== $url
				? '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:#87986A;color:#ffffff;'
					. 'font:700 13px Arial,Helvetica,sans-serif;text-decoration:none;padding:8px 15px;border-radius:4px;">'
					. 'Open the discussion &rarr;</a>'
				// Honest, and deliberately NOT a link to the old forum. Says why rather
				// than leaving a row that looks broken.
				: '<span style="display:inline-block;font:italic 12px Arial,Helvetica,sans-serif;color:#8a8175;">'
					. 'This discussion is in a private group and can&rsquo;t be opened from email yet.</span>' )
			. '</td></tr>';
	}

	$n   = count( $batch['items'] );
	$t   = count( $by_topic );
	$sum = 1 === $n ? 'One new reply in a discussion you follow.'
		: ( 1 === $t ? sprintf( '%d new replies in a discussion you follow.', $n )
		             : sprintf( '%d new replies across %d discussions you follow.', $n, $t ) );
	if ( $batch['capped'] ) { $sum .= ' (Showing the first ' . $n . '.)'; }

	/* ⚠️ THE <head> IS NOT OPTIONAL, and the first draft of this template had none.
	 * Measured: without a viewport meta, innerWidth reports 980 at EVERY emulated
	 * width, because a mobile client with no viewport declaration lays out at 980px
	 * and zooms out — the mail arrives readable-only-after-pinching on the device
	 * most members open it on. And without a charset the em-dash, the middot and any
	 * accented display name mojibake. Both are exactly what
	 * lg-weekly-digest/templates/email.php declares, for the same reasons. */
	return '<!DOCTYPE html><html lang="en"><head>'
		. '<meta charset="UTF-8">'
		. '<meta name="viewport" content="width=device-width,initial-scale=1.0">'
		. '<meta http-equiv="X-UA-Compatible" content="IE=edge">'
		. '<title>' . esc_html( lg_fd_subject( $batch ) ) . '</title>'
		/* The fixed 600 table is for Outlook, which ignores max-width; the media query
		 * is for everything else, which would otherwise lay out at 628px on a 390px
		 * phone and zoom the whole mail out. Measured both ways — this is the same
		 * split lg-weekly-digest/templates/email.php uses, not a second idiom. */
		. '<style>@media only screen and (max-width:480px){'
		. '.lgfd-c{width:100%!important;max-width:100%!important}'
		. '.lgfd-p{padding:18px 16px!important}'
		. '.lgfd-w{padding:8px 4px!important}}</style>'
		. '</head>'
		. '<body style="margin:0;padding:0;background:#e8e2d8;">'
		. '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation"><tr><td align="center" class="lgfd-w" style="padding:14px;">'
		. '<table width="600" class="lgfd-c" cellpadding="0" cellspacing="0" border="0" role="presentation" style="max-width:600px;background:#ffffff;">'
		. '<tr><td style="background:#2B2318;padding:18px 22px;">'
		. '<div style="font:700 15px Arial,Helvetica,sans-serif;color:#ECB351;letter-spacing:.6px;">THE LOOTH GROUP</div>'
		. '<div style="font:11px Arial,Helvetica,sans-serif;color:#b9b0a0;padding-top:3px;">Your discussions</div>'
		. '</td></tr>'
		. '<tr><td class="lgfd-p" style="padding:22px;">'
		. '<p style="font:700 19px Arial,Helvetica,sans-serif;color:#2B2318;margin:0 0 3px;">'
		. esc_html( $name ) . ' &mdash;</p>'
		. '<p style="font:14px Arial,Helvetica,sans-serif;color:#5a5245;margin:0 0 18px;padding-bottom:14px;border-bottom:2px solid #ECB351;">'
		. esc_html( $sum ) . '</p>'
		. '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">' . $rows . '</table>'
		. '</td></tr>'
		. '<tr><td style="background:#faf8f3;border-top:1px solid #e3e1d8;padding:15px 22px;'
		. 'font:11px/1.7 Arial,Helvetica,sans-serif;color:#7a7264;">'
		. 'You get these <b>' . esc_html( $every ) . '</b>. '
		. '<a href="' . esc_url( home_url( '/manage-subscription/' ) ) . '" style="color:#5a6b3f;">Change how often</a>'
		. '</td></tr></table></td></tr></table></body></html>';
}

/** "Gerry Gentry, Ian Davlin and 2 others replied · 14 replies" — and it must read
 *  correctly at ONE name and ONE reply, which is the case most members will get. */
function lg_fd_names_phrase( array $names, int $count ): string {
	$n = count( $names );
	if ( 0 === $n ) { $who = 'Someone'; }
	elseif ( 1 === $n ) { $who = '<b>' . esc_html( $names[0] ) . '</b>'; }
	elseif ( 2 === $n ) { $who = '<b>' . esc_html( $names[0] ) . '</b> and <b>' . esc_html( $names[1] ) . '</b>'; }
	else {
		$shown = array_slice( $names, 0, 2 );
		$who   = '<b>' . esc_html( $shown[0] ) . '</b>, <b>' . esc_html( $shown[1] ) . '</b> and '
			. ( $n - 2 ) . ' other' . ( $n - 2 > 1 ? 's' : '' );
	}
	// At one reply the tally is redundant — "Ian Davlin replied · 1 reply" is clumsy in
	// the case MOST members will actually receive (design note §1.2), so it is dropped.
	if ( 1 === $count ) { return $who . ' replied'; }
	return $who . ' replied &middot; ' . $count . ' replies';
}
