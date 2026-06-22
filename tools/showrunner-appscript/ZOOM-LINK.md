# Zoom Link → gated event Join CTA

How the showrunner Sheet feeds the virtual-attend Zoom link onto event pages, and how to
operate it. Added 2026-06-22 (supersedes the standalone `events/sheets-zoom-url-patch.gs`).

## The full chain

```
Sheet "Zoom Link" column (W / CONFIG.COL.ZOOM_URL)
  └─ default on new events = CONFIG.DEFAULT_ZOOM_URL (editable per row)
        │  Publish Selected Row to WP / modal "Publish now?"
        ▼
  POST /wp-json/loothdev/v1/events   payload.zoom_url       (loothdev-sheets-bridge.php)
        ▼
  update_field('field_66647b9a6fd15') → postmeta
        zoom_url_for_looth_group_virtual_event
        ▼
  lg-layout-v2 `event-header` block render.php
        get_post_meta(..., 'zoom_url_for_looth_group_virtual_event')
        ▼
  GATED "Join" CTA — emitted ONLY to tier-satisfied viewers.
  Under-tier / anon: the URL is never written into their DOM (un-scrapeable);
  they get the "Upgrade to join" card instead.
```

Gating tier = the event's `tier` taxonomy term (Event Tier column → Public / Looth Lite /
Looth Pro). The Zoom link is the ONE gated field on an event; date/time/region/type are public.

## Where it lives in the script (`Code.gs`)

| Piece | Location |
|---|---|
| Column index | `CONFIG.COL.ZOOM_URL = 23` |
| Default room | `CONFIG.DEFAULT_ZOOM_URL` |
| Header label | `HEADERS[] = 'Zoom Link'` |
| Column width | `setupSheet()` |
| Re-publish nudge on edit | `onEdit()` `wpRelevantCols` |
| Default on Google-Form row | `onFormSubmit()` |
| Default on modal row (honors edit) | `submitEpisodeFromWebApp()` |
| Modal input (pre-filled, editable) | `buildEpisodeWebAppHtml_()` `#zoomUrl` + payload `zoom` |
| Sent to WP (menu publish) | `publishRowToWp_()` → `payload.zoom_url` |
| Sent to WP (modal publish) | `publishRowFromWebApp()` → `wpPayload.zoom_url` |

WP side needs **no change** — the bridge already accepted `zoom_url` and the block already
read the meta; the Sheet simply had no entry point until now.

## Operating it

- **Roll the standing room:** edit `CONFIG.DEFAULT_ZOOM_URL` once, re-paste `Code.gs`. New
  events pick it up; existing rows keep their stored value.
- **Per-event override:** type a different link in the row's Zoom Link cell (or edit the
  modal field) before publishing. Editing it on an already-published row toasts a
  "re-publish to push the update" nudge.
- **Omit it:** clear the cell — `zoom_url` is only sent when non-empty, so recording-only /
  in-person events render no Join CTA.

## Default room (current)

```
https://us02web.zoom.us/j/87325405572?pwd=ZnA3NEtwTlNXN0RKQThCNVJ2YzZoQT09#success
```

## Gotcha — anon render cache

After changing a Zoom link on a live event, logged-out visitors may not see the change until
the v2 anon render cache for that post is invalidated. That invalidation is handled WP-side by
lg-layout-v2 (`Plugin.php` busts the cache when `zoom_url_for_looth_group_virtual_event`
changes) — nothing the Sheet can do. Tier-gated viewers are server-evaluated per request, so
the *gating* is always live; only the cached anon HTML lags.

## Verify after deploy

1. New event via the modal → confirm the Zoom field is pre-filled with the default and is
   editable; submit → the row's Zoom Link cell is populated.
2. Publish → `wp post meta get <id> zoom_url_for_looth_group_virtual_event` returns the link.
3. Load the event page as a satisfied tier → "Join" CTA links to Zoom.
4. Load as anon/under-tier → no Zoom URL anywhere in the HTML (`curl … | grep zoom.us` = 0).
