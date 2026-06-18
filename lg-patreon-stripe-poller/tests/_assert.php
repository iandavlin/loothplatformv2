<?php
/**
 * Zero-dependency assert harness for the poller's pure-logic tests.
 * No PHPUnit, no WordPress bootstrap. `php tests/<name>.php` exits non-zero
 * on the first failure so tests/run.sh can gate a push.
 */

declare(strict_types=1);

$GLOBALS['__lgms_test_pass'] = 0;

function lgms_ok( bool $cond, string $what ): void {
    if ( $cond ) {
        $GLOBALS['__lgms_test_pass']++;
        fwrite( STDOUT, "  ok   {$what}\n" );
        return;
    }
    fwrite( STDERR, "  FAIL {$what}\n" );
    exit( 1 );
}

function lgms_eq( $expected, $actual, string $what ): void {
    lgms_ok(
        $expected === $actual,
        $what . '  (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')'
    );
}

function lgms_done( string $suite ): void {
    fwrite( STDOUT, "{$suite}: {$GLOBALS['__lgms_test_pass']} assertions passed\n" );
}
