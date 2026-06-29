<?php
/**
 * Plugin Name: LG Event Reminders — one-click signup
 * Description: Adds the LOGGED-IN member to the FluentCRM "Event Reminder
 * Email List" in one click (front-page bento button, Ian 6/12). admin-ajax
 * (cookie auth — works from the standalone pages where REST nonces aren't
 * available), same-origin checked, idempotent: re-clicks just re-confirm.
 */

if (!defined('ABSPATH')) exit;

const LG_EVENT_REMINDER_LIST_ID = 4;   // wp_fc_lists: "Event Reminder Email List"

/** Shared guards → current user or JSON-error exit. */
function lg_evr_user(): WP_User {
    $src  = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($src !== '' && parse_url($src, PHP_URL_HOST) !== $host) {
        wp_send_json(['ok' => false, 'error' => 'bad_origin'], 403);
    }
    $u = wp_get_current_user();
    if (!$u || !$u->exists()) wp_send_json(['ok' => false, 'error' => 'auth'], 401);
    if (!function_exists('FluentCrmApi')) wp_send_json(['ok' => false, 'error' => 'crm_unavailable'], 500);
    return $u;
}

function lg_evr_is_on(string $email): bool {
    $c = FluentCrmApi('contacts')->getContact($email);
    if (!$c) return false;
    $ids = array_map('intval', $c->lists->pluck('id')->toArray());
    return in_array(LG_EVENT_REMINDER_LIST_ID, $ids, true);
}

/** GET state — the button renders its real CRM state on page load. */
add_action('wp_ajax_lg_event_reminder_state', function () {
    $u = lg_evr_user();
    try { wp_send_json(['ok' => true, 'on' => lg_evr_is_on($u->user_email)]); }
    catch (\Throwable $e) { wp_send_json(['ok' => false, 'error' => 'crm_error'], 500); }
});

/** TOGGLE — on adds to the list, off detaches from it (Ian 6/12: both ways). */
add_action('wp_ajax_lg_event_reminder_signup', function () {
    $u    = lg_evr_user();
    $want = (string)($_POST['on'] ?? '1') === '1';
    try {
        $api = FluentCrmApi('contacts');
        if ($want) {
            $api->createOrUpdate([
                'email'      => $u->user_email,
                'first_name' => $u->first_name ?: $u->display_name,
                'status'     => 'subscribed',
                'lists'      => [LG_EVENT_REMINDER_LIST_ID],
            ]);
        } else {
            $c = $api->getContact($u->user_email);
            if ($c) $c->detachLists([LG_EVENT_REMINDER_LIST_ID]);
        }
        wp_send_json(['ok' => true, 'on' => lg_evr_is_on($u->user_email)]);
    } catch (\Throwable $e) {
        error_log('[lg-event-reminders] ' . $e->getMessage());
        wp_send_json(['ok' => false, 'error' => 'crm_error'], 500);
    }
});

/**
 * ANON weekly-digest signup (Ian 6/12: "offer it to logged out") —
 * wp_ajax_nopriv: email capture from the public /weekly/ page into the
 * "Non Member Weekly Email Subscriber" list, DOUBLE OPT-IN (pending +
 * confirmation email) so it's consent-clean and spam-resistant. Honeypot
 * field + same-origin + a light per-IP rate limit.
 */
const LG_WEEKLY_NONMEMBER_LIST_ID = 7;   // wp_fc_lists: "Non Member Weekly Email Subscriber"

