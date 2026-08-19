<?php
/**
 * Plugin Name: LG Composer — categorize last (#129, ledger 44)
 * Description: Write first, categorize last. Reads the tracked flag/mapping config,
 *              registers Content Topics for discussions, and maps a picked topic to
 *              its forum. Everything here is inert while the flag is OFF.
 *
 * ── WHAT THIS FILE IS FOR ────────────────────────────────────────────────────
 * Ian ruled (8/16, refined 8/19) that the composer's required "Where" step goes
 * away: new discussions land in a default forum, and an OPTIONAL tag step at the
 * END maps taxonomy -> forum behind the scenes. This is the WordPress half — the
 * taxonomy and the mapping. The UI half is bb-mirror's hub app, which is a
 * different FPM pool with no WP loaded, which is exactly why the flag and the map
 * live in a tracked config file both can read (platform/config/composer-categorize-last.php).
 *
 * ── THE TAXONOMY IS NOT REGISTERED FOR `topic`, AND THAT IS THE WHOLE PROBLEM ─
 * Measured 2026-08-19 on dev2. `shared_category` (labelled "Content Topics") is
 * ACF-defined in the DATABASE at wp_posts 21219, and its object_type list is
 * exactly these 8:
 *
 *     post-type-videos, post-imgcap, post-regular, loothcuts,
 *     loothprint, useful_links, coe-questions, document
 *
 * `topic` is not among them. Consequence, also measured: 1,406 term assignments
 * exist and ZERO are on a discussion. So this feature is the FIRST EVER writer of
 * shared_category onto a topic, and without the registration below
 * wp_set_object_terms() would write rows that nothing reads.
 *
 * It is done HERE, in a tracked file, and NOT by editing the ACF definition —
 * because that definition lives in the database, and a DB edit is not traceable to
 * a commit. Per the repo rule: if it is not in the monorepo and traceable to a
 * commit, it does not exist.
 */

if (!defined('ABSPATH')) exit;

/* ─────────────────────────────────────────────────────────── the config ───── */

/**
 * The tracked config, plus the gitignored per-box override.
 *
 * Identical loader shape to lg_fc_enabled() in lg-frontend-compose.php, and for
 * the recorded reason: a pool env reaches FPM ONLY, so wp-cli, WP-cron and the
 * gates would read the opposite state from the serve. That mismatch is what turned
 * gate 35 red on a healthy box and 404'd /compose/ for an allowed admin after a
 * reboot, 8/15. One file on disk, every runtime reads the same truth.
 */
function lg_ccl_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $path = dirname(__DIR__) . '/config/composer-categorize-last.php';
    if (!is_readable($path)) {
        error_log('[lg-ccl] tracked config unreadable at ' . $path . ' — OFF (fail-closed)');
        return $cfg = ['enabled' => false, 'default_forum_id' => 0, 'taxo_forum_map' => []];
    }
    $raw = require $path;
    $cfg = is_array($raw) ? $raw : [];

    $local = dirname(__DIR__) . '/config/composer-categorize-last.local.php';
    if (is_readable($local)) {
        $lraw = require $local;
        if (is_array($lraw)) $cfg = array_merge($cfg, $lraw);
    }
    return $cfg;
}

/**
 * Is the feature on?
 *
 * Both getenv() AND $_SERVER, deliberately: tools/preview/lane-preview.sh gives a
 * branch a URL by setting fastcgi_param, and a fastcgi_param lands in $_SERVER but
 * not reliably in the process environment. A getenv()-only read serves the OFF
 * path on the very preview URL built for Ian to click. A fastcgi_param can only be
 * set by an nginx conf, never by a query string, so this is not a visitor switch.
 */
function lg_ccl_enabled(): bool
{
    static $on = null;
    if ($on !== null) return $on;
    if (getenv('LG_CCL_PREVIEW') === '1' || (($_SERVER['LG_CCL_PREVIEW'] ?? '') === '1')) {
        return $on = true;
    }
    return $on = (lg_ccl_config()['enabled'] ?? false) === true;
}

/** The forum an untagged discussion lands in. 0 = not configured (fail closed). */
function lg_ccl_default_forum_id(): int
{
    return (int)(lg_ccl_config()['default_forum_id'] ?? 0);
}

/** taxonomy term slug => postable forum ID. */
function lg_ccl_map(): array
{
    $m = lg_ccl_config()['taxo_forum_map'] ?? [];
    return is_array($m) ? $m : [];
}

