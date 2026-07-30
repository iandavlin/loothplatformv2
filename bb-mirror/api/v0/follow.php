<?php
/**
 * bb-mirror/api/v0/follow.php — the TWO per-discussion opt-in bits.
 *
 * SPEC: docs/atlas/THREAD-FOLLOW-SPEC.md §3.2. Lane: thread-follow, 2026-07-28.
 *
 *   GET  /bb-mirror-api/v0/follow?topics=12,44,91        cookie-authed (or anon)
 *          → { authenticated:bool, nonce:string, email_master:bool,
 *              state:{ "12":{notify:true,email:false}, … } }
 *
 *   POST /bb-mirror-api/v0/follow                        cookie-authed + X-WP-Nonce
 *          { topic_id:int, channel:'notify'|'email', on:bool }
 *          → { ok:true, topic_id:int, notify:bool, email:bool, email_master:bool }
 *
 * TWO INDEPENDENT BITS, BOTH DEFAULT OFF (Ian, §0 ruling 1). A member may hold
 * either, both or neither; turning one on NEVER turns the other on.
 *
 *   🔔 notify → forums.topic_follow      (PG `looth`; ours, see schema.pg.sql)
 *   ✉ email  → wp_bb_notifications_subscriptions via bbPress-compat API (BB's
 *               mailer already reads it, so the send path needs no new machinery)
 *
 * ── THIS ENDPOINT IS THE ONLY CALLER OF THE SUBSCRIPTION WRITERS ──────────────
 * No path from bbp_new_topic, bbp_new_reply, reply.php or the mention legs reaches
 * bbp_add_user_subscription. That is the STRUCTURAL enforcement of "nothing
 * auto-subscribes" (§3.2) — a lane adding one is a spec violation, not a bug.
 *
 * ── SELF-SCOPED, ALWAYS ──────────────────────────────────────────────────────
 * The acting user is ALWAYS get_current_user_id(). The body carries a topic and a
 * channel, NEVER a user id — there is no shape of request that lets one member
 * change another member's bits.
 *
 * Auth posture mirrors reply.php exactly (:81-84 401, :281 wp_rest nonce). The
 * write-freeze map (lg-write-freeze-map.conf:7-10) already catches all
 * bb-mirror-api writes by prefix, so this is correctly frozen during a freeze with
 * no change needed.
 */

require __DIR__ . '/../../config.php';

if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST']   ??= LG_BB_MIRROR_HOST;
$_SERVER['REQUEST_URI'] ??= '/';
require LG_BB_MIRROR_WP_LOAD;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function follow_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

/**
 * Is discussion-reply EMAIL enabled for this member at the ACCOUNT level?
 *
 * ⚠️ THIS IS THE §8.1.3(a) FIX, AND IT IS A BUILD REQUIREMENT OF IAN'S 2026-07-28
 * RULING — "the UI must tell the truth about what is actually going to happen to
 * that member."
 *
 * A subscription row is NOT sufficient for mail to arrive. BuddyBoss gates every
 * send on bb_is_notification_enabled() (class-bp-forums-notification.php:1055).
 * Measured on LIVE 2026-07-28: 40 subscription rows across 7 members would render
 * a lit envelope while no email will EVER be sent to them. Returning this bit lets
 * the UI say "emails are off for your account" instead of lying.
 *
 * The key selection mirrors BB's own sender (…:1009-1012) rather than hardcoding,
 * because live runs MODERN preference mode but the legacy mode is still reachable
 * via the bb_enable_legacy_notification_preference filter.
 *
 * Fails OPEN (true) when BB's helpers are absent: an unknown gate must not make the
 * UI claim mail is suppressed when it may not be.
 */
function follow_email_master(int $uid): bool {
    if ($uid < 1 || !function_exists('bb_is_notification_enabled')) return true;
    $key = 'notification_forums_following_reply';
    if (function_exists('bb_enabled_legacy_email_preference')
        && function_exists('bb_get_prefences_key')
        && !bb_enabled_legacy_email_preference()) {
        $modern = bb_get_prefences_key('legacy', $key);
        if (is_string($modern) && $modern !== '') $key = $modern;   // → bb_forums_subscribed_reply
    }
    return (bool) bb_is_notification_enabled($uid, $key);
}

/** 🔔 — does this member follow this topic? (PG, forums.topic_follow) */
function follow_notify_state(int $uid, array $topicIds): array {
    $out = [];
    if ($uid < 1 || !$topicIds) return $out;
    try {
        $pdo = bb_mirror_db(true);
        $in  = implode(',', array_fill(0, count($topicIds), '?'));
        $st  = $pdo->prepare("SELECT topic_id FROM topic_follow WHERE user_id = ? AND topic_id IN ($in)");
        $st->execute(array_merge([$uid], $topicIds));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) $out[(int) $tid] = true;
    } catch (Throwable $e) {
        error_log('[follow] notify read failed: ' . $e->getMessage());
    }
    return $out;
}

