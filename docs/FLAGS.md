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
| frontend-compose `enabled` | platform/config/frontend-compose.php (+ **box-local** frontend-compose.local.php — see below) | **false** | **ON** (via the .local.php, verified on the serve 8/16: `lg_fc_enabled()` true, route serves 184,627B to an allowed member) | OFF (no .local.php there) | Front-end compose/edit. Ian's item-5 'Do it' 8/15, light + dark. Gates 19 (OFF is a byte-identical no-op) + 35. ⚠️ ON for **all members** once flipped — the allow-list was deleted, so the flag is the only thing narrowing it |

### ⚠️ The `*.local.php` BOX-LOCAL override — a second way these flip, added 8/15

The table above says "flip by commit + pull". That is no longer the whole truth.
Two of these flags are ON on dev2 without any commit, via an **untracked,
gitignored** per-box file next to the tracked one:

| Flag | Tracked default | Box-local file (dev2 only) | Reader |
|---|---|---|---|
| frontend-compose | `false` | `platform/config/frontend-compose.local.php` | `lg_fc_enabled()` in the mu-plugin |
| back-pill | `false` | `platform/config/back-pill.local.php` | `bb-mirror/web/_chrome.php` |

**The shape**, and copy it rather than inventing a third: the reader loads the
tracked config first, then `@include`s `<name>.local.php` and lets it win only on
`array_key_exists('enabled')` + `=== true`. Unreadable or malformed → the tracked
value stands. `.gitignore` carries the glob `platform/config/*.local.php`.

**Why it exists:** an FPM pool env reaches FPM *only*, so wp-cli, WP-cron and the
gates read the opposite state from the serve — that split is what reddened gate 35
on a healthy box. It also writes a *tracked* file inside the serving checkout via
the pool symlinks, which dirties the one checkout that must only ever pull.

**LIVE IS PROTECTED BY ABSENCE, not by a check.** No code asks which box it is on;
live simply has no `.local.php`, so it takes the tracked default. That is exactly
why these files must never be committed — one commit would switch a member-facing
surface on for everyone.

**⚠️ ORDER, learned the hard way:** the READER merges and the serve pulls BEFORE
the `.local.php` is placed. Reversed on 8/15 — the file was created while nothing
read it, the pool env was removed in the same change, and compose went dark
(`/compose/` 404 to an allowed admin) until the reader landed.

## Runtime WP-option flags (flip by wp option update — PER BOX, no deploy)

