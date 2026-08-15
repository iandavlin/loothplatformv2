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
 * the /hub discussion landing, so the modal == the page. Visibility is enforced
 * SERVER-SIDE exactly as web/forums/_topic-modal.php does (both inherited these
 * rules from the retired _single-topic.php):
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
//    forum-first lookup is non-deterministic). Mirrors _topic-modal.php. ───────
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

// ── ?reply_context= — the NOTIFICATION QUOTE (notif-quickreply lane, Ian 7/30) ──
//    Tapping a notification opens a reply modal that quotes the reply which rang
//    you. That quote is ONE reply's text, and nothing in the stack could return it:
//    GET /bb-mirror-api/v0/reply?reply_id= gives media and nothing else, and its
//    ?topic_id= sibling is author-gated for editing.
//
//    IT LIVES HERE, and not in a new endpoint, for two reasons that are not style:
//      1. EVERY .php under /bb-mirror-api/v0/ needs its OWN nginx `location` block
//         — there is no catch-all — so a new file would fall through the parent
//         `alias` and be served as PHP SOURCE. That is the /archive-api/v0/ leak
//         exactly. Adding one also needs an nginx reload, which a `git pull` does
//         not do, so branch and deploy would have to land in the same window.
//      2. This endpoint ALREADY enforces the four rules a quote must obey — public
//         forum only, publish/closed only, anon masked, member-only masked. Writing
//         a second reader means re-deriving all four, and getting one wrong leaks
//         precisely what they exist to protect. The gate above has already run.
//
//    reply_context=<id> quotes that reply; reply_context=0 quotes the OPENING POST.
//    The zero case is REAL, not defensive: an @mention in a brand-new discussion
//    rings before any reply exists (lg_notify_on_topic passes no reply id), and dev2
//    still holds a pre-anchor row (id 471, 2026-07-13) whose link has no &reply= at
//    all. Both arrive here as 0 and both want the OP.
//
//    Returns a FRAGMENT, like the rest of this file. Deliberately NOT the member's
//    own post above the reply — Ian picked layout A over B on 7/30: "do not render
//    the member's own post for context".
//    GATED ON THE FLAG, not merely uncalled. With LG_NOTIF_QUICKREPLY_ENABLED off
//    this branch does not exist as far as any caller is concerned — the request falls
//    through to the ordinary OP fragment, byte-for-byte what it returns today. "No
//    client sends the parameter" is not a gate; this is.
if (isset($_GET['reply_context']) && lg_notif_quickreply_enabled()) {
    $rc_id = (int)$_GET['reply_context'];
    $rc_author = $rc_slug = $rc_avatar_url = null;
    $rc_html = '';
    $rc_anon = 0;
    $rc_vis  = 'member';
    $rc_when = '';

    if ($rc_id > 0) {
        // Scoped to THIS topic on purpose: the topic came from the slugs, and the
        // slugs went through the visibility gate. Taking a bare reply id would let a
        // caller pair a public topic's slugs with a hidden forum's reply id and read
        // straight past the gate that just ran.
        $rq = $db->prepare("
            SELECT r.id, r.content_html, r.created_at, r.is_anon::int AS is_anon,
                   r.author_name, p.slug AS author_slug, p.avatar_url,
                   COALESCE(p.discussion_visibility, 'member') AS discussion_visibility
              FROM forums.reply r
              LEFT JOIN forums.person p ON p.id = r.author_id
             WHERE r.id = :rid AND r.topic_id = :tid AND r.status = 'publish'
             LIMIT 1
        ");
        $rq->execute([':rid' => $rc_id, ':tid' => $tid]);
        $rrow = $rq->fetch();
        // A deleted / moved / mismatched reply falls back to the OP rather than
        // 404ing the whole modal: the notification is still a true record of
        // something that happened, and the discussion it points at still exists.
        if ($rrow) {
            lg_bb_mirror_mask_anon($rrow, $can_mod);
            lg_bb_mirror_mask_visibility($rrow, $viewer_logged_in);
            $rc_author     = $rrow['author_name'] ?: 'Anonymous';
            $rc_slug       = $rrow['author_slug'] ?? null;
            $rc_avatar_url = $rrow['avatar_url'] ?? null;
            $rc_html       = (string)$rrow['content_html'];
            $rc_when       = $rrow['created_at'] ? feed_rel_time((string)$rrow['created_at']) : '';
        } else {
            $rc_id = 0;
        }
    }

    if ($rc_id === 0) {
        // The OP as the quote. $topic is already masked by the two calls above, so
        // the identity fields are taken from it rather than re-read. Only the body
        // needs a fetch: the lookup query above deliberately does not select
        // content_html (the OP path renders through _topic-body.php instead), and
        // widening a query the whole endpoint depends on to serve one branch would
        // make every OP request pay for it.
        $bq = $db->prepare("SELECT content_html FROM forums.topic WHERE id = :tid LIMIT 1");
        $bq->execute([':tid' => $tid]);
        $rc_author     = $author;
        $rc_slug       = $aslug;
        $rc_avatar_url = $topic['avatar_url'] ?? null;
        $rc_html       = (string)($bq->fetchColumn() ?: '');
        $rc_when       = $rel;
    }

    // Same snippet formatter the feed teasers use, so a quoted reply reads exactly
    // as it does on the card: mentions resolved to current display names, markup
    // preserved, length capped. 420 chars is ~4 lines at phone width; the client
    // clamps visually and offers "Show more".
    $rc_snip = bb_mirror_format_snippet($rc_html, 420, $db, true);
    // Logged-out contact scrub, matching _topic-body.php. A signed-out caller can
    // reach this endpoint directly even though only a signed-in member ever sees
    // the modal, so the scrub is applied on the same rule as everywhere else.
    if (!$viewer_logged_in) $rc_snip = lg_scrub_anon_contacts($rc_snip);
    $rc_avatar = bb_mirror_avatar((string)$rc_author, (string)($rc_slug ?: $rc_author), 34, $rc_avatar_url);
    $rc_more   = mb_strlen(trim(strip_tags($rc_html))) > 420;
    ?>
<div class="lg-nqr-quote" data-kind="<?= $rc_id > 0 ? 'reply' : 'topic' ?>"
     data-topic-id="<?= $tid ?>" data-forum-id="<?= $fid ?>"
     data-reply-id="<?= $rc_id ?>"
     data-topic-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>"
     data-author="<?= htmlspecialchars((string)$rc_author, ENT_QUOTES) ?>"
     data-can-post="<?= $viewer_logged_in ? '1' : '0' ?>"
     data-more="<?= $rc_more ? '1' : '0' ?>">
  <?php /* WHICH DISCUSSION. Added after looking at a real screenshot rather than at
           the assertions, which were all green: the modal showed who replied and what
           they said, and never once named the thread. Ian has already been burned by
           exactly this ("the modal that never says which discussion"), so it is a
           known defect class here, not a nicety. */ ?>
  <p class="lg-nqr-quote__where"><?= htmlspecialchars($title) ?></p>
  <div class="lg-nqr-quote__q">
    <div class="lg-nqr-quote__head">
      <span class="lg-nqr-quote__avi"><?= $rc_avatar ?></span>
      <span class="lg-nqr-quote__who"><?= htmlspecialchars((string)$rc_author) ?></span>
      <?php if ($rc_when !== ''): ?><time class="lg-nqr-quote__time"><?= htmlspecialchars($rc_when) ?></time><?php endif; ?>
    </div>
    <div class="lg-nqr-quote__body"><?= $rc_snip !== '' ? $rc_snip : '<em class="lg-nqr-quote__empty">No text — open the discussion to see it.</em>' ?></div>
  </div>
</div>
<?php
    exit;
}

// ── OP body — reuse _topic-body.php verbatim (mention-resolve + attachments +
//    logged-out contact scrub), captured. Re-checks forum visibility itself. ──
$_GET['body'] = (string)$tid;
ob_start();
require __DIR__ . '/../../web/forums/_topic-body.php';
$body_html = ob_get_clean();

$login_url = '/wp-login.php?redirect_to=' . rawurlencode('/hub/' . $topic['forum_slug'] . '/' . $topic['slug'] . '/');

// ── ?withrx=1 — include the OP reaction bar (hub-seo-landing lane, 2026-08-09) ─
// OPT-IN, and off by default, so every existing caller gets byte-identical HTML.
//
// forums.js §4f's cold deep-link path used to scrape the STANDALONE PAGE, which
// renders `.fc-actions .fcr` precisely so the synthetic card could clone it into
// the modal. Repointing that path here (114KB of page → 2KB of fragment, and the
// step that lets _single-topic.php be retired) would have silently dropped the
// OP's reactions on exactly that path.
//
// It is a parameter rather than the default because the OTHER consumer — the
// front page's fp-discuss.js — runs without forums.js, so a reaction bar there
// would render a control with nothing wired to it. A dead button is worse than
// no button, and that is the defect class Ian's phone keeps finding.
$with_rx = ($_GET['withrx'] ?? '') === '1';
$op_rx_counts = [];
if ($with_rx) {
    try {
        require_once __DIR__ . '/../../../archive-poc/api/v0/_reactions.php';
        $rx_map       = lg_card_reactions_for_items($db, [['post_type' => 'topic', 'item_id' => $tid]]);
        $op_rx_counts = $rx_map['topic:' . $tid] ?? [];
    } catch (\Throwable $e) {
        $op_rx_counts = [];   // unreadable store → no bar, never no fragment
    }
}
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
    <?php /* Folded into the SAME php tag as the branch below, deliberately. On
             its own line its leading indent was still emitted with withrx off,
             which shifted every existing caller's output by four spaces — a
             whitespace-only diff is still not byte-identical. */
          if ($with_rx && function_exists('feed_reactions_bar')) feed_reactions_bar('topic', $tid, $op_rx_counts);
          if ($viewer_logged_in): ?>
      <button type="button" class="lg-dmodal__act feed-card__reply-cta" data-frm-open
              data-topic-id="<?= $tid ?>" data-forum-id="<?= $fid ?>"
              data-topic-title="<?= htmlspecialchars($title, ENT_QUOTES) ?>">&#8617; Reply</button>
    <?php else: ?>
      <a class="lg-dmodal__act lg-dmodal__signin" href="<?= htmlspecialchars($login_url, ENT_QUOTES) ?>">Sign in to reply</a>
    <?php endif; ?>
  </div>
</div>
