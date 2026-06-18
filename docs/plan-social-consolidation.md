# Plan — social consolidation into profile-app (profile 2.0)

**Goal:** populate profile-app's `profile_socials` from the legacy data, merging
**two** sources: BuddyBoss xprofile socials (primary) with **ACF author socials
as fallback**. Owner: profile-app (in-lane, extends `migrate-from-xprofile.php`).

## The two sources — CONFIRMED ON LIVE (BATCH-06, 2026-05-29)

| Source | Where | Role | Live shape |
|---|---|---|---|
| **xprofile field 266 "Social Media"** (`socialnetworks`) | `wp_bp_xprofile_data` field 266 | **primary** (123 users w/ ≥1 URL) | serialized PHP keyed by platform: facebook, instagram, twitter, reddit, youTube (empty strings for unset) |
| **ACF `author_*`** (group 65139) | `wp_usermeta` | **fallback** (sparse: ig 23, web 16, yt 11, fb 10, linktree 4) | full URLs, some dirty (linktr.ee in a facebook field) |

Precedence validated by coverage: xprofile carries ~3-4× the social data → primary confirmed.

**Tables:** `wp_bp_xprofile_fields` (plural), `wp_bp_xprofile_data`, and a legacy
`wp_bb_xprofile_visibility` (per-field vis) — **IGNORE the latter**; we apply
fresh block-level pmp defaults, not legacy per-field visibility.

### Platform mapping → SOCIAL_KINDS — FINAL (2026-05-29)
| source platform | → SOCIAL_KINDS |
|---|---|
| facebook / instagram / youtube(youTube) / website | facebook / instagram / youtube / web |
| **twitter** | **x** (rename) |
| **reddit** (xprofile) | **`web`** — folded (preserve the URL, not its own kind) |
| **linktree** (ACF) | **`linktree`** — NEW kind |

**DECIDED 2026-05-29 (Ian, confirmed twice): `SOCIAL_KINDS` gains exactly ONE
entry — `linktree`.** Reddit folds into `web` (preserve the real URL, don't drop;
editor can recategorize). profile-app adds only `linktree` to the enum (edit.js
`SOCIAL_KINDS` + the block validator). Mapping is now final — both backfills can
be written.

### Location (slice-4, not socials but recon'd same pass)
Field **96 "Your Location"** (type `location`), **726 users**, **free-text full
addresses** (international, varied). Approximate/exact split likely needs
**address parsing** (no obvious structured geo-meta) — profile-app settles
parse-vs-structured at build time. Feeds `users.location_address` (exact) +
city/region extraction (approximate).

## Target: profile-app `profile_socials`

Existing taxonomy (`SOCIAL_KINDS`):
`instagram · youtube · bandcamp · web · email · phone · x · tiktok · facebook · patreon`

## ACF author-social fields (confirmed, group 65139)

| ACF user-meta key | → `SOCIAL_KINDS` |
|---|---|
| `author_instagram` | `instagram` |
| `author_youtube` | `youtube` |
| `author_facebook` | `facebook` |
| `author_website` | `web` |
| `author_linktree` | **no equivalent — DECISION (below)** |

Values are stored as full URLs. Data is dirty in places (saw a linktr.ee URL
inside `author_facebook`) — copy literally; the editor is the self-correct path.

## ⚠️ Per-row visibility SUPERSEDED by block-level pmp (2026-05-29)

The block-system decision (`plan-profile-block-system.md`) sets **block-level
pmp**: socials live in the `identity` block under one block visibility, NOT
per-social-row vis. **So the social backfill writes `kind` + `url` only — do
NOT populate or maintain a per-row visibility column** (it would be ignored at
render). If a per-row vis column exists on `profile_socials`, leave it null /
plan to drop it. This reconciles the two plans (profile-app flagged it).

## Consolidation precedence (per user, per platform)

```
1. profile_socials already has this kind  → KEEP (never clobber an editor edit)
2. else xprofile has it (primary)         → use xprofile value
3. else ACF author_* has it (fallback)    → use ACF value
4. else                                    → nothing
```

Non-clobbering throughout — same discipline as the field-1/field-2 crib.

## Population note (why the fallback matters)

- xprofile covers the **broad BuddyBoss membership** (~1,795 users).
- ACF author socials cover the **~132 content authors/contributors**.
- These are overlapping but not identical populations. The fallback fills two
  gaps: authors who have ACF socials but never set xprofile ones, and the
  reverse is handled by xprofile being primary. BATCH-06 will confirm the
  live counts + overlap.

## Explicitly OUT of scope

- **`brand_*` socials** (`brand_instagram`, `brand_youtube`, `brand_facebook`,
  `brand_website`) — **business/brand-scoped, NOT personal. Do NOT fold into the
  user backfill.** They feed the **practice** entity (the `/p/` storefront), via
  a **separate practice-socials backfill** paired with the practice storefront/
  block work (post-cutover). A person's socials ≠ their shop's socials.
  **`brand_*` live presence not yet recon'd** (BATCH-06 pulled `author_*` only) —
  recon when the practice-socials backfill is built.
  **Personal backfill source list is field-266 + `author_*` ONLY** — must not
  sweep `brand_*`.
- **"Connect" member-directory group (67510)** — mentorship/employment/privacy,
  not social. Ignore.

## Open decisions (your call)

1. **`author_linktree`** — no `SOCIAL_KINDS` equivalent. Options:
   (a) add a `linktree` kind to profile-app (Linktree is common in the scene —
   arguably worth its own), or (b) land it in `web` as a generic link.
   **Lean (a)** — it's a distinct, recognizable platform; folding into `web`
   loses that. profile-app's call since it owns `SOCIAL_KINDS`.
2. **xprofile-vs-ACF platform overlap** — once BATCH-06 shows the xprofile
   platform set, reconcile any platform present in BOTH (e.g. instagram in both
   xprofile and ACF) — precedence rule above already covers it (xprofile wins),
   just confirm no surprise platforms.

## Recon gaps → BATCH-06 (live, read-only)

BATCH-06 extended to recon BOTH sources on live:
- xprofile social field id/type/format (#56–59)
- ACF author-social presence + counts on live (#60) — confirm `author_*` user
  meta exists on live and how many users have it

## Sequence

1. Run extended **BATCH-06** on live → paste back.
2. profile-app builds the consolidation into `migrate-from-xprofile.php` (or a
   `migrate-socials.php` sibling): xprofile parse + ACF author fallback +
   precedence + mapping → `profile_socials`.
3. Dev-rehearse against fixtures built from the BATCH-06 samples (dev xprofile
   is stripped; dev ACF author socials are live-present so those can test directly).
4. `--commit` at cutover, dev-proven first.

— coordinator
