# OPERATOR.md — read this FIRST, every session (5 minutes that prevent wrong assumptions)

**Status: authoritative, 2026-07-04.** If this disagrees with an older doc, THIS wins. If the box
disagrees with this file, verify on the box, then update this file in the same task (keeper rule).
Written so any operator (human or model) can run the site without deep-diving or guessing.

## 1. The boxes — identify by NAME/ROLE, never by IP alone

| role | instance | IP | access |
|---|---|---|---|
| **LIVE** (loothgroup.com) | `loothgroup2-4` / i-0b938c07575254cab | 54.146.118.131 (EIP) | READ: `ssh live-ro` (SELECT-only mysql via its ~/.my.cnf). WRITES: hand Ian a bash — never write to live yourself. |
| **dev2** (the dev env, dev2.loothgroup.com) | Name tag `dev2*` — **instance id CHANGES per rebuild, find by tag** | 34.193.244.53 (EIP) | `ssh -i ~/projects/lg-stripe-billing/claude-keypair.pem ubuntu@34.193.244.53`, passwordless `sudo -n`. Power on/off from dev1: `aws ec2 start-instances/stop-instances` (devgbox-cli IAM allows dev1 + `dev2*` tags ONLY). |
| **dev1** (orchestration/keeper) | "Claude Code" / i-01e54ed6c9a4ba91e | 50.19.198.38 | You usually RUN here. Chat transcripts + `msg` board live here. NOT a platform box. |

⚠️ **EIPs get recycled across rebuilds** (54.146.118.131 was OLD-live before it was new-live;
34.193.244.53 has fronted three different dev2 instances). Confirm identity with `hostname` /
instance metadata before trusting any conclusion tied to an IP.

dev2 is **cookie-gated** (token in `/etc/nginx/snippets/loothdev-tokens.conf` on the box; claim URL
= `https://dev2.loothgroup.com/claim?t=<token>`), and **mail is hard-blocked** (`lg-dev-mail-block.php`
mu-plugin short-circuits ALL wp_mail). Loopback (127.0.0.1) bypasses the gate for curl tests.

## 2. The ONE topology (both live and dev2 — identical by construction)

Everything serves from **`/home/ubuntu/loothplatformv2-clean`** — a git checkout pinned to
`origin/main` — via symlinks:
- `/srv/{profile-app,archive-poc,bb-mirror,events,membership-pages,lg-shared,lg-layout-v2}` → checkout
- WP plugins `lg-*` and most of `wp-content/mu-plugins/*` → checkout (`platform/mu-plugins/`)
- `/etc/nginx/` vhost + snippets + conf.d → checkout (`platform/nginx/`)
- systemd units in `platform/systemd/` are copies (install once per box)

⚠️ **THE #1 TRAP: check for symlink-into-repo BEFORE writing any "deployed" path.**
`readlink -f <path>` first. `tee`/`cp` through a symlinked conf writes INTO the git working tree —
dirties the repo and can put secrets in `git status`. Box-local overrides = replace the symlink with
a real file, then `git checkout --` the repo copy. (Bit us twice on 2026-07-04.)

Intentionally box-local (do NOT capture into git): `/etc/looth/*` (secrets/env), TLS certs, gate
tokens, `r2-uploads-dev.service`, thumbnails services. Full list: `~/BOX-LOCAL-dev2-units.md` (dev2)
+ `env.template` (the /etc/looth/env contract — promotion = value changes only, zero code).

## 3. Deploy & git discipline

- **Deploy = `git pull` in the checkout + `systemctl reload php8.3-fpm`** (+ `nginx -t && reload` if
  confs changed). Same on live, but Ian runs it.
- Lanes: worktree off main (`git -C ~/loothplatformv2-clean worktree add ~/worktrees/<lane> -b <lane> main`),
  **push the branch to origin** after first commit (backup — branch-push ≠ merge), report to keeper.
  Merge to main ONLY after dev2-tested + Ian-approved; present commits+diffstat before every push to main.
- **NEVER `sudo git` in the shared checkout** (root-owns .git internals → breaks everyone; fix =
  `sudo chown -R ubuntu:ubuntu .git`). NEVER hand-edit the checkout. NEVER leave a serve symlink
  pointed at a lane worktree — always restore + reload after testing.

## 4. Scheduled work (since 2026-07-04)

`lg-wp-cron.timer` (systemd, 1-min tick, `wp cron event run --due-now` as looth-dev) is the ONLY WP
scheduler; `DISABLE_WP_CRON=true` stays in wp-config. PG maintenance: `bb-mirror-reconcile.timer`,
`lg-person-vis-refresh.timer`. There are NO crontabs. If cron events pile up "now"-overdue, the
timer is broken — check `systemctl status lg-wp-cron.timer`.

