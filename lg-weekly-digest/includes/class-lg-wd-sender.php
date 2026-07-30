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
        $nonmember  = (string) ( $settings['fcrm_nonmember_list_id'] ?? 7 );
        $tag        = $settings['fcrm_tag'] ?? 'all';
        $from_name  = $settings['from_name'];
        $from_email = $settings['from_email'];

        // FluentCRM requires both 'list' and 'tag' keys; use 'all' to skip filtering
        $tag_value = ( empty( $tag ) ) ? 'all' : $tag;

        /**
         * TWO LISTS, ONE EMAIL (Ian, 2026-07-29 backlog + his 2026-07-30 ruling).
         *
         * List 3 is the members' Weekly News Letter. List 7 is "Non Member Weekly
         * Email Subscriber" — the store the PUBLIC SIGNUP PAGE writes to, and the
         * only list that page ever writes.
         *
         * Ian's model, in his words: the email announces this week's public content
         * to everyone on the list, and non-members are on the list BECAUSE THE
         * ANNOUNCEMENT IS FOR THEM. A digest that reads only list 3 would leave the
         * signup page collecting addresses that are never mailed — a page that lies.
         *
         * The two entries are an OR: FluentCRM resolves each {list,tag} pair and
         * unions the ids. A contact on both lists resolves once (the ids are
         * de-duplicated downstream by recipients_with_something_waiting(), which
         * array_unique()s its input) — and by Ian's ruling 6 no member can be on
         * list 7 through the signup page anyway.
         *
         * Setting-driven so a box can point at different list ids, but defaulting to
         * the real ones rather than to "off": a digest that silently stops mailing
         * non-members because a setting is unset is the failure this is meant to fix.
         */
        $audience = [ [ 'list' => $list_id, 'tag' => $tag_value ] ];
        if ( $nonmember !== '' && $nonmember !== '0' && $nonmember !== $list_id ) {
            $audience[] = [ 'list' => $nonmember, 'tag' => $tag_value ];
        }

        $subscriber_settings = [
            'subscribers'    => $audience,
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
            /**
             * DE-DUPLICATE HERE, NOT AS A SIDE EFFECT SOMEWHERE ELSE.
             *
             * The audience is now TWO {list,tag} pairs (members + non-members), and
             * 9 live contacts are subscribed to BOTH list 3 and list 7 — measured
             * 2026-07-30. If a duplicate id survives, that contact gets the digest
             * twice.
             *
             * Until now the only de-duplication we owned was the array_unique()
             * inside recipients_with_something_waiting(), which exists for an
             * entirely different reason (Ian's empty-means-no-send filter) and sits
             * behind a class_exists() guard. That made double-send protection a
             * SIDE EFFECT of a suppression rule that is itself under active question
             * (WEEKLY-DIGEST-RECAP §10.4 — Ian's 07-30 "everyone on the list" ruling
             * points the other way). Retire that filter and 9 people start getting
             * two copies, silently, with nothing in the diff to say so.
             *
             * So the ids are made unique at the point they are resolved, where the
             * duplication is actually created. Behaviour-neutral today — it is a
             * no-op on an already-unique list — and it stops being load-bearing on a
             * ruling that has nothing to do with it.
             *
             * NOT PROVEN: whether FluentCRM already unions these internally. I did
             * not read its resolver. This does not depend on the answer.
             */
            $subscriber_ids = array_values( array_unique( array_map(
                'intval', $result['subscriber_ids'] ?? [] ) ) );
        } catch ( \Exception $e ) {
            self::log( 'ERROR: getSubscriberIdsBySegmentSettings threw: ' . $e->getMessage() );
            return [ 'success' => false, 'message' => 'Subscriber resolution failed.', 'campaign_id' => $campaign->id ];
        }

        if ( empty( $subscriber_ids ) ) {
            self::log( 'WARNING: Zero subscribers resolved for lists ' . implode( '+', array_column( $audience, 'list' ) ) . '.' );
            return [ 'success' => false, 'message' => 'No subscribers resolved.', 'campaign_id' => $campaign->id ];
        }

        self::log( 'INFO: Resolved ' . count( $subscriber_ids ) . ' subscribers.' );

        // ── EMPTY MEANS SEND NOTHING (Ian, 2026-07-28) ────────────────────────
        //
        // Drop recipients with nothing waiting on them BEFORE the CampaignEmail rows
        // exist, so they are never mailed rather than mailed-and-empty. This is the
        // one place the recipient set is decided.
        //
        // SCOPE WORTH SEEING, because it is larger than the recap: this suppresses
        // the WHOLE email, including the curated sections (Upcoming Events, new
        // videos, loothprint) that have nothing to do with the member's to-do list.
        // RE-MEASURED ON LIVE 2026-07-30, and the earlier figure here was WRONG in a
        // way worth naming: it predated account-less subscribers being kept.
        //
        //   recipient set (lists 3 + 7, subscribed)   1,859
        //     account-less — ALWAYS KEPT                314
        //     members — subject to Rule 5             1,545
        //       with something waiting                   159
        //   RECEIVES the next send                       473
        //   RECEIVES NOTHING                           1,386   (74.6%)
        //
        // The old comment said "96 of 1,663, ~94% get nothing". Both numbers came from
        // before the account-less branch below, which keeps all 314 unconditionally, so
        // every pre-07-30 figure UNDERSTATES who still gets mail. Do not quote them.
        //
        // That is what the ruling says; it is flagged to keeper with the numbers
        // because it changes what the weekly digest IS, not just who it greets.
        if ( class_exists( 'LG_WD_Recap_Source' ) ) {
            $before         = count( $subscriber_ids );
            $subscriber_ids = LG_WD_Recap_Source::recipients_with_something_waiting( $subscriber_ids );
            self::log( sprintf(
                'INFO: to-do filter kept %d of %d subscribers (%d had nothing waiting).',
                count( $subscriber_ids ), $before, $before - count( $subscriber_ids )
            ) );

            if ( empty( $subscriber_ids ) ) {
                // Nobody on the whole list has anything waiting. Send no campaign at
                // all rather than one with zero recipients — an empty campaign row is
                // a confusing artifact in Send History and in FluentCRM.
                self::log( 'INFO: nobody has anything waiting — no digest sent this week.' );
                return [
                    'success'     => true,
                    'message'     => 'No digest sent: no member had an item needing attention.',
                    'campaign_id' => $campaign->id,
                    'sent'        => 0,
                ];
            }
        }

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

        $subject = LG_WD_Email_Builder::build_subject( $payload );

        /**
         * How the per-member "Your week" token is resolved differs per path, and
         * getting it wrong is user-visible, so it is decided explicitly here rather
         * than defaulted anywhere downstream:
         *
         *  - PREVIEW  → render the previewing admin's own recap, so the compose
         *               page shows a real section instead of a placeholder.
         *  - TEST     → render the test recipient's recap if that address belongs
         *               to a member; otherwise strip (no literal token in a test).
         *  - CAMPAIGN → keep the token. This is the ONLY path where FluentCRM gets
         *               to substitute per recipient.
         *  - wp_mail fallback → one body, one recipient: render for that member.
         */
        if ( $dry_run ) {
            $html = LG_WD_Email_Builder::build( $payload, [
                'mode'       => 'render',
                'wp_user_id' => get_current_user_id(),
            ] );
            return [ 'success' => true, 'message' => 'Preview ready.', 'html' => $html, 'subject' => $subject, 'campaign_id' => null ];
        }

        $sender = self::get_sender();

        // Test send
        if ( $test_email ) {
            $tester = get_user_by( 'email', $test_email );
            $html   = LG_WD_Email_Builder::build( $payload, [
                'mode'       => $tester ? 'render' : 'strip',
                'wp_user_id' => $tester ? (int) $tester->ID : 0,
            ] );
            return $sender->send_test( $test_email, $subject, $html );
        }

        // Full send
        $issue_title = get_the_title( $issue_id );

        if ( $sender instanceof LG_WD_Sender_FluentCRM ) {
            $html = LG_WD_Email_Builder::build( $payload, [ 'mode' => 'token' ] );
        } else {
            // wp_mail fallback: a single body to a single address, so the token has
            // to be resolved here — nothing downstream will do it. LG_WD_Sender_WPMail
            // takes its recipient from the same setting.
            $to     = (string) LG_WD_Settings::get( 'review_notify_email', '' );
            $member = $to ? get_user_by( 'email', $to ) : null;
            $html   = LG_WD_Email_Builder::build( $payload, [
                'mode'       => $member ? 'render' : 'strip',
                'wp_user_id' => $member ? (int) $member->ID : 0,
            ] );
        }

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
