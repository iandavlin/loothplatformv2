# Audit: LG_ENV used as a SAFETY key instead of a LAYOUT key

**Lane:** mail-safety (backlog #15) · **Date:** 2026-07-31 · **Fix commit:** `928bc36`

## The rule this audit enforces

Verified against `live-ro` on 2026-07-31 — `/etc/looth/env` on the two boxes:

| key | dev2 | live | discriminates? |
|---|---|---|---|
| `LG_ENV` | `dev2` | **`dev2`** | **NO** |
| `LG_PUBLIC_HOST` | `dev2.loothgroup.com` | `loothgroup.com` | **YES** |
| `LG_WP_PATH` | `/var/www/dev` | `/var/www/dev` | NO |
| `LG_WP_USER` | `looth-dev` | `looth-dev` | NO |
| `LG_MYSQL_DB` | `looth_import` | `looth_import` | NO |
| `LG_MYSQL_BILLING_DB` | `lg_membership` | `lg_membership` | NO |
| `LG_PG_DB` / `LG_PG_DB_PROFILE` | `looth` / `profile_app` | same | NO |
| `LG_GATE_COOKIE` | `loothdev_auth` | `loothdev_auth` | NO |

> **`LG_PUBLIC_HOST` is the only key that differs between dev2 and live.**
> Everything else — env string, paths, users, DB names, gate cookie — is a
> LAYOUT fact and is byte-identical on both boxes. Any code asking "is it safe
> to do the dangerous thing here?" must ask the host, and nothing else.

`lg-shared/lg-env.php:29-31` says the cut would flip *two* values
(`dev2`→`live` **and** the host). In reality **only the host was flipped**, and
`bb-mirror/config.php:36-44` documents that as deliberate — the prod box keeps
the dev layout on purpose. So `LG_ENV=live` is never coming, and `LG_ENV` is
permanently a layout key. That is the root of this whole defect class.

---

## Instance 1 — FIXED: mail containment swallowed live mail

`platform/mu-plugins/lg-dev-mail-containment.php:31` (pre-fix)

```php
$isDev = in_array($env, ['dev', 'dev2', 'development', 'staging'], true);
if (!$isDev) { return; }          // <- passes on LIVE, which is LG_ENV=dev2
```

Live satisfies this test. The plugin then short-circuits `pre_wp_mail` and
**returns `true`** — the caller records a successful send. Live has no mailpit,
so the message is dropped. Silent, total, and invisible to any send-side check.

**Second vector in the same file, not in the original report:** line 35 set
`FLUENTMAIL_SIMULATE_EMAILS = true` off that same test. On live that alone makes
FluentSMTP simulate *every* send site-wide — a full mail outage even if the
`pre_wp_mail` filter were deleted. The fix moves it below the host check.

**Fix:** containment now requires an affirmative dev-host match, with
`LG_PUBLIC_HOST` (root-owned) authoritative and the request header consulted only
when the box declares no host — otherwise a forged `Host: loothgroup.com` could
switch dev containment *off* and let dev2 mail real members. Host
undeterminable ⇒ **do not contain**: swallowing real mail while reporting success
is an invisible outage; a dev box sending for real is loud and recoverable.

**Currently dormant on live** only because the file is absent from live's 35
curated mu-plugin symlinks — an accident of which symlinks exist, not a safety
property, on a platform that adds mu-plugin symlinks routinely.

---

## Instance 2 — LATENT, and it fails the *other* way

`bb-mirror/config.php:26-32` · `events/config.php:29-35` · `archive-poc/config.php:24-33`

```php
$env = $shared['env'] ?? getenv('LG_..._ENV');
if (!$env) {
    $host = $_SERVER['HTTP_HOST'] ?? gethostname();
    $env = ( str_starts_with($host,'dev.') || str_contains($host,'ip-172-31-81-87')
          || str_contains($host,'claude.loothgroup') ) ? 'dev' : 'live';
}
```

The dev patterns are **dev1's**. dev2 matches none of them:
`dev2.loothgroup.com` does not start with `dev.`, and dev2's internal name is
`ip-172-31-78-94`, not `ip-172-31-81-87`. So if `/etc/looth/env` is ever absent
or unreadable, **dev2 identifies itself as `live`** — and the fallback's default
side is `live`, the dangerous one.

Consequence on dev2 (`archive-poc/config.php:45-55`): `wp_path=/var/www/html`,
`wp_user=looth-live`, and `gate_cookie=''` — **the cookie gate turns off**. That
is the same exposure shape as the already-recorded `/archive-api/v0/*.php`
source-disclosure trap.

Dormant today because both boxes carry `/etc/looth/env` — the identical kind of
"safe by accident" that Instance 1 was. Not fixed here: it spans three strangler
apps outside this lane's scope. Recommend one shared `lg_is_live_host()` helper
in `lg-shared/lg-env.php` that all four call sites use.

---

## Instance 3 — the documented model of live is wrong

`archive-poc/config.php:48` — `$ap_def_gate_cookie = ''; // live has no cookie gate`
and `lg-shared/lg-env.php:77` — *"present-but-empty is meaningful (the prod box runs gate-free)"*.

Live's actual `/etc/looth/env` sets `LG_GATE_COOKIE=loothdev_auth`, **non-empty**.
`$shared` wins over the default, so no live behaviour is currently wrong — but
anyone reasoning about live from these comments will get it backwards. Worth
Ian confirming whether live is *meant* to carry a gate cookie.

---

## Cleared — these use `LG_ENV` correctly (layout only)

| site | why it's fine |
|---|---|
| `bb-mirror/config.php:25,34,36-44` | selects filesystem/user; explicitly documents the host decoupling |
| `profile-app/config.php:83-99` | per-env path/DB defaults; shared env wins; comments the exact trap |
| `membership-pages/config.php:46` | layout defaults only |
| `lg-shared/lg-env.php:58` | the provider that parses the file |
| `platform/bin/live-one-pull.sh:33,40` | read once, printed in a log line — never branched on |
| `profile-app/bin/visibility-matrix.php:57,59,71` | `$LG_ENV` is a local from `lg_gate_env()`; keys off `LG_GATE_HOST` |
| `tools/gates/gate-env.sh:47,62,64` | already keys off `LG_PUBLIC_HOST` — the correct key |
| `tools/gates/run-all.sh:82,85` | comments |
| `tools/dev2/dev2-drift-check.sh:283,320,323` | a *detector* for this class, not an instance |

## Cleared — the other mail gates already use the right pattern

Both gate on a **runtime option**, not the environment, and both fail closed.
This is the shape to copy:

- `platform/mu-plugins/lg-poller-mail-killswitch.php:26` — `get_option('lgms_poller_mail_enabled', false)`
- `lg-patreon-stripe-poller/src/Plugin.php:281-291` — same option, plus an
  `X-LG-Poller-Intent` allow-list so intentional notices always send
- `lg-weekly-digest/` — **no environment predicate anywhere**; uses an explicit
  `$dry_run` argument (`class-lg-wd-sender.php:298,327`) and settings options

---

## Proof

`tools/mail-safety/run-liveness-test.sh` runs the **real** plugin as the **real**
pool user (`looth-dev`), with **live's real `/etc/looth/env`** bind-mounted at the
real path inside a private mount namespace. No PHP-level faking of the
environment; live is never contacted; nothing on dev2 is modified.

It asserts on whether the plugin *claims the send* — never on "`wp_mail()`
returned `true`", which is the false positive documented at
`lg-weekly-digest/dev/build-inbox-test.php:9-10`.

```
before:  live-sim(web) CONTAIN   live-sim(cli) CONTAIN   dev2-control CONTAIN
         => defect reproduced; the harness can see it

after:   live-sim(web)  PROCEED    dev2-control  CONTAIN
         live-sim(cli)  PROCEED    dev2-cli      CONTAIN
         live-www       PROCEED    spoofed-host  CONTAIN
         no-host-failsafe PROCEED
         => 7 passed, 0 failed
```

Real-box check (no namespace, dev2's own env): `CONTAIN`, still delivering to
mailpit — dev2 behaviour unchanged.

**Not flagged.** The change only ever *removes* containment, is a proven no-op on
dev2, and a flag defaulted OFF would mean shipping the defect.
