<?php
/**
 * keeper-notify.php — deliver one email to Ian from dev2, or fail loudly.
 *
 * Invoked by the `keeper-notify` wrapper alongside it. Do not call directly;
 * the wrapper handles root elevation and the hang timeout.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 * There is NO working outbound mail path from dev2 by default:
 *   - no sendmail installed;
 *   - mailpit is active and SWALLOWS anything sent through wp_mail while
 *     returning TRUE, so "the test passed" means nothing (banked trap:
 *     any lane that "verified email" via wp_mail verified nothing);
 *   - the keeper IAM user (devgbox-cli) holds NO SES permissions at all —
 *     GetSendQuota, ListIdentities and GetAccountSendingEnabled are each
 *     AccessDenied, so the AWS CLI is not an option either.
 * The one proven path is the SES credentials FluentSMTP already holds.
 *
 * ── WHY IT REUSES FluentSMTP'S CLIENT RATHER THAN R2.php's SigV4 ─────────────
 * The brief pointed at profile-app/src/R2.php, which does implement AWS SigV4
 * for S3/R2 — and SES signs the same way. But the strongest available reuse is
 * one level up: FluentSMTP ships its own SES client, and that client is the
 * exact code path that has *demonstrably* delivered mail from this box (it is
 * what returned a real MessageId). Hand-porting R2's S3 signer to SES would
 * mean re-deriving the canonical request for a different service and a
 * different API shape, at 2am, to arrive at code that is at best equal to what
 * is already installed and proven. So: no new signing code. Same intent as the
 * brief — reuse, do not reinvent — applied to the better candidate.
 *
 * ── THIS IS NOT wp_mail ──────────────────────────────────────────────────────
 * We bootstrap WordPress only to read settings and load the SES class. The send
 * itself is a direct HTTPS call to the SES API. mailpit hooks the wp_mail /
 * PHPMailer chain and cannot see this, which is the entire point.
 *
 * ── THE FAILURE MODE THIS GUARDS ─────────────────────────────────────────────
 * Ian's plan is that when every lane is finished, dev2 emails him and then
 * powers itself off. Nothing on our infrastructure can wake this box — only the
 * AWS console. So this email is the LAST thing that happens before the box goes
 * dark. A notifier that silently reports success would mean no notice AND an
 * unreachable box. Hence: exit 0 ONLY on a MessageId returned by SES. Every
 * other outcome is a non-zero exit with the reason on stderr.
 */

declare(strict_types=1);

const EXIT_OK          = 0;
const EXIT_USAGE       = 64;
const EXIT_NO_WP       = 65;
const EXIT_NO_SETTINGS = 66;
const EXIT_NO_SES      = 67;
const EXIT_BAD_CREDS   = 68;
const EXIT_SEND_FAILED = 69;

function fail(int $code, string $msg): never
{
    fwrite(STDERR, "keeper-notify: FAILED — {$msg}\n");
    exit($code);
}

// ── Make "loud" survive the WordPress bootstrap ──────────────────────────────
// Measured, not assumed: bootstrapping WP repoints error_log to
// /var/www/dev/wp-content/debug.log. So after that point an uncaught fatal or
// exception inside the SES client would be written to a FILE and the operator
// would see a bare non-zero exit with no message on the terminal — silent from
// where they are standing. For a tool whose whole job is never to fail quietly,
// that is a hole. These two handlers write to STDERR explicitly, which no ini
// setting can redirect away.
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, 'keeper-notify: FAILED — uncaught ' . get_class($e) . ': '
        . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(EXIT_SEND_FAILED);
});
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e !== null && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        fwrite(STDERR, 'keeper-notify: FAILED — fatal: ' . $e['message']
            . ' @ ' . $e['file'] . ':' . $e['line'] . "\n");
        // Exit code is already non-zero on a fatal; this only guarantees the
        // operator is told why, rather than finding it in debug.log later.
    }
});

// ── Arguments ────────────────────────────────────────────────────────────────
$argvv   = $_SERVER['argv'] ?? [];
$subject = $argvv[1] ?? '';
$body    = $argvv[2] ?? '';
$to      = $argvv[3] ?? (getenv('KEEPER_NOTIFY_TO') ?: 'ian.davlin@gmail.com');

if ($subject === '') {
    fail(EXIT_USAGE, 'no subject. usage: keeper-notify "<subject>" "<body>" [recipient]');
}
// A literal "-" means "read the body from stdin". Long prose containing
// backticks gets shell-substituted and silently mangled when passed as a
// double-quoted argument — the same trap that mangles board posts — so piping
// is the safe way to send anything longer than a line.
if ($body === '-') {
    $body = (string) stream_get_contents(STDIN);
}
if (trim($body) === '') {
    fail(EXIT_USAGE, 'empty body. pass it as argv[2], or "-" to read stdin.');
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fail(EXIT_USAGE, "recipient is not a valid address: {$to}");
}

// ── Bootstrap WordPress ──────────────────────────────────────────────────────
// Absolute path on purpose. __DIR__ here resolves through the /usr/local/bin
// symlink into the monorepo, where wp-load.php does not live — that trap has
// broken symlink-deployed scripts on this box before.
$wpLoad = getenv('LG_WP_LOAD') ?: '/var/www/dev/wp-load.php';
if (!is_readable($wpLoad)) {
    fail(EXIT_NO_WP, "cannot read {$wpLoad} (run as root — the docroot is owned by looth-dev)");
}
define('WP_USE_THEMES', false);

