<?php
/**
 * Plugin Name: LG Event Reminders & Auto-Archive
 * Description: When a Looth Group event post is published or updated, automatically
 *              creates a FluentCRM scheduled campaign timed relative to event start.
 *              Per-event lead time is configurable from the event edit screen.
 *              Handles updates and deletions cleanly.
 *              Also auto-archives past events: moves to Archived status 1 day after the event ends.
 * Version: 3.4.0
 * Author: The Looth Group
 *
 * 3.4.0 — Idempotency fix (no more duplicate reminder campaigns):
 *   • Dedupe on a STABLE event→campaign key (slug prefix lg-event-reminder-<postid>-,
 *     no time()) that survives a post-meta wipe / DB reload. create_or_update now
 *     finds+deletes ALL scheduled/draft campaigns for an event, not just the one in
 *     post-meta. (was: lost-linkage duplicate.)
 *   • Past-window guard: never (re)create a 'scheduled' campaign whose send time has
 *     already passed — that is what made a re-save of an already-reminded event send
 *     again. A genuinely rescheduled event (time moved forward) still re-schedules.
 *   • Sent/working campaigns are still always left intact.
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────
// CUSTOM POST STATUS: Archived
// ─────────────────────────────────────────────

add_action( 'init', function() {
    register_post_status( 'archived', [
        'label'                     => 'Archived',
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( 'Archived <span class="count">(%s)</span>', 'Archived <span class="count">(%s)</span>' ),
    ] );
} );

// Admin UI: inject 'Archived' into Publish meta box status dropdown
add_action( 'admin_footer-post.php', 'lg_er_archived_admin_js' );
add_action( 'admin_footer-post-new.php', 'lg_er_archived_admin_js' );
function lg_er_archived_admin_js() {
    global $post;
    if ( ! $post || $post->post_type !== LG_ER_POST_TYPE ) return;
    $is = ( $post->post_status === 'archived' ) ? 'true' : 'false';
    echo '<script>jQuery(function($){';
    echo 'var s=$("#post_status");';
    echo 'if(!s.find("option[value=archived]").length)s.append($("<option>").val("archived").text("Archived"));';
    echo 'if(' . $is . '){s.val("archived");$("#post-status-display").text("Archived");}';
    echo '});<\/script>';
}

// ─────────────────────────────────────────────
// CONFIGURATION
// ─────────────────────────────────────────────

// ACF field names on your 'event' CPT (confirmed from ACF field group)
define( 'LG_ER_DATE_FIELD',     'events_start_date_and_time_' );  // ACF Date Picker — returns Ymd
define( 'LG_ER_TIME_FIELD',     'time_of_event' );                // ACF Time Picker
define( 'LG_ER_TITLE_FIELD',    '' );                             // Uses post_title

// Timezone — must match WordPress Settings > General > Timezone
define( 'LG_ER_TIMEZONE',       'America/New_York' );

// Default reminder lead time in minutes
define( 'LG_ER_LEAD_TIME_DEFAULT', 60 );

// FluentCRM tag title — must match exactly in FluentCRM > Tags
define( 'LG_ER_FCRM_TAG',       'Event Reminders' );

// FluentCRM list ID for "Event Reminder Email List" (ID 4 from FluentCRM > Lists)
define( 'LG_ER_FCRM_LIST_ID',   '4' );

// From name / email for the reminder campaign
define( 'LG_ER_FROM_NAME',      'The Looth Group' );
define( 'LG_ER_FROM_EMAIL',     'hello@loothgroup.com' );

// Post type slug
define( 'LG_ER_POST_TYPE',      'event' );

// Test mode — FluentCRM tag for test sends (only you as subscriber)
define( 'LG_ER_TEST_TAG',       'Test Event Reminders' );

// Post meta keys
define( 'LG_ER_CAMPAIGN_META',       '_lg_er_fcrm_campaign_id' );
define( 'LG_ER_TEST_CAMPAIGN_META',  '_lg_er_test_campaign_id' );
define( 'LG_ER_LEAD_TIME_META',      '_lg_er_lead_time_minutes' );

// ─────────────────────────────────────────────
// LOGGING — always logs to error_log for debugging
// ─────────────────────────────────────────────

function lg_er_log( $message, $level = 'info' ) {
    // Only log errors/warnings in production; log everything when WP_DEBUG_LOG is on
    if ( $level === 'info' && ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) ) return;
    error_log( '[LG Event Reminders] [' . strtoupper( $level ) . '] ' . $message );
}

// ─────────────────────────────────────────────
// HOOKS: We use BOTH save_post AND acf/save_post
// A static flag prevents double-firing.
// ─────────────────────────────────────────────

add_action( 'acf/save_post', 'lg_er_on_acf_save', 20 );
add_action( 'save_post', 'lg_er_on_save_post', 99, 2 );

/**
 * Static flag to prevent the campaign logic running twice on the same request.
 */
function lg_er_has_run( $post_id = null ) {
    static $ran = [];
    if ( $post_id === null ) return false;
    if ( isset( $ran[ $post_id ] ) ) return true;
    $ran[ $post_id ] = true;
    return false;
}

function lg_er_on_acf_save( $post_id ) {
    lg_er_log( 'acf/save_post fired for post #' . $post_id );

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== LG_ER_POST_TYPE ) return;

    lg_er_log( 'acf/save_post — post type matched "event", status: ' . $post->post_status );

    if ( lg_er_has_run( $post_id ) ) {
        lg_er_log( 'acf/save_post — already processed #' . $post_id . ' this request, skipping.' );
        return;
    }

    if ( $post->post_status !== 'publish' ) {
        lg_er_delete_campaign_for_post( $post_id );
        return;
    }

    lg_er_create_or_update_campaign( $post_id );
}

function lg_er_on_save_post( $post_id, $post ) {
    lg_er_log( 'save_post fired for post #' . $post_id . ' (type: ' . $post->post_type . ')' );

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( $post->post_type !== LG_ER_POST_TYPE ) return;

    if ( lg_er_has_run( $post_id ) ) {
        lg_er_log( 'save_post — already processed #' . $post_id . ' via acf/save_post, skipping.' );
        return;
    }

    lg_er_log( 'save_post — acf/save_post did NOT fire for #' . $post_id . ', running fallback.' );

    if ( $post->post_status !== 'publish' ) {
        lg_er_delete_campaign_for_post( $post_id );
        return;
    }

    lg_er_create_or_update_campaign( $post_id );
}

// ─────────────────────────────────────────────
// HOOK: POST DELETED / TRASHED
// ─────────────────────────────────────────────

add_action( 'trashed_post',      'lg_er_delete_campaign_for_post' );
add_action( 'transition_post_status', function( $new, $old, $post ) { if ( $new === 'archived' && $post->post_type === LG_ER_POST_TYPE ) lg_er_delete_campaign_for_post( $post->ID ); }, 10, 3 );
add_action( 'before_delete_post', 'lg_er_delete_campaign_for_post' );

