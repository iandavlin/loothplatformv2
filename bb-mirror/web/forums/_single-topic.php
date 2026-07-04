<?php

/**
 * /forums-poc/<forum-slug>/<topic-slug>/ — single topic + threaded replies.
 *
 * Reads are NOT tier-gated. Visibility = 'public' on forum is the only gate.
 *
 * Reply tree: built in PHP from parent_reply_id, rendered recursively.
 * Depth-capped at 4 desktop / 2 mobile (CSS handles mobile cap).
 * Deeper chains show a decorative "Show N deeper replies" toggle (no JS yet).
 *
 * Reply form: wireframe, action /wp-json/buddyboss/v1/reply, disabled.
 * JS fetch handler is a separate next-session item.
 */

declare(strict_types=1);


// Logged-out contact scrub (Ian 2026-06-10) — see _anon-scrub.php. Included
// by index.php (config already loaded).
require_once __DIR__ . '/../_anon-scrub.php';
$lg_anon_view = function_exists('lg_bb_mirror_can_post') ? !lg_bb_mirror_can_post() : true;
require __DIR__ . '/../_chrome.php';

$db          = bb_mirror_db();
$forum_slug  = $_GET['forum_slug'] ?? '';
$topic_slug  = $_GET['topic_slug']  ?? '';

