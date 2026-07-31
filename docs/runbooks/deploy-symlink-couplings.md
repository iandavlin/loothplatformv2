# Deploy couplings a `git pull` does NOT do

> ## ⚠️ THERE IS NOW ONE COMMAND FOR ALL OF THIS
>
> ```bash
> bash tools/deploy/live-deploy.sh            # DRY RUN — the default
> bash tools/deploy/live-deploy.sh --apply
> ```
>
> It records the rollback SHA first, derives every coupling from
> `git diff --name-status <old>..<new>` rather than a checklist, runs the couplings
> in order, **verifies** each step instead of merely running it, and prints what it
> did **not** do so silence is never mistaken for coverage. It refuses to continue
> when a step fails.
>
> Built 2026-07-31 after a live deploy that needed five manual interventions. Ian:
> *"Are we doing a lot of hand work? This looks like stuff that should have been
> pulled?"* Read `docs/runbooks/live-divergences.md` alongside it — that is where
> anything which genuinely cannot arrive by pull is recorded, and it is the
> allowlist for `tools/gates/deploy-drift-gate.sh`.
>
> **The rest of this document is still accurate and is the WHY behind those steps.**
> Two things it did not cover, both now handled by the script: **new nginx snippet
> symlinks** (nothing covered them at all — a new snippet arrives in the pull with no
> `/etc/nginx/snippets/` link, so `nginx -t` fails and the reload is correctly
> refused), and **Postgres roles/grants** (`cutover/ensure-pg-roles.sh`).

**Rule (Ian, 2026-07-30): "if you are creating a symlink add to runbook and to repo."**
A symlink made by hand exists only on the box that saw it made. This runbook is where
that knowledge lives; the *tool* below is where the symlink SET lives.

## Do not hand-author symlinks — the farm already derives them

`cutover/symlink-farm.sh` builds every link from repo contents. For mu-plugins it
loops **every** `platform/mu-plugins/*.php`, so a newly merged mu-plugin needs no
manual `ln` and no edit to any list. It is idempotent and **dry-run by default**.

```bash
# DRY RUN first, always. Filter to the thing you just merged.
REPO=/home/ubuntu/loothplatformv2-clean WP=/var/www/dev \
  bash cutover/symlink-farm.sh author-socials

# Apply.
REPO=/home/ubuntu/loothplatformv2-clean WP=/var/www/dev \
  bash cutover/symlink-farm.sh --apply author-socials
```

> ### ⚠️ `REPO=` IS NOT OPTIONAL ON dev2
> The script defaults to `REPO=/home/ubuntu/projects`. **The dev2 serving checkout is
> `/home/ubuntu/loothplatformv2-clean`.** Running with defaults points fresh symlinks
> at `~/projects` — a directory that is not the serve and is not what `lg-deploy`
> pulls. Always pass `REPO=` explicitly.

Per-target behaviour: repo source missing → SKIP; already correct → OK; a real file in
the way → moved to `<target>.pre-symlink-<ts>` (that file is your rollback) → linked;
wrong symlink → repointed.

## The full order, and why each step exists

```
1. merge to main, push
2. git -C ~/loothplatformv2-clean pull --ff-only origin main
3. symlink-farm.sh --apply   (only if the branch ADDED a mu-plugin / webroot file)
4. sudo nginx -t             (only if the branch touched platform/nginx/)
5. sudo systemctl reload nginx
6. verify — see below
```

**Step 3 is needed because the symlink set is not tracked; the farm regenerates it.**
`webroot/install-symlinks.sh --new-only` is the equivalent for loose webroot files.

**Step 5 is a separate fact from step 2.** nginx snippets are symlinked into the
serving checkout, so the pull deploys the *file* — but the running workers keep the
old config until reloaded. A disclosure fix once sat inert on dev2 for three hours
this way.

## Verify the thing, not the thing next to it

- **Symlink:** `sudo ls -la <target>` — as `ubuntu` you get *Permission denied* on
  `/var/www/dev/wp-content/`, which reads as "0 mu-plugins" and is a **false
  negative**. Use `sudo`. (There are 35 as of 2026-07-30.)
- **nginx actually reloaded:** compare the **worker** start time, not the master's —
  `ps -eo lstart,cmd | grep "[n]ginx: worker"`. Fresh workers = live config.
- **A new internal endpoint:** it should go **404 → 403**, not 404 → 200. 403 means
  nginx routes it and the auth gate is doing its job. Curl it on the box with
  `--resolve`; a plain public curl is Cloudflare-challenged into a 403 that looks
  identical to success and identical to an outage.

```bash
curl -sk -o /dev/null -w "%{http_code}\n" -X POST \
  --resolve "dev2.loothgroup.com:443:127.0.0.1" \
  "https://dev2.loothgroup.com/profile-api/v0/internal/byline-socials"
```

## Live

Same sequence, `lg-deploy` in place of the pull. **Every live step is Ian's to run.**
Hand him literal commands with `set -euo pipefail` and numeric guards — never a
paste-block with chained `$(...)` lookups that can silently return empty.

## Worked example — profile-social-links, 2026-07-30

Merged `a307871`. Added `platform/mu-plugins/lg-author-socials.php` **and** a new
internal location in `platform/nginx/strangler-profile-app.conf`. Pull alone deployed
neither. After symlink + reload: workers restarted `21:00:08`, endpoint moved 404 →
**403**. Without the reload the endpoint 404s and the byline silently degrades to the
legacy ACF rail — which looks like the fix failed rather than like an outage, and is
the harder failure to notice.
