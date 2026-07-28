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

	/**
	 * ── THE WINDOW. FIXED AT SEVEN DAYS. IAN RULED IT 2026-07-28. ─────────────
	 *
	 * The digest looks back exactly this far, every send, for every member. It is a
	 * declared constant rather than a default argument because it is a DECISION, and
	 * a decision that lives in a default is one any future call site can overturn by
	 * accident.
	 *
	 * TWO ALTERNATIVES WERE BUILT UP, MEASURED ON LIVE, AND DECLINED BY IAN — they
	 * are recorded here so nobody re-derives them as though they were never asked:
	 *
	 *   3a  window starts at the previous digest's send time (global)
	 *   3b  window starts at the last digest THIS MEMBER ACTUALLY RECEIVED
	 *
	 * 3b was this lane's recommendation and the case for it was real: on 2026-06-01
	 * the campaign `Weekly Digest — June 1, 2026` lost six members to a single SES
	 * `SignatureDoesNotMatch`, all six still marked `failed` eight weeks later and
	 * never re-sent. Shown side by side (2 rows vs 6 for a real member), Ian weighed
	 * that against predictability and chose predictability. His call, and it is
	 * settled. See docs/atlas/RECAP-SUPPRESSION-PROPOSAL.md §2 Rule 3.
	 *
	 * THE CONSEQUENCE HE ACCEPTED, stated here so it is designed around honestly
	 * rather than rediscovered as a bug: **a member who misses one digest never
	 * hears about that week.** Items older than this window are gone from the email
	 * permanently — they remain in the bell, but nothing will mail them again.
	 *
	 * DO NOT widen this to compensate, and do not make it conditional. If the fixed
	 * window ever needs to move, it moves HERE, once, for everyone, deliberately.
	 */
	const WINDOW_DAYS = 7;

	/** Per-request memo: wp_user_id → payload. */
	private static $cache = [];

	/**
	 * Did the recap source actually ANSWER the last fetch?
	 *
	 * post() used to throw this away: every failure path — no secret, curl error,
	 * non-200, unparseable body — returned `['recaps' => []]`, which is byte-for-byte
	 * what a healthy source returns when nobody has anything. That was harmless while
	 * an empty payload only cost a section.
	 *
	 * Under "empty means send nothing" (Ian, 2026-07-28) it stops being harmless: the
	 * two cases now mean "mail nobody" and "mail nobody", and one of them is an
	 * outage. Without this flag the recipient filter cannot tell a quiet week from a
	 * dead endpoint, and the failure is invisible — no bounce, no error, the digest
	 * just stops. So the transport outcome is recorded rather than discarded.
	 */
	private static $source_answered = true;

	/** True if the last fetch reached a healthy source. See $source_answered. */
	public static function source_answered(): bool {
		return self::$source_answered;
	}

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

		return LG_WD_Recap::render( $payload );
	}


	/**
	 * A FluentCRM subscriber's WP user id.
	 *
	 * `user_id` is populated when the contact is linked to a WP user; when it is
	 * not, fall back to matching on email, which is how the weekly list was built
	 * in the first place.
	 */
	/**
	 * ── EMPTY MEANS SEND NOTHING (Ian, 2026-07-28) ────────────────────────────
	 *
	 * Of a resolved recipient list, the subset that actually has something waiting
	 * on them. A member with nothing gets NO EMAIL — not the digest minus the
	 * section. His rationale, worth keeping because it is the whole design: an email
	 * from us should mean something genuinely wants them. That protects the signal.
	 *
	 * THIS SUPERSEDES the earlier empty-case behaviour (an empty recap rendering a
	 * body byte-identical to the no-recap digest). That proof still holds and its
	 * test is kept, repointed — see dev/verify-empty-means-no-send.php.
	 *
	 * ONE ROUND TRIP PER CHUNK, not one per member: fetch() has been public and
	 * batch-shaped since it was written, for exactly this. At list-3 size that is
	 * ~1,700 members in chunks rather than ~1,700 loopback calls.
	 *
	 * FAIL-OPEN, DELIBERATELY, AND THIS IS THE IMPORTANT LINE. If the recap source
	 * cannot be reached, every member comes back as "has something" and the send
	 * goes out whole. The alternative fails CLOSED — one unreachable endpoint and
	 * the entire week's digest silently mails nobody, which looks exactly like a
	 * quiet week. A gate whose absent-signal branch is restrictive turns an outage
	 * into a total send failure with no error anywhere.
	 *
	 * @param int[] $subscriber_ids FluentCRM subscriber ids
	 * @return int[] the subset to actually mail, order preserved
	 */
	public static function recipients_with_something_waiting( array $subscriber_ids ): array {
		$subscriber_ids = array_values( array_unique( array_filter(
			array_map( 'intval', $subscriber_ids ), fn( $i ) => $i > 0 ) ) );
		if ( ! $subscriber_ids ) {
			return [];
		}
		if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
			self::log_skip( 'FluentCRM Subscriber model missing — sending to everyone' );
			return $subscriber_ids;
		}

		$keep = [];
		foreach ( array_chunk( $subscriber_ids, 200 ) as $chunk ) {
			$subs   = \FluentCrm\App\Models\Subscriber::whereIn( 'id', $chunk )->get();
			$wp_by_sub = [];
			foreach ( $subs as $sub ) {
				$wp = self::wp_user_id_for( $sub );
				if ( $wp > 0 ) {
					$wp_by_sub[ (int) $sub->id ] = $wp;
				}
				// A subscriber with no WP account has no bell rows and no DMs by
				// definition. Nothing can be waiting on them, so they are dropped
				// rather than fail-opened — that is a real answer, not a missing one.
			}
			if ( ! $wp_by_sub ) {
				continue;
			}

			$payloads = self::fetch( array_values( $wp_by_sub ) );

			// FAIL OPEN on an outage — and ask the transport, not the shape of the
			// result. `$payloads === []` was the first version of this check and it
			// is WRONG: fetch() normalises every requested id to [], so a dead
			// endpoint and a genuinely quiet week produce identical arrays and the
			// check never fires. It failed CLOSED, silently, which is the exact
			// failure this branch exists to prevent. Caught by
			// dev/verify-empty-means-no-send.php case 5.
			if ( ! self::source_answered() ) {
				self::log_skip( 'recap source did not answer for a chunk of '
					. count( $wp_by_sub ) . ' — keeping them all rather than mailing nobody' );
				$keep = array_merge( $keep, array_keys( $wp_by_sub ) );
				continue;
			}
			foreach ( $wp_by_sub as $sub_id => $wp ) {
				if ( ! empty( $payloads[ $wp ] ) ) {
					$keep[] = $sub_id;
				}
			}
		}

		// Preserve the caller's order; $keep is built per chunk.
		$set = array_flip( $keep );
		return array_values( array_filter( $subscriber_ids, fn( $id ) => isset( $set[ $id ] ) ) );
	}

	private static function log_skip( string $msg ): void {
		error_log( '[LG Weekly Digest] recipient filter: ' . $msg );
	}

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
	 * THE WINDOW IS NOT A PARAMETER HERE, deliberately. It was one while Rules 3a
	 * and 3b were live options — both wanted a value computed per send. Ian declined
	 * both (2026-07-28) and fixed the window at self::WINDOW_DAYS, so a per-call
	 * override is now flexibility nobody uses, and the kind that quietly becomes a
	 * second window. The endpoint still takes `days` because /internal/recap is a
	 * general read API that dev verification legitimately drives at other widths
	 * (dev/verify-missed-exclusions.php opens it to 3650 to isolate a rule); the
	 * DIGEST's window is this constant and has exactly one writer.
	 *
	 * @param int[] $wp_user_ids
	 * @return array<int, array> keyed by wp user id; members with nothing are
	 *                           memoised as [] so they are not re-fetched.
	 */
	public static function fetch( array $wp_user_ids ): array {
		$days = self::WINDOW_DAYS;
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
		 * Return FALSE to say "the source is unavailable" — distinct from an array
		 * of nothing, which says "the source answered and these members have
		 * nothing". Those two were indistinguishable until 2026-07-28 and the
		 * difference now decides whether a member is mailed at all.
		 *
		 * @param array|null|false $recaps
		 * @param int[]            $want
		 * @param int              $days
		 */
		$pre = apply_filters( 'lg_wd_recap_fetch', null, $want, $days );

		if ( $pre === false ) {
			self::$source_answered = false;
			$res = [ 'recaps' => [] ];
		} elseif ( is_array( $pre ) ) {
			self::$source_answered = true;      // a filter supplying data IS an answer
			$res = [ 'recaps' => $pre ];
		} else {
			$res = self::post( [ 'wp_user_ids' => $want, 'days' => $days ] );
		}
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
			self::$source_answered = false;
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
			self::$source_answered = false;
			return [ 'recaps' => [] ];
		}

		$json = json_decode( (string) $raw, true );
		if ( ! is_array( $json ) || empty( $json['ok'] ) ) {
			error_log( '[LG Weekly Digest] recap fetch returned a bad body' );
			self::$source_answered = false;
			return [ 'recaps' => [] ];
		}

		self::$source_answered = true;
		return [ 'recaps' => $json['recaps'] ?? [] ];
	}
}
