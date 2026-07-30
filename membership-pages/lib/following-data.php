<?php
/**
 * membership-pages/lib/following-data.php — "Discussions you're following".
 *
 * Ian, 2026-07-30 (via keeper): "in the manage my account page a section for
 * posts I'm following." Scope, same conversation: DISCUSSIONS ONLY. Follow does
 * not exist for articles or events (bb-mirror/api/v0/follow.php:176 gates on
 * post_type !== 'topic'), and extending it was offered and declined.
 *
 * ── THE SHAPE OF THE PROBLEM: ONE FEATURE, TWO DATABASES ─────────────────────
 * "Following" is two independent bits, and they live in different engines:
 *
 *   🔔 notify → POSTGRES  forums.topic_follow          (ours; schema.pg.sql:254)
 *   ✉ email  → MYSQL     wp_usermeta._bbp_subscriptions (bbPress's own store)
 *
 * A member may hold either, both or neither (follow.php:15). So the list this
 * page renders is the UNION of the two, not either one — a discussion that only
 * emails you is still a discussion you are following, and it is the one you are
 * most likely to have come here to stop.
 *
 * ── WHY THE ✉ SIDE READS usermeta AND NOT THE BuddyBoss TABLE ────────────────
 * wp_bb_notifications_subscriptions also holds topic rows and looks like the
 * obvious source. It is not the one bbPress answers from. Measured on dev2
 * 2026-07-30: bbp_get_user_subscribed_topic_ids(1) returns exactly the
 * wp__bbp_subscriptions CSV reversed, id for id. Since follow.php's write path
 * goes through bbp_add/remove_user_subscription, the CSV is what its own reads
 * agree with — so this is the store that matches the endpoint we write through.
 *
 * ── AND WHY NEITHER SIDE READS forums.forum_subscription ─────────────────────
 * That PG table mirrors the ✉ bit and would make this a single-database read.
 * IT IS DRIFTED ON DEV2 RIGHT NOW: it claims user 1 is subscribed to topic 72330
 * while bbp_is_user_subscribed(1, 72330) is false and the id is absent from the
 * usermeta CSV. The mirror is maintained by one explicit dispatch call
 * (follow.php:214); when that leg drops, the mirror keeps a subscription the
 * mailer will never honour. Reading it here would make this page claim a member
 * gets email they do not get — the exact §8.1.3(a) lie Ian's 2026-07-28 ruling
 * exists to prevent. Reported to thread-follow; not ours to fix.
 *
 * ── CONNECTIONS ──────────────────────────────────────────────────────────────
 * MySQL: the page's existing read-only PDO (lg_membership_db()).
 * Postgres: bb-mirror's OWN bb_mirror_db(), by requiring its config, so the DSN,
 * search_path and PDO attributes can never drift from the app that owns the
 * store. This surface runs on the `membership` FPM pool, which had no Postgres
 * identity at all until 2026-07-30 — see LG_FOLLOWING_PG_ROLE below.
 *
 * Everything here is SELECT. The only writes in this feature are the member's
 * own unfollow clicks, and those go out through follow.php's POST — there is no
 * second write path to topic_follow.
 */

declare(strict_types=1);

/**
 * The Postgres role this pool authenticates as (unix-socket peer auth, so the
 * role name must equal the pool's unix user: `membership`).
 *
 * PROVISIONED 2026-07-30, read-only, three tables:
 *     CREATE ROLE "membership" LOGIN;
 *     GRANT CONNECT ON DATABASE looth TO "membership";
 *     GRANT USAGE ON SCHEMA forums TO "membership";
 *     GRANT SELECT ON forums.topic_follow, forums.topic, forums.forum
 *           TO "membership";
 * Rollback is one line: DROP OWNED BY "membership"; DROP ROLE "membership";
 *
 * Verified read-only by attempting a DELETE on topic_follow (permission denied)
 * and a SELECT on forums.reply (permission denied). LIVE STILL NEEDS THIS ROLE —
 * without it the section degrades as described in lg_following_list().
 */