// ── 1+2. Combined forum+topic lookup (JOIN on both slugs simultaneously) ─────
// Two forums share slug='finish' (ids 3829 + 3847). A forum-first lookup by
// slug alone returns whichever row the DB picks first (non-deterministic). The
// JOIN anchors the topic to the correct forum in one shot.
$topq = $db->prepare("
    SELECT t.id, t.slug, t.title, t.content_html,
           t.author_name, t.author_slug, t.author_id,
           t.is_anon::int                              AS is_anon,
           COALESCE(p.discussion_visibility, 'member') AS discussion_visibility,
           t.created_at, t.status, t.sticky_kind, t.voice_count, t.reply_count,
           f.id   AS forum_id,
           f.slug AS forum_slug,
           f.title AS forum_title,
           f.parent_forum_id,
           f.header_image_url
      FROM forums.topic  t
      JOIN forums.forum  f ON f.id = t.forum_id
      LEFT JOIN forums.person p ON p.id = t.author_id
     WHERE f.slug  = :fs
       AND t.slug  = :ts
       AND t.status IN ('publish', 'closed')
       AND f.visibility = 'public'
     LIMIT 1
");
$topq->execute([':fs' => $forum_slug, ':ts' => $topic_slug]);
$row = $topq->fetch();

if (!$row) {
    bb_mirror_chrome_header('Topic not found');
    http_response_code(404);
    echo '<div class="page"><p class="bb-mirror__empty">Topic not found.</p></div>';
    bb_mirror_chrome_footer();
    return;
}

$forum = ['id' => $row['forum_id'], 'slug' => $row['forum_slug'], 'title' => $row['forum_title'],
          'parent_forum_id' => $row['parent_forum_id'], 'header_image_url' => $row['header_image_url']];
$topic = $row; // all t.* fields are top-level keys

// ── 2b. Author-identity masks (H6) — same leak-safe masks the feed uses, now on
//    the permalink too. is_anon → "Anonymous"; member-only discussion authors →
//    "Private member" for logged-out viewers. Logged-in viewers see real authors
//    (mask_visibility no-ops). Mask BEFORE the OP mod-badge lookup below so a
//    masked author's author_id is nulled and the badge query never reveals them.
$viewer_logged_in = !$lg_anon_view;
$can_mod = function_exists('lg_bb_mirror_can_moderate') ? lg_bb_mirror_can_moderate() : false;
lg_bb_mirror_mask_anon($topic, $can_mod);
lg_bb_mirror_mask_visibility($topic, $viewer_logged_in);

// ── 3. OP person record (for moderator badge) ────────────────────────────────
$op_is_mod = false;
if ($topic['author_id']) {
    $ps = $db->prepare("SELECT is_moderator FROM person WHERE id = ? LIMIT 1");
    $ps->execute([$topic['author_id']]);
    $op_person = $ps->fetch();
    $op_is_mod = (bool)($op_person['is_moderator'] ?? false);
}

// ── 4. All published replies + person data ───────────────────────────────────
$rs = $db->prepare("
    SELECT r.id, r.parent_reply_id, r.content_html, r.author_name,
           r.author_slug, r.author_id, r.created_at,
           r.is_anon::int                              AS is_anon,
           COALESCE(p.discussion_visibility, 'member') AS discussion_visibility,
           p.is_moderator
      FROM reply r
      LEFT JOIN person p ON p.id = r.author_id
     WHERE r.topic_id = ?
       AND r.status   = 'publish'
     ORDER BY r.created_at ASC
");
$rs->execute([(int)$topic['id']]);
$replies_flat = $rs->fetchAll();
// Apply the same author-identity masks to every reply BEFORE the tree is built,
// so masked names propagate to the "↩ in reply to <author>" back-references too.
foreach ($replies_flat as &$lg_r) {
    lg_bb_mirror_mask_anon($lg_r, $can_mod);
    lg_bb_mirror_mask_visibility($lg_r, $viewer_logged_in);
}
unset($lg_r);

// ── 4b. Attachments — one query covers topic + all replies in this thread. ──
$reply_ids = array_map(fn($r) => (int)$r['id'], $replies_flat);
$topic_id  = (int)$topic['id'];
$att_map   = ['topic' => [], 'reply' => []];
$asql = "SELECT parent_kind, parent_id, url, alt, mime, width, height
           FROM attachment
          WHERE (parent_kind = 'topic' AND parent_id = ?)";
$binds = [$topic_id];
if ($reply_ids) {
    $ph = implode(',', array_fill(0, count($reply_ids), '?'));
    $asql .= " OR (parent_kind = 'reply' AND parent_id IN ($ph))";
    $binds = array_merge($binds, $reply_ids);
}
$asql .= " ORDER BY parent_kind, parent_id, position";
$as = $db->prepare($asql);
$as->execute($binds);
foreach ($as->fetchAll() as $a) {
    $att_map[$a['parent_kind']][(int)$a['parent_id']][] = $a;
}

// ── 5. Build reply tree ──────────────────────────────────────────────────────
// Index by id; build children map; find roots (no valid parent in set).
$reply_map    = []; // id => reply row
$children_map = []; // parent_id => [child_id, ...]

foreach ($replies_flat as $r) {
    $reply_map[(int)$r['id']] = $r;
}
foreach ($replies_flat as $r) {
    $pid = $r['parent_reply_id'] ? (int)$r['parent_reply_id'] : null;
    if ($pid && isset($reply_map[$pid])) {
        $children_map[$pid][] = (int)$r['id'];
    }
}
$roots = [];
foreach ($replies_flat as $r) {
    $pid = $r['parent_reply_id'] ? (int)$r['parent_reply_id'] : null;
    if (!$pid || !isset($reply_map[$pid])) {
        $roots[] = (int)$r['id'];
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function fmt_ts_single($ts): string {
    if (!$ts) return '—';
    $unix = is_numeric($ts) ? (int)$ts : strtotime((string)$ts . ' UTC');
    return $unix ? date('Y-m-d H:i', $unix) : '—';
}

function fmt_ts_dt($ts): string {
    if (!$ts) return '';
    $unix = is_numeric($ts) ? (int)$ts : strtotime((string)$ts . ' UTC');
    return $unix ? date('c', $unix) : '';
}

function avatar_letter(string $name): string {
    return strtoupper(mb_substr(trim($name) ?: '?', 0, 1));
}

// FB-style "⋯" overflow menu (Edit + Delete) for one post — the OP or any reply.
// Rendered HIDDEN; forums.js reveals the trigger only for the post's author OR a
// moderator (viewer identity comes from /bb-mirror-api/v0/auth.php). The owned
// write endpoint (/bb-mirror-api/v0/reply, PUT/DELETE) re-checks author-or-mod on
// every request, so this UI gate is convenience, not security. Edit/Delete carry
// the SAME data-attributes the existing startEdit/confirmDelete handlers read.
function lg_post_menu_icon(string $which): string {
    if ($which === 'edit') {
        return '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    }
    return '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/></svg>';
}
function lg_post_menu(string $kind, int $id, int $author_id, array $opts = []): void {
    $topic_id = (int)($opts['topic_id'] ?? 0);
    $forum_id = (int)($opts['forum_id'] ?? 0);
    $title    = (string)($opts['title'] ?? '');
    echo '<div class="post__menu-wrap" data-author-id="' . $author_id . '" hidden>';
    echo   '<button type="button" class="post__menu-btn" aria-haspopup="true" aria-expanded="false" aria-label="Post options">';
    echo     '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>';
    echo   '</button>';
    echo   '<div class="post__menu" role="menu" hidden>';
    echo     '<button type="button" role="menuitem" class="post__menu-item post__edit-btn"'
           . ' data-edit-kind="' . htmlspecialchars($kind, ENT_QUOTES) . '"'
           . ' data-edit-id="' . $id . '"'
           . ' data-author-id="' . $author_id . '"'
           . ($topic_id ? ' data-topic-id="' . $topic_id . '"' : '')
           . ($forum_id ? ' data-forum-id="' . $forum_id . '"' : '')
           . ($kind === 'topic' ? ' data-title="' . htmlspecialchars($title, ENT_QUOTES) . '"' : '')
           . '>' . lg_post_menu_icon('edit') . '<span>Edit</span></button>';
    echo     '<button type="button" role="menuitem" class="post__menu-item post__menu-item--danger post__delete-btn"'
           . ' data-del-kind="' . htmlspecialchars($kind, ENT_QUOTES) . '"'
           . ' data-del-id="' . $id . '"'
           . ' data-author-id="' . $author_id . '"'
           . '>' . lg_post_menu_icon('delete') . '<span>Delete</span></button>';
    echo   '</div>';
    echo '</div>';
}

// Render the attachment gallery for one parent post (topic or reply).
// Images become a flex row of thumbnails linking to the original URL;
// non-image mimes get a download chip.
if (!function_exists('lg_attach_src')) {
    /** Route an uploads image through /img.php at a display width; pass external URLs through. */
    function lg_attach_src(?string $url, int $w): ?string {
        if ($url && preg_match('#/wp-content/uploads/(.+)$#', $url, $m)) {
            return '/img.php?s=' . rawurlencode($m[1]) . '&w=' . $w;
        }
        return $url;
    }
}
function render_attachments(array $atts): void {
    if (!$atts) return;
    ?>
    <div class="post__attachments">
      <?php foreach ($atts as $a):
        $is_image = $a['mime'] && str_starts_with((string)$a['mime'], 'image/');
        $alt = htmlspecialchars((string)($a['alt'] ?? ''));
        $raw = (string)$a['url'];
        $url = htmlspecialchars($raw);                                  // full image = lightbox href
      ?>
        <?php if ($is_image):
          $t480 = htmlspecialchars(lg_attach_src($raw, 480) ?? $raw);   // display-sized thumb
          $t240 = htmlspecialchars(lg_attach_src($raw, 240) ?? $raw);
          $t800 = htmlspecialchars(lg_attach_src($raw, 800) ?? $raw);
        ?>
          <a class="attachment attachment--image" href="<?= $url ?>" target="_blank" rel="noopener">
            <img src="<?= $t480 ?>"
                 srcset="<?= $t240 ?> 240w, <?= $t480 ?> 480w, <?= $t800 ?> 800w"
                 sizes="(max-width:640px) 86vw, 240px"
                 alt="<?= $alt ?>" loading="lazy" decoding="async"
                 <?php if ($a['width']):  ?>width="<?= (int)$a['width']  ?>"<?php endif; ?>
                 <?php if ($a['height']): ?>height="<?= (int)$a['height'] ?>"<?php endif; ?>>
          </a>
        <?php else: ?>
          <a class="attachment attachment--file" href="<?= $url ?>" target="_blank" rel="noopener">
            📎 <?= htmlspecialchars($a['alt'] ?: basename((string)$a['url'])) ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php
}

// Count total descendants of a node.
function count_descendants(int $id, array $children_map): int {
    $kids = $children_map[$id] ?? [];
    $n    = count($kids);
    foreach ($kids as $cid) $n += count_descendants($cid, $children_map);
    return $n;
}

// Collect all descendants of a node in DFS pre-order (child then its subtree)
// — used to flatten a thread to two visual tiers (top-level + one indent).
function collect_descendants(int $id, array $children_map): array {
    $out = [];
    foreach ($children_map[$id] ?? [] as $cid) {
        $out[] = $cid;
        foreach (collect_descendants($cid, $children_map) as $d) $out[] = $d;
    }
    return $out;
}

const MAX_DEPTH = 4;

function render_reply(
    int $id, array $reply_map, array $children_map,
    int $op_id, int $depth = 0
): void {
    $r        = $reply_map[$id];
    $children = $children_map[$id] ?? [];
    $is_op    = ((int)$r['author_id'] === $op_id && $op_id > 0);
    // Don't reveal that a masked (anon / member-only) author is a moderator.
    $is_masked = ($r['_anon_masked'] ?? false) || ($r['_visibility_masked'] ?? false);
    $is_mod   = !$is_masked && (bool)($r['is_moderator'] ?? false);
    $letter   = avatar_letter($r['author_name'] ?: '?');
    $created  = fmt_ts_single($r['created_at']);
    $dt_attr  = fmt_ts_dt($r['created_at']);
    ?>
    <div class="post" id="reply-<?= (int)$r['id'] ?>">
      <div class="post__avatar-wrap">
        <div class="post__avatar"><?= htmlspecialchars($letter) ?></div>
      </div>
      <div class="post__content">
        <div class="post__head">
          <?php $pslug = $r['author_slug'] ?? null; ?>
          <?php if ($pslug): ?><a class="post__author" href="/u/<?= rawurlencode((string)$pslug) ?>"><?= htmlspecialchars($r['author_name'] ?: 'Anonymous') ?></a><?php else: ?><span class="post__author"><?= htmlspecialchars($r['author_name'] ?: 'Anonymous') ?></span><?php endif; ?>
          <?php if ($is_op): ?>
            <span class="badge badge--op">OP</span>
          <?php endif; ?>
          <?php if ($is_mod): ?>
            <span class="badge badge--mod">MOD</span>
          <?php endif; ?>
          <?php if ($r['parent_reply_id'] && isset($reply_map[(int)$r['parent_reply_id']])): ?>
            <span class="post__reply-to">↩ in reply to
              <a href="#reply-<?= (int)$r['parent_reply_id'] ?>">
                <?= htmlspecialchars($reply_map[(int)$r['parent_reply_id']]['author_name'] ?: 'a reply') ?>
              </a>
            </span>
          <?php endif; ?>
          <time class="post__time" datetime="<?= $dt_attr ?>"><?= $created ?></time>
          <?php lg_post_menu('reply', (int)$r['id'], (int)($r['author_id'] ?? 0), [
              'topic_id' => (int)($GLOBALS['topic_id'] ?? 0),
              'forum_id' => (int)($GLOBALS['forum']['id'] ?? 0),
          ]); ?>
        </div>
        <div class="post__body"><?= ($GLOBALS['lg_anon_view'] ?? true) ? lg_scrub_anon_contacts((string)$r['content_html']) : $r['content_html'] /* sanitized at sync write */ ?></div>
        <?php render_attachments($GLOBALS['att_map']['reply'][(int)$r['id']] ?? []); ?>
        <div class="post__actions">
          <button type="button" class="post__reply-btn" data-reply-to="<?= (int)$r['id'] ?>"
                  data-reply-to-author="<?= htmlspecialchars($r['author_name'] ?: 'Anonymous') ?>"
                  hidden>Reply</button>
        </div>
        <?php /* Per-reply engagement bar — same .fc-actions .fcr surface as the OP
                 bar / feed card; forums.js §4d wires .fcr generically so clicks
                 round-trip once the bar is in the DOM. Reply reactions 2026-06-26. */ ?>
        <div class="fc-actions">
          <?php if (function_exists('feed_reactions_bar')) feed_reactions_bar('reply', (int)$r['id'], $GLOBALS['rx_replies']['reply:' . (int)$r['id']] ?? []); ?>
        </div>
      </div>
    </div>
    <?php
    // Flatten to two visual tiers: a top-level reply renders ALL its descendants
    // (DFS) at a single child indent. Each nested reply keeps its "↩ in reply to
    // <author>" reference (above) so the thread stays legible. Replies at depth >= 1
    // don't recurse here — the depth-0 caller already emitted the whole subtree.
    if ($depth >= 1) return;
    $desc = collect_descendants($id, $children_map);
    if (!$desc) return;
    ?>
    <ul class="replies-tree">
      <?php foreach ($desc as $cid): ?>
        <li>
          <?php render_reply($cid, $reply_map, $children_map, $op_id, 1); ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php
}

// ── Render ───────────────────────────────────────────────────────────────────
bb_mirror_chrome_header($topic['title']);

$op_letter  = avatar_letter($topic['author_name'] ?: '?');
$op_created = fmt_ts_single($topic['created_at']);
$op_dt      = fmt_ts_dt($topic['created_at']);
$reply_count = (int)$topic['reply_count'];
$public_path = LG_BB_MIRROR_PUBLIC_PATH;

// ── OP reaction bar (deep-link full-parity, hub-topic-deeplink 2026-06-25) ──
// Render the SAME .fc-actions .fcr engagement bar the feed card emits, via the
// shared count contract (lg_card_reactions_for_items) + shared renderer
// (feed_reactions_bar). Gives the standalone page working reactions AND lets the
// §4e modal's cold-fetch deep-link clone the bar for full parity with a normal
// card-clone open. Try/catch so a missing grant degrades to "no bar".
$op_rx_counts = [];
try {
    require_once __DIR__ . '/../../../archive-poc/api/v0/_reactions.php'; // count contract + palette
    require_once __DIR__ . '/_reply-render.php';                          // feed_reactions_bar + glyphs
    $op_rx_map    = lg_card_reactions_for_items($db, [['post_type' => 'topic', 'item_id' => (int)$topic['id']]]);
    $op_rx_counts = $op_rx_map['topic:' . (int)$topic['id']] ?? [];
} catch (\Throwable $e) {
    $op_rx_counts = []; // store/grant unreadable → omit reactions, keep the page
}

// ── Per-reply reaction counts — one batched read for the whole thread, same
// shared count contract as the OP bar. render_reply() reads $GLOBALS['rx_replies']
// (like att_map). Try/catch so a missing grant degrades to bare replies. ──
$rx_replies = [];
try {
    if ($reply_ids) {
        $rx_replies = lg_card_reactions_for_items($db, array_map(
            fn($id) => ['post_type' => 'reply', 'item_id' => $id], $reply_ids
        ));
    }
} catch (\Throwable $e) {
    $rx_replies = []; // store/grant unreadable → omit reply reactions, keep the page
}

// Forum-header context for the post page (category accent + parent breadcrumb).
$fcat_rows  = $db->query("SELECT id, slug, parent_forum_id FROM forum WHERE visibility='public' AND status IN ('open','closed')")->fetchAll();
$forum_cat  = bb_mirror_build_cat_map($fcat_rows)[(int)$forum['id']] ?? 'general';
$fh_parent  = null;
if (!empty($forum['parent_forum_id'])) {
    $pfq = $db->prepare("SELECT slug, title FROM forum WHERE id = ? AND visibility='public' LIMIT 1");
    $pfq->execute([(int)$forum['parent_forum_id']]);
    $fh_parent = $pfq->fetch() ?: null;
}
$forum_url     = $public_path . '/' . $forum['slug'] . '/';
$fh_parent_url = $fh_parent ? $public_path . '/' . $fh_parent['slug'] . '/' : '';
$fh_image      = $forum['header_image_url'] ?: null;
?>

<div class="page">

  <header class="forum-header forum-header--post<?= $fh_image ? ' forum-header--has-image' : '' ?>" data-cat="<?= htmlspecialchars($forum_cat) ?>">
    <?php if ($fh_image): ?>
      <div class="forum-header__bg" style="background-image: url('<?= htmlspecialchars($fh_image, ENT_QUOTES, 'UTF-8') ?>')"></div>
    <?php endif; ?>
    <div class="forum-header__body">
      <a class="forum-header__home" href="<?= htmlspecialchars($public_path . '/') ?>">The Hub</a>
      <?php if ($fh_parent): ?>
        <a class="forum-header__parent" href="<?= htmlspecialchars($fh_parent_url) ?>">&lsaquo; <?= htmlspecialchars($fh_parent['title']) ?></a>
      <?php endif; ?>
      <div class="forum-header__title-row">
        <a class="forum-header__title forum-header__title--link" href="<?= htmlspecialchars($forum_url) ?>"><?= htmlspecialchars($forum['title']) ?></a>
      </div>
      <span class="forum-header__label">The Hub</span>
    </div>
  </header>

  <div class="topic-header">
    <h1 class="topic-header__title"><?= htmlspecialchars($topic['title']) ?></h1>
    <div class="topic-header__meta">
      <?php if ($topic['sticky_kind'] === 'super'): ?><span>📍 super sticky</span><?php endif; ?>
      <?php if ($topic['sticky_kind'] === 'forum'): ?><span>📌 pinned</span><?php endif; ?>
      <?php if ($topic['status'] === 'closed'): ?><span>🔒 closed</span><?php endif; ?>
      <span><?= $reply_count ?> repl<?= $reply_count === 1 ? 'y' : 'ies' ?></span>
      <span>·</span>
      <span><?= (int)$topic['voice_count'] ?> voice<?= (int)$topic['voice_count'] === 1 ? '' : 's' ?></span>
    </div>
  </div>

  <?php $lg_topic_share_url = $public_path . '/' . $forum['slug'] . '/' . $topic['slug'] . '/'; ?>
  <div class="thread__util">
    <span><?= $reply_count ?> repl<?= $reply_count === 1 ? 'y' : 'ies' ?></span>
    <div class="thread__util-acts">
      <?php if (function_exists('feed_save_btn')) feed_save_btn('topic', (int)$topic['id']); ?>
      <button type="button" class="lg-share-btn" data-share-topic
              data-share-url="<?= htmlspecialchars($lg_topic_share_url, ENT_QUOTES) ?>"
              data-share-title="<?= htmlspecialchars((string)$topic['title'], ENT_QUOTES) ?>"
              aria-label="Share" title="Share">
        <svg class="ico" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>
        <span class="lg-share-btn__lbl">Share</span>
      </button>
    </div>
  </div>

  <!-- OP post -->
  <div class="thread">
    <div class="post post--op" id="topic-<?= (int)$topic['id'] ?>">
      <div class="post__avatar-wrap">
        <div class="post__avatar"><?= htmlspecialchars($op_letter) ?></div>
      </div>
      <div class="post__content">
        <div class="post__head">
          <?php $pslug = $topic['author_slug'] ?? null; ?>
          <?php if ($pslug): ?><a class="post__author" href="/u/<?= rawurlencode((string)$pslug) ?>"><?= htmlspecialchars($topic['author_name'] ?: 'Anonymous') ?></a><?php else: ?><span class="post__author"><?= htmlspecialchars($topic['author_name'] ?: 'Anonymous') ?></span><?php endif; ?>
          <span class="badge badge--op">OP</span>
          <?php if ($op_is_mod): ?>
            <span class="badge badge--mod">MOD</span>
          <?php endif; ?>
          <time class="post__time" datetime="<?= $op_dt ?>"><?= $op_created ?></time>
          <?php lg_post_menu('topic', (int)$topic['id'], (int)($topic['author_id'] ?? 0), [
              'forum_id' => (int)$forum['id'],
              'title'    => (string)$topic['title'],
          ]); ?>
        </div>
        <div class="post__body"><?= $lg_anon_view ? lg_scrub_anon_contacts((string)$topic['content_html']) : $topic['content_html'] /* sanitized at sync write */ ?></div>
        <?php render_attachments($att_map['topic'][$topic_id] ?? []); ?>
        <?php /* Engagement bar — same .fc-actions .fcr surface the feed card emits
                 (_feed.php), so the §4e modal cold-fetch can clone it for full OP
                 reaction parity, and the standalone page itself becomes reactable
                 (forums.js §4d wires .fcr generically). Ian 2026-06-25. */ ?>
        <div class="fc-actions">
          <?php if (function_exists('feed_reactions_bar')) feed_reactions_bar('topic', (int)$topic['id'], $op_rx_counts); ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Threaded replies -->
  <?php if ($replies_flat): ?>
    <h2 class="replies-heading"><?= $reply_count ?> <?= $reply_count === 1 ? 'reply' : 'replies' ?></h2>
    <ul class="replies-tree">
      <?php foreach ($roots as $rid): ?>
        <li>
          <?php render_reply($rid, $reply_map, $children_map, (int)($topic['author_id'] ?? 0)); ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <!--
    Reply form — two states managed by web/forums.js after fetching
    /bb-mirror-api/v0/auth.php for cookie-authed nonce.
      .reply-form--anon      → Sign in CTA
      .reply-form--authed    → enabled textarea + submit
    Server renders both shapes hidden; JS reveals the appropriate one.
    Group-membership gate is a noop today; hook present for /whoami wiring.
  -->
  <section class="reply-form-wrap"
           data-topic-id="<?= (int)$topic['id'] ?>"
           data-forum-id="<?= (int)$forum['id'] ?>"
           data-bb-rest-base="/wp-json/buddyboss/v1">
    <div class="reply-form reply-form--loading" data-state="loading">
      <p class="reply-form__note">Checking sign-in…</p>
    </div>

    <div class="reply-form reply-form--anon" data-state="anon" hidden>
      <p class="reply-form__cta">
        <a class="reply-form__signin"
           href="/wp-login.php?redirect_to=<?= rawurlencode(LG_BB_MIRROR_PUBLIC_PATH . '/' . $forum['slug'] . '/' . $topic['slug'] . '/') ?>">
          Sign in to post a reply
        </a>
      </p>
    </div>

    <form class="reply-form reply-form--authed" data-state="authed" hidden autocomplete="off"
          method="post" action="/wp-json/buddyboss/v1/reply">
      <input type="hidden" name="topic_id" value="<?= (int)$topic['id'] ?>">
      <input type="hidden" name="forum_id" value="<?= (int)$forum['id'] ?>">
      <input type="hidden" name="parent_reply_id" value="">
      <div class="reply-form__replying-to" hidden>
        ↩ replying to <strong class="reply-form__replying-to-name"></strong>
        <button type="button" class="reply-form__cancel-thread">cancel</button>
      </div>
      <label class="reply-form__label">Add a reply</label>
      <!-- Quill mounts here (same editor as new-topic/feed reply); textarea is the fallback -->
      <div class="reply-form__editor" id="reply-editor"></div>
      <textarea id="reply-content" name="content" rows="6" autocomplete="off"
                placeholder="Share your build, ask a question, drop a measurement…" hidden></textarea>
      <div class="reply-form__row">
        <button type="submit" class="reply-form__submit">Post reply</button>
        <span class="reply-form__status" role="status" aria-live="polite"></span>
      </div>
    </form>
  </section>


</div>

<?php bb_mirror_chrome_footer(); ?>
