<?php
/**
 * RED-FIRST GATE for LGMS\Log (audit R4 — the tick had no log on live).
 *
 *   php test-log-unblinding.php
 *
 * Exit 0 green, 1 a real defect, 3 cannot run.
 *
 * The assertion that matters is not "a log file appears". It is that a line is
 * NEVER SILENTLY LOST — the old code's whole failure was that it dropped every
 * line and said nothing. So the unwritable-directory case is tested explicitly,
 * and is required to (a) not throw and (b) still surface the line.
 *
 * An absence assertion is vacuous without a liveness assertion: "no log written"
 * is trivially true on a box with no PHP at all. Every negative case here is
 * paired with a positive one proving the writer is alive.
 */

declare(strict_types=1);

if ( ! is_readable( __DIR__ . '/../../src/Log.php' ) ) {
    fwrite( STDERR, "CANNOT RUN: src/Log.php not found\n" ); exit( 3 );
}

// ---- capture error_log() so "did the line survive?" is observable
$ERRLOG = sys_get_temp_dir() . '/lgms-log-gate-' . getmypid() . '.err';
ini_set( 'error_log', $ERRLOG );
ini_set( 'log_errors', '1' );
ini_set( 'display_errors', '0' );

function errlog_read( string $p ): string { return is_file( $p ) ? (string) file_get_contents( $p ) : ''; }
function errlog_clear( string $p ): void { if ( is_file( $p ) ) { unlink( $p ); } }

$ROOT = sys_get_temp_dir() . '/lgms-log-gate-root-' . getmypid();
@mkdir( $ROOT, 0775, true );

// wp_upload_dir stub — the second candidate in Log's resolution order.
$GLOBALS['UPLOAD_BASEDIR'] = $ROOT . '/uploads';
@mkdir( $GLOBALS['UPLOAD_BASEDIR'], 0775, true );
function wp_upload_dir( $t = null, $c = true ) {
    return [ 'basedir' => $GLOBALS['UPLOAD_BASEDIR'], 'error' => false ];
}

require_once __DIR__ . '/../../src/Log.php';
use LGMS\Log;

$fail = 0; $pass = 0;
$note = function ( bool $ok, string $label, string $detail = '' ) use ( &$fail, &$pass ) {
    if ( $ok ) { $pass++; printf( "  ok   %s\n", $label ); }
    else       { $fail++; printf( "  FAIL %s%s\n", $label, $detail ? "  ({$detail})" : '' ); }
};

echo "=== LGMS\\Log — red-first gate (audit R4) ===\n";

// ---------------------------------------------------------- 1. happy path
echo "\n[1] a writable uploads dir gets a real log file\n";
Log::reset();
errlog_clear( $ERRLOG );
Log::line( "[t] tick start\n" );
$path = Log::file();
$note( $path !== null && is_file( $path ), 'log file created under uploads/lg-logs', (string) $path );
$note( $path !== null && strpos( $path, '/uploads/lg-logs/tick.log' ) !== false, 'path is uploads/lg-logs/tick.log' );
$note( strpos( (string) @file_get_contents( (string) $path ), 'tick start' ) !== false, 'the line is IN the file' );
$note( strpos( errlog_read( $ERRLOG ), 'tick start' ) === false, 'a successful write does NOT also spam error_log' );

$st = Log::status();
$note( $st['writable'] === true && $st['exists'] === true, 'status() reports writable + exists' );

// the log must not be web-readable — it sits under a served uploads dir
$note( is_file( dirname( (string) $path ) . '/.htaccess' ), 'lg-logs carries a deny .htaccess' );

// ---------------------------------------------------------- 2. THE BUG
echo "\n[2] an UNWRITABLE directory must fail loudly, never silently\n";
$ro = $ROOT . '/readonly';
@mkdir( $ro, 0500, true );
@chmod( $ro, 0500 );

