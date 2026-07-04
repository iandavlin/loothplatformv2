<?php
/**
 * bb-mirror chrome — header + footer wrappers + left nav.
 *
 * bb_mirror_chrome_header():
 *   Outputs doctype → <body open> → shared site header → searchbar →
 *   .bb-layout with left nav open. Template content goes in the content pane.
 *
 * bb_mirror_chrome_footer():
 *   Closes .bb-layout, emits shared site footer, closes <body>.
 *
 * Shared header from /srv/lg-shared/site-header.php (P3 partial).
 * Shared CSS at /lg-shared/site-header.css linked in <head>.
 *
 * Viewer state comes from lg_bb_mirror_whoami() — same loopback pattern
 * as archive-poc, defined in bb-mirror config.php.
 */

declare(strict_types=1);

/**
 * Cache-buster for static assets: filemtime so edits invalidate the browser
 * cache automatically. Falls back to a constant if the file can't be stat'd.
 */
function bb_mirror_asset_ver(string $filename): string
{
    $path = __DIR__ . '/' . $filename;
    $mt = @filemtime($path);
    return $mt ? (string)$mt : '1';
}

/**
 * Map a top-level forum slug to a category color key.
 */
function bb_mirror_cat_key(?string $parent_slug, ?string $own_slug = null): string
{
    $slug = $parent_slug ?? $own_slug ?? '';
    if ($slug === '') return 'general';

    if (str_contains($slug, 'acoustic'))                                            return 'acoustic';
    if (str_contains($slug, 'build') || str_contains($slug, 'construction'))        return 'builds';
    if (str_contains($slug, 'repair') || str_contains($slug, 'restoration'))       return 'repair';
    if (str_contains($slug, 'tool'))                                                return 'tools';
    if (str_contains($slug, 'business') || str_contains($slug, 'professional'))    return 'business';
    if (str_contains($slug, 'market') || str_contains($slug, 'buy')
        || str_contains($slug, 'sell') || str_contains($slug, 'classif'))          return 'market';
    if (str_contains($slug, 'sponsor'))                                             return 'sponsors';
    if (str_contains($slug, 'looth') && $slug !== 'looth-group-partners')          return 'looths';
    if (str_contains($slug, 'suggestion') || str_contains($slug, 'bug-report'))    return 'suggestions';

    return 'general';
}

/**
 * Build a map of forum_id → category key for all public forums.
 */
function bb_mirror_build_cat_map(array $rows): array
{
    $slugs   = [];
    $parents = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $slugs[$id]   = (string)$r['slug'];
        $parents[$id] = $r['parent_forum_id'] !== null ? (int)$r['parent_forum_id'] : null;
    }

    $map = [];
    foreach ($rows as $r) {
        $id        = (int)$r['id'];
        $parent_id = $parents[$id];
        if ($parent_id === null) {
            $map[$id] = bb_mirror_cat_key(null, $slugs[$id]);
        } else {
            $parent_slug = $slugs[$parent_id] ?? $slugs[$id];
            $map[$id] = bb_mirror_cat_key($parent_slug, $slugs[$id]);
        }
    }
    return $map;
}

