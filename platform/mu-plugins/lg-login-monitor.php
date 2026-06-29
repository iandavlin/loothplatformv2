<?php
/**
 * Plugin Name: LG Login Monitor
 * Description: Real-time alert to Ian when a REAL Looth member fails to log in —
 *   so we hear about a member who is locked out, not the bot noise. Two surfaces:
 *
 *   A) WP password login (wp-login.php). Hooks wp_login_failed and alerts ONLY
 *      when the attempted username/email maps to an EXISTING account. Bots spray
 *      random / admin names that resolve to nobody — those are ignored. A failure
 *      against a real member's exact login/email is that member struggling, and
 *      that is what we want to hear about.
 *
 *   B) Patreon "Log in with Patreon" connect blocks. Listens for the decoupled
 *      do_action('lg_login_blocked', $ctx) the onboarding callback fires at each
 *      real-person-blocked terminal (email_collision / different_patreon_id /
 *      admin_collision) and emails a structured alert. Transient Patreon-API
 *      failures do NOT fire this action, so they never page anyone.
 *
 *   Guards on surface A:
 *     - Dedup: at most one alert per (user_id + IP) per 60 minutes.
 *     - Credential-stuffing: if ONE IP fails against many DISTINCT real accounts
 *       in a short window, the per-account alerts are suppressed and a single
 *       "possible credential stuffing from <IP>" alert is sent instead.
 *
 *   Delivery: every alert carries the header `X-LG-Poller-Intent: notify`, the
 *   agreed marker that bypasses BOTH the poller pre_wp_mail gate
 *   (LGMS\Plugin::gateOutboundMail) AND the lg-poller-mail-killswitch mu-plugin —
 *   the Patreon listener's wp_mail() runs inside the poller call stack, so without
 *   the marker it would be suppressed exactly like the bulk poller mail. On dev2
 *   the alert is captured to mailpit by lg-dev-mail-containment; on live it is a
 *   single-recipient operator alert to lgpo_contact_email.
 *
 *   A tiny ring-buffer log (option lg_login_monitor_log, last 100 events) is kept
 *   for an optional future admin view — no schema, no table.
 *
 * Author: Looth Group
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/* -------------------------------------------------------------------------
 * Tunables (filterable so they can be adjusted without a redeploy).
 * ---------------------------------------------------------------------- */

/** Dedup window for a single (user + IP) — one alert per this many seconds. */
function lg_login_monitor_dedup_ttl(): int {
	return (int) apply_filters( 'lg_login_monitor_dedup_ttl', HOUR_IN_SECONDS );
}

/** Rolling window over which distinct-account failures from one IP are counted. */
function lg_login_monitor_stuffing_window(): int {
	return (int) apply_filters( 'lg_login_monitor_stuffing_window', 15 * MINUTE_IN_SECONDS );
}

/** Distinct real accounts from one IP within the window that flips to "stuffing". */
function lg_login_monitor_stuffing_threshold(): int {
	return (int) apply_filters( 'lg_login_monitor_stuffing_threshold', 5 );
}

/** How long one stuffing alert silences further alerts (per-account + stuffing) for that IP. */
function lg_login_monitor_stuffing_alert_ttl(): int {
	return (int) apply_filters( 'lg_login_monitor_stuffing_alert_ttl', HOUR_IN_SECONDS );
}

/* -------------------------------------------------------------------------
 * Shared helpers.
 * ---------------------------------------------------------------------- */

/**
 * True client IP, honoring Cloudflare. CF passes the real client in
 * CF-Connecting-IP; the proxy chain otherwise puts the client FIRST in
 * X-Forwarded-For. Fall back to REMOTE_ADDR. Returns 'unknown' if nothing
 * validates (e.g. WP-CLI).
 */
function lg_login_monitor_client_ip(): string {
	$cf = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? trim( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) : '';
	if ( $cf !== '' && filter_var( $cf, FILTER_VALIDATE_IP ) ) {
		return $cf;
	}
	$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
	if ( $xff !== '' ) {
		$first = trim( explode( ',', $xff )[0] );
		if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
			return $first;
		}
	}
	$ra = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
	if ( $ra !== '' && filter_var( $ra, FILTER_VALIDATE_IP ) ) {
		return $ra;
	}
	return 'unknown';
}

/** Best-effort raw user agent, trimmed to a sane length. */
function lg_login_monitor_user_agent(): string {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
	$ua = trim( wp_strip_all_tags( $ua ) );
	return $ua === '' ? '(none)' : mb_substr( $ua, 0, 300 );
}

/**
 * Send an operator alert. ALWAYS tagged X-LG-Poller-Intent: notify so it passes
 * the poller mail gate + killswitch (the Patreon path sends from inside the
 * poller call stack). Single recipient (lgpo_contact_email). Best-effort.
 */
