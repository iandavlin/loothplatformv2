# INFRA — boxes, deploy, front door

## The map
dev2 = ip-172-31-78-94 (dev + serving checkout ~/loothplatformv2-clean, pulls
main only). live = ip-172-31-67-175, deploys via `lg-deploy` (pull + conf
test/reload + symlink install). Both Cloudflare-proxied on 443: web = cookie-
gated + world-knockable; SG IP-lock is real only for non-web ports (ssh,
webmin:10000). Webroot + mu-plugins are per-file symlinks — new files need
install-symlinks; A PULLED NGINX CONF IS NOT DEPLOYED UNTIL RELOAD.
**NEITHER BOX RESTORES REAL CLIENT IPs.** There is no `set_real_ip_from`
anywhere in `/etc/nginx` (verified 8/20), so `$remote_addr` in every conf, log
line and `limit_req` zone is a CLOUDFLARE EDGE NODE, not a visitor. Anything
that acts on a client address must read `CF-Connecting-IP` — and must believe it
only when `$remote_addr` is itself in a Cloudflare range, because the origin is
world-knockable and the header is otherwise forgeable. Adding `set_real_ip_from`
globally would silently re-point three unrelated things (access logs, the
`/thumb/` rate limit, the `allow 127.0.0.1` lock on `/wp-json/looth-internal/`)
and is its own issue, not a side effect.

## Paid-for traps
- Every command handed to Ian carries a hostname guard
  (`[ "$(hostname)" = "ip-…" ] && … || echo WRONG BOX`) — he ran a dev2 pull
  on live 8/18.
- `location = /` (strangler-archive-poc.conf) owns the bare root: any
  root-query URL a system depends on needs an explicit escape (see EMAIL).
