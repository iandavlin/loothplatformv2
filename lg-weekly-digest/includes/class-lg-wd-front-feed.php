<?php
/**
 * The front page's read-only feed of the latest SENT issue. Backlog item 8.
 *
 * Ian 2026-07-30: surface the most-recent weekly email on the FRONT PAGE for
 * logged-out visitors. Ruled 2026-08-15 after the mock: build it, Option A —
 * the issue's contents rendered as the front page's own cards.
 *
 * ── WHY THIS EXISTS AT ALL, WHEN THE FRONT PAGE COULD JUST QUERY ────────────
 * It could not. archive-poc/web/index.php never loads WordPress (its own header
 * says "No WP needed") — it runs on a separate FPM pool against a read-only
 * Postgres discovery index. Booting WordPress there would put a ~0.8s
 * BuddyBoss bootstrap on every anonymous front-page render, on a 2-core box.
 *
 * And it must not re-derive the week's content either. The issue is already
 * resolved by LG_WD_Query::build_payload_from_issue() — the SAME call the email
 * goes through — which handles post lookup, excerpt trimming, hand-typed
 * manual items, and hub-correct URLs for forum topics. This endpoint is a thin
 * public projection of that, not a second implementation. See
 * docs/BACKLOG-8-STORE-AND-BUILD-PLAN.md.
 *
 * ── FOUR THINGS IT DOES THAT THE EMAIL DOES NOT, EACH MEASURED ──────────────
 *
 * 1. STRIPS EXCERPTS FROM GATED ITEMS. The payload carries prose for every
 *    item regardless of tier, because an email decides entitlement at the
 *    click, not at the render. A public front page decides at the render. The
 *    strip happens HERE, inside WordPress, rather than being hidden by the
 *    front page: an endpoint that emits gated prose and trusts its caller to
 *    conceal it is one CSS mistake from a leak. Measured 2026-08-15: card
 *    items on this platform come back with EMPTY excerpts anyway (layout-v2
 *    posts keep their body in meta), so today this strip mostly bites the
 *    `forum` section — which is exactly the section that does carry prose, and
 *    one clean_excerpt() change from it biting everywhere.
 *
 * 2. MAPS THE TIER SLUG, and this one fails silently if forgotten. The `tier`
 *    taxonomy's terms are `looth-lite` / `looth-pro` / `public`; the front
 *    page's CSS is `.rcard--gated-lite` and `.badge--lite`. Passing the raw
 *    slug through yields `rcard--gated-looth-lite`, which matches no rule —
 *    so a GATED CARD RENDERS WITH NO PADLOCK and reads as free content. No
 *    error, no warning, just a member-only item that looks open.
 *
 * 3. DROPS ARCHIVED POSTS. normalize_posts_by_ids() deliberately asks for
 *    post_status IN (publish, closed, open, ARCHIVED) — right for an email,
 *    which is a record of what was sent. Wrong for a shop window, which is a
 *    claim about what is there now. Measured on live: the August 10 issue
 *    contains post 72616, archived after the send.
 *
 * 4. SKIPS THE EVENTS SECTION. An issue's events are a `date-forward` list of
 *    what was upcoming AT SEND TIME. A week later they have happened. The
 *    front page already carries its own live upcoming-events row, so replaying
 *    a stale one beside it would be both wrong and duplicated.
 *
 * ── WHY admin-ajax AND NOT A REST ROUTE ─────────────────────────────────────
 * Same reason LG_WD_Signup_Page::init() documents at length: admin-ajax is
 * routed by WordPress unconditionally, so it cannot be intercepted by the
 * strangler that owns `/`, and it needs no rewrite and no new symlink. Two
 * bugs in two days came from tying the sample-email preview to a routable page.
 *
 * This file lives inside lg-weekly-digest, which is symlinked into the docroot
 * as a WHOLE DIRECTORY, so it arrives with an ordinary git pull and needs no
 * new symlink of its own — the one deploy coupling a pull does not handle.
 *
 * @package LG_Weekly_Digest
 */

defined( 'ABSPATH' ) || exit;

class LG_WD_Front_Feed {

	/** admin-ajax action serving the feed. */
	const ACTION = 'lg_wd_front_feed';

	/** Transient holding the built feed. */
	const CACHE = 'lg_wd_front_feed_payload';

	/**
	 * One hour, matching the sample-email preview. The issue changes weekly, so
	 * this is not about freshness — it is about not rebuilding a payload that
	 * walks a dozen posts on every front-page cache miss.
	 */
	const TTL = HOUR_IN_SECONDS;

