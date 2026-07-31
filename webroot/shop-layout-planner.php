<?php
/**
 * /shop-layout-planner/ — standalone front controller for the Luthier Shop
 * Layout Planner. Ian picked layout "B" (landing page carrying Looth chrome)
 * on 2026-07-31 over the tool-first alternative.
 *
 * WHY THIS FILE EXISTS. The URL is LIVE and takes organic Google traffic, and it
 * was rendering through `twentytwentyfive` — the stock WordPress theme (Ian:
 * "it's rendering with the wordpress theme"). Nothing on this platform renders
 * from a theme. This controller serves the same URL, with the same copy, in our
 * own chrome.
 *
 * WHAT IT DELIBERATELY DOES NOT DO:
 *   - It does not touch WP page 68840 (or the superseded 63845). Those rows stay
 *     exactly as they are; nginx simply routes the slug here before WordPress
 *     ever sees it.
 *   - It does not reimplement the planner. The markup comes from the plugin's own
 *     partial and the ads/gating come from the plugin's own classes, so the two
 *     render paths cannot drift.
 *
 * ROLLBACK IS ONE LINE: pull the nginx location and the slug falls straight back
 * to the WordPress page render. That is why 68840 must stay published.
 *
 * DEGRADED MODE IS A FEATURE, NOT AN OVERSIGHT. The planner is static markup plus
 * static assets, so it does not need WordPress at all. WordPress is booted only
 * for the three things that genuinely need it — logged-in state, the rotating
 * sponsor ads, and operator CSS. If wp-load cannot be found we still serve a
 * fully working planner rather than 500 a URL that earns traffic.
 */
declare(strict_types=1);

/* ─────────────────────────────────────────────────────────────
   1. WordPress — optional, and never fatal
   ───────────────────────────────────────────────────────────── */

// NOT __DIR__ for wp-load: this file is SYMLINKED into the docroot and __DIR__
// resolves through the symlink to the repo, where wp-load.php is not and never
// will be. lg-wp-load.php asks the request instead. (__DIR__ is correct for
// lg-wp-load.php itself — that file travels with this one inside the repo.)
$lg_wp_load = require __DIR__ . '/lg-wp-load.php';
$lg_has_wp  = false;
if ($lg_wp_load !== '') {
    // wp-load.php runs core + plugins (plugins_loaded, init) but NOT the query or
    // template phase, so BuddyBoss's members-only template_redirect never fires and
    // no theme is involved. Same reason webroot/loothalong.php can do this safely.
    require_once $lg_wp_load;
    $lg_has_wp = function_exists('is_user_logged_in');
}

$lg_logged_in = $lg_has_wp && is_user_logged_in();

// Mark the app queued so LGApps_Ads::render_ads() has something to render into;
// the enqueue side-effects are inert here because we never call wp_head/wp_footer
// (that is the point — calling them would drag the whole theme back in).
if ($lg_has_wp && class_exists('LGApps_Registry')) {
    LGApps_Registry::enqueue('shop-planner');
}

/* ─────────────────────────────────────────────────────────────
   2. Paths — resolved with WP when we have it, from the request when we don't
   ───────────────────────────────────────────────────────────── */

$lg_docroot    = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
$lg_plugin_dir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/lg-apps' : $lg_docroot . '/wp-content/plugins/lg-apps';
// ROOT-RELATIVE, deliberately. LGAPPS_URL is plugin_dir_url(), which returns an
// ABSOLUTE url built from WordPress's home_url — so the stylesheets would be
// requested from whatever host WP thinks it is, regardless of the host actually
// serving this page. On live that happens to be the same host; anywhere else (a
// preview origin, the real-origin proxy the gates use, a renamed vhost) the CSS
// silently fails to load and the page renders unstyled while still returning 200.
// Keeping only the PATH makes the assets same-origin by construction. Same bytes
// on live, testable everywhere else.
$lg_assets_url = defined('LGAPPS_URL')
    ? rtrim((string) (parse_url(LGAPPS_URL, PHP_URL_PATH) ?: '/wp-content/plugins/lg-apps/'), '/')
    : '/wp-content/plugins/lg-apps';

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$v = static fn(string $f): string => (string) (@filemtime($f) ?: '1');

