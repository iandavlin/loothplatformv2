<?php
/**
 * lg-weekly-email-bridge  (events/weekly lane — standalone bridge glue)
 *
 * Loopback target that renders the EMAIL HTML for a published weekly_email
 * issue ON THE FLY from its curated section data, using the SAME builder the
 * sent email uses (LG_WD_Sender::send_issue dry-run → LG_WD_Email_Builder).
 *
 * Why: the standalone /weekly/<slug>/ page shows the SENT FluentCRM campaign
 * body for archived issues, but the CURRENT (unsent) lead issue has no sent
 * body and was falling back to the plain web-card layout. This endpoint lets
 * the standalone page preview the lead as the real email instead.
 *
 * Surface: GET /wp-json/looth/v1/weekly-email-html?slug=<issue-slug>
 *   → { "html": "<full email document>", "subject": "..." }
 *
 * Gating: the looth/v1 namespace is NOT in nginx's gate-exempt list, so on
 * dev/dev2 an anon WITHOUT the cookie gate gets 403 at the edge; the standalone
 * page loops back forwarding the viewer cookie (same as the /whoami loopback).
 * The digest body is PUBLIC to read (VIS-1, Ian 6/12); forum-author byline
 * masking for anon is applied by the caller (events/lib/weekly-query.php),
 * matching the sent-campaign path — this endpoint returns canonical HTML.
 *
 * Owned by the events/weekly lane (deploy glue), NOT the lg-weekly-digest
 * plugin lane: it only CONSUMES that plugin's already-loaded classes, so the
 * plugin repo stays untouched.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	register_rest_route( 'looth/v1', '/weekly-email-html', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => [
			'slug' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_title',
			],
		],
		'callback'            => 'lg_weekly_email_bridge_render',
	] );
} );

function lg_weekly_email_bridge_render( WP_REST_Request $req ) {
	// The builder + sender live in the lg-weekly-digest plugin. If it's
	// inactive there is nothing to render — fail soft so the standalone page
	// falls back to its web-card path.
	if ( ! class_exists( 'LG_WD_Sender' ) || ! class_exists( 'LG_WD_Issue' ) ) {
		return new WP_Error( 'lg_wd_unavailable', 'Weekly-digest plugin not loaded.', [ 'status' => 503 ] );
	}

	$slug = sanitize_title( (string) $req->get_param( 'slug' ) );
	if ( $slug === '' ) {
		return new WP_Error( 'lg_wd_no_slug', 'Missing slug.', [ 'status' => 400 ] );
	}

	$ids = get_posts( [
		'post_type'        => LG_WD_Issue::POST_TYPE,
		'post_status'      => 'publish',
		'name'             => $slug,
		'posts_per_page'   => 1,
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'suppress_filters' => false,
	] );
	$issue_id = (int) ( $ids[0] ?? 0 );
	if ( ! $issue_id ) {
		return new WP_Error( 'lg_wd_not_found', 'Issue not found.', [ 'status' => 404 ] );
	}

	// dry_run = build the email HTML only, no send. Same path the sent email
	// takes, so the preview == the real email.
	$result = LG_WD_Sender::send_issue( $issue_id, true );
	if ( empty( $result['success'] ) || empty( $result['html'] ) ) {
		return new WP_Error(
			'lg_wd_build_failed',
			(string) ( $result['message'] ?? 'Build failed.' ),
			[ 'status' => 422 ]
		);
	}

	return rest_ensure_response( [
		'html'    => (string) $result['html'],
		'subject' => (string) ( $result['subject'] ?? '' ),
	] );
}
