<?php
/**
 * The PUBLIC weekly-email signup page — the real surface, not the mock.
 *
 * Ian, 2026-07-29 (docs/BACKLOG.md): "A page where someone WITHOUT a WP account
 * signs up for the weekly email. No WP user may be required or created."
 *
 * ── WHY THIS LIVES IN THE PLUGIN AND NOT IN A NEW mu-plugin ─────────────────
 * `lg-weekly-digest` is symlinked into the docroot AS A WHOLE DIRECTORY
 * (/var/www/dev/wp-content/plugins/lg-weekly-digest -> the serving checkout), so a
 * new file inside it arrives with an ordinary `git pull` and needs NO new symlink.
 * A new mu-plugin would need one created in the same window as the pull —
 * mu-plugins are symlinked INDIVIDUALLY (33 of them) and that coupling is the one
 * step a pull does not handle. Putting the page here removes the coupling entirely.
 *
 * ── WHERE THE SEAM IS, so nobody looks for the write in this file ───────────
 * This file RENDERS and it POSTS. It never touches FluentCRM. The endpoint is
 * `wp_ajax_nopriv_lg_weekly_signup` in platform/mu-plugins/lg-event-reminders.php,
 * which carries Ian's ruling 6 (four audiences; only `new` writes; the MEMBER list
 * is never written) and is proven by dev/verify-signup-audience.php. That test
 * brace-matches the handler inside that file, so the handler stays there.
 *
 * The four endpoint states this page must render, and all four are reachable:
 *   already_member       on the member list  -> told, nothing written
 *   member_needs_prefs   has an account, not on the list (233 on live) -> the truth
 *   already_signed_up    already a non-member subscriber -> no duplicate
 *   pending              new -> added to the NON-MEMBER list, confirmation sent
 *
 * ── CRAFT ───────────────────────────────────────────────────────────────────
 * ZERO <img> tags in the page chrome (section icons are text glyphs), so the
 * resizer/srcset/dimension rules have nothing to bite on. The ONE piece of heavy
 * content is the sample-email iframe, and see render_email_preview() for why its
 * images are routed through the resizer even though the craft gate structurally
 * cannot see inside an iframe to check.
 *
 * @package LG_Weekly_Digest
 */

defined( 'ABSPATH' ) || exit;

class LG_WD_Signup_Page {

	/** Query var that serves the sample email for the iframe (legacy route). */
	const PREVIEW_QV = 'lg_wd_email_preview';

	/** admin-ajax action that serves it — the route that does not depend on page routing. */
	const PREVIEW_ACTION = 'lg_wd_email_preview';

	/** Transient holding the rendered sample email. */
	const PREVIEW_CACHE = 'lg_wd_public_email_preview';

	/** How long a rendered sample is reused. One hour: the issue changes weekly. */
	const PREVIEW_TTL = HOUR_IN_SECONDS;

	public static function init(): void {
		add_shortcode( 'lg_weekly_signup', [ __CLASS__, 'render' ] );

		/**
		 * THE PREVIEW IS SERVED FROM admin-ajax, NOT FROM A PAGE ROUTE.
		 *
		 * It used to hang off `template_redirect`, which meant the URL had to be one
		 * WordPress routes. That coupling produced two bugs in two days:
		 *
		 *   home_url('/')  -> the strangler owns `/` and answered first, so the frame
		 *                     got the DISCOVERY FEED (fixed by moving to the permalink)
		 *   the permalink  -> only exists while this is a WP-rendered PAGE. Ian has
		 *                     ruled that it must render STANDALONE like the rest of the
		 *                     platform, and the moment it does, get_permalink() returns
		 *                     nothing, preview_url() returns '' and the section is
		 *                     silently DROPPED. Not a wrong document — no document.
		 *
		 * admin-ajax is routed by WordPress unconditionally. It cannot be intercepted by
		 * the strangler, needs no rewrite and no new symlink, and is completely
		 * indifferent to what renders the page framing it. So the preview stops caring
		 * where the page lives, which is the property both bugs were missing.
		 *
		 * `nopriv` because the entire audience of this page is logged out.
		 */
		add_action( 'wp_ajax_'        . self::PREVIEW_ACTION, [ __CLASS__, 'serve_preview' ] );
		add_action( 'wp_ajax_nopriv_' . self::PREVIEW_ACTION, [ __CLASS__, 'serve_preview' ] );

		// Kept so URLs already in the wild (and the merged permalink form) still answer.
		add_action( 'template_redirect', [ __CLASS__, 'maybe_serve_preview' ], 0 );
	}

	// ── The sample email ──────────────────────────────────────────────────────