$lg_self_css = __DIR__ . '/shop-layout-planner.css';

// Cache-bust each asset off its own mtime so a deploy cannot serve stale CSS.
$lg_asset = static function (string $rel) use ($lg_plugin_dir, $lg_assets_url, $v): string {
    return $lg_assets_url . $rel . '?v=' . $v($lg_plugin_dir . $rel);
};

/* ─────────────────────────────────────────────────────────────
   3. Head metadata
   ───────────────────────────────────────────────────────────── */

// The WP render emitted "<title>The Looth Group</title>" and NO meta description,
// because page 68840's post_title is empty — plainly an accident, not a choice.
// The strings below are the ones the page's own authoring comment specifies as
// the intended Title / Meta Desc / OG values, so this is applying stated intent
// rather than inventing new SEO copy.
$lg_title    = 'Luthier Shop Layout Planner — Free Workshop Floor Plan Tool';
$lg_desc     = 'Plan your luthier workshop with our free drag-and-drop shop layout tool. Place workbenches, tools, walls, doors & windows. Export to PDF. No signup required.';
$lg_og_title = 'Free Luthier Shop Layout Planner | Looth Group';
$lg_og_desc  = 'Drag-and-drop workshop floor plan tool built for luthiers. Place your workbench, band saw, spray booth — export as PDF.';

// Derive the canonical from the request host so dev2 never advertises a live URL.
$lg_host      = (string) ($_SERVER['HTTP_HOST'] ?? 'loothgroup.com');
$lg_canonical = 'https://' . $lg_host . '/shop-layout-planner/';

$lg_patreon = 'https://www.patreon.com/cw/theloothgroup/membership';

/* ─────────────────────────────────────────────────────────────
   4. Chrome context
   ───────────────────────────────────────────────────────────── */

$lg_ctx = ['authenticated' => $lg_logged_in, 'active_nav' => ''];
if ($lg_logged_in) {
    $u = wp_get_current_user();
    $lg_ctx['display_name'] = (string) ($u->display_name ?: $u->user_login);
    $lg_ctx['avatar_url']   = get_avatar_url($u->ID, ['size' => 96]) ?: null;
    $lg_ctx['logout_url']   = wp_logout_url(home_url('/shop-layout-planner/'));
}

$lg_header = '/srv/lg-shared/site-header.php';   // absolute on purpose — see the __DIR__ note above
$lg_footer = '/srv/lg-shared/site-footer.php';
if (is_readable($lg_header)) { require_once $lg_header; }
if (is_readable($lg_footer)) { require_once $lg_footer; }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($lg_title) ?></title>
<meta name="description" content="<?= $h($lg_desc) ?>">
<link rel="canonical" href="<?= $h($lg_canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $h($lg_og_title) ?>">
<meta property="og:description" content="<?= $h($lg_og_desc) ?>">
<meta property="og:url" content="<?= $h($lg_canonical) ?>">
<meta name="twitter:card" content="summary_large_image">
<script>
/* Pre-paint theme resolve. THE SIGNAL IS html[data-lguser-theme="dark"] — an
   attribute set from the app's own resolved theme, NOT a prefers-color-scheme
   media query (site-header.php:128). app-settings.js sets it, but it loads at the
   foot of the page, so without this the dark-mode user gets a white flash on every
   visit. Mirrors app-settings.js's own resolution order: explicit pick, else OS. */
(function () {
  try {
    var t = localStorage.getItem('lg-set-theme');
    if (t !== 'dark' && t !== 'default') {
      t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'default';
    }
    var r = document.documentElement;
    r.setAttribute('data-lguser-theme', t);
    r.setAttribute('data-lguser-dark', t === 'dark' ? '1' : '0');
  } catch (e) {}
})();
</script>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= $h($v('/srv/lg-shared/site-header.css')) ?>">
<link rel="stylesheet" href="<?= $h($lg_asset('/assets/css/lgapps-base.css')) ?>">
<link rel="stylesheet" href="<?= $h($lg_asset('/apps/shop-planner/assets/shop-planner.css')) ?>">
<link rel="stylesheet" href="/shop-layout-planner.css?v=<?= $h($v($lg_self_css)) ?>">
</head>
<body class="lg-shopplan-body">

