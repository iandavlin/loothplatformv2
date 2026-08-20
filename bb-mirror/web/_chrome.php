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
 * Backlog 38 — see platform/config/hub-author-banner-swap.php for the full
 * trace (the Advanced Search modal's in-place author-pick apply never updates
 * the green author banner). Declared at TOP LEVEL (not inside
 * bb_mirror_chrome_footer()) and early in this file so it is registered the
 * moment _chrome.php is required — _feed.php's own author-header markup
 * (rendered well before chrome_footer() is ever CALLED) needs the same
 * answer chrome_footer uses to emit window.LG_HUB_AUTHOR_BANNER_SWAP, and a
 * function-local var from inside chrome_footer() cannot reach it (that was
 * the first cut of this fix — it read `true` in the footer's own script tag
 * while _feed.php's markup, rendered earlier in the response, saw the flag
 * as unset and never built the wrapper the swap needs. Caught by curling the
 * fixed preview and finding the wrapper div simply absent).
 */
if (!function_exists('lg_hub_author_banner_swap_enabled')) {
    function lg_hub_author_banner_swap_enabled(): bool
    {
        // NOT memoized with `static`: PHP-FPM workers are long-lived and
        // reuse the same process across many requests, so a static cache
        // here would stick the FIRST request's answer to every later one
        // that worker happens to serve. Harmless in real production (the
        // box has one flag state, changed rarely, via a deploy that
        // recycles workers anyway) but a real risk against a lane preview,
        // which exists specifically to serve ON and OFF side by side on the
        // SAME pool — worth avoiding even though it was NOT what caused the
        // one red run investigated here (that was the tracked default
        // itself being flipped mid-session; see hub-author-banner-swap.php).
        $raw = @include __DIR__ . '/../../platform/config/hub-author-banner-swap.php';
        $on = is_array($raw) && !empty($raw['enabled']);
        // Box-local override, the FLAGS.md shape: tracked default first, the
        // gitignored .local.php wins only on an explicit enabled === true.
        // This (not FPM pool env) is the one-truth dev2-ON mechanism — the
        // runtime, wp-cli and the gates all read the same file.
        $loc = @include __DIR__ . '/../../platform/config/hub-author-banner-swap.local.php';
        if (is_array($loc) && array_key_exists('enabled', $loc)) $on = ($loc['enabled'] === true);
        foreach ([getenv('LG_HUB_AUTHOR_BANNER_SWAP'), $_SERVER['LG_HUB_AUTHOR_BANNER_SWAP'] ?? false] as $o) {
            if ($o !== false && $o !== '') $on = ($o === '1' || $o === 'true');
        }
        return $on;
    }
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
    // The explicit exclusions come from config.php so the topic-edit PUT can enforce
    // the SAME list on the WP pool (LG_BB_MIRROR_NONPOSTABLE_FORUM_IDS). They were a
    // literal here, which made this list "what we offer" rather than "what is
    // allowed" — the edit endpoint happily moved posts into one of them.
    $excluded = implode(',', array_map('intval', LG_BB_MIRROR_NONPOSTABLE_FORUM_IDS));
    $forums = $db->query("
        SELECT f.id, f.slug, f.title, f.parent_forum_id, f.menu_order,
               p.title AS parent_title
          FROM forum f
          LEFT JOIN forum p ON p.id = f.parent_forum_id
         WHERE f.visibility = 'public' AND f.status = 'open' AND f.forum_type = 'forum'
           AND f.id NOT IN ($excluded)
           AND f.id NOT IN (SELECT parent_forum_id FROM forum WHERE parent_forum_id IS NOT NULL)
         ORDER BY (f.parent_forum_id IS NULL), COALESCE(f.parent_forum_id, f.id), f.menu_order ASC
    ")->fetchAll();
    // ^ parentless leaves sort LAST as one contiguous block (GH #26): the old
    //   COALESCE(pid, f.id) key scattered them between the parent groups, and the
    //   render loop below prints a category header on every pid CHANGE — so each
    //   scattered parentless forum minted its own "General" header ("GENERAL
    //   listed twice" in the picker). Contiguous ⇒ exactly one General group.

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
    // Sign-in link for the ntm/frm ANON panels carries the reader back to the page
    // they tried to post from (anon-gate lane 2026-07-27). Before this it was a bare
    // /wp-login.php, so a logged-out desktop reader who clicked Reply, signed in, and
    // landed on /activity/ had to find the discussion again by hand. The F1 fix
    // (lg-login-redirect-honor, 9ab8fcd) is what makes redirect_to actually survive
    // BuddyBoss's forced-login destination stomp, so this now lands.
    //
    // Only a same-host ABSOLUTE PATH is ever emitted: must start with a single '/'
    // (a leading '//' is protocol-relative = off-host), else fall back to bare login.
    // The server-side validator re-checks same-host regardless; this is belt and braces.
    $lg_return = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $login_url = (isset($lg_return[0]) && $lg_return[0] === '/' && strncmp($lg_return, '//', 2) !== 0)
        ? '/wp-login.php?redirect_to=' . rawurlencode($lg_return)
        : '/wp-login.php';
    ?>
<div class="ntm-overlay" id="ntm-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="ntm-heading">
  <div class="ntm-backdrop" id="ntm-backdrop"></div>
  <div class="ntm-dialog">
    <h2 class="ntm-heading" id="ntm-heading">New post</h2>
<?php /* ── TYPE TOGGLE (Ian 2026-08-09) ────────────────────────────────────────
     "part of the compose package in the new post in the hub… toggle between
     discussion and loothprint… two different forms."

     IT SITS ABOVE THE STEP RAIL BECAUSE THE RAIL IS ONE OF THE THINGS IT CHANGES:
     a discussion has four steps (Where/Write/Photos/Review), a Loothprint has none
     — Ian ruled the single screen — and a Loothprint has no forum to pick. A
     control that changes the steps cannot live inside them.

     RENDERED ONLY WHEN BOTH HALVES AGREE. lg_frontend_compose_enabled() reads the
     SAME platform/config/frontend-compose.php the WordPress form reads, so this
     cannot render a toggle whose iframe 404s. With the flag off nothing is emitted
     at all — not hidden, absent — which is what keeps OFF a byte-identical no-op.
     The can-post test calls lg_bb_mirror_can_post() DIRECTLY rather than reading
     $lg_can_post. That variable is assigned at FILE scope further down (~:678) and
     this markup lives inside bb_mirror_new_topic_modal(), so it is simply not in
     scope here — the condition was silently false and the toggle never rendered,
     with nothing in the HTML to say why. Same shape as the recorded wp eval-file
     function-scope trap: the value exists, just not where it is being read. */ ?>
<?php if (function_exists('lg_bb_mirror_can_post') && lg_bb_mirror_can_post()
          && function_exists('lg_frontend_compose_enabled') && lg_frontend_compose_enabled()): ?>
    <div class="ntm-typetoggle" id="ntm-typetoggle" role="tablist" aria-label="What are you posting?"
         data-compose-base="<?= htmlspecialchars(LG_FC_COMPOSE_BASE) ?>">
      <button type="button" class="ntm-typetoggle__opt is-on" data-ntm-type="discussion"
              role="tab" aria-selected="true">Discussion</button>
      <button type="button" class="ntm-typetoggle__opt" data-ntm-type="loothprint"
              role="tab" aria-selected="false">Loothprint</button>
    </div>
    <?php /* NO EMBEDDED FRAME. Ian 2026-08-15: the Loothprint form must not share
             this modal. Tapping Loothprint navigates to the standalone /compose/
             page (forums.js §type-toggle) — which removes the stacked-furniture
             race (ntmSetState('authed') re-showing the discussion wizard under
             the frame) and the signed-out-embed class in one move, rather than
             tidying a surface that should not be here. */ ?>
<?php endif; ?>

    <div class="ntm-state ntm-state--loading" id="ntm-loading" hidden>
      Loading…
    </div>

    <div class="ntm-state ntm-state--anon" id="ntm-anon" hidden>
      <p class="ntm-anon__msg">Sign in to post to the forums.</p>
      <a class="ntm-anon__link" href="<?= htmlspecialchars($login_url) ?>">Sign in</a>
    </div>

<?php
    /* ── #129 categorize-last: is the Where step gone for this request? ──────────
       BOTH conditions, deliberately. lg_ccl_default_forum_ok() asks Postgres whether
       the configured landing forum is actually a postable row in the MIRROR, because
       a default forum WordPress knows about and the mirror does not is worse than no
       feature: the picker, the hub's forum reads and the postable contract all read
       Postgres, so posts would be filed at a forum row that does not exist. Measured
       8/19: dev2's new "Discussions" (73564) is in WordPress and NOT in the mirror.
       Failing back to the Where step is the loud, harmless answer. */
    $ccl = function_exists('lg_ccl_enabled') && lg_ccl_enabled()
        && function_exists('lg_ccl_default_forum_ok') && lg_ccl_default_forum_ok();

    /* Where a new discussion lands. Context is honoured: composing from inside a
       postable forum still posts THERE, which is today's behaviour and is not what
       Ian ruled away — he removed the required CHOICE, not the context. Anywhere
       else (the hub, a category page, a search) it is the configured default. */
    $ccl_landing = 0; $ccl_landing_title = ''; $ccl_landing_slug = '';
    if ($ccl) {
        $ccl_by_id = [];
        foreach ($forums as $f) { $ccl_by_id[(int)$f['id']] = $f; }
        $ccl_landing = ($current_forum_id && isset($ccl_by_id[$current_forum_id]))
            ? $current_forum_id
            : lg_ccl_default_forum_id();
        if (isset($ccl_by_id[$ccl_landing])) {
            $ccl_landing_title = (string)$ccl_by_id[$ccl_landing]['title'];
            $ccl_landing_slug  = (string)$ccl_by_id[$ccl_landing]['slug'];
        } else {
            /* The default is postable per lg_ccl_default_forum_ok() but absent from
               $forums — it is on the explicit non-postable list, or the two queries
               disagree. Do not guess: keep the Where step. */
            $ccl = false; $ccl_landing = 0;
        }
    }
?>
    <form class="ntm-form" id="ntm-form" novalidate hidden autocomplete="off"
          data-rest-base="<?= htmlspecialchars($rest_base) ?>"
          data-current-forum="<?= $current_forum_id ?>"
<?php if ($ccl): ?>
          data-ccl="1"
          data-ccl-landing="<?= (int)$ccl_landing ?>"
          data-ccl-landing-title="<?= htmlspecialchars($ccl_landing_title) ?>"
<?php endif; ?>
          data-public-path="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH) ?>">

<?php if ($ccl): ?>
      <!-- #129 categorize-last: the Where step is GONE. This is not a deletion but a
           PRE-MADE CHOICE — the same #ntm-forum radiogroup contract with exactly one
           checked leaf, hidden. Everything that reads a forum out of this composer
           keeps working untouched: ntmGetForum() in forums.js, the wizard's review
           row, and hub-polish.js's own pre-submit check
           (`if (!wForum.querySelector('input[name=forum_id]:checked'))`) which would
           otherwise refuse every mobile post forever. Deleting the element would have
           meant editing three readers to agree about its absence; satisfying the
           contract means editing none of them. -->
      <div class="ntm-forumlist" id="ntm-forum" role="radiogroup" aria-label="Forum" hidden>
        <label class="ntm-fl__leaf">
          <input type="radio" name="forum_id" value="<?= (int)$ccl_landing ?>"
                 data-slug="<?= htmlspecialchars($ccl_landing_slug) ?>" checked>
          <span class="ntm-fl__title"><?= htmlspecialchars($ccl_landing_title) ?></span>
        </label>
      </div>
<?php else: ?>
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
<?php endif; ?>

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

<?php if ($ccl): ?>
      <!-- ── #129: THE TAG STEP (Ian ruled Option C, 8/19: "add in the tags and maybe
               popping up a new modal with a decent heirarchical layout") ────────────
           SERVER-RENDERED ONCE, USED BY BOTH SHAPES on purpose. The desktop wizard
           MOVES this node into its Topics pane the same way it moves the title and
           editor; the mobile flat form shows it exactly where it sits. One piece of
           markup means the tag field cannot drift between the two composers — which
           is the failure this codebase already has a scar from, when a hidden native
           picker and hub-polish's own chip picker disagreed about how many forums you
           were posting to.
           Terms are NOT inlined here: the picker fetches them on intent from
           /wp-json/lg-ccl/v1/topics the first time Add topics is tapped. Anon never
           pays for the list, and the hub pool has no WordPress to read it with. -->
      <div class="lgt" id="ntm-topics" data-endpoint="/wp-json/lg-ccl/v1/topics"
           data-landing-title="<?= htmlspecialchars($ccl_landing_title) ?>">
        <div class="lgt__head">
          <span class="lgt__h">Add topics</span>
          <span class="lgt__bonus">★ bonus points</span>
        </div>
        <p class="lgt__say">Optional — your post is ready either way. Tags help the
          right people trip over it later.</p>
        <div class="tagf tagf--empty" id="ntm-topics-field">
          <p class="tagf__hint">No topics yet — that&rsquo;s fine.</p>
          <button type="button" class="tagf__add" id="ntm-topics-add">&#65291; Add topics</button>
        </div>
        <div class="lgt__lands" id="ntm-topics-lands">
          <span>Lands in</span>
          <b id="ntm-topics-forum"><?= htmlspecialchars($ccl_landing_title) ?></b>
          <span class="ar">&larr;</span>
          <span class="lgt__why" id="ntm-topics-why">the default. Add a topic and it moves itself.</span>
        </div>
        <!-- Slugs the member picked, comma-separated. Read by the submit handler,
             which sends them to /wp-json/lg-ccl/v1/apply after the topic exists. -->
        <input type="hidden" id="ntm-topics-input" value="">
      </div>
<?php endif; ?>
      <!-- Post anonymously (anon-rebuild lane): per-post toggle. Sends _lg_anon
           with the topic write; the post renders as "Anonymous" to members
           (admins/mods still see the real author). Shared markup — Buck's mobile
           composer reads #ntm-form; announce any shape change to buck-COORD. -->
      <label class="ntm-anon" for="ntm-anon-check">
        <input type="checkbox" class="ntm-anon__check" id="ntm-anon-check" name="_lg_anon" value="1">
        <span class="ntm-anon__tx">Post anonymously
          <span class="ntm-anon__hint">— your name &amp; avatar are hidden from other members</span></span>
      </label>

<?php /* ── RULING 6: the post→follow controls ────────────────────────────────────
           Ian, 2026-08-08 (re-amended): both surfaces carry BOTH controls, with
           🔔 Notifications TICKED and ✉ Emails PRESENT BUT UNTICKED.

           ⚠️ GATED AT BUILD TIME, not hidden with CSS. The markup is absent entirely
           when the flag is off, so OFF is a true no-op rather than a node a stray rule
           could reveal — the same reason the reader modal builds its follow pair
           conditionally (forums.js:4985).

           Labels and sublabels are lifted verbatim from the follow modal
           (forums.js fmRow) and Manage Account so the platform speaks ONE language
           about these two bits. Real checkboxes, not switch divs: "ticked by default"
           is ruling 6's own word, and a checkbox is the control that says it to a
           keyboard and a screen reader without extra ARIA.

           ✉ unticked is a CONSENT position, not a style choice — see gate 18. */ ?>
      <?php if (function_exists('lg_post_follow_enabled') && lg_post_follow_enabled()): ?>
      <fieldset class="pf-strip" id="ntm-pf">
        <legend class="pf-strip__lg">Follow discussion</legend>
        <label class="pf-row">
          <input type="checkbox" class="pf-row__cb" id="ntm-pf-notify" checked>
          <span class="pf-row__tx">Notifications<small>A bell row for new replies</small></span>
        </label>
        <label class="pf-row">
          <input type="checkbox" class="pf-row__cb" id="ntm-pf-email">
          <span class="pf-row__tx">Emails<small>Email me about new replies</small></span>
        </label>
      </fieldset>
      <?php endif; ?>

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
<?php /* ── RULING 6: the post→follow controls ────────────────────────────────────
           Ian, 2026-08-08 (re-amended): both surfaces carry BOTH controls, with
           🔔 Notifications TICKED and ✉ Emails PRESENT BUT UNTICKED.

           ⚠️ GATED AT BUILD TIME, not hidden with CSS. The markup is absent entirely
           when the flag is off, so OFF is a true no-op rather than a node a stray rule
           could reveal — the same reason the reader modal builds its follow pair
           conditionally (forums.js:4985).

           Labels and sublabels are lifted verbatim from the follow modal
           (forums.js fmRow) and Manage Account so the platform speaks ONE language
           about these two bits. Real checkboxes, not switch divs: "ticked by default"
           is ruling 6's own word, and a checkbox is the control that says it to a
           keyboard and a screen reader without extra ARIA.

           ✉ unticked is a CONSENT position, not a style choice — see gate 18. */ ?>
      <?php if (function_exists('lg_post_follow_enabled') && lg_post_follow_enabled()): ?>
      <fieldset class="pf-strip" id="frm-pf">
        <legend class="pf-strip__lg">Follow discussion</legend>
        <label class="pf-row">
          <input type="checkbox" class="pf-row__cb" id="frm-pf-notify" checked>
          <span class="pf-row__tx">Notifications<small>A bell row for new replies</small></span>
        </label>
        <label class="pf-row">
          <input type="checkbox" class="pf-row__cb" id="frm-pf-email">
          <span class="pf-row__tx">Emails<small>Email me about new replies</small></span>
        </label>
      </fieldset>
      <?php endif; ?>

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

/**
 * $canonical_path — emit a SELF-REFERENCING <link rel=canonical> + og:url for
 * pages that have one canonical address (hub-seo-landing lane, Ian 2026-08-09).
 *
 * WHY, and it is not boilerplate. forums.js §4f rewrites the address bar to
 * /hub/?topic=<forum>/<topic> the moment the discussion modal is up, and live
 * robots.txt carries `Disallow: /hub/?` — VERIFIED on live, not assumed. So the
 * form a reader copies out of the address bar and shares is a URL Google is
 * FORBIDDEN to fetch. Serving the right content at the permalink is not enough
 * on its own: without a canonical, nothing tells Google that the permalink owns
 * that content, and the sitemap's promise is left arguing with the address bar.
 * Ian caught this by eye on the flipped serve — the content and the layout were
 * both already correct, which is exactly why no existing assertion saw it.
 *
 * ABSOLUTE and built from LG_BB_MIRROR_HOST, the single request-derived host
 * source this app already uses (config.php sanitises it), so the tag names this
 * page on the host that actually served it — self-referencing on dev2, on live,
 * and under any preview mount, with no per-env edit.
 *
 * Pass null (the default) and nothing is emitted, so every page that has NOT
 * decided what its canonical address is stays byte-identical rather than
 * asserting a wrong one. A wrong canonical is worse than none: it actively tells
 * Google to consolidate on the wrong URL.
 */
function bb_mirror_chrome_header(string $page_title = 'The Hub', ?string $canonical_path = null, string $og_type = 'article'): void
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
<?php if ($canonical_path !== null && $canonical_path !== ''):
        $lg_canon = 'https://' . LG_BB_MIRROR_HOST . '/' . ltrim($canonical_path, '/');
        $lg_canon = htmlspecialchars($lg_canon, ENT_QUOTES, 'UTF-8'); ?>
<link rel="canonical" href="<?= $lg_canon ?>">
<meta property="og:url" content="<?= $lg_canon ?>">
<meta property="og:type" content="<?= htmlspecialchars($og_type, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:title" content="<?= $title ?>">
<?php endif; ?>
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
<?php
/* data-lg-can-post — the EXPLICIT server can-post signal for client-built composers.
 *
 * WHY THIS EXISTS (anon-gate lane 2026-07-27): every composer on the Hub is built
 * CLIENT-SIDE by hub-polish.js, which is served identically to everyone. The only
 * thing that ever gated posting was the server refusing to render composer markup
 * (lg_bb_mirror_can_post below) — a gate that cannot cover a composer the client
 * constructs from scratch. Anon viewers still receive the reply AFFORDANCES
 * (feed_action_bar() in _feed.php emits .lg-act-replies unconditionally), so a
 * logged-out tap reached a real composer shell. This attribute is the one thing the
 * client can synchronously read to know the answer.
 *
 * ONE SOURCE OF TRUTH: lg_bb_mirror_can_post() (_reply-render.php) — the SAME
 * predicate that decides whether reply markup is rendered at all. Deliberately NOT
 * /whoami: that would hide the post UI from logged-in members whose profile isn't
 * bridged yet (whoami→anon despite a valid WP session). See the note on the function.
 *
 * THIS IS A UX LAYER, NOT THE LOCK. The server still renders no composer markup to
 * anon, BuddyBoss REST still 401s anonymous writes, and the anon contact/mention
 * scrub is untouched. A forged attribute at most opens a composer that fails on submit.
 */
$lg_can_post = function_exists('lg_bb_mirror_can_post')
    ? lg_bb_mirror_can_post()
    : lg_bb_mirror_wp_logged_in();   // same cookie rule; _reply-render.php not loaded on every page
?>
<body class="bb-mirror<?= !empty($GLOBALS['__bb_hub_rail']) ? ' hub-fmodal-page' : '' ?>" data-lg-can-post="<?= $lg_can_post ? '1' : '0' ?>" data-lg-follow="<?= (function_exists('lg_thread_follow_enabled') && lg_thread_follow_enabled()) ? '1' : '0' ?>" data-lg-post-follow="<?= (function_exists('lg_post_follow_enabled') && lg_post_follow_enabled()) ? '1' : '0' ?>"<?= (function_exists('lg_notif_quickreply_enabled') && lg_notif_quickreply_enabled()) ? ' data-lg-notifreply="1"' : '' ?>><?php /* PREVIEW-ONLY NOTE. Rendered only when the app is mounted under a lane
         preview path (LG_BB_MIRROR_PUBLIC_PATH is overridden by the preview
         nginx conf; on /hub/ this is exactly "/hub" and nothing renders). It
         exists so Ian does not report a KNOWN GAP as a defect -- he picked
         variant A from a mock that had a frequency segment, and this build has
         none. Self-removing: it cannot appear on the real Hub or on live. */ ?>
<?php /* ⚠️ SCOPED TO ITS OWN LANE, 2026-08-11 (frontend-compose). This banner used
         to render on ANY /preview/ mount, so every other lane's preview announced
         itself as "the thread-follow branch" — I hit it on mine and it is exactly
         the recorded "scope preview banners to their own lane path" trap. The
         mount now has to match. */ ?>
<?php if (defined('LG_BB_MIRROR_PUBLIC_PATH') && strpos(LG_BB_MIRROR_PUBLIC_PATH, '/preview/thread-follow/') === 0): ?>
<div style="background:#fdf6e9;border-bottom:1px solid #e8cfa8;padding:10px 16px;font:500 13.5px/1.5 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#4a4d4a">
  <strong>Preview of the thread-follow branch.</strong> The follow controls are switched ON here only &mdash; the real Hub is unchanged. <strong>Things to try:</strong> the <em>Follow</em> pill in each card's top-right corner (desktop); tap it to open the settings modal and check it names the discussion; on a phone, the replies count now sits with the reactions and is no longer a button.
  <br><strong>Not a bug:</strong> there is no <em>Frequency</em> row (Instant/Daily/Weekly) in the modal. It was in the mock you picked from, but nothing sends those digests yet, so shipping the control would have been a setting that quietly does nothing. It goes in when the sending side exists.
</div>
<?php endif; ?>
<?php if (defined('LG_BB_MIRROR_PUBLIC_PATH') && strpos(LG_BB_MIRROR_PUBLIC_PATH, '/preview/frontend-compose/') === 0): ?>
<div style="background:#fdf6e9;border-bottom:1px solid #e8cfa8;padding:10px 16px;font:500 13.5px/1.5 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#4a4d4a">
  <strong>Preview of the frontend-compose branch.</strong> The compose form is switched ON here only &mdash; the real Hub is unchanged and <em>/compose/</em> still 404s. <strong>Things to try:</strong> the <em>Discussion / Loothprint</em> toggle at the top of this composer; switching to Loothprint swaps in the real form (photos, print files, licence pre-answered), and switching back restores the discussion wizard untouched.
  <br><strong>Not a bug:</strong> the Loothprint side has no step rail &mdash; you ruled the single screen, and a Loothprint has no forum to pick. It scrolls inside the dialog; that length is the thing to judge.
</div>
<?php endif; ?>
<?php /* data-lg-post-follow: ruling 6's post→follow controls crossing into the client, by
     the same seam and for the same reason. Its source is the SHARED tracked config
     (platform/config/post-follow.php) that the WordPress write-half also reads, so the
     rendered control and the write it triggers cannot disagree about whether the
     feature is on. Read via lg_post_follow_enabled() (_reply-render.php) — the one
     read point; nothing else may test it.

     data-lg-follow: the thread-follow exposure gate crossing into the client. The desktop reader modal and the mobile sheet build their follow toggles in JS, so the server-side gate alone cannot reach them; this is how the ONE read point (lg_thread_follow_enabled(), _reply-render.php) governs those surfaces too. Same seam as data-lg-can-post. */ ?>
<?php /* Hub feed: filters live in a CENTERED MODAL (Ian 2026-06-11), not the
         side rail — so the hub emits no nav aside, no hamburger, no drawer
         backdrop, and needs no pre-paint nav-closed state. Forum subpages
         keep the classic left nav + hamburger below. */ ?>
<?php /* A GOOGLE DOOR ADDS NO MEMBER NAV (Ian 2026-08-12, ruling 7). Both
         branches below are member navigation: the legacy category tree, and the
         hub rail that the door's own category filter would otherwise bring with
         it. A door suppresses BOTH — that is the whole distinction between
         "rebuilt in the hub look" and "turned into another hub". */ ?>
<?php $lg_door = !empty($GLOBALS['__bb_hub_door']); ?>
<?php if (!$lg_door && empty($GLOBALS['__bb_hub_rail'])): ?>
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

<?php if (!$lg_door && !empty($GLOBALS['__bb_hub_rail']) && function_exists('hub_render_rail')): ?>
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
<?php
/* Backlog 3.7 — the mobile reader sheet renders embeds. hub-polish.js is a static
   docroot overlay and cannot read PHP, so the bit is emitted here.

   EMITTED ONLY WHEN ON, never as `= false`: with the flag off this block writes
   nothing at all, so the served page is byte-for-byte what it is today and the
   client guard reads undefined. A `= false` would be a behavioural no-op but not a
   byte-identical one. See platform/config/sheet-embeds.php. */
$lg_se = @include __DIR__ . '/../../platform/config/sheet-embeds.php';
$lg_se_on = is_array($lg_se) && !empty($lg_se['enabled']);
foreach ([getenv('LG_SHEET_EMBEDS'), $_SERVER['LG_SHEET_EMBEDS'] ?? false] as $lg_se_o) {
    if ($lg_se_o !== false && $lg_se_o !== '') $lg_se_on = ($lg_se_o === '1' || $lg_se_o === 'true');
}
if ($lg_se_on): ?>
<script>window.LG_SHEET_EMBEDS = true;</script>
<?php endif;
/* Backlog 3.8 — the mobile/PWA "← Hub" sticky pill (Ian ruled option D, 8/9).
   Built inside webroot/bottom-nav.js, which is a static docroot layer and cannot
   read PHP, so the bit is emitted here.

   EMITTED ONLY WHEN ON, never as `= false`: flag off writes nothing at all, so the
   served page is byte-for-byte unchanged and the client guard reads undefined.
   See platform/config/back-pill.php. */
$lg_bp = @include __DIR__ . '/../../platform/config/back-pill.php';
$lg_bp_on = is_array($lg_bp) && !empty($lg_bp['enabled']);
/* Per-box override, gitignored (the compose/guitardle one-truth pattern,
   8/15-16): dev2 runs the pill ON via back-pill.local.php while the tracked
   default stays false, so a live pull cannot switch it on unverified. Sits
   BEFORE the env loop so a gate forcing a state via LG_BACK_PILL still wins. */
$lg_bp_local = @include __DIR__ . '/../../platform/config/back-pill.local.php';
if (is_array($lg_bp_local) && array_key_exists('enabled', $lg_bp_local)) {
    $lg_bp_on = ($lg_bp_local['enabled'] === true);
}
foreach ([getenv('LG_BACK_PILL'), $_SERVER['LG_BACK_PILL'] ?? false] as $lg_bp_o) {
    if ($lg_bp_o !== false && $lg_bp_o !== '') $lg_bp_on = ($lg_bp_o === '1' || $lg_bp_o === 'true');
}
if ($lg_bp_on): ?>
<script>window.LG_BACK_PILL = true;</script>
<?php endif;
/* Backlog 38 — forums.js reads this global to decide whether fmodalApply
   extends its existing chip-bar-style swap to the author banner too.
   lg_hub_author_banner_swap_enabled() is declared near the top of this file
   (function_exists-guarded) so _feed.php's own markup, rendered earlier in
   the same response, can call the identical answer.

   EMITTED ONLY WHEN ON, never as `= false`: flag off writes nothing at all, so
   the served page is byte-for-byte unchanged and the client guard reads
   undefined. */
if (lg_hub_author_banner_swap_enabled()): ?>
<script>window.LG_HUB_AUTHOR_BANNER_SWAP = true;</script>
<?php endif;
/* The comma-splitting author-filter defect (see _hub-filters.php's
   hub_author_delim()) — hub-filters.js's addAuthor() reads this global to
   join with the SAME delimiter the server will split on. function_exists-
   guarded: bb_mirror_chrome_footer() is shared by every bb-mirror page, not
   just the Hub, and _hub-filters.php is only required on Hub-shaped pages.

   EMITTED ONLY WHEN ON, never as `= false`: flag off writes nothing at all,
   so the served page is byte-for-byte unchanged and the client guard reads
   undefined (the JS falls back to its own hardcoded ',' — see hub-filters.js). */
if (function_exists('hub_author_delim') && hub_author_delim() !== ','): ?>
<script>window.LG_HUB_AUTHOR_COMMA_FIX = true;</script>
<?php endif; ?>
<script src="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH) ?>/forums.js?v=<?= bb_mirror_asset_ver('forums.js') ?>" defer></script>
<!-- Hub toolbar type-ahead: live search + author autocomplete (forums/_suggest.php). -->
<script src="<?= htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH) ?>/hub-filters.js?v=<?= bb_mirror_asset_ver('hub-filters.js') ?>" defer></script>
</body>
</html>
<?php
}
