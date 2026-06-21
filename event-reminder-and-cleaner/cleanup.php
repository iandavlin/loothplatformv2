<?php
/**
 * One-time cleanup of duplicate / orphan SCHEDULED event-reminder campaigns.
 *
 * Keeps at most ONE scheduled campaign per event (the newest), deletes the
 * rest and any orphans (event post missing / not a published 'event'), and
 * removes their wp_fc_campaign_emails rows. NEVER touches sent/working.
 *
 * Usage (run as the web user so model writes succeed):
 *   sudo -u looth-dev php cleanup.php            # DRY RUN (reports only)
 *   sudo -u looth-dev php cleanup.php --apply     # actually delete
 *
 * After the 3.4.0 plugin is live this is a one-shot; saves self-heal thereafter.
 */

define( 'WP_USE_THEMES', false );
require getenv( 'WP_LOAD' ) ?: '/var/www/dev/wp-load.php';

use FluentCrm\App\Models\Campaign;
use FluentCrm\App\Models\CampaignEmail;

$apply = in_array( '--apply', $argv, true );
echo $apply ? "MODE: APPLY (will delete)\n" : "MODE: DRY RUN (no changes; pass --apply to delete)\n";

$rows = Campaign::where( 'slug', 'LIKE', 'lg-event-reminder-%' )
    ->whereIn( 'status', [ 'scheduled', 'draft', 'paused' ] )
    ->get();

// Group by event post id parsed from the slug: lg-event-reminder-<postid>-<suffix>
$by_event = [];
foreach ( $rows as $c ) {
    if ( preg_match( '/^lg-event-reminder-(\d+)(?:-|$)/', $c->slug, $m ) ) {
        $by_event[ (int) $m[1] ][] = $c;
    }
}

$to_delete = [];
foreach ( $by_event as $post_id => $camps ) {
    $post = get_post( $post_id );
    $is_live_event = ( $post && $post->post_type === 'event' && $post->post_status === 'publish' );

    // newest id wins
    usort( $camps, fn( $a, $b ) => $b->id <=> $a->id );

    foreach ( $camps as $i => $c ) {
        if ( ! $is_live_event ) {
            $to_delete[] = [ $c, "orphan (event #$post_id not a published event)" ];
        } elseif ( $i > 0 ) {
            $to_delete[] = [ $c, "duplicate of kept #{$camps[0]->id} for event #$post_id" ];
        }
    }
    if ( $is_live_event ) {
        echo "event #$post_id: keep #{$camps[0]->id}" . ( count( $camps ) > 1 ? ", drop " . ( count( $camps ) - 1 ) : "" ) . "\n";
    }
}

if ( ! $to_delete ) {
    echo "Nothing to clean — 0 duplicates, 0 orphans.\n";
    return;
}

foreach ( $to_delete as [ $c, $why ] ) {
    $emails = CampaignEmail::where( 'campaign_id', $c->id )->count();
    echo ( $apply ? "DELETE" : "WOULD DELETE" ) . " campaign #{$c->id} ({$c->status}, {$emails} emails) — $why\n";
    if ( $apply ) {
        CampaignEmail::where( 'campaign_id', $c->id )->delete();
        $c->delete();
    }
}
echo ( $apply ? "Done. Deleted " : "Dry run. Would delete " ) . count( $to_delete ) . " campaign(s).\n";
