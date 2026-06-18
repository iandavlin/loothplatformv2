# Coordinator → archive-poc: step 2 — switch to /whoami-backed gating

Step 1 of the cutover sequence is complete. `/whoami` is live on dev at
`/profile-api/v0/whoami` (WP shim at `/wp-json/looth/v1/whoami`).
You are now step 2.

## What to build

Switch your auth gating from cookie-only (`lg_tier` cookie) to
`/whoami`-backed for sensitive decisions. Cookie stays as first-paint hint
only — not as truth for anything more sensitive.

Pattern:

1. **First-paint** — read `lg_tier` cookie as before (no network call, fast).
2. **Gate decisions** — call `/whoami` (or read from your own short-lived
   server-side cache of the response) for any decision that controls access:
   - `edit_archive_poc` capability check → use `capabilities.edit_archive_poc`
     from `/whoami` response
   - Tier-gated overlay logic → use `tier` from `/whoami` (`public`, `lite`,
      `pro`)
   - Authenticated-only views → use `authenticated` boolean

## /whoami shape (confirmed)

Authed response:
```json
{
  "authenticated": true,
  "user_uuid": "...",
  "wp_user_id": 1,
  "slug": "iandavlin",
  "display_name": "Ian B Davlin",
  "avatar_url": "...",
  "tier": "public",
  "provenance": "lapsed",
  "capabilities": {
    "edit_own_profile": true,
    "manage_options": true,
    "edit_archive_poc": true,
    "edit_posts": true,
    "moderate_forums": true
  },
  "cache": { "etag": "W/\"...\"", "max_age": 30 }
}
```

Anon response:
```json
{ "authenticated": false, "tier": "public" }
```

`tier_unavailable: true` appears only if poller is unreachable — treat same
as `tier: "public"` (fail open, not fail closed).

## Coordinator answers to your open design questions

From your prior handoff:

**a. `edit_archive_poc` cap** — you own and register it. The `/whoami`
response already emits it correctly from the poller's `user_can()` call.
Wire your editor gate directly to `capabilities.edit_archive_poc`.

**b. Coordinator answer on capability pass-through** — profile-app will
continue to pass through `edit_posts` and `moderate_forums` in addition
to the §2-named caps. Keep `edit_archive_poc` as your gate; ignore the
extras.

**c. Stale-cache fallback** — profile-app fails visibly (`tier_unavailable:
true`) when poller is down. Your guard (`render public, no overlays, no
redirects`) is correct. Near-zero risk on live since poller + `/whoami`
ship in the same cutover window.

**d. Anon shape** — `{authenticated: false, tier: "public"}` only. No
session ID needed.

## How to call /whoami from your FPM

Important gotcha from profile-app (their "What surprised me" #1):

**FPM curl loopback requires `CURLOPT_HTTP_VERSION=CURL_HTTP_VERSION_1_1`
and `CURLOPT_TIMEOUT=5`.** ALPN handshake on a fresh FPM-worker SSL
session will time out on HTTP/2. Set these explicitly or calls will fail.

Recommended: call `/wp-json/looth/v1/whoami` (WP shim on the same host)
with the caller's cookies forwarded. That shim forwards to profile-app
and returns the identical shape.

## Internal call pattern

```php
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://127.0.0.1/wp-json/looth/v1/whoami',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_TIMEOUT        => 5,
    CURLOPT_HTTPHEADER     => [
        'Host: dev.loothgroup.com',
        'Cookie: ' . $_SERVER['HTTP_COOKIE'],
    ],
]);
$body = curl_exec($ch);
$whoami = $body ? json_decode($body, true) : null;
```

Cache this response for the duration of the request (or a short TTL)
so you don't call it on every render row.

## When done

Report back:

```
**archive-poc → coordinator:** step 2 complete, /whoami-backed gating live
```

Path:
```
/home/ubuntu/projects/archive-poc/SESSION-HANDOFF.md
```

— coordinator
