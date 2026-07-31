# Live divergences — every place a box is not just `main`

**Ian, 2026-07-31:** *"we have a mandate to only pull except for extreme conditions.
What was extreme about this?"* — *"why wasnt that built into the repo and pulled? So now
we have untracked changes?"*

Nothing was extreme. This file exists because that question had no answer, and because
the honest answer to *"what is different about live?"* was **"whatever is in someone's
head."**

## The rule

> **Deploy is ONE PULL. If something cannot arrive that way, that is a defect in the
> tooling, not a licence to do it by hand.** Anything done by hand is a bug report, not
> a precedent.

Two things follow, and both are enforced rather than trusted:

1. **`tools/gates/deploy-drift-gate.sh` treats the DECLARE block below as an allowlist.**
   Divergence that is declared here is green. Divergence that is not is **RED**. There is
   no third option and no silent state — which is the property that was missing when
   dev2's hand-edited `strangler-membership.conf` sat undetected for an unknown number of
   weeks.
2. **Declaring something is not the same as accepting it.** The §1 items below are
   declared *and* scheduled for removal; the §3 items are declared *and* permanent.

---

<!-- DECLARE-BEGIN -->
<!-- Machine-readable allowlist, parsed by tools/gates/deploy-drift-gate.sh.
     Format:  <box> <kind> <identifier>       # prose after # is ignored
     kinds:   flag | snippet-copy | pool-copy | mu-absent | extlink
     NOTE: a `flag` identifier is SOURCE-SCOPED — `nginx:<file>:<NAME>` or
     `pool:<file>:<NAME>`. Declaring a bare flag name would silence it in every file
     on that box, so a lane-preview declaration would also hide a later hand edit of
     a serving snippet. That is the exact defect this register exists to catch.
     ADDING A LINE HERE IS A DELIBERATE ACT. It silences a gate. Say why, below. -->
```
dev2 snippet-copy lane-preview-account-following.conf   # §3.1 lane overlay, regenerated per lane
dev2 snippet-copy lane-preview-thread-follow.conf       # §3.1
dev2 snippet-copy lane-preview-weekly-digest-recap.conf # §3.1
dev2 pool-copy    www.conf                              # §3.2 no dev2 variant in repo
dev2 pool-copy    lg-billing-dev.conf                   # §3.2 no dev2 variant in repo
dev2 mu-absent    lg-dev-disable-looth1-bounce.php      # §3.3 inactive on BOTH boxes
dev2 mu-absent    lg-impact-tracking.php                # §4.1 OPEN FINDING — live has it, dev2 does not
dev2 mu-absent    lg-merged-login-redirect.php          # §4.1 OPEN FINDING — live has it, dev2 does not
dev2 flag nginx:lane-preview-account-following.conf:LG_MS_SLUG                # §3.1
dev2 flag nginx:lane-preview-account-following.conf:LG_MS_PUBLIC_PATH         # §3.1
dev2 flag nginx:lane-preview-account-following.conf:LG_FOLLOWING_ROW_TOGGLES  # §3.1
dev2 flag nginx:lane-preview-thread-follow.conf:LG_BB_MIRROR_FOLLOW           # §3.1
dev2 flag nginx:lane-preview-thread-follow.conf:LG_BB_MIRROR_PUBLIC_PATH      # §3.1
live mu-absent    lg-dev-disable-looth1-bounce.php      # §3.3 inactive on BOTH boxes
live mu-absent    lg-dev-mail-containment.php           # §3.4 DEV-ONLY BY DESIGN — must never be on live
```
<!-- DECLARE-END -->

---

## §1 — UNTRACKED STATE FROM 2026-07-31. All of it is being removed.

These are the five manual interventions from that night's deploy. **Four of the five are
now repo-native**; each entry gives the one command that restores repo ownership.

**Prove it on dev2 before live.** Every fix below was reproduced and verified on dev2
first — Ian's box is not the experiment.

### §1.1 — a hand-edited nginx snippet on LIVE ⚠️ THE WORST ONE

`/etc/nginx/snippets/strangler-membership.conf` **was** a symlink into the serving
checkout. To set `fastcgi_param LG_FOLLOWING_ROW_TOGGLES 1;` it was replaced with a real
file and edited. From that moment it stopped receiving repo updates, and **the only
record of the divergence was a comment inside the file itself.**

**dev2 carries the identical divergence, older, and nobody had recorded that either** —
it was found only by looking on the night.

**Root cause, and it is a code defect not a process one:** the flag was read from
`$_SERVER` only, so *only* a `fastcgi_param` could set it, and the only per-box nginx
file is a snippet symlinked out of the serving checkout. **The flag was unreachable by
pull.** That is the tooling defect Ian's mandate points at.