function lg_login_monitor_send( string $subject, string $body ): bool {
	$to = (string) get_option( 'lgpo_contact_email', 'ian.davlin@gmail.com' );
	if ( $to === '' || ! is_email( $to ) ) {
		error_log( 'lg-login-monitor: no valid lgpo_contact_email — alert not sent: ' . $subject );
		return false;
	}
	$headers = array(
		'Content-Type: text/plain; charset=UTF-8',
		'X-LG-Poller-Intent: notify',
	);
	try {
		return (bool) wp_mail( $to, $subject, $body, $headers );
	} catch ( \Throwable $e ) {
		error_log( 'lg-login-monitor: wp_mail threw — ' . $e->getMessage() );
		return false;
	}
}

/**
 * Append one event to the ring-buffer log (option, autoload off, last 100).
 * For an optional future admin view — never throws, best-effort.
 */
function lg_login_monitor_log_event( array $event ): void {
	try {
		$log   = get_option( 'lg_login_monitor_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = $event;
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}
		update_option( 'lg_login_monitor_log', $log, false );
	} catch ( \Throwable $e ) {
		// best-effort
	}
}

/* -------------------------------------------------------------------------
 * Surface A — WP password login failures (wp-login.php / wp_signon).
 * ---------------------------------------------------------------------- */

add_action( 'wp_login_failed', 'lg_login_monitor_wp_failed', 10, 1 );

function lg_login_monitor_wp_failed( $username ): void {
	$username = trim( (string) $username );
	if ( $username === '' ) {
		return;
	}

	// BOT FILTER: only real, existing accounts. WP's login field accepts either a
	// username or an email, so resolve both ways. A miss = bot/typo spray → ignore.
	$user = get_user_by( 'login', $username );
	if ( ! $user && is_email( $username ) ) {
		$user = get_user_by( 'email', $username );
	}
	if ( ! $user ) {
		return;
	}

	$ip  = lg_login_monitor_client_ip();
	$ua  = lg_login_monitor_user_agent();
	$now = time();

	// --- Credential-stuffing accounting: distinct real accounts per IP --------
	$alerted    = false;
	$suppressed = '';
	if ( $ip !== 'unknown' ) {
		$set_key = 'lglm_ipset_' . md5( $ip );
		$set     = get_transient( $set_key );
		if ( ! is_array( $set ) ) {
			$set = array();
		}
		// Prune entries that have aged out of the rolling window.
		$window = lg_login_monitor_stuffing_window();
		foreach ( $set as $uid => $ts ) {
			if ( ( $now - (int) $ts ) > $window ) {
				unset( $set[ $uid ] );
			}
		}
		$set[ (int) $user->ID ] = $now;
		set_transient( $set_key, $set, $window );

		if ( count( $set ) >= lg_login_monitor_stuffing_threshold() ) {
			// Stuffing mode: suppress the per-account alert; send ONE IP alert,
			// deduped for the stuffing-alert TTL.
			$stuff_key = 'lglm_stuffalert_' . md5( $ip );
			if ( ! get_transient( $stuff_key ) ) {
				set_transient( $stuff_key, $now, lg_login_monitor_stuffing_alert_ttl() );
				lg_login_monitor_send_stuffing_alert( $ip, $ua, count( $set ), $now );
				$alerted = true;
			}
			$suppressed = 'stuffing';
			lg_login_monitor_log_event( array(
				'time'    => gmdate( 'c', $now ),
				'surface' => 'wp',
				'user_id' => (int) $user->ID,
				'login'   => $user->user_login,
				'email'   => $user->user_email,
				'ip'      => $ip,
				'ua'      => $ua,
				'alerted' => $alerted,
				'note'    => 'suppressed:stuffing (' . count( $set ) . ' distinct accts/IP)',
			) );
			return;
		}
	}

	// --- Per (user + IP) dedup -----------------------------------------------
	$seen_key = 'lglm_seen_' . md5( $user->ID . '|' . $ip );
	if ( get_transient( $seen_key ) ) {
		$suppressed = 'dedup';
	} else {
		set_transient( $seen_key, $now, lg_login_monitor_dedup_ttl() );
		lg_login_monitor_send_wp_alert( $user, $ip, $ua, $now );
		$alerted = true;
	}

	lg_login_monitor_log_event( array(
		'time'    => gmdate( 'c', $now ),
		'surface' => 'wp',
		'user_id' => (int) $user->ID,
		'login'   => $user->user_login,
		'email'   => $user->user_email,
		'ip'      => $ip,
		'ua'      => $ua,
		'alerted' => $alerted,
		'note'    => $suppressed ? 'suppressed:' . $suppressed : '',
	) );
}

