# Hub filter / nav spec (Ian, 2026-06-05)

For the hub lane to build once the unified feed (UNION content + forums) is in. Interactive design
reference: **https://dev.loothgroup.com/mockups/hub-filters.html** (the mockup IS the spec for layout +
interactions). Per-user state persists in profile-app (localStorage = fast local cache only).

## Filtering model
- **AND across facets** — Type ∩ Category ∩ Author narrows, never ORs.
- **Type** — finite list w/ counts; each has a **sticky mute toggle** (switch) + click name = filter
  this view. Muted types never appear in the user's feed.
- **Category** — same pattern as Type: sticky mute toggle + click-name filter.
- **Author** — **search-first** (hundreds of authors, no toggle list): type-ahead → click to filter;
  every **byline clickable** (posts + replies) → filter to that author. No mute control here.
- **Active filters** shown as removable chips up top + AND badge; "Reset all". Sticky mutes shown as
  distinct "Muted" chips.
- **Persistence:** sticky type/category mutes + reading prefs (theme, text-size, default sort) persist
  **per-user in profile-app**; transient filters (a chosen type/cat/author) reset.

## Author header (on single-author filter) — pull from profile
When the feed is filtered to ONE author, show an author header at the top, sourced from **profile-app**
(single-source author identity — same data as the byline avatar/bio):
- avatar + display_name + **about/bio** (`at_a_glance`) + the author's **social links**, post count,
  "✕ Clear author".
- Use the batch users lookup / profile fetch keyed on the author's `user_uuid`. Mirrors today's archive
  author-header behavior, enriched with profile bio + links.

## Mute — TOPICS + CPTs ONLY (lives on the filter rail)
- Mute applies to **Types (CPTs) and Categories (topics) only** — the sticky toggle switches on the
  filter rail. Flip one off → that type/category never appears in the user's feed.
- **There is NO person/author mute** — not on the rail, not on the profile. We are not building
  member-muting. The Author facet is filter-only (search-first, byline-clickable); it has no mute.
- Sticky type/category mutes persist **per-user in profile-app** and show as distinct "Muted" chips up
  top, alongside the AND-filter chips.

## Out of scope here
The feed UNION + content-card variant + gating are the base hub-lane work; this filter/nav layer sits
on top of it.
