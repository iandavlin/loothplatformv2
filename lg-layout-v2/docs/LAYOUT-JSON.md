# Layout JSON reference

> **Auto-generated** from `blocks/*/manifest.json`. Do not edit by hand —
> run `bin/generate-schema-doc.php` to regenerate. The manifests are the
> source of truth; this doc is the human-readable projection.

## Top-level shape

Every layout JSON is an object with this wrapper:

```json
{
  "schema": 1,
  "_meta": { /* optional */ },
  "blocks": [ /* array of blocks, see Block types below */ ]
}
```

| Field | Required | Type | Notes |
|---|---|---|---|
| `schema` | yes | integer | Wire-format version. Currently `1`. Validator rejects unknown versions. See `BLOCK-ONBOARDING.md` § Versioning. |
| `_meta` | no | object | Free-form provenance (translator notes, source bundle, etc.). Ignored by the renderer. |
| `blocks` | yes | array | Ordered list of blocks. Each block is one of the types below. |

## Common block fields

Every block, regardless of type, may carry these fields in addition to its type-specific props:

| Field | Required | Type | Notes |
|---|---|---|---|
| `type` | yes | string | Block type. Must match one of the keys below. |
| `id` | no | string | Stable per-instance id (e.g., `b_abc123`). Auto-assigned `b_` + 6 chars if omitted. The editor needs ids to address blocks. |
| `gated_tier` | no | string | Tier-gating. Viewers not satisfying the tier skip this block silently. Allowed values are project-defined; see `TierResolver`. |
| `variant` | no | string | Variant key. Must be declared in the block's manifest under `variants`. Available variants per block listed below. |

## Block types

Alphabetical. Each block lists its current `manifest.version`, props, variants, and any structural notes.

### `brand-gallery`

*Version 1. Selector `.lg-brand-gallery`. Insertable.*

Sponsor-page image gallery. Reads the resolved image URLs from $ctx['sponsor'].gallery_urls (Lane-A baked these as URLs, not attachment IDs — so unlike the `gallery` block it needs no media resolver). Renders a horizontal lightbox carousel (reuses the shared [data-lg-carousel] + [data-lg-lightbox] front-end). Optional `heading`. Empty gallery_urls → block renders nothing. Auto-themes via --brand-primary.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `heading` | no | `string` | `"Gallery"` | Section heading shown above the carousel (inline-editable). |

**Variants**

- `variant-1` (extends `defaults`)

---

### `brand-hero`

*Version 1. Selector `.lg-brand-hero`. Insertable.*