function bb_mirror_left_nav(): void
{
    $db   = bb_mirror_db();
    $rows = $db->query("
        SELECT id, slug, title, parent_forum_id, menu_order, forum_type
          FROM forum
         WHERE visibility = 'public' AND status IN ('open','closed')
           AND id NOT IN (67251, 3876)
         ORDER BY parent_forum_id NULLS FIRST, menu_order ASC
    ")->fetchAll();

    $children = [];
    $top      = [];
    foreach ($rows as $r) {
        if ($r['parent_forum_id'] === null) $top[] = $r;
        else $children[(int)$r['parent_forum_id']][] = $r;
    }

    $containers = [];
    $general    = [];
    $sponsors   = [];
    $local      = [];
    $solo       = [];   // standalone top-level forums that get their own pill (no group)
    foreach ($top as $t) {
        $kids       = $children[(int)$t['id']] ?? [];
        $slug       = (string)$t['slug'];
        $is_local   = str_contains($slug, 'looth') && $slug !== 'looth-group-partners';
        $is_sponsor = ((int)$t['id'] === 34044 || str_contains($slug, 'sponsor'));
        $is_solo    = str_contains($slug, 'suggestion') || str_contains($slug, 'bug-report');
        if ($kids || $t['forum_type'] === 'category') {
            $containers[] = ['parent' => $t, 'kids' => $kids];
        } elseif ($is_solo) {
            $solo[] = $t;        // e.g. Suggestion Box / Bug Reporting — own pill, not in General
        } elseif ($is_local) {
            $local[] = $t;
        } elseif ($is_sponsor) {
            $sponsors[] = $t;
        } else {
            $general[] = $t;
        }
    }

    $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $prefix = LG_BB_MIRROR_PUBLIC_PATH;
    $rel    = ltrim(str_starts_with($uri, $prefix) ? substr($uri, strlen($prefix)) : $uri, '/');
    $segs   = array_values(array_filter(explode('/', $rel)));
    $active = $segs[0] ?? '';

    $active_forum_id = null;
    if (count($segs) === 2) {
        $dis = $db->prepare(
            "SELECT t.forum_id FROM forums.topic t
               JOIN forums.forum f ON f.id = t.forum_id
              WHERE f.slug = ? AND t.slug = ?
                AND t.status = 'publish'
              LIMIT 1"
        );
        $dis->execute([$segs[0], $segs[1]]);
        $drow = $dis->fetch();
        if ($drow) $active_forum_id = (int)$drow['forum_id'];
    }

    $root_href   = htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/');
    $root_active = ($active === '');
    ?>
    <nav class="nav-tree" aria-label="Forum navigation">

      <a class="nav-tree__root <?= $root_active ? 'nav-tree__root--active' : '' ?>"
         href="<?= $root_href ?>">
        <span class="nav-tree__root-icon" aria-hidden="true">&#9776;</span>
        <span class="nav-tree__root-label">All activity</span>
      </a>

      <?php
      $render_link = function (array $f, string $extra_class = '') use ($active, $active_forum_id): void {
          $href    = htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/' . $f['slug'] . '/');
          $is_act  = $active_forum_id !== null
              ? ((int)$f['id'] === $active_forum_id)
              : ($active === $f['slug']);
          $classes = 'nav-tree__item nav-tree__pill ' . $extra_class . ($is_act ? ' nav-tree__item--active' : '');
          echo '<a class="' . trim($classes) . '" href="' . $href . '">'
             . htmlspecialchars($f['title'])
             . '</a>' . "\n";
      };
      // true if the active forum is one of these leaves (so its section opens on load)
      $leaves_active = function (array $list) use ($active, &$active_forum_id): bool {
          foreach ($list as $f) {
              if ($active === (string)$f['slug']
                  || ($active_forum_id !== null && (int)$f['id'] === $active_forum_id)) return true;
          }
          return false;
      };
      ?>

      <?php foreach ($containers as $c):
          $cat_key  = bb_mirror_cat_key(null, (string)$c['parent']['slug']);
          $cat_href = htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/' . $c['parent']['slug'] . '/');

          // Open if active forum is this category or any of its children
          $sec_active = false;
          if ($active === (string)$c['parent']['slug']
              || ($active_forum_id !== null && (int)$c['parent']['id'] === $active_forum_id)) {
              $sec_active = true;
          } else {
              foreach ($c['kids'] as $kid) {
                  if ($active === (string)$kid['slug']
                      || ($active_forum_id !== null && (int)$kid['id'] === $active_forum_id)) {
                      $sec_active = true; break;
                  }
              }
          }
      ?>
        <div class="nav-tree__section nav-section--<?= $cat_key ?><?= $sec_active ? ' nav-tree__section--open' : '' ?>">
          <div class="nav-tree__cat-pill nav-section--<?= $cat_key ?>">
            <a class="nav-tree__cat-name" href="<?= $cat_href ?>"><?= htmlspecialchars($c['parent']['title']) ?></a>
            <button class="nav-tree__section-toggle" type="button"
                    aria-expanded="<?= $sec_active ? 'true' : 'false' ?>"
                    aria-label="Toggle <?= htmlspecialchars($c['parent']['title'], ENT_QUOTES) ?> sub-forums">
              <span class="nav-tree__chevron" aria-hidden="true">&#9656;</span>
            </button>
          </div>
          <div class="nav-tree__section-body">
            <?php foreach ($c['kids'] as $kid) $render_link($kid, 'nav-tree__item--child nav-section--' . $cat_key); ?>
          </div>
        </div>
      <?php endforeach; ?>

      <?php
      // Virtual groups (no single parent forum) — same collapsible pill, no "View all".
      $render_group = function (string $cat_key, string $title, array $list) use ($render_link, $leaves_active): void {
          $open = $leaves_active($list);
      ?>
        <div class="nav-tree__section nav-section--<?= $cat_key ?><?= $open ? ' nav-tree__section--open' : '' ?>">
          <button class="nav-tree__section-toggle nav-tree__cat-pill nav-section--<?= $cat_key ?>" type="button"
                  aria-expanded="<?= $open ? 'true' : 'false' ?>">
            <span class="nav-tree__cat-name"><?= htmlspecialchars($title) ?></span>
            <span class="nav-tree__chevron" aria-hidden="true">&#9656;</span>
          </button>
          <div class="nav-tree__section-body">
            <?php foreach ($list as $f) $render_link($f, 'nav-tree__item--child nav-section--' . $cat_key); ?>
          </div>
        </div>
      <?php
      };
      if ($general)  $render_group('general',  'General',      $general);
      if ($sponsors) $render_group('sponsors', 'Sponsors',     $sponsors);
      if ($local)    $render_group('looths',   'Local Looths', $local);

      // Standalone forums (e.g. Suggestion Box) — their own navigable pill, no group.
      foreach ($solo as $sf):
          $sk    = bb_mirror_cat_key(null, (string)$sf['slug']);
          $shref = htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/' . $sf['slug'] . '/');
          $sact  = ($active === (string)$sf['slug']
                    || ($active_forum_id !== null && (int)$sf['id'] === $active_forum_id));
      ?>
        <div class="nav-tree__section nav-section--<?= $sk ?>">
          <a class="nav-tree__cat-pill nav-tree__cat-pill--solo nav-section--<?= $sk ?><?= $sact ? ' nav-tree__cat-pill--active' : '' ?>"
             href="<?= $shref ?>"><span class="nav-tree__cat-name"><?= htmlspecialchars($sf['title']) ?></span></a>
        </div>
      <?php endforeach; ?>

    </nav>
    <?php
}

function bb_mirror_new_topic_modal(): void
{
    $db = bb_mirror_db();

    // Postable LEAF forums for the <select>. Excludes category containers AND any
    // forum that has children (the placeholder parents that just hold subforums) —
    // you post to a subforum, never to its container.
    $forums = $db->query("
        SELECT f.id, f.slug, f.title, f.parent_forum_id, f.menu_order,
               p.title AS parent_title
          FROM forum f
          LEFT JOIN forum p ON p.id = f.parent_forum_id
         WHERE f.visibility = 'public' AND f.status = 'open' AND f.forum_type = 'forum'
           AND f.id NOT IN (67251, 3876)
           AND f.id NOT IN (SELECT parent_forum_id FROM forum WHERE parent_forum_id IS NOT NULL)
         ORDER BY COALESCE(f.parent_forum_id, f.id), f.menu_order ASC
    ")->fetchAll();

    // Detect currently-scoped forum from URL (same logic as nav active highlight)
    $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $prefix = LG_BB_MIRROR_PUBLIC_PATH;
    $rel    = ltrim(str_starts_with($uri, $prefix) ? substr($uri, strlen($prefix)) : $uri, '/');
    $segs   = array_values(array_filter(explode('/', $rel)));
    $active_slug = count($segs) === 1 ? $segs[0] : '';  // only pre-select on 1-segment forum feeds

    $uri_fid = null;
    if (preg_match('/[?&]fid=(\d+)/', $_SERVER['REQUEST_URI'] ?? '', $m)) {
        $uri_fid = (int)$m[1];
    }

    $current_forum_id = 0;
    if ($uri_fid !== null) {
        $current_forum_id = $uri_fid;
    } elseif ($active_slug !== '') {
        foreach ($forums as $f) {
            if ($f['slug'] === $active_slug) { $current_forum_id = (int)$f['id']; break; }
        }
    }

    // Same-origin (relative) so the composer's media/upload + reply fetches hit the
    // CURRENT host — inherently cross-origin-safe, no host to get wrong. (Browser
    // resolves these against the page origin, which is what we want on dev / dev2 /
    // loothgroup.com alike.) LG_BB_MIRROR_HOST is now request-derived (config.php) so
    // the loopback/server side self-resolves too; relative stays as the cleanest
    // browser-side form. Was a band-aid for the dev2 cross-origin upload CORS block.
    $rest_base = '/wp-json/buddyboss/v1';
    $login_url = '/wp-login.php';
    ?>
<div class="ntm-overlay" id="ntm-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="ntm-heading">
  <div class="ntm-backdrop" id="ntm-backdrop"></div>
  <div class="ntm-dialog">
    <h2 class="ntm-heading" id="ntm-heading">New post</h2>

    <div class="ntm-state ntm-state--loading" id="ntm-loading" hidden>
      Loading…
    </div>

    <div class="ntm-state ntm-state--anon" id="ntm-anon" hidden>
      <p class="ntm-anon__msg">Sign in to post to the forums.</p>
      <a class="ntm-anon__link" href="<?= htmlspecialchars($login_url) ?>">Sign in</a>
    </div>

    <form class="ntm-form" id="ntm-form" novalidate hidden autocomplete="off"
          data-rest-base="<?= htmlspecialchars($rest_base) ?>"
          data-current-forum="<?= $current_forum_id ?>"
          data-public-path="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH) ?>">

      <span class="ntm-label" id="ntm-forum-label">Forum <span class="ntm-label__opt">(pick one)</span></span>
      <!-- Single-select forum list: category headers + leaf radio rows. Replaces the
           native <select> whose optgroup labels read as a second pickable level. The
           category headers are plainly not pickable; exactly one leaf radio can be on. -->
      <div class="ntm-forumlist" id="ntm-forum" role="radiogroup" aria-labelledby="ntm-forum-label">
        <?php
        $cur_group_pid = false;
        foreach ($forums as $f):
            $pid = $f['parent_forum_id'] !== null ? (int)$f['parent_forum_id'] : null;
            if ($pid !== $cur_group_pid) {
                $label = $pid !== null ? htmlspecialchars((string)$f['parent_title']) : 'General';
                echo '<div class="ntm-fl__cat">' . $label . '</div>' . "\n";
                $cur_group_pid = $pid;
            }
            $chk = ((int)$f['id'] === $current_forum_id) ? ' checked' : '';
            echo '<label class="ntm-fl__leaf">'
               . '<input type="radio" name="forum_id" value="' . (int)$f['id'] . '"'
               . ' data-slug="' . htmlspecialchars($f['slug']) . '" required' . $chk . '>'
               . '<span class="ntm-fl__title">' . htmlspecialchars($f['title']) . '</span>'
               . '</label>' . "\n";
        endforeach;
        ?>
      </div>

      <label class="ntm-label" for="ntm-title-in">Title</label>
      <input class="ntm-input" id="ntm-title-in" name="title" type="text" autocomplete="off"
             required placeholder="What's this about?">

      <label class="ntm-label">Body <span class="ntm-label__opt">(optional — formatting, images & links)</span></label>
      <!-- Quill mounts here; falls back to the plain textarea if Quill fails to load -->
      <div class="ntm-editor" id="ntm-editor"></div>
      <textarea class="ntm-textarea ntm-textarea--fallback" id="ntm-content" name="content" rows="6"
                autocomplete="off" placeholder="Share details, ask a question…" hidden></textarea>
      <p class="ntm-paste-hint">Tip: paste a YouTube, Vimeo, or Instagram link on its own line to embed it.</p>

      <label class="ntm-label" for="ntm-tags">Tags <span class="ntm-label__opt">(optional, comma-separated)</span></label>
      <input class="ntm-input" id="ntm-tags" name="topic_tags" type="text"
             placeholder="e.g. neck reset, fret press, martin d18" autocomplete="off">
      <!-- Quick-add workflow tags: toggle the named tag in/out of #ntm-tags.
           These mirror FluentForms Form 38's Council/Weekly checkboxes (the LIVE-only
           anon+tag flow). See docs/hub-anon-and-workflow-tags-FORM38.md. NB: anon
           posting is NOT in this composer — it only exists via Form 38 on live. -->
      <div class="ntm-quicktags" id="ntm-quicktags">
        <button type="button" class="ntm-qtag" data-tag="councilyes">+ councilyes</button>
        <button type="button" class="ntm-qtag" data-tag="weeklyyes">+ weeklyyes</button>
      </div>

      <!-- Post anonymously (anon-rebuild lane): per-post toggle. Sends _lg_anon
           with the topic write; the post renders as "Anonymous" to members
           (admins/mods still see the real author). Shared markup — Buck's mobile
           composer reads #ntm-form; announce any shape change to buck-COORD. -->
      <label class="ntm-anon" for="ntm-anon-check">
        <input type="checkbox" class="ntm-anon__check" id="ntm-anon-check" name="_lg_anon" value="1">
        <span class="ntm-anon__tx">Post anonymously
          <span class="ntm-anon__hint">— your name &amp; avatar are hidden from other members</span></span>
      </label>

      <div class="ntm-row">
        <button type="submit" class="ntm-submit" id="ntm-submit">Post</button>
        <button type="button" class="ntm-cancel" id="ntm-cancel">Cancel</button>
        <span class="ntm-status" id="ntm-status" aria-live="polite"></span>
      </div>
    </form>
  </div>
</div>

<!-- Feed reply modal — opened by a card's "Reply" button (see forums.js §4b). -->
<div class="ntm-overlay" id="frm-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="frm-heading">
  <div class="ntm-backdrop" id="frm-backdrop"></div>
  <div class="ntm-dialog">
    <h2 class="ntm-heading" id="frm-heading">Reply</h2>
    <p class="frm-context" id="frm-context" hidden>Replying to <span class="frm-context__title"></span></p>

    <div class="ntm-state ntm-state--loading" id="frm-loading" hidden>Loading…</div>

    <div class="ntm-state ntm-state--anon" id="frm-anon" hidden>
      <p class="ntm-anon__msg">Sign in to reply.</p>
      <a class="ntm-anon__link" href="<?= htmlspecialchars($login_url) ?>">Sign in</a>
    </div>

    <form class="ntm-form" id="frm-form" novalidate hidden autocomplete="off"
          data-rest-base="<?= htmlspecialchars($rest_base) ?>">
      <input type="hidden" id="frm-topic-id" name="topic_id" value="">
      <input type="hidden" id="frm-forum-id" name="forum_id" value="">
      <!-- Title row — shown ONLY when editing a TOPIC/OP (lgFrmEditTopic), so the
           same composer doubles as the OP editor; hidden for replies. -->
      <div class="frm-title-wrap" id="frm-title-wrap" hidden>
        <label class="ntm-label" for="frm-title">Title</label>
        <input class="ntm-input" id="frm-title" name="title" type="text" maxlength="200"
               placeholder="Post title" autocomplete="off">
      </div>
      <label class="ntm-label" id="frm-body-label">Your reply <span class="ntm-label__opt">(formatting, images &amp; links)</span></label>
      <!-- Quill mounts here (same editor as the new-topic modal); falls back to the textarea -->
      <div class="ntm-editor" id="frm-editor"></div>
      <textarea class="ntm-textarea ntm-textarea--fallback" id="frm-content" name="content" rows="5"
                autocomplete="off" placeholder="Share your thoughts…" hidden></textarea>
      <p class="ntm-paste-hint">Tip: paste a YouTube, Vimeo, or Instagram link on its own line to embed it.</p>
      <?php /* Anonymous toggle REMOVED from replies 2026-06-10 (Ian: "we don't
               want anon replies. Just anon posts.") — anon stays on the
               new-TOPIC composer only. forums.js guards on the checkbox's
               existence, so no _lg_anon ever rides a reply now; the API door
               is closed server-side too (reply.php). */ ?>
      <div class="ntm-row">
        <button type="submit" class="ntm-submit" id="frm-submit">Post reply</button>
        <button type="button" class="ntm-cancel" id="frm-cancel">Cancel</button>
        <span class="ntm-status" id="frm-status" aria-live="polite"></span>
      </div>
    </form>
  </div>
</div>

<!-- Content comment modal — opened by a Hub content card's comment button.
     The iframe loads the WP-free read endpoint (archive-poc/api/v0/comments.php,
     ~30ms, no WP boot); that page handles its own composer + posts its content
     height back via postMessage. See forums.js §4c. -->
<div class="lgc-modal" id="lgc-modal" role="dialog" aria-modal="true" aria-label="Comments" hidden>
  <div class="lgc-modal__backdrop" data-lgc-close></div>
  <div class="lgc-modal__panel">
    <div class="lgc-modal__head">
      <span class="lgc-modal__title">Comments</span>
      <button type="button" class="lgc-modal__close" data-lgc-close aria-label="Close">&times;</button>
    </div>
    <iframe class="lgc-modal__frame" id="lgc-modal-frame" title="Comments"></iframe>
  </div>
</div>
    <?php
}

/**
 * Viewer assembly — inline-verify fast path + whoami fallback.
 * design-shim-replacement.md §4 Step A. Both builders return the SAME shape so
 * bb_mirror_chrome_header() is source-agnostic. Defined here (not config.php)
 * because config.php is bb-mirror-owned; lg_bb_mirror_whoami() is already loaded
 * from config.php before this file runs.
 */
if (!function_exists('lg_bb_mirror_viewer_from_whoami')) {
function lg_bb_mirror_viewer_from_whoami(): array {
    // Existing loopback (lg_bb_mirror_whoami in config.php), normalized to the
    // shared shape. Retired in Step B once looth_id is universal — NOT this turn.
    $w = lg_bb_mirror_whoami();
    return [
        'authenticated' => ($w['authenticated'] ?? false) === true,
        'user_uuid'     => $w['user_uuid'] ?? null,
        'wp_user_id'    => $w['wp_user_id'] ?? null,
        'slug'          => $w['slug'] ?? null,
        'display_name'  => (string)($w['display_name'] ?? ''),
        'avatar_url'    => $w['avatar_url'] ?? null,
        'tier'          => (string)($w['tier'] ?? 'public'),
        'capabilities'  => (array)($w['capabilities'] ?? []),
    ];
}
}

function bb_mirror_chrome_header(string $page_title = 'The Hub'): void
{
    require_once '/srv/lg-shared/site-header.php';

    // Inline-verify fast path (design §4 Step A): verify looth_id locally with
    // the RS256 public key — no WP-boot loopback. Fall back to the whoami shim
    // when the cookie is absent/invalid so nothing breaks mid-rollout. The
    // is_readable guard keeps bb-mirror working even before the helper deploys.
    // Header identity comes from /whoami — the single source of truth for
    // display_name / avatar_url / tier / capabilities (header convergence Step 1;
    // contract: docs/relay-header-convergence.md). The looth_id JWT is still
    // verified, but ONLY as the identity anchor (sub) — never as a display or
    // tier source (that was the bug: slug + lg_tier cookie instead of real name/photo).
    $verify_helper = '/srv/lg-shared/jwt-verify.php';
    $anchor_sub = null;
    if (is_readable($verify_helper)) {
        require_once $verify_helper;
        if (function_exists('lg_shared_verify_looth_id')) {
            $claims = lg_shared_verify_looth_id($_COOKIE['looth_id'] ?? null);
            if ($claims !== null) $anchor_sub = $claims['sub'] ?? null;
        }
    }
    $viewer = lg_bb_mirror_viewer_from_whoami();
    if ($anchor_sub && empty($viewer['user_uuid'])) $viewer['user_uuid'] = $anchor_sub;
    $authed = $viewer['authenticated'];
    $tier   = (string)$viewer['tier'];
    $caps   = (array)$viewer['capabilities'];
    $dname  = (string)$viewer['display_name'];
    $avatar = $viewer['avatar_url'] ?? null;
    $slug   = $viewer['slug'] ?? null;

    if ($authed && $dname === '') {
        foreach ($_COOKIE as $name => $val) {
            if (str_starts_with($name, 'wordpress_logged_in_')) {
                $parts = explode('|', urldecode($val), 4);
                if (!empty($parts[0])) { $dname = $parts[0]; break; }
            }
        }
    }

    $logo_url = 'https://' . LG_BB_MIRROR_HOST . '/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png';

    $title = htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8');
    ?><!doctype html>
<html lang="en">
<head>
<?php /* legacy hub-theme + compact pre-paint appliers REMOVED 2026-06-10
         (bespoke-cutover two-mode pare-back): color is Light/Dark via the gear
         (applied pre-paint by the nginx boot script); compact view retired. */ ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $title ?> — Looth Group</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php /* Google Fonts CSS INLINED (perf lane 2026-06-11): the css2 <link> for
         Lora+Cabin plus the Source Serif Pro @import formerly at the top of
         forums.css — together ~930ms of render-blocking CDN round trips for
         <15KB of @font-face rules (cascade-position-independent, so relocating
         them is a visual no-op). Binaries still stream from fonts.gstatic.com
         (preconnect above). See web/_fonts-inline.css header for refresh steps. */ ?>
<style><?php @readfile(__DIR__ . '/_fonts-inline.css'); ?></style>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<?php /* Quill toolbar CSS loads ASYNC (perf lane 2026-06-11): Quill only initializes
         lazily when a composer opens (forums.js, with a plain-textarea fallback), so
         blocking first paint on a CDN stylesheet cost ~770ms on mobile Lighthouse.
         media-swap keeps the element's cascade position; print never matches first. */ ?>
<?php if (lg_bb_mirror_wp_logged_in()): /* editor assets gate on the WP login session, NOT /whoami (Ian: posting=WP-login). A logged-in member whose whoami resolves anon still gets the real composer; true anon gets none (craft gate, Ian 6/12). */ ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css"></noscript>
<?php endif; ?>
<link rel="stylesheet" href="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH) ?>/forums.css?v=<?= bb_mirror_asset_ver('forums.css') ?>">
<?php /* Mobile presentation layer (Buck) — flat-card → FB app-card via grid-template-areas.
         MUST be a media-gated <head> <link> so it paints on first load (deferring it via
         pwa.js re-introduces the flash). Behaviors-only mobile-hub.js may defer. */ ?>
<link rel="stylesheet" href="/mobile-hub.css?v=<?= @filemtime('/var/www/dev/mobile-hub.css') ?: '1' ?>" media="(max-width:640px)">
</head>
<body class="bb-mirror<?= !empty($GLOBALS['__bb_hub_rail']) ? ' hub-fmodal-page' : '' ?>">
<?php /* Hub feed: filters live in a CENTERED MODAL (Ian 2026-06-11), not the
         side rail — so the hub emits no nav aside, no hamburger, no drawer
         backdrop, and needs no pre-paint nav-closed state. Forum subpages
         keep the classic left nav + hamburger below. */ ?>
<?php if (empty($GLOBALS['__bb_hub_rail'])): ?>
<!-- Fixed triangle-corner hamburger (top-left, always on top) -->
<button class="corner-hamburger" id="bb-ham"
        aria-label="Toggle navigation" aria-expanded="true">
  <span class="corner-hamburger__icon" aria-hidden="true">&#9776;</span>
</button>

<!-- Mobile drawer backdrop -->
<div class="nav-overlay" id="bb-overlay" aria-hidden="true"></div>
<?php endif; ?>

<?php
    lg_shared_render_site_header([
        'authenticated'      => $authed,
        'active_nav'         => 'hub',     // light the The Hub nav item (§0a; key coordinated w/ lg-shell)
        'tier'               => $tier,
        'display_name'       => $dname,
        'avatar_url'         => $avatar,   // verbatim from /whoami (matches /archive + /u); browser holds the gate cookie so the d= bp-full photo loads
        'capabilities'       => $caps,
        'msg_unread'         => null,
        'notif_unread'       => null,
        'logo_url'           => $logo_url,
        'profile_url'        => $slug ? '/u/' . rawurlencode((string)$slug) : '/profile/edit',
    ]);
?>

<?php if (!empty($GLOBALS['__bb_hub_rail']) && function_exists('hub_render_rail')): ?>
<?php /* Centered filters modal (Ian 2026-06-11): the rail content — Categories
         AND Types both visible — in a dialog the sort-bar "Filters" chip opens.
         Server-rendered, link-driven (zero-JS filtering still round-trips);
         forums.js only opens/closes the shell. All viewports. */ ?>
<div class="hub-fmodal" id="hub-fmodal" hidden role="dialog" aria-modal="true" aria-label="Advanced Search">
  <div class="hub-fmodal__back" data-hub-fmodal-close></div>
  <div class="hub-fmodal__panel" tabindex="-1">
    <header class="hub-fmodal__head">
      <h2 class="hub-fmodal__title">Advanced Search</h2>
      <p class="hub-fmodal__help">Search the Hub or by author, or tap a filter to narrow the feed.</p>
      <button type="button" class="hub-fmodal__x" data-hub-fmodal-close aria-label="Close">&times;</button>
    </header>
    <div class="hub-fmodal__body">
      <?php
        $__r = $GLOBALS['__bb_hub_rail'];
        // Active-filter chips (hub-mobile-search lane, 2026-06-25): surface the SAME
        // persistent, individually-clearable chip bar here, ABOVE the search inputs,
        // so on mobile the modal is the one place for ALL filters (query + author +
        // category). Distinct outer class 'hub-fmodal__chips' (NOT .hub-chipbar) keeps
        // fmodalApply's feed-bar swap from being hijacked. The per-chip × / "Reset all"
        // are server <a href> handled by the existing forums.js a[href]->fmodalApply
        // path; the modal body innerHTML-swap keeps them fresh — no forums.js change.
        // CSS shows this only on mobile (forums.css hides it >=641); the feed chipbar
        // is the desktop surface (unchanged).
        if (function_exists('hub_render_chipbar')) {
          hub_render_chipbar($__r['filters'], $__r['muted'] ?? ['types' => [], 'cats' => []], $__r['sort'] ?? 'new', $__r['leaf_labels'] ?? [], $__r['tree'] ?? [], 'hub-fmodal__chips');
        }
        // Advanced Search (Ian 2026-06-20): the two search bars are EXPOSED at the
        // top (always visible); filters live in the accordions below (Shows folded in).
        if (function_exists('hub_render_toolbar_search')) {
          echo '<div class="hub-fmodal__search">';
          hub_render_toolbar_search($__r['filters'], $__r['sort'] ?? 'new');
          echo '</div>';
        }
        hub_render_rail($__r['facets'], $__r['filters'], $__r['muted'] ?? ['types' => [], 'cats' => []], $__r['sort'] ?? 'new', $__r['tree'] ?? [], $__r['shows'] ?? []);
      ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="bb-layout">
  <?php if (empty($GLOBALS['__bb_hub_rail'])): ?>
  <aside class="bb-layout__nav" id="bb-nav">
    <button type="button" class="bb-nav__close" data-lg-nav-close aria-label="Close filters" title="Close filters">&times;</button>
    <?php bb_mirror_left_nav(); ?>

    <nav class="bb-mirror__searchbar bb-mirror__searchbar--sidebar" aria-label="Forum search">
      <form class="search-form search-form--sidebar" method="get" action="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH . '/') ?>">
        <label class="search-form__label" for="q">Search forums</label>
        <input class="search-form__input" id="q" name="q" type="search"
               placeholder="Search topics + replies…"
               value="<?= htmlspecialchars((string)($_GET['q'] ?? '')) ?>"
               autocomplete="off">
        <button class="search-form__btn" type="submit" aria-label="Search">&#9906;</button>
      </form>
    </nav>
  </aside>
  <?php endif; ?>
  <main class="bb-layout__content bb-mirror__main" id="lg-main">
<?php
}

function bb_mirror_chrome_footer(): void
{
    require_once '/srv/lg-shared/site-footer.php';

    $logo_url = 'https://' . LG_BB_MIRROR_HOST . '/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png';
    ?>
  </main><!-- .bb-layout__content -->
</div><!-- .bb-layout -->

<?php lg_shared_render_site_footer(['logo_url' => $logo_url]); ?>

<?php bb_mirror_new_topic_modal(); ?>

<?php if (lg_bb_mirror_wp_logged_in()): /* WP-login session, not /whoami — see CSS gate above */ ?>
<?php /* Quill loads AFTER first paint settles (load+idle) — members only. By
         composer tap-time it's been ready for seconds, so Buck's synchronous
         tap-focus iOS keyboard path is untouched; forums.js already has the
         plain-textarea fallback if a tap somehow beats the idle load.
         Anon never loads it (no composer exists). Ian 6/12. */ ?>
<script>
(function(){var go=function(){(window.requestIdleCallback||function(f){setTimeout(f,600)})(function(){
  var s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js';document.head.appendChild(s);});};
if(document.readyState==='complete')go();else window.addEventListener('load',go,{once:true});})();
</script>
<?php endif; ?>
<!-- Single source of the forum base path for forums.js (self-links, lazy fetches). -->
<script>window.LG_FORUM_BASE = <?= json_encode(LG_BB_MIRROR_PUBLIC_PATH) ?>;</script>
<script src="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH) ?>/forums.js?v=<?= bb_mirror_asset_ver('forums.js') ?>" defer></script>
<!-- Hub toolbar type-ahead: live search + author autocomplete (forums/_suggest.php). -->
<script src="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH) ?>/hub-filters.js?v=<?= bb_mirror_asset_ver('hub-filters.js') ?>" defer></script>
</body>
</html>
<?php
}
