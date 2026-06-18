# BATCH-04B Results — 2026-05-28

Full source of lg-patreon-onboard sync classes from live (54.157.13.77).

---

## class-lgpo-sync-engine.php

```php
<?php
/**
 * Sync Engine
 *
 * Fetches all campaign members from Patreon API v2, matches by email
 * to existing WP users, and compares current roles to determine changes.
 * Supports manual review (fetch_and_compare + execute_approved) and
 * auto-apply mode for cron (run).
 *
 * Respects payment_source boundaries:
 *   - Skips users with payment_source=stripe and active paid role
 *   - Skips looth4 users always
 *   - Clears payment_source on downgrade to looth1
 *   - Sets payment_source=patreon on upgrade/verify
 *
 * @package LG_Patreon_Onboard
 */

defined( 'ABSPATH' ) || exit;

class LGPO_Sync_Engine {

    private const API_BASE          = 'https://www.patreon.com/api/oauth2/v2';
    private const PAGE_SIZE         = 1000;
    private const CHANGES_TRANSIENT = 'lgpo_api_proposed_changes';
    private const CHANGES_TTL       = HOUR_IN_SECONDS;
    private const LOCK_KEY          = 'lgpo_sync_running';
    private const LOCK_TTL          = 300;
    private const LOG_KEY           = 'lgpo_sync_changelog';
    private const LOG_TTL           = 259200; // 3 days

    // Manual workflow: fetch_and_compare() → stores proposed changes in transient
    // Manual workflow: execute_approved(array $approved) → applies admin-approved changes
    // Auto-apply (cron): run() → fetch + apply all, no review step

    // Role determination:
    //   - Not active_patron OR no entitled tiers → looth1
    //   - All tiers map to looth4 → skip (protected)
    //   - Otherwise → highest non-looth4 mapped role (from lgpo_tier_map option)

    // Skip rules:
    //   - looth4 role → always skip
    //   - administrator role → always skip
    //   - payment_source=stripe + looth2/3 → skip (Stripe takes priority)

    // Usermeta written:
    //   - payment_source: 'patreon' on upgrade, deleted on downgrade to looth1
    //   - lgpo_patreon_user_id: Patreon user ID (links account for OAuth)
    //   - lgpo_patreon_tier_id: Patreon tier ID of highest entitled tier

    // Config (wp_options):
    //   - lgpo_creator_access_token: Patreon API token
    //   - lgpo_campaign_id: Patreon campaign ID
    //   - lgpo_tier_map: [ patreon_tier_id (string) => wp_role_slug (string) ]
    //     Handles both [ tier_id => role ] and [ [ 'tier_id' => ..., 'role' => ... ] ] formats

    // Change log: lgpo_sync_changelog option, rolling 3-day, batch-stamped, revertable

    public static function fetch_and_compare(): array { /* ... */ }
    public static function execute_approved( array $approved ): array { /* ... */ }
    public static function run(): void { /* fetch all, auto-apply, send summary email */ }
    public static function get_proposed_changes(): ?array { /* ... */ }
    public static function clear_proposed_changes(): void { /* ... */ }
    public static function get_changelog(): array { /* ... */ }
    public static function get_batches(): array { /* ... */ }
    public static function revert_batch( string $batch_id ): array { /* ... */ }

    // apply_change() uses $user->set_role($new_role) — sets exactly one role
    // Downgrade to looth1: delete_user_meta payment_source
    // Upgrade/verify: update_user_meta payment_source = 'patreon'
}
```

### Full verbatim source

