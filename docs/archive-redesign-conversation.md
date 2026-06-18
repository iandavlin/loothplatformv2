# Archive page redesign — design conversation log

**Date:** 2026-05-24
**Status:** Exploration / pre-spec. No code written beyond mockups.
**Mockups:** https://dev.loothgroup.com/mockups/archive.html  (file: `/var/www/dev/mockups/archive.html`)

This file captures a long design conversation between Ian and Claude about the archive/loop page, plus adjacent topics that fell out of it (search, discovery, bookmarks, history, forum integration, Strangler-vine migration off WordPress, CPT consolidation, and Profile 2.0). Saved here so it survives across machines.

---

## 1. Starting problem

The current archive page (built with Elementor + Search & Filter Pro) exposes **8 controls before the user sees a single post**:

- Search full archive (text)
- Select post types (dropdown)
- Author search (dropdown)
- Topic search (dropdown)
- Tag search (dropdown)
- Video types (dropdown)
- Loothprint types (dropdown)
- Article types (dropdown)
- "Looth Pro (52)" checkbox

Plus Submit/Reset buttons. Filter overload, very heavy load. Each facet click does a full WP_Query + page reload via S&F Pro.

**Goal:** lighter page, better UX, fewer visible controls without losing power.

---

## 2. Architectural options (cheapest → richest)

1. **Static JSON index + tiny JS frontend.** Cron writes `archive-index.json`. Client-side filter/search. ~5KB JS. Best fit for <5k posts.
2. **Custom REST endpoint + JS frontend.** One tuned `/wp-json/looth/v1/archive` route. Scales past 10k. ~10× faster than S&F Pro.
3. **Meilisearch/Typesense sidecar.** Overkill unless we want serious relevance + autocomplete.

**Note:** Relevanssi is already installed — can be used inside option 2 without adding new infra.

**Integration: bypass template, not shortcode.** Same pattern as post-imgcap / lg-layout-v2 — a custom template that skips BuddyBoss wrappers + Elementor. Embedding the new fast thing inside the slow Elementor shell wastes most of the win.

---

## 3. UX variants explored (in `/var/www/dev/mockups/archive.html`)

All share the same card design so filter-UX is isolated from visual.

### A · Search-forward
One search bar + sort + "Filters (n)" button that opens a drawer. Active filters appear as removable chips above results. **Trade-off:** filters one click away, not zero.

### B · Smart sidebar with progressive disclosure
Sticky left sidebar. Type-specific sub-options (article subtypes, video subtypes, loothprint subtypes) appear *only* when the parent type is selected. Cuts visible controls in half without losing power.

### C · Segmented tabs + pills (Ian's pick)
Type tabs front and center (All / Articles / Videos / Loothprints / Events). Sub-pills change per tab. Search + sort up top. **Why C wins:** type tabs match how readers describe content ("did you see that video where…"), and sub-pills change per tab so article-types/video-types never compete for space.

### D · Discovery / Netflix-style rows
Default landing mode. Horizontal-scroll rows (native `scroll-snap`, no carousel library). Rows: Featured, Continue, Because-you-liked, #tag-driven, New this week, Series, Deep cuts. Search/filter flips the page to grid mode (C). Same JSON powers both modes.

**Performance budget for D:** ~400KB total initial load (HTML 5KB + CSS 8KB + JS 10KB + archive.json ~300KB gzipped + 6 visible thumbs ~90KB). Compare to current ~2-3MB Elementor + S&F Pro path.

### E · Discussions + bookmarks + history
Added "Discussions" tab with a forum-card style (no hero image; avatar + question + first 3 lines + reply/participant counts + "Resolved" / "Active · Nm ago" badges). Bookmark icon on every card (outlined = unsaved, filled-amber = saved). "Saved for later" row + "Recently viewed" row in discovery. `/me/saved` and `/me/history` pages.

---

## 4. Key UX decisions reached

