# Coordinator → profile-app: WP-session auth bridge needed in /whoami shim

## What's missing

archive-poc wired up the /whoami switchover and found: WP-logged-in users
without a `looth_id` JWT cookie get `authenticated: false` from profile-app.
The WP shim currently forwards caller cookies verbatim, but profile-app only
authenticates via `looth_id` RS256 JWT. WP session → profile-app identity
path is not connected.

Your slice 3.5 build order listed "Auth::whoami(WP|JWT)" — the WP path
wasn't completed.

## What to build

Update the WP shim (`profile-whoami-shim.mu-plugin.php`) to:

1. Check `get_current_user_id()` — if a WP user is logged in, pass their
   `wp_user_id` to profile-app via a trusted header instead of forwarding
   cookies.
2. Profile-app's `/profile-api/v0/whoami` endpoint already has
   `buildForWpUserId()` — expose a code path that reads a trusted
   `X-LG-WP-User-Id` header when the request also carries a valid
   `X-LG-Internal-Auth` shared-secret header.

Pattern:

```php
// In profile-whoami-shim.mu-plugin.php:
$wp_user_id = get_current_user_id();
$headers = [ 'Host: dev.loothgroup.com' ];
if ( $wp_user_id ) {
    $headers[] = 'X-LG-WP-User-Id: ' . $wp_user_id;
    $headers[] = 'X-LG-Internal-Auth: ' . LG_INTERNAL_SECRET;
} else {
    // anon — forward as before (no extra headers)
}
// ... curl to profile-app with CURLOPT_HTTP_VERSION_1_1 + 5s timeout
```

```php
// In api/v0/whoami.php (or Auth/Whoami.php):
// If X-LG-Internal-Auth is valid AND X-LG-WP-User-Id is present:
//   → call buildForWpUserId($wpUserId)
// Else if looth_id JWT present:
//   → existing JWT path
// Else:
//   → anon response
```

The shared-secret check (`hash_equals()`) guards the trusted-header path.
The endpoint must reject `X-LG-WP-User-Id` without a valid secret to prevent
spoofing.

## Scope

This is a slice 3.5 extension — same session. It's small (~20 lines across
shim + endpoint). archive-poc is blocked on it for the step 2 switchover.

## When done

Report back:

```
profile-app → coordinator: WP-session auth bridge live
```

And notify archive-poc directly:

```
profile-app → archive-poc: looth_id bridge live — WP-logged-in users now
get authenticated: true from /whoami. Re-test your gating flow.
```

Path:
```
/home/ubuntu/projects/profile-app/SESSION-HANDOFF.md
```

— coordinator