// ─────────────────────────────────────────────
// HELPER: Read ACF field with fallback to postmeta
// ─────────────────────────────────────────────

function lg_er_get_field_value( $field_name, $post_id ) {
    // Try get_field() first (ACF API)
    $value = function_exists( 'get_field' ) ? get_field( $field_name, $post_id ) : null;
    if ( $value ) {
        lg_er_log( "get_field('{$field_name}', {$post_id}) = '{$value}'" );
        return $value;
    }

    // Fallback: read directly from postmeta
    $value = get_post_meta( $post_id, $field_name, true );
    if ( $value ) {
        lg_er_log( "get_post_meta({$post_id}, '{$field_name}') = '{$value}' (fallback)" );
        return $value;
    }

    // Debug: dump related meta keys so we can diagnose field name issues
    $all_meta = get_post_meta( $post_id );
    $matches  = [];
    foreach ( $all_meta as $key => $val ) {
        if ( stripos( $key, 'date' ) !== false || stripos( $key, 'time' ) !== false || stripos( $key, 'event' ) !== false ) {
            $matches[ $key ] = $val[0] ?? '';
        }
    }
    lg_er_log( "Field '{$field_name}' returned empty for post #{$post_id}. Related meta keys: " . wp_json_encode( $matches ) );

    return '';
}

// ─────────────────────────────────────────────
// CORE: CREATE OR UPDATE THE FLUENTCRM CAMPAIGN
// ─────────────────────────────────────────────

