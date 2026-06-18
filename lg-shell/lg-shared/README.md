# lg-shared — git mirror (NOT the live files)

These four files are a **mirror for review + history** of the live, deployed lane
code at **`/srv/lg-shared/`** (www-data-owned, served at `/lg-shared/`). The live
files are the source of truth; this directory exists only because `/srv/lg-shared/*`
is not otherwise under version control — which is how a wrong-API-contract
`social-modals.js` once shipped unreviewed.

| file | live path |
|---|---|
| `site-header.php`   | `/srv/lg-shared/site-header.php`   |
| `site-header.css`   | `/srv/lg-shared/site-header.css`   |
| `social-modals.js`  | `/srv/lg-shared/social-modals.js`  |
| `jwt-verify.php`    | `/srv/lg-shared/jwt-verify.php`    |

## Rules
- **Edit live, then re-mirror.** Change `/srv/lg-shared/<f>` (sudo-chown to edit,
  chown back to `www-data:www-data`), then copy it here and commit. Do **not** edit
  the mirror and expect it to deploy — nothing syncs this dir to `/srv`.
- Keep them identical. To check drift:
  ```sh
  for f in site-header.php site-header.css social-modals.js jwt-verify.php; do
    diff -q /srv/lg-shared/$f "$(dirname "$0")/$f"
  done
  ```
- `lg_shared_render_site_header($ctx)` in `site-header.php` is a **contract** consumed
  by archive-poc + bb-mirror — don't change its signature without relaying to both lanes.
