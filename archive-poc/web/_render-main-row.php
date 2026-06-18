<?php
if (!function_exists('fp_img')) {
    /**
     * Front-page image delivery (craft gate, Ian 6/12 "fix front page"):
     * uploads-hosted images route through the /img.php resizer at the slot
     * width; external URLs (ytimg etc.) pass through. fp_img_srcset() adds
     * the 1x/2x pair so phones and 1x screens stop downloading full-size
     * originals (a 1920px sponsor JPG was shipping into a 480px card).
     */
    function fp_img(?string $url, int $w): string
    {
        if (!$url) return '';
        if (preg_match('#/wp-content/uploads/(.+)$#', $url, $m)) {
            return '/img.php?s=' . rawurlencode($m[1]) . '&w=' . $w;
        }
        return $url;
    }
    function fp_img_srcset(?string $url, int $w, string $sizes): string
    {
        if (!$url || !preg_match('#/wp-content/uploads/#', $url)) return '';
        return ' srcset="' . h(fp_img($url, $w)) . ' ' . $w . 'w, '
                           . h(fp_img($url, $w * 2)) . ' ' . ($w * 2) . 'w" sizes="' . h($sizes) . '"';
    }
}
?>
<?php if ($layout === 'activity'):
        // Pre-pass: classify each item and group consecutive text-only items into stacks of 2.
        $items = $row['items'];
        $slots = [];      // each slot is ['type' => 'card'|'stack', 'cards' => [...]]
        $buffer = [];     // accumulate consecutive text-only cards
        $classify = function(array $it) {
            $is_sticky = ($it['kind'] ?? '') === 'sticky';
            $has_image = !empty($it['image_url']);
            // Prefer yt_id from API (topic body, update URL, etc.); fall back
            // to target.url detection for legacy items.
            $yt_id = !empty($it['yt_id']) ? $it['yt_id'] : null;
            if (!$yt_id && !empty($it['target']['url']) && preg_match('~(?:(?:m\.|www\.)?youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,15})~', $it['target']['url'], $m)) {
                $yt_id = $m[1];
            }
            if ($yt_id && empty($it['image_url'])) {
                $it['image_url'] = 'https://i.ytimg.com/vi/' . $yt_id . '/hqdefault.jpg';
                $has_image = true;
            }
            $variant = $is_sticky ? 'sticky' : ($has_image ? 'image' : 'text');
            $it['_meta'] = [
                'is_sticky' => $is_sticky,
                'has_image' => $has_image,
                'variant'   => $variant,
                'yt_id'     => $yt_id,
            ];
            return $it;
        };
        foreach ($items as $raw) {
            $it = $classify($raw);
            if ($it['_meta']['variant'] === 'text') {
                $buffer[] = $it;
                if (count($buffer) === 2) {
                    $slots[] = ['type' => 'stack', 'cards' => $buffer];
                    $buffer = [];
                }
            } else {
                if (count($buffer) >= 2) {
                    $slots[] = ['type' => 'stack', 'cards' => $buffer];
                } elseif (count($buffer) === 1) {
                    // Lone text card — render as solo, not a 1-item stack
                    $slots[] = ['type' => 'card', 'cards' => $buffer];
                }
                $buffer = [];
                $slots[] = ['type' => 'card', 'cards' => [$it]];
            }
        }
        if (count($buffer) >= 2) {
            $slots[] = ['type' => 'stack', 'cards' => $buffer];
        } elseif (count($buffer) === 1) {
            $slots[] = ['type' => 'card', 'cards' => $buffer];
        }

        // Closure to render a single acard
        $render_card = function(array $it, bool $compact = false) {
            $meta = $it['_meta'];
            $tier = strtolower((string)($it['tier'] ?? 'public'));
            $viewer_tier = $GLOBALS['LG_VIEWER_TIER'] ?? 'public';
            $tier_rank = ['public' => 0, 'lite' => 1, 'pro' => 2];
            $is_gated = ($tier_rank[$tier] ?? 0) > ($tier_rank[$viewer_tier] ?? 0);
            $gated_class = $is_gated ? ' acard--gated acard--gated-' . $tier : '';
            $user = $it['user'] ?? null;
            $target = $it['target'] ?? null;
            $href = $target['url'] ?? '#';
            $when_iso = $it['when'] ? gmdate('c', (int)$it['when']) : '';
            $when_short = $it['when'] ? date('M j', (int)$it['when']) : '';
            $extra = $meta['yt_id'] ? ' acard--youtube' : '';
            if ($compact) $extra .= ' acard--compact';
            $img_url = $meta['has_image'] ? $it['image_url'] : '';
            // Leak guard: the YouTube thumbnail URL embeds the video id. For a
            // gated card (viewer below the content's tier) don't emit it — fall
            // back to the generic image so a non-entitled viewer never receives
            // the id anywhere in the HTML.
            if ($is_gated && str_contains((string)$img_url, 'ytimg.com')) $img_url = LG_FALLBACK_IMG;
            ob_start();
?>
        <a class="acard acard--<?= h($meta['variant']) ?> acard--kind-<?= h($target['kind'] ?? 'misc') ?><?= $extra ?><?= $gated_class ?>" href="<?= h($href) ?>">
          <?php if ($meta['is_sticky']): ?><span class="acard__pin">📌 Pinned</span><?php endif; ?>
          <?php if ($meta['has_image']): ?>
            <div class="acard__img-wrap">
              <img class="acard__img" src="<?= h(fp_img($img_url, 560)) ?>"<?= fp_img_srcset($img_url, 480, '(max-width: 640px) 100vw, 480px') /* buckets 480/960 */ ?> alt="" loading="lazy" width="560" height="320" onerror="this.onerror=null;this.src='<?= h(LG_FALLBACK_IMG) ?>'">
              <?php if ($meta['yt_id'] && !$is_gated): ?><button type="button" class="acard__play" data-yt-play="<?= h($meta['yt_id']) ?>" aria-label="Play video"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></button><?php endif; ?>
              <?php if ($is_gated): ?>
                <span class="acard__gate" aria-label="<?= h(ucfirst($tier)) ?> member content" title="<?= h(ucfirst($tier)) ?> members only">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                  </svg>
                </span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="acard__body">
            <?php if ($user): ?>
            <div class="acard__head">
              <?php if (!empty($user['avatar_url'])): ?><img class="acard__avatar" src="<?= h($user['avatar_url']) ?>" alt="" width="32" height="32"><?php endif; ?>
              <span class="acard__user">
                <span class="acard__name"><?= h($user['name']) ?></span>
                <span class="acard__action"><?= h($it['action'] ?? '') ?></span>
              </span>
            </div>
            <?php endif; ?>
            <?php if ($target && !empty($target['title'])): ?>
              <h3 class="acard__title"><?= h($target['title']) ?></h3>
            <?php endif; ?>
            <?php if (!$is_gated && !empty($it['excerpt'])): /* excerpt = baked body prose; never to a non-entitled viewer */ ?>
              <p class="acard__excerpt"><?= h($it['excerpt']) ?></p>
            <?php endif; ?>
            <?php
              $cm = $it['comments'] ?? null;
              $cm_shown = $compact ? 1 : 2;
              if (is_array($cm) && !empty($cm['recent'])):
            ?>
              <ul class="acard__comments">
              <?php foreach (array_slice($cm['recent'], 0, $cm_shown) as $c): if (empty($c['snippet'])) continue; ?>
                <li class="acard__comment">
                  <?php if (!empty($c['avatar_url'])): ?>
                    <img class="acard__comment-avatar" src="<?= h($c['avatar_url']) ?>" alt="" width="20" height="20" loading="lazy">
                  <?php endif; ?>
                  <span class="acard__comment-text"><span class="acard__comment-name"><?= h($c['user_name']) ?>:</span> <?= h($c['snippet']) ?></span>
                </li>
              <?php endforeach; ?>
              </ul>
              <?php if ((int)$cm['total'] > $cm_shown): ?>
                <span class="acard__comments-more">+<?= (int)$cm['total'] - $cm_shown ?> more</span>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($target && !empty($target['url'])): ?>
              <span class="acard__more">Read more →</span>
            <?php endif; ?>
            <div class="acard__foot">
              <?php $aid = (int)($it['activity_id'] ?? 0); if ($aid > 0): ?>
                <button type="button"
                        class="acard__like<?= !empty($it['liked_by_me']) ? ' is-liked' : '' ?>"
                        data-like
                        data-activity-id="<?= $aid ?>"
                        aria-pressed="<?= !empty($it['liked_by_me']) ? 'true' : 'false' ?>"
                        aria-label="Like">
                  <svg class="acard__heart" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                    <path d="M12 21s-7-4.35-9.5-9.18C.87 8.4 2.92 4.5 6.6 4.5c1.86 0 3.4 1 4.4 2.5 1-1.5 2.54-2.5 4.4-2.5 3.68 0 5.73 3.9 4.1 7.32C19 16.65 12 21 12 21z"
                          fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                  </svg>
                </button>
              <?php endif; ?>
              <?php if ($tier !== 'public'): ?>
                <span class="badge badge--<?= h($tier) ?>"><?= h(tier_label($tier)) ?></span>
              <?php endif; ?>
              <?php if ($target): ?>
                <span class="badge badge--kind"><?= h(KIND_LABELS[$target['kind'] ?? ''] ?? ($target['kind'] ?? '')) ?></span>
              <?php endif; ?>
              <?php
                // Always render the reactions cluster (even at 0) so the like
                // button's optimistic toggle has a [data-react-total] hook to
                // update. The --empty modifier styles 0-state subtly.
                $rx = $it['reactions'] ?? ['total' => 0, 'top' => []];
                $rx_total = (int) ($rx['total'] ?? 0);
                $rx_class = 'acard__reactions' . ($rx_total === 0 ? ' acard__reactions--empty' : '');
                $rx_title = $rx_total > 0 ? h(implode(', ', array_map(fn($r) => $r['label'] . ' ' . $r['count'], $rx['top']))) : '';
              ?>
                <span class="<?= $rx_class ?>" title="<?= $rx_title ?>">
                  <?php foreach (array_slice($rx['top'] ?? [], 0, 3) as $r): ?>
                    <span class="acard__reaction"><?= $r['emoji'] ?></span>
                  <?php endforeach; ?>
                  <span class="acard__reaction-total" data-react-total><?= $rx_total ?></span>
                </span>
              <?php if ($when_iso): ?>
                <time class="acard__when" datetime="<?= h($when_iso) ?>"><?= h($when_short) ?></time>
              <?php endif; ?>
            </div>
          </div>
        </a>
<?php
            return ob_get_clean();
        };
