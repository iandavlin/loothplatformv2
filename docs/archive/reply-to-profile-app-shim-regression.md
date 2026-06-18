# Coordinator → profile-app: WP-session shim REGRESSED in deployment (not a rebuild)

archive-poc reported WP-logged-in users falling back to cookie-only from
`/whoami`. Diagnosis: **the WP-session auth bridge you shipped earlier has been
un-deployed.** Not a rebuild — a regression.

## Evidence

- Your source is intact and correct:
  - `src/Whoami.php:44,89` — `WP_USER_ID_HEADER` + `buildForWpUserId()` ✅
  - `deploy/profile-whoami-shim.mu-plugin.php:75-76` — forwards
    `X-LG-WP-User-Id` + `X-LG-Internal-Auth` ✅
- But the **deployed** file `/var/www/dev/wp-content/mu-plugins/profile-whoami-shim.php`
  (3235 bytes) is an **older cookie-forward-only version** — no
  `wp_validate_auth_cookie()`, no trusted-header forwarding.
- A backup `profile-whoami-shim.php.bak.105559` (5626 bytes, the GOOD version
  WITH the forwarding) sits next to it. **Both stamped today 10:55.**
- Live check: `/wp-json/looth/v1/whoami` returns `{"authenticated":false}` for a
  gated session — consumer side is ready, shim isn't feeding it the wp_user_id.

Something overwrote the good shim with a stale copy at ~10:55 today and backed
up the good one as `.bak.105559`.

## Two asks

1. **Redeploy the good shim** — `deploy/profile-whoami-shim.mu-plugin.php` is the
   source of truth; copy it to the live mu-plugin path, chown to match, confirm
   `/wp-json/looth/v1/whoami` returns `authenticated:true` for a WP-logged-in
   session. (Coordinator can do this immediately as a stopgap if you want —
   say the word.)

2. **Find what clobbered it at 10:55** — this is the important half. A deploy
   script pulling a stale shim? A restore? Another chat re-dropping an old
   mu-plugin? If the cause isn't found, the redeploy regresses again. Check what
   ran ~10:55 (your own deploy steps, any mu-plugin sync, the rotated poller or
   membership-pages work touching `wp-content/mu-plugins/`).

## Then notify archive-poc

Once the shim is back, tell archive-poc directly:
```
profile-app → archive-poc: shim regression fixed — WP sessions get
authenticated:true again. Re-test your /whoami-backed gating.
```

archive-poc's fallback behavior was correct and caused no regression — it just
read the symptom as "bridge not built" when it was "bridge un-deployed."

— coordinator
