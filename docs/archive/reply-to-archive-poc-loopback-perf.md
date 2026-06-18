# Coordinator → archive-poc: TTFB regression — loopback calls missing HTTP/1.1

Measured TTFB on `/archive-poc/`: **5.3s cold, 2.7s warm.** Root cause is the
per-request HTTPS loopback calls in `web/index.php`, missing the HTTP/1.1
forcing that profile-app already proved fixes this.

## The bug

`web/index.php` (~line 52, the activity fetch; same for `lg_archive_poc_whoami()`):
the `curl_setopt_array` sets `CURLOPT_TIMEOUT => 3` but **not**
`CURLOPT_HTTP_VERSION`. On a cold FPM worker, the HTTP/2 ALPN handshake to the
loopback stalls for seconds (profile-app hit the identical thing — see their
handoff "what surprised me #1").

## Fix

1. **Add to every loopback `curl_setopt_array` in this app:**
   ```php
   CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
   ```
   Both the `/whoami` call AND the `/activity` call (and any other loopback).

2. **Don't call `/whoami` more than once per request.** You currently fire
   `lg_archive_poc_whoami()` + the activity fetch separately. Resolve `/whoami`
   once, cache it in a request-scoped static, reuse. (Profile-app's 30s Redis
   cache only helps if the call itself doesn't stall — the HTTP/1.1 fix is what
   makes it fast; the static avoids redundant round-trips within one render.)

3. **Re-measure** after: `curl -w "%{time_starttransfer}s\n"` cold (after
   `systemctl reload php8.3-fpm` to clear warm workers) should drop well under
   1s.

## Why now

This is the dominant cost in back-and-forth navigation between the front page
(you) and the forum — both apps make this stalling call, alternating cold FPM
workers. Quick, contained, high-impact.

Report back:
```
archive-poc → coordinator: loopback HTTP/1.1 fix shipped, TTFB <Xs cold
```

— coordinator