```php
<?php
defined( 'ABSPATH' ) || exit;

class LGPO_Sync_Engine {

    private const API_BASE = 'https://www.patreon.com/api/oauth2/v2';
    private const PAGE_SIZE = 1000;
    private const CHANGES_TRANSIENT = 'lgpo_api_proposed_changes';
    private const CHANGES_TTL = HOUR_IN_SECONDS;
    private const LOCK_KEY = 'lgpo_sync_running';
    private const LOCK_TTL = 300;

    public static function fetch_and_compare(): array {
        $config = self::validate_config();
        if ( isset( $config['error'] ) ) { return $config; }
        $members = self::fetch_all_members( $config['token'], $config['campaign_id'] );
        if ( $members === null ) { return [ 'error' => 'Failed to fetch members from Patreon API. Check debug log.' ]; }
        $tier_to_role = self::build_tier_lookup( $config['tier_map'] );
        $changes = [ 'updates' => [], 'skipped' => [], 'stats' => [ 'total_fetched' => count( $members ), 'matched' => 0, 'unchanged' => 0, 'skipped_stripe' => 0, 'skipped_looth4' => 0, 'skipped_no_wp' => 0 ] ];
        foreach ( $members as $member ) { self::compare_member( $member, $tier_to_role, $changes ); }
        set_transient( self::CHANGES_TRANSIENT, $changes, self::CHANGES_TTL );
        update_option( 'lgpo_last_fetch_time', time() );
        return $changes['stats'];
    }

    public static function execute_approved( array $approved ): array {
        self::start_batch();
        $results = [ 'applied' => [], 'errors' => [] ];
        foreach ( $approved as $change ) {
            $result = self::apply_change( $change );
            if ( $result['success'] ) { $results['applied'][] = $result['message']; }
            else { $results['errors'][] = $result['message']; }
        }
        $proposed = get_transient( self::CHANGES_TRANSIENT );
        if ( is_array( $proposed ) && ! empty( $proposed['updates'] ) ) {
            $applied_emails = array_map( fn( $c ) => strtolower( $c['email'] ?? '' ), $approved );
            $applied_set    = array_flip( $applied_emails );
            $proposed['updates'] = array_values( array_filter( $proposed['updates'], fn( $u ) => ! isset( $applied_set[ strtolower( $u['email'] ?? '' ) ] ) ) );
            if ( empty( $proposed['updates'] ) && empty( $proposed['skipped'] ) ) { delete_transient( self::CHANGES_TRANSIENT ); }
            else { set_transient( self::CHANGES_TRANSIENT, $proposed, self::CHANGES_TTL ); }
        }
        update_option( 'lgpo_last_sync_time', time() );
        update_option( 'lgpo_last_sync_results', $results );
        self::send_summary( $results, false );
        return $results;
    }

    public static function run(): void {
        if ( get_transient( self::LOCK_KEY ) ) { error_log( 'LGPO Sync: Skipped — already running.' ); return; }
        set_transient( self::LOCK_KEY, true, self::LOCK_TTL );
        $config = self::validate_config();
        if ( isset( $config['error'] ) ) { error_log( 'LGPO Sync: Aborted — ' . $config['error'] ); delete_transient( self::LOCK_KEY ); return; }
        $members = self::fetch_all_members( $config['token'], $config['campaign_id'] );
        if ( $members === null ) { error_log( 'LGPO Sync: Aborted — API fetch failed.' ); delete_transient( self::LOCK_KEY ); return; }
        $tier_to_role = self::build_tier_lookup( $config['tier_map'] );
        $changes = [ 'updates' => [], 'skipped' => [], 'stats' => [ 'total_fetched' => count( $members ), 'matched' => 0, 'unchanged' => 0, 'skipped_stripe' => 0, 'skipped_looth4' => 0, 'skipped_no_wp' => 0 ] ];
        foreach ( $members as $member ) { self::compare_member( $member, $tier_to_role, $changes ); }
        self::start_batch();
        $results = [ 'applied' => [], 'errors' => [] ];
        foreach ( $changes['updates'] as $change ) {
            $result = self::apply_change( $change );
            if ( $result['success'] ) { $results['applied'][] = $result['message']; }
            else { $results['errors'][] = $result['message']; }
        }
        update_option( 'lgpo_last_sync_time', time() );
        update_option( 'lgpo_last_sync_results', $results );
        self::send_summary( $results, true );
        delete_transient( self::LOCK_KEY );
    }

    private static function fetch_all_members( string $token, string $campaign_id ): ?array {
        $members = []; $cursor = null; $page = 0;
        do {
            $page++;
            $url = self::build_members_url( $campaign_id, $cursor );
            $response = wp_remote_get( $url, [ 'timeout' => 30, 'headers' => [ 'Authorization' => 'Bearer ' . $token, 'User-Agent' => 'LoothGroup-Sync/1.0' ] ] );
            if ( is_wp_error( $response ) ) { return null; }
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) { return null; }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! $body || ! isset( $body['data'] ) ) { return null; }
            $included = self::index_included( $body['included'] ?? [] );
            foreach ( $body['data'] as $item ) { $normalized = self::normalize_member( $item, $included ); if ( $normalized ) { $members[] = $normalized; } }
            $cursor = $body['meta']['pagination']['cursors']['next'] ?? null;
        } while ( $cursor !== null );
        return $members;
    }

    private static function build_members_url( string $campaign_id, ?string $cursor ): string {
        $params = [ 'include' => 'currently_entitled_tiers,user', 'fields[member]' => 'patron_status,email,full_name', 'fields[tier]' => 'title,amount_cents', 'fields[user]' => 'email,full_name', 'page[count]' => self::PAGE_SIZE ];
        if ( $cursor !== null ) { $params['page[cursor]'] = $cursor; }
        return self::API_BASE . '/campaigns/' . $campaign_id . '/members?' . http_build_query( $params );
    }

    private static function index_included( array $included ): array {
        $index = [];
        foreach ( $included as $resource ) { $key = ( $resource['type'] ?? '' ) . ':' . ( $resource['id'] ?? '' ); $index[ $key ] = $resource; }
        return $index;
    }

    private static function normalize_member( array $item, array $included ): ?array {
        $attrs = $item['attributes'] ?? []; $rels = $item['relationships'] ?? [];
        $email = $attrs['email'] ?? '';
        if ( ! $email ) { $user_data = $rels['user']['data'] ?? null; if ( $user_data ) { $user_resource = $included[ 'user:' . $user_data['id'] ] ?? null; $email = $user_resource['attributes']['email'] ?? ''; } }
        if ( ! $email ) { return null; }
        $tier_ids = []; $tier_data = $rels['currently_entitled_tiers']['data'] ?? [];
        foreach ( $tier_data as $tier_ref ) { $tier_ids[] = (string) $tier_ref['id']; }
        $patreon_user_id = ''; $user_data = $rels['user']['data'] ?? null;
        if ( $user_data ) { $patreon_user_id = (string) $user_data['id']; }
        return [ 'email' => strtolower( trim( $email ) ), 'full_name' => $attrs['full_name'] ?? '', 'patron_status' => $attrs['patron_status'] ?? null, 'tier_ids' => $tier_ids, 'patreon_user_id' => $patreon_user_id ];
    }

    private static function compare_member( array $member, array $tier_to_role, array &$changes ): void {
        $email = $member['email'];
        $user = get_user_by( 'email', $email );
        if ( ! $user ) { $changes['stats']['skipped_no_wp']++; $changes['skipped'][] = [ 'email' => $email, 'reason' => 'No WP account' ]; return; }
        $changes['stats']['matched']++;
        $user_id = $user->ID; $current_roles = (array) $user->roles;
        if ( in_array( 'looth4', $current_roles, true ) || in_array( 'administrator', $current_roles, true ) ) { $changes['stats']['skipped_looth4']++; $changes['skipped'][] = [ 'email' => $email, 'reason' => 'Protected role (looth4/admin)' ]; return; }
        $payment_source = get_user_meta( $user_id, 'payment_source', true );
        if ( $payment_source === 'stripe' && ( in_array( 'looth2', $current_roles, true ) || in_array( 'looth3', $current_roles, true ) ) ) { $changes['stats']['skipped_stripe']++; $changes['skipped'][] = [ 'email' => $email, 'reason' => 'Active Stripe member' ]; return; }
        $target_role = self::determine_role( $member, $tier_to_role );
        if ( $target_role === 'skip' ) { $changes['stats']['skipped_looth4']++; $changes['skipped'][] = [ 'email' => $email, 'reason' => 'Tier mapped to looth4 (bypass)' ]; return; }
        $current_role = 'looth1';
        foreach ( [ 'looth3', 'looth2', 'looth1' ] as $r ) { if ( in_array( $r, $current_roles, true ) ) { $current_role = $r; break; } }
        if ( $target_role === $current_role ) {
            if ( $target_role !== 'looth1' && $payment_source !== 'patreon' ) { $changes['updates'][] = [ 'action' => 'tag_only', 'user_id' => $user_id, 'email' => $email, 'current_role' => $current_role, 'new_role' => $target_role, 'tier_id' => $member['tier_ids'][0] ?? '', 'patreon_user_id' => $member['patreon_user_id'] ?? '', 'reason' => 'Set payment_source=patreon (role unchanged)' ]; }
            else { $changes['stats']['unchanged']++; }
            return;
        }
        $action = ( $target_role === 'looth1' ) ? 'downgrade' : 'update';
        $reason = ( $action === 'downgrade' ) ? 'Patron status: ' . ( $member['patron_status'] ?? 'none' ) : 'Tier mapped to ' . $target_role;
        $changes['updates'][] = [ 'action' => $action, 'user_id' => $user_id, 'email' => $email, 'current_role' => $current_role, 'new_role' => $target_role, 'tier_id' => $member['tier_ids'][0] ?? '', 'patreon_user_id' => $member['patreon_user_id'] ?? '', 'reason' => $reason ];
    }

    private static function determine_role( array $member, array $tier_to_role ): string {
        if ( ( $member['patron_status'] ?? '' ) !== 'active_patron' ) { return 'looth1'; }
        if ( empty( $member['tier_ids'] ) ) { return 'looth1'; }
        $non_looth4_tiers = array_filter( $member['tier_ids'], function ( $tid ) use ( $tier_to_role ) { return ( $tier_to_role[ $tid ] ?? null ) !== 'looth4'; } );
        if ( empty( $non_looth4_tiers ) ) { return 'skip'; }
        $best_role = 'looth1'; $role_rank = [ 'looth1' => 0, 'looth2' => 1, 'looth3' => 2 ];
        foreach ( $member['tier_ids'] as $tier_id ) { $role = $tier_to_role[ $tier_id ] ?? null; if ( $role && $role !== 'looth4' && ( $role_rank[ $role ] ?? 0 ) > ( $role_rank[ $best_role ] ?? 0 ) ) { $best_role = $role; } }
        return $best_role;
    }

    private static function apply_change( array $change ): array {
        $user_id = $change['user_id'] ?? 0; $email = $change['email'] ?? '';
        $user = get_userdata( $user_id );
        if ( ! $user ) { return [ 'success' => false, 'message' => "User {$email} (ID {$user_id}) not found." ]; }
        if ( in_array( 'looth4', (array) $user->roles, true ) ) { return [ 'success' => false, 'message' => "User {$email} has looth4 — skipped." ]; }
        $new_role = $change['new_role'] ?? ''; $action = $change['action'] ?? 'update';
        $old_payment_source = get_user_meta( $user_id, 'payment_source', true );
        if ( ! empty( $change['patreon_user_id'] ) ) { update_user_meta( $user_id, 'lgpo_patreon_user_id', sanitize_text_field( $change['patreon_user_id'] ) ); }
        if ( $action === 'tag_only' ) { update_user_meta( $user_id, 'payment_source', 'patreon' ); self::log_change( $user_id, $email, $change['current_role'], $change['current_role'], $old_payment_source, 'patreon', 'tag_only' ); return [ 'success' => true, 'message' => "{$email} — set payment_source=patreon (role unchanged)." ]; }
        $user->set_role( $new_role );
        if ( $new_role === 'looth1' ) { delete_user_meta( $user_id, 'payment_source' ); self::log_change( $user_id, $email, $change['current_role'], 'looth1', $old_payment_source, '', 'downgrade' ); return [ 'success' => true, 'message' => "{$email} — downgraded to looth1." ]; }
        update_user_meta( $user_id, 'payment_source', 'patreon' );
        if ( ! empty( $change['tier_id'] ) ) { update_user_meta( $user_id, 'lgpo_patreon_tier_id', sanitize_text_field( $change['tier_id'] ) ); }
        self::log_change( $user_id, $email, $change['current_role'], $new_role, $old_payment_source, 'patreon', $action );
        return [ 'success' => true, 'message' => "{$email} — {$change['current_role']} → {$new_role}." ];
    }

    private static function validate_config(): array {
        $token = get_option( 'lgpo_creator_access_token', '' ); $campaign_id = get_option( 'lgpo_campaign_id', '' ); $tier_map = get_option( 'lgpo_tier_map', [] );
        if ( ! $token ) { return [ 'error' => 'Creator Access Token not configured.' ]; }
        if ( ! $campaign_id ) { return [ 'error' => 'Campaign ID not configured.' ]; }
        if ( empty( $tier_map ) ) { return [ 'error' => 'Tier map is empty.' ]; }
        return compact( 'token', 'campaign_id', 'tier_map' );
    }

    private static function build_tier_lookup( $tier_map ): array {
        if ( ! is_array( $tier_map ) ) { return []; }
        $lookup = [];
        foreach ( $tier_map as $tier_id => $role ) {
            if ( is_array( $role ) ) { $lookup[ (string) ( $role['tier_id'] ?? $tier_id ) ] = (string) ( $role['role'] ?? 'looth1' ); }
            else { $lookup[ (string) $tier_id ] = (string) $role; }
        }
        return $lookup;
    }

    public static function get_proposed_changes(): ?array { $changes = get_transient( self::CHANGES_TRANSIENT ); return is_array( $changes ) ? $changes : null; }
    public static function clear_proposed_changes(): void { delete_transient( self::CHANGES_TRANSIENT ); }

    private static function send_summary( array $results, bool $is_auto ): void { /* wp_mail admin summary */ }

    private const LOG_KEY = 'lgpo_sync_changelog';
    private const LOG_TTL = 259200;

    private static function log_change( int $user_id, string $email, string $old_role, string $new_role, string $old_ps, string $new_ps, string $action ): void {
        $log = get_option( self::LOG_KEY, [] );
        $log[] = [ 'user_id' => $user_id, 'email' => $email, 'old_role' => $old_role, 'new_role' => $new_role, 'old_payment_source' => $old_ps, 'new_payment_source' => $new_ps, 'action' => $action, 'batch' => get_option( 'lgpo_sync_batch_id', '' ), 'timestamp' => time() ];
        $cutoff = time() - self::LOG_TTL;
        $log = array_filter( $log, fn( $entry ) => $entry['timestamp'] >= $cutoff );
        update_option( self::LOG_KEY, array_values( $log ), false );
    }

    private static function start_batch(): string { $batch_id = 'batch_' . gmdate( 'Ymd_His' ) . '_' . wp_rand( 1000, 9999 ); update_option( 'lgpo_sync_batch_id', $batch_id, false ); return $batch_id; }
    public static function get_changelog(): array { $log = get_option( self::LOG_KEY, [] ); $cutoff = time() - self::LOG_TTL; return array_filter( $log, fn( $entry ) => $entry['timestamp'] >= $cutoff ); }
    public static function get_batches(): array { /* ... */ }
    public static function revert_batch( string $batch_id ): array { /* restore old_role + old_payment_source for each entry in batch */ }
}
```

