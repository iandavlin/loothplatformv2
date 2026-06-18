<?php
/**
 * Plugin Name: LG Recent Posts Widget + Tier Badges
 * Description: Sidebar widget showing recent posts across all Looth Group CPTs with tier badges. Also injects tier badges into the BuddyBoss activity feed.
 * Version:     1.8.0
 * Author:      The Looth Group
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
   CPT registry — slug => [ label, accent color ]
--------------------------------------------------------------- */
define( 'LG_RPW_TYPES', [
    'post'             => [ 'Post',            '#5b7fa6' ],
    'post-imgcap'      => [ 'Article',         '#c8a951' ],
    'document'         => [ 'Document',        '#6a994e' ],
    'loothcuts'        => [ 'Loothcut',        '#e76f51' ],
    'loothprint'       => [ 'Loothprint',      '#9b5de5' ],
    'member-benefit'   => [ 'Member Benefit',  '#2a9d8f' ],
    'useful_links'     => [ 'Useful Link',     '#e9c46a' ],
    'post-type-videos' => [ 'Video',           '#e63946' ],
    'sponsor-page'     => [ 'Sponsor Page',    '#457b9d' ],
    'sponsor-post'     => [ 'Sponsor Post',    '#1d3557' ],
    'sponsor-product'  => [ 'Sponsor Product', '#a8dadc' ],
    'event'            => [ 'Event',           '#f4a261' ],
    'post-regular'     => [ 'Post',            '#5b7fa6' ],
    'shorty'           => [ 'Shorty',          '#48cae4' ],
] );

define( 'LG_RPW_DEFAULTS', [
    'post', 'post-imgcap', 'document', 'loothcuts',
    'loothprint', 'member-benefit', 'useful_links', 'post-type-videos',
] );

/* ---------------------------------------------------------------
   Tier badge config — taxonomy term slug => [ label, bg, text ]
--------------------------------------------------------------- */
define( 'LG_TIER_BADGES', [
    'public'     => [ 'Public',     '#6c757d', '#fff' ],
    'looth-lite'  => [ 'Looth Lite',  '#2d6a9f', '#fff' ],
    'looth-pro'   => [ 'Looth Pro',   '#c8a951', '#1a1a1a' ],
    'looth-plus'  => [ 'Looth Plus',  '#7b2d8b', '#fff' ],
] );

/* ---------------------------------------------------------------
   Helper: get tier badge HTML for a post ID
   Returns '' if no tier term found (treat as public / untiered)
--------------------------------------------------------------- */
function lg_get_tier_badge( $post_id ) {
    $terms = get_the_terms( $post_id, 'tier' );

    // No tier taxonomy or no terms — show Public badge
    if ( empty( $terms ) || is_wp_error( $terms ) ) {
        [ $label, $bg, $color ] = [ 'Public', '#6c757d', '#fff' ];
        return sprintf(
            '<span class="lg-tier-badge lg-tier-public" style="background:%s;color:%s;">%s</span>',
            esc_attr( $bg ), esc_attr( $color ), esc_html( $label )
        );
    }

    // Use the first term found
    $term = $terms[0];
    $slug = $term->slug;

    if ( isset( LG_TIER_BADGES[ $slug ] ) ) {
        [ $label, $bg, $color ] = LG_TIER_BADGES[ $slug ];
    } else {
        // Fallback: use the term name as-is
        $label = $term->name;
        $bg    = '#888';
        $color = '#fff';
    }

    return sprintf(
        '<span class="lg-tier-badge lg-tier-%s" style="background:%s;color:%s;">%s</span>',
        esc_attr( sanitize_html_class( $slug ) ),
        esc_attr( $bg ),
        esc_attr( $color ),
        esc_html( $label )
    );
}

/* ---------------------------------------------------------------
   1. Register the widget
--------------------------------------------------------------- */
add_action( 'widgets_init', function () {
    register_widget( 'LG_Recent_Posts_Widget' );
} );

