<?php
/**
 * bb-mirror/api/v0/seo-redirect.php — SEO continuity slug-resolver (cut lane, 2026-06-15).
 *
 * GET /bb-mirror-api/v0/seo-redirect?slug=<slug>
 * GET /bb-mirror-api/v0/seo-redirect?reply=<id>
 *   Resolves an OLD (pre-cut) bbPress topic/forum slug to its canonical /hub URL
 *   and emits a **301 Moved Permanently**. The cut changes the URL structure; any
 *   old indexed URL that 404s loses its Google ranking. ~69% of our indexed footprint
 *   is old forum/group topic URLs (GSC 2026-06-15). This endpoint preserves that
 *   equity by 301-ing every old slug to its new home — and NEVER 404s.
 *
 * Resolution order (topic slugs are GLOBALLY UNIQUE — verified 1284/1284 distinct —
 * so a topic-slug lookup is deterministic even though forum slugs are NOT unique):
 *   1. slug matches a topic   → 301 /hub/<forum_slug>/<topic_slug>/   (the big save)
 *   2. slug matches a forum   → 301 /hub/<forum_slug>/                 (forum landings)
 *   3. no match               → 301 /hub/                             (never 404)
 *
 * ?reply=<id> — the LEGACY REPLY PERMALINK (hub-seo-landing lane, 2026-08-09).
 * bbPress numbered its replies: /all-forums-all-topics/reply/14770/. Those URLs
 * were 301ing many-to-one to bare /hub/, which Google reads as a SOFT 404 — it
 * transfers no authority and does not consolidate, so the old URL keeps its own
 * index entry. That is the reported symptom, and it is why a blanket Disallow
 * would make it worse (a disallowed URL cannot be recrawled, so it stays indexed
 * with no content).
 *
 * Measured over one live log window: 23 requests, and the ids RESOLVE — both
 * sampled ids exist in forums.reply, and 5,110 replies join to a public,
 * published topic. So this is the last legacy shape with a real per-URL target.
 *   4. reply id → its topic  → 301 /hub/<forum_slug>/<topic_slug>/
 *   5. no match / gated      → 301 /hub/                             (never 404)
 * Deliberately NOT anchored to the individual reply: the modal's reply anchor is
 * ?topic=…&reply=<id>, a form live robots.txt Disallows (`Disallow: /hub/?`), so
 * sending a crawler there would trade a soft 404 for a blocked URL. The topic
 * permalink is the indexable thing, and it is what the sitemap advertises.
 *
 * 301 (not 302) is mandatory: 302 keeps Google on the OLD URL and transfers no equity.
 *
 * Runs on the bb-mirror FPM pool (PG-only, no WP) like topic.php. On dev it sits
 * behind the cookie gate so it's testable with the dev cookie; at the cut the gate
 * block is removed and these redirects become public (Googlebot is anonymous).
 * Only published topics in public forums are honored as a "topic" hit — gated/hidden
 * threads fall through to the forum or /hub redirect, never leaking a private slug's
 * existence beyond a generic landing.
 */

declare(strict_types=1);

require __DIR__ . '/../../config.php';

$base = defined('LG_BB_MIRROR_PUBLIC_PATH') ? LG_BB_MIRROR_PUBLIC_PATH : '/hub';

/** Emit a 301 to a /hub path and stop. Always permanent, always trailing-slashed. */
function seo_301(string $path): void {
    // 301s are cached hard by browsers/crawlers; let intermediaries cache too.
    header('Cache-Control: public, max-age=86400');
    header('Location: ' . $path, true, 301);
    exit;
}

// nginx alias+try_files can drop QUERY_STRING for some routes; parse REQUEST_URI as
// the front controller / topic.php do, so ?slug= survives regardless of routing.
if (empty($_GET['slug'])) {
    $qs = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?: '';
    if ($qs !== '') { parse_str($qs, $parsed); $_GET = array_merge($parsed, $_GET); }
}

// ?reply=<id> resolves FIRST and is separate from ?slug= on purpose: a numeric
// value is a legitimate topic slug in principle, so overloading one parameter
// would make the caller's intent ambiguous at exactly the point where guessing
// wrong sends a crawler to the wrong page.
$reply_id = (int)($_GET['reply'] ?? 0);
if ($reply_id > 0) {
    try {
        $db = bb_mirror_db();
        // Same visibility gate as every other read path: a reply in a hidden or
        // private forum resolves to NOTHING and falls through to the /hub/
        // landing, so this endpoint cannot be used to confirm that a gated
        // thread exists.
        $q = $db->prepare("
            SELECT f.slug AS forum_slug, t.slug AS topic_slug
              FROM forums.reply r
              JOIN forums.topic t ON t.id = r.topic_id
              JOIN forums.forum f ON f.id = t.forum_id
             WHERE r.id = :rid
               AND r.status = 'publish'
               AND t.status IN ('publish','closed')
               AND f.visibility = 'public'
             LIMIT 1
        ");
        $q->execute([':rid' => $reply_id]);
        if ($row = $q->fetch()) {
            seo_301($base . '/' . $row['forum_slug'] . '/' . $row['topic_slug'] . '/');
        }
    } catch (Throwable $e) {
        error_log('seo-redirect(reply): ' . $e->getMessage());
    }
    seo_301($base . '/');   // unknown/gated reply → the Hub landing. Never a 404.
}

$slug = trim((string)($_GET['slug'] ?? ''));
// bbPress slugs are [a-z0-9-]; normalize defensively (strip any trailing slash/junk).
$slug = strtolower(trim($slug, "/ \t\n\r\0\x0B"));

if ($slug === '') seo_301($base . '/');

try {
    $db = bb_mirror_db();

    // 1) Topic slug (unique) — only honor public, published/closed threads.
    $q = $db->prepare("
        SELECT f.slug AS forum_slug, t.slug AS topic_slug
          FROM forums.topic t
          JOIN forums.forum f ON f.id = t.forum_id
         WHERE t.slug = :s
           AND t.status IN ('publish','closed')
           AND f.visibility = 'public'
         LIMIT 1
    ");
    $q->execute([':s' => $slug]);
    if ($row = $q->fetch()) {
        seo_301($base . '/' . $row['forum_slug'] . '/' . $row['topic_slug'] . '/');
    }

    // 2) Forum slug (NOT unique — LIMIT 1; honor public forums only).
    $q = $db->prepare("
        SELECT slug FROM forums.forum
         WHERE slug = :s AND visibility = 'public'
         LIMIT 1
    ");
    $q->execute([':s' => $slug]);
    if ($row = $q->fetch()) {
        seo_301($base . '/' . $row['slug'] . '/');
    }
} catch (Throwable $e) {
    // Never 404 / never 500 a crawler — fall through to the /hub landing.
    error_log('seo-redirect: ' . $e->getMessage());
}

// 3) Unknown slug → the Hub landing. Never a 404.
seo_301($base . '/');
