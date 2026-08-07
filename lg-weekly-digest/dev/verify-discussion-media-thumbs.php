<?php
/**
 * verify-discussion-media-thumbs.php — the discussion cards that lost their images
 * get them back, and the ones that still work do not move a byte.
 *
 * Ian, 2026-08-03: "images from discussions are now sometimes not making it into
 * the weekly digest." Narrowed 08-05: "mostly ... the discussion section."
 *
 * ── WHAT THIS PROVES, AND WHY IT IS SHAPED THIS WAY ──────────────────────────
 *
 * The defect is DATA-SHAPED, so a fixture that renders is worth nothing. Every
 * assertion below runs against the REAL corpus on whichever box it is pointed at
 * (dev2: 2825 bp_media rows / 966 topics carrying bp_media_ids) and reports the
 * counts it measured, so a corpus that stops containing the failing class shows up
 * as CANNOT RUN rather than as a pass.
 *
 * Both halves of the standard are asserted, because only proving the first half is
 * how a fix ships a regression:
 *   A. the FAILING class (composer topic, media in bp_media_ids) now resolves
 *   B. the PASSING class (FluentForm-era topic with an inline <img>) resolves to
 *      the BYTE-IDENTICAL url it did before, flag ON or OFF
 *
 * ── RUN ──────────────────────────────────────────────────────────────────────
 *   bash lg-weekly-digest/dev/verify-discussion-media-thumbs.sh
 *
 * --skip-plugins=lg-weekly-digest is load-bearing: the plugin is ACTIVE on dev2 at
 * 3.0.1, and with it loaded WP declares LG_WD_Query at boot, so the branch copy
 * could not be required without either a redeclare fatal or (worse) silently
 * testing the DEPLOYED class while the report claims it tested the branch. See
 * _load-under-test.php for the same trap in its original form.
 *
 * Exit: 0 green · 1 RED · 2 CANNOT RUN (louder than RED — it proves nothing).
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "CANNOT RUN: not inside WordPress (run via the .sh wrapper).\n" );
	exit( 2 );
}

/* Overridable so the RED of every assertion below can be PROVEN against a
 * deliberately broken copy rather than assumed. See the .sh header. */
$BRANCH = getenv( 'LG_WD_UNDER_TEST' ) ?: dirname( __DIR__ ) . '/includes/class-lg-wd-query.php';
$fail   = 0;

/* ── 0. CANNOT-RUN guards ──────────────────────────────────────────────────── */

if ( class_exists( 'LG_WD_Query', false ) ) {
	fwrite( STDERR, "CANNOT RUN: LG_WD_Query already declared — the deployed copy would be\n" );
	fwrite( STDERR, "            under test instead of the branch. Pass --skip-plugins=lg-weekly-digest.\n" );
	exit( 2 );
}
if ( ! is_readable( $BRANCH ) ) {
	fwrite( STDERR, "CANNOT RUN: unreadable branch file: $BRANCH\n" );
	exit( 2 );
}
require_once $BRANCH;
if ( ! class_exists( 'LG_WD_Query', false ) ) {
	fwrite( STDERR, "CANNOT RUN: $BRANCH did not declare LG_WD_Query.\n" );
	exit( 2 );
}
echo "UNDER TEST: " . $BRANCH . "\n";
echo "            sha1 " . sha1_file( $BRANCH ) . "\n\n";

global $wpdb;

$has_table = (string) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}bp_media'" );
if ( ! $has_table ) {
	fwrite( STDERR, "CANNOT RUN: no {$wpdb->prefix}bp_media table on this box.\n" );
	exit( 2 );
}

/** Topics whose ONLY possible image source is bp_media_ids — the failing class. */
$failing = $wpdb->get_col(
	"SELECT p.ID FROM {$wpdb->posts} p
	  JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'bp_media_ids' AND pm.meta_value <> ''
	 WHERE p.post_type = 'topic' AND p.post_status = 'publish'
	   AND p.post_content NOT LIKE '%<img%'
	   AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} t WHERE t.post_id = p.ID AND t.meta_key = '_thumbnail_id')
	 ORDER BY p.post_date DESC LIMIT 40"
);

