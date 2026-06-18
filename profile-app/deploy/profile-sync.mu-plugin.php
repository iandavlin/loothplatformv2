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
    ]);

    wp_remote_post('https://127.0.0.1/profile-api/v0/hooks/user-created', [
        'method'    => 'POST',
        'timeout'   => 1,
        'blocking'  => false,
        'sslverify' => false,
        'headers'   => [
            'Host'           => $_SERVER['HTTP_HOST'] ?? 'dev.loothgroup.com',
            'Content-Type'   => 'application/json',
            'X-Hook-Secret'  => $secret,
        ],
        'body' => $payload,
    ]);
}
}

add_action('user_register', function ($user_id) {
    profile_sync_stamp_looth_uuid((int)$user_id);
    profile_sync_dispatch_user_created((int)$user_id);
}, 99, 1);
