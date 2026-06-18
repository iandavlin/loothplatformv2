<?php
/**
 * archive-poc/web/_page-shell.php — shared scaffold for standalone content
 * pages (/about/, /contact/, /sponsors/, /calendar/).
 *
 * These are plain content surfaces that wear the shared site header/footer and
 * render with zero WP boot (direct PDO read where they need data). Each page
 * file is thin: boot viewer state, open the shell, emit its <main> body, close.
 *
 *   require __DIR__ . '/_page-shell.php';
 *   [$is_member, $tier] = lg_page_boot();
 *   lg_page_open($is_member, 'Title', 'meta description', 'view-content', '', $extraCss);
 *   ... body ...
 *   lg_page_close();
 */
declare(strict_types=1);
require __DIR__ . '/../config.php';

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

/** Resolve viewer state (cookie + /whoami). Mirrors index.php / search.php. */
function lg_page_boot(): array {
    $is_member = false;
    foreach (array_keys($_COOKIE) as $name) {
        if (str_starts_with($name, 'wordpress_logged_in_')) { $is_member = true; break; }
    }
    // Tier from /whoami ONLY — never the forgeable lg_tier cookie (Buck 6/11
    // paywall audit). One rule, in config.php: anon→public, admin→pro.
    $whoami = lg_archive_poc_whoami();
    $viewer_tier = lg_archive_poc_viewer_tier($whoami);
    if (!empty($whoami['authenticated'])) $is_member = true;
    $GLOBALS['LG_VIEWER_TIER'] = $viewer_tier;
    return [$is_member, $viewer_tier];
}

/** Base content-page styling shared by every content surface. */
function lg_page_base_css(): string {
    return <<<'CSS'
.lg-content-page { max-width: 880px; margin: 0 auto; padding: 28px 20px 72px; }
.lg-content-page h1 { font: 700 30px/1.15 var(--lg-font-serif); color: var(--lg-ink); margin: 0 0 6px; }
.lg-content-page .lg-page-sub { color: var(--lg-mute); font: 400 15px/1.5 var(--lg-font-sans); margin: 0 0 28px; }
.lg-content-page p { font: 400 16px/1.65 var(--lg-font-sans); color: var(--lg-ink); margin: 0 0 16px; }
.lg-content-page a { color: var(--lg-sage-d); }
CSS;
}

/**
 * Emit doctype + head + shared header, open <main>.
 * $is_member must come from lg_page_boot() (also needed by _chrome.php).
 */
function lg_page_open(bool $is_member, string $title, string $desc, string $bodyClass, string $activeNav = '', string $extraCss = ''): void {
    $path      = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $canonical = LG_ARCHIVE_POC_CANONICAL_BASE . $path;
    $css       = lg_page_base_css() . "\n" . $extraCss;
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> — Looth Group</title>
<meta name="description" content="<?= h($desc) ?>">
<meta name="robots" content="noindex, follow">
<link rel="canonical" href="<?= h($canonical) ?>">
<link rel="stylesheet" href="/archive-poc/archive.css?v=<?= @filemtime(__DIR__ . '/archive.css') ?>">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<style><?= $css ?></style>
</head>
<body class="<?= h($bodyClass) ?>">
<?php $lg_active_nav = $activeNav; require __DIR__ . '/_chrome.php'; ?>
<main class="arc-page lg-content-page" id="main">
<?php
}

/** Close <main>, emit shared footer, close document. */
function lg_page_close(): void {
    ?>
</main>
<?php require __DIR__ . '/_chrome-footer.php'; ?>
</body>
</html>
<?php
}
