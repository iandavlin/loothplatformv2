<?php
/**
 * REMEDIATION #2 — backfill the legacy unmatched patron accounts.
 * Settled-design item 6. PREPARED FOR LIVE — Ian runs this by hand.
 *
 * Background: ~30 active patrons have a same-name WP account with a BLANK email,
 * plus 2 with a DIFFERENT email. The old sweep bridged purely by email, so these
 * never matched -> never got a role. This branch re-keys the sweep on the stable
 * Patreon user ID, but these legacy accounts have no `lgpo_patreon_user_id` meta
 * yet AND don't match by email, so the sweep can't link them on its own. This
 * one-time backfill name-matches them, stamps the Patreon ID, mirrors the email
 * (uniqueness-checked), and runs the Arbiter so the role lands.
 *
 * Run (as the WP system user on live):
 *   wp eval-file backfill-blank-emails.php             # REVIEW — prints proposed links, writes nothing
 *   wp eval-file backfill-blank-emails.php apply        # APPLY the proposals
 *
 * Matching logic per active Patreon member in the campaign roster:
 *   1. already linked by lgpo_patreon_user_id -> skip (sweep handles it)
 *   2. matches a WP user by email             -> skip (sweep handles it)
 *   3. else name-match: normalize full_name vs WP display_name/login among
 *      candidate accounts (blank-email first, then any). Propose a link ONLY on
 *      a UNIQUE name match — ambiguous (0 or 2+) matches are listed for manual
 *      review and never auto-applied.
 *
 * On apply, for a proposed link:
 *   - stamp lgpo_patreon_user_id (+ lgpo_patreon_email)
 *   - set user_email = Patreon email, BUT ONLY if no different WP user already
 *     holds it (uniqueness collision -> skip the email, keep the link, flag it)
 *   - report the Patreon tier via the arbiter so the role is applied
 *
 * Safety: never deletes/creates users; never touches admins; email writes are
 * uniqueness-guarded; idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'LGPO_Sync_Engine' ) ) { echo "LGPO_Sync_Engine missing — abort.\n"; return; }

$APPLY = in_array( 'apply', $args ?? [], true );

$norm = function ( $s ) {
    $s = strtolower( trim( (string) $s ) );
    $s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
    return trim( preg_replace( '/\s+/', ' ', $s ) );
};

echo "Fetching Patreon roster…\n";
$roster = LGPO_Sync_Engine::fetch_member_roster();
if ( $roster === null ) {
    echo "Roster fetch FAILED (creator token / campaign / API). Fix config and retry.\n";
    return;
}
printf( "Roster: %d members. Mode: %s\n\n", count( $roster ), $APPLY ? 'APPLY' : 'REVIEW (no writes)' );

// Index WP users by normalized name for name-matching.
$wp_by_name = [];
foreach ( get_users( [ 'fields' => [ 'ID', 'display_name', 'user_login', 'user_email' ] ] ) as $u ) {
    foreach ( array_unique( [ $norm( $u->display_name ), $norm( $u->user_login ) ] ) as $key ) {
        if ( $key !== '' ) { $wp_by_name[ $key ][] = (int) $u->ID; }
    }
}

$tier_map = get_option( 'lgpo_tier_map', [] );
$proposals = [];
$ambiguous = [];

foreach ( $roster as $m ) {
    if ( ( $m['patron_status'] ?? '' ) !== 'active_patron' ) { continue; }
    $pid   = (string) ( $m['patreon_user_id'] ?? '' );
    $email = strtolower( trim( (string) ( $m['email'] ?? '' ) ) );
    $name  = (string) ( $m['full_name'] ?? '' );

    // 1) already linked by id?
    if ( $pid !== '' ) {
        $linked = get_users( [ 'meta_key' => 'lgpo_patreon_user_id', 'meta_value' => $pid, 'number' => 1, 'fields' => 'ID' ] );
        if ( $linked ) { continue; }
    }
    // 2) matches by email?
    if ( $email !== '' && get_user_by( 'email', $email ) ) { continue; }

    // 3) name match — require a multi-token name (first + last). Single-token
    // names ("Ian") over-match on common first names and produce false links,
    // so they are routed to manual review instead of auto-proposed.
    $key = $norm( $name );
    if ( $key === '' || count( explode( ' ', $key ) ) < 2 ) {
        $ambiguous[] = [ 'name' => $name, 'email' => $email, 'pid' => $pid, 'candidates' => [], 'note' => 'single-token name — review by hand' ];
        continue;
    }
    $cands = isset( $wp_by_name[ $key ] ) ? array_values( array_unique( $wp_by_name[ $key ] ) ) : [];
    // Prefer blank-email candidates when several share a name.
    $blank = array_values( array_filter( $cands, fn( $cid ) => trim( (string) get_userdata( $cid )->user_email ) === '' ) );
    $pick  = count( $blank ) === 1 ? $blank : $cands;

    if ( count( $pick ) === 1 ) {
        $proposals[] = [ 'wp' => $pick[0], 'pid' => $pid, 'email' => $email, 'name' => $name, 'member' => $m ];
    } else {
        $ambiguous[] = [ 'name' => $name, 'email' => $email, 'pid' => $pid, 'candidates' => $cands ];
    }
}

echo "PROPOSED LINKS (unique name match):\n";
foreach ( $proposals as $p ) {
    $u = get_userdata( $p['wp'] );
    printf( "  patreon %s \"%s\" <%s>  ->  WP #%d %s <%s>\n",
        $p['pid'] ?: '?', $p['name'], $p['email'] ?: '(blank)',
        $u->ID, $u->user_login, $u->user_email ?: '(blank)' );
}
printf( "\nAMBIGUOUS / NO MATCH (manual review — %d):\n", count( $ambiguous ) );
foreach ( $ambiguous as $a ) {
    printf( "  \"%s\" <%s> patreon %s  -> candidates: [%s]%s\n",
        $a['name'], $a['email'] ?: '(blank)', $a['pid'] ?: '?', implode( ',', $a['candidates'] ),
        isset( $a['note'] ) ? '  (' . $a['note'] . ')' : '' );
}

if ( ! $APPLY ) {
    printf( "\nREVIEW ONLY — %d proposals. Re-run with `apply` to execute.\n", count( $proposals ) );
    return;
}

echo "\nApplying…\n";
$ok = 0; $coll = 0;
foreach ( $proposals as $p ) {
    $id = (int) $p['wp'];
    $u  = get_userdata( $id );
    if ( user_can( $u, 'manage_options' ) ) { echo "  skip admin #{$id}\n"; continue; }

    if ( $p['pid'] !== '' ) {
        update_user_meta( $id, 'lgpo_patreon_user_id', sanitize_text_field( $p['pid'] ) );
    }
    // email mirror, uniqueness-checked
    if ( $p['email'] !== '' && is_email( $p['email'] ) ) {
        $owner = get_user_by( 'email', $p['email'] );
        if ( $owner && (int) $owner->ID !== $id ) {
            $coll++;
            echo "  COLLISION #{$id}: {$p['email']} held by #{$owner->ID} — email NOT written (link kept).\n";
            if ( function_exists( 'lgpo_notify_failure' ) ) {
                lgpo_notify_failure( $p['email'], $p['name'], 'backfill.email_collision',
                    "Backfill: {$p['email']} already on WP #{$owner->ID}; left WP #{$id} email unchanged.", $id );
            }
        } else {
            wp_update_user( [ 'ID' => $id, 'user_email' => $p['email'] ] );
            update_user_meta( $id, 'lgpo_patreon_email', $p['email'] );
            // Freeze the identity uuid so /whoami resolves on this backfilled
            // account (else: role present but anon at the identity layer).
            if ( method_exists( 'LGPO_Sync_Engine', 'stamp_looth_uuid' ) ) {
                LGPO_Sync_Engine::stamp_looth_uuid( $id, $p['email'] );
            }
        }
    }
    // apply role via the same snapshot path the sweep uses, then arbiter
    if ( method_exists( 'LGPO_Sync_Engine', 'record_patreon_member' ) ) {
        LGPO_Sync_Engine::record_patreon_member( $id, $p['member'] );
    }
    update_user_meta( $id, 'payment_source', 'patreon' );
    // resolve tier from the entitled tier id
    $tier_id = $p['member']['tier_ids'][0] ?? '';
    $role    = ( $tier_id && isset( $tier_map[ $tier_id ] ) ) ? $tier_map[ $tier_id ] : 'looth1';
    if ( function_exists( 'lgpo_apply_role_via_arbiter' ) ) {
        lgpo_apply_role_via_arbiter( $id, $role );
    }
    $after = array_values( array_intersect( [ 'looth1','looth2','looth3','looth4' ], (array) get_userdata( $id )->roles ) );
    printf( "  linked #%d -> role [%s]\n", $id, implode( ',', $after ) );
    $ok++;
}
printf( "\nDone. Linked: %d, collisions skipped: %d, ambiguous left: %d.\n", $ok, $coll, count( $ambiguous ) );
