# Handoff — lean comments READ endpoint (SHORTINIT) · for coordinator + Ian sign-off

**Status:** PROTOTYPE, measured, **NOT shipped.** Architectural → needs coordinator review + Ian
sign-off before any nginx wiring. Lane: lg-comments / perf. Author: ubuntu (dev box), 2026-06-03.

## Why
The `?lg_comments=1` modal was slow on two counts. The **asset-weight** half is fixed (dequeue in
`lg-comments-frame.php`: 171→9 req, 6.2 MB→131 KB). The **PHP-boot** half remained: every comment-thread
open boots all of WordPress + BuddyBoss + Elementor (~2.3 s of plugin/theme load) just to list a few
comments. Measured boot vs SHORTINIT (DB layer only) on this box: **~2300 ms vs ~50 ms** for the same
query — the comment query itself is trivial; the cost is 100% plugin/theme bootstrap.

## What this prototype is
`archive-poc/standalone/comments.php` — a standalone lean entry point that renders a post's comment
thread **without** the plugin/theme stack, mirroring the `.lg-cframe` markup so it's a drop-in iframe
target. **READ PATH ONLY** — posting still goes through full WP (`/wp-comments-post.php`), unchanged.

It is the **shared lean-comments service the /stream/ launch needs**: the CPT comment-modal AND the
`/stream/` card comment-modal both point their iframe here. **No second fork** — markup contract is
coordinated with the stream lane.

### Bootstrap constraint (important)
Comments live in WP's **MySQL** `wp_comments`. The **archive-poc FPM pool is Postgres-only and cannot
read `wp-config.php`** (verified). So this endpoint **must run on the looth-dev (www-data) pool** — same
pool that serves WP, but a *lean entry point* that `define('SHORTINIT', true)` before `wp-load.php`. The
file is world-readable / www-data-readable (verified).

## Measured result (FPM-served, opcache warm, via `cgi-fcgi` on the looth-dev socket)

| Path | TTFB (served) | Notes |
|---|--:|---|
| Current `?lg_comments=1` (full WP), logged-in | **~1400 ms** | what users feel today |
| Current `?lg_comments=1` (full WP), anon | ~900 ms | |
| **Lean endpoint (this prototype)** | **~4 ms PHP / ~20 ms round-trip** | SHORTINIT boot + query + render |
| (reference) archive-poc standalone renderer | ~100 ms | the "fast page" class on this box |

**~30–50× faster.** Threading verified (post 342: 9 comments, nested `<ol class="children">`, 3.9 ms).
Visual proof: `https://dev.loothgroup.com/mockups/lean-comments-shot.png`
(the stray `Content-Type:` line at the top is a static-serve artifact of the screenshot method — raw
FCGI output served as a file; real nginx turns those into HTTP headers. Not an endpoint bug.)

## ⇨ nginx location spec (COORDINATOR owns this — do not let me add it)

Add inside the SSL server block of `dev.loothgroup.com.conf`, alongside the other archive-poc
locations (above `location /`). Clean, id-keyed URL so any surface (CPT, stream, page) can target it:

```nginx
    # ----- lean comments READ endpoint (SHORTINIT; WP pool for MySQL access) -----
    # /lg-comments/<post_id>/ -> thread-only render, no plugin/theme boot (~4 ms).
    # Reads only APPROVED comments. Posting is unchanged (form POSTs to full WP).
    location ~ ^/lg-comments/([0-9]+)/?$ {
        if ($loothdev_is_authorized != 1) { return 403; }
        include fastcgi.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm-looth-dev.sock;   # WP pool: SHORTINIT needs wp-config+MySQL
        fastcgi_param SCRIPT_FILENAME /home/ubuntu/projects/archive-poc/standalone/comments.php;
        fastcgi_param LG_POST_ID $1;
        fastcgi_param QUERY_STRING $args;
    }
```

(The endpoint also accepts `LG_POST_TYPE`+`LG_SLUG` if a slug-keyed URL is preferred — but the stream
lane already has the post id, and id-keyed avoids a slug→id lookup, so id is the recommended contract.)

## Consumer contract (coordinate with stream lane — ONE service, no fork)
Both modals build the iframe `src` as **`/lg-comments/<post_id>/`** instead of
`<permalink>?lg_comments=1`:
- **CPT modal:** `archive-poc/standalone/render.php` emits the comments-modal iframe — point it here.
- **`/stream/` modal:** `archive-poc/web/stream.php` (+ its card JS) — point it here.
- The existing `?lg_comments=1` → WP interception can stay as the **posting/fallback** path (and for
  `?lg_edit=1`). The lean endpoint replaces only the **read/open** render.

## Open questions / follow-ups (NOT done in the prototype)
1. **Tier gating.** The endpoint shows approved comments for any cookie-gated viewer. If comment threads
   on tier-gated posts must inherit the post's gate, add a tier check (the standalone renderer already
   resolves viewer tier — could share that). **Decision needed.**
2. **Avatars.** Uses Gravatar; BuddyBoss stores local avatars under `wp-content/uploads/avatars/…`.
   Parity needs either a denormalized avatar URL in the (future) stream comment store or a lean
   BB-avatar path resolver.
3. **comment_text fidelity.** Prototype does `esc + nl2br`; full WP applies `wpautop` /
   `make_clickable` / `convert_smilies`. Readable, not identical — fine for a read view, revisit if
   links/formatting matter.
4. **Posting stays full-WP** (per scope). When the stream lane builds native inline posting on the
   archive-poc stack, the write path joins this service and full-WP drops out entirely.

## Files
- `archive-poc/standalone/comments.php` — the prototype (committed to repo, **not** nginx-wired).
- `archive-poc/deploy/lg-comments-frame.php` — the already-done dequeue (separate, uncommitted-for-review).
