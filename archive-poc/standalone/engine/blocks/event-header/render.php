<?php
/**
 * blocks/event-header/render.php
 *
 * When/where/attend strip for an `event` CPT post. Reads live from event
 * postmeta + taxonomies (the showrunner Sheet pipeline owns that data), with
 * every field overridable via an explicit prop for static-bake / CLI snapshot
 * testing. Public header details + a tier-gated virtual-attend CTA.
 *
 * Mirrors blocks/post-header/: post-context blocks read WP live (guarded by
 * function_exists so the CLI harness, which has no WP, falls back to $args).
 *
 * @var array $args  Parsed + validated props from the layout JSON
 * @var array $ctx   Render context — post_id, post_tier, viewer, editor_mode
 */

use LG\LayoutV2\TierResolver;

$post_id    = (int) ($ctx['post_id'] ?? 0);
$editorMode = !empty($ctx['editor_mode']);

$esc = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
};

/* ── Resolve raw values: explicit prop wins, else live postmeta. ──────── */
$dateRaw  = trim((string) ($args['date'] ?? ''));
$timeRaw  = trim((string) ($args['time'] ?? ''));
$zoomUrl  = trim((string) ($args['zoom_url'] ?? ''));
$region   = trim((string) ($args['region'] ?? ''));
$tzLabel  = trim((string) ($args['tz_label'] ?? '')) ?: 'ET';
$ctaLabel = trim((string) ($args['cta_label'] ?? '')) ?: 'Join on Zoom';

$eventTypes = [];
if (is_array($args['event_types'] ?? null)) {
    foreach ($args['event_types'] as $t) {
        $t = trim((string) $t);
        if ($t !== '') $eventTypes[] = $t;
    }
}

if ($post_id > 0 && function_exists('get_post_meta')) {
    if ($dateRaw === '') $dateRaw = (string) get_post_meta($post_id, 'events_start_date_and_time_', true);
    if ($timeRaw === '') $timeRaw = (string) get_post_meta($post_id, 'time_of_event', true);
    if ($zoomUrl === '') $zoomUrl = (string) get_post_meta($post_id, 'zoom_url_for_looth_group_virtual_event', true);
}

/* Region + event-type display names from taxonomies (override-first). */
if ($post_id > 0 && function_exists('get_the_terms')) {
    if ($region === '') {
        $rterms = get_the_terms($post_id, 'region');
        if (is_array($rterms) && $rterms && isset($rterms[0]->name)) {
            $region = (string) $rterms[0]->name;
        }
    }
    if (!$eventTypes) {
        $tterms = get_the_terms($post_id, 'event-type');
        if (is_array($tterms)) {
            foreach ($tterms as $term) {
                if (isset($term->name)) $eventTypes[] = (string) $term->name;
            }
        }
    }
}

/* ── Format date + time. ──────────────────────────────────────────────── */
$pillMon = $pillDay = $fullDate = $timeLabel = '';
if (preg_match('/^\d{8}$/', $dateRaw)) {
    $y = (int) substr($dateRaw, 0, 4);
    $m = (int) substr($dateRaw, 4, 2);
    $d = (int) substr($dateRaw, 6, 2);
    $ts = mktime(12, 0, 0, $m, $d, $y);
    if ($ts !== false) {
        $pillMon  = strtoupper(gmdate('M', $ts));   // MAR
        $pillDay  = gmdate('j', $ts);               // 29
        $fullDate = gmdate('l, F j, Y', $ts);       // Sunday, March 29, 2026
    }
}
if (preg_match('/(\d{1,2}):(\d{2})/', $timeRaw, $tm)) {
    /* Two data sources, two formats: legacy ACF stores 24h ("15:00:00");
       the showrunner Sheet bridge stores 12h ("7:30 pm", via date('g:i a')).
       Detect a meridiem and normalize $h to 24h either way. */
    $h  = (int) $tm[1];
    $mn = (int) $tm[2];
    if (preg_match('/p\.?m/i', $timeRaw))      { if ($h < 12) $h += 12; }
    elseif (preg_match('/a\.?m/i', $timeRaw))  { if ($h === 12) $h = 0; }
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12  = $h % 12 === 0 ? 12 : $h % 12;
    $timeLabel = sprintf('%d:%02d %s %s', $h12, $mn, $ampm, $tzLabel);
}

/* Nothing resolvable (e.g. a draft with no date) → don't emit an empty box. */
if ($pillMon === '' && $region === '' && !$eventTypes && $zoomUrl === '') {
    return;
}

/* ── Gating decision for the virtual-attend CTA. ──────────────────────── */
$gate = trim((string) ($args['cta_tier'] ?? ''));
if ($gate === '') $gate = (string) ($ctx['post_tier'] ?? '');
$viewer = is_array($ctx['viewer'] ?? null) ? $ctx['viewer'] : TierResolver::anonymous();
$satisfied = ($gate === '' || $gate === 'public')
    ? true
    : TierResolver::satisfies($viewer, $gate);

/* ── Variant. ─────────────────────────────────────────────────────────── */
$variant = is_string($args['variant'] ?? null) ? strtolower((string) $args['variant']) : 'variant-1';
if (!in_array($variant, ['variant-1', 'variant-2', 'variant-3'], true)) $variant = 'variant-1';

/* Compose the date line ("Sunday, March 29, 2026 · 3:00 PM ET"). */
$dateLine = trim($fullDate . ($timeLabel !== '' ? ' · ' . $timeLabel : ''), " ·");

$ctaProp = $editorMode ? ' data-lg-edit-prop="cta_label"' : '';
?>
<section class="lg-event-header lg-event-header--<?= $variant ?>">
  <div class="lg-event-header__row">
    <?php if ($pillMon !== ''): ?>
    <div class="lg-event-header__pill" aria-label="<?= $esc($fullDate) ?>">
      <span class="lg-event-header__pill-mon"><?= $esc($pillMon) ?></span>
      <span class="lg-event-header__pill-day"><?= $esc($pillDay) ?></span>
    </div>
    <?php endif; ?>
    <div class="lg-event-header__detail">
      <?php if ($dateLine !== ''): ?>
      <p class="lg-event-header__date"><?= $esc($dateLine) ?></p>
      <?php endif; ?>
      <?php if ($region !== ''): ?>
      <p class="lg-event-header__region"><span class="lg-event-header__pin" aria-hidden="true">📍</span><?= $esc($region) ?></p>
      <?php endif; ?>
      <?php if ($eventTypes): ?>
      <ul class="lg-event-header__types">
        <?php foreach ($eventTypes as $type): ?>
        <li class="lg-event-header__type"><?= $esc($type) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($zoomUrl !== ''): ?>
    <?php if ($satisfied): ?>
  <a class="lg-event-header__join" href="<?= $esc($zoomUrl) ?>" target="_blank" rel="noopener">
    <span class="lg-event-header__join-icon" aria-hidden="true">▶</span><span<?= $ctaProp ?>><?= $esc($ctaLabel) ?></span>
  </a>
    <?php else: ?>
  <div class="lg-event-header__gate">
    <p class="lg-event-header__gate-msg"><span class="lg-event-header__lock" aria-hidden="true">🔒</span><span>Looth members join this event live. The recording posts to the Archive afterward.</span></p>
    <a class="lg-event-header__upgrade" href="/lgjoin">Upgrade to join &rarr;</a>
  </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php
/* Editor-mode state node: lets the FE editor seed cta_label without a REST
   round-trip (skill rule #11). Only emitted in editor mode. */
if ($editorMode): ?>
<script type="application/json" data-lg-event-header-state><?= json_encode(['cta_label' => $ctaLabel], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
