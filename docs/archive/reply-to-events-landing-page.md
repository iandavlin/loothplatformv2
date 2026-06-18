# Coordinator → events: build the landing page — STANDALONE (revised 2026-05-29)

> **REVISED — the template_include approach is OUT.** Per "all launch pages
> outside WordPress" (Ian): the events landing must be a **standalone surface**
> (like archive-poc/bb-mirror), NOT a WP-templated page. A WP-templated page
> boots WordPress every load (slow) — the shim doesn't fix that; only standalone
> serving does. Your *listing logic is reusable*; the serving *wrapper* changes.

Event post pages render v2 ✓. Now build the **events landing** as a standalone surface.

## Approach (revised — standalone)
- **Surface:** a **standalone PHP page on its own nginx route** (mirror how
  archive-poc/bb-mirror are served — nginx → standalone PHP, no WP boot). NOT a
  `template_include` on WP page 2773. (Take over `/events/` via the route.)
- **Chrome:** `require_once /srv/lg-shared/site-header.php` directly (same as the
  other standalone surfaces), passing the full consumer contract incl.
  **`active_nav` + `logout_url`** (coord §0a).
- **Listing data — read OUTSIDE WP:** the light path is a **read-only query on
  WP's MySQL** (`wp_posts`/postmeta — the same `UpcomingEvents` logic, but from
  standalone PHP, not calling into WP). Reimplement the query data-side; don't
  boot WP and don't loopback. (Open: direct WP-MySQL read vs. a small mirror —
  your call; direct read is lightest for the event volume.)
- **Listing UX:** upcoming + past split (sort by `events_start_date_and_time_`
  NUMERIC), region taxonomy filter, each row links to that event's v2 detail page.

## Gating — the listing is PUBLIC
List all events publicly (date/time/region/type are public per your zoom-only
gating decision). The per-event Zoom-CTA gating already lives on the detail page
(`event-header` block) — **do not gate the listing itself.** Clicking through to
an event applies the existing per-event gate.

## Cross-lane note
`UpcomingEvents::nextN()` is now a read-API the events landing depends on. Poller
lane: don't break its signature without a heads-up (coordinator will relay if it
moves).

## Repo + commit discipline (now in effect)
The events lane's work is in the **looth-platform repo** now: `events/`, the
`lg-layout-v2/blocks/event-header/` block, and the `Plugin.php` changes. Edit in
the repo, **commit at end of the change set + push** (coordination-doc §0). Don't
hand-edit deployed copies.

## Carry-forward (unchanged)
- **Cutover must ship** `Plugin.php` `MANAGED_CPTS += event` — already flagged.
- Still-open from your handoff: anon-cache invalidation for live Sheet edits; TZ
  (Sheet lane); `_ame_cpe_post_policy` confirm at cutover; `international-loothi`
  is a separate CPT (flag if wanted — not in this scope).

Report back when the landing renders on the shared shell with the v2 event list.

— coordinator
