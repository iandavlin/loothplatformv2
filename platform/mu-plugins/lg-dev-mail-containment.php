<?php
/**
 * Plugin Name: LG Dev Mail Containment (mailpit redirect)
 * Description: DEV-ONLY. Short-circuits pre_wp_mail (priority 1) so FluentSMTP / SES /
 *   FluentCRM / the weekly digest NEVER reach the internet from a dev box — but instead of
 *   DROPPING the mail (the old lg-dev-mail-block.php "first-strike" behaviour, which returned
 *   true and told the caller it had sent), it DELIVERS to the local mailpit catcher so the
 *   message is readable at /mailpit/. Zero egress, full visibility.
 *
 *   WHY: dev2 was rebuilt from the live AMI (2026-07-04) and mailpit was never reinstalled, so
 *   the blocker was swallowing every send into a catcher that did not exist. Ian lost a morning
 *   to "the weekly digest and FluentCRM test emails don't arrive" — they were being blocked and
 *   reported as SUCCESS (2026-07-13).
 *
 *   LIVE SAFETY: gated on the PUBLIC HOST, not on LG_ENV. LG_ENV is a LAYOUT key — live was
 *   cloned from dev2's image and reports LG_ENV=dev2 too, BY DESIGN (bb-mirror/config.php:36-44,
 *   lg-shared/lg-env.php:20-31), so "LG_ENV says dev2" has never meant "this is a dev box".
 *   Containment now requires an AFFIRMATIVE dev-host match and is a proven no-op on live even if
 *   this file is symlinked there by accident. If the host cannot be determined, it does NOT
 *   contain. Proof harness: tools/mail-safety/run-liveness-test.sh (backlog #15, 2026-07-31).
 */
if (!defined('ABSPATH')) { exit; }

(function () {
    $envRaw = is_readable('/etc/looth/env') ? (string) @file_get_contents('/etc/looth/env') : '';

    // ---- 1. LAYOUT check (cheap first filter, NOT a safety property) -------
    // LG_ENV answers "which filesystem/DB layout does this box use?" — live
    // answers 'dev2' to that question exactly like dev2 does. Passing this test
    // means nothing on its own; the host check below is what actually decides.
    $env = '';
    if ($envRaw !== '' && preg_match('/^\s*LG_ENV\s*=\s*"?([a-z0-9_-]+)"?/mi', $envRaw, $m)) {
        $env = strtolower($m[1]);
    }
    if ($env === '' && defined('LG_ENV'))              { $env = strtolower((string) LG_ENV); }
    if ($env === '' && function_exists('wp_get_environment_type')) { $env = strtolower(wp_get_environment_type()); }

    if (!in_array($env, ['dev', 'dev2', 'development', 'staging'], true)) { return; }

    // ---- 2. SAFETY check: what host does this box actually serve? ----------
    // LG_PUBLIC_HOST in the root-owned /etc/looth/env is AUTHORITATIVE — it is
    // the one key whose value genuinely differs between dev2 and live. A request
    // header is attacker-controllable, so it is consulted ONLY when the box
    // declares no host of its own; otherwise a forged `Host: loothgroup.com`
    // could switch dev containment OFF and let a dev box mail real members.
    $host = '';
    if ($envRaw !== '' && preg_match('/^\s*LG_PUBLIC_HOST\s*=\s*"?([^"\r\n\s]+)"?/mi', $envRaw, $m)) {
        $host = $m[1];
    }
    if ($host === '' && !empty($_SERVER['HTTP_HOST'])) { $host = (string) $_SERVER['HTTP_HOST']; }
    if ($host === '' && defined('LG_PUBLIC_HOST'))     { $host = (string) LG_PUBLIC_HOST; }

    $host = rtrim(preg_replace('/:\d+$/', '', strtolower(trim($host))), '.');

    // FAIL SAFE: if we cannot PROVE this is a dev box, do not contain — send
    // normally. Swallowing real member mail while returning true to the caller
    // is a silent outage nobody can see; a dev box sending for real (or into a
    // mailpit that isn't there) is loud, bounded and recoverable. So containment
    // requires an AFFIRMATIVE dev-host match: unknown host => deliver.
    if ($host === '') { return; }

    // Explicit live denial first, so the intent stays legible even if the
    // allow-list below is ever widened carelessly.
    if (in_array($host, ['loothgroup.com', 'www.loothgroup.com'], true)) { return; }

    $isDevHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
              || (bool) preg_match('/^dev\d*\.loothgroup\.com$/', $host);
    if (!$isDevHost) { return; }

    // ---- 3. Proven dev box — containment is safe from here down. -----------
    // Belt (code-level): FluentSMTP must never hand anything to SES from here.
    // This define sits BELOW the host check on purpose: on live it would make
    // FluentSMTP simulate EVERY send site-wide — a total mail outage on its own,
    // even if the pre_wp_mail filter below were deleted.
    if (!defined('FLUENTMAIL_SIMULATE_EMAILS')) { define('FLUENTMAIL_SIMULATE_EMAILS', true); }

    add_filter('pre_wp_mail', function ($null, $atts) {
        $to      = $atts['to']      ?? '';
        $subject = (string) ($atts['subject'] ?? '(no subject)');
        $message = (string) ($atts['message'] ?? '');
        $headers = $atts['headers'] ?? '';
        $tos     = is_array($to) ? $to : array_filter(array_map('trim', explode(',', (string) $to)));

        $flat    = is_array($headers) ? implode("\n", $headers) : (string) $headers;
        $isHtml  = stripos($flat, 'text/html') !== false;

        $ok = false;
        try {
            if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
                require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
                require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
            }
            $m = new \PHPMailer\PHPMailer\PHPMailer(true);
            $m->isSMTP();
            $m->Host       = '127.0.0.1';
            $m->Port       = 1025;          // mailpit SMTP
            $m->SMTPAuth   = false;
            $m->SMTPAutoTLS = false;
            $m->Timeout    = 5;
            $m->CharSet    = 'UTF-8';
            $m->setFrom('dev2@dev2.loothgroup.com', 'Looth dev2 (contained)');
            foreach ($tos as $addr) {
                if (preg_match('/<([^>]+)>/', $addr, $mm)) { $addr = $mm[1]; }   // "Name <a@b>" → a@b
                if (is_email($addr)) { $m->addAddress($addr); }
            }
            $m->Subject = $subject;
            $m->Body    = $message;
            if ($isHtml) { $m->isHTML(true); }
            $m->addCustomHeader('X-LG-Dev-Contained', 'mailpit');
            $ok = $m->send();
        } catch (\Throwable $e) {
            error_log('LG dev mail containment: mailpit delivery failed — ' . $e->getMessage());
        }

        $line = sprintf(
            "[%s] %s to=%s subj=%s\n",
            gmdate('c'),
            $ok ? 'MAILPIT' : 'DROPPED(mailpit unreachable)',
            is_array($to) ? implode(',', $to) : (string) $to,
            $subject
        );
        @file_put_contents(WP_CONTENT_DIR . '/dev-mail-blocked.log', $line, FILE_APPEND | LOCK_EX);

        return true;   // never fall through to FluentSMTP/SES
    }, 1, 2);
})();
