<?php
/**
 * Plugin Name: LG Auto-Ban (login door)
 * Description: #162 — the credential-stuffing detector feeds a webserver blocklist.
 *
 *   Ian, 2026-08-20: "can we set up an auto block list when that email fires? It
 *   seems to know the IP, can we just add it to a file of known offenders and nip
 *   it at our webserver?" — narrowed the same day to "this should only block ips
 *   that try several different logins in one block."
 *
 *   This file is the WordPress half: it listens for lg-login-monitor's decoupled
 *   `lg_login_stuffing_detected` signal, records the offender in a data file, and
 *   gives Ian a wp-admin page to undo a mistake. It blocks nobody by itself.
 *   nginx does the blocking, from a config the root renderer builds out of this
 *   file's data — see tools/infra/lg-auto-ban-render.py.
 *
 * ══ THE ADDRESS PROBLEM, WHICH IS THE WHOLE SECURITY MODEL ═══════════════════
 *
 * Neither box restores real client IPs: `grep -rn real_ip /etc/nginx/` finds
 * nothing, so $_SERVER['REMOTE_ADDR'] is a CLOUDFLARE EDGE NODE on every ordinary
 * request. Banning that value would ban Cloudflare — i.e. take the whole site off
 * the internet for every visitor behind that edge, which is the opposite of the
 * feature.
 *
 * So the real client is in CF-Connecting-IP. But the origin is world-knockable on
 * 443, so ANYONE can connect straight to it and send that header saying whatever
 * they like. lg_login_monitor_client_ip() honours it unconditionally — harmless
 * when the value only decorates an email, an attack the moment it selects who
 * gets blocked: forge the header, fail five logins against five real member
 * logins, and put an address of your CHOOSING on our blocklist. Ian's home
 * address, for instance.
 *
 * lg_ab_vouched_ip() is therefore a SEPARATE computation and never reuses the
 * monitor's:
 *
 *     connection came from a Cloudflare range  →  trust CF-Connecting-IP
 *     connection came from anywhere else       →  use REMOTE_ADDR, the real peer
 *
 * In the second case the header is ignored entirely, so a forged one bans the
 * forger and nobody else. nginx makes the identical decision from the identical
 * range list (tools/infra/cloudflare-ranges.txt), because a PHP half and an nginx
 * half that disagree about "arrived through Cloudflare" is a feature that reads
 * as working while blocking the wrong body.
 *
 * ══ FAILS CLOSED, EVERYWHERE ════════════════════════════════════════════════
 *
 * Unreadable config → OFF. Unwritable store → nothing recorded, and the dash SAYS
 * SO rather than showing a reassuring empty table. Unparseable address → no ban.
 * Every refusal is logged. A blocklist that silently stops recording looks exactly
 * like a quiet week.
 *
 * Author: Looth Group
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/** Where the mutable state lives. Overridable so the gate can run offline. */
if ( ! defined( 'LG_AB_DIR' ) ) {
	define( 'LG_AB_DIR', '/var/lib/lg-auto-ban' );
}
/** Where the tracked config lives. Overridable for the same reason. */
if ( ! defined( 'LG_AB_CONFIG_DIR' ) ) {
	define( 'LG_AB_CONFIG_DIR', __DIR__ . '/../config' );
}
/** The one CF range list, shared with nginx and the renderer. */
if ( ! defined( 'LG_AB_CF_RANGES' ) ) {
	define( 'LG_AB_CF_RANGES', __DIR__ . '/../../tools/infra/cloudflare-ranges.txt' );
}

const LG_AB_CAP  = 'manage_options';
const LG_AB_SLUG = 'lg-auto-ban';

/* -------------------------------------------------------------------------
 * Config.
 * ---------------------------------------------------------------------- */

/**
 * The tracked flag file, with a gitignored .local.php beside it winning per key.
 * FAILS CLOSED: anything unreadable or malformed is OFF, because the failure mode
 * of guessing ON is a login door refusing members nobody decided to refuse.
 */