function lg_er_create_or_update_campaign( $post_id ) {

    lg_er_log( '── Begin campaign creation for event #' . $post_id . ' ──' );

    $post = get_post( $post_id );
    if ( ! $post || $post->post_status !== 'publish' ) {
        lg_er_log( 'Post #' . $post_id . ' is not published — aborting.' );
        return;
    }

    // ── 1. Read and validate ACF fields ──────────────────────────────

    $date_raw = lg_er_get_field_value( LG_ER_DATE_FIELD, $post_id );
    $time_raw = lg_er_get_field_value( LG_ER_TIME_FIELD, $post_id );

    if ( ! $date_raw || ! $time_raw ) {
        lg_er_log( 'Event #' . $post_id . ' is missing date ("' . $date_raw . '") or time ("' . $time_raw . '") — skipping.', 'error' );
        lg_er_delete_campaign_for_post( $post_id );
        return;
    }

    // ── 2. Build the send datetime ───────────────────────────────────

    // Normalise date to Ymd (strip dashes if present)
    $date_raw = str_replace( '-', '', $date_raw );

    $tz = new DateTimeZone( LG_ER_TIMEZONE );

    // Try multiple time formats since ACF Time Picker can return various formats
    $event_dt     = null;
    $time_formats = [ 'Ymd g:i a', 'Ymd G:i', 'Ymd H:i', 'Ymd h:i A', 'Ymd g:iA', 'Ymd h:iA' ];
    foreach ( $time_formats as $fmt ) {
        $event_dt = DateTime::createFromFormat( $fmt, $date_raw . ' ' . $time_raw, $tz );
        if ( $event_dt ) {
            lg_er_log( 'Parsed datetime with format "' . $fmt . '" → ' . $event_dt->format( 'Y-m-d H:i T' ) );
            break;
        }
    }

    if ( ! $event_dt ) {
        lg_er_log( 'Could not parse datetime for event #' . $post_id . ' — date: "' . $date_raw . '", time: "' . $time_raw . '"', 'error' );
        return;
    }

    // Read per-event lead time; fall back to the plugin default
    $lead_minutes = intval( get_post_meta( $post_id, LG_ER_LEAD_TIME_META, true ) );
    if ( $lead_minutes <= 0 ) {
        $lead_minutes = LG_ER_LEAD_TIME_DEFAULT;
    }

    // Send time = event start minus lead time
    $send_dt = clone $event_dt;
    $send_dt->modify( '-' . $lead_minutes . ' minutes' );

    // FluentCRM expects scheduled_at in LOCAL (WordPress) time, not UTC
    $scheduled_at = $send_dt->format( 'Y-m-d H:i:s' );

    lg_er_log( sprintf(
        'Event #%d — event at %s, reminder %d min before → send at %s (local)',
        $post_id,
        $event_dt->format( 'Y-m-d H:i T' ),
        $lead_minutes,
        $scheduled_at
    ) );

    // ── 3. Reconcile existing campaigns for this event ───────────────
    //    Dedupe on a STABLE event→campaign key (slug prefix), which survives
    //    a post-meta wipe (e.g. a DB reload). Find ALL campaigns for this
    //    event, not just the one recorded in post-meta.

    $existing     = lg_er_find_event_campaigns( $post_id );
    $already_sent = false;
    foreach ( $existing as $c ) {
        if ( in_array( $c->status, [ 'sent', 'working' ], true ) ) {
            $already_sent = true;
            break;
        }
    }

    // Guard against re-sending: a 'scheduled' campaign whose send time is in
    // the past gets blasted immediately by FluentCRM. That only happens on a
    // re-save of a current/past event (the duplicate-send bug). Never create
    // one then — just clear any stale scheduled/draft duplicates and stop.
    // A genuinely rescheduled event (time moved forward) lands here with a
    // future send time and proceeds normally below.
    $now_local = new DateTime( 'now', $tz );
    if ( $send_dt <= $now_local ) {
        lg_er_log( sprintf(
            'Event #%d reminder send time %s already passed (now %s) — not (re)scheduling%s.',
            $post_id, $scheduled_at, $now_local->format( 'Y-m-d H:i:s' ),
            $already_sent ? '; a reminder already sent for this event' : ''
        ), 'warning' );
        lg_er_delete_campaign_for_post( $post_id );
        return;
    }

    // Future send time: clear any stale scheduled/draft/paused duplicates
    // (sent/working left intact) so exactly one fresh campaign remains.
    lg_er_delete_campaign_for_post( $post_id );

    // ── 4. Check FluentCRM is available ──────────────────────────────

    if ( ! class_exists( '\FluentCrm\App\Models\Campaign' ) ) {
        lg_er_log( 'FluentCRM Campaign model not found — is FluentCRM active?', 'error' );
        return;
    }

    if ( ! class_exists( '\FluentCrm\App\Models\Tag' ) ) {
        lg_er_log( 'FluentCRM Tag model not found.', 'error' );
        return;
    }

    lg_er_log( 'FluentCRM models available.' );

    // ── 5. Resolve the recipient tag ─────────────────────────────────

    $tag = \FluentCrm\App\Models\Tag::where( 'title', LG_ER_FCRM_TAG )->first();

    if ( ! $tag ) {
        lg_er_log( 'FluentCRM tag "' . LG_ER_FCRM_TAG . '" not found — create it in FluentCRM > Tags.', 'error' );
        return;
    }

    lg_er_log( 'Found FluentCRM tag "' . LG_ER_FCRM_TAG . '" (ID: ' . $tag->id . ').' );

    // ── 6. Build email content from event data ───────────────────────

    $event_title = $post->post_title;
    $event_url   = get_permalink( $post_id );
    $date_nice   = $event_dt->format( 'l, F j, Y' );
    $time_nice   = $event_dt->format( 'g:i A' );

    // UTC conversion for international subscribers
    $event_dt_utc = clone $event_dt;
    $event_dt_utc->setTimezone( new DateTimeZone( 'UTC' ) );
    $time_utc    = $event_dt_utc->format( 'g:i A' );

    $thumb_id    = get_post_thumbnail_id( $post_id );
    $thumb_url   = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';

    $lead_label  = lg_er_lead_time_label( $lead_minutes );
    $subject     = '⏰ ' . $lead_label . ': ' . $event_title . ' at ' . $time_nice . ' ET / ' . $time_utc . ' UTC';
    $pre_header  = $event_title . ' — ' . strtolower( $lead_label ) . ' reminder';
    $body        = lg_er_build_email_body( $event_title, $date_nice, $time_nice, $event_url, $thumb_url, $time_utc );

    // ── 7. Create the FluentCRM campaign ─────────────────────────────

    $campaign_data = [
        'title'            => 'Event Reminder: ' . $event_title . ' — ' . $date_nice,
        // Stable, queryable slug: prefix carries the event id; the suffix is
        // the send time (YmdHi) — deterministic, NOT time(), so re-saves with
        // the same event time regenerate the same slug instead of a new one.
        'slug'             => sanitize_title( 'lg-event-reminder-' . $post_id . '-' . $send_dt->format( 'YmdHi' ) ),
        'type'             => 'campaign',
        'status'           => 'scheduled',
        'email_subject'    => $subject,
        'email_pre_header' => $pre_header,
        'email_body'       => $body,
        'scheduled_at'     => $scheduled_at,
        'design_template'  => 'raw_classic',
        'created_by'       => get_current_user_id() ?: 1,
        'settings'         => [
            'mailer_settings' => [
                'from_name'      => LG_ER_FROM_NAME,
                'from_email'     => LG_ER_FROM_EMAIL,
                'reply_to_name'  => LG_ER_FROM_NAME,
                'reply_to_email' => LG_ER_FROM_EMAIL,
                'is_custom'      => 'yes',
            ],
            'subscribers'         => [
                [ 'list' => LG_ER_FCRM_LIST_ID, 'tag' => (string) $tag->id ],
            ],
            'excludedSubscribers' => null,
            'sending_filter'      => 'list_tag',
            'dynamic_segment'     => [ 'id' => '', 'slug' => '' ],
            'advanced_filters'    => [ [] ],
        ],
    ];

    lg_er_log( 'Creating FluentCRM campaign...' );

    try {
        $campaign = \FluentCrm\App\Models\Campaign::create( $campaign_data );
    } catch ( \Exception $e ) {
        lg_er_log( 'Exception creating campaign: ' . $e->getMessage(), 'error' );
        return;
    }

    if ( ! $campaign || ! $campaign->id ) {
        lg_er_log( 'FluentCRM campaign creation returned empty for event #' . $post_id, 'error' );
        return;
    }

    lg_er_log( 'Campaign #' . $campaign->id . ' created successfully.' );

    // ── 8. Subscribe recipients (populate CampaignEmail rows) ────────
    //    This is CRITICAL. Without this, FluentCRM has no emails to send.
    //    We use getSubscriberIdsBySegmentSettings() + subscribe() because
    //    subscribeBySegment() does not reliably populate CampaignEmail rows.

    lg_er_log( 'Subscribing recipients by segment settings...' );

    $recipient_count = 0;

    try {
        $campaign_settings = $campaign->settings;
        if ( is_string( $campaign_settings ) ) {
            $campaign_settings = json_decode( $campaign_settings, true );
        }
        if ( ! is_array( $campaign_settings ) ) {
            $campaign_settings = [];
        }

        // Step 1: Get subscriber IDs matching the list+tag filter
        $id_result = $campaign->getSubscriberIdsBySegmentSettings( $campaign_settings );

        $subscriber_ids = [];
        if ( is_array( $id_result ) && isset( $id_result['subscriber_ids'] ) ) {
            $subscriber_ids = $id_result['subscriber_ids'];
        } elseif ( is_array( $id_result ) ) {
            $subscriber_ids = $id_result;
        }

        lg_er_log( 'Found ' . count( $subscriber_ids ) . ' subscriber IDs.' );

        // Step 2: Subscribe them to the campaign (creates CampaignEmail rows)
        if ( ! empty( $subscriber_ids ) ) {
            $campaign->subscribe( $subscriber_ids );

            // CRITICAL: Set scheduled_at on CampaignEmail rows so the mailer picks them up.
            // subscribe() creates rows but doesn't set scheduled_at — the mailer handler
            // only processes rows where scheduled_at <= NOW().
            if ( class_exists( '\FluentCrm\App\Models\CampaignEmail' ) ) {
                \FluentCrm\App\Models\CampaignEmail::where( 'campaign_id', $campaign->id )
                    ->update( [ 'scheduled_at' => $scheduled_at ] );
                $recipient_count = \FluentCrm\App\Models\CampaignEmail::where( 'campaign_id', $campaign->id )->count();
            } else {
                $recipient_count = count( $subscriber_ids );
            }
        }

    } catch ( \Exception $e ) {
        lg_er_log( 'Recipient subscription failed: ' . $e->getMessage(), 'error' );
    }

    if ( $recipient_count === 0 ) {
        lg_er_log( sprintf(
            'Campaign #%d has 0 recipients — check that list ID %s and tag "%s" (ID: %d) have subscribed contacts.',
            $campaign->id,
            LG_ER_FCRM_LIST_ID,
            LG_ER_FCRM_TAG,
            $tag->id
        ), 'warning' );
    } else {
        $campaign->recipients_count = $recipient_count;
        $campaign->save();
        lg_er_log( 'Subscribed ' . $recipient_count . ' recipients.' );
    }

    // ── 10. Store campaign ID on the event post ──────────────────────

    update_post_meta( $post_id, LG_ER_CAMPAIGN_META, $campaign->id );

    lg_er_log( sprintf(
        '✅ Campaign #%d for event #%d "%s" — %d recipients — scheduled %s UTC (%s %s)',
        $campaign->id,
        $post_id,
        $event_title,
        $recipient_count,
        $scheduled_at,
        $date_nice,
        $time_nice
    ) );

    lg_er_log( '── End campaign creation for event #' . $post_id . ' ──' );
}

// ─────────────────────────────────────────────
// HELPER: STABLE event → campaign linkage
//   Campaign slugs are prefixed `lg-event-reminder-<postid>-` so a campaign
//   can always be found from its event id alone — even if the post-meta
//   pointer is lost (e.g. a DB reload). This is what prevents duplicates:
//   dedupe NEVER relies solely on post-meta.
// ─────────────────────────────────────────────