/* ---------------------------------------------------------------
   2. Widget class
--------------------------------------------------------------- */
class LG_Recent_Posts_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'lg_recent_posts_widget',
            __( 'LG Recent Posts (All CPTs)', 'lg-recent-posts' ),
            [ 'description' => __( 'Shows recent posts from selected Looth Group post types with tier badges.', 'lg-recent-posts' ) ]
        );
    }

    public function widget( $args, $instance ) {
        $title       = ! empty( $instance['title'] )       ? apply_filters( 'widget_title', $instance['title'] ) : 'Recent Posts';
        $count       = ! empty( $instance['count'] )       ? absint( $instance['count'] )       : 5;
        $post_types  = ! empty( $instance['post_types'] )  ? (array) $instance['post_types']    : LG_RPW_DEFAULTS;
        $show_type   = isset( $instance['show_type'] )     ? (bool) $instance['show_type']      : true;
        $show_date   = isset( $instance['show_date'] )     ? (bool) $instance['show_date']      : true;
        $show_thumb  = isset( $instance['show_thumb'] )    ? (bool) $instance['show_thumb']     : false;
        $show_tier   = isset( $instance['show_tier'] )     ? (bool) $instance['show_tier']      : true;

        $valid_types = array_values( array_filter( $post_types, 'post_type_exists' ) );

        echo $args['before_widget'];
        if ( $title ) echo $args['before_title'] . esc_html( $title ) . $args['after_title'];

        if ( empty( $valid_types ) ) {
            echo '<p class="lg-recent-cpt-empty">No post types selected.</p>';
            echo $args['after_widget'];
            return;
        }

        $query = new WP_Query( [
            'post_type'           => $valid_types,
            'posts_per_page'      => $count,
            'post_status'         => 'publish',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'orderby'             => 'date',
            'order'               => 'DESC',
        ] );

        if ( $query->have_posts() ) :
            echo '<ul class="lg-recent-cpt-list">';
            while ( $query->have_posts() ) :
                $query->the_post();
                $pt_slug   = get_post_type();
                $type_data = LG_RPW_TYPES[ $pt_slug ] ?? [ ucfirst( str_replace( ['-','_'], ' ', $pt_slug ) ), '#888' ];
                [$pt_label, $pt_color] = $type_data;
                ?>
                <li class="lg-recent-cpt-item lg-cpt--<?php echo sanitize_html_class( $pt_slug ); ?>">
                    <?php if ( $show_thumb && has_post_thumbnail() ) : ?>
                        <a href="<?php the_permalink(); ?>" class="lg-recent-cpt-thumb" tabindex="-1" aria-hidden="true">
                            <?php the_post_thumbnail( 'thumbnail' ); ?>
                        </a>
                    <?php endif; ?>
                    <div class="lg-recent-cpt-body">
                        <div class="lg-recent-cpt-meta">
                            <?php if ( $show_type ) : ?>
                                <span class="lg-recent-cpt-type" style="color:<?php echo esc_attr( $pt_color ); ?>">
                                    <?php echo esc_html( $pt_label ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <a href="<?php the_permalink(); ?>" class="lg-recent-cpt-title"><?php the_title(); ?></a>
                        <?php if ( $show_date ) : ?>
                            <time class="lg-recent-cpt-date" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
                                <?php echo esc_html( get_the_date() ); ?>
                            </time>
                        <?php endif; ?>
                    </div>
                </li>
                <?php
            endwhile;
            echo '</ul>';
            wp_reset_postdata();
        else :
            echo '<p class="lg-recent-cpt-empty">No recent posts found.</p>';
        endif;

        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $title      = $instance['title']      ?? 'Recent Posts';
        $count      = isset( $instance['count'] ) ? absint( $instance['count'] ) : 5;
        $post_types = $instance['post_types'] ?? LG_RPW_DEFAULTS;
        $show_type  = $instance['show_type']  ?? true;
        $show_date  = $instance['show_date']  ?? true;
        $show_thumb = $instance['show_thumb'] ?? false;
        $show_tier  = $instance['show_tier']  ?? true;
        ?>
        <p>
            <label for="<?php echo $this->get_field_id('title'); ?>"><strong>Title:</strong></label>
            <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>"
                   name="<?php echo $this->get_field_name('title'); ?>"
                   type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('count'); ?>"><strong>Number of posts:</strong></label>
            <input class="tiny-text" id="<?php echo $this->get_field_id('count'); ?>"
                   name="<?php echo $this->get_field_name('count'); ?>"
                   type="number" min="1" max="20" value="<?php echo esc_attr($count); ?>">
        </p>
        <p><strong>Post types to include:</strong></p>
        <div style="max-height:220px;overflow-y:auto;border:1px solid #ddd;padding:8px;border-radius:3px;background:#fafafa;">
        <?php foreach ( LG_RPW_TYPES as $pt_slug => [$pt_label, $pt_color] ) : ?>
            <p style="margin:4px 0;">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="checkbox"
                           name="<?php echo $this->get_field_name('post_types'); ?>[]"
                           value="<?php echo esc_attr($pt_slug); ?>"
                           <?php checked( in_array($pt_slug, (array)$post_types, true) ); ?>>
                    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr($pt_color); ?>;flex-shrink:0;"></span>
                    <strong><?php echo esc_html($pt_label); ?></strong>
                    <code style="color:#999;font-size:10px;"><?php echo esc_html($pt_slug); ?></code>
                </label>
            </p>
        <?php endforeach; ?>
        </div>
        <p style="margin-top:10px;">
            <label><input type="checkbox" name="<?php echo $this->get_field_name('show_tier'); ?>" value="1" <?php checked($show_tier); ?>> <strong>Show tier badge</strong></label>
        </p>
        <p>
            <label><input type="checkbox" name="<?php echo $this->get_field_name('show_type'); ?>" value="1" <?php checked($show_type); ?>> <strong>Show post type label</strong></label>
        </p>
        <p>
            <label><input type="checkbox" name="<?php echo $this->get_field_name('show_date'); ?>" value="1" <?php checked($show_date); ?>> <strong>Show date</strong></label>
        </p>
        <p>
            <label><input type="checkbox" name="<?php echo $this->get_field_name('show_thumb'); ?>" value="1" <?php checked($show_thumb); ?>> <strong>Show thumbnail</strong> (if available)</label>
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        return [
            'title'      => sanitize_text_field( $new_instance['title'] ?? '' ),
            'count'      => absint( $new_instance['count'] ?? 5 ),
            'post_types' => isset( $new_instance['post_types'] )
                            ? array_map( 'sanitize_key', (array) $new_instance['post_types'] )
                            : LG_RPW_DEFAULTS,
            'show_type'  => ! empty( $new_instance['show_type'] ),
            'show_date'  => ! empty( $new_instance['show_date'] ),
            'show_thumb' => ! empty( $new_instance['show_thumb'] ),
            'show_tier'  => ! empty( $new_instance['show_tier'] ),
        ];
    }
}

