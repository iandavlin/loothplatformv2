<?php
defined( 'ABSPATH' ) || exit;

/**
 * LG_WD_Settings
 * Global plugin configuration. Sections are now in CPT Registry + per-issue data.
 */
class LG_WD_Settings {

    private static array $defaults = [
        // Schedule
        'enabled'          => true,
        'send_day'         => 'monday',
        'send_time'        => '09:00',

        // Email design
        'header_image_url' => '',
        'from_name'        => 'The Looth Group',
        'from_email'       => 'hello@loothgroup.com',
        'subject_template' => 'The Looth Group — Week of {{week_date}}',
        'signoff'          => "Don't forget to try to have some fun. Did you really get into this stuff to perfect your frown?",

        // Display toggles
        'show_excerpts'    => true,
        'show_thumbnails'  => true,
        'skip_empty'       => true,

        // FluentCRM targeting
        'fcrm_list_id'        => 3,
        'fcrm_tag'            => 'all',

        // Cron behavior
        'cron_mode'           => 'auto_send',
        'review_notify_email' => '',

        // Email content
        'intro_text'          => "Here's what's been happening in the Looth Group community this week. Catch up on new content, events, and conversations from the forum.",
        'footer_links'        => '[]',
        'branding_tagline'    => 'Guitar Repair & Restoration Community',

        // UTM tracking
        'utm_enabled'         => false,
        'utm_source'          => 'weekly-digest',
        'utm_medium'          => 'email',
        'utm_campaign'        => '{{week_date}}',

        // Content fallback
        'fallback_enabled'    => true,
    ];

    public static function get_all(): array {
        $saved = get_option( LG_WD_OPTION_KEY, [] );
        $merged = wp_parse_args( $saved, self::$defaults );

        // Self-heal: strip lingering slashes from values saved before the wp_unslash fix.
        foreach ( [ 'intro_text', 'signoff', 'subject_template', 'branding_tagline' ] as $k ) {
            if ( is_string( $merged[ $k ] ) && str_contains( $merged[ $k ], '\\' ) ) {
                $merged[ $k ] = wp_unslash( $merged[ $k ] );
            }
        }

        return $merged;
    }

    public static function get( string $key, $fallback = null ) {
        $all = self::get_all();
        return $all[ $key ] ?? $fallback;
    }

    public static function save( array $data ): void {
        $current = self::get_all();

        $scalar_keys = array_keys( self::$defaults );

        foreach ( $scalar_keys as $k ) {
            if ( array_key_exists( $k, $data ) ) {
                $current[ $k ] = $data[ $k ];
            }
        }

        update_option( LG_WD_OPTION_KEY, $current, false );
    }
}
