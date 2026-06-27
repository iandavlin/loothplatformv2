<?php
/**
 * Plugin Name: LG Dev Mail Containment (dev2 ONLY)
 * @lg-dev-only EXCLUDED from live deploy (deploy.sh marker filter). Forces mail→mailpit; env-inert on live but must not ship.
 * Description: Hard guarantee that on the dev box NO outbound email can reach a
 *   real inbox. Intercepts wp_mail BEFORE FluentSMTP can route it to Amazon SES
 *   (SES rides HTTPS:443 and is therefore NOT blocked by the outbound-SMTP
 *   iptables cap on 25/465/587) and instead delivers a copy to the local mailpit
 *   catcher via the sendmail shim. Code-level, so it survives DB reloads that
 *   wipe FluentSMTP's `simulate_emails` flag (the exact gap behind the
 *   2026-06-25 incident, where a notify alert escaped to a real Gmail during a
 *   window when simulate_emails was momentarily OFF).
 *
 *   - Runs AFTER lg-poller-mail-killswitch (priority 99) so that plugin's bulk
 *     suppression (returns false at pri 10) is still honored.
 *   - Inert on a positively-identified LIVE box (LG_ENV=live/prod/production)
 *     so real delivery is preserved there. Fail-safe: if the env is unknown it
 *     CONTAINS rather than risk a leak.
 *
 * Incident: 2026-06-25. Revert = delete this file.
 */
if (!defined('ABSPATH')) return;

(function () {
    // --- Environment gate -------------------------------------------------
    // Active everywhere EXCEPT a box that positively identifies as live/prod.
    $env = '';
    if (is_readable('/etc/looth/env')) {
        foreach (file('/etc/looth/env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strpos($line, 'LG_ENV=') === 0) { $env = strtolower(trim(substr($line, 7))); break; }
        }
    }
    if ($env === 'live' || $env === 'prod' || $env === 'production') {
        return; // LIVE: leave real SES delivery untouched.
    }

    // --- Belt: force FluentSMTP into simulate at the CODE level ------------
    // If the pre_wp_mail short-circuit below is ever bypassed, FluentSMTP will
    // still pick its Simulator handler (drop) instead of the SES connection,
    // regardless of what the (DB-stored) simulate_emails option says.
    if (!defined('FLUENTMAIL_SIMULATE_EMAILS')) {
        define('FLUENTMAIL_SIMULATE_EMAILS', true);
    }

    // --- Primary guard: short-circuit wp_mail -> mailpit only -------------
    add_filter('pre_wp_mail', function ($short, $atts) {
        // Respect a prior decision: the poller killswitch returns false to
        // suppress bulk mail. Only act when nothing upstream has decided.
        if ($short !== null) {
            return $short;
        }

        $to      = isset($atts['to'])      ? $atts['to']      : '';
        $subject = isset($atts['subject']) ? (string) $atts['subject'] : '';
        $message = isset($atts['message']) ? $atts['message'] : '';
        $headers = isset($atts['headers']) ? $atts['headers'] : '';

        $to_str = is_array($to) ? implode(', ', $to) : (string) $to;
        if (is_array($message)) { $message = implode("\n", $message); }
        $hdr_str = is_array($headers) ? implode("\r\n", $headers) : (string) $headers;

        // Stamp containment so it is unmistakable in mailpit and the log that
        // this mail went through the dev sink and NOT through SES.
        $hdr_str = rtrim($hdr_str);
        $hdr_str .= ($hdr_str !== '' ? "\r\n" : '') . 'X-LG-Dev-Contained: mailpit';

        // sendmail_path => /usr/sbin/sendmail -t -i => lg-sendmail =>
        // `mailpit sendmail -S 127.0.0.1:1025`. Local-only sink; combined with
        // the outbound-SMTP iptables cap nothing can leave the box this way.
        $ok = false;
        try {
            $ok = @mail($to_str, $subject, (string) $message, $hdr_str);
        } catch (\Throwable $e) {
            $ok = false;
        }
        error_log('LG dev-mail-containment: captured to mailpit (to=' . $to_str
            . ', subj=' . $subject . ', ok=' . ($ok ? '1' : '0') . ')');

        // ALWAYS return true: even if the mailpit handoff failed we must not
        // fall through to FluentSMTP/SES. Containment beats delivery on dev.
        return true;
    }, 99, 2);
})();
