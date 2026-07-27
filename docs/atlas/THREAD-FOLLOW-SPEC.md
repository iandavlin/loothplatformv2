# THREAD-FOLLOW-SPEC — opt-in following, coalesced follow-ups, one follow state everywhere

> **Status: SPEC + MOCK, Ian-gated — no build until Ian approves both.**
> Lane: threadfollow-spec (dev1, docs+mock only). Branch `threadfollow-spec`.
> **REVISED 2026-07-27 — Ian reversed Ruling 1: following is OPT-IN ONLY.** See §0.0 for what
> changed and §8 for the two decisions still sitting with Ian.
> Mock frames: `footer-mockups/threadfollow-notif-panel/` (see §5 — the existing 4 frames now
> under-represent the design).
> Cross-refs: NOTIFICATIONS-AUDIT.md, DISCUSSION-SURFACE-CANON.md, OPERATOR.md §4–5,
> REPO-MANDATE.md.
> **Everything below cites current `main` (@c10df43).** The prior revision cited @aad6e3f; main
> has moved and several line numbers with it — re-derived throughout.

---

## 0.0 What the reversal changed (read this first if you read the old version)

Ian, 2026-07-27: *"I dont want any auto subscribes. Has to be opt in for everything. I think a
button on the card and in the controls inside the open modal. Maybe up by s m l xl or something."*

| Old ruling | Now |
|---|---|
| 1. Involvement auto-subscribes (author / reply / mention) | **DEAD.** Nothing subscribes you without a deliberate click. |
| 2. One coalesced counting row per thread | unchanged |
| 3. Opt-out = per-row "Mute" in the notifications panel | **rewritten** — mute-as-a-concept is gone; the row carries the same *Unfollow* state as everywhere else (§2.4) |
| 4. Remove-my-mention = unlink **+ unfollow** | **rewritten to unlink-only** (§2.6) |
| 5. Store = BB/bbPress native subscriptions | unchanged (but written only on an explicit click) |
| 6. Per-event BB email permanently OFF, digest is the only email | **CONTRADICTED by Ian's own 7/26 mail — §8.1, his call, not decided here** |
| 7. Deep links per the notify-bridge contract | unchanged |
| 8. One panel implementation, desktop + mobile | unchanged, and now extends to three surfaces (§2.2) |
| — | **NEW §8.2: the 1,519 subscriptions live already has.** Opt-in is a rule for NEW follows; it says nothing about existing ones. Options + consequences for Ian. |

**The reversal is cheap, and here is why** (the single most important consequence, easy to miss):
removing auto-subscribe does **not** make anyone go dark. Three of the bell's four notification
rungs are authorship/mention-based and have nothing to do with subscriptions —
`forum.reply_to_topic` (someone replied to *your* topic), `forum.reply_to_reply` (…to *your*
reply), `forum.mention` (someone named you). Those fire today, unchanged, for people who hold
zero subscriptions (notify-bridge.php:170-236). **Following only ever adds the fourth, least
specific rung: "a thread I chose to watch but am not otherwise part of."** So a topic author still
hears about replies to their topic without following it; the opt-in toggle is for bystanders who
want to watch. That is the whole scope of what Ruling 1's reversal touches.

---

## 0. The Ian-CONFIRMED lifecycle (the rulings, one screen)

1. **Following is an EXPLICIT TOGGLE — opt-in only, never implicit.** Creating a topic, replying
   in it, and being @mentioned in it all subscribe you to **nothing**. The toggle lives in two
   places Ian named plus one parity surface, all driving one state (§2.2):
   **(a)** the feed **CARD**, as a peer of the Save star;
   **(b)** the open **DISCUSSION MODAL**, in the header control cluster beside the S/M/L/XL size
   button; **(c)** the mobile discussion **SHEET** header — the ≤640 equivalent of (b), because
   the S/M/L/XL cluster is desktop-only (§2.2c).
2. **Follow-ups are ONE coalesced counting row per thread** in the bell — never one row per
   reply. "Alice and 3 others replied in a discussion you follow", count climbing, link always
   pointing at the newest reply.
3. **The notifications row carries the SAME follow state**, expressed as **Unfollow**, in a
   per-row ⋯ control. It is a convenience and an escape hatch, not the primary control, and it is
   **not a separate "mute" concept** — one state, four places to change it (§2.4).
4. **Remove-my-mention = unlink ONLY.** The stored mention anchor becomes plain text. It does
   **not** unfollow, because a mention never subscribed you and a deliberate follow is not the
   system's to cancel (§2.6).
5. **Store = BB/bbPress NATIVE topic subscriptions.** Follow writes the native subscription,
   unfollow writes the native unsubscribe. No new subscription store — the existing BB registry
   + its already-built PG mirror are the truth.
