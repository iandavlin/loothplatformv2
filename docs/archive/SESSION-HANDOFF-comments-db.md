# Comments DB lane — session handoff (2026-06-05)

**Goal (briefing `docs/briefing-comments-db.md`):** pull content comments OUT of
WordPress into Postgres + serve them from the standalone archive-poc stack, so the
comment modal stops booting WP (~1–3s) and reads in ~50ms. Supersedes comments-lean.

## Status: DEV-COMPLETE + DEV-PROVEN ✅ (write path needs Ian/coordinator review before live)

### What shipped (all in canonical tree `/home/ubuntu/projects/archive-poc/`)
- **Schema** `sql/comments.pg.sql` → `discovery.comments` (applied on dev). Keyed
  `(post_type, item_id)` like `discovery.likes`; self-ref `parent_id` threading;
  `user_uuid` author (NULL+`author_name` for legacy anon); `legacy_wp_id` UNIQUE for
  idempotent backfill. Grants: looth-dev write, profile-app read. Reactions-on-
  comments fast-follow drops in against the surrogate `id` (NOT built, per Ian).
- **Read endpoint** `api/v0/comments.php` — archive-poc pool, **no WP boot**. Returns
  the brand-styled modal HTML (iframe), threaded, with the existing postMessage height
  handshake. **34ms cold / 20ms warm** (target was 50ms; old WP-frame ~1–3s).
- **Write endpoint** `api/v0/comment-post.php` — **looth-dev WP pool** (boots WP).
  GET → `{authenticated, nonce}`; POST → insert. Gate = WP login cookie (NOT /whoami;
  unbridged members are anon to /whoami but have a valid WP cookie). CSRF = WP nonce
  (`lg_comment`). IDOR-proof: author from the session, never the client. Mirrors
  bb-mirror `auth.php`.
- **Shared lib** `api/v0/_comments.php` — pdo, thread fetch, insert, + author-card
  resolution via `/profile-api/v0/users` (forwards the request cookie to clear the
  dev gate, like whoami; live has no gate).
- **Backfill** `bin/backfill-comments.php` — `sudo -u looth-dev php …` (fixture) /
  `--all` (cutover). 2-pass threading; entity-decoded legacy text. Dev fixture loaded
  = 79 rows over the 6 most-discussed items, 28 threaded.
- **Integration** `standalone/render.php` — modal `$commentsUrl` now points at the
  WP-free endpoint for covered types (loothprint / post-type-videos / post-imgcap),
  falls back to `?lg_comments=1` (WP frame) for the rest.
- **nginx (dev, live)** — `comments` on the archive-poc read regex; `comment-post` on
  the WP pool (looth-dev dev / looth-live deploy snippet). Dev wired + reloaded; repo
  deploy snippet updated.

### Verified on dev (real browser + curl)
- Modal opens, thread + threading + live author cards (avatar/slug) render. ~30–65ms.
- Logged-in admin (uid 4) posted a top-level comment + a threaded reply (bridged →
  live author card). Logged-out: GET→`{authenticated:false}`, POST→401.
- CSRF: bad nonce→403, cross-origin→403. Scope: `shop_order`→`bad_request`, fake
  item→`bad_target`. (Test rows deleted; fixture back to 79.)

## FLAGS for coordinator / Ian
1. **Write path runs on the WP pool, not the archive-poc pool** — deliberate: the
   "gate on the WP cookie" requirement can't be satisfied off-WP (profile-app /whoami
   reads its own JWT, returns anon+no wp_user_id for unbridged members). Read stays
   WP-free; only writes boot WP (infrequent; bb-mirror does the same). This is the one
   departure from "mirror like.php exactly."
2. **Scope gap:** Ian's 7 content types omit 4 stream-content types that also have
   comments — loothcuts(8) / useful_links(6) / member-benefit(5) / sponsor-post(2),
   ~21 dev rows. Left out pending a call; widening = extend `LG_COMMENTS_TYPES` (one
   array in `_comments.php`) + re-run backfill. Nothing else changes.
3. **nginx `comment-post` location is coordinator-owned for live** — flagged per
   protocol. Dev is wired; the deploy snippet has the parity block.
4. **Modal header count** still comes from the WP-baked `comments_count` at
   materialize. In sync with the store on dev; at cutover the full backfill keeps them
   aligned. Reading the count from the store is a small follow-on.

## NOT done (out of scope / follow-on)
- Stream card (`web/_render-stream-card.php`) still points at `?lg_comments=1`; the
  `/stream/` UI wiring is the stream lane's (store already backs both).
- `deploy/lg-comments-frame.php` retained — still the fallback for uncovered/WP-served
  types. Remove only when the store covers everything.
- Full backfill runs at **cutover** (`--all`), not dev.
