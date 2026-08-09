# Keeper handoff — 2026-08-09

**live = dev2 = main = `c2c3b72`.** Nothing undeployed, nothing half-migrated.
Six lanes seated. Written after a long session; read §1 and §2 before touching
anything.

---

## 1. WHAT IS OWED TO IAN — start here

| # | Owed | Where it sits |
|---|---|---|
| 1 | **Stripe orphan retraction** — staged, dev2-rehearsed, NOT run | `lg-patreon-stripe-poller/deploy/remediation/ORPHAN-REVOKE-RUNBOOK.md` |
| 2 | **3 over-tiered members** — held for his PER-MEMBER call | same runbook; blast radius re-verified as 10, not 3 |
| 3 | **hub-seo-landing screenshots** — he rules before the flag flips | lane in flight |
| 4 | **Slug rulings** — 6 decisions | `/footer-mockups/slug-rulings.html` |
| 5 | **anon-mobile-dash mock** — merges dark either way | `/footer-mockups/anon-mobile-dash/` |
| 6 | **`sudo rm /var/www/dev/footer-mockups`** on live | one command, closes S2 |
| 7 | **Submit sitemap in Search Console** | `https://loothgroup.com/sitemap.xml` |
| 8 | **Drop the sweep backup table** ~Monday | `wp_lg_group_unsub_20260808` — it IS the rollback, do not drop early |

Ian's rulings 1-8 are in `docs/IAN-RULINGS-2026-08-03.md`. **Quote them in commit
bodies.** Ruling 4's original premise was keeper's error and is corrected in place —
read the whole entry, not the heading.

---

## 2. MY ERRORS THIS SESSION — so they are not repeated

1. **Sitemapped 1,335 URLs without looking at the rendered page.** I read the markup
   ("no bbpress, has hub.css"), called it fine, and fed Google the *legacy layout*
   page Ian hates. Markup is not a screenshot. `hub-seo-landing` is cleaning it up.
2. **Force-pushed after the serving checkout had pulled** → dev2 diverged, recovered
   with `git reset --hard origin/main`. Amend BEFORE anything pulls.
3. **The fleet manifest destroyed itself.** The 5-min cron ran post-reboot, saw zero
   sessions, wrote an empty file — killing the very record respawn needed. Fixed
   (`e64ab2e`): an empty reading never overwrites.
4. **Twice waved off the craft-gate red as furniture.** It was a real 107KB avatar on
   the anon finder. Ten minutes of attention → 3KB. A permanent red hides regressions.
