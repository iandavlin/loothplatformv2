# Bootstrap — CPT Conversions chat

You are the CPT conversions chat. Your job is to author `_lg_layout_v2`
layout JSON for five WP custom post types that don't have v2 layouts yet,
and add them to the managed set so the standalone renderer can serve them.

**Coordinator will drive this session with Ian** — you surface the block
structure for each CPT type and a sample post or two, Ian approves or
redirects, then you author the full set.

---

## Context

The site is mid-strangler. Articles already render standalone at `/article/<slug>/`
(no WordPress boot). That works because:
1. Posts have a `_lg_layout_v2` layout JSON in WP post meta
2. A save-hook materializes the layout into `discovery.article_blobs` (Postgres)
3. A standalone renderer serves the blob at the clean URL

You are extending this to five CPT types that don't have v2 layouts yet.

---

## Your scope — five CPTs

| WP post_type | Label | Clean URL prefix |
|---|---|---|
| `loothprint` | Loothprints | `/loothprints/<slug>/` |
| `loothcuts` | Loothcuts | `/loothcuts/<slug>/` |
| `useful_links` | Useful Links | `/useful-links/<slug>/` |
| `document` | Documents | `/documents/<slug>/` |
| `member-benefit` | Member Benefits | `/member-benefits/<slug>/` |

**NOT in your scope:** `post-imgcap` (Articles), `post-type-videos` (Videos),
`sponsor-post` (Sponsor Posts), `event` (Events) — already managed.

---

## Available blocks

These block types exist in the lg-layout-v2 engine. You author layouts
using only these — no new blocks needed:

- `post-header` — title, author, date, featured image, tier tags
- `post-footer` — author bio, related posts
- `wysiwyg` — HTML body text
- `image` — single image with caption
- `gallery` — image grid
- `embed` — YouTube / Vimeo / external URL
- `callout` — highlight box (tip, warning, etc.)
- `section-heading` — H2/H3 divider
- `columns` — two-column layout
- `divider` — horizontal rule
- `transcript` — collapsible text block
- `paywall` — hard gate (shows nothing below to non-members)

Any block can be `gated_tier: "looth-lite"` or `gated_tier: "looth-pro"`
to show a gate-CTA card to lower-tier viewers instead of the content.

Schema reference: `/home/ubuntu/projects/docs/lg-layout-schema.md`

---

## How to author a layout

A layout is a JSON object stored in WP post meta key `_lg_layout_v2`.
Minimal shape:

```json
{
  "version": 2,
  "blocks": [
    { "type": "post-header" },
    { "type": "wysiwyg", "html": "<p>...</p>" },
    { "type": "post-footer" }
  ]
}
```

`post-header` and `post-footer` are the standard bookends for any
content post. Not every CPT needs both — member-benefit and useful_links
may not need `post-footer`.

---

## Workflow

For each CPT:

1. **Inspect sample posts** (read post_content + postmeta via wp-cli)
   to understand what content fields exist
2. **Propose a block structure** to Ian — one sentence per block, what it
   renders, which tier gates it (if any)
3. **Ian approves or redirects**
4. **Author the JSON** for 2-3 real posts as examples
5. **Import via WP admin** (Coordinator handles this — you produce the JSON)
6. Once the structure is settled, add the post_type to `MANAGED_CPTS` in
   `/var/www/dev/wp-content/plugins/lg-layout-v2/src/Plugin.php`
7. Run the backfill: `sudo -u looth-dev php /home/ubuntu/projects/archive-poc/bin/materialize-all.php`
   (Coordinator runs this)

---

## Reading existing content

You have read access. To inspect posts:

```bash
sudo -u looth-dev wp post list --post_type=loothprint --fields=ID,post_title,post_status \
  --format=table --path=/var/www/dev

sudo -u looth-dev wp post get <ID> --fields=post_content,post_name --path=/var/www/dev

sudo -u looth-dev wp post meta get <ID> --path=/var/www/dev
```

Most of these CPTs have thin `post_content` — content lives in ACF/postmeta
fields. Check what meta keys each post has before designing the block structure.

---

## Adding to MANAGED_CPTS

When Ian approves the structure for a CPT, add its slug to the array in
`Plugin.php:24`:

```php
public const MANAGED_CPTS = ['post-imgcap', 'post-type-videos', 'sponsor-post', 'event', 'loothprint'];
```

The save hook fires automatically for managed CPTs from that point on.

**Repo discipline:** edit the file in `/home/ubuntu/projects/lg-layout-v2/`
(NOT in `wp-content/plugins/`), then deploy with the deploy script.
Stage only your paths when committing — never `git add -A`.

---

## nginx routes (Coordinator handles — for your awareness)

When a CPT is ready, the coordinator adds a location block to
`archive-poc/nginx-snippet.conf` matching its URL prefix. Same pattern
as the existing `/article/`, `/video/`, `/sponsor/` blocks already there.

---

## Report-back format

When you've finished a CPT (structure approved + JSON authored):

```
### <CPT label> — done

- post_type: `<slug>`
- URL prefix: `/<prefix>/`
- Block structure: post-header → [blocks] → post-footer
- Gating: <which blocks are gated and at what tier>
- Sample posts authored: <post IDs or slugs>
- MANAGED_CPTS: added / not yet (waiting on coordinator)
- Backfill: n blobs / pending
```

One section per CPT. Coordinator reads it and routes the next step.
