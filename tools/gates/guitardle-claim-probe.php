<?php
// Drives the WORKING TREE's guitardle-score.php with a real WP session cookie.
// Not curl: curl would reach /srv, i.e. the serving checkout (main), and prove
// nothing about this branch.
if (getenv('GDLE_FLAG') !== '') {
    define('LG_GUITARDLE_DAILY_CLAIM', getenv('GDLE_FLAG') === '1');
}
// Separate knob: the rules overlay has its OWN flag, and the gate has to be able
// to drive the two independently or "independent" is an untested word.
if (getenv('GDLE_HELP') !== '') {
    define('LG_GUITARDLE_HOW_TO_PLAY', getenv('GDLE_HELP') === '1');
}
if (getenv('GDLE_RETRY') !== '') {
    define('LG_GUITARDLE_SCORE_RETRY', getenv('GDLE_RETRY') === '1');
}
if (getenv('GDLE_SERVERPLAY') !== '') {
    define('LG_GUITARDLE_SERVER_PLAY', getenv('GDLE_SERVERPLAY') === '1');
}
$cn = getenv('GDLE_COOKIE_NAME');
if ($cn && getenv('GDLE_COOKIE')) $_COOKIE[$cn] = getenv('GDLE_COOKIE');
$_SERVER['REQUEST_METHOD'] = getenv('GDLE_METHOD') ?: 'GET';
$_SERVER['HTTP_HOST']      = getenv('GDLE_HOST') ?: 'dev2.loothgroup.com';
$_SERVER['REQUEST_URI']    = '/archive-api/v0/guitardle-score';
$_SERVER['HTTPS']          = 'on';
parse_str((string) getenv('GDLE_QS'), $_GET);
$b = json_decode((string) getenv('GDLE_BODY'), true);
$_POST = is_array($b) ? $b : [];
if (getenv('GDLE_NONCE')) $_SERVER['HTTP_X_WP_NONCE'] = getenv('GDLE_NONCE');
require getenv('GDLE_ENDPOINT');