/* ──────────────────────────────────────────── the postable contract ───── */

/**
 * May a topic be filed into this forum? WordPress's half of a contract that two
 * pools have to agree about.
 *
 * bb-mirror/web/_chrome.php asks Postgres the same question for the picker, and
 * bb-mirror/api/v0/reply.php enforces it on the topic-edit PUT. The exclusion list
 * is shared through config for exactly that reason — when only the picker knew,
 * a hand-built request could still file a post into a forum that was merely "not
 * offered". This function is the third caller of the same rule, so `wp lg-recat`
 * and the mapping cannot open a hole the UI closes.
 *
 * The rule: publish + forum_type 'forum' + not closed + has no sub-forums + not
 * explicitly excluded. "Has no sub-forums" matters because you post to a subforum,
 * never to its container.
 */
function lg_ccl_forum_postable(int $forum_id): bool
{
    if ($forum_id <= 0) return false;

    $p = get_post($forum_id);
    if (!$p || $p->post_type !== 'forum' || $p->post_status !== 'publish') return false;

    // Mirrors LG_BB_MIRROR_NONPOSTABLE_FORUM_IDS (bb-mirror/config.php). Kept as a
    // literal here ONLY because WordPress cannot read bb-mirror's config, and
    // asserted equal to it by the gate so the two can never drift apart.
    if (in_array($forum_id, lg_ccl_nonpostable_forum_ids(), true)) return false;

    if ((string)get_post_meta($forum_id, '_bbp_forum_type', true) === 'category') return false;
    if ((string)get_post_meta($forum_id, '_bbp_status', true) === 'closed')      return false;

    $kids = get_posts([
        'post_type'        => 'forum',
        'post_parent'      => $forum_id,
        'post_status'      => ['publish', 'private', 'hidden'],
        'posts_per_page'   => 1,
        'fields'           => 'ids',
        'suppress_filters' => true,
    ]);
    return empty($kids);
}

/** The shared exclusion list. Gate-asserted equal to bb-mirror's constant. */
function lg_ccl_nonpostable_forum_ids(): array
{
    // 3876  Quick Questions     — public, open, a leaf. Excluded by product decision.
    // 67251 Anonymous Questions — has its own posting route.
    return [3876, 67251];
}

/* ─────────────────────────────────────────────── taxonomy -> forum ───── */

/**
 * Which forum do these term slugs put the post in? Null = leave it where it is.
 *
 * MOST SPECIFIC WINS: a child term beats its parent, because "3D Printing" is a
 * better answer than "Tools, Spaces, Robots and Widgets". Among equals, the first
 * mapped slug in the caller's order wins.
 *
 * An unmapped slug is NOT an error — ruling (b), Ian 8/19: an unmapped topic lands
 * in the default forum and that is workable. 15 of the 36 terms are deliberately
 * unmapped; the config header says which and why.
 */
function lg_ccl_forum_for_terms(array $slugs): ?int
{
    $map = lg_ccl_map();
    if (!$map) return null;

    $best = null; $best_depth = -1;
    foreach ($slugs as $slug) {
        $slug = (string)$slug;
        if (!isset($map[$slug])) continue;
        $fid = (int)$map[$slug];
        if (!lg_ccl_forum_postable($fid)) {
            error_log('[lg-ccl] mapped forum ' . $fid . ' for term "' . $slug . '" is NOT postable — skipped');
            continue;
        }
        $depth = lg_ccl_term_depth($slug);
        if ($depth > $best_depth) { $best = $fid; $best_depth = $depth; }
    }
    return $best;
}

/** 0 for a top-level term, 1 for a child, and so on. */
function lg_ccl_term_depth(string $slug): int
{
    $t = get_term_by('slug', $slug, 'shared_category');
    if (!$t || is_wp_error($t)) return 0;
    $d = 0; $parent = (int)$t->parent;
    while ($parent > 0 && $d < 10) {
        $p = get_term($parent, 'shared_category');
        if (!$p || is_wp_error($p)) break;
        $d++; $parent = (int)$p->parent;
    }
    return $d;
}

/* ─────────────────────────────────────────────────── registration ───── */

/**
 * Make Content Topics assignable to discussions.
 *
 * Priority 20 on `init`: ACF registers its taxonomies on `init` at the default 10,
 * so calling this earlier would attach `topic` to a taxonomy that does not exist
 * yet and silently do nothing. The function is idempotent, and it is the ONLY
 * thing that makes the term rows this feature writes actually readable.
 *
 * Gated: with the flag OFF the taxonomy keeps exactly the 8 object types it has
 * had all along, which is what makes OFF a real no-op rather than a quiet schema
 * change.
 */
