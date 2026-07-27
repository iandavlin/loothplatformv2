<?php
/**
 * render-recap-frames.php — DESIGN FRAMES for the weekly-digest personal recap.
 *
 * Run under wp-cli on dev2 so the frames are the REAL email, not a drawing of one:
 *
 *   cd /var/www/dev && sudo -u looth-dev wp eval-file <this file>
 *
 * It renders a real digest issue through the real LG_WD_Email_Builder (i.e. main's
 * chrome, untouched by this lane) and injects the real LG_WD_Recap section, fed
 * with REAL rows exported from profile_app.notifications / message_recipients.
 *
 * WHY A FIXTURE AND NOT A LIVE QUERY. WordPress runs as `looth-dev`, which has zero
 * grants on the `profile_app` database (checked: 0 rows in role_table_grants) — the
 * exact reason notify-bridge.php POSTs over loopback instead of writing PG. The
 * send-time reader is therefore a loopback endpoint (WEEKLY-DIGEST-RECAP.md §3), and
 * these frames deliberately do not wait on that plumbing: they read a JSON export of
 * the same rows so the DESIGN can be decided first (Ian, 2026-07-27).
 *
 * READ-ONLY. Renders to $OUT. Touches no plugin, no option, no campaign, no mail.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run me with wp eval-file\n" ); exit( 1 ); }

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
$OUT  = '/var/www/dev/mockups/wd-recap';
$FIX  = '/tmp/lg-wdr/fixtures.json';

require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php';

if ( ! is_dir( $OUT ) ) { mkdir( $OUT, 0755, true ); }

$fixtures = json_decode( (string) file_get_contents( $FIX ), true );
if ( ! is_array( $fixtures ) ) { fwrite( STDERR, "bad fixtures at $FIX\n" ); exit( 1 ); }

// ── The host email: a real, already-sent issue rendered through main's builder ──
$issue_id   = 72147;                                    // "Weekly Digest — July 13, 2026"
$issue_data = LG_WD_Issue::get_data( $issue_id );
$payload    = LG_WD_Query::build_payload_from_issue( $issue_data );
$base_html  = LG_WD_Email_Builder::build( $payload );

/**
 * Inject the recap into the built email.
 *
 * TOP = immediately after the intro rule, before the first curated section — the
 * position the shipping template will hold with the `##lg_recap.section##` smart
 * code. BOTTOM = just before the sign-off block. The anchors below are the two
 * literal strings templates/email.php emits, so the injection lands in exactly the
 * place the real substitution would.
 */
function frame_inject( string $html, string $section, string $where ): string {
	if ( $section === '' ) { return $html; }
	if ( $where === 'bottom' ) {
		$anchor = '<!-- SIGN-OFF -->';
		$pos    = strpos( $html, $anchor );
		if ( $pos === false ) { return $html; }
		// Close the open body cell/row, then reopen one for the recap.
		return substr( $html, 0, $pos )
			. "</td></tr>\n<tr><td class=\"email-body\" style=\"padding:0 24px 8px;\">" . $section . "</td></tr>\n<tr><td>"
			. substr( $html, $pos );
	}
	// TOP: after the gold-ruled intro paragraph.
	$anchor = '</p>';
	$pos    = strpos( $html, 'border-bottom:2px solid #ECB351' );
	if ( $pos === false ) { return $html; }
	$end = strpos( $html, $anchor, $pos );
	if ( $end === false ) { return $html; }
	$end += strlen( $anchor );
	return substr( $html, 0, $end ) . "\n" . $section . substr( $html, $end );
}

/** Resolve discussion/post titles WP-side — target_id is a WP post id. */
function hydrate_titles( array $payload ): array {
	foreach ( $payload['notifications'] as &$n ) {
		$tid = (int) ( $n['target_id'] ?? 0 );
		$n['title'] = $tid ? (string) get_the_title( $tid ) : '';
	}
	unset( $n );
	return $payload;
}