function lg_ab_config(): array {
	static $cfg = null;
	if ( $cfg !== null ) {
		return $cfg;
	}
	$defaults = array( 'enabled' => false, 'ban_seconds' => 86400, 'max_entries' => 500 );

	$base = @include LG_AB_CONFIG_DIR . '/auto-ban.php';
	if ( is_array( $base ) ) {
		$defaults = array_merge( $defaults, $base );
	}
	$local = @include LG_AB_CONFIG_DIR . '/auto-ban.local.php';
	if ( is_array( $local ) ) {
		$defaults = array_merge( $defaults, $local );
	}

	$cfg = array(
		'enabled'     => ! empty( $defaults['enabled'] ),
		'ban_seconds' => max( 60, (int) $defaults['ban_seconds'] ),
		'max_entries' => max( 1, (int) $defaults['max_entries'] ),
	);
	return $cfg;
}

function lg_ab_enabled(): bool {
	$c = lg_ab_config();
	return (bool) $c['enabled'];
}

/* -------------------------------------------------------------------------
 * The address, and whether we are allowed to believe it.
 * ---------------------------------------------------------------------- */

/** Parsed CIDR list from the shared tracked file. Empty list = trust nothing. */
function lg_ab_cf_ranges(): array {
	static $ranges = null;
	if ( $ranges !== null ) {
		return $ranges;
	}
	$ranges = array();
	$raw    = @file( LG_AB_CF_RANGES, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! is_array( $raw ) ) {
		error_log( 'lg-auto-ban: cannot read CF range list at ' . LG_AB_CF_RANGES . ' — treating every connection as UNVOUCHED' );
		return $ranges;
	}
	foreach ( $raw as $line ) {
		$line = trim( $line );
		if ( $line === '' || $line[0] === '#' ) {
			continue;
		}
		if ( strpos( $line, '/' ) === false ) {
			continue;
		}
		list( $net, $bits ) = explode( '/', $line, 2 );
		$packed = @inet_pton( $net );
		if ( $packed === false ) {
			continue;
		}
		$ranges[] = array( 'net' => $packed, 'bits' => (int) $bits, 'len' => strlen( $packed ) );
	}
	return $ranges;
}

/** True when $ip sits inside one of the Cloudflare ranges. v4 and v6. */
function lg_ab_is_cf( string $ip ): bool {
	$packed = @inet_pton( $ip );
	if ( $packed === false ) {
		return false;
	}
	foreach ( lg_ab_cf_ranges() as $r ) {
		if ( strlen( $packed ) !== $r['len'] ) {
			continue;   // never compare a v4 address against a v6 range
		}
		$bits  = $r['bits'];
		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;
		if ( $bytes > 0 && strncmp( $packed, $r['net'], $bytes ) !== 0 ) {
			continue;
		}
		if ( $rem === 0 ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rem ) ) & 0xFF;
		if ( ( ord( $packed[ $bytes ] ) & $mask ) === ( ord( $r['net'][ $bytes ] ) & $mask ) ) {
			return true;
		}
	}
	return false;
}

/** Canonical text form, so PHP and nginx and Python all spell an address alike. */
function lg_ab_normalise_ip( string $ip ): string {
	$packed = @inet_pton( trim( $ip ) );
	if ( $packed === false ) {
		return '';
	}
	$out = @inet_ntop( $packed );
	return is_string( $out ) ? strtolower( $out ) : '';
}

/**
 * THE address this feature bans. See the docblock at the top of this file — this
 * function is the security model, not a convenience wrapper.
 *
 * Returns '' when nothing trustworthy is available (WP-CLI, cron), and '' never
 * bans anyone.
 */
