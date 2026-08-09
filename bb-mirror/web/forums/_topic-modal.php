<?php
/**
 * _topic-modal.php — SERVER-RENDER the discussion modal, open, over the hub.
 *
 * WHY (Ian, 2026-08-09): "we need google to go to the modals on the hub page."
 * / "I don't want people landing on the pages we are mirroring for hub. They
 * look aweful." / "does this look like the fucking hub with a fucking modal
 * open?"
 *
 * e9ddc28 listed 1,352 discussions in the sitemap. Every URL is the canonical
 * permalink /hub/<forum>/<topic>/, which index.php routed to _single-topic.php
 * — the legacy standalone layout. The sitemap turned a fallback page into the
 * front door, so the fix goes on the ROUTE.
 *
 * ── THE ONE THING THAT MAKES THIS HARD ───────────────────────────────────────
 * The obvious implementation is a one-line route change: send `case 2` at
 * _feed.php with ?topic= set and let forums.js §4f open the modal. That renders
 * the right picture for a human and DESTROYS the sitemap work, silently.
 * §4f's cold path (fetchStandalone) fetches the discussion AFTER load, so the
 * served HTML a crawler reads would contain an open, empty modal. There is no
 * visible symptom — the page looks perfect to everyone who runs JS.
 *
 * So the contract this file exists to keep is:
 *      THE DISCUSSION'S TEXT IS IN THE SERVER-RENDERED HTML.
 * OP body and every reply, in `curl` output, with no JS and no cookies.
 * tools/gates/hub-topic-landing-gate.py asserts exactly that, from the sitemap's
 * own URLs, against ground truth taken from the fragment endpoints.
 *
 * ── ONE IMPLEMENTATION, NOT A SECOND RENDERER ────────────────────────────────
 * Everything here is CAPTURED from the endpoints the modal already drives —
 * _topic-body.php (?body=) and _topic-replies.php (?replies=) — rather than
 * re-implemented. That is deliberate and load-bearing twice over:
 *   1. The modal's JS path and this server path cannot drift into rendering the
 *      same discussion differently, which is what would make "JS takes over
 *      without re-fetching" quietly wrong.
 *   2. Those endpoints carry the visibility masks (is_anon → "Anonymous",
 *      member-only author → "Private member", logged-out contact scrub). A
 *      hand-rolled second renderer is exactly how a mask gets left behind —
 *      audit H6 (STRANGLER-FRESH-AUDIT-2026-06-13) was that bug on this very
 *      permalink.
 *
 * The lookup mirrors api/v0/topic.php's, which mirrors _single-topic.php's:
 * JOIN on BOTH slugs (two forums share slug='finish', so a forum-first lookup is
 * non-deterministic), public forums only, publish/closed only.
 *
 * Reads are not tier-gated; forum visibility='public' is the only gate.
 */

declare(strict_types=1);

require_once __DIR__ . '/_reply-render.php';
require_once __DIR__ . '/../_anon-scrub.php';

