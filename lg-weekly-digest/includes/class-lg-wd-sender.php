<?php
defined( 'ABSPATH' ) || exit;

/**
 * LG_WD_Sender_Interface
 * All senders must implement this.
 */
interface LG_WD_Sender_Interface {
    /**
     * Send the email to the full subscriber list.
     * @return array [ 'success' => bool, 'message' => string, 'campaign_id' => int|null ]
     */
    public function send( string $subject, string $html, array $options = [] ): array;

    /**
     * Send a test email to a single address.
     * @return array [ 'success' => bool, 'message' => string ]
     */
    public function send_test( string $to_email, string $subject, string $html ): array;

    /**
     * Human-readable label for this sender.
     */
    public function get_label(): string;
}

// ── FluentCRM implementation ────────────────────────────────────────────────

class LG_WD_Sender_FluentCRM implements LG_WD_Sender_Interface {

    public function get_label(): string {
        return 'FluentCRM';
    }

    public function send( string $subject, string $html, array $options = [] ): array {
        if ( ! class_exists( 'FluentCrm\App\Models\Campaign' ) ) {
            self::log( 'ERROR: FluentCRM Campaign model not found.' );
            return [ 'success' => false, 'message' => 'FluentCRM not available.', 'campaign_id' => null ];
        }

        $settings   = LG_WD_Settings::get_all();
        $list_id    = (string) ( $settings['fcrm_list_id'] ?? 3 );
        $tag        = $settings['fcrm_tag'] ?? 'all';
        $from_name  = $settings['from_name'];
        $from_email = $settings['from_email'];

        // FluentCRM requires both 'list' and 'tag' keys; use 'all' to skip filtering
        $tag_value = ( empty( $tag ) ) ? 'all' : $tag;

        $subscriber_settings = [
            'subscribers' => [
                [ 'list' => $list_id, 'tag' => $tag_value ],
            ],
            'sending_filter' => 'list_tag',
        ];

        $scheduled_at = current_time( 'mysql' );

        $campaign_data = [
            'title'         => $options['campaign_title'] ?? ( 'Weekly Digest — ' . date_i18n( 'F j, Y' ) ),
            'email_subject' => $subject,
            'type'            => 'campaign',
            'status'          => 'draft',
            'template_id'     => 0,
            'design_template' => 'raw_html',
            'email_body'      => $html,
            'settings'      => [
                'mailer_settings' => [
                    'from_name'  => $from_name,
                    'from_email' => $from_email,
                    'reply_to'   => $from_email,
                    'is_custom'  => 'yes',
                ],
                'subscribers'      => $subscriber_settings['subscribers'],
                'sending_filter'   => 'list_tag',
                'excludedSubscribers' => [],
                'template_config'  => [
                    'content_width'    => '600',
                    'body_bg_color'    => '#e8e2d8',
                    'content_bg_color' => '#FAF6EE',
                    'content_font'     => 'Arial, Helvetica, sans-serif',
                    'footer_text_color'=> '#5C4E3A',
                    'disable_footer'   => 'yes',
                ],
            ],
            'scheduled_at'  => $scheduled_at,
        ];

        self::log( 'INFO: Creating FluentCRM campaign: ' . $campaign_data['title'] );

        try {
            $campaign = \FluentCrm\App\Models\Campaign::create( $campaign_data );
        } catch ( \Exception $e ) {
            self::log( 'ERROR: Campaign::create() threw: ' . $e->getMessage() );
            return [ 'success' => false, 'message' => 'Campaign creation failed: ' . $e->getMessage(), 'campaign_id' => null ];
        }

        if ( ! $campaign || ! $campaign->id ) {
            self::log( 'ERROR: Campaign::create() returned empty/null.' );
            return [ 'success' => false, 'message' => 'Campaign creation returned null.', 'campaign_id' => null ];
        }

        self::log( "INFO: Campaign created. ID={$campaign->id}" );

        // Resolve subscriber IDs
        try {
            $result         = $campaign->getSubscriberIdsBySegmentSettings( $subscriber_settings );
            $subscriber_ids = $result['subscriber_ids'] ?? [];
        } catch ( \Exception $e ) {
            self::log( 'ERROR: getSubscriberIdsBySegmentSettings threw: ' . $e->getMessage() );
            return [ 'success' => false, 'message' => 'Subscriber resolution failed.', 'campaign_id' => $campaign->id ];
        }

        if ( empty( $subscriber_ids ) ) {
            self::log( "WARNING: Zero subscribers resolved for List ID {$list_id}." );
            return [ 'success' => false, 'message' => 'No subscribers resolved.', 'campaign_id' => $campaign->id ];
        }

        self::log( 'INFO: Resolved ' . count( $subscriber_ids ) . ' subscribers.' );

        // Create CampaignEmail rows
        try {
            $campaign->subscribe( $subscriber_ids );
        } catch ( \Exception $e ) {
            self::log( 'ERROR: subscribe() threw: ' . $e->getMessage() );
            return [ 'success' => false, 'message' => 'subscribe() failed.', 'campaign_id' => $campaign->id ];
        }

        // Verify
        $email_count = \FluentCrm\App\Models\CampaignEmail::where( 'campaign_id', $campaign->id )->count();
        self::log( "INFO: CampaignEmail rows created: {$email_count}" );

        if ( $email_count === 0 ) {
            self::log( 'ERROR: CampaignEmail rows = 0 after subscribe().' );
            return [ 'success' => false, 'message' => 'CampaignEmail rows not created.', 'campaign_id' => $campaign->id ];
        }

        $campaign->status = 'draft';
        $campaign->save();

        self::log( "SUCCESS: Campaign ID={$campaign->id} ready for review with {$email_count} subscribers." );

        return [
            'success'     => true,
            'message'     => "Campaign created with {$email_count} subscribers. Review and send from FluentCRM.",
            'campaign_id' => $campaign->id,
        ];
    }