add_action('init', function () {
    if (!lg_ccl_enabled()) return;
    if (!taxonomy_exists('shared_category')) {
        error_log('[lg-ccl] shared_category not registered at init:20 — topic attach skipped');
        return;
    }
    register_taxonomy_for_object_type('shared_category', 'topic');
}, 20);

/* ══════════════════════════════════════════════════════════ the applier ═════
 *
 * ONE implementation, THREE callers: the composer (via the REST route below),
 * `wp lg-recat` (Ian's hand tool), and any supervised LLM batch driving that same
 * command. Plan v2 called this "a single motion", and it has to be one function or
 * the three callers drift.
 *
 * ⚠️ WHY THIS IS SIX STEPS AND NOT ONE — TWO MEASURED FACTS
 *
 * 1. A bbPress topic stores its forum in TWO places: `post_parent` AND the
 *    `_bbp_forum_id` meta. So does every one of its replies — measured on dev2,
 *    5,128 of 5,130 replies carry `_bbp_forum_id`, and the mirror's `reply` table
 *    has its own `forum_id` column. Move a topic and leave the replies and you get
 *    a thread whose posts claim to live in a forum the topic left.
 *
 * 2. IT IS RECORDED THAT A CHANGE WHICH DOES NOT BUMP `post_modified_gmt` NEVER
 *    REACHES THE FORUM MIRROR — confirmed specifically for the replies of a topic
 *    moved between forums, which is exactly this operation. A meta_update alone
 *    does not touch post_modified_gmt, so the bump is explicit here.
 *
 * Together those mean a version of this that "worked" in MySQL would leave the Hub
 * showing the old forum indefinitely. Verify a run by reading `forums.topic` /
 * `forums.reply` in POSTGRES, never the MySQL rows it just wrote.
 */

/**
 * Assign Content Topics to a discussion and re-home it to the mapped forum.
 *
 * @param int   $topic_id
 * @param array $slugs   shared_category term slugs
 * @param array $opts    forum:int|null explicit override · no_forum:bool leave it put
 *                       · append:bool (default true) · dry_run:bool · reason:string
 * @return array|WP_Error
 */
function lg_ccl_apply(int $topic_id, array $slugs, array $opts = [])
{
    $append  = (bool)($opts['append']  ?? true);
    $dry     = (bool)($opts['dry_run'] ?? false);
    $reason  = (string)($opts['reason'] ?? '');

    $topic = get_post($topic_id);
    if (!$topic || $topic->post_type !== 'topic') {
        return new WP_Error('lg_ccl_not_topic', "#$topic_id is not a discussion");
    }
    if (!taxonomy_exists('shared_category')) {
        return new WP_Error('lg_ccl_no_taxonomy', 'shared_category is not registered (flag OFF?)');
    }

    /* Validate EVERY slug before writing ANYTHING. All-or-nothing: a batch that
       half-applied would be worse than one that refused, because nothing downstream
       could tell which half. */
    $terms = [];
    foreach (array_unique(array_filter(array_map('strval', $slugs))) as $slug) {
        $t = get_term_by('slug', $slug, 'shared_category');
        if (!$t || is_wp_error($t)) {
            return new WP_Error('lg_ccl_bad_term', "no such Content Topic: \"$slug\"");
        }
        $terms[$slug] = (int)$t->term_id;
    }

    $cur_forum = (int)$topic->post_parent;
    $target    = null;
    if (!empty($opts['no_forum'])) {
        $target = null;
    } elseif (!empty($opts['forum'])) {
        $target = (int)$opts['forum'];
        if (!lg_ccl_forum_postable($target)) {
            return new WP_Error('lg_ccl_forum_not_postable',
                "forum #$target is not postable (category, closed, has sub-forums, or excluded)");
        }
    } else {
        $target = lg_ccl_forum_for_terms(array_keys($terms));
    }
    $move = ($target && $target !== $cur_forum) ? $target : null;

    $replies = get_posts([
        'post_type'        => 'reply',
        'post_parent'      => $topic_id,
        'post_status'      => ['publish', 'private', 'pending', 'spam', 'trash'],
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'suppress_filters' => true,
    ]);

    $plan = [
        'topic_id'    => $topic_id,
        'title'       => get_the_title($topic_id),
        'terms'       => array_keys($terms),
        'from_forum'  => $cur_forum,
        'to_forum'    => $move,
        'replies'     => count($replies),
        'append'      => $append,
        'dry_run'     => $dry,
    ];
    if ($dry) return $plan;

    /* 1 — the terms. */
    $set = wp_set_object_terms($topic_id, array_values($terms), 'shared_category', $append);
    if (is_wp_error($set)) return $set;

    /* 2 — the move, both stores WordPress owns. */
    if ($move) {
        wp_update_post(['ID' => $topic_id, 'post_parent' => $move]);
        update_post_meta($topic_id, '_bbp_forum_id', $move);

        foreach ($replies as $rid) {
            update_post_meta($rid, '_bbp_forum_id', $move);
            lg_ccl_touch_modified((int)$rid);          // else the mirror never hears
            bb_mirror_sync_dispatch_safe('reply', (int)$rid);
        }

        /* Counters on BOTH forums, or the old one keeps claiming the topic. */
        if (function_exists('bbp_update_forum')) {
            bbp_update_forum(['forum_id' => $move]);
            if ($cur_forum) bbp_update_forum(['forum_id' => $cur_forum]);
        }
    }

    /* 3 — the topic's own bump + dispatch, after the move so the mirror reads the
           new parent. */
    lg_ccl_touch_modified($topic_id);
    bb_mirror_sync_dispatch_safe('topic', $topic_id);

    /* 4 — an audit trail, so a later reader can tell Ian's hand call from a
           supervised LLM suggestion. */
    /* NOT (array)get_post_meta(...): an absent meta returns '' and (array)'' is
       [''], so the very first run left a junk empty element at index 0. Caught by
       reading the row back after the first real apply. */
    $log = get_post_meta($topic_id, '_lg_ccl_log', true);
    if (!is_array($log)) $log = [];
    $log[] = [
        'at'     => gmdate('c'),
        'user'   => get_current_user_id(),
        'terms'  => array_keys($terms),
        'moved'  => $move ? [$cur_forum, $move] : null,
        'reason' => $reason,
    ];
    update_post_meta($topic_id, '_lg_ccl_log', $log);

    $plan['applied'] = true;
    return $plan;
}