/* ---------------------------------------------------------------
   3. BuddyBoss/BuddyPress activity feed — inject tier badge
   Uses bp_before_activity_entry which fires cleanly before each
   activity item is rendered, outside of the content body.
   This is the correct hook — NOT bp_activity_entry_content which
   fires inside the content block and causes layout bugs.
--------------------------------------------------------------- */
add_action( 'bp_before_activity_entry', 'lg_inject_activity_tier_badge' );

function lg_inject_activity_tier_badge() {
    global $activities_template;
    if ( empty( $activities_template->activity ) ) return;

    $activity = $activities_template->activity;

    // Resolve post ID — try secondary_item_id first, then item_id
    $post_id = 0;
    if ( ! empty( $activity->secondary_item_id ) ) {
        $post_id = absint( $activity->secondary_item_id );
    }
    if ( ! $post_id && ! empty( $activity->item_id ) ) {
        $post_id = absint( $activity->item_id );
    }
    if ( ! $post_id ) return;

    $post = get_post( $post_id );
    if ( ! $post || $post->post_status !== 'publish' ) return;

    // Only badge our known CPTs — skip profile updates, forum posts, etc.
    $known_types = array_keys( LG_RPW_TYPES );
    if ( ! in_array( $post->post_type, $known_types, true ) ) return;

    $terms = get_the_terms( $post_id, 'tier' );
    if ( empty( $terms ) || is_wp_error( $terms ) ) {
        $tier_slug = 'public';
    } else {
        $tier_slug = $terms[0]->slug;
    }
    $badge = lg_get_tier_badge( $post_id );
    if ( $badge ) {
        // Emit as hidden <li> with data-tier so JS can:
        // 1. Add lg-has-tier-{slug} class to the next sibling activity li (for border CSS)
        // 2. Insert the visible badge span just before the li (outside card, vis:hidden for non-admins)
        printf(
            '<li class="lg-activity-tier-badge-pending" data-tier="%s" aria-hidden="true">%s</li>',
            esc_attr( $tier_slug ),
            $badge
        );
    }
}