    public function send_test( string $to_email, string $subject, string $html ): array {
        self::log( "INFO: Sending test email to {$to_email}" );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . LG_WD_Settings::get( 'from_name' ) . ' <' . LG_WD_Settings::get( 'from_email' ) . '>',
        ];

        $sent = wp_mail( $to_email, '[TEST] ' . $subject, $html, $headers );

        if ( $sent ) {
            self::log( "INFO: Test email sent to {$to_email}" );
            return [ 'success' => true, 'message' => "Test sent to {$to_email}." ];
        }

        self::log( "ERROR: wp_mail() failed for {$to_email}" );
        return [ 'success' => false, 'message' => 'wp_mail() failed.' ];
    }

    private static function log( string $message ): void {
        $always = str_starts_with( $message, 'ERROR' ) || str_starts_with( $message, 'WARNING' ) || str_starts_with( $message, 'SUCCESS' );
        if ( $always || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
            error_log( '[LG Weekly Digest] ' . $message );
        }
    }
}

// ── wp_mail implementation ──────────────────────────────────────────────────

class LG_WD_Sender_WPMail implements LG_WD_Sender_Interface {

    public function get_label(): string {
        return 'WordPress Mail';
    }

    public function send( string $subject, string $html, array $options = [] ): array {
        $to = $options['to'] ?? '';
        if ( empty( $to ) ) {
            return [ 'success' => false, 'message' => 'No recipient specified for wp_mail sender.', 'campaign_id' => null ];
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . LG_WD_Settings::get( 'from_name' ) . ' <' . LG_WD_Settings::get( 'from_email' ) . '>',
        ];

        $sent = wp_mail( $to, $subject, $html, $headers );

        return [
            'success'     => $sent,
            'message'     => $sent ? 'Email sent via wp_mail.' : 'wp_mail() failed.',
            'campaign_id' => null,
        ];
    }

    public function send_test( string $to_email, string $subject, string $html ): array {
        return $this->send( $subject, $html, [ 'to' => $to_email ] );
    }
}

// ── Factory ─────────────────────────────────────────────────────────────────

class LG_WD_Sender {

    /**
     * Get the active sender implementation.
     * Developers can override via the 'lg_wd_sender' filter.
     */
    public static function get_sender(): LG_WD_Sender_Interface {
        $sender = apply_filters( 'lg_wd_sender', null );
        if ( $sender instanceof LG_WD_Sender_Interface ) {
            return $sender;
        }

        // Default: FluentCRM if available, otherwise wp_mail
        if ( class_exists( 'FluentCrm\App\Models\Campaign' ) ) {
            error_log( '[LG Weekly Digest] INFO: Using FluentCRM sender.' );
            return new LG_WD_Sender_FluentCRM();
        }

        error_log( '[LG Weekly Digest] WARNING: FluentCRM not detected, falling back to wp_mail sender.' );
        return new LG_WD_Sender_WPMail();
    }

    /**
     * Convenience: send an issue.
     */
    public static function send_issue( int $issue_id, bool $dry_run = false, string $test_email = '' ): array {
        $issue_data = LG_WD_Issue::get_data( $issue_id );

        if ( empty( $issue_data['sections'] ) ) {
            return [ 'success' => false, 'message' => 'Issue has no sections.', 'campaign_id' => null ];
        }

        // Build payload from curated issue
        $payload = LG_WD_Query::build_payload_from_issue( $issue_data );

        if ( empty( $payload ) ) {
            return [ 'success' => false, 'message' => 'No content in issue.', 'campaign_id' => null ];
        }

        // Render HTML
        $html    = LG_WD_Email_Builder::build( $payload );
        $subject = LG_WD_Email_Builder::build_subject( $payload );

        if ( $dry_run ) {
            return [ 'success' => true, 'message' => 'Preview ready.', 'html' => $html, 'subject' => $subject, 'campaign_id' => null ];
        }

        $sender = self::get_sender();

        // Test send
        if ( $test_email ) {
            return $sender->send_test( $test_email, $subject, $html );
        }

        // Full send
        $issue_title = get_the_title( $issue_id );
        $result = $sender->send( $subject, $html, [ 'campaign_title' => $issue_title ] );

        if ( $result['success'] ) {
            LG_WD_Issue::mark_sent( $issue_id, $result['campaign_id'] ?? null );
            self::record_send( $result['campaign_id'] ?? null, $issue_title, $result['message'] );
        }

        return $result;
    }

    // ── Send history ─────────────────────────────────────────────────────────

    private static function record_send( ?int $campaign_id, string $title, string $message ): void {
        $history   = get_option( 'lg_wd_send_history', [] );
        $history[] = [
            'campaign_id' => $campaign_id,
            'title'       => $title,
            'message'     => $message,
            'sent_at'     => current_time( 'mysql' ),
        ];
        // Keep last 50
        if ( count( $history ) > 50 ) {
            $history = array_slice( $history, -50 );
        }
        update_option( 'lg_wd_send_history', $history, false );
    }
}
