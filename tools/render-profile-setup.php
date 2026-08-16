<?php
/**
 * Render the profile-setup step's REAL output to a file, without the route.
 *
 * The mu-plugin is not symlinked into the dev2 docroot, so /profile-setup/ 404s
 * and a browser cannot reach the page to be captured. This renders the same
 * template the route would, by loading the plugin and invoking ITS OWN init
 * callback — pulled out of $wp_filter rather than firing do_action('init'),
 * which would re-run every other plugin's init inside a CLI process.
 *
 * The callback ends in exit, so the HTML is captured in a shutdown handler.
 *
 *   wp --path=/var/www/dev eval-file tools/render-profile-setup.php <uid> <outdir>
 */
$uid = isset( $args[0] ) ? (int) $args[0] : 0;
$out = isset( $args[1] ) ? rtrim( $args[1], '/' ) : '';
if ( $uid <= 0 || $out === '' ) {
	fwrite( STDERR, "usage: eval-file render-profile-setup.php <uid> <outdir>\n" );
	return;
}
if ( ! is_dir( $out ) ) {
	mkdir( $out, 0755, true );
}

// The step only registers its route when it is live; this is the documented
// override the lane-preview path uses. $_SERVER, not putenv: the config reads
// both, and $_SERVER is the one a preview can actually set.
$_SERVER['LG_PROFILE_SETUP'] = '1';

$plugin = dirname( __DIR__ ) . '/platform/mu-plugins/lg-profile-setup.php';
if ( ! is_readable( $plugin ) ) {
	fwrite( STDERR, "cannot read {$plugin}\n" );
	return;
}

wp_set_current_user( $uid );
if ( ! is_user_logged_in() ) {
	fwrite( STDERR, "user {$uid} did not become the current user\n" );
	return;
}

require $plugin;

// Take THIS plugin's callback only — the last one added to init at priority 10.
global $wp_filter;
$cbs = $wp_filter['init']->callbacks[10] ?? array();
if ( ! $cbs ) {
	fwrite( STDERR, "no init callbacks at priority 10\n" );
	return;
}
$last = end( $cbs );
$fn   = $last['function'];

// file => [request uri, a marker that ONLY that page can contain]
$targets = array(
	'rendered.html' => array( '/profile-setup/', 'ps-name' ),
	'skipped.html'  => array( '/profile-setup/?skipped=1', 'No problem' ),
);

// Each render ends in exit(), so run each in its own child process: one exit
// would otherwise end the whole run after the first file.
foreach ( $targets as $file => $spec ) {
	list( $uri, $marker ) = $spec;
	$pid = pcntl_fork();
	if ( $pid === 0 ) {
		$_SERVER['REQUEST_URI'] = $uri;
		$_GET = array();
		if ( strpos( $uri, 'skipped=1' ) !== false ) {
			$_GET['skipped'] = '1';
		}
		$path = $out . '/' . $file;
		file_put_contents( $path, '' );
		// A CALLBACK buffer, not a plain ob_start(): the shared site-header
		// partial flushes buffers of its own, and a plain buffer read back at
		// shutdown came back EMPTY while the page printed to stdout instead.
		// A callback fires on every flush, so the bytes land in the file whoever
		// flushes them, and returning '' keeps them off stdout.
		ob_start( function ( $buf ) use ( $path ) {
			if ( $buf !== '' ) {
				file_put_contents( $path, $buf, FILE_APPEND );
			}
			return '';
		}, 1 );
		register_shutdown_function( function () use ( $path ) {
			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
			fwrite( STDERR, sprintf( "  %-16s %d bytes\n", basename( $path ), (int) @filesize( $path ) ) );
		} );
		$fn();
		exit( 0 );
	}
	pcntl_waitpid( $pid, $status );
}

// LIVENESS, PER PAGE. The two pages are genuinely different documents, so one
// shared marker is wrong: an earlier version demanded 'ps-name' on the SKIPPED
// page, which has no form at all, and reported a perfectly good 48KB render as a
// failure. Each page asserts the marker only it can contain.
$bad = 0;
foreach ( $targets as $file => $spec ) {
	$p    = $out . '/' . $file;
	$html = is_readable( $p ) ? file_get_contents( $p ) : '';
	if ( strpos( $html, '</style>' ) === false || strpos( $html, $spec[1] ) === false ) {
		fwrite( STDERR, "LIVENESS FAIL: {$file} lacks a style block or the marker '{$spec[1]}'\n" );
		$bad++;
	}
}
if ( $bad ) {
	fwrite( STDERR, "{$bad} render(s) failed liveness — do NOT build snapshots from these\n" );
}
echo "rendered to {$out}\n";
