<?php
/**
 * Plugin Name: LG — Per-discussion email unsubscribe
 * Description: Signed, logged-out, discussion-SPECIFIC unsubscribe link in reply-notification emails, plus its confirmation page. Replaces BuddyBoss's blanket type-level link.
 * Author: thread-follow lane
 * Version: 1.0
 *
 * SPEC: docs/atlas/THREAD-FOLLOW-SPEC.md §4. Lane: thread-follow, 2026-07-28.
 *
 * ── UNSET SURFACE #3, and the one with real constraints ──────────────────────
 * Must work for a LOGGED-OUT reader clicking from their inbox; must be SPECIFIC to
 * one discussion, never a blanket kill; must land on a small confirmation page that
 * ALSO offers "stop all discussion emails" for someone who is really done.
 *
 * This is the surface that discharges Ian's 2026-07-28 ruling in the place it
 * actually matters. Ruling 10 honours the 1,519 existing subscriptions and shows
 * them as ON — and the honest remedy for the ~1,091 created by the banned
 * auto-subscribe mechanism is that people can leave FROM INSIDE THE VERY EMAIL THAT
 * ANNOYED THEM, without hunting for the thread or logging in.
 *
 * ── IT REPLACES A LINK, IT DOES NOT ADD ONE ──────────────────────────────────
 * Correction to an earlier reading of the spec, verified in the deployed 2.20.0
 * source: bb_send_forums_subscribed_reply already injects
 *     bp_email_get_unsubscribe_link( $uid, 'bbp-new-forum-reply' )
 * into the `unsubscribe` token (class-bp-forums-notification.php:1078-1080),
 * OVERWRITING the topic URL that bbp_notify_topic_subscribers set at
 * functions.php:1249. So today's emails ALREADY carry a working unsubscribe — at
 * exactly the blanket, type-level granularity §4.1 ruled out. One click there kills
 * every reply email from every discussion forever.
 *
 * ── WHY BUDDYBOSS'S OWN MECHANISM IS THE BASE BUT NOT THE FUNCTION ───────────
 * bp_email_get_unsubscribe_link() signs "{$email_type}:{$user_id}" — scoped to a
 * notification TYPE, not an item, so it CANNOT express "this discussion". We reuse
 * its SALT and its HMAC + hash_equals posture (bp_email_get_salt(),
 * bp-core-functions.php:4183; verification posture :4089) and put the TOPIC ID
 * INSIDE the signed payload. That is the entire difference.
 *
 * NO EXPIRY, deliberately, matching BB: the token only ever REMOVES a subscription,
 * and an expiry would silently break links in older mail. Stated so it reads as a
 * choice rather than an oversight.
 *
 * ⚠️ SALT ROTATION INVALIDATES EVERY OUTSTANDING LINK. bp-emails-unsubscribe-salt is
 * seeded once at install; rotating it silently breaks unsubscribe links in mail
 * already delivered. Do not rotate it casually.
 *
 * ── GET CONFIRMS, POST ACTS — A CORRECTNESS REQUIREMENT, NOT POLITENESS ──────
 * BuddyBoss's own handler mutates on a bare GET (bp-core-functions.php:4106).
 * Corporate mail scanners and link prefetchers (Outlook SafeLinks, proxying
 * gateways) follow every link in an email — against a GET-mutating endpoint that
 * silently unsubscribes people who never clicked. The weekly-recap lane measured
 * this on our own mail: 7-10% of apparent clickers were machines following every
 * link. So the confirmation step is load-bearing, and it is also what Ian asked for.
 *
 * THREAT MODEL, stated rather than implied: the signed token IS the authorisation,
 * so anyone holding the link (e.g. a forwarded email) can act on it. That is
 * inherent to logged-out unsubscribe and is BuddyBoss's posture too. What the
 * confirmation step removes is the ACCIDENTAL case, which is the common one. A
 * logged-in user may never act on a different uid (BB's own rule, :4076-4085).
 */

if (!defined('ABSPATH')) exit;

const LG_DISC_UNSUB_PATH = '/discussions/unsubscribe/';

/** The account-level master key, selected exactly as BuddyBoss's sender does. */
function lg_disc_unsub_master_key(): string
{
    $key = 'notification_forums_following_reply';
    if (function_exists('bb_enabled_legacy_email_preference')
        && function_exists('bb_get_prefences_key')
        && !bb_enabled_legacy_email_preference()) {
        $modern = bb_get_prefences_key('legacy', $key);
        if (is_string($modern) && $modern !== '') return $modern;   // → bb_forums_subscribed_reply
    }
    return $key;
}