/** Topics carrying an inline <img> — the class that worked, and must keep working. */
$passing = $wpdb->get_col(
	"SELECT p.ID FROM {$wpdb->posts} p
	  WHERE p.post_type = 'topic' AND p.post_status = 'publish'
	    AND p.post_content LIKE '%<img%'
	  ORDER BY p.post_date DESC LIMIT 40"
);

if ( count( $failing ) < 3 ) {
	fwrite( STDERR, "CANNOT RUN: only " . count( $failing ) . " topics of the FAILING class in this corpus.\n" );
	exit( 2 );
}
if ( count( $passing ) < 3 ) {
	fwrite( STDERR, "CANNOT RUN: only " . count( $passing ) . " topics of the PASSING class in this corpus.\n" );
	exit( 2 );
}
printf( "corpus: %d failing-class topics, %d passing-class topics\n\n", count( $failing ), count( $passing ) );

/* The flag is a CONSTANT, so it cannot be toggled inside one process. The filter
 * exists for exactly this: it is the only way to prove OFF and ON against the same
 * bytes in the same run. */
$arm = static function ( bool $on ): void {
	remove_all_filters( 'lg_wd_topic_media_thumbs' );
	add_filter( 'lg_wd_topic_media_thumbs', static fn() => $on );
};

/* ── 1. The default is OFF ─────────────────────────────────────────────────── */

remove_all_filters( 'lg_wd_topic_media_thumbs' );
$default_on = LG_WD_Query::topic_media_enabled();
printf( "%-58s %s\n", '1. default with the constant undefined', $default_on ? 'ON' : 'OFF' );
if ( $default_on ) {
	echo "   FAIL: a member-facing surface must merge dark (keeper rule).\n";
	$fail++;
}
if ( defined( LG_WD_Query::MEDIA_FLAG ) ) {
	echo "   note: " . LG_WD_Query::MEDIA_FLAG . " IS defined on this box — the default above\n";
	echo "         was still measured through the filter, so it remains meaningful.\n";
}

/* ── 2. RED-FIRST: flag OFF must still reproduce the bug ───────────────────── */

$arm( false );
$off_resolved = 0;
foreach ( $failing as $id ) {
	if ( LG_WD_Query::topic_media_thumb( (int) $id ) !== '' ) { $off_resolved++; }
}
printf( "%-58s %d of %d\n", '2. flag OFF: failing-class topics resolving a thumb', $off_resolved, count( $failing ) );
if ( $off_resolved !== 0 ) {
	echo "   FAIL: OFF is not a no-op — it resolved images it must not.\n";
	$fail++;
} else {
	echo "   (OFF reproduces the reported bug exactly — this is the red.)\n";
}

/* ── 3. Flag ON: the failing class comes back ──────────────────────────────── */

$arm( true );
$on_resolved = 0;
$samples     = [];
foreach ( $failing as $id ) {
	$url = LG_WD_Query::topic_media_thumb( (int) $id );
	if ( $url !== '' ) {
		$on_resolved++;
		if ( count( $samples ) < 3 ) { $samples[ (int) $id ] = $url; }
	}
}
printf( "%-58s %d of %d\n", '3. flag ON: failing-class topics resolving a thumb', $on_resolved, count( $failing ) );
if ( $on_resolved === 0 ) {
	echo "   FAIL: the fix resolves nothing — the bug is not fixed.\n";
	$fail++;
}
foreach ( $samples as $id => $url ) {
	echo "     #$id -> " . preg_replace( '#^https?://[^/]+#', '', $url ) . "\n";
}

/* ── 4. THE OTHER HALF: the passing class does not move ────────────────────── */

