<?php
/**
 * /forums-poc/<forum-slug>/ — topic list for a single forum.
 *
 * Routing: front controller stashes the slug in $_GET['forum_slug'].
 * Order: super-sticky → forum-sticky → normal, each chunk by last_active_at DESC.
 * Reads are NOT tier-gated — visibility = 'public' is the only gate.
 */

require __DIR__ . '/../_chrome.php';

$db       = bb_mirror_db();
$slug     = $_GET['forum_slug'] ?? '';
$per_page = LG_BB_MIRROR_PER_PAGE;
$page     = bb_mirror_page();
$offset   = ($page - 1) * $per_page;

// Forum lookup (visibility-gated, no tier filter).
$fs = $db->prepare("
    SELECT id, slug, title, description, topic_count, total_reply_count
      FROM forum
     WHERE slug = ?
       AND visibility = 'public'
       AND status IN ('open', 'closed')
     LIMIT 1
");
$fs->execute([$slug]);
$forum = $fs->fetch();

if (!$forum) {
    bb_mirror_chrome_header('Forum not found');
    http_response_code(404);
    echo '<div class="page"><p class="bb-mirror__empty">Forum not found.</p></div>';
    bb_mirror_chrome_footer();
    return;
}

// Topic list — no tier filter.
$ts = $db->prepare("
    SELECT id, slug, title, sticky_kind, voice_count, reply_count,
           last_active_at, author_name, author_slug, status
      FROM topic
     WHERE forum_id = ?
       AND status IN ('publish', 'closed')
     ORDER BY CASE sticky_kind
                WHEN 'super' THEN 0
                WHEN 'forum' THEN 1
                ELSE 2
              END,
              last_active_at DESC,
              created_at DESC
     LIMIT $per_page OFFSET $offset
");
$ts->execute([(int)$forum['id']]);
$topics = $ts->fetchAll();

// Find which of these topics have threaded replies (one batch query).
$threaded_ids = [];
if ($topics) {
    $ids    = array_column($topics, 'id');
    $ph     = implode(',', array_fill(0, count($ids), '?'));
    $thr_s  = $db->prepare(
        "SELECT DISTINCT topic_id FROM reply
          WHERE topic_id IN ($ph) AND parent_reply_id IS NOT NULL"
    );
    $thr_s->execute($ids);
    $threaded_ids = array_flip($thr_s->fetchAll(PDO::FETCH_COLUMN));
}

$total_pages = (int)ceil(((int)$forum['topic_count']) / $per_page);

bb_mirror_chrome_header($forum['title']);

// Deterministic gradient from first letter of title.
function topic_gradient(string $title): string {
    static $grads = [
        'linear-gradient(135deg,#8c4a2b,#c4631e)',
        'linear-gradient(135deg,#6b2c91,#8c4a2b)',
        'linear-gradient(135deg,#b8860b,#6b4a1a)',
        'linear-gradient(135deg,#5a8c5a,#2a4a2a)',
        'linear-gradient(135deg,#c4631e,#6b2c1e)',
        'linear-gradient(135deg,#98938b,#6b6760)',
        'linear-gradient(135deg,#6b2c91,#3a1e4a)',
    ];
    $c = strtoupper($title[0] ?? 'A');
    return $grads[(ord($c) - 65 + 26) % count($grads)];
}

function fmt_last_topic($ts): string {
    if (!$ts) return '—';
    $unix = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
    if (!$unix) return '—';
    return date('Y-m-d', $unix);
}
?>

<div class="page">
  <nav class="breadcrumbs">
    <a href="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/') ?>">Forums</a> /
    <?= htmlspecialchars($forum['title']) ?>
  </nav>

  <h1 class="bb-mirror__page-title"><?= htmlspecialchars($forum['title']) ?></h1>
  <?php if ($forum['description'] && trim((string)$forum['description']) !== ''): ?>
    <p class="forum__description"><?= htmlspecialchars($forum['description']) ?></p>
  <?php endif; ?>

  <?php if (!$topics): ?>
    <p class="bb-mirror__empty">No topics in this forum yet.</p>
  <?php else: ?>
    <ul class="topic-list" role="list">
      <?php foreach ($topics as $t): ?>
        <?php
          $is_sticky   = (bool)$t['sticky_kind'];
          $is_closed   = $t['status'] === 'closed';
          $has_threads = isset($threaded_ids[$t['id']]) && (int)$t['reply_count'] > 0;
          $cls_mods    = '';
          if ($is_sticky) $cls_mods .= ' topic--sticky';
          if ($is_closed) $cls_mods .= ' topic--closed';
          $href = htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/' . $forum['slug'] . '/' . $t['slug'] . '/');
          $letter = strtoupper(mb_substr($t['title'], 0, 1));
          $grad   = topic_gradient($t['title']);
        ?>
        <li>
          <a class="topic<?= $cls_mods ?>"
             href="<?= $href ?>"
             data-topic-id="<?= (int)$t['id'] ?>">

            <div class="topic__icon">
              <?php if ($t['sticky_kind'] === 'super'): ?>📍
              <?php elseif ($t['sticky_kind'] === 'forum'): ?>📌
              <?php elseif ($is_closed): ?>🔒
              <?php else: ?>○
              <?php endif; ?>
            </div>

            <div class="topic__monogram" style="background:<?= $grad ?>">
              <?= htmlspecialchars($letter) ?>
            </div>

            <div class="topic__main">
              <div class="topic__title">
                <?= htmlspecialchars($t['title']) ?>
              </div>
              <div class="topic__meta">
                by <span class="topic__meta-author"><?= htmlspecialchars($t['author_name'] ?: '—') ?></span>
                · <?= (int)$t['reply_count'] ?> repl<?= (int)$t['reply_count'] === 1 ? 'y' : 'ies' ?>
                · <?= (int)$t['voice_count'] ?> voice<?= (int)$t['voice_count'] === 1 ? '' : 's' ?>
              </div>
              <?php if ($has_threads): ?>
                <div class="topic__thread-note">↩ threaded discussion</div>
              <?php endif; ?>
            </div>

            <div class="topic__stats">
              <strong><?= (int)$t['reply_count'] ?></strong>
              <?php if ($t['last_active_at']): ?>
                <?= fmt_last_topic($t['last_active_at']) ?>
              <?php endif; ?>
            </div>

          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($total_pages > 1): ?>
      <nav class="pager" aria-label="Topic pages">
        <?php if ($page > 1): ?>
          <a href="?page=<?= $page - 1 ?>">← Prev</a>
        <?php endif; ?>
        <span class="pager__current">Page <?= $page ?> of <?= $total_pages ?></span>
        <?php if ($page < $total_pages): ?>
          <a href="?page=<?= $page + 1 ?>">Next →</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php bb_mirror_chrome_footer(); ?>
