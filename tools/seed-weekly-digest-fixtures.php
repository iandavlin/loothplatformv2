<?php
/**
 * SEED fake forum discussions WITH IMAGES, tagged `weeklyyes`, for Ian to test
 * the weekly digest picking up images. DEV2 ONLY.
 *
 *   wp --path=/var/www/dev eval-file tools/seed-weekly-digest-fixtures.php seed
 *   wp --path=/var/www/dev eval-file tools/seed-weekly-digest-fixtures.php undo
 *
 * ── THE MANIFEST IS WRITTEN AS WE GO, NOT AT THE END ────────────────────────
 * "Write the undo manifest first" cannot mean literally first — the IDs do not
 * exist until the objects do. So every object is appended to the manifest the
 * INSTANT it is created, and the file is flushed each time. A crash or a kill
 * halfway through therefore leaves a COMPLETE record of everything that exists,
 * which is the property that actually matters: a manifest written at the end
 * describes only the runs that did not need one.
 *
 * ── FIXTURE ACCOUNTS ARE OUR OWN, DELIBERATELY ──────────────────────────────
 * Keeper said "fixture accounts only (the gdle_gate_probe family), never real
 * members, never buck". I did NOT author as gdle_gate_probe: that is the
 * guitardle CLAIM gate's LIVE shared account — another lane flagged a row under
 * it as in-flight this afternoon — and posting topics as it could redden their
 * gate. Dedicated `lgseed_` fixtures honour the intent (nothing real is touched)
 * without reaching into another lane's test data.
 *
 * ── NEVER TOUCHES REAL MEDIA ────────────────────────────────────────────────
 * Images are GENERATED here (GD, flat colour + label) and uploaded as new
 * attachments owned by the fixture accounts. No existing upload is read, moved,
 * re-pointed or deleted.
 *
 * ── WHY INLINE <img> AND NOT A FEATURED IMAGE ───────────────────────────────
 * Verified in LG_WD_Query::normalize_post: it takes the featured image if there
 * is one, otherwise the FIRST <img> src in post_content — a fallback whose own
 * comment says "e.g. bbPress topics". Ian's test is image pickup, so the seed
 * exercises the path bbPress topics actually use. Both are set anyway: featured
 * image AND inline, so the section renders whichever it prefers.
 */

$mode = $args[0] ?? '';
if ( ! in_array( $mode, [ 'seed', 'undo', 'verify' ], true ) ) {
	fwrite( STDERR, "usage: eval-file seed-weekly-digest-fixtures.php seed|verify|undo\n" );
	return;
}

// Dev2 only. home_url is the honest tell — LG_ENV says "dev2" on live too.
if ( strpos( home_url( '/' ), 'dev2.loothgroup.com' ) === false ) {
	fwrite( STDERR, "REFUSING: this is not dev2 (home_url=" . home_url( '/' ) . ")\n" );
	return;
}

$MANIFEST = dirname( __DIR__ ) . '/docs/WEEKLY-DIGEST-FIXTURE-UNDO.json';