function lg_er_event_slug_prefix( $post_id ) {
    return 'lg-event-reminder-' . intval( $post_id );
}

/**
 * Find every FluentCRM campaign belonging to an event, by stable slug
 * (prefix match) plus the legacy post-meta pointer. Returns an array of
 * Campaign models keyed by id (de-duplicated).
 */
function lg_er_find_event_campaigns( $post_id ) {
    if ( ! class_exists( '\FluentCrm\App\Models\Campaign' ) ) return [];

    $post_id = intval( $post_id );
    $prefix  = lg_er_event_slug_prefix( $post_id );
    $found   = [];

    // Stable slug: exact `lg-event-reminder-<id>` or `lg-event-reminder-<id>-*`.
    // The trailing dash in the LIKE keeps id 12 from matching id 123.
    $rows = \FluentCrm\App\Models\Campaign::where( 'slug', $prefix )
        ->orWhere( 'slug', 'LIKE', $prefix . '-%' )
        ->get();
    foreach ( $rows as $c ) {
        $found[ $c->id ] = $c;
    }

    // Legacy linkage: a campaign recorded in post-meta whose slug predates
    // the stable scheme (belt-and-braces; current slugs already carry the id).
    $meta_id = get_post_meta( $post_id, LG_ER_CAMPAIGN_META, true );
    if ( $meta_id && ! isset( $found[ $meta_id ] ) ) {
        $c = \FluentCrm\App\Models\Campaign::find( $meta_id );
        if ( $c ) {
            $found[ $c->id ] = $c;
        }
    }

    return array_values( $found );
}

// ─────────────────────────────────────────────
// HELPER: DELETE EXISTING CAMPAIGN(S) FOR A POST
//   Removes ALL scheduled/draft/paused campaigns for the event (found by
//   stable slug, so meta loss can't strand a duplicate). Sent/working
//   campaigns are always left intact.
// ─────────────────────────────────────────────

function lg_er_delete_campaign_for_post( $post_id ) {
    $campaigns = lg_er_find_event_campaigns( $post_id );

    foreach ( $campaigns as $campaign ) {
        if ( in_array( $campaign->status, [ 'sent', 'working' ], true ) ) {
            lg_er_log( 'Campaign #' . $campaign->id . ' is already ' . $campaign->status . ' — left intact.' );
            continue;
        }

        // Also delete associated CampaignEmail rows
        if ( class_exists( '\FluentCrm\App\Models\CampaignEmail' ) ) {
            \FluentCrm\App\Models\CampaignEmail::where( 'campaign_id', $campaign->id )->delete();
        }
        $campaign->delete();
        lg_er_log( 'Deleted FluentCRM campaign #' . $campaign->id . ' for event #' . $post_id );
    }

    delete_post_meta( $post_id, LG_ER_CAMPAIGN_META );
}

// ─────────────────────────────────────────────
// EMAIL BODY TEMPLATE — Branded HTML
// ─────────────────────────────────────────────

function lg_er_build_email_body( $title, $date, $time, $url, $image_url = '', $time_utc = '' ) {

    $gold  = '#ECB351';
    $sand  = '#F1DE83';
    $mint  = '#A8BC8E';
    $coral = '#FE6A4F';
    $dark  = '#2B2318';
    $mid   = '#5C4E3A';
    $light = '#FAF6EE';

    $image_block = '';
    if ( $image_url ) {
        $image_block = "
        <tr>
            <td align=\"center\" style=\"padding:0;\">
                <a href=\"{$url}\" style=\"display:block;\">
                    <img src=\"{$image_url}\" alt=\"" . esc_attr( $title ) . "\"
                         width=\"600\" style=\"display:block;width:100%;max-width:600px;height:auto;border:0;margin:0;\" />
                </a>
            </td>
        </tr>";
    }

    $time_row = $time ? "
                        <tr>
                            <td width=\"80\" style=\"font-family:Georgia,'Times New Roman',serif;font-size:11px;text-transform:uppercase;letter-spacing:0.12em;color:{$mint};padding-bottom:2px;white-space:nowrap;\">Time</td>
                            <td style=\"font-family:Georgia,'Times New Roman',serif;font-size:15px;color:{$dark};padding-bottom:2px;\">{$time} ET" . ( $time_utc ? " <span style=\"color:{$mid};font-size:13px;\">({$time_utc} UTC)</span>" : '' ) . "</td>
                        </tr>" : '';

    $unsub_url  = '{{unsubscribe_url}}';
    $manage_url = home_url( '/member-profile/' );

    return "<!DOCTYPE html>
<html lang=\"en\">
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\">
    <title>Event Reminder — The Looth Group</title>
    <!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
    <style type=\"text/css\">
        body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}
        table,td{mso-table-lspace:0pt;mso-table-rspace:0pt;}
        img{-ms-interpolation-mode:bicubic;border:0;outline:none;text-decoration:none;}
        body{margin:0;padding:0;background-color:{$light};}
        a{color:{$coral};}
        @media only screen and (max-width:620px){
            .email-wrapper{width:100%!important;}
            .content-pad{padding:28px 20px!important;}
            .footer-pad{padding:24px 20px!important;}
        }
    </style>
</head>
<body style=\"margin:0;padding:0;background-color:{$light};\">

<div style=\"display:none;max-height:0;overflow:hidden;mso-hide:all;\">{$title} is happening today — see you there! &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>

