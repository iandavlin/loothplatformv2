<?php
/**
 * bb-mirror/api/v0/topic.php — single-topic READ fragment for the front-page
 * discussion modal (front-page-discussion-modal lane, 2026-06-14).
 *
 * GET /bb-mirror-api/v0/topic?forum=<forum-slug>&topic=<topic-slug>
 *   → HTML fragment: the OP (author meta + full body + reply CTA), shaped to
 *     drop straight into the shared #lg-dmodal chrome (forums.css §lg-dmodal,
 *     the same modal the Hub feed opens). Replies are NOT inlined here — the
 *     client drains the proven /hub/?replies=<id> endpoint (identical to §4e),
 *     which carries reply reactions + the ↪@parent prefix server-rendered.
 *
 * Runs on the bb-mirror FPM pool (PG-only, no WP) — the SAME pool + masks as
 * the /hub single-topic page, so the modal == the page. Visibility is enforced
 * SERVER-SIDE exactly as _single-topic.php does:
 *   • forum visibility != 'public'  → 404 (hidden/private threads never load)
 *   • topic status not publish/closed → 404
 *   • is_anon author        → masked to "Anonymous" for non-moderators
 *   • member-only author     → masked to "Private member" for logged-out viewers
 *   • logged-out body        → email/@handle contact-scrub (_topic-body.php)
 * The discussions row is audience:members (anon never sees the cards), but this
 * endpoint is directly callable, so it gates independently — never trust the client.
 *
 * Composer gate (Ian's standing rule): the reply CTA shows on the WP login
 * COOKIE (lg_bb_mirror_can_post), NOT /whoami — an unbridged member still gets
 * the composer; the server 401 on /bb-mirror-api/v0/reply is the real lock.
 */

declare(strict_types=1);

require __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../web/forums/_reply-render.php'; // lg_bb_mirror_can_post, bb_mirror_avatar, feed_rel_time
require_once __DIR__ . '/../../web/_anon-scrub.php';          // lg_scrub_anon_contacts (used by _topic-body)

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function topic_404(): void {
    http_response_code(404);
    echo '<div class="lg-dmodal__note">Discussion not found.</div>';
    exit;
}

// nginx alias+try_files drops QUERY_STRING for some routes; parse REQUEST_URI as
// the front controller does, so ?forum=&topic= survive regardless.
if (empty($_GET['forum']) && empty($_GET['topic'])) {
    $qs = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?: '';
    if ($qs !== '') { parse_str($qs, $parsed); $_GET = array_merge($parsed, $_GET); }
}

$forum_slug = trim((string)($_GET['forum'] ?? ''));
$topic_slug = trim((string)($_GET['topic'] ?? ''));
if ($forum_slug === '' || $topic_slug === '') topic_404();

$db = bb_mirror_db();

// ── Lookup + gating — JOIN on BOTH slugs (two forums share slug='finish'; a
//    forum-first lookup is non-deterministic). Mirrors _single-topic.php. ──────
$q = $db->prepare("
    SELECT t.id, t.slug, t.title, t.author_name, t.author_slug, t.author_id,
           t.is_anon::int                              AS is_anon,
           COALESCE(p.discussion_visibility, 'member') AS discussion_visibility,
           p.avatar_url,
           t.created_at, t.status,
           f.id   AS forum_id,
           f.slug AS forum_slug
      FROM forums.topic  t
      JOIN forums.forum  f ON f.id = t.forum_id
      LEFT JOIN forums.person p ON p.id = t.author_id
     WHERE f.slug = :fs
       AND t.slug = :ts
       AND t.status IN ('publish', 'closed')
       AND f.visibility = 'public'
     LIMIT 1
");
$q->execute([':fs' => $forum_slug, ':ts' => $topic_slug]);
$topic = $q->fetch();
if (!$topic) topic_404();

// ── Author-identity masks (leak-safe) — BEFORE any identity render. Same masks
//    the feed + single-topic page apply. ──────────────────────────────────────
$viewer_logged_in = lg_bb_mirror_can_post();
$can_mod          = lg_bb_mirror_can_moderate();
lg_bb_mirror_mask_anon($topic, $can_mod);
lg_bb_mirror_mask_visibility($topic, $viewer_logged_in);

$tid        = (int)$topic['id'];
$fid        = (int)$topic['forum_id'];
$title      = (string)$topic['title'];
$author     = $topic['author_name'] ?: 'Anonymous';
$aslug      = $topic['author_slug'] ?? null;
$rel        = $topic['created_at'] ? feed_rel_time((string)$topic['created_at']) : '';
$avatar     = bb_mirror_avatar($author, $aslug ?: $author, 38, $topic['avatar_url'] ?? null);

// ── OP body — reuse _topic-body.php verbatim (mention-resolve + attachments +
//    logged-out contact scrub), captured. Re-checks forum visibility itself. ──
$_GET['body'] = (string)$tid;
ob_start();
require __DIR__ . '/../../web/forums/_topic-body.php';
$body_html = ob_get_clean();

$login_url = '/wp-login.php?redirect_to=' . rawurlencode('/hub/' . $topic['forum_slug'] . '/' . $topic['slug'] . '/');
?>
<div class="lg-fpd-op" data-topic-id="<?= $tid ?>" data-forum-id="<?= $fid ?>"
     data-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>"
     data-author-id="<?= (int)($topic['author_id'] ?? 0) ?>"
     data-can-post="<?= $viewer_logged_in ? '1' : '0' ?>">
  <div class="lg-dmodal__meta">
    <span class="fc-avatar"><?= $avatar ?></span>
    <div class="lg-dmodal__meta-id">
      <?php if ($aslug): ?>
        <a class="fc-author" href="/u/<?= rawurlencode((string)$aslug) ?>"><span class="fc-author__name"><?= htmlspecialchars($author) ?></span></a>
      <?php else: ?>
        <span class="fc-author"><span class="fc-author__name"><?= htmlspecialchars($author) ?></span></span>
      <?php endif; ?>
      <?php if ($rel !== ''): ?><time class="fc-time"><?= htmlspecialchars($rel) ?></time><?php endif; ?>
    </div>
  </div>
  <div class="lg-dmodal__body"><?= $body_html ?></div>
  <div class="lg-dmodal__opacts">
    <?php if ($viewer_logged_in): ?>
      <button type="button" class="lg-dmodal__act feed-card__reply-cta" data-frm-open
              data-topic-id="<?= $tid ?>" data-forum-id="<?= $fid ?>"
              data-topic-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>">&#8617; Reply</button>
    <?php else: ?>
      <a class="lg-dmodal__act lg-dmodal__signin" href="<?= htmlspecialchars($login_url, ENT_QUOTES) ?>">Sign in to reply</a>
    <?php endif; ?>
  </div>
</div>