add_action('wp_ajax_nopriv_lg_weekly_signup', 'lg_weekly_signup_handler');
add_action('wp_ajax_lg_weekly_signup',        'lg_weekly_signup_handler');  // logged-in fallback: same flow
function lg_weekly_signup_handler() {
    $src  = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($src !== '' && parse_url($src, PHP_URL_HOST) !== $host) {
        wp_send_json(['ok' => false, 'error' => 'bad_origin'], 403);
    }
    if (!function_exists('FluentCrmApi')) wp_send_json(['ok' => false, 'error' => 'crm_unavailable'], 500);

    // Honeypot: real users never fill "website".
    if (trim((string)($_POST['website'] ?? '')) !== '') wp_send_json(['ok' => true]);   // silently swallow bots

    $email = sanitize_email((string)($_POST['email'] ?? ''));
    if (!$email || !is_email($email)) wp_send_json(['ok' => false, 'error' => 'bad_email'], 422);

    // Per-IP rate limit: 5 signups/hour.
    $ipKey = 'lg_wk_signup_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    $n = (int) get_transient($ipKey);
    if ($n >= 5) wp_send_json(['ok' => false, 'error' => 'slow_down'], 429);
    set_transient($ipKey, $n + 1, HOUR_IN_SECONDS);

    try {
        $api = FluentCrmApi('contacts');
        $existing = $api->getContact($email);
        if ($existing && $existing->status === 'subscribed') {
            // Already a confirmed contact: just attach the list, no re-confirm spam.
            $existing->attachLists([LG_WEEKLY_NONMEMBER_LIST_ID]);
            wp_send_json(['ok' => true, 'state' => 'subscribed']);
        }
        $contact = $api->createOrUpdate([
            'email'  => $email,
            'status' => 'pending',
            'lists'  => [LG_WEEKLY_NONMEMBER_LIST_ID],
        ]);
        if ($contact && method_exists($contact, 'sendDoubleOptinEmail')) {
            $contact->sendDoubleOptinEmail();
        }
        wp_send_json(['ok' => true, 'state' => 'pending']);
    } catch (\Throwable $e) {
        error_log('[lg-weekly-signup] ' . $e->getMessage());
        wp_send_json(['ok' => false, 'error' => 'crm_error'], 500);
    }
}

/* ─────────────────────────────────────────────────────────────────────────
 * Weekly Digest (members' list) on/off — for the Manage Account page.
 * Mirrors the event-reminder toggle above, but for the members' Weekly Digest
 * FluentCRM list (id 3 — the list the weekly campaign sends to). Logged-in only,
 * cookie-auth via admin-ajax, same-origin checked (lg_evr_user()), idempotent.
 * ───────────────────────────────────────────────────────────────────────── */
const LG_WEEKLY_MEMBER_LIST_ID = 3;   // wp_fc_lists: members' Weekly Digest list

function lg_weekly_member_is_on(string $email): bool {
    $c = FluentCrmApi('contacts')->getContact($email);
    if (!$c) return false;
    $ids = array_map('intval', $c->lists->pluck('id')->toArray());
    return in_array(LG_WEEKLY_MEMBER_LIST_ID, $ids, true);
}

/** GET state — the toggle renders its real CRM state on page load. */
add_action('wp_ajax_lg_weekly_member_state', function () {
    $u = lg_evr_user();
    try { wp_send_json(['ok' => true, 'on' => lg_weekly_member_is_on($u->user_email)]); }
    catch (\Throwable $e) { wp_send_json(['ok' => false, 'error' => 'crm_error'], 500); }
});

/** TOGGLE — on adds to the members' weekly list, off detaches (both ways). */
add_action('wp_ajax_lg_weekly_member_toggle', function () {
    $u    = lg_evr_user();
    $want = (string)($_POST['on'] ?? '1') === '1';
    try {
        $api = FluentCrmApi('contacts');
        if ($want) {
            $api->createOrUpdate([
                'email'      => $u->user_email,
                'first_name' => $u->first_name ?: $u->display_name,
                'status'     => 'subscribed',
                'lists'      => [LG_WEEKLY_MEMBER_LIST_ID],
            ]);
        } else {
            $c = $api->getContact($u->user_email);
            if ($c) $c->detachLists([LG_WEEKLY_MEMBER_LIST_ID]);
        }
        wp_send_json(['ok' => true, 'on' => lg_weekly_member_is_on($u->user_email)]);
    } catch (\Throwable $e) {
        error_log('[lg-event-reminders] weekly-member: ' . $e->getMessage());
        wp_send_json(['ok' => false, 'error' => 'crm_error'], 500);
    }
});