/* ---------------------------------------------------------------
   5. Frontend styles
--------------------------------------------------------------- */

/* ---------------------------------------------------------------
   7. ACF Author Override for BuddyBoss activity entries
   
   BuddyBoss captures $post->post_author at publish time, which is
   always the admin. This corrects the activity user_id to match
   the ACF "post_author" field (Frontend Admin field type) on:
     - Initial publish (bp_activity_post_type_published)
     - Any subsequent save/author change (acf/save_post @ priority 20)
   
   Affected CPTs: post-imgcap, event, post-type-videos, member-directory
--------------------------------------------------------------- */

define( 'LG_AUTHOR_CPTS', [ 'post-imgcap', 'event', 'post-type-videos', 'member-directory' ] );

/**
 * Get the correct author user ID from the ACF post_author field.
 * The field returns an array with at minimum an 'ID' key.
 * Falls back to $post->post_author if ACF field is empty/invalid.
 */
function lg_get_acf_author_id( $post_id ) {
    if ( ! function_exists( 'get_field' ) ) return null;

    $acf_author = get_field( 'post_author', $post_id );

    // Field returns array: [ 'ID' => int, ... ] or a user ID int depending on return format
    if ( is_array( $acf_author ) && ! empty( $acf_author['ID'] ) ) {
        return absint( $acf_author['ID'] );
    }
    if ( is_numeric( $acf_author ) && $acf_author > 0 ) {
        return absint( $acf_author );
    }

    return null;
}

/**
 * Fix the activity user_id at publish time.
 * Fires immediately after BuddyBoss creates the activity entry.
 * $activity_id is the bp_activity row just inserted.
 */
add_action( 'bp_activity_post_type_published', function( $activity_id, $post, $activity_args ) {
    if ( ! in_array( $post->post_type, LG_AUTHOR_CPTS, true ) ) return;
    if ( ! $activity_id ) return;

    $author_id = lg_get_acf_author_id( $post->ID );
    if ( ! $author_id ) return;

    // Update the activity row directly
    global $wpdb;
    $bp_prefix   = bp_core_get_table_prefix();
    $table       = $bp_prefix . 'bp_activity';

    // Also regenerate the action string with the correct user link
    $activity = new BP_Activity_Activity( $activity_id );
    if ( ! $activity->id ) return;

    $activity->user_id      = $author_id;
    $activity->primary_link = bp_core_get_userlink( $author_id, false, true );

    // Regenerate the "X posted a new video" string with correct name
    $activity->action = bp_activity_generate_action_string( $activity );
    $activity->save();

}, 10, 3 );

/**
 * Fix the activity user_id when ACF saves post_author field.
 * Covers: initial publish if ACF fires after BP, and author edits.
 * Priority 20 = after ACF has written the field value.
 */
