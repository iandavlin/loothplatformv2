<?php
/**
 * verify-recap-flag-off.php — with LG_WD_RECAP_ENABLED OFF, the weekly digest must be
 * EXACTLY what it was before this lane existed: same body, same recipients.
 *
 * ── WHY THIS IS THE MOST IMPORTANT ASSERTION IN THE SUITE ───────────────────
 *
 * EMAIL IS UNRECALLABLE. Live carries a scheduled cron event `lg_wd_send_digest`
 * (next fire 2026-08-03 13:00 UTC), so this feature sits one boolean away from the
 * real member list at all times. Every other blocker on the board is recoverable.
 *
 * THREE THINGS ARE GATED, and the third is the one that reaches members:
 *   1. the `##lg_recap.section##` token in templates/email.php
 *   2. LG_WD_Recap_Source::init()'s smart-code registration
 *   3. recipients_with_something_waiting() — WHO IS MAILED AT ALL
 *
 * (3) is why gating only 1 and 2 would have been a trap. That filter is invoked on
 * `class_exists()`, not on the registration, so unregistering the smart code would
 * leave the suppression running with nothing to show for it: the digest would skip
 * ~75% of the list (measured on live 2026-07-30: 473 of 1,859 kept) while rendering
 * no recap at all. Fewer people silently receiving mail is a worse failure than the
 * feature being visible, and it is the one a "the section is gone" check misses.
 *
 * Runs under plain php — it must prove THIS BRANCH before the merge it justifies,
 * and under WordPress it would exercise the serving copy which has no flag yet.
 *
 * Run: php lg-weekly-digest/dev/verify-recap-flag-off.php
 */

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
$SRC  = $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap-source.php';
$TPL  = $LANE . '/lg-weekly-digest/templates/email.php';
$fail = 0;

foreach ( [ $SRC, $TPL ] as $f ) {
	if ( ! is_readable( $f ) ) { fwrite( STDERR, "CANNOT RUN: unreadable: $f\n" ); exit( 2 ); }
}
$src = (string) file_get_contents( $SRC );
$tpl = (string) file_get_contents( $TPL );

/* ── 1. The default must be OFF ────────────────────────────────────────────── */
if ( ! preg_match( '/function recap_enabled\(\)[^{]*\{(.*?)\n\t\}/s', $src, $m ) ) {
	fwrite( STDERR, "CANNOT RUN: recap_enabled() not found — the flag's read site changed shape.\n" );
	exit( 2 );
}
/* `.*?`, not `[^:]*`: the true-branch contains `self::ENABLE_FLAG`, whose `::` a
 * negated-colon class cannot cross. That exact mistake shipped twice today. */
if ( ! preg_match( '/defined\(\s*self::ENABLE_FLAG\s*\).*?:\s*false\s*;/s', $m[1] ) ) {
	echo "  FAIL: recap_enabled() does not default to FALSE when the constant is undefined.\n";
	$fail++;
} else {
	echo "default when undefined        : OFF\n";
}

/* ── 2. All three gate points must actually consult it ─────────────────────── */
$points = [
	'token emission (email.php)'      => (bool) preg_match( '/recap_enabled\(\)\s*\)\s*\{\s*\n\s*echo LG_WD_RECAP_SMARTCODE/', $tpl ),
	'init() registration'             => (bool) preg_match( '/function init\(\)[^{]*\{\s*if \( ! self::recap_enabled\(\) \)/s', $src ),
	'recipients_with_something_waiting' => (bool) preg_match( '/function recipients_with_something_waiting\([^)]*\)[^{]*\{.*?if \( ! self::recap_enabled\(\) \)\s*\{\s*return \$subscriber_ids;/s', $src ),
];
foreach ( $points as $label => $ok ) {
	printf( "gate %-32s %s\n", $label, $ok ? 'wired' : 'MISSING' );
	if ( ! $ok ) { echo "  FAIL: $label does not consult recap_enabled().\n"; $fail++; }
}

/* ── 3. OFF MUST NOT SHRINK THE RECIPIENT SET. This is the unrecallable one. ── */
if ( preg_match( '/function recipients_with_something_waiting\([^)]*\)[^{]*\{(.*?)\n\t\}/s', $src, $r ) ) {
	$body = $r[1];
	$off  = strpos( $body, 'if ( ! self::recap_enabled() )' );
	$ret  = strpos( $body, 'return $subscriber_ids;' );
	if ( $off === false || $ret === false || $ret < $off ) {
		echo "  FAIL: the OFF branch does not return the INPUT set unchanged.\n";
		$fail++;
	} elseif ( preg_match( '/if \( ! self::recap_enabled\(\) \)\s*\{\s*return \[\];/', $body ) ) {
		echo "  FAIL: the OFF branch returns an EMPTY set — that would mail NOBODY.\n";
		$fail++;
	} else {
		echo "OFF recipient behaviour       : returns the input unchanged (everyone is mailed)\n";
	}
}

/* ── 4. The dedup must NOT live behind the flag ────────────────────────────── */
$sender = $LANE . '/lg-weekly-digest/includes/class-lg-wd-sender.php';
if ( is_readable( $sender ) ) {
	$sn = (string) file_get_contents( $sender );
	$has_own_dedup = (bool) preg_match( "/subscriber_ids\s*=\s*array_values\(\s*array_unique\(/", $sn );
	printf( "dedup independent of the flag : %s\n", $has_own_dedup ? 'yes (at the resolution site)' : 'NO' );
	if ( ! $has_own_dedup ) {
		echo "  FAIL: the only de-duplication is inside the flagged filter, so turning the\n";
		echo "        flag off would let a contact on BOTH lists receive the digest twice.\n";
		$fail++;
	}
}

/* ── 5. BYTE-IDENTITY, ACTUALLY DIFFED ─────────────────────────────────────────
 *
 * Keeper: "OFF must mean the issue renders EXACTLY as it did before this work
 * existed — prove it byte-identical, do not assert it."
 *
 * The flag's ONLY effect on the rendered body is the gated echo. So: take the
 * template as it stood on LIVE (c57b70f, before this lane), take this branch's
 * template with the gated region cut out, and require the two to be byte-identical.
 * If they are, then a flag-off render cannot differ from a pre-feature render,
 * because the only thing the flag can add is the thing that was cut. */
$pre = shell_exec( 'git -C ' . escapeshellarg( $LANE ) . ' show c57b70f:lg-weekly-digest/templates/email.php 2>/dev/null' );
if ( ! is_string( $pre ) || $pre === '' ) {
	echo "  note: could not read the pre-feature template from git (c57b70f) — byte-identity\n";
	echo "        UNPROVEN here rather than assumed.\n";
} else {
	$cut = preg_replace( '/\n\s*<\?php\n\s*\/\*\*\n\s*\* THE PER-MEMBER SEAM.*?\n\s*\?>\n/s', "\n", $tpl );
	$norm = static fn( string $x ): string => preg_replace( '/[ \t]+\n/', "\n", $x );
	if ( $norm( $cut ) === $norm( $pre ) ) {
		echo "byte-identity                 : branch template MINUS the gated region == c57b70f (live)\n";
	} else {
		echo "  FAIL: with the gated region removed, this template still differs from the\n";
		echo "        pre-feature one on live — the flag is not the only thing this lane\n";
		printf( "        added to the email body. (%d vs %d bytes)\n", strlen( $norm($cut) ), strlen( $norm($pre) ) );
		$fail++;
	}
}

echo $fail ? "\n$fail FAILURE(S)\n" : "\nRECAP FLAG OFF IS A NO-OP\n";
exit( $fail ? 1 : 0 );
