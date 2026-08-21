# 162 — Auto-ban: the stuffing detector feeds a webserver blocklist

> Plan only. No code written. Lane `162-auto-ban`, issue #162 (`approved`, `infra`).
> Awaiting Ian's approval per LANE-RULES' plan-first gate.

## 1. What I'm solving

Ian, 8/20: *"can we set up an auto block list when that email fires? It seems to
know the IP, can we just add it to a file of known offenders and nip it at our
webserver?"* — then narrowed the same day: *"this should only block ips that try
several different logins in one block."*

So: the credential-stuffing alert that lg-login-monitor already sends becomes the
one and only trigger. The same IP, at the same moment, goes into a file. nginx
stops that address at **the login door only**, for **24 hours**, and Ian gets a
wp-admin page where he can undo a mistake in one click.

Nothing else bans anyone. A single account hammered fifty times from one address
is not this feature's business — that already gets its own per-member alert.

## 2. What I found on the box (this is what changes the design)

### 2a. There is no real-IP restoration on either box. `$remote_addr` is Cloudflare.

`grep -rn real_ip /etc/nginx/` returns **nothing**, and the live access log
confirms it — the last five requests to dev2 came from `104.23.190.196` and
`162.158.159.108`, which are Cloudflare's own ranges, not visitors.

That makes the obvious implementation catastrophic. A deny list keyed on
`$remote_addr` would, the first time it fired, ban a Cloudflare edge node — and
because every visitor arrives through those same nodes, the ban would take the
**whole site** off the internet for everyone behind that edge, not the attacker.

Enforcement therefore keys on `CF-Connecting-IP`, and only trusts it when the
connection itself arrived from a Cloudflare range (a `geo` block). I am **not**
adding `set_real_ip_from` to fix this globally: that rewrites `$remote_addr` for
access logs, the `limit_req` zone on `/thumb/`, and the `allow 127.0.0.1` lock on
`/wp-json/looth-internal/`. Three unrelated systems, one line, no way to test the
blast radius before it lands. Out of scope, and worth its own issue.

### 2b. The monitor trusts `CF-Connecting-IP` unconditionally — harmless for email, a poisoning vector for a ban list.

`lg_login_monitor_client_ip()` returns the `CF-Connecting-IP` header whenever it
parses as an IP, without checking who sent it. The origin is world-knockable on
443 (box CLAUDE.md), so anyone can connect straight to it, set that header to any
address they like, fail five logins against five real member logins, and put
**an address of their choosing** on our blocklist. Ian's own home IP, for
instance.

For an email alert that is a cosmetic lie. For a blocklist it is the attack.

So the ban path computes its own address and never reuses the monitor's:

- connection from a Cloudflare range → trust `CF-Connecting-IP`
- connection from anywhere else → use `$remote_addr`, the actual peer, which
  cannot be forged

I am not changing `lg_login_monitor_client_ip()` itself — it feeds the existing
alert dedup keys and the alert bodies, and changing it changes email nobody asked
me to change.

### 2c. The detector watches one password door. There is a second one.

`wp_login_failed` fires for `wp-login.php`. The membership app's
`/wp-json/lg-member-sync/v1/auth` (and `/gift-auth`) checks passwords with
`wp_check_password()` directly, so it fires no hook and the detector has never
seen it. It has its own throttle already: 6 failures per email per 15 min, 21 per
IP per hour, both 429.

I propose to **enforce** the ban on that door too (a banned address should not
walk to the next password door), while leaving detection exactly where Ian ruled
it. It is four extra lines in the same snippet. Say the word if you'd rather I
leave it alone — it is the one genuinely optional piece here.

### 2d. Smaller things worth knowing

- dev2's serving vhost `/etc/nginx/sites-enabled/dev2.loothgroup.com.conf` is a
  **hand-edited copy**, not a symlink into the checkout, and it has already
  drifted from the repo (the lane-preview include sits in a different place).
  So on dev2 a vhost change needs a `sudo cp`; on live the vhost *is* the tracked
  file and rides the pull. Both then need a reload — a pulled conf is not live
  until reload.