$arm( false );
$before = [];
foreach ( $passing as $id ) {
	$p = get_post( (int) $id );
	if ( $p ) { $before[ (int) $id ] = LG_WD_Query::normalize_post( $p )['thumb_url']; }
}
$arm( true );
$moved = [];
foreach ( $before as $id => $was ) {
	$p   = get_post( (int) $id );
	$now = $p ? LG_WD_Query::normalize_post( $p )['thumb_url'] : '';
	if ( $now !== $was ) { $moved[ $id ] = [ $was, $now ]; }
}
printf( "%-58s %d of %d\n", '4. passing-class thumb_url changed by the flag', count( $moved ), count( $before ) );
if ( $moved ) {
	echo "   FAIL: the fix altered cards that already worked.\n";
	foreach ( array_slice( $moved, 0, 3, true ) as $id => $d ) {
		echo "     #$id was[$d[0]] now[$d[1]]\n";
	}
	$fail++;
} else {
	echo "   (byte-identical under OFF and ON — the fix cannot reach a card that already resolved.)\n";
}

/* Assertion 4 compares OFF against ON, so it is blind to a change that damages
 * BOTH equally — and that is the likeliest way this fix goes wrong, by moving the
 * media lookup somewhere it can clobber a thumb the earlier rules already found.
 * This is the assertion that catches it, and it must be EXACT.
 *
 * These topics were selected BECAUSE post_content carries an <img>, so the inline
 * fallback guarantees every one of them resolves. Tolerating a shortfall is how a
 * guard goes quiet: an early draft failed only at zero, and a break that emptied
 * 39 of 40 passing cards slid through on the strength of the 1 survivor. */
$non_empty = count( array_filter( $before, static fn( $u ) => $u !== '' ) );
printf( "%-58s %d of %d\n", '   ...and resolved a thumb under OFF', $non_empty, count( $before ) );
if ( $non_empty !== count( $before ) ) {
	printf( "   FAIL: %d passing-class topic(s) resolve NOTHING under flag OFF — the\n",
		count( $before ) - $non_empty );
	echo "         pre-fix behaviour is no longer intact, so assertion 4 proves nothing.\n";
	$fail++;
}

/* ── 5. Weight: the rung must COVER the slot without overshooting it ────────
 *
 * Both directions matter and they fail differently. Too narrow is a blurry photo
 * on a phone; too wide is a multi-hundred-KB original in an inbox. The named-chain
 * version of this fix passed a "not the original" check while handing every
 * PORTRAIT topic a 300px file — which is why this measures width, not identity. */

