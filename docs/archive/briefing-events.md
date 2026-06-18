# Bootstrap — events chat

You're the **events** chat. Read this top-to-bottom, then the pointers, before
touching anything.

## Your job

Get the **events landing page** and the **event post page** onto the modern
stack: event post pages render as **lg-layout-v2** (with a new event block),
and the landing page renders on the **unified `/srv/lg-shared/` header**.
Dev-testable, ready to ride the blue-green cutover.

**In scope (now):** the events landing page + the event post page.
**Out of scope (noted, not yours yet):** Events Dashboard, Map of Events,
Reminder Opt-In page. They exist on dev; flag if you trip over them, don't
build them.

## What events are today (already investigated — head start)

- `event` is a CPT. ~6 published on dev. Renders via a **legacy/BuddyBoss
  template**, NOT lg-layout-v2 (sample event 68879/69457 carry no v2 layout
  meta).
- **No calendar plugin** (no Tribe/Events Calendar/Tickets active). Event data
  is **bespoke postmeta**.
- Fed by the **showrunner Google-Sheet → `event`-CPT bridge**
  (`mu-plugins/loothdev-sheets-bridge.php` + `…apps-script.gs.txt`). That
  pipeline owns event *creation* — you own *render*. Do not break it.

### Event data model (the postmeta you'll map into the block)

| Meta key | Meaning |
|---|---|
| `events_start_date_and_time_` | start date + time |
| `time_of_event` | time |
| `region` | location / region (events are "International Loothing Events" — i18n/region aware; also `_language_`) |
| `zoom_url_for_looth_group_virtual_event` | virtual-event Zoom link (gated) |
| `patreon_url_for_higher_tier_content` | gated higher-tier content link |
| `event_tier_` + `patreon-level` | tier gating (TWO signals — reconcile; see gating note) |
| `_lg_er_fcrm_campaign_id`, `_lg_er_lead_time_minutes` | reminder wiring (FluentCRM campaign + lead time) — the Reminder Opt-In surface |
| `_expiration-date-*` | events auto-expire (post-expirator plugin) |
| `_post_addon_gallery_radio` | gallery addon flag |

Most keys exist in both `_`-prefixed and bare forms — confirm which the
render path should read.

## What you'll build

1. **lg-layout-v2 event block(s).** v2 has NO event/date/location/RSVP block
   today (block list is article-shaped: hero, gallery, pull-quote, paywall…).
   Build an **event-header block** (date/time/region/virtual-link) — possibly a
   companion RSVP/reminder block. This is a **v2-plugin change**; no dedicated
   v2 chat exists, so it's yours. **Load the `lg-layout-v2` skill** — it has the
   cascade-ordering + bundle/cache lifecycle gotchas that will bite otherwise.
2. **Event post page → v2.** Map the event postmeta into the new block + the
   body into standard v2 blocks. Decide: per-post conversion now (only ~6), or a
   small extractor (reuse `lg-legacy-import/src/Extractors/` pattern) if the
   bulk-converter will later sweep remaining events.
3. **Events landing page → shared shell.** The listing/calendar surface, wrapped
   in `/srv/lg-shared/site-header.php` (see `bb-mirror/web/_chrome.php` for the
   include pattern). Sorting by `events_start_date_and_time_`, region filter,
   upcoming-vs-past.

## Gating — reconcile the two signals

Events carry BOTH `patreon-level` (the legacy snippet-#44 meta) AND
`event_tier_`. lg-layout-v2's gating truth is `src/TierResolver.php`
(`gated_tier: public|looth-lite|looth-pro`, viewer tier from WP role via
`/whoami`). Map the event's tier meta → v2 `gated_tier`. Confirm which of
`event_tier_` / `patreon-level` is authoritative before wiring. See
STRANGLER-COORDINATION.md §1 (looth1=public … looth4=pro) + §3k.

## Coordination map

- **lg-layout-v2 plugin** — the event block lives here. Build per the
  `lg-layout-v2` skill + `docs/BLOCK-ONBOARDING.md`.
- **showrunner-wp-bridge** — owns event creation from the Sheet
  (`loothdev-sheets-bridge.php`). Render layer only; don't touch the pipeline.
- **lg-shell** (`1d248347`) — consume the shared header for the landing page;
  don't edit it. Header changes route through coordinator.
- **bulk-converter** (if/when spawned) — hand it your event block + extractor
  for any remaining `event` posts.
- **cutover** (`c4e655f8`) — your block ships in the v2 bundle; landing-page
  template + any nginx must be cutover-ready (works on the fresh EC2).

## Critical constraints

- **Live is Claude-free.** Build + test on dev. Coordinator + Ian handle the
  new-box build and DNS swing.
- **Cookie gate:** dev needs the `loothdev_auth` cookie — claim via
  `/claim?t=<token>` (token in `dev.loothgroup.com.conf`).
- **v2 bundle/cache:** editing blocks needs a bundle rebuild + cache-epoch bump
  — the `lg-layout-v2` skill covers this; skipping it = stale renders.

## Read first

1. This file
2. `/home/ubuntu/projects/lg-layout-v2/docs/BLOCKS.md` + `docs/BLOCK-ONBOARDING.md` (how to add a block)
3. `/home/ubuntu/projects/lg-layout-v2/src/TierResolver.php` (gating)
4. `/srv/lg-shared/site-header.php` docblock + `bb-mirror/web/_chrome.php` (~line 325) for the shell include
5. `mu-plugins/loothdev-sheets-bridge.php` (the event data pipeline — read, don't edit)
6. STRANGLER-COORDINATION.md §1, §3k, §4 (tiers, IA, blue-green cutover)

## First moves

1. Read a real event end-to-end (68879): its postmeta, how the legacy template
   renders it, what the Zoom/region/tier fields drive. Confirm the authoritative
   tier signal.
2. Build the event-header block in lg-layout-v2 (fixture + snapshot per TESTING.md).
3. Convert ONE event (68879) to v2 as the proof of concept; verify gated render
   (lite/pro/public) via `?lg_preview_role=`.
4. Report back the PoC + your landing-page approach before doing the rest.

## When you spawn

Create `/home/ubuntu/projects/events/SESSION-HANDOFF.md` for your lane state.
Capture your session ID + outliner title and report to coordinator so the
roster + lineage get filled in.

## Report-back format

```
**events → coordinator:** <one-line status>

<absolute path to your SESSION-HANDOFF.md>
```

— coordinator