/**
 * Bump post_modified_gmt so the mirror sees the row as changed.
 *
 * Direct $wpdb because a meta-only edit leaves post_modified alone, and that is
 * precisely the recorded failure: a change that does not bump post_modified_gmt
 * never reaches the forum mirror. clean_post_cache() after, or the object cache
 * hands the sync the stale row it was told to re-read.
 */
function lg_ccl_touch_modified(int $post_id): void
{
    global $wpdb;
    $now = current_time('mysql');
    $gmt = current_time('mysql', true);
    $wpdb->update($wpdb->posts,
        ['post_modified' => $now, 'post_modified_gmt' => $gmt],
        ['ID' => $post_id]);
    clean_post_cache($post_id);
}

/** Dispatch only if the mirror plugin is actually loaded; never fatal on its absence. */
function bb_mirror_sync_dispatch_safe(string $kind, int $id): void
{
    if (function_exists('bb_mirror_sync_dispatch')) {
        bb_mirror_sync_dispatch($kind, $id, 'upsert');
        return;
    }
    error_log("[lg-ccl] bb_mirror_sync_dispatch missing — $kind #$id not mirrored");
}

/* ═══════════════════════════════════════════════════════════════ REST ═══════
 *
 * Two routes, both behind the flag, both refusing anonymous callers.
 *
 * The term list is fetched ON INTENT — when "＋ Add topics" is first tapped — and
 * NOT inlined into every Hub render. That is the craft standard (editors and
 * composers load on intent, never eagerly for anon) and it is why this is a route
 * at all: the hub app is a different FPM pool with no WordPress loaded, so it
 * cannot read the taxonomy itself.
 */