/** HMAC over the topic AND the user — the topic id inside the payload is the point. */
function lg_disc_unsub_sig(int $topicId, int $userId): string
{
    if (!function_exists('bp_email_get_salt')) return '';
    $salt = (string) bp_email_get_salt();
    if ($salt === '') return '';
    return hash_hmac('sha1', "lgdisc:{$topicId}:{$userId}", $salt);
}

function lg_disc_unsub_link(int $topicId, int $userId): string
{
    $nh = lg_disc_unsub_sig($topicId, $userId);
    if ($nh === '') return '';
    return home_url(LG_DISC_UNSUB_PATH) . '?' . http_build_query([
        'uid' => $userId, 'tid' => $topicId, 'nh' => $nh,
    ]);
}

function lg_disc_unsub_verify(int $topicId, int $userId, string $nh): bool
{
    if ($topicId < 1 || $userId < 1 || $nh === '') return false;
    $expected = lg_disc_unsub_sig($topicId, $userId);
    if ($expected === '') return false;
    if (!hash_equals($expected, $nh)) return false;
    // BB's own safety rule: a LOGGED-IN user may not act on someone else's uid.
    $current = get_current_user_id();
    if ($current && (int) $current !== $userId) return false;
    return true;
}

/* ── The token swap ───────────────────────────────────────────────────────────
   bp_get_email( $email_type ) runs BEFORE $email->set_tokens() inside
   bp_send_email (bp-core-functions.php:3600 then :3610), and BP_Email::$type is
   protected with no accessor — so we capture the type on the way past and read it
   in the token filter. Without that we would rewrite `unsubscribe` on any email
   that happens to carry a reply.id. */
add_filter('bp_get_email', function ($email, $email_type) {
    $GLOBALS['lg_disc_unsub_current_type'] = (string) $email_type;
    return $email;
}, 10, 2);

add_filter('bp_email_set_tokens', function ($tokens) {
    if (($GLOBALS['lg_disc_unsub_current_type'] ?? '') !== 'bbp-new-forum-reply') return $tokens;
    if (!is_array($tokens)) return $tokens;

    $userId  = (int) ($tokens['receiver-user.id'] ?? 0);
    $replyId = (int) ($tokens['reply.id'] ?? 0);
    if ($userId < 1 || $replyId < 1 || !function_exists('bbp_get_reply_topic_id')) return $tokens;

    $topicId = (int) bbp_get_reply_topic_id($replyId);
    if ($topicId < 1) return $tokens;

    $link = lg_disc_unsub_link($topicId, $userId);
    // Fail SAFE: if we cannot mint a link (no salt), leave BuddyBoss's blanket one in
    // place. An email with a too-broad unsubscribe is bad; an email with NO working
    // unsubscribe is worse, and in several jurisdictions is not merely bad.
    if ($link !== '') $tokens['unsubscribe'] = esc_url_raw($link);
    return $tokens;
}, 20);

/* ── The page ─────────────────────────────────────────────────────────────────
   Small, standalone, no login, theme-independent. Rendered on template_redirect, so
   it needs NO nginx change — VERIFIED on dev2 by reading the live config rather than
   assuming (2026-07-29):
     * nothing anywhere in /etc/nginx/ mentions "discussion", so no block claims it;
     * the vhost's catch-all is `location / { try_files $uri $uri/ /index.php?$args; }`
       — the WordPress fallback, which /discussions/unsubscribe/ reaches;
     * and no REGEX location intercepts it first. That check matters specifically
       because a regex location outranks a prefix one — but every regex block here is
       extension-anchored (\.php$, the static-asset alternation, wp-config), and this
       path has no extension.
   So SPEC §10 item 8's "route for the unsub page" is a no-op on this vhost. Recorded
   because the NEXT box may differ: if a vhost ever adds a /discussions prefix or a
   broader regex, this page stops resolving with no other symptom.

   ── ⚠️ PRIORITY 5, AND WHY IT IS LOAD-BEARING (exercise pass, 2026-07-29) ─────
   BuddyPress registers bp_template_redirect on this same hook at priority 10, and
   for a LOGGED-OUT visitor it 302s to
       /wp-login.php?…&bp-auth=1&action=bpnoaccess
   Measured on dev2: with our callback also at 10, the ONLY thing that saved this
   page was registration order — mu-plugins load before regular plugins, so ours
   happened to run first and exit. That is luck, not design, and the audience it
   would fail is precisely the one this feature exists for: a logged-out member
   clicking unsubscribe from their inbox. The symptom would be a login wall, which
   reads as "the unsubscribe link is broken" and would be reported as such.

   Priority 5 makes it deterministic regardless of load order. It also puts us ahead
   of bbp_template_redirect (8) and redirect_canonical (10); neither claims this
   path, and running first is correct for a page that owns its URL outright.

   PROVEN BOTH WAYS on dev2: at priority 10 with the plugin loaded LAST → 302 to
   wp-login (the failure reproduced); at priority 5 → 200 and the confirmation page,
   with the plugin loaded last. */