## 5. Member email — CHECK BEFORE TOUCHING ANYTHING MAIL-ADJACENT

- LIVE: poller/member mail (welcome, membership, gift, bulk) is **OFF by double-lock**:
  `lgms_poller_mail_enabled` absent→false (poller's own gate) AND that same false keeps the
  `lg-poller-mail-killswitch.php` mu-plugin ACTIVE (suppresses all poller-originated wp_mail).
  Only mails tagged `X-LG-Poller-Intent: notify` pass (failure alerts to Ian + member "we're aware").
  **Setting that one flag =1 disables BOTH locks at once. Never set it casually.**
  ⚠️ GiftMailer lacks the notify header → gift-code emails are suppressed until this is revisited
  (must fix before gift sales launch).
- What DOES email members: Weekly Digest (FluentCRM, Mon 09:00), event reminders (FluentCRM),
  WP password resets, BuddyBoss per-event notification emails (defaults ON for everyone).
- dev2: ALL wp_mail hard-blocked (`lg-dev-mail-block.php`). Never remove without replacement.

## 6. Identity / auth / passwords — settled truths (don't re-derive)

- Tier truth = **WP roles** (looth1–4). `/profile-api/v0/whoami` (fast) is display identity; auth =
  WP login cookie; `looth_id` JWT is an identity claim, NOT a login credential.
- Password writers are ONLY: `/patreon-password/` page (mu-plugin), WP core reset, gift first-claim
  (redemption-code-proven). The hourly poller sync NEVER touches passwords (email only).
- "Changed password on one device, other device can't log in" = sessions are invalidated everywhere
  on change (by design) + the other device autofills the OLD saved password. Audited 2026-07-04, no
  server bug — see memory `reference_password_login_audit_20260704` before re-investigating.
- `wp user delete` fires an IRREVERSIBLE UserLifecycle nuke across all stores — use SQL for test
  teardown (+ `wp cache flush`).

## 7. Perf machinery you must not accidentally break

- `/hub/` has a 60s ANON microcache (nginx `lghub` zone; logged-in/gate cookies bypass via
  `$lg_anon_nocache` map). Bots ≈ 87% of traffic ride it. Purge: `rm -rf /var/cache/nginx-lghub/*`.
- nginx gotchas: `add_header` in a location REPLACES all inherited headers (restate the security
  set); a second `sub_filter` on the same match string silently never fires (merge into the
  existing '</head>' blob in the vhost); the GA/theme-boot script is edge-injected there.
- `wp option update siteurl/home` NO-OPs (env-driven host filter) — raw SQL + fpm restart + `wp
  cache flush` if a host pin is ever needed. Standalone apps' PUBLIC_HOST also pinned in FPM pool
  files (the "7-point edit" debt).

## 8. Data stores (one line each)

MySQL: `looth_import` (WP, yes that name), `lg_membership` (billing). Postgres (peer-auth):
`looth` (schemas `discovery`=archive-poc, `forums`=bb-mirror), `profile_app` (profile-app).
R2 (S3): dev = `loothgroup-uploads-dev` + `loothgroup-2-0-profile-dev`; live = `loothgroup2-0` +
`loothgroup2-0-profile-bucket`. WP uploads dir = symlink to the R2 FUSE mount. Any R2 401 = dead
credential; 403 = scope/bucket-name (load the r2-wiring skill FIRST; verify by object put→cat→delete,
never by listing). Secrets map = `sudo -n /usr/local/sbin/lg-secrets-helper list|buckets` (on dev2).

## 9. Verify-before-asserting (the anti-false-assumption checklist)

1. Which box am I on / talking to? → `hostname`, `curl -s ifconfig.me`, Name tag.
2. Is this file what the repo serves? → `readlink -f` + `git -C ~/loothplatformv2-clean status`.
3. Is this "bug" actually the documented behavior? → grep this file + `docs/atlas/` + the memory
   index BEFORE diving into code.
4. Email/red-flag change? → re-read §5 first.
5. About to write under /etc or wp-content? → §2 trap check.
6. Done testing? → restore symlinks byte-identical, reload FPM, purge test data, confirm
   `git status` clean.

Cross-refs: SYSTEM-MAP.md (deep architecture), REPO-MANDATE.md (binding policy), GIT-PROTOCOL.md,
MOBILE-DESKTOP-SPLIT.md, R2-TOPOFF.md, env.template. Audit snapshot: dev1
`~/projects/AUDIT-DEV2-LIVE-2026-07-04.md`.