add_action( 'acf/save_post', function( $post_id ) {
    // Skip autosaves, revisions, options pages
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

    $post = get_post( $post_id );
    if ( ! $post || ! in_array( $post->post_type, LG_AUTHOR_CPTS, true ) ) return;
    if ( $post->post_status !== 'publish' ) return;

    $author_id = lg_get_acf_author_id( $post_id );
    if ( ! $author_id ) return;

    // Find the existing activity entry for this post
    $activities = bp_activity_get( [
        'filter'        => [
            'secondary_item_id' => $post_id,
            'component'         => 'blogs',
        ],
        'show_hidden'   => true,
        'per_page'      => 1,
        'page'          => 1,
    ] );

    if ( empty( $activities['activities'] ) ) return;

    $activity = $activities['activities'][0];
    if ( $activity->user_id === $author_id ) return; // Already correct

    $activity_obj               = new BP_Activity_Activity( $activity->id );
    $activity_obj->user_id      = $author_id;
    $activity_obj->primary_link = bp_core_get_userlink( $author_id, false, true );
    $activity_obj->action       = bp_activity_generate_action_string( $activity_obj );
    $activity_obj->save();

}, 20 );

add_action( 'wp_head', function () { ?>
<style id="lg-recent-cpt-styles">

/* =====================================================
   WIDGET — Recent Posts list (no tier badges — kept clean)
   ===================================================== */
.lg-recent-cpt-list { list-style:none; margin:0; padding:0; }
.lg-recent-cpt-item { display:flex; align-items:flex-start; gap:10px; padding:9px 0; border-bottom:1px solid rgba(0,0,0,.07); }
.lg-recent-cpt-item:last-child { border-bottom:none; padding-bottom:0; }
.lg-recent-cpt-thumb { flex-shrink:0; }
.lg-recent-cpt-thumb img { width:52px; height:52px; object-fit:cover; border-radius:4px; display:block; }
.lg-recent-cpt-body { display:flex; flex-direction:column; gap:3px; min-width:0; }
.lg-recent-cpt-meta { display:flex; align-items:center; flex-wrap:wrap; gap:4px; }
.lg-recent-cpt-type { font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; line-height:1.2; }
.lg-recent-cpt-title { font-size:13px; font-weight:600; line-height:1.35; color:#222; text-decoration:none; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.lg-recent-cpt-title:hover { color:#c8a951; text-decoration:underline; }
.lg-recent-cpt-date { font-size:11px; color:#888; line-height:1.2; }
.lg-recent-cpt-empty { font-size:13px; color:#888; margin:0; }

/* =====================================================
   TIER BADGE base style
   ===================================================== */
.lg-tier-badge {
    display: inline-flex;
    align-items: center;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: .07em;
    text-transform: uppercase;
    padding: 2px 7px;
    border-radius: 3px;
    line-height: 1.4;
    white-space: nowrap;
}

/* =====================================================
   ACTIVITY FEED — pending badge li (hidden, structural only)
   ===================================================== */
li.lg-activity-tier-badge-pending {
    display: none !important;
    list-style: none;
    height: 0;
    overflow: hidden;
}

/* =====================================================
   ACTIVITY FEED — FILE TAB badge
   JS inserts .lg-activity-tab BEFORE each activity li.
   The tab sits flush on the top-right of the card,
   styled like a folder tab with flat bottom edge that
   merges into the card's top border.
   ===================================================== */

/* The tab is a block-level row that holds the tab pill */
.lg-activity-tab {
    display: flex;
    justify-content: flex-end;
    padding-right: 12px;
    pointer-events: none;
    position: relative;
    z-index: 1;
    /* Each activity li has margin-top:20px AND margin-bottom:20px (no collapse).
       Tab sits between two cards in that 40px total gap.
       Negative margins pull the tab flush to the card BELOW it.
       Tab height ~22px, so: eat the bottom card margin-top (20px) + 1px for border overlap */
    margin-bottom: -21px;
}

.lg-activity-tab .lg-tier-badge {
    /* Tab shape: rounded top corners, flat bottom */
    border-radius: 4px 4px 0 0;
    font-size: 9px;
    padding: 3px 10px 4px;
    border: 1px solid transparent;
    border-bottom: none;
    position: relative;
    display: inline-block;
}

/* Per-tier tab colors — bg matches border color */
.lg-activity-tab .lg-tier-public {
    background: #9aab6f;
    color: #fff;
    border-color: #9aab6f;
    border-bottom-color: #9aab6f;
}
.lg-activity-tab .lg-tier-looth-lite {
    background: #2d6a9f;
    color: #fff;
    border-color: #2d6a9f;
    border-bottom-color: #2d6a9f;
}
.lg-activity-tab .lg-tier-looth-pro {
    background: #c8a951;
    color: #1a1a1a;
    border-color: #c8a951;
    border-bottom-color: #c8a951;
}
.lg-activity-tab .lg-tier-looth-plus {
    background: #7b2d8b;
    color: #fff;
    border-color: #7b2d8b;
    border-bottom-color: #7b2d8b;
}

/* =====================================================
   ACTIVITY CARD — colored LEFT border per tier
   JS adds lg-has-tier-{slug} class to each activity li
   ===================================================== */
#activity-stream li.lg-has-tier-public {
    border-left: 3px solid #9aab6f !important;
    border-top-color: #9aab6f !important;
}
#activity-stream li.lg-has-tier-looth-lite {
    border-left: 3px solid #2d6a9f !important;
    border-top-color: #2d6a9f !important;
}
#activity-stream li.lg-has-tier-looth-pro {
    border-left: 3px solid #c8a951 !important;
    border-top-color: #c8a951 !important;
}
#activity-stream li.lg-has-tier-looth-plus {
    border-left: 3px solid #7b2d8b !important;
    border-top-color: #7b2d8b !important;
}

</style>
<?php } );