?>
    <section class="row row--activity" data-row-id="<?= h($row_id) ?>"
             data-acard-count="<?= count($items) ?>">
      <header class="row__head row__head--activity">
        <div class="row__head__brand">
          <span class="live-dot" aria-hidden="true"></span>
          <h2 class="row__title row__title--activity"><?= h($row['title'] ?: 'Looth Live') ?></h2>
          <span class="row__subtitle">right now in the community</span>
        </div>
      </header>
      <button class="acard-nav acard-nav--prev" type="button" aria-label="Previous">‹</button>
      <button class="acard-nav acard-nav--next" type="button" aria-label="Next">›</button>
      <div class="rail" data-activity-rail>
<?php foreach ($slots as $slot):
        if ($slot['type'] === 'stack'):
?>
        <div class="acard-stack">
<?php foreach ($slot['cards'] as $it): echo $render_card($it, true); endforeach; ?>
        </div>
<?php else: echo $render_card($slot['cards'][0]); endif; ?>
<?php endforeach; ?>
      </div>
    </section>

<?php elseif ($layout === 'billboard'): $it = $row['items'][0]; $tier = strtolower($it['tier'] ?? 'public'); $kind = $it['kind'];
        $is_gated = lg_archive_poc_is_gated($tier, $GLOBALS['LG_VIEWER_TIER'] ?? 'public'); ?>
    <section class="row row--billboard" data-row-id="<?= h($row_id) ?>">
      <div class="billboard__search">
        <form class="topbar" id="topbar-hero" autocomplete="off" onsubmit="return false">
          <svg class="topbar__icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6b6f6b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <input id="q-hero" name="q" type="search" placeholder="Search or just browseâ¦">
        </form>
      </div>
      <a class="billboard billboard--<?= h($kind) ?>" href="<?= h($it['url'] ?: '#') ?>">
        <img class="billboard__img" src="<?= h(fp_img(thumb_url($it), 1280)) ?>"<?= fp_img_srcset(thumb_url($it), 800, '(max-width: 640px) 100vw, 960px') ?> alt="" width="1280" height="640" onerror="this.onerror=null;this.src='<?= h(LG_FALLBACK_IMG) ?>'">
        <div class="billboard__scrim"></div>
        <div class="billboard__body">
          <span class="billboard__eyebrow">
            <span class="billboard__kind"><?= h(KIND_LABELS[$kind] ?? $kind) ?></span>
            <?php if ($tier !== 'public'): ?><span class="billboard__tier billboard__tier--<?= h($tier) ?>"><?= h(tier_label($tier)) ?></span><?php endif; ?>
          </span>
          <h2 class="billboard__title"><?= h($it['title'] ?: '(untitled)') ?></h2>
          <?php if (!$is_gated && !empty($it['excerpt'])): /* baked body prose — gate it */ ?>
            <p class="billboard__excerpt"><?= h(mb_substr($it['excerpt'], 0, 180)) ?><?= mb_strlen($it['excerpt']) > 180 ? '…' : '' ?></p>
          <?php endif; ?>
          <div class="billboard__meta">
            <?php if ($it['author_name']): ?><span class="billboard__author"><?= h($it['author_name']) ?></span><?php endif; ?>
            <span class="billboard__cta"><?= h(kind_cta($kind)) ?> →</span>
          </div>
        </div>
      </a>
      <script type="application/ld+json"><?= row_jsonld($row) ?></script>
    </section>