if ( posix_getuid() === 0 ) {
    echo "  skip  (running as root — mode 0500 does not block root)\n";
} else {
    Log::reset();
    errlog_clear( $ERRLOG );
    $GLOBALS['UPLOAD_BASEDIR'] = $ro;          // uploads itself unwritable
    define( 'LGMS_LOG_DIR', $ro . '/nope' );   // and the override too

    $threw = false;
    try { Log::line( "[t] provision failed: boom\n" ); }
    catch ( \Throwable $e ) { $threw = true; }

    $err = errlog_read( $ERRLOG );
    $note( ! $threw, 'logging to an unwritable dir does NOT throw' );
    $note( strpos( $err, 'provision failed: boom' ) !== false,
           'THE LINE SURVIVES — routed to error_log, not dropped' );
    $note( strpos( $err, 'no writable log directory' ) !== false,
           'the inability to log is ITSELF logged (the R4 gap)' );
    $note( Log::file() === null, 'file() reports null rather than a lying path' );

    // liveness: the same call site works the moment a writable dir exists
    Log::reset();
    $GLOBALS['UPLOAD_BASEDIR'] = $ROOT . '/uploads';
    Log::line( "[t] recovered\n" );
    $note( Log::file() !== null && strpos( (string) @file_get_contents( (string) Log::file() ), 'recovered' ) !== false,
           'liveness: the writer works again once a dir is writable' );
}

// ---------------------------------------------------------- 3. no @-silencing left
echo "\n[3] the silenced writes are gone from the tick\n";
$tick = (string) file_get_contents( __DIR__ . '/../../src/Tick.php' );
// grep -c counts LINES; count OCCURRENCES.
$silenced = preg_match_all( '/@file_put_contents/', $tick );
$plugindir = preg_match_all( '/LGMS_PLUGIN_DIR\s*\.\s*[\'"]tick\.log/', $tick );
$lines     = preg_match_all( '/Log::line\(/', $tick );
$note( $silenced === 0, 'zero @file_put_contents remain in Tick.php', "found {$silenced}" );
$note( $plugindir === 0, 'Tick.php no longer writes into the plugin dir', "found {$plugindir}" );
$note( $lines === 15, 'all 15 call sites route through Log::line', "found {$lines}" );
$note( strpos( $tick, 'Runs hourly' ) === false, 'the stale "Runs hourly" docblock is corrected (it is 5-minutely)' );

// ---------------------------------------------------------- 4. rotation
echo "\n[4] rotation keeps a 5-minute tick from filling the disk\n";
Log::reset();
$GLOBALS['UPLOAD_BASEDIR'] = $ROOT . '/uploads';
$p = Log::file();
file_put_contents( (string) $p, str_repeat( 'x', 8388609 ) );   // just over MAX_BYTES
Log::line( "[t] after rotate\n" );
$note( is_file( (string) $p . '.1' ), 'oversized log is rotated to .1' );
$note( filesize( (string) $p ) < 1000, 'the live file starts fresh', (string) filesize( (string) $p ) );
$note( strpos( (string) file_get_contents( (string) $p ), 'after rotate' ) !== false, 'the triggering line is in the NEW file' );

// ---------------------------------------------------------- 5. mutation
echo "\n[5] mutation — the gate must be able to fail\n";
// Prove [3] is not vacuous: the same assertions run against the ORIGINAL
// pre-fix shape of the code and must all go red.
$old = "<?php\n\$log = LGMS_PLUGIN_DIR . 'tick.log';\n@file_put_contents( \$log, 'x', FILE_APPEND );\n * Runs hourly via WP cron\n";
$note( preg_match_all( '/@file_put_contents/', $old ) === 1
    && preg_match_all( '/LGMS_PLUGIN_DIR\s*\.\s*[\'"]tick\.log/', $old ) === 1
    && preg_match_all( '/Log::line\(/', $old ) === 0
    && strpos( $old, 'Runs hourly' ) !== false,
       'the [3] assertions all go RED against the pre-fix code' );

// ---------------------------------------------------------- cleanup
@chmod( $ro, 0755 );
exec( 'rm -rf ' . escapeshellarg( $ROOT ) );
errlog_clear( $ERRLOG );

printf( "\n%d passed, %d failed\n", $pass, $fail );
if ( $fail ) { echo "RED\n"; exit( 1 ); }
echo "GREEN — the tick can log, and cannot lose a line in silence.\n";
exit( 0 );
