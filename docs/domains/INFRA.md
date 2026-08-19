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

## Issue history
#132/ledger-47 (unreachable projects remote — dated Wed 8/20) · #138 watcher
phase A live, phase B on probation · #141 rider batching.