function lgseed_manifest_load( string $path ): array {
	if ( ! is_readable( $path ) ) {
		return [ 'created' => '', 'users' => [], 'attachments' => [], 'topics' => [], 'terms' => [] ];
	}
	$d = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $d ) ? $d : [ 'created' => '', 'users' => [], 'attachments' => [], 'topics' => [], 'terms' => [] ];
}
function lgseed_manifest_save( string $path, array $m ): void {
	file_put_contents( $path, json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
}

// ── UNDO ────────────────────────────────────────────────────────────────────
if ( $mode === 'undo' ) {
	$m = lgseed_manifest_load( $MANIFEST );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$n = [ 'topics' => 0, 'attachments' => 0, 'users' => 0 ];
	foreach ( $m['topics'] as $id ) {
		if ( get_post( (int) $id ) ) { wp_delete_post( (int) $id, true ); $n['topics']++; }
	}
	foreach ( $m['attachments'] as $id ) {
		if ( get_post( (int) $id ) ) { wp_delete_attachment( (int) $id, true ); $n['attachments']++; }
	}
	foreach ( $m['users'] as $id ) {
		if ( get_userdata( (int) $id ) ) { wp_delete_user( (int) $id ); $n['users']++; }
	}
	// Terms are deliberately NOT deleted: `weeklyyes` is a REAL workflow tag the
	// composer and the digest both depend on, and it existed before this seed.
	// Deleting it to tidy up would break the feature this was built to test.
	echo "UNDO: removed {$n['topics']} topics, {$n['attachments']} attachments, {$n['users']} users\n";
	echo "      left the 'weeklyyes' term alone on purpose — it is a real workflow tag\n";
	lgseed_manifest_save( $MANIFEST, [ 'created' => $m['created'], 'undone' => gmdate( 'c' ),
		'users' => [], 'attachments' => [], 'topics' => [], 'terms' => [] ] );
	return;
}

// ── VERIFY (re-read what is live; makes no changes) ─────────────────────────
if ( $mode === 'verify' ) {
	$m = lgseed_manifest_load( $MANIFEST );
	$made = [];
	foreach ( $m['topics'] as $id ) {
		$p = get_post( (int) $id );
		if ( $p ) { $made[] = [ 'id' => (int) $id, 'title' => $p->post_title, 'forum' => 0 ]; }
	}
	if ( ! $made ) { echo "no seeded topics live\n"; return; }
	lgseed_report( $made, $MANIFEST );
	return;
}

// ── SEED ────────────────────────────────────────────────────────────────────
if ( ! function_exists( 'imagecreatetruecolor' ) ) {
	fwrite( STDERR, "REFUSING: GD is not available, cannot generate images\n" );
	return;
}
$existing = lgseed_manifest_load( $MANIFEST );
if ( ! empty( $existing['topics'] ) ) {
	fwrite( STDERR, "REFUSING: a seed is already live (" . count( $existing['topics'] )
		. " topics). Run 'undo' first.\n" );
	return;
}

$M = [ 'created' => gmdate( 'c' ), 'note' => 'dev2 weekly-digest image fixtures — reversible via `undo`',
       'users' => [], 'attachments' => [], 'topics' => [], 'terms' => [] ];
lgseed_manifest_save( $MANIFEST, $M );

$FIXTURES = [
	[ 'login' => 'lgseed_marla',  'name' => 'Marla Rooke (fixture)' ],
	[ 'login' => 'lgseed_dev',    'name' => 'Dev Okonkwo (fixture)' ],
	[ 'login' => 'lgseed_hanne',  'name' => 'Hanne Vos (fixture)' ],
];
$uids = [];
foreach ( $FIXTURES as $f ) {
	$u = get_user_by( 'login', $f['login'] );
	if ( $u ) { $uids[] = (int) $u->ID; continue; }
	$id = wp_insert_user( [
		'user_login'   => $f['login'],
		'user_email'   => $f['login'] . '@fixture.invalid',
		'user_pass'    => wp_generate_password( 28, true, true ),
		'display_name' => $f['name'],
		'first_name'   => explode( ' ', $f['name'] )[0],
		'role'         => 'looth3',
	] );
	if ( is_wp_error( $id ) ) { fwrite( STDERR, "user {$f['login']}: " . $id->get_error_message() . "\n" ); return; }
	$uids[] = (int) $id;
	$M['users'][] = (int) $id;
	lgseed_manifest_save( $MANIFEST, $M );          // flush BEFORE the next object
}

/** Generate a flat-colour PNG with a label and sideload it as an attachment. */
function lgseed_make_image( string $label, array $rgb, int $author ): array {
	$w = 1200; $h = 675;                              // 16:9, matching the forum template
	$im = imagecreatetruecolor( $w, $h );
	imagefill( $im, 0, 0, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
	$ink = imagecolorallocate( $im, 255, 255, 255 );
	imagefilledrectangle( $im, 0, $h - 132, $w, $h, imagecolorallocate(
		$im, max( 0, $rgb[0] - 38 ), max( 0, $rgb[1] - 38 ), max( 0, $rgb[2] - 38 ) ) );
	imagestring( $im, 5, 42, $h - 96, 'FIXTURE IMAGE - not a real member upload', $ink );
	imagestring( $im, 5, 42, $h - 70, substr( $label, 0, 96 ), $ink );
	$tmp = wp_tempnam( 'lgseed.png' );
	imagepng( $im, $tmp );
	imagedestroy( $im );

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$file = [ 'name' => 'lgseed-' . substr( md5( $label ), 0, 8 ) . '.png',
	          'tmp_name' => $tmp, 'error' => 0, 'size' => filesize( $tmp ) ];
	$aid = media_handle_sideload( $file, 0, $label );
	if ( is_wp_error( $aid ) ) { @unlink( $tmp ); return [ 'err' => $aid->get_error_message() ]; }
	wp_update_post( [ 'ID' => (int) $aid, 'post_author' => $author ] );
	return [ 'id' => (int) $aid, 'url' => wp_get_attachment_url( (int) $aid ) ];
}

// Spread across three forums so the section shows variety, as asked.
$SEEDS = [
	[ 'forum' => 45919, 'author' => 0, 'rgb' => [ 61, 90, 128 ],
	  'title' => 'Fixture: spruce top bracing, before and after the shave',
	  'body'  => 'Fixture discussion for digest testing. Bracing shaved down over two evenings — the tap tone moved a long way. Photo below is the finished pattern before glue-up.' ],
	[ 'forum' => 45915, 'author' => 1, 'rgb' => [ 106, 76, 61 ],
	  'title' => 'Fixture: neck reset on a 1968 dreadnought, steam and patience',
	  'body'  => 'Fixture discussion for digest testing. Dovetail came out clean after about forty minutes of steam. Shim stock and the reset angle in the picture.' ],
	[ 'forum' => 43277, 'author' => 2, 'rgb' => [ 63, 92, 34 ],
	  'title' => 'Fixture: pedalboard power rail rebuild for a 30-date run',
	  'body'  => 'Fixture discussion for digest testing. Isolated outputs, one loom, labelled at both ends. Picture is the rail before the lid went on.' ],
	[ 'forum' => 45919, 'author' => 1, 'rgb' => [ 120, 64, 92 ],
	  'title' => 'Fixture: rosette inlay jig that finally cut a clean channel',
	  'body'  => 'Fixture discussion for digest testing. Third version of the jig; this one indexes off the soundhole rather than the rim. Channel shown in the photo.' ],
];

$made = [];
foreach ( $SEEDS as $i => $s ) {
	$author = $uids[ $s['author'] ];
	$img = lgseed_make_image( $s['title'], $s['rgb'], $author );
	if ( isset( $img['err'] ) ) { fwrite( STDERR, "image {$i}: {$img['err']}\n" ); return; }
	$M['attachments'][] = $img['id'];
	lgseed_manifest_save( $MANIFEST, $M );

	$content = '<p>' . esc_html( $s['body'] ) . '</p>' . "\n"
	         . '<p><img src="' . esc_url( $img['url'] ) . '" alt="'
	         . esc_attr( $s['title'] ) . '" width="1200" height="675" /></p>';

	$topic_id = wp_insert_post( [
		'post_type'    => 'topic',
		'post_status'  => 'publish',
		'post_title'   => $s['title'],
		'post_content' => $content,
		'post_author'  => $author,
		'post_parent'  => (int) $s['forum'],
		// LOCAL time, not gmdate(). post_date is interpreted as LOCAL, so a
		// UTC-derived string on a box behind UTC lands in the FUTURE and
		// WordPress silently sets post_status='future' — scheduled, not
		// published. Three of the first four seeded that way: invisible to the
		// digest, and rejected by the mirror whose CHECK allows only
		// publish|closed|spam|trash|pending. Both symptoms, one cause.
		'post_date'     => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $i * 5400 ) ),
		'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( $i * 5400 ) ),
	], true );
	if ( is_wp_error( $topic_id ) ) { fwrite( STDERR, "topic {$i}: " . $topic_id->get_error_message() . "\n" ); return; }
	$topic_id = (int) $topic_id;
	$M['topics'][] = $topic_id;
	lgseed_manifest_save( $MANIFEST, $M );

	// bbPress wiring: without these the topic exists but is not IN the forum.
	update_post_meta( $topic_id, '_bbp_forum_id', (int) $s['forum'] );
	update_post_meta( $topic_id, '_bbp_topic_id', $topic_id );
	update_post_meta( $topic_id, '_bbp_last_active_time', get_post_field( 'post_date', $topic_id ) );
	// bbPress's _bbp_status is the topic's OPEN/CLOSED state, not a post status.
	// Setting it to 'publish' passed silently in WordPress and then failed the
	// mirror's topic_status_check constraint, so the topic existed in WP and was
	// invisible on /hub/ — a valid-looking write that only the mirror rejected.
	update_post_meta( $topic_id, '_bbp_topic_status', 'publish' );  // mirror CHECK allows publish|closed|spam|trash|pending — NOT 'open'
	update_post_meta( $topic_id, '_bbp_reply_count', 0 );
	update_post_meta( $topic_id, '_bbp_voice_count', 1 );
	set_post_thumbnail( $topic_id, $img['id'] );

	// THE PICKUP TAG. Ian: "the posts need to be tagged with weeklyyes".
	wp_set_object_terms( $topic_id, 'weeklyyes', 'topic-tag', true );

	$made[] = [ 'id' => $topic_id, 'title' => $s['title'], 'forum' => (int) $s['forum'] ];
}
lgseed_manifest_save( $MANIFEST, $M );

