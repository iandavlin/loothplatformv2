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