<?php elseif ($layout === 'events'): ?>
    <section class="row row--events" data-row-id="<?= h($row_id) ?>">
      <header class="row__head">
        <h2 class="row__title"><?= h($row['title']) ?></h2>
      </header>
      <div class="rail">
<?php foreach ($row['items'] as $it): $blk = event_date_block($it); $tier = strtolower($it['tier'] ?? 'public');
        $ev_start = (int)($it['event_start_at'] ?? 0);
        $ev_end   = (int)($it['event_end_at'] ?? 0) ?: ($ev_start ? $ev_start + 3600 : 0);
        $ev_loc   = trim((string)($it['event_region'] ?? ''));
        // Card → the event post page for everyone (Ian 6/12); the post carries
        // RSVP/join and its own upgrade gate. The Zoom URL only rides the
        // calendar ICS, tier-gated. Never leak Zoom anon.
        $ev_viewer_tier = $GLOBALS['LG_VIEWER_TIER'] ?? 'public';
        $ev_rank    = ['public' => 0, 'lite' => 1, 'pro' => 2];
        $ev_gated   = ($ev_rank[$tier] ?? 0) > ($ev_rank[$ev_viewer_tier] ?? 0);
        $ev_can_join = !$ev_gated && !empty($it['event_join_url']);
        $ev_href    = $it['url'] ?: '#';
        $ev_cal_url = $ev_can_join ? $it['event_join_url'] : ($it['url'] ?: '');
        $ev_cta     = $ev_gated ? 'Details →' : 'RSVP →'; ?>
        <a class="ecard" href="<?= h($ev_href) ?>">
          <div class="ecard__date">
            <span class="ecard__mon"><?= h($blk['mon']) ?></span>
            <span class="ecard__day"><?= h($blk['day']) ?></span>
          </div>
          <div class="ecard__body">
            <h3 class="ecard__title"><?= h($it['title']) ?></h3>
            <div class="ecard__meta">
              <span class="ecard__time"><?= h($blk['time']) ?></span>
              <?php if (!empty($blk['rel'])): ?><span class="ecard__rel"><?= h($blk['rel']) ?></span><?php endif; ?>
              <?php if (!empty($it['event_region'])): ?><span class="ecard__region"><?= h($it['event_region']) ?></span><?php endif; ?>
            </div>
            <div class="ecard__foot">
              <span class="badge badge--<?= h($tier) ?>"><?= h(tier_label($tier)) ?></span>
              <?php if ($ev_start > 0): ?>
                <button type="button" class="ecard__cal" data-ics
                        data-title="<?= h($it['title']) ?>"
                        data-start="<?= $ev_start ?>"
                        data-end="<?= $ev_end ?>"
                        data-url="<?= h($ev_cal_url) ?>"
                        data-location="<?= h($ev_loc) ?>"
                        aria-label="Add to calendar">📅 Add</button>
              <?php endif; ?>
              <span class="ecard__cta"><?= h($ev_cta) ?></span>
            </div>
          </div>
        </a>