function lg_ab_vouched_ip(): string {
	$peer = isset( $_SERVER['REMOTE_ADDR'] ) ? lg_ab_normalise_ip( (string) $_SERVER['REMOTE_ADDR'] ) : '';
	if ( $peer === '' ) {
		return '';
	}
	if ( ! lg_ab_is_cf( $peer ) ) {
		// Direct connection to the origin. The header is attacker-settable here,
		// so it is ignored outright and the real peer is what gets banned.
		return $peer;
	}
	$cf = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? lg_ab_normalise_ip( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) : '';
	// Vouched connection, but a missing/unparseable header leaves us with only the
	// edge node — and banning an edge node bans everybody behind it.
	return $cf !== '' ? $cf : '';
}

/**
 * Addresses this feature must never write down, whatever the detector says.
 * Structural refusals, applied at the WRITE as well as at the render, because
 * two independent checks are how one of them being wrong stays survivable.
 */
function lg_ab_refuse_reason( string $ip ): string {
	if ( $ip === '' ) {
		return 'unparseable';
	}
	$packed = @inet_pton( $ip );
	if ( $packed === false ) {
		return 'unparseable';
	}
	if ( lg_ab_is_cf( $ip ) ) {
		return 'cloudflare';   // banning the edge takes the site off the internet
	}
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return 'not-public';   // private, loopback, link-local, reserved
	}
	return '';
}

/* -------------------------------------------------------------------------
 * The store. WordPress is the only writer; root only ever reads.
 * ---------------------------------------------------------------------- */

function lg_ab_state_path(): string {
	return rtrim( LG_AB_DIR, '/' ) . '/state.json';
}

function lg_ab_status_path(): string {
	return rtrim( LG_AB_DIR, '/' ) . '/render-status.json';
}

/** Read the store. A missing file is an empty store, not an error. */
function lg_ab_read_state(): array {
	$empty = array( 'version' => 1, 'bans' => array(), 'allowlist' => array() );
	$raw   = @file_get_contents( lg_ab_state_path() );
	if ( $raw === false || $raw === '' ) {
		return $empty;
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		error_log( 'lg-auto-ban: state.json is not valid JSON — treating as empty' );
		return $empty;
	}
	$data['bans']      = isset( $data['bans'] ) && is_array( $data['bans'] ) ? $data['bans'] : array();
	$data['allowlist'] = isset( $data['allowlist'] ) && is_array( $data['allowlist'] ) ? $data['allowlist'] : array();
	$data['version']   = 1;
	return $data;
}

/**
 * Read-modify-write under an exclusive lock, committed by rename().
 *
 * The rename matters as much as the lock: the root renderer reads this file on a
 * path-unit trigger, which fires the instant the file is touched. Writing in
 * place would hand it a half-written document to parse, and "the JSON was
 * truncated" is indistinguishable from "there are no bans".
 *
 * $mutator receives the state array and returns the new one; returning null
 * abandons the write.
 */
function lg_ab_mutate_state( callable $mutator ): bool {
	$dir = rtrim( LG_AB_DIR, '/' );
	if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
		error_log( 'lg-auto-ban: store directory ' . $dir . ' is missing or not writable — nothing recorded' );
		return false;
	}
	$lockfile = $dir . '/.lock';
	$lh       = @fopen( $lockfile, 'c' );
	if ( ! $lh ) {
		error_log( 'lg-auto-ban: cannot open lock file — nothing recorded' );
		return false;
	}
	if ( ! flock( $lh, LOCK_EX ) ) {
		fclose( $lh );
		error_log( 'lg-auto-ban: cannot take the write lock — nothing recorded' );
		return false;
	}
	try {
		$state = lg_ab_read_state();
		$next  = $mutator( $state );
		if ( ! is_array( $next ) ) {
			return false;
		}
		$json = json_encode( $next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			error_log( 'lg-auto-ban: refusing to write unencodable state' );
			return false;
		}
		$tmp = $dir . '/.state.' . getmypid() . '.tmp';
		if ( @file_put_contents( $tmp, $json . "\n" ) === false ) {
			error_log( 'lg-auto-ban: cannot write ' . $tmp . ' — nothing recorded' );
			return false;
		}
		@chmod( $tmp, 0644 );
		if ( ! @rename( $tmp, lg_ab_state_path() ) ) {
			@unlink( $tmp );
			error_log( 'lg-auto-ban: cannot commit state.json — nothing recorded' );
			return false;
		}
		return true;
	} finally {
		flock( $lh, LOCK_UN );
		fclose( $lh );
	}
}