---

## class-lgpo-sync-cron.php

```php
<?php
/**
 * Sync Cron
 *
 * Manages WP Cron scheduling for automated Patreon member sync.
 * Only active when lgpo_auto_sync_enabled is checked.
 * Supports daily (default), twicedaily, and hourly frequencies.
 */

defined( 'ABSPATH' ) || exit;

class LGPO_Sync_Cron {

    private const CRON_HOOK = 'lgpo_patreon_auto_sync';

    public static function init(): void {
        add_action( self::CRON_HOOK, [ 'LGPO_Sync_Engine', 'run' ] );
        self::maybe_manage_schedule();
    }

    private static function maybe_manage_schedule(): void {
        $enabled   = get_option( 'lgpo_auto_sync_enabled', '' );
        $frequency = get_option( 'lgpo_sync_frequency', 'daily' );
        $next      = wp_next_scheduled( self::CRON_HOOK );

        if ( ! $enabled ) {
            if ( $next ) { wp_unschedule_event( $next, self::CRON_HOOK ); }
            return;
        }

        if ( $next ) {
            $current_schedule = wp_get_schedule( self::CRON_HOOK );
            if ( $current_schedule === $frequency ) { return; }
            wp_unschedule_event( $next, self::CRON_HOOK );
        }

        wp_schedule_event( time(), $frequency, self::CRON_HOOK );
    }

    public static function deactivate(): void {
        $next = wp_next_scheduled( self::CRON_HOOK );
        if ( $next ) { wp_unschedule_event( $next, self::CRON_HOOK ); }
    }
}
```