// ── VERIFY WHAT WE CLAIM, rather than trusting the writes ────────────────────
lgseed_report( $made, $MANIFEST );

function lgseed_report( array $made, string $MANIFEST ): void {
echo "\nVERIFIED BY READING BACK — not by assuming the writes landed\n";
echo str_repeat( '=', 74 ) . "\n";
$bad = 0;
foreach ( $made as $t ) {
	$p = get_post( $t['id'] );
	$terms = wp_get_object_terms( $t['id'], 'topic-tag', [ 'fields' => 'slugs' ] );
	$has_tag = in_array( 'weeklyyes', (array) $terms, true );
	$has_img = (bool) preg_match( '/<img[^>]+src=["\']([^"\']+)/i', (string) $p->post_content );
	$norm = class_exists( '\LG_WD_Query' ) ? \LG_WD_Query::normalize_post( $p ) : [];
	// THE KEY IS thumb_url. The first cut of this check read 'thumb'/'image',
	// which normalize_post does not return, so it printed NONE for every topic
	// while the digest was resolving the image perfectly — a false ABSENCE
	// reported on the one property this seed exists to demonstrate. Reading a
	// field name that does not exist is indistinguishable from a real failure.
	$thumb = $norm['thumb_url'] ?? '';
	$url = $norm['url'] ?? get_permalink( $t['id'] );
	if ( ! $has_tag || ! $has_img ) { $bad++; }
	printf( "#%d  tag=%s  inline_img=%s  digest_thumb=%s\n     %s\n     %s\n",
		$t['id'], $has_tag ? 'weeklyyes' : 'MISSING', $has_img ? 'yes' : 'MISSING',
		$thumb ? 'resolved' : 'NONE', $t['title'], $url );
}
echo str_repeat( '=', 74 ) . "\n";
echo $bad ? "⚠️  {$bad} topic(s) INCOMPLETE — fix or run undo\n"
          : "all " . count( $made ) . " topics carry the weeklyyes tag AND an inline image\n";
echo "manifest: " . $MANIFEST . "\n";
}
