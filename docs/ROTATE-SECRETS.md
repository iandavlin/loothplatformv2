# ROTATE-SECRETS — post-go-live credential rotation runbook

**Box:** the new prod box (was "dev2", 34.193.244.53) going live as `loothgroup.com`.
**When:** run AFTER the DNS flip + smoke-test pass, in a maintenance window. Ian's call: rotate
**everything** once we're up, because the dev build carried dev/test secrets onto the prod box.
**Author:** build chat, 2026-06-17. Anchored to real locations recon'd on dev1; `[VERIFY]` =
location/var-name not fully confirmed — confirm before rotating that line.

> **⚠️ LAUNCH IS WITH LIVE SALTS (Ian 2026-06-17).** We deliberately carry the LIVE WP keys+salts
> (`/etc/looth/live-wp-keys.php`) so live login cookies stay valid across the flip — **users stay
> logged in, no forced re-login at cut.** This REVERSES the earlier top-off "everyone re-logins"
> assumption. Therefore:
> - Rotating the WP salts is **NOT free** anymore — it WILL log everyone out. It's a **separate,
>   scheduled session-reset event**, not a freebie folded into the cut window.
> - The live salts are genuine prod secrets (not dev/test), so rotating them is **lower priority /
>   optional** — do it only if you want a clean break from old-live. The dev-CARRIED secrets (JWT,
>   R2 clone, internal/app-to-app, DB passwords) are the urgent ones; rotate those first.
> - For carried sessions to actually validate, the users' `wp_usermeta` `session_tokens` rows must
>   come over with the live salts — **flag to the top-off lane** (additive/missing-rows-only skips
>   users already on the dev mirror, so their tokens may not be the live ones).

---

## 0. Operating principle + cascade order

Some secrets are **paired across services** — rotate both ends in the SAME step or auth breaks
silently. Some **invalidate sessions** — do them first. Rotate in THIS order:

1. **Session-invalidating, do first (window start):** WP keys+salts → JWT signing keypair.
2. **Paired app-to-app secrets (lockstep):** `LG_INTERNAL_SECRET`, `profile_hook_secret`, any HMAC.
3. **Datastore passwords:** MariaDB app user, Postgres roles (update the app configs in the same step).
4. **External providers (rotate at provider + on box):** R2, Stripe, Patreon, VAPID, SMTP.
5. **Access + cleanup:** SSH/EC2 keypair, retire `/etc/lg-topoff.conf`, confirm cookie-gate is gone.
6. **Third-party plugin keys (lower urgency):** the `wp_options` pile (§I).

After EACH group: run the §Verification checklist before moving on.

---

## A. WordPress keys & salts  — `/var/www/dev/wp-config.php`

| Constant | Effect of rotating |
|---|---|
| `AUTH_KEY` `SECURE_AUTH_KEY` `LOGGED_IN_KEY` `NONCE_KEY` + matching `*_SALT` (8 total) | logs out every WP session |
| `WP_CACHE_KEY_SALT` | invalidates object-cache keys (harmless, repopulates) |
| `LG_INTERNAL_SECRET` | **app-to-app** — see §D, rotate in lockstep, NOT here alone |

> **Launch state:** these 8 are the **LIVE** salts (from `/etc/looth/live-wp-keys.php`), carried so
> sessions survive the flip. Rotating them = a deliberate all-user logout; schedule it as its own event.

**Rotate (the 8 auth keys+salts):**
```bash
# generate fresh values
curl -s https://api.wordpress.org/secret-key/1.1/salt/
# paste over the 8 define() lines in /var/www/dev/wp-config.php (sudo), keep formatting
sudo -u looth-dev redis-cli -n 0 FLUSHDB   # if redis object cache, drop stale cache  [VERIFY db index]
```
No service restart needed (PHP reads wp-config per request). All users re-login (expected at cut).
**Verify:** fresh login works; `/whoami` returns correct identity/tier.

---

## B. Datastore passwords

### B1. MariaDB — WP DB user (`DB_USER`/`DB_PASSWORD` in wp-config.php)
```bash
NEWPW=$(openssl rand -base64 24)
sudo mysql -e "ALTER USER '<DB_USER>'@'localhost' IDENTIFIED BY '$NEWPW'; FLUSH PRIVILEGES;"
# update DB_PASSWORD in /var/www/dev/wp-config.php to match, same step
```
**Verify:** site loads, `wp option get siteurl` works.

