# events lane — SESSION HANDOFF

> ⚠️ **SNAPSHOT — verify every open/queued to-do against `git log` before working it (flagged 2026-06-15).** Items marked open/TODO/next here may already be shipped — a lane re-did a done task off a stale handoff. Source of truth = `git log` + `tools/gates/run-all.sh`, not these bullets.

**Lane:** events (event post page → lg-layout-v2; events landing → shared shell)
**Session ID:** `8d852dda-54b5-41fc-8308-84cffe16e770` (from transcript path; confirm with Ian)
**Outliner title:** _events — event page → v2 + landing → shared shell_ (set in roster)
**Status as of 2026-05-29:** PoC complete + verified. **Default-event-layout shipped**
— every published event now auto-renders on v2 (no per-event conversion). Landing
page NOT started.

### Update — default event layout + Sheet alignment
- **`Plugin::default_event_layout()`** — events with no explicit `_lg_layout_v2`
  meta get `post-header → event-header → wysiwyg(body) → post-footer` synthesized
  live from postmeta. `manages()` returns true for any *published* event; explicit
  layout meta still wins as an override. Verified in-browser on 68780 (no explicit
  meta → renders v2, gate card for public, body clean). `Plugin::strip_zoom_links()`
  removes the legacy public `<h1>ZOOM LINK</h1>` from the body so the only route to
  the Zoom link stays the gated CTA.
- **Time parser hardened** (event-header render.php) — handles BOTH legacy 24h
  (`15:00:00`) AND the Sheet bridge's 12h (`7:30 pm`, via `date('g:i a')`). Without
  this, Sheet events at PM times misrendered as AM.
- **Sheet patch written:** `events/sheets-zoom-url-patch.gs` — adds a Zoom URL
  column + sends `zoom_url` in the publish payload. The bridge already accepts it;
  no WP change needed. This closes the one entry-point gap for the gated field.
- **Anon-cache invalidation — DONE.** `Plugin.php` now busts a post's anon
  render cache when an event's live-read meta changes (`events_start_date_and_time_`,
  `time_of_event`, `zoom_url_for_looth_group_virtual_event`), when its display
  taxonomies change (`tier`/`region`/`event-type`/`language`), or on
  `save_post_event` (title/body/status). Verified: unrelated meta does NOT bust
  (no over-invalidation); zoom_url change does. So Sheet edits now surface to
  logged-out visitors immediately — the live Sheet→render loop is closed.
- **Events landing page — DONE (STANDALONE, revised approach).** Per the
  coordinator's revised order ("all launch pages outside WordPress"), the
  `template_include` mu-plugin was **retired** and rebuilt as a **standalone
  surface** like bb-mirror/archive-poc — own nginx route + FPM pool, reads WP
  MySQL read-only, **no WP boot**.
  - **App:** `events/config.php`, `events/lib/events-query.php`,
    `events/web/{index.php,events.css}`. Front controller renders a PUBLIC
    listing (upcoming/past split, region filter) on the `/srv/lg-shared/` shell;
    cards link to each event's v2 detail page. Zoom URL never selected/emitted.
  - **Data:** direct read-only PDO MySQL on `wp_posts`/postmeta/terms (string
    compare on the 8-char Ymd — no DATE cast). Creds from `/etc/lg-events-db`
    (no committed secrets, no WP boot). Header viewer state via cached `/whoami`
    loopback (listing data never loops back).
  - **Route/infra:** `platform/nginx/strangler-events.conf` (mount `/events/`,
    dev-gated) + `platform/fpm/events.conf` (`events` user pool). Wired into the
    dev site conf + `deploy/{deploy.sh,MANIFEST.md}` for cutover.
  - **Verified on dev:** `/events/` → shared `.lg-chrome` header, 3 upcoming +
    3 past, `?ev_region=europe` → 2, CSS 200, **zero `zoom.us` leak**, no PHP
    errors, no WP boot. Screenshot: dev.loothgroup.com/mockups/events-standalone.png.
  - **`nextN()` flag (per coordinator):** did NOT use `UpcomingEvents::nextN()`
    (caps at 12, single bucket) — reimplemented the query data-side. No poller
    touched. Dormant `lg-events-shortcode.php` at repo root left alone.
  - **Note:** mount is `/events/` (the route takes it over); the old WP page
    2773 is slug `/calendar/`. If `/calendar/` should redirect to `/events/`,
    that's a small follow-up — flagging.

**Lane status: COMPLETE** — event post pages on v2 (only Zoom gated) + standalone
events landing at `/events/` on the shared shell. Remaining items are other lanes'
(cutover ships `MANAGED_CPTS += event` + the events post-deploy steps in
`deploy/MANIFEST.md`; Sheet lane adds the Zoom URL column per
`events/sheets-zoom-url-patch.gs`; TZ; `_ame_cpe_post_policy` confirm). Header
`active_nav` is passed but not yet consumed by the deployed shared header —
lg-shell change (flagged).

---

## What's done (event post page → v2)

### 1. New block: `event-header` (lg-layout-v2)
Full block-onboarding completed. Files under `blocks/event-header/`:
- `manifest.json` — props: `date`, `time`, `tz_label`, `region`, `event_types`,
  `zoom_url`, `cta_label`, `cta_tier`, `variant` (variant-1/2/3). All `required: []`.
- `shell.css`, `render.php`, `preview.html`, `README.md`
- `docs/blocks/event-header.md` (design doc)
- `tests/fixtures/event-header-minimal.json` + committed snapshot
- Indexed in `docs/BLOCKS.md`; `docs/LAYOUT-JSON.md` regenerated.

