# Conversion lane — handoff 2026-06-04

Supersedes the loothprint/video state in `handoff-coordinator-2026-06-03.md`. This session
took the managed-CPT → v2-layout conversion from "videos only" to: **videos + loothprints +
loothcuts + documents done, and a working article parser**. All deterministic parsers; no AI.

## State (publish posts; `_lg_layout_v2` = converted)

| CPT | done / total | remaining | parser | notes |
|---|---|---|---|---|
| post-type-videos | 340 / 341 | **1** | `tools/video-parse.php` | only **20210** left (oembed-only, no plain URL) |
| loothprint | 165 / 166 | **1** | `tools/loothprint-parse.php` | only **3666** "Jack Brace part Deux" (no `loothprint_3d_file`) |
| loothcuts | 7 / 7 | 0 | loothprint-parse (CPT-aware) | done |
| document | 4 / 6 | **2** | loothprint-parse (`document` branch) | **46552, 46009** flagged (no PDF/file — inline-content docs) |
| post-imgcap | **61 / 63** | **2** | `article-parse.php` (inline) + `article-acf-parse.php` (ACF) | **DONE.** 7 inline-HTML + 35 ACF-repeater + 5 ACF single-row essays + 7 post_content essays. Remaining 2 are deferred, not failures: **43773** = a PDF in an `<iframe>` (belongs in the *document* flow, not article) and **14204** = content imgs hotlinked from ukuleles.com (all 404). |
| useful_links | **39 / 39** | 0 | `tools/addon-parse.php` | **DONE.** Each = post-header + `callout`(links variant: useful_url + description) + optional gallery. PUBLIC, ungated. Route `/useful_links/<slug>`. |
| sponsor-post | **16 / 16** | 0 | `article-parse.php` (inline) | **DONE.** Inline-HTML, reused the article parser. Mostly public → **ungated** (promotional). Route `/sponsor/<slug>`. ⚠️ **image figure-numbers stripped** (ads aren't numbered tutorials) — see [[feedback_sponsor_post_no_image_numbers]]; stripped via `/tmp/strip-numbers.php`, future runs should guard `article-parse` on `post_type==='sponsor-post'`. |
| member-benefit | **6 / 6** | 0 | `tools/addon-parse.php` | **DONE.** post-header(hero) + intro + **PAYWALL** + `callout`(Member Offer: link_title/code/url) + gallery. Offer/code gated behind the paywall (looth-lite). Route `/member-benefit/<slug>`. |
| shorty | **29 / 29** | 0 | `tools/video-parse.php` | **DONE.** Video-shaped (youtu.be / `/shorts/` / `/embed/`). Needed: (1) video-parse `/shorts/` URL support, (2) **`shorty` added to `Plugin::MANAGED_CPTS`** (else import refuses meta + materialize returns not-managed), (3) **`shorty` added to the nginx route alternation** in `snippets/strangler-archive-poc.conf`. Mostly public. |

## Per-post loop (unchanged)
`parse (dry-run → /tmp json) → review → wp lg-layout-v2 import --post-id=<id> → _materialize curl → verify`.
Materialize: `curl -s -X POST https://dev.loothgroup.com/archive-api/v0/_materialize --resolve dev.loothgroup.com:443:127.0.0.1 -k -H 'Content-Type: application/json' -d '{"post_id":<id>,"action":"upsert"}'`.
Gate cookie for curl verify: `loothdev_auth=<$loothdev_token>` (non-`/billing` paths need it). Routes: `/video/`, `/article/` (post-imgcap), `/loothprint/`, `/loothcuts/`, `/document/<id>/` (id-based), `/sponsor/`.

## Parsers (all committed)
- **video-parse** (committed earlier, 94de8ac): post_content → header/embed/wysiwyg/chapters/links/footer. Chapters auto-gate via tier (embed in AUTO_GATE_TYPES).
- **loothprint-parse** (e67bf96, ba882e1, 50ba49f, +document branch 5011d43): ACF→blocks. `download` block = file only, **auto-gates from the post tier** (members get file; anon gets `lg-gate-cta--download`, file URL absent). CPT-aware: loothcuts (`loothcut_` prefix, cnc_file, prose in ACF), document (PDF via file_upload/pdf_url). Instructional video embed kept **public** (`gated_tier:"public"`). Recipe: `~/.claude/skills/write-article-v2/recipes/loothprint.md`.
- **article-parse** (5011d43): inline-HTML body → panel prose / section-headings / aspect-placed images. **Image-caption model (Ian's, conclusive):** a *short* (≤400c) prose run immediately preceding an image becomes that image's `image_text` → renders as the **figcaption** (description under the image); the img `alt` (short label) is the **lightbox** caption (engine change: image render `data-lg-caption` ← alt). Longer prose stays a panel. Sequential image numbers (1,2,3… top-to-bottom, incl. pairs). Datelines/bylines stripped from tagline. Links host-shortened. Non-image attachments (stray .mp4) dropped.

## Key fixes this session (committed)
- materializer: collect gallery `image_ids[]` into the blob media map; **defensive srcset filter** (drop size variants whose files don't exist) — fixes the dev-clone broken-`<img>` issue at bake time, so **no per-image `wp media regenerate` needed**.
- parsers: CRLF paragraph normalization; make_clickable + host-shortened link text (no panel overflow).

## ⚠️ Cross-lane relays pending (handed to Ian; not yet actioned)
1. **lg-layout-v2 engine:** (a) mirror the srcset existence-filter canonically in `WpMedia::resolve` (my fix is the dev archive-poc materializer + image render copies only — LIVE/WP-render need the canonical); (b) `overflow-wrap:break-word` on `.lg-wysiwyg`; (c) image render `data-lg-caption ← alt` (committed in the dev standalone copy, needs canonical mirror).
2. **archive (feed/index) lane:** a `document` (47597 "Marketing Club 3-14-25", a PDF deck) is mis-bucketed under "Loothprints" in the member feed — feed type-grouping fix. (Its real video is the separate post-type-videos 48704.)

## Open items / stragglers
- **20210** (video, oembed-only) + **3666** (loothprint, no file) + **46552/46009** (documents, no PDF) — hand-finish.
- **base64-embedded article images:** some articles (e.g. **67638** Erlewine Archive) embed images as base64 data-URIs in post_content → `image_id` null, bloated blob, no srcset. Decide: extract to real attachments vs leave. The caption model still works on them.
- **post-imgcap member-tier gating — RESOLVED (Ian 6/4): teaser-then-paywall.** Articles have no money-shot block, so insert a `paywall` section block at the natural teaser→payoff boundary, `tier` = the post's tier (looth-lite). Members at that tier+ get the full layout; anon gets teaser + CTA, payoff trimmed server-side (crawler-safe). Proven on **49197** (gate before "The Results") and **2707** (gate before "Routing the Headstock"). For the batch, the parser needs to emit the paywall automatically (or splice it) — boundary pick is editorial: gate where the how-to/payoff begins. Splice helper used this session: `/tmp/splice-paywall.php <file> <heading-text> <label>`. NB: base64-image articles (67638) still render fully — no auto-gate, and they may want hand-gating too.
- **shorty (shorts, 29)** + banger/freebie-video/etc. — NOT in the managed-CPT route. `shorty` is video-shaped → run `video-parse` + add `shorty` to the nginx CPT alternation in `strangler-archive-poc.conf`. Sysadmin (me/ubuntu), not a relay.

## Next steps (recommended order)
**post-imgcap conversion is COMPLETE (61/63).**

### How the post-imgcap batch was done (for re-runs / the next CPTs)
- **ACF model** → `tools/article-acf-parse.php` (NEW, self-contained). `LG_PARSE_POST=<id>` (single/verbose) or `LG_PARSE_ALL=1` (batch → `/tmp/lg-acf-<id>.json`). Row sub-fields: `img_cap_repeater_image` (single), `gallery2` (serialized gallery — 17 rows), `img_cap_repeater_text` (HTML → figcaption if lone-image + short + link-free, else panel), `repeater_oembed` (youtube/instagram → `embed` block — 21 rows). Auto-emits the `paywall` after row-0 blocks for non-public tiers; markdown-bold + Ian's aspect/pairing built in.
- **Gating** = teaser-then-paywall via `/tmp/splice-paywall.php <file> <mode> <label>` — modes `heading:<substr>` | `index:<n>` | `split:<nParas>` (splits a single essay panel). Single-row ACF essays + post_content-only essays were split-gated at 2 paras.
- **post_content-only essays** (rows=0) → inline `article-parse.php`; the 2 with a stray empty repeater row that the inline guard refuses (27753, 23896) were built from post_content via the one-off `/tmp/essay-build.php`.
- ⚠️ **`article-parse.php` is back at its committed (HEAD) state** — last session's inline-parser improvements (resize-variant `ap_resolve_aid`, junk/avatar filter, title-echo + bare-URL + trailing-orphan heading drops, Elementor `<style>` strip + overlong-heading demote) were reverted in the working tree. The 7 inline articles were already materialized with those fixes (baked into `_lg_layout_v2` + blobs), so nothing live is broken — but re-running the inline parser on a NEW inline post won't have them. `article-acf-parse.php` carries its own copies of the still-relevant filters. Re-apply to `article-parse.php` if/when more inline posts appear.

### Next CPTs
2. **useful_links (39)** — new simple links-callout parser.
3. sponsor-post (18), member-benefit (6) — small recipes.
4. shorty onboarding (route + video-parse).
5. The ACF `img_cap` repeater article model (second pass) for posts with empty post_content.

## Ops / gotchas
- **Dev box reboots unexpectedly** (twice now; last 6/4 10:38). After a reboot: re-arm mail cap (`iptables OUTPUT DROP 25/465/587` — done), `/tmp` clears (resume batches by re-parsing unconverted; DB persists), rclone R2 mount auto-remounts. See `feedback_dev_reboot_recovery` memory.
- Uploads served from R2 via rclone FUSE (`/var/www/dev/wp-content/uploads -> /mnt/loothgroup-uploads-dev`), NOT local disk.
- Nothing pushed — all local on `main`. Latest: 5011d43.