### B2. Postgres roles (profile_app, discovery, forums/bb-mirror, membership)
Each app reads its DSN from its own config — rotate the role AND the config together:
```bash
sudo -u postgres psql -c "ALTER ROLE <role> WITH PASSWORD '<new>';"
```
Update the matching DSN in:
- profile-app runtime config `[VERIFY path — not found under /srv/profile-app; likely a systemd EnvironmentFile or app .env]`
- `bb-mirror/config.php` (in the projects tree → deployed copy)
- `archive-poc/config.php`
- `lgms_db_*` options if the membership DB role changes (§D)

**Verify:** `/whoami` (profile-app), `/hub/` feed (bb-mirror), `/archive-poc/` (archive-poc) all render.

---

## C. JWT signing keypair  — `/etc/looth/jwt-private.pem` + `jwt-public.pem`

RS256 keypair that signs the identity/`whoami` JWTs (profile-app issues, consumers verify with the
public key). Rotating **re-mints all tokens** → existing JWTs invalid until refresh.
```bash
sudo openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out /etc/looth/jwt-private.pem.new
sudo openssl rsa -pubout -in /etc/looth/jwt-private.pem.new -out /etc/looth/jwt-public.pem.new
# swap both atomically, preserve perms (private 0640 root:looth-dev, public 0644 root:root)
sudo install -m0640 -o root -g looth-dev /etc/looth/jwt-private.pem.new /etc/looth/jwt-private.pem
sudo install -m0644 -o root -g root      /etc/looth/jwt-public.pem.new  /etc/looth/jwt-public.pem
sudo systemctl restart profile-app   # [VERIFY unit name] + any consumer that caches the pubkey
```
**Pre-req confirmed at build:** the refresh-JWT re-mints cleanly for wrong-key/expired/absent token +
valid WP cookie (a build blocker we cleared). So a stale JWT after rotation self-heals on next request.
**Verify:** logged-in `/whoami` ~5ms path returns tier; no re-mint storm in logs.

---

## D. App-to-app shared secrets  (ROTATE IN LOCKSTEP)

These authenticate one internal service to another — change both ends in one step or provisioning/
bridge calls 401 silently.

| Secret | Lives at | Pairs |
|---|---|---|
| `profile_hook_secret` | `wp_options` (WP) **and** profile-app config | WP → profile-app bridge (provisioning) |
| `LG_INTERNAL_SECRET` | `wp-config.php` **and** whatever consumes it `[VERIFY consumer]` | internal app calls |
| `lgms_db_*` (host/port/name/user/pass) | `wp_options` | poller → membership DB (tier lookups) |

```bash
# profile_hook_secret example
NEW=$(openssl rand -hex 32)
sudo -u looth-dev -i wp --path=/var/www/dev option update profile_hook_secret "$NEW"
# set the SAME value in profile-app config, then restart profile-app   [VERIFY path]
```
**Verify:** create a throwaway user → it provisions into profile-app (not stuck anon); poller tier
lookup returns (not 401/500). This is the "5-way re-arm" surface from the deploy traps.

---

## E. Object storage (Cloudflare R2)

The dev box carried a **read-only, IP-locked R2 clone token** — prod needs the REAL bucket + write creds.

| Location | What |
|---|---|
| `/home/ubuntu/.config/rclone/rclone.conf` | rclone R2 token (uploads sync/backfill) |
| `/etc/looth/profile-r2` | profile-app media R2 creds (0640 root:profile-app) |
| WP offload plugin creds `[VERIFY — AS3CF key in wp-config is DEAD per memory; the live S3/R2 key is elsewhere]` | WP media → R2 |

Rotate at Cloudflare (new R2 API token / S3 access-key pair, scoped to the real bucket, write), then
overwrite each file above + restart the consumer (profile-app, any sync unit). **Verify:** upload an
avatar → it lands in real R2 and serves; image resizer `/img.php?w=` works.

---

## F. Stripe  — `/srv/lg-stripe-billing/.env`

Holds the Stripe **secret key** + **webhook signing secret** `[VERIFY exact var names: STRIPE_SECRET_KEY,
STRIPE_WEBHOOK_SECRET]`. Also plugin-side Stripe keys live in `wp_options` (§I: `dce_stripe_api_secret_key_live`,
`elementor_pro_stripe_live_secret_key`) — decide which path is actually live before launch.
```bash
# 1. Stripe dashboard: roll the secret key (or use a fresh restricted key), copy new webhook signing secret
# 2. update /srv/lg-stripe-billing/.env (sudo, owner ccdev)
# 3. re-point the Stripe webhook endpoint at https://loothgroup.com/... and restart the billing service [VERIFY unit]
```
**Verify:** a test-mode payment + webhook delivers and is signature-verified.

