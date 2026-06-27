# NOTIFICATIONS-AUDIT

> **Status:** AUDIT + RECOMMENDATIONS ONLY — no implementation. Read-only survey of how
> notifications + email are handled on the Looth platform today, toward (a) making it better,
> (b) giving users controls, (c) expanding which events notify.
> **Lane:** notifications-audit (branch `notif-audit`). **Box audited:** dev2 (`34.193.244.53`),
> WP `/var/www/dev`, repo `~/loothplatformv2` @ `7f4ccf2`. **Date:** 2026-06-27.
> Cross-refs: SYSTEM-MAP.md, HUB-RENDER-ARCHITECTURE-AUDIT.md, REPO-MANDATE.md.

---

## 0. Executive summary (read this first)

The platform has **three disconnected notification worlds**, and the one users actually see in
the header is **not** the one that fires on forum activity:

1. **profile-app in-app social system (REAL, live, the header bell/badges).** A working Postgres-
   backed store (`profile_app` DB) with a notification bell, DM messaging, and connections. It is
   **in-app only — zero email** — and it only notifies on **connection request / accept**. DMs do
   *not* notify (deliberate "no double-notify"), and nothing else writes to it.

2. **Legacy BuddyBoss notifications (STILL FIRING, invisibly).** Even though the forum is
   strangled to the custom Hub (bb-mirror), every Hub reply and new topic **replays the native
   BuddyBoss REST handler in-process**, which keeps writing `wp_bp_notifications`
   (`bbp_new_reply`, `bb_forums_subscribed_reply/_discussion`, `bb_new_mention`) — 66k rows,
   freshest yesterday — and would email via the active `bb_digest_email_notifications_hook` cron.
   These rows are written to a table **no current UI surface reads**, and on **live** the digest
   cron would send **real subscriber/mention email** that nobody designed for the Hub era.

3. **Transactional/membership email (poller, events, weekly digest).** A pile of `wp_mail()`
   sites (welcome, payment-failed, gifts, admin alerts), event reminders (via FluentCRM), and a
   weekly digest — all currently **gated off** on dev2 by two stacked `pre_wp_mail` mu-plugins
   (poller killswitch + dev-mail-containment → mailpit) and FluentSMTP `simulate_emails=yes`.

**The gap in one line:** the Hub (where the community actually lives) has **no notification surface
of its own** — reactions, replies-to-your-topic, and @mentions either notify *nobody useful* or
notify a *legacy table no one reads*; meanwhile the modern profile-app bell only knows about
connections, and **no user-facing notification preferences exist anywhere.**

---

## 1. Current-state inventory