/** ✉ — is this member subscribed to this topic? (native BB store) */
function follow_email_state(int $uid, int $topicId): bool {
    if ($uid < 1 || $topicId < 1 || !function_exists('bbp_is_user_subscribed')) return false;
    return (bool) bbp_is_user_subscribed($uid, $topicId);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$uid    = get_current_user_id();

// ── GET — the BATCH read. Load-bearing: a feed page renders many cards and each
//    needs both bits, so this takes a topic LIST and resolves auth + nonce + state
//    in ONE round trip. Mirrors /archive-api/v0/save-post?items= (forums.js:3809)
//    so the client module is a near-copy of the fc-save one. ────────────────────
if ($method === 'GET') {
    $raw   = (string) ($_GET['topics'] ?? '');
    $ids   = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), fn($n) => $n > 0)));
    $ids   = array_slice($ids, 0, 200);           // a feed page cannot legitimately ask for more

    if ($uid < 1) {
        // Anon: inert and CSS-hidden client-side (body.fc-save-anon precedent,
        // forums.css:585). No nonce, no state — never an error.
        follow_out(200, ['authenticated' => false, 'nonce' => '', 'email_master' => false, 'state' => (object) []]);
    }

    $notify = follow_notify_state($uid, $ids);
    $state  = [];
    foreach ($ids as $tid) {
        $state[(string) $tid] = [
            'notify' => isset($notify[$tid]),
            'email'  => follow_email_state($uid, $tid),
        ];
    }

    follow_out(200, [
        'authenticated' => true,
        'nonce'         => wp_create_nonce('wp_rest'),
        // Per Ian's ruling: the client renders the ✉ bit honestly, and when this is
        // false it must say emails are off account-wide rather than imply delivery.
        'email_master'  => follow_email_master($uid),
        'state'         => $state ?: (object) [],
    ]);
}

if ($method !== 'POST') {
    follow_out(405, ['ok' => false, 'error' => 'method', 'message' => 'GET/POST only.']);
}

// ── POST — the write. ────────────────────────────────────────────────────────
if ($uid < 1) {
    follow_out(401, ['ok' => false, 'error' => 'auth', 'message' => 'Sign in to follow a discussion.']);
}
// CSRF: the same wp_rest nonce reply.php verifies (:281).
if (!wp_verify_nonce((string) ($_SERVER['HTTP_X_WP_NONCE'] ?? ''), 'wp_rest')) {
    follow_out(403, ['ok' => false, 'error' => 'nonce', 'message' => 'Session expired — reload and retry.']);
}

$body    = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$topicId = (int) ($body['topic_id'] ?? 0);
$action  = (string) ($body['action'] ?? '');

if ($topicId < 1) {
    follow_out(400, ['ok' => false, 'error' => 'topic_id', 'message' => 'A topic id is required.']);
}

// §3.6 remove-my-mention shares this endpoint in the spec. NOT BUILT YET — it is a
// post_content rewrite against the stored mention anchor, not a subscription write,
// and half of it is worse than none. Declared rather than silently missing.
if ($action === 'remove_mention') {
    follow_out(501, ['ok' => false, 'error' => 'not_implemented',
                     'message' => 'Remove-my-mention is specified (§3.6) but not built yet.']);
}

// The topic must be a real, published topic — never mint a follow for a dead id.
$post = get_post($topicId);
if (!$post || $post->post_type !== 'topic' || $post->post_status !== 'publish') {
    follow_out(404, ['ok' => false, 'error' => 'no_topic', 'message' => 'That discussion no longer exists.']);
}

$channel = (string) ($body['channel'] ?? '');
if (!in_array($channel, ['notify', 'email'], true)) {
    follow_out(400, ['ok' => false, 'error' => 'channel', 'message' => "channel must be 'notify' or 'email'."]);
}
if (!array_key_exists('on', $body)) {
    follow_out(400, ['ok' => false, 'error' => 'on', 'message' => 'on:bool is required.']);
}
$on = (bool) filter_var($body['on'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

try {
    if ($channel === 'notify') {
        // 🔔 — ours. Idempotent both ways; ON CONFLICT DO NOTHING so a double-click
        // is a no-op rather than an error the optimistic UI has to revert.
        $pdo = bb_mirror_db(false);
        if ($on) {
            $st = $pdo->prepare('INSERT INTO topic_follow (user_id, topic_id) VALUES (:u, :t)
                                 ON CONFLICT (user_id, topic_id) DO NOTHING');
        } else {
            $st = $pdo->prepare('DELETE FROM topic_follow WHERE user_id = :u AND topic_id = :t');
        }
        $st->execute([':u' => $uid, ':t' => $topicId]);
    } else {
        // ✉ — the native BB subscription.
        if (!function_exists('bbp_add_user_subscription') || !function_exists('bbp_remove_user_subscription')) {
            follow_out(503, ['ok' => false, 'error' => 'no_bbp', 'message' => 'Subscriptions are unavailable.']);
        }
        if ($on) {
            bbp_add_user_subscription($uid, $topicId);
        } else {
            bbp_remove_user_subscription($uid, $topicId);
        }
        // EXPLICIT mirror dispatch — required. bbp_subscriptions_handler
        // (bb-mirror-sync.php:324) is the UI FORM-handler action and does NOT fire
        // on programmatic writes, so without this the PG mirror silently drifts.
        if (function_exists('bb_mirror_sync_dispatch')) {
            bb_mirror_sync_dispatch('subscription', $topicId, $on ? 'subscribe' : 'unsubscribe', ['user_id' => $uid]);
        }
    }
} catch (Throwable $e) {
    error_log('[follow] write failed uid=' . $uid . ' topic=' . $topicId . ' ch=' . $channel . ': ' . $e->getMessage());
    follow_out(500, ['ok' => false, 'error' => 'write_failed', 'message' => 'Could not save that. Try again.']);
}

// Re-read rather than echo the intent back: the response is what the store now
// SAYS, so an optimistic client that reverts on mismatch reverts on truth.
$notify = follow_notify_state($uid, [$topicId]);
follow_out(200, [
    'ok'           => true,
    'topic_id'     => $topicId,
    'notify'       => isset($notify[$topicId]),
    'email'        => follow_email_state($uid, $topicId),
    'email_master' => follow_email_master($uid),
]);
