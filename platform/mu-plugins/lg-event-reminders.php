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

/**
 * Toggle ON: add the member to $list_id WITHOUT resurrecting a global unsubscribe.
 * Existing contact → attachLists only (global status left untouched — Ian: FluentCRM's
 * GLOBAL unsubscribe is the master off-switch). Brand-new contact → create subscribed on
 * the list. (A globally-unsubscribed member who explicitly toggles ON is added to the list
 * but stays unsubscribed, so the native sender still suppresses them — by design.)
 */
function lg_evr_list_attach(WP_User $u, int $list_id): void {
    $api = FluentCrmApi('contacts');
    $c   = $api->getContact($u->user_email);
    if ($c) {
        $c->attachLists([$list_id]);
    } else {
        $api->createOrUpdate([
            'email'      => $u->user_email,
            'first_name' => $u->first_name ?: $u->display_name,
            'status'     => 'subscribed',
            'lists'      => [$list_id],
        ]);
    }
}

/** Toggle OFF: detach the member from $list_id (global status left untouched). */
function lg_evr_list_detach(WP_User $u, int $list_id): void {
    $c = FluentCrmApi('contacts')->getContact($u->user_email);
    if ($c) $c->detachLists([$list_id]);
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
        $want ? lg_evr_list_attach($u, LG_EVENT_REMINDER_LIST_ID)
              : lg_evr_list_detach($u, LG_EVENT_REMINDER_LIST_ID);
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
        $want ? lg_evr_list_attach($u, LG_WEEKLY_MEMBER_LIST_ID)
              : lg_evr_list_detach($u, LG_WEEKLY_MEMBER_LIST_ID);
        wp_send_json(['ok' => true, 'on' => lg_weekly_member_is_on($u->user_email)]);
    } catch (\Throwable $e) {
        error_log('[lg-event-reminders] weekly-member: ' . $e->getMessage());
        wp_send_json(['ok' => false, 'error' => 'crm_error'], 500);
    }
});

/* ─────────────────────────────────────────────────────────────────────────
 * Weekly Digest DEFAULT-ON for members (Ian, settled 6/29).
 *
 * New members default ONTO the Weekly Digest (list 3); Events (list 4) stay off.
 * The master off-switch is FluentCRM's GLOBAL unsubscribe — automation here never
 * resurrects it, and a member who explicitly turned Weekly off is never re-added.
 * ───────────────────────────────────────────────────────────────────────── */

const LG_MEMBER_TIER_ROLES = ['looth1', 'looth2', 'looth3', 'looth4'];

/**
 * Ensure $email is on the Weekly list, idempotently, WITHOUT resurrecting a global
 * unsubscribe. Returns one of:
 *   'created'              brand-new contact → created subscribed + on the list
 *   'attached'             existing subscribed contact → list added
 *   'already_on'           existing contact already on the list → no-op
 *   'skipped_unsubscribed' globally unsubscribed → left untouched (prior opt-out)
 *   'skipped_no_crm'       FluentCRM unavailable
 * $dry classifies without mutating (for the reconcile dry-run).
 */
function lg_evr_weekly_default_optin(string $email, string $first_name = '', bool $dry = false): string {
    if (!function_exists('FluentCrmApi')) return 'skipped_no_crm';
    $api = FluentCrmApi('contacts');
    $c   = $api->getContact($email);
    if ($c) {
        if ($c->status === 'unsubscribed') return 'skipped_unsubscribed';
        $on = in_array(LG_WEEKLY_MEMBER_LIST_ID,
                       array_map('intval', $c->lists->pluck('id')->toArray()), true);
        if ($on) return 'already_on';
        if (!$dry) $c->attachLists([LG_WEEKLY_MEMBER_LIST_ID]);
        return 'attached';
    }
    if (!$dry) {
        $api->createOrUpdate([
            'email'      => $email,
            'first_name' => $first_name,
            'status'     => 'subscribed',
            'lists'      => [LG_WEEKLY_MEMBER_LIST_ID],
        ]);
    }
    return 'created';
}

/**
 * New-member Weekly default-on. The poller fires looth_tier_changed(uid,old,new,prov)
 * on every tier grant; a BRAND-NEW member has $old === null (Wp\UserProvisioner and the
 * gift-auth path; any first-time grant). We act ONLY on that first provision, so a later
 * tier re-sync can never undo a member's explicit Weekly opt-out. Events (list 4) are
 * intentionally NOT added — Events default off.
 */
add_action('looth_tier_changed', function ($wp_user_id, $old, $new, $prov = '') {
    if ($old !== null) return;                                  // only the first tier grant
    if (!in_array($new, LG_MEMBER_TIER_ROLES, true)) return;    // member tiers only
    if (!function_exists('FluentCrmApi')) return;
    $user = get_userdata((int) $wp_user_id);
    if (!$user || !is_email($user->user_email)) return;
    try {
        $r = lg_evr_weekly_default_optin($user->user_email, $user->first_name ?: $user->display_name);
        error_log("[lg-event-reminders] weekly-default new member {$wp_user_id} ({$new}): {$r}");
    } catch (\Throwable $e) {
        error_log('[lg-event-reminders] weekly-default: ' . $e->getMessage());
    }
}, 20, 4);

/**
 * One-time, idempotent reconcile (committed + re-runnable):
 *   sudo -u looth-dev wp --path=/var/www/dev lg-evr reconcile-weekly [--dry-run]
 * Ensures every existing member (roles looth1–4) is on the Weekly Digest list, EXCEPT
 * globally-unsubscribed contacts (prior opt-out respected — never re-added/re-subscribed).
 * Events (list 4) are left exactly as-is. Reports counts; safe to run repeatedly.
 */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('lg-evr reconcile-weekly', function ($args, $assoc) {
        $dry = isset($assoc['dry-run']);
        if (!function_exists('FluentCrmApi')) WP_CLI::error('FluentCRM not available.');
        $users = (new WP_User_Query([
            'role__in' => LG_MEMBER_TIER_ROLES,
            'fields'   => ['ID', 'user_email', 'display_name'],
        ]))->get_results();
        $c = ['members' => 0, 'created' => 0, 'attached' => 0, 'already_on' => 0,
              'skipped_unsubscribed' => 0, 'skipped_no_email' => 0];
        foreach ($users as $u) {
            $c['members']++;
            $email = (string) $u->user_email;
            if (!is_email($email)) { $c['skipped_no_email']++; continue; }
            switch (lg_evr_weekly_default_optin($email, (string) $u->display_name, $dry)) {
                case 'created':              $c['created']++;              break;
                case 'attached':             $c['attached']++;             break;
                case 'already_on':           $c['already_on']++;           break;
                case 'skipped_unsubscribed': $c['skipped_unsubscribed']++; break;
            }
        }
        $tag = $dry ? '[DRY-RUN] ' : '';
        WP_CLI::log($tag . wp_json_encode($c));
        WP_CLI::success($tag . "members={$c['members']} created={$c['created']} attached={$c['attached']} "
            . "already_on={$c['already_on']} skipped_unsubscribed={$c['skipped_unsubscribed']} skipped_no_email={$c['skipped_no_email']}");
    });
}

