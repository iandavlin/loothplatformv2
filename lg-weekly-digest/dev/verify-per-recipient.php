<?php
/**
 * verify-per-recipient.php — ONE campaign body, DIFFERENT emails per recipient,
 * driven through the REAL FluentCRM smart-code path.
 *
 *   bash lg-weekly-digest/dev/run-suite.sh      (or wp --path=/var/www/dev eval-file)
 *
 * This is the only test that exercises `render_for_subscriber()` — the callback
 * FluentCRM actually invokes per recipient. Everything else in the suite calls
 * `build_rows()` / `render()` / the recipient filter directly. The path under test is
 * the real one: `register_smartcode()` → `Parser::parse($body, $subscriber)` →
 * `fluent_crm/smartcode_group_callback_lg_recap` → `render_for_subscriber()` →
 * `wp_user_id_for()` → `payload_for()` → `LG_WD_Recap::render()`.
 *
 * ── REWRITTEN 2026-07-29, FOR THREE REASONS, AND THE FIRST IS THE WORST ──────
 *
 * 1. **IT COULD NOT RUN AT ALL.** It read a fixture from `/tmp/lg-wdr/`, which was
 *    wiped when the box lost its tmux session. A test whose fixture lives in /tmp is
 *    a test that quietly stops existing. It was also NOT in run-suite.sh, so nothing
 *    reported its absence — precisely the dead-gate shape keeper adopted a rule about
 *    the same day. It is in the suite now, and it builds its own inputs.
 * 2. Its cases were pre-ruling — "2 reactions + a connection" — and reactions and
 *    connection acceptances were removed from the digest on 2026-07-28.
 * 3. It had no coverage of the counted register, which did not exist when it was
 *    written and is now the majority of the recipient list.
 *
 * ── THE PRIVACY CHECK IS NOW A POSITIVE CONTROL, WHICH IS STRONGER ───────────
 *
 * It used to grep the output for the verbatim text of real replies read out of
 * `forums.reply`, and hope that if the ruling were broken those particular strings
 * would show up. That only tests the members whose data happened to be in the
 * fixture. Now the payloads are built here with content fields DELIBERATELY PRESENT —
 * `body`, `content`, `content_text`, `excerpt` — carrying unmistakable sentinels. If
 * the renderer ever widens to echo a field it should not, the sentinel appears and
 * this fails, for every shape, not just for whoever was in a fixture.
 *
 * No mail, no campaign, no network. Real subscribers on THIS box are used because
 * Parser::parse needs a real Subscriber model; their stored data is not read.
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run me with wp eval-file\n" ); exit( 1 ); }

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
if ( ! defined( 'LG_WD_RECAP_SMARTCODE' ) ) { define( 'LG_WD_RECAP_SMARTCODE', '##lg_recap.section##' ); }
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php';
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap-source.php';

if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
	fwrite( STDERR, "FluentCRM not loaded\n" ); exit( 1 );
}

$fail = 0;

/** Content that must NEVER reach a rendered email. Present in every payload below. */
const LEAK = [
	'body'         => 'LEAKCANARY-BODY-must-never-render',
	'content'      => 'LEAKCANARY-CONTENT-must-never-render',
	'content_text' => 'LEAKCANARY-TEXT-must-never-render',
	'excerpt'      => 'LEAKCANARY-EXCERPT-must-never-render',
];

/** A named row, with content fields deliberately attached. */
function note( string $type, array $extra = [] ): array {
	return array_merge( [
		'type'        => $type,
		'target_kind' => 'topic',
		'target_id'   => 71865,
		'anchor_id'   => null,
		'target_url'  => '/hub/?topic=suggest-an-alternative-to-concave-fret-file',
		'actor_count' => 1,
		'actor_name'  => 'Doug Proper',
		'actor_slug'  => 'the-guitar-specialist',
		'created_at'  => '2026-07-28 09:00:00',
		'title'       => 'Suggest an alternative to concave fret file',
	], LEAK, $extra );
}

/** The shapes that actually occur under the 2026-07-28 rulings. */
$SHAPES = [
	'named only — a mention'          => [ 'notifications' => [ note( 'forum.mention' ) ], 'dms' => [], 'stale' => [] ],
	'named only — a request'          => [ 'notifications' => [ note( 'connection_request' ) ], 'dms' => [], 'stale' => [] ],
	'COUNTED only (181 of 280 live)'  => [ 'notifications' => [], 'dms' => [], 'stale' => [ 'connection_request' => 3 ] ],
	'BOTH registers'                  => [ 'notifications' => [ note( 'connection_request' ) ], 'dms' => [],
	                                       'stale' => [ 'connection_request' => 2 ] ],
	'DMs only'                        => [ 'notifications' => [], 'stale' => [],
	                                       'dms' => [ array_merge( [ 'thread_uuid' => 'u-1', 'unread' => 6,
	                                            'senders' => [ 'Sharon Fisher' ], 'sender_slugs' => [ 'sharon' ],
	                                            'last_message_at' => '2026-07-28 09:00:00' ], LEAK ) ] ],
	'NOTHING — must be absent'        => [ 'notifications' => [], 'dms' => [], 'stale' => [] ],
];