5. **Claimed FluentSMTP logging was broken.** I searched `%fsmtp%`; the table is
   `wp_fsmpt_email_logs` (transposition in the plugin's own constant). It had 5,530
   rows all along.
6. **Told Ian to `tmux attach`.** He dislikes tmux — drive lanes from the keeper chat.

---

## 3. SHIPPED TO LIVE TODAY

- **Follow roundup general release** (`fd0d196`) — all members, **daily** default,
  watched by cron (`tools/roundup/watch-roundup.sh`, 12:20 UTC).
- **Legacy group email DEAD** — Ian deactivated `bp-auto-group-join`, then swept
  9,297 subscriptions to `status=0`. Regionals untouched (DMV verified 365).
- **P0: participation no longer unsubscribes you** (`10ea816`) — our composer omitted
  a field BuddyBoss reads, so BB read absence as "untick" and removed the follow.
  Eroding the 381 followers since June. Fixed + red-first gated on both routes.
- **Craft gate GREEN** — avatar through the real resizer, 107KB → 3KB.
- **1,335 discussions in the sitemap** (`e9ddc28`) — they were invisible to Google.
- **Stripe Phase 0** (`fc9b5cc`, `c2c3b72`) — email-keyed minter gated OFF, tick log
  unblinded, tick-log public exposure closed before it reached live.
- **Hub "Newest" sort** — was ordering by last activity, i.e. a second Trending.
- **Digest bylines → profiles**, not the legacy archive.

---

## 4. LANES (6 seats, cap re-measured at 6 on this 15.8GB box)

| Lane | State | Doing |
|---|---|---|
| `hub-seo-landing` | WORKING | **Ian's live priority.** Make `/hub/<f>/<t>/` render hub+modal server-side, then DELETE `_single-topic.php` |
| `mobile-bugs` | WORKING | 4.4 DM tray, 4.3 3-dots, 3.7 embeds — one phone harness |
| `frontend-compose` | WORKING | Ruling 3, Option A single screen |
| `notif-bridge` | parked, 7 unmerged | delete=dismiss migration + bell delivery gap |
| `one-mailer` | parked, 1 unmerged | composer follow controls, scope doc |
| `stripe-build` | parked | Phase 0 done; **Ian drives Stripe from the keeper chat** |
| `emoji-picker-build` | PARKED (seat taken) | 4 commits pushed; Variant 1 ruled |

**Merge queue, all Ian-authorized 8/8 ("All four"):** `recap-read-timer` (5),
`digest-images` (3), `anon-mobile-dash` (3). Each needs review + gates + dev2
verification. `digest-images` review found its flag documented as a `wp-config.php`
define — **untracked on live, violates deploy-by-pull**; make it a tracked config
like `platform/config/post-follow.php` before merging.

---

## 5. TRAPS LEARNED TODAY

- **`wp_fsmpt_email_logs`** is the delivery record. `` `to` `` is reserved AND
  serialized (LIKE works, joins on it lie). `created_at` is SITE-LOCAL, not UTC.
- **`sudo wp --allow-root` breaks Postgres** — peer auth, `root` has no role. Run as
  `looth-dev`, as `lg-wp-cron.service` does.
- **`wp cron event run lg_fd_send` sends nothing outside 08:00 site-local** —
  `lg_fd_tick()` gates on the hour. Off-schedule: `lg_fd_flush('daily', 0)`.
- **dev2 cannot reproduce activity-vs-creation bugs**: every dev2 topic has
  `created_at == last_active_at` because nobody replies here.
- **New mu-plugins need per-file symlinks** in the same window as the pull. Other
  trees ride directory symlinks (`/srv/*`) and arrive with the pull alone.
- **nginx allow-lists sitemap section names** — `strangler-archive-poc.conf:210`.
- **Ignore Buck's surfaces** (`/home/buck`, his vhost, `buck-*` branches).
- **Reboots are routine.** `bash tools/lanes/respawn-fleet.sh` resumes lanes WITH
  their conversations (transcripts are on disk; `claude --continue`).

---

## 6. THE EMAIL ARCHITECTURE, as Ian ruled it

Vocabulary is pinned in `docs/EMAIL-GLOSSARY.md` — **"digest" means the Weekly
Digest and nothing else.**

- **Weekly Digest** — Ian's editorial email, FluentCRM, ~1,860 recipients.
- **Weekly Recap** — a SECTION inside it, scoped to **bell-only types** (ruling 7):
  types with no email channel of their own. Discussions have the roundup; mentions
  and connections have BB natives. Kills the dedup problem by construction. Parked
  behind the notification bridge.
- **Follow roundup** — batched follow-notification mail. Instant / daily / weekly,
  **daily default**. Live for all members.
- **Post→follow** (ruling 6): composer carries both controls — 🔔 bell TICKED by
  default, ✉ email present but UNTICKED.

⚠️ Bell-as-default makes the **notification bridge** the default delivery path for
"your question was answered" — which is why `notif-bridge` is top-order.

---

## 7. STILL UNOWNED

Notification quick-reply (9 commits on `origin/notif-quickreply`), inline hub video,
front-page weekly email, notification filters, advanced search, `/v2/` anon exposure
(S1, blocked on the untracked live vhost), dev2 vhost drift (S3), and ~10 stale
branches needing merged-or-dead triage — **do not archive on age**, several may be
stalled work (`connections-backfill` alone is 23 commits).

`docs/BACKLOG.md` now carries an owner on every line: a lane, MERGED, or UNOWNED.