- **D as default landing, C as search/filter mode.** Discovery rows do double duty as marketing surface for gated content (lock badges + free-pick banner) and as low-cognition browsing.
- **Filters appear from results, not hardcoded.** Empty state = discovery rows (no filter overload). When a search/tag-click produces results, the filters that appear are derived from what's *in* those results (filter values that don't apply don't show).
- **Soft-gate signals on Pro content.** Show Pro cards with a small lock badge in rows, plus a weekly "free Pro pick" banner — turns gated content into marketing surface for non-Pro users instead of being invisible until clicked.
- **Tags as in-card filter chips, not nav.** One tag per card, the most specific/rarest one. `#prefix`, sage hover. Click → filters current view. NOT a separate tag-archive surface (tag-clouds are dead UX).
- **Forum content folded into the same archive.** Forum topics get their own tab + a different card style (avatar OP + question + reply count + last-activity). High-signal content currently invisible to the main search.
- **Bookmarks** = explicit save, persistent. **History** = implicit (open ping → /me/history + Continue row).

---

## 5. Tracking / privacy answer

Q: Are we putting tracking cookies on people for "Because you liked"?

A: **No third-party tracking needed at all.** Site is logged-in, so:
- **Likes** are already tracked (wp-ulike, per-user, explicit) — powers "Because you liked X" with no new data.
- **Page views** are tracked by Burst Statistics (188K rows), but with anonymous hashed `uid` — useless for per-user resume.
- **History / read-progress** is the only thing needing new instrumentation. Tiny `/wp-json/looth/v1/progress` endpoint writing to `wp_usermeta` on scroll milestones / video `timeupdate`. ~30 lines. First-party, no cookies, scoped to logged-in users.
- **No GA / Facebook pixel / fingerprinting / anonymous tracking** required.

---

## 6. Strangler-vine off WordPress

Ian raised the possibility of eventual migration off WP. The conversation reframed:

**Build the index DB outside WP from the start.** Same work as the perf project, but:
- Today: WP keeps publishing; index reads from WP via `save_post` hook (POST to indexer service); new archive UI reads index. Search & Filter Pro + Elementor's archive footprint go away.
- Tomorrow: New non-WP admin authors content directly into the index. WP becomes read-only, then archived.
- Always: Postgres with `tsvector + pg_trgm + GIN` (and optional `pgvector`) — search quality WP can't match.

### Proposed Postgres schema (working draft)

```sql
content_item (
  id              uuid          -- canonical, stable, not WP ID
  source          text          -- 'wp' for now; 'native' later
  source_ref      text          -- wp post_id, for re-sync
  kind            text          -- article | video | loothprint | event | discussion | profile
  subkind         text          -- how-to | profile | opinion | review | ...
  title           text
  slug            text
  excerpt         text
  body_md         text          -- extracted from lg-layout-v2 JSON
  layout_json     jsonb         -- the v2 layout, canonical
  thumb_url       text
  hero_url        text
  author_id       uuid          -- FK person
  series_id       uuid          -- FK series (nullable)
  tier            text          -- public | lite | pro
  published_at    timestamptz
  last_activity   timestamptz   -- for discussions
  reply_count     int
  participant_count int
  like_count      int           -- denormalized from wp_ulike
  view_count      int
  status          text
  search_vector   tsvector
  embedding       vector(384)   -- optional, for "more like this"
  kind_specific   jsonb         -- video duration, STL url, event date, etc.
)

person (
  id              uuid
  wp_user_id      int           -- auth linkage
  fluentcrm_id    int           -- CRM linkage
  display_name    text
  slug            text
  role            text
  avatar_url      text
  headshot_url    text
  short_bio_md    text
  long_bio_md     text
  location        text
  socials         jsonb
  shop_url        text
  expertise_tags  text[]
  member_since    date
  status          text
  meta            jsonb
)

tag (id, slug, label, kind)  -- kind: topic | technique | material | era | instrument
content_tag (content_id, tag_id, weight)

user_bookmark (user_id, content_id, created_at)
user_view     (user_id, content_id, opened_at, progress_pct, finished_at)
user_follow   (user_id, person_id, created_at)

discovery_row (slug, for_user_id, content_ids jsonb, generated_at)
```

### Three shippable stages

1. **Index.** Postgres schema + `save_post` sync mu-plugin + backfill. No UI change. Low risk.
2. **New archive UI.** Variants C+D+E reading from index via small REST endpoint. Replaces current archive + deactivates Search & Filter Pro (3 plugins die). Bookmarks + history start here.
3. **Authoring optional.** Non-WP admin if/when. Frontend doesn't care.

