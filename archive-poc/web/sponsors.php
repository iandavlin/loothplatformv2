<?php
/**
 * archive-poc/web/sponsors.php — /sponsors/ ("Our Sponsors") standalone page.
 *
 * Listing is driven from the MATERIALIZED sponsor pages (discovery.article_blobs,
 * post_type='sponsor-page') — so it shows exactly the sponsors that have a real
 * v2 page, and the dropped tester (never materialized) simply doesn't appear.
 * Each card pulls its hero image, logo, name + brand color straight from the
 * baked blob (no WP boot, no per-request brand-store HTTP), and links to the
 * /sponsors/<slug>/ surface.
 *
 * Fallback: if the blob store is unavailable / empty (or we're still on the
 * sqlite leg), fall back to the content_item index as a plain text listing.
 */
declare(strict_types=1);
require __DIR__ . '/_page-shell.php';
[$is_member, $tier] = lg_page_boot();

/** @var array<int,array{slug:string,name:string,hero:string,logo:string,primary:string}> */
$sponsors = [];
try {
    $pdo = lg_archive_poc_pdo();
    if (lg_archive_poc_is_pg($pdo)) {
        // Brand assets live inside the baked layout: blob.layout.sponsor.*
        $stmt = $pdo->query(
            "SELECT slug,
                    COALESCE(blob->'layout'->'sponsor'->>'display_name',
                             blob->'layout'->'sponsor'->>'name', slug) AS name,
                    COALESCE(blob->'layout'->'sponsor'->'hero'->>'url', '')        AS hero,
                    COALESCE(blob->'layout'->'sponsor'->>'logo_url', '')           AS logo,
                    COALESCE(blob->'layout'->'sponsor'->'colors'->>'primary', '')  AS primary
             FROM article_blobs
             WHERE post_type = 'sponsor-page'
             ORDER BY lower(COALESCE(blob->'layout'->'sponsor'->>'display_name',
                                     blob->'layout'->'sponsor'->>'name', slug))"
        );
        $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    error_log('sponsors.php (blobs): ' . $e->getMessage());
    $sponsors = [];
}

/* Fallback to the content_item index (plain text cards) if the blob listing is
   empty — keeps the page alive pre-materialization or on the sqlite leg. */
if (!$sponsors) {
    try {
        $pdo = $pdo ?? lg_archive_poc_pdo();
        $ci_order = lg_archive_poc_is_pg($pdo) ? 'lower(title)' : 'title COLLATE NOCASE';
        $rows = $pdo->query(
            "SELECT title, url FROM content_item WHERE cpt='sponsor-page' ORDER BY $ci_order"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $slug = trim((string) parse_url((string) ($r['url'] ?? ''), PHP_URL_PATH), '/');
            $slug = ($p = strrpos($slug, '/')) !== false ? substr($slug, $p + 1) : $slug;
            if ($slug === '' || $slug === 'the-guitar-specialist') continue;
            $sponsors[] = ['slug' => $slug, 'name' => (string) ($r['title'] ?? 'Sponsor'),
                           'hero' => '', 'logo' => '', 'primary' => ''];
        }
    } catch (Throwable $e) {
        error_log('sponsors.php (index): ' . $e->getMessage());
    }
}

$css = <<<'CSS'
.sponsor-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; margin-top: 8px; }
.sponsor-card {
  position: relative; display: block; aspect-ratio: 16 / 10; border-radius: 14px;
  overflow: hidden; text-decoration: none; background: var(--lg-card-bg);
  border: 1px solid var(--lg-line); box-shadow: 0 1px 3px rgba(0,0,0,.06);
  transition: transform .15s ease, box-shadow .15s ease;
}
.sponsor-card:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(0,0,0,.16); }
.sponsor-card__hero { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; transition: transform .35s ease; }
.sponsor-card:hover .sponsor-card__hero { transform: scale(1.06); }
/* Heroless fallback: a soft brand-tinted field so the name still reads as a card. */
.sponsor-card--plain { background: linear-gradient(135deg, var(--lg-sage-3, #e7ecd9), var(--lg-card-bg)); }
.sponsor-card__veil { position: absolute; inset: 0;
  background: linear-gradient(to top, rgba(20,22,18,.82) 0%, rgba(20,22,18,.25) 52%, rgba(20,22,18,0) 100%); }
.sponsor-card--plain .sponsor-card__veil { background: none; }
.sponsor-card__body { position: absolute; left: 0; right: 0; bottom: 0;
  display: flex; align-items: center; gap: 13px; padding: 16px 18px; }
.sponsor-card--plain .sponsor-card__body { position: static; height: 100%; justify-content: center; }
.sponsor-card__logo { width: 50px; height: 50px; flex: 0 0 auto; border-radius: 11px;
  background: #fff; padding: 5px; object-fit: contain; box-shadow: 0 2px 8px rgba(0,0,0,.25); }
.sponsor-card__name { font: 800 19px/1.2 var(--lg-font-serif); color: #fff; text-shadow: 0 1px 4px rgba(0,0,0,.45); }
.sponsor-card--plain .sponsor-card__name { color: var(--lg-ink); text-shadow: none; }
.sponsor-card__accent { position: absolute; left: 0; right: 0; bottom: 0; height: 4px; background: var(--lg-sage, #87986a); }
.sponsors-empty { color: var(--lg-mute); font: 400 16px/1.6 var(--lg-font-sans); }
CSS;

lg_page_open($is_member, 'Our Sponsors', 'The sponsors who support The Looth Group community.', 'view-content arc-sponsors-page', '', $css);
?>
<h1>Our Sponsors</h1>
<p class="lg-page-sub">The companies that support the Looth Group community.</p>

<?php if (!$sponsors): ?>
  <p class="sponsors-empty">Sponsor listings are coming soon.</p>
<?php else: ?>
  <div class="sponsor-grid">
    <?php foreach ($sponsors as $s):
        $slug = (string) ($s['slug'] ?? '');
        if ($slug === '') continue;
        $name    = (string) ($s['name'] ?? 'Sponsor');
        $hero    = trim((string) ($s['hero'] ?? ''));
        $logo    = trim((string) ($s['logo'] ?? ''));
        $primary = trim((string) ($s['primary'] ?? ''));
        $plain   = $hero === '';
        $accent  = preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $primary) ? $primary : '';
    ?>
    <a class="sponsor-card<?= $plain ? ' sponsor-card--plain' : '' ?>" href="/sponsors/<?= h(rawurlencode($slug)) ?>/">
      <?php if (!$plain): ?>
        <img class="sponsor-card__hero" src="<?= h($hero) ?>" alt="" loading="lazy">
        <span class="sponsor-card__veil"></span>
      <?php endif; ?>
      <span class="sponsor-card__body">
        <?php if ($logo !== ''): ?>
          <img class="sponsor-card__logo" src="<?= h($logo) ?>" alt="<?= h($name) ?> logo" loading="lazy">
        <?php endif; ?>
        <span class="sponsor-card__name"><?= h($name) ?></span>
      </span>
      <span class="sponsor-card__accent"<?= $accent !== '' ? ' style="background:' . h($accent) . '"' : '' ?>></span>
    </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
lg_page_close();