$arm( true );
$widths = [];
$bytes  = [];
$soft   = 0;
$heavy  = 0;
foreach ( $failing as $id ) {
	$raw = (string) get_post_meta( (int) $id, 'bp_media_ids', true );
	$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
	if ( ! $ids ) { continue; }
	$att = (int) $wpdb->get_var(
		"SELECT attachment_id FROM {$wpdb->prefix}bp_media
		  WHERE id IN (" . implode( ',', $ids ) . ") AND status='published' AND type='photo' AND privacy='forums'
		  ORDER BY menu_order ASC, id ASC LIMIT 1"
	);
	if ( ! $att ) { continue; }
	$meta = wp_get_attachment_metadata( $att );
	$url  = LG_WD_Query::topic_media_thumb( (int) $id );
	if ( ! is_array( $meta ) || $url === '' ) { continue; }

	/* Every width this attachment could have offered. "Too narrow" is only a
	 * defect when a COMPLIANT wider option existed — one that covers the slot
	 * without breaching the 1.7x ceiling. Measured against "anything wider at
	 * all", this flagged two images that the selector handled correctly: a
	 * 262x415 original with no larger rung, and a 782x1971 screenshot whose
	 * only wider option was a 333KB original the ceiling exists to refuse. */
	$offered = array_merge( [ (int) ( $meta['width'] ?? 0 ) ],
		array_map( static fn( $s ) => (int) ( $s['width'] ?? 0 ), $meta['sizes'] ?? [] ) );

	$w = 0; $b = 0;
	foreach ( ( $meta['sizes'] ?? [] ) as $s ) {
		if ( ! empty( $s['file'] ) && str_ends_with( $url, (string) $s['file'] ) ) {
			$w = (int) $s['width']; $b = (int) ( $s['filesize'] ?? 0 ); break;
		}
	}
	if ( ! $w ) { $w = (int) ( $meta['width'] ?? 0 ); $b = (int) ( $meta['filesize'] ?? 0 ); }
	if ( ! $w ) { continue; }

	$widths[] = $w;
	if ( $b ) { $bytes[] = $b; }

	/* Soft = the thumb does not cover the slot WHILE a compliant rung existed.
	 * A wider rung above one that already covers the slot is not a defect — the
	 * rule is deliberately "narrowest that covers", so 518px chosen over an
	 * available 692px is the selector working, not failing. */
	$compliant = array_filter( $offered, static fn( $o ) =>
		$o >= LG_WD_Query::THUMB_SLOT_PX && $o <= LG_WD_Query::THUMB_MAX_PX );
	if ( $w < LG_WD_Query::THUMB_SLOT_PX && $compliant ) { $soft++; }
	if ( $w > LG_WD_Query::THUMB_MAX_PX ) { $heavy++; }
}
if ( ! $widths ) {
	echo "   FAIL: nothing was actually measured for weight.\n";
	$fail++;
} else {
	sort( $widths );
	$median = $widths[ intdiv( count( $widths ), 2 ) ];
	$mean   = $bytes ? array_sum( $bytes ) / count( $bytes ) / 1024 : 0;
	printf( "%-58s %dpx median, %dKB mean (n=%d)\n", '5. resolved thumb size', $median, $mean, count( $widths ) );
	printf( "%-58s %d\n", '   narrower than the ' . LG_WD_Query::THUMB_SLOT_PX . 'px slot despite a wider rung', $soft );
	printf( "%-58s %d\n", '   wider than ' . LG_WD_Query::THUMB_MAX_PX . 'px (1.7x the slot)', $heavy );
	if ( $soft > 0 ) {
		echo "   FAIL: a wider rung existed and was not used — soft on a phone.\n";
		$fail++;
	}
	if ( $heavy > count( $widths ) * 0.05 ) {
		echo "   FAIL: more than 5% overshoot the craft ceiling.\n";
		$fail++;
	}
}

/* ── 6. Privacy is an allowlist, not a formality ─────────────────────────────
 *
 * wp_bp_media also holds message (DM), grouponly, loggedin, friends and onlyme
 * photos. Nothing about the meta key stops a future composer from putting one of
 * those ids in bp_media_ids, and email is unrecallable.
 *
 * THIS ASSERTION REPORTS WHICH KIND OF PROOF IT GOT. Writing a fixture would mean
 * writing to the DB, which this lane does not do, so where the corpus has no
 * non-forums media attached to a discussion the clause is checked lexically and
 * SAYS SO rather than printing a pass it did not earn. (An earlier draft asserted
 * `privacy <> 'forums' AND privacy = 'forums'`, which is 0 by construction — a
 * check that could never fail, reported as if it had passed.) */

$arm( true );
$leaky = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}bp_media WHERE privacy <> 'forums' AND status='published' AND type='photo'"
);

/* Topics that actually reference a non-forums photo — the only real fixture. */
$exposed = $wpdb->get_col(
	"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
	   JOIN {$wpdb->prefix}bp_media m
	     ON FIND_IN_SET(m.id, REPLACE(pm.meta_value, ' ', ''))
	  WHERE pm.meta_key = 'bp_media_ids' AND pm.meta_value <> ''
	    AND m.privacy <> 'forums' AND m.type = 'photo' AND m.status = 'published'
	  LIMIT 20"
);