/** Bans that have not expired, newest first. */
function lg_ab_active_bans( ?int $now = null ): array {
	$now   = $now ?? time();
	$state = lg_ab_read_state();
	$out   = array();
	foreach ( $state['bans'] as $b ) {
		if ( ! is_array( $b ) || empty( $b['ip'] ) ) {
			continue;
		}
		if ( (int) ( $b['expires_at'] ?? 0 ) <= $now ) {
			continue;
		}
		$out[] = $b;
	}
	usort( $out, static function ( $a, $b ) {
		return (int) ( $b['banned_at'] ?? 0 ) <=> (int) ( $a['banned_at'] ?? 0 );
	} );
	return $out;
}

function lg_ab_allowlist(): array {
	$state = lg_ab_read_state();
	$out   = array();
	foreach ( $state['allowlist'] as $a ) {
		if ( is_array( $a ) && ! empty( $a['ip'] ) ) {
			$out[] = $a;
		}
	}
	return $out;
}

function lg_ab_is_allowlisted( string $ip ): bool {
	foreach ( lg_ab_allowlist() as $a ) {
		if ( (string) $a['ip'] === $ip ) {
			return true;
		}
	}
	return false;
}

/* -------------------------------------------------------------------------
 * The signal. lg-login-monitor fires this at the same moment it alerts.
 * ---------------------------------------------------------------------- */

add_action( 'lg_login_stuffing_detected', 'lg_ab_on_stuffing', 10, 1 );

/**
 * Record one offender. Returns the ban row on success, null on any refusal — and
 * every refusal is logged with its reason, because "no bans this week" and "the
 * mechanism stopped working" must never look the same from the outside.
 */
function lg_ab_on_stuffing( $ctx ): ?array {
	if ( ! is_array( $ctx ) ) {
		return null;
	}
	if ( ! lg_ab_enabled() ) {
		return null;   // flag OFF: no file, no directory, no trace
	}

	// NOT $ctx['ip'] — see the docblock. The monitor's address is forgeable; this
	// one is what the connection actually proves.
	$ip = lg_ab_vouched_ip();

	$refusal = lg_ab_refuse_reason( $ip );
	if ( $refusal !== '' ) {
		error_log( 'lg-auto-ban: refusing to ban (' . $refusal . ') — reported=' . (string) ( $ctx['ip'] ?? '?' ) );
		return null;
	}
	if ( lg_ab_is_allowlisted( $ip ) ) {
		error_log( 'lg-auto-ban: ' . $ip . ' is allowlisted — not banned' );
		return null;
	}

	$cfg   = lg_ab_config();
	$now   = (int) ( $ctx['time'] ?? time() );
	$first = (int) ( $ctx['first_seen'] ?? $now );
	$row   = array(
		'ip'           => $ip,
		'reason'       => 'stuffing',
		'banned_at'    => $now,
		'expires_at'   => $now + $cfg['ban_seconds'],
		'accounts'     => max( 0, (int) ( $ctx['accounts'] ?? 0 ) ),
		'span_seconds' => max( 0, $now - $first ),
		'ua'           => mb_substr( (string) ( $ctx['ua'] ?? '' ), 0, 300 ),
		'reported_ip'  => (string) ( $ctx['ip'] ?? '' ),
	);

	$ok = lg_ab_mutate_state( static function ( array $state ) use ( $row, $cfg, $now ) {
		$kept = array();
		foreach ( $state['bans'] as $b ) {
			if ( ! is_array( $b ) || empty( $b['ip'] ) ) {
				continue;
			}
			if ( (string) $b['ip'] === $row['ip'] ) {
				continue;   // re-detection refreshes rather than duplicates
			}
			if ( (int) ( $b['expires_at'] ?? 0 ) <= $now ) {
				continue;   // expired rows are dropped on every write, not hoarded
			}
			$kept[] = $b;
		}
		$kept[] = $row;
		// Cap. Oldest out first, so a flood cannot push a fresh offender off.
		if ( count( $kept ) > $cfg['max_entries'] ) {
			usort( $kept, static function ( $a, $b ) {
				return (int) ( $a['banned_at'] ?? 0 ) <=> (int) ( $b['banned_at'] ?? 0 );
			} );
			$kept = array_slice( $kept, -$cfg['max_entries'] );
		}
		$state['bans'] = array_values( $kept );
		return $state;
	} );

	if ( ! $ok ) {
		return null;
	}
	error_log( 'lg-auto-ban: banned ' . $ip . ' until ' . gmdate( 'c', $row['expires_at'] ) . ' (' . $row['accounts'] . ' accounts)' );
	return $row;
}

