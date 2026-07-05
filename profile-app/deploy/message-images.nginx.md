# message-images — nginx additions

**Since the migration-capture lane, nginx IS repo-tracked**: the live snippet
`/etc/nginx/snippets/strangler-profile-app.conf` is a symlink to
`platform/nginx/strangler-profile-app.conf` in the clean checkout. This branch
commits the additions there directly — so on dev2/live a plain
`git pull` in the clean checkout DOES carry them; just `nginx -t && systemctl reload nginx`.

(`profile-app/nginx-snippet.conf` is the older reference copy; it carries the same
additions for parity but the platform/nginx file is canonical.)

The three additions, for review / for any box where the snippet is NOT repo-linked:

## 1. Upload rewrites — inside `location ^~ /profile-api/v0/ { … }`
Place BEFORE the `^/profile-api/v0/me/messages/…` thread rewrites (so `<uuid>/image`
isn't swallowed by the thread route):

    rewrite "^/profile-api/v0/me/messages/image/?$"                  /profile-api/v0/me-message-image.php last;
    rewrite "^/profile-api/v0/me/messages/([0-9a-f-]{36})/image/?$"  /profile-api/v0/me-message-image.php?uuid=$1 last;

## 2. PHP-exec allowlist
Add `|me-message-image` to the big `location ~ "^/profile-api/v0/(me|…)\.php$"` alternation.

## 3. Access-controlled serve block — alongside the `/profile-media/` block

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

## Also (deploy, not nginx) — or just run deploy/provision-message-media.sh
- `mkdir -p /srv/profile-app-message-media; chown profile-app:profile-app; chmod 2775`
  (local fallback store; the R2 path needs no local dir).
- DB migration `profile-app/sql/2026-06-30-message-media.sql` (run as postgres — idempotent).
- R2 creds at `/etc/looth/messages-r2` (640 root:profile-app): endpoint / bucket / key /
  secret for the DEDICATED message bucket. Until present, MessageR2::enabled() is false
  and uploads use the local fallback store (access control is identical either way).
