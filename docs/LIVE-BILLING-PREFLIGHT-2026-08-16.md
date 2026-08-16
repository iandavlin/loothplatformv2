# Live billing preflight — the /billing/ alias, and what to run

**Measured on the live box 2026-08-16, read-only.** Live carries the same
one-character nginx bug dev2 had.

## The finding

| Probe (live loopback, `Host: loothgroup.com`) | Answer |
|---|---|
| `/billing/` | **403** text/html |
| `/billing/v1/products` | **404** text/html |
| `/billing/index.php` | **404** text/html |

That is the exact fingerprint: the directory exists (403) and nothing beneath it
resolves (404). Live's conf line 181 reads

```
location ^~ /billing/ {          # ends in a slash
    alias /srv/lg-stripe-billing/public;   # does NOT
```

nginx appends the remainder after the location prefix to the alias VALUE, so
`/billing/v1/products` resolves to `…/publicv1/products`, and the
`@billing_rewrite` fallback hands PHP-FPM a `SCRIPT_FILENAME` of
`…/publicindex.php`. No such file → every route 404s and the plans XHR receives
HTML, hence "Failed to load memberships: Unexpected token <".

**The app itself is healthy on live**: `.env`, `public/index.php`,
`vendor/autoload.php` all present, billing FPM socket up. Only the routing is
wrong.

**Why this is pre-launch critical:** switch the Stripe join page on live today
and every member sees that error on page one.

## ⚠️ The fix is a DEPLOY, not an edit

`/etc/nginx/sites-enabled/dev2.loothgroup.com.conf` on live is a **symlink into
the serving checkout** — it *is* `platform/nginx/dev2.loothgroup.com.conf`.
Hand-editing it dirties the pull-only tree, and **`lg-deploy` fatals on a dirty
checkout**, so the next deploy would refuse until someone unpicked it.

`origin/main` already carries the fix. Live is at `4d8fdd6`, **54 commits
behind**. Both couplings a pull does not handle were checked in that range:

- **mu-plugins added/removed: none** → no symlink work
- **new webroot files: none** → `install-symlinks --new-only` is a no-op

So a plain `lg-deploy` is sufficient — it pulls, sees `platform/nginx/` changed,
and reloads nginx itself.

## Paste block for Ian (all live writes are his)

```bash
# 1. Deploy. Refuses if the tree is dirty or off main — that is the guard, not a problem.
lg-deploy

# 2. The line should now END IN A SLASH:
grep -n 'alias /srv/lg-stripe-billing/public' /etc/nginx/sites-enabled/dev2.loothgroup.com.conf

# 3. THE PROOF — this must be 200 application/json, not 404 text/html:
curl -sk -H 'Host: loothgroup.com' -o /dev/null -w '%{http_code} %{content_type}\n' \
  https://127.0.0.1/billing/v1/products
```

**Rollback:** `lg-deploy` only fast-forwards, so rollback is
`git -C /home/ubuntu/loothplatformv2-clean reset --hard 4d8fdd6 && sudo nginx -t
&& sudo systemctl reload nginx`. It has not been needed on dev2.

**What else rides along:** those 54 commits carry other lanes' merged work (46
files — archive-poc, bb-mirror and others). That is the normal deploy shape, but
it is a deploy of everything merged, not of one character, and that is Ian's
call to make knowingly.