if (!function_exists('lg_topic_modal_lookup')) {
    /**
     * Resolve {forum-slug, topic-slug} → the topic row, masks already applied.
     *
     * Returns null when there is no such PUBLIC topic. The caller decides what
     * that means (404, or the stale-deep-link rescue below) — this function
     * never emits output and never exits, because it runs mid-page-build where
     * an exit would ship a half-rendered document.
     */
    function lg_topic_modal_lookup(PDO $db, string $forum_slug, string $topic_slug): ?array
    {
        if ($forum_slug === '' || $topic_slug === '') return null;

        $q = $db->prepare("
            SELECT t.id, t.slug, t.title, t.author_name, t.author_slug, t.author_id,
                   t.is_anon::int                              AS is_anon,
                   COALESCE(p.discussion_visibility, 'member') AS discussion_visibility,
                   p.avatar_url,
                   t.created_at, t.status, t.reply_count,
                   f.id    AS forum_id,
                   f.slug  AS forum_slug,
                   f.title AS forum_title
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
        if (!$topic) return null;

        // Author-identity masks BEFORE any identity render — the same masks the
        // feed, the permalink and api/v0/topic.php apply, in the same order.
        lg_bb_mirror_mask_anon($topic, lg_bb_mirror_can_moderate());
        lg_bb_mirror_mask_visibility($topic, lg_bb_mirror_can_post());

        return $topic;
    }
}

if (!function_exists('lg_topic_modal_rescue_slug')) {
    /**
     * Stale-deep-link rescue (HK-017 / GH #48), lifted intact from
     * _single-topic.php so retiring that file does not retire the behaviour.
     *
     * A re-categorised topic (or a link minted with the wrong forum slug) used
     * to dead-end. On EXACTLY ONE live match by topic slug alone, return its
     * real forum slug so the caller can 301. Ambiguous slugs return null and
     * stay a 404 — guessing which one the visitor meant would be worse.
     */
    function lg_topic_modal_rescue_slug(PDO $db, string $topic_slug): ?string
    {
        if ($topic_slug === '') return null;
        $r = $db->prepare("
            SELECT f.slug AS forum_slug
              FROM forums.topic t
              JOIN forums.forum f ON f.id = t.forum_id
             WHERE t.slug = :ts
               AND t.status IN ('publish', 'closed')
               AND f.visibility = 'public'
             LIMIT 2
        ");
        $r->execute([':ts' => $topic_slug]);
        $homes = $r->fetchAll(PDO::FETCH_COLUMN);
        return count($homes) === 1 ? (string)$homes[0] : null;
    }
}

if (!function_exists('lg_topic_modal_route')) {
    /**
     * The /hub/<forum>/<topic>/ route, minus the rendering.
     *
     * Returns the resolved topic row, or null when the caller must stop because
     * a 301 or a 404 has already been issued. Every exit path _single-topic.php
     * owned lives here now, so retiring that file retires no behaviour:
     *   • wrong-but-unambiguous forum slug → 301 to the canonical path
     *   • genuinely gone                   → 404, status set BEFORE any output
     *
     * That ordering is not stylistic. _single-topic.php originally emitted its
     * chrome first and called http_response_code() after, which PHP ignores once
     * output has started — so a missing topic shipped HTTP 200 for a while. The
     * status goes first here for the same reason.
     */
    function lg_topic_modal_route(PDO $db, string $forum_slug, string $topic_slug): ?array
    {
        $topic = lg_topic_modal_lookup($db, $forum_slug, $topic_slug);
        if ($topic) return $topic;

        $home = lg_topic_modal_rescue_slug($db, $topic_slug);
        if ($home !== null && $home !== $forum_slug) {
            header('Location: ' . LG_BB_MIRROR_PUBLIC_PATH . '/' . rawurlencode($home)
                 . '/' . rawurlencode($topic_slug) . '/', true, 301);
            return null;
        }

        http_response_code(404);
        // Loaded HERE, not at the top of this file: the happy path hands off to
        // _feed.php, which requires chrome itself. Verified to emit 0 bytes at
        // include time, so pulling it in after the status line is safe.
        require_once __DIR__ . '/../_chrome.php';
        bb_mirror_chrome_header('Topic not found');
        echo '<div class="page"><div class="bb-mirror__empty" style="text-align:center;padding:48px 20px">'
           . '<p style="font-size:17px;margin:0 0 6px">Topic not found</p>'
           . '<p style="margin:0 0 18px;color:var(--lg-mute,#6b6f6b)">It may have been removed, renamed, or the link is out of date.</p>'
           . '<a href="' . htmlspecialchars(LG_BB_MIRROR_PUBLIC_PATH, ENT_QUOTES) . '/"'
           . ' style="display:inline-block;background:var(--lg-sage,#87986a);color:#fff;border-radius:10px;'
           . 'padding:10px 22px;font-weight:600;text-decoration:none">Browse the Hub</a>'
           . '</div></div>';
        bb_mirror_chrome_footer();
        return null;
    }
}

if (!function_exists('lg_topic_modal_capture')) {
    /**
     * Run one of the fragment endpoints and capture its HTML.
     *
     * $_GET is saved and restored around the call: these endpoints read their
     * arguments from $_GET, and _feed.php is still mid-build with its own $_GET
     * (forum_slug, sort, the hub filters) that it reads AFTER this returns.
     * Leaking `body`/`replies` into that would re-scope the feed under the
     * caller's feet.
     */
    function lg_topic_modal_capture(string $file, array $get): string
    {
        $saved      = $_GET;
        $saved_embed = $GLOBALS['lg_fragment_embedded'] ?? null;
        $_GET  = $get;
        // Silences the endpoint's http_response_code()/error text — see
        // lg_fragment_embedded() in _reply-render.php for why a fragment must
        // not be able to 404 the page it is embedded in.
        $GLOBALS['lg_fragment_embedded'] = true;
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            $_GET = $saved;
            $GLOBALS['lg_fragment_embedded'] = $saved_embed;
            return '';
        }
        $html = ob_get_clean();
        $_GET = $saved;
        $GLOBALS['lg_fragment_embedded'] = $saved_embed;
        return $html !== false ? $html : '';
    }
}

if (!function_exists('lg_topic_modal_html')) {
    /**
     * The whole modal, populated and OPEN, ready to drop at the end of <body>.
     *
     * Markup matches §4e's ensure() + open() exactly (forums.js) — same ids,
     * same classes, same data-* — because forums.js ADOPTS this node instead of
     * building its own. Any drift here shows up there as a modal that opens
     * empty or a control that no longer binds, so treat the two as one contract.
     *
     * data-lg-ssr="1" is what tells §4f "already open, already populated — seat
     * the history entries but do NOT re-open, and do NOT re-fetch what is in
     * front of you". That attribute is the entire no-refetch contract.
     *
     * MUST be called BEFORE bb_mirror_chrome_header() emits anything: the
     * captured endpoints call header(), which is a warning (and, with
     * display_errors on, a corrupted page) once output has started.
     */
    function lg_topic_modal_html(PDO $db, array $topic): string
    {
        $tid   = (int)$topic['id'];
        $fid   = (int)$topic['forum_id'];
        $title = (string)$topic['title'];

        // OP body: ?body= verbatim — mention-resolve, attachment gallery,
        // logged-out contact scrub. It re-checks forum visibility itself.
        $body_html = lg_topic_modal_capture(
            __DIR__ . '/_topic-body.php',
            ['body' => (string)$tid]
        );

        // The WHOLE thread, not the first page of 5. `all=1` is what the §4e
        // modal ends up showing anyway after drain() walks every page, so
        // rendering it once server-side is both the stronger SEO position and
        // strictly less work than the drain it replaces. Cheap at this corpus:
        // median 4 replies, p95 12, max 35 (measured 2026-08-09).
        $replies_html = lg_topic_modal_capture(
            __DIR__ . '/_topic-replies.php',
            ['replies' => (string)$tid, 'all' => '1']
        );

        // OP reaction bar — the SAME .fc-actions .fcr surface the feed card and
        // the standalone page emit, through the shared count contract + shared
        // renderer. §4e's open() gets this by CLONING the card's bar; a landing
        // has no card to clone from, so without it a visitor arriving from a
        // search result could read the discussion and not react to it, while the
        // same discussion opened from the feed is fully reactable. forums.js §4d
        // wires .fcr generically, so this needs no JS of its own.
        // Try/catch so an unreadable store degrades to "no bar", never to no page.
        $op_rx_counts = [];
        try {
            require_once __DIR__ . '/../../../archive-poc/api/v0/_reactions.php';
            $rx_map       = lg_card_reactions_for_items($db, [['post_type' => 'topic', 'item_id' => $tid]]);
            $op_rx_counts = $rx_map['topic:' . $tid] ?? [];
        } catch (\Throwable $e) {
            $op_rx_counts = [];
        }

        $viewer_logged_in = lg_bb_mirror_can_post();
        $author = $topic['author_name'] ?: 'Anonymous';
        $aslug  = $topic['author_slug'] ?? null;
        $rel    = $topic['created_at'] ? feed_rel_time((string)$topic['created_at']) : '';
        $avatar = bb_mirror_avatar($author, $aslug ?: $author, 38, $topic['avatar_url'] ?? null);
        $esc    = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        $permalink = LG_BB_MIRROR_PUBLIC_PATH . '/' . rawurlencode((string)$topic['forum_slug'])
                   . '/' . rawurlencode((string)$topic['slug']) . '/';
        $share     = LG_BB_MIRROR_PUBLIC_PATH . '/?topic='
                   . rawurlencode($topic['forum_slug'] . '/' . $topic['slug']);
        $login_url = '/wp-login.php?redirect_to=' . rawurlencode($permalink);

        ob_start();
        ?>
<div id="lg-dmodal" data-topic-id="<?= $tid ?>" data-lg-ssr="1"
     data-forum-id="<?= $fid ?>" data-author-id="<?= (int)($topic['author_id'] ?? 0) ?>"
     <?php /* data-title mirrors api/v0/topic.php's .lg-fpd-op so ONE builder in
              forums.js (buildCardFromOp) reads both shapes. */ ?>
     data-title="<?= $esc($title) ?>"
     data-lg-forum-slug="<?= $esc($topic['forum_slug']) ?>" data-lg-topic-slug="<?= $esc($topic['slug']) ?>"
     data-lg-share-url="<?= $esc($share) ?>" data-lg-permalink="<?= $esc($permalink) ?>">
  <div class="lg-dmodal__back" data-dm-close></div>
  <div class="lg-dmodal__panel lg-dmodal--m" role="dialog" aria-modal="true" aria-label="Discussion">
    <header class="lg-dmodal__head">
      <h2 class="lg-dmodal__title"><?= $esc($title) ?></h2>
      <?php /* Follow opt-ins are gated at BUILD time, exactly as §4e gates them:
               the header persists across opens, so a hidden node here would still
               be a node that retargets and hydrates. */ ?>
      <?php if (lg_thread_follow_enabled()): ?>
        <button type="button" class="lg-dmodal__notify" data-follow="notify" data-topic-id="<?= $tid ?>"
                aria-pressed="false" aria-label="Notify me about new replies" title="Notify me about new replies">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        </button>
        <button type="button" class="lg-dmodal__email" data-follow="email" data-topic-id="<?= $tid ?>"
                aria-pressed="false" aria-label="Email me about new replies" title="Email me about new replies">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/></svg>
        </button>
      <?php endif; ?>
      <button type="button" class="lg-dmodal__size" aria-label="Modal size" title="Modal size">M</button>
      <button type="button" class="lg-dmodal__x" data-dm-close aria-label="Close">&times;</button>
    </header>
    <div class="lg-dmodal__scroll feed-page">
      <div class="lg-dmodal__op">
        <div class="lg-dmodal__meta">
          <span class="fc-avatar"><?= $avatar ?></span>
          <div class="lg-dmodal__meta-id">
            <?php if ($aslug): ?>
              <a class="fc-author" href="/u/<?= rawurlencode((string)$aslug) ?>"><span class="fc-author__name"><?= $esc($author) ?></span></a>
            <?php else: ?>
              <span class="fc-author"><span class="fc-author__name"><?= $esc($author) ?></span></span>
            <?php endif; ?>
            <?php if ($rel !== ''): ?><time class="fc-time"><?= $esc($rel) ?></time><?php endif; ?>
          </div>
        </div>
        <div class="lg-dmodal__body"><?= $body_html ?></div>
        <div class="lg-dmodal__opacts">
          <?php /* First child, matching open()'s insertBefore(opBar, firstChild). */
                if (function_exists('feed_reactions_bar')) feed_reactions_bar('topic', $tid, $op_rx_counts); ?>
          <?php if ($viewer_logged_in): ?>
            <button type="button" class="lg-dmodal__act feed-card__reply-cta" data-frm-open
                    data-topic-id="<?= $tid ?>" data-forum-id="<?= $fid ?>"
                    data-topic-title="<?= $esc($title) ?>">&#8617; Reply</button>
          <?php else: ?>
            <a class="lg-dmodal__act lg-dmodal__signin" href="<?= $esc($login_url) ?>">Sign in to reply</a>
          <?php endif; ?>
          <button type="button" class="lg-dmodal__act lg-dmodal__share" data-share-topic
                  data-share-url="<?= $esc($share) ?>" data-share-title="<?= $esc($title) ?>" aria-label="Share">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg> Share
          </button>
        </div>
      </div>
      <div class="lg-dmodal__replies">
        <?php if (trim(strip_tags($replies_html)) !== '' || str_contains($replies_html, 'reply-stub')): ?>
          <div class="feed-card__replies-full lg-rshow lg-dmodal__thread"><?= $replies_html ?></div>
        <?php else: ?>
          <div class="lg-dmodal__note">No replies yet. Be the first to reply.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
        <?php
        return (string)ob_get_clean();
    }
}
