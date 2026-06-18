<?php
declare(strict_types=1);

/**
 * weekly-query.php — read-only WP-MySQL readers for the STANDALONE weekly
 * digest page (Ian 6/12: "weekly email page built for standalone").
 *
 * The lg-weekly-digest plugin (WP-side) owns composing + SENDING the email;
 * each sent issue is a `weekly_email` post whose `_lg_wd_issue_data` meta
 * carries the curated sections (header rows + post_ids per section). This
 * lib reads that meta with NO WP boot (same pattern as events-query.php)
 * and resolves the referenced posts into render-ready cards whose links
 * target the NEW surfaces (hub topics, v2 event/video pages) — not the
 * retiring BB permalinks.
 */

/** Published issues, newest first: [{id, slug, title, date, from, to}] */
function lg_weekly_issues(PDO $db, int $limit = 52): array
{
    $st = $db->prepare("
        SELECT p.ID, p.post_name, p.post_title, p.post_date, m.meta_value
        FROM wp_posts p
        LEFT JOIN wp_postmeta m ON m.post_id = p.ID AND m.meta_key = '_lg_wd_issue_data'
        WHERE p.post_type = 'weekly_email' AND p.post_status = 'publish'
        ORDER BY p.post_date DESC
        LIMIT " . max(1, min(200, $limit)));
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $d = lg_weekly_unserialize((string)($r['meta_value'] ?? ''));
        $out[] = [
            'id'    => (int)$r['ID'],
            'slug'  => (string)$r['post_name'],
            'title' => (string)$r['post_title'],
            'date'  => (string)$r['post_date'],
            'from'  => (string)($d['date_from'] ?? ''),
            'to'    => (string)($d['date_to'] ?? ''),
        ];
    }
    return $out;
}