add_action('template_redirect', function () {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (rtrim($path, '/') !== rtrim(LG_DISC_UNSUB_PATH, '/')) return;

    $isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
    $src    = $isPost ? $_POST : $_GET;
    $uid    = (int)    ($src['uid'] ?? 0);
    $tid    = (int)    ($src['tid'] ?? 0);
    $nh     = (string) ($src['nh']  ?? '');
    $scope  = (string) ($src['scope'] ?? 'topic');      // 'topic' | 'all'
    $undo   = !empty($src['undo']);

    if (!lg_disc_unsub_verify($tid, $uid, $nh)) {
        status_header(403);
        lg_disc_unsub_render('This link is no longer valid.',
            '<p>It may have been used already, or the link was incomplete. '
            . 'You can change email settings for any discussion from the discussion itself.</p>', $tid, $uid, $nh, false);
    }

    $topic = get_post($tid);
    $title = ($topic && $topic->post_type === 'topic') ? get_the_title($tid) : 'this discussion';

    if (!$isPost) {
        // GET → ASK. Never mutate here; see the prefetch note in the header.
        $body = '<p>You are receiving emails when someone replies to '
              . '<strong>' . esc_html($title) . '</strong>.</p>'
              /* Naming what is NOT affected is the honest half: the two bits are
                 independent, and a member who stops emails has not gone dark. */
              . '<p class="lg-u-note">Your notifications for this discussion are not affected — '
              . 'you&rsquo;ll still see new replies in your notifications.</p>'
              . lg_disc_unsub_form($tid, $uid, $nh, 'topic', 'Stop emails from this discussion', 'primary')
              . lg_disc_unsub_form($tid, $uid, $nh, 'all', 'Stop ALL discussion emails', 'secondary')
              . '<p class="lg-u-note">&ldquo;All discussion emails&rdquo; stops reply emails from every '
              . 'discussion. You can turn it back on in your account settings at any time.</p>';
        lg_disc_unsub_render('Stop emails about &lsquo;' . esc_html($title) . '&rsquo;?', $body, $tid, $uid, $nh, false);
    }

    // POST → ACT.
    $masterKey = lg_disc_unsub_master_key();
    if ($scope === 'all') {
        // Writes the SAME store that already gates every send, which is why the
        // account page can never claim "off" while mail still arrives (§6).
        if ($undo) delete_user_meta($uid, $masterKey);
        else       update_user_meta($uid, $masterKey, 'no');
    } else {
        if ($undo) { if (function_exists('bbp_add_user_subscription'))    bbp_add_user_subscription($uid, $tid); }
        else       { if (function_exists('bbp_remove_user_subscription')) bbp_remove_user_subscription($uid, $tid); }
        // bbp_subscriptions_handler is the UI FORM-handler action and does not fire on
        // programmatic writes, so the PG mirror needs the dispatch explicitly.
        if (function_exists('bb_mirror_sync_dispatch')) {
            bb_mirror_sync_dispatch('subscription', $tid, $undo ? 'subscribe' : 'unsubscribe', ['user_id' => $uid]);
        }
    }

    $done = $undo
        ? ($scope === 'all' ? 'Discussion emails are back on.' : 'Emails for this discussion are back on.')
        : ($scope === 'all' ? 'All discussion emails are off.' : 'Emails for this discussion are off.');
    $body = '<p>' . esc_html($scope === 'all'
            ? ($undo ? 'You will receive reply emails again.' : 'You will no longer receive reply emails from any discussion.')
            : ($undo ? 'You will receive reply emails from this discussion again.'
                     : 'You will no longer receive reply emails from ' . $title . '.')) . '</p>'
          // A mis-click is otherwise unrecoverable without finding the thread again.
          . lg_disc_unsub_form($tid, $uid, $nh, $scope, $undo ? 'Redo' : 'Undo', 'secondary', !$undo);
    if (!$undo && $scope !== 'all') {
        $body .= '<p class="lg-u-note">You&rsquo;ll still see new replies in your notifications.</p>';
    }
    lg_disc_unsub_render($done, $body, $tid, $uid, $nh, true);
}, 5);   // ⚠️ priority 5 — NOT default 10. See below; measured, not stylistic.

