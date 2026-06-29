<?php
/**
 * REMEDIATION #3 — reconcile the skeleton `patreon_<id>` accounts.
 * Sibling of dedupe-multirole.php / backfill-blank-emails.php. PREPARED FOR LIVE
 * — Ian runs this by hand; it is NOT wired into cron.
 *
 * Background: a bulk import / DB-reload created ~80 paying patrons as SKELETON
 * accounts — `user_login = patreon_<id>` (the Patreon user id is IN the username)
 * but the `lgpo_patreon_user_id` meta was never written and `user_email` left
 * blank. The hourly sweep (LGPO_Sync_Engine::compare_member) keys on that META
 * (then email), so these accounts are invisible to it: never linked, never get
 * their email mirrored, never get a role — hard-locked out. ~149 more carry an
 * email but still no meta, so the sweep can only reach them by an email match
 * that may not hold; stamping the meta re-keys them onto the stable id.
 *
 * The Patreon id is recoverable DETERMINISTICALLY by parsing the username, so
 * this is not a fuzzy name-match (that's backfill-blank-emails.php's job for the
 * *non*-skeleton legacy accounts) — it's an exact id parse, roster-confirmed.
 *
 * Modes (mirror the sibling review/apply pattern):
 *   wp eval-file reconcile-patreon-skeletons.php                  # REVIEW  — prints proposals, writes nothing
 *   wp eval-file reconcile-patreon-skeletons.php apply            # APPLY   — writes + records a reversible journal
 *   wp eval-file reconcile-patreon-skeletons.php revert <batch>   # REVERT  — undo a specific apply batch
 *
 * Per skeleton account (user_login REGEXP '^patreon_[0-9]+$' AND missing meta;
 * admins and the non-matching placeholders `deleted-member` / the `670aa65904420`
 * hex-timestamp oddball are excluded by construction):
 *
 *   pid ALREADY held by another WP account (lgpo_patreon_user_id meta)
 *     -> DUPLICATE: that account is the canonical home (the Patreon id is the
 *        identity source of truth; typically the newer fully-onboarded account).
 *        The skeleton is a stale duplicate — REPORT it for manual retirement and
 *        write NOTHING. Checked before roster classification, so a dup is never
 *        linked even if its id happens to be in the roster.
 *
 *   pid in active roster
 *     -> LINK: stamp lgpo_patreon_user_id, mirror user_email from the roster
 *        (reusing sync_wp_email's guards — skip on a uniqueness collision and
 *        flag, freeze _looth_uuid so /whoami resolves), and write the
 *        lg_patreon_members snapshot (record_patreon_member) so Manage Account
 *        renders ACTIVE immediately. The ROLE is intentionally NOT applied here:
 *        once the meta is stamped the next hourly sweep sees the account and
 *        grants the correct tier — which keeps this script's writes (meta +
 *        email + row) exactly the set the journal can reverse.
 *
 *   pid NOT in roster, blank email
 *     -> FLAG (lapsed / left Patreon). Write NOTHING — do not blind-link an
 *        account we can't confirm is still a paying member, and with no roster
 *        email there's nothing to mirror.
 *
 *   pid NOT in roster, email present
 *     -> STAMP META only (the ~149): stamp lgpo_patreon_user_id so every future
 *        sweep keys on the stable id. No email / row / role (not an active member
 *        in this roster; the sweep maintains them once it can see the id).
 *
 * Safety: never deletes / creates users; never touches admins; email writes are
 * uniqueness-guarded; idempotent (already-linked skeletons are skipped by the
 * missing-meta filter, so re-running apply is a no-op); fully reversible (see
 * `revert`). The role is left to the sweep, so this script grants no access on
 * its own that a revert couldn't fully undo.
 */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
if ( ! class_exists( 'LGPO_Sync_Engine' ) ) { echo "LGPO_Sync_Engine missing — abort.\n"; return; }

global $wpdb;

$ARGS = $args ?? [];
$MODE = 'review';
$BATCH_ARG = '';
if ( in_array( 'revert', $ARGS, true ) ) {
    $MODE = 'revert';
    $ri   = array_search( 'revert', $ARGS, true );
    $BATCH_ARG = (string) ( $ARGS[ $ri + 1 ] ?? '' );
} elseif ( in_array( 'apply', $ARGS, true ) ) {
    $MODE = 'apply';
}

const RECON_JOURNAL_PREFIX = 'lgpo_reconcile_journal_';
const RECON_INDEX_OPT      = 'lgpo_reconcile_batches';