Sponsor-page hero. Reads the sponsor record from $ctx['sponsor'] (resolved from the layout's sponsor key / Lane-A API): optional hero banner image, brand logo, brand name, a social-icon row (facebook / instagram / youtube / website), and a CTA strip (Visit Website / Visit Forum / Related Content). Auto-themes from the article root's --brand-* vars. Every piece degrades gracefully when its field is null — a sparse sponsor renders just the parts it has, never a broken image or empty bar. Page-level block (the top of a sponsor page); not for inline article use.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `show_hero_image` | no | `boolean` | `true` | Render sponsor.hero.url as a full-bleed banner above the brand bar. Skipped when the sponsor has no hero image. |
| `tagline` | no | `string` | `""` | Optional short line shown under the brand name (inline-editable). Falls back to sponsor.hero.caption when empty. |
| `related_label` | no | `string` | `"Related Content"` | Label for the tag_url CTA button. |

**Variants**

- `variant-1` (extends `defaults`)

---

### `callout`

*Version 2. Selector `.lg-callout`. Insertable.*

Structured aside with a title and a repeating list of items (icon + label + url + optional description). Six role-named variants pick the layout shape and accent color: links, files, people (avatars), data (dense), note (prose), quote (pull-quote).

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `title` | no | `string` | `""` | Short uppercase eyebrow above the items list (e.g. 'Reach Tim', 'Downloads', 'Guests in this piece'). Empty = no title row. |
| `items` | no | `array_of_objects` | `[]` | Rows. Each row has an icon key, a label, a URL, and an optional description. Files variant additionally honors ext/size; people variant honors image_url/initials. Ignored by note/quote variants. |
| `body` | no | `string` | `""` | Long-form HTML for variants `note` and `quote`. Authored via TinyMCE in the metabox repeater UI (Phase B); for now, paste-JSON. Sanitized with wp_kses_post on save. Ignored by list-style variants. |
| `attribution` | no | `string` | `""` | quote-variant only: short attribution line shown below the pull-quote. |
| `variant` | no | `string` | `"links"` | Role-named visual treatment. links/files/people/data render items as a list. note renders body as prose. quote renders body as a large italic pull-quote with attribution. Enum: `links` / `files` / `people` / `data` / `note` / `quote`. |

**Variants**

- `links` (extends `defaults`)
- `files` (extends `defaults`)
    - `container.bg`: `#fdf7e8`
- `people` (extends `defaults`)
    - `container.bg`: `#f3f6ec`
    - `container.border-color`: `#87986a`
    - `text.title-color`: `#586b3f`
    - `text.accent-color`: `#586b3f`
- `data` (extends `defaults`)
    - `container.bg`: `#f7f5f0`
    - `container.border-color`: `#323532`
    - `text.title-color`: `#323532`
    - `text.accent-color`: `#323532`
- `note` (extends `defaults`)
    - `container.bg`: `#fdfaf0`
- `quote` (extends `defaults`)
    - `container.bg`: `#fdfaf0`
    - `container.padding`: `24px 28px 22px 32px`

---

### `columns`

*Version 2. Selector `.lg-columns`. Insertable.*

Multi-column container. Children live in explicit per-column buckets (block.columns[N].blocks). 2 or 3 columns supported; columns stack vertically on narrow viewports. Optional chrome (bg/border/radius/padding) lets the whole row read as a framed callout.

**Structural shape** (overrides the standard `blocks` field):

```json
{
  "type": "columns",
  "columns": [
    { "blocks": [ /* children of column 1 */ ] },
    { "blocks": [ /* children of column 2 */ ] }
  ]
}
```

Column count is `columns.length` and must be 2 or 3. Nesting columns inside columns is rejected by the validator.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `columns` | no | `array` | `[]` | Array of column buckets. Each bucket is an object with a `blocks` array of child blocks. Length determines the column count (2 or 3). |
| `variant` | no | `string` | `"variant-1"` | Visual style. variant-1 = plain (no chrome, like a bare row). variant-2 = framed (cream panel + sage border). variant-3 = dark (ink bg). Enum: `variant-1` / `variant-2` / `variant-3`. |

**Variants**

- `variant-1` (extends `defaults`)
- `variant-2` (extends `defaults`)
    - `container.padding`: `20px`
    - `container.bg`: `var(--lg-cream, #fbfbf8)`
    - `container.border`: `1px solid var(--lg-sage-3, #d4e0b8)`
    - `container.radius`: `8px`
- `variant-3` (extends `defaults`)
    - `container.padding`: `20px`
    - `container.bg`: `var(--lg-ink, #323532)`
    - `container.radius`: `8px`

---

### `contact-form`

*Version 1. Selector `.lg-contact-form`. Insertable.*

Sponsor-page lead-gen form (replaces the old [fluentform id=13]). Native name / email / message fields that POST to the sponsor-contact endpoint, which emails sponsor.email (the durable bridge key). The sponsor slug + recipient are carried in hidden fields so the endpoint never trusts a client-supplied address. When sponsor.email is present a 'or email us directly' mailto: fallback renders so the form is never a dead end; when it's null the form is suppressed (no recipient to send to). Auto-themes via --brand-primary. NOTE: the POST endpoint (POST /archive-api/v0/sponsor-contact { slug, name, email, message }) is the one server-side piece this block depends on.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `heading` | no | `string` | `"Get in touch"` | Section heading (inline-editable). |
| `blurb` | no | `string` | `""` | Optional intro line under the heading (inline-editable). |
| `action` | no | `string` | `"/archive-api/v0/sponsor-contact"` | Form POST endpoint. Receives { slug, name, email, message } and emails the sponsor. Override only if the endpoint path changes. |

**Variants**

- `variant-1` (extends `defaults`)

---

### `divider`

*Version 1. Selector `.lg-divider`. Insertable.*

Horizontal rule with brand styling. Variants: default line, dots, or invisible space.

**Variants**

- `dots` (extends `defaults`)
    - `container.color`: `var(--lg-sage)`
- `space` (extends `defaults`)
    - `container.margin-block`: `48px`

---

### `download`

*Version 1. Selector `.lg-download`. Insertable.*

A single downloadable file rendered as a download button. Resolves file_id through the media map (url, filename, mime, human size are pre-baked) — no WP attachment calls at render. Visually reuses the callout 'files' treatment. Emitted by the loothprint/loothcut conversion as a bare { type: download, file_id }.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `file_id` | no | `int` | `0` | Attachment ID of the downloadable file. Resolved to a URL + filename + size via the media map. |
| `url` | no | `string` | `""` | Explicit download URL. Overrides file_id resolution when set (e.g. off-site file). |
| `label` | no | `string` | `""` | Override for the row label. Empty falls back to the file's title, then its filename, then 'Download File'. |
| `title` | no | `string` | `"Download"` | Eyebrow shown above the download row. Empty = no eyebrow. |

---

### `embed`

*Version 1. Selector `.lg-embed`. Insertable.*

Third-party media embed (YouTube, Vimeo, Twitter, SoundCloud, etc.) via URL. Aspect-ratio reservation gives zero cumulative layout shift.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `url` | yes | `string` | `""` | Source URL. Resolved at render time via WP oEmbed cache. |
| `ratio` | no | `string` | `"16x9"` | Aspect ratio for the reserved box. Authoring pill cycles through these. Enum: `16x9` / `4x3` / `1x1` / `9x16` / `21x9`. |
| `caption` | no | `string` | `""` | Optional caption below the embed. Plain text, escaped. |
| `variant` | no | `string` | `"variant-1"` | Visual style. variant-1 = framed (cream bg + sage border, current default). variant-2 = borderless (transparent, no chrome — just the embed). Enum: `variant-1` / `variant-2`. |

**Variants**

- `variant-1` (extends `defaults`)
- `variant-2` (extends `defaults`)
    - `container.padding`: `0`
    - `container.bg`: `transparent`
    - `container.border`: `none`
    - `container.radius`: `0`

**Context normalization**: participates in `columns`.

---

### `event-header`

*Version 1. Selector `.lg-event-header`. Insertable.*

Event header strip for the `event` CPT: date pill + full date/time line, region chip, event-type chips, and a tier-gated virtual-attend CTA. Reads live from event postmeta (showrunner Sheet pipeline owns the data); every field has an explicit-prop override for static-bake / CLI snapshot testing. Header details are public; only the Zoom CTA gates against the event's tier.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `date` | no | `string` | `""` | Override start date as YYYYMMDD. Empty → read postmeta `events_start_date_and_time_`. |
| `time` | no | `string` | `""` | Override start time as HH:MM:SS (24h). Empty → read postmeta `time_of_event`. |
| `tz_label` | no | `string` | `"ET"` | Timezone label appended to the rendered time (times are stored without a TZ). |
| `region` | no | `string` | `""` | Override region display name. Empty → read the post's `region` taxonomy term name. |
| `event_types` | no | `array` | `[]` | Override list of event-type chip labels. Empty → read the post's `event-type` taxonomy term names. |
| `zoom_url` | no | `string` | `""` | Override the virtual-attend URL. Empty → read postmeta `zoom_url_for_looth_group_virtual_event`. |
| `cta_label` | no | `string` | `"Join on Zoom"` | Label on the virtual-attend button. |
| `cta_tier` | no | `string` | `""` | Override the tier required to see the live Zoom link. Empty → use the post's tier ($ctx['post_tier']). NOTE: deliberately NOT named `gated_tier` — that key is reserved by the Renderer for whole-block gating, which would replace this entire header with a gate-CTA card. This prop gates only the virtual-attend CTA, internal to render.php. Enum: `` / `public` / `looth-lite` / `looth-pro`. |
| `variant` | no | `string` | `"variant-1"` | Visual palette. variant-1 = amber accent. variant-2 = sage accent. variant-3 = neutral cream (understated / in-person). Enum: `variant-1` / `variant-2` / `variant-3`. |

**Variants**

- `variant-1` (extends `defaults`)
- `variant-2` (extends `defaults`)
    - `text.link-color`: `var(--lg-sage, #87986a)`
- `variant-3` (extends `defaults`)
    - `text.link-color`: `var(--lg-charcoal, #1a1d1a)`

**Context normalization**: participates in `columns`.

---

### `featured-products`

*Version 1. Selector `.lg-feat-products`. Insertable.*

Sponsor-page product carousel. Shows the sponsor's `sponsor-product` CPT items as a horizontal scroll-snap strip of cards (thumbnail + title + optional price + optional CTA). The cards are baked into the `items` prop at author/materialize time by querying WHERE author = sponsor.wp_user_id (the `author` prop records that link so write-sponsor-v2 can refresh) — so render stays WordPress-free in the standalone path. Reuses the shared [data-lg-carousel] front-end (prev/next + scroll-snap). Auto-themes from the article root's --brand-primary. Empty items → block renders nothing (no empty strip).

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `heading` | no | `string` | `"Featured Products"` | Section heading shown above the carousel (inline-editable). |
| `author` | no | `integer` | `0` | Sponsor wp_user_id this feed is keyed to. Render uses the baked `items`; this records the author link so the authoring skill can re-query and re-bake. |
| `items` | no | `array` | `[]` | Baked product cards: [{ title, url, image, price?, badge? }]. Order = render order. Resolved from sponsor-product at author time. |

**Variants**

- `variant-1` (extends `defaults`)

---

### `gallery`

*Version 1. Selector `.lg-gallery`. Insertable.*

Multi-image grid. Renders an N-column tile grid (default 3) of attachment-driven images. Each tile is lightbox-tagged and pulls its lightbox caption from the WP attachment's own caption (post_excerpt) so it travels with the file — same model as the image block. Useful for repair sequences, before/afters, or any “here are the photos” block where the per-image article-text isn't needed inline.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `image_ids` | yes | `array` | `[]` | Array of attachment IDs (integers). Order = render order. Each ID is resolved to a URL + alt + caption via the media resolver at render time. |
| `columns` | no | `integer` | `3` | Tile columns at desktop widths. Collapses to 2 below 960px and 1 below 560px regardless of this setting. Enum: `2` / `3` / `4`. |
| `layout` | no | `string` | `"grid"` | grid = uniform square tiles (object-fit: cover). masonry = CSS column-count layout that preserves natural aspect ratios; Pinterest-style stagger. carousel = horizontal scroll-snap strip with prev/next nav arrows; tile width is fixed, height tracks the row. Enum: `grid` / `masonry` / `carousel`. |
| `image_text` | no | `string` | `""` | Optional figcaption rendered below the whole grid. Multi-paragraph plain text — split on blank lines into <p>s at render. Article-side text, not per-image. The per-image lightbox caption comes from the attachment itself. |
| `variant` | no | `string` | `"variant-1"` | Visual style. variant-1 = bare tiles (no surrounding chrome). variant-2 = framed (cream panel + sage border, like the framed-image variant). Enum: `variant-1` / `variant-2`. |

**Variants**

- `variant-1` (extends `defaults`)
- `variant-2` (extends `defaults`)
    - `container.padding`: `16px`
    - `container.gap`: `16px`
    - `container.bg`: `var(--lg-cream, #fbfbf8)`
    - `container.border`: `1px solid var(--lg-sage-3, #d4e0b8)`
    - `container.radius`: `8px`

**Context normalization**: participates in `columns`.

---

### `image`

*Version 1. Selector `.lg-image`. Insertable.*

Image with optional caption underneath. All chrome flows through dash-tweakable variables.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `image_id` | yes | `integer` | `—` | Attachment ID. Resolved to a URL via the media resolver at render time. Named image_id (not id) because 'id' is the universal block-instance identifier (b_xxx string) and we don't want the two conflated. |
| `url` | no | `string` | `""` | Optional explicit image URL. If absent, resolved from image_id. |
| `alt` | no | `string` | `""` | Alt text for accessibility. Empty string only if the image is purely decorative. |
| `image_text` | no | `string` | `""` | Per-instance article text shown as the figcaption below the image AND as the lightbox caption when the image is opened. Multi-paragraph plain text — split on blank lines into <p>s at render; newlines collapse to spaces in the lightbox pill. |
| `number` | no | `string` | `""` | Optional badge text shown as a small overlay in the top-left corner of the image (e.g. '1', '01', 'A'). Empty = no badge. Free-form string so authors can use any short label, not just integers. |
| `variant` | no | `string` | `"variant-1"` | Visual style variant. variant-1 = default chrome (sage border + cream bg). variant-2 = framed (heavier border, larger radius, drop shadow). Enum: `variant-1` / `variant-2`. |
| `aspect` | no | `string` | `""` | Optional display aspect ratio (e.g. '1/1', '16/9', '4/3', '3/2'). When set, the image frame is locked to this ratio and the image fills via object-fit: cover. Empty = render at the source image's intrinsic aspect. Use for hero crops or column-pair normalization. |
| `focal_x` | no | `integer` | `50` | Horizontal focal point (0-100, percentage from left). Drives object-position when `aspect` crops the image. 50 = centered. Lower = subject is on the left side of the source image; higher = right side. |
| `focal_y` | no | `integer` | `50` | Vertical focal point (0-100, percentage from top). Drives object-position when `aspect` crops the image. 50 = centered. Lower = subject near top; higher = subject near bottom. |
| `zoom` | no | `integer` | `100` | Zoom level as a percentage of the natural cover-fit size (100 = no zoom, 200 = 2x, 300 = 3x). Drives a CSS scale on the img inside the cropped frame. Higher values give more pan range to reach edges of the source. Only meaningful when `aspect` is set. |

**Variants**

- `variant-1` (extends `defaults`)
- `variant-2` (extends `defaults`)
    - `container.padding`: `10px 10px 14px`
    - `container.bg`: `#fff`
    - `container.border`: `1px solid var(--lg-ink, #323532)`
    - `container.radius`: `2px`
    - `container.shadow`: `0 6px 18px rgba(0, 0, 0, 0.18)`

**Context normalization**: participates in `columns`.

---

### `license`

*Version 1. Selector `.lg-license`. Insertable.*

The Creative Commons licence a post is published under, rendered as a choice: the licence name, a link to its canonical deed, and one chip per clause it imposes (credit / non-commercial / share-alike / no-derivatives). With `code` empty (the default) the block resolves the licence LIVE from the post's loothprint_creative_commons meta, so a member changing it in the form changes the page. Setting `code` pins the block to one licence instead.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `code` | no | `string` | `""` | Which Creative Commons licence. EMPTY (default) means follow the post's own licence meta at render time — that is what keeps a loothprint page in step with the form. A non-empty code pins this block to that licence and stops it tracking the post. Enum: `` / `by` / `by-sa` / `by-nc-sa` / `by-nc-nd`. |
| `title` | no | `string` | `"License"` | Short uppercase eyebrow above the licence row. Empty = no eyebrow. |
| `show_deed` | no | `bool` | `true` | Link the licence name to its canonical creativecommons.org deed. The link carries rel="license", the standard machine-readable licence signal. |
| `variant` | no | `string` | `"note"` | note stacks the licence name over its clause chips, matching the callout `note` treatment the loothprint page uses today. compact puts name and clauses on one line. Enum: `note` / `compact`. |

**Variants**

- `note` (extends `defaults`)
- `compact` (extends `defaults`)
    - `container.padding`: `12px 18px`
    - `container.margin-block`: `16px`

---

### `paywall`

*Version 1. Selector `.lg-paywall`. Insertable.*

Section gate line. Everything below this block is rendered only to viewers at the declared tier or higher. Members see a labeled divider; non-members get the gate-CTA card in place of the line and everything past it is trimmed from the output.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `tier` | no | `string` | `"looth-pro"` | Required tier. Viewers at this tier or higher see the rest of the post; everyone else sees a CTA card and nothing past the line. Enum: `looth-lite` / `looth-pro` / `looth-group` / `admin`. |
| `label` | no | `string` | `"Members only below"` | Plain text shown on the divider for members. Tier name auto-appended if absent. |

---

### `post-footer`

*Version 1. Selector `.lg-post-footer`. Insertable.*

Article footer: author card (avatar + bio + social links + 'more from author' CTA) followed by a 'Keep reading' related-posts grid. Mirrors the post-header's accent palette so a post reads as one piece top-to-tail. Best as the last block in a post layout.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `show_author` | no | `boolean` | `true` | Show the author card (avatar + bio + socials + CTA). |
| `show_related` | no | `boolean` | `true` | Show the 3-card 'Keep reading' related grid. |
| `show_comments` | no | `boolean` | `true` | Show the comments thread to logged-in viewers. Logged-out viewers never see it regardless of this toggle. |
| `show_share` | no | `boolean` | `true` | Show the share row (X, Facebook, email, copy-link). Renders above the author card. |
| `related_count` | no | `integer` | `3` | How many related posts to show. The picker draws from a shared-tag + same-author + same-CPT pool. |
| `related_heading` | no | `string` | `"Keep reading"` | Heading text above the related-posts grid. |
| `author_cta_label` | no | `string` | `"More from {author} \u2192"` | CTA link below the bio. {author} → display name. |
| `variant` | no | `string` | `"variant-1"` | Accent palette. variant-1 = amber (matches default post-header). variant-2 = sage. variant-3 = neutral cream. Enum: `variant-1` / `variant-2` / `variant-3`. |

**Variants**

- `variant-1` (extends `defaults`)
- `variant-2` (extends `defaults`)
    - `text.link-color`: `var(--lg-sage, #87986a)`
- `variant-3` (extends `defaults`)
    - `text.link-color`: `var(--lg-cream, #fbfbf8)`

---

### `post-header`

*Version 1. Selector `.lg-post-header`. Insertable.*

Article header: featured image with gradient scrim, title + byline overlaid, tags in a strip below. Pulls author + meta dynamically from the current post; image_id falls back to the post thumbnail. Best as the first block in a post layout.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `image_id` | no | `integer` | `0` | Attachment ID for the hero photo. 0 → use the post's featured image. |
| `tagline` | no | `string` | `""` | Optional one-line dek shown under the title (the magazine-style 'sub-headline'). Empty = none. |
| `show_read_time` | no | `boolean` | `true` | Show estimated read time alongside the date in the byline. Computed from wysiwyg block word counts. |
| `hidden_links` | no | `array` | `[]` | Per-post hide-list of author social slot keys (e.g. ["facebook", "youtube"]). Matching slots are dropped from the social-icon row even if the author has the meta filled in. Edited via the pencil + modal on the social row. |
| `video_url` | no | `string` | `""` | YouTube or Vimeo URL. Only meaningful for variant=video. Renders the hero as a facade — the post's featured image (or image_id) shows as the thumbnail with a play-button overlay; click swaps in the real iframe in place. No JS or iframe loads until the user clicks. |
| `variant` | no | `string` | `"variant-1"` | Color palette / mode. variant-1 = amber accent. variant-2 = sage. variant-3 = neutral cream. video = video-hero facade (play button + click-to-play iframe; uses video_url). sponsor = branded sponsor-post header — replaces author byline with brand identity row (logo, name, social icons) + CTA strip; reads brand_* and tlg_* user meta from the post author. Enum: `variant-1` / `variant-2` / `variant-3` / `video` / `sponsor`. |

**Variants**

- `variant-1` (extends `defaults`)
- `variant-2` (extends `defaults`)
    - `text.link-color`: `var(--lg-sage-3, #d4e0b8)`
- `variant-3` (extends `defaults`)
    - `text.link-color`: `var(--lg-cream, #fbfbf8)`
- `video` (extends `defaults`)
- `sponsor` (extends `defaults`)

---

### `recent-posts`

*Version 1. Selector `.lg-recent-posts`. Insertable.*

Sponsor-page post carousel. Shows the sponsor's `sponsor-post` CPT items as a horizontal scroll-snap strip of cards (thumbnail + title + date + excerpt). Cards are baked into the `items` prop at author/materialize time by querying WHERE author = sponsor.wp_user_id (the `author` prop records that link for write-sponsor-v2 to refresh) — render stays WordPress-free in the standalone path. Reuses the shared [data-lg-carousel] front-end. Auto-themes from --brand-primary. Empty items → block renders nothing.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `heading` | no | `string` | `"Recent Posts"` | Section heading shown above the carousel (inline-editable). |
| `author` | no | `integer` | `0` | Sponsor wp_user_id this feed is keyed to. Render uses the baked `items`; this records the author link for re-baking. |
| `items` | no | `array` | `[]` | Baked post cards: [{ title, url, image, date?, excerpt? }]. Order = render order. Resolved from sponsor-post at author time. |

**Variants**

- `variant-1` (extends `defaults`)

---

### `section-heading`

*Version 1. Selector `.lg-section-heading`. Insertable.*

Boxed section heading — like heading, but wrapped in a container with bg/border/radius so it reads as a visual section break inside an article. Level (h2/h3/h4) is a regular prop; the variant axis is free for color/style flavors of the box.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `text` | yes | `string` | `""` | The heading's text content. Plain text, not HTML; escaped on render. |
| `level` | no | `string` | `"h2"` | Heading level used for the rendered HTML tag. Drives semantic outline + the size modifier class. Enum: `h2` / `h3` / `h4`. |
| `variant` | no | `string` | `"variant-1"` | Visual style. variant-1 = plain (no chrome — straight heading). variant-2 = boxed (cream bg + sage border). variant-3 = inverted (ink bg + amber text). Enum: `variant-1` / `variant-2` / `variant-3`. |

**Variants**

- `variant-1` (extends `defaults`)
- `variant-2` (extends `defaults`)
    - `container.padding`: `16px 20px`
    - `container.bg`: `var(--lg-cream, #fbfbf8)`
    - `container.border`: `1px solid var(--lg-sage-3, #d4e0b8)`
    - `container.radius`: `8px`
- `variant-3` (extends `defaults`)
    - `container.padding`: `16px 20px`
    - `container.bg`: `var(--lg-ink, #323532)`
    - `container.radius`: `8px`
    - `text.color`: `var(--lg-amber, #ecb351)`

**Context normalization**: participates in `columns`.

---

### `transcript`

*Version 1. Selector `.lg-transcript`. Insertable.*

Collapsible transcript body. Renders as a native HTML <details>/<summary> accordion so the prose is always in the DOM (search-crawlable) while staying collapsed by default to keep the page short. Authors paste the transcript HTML once; the chrome is provided.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `label` | no | `string` | `"Show transcript"` | Summary text shown on the collapsed accordion (the clickable affordance). |
| `body` | no | `string` | `""` | Transcript HTML — paragraphs, in-line timestamps, light emphasis. Sanitized with wp_kses_post on save. Lives in the DOM whether the accordion is open or closed. |
| `open` | no | `boolean` | `false` | Initial state. Default closed (collapsed). |

---

### `whos-talking`

*Version 1. Selector `.lg-whos-talking`. Insertable.*

Sponsor-page social-proof / cross-link panel. Reads sponsor.forum_url + sponsor.tag_url + name from $ctx['sponsor'] and renders a 'See who's talking about <brand>' card with up to two CTAs: the sponsor's forum thread and its tagged-content archive. Each CTA only renders if its URL is present (a sponsor with no forum still shows the tag link, or nothing). Auto-themes via --brand-primary / --brand-header.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `heading` | no | `string` | `""` | Override the auto 'See who's talking about <brand>' heading (inline-editable). |
| `blurb` | no | `string` | `""` | Optional intro line under the heading (inline-editable). |

**Variants**

- `variant-1` (extends `defaults`)

---

### `wysiwyg`

*Version 1. Selector `.lg-wysiwyg`. Insertable.*

TinyMCE-edited rich text body copy. Paragraphs, in-body headings (H1–H3), inline emphasis, lists, links.

**Props**

| Name | Required | Type | Default | Notes |
|---|---|---|---|---|
| `html` | no | `string` | `""` | Rich-text HTML from TinyMCE. Sanitized with wp_kses_post on save. |
| `style` | no | `string` | `"plain"` | Visual variant. plain = transparent body copy. panel = framed callout with bg + padding + radius. Enum: `plain` / `panel`. |

**Variants**

- `plain` (extends `defaults`)
- `panel` (extends `defaults`)
    - `container.padding`: `20px 24px`
    - `container.bg`: `var(--lg-cream, #fbfbf8)`
    - `container.border`: `1px solid var(--lg-sage-3, #d4e0b8)`
    - `container.radius`: `8px`

**Context normalization**: participates in `columns`.

---

## Examples

### Minimal layout (a heading + a paragraph)

```json
{
  "schema": 1,
  "blocks": [
    { "type": "heading", "level": "h2", "text": "Hello world" },
    { "type": "wysiwyg", "html": "<p>First post.</p>" }
  ]
}
```

### Two-column layout

```json
{
  "schema": 1,
  "blocks": [
    {
      "type": "columns",
      "columns": [
        { "blocks": [ { "type": "image", "image_id": 123 } ] },
        { "blocks": [ { "type": "wysiwyg", "html": "<p>Caption-style prose.</p>" } ] }
      ]
    }
  ]
}
```

### Embed with caption

```json
{
  "schema": 1,
  "blocks": [
    {
      "type": "embed",
      "url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
      "caption": "Optional caption text."
    }
  ]
}
```

*YouTube `shorts/` URLs auto-resolve to 9×16; standard YT URLs resolve to 16×9. Instagram URLs route through `embeds.js` and ignore `ratio`.*