| Option | Default | dev2 | live | State |
|---|---|---|---|---|
| `lgms_retraction_sweep` | absent=OFF | OFF | OFF | Phase 1 wave 1 (29fe64e): detection-only sweep; retraction stays the explicit script |
| `lgms_null_shadow_fix` | absent=OFF | OFF | OFF | Phase 1 wave 1: NULL patreon row stops shadowing the reader; measured blast radius = members 612/1768 (fix PROTECTS them) |
| `lgms_stripe_lifecycle` | absent=OFF | OFF | OFF | Phase 1 (0ffb32f): webhook-driven single-tier membership. OFF = no route/no read/no log (keeper-verified). ⚠️ INTERLOCK: refuses while `lgms_identity_gate` dark. FLIP ORDER live: identity_gate ON → lifecycle ON → allowlist governs WHO. ⚠️ SCOPE WIDENED 2026-08-15: this flag now also arms the allowlist fence in `Sync::customer()`, i.e. the ENTITLEMENT SWEEP — the road a redeemed GIFT takes to a role (billing app → `/sync-customer` → Arbiter) and the road `Tick` pass 2 takes every five minutes. Before that, the list guarded the webhook ONLY and a gift to an unlisted member let them in anyway. Flag OFF = the sweep behaves exactly as it always has. Gate #34d: tools/gates/stripe-testgroup-sweep-gate.php (26) |
| `lgms_stripe_lifecycle_allowlist` | absent=**CLOSED for everyone** | unset | unset | Soft-launch cohort (docs/STRIPE-SOFT-LAUNCH-ALLOWLIST.md): array of THIS box's WP user ids; only listed members transition, everyone else's events are 200-acknowledged + journaled `skipped: not in soft-launch cohort (uid=N)`, no membership change EITHER direction. Empty/absent/malformed = nobody (fail-safe, gated). Edit: wp-admin → Settings → LG Member Sync → **Stripe Test Group** tab, or `wp option update lgms_stripe_lifecycle_allowlist '[101,202]' --format=json`. Gate #34: test-soft-launch-allowlist.php (39) + e2e §6 (12) |
| `lgms_stripe_testgroup_pages` | absent=**OFF** | unset | unset | Unlocks the EXISTING member pages (join / gift / redeem / my-gifts / refund / welcome / regional) for the Stripe Test Group, per Ian 2026-08-14 (docs/STRIPE-TEST-VIA-EXISTING-PAGES.md). OFF/absent = those pages stay administrator-only exactly as today — the Test Group unlocks NOTHING. TWO locks: this flag AND `lgms_stripe_lifecycle_allowlist`; either one shut refuses everyone, and an absent/empty/malformed list is nobody. An administrator is never gated behind the list (Ian must not lock himself out of the surface he is building on). Read by the standalone membership-pages app, NOT the shortcodes — an nginx regex shadows those. Gate #34b: tools/gates/stripe-testgroup-pages-gate.php (54) |
| `lgms_stripe_lifecycle_allowlist_added` | absent | unset | unset | Dash bookkeeping only (uid→date added, feeds the cohort table's "date added" column). The lifecycle gate NEVER reads it; losing it costs display data only |
| `lgms_stripe_webhook_secret` | unset | unset | unset | Stripe dashboard webhook signing secret — set on live before lifecycle flip |
| `lgms_stripe_price_month` / `lgms_stripe_price_year` | unset | unset | unset | **ONE TIER, TWO CADENCES** (Ian 2026-08-15: "We need a monthly and a yearly price etc." — his Patreon shape, 5/mo + 60/yr). One option per cadence so neither can overwrite the other; both prices hang off the SAME product and grant the same membership, so the poller still needs no price logic. Leave one unset and that cadence is simply not offered. Set from **Settings → LG Member Sync → Stripe Price**. Gate #34c §6b |
| `lgms_stripe_price_id` (LEGACY) | unset | unset | unset | Superseded by the per-cadence options above; kept as a READ-ONLY fallback for the MONTHLY slot so a box configured before cadences existed keeps working. Nothing writes it any more, and a real monthly price wins over it. Originally: the single Stripe price NEW joins are sold (read by Wp\CheckoutRestController). **Set it from the dash — Settings → LG Member Sync → Stripe Price** (Ian 2026-08-15: "I'd like to be able to set the price. In the dash."). That control is the only supported writer: it creates the Stripe price, records it in our own `prices` table, and repoints this option as ONE action, because a price Stripe knows and we do not makes an existing subscriber VANISH from the join page's already-subscribed check (INNER JOIN through `prices`) and offers them a second subscription. Nothing back-fills it — the webhook never handles price.created. Changing it grandfathers everyone already subscribed. SANDBOX ONLY: the control refuses a live key. Ships UNSET on purpose — the number is Ian's. Gate #34c: tools/gates/stripe-price-control-gate.php (49) |
| `lgms_stripe_frozen` | (see poller) | — | — | Stripe ingest freeze — Phase 1 proper unfreezes; stripe seat owns |
| `lgms_poller_mail_enabled` etc. | (see poller settings) | — | — | Poller operational toggles; stripe seat owns |

## Flags on UNMERGED branches (tracked here so they don't get lost)

| Flag | Branch | State |
|---|---|---|
| `dismiss_instead_of_delete` (E4) | origin/notif-bridge | Ian approved merge 8/9; bounced for gate renumber, re-minted 22-25 and re-merged. Now listed in the member-facing table above — **read the ordering note below before flipping it** |
| `bell_follows_bb_subscriptions` (E4) | origin/notif-bridge | ❌ CLOSED by Ian 8/9, ships OFF. Listed above; the code stays so reversing the ruling needs no rediscovery |
| notif-read-seen flag (P0 4.1) | origin/recap-read-timer | 35/35 green; renumbering to gate 21; needs `## Decision to arm` before ARM (not before merge) |
| digest-images flag (P0 4.0) | origin/digest-images | ❌ WRONG PATTERN — documented as wp-config define (untracked on live, breaks deploy-by-pull). Must be reworked to tracked config BEFORE merge. Unowned |
| `LG_NOTIF_QUICKREPLY_ENABLED` (env `LG_BB_MIRROR_NOTIF_QUICKREPLY`) | origin/notif-quickreply-v2 | Tap-to-reply from a notification. Defined in `bb-mirror/config.php`, **default OFF**; read from **both** `getenv()` and `$_SERVER`, because the lane preview feeds it a `fastcgi_param` and that does not reach `getenv()` — a real bug caught on 7/31, not a hypothetical. **NOT ARMED ON THIS BOX** (verified 8/16: absent from every FPM pool and from /etc/nginx). OFF ships no bytes — `pwa.js` does not even request `notif-reply.js` — and the API 404s rather than merely going uncalled. Gate 52. Merges clean into main; awaiting keeper |

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