Stage 1 ~1 week. Stage 2 ~2 weeks. Stage 3 only when ready.

---

## 7. lg-layout-v2 is already a quiet strangler vine

The conversation took a turn when we looked at lg-layout-v2:

- v2 stores post body as **canonical JSON** in `_lg_layout_v2` postmeta — an ordered array of typed blocks (`post-header`, `wysiwyg`, `image`, `gallery`, `callout`, `columns`, `divider`, `embed`, `paywall`, `post-footer`, `section-heading`, `transcript`).
- Every block declares schema in `manifest.json` — props, defaults, variants, validation. Self-documenting.
- Tier/paywall is in the JSON (`gated_tier`, dedicated `paywall` block) — not WP-specific.
- Migration pipeline: dumb **exporter** → "fat JSON bundle" → per-CPT **translator** → v2 layout JSON. The bundle format IS the portable migration artifact.

**Status correction from Ian:** block manifest is brand new. Only 5 posts converted to v2. v2 is the *plan*, the corpus is still in legacy formats.

**Implications:**
- Use lg-layout-v2 JSON as the canonical body in the index. Body search extracts text from wysiwyg / callout / transcript blocks.
- Index `kind`/`subkind` taxonomy mirrors v2 vocabulary, not WP CPTs. WP CPT → canonical kind is one mapping table.
- Migration progress = search quality. Each post moved to v2 gets richer index data (separately searchable callouts, image alts, transcripts). Forcing function for finishing the v2 migration.
- The v2 renderer is portable. PHP today (in WP); could be a small TS renderer for a future non-WP frontend, same JSON in.

---

## 8. CPT consolidation question

Q: Should we collapse CPTs to one post + taxonomy?

**Answer: collapse in the index, not in WordPress.**

- The kind-specific fields are *real* schema (loothprint STL ≠ video URL ≠ event zoom_url). Pushing them into postmeta with kind-prefixes reimplements WP's CPT segregation badly.
- WP's permission system, URL structure, admin UI, BuddyBoss/Elementor integration all assume CPTs. Re-doing them is churn.
- The index already collapses to ~6 canonical kinds with `kind_specific jsonb` for the distinctive bits. That's where "everything is one type with a kind taxonomy" pays off.

**One worthwhile WP cleanup:** the three article-shaped CPTs (`post-imgcap`, `post-regular`, `post`) are near-duplicates. Consolidating those (post-imgcap is the modern one) takes 21 CPTs → ~18 meaningfully distinct ones. Going further (8 → 1) is theology.

### CPT inventory snapshot (dev box, 2026-05-24)

| Canonical kind | WP source post_types | Published |
|---|---|---|
| article | post-imgcap, post-regular, post | ~98 |
| video | post-type-videos | 318 |
| loothprint | loothprint, loothcuts, document | ~150+ |
| event | event, ajde_events, international-loothi | ~140 |
| discussion | topic + replies | 1161 / 4488 |
| profile | member-spotlight, member-directory | 7 |
| benefit | member-benefit, sponsor-product | smaller |
| misc | useful_links, coe-questions, etc. | tiny |

### ACF field shape per CPT (key fields)

- **Loothprint:** featured_image, more_images, **3d_file**, video_instructions, **onshape_link**, loothprint_category, content_topic, buy_me_a_coffee, **creative_commons license**
- **Video:** featured_image, video URL, related_links repeater, video_category, published_date
- **Event:** tier, **start/end date+time**, region, language, **zoom_url**
- **Document:** **file_upload, pdf_url, download_url**
- **Member Benefit:** featured/hero images, introduction, full_details, **link, code/instructions**, benefit_type
- **Article (post-imgcap):** WYSIWYG preamble + image-caption repeater + category
- **Council of Elders:** anonymous flag, question, image gallery, email

These are real distinguishing fields, not metadata-flavored tags.

---

## 9. Profile 2.0

### User data lives in 4 places today

