# dev2 worktrees — verified safe-to-remove list
**Measured 2026-08-15 by the stripe-membership lane. READ-ONLY — nothing removed.**
Companion to `DEV2-DISK-OFFENDERS-2026-08-15.md`, whose worktree headline this
**corrects**.

---

## The correction
My first pass said *"79 orphaned directories, git knows nothing about them."*
**That was wrong.** I ran `git worktree list` in `keeper-repo` only, saw 10 against 89
directories, and concluded the rest were untracked.

**All 89 are properly registered.** They belong to **three** parent repos:

| Parent repo | Worktrees |
|---|---|
| `loothplatformv2-clean` | 71 |
| `projects` | 9 |
| `keeper-repo` | 9 |

Zero are orphaned. The same shape of mistake the Stripe rehearsal caught the night
before: I checked one door and reported on the building.

**Consequence:** these must be removed with `git worktree remove` **from the right
parent**, never `rm -rf` — otherwise each parent's registry is left pointing at
nothing.

---

## The verified result

Every directory read with its **own** gitdir pointer. `origin/main` was confirmed to
exist in all three parents first — a missing ref would have made every merged/unmerged
verdict meaningless.

| | Count | Size |
|---|---|---|
| Live lanes — **never touch** | 6 | — |
| **SAFE**: merged into `origin/main`, clean tree, nothing unpushed | **48** | **1555 MB (1.52 GB)** |
| **HOLD**: something would be lost | 35 | 1268 MB |

So the honest reclaim from worktrees is **~1.5 GB, not the 2.6 GB I first claimed**.

### ⚠ One judgement call that is keeper's, not mine

**71 of these belong to `loothplatformv2-clean` — the serving checkout**, the repo whose
first rule is that it only ever pulls. `git worktree remove` does not alter its working
tree, only its worktree registry — but it *is* a git write to that repo, so it is your
call whether that counts. Everything needed to decide is here; I have not run it.

---

## SAFE — 48 directories, 1555 MB

### parent: `keeper-repo` — 1 dirs, 76 MB

| MB | directory | branch |
|---|---|---|
| 76 | `account-following` | `account-following` |

### parent: `loothplatformv2-clean` — 45 dirs, 1415 MB

| MB | directory | branch |
|---|---|---|
| 64 | `reply-images-count` | `mirror-delete-orphans` |
| 55 | `composer-v2-p2` | `composer-v2-p2` |
| 55 | `mentions` | `username-mentions-finish` |
| 51 | `layoutv2-ian` | `layoutv2-ian` |
| 38 | `buck-batch2-extract` | `buck-batch2-extract` |
| 38 | `buck-fixes-extract` | `buck-fixes-extract` |
| 38 | `deploy-one-pull` | `deploy-one-pull` |
| 38 | `f1-login-redirect` | `f1-login-redirect` |
| 38 | `housekeeping-0726` | `housekeeping-0726` |
| 38 | `identity-pass` | `profile-fixes-0726` |
| 38 | `messages-dm-from-group` | `messages-dm-from-group` |
| 38 | `messages-search` | `messages-search` |
| 38 | `react-add-unhide` | `react-add-sheet-unhide` |
| 38 | `slug-live` | `slug-backfill` |
| 33 | `dark-c-sweep` | `dark-c-sweep` |
| 30 | `dark-mode` | `dark-mode` |
| 30 | `dark-nudges` | `dark-brand-nudges` |
| 30 | `gallery-palette` | `gallery-palette-supersede` |
| 26 | `connections-confirm` | `connections-confirm` |
| 26 | `hub-default-recent` | `hub-default-recent` |
| 26 | `hub-picker-in-tray` | `hub-picker-in-tray` |
| 26 | `idle-hold-ttl` | `idle-hold-ttl` |
| 26 | `live-fixes` | `live-fixes-member-sponsor-footer` |
| 26 | `loothalong-newtab` | `loothalong-newtab` |
| 26 | `messages-fixes` | `messages-fixes` |
| 26 | `messages-mobile-bugs` | `messages-mobile-bugs` |
| 26 | `messages-peer-fix` | `messages-peer-fix` |
| 26 | `messages-scroll-lock` | `messages-scroll-lock` |
| 26 | `notif-conn-links` | `notif-connection-links` |
| 26 | `notifications-rebuild` | `notifications-rebuild` |
| 26 | `notifications` | `notif-delete-rotation` |
| 26 | `profile-enhance` | `profile-enhance` |
| 26 | `profileapp-depin` | `profileapp-depin` |
| 26 | `reply-images` | `reply-images` |
| 26 | `username-mentions` | `username-mentions` |
| 25 | `dir-map-geoinit` | `dir-map-geoinit` |
| 25 | `dir-mobile-list` | `dir-mobile-list` |
| 25 | `msgimg-fe-polish` | `msgimg-fe-polish` |
| 25 | `msgimg-sending-state` | `msgimg-sending-state` |
| 25 | `nav-hub-cpt-modal` | `nav-hub-cpt-modal` |
| 24 | `guitardle` | `guitardle` |
| 24 | `hub-deeplinks` | `hub-deeplinks` |
| 24 | `message-images` | `message-images` |
| 24 | `poller-roles` | `poller-roles` |
| 24 | `weekly-digest-fixes` | `weekly-digest-fixes` |

