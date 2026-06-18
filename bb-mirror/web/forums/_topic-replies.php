<?php
/**
 * Threaded replies fragment endpoint.
 *
 * Route: /forums-poc/?replies=<topic_id>
 * Returns the full threaded reply list for one topic as a bare HTML fragment
 * (top-level threads newest-first, each with indented direct children).
 * Called by forums.js on the first "View N replies" click — keeps the initial
 * feed light (one teaser reply per card; the rest load on demand).
 */

declare(strict_types=1);

require __DIR__ . '/../../config.php';
require __DIR__ . '/_reply-render.php';

$tid  = (int)($_GET['replies'] ?? 0);
$sort = (($_GET['sort'] ?? '') === 'oldest') ? 'oldest' : 'newest';   // default newest
if (!$tid) { http_response_code(400); echo 'bad request'; exit; }

$db = bb_mirror_db();

// Forum-visibility gate (C2): replies are only readable when the parent topic
// lives in a PUBLIC forum. Hidden/private forums 404 — mirrors _topic-body.php
// and the single-topic / feed read paths (which all gate on f.visibility).
$vis = $db->prepare("
    SELECT 1 FROM forums.topic t
      JOIN forums.forum f ON f.id = t.forum_id
     WHERE t.id = :tid AND t.status IN ('publish', 'closed') AND f.visibility = 'public'
     LIMIT 1
");
$vis->bindValue(':tid', $tid, PDO::PARAM_INT);
$vis->execute();
if (!$vis->fetch()) { http_response_code(404); echo 'not found'; exit; }

$rs = $db->prepare("
    SELECT r.id AS reply_id, r.parent_reply_id,
           COALESCE(r.author_name, 'Anonymous') AS author_name,
           r.author_id,
           p.slug AS author_slug,
           p.avatar_url AS avatar_url,
           COALESCE(p.discussion_visibility, 'member') AS discussion_visibility,
           r.is_anon::int AS is_anon,
           LEFT(r.content_text, 200) AS excerpt,
           r.content_html,
           r.created_at,
           reply_img.url AS reply_image_url
      FROM forums.reply r
      LEFT JOIN forums.person p ON p.id = r.author_id
      LEFT JOIN LATERAL (
        SELECT url FROM forums.attachment
         WHERE parent_kind = 'reply' AND parent_id = r.id
         ORDER BY id ASC LIMIT 1
      ) reply_img ON true
     WHERE r.topic_id = :tid AND r.status = 'publish'
     ORDER BY r.created_at ASC
");
$rs->bindValue(':tid', $tid, PDO::PARAM_INT);
$rs->execute();
$flat = $rs->fetchAll();

if (!$flat) { header('Content-Type: text/html; charset=utf-8'); echo ''; exit; }

// Build the tree: index by id, attach child ids, collect top-level roots.
// Anonymous-posting mask (anon-rebuild lane): scrub anon authors leak-safe for
// non-moderators BEFORE the tree is built, so even the "↪ @parent" deep-reply
// prefix (sourced from a parent node's author_name) reads "Anonymous".
$can_mod = lg_bb_mirror_can_moderate();
$viewer_logged_in = lg_bb_mirror_can_post();   // logged-out is the ONLY path that masks
$by_id = [];
foreach ($flat as $r) {
    lg_bb_mirror_mask_anon($r, $can_mod);
    // Member-only author mask, BEFORE the tree/flatten so the "↪ @parent" deep-reply
    // prefix (built from a parent's author_name) reads "Private member", not the real name.
    lg_bb_mirror_mask_visibility($r, $viewer_logged_in);
    $by_id[(int)$r['reply_id']] = $r + ['_children' => []];
}
$top = [];
foreach ($by_id as $rid => $node) {
    $pid = $node['parent_reply_id'] !== null ? (int)$node['parent_reply_id'] : null;
    if ($pid !== null && isset($by_id[$pid])) {
        $by_id[$pid]['_children'][] = $rid;
    } else {
        $top[] = $rid;
    }
}
// Order top-level threads by the chosen sort (children stay chronological within
// each thread). Newest-first is the default + matches the feed teaser.
usort($top, function ($a, $b) use ($by_id, $sort) {
    $d = strtotime((string)$by_id[$a]['created_at']) - strtotime((string)$by_id[$b]['created_at']);
    return $sort === 'oldest' ? $d : -$d;
});

// Flatten the whole thread into ONE ordered list of reply ROWS (DFS: each
// top-level thread immediately followed by its descendants), then paginate by 5
// — "only 5 replies open at a time", with a "Load N more" button revealing the
// next 5. Two visual tiers: top-level + one child indent; depth ≥ 2 shows a
// "↪ @parent" prefix so context survives the flatten + page boundaries.
$ordered = [];
$flatten = function (int $rid, int $depth, ?string $parent_author) use (&$flatten, &$by_id, &$ordered) {
    $ordered[] = ['rid' => $rid, 'depth' => $depth, 'pa' => $parent_author];
    foreach ($by_id[$rid]['_children'] as $cid) {
        $flatten($cid, $depth + 1, (string)($by_id[$rid]['author_name'] ?? 'Anonymous'));
    }
};
foreach ($top as $rid) {
    $flatten($rid, 0, null);
}

$PER    = 5;
$offset = max(0, (int)($_GET['offset'] ?? 0));
$total  = count($ordered);
$page   = array_slice($ordered, $offset, $PER);

// Reply reaction counts for the visible page (ec9a30e: 'reply' is a reactable
// target). Same count contract as the feed; stashed for bb_mirror_render_reply_stub.
$GLOBALS['__bb_reply_rx'] = [];
$rx_reply_items = [];
foreach ($page as $row) $rx_reply_items[] = ['post_type' => 'reply', 'item_id' => (int)$row['rid']];
if ($rx_reply_items) {
    try {
        require_once __DIR__ . '/../../../archive-poc/api/v0/_reactions.php';
        $GLOBALS['__bb_reply_rx'] = lg_card_reactions_for_items($db, $rx_reply_items);
    } catch (\Throwable $e) {
        $GLOBALS['__bb_reply_rx'] = [];
    }
}

header('Content-Type: text/html; charset=utf-8');

// Newest/Oldest toggle — first page only, and only when there's more than one reply.
if ($offset === 0 && $total > 1) {
    echo '<div class="replies-sort" data-topic-id="' . $tid . '">';
    foreach (['newest' => 'Newest', 'oldest' => 'Oldest'] as $key => $label) {
        echo '<button type="button" class="replies-sort__btn' . ($sort === $key ? ' is-active' : '')
           . '" data-sort="' . $key . '">' . $label . '</button>';
    }
    echo '</div>';
}

foreach ($page as $row) {
    $node = $by_id[$row['rid']];
    // Full reply text in the expanded thread (this fragment IS the full-thread
    // view, so don't truncate — the card teaser elsewhere stays short). @mentions +
    // clickable URLs still resolved; high cap = effectively no truncation.
    $node['excerpt_html'] = bb_mirror_format_snippet((string)($node['content_html'] ?? ''), 100000, $db, true);
    bb_mirror_render_reply_stub(
        $node,
        $row['depth'] >= 1,                              // is_child (one indent tier)
        false,                                           // load image inline (Ian 2026-06-11: images just load, no click)
        true,                                            // per-reply Reply button
        $row['depth'] >= 2 ? $row['pa'] : null           // "↪ @author" for deep replies
    );
}

// Load-more — reveals the next 5 reply rows.
if ($offset + $PER < $total) {
    $next = $offset + $PER;
    $more = min($PER, $total - $next);
    echo '<button class="replies-loadmore" type="button"'
       . ' data-topic-id="' . $tid . '"'
       . ' data-sort="' . htmlspecialchars($sort) . '"'
       . ' data-offset="' . $next . '">Load ' . $more . ' more ' . ($more === 1 ? 'reply' : 'replies') . ' &#9662;</button>';
}