- mu-plugins are 42 individual symlinks. A **new** mu-plugin needs its `ln -s`
  in the same window as the pull or it simply does not load.
- `/srv/lg-shared` → the repo's `lg-shared/`, and `location ^~ /lg-error/`
  already serves `lg-shared/errors/*.html` as `internal`. The polite page can
  live there and arrive by pull, with no PHP and no WordPress in its path — it
  renders even when a pool is down.

## 3. The design

Five pieces. Each one fails closed.

```
  wp-login.php failure
        │
        ▼
  lg-login-monitor  ── 5 distinct real accounts / 15 min from one IP ──┐
  (unchanged thresholds; fires its existing stuffing email)            │
        │                                                              │
        │  do_action('lg_login_stuffing_detected', …)   ← new, decoupled
        ▼                                                              │
  lg-auto-ban (new mu-plugin)                                          │
   · flag OFF → returns, nothing written                               │
   · computes the CF-vouched address itself                            │
   · appends {ip, when, accounts, span, expires} to                    │
     /var/lib/lg-auto-ban/state.json  (atomic, flocked)                │
        │                                                              │
        ▼   (systemd .path notices the write; .timer also runs /5 min) │
  lg-auto-ban-render.py  (root)                                        │
   · drops expired, allowlisted, private, loopback, CF-owned, malformed│
   · caps the list, normalises v4/v6                                   │
   · writes /etc/nginx/snippets/lg-auto-ban-list.conf                  │
   · nginx -t, then reload — restores the previous file if the test    │
     fails, and records the outcome where the dash can read it         │
        │                                                              │
        ▼
  nginx: location = /wp-login.php  →  banned && it's the password door
                                      →  462 → polite page, 403 status
```

**The store.** `/var/lib/lg-auto-ban/state.json`, owned by the WP pool user,
holding `bans[]` and `allowlist[]`. WordPress is the only writer; root only
reads. Every write is tmp-file + `rename()` under an flock, so the renderer can
never read a half-written file.

**The renderer is the privilege boundary, and it assumes the store is hostile.**
Even if the JSON were fully attacker-controlled it can only ever produce lines of
the form `"<validated public IP>" 1;` — every entry goes through Python's
`ipaddress`, and anything private, loopback, link-local, multicast, unspecified,
inside a Cloudflare range, or simply unparseable is dropped and logged. The list
is capped (default 500, oldest first out) so it cannot grow into a config nginx
chokes on.

**Cloudflare's ranges are one tracked file** (`tools/infra/cloudflare-ranges.txt`,
dated, fetched from cloudflare.com/ips-v4 + ips-v6). The installer generates the
nginx `geo` block from it, the renderer reads it, the mu-plugin reads it. Three
consumers, one source — otherwise they drift and the trust boundary quietly
stops matching itself.

**What nginx blocks, precisely.** Only when the address is banned *and* the
request is a password attempt:

- `POST` or `GET` `/wp-login.php` with no `action`, or `action=login`
- (proposed, §2c) `/wp-json/lg-member-sync/v1/auth` and `/gift-auth`

**Everything else on that endpoint stays open on purpose** — `action=logout`,
`lostpassword`, `rp`, `resetpass`, `register`. A caught innocent must be able to
log out and to reset their password; and a stuffer gains nothing from a reset
link sent to an inbox they don't own. Reading the site, existing sessions, and
"Log in with Patreon" are untouched in every case, which is the whole reason the
ban is door-scoped and not site-wide.

**The polite page** (`lg-shared/errors/login-blocked.html`) says what happened,
that it lifts by itself within a day, that Patreon sign-in still works right now,
and gives a contact path. Branded like the existing 403/404, static, no PHP.

**The dash** — wp-admin, "Login bans", `manage_options`:

- a banner stating whether enforcement is actually **armed on this box**, read
  from the renderer's own status file: when it last rebuilt, how many addresses
  are live, whether the reload succeeded. Presence of a feature is not
  reachability of it, and Ian should not have to ask keeper which one this is.
- one row per current ban, in plain English —
  *"blocked yesterday at 7:50am after trying 5 members' passwords in 4 seconds ·
  lifts in 21 hours"* — never a bare IP and a timestamp.
- **Remove** per row (unban now) and **Never ban this address** (promote to the
  permanent allowlist and unban). Both `admin_post_` handlers, both nonce-checked
  and capability-checked, both redirect-after-POST.
- recent login events underneath, from the ring buffer `lg-login-monitor` has
  been keeping for exactly this since day one.

**Two independent keys, and that is deliberate:**

| | what it does | repo default |
|---|---|---|
| `platform/config/auto-ban.php` `enabled` | whether WordPress writes to the ban file at all | **false** |
| the box-local nginx snippet existing | whether anything is actually blocked | **absent** |

So the merge changes nothing anywhere. Flipping the flag on dev2 fills the file
while still blocking nobody — that is the state Ian asked for, *"until Ian has
seen the ban file behave on dev2."* Only installing the snippet arms enforcement,
and live is protected by absence exactly like the other flags.

## 4. Files I expect to touch

Guessing wider rather than narrower, per LANE-RULES.

**New**
- `platform/mu-plugins/lg-auto-ban.php` — store, vouched-IP, flag, dash
- `platform/config/auto-ban.php` — the flag (`enabled`, `ban_seconds`, `max_entries`)
- `platform/nginx/lg-auto-ban.conf.template` — geo + maps + the two locations
- `tools/infra/lg-auto-ban-render.py` — the root renderer
- `tools/infra/install-auto-ban.sh` — installer/flip kit + rollback lines
- `tools/infra/cloudflare-ranges.txt` — dated snapshot, single source
- `platform/systemd/lg-auto-ban.path` / `.service` / `.timer`
- `lg-shared/errors/login-blocked.html` — the polite page
- `tools/gates/auto-ban-gate.py` — gate **84**
- `tools/gates/auto-ban-redfirst.sh` — the mutation proof

**Edited**
- `platform/mu-plugins/lg-login-monitor.php` — one `do_action` at the alert
  moment, nothing else
- `platform/nginx/dev2.loothgroup.com.conf` — **one glob include line**,
  `include /etc/nginx/snippets/lg-auto-ban-*.conf;`. A glob so a box without the
  snippet matches nothing and behaves exactly as today. ⚠️ Shared with live —
  this file *is* live's serving vhost.
- `tools/gates/run-all.sh` — register gate 84 ⚠️ shared: `166-meta-leak` is
  minting **83** and `emoji-picker-build` also touches this file
- `docs/CRAFT-STANDARD.md` — the gate-84 row ⚠️ shared with `166-meta-leak`
- `docs/FLAGS.md` — the flag row (the flag-register gate requires it)
- `docs/domains/INFRA.md` — same commit, per the domain rule
- `handoffs/plans/162-auto-ban-PLAN.md` — this file

