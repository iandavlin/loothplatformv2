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

/**
 * Per-row 🔔/✉ TOGGLES on this page. DEFAULT OFF.
 *
 * Ian, 2026-07-31: "on the account manage page, they cant change the setting,
 * just close it out, could they change the toggles on that page too?" He is
 * describing a real dead end — the row's only action is the X, so a member who
 * wants to keep reading a thread but stop the EMAIL has no move except leaving
 * the discussion altogether.
 *
 * ⚠️ THIS REVERSES A DELIBERATE EARLIER DECISION, and the reversal is his to
 * make. The marks were built to REPORT and not toggle, because thread-follow's
 * own mock (footer-mockups/threadfollow-notif-panel/mock-account.html) rated a
 * switch-per-row BAD: "unbounded — someone following thirty threads gets a
 * thirty-row settings page." That verdict is not wrong; it is answered. The list
 * is bounded at LG_FOLLOWING_PAGE_SIZE with "Show all", and "Stop all" is the
 * single make-it-stop control the verdict said was missing. Those two are what
 * make a per-row switch safe here, so if either is ever removed, this should go
 * back to reporting.
 *
 * OFF is a byte-identical no-op: the same <span> markup, no data attributes, no
 * behaviour — see the gate, which asserts the OFF state rather than assuming it.
 * Flag pattern copied from LG_AUTHOR_SOCIALS_ALL_MEMBERS
 * (platform/mu-plugins/lg-author-socials.php:48).
 */
/**
 * The account-level EMAIL CADENCE control. DEFAULT OFF, and it must stay off.
 *
 * Ian, 2026-07-31: "we need the email frequency on there too."
 *
 * ⚠️ HIDDEN UNTIL THE BATCHER IS REAL, and that is a RULE, not caution.
 * THREAD-FOLLOW-SPEC §15.4: do not ship a cadence control that silently does
 * nothing. thread-follow already built the same control in the follow modal and
 * shipped it dark for exactly this reason (forums.js:4294, FREQ_ENABLED=false) —
 * a control on THIS page that stores a value no sender reads is precisely as
 * dishonest as one in the modal. Measured 2026-07-31: `cadence` appears in
 * follow.php zero times on main AND on origin/thread-follow, and wp_usermeta
 * holds zero lg_disc_email_cadence rows. Nothing writes it, nothing sends on it.
 *
 * IT FLIPS ON follow-digest's SIGNAL, relayed by keeper to Ian (their ruling 3).
 * Not by me, and not because this page happens to be ready.
 *
 * ── ONE SETTING, TWO SURFACES, AND NO STORE OF MINE ──────────────────────────
 * The value lives in WP usermeta as lg_disc_email_cadence ∈ {instant, daily,
 * weekly}, absent ⇒ instant — follow-digest's §2.1, and explicitly NOT
 * forums.topic_follow, which is per-thread while this is per-account. I define
 * no store, no endpoint and no migration: this page READS the cadence from
 * follow.php's GET envelope (§2.3) and, once the seam is confirmed on the board,
 * will write through that same endpoint's POST. Three lanes touching one setting
 * is how drift gets built in, so this one owns none of it.
 */
if (!defined('LG_FOLLOWING_CADENCE')) {
    define('LG_FOLLOWING_CADENCE',
        (($_SERVER['LG_FOLLOWING_CADENCE'] ?? '') === '1'));
}

/** The allow-list, as ruled. Hourly is out (measured: no member has ever had two
 *  forum notifications in one hour); "Off" is out because the ✉ toggle owns
 *  on/off and a cadence that can mean "never" is two settings wearing one hat. */
const LG_FOLLOWING_CADENCES = ['instant' => 'Instant', 'daily' => 'Daily', 'weekly' => 'Weekly'];

if (!defined('LG_FOLLOWING_ROW_TOGGLES')) {
    // Server-settable so a lane preview can show Ian the ON state without the
    // flag being on for anyone else. fastcgi_param only — an nginx block on this
    // box can set it, a query string never can. Absent (every normal request on
    // dev and live) => false, and false is the byte-identical no-op.
    define('LG_FOLLOWING_ROW_TOGGLES',
        (($_SERVER['LG_FOLLOWING_ROW_TOGGLES'] ?? '') === '1'));
}

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
 * Where a row's title links to: THE HUB, WITH THAT DISCUSSION OPEN.
 *
 * Ian, 2026-07-31, on the first version: "the link in the manage goes to the old
 * foum not to the hub with the right modal open."
 *
 * The addressable form is the §4f deep link (bb-mirror/web/forums.js:5355):
 *
 *     /hub/?topic=<forum-slug>/<topic-slug>
 *
 * a query param on the FEED route, which auto-opens the §4e discussion modal —
 * the loaded card if the feed already has it, else a standalone fetch of the
 * canonical permalink poured into a synthetic card (the cold deep-link path).
 * One contract, both surfaces: ≥641 the desktop dmodal, ≤640 hub-polish.js's
 * #looth-rep-sheet via window.lgOpenTopicMobile. That is exactly what Ian asked
 * for, and it is why this does NOT link /hub/<forum>/<topic>/ — that is the
 * standalone permalink, the right thing to SHARE and the wrong thing to arrive
 * at from your account page.
 *
 * Both slugs are needed: §4f's parseTopicParam requires two path parts and
 * returns null on one, so the modal would silently never open.
 *
 * ── HIDDEN GROUP FORUMS GET NO LINK, DELIBERATELY ────────────────────────────
 * The hub cannot serve them and it is not an oversight: _single-topic.php gates
 * BOTH its lookups on `f.visibility = 'public'` (:53 and :72), and the feed does
 * the same (forums/index.php:33). So for a topic in a hidden BuddyBoss group
 * forum there is no loaded card AND the cold fetch 404s — the deep link would
 * open nothing. Measured on dev2 2026-07-31 as an authenticated ADMIN, not
 * anonymously: /hub/the-jannies-3/to-do-list/ is 404 for a signed-in member too.
 *
 * The earlier version sent those rows to the BuddyBoss group permalink. It
 * worked, and Ian rejected it — the old forum is not where members get sent from
 * their account page. So the row renders with NO link and says where it lives
 * instead. That is INTERIM: the real fix is for the hub to serve group forums to
 * their own members, which is bb-mirror's gate to change, not this page's. Never
 * silently restore the old-forum link — it is a rejected behaviour, not a
 * fallback.
 */
function lg_following_topic_url(array $t, array $group_slugs): string {
    $topic_slug = (string) ($t['slug'] ?? '');
    $forum_slug = (string) ($t['forum_slug'] ?? '');
    if ($topic_slug === '' || $forum_slug === '') return '';

    // Hidden forum → the hub has nothing to open. No link beats a dead link, and
    // beats a link to a UI Ian has ruled out.
    if ((string) ($t['forum_visibility'] ?? 'public') !== 'public') return '';

    // encodeURIComponent's shape, matching §4f's own shareUrl(): the slash between
    // the two slugs is encoded, which is what the address bar and the weekly
    // digest links already produce.
    return '/hub/?topic=' . rawurlencode($forum_slug . '/' . $topic_slug);
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
            // The row has no link and it is NOT broken: the hub does not serve
            // hidden group forums, so the page says where the discussion lives
            // rather than rendering a title that goes nowhere.
            'private'    => !$gone && (string) ($r['forum_visibility'] ?? 'public') !== 'public',
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