const LG_FOLLOWING_PG_ROLE = 'membership';

/** How many rows render before "Show all". Bounded on purpose — see the section comment. */
const LG_FOLLOWING_PAGE_SIZE = 5;

if (!function_exists('lg_following_pg')) {
/**
 * Postgres handle, using bb-mirror's own connection builder.
 *
 * Returns null rather than throwing: a Postgres outage must degrade this ONE
 * section, never take down a member's billing page.
 */
function lg_following_pg(): ?PDO {
    static $tried = false, $pdo = null;
    if ($tried) return $pdo;
    $tried = true;

    // /srv/membership-pages and /srv/bb-mirror are siblings in the same checkout,
    // so this relative path resolves in the serving tree and in a worktree alike.
    $cfg = __DIR__ . '/../../bb-mirror/config.php';
    try {
        if (!function_exists('bb_mirror_db')) {
            if (!is_readable($cfg)) {
                error_log('[following] bb-mirror config not readable at ' . $cfg);
                return null;
            }
            require_once $cfg;
        }
        $pdo = bb_mirror_db(true);
    } catch (Throwable $e) {
        error_log('[following] pg connect failed: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}
}

if (!function_exists('lg_following_notify_ids')) {
/**
 * 🔔 — topic ids this member follows. PG forums.topic_follow.
 *
 * @return array{0: int[], 1: bool}  [ids, ok] — ok=false means "could not read",
 *         which is NOT the same as "follows nothing" and must not render as it.
 */
function lg_following_notify_ids(int $uid): array {
    if ($uid < 1) return [[], true];
    $pdo = lg_following_pg();
    if (!$pdo) return [[], false];
    try {
        $st = $pdo->prepare('SELECT topic_id FROM topic_follow WHERE user_id = :u');
        $st->execute([':u' => $uid]);
        return [array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), true];
    } catch (Throwable $e) {
        error_log('[following] notify read failed uid=' . $uid . ': ' . $e->getMessage());
        return [[], false];
    }
}
}

if (!function_exists('lg_following_email_ids')) {
/**
 * ✉ — topic ids this member is subscribed to. MySQL wp_usermeta CSV.
 *
 * bbPress stores the list as a comma-separated meta_value and returns it
 * REVERSED (newest first); order is irrelevant here because the list is sorted
 * by last activity, so only the id set matters.
 *
 * @return array{0: int[], 1: bool}  [ids, ok]
 */
function lg_following_email_ids(int $uid): array {
    if ($uid < 1) return [[], true];
    try {
        $stmt = lg_membership_db()->prepare(
            'SELECT meta_value FROM ' . LG_MEMBERSHIP_TABLE_PREFIX . 'usermeta
              WHERE user_id = ? AND meta_key = ? LIMIT 1'
        );
        $stmt->execute([$uid, LG_MEMBERSHIP_TABLE_PREFIX . '_bbp_subscriptions']);
        $csv = (string) ($stmt->fetchColumn() ?: '');
        $ids = array_values(array_filter(array_map('intval', explode(',', $csv)), fn($n) => $n > 0));
        return [$ids, true];
    } catch (Throwable $e) {
        error_log('[following] email read failed uid=' . $uid . ': ' . $e->getMessage());
        return [[], false];
    }
}
}

if (!function_exists('lg_following_group_slugs')) {
/**
 * group_id => group slug, for the hidden-forum permalinks below. MySQL.
 */
function lg_following_group_slugs(array $group_ids): array {
    $group_ids = array_values(array_unique(array_filter(array_map('intval', $group_ids))));
    if (!$group_ids) return [];
    try {
        $in   = implode(',', array_fill(0, count($group_ids), '?'));
        $stmt = lg_membership_db()->prepare(
            "SELECT id, slug FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "bp_groups WHERE id IN ($in)"
        );
        $stmt->execute($group_ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int) $r['id']] = (string) $r['slug'];
        return $out;
    } catch (Throwable $e) {
        error_log('[following] group slug read failed: ' . $e->getMessage());
        return [];
    }
}
}

if (!function_exists('lg_following_topic_url')) {
/**
 * Where a row's title links to.
 *
 * /hub/ is the canonical reader and resolves a topic by its OWN slug — the forum
 * segment is decorative, which is why two forums sharing the slug `acoustic`
 * (ids 3823 and 3845) both resolve correctly.
 *
 * IT DOES NOT SERVE HIDDEN FORUMS. Two of the first real member's twelve
 * followed discussions are in "The Jannies" (forum 23813, visibility=hidden, a
 * BuddyBoss group forum) and /hub/the-jannies-3/to-do-list/ is a hard 404. For
 * those, link the group-native permalink instead: BuddyBoss gates it by group
 * membership, so a non-member gets its redirect rather than the content, and a
 * member gets the thread. A row whose link 404s is worse than no row.
 */
function lg_following_topic_url(array $t, array $group_slugs): string {
    $topic_slug = (string) ($t['slug'] ?? '');
    if ($topic_slug === '') return '';

    $hidden = (string) ($t['forum_visibility'] ?? 'public') !== 'public';
    if ($hidden) {
        $gs = $group_slugs[(int) ($t['group_id'] ?? 0)] ?? '';
        if ($gs !== '') return '/groups/' . rawurlencode($gs) . '/forum/topic/' . rawurlencode($topic_slug) . '/';
        return '';                                   // unknown group → no link, not a guess
    }
    $forum_slug = (string) ($t['forum_slug'] ?? '');
    if ($forum_slug === '') return '';
    return '/hub/' . rawurlencode($forum_slug) . '/' . rawurlencode($topic_slug) . '/';
}
}

if (!function_exists('lg_following_list')) {
/**
 * The section's whole payload.
 *
 * @return array{
 *   items: array<int, array>,   every followed discussion, newest activity first
 *   total: int,                 count of items
 *   notify_count: int,
 *   email_count: int,
 *   degraded: string,           '' | 'notify' | 'email' — a store we could NOT read
 *   hydrated: bool,             false = the topic mirror itself was unreadable
 * }
 *
 * `degraded` is not decoration. If Postgres is unreachable the 🔔-only rows are
 * simply absent, and a list that quietly drops rows while looking complete is a
 * lie of exactly the kind this feature exists to stop. The caller renders a
 * plain sentence saying which half could not be read.
 *
 * `hydrated` is the sharper case: the topic mirror itself was unreadable. This is
 * ORDINARY ERROR HANDLING for an unreachable store — a Postgres restart, a
 * saturated box, a revoked grant — and NOT a mode the feature is designed around.
 * The section assumes forums.topic_follow exists and is readable; that is the
 * contract, and thread-follow's migration makes it true on live in the same window
 * as the deploy (docs/UNDEPLOYED.md).
 *
 * It earns its place because without it the failure is silent and confident: every
 * ✉ row falls through the not-in-the-mirror branch and renders "This discussion is
 * no longer available", so a member is told eleven live discussions are dead.
 * "The mirror says this topic is gone" and "we could not reach the mirror" are
 * different facts and the page must not collapse them. On !hydrated the caller
 * drops the list, says so, and still offers Stop all — which needs only the ids.
 */
function lg_following_list(int $uid): array {
    $empty = ['items' => [], 'total' => 0, 'notify_count' => 0, 'email_count' => 0,
              'degraded' => '', 'hydrated' => true];
    if ($uid < 1) return $empty;

    [$notify_ids, $notify_ok] = lg_following_notify_ids($uid);
    [$email_ids,  $email_ok]  = lg_following_email_ids($uid);

    $degraded = !$notify_ok ? 'notify' : (!$email_ok ? 'email' : '');
    $notify   = array_fill_keys($notify_ids, true);
    $email    = array_fill_keys($email_ids,  true);
    $ids      = array_values(array_unique(array_merge($notify_ids, $email_ids)));
    if (!$ids) return $empty + ['degraded' => $degraded];

    // Hydrate titles / forum / last activity from the PG topic mirror. Both the
    // 🔔 and the ✉ ids resolve here — verified 12/12 for the first real member,
    // including the eight that are email-only and have no topic_follow row.
    $rows     = [];
    $hydrated = false;
    $pdo      = lg_following_pg();
    if ($pdo) {
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare(
                "SELECT t.id, t.slug, t.title, t.status, t.tier_gate, t.reply_count, t.last_active_at,
                        f.slug AS forum_slug, f.title AS forum_title,
                        f.visibility AS forum_visibility, f.group_id
                   FROM topic t
                   LEFT JOIN forum f ON f.id = t.forum_id
                  WHERE t.id IN ($in)"
            );
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int) $r['id']] = $r;
            $hydrated = true;
        } catch (Throwable $e) {
            error_log('[following] hydrate failed uid=' . $uid . ': ' . $e->getMessage());
            if ($degraded === '') $degraded = 'notify';
        }
    } elseif ($degraded === '') {
        $degraded = 'notify';
    }

    $group_slugs = lg_following_group_slugs(array_column($rows, 'group_id'));

    $items = [];
    foreach ($ids as $tid) {
        $r = $rows[$tid] ?? null;

        // A followed topic the mirror does not carry, or one that has been
        // trashed/spammed. Kept as a row rather than dropped, because it is
        // still costing the member email and they need a way to switch it off.
        $gone = !$r || !in_array((string) $r['status'], ['publish', 'closed'], true);

        $items[] = [
            'id'         => $tid,
            'title'      => $gone ? '' : (string) $r['title'],
            'url'        => $gone ? '' : lg_following_topic_url($r, $group_slugs),
            'forum'      => $gone ? '' : (string) ($r['forum_title'] ?? ''),
            'replies'    => $gone ? 0  : (int) $r['reply_count'],
            'active_at'  => $gone ? null : ($r['last_active_at'] ?? null),
            'tier_gate'  => $gone ? 'public' : (string) ($r['tier_gate'] ?? 'public'),
            'notify'     => isset($notify[$tid]),
            'email'      => isset($email[$tid]),
            'gone'       => $gone,
        ];
    }

    // Newest activity first; unresolvable rows sink to the bottom rather than
    // floating on a null that sorts high.
    usort($items, function (array $a, array $b): int {
        $at = $a['active_at'] ? strtotime((string) $a['active_at']) : 0;
        $bt = $b['active_at'] ? strtotime((string) $b['active_at']) : 0;
        return $bt <=> $at;
    });

    return [
        'items'        => $items,
        'total'        => count($items),
        'notify_count' => count($notify_ids),
        'email_count'  => count($email_ids),
        'degraded'     => $degraded,
        'hydrated'     => $hydrated,
    ];
}
}

if (!function_exists('lg_following_when')) {
/**
 * "yesterday" / "3 days ago" / "11 May" / "Sep 2025".
 *
 * Relative only inside a week — past that it is noise ("104 weeks ago" tells a
 * member nothing, and the first real member's oldest subscription is from 2023).
 */
function lg_following_when(?string $ts): string {
    if (!$ts) return '';
    $t = strtotime($ts);
    if (!$t) return '';
    $age = time() - $t;
    if ($age < 0)      return 'just now';
    if ($age < 3600)   return 'less than an hour ago';
    if ($age < 86400)  return 'today';
    if ($age < 172800) return 'yesterday';
    if ($age < 604800) return ((int) floor($age / 86400)) . ' days ago';
    if ((int) date('Y', $t) === (int) date('Y')) return date('j M', $t);
    return date('M Y', $t);
}
}
