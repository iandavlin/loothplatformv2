# Looth Secrets Dashboard

Unified WordPress admin page to **read / reveal / rotate** the platform's
operational secrets. Scoped v1 = **R2 buckets + Patreon API (poller)**.

## What it manages (v1)

| Group | Secrets | Backend |
|-------|---------|---------|
| **R2 buckets** | `/etc/looth/profile-r2` (bucket, endpoint, access key, secret) | plain `key=value` file |
| | rclone remotes `r2, r2up, r2backups, cfbk, r2test, r2live` — access key + secret each | `~/.config/rclone/rclone.conf` INI |
| **Patreon API** | `lgpo_client_id`, `lgpo_client_secret`, `lgpo_creator_access_token`, `lgpo_creator_refresh_token` (+ `…_expires_at`, read-only) | WP `wp_options` |

Twin tokens (edit both when rotating): **`r2up` ≡ `profile-r2`**, **`cfbk` ≡ `r2backups`**.
The manifest cross-notes these in the UI.

## Architecture — privilege is in one tiny binary, not the web app

```
WP admin page (mu-plugin)         sudo -n          root-owned helper
lg-secrets-dash.php  ──────────────────────────▶  /usr/local/sbin/lg-secrets-helper
 (holds no secrets, shells out)                     │ loads root-owned manifest
                                                    │ env_kv  -> /etc/looth/profile-r2
                                                    │ rclone  -> rclone.conf
                                                    │ wp_opt  -> drops to wp-cli as $LG_WP_USER
                                                    └ logs every reveal/set -> /var/log/lg-secrets-audit.log
```

- The dashboard runs as the WP FPM user and **only** calls the helper.
- The helper runs as **root** because the secrets are split across owners
  (`root:profile-app 0640`, ubuntu-owned rclone.conf, etc.) — no single non-root
  identity spans them. Root is used surgically: `wp_option` writes drop to the WP
  user; only file/rclone writes use root, preserving each file's existing
  owner/mode via an atomic temp-then-rename.
- Confinement is the helper: it acts only on **manifest-declared** ids, validates
  every request, refuses non-writable entries, and logs. The dashboard can never
  become a general root exploit.

## In git vs. box-local (the important bit)

**In git** (`loothplatformv2`):
- `platform/secrets/manifest.php` — declares every secret (no values). Derives
  box paths from `/etc/looth/env`, so it's correct on dev2/dev3/live unchanged.
- `platform/bin/lg-secrets-helper` — the privileged CLI (source).
- `platform/mu-plugins/lg-secrets-dash.php` — the admin UI.
- `platform/sudoers/lg-secrets` — sudoers template (`@WPUSER@` placeholder).
- `platform/bin/install-secrets-bridge.sh` — the provisioning installer.

**Box-local, provisioned by the installer (NOT git):**
1. `/usr/local/sbin/lg-secrets-helper` — root:root 0755 copy of the helper.
   *Cannot* be the repo file: sudo-running a user-writable script = instant root.
2. `/usr/local/lib/looth/lg-secrets-manifest.php` — root-owned manifest copy the
   helper loads (a tampered repo manifest must not redirect a root write).
3. `/etc/sudoers.d/lg-secrets` — `visudo`-validated, references the root-owned path.
4. `/var/log/lg-secrets-audit.log` — root:root 0600.
5. dev only: `wp-content/mu-plugins/lg-secrets-dash.php` symlink → repo.
   (Live deploys the mu-plugin via `deploy/deploy.sh` rsync.)
6. The **secret values themselves** stay where they already live; none enter git
   (`.gitignore` already blocks `.env`, `*.pem`, `*.key`).

## Install / deploy

```bash
# on the box, after git pull:
sudo ~/loothplatformv2/platform/bin/install-secrets-bridge.sh   # idempotent
# verify:
sudo -u "$(. /etc/looth/env; echo $LG_WP_USER)" sudo -n /usr/local/sbin/lg-secrets-helper list
```
Then: WP admin → **Looth Secrets**.

## Notes

- Reveal and Save are admin-only (`manage_options`), nonce-protected, and logged
  with the WP username as `actor=`.
- Adding a secret = one manifest entry + re-run the installer (re-copies the
  manifest). No code change.
- Out of scope for v1 (easy to add later): wp-config salts, app `.env` Stripe,
  `/etc/lg-*` platform secrets, vendor Patreon `patreon-*`, license keys.
