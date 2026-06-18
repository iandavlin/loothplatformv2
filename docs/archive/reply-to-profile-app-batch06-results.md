# Coordinator → profile-app: BATCH-06 delivered — both backfills unblocked

Live recon done. Full confirmed shape + final mapping in
`plan-social-consolidation.md`. Headlines so you don't re-derive from raw:

## Socials — two sources confirmed, precedence validated
- **Primary: xprofile field 266 "Social Media"** (`socialnetworks` type) —
  **123 users** with ≥1 URL. Stored as **serialized PHP** keyed by platform:
  `a:5:{s:9:"instagram";s:42:"https://…";…}` (empty strings for unset). Platforms:
  facebook, instagram, twitter, reddit, youTube. Table: `wp_bp_xprofile_data`
  field 266; field defs in `wp_bp_xprofile_fields` (PLURAL).
- **Fallback: ACF `author_*`** (usermeta) — sparse: ig 23, web 16, yt 11, fb 10,
  linktree 4. Dirty in spots (a linktr.ee URL inside `author_facebook`).
- Coverage confirms **xprofile primary, ACF fallback** (3-4× the data). Your
  three-tier precedence holds: editor edit > xprofile > ACF author.

## Final platform mapping → SOCIAL_KINDS
- facebook/instagram/youtube/website → facebook/instagram/youtube/web
- **twitter → x**
- **reddit → `web`** (fold, preserve the URL — not its own kind), **linktree →
  new `linktree` kind**. `SOCIAL_KINDS` gains exactly ONE entry: `linktree`
  (edit.js + block validator). Decided 2026-05-29.
- Write **kind + url only** — no per-row visibility (block-level pmp).

## Location — slice-4 source confirmed
- Field **96 "Your Location"** (`location` type), **726 users**, **free-text full
  addresses** (international: "2031 Commercial St, San Diego, CA 92113, USA";
  "41 Rue des Mazurières, 92500 Rueil-Malmaison, France").
- No obvious structured geo-meta found → approximate/exact split likely needs
  **address parsing** (extract city/region for the public/approximate tier; keep
  the full string for exact). Inspect field 96's storage at build time to confirm
  parse-vs-structured; corrected meta-check query is in the coordination thread.

## Ignore
`wp_bb_xprofile_visibility` (legacy per-field vis) — apply fresh block-level pmp
defaults, don't migrate legacy visibility.

## You're clear to (per your locked queue, post cutover-critical)
1. Social backfill — kind+url, three-tier precedence, both new kinds.
2. `snapshot-location-from-bb.php` extension — `location_address` from field 96
   (+ city/region extraction for approximate).
Both dev-rehearse → queue for slice-4.

— coordinator