/* -------------------------------------------------------------------------
 * Operator verbs, used by the dash.
 * ---------------------------------------------------------------------- */

/** Lift a ban now. Returns true if a row was actually removed. */
function lg_ab_remove_ban( string $ip ): bool {
	$ip      = lg_ab_normalise_ip( $ip );
	$removed = false;
	if ( $ip === '' ) {
		return false;
	}
	lg_ab_mutate_state( static function ( array $state ) use ( $ip, &$removed ) {
		$kept = array();
		foreach ( $state['bans'] as $b ) {
			if ( is_array( $b ) && (string) ( $b['ip'] ?? '' ) === $ip ) {
				$removed = true;
				continue;
			}
			$kept[] = $b;
		}
		$state['bans'] = array_values( $kept );
		return $state;
	} );
	return $removed;
}

/** Promote to the permanent allowlist AND lift any current ban, in one write. */
function lg_ab_allow_ip( string $ip, string $by = '', string $note = '' ): bool {
	$ip = lg_ab_normalise_ip( $ip );
	if ( $ip === '' ) {
		return false;
	}
	$now = time();
	return lg_ab_mutate_state( static function ( array $state ) use ( $ip, $by, $note, $now ) {
		$kept = array();
		foreach ( $state['bans'] as $b ) {
			if ( is_array( $b ) && (string) ( $b['ip'] ?? '' ) === $ip ) {
				continue;
			}
			$kept[] = $b;
		}
		$state['bans'] = array_values( $kept );

		foreach ( $state['allowlist'] as $a ) {
			if ( is_array( $a ) && (string) ( $a['ip'] ?? '' ) === $ip ) {
				return $state;   // already permanent; the unban above still stands
			}
		}
		$state['allowlist'][] = array(
			'ip'       => $ip,
			'added_at' => $now,
			'added_by' => mb_substr( $by, 0, 60 ),
			'note'     => mb_substr( $note, 0, 200 ),
		);
		return $state;
	} );
}

/** Take an address back off the permanent allowlist. */
function lg_ab_unallow_ip( string $ip ): bool {
	$ip      = lg_ab_normalise_ip( $ip );
	$removed = false;
	if ( $ip === '' ) {
		return false;
	}
	lg_ab_mutate_state( static function ( array $state ) use ( $ip, &$removed ) {
		$kept = array();
		foreach ( $state['allowlist'] as $a ) {
			if ( is_array( $a ) && (string) ( $a['ip'] ?? '' ) === $ip ) {
				$removed = true;
				continue;
			}
			$kept[] = $a;
		}
		$state['allowlist'] = array_values( $kept );
		return $state;
	} );
	return $removed;
}