<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background-color:{$light};min-width:320px;\">
<tr><td align=\"center\" style=\"padding:32px 16px 48px;\">

    <table class=\"email-wrapper\" role=\"presentation\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\"
           style=\"max-width:600px;width:100%;background-color:#ffffff;border-radius:4px;box-shadow:0 2px 16px rgba(43,35,24,0.10);\">

        <!-- HEADER -->
        <tr>
            <td style=\"background-color:{$dark};border-radius:4px 4px 0 0;padding:0;\">
                <table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\">
                <tr><td style=\"height:4px;background-color:{$gold};border-radius:4px 4px 0 0;font-size:0;line-height:0;\">&nbsp;</td></tr>
                <tr>
                    <td align=\"center\" style=\"padding:22px 32px 20px;\">
                        <span style=\"font-family:Georgia,'Times New Roman',serif;font-size:22px;letter-spacing:0.18em;text-transform:uppercase;color:{$gold};font-weight:normal;\">The Looth Group</span>
                    </td>
                </tr>
                </table>
            </td>
        </tr>

        {$image_block}

        <!-- BODY -->
        <tr>
            <td class=\"content-pad\" style=\"padding:36px 40px 32px;\">

                <p style=\"margin:0 0 10px;font-family:Georgia,'Times New Roman',serif;font-size:11px;letter-spacing:0.14em;text-transform:uppercase;color:{$mint};\">Event Reminder</p>

                <h1 style=\"margin:0 0 24px;font-family:Georgia,'Times New Roman',serif;font-size:26px;font-weight:normal;line-height:1.25;color:{$dark};border-bottom:2px solid {$sand};padding-bottom:20px;\">{$title}</h1>

                <p style=\"margin:0 0 28px;font-family:Georgia,'Times New Roman',serif;font-size:16px;line-height:1.6;color:{$mid};\">This event is happening <strong style=\"color:{$dark};\">today</strong> — we hope to see you there!</p>

                <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\"
                       style=\"background-color:{$light};border-left:3px solid {$gold};border-radius:0 4px 4px 0;margin:0 0 32px;width:100%;\">
                <tr><td style=\"padding:16px 20px;\">
                    <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"4\" border=\"0\">
                        <tr>
                            <td width=\"80\" style=\"font-family:Georgia,'Times New Roman',serif;font-size:11px;text-transform:uppercase;letter-spacing:0.12em;color:{$mint};padding-bottom:2px;white-space:nowrap;\">Date</td>
                            <td style=\"font-family:Georgia,'Times New Roman',serif;font-size:15px;color:{$dark};padding-bottom:2px;\">{$date}</td>
                        </tr>
                        {$time_row}
                    </table>
                </td></tr>
                </table>

                <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin:0 auto 8px;\">
                <tr>
                    <td align=\"center\" style=\"border-radius:3px;background-color:{$coral};\">
                        <a href=\"{$url}\" style=\"display:inline-block;padding:14px 36px;font-family:Georgia,'Times New Roman',serif;font-size:14px;letter-spacing:0.1em;text-transform:uppercase;color:#ffffff;text-decoration:none;border-radius:3px;mso-padding-alt:14px 36px;\">
                            <!--[if mso]>&nbsp;&nbsp;&nbsp;&nbsp;<![endif]-->View Event Details<!--[if mso]>&nbsp;&nbsp;&nbsp;&nbsp;<![endif]-->
                        </a>
                    </td>
                </tr>
                </table>

            </td>
        </tr>

        <!-- FOOTER -->
        <tr>
            <td class=\"footer-pad\" style=\"background-color:{$dark};border-radius:0 0 4px 4px;padding:28px 40px 32px;\">

                <table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin-bottom:20px;\">
                <tr><td style=\"height:2px;background-color:{$gold};font-size:0;line-height:0;\">&nbsp;</td></tr>
                </table>

                <p style=\"margin:0 0 6px;font-family:Georgia,'Times New Roman',serif;font-size:13px;letter-spacing:0.16em;text-transform:uppercase;color:{$sand};\">The Looth Group</p>

                <p style=\"margin:0 0 20px;font-family:Georgia,'Times New Roman',serif;font-size:12px;color:{$mint};letter-spacing:0.04em;\">A community for luthiers and guitar repair professionals.</p>

                <p style=\"margin:0 0 20px;font-family:Georgia,'Times New Roman',serif;font-size:12px;\">
                    <a href=\"https://loothgroup.com\" style=\"color:{$gold};text-decoration:none;\">loothgroup.com</a>
                    &nbsp;&nbsp;·&nbsp;&nbsp;
                    <a href=\"https://www.facebook.com/profile.php?id=100090399816475\" style=\"color:{$gold};text-decoration:none;\">Facebook</a>
                    &nbsp;&nbsp;·&nbsp;&nbsp;
                    <a href=\"https://www.youtube.com/@theloothgroup\" style=\"color:{$gold};text-decoration:none;\">YouTube</a>
                    &nbsp;&nbsp;·&nbsp;&nbsp;
                    <a href=\"https://www.instagram.com/theloothgroup/\" style=\"color:{$gold};text-decoration:none;\">Instagram</a>
                    &nbsp;&nbsp;·&nbsp;&nbsp;
                    <a href=\"{$manage_url}\" style=\"color:{$gold};text-decoration:none;\">Manage Preferences</a>
                    &nbsp;&nbsp;·&nbsp;&nbsp;
                    <a href=\"{$unsub_url}\" style=\"color:{$gold};text-decoration:none;\">Unsubscribe</a>
                </p>

                <p style=\"margin:0;font-family:Georgia,'Times New Roman',serif;font-size:11px;color:#7A6B56;line-height:1.6;\">
                    You're receiving this because you opted in to event reminders on
                    <a href=\"https://loothgroup.com\" style=\"color:#7A6B56;\">loothgroup.com</a>.
                    To stop receiving these emails, click Unsubscribe above or update your member profile preferences.
                </p>

            </td>
        </tr>

    </table>

</td></tr>
</table>
</body>
</html>";
}

// ─────────────────────────────────────────────
// HELPER: Human-readable lead time label
// ─────────────────────────────────────────────

function lg_er_lead_time_label( $minutes ) {
    if ( $minutes < 60 ) {
        return $minutes . ' minutes before';
    } elseif ( $minutes === 60 ) {
        return '1 hour before';
    } elseif ( $minutes % 60 === 0 ) {
        return ( $minutes / 60 ) . ' hours before';
    } else {
        $h = floor( $minutes / 60 );
        $m = $minutes % 60;
        return $h . 'h ' . $m . 'm before';
    }
}

// ─────────────────────────────────────────────
// ADMIN META BOX — per-event lead time setting
// ─────────────────────────────────────────────

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'lg_er_lead_time',
        '⏱ Reminder Lead Time',
        'lg_er_render_lead_time_box',
        LG_ER_POST_TYPE,
        'side',
        'high'
    );
} );

function lg_er_render_lead_time_box( WP_Post $post ) {
    wp_nonce_field( 'lg_er_lead_time_save', 'lg_er_lead_time_nonce' );

    $saved   = get_post_meta( $post->ID, LG_ER_LEAD_TIME_META, true );
    $current = $saved !== '' ? intval( $saved ) : LG_ER_LEAD_TIME_DEFAULT;

    $presets = [
        15   => '15 minutes before',
        30   => '30 minutes before',
        60   => '1 hour before (default)',
        120  => '2 hours before',
        180  => '3 hours before',
        1440 => '1 day before',
        0    => 'Custom…',
    ];
    ?>
    <p style="margin:0 0 8px;font-size:12px;color:#666;">
        How far in advance to send the reminder email.
    </p>

    <select id="lg_er_preset" style="width:100%;margin-bottom:8px;">
        <?php foreach ( $presets as $val => $label ) :
            $selected = ( $val !== 0 && $val === $current ) ? 'selected' : '';
            if ( $val === 0 && ! array_key_exists( $current, $presets ) ) $selected = 'selected';
        ?>
            <option value="<?php echo esc_attr( $val ); ?>" <?php echo $selected; ?>>
                <?php echo esc_html( $label ); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <div id="lg_er_custom_wrap" style="display:none;margin-bottom:8px;">
        <label style="font-size:12px;display:block;margin-bottom:4px;">Custom minutes before event:</label>
        <input type="number" id="lg_er_custom_minutes" min="1" max="10080"
               style="width:100%;" placeholder="e.g. 90" />
    </div>

    <input type="hidden" name="lg_er_lead_minutes" id="lg_er_lead_minutes"
           value="<?php echo esc_attr( $current ); ?>" />

    <p style="margin:8px 0 0;font-size:11px;color:#999;">
        Currently set to: <strong><?php echo esc_html( lg_er_lead_time_label( $current ) ); ?></strong>
    </p>

    <script>
    (function() {
        var preset  = document.getElementById('lg_er_preset');
        var custom  = document.getElementById('lg_er_custom_wrap');
        var custIn  = document.getElementById('lg_er_custom_minutes');
        var hidden  = document.getElementById('lg_er_lead_minutes');

        function syncPreset() {
            var val = parseInt( preset.value );
            if ( val === 0 ) {
                custom.style.display = 'block';
                custIn.value = hidden.value;
            } else {
                custom.style.display = 'none';
                hidden.value = val;
            }
        }

        preset.addEventListener('change', syncPreset);

        custIn.addEventListener('input', function() {
            var v = parseInt( custIn.value );
            if ( v > 0 ) hidden.value = v;
        });

        var presetVals = [15, 30, 60, 120, 180, 1440];
        if ( presetVals.indexOf( parseInt( hidden.value ) ) === -1 ) {
            preset.value = 0;
            syncPreset();
            custIn.value = hidden.value;
        }
    })();
    </script>
    <?php
}

