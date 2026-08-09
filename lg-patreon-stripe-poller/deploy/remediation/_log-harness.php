<?php
/**
 * Subprocess harness for test-log-unblinding.php. Not a test itself.
 *
 * Run as a subprocess because LGMS_LOG_DIR and ABSPATH are CONSTANTS — each
 * scenario needs its own values, and a constant cannot be redefined in one
 * process. Emits a JSON result on stdout.
 *
 *   php _log-harness.php <abspath> <logdir|-> <errlog> [rotate]
 */

declare(strict_types=1);

[ , $abspath, $logdir, $errlog ] = $argv;
$rotate = in_array( 'rotate', $argv, true );

ini_set( 'error_log', $errlog );
ini_set( 'log_errors', '1' );
ini_set( 'display_errors', '0' );

define( 'ABSPATH', rtrim( $abspath, '/' ) . '/' );
if ( $logdir !== '-' ) { define( 'LGMS_LOG_DIR', $logdir ); }

// wp_upload_dir deliberately EXISTS and points somewhere writable inside the
// web root — so "uploads is never chosen" is a real assertion, not a vacuous
// one that passes because the function is missing.
$GLOBALS['UPLOADS'] = rtrim( $abspath, '/' ) . '/wp-content/uploads';
@mkdir( $GLOBALS['UPLOADS'], 0775, true );
function wp_upload_dir( $t = null, $c = true ) {
    return [ 'basedir' => $GLOBALS['UPLOADS'], 'error' => false ];
}

require_once __DIR__ . '/../../src/Log.php';

use LGMS\Log;

$marker = 'MARK-' . getmypid();

if ( $rotate ) {
    $p = Log::file();
    if ( $p !== null ) { file_put_contents( $p, str_repeat( 'x', 8388609 ) ); }
}

$threw = false;
try {
    Log::line( "[t] provision failed: {$marker}\n" );
} catch ( \Throwable $e ) {
    $threw = true;
}

$st   = Log::status();
$path = $st['path'];

echo json_encode( [
    'threw'        => $threw,
    'path'         => $path,
    'sink'         => $st['sink'],
    'emitted'      => $st['emitted'],
    'marker'       => $marker,
    'in_file'      => ( $path !== null && is_file( $path ) )
                        ? ( strpos( (string) file_get_contents( $path ), $marker ) !== false ) : false,
    'rotated'      => ( $path !== null && is_file( $path . '.1' ) ),
    'uploads_used' => ( $path !== null && strpos( $path, '/wp-content/uploads' ) !== false ),
    'htaccess'     => ( $path !== null && is_file( dirname( $path ) . '/.htaccess' ) ),
    'errlog'       => is_file( $errlog ) ? (string) file_get_contents( $errlog ) : '',
] ), "\n";
