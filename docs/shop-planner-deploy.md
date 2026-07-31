# Deploying /shop-layout-planner/ standalone — order, traps, rollback

Built 2026-07-31, layout **B** (Ian's pick). Companion to
`docs/shop-planner-step1-findings.md` and `docs/shop-planner-standalone-plan.md`.

**This URL is live and takes organic traffic. Prove it 200s before and after.**

---

## What ships

| File | New? | How it reaches the box |
|---|---|---|
| `webroot/shop-layout-planner.php` | new | `webroot/install-symlinks.sh --new-only` |
| `webroot/shop-layout-planner.css` | new | same |
| `platform/nginx/strangler-shop-planner.conf` | new | **symlink into `/etc/nginx/snippets/` — a pull does not create it** |
| `platform/nginx/dev2.loothgroup.com.conf` | edited | pull (live) / **manual on dev2, see trap 1** |
| `lg-apps/apps/shop-planner/partials/planner-markup.php` | new | pull → repo (the page reads it from there; see trap 2) |
| `lg-apps/apps/shop-planner/app.php` | edited | pull → repo |
| `tools/gates/shop-planner-url-gate.sh` | edited | pull |

## Deploy order

```bash
# 0. BEFORE — record that the URL is currently healthy. Do not skip; this is the
#    only "before" you get, and it is the half a new-page check cannot see.
bash tools/gates/shop-planner-url-gate.sh --live

# 1. merge to main, push

# 2. pull the serving checkout
git -C ~/loothplatformv2-clean pull --ff-only origin main

# 3. link the two NEW webroot files (the pull does NOT link them)
sudo ~/loothplatformv2-clean/webroot/install-symlinks.sh --new-only /var/www/dev

# 4. link the NEW nginx snippet (the pull does NOT link it either)
sudo ln -sfn ~/loothplatformv2-clean/platform/nginx/strangler-shop-planner.conf \
             /etc/nginx/snippets/strangler-shop-planner.conf

# 5. nginx: a MISSING location passes `nginx -t` just as happily as a present one,
#    so -t proves syntax, not routing. Step 7 proves routing.
sudo nginx -t
sudo systemctl reload nginx

# 6. confirm the reload actually took — WORKER start time, never the master's
ps -eo lstart,cmd | grep "[n]ginx: worker"

# 7. AFTER
LG_SP_EXPECT_STANDALONE=1 bash tools/gates/shop-planner-url-gate.sh          # dev2
LG_SP_EXPECT_STANDALONE=1 bash tools/gates/shop-planner-url-gate.sh --live   # live
```

**Every live step is Ian's to run.**

## Trap 1 — on dev2 the enabled vhost is a COPY, so step 2 does not deploy it

- **live**: `sites-enabled/dev2.loothgroup.com.conf` → `sites-available/…` → the repo.
  Clean chain, so the pull deploys the include line. Nothing extra to do.
- **dev2**: `sites-enabled/dev2.loothgroup.com.conf` is a **real file owned by
  `ubuntu`**, which `tools/preview/lane-preview.sh` edits in place to append its
  lane-preview block. It is otherwise identical to the repo conf. A pull therefore
  changes `sites-available` and **nothing that nginx reads.**

On dev2 the include must be added to the enabled file directly:

```bash
sudo sed -i 's|^\(    include /etc/nginx/snippets/strangler-membership.conf;\)$|\1\n    include /etc/nginx/snippets/strangler-shop-planner.conf;|' \
  /etc/nginx/sites-enabled/dev2.loothgroup.com.conf
```

## Trap 2 — the plugin partial and the symlink-farm drift guard

`wp-content/plugins/lg-apps` is a **real directory** on dev2 and live, even though
`cutover/symlink-farm.sh` lists `lg-apps` among the plugins it should symlink. Its
drift guard (`symlink-farm.sh:43-56`) converts a real dir only when
`diff -rq repo docroot` is clean — and this commit is what makes them differ.

**This is already handled and blocks nothing.** The controller resolves the partial
from the docroot plugin dir *else from the repo*, and the repo copy is always at the
same commit as the controller. So:

- the standalone page works immediately, converted or not;
- the WordPress render keeps using the docroot's older `app.php` with the markup
  still inline, so **the rollback path is unaffected**;
- both paths render the same bytes either way.

Consequence to expect, so nobody reports it as a fault: after this merge
`symlink-farm.sh lg-apps` will report **DRIFT** and refuse, permanently, because
the repo genuinely differs from the docroot copy. Resolving it is a deliberate,
separate step — move the docroot dir aside and link it — and is **not** required by
this deploy:

```bash
# optional, later, and NOT part of this deploy
sudo mv /var/www/dev/wp-content/plugins/lg-apps{,.pre-symlink-$(date +%Y%m%d)}
sudo ln -s ~/loothplatformv2-clean/lg-apps /var/www/dev/wp-content/plugins/lg-apps
```

## Trap 3 — the location must keep its trailing slash

`location ^~ /shop-layout-planner/`

- `^~` because a **regex** location outranks a plain prefix one; without it the
  vhost's `location ~* \.(…|css|js|…)$` asset regex wins for anything underneath.
- the **trailing slash** because `^~ /shop-layout-planner` (without it) would also
  swallow `/shop-layout-planner.css` — the page's own stylesheet — and hand it to
  PHP. With the slash the stylesheet stays outside and is served and cached by the
  asset regex.
- `location = /shop-layout-planner` keeps the no-slash **301** WordPress does today.
  The slashed form is what Google indexed; dropping this turns every existing
  inbound link into a 404.

## Rollback

One line, and it needs no code revert:

```bash
sudo sed -i '/strangler-shop-planner.conf/d' /etc/nginx/sites-enabled/dev2.loothgroup.com.conf
sudo nginx -t && sudo systemctl reload nginx
```

The slug falls straight back to the WordPress render of page 68840 — which is why
**68840 must stay published and unedited**. Confirm with:
`bash tools/gates/shop-planner-url-gate.sh --live` (green, minus the standalone flag).

## Current dev2 state — read before merging

dev2 is carrying a **lane overlay** right now so Ian can look at it:

- `/etc/nginx/snippets/strangler-shop-planner.conf` → **this worktree**, not the
  serving checkout
- the include line was added to `/etc/nginx/sites-enabled/dev2.loothgroup.com.conf`
- `/var/www/dev/shop-layout-planner.{php,css}` → **this worktree**

At merge, repoint all three at `~/loothplatformv2-clean` (steps 3–4 above do exactly
that with `ln -sfn`). To remove the overlay instead:

```bash
sudo rm /etc/nginx/snippets/strangler-shop-planner.conf \
        /var/www/dev/shop-layout-planner.php /var/www/dev/shop-layout-planner.css
sudo sed -i '/strangler-shop-planner.conf/d' /etc/nginx/sites-enabled/dev2.loothgroup.com.conf
sudo nginx -t && sudo systemctl reload nginx
```

## Two things Ian should decide, not me

1. **The `<title>` changes.** The live page emits `<title>The Looth Group</title>`
   and **no meta description**, because page 68840's `post_title` is empty. The
   standalone page uses the title, description and OG strings written in the page's
   own authoring comment as the intended values. That is applying stated intent, not
   inventing SEO copy — but it *is* a change to a ranking page, so it should be a
   conscious call. Reverting to the old empty-title behaviour is a two-line edit.
2. **The FAQ claims gating that does not exist.** It says PDF export and save/load
   are "available to Looth Group members", but `lgapps_settings.gated_features` is an
   **empty array** on live — nothing is gated, for anyone. Pre-existing, unchanged by
   this work, and it is a copy-vs-product decision: either gate the features or fix
   the sentence.

## Still open, deliberately not in this change

- **Touch support.** The canvas binds mouse events only and the editor sidebar is
  `display:none` under 600px, so the planner is desktop-only. Pre-existing. The page
  copy already says "desktop, laptop, and tablet".
- **Vendoring jsPDF.** Still cdnjs, matching `app.php:23-29`. It now carries an SRI
  hash computed from the real response, so a tampered file fails closed rather than
  executing — but availability still depends on cdnjs.