if ( $exposed ) {
	$leaked = 0;
	foreach ( $exposed as $id ) {
		$url = LG_WD_Query::topic_media_thumb( (int) $id );
		if ( $url === '' ) { continue; }
		$bad = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bp_media m
			   JOIN {$wpdb->postmeta} pm ON pm.post_id = %d AND pm.meta_key='bp_media_ids'
			      AND FIND_IN_SET(m.id, REPLACE(pm.meta_value,' ',''))
			  WHERE m.privacy <> 'forums'
			    AND m.attachment_id = (SELECT ID FROM {$wpdb->posts} WHERE guid = %s LIMIT 1)",
			(int) $id, $url
		) );
		if ( $bad ) { $leaked++; }
	}
	printf( "%-58s %d non-forums photos, %d leaked (behavioural, n=%d)\n",
		'6. privacy allowlist', $leaky, $leaked, count( $exposed ) );
	if ( $leaked ) {
		echo "   FAIL: a non-forums photo reached a discussion card.\n";
		$fail++;
	}
} else {
	/* No topic references a non-forums photo, so there is no natural fixture and
	 * this lane does not write to the DB to invent one. Instead: lift the SELECT
	 * the code actually runs out of the source and execute it over an id list
	 * that DOES contain non-forums photos. That exercises the real clause rather
	 * than counting the string — grepping the file passed happily with the
	 * allowlist deleted, because the phrase also appears in the doc comment. */
	$src = (string) file_get_contents( $BRANCH );
	if ( ! preg_match( '/"(SELECT attachment_id FROM \{\$wpdb->prefix\}bp_media.*?)"/s', $src, $q ) ) {
		printf( "%-58s %s\n", '6. privacy allowlist', 'CANNOT RUN — query literal not found' );
		fwrite( STDERR, "CANNOT RUN: the bp_media SELECT changed shape; assertion 6 cannot locate it.\n" );
		exit( 2 );
	}

	$probe_ids = $wpdb->get_col(
		"SELECT id FROM {$wpdb->prefix}bp_media
		  WHERE privacy <> 'forums' AND status='published' AND type='photo' LIMIT 50"
	);
	if ( ! $probe_ids ) {
		printf( "%-58s %s\n", '6. privacy allowlist', 'CANNOT RUN — no non-forums photo anywhere' );
		fwrite( STDERR, "CANNOT RUN: corpus has no non-forums photo to probe with.\n" );
		exit( 2 );
	}

	$sql  = str_replace(
		[ '{$wpdb->prefix}', '($in)' ],
		[ $wpdb->prefix, '(' . implode( ',', array_map( 'absint', $probe_ids ) ) . ')' ],
		$q[1]
	);
	$got  = $wpdb->get_col( $sql );
	$n    = count( $got );
	printf( "%-58s %d non-forums photos, %d returned by the real query (n=%d probed)\n",
		'6. privacy allowlist', $leaky, $n, count( $probe_ids ) );
	if ( $n > 0 ) {
		echo "   FAIL: the query the code runs selected non-forums media — a DM or\n";
		echo "         group-only photo could reach an inbox. Email is unrecallable.\n";
		$fail++;
	}
}

/* ── 7. Scope: only discussions are touched ────────────────────────────────── */

$other = $wpdb->get_col(
	"SELECT p.ID FROM {$wpdb->posts} p
	  JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'bp_media_ids' AND pm.meta_value <> ''
	 WHERE p.post_type NOT IN ('topic','reply') AND p.post_status='publish' LIMIT 10"
);
$touched = 0;
if ( $other ) {
	$arm( false );
	$was = [];
	foreach ( $other as $id ) {
		$p = get_post( (int) $id );
		if ( $p ) { $was[ (int) $id ] = LG_WD_Query::normalize_post( $p )['thumb_url']; }
	}
	$arm( true );
	foreach ( $was as $id => $w ) {
		$p = get_post( (int) $id );
		if ( $p && LG_WD_Query::normalize_post( $p )['thumb_url'] !== $w ) { $touched++; }
	}
	printf( "%-58s %d of %d\n", '7. non-discussion posts moved by the flag', $touched, count( $was ) );
	if ( $touched ) {
		echo "   FAIL: the fallback escaped the discussion section.\n";
		$fail++;
	}
} else {
	printf( "%-58s %s\n", '7. non-discussion posts carrying bp_media_ids', 'none in this corpus' );
}

echo "\n";
if ( $fail ) {
	echo "RED: $fail assertion(s) failed.\n";
	exit( 1 );
}
echo "GREEN: discussion media resolves under the flag; nothing that already worked moved.\n";
exit( 0 );
