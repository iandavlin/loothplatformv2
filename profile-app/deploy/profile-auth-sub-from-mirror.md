# profile-auth.php — `sub` from `_looth_uuid` mirror (Decision 2 / option b)

Cross-lane deploy artifact. The file is the WP minter
`/var/www/dev/wp-content/mu-plugins/profile-auth.php` (owner `www-data`) — it
lives outside this repo, so the change ships as this reviewed patch and is
applied with sudo, **coordinated with the infra lane** (per the lane briefing).

## What changes
`looth_auth_mint_jwt()` stops deriving `sub` from the email and instead reads the
stored profile-app uuid mirrored into WP usermeta as `_looth_uuid`. If the mirror
is absent it **refuses to mint** (throws — the existing wp_login / init / refresh
/ issue catch-blocks already degrade gracefully and skip the cookie). It never
falls back to `UUIDv5(email)` — that is the G4 silent-logout bug being killed.
Authority for this value: `Mint::subForWpUserId()` (profile-app).

## ⚠️ Activation ordering — DO NOT FLIP BEFORE THE BACKFILL
`profile-auth.php` is a live mu-plugin; editing the file activates it instantly.
If the consume-side goes live **before** the poller lane has written `_looth_uuid`
for every bridged user, fresh logins mint nothing → strangler surfaces go anon
site-wide. Required sequence:

1. **Poller lane (NOT this lane):** write `_looth_uuid` on provision (the
   user-created hook already returns `uuid`) **and** backfill all existing
   bridged users.
2. Confirm coverage: every active `wp_user_bridge` user has `_looth_uuid`.
3. **Then** apply this patch to profile-auth.php.
4. Run the acceptance gate: `bin/test-wp-minter-sub.sh` → must be GREEN.

## The edit

### 1. Header doc comment — replace
```
 * `sub` claim is UUIDv5(LOOTH_IDENTITY_NAMESPACE, lower(trim(user_email))).
 * If a user changes their email in WP, the next minted token will resolve to
 * a different uuid than profile-app stored — caveat noted in slice one handoff.
```
with
```
 * `sub` claim is the STORED profile-app users.uuid, read from WP usermeta
 * `_looth_uuid` (mirrored at provision by the poller lane). It is NOT recomputed
 * from the email, so an email change can't drift the token subject (the G4
 * silent-logout bug). Missing mirror => skip minting, never email-derive.
 * Decision 2 option (b); collapses to profile-app-sole-signer (a) post-cut.
```

### 2. `looth_auth_mint_jwt()` — replace the `$payload` build
Replace:
```php
    $now = time();
    $payload = [
        'iss'        => LOOTH_AUTH_ISS,
        'sub'        => looth_auth_compute_uuid($user->user_email),
        'wp_user_id' => (int) $user->ID,
        'email'      => $user->user_email,
        'iat'        => $now,
        'exp'        => $now + LOOTH_AUTH_TTL_SECONDS,
    ];
    return JWT::encode($payload, $pk, 'RS256');
```
with:
```php
    // `sub` = the STORED profile-app users.uuid, mirrored into WP usermeta as
    // `_looth_uuid` at provision (poller lane). Seeded from the email ONCE at
    // create and never recomputed, so a WP email change can't drift the token
    // subject (G4). Absent mirror => refuse to mint (caller skips the cookie);
    // the init/issue re-mint heals on the next pageview once the mirror lands.
    // NEVER fall back to looth_auth_compute_uuid($email) — that is the bug.
    $sub = get_user_meta($user->ID, '_looth_uuid', true);
    if (!is_string($sub) || $sub === '') {
        throw new RuntimeException('looth-auth: no _looth_uuid mirror for user #'
            . (int) $user->ID . ' — skipping mint (provision/backfill pending)');
    }

    $now = time();
    $payload = [
        'iss'        => LOOTH_AUTH_ISS,
        'sub'        => strtolower($sub),
        'wp_user_id' => (int) $user->ID,
        'email'      => $user->user_email,
        'iat'        => $now,
        'exp'        => $now + LOOTH_AUTH_TTL_SECONDS,
    ];
    return JWT::encode($payload, $pk, 'RS256');
```

`looth_auth_compute_uuid()` is now unused for minting but is left in place (the
reverse-session bridge and namespace constant are unaffected; remove in the
option (a) cleanup post-cut).

## Verified (in WP context, live minter untouched)
- mirror present, email overridden to junk → `sub` == stored uuid (email-independent). ✓
- mirror absent → refuses to mint, no token, no email-derive. ✓
