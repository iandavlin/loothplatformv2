<?php
/**
 * archive-poc/web/search.php — dedicated search/results page.
 *
 * Lives at /archive/. The discovery page (/archive-poc/) is the
 * editorial feed; this page is for active searching. The chrome search modal's
 * "See all" links land here with ?q=, ?kind=, ?author= pre-filled.
 */
declare(strict_types=1);
require __DIR__.'/../config.php';

// ---- Viewer state (same pattern as index.php) ---------------------------
$is_member = false;
foreach (array_keys($_COOKIE) as $name) {
    if (str_starts_with($name, 'wordpress_logged_in_')) { $is_member = true; break; }
}
// Tier from /whoami ONLY — never the forgeable lg_tier cookie (Buck 6/11
// paywall audit; anon fails closed to public, admin resolves pro — config.php).
$whoami = lg_archive_poc_whoami();
$viewer_tier = lg_archive_poc_viewer_tier($whoami);
if (!empty($whoami['authenticated'])) $is_member = true;
$edit_capable = ($whoami['capabilities']['edit_archive_poc'] ?? false) === true;
// ?as= QA preview: downgrades open; tier-raising requires the edit capability
// (anon ?as=pro was an entitlement bypass).
$preview_as = $_GET['as'] ?? null;
if ($preview_as === 'public') { $is_member = false; $viewer_tier = 'public'; $edit_capable = false; }
elseif ($preview_as === 'lite' && $edit_capable) { $is_member = true; $viewer_tier = 'lite'; }
elseif ($preview_as === 'pro'  && $edit_capable) { $is_member = true; $viewer_tier = 'pro'; }
$GLOBALS['LG_VIEWER_TIER']  = $viewer_tier;
$GLOBALS['LG_EDIT_CAPABLE'] = $edit_capable;

$init_q = trim($_GET['q'] ?? '');

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

$canonical = LG_ARCHIVE_POC_CANONICAL_BASE . '/archive/';
$LOGO = LG_ARCHIVE_POC_LOGO_URL;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $init_q ? h('"' . $init_q . '" — ') : '' ?>Search — Looth Group Archive</title>
<meta name="description" content="Search articles, videos, loothprints, events, and discussions from the Looth Group community.">
<meta name="robots" content="noindex, follow">
<link rel="canonical" href="<?= h($canonical) ?>">
<link rel="stylesheet" href="/archive-poc/archive.css?v=<?= @filemtime(__DIR__.'/archive.css') ?>">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
</head>
<body class="view-grid arc-search-page">
<?php $lg_active_nav = 'archive'; // on the Archive page — suppress its own nav link
require __DIR__ . '/_chrome.php'; ?>