/* -------------------------------------------------------------------------
 * Is enforcement actually live on this box?
 *
 * PRESENCE IS NOT REACHABILITY. A dash that lists three bans while nginx has
 * never been armed is a dash telling Ian a comforting lie, and it is exactly the
 * class of miss his phone has caught before. The renderer writes what it did;
 * this reads it back and the page says so in the first thing you see.
 * ---------------------------------------------------------------------- */

function lg_ab_render_status(): array {
	$raw = @file_get_contents( lg_ab_status_path() );
	if ( $raw === false || $raw === '' ) {
		return array( 'armed' => false, 'why' => 'The blocklist watcher has never run on this box.' );
	}
	$d = json_decode( $raw, true );
	if ( ! is_array( $d ) ) {
		return array( 'armed' => false, 'why' => 'The watcher wrote a status this page could not read.' );
	}
	$d['armed'] = ! empty( $d['armed'] );
	return $d;
}

/* -------------------------------------------------------------------------
 * The dash. Ian, 2026-08-20: "We should add a dash in the wp dash where I can
 * remove a mistake."
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', static function () {
	add_menu_page(
		'Login bans',
		'Login bans',
		LG_AB_CAP,
		LG_AB_SLUG,
		'lg_ab_render_page',
		'dashicons-lock',
		82
	);
} );

/** "5 members' passwords in 4 seconds" — plain English, never a bare timestamp. */
function lg_ab_describe_ban( array $b, int $now ): string {
	$accounts = (int) ( $b['accounts'] ?? 0 );
	$span     = (int) ( $b['span_seconds'] ?? 0 );
	$when     = (int) ( $b['banned_at'] ?? $now );

	$day  = wp_date( 'Y-m-d', $when );
	$today = wp_date( 'Y-m-d', $now );
	$yday  = wp_date( 'Y-m-d', $now - DAY_IN_SECONDS );
	if ( $day === $today ) {
		$dayword = 'today';
	} elseif ( $day === $yday ) {
		$dayword = 'yesterday';
	} else {
		$dayword = 'on ' . wp_date( 'j M', $when );
	}

	if ( $span <= 1 ) {
		$spanword = 'in one second';
	} elseif ( $span < 60 ) {
		$spanword = sprintf( 'in %d seconds', $span );
	} elseif ( $span < 3600 ) {
		$spanword = sprintf( 'over %d minutes', max( 1, (int) round( $span / 60 ) ) );
	} else {
		$spanword = sprintf( 'over %d hours', max( 1, (int) round( $span / 3600 ) ) );
	}

	return sprintf(
		'Blocked %s at %s after trying %d %s %s.',
		$dayword,
		wp_date( 'g:ia', $when ),
		$accounts,
		$accounts === 1 ? "member's password" : "members' passwords",
		$spanword
	);
}

