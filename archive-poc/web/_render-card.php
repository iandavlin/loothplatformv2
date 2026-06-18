<?php
/**
 * Shared rail-card renderer. Used by initial SSR and the AJAX rows-more endpoint.
 * Returns HTML string for one rcard <a>.
 *
 * Dependencies (must be in scope when called):
 *   - h()                helper       (htmlspecialchars wrapper)
 *   - thumb_url()        helper       (resolves image URL with fallback)
 *   - tier_label()       helper       (e.g. 'lite' → 'Looth Lite')
 *   - KIND_LABELS        const map    (e.g. 'video' → 'Video')
 *   - LG_FALLBACK_IMG    const string
 */
if (function_exists('archive_poc_render_rcard')) return;
function archive_poc_render_rcard(array $it, string $viewer_tier): string {
    $tier = strtolower((string)($it['tier'] ?? 'public'));
    $kind = $it['kind'] ?? 'misc';
    $date = !empty($it['published_at']) ? date('M j, Y', (int)$it['published_at']) : '';
    $tier_rank = ['public' => 0, 'lite' => 1, 'pro' => 2];
    $is_gated  = ($tier_rank[$tier] ?? 0) > ($tier_rank[$viewer_tier] ?? 0);
    $gated_class = $is_gated ? ' rcard--gated rcard--gated-' . $tier : '';
    ob_start(); ?>
<a class="rcard rcard--<?= h($kind) ?><?= $gated_class ?>" href="<?= h($it['url'] ?: '#') ?>">
  <?php
    // Prefer the real YouTube thumbnail for video cards. Gated cards keep the
    // generic/featured image so the video id (embedded in the ytimg URL) never
    // reaches a non-entitled viewer.
    $thumb_src = thumb_url($it);
    if (!empty($it['yt_id']) && !$is_gated) {
        $thumb_src = 'https://i.ytimg.com/vi/' . h($it['yt_id']) . '/hqdefault.jpg';
    }
  ?>
  <div class="rcard__img-wrap">
    <?php /* uploads thumbs via the resizer + 1x/2x pair (craft gate 6/12);
             fp_img helpers live in _render-main-row.php, included before any
             card renders on every front-page path. */ ?>
    <img class="rcard__img" src="<?= h(function_exists('fp_img') ? fp_img($thumb_src, 480) : $thumb_src) ?>"<?= function_exists('fp_img_srcset') ? fp_img_srcset($thumb_src, 240, '(max-width: 640px) 45vw, 240px') : '' ?> alt="" loading="lazy" width="480" height="320" onerror="this.onerror=null;this.src='<?= h(LG_FALLBACK_IMG) ?>'">
    <?php if (!empty($it['yt_id']) && !$is_gated): ?>
      <button type="button" class="rcard__play" data-yt-play="<?= h($it['yt_id']) ?>" aria-label="Play video"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></button>
    <?php endif; ?>
    <?php if ($is_gated): ?>
      <span class="rcard__gate" aria-label="<?= h(ucfirst($tier)) ?> member content" title="<?= h(ucfirst($tier)) ?> members only">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="3" y="11" width="18" height="11" rx="2"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
        </svg>
      </span>
    <?php endif; ?>
  </div>
  <div class="rcard__body">
    <h3 class="rcard__title"><?= h($it['title'] ?: '(untitled)') ?></h3>
    <div class="rcard__meta">
      <?php if (!empty($it['author_name'])): ?><span class="author"><?= h($it['author_name']) ?></span><?php endif; ?>
      <?php if ($date): ?><span><?= h($date) ?></span><?php endif; ?>
    </div>
    <div class="rcard__foot">
      <span class="badge badge--<?= h($tier) ?>"><?= h(tier_label($tier)) ?></span>
      <span class="badge badge--kind"><?= h(KIND_LABELS[$kind] ?? $kind) ?></span>
      <?php if (!empty($it['like_count'])): ?><span class="like">♥ <?= (int)$it['like_count'] ?></span><?php endif; ?>
    </div>
  </div>
</a>
<?php
    return ob_get_clean();
}
