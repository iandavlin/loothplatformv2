<?php
defined( 'ABSPATH' ) || exit;

/**
 * LG_WD_Recap_Source — where the recap's material comes from, and the FluentCRM
 * seam that makes one campaign render differently for every recipient.
 *
 * ── THE PER-USER SEAM (Ian's decision 1, 2026-07-27, verified on dev2) ───────
 *
 * The digest sends as ONE FluentCRM campaign with `design_template: raw_html` and a
 * single `email_body` (LG_WD_Sender_FluentCRM::send), so per-user content cannot be
 * baked at compose time. It is substituted at SEND time instead, by a custom smart
 * code — the same mechanism `##crm.unsubscribe_url##` already rides in
 * templates/email.php.
 *
 * The survey the lane was told to do first, and what it found:
 *
 *  1. `FluentCrmApi('extender')->addSmartCode($key, $title, $codes, $callback)`
 *     exists (Api/Classes/Extender.php:84) and registers
 *     `fluent_crm/smartcode_group_callback_<key>`, which ShortcodeParser dispatches
 *     to for any unknown group (Parser/ShortcodeParser.php:160) with
 *     ($code, $valueKey, $default, $subscriber). That is a genuine per-subscriber
 *     callback.
 *  2. It runs PER RECIPIENT: CampaignEmail::getEmailBody() applies
 *     `fluent_crm/parse_campaign_email_text` to every CampaignEmail row
 *     (Models/CampaignEmail.php:277), and that filter is
 *     Parser::parse($text, $subscriber) (Hooks/filters.php:27).
 *  3. Critically, `raw_html` BYPASSES FluentCRM's per-campaign body cache. For
 *     block templates getParsedEmailBody() memoises one parsed body per campaign
 *     in a static (CampaignEmail.php:405-415) — which would have baked ONE
 *     member's recap into everyone's email. For raw_html the code takes
 *     `$this->campaign->email_body` fresh per row (CampaignEmail.php:259-261) and
 *     the static is never consulted. The digest sends raw_html, so we are on the
 *     safe side of that branch — but it is a real trap for anyone who later
 *     "improves" the digest onto a block template.
 *
 * Proven on dev2 against three real subscribers before any of this was written:
 * the same template string produced three different bodies.
 *
 * KNOWN LIMIT (cosmetic, not correctness): FluentCRM caches the click-tracking URL
 * map per campaign when the body has no conditionals (CampaignEmail::getCampaignUrls,
 * :455-459), so per-recipient recap links are NOT click-tracked — the map is built
 * from whichever recipient rendered first. The links themselves are correct and work
 * for everyone; only the click metric is missing. Not worth defeating the cache for.
 *
 * ── WHERE THE DATA COMES FROM ────────────────────────────────────────────────
 *
 * profile-app's /internal/recap over loopback, because WordPress cannot read the
 * `profile_app` database (the WP pool is `looth-dev`, which holds zero grants on
 * it). This is the read twin of the write path notify-bridge.php already uses.
 * Titles are resolved HERE, WP-side, because a notification's target_id is a WP
 * post id and get_the_title() is the cheap way to turn it into a name.
 */
class LG_WD_Recap_Source {

	/** Shared secret, same file the notify bridge reads. */
	const SECRET_FILE = '/etc/lg-internal-secret';

	/** Smart code group. `##lg_recap.section##` in templates/email.php. */
	const SMARTCODE_KEY = 'lg_recap';

	/** Per-request memo: wp_user_id → payload. */
	private static $cache = [];

