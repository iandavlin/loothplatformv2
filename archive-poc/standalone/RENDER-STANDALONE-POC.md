# RENDER-STANDALONE-POC — layout-standalone lane, turn 1

**Status:** PoC written (dark-launch, not installed, not wired into nginx).
**Date:** 2026-05-30. **Lane:** layout-standalone. **Host (ruled, design §6):** extend archive-poc.

Proves the §4 ~3-day lift in one turn: the lg-layout-v2 render engine runs
**standalone — no WordPress boot** — against a hand-materialized blob + a viewer
array, wears the unified shared shell, and **gates at render** so the raw blob
never reaches the wire.

## What's here

```
archive-poc/standalone/
├── engine/                 ← VENDORED COPY of lg-layout-v2 (src/ + blocks/). Copy, not move
│   ├── src/                  (CssBuilder, Renderer, Pipeline, TierResolver, GateCta, Manifest, Theme, Icons, Validator … run; Wp* classes copied but never loaded)
│   └── blocks/               (manifest.json + render.php + shell.css per block)
├── wp-shim.php             ← the materialize-on-save BOUNDARY as code: ~20 WP fns backed by a flat PostContext
├── render.php              ← the harness: blob + viewer → portable engine → shared shell → full HTML. No WP boot
├── blobs/
│   └── article-jazz-bass.json   ← hand-materialized blob: { layout: <verbatim _lg_layout_v2>, post_context: <flat identity> }
└── RENDER-STANDALONE-POC.md     ← this file
```

## How to run (coordinator — this is a WRITE-ONLY turn; I did not execute)

```bash
cd /home/ubuntu/projects/archive-poc/standalone

# 1) Gating self-check — the one command that proves §5. Renders public + pro,
#    asserts the invariants, sets exit code.
php render.php --blob=article-jazz-bass --proof ; echo "exit=$?"

# 2) Eyeball the two full pages:
php render.php --blob=article-jazz-bass --as=public > /tmp/pub.html
php render.php --blob=article-jazz-bass --as=pro    > /tmp/pro.html

# 3) Independent confirmation the gated payload never ships to public:
grep -c 8xg3vE8Ie_E /tmp/pub.html   # → 0  (gated YouTube id absent for public)
grep -c 8xg3vE8Ie_E /tmp/pro.html   # → ≥1 (present for pro)
grep -c lg-gate-cta /tmp/pub.html   # → ≥1 (public sees the CTA card instead)
```

`--proof` checks, all of which should PASS:
1. gated YouTube id **absent** in public HTML
2. gated YouTube id **present** in pro HTML
3. public sees the **gate-CTA** card (`lg-gate-cta`) — preview, not payload
4. pro sees the **real embed payload** (`data-yt-id`)
5. pro does **not** get a gate-CTA in place of the embed
6. no `<lg-edit>` editor markers on the wire
7. the word `post_context` never appears on the wire (raw blob not echoed)

> Caveat: `php -l` / execution is sandbox-blocked this turn, so I have not run it.
> The harness mirrors the proven `bin/render-test.php` boot exactly (same
> Autoload → Manifest::configure → Pipeline::run), so failures, if any, will be
> in the new shim/blob plumbing, not the engine.

## What I stubbed (and what it proves)

The engine is already portable. The **only** WP coupling in the render path is
article *identity* — post-header / post-footer / GateCta read title / author /
date / terms / avatar / featured-image via ~18 WP functions (design §3). I did
**not** fork those blocks. Instead `wp-shim.php` defines exactly those function
names, each backed by a flat `post_context` array. The **unmodified** engine and
**unmodified** block render.php files run against the shim with zero WP boot.

Key consequence: **the shim's read surface IS the materializer contract.**
Whatever key the shim touches, the production save hook must bake into the blob.

Deliberately left undefined so the engine takes its no-WP branch:
`bp_core_get_user_domain`, `get_field` (ACF/sponsor only), `wp_oembed_get` /
`wp_remote_get` / `get_transient` (YouTube + Instagram render via pure-regex
facades; only generic oEmbed needs them), and `comments_template` / `WP_Query`
(gated behind `show_comments` / `show_related`, both `false` in the PoC blob).

## The PostContext contract — what the save-hook materializer MUST produce

The blob is `{ layout, post_context }`.

- **`layout`** = the post's `_lg_layout_v2` meta, copied **verbatim** (no
  enrichment — same escape-hatch discipline as the profile-app migration:
  literal source → literal target). Events are the exception (design §3): their
  layout is synthesized per-render today, so the save hook must **flatten event →
  layout JSON** before storing.

