<?php
/**
 * /forums-poc/ — forum index.
 *
 * Render order:
 *   1. "General"      — top-level forums with no subforums + no group attachment
 *   2. "Local Looths" — top-level forums with no subforums + a group attachment
 *                       (the 9 regional chapters + Looth Group Partners + Jannies-shaped)
 *   3. Each category container (top-level forums WITH subforums) renders as its
 *      own named section.
 *
 * Reads are NOT tier-gated — `visibility = 'public'` on forum is the only gate.
 * Posting/replying gates on tier separately (form-level).
 */

require __DIR__ . '/../_chrome.php';

$db = bb_mirror_db();

// LEFT JOIN bp_group via effective_group_id so each forum carries its group's
// display name. Orphan-gate rule: a forum whose group has been deleted falls
// back to "no group" (no pill, no gating).
$sql = "
    SELECT f.id, f.slug, f.title, f.description, f.forum_type, f.visibility,
           f.topic_count, f.total_topic_count, f.total_reply_count,
           COALESCE(f.total_last_active_at, f.last_active_at) AS last_active_at,
           f.tier_gate, f.parent_forum_id, f.menu_order,
           f.group_id, f.effective_group_id,
           bg.name AS effective_group_name,
           bg.status AS effective_group_status
      FROM forum f
      LEFT JOIN bp_group bg ON bg.id = f.effective_group_id
     WHERE f.visibility = 'public'
       AND f.status IN ('open', 'closed')
     ORDER BY f.parent_forum_id NULLS FIRST, f.menu_order ASC, f.last_active_at DESC NULLS LAST
";
$stmt = $db->query($sql);
$rows = $stmt->fetchAll();

// Build a parent → kids map.
$children = [];
$top      = [];
foreach ($rows as $r) {
    if ($r['parent_forum_id'] === null) $top[] = $r;
    else $children[(int)$r['parent_forum_id']][] = $r;
}

// Bucket the top-level forums.
//   $containers  — top-level forums that have any subforums
//   $local       — regional Looths chapters
//   $general     — everything else (standalone site-wide forums)
//
// Local Looths detection: slug contains "looth" (catches `ohio-local-looths`,
// `middle-tennessee-looths`, `basque-country-looths`, etc.) but NOT
// `looth-group-partners` (admin-only, different shape). Falling back to
// pure-slug match because `effective_group_id` is missing for at least one
// regional (middle-tennessee-looths has `_bbp_group_ids = a:0:{}` upstream).
$containers = [];
$general    = [];
$local      = [];
foreach ($top as $t) {
    $kids = $children[(int)$t['id']] ?? [];
    $slug = (string)$t['slug'];
    $is_local_looths = (
        str_contains($slug, 'looth') &&
        $slug !== 'looth-group-partners'
    );
    if ($kids || $t['forum_type'] === 'category') {
        $containers[] = $t;
    } elseif ($is_local_looths) {
        $local[] = $t;
    } else {
        $general[] = $t;
    }
}

bb_mirror_chrome_header('Forums');

function fmt_last_active_idx($ts): string {
    if (!$ts) return '—';
    $unix = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
    if (!$unix) return '—';
    $diff = time() - $unix;
    if ($diff < 3600)   return round($diff / 60) . 'm ago';
    if ($diff < 86400)  return round($diff / 3600) . 'h ago';
    if ($diff < 604800) return round($diff / 86400) . 'd ago';
    return date('Y-m-d', $unix);
}

function forum_gradient(string $title): string {
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

function render_forum_card(array $f): void {
    $href   = htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/' . $f['slug'] . '/');
    $letter = strtoupper(mb_substr($f['title'], 0, 1));
    $grad   = forum_gradient($f['title']);
    $last   = fmt_last_active_idx($f['last_active_at'] ?? null);
    $count  = (int)($f['total_topic_count'] ?? $f['topic_count'] ?? 0);
    ?>
    <a class="forum-card" href="<?= $href ?>">
      <div class="forum-card__icon" style="background:<?= $grad ?>"><?= htmlspecialchars($letter) ?></div>
      <div class="forum-card__body">
        <div class="forum-card__title">
          <?= htmlspecialchars($f['title']) ?>
          <?php if (!empty($f['effective_group_name'])): ?>
            <span class="group-pill" title="Group-attached forum">
              <?= htmlspecialchars($f['effective_group_name']) ?>
            </span>
          <?php endif; ?>
          <?php if (($f['tier_gate'] ?? 'public') !== 'public'): ?>
            <span class="gate-pill gate-pill--<?= htmlspecialchars($f['tier_gate']) ?>">
              <?= htmlspecialchars(strtoupper($f['tier_gate'])) ?>
            </span>
          <?php endif; ?>
        </div>
        <div class="forum-card__meta">
          <span><?= $count ?> topics</span>
          <span class="forum-card__last"><?= $last ?></span>
        </div>
      </div>
    </a>
    <?php
}

// Render a named cat-group containing a flat grid of forum-cards.
function render_section(string $title, array $forums, ?string $desc = null): void {
    if (!$forums) return;
    ?>
    <div class="cat-group">
      <div class="cat-group__head">
        <h2 class="cat-group__title"><?= htmlspecialchars($title) ?></h2>
        <span class="cat-group__count"><?= count($forums) ?> forum<?= count($forums) !== 1 ? 's' : '' ?></span>
      </div>
      <?php if ($desc): ?>
        <p class="cat-group__desc"><?= htmlspecialchars($desc) ?></p>
      <?php endif; ?>
      <div class="forum-grid">
        <?php foreach ($forums as $f) render_forum_card($f); ?>
      </div>
    </div>
    <?php
}

// Render a category-container forum: its own named cat-group containing its
// subforums. Title comes from the parent forum's title.
function render_container(array $parent, array $children): void {
    $kids = $children[(int)$parent['id']] ?? [];
    $desc = trim((string)($parent['description'] ?? ''));
    render_section($parent['title'], $kids, $desc ?: null);
}
?>

<div class="page">
  <h1 class="bb-mirror__page-title">Forums</h1>

  <?php if (!$top): ?>
    <p class="bb-mirror__empty">No forums to display.</p>
  <?php else: ?>

    <?php render_section('General', $general); ?>
    <?php render_section('Local Looths', $local, 'Regional chapters — discuss in your zone.'); ?>

    <?php foreach ($containers as $parent) render_container($parent, $children); ?>

  <?php endif; ?>
</div>

<?php bb_mirror_chrome_footer(); ?>
