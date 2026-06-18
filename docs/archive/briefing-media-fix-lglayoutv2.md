# Briefing — media fix (WpMedia srcset + wysiwyg wrap) → lg-layout-v2 lane

**Paste into the lg-layout-v2 chat.** Sanity-check the box first: `curl -s ifconfig.me` → `50.19.198.38`,
`whoami` → `ubuntu` = you ARE dev, act locally with sudo, don't SSH.

## What's done
Two engine fixes are written + uncommitted in `lg-layout-v2/`:
- `src/WpMedia.php` (+ vendored engine copy) — `resolve()` drops srcset size variants whose files are
  missing on disk (file_exists vs the on-disk base), with a keep-on-unknown-base guard. Shared resolver →
  fixes WP render + live, not just the baked blob.
- `blocks/wysiwyg/shell.css` (+ engine copy) — `overflow-wrap: break-word` so long unbreakable tokens
  (raw URLs) wrap instead of escaping the column. Verified via bundle + 10 fixtures.

## Open: VERIFY WpMedia before committing
The wysiwyg fix is verified. The WpMedia fix is **NOT** — it only lints; the CLI harness uses the
`_media.json` stub so the file_exists drop-branch has never run. A 600-attachment scan on dev found no
metadata/disk mismatch, so don't rely on finding one in the wild — **construct** the case:
- Take an attachment, craft a copy of its metadata with one size pointing at a bogus filename, call
  `\LG\LayoutV2\WpMedia::resolve($id)`, assert that size is dropped and the real ones kept.
- Also assert the unknown-base guard: when `get_attached_file()` is empty, all sizes are kept (no strip).
- Run under real WP (`sudo -u looth-dev wp --path=/var/www/dev eval-file ...`). Dev's running plugin is
  byte-identical to the repo source, so this validates exactly what you'd ship.

## Commit — clean increments only
The v2 working tree has a lot of unrelated uncommitted work (`GateCta.php`, new `blocks/download/`, six
`storage/layouts/*.json`, `LAYOUT-JSON.md`, ~10 `bundle.css`). Commit ONLY the media-fix files:
`src/WpMedia.php` + engine copy, `blocks/wysiwyg/shell.css` + engine copy, and the wysiwyg-regenerated
bundle snapshots. Leave GateCta / download / storage / unrelated docs alone. Likely two commits:
"media: WpMedia srcset existence filter" + "wysiwyg: overflow-wrap break-word".
Commit in clean increments after the change is tested — don't stack. Commit ≠ push; show me the diffstat
before any push.

## Deploy
After commit, build the versioned deploy zip per the lg-layout-v2 deploy flow; the live-side curl/unzip +
chown looth-live + bundle regen + epoch bump is Ian/coordinator-driven. Confirm on live that srcset
variants render clean (the real-world proof the verify step simulates).

## Note
Once this is deployed + confirmed live, the archive-poc materializer's redundant `$m['sizes']` file_exists
filter (materializer.php:157–161 only — NOT the indexer/backfill thumb pickers) can be dropped as a
follow-up cleanup. Leave it until then.