- **`post_context`** = identity resolved **once at save**, where the WP fns exist:

  | key | resolved from (at save time) | consumed by |
  |---|---|---|
  | `post_id`, `title`, `permalink`, `date` | `get_the_title` / `get_permalink` / `get_the_date` | header, footer, GateCta poster |
  | `post_tier` | `wp_get_object_terms($id,'tier')` (non-`public` slug) | render gating + `scrubGatedAnchors` |
  | `bloginfo_name` | `get_bloginfo('name')` | header/footer publication line |
  | `author.{id,display_name,avatar_url,archive_url}` | author user + **profile-app batch lookup** (single-source avatar, §3 coord) | byline, author card |
  | `author.meta.{author_about,author_website,author_instagram,…}` | author user-meta / ACF | author links + bio |
  | `featured_image.{id,url,alt}` | `get_post_thumbnail_id` + attachment | hero, gate poster |
  | `terms.{tier,shared_category,post_tag,…}[]` = `{name,slug,term_id,link}` | `get_the_terms` + `get_term_link` | tier chip + tag strip |
  | `media.{<id>}.{url,alt,mime,sizes}` | `WpMedia::resolve` per attachment the layout references | image/embed blocks |
  | `options.lg_layout_v2_gate_cta` | `get_option` | GateCta copy |

  All flat, all server-resolved, **zero live WP queries at render**. This is the
  archive-poc/bb-mirror mirror model applied to article identity.

## §5 security posture (as built)

- Gating is applied at **render**, per viewer, via the portable
  `TierResolver::satisfies()` — identical logic to the live WP path.
- The blob (all blocks, incl. gated) is **server-side only**; only rendered+gated
  HTML is emitted. The gated embed becomes a `GateCta` card for public — the
  YouTube id / `data-yt-id` / thumbnail never enter the public HTML (proof check 1).
- **Fail-closed:** `lg_standalone_viewer()` maps unknown/absent `as` → anonymous
  public; gated blocks render their CTA, never the payload.
- `editor_mode:false` is hard-wired (design §1 — FE editor is OFF the standalone
  path), so no `<lg-edit>` markers and no editor state JSON ship.

## Open questions for the coordinator (design §6 + new)

1. **Engine delivery: vendored copy vs reference-in-place vs shared lib.** I
   **vendored a copy** into `standalone/engine/` per the bootstrap's "copy/extract,
   don't move." It proves true portability (engine runs with no WP plugin present)
   but creates a fork that will drift from `lg-layout-v2/`. Production archive-poc
   deploys as its own service, so it can't rely on the WP plugin dir being on
   disk. **Options:** (a) keep the vendored copy + a sync/extract step in
   `deploy.sh`; (b) extract the portable engine into a shared package both the WP
   plugin and the standalone host depend on; (c) reference `lg-layout-v2/` by path
   (rejected for prod — couples the service to the WP install layout). **Recommend
   (b)** long-term, **(a)** to keep moving now. Needs a ruling before the save-hook
   build.

2. **Keep the WP-fn shim, or refactor blocks to read `$ctx['post_context']`
   directly?** The shim lets the block render.php files stay **byte-identical**
   between WP and standalone — one engine, two backends, no fork. The design §3
   table phrasing ("PostContext baked into the blob") could also mean refactoring
   post-header/footer to read ctx directly. **Recommend keeping the shim** — it's
   the lower-drift path and avoids touching the live blocks. Flagging because it's
   an architecture choice the coordinator should bless.

3. **Comments snapshot (design §6 #2) not exercised yet.** PoC blob sets
   `show_comments:false`. `comments_template()` is deep WP machinery; standalone
   needs the materializer to snapshot a flat comment array (or pre-rendered HTML)
   into the blob and a standalone-safe post-footer comments branch to emit it.
   Scoped as a production materializer task; contract TBD with this lane.

4. **HOST = extend archive-poc** is ruled (§6 #1) and I built to it (files under
   `archive-poc/`, designed for its FPM pool + shared chrome). **Not** wired into
   nginx this turn. The clean URL (per coord §0d) would be something like
   `/loothprint/<slug>` / `/<cpt>/<slug>` routed to this host — routing is
   sysadmin + a future turn, not now.

## What I did NOT touch (per bootstrap)

No WP save hook. No live `the_content` path (the WP plugin is unmodified — I
copied, never moved). No authoring / dash / FE editor. Installed nothing. No
git commit, no `php -l`, no CDP — coordinator commits + lints + tests.