function lg_disc_unsub_form(int $tid, int $uid, string $nh, string $scope, string $label, string $kind, bool $undo = false): string
{
    return '<form method="post" action="' . esc_url(home_url(LG_DISC_UNSUB_PATH)) . '">'
        . '<input type="hidden" name="uid"   value="' . (int) $uid . '">'
        . '<input type="hidden" name="tid"   value="' . (int) $tid . '">'
        . '<input type="hidden" name="nh"    value="' . esc_attr($nh) . '">'
        . '<input type="hidden" name="scope" value="' . esc_attr($scope) . '">'
        . ($undo ? '<input type="hidden" name="undo" value="1">' : '')
        . '<button type="submit" class="lg-u-btn lg-u-btn--' . esc_attr($kind) . '">' . $label . '</button>'
        . '</form>';
}

/** Self-contained page — no theme, no login, light + dark. Always exits. */
function lg_disc_unsub_render(string $heading, string $body, int $tid, int $uid, string $nh, bool $acted): void
{
    $back = $tid > 0 ? lg_disc_unsub_topic_url($tid) : '';
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow', true);
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= esc_html(wp_strip_all_tags($heading)) ?></title>
<style>
  :root{--bg:#f4f1e9;--card:#fff;--ink:#23291f;--mute:#6b6f6b;--line:#e3ddd0;--sage:#87986a;--sage-d:#586b3f;--tint:#eef2e3}
  @media (prefers-color-scheme:dark){:root{--bg:#15171a;--card:#1c1f22;--ink:#e5e7e1;--mute:#9aa097;--line:#2c312d;--tint:#22262a}}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
       background:var(--bg);color:var(--ink);
       font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,system-ui,sans-serif}
  .lg-u{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:28px 26px;max-width:520px;width:100%;
        box-shadow:0 10px 34px rgba(0,0,0,.09)}
  h1{margin:0 0 14px;font-size:21px;line-height:1.3}
  p{margin:0 0 14px}
  .lg-u-note{color:var(--mute);font-size:14px}
  form{margin:0 0 10px}
  .lg-u-btn{-webkit-appearance:none;appearance:none;width:100%;cursor:pointer;border-radius:10px;
            padding:13px 16px;font:600 15px/1 inherit;border:1px solid var(--line);background:none;color:var(--ink)}
  .lg-u-btn--primary{background:var(--sage-d);border-color:var(--sage-d);color:#fff}
  .lg-u-btn--primary:hover{background:var(--sage);border-color:var(--sage)}
  .lg-u-btn--secondary:hover{background:var(--tint)}
  .lg-u-links{margin-top:18px;padding-top:14px;border-top:1px solid var(--line);font-size:14px}
  .lg-u-links a{color:var(--sage-d);text-decoration:none;margin-right:14px}
  @media (prefers-color-scheme:dark){.lg-u-links a{color:#9cb37d}}
  .lg-u-links a:hover{text-decoration:underline}
</style></head>
<body><main class="lg-u"><h1><?= $heading ?></h1><?= $body ?>
<div class="lg-u-links">
  <?php if ($back): ?><a href="<?= esc_url($back) ?>">Open the discussion</a><?php endif; ?>
  <?php if (get_current_user_id()): ?><a href="<?= esc_url(home_url('/my-account/')) ?>">Email settings</a><?php endif; ?>
</div>
</main></body></html><?php
    exit;
}

/** The Hub deep link, matching notify-bridge's shape so the two never diverge. */
function lg_disc_unsub_topic_url(int $topicId): string
{
    if (!function_exists('bbp_get_topic_forum_id')) return '';
    $topicSlug = (string) get_post_field('post_name', $topicId);
    $forumId   = (int) bbp_get_topic_forum_id($topicId);
    $forumSlug = $forumId ? (string) get_post_field('post_name', $forumId) : '';
    if ($topicSlug === '' || $forumSlug === '') return '';
    return home_url('/hub/') . '?topic=' . rawurlencode($forumSlug . '/' . $topicSlug);
}