---

## G. Patreon

OAuth **client id/secret** + **webhook secret** `[VERIFY storage — likely the poller plugin config or
wp_options]`. Rotate at the Patreon developer portal, update on box, re-point the webhook at the new
host. **Verify:** Patreon OAuth onboard completes; a membership event is received + tiers a user.

## H. VAPID (web push)  `[VERIFY location — wp_options or poller/push config]`
Rotate the VAPID keypair → **all existing push subscriptions die** and clients re-subscribe. Low urgency;
do it knowingly. **Verify:** a test push delivers to a fresh subscription.

---

## I. Third-party plugin API / license keys  (`wp_options` — lower urgency, large surface)

The WP DB carries many vendor keys. Most are **license keys** (low risk) but several are **live payment
/ API secrets** worth rotating. Non-exhaustive (from recon):

- **Payment-adjacent (rotate):** `dce_stripe_api_secret_key_live`, `dce_paypal_api_client_secret_live`,
  `elementor_pro_stripe_live_secret_key`, `elementor_pro_recaptcha_secret_key`/`_v3_secret_key`.
- **API keys:** `bb-pusher-app-secret`, `bp_media_gif_api_key`, `elementor_google_maps_api_key`,
  `elementor_pro_*_api_key` (mailchimp/mailerlite/getresponse/convertkit/activecampaign/drip),
  `ewww_image_optimizer_cloud_key`, `jetpack_protect_key`, `ai1wm_secret_key`, `dce_coinmarketcap_key`.
- **License keys (rotate only if leaked):** `elementor_pro_license_key`, `bb-web_license_key`,
  `buddyx-pro-theme_license_key`, `_ff_fluentform_pro_license_key`, `dsh_license_key`,
  `edd_wbcom_bp_business_profile_license_key`, etc.

```bash
# full list to triage:
sudo -u looth-dev -i wp --path=/var/www/dev option list \
  --search='*key*' --search='*secret*' --search='*token*' --field=option_name
```
**Decide per key:** is the plugin live in prod? If the feature isn't used, the key can be cleared, not
rotated. Flag which plugins survive launch (many Elementor/legacy keys may be dead weight).

---

## J. SMTP (real mail)
Dev used mailpit (a trap). Prod needs real SMTP creds (welcome/reset/notify mail). Set the real
provider creds `[VERIFY where — WP mailer plugin in wp_options, or a system MTA]`, then send a test
reset email and confirm delivery to a real inbox.

## K. Access — SSH / EC2 keypair
- `claude-keypair.pem` (`/home/ubuntu/projects/lg-stripe-billing/claude-keypair.pem`) is the box access
  key. To rotate: add a new keypair's public key to `~ubuntu/.ssh/authorized_keys`, verify login, remove
  the old. Update any automation that references the .pem path.
- Tighten the SG SSH source from dev1's /32 to the real admin source once the build box is retired.

## L. Retire build-only creds
- `/etc/lg-topoff.conf` (`devsync_ro` read-only live MySQL creds) — **delete after the final top-off**;
  it's a build/cut tool, not a prod secret. Also drop the `devsync_ro` grant on the (old) live DB.
- **nginx cookie-gate** `$loothdev_token` — NOT rotated; it must be **removed/disabled** at cut (dev
  gate would 403 real users). Confirm it's gone in the prod nginx conf.

---

## Verification checklist (run after each rotation group)
- [ ] Fresh WP login works; logout/login bounce clean
- [ ] `/whoami` (fast path) returns correct identity + tier for a real member
- [ ] New-user provision lands non-anon (bridge ok)
- [ ] Avatar upload → real R2 → serves; `/img.php?w=` resizes
- [ ] Hub `/hub/`, archive `/archive-poc/`, a profile `/u/<slug>` all render
- [ ] Stripe test payment + webhook signature-verifies
- [ ] Patreon onboard tiers a user
- [ ] gates green: `tools/gates/run-all.sh`

## Gaps to confirm before rotating (`[VERIFY]` collected)
1. profile-app runtime config path (DSN, JWT path, hook secret) — not found under `/srv/profile-app`.
2. `LG_INTERNAL_SECRET` consumer(s).
3. Live R2/S3 write-key location for WP media (AS3CF key in wp-config is dead).
4. Stripe `.env` exact var names + the live billing systemd unit.
5. Patreon + VAPID secret storage location.
6. SMTP creds location.
7. Redis object-cache DB index (for the cache flush step).
