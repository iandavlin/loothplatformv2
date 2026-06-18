<?php
/**
 * /membership-guide/ — standalone front controller (no WP boot).
 *
 * Replaces the WP-templated page render with a self-contained PHP surface,
 * per coord §0b launch invariant.
 *
 * Render shape:
 *   <!doctype> → site-header.css → shared header → <main> guide content → shared footer
 *
 * Data: read once from wp_options via direct PDO (lg_membership_guide_load_options).
 *
 * Viewer state: cached /whoami loopback (lg_membership_whoami).
 *
 * Scope for this PoC: the guide content rendered here is intentionally a
 * SUBSET of the 707-line legacy template (preview cards + elders + loothalong
 * URL) — enough to verify the standalone delivery mechanism end-to-end and
 * exercise the shared chrome with real data. Full feature parity (recurring
 * shows carousel, demo clips, screenshot grid, forums-image card, admin
 * preview bar, inline-edit hooks) follows in a subsequent turn once the
 * delivery shape is approved. The legacy template at
 *   /var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/templates/page/membership-guide.php
 * remains the source-of-truth for the full markup; this file is the
 * standalone port-in-progress.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/whoami.php';
require __DIR__ . '/../lib/guide-data.php';
require '/srv/lg-shared/site-header.php';
require '/srv/lg-shared/site-footer.php';

$h       = 'lg_membership_h';
$ctx     = lg_membership_header_ctx('');                                    // §0a: no top-nav slot for membership
$opts    = lg_membership_guide_load_options();
$is_anon = !(($ctx['authenticated'] ?? false) === true);
$body_class = $is_anon ? 'lgms-mg-anon' : 'lgms-mg-member';

$preview_cards = is_array($opts['preview_cards'] ?? null) ? $opts['preview_cards'] : [];
$elders        = is_array($opts['elders']        ?? null) ? $opts['elders']        : [];
$loothalong    = (string)($opts['loothalong_url'] ?? '');

$asset_v = (string)(@filemtime(__DIR__ . '/membership-guide.css') ?: '1');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Membership Guide — The Looth Group</title>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="<?= $h(LG_MEMBERSHIP_PUBLIC_PATH) ?>/membership-guide.css?v=<?= $h($asset_v) ?>">
</head>
<body class="lg-membership-page <?= $h($body_class) ?>">

<?php lg_shared_render_site_header($ctx); ?>

<main id="lg-main" class="lg-mguide">
    <header class="lg-mguide__head">
        <h1 class="lg-mguide__title">Membership Guide</h1>
        <?php if ($is_anon): ?>
            <p class="lg-mguide__sub">What's inside — a tour for visitors.</p>
        <?php else: ?>
            <p class="lg-mguide__sub">Start here.</p>
        <?php endif; ?>
    </header>

    <?php if ($preview_cards !== []): ?>
    <section class="lg-mguide__section lg-mguide__previews">
        <h2 class="lg-mguide__h">What's inside</h2>
        <div class="lg-mguide__grid">
            <?php foreach ($preview_cards as $card):
                if (!is_array($card)) continue;
                $title = (string)($card['title'] ?? '');
                $url   = (string)($card['url']   ?? '');
                $kind  = (string)($card['kind']  ?? '');
                $thumb_id = (int)($card['thumb_id'] ?? 0);
                $thumb_url = $thumb_id > 0 ? lg_membership_guide_resolve_attachment_url($thumb_id) : '';
            ?>
                <a class="lg-mguide__card" href="<?= $h($url) ?>">
                    <?php if ($thumb_url !== ''): ?>
                        <div class="lg-mguide__card-thumb" style="background-image:url('<?= $h($thumb_url) ?>')"></div>
                    <?php endif; ?>
                    <div class="lg-mguide__card-body">
                        <?php if ($kind !== ''): ?>
                            <span class="lg-mguide__card-kind"><?= $h($kind) ?></span>
                        <?php endif; ?>
                        <h3 class="lg-mguide__card-title"><?= $h($title) ?></h3>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($elders !== []): ?>
    <section class="lg-mguide__section lg-mguide__elders" id="elders">
        <h2 class="lg-mguide__h">Council of Elders</h2>
        <div class="elders">
            <?php foreach ($elders as $idx => $elder):
                if (!is_array($elder)) continue;
                $name      = (string)($elder['name'] ?? '');
                $avatar_id = (int)($elder['avatar_id'] ?? 0);
                $avatar    = $avatar_id > 0 ? lg_membership_guide_resolve_attachment_url($avatar_id) : '';
                $slug      = $name !== '' ? strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) : '';
                $bio_href  = $slug !== '' ? '/elder-' . $slug . '/' : '#';
            ?>
                <a class="elder" href="<?= $h($bio_href) ?>" target="_blank" rel="noopener">
                    <span class="lgms-elder-pic"<?= $avatar ? ' style="background-image:url(\'' . $h($avatar) . '\')"' : '' ?>></span>
                    <span class="lgms-elder-name"><?= $h($name) ?></span>
                    <span class="lgms-elder-cta">VIEW BIO</span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($loothalong !== ''): ?>
    <section class="lg-mguide__section lg-mguide__loothalong" id="loothalong">
        <h2 class="lg-mguide__h">Loothalong</h2>
        <?php if ($is_anon): ?>
            <p><a class="lg-mguide__cta" href="/lgjoin/">See the plans &rarr;</a></p>
        <?php else: ?>
            <p><a class="lg-mguide__cta" href="<?= $h($loothalong) ?>" target="_blank" rel="noopener">Join the room &rarr;</a></p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <p class="lg-mguide__poc-note">
        <small>Standalone PoC — full feature parity (recurring shows, demo clips,
        screenshots, forums image, admin preview bar) is staged for the next pass.</small>
    </p>
</main>

<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>

</body>
</html>
