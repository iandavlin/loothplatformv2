<?php
/**
 * /forums-poc/?q=<query> — full-text search over topics + replies.
 *
 * tsvector indexes are maintained by triggers on topic + reply (see
 * schema.pg.sql). Query uses websearch_to_tsquery for natural-language
 * input: quoted phrases, `-exclude`, `OR`, etc. all work out of the box.
 *
 * Results: union of topic-title matches + reply-body matches. Each result
 * links to the topic page and previews the snippet with ts_headline().
 * Topics outrank replies (weight A vs B in the tsvector).
 */

declare(strict_types=1);

require __DIR__ . '/../_chrome.php';
require __DIR__ . '/_reply-render.php';

$db = bb_mirror_db();
$cat_map = bb_mirror_build_cat_map($db->query("SELECT id, slug, parent_forum_id FROM forum WHERE visibility='public' AND status IN ('open','closed')")->fetchAll());
$q = trim((string)($_GET['q'] ?? ''));

bb_mirror_chrome_header('Search: ' . ($q ?: 'forums'));

if ($q === '' || mb_strlen($q) < 2) {
    ?>
    <div class="page">
      <h1 class="bb-mirror__page-title">Search</h1>
      <p class="bb-mirror__empty">Enter at least 2 characters to search.</p>
    </div>
    <?php
    bb_mirror_chrome_footer();
    return;
}

// websearch_to_tsquery is forgiving — handles bad input without erroring.
// 50-row cap; pagination later if needed.
$stmt = $db->prepare("
    WITH q AS (SELECT websearch_to_tsquery('english', ?) AS tsq),
    topic_hits AS (
      SELECT 'topic' AS kind, t.id, t.id AS topic_id, t.slug AS topic_slug,
             t.title, t.author_name, p.slug AS author_slug, t.created_at,
             ts_rank(t.search_doc, q.tsq) * 2.0 AS rank,
             ts_headline('english', COALESCE(t.content_text,''), q.tsq,
                         'MaxWords=24, MinWords=10, ShortWord=2') AS snippet,
             f.slug AS forum_slug, f.title AS forum_title, f.id AS forum_id, pf.title AS parent_title
        FROM topic t
        CROSS JOIN q
        JOIN forum f ON f.id = t.forum_id
        LEFT JOIN person p ON p.id = t.author_id
        LEFT JOIN forum pf ON pf.id = f.parent_forum_id
       WHERE t.status IN ('publish','closed')
         AND f.visibility = 'public'
         AND t.search_doc @@ q.tsq
    ),
    reply_hits AS (
      SELECT 'reply' AS kind, r.id, r.topic_id, t.slug AS topic_slug,
             t.title, r.author_name, p.slug AS author_slug, r.created_at,
             ts_rank(r.search_doc, q.tsq) AS rank,
             ts_headline('english', COALESCE(r.content_text,''), q.tsq,
                         'MaxWords=24, MinWords=10, ShortWord=2') AS snippet,
             f.slug AS forum_slug, f.title AS forum_title, f.id AS forum_id, pf.title AS parent_title
        FROM reply r
        CROSS JOIN q
        JOIN topic t ON t.id = r.topic_id
        JOIN forum f ON f.id = r.forum_id
        LEFT JOIN person p ON p.id = r.author_id
        LEFT JOIN forum pf ON pf.id = f.parent_forum_id
       WHERE r.status = 'publish'
         AND t.status IN ('publish','closed')
         AND f.visibility = 'public'
         AND r.search_doc @@ q.tsq
    )
    SELECT * FROM topic_hits
    UNION ALL
    SELECT * FROM reply_hits
    ORDER BY rank DESC
    LIMIT 50
");
$stmt->execute([$q]);
$rows = $stmt->fetchAll();

function fmt_ts_search($ts): string {
    if (!$ts) return '';
    $unix = is_numeric($ts) ? (int)$ts : strtotime((string)$ts . ' UTC');
    return $unix ? date('Y-m-d', $unix) : '';
}
?>

<div class="page">
  <h1 class="bb-mirror__page-title">Search</h1>
  <p class="search-meta">
    <?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?> for
    <strong><?= htmlspecialchars($q) ?></strong>
  </p>

  <?php if (!$rows): ?>
    <p class="bb-mirror__empty">No matches. Try fewer words or a different phrasing.</p>
  <?php else: ?>
    <div class="feed">
      <?php foreach ($rows as $r):
        $href = LG_BB_MIRROR_PUBLIC_PATH . '/' . $r['forum_slug'] . '/' . $r['topic_slug'] . '/';
        if ($r['kind'] === 'reply') $href .= '#reply-' . (int)$r['id'];
        $scat    = $cat_map[(int)$r['forum_id']] ?? 'general';
        $sparent = trim((string)($r['parent_title'] ?? ''));
      ?>
        <article class="feed-card feed-card--topic feed-card--search" data-cat="<?= htmlspecialchars($scat) ?>" data-href="<?= htmlspecialchars($href) ?>">
          <div class="feed-card__meta-top">
            <span class="feed-card__forum-ctx">
              <?php if ($sparent !== ''): ?>
                <span class="feed-card__ctx-parent"><?= htmlspecialchars($sparent) ?></span>
                <span class="feed-card__ctx-sep">&rsaquo;</span>
                <span class="feed-card__ctx-forum"><?= htmlspecialchars($r['forum_title']) ?></span>
              <?php else: ?>
                <span class="feed-card__ctx-parent"><?= htmlspecialchars($r['forum_title']) ?></span>
              <?php endif; ?>
            </span>
            <time class="feed-card__time"><?= htmlspecialchars(fmt_ts_search($r['created_at'])) ?></time>
          </div>
          <div class="feed-card__header">
            <div class="feed-card__header-body">
              <h2 class="feed-card__title">
                <a href="<?= htmlspecialchars($href) ?>"><?= htmlspecialchars($r['title']) ?></a>
                <?php if ($r['kind'] === 'reply'): ?><span class="feed-card__kind-badge">reply</span><?php endif; ?>
              </h2>
              <div class="feed-card__op">
                <p class="feed-card__op-excerpt"><?= $r['snippet'] /* ts_headline marked HTML */ ?></p>
                <div class="feed-card__op-meta" style="display:flex;align-items:center;gap:6px;">
                  <?= bb_mirror_avatar($r['author_name'] ?: 'A', $r['author_name'] ?: 'anon', 36) ?>
                  <?php $sslug = $r['author_slug'] ?? null; ?><span>by <?php if ($sslug): ?><a class="feed-card__op-author" href="/u/<?= rawurlencode((string)$sslug) ?>"><?= htmlspecialchars($r['author_name'] ?: 'Anonymous') ?></a><?php else: ?><span class="feed-card__op-author"><?= htmlspecialchars($r['author_name'] ?: 'Anonymous') ?></span><?php endif; ?></span>
                </div>
              </div>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php bb_mirror_chrome_footer(); ?>
