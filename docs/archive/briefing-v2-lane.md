# Briefing — lg-layout-v2 lane (general starter)

**Paste into a fresh chat to stand up a v2 lane.** This orients you to the layout engine; pair it
with a specific task (e.g. docs/briefing-v2-lightbox-regression.md) for the actual work.

## What lg-layout-v2 is
The structured-layout engine for Looth's managed content. Source: `/home/ubuntu/projects/lg-layout-v2/`.
Deployed plugin: `/var/www/dev/wp-content/plugins/lg-layout-v2/`. A layout = an ordered list of typed
blocks rendered to HTML. **Every layout brackets its content with a `post-header` (first) + `post-footer`
(last)** — not auto-injected; posts without them render naked.

## Two render paths (know which you're touching)
- **WP path** — logged-in / `?lg_edit=1`. `src/WpRenderer.php` filters `the_content`; the BB theme renders
  the page chrome. Used by authors in the editor + fallback. Anon WP renders are cached.
- **Standalone path** — public posts. `archive-poc/standalone/render.php` reads a materialized blob from
  Postgres `article_blobs` and runs a **vendored copy of the engine** (`archive-poc/standalone/engine/`).
  Zero WP boot. This is what most readers actually see. The vendored copy is byte-synced — if a fix must
  touch it, flag to coordinator, don't fork it silently.

## Managed CPTs
`post-imgcap, post-type-videos, sponsor-post, loothprint, loothcuts, useful_links, member-benefit, document`
(`Plugin::MANAGED_CPTS`). Standalone permalinks route via the nginx archive-poc snippet (`/article/`,
`/video/`, `/sponsor/`, plus the slug-prefixed CPT locations).

## Block system
- A block = `blocks/<name>/{manifest.json, render.php, shell.css}`.
- **Brand tokens** live in `src/theme/tokens.json` (registry: `src/Theme.php`); they auto-surface in the
  dash brand panel + colorpicker swatches when `category:"color"`. Recently added `--lg-coral`/`--lg-slate`.
- **`shell.css` per-level modifier rules must live OUTSIDE `@layer block-shell`** or the manifest's
  block-defaults override them.
- Test fixtures: `tests/expected/*/bundle.css` — **regenerate when block/token CSS changes** (they're
  derived; a stale bundle fails tests).
- `src/Isolate.php` runs a dequeue+allowlist pass on managed CPTs (`STYLE_ALLOWLIST` / `SCRIPT_ALLOWLIST`).
  Our front JS handle is `lg-layout-v2-front` (`assets/lg-front.js`, lightbox + popout) — it's allowlisted.

## Hard rules (cross-cutting — route to coordinator, don't touch in-lane)
- **Header = consumer only.** `require '/srv/lg-shared/site-header.php'; lg_shared_render_site_header($ctx)`,
  `$ctx` from `/whoami`. lg-shell owns the header; don't fork or restyle it.
- **Gating = server-side ABSENCE**, not CSS hiding. Per-block `gated_tier` / a section `paywall` block /
  the gate-CTA pattern; post-level tier via the `tier` taxonomy term. Withheld payload must be absent from
  the DOM/network for unentitled viewers.
- **Identity:** consumers resolve from `/profile-api/v0/whoami` (JWT-fast ~5ms) — NOT the WP-shim REST route.
  Never trust the `lg_tier` cookie for gating (it's a cache).

## Working conventions
- **Leave changes uncommitted** for review-before-push (coordinator gate); never push.
- **In-lane scope only**; flag cross-cutting (header/whoami/Isolate-allowlist) to coordinator.
- Browser testing: load the `chrome-dev-login` skill (CDP, logged-in WP admin). Test user `pilot_pro`
  (id 1883, looth4→pro) for authed/pro states.
- Authoring new posts: the `write-article-v2` skill turns prose+images or a video+chapters into a layout.
- Deploy to live (when asked): versioned zip in `/var/www/dev/.well-known/`, user curls it on live,
  unzip + chown looth-live + bundle regen + epoch bump.

## Report back to coordinator
Root cause / what changed, files touched, both-path verification where relevant (WP + standalone,
in-browser), and any cross-cutting need flagged rather than fixed in-lane.