/* ---------------------------------------------------------------
   6. JS: process pending badge li elements —
      - Add lg-has-tier-{slug} class to sibling activity li (for border)
      - Insert .lg-activity-tab div BEFORE each activity li (file tab on top of card)
      - Runs on load + MutationObserver for AJAX load-more
--------------------------------------------------------------- */
add_action( 'wp_footer', function () { ?>
<script id="lg-tier-badge-relocator">
(function() {
    function lgProcessBadges() {
        var ul = document.querySelector('ul.activity-list');
        if ( ! ul ) return;

        var pending = ul.querySelectorAll('li.lg-activity-tier-badge-pending');
        pending.forEach(function(p) {
            var nextLi = p.nextElementSibling;
            if ( ! nextLi || ! nextLi.classList.contains('activity-item') ) {
                p.parentNode.removeChild(p);
                return;
            }

            var tier = p.getAttribute('data-tier') || 'public';
            var badgeEl = p.querySelector('.lg-tier-badge');

            // 1. Add tier class to the activity li for border color
            nextLi.classList.add('lg-has-tier-' + tier);

            // 2. Insert file-tab div BEFORE the activity li (flush on top of card)
            if ( badgeEl && ! nextLi.previousElementSibling?.classList.contains('lg-activity-tab') ) {
                var tab = document.createElement('div');
                tab.className = 'lg-activity-tab';
                tab.appendChild( badgeEl.cloneNode(true) );
                ul.insertBefore( tab, nextLi );
            }

            // 3. Remove the structural placeholder
            p.parentNode.removeChild(p);
        });
    }

    // Run on load
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', lgProcessBadges );
    } else {
        lgProcessBadges();
    }

    // BuddyBoss AJAX events
    if ( typeof jQuery !== 'undefined' ) {
        jQuery(document).on(
            'bp-activity-stream-updated bp_ajax_request heartbeat-tick',
            lgProcessBadges
        );
    }

    // MutationObserver for load-more injected items
    function lgObserve() {
        var ul = document.querySelector('ul.activity-list');
        if ( ! ul ) return;
        var obs = new MutationObserver(function(mutations) {
            var needsRun = false;
            mutations.forEach(function(m) {
                m.addedNodes.forEach(function(n) {
                    if ( n.nodeType === 1 && n.classList && (
                        n.classList.contains('lg-activity-tier-badge-pending') ||
                        ( n.querySelector && n.querySelector('.lg-activity-tier-badge-pending') )
                    )) { needsRun = true; }
                });
            });
            if ( needsRun ) lgProcessBadges();
        });
        obs.observe( ul, { childList: true, subtree: false } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', lgObserve );
    } else {
        lgObserve();
    }
})();
</script>
<?php } );
