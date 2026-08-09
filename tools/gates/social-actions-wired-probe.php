<?php
/**
 * Probe for social-actions-wired-gate: render the REAL widget and report what it emits.
 *
 * Run once per flag state, as the profile-app pool user, against the real dev2
 * Postgres. Nothing is mocked and nothing is written — renderProfileActions only
 * reads (connection state + mute state).
 *
 * env:
 *   LG_SA_ROOT   repo root to render from (lets the gate compare a branch to main)
 *   LG_SOCIAL_ACTIONS_SRC  "1"/"0" — the flag override the widget itself honours
 *   LG_SA_VIEWER / LG_SA_SUBJECT  uuids
 *
 * Prints KEY=VALUE lines. Any fatal becomes ERROR= so the gate says CANNOT RUN
 * rather than inventing a verdict.
 */

$root    = getenv('LG_SA_ROOT') ?: dirname(__DIR__, 2);
$viewer  = getenv('LG_SA_VIEWER')  ?: '';
$subject = getenv('LG_SA_SUBJECT') ?: '';

set_error_handler(function ($n, $s) { throw new ErrorException($s, 0, $n); });

try {
    if ($viewer === '' || $subject === '') throw new RuntimeException('viewer/subject not set');

    require_once $root . '/profile-app/config.php';
    require_once $root . '/profile-app/src/Social.php';

    $html = \Looth\ProfileApp\Social::renderProfileActions($viewer, $subject);

    // LIVENESS FIRST. Every assertion below is about what the widget does or does
    // not contain, and all of them are vacuously true of an empty string — which is
    // exactly what this function returns for an anonymous viewer or for your own
    // profile. Without this the gate would go green on a broken fixture.
    printf("RENDERED=%d\n", strlen($html));
    printf("HAS_WIDGET=%d\n", (int)(strpos($html, 'lg-social-actions') !== false));
    printf("HAS_MOREBTN=%d\n", (int)(strpos($html, 'lg-social-morebtn') !== false));
    printf("HAS_MENU=%d\n", (int)(strpos($html, 'lg-social-menu') !== false));
    printf("N_ACTIONS=%d\n", preg_match_all('/data-lg-social="/', $html));

    // The two competing sources of behaviour. Exactly one must be present.
    printf("HAS_STAMP=%d\n", (int)(strpos($html, 'data-lg-social-src=') !== false));
    printf("HAS_INLINE=%d\n", (int)(strpos($html, '__lgSocialWired') !== false));
    if (preg_match('/data-lg-social-src="([^"]*)"/', $html, $m)) {
        printf("STAMP_SRC=%s\n", $m[1]);
    }
    if (preg_match('/<script src="([^"]*)"[^>]*><\/script>/', $html, $m)) {
        printf("SCRIPT_SRC=%s\n", $m[1]);
    }
    printf("MD5=%s\n", md5($html));
    echo "OK=1\n";
} catch (Throwable $e) {
    echo "ERROR=" . str_replace("\n", ' ', $e->getMessage()) . "\n";
}