6. **Email posture: OPEN — see §8.1.** The old ruling ("all per-event BB email permanently off,
   weekly digest only") is contradicted by the per-event email Ian received and wanted on 7/26.
   §8.1 shows why the two positions are *narrower* than they look and lays out the resolutions.
   **Not decided here.**
7. **Deep links per the existing notify-bridge contract** — `/hub/?topic=<forum>/<topic>[&reply=<id>]`,
   nothing new.
8. **Parity:** one implementation per surface, CSS-responsive, both themes, both widths.
9. **Existing subscriptions: OPEN — see §8.2.** Live holds 1,519 active topic + 46 forum
   (+ 12,948 group) subscriptions today. Opt-in governs new follows; what happens to those is
   Ian's call, with options and member-visible consequences laid out.

---

## 1. Current machinery (what already exists — read before touching anything)

### 1.1 The bell pipeline (live, one writer, one store)

```
WRITE PATHS                          BRIDGE                    STORE                    UI
reply.php:402-405 ─────────┐
(mobile-sheet replies)     │   lg-shared/notify-bridge.php    profile-app               site-header.php:872-888
bb-mirror-sync.php:225-231 ├─▶ lg_notify_on_reply():170  ──▶  internal-notify.php  ──▶  (#lg-notif-modal)
(native REST replies; G8)  │   lg_notify_on_topic():238      :97 pushHubEvent           social-modals.js:231
bb-mirror-sync.php:175-180 │   lg_notify_on_reaction():266   Notifications.php:105      (loadNotifications)
(new topics)               │   (dedup: mention > reply_to_   (ON CONFLICT coalesce
card-react.php ────────────┘    reply > reply_to_topic,       :122-133, actor_count,
                                one row per person/event)     unread-scoped)
```

- **Dedup rule** (notify-bridge.php:161-182): exactly ONE notification per person per event; the
  most specific type wins (mention → reply_to_reply → reply_to_topic); never your own action.
  The `$notified` set (notify-bridge.php:177) is the mechanism — §2.3 extends it with a fourth,
  least-specific rung.
- **Coalescing** (Notifications.php:94-133): unique index on
  `(user_uuid, type, target_kind, target_id, COALESCE(anchor_id,0))` scoped `WHERE is_read=false`
  (sql/2026-07-12-notifications-hub-events.sql:64-74). A second actor merges → `actor_count+1`,
  latest actor wins, `target_url` re-pointed. Once READ, the next event rings a FRESH row
  (Notifications.php:99-101). **`forum.reply_to_topic` with `anchor_id=0` already demonstrates
  the exact one-row-per-topic counting shape** (notify-bridge.php:213-223) — the follow-up row is
  the same shape with a different recipient set.
- **Type vocabulary**: PHP allowlist `Notifications::HUB_TYPES` (Notifications.php:38-43) + DB
  CHECK `notifications_type_check` (sql/2026-07-12-notifications-hub-events.sql:49-53). Both must
  widen for a new type — a 2-line delta, designed for this.
- **Ingest door**: internal-notify.php — loopback + shared secret (:47-49), wp_id→uuid bridge
  (:78-87), unbridged recipients skipped silently (:91-94), site-relative `target_url` enforced.
- **Read/delete contract** (me-notifications.php:10-15): GET list, POST `read`/`read_all`,
  DELETE `?id=` / `?all=1`. Click-through marks the ONE row read; per-row × is a real
  server-side delete (social-modals.js:250-254).
- **Panel render**: `notifText()` social-modals.js:173-186 (the copy switch),
  `renderNotifItem()` :197-212, `.lg-notif__clear` × button :209 styled at
  site-header.css:560-566, panel markup site-header.php:872-888.
- **Retention**: 30-day prune by age regardless of read state (Notifications.php:273-287).

### 1.2 The subscription machinery (exists, INERT for the Hub — but NOT inert for email)

- **Native store**: BuddyBoss Platform 2.20 forum subscriptions — MySQL
  `wp_bb_notifications_subscriptions` (columns verified on live 2026-07-27:
  `id, blog_id, user_id, type, item_id, secondary_item_id, status, date_recorded`;
  `type ∈ {forum, topic, group}`, `status=1` = active). The bbPress-compat write API
  (`bbp_add_user_subscription` / `bbp_remove_user_subscription` / `bbp_get_topic_subscribers`)
  fronts it. ⚠️ exact function/action names on the deployed BB build remain a **build-time dev2
  verify** (§7).
- **Site settings** (live `wp_options`, verified 2026-07-27): `_bbp_enable_subscriptions = 1`,
  `bb_enable_group_subscriptions = 1`. Subscriptions are switched ON at the platform level today.
- **Mirror**: BB's subscribe/unsubscribe UI handler action is already synced —
  bb-mirror-sync.php:324-329 (`bbp_subscriptions_handler`) → _sync.php:103-125 → PG
  `forums.forum_subscription` (schema.pg.sql:209-217, PK `(user_id, target_kind, target_id)`).
- **Nothing in the Hub reads any of it** — but the legacy BB email path does, every day (§1.3).
  "Inert" was the previous revision's word and it was wrong about email.

### 1.3 The email path — CORRECTED (the previous revision got the trigger wrong)

The prior revision blamed `lg-wp-cron.timer` for arming a dormant landmine. That is right for the
**digest** and **wrong for the per-event mail**, which is synchronous and has never stopped.
Verified end-to-end on live by the forum-email-audit lane (2026-07-26, read-only):

```
Hub composer → POST /wp-json/buddyboss/v1/topics  (rest_base set in bb-mirror/web/_chrome.php)
  → a REAL post_type=topic row → bbp_new_topic
  → bbp_notify_forum_subscribers            (bp-forums/core/actions.php:221, priority 9999)
  → bb_send_notifications_to_subscribers()  (bp-forums/common/functions.php:1434)
  → BP_Forums_Notification::bb_send_forums_subscribed_discussion()   (…:862)
       author excluded; per-user `bb_is_notification_enabled` gate
  → bp_send_email('bbp-new-forum-topic') :957 → bp-email post ID 64928
       "[{{{site.name}}}] New discussion: {{discussion.title}}"
  → wp_mail → FluentSMTP → Amazon SES us-east-1        ← SYNCHRONOUS with the POST (~1s)
```

Two distinct event classes ride two distinct subscription types — **this distinction is the whole
of §8.1 and it was collapsed in the previous revision**:

| Class | Subscription type | Trigger | Frequency shape |
|---|---|---|---|
| **"New discussion"** — a topic was started in a forum you follow | `type='forum'` (46 rows, 38 users) | `bbp_new_topic`, synchronous | rare — bounded by how often anyone starts a thread |
| **"New reply"** — a reply landed in a topic you follow | `type='topic'` (1,519 rows, 383 users) | `bbp_new_reply` | the high-frequency one — this is what Ruling 6 was written to stop |

If the forum **is** group-linked the code takes the GROUP-subscriber branch instead (template
64927) — `bbp_get_forum_group_ids` decides, and the group population is 12,948 rows / 1,853 users.
Check it before predicting blast radius for anything.

**Measured live volume** (`wp_fsmpt_email_logs`, 14-day window ending 2026-07-27): 31 discussion/
reply emails total across 7 days, alongside ~1,795-recipient weekly digests. The two "New
discussion:" sends in the window went to 5 recipients each. Per-event discussion mail today is
**small**, not a flood — the flood risk was always in *auto-subscribing everyone*, which Ruling 1's
reversal now prevents at the source. Retention note: FluentSMTP `log_saved_interval_days=14`, so an
empty older window means retention, not silence; for older history use `wp_bp_notifications` where
`component_action='bb_forums_subscribed_discussion'`.

### 1.4 The deep-link + surface contract (unchanged, cited for completeness)

- URL shape: `/hub/?topic=<forum-slug>/<topic-slug>[&reply=<id>]` — built by
  notify-bridge.php:45-56, encoded like forums.js `shareUrl()`.
- Router: forums.js §4f — desktop ≥641 opens the §4e dmodal, ≤640 the `#looth-rep-sheet` via
  `lgOpenTopicMobile`; `&reply=` anchors + highlights the exact reply.
- Panel: ONE `lg-social-modal` drawer for both surfaces — right-side 400px on desktop
  (site-header.css:452-460), full-width ≤480 (:743-745). Dark theming injected by
  webroot/app-settings.js:255-268 under `html[data-lguser-theme="dark"]`.

### 1.5 The control cluster Ian pointed at — CORRECTED

The reversal brief cited forums.js:218-244 (the `--lg-read-scale` text-size pill, a 3-state
Normal/Large/Larger cycle) as the control to sit beside. **That is the wrong control**, on two
counts, and the build lane must not aim at it:

1. **It is not in the modal.** It was a sort-bar pill, and it was **RETIRED 2026-06-10**
   (bespoke-cutover; Ian: "the header GEAR is the only page-state control zone") —
   `hub_render_view_toggles()` is now an empty function (_filter-rail.php:103-114) and
   hub-polish.js additionally CSS-hides `.feed-text-toggle` at :4387. The forums.js handler is
   null-guarded dead code driving a button nothing emits. Reading size now lives in the
   settings-gear LGSettings panel (webroot/app-settings.js).
2. **The real "s m l xl" is the MODAL SIZE control**, and it renders those exact four letters —
   `.lg-dmodal__size` in the modal header (forums.js:4216-4218 markup;
   `SIZES = ['s','m','l','xl']` :4232; `textContent = sz.toUpperCase()` :4238; cycle handler
   :4241). Ian's "up by s m l xl" is a literal description of what he is looking at. **It is
   already 4-state** — the brief's "if it should become 4 states that is a separate question" is
   moot; only the stale code comment at :4231 ("3 panel sizes (Ian): S / M / L") is out of date,
   which is worth a one-line fix but is not this lane's business.

So the modal-side follow control is a **peer of `.lg-dmodal__size` in `.lg-dmodal__head`** — §2.2b.

---

## 2. The spec

### 2.1 One follow state, one verb, one store

```php
lg_follow_set(int $user_id, int $topic_id, bool $following): void   // lg-shared, rides notify-bridge.php
```

- Writes the **native** subscription (bbp-compat add/remove → `wp_bb_notifications_subscriptions`)
  AND dispatches the mirror explicitly:
  `bb_mirror_sync_dispatch('subscription', $topic_id, $following ? 'subscribe' : 'unsubscribe', ['user_id' => $user_id])`.
  Explicit dispatch is required — `bbp_subscriptions_handler` (bb-mirror-sync.php:324) is the
  **UI form-handler** action and does NOT fire on programmatic writes.
- **Idempotent** and **fire-and-forget** — same contract as the bell (notify-bridge.php:25-27).
- **Called from exactly one place: the §2.5 endpoint, on an authenticated user's own click.**
  There are no other call sites. That is the enforcement of Ruling 1 — not a policy note, a
  structural fact: no code path from `bbp_new_topic`, `bbp_new_reply`, reply.php, or the mention
  legs reaches this helper. **A build lane adding one is a spec violation.**

The verb is **Follow / Following**, everywhere, in copy and in code. "Mute", "Subscribe", and
"Watch" do not appear in the UI — a single vocabulary is what makes one state legible across four
surfaces.

### 2.2 Where the toggle lives

#### (a) The feed CARD — peer of the Save star

Ian: *"a button on the card."* The exact precedent already exists and should be copied rather
than reinvented: `.fc-save`, the per-topic per-user star, is server-rendered inert then
batch-hydrated from the viewer's real state.

- **Markup**: a new `feed_follow_btn(int $topicId)` beside `feed_save_btn()` in the topic card's
  `.fc-actions` row (_feed.php:1617-1621), living in _reply-render.php next to `feed_save_btn()`
  (:666) so every card-rendering partial can emit it. Shape mirrors the star exactly:
  `<button class="fc-follow" data-follow data-topic-id="N" aria-pressed="false" …>` + icon +
  `<span class="fc-follow__lbl">Follow</span>`.
- **Hydration**: the fc-save module (forums.js:3767-3841) is the template, verbatim in structure —
  server renders inert, one **batch** GET resolves auth + nonce + the viewer's following-set for
  every card on screen, `MutationObserver` re-syncs on filter swap and infinite-scroll appends,
  click is optimistic with revert-on-failure, `stopPropagation` so it never opens the thread.
  **The batch read is why §2.5's GET takes a topic LIST, not one id** — a per-card single-id GET
  would be one request per card.
- **Anon**: no nonce → button stays inert and is CSS-hidden, exactly as
  `body.fc-save-anon .fc-save { display:none }` (forums.css:585, :4123).
- **Topic cards only in v1.** Content cards (managed CPTs — the `.fc-actions` block at
  _feed.php:1482) have comments, not topic subscriptions. "Follow this article's comments" is a
  coherent extension and explicitly **out of scope** here (§8.3 q4).
- ⚠️ **Design risk, flagged not hidden**: `.fc-actions` already carries reactions + Like/replies/
  Share + Save + Share + expand. This is a 6th control in a tight row, and `.fc-share` is
  desktop-only by CSS (forums.css:600 base-hidden, shown ≥641) while mobile cards use
  hub-polish.js's own `.lg-act-*` bar. **Whether the card control renders on mobile cards, and if
  so in which bar, is a real design question for Ian** — §8.3 q1. It does not block the desktop
  card or either modal surface.

#### (b) The DISCUSSION MODAL header — peer of S/M/L/XL

Ian: *"in the controls inside the open modal. Maybe up by s m l xl."* Literal:

```js
// forums.js ensure(), currently :4215-4220
'<header class="lg-dmodal__head">' +
  '<h2 class="lg-dmodal__title"></h2>' +
  '<button type="button" class="lg-dmodal__follow" aria-pressed="false" ' +
          'aria-label="Follow this discussion" title="Follow this discussion"></button>' +   // NEW
  '<button type="button" class="lg-dmodal__size" aria-label="Modal size" title="Modal size"></button>' +
  '<button type="button" class="lg-dmodal__x" data-dm-close aria-label="Close">&times;</button>' +
'</header>'
```

- Follow sits **before** size, so the cluster reads *[title] … [Follow] [M] [×]* — the destructive/
  dismissive control stays rightmost, and the two state controls group together.
- State is set when the modal is populated (the same place the title is set, forums.js:4354) from
  the batch already fetched for the cards, falling back to a single-topic read if the modal was
  deep-linked into cold (`/hub/?topic=…` with no feed behind it).
- Same optimistic-toggle handler as the card — one delegated listener matching
  `.fc-follow, .lg-dmodal__follow, .lrs-follow`, one `setState()`.

#### (c) The MOBILE SHEET header — the ≤640 parity surface (NOT optional)

The S/M/L/XL cluster **is desktop-only**: ≤640 the router opens `#looth-rep-sheet`, whose header is
`<div class="lrs-hd"><span class="lrs-t"></span><button class="lrs-x" …>×</button></div>`
(hub-polish.js:3628) — title + close, no size control. Ian's instruction has no literal target on
mobile, so it needs a decision rather than a guess, and Ruling 8 (parity) forbids shipping the
control desktop-only. **Spec'd: a `.lrs-follow` button between `.lrs-t` and `.lrs-x`**, same state,
same handler, ≥44px touch target. Flagged for Ian in §8.3 q2 since he only named two surfaces.

### 2.3 Follow-up fan-out: `forum.followed_topic`, ONE counting row per thread

New event type `forum.followed_topic` — added to `Notifications::HUB_TYPES`
(Notifications.php:38-43) and the DB CHECK (migration widens
sql/2026-07-12-notifications-hub-events.sql:49-53 by one value; the coalescing unique index
:64-74 needs **no change**).

New leg **4** in `lg_notify_on_reply` (after :213-223), reusing the `$notified` dedup set:

```
4. Everyone SUBSCRIBED to the topic, minus everyone already rung (mention,
   parent-reply author, topic author) and the replier:
     type        = forum.followed_topic
     target_kind = 'topic', target_id = topic_id
     anchor_id   = 0            ← NULL in the dedup key → ONE row per topic per user
     target_url  = lg_notify_topic_url(topic_id, reply_id)   ← newest reply, re-pointed on coalesce
```

- Coalescing, counting, read-resets-the-row, prune — **all inherited unchanged** from the existing
  `pushHubEvent` machinery (§1.1). This is deliberately the `reply_to_topic` shape fanned to
  followers instead of the author.
- Subscriber read: `bbp_get_topic_subscribers($topic_id)` on the WP pool. NOT the PG mirror — the
  native store is the truth (Ruling 5); the mirror serves PG-side reads like the digest recap.
- **Fan-out is now bounded by opt-in.** Under the old auto-subscribe rule every participant became
  a recipient and the subscriber list grew with the thread; under opt-in it grows only when
  someone chooses. The batch-POST contingency stays in §7 but is now unlikely to be needed.
- Bell copy (social-modals.js `notifText`, :173-186, new case): `forum.followed_topic` →
  `notifActors(n) + ' replied in a discussion you follow'`, via the existing actor_count sentence
  builder (:166-172).

**Recipient-set invariant (unchanged):** one person, one row per event — a following topic author
still gets `reply_to_topic`; a following mentioned member still gets `mention`. The
most-specific-wins ladder just grows a fourth, least-specific rung.

### 2.4 The ⋯ row control — Unfollow, not Mute

Ruling 3 survives, **demoted and re-verbed**. Three reasons it is worth keeping rather than
dropping now that the card and modal carry the primary control:

1. **The bell row is often the only place the user meets the thread.** Making them open the
   discussion to stop hearing about it is a worse exit than the one that put them there.
2. **It is the only exit that reaches the legacy 1,519** (§8.2). Those members never clicked a
   Follow button, so no card affordance in their history explains why they are getting rows.
   Whatever Ian decides in §8.2, an in-row exit is what makes grandfathering humane rather than
   trapping.
3. **A thread can become unreachable** — filtered out of the rail, scrolled past, in a forum the
   viewer no longer browses. The row outlives the card.

**What changes from the old ruling:** "Mute this thread" is gone as a distinct concept. Under
auto-subscribe, *mute* was the right word — it suppressed something you never asked for. Under
opt-in there is nothing to mute; there is a follow you turned on, and the honest control is
**Unfollow**, showing the *same state* as the card and modal. One state, one verb, four places.
This deletes a mute-vs-unfollow vocabulary split that would have confused both users and code.

**Placement.** Every hub-event row whose `ref.kind` is `topic`|`reply` gets a ⋯ (overflow) button
between the body and the existing × — `[text/time] [⋯] [×]`. Same 26px round hover-target styling
as `.lg-notif__clear` (site-header.css:560-566). Connection rows get no ⋯.

**Menu**, a small anchored popover (not a new modal layer):

| Row type | Items |
|---|---|
| `forum.followed_topic` | **Unfollow this discussion** |
| `forum.reply_to_topic`, `forum.reply_to_reply`, `reaction.on_post` (kind topic/reply) | **Follow this discussion** / **Unfollow this discussion** — reflects live state; these rows fire from authorship, so the viewer may not be following |
| `forum.mention` | **Remove my mention** + **Follow / Unfollow this discussion** |

- Quiet copy under the title: *"Unfollowing stops follow-ups for this discussion. You'll still be
  notified when someone replies to you or mentions you."* — accurate under opt-in, and it names
  the three rungs following does not control (§0.0).
- **The menu is also an opt-IN surface.** Because rows now arrive for people who follow nothing,
  the ⋯ can *start* a follow — a genuinely useful affordance the auto-subscribe design had no room
  for.
- Toggle → `POST /bb-mirror-api/v0/follow {topic_id, action:'follow'|'unfollow'}` (§2.5). The row
  stays put either way (it is still a truthful record and its deep link still works); the client
  updates the menu item and shows a one-shot confirmation. No future follow-up refires after
  unfollow: the next reply simply finds them absent from the subscriber set.
- The client already knows `topic_id`: `ref.id` IS the topic id for every `forum.*` type
  (Notifications.php:190-196).
- **A11y/parity**: `aria-haspopup="menu"`, Esc closes, click-outside closes, ⋯ has
  `stopPropagation` like the × so opening the menu never navigates the row; ≥44px effective touch
  target ≤480. One implementation, both widths, both themes.

### 2.5 The WP-side endpoint: `bb-mirror-api/v0/follow.php`

The bell store (profile-app/PG) cannot write BB subscriptions (MySQL/WP) — follow must land on the
WP pool, exactly where reply.php lives. The caller mutates only their OWN subscription (`$uid` from
the session, **never** from the body).

```
GET  /bb-mirror-api/v0/follow?topics=12,44,91      → {authenticated:bool, nonce:string,
                                                      following:{"12":true,"91":true}}
POST /bb-mirror-api/v0/follow                       cookie-authed + X-WP-Nonce, self-scoped
  {topic_id:int, action:'follow'}                      → native subscribe   + mirror dispatch
  {topic_id:int, action:'unfollow'}                    → native unsubscribe + mirror dispatch
  {topic_id:int, action:'remove_mention', reply_id?:int} → §2.6 (unlink only)
```

- **The batch GET is load-bearing**, not a convenience: a feed page renders many cards and each
  needs the viewer's state. This mirrors `/archive-api/v0/save-post?items=` (forums.js:3809-3813)
  — same auth+nonce+my-state envelope, so the client module is a near-copy.
- Auth posture: `get_current_user_id()` or 401, as reply.php:5, :81-84.
- Plumbing: one nginx rewrite line in strangler-bb-mirror.conf alongside `reply`. The write-freeze
  map (lg-write-freeze-map.conf:7-10) already catches ALL bb-mirror-api writes by prefix — follow
  is correctly frozen during a freeze, like replies. **No change.**

### 2.6 Remove-my-mention = unlink ONLY (Ruling 4, rewritten)

The old ruling bundled unfollow into it. Under opt-in that half is not merely meaningless — **it
would be actively wrong**, and the argument is worth stating because it is the same principle that
drove the reversal:

- A mention never subscribed you, so in the common case there is nothing to unfollow.
- In the case where there *is* — you deliberately followed the thread **and** were mentioned in it
  — unfollowing would **silently cancel a choice the member made**, triggered by an unrelated act.
  That is a non-consensual state change in the opposite direction, and Ruling 1 outlaws the class,
  not just the sign.
- They are independent concerns: removing your name from someone else's post is about **identity
  and attribution**; unfollowing is about **notification volume**. Bundling them means a member who
  wants their name off a post must accept losing a thread they chose to watch.
- Nothing is lost by separating them: the Unfollow item sits directly beneath Remove-my-mention in
  the very same ⋯ menu (§2.4). One extra click, fully in the member's control.

**So: unlink only.** Server side (`action:'remove_mention'`), acting user = the MENTIONED member:

1. Resolve the caller's mention identity: the stored anchor is
   `<a class="bp-suggestions-mention" data-lg-uuid="<uuid>" href="{{mention_user_id_N}}">@<slug></a>`
   (_mention-ingest.php:15-27) — match on `{{mention_user_id_<their-wp-id>}}` and/or their uuid,
   **never on the @slug text** (slugs change; ids don't).
2. Rewrite the stored `post_content` of the mentioning post(s) in the topic (or just `reply_id` if
   given): replace each matching anchor with **the display name, without the `@` sigil**. kses-off
   `wp_update_post`, exactly the re-mint precedent (reply.php:377-385, bb-mirror-sync.php:166-173)
   — the save hooks re-fire so the PG mirror carries the unlinked content automatically.
   Idempotent: no matching anchor → no-op success.
   *Why no `@`:* a bare `@slug` is a **resolvable token** (_mention-ingest.php:28-30), so leaving
   it would re-link on the next content re-mint. Dropping the sigil makes the removal stick and
   the sentence still reads.
3. **No subscription write of any kind.**
4. The client then deletes the mention row via the EXISTING
   `DELETE /profile-api/v0/me/notifications/?id=` (me-notifications.php:61-79) — no new bell API.

### 2.7 Email — deferred to §8.1

The previous revision's §2.6 ("new mu-plugin `lg-bb-subscription-email-off.php`, kill all per-event
BB mail, ship it in the same change as auto-subscribe, kill first") was written as a **hard
precondition of auto-subscribe** — because auto-subscribing every involved member would have
multiplied a live email fan-out (old §1.3). **Ruling 1's reversal removes that precondition
entirely**: opt-in cannot multiply fan-out, because nothing is written without a click.

That is the whole reason the email question is now *open* rather than settled: the kill-switch was
load-bearing for a design that no longer exists, and Ian's 7/26 mail says the blanket kill is not
what he wants. **§8.1 lays out the resolutions; nothing here presumes one.** The weekly-digest
recap (§2.8) is written to be correct under any of them.

### 2.8 Weekly digest recap — counts + senders, never content

Independent of §8.1: a recap section is wanted whether or not per-event mail survives.

- **Surface**: one section in the existing weekly digest (lg-weekly-digest, FluentCRM campaign to
  list 3 — class-lg-wd-sender.php:29-52; live cadence Mon 09:00, OPERATOR.md §5).
- **Copy shape** — recap, not alert, zero content:
  > **Your discussions** — 12 new replies this week across 3 discussions you follow, from
  > Doug Proper, Sharon Fisher and 4 others. *[Open the Hub →]*
  - Counts + sender display names ONLY. Never reply text; never topic titles of private-forum
    threads (public-thread titles MAY be listed — Ian call, §8.3 q3).
  - One "Open the Hub" link (`/hub/`), not per-thread deep links, keeps the email inert.
- **Data**: entirely from the PG mirror, no WP round-trips: `forums.forum_subscription`
  (schema.pg.sql:209) ⋈ replies-in-window ⋈ `person` (names). Exposed as an internal loopback
  endpoint `bb-mirror-api/v0/follow-recap?wp_user_id=N` (loopback+deny-all like _sync).
- **Per-recipient rendering** inside a FluentCRM broadcast is the one genuinely new mechanism (the
  digest today is one body for all). Candidate: a FluentCRM custom smartcode rendered per
  subscriber at send. **Feasibility = dev2 verify (§7).** If unsupported, the section ships generic
  ("Discussions you follow had new activity this week") in v1 and per-user counts become a
  follow-up — the bell experience does not depend on it.

### 2.9 What deliberately does NOT change

- Deep-link contract, reply anchor, read-on-clickthrough, per-row ×, Clear all, badge counts,
  30-day prune, unbridged-recipient skip, "never notify yourself", mention > reply dedup ladder.
- The legacy BB notification rows keep being written by the in-process REST replay (audit Phase 3
  retires them; out of scope).
- No notification-preferences UI. The follow toggle is the only control this ships (audit §4.2's
  prefs matrix remains future work and stays compatible — follow is just the native subscription
  bit). Note BuddyBoss already has its own per-user `bb_is_notification_enabled` gate in the mail
  path (§1.3) — a second, overlapping preference surface is a reason to keep this spec's control
  count at exactly one.

---

## 3. Delta summary (what a build lane actually touches)

| # | File | Change |
|---|---|---|
| 1 | `profile-app/sql/` new migration | widen `notifications_type_check` with `forum.followed_topic` |
| 2 | `profile-app/src/Notifications.php:38-43` | add `forum.followed_topic` to `HUB_TYPES` |
| 3 | `lg-shared/notify-bridge.php` | `lg_follow_set()` helper; new leg 4 fan-out in `lg_notify_on_reply` (:170). **No subscribe calls in the topic/reply/mention legs — that is the reversal.** |
| 4 | `bb-mirror/api/v0/follow.php` **new** | batch GET + follow/unfollow/remove_mention (§2.5-2.6) |
| 5 | `bb-mirror/api/v0/follow-recap.php` **new** | loopback recap counts (§2.8) |
| 6 | `platform/nginx/strangler-bb-mirror.conf` | 2 rewrite lines + loopback block for follow-recap |
| 7 | `bb-mirror/web/forums/_reply-render.php:666` | `feed_follow_btn()` beside `feed_save_btn()` |
| 8 | `bb-mirror/web/forums/_feed.php:1617-1621` | emit it in the topic card's `.fc-actions` |
| 9 | `bb-mirror/web/forums.js:4215-4220` | `.lg-dmodal__follow` in the modal head; set state where the title is set (:4354) |
| 10 | `bb-mirror/web/forums.js` (new module, template = fc-save :3767-3841) | batch hydrate + delegated optimistic toggle for `.fc-follow, .lg-dmodal__follow, .lrs-follow` |
| 11 | `webroot/hub-polish.js:3628` | `.lrs-follow` in the mobile sheet head (§2.2c) |
| 12 | `bb-mirror/web/forums.css` | `.fc-follow` + `.lg-dmodal__follow` styles incl. `body.fc-save-anon`-style anon hide |
| 13 | `lg-shared/social-modals.js:173-186, :197-212` | `notifText` case; ⋯ button + popover in `renderNotifItem`; follow/unfollow/remove handlers |
| 14 | `lg-shared/site-header.css:560+` | ⋯ + popover styles (light), ~30 lines |
| 15 | `webroot/app-settings.js:255-268` | dark rules for the popover, ~4 lines |
| 16 | `lg-weekly-digest` | recap section hook (§2.8, mechanism pending verify) |
| 17 | — | **§8.1's outcome may add or remove an email mu-plugin. Not spec'd until Ian rules.** |

No new stores. One new event type. Two new WP-pool endpoints. Zero automatic subscription writes.

---

## 4. Deleted from the previous revision (so nobody rebuilds it)

- `lg_follow_on_involve()` and its three call-site table rows (topic author / replier / mentioned).
- The "re-involvement re-subscribes" rule and the sticky-mute tombstone question it raised — both
  are moot: nothing subscribes on involvement, so nothing re-subscribes, and an unfollow is simply
  the absence of a row until the member clicks again. **This also removes a whole open question
  from Ian's list.**
- "Mute" as a concept and as UI copy (§2.4).
- The unfollow half of remove-my-mention (§2.6).
- `lg-bb-subscription-email-off.php` as a *hard precondition* — see §2.7 and §8.1.

---

## 5. Mock frames — what survives, what Ian still needs to gate

Existing: `footer-mockups/threadfollow-notif-panel/mock.html` + 4 frames shot @8da56d3
(`notif-d-{light,dark}` 1280, `notif-m-{light,dark}` 390).

**They were drawn around auto-subscribe + per-row mute, and they under-represent the design now.**
Plainly:

| Frame element | Verdict |
|---|---|
| Panel chrome, tokens, dark/light, desktop-drawer vs mobile-sheet geometry | **SURVIVES** — untouched by the reversal, still the right previs for the panel |
| The coalesced `forum.followed_topic` counting row ("…and 3 others replied in a discussion you follow") | **SURVIVES** — Ruling 2 unchanged |
| The ⋯ affordance + popover geometry, and "no ⋯ on connection rows" | **SURVIVES** — placement and mechanics unchanged (§2.4) |
| Menu copy **"Mute this thread"** | **WRONG** — now "Unfollow this discussion" (§2.4) |
| The **"Muted ✓"** post-action state | **WRONG** — the row shows a follow state, not a mute state |
| The mention row's two-item menu **Mute + Remove my mention** | **WRONG PAIRING** — now Remove my mention + Follow/Unfollow, and the unlink no longer implies unfollow (§2.6) |
| **The feed-card follow control** | **ABSENT** — the surface Ian named first does not appear in any frame |
| **The modal header cluster (Follow beside S/M/L/XL)** | **ABSENT** — the surface Ian described most specifically does not appear in any frame |
| **The mobile sheet header control** | **ABSENT** — and it is the one surface Ian has not seen a proposal for at all (§2.2c) |

**New frames Ian needs to gate** (none exist yet):

1. **Card, desktop 1280** — `.fc-actions` with Follow beside Save, in both states (Follow /
   Following), light + dark. *This is the crowded-row risk in §2.2a made visible — it is the frame
   most likely to change the design.*
2. **Card, mobile 390** — whichever bar Ian picks in §8.3 q1, or a frame showing the control
   absent on mobile cards if he prefers modal-only there.
3. **Modal header, desktop 1280** — *[title] [Follow] [M] [×]* and *[title] [Following] [M] [×]*,
   light + dark.
4. **Mobile sheet header, 390** — `.lrs-hd` with the control between title and ×, both states.
5. **Notif panel, re-shot** — the 4 existing frames with corrected verbs and the corrected mention
   menu (cheap: same harness, `shoot.sh` is already strictly-serial and the resize trap is already
   encoded in the mock JS).

Frames 1 and 3 are the ones that decide whether Ian's instruction survives contact with the actual
control rows; recommend shooting those first and gating the rest on his reaction.

---

## 6. Verify plan for the build lane (dev2, at build time — NOT verified by this lane)

1. BB 2.20 subscription internals: exact write/read function names
   (`bbp_add_user_subscription` / `bb_create_subscription` / `bbp_get_topic_subscribers`), and
   which low-level action (if any) fires on programmatic writes → decides §2.1's dispatch shape.
   (Table + columns + `type` vocabulary already verified on live 2026-07-27, §1.2.)
2. Whichever email posture §8.1 lands on: prove it on dev2 via mailpit — a follower receives
   exactly the intended mail (or none) on a reply, and a non-follower receives nothing.
3. FluentCRM per-subscriber dynamic content (smartcode/callback) → §2.8 mechanism or fallback.
4. Batch-GET shape at feed scale: one `?topics=` request per feed page, correct after filter swap
   and infinite-scroll appends (the fc-save MutationObserver path, forums.js:3836-3841).
5. E2E per DISCUSSION-SURFACE-CANON, **starting from zero subscriptions**:
   - post a topic as A → **A follows nothing**; B replies → A still gets `reply_to_topic` (the
     authorship rung, §0.0), and **no** `followed_topic` row exists for anyone;
   - A clicks Follow on the card → state persists across reload, and shows Following in the modal
     header and the mobile sheet (one state, three surfaces);
   - C replies → A gets ONE `followed_topic` row; D replies → same row, "and 1 other", link at D's
     reply; A reads → next reply = fresh row;
   - A unfollows from the ⋯ row → no new rows; A is still rung when @mentioned;
   - remove-my-mention → anchor unlinked in stored + mirrored content, **A's follow untouched**,
     no re-mint on the next edit pass.
   - Both widths, both themes, through the real serve.

---

## 7. Live subscription reality (measured 2026-07-27, read-only via `live-ro`, DB `looth_import`)

Everything in §8.2 rests on these numbers, so they are stated with their queries' shape rather
than asserted.

```
type × status=1        rows      distinct users
  topic               1,519          383
  forum                  46           38
  group              12,948        1,853
```

**Topic subscriptions, by how they most likely came to exist:**

| | rows | share |
|---|---|---|
| subscriber **is the topic author** | 736 | 48% |
| subscriber **replied** in that topic | 355 | 23% |
| subscriber **never posted** in that topic | 428 | 28% |

**1,091 of 1,519 (72%) correlate exactly with involvement — authored or replied.** That is the
fingerprint of legacy auto-subscribe-on-involvement: precisely the mechanism Ian just outlawed. The
428 never-posted rows are the population most likely to represent a deliberate "subscribe to this
thread" click.

**How much of it is actually live:**

| | rows | distinct users |
|---|---|---|
| on a topic with **any reply in the last 90 days** | **112** | **49** |
| on a topic dormant 90+ days | 1,407 | — |

**93% of the legacy subscriptions are dormant.** The real, present-day email/bell exposure is
**49 people across 112 subscriptions**, not 383 across 1,519.

**Age:** 321 rows from 2026, 583 from 2025, 448 from 2024, 167 from 2023 — roughly three-quarters
predate this year.
**Concentration:** one user (wp id 779) holds 335 topic subscriptions — 22% of the entire table.
Any per-user framing of "the average member" is distorted by this account; check it before
generalising.
**Measured mail:** 31 discussion/reply emails in the trailing 14 days (§1.3).

---

## 8. OPEN FOR IAN — two decisions this lane must not make, plus small ones

### 8.1 The email contradiction (Ruling 6 vs. the mail you got on 7/26)

**The conflict.** Spec Ruling 6 turned per-event BuddyBoss discussion email permanently OFF and
made the weekly digest the only email surface. On 2026-07-26 you received a per-event discussion
email and called it legitimate and wanted. Both cannot stand.

**The two positions are narrower than they look** — this is the finding that may dissolve most of
the conflict, and it comes from the forum-email-audit lane's end-to-end trace (§1.3):

- The mail you received was a **"New discussion:"** email — the **forum**-subscription path
  (`bbp_notify_forum_subscribers` → `bb_send_forums_subscribed_discussion` → template 64928),
  fired because you hold a **forum** subscription. That population is **46 subscriptions / 38
  users**, and the event is rare: it fires only when someone *starts a thread* in a forum you
  follow.
- What Ruling 6 was written to stop is the **other** path: **"New reply"** mail from **topic**
  subscriptions — **1,519 subscriptions / 383 users** — which fires on *every reply*. That is the
  high-frequency surface, and it is the one this spec's follow feature feeds.

They are different subscription types, different triggers, and different frequency shapes. Ruling 6
collapsed them into one switch; the evidence says they deserve two.

**Resolutions, for your call:**

| | Posture | Consequence |
|---|---|---|
| **A** | **Split the switch** (recommended by the evidence, not decided here): keep per-event **"new discussion in a forum you follow"** mail — the one you liked — and route per-reply follow-up mail to the weekly digest only. | You keep exactly the email you called wanted. The high-frequency surface never turns on. Two clearly different things stop sharing one switch. |
| **B** | **An explicit follow earns the email.** Since following is now a deliberate click, per-event mail for followed threads is consented-to by definition; send it, and let the toggle be the unsubscribe. | Most generous reading of opt-in. But a member who follows an active thread can receive many emails a day, and the only volume control is unfollowing entirely — no "in-app only" middle setting exists in this spec (§2.9). |
| **C** | **Original Ruling 6** — all per-event off, digest only. | Simplest and quietest. But it kills the 7/26 email you said you wanted, and the measured volume (31 sends / 14 days) suggests the problem it was solving is not currently large. |

Note that under **any** option the reversal has already removed the original danger: the old §1.3
worry was auto-subscribing every involved member and multiplying a live fan-out. Opt-in makes that
impossible. **The email decision is now about what members want, not about containing a blast
radius** — which is why it is yours and not the spec's.

*(One correction worth carrying into the decision: the per-event mail is **synchronous with the
post**, not cron-driven. The previous revision blamed `lg-wp-cron.timer`; that timer drives the
digest, not this. Turning off cron would not have stopped these emails.)*

### 8.2 The 1,519 subscriptions live already has

Opt-in-only is a rule for **new** follows. It does not say what happens to subscriptions that
already exist and are emailing people today. **No option below is recommended as "obviously
right"; the trade is real.** Numbers and their derivation are in §7. Mass-unsubscribing real
members is explicitly off the table.

| | Option | Member-visible consequence |
|---|---|---|
| **A** | **Grandfather everything, change nothing.** All 1,519 stay; they simply become "follows" in the new vocabulary. | Nobody loses anything and nobody is surprised. But ~1,091 subscriptions that were created by the auto-subscribe you just banned keep running — the rule applies going forward only, and the back catalogue quietly contradicts it. |
| **B** | **Grandfather, but surface and make exitable** *(the smallest honest option)*: change no data; the card, modal, sheet, and ⋯ row all show **Following** on those threads, so every legacy subscription becomes visible and one click from off. | Nobody loses anything, and for the first time members can *see* what they are subscribed to and leave. Converts hidden state into visible state — which is arguably what "opt-in" is really asking for. Cost: the 1,091 non-consensual subscriptions persist until each member acts. |
| **C** | **B + retire the dormant tail**: unsubscribe the **1,407** rows on threads with no reply in 90 days; keep the **112** live ones. | Clears 93% of the legacy state while touching nobody who is currently receiving anything — dormant threads are, by definition, sending no mail today. Risk: if a dormant thread revives, a member who genuinely subscribed loses a notification they wanted and will never know why. |
| **D** | **B + retire the involvement-created ones**: drop the **1,091** authored-or-replied rows, keep the **428** never-posted ones. | Philosophically the closest match to the ruling — it removes exactly what the banned mechanism created. But it unsubscribes **topic authors from their own threads** (736 rows), which most members would experience as a regression, and "authored or replied" is a correlation, not proof of how the row was made. |
| **E** | **Mass unsubscribe all.** | Off the table per the lane brief, listed for completeness: 383 members silently stop hearing about threads some of them deliberately chose, with no way to recover the list once deleted. |

**Two things to weigh alongside:** (1) the practical exposure is **49 people / 112 subscriptions**,
so the difference between A/B and C is much smaller in lived experience than the raw 1,519
suggests; (2) whatever you choose interacts with §8.1 — if per-reply email stays off (8.1 A or C),
the legacy topic subscriptions are a *bell-volume* question, not an *inbox* question, and the case
for touching them at all weakens considerably.

**Also flagged, not folded in:** the **12,948 group subscriptions / 1,853 users** are a third
population this spec has never addressed. Group-linked forums route discussion mail through the
GROUP-subscriber branch (template 64927) rather than the forum one, so they are a live email path
with 36× the reach of the forum subscriptions. **Out of scope here and deliberately not proposed
against** — but no decision in §8.1 or §8.2 should be described as "covering the subscriptions"
while this sits untouched.

### 8.3 Smaller open questions (none block the mock)

1. **Card control on mobile** (§2.2a): mobile cards use hub-polish's `.lg-act-*` bar, not
   `.fc-actions`; `.fc-share` is already desktop-only. Does Follow appear on mobile cards, and in
   which bar — or is the mobile sheet header (§2.2c) enough there?
2. **Mobile sheet header** (§2.2c): you named the card and the modal; the ≤640 sheet has no
   S/M/L/XL cluster to sit beside. Spec'd as a peer of the × in `.lrs-hd`. Confirm or redirect.
3. **Digest recap** (§2.8): may public-forum topic TITLES appear, or names + counts only?
4. **Content cards** (§2.2a): v1 puts Follow on discussion cards only. Should articles/videos get
   "follow the comments" later, or never?
5. **Stale code comment** at forums.js:4231 says "3 panel sizes (Ian): S / M / L" while `SIZES` has
   four. Cosmetic, adjacent to our change, not fixed by this lane — worth a one-liner from whoever
   touches that block next.

---

*Written from static study of `main@c10df43` on dev1, plus read-only measurement of LIVE via
`live-ro` on 2026-07-27 (§7) — the subscription and email-volume numbers are measured, not
asserted. Claims about BB internals are cited to NOTIFICATIONS-AUDIT.md and the forum-email-audit
lane's live trace; everything tagged "verify" in §6 must be proven on dev2 before the build lane
asserts it. No build has started and none may start until Ian approves §0's rulings and answers
§8.1 and §8.2.*