/* ----------------------------------------------------------------------------
 * Small helpers
 * --------------------------------------------------------------------------*/

// Stamp a meta key, returning the journal record of its PRIOR state so revert
// can restore it exactly (delete if it was absent, restore the old value if not).
$journal_meta_set = function ( int $id, string $key, string $val ) {
    $had  = metadata_exists( 'user', $id, $key );
    $prev = $had ? (string) get_user_meta( $id, $key, true ) : '';
    update_user_meta( $id, $key, $val );
    return [ 'existed' => $had, 'prev' => $prev ];
};

// Does an lg_patreon_members row already exist for this WP user? Used to decide
// whether the upsert INSERTED (revert deletes) or merely UPDATED (revert leaves).
$members_row_exists = function ( int $id ): bool {
    try {
        $stmt = \LGMS\Db::pdo()->prepare( 'SELECT 1 FROM lg_patreon_members WHERE wp_user_id = ? LIMIT 1' );
        $stmt->execute( [ $id ] );
        return (bool) $stmt->fetchColumn();
    } catch ( \Throwable $e ) {
        // DB unreachable: record_patreon_member will no-op (it try/catches), so
        // nothing is inserted -> safest to report "exists" so revert won't try
        // to delete a row that was never written.
        return true;
    }
};

/* ----------------------------------------------------------------------------
 * REVERT MODE
 * --------------------------------------------------------------------------*/

if ( $MODE === 'revert' ) {
    if ( $BATCH_ARG === '' ) {
        echo "Usage: wp eval-file reconcile-patreon-skeletons.php revert <batch-id>\n\n";
        $index = get_option( RECON_INDEX_OPT, [] );
        if ( empty( $index ) ) { echo "No apply batches on record.\n"; return; }
        echo "Known batches:\n";
        foreach ( $index as $bid => $meta ) {
            $j = get_option( RECON_JOURNAL_PREFIX . $bid, [] );
            $state = ! empty( $j['reverted_at'] ) ? 'REVERTED ' . $j['reverted_at'] : 'active';
            printf( "  %s  created %s  accounts=%d  [%s]\n", $bid, $meta['created'] ?? '?', $meta['count'] ?? 0, $state );
        }
        return;
    }

    $j = get_option( RECON_JOURNAL_PREFIX . $BATCH_ARG, null );
    if ( ! is_array( $j ) || empty( $j['entries'] ) ) {
        echo "No journal found for batch '{$BATCH_ARG}'.\n";
        return;
    }
    if ( ! empty( $j['reverted_at'] ) ) {
        echo "Batch '{$BATCH_ARG}' was already reverted at {$j['reverted_at']} — no-op.\n";
        return;
    }

    printf( "=== REVERT batch %s (applied %s, %d accounts) ===\n\n", $BATCH_ARG, $j['created'] ?? '?', count( $j['entries'] ) );
    $n_meta = 0; $n_email = 0; $n_row = 0;

    foreach ( $j['entries'] as $e ) {
        $id = (int) $e['user_id'];
        $u  = get_userdata( $id );
        printf( "  #%-6d %s\n", $id, $u ? $u->user_login : '(gone)' );

        // meta — restore each key to its captured prior state
        foreach ( (array) ( $e['meta'] ?? [] ) as $key => $info ) {
            if ( empty( $info['existed'] ) ) {
                delete_user_meta( $id, $key );
                echo "      cleared meta {$key}\n"; $n_meta++;
            } else {
                update_user_meta( $id, $key, $info['prev'] );
                echo "      restored meta {$key} = '{$info['prev']}'\n"; $n_meta++;
            }
        }

        // email — restore prior value (direct write so a blank prior restores
        // faithfully; wp_update_user rejects an empty email on update).
        if ( ! empty( $e['email']['changed'] ) ) {
            $prev = (string) $e['email']['prev'];
            $wpdb->update( $wpdb->users, [ 'user_email' => $prev ], [ 'ID' => $id ] );
            clean_user_cache( $id );
            echo "      restored email = '" . ( $prev === '' ? '(blank)' : $prev ) . "'\n"; $n_email++;
        }

        // membership row — delete ONLY if this apply inserted it
        if ( ! empty( $e['row_inserted'] ) ) {
            try {
                \LGMS\Db::pdo()->prepare( 'DELETE FROM lg_patreon_members WHERE wp_user_id = ?' )->execute( [ $id ] );
                echo "      deleted lg_patreon_members row\n"; $n_row++;
            } catch ( \Throwable $ex ) {
                echo "      ROW DELETE FAILED: " . $ex->getMessage() . "\n";
            }
        }
    }

    $j['reverted_at'] = current_time( 'mysql' );
    update_option( RECON_JOURNAL_PREFIX . $BATCH_ARG, $j, false );

    printf( "\nReverted batch %s — meta restores: %d, emails restored: %d, rows deleted: %d.\n", $BATCH_ARG, $n_meta, $n_email, $n_row );
    echo "NOTE: any tier role a sweep granted from the now-cleared meta is NOT undone here\n";
    echo "      (this script never applies roles). If a sweep already ran on this batch,\n";
    echo "      run dedupe-multirole.php / the arbiter to settle roles after reverting.\n";
    return;
}

