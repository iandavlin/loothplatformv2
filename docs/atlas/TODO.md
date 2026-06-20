# TODO — deferred platform tasks & tech debt

Cross-cutting items deliberately deferred. Each links to the SYSTEM-MAP section that explains
the current (non-ideal) state. Add new items with a date + a one-line why.

## Consolidation / config

- [ ] **Single-source the app public host** (added 2026-06-20). The 4 standalone apps
  (archive-poc, bb-mirror, events, membership) hardcode `env[LG_<APP>_PUBLIC_HOST]` in their own
  FPM pools — 6 pin points across `archive-poc.conf`, `bb-mirror.conf`, `events.conf`,
  `membership.conf`, `looth-dev.conf` (×2) — instead of deriving from `/etc/looth/env`
  `LG_PUBLIC_HOST`. Result: changing the public host is a 7-point edit (env + 6 pools) +
  `systemctl restart php8.3-fpm`, easy to half-apply. **Fix:** make all pools inherit the one env
  — `EnvironmentFile=/etc/looth/env` on the `php8.3-fpm` systemd unit, or have apps read
  `lg_env('PUBLIC_HOST')` via `/srv/lg-shared/lg-env.php` — so the host is genuinely one knob.
  Ref: SYSTEM-MAP §2/§3.

## Cut blockers (pre-DNS)

- [ ] **`loothgroup.com` TLS cert on dev2** (added 2026-06-20). Current LE cert covers only
  `dev2.loothgroup.com` (webroot HTTP-01); the apex can't be HTTP-01-validated while it still
  points at Cloudflare/live, and the on-box `cf-api-token` is R2-scoped (no DNS/SSL). **Fix:**
  install a **Cloudflare Origin Certificate** (covers apex + `*.loothgroup.com`, 15yr, trusted by
  CF "Full (strict)"), OR issue via LE DNS-01 with a CF DNS:Edit token. Moot only if CF SSL mode
  is "Full" (non-strict)/"Flexible". **Needs a CF credential (Ian).** Ref: SYSTEM-MAP §3.
