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

    /** Patreon API v2 base URL. */
    private const API_BASE = 'https://www.patreon.com/api/oauth2/v2';

    /** Max members per API page. */
    private const PAGE_SIZE = 1000;

    /** Transient key for proposed changes. */
    private const CHANGES_TRANSIENT = 'lgpo_api_proposed_changes';

    /** Transient TTL: 1 hour. */
    private const CHANGES_TTL = HOUR_IN_SECONDS;

    /** Lock transient to prevent concurrent runs. */
    private const LOCK_KEY = 'lgpo_sync_running';
    private const LOCK_TTL = 300; // 5 minutes

    /* ------------------------------------------------------------------
     * Manual workflow: Phase A — Fetch and Compare
     * ----------------------------------------------------------------*/

    /**
     * Fetch all campaign members and compare with WP users.
     * Stores proposed changes in a transient for the review UI.
     *
     * @return array Summary stats, or [ 'error' => string ] on failure.
     */
    public static function fetch_and_compare(): array {
        $config = self::validate_config();
        if ( isset( $config['error'] ) ) {
            return $config;
        }

        $members = self::fetch_all_members( $config['token'], $config['campaign_id'] );
        if ( $members === null ) {
            return [ 'error' => 'Failed to fetch members from Patreon API. Check debug log.' ];
        }

        $tier_to_role = self::build_tier_lookup( $config['tier_map'] );

        $changes = [
            'updates'    => [],
            'skipped'    => [],
            'stats'      => [
                'total_fetched'  => count( $members ),
                'matched'        => 0,
                'unchanged'      => 0,
                'skipped_stripe' => 0,
                'skipped_looth4' => 0,
                'skipped_no_wp'  => 0,
            ],
        ];

        foreach ( $members as $member ) {
            self::compare_member( $member, $tier_to_role, $changes );
        }

        // Store for review UI
        set_transient( self::CHANGES_TRANSIENT, $changes, self::CHANGES_TTL );
        update_option( 'lgpo_last_fetch_time', time() );

        return $changes['stats'];
    }

    /* ------------------------------------------------------------------
     * Manual workflow: Phase B — Execute Approved Changes
     * ----------------------------------------------------------------*/

    /**
     * Execute a set of admin-approved changes.
     *
     * @param array $approved Array of change records (from the review UI).
     * @return array Results summary.
     */
    public static function execute_approved( array $approved ): array {
        self::start_batch();

        $results = [
            'applied'    => [],
            'reconciled' => [],
            'errors'     => [],
        ];

        foreach ( $approved as $change ) {
            $result = self::apply_change( $change );
            if ( ! $result['success'] ) {
                $results['errors'][] = $result['message'];
            } elseif ( ! empty( $result['changed'] ) ) {
                $results['applied'][] = $result['message'];
            } else {
                $results['reconciled'][] = $result['message'];
            }
        }

        // Remove applied changes from the transient (keep the rest for continued review)
        $proposed = get_transient( self::CHANGES_TRANSIENT );
        if ( is_array( $proposed ) && ! empty( $proposed['updates'] ) ) {
            $applied_emails = array_map( fn( $c ) => strtolower( $c['email'] ?? '' ), $approved );
            $applied_set    = array_flip( $applied_emails );

            $proposed['updates'] = array_values( array_filter(
                $proposed['updates'],
                fn( $u ) => ! isset( $applied_set[ strtolower( $u['email'] ?? '' ) ] )
            ) );

            if ( empty( $proposed['updates'] ) && empty( $proposed['skipped'] ) ) {
                delete_transient( self::CHANGES_TRANSIENT );
            } else {
                set_transient( self::CHANGES_TRANSIENT, $proposed, self::CHANGES_TTL );
            }
        }

        // Store results and send admin email
        update_option( 'lgpo_last_sync_time', time() );
        update_option( 'lgpo_last_sync_results', $results );
        self::send_summary( $results, false );

        return $results;
    }

    /* ------------------------------------------------------------------
     * Auto-apply mode (for cron)
     * ----------------------------------------------------------------*/

    /**
     * Run a full fetch-and-apply cycle. Used by cron.
     * Applies all changes automatically (no review step).
     */
    public static function run(): void {
        // Prevent concurrent runs
        if ( get_transient( self::LOCK_KEY ) ) {
            error_log( 'LGPO Sync: Skipped — already running.' );
            return;
        }
        set_transient( self::LOCK_KEY, true, self::LOCK_TTL );

        error_log( 'LGPO Sync: Starting auto sync.' );

        $config = self::validate_config();
        if ( isset( $config['error'] ) ) {
            error_log( 'LGPO Sync: Aborted — ' . $config['error'] );
            if ( function_exists( 'lgpo_alert_failure' ) ) {
                lgpo_alert_failure(
                    'sync.validate_config',
                    "Hourly Patreon sync aborted — " . $config['error']
                    . "\n\nThis means polling is NOT happening; member churn / upgrades will NOT reflect."
                    . "\nLikely cause: missing or expired creator token (lgpo_creator_access_token), or wiped campaign_id / tier_map."
                );
            }
            delete_transient( self::LOCK_KEY );
            return;
        }

        $members = self::fetch_all_members( $config['token'], $config['campaign_id'] );
        if ( $members === null ) {
            error_log( 'LGPO Sync: Aborted — API fetch failed.' );
            if ( function_exists( 'lgpo_alert_failure' ) ) {
                lgpo_alert_failure(
                    'sync.fetch_all_members',
                    "Hourly Patreon sync aborted — campaign-members API fetch failed."
                    . "\n\nThis means polling is NOT happening; member churn / upgrades will NOT reflect."
                    . "\nCheck the debug log for the specific HTTP error. Common causes:"
                    . "\n  - Creator access token expired (HTTP 401)"
                    . "\n  - Patreon API outage (HTTP 5xx / connect timeout)"
                    . "\n  - Campaign ID wrong (HTTP 404)"
                );
            }
            delete_transient( self::LOCK_KEY );
            return;
        }

        error_log( sprintf( 'LGPO Sync: Fetched %d members from Patreon.', count( $members ) ) );

        $tier_to_role = self::build_tier_lookup( $config['tier_map'] );

        $changes = [
            'updates' => [],
            'skipped' => [],
            'stats'   => [
                'total_fetched'  => count( $members ),
                'matched'        => 0,
                'unchanged'      => 0,
                'skipped_stripe' => 0,
                'skipped_looth4' => 0,
                'skipped_no_wp'  => 0,
            ],
        ];

        foreach ( $members as $member ) {
            self::compare_member( $member, $tier_to_role, $changes );
        }

        // Auto-apply all updates
        self::start_batch();

        $results = [
            'applied'    => [],
            'reconciled' => [],
            'errors'     => [],
        ];

        foreach ( $changes['updates'] as $change ) {
            $result = self::apply_change( $change );
            if ( ! $result['success'] ) {
                $results['errors'][] = $result['message'];
            } elseif ( ! empty( $result['changed'] ) ) {
                $results['applied'][] = $result['message'];
            } else {
                $results['reconciled'][] = $result['message'];
            }
        }

        update_option( 'lgpo_last_sync_time', time() );
        update_option( 'lgpo_last_sync_results', $results );

        error_log( sprintf(
            'LGPO Sync: Complete — fetched: %d, matched: %d, applied: %d, unchanged: %d, errors: %d',
            $changes['stats']['total_fetched'],
            $changes['stats']['matched'],
            count( $results['applied'] ),
            $changes['stats']['unchanged'],
            count( $results['errors'] ),
        ) );

        self::send_summary( $results, true, $changes['stats'] );
        delete_transient( self::LOCK_KEY );
    }

    /* ------------------------------------------------------------------
     * Patreon API
     * ----------------------------------------------------------------*/

    /**
     * Public roster fetch — returns the normalized active-member list (same
     * shape compare_member consumes: email, full_name, patreon_user_id, tiers).
     * Used by the prepared remediation backfill (deploy/remediation) to name-
     * match the legacy blank-email accounts. Returns null on config / API error.
     *
     * @return array|null
     */
    public static function fetch_member_roster(): ?array {
        $config = self::validate_config();
        if ( isset( $config['error'] ) ) {
            return null;
        }
        return self::fetch_all_members( $config['token'], $config['campaign_id'] );
    }

    /**
     * Fetch all campaign members, handling pagination.
     *
     * @return array|null Array of normalized member records, or null on failure.
     */
    private static function fetch_all_members( string $token, string $campaign_id ): ?array {
        $members         = [];
        $cursor          = null;
        $page            = 0;
        $refreshed_once  = false; // gate the 401-refresh retry to a single attempt

        do {
            $page++;
            $url = self::build_members_url( $campaign_id, $cursor );

            error_log( "LGPO Sync: Fetching page {$page}..." );

            $response = wp_remote_get( $url, [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent'    => 'LoothGroup-Sync/1.0',
                ],
            ] );

            if ( is_wp_error( $response ) ) {
                error_log( 'LGPO Sync: API request failed — ' . $response->get_error_message() );
                return null;
            }

            $code = wp_remote_retrieve_response_code( $response );

            // Retry-on-401 with refresh — only once per fetch_all_members call,
            // and only if a refresh_token is available. On a 2nd 401 (or no
            // refresh_token, or refresh failure), fall through to the alert
            // path and return null.
            if ( $code === 401 && ! $refreshed_once && function_exists( 'lgpo_refresh_creator_token' ) ) {
                $refreshed_once = true;
                $refresh_result = lgpo_refresh_creator_token();
                if ( ! empty( $refresh_result['ok'] ) ) {
                    error_log( 'LGPO Sync: 401 → refresh OK, retrying same page with new token.' );
                    $token = (string) $refresh_result['access_token'];
                    // re-request this same page with the rotated token
                    $response = wp_remote_get( $url, [
                        'timeout' => 30,
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token,
                            'User-Agent'    => 'LoothGroup-Sync/1.0',
                        ],
                    ] );
                    if ( is_wp_error( $response ) ) {
                        error_log( 'LGPO Sync: API retry after refresh failed — ' . $response->get_error_message() );
                        return null;
                    }
                    $code = wp_remote_retrieve_response_code( $response );
                } else {
                    if ( function_exists( 'lgpo_alert_failure' ) ) {
                        lgpo_alert_failure(
                            'sync.refresh_failed',
                            "401 on campaign-members API, refresh attempt also FAILED.\n"
                            . "Refresh error: " . (string) ( $refresh_result['error'] ?? 'unknown' )
                            . "\n\nPolling will stay broken until an admin reconnects the creator account "
                            . "via Settings → LG Patreon Onboard → Connect Creator Account."
                        );
                    }
                    return null;
                }
            }

            if ( $code !== 200 ) {
                $body = wp_remote_retrieve_body( $response );
                error_log( "LGPO Sync: API returned HTTP {$code} — " . substr( $body, 0, 500 ) );
                if ( $code === 401 && function_exists( 'lgpo_alert_failure' ) ) {
                    // 401 *after* a refresh attempt (or with no refresh_token
                    // on file) — the most likely silent-death cause. The
                    // generic null-return alert in run() catches the rest;
                    // this gives the email a clear subject line.
                    lgpo_alert_failure(
                        'sync.creator_token_401',
                        "Patreon campaign-members API returned HTTP 401 after a refresh attempt."
                        . "\n\nThe creator account needs to be re-connected from Settings → LG Patreon Onboard → Connect Creator Account."
                        . "\n\nResponse body (first 500 chars):\n" . substr( $body, 0, 500 )
                    );
                }
                return null;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! $body || ! isset( $body['data'] ) ) {
                error_log( 'LGPO Sync: API returned invalid JSON.' );
                return null;
            }

            // Index included resources (users, tiers)
            $included = self::index_included( $body['included'] ?? [] );

            // Normalize each member record
            foreach ( $body['data'] as $item ) {
                $normalized = self::normalize_member( $item, $included );
                if ( $normalized ) {
                    $members[] = $normalized;
                }
            }

            // Next page cursor
            $cursor = $body['meta']['pagination']['cursors']['next'] ?? null;

        } while ( $cursor !== null );

        return $members;
    }

    /**
     * Build the campaign members API URL.
     */
    private static function build_members_url( string $campaign_id, ?string $cursor ): string {
        $params = [
            'include'        => 'currently_entitled_tiers,user',
            'fields[member]' => 'patron_status,email,full_name,last_charge_status,last_charge_date,next_charge_date,will_pay_amount_cents,currently_entitled_amount_cents,pledge_cadence',
            'fields[tier]'   => 'title,amount_cents',
            'fields[user]'   => 'email,full_name',
            'page[count]'    => self::PAGE_SIZE,
        ];

        if ( $cursor !== null ) {
            $params['page[cursor]'] = $cursor;
        }

        return self::API_BASE . '/campaigns/' . $campaign_id . '/members?' . http_build_query( $params );
    }

    /**
     * Index the "included" array from JSON:API response by type:id.
     */
    private static function index_included( array $included ): array {
        $index = [];
        foreach ( $included as $resource ) {
            $key = ( $resource['type'] ?? '' ) . ':' . ( $resource['id'] ?? '' );
            $index[ $key ] = $resource;
        }
        return $index;
    }

    /**
     * Normalize a raw member data item into a flat record.
     */
    private static function normalize_member( array $item, array $included ): ?array {
        $attrs = $item['attributes'] ?? [];
        $rels  = $item['relationships'] ?? [];

        // Get email: try member email first, then included user resource
        $email = $attrs['email'] ?? '';
        if ( ! $email ) {
            $user_data = $rels['user']['data'] ?? null;
            if ( $user_data ) {
                $user_resource = $included[ 'user:' . $user_data['id'] ] ?? null;
                $email = $user_resource['attributes']['email'] ?? '';
            }
        }

        if ( ! $email ) {
            return null; // Cannot match without email
        }

        // Extract entitled tier IDs and labels
        $tier_ids    = [];
        $tier_labels = [];
        $tier_data   = $rels['currently_entitled_tiers']['data'] ?? [];
        foreach ( $tier_data as $tier_ref ) {
            $tid          = (string) $tier_ref['id'];
            $tier_ids[]   = $tid;
            $tier_resource = $included[ 'tier:' . $tid ] ?? null;
            if ( $tier_resource ) {
                $title = $tier_resource['attributes']['title'] ?? '';
                if ( $title !== '' ) {
                    $tier_labels[] = (string) $title;
                }
            }
        }

        // Get Patreon user ID from relationship
        $patreon_user_id = '';
        $user_data = $rels['user']['data'] ?? null;
        if ( $user_data ) {
            $patreon_user_id = (string) $user_data['id'];
        }

        return [
            'email'                              => strtolower( trim( $email ) ),
            'full_name'                          => $attrs['full_name'] ?? '',
            'patron_status'                      => $attrs['patron_status'] ?? null,
            'last_charge_status'                 => $attrs['last_charge_status'] ?? null,
            'last_charge_date'                   => $attrs['last_charge_date'] ?? null,
            'next_charge_date'                   => $attrs['next_charge_date'] ?? null,
            'will_pay_amount_cents'              => isset( $attrs['will_pay_amount_cents'] ) ? (int) $attrs['will_pay_amount_cents'] : null,
            'currently_entitled_amount_cents'    => isset( $attrs['currently_entitled_amount_cents'] ) ? (int) $attrs['currently_entitled_amount_cents'] : null,
            'pledge_cadence'                     => isset( $attrs['pledge_cadence'] ) ? (int) $attrs['pledge_cadence'] : null,
            'tier_ids'                           => $tier_ids,
            'tier_labels'                        => $tier_labels,
            'patreon_user_id'                    => $patreon_user_id,
        ];
    }

    /* ------------------------------------------------------------------
     * Comparison logic
     * ----------------------------------------------------------------*/

    /**
     * Compare a single Patreon member against WP and record the result.
     */
    private static function compare_member( array $member, array $tier_to_role, array &$changes ): void {
        $email           = $member['email'];
        $patreon_user_id = (string) ( $member['patreon_user_id'] ?? '' );

        // RE-KEY THE BRIDGE ON THE STABLE PATREON USER ID for MATCHING only.
        // Match by Patreon ID first because email is mutable and was blank on
        // ~30 legacy accounts (get_user_by('email','') never matched them, so
        // they never got a role); fall back to email for accounts not yet
        // linked, and backfill the ID meta on an email match so every later
        // pass keys on the stable ID.
        //
        // The email is NOT optional and is NEVER non-load-bearing: it is the
        // user's LOGIN CREDENTIAL and MUST be captured. sync_wp_email() below
        // mirrors the patron's current Patreon email onto the WP user every
        // pass; a blank user_email is a DEFECT to fix (backfill-blank-emails.php),
        // not an acceptable steady state. Do not reintroduce the idea that the
        // email is dispensable.
        $user       = null;
        $matched_by = '';
        if ( $patreon_user_id !== '' && function_exists( 'lgpo_get_user_by_patreon_id' ) ) {
            $user = lgpo_get_user_by_patreon_id( $patreon_user_id );
            if ( $user ) {
                $matched_by = 'patreon_id';
            }
        }
        if ( ! $user && $email !== '' ) {
            $by_email = get_user_by( 'email', $email );
            if ( $by_email ) {
                $user       = $by_email;
                $matched_by = 'email';
            }
        }

        if ( ! $user ) {
            $changes['stats']['skipped_no_wp']++;
            $changes['skipped'][] = [
                'email'  => $email,
                'reason' => 'No WP account',
            ];
            return;
        }

        $changes['stats']['matched']++;
        $user_id       = $user->ID;
        $current_roles = (array) $user->roles;

        // Backfill the Patreon-ID link on an email-matched account so every
        // future pass keys on the stable ID (and the email can then drift /
        // be mirrored freely below).
        if ( $matched_by === 'email' && $patreon_user_id !== ''
             && (string) get_user_meta( $user_id, 'lgpo_patreon_user_id', true ) !== $patreon_user_id ) {
            update_user_meta( $user_id, 'lgpo_patreon_user_id', sanitize_text_field( $patreon_user_id ) );
        }

        // Persist the rich Patreon data for every matched WP user, regardless
        // of role-change decisions below. The Manage Subscription panel reads
        // from this row via Membership::statusFor().
        self::upsert_patreon_member_row( $user_id, $member );

        // WP email = live mirror of the patron's current Patreon email,
        // overwritten every pass (Item 3). Safe because login is Patreon OAuth,
        // not email/password — changing the email can't lock anyone out. On a
        // uniqueness collision (email already on a different WP user) it skips
        // the write and alerts the patron + Ian instead.
        self::sync_wp_email( $user, $member, $changes );

        // Skip looth4 always
        if ( in_array( 'looth4', $current_roles, true ) || in_array( 'administrator', $current_roles, true ) ) {
            $changes['stats']['skipped_looth4']++;
            $changes['skipped'][] = [
                'email'  => $email,
                'reason' => 'Protected role (looth4/admin)',
            ];
            return;
        }

        // Skip active Stripe members (payment_source=stripe + paid role)
        $payment_source = get_user_meta( $user_id, 'payment_source', true );
        if (
            $payment_source === 'stripe'
            && ( in_array( 'looth2', $current_roles, true ) || in_array( 'looth3', $current_roles, true ) )
        ) {
            $changes['stats']['skipped_stripe']++;
            $changes['skipped'][] = [
                'email'  => $email,
                'reason' => 'Active Stripe member',
            ];
            return;
        }

        // Determine target role
        $target_role = self::determine_role( $member, $tier_to_role );

        // Skip members whose tiers all map to looth4
        if ( $target_role === 'skip' ) {
            $changes['stats']['skipped_looth4']++;
            $changes['skipped'][] = [
                'email'  => $email,
                'reason' => 'Tier mapped to looth4 (bypass)',
            ];
            return;
        }

        // Get current looth role
        $current_role = 'looth1'; // default
        foreach ( [ 'looth3', 'looth2', 'looth1' ] as $r ) {
            if ( in_array( $r, $current_roles, true ) ) {
                $current_role = $r;
                break;
            }
        }

        // No change needed?
        if ( $target_role === $current_role ) {
            // Ensure payment_source is set if they're active
            if ( $target_role !== 'looth1' && $payment_source !== 'patreon' ) {
                $changes['updates'][] = [
                    'action'          => 'tag_only',
                    'user_id'         => $user_id,
                    'email'           => $email,
                    'current_role'    => $current_role,
                    'new_role'        => $target_role,
                    'tier_id'         => $member['tier_ids'][0] ?? '',
                    'patreon_user_id' => $member['patreon_user_id'] ?? '',
                    'reason'          => 'Set payment_source=patreon (role unchanged)',
                ];
            } else {
                $changes['stats']['unchanged']++;
            }
            return;
        }

        // Role change needed
        $action = ( $target_role === 'looth1' ) ? 'downgrade' : 'update';
        $reason = ( $action === 'downgrade' )
            ? 'Patron status: ' . ( $member['patron_status'] ?? 'none' )
            : 'Tier mapped to ' . $target_role;

        $changes['updates'][] = [
            'action'          => $action,
            'user_id'         => $user_id,
            'email'           => $email,
            'current_role'    => $current_role,
            'new_role'        => $target_role,
            'tier_id'         => $member['tier_ids'][0] ?? '',
            'patreon_user_id' => $member['patreon_user_id'] ?? '',
            'reason'          => $reason,
        ];
    }

    /**
     * Determine the target WP role for a Patreon member.
     */
    private static function determine_role( array $member, array $tier_to_role ): string {
        // Not an active patron → looth1
        if ( ( $member['patron_status'] ?? '' ) !== 'active_patron' ) {
            return 'looth1';
        }

        // Active but no entitled tiers → looth1
        if ( empty( $member['tier_ids'] ) ) {
            return 'looth1';
        }

        // Check if ALL entitled tiers map to looth4 → skip this member
        $non_looth4_tiers = array_filter( $member['tier_ids'], function ( $tid ) use ( $tier_to_role ) {
            return ( $tier_to_role[ $tid ] ?? null ) !== 'looth4';
        } );
        if ( empty( $non_looth4_tiers ) ) {
            return 'skip'; // All tiers are looth4-mapped
        }

        // Find the highest-value role among entitled tiers (ignoring looth4)
        $best_role = 'looth1';
        $role_rank = [ 'looth1' => 0, 'looth2' => 1, 'looth3' => 2 ];

        foreach ( $member['tier_ids'] as $tier_id ) {
            $role = $tier_to_role[ $tier_id ] ?? null;
            if ( $role && $role !== 'looth4' && ( $role_rank[ $role ] ?? 0 ) > ( $role_rank[ $best_role ] ?? 0 ) ) {
                $best_role = $role;
            }
        }

        return $best_role;
    }

    /* ------------------------------------------------------------------
     * Email mirror (Item 3)
     * ----------------------------------------------------------------*/

    /**
     * Keep the WP user_email as a live mirror of the patron's CURRENT Patreon
     * email, overwritten on every pass. Email is communication-only — login is
     * Patreon OAuth (wp_set_auth_cookie), so rewriting the WP email can never
     * lock a member out.
     *
     * EXCEPTION — uniqueness collision: WP requires a unique user_email. If the
     * Patreon email is already held by a DIFFERENT WP user (a duplicate / split
     * account), we must NOT write it (it would clobber / fail against the other
     * account). Skip the write and alert: reassure the patron + email Ian the
     * details (Item 5).
     *
     * Administrators are never touched.
     */
    private static function sync_wp_email( \WP_User $user, array $member, array &$changes ): void {
        $patreon_email = strtolower( trim( (string) ( $member['email'] ?? '' ) ) );
        if ( $patreon_email === '' || ! is_email( $patreon_email ) ) {
            // Patreon returned no usable email for this patron. Log it — a
            // missing login credential must be VISIBLE, never silently dropped.
            error_log( sprintf(
                'LGPO Sync: no Patreon email to mirror for WP #%d (patron %s) — login credential missing, investigate.',
                $user->ID, (string) ( $member['patreon_user_id'] ?? '?' )
            ) );
            return;
        }
        // Never overwrite a privileged account's email over poller data.
        if ( user_can( $user, 'manage_options' ) ) {
            return;
        }
        if ( strtolower( trim( (string) $user->user_email ) ) === $patreon_email ) {
            return; // already mirrored
        }

        // Uniqueness check.
        $owner = get_user_by( 'email', $patreon_email );
        if ( $owner && (int) $owner->ID !== (int) $user->ID ) {
            if ( function_exists( 'lgpo_notify_failure' ) ) {
                lgpo_notify_failure(
                    $patreon_email,
                    (string) ( $member['full_name'] ?? '' ),
                    'sync.email_collision',
                    sprintf(
                        "Could not mirror the WP email for Patreon user %s (%s).\n"
                        . "Their current Patreon email %s already belongs to a DIFFERENT WP account "
                        . "(#%d / %s). This is a duplicate / split account that needs a manual merge — "
                        . "the email was NOT changed and the member keeps their existing access.",
                        (string) ( $member['patreon_user_id'] ?? '?' ),
                        (string) ( $member['full_name'] ?? '?' ),
                        $patreon_email,
                        (int) $owner->ID,
                        $owner->user_login
                    ),
                    (int) $user->ID
                );
            }
            $changes['skipped'][] = [
                'email'  => $patreon_email,
                'reason' => 'Email collision (already on WP #' . (int) $owner->ID . ') — not written',
            ];
            return;
        }

        // Safe to mirror.
        $res = wp_update_user( [ 'ID' => $user->ID, 'user_email' => $patreon_email ] );
        if ( is_wp_error( $res ) ) {
            error_log( 'LGPO Sync: email mirror failed for #' . $user->ID . ': ' . $res->get_error_message() );
            if ( function_exists( 'lgpo_notify_failure' ) ) {
                lgpo_notify_failure(
                    $patreon_email,
                    (string) ( $member['full_name'] ?? '' ),
                    'sync.email_update_failed',
                    'wp_update_user failed setting email for WP #' . $user->ID . ': ' . $res->get_error_message(),
                    (int) $user->ID
                );
            }
            return;
        }
        update_user_meta( $user->ID, 'lgpo_patreon_email', $patreon_email );
        error_log( sprintf( 'LGPO Sync: mirrored WP email #%d -> %s', $user->ID, $patreon_email ) );

        // Freeze the identity uuid the FIRST time a real email lands so /whoami
        // resolves (the JWT minter hard-throws on a missing _looth_uuid -> anon).
        // Immutable: no-op if already set (a later email change must NOT re-derive).
        self::stamp_looth_uuid( $user->ID, $patreon_email );
    }

    /**
     * Freeze the profile-app identity uuid (`_looth_uuid`) for a WP user the FIRST
     * time a real email lands, so the JWT minter (profile-auth.php) resolves
     * `/whoami` instead of falling back to anon. Without it a re-keyed / backfilled
     * blank-email account gets a role but stays anon at the identity layer (the
     * minter refuses to recompute from email and hard-throws on a missing uuid).
     *
     * Mirrors profile-sync.php`s `user_register` stamp EXACTLY: same canonical
     * derivation UUIDv5( LOOTH_AUTH_NAMESPACE, lower(trim(email)) ) — the namespace
     * is identical to profile-app`s LOOTH_IDENTITY_NAMESPACE — so the value equals
     * the uuid profile-app froze in Postgres at create. Prefers the canonical
     * looth_auth_compute_uuid() (loaded by the profile-auth mu-plugin in any full
     * WP boot: cron / admin / wp-cli eval-file); the self-contained v5 fallback is
     * used only if that function is somehow absent, and is byte-identical.
     *
     * IMMUTABLE — never overwrites an existing uuid. The uuid is frozen at the
     * CREATE / first email; a later email change must keep the original (re-deriving
     * from the new email would mint a `sub` that no longer matches the frozen
     * users.uuid -> broken identity). Only stamps when `_looth_uuid` is absent and a
     * valid email exists. Authoritative drift reconciler stays profile-app
     * bin/backfill-looth-uuid.php (reads users.uuid straight from Postgres).
     *
     * @return string the uuid now stored (pre-existing or newly stamped), or `` if none.
     */
    public static function stamp_looth_uuid( int $user_id, string $email ): string {
        if ( $user_id <= 0 ) {
            return '';
        }
        $existing = (string) get_user_meta( $user_id, '_looth_uuid', true );
        if ( $existing !== '' ) {
            return $existing; // immutable — never re-derive on an email change
        }
        $email = strtolower( trim( $email ) );
        if ( $email === '' || ! is_email( $email ) ) {
            return '';
        }
        if ( function_exists( 'looth_auth_compute_uuid' ) ) {
            $uuid = looth_auth_compute_uuid( $email );
        } else {
            $uuid = self::compute_looth_uuid_v5( $email );
            error_log( 'LGPO stamp_looth_uuid: canonical looth_auth_compute_uuid() absent — used identical v5 fallback for #' . $user_id );
        }
        update_user_meta( $user_id, '_looth_uuid', $uuid );
        error_log( sprintf( 'LGPO stamp_looth_uuid: stamped _looth_uuid %s for #%d', $uuid, $user_id ) );
        return $uuid;
    }

    /**
     * Self-contained UUIDv5 — byte-identical to looth_auth_compute_uuid()
     * (Ramsey\Uuid::uuid5) for the same namespace + name. Fallback only. Namespace
     * == LOOTH_AUTH_NAMESPACE (profile-auth.php) == LOOTH_IDENTITY_NAMESPACE
     * (profile-app config) == eaef23f7-9bc9-4a95-ac49-ffff632e6646.
     */
    private static function compute_looth_uuid_v5( string $name ): string {
        $ns_hex = str_replace( '-', '', 'eaef23f7-9bc9-4a95-ac49-ffff632e6646' );
        $ns_bin = '';
        for ( $i = 0; $i < strlen( $ns_hex ); $i += 2 ) {
            $ns_bin .= chr( hexdec( substr( $ns_hex, $i, 2 ) ) );
        }
        $hash = sha1( $ns_bin . $name );
        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr( $hash, 0, 8 ),
            substr( $hash, 8, 4 ),
            ( hexdec( substr( $hash, 12, 4 ) ) & 0x0fff ) | 0x5000,
            ( hexdec( substr( $hash, 16, 4 ) ) & 0x3fff ) | 0x8000,
            substr( $hash, 20, 12 )
        );
    }

    /* ------------------------------------------------------------------
     * Apply a single change
     * ----------------------------------------------------------------*/

    /**
     * Apply a single change record to a WP user.
     *
     * @return array [ 'success' => bool, 'message' => string ]
     */
    private static function apply_change( array $change ): array {
        $user_id = $change['user_id'] ?? 0;
        $email   = $change['email'] ?? '';

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return [ 'success' => false, 'message' => "User {$email} (ID {$user_id}) not found." ];
        }

        // Double-check looth4 protection
        if ( in_array( 'looth4', (array) $user->roles, true ) ) {
            return [ 'success' => false, 'message' => "User {$email} has looth4 — skipped." ];
        }

        $new_role = $change['new_role'] ?? '';
        $action   = $change['action'] ?? 'update';
        $old_payment_source = get_user_meta( $user_id, 'payment_source', true );

        // Set Patreon user ID if available (links account for OAuth reuse)
        if ( ! empty( $change['patreon_user_id'] ) ) {
            update_user_meta( $user_id, 'lgpo_patreon_user_id', sanitize_text_field( $change['patreon_user_id'] ) );
        }

        if ( $action === 'tag_only' ) {
            // Just set payment_source, no role change
            update_user_meta( $user_id, 'payment_source', 'patreon' );
            self::log_change( $user_id, $email, $change['current_role'], $change['current_role'], $old_payment_source, 'patreon', 'tag_only' );
            return [ 'success' => true, 'changed' => false, 'message' => "{$email} — set payment_source=patreon (role unchanged)." ];
        }

        // Bridge: report the Patreon opinion to lg_role_sources and let the
        // arbiter merge it with Stripe and write wp_capabilities.
        // 'looth1' from Patreon means "no active Patreon tier" — report null
        // so the arbiter can fall back to whatever Stripe says (or looth1).
        $patreon_tier = ( $new_role === 'looth1' ) ? null : $new_role;

        $effective_before = $change['current_role'] ?? 'looth1';

        if ( class_exists( '\\LGMS\\RoleSourceWriter' ) && class_exists( '\\LGMS\\Arbiter' ) ) {
            // Any failure in the bridge / role pipeline reassures the member and
            // alerts Ian (Item 5), then surfaces as a sweep error.
            try {
                \LGMS\RoleSourceWriter::report( (int) $user_id, 'patreon', $patreon_tier );
                \LGMS\Arbiter::sync( (int) $user_id );
            } catch ( \Throwable $e ) {
                error_log( 'LGPO Sync: role apply failed for ' . $email . ' (#' . $user_id . '): ' . $e->getMessage() );
                if ( function_exists( 'lgpo_notify_failure' ) ) {
                    lgpo_notify_failure(
                        $email,
                        (string) ( $change['full_name'] ?? '' ),
                        'sync.role_apply_failed',
                        'Arbiter/RoleSourceWriter threw applying Patreon tier ' . $new_role
                        . ' to WP #' . $user_id . ': ' . $e->getMessage(),
                        (int) $user_id
                    );
                }
                return [ 'success' => false, 'message' => "{$email} — role apply failed: " . $e->getMessage() ];
            }
        } else {
            // Fallback if the LGMS\* namespace isn't loaded — write directly.
            error_log( 'LGPO Sync: LGMS namespace unavailable, falling back to direct set_role.' );
            $user->set_role( $new_role );
        }

        // Re-read the EFFECTIVE role after the arbiter merged the Patreon opinion
        // with any other source (e.g. Stripe). The arbiter — not this proposal —
        // decides the final role, so the report must reflect what ACTUALLY changed.
        // Without this, a Patreon opinion the arbiter overrides (Stripe outranks
        // Patreon, or a looth4/protected role) is re-proposed and re-reported as
        // "applied" on every sweep forever — phantom-change spam.
        $fresh           = get_userdata( $user_id );
        $effective_after = 'looth1';
        if ( $fresh ) {
            foreach ( [ 'looth4', 'looth3', 'looth2', 'looth1' ] as $r ) {
                if ( in_array( $r, (array) $fresh->roles, true ) ) {
                    $effective_after = $r;
                    break;
                }
            }
        }
        $changed = ( $effective_after !== $effective_before );

        if ( $new_role === 'looth1' ) {
            // Downgrade proposal: clear payment_source (Patreon tier ended).
            delete_user_meta( $user_id, 'payment_source' );
            self::log_change( $user_id, $email, $effective_before, $effective_after, $old_payment_source, '', 'downgrade' );
            error_log( "LGPO Sync: {$email} Patreon tier ended; effective {$effective_before} -> {$effective_after}." );
            $msg = $changed
                ? "{$email} — {$effective_before} → {$effective_after} (Patreon tier ended)."
                : "{$email} — Patreon tier ended; effective role stays {$effective_after} (held by another source).";
            return [ 'success' => true, 'changed' => $changed, 'message' => $msg ];
        }

        // Upgrade or change: set payment_source=patreon
        update_user_meta( $user_id, 'payment_source', 'patreon' );

        // Update tier ID in usermeta
        if ( ! empty( $change['tier_id'] ) ) {
            update_user_meta( $user_id, 'lgpo_patreon_tier_id', sanitize_text_field( $change['tier_id'] ) );
        }

        self::log_change( $user_id, $email, $effective_before, $effective_after, $old_payment_source, 'patreon', $action );
        error_log( "LGPO Sync: {$email} proposed {$new_role}; effective {$effective_before} -> {$effective_after}." );
        $msg = $changed
            ? "{$email} — {$effective_before} → {$effective_after}."
            : "{$email} — Patreon says {$new_role}; effective role stays {$effective_after} (held by another source).";
        return [ 'success' => true, 'changed' => $changed, 'message' => $msg ];
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    /**
     * Validate that all required config is present.
     *
     * @return array Config values, or [ 'error' => string ] on failure.
     */
    private static function validate_config(): array {
        $token       = get_option( 'lgpo_creator_access_token', '' );
        $campaign_id = get_option( 'lgpo_campaign_id', '' );
        $tier_map    = get_option( 'lgpo_tier_map', [] );

        if ( ! $token ) {
            return [ 'error' => 'Creator Access Token not configured.' ];
        }
        if ( ! $campaign_id ) {
            return [ 'error' => 'Campaign ID not configured.' ];
        }
        if ( empty( $tier_map ) ) {
            return [ 'error' => 'Tier map is empty.' ];
        }

        return compact( 'token', 'campaign_id', 'tier_map' );
    }

    /**
     * Build a flat lookup from the lgpo_tier_map option.
     * Handles the format: [ tier_id => role_slug ]
     *
     * @return array Patreon Tier ID (string) → WP role (string).
     */
    private static function build_tier_lookup( $tier_map ): array {
        if ( ! is_array( $tier_map ) ) {
            return [];
        }

        $lookup = [];
        foreach ( $tier_map as $tier_id => $role ) {
            if ( is_array( $role ) ) {
                // Handle [ [ 'tier_id' => '...', 'role' => '...' ], ... ] format
                $lookup[ (string) ( $role['tier_id'] ?? $tier_id ) ] = (string) ( $role['role'] ?? 'looth1' );
            } else {
                $lookup[ (string) $tier_id ] = (string) $role;
            }
        }

        return $lookup;
    }

    /**
     * Get the currently stored proposed changes (for the review UI).
     *
     * @return array|null Changes array or null if none/expired.
     */
    public static function get_proposed_changes(): ?array {
        $changes = get_transient( self::CHANGES_TRANSIENT );
        return is_array( $changes ) ? $changes : null;
    }

    /**
     * Clear stored proposed changes.
     */
    public static function clear_proposed_changes(): void {
        delete_transient( self::CHANGES_TRANSIENT );
    }

    /* ------------------------------------------------------------------
     * Admin email summary
     * ----------------------------------------------------------------*/

    /**
     * Email Ian a role-change digest for the sync run.
     *
     * Sends ONLY when at least one role change actually APPLIED (effective
     * role transitioned) — never on steady-state sweeps, and not on
     * errors-only sweeps either: persistent per-member errors would repeat
     * hourly (the exact noise this gate exists to prevent), and role-apply
     * failures already alert Ian individually via lgpo_notify_failure.
     *
     * Recipient is lgpo_contact_email (Ian), falling back to admin_email —
     * same resolution as lgpo_alert_failure. Tagged X-LG-Poller-Intent:
     * notify so it passes the poller mail gate (Plugin::gateOutboundMail)
     * and the killswitch mu-plugin regardless of lgms_poller_mail_enabled;
     * that flag governs member-facing bulk mail and stays untouched.
     */
    private static function send_summary( array $results, bool $is_auto, array $stats = [] ): void {
        $to = (string) get_option( 'lgpo_contact_email', '' );
        if ( $to === '' ) {
            $to = (string) get_option( 'admin_email', '' );
        }
        if ( $to === '' ) {
            return;
        }

        $applied    = $results['applied'] ?? [];
        $errors     = $results['errors'] ?? [];
        $reconciled = $results['reconciled'] ?? [];

        if ( empty( $applied ) ) {
            return;
        }

        $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
        $mode      = $is_auto ? 'Auto' : 'Manual';
        $subject   = sprintf(
            '[%s] Patreon Sync Report (%s) — %d changed, %d errors',
            $site_name, $mode, count( $applied ), count( $errors )
        );

        $lines = [
            "Patreon Member Sync Report ({$mode})",
            '======================================',
            '',
        ];

        // Full pipeline stats. Previously the report printed only Applied/Errors
        // and omitted fetched/matched/unchanged/skipped, making a real sweep look
        // empty/broken. Include them when the caller supplies them.
        if ( ! empty( $stats ) ) {
            $lines[] = sprintf( 'Fetched from Patreon:  %d', $stats['total_fetched'] ?? 0 );
            $lines[] = sprintf( 'Matched to WP users:   %d', $stats['matched'] ?? 0 );
            $lines[] = sprintf( 'Unchanged:             %d', $stats['unchanged'] ?? 0 );
            $lines[] = sprintf( 'Skipped (no WP acct):  %d', $stats['skipped_no_wp'] ?? 0 );
            $lines[] = sprintf( 'Skipped (looth4):      %d', $stats['skipped_looth4'] ?? 0 );
            $lines[] = sprintf( 'Skipped (stripe):      %d', $stats['skipped_stripe'] ?? 0 );
            $lines[] = '';
        }

        $lines[] = sprintf( 'Role changes applied:  %d', count( $applied ) );
        $lines[] = sprintf( 'Errors:                %d', count( $errors ) );
        if ( ! empty( $reconciled ) ) {
            $lines[] = sprintf( 'Reconciled (no net change): %d', count( $reconciled ) );
        }

        if ( ! empty( $applied ) ) {
            $lines[] = '';
            $lines[] = 'Applied:';
            foreach ( $applied as $msg ) {
                $lines[] = '  - ' . $msg;
            }
        }

        if ( ! empty( $errors ) ) {
            $lines[] = '';
            $lines[] = 'Errors:';
            foreach ( $errors as $msg ) {
                $lines[] = '  - ' . $msg;
            }
        }

        // Explicit UTF-8 so the "—" / "→" in messages never mojibake.
        // X-LG-Poller-Intent: notify = the agreed operator-notification bypass
        // marker (see lgpo_alert_failure / Plugin::gateOutboundMail).
        $headers = [ 'Content-Type: text/plain; charset=UTF-8', 'X-LG-Poller-Intent: notify' ];
        wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
    }

    /* ------------------------------------------------------------------
     * Change Log — rolling 3-day history with revert support
     * ----------------------------------------------------------------*/

    /** Option key for the change log. */
    private const LOG_KEY = 'lgpo_sync_changelog';

    /** How long to keep log entries (3 days in seconds). */
    private const LOG_TTL = 259200;

    /**
     * Record a single change to the rolling log.
     */
    private static function log_change( int $user_id, string $email, string $old_role, string $new_role, string $old_ps, string $new_ps, string $action ): void {
        $log   = get_option( self::LOG_KEY, [] );
        $log[] = [
            'user_id'            => $user_id,
            'email'              => $email,
            'old_role'           => $old_role,
            'new_role'           => $new_role,
            'old_payment_source' => $old_ps,
            'new_payment_source' => $new_ps,
            'action'             => $action,
            'batch'              => get_option( 'lgpo_sync_batch_id', '' ),
            'timestamp'          => time(),
        ];

        // Prune entries older than 3 days
        $cutoff = time() - self::LOG_TTL;
        $log    = array_filter( $log, fn( $entry ) => $entry['timestamp'] >= $cutoff );

        update_option( self::LOG_KEY, array_values( $log ), false );
    }

    /**
     * Stamp a new batch ID before executing changes.
     * Call this at the start of execute_approved() and run().
     */
    private static function start_batch(): string {
        $batch_id = 'batch_' . gmdate( 'Ymd_His' ) . '_' . wp_rand( 1000, 9999 );
        update_option( 'lgpo_sync_batch_id', $batch_id, false );
        return $batch_id;
    }

    /**
     * Get the full change log (pruned to 3 days).
     *
     * @return array
     */
    public static function get_changelog(): array {
        $log    = get_option( self::LOG_KEY, [] );
        $cutoff = time() - self::LOG_TTL;
        return array_filter( $log, fn( $entry ) => $entry['timestamp'] >= $cutoff );
    }

    /**
     * Get a list of distinct batches in the log.
     *
     * @return array [ [ 'batch' => string, 'timestamp' => int, 'count' => int ], ... ]
     */
    public static function get_batches(): array {
        $log     = self::get_changelog();
        $batches = [];

        foreach ( $log as $entry ) {
            $b = $entry['batch'];
            if ( ! isset( $batches[ $b ] ) ) {
                $batches[ $b ] = [ 'batch' => $b, 'timestamp' => $entry['timestamp'], 'count' => 0 ];
            }
            $batches[ $b ]['count']++;
        }

        // Sort newest first
        usort( $batches, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
        return $batches;
    }

    /**
     * Revert all changes from a specific batch.
     *
     * @return array [ 'reverted' => int, 'errors' => string[] ]
     */
    public static function revert_batch( string $batch_id ): array {
        $log      = self::get_changelog();
        $entries  = array_filter( $log, fn( $e ) => $e['batch'] === $batch_id );
        $reverted = 0;
        $errors   = [];

        foreach ( $entries as $entry ) {
            $user = get_userdata( $entry['user_id'] );
            if ( ! $user ) {
                $errors[] = "User {$entry['email']} (ID {$entry['user_id']}) not found.";
                continue;
            }

            // Skip looth4 protection
            if ( in_array( 'looth4', (array) $user->roles, true ) ) {
                $errors[] = "User {$entry['email']} is looth4 — skipped.";
                continue;
            }

            // Restore old role (only if it actually changed)
            if ( $entry['old_role'] !== $entry['new_role'] ) {
                $user->set_role( $entry['old_role'] );
            }

            // Restore old payment_source
            if ( $entry['old_payment_source'] ) {
                update_user_meta( $entry['user_id'], 'payment_source', $entry['old_payment_source'] );
            } else {
                delete_user_meta( $entry['user_id'], 'payment_source' );
            }

            $reverted++;
        }

        // Remove reverted entries from the log
        $remaining = array_filter( $log, fn( $e ) => $e['batch'] !== $batch_id );
        update_option( self::LOG_KEY, array_values( $remaining ), false );

        error_log( "LGPO Sync: Reverted batch {$batch_id} — {$reverted} changes restored." );
        return [ 'reverted' => $reverted, 'errors' => $errors ];
    }

    /**
     * Public entry point for the self-connect onboard path: write the SAME
     * lg_patreon_members snapshot the sweep writes, so a freshly-connected member
     * is fully provisioned immediately instead of waiting for the next hourly
     * sweep. Idempotent (ON DUPLICATE KEY UPDATE) — the next sweep enriches the
     * row with charge dates the OAuth identity response doesn't carry.
     */
    public static function record_patreon_member( int $wp_user_id, array $member ): void {
        self::upsert_patreon_member_row( $wp_user_id, $member );
    }

    /**
     * Upsert a Patreon member row in lg_patreon_members. Powers the unified
     * Membership::statusFor() panel on Manage Subscription.
     */
    private static function upsert_patreon_member_row( int $wp_user_id, array $member ): void {
        // Tolerate partial snapshots. The self-connect onboard builds $member from
        // the self-token /identity response, which (unlike the creator-token
        // members API the sweep uses) carries NO charge fields — so default every
        // column here. Missing keys would otherwise raise "Undefined array key" on
        // the ?: reads below; the next sweep enriches the nulls via the ON
        // DUPLICATE KEY UPDATE above.
        $member += [
            'patreon_user_id'                 => null,
            'email'                           => null,
            'full_name'                       => null,
            'patron_status'                   => null,
            'last_charge_status'              => null,
            'last_charge_date'                => null,
            'next_charge_date'                => null,
            'will_pay_amount_cents'           => null,
            'currently_entitled_amount_cents' => null,
            'pledge_cadence'                  => null,
            'tier_labels'                     => [],
        ];
        try {
            $pdo = \LGMS\Db::pdo();
            $pdo->prepare(
                'INSERT INTO lg_patreon_members
                    (wp_user_id, patreon_user_id, email, full_name, patron_status,
                     last_charge_status, last_charge_date, next_charge_date,
                     will_pay_amount_cents, currently_entitled_amount_cents, pledge_cadence, tier_label)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    patreon_user_id                 = VALUES(patreon_user_id),
                    email                           = VALUES(email),
                    full_name                       = VALUES(full_name),
                    patron_status                   = VALUES(patron_status),
                    last_charge_status              = VALUES(last_charge_status),
                    last_charge_date                = VALUES(last_charge_date),
                    next_charge_date                = VALUES(next_charge_date),
                    will_pay_amount_cents           = VALUES(will_pay_amount_cents),
                    currently_entitled_amount_cents = VALUES(currently_entitled_amount_cents),
                    pledge_cadence                  = VALUES(pledge_cadence),
                    tier_label                      = VALUES(tier_label)'
            )->execute( [
                $wp_user_id,
                $member['patreon_user_id'] ?: null,
                $member['email'] ?: null,
                $member['full_name'] ?: null,
                $member['patron_status'] ?: null,
                $member['last_charge_status'] ?: null,
                self::normalize_datetime( $member['last_charge_date'] ?? null ),
                self::normalize_datetime( $member['next_charge_date'] ?? null ),
                $member['will_pay_amount_cents'] ?? null,
                $member['currently_entitled_amount_cents'] ?? null,
                $member['pledge_cadence'] ?? null,
                ( $member['tier_labels'][0] ?? null ) ?: null,
            ] );
        } catch ( \Throwable $e ) {
            error_log( 'LGPO upsert_patreon_member_row: ' . $e->getMessage() );
        }
    }

    /**
     * Convert Patreon ISO-8601 timestamps to MySQL DATETIME (UTC), or null.
     */
    private static function normalize_datetime( ?string $iso ): ?string {
        if ( ! $iso ) {
            return null;
        }
        try {
            $dt = new \DateTime( $iso );
            $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( \Throwable $_ ) {
            return null;
        }
    }
}