add_action('rest_api_init', function () {
    if (!lg_ccl_enabled()) return;

    register_rest_route('lg-ccl/v1', '/topics', [
        'methods'             => 'GET',
        'permission_callback' => function () { return is_user_logged_in(); },
        'callback'            => function () {
            $out = [];
            $tops = get_terms([
                'taxonomy'   => 'shared_category',
                'hide_empty' => false,
                'parent'     => 0,
                'orderby'    => 'count',
                'order'      => 'DESC',
            ]);
            if (is_wp_error($tops)) return $tops;
            foreach ($tops as $t) {
                $kids = get_terms([
                    'taxonomy'   => 'shared_category',
                    'hide_empty' => false,
                    'parent'     => $t->term_id,
                    'orderby'    => 'count',
                    'order'      => 'DESC',
                ]);
                $out[] = [
                    'slug'     => $t->slug,
                    'name'     => $t->name,
                    'uses'     => (int)$t->count,
                    'forum'    => lg_ccl_forum_label(lg_ccl_forum_for_terms([$t->slug])),
                    'children' => array_map(function ($k) {
                        return [
                            'slug'  => $k->slug,
                            'name'  => $k->name,
                            'uses'  => (int)$k->count,
                            'forum' => lg_ccl_forum_label(lg_ccl_forum_for_terms([$k->slug])),
                        ];
                    }, is_wp_error($kids) ? [] : $kids),
                ];
            }
            $def = lg_ccl_default_forum_id();
            return [
                'default_forum' => ['id' => $def, 'title' => get_the_title($def) ?: 'the default forum'],
                'topics'        => $out,
            ];
        },
    ]);

    register_rest_route('lg-ccl/v1', '/apply', [
        'methods'             => 'POST',
        'permission_callback' => function () { return is_user_logged_in(); },
        'callback'            => function (WP_REST_Request $req) {
            $topic_id = (int)$req->get_param('topic_id');
            $slugs    = (array)$req->get_param('terms');

            /* The composer's own post, or a moderator's. Same question the topic-edit
               PUT asks — a member must not tag someone else's discussion. */
            if (!current_user_can('edit_post', $topic_id)) {
                return new WP_Error('lg_ccl_forbidden', 'not yours to categorize',
                                    ['status' => 403]);
            }
            $r = lg_ccl_apply($topic_id, $slugs, ['append' => true, 'reason' => 'composer']);
            if (is_wp_error($r)) {
                $r->add_data(['status' => 400]);
                return $r;
            }
            return $r;
        },
    ]);
});

/** "3D Printing (#3863)" for a mapped forum, null when a term maps nowhere. */
function lg_ccl_forum_label(?int $forum_id): ?array
{
    if (!$forum_id) return null;
    return ['id' => $forum_id, 'title' => get_the_title($forum_id)];
}

/* ═══════════════════════════════════════════════════════════ wp-cli ════════
 *
 *   wp lg-recat <topic-id>... --terms=<slug[,slug…]>
 *                             [--forum=<id>] [--no-forum]
 *                             [--dry-run] [--porcelain] [--reason=<text>]
 *   wp lg-recat-list [--all] [--forum=<id>] [--since=<YYYY-MM-DD>]
 *                    [--limit=<n>] [--format=<table|json|csv|ids>]
 *
 * Ian's hand tool AND the LLM's tool, which is why there are two verbs: one
 * READS the uncategorized backlog, the other WRITES. A supervised batch reads with
 * the first and applies suggestions through the second, one topic at a time, with
 * --dry-run available for every one of them.
 *
 * It lives in this file rather than its own so the deploy needs ONE new mu-plugin
 * symlink instead of two — mu-plugins are symlinked individually here, and a
 * missing link is a feature that silently does not exist.
 *
 * SLUGS, NOT TERM IDS, on purpose: a slug is what a model can read straight off
 * `wp lg-recat-list` and what a human can type without a lookup table.
 */