/** One issue by slug → ['post' => row, 'data' => issue meta] or null. */
function lg_weekly_issue(PDO $db, string $slug): ?array
{
    $st = $db->prepare("
        SELECT p.ID, p.post_name, p.post_title, p.post_date, m.meta_value
        FROM wp_posts p
        LEFT JOIN wp_postmeta m ON m.post_id = p.ID AND m.meta_key = '_lg_wd_issue_data'
        WHERE p.post_type = 'weekly_email' AND p.post_status = 'publish' AND p.post_name = :s
        LIMIT 1");
    $st->execute([':s' => $slug]);
    $r = $st->fetch();
    if (!$r) return null;
    $d = lg_weekly_unserialize((string)($r['meta_value'] ?? ''));
    if (!is_array($d) || empty($d['sections'])) return null;
    return ['post' => $r, 'data' => $d];
}

/** PHP-serialized meta → array (classes forbidden), [] on garbage. */
function lg_weekly_unserialize(string $raw): array
{
    if ($raw === '') return [];
    $v = @unserialize($raw, ['allowed_classes' => false]);
    return is_array($v) ? $v : [];
}

/**
 * Resolve a set of post IDs → render cards keyed by id:
 *   {id, title, type, slug, url, thumb, excerpt, event_when}
 * Links target the NEW surfaces: topics → /hub/topic/<slug>/ (the standalone
 * hub), everything else → /<post_type>/<slug>/ (the v2 pages; same pretty
 * permalinks WP uses, host-relative so they work on dev AND live).
 */
function lg_weekly_resolve(PDO $db, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $st = $db->prepare("
        SELECT p.ID, p.post_title, p.post_type, p.post_name, p.post_excerpt, p.post_status,
               thumbf.meta_value AS thumb_file,
               evdate.meta_value AS event_start
        FROM wp_posts p
        LEFT JOIN wp_postmeta thumbid ON thumbid.post_id = p.ID AND thumbid.meta_key = '_thumbnail_id'
        LEFT JOIN wp_postmeta thumbf  ON thumbf.post_id = thumbid.meta_value AND thumbf.meta_key = '_wp_attached_file'
        LEFT JOIN wp_postmeta evdate  ON evdate.post_id = p.ID AND evdate.meta_key = 'events_start_date_and_time_'
        WHERE p.ID IN ($ph)");
    $st->execute($ids);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        if (!in_array($r['post_status'], ['publish', 'archived'], true)) continue;  // unpublished refs drop out
        $type = (string)$r['post_type'];
        $slug = (string)$r['post_name'];
        $url  = ($type === 'topic')
            ? '/hub/topic/' . rawurlencode($slug) . '/'
            : '/' . rawurlencode($type) . '/' . rawurlencode($slug) . '/';
        $when = '';
        if (!empty($r['event_start'])) {
            $ts = strtotime((string)$r['event_start']);
            if ($ts) $when = date('D M j · g:i a', $ts);
        }
        $out[(int)$r['ID']] = [
            'id'      => (int)$r['ID'],
            'title'   => (string)$r['post_title'],
            'type'    => $type,
            'slug'    => $slug,
            'url'     => $url,
            'thumb'   => !empty($r['thumb_file']) ? LG_EVENTS_UPLOADS_BASE . ltrim((string)$r['thumb_file'], '/') : '',
            'excerpt' => (string)$r['post_excerpt'],
            'when'    => $when,
        ];
    }
    return $out;
}

/** Latest published issue slug ('' if none). */
function lg_weekly_latest_slug(PDO $db): string
{
    $v = $db->query("SELECT post_name FROM wp_posts WHERE post_type='weekly_email'
                     AND post_status='publish' ORDER BY post_date DESC LIMIT 1")->fetchColumn();
    return is_string($v) ? $v : '';
}

/**
 * The EXACT email HTML the issue was sent as (FluentCRM campaign body, via the
 * campaign_id in the issue meta). '' when the issue was never sent — the CURRENT
 * (unsent) lead issue takes this path; the caller then renders it on the fly
 * via lg_weekly_email_preview_html(). The web page serves whichever HTML in an
 * isolated iframe so it displays "just like the email" (Ian 6/12) — with the
 * email-only unsubscribe footer line removed.
 */
function lg_weekly_campaign_html(PDO $db, array $issueData, bool $maskAuthors = false): string
{
    $cid = (int)($issueData['campaign_id'] ?? 0);
    if ($cid < 1) return '';
    $st = $db->prepare("SELECT email_body FROM wp_fc_campaigns WHERE id = :i");
    $st->execute([':i' => $cid]);
    $html = (string)($st->fetchColumn() ?: '');
    if ($html === '') return '';
    return lg_weekly_email_chrome($html, $maskAuthors);
}

/**
 * The email HTML built ON THE FLY from the issue's curated section data — used
 * for the LEAD (current, unsent) digest, which has no sent FluentCRM body. A
 * WP loopback runs the SAME builder the sent email uses (LG_WD_Sender dry-run,
 * via the lg-weekly-email-bridge mu-plugin), so the preview == the real email.
 * '' on any failure → caller falls back to the web-card layout.
 *
 * Forwards the viewer cookie like the /whoami loopback so the cookie gate
 * passes on dev/dev2; the result is cached in tmpfs (keyed on slug + mask) to
 * skip the WP-boot tax on the page render AND the follow-up /raw iframe fetch.
 */
function lg_weekly_email_preview_html(string $slug, bool $maskAuthors = false): string
{
    $slug = trim($slug);
    if ($slug === '' || PHP_SAPI === 'cli') return '';

    $cache = '/dev/shm/lg-weekly-email-' . hash('sha256', $slug . '|' . ($maskAuthors ? '1' : '0')) . '.html';
    if (is_readable($cache) && (time() - (int)filemtime($cache)) < 300) {
        $hit = (string)file_get_contents($cache);
        if ($hit !== '') return $hit;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://127.0.0.1/wp-json/looth/v1/weekly-email-html?slug=' . rawurlencode($slug),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'Host: ' . LG_EVENTS_HOST,
            'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? ''),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($body)) return '';

    $json = json_decode($body, true);
    $html = is_array($json) ? (string)($json['html'] ?? '') : '';
    if ($html === '') return '';

    $html = lg_weekly_email_chrome($html, $maskAuthors);
    @file_put_contents($cache, $html, LOCK_EX);
    return $html;
}

/**
 * Email-only chrome adjustments shared by the sent-campaign and on-the-fly
 * preview paths, so both display identically in the standalone iframe.
 */
function lg_weekly_email_chrome(string $html, bool $maskAuthors = false): string
{
    if ($html === '') return '';
    // Email-only chrome: drop anchors that point at unsubscribe/preference
    // endpoints (meaningless on the web view), keep everything else verbatim.
    $html = preg_replace('#<a\b[^>]*href="[^"]*unsubscribe[^"]*"[^>]*>.*?</a>#is', '', $html) ?? $html;
    // The web view serves this in an IFRAME: without a base target, links
    // navigate the frame (page-inside-a-page, Ian 6/12). _top breaks every
    // click out to the real CPT/hub page.
    $html = preg_replace('#<head([^>]*)>#i', '<head$1><base target="_top">', $html, 1) ?? $html;
    if ($maskAuthors) {
        // ANON view (vis-1 digest, Ian 6/12): forum-author bylines follow the
        // discussion-identity mask — logged-out viewers never see a forum
        // author's name/profile link here, same rule as the Hub. The byline
        // anchors are the /members/<slug>/forums/ links the email builder
        // emits; everything else (event hosts, video authors) is public.
        $html = preg_replace('#<a\b[^>]*href="[^"]*/members/[^"]*"[^>]*>.*?</a>#is', 'a Looth member', $html) ?? $html;
    }
    return $html;
}
