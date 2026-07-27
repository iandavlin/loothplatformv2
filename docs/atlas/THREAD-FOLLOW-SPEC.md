# THREAD-FOLLOW-SPEC — two opt-in toggles per discussion, both default OFF

> **Status: SPEC + MOCK, Ian-gated — no build until Ian approves both.**
> Lane: threadfollow-spec (dev1, docs+mock only). Branch `threadfollow-spec`.
> **REVISED 2026-07-27 (v2) — Ian: TWO independent toggles per discussion, NOTIFICATIONS and
> EMAILS, both default OFF, both unsettable from three places including the email itself.**
> **IAN GATED, 2026-07-27 — three closures, all recorded here so none is re-litigated:**
> **(1) the CARD carries two icons**, not a single expanding control; **(2) MOBILE CARD** — the two
> icons go at the right end of the `.lg-card-actions` bar; **(3) MOBILE SHEET HEADER** — circular
> peers between the title and the ×. Each rejected alternative has been **deleted** from this
> document rather than archived.
> *(Closures 2 and 3 were keeper-relayed through the operator on 2026-07-27 and were not posted to
> the devmsg board as of id 1378; closure 1 is board post id 1377. Noted so the provenance of each
> is on the record.)*
> This supersedes v1's single follow toggle, which itself reversed the original auto-subscribe
> design. See §0.0 for the trail and §9 for what is still Ian's to decide.
> Mock frames: `footer-mockups/threadfollow-notif-panel/` — published for gating at
> **https://dev2.loothgroup.com/v2/tests/output/threadfollow/index.html** (cookie-gated).
> Cross-refs: NOTIFICATIONS-AUDIT.md, DISCUSSION-SURFACE-CANON.md, OPERATOR.md §4–5, REPO-MANDATE.md.
> **Everything below cites current `main` (@c10df43)**, plus read-only measurement of LIVE
> (§8) and of the deployed BuddyBoss source (§4.1).

---

## 0.0 The ruling trail (three positions, this is the third)

| | Position | Status |
|---|---|---|
| **original** | Involvement auto-subscribes — authoring, replying or being @mentioned makes you follow | **DEAD** (Ian, 7/27) |
| **v1** | ONE opt-in follow toggle; a separate global email preference | **SUPERSEDED** (keeper's suggestion, not Ian's) |
| **v2 — THIS** | **TWO independent opt-ins per discussion: NOTIFICATIONS (bell) and EMAILS. Both default OFF for everyone, always.** A member can hold either, both, or neither. | **CURRENT** |

Nothing auto-subscribes, to either toggle. Creating a topic, replying in it, and being @mentioned
in it subscribe you to **nothing**.

**Why the reversal stays cheap** (carried from v1, still the most important consequence): killing
auto-subscribe makes nobody go dark. Three of the bell's four rungs are authorship/mention-based
and independent of any subscription — `forum.reply_to_topic` (someone replied to *your* topic),
`forum.reply_to_reply`, `forum.mention` all fire today for people holding zero subscriptions
(notify-bridge.php:170-236). **The NOTIFICATIONS toggle only ever adds the fourth, least-specific
rung**: "a thread I chose to watch but am not otherwise part of." A topic author still hears about
replies to their own topic without toggling anything.

---

## 0. The rulings

1. **TWO independent per-discussion opt-ins, both default OFF:**
   - 🔔 **Notifications** — bell rows in the panel for new replies (§3.3).
   - ✉ **Emails** — email for new replies (§3.4).
   Independent: either, both, or neither. Nothing writes either bit without a deliberate click.
2. **SET them in two places** — the feed **CARD** and the open **DISCUSSION MODAL**, in the header
   control cluster (§2). Ian: the affordance must be **visible, not buried**.
   **The card carries the bell and the envelope as two separate visible icons — Ian-confirmed
   2026-07-27 from the previs.** Eight controls in that action row is the shape he approved.
   **Mobile placements are Ian-confirmed the same day**: right end of the mobile card bar (§2.2b)
   and circular peers of the × in the sheet header (§2.4).
   **Explicitly NOT in the post ⋯ menu** — verified wrong surface twice over (§2.3).
3. **UNSET them from three places, all reaching the same store:**
   (1) the thread itself, same controls; (2) the notifications panel per-row ⋯ (§3.5);
   (3) **an unsubscribe link in the email itself** (§4) — logged-out, signed, and **specific to
   that discussion**, never a blanket kill.
4. **Follow-ups are ONE coalesced counting row per thread** in the bell — never one row per reply.
5. **Remove-my-mention = unlink ONLY** (§3.6). It touches neither toggle.
6. **Store mapping (amends the old "no new store" ruling — see §5):** the **native BB subscription
   is the EMAIL bit**; the **notifications bit is a new store we own**. This is the mapping that
   costs least, and it makes the legacy-subscription question (§9.2) land on exactly one toggle.
7. **Deep links per the existing notify-bridge contract** — unchanged.
8. **Parity** — every surface works desktop and mobile, both themes, one implementation.
9. **Account-level email prefs coexist by a strict master/member rule** (§6) — no state where the
   account page says off and mail still arrives.
10. **OPEN, Ian's call:** the **1,519 existing topic + 46 forum subscriptions** already emailing
    real members (§9.2) and the per-event vs digest email posture (§9.1). Both now carry a
    **recommendation with the member-visible consequence spelled out**, and §9.2 has a frame — but
    neither is decided here.

---

## 1. Current machinery (read before touching anything)

### 1.1 The bell pipeline (live, one writer, one store)

```
WRITE PATHS                          BRIDGE                    STORE                    UI
reply.php:402-405 ─────────┐
(mobile-sheet replies)     │   lg-shared/notify-bridge.php    profile-app               site-header.php:872-888
bb-mirror-sync.php:225-231 ├─▶ lg_notify_on_reply():170  ──▶  internal-notify.php  ──▶  (#lg-notif-modal)
(native REST replies)      │   lg_notify_on_topic():238      :97 pushHubEvent           social-modals.js:231
bb-mirror-sync.php:175-180 │   lg_notify_on_reaction():266   Notifications.php:105      (loadNotifications)
(new topics)               │   (dedup: mention > reply_to_   (ON CONFLICT coalesce
card-react.php ────────────┘    reply > reply_to_topic)       :122-133, actor_count)
```

