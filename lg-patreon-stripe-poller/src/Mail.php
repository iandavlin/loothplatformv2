<?php

declare(strict_types=1);

namespace LGMS;

/**
 * Hard mail kill-switch for the lg-patreon-stripe-poller plugin.
 *
 * EVERY outbound mail in this plugin routes through Mail::send(). Mail is
 * SUPPRESSED unless the constant LGMS_MAIL_ENABLED is BOTH defined AND truthy.
 * Default (constant undefined) = OFF. This does NOT depend on the global
 * FluentSMTP `simulate` flag — it is an independent, in-plugin gate so the
 * off-state survives any SMTP-layer reconfiguration.
 *
 * To re-enable sending, define( 'LGMS_MAIL_ENABLED', true ) (e.g. in
 * wp-config.php). Until then, sends are logged via error_log and dropped.
 */
final class Mail
{
    /**
     * Drop-in replacement for wp_mail() with a hard enable gate.
     *
     * @param string|string[] $to
     * @param string          $subject
     * @param string          $body
     * @param string|string[] $headers
     * @param string|string[] $attachments
     */
    public static function send( $to, string $subject, string $body, $headers = [], $attachments = [] ): bool
    {
        if ( ! ( defined( 'LGMS_MAIL_ENABLED' ) && LGMS_MAIL_ENABLED ) ) {
            error_log( '[LGMS] mail suppressed: ' . $subject );
            return false;
        }

        return wp_mail( $to, $subject, $body, $headers, $attachments );
    }
}