<?php endforeach; ?>
      </div>
      <script type="application/ld+json"><?= row_jsonld($row) ?></script>
    </section>

<?php elseif ($layout === 'cta-bar'):
        $buttons = $is_member ? LG_CTA_MEMBER : LG_CTA_PUBLIC;
?>
    <section class="row row--cta" data-row-id="<?= h($row_id) ?>">
      <div class="cta-bar">
<?php foreach ($buttons as $b): ?>
        <a class="cta-btn cta-btn--<?= h($b['style']) ?>" href="<?= h($b['url']) ?>"<?= !empty($b['action']) ? ' data-action="' . h($b['action']) . '"' : '' ?><?= !empty($b['attr']) ? ' ' . $b['attr'] : '' ?>><?= !empty($b['icon']) ? $b['icon'] : '' ?><?= h($b['label']) ?></a>
<?php endforeach; ?>
      </div>
    </section>

<?php elseif ($layout === 'local-looths'): ?>
    <section class="row row--looths" data-row-id="<?= h($row_id) ?>">
      <header class="row__head">
        <h2 class="row__title"><?= h($row['title'] ?: 'Local Looths') ?></h2>
        <span class="row__subtitle">find your people</span>
      </header>
      <div class="looth-rail">
<?php foreach (LG_LOCAL_LOOTHS as $l): ?>
        <a class="looth-pin" href="<?= h($l['url']) ?>" title="<?= h($l['name']) ?>">
          <img class="looth-pin__avatar" src="<?= h($l['avatar']) ?>" alt="" loading="lazy" width="48" height="48"
               onerror="this.onerror=null;this.src='<?= h(LG_FALLBACK_IMG) ?>'">
          <span class="looth-pin__name"><?= h($l['name']) ?></span>
        </a>
