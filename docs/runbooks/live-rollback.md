# Live rollback — get the site back in seconds, without keeper

**Ian, 2026-07-30: "I guess I'll fucking hope deploying your work doesn't fuck up my
website."** You should not have to hope. This is the undo. Run it yourself.

## The one command

```bash
# ON LIVE, in the serving checkout
git reset --hard c57b70f          # <- the commit live ran before 2026-07-30
sudo nginx -t && sudo systemctl reload nginx    # only if the deploy touched nginx
```

`c57b70f` is also pinned as the **`release`** branch on origin, so the target survives
even if this file is lost: `git rev-parse origin/release`.

**Before deploying anything, record the current commit** — that becomes the next
rollback target:

```bash
git -C <serving checkout> rev-parse --short HEAD
```

## Why `reset --hard` and not a revert

`lg-deploy` **refuses to run unless the serving checkout is on `main`** (a guard from
the 2026-07-26 outage). `git reset --hard` keeps you on `main`, just at an older
commit, so it does not fight that guard. A `git revert` would need a push and a pull
and is slower when the site is down.

## PROVEN, not asserted — dev2, 2026-07-30

Rolled the dev2 serving checkout from `9707fa6` back to `c57b70f`: `/` and `/hub/`
both **HTTP 200**. Rolled forward again to `9707fa6`: `/`, `/hub/`,
`/hub/?type=discussions` all **HTTP 200**, tree clean, `0 0` against `origin/main`.
Both directions exercised on a real serve, not described.

## ⚠️ What a rollback does NOT undo

- **Migrations.** Rolling back code leaves database changes in place. For the
  2026-07-30 window this is safe by design: both migrations are *additive* (a new
  table, a widened CHECK constraint), so older code simply ignores them. `ROLLBACK`
  SQL exists at `~/lane-outbox/thread-follow-LIVE-topic-follow-ROLLBACK.sql` but
  **should not normally be run** — dropping the table would lose real follow rows.
- **Symlinks.** A rolled-back checkout leaves new mu-plugin symlinks pointing at files
  that no longer exist at that commit. A dangling mu-plugin symlink is a PHP warning,
  not an outage, but remove them if the rollback is permanent:
  `sudo rm /var/www/dev/wp-content/mu-plugins/<name>.php`
- **nginx config.** Symlinked out of the checkout, so the reset restores the old file —
  but the running workers keep the new config until reloaded. Reload, then verify the
  **worker** start time, not the master's:
  `ps -eo lstart,cmd | grep "[n]ginx: worker"`

## Verify the rollback actually took

Never smoke live with a plain public curl — Cloudflare bot-challenges it into a 403
that reads as an outage. On the box:

```bash
for u in / /hub/ ; do
  curl -sk -o /dev/null -w "$u %{http_code}\n" --max-time 15 \
    --resolve "loothgroup.com:443:127.0.0.1" "https://loothgroup.com$u"
done
```
