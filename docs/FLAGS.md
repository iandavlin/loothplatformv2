# FLAG REGISTER — every switch, where it lives, and what it's doing

Started 2026-08-09 (Ian: "how are we keeping track of all these flags?").
**Maintenance rule: any merge that adds, flips, or retires a flag updates this
file IN THE SAME COMMIT — keeper refuses the merge otherwise.** States below are
as-verified on 2026-08-09; live = commit `021ff38` unless noted.

## Member-facing feature flags (tracked config — flip by commit + pull)

| Flag | Lives in | Repo default | dev2 | live | State |
|---|---|---|---|---|---|
| sheet-embeds `enabled` | platform/config/sheet-embeds.php | **true** | ON | **ON** (smoke-verified on box) | ✅ DONE — Ian-verified both boxes 8/9 ("looks good") |
| social-actions `enabled` | platform/config/social-actions.php | **true** | ON | **OFF** (flip commit post-dates live's pull) | Ian-verified dev2 ("workds"); reaches live on next lg-deploy |
| `LG_HUB_TOPIC_LANDING` | bb-mirror/config.php (env/$_SERVER '0' forces OFF) | **true** | **ON** | code not on live yet | Flipped ON 8/9 (Ian). Serves the hub+modal at the permalink instead of the legacy page. Reaches live on next lg-deploy; Ian testing dev2 serve first |
| post-follow `enabled` | platform/config/post-follow.php | **false** | OFF | OFF | E3 server half built (one-mailer); gate 18 asserts ruling-6 defaults |
| follow-digest `enabled` | platform/config/follow-digest.php | **true** | ON | ON | E1 GA since 8/8 (fd0d196); the nested second `enabled=false` is §2.2 forum-items — see below |
| follow-digest forum-items (nested) | platform/config/follow-digest.php | **false** | OFF | n/a | §2.2 build in flight (one-mailer); lane refuses merge until preview-armed verification — arm previews via `LG_FD_FORUM_ITEMS` env/$_SERVER only |
| `bell_follows_bb_subscriptions` | platform/config/notify-bridge.php | **false** | OFF | OFF | ❌ CLOSED — Ian ruled leave OFF 8/9 (consent inference; ruling 6 separation stands) |
| `LG_FD_CADENCE_CONTROL_SHIPPED` | mu-plugin define | **false** | OFF | OFF | Cadence control behind it; gate 15 asserts absence when off |
| `LG_PRESERVE_FORUM_SUBSCRIPTION` | mu-plugin define | **true** | ON | ON | P0 4.5 fix, LIVE since 8/8 — never turn off (data loss) |
| `LG_AUTHOR_SOCIALS_ALL_MEMBERS` | bb-mirror/config.php define | **true** | ON | ON | The original flag-pattern exemplar; GA |
| `LG_BB_MIRROR_FOLLOW` | FPM pool env (platform/fpm/) | dev2 "1" | ON | per live pool | Thread-follow surface; ⚠️ deploy-tooling branch documents a restore sequence that silently zeroed it — see that branch's register before touching pools |
| `LG_THREAD_FOLLOW_ENABLED` | bb-mirror/config.php | see file | — | — | Same family; two-source pattern (getenv/$_SERVER) |

## Runtime WP-option flags (flip by wp option update — PER BOX, no deploy)

| Option | Default | dev2 | live | State |
|---|---|---|---|---|
| `lgms_retraction_sweep` | absent=OFF | OFF | OFF | Phase 1 wave 1 (29fe64e): detection-only sweep; retraction stays the explicit script |
| `lgms_null_shadow_fix` | absent=OFF | OFF | OFF | Phase 1 wave 1: NULL patreon row stops shadowing the reader; measured blast radius = members 612/1768 (fix PROTECTS them) |
| `lgms_stripe_frozen` | (see poller) | — | — | Stripe ingest freeze — Phase 1 proper unfreezes; stripe seat owns |
| `lgms_poller_mail_enabled` etc. | (see poller settings) | — | — | Poller operational toggles; stripe seat owns |

## Flags on UNMERGED branches (tracked here so they don't get lost)

| Flag | Branch | State |
|---|---|---|
| E4 bell-delivery flag | origin/notif-bridge | Ian approved merge 8/9; bounced for gate renumber (22-25); re-merge on push. Sequence: code → SQL → flip; live pre-flight: `grep -c schemaHasDismiss /srv/profile-app/src/Notifications.php` ≠ 0 |
| notif-read-seen flag (P0 4.1) | origin/recap-read-timer | 35/35 green; renumbering to gate 21; needs `## Decision to arm` before ARM (not before merge) |
| digest-images flag (P0 4.0) | origin/digest-images | ❌ WRONG PATTERN — documented as wp-config define (untracked on live, breaks deploy-by-pull). Must be reworked to tracked config BEFORE merge. Unowned |

## Related, not flags

- **deploy-tooling branch (7/31, unmerged)**: flag/deploy REGISTER runbooks
  (deploy-symlink-couplings.md, live-divergences.md) + restore-sequence
  preconditions + gate check [0] — born from the 04:39 outage where a restore
  silently turned `LG_FOLLOWING_ROW_TOGGLES` off. Needs a verification pass +
  merge; owner wanted.
- The house pattern itself: docs/BACKLOG lines carry flag state per item; each
  config file carries the flag's whole story as comments; gates assert shipped
  defaults by READING them (never by assuming).