| # | Surface | Tech / location | Events it knows about | Stores where | Delivery today | State |
|---|---------|-----------------|-----------------------|--------------|----------------|-------|
| 1 | **profile-app bell** | `/srv/profile-app` `Notifications.php`, route `/profile-api/v0/me-notifications` | `connection_request`, `connection_accept` (the `message` type exists but is **never written**) | PG `profile_app.notifications` (`is_read`/`read_at`) | **In-app only** (header bell + modal). No email. | **LIVE** |
| 2 | **profile-app DMs** | `Messaging.php`, `/me-messages`, `/me-thread` | DM sent (connections-only gate) | PG `profile_app.message_threads / messages / message_recipients` (per-recipient `unread_count`) | In-app **badge only** — no bell row, no email, no push | **LIVE** (42 native sends post-migration) |
| 3 | **profile-app connections** | `Connections.php`, `/me-connections` | request / accept (decline/cancel/block/disconnect: silent) | PG `profile_app.connections` (mutual, no follow) | Writes bell rows (#1) on request+accept | **LIVE** |
| 4 | **profile-app counts** | `me-social-counts` | aggregates unread msgs / pending reqs / unread notifs | (reads 1–3) | Drives 3 header badges, **browser-polled** (no push) | **LIVE** |
| 5 | **Legacy BuddyBoss notifications** | buddyboss-platform 2.20.0 (active), Hub reply/topic write path replays native REST | `bbp_new_reply`, `bb_forums_subscribed_reply`, `bb_forums_subscribed_discussion`, `bb_new_mention`, `bb_messages_new` | MySQL `wp_bp_notifications` (66k rows, fresh) + `wp_bb_notifications_subscriptions` | In-app BB rows (no Hub UI reads them) + **`bb_digest_email_notifications_hook` cron** (→ mailpit on dev) | **FIRING but orphaned** |
| 6 | **bb-mirror / Hub native** | `/srv/bb-mirror`, PG `forums` schema | reply, new topic, mark-seen/unread, subscription mirror | PG `forums.forum_read_state` (personal read-state), `forums.forum_subscription` (inert mirror) | **Notifies no one.** Read-state → client "NEW" markers only | **LIVE, notif-silent** |
| 7 | **Hub reactions / likes** | archive-poc `card-react.php` + `_reactions.php` (shared) | reaction/like on topic, reply, card, comment | PG `discovery.card_reactions` | **Notifies no one** — silent count only | **LIVE, notif-silent** |
| 8 | **WP core / BuddyBoss account mail** | BuddyBoss overrides `wp_new_user_notification`, password-reset templating | registration, password reset, comment moderation | n/a (transactional) | `wp_mail` → FluentSMTP/SES (live) / mailpit (dev) | **LIVE** |
| 9 | **Poller / membership mail** | plugin `lg-patreon-stripe-poller` v2.0.0 | welcome, payment-failed, trial-ending, gift codes, chargeback/admin/affiliate/onboard-collision alerts, sync report | n/a (transactional, recipients from `customers`) | `wp_mail` → **suppressed on dev** by killswitch; live = SES | **LIVE code, gated** |
| 10 | **Event reminders** | plugin `event-reminder-and-cleaner` v3.3.1 | event starting soon (datetime − lead mins) | FluentCRM scheduled **campaign** (list 4 / tag "Event Reminders") | FluentCRM mailer (→ mailpit on dev). Cleanup cron archives past events | **LIVE, FluentCRM-gated** |
| 11 | **Weekly digest** | plugin `lg-weekly-digest` v3.0.0 + `lg-weekly-email-bridge` (mu, render-only) | weekly curated issue | FluentCRM list 3 (or wp_mail fallback) | Cron `lg_wd_send_digest` — **`enabled=false` + `cron_mode=draft_and_notify`** → fires nothing today | **DOUBLE-OFF** |
| 12 | **FluentForms** | fluentform 6.1.20 | Form 38 (anon Hub posting), other forms | FF submissions table | Form 38 admin-notify **disabled** → sends nothing | **LIVE, notify off** |
| 13 | **Bug report** | mu-plugin `lg-bug-report.php`, REST `POST /wp-json/looth/v1/bug-report` | user-submitted bug | n/a | `wp_mail` → `ian.davlin@gmail.com` (→ mailpit on dev) | **LIVE** |
| 14 | **Abuse reports** | profile-app `api/v0/reports.php:46` | user abuse report | n/a | `@mail()` → Ian | **LIVE** |

---

## 2. Delivery + storage map

### 2.1 The `wp_mail` interception chain (the single most important plumbing fact)

Every `wp_mail()` on the box flows through this ordered `pre_wp_mail` gauntlet before any transport:

```
wp_mail()  [FluentSMTP overrides the pluggable, /plugins/fluent-smtp/fluent-smtp.php:48]
  │
  ├─ pre_wp_mail  priority 10  →  lg-poller-mail-killswitch.php  (mu-plugin, dev2 copy)
  │     • if call stack passes through /lg-patreon-stripe-poller/  → return false (SUPPRESS)
  │     • allowlist: header `X-LG-Poller-Intent` lets a mail through
  │       ⚠️ NO deployed mailer emits that header → allowlist is DEAD → ALL poller mail suppressed
  │
  ├─ pre_wp_mail  priority 99  →  lg-dev-mail-containment.php  (mu-plugin, dev2 only)
  │     • honors prior decision ($short !== null → return it, so pri-10 suppress still wins)
  │     • else: define FLUENTMAIL_SIMULATE_EMAILS=true + deliver via @mail() → sendmail shim → mailpit
  │     • ALWAYS returns true → never falls through to SES.  Env-gated: inert only if LG_ENV ∈ {live,prod,production}
  │       (fail-safe: unknown env → contain)
  │
  └─ [live only, both gates pass]  FluentSMTP body → Amazon SES (noreply@loothgroup.com, us-east-1, over HTTPS:443)
```

- **Sendmail shim:** `/usr/sbin/sendmail` → `/usr/local/bin/lg-sendmail` → `mailpit sendmail -S 127.0.0.1:1025`.
  PHP `sendmail_path = /usr/sbin/sendmail -t -i`. Mailpit runs as a systemd service (SMTP 1025, UI
  `/mailpit/` on 8025). **Mailpit DB lives in /tmp → cleared on reboot.**
- **FluentSMTP** default connection = **Amazon SES**; a "Mailpit (dev catcher)" SMTP connection also
  exists but is not default/fallback. `misc.simulate_emails = yes` on dev2.
- **iptables 25/465/587 REJECT cap:** NOT currently armed (and wouldn't matter — SES rides 443).
  The **only** real-send guard on dev2 today is the containment mu-plugin + simulate flag.

> **FRAGILITY (2026-06-25 incident root cause):** `simulate_emails` is a DB-only `wp_options` row.
> A DB reload from a live snapshot can flip it to `no`, at which point FluentSMTP → real SES. The
> durable mitigation is the **code-level** containment mu-plugin (`FLUENTMAIL_SIMULATE_EMAILS`
> constant + `pre_wp_mail` short-circuit), which survives DB reloads but **NOT** a file deletion or
> a `LG_ENV` mislabel. mu-plugins are **per-box copies** (not in the serve-from-git tree) — a clean
> live build has neither killswitch nor containment, by design; verify explicitly at cut.

### 2.2 No cron engine → no digests fire on dev2

`DISABLE_WP_CRON = true` in wp-config, and **no system driver** (empty crontabs, no systemd timer
runs `wp cron event run`). The WP cron queue is **dormant** — every event's `next_run` is stuck days
in the past. So **all scheduled/batched mail is untested on dev2**: BuddyBoss digest
(`bb_digest_email_notifications_hook`, 3h), FluentCRM tasks, FluentForms reports, weekly digest
(`lg_wd_send_digest`), poller tick (`lgms_poll_tick`, 5m). Only **synchronous request-time** mail
(bug report, registration, a live form submit) is exercised — and that lands in mailpit.

> **Implication for this audit:** any future digest/batch notification architecture must ship with a
> real cron driver (systemd timer calling `wp cron event run`, or a dedicated runner). There is no
> working scheduler today.

### 2.3 Storage map (where notification state actually lives)

| Store | Engine | Holds | Read by |
|-------|--------|-------|---------|
| `profile_app.notifications` | PG (`profile_app` DB) | the modern bell (connection events; `is_read`) | header bell + `me-social-counts` |
| `profile_app.message_threads / messages / message_recipients` | PG | DMs + per-recipient unread | messages modal + badge |
| `profile_app.connections` | PG | mutual connections (pending/accepted/blocked) | connections modal + badge |
| `wp_bp_notifications` (+ `_meta`) | MySQL | **legacy** BB forum/mention/message notifications (still written) | **nothing in current UI** |
| `wp_bb_notifications_subscriptions` | MySQL | BB topic/forum subscription registry (drives fan-out + digest mail) | BB digest cron |
| `forums.forum_read_state` | PG (`looth`/`forums`) | per-user last-read per topic | Hub "NEW" markers (client) |
| `forums.forum_subscription` | PG | read-only mirror of BB subscriptions | **inert** (drives nothing) |
| `discovery.card_reactions` | PG (`looth`/`discovery`) | reactions/likes on topic/reply/card/comment | SSR count chips only |
| `wp_usermeta` notification_* | MySQL | BuddyBoss per-user notification prefs (legacy) | BuddyBoss (orphaned re: Hub) |

**Three separate Postgres databases/schemas** (`profile_app`, `looth.forums`, `looth.discovery`) plus
**MySQL** all hold a piece of "notification-ish" state, and none of them is a single source of truth.

---

## 3. Current user controls + gaps

### 3.1 What controls exist today

- **profile-app:** **none.** No per-event toggles, no email opt-in/out, no digest cadence — there is
  no preferences surface at all. The "no double-notify" rule (DMs don't ring the bell) is hard-coded,
  not a user choice.
- **BuddyBoss (legacy):** a full notification-preferences component **does** exist
  (`wp_usermeta notification_*`, per-component email/web toggles, digest settings) — but it is
  **orphaned**: it governs the legacy `wp_bp_notifications`/BB-email path that the Hub UI doesn't
  surface, so users can't reach it through the current product and it controls notifications they
  never see.
- **FluentCRM lists:** members can subscribe/unsubscribe to **event reminders** (list 4) and the
  **weekly digest / non-member weekly** (lists 3 / 7) via one-click bento buttons + double-opt-in.
  This is the *only* real user-facing notification control on the platform today — and it's list
  membership, not per-event preference.
- **Poller / transactional:** no user controls (correctly — these are required transactional mails),
  but also no preference for the *optional* ones.

### 3.2 Gaps (events that SHOULD notify but don't, + broken/orphaned plumbing)

**Events with NO useful notification today:**
- **Reply to *your* topic / *your* reply** — fires only legacy `bbp_new_reply` into a table no UI
  reads; the Hub author sees nothing in-product.
- **@mention of you** — only legacy `bb_new_mention`, same dead-end. No in-app mention inbox.
- **Reaction / like on your post or comment** — `discovery.card_reactions` is completely silent;
  the author is never told.
- **New DM arrives** — only increments a badge visible on the recipient's *next page-load poll*; no
  bell row, no email, no push. Offline users get nothing until they return.
- **New connection / follower** — the *one* thing that works in-app (bell row), but **no email**, so
  again invisible to anyone not currently on the site.
- **Event / sponsor activity, new content in a followed topic/world, weekly recap** — only via the
  FluentCRM list path (opt-in lists), disconnected from the in-app bell.

**Broken / orphaned plumbing (cleanup opportunities):**
- `profile_app.notifications` **`message` type is dead code** — schema column + dedup index +
  `push()` branch built, never called.
- **No prune** — `bin/prune-notifications` doesn't exist, no cron; `notifications` grows unbounded
  (design promised 30-day retention).
- **Legacy BB digest cron is a LIVE landmine** — on a live box (containment self-disables) Hub
  replies/topics would emit **real** BB subscriber/mention/digest email that nobody designed for the
  Hub era. Either intentionally wire it or intentionally kill it before cut.
- **Killswitch allowlist is inert** (`X-LG-Poller-Intent` emitted by nothing) → suppresses 100% of
  poller mail, including the welcome/alert mails it was meant to let through.
- **Weekly digest double-off** (`enabled=false` + `draft_and_notify`); Form 38 notify off; the
  remembered poller option-flags (`lgms_poller_mail_enabled`, `lgms_stripe_frozen`, `Mail::send`
  gate) are **branch-only, not deployed** — don't rely on them as a safety gate.
- **`forums.forum_subscription` mirror is inert** — synced from BB but drives nothing; a natural
  hook point for a native Hub subscribe-notify feature.

---

## 4. Recommendations

Goal: one coherent notification system with a single in-app store, optional email/digest, and real
user controls — **reusing the profile-app social layer as the spine** (it already is the header bell
and already speaks the right `actor → recipient → type` shape), while **decommissioning the orphaned
legacy BB notification path** rather than carrying it to live.

### 4.1 Notification event taxonomy

A flat, namespaced event vocabulary (`domain.event`), each row already expressible in the existing
`notifications(user_uuid, actor_uuid, type, target_ref, …)` shape:

| Category | Event key | Actor → Recipient | Default channels |
|----------|-----------|-------------------|------------------|
| **Social** | `connection.request` | requester → addressee | in-app + email *(exists in-app)* |
| | `connection.accept` | accepter → requester | in-app *(exists)* |
| | `connection.follow` *(if follow added)* | follower → followee | in-app |
| **Messaging** | `message.new` | sender → recipient | in-app + email *(currently silent)* |
| **Forum / Hub** | `forum.reply_to_topic` | replier → topic author | in-app + email |
| | `forum.reply_to_reply` | replier → parent-reply author | in-app |
| | `forum.mention` | mentioner → mentioned | in-app + email |
| | `forum.subscribed_activity` | replier → topic subscribers | in-app / digest |
| **Reactions** | `reaction.on_post` | reactor → post author | in-app *(batched: "3 people reacted")* |
| | `reaction.on_comment` | reactor → comment author | in-app *(batched)* |
| **Content / events** | `event.reminder` | system → opted-in | email/digest *(exists via FluentCRM)* |
| | `event.new` *(opt)* | system → followers | digest |
| | `sponsor.activity` *(opt)* | system → segment | digest |
| | `content.weekly_digest` | system → subscribers | digest *(exists, off)* |
| **Account** | `account.welcome` | system → member | email *(exists, poller)* |
| | `account.payment_failed` / `trial_ending` / `gift` | system → member | email *(exists, poller)* |
| | `moderation.*` | system → user/admin | in-app + email |

Design notes: **reaction events MUST batch/coalesce** (use the existing `ON CONFLICT DO UPDATE`
dedup pattern — "N people reacted to your post" as one evolving row) or they will spam. Forum
subscribed-activity should default to **digest**, not per-event, to avoid the BB fan-out firehose.

### 4.2 User preferences model

A single matrix: **event-type × channel**, with sensible defaults and a global kill switch.

```
notification_prefs (
  user_uuid     uuid,
  event_key     text,          -- from the taxonomy (or a category wildcard, e.g. 'forum.*')
  channel       text,          -- 'in_app' | 'email' | 'digest'
  enabled       bool,
  PRIMARY KEY (user_uuid, event_key, channel)
)
```

- **Resolution:** most-specific wins — exact `event_key` row > category wildcard row > system
  default. Absent row = system default (so the table only stores *overrides* → stays small).
- **Channels:** `in_app` (always-on spine, the bell), `email` (immediate transactional/important),
  `digest` (rolled into a periodic email — daily or weekly). `off` = no row enabled for that pair.
- **Global controls:** a master "pause all email" toggle + a digest-cadence picker (off / daily /
  weekly) per user.
- **Reuse:** fold the existing FluentCRM list opt-ins (event reminders, weekly) into this matrix as
  the `digest`/`email` channel for `event.*` and `content.weekly_digest`, so there's one prefs UI
  rather than bento-buttons + (orphaned) BB settings + nothing-for-the-bell.
- **Migration of legacy prefs:** the orphaned BuddyBoss `wp_usermeta notification_*` values can seed
  initial `notification_prefs` rows for forum events (best-effort), then BB's prefs UI is retired.

### 4.3 Delivery architecture

```
            EVENT SOURCES                         SPINE                         CHANNELS
  ┌──────────────────────────────┐                                    ┌────────────────────┐
  │ profile-app: connect/accept  │                                    │ in-app  (bell)     │
  │ messaging: message.new       │                                    │  = notifications    │
  │ Hub reply.php: reply/mention │──┐                                 │    table (exists)   │
  │ reactions card-react.php     │  │   ┌────────────────────────┐    └────────────────────┘
  │ events / sponsor / weekly    │  ├──▶│ Notifications::push()  │──▶ apply prefs ┌─────────┐
  │ poller: account.* (txn)      │  │   │  (single ingest API,   │       per       │ email   │
  └──────────────────────────────┘  │   │   dedup + fan-out)     │    event×channel│ (SES)   │
                                     │   └────────────────────────┘                 └─────────┘
                                     │            │                          ┌─────────────────┐
   account.* stays a pure email      │            └────── digest queue ─────▶│ digest (cron →  │
   path (transactional, no prefs)    │                                       │ rollup email)   │
                                     └─ legacy wp_bp_notifications: RETIRE ───┘
```

Principles:
- **One ingest API** — extend profile-app `Notifications::push()` into the canonical sink for *all*
  in-app notifications (it already has the table, dedup, read-state, and the header bell wired). Hub
  and reactions call it instead of relying on the legacy BB side-effect.
- **In-app is the always-on spine**; email/digest are prefs-gated derivatives. A `push()` writes the
  bell row, then consults `notification_prefs` to decide immediate-email vs. digest-queue vs. nothing.
- **Email reuses the existing FluentSMTP/SES transport** (already configured) — no new mail infra.
  Keep the `pre_wp_mail` containment posture for dev; live just needs the env gate correct.
- **Digest** = a queue table drained by a real cron (which must be built — §2.2) into one rollup
  email per user per cadence; fold weekly-digest + event-reminders into it.
- **Retire the legacy BB notification path** — stop relying on `wp_bp_notifications` and disable
  `bb_digest_email_notifications_hook` so it can't surprise-send on live.

### 4.4 Phased plan

**Phase 0 — contain the live landmine (safety, do first).**
Decide and gate the legacy BuddyBoss digest/email at cutover: either disable
`bb_digest_email_notifications_hook` + BB notification email component, or consciously keep it. Fix
the killswitch allowlist or accept that all poller mail is off. Confirm `LG_ENV` gating + containment
mu-plugin presence on live. *(No new features — just make sure nothing unintended emails on cut.)*

**Phase 1 — make the existing bell complete + add prefs (in-app only, no new email).**
- Wire `message.new` to ring the bell (or keep "no double-notify" as a *user pref* default-off).
- Route Hub `reply.php` reply/mention and reaction events into `Notifications::push()` (new event
  types) — in-app only. Batch reactions.
- Ship `notification_prefs` + a minimal preferences UI (in-app on/off per category). Add the missing
  **prune cron** and a cron driver.
- Build a Hub-native bell read (or confirm the shared header bell renders on Hub — it currently
  feeds `notif_unread = null`).

**Phase 2 — email channel + digest.**
- Add the email channel gated by prefs (reuse SES). Start with high-value immediate emails:
  `connection.request`, `forum.reply_to_topic`, `forum.mention`, `message.new`.
- Build the digest queue + rollup cron; fold weekly-digest + event-reminders into the unified prefs
  so there's one place users manage everything. Retire the orphaned BB prefs UI + FluentCRM bento
  buttons (migrate list memberships into `notification_prefs`).

**Phase 3 — expand events + polish.**
- Add `connection.follow` (if a follow model lands), `event.new`/`sponsor.activity` opt-ins,
  followed-topic activity digests.
- Optional: web-push / realtime (replace browser-poll of `me-social-counts`).
- Decommission `wp_bp_notifications` writes entirely; stop replaying BB notification side-effects in
  `reply.php` (keep only the counts/sync hooks it needs).

---

## Appendix — key file references

- **profile-app:** `/srv/profile-app/api/v0/{me-notifications,me-messages,me-thread,me-connections,me-social-counts}.php`;
  `src/{Notifications,Messaging,Connections}.php`; schema `sql/2026-05-30-social-layer.sql`
  (comment says "STUB — NOT APPLIED" but it **is** applied + populated). UI `/srv/lg-shared/social-modals.js`,
  `site-header.php`. nginx `/etc/nginx/snippets/strangler-profile-app.conf:173-180`.
- **Hub / bb-mirror:** `/srv/bb-mirror/api/v0/{reply,topic,mark-seen,unread}.php`; render `web/forums/_*.php`;
  reactions `/srv/archive-poc/api/v0/{card-react,_reactions}.php`; PG `looth` schemas `forums` + `discovery`.
- **Legacy BB:** buddyboss-platform 2.20.0 active; `wp_bp_notifications` (66k rows, fresh),
  `wp_bb_notifications_subscriptions`; reply replay in `reply.php` → `POST /buddyboss/v1/reply`.
- **Mail plumbing:** `/var/www/dev/wp-content/mu-plugins/{lg-poller-mail-killswitch,lg-dev-mail-containment,lg-bug-report,lg-weekly-email-bridge,lg-event-reminders}.php`;
  FluentSMTP `plugins/fluent-smtp` (SES, `simulate_emails=yes`); sendmail shim → mailpit.
- **Transactional senders:** `plugins/lg-patreon-stripe-poller` (WelcomeMailer, EventHandler, AdminAlerts,
  RestController, GiftMailer); `plugins/event-reminder-and-cleaner` (FluentCRM campaigns);
  `plugins/lg-weekly-digest` (`lg_wd_send_digest`, disabled).
