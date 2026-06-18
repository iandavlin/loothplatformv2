# `event-header` block

The event-page counterpart to `post-header`: a header strip for `event` CPT
posts that surfaces **when / where / how to attend** an event, with the live
"join" link gated to the event's tier. Public event details stay visible to
everyone; only the actionable virtual link gates.

## Purpose

One block that renders an event's date, time, region, and event-type chips,
plus a tier-gated virtual-attend CTA (Zoom link for satisfied viewers, an
upgrade card for everyone else). Used as (or near) the first block in a
converted `event` post's v2 layout. Pulls its data **live from event
postmeta** at render time so the showrunner Sheet→CPT bridge stays the single
source of truth — editing the Sheet updates the rendered header with no
re-conversion.

## Data source — live postmeta, not baked props

Events are created and continuously updated by the showrunner
`loothdev-sheets-bridge` pipeline. If we baked date/zoom/tier into the static
layout JSON at conversion time, a later Sheet edit would silently go stale.
So the block reads live from the current post (`$ctx['post_id']`), exactly the
way `post-header` reads title/author/thumbnail:

| Meta / source | Drives |
|---|---|
| `events_start_date_and_time_` (`YYYYMMDD`) | date pill + full date |
| `time_of_event` (`HH:MM:SS`, 24h) | time line ("3:00 PM ET") |
| `region` taxonomy term | region chip (e.g. "United States") |
| `event-type` taxonomy terms | type chips (virtual-event, instructional-event, …); presence of `virtual-event` toggles the Zoom CTA |
| `zoom_url_for_looth_group_virtual_event` | the gated live link |
| `tier` taxonomy slug (= `$ctx['post_tier']`) | gate for the Zoom CTA |

`patreon-level` postmeta is **ignored** — it's the legacy snippet-#44 integer
system. The authoritative tier signal is the `tier` taxonomy term (ACF
`event_tier_` saves to it), which is already the v2 `gated_tier` vocabulary
(`public` / `looth-lite` / `looth-pro`) and already exposed as
`$ctx['post_tier']`.

Every live-read field has an **explicit-prop override** (see Content shape):
when a prop is non-empty the block uses it verbatim instead of reading meta.
This is what makes the block CLI-snapshot-testable (the fixture passes literal
props) and gives a static-bake escape hatch; in production all overrides are
empty and the block reads live.

## Content shape

| Prop | Type | Required | Default | Description |
|---|---|---|---|---|
| `date` | string | no | "" | Override: `YYYYMMDD`. Empty → read `events_start_date_and_time_`. |
| `time` | string | no | "" | Override: `HH:MM:SS`. Empty → read `time_of_event`. |
| `tz_label` | string | no | "ET" | Timezone label appended to the rendered time. |
| `region` | string | no | "" | Override region display name. Empty → read `region` taxonomy term name. |
| `event_types` | array | no | [] | Override list of type-chip labels. Empty → read `event-type` taxonomy term names. |
| `zoom_url` | string | no | "" | Override virtual-attend URL. Empty → read `zoom_url_for_looth_group_virtual_event`. |
| `cta_label` | string | no | "Join on Zoom" | Label on the virtual-attend button. |
| `cta_tier` | string | no | "" | Override gate for the Zoom CTA. Empty → use `$ctx['post_tier']`. **Not** named `gated_tier` — that key is reserved by the Renderer for whole-block gating (it would replace the entire header with a gate-CTA card). This prop gates only the CTA, inside render.php. |
| `variant` | enum | no | "variant-1" | Visual palette. variant-1 = amber accent; variant-2 = sage; variant-3 = neutral cream. |

`required: []` — every field self-resolves from the post, so a bare
`{ "type": "event-header" }` block renders a complete header for the current
event.

## Gating model

- The **header details** (date, time, region, type chips) are always public —
  event discovery shouldn't require a membership.
- The **virtual-attend CTA** is the gated deliverable:
  - viewer satisfies the gate (`TierResolver::satisfies($ctx['viewer'], $gate)`)
    → render `<a class="lg-event-header__join" href="{zoom_url}">{cta_label}</a>`.
  - viewer does not satisfy → render a muted upgrade card
    ("Looth Pro members join live — the recording posts to the Archive.")
    with a link to the join page. No `zoom_url` is emitted into the DOM, so
    the link can't be scraped by under-tier viewers (mirrors
    `Renderer::scrubGatedAnchors`).
- We do **not** add `event-header` to `Renderer::AUTO_GATE_TYPES` — that would
  gate the whole header. Gating is internal to the block and scoped to the CTA.
- The CTA-gate override prop is `cta_tier`, **not** `gated_tier`: the Renderer
  treats a block-level `gated_tier` key as "gate this entire block" and swaps
  in a generic gate-CTA card before render.php runs. Using a distinct key keeps
  the header visible and confines gating to the CTA.
