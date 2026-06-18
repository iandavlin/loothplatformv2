# Session handoff — comments + reactions lane (2026-06-06)

## Done this session (2026-06-06, committed NOT pushed)
- **`d0c8f0e`** — **REAL BB-reaction backfill landed on dev** (Ian GREENLIT). Self-contained
  (supersedes the bb-mirror staging table, which was removed): target derived from
  `wp_bp_activity.secondary_item_id` joined to OUR stores — `bbp_topic_create`→`forums.topic`
  →('topic',id); `new_blog_*`→`discovery.content_item`→(content_item.cpt,id). Slug from
  bb_reaction CPT, user via profile bridge, idempotent actor_key upsert, created_at kept,
  self-verifies (topic:69387 18==18 ✓). **Hub now renders REAL counts WP-free** (35/50
  first-page cards show chips). The Hot-ranking subqueries were handed to SURFACE (see
  prior entry) — both count ALL slugs.
  - **Accounting of the 6,413 activity reactions** (Hub = topics+content only): **1,901
    landed / 670 cards**. Not-landed: 2,914 orphaned (bp_activity row missing on dev —
    partial clone; expect more on live), 890 activity_update/share (no card), 231
    bbp_reply_create (no per-reply card), 468 activity_comment (no discovery.comments
    target), some new_blog_<type> CPTs not in our stores.
  - **DECISIONS**: (a) reply reactions — RESOLVED (Ian: yes). `ec9a30e` added 'reply' as a
    reactable target ('reply', reply_id); 184 landed across 118 replies. SURFACE renders the
    bar on reply markup. (b) activity_comment (468) + activity_update/share (890) — confirm
    drop (no Hub surface). (c) orphans (2,914) — verify they resolve on live at cutover.
  - Re-run anytime (idempotent); `--all` at cutover against complete live data.

