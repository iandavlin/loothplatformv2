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
 *   LIVE SAFETY: hard-gated on LG_ENV. If the environment is live/prod (or unknown), this file
 *   does nothing at all and normal delivery proceeds. It is safe to ship everywhere.
 */
if (!defined('ABSPATH')) { exit; }

(function () {
    // Resolve environment: /etc/looth/env wins, then LG_ENV constant, then WP_ENVIRONMENT_TYPE.
    $env = '';
    if (is_readable('/etc/looth/env')) {
        $raw = (string) @file_get_contents('/etc/looth/env');
        if (preg_match('/^\s*LG_ENV\s*=\s*"?([a-z0-9_-]+)"?/mi', $raw, $m)) { $env = strtolower($m[1]); }
    }
    if ($env === '' && defined('LG_ENV'))              { $env = strtolower((string) LG_ENV); }
    if ($env === '' && function_exists('wp_get_environment_type')) { $env = strtolower(wp_get_environment_type()); }

    // FAIL SAFE: only contain when we are CERTAIN this is a dev box. On live/prod/unknown → no-op.
    $isDev = in_array($env, ['dev', 'dev2', 'development', 'staging'], true);
    if (!$isDev) { return; }

    // Belt (code-level): FluentSMTP must never hand anything to SES from here.
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
