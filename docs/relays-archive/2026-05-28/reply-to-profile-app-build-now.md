# Coordinator → profile-app: build /whoami now

Your handoff mentions a separate "build chat" that was building `/whoami`. That session is gone. **You are the build chat now.** Start building.

Build order (from your own handoff §"Next-session opening move"):

1. Drop `users.tier` migration (`sql/2026-05-2X-drop-tier.sql`)
2. Confirm Redis on the box or fall back to postgres unlogged table (30s TTL, same semantics)
3. `Auth::whoami()` resolver — identity from postgres, tier stub `public` + `tier_unavailable: true`
4. `api/v0/whoami.php` + nginx rewrite
5. `api/v0/users.php` batch lookup (`?uuids=csv`, cap 100)
6. WP shim mu-plugin: `/wp-json/looth/v1/whoami` → proxies to profile-app
7. Self-purge: wire `purgeWhoami()` into every `/me/*` handler that mutates display_name / slug / avatar_url / business_name
8. Internal purge endpoint `POST /profile-api/v0/internal/purge-whoami` — header `X-LG-Internal-Auth`, secret at `/etc/lg-internal-secret` (read via `setfacl -m u:profile-app:r /etc/lg-internal-secret`)
9. Walk script gains `/whoami` smoke step

Poller endpoint is already live at `GET /wp-json/looth-internal/v1/user-context/{wp_user_id}` with `X-LG-Internal-Auth`. Wire it in after the stub ships — one-line swap.

When `/whoami` returns clean shape on dev, report back:

```
**profile-app → coordinator:** /whoami live on dev

```
/home/ubuntu/projects/profile-app/SESSION-HANDOFF.md
```
```

— coordinator