function lg_ab_render_page(): void {
	if ( ! current_user_can( LG_AB_CAP ) ) {
		wp_die( 'Forbidden' );
	}
	$now    = time();
	$cfg    = lg_ab_config();
	$status = lg_ab_render_status();
	$bans   = lg_ab_active_bans( $now );
	$allow  = lg_ab_allowlist();
	$nonce  = wp_create_nonce( 'lg_ab' );

	echo '<div class="wrap"><h1><span class="dashicons dashicons-lock" style="font-size:1em;height:1em;width:1em"></span> Login bans</h1>';
	echo '<p class="description">When one address fails the password login against several different members in a few minutes, it is put on this list and the sign-in form stops answering it for a day. Everything else stays open to them: they can still read the site, they stay signed in if they already were, and “Log in with Patreon” keeps working.</p>';

	if ( ! empty( $_GET['lg_ab_done'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['lg_ab_done'] ) ) ) . '</p></div>';
	}

	// ── Is it actually doing anything? Two independent keys, both reported. ──
	$writable = is_dir( LG_AB_DIR ) && is_writable( LG_AB_DIR );
	if ( ! $cfg['enabled'] ) {
		echo '<div class="notice notice-warning inline"><p><b>Recording is off.</b> Nothing is being written down and nobody is being blocked. Turn it on by setting <code>enabled</code> in <code>platform/config/auto-ban.php</code> (or the <code>.local.php</code> beside it on this box).</p></div>';
	} elseif ( ! $writable ) {
		echo '<div class="notice notice-error inline"><p><b>Recording is on but the file cannot be written.</b> <code>' . esc_html( LG_AB_DIR ) . '</code> is missing or not writable by the web user, so offenders are being dropped on the floor. Run <code>sudo tools/infra/install-auto-ban.sh</code>.</p></div>';
	} elseif ( empty( $status['armed'] ) ) {
		$why = isset( $status['why'] ) ? (string) $status['why'] : '';
		echo '<div class="notice notice-warning inline"><p><b>Recording, but not yet blocking.</b> Offenders are being written down and you can watch them arrive below — the webserver is not reading the list yet, so nobody is actually being stopped. ' . esc_html( $why ) . '</p></div>';
	} else {
		$when  = isset( $status['rendered_at'] ) ? (int) $status['rendered_at'] : 0;
		$count = isset( $status['entries'] ) ? (int) $status['entries'] : 0;
		echo '<div class="notice notice-success inline"><p><b>Blocking is live.</b> The webserver was last given the list '
			. esc_html( $when ? human_time_diff( $when, $now ) . ' ago' : 'at an unknown time' )
			. ' and is currently refusing ' . esc_html( (string) $count ) . ' ' . ( $count === 1 ? 'address' : 'addresses' ) . '.</p></div>';
	}

	// ── Current bans ──
	echo '<h2>Currently blocked</h2>';
	if ( ! $bans ) {
		echo '<p>Nobody is blocked right now.</p>';
	} else {
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>'
			. '<th style="width:210px">Address</th><th>What happened</th><th style="width:150px">Lifts</th>'
			. '<th style="width:280px">Undo</th></tr></thead><tbody>';
		foreach ( $bans as $b ) {
			$ip = (string) $b['ip'];
			echo '<tr><td><code>' . esc_html( $ip ) . '</code></td>';
			echo '<td>' . esc_html( lg_ab_describe_ban( $b, $now ) );
			if ( ! empty( $b['ua'] ) ) {
				echo '<br><span class="description" style="word-break:break-all">' . esc_html( (string) $b['ua'] ) . '</span>';
			}
			echo '</td>';
			echo '<td>in ' . esc_html( human_time_diff( $now, (int) $b['expires_at'] ) ) . '</td>';
			echo '<td>' . lg_ab_action_button( 'lg_ab_remove', $ip, $nonce, 'Remove', 'button' )
				. ' ' . lg_ab_action_button( 'lg_ab_allow', $ip, $nonce, 'Never ban this address', 'button' )
				. '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ── Allowlist ──
	echo '<h2 style="margin-top:2em">Never blocked</h2>';
	echo '<p class="description">Addresses that can never be put on the list — a member’s home, a venue you play, this office. The boxes themselves and Cloudflare’s own network are refused structurally and do not need a row here.</p>';
	if ( ! $allow ) {
		echo '<p>Nothing here yet.</p>';
	} else {
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>'
			. '<th style="width:210px">Address</th><th style="width:200px">Added</th><th>By</th><th style="width:140px"></th>'
			. '</tr></thead><tbody>';
		foreach ( $allow as $a ) {
			$ip = (string) $a['ip'];
			echo '<tr><td><code>' . esc_html( $ip ) . '</code></td>'
				. '<td>' . esc_html( ! empty( $a['added_at'] ) ? wp_date( 'j M Y, g:ia', (int) $a['added_at'] ) : '—' ) . '</td>'
				. '<td>' . esc_html( (string) ( $a['added_by'] ?? '' ) ) . '</td>'
				. '<td>' . lg_ab_action_button( 'lg_ab_unallow', $ip, $nonce, 'Remove', 'button-link-delete' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ── The raw feed the detector works from ──
	echo '<h2 style="margin-top:2em">Recent sign-in trouble</h2>';
	echo '<p class="description">The last hundred failed sign-ins against real member accounts — the feed the list above is built from, so you can judge a block without asking anyone.</p>';
	$log = get_option( 'lg_login_monitor_log', array() );
	if ( ! is_array( $log ) || ! $log ) {
		echo '<p>Nothing recorded yet.</p>';
	} else {
		$log = array_slice( $log, -40 );
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>'
			. '<th style="width:170px">When</th><th style="width:200px">Who</th><th style="width:180px">From</th><th>Note</th>'
			. '</tr></thead><tbody>';
		foreach ( array_reverse( $log ) as $e ) {
			if ( ! is_array( $e ) ) {
				continue;
			}
			$ts  = isset( $e['time'] ) ? strtotime( (string) $e['time'] ) : 0;
			$who = (string) ( $e['login'] ?? ( $e['patreon_email'] ?? '—' ) );
			echo '<tr><td>' . esc_html( $ts ? wp_date( 'j M, g:ia', $ts ) : '—' ) . '</td>'
				. '<td>' . esc_html( $who ) . '</td>'
				. '<td><code>' . esc_html( (string) ( $e['ip'] ?? '—' ) ) . '</code></td>'
				. '<td>' . esc_html( (string) ( $e['note'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	echo '</div>';
}

/** One nonce-carrying POST form per button. No GET verbs, no bare links. */
function lg_ab_action_button( string $action, string $ip, string $nonce, string $label, string $class ): string {
	return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
		. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
		. '<input type="hidden" name="ip" value="' . esc_attr( $ip ) . '">'
		. '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
		. '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>'
		. '</form>';
}

/* -------------------------------------------------------------------------
 * The three verbs, each capability-checked AND nonce-checked, each redirecting
 * back so a refresh cannot replay it.
 * ---------------------------------------------------------------------- */

function lg_ab_handle( string $verb ): void {
	if ( ! current_user_can( LG_AB_CAP ) ) {
		wp_die( 'Forbidden' );
	}
	check_admin_referer( 'lg_ab' );
	$ip = isset( $_POST['ip'] ) ? lg_ab_normalise_ip( sanitize_text_field( wp_unslash( (string) $_POST['ip'] ) ) ) : '';
	if ( $ip === '' ) {
		lg_ab_redirect( 'That is not an address this page recognises.' );
	}
	$user = wp_get_current_user();
	$who  = ( $user && $user->user_login ) ? $user->user_login : 'admin';

	switch ( $verb ) {
		case 'remove':
			$ok = lg_ab_remove_ban( $ip );
			lg_ab_redirect( $ok ? $ip . ' can sign in again.' : $ip . ' was not on the list.' );
			break;
		case 'allow':
			$ok = lg_ab_allow_ip( $ip, $who, 'added from the dashboard' );
			lg_ab_redirect( $ok ? $ip . ' will never be blocked again, and can sign in now.' : 'Could not update the list — check the file is writable.' );
			break;
		case 'unallow':
			$ok = lg_ab_unallow_ip( $ip );
			lg_ab_redirect( $ok ? $ip . ' is no longer permanently allowed.' : $ip . ' was not permanently allowed.' );
			break;
	}
	lg_ab_redirect( 'Nothing to do.' );
}

function lg_ab_redirect( string $message ): void {
	wp_safe_redirect( add_query_arg(
		array( 'page' => LG_AB_SLUG, 'lg_ab_done' => rawurlencode( $message ) ),
		admin_url( 'admin.php' )
	) );
	exit;
}

add_action( 'admin_post_lg_ab_remove',  static function () { lg_ab_handle( 'remove' ); } );
add_action( 'admin_post_lg_ab_allow',   static function () { lg_ab_handle( 'allow' ); } );
add_action( 'admin_post_lg_ab_unallow', static function () { lg_ab_handle( 'unallow' ); } );