// ── The frames ────────────────────────────────────────────────────────────────
// Each is a real member's real unread material. `full-week` is a COMPOSITE and is
// labelled as one everywhere it appears: no single member on dev2 has replies AND
// a mention AND reactions AND unread DMs in one week, because the Hub notification
// bridge only shipped 2026-07-12 and this box gets a trickle of traffic. Every row
// in it is a real stored row; they are merged onto one recipient so the design can
// be judged at density.

$frames = [
	'full-week' => [
		'title' => 'A member with a full week',
		'note'  => 'COMPOSITE — every row is a real stored row, merged from several members onto one recipient so the design can be seen at density. No single dev2 member has all four kinds in one week (the Hub notification bridge shipped 2026-07-12).',
		'data'  => $fixtures['composite'],
	],
	'only-dms' => [
		'title' => 'A member with only DMs',
		'note'  => 'Real: Tony Lewis (wp 1138) — 6 unread messages in one thread, nothing else. Note the row links to the SENDER\'S PROFILE, not the thread: there is no URL that opens a DM thread today (§5).',
		'data'  => $fixtures['only_dms'],
	],
	'one-connection' => [
		'title' => 'A member with one lonely connection request',
		'note'  => 'Real: Jim Hensley (wp 1891) — a single pending connection request from Doug Proper. The thinnest section that is still worth sending.',
		'data'  => $fixtures['one_connection'],
	],
	'long-name' => [
		'title' => 'The longest real name on the platform (71 characters)',
		'note'  => 'Real: Dave Staudte (wp 32) — "Dave Staudte (rhymms with &ldquo;Howdy&rdquo;) NB Guitar Repair (New Braunfels, TX)", the longest display_name in the store at 71 chars. The greeting says "Dave": the platform greets on the FIRST TOKEN only, because the legacy name system backfilled business names into display_name. The ROWS carry the long ones — including "Dan Wolf &amp; Steve Baker…", which is stored HTML-entity-damaged and must render with a real ampersand.',
		'data'  => $fixtures['long_name'],
	],
	'empty' => [
		'title' => 'A member with nothing this week',
		'note'  => 'EMPTY MEANS ABSENT. No heading, no panel, no "you have 0 notifications". The digest is byte-identical to what it is today — scroll: the intro rule runs straight into the first curated section.',
		'data'  => [ 'notifications' => [], 'dms' => [] ],
	],
];

// ONE layout. Ian picked the recommended design from the frames on 2026-07-27 and
// asked for the alternatives to be dropped rather than left as options, so the
// renderer no longer has knobs and neither does this deck. The rejected shapes
// survive as history in docs/atlas/WEEKLY-DIGEST-RECAP.md §4.
$variants = [
	'a-recommended' => [ 'where' => 'top', 'title' => 'The shipping design' ],
];

$written = [];
foreach ( $frames as $fkey => $frame ) {
	$data = hydrate_titles( $frame['data'] );
	foreach ( $variants as $vkey => $variant ) {
		$section = LG_WD_Recap::render( $data );
		$html    = frame_inject( $base_html, $section, $variant['where'] );
		$file    = "$OUT/$fkey--$vkey.html";
		file_put_contents( $file, $html );
		$written[] = [
			'frame'   => $fkey,
			'variant' => $vkey,
			'file'    => basename( $file ),
			'empty'   => $section === '',
			'bytes'   => strlen( $section ),
		];
	}
}

file_put_contents( "$OUT/manifest.json", wp_json_encode( [
	'frames'   => array_map( fn( $f ) => [ 'title' => $f['title'], 'note' => $f['note'] ], $frames ),
	'variants' => array_map( fn( $v ) => $v['title'], $variants ),
	'written'  => $written,
	'issue'    => [ 'id' => $issue_id, 'title' => get_the_title( $issue_id ) ],
], JSON_PRETTY_PRINT ) );

foreach ( $written as $w ) {
	printf( "%-32s %-16s %s\n", $w['frame'], $w['variant'], $w['empty'] ? 'SECTION ABSENT (0 bytes)' : $w['bytes'] . ' bytes' );
}
echo "\nwrote " . count( $written ) . " frames to $OUT\n";
