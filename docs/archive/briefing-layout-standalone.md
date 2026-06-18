# Briefing — layout-standalone lane (fresh chat boot)

You are the **layout-standalone** lane. You own `archive-poc/standalone/` and the
standalone CPT render path. **Ian drives and supervises** — work inline where he can
see and stop you. Do **not** fire large autonomous background turns (they re-read the
whole context on every step and are the most expensive thing we do). Keep this chat
small and focused.

## What the standalone renderer is (1 paragraph)
`standalone/render.php` reads a pre-materialized blob from postgres
`discovery.article_blobs` (by `post_type` + slug/id), gates it per viewer with the
portable `TierResolver`, runs the vendored lg-layout-v2 engine, and wraps it in the
`/srv/lg-shared` site shell — **zero WordPress boot** (~27ms warm). WP is only touched
at *write* time: the materializer (`bin/materializer.php` +
`deploy/lg-article-materializer.mu-plugin.php`) is the **sole writer** of blobs and
re-bakes on save. The shim's read surface IS the materializer contract.

## DONE + verified (do not redo)
**Increment 0 — perf + nav (landed + verified live this session):**
- **Tag pills → `/archive/?tag=<slug>`** and **tier chip → `/archive/?tier=<vocab>`**
  (archive-poc browse, not WP's legacy Search-&-Filter archive). In
  `standalone/engine/blocks/post-header/render.php`.
- **CSS bundle externalized** → content-hashed cacheable file in `web/assets/`
  (`lg-v2-bundle.<hash>.css`); page dropped **133KB → 30KB**. See
  `lg_standalone_css_href()` in `render.php`. ⚠️ `web/assets/` is FPM-writable
  (`archive-poc:www-data` 2775) — don't clobber it.
- **Content images lazy** (`lg_standalone_lazyload_imgs()` — adds `loading="lazy"` +
  `decoding="async"`; hero stays eager). srcset/sizes NOT done (needs materializer to
  bake size variants — open item).
- **Feed cards same-tab**: internal content links lost `target="_blank"`; external
  (sponsor-tile, side-sponsor, ecard event-join, socials) KEEP it. In
  `web/_render-card.php`, `web/_render-main-row.php`, `web/archive.js`.

**Increment A / B (parallel worker already committed):**
- Related-posts parity — footer carousel baked at materialize (`ce6a748`).
- FE-edit **Edit button** (`d75ad4a`), materializer **re-bake on `_lg_layout_v2`
  write** (`b59a9f8`), **`?lg_edit=1` + edit-capable → routed to WordPress** FE editor
  (`8c4bc4e`). Read = standalone (fast); edit = WP (full plugin editor); save → re-bake.

## OPEN / in-flight (your work)
- **Comments parity** — modal iframes WP's comments-only view (`?lg_comments=1`).
  *Uncommitted* work is sitting in the tree right now (`render.php`,
  `deploy/lg-comments-frame.php`, `materializer.php`). Finish + commit **by pathspec**.
- **Block parity remaining:** sponsor (ACF `brand_*` → bake into `post_context.sponsor`)
  and generic/Vimeo oEmbed (bake thumb at materialize). YouTube/Instagram already
  render via regex facade.
- **Images srcset/sizes** — bake the WP size variants into the blob's `media` map.
- Re-enabling `/post-type-videos/` + `/sponsor-post/` permalink interception is a
  separate **coverage/fallback** decision (video 9/319, sponsor 1/13 blob coverage) —
  was backed out to avoid 404s; needs coverage or a render→WP fallback. Ask Ian.

## Ops cheatsheet
- **Ownership:** `standalone/` = `archive-poc:www-data`; `web/` = `archive-poc:archive-poc`.
  To edit: `sudo chown ubuntu:ubuntu <file>`, edit, then `sudo chown` it back. Don't
  leave files ubuntu-owned.
- **Run PHP as the pool user:** `sudo -u archive-poc php …` (peer-auth pg). Plain `php`
  as ubuntu has no pg role.
- **Proof (gating self-check):**
  `sudo -u archive-poc LG_POST_TYPE=post-imgcap LG_SLUG=carbon-fiber-headstock-repair php standalone/render.php --proof`
- **HTTP test (warm):** curl with the gate cookie —
  `loothdev_auth=<$loothdev_token from /etc/nginx/sites-available/dev.loothgroup.com.conf>`.
  Covered test post: `post-imgcap` / `carbon-fiber-headstock-repair` (id 2707).
- **nginx:** `archive-poc/nginx-snippet.conf` (deployed copy DRIFTS — don't blind-deploy).
- **Commit by PATHSPEC, never `git add -A`** — this is a shared tree with other lanes.

## Coordination
The strangler **coordinator chat** holds the contract + handoff
(`docs/STRANGLER-SESSION-HANDOFF.md`); route cross-lane contract changes through Ian.
The coordinator will **stop touching standalone files** now that you own them.