- If the event has no `virtual-event` type and no `zoom_url`, the CTA is
  omitted entirely (in-person / recording-only events).

## Visual reference

```
┌──────────────────────────────────────────────────────────┐
│  ┌──────┐                                                 │
│  │ MAR  │   Sunday, March 29, 2026 · 3:00 PM ET           │
│  │  29  │   📍 United States                              │
│  └──────┘   [ Virtual event ] [ Instructional ]          │
│                                                            │
│   ┌────────────────────────────────────────────────────┐ │
│   │  ▶  Join on Zoom                                    │ │   ← satisfied viewer
│   └────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────┘

  (under-tier viewer sees, in place of the button:)
   ┌────────────────────────────────────────────────────┐
   │  🔒 Looth Pro members join live.                    │
   │     The recording posts to the Archive afterward.   │
   │     [ Upgrade to join → ]                           │
   └────────────────────────────────────────────────────┘
```

## Variable contract

### Container

- `padding`, `margin-block`, `bg`, `border`, `radius`, `shadow` — block chrome.

### Text

- `color` — body text (date line, region).
- `link-color` — the join CTA accent (also the date-pill bg via the variant).
- `font-family`, `font-size`, `font-weight`, `line-height` — date/time line.

Chip styling (type chips, date pill, gate card) is structural + driven off the
container/text vars; not separately user-exposed, to keep the dash panel small.
The date-pill and CTA accent track `--lg-link-color` so a single variant swap
recolors the whole block coherently.

## Defaults

### Container

```json
{ "padding": "20px 24px", "margin-block": "0 28px", "bg": "var(--lg-cream, #fbfbf8)",
  "border": "1px solid var(--lg-sage-3, #d4e0b8)", "radius": "12px", "shadow": "none" }
```

### Text

```json
{ "color": "var(--lg-ink, #323532)", "link-color": "var(--lg-amber, #ecb351)",
  "font-family": "var(--lg-font-sans, system-ui)", "font-size": "18px",
  "font-weight": "600", "line-height": "1.4" }
```

## Variants

- `variant-1` — amber accent (date pill + CTA in amber). Default.
- `variant-2` — sage accent (`--lg-link-color: var(--lg-sage)`).
- `variant-3` — neutral cream (`--lg-link-color: var(--lg-charcoal)`), for
  understated / in-person listings.

## Editor affordances

| Affordance | Setting | Notes |
|---|---|---|
| Insertable? | yes | Appears in the "+" menu (one per event by convention). |
| Inline-editable props | `cta_label` | Contenteditable; blur-saves the button label. |
| Custom picker | none | Date/time/region come from the Sheet pipeline, not hand-edited here. |
| Pill buttons | up, down, edit, variant, tier, delete | `tier` sets the `gated_tier` override on the CTA. |

The data fields are intentionally **not** dash-editable: the showrunner Sheet
owns them. Editing them here would create a second source of truth that the
next Sheet sync would clobber.

## Accessibility notes

- Date pill carries `aria-label` with the full date ("March 29, 2026").
- Type chips are a `<ul>` of plain text, not links.
- The join CTA is a real `<a>`; the gate card's upgrade action is a real `<a>`.
- 🔒 / ▶ glyphs are decorative (`aria-hidden`), never the only signal.
- Container/text default contrast meets WCAG AA.

## Opt-outs

- Participates in `columns` normalization (chrome stripped inside a column slot)
  — though event-header is expected at root, not inside columns.
- Does not participate in any hero-overlay normalization.

## Cross-block interactions

- Designed to sit immediately after (or replace the role of) `post-header` on
  an event layout. The event title + featured image remain `post-header`'s job;
  event-header adds the *when/where/attend* strip beneath it.
- The post's `event` recording, when published, is a normal `embed` block lower
  in the layout — which auto-gates against `post_tier` via `AUTO_GATE_TYPES`.

## Open questions

- **Timezone**: times are stored without a TZ; we render a static `tz_label`
  ("ET") matching the existing `UpcomingEvents::formatWhen()` behavior. Real
  per-event TZ handling is a Sheet-pipeline concern — flagged to that lane,
  not built here.
- **Reminder opt-in** (`_lg_er_fcrm_campaign_id` / `_lg_er_lead_time_minutes`):
  out of scope (Reminder Opt-In is a separate surface). A companion
  `event-reminder` block could surface it later; deferred until that lane exists.

---

**See also**
- [BLOCKS.md](../BLOCKS.md) — block index where this block is listed
- [_template.md](_template.md) — design doc template
- `blocks/post-header/` — the live-WP-read sibling this block mirrors
