<?php
/**
 * Plugin Name: LG Anonymous Authors
 * Description: Anonymizes member display names, avatars, and modification bylines for logged-out viewers — scoped to bbPress forums, forum activity items, and group activity items only. Regular posts (blog, sponsor, LoothPrints) always show real authors. Whitelisted members always show real name + avatar even in anon-scoped contexts.
 * Version: 2.3.0
 * Author: Ian Davlin LLC
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LG_Anonymous_Authors {

    private const WHITELIST_OPTION = 'lg_anon_authors_whitelist';

    /**
     * Components whose activity feed entries should be anonymized for logged-out viewers.
     * bbpress = forum topics and replies in the activity feed
     * groups  = posts/updates made inside a BuddyBoss group
     */
    private const ANON_ACTIVITY_COMPONENTS = [ 'bbpress', 'groups' ];

    public function __construct() {
        // --- bbPress: author display names (topic + reply) ---
        add_filter( 'bbp_get_reply_author_display_name', [ $this, 'anon_name' ], 10, 2 );
        add_filter( 'bbp_get_topic_author_display_name', [ $this, 'anon_name' ], 10, 2 );

        // --- bbPress: author links ---
        add_filter( 'bbp_get_reply_author_link', [ $this, 'anon_link' ], 10, 2 );
        add_filter( 'bbp_get_topic_author_link', [ $this, 'anon_link' ], 10, 2 );

        // --- bbPress: avatars ---
        add_filter( 'bbp_get_reply_author_avatar', [ $this, 'anon_avatar' ], 10, 2 );
        add_filter( 'bbp_get_topic_author_avatar', [ $this, 'anon_avatar' ], 10, 2 );

        // --- bbPress: "This reply was modified X ago by [user]" line ---
        add_filter( 'bbp_get_reply_last_active', [ $this, 'anon_last_active_string' ] );
        add_filter( 'bbp_get_topic_last_active', [ $this, 'anon_last_active_string' ] );

        // --- BuddyBoss activity feed: only for bbPress + groups components ---
        add_filter( 'bp_get_activity_action', [ $this, 'anon_activity_action' ], 10, 2 );
        add_filter( 'bp_get_activity_avatar', [ $this, 'anon_activity_avatar' ] );
        add_filter( 'bp_get_activity_user_link', [ $this, 'anon_activity_user_link' ] );

        // --- Admin whitelist UI ---
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'admin_init', [ $this, 'save_whitelist' ] );
    }

    // ------------------------------------------------------------------
    // Auth check
    // ------------------------------------------------------------------

    private function is_authenticated(): bool {
        return is_user_logged_in();
    }

    // ------------------------------------------------------------------
    // Whitelist helpers
    // ------------------------------------------------------------------

    private function get_whitelist(): array {
        return (array) get_option( self::WHITELIST_OPTION, [] );
    }

    private function is_whitelisted( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        return in_array( $user_id, array_map( 'intval', $this->get_whitelist() ), true );
    }

    // ------------------------------------------------------------------
    // Anon helpers
    // ------------------------------------------------------------------

    private function anon_label(): string {
        return 'Private Member';
    }

    private function get_anon_avatar(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50" viewBox="0 0 50 50" style="border-radius:50%;background:#e0e0e0;display:inline-block;vertical-align:middle;">'
             . '<circle cx="25" cy="19" r="10" fill="#aaa"/>'
             . '<ellipse cx="25" cy="44" rx="16" ry="10" fill="#aaa"/>'
             . '</svg>';
    }

    /**
     * Check if the current activity item in the BP loop is anon-eligible.
     * Reads the global $activities_template when no $activity is passed in.
     */
    private function current_activity_is_anon_scope( $activity = null ): bool {
        if ( ! $activity ) {
            global $activities_template;
            if ( empty( $activities_template->activity ) ) return false;
            $activity = $activities_template->activity;
        }
        if ( empty( $activity->component ) ) return false;
        return in_array( $activity->component, self::ANON_ACTIVITY_COMPONENTS, true );
    }

    /**
     * Get the user ID of the current activity item in the BP loop.
     */
    private function current_activity_user_id( $activity = null ): int {
        if ( ! $activity ) {
            global $activities_template;
            if ( empty( $activities_template->activity ) ) return 0;
            $activity = $activities_template->activity;
        }
        return (int) ( $activity->user_id ?? 0 );
    }

    // ------------------------------------------------------------------
    // bbPress filters
    // ------------------------------------------------------------------

    public function anon_name( $name, $post_id ) {
        if ( $this->is_authenticated() ) return $name;
        $user_id = (int) ( bbp_get_reply_author_id( $post_id ) ?: bbp_get_topic_author_id( $post_id ) );
        if ( $this->is_whitelisted( $user_id ) ) return $name;
        return $this->anon_label();
    }

    public function anon_link( $link, $args ) {
        if ( $this->is_authenticated() ) return $link;
        $post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
        $user_id = (int) ( bbp_get_reply_author_id( $post_id ) ?: bbp_get_topic_author_id( $post_id ) );
        if ( $this->is_whitelisted( $user_id ) ) return $link;
        return '<span class="lg-anon-author">' . esc_html( $this->anon_label() ) . '</span>';
    }

    public function anon_avatar( $avatar, $post_id ) {
        if ( $this->is_authenticated() ) return $avatar;
        $user_id = (int) ( bbp_get_reply_author_id( $post_id ) ?: bbp_get_topic_author_id( $post_id ) );
        if ( $this->is_whitelisted( $user_id ) ) return $avatar;
        return $this->get_anon_avatar();
    }

    /**
     * Strip the "by [username]" portion from the "This reply was modified X ago by [user]" line.
     * Leaves the timestamp intact. Works for both reply and topic last-active strings.
     */
    public function anon_last_active_string( $last_active ) {
        if ( $this->is_authenticated() ) return $last_active;
        $last_active = preg_replace( '/\s+by\s+<a[^>]*>.*?<\/a>/i', '', $last_active );
        $last_active = preg_replace( '/\s+by\s+\S+/i', '', $last_active );
        return $last_active;
    }

    // ------------------------------------------------------------------
    // BuddyBoss activity feed filters — scoped to bbpress + groups only
    // ------------------------------------------------------------------

    /**
     * Anonymize the action line ("John Smith posted in Forum Foo") for bbPress
     * and groups activity items. Leaves blog posts, sponsor posts, and LoothPrint
     * activity alone so real authors/avatars still render correctly.
     */
    public function anon_activity_action( $action, $activity ) {
        if ( $this->is_authenticated() ) return $action;
        if ( ! $this->current_activity_is_anon_scope( $activity ) ) return $action;
        $user_id = $this->current_activity_user_id( $activity );
        if ( $this->is_whitelisted( $user_id ) ) return $action;

        // Replace any author <a> (profile tooltip, bp-tooltip class, or /members/ URL) with anon span
        return preg_replace(
            '/<a[^>]+data-bp-tooltip[^>]*>.*?<\/a>|<a[^>]+class="[^"]*bp-tooltip[^"]*"[^>]*>.*?<\/a>|<a\s+href="[^"]*\/members\/[^"]*"[^>]*>.*?<\/a>/i',
            '<span class="lg-anon-author">' . esc_html( $this->anon_label() ) . '</span>',
            $action
        );
    }

    /**
     * Swap the activity avatar for bbPress + group items only.
     * Reads the current activity out of the BP loop global.
     */
    public function anon_activity_avatar( $avatar ) {
        if ( $this->is_authenticated() ) return $avatar;
        if ( ! $this->current_activity_is_anon_scope() ) return $avatar;
        $user_id = $this->current_activity_user_id();
        if ( $this->is_whitelisted( $user_id ) ) return $avatar;
        return $this->get_anon_avatar();
    }

    /**
     * Neutralize the profile URL on bbPress + group activity items so
     * logged-out visitors can't click through to a real member profile.
     */
    public function anon_activity_user_link( $url ) {
        if ( $this->is_authenticated() ) return $url;
        if ( ! $this->current_activity_is_anon_scope() ) return $url;
        $user_id = $this->current_activity_user_id();
        if ( $this->is_whitelisted( $user_id ) ) return $url;
        return '#';
    }

    // ------------------------------------------------------------------
    // Admin whitelist page
    // ------------------------------------------------------------------

    public function register_admin_page(): void {
        add_options_page(
            'LG Anonymous Authors',
            'Anon Authors Whitelist',
            'manage_options',
            'lg-anon-authors',
            [ $this, 'render_admin_page' ]
        );
    }

    public function save_whitelist(): void {
        if (
            ! isset( $_POST['lg_anon_nonce'] ) ||
            ! wp_verify_nonce( $_POST['lg_anon_nonce'], 'lg_anon_save' ) ||
            ! current_user_can( 'manage_options' )
        ) return;

        $raw = isset( $_POST['lg_anon_whitelist'] ) ? sanitize_textarea_field( $_POST['lg_anon_whitelist'] ) : '';
        $ids = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', $raw ) ) );
        update_option( self::WHITELIST_OPTION, array_values( $ids ) );

        add_settings_error( 'lg_anon_authors', 'saved', 'Whitelist saved.', 'updated' );
    }

    public function render_admin_page(): void {
        settings_errors( 'lg_anon_authors' );
        $whitelist = implode( "\n", $this->get_whitelist() );
        ?>
        <div class="wrap">
            <h1>LG Anonymous Authors — Whitelist</h1>
            <p>Enter one user ID per line (or comma-separated). Whitelisted users always display their real name and avatar to logged-out visitors.</p>
            <p><em>Scope reminder: this plugin anonymizes bbPress forum posts, forum activity feed items, and group activity items. Regular blog, sponsor, and LoothPrint posts are never anonymized regardless of whitelist.</em></p>
            <form method="post">
                <?php wp_nonce_field( 'lg_anon_save', 'lg_anon_nonce' ); ?>
                <textarea name="lg_anon_whitelist" rows="10" cols="30" style="font-family:monospace;"><?php echo esc_textarea( $whitelist ); ?></textarea>
                <br><br>
                <?php submit_button( 'Save Whitelist' ); ?>
            </form>
        </div>
        <?php
    }
}

new LG_Anonymous_Authors();