- **Dedup** (notify-bridge.php:161-182): one notification per person per event, most specific type
  wins, never your own action. The `$notified` set (:177) is the mechanism — §3.3 adds a fourth rung.
- **Coalescing** (Notifications.php:94-133): unique index on
  `(user_uuid, type, target_kind, target_id, COALESCE(anchor_id,0))` scoped `WHERE is_read=false`
  (sql/2026-07-12-notifications-hub-events.sql:64-74). Second actor merges → `actor_count+1`,
  `target_url` re-pointed. Once READ, the next event rings a fresh row (Notifications.php:99-101).
  `forum.reply_to_topic` with `anchor_id=0` already demonstrates the one-row-per-topic shape.
- **Type vocabulary**: `Notifications::HUB_TYPES` (Notifications.php:38-43) + DB CHECK
  (sql/…:49-53). Both widen by one value for a new type.
- **Panel render**: `notifText()` social-modals.js:173-186, `renderNotifItem()` :197-212,
  `.lg-notif__clear` :209 styled site-header.css:560-566, markup site-header.php:872-888.
- **Retention**: 30-day prune regardless of read state (Notifications.php:273-287).

### 1.2 The subscription store (exists; inert for the Hub, NOT inert for email)

- **Native**: `wp_bb_notifications_subscriptions` — columns verified on live 2026-07-27:
  `id, blog_id, user_id, type, item_id, secondary_item_id, status, date_recorded`;
  `type ∈ {forum, topic, group}`, `status=1` = active. Fronted by the bbPress-compat API
  (`bbp_add_user_subscription` / `bbp_remove_user_subscription` / `bbp_get_topic_subscribers`).
  ⚠️ exact function names on the deployed build = build-time dev2 verify (§7).
- **Site settings** (live `wp_options`, verified): `_bbp_enable_subscriptions = 1`,
  `bb_enable_group_subscriptions = 1`. Switched on at platform level today.
- **Mirror**: `bbp_subscriptions_handler` (bb-mirror-sync.php:324-329) → _sync.php:103-125 → PG
  `forums.forum_subscription` (schema.pg.sql:209-217, PK `(user_id, target_kind, target_id)`).
- **One bit per (user, topic).** The table has room for exactly one subscription state per pair —
  which is why two independent toggles need a second home. §5.

### 1.3 The email path (synchronous, and it has never stopped)

Verified end-to-end on live by the forum-email-audit lane (2026-07-26, read-only):

```
Hub composer → POST /wp-json/buddyboss/v1/topics  → real post_type=topic → bbp_new_topic
  → bbp_notify_forum_subscribers            (bp-forums/core/actions.php:221, priority 9999)
  → bb_send_notifications_to_subscribers()  (bp-forums/common/functions.php:1434)
  → BP_Forums_Notification::bb_send_forums_subscribed_discussion()  (…:862)
       author excluded; per-user `bb_is_notification_enabled` gate
  → bp_send_email('bbp-new-forum-topic') :957 → bp-email post 64928
  → wp_mail → FluentSMTP → Amazon SES us-east-1     ← SYNCHRONOUS with the POST (~1s)
```

**Two event classes on two subscription types — the distinction that drives §9.1:**

| Class | Type | Trigger | Shape |
|---|---|---|---|
| **"New discussion"** — a topic started in a forum you follow | `forum` (46 rows / 38 users) | `bbp_new_topic`, sync | rare |
| **"New reply"** — a reply in a topic you follow | `topic` (1,519 rows / 383 users) | `bbp_new_reply` | every reply — the high-frequency one |

Group-linked forums take the GROUP branch instead (template 64927); that population is 12,948 rows
/ 1,853 users. Check `bbp_get_forum_group_ids` before predicting blast radius for anything.

**Measured volume** (`wp_fsmpt_email_logs`, 14 days to 2026-07-27): 31 discussion/reply emails
across 7 days; the two "New discussion:" sends went to 5 recipients each. FluentSMTP retention is
`log_saved_interval_days=14`, so an empty older window means retention, not silence.

**Correction carried from v1:** this mail is *synchronous with the post*, not cron-driven. An
earlier revision blamed `lg-wp-cron.timer`; that timer drives the digest only.

### 1.4 Deep links + surfaces (unchanged)

- URL: `/hub/?topic=<forum-slug>/<topic-slug>[&reply=<id>]` (notify-bridge.php:45-56).
- Router: forums.js §4f — ≥641 the dmodal, ≤640 `#looth-rep-sheet` via `lgOpenTopicMobile`.
- Panel: one `lg-social-modal` drawer — 400px right on desktop (site-header.css:452-460),
  full-width ≤480 (:743-745); dark injected by app-settings.js:255-268.

---

## 2. Where the toggles are SET

### 2.1 Two peer icon toggles — Ian-confirmed 2026-07-27

Ian asked for two toggles, visible, not buried, and on reviewing the previs chose exactly that over
a single control that expands to reveal them. **Two peer icon toggles** with `aria-pressed`, so both
states are readable at a glance without a click:

```
🔔  Notifications for this discussion     ✉  Emails for this discussion
```

- Icon-only on the card (space, §2.2), icon + short label in the modal and sheet headers.
- `aria-pressed` + a filled/sage state, matching `.fc-save`'s `.is-saved` idiom exactly.
- Copy on hover/aria: "Notify me about new replies" / "Email me about new replies", flipping to
  "Stop notifications" / "Stop emails" when on.

### 2.2 The feed CARD

The precedent to copy rather than reinvent is `.fc-save` — the per-topic, per-user star that is
server-rendered inert and batch-hydrated with the viewer's real state.

- **Markup**: `feed_follow_btns(int $topicId)` beside `feed_save_btn()` in the topic card's
  `.fc-actions` (_feed.php:1617-1621), defined in _reply-render.php next to `feed_save_btn()`
  (:666) so every card-rendering partial can emit it. Shape mirrors the star:
  `<button class="fc-notify" data-follow="notify" data-topic-id="N" aria-pressed="false">` and
  `<button class="fc-email" data-follow="email" data-topic-id="N" aria-pressed="false">`.