// wp-config.php defines DISABLE_WP_CRON twice, so bootstrapping emits two
// "Constant already defined" warnings on stderr. They are pre-existing noise
// from a file this tool must not edit — but a notifier that things get WIRED to
// must not print warnings to stderr on a successful run, or every caller has to
// guess whether it worked. Suppress display for the bootstrap only, then restore
// so any genuine problem in our own code below is still visible.
// NOTE: display_errors alone is NOT enough. On CLI these warnings reach stderr
// through log_errors (error_log unset => stderr), so suppressing only the
// display channel leaves them exactly where they were. Measured, not assumed.
$prevDisplay = ini_get('display_errors');
$prevLog     = ini_get('log_errors');
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once $wpLoad;
ini_set('display_errors', (string) $prevDisplay);
ini_set('log_errors', (string) $prevLog);

if (!function_exists('fluentMailGetSettings')) {
    fail(EXIT_NO_SETTINGS, 'fluentMailGetSettings() is unavailable — is FluentSMTP active?');
}
if (!function_exists('fluentMailSesConnection')) {
    fail(EXIT_NO_SETTINGS, 'fluentMailSesConnection() is unavailable — FluentSMTP layout changed.');
}

// ── Pick the SES connection EXPLICITLY ───────────────────────────────────────
// Deliberately NOT the "default connection": this box also has a mailpit SMTP
// connection configured, and the default is a settings value anyone can change
// in wp-admin. If the default ever flips to mailpit, a notifier that followed it
// would report success forever while delivering to a local sink — precisely the
// silent-success failure this tool exists to prevent. So we select by provider
// and refuse to send if there is no SES connection.
$settings = fluentMailGetSettings();
$ses      = null;
$sesId    = '';
foreach (($settings['connections'] ?? []) as $id => $conn) {
    $p = $conn['provider_settings'] ?? [];
    if (($p['provider'] ?? '') === 'ses') { $ses = $p; $sesId = (string) $id; break; }
}
if ($ses === null) {
    fail(EXIT_NO_SES, 'no SES connection in FluentSMTP settings. Refusing to fall back to any other provider (mailpit would swallow it and report success).');
}

$region = (string) ($ses['region'] ?? '');
$sender = (string) ($ses['sender_email'] ?? '');
$access = (string) ($ses['access_key'] ?? '');
$secret = (string) ($ses['secret_key'] ?? '');

if ($region === '' || $sender === '' || $access === '') {
    fail(EXIT_BAD_CREDS, "SES connection {$sesId} is incomplete (region/sender/access_key).");
}

// The banked trap, turned into a guard. FluentSMTP stores the secret ENCRYPTED:
// the raw wp_options value is ~208 chars, and fluentMailGetSettings() decrypts
// it to the real 40-char AWS secret. Signing with the raw value fails with
// SignatureDoesNotMatch — an error that reads like a credentials problem and
// sends you looking in the wrong place. If we ever get a non-40-char secret,
// say exactly that instead of letting SES produce the confusing error.
if (strlen($secret) !== 40) {
    fail(EXIT_BAD_CREDS, sprintf(
        'SES secret is %d chars, expected 40. ~208 means it is still ENCRYPTED — '
        . 'fluentMailGetSettings() decrypts it, so this indicates the helper did not run '
        . 'or the encryption keys changed. Signing with the raw value yields SignatureDoesNotMatch.',
        strlen($secret)
    ));
}

// ── Build and send ───────────────────────────────────────────────────────────
$driver = fluentMailSesConnection([
    'sender_email' => $sender,
    'access_key'   => $access,
    'secret_key'   => $secret,
    'region'       => $region,
]);

// Best-effort scrub of our local copies. Stated honestly: PHP gives no
// guarantee the bytes leave the heap (the driver holds its own copy, and the
// engine may keep interned copies), so this reduces incidental exposure — it is
// not a security boundary. The real control is that the secret is never written
// to a file, a commit, an env var or a log by this tool.
$secret = str_repeat("\0", strlen($secret));
unset($secret, $access);

$msgClass = '\FluentMail\App\Services\Mailer\Providers\AmazonSes\SimpleEmailServiceMessage';
if (!class_exists($msgClass)) {
    fail(EXIT_NO_SES, "SES message class not found ({$msgClass}) — FluentSMTP layout changed.");
}

$m = new $msgClass();
$m->setFrom($sender);
$m->addTo($to);
$m->setSubject($subject);
$m->setMessageFromString($body);

$res = $driver->sendEmail($m, false, true);

// ── Verify. Success is a MessageId, nothing else. ────────────────────────────
// sendEmail() returns ['MessageId'=>…,'RequestId'=>…] on success; false on a
// triggered error; and an array carrying 'error' on an unexpected HTTP status.
// Anything that is not a non-empty MessageId is a failure here, on purpose —
// "it didn't throw" is not delivery.
if (!is_array($res) || empty($res['MessageId'])) {
    $detail = 'no MessageId returned';
    if (is_array($res) && isset($res['error'])) {
        $detail = 'SES error: ' . json_encode($res['error']);
    } elseif ($res === false) {
        $detail = 'SES client returned false (message failed validation, or a transport/API error was triggered)';
    } elseif (is_object($res)) {
        $detail = 'SES returned a raw response object: ' . json_encode(['code' => $res->code ?? null, 'error' => $res->error ?? null]);
    }
    fail(EXIT_SEND_FAILED, "send NOT accepted — {$detail}");
}

fwrite(STDOUT, "keeper-notify: ACCEPTED by SES\n");
fwrite(STDOUT, "  to        : {$to}\n");
fwrite(STDOUT, "  from      : {$sender} (region {$region})\n");
fwrite(STDOUT, "  subject   : {$subject}\n");
fwrite(STDOUT, "  MessageId : {$res['MessageId']}\n");
exit(EXIT_OK);