/* ----------------------------------------------------------------------------
 * REVIEW / APPLY — fetch roster, enumerate skeletons, classify
 * --------------------------------------------------------------------------*/

echo "Fetching Patreon roster…\n";
$roster = LGPO_Sync_Engine::fetch_member_roster();
// Override seam: lets a dev box (no creator token) inject a synthetic roster to
// verify the apply/revert paths end-to-end. No listener on live -> pure
// pass-through of the real roster.
$roster = apply_filters( 'lgpo_reconcile_roster', $roster );
if ( ! is_array( $roster ) ) {
    echo "Roster fetch FAILED (creator token / campaign / API). Fix config and retry.\n";
    return;
}

// Index the active roster by Patreon user id. Note: the roster normalizer drops
// members with no email on Patreon's side, so an active patron whose Patreon
// account exposes no email would read here as 'not in roster' (-> flagged, never
// mis-linked). That's the safe failure direction.
$by_pid = [];
$active_pids = 0;
foreach ( $roster as $m ) {
    $pid = (string) ( $m['patreon_user_id'] ?? '' );
    if ( $pid === '' ) { continue; }
    $by_pid[ $pid ] = $m;
    if ( ( $m['patron_status'] ?? '' ) === 'active_patron' ) { $active_pids++; }
}
printf( "Roster: %d members (%d active). Mode: %s\n\n", count( $roster ), $active_pids, $MODE === 'apply' ? 'APPLY' : 'REVIEW (no writes)' );

// Enumerate every patreon_<id> account; the missing-meta filter is applied in
// PHP so an EMPTY meta value counts as missing too (get_user_meta returns '').
$rows = $wpdb->get_results( "SELECT ID, user_login, user_email FROM {$wpdb->users} WHERE user_login REGEXP '^patreon_[0-9]+$' ORDER BY ID" );

$link = []; $flag = []; $stamp = []; $dup = []; $skip_admin = []; $already = 0;

// Pre-index every Patreon id already claimed by a WP account (the identity source
// of truth). A skeleton whose id is already held elsewhere is a DUPLICATE — the
// other account (typically the newer, fully-onboarded one) is the canonical home;
// re-stamping the stale skeleton would split identity again. So: never link it.
$claimed_pid = [];
foreach ( $wpdb->get_results( "SELECT user_id, meta_value AS v FROM {$wpdb->usermeta} WHERE meta_key = 'lgpo_patreon_user_id' AND meta_value <> ''" ) as $cr ) {
    $claimed_pid[ (string) $cr->v ][] = (int) $cr->user_id;
}

foreach ( $rows as $r ) {
    $id = (int) $r->ID;
    if ( (string) get_user_meta( $id, 'lgpo_patreon_user_id', true ) !== '' ) { $already++; continue; }
    $u = get_userdata( $id );
    if ( ! $u ) { continue; }
    if ( user_can( $u, 'manage_options' ) ) { $skip_admin[] = $id; continue; }
    if ( ! preg_match( '/^patreon_([0-9]+)$/', $u->user_login, $mm ) ) { continue; }

    $pid    = $mm[1];
    $blank  = ( trim( (string) $u->user_email ) === '' );
    $member = $by_pid[ $pid ] ?? null;
    $rec = [ 'id' => $id, 'pid' => $pid, 'login' => $u->user_login, 'email' => $u->user_email, 'blank' => $blank, 'member' => $member ];

    // Patreon id already claimed by another WP account? -> DUPLICATE, never link/stamp.
    $owner_ids = array_values( array_diff( $claimed_pid[ $pid ] ?? [], [ $id ] ) );
    if ( $owner_ids ) {
        $rec['owner'] = $owner_ids[0];
        $dup[] = $rec;
        continue;
    }

    if ( $member !== null ) {
        $link[] = $rec;
    } elseif ( $blank ) {
        $flag[] = $rec;
    } else {
        $stamp[] = $rec;
    }
}

