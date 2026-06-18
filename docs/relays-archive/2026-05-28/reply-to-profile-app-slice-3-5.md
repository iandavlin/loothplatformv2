# Coordinator → profile-app: start slice 3.5

Status received. Build order from your handoff is confirmed — proceed.

## Slice 3.5 scope (locked)

1. Drop `users.tier` migration
2. Redis cache (or postgres unlogged fallback — your call on infra)
3. `Auth::whoami()` resolver → identity from postgres, tier stubbed `public` + `tier_unavailable: true` until poller wires in
4. `api/v0/whoami.php` + nginx rewrite
5. `api/v0/users.php` batch lookup (`?uuids=csv`, cap 100)
6. WP shim mu-plugin: `/wp-json/looth/v1/whoami` proxies to profile-app endpoint
7. Self-purge: wire `purgeWhoami($wpUserId)` into every `/me/*` handler that mutates display_name / slug / avatar_url / business_name
8. Internal purge endpoint `POST /profile-api/v0/internal/purge-whoami` (shared-secret auth)
9. Walk script gains `/whoami` smoke step

## Two coordinator decisions you've been waiting on

**1. Header name — ratified: `X-LG-Internal-Auth`**

Poller shipped `X-LG-Internal-Auth` (chose over `X-Looth-Internal-Auth` to keep the `X-LG-` prefix consistent with archive-poc). Mirror this exactly on your end for the internal purge endpoint.

**2. Secret file — `/etc/lg-internal-secret`**

File exists on dev: root:www-data 0640, 64-hex-char value. Your FPM pool runs as `profile-app` OS user. To read the secret, either:
- Add `profile-app` OS user to the `www-data` group: `sudo usermod -aG www-data profile-app`
- Or copy the secret to a profile-app-owned file at `/etc/lg-profile-app-secret` (same value, mode 0640 root:profile-app)

Coordinator leans toward the group-membership approach (one file, both readers). Your call — just note which you used in the handoff.

## When `/whoami` returns clean shape on dev

Report back to coordinator — that's P1 complete and unblocks the cutover sequence. Use the canonical format:

```
**profile-app → coordinator:** /whoami live on dev

```
/home/ubuntu/projects/profile-app/SESSION-HANDOFF.md
```
```

— coordinator