**Fixed** in `membership-pages/lib/following-data.php`: the flag now reads `getenv()`
**and** `$_SERVER`, exactly as `LG_BB_MIRROR_FOLLOW` has always done
(`bb-mirror/config.php:461`). `getenv()` is fed by `env[]` in the **tracked per-box FPM
pool file**, so box-level flag state now arrives by `git pull`.

- `platform/fpm/live/membership.conf` → `env[LG_FOLLOWING_ROW_TOGGLES] = "1"` (ON, Ian's call)
- `platform/fpm/dev2/membership.conf` → same

`$_SERVER` is deliberately kept: `tools/preview/lane-preview.sh` sets the flag by
`fastcgi_param` for a single lane URL, and a `fastcgi_param` does not reliably reach the
process environment. Neither source is reachable by a visitor — a query string can set
neither.

**RESTORE (live, Ian). Order matters — this sequence never has the flag OFF:**

```bash
# 1. pull first: brings the getenv() read AND the tracked pool file that sets the flag
lg-deploy

# 2. reload FPM so the pool env is live. The flag is now set by BOTH the pool and the
#    old hand-edited snippet — belt and braces, so there is no window where it is off.
sudo systemctl reload php8.3-fpm

# 3. NOW restore the symlink; the snippet stops setting the flag, the pool still does
sudo ln -sfn /home/ubuntu/loothplatformv2-clean/platform/nginx/strangler-membership.conf \
             /etc/nginx/snippets/strangler-membership.conf
sudo nginx -t && sudo systemctl reload nginx

# 4. prove it: the toggles must still be there. nginx -t proves syntax, never behaviour.
bash tools/gates/deploy-drift-gate.sh --box live
```

Same three commands on dev2, minus `lg-deploy`.

### §1.2 — a Postgres ROLE that existed in no file

The `membership` FPM pool runs as OS user `membership`, and bb-mirror's DSN is
`pgsql:host=/var/run/postgresql;dbname=looth` with **no user and no password** — so
Postgres peer-auths as the OS user. dev2 had a `membership` role; **live never did.**

Every load of Manage Account logged, on live:

```
FATAL:  role "membership" does not exist
```

`cutover/topic-follow-migrate.sh` created the *table* and granted `looth_ro` — it never
checked that **the role the app connects as exists**. A role is invisible to `git diff`,
so nothing could have caught it.

**Fixed:** `cutover/ensure-pg-roles.sh` — the role and grant manifest as a repo artifact,
idempotent, dry-run by default, with a `--check` mode that is the deploy preflight and
**fails loudly** rather than letting the box fail per-request afterwards.

```bash
bash cutover/ensure-pg-roles.sh            # dry run
bash cutover/ensure-pg-roles.sh --apply    # create anything missing
bash cutover/ensure-pg-roles.sh --check    # preflight; exit 1 if the box does not match
```

**Live already satisfies this** (Ian created the role and the three grants on the night;
`membership=r` on `forums.{forum,topic,topic_follow}`, verified read-only — live and dev2
ACLs are now identical). The artifact exists so the next box, or the next rebuild, cannot
repeat it.

> **Deliberately absent:** `events` and `tool-dev` run FPM pools but open no Postgres
> connection, so they have no role. That is a finding, recorded so nobody "fixes" it later
> by granting real access for no reason.

### §1.3 — FPM pool symlinks, correct only because Ian repaired them by hand

Run unfiltered on live, `cutover/symlink-farm.sh` **repointed all seven FPM pools** from
`platform/fpm/live/*.conf` to `platform/fpm/*.conf` — the dev variants, which set
`pm.max_children = 2` instead of 6–12 and drop `LG_*_PUBLIC_HOST`. It took no effect only
because php-fpm was never reloaded; **the next reload would have cut live to two workers
per pool.** It also moved two live plugin directories aside and symlinked repo copies over
them.

The script was written for the **cut box**, where non-`live/` was canonical. It says so
nowhere.

**Fixed:** see §2 — the farm now refuses to touch FPM pools unless the box is explicitly
identified, and it detects the box rather than trusting a flag. Live's pools are currently
correct and the drift gate asserts they stay that way.

### §1.4 — a hand-edited FPM pool on dev2 (same class as §1.1, found while writing this)

`/etc/php/8.3/fpm/pool.d/bb-mirror.conf` on dev2 is a real file — the tracked symlink was
displaced to `bb-mirror.conf.bak-followflag-20260731-004038` — carrying
`env[LG_BB_MIRROR_FOLLOW] = "1"`.

Nothing needed changing in code here: `bb-mirror/config.php` already read `getenv()`. The
flag simply had no tracked home. It now has one, in `platform/fpm/dev2/bb-mirror.conf`,
which live's directory deliberately does not mirror — **the follow feature stays dark on
live even with `main` deployed.**

**RESTORE (dev2):**

```bash
sudo ln -sfn /home/ubuntu/loothplatformv2-clean/platform/fpm/dev2/bb-mirror.conf \
             /etc/php/8.3/fpm/pool.d/bb-mirror.conf
sudo systemctl reload php8.3-fpm
```

### §1.5 — nginx snippet symlinks are created by nothing

A new `platform/nginx/*.conf` arrives in the pull with **no** `/etc/nginx/snippets/` link,
so `nginx -t` fails and the reload is correctly refused. `strangler-shop-planner.conf` hit
this on the night. `symlink-farm.sh` does cover snippets, but running it on live is what
§1.3 is about, so in practice nobody ran it.

**Fixed:** `tools/deploy/live-deploy.sh` derives new snippets from the diff and links them.

---

## §2 — `cutover/symlink-farm.sh` is dangerous on a serving box

Read `cutover/symlink-farm.sh`'s own header before running it anywhere. The guard added
2026-07-31 makes it **refuse to repoint FPM pools unless `--fpm-box <name>` names the box
it is actually on**, and it determines the box by inspecting the machine, not by trusting
the flag. See §1.3 for what it did without that.

---

## §3 — Permanent, deliberate divergences

These are declared above and are **not** scheduled for removal.

### §3.1 — lane-preview snippets on dev2 are real files
`tools/preview/lane-preview.sh` generates `/etc/nginx/snippets/lane-preview-*.conf` per
lane so a branch gets a URL without merging. They are ephemeral by design and set
`LG_MS_SLUG`, `LG_MS_PUBLIC_PATH`, `LG_BB_MIRROR_PUBLIC_PATH`, and the two feature flags
for **one lane's URL only**.

> **Open question, not a blocker:** the repo also contains `platform/nginx/lane-preview-*.conf`
> for these same lanes. A generated per-lane overlay and a tracked file of the same name
> are two sources for one thing. Worth a decision by whoever owns the preview tooling;
> it is declared here so it stops being invisible.

### §3.2 — `www.conf` and `lg-billing-dev.conf` are real files on dev2
The repo carries only `platform/fpm/live/` variants of these two. dev2 has no counterpart
to link to, so they are local files. On live both are correctly symlinked.

### §3.3 — `lg-dev-disable-looth1-bounce.php` is linked on neither box
Present in the repo, active nowhere. Declared so it does not read as an accident.

### §3.4 — `lg-dev-mail-containment.php` is dev2-only, and must stay that way
It contains outbound mail on the dev box. **Linking it on live would be a serious
incident.** Its absence from live is the correct state, permanently.

---

## §4 — Open findings that need a decision (not fixed by this lane)

### §4.1 — dev2 is missing two mu-plugins that live runs ⚠️
Live has `lg-impact-tracking.php` and `lg-merged-login-redirect.php` linked; **dev2 does
not.** Neither is dev-only by name or content. So dev2 is not a faithful staging box for
either, and anything they affect — impact tracking, and the post-merge login redirect —
behaves differently on the box Ian approves from than on the box members use.

Declared rather than fixed because linking a mu-plugin **changes dev2's behaviour**, and
that is a deliberate act for whoever owns those two, not a side effect of a tooling lane.

### §4.2 — leftovers from the symlink-farm run on live
`/var/www/dev/wp-content/mu-plugins/lg-patreon-stripe-poller.php.pre-symlink-20260731-035531`
is a rollback file the farm left behind (§1.3). Harmless — WordPress does not load
`.php.pre-symlink-*` — but it should be removed once the deploy is settled, and its
existence is the evidence for what the farm did.

### §4.3 — per-pool error logs go nowhere on either box
Every pool sets `php_admin_value[error_log] = /var/log/php-fpm/<pool>-error.log`, and
**`/var/log/php-fpm/` does not exist on dev2 or live.** Errors survive only because
`catch_workers_output = yes` funnels them into `/var/log/php8.3-fpm.log` — which is why
`tools/gates/fpm-error-log-gate.sh` reads that file and not the per-pool ones. Either
create the directory or drop the setting; right now the config states an intent the boxes
do not honour.

---

## How to check this file is still true

```bash
bash tools/gates/deploy-drift-gate.sh          # dev2 + live (live is read-only)
bash tools/gates/fpm-error-log-gate.sh         # what the boxes are actually logging
```

Both are in `tools/gates/run-all.sh` (gates 15 and 16). **The drift gate is RED by design
until the §1.1 and §1.4 restores are run** — that is the gate working, not a bug in it.

*Written 2026-07-31 by the deploy-tooling lane. Every "live" fact came from a read-only
`ssh live-ro`; nothing was run against live.*
