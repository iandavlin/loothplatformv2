# Live nginx changes (dev) — 2026-05-31

**Status (updated 2026-05-31 PM):** §1–§3 are now reconciled into the dev mirror
`archive-poc/nginx-snippet.conf` — that repo copy is byte-identical to the deployed
`/etc/nginx/snippets/strangler-archive-poc.conf`. The other repo copy,
`archive-poc/deploy/archive-poc.nginx-snippet.conf`, is a **separate production-target
paste template** (uses `/srv/archive-poc/` paths and the older /archive-poc/+API-only
structure — it predates the standalone-CPT intercept locations). It was deliberately
NOT rewritten with dev `/home/ubuntu/projects/` paths; regenerating it for production
is its own task at cutover, not part of this drift reconciliation.

The three changes below are recorded for that future production regeneration.

## 1. Loopback endpoints 502 fix (`_sync` + `_materialize`)
Both `location = /archive-api/v0/_sync.php` and `…/_materialize.php`:
- `include snippets/fastcgi-php.conf;` → `include fastcgi.conf;` (drop the alias-incompatible `try_files`)
- `fastcgi_param SCRIPT_FILENAME $request_filename;` → absolute path
  (`/home/ubuntu/projects/archive-poc/api/v0/_{sync,materialize}.php`)

Cause: `try_files` / `$request_filename` mis-resolve under the parent `alias` → fast 502.
This endpoint is what the save-hook dispatches to, so it gates save-triggered re-bake.

## 2. FE-edit handoff (`?lg_edit=1` → WordPress)
Added to each intercepted CPT permalink location (post-imgcap, loothprint, loothcuts,
useful_links, member-benefit, document), right after the 403 gate line:

    if ($arg_lg_edit)     { rewrite ^ /index.php last; }   # FE-edit -> WP

Routes edit requests to WP's front controller (WP resolves the same permalink from
`REQUEST_URI` → plugin FE editor + capability check); read-only requests render
standalone. Verified: `?lg_edit=1` → WP (`/wp-json/` markers, no standalone wrapper),
no flag → standalone.

## 3. Comments-view handoff (`?lg_comments=1` → WordPress)  ← added 2026-05-31 PM
Sits directly under the FE-edit branch in the same six CPT locations:

    if ($arg_lg_comments) { rewrite ^ /index.php last; }   # comments view -> WP

Routes the standalone comments-modal iframe to WP, where the `lg-comments-frame`
mu-plugin (`archive-poc/deploy/lg-comments-frame.php`) renders a chrome-free
comments-only view. Read-only/no-flag requests still render standalone.
Verified across all six CPTs: no flag → standalone, `?lg_comments=1` → comments-frame
(`body.lg-cframe`, comment thread + form, no standalone wrapper leak).