### parent: `projects` — 2 dirs, 64 MB

| MB | directory | branch |
|---|---|---|
| 36 | `consolidate-topology` | `consolidate-serve-topology` |
| 28 | `bb-mirror` | `lane/bb-mirror` |

---

## HOLD — 35 directories, 1268 MB. Do not remove.

| directory | parent | branch | why it is held |
|---|---|---|---|
| `archive-poc` | projects | `lane/archive-poc` | uncommitted changes |
| `composer-picker-fix` | loothplatformv2-clean | `composer-link-insert` | uncommitted changes |
| `consolidate-poller` | projects | `consolidate-poller-e01cf6f` | uncommitted changes |
| `dir-name-search` | loothplatformv2-clean | `dir-name-search` | uncommitted changes |
| `ghost-containment` | loothplatformv2-clean | `ghost-containment` | uncommitted changes |
| `hub-reply-reactions` | loothplatformv2-clean | `hub-reply-reactions` | uncommitted changes |
| `identity-reconcile` | loothplatformv2-clean | `identity-reconcile` | uncommitted changes |
| `lg-layout-v2` | projects | `lane/lg-layout-v2` | uncommitted changes |
| `membership-pages` | projects | `lane/membership-pages` | uncommitted changes |
| `messages-manage` | loothplatformv2-clean | `messages-manage` | uncommitted changes |
| `messages-reactions` | loothplatformv2-clean | `messages-reactions` | uncommitted changes |
| `posts` | projects | `lane/posts` | uncommitted changes |
| `reply-edit-icons` | loothplatformv2-clean | `reply-edit-icons` | uncommitted changes |
| `signout-fix` | loothplatformv2-clean | `signout-fix` | uncommitted changes |
| `bespoke-cutover` | projects | `bespoke-cutover` | uncommitted changes + unpushed commits |
| `dmv-native` | loothplatformv2-clean | `dmv-native` | uncommitted changes + unpushed commits |
| `emoji-picker-build` | keeper-repo | `emoji-picker-build` | uncommitted changes + unpushed commits |
| `front-page-editor` | keeper-repo | `front-page-editor` | uncommitted changes + unpushed commits |
| `reply-images-6` | loothplatformv2-clean | `reply-images-6` | uncommitted changes + unpushed commits |
| `composer-v2-p3` | loothplatformv2-clean | `phase3-exit-mobile` | unpushed commits |
| `dev2-idle` | loothplatformv2-clean | `dev2-idle` | unpushed commits |
| `groups-design` | loothplatformv2-clean | `groups-design` | unpushed commits |
| `ian-local-looths` | loothplatformv2-clean | `ian/local-looths` | unpushed commits |
| `impact-tracking` | loothplatformv2-clean | `impact-tracking` | unpushed commits |
| `login-destination` | loothplatformv2-clean | `login-destination` | unpushed commits |
| `login-poller` | projects | `lane/login-poller` | unpushed commits |
| `map-infinite` | loothplatformv2-clean | `map-infinite` | unpushed commits |
| `messages-group-names` | loothplatformv2-clean | `messages-group-names` | unpushed commits |
| `monorepo-audit` | loothplatformv2-clean | `monorepo-audit` | unpushed commits |
| `patreon-return` | loothplatformv2-clean | `gate-harness-repair` | unpushed commits |
| `poller-patreon-id` | loothplatformv2-clean | `poller-patreon-id` | unpushed commits |
| `slug-backfill` | loothplatformv2-clean | `gate-harness-portable` | unpushed commits |
| `social-poster-meta` | loothplatformv2-clean | `social-poster-meta` | unpushed commits |
| `stewmac-impact-fix` | loothplatformv2-clean | `stewmac-impact-fix` | unpushed commits |
| `weekly-digest-recap` | loothplatformv2-clean | `weekly-digest-recap` | unpushed commits |

Each of these holds work that is not safely in `origin/main`. Sixteen have commits that
were never pushed — those would be **gone**, not merely inconvenient. They want a
per-branch decision (push it, or deliberately abandon it), which is a separate job from
reclaiming disk.

---

## How to remove one, when you decide to

```
git -C <parent-repo> worktree remove <path>
```

Run from the parent named in the table. `git worktree prune` will **not** help here —
nothing is orphaned, which is exactly what my first pass got wrong.
