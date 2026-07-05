# message-images — nginx additions (a `git pull` does NOT carry these)

The repo `profile-app/nginx-snippet.conf` is the canonical reference but has drifted
behind the LIVE running config `/etc/nginx/snippets/strangler-profile-app.conf`
(which carries the full `/me/messages/*` route set). Apply these THREE edits to the
LIVE snippet on each box, then `nginx -t && systemctl reload nginx`.

## 1. Upload rewrites — inside `location ^~ /profile-api/v0/ { … }`
Place BEFORE the existing `^/profile-api/v0/me/messages/([0-9a-f-]{36})/?$` thread
rewrite (so `<uuid>/image` isn't swallowed by the thread route):

    rewrite "^/profile-api/v0/me/messages/image/?$"                  /profile-api/v0/me-message-image.php last;
    rewrite "^/profile-api/v0/me/messages/([0-9a-f-]{36})/image/?$"  /profile-api/v0/me-message-image.php?uuid=$1 last;

## 2. PHP-exec allowlist — add `me-message-image` to the `/me/*` location regex
The big `location ~ "^/profile-api/v0/(me|…|me-discussion-visibility)\.php$"` block:
add `|me-message-image` to the alternation.

## 3. Access-controlled serve block — add alongside the `/profile-media/` block

    location ^~ /message-media/ {
        if ($loothdev_is_authorized != 1) { return 403; }   # dev cookie gate only; live var is 1
        rewrite ^/message-media/(.*)$ /message-media-auth?path=$1 last;
    }
    location = /message-media-auth {
        internal;
        include fastcgi.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm-profile-app.sock;
        fastcgi_param SCRIPT_FILENAME /srv/profile-app/web/message-media.php;
    }
    location ^~ /message-media-internal/ {
        internal;
        alias /srv/profile-app-message-media/;
    }

## Also (deploy, not nginx)
- `mkdir -p /srv/profile-app-message-media && chown profile-app:profile-app … ; chmod 0775`
  (local fallback store + future resize cache; the R2 path needs no local dir).
- DB migration `profile-app/sql/2026-06-30-message-media.sql` (run as the table owner /
  postgres superuser — `looth-dev` is not the `messages` table owner).
- R2 creds at `/etc/looth/messages-r2` (640 root:profile-app): endpoint / bucket / key /
  secret for the DEDICATED message bucket. Until present, MessageR2::enabled() is false
  and uploads use the local fallback store (access control is identical either way).