// Diagnostic scan: blank-email + unlinked accounts that are NOT patreon_<id> —
// these are the edge cases the reconciler intentionally does NOT touch (the
// `deleted-member` placeholder, the `670aa65904420` hex-timestamp orphan, etc.).
$edge = [];
$edge_rows = $wpdb->get_results( "SELECT ID, user_login FROM {$wpdb->users} WHERE (user_email = '' OR user_email IS NULL) AND user_login NOT REGEXP '^patreon_[0-9]+$' ORDER BY ID" );
foreach ( (array) $edge_rows as $er ) {
    $eid = (int) $er->ID;
    if ( (string) get_user_meta( $eid, 'lgpo_patreon_user_id', true ) !== '' ) { continue; }
    $eu = get_userdata( $eid );
    if ( $eu && user_can( $eu, 'manage_options' ) ) { continue; }
    $edge[] = [ 'id' => $eid, 'login' => (string) $er->user_login ];
}

/* ----------------------------------------------------------------------------
 * Report
 * --------------------------------------------------------------------------*/

echo "LINK — id in active roster (stamp meta + mirror email + write membership row):\n";
foreach ( $link as $p ) {
    printf( "  #%-6d %-24s  patreon %s  email %s -> %s\n",
        $p['id'], $p['login'], $p['pid'],
        $p['blank'] ? '(blank)' : $p['email'],
        strtolower( trim( (string) ( $p['member']['email'] ?? '' ) ) ) ?: '(roster blank)' );
}
printf( "\nSTAMP-META — email present, id NOT in roster (re-key onto stable id; sweep maintains) — %d:\n", count( $stamp ) );
foreach ( array_slice( $stamp, 0, 25 ) as $p ) {
    printf( "  #%-6d %-24s  patreon %s  email %s\n", $p['id'], $p['login'], $p['pid'], $p['email'] );
}
if ( count( $stamp ) > 25 ) { printf( "  … and %d more\n", count( $stamp ) - 25 ); }

printf( "\nFLAG — blank email, id NOT in roster (lapsed/left Patreon — NOT linked) — %d:\n", count( $flag ) );
foreach ( $flag as $p ) {
    printf( "  #%-6d %-24s  patreon %s\n", $p['id'], $p['login'], $p['pid'] );
}

printf( "\nDUPLICATE — Patreon id ALREADY held by another WP account (skeleton is a stale dup; canonical = owner; NOT linked) — %d:\n", count( $dup ) );
foreach ( array_slice( $dup, 0, 25 ) as $p ) {
    printf( "  #%-6d %-24s  patreon %s  ->  canonical WP #%d\n", $p['id'], $p['login'], $p['pid'], $p['owner'] );
}
if ( count( $dup ) > 25 ) { printf( "  … and %d more\n", count( $dup ) - 25 ); }

printf( "\nEDGE — blank-email + unlinked, NOT a patreon_<id> username (manual review, untouched) — %d:\n", count( $edge ) );
foreach ( array_slice( $edge, 0, 25 ) as $p ) {
    printf( "  #%-6d %s\n", $p['id'], $p['login'] );
}
if ( count( $edge ) > 25 ) { printf( "  … and %d more\n", count( $edge ) - 25 ); }

if ( ! empty( $skip_admin ) ) { printf( "\nSKIPPED admins (never auto-linked): %s\n", implode( ', ', array_map( fn( $i ) => '#' . $i, $skip_admin ) ) ); }

printf( "\nSummary: LINK %d, STAMP-META %d, FLAG %d, DUPLICATE %d, EDGE %d, already-linked %d, admin-skip %d.\n",
    count( $link ), count( $stamp ), count( $flag ), count( $dup ), count( $edge ), $already, count( $skip_admin ) );

if ( $MODE !== 'apply' ) {
    echo "\nREVIEW ONLY — no writes. Re-run with `apply` to execute.\n";
    return;
}

/* ----------------------------------------------------------------------------
 * APPLY — write meta + email + row, journaling each account under a batch id
 * --------------------------------------------------------------------------*/

$batch = 'recon-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
$journal = [];
$n_link = 0; $n_stamp = 0; $n_coll = 0;

echo "\nApplying (batch {$batch})…\n";