function lg_login_monitor_send_wp_alert( \WP_User $user, string $ip, string $ua, int $now ): void {
	$who     = $user->display_name !== '' ? $user->display_name : $user->user_login;
	$subject = sprintf( '[Looth] Login failure — %s (WP)', $who );
	$body    =
		  "A real Looth member just failed a WordPress password login.\n\n"
		. 'Member:     ' . $who . "\n"
		. 'User ID:    ' . (int) $user->ID . "\n"
		. 'Login:      ' . $user->user_login . "\n"
		. 'Email:      ' . $user->user_email . "\n"
		. "Surface:    WP password login (wp-login.php)\n"
		. 'IP:         ' . $ip . "\n"
		. 'User-agent: ' . $ua . "\n"
		. 'Time:       ' . gmdate( 'Y-m-d H:i:s', $now ) . " UTC\n\n"
		. 'Manage user: ' . admin_url( 'user-edit.php?user_id=' . (int) $user->ID ) . "\n";
	lg_login_monitor_send( $subject, $body );
}

function lg_login_monitor_send_stuffing_alert( string $ip, string $ua, int $distinct, int $now ): void {
	$subject = sprintf( '[Looth] Possible credential stuffing from %s (WP)', $ip );
	$body    =
		  "Multiple DISTINCT real Looth accounts have failed login from a single IP\n"
		. "in a short window — this looks like credential stuffing, so per-account\n"
		. "alerts have been suppressed in favour of this one notice.\n\n"
		. 'IP:               ' . $ip . "\n"
		. 'Distinct accounts: ' . $distinct . ' (within ' . ( lg_login_monitor_stuffing_window() / 60 ) . " min)\n"
		. 'User-agent:        ' . $ua . "\n"
		. 'Time:              ' . gmdate( 'Y-m-d H:i:s', $now ) . " UTC\n\n"
		. "Further per-account alerts from this IP are paused for "
		. ( lg_login_monitor_stuffing_alert_ttl() / 60 ) . " min.\n";
	lg_login_monitor_send( $subject, $body );
}

/* -------------------------------------------------------------------------
 * Surface B — Patreon connect blocks (decoupled signal from onboarding).
 *
 * The onboarding callback fires do_action('lg_login_blocked', $ctx) at each
 * real-person-blocked terminal. Expected $ctx keys:
 *   surface          'patreon'
 *   reason           'different_patreon_id' | 'admin_collision' | 'email_collision'
 *   patreon_user_id  Patreon numeric id
 *   patreon_email    Patreon email
 *   patreon_name     Patreon display name
 *   wp_user_id       conflicting WP account id (0 if none)
 * ---------------------------------------------------------------------- */

add_action( 'lg_login_blocked', 'lg_login_monitor_login_blocked', 10, 1 );

function lg_login_monitor_login_blocked( $ctx ): void {
	if ( ! is_array( $ctx ) ) {
		return;
	}
	$surface = isset( $ctx['surface'] ) ? (string) $ctx['surface'] : 'patreon';

	$patreon_user_id = isset( $ctx['patreon_user_id'] ) ? (string) $ctx['patreon_user_id'] : '';
	$patreon_email   = isset( $ctx['patreon_email'] ) ? (string) $ctx['patreon_email'] : '';
	$patreon_name    = isset( $ctx['patreon_name'] ) ? (string) $ctx['patreon_name'] : '';
	$reason          = isset( $ctx['reason'] ) ? (string) $ctx['reason'] : 'unknown';
	$wp_user_id      = isset( $ctx['wp_user_id'] ) ? (int) $ctx['wp_user_id'] : 0;
	$now             = time();

	$who     = $patreon_name !== '' ? $patreon_name : ( $patreon_email !== '' ? $patreon_email : ( 'Patreon #' . $patreon_user_id ) );
	$subject = sprintf( '[Looth] Login failure — %s (Patreon)', $who );

	$conflict_line = $wp_user_id
		? ( $wp_user_id . "\n" . 'Conflict admin: ' . admin_url( 'user-edit.php?user_id=' . $wp_user_id ) )
		: 'n/a';

	$body =
		  "A real person was BLOCKED while connecting with Patreon — their Patreon\n"
		. "membership clashes with an existing Looth account and needs a human.\n\n"
		. 'Person:           ' . $who . "\n"
		. 'Patreon name:     ' . ( $patreon_name !== '' ? $patreon_name : 'n/a' ) . "\n"
		. 'Patreon email:    ' . ( $patreon_email !== '' ? $patreon_email : 'n/a' ) . "\n"
		. 'Patreon user id:  ' . ( $patreon_user_id !== '' ? $patreon_user_id : 'n/a' ) . "\n"
		. 'Surface:          Patreon connect (Log in with Patreon)' . "\n"
		. 'Reason:           ' . $reason . "\n"
		. 'Conflicting WP #: ' . $conflict_line . "\n"
		. 'Time:             ' . gmdate( 'Y-m-d H:i:s', $now ) . " UTC\n";

	lg_login_monitor_send( $subject, $body );

	lg_login_monitor_log_event( array(
		'time'            => gmdate( 'c', $now ),
		'surface'         => $surface,
		'reason'          => $reason,
		'patreon_user_id' => $patreon_user_id,
		'patreon_email'   => $patreon_email,
		'patreon_name'    => $patreon_name,
		'wp_user_id'      => $wp_user_id,
		'alerted'         => true,
		'note'            => '',
	) );
}