<?php endforeach; ?>
      </div>
    </section>

<?php elseif ($layout === 'sponsors'): ?>
    <section class="row row--sponsors" data-row-id="<?= h($row_id) ?>">
      <header class="row__head row__head--sponsors">
        <h2 class="row__title row__title--sponsors"><?= h($row['title'] ?: 'Our Sponsors') ?></h2>
      </header>
      <div class="sponsor-rail">
<?php foreach (LG_SPONSORS as $s):
        $bg = !empty($s['bg']) ? 'style="background:' . h($s['bg']) . ';"' : '';
?>
        <a class="sponsor-tile" href="<?= h($s['url']) ?>" target="_blank" rel="noopener" <?= $bg ?>>
          <img class="sponsor-tile__logo" src="<?= h($s['logo']) ?>" alt="<?= h($s['name']) ?>" loading="lazy">
        </a>
<?php endforeach; ?>
      </div>
    </section>

<?php elseif ($layout === 'discussions'): $d_bare = !empty($row['_bare']); /* _bare = emit just the rail (no section/header) — used under the hub-teaser heading */ ?>
    <?php if (!$d_bare): ?>
    <section class="row row--discussions" data-row-id="<?= h($row_id) ?>">
      <header class="row__head">
        <h2 class="row__title"><?= h($row['title']) ?></h2>
      </header>
    <?php endif; ?>
      <div class="rail">
<?php foreach ($row['items'] as $it):
        $author = $it['author_name'] ?: 'Member';
        $author_id = (int)($it['author_id'] ?? 0);
        $avatar = 'https://picsum.photos/seed/lg-user-' . ($author_id ?: $it['id']) . '/64/64';
        $replies = (int)($it['reply_count'] ?? 0);
        $last = (int)($it['last_activity'] ?? 0);
        $is_stale = $last > 0 && (time() - $last) > 86400 * 30;
        $badge_label = $last ? ($is_stale ? 'Quiet · ' . rel_time($last) : 'Active · ' . rel_time($last)) : 'New';
        $badge_class = $is_stale ? 'dcard__badge--quiet' : 'dcard__badge--active';
        $is_gated = lg_archive_poc_is_gated($it['tier'] ?? 'public', $GLOBALS['LG_VIEWER_TIER'] ?? 'public');
        // Slugs for the front-page discussion modal (fp-discuss.js). Parsed from
        // the canonical /hub/<forum>/<topic>/ href so the card stays a real link
        // (no-JS / middle-click fallback) while JS upgrades the click to a modal.
        $d_forum = ''; $d_topic = '';
        if (preg_match('#/hub/([^/]+)/([^/]+)/#', (string)($it['url'] ?? ''), $dm)) { $d_forum = $dm[1]; $d_topic = $dm[2]; }
        $d_modal = ($d_forum !== '' && $d_topic !== '');
