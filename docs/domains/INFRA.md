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
- A GitHub **deploy key** greets as `owner/repo`, is scoped to that ONE repo,
  and can still READ any *public* repo — so `ls-remote` succeeding proves
  nothing about write. Probe write with
  `ssh -i KEY git@github.com "git-receive-pack 'owner/repo.git'"` (read-only:
  it prints the advertisement or refuses).
- The repos API `permissions:{push,admin}` reflects the **authenticated
  USER's** rights on the repo, NOT what a fine-grained PAT was granted.
  `LG_GITHUB_ISSUES_TOKEN` reported `push:true,admin:true` on looth-platform
  and is issues-only (receive-pack 403 on BOTH repos; contents API 404 — a
  fine-grained PAT lacking a permission 404s rather than 403s). Probe
  `/info/refs?service=git-receive-pack`, don't read the metadata.
- An ssh alias with **no `Host` block** is not a broken key, it is a literal
  hostname: `Could not resolve hostname github-looth`. Every push from
  ~/projects failed that way, silently, for two months.
- `/etc/looth/env` is mode **644 root:root** — anything put there is readable
  by every user on the box. It holds a PAT. Do not widen its grant.

## ~/projects — a SECOND repo, not the monorepo
`~/projects` is `iandavlin/looth-platform` (**public**), whose root commit is
unrelated to loothplatformv2 — the two share no history. It is ONE working
tree with 19 local branches (the ledger's "8 worktrees" was wrong; there is no
`.git/worktrees/`). Its remote-tracking refs froze 2026-06-18 while the remote
moved on, so **stale `origin/*` refs mis-measure risk in both directions** —
measure against a fresh `ls-remote`/fetch, and use `git cherry` to tell a
genuinely-unique commit from one already re-applied upstream.
Survey 8/20: 16 local-only commits over 3 branches — `bespoke-cutover` +11
(all unique, the real payload), `lane-wp-auth` +2 and `lane-profile-app` +3
(mostly already equivalent upstream). Local `lane-profile-app` is 3 ahead /
**22 behind** its remote namesake, so it rescues to a NEW ref; a force-push
there would destroy 22 commits this disk has never seen. The 195 uncommitted
files in that tree are a larger exposure than the commits and no push covers
them. Interim: `~/projects-rescue-bundles/at-risk-20260820.bundle` (verified,
same disk — not off-box safety).

## Issue history
#132/ledger-47 (unreachable projects remote — dated Wed 8/20): alias added, so
the remote READS again; pushes BLOCKED on Ian adding deploy key
`looth-platform-deploy-dev2` with write to looth-platform · #138 watcher
phase A live, phase B on probation · #141 rider batching.