- **`f8a063e`** — **migrate wp_bb_user_reactions → card_reactions** (the real BB-reaction
  backend, 6.8k rows; replaces BuddyBoss AJAX). `bin/migrate-bb-reactions.php` (runs as
  looth-dev, boots WP RO): reaction_id→slug from `bb_reaction` CPT menu_order × palette
  (covers all 7 used ids), user→wp_id+uuid bridge, idempotent actor_key upsert,
  created_at preserved, date_created ASC = latest-wins per card.
  - **CROSS-LANE DEP**: legacy reactions hang on `bp_activity` ids; the bp_activity→card
    map is **bb-mirror's to populate** in `discovery.bb_activity_target`
    (`sql/bb-activity-target.pg.sql`, bb-mirror INSERT / looth-dev SELECT). Migration
    consumes it; empty-map = clean no-op. **bb-mirror: populate this, then I run --all
    at cutover.** Derivable from `wp_bp_activity.type`: `new_blog_<cpt>`→(cpt,
    secondary_item_id); `bbp_topic_create`→('topic', id).
  - Palette custom images already vendored WP-free (web/reactions/*.png == bb_reaction
    source pngs by size).
  - **Dev-proven**: 3-row fixture map → 41 reactions landed on right loothprint cards
    w/ exact slug breakdowns; guard + skips correct. Test data cleaned (baseline = 2
    folded likes). Full run = cutover.
  - **OUT OF SCOPE (flagged)**: 468 `activity_comment` reactions (BB activity-comment ≠
    discovery.comments) + ~888 `activity_update`/`share` (no Hub card).
- **Hot-ranking join handed to SURFACE** (no engine code; their `_feed.php` change):
  replace frozen `like_count` in the Hot `union_order_by` with a live count off
  card_reactions — `LEFT JOIN LATERAL (SELECT COUNT(*) FROM discovery.card_reactions cr
  WHERE cr.post_type=<branch post_type> AND cr.item_id=<branch item_id>) rx ON true`,
  then ORDER BY uses `(reply_count + rx.cnt)`. Ranking-only, not cut-blocking.

- **`6210149`** — bugfix: count helper queried `FROM card_reactions` UNqualified
  (only resolves with search_path=discovery, which the bb-mirror feed `$db` does NOT
  set). Live Hub count read threw → swallowed by `_feed.php` try/catch → every card
  rendered 0 reaction chips. Qualified to `discovery.card_reactions` (safe on all
  pools). Hub now renders live chips. **Feed "like number" is now LIVE** (the SURFACE
  watch-out): the stale `content_item.like_count` heart was dropped (SURFACE's
  `.fc-actions` restructure, `69456c7`); likes show as the live 👍 chip from
  card_reactions. Open nit flagged to SURFACE: dead `$c_likes` var at _feed.php:913.

- **`d95ce1d` + `8b9089f`** — **card-reactions engine** (reactions on feed cards =
  topics + content). Mirrors comment_reactions but keyed to a content target
  `(post_type,item_id)`. RECONCILE: likes FOLD into it (slug='like') — one store, not
  two. Accepted the SURFACE lane's `actor_key` design.
  - `sql/card-reactions.pg.sql` — `discovery.card_reactions`, normalized
    `actor_key = COALESCE(uuid,'wp:'||wp_id)`, `UNIQUE(post_type,item_id,actor_key)`,
    `CHECK(wp_id OR uuid)`. Applied on dev; 2 like rows migrated → slug='like'.
    Grants: looth-dev write; bb-mirror + profile-app read (re-apply at cutover).
  - `api/v0/_reactions.php` — **count-read contract for SURFACE**:
    `lg_card_reactions_for_items($pdo,$items)` → `"pt:id"=>[slug=>count]`;
    `like_count = result["pt:id"]['like'] ?? 0`. Plus `_mine` + `_set`.
  - `api/v0/card-react.php` — looth-dev pool (boots WP). `GET ?items=pt:id,pt:id` →
    nonce + my_reactions + counts; `POST {post_type,item_id,slug,_wpnonce}` → toggle.
    Gate = WP login cookie (unbridged-safe), nonce 'lg_card_react', IDOR-proof.
  - `api/v0/_likes.php` — repointed `lg_likes_toggle/_counts` → card_reactions
    (slug='like'); contract unchanged. discovery.likes kept read-only (revert).
  - nginx: live snippet `/archive-api/v0/card-react` route + reload; deploy copy too.
  - **Dev-proven e2e** (real nginx+FPM, unbridged member 1881 → actor_key wp:1881):
    GET→nonce; POST→row+counts; my_reactions round-trips; toggle off; bad-nonce 403;
    anon 401; bad type/slug 400. bb-mirror SELECT ok, write denied. Fold semantics
    (wow→like switch = one row) verified.
  - **FLAGGED (sequence separately)**: the feed's like number is a DENORMALIZED
    `content_item.like_count` (materializer does NOT recompute it from likes), so
    repointing the SSR like read to card_reactions is a separate SURFACE/discovery
    step. discovery.likes retirement = coordinator's call (held for revert safety).


- **`bf2b4e4`** — widened `LG_COMMENTS_TYPES` to add `loothcuts`, `useful_links`,
  `member-benefit` (queue item 1). **Ian approved 6/6** (sponsor-post deliberately
  excluded — ads aren't discussion). Read/write endpoints are type-agnostic, so the
  array is the only switch. Same commit hardens the dev **fixture** backfill to
  guarantee one representative item per covered type (so widened types are testable
  on dev even when they don't crack the global top-N).
- Confirmed the GREENLIT **reactions-on-comments** build (`8ec8ba4`+`0e6ce62`) was
  already complete & dev-proven; palette helper is an exact match to the approved
  `tools/reaction-assets/palette.json` (3 custom pngs + 4 unicode emoji). No rebuild.
- **`a3cb6cc`** — bugfix (Ian reported 6/6): reaction picker was showing permanently on
  every comment. It was coded click-to-open but `.lgc-rx-palette{display:flex}` overrode
  the UA `[hidden]{display:none}`. Added `.lgc-rx-palette[hidden]{display:none}` so the
  `hidden` attr wins. CDP-verified: default `display:none`, click ☺+ → `display:flex`.
- **Verified**: re-ran `sudo -u looth-dev php bin/backfill-comments.php`; store now
  covers all 10 types incl. loothcuts(6)/useful_links(2)/member-benefit(3);
  `lg_comments_count()` returns live counts for each. `php -l` clean on both files.

---

# Session handoff — comments + reactions lane (2026-06-05)

Consolidated lane owning content comments + reactions/likes backends. Both backends
were already dev-proven (comments-db 99c00cb, stream reactions 049e7d9); this session
cleared two open follow-ons. Predecessor detail: `SESSION-HANDOFF-comments-db.md`.

## Done this session (committed, NOT pushed)
- **`dd248c5`** — committed the cross-cutting `GRANT SELECT ON comments TO "bb-mirror"`
  into `archive-poc/sql/comments.pg.sql` (queue item 2). Grant was already applied on
  dev; this records it for the cutover grant list. **Flag for cutover grant list.**
- **`3dfda18`** — modal/badge count now reads LIVE from `discovery.comments` instead of
  the WP-baked `post_context.comments_count` (queue item 3). In
  `standalone/render.php`: for covered types over HTTP, call
  `lg_comments_count(lg_comments_pdo(), $postType, $itemId)` with baked-value fallback
  on error. CLI/materialize path unchanged. The baked value only re-bakes on content
  change, so it drifted as members commented; badge is now always current.

## Verified (how)
- `php -l standalone/render.php` clean.
- `lg_comments_count()` as the real `archive-poc` FPM user returns the live store count
  (20 for post-type-videos/14163).
- **End-to-end through real nginx+FPM**: inserted one throwaway comment on 14163 WITHOUT
  re-materializing → rendered badge moved `💬 20 → 21` (blob baked value stayed 20),
  back to `20` after deleting the test row. Proves the badge tracks the store, not the
  stale bake.

## Done this session — reactions-on-comments (queue item 4, Ian greenlit 2026-06-05)
Built the full comment-reactions feature with the Ian-approved 7-reaction BuddyBoss
palette (like · ouch · wow · lol · shop · take-my-money · brain). Commits `8ec8ba4`
(store+palette+assets) and `0e6ce62` (modal UI + write endpoint + routing).

- **Store**: `discovery.comment_reactions` (`sql/comment-reactions.pg.sql`, applied on
  dev), keyed `(comment_id, user_wp_id)` — one reaction per user per comment, stored
  BY SLUG. Identity = **WP user id** (matches the comment WRITE gate so unbridged
  members can react), bridged `user_uuid` captured too. A comment isn't a content
  item → 'like' on a comment lives here, NOT in content `discovery.likes`.
- **Helpers** in `_comments.php`: `lg_reactions_palette()/_slugs()` (single source of
  truth), `lg_reactions_for_comments()` (WP-free counts), `lg_reactions_mine()`,
  `lg_reactions_set()` (idempotent toggle/switch/off).
- **Read** (`comments.php`, WP-free): per-comment reaction bar — count chips + a
  7-reaction picker. Counts public; viewer's own pick highlighted client-side.
- **Write** (`comment-react.php`, NEW, WP pool): POST toggle, WP-cookie gate +
  'lg_comment' nonce + same-origin + server-derived identity (IDOR-proof).
- **Viewer state**: `comment-post.php` GET also returns `my_reactions{cid:slug}`.
- **Assets**: 3 pngs in `web/reactions/` served WP-free at `/archive-poc/reactions/`.
- **Routing**: live dev nginx (`/etc/nginx/snippets/strangler-archive-poc.conf`) got
  the comment-react route + reload; the in-repo **deploy** copy
  (`archive-poc/deploy/archive-poc.nginx-snippet.conf`, looth-live pool) updated to
  match. NOTE: `platform/nginx/strangler-archive-poc.conf` is badly stale (May 29,
  predates most current routes) — pre-existing drift, NOT reconciled here (out of lane).

**Verified end-to-end on dev** (real nginx+FPM, minted WP session): GET→nonce +
my_reactions; POST react→DB row + counts; my_reactions round-trips; same-slug toggles
off; bad nonce→403; anon→401; SSR chips (emoji + image glyphs) render with live counts.
All test rows + the minted session cleaned up.

**DECISION-NEEDED**: on a COMMENT, the `like` slug is stored in comment_reactions (a
comment can't key into content `discovery.likes`). If you wanted comment-likes unified
with content likes, that's a different schema — flag if so. Also: reaction writes boot
WP per click (consistent with comment posting); a WP-free fast-path is a possible perf
follow-up if reactions get heavy.

## Still open (gated — did NOT touch)
1. ~~**Widen comment coverage**~~ — DONE 6/6 (`bf2b4e4`): added loothcuts/useful_links/
   member-benefit; sponsor-post stays out (Ian's call). Full `--all` at cutover picks up
   all rows; dev fixture now seeds one item per type.
5. **Stream card** `web/_render-stream-card.php` still points at `?lg_comments=1`; store
   backs it but wiring is stream/hub UI's call — coordinate before touching.
6. **Full backfill (`--all`)** runs at cutover, not dev. Dev keeps the small fixture.
   (Reactions have no backfill — net-new only.)

## Rules in force
WP-cookie gate on the write path is the agreed model — don't "fix" to /whoami (breaks
unbridged members). Edit only comments/reactions-lane code + this handoff; other lanes'
docs read-only; route cross-lane via Ian. Commit ≠ push (coordinator + Ian gate pushes).