<?php if (function_exists('lg_shared_render_site_header')) { lg_shared_render_site_header($lg_ctx); } ?>

<main id="lg-main" class="lg-shopplan">

  <header class="lg-shopplan__hero">
    <span class="lg-shopplan__badge">Free Tool</span>
    <h1 class="lg-shopplan__h1">Luthier Shop Layout Planner</h1>
    <p class="lg-shopplan__sub">Drag-and-drop workshop floor plan tool — built for instrument makers</p>
    <p class="lg-shopplan__intro">
      Whether you're setting up your first repair bench in a spare bedroom or designing a
      full production lutherie shop, getting the layout right makes everything easier.
      Place workbenches, tools, walls, doors, and windows to design your ideal luthier
      workshop — right in your browser, no software to install.
    </p>
  </header>

  <div class="lg-shopplan__cta">
    <p class="lg-shopplan__cta-text">
      Starts with a <strong>sample luthier workshop</strong> you can customize or clear and start from scratch.
    </p>
    <button type="button" class="lgapps-open-btn" onclick="window.lgapps_open('shop-planner')">
      Open the Shop Planner — It's Free
    </button>
  </div>

  <h2 class="lg-shopplan__h2">Built for Luthiers, By Luthiers</h2>
  <p class="lg-shopplan__p">
    Most floor plan tools are designed for kitchens and living rooms. This one was built by
    <a href="https://www.instagram.com/jroadman/" target="_blank" rel="noopener noreferrer">J. Roadman</a>
    specifically for instrument makers and repair shops. The starter layout includes a workbench,
    assembly table, band saw, drill press, router table, spray booth, and wood storage — all sized
    to real-world dimensions. Delete what you don't need, add what you do.
  </p>

  <h2 class="lg-shopplan__h2">What You Can Do</h2>
  <div class="lg-shopplan__cards">
    <div class="lg-shopplan__card">
      <h3>Design Your Room</h3>
      <p>Set custom dimensions in feet or metric with snap-to-grid precision. Add interior walls for finish rooms, tool alcoves, or spray areas.</p>
    </div>
    <div class="lg-shopplan__card">
      <h3>Place Equipment</h3>
      <p>Add rectangular or circular items with custom names and dimensions. Color-code by category — benches, power tools, storage, furniture.</p>
    </div>
    <div class="lg-shopplan__card">
      <h3>Architectural Details</h3>
      <p>Place doors with configurable swing direction, windows along any wall, and text labels to annotate your plan. Interior and exterior walls render distinctly.</p>
    </div>
    <div class="lg-shopplan__card">
      <h3>Save &amp; Export</h3>
      <p>Auto-saves in your browser between visits. Download as JSON for backup. Export a clean, print-ready PDF with custom header text.</p>
    </div>
  </div>

<?php if (!$lg_logged_in): /* the page's own [lgapps_logged_out] sponsor block — distinct from the rotating sidebar ads */ ?>
  <a class="lg-shopplan__ad"
     href="https://www.stewmac.com/kits-and-projects/electronic-kits/pedals-and-mod-kits/stewmac-ghost-drive-pedal-kit/"
     target="_blank" rel="noopener noreferrer sponsored">
    <img class="lg-shopplan__ad-img"
         src="/wp-content/uploads/2026/03/103733-ghost-drive-sq-crop.webp"
         alt="StewMac Ghost Drive Pedal Kit" width="300" height="300" loading="lazy" decoding="async">
    <span class="lg-shopplan__ad-body">
      <span class="lg-shopplan__ad-label">Sponsored</span>
      <span class="lg-shopplan__ad-title">StewMac Ghost Drive</span>
      <span class="lg-shopplan__ad-copy">Build the legendary Klon Centaur circuit yourself — real 1N34A germanium diodes, hand-wired, no epoxy required. A fun weekend project that sounds incredible.</span>
      <span class="lg-shopplan__ad-btn">Shop at StewMac →</span>
    </span>
  </a>