	// ── Boot ──────────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'fluentcrm_loaded', [ __CLASS__, 'register_smartcode' ] );
	}

	/**
	 * Register `##lg_recap.section##`.
	 *
	 * Returning '' when the member has nothing is what makes "empty means absent"
	 * work end to end: the token vanishes from the body and the email is
	 * byte-identical to today's digest for that recipient.
	 */
	public static function register_smartcode(): void {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			return;
		}

		FluentCrmApi( 'extender' )->addSmartCode(
			self::SMARTCODE_KEY,
			'Looth weekly recap',
			[ 'section' => 'Personal "Your week" recap section' ],
			function ( $code, $valueKey, $defaultValue, $subscriber ) {
				if ( $valueKey !== 'section' ) {
					return '';
				}
				try {
					return self::render_for_subscriber( $subscriber );
				} catch ( \Throwable $e ) {
					// A recap that cannot be built must never cost a member their
					// digest. Swallow, log, and send the email without the section.
					error_log( '[LG Weekly Digest] recap smartcode failed: ' . $e->getMessage() );
					return '';
				}
			}
		);
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/** FluentCRM subscriber → their recap HTML, or '' for nothing-this-week. */
	public static function render_for_subscriber( $subscriber ): string {
		$wp_user_id = self::wp_user_id_for( $subscriber );
		if ( $wp_user_id < 1 ) {
			// A subscriber with no WP account (an email-only list member) has no
			// activity by definition — no bell rows, no DMs. No section.
			return '';
		}

		$payload = self::payload_for( $wp_user_id );
		if ( ! $payload ) {
			return '';
		}

		return LG_WD_Recap::render( $payload, self::render_opts() );
	}

	/**
	 * Layout knobs, filterable so the answers to the three open design questions
	 * (WEEKLY-DIGEST-RECAP.md §4) become a one-line change rather than a rewrite.
	 */
	public static function render_opts(): array {
		return apply_filters( 'lg_wd_recap_opts', LG_WD_Recap::defaults() );
	}

	/**
	 * A FluentCRM subscriber's WP user id.
	 *
	 * `user_id` is populated when the contact is linked to a WP user; when it is
	 * not, fall back to matching on email, which is how the weekly list was built
	 * in the first place.
	 */
	private static function wp_user_id_for( $subscriber ): int {
		if ( ! $subscriber ) {
			return 0;
		}
		$uid = (int) ( $subscriber->user_id ?? 0 );
		if ( $uid > 0 ) {
			return $uid;
		}
		$email = (string) ( $subscriber->email ?? '' );
		if ( $email === '' ) {
			return 0;
		}
		$user = get_user_by( 'email', $email );
		return $user ? (int) $user->ID : 0;
	}

	// ── Fetch ─────────────────────────────────────────────────────────────────

	/**
	 * Recap payload for one member, titles hydrated. Empty array = nothing.
	 *
	 * Memoised per request. The smart code fires once per recipient inside a send
	 * batch, so the memo mostly guards against a preview and a send in the same
	 * request, and against a template that ever carries the token twice.
	 */
	public static function payload_for( int $wp_user_id ): array {
		if ( isset( self::$cache[ $wp_user_id ] ) ) {
			return self::$cache[ $wp_user_id ];
		}
		$all = self::fetch( [ $wp_user_id ] );
		return self::$cache[ $wp_user_id ] ?? ( $all[ $wp_user_id ] ?? [] );
	}

	/**
	 * Fetch (and memoise) recaps for a batch of members.
	 *
	 * Public so a send can prime a whole chunk in one round trip instead of paying
	 * ~1,700 of them one at a time.
	 *
	 * @param int[] $wp_user_ids
	 * @return array<int, array> keyed by wp user id; members with nothing are
	 *                           memoised as [] so they are not re-fetched.
	 */
	public static function fetch( array $wp_user_ids, int $days = 7 ): array {
		$want = array_values( array_filter( array_map( 'intval', $wp_user_ids ), fn( $i ) => $i > 0 ) );
		if ( ! $want ) {
			return [];
		}

		/**
		 * Short-circuit the loopback call.
		 *
		 * Return an array shaped like the endpoint's `recaps` (keyed by wp user id)
		 * to supply the material from somewhere else; return null to fetch normally.
		 * Exists so a send can prime a whole chunk from one place, and so the
		 * substitution path can be exercised without standing up the HTTP route.
		 *
		 * @param array|null $recaps
		 * @param int[]      $want
		 * @param int        $days
		 */
		$pre = apply_filters( 'lg_wd_recap_fetch', null, $want, $days );
		$res = is_array( $pre ) ? [ 'recaps' => $pre ] : self::post( [ 'wp_user_ids' => $want, 'days' => $days ] );
		$out = [];

		foreach ( $want as $id ) {
			$raw = $res['recaps'][ (string) $id ] ?? $res['recaps'][ $id ] ?? null;
			$payload = is_array( $raw ) ? self::hydrate_titles( $raw ) : [];

			// An all-empty payload is normalised to [] so callers have one test for
			// "nothing this week" instead of two.
			if ( empty( $payload['notifications'] ) && empty( $payload['dms'] ) ) {
				$payload = [];
			}

			self::$cache[ $id ] = $payload;
			$out[ $id ]         = $payload;
		}

		return $out;
	}

	/**
	 * Resolve WP post titles for the rows that name a thing.
	 *
	 * target_id is a WP post id for every hub type — the topic id for
	 * topic/reply rows (the reply itself is anchor_id) and the content-item id for
	 * card rows (notify-bridge.php:266-296). A post that has since been deleted
	 * yields '' and the renderer falls back to untitled wording rather than
	 * printing an empty pair of quotes.
	 */
	private static function hydrate_titles( array $payload ): array {
		$payload['notifications'] = $payload['notifications'] ?? [];
		$payload['dms']           = $payload['dms'] ?? [];

		foreach ( $payload['notifications'] as &$n ) {
			$n['title'] = '';
			$tid = (int) ( $n['target_id'] ?? 0 );
			if ( $tid > 0 && get_post_status( $tid ) ) {
				$n['title'] = (string) get_the_title( $tid );
			}
		}
		unset( $n );

		return $payload;
	}

	/**
	 * POST to the loopback endpoint.
	 *
	 * Same convention the notify bridge uses: https://127.0.0.1 with an explicit
	 * Host header and no peer verification — plain http:// gets a 301 from the
	 * vhost and the POST body is lost. Failure returns an empty result rather than
	 * throwing: a recap the box could not fetch means no section, never a failed
	 * digest.
	 */
	private static function post( array $body ): array {
		$secret = @file_get_contents( self::SECRET_FILE );
		if ( ! is_string( $secret ) || trim( $secret ) === '' ) {
			error_log( '[LG Weekly Digest] recap: no internal secret readable — section skipped' );
			return [ 'recaps' => [] ];
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';
		$ch   = curl_init( 'https://127.0.0.1/profile-api/v0/internal/recap' );
		curl_setopt_array( $ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Host: ' . $host,
				'X-LG-Internal-Auth: ' . trim( $secret ),
			],
		] );

		$raw  = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$err  = curl_error( $ch );
		curl_close( $ch );

		if ( $raw === false || $code !== 200 ) {
			error_log( '[LG Weekly Digest] recap fetch failed: HTTP ' . $code . ' ' . $err );
			return [ 'recaps' => [] ];
		}

		$json = json_decode( (string) $raw, true );
		if ( ! is_array( $json ) || empty( $json['ok'] ) ) {
			error_log( '[LG Weekly Digest] recap fetch returned a bad body' );
			return [ 'recaps' => [] ];
		}

		return [ 'recaps' => $json['recaps'] ?? [] ];
	}
}
