# Design — Standalone CPT Renderer (articles + events off WordPress)

> **Status:** v0 scoping draft, 2026-05-30 (Ian + coordinator, Opus). Grounded in
> two read-only code traces of `lg-layout-v2/` (file:line throughout §3).
> **POST-CUT project.** Third in the "WP off the hot path" line, next to
> billing-rebuild + login-into-profile-app.

## 0. The problem (what Ian noticed)

Click *into* an article or event single-page and you leave the fast standalone
world: those pages are **rendered inside WordPress** (lg-layout-v2 hooks
`the_content`) and wear a **forked header** — lg-layout-v2's own cloned masthead
(`SiteHeader.php` → `templates/partials/site-header.php`), NOT the unified
`/srv/lg-shared/` shell. Two consequences a user feels:
- **Slow** — WordPress boots (~2.6s floor) to render each post.
- **Stale chrome** — the account dropdown + P9 modals + every future header change
  land in the shared shell; the article pages are on a frozen clone that drifts.

## 1. The constraint that shapes the whole design (Ian, 2026-05-30)

> "We'd still need to post and manage posts from WordPress, just not render or
> front-end edit."

- **Authoring + management STAY in WP** — the dash, the CPT, save flow. (The
  north star: WP = admin console.)
- **Rendering moves OFF WP** — read-only, standalone, shared shell, no WP boot.
- **Front-end editor RETIRES from the live page** — editing happens in wp-admin.
  This removes the FE-editor from the standalone path entirely.

These cuts are what make the project tractable (see §4).

## 2. Architecture — materialize on save, render standalone (the strangler pattern)

Same shape archive-poc + bb-mirror already use — WP writes, a sync hook mirrors a
portable artifact, a standalone service reads + serves it:

```
  WP (authoring)                          standalone (rendering)
  ┌────────────────────┐                  ┌──────────────────────────┐
  │ edit post in dash  │   save hook      │ read materialized blob    │
  │ lg-layout-v2 CPT   │ ───────────────► │ + viewer array (/whoami)  │
  │ resolves layout +  │  writes a flat,  │ → portable render engine  │
  │ post/author/term/  │  fully-resolved  │ → shared shell chrome     │
  │ comment data ONCE  │  layout artifact │ → HTML, no WP boot        │
  └────────────────────┘                  └──────────────────────────┘
```

**Why materialize-on-save (not live DB reads):** the only deep WP couplings in
the render path are post-header / post-footer / event-header reading *live* WP
data (title, author, date, terms, avatar, comments — §3). Because WP keeps
authoring, we resolve all of that **once, at save time, where those functions
exist**, and write a flat artifact. The standalone renderer then reads a plain
blob — zero live WP queries. This is exactly the archive-poc/bb-mirror mirror
model; the article renderer stops being a special case.

## 3. Grounded findings (from the code traces)

**The render engine is ~95% portable.** `CssBuilder`, `TierResolver`, the block
dispatch in `Renderer.php` — zero WP calls, already operate on plain arrays. The
viewer/tier contract is already a portable array shaped like `/whoami`.

**Where the layout lives:** `_lg_layout_v2` post meta (`Plugin.php:261–273`).
Directly DB-readable. **Events are the exception** — their layout is synthesized
fresh per render from post_content + live postmeta (`Plugin.php:287–309`), so
they MUST be materialized to a stored blob on save.

**The couplings to break, and how (all collapse under materialize-on-save):**

| Coupling | File:line | Resolution |
|---|---|---|
| post-header live data (18 WP fns: title/author/date/terms/avatar) | `blocks/post-header/render.php:44–162` | resolve at save → `PostContext` baked into the blob |
| post-footer author + `wp_list_comments()` | `blocks/post-footer/render.php` | same; snapshot comments into the blob (or a comments mirror) |
| event-header live postmeta (date/zoom) + `wpautop` | `event-header/render.php:42–64`, `Plugin.php:287` | flatten event → layout JSON in the save hook |
| post-level tier auto-gate via `wp_get_object_terms(...,'tier')` | `Plugin.php:66–71` | pass `post_tier` into ctx (already a ctx param) |
| editor mode / `current_user_can` / `is_preview` | `WpRenderer.php:34–36` | OUT of scope — standalone is read-only, `editor_mode:false` |
| brand/dash options | `WpRenderer.php:57–60` | already optional params; read from the mirror |
| media resolution | `WpMedia.php:19–50` | swappable `media_resolver` callable — already an interface |
| CSS `@import` relative URLs | `Isolate.php:169–173` | pre-resolve to absolute, or pass `base_url` |