	/**
	 * Serve the most recent SENT issue as a standalone document for the iframe.
	 *
	 * ── WHY A LIVE RENDER AND NOT A COMMITTED SNAPSHOT ──────────────────────
	 * The first design was a static HTML snapshot committed next to the mock. It
	 * was wrong for a reason worth writing down: a snapshot rendered on dev2 carries
	 * dev2 upload URLs, so on live it would show either broken images or another
	 * box's content, and the page's own claim — "this is an issue that actually
	 * went out, rendered by the same code that sends it" — would quietly become
	 * false the first week nobody regenerated it. Rendering the real issue makes
	 * that sentence true on whatever box the page is on, and there is no file to
	 * keep in step.
	 *
	 * ── THE RECAP TOKEN IS STRIPPED EXPLICITLY, NOT INCIDENTALLY ────────────
	 * LG_WD_Sender::send_issue($id, true) would ALSO have worked here — its preview
	 * path renders the recap for get_current_user_id(), which is 0 for an anonymous
	 * visitor, which resolves to nothing. That is safety by accident: it holds only
	 * as long as payload_for(0) keeps returning nothing, and a reader cannot see the
	 * guarantee at this call site. `mode => strip` is the guarantee, stated here.
	 */
	public static function maybe_serve_preview(): void {
		if ( ! isset( $_GET[ self::PREVIEW_QV ] ) ) {
			return;
		}
		self::serve_preview();
	}

	/**
	 * Emit the sample email and exit. ONE implementation behind both routes, so the
	 * admin-ajax route and the legacy page route can never drift into rendering
	 * different documents — which is the failure mode this whole area keeps having.
	 */
	public static function serve_preview(): void {
		$html = get_transient( self::PREVIEW_CACHE );
		if ( ! is_string( $html ) || $html === '' ) {
			$html = self::build_email_preview();
			set_transient( self::PREVIEW_CACHE, $html, self::PREVIEW_TTL );
		}

		// Same-origin framing only. The page frames it from its own host; nothing
		// else has a reason to.
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $html; // phpcs:ignore WordPress.Security.EscapingOutput — a rendered email document.
		exit;
	}

	/** Render the latest sent issue, or '' if there is not one. */
	private static function build_email_preview(): string {
		if ( ! class_exists( 'LG_WD_Issue' ) || ! class_exists( 'LG_WD_Query' ) ) {
			return '';
		}

		$issue_id = self::latest_sent_issue_id();
		if ( ! $issue_id ) {
			return '';
		}

		$data = LG_WD_Issue::get_data( $issue_id );
		if ( empty( $data['sections'] ) ) {
			return '';
		}

		$payload = LG_WD_Query::build_payload_from_issue( $data );
		if ( empty( $payload ) ) {
			return '';
		}

		$html = LG_WD_Email_Builder::build( $payload, [ 'mode' => 'strip' ] );

		return self::route_images_through_resizer( $html );
	}

	/** The most recent issue whose own data says it was sent. */
	private static function latest_sent_issue_id(): int {
		foreach ( LG_WD_Issue::get_all_issues( 20 ) as $issue ) {
			if ( ( $issue['status'] ?? '' ) === 'sent' ) {
				return (int) $issue['id'];
			}
		}
		return 0;
	}

