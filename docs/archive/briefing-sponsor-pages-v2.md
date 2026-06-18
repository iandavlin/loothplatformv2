# Build briefing — Sponsor pages + posts, rebuilt on v2 (2026-06-09)

**Vision (Ian):** rebuild the sponsor pages + sponsor posts as **lg-layout-v2 JSON layouts** so they're
first-class managed content and **LLM-spin-uppable** (one prompt → a new sponsor page or post, like
`write-article-v2`). Get the brand data **out of ACF / WP user-meta** into **profile-app's Postgres** as a
clean structured store. Sponsors are **independent expanded functionality** — NOT member profiles; we're
borrowing the profile DB as the home for structured brand data, nothing more.

## The set
**5 real sponsors** (drop `The Guitar Specialist` #33492 — it was a tester):
| Sponsor | WP user (author) | brand_email |
|---|---|---|
| Total Vise | 739 | jeff@totalvise.com |
| Gluboost | 808 | — |
| Strings Micro Factory | 476 | — |
| Go Acoustic Audio | 1503 | — |
| Stewmac | 733 | — |

## The two identity keys (Ian)
- **Author = the content link.** `sponsor-product` + `sponsor-post` are WP CPTs authored by the sponsor
  user. The page's product/post feeds query `WHERE author = <sponsor wp_user_id>`. Keep this.
- **Email = the cross-system bridge.** A sponsor is also a billing identity; the Patreon/Stripe **poller**
  reconciles to a user by **email**. So the brand record carries `email` as the durable bridge key — it
  ties the sponsor to its poller/Stripe/Patreon identity, independent of the recyclable WP user ID.

## Where the design comes from (current live page → sections)
The live Total Vise page (screenshots on file) is a business landing page in this order:
1. **Hero** — logo + name + socials (YT/FB/IG) + CTAs (Visit Website / Visit Forum / Related Content)
2. **About / mission** — image + copy
3. **Featured products** — carousel of their `sponsor-product`
4. **Sponsored event** — featured promo banner
5. **Recent posts** — carousel of their `sponsor-post`
6. **Related content** — forum/tag cross-link
7. **Image gallery** — carousel
8. **"See who's talking about…"** — social proof + forum link
9. **Contact form** — name/email/message → emails the sponsor (lead-gen)

All of it is driven by the ACF group **"Sponsor Brand Information" (#33147, `user_form == all`)** + the
author-keyed CPTs. That ACF group + the `brand_*` user-meta is what we're retiring.

## Source ACF fields → the new `sponsor` table (migration map)
From `wp_usermeta` (group #33147). Resolve attachment IDs → URLs at migration (store resolved URLs in PG,
same pattern as the avatar backfill — don't make profile-app depend on WP media lookups).

| ACF user field | → `sponsor` column | notes |
|---|---|---|
| `brand_name` | `name` | long name ("Jeff Howard's Total Vise") |
| `brand_name_` | `display_name` | short ("TOTAL VISE") |
| `brand_logo` (att id) | `logo_url` | resolve to URL |
| `brand_hero_image` (att id) + `_caption` + `_title_` | `hero_url`, `hero_caption`, `hero_title` | resolve |
| `brand_hero_youtube_link_` | `hero_youtube` | |
| `brand_about_` | `about` | mission copy |
| `brand_email` | `email` | **the poller bridge key** |
| `brand_website` | `website` | |
| `brand_primary_color` / `brand_secondary_color_` / `brand_third_color_header_color` | `color_primary` / `color_secondary` / `color_header` | hex; theming vars |
| `brand_facebook` / `brand_instagram` / `brand_youtube` | `social_facebook` / `social_instagram` / `social_youtube` | |
| `brand_image_gallery_` (14 att ids) | `gallery_urls jsonb` | resolve each |
| `brand_tag` | `tag_url` | tagged-content archive link |
| `sponsor_forum_url` / `Sponsor_Forum_Shortcode` | `forum_url` | |
| (WP author) | `wp_user_id` | content link |
| — | `slug` | url key (e.g. `total-vise`) |

*(Ignore the legacy dupes `colors_*`, `social_media_links_*`, `website_`, `youtube_embed_` — superseded,
mostly empty. Migrate only the `brand_*` set above.)*

---

## The build — 3 lanes + a skill

### Lane A — profile-app: the brand store (profile-app lane)
1. **`sponsor` table** in profile-app PG (schema above). Keyed on `id`; carries `wp_user_id` (content link)
   + `email` (bridge) + `slug`. Sponsor-only — NOT joined to the member `users` table.
2. **Read API:** `GET /profile-api/v0/sponsor/<slug>` (and `?wp_id=` / `?email=`) → the brand record JSON.
   Public-readable (sponsor pages are public); no auth.
3. **Migration script:** lift the 5 sponsors' `brand_*` user-meta → the table, resolving attachment IDs to
   URLs. Idempotent, re-runnable. Verify each of the 5 round-trips.
4. **Retire the source:** once the table serves, deactivate the ACF "Sponsor Brand Information" group +
   leave the user-meta dormant (don't delete — rollback). ⚠️ **Cut-day:** the new table + API route are
   "doesn't ride git" infra — log them for re-apply.

### Lane B — lg-layout-v2: the sponsor blocks + theming (lg-layout-v2 lane)
New v2 blocks (engine + standalone-vendor copy + deploy), each reads the brand record (by sponsor key on
the layout) from the Lane-A API:
- `brand-hero` — logo + name + socials + CTA buttons
- `featured-products` — author-keyed carousel of `sponsor-product` (resolve cards from the CPT)
- `recent-posts` — author-keyed carousel of `sponsor-post`
- `brand-gallery` — the gallery carousel
- `contact-form` — native name/email/message form → **emails `sponsor.email`** (replaces the
  `[fluentform id=13]` shortcode; reuse a simple mailer / the membership lead path)
- `whos-talking` — forum/tag cross-link block
**Brand-color theming:** the layout root emits CSS vars (`--brand-primary` = `color_primary`, etc.) from the
brand record, so every block auto-themes to the sponsor's colors. No per-page CSS. (Same discipline as the
gate-cta block reading an option.)

### Lane C — the LLM authoring skill: `write-sponsor-v2` (new skill)
Sibling to `write-article-v2`. Inputs: a sponsor (slug/email) + raw post copy/images (or page intent) →
emits validated `_lg_layout_v2` JSON using the new blocks, wires the brand key + author association, ready
to import into a `sponsor-page` or `sponsor-post` managed CPT. Makes "spin up a new sponsor / new sponsor
post" a one-prompt op.

## Sequencing
1. **Lane A first** (table + API + migration) — the blocks depend on the API. Ship + verify the 5 records.
2. **Lane B** (blocks + theming) against the live API — build `brand-hero` + `featured-products` +
   `recent-posts` first (the page skeleton), then gallery / contact / who's-talking.
3. **Author one sponsor page** (Total Vise) as a v2 layout end-to-end → standalone render → compare to the
   live screenshot. Iterate the blocks.
4. **Lane C skill** once the block vocabulary is stable → spin up the remaining 4 + their posts.
5. **Migrate sponsor-post bodies** to v2 (the existing ~16) + re-author the few authored-by-0/admin posts
   to their sponsor (the author link must be correct for the feeds).

## Open / decide later
- `sponsor-product` fields (ACF group #47476) + `sponsor-post` fields (#33069): keep as CPTs for now; the
  feeds read them. A later pass could move product data into PG too — not this build.
- Sponsored-event banner (section 4): a static block per page for now, or wire to the `event` CPT later.

## Report back (each lane → coordinator)
`DONE · FILES · VERIFIED · NEEDS-OTHER-LANE · BLOCKED`. Report session ID + outliner title for CHATS-MENU.
Lane A reports the API shape; Lane B reports the block names + the brand-var contract; both feed Lane C.
