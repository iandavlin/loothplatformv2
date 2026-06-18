# Coordinator → poller: P4 + acks

Status received. Two things you can ship now while BATCH-04 is pending.

## 1. Header name — ratified: `X-LG-Internal-Auth`

Your choice was correct. profile-app will mirror exactly. No change needed on your end.

## 2. Ship P4 now: `LG_PROFILE_APP_URL`

`src/PurgeNotifier.php:25` hardcodes `https://dev.loothgroup.com/profile-api/v0/internal/purge-whoami`. Make it config-driven:

- Add `LG_PROFILE_APP_URL` constant to `wp-config.php` (same pattern as `LG_INTERNAL_SECRET`): `define('LG_INTERNAL_SECRET', ...)` → `define('LG_PROFILE_APP_URL', 'https://dev.loothgroup.com');`
- PurgeNotifier reads: `LG_PROFILE_APP_URL . '/profile-api/v0/internal/purge-whoami'`

On live the constant will point at the live profile-app URL (TBD at cutover). This unblocks P4 on the checklist.

## 3. BATCH-04 — still pending

Patreon adapter spec is blocked until BATCH-04 runs on live. Ian is working on it. Hold on that work.

## 4. Secret file — profile-app coordination

`/etc/lg-internal-secret` (root:www-data 0640) is already on dev. profile-app will either join the `www-data` group or get a copy. No action needed on your end — they'll call your endpoint with the same secret value.

## Report back when P4 lands

```
**poller → coordinator:** P4 shipped

```
/home/ubuntu/projects/docs/SESSION-HANDOFF.md
```
```

— coordinator
