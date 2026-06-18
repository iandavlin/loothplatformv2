<?php
/**
 * lg-bug-report  (site-wide "Report a bug" receiving endpoint)
 *
 * Deploy: /var/www/dev/wp-content/mu-plugins/lg-bug-report.php
 *         (chown looth-dev:loothdevs ; mode 0644 — matches sibling mu-plugins)
 *
 * Surface: POST /wp-json/looth/v1/bug-report
 *   body (application/json or form):
 *     { "message": "<what went wrong, required>",
 *       "page_url": "<the page the reporter was on>",
 *       "nonce":    "<wp_rest nonce, optional/best-effort>" }
 *
 * Logged-in-only. The reporter's IDENTITY is read SERVER-SIDE — never trusted
 * from the client body:
 *   1. Primary  — the `looth_id` JWT cookie (works on EVERY strangler surface,
 *      no WP dependency), verified via /srv/lg-shared/jwt-verify.php. Claims:
 *      sub (user_uuid), wp_user_id, display_name, slug. (STRANGLER-COORD §0c)
 *   2. Fallback — wp_get_current_user() when the request carries WP auth
 *      cookies (WP-native pages). Supplies user_email, which the JWT omits.
 * If NEITHER proves a logged-in viewer → 401. This is the real lock; the nginx
 * cookie-gate (loothdev_auth) already blocks anon at the edge on dev.
 *
 * The browser User-Agent + client IP are captured from the request headers.
 *
 * Mail: wp_mail() to ian.davlin@gmail.com. NOTE (dev): outbound mail is caught
 * by mailpit (UI /mailpit/) — test sends land there, not a real inbox. Real
 * SMTP delivery is a cut-day concern.
 *
 * Same-origin: every surface lives under dev.loothgroup.com, so the modal POSTs
 * here with cookies attached automatically — no CORS, no nginx route needed
 * (/wp-json/looth/v1/* falls through the catch-all `location /` to index.php,
 * exactly like the existing lg-weekly-email-bridge looth/v1 route).
 */

defined( 'ABSPATH' ) || exit;

const LG_BUG_REPORT_TO = 'ian.davlin@gmail.com';

add_action( 'rest_api_init', function () {
	register_rest_route( 'looth/v1', '/bug-report', [
		'methods'             => 'POST',
		// Auth is enforced inside the callback (JWT cookie OR WP session), so
		// cross-surface POSTs that carry only the looth_id cookie still work.
		'permission_callback' => '__return_true',
		'args'                => [
			'message'  => [ 'required' => true ],
			'page_url' => [ 'required' => false ],
		],
		'callback'            => 'lg_bug_report_submit',
	] );
} );

/**
 * Resolve the reporting viewer server-side. Returns null if not logged in.
 *
 * @return array{user_uuid:string,wp_user_id:int,display_name:string,slug:string,email:string}|null
 */
function lg_bug_report_identity(): ?array {
	$id = [
		'user_uuid'    => '',
		'wp_user_id'   => 0,
		'display_name' => '',
		'slug'         => '',
		'email'        => '',
	];

	// (1) looth_id JWT — present on every strangler surface.
	if ( is_readable( '/srv/lg-shared/jwt-verify.php' ) ) {
		require_once '/srv/lg-shared/jwt-verify.php';
		if ( function_exists( 'lg_shared_verify_looth_id' ) ) {
			$claims = lg_shared_verify_looth_id( $_COOKIE['looth_id'] ?? null );
			if ( is_array( $claims ) ) {
				$id['user_uuid']    = (string) ( $claims['sub'] ?? $claims['user_uuid'] ?? '' );
				$id['wp_user_id']   = (int) ( $claims['wp_user_id'] ?? 0 );
				$id['display_name'] = (string) ( $claims['display_name'] ?? '' );
				$id['slug']         = (string) ( $claims['slug'] ?? '' );
			}
		}
	}

	// (2) WP session fallback / enrichment (gives us the email).
	if ( function_exists( 'wp_get_current_user' ) ) {
		$u = wp_get_current_user();
		if ( $u && (int) $u->ID > 0 ) {
			if ( $id['wp_user_id'] === 0 )     $id['wp_user_id']   = (int) $u->ID;
			if ( $id['display_name'] === '' )  $id['display_name'] = (string) $u->display_name;
			if ( $id['slug'] === '' )          $id['slug']         = (string) $u->user_nicename;
			$id['email'] = (string) $u->user_email;
		}
	}

	// Logged-in proof: either a verified JWT (wp_user_id/uuid set) OR a WP user.
	$logged_in = $id['wp_user_id'] > 0 || $id['user_uuid'] !== '';
	return $logged_in ? $id : null;
}