---

## Analyst notes

### Data model summary (for Patreon adapter spec)

The Poller's Patreon adapter needs to READ from WP state that LGPO has already written.
It does NOT need to call the Patreon API itself — LGPO does that on its own cron.

**Usermeta keys written by LGPO:**
| Key | Value | Meaning |
|---|---|---|
| `payment_source` | `'patreon'` | User's active tier is Patreon-sourced |
| `payment_source` | `'stripe'` | User's active tier is Stripe-sourced (LGPO skips these) |
| `payment_source` | (absent) | looth1 / no active paid source |
| `lgpo_patreon_user_id` | Patreon user ID string | Links WP account to Patreon identity |
| `lgpo_patreon_tier_id` | Patreon tier ID string | The specific tier driving the role |

**WP options read by LGPO:**
| Option | Content |
|---|---|
| `lgpo_creator_access_token` | Patreon API creator token |
| `lgpo_campaign_id` | Patreon campaign ID |
| `lgpo_tier_map` | `[ patreon_tier_id => wp_role_slug ]` |
| `lgpo_auto_sync_enabled` | Whether cron is active |
| `lgpo_sync_frequency` | `'daily'` (default), `'twicedaily'`, or `'hourly'` |

**Adapter read pattern:**
1. `get_user_meta($wp_user_id, 'payment_source', true)` → `'patreon'` means Patreon-sourced
2. User's current looth role (from `WP_User->roles`) → that IS the Patreon-attributed tier
3. `get_user_meta($wp_user_id, 'lgpo_patreon_tier_id', true)` → specific tier ID if needed

**Coexistence guard (already in LGPO):**
- `payment_source=stripe` + looth2/3 → LGPO skips, preserves Stripe role
- `payment_source=patreon` + looth2/3 → LGPO-managed
- looth4 → always skipped by LGPO

The Poller adapter reads this same guard to know which source is authoritative for a given user.
