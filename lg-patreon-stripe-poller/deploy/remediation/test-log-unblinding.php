<?php
/**
 * RED-FIRST GATE for LGMS\Log (audit R4 — the tick had no log on live).
 *
 *   php test-log-unblinding.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * Two properties, and the second was added after checking live rather than
 * assuming it:
 *
 *   1. A LINE IS NEVER SILENTLY LOST. The original bug was that every line was
 *      dropped by an `@` and nothing said so. So the unwritable case must not
 *      throw and must still emit.
 *   2. A LINE IS NEVER WRITTEN SOMEWHERE THE PUBLIC CAN READ IT. The first cut
 *      of this class defaulted to `wp-content/uploads/lg-logs`. On live that
 *      path answers HTTP 200, and it is an R2 bucket over rclone FUSE rather
 *      than local disk. The `.htaccess` deny written alongside it was
 *      decorative — live runs nginx, which never reads .htaccess. The log
 *      carries member emails, so that was a real exposure.
 *
 * Each scenario runs in its own SUBPROCESS: ABSPATH and LGMS_LOG_DIR are
 * constants and cannot be redefined within one process, so a single-process
 * gate could only ever have tested one path — which is how the uploads default
 * went unchallenged the first time.
 */

declare(strict_types=1);

$HARNESS = __DIR__ . '/_log-harness.php';
$LOGSRC  = __DIR__ . '/../../src/Log.php';
if ( ! is_readable( $HARNESS ) ) { fwrite( STDERR, "CANNOT RUN: harness missing\n" ); exit( 3 ); }
if ( ! is_readable( $LOGSRC ) )  { fwrite( STDERR, "CANNOT RUN: src/Log.php missing\n" ); exit( 3 ); }

$ROOT = sys_get_temp_dir() . '/lgms-log-gate-' . getmypid();
@mkdir( $ROOT . '/web', 0775, true );          // stands in for ABSPATH
@mkdir( $ROOT . '/outside', 0775, true );      // a legitimate log dir

$fail = 0; $pass = 0; $skip = 0;
$note = function ( bool $ok, string $l, string $d = '' ) use ( &$fail, &$pass ) {
    if ( $ok ) { $pass++; printf( "  ok   %s\n", $l ); }
    else       { $fail++; printf( "  FAIL %s%s\n", $l, $d ? "  ({$d})" : '' ); }
};

$run = function ( string $logdir, array $extra = [] ) use ( $HARNESS, $ROOT ): array {
    static $n = 0;
    $err = $ROOT . '/err-' . ( ++$n ) . '.log';
    $cmd = escapeshellcmd( PHP_BINARY ) . ' ' . escapeshellarg( $HARNESS )
         . ' ' . escapeshellarg( $ROOT . '/web' )
         . ' ' . escapeshellarg( $logdir )
         . ' ' . escapeshellarg( $err );
    foreach ( $extra as $e ) { $cmd .= ' ' . escapeshellarg( $e ); }
    exec( $cmd . ' 2>/dev/null', $out );
    return json_decode( (string) end( $out ), true ) ?: [];
};

echo "=== LGMS\\Log — red-first gate (audit R4) ===\n";

// ------------------------------------------------ 1. the happy path
echo "\n[1] a writable dir OUTSIDE the web root gets a real file\n";
$r = $run( $ROOT . '/outside/lg-logs' );
$note( ( $r['path'] ?? null ) !== null, 'a path is resolved', (string) ( $r['path'] ?? 'null' ) );
$note( ( $r['sink'] ?? '' ) === 'file', 'sink is the file', $r['sink'] ?? '?' );
$note( ( $r['in_file'] ?? false ) === true, 'the line is IN the file' );
$note( ( $r['threw'] ?? true ) === false, 'does not throw' );

// ------------------------------------------------ 2. THE EXPOSURE BUG
echo "\n[2] a dir inside the web root is REFUSED — it would be publicly fetchable\n";
$r = $run( $ROOT . '/web/wp-content/uploads/lg-logs' );
$note( array_key_exists( 'path', $r ) && $r['path'] === null, 'no file path is used', var_export( $r['path'] ?? 'missing', true ) );
$note( ( $r['uploads_used'] ?? true ) === false, 'uploads is NOT written to' );
$note( strpos( $r['errlog'] ?? '', 'inside the web root' ) !== false,
       'the refusal says WHY, naming public fetchability' );
$note( ( $r['sink'] ?? '' ) === 'syslog', 'the line goes to syslog instead', $r['sink'] ?? '?' );
$note( (int) ( $r['emitted'] ?? 0 ) === 1, 'THE LINE STILL SURVIVES — exactly one emission' );
$note( ( $r['threw'] ?? true ) === false, 'and it does not throw' );