1. **`wp_users` + `wp_usermeta`** — auth + WP basics
2. **BB xprofile** (`wp_bp_xprofile_*`) — legacy/messy, duplicate "Name/Email/Website" fields across groups 10/11/13
3. **Member Directory ACF** — a thoughtful 7-section sketch (NOT yet committed design). Only ~5 records.
4. **FluentCRM** — 2022 active subscribers with first/last name, email, address, lat/lng, points, lifetime value, contact_type, source. Real, populated, in use.

### Member Directory schema (the sketch — current state)

Ian's note: **this is a sketch, not finished design.** Direction-of-thinking, not commitment. Open to redesign.

7 sections, per-section privacy controls:

- **Profile** — photo, cover, bio, featured video, socials
- **Workspace** — shop description, photos, equipment, accessibility
- **Craft/Discovery** — instruments worked on, knowledge domains, contexts, experience level, years
- **Business** — name/logo/hero, services, instruments serviced, warranty, location with precision toggle, hours, booking URL, languages
- **Connect** — mentorship / apprenticeship / employment status, willing-to-relocate, collaboration
- **Experience** — work history, education, achievements, portfolio gallery, resume
- **Vibe** — personality, working style, shop playlist, "things I say in the shop," workshop buddies
- **System** — display name, tagline, availability, global privacy default, per-section enable toggles

### Recommended approach (recalibrated for "it's a sketch")

Design the `person` schema in the index and Profile 2.0 in parallel — they're the same exercise from two sides.

**Open framing questions:**

1. **Who is a "person" in Profile 2.0** — every member (~5k) or just contributors (~100)? Drives population, drives mandatory-vs-opt-in.
2. **What's the smallest version that ships?** Current sketch has ~60 fields. v1 might be 8 (name, handle, photo, short bio, location, socials, expertise tags, "what I do"). Directory richness is v2 once v1 has uptake.
3. **What's the rendering surface?** Working backward from "contributor-card block needs X" / "/people/<slug> page needs Y" / "byline block needs Z" constrains the field list better than top-down design.
4. **What's the ONE new thing Profile 2.0 unlocks?**
   - Mentorship/apprenticeship matching → Connect section is load-bearing
   - "Creators you follow" → bio + follow primitive
   - Directory search ("who works on acoustic in Portland") → location + expertise fields
   - Different MVPs depending on the answer.

### How Profile 2.0 connects to the renderer

Once `person` is canonical in the index, new lg-layout-v2 blocks consume it:

- **author-byline** — props: `person_slug`, optional overrides. Used in post headers.
- **contributor-card** — for "guests in this piece" inline callouts.
- **profile-section** — used to build `/people/<slug>` pages from Member Directory sections, with privacy honored.
- **author-footer** — small bio under articles, follow CTA.

"Posts and pages built with user info" = blocks that take a `person_slug` prop. Authors stop being hardcoded into HTML.

---

## 10. Where we left off

Two clarifying questions outstanding for Profile 2.0:

1. Is Profile 2.0 for every member or just contributors?
2. What's the *one new thing* Profile 2.0 unlocks? (Mentorship matching? Follow primitive? Directory search? Something else?)

Next decision points (whenever resumed):

- **Profile 2.0 goals first**, then design `person` schema + Member Directory sections together from the rendering surfaces backward.
- **Or skip ahead and start Stage 1 (index DB)** with a minimal `person` shape; promote fields as Profile 2.0 firms up.
- **Archive UI implementation** can start independently of Profile 2.0 — it needs `person.display_name + avatar + slug` only, and that's resolvable from existing data immediately.

---

## Appendix: discarded ideas (why)

- **Tag clouds / tag archive pages as nav** — dead UX, <2% pageviews on content sites, mostly SE traffic.
- **Multiple tags per card** — three tags = none read.
- **Collapsing all CPTs in WP** — kind-specific fields are real schema, reimplements CPT segregation badly.
- **Putting author bios in FluentCRM** — wrong tool; CRM is for outreach metadata, not content.
- **Repurposing Burst Statistics for per-user history** — anonymous-uid by design, working against the plugin.
- **Carousel libraries (Swiper, Slick, Owl)** — native `scroll-snap-type: x mandatory` does the job in CSS, no JS.
- **Shortcode-on-existing-Elementor-page integration** — wastes most of the perf win; bypass template instead.