	/**
	 * Section templates this endpoint refuses to project.
	 *
	 * `date-forward` — events, see §4 above.
	 * `html-block`   — a raw HTML blob authored for a 960px email column. It has
	 *                  no card shape to map onto, and injecting arbitrary stored
	 *                  HTML into the front page is a bigger decision than this
	 *                  feature. Dropped rather than half-rendered.
	 */
	const SKIP_TEMPLATES = [ 'date-forward', 'html-block' ];

	public static function init(): void {
		add_action( 'wp_ajax_'        . self::ACTION, [ __CLASS__, 'serve' ] );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ __CLASS__, 'serve' ] );
	}

	/**
	 * The flag. Reads the tracked config, then getenv() and $_SERVER — a
	 * fastcgi_param lands in $_SERVER but not reliably in the environment, so
	 * reading only one serves the OFF path on a lane preview URL.
	 */
	public static function enabled(): bool {
		$cfg = @include dirname( __DIR__, 2 ) . '/platform/config/weekly-front.php';
		$on  = is_array( $cfg ) && ! empty( $cfg['enabled'] );

		foreach ( [ getenv( 'LG_WEEKLY_FRONT' ), $_SERVER['LG_WEEKLY_FRONT'] ?? false ] as $o ) {
			if ( $o !== false && $o !== '' ) {
				$on = ( $o === '1' || $o === 'true' );
			}
		}
		return $on;
	}

	/**
	 * Serve the feed as JSON, or 404 when the flag is off.
	 *
	 * 404 rather than an empty 200: OFF must add NO public surface, not an empty
	 * one. A 200 with `{"sections":[]}` is a new endpoint that exists and says
	 * nothing, which is a different (and weaker) claim than "this is not here".
	 */
	public static function serve(): void {
		if ( ! self::enabled() ) {
			status_header( 404 );
			wp_send_json( [ 'error' => 'disabled' ], 404 );
		}

		$payload = get_transient( self::CACHE );
		if ( ! is_array( $payload ) ) {
			$payload = self::build();
			set_transient( self::CACHE, $payload, self::TTL );
		}

		header( 'X-Robots-Tag: noindex' );
		wp_send_json( $payload );
	}

	/**
	 * Build the public projection of the latest sent issue.
	 *
	 * Returns [] when there is no sent issue — the front page treats that as
	 * "no block", by the same route it treats the flag being off.
	 */
	public static function build(): array {
		if ( ! class_exists( 'LG_WD_Issue' ) || ! class_exists( 'LG_WD_Query' ) ) {
			return [];
		}

		$issue_id = LG_WD_Issue::latest_sent_id();
		if ( ! $issue_id ) {
			return [];
		}

		$data = LG_WD_Issue::get_data( $issue_id );
		if ( empty( $data['sections'] ) ) {
			return [];
		}

		$raw = LG_WD_Query::build_payload_from_issue( $data );
		if ( empty( $raw ) ) {
			return [];
		}

		$sections = [];
		foreach ( $raw as $entry ) {
			$section  = $entry['section'] ?? [];
			$template = $section['template'] ?? 'card';

			// Group headers carry no items; the front-page block uses each
			// section's own label, so a divider with nothing under it is noise.
			if ( self::skip_section( $template, ! empty( $entry['is_header'] ) ) ) {
				continue;
			}

			$items = [];
			foreach ( $entry['items'] ?? [] as $it ) {
				$item = self::project_item( $it, $template );
				if ( $item !== null ) {
					$items[] = $item;
				}
			}
			if ( ! $items ) {
				continue;
			}

			$sections[] = [
				'key'      => (string) ( $section['key'] ?? '' ),
				'label'    => (string) ( $section['label'] ?? '' ),
				'template' => $template,
				'items'    => $items,
			];
		}

		if ( ! $sections ) {
			return [];
		}

		return [
			'issue_id' => $issue_id,
			// The issue's OWN send date, never the render clock. The email
			// builder dates itself date_i18n('F j, Y') at render time, which is
			// right on send day and wrong for every later re-render — measured
			// on dev2, where the sample preview shows the July 13 issue under
			// "Week of August 15, 2026". This feed re-renders an old issue by
			// definition, so it must not repeat that.
			'sent_at'  => (string) ( $data['sent_at'] ?? '' ),
			'sections' => $sections,
		];
	}

	/**
	 * One item, projected for an anonymous viewer.
	 *
	 * Returns null for anything that must not appear at all.
	 */
	private static function project_item( array $it, string $template ): ?array {
		$post_id = (int) ( $it['id'] ?? 0 );

		// Hand-typed manual items have id 0 and no post behind them. They are
		// legitimate issue content, so they pass — but they can carry neither a
		// tier nor a post_status, so they are treated as public and unarchived,
		// which is what they are: an editor typed them in on purpose.
		if ( $post_id > 0 && self::hidden_status( (string) get_post_status( $post_id ) ) ) {
			return null;
		}

		$tier   = self::map_tier( (string) ( $it['tier_slug'] ?? '' ) );
		$gated  = ( $tier !== 'public' );
		$title  = (string) ( $it['title'] ?? '' );
		$url    = (string) ( $it['url'] ?? '' );

		if ( $title === '' || $url === '' ) {
			return null;
		}

		return [
			'id'        => $post_id,
			'title'     => $title,
			'url'       => $url,
			'thumb_url' => (string) ( $it['thumb_url'] ?? '' ),
			'date'      => (string) ( $it['date'] ?? '' ),
			'kind'      => self::map_kind( (string) ( $it['post_type'] ?? '' ), $template ),
			'tier'      => $tier,
			'gated'     => $gated,
			// THE LEAK RULE. Gated prose never leaves WordPress.
			'excerpt'   => $gated ? '' : (string) ( $it['excerpt'] ?? '' ),
			// normalize_post() emits no author — measured; author_name exists
			// only on manual items. Resolve it here so a card can carry a
			// byline instead of quietly dropping one.
			'author'    => self::author_for( $it, $post_id ),
		];
	}

	/**
	 * Taxonomy slug -> the token the front page's CSS actually uses.
	 *
	 * Getting this wrong does not throw: it produces `rcard--gated-looth-lite`,
	 * which matches nothing, so the padlock silently disappears from a
	 * member-only card. Anything unrecognised is treated as GATED, not public —
	 * a new tier must fail closed.
	 */
	private static function map_tier( string $slug ): string {
		switch ( $slug ) {
			case '':
			case 'public':
				return 'public';
			case 'looth-lite':
			case 'lite':
				return 'lite';
			case 'looth-pro':
			case 'pro':
				return 'pro';
			default:
				return 'pro';
		}
	}

	/**
	 * Statuses that must never reach a public front page.
	 *
	 * A PREDICATE RATHER THAN AN INLINE COMPARISON, so gate 54 can call it and
	 * actually break it. The first version of that gate asserted this by
	 * grepping the file for the word "archived" — which the docblock above
	 * satisfies all by itself, so the assertion stayed green when the filter was
	 * deleted. Behaviour is testable; prose is not.
	 *
	 * normalize_posts_by_ids() asks for publish/closed/open/ARCHIVED. That is
	 * right for an email, which is a record of what was sent, and wrong for a
	 * shop window, which is a claim about what is there now. Measured on live:
	 * the August 10 issue carries post 72616, archived after the send.
	 */
	private static function hidden_status( string $status ): bool {
		return in_array( $status, [ 'archived', 'trash', 'draft', 'pending', 'auto-draft', 'future' ], true );
	}

	/**
	 * Sections this endpoint refuses to project. Same reasoning as
	 * hidden_status(): a predicate, so it can be broken and caught.
	 */
	private static function skip_section( string $template, bool $is_header ): bool {
		if ( $is_header || $template === 'header' ) {
			return true;                    // a divider with no items under it
		}
		return in_array( $template, self::SKIP_TEMPLATES, true );
	}

	/** Post type -> the front page's card kind. */
	private static function map_kind( string $post_type, string $template ): string {
		if ( $template === 'forum' || $post_type === 'topic' ) {
			return 'discussion';
		}
		switch ( $post_type ) {
			case 'post-type-videos': return 'video';
			case 'loothprint':       return 'loothprint';
			case 'loothcuts':        return 'loothcuts';
			case 'external':         return 'external';
			default:                 return 'article';
		}
	}

	/** Manual items carry their own author; posts need one resolving. */
	private static function author_for( array $it, int $post_id ): string {
		$manual = trim( (string) ( $it['author_name'] ?? '' ) );
		if ( $manual !== '' ) {
			return $manual;
		}
		if ( $post_id <= 0 ) {
			return '';
		}
		$author_id = (int) get_post_field( 'post_author', $post_id );
		if ( ! $author_id ) {
			return '';
		}
		return (string) get_the_author_meta( 'display_name', $author_id );
	}

	/**
	 * Drop the cached payload. Called when an issue is saved or sent, so the
	 * front page picks a new issue up without waiting out the hour.
	 */
	public static function flush(): void {
		delete_transient( self::CACHE );
	}
}