function lg_bug_report_submit( WP_REST_Request $req ) {
	$who = lg_bug_report_identity();
	if ( $who === null ) {
		return new WP_Error( 'lg_bug_not_logged_in', 'You must be signed in to report a bug.', [ 'status' => 401 ] );
	}

	$message = trim( (string) $req->get_param( 'message' ) );
	if ( $message === '' ) {
		return new WP_Error( 'lg_bug_empty', 'Please describe what went wrong.', [ 'status' => 400 ] );
	}
	// Clamp to a sane size; strip tags (plain-text email body).
	$message = wp_strip_all_tags( $message );
	if ( strlen( $message ) > 8000 ) {
		$message = substr( $message, 0, 8000 ) . "\n…(truncated)";
	}

	// Auto-captured context.
	$page_url = esc_url_raw( (string) $req->get_param( 'page_url' ) );
	$ua       = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 500 ) : '';
	$referer  = isset( $_SERVER['HTTP_REFERER'] )    ? (string) $_SERVER['HTTP_REFERER'] : '';
	$ip       = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
		? trim( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] )
		: (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

	// If the client didn't send page_url, the Referer is the next-best source.
	if ( $page_url === '' && $referer !== '' ) {
		$page_url = esc_url_raw( $referer );
	}

	$who_label = $who['display_name'] !== '' ? $who['display_name'] : ( $who['slug'] !== '' ? $who['slug'] : 'member #' . $who['wp_user_id'] );

	$lines = [
		'A bug report was submitted on the Looth site.',
		'',
		'── What went wrong ─────────────────────────────',
		$message,
		'',
		'── Context (auto-captured) ─────────────────────',
		'Page URL    : ' . ( $page_url !== '' ? $page_url : '(not supplied)' ),
		'Reporter    : ' . $who_label,
		'  user_uuid : ' . ( $who['user_uuid'] !== '' ? $who['user_uuid'] : '(none)' ),
		'  wp_user_id: ' . ( $who['wp_user_id'] > 0 ? $who['wp_user_id'] : '(none)' ),
		'  slug      : ' . ( $who['slug'] !== '' ? $who['slug'] : '(none)' ),
		'  email     : ' . ( $who['email'] !== '' ? $who['email'] : '(unknown)' ),
		'User-Agent  : ' . ( $ua !== '' ? $ua : '(none)' ),
		'Client IP   : ' . ( $ip !== '' ? $ip : '(none)' ),
		'Submitted   : ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
	];
	$body = implode( "\n", $lines );

	$subject = 'Looth bug report — ' . $who_label;

	$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
	// Reply-To the reporter when we know their email, so Ian can reply directly.
	if ( $who['email'] !== '' && is_email( $who['email'] ) ) {
		$headers[] = 'Reply-To: ' . $who['display_name'] . ' <' . $who['email'] . '>';
	}

	$sent = wp_mail( LG_BUG_REPORT_TO, $subject, $body, $headers );
	if ( ! $sent ) {
		return new WP_Error( 'lg_bug_mail_failed', 'Could not send the report. Please try again later.', [ 'status' => 500 ] );
	}

	return rest_ensure_response( [ 'ok' => true, 'message' => 'Thanks — your report was sent.' ] );
}