<?php endif; ?>

  <h2 class="lg-shopplan__h2">Why Luthier Workshop Layout Matters</h2>
  <p class="lg-shopplan__p">
    A well-planned lutherie shop isn't about having more space — it's about using what you have
    efficiently. Think about workflow: tonewood comes in the door, gets rough-cut near the entry,
    moves to the workbench for shaping, then to assembly, finishing, and finally setup. Your layout
    should follow that flow without backtracking.
  </p>
  <ul class="lg-shopplan__list">
    <li><strong>Table saw placement</strong> — needs clearance on all four sides for sheet goods and long stock. Position it near the entry so you can break down lumber as it comes in.</li>
    <li><strong>Workbench access</strong> — you need at least three sides clear. A bench shoved against a wall limits how you can clamp, plane, and work around a body or neck.</li>
    <li><strong>Finish area separation</strong> — dust is the enemy of finish work. Even a simple interior wall between your sanding area and spray booth makes a huge difference.</li>
    <li><strong>Wood storage</strong> — store planks and sheet goods vertically on edge to save floor space. Milled wood should be stickered on wall racks or overhead, not on the floor.</li>
    <li><strong>Lighting</strong> — plan bench positions relative to windows. Good natural light on your primary work surface improves your finishes dramatically.</li>
    <li><strong>Dust collection routing</strong> — if you're running a central collector, plan the ductwork paths before you commit to tool placement.</li>
  </ul>

  <h2 class="lg-shopplan__h2">Use the Luthier Shop Planner on Any Device</h2>
  <p class="lg-shopplan__p">
    The luthier shop layout planner runs entirely in your browser — nothing to download, no account
    required to start. Works on desktop, laptop, and tablet. Right-click and drag to pan, scroll to
    zoom, and use keyboard shortcuts (Ctrl+Z to undo, [ and ] to rotate) to work quickly.
  </p>

  <div class="lg-shopplan__cta">
    <p class="lg-shopplan__cta-text">Free to use. <strong>No signup required</strong> to start designing.</p>
    <button type="button" class="lgapps-open-btn" onclick="window.lgapps_open('shop-planner')">
      Start Planning Your Shop
    </button>
  </div>

  <h2 class="lg-shopplan__h2">Frequently Asked Questions</h2>
  <div class="lg-shopplan__faq">
    <h3>Is the luthier shop layout planner free?</h3>
    <p>Yes. The planner is free to use with no signup required. Some features like PDF export and file save/load are available to Looth Group members.</p>
    <hr>
    <h3>Do I need an account to use the shop planner?</h3>
    <p>No account is needed to design your layout. Creating a free Looth Group account lets you save your work as files and export PDFs.</p>
    <hr>
    <h3>Can I save my workshop layout and come back later?</h3>
    <p>Your layout automatically saves in your browser, so it's there when you return on the same device. For backup or transferring between devices, members can download their layout as a JSON file.</p>
    <hr>
    <h3>What size luthier shop can I plan?</h3>
    <p>Any size — from a 6×8 foot closet shop to a 40×60 foot production facility. Enter your dimensions in feet or metric and the planner scales to fit.</p>
    <hr>
    <h3>Can I design a multi-room lutherie workshop?</h3>
    <p>Yes. Use interior walls to divide your space — a common setup is a main workshop with a walled-off finish room and separate storage area.</p>
    <hr>
    <h3>Can I print my workshop floor plan?</h3>
    <p>Members can export their layout as a PDF with a custom header, sized for standard letter paper. The PDF includes all walls, doors, windows, items, labels, and dimensions.</p>
  </div>

  <div class="lg-shopplan__footer-cta">
    <p>
      The Luthier Shop Layout Planner is one of the free tools from the <a href="/">Looth Group</a> —
      an online community of over 1,500 luthiers sharing knowledge about instrument building, repair,
      and the craft of lutherie. <a href="<?= $h($lg_patreon) ?>" target="_blank" rel="noopener">Join us</a>
      to access the full suite of tools, our member directory, the Looth Group Live interview series, and more.
    </p>
  </div>

