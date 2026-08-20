# INFRA — boxes, deploy, front door

## The map
dev2 = ip-172-31-78-94 (dev + serving checkout ~/loothplatformv2-clean, pulls
main only). live = ip-172-31-67-175, deploys via `lg-deploy` (pull + conf
test/reload + symlink install). Both Cloudflare-proxied on 443: web = cookie-
gated + world-knockable; SG IP-lock is real only for non-web ports (ssh,
webmin:10000). Webroot + mu-plugins are per-file symlinks — new files need
install-symlinks; A PULLED NGINX CONF IS NOT DEPLOYED UNTIL RELOAD.

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
## Cloudflare challenges the whole standard WP API on live (verified 8/20)
Path-scoped, not bot-heuristics: `/wp-json/` root and everything under
`/wp-json/wp/v2/*` answer 403 `cf-mitigated: challenge` ("Just a moment…")
to ANY non-browser caller — measured with the same UA+IP that
`/wp-json/loothdev/v1/*` happily serves (real WP JSON, 401 on bad creds).
So our own namespace is carved out; core's is challenged. Consequence:
server-to-server calls (Apps Script, curl) can NEVER reach core endpoints
like `POST /wp-json/wp/v2/media` through the front door — the showrunner
sheet's image upload died on exactly this, 8/20 evening. The box's
cf-api-token is R2-read-only (see login-door section); the rule lives in
Ian's Cloudflare dashboard (same Security area as the 8/20 login rate
limit). Fix shape: an exception for `POST /wp-json/wp/v2/media`, or a
loothdev-namespace upload door in a mu-plugin. ⚠️ ASN trap (cost a
round-trip 8/20): Apps Script's egress (seen live as 107.178.193.205) is
**AS396982, Google Cloud** — NOT Google-proper AS15169; a rule scoped to
15169 silently never matches. Use `ip.src.asnum in {15169 396982}`.
