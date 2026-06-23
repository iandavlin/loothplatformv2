# Poller — environment, secrets, and deploy

How `lg-patreon-stripe-poller` stays box-portable: every value that differs
between dev2 and live comes from config, never a hardcoded string in the repo.

## 1. Box-varying config (from `/etc/looth/env` via `lg_env()`)

The plugin reads the box's **public host** from `/etc/looth/env` through the
shared helper (`/srv/lg-shared/lg-env.php`, `lg_env()['host']`):

- `PurgeNotifier::onTierChanged` — `Host:`/SNI override for the tier-change
  ping to profile-app.
- `UserLifecycle` — profile-identity create + identity-erase calls.

Precedence at each site: the explicit `LG_PROFILE_APP_PUBLIC_HOST` wp-config
constant wins if defined; otherwise `lg_env()['host']`; otherwise a last-resort
literal. The lookup is absent-safe (no `/etc/looth/env` → literal, box
unchanged).

The **WP path** is never hardcoded (plugin runs inside WP: `ABSPATH`/`$wpdb`).
The **membership DB** DSN is option-driven (`Db.php` → `lgms_db_*`), not a box
literal.

> The hardcoded `https://loothgroup.com/...` URLs in `templates/` and the
> mailers are **intentional** — `MembershipGuide::liveify()` deliberately points
> member-facing links at the production public site on every box. Do not
> "fix" these to the dev host.

## 2. dev → live promotion

Flip the **two** values in `/etc/looth/env` (`LG_ENV=dev2→live`,
`LG_PUBLIC_HOST=dev2.loothgroup.com→loothgroup.com`). Nothing in the poller
code changes. See repo-root `env.template`.

## 3. Secrets — NOT in git; how they are provisioned

No secret value is committed. The repo `.gitignore` excludes `vendor/`,
`*.log`, `assets/video/`. Real values live in:

| Secret | Where it lives | How to set |
|---|---|---|
| `LG_INTERNAL_SECRET` | `wp-config.php` define | edit wp-config (shared with profile-app) |
| `LG_PROFILE_APP_URL` | `wp-config.php` define | edit wp-config (loopback `https://127.0.0.1`) |
| `lgms_db_pass` (+host/port/name/user) | WP option (DB) | Settings → LG Member Sync, or `wp option update` |
| `lgms_shared_secret` (`X-LGMS-Token`) | WP option (DB) | `wp option update lgms_shared_secret <val>` |
| `lgms_stripe_secret_key` | WP option (DB) | from Stripe dashboard → `wp option update` |
| `lgpo_client_id` / `lgpo_client_secret` | WP option (DB) | from Patreon OAuth app |
| `lgpo_creator_access_token` / `lgpo_creator_refresh_token` | WP option (DB) | Patreon creator OAuth (auto-refreshed) |
| `profile_hook_secret` | WP option (DB) | shared with profile-app bridge |

`lg_membership` DB creds are also mirrored in `lg-stripe-billing/.env` for the
Slim side. Read a value with `sudo -u looth-dev wp --path=/var/www/dev option
get <key>`; never paste it into the repo or a relay.

Provisioning a fresh box: WP options ride along in the cloned DB (dev2 is an AMI
of live), so a clone needs **no** re-entry. A truly empty box: set the
wp-config defines, then `wp option update` each row above.

## 4. Deploy model

The poller is a **wp-content plugin**, not a `/srv` git-served app. It lives at
`/var/www/dev/wp-content/plugins/lg-patreon-stripe-poller/` as a real dir owned
by `looth-dev` and is deployed via the **self-verifying patchers** in
`deploy/patch-*.py` (run as `looth-dev`; copy the patcher to `/tmp` first so
that user can read it) — NOT `git pull`.

> **Long-term (the consolidation goal):** symlink the plugin dir into the serve
> clone like the `/srv` apps so deploy becomes `git pull`. That is a serve-
> topology change → keeper review; tracked separately from this reconcile.