// uploads must not be picked even when it is the ONLY writable thing around
echo "\n[3] uploads is never chosen even with no LGMS_LOG_DIR set\n";
$r = $run( '-' );
$note( array_key_exists( 'path', $r ) && $r['path'] === null, 'no path — uploads is not a candidate at all' );
$note( ( $r['uploads_used'] ?? true ) === false, 'uploads still not written to' );
$note( ( $r['sink'] ?? '' ) === 'syslog', 'falls straight to syslog', $r['sink'] ?? '?' );
$note( (int) ( $r['emitted'] ?? 0 ) === 1, 'the line survives' );
$note( ( $r['htaccess'] ?? false ) === false,
       'no .htaccess theatre is written — nginx would never read it' );

// ------------------------------------------------ 4. unwritable, outside root
echo "\n[4] an unwritable dir outside the web root still loses nothing\n";
$ro = $ROOT . '/readonly';
@mkdir( $ro, 0500, true ); @chmod( $ro, 0500 );
if ( posix_getuid() === 0 ) {
    echo "  skip  (running as root — mode 0500 does not block root)\n"; $skip++;
} else {
    $r = $run( $ro . '/nope' );
    $note( array_key_exists( 'path', $r ) && $r['path'] === null, 'no path' );
    $note( (int) ( $r['emitted'] ?? 0 ) === 1, 'the line survives' );
    $note( strpos( $r['errlog'] ?? '', 'not writable' ) !== false, 'the reason is logged' );
}

// ------------------------------------------------ 5. does syslog really receive?
echo "\n[5] syslog genuinely receives (end-to-end, not just 'we called it')\n";
$r = $run( '-' );
exec( 'journalctl -t lgms-poller -n 40 --no-pager 2>/dev/null', $j, $jc );
if ( $jc !== 0 || ! $j ) {
    printf( "  skip  journalctl unreadable here — cannot prove the far end (sink was '%s')\n", $r['sink'] ?? '?' );
    $skip++;
} else {
    $note( strpos( implode( "\n", $j ), (string) $r['marker'] ) !== false,
           'the exact line appears in the journal under tag lgms-poller' );
}

// ------------------------------------------------ 6. rotation
echo "\n[6] rotation keeps a 5-minute tick from filling the disk\n";
$r = $run( $ROOT . '/outside/rot', [ 'rotate' ] );
$note( ( $r['rotated'] ?? false ) === true, 'oversized log rotated to .1' );
$note( ( $r['in_file'] ?? false ) === true, 'the triggering line is in the NEW file' );

// ------------------------------------------------ 7. code shape
echo "\n[7] the silenced writes are gone from the tick\n";
$tick = (string) file_get_contents( __DIR__ . '/../../src/Tick.php' );
$src  = (string) file_get_contents( $LOGSRC );
// grep -c counts LINES; count OCCURRENCES.
$note( preg_match_all( '/@file_put_contents/', $tick ) === 0, 'zero @file_put_contents in Tick.php' );
$note( preg_match_all( '/LGMS_PLUGIN_DIR\s*\.\s*[\'"]tick\.log/', $tick ) === 0, 'Tick.php does not write into the plugin dir' );
// 15 original sites + the retraction-sweep pass's FAILED line (Pass 3).
$note( preg_match_all( '/Log::line\(/', $tick ) === 16, 'all 16 call sites route through Log::line' );
$note( strpos( $tick, 'Runs hourly' ) === false, 'the stale "Runs hourly" docblock is corrected' );
$note( preg_match_all( "/\\\$up\\['basedir'\\]/", $src ) === 0
    && preg_match_all( '/function_exists\s*\(\s*.wp_upload_dir/', $src ) === 0,
       'src/Log.php builds no candidate from uploads (naming it in a comment is fine)' );

// ------------------------------------------------ 8. mutation
echo "\n[8] mutation — the gate must be able to fail\n";
$old = "<?php\n\$log = LGMS_PLUGIN_DIR . 'tick.log';\n@file_put_contents( \$log, 'x', FILE_APPEND );\n * Runs hourly\n";
$note( preg_match_all( '/@file_put_contents/', $old ) === 1
    && preg_match_all( '/Log::line\(/', $old ) === 0
    && strpos( $old, 'Runs hourly' ) !== false,
       'the [7] assertions all go RED against the pre-fix code' );
$deep = $run( $ROOT . '/web/x' );
$note( array_key_exists( 'path', $deep ) && $deep['path'] === null,
       'any path under the web root is refused, not just uploads' );

@chmod( $ro, 0755 );
exec( 'rm -rf ' . escapeshellarg( $ROOT ) );

printf( "\n%d passed, %d failed, %d skipped\n", $pass, $fail, $skip );
if ( $fail ) { echo "RED\n"; exit( 1 ); }
echo "GREEN — the tick can log, loses no line, and cannot write where the public can read.\n";
exit( 0 );