- webroot/*.php symlink onto LIVE too — a dev-facing endpoint ships to the
  member site unless exempted (standing tidy-up: #134-adjacent note).
- Known cosmetic WARNs on lg-deploy: lg-wp-cron unit drift (pre-8/11),
  duplicate MIME line.
- **A `}` inside a `${VAR:-default}` CLOSES THE EXPANSION.** Written
  `"${X:-tmux list-sessions -F #{session_name}}"`, the format string's own brace
  ends the expansion and the leftover `}` is appended as a separate word. tmux
  errors, the list is empty forever, and every guard that reads it silently
  passes. Found in #138 phase B before it shipped; assign the default on its own
  line. The class: a default that is wrong while the OVERRIDE still works is
  invisible to any test that only drives the overrides.
- **The systemd units in `platform/systemd/` are COPIES in `/etc`, not
  symlinks** (root-owned, verified 8/20). A pull does NOT deploy a unit change —
  it needs `sudo cp` + `daemon-reload`, and that belongs in the flip kit, never
  in a merge assumption.
- **dev2's serving vhost is a hand-edited COPY, not a symlink** (verified 8/20):
  `/etc/nginx/sites-enabled/dev2.loothgroup.com.conf` is a plain file owned by
  `ubuntu` and has already drifted from `platform/nginx/` (the lane-preview
  include sits in a different place). So a tracked vhost change rides the pull on
  LIVE and does NOT on dev2 — dev2 needs `sudo cp` + `nginx -t` + reload. A lane
  that edits the tracked file and then tests dev2 is testing the old copy.
- **A `map` key file must not live where a server-context glob will find it.**
  `include /etc/nginx/snippets/lg-auto-ban-*.conf` in the vhost would swallow a
  generated `lg-auto-ban-list.conf` sitting in `snippets/` and parse map entries
  as directives. Generated list lives in `/etc/nginx/lg-auto-ban/` for that
  reason alone.
- **A tracked conf that names a variable only a box-local file defines is a
  live-outage waiting on a pull.** `geo`/`map` are http-context and `location` is
  not, so a feature like this is two files; if the tracked vhost referenced
  `$lg_ab_block` directly, a box that never ran the installer would fail
  `nginx -t` on the next pull. The vhost carries only a GLOB include and names no
  such variable — gate 84 §F2 asserts it, comments stripped first so prose about
  the variable is allowed and directives are not.

## Issue history
#132/ledger-47 (unreachable projects remote — dated Wed 8/20) · #138 watcher
phase A live, phase B built 8/20 (below) · #141 rider batching.

## The approved-watcher — approval is the start button (#138)
`tools/approved-watcher.sh`, every 5 min via `approved-watcher.timer`, dev2 only.

**Phase A (live since 8/19):** an open issue newly wearing `approved` rings
`/tmp/claude-ian-action` and posts one board line. State: `~/.approved-acks`.

**Phase B (8/20, Ian: "If a lane goes Idle waiting for me to make a decision, I
want the next work in line to start up while I'm screwing around"):** it spins
the lane itself — **PRE-STAGED WORK ONLY**, through `spin-lane.sh`, which stays
the one door. Approval alone still only rings.

Four holds, checked once per tick: **keeper-quiet** (`/tmp/keeper-quiet` for the
session, `~/.keeper-quiet` to survive a reboot) · **fleet-down** (manifest
non-empty, zero lane sessions — the reboot signature; `respawn-fleet` is
deliberately manual and the watcher must not become the thing that relights the
box) · **1-min load over 4** · **the WORKING cap**, counted by shelling
`lanes-status.sh --json` so the detector is never forked.

Five per-issue guards: a charter at `~/lane-prompts/<n>-*.md` is REQUIRED and
**names the seat** (nothing is slugified or generated) · **one spin per issue
ever**, tracked in `~/.autospin-log` *and* backstopped by a branch-exists check
— that backstop is what stops nine parked branches from all re-spinning the
moment it goes live · an existing tmux session means it is already running · a
worktree must be on its own branch, and a cut one is pushed `-u` so its upstream
IS `origin/<lane>` (75a0fb6).

**Dry-run is the default.** `~/.autospin-mode` must contain `live` to arm — a
file, not a code edit. A dry run announces once per issue and consumes nothing,
so the first real spin still happens after the flip. On a live spin the log line
is written BEFORE tmux is touched: a crash costs one lost spin, never a loop.
Holds and refusals are edge-triggered — announced when the reason changes, not
every five minutes.

Gate 82 (`approved-autospin-gate.py`) asserts all of it with no network, no real
seat and no claude process; every absence assertion is paired with a liveness
control.


## Login-door defense (verified in action 8/20)
Cloudflare carries a RATE LIMIT on the login door (Ian confirmed 8/20; the
box's cf-api-token is R2-read-only so keepers cannot inspect the rule — ask
Ian for thresholds). Signature of it WORKING: a burst of ~5 failed logins in
one second, then silence — the edge truncated the volley; WP saw only the
head. lg-login-monitor then sends ONE stuffing summary (5 distinct accounts /
15 min threshold, per-account alerts suppressed 60 min). 8/20 incident:
5 tried, 0 succeeded (session audit clean), all patreon_* machine-password
accounts, list was outside-world breach spray (public surfaces leak no
emails — scanned). An alert of this shape = the wall held; verify successes
via wp_usermeta session_tokens before escalating.

## Auto-ban: the stuffing detector feeds a login-door blocklist (#162)
Ian 8/20: *"can we just add it to a file of known offenders and nip it at our
webserver?"* — narrowed the same day to *"this should only block ips that try
several different logins in one block"*. That narrowing is the whole trigger:
lg-login-monitor's existing credential-stuffing detector (5 distinct real
accounts from one address in 15 min) fires
`do_action('lg_login_stuffing_detected')` at the same moment it sends its alert,
and nothing else bans anyone.

**The chain.** detector → `lg-auto-ban.php` appends to
`/var/lib/lg-auto-ban/state.json` (atomic rename under flock) → the
`lg-auto-ban.path` unit fires, and `lg-auto-ban.timer` runs every 5 min so bans
expire on a quiet box → `tools/infra/lg-auto-ban-render.py` (root) rebuilds
`/etc/nginx/lg-auto-ban/list.conf` and reloads → nginx refuses the login door.

**The address is the security model, not a detail.** `lg_ab_vouched_ip()` trusts
`CF-Connecting-IP` only on a connection from a Cloudflare range and otherwise
bans the real peer, so a header forged straight at the origin bans the forger
rather than a victim of their choosing. `lg_login_monitor_client_ip()` does NOT
do this — it honours the header unconditionally, which is harmless for an email
and must never be reused to select who gets blocked. nginx makes the identical
decision from the identical list (`tools/infra/cloudflare-ranges.txt`, one file,
three readers).

**Scope: the door, never the site.** wp-login.php's password action plus the
membership app's `/wp-json/lg-member-sync/v1/auth` and `/gift-auth`.
`logout`, `lostpassword`, `rp`, `resetpass`, `register` and every other URL stay
open, because stuffing rents hacked home routers and one carrier-NAT address
fronts thousands of members — a caught innocent keeps reading, keeps their
session, and can still sign in through Patreon. 24h expiry, an allowlist in the
dash plus a root-only one WordPress cannot edit, and a polite page
(`lg-shared/errors/login-blocked.html`) rather than a blank 403.

**TWO INDEPENDENT KEYS, both off by default.** `platform/config/auto-ban.php`
`enabled` decides whether anything is written down; the box-local nginx snippet
existing decides whether anyone is refused. A merge arms neither. Flag ON with
the snippet absent is the state Ian asked for — the file filling up while nobody
is blocked — and the wp-admin dash (**Login bans**) leads with which of the two
it is, read from the renderer's own status file, so a table of bans on an
unarmed box can never read as enforcement.

**Deploying it needs three things a pull cannot do**, all in
`sudo tools/infra/install-auto-ban.sh` (`--check` / `--uninstall` too): the
`/var/lib` store owned by the web user, the two nginx files in `/etc/nginx`
(installed and removed as a PAIR — either alone is a config nginx rejects), and
the three units as copies in `/etc/systemd/system`. It refuses to arm if the
vhost lacks the glob include, or if the polite page is not yet in the serving
checkout — that second one is invisible to `nginx -t` and would hand blocked
members a bare 404, the exact blank refusal the design was revised to avoid.

**Known gaps, stated rather than papered over.** (1) An attacker connecting
direct to the origin and rotating a forged `CF-Connecting-IP` evades *detection*
entirely, because the detector keys its per-IP set on the forgeable address —
pre-existing behaviour, unchanged here by ruling ("same threshold, no new
detection"). (2) `?rest_route=` is an alternate spelling of a REST path and would
not match the exact `location` on the membership auth door; that door keeps its
own 6-per-email / 21-per-IP-per-hour 429 throttle either way. (3) PHP's
`FILTER_FLAG_NO_RES_RANGE` and Python's `ipaddress` disagree about RFC 5737
documentation ranges — the renderer is stricter, which is the safe direction, and
it is the authority.