- **Hydration**: the fc-save module (forums.js:3767-3841) is the structural template — server
  renders inert, ONE batch GET resolves auth + nonce + both bits for every card on screen,
  `MutationObserver` re-syncs on filter swap and infinite-scroll appends, click is optimistic with
  revert-on-failure, `stopPropagation` so it never opens the thread. **The batch read is why §3.2's
  GET takes a topic LIST.**
- **Anon**: no nonce → inert and CSS-hidden, as `body.fc-save-anon .fc-save{display:none}`
  (forums.css:585, :4123).
- **Topic cards only in v1.** Content cards (managed CPTs, _feed.php:1482) have comments, not topic
  subscriptions — §9.3 q4.
- **Eight controls in the row — approved on sight.** `.fc-actions` already carries reactions +
  Like/replies/Share + Save + Share + expand (_feed.php:1617-1626); the two toggles make eight.
  Desktop styling is `display:flex; gap:8px` with `.fc-save{margin-left:auto}`
  (forums.css:4113-4123). Ian reviewed this exact row in the previs and gated it, so the row is
  settled — do not re-open it.
- **MOBILE cards are a different bar and still need Ian's word — §2.2b, proposal below.**

### 2.2b Mobile card placement — IAN-CONFIRMED 2026-07-27 (frame 2)

Mobile cards do **not** use `.fc-actions`. They use `.lg-card-actions`/`.lg-act` (forums.css @≤640,
`gap:18px; padding:8px 13px 12px`), and `.fc-share`/`.fc-save` are desktop-only (:600).

**The two icons sit at the right end of that bar** — icon-only, `margin-left:auto`, ≥44px touch
targets. The bar carries only three items (Like / N replies / Share) against 390px, so unlike
desktop there is room, with space to spare in frame 2. Ordering matches desktop (state controls
right).

Ian's reason for choosing it, worth keeping because it generalises: **a card control whose whole
point is opting in without opening the thread is worthless if it only exists inside the thread.**

*The rejected alternative — omit from mobile cards, sheet header only — is deleted, not archived.*

### 2.3 The DISCUSSION MODAL header — and why NOT the ⋯ menu

**⋯ is the wrong surface twice over — verified, and worth recording so nobody proposes it again:**
`forums.js:3122` — *"FB-style '⋯' post menu (own posts; admins/mods on all)"* — the trigger is
revealed only for the post's **author or a moderator**, and the menu's contents are **Edit /
Delete** against the owned `/bb-mirror-api/v0/reply` PUT/DELETE endpoints. Same again for modal
replies at `forums.js:4019-4024`. So it is (a) *scoped to people who own the post*, which is
precisely **not** the audience for a follow control, and (b) *semantically a destructive
content-management menu*. Ian's instruction and the code agree.

The right home is the modal header cluster (forums.js:4215-4220):

```js
'<header class="lg-dmodal__head">' +
  '<h2 class="lg-dmodal__title"></h2>' +
  '<button type="button" class="lg-dmodal__notify" aria-pressed="false" ' +
          'aria-label="Notify me about new replies">🔔</button>' +          // NEW
  '<button type="button" class="lg-dmodal__email"  aria-pressed="false" ' +
          'aria-label="Email me about new replies">✉</button>' +           // NEW
  '<button type="button" class="lg-dmodal__size" aria-label="Modal size" title="Modal size"></button>' +
  '<button type="button" class="lg-dmodal__x" data-dm-close aria-label="Close">&times;</button>' +
'</header>'
```

Both toggles sit **before** the size control, so the cluster reads
*[title] … [🔔] [✉] [S|M|L|XL] [×]* — state controls grouped, dismissal rightmost. State is set when
the modal is populated (where the title is set, forums.js:4354) from the batch already fetched for
the cards, falling back to a single-topic read when deep-linked in cold.

> **⚠️ Citation correction — second time this has come through, so it is recorded here permanently.**
> The brief points at `bb-mirror/web/forums.js:219-228` as "the reading-size control" to sit beside.
> **That control does not exist on the page and never lived in the modal.** It is `.feed-text-toggle`,
> the 3-state `--lg-read-scale` sort-bar pill, and it was **RETIRED 2026-06-10** (bespoke-cutover;
> Ian: "the header GEAR is the only page-state control zone"): `hub_render_view_toggles()` is now an
> empty function that emits nothing (_filter-rail.php:103-114), and hub-polish.js additionally
> CSS-hides it (:4387). The forums.js block at :218-244 is null-guarded dead code. Reading size lives
> in the settings-gear LGSettings panel (webroot/app-settings.js).
> **The only size control in the modal header is `.lg-dmodal__size`** — `SIZES=['s','m','l','xl']`
> (forums.js:4232), rendered as those literal letters (:4238), cycled at :4241. That is what Ian
> described as "s m l xl" in the previous ruling, and it is the cluster this spec targets. The
> physical placement is the same either way, so nothing in the design changes — but a build lane
> aiming at :219-228 would be editing dead code.

### 2.4 The MOBILE SHEET header — IAN-CONFIRMED 2026-07-27 (frame 5)

≤640 the router opens `#looth-rep-sheet`, whose header is
`<div class="lrs-hd"><span class="lrs-t"></span><button class="lrs-x">×</button></div>`
(hub-polish.js:3628, styles :3171) — title + close, and **no size control at all**, so the desktop
instruction ("beside S/M/L/XL") has no literal target here.

**`.lrs-notify` + `.lrs-email` as circular 34px buttons between `.lrs-t` and `.lrs-x`.** Same state,
same handler, ≥44px effective touch targets. The order reads *title → state → dismiss*, identical to
the desktop cluster, so the two surfaces stay learnable as one thing.

*The rejected placement — a control row beneath the header — is deleted, not archived. It added
permanent vertical chrome to the smallest screen, on a sheet already competing with the composer and
the keyboard.*

---

## 3. Behaviour

### 3.1 The two bits

