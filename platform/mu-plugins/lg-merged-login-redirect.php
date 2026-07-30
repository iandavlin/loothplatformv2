<?php
/**
 * Plugin Name: LG Merged-Account Login Redirect
 * Description: Lets a member who was merged sign in with their old address instead of hitting a dead account.
 * Version: 1.0
 *
 * When a duplicate pair is merged, the retired twin's user_email is parked as
 * merged-<id>@retired.invalid so WP's unique-email index frees the address for
 * the survivor. The member does not know that. They type the address they have
 * always typed, WP finds no account, and they get "unknown email" — which reads
 * as "my account is gone".
 *
 * This closes that hole. The old address is looked up against the lg_prior_email
 * marker the merge leaves behind, and:
 *
 *   - if the password they typed is the SURVIVOR's password, they are signed
 *     into the survivor account, which is where all their content now lives;
 *   - if it is not, they get a message naming the address to use, rather than
 *     a bare failure.
 *
 * The retired account itself is never a login target: the only credential that
 * works is the survivor's own password. A stale password for the retired twin
 * grants nothing.
 *
 * Deploy note: this is a code change to a login path. It goes through
 * tools/gates/run-all.sh and Ian's approval before it ships, and it must land
 * in the SAME window as the merge — the merge parks the address, and until this
 * is live the old address simply fails.
 */

if (!defined('ABSPATH')) exit;

/**
 * Resolve an address that used to belong to a now-retired account.
 * Returns the survivor's WP_User, or null.
 */
function lg_merged_survivor_for_email(string $email): ?WP_User {
    $email = trim(strtolower($email));
    if ($email === '' || !is_email($email)) return null;

    global $wpdb;
    // lg_prior_email is written only by the merge tool, one row per retired twin
    $twinId = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta}
          WHERE meta_key = 'lg_prior_email' AND LOWER(meta_value) = %s
          LIMIT 1", $email));
    if (!$twinId) return null;

    $survivorId = (int) get_user_meta((int) $twinId, 'lg_merged_into', true);
    if (!$survivorId) return null;

    $survivor = get_user_by('id', $survivorId);
    return $survivor ?: null;
}

/** Mask an address for display: j****n@example.com — enough to recognise, not to harvest. */
function lg_merged_mask_email(string $email): string {
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') return '***';
    $keep = mb_substr($local, 0, 1);
    $last = mb_strlen($local) > 1 ? mb_substr($local, -1) : '';
    return $keep . str_repeat('*', max(1, mb_strlen($local) - 2)) . $last . '@' . $domain;
}

add_filter('authenticate', function ($user, $username, $password) {
    // Only step in once WP itself has failed to find the account, and only for
    // a real login attempt — never for empty or already-successful ones.
    if ($user instanceof WP_User) return $user;
    if (!is_string($username) || $username === '' || !is_string($password) || $password === '') return $user;
    if (!is_email($username)) return $user;
    if (get_user_by('email', $username)) return $user;   // live account, not our business

    $survivor = lg_merged_survivor_for_email($username);
    if (!$survivor) return $user;

    // Their content is on the survivor, so the survivor's password is the one
    // that counts. Checking it here means the old address keeps working as an
    // alias without the retired account ever becoming loggable.
    if (wp_check_password($password, $survivor->user_pass, $survivor->ID)) {
        return $survivor;
    }

    return new WP_Error('lg_merged_account', sprintf(
        /* translators: %s: partially masked email address */
        __('That address now signs in as <strong>%s</strong>. Use that address — your posts, messages and connections are all on it. If you have forgotten the password, reset it below.', 'lg'),
        esc_html(lg_merged_mask_email($survivor->user_email))
    ));
}, 30, 3);

/**
 * A password-reset request on the old address should reach the survivor too,
 * otherwise the message above sends them to a reset that also fails.
 */
add_filter('lostpassword_errors', function ($errors, $user_data) {
    if (!is_wp_error($errors) || !$errors->has_errors()) return $errors;
    $login = isset($_POST['user_login']) ? trim(wp_unslash($_POST['user_login'])) : '';
    if ($login === '' || !is_email($login)) return $errors;
    if (!lg_merged_survivor_for_email($login)) return $errors;

    $survivor = lg_merged_survivor_for_email($login);
    $errors->remove('invalid_email');
    $errors->remove('invalidcombo');
    if (!$errors->has_errors()) {
        // hand WP the survivor so it sends the reset to the address in use
        add_filter('retrieve_password_user_data', fn() => $survivor, 10, 0);
    }
    return $errors;
}, 10, 2);