**Header (the chrome fork):** lg-layout-v2's masthead takes over the
`buddyboss_header` hook cleanly (`SiteHeader.php:54`) — no BB string-hacking. But
it's a different build from the shell (`.lg-site-header` vs `.lg-chrome`, enqueued
+ lazy-load JS vs the shell's inline, different `data-*`). In the standalone world
this is moot — the standalone renderer calls `lg_shared_render_site_header()`
natively, and the WP-side clone is simply no longer on the read path.

## 4. Effort (grounded, with Ian's constraints applied)

- **PoC: ~3 days** — standalone harness reads a hand-materialized blob for one
  article + the shared shell, renders it, no WP boot. Proves the engine lifts.
- **Production: ~1.5–2 weeks** — the save-hook materializer (PostContext +
  comments snapshot + event flatten), the standalone host + route, gating parity,
  cache/invalidation on re-save. Lower than the raw 2–3 wk trace estimate because
  the FE-editor decoupling and the live post-header/footer query mesh both
  collapse under materialize-on-save.

## 5. Security + gating (must hold — read surfaces gate real entitlement)

- **Gating happens at RENDER, per viewer — never bake ungated content into a
  public artifact.** `TierResolver::satisfies()` is pure + portable; the
  standalone renderer applies it against the viewer array (from `/whoami` /
  `looth_id` JWT + `lg_tier`) exactly as WP does today.
- **The materialized blob contains ALL blocks (including gated) and is
  server-side only.** The standalone endpoint serves **rendered + gated HTML**,
  never the raw blob. A gated block must never reach the wire for a viewer who
  can't see it — strip at render, don't rely on client hiding.
- **Fail-closed:** unknown/absent viewer → public tier; gated blocks render their
  gate-CTA, never their payload. Mirror the §5b billing discipline.
- **Materializer integrity:** the save hook is the only writer of the artifact;
  same mirror-writer discipline as archive-poc/bb-mirror (§3i grants).

## 6. Decisions (RULED 2026-05-30, Ian — lane does NOT re-ask)

1. **HOST = extend archive-poc.** It already mirrors editorial posts + has the FPM
   pool + standalone chrome; full-article render reads the *same* mirror, so it's a
   natural extension, not new scope. "Don't pre-split" (§3i): if article render ever
   outgrows the feed, splitting later is a `pg_dump` + connection-string swap.
   Rejected: a new sibling service (re-derives mirror+chrome+FPM for separation we
   don't need yet).
2. **Comments = snapshot into the blob on save.** Simple, slightly stale. Add a
   live comments mirror later only if staleness actually bites.
3. **FE-editor = retire from the LIVE page, keep a WP-rendered edit view behind a
   flag during transition.** Editing's home is wp-admin; don't burn the live-edit
   bridge until standalone render is proven on real traffic, then drop the flag.

## 7. What stays in WP (by design)

Authoring + post management (the dash, the CPT, save flow), the layout editor
(wp-admin), and the canonical post/author/term/comment data. WP writes; the
standalone renderer reads a mirror. WP is the admin console — the north star.

## 8. What this is NOT

- Not a rewrite of the render engine — it's ~95% portable; we extract + host it.
- Not touching authoring — WP keeps it.
- Not the header-swap stopgap (~2–3 days to put the shared shell on the WP-rendered
  pages). That's throwaway once this lands; only do it if the forked header is
  intolerable during the ~2-week build. Recommendation: skip it, go straight here.

— coordinator (Opus scoping pass, 2026-05-30)

## 9. Rulings on PoC open Qs (2026-05-30, coordinator)
1. **Engine delivery = vendored copy + `deploy.sh` sync NOW** (keep moving), **extract to a shared package LATER** (the lane's own long-term pick). Don't block the materializer on the package extraction; just keep the vendored copy in sync via deploy until then.
2. **Keep the WP-fn shim** — one engine, two backends, block `render.php` stays byte-identical WP↔standalone. No fork. (Lane's recommendation, accepted.)
3. **Comments snapshot** — production-materializer task: flat comment snapshot in the blob + standalone post-footer branch (per §6 #2 snapshot decision).
4. **HOST nginx routing** — later sysadmin turn (coordinator), not the lane's.