?>
        <a class="dcard" href="<?= h($it['url'] ?: '#') ?>"<?php if ($d_modal): ?> data-topic-id="<?= (int)($it['id'] ?? 0) ?>" data-forum="<?= h($d_forum) ?>" data-topic="<?= h($d_topic) ?>"<?php endif; ?>>
          <div class="dcard__head">
            <img class="dcard__avatar" src="<?= h($avatar) ?>" alt="" width="32" height="32" loading="lazy" onerror="this.onerror=null;this.style.background='#87986a'">
            <span class="dcard__author"><?= h($author) ?></span>
          </div>
          <h3 class="dcard__title"><?= h($it['title']) ?></h3>
          <?php if (!$is_gated): /* discussion body prose — gate it */ ?><p class="dcard__excerpt"><?= h($it['excerpt'] ?: '') ?></p><?php endif; ?>
          <div class="dcard__foot">
            <span class="dcard__replies">💬 <?= $replies ?> <?= $replies === 1 ? 'reply' : 'replies' ?></span>
            <span class="dcard__badge <?= $badge_class ?>"><?= h($badge_label) ?></span>
          </div>
        </a>
<?php endforeach; ?>
      </div>
    <?php if (!$d_bare): ?>
      <script type="application/ld+json"><?= row_jsonld($row) ?></script>
    </section>
    <?php endif; ?>

<?php elseif ($layout === 'video-promo'):
        // Two-column row: YouTube video + freeform HTML copy.
        // Stored shape: row['query'] (re-used as a config JSON blob by the dash):
        //   {
        //     "side":"video-right|video-left",
        //     "video_id":"YT_ID",
        //     "html":"...",                         // supports shortcodes (see below)
        //     "aspect":"16x9|4x3|1x1"
        //   }
        // Shortcodes recognized inside `html`:
        //   [member_map]          → inline map for logged-in viewers; teaser image for anon
        //   [member_count]        → live member count (TBD)
        $cfg = is_array($row['config'] ?? null) ? $row['config']
             : (is_array($row['query']  ?? null) ? $row['query']
             : []);
        $side    = $cfg['side']     ?? 'video-right';
        $vid     = trim((string)($cfg['video_id'] ?? ''));
        // Instagram embed (Ian 6/12): `instagram` accepts a post URL or bare
        // shortcode and takes precedence over video_id (which stays in config
        // as the easy revert). Renders IG's /embed/ doc — no embed.js needed.
        $ig      = trim((string)($cfg['instagram'] ?? ''));
        if ($ig !== '' && preg_match('~instagram\.com/(?:p|reel|tv)/([A-Za-z0-9_-]+)~', $ig, $igm)) $ig = $igm[1];
        if ($ig !== '' && !preg_match('~^[A-Za-z0-9_-]{5,40}$~', $ig)) $ig = '';
        $html    = (string)($cfg['html'] ?? '');
        $aspect  = $cfg['aspect']   ?? '16x9';
        $viewer_tier = $GLOBALS['LG_VIEWER_TIER'] ?? 'public';
        $is_member_view = $viewer_tier !== 'public';

        // ── Expand shortcodes in $html ─────────────────────────────────
        // Keep this small + explicit. If we ever grow >3 shortcodes, refactor
        // into a registry; for now, inline is fine.
        $html = preg_replace_callback('/\[member_map\]/', function () use ($is_member_view) {
            if ($is_member_view) {
                return '<div class="vpromo-shortcode vpromo-shortcode--map">'
                     . '<div class="vpromo__map" data-init="members-geo" aria-label="Looth member map"></div>'
                     . '</div>';
            }
            return '<div class="vpromo-shortcode vpromo-shortcode--map-teaser">'
                 . '<img src="/archive-poc/member-map-teaser.webp" alt="Looth members across the globe" loading="lazy">'
                 . '</div>';
        }, $html);

        if ($vid === '' && $ig === '' && trim($html) === '') goto video_promo_end;
