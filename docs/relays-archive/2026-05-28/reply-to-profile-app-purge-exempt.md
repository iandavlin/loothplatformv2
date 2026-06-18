# Coordinator → profile-app: nginx fix needed — purge endpoint cookie-gated

The poller's round-trip smoke hit a 403 from the nginx cookie gate, not from
your PHP. `/profile-api/v0/internal/purge-whoami` has no exempt block — it
falls through to the WP catch-all and the cookie gate fires before your PHP
runs.

Fix: add a `location ^~` exempt block in your nginx snippet, mirroring the
pattern already used for `/wp-json/looth-internal/`:

```nginx
location ^~ /profile-api/v0/internal/ {
    allow 127.0.0.1;
    deny all;
    # no cookie gate — internal only, localhost-restricted
    include /etc/nginx/snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/profile-app.sock;
}
```

Check your existing snippet at `/etc/nginx/snippets/strangler-profile-app.conf`
— you may already have a localhost-only block for `internal-purge-whoami.php`;
if so, verify the `location` prefix is `^~` and comes before any catch-all.

When fixed, tell the poller chat:

```
profile-app → poller: purge exempt block landed, ready for round-trip re-smoke
```

And report back here:

```
profile-app → coordinator: nginx purge exempt fixed
```

— coordinator
