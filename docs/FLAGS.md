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
| back-pill `enabled` | platform/config/back-pill.php | **false** | OFF | OFF (code not on live yet) | 3.8 back-nav hybrid (lower-left, appear-on-scroll). Gate 22 (nav must navigate). Merged 8/9; awaiting Ian on the serve, then flip |
| `dismiss_instead_of_delete` | profile-app/config/notifications.php | **false** | OFF | OFF (code not on live yet) | E4 delete=dismiss (ruling 9). ⚠️ **HAS A SCHEMA DEPENDENCY AND AN ORDER — see below.** Gates 23 + 25 assert both states |
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
| `lgms_stripe_lifecycle` | absent=OFF | OFF | OFF | Phase 1 (0ffb32f): webhook-driven single-tier membership. OFF = no route/no read/no log (keeper-verified). ⚠️ INTERLOCK: refuses while `lgms_identity_gate` dark. FLIP ORDER live: identity_gate ON → lifecycle ON → allowlist governs WHO |
| `lgms_stripe_lifecycle_allowlist` | absent=**CLOSED for everyone** | unset | unset | Soft-launch cohort (docs/STRIPE-SOFT-LAUNCH-ALLOWLIST.md): array of THIS box's WP user ids; only listed members transition, everyone else's events are 200-acknowledged + journaled `skipped: not in soft-launch cohort (uid=N)`, no membership change EITHER direction. Empty/absent/malformed = nobody (fail-safe, gated). Edit: wp-admin → Settings → LG Member Sync → **Stripe Cohort** tab, or `wp option update lgms_stripe_lifecycle_allowlist '[101,202]' --format=json`. Gate #TBD-keeper: test-soft-launch-allowlist.php (39) + e2e §6 (12) |
| `lgms_stripe_lifecycle_allowlist_added` | absent | unset | unset | Dash bookkeeping only (uid→date added, feeds the cohort table's "date added" column). The lifecycle gate NEVER reads it; losing it costs display data only |
| `lgms_stripe_webhook_secret` | unset | unset | unset | Stripe dashboard webhook signing secret — set on live before lifecycle flip |
| `lgms_stripe_price_id` | unset | unset | unset | The single Stripe price for looth3 — set on live before lifecycle flip |
| `lgms_stripe_frozen` | (see poller) | — | — | Stripe ingest freeze — Phase 1 proper unfreezes; stripe seat owns |
| `lgms_poller_mail_enabled` etc. | (see poller settings) | — | — | Poller operational toggles; stripe seat owns |

## Flags on UNMERGED branches (tracked here so they don't get lost)

| Flag | Branch | State |
|---|---|---|
| `dismiss_instead_of_delete` (E4) | origin/notif-bridge | Ian approved merge 8/9; bounced for gate renumber, re-minted 22-25 and re-merged. Now listed in the member-facing table above — **read the ordering note below before flipping it** |
| `bell_follows_bb_subscriptions` (E4) | origin/notif-bridge | ❌ CLOSED by Ian 8/9, ships OFF. Listed above; the code stays so reversing the ruling needs no rediscovery |
| notif-read-seen flag (P0 4.1) | origin/recap-read-timer | 35/35 green; renumbering to gate 21; needs `## Decision to arm` before ARM (not before merge) |
| digest-images flag (P0 4.0) | origin/digest-images | ❌ WRONG PATTERN — documented as wp-config define (untracked on live, breaks deploy-by-pull). Must be reworked to tracked config BEFORE merge. Unowned |

## ⚠️ `dismiss_instead_of_delete` — the one flag here with a SCHEMA DEPENDENCY

Every other flag in this file is safe to flip in any order. This one is not, and the
constraint runs the opposite way to the intuition, so it is written out rather than
left to be re-derived.

**ORDER: deploy the code → apply the migration → flip the flag.**
Live pre-flight: `grep -c schemaHasDismiss /srv/profile-app/src/Notifications.php`
on the live box — **0 means STOP**, the code is not there yet.

Migration: `profile-app/sql/2026-08-08-notification-dismiss.sql` (Ian runs live SQL).

**Why the order is not free, measured not theorised.** The migration narrows
`uq_notifications_target_unread` to add `AND dismissed_at IS NULL`. Postgres infers an
ON CONFLICT arbiter whose predicate is IMPLIED BY the clause, and implication runs one
way — so code emitting the OLD two-term clause matches nothing against the new index
and throws `42P10` on every hub push. Applied to dev2 while the serving checkout was
still on `main`, this killed every notification on the box instantly and **silently**
(`lg_notify_push` swallows its errors by contract). Repaired by reverting the index.

**Per-box oddity worth knowing:** dev2 currently has the `dismissed_at` COLUMN but the
OLD two-term index. That transitional state is deliberate and safe both ways — old code
emits two-term against a two-term index; new code emits three-term against it and also
works, since `A∧B∧C` implies `A∧B`. Only the narrowed index is exclusive.

Full reasoning in the migration header and `[[trap-on-conflict-arbiter-implication-direction]]`.

## Related, not flags

- **deploy-tooling branch (7/31, unmerged)**: flag/deploy REGISTER runbooks
  (deploy-symlink-couplings.md, live-divergences.md) + restore-sequence
  preconditions + gate check [0] — born from the 04:39 outage where a restore
  silently turned `LG_FOLLOWING_ROW_TOGGLES` off. Needs a verification pass +
  merge; owner wanted.
- The house pattern itself: docs/BACKLOG lines carry flag state per item; each
  config file carries the flag's whole story as comments; gates assert shipped
  defaults by READING them (never by assuming).
