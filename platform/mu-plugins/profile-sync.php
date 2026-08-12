<?php
/**
 * Plugin Name: Profile-app Sync
 * Description: On user_register, fires non-blocking POST to /profile-api/v0/hooks/user-created,
 *              and stamps the immutable profile-app identity uuid into usermeta
 *              `_looth_uuid` so the JWT minter (profile-auth.php) can read it
 *              locally instead of recomputing the `sub` from the mutable email.
 *              Reads shared secret from wp_options['profile_hook_secret'].
 *              Loopback-only target; sslverify off (self-signed dev cert).
 * Version:     0.2.0
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('profile_sync_stamp_looth_uuid')) {
/**
 * Freeze the profile-app identity uuid into usermeta at account creation.
 *
 * profile-app seeds users.uuid as UUIDv5(LOOTH_IDENTITY_NAMESPACE, lower(trim(email)))
 * exactly ONCE, at first create, and never recomputes it. looth_auth_compute_uuid()
 * (profile-auth.php) uses the SAME namespace + normalization, so at user_register
 * time — when the WP email IS the create email — this value equals the stored
 * identity uuid. The uuid is immutable, so freezing it here keeps the minted JWT
 * `sub` stable even if the member later changes their WP email (the bug the
 * recompute-from-email path has). The authoritative reconciler for any drift is
 * bin/backfill-looth-uuid.php, which reads users.uuid straight from Postgres.
 */
function profile_sync_stamp_looth_uuid(int $user_id): void {
    if ($user_id <= 0) return;
    if (!function_exists('looth_auth_compute_uuid')) return; // minter absent → nothing reads it
    $u = get_userdata($user_id);
    if (!$u || $u->user_email === '') return;
    update_user_meta($user_id, '_looth_uuid', looth_auth_compute_uuid($u->user_email));
}
}

if (!function_exists('profile_sync_dispatch_user_created')) {
function profile_sync_dispatch_user_created(int $user_id): void {
    if ($user_id <= 0) return;
    $u = get_userdata($user_id);
    if (!$u) return;
    $secret = (string) get_option('profile_hook_secret', '');
    if ($secret === '') return; // refuse to send without secret

    $payload = wp_json_encode([
        'wp_user_id'   => $user_id,
        'email'        => $u->user_email,
        'display_name' => $u->display_name,
        'nicename'     => $u->user_nicename,   // human chain (name->vanity->email) minted at WP create; preferred slug source
    ]);

    wp_remote_post('https://127.0.0.1/profile-api/v0/hooks/user-created', [
        'method'    => 'POST',
        'timeout'   => 5,
        'blocking'  => true,   // onboard needs slug provisioned BEFORE the wp_login mint (else slug-less cookie → /profile/edit)
        'sslverify' => false,
        'headers'   => [
            'Host'           => $_SERVER['HTTP_HOST'] ?? 'dev2.loothgroup.com',
            'Content-Type'   => 'application/json',
            'X-Hook-Secret'  => $secret,
        ],
        'body' => $payload,
    ]);
}
}

if (!function_exists('profile_sync_dispatch_email_changed')) {
/**
 * Forward a WP email change to profile-app (audit §5).
 *
 * profile-app's receiver (Provision::applyEmailChange) has existed and been
 * correct for months, but nothing ever called it: this file hooked `user_register`
 * only. Consequence measured on live 2026-07-29 — 18 members carried a stale
 * `primary_email` because their address moved and profile-app was never told.
 *
 * Deliberately does NOT stamp `_looth_uuid`. The uuid is frozen at create and is
 * what the looth_id JWT carries as `sub`; re-deriving it from the new email would
 * mint a `sub` that no longer matches the stored users.uuid and log the member
 * out as a stranger. Keeping identity stable across an email change is the whole
 * point of the receiver — it re-points primary_email and records an alias while
 * the uuid never moves.
 *
 * Blocking, unlike a fire-and-forget: an email change is rare (18 in the platform's
 * history) so the cost is nil, and a silently dropped POST reproduces exactly the
 * bug being fixed. Non-200s are logged loudly rather than swallowed.
 */
function profile_sync_dispatch_email_changed(int $user_id, string $email): void {
    if ($user_id <= 0 || $email === '') return;
    $secret = (string) get_option('profile_hook_secret', '');
    if ($secret === '') return; // refuse to send without secret

    $res = wp_remote_post('https://127.0.0.1/profile-api/v0/hooks/email-changed', [
        'method'    => 'POST',
        'timeout'   => 5,
        'blocking'  => true,
        'sslverify' => false,
        'headers'   => [
            'Host'          => $_SERVER['HTTP_HOST'] ?? 'dev2.loothgroup.com',
            'Content-Type'  => 'application/json',
            'X-Hook-Secret' => $secret,
        ],
        'body' => wp_json_encode(['wp_user_id' => $user_id, 'email' => $email]),
    ]);

    if (is_wp_error($res)) {
        error_log('profile-sync email-changed FAILED for #' . $user_id . ': ' . $res->get_error_message());
        return;
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code !== 200) {
        // 404 here means the nginx route is missing on this box — the receiver is
        // unreachable and profile-app is still carrying the old address.
        error_log('profile-sync email-changed HTTP ' . $code . ' for #' . $user_id
            . ' -> ' . substr((string) wp_remote_retrieve_body($res), 0, 200));
    }
}
}

add_action('user_register', function ($user_id) {
    profile_sync_stamp_looth_uuid((int)$user_id);
    profile_sync_dispatch_user_created((int)$user_id);
}, 99, 1);

/**
 * Every wp_update_user email change passes through `profile_update` — the hourly
 * poller sweep's email mirror (LGPO_Sync_Engine::sync_wp_email), the onboard's
 * skeleton-adopt mirror, and admin/profile edits alike. Hooking here rather than
 * inside the poller is what makes the poller's own mirror writes covered too.
 */
add_action('profile_update', function ($user_id, $old_user_data = null) {
    $user = get_userdata((int)$user_id);
    if (!$user || !$old_user_data instanceof WP_User) return;
    $old = strtolower(trim((string)$old_user_data->user_email));
    $new = strtolower(trim((string)$user->user_email));
    if ($new === '' || $new === $old) return;
    profile_sync_dispatch_email_changed((int)$user_id, $new);
}, 99, 2);
