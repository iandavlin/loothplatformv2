<?php
/**
 * events — read-only listing queries against WP's MySQL.
 *
 * The `event` CPT data lives in wp_posts + wp_postmeta + the term tables. We
 * read it directly (no WP boot, no loopback) and shape it for the listing.
 * Same logic the poller's UpcomingEvents uses, reimplemented data-side.
 *
 * The Zoom URL is deliberately NOT selected here — the listing is public and
 * must never expose it; the per-event gate lives on the v2 detail page.
 */

declare(strict_types=1);

/** Regions actually used by published events → [slug => name], sorted. */
function lg_events_regions(): array {
    $p   = LG_EVENTS_TABLE_PREFIX;
    $sql = "SELECT DISTINCT rt.slug, rt.name
              FROM {$p}posts pp
              JOIN {$p}term_relationships tr ON tr.object_id = pp.ID
              JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'region'
              JOIN {$p}terms rt ON rt.term_id = tt.term_id
             WHERE pp.post_type = 'event' AND pp.post_status = 'publish'
             ORDER BY rt.name";
    $out = [];
    foreach (lg_events_db()->query($sql) as $r) {
        $out[(string)$r['slug']] = html_entity_decode((string)$r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $out;
}

/**
 * One bucket of events (upcoming or past), optionally region-filtered.
 * Each row: id, title, url, ymd, hms, when{mon,day,line}, region, tier_label, thumb.
 *
 * @return list<array<string,mixed>>
 */
function lg_events_list(bool $past, string $region_slug): array {
    $p      = LG_EVENTS_TABLE_PREFIX;
    $today  = gmdate('Ymd');
    $cmp    = $past ? '<' : '>=';
    $order  = $past ? 'DESC' : 'ASC';
    $params = [':today' => $today];

    $region_join = '';
    if ($region_slug !== '') {
        $region_join = "JOIN {$p}term_relationships rtr ON rtr.object_id = p.ID
                        JOIN {$p}term_taxonomy rtt ON rtt.term_taxonomy_id = rtr.term_taxonomy_id AND rtt.taxonomy = 'region'
                        JOIN {$p}terms rgt ON rgt.term_id = rtt.term_id AND rgt.slug = :region";
        $params[':region'] = $region_slug;
    }

    /* String compare on the 8-char Ymd (events_start_date_and_time_), never a
       DATE cast — mirrors the shortcode fix that stopped the old widget crash. */
    $sql = "SELECT p.ID, p.post_title, p.post_name,
                   d.meta_value AS ymd,
                   COALESCE(tm.meta_value, '') AS hms
              FROM {$p}posts p
              JOIN {$p}postmeta d  ON d.post_id  = p.ID AND d.meta_key  = 'events_start_date_and_time_'
         LEFT JOIN {$p}postmeta tm ON tm.post_id = p.ID AND tm.meta_key = 'time_of_event'
              {$region_join}
             WHERE p.post_type = 'event' AND p.post_status = 'publish'
               AND d.meta_value <> '' AND d.meta_value {$cmp} :today
             ORDER BY d.meta_value {$order}
             LIMIT 50";

    $stmt = lg_events_db()->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt as $r) {
        $id  = (int)$r['ID'];
        $rows[] = [
            'id'         => $id,
            'title'      => html_entity_decode((string)$r['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'url'        => LG_EVENTS_EVENT_BASE . rawurlencode((string)$r['post_name']) . '/',
            'when'       => lg_events_when((string)$r['ymd'], (string)$r['hms']),
            'region'     => lg_events_term_name($id, 'region'),
            'tier_label' => lg_events_tier_label($id),
            'thumb'      => lg_events_thumb_url($id),
        ];
    }
    return $rows;
}

/** First term NAME of $taxonomy on a post, or ''. */
function lg_events_term_name(int $post_id, string $taxonomy): string {
    $p   = LG_EVENTS_TABLE_PREFIX;
    $sql = "SELECT t.name
              FROM {$p}term_relationships tr
              JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = :tax
              JOIN {$p}terms t ON t.term_id = tt.term_id
             WHERE tr.object_id = :id LIMIT 1";
    $st = lg_events_db()->prepare($sql);
    $st->execute([':tax' => $taxonomy, ':id' => $post_id]);
    $name = (string)($st->fetchColumn() ?: '');
    return html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Human tier label ('Pro'/'Lite') from the `tier` taxonomy, or '' to omit.
 *  Informational on the public listing — not a gate. */
function lg_events_tier_label(int $post_id): string {
    $p   = LG_EVENTS_TABLE_PREFIX;
    $sql = "SELECT t.slug
              FROM {$p}term_relationships tr
              JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'tier'
              JOIN {$p}terms t ON t.term_id = tt.term_id
             WHERE tr.object_id = :id";
    $st = lg_events_db()->prepare($sql);
    $st->execute([':id' => $post_id]);
    foreach ($st as $r) {
        if ($r['slug'] === 'looth-pro')  return 'Pro';
        if ($r['slug'] === 'looth-lite') return 'Lite';
    }
    return '';
}

/** Featured-image URL via _thumbnail_id → _wp_attached_file, or ''. */
function lg_events_thumb_url(int $post_id): string {
    $p   = LG_EVENTS_TABLE_PREFIX;
    $sql = "SELECT af.meta_value
              FROM {$p}postmeta ti
              JOIN {$p}postmeta af ON af.post_id = ti.meta_value AND af.meta_key = '_wp_attached_file'
             WHERE ti.post_id = :id AND ti.meta_key = '_thumbnail_id' LIMIT 1";
    $st = lg_events_db()->prepare($sql);
    $st->execute([':id' => $post_id]);
    $file = (string)($st->fetchColumn() ?: '');
    return $file !== '' ? LG_EVENTS_UPLOADS_BASE . $file : '';
}

/**
 * Format stored date + time → display parts. Handles legacy 24h ("15:00:00")
 * and the Sheet bridge's 12h ("3:00 pm"). Pure (no DB).
 * @return array{mon:string,day:string,line:string}
 */
function lg_events_when(string $ymd, string $hms): array {
    $mon = $day = $line = '';
    if (preg_match('/^\d{8}$/', $ymd)) {
        $ts = mktime(12, 0, 0, (int)substr($ymd, 4, 2), (int)substr($ymd, 6, 2), (int)substr($ymd, 0, 4));
        if ($ts !== false) {
            $mon  = strtoupper(gmdate('M', $ts));
            $day  = gmdate('j', $ts);
            $line = gmdate('l, F j, Y', $ts);
        }
    }
    if (preg_match('/(\d{1,2}):(\d{2})/', $hms, $m)) {
        $h  = (int)$m[1];
        $mn = (int)$m[2];
        if (preg_match('/p\.?m/i', $hms))     { if ($h < 12) $h += 12; }
        elseif (preg_match('/a\.?m/i', $hms)) { if ($h === 12) $h = 0; }
        $ampm = $h >= 12 ? 'PM' : 'AM';
        $h12  = $h % 12 === 0 ? 12 : $h % 12;
        $line = trim($line . ' · ' . sprintf('%d:%02d %s ET', $h12, $mn, $ampm), ' ·');
    }
    return ['mon' => $mon, 'day' => $day, 'line' => $line];
}