| Toggle | Default | Effect when ON | Store |
|---|---|---|---|
| 🔔 Notifications | **OFF** | new replies produce ONE coalesced `forum.followed_topic` bell row (§3.3) | **new store we own** (§5) |
| ✉ Emails | **OFF** | new replies produce email via the existing BB sender (§1.3) | **native `wp_bb_notifications_subscriptions`** (§5) |

Independent in both directions. Turning one on never turns the other on.

### 3.2 The endpoint: `bb-mirror-api/v0/follow.php`

The bell store and the BB subscription both need a WP-pool writer (MySQL/WP), exactly where
reply.php lives. The caller mutates only their OWN state (`$uid` from the session, **never** the body).

```
GET  /bb-mirror-api/v0/follow?topics=12,44,91
       → {authenticated:bool, nonce:string,
          state:{"12":{notify:true,email:false}, "91":{notify:false,email:true}}}

POST /bb-mirror-api/v0/follow            cookie-authed + X-WP-Nonce, self-scoped
  {topic_id:int, channel:'notify'|'email', on:bool}
  {topic_id:int, action:'remove_mention', reply_id?:int}     → §3.6
```

- **The batch GET is load-bearing** — a feed page renders many cards and each needs both bits. It
  mirrors `/archive-api/v0/save-post?items=` (forums.js:3809-3813), same auth+nonce+state envelope,
  so the client module is a near-copy.
- `channel:'email'` writes the native subscription **and** dispatches the mirror explicitly:
  `bb_mirror_sync_dispatch('subscription', $topic_id, $on?'subscribe':'unsubscribe', ['user_id'=>$uid])`.
  Explicit dispatch is required — `bbp_subscriptions_handler` (bb-mirror-sync.php:324) is the **UI
  form-handler** action and does not fire on programmatic writes.
- Auth posture as reply.php:5, :81-84 (`get_current_user_id()` or 401). One nginx rewrite line in
  strangler-bb-mirror.conf beside `reply`. The write-freeze map (lg-write-freeze-map.conf:7-10)
  already catches all bb-mirror-api writes by prefix — correctly frozen during a freeze. No change.
- **This endpoint is the ONLY caller of the subscription writers.** No path from `bbp_new_topic`,
  `bbp_new_reply`, reply.php, or the mention legs reaches them. That is the structural enforcement
  of "no auto-subscribes" — **a build lane adding one is a spec violation.**

### 3.3 Notifications ON → `forum.followed_topic`, one counting row per thread

New type `forum.followed_topic` in `Notifications::HUB_TYPES` (Notifications.php:38-43) and the DB
CHECK (sql/2026-07-12-notifications-hub-events.sql:49-53 widens by one value; the coalescing index
:64-74 needs **no change**).

New leg **4** in `lg_notify_on_reply` (after :213-223), reusing the `$notified` dedup set:

```
4. Everyone with the NOTIFY bit on this topic, minus everyone already rung
   (mention, parent-reply author, topic author) and the replier:
     type = forum.followed_topic, target_kind='topic', target_id=topic_id
     anchor_id = 0        ← NULL in the dedup key → ONE row per topic per user
     target_url = lg_notify_topic_url(topic_id, reply_id)   ← newest reply, re-pointed on coalesce
```

Coalescing, counting, read-resets-the-row and prune are all **inherited unchanged**. Bell copy
(social-modals.js `notifText` :173-186, new case): `notifActors(n) + ' replied in a discussion you follow'`.

**Recipient-set invariant:** one person, one row per event — a following topic author still gets
`reply_to_topic`; a following mentioned member still gets `mention`. The ladder grows a fourth,
least-specific rung.

### 3.4 Emails ON → the existing BB sender, unchanged

Because the email bit **is** the native subscription (§5), the send path needs no new machinery:
`bb_send_notifications_to_subscribers` already reads that table. The work is confined to (a) the
toggle writing it, (b) the unsubscribe link in the message (§4), and (c) whatever §9.1 decides
about the posture.

### 3.5 The notifications-panel row ⋯ (unset surface #2)

Every hub-event row whose `ref.kind` is `topic`|`reply` gets a ⋯ between the body and the existing
× — `[text/time] [⋯] [×]`, same 26px round hover-target as `.lg-notif__clear`
(site-header.css:560-566). Connection rows get none.

The menu carries **both** bits, so the row can unset whichever is ringing — and, because rows now
arrive for people who follow nothing, it can also **opt in**:

| Row type | Items |
|---|---|
| `forum.followed_topic` | **Stop notifications** · **Stop emails** (each shown per live state) |
| `forum.reply_to_topic`, `forum.reply_to_reply`, `reaction.on_post` | **Notify me / Stop notifications** · **Email me / Stop emails** |
| `forum.mention` | the two above + **Remove my mention** (§3.6) |

- Quiet copy: *"You'll still be notified when someone replies to you or mentions you."* — accurate,
  and it names the three rungs neither toggle controls.
- Writes the same §3.2 endpoint. The row stays put (still a truthful record, deep link still works).
- `ref.id` IS the topic id for every `forum.*` type (Notifications.php:190-196).
- **A11y**: `aria-haspopup="menu"`, Esc closes, click-outside closes, `stopPropagation` like the ×
  so opening never navigates; ≥44px effective target ≤480. Both widths, both themes.

### 3.6 Remove-my-mention = unlink ONLY

It touches **neither** toggle. A mention never subscribed you; and where you *did* deliberately
toggle something on, cancelling it via an unrelated act would be a silent, non-consensual state
change — the same class the opt-in rule outlaws, just the other sign. They are independent
concerns: removing your name from someone's post is **attribution**; the toggles are **volume**.
Nothing is lost by separating them — both toggles sit in the same ⋯ menu, one click away.

Server side (`action:'remove_mention'`), acting user = the MENTIONED member:

1. Resolve identity from the stored anchor
   `<a class="bp-suggestions-mention" data-lg-uuid="<uuid>" href="{{mention_user_id_N}}">@<slug></a>`
   (_mention-ingest.php:15-27) — match on `{{mention_user_id_<their-wp-id>}}` and/or uuid,
   **never on the @slug text** (slugs change; ids don't).
2. Rewrite the mentioning post's stored `post_content`: replace each matching anchor with **the
   display name, without the `@` sigil**. kses-off `wp_update_post`, the re-mint precedent
   (reply.php:377-385, bb-mirror-sync.php:166-173) — save hooks re-fire so the PG mirror follows.
   Idempotent. *Why no `@`:* a bare `@slug` is a resolvable token (_mention-ingest.php:28-30) and
   would re-link on the next re-mint.
3. **No subscription write of any kind.**
4. Client deletes the row via the existing `DELETE /profile-api/v0/me/notifications/?id=`
   (me-notifications.php:61-79).

---

## 4. The email unsubscribe link (unset surface #3) — the one with real constraints

**Requirement:** works for a **logged-out** reader clicking from their inbox; **specific** to that
discussion, never a blanket kill; lands on a **small confirmation page** that also offers "stop all
discussion emails" for someone who is really done; one click, no login, no accidental total-unsub.

### 4.1 What already exists — reuse analysis (all verified, not assumed)

| Candidate | What it actually is | Verdict |
|---|---|---|
| **BuddyBoss `bp-emails-unsubscribe-salt`** — `bp_email_get_salt()` (bp-core-functions.php:4183-4185), option seeded at install as base64 of `wp_generate_password(64)` (bp-core-update.php:841); present on live | A persistent HMAC salt + a working logged-out verify path | ✅ **the right base — take the salt and the pattern** |
| `bp_email_get_unsubscribe_link()` (bp-core-functions.php:4142-4172) | Signs **`"{$email_type}:{$user_id}"`** → scoped to a notification **TYPE**, not an item. One click kills that whole email type via `bp_update_user_meta($uid,$meta_key,'no')` (:4106) | ❌ **wrong granularity** — it can only produce the blanket kill Ian ruled out. Reuse the salt, **not** this function |
| FluentCRM `##crm.unsubscribe_url##` (lg-weekly-digest/templates/email.php:11, :181) | List/global unsubscribe smartcode | ❌ wrong granularity **and** wrong blast radius — would also kill the weekly digest |
| `platform/mu-plugins/lg-event-reminders.php` | FluentCRM **list membership** management; defers to FluentCRM's GLOBAL unsubscribe as master off (:35-39, :177-198) | ❌ **emits no unsubscribe link and mints no token** — correction to the brief |
| `platform/mu-plugins/bb-mirror-sync.php` | Its "unsubscribe" is the **mirror dispatch action name** (:323-326) | ❌ **not an unsub link at all** — correction to the brief |
| `platform/mu-plugins/looth-vendor/firebase/php-jwt` | Full JWT, used by the identity minter | ➖ works, but oversized for a one-purpose link and drags in identity semantics |

**So there is exactly one real signed-token unsubscribe mechanism on the box (BuddyBoss's) and one
real unsub link in the repo (the digest's FluentCRM smartcode).** Recommendation: **base it on
BuddyBoss's salt + HMAC pattern**, extended with the topic id.

### 4.2 The link

```php
$nh  = hash_hmac('sha1', "lgdisc:{$topic_id}:{$user_id}", bp_email_get_salt());
$url = home_url("/discussions/unsubscribe/?uid={$user_id}&tid={$topic_id}&nh={$nh}");
```

- Same primitive, same salt, same `hash_equals()` verification posture as BB
  (bp-core-functions.php:4089) — but the **topic id is inside the signed payload**, which is the
  entire difference between "this discussion" and "all mail of this type."
- Carries BB's own safety rule forward: a **logged-in** user may not act on a different `uid`
  (bp-core-functions.php:4076-4085).
- **No expiry**, deliberately, matching BB: the token only ever *removes* a subscription, and an
  expiry would silently break the link in older emails. Stated so it is a choice, not an oversight.
- Salt rotation invalidates every outstanding link — worth a note in the mu-plugin header.

### 4.3 GET shows a confirmation page; POST performs the change

**This is a correctness requirement, not politeness.** BuddyBoss's own handler mutates on a bare
GET (`bp_update_user_meta(...)`, bp-core-functions.php:4106). Corporate mail scanners and link
prefetchers (Outlook SafeLinks, proxying gateways) follow links in email — against a GET-mutating
endpoint, that silently unsubscribes people who never clicked. The confirmation step removes the
hazard and is also what Ian asked for.

**The page** (small, standalone, no login, works in any theme):

- Names the discussion: *"Stop emails about **'Truss rod won't budge'**?"*
- **Primary action — "Stop emails from this discussion"** (POST). Affects the ✉ bit for that topic
  only. **The 🔔 notifications bit is untouched** and the page says so: *"You'll still see new
  replies in your notifications."*
- **Secondary — "Stop ALL discussion emails"** (POST). Sets the account-level master (§6), which is
  the same store the account page writes. For the person who is really done.
- Third, quiet: a link back to the discussion, and (if logged in) to the account email preferences.
- After acting: a confirmation state with an **Undo**, since a mis-click is otherwise unrecoverable
  without finding the thread again.

### 4.4 Where it renders

The link belongs in the reply-notification email body. Which template that is depends on §9.1: if
BB's own sender stays (posture A/B), the link is filtered into the bp-email template; if per-event
mail is replaced, it rides whatever replaces it. **Spec'd as a filter on the outgoing message so it
is independent of that decision.**

---

## 5. The store mapping (this ruling amends "no new subscription store")

Two independent bits per (user, topic); the native table holds **one** (§1.2). So one bit needs a
new home. **Which bit goes where is the load-bearing choice, and the mapping is not symmetric:**

**✉ Emails → the NATIVE BB subscription.**
1. BB's mailer already reads exactly that table (`bb_get_subscription_users`), so the email path
   needs no recipient-filter hack — the hard part comes free.
2. BB's own unsubscribe machinery already writes that world, so §4 stays close to its base.
3. The PG mirror (`forums.forum_subscription`) already syncs it.
4. **The 1,519 legacy rows ARE today's email population.** Mapping them onto the email toggle is
   simply true, and it collapses §9.2 into a question about **one** toggle instead of two.

**🔔 Notifications → a new store we own.** The bell is entirely ours (notify-bridge + profile-app
PG); it needs no BB interop, so a new store costs nothing there.

- **Recommended placement**: PG, owned by profile-app — `forums.topic_follow (user_uuid, topic_id,
  created_at)`, PK `(user_uuid, topic_id)`. Leg 4 (§3.3) then becomes **ONE** loopback post
  (`fanout:'followers'`) that profile-app expands against its own table — which also retires the
  old "one loopback POST per recipient" cost. **This is the one genuinely new mechanism in the
  spec**: internal-notify gains a fan-out form.
- **Simpler fallback** if the build lane wants minimal change: a MySQL table on the WP pool read
  directly by notify-bridge, keeping today's per-recipient posts. Cheaper to build, worse at scale.
- Either way it is a **new store**, and the original "no new subscription store" ruling is
  superseded by the two-toggle model — not quietly, here in writing.

---

## 6. How per-thread email coexists with the account-level toggles

**What already ships** (bf9e3a1, membership-pages/web/manage-subscription.php:167-176): a
`#lg-email-prefs` card with two switches — **Weekly Digest** and **Event Reminders** — wired at
:202-214 to admin-ajax `lg_weekly_member_state`/`lg_weekly_member_toggle` and
`lg_event_reminder_state`/`lg_event_reminder_signup`, both FluentCRM-list backed.

**PROPOSAL (frame 6) — recommended, with the one alternative below.**

> **Account level = a master switch per email CLASS. Per-thread = which threads are in that class.**

- Add a **third** account row: **Discussion emails** — the master for all per-thread discussion mail.
- **Strict precedence: master OFF ⇒ never send, whatever the per-thread bits say. Master ON ⇒ the
  per-thread bit decides.** There is no state where the account page says off and mail still
  arrives — that is the whole point of the rule.
- The account page does **not** list individual threads (there could be hundreds). It shows the
  master plus a count — *"You get emails from 3 discussions"* — linking into the Hub.
- **§4.3's "stop all discussion emails" writes this same master.** Three surfaces, one store —
  the same discipline the toggles themselves follow.
- **Weekly Digest stays independent.** The digest recap (§3.7 below) rides the Weekly Digest
  switch, not the discussion one, so a member can have discussion emails off and still get the
  weekly recap. That is exactly the point of having a recap.
- ⚠️ Store asymmetry to settle at build time: Weekly/Event are FluentCRM-list backed; Discussion
  emails is naturally usermeta (BB's own per-type meta key is literally what its unsubscribe
  writes). Not a contradiction, but the account UI will be writing two different stores behind one
  card — noted so it is designed, not discovered.

**What this does to a real member:** one obvious place to make all of it stop, while the per-thread
switches stay where the threads are. The page can never say "off" while mail still arrives.

**The alternative — no master; list every emailed thread on the account page.** Honest, but
unbounded: someone following thirty threads gets a thirty-row settings page, and there is no single
"make it stop" control — which is the exact thing people go looking for when they are annoyed enough
to open that page. **Not recommended**; drawn in frame 6 beside the recommendation so the difference
is visible rather than argued.

### 6b ⚠️ CROSS-LANE COLLISION — this section and the weekly-recap lane are designing one page

**Two lanes are editing `#lg-email-prefs` (membership-pages/web/manage-subscription.php:167-214)
from opposite ends.** This spec proposes a third **Discussion emails** master row (§6); the
weekly-recap lane on dev2 is building a per-user digest section governed by the **same Weekly
Digest toggle** shipped at bf9e3a1. Neither lane can land cleanly without the other knowing.

**What this lane needs from the weekly-recap lane — two things:**

1. **The recap must NOT mint its own account-level toggle.** Under §6's rule the account page holds
   one master *per class of email*. The weekly digest is a class; **the recap is content inside that
   class**, not a new class. If the recap ships its own switch (e.g. "My discussions recap"), then
   discussion content is governed by two account controls plus the per-thread bits, and §6's
   guarantee — *the page can never say off while mail still arrives* — is dead. Keep the recap
   governed by the existing `lg-pref-weekly` toggle and nothing else.
2. **Say whether `#lg-email-prefs` stops being append-only markup.** Today it is sibling
   `.lg-pref-row` divs, each wired by `wire(id, stateAction, toggleAction)` (:202-214); §6's row is
   a fourth sibling plus one `wire()` call. If that lane converts the block to a generated list
   (config array, REST-driven render), that is **better** for this spec — but §6's delta then
   becomes a config entry rather than markup, and it must be re-spec'd before build. Either shape
   works. Silence is the problem.

**What it must not touch:**

- **The semantics of `lg_weekly_member_state` / `lg_weekly_member_toggle`.** §3.7 rides that toggle;
  splitting, inverting or making it tri-state breaks both §3.7 and §6's precedence rule.
- **Weekly Digest's independence from discussion email.** A member who turns **Discussion emails**
  off must still receive the weekly recap — that is the entire point of having a recap, and it is
  what makes §9.1's options A and C survivable at all.

**Mechanical:** both lanes edit the same file, so a merge conflict is certain rather than likely —
whoever lands second rebases, and neither hand-edits live (REPO-MANDATE).

**Offered as the shared contract:** *account = one master per email class; per-thread = membership
of that class; a recap is content inside a class, never a class of its own.* If both lanes adopt
that, the page composes with no further coordination.

### 3.7 Weekly digest recap (unchanged from v1, independent of §9.1)

- One section in the existing weekly digest (lg-weekly-digest, FluentCRM campaign to list 3 —
  class-lg-wd-sender.php:29-52; live cadence Mon 09:00).
- Counts + sender display names ONLY, never content:
  > **Your discussions** — 12 new replies this week across 3 discussions you follow, from
  > Doug Proper, Sharon Fisher and 4 others. *[Open the Hub →]*
- Data entirely from the PG mirror: `forums.forum_subscription` ⋈ replies-in-window ⋈ `person`,
  via a loopback `bb-mirror-api/v0/follow-recap?wp_user_id=N`.
- Per-recipient rendering inside a FluentCRM broadcast is new (the digest today is one body for
  all). Candidate: a custom smartcode rendered per subscriber. **Feasibility = dev2 verify (§8);**
  fallback is a generic section in v1.

---

## 7. Mock frames

Published for gating (cookie-gated dev2):
**https://dev2.loothgroup.com/v2/tests/output/threadfollow/index.html**

| # | Frame | Shows | Status |
|---|---|---|---|
| 1 | `card-d-{light,dark}` 1280 | **Feed card** — bell + envelope beside Save, on/off states | **IAN GATED 2026-07-27** |
| 2 | `card-m-{light,dark}` 390 | **Mobile card** — the two icons at the right end of the mobile bar | **IAN GATED 2026-07-27** |
| 3 | `modal-d-{light,dark}` 1280 | **Modal header** *[title] [bell] [mail] [M] [×]*, both states | previs'd |
| 4 | `unsub-d-{light,dark,done}` 1280 + `unsub-m-light` 390 | **Email unsubscribe page** — ask state, done state with Undo (§4.3) | previs'd |
| 5 | (in `modal-d-*`) | **Mobile sheet header** — circular peers between the title and the × | **IAN GATED 2026-07-27** |
| 6 | `account-d-{light,dark}` 1280, `account-m-light` 390 | **Account email prefs** — the third "Discussion emails" master row vs the per-thread-list alternative | **PROPOSAL — §6** |
| 7 | `legacy-d-{light,dark}` 1280 | **The 1,519 existing subscriptions** — the recommendation shown as a member experiences it | **PROPOSAL — §9.2** |
| 8 | `notif-d-{light,dark}` 1280, `notif-m-{light,dark}` 390 | Notifications panel: coalesced row + ⋯ menu carrying both bits | previs'd |

Frames 6 and 7 are the only ones still carrying an open decision; each shows a recommendation and
at most one alternative, side by side, so the choice can be made by looking rather than by reading.
Frames 1, 2 and 5 are gated — their rejected alternatives are gone from the mocks as well as the doc.

---

## 8. Live measurements (read-only via `live-ro`, DB `looth_import`, 2026-07-27)

```
type × status=1        rows      distinct users
  topic               1,519          383
  forum                  46           38
  group              12,948        1,853
```

**Topic subscriptions by likely origin:**

| | rows | share |
|---|---|---|
| subscriber **is the topic author** | 736 | 48% |
| subscriber **replied** in that topic | 355 | 23% |
| subscriber **never posted** in that topic | 428 | 28% |

**1,091 of 1,519 (72%) correlate exactly with involvement** — the fingerprint of legacy
auto-subscribe-on-involvement, the mechanism now outlawed. The 428 never-posted rows are the
population most likely to be deliberate clicks.

**How much is actually live:**

| | rows | users |
|---|---|---|
| topic has **any reply in the last 90 days** | **112** | **49** |
| dormant 90+ days | 1,407 | — |

**93% dormant.** Present-day exposure is **49 people / 112 subscriptions**, not 383 / 1,519.
**Age:** 321 rows from 2026, 583 from 2025, 448 from 2024, 167 from 2023.
**Concentration:** one account (wp id 779) holds 335 topic subs — 22% of the table; it distorts any
"average member" framing.
**Mail:** 31 discussion/reply sends in the trailing 14 days.

---

## 9. OPEN FOR IAN

### 9.1 Per-event email vs digest-only

The original ruling turned all per-event BuddyBoss discussion email permanently off with the weekly
digest as the only surface. On 2026-07-26 Ian received a per-event discussion email and called it
legitimate and wanted.

**The positions are narrower than they look.** The mail he received was a **"New discussion"** email
— the **forum**-subscription path (46 subs / 38 users), which fires only when someone *starts* a
thread. The blanket-off ruling was written against the **"New reply"** path — **topic**
subscriptions (1,519 / 383), firing on *every reply*. Different types, triggers, frequency shapes;
one switch was covering two things.

| | Posture | Consequence |
|---|---|---|
| **A** | **Split the switch** — keep per-event "new discussion in a forum you follow"; route per-reply follow-ups to the weekly digest | Keeps exactly the email Ian called wanted; the high-frequency surface never turns on |
| **B** | **An explicit ✉ toggle earns the email** — with opt-in now the rule, per-event mail for a thread you deliberately ticked is consented-to by definition | Most consistent with the two-toggle model. But an active thread can mean many emails a day, and the only volume control is switching ✉ off |
| **C** | **Original ruling** — all per-event off, digest only | Simplest and quietest; kills the 7/26 email, and 31 sends/14 days suggests the problem it solved is not currently large |

**The two-toggle ruling leans toward B** — a per-discussion ✉ toggle that never sends email is a
contradiction — but that is an inference from Ian's design, not his decision, so it stays here.
Note the original danger is already gone either way: the blanket-off was a precondition of
*auto-subscribe multiplying fan-out*, which opt-in makes impossible.

### 9.2 The 1,519 existing subscriptions

Opt-in governs **new** follows and says nothing about subscriptions that already exist and are
emailing people today. Under §5's mapping these are all **✉ email** subscriptions — one toggle, not
two, which simplifies every option below. Mass-unsubscribing real members is off the table.

> ### RECOMMENDED: option B — grandfather, but surface and make exitable. Frame 7 shows it.
>
> **Change no data. Make every one of those subscriptions visible and one click from off**, in the
> three places a member will actually be when it annoys them: the email itself now carries a
> discussion-specific unsubscribe link (§4); the card and thread now *show* the ✉ bit as on, which
> nothing on the site does today; and the account page names the count with one switch that stops
> all of it (§6).
>
> **Why not just delete the 1,091 that were created without consent?** Because removing them
> unasked is a second change made on the member's behalf, in the other direction — the same class of
> act the opt-in rule exists to stop — and the "created by involvement" signal is a correlation, not
> proof, so 428 apparently-deliberate subscribes would go with them. Making the state visible and
> one tap from off is the honest remedy: people who never wanted it can leave from inside the very
> email that annoyed them, people who did want it keep it, and the population clears itself over
> time without us guessing on anyone's behalf.
>
> **The one alternative worth considering: B + C** — additionally retire the 1,407 dormant
> subscriptions (no reply in 90 days), keeping the 112 live ones. Nobody currently receiving email
> is affected. The cost: if a dormant thread wakes up years later, a member who genuinely subscribed
> stops hearing about it and will never know why.
>
> **Not recommended: D or E.** D unsubscribes 736 topic authors from their own threads, which reads
> as a regression to the person it happens to. E is off the table per the lane brief.

| | Option | Member-visible consequence |
|---|---|---|
| **A** | **Grandfather, change nothing** — all 1,519 become ✉-on | Nobody loses anything, nobody is surprised. But ~1,091 subscriptions created by the banned mechanism keep running; the rule applies forward only |
| **B** | **Grandfather but SURFACE and make exitable** — no data change; card, modal, sheet, ⋯ row and every email footer show ✉ on and are one click from off | Nobody loses anything, and for the first time members can *see* what they're subscribed to and leave. Turns hidden state into visible state — arguably what opt-in is really asking for. Cost: the 1,091 persist until each member acts |
| **C** | **B + retire the dormant tail** — unsubscribe the 1,407 on threads with no reply in 90 days, keep the 112 live | Clears 93% while touching nobody currently receiving anything. Risk: if a dormant thread revives, a genuine subscriber loses mail they wanted and never knows why |
| **D** | **B + retire the involvement-created** — drop the 1,091 authored-or-replied, keep the 428 never-posted | Closest match to the ruling's spirit. But it unsubscribes **736 topic authors from their own threads**, which most members would read as a regression, and the heuristic is correlation, not proof |
| **E** | **Mass unsubscribe all** | Off the table; listed for completeness — 383 members silently lose threads some chose, unrecoverable once deleted |

**Weigh alongside:** practical exposure is 49 people / 112 subscriptions, so A/B vs C differ far
less in lived experience than 1,519 suggests; and this interacts with §9.1 — if per-reply email
ends up off, the legacy rows are a *bell-volume* question, not an *inbox* one, and the case for
touching them weakens.

**Flagged, not folded in:** the **12,948 group subscriptions / 1,853 users** are a third population
this spec has never addressed. Group-linked forums route discussion mail through the GROUP branch
(template 64927) — a live email path with 36× the forum reach. Out of scope and deliberately not
proposed against, but no §9.1/§9.2 decision should be described as "covering the subscriptions"
while it sits untouched.

### 9.3 Smaller open questions

1. ~~Two icons vs one expanding control~~ — **CLOSED, Ian 2026-07-27** (§2.1). Fallback deleted, not archived.
2. ~~Card control on mobile~~ — **CLOSED, Ian 2026-07-27** (§2.2b): right end of the mobile bar.
   Rejected alternative deleted, not archived.
3. ~~Mobile sheet header~~ — **CLOSED, Ian 2026-07-27** (§2.4): circular peers of the ×.
   Rejected placement deleted, not archived.
4. **Digest recap** (§3.7) — may public-forum topic TITLES appear, or names + counts only? *(No
   frame; low stakes, and it does not block anything.)*
5. **Content cards** (§2.2) — v1 is discussion cards only. Should articles/videos get "email me
   about new comments" later, or never?
6. Stale comment at forums.js:4231 says "3 panel sizes (Ian): S / M / L" while `SIZES` has four.
   Cosmetic, adjacent, not fixed by this lane.

---

## 10. Delta summary (what a build lane touches)

| # | File | Change |
|---|---|---|
| 1 | `profile-app/sql/` new migration | widen `notifications_type_check`; **new `forums.topic_follow` table** (§5) |
| 2 | `profile-app/src/Notifications.php:38-43` | add `forum.followed_topic` to `HUB_TYPES` |
| 3 | `profile-app/` internal-notify | **fan-out form** — expand one event to followers (§5) |
| 4 | `lg-shared/notify-bridge.php` | leg 4 in `lg_notify_on_reply` (:170). **No subscribe calls anywhere else — that is the ruling** |
| 5 | `bb-mirror/api/v0/follow.php` **new** | batch GET + per-channel POST + remove_mention (§3.2, §3.6) |
| 6 | `bb-mirror/api/v0/follow-recap.php` **new** | loopback recap counts (§3.7) |
| 7 | `platform/mu-plugins/lg-discussion-unsub.php` **new** | signed link builder + `/discussions/unsubscribe/` confirm page (§4) |
| 8 | `platform/nginx/strangler-bb-mirror.conf` | rewrites for follow + follow-recap; route for the unsub page |
| 9 | `bb-mirror/web/forums/_reply-render.php:666` | `feed_follow_btns()` beside `feed_save_btn()` |
| 10 | `bb-mirror/web/forums/_feed.php:1617-1621` | emit them in the topic card `.fc-actions` |
| 11 | `bb-mirror/web/forums.js:4215-4220` | `.lg-dmodal__notify` + `.lg-dmodal__email`; state where the title is set (:4354) |
| 12 | `bb-mirror/web/forums.js` new module (template = fc-save :3767-3841) | batch hydrate + delegated optimistic toggles across all three surfaces |
| 13 | `webroot/hub-polish.js:3628` | `.lrs-notify` + `.lrs-email` in `.lrs-hd` |
| 14 | `bb-mirror/web/forums.css` | toggle styles + anon hide |
| 15 | `lg-shared/social-modals.js:173-186, :197-212` | `notifText` case; ⋯ button + two-bit popover; handlers |
| 16 | `lg-shared/site-header.css:560+` / `webroot/app-settings.js:255-268` | popover light + dark |
| 17 | `membership-pages/web/manage-subscription.php:167-176` | third pref row: **Discussion emails** master (§6) |
| 18 | `lg-weekly-digest` | recap section (§3.7) |

One new event type. One new small table. Two new WP-pool endpoints. One unsub mu-plugin.
**Zero automatic subscription writes.**

---

*Written from static study of `main@c10df43` on dev1, read-only measurement of LIVE via `live-ro`
(§8), and read-only inspection of the deployed BuddyBoss source for the unsubscribe machinery
(§4.1). Numbers are measured, not asserted. Items tagged "verify" must be proven on dev2 before a
build lane asserts them. No build has started and none may start until Ian approves §0 and answers
§9.1 and §9.2.*