	/**
	 * Point the preview's upload images at the resizer.
	 *
	 * ── THE GATE CANNOT SEE THIS, WHICH IS EXACTLY WHY IT IS DONE ───────────
	 * tools/gates/craft-gate.py collects `document.querySelectorAll('img')` and
	 * `performance.getEntriesByType('resource')` in the TOP frame only. A frame has
	 * its own document and its own resource timeline, so every image inside this
	 * iframe is invisible to IMG-RAW, IMG-OVERSIZE and the page KB budget. The page
	 * would therefore pass the craft gate while shipping full-size uploads into a
	 * 624px column — a green light over an unaudited region.
	 *
	 * MEASURED on dev2 for the July 13 issue, before this rewrite: five upload
	 * images totalling 577KB, the largest a single 308KB file, all served at their
	 * stored width into a 624px-wide email column.
	 *
	 * SAFE BY CONSTRUCTION IF THE RESIZER IS ABSENT. img.php lives in the docroot
	 * (bb-mirror/web/img.php, symlinked to /img.php) and is not guaranteed to exist
	 * on every box, so the rewrite only happens when the file is actually there; and
	 * img.php itself 302s to the original for any source it cannot handle, so a
	 * missing or odd file degrades to today's behaviour instead of a broken image.
	 *
	 * 600 is the resizer's own bucket nearest the email's 624px content column
	 * (ALLOWED_W = 96,240,400,480,600,800,...); an unlisted width silently becomes
	 * 800, so the number is chosen from that list rather than from the column.
	 *
	 * NOT applied to the email itself. Real inboxes fetch images with no cookies,
	 * and img.php sits behind the dev cookie gate on dev2 — routing the SENT email
	 * through it would break images in every recipient's client. This rewrite is
	 * for the same-origin on-page preview only.
	 */
	private static function route_images_through_resizer( string $html ): string {
		if ( $html === '' || ! file_exists( ABSPATH . 'img.php' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#(src=")([^"]*?/wp-content/uploads/)([^"]+)(")#i',
			static function ( array $m ): string {
				$path = $m[3];
				// Already a resizer URL, or a query string we would corrupt.
				if ( str_contains( $path, 'img.php' ) || str_contains( $path, '?' ) ) {
					return $m[0];
				}
				return $m[1] . '/img.php?s=' . rawurlencode( $path ) . '&w=600' . $m[4];
			},
			$html
		);
	}

	/**
	 * The URL the iframe points at, or '' when there is nothing to show.
	 *
	 * ── IT MUST NOT BE home_url('/'). THE STRANGLER OWNS THAT PATH ──────────
	 * maybe_serve_preview() hangs off `template_redirect`, so it only fires for a
	 * request WordPress actually routes. `/` on this platform is served by
	 * archive-poc's discovery feed, which answers before WP's template_redirect
	 * ever runs — so `/?lg_wd_email_preview=1` returns the DISCOVER FEED and the
	 * handler is never reached.
	 *
	 * That is not theoretical. Measured on dev2 2026-07-30, both anon:
	 *   /?lg_wd_email_preview=1                  -> 75,458B, <title>Looth Group —
	 *                                               Lutherie Community</title>,
	 *                                               ZERO unsubscribe markers
	 *   /weekly-email-sign-up/?lg_wd_email_preview=1
	 *                                            -> 37,321B, <title>The Looth Group
	 *                                               — Week of July 30, 2026</title>
	 * The section promises "this is the most recent issue that actually went out".
	 * With the old URL it framed the front page instead — the page's centrepiece
	 * showing the wrong document entirely, and a 200 the whole way, which is why
	 * nothing caught it.
	 *
	 * THE PERMALINK WAS THE SECOND ANSWER AND IT WAS ALSO WRONG, for a subtler
	 * reason. It required this to be a WP-rendered PAGE. Ian ruled on 2026-07-30 that
	 * the platform renders STANDALONE — "nothing renders from the theme" — and the
	 * moment this page moves, get_permalink() returns nothing, this method returns ''
	 * and the section is DROPPED. Not the wrong document: no document.
	 *
	 * So the URL is now admin-ajax, which WordPress routes unconditionally and which
	 * no page-routing decision can take away. Same origin, no rewrite, no new
	 * symlink, and immune to the strangler. The preview stops caring where the page
	 * lives — the property both previous answers were missing.
	 *
	 * Still returns '' when there is no sent issue: no section beats an empty frame.
	 */
	private static function preview_url(): string {
		$cached = get_transient( self::PREVIEW_CACHE );
		if ( is_string( $cached ) && $cached === '' ) {
			return '';                      // known-empty: no sent issue. Hide the section.
		}
		if ( ! is_string( $cached ) && ! self::latest_sent_issue_id() ) {
			return '';
		}

		return add_query_arg( 'action', self::PREVIEW_ACTION, admin_url( 'admin-ajax.php' ) );
	}

	// ── The page ──────────────────────────────────────────────────────────────

	/**
	 * __DIR__, not LG_WD_PLUGIN_DIR, and the difference is testable.
	 *
	 * LG_WD_PLUGIN_DIR is fixed at boot to whichever copy of the plugin WordPress
	 * loaded — on dev2 that is the SERVING CHECKOUT. A verifier that require_once's
	 * this class out of a lane's worktree would then render the serving checkout's
	 * template, i.e. prove the wrong file. __DIR__ makes the template travel with
	 * the class it belongs to, so both the served copy and a worktree copy load
	 * their own. (This is the safe use of __DIR__ — a SIBLING file that moves with
	 * this one. The trap is using it to reach wp-load.php, which does not.)
	 */
	public static function render(): string {
		ob_start();
		include dirname( __DIR__ ) . '/templates/signup-page.php';
		return (string) ob_get_clean();
	}

	/** Exposed for the template. */
	public static function ajax_url(): string {
		return admin_url( 'admin-ajax.php' );
	}

	/** Exposed for the template — '' hides the sample-email section entirely. */
	public static function sample_email_url(): string {
		return self::preview_url();
	}

	/**
	 * Where a member who already has an account manages the weekly email.
	 *
	 * The members' Weekly Digest toggle is on the Manage Account page (the
	 * lg_weekly_digest_toggle in lg-event-reminders.php renders there). Resolved
	 * from the live page rather than hardcoded, because a slug that 404s is worse
	 * than no link at all for the 233 people this is aimed at.
	 */
	public static function prefs_url(): string {
		foreach ( [ 'manage-account', 'my-account', 'manage-subscription' ] as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page ) {
				return get_permalink( $page );
			}
		}
		return '';
	}
}