// LINK group — full provision (meta + email + row).
foreach ( $link as $p ) {
    $id     = (int) $p['id'];
    $member = $p['member'];
    $entry  = [ 'user_id' => $id, 'login' => $p['login'], 'action' => 'link', 'meta' => [], 'email' => [ 'changed' => false, 'prev' => null ], 'row_inserted' => false ];

    // 1) stamp the Patreon-id link
    $entry['meta']['lgpo_patreon_user_id'] = $journal_meta_set( $id, 'lgpo_patreon_user_id', $p['pid'] );

    // 2) mirror the Patreon email (sync_wp_email guards: uniqueness + uuid stamp)
    $pemail = strtolower( trim( (string) ( $member['email'] ?? '' ) ) );
    if ( $pemail !== '' && is_email( $pemail ) ) {
        $cur = strtolower( trim( (string) get_userdata( $id )->user_email ) );
        $write_email = true;
        if ( $cur !== $pemail ) {
            $owner = get_user_by( 'email', $pemail );
            if ( $owner && (int) $owner->ID !== $id ) {
                $write_email = false; $n_coll++;
                printf( "  COLLISION #%d: %s held by #%d — email NOT written (link kept).\n", $id, $pemail, $owner->ID );
                if ( function_exists( 'lgpo_notify_failure' ) ) {
                    lgpo_notify_failure( $pemail, (string) ( $member['full_name'] ?? '' ), 'reconcile.email_collision',
                        "Reconciler: {$pemail} already on WP #{$owner->ID}; left WP #{$id} email unchanged.", $id );
                }
            } else {
                $entry['email'] = [ 'changed' => true, 'prev' => (string) get_userdata( $id )->user_email ];
                wp_update_user( [ 'ID' => $id, 'user_email' => $pemail ] );
            }
        }
        if ( $write_email ) {
            $entry['meta']['lgpo_patreon_email'] = $journal_meta_set( $id, 'lgpo_patreon_email', $pemail );
            $had_uuid = metadata_exists( 'user', $id, '_looth_uuid' ) && (string) get_user_meta( $id, '_looth_uuid', true ) !== '';
            LGPO_Sync_Engine::stamp_looth_uuid( $id, $pemail );
            if ( ! $had_uuid && (string) get_user_meta( $id, '_looth_uuid', true ) !== '' ) {
                $entry['meta']['_looth_uuid'] = [ 'existed' => false, 'prev' => '' ];
            }
        }
    }

    // 3) write the membership snapshot so Manage Account renders ACTIVE now
    $entry['row_inserted'] = ! $members_row_exists( $id );
    LGPO_Sync_Engine::record_patreon_member( $id, $member );

    $journal[ (string) $id ] = $entry;
    $n_link++;
    printf( "  linked #%d %s (role left to next sweep)\n", $id, $p['login'] );
}

// STAMP-META group — id meta only, so the sweep keys on the stable id.
foreach ( $stamp as $p ) {
    $id = (int) $p['id'];
    $entry = [ 'user_id' => $id, 'login' => $p['login'], 'action' => 'stamp_meta', 'meta' => [], 'email' => [ 'changed' => false, 'prev' => null ], 'row_inserted' => false ];
    $entry['meta']['lgpo_patreon_user_id'] = $journal_meta_set( $id, 'lgpo_patreon_user_id', $p['pid'] );
    $journal[ (string) $id ] = $entry;
    $n_stamp++;
}

// FLAG group — write nothing (recorded in the review output above).

// Persist the journal + index entry (autoload off — operational data).
update_option( RECON_JOURNAL_PREFIX . $batch, [ 'batch' => $batch, 'created' => current_time( 'mysql' ), 'entries' => $journal, 'reverted_at' => null ], false );
$index = get_option( RECON_INDEX_OPT, [] );
$index[ $batch ] = [ 'created' => current_time( 'mysql' ), 'count' => count( $journal ) ];
update_option( RECON_INDEX_OPT, $index, false );

printf( "\nDone. Linked %d, stamped-meta %d, collisions %d, flagged %d.\n", $n_link, $n_stamp, $n_coll, count( $flag ) );
printf( "Batch id: %s\n", $batch );
printf( "To undo this batch:  wp eval-file reconcile-patreon-skeletons.php revert %s\n", $batch );
echo "Roles: the next hourly sweep grants tiers to the LINK accounts now that the meta is stamped\n";
echo "       (or trigger it manually). Then smoke /whoami on each linked account.\n";
