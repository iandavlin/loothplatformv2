# Briefing — Video-conversion chat

**Paste into a fresh chat to run the legacy→v2 video conversions.** Stay in this lane: convert `post-type-videos` posts → v2 layouts → fast standalone render. Do NOT touch profile-app, the DB reset, or Buck's lanes — ping the main coordinator for anything cross-cutting.

## The job
341 published `post-type-videos`; only a few converted. Convert **one at a time** (Ian's call — not bulk yet). Full context + the standalone-renderer history is in `docs/handoff-coordinator-2026-06-03.md` — read it first.

## The per-post loop
1. **Parse (dry-run):** `sudo -u www-data env LG_PARSE_POST=<id> wp --path=/var/www/dev eval-file tools/video-parse.php`
   - Deterministic, no AI. Reads `post_content` (the pasted YouTube description) → v2 layout JSON to `/tmp/lg-parse-<id>.json`. Stamps `_meta.importer="video-parse/1"`, `schema:1`. Skips if already converted.
   - Tidbits come from WP `post_content` (descriptions were pasted in). **YouTube is bot-walled from this AWS IP** (yt-dlp + WebFetch both fail) — never rely on fetching YouTube.
2. **Review** the block summary it prints (header tagline, embed, wysiwyg, Chapters callout w/ deep-links, `Label:`-grouped link callouts, footer). Show Ian before writing.
3. **Import** (update-by-id, never create — avoids the dup-post bug): `sudo -u www-data wp --path=/var/www/dev lg-layout-v2 import --post-id=<id> --file=/tmp/lg-parse-<id>.json`
4. **Materialize the blob:** `curl -s -X POST https://dev.loothgroup.com/archive-api/v0/_materialize --resolve dev.loothgroup.com:443:127.0.0.1 -k -H 'Content-Type: application/json' -d '{"post_id":<id>,"action":"upsert"}'`
5. **Verify** the standalone render (fast, no band, video plays).

## Settled decisions — don't re-open
- **Keep `_lg_layout_v2`** — parser emits the same format; "standalone" is a render path, not a format. No v3. Provenance = the `_meta.importer` stamp.
- **Tier gating is automatic by post tier** — looth-lite post auto-gates its video; public doesn't. Parser needs no gating logic.
- **The parser already shook out 2 bugs** (reference corruption; embed-URL eating the description) and the standalone renderer got 5 systemic fixes this session (facade play JS, admin gate-bypass, chapter-link CSS, container-width override, the top band) — all in `archive-poc/standalone/render.php` + callout `shell.css`. See the handoff.

## Gotchas
- `archive-poc/standalone/engine/blocks/*` and the nginx confs are **root/archive-poc-owned** → edit with sudo. `render.php` CSS serves via a content-hashed bundle that auto-regenerates; opcache `validate_timestamps=On` (2s) so PHP edits go live without restart.
- Done so far: 71302 (3D Club), 70899 ("Where Does the Sound…"). ~175 video posts have rich content; ~135 are URL-only (embed-only layout).
- Materialize is NOT automatic on import — you must run the `_materialize` curl (or the post falls back to the slow WP render).
- Mail cap stays ON (dev is on live data). Nothing pushed — local on `main`.

Report back to the main coordinator per the canonical relay format.