if (defined('WP_CLI') && WP_CLI) {

    /**
     * Assign Content Topics to discussions and re-home them to the mapped forum.
     */
    WP_CLI::add_command('lg-recat', function ($args, $assoc) {

        /* The flag gates the tool as well as the UI, and it must: with the flag OFF
           `shared_category` is not registered for `topic`, so terms written here
           would be rows nothing reads — the worst kind of success. Naming the flag
           in the failure is the difference between a five-second fix and an hour. */
        if (!lg_ccl_enabled()) {
            WP_CLI::error(
                "categorize-last is OFF, so shared_category is not registered for topics and\n"
              . "any terms written now would be unreadable. Turn it on first:\n"
              . "  platform/config/composer-categorize-last.php  → 'enabled' => true\n"
              . "  (or a box-local composer-categorize-last.local.php, or LG_CCL_PREVIEW=1)"
            );
        }

        $ids = array_values(array_filter(array_map('intval', (array)$args)));
        if (!$ids) WP_CLI::error('give me at least one topic id');

        $terms_raw = (string)($assoc['terms'] ?? '');
        $slugs = array_values(array_filter(array_map('trim', explode(',', $terms_raw))));
        if (!$slugs && empty($assoc['no-forum'])) {
            WP_CLI::error('--terms=<slug,slug> is required (or --no-forum to move without tagging)');
        }

        $opts = [
            'append'   => true,
            'dry_run'  => !empty($assoc['dry-run']),
            'reason'   => (string)($assoc['reason'] ?? 'wp lg-recat'),
            'no_forum' => !empty($assoc['no-forum']),
        ];
        if (isset($assoc['forum'])) $opts['forum'] = (int)$assoc['forum'];

        $porcelain = !empty($assoc['porcelain']);
        $ok = 0; $fail = 0;

        foreach ($ids as $id) {
            $r = lg_ccl_apply($id, $slugs, $opts);
            if (is_wp_error($r)) {
                $fail++;
                WP_CLI::warning("#$id: " . $r->get_error_message());
                continue;
            }
            $ok++;
            if ($porcelain) {
                // double quotes: in single quotes \t is a literal backslash-t, which
                // would have made the porcelain output unsplittable by the batch caller.
                WP_CLI::line(sprintf("%d\t%s\t%s\t%s",
                    $r['topic_id'],
                    implode(',', $r['terms']),
                    $r['to_forum'] ? $r['from_forum'] . '->' . $r['to_forum'] : 'forum-unchanged',
                    $r['dry_run'] ? 'dry-run' : 'applied'));
                continue;
            }
            WP_CLI::line(sprintf('%s #%d %s',
                $r['dry_run'] ? '[dry-run]' : '✔', $r['topic_id'], $r['title']));
            WP_CLI::line('    topics: ' . (implode(', ', $r['terms']) ?: '(none)'));
            if ($r['to_forum']) {
                WP_CLI::line(sprintf('    forum:  %d → %d  (%s, plus %d repl%s re-homed)',
                    $r['from_forum'], $r['to_forum'], get_the_title($r['to_forum']),
                    $r['replies'], $r['replies'] === 1 ? 'y' : 'ies'));
            } else {
                WP_CLI::line('    forum:  unchanged (' . get_the_title($r['from_forum']) . ')');
            }
        }

        /* A count ALWAYS, including zero — a run that printed nothing would read as
           "nothing needed doing" when it means "everything was refused". */
        $verb = $opts['dry_run'] ? 'would change' : 'changed';
        WP_CLI::log(sprintf('%d %s, %d refused', $ok, $verb, $fail));

        /* 0 = all applied · 1 = nothing applied · 2 = partial. Never silent. */
        if ($ok && $fail)  WP_CLI::halt(2);
        if (!$ok)          WP_CLI::halt(1);

        if (!$opts['dry_run']) {
            WP_CLI::log('verify in POSTGRES (forums.topic / forums.reply), not in MySQL — a '
                      . 'change that does not reach the mirror looks perfect here');
        }
    });

    /**
     * The read side: which discussions have no Content Topic yet.
     */
    WP_CLI::add_command('lg-recat-list', function ($args, $assoc) {
        $limit  = (int)($assoc['limit'] ?? 50);
        $format = (string)($assoc['format'] ?? 'table');

        $q = [
            'post_type'      => 'topic',
            'post_status'    => 'publish',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if (!empty($assoc['forum'])) $q['post_parent'] = (int)$assoc['forum'];
        if (!empty($assoc['since'])) {
            $q['date_query'] = [['after' => (string)$assoc['since']]];
        }
        /* Uncategorized IS the default — that is what the command is for. --all opts
           out, for a spot check of a forum's whole contents. Spelling the default as
           the ABSENCE of a flag means a batch caller cannot widen its own work set by
           forgetting one. */
        if (empty($assoc['all'])) {
            $q['tax_query'] = [[
                'taxonomy' => 'shared_category',
                'operator' => 'NOT EXISTS',
            ]];
        }

        $rows = [];
        foreach (get_posts($q) as $p) {
            $body = wp_strip_all_tags((string)$p->post_content);
            $rows[] = [
                'id'     => $p->ID,
                'forum'  => get_the_title($p->post_parent),
                'date'   => mysql2date('Y-m-d', $p->post_date),
                'title'  => $p->post_title,
                'excerpt'=> mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 160),
            ];
        }
        if (!$rows) { WP_CLI::log('0 uncategorized discussions matched'); return; }
        if ($format === 'ids') { WP_CLI::line(implode(' ', wp_list_pluck($rows, 'id'))); return; }
        WP_CLI\Utils\format_items($format, $rows, ['id', 'forum', 'date', 'title', 'excerpt']);
    });
}