**Deliberately not touched:** `lg_login_monitor_client_ip()`, the alert bodies,
the thresholds, `/etc/nginx/**` (installer's job, run by a human with sudo),
anything under the serving checkout.

## 5. Gate 84 — what it will prove

Red-first, on file snapshots, never `git checkout --`. No network, no browser, no
real seat, no root: the store, the rendered output and the config all redirect
into a per-run temp dir, and the Cloudflare ranges come from a fixture. The
mu-plugin legs run the **real** `lg_login_monitor_wp_failed()` under a PHP
harness that stubs WordPress, so it is the shipped code being measured.

1. **A stuffing burst bans.** 5 distinct real accounts from one IP → that address
   is in the store, and the renderer puts it in the conf.
2. **A single-account hammer does not.** 50 failures, one account, same IP →
   store empty. Paired with leg 1 as its liveness control: same harness, one
   condition apart, so "nothing was banned" can't be true because the harness is
   broken.
3. **Expiry works.** A 25-hour-old ban is absent from the render, a 1-hour-old one
   is present, in the same fixture.
4. **The allowlist is immune** — an allowlisted address never renders even while
   it is in `bans[]`; and private, loopback, Cloudflare-owned and malformed
   entries are each dropped, each with its own named assertion.
5. **Spoofing does not poison it.** A forged `CF-Connecting-IP` on a
   non-Cloudflare connection bans the *peer*, never the forged address.
6. **The dash Remove works** — the real handler, nonce enforced (a wrong nonce
   changes nothing), row gone afterwards; and **Never ban** moves the address to
   the allowlist and drops it from the render.
7. **All three flag states, read from the flag, never hardcoded** — absent / OFF /
   ON. OFF writes no file at all *and* leaves the stuffing alert byte-identical.
8. **The door is the only thing blocked.** Against the generated conf: the login
   POST matches; `action=logout`, `lostpassword`, `rp`, `resetpass` and every
   non-login URL do not.
9. **No undefined variable can reach a box without the snippet** — the tracked
   vhost names `$lg_ab_*` nowhere outside the glob-included snippet, so a pull
   onto a box that never ran the installer cannot make `nginx -t` fail.
10. **The cap holds** — 5,000 entries in, at most `max_entries` out.

## 6. Deploying it (what a pull does *not* do)

Written up as a flip kit in the installer, in order, with the rollback line for
each step, and with the hostname guard on every command — a dev2 pull was run on
live on 8/18.

1. `git pull` — brings the mu-plugin, the flag, the polite page, the templates.
2. `ln -s` the new mu-plugin into the docroot **in the same window** — 42
   individual symlinks, and a pull creates none of them.
3. dev2 only: `sudo cp` the vhost (dev2's is a drifted copy, not a symlink),
   `nginx -t`, reload.
4. `sudo tools/infra/install-auto-ban.sh` — creates `/var/lib/lg-auto-ban`,
   installs and enables the three units, renders an empty list, arms the snippet.
   Units are **copies** in `/etc/systemd/system`, so this is `cp` +
   `daemon-reload`, never a pull.
5. Flip `enabled` on dev2 via a `.local.php` (not an FPM `env[]` — dev2's pool
   files symlink into the serving checkout and an env flip dirties tracked files
   there, which can block a later `pull --ff-only`).
6. Ian watches the file fill. Then, and only then, arm enforcement.

Live is Ian's, always.

## 7. Risks I am carrying, stated up front

- **The vhost line touches live's serving config.** It is one glob include and it
  matches nothing on a box without the snippet — but it *is* the file live
  serves, and it needs a reload there. Gate leg 9 exists specifically so this
  line can never break `nginx -t` on a box that has none of the rest.
- **A ban is only as good as the address behind it.** Residential-proxy botnets,
  carrier NAT and shared VPN exits all mean the offender IP is sometimes a real
  household — which is exactly why this is door-scoped, 24-hour, undoable in one
  click, and why the ceiling on harm is "password login from one network needs a
  day or a different network."
- **dev2 cannot prove enforcement end-to-end from a synthetic request**, because
  every request to dev2 arrives through the same Cloudflare edge and the dev gate
  sits in front of everything. The gate proves the generated config; the *live*
  proof is Ian's own browser, and that is the honest limit.
- **Known gap, stated rather than papered over:** `?rest_route=` is an alternate
  spelling of a REST path and would not match an exact `location`. It is not a
  bypass of the wp-login door, only of the optional §2c one, which keeps its own
  429 throttle either way.

## 8. The one thing I'd like a call on

**§2c — do I also stop a banned address at the membership app's password
endpoint** (`/wp-json/lg-member-sync/v1/auth`), or leave that door to its
existing 429 throttle?

I recommend including it: it is four lines, it is the same class of door, and a
blocklist with a second unlocked door beside it is a blocklist that reads as
working. Everything else in this plan is already ruled on the issue and I'll
build it as written.