?>
    <section class="row row--video-promo row--video-promo--<?= h($side) ?>" data-row-id="<?= h($row_id) ?>">
      <?php if (!empty($row['title'])): ?>
        <header class="row__head"><h2 class="row__title"><?= h($row['title']) ?></h2></header>
      <?php endif; ?>
      <div class="vpromo">
        <div class="vpromo__video">
          <?php if ($ig !== ''): ?>
            <p class="vpromo__label">From Instagram</p>
            <div class="vpromo__embed vpromo__embed--instagram">
              <iframe
                src="https://www.instagram.com/p/<?= h($ig) ?>/embed/"
                title="Instagram post"
                allow="encrypted-media"
                loading="lazy"
                scrolling="no"
                referrerpolicy="strict-origin-when-cross-origin"></iframe>
            </div>
          <?php elseif ($vid !== ''): ?>
            <p class="vpromo__label">Featured video</p>
            <div class="vpromo__embed vpromo__embed--<?= h($aspect) ?>">
              <iframe
                src="https://www.youtube.com/embed/<?= h($vid) ?>?rel=0&modestbranding=1"
                title="<?= h($row['title'] ?: 'Featured video') ?>"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                allowfullscreen
                loading="lazy"
                referrerpolicy="strict-origin-when-cross-origin"></iframe>
            </div>
          <?php endif; ?>
          <?php /* Guitardle rides stacked under the featured video, BOTH
                   audiences (Ian 6/12: launch WITH guitardle — promo + modal +
                   always-5-slot board; anon plays its own phrase track). */
                if ($row_id === 'video-promo-members' || $row_id === 'video-promo-public') {
                    $gdle_compact = true;
                    require __DIR__ . '/_gdle-promo.php';
                } ?>
        </div>
        <div class="vpromo__copy">
          <?= $html /* trusted: sanitized by dash on save; shortcodes expanded above */ ?>
        </div>
      </div>
    </section>
<?php video_promo_end: ?>

<?php elseif ($layout === 'guitardle'): ?>
    <section class="row row--guitardle" data-row-id="<?= h($row_id) ?>">
      <header class="row__head">
        <h2 class="row__title"><?= h($row['title'] ?: 'Guitardle') ?></h2>
        <span class="row__subtitle">the daily guitar phrase game</span>
      </header>
      <?php /* Body = the shared promo partial (full three-column shape). The
               front page currently mounts Guitardle INSIDE the What's-New
               container instead (Ian 6/12) — this row stays renderable for
               when a standalone placement returns. */ ?>
      <?php $gdle_compact = false; require __DIR__ . '/_gdle-promo.php'; ?>
    </section>

<?php else: /* default rail */
    require_once __DIR__ . '/_render-card.php';
    $viewer_tier = $GLOBALS['LG_VIEWER_TIER'] ?? 'public';
    $items = $row['items'] ?? [];
    $rail_limit = (int)($row['limit'] ?? $row['query']['limit'] ?? count($items));
    $more_eligible = $layout === 'rail' && !empty($row_id) && count($items) >= $rail_limit && $rail_limit > 0;
?>
    <section class="row row--<?= h($layout) ?>" data-row-id="<?= h($row_id) ?>">
      <header class="row__head">
        <h2 class="row__title"><?= h($row['title']) ?></h2>
      </header>
      <div class="rail">
<?php foreach ($items as $it): ?>
<?= archive_poc_render_rcard($it, $viewer_tier) ?>
<?php endforeach; ?>
<?php if ($more_eligible): ?>
        <button type="button" class="rail-more"
                data-row-id="<?= h($row_id) ?>"
                data-offset="<?= (int)count($items) ?>"
                aria-label="Show more">→</button>
<?php endif; ?>
      </div>
      <script type="application/ld+json"><?= row_jsonld($row) ?></script>
    </section>
<?php endif; ?>