// Save lead time on post save
add_action( 'save_post', function( $post_id, $post ) {
    if ( ! isset( $_POST['lg_er_lead_time_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['lg_er_lead_time_nonce'], 'lg_er_lead_time_save' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( $post->post_type !== LG_ER_POST_TYPE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $minutes = intval( $_POST['lg_er_lead_minutes'] ?? LG_ER_LEAD_TIME_DEFAULT );
    $minutes = max( 1, min( 10080, $minutes ) );
    update_post_meta( $post_id, LG_ER_LEAD_TIME_META, $minutes );
}, 10, 2 );

// ─────────────────────────────────────────────
// ADMIN META BOX — campaign status on event edit screen
// ─────────────────────────────────────────────

add_action( 'add_meta_boxes', function () {
    add_meta_box(
        'lg_er_campaign_status',
        '📧 Event Reminder Email',
        'lg_er_render_meta_box',
        LG_ER_POST_TYPE,
        'side',
        'high'
    );
    add_meta_box(
        'lg_er_archive_status',
        '🗂 Auto-Archive',
        'lg_er_render_archive_box',
        LG_ER_POST_TYPE,
        'side',
        'default'
    );
} );

function lg_er_render_archive_box( WP_Post $post ) {
    $tz  = new DateTimeZone( LG_ER_TIMEZONE );
    $now = new DateTime( 'now', $tz );

    // Check if already auto-archived
    $drafted_at_raw = get_post_meta( $post->ID, LG_ER_DRAFTED_AT_META, true );

    if ( $drafted_at_raw ) {
        $drafted_at   = DateTime::createFromFormat( 'Y-m-d H:i:s', $drafted_at_raw, $tz );

        echo '<p style="margin:0 0 6px;color:#d63638;"><strong>⚠ Auto-drafted</strong> on ' . esc_html( $drafted_at->format( 'M j, Y' ) ) . '</p>';
        echo '<p style="margin:0;font-size:11px;color:#888;">This event has been auto-archived.</p>';
        return;
    }

    // Get event datetime
    $event_dt = lg_er_get_event_datetime( $post->ID, $tz );

    if ( ! $event_dt ) {
        echo '<p style="margin:0;color:#888;font-size:12px;">📌 No date set — this event won\'t be auto-archived.</p>';
        echo '<p style="margin:4px 0 0;font-size:11px;color:#aaa;">Safe to use as a template.</p>';
        return;
    }

    $draft_date = clone $event_dt;
    $draft_date->setTime( 0, 0, 0 );
    $draft_date->modify( '+1 day' );


    if ( $post->post_status === 'publish' && $now > $draft_date ) {
        // Past event, will be drafted on next cron run
        echo '<p style="margin:0 0 6px;color:#dba617;"><strong>⏳ Pending archive</strong></p>';
        echo '<p style="margin:0;font-size:11px;color:#888;">This past event will be moved to Archived on the next cleanup run.</p>';
    } elseif ( $post->post_status === 'publish' ) {
        // Future event
        echo '<p style="margin:0 0 6px;color:#00a32a;"><strong>✓ Active event</strong></p>';
        echo '<p style="margin:0;font-size:11px;color:#888;">Auto-archive: <strong>' . esc_html( $draft_date->format( 'M j, Y' ) ) . '</strong></p>';
    } elseif ( $post->post_status === 'draft' ) {
        echo '<p style="margin:0;color:#888;font-size:12px;">This draft was not auto-archived — it won\'t be auto-trashed.</p>';
    } else {
        echo '<p style="margin:0;color:#888;font-size:12px;">Auto-archive only applies to published events.</p>';
    }
}

function lg_er_render_meta_box( WP_Post $post ) {
    $campaign_id = get_post_meta( $post->ID, LG_ER_CAMPAIGN_META, true );

    if ( ! $campaign_id ) {
        echo '<p style="color:#888;margin:0 0 6px;">⏳ No reminder scheduled yet.</p>';
        echo '<p style="font-size:11px;color:#aaa;margin:0 0 12px;">Will be created automatically on publish if date &amp; time fields are set.</p>';
    } else {

        if ( ! class_exists( '\FluentCrm\App\Models\Campaign' ) ) {
            echo '<p>FluentCRM not available.</p>';
        } else {

            $campaign = \FluentCrm\App\Models\Campaign::find( $campaign_id );

            if ( ! $campaign ) {
                echo '<p style="color:#888;">Campaign #' . esc_html( $campaign_id ) . ' no longer exists in FluentCRM.</p>';
                delete_post_meta( $post->ID, LG_ER_CAMPAIGN_META );
            } else {

                $status_colors = [
                    'scheduled' => '#2271b1',
                    'working'   => '#d63638',
                    'sent'      => '#00a32a',
                    'paused'    => '#dba617',
                    'draft'     => '#888',
                    'archived'  => '#888',
                ];
                $color = $status_colors[ $campaign->status ] ?? '#888';

                echo '<p style="margin:0 0 6px;"><strong>Campaign ID:</strong> #' . esc_html( $campaign_id ) . '</p>';
                echo '<p style="margin:0 0 6px;"><strong>Status:</strong> <span style="color:' . $color . ';font-weight:bold;text-transform:uppercase;font-size:11px;">' . esc_html( $campaign->status ) . '</span></p>';

                if ( $campaign->recipients_count ) {
                    echo '<p style="margin:0 0 6px;"><strong>Recipients:</strong> ' . esc_html( $campaign->recipients_count ) . '</p>';
                }

                if ( $campaign->scheduled_at ) {
                    // scheduled_at is stored in LOCAL time (not UTC), so read it directly in local tz
                    $send_dt = new DateTime( $campaign->scheduled_at, new DateTimeZone( LG_ER_TIMEZONE ) );
                    echo '<p style="margin:0 0 12px;"><strong>Sends at:</strong><br>' . esc_html( $send_dt->format( 'D M j, Y \a\t g:i A T' ) ) . '</p>';
                }

                if ( in_array( $campaign->status, [ 'scheduled', 'draft', 'paused' ], true ) ) {
                    $cancel_url = wp_nonce_url(
                        admin_url( 'admin-post.php?action=lg_er_cancel_campaign&post_id=' . $post->ID ),
                        'lg_er_cancel_' . $post->ID
                    );
                    echo '<a href="' . esc_url( $cancel_url ) . '" class="button button-small" style="color:#d63638;margin-bottom:12px;" '
                       . 'onclick="return confirm(\'Cancel and delete this reminder campaign?\')">Cancel Reminder</a>';
                }
            }
        }
    }

    // Test fire button — always visible on published events
    if ( $post->post_status === 'publish' ) {
        $test_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=lg_er_test_campaign&post_id=' . $post->ID ),
            'lg_er_test_' . $post->ID
        );
        echo '<div style="border-top:1px solid #ddd;padding-top:10px;margin-top:10px;">';
        echo '<a href="' . esc_url( $test_url ) . '" class="button button-small" style="background:#f0ad4e;border-color:#eea236;color:#fff;" '
           . 'onclick="return confirm(\'Send a test reminder to the \\&quot;' . esc_js( LG_ER_TEST_TAG ) . '\\&quot; tag? It will fire in ~2 minutes.\')">Send Test Reminder</a>';
        echo '<p style="margin:6px 0 0;font-size:11px;color:#999;">Sends to "' . esc_html( LG_ER_TEST_TAG ) . '" tag only.</p>';
        echo '</div>';
    }
}

add_action( 'admin_post_lg_er_cancel_campaign', function () {
    $post_id = intval( $_GET['post_id'] ?? 0 );
    check_admin_referer( 'lg_er_cancel_' . $post_id );
    if ( ! current_user_can( 'edit_post', $post_id ) ) wp_die( 'Unauthorized' );
    lg_er_delete_campaign_for_post( $post_id );
    wp_redirect( get_edit_post_link( $post_id, 'redirect' ) . '&lg_er_cancelled=1' );
    exit;
} );

add_action( 'admin_notices', function () {
    if ( isset( $_GET['lg_er_cancelled'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Event reminder campaign cancelled and removed from FluentCRM.</p></div>';
    }
    if ( isset( $_GET['lg_er_test_sent'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>🧪 Test reminder campaign scheduled! Check your email in ~2 minutes.</p></div>';
    }
    if ( isset( $_GET['lg_er_test_error'] ) ) {
        $err = sanitize_text_field( urldecode( $_GET['lg_er_test_error'] ) );
        echo '<div class="notice notice-error is-dismissible"><p>Test reminder failed: ' . esc_html( $err ) . '</p></div>';
    }
} );

// ─────────────────────────────────────────────
// TEST FIRE — Send a test campaign to the test tag
// ─────────────────────────────────────────────

add_action( 'admin_post_lg_er_test_campaign', function () {
    $post_id = intval( $_GET['post_id'] ?? 0 );
    check_admin_referer( 'lg_er_test_' . $post_id );
    if ( ! current_user_can( 'edit_post', $post_id ) ) wp_die( 'Unauthorized' );

    $redirect_base = get_edit_post_link( $post_id, 'redirect' );

    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== LG_ER_POST_TYPE ) {
        wp_redirect( $redirect_base . '&lg_er_test_error=' . urlencode( 'Invalid event post.' ) );
        exit;
    }

    // Delete any previous test campaign for this event
    $old_test_id = get_post_meta( $post_id, LG_ER_TEST_CAMPAIGN_META, true );
    if ( $old_test_id && class_exists( '\FluentCrm\App\Models\Campaign' ) ) {
        $old = \FluentCrm\App\Models\Campaign::find( $old_test_id );
        if ( $old && ! in_array( $old->status, [ 'sent', 'working' ], true ) ) {
            if ( class_exists( '\FluentCrm\App\Models\CampaignEmail' ) ) {
                \FluentCrm\App\Models\CampaignEmail::where( 'campaign_id', $old_test_id )->delete();
            }
            $old->delete();
        }
        delete_post_meta( $post_id, LG_ER_TEST_CAMPAIGN_META );
    }

    // Check FluentCRM
    if ( ! class_exists( '\FluentCrm\App\Models\Campaign' ) || ! class_exists( '\FluentCrm\App\Models\Tag' ) ) {
        wp_redirect( $redirect_base . '&lg_er_test_error=' . urlencode( 'FluentCRM not available.' ) );
        exit;
    }

    // Resolve test tag
    $tag = \FluentCrm\App\Models\Tag::where( 'title', LG_ER_TEST_TAG )->first();
    if ( ! $tag ) {
        wp_redirect( $redirect_base . '&lg_er_test_error=' . urlencode( 'Tag "' . LG_ER_TEST_TAG . '" not found. Create it in FluentCRM > Tags and add yourself.' ) );
        exit;
    }

    // Read event fields for email content
    $tz        = new DateTimeZone( LG_ER_TIMEZONE );
    $event_dt  = lg_er_get_event_datetime( $post_id, $tz );
    $date_nice = $event_dt ? $event_dt->format( 'l, F j, Y' ) : 'TBD';
    $time_nice = $event_dt ? $event_dt->format( 'g:i A' ) : 'TBD';

    // UTC conversion for international subscribers
    $time_utc = 'TBD';
    if ( $event_dt ) {
        $event_dt_utc = clone $event_dt;
        $event_dt_utc->setTimezone( new DateTimeZone( 'UTC' ) );
        $time_utc = $event_dt_utc->format( 'g:i A' );
    }

    $event_title = $post->post_title;
    $event_url   = get_permalink( $post_id );
    $thumb_id    = get_post_thumbnail_id( $post_id );
    $thumb_url   = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';

    $subject = '[TEST] ⏰ 1 hour before: ' . $event_title . ' at ' . $time_nice . ' ET / ' . $time_utc . ' UTC';
    $body    = lg_er_build_email_body( $event_title, $date_nice, $time_nice, $event_url, $thumb_url, $time_utc );

    // Schedule 2 minutes from now — FluentCRM expects local (WordPress) time, not UTC
    $send_dt = new DateTime( 'now', new DateTimeZone( LG_ER_TIMEZONE ) );
    $send_dt->modify( '+2 minutes' );
    $scheduled_at = $send_dt->format( 'Y-m-d H:i:s' );

    $campaign_data = [
        'title'            => '[TEST] Event Reminder: ' . $event_title,
        'slug'             => sanitize_title( 'lg-test-reminder-' . $post_id . '-' . time() ),
        'type'             => 'campaign',
        'status'           => 'scheduled',
        'email_subject'    => $subject,
        'email_pre_header' => '[TEST] ' . $event_title . ' reminder',
        'email_body'       => $body,
        'scheduled_at'     => $scheduled_at,
        'design_template'  => 'raw_classic',
        'created_by'       => get_current_user_id() ?: 1,
        'settings'         => [
            'mailer_settings' => [
                'from_name'      => LG_ER_FROM_NAME,
                'from_email'     => LG_ER_FROM_EMAIL,
                'reply_to_name'  => LG_ER_FROM_NAME,
                'reply_to_email' => LG_ER_FROM_EMAIL,
                'is_custom'      => 'yes',
            ],
            'subscribers'         => [
                [ 'list' => LG_ER_FCRM_LIST_ID, 'tag' => (string) $tag->id ],
            ],
            'excludedSubscribers' => null,
            'sending_filter'      => 'list_tag',
            'dynamic_segment'     => [ 'id' => '', 'slug' => '' ],
            'advanced_filters'    => [ [] ],
        ],
    ];

    try {
        $campaign = \FluentCrm\App\Models\Campaign::create( $campaign_data );
    } catch ( \Exception $e ) {
        wp_redirect( $redirect_base . '&lg_er_test_error=' . urlencode( $e->getMessage() ) );
        exit;
    }

    if ( ! $campaign || ! $campaign->id ) {
        wp_redirect( $redirect_base . '&lg_er_test_error=' . urlencode( 'Campaign creation returned empty.' ) );
        exit;
    }

    // Subscribe test recipients
    try {
        $campaign_settings = $campaign->settings;
        if ( is_string( $campaign_settings ) ) {
            $campaign_settings = json_decode( $campaign_settings, true );
        }
        if ( ! is_array( $campaign_settings ) ) {
            $campaign_settings = [];
        }

        $id_result      = $campaign->getSubscriberIdsBySegmentSettings( $campaign_settings );
        $subscriber_ids = [];
        if ( is_array( $id_result ) && isset( $id_result['subscriber_ids'] ) ) {
            $subscriber_ids = $id_result['subscriber_ids'];
        } elseif ( is_array( $id_result ) ) {
            $subscriber_ids = $id_result;
        }

        if ( ! empty( $subscriber_ids ) ) {
            $campaign->subscribe( $subscriber_ids );
            // CRITICAL: Set scheduled_at on CampaignEmail rows so the mailer picks them up
            if ( class_exists( '\FluentCrm\App\Models\CampaignEmail' ) ) {
                \FluentCrm\App\Models\CampaignEmail::where( 'campaign_id', $campaign->id )
                    ->update( [ 'scheduled_at' => $scheduled_at ] );
                $count = \FluentCrm\App\Models\CampaignEmail::where( 'campaign_id', $campaign->id )->count();
                $campaign->recipients_count = $count;
                $campaign->save();
            }
        }
    } catch ( \Exception $e ) {
        lg_er_log( 'Test campaign recipient subscription failed: ' . $e->getMessage(), 'error' );
    }

    update_post_meta( $post_id, LG_ER_TEST_CAMPAIGN_META, $campaign->id );
    lg_er_log( sprintf( '🧪 Test campaign #%d created for event #%d, fires at %s (local)', $campaign->id, $post_id, $scheduled_at ) );

    wp_redirect( $redirect_base . '&lg_er_test_sent=1' );
    exit;
} );

// ─────────────────────────────────────────────
// AUTO-ARCHIVE PAST EVENTS
// Lifecycle: Published → Archived (1 day after event ends)
// ─────────────────────────────────────────────

// Post meta key to track when an event was moved to draft by our cleanup
define( 'LG_ER_DRAFTED_AT_META', '_lg_er_auto_drafted_at' );

// Schedule the cron event on plugin activation
register_activation_hook( __FILE__, 'lg_er_schedule_cleanup_cron' );
function lg_er_schedule_cleanup_cron() {
    if ( ! wp_next_scheduled( 'lg_er_cleanup_past_events' ) ) {
        wp_schedule_event( time(), 'twicedaily', 'lg_er_cleanup_past_events' );
    }
}

// Unschedule on deactivation
register_deactivation_hook( __FILE__, 'lg_er_unschedule_cleanup_cron' );
function lg_er_unschedule_cleanup_cron() {
    wp_clear_scheduled_hook( 'lg_er_cleanup_past_events' );
}

// Also ensure the cron is scheduled if plugin is already active (covers manual file updates)
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'lg_er_cleanup_past_events' ) ) {
        wp_schedule_event( time(), 'twicedaily', 'lg_er_cleanup_past_events' );
    }
} );

// The cron callback
add_action( 'lg_er_cleanup_past_events', 'lg_er_process_past_events' );

function lg_er_process_past_events() {

    $tz  = new DateTimeZone( LG_ER_TIMEZONE );
    $now = new DateTime( 'now', $tz );

    lg_er_log( 'Running past-event cleanup at ' . $now->format( 'Y-m-d H:i T' ) );

    // ── Step 1: Move published events to Draft if 1+ day past ────────

    $published_events = get_posts( [
        'post_type'      => LG_ER_POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    $drafted_count = 0;

    foreach ( $published_events as $post_id ) {
        $event_dt = lg_er_get_event_datetime( $post_id, $tz );
        if ( ! $event_dt ) continue;

        // Check if event date has passed (archive at midnight the next day)
        $cutoff = clone $event_dt;
        $cutoff->setTime( 0, 0, 0 );
        $cutoff->modify( '+1 day' );

        if ( $now > $cutoff ) {
            // Move to archived
            wp_update_post( [
                'ID'          => $post_id,
                'post_status' => 'archived',
            ] );

            // Record when we drafted it
            update_post_meta( $post_id, LG_ER_DRAFTED_AT_META, $now->format( 'Y-m-d H:i:s' ) );

            $drafted_count++;
            lg_er_log( 'Auto-archived event #' . $post_id . ' (event was ' . $event_dt->format( 'Y-m-d H:i' ) . ')' );
        }
    }

    if ( $drafted_count === 0 ) {
        lg_er_log( 'No past events to clean up.' );
    } else {
        lg_er_log( sprintf( 'Cleanup complete: %d drafted.', $drafted_count ) );
    }
}

/**
 * Helper: Parse event date+time into a DateTime object.
 */
function lg_er_get_event_datetime( $post_id, $tz ) {
    $date_raw = lg_er_get_field_value( LG_ER_DATE_FIELD, $post_id );
    $time_raw = lg_er_get_field_value( LG_ER_TIME_FIELD, $post_id );

    if ( ! $date_raw || ! $time_raw ) return null;

    $date_raw = str_replace( '-', '', $date_raw );

    $formats = [ 'Ymd g:i a', 'Ymd G:i', 'Ymd H:i', 'Ymd h:i A', 'Ymd g:iA', 'Ymd h:iA' ];
    foreach ( $formats as $fmt ) {
        $dt = DateTime::createFromFormat( $fmt, $date_raw . ' ' . $time_raw, $tz );
        if ( $dt ) return $dt;
    }

    return null;
}