<main class="arc-page" id="main">

  <!-- Plain, always-visible search bar — this is the dedicated search page, so
       the input lives on the page itself (not behind the header magnifier).
       archive.js binds #q for live, debounced results. #sort stays hidden
       (the rail's #sort-rail is the visible sort control). -->
  <form class="arc-searchbar" id="topbar" autocomplete="off" onsubmit="event.preventDefault(); return false">
    <svg class="arc-searchbar__icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input id="q" name="q" type="search" placeholder="Search articles, videos, discussions, people…" value="<?= h($init_q) ?>" autofocus aria-label="Search the archive">
    <select id="sort" class="sort" hidden>
      <option value="newest">Newest</option>
      <option value="oldest">Oldest</option>
      <option value="liked">Most liked</option>
      <option value="viewed">Most viewed</option>
      <option value="least_viewed">Least viewed</option>
      <option value="discussed">Most discussed</option>
      <option value="active">Most recently active</option>
      <option value="random">Random</option>
      <option value="relevance">Best match</option>
    </select>
  </form>

  <!-- #discover required by archive.js but never shown on search page -->
  <div id="discover" hidden></div>

  <!-- Grid layout: filter rail + results. Shown in view-grid mode. -->
  <div class="grid-layout">
    <button class="rail-toggle" type="button" id="rail-toggle" aria-expanded="false" aria-controls="grid-rail">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
      <span>Filters</span>
    </button>

    <aside class="grid-rail" id="grid-rail" aria-label="Filters">
      <button class="rail-close" type="button" id="rail-close" aria-label="Close filters">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
      <button class="rail-clear" type="button" id="rail-clear" hidden>
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        Clear all filters
      </button>
      <section class="rail-sec">
        <h3 class="rail-h">Type</h3>
        <nav class="tabs" id="tabs" aria-label="Content type"></nav>
      </section>
      <section class="rail-sec" id="rail-sec-tag" hidden>
        <h3 class="rail-h">Tags</h3>
        <div class="pills" id="tag-pills"></div>
      </section>
      <section class="rail-sec" id="rail-sec-author" hidden>
        <h3 class="rail-h">Author</h3>
        <div class="pills" id="author-pills"></div>
      </section>
      <section class="rail-sec" id="rail-sec-tier" hidden>
        <h3 class="rail-h">Membership</h3>
        <div class="pills" id="tier-pills"></div>
      </section>
    </aside>

    <div class="grid-content">
      <div class="grid-sort">
        <label for="sort-rail" class="grid-sort__label">Sort:</label>
        <select id="sort-rail" class="grid-sort__select">
          <option value="newest">Newest</option>
          <option value="oldest">Oldest</option>
          <option value="liked">Most liked</option>
          <option value="viewed">Most viewed</option>
          <option value="least_viewed">Least viewed</option>
          <option value="discussed">Most discussed</option>
          <option value="active">Most recently active</option>
          <option value="random">Random</option>
          <option value="relevance">Best match</option>
        </select>
      </div>
      <aside class="author-banner" id="author-banner" hidden></aside>
      <div class="lg-modal author-bio-modal" id="author-bio-modal" hidden aria-hidden="true">
        <div class="lg-modal__backdrop" data-author-bio-close></div>
        <div class="lg-modal__panel author-bio-modal__panel" role="dialog" aria-modal="true" aria-labelledby="author-bio-name">
          <button type="button" class="lg-modal__close" data-author-bio-close aria-label="Close">×</button>
          <div class="author-bio-modal__body" id="author-bio-body"></div>
        </div>
      </div>
      <p class="results-meta" id="results-meta" hidden></p>
      <div class="active-filters" id="active-filters" hidden></div>
      <section class="cards" id="cards" aria-live="polite" hidden></section>
      <nav class="pagination" id="pagination" aria-label="Pagination" hidden></nav>
      <div class="loadmore" hidden><span id="loadmore-status"></span></div>
    </div>
  </div>
  <div id="subfilters" hidden></div>

</main>

<!-- Search modal -->
<div class="search-modal" id="search-modal" hidden aria-hidden="true">
  <div class="search-modal__backdrop" data-search-modal-close></div>
  <div class="search-modal__dialog" role="dialog" aria-modal="true" aria-label="Search the archive">
    <form class="search-modal__form" onsubmit="event.preventDefault();">
      <svg class="search-modal__icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="search" id="search-modal-q" class="search-modal__input" placeholder="Search articles, videos, loothprints, discussions…" autocomplete="off">
      <button type="button" class="search-modal__close" data-search-modal-close aria-label="Close">×</button>
    </form>
    <div class="search-modal__results" id="search-modal-results"></div>
    <div class="search-modal__foot">
      <button type="button" class="search-modal__more" id="search-modal-more" hidden>See all results →</button>
    </div>
  </div>
</div>

<script>
// On the search page, "enter discover mode" means show the empty state and
// clear the grid — not navigate away. Esc clears the query; back link goes home.
window.__LG_SEARCH_PAGE__ = true;
</script>
<script src="/archive-poc/archive.js?v=<?= @filemtime(__DIR__.'/archive.js') ?>"></script>
</body>
</html>