/** Real subscribers, one per shape — Parser::parse needs a real Subscriber model. */
$subs = \FluentCrm\App\Models\Subscriber::whereNotNull( 'user_id' )->where( 'user_id', '>', 0 )
	->orderBy( 'id' )->limit( count( $SHAPES ) )->get();
if ( count( $subs ) < count( $SHAPES ) ) {
	fwrite( STDERR, "need " . count( $SHAPES ) . " WP-linked subscribers on this box\n" ); exit( 1 );
}

$byWp   = [];
$labels = array_keys( $SHAPES );
foreach ( $subs as $i => $s ) { $byWp[ (int) $s->user_id ] = $SHAPES[ $labels[ $i ] ]; }

add_filter( 'lg_wd_recap_fetch', function ( $pre, $want ) use ( $byWp ) {
	$out = [];
	foreach ( $want as $id ) {
		$p = $byWp[ $id ] ?? null;
		// Mirror the source's own normalisation: all three empty => [].
		$out[ $id ] = ( $p && ( $p['notifications'] || $p['dms'] || array_filter( $p['stale'] ) ) )
			? array_merge( [ 'display_name' => 'Test Member' ], $p ) : [];
	}
	return $out;
}, 10, 3 );

LG_WD_Recap_Source::register_smartcode();

$body    = '<p>INTRO</p>' . LG_WD_RECAP_SMARTCODE . '<p>CURATED CONTENT</p>';
$NORECAP = '<p>INTRO</p><p>CURATED CONTENT</p>';
$renders = [];

foreach ( $subs as $i => $sub ) {
	$label = $labels[ $i ];
	$wp    = (int) $sub->user_id;

	// The real parse path — exactly what CampaignEmail::getEmailBody() applies to
	// every recipient's row at send (fluent_crm/parse_campaign_email_text).
	$out = \FluentCrm\App\Services\Libs\Parser\Parser::parse( $body, $sub );
	$renders[ $wp ] = $out;

	$has_section = strpos( $out, 'Your week' ) !== false;
	$has_token   = strpos( $out, LG_WD_RECAP_SMARTCODE ) !== false;
	$rows        = substr_count( $out, 'border-radius:50%' );
	$expect_none = ( $label === 'NOTHING — must be absent' );

	printf( "  %-32s wp:%-5d section=%-3s rows=%d\n", $label, $wp, $has_section ? 'YES' : 'no', $rows );

	if ( $has_token ) { echo "     FAIL: the smart code did not substitute\n"; $fail++; }

	if ( $expect_none ) {
		// REPOINTED, NOT DELETED (2026-07-28). This used to be the SHIPPING empty
		// case. Ian then ruled such a member gets NO EMAIL AT ALL, so the recipient
		// filter drops them before a CampaignEmail row exists — see
		// verify-empty-means-no-send.php, which is the test of record for the ruling.
		// It stays here as the belt-and-braces: if a member ever reaches the render
		// with an empty payload (a filter bug, a fail-open, a manual resend), the
		// renderer must still emit nothing rather than an empty heading.
		if ( $has_section ) { echo "     FAIL: empty member got a section\n"; $fail++; }
		if ( $out !== $NORECAP ) { echo "     FAIL: not byte-identical to the no-recap body\n"; $fail++; }
	} elseif ( ! $has_section ) {
		echo "     FAIL: member with something waiting got no section\n"; $fail++;
	}
}

echo "\n--- one body in, DIFFERENT bodies out (the per-user seam) ---\n";
$distinct = count( array_unique( $renders ) );
printf( "  distinct renders from ONE campaign body: %d of %d  %s\n",
	$distinct, count( $renders ), $distinct === count( $renders ) ? 'OK' : 'FAIL' );
if ( $distinct !== count( $renders ) ) { echo "  FAIL: two members received identical bodies\n"; $fail++; }

echo "\n--- the privacy ruling, as a POSITIVE control ---\n";
echo "  Every payload above carries body/content/content_text/excerpt sentinels.\n";
echo "  Counts, actors and links only — never content.\n";
$leaks = 0;
foreach ( $renders as $wp => $out ) {
	foreach ( LEAK as $field => $canary ) {
		if ( strpos( $out, $canary ) !== false ) {
			printf( "  FAIL: wp:%d leaked the '%s' field into the email\n", $wp, $field );
			$fail++; $leaks++;
		}
	}
}
printf( "  %d sentinel fields x %d recipients checked, %d leaked  %s\n",
	count( LEAK ), count( $renders ), $leaks, $leaks === 0 ? 'OK' : 'FAIL' );

echo "\n--- the control that keeps the check above honest ---\n";
// If the sentinels could never appear, the loop above would pass vacuously. Prove
// the detector works by looking for a string that IS in every render.
$control = 'CURATED CONTENT';
$seen    = 0;
foreach ( $renders as $out ) { if ( strpos( $out, $control ) !== false ) { $seen++; } }
printf( "  a string that IS present ('%s') found in %d of %d renders  %s\n",
	$control, $seen, count( $renders ), $seen === count( $renders ) ? 'OK' : 'FAIL' );
if ( $seen !== count( $renders ) ) { echo "  FAIL: the leak detector cannot see strings at all\n"; $fail++; }

remove_all_filters( 'lg_wd_recap_fetch' );
echo $fail ? "\n$fail FAILED\n" : "\nPER-RECIPIENT SEAM HOLDS\n";
exit( $fail ? 1 : 0 );