**Gates clear:** `bin/lint-block.php event-header` ✓ ; `bin/render-test.php
--block=event-header` ✓ ; `bin/render-test.php --all` ✓ (inert addition — only
`bundle.css` grew, no other fixture's HTML moved). Bundle regenerated +
`lg_layout_v2_cache_epoch` bumped on dev.

**Design — only the Zoom link gates (per Ian, 2026-05-29):**
- Header details (date pill, full date/time, region chip, event-type chips) are
  always public.
- The virtual-attend CTA is the *only* gated element. Satisfied viewers get a
  real `<a>` to the Zoom URL; under-tier viewers get an "Upgrade to join" card
  and the Zoom URL is **never emitted into their DOM** (can't be scraped).
- Reads **live from postmeta** (`$ctx['post_id']`), like `post-header` — so the
  showrunner Sheet→CPT pipeline stays the single source of truth; no re-convert
  on Sheet edits. Every field has an explicit-prop override (used for the CLI
  snapshot fixture / static bake).
- CTA gate = the post's `tier` taxonomy term (`$ctx['post_tier']`), override via
  the `cta_tier` prop. **The prop is `cta_tier`, NOT `gated_tier`** — `gated_tier`
  is the Renderer's reserved whole-block gating key (it would replace the entire
  header with a generic gate-CTA card; the snapshot test caught this).

### 2. `event` is now a v2-managed CPT
`src/Plugin.php` — added `event` to `MANAGED_CPTS`. Safe + incremental: only
events that carry a `_lg_layout_v2` meta render via v2; unconverted events keep
the legacy BuddyBoss template (`manages()` requires both CPT membership AND the
meta). **Cutover lane must ship this Plugin.php change.**

### 3. PoC conversion — event 68879
`/home/ubuntu/projects/events/poc-68879-layout.json` → set as `_lg_layout_v2`
meta on post 68879 (`Looth PRO - Les Paul…`, tier=looth-pro). Layout:
`post-header → event-header → wysiwyg(body) → post-footer`.

The legacy body carried the Zoom link **publicly** as `<h1>ZOOM LINK</h1>` —
stripped from the converted body and moved into the gated event-header CTA.
(Note: raw `post_content` still contains it but v2 owns the render so it never
displays; full migration may want to scrub post_content too.)

**Verified two ways:**
- Pipeline-level (all roles): public/lite → zoom hidden + gate card; pro/admin →
  zoom shown. Header + body public for all.
- **End-to-end anonymous fetch** of the live dev URL: v2 template fires,
  0 zoom-URL leaks, gate card shown, body + header public, **no AME
  content-replacement** (`_ame_cpe_post_policy` not interfering in practice).

---

## Authoritative data model (confirmed)

| Field | Source | Notes |
|---|---|---|
| start date | `events_start_date_and_time_` (YYYYMMDD) | |
| time | `time_of_event` (HH:MM:SS) | rendered with static "ET" (TZ unowned — flag to Sheet lane) |
| region | `region` taxonomy term | real terms assigned, not just ACF meta-id |
| event types | `event-type` taxonomy terms | `virtual-event` etc. |
| **tier (authoritative)** | `tier` taxonomy term (slug) | ACF `event_tier_` saves to it; already = v2 gating vocab (`public`/`looth-lite`/`looth-pro`) and already `$ctx['post_tier']`. **`patreon-level` is legacy snippet-#44 — ignored.** |
| zoom | `zoom_url_for_looth_group_virtual_event` | the one gated field |

---

## Landing page — PROPOSED approach (not yet built)

- **Surface:** the existing **`/events/` page (ID 2773)** — `event` CPT has
  `has_archive=false`, so use the page, not an archive.
- **Chrome:** mirror `mu-plugins/lg-membership-chrome.php` — a `template_include`
  swap that emits `header → listing → footer` on `/srv/lg-shared/site-header.php`
  + `site-footer.php`, with viewer context built in-process (no /whoami dep).
- **Listing data:** reuse the query logic already in
  `lg-patreon-stripe-poller/src/Wp/UpcomingEvents.php` (`queryEvents()` sorts by
  `events_start_date_and_time_` NUMERIC; upcoming-vs-past split; `formatWhen()`
  for the date/time labels). Region filter = `region` taxonomy query.
- **Open question for coordinator:** the listing-render code — new mu-plugin in
  the events lane, or extend the poller's `UpcomingEvents`? The poller is another
  lane (don't edit its code without coordination). Leaning toward a small events
  mu-plugin that *calls* `UpcomingEvents::nextN()` (already a public data-only
  accessor) rather than duplicating the query.

---

## Out of scope / flags for coordinator

- `international-loothi` is a **separate CPT** (in-person guitar shows/expos) that
  shares the region/language taxonomies — the briefing's data-model note
  conflated it with `event`. Not built; flag if it's wanted.
- **`_ame_cpe_post_policy`** content-protection meta exists on events (`{"accessProtection":{"active":"replace"}}`). Not interfering now, but confirm it's
  cleared/ignored at cutover so it can't over-gate beyond the Zoom link.
- Events Dashboard / Map of Events / Reminder Opt-In — noted, not mine.
- Timezone on event times is unowned (rendered static "ET") — Sheet-pipeline lane.

## Remaining acceptance items (need interactive dash/browser)
- Dash: confirm event-header panel renders with its vars; Preview modal cycles
  variants. (`bin/editor-test.js --block=event-header` for the inline `cta_label`.)
- The `lg-layout-v2` skill's `LAYOUT-JSON.md` has 2 *pre-existing* lint-docs
  violations (machine-generated doc; not mine, must not hand-edit).
