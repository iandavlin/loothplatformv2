<?php
/**
 * render-ruled-frames.php — the design AS RULED, rendered from live rows.
 *
 *   LG_RECAP_CLASS=<path to class-lg-wd-recap.php> LG_SIDE=<before|after> \
 *     wp eval-file <this file>
 *
 * RUN TWICE, IN TWO PROCESSES, ON PURPOSE. The "before" panels need the renderer as
 * it stood on 2026-07-27 — with reactions and connection acceptances still in — and
 * that class was deleted, not disabled. Both versions declare LG_WD_Recap, so they
 * cannot co-exist in one process. The before side is loaded from git history:
 *
 *   git show f48561f:lg-weekly-digest/includes/class-lg-wd-recap.php > /tmp/...
 *
 * That is also the honest way to draw a "before": the actual bytes that shipped,
 * not a reconstruction of them from memory.
 *
 * Data is a read-only LIVE extract (extract-ruled-frames.sh) with every rule already
 * applied in SQL. Counts, actors and links only — never content.
 *
 * Writes to /tmp; wp-cli runs as looth-dev and cannot write a ubuntu-owned worktree.
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "wp eval-file\n" ); exit( 1 ); }

$LANE  = '/home/ubuntu/worktrees/weekly-digest-recap';
$CLASS = getenv( 'LG_RECAP_CLASS' ) ?: $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php';
$SIDE  = getenv( 'LG_SIDE' ) ?: 'after';
$SRC   = '/tmp/lg-wdr/out/ruled-frames.json';
$OUT   = '/tmp/lg-wdr/out/frames';

if ( ! defined( 'LG_WD_RECAP_SMARTCODE' ) ) { define( 'LG_WD_RECAP_SMARTCODE', '##lg_recap.section##' ); }
require_once $CLASS;
if ( ! is_dir( $OUT ) ) { mkdir( $OUT, 0777, true ); }

$src = json_decode( (string) file_get_contents( $SRC ), true );
if ( ! $src ) { fwrite( STDERR, "no extract at $SRC\n" ); exit( 1 ); }

$base = LG_WD_Email_Builder::build( LG_WD_Query::build_payload_from_issue( LG_WD_Issue::get_data( 72147 ) ) );

function inject( string $html, string $section ): string {
	if ( $section === '' ) { return $html; }
	$pos = strpos( $html, 'border-bottom:2px solid #ECB351' );
	$end = $pos !== false ? strpos( $html, '</p>', $pos ) : false;
	if ( $end === false ) { return $html; }
	$end += 4;
	return substr( $html, 0, $end ) . "\n" . $section . substr( $html, $end );
}

/** LIVE post ids do not resolve on dev2 — blank beats a wrong title. */
function titles( array $rows ): array {
	foreach ( $rows as &$n ) {
		$t = (int) ( $n['target_id'] ?? 0 );
		$n['title'] = ( $t && get_post_status( $t ) ) ? (string) get_the_title( $t ) : '';
	}
	return $rows;
}

function put( string $p, string $b ): void {
	if ( file_put_contents( $p, $b ) !== strlen( $b ) ) { fwrite( STDERR, "FAILED $p\n" ); exit( 1 ); }
}

$meta = [];
foreach ( $src as $m ) {
	$wp = (int) $m['wp_user_id'];

	if ( $SIDE === 'before' ) {
		// 07-27: every type, is_read only, no counted register.
		$payload = [
			'display_name'  => $m['display_name'],
			'notifications' => titles( $m['before_ruling'] ),
			'dms'           => [],
		];
	} else {
		// AS RULED: to-do types only, fresh NAMED + stale COUNTED.
		$payload = [
			'display_name'  => $m['display_name'],
			'notifications' => titles( $m['named'] ),
			'dms'           => [],
			'stale'         => $m['stale'],
		];
	}

	$section = LG_WD_Recap::render( $payload );
	put( "$OUT/ruled-$wp-$SIDE.html", inject( $base, $section ) );

	$meta[] = [
		'wp'    => $wp,
		'name'  => $m['display_name'],
		'side'  => $SIDE,
		'rows'  => substr_count( $section, 'border-radius:50%' ),
		'empty' => $section === '',
	];
	printf( "wp:%-5d %-36s %-6s rows=%-3d %s\n", $wp, substr( $m['display_name'], 0, 34 ),
		$SIDE, substr_count( $section, 'border-radius:50%' ),
		$section === '' ? 'NO SECTION' : '' );
}

$f = "$OUT/ruled-$SIDE.json";
put( $f, (string) wp_json_encode( $meta, JSON_PRETTY_PRINT ) );
echo "wrote " . count( $meta ) . " frames ($SIDE) to $OUT\n";