</main>

<?php if (function_exists('lg_shared_render_site_footer')) { lg_shared_render_site_footer($lg_ctx); } ?>

<?php
/* The planner modal itself — the plugin's own partial, so this render and the
   WordPress one emit the same bytes. */
if (!defined('LGAPPS_PARTIAL')) { define('LGAPPS_PARTIAL', 1); }

// TWO candidate paths, and the second is not paranoia — it is what lets this page
// deploy independently of the plugin.
//
// The docroot's wp-content/plugins/lg-apps is a REAL DIRECTORY, not a symlink into
// the serving checkout (measured on dev2 AND live, 2026-07-31), even though
// cutover/symlink-farm.sh lists lg-apps as a plugin it should link. That farm has a
// DRIFT GUARD which converts a real dir only while repo and docroot match exactly
// — so the very commit that adds this partial is the commit that makes the guard
// refuse. Chicken and egg.
//
// Candidate 2 breaks it: THIS FILE is symlinked from the repo, so __DIR__ resolves
// INTO the repo, where the partial always sits at the same commit as this
// controller. Same source, same bytes, no drift possible. The page therefore works
// whether or not the plugin dir has been converted yet, and the conversion can
// happen on its own schedule without ever leaving this URL broken.
$lg_partial = '';
foreach ([
    $lg_plugin_dir . '/apps/shop-planner/partials/planner-markup.php',
    __DIR__ . '/../lg-apps/apps/shop-planner/partials/planner-markup.php',
] as $cand) {
    if (is_readable($cand)) { $lg_partial = $cand; break; }
}
if ($lg_partial !== '') {
    require $lg_partial;
} else {
    // Never silently ship a page whose CTA opens nothing. The gate asserts the
    // canvas id, so this comment is a diagnostic, not a fallback.
    error_log('shop-layout-planner: planner partial not found in plugin dir or repo');
    echo "<!-- shop-planner: partial unavailable -->\n";
}
?>

<script>
/* Same shape lg-apps.php:99-106 emits on wp_footer. shop-planner.js:18 treats a
   missing object as "nothing gated", so the degraded path stays permissive rather
   than locking a free tool behind a prompt it cannot resolve. */
window.lgapps_gating = <?= json_encode([
    'logged_in'      => $lg_logged_in,
    'gated_features' => ($lg_has_wp && class_exists('LGApps_Admin'))
                          ? (LGApps_Admin::get_settings()['gated_features'] ?? [])
                          : [],
    'login_url'      => $lg_has_wp ? wp_login_url($lg_canonical) : '/wp-login.php',
    'register_url'   => $lg_has_wp ? wp_registration_url()       : '/wp-login.php?action=register',
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<?php
/* Rotating sponsor ads in the planner sidebar. This is REVENUE: dropping it would
   be invisible to any "does the page render" check, so it is called explicitly and
   asserted by tools/gates/shop-planner-url-gate.sh. */
if ($lg_has_wp && class_exists('LGApps_Ads')) {
    LGApps_Ads::render_ads();
    LGApps_Ads::render_custom_css();
}
?>

<?php /* jsPDF still comes from cdnjs, matching app.php:23-29 exactly — PDF export is
        advertised on this page and this is not the change to alter its delivery in.
        The integrity hash below was COMPUTED from the actual 364463-byte response
        (openssl dgst -sha512), not copied from documentation; a guessed hash would
        silently kill PDF export. Vendoring this file is still the right follow-up. */ ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"
        integrity="sha512-qZvrmS2ekKPF2mSznTQsxqPgnpkI4DNTlrdUmTzrDgektczlKNRRhy5X5AAOnx5S09ydFYWWNSfcEqDTTHgtNA=="
        crossorigin="anonymous" referrerpolicy="no-referrer" defer></script>
<script src="<?= $h($lg_asset('/apps/shop-planner/assets/shop-planner.js')) ?>" defer></script>
<script src="/app-settings.js?v=<?= $h($v($lg_docroot . '/app-settings.js')) ?>" defer></script>
</body>
</html>
