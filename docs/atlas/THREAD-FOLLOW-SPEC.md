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
10bis. **⚠️ SUPERSEDED — RULED AGAIN, Ian 2026-07-29 (via keeper). READ THIS BEFORE RULING 10.**
    Three rulings, and the first REVERSES ruling 10 below:
    1. **§9.2 IS DECIDED: DEFAULT-OFF APPLIES TO THE 1,519. NO GRANDFATHERING.** At cutover, legacy
       discussion emailing **stops**, and members opt in fresh through the toggles. Option B
       (grandfather + surface) is **dead**; ruling 10's "honour existing, show them as ON" no longer
       governs the ✉ bit.
    2. **THE ENVELOPE EMAILS SHIP THROUGH OUR OWN SEND MECHANISM.** At cutover we **replace** the
       BuddyBoss/bbPress notify path — **not wrap it**.
    3. Ian disputed the send figures; the delivery evidence is **§8.1.7**. Result: the mail is real
       and he is himself a recipient, but the reply path is **6 sends / 6 people / 21 days**.
    **What this changes in the build (§5b):** because our own sender reads **our own store**, the
    native BB subscription stops being consulted the moment the BB path is replaced — so "no
    grandfathering" is achieved **without deleting anyone's data**, which is the outcome Ian rejected
    as "a second unasked change" when the question was framed the other way round. The 1,519 rows
    simply become vestigial. **This is a proposal derived from rulings 1+2, not a fourth ruling.**
10. **RULED, Ian 2026-07-28 — HONOUR EXISTING SUBSCRIPTIONS, SHOW THEM AS ON** *(⚠️ SUPERSEDED for the
    ✉ bit by 10bis above; retained as the record of what was decided and why).* The 1,519 topic +
    46 forum subscriptions are **not wiped and not hidden**. Anyone already carrying one sees the
    envelope **already lit** and can now turn it off. Nothing changes for them silently.
    **Read the ruling precisely:** "both default OFF" (ruling 1) means off for anyone with **no
    existing subscription**; it does **not** mean wiping people who already have one.
    **The governing sentence, and it is a build requirement, not a sentiment:** *the UI must tell the
    truth about what is actually going to happen to that member.* §8.1.3 names the two places where a
    naive implementation would break it — both must be fixed, not documented.
    Explicitly rejected: **wiping** them (silently unsubscribes people who may want them) and
    **hiding** them (the toggle would read off while mail keeps arriving). This closes §9.2 as
    **option B**.
11. **RULED, Ian 2026-07-28 (second ruling) — "GROUP" IS TWO DIFFERENT THINGS (§8.2.4):**
    **TYPE 1 LAYOUT GROUPS** are plumbing — a group created only to force a layout so a forum gets
    its own activity feed. They **produce no notifications and no emails, and never appear in the
    toggle UI**. **TYPE 2 LOCAL LOOTHS** are real geographic communities that *will* need both —
    **but the feature is not built yet, so design the seam and build nothing.**
    **The split is a real field**, not a heuristic: `bp_group_type` — **`loothing` = Type 1
    (5 groups, 9,262 subs, 71.5%)**, **`34507` = Type 2 (9 groups, 3,480 subs)**; one type per group,
    zero ambiguity, totals reconcile to 12,948 exactly (§8.2.5).
    ⚠️ **The term names invert the meaning** — the plumbing is called `loothing`, the real communities
    are called `34507` — so the build keys on the **slug** and implements an **allow-list that fails
    closed** (§8.2.6). Local Looths is unbuilt ⇒ **the allow-list ships EMPTY** ⇒ no group
    subscription produces anything. That is the whole seam.
    This ruling also **disarms §8.2.2's 1,830-recipient hazard**, since that path is Type 1.
12. **STILL OPEN:** the per-event vs digest email posture (§9.1), which carries a recommendation but
    is not decided here.

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

## 8.1 DAY-ONE TRUTH TABLE — what the 1,519 do the moment the toggles ship

> **Measured 2026-07-28 on LIVE** (`live-ro`, DB `looth_import`) against the **deployed BuddyBoss
> 2.20.0 source**, by the thread-follow build lane. This section is the precondition on writing any
> code: *if the UI shows a bit, that bit must be true.* Where it is not true, it is named here.
>
> §8's figures reproduce (113/48 for the 90-day live set vs §8's 112/49 — one day's drift, not a
> discrepancy). Every count below is LIVE, never dev2; the two boxes hold different data.

### 8.1.1 The mechanism, verified from source rather than assumed

| Path | Hook | Recipients resolved by | Inherits? |
|---|---|---|---|
| **New REPLY** email | `bbp_notify_topic_subscribers`, `bbp_new_reply` @9999 (bp-forums/core/actions.php:220) | `bb_get_subscription_users(type:'topic', item_id:<topic>)` | **NO — topic-scoped, full stop** |
| **New DISCUSSION** email | `bbp_notify_forum_subscribers`, `bbp_new_topic` @9999 (…:221) | group subs if the forum is group-linked, **else** forum subs — an exclusive `if/else` (bp-forums/common/functions.php:1382-1416) | n/a — never reads topic subs |

**This is the load-bearing finding.** Reply mail — the thing the ✉ toggle governs — has **no
inheritance path**. Nothing in forum or group subscriptions can produce a reply email. So the ✉ bit
read from `wp_bb_notifications_subscriptions` is a *complete* account of who gets reply mail.

### 8.1.2 Where the UI would be TRUTHFUL

- **All 1,519 rows render ✉ = ON.** No data changes; state that has been invisible since 2023
  simply becomes visible for the first time. 383 members see at least one lit envelope.
- **🔔 renders OFF for all of them**, truthful *by construction* — `forums.topic_follow` (§5) is a
  new, empty table. Nobody is silently opted into the bell.
- **✉ = OFF is truthful for replies.** Proven above, not inferred.
- **0 orphans**: every one of the 1,519 points at an existing user AND a published topic. There is
  no dead-row cleanup hiding in this work.

### 8.1.3 Where the UI would LIE — both directions, named

**(a) Says ON, sends nothing — 40 rows / 7 users.**
BuddyBoss gates every send on `bb_is_notification_enabled($uid, 'bb_forums_subscribed_reply')`
(class-bp-forums-notification.php:1055). Live runs **modern** preference mode
(`bp_is_labs_notification_preferences_support_enabled` defaults 1; the `bb_enabled_notification`
option is populated with 20 entries), so the key is `bb_forums_subscribed_reply` and the admin
default is `main:yes, email:yes` — mail flows unless the member opted out. Site-wide,
**13 users** hold `bb_forums_subscribed_reply='no'` and **2** hold the master `enable_notification='no'`.
Intersected with the topic subscriptions: **40 rows across 7 users** whose card would read ✉ ON
while no email will ever arrive. **Of the 113 currently-live rows, only 3** are in this state.

**(b) Says OFF, mail arrives anyway — the inherited-state gap, and it is the *majority* of today's mail.**
The "New discussion" email is driven by **forum (46) or group (12,948)** subscriptions and fires when
a topic is *created*. A member holding one of those opens a brand-new discussion, sees ✉ **OFF**, and
has already received an email about that very discussion. The per-topic toggle cannot see or set
that state. The toggle's copy ("Email me about new **replies**") is narrowly accurate — but no member
parses it that finely, and this is the first thing that will be found.

Sizing it, trailing 14 days on live: **29 of 33** discussion emails were exactly this "New discussion"
class; only **4** were the reply path. So the population the toggles do *not* govern is currently
sending ~7× the mail of the population they do.

**But the group tail is nearly inert, which shrinks the fix:** of **124** topics created in the last
90 days only **1** was in a group-linked forum (16 such forums exist). The 12,948 group subscriptions
are therefore doing almost nothing today; essentially all 29 sends came from the **46 forum
subscriptions / 38 users**. A remedy aimed at 46 rows is a very different proposition from one aimed
at 12,948.

### 8.1.4 Consequence for §9.2 — the recommendation survives, with one addition

Option **B** (grandfather, surface, make exitable) still holds: the ✉ bit is a *complete and honest*
account of reply mail, so surfacing it tells the truth for 1,479 of 1,519 rows. **But B as written
does not cover 8.1.3(b)**, and that is where most of the mail is. Two additions are needed before B
can be described as "members can see and leave what they're subscribed to":

1. **Reconcile the 40 lying rows.** Cheapest honest fix: the ✉ toggle reads the subscription row
   **AND** the preference gate, showing OFF when the gate suppresses. Turning it ON then clears the
   member's `bb_forums_subscribed_reply='no'` for that member — a deliberate click, which is exactly
   what the opt-in rule wants. **7 members, 40 rows — small enough to get right rather than paper over.**
2. **Give the 46 forum subscriptions a surface.** They are unreachable from any per-topic control by
   construction. Either the unsubscribe link (§4) carries a forum-scoped variant when the mail came
   from the forum path, or the account master (§6) names them. **Not yet spec'd; flagged, not folded.**

### 8.1.5 Two corrections to this document, from the same source read

- **§1.3's "SYNCHRONOUS with the POST" is only true for a single subscriber.**
  `bb_send_notifications_to_subscribers` sets `$background_process = true` whenever
  `$subscriptions['total'] > 1` and hands the send to `$bb_background_updater` in chunks of 20
  (bb-core-subscriptions.php:1135-1163). This morning's 3-recipient reply send went through the
  background updater. It matters because a write-freeze or a rollback window does **not** necessarily
  catch mail already queued.
- **§4 is not adding an unsubscribe link where none exists — it is *replacing* one.** The reply
  sender already injects `bp_email_get_unsubscribe_link($uid, 'bbp-new-forum-reply')` into the
  `unsubscribe` token (class-bp-forums-notification.php:1078-1080), **overwriting** the
  `'unsubscribe' => $topic_url` that `bbp_notify_topic_subscribers` set at functions.php:1249. So
  today's emails already carry a working unsubscribe — at exactly the blanket type-level granularity
  §4.1 ruled out. §4's filter has a concrete, known target rather than an open one.

### 8.1.6 Verified for build (clears §1.2's "⚠️ build-time verify")

All present on the deployed **BuddyBoss 2.20.0** source: `bbp_add_user_subscription`
(bp-forums/users/functions.php:605), `bbp_remove_user_subscription` (:739),
`bbp_get_topic_subscribers` (:235), `bbp_is_user_subscribed` (:434), `bb_create_subscription`
(bp-core/bb-core-subscriptions.php:624), `bb_delete_subscription` (:907).
⚠️ Read from **live's** copy — dev2's plugin tree is mode `0770 looth-dev:loothdevs` and unreadable
to `ubuntu`, so "same build on dev2" is **not proven** and must be confirmed before the build asserts it.

---

## 8.1.7 DELIVERY PROOF — Ian disputed the send figures, so here is the evidence chain

> **Ian, 2026-07-28 (via keeper): "I don't think anyone has been getting those emails, might be in a
> buddyboss setting."** He was right to push: my earlier figure came from `wp_fsmpt_email_logs`, which
> logs send **attempts**, and I had not checked the `status` column. On this box "wp_mail returned
> true" is a known false positive, so the challenge was fair. Re-measured on LIVE 2026-07-29.

**The finding: the emails are real, they left the box, and Ian is himself a recipient.** But his
instinct about the *scale* was sound — the reply path is tiny.

**The chain, each link measured rather than assumed:**

| # | Link | Evidence |
|---|---|---|
| 1 | The path is **enabled** | `bb_is_enabled_subscription('topic')` → `bbp_is_subscriptions_active()` → option **`_bbp_enable_subscriptions = 1`**. *That is the BuddyBoss setting in question, and it is ON.* |
| 2 | The sender **runs** | `bbp_notify_topic_subscribers` hooked to `bbp_new_reply` @9999 (bp-forums/core/actions.php:220) |
| 3 | Recipients **hold subscriptions** | all 6 recipients hold live `type=topic` rows (11, 25, 335, 5, 8, 14) |
| 4 | The per-user gate **allows** | 5 unset (default `yes`), 1 explicit `yes` |
| 5 | FluentSMTP **accepted** | `status='sent'` for **45/45** discussion emails and 4,679/4,679 overall in 14 days |
| 6 | **AWS SES accepted** | every row carries a real SES **`MessageId`** + **`RequestId`** (e.g. `0100019fa9201a22-e22625e2-…`). This is the link that answers the challenge: it is an AWS acknowledgement, not a `wp_mail` return value |

**Reply-path emails, 21 days to 2026-07-29 — the complete list, not a sample:**

| when (UTC) | recipient | subject |
|---|---|---|
| 2026-07-28 10:28 | **ian.davlin@gmail.com** | James Huntley replied to one of your forum discussions |
| 2026-07-28 10:28 | flacrosse82@gmail.com | (same reply) |
| 2026-07-28 08:49 | wgbluetone1@gmail.com | Rick Liftig … replied to one of your forum discussions |
| 2026-07-28 08:49 | james.huntley27@gmail.com | (same reply) |
| 2026-07-28 08:49 | michael@bashkinguitars.com | (same reply) |
| 2026-07-21 07:44 | zwitchguitars@gmail.com | Anthony Kreher … replied to one of your forum discussions |

**So: 6 sends / 6 people / 21 days on the reply path.** Both halves of Ian's position are addressed —
*"nobody is getting them"* is not correct (he received one himself on 2026-07-28), but *"this is not
a large problem"* is: six emails in three weeks.

**⚠️ THE ONE BOUNDARY I CANNOT CROSS FROM THIS BOX, stated rather than glossed:** an SES `MessageId`
proves AWS **accepted** the message for delivery. It does **not** prove inbox placement — a later
bounce, or Gmail filing it under Promotions/Spam, is invisible here because the bounce/complaint
feedback lives in AWS (SNS/CloudWatch), not on the box. **The decisive check is Ian's own inbox:**
search for *"replied to one of your forum discussions"* — he has one dated **2026-07-28 10:28 UTC
(03:28 PDT)** from James Huntley. If it is not there, the message was accepted by SES and dropped
downstream, which is a different and more interesting finding.

**Consequence for the cutover (§9.2): small either way.** Whether or not those six landed, the
population that would notice a cutover is **6 people over 3 weeks**, not 383. The 29 "New discussion"
emails in the same window are the **forum**-subscription path (§8.1.1), which is a separate switch.

---

## 8.2 THE 12,948 GROUP SUBSCRIPTIONS — a separate question, NOT covered by the 2026-07-28 ruling

> Measured on **LIVE** 2026-07-28. Ruling 10 was made about the 1,519 topic + 46 forum
> subscriptions. This population was not known to keeper or Ian at the time. **It must not inherit
> that ruling by analogy**, because on the two facts that mattered to the ruling it behaves in the
> *opposite* way.

### 8.2.1 They are NOT chosen — they are minted by group membership

| | rows |
|---|---|
| group subscriptions (`status=1`) | **12,948** |
| confirmed group memberships | 12,952 |
| subscriptions matching a membership 1:1 | **12,944 (99.97%)** |
| subscriptions with **no** membership | 7 |
| memberships with **no** subscription | 10 |
| subscription timestamped **within 5s of the join** | **12,917 (99.8%)** |

Source confirms the correlation: `BP_Groups_Member::bb_create_group_subscription()`
(bp-groups/classes/class-bp-groups-member.php:1574) — *"Create group subscription when member
join/accept to the group"* — with a matching delete-on-leave immediately below. **Joining a group
subscribes you; leaving unsubscribes you.** No member ever clicked anything.

**This is the decisive asymmetry.** Ruling 10 honours the topic/forum subscriptions because a member
plausibly *chose* them (§8's 428 never-posted rows are the deliberate ones). **Nobody chose these.**
"Show them as ON so the UI tells the truth" and "these represent a member's intent" are different
claims, and only the first one survives here.

### 8.2.2 They CAN email, and the blast radius is the largest on the platform

The group branch is live (`bb_enable_group_subscriptions=1`) and fires on **topic creation** in a
group-linked forum (§8.1.1). Subscriptions are concentrated in five platform-wide groups:

| group | subs | linked forum | topics in it, **ever** |
|---|---|---|---|
| New Builds (32) | 1,853 | 3839 | 2 |
| Market Place (35) | 1,853 | 7547 | **0** |
| Repair and Restoration (31) | 1,852 | 3818 | 3 |
| Tools, Spaces, Robots and Widgets (33) | 1,852 | 3857 | **0** |
| Business (34) | 1,851 | 3873 | **0** |

**One new topic in the New Builds forum emails 1,830 people** — measured, net of the 22 users holding
`bb_groups_subscribed_discussion='no'` and the 2 holding the master `enable_notification='no'`.
For scale: the entire platform sent **33** discussion emails in the trailing 14 days. **A single post
in a forum with two topics in its history is ~55× that, in one event.**

### 8.2.3 Why it has not fired — dormancy, not safety

Across all **16** group-linked forums there are **21 topics in the site's entire history**; **11 have
zero**. The newest is **2026-05-18** (DMV Looths) — which is why nothing appears in the 14-day mail
log and why §8's audit never saw it. Of 124 topics created in the last 90 days, **exactly 1** was in a
group-linked forum.

**So this is not a live problem — it is a dormant one.** Nothing is emailing anyone today. But the
mechanism is armed, the audience is 1,830, and the trigger is *one member starting a discussion in
an ordinary-looking forum*. It is dormant by accident, not by design.

### 8.2.4 ✅ RULED, Ian 2026-07-28 (second ruling): "GROUP" IS TWO DIFFERENT THINGS

> **TYPE 1 — LAYOUT GROUPS.** Created purely as a BuddyBoss mechanism: a group exists to force a
> particular layout so a forum gets its own activity feed. **Plumbing, not communities.** Nobody
> joined them to receive anything. They **must NOT produce notifications or emails, and must not
> appear in the toggle UI at all.**
>
> **TYPE 2 — LOCAL LOOTHS.** The geographic-community initiative. These **are** real communities and
> **will** need notifications and email prompts — **but the feature is not built out or functioning
> yet.** So: **design for it, do not build it.** Leave a clean seam; invent no behaviour for a
> feature that does not exist.
>
> This **supersedes nothing else**. Ruling 10 still governs the 1,519 topic + 46 forum subscriptions.

**This ruling resolves §8.2.2's landmine.** The 1,830-recipient blast radius belongs to **New Builds
(group 32)**, which is **Type 1**. Under this ruling that path must not email anyone, so the hazard is
disarmed by the ruling itself — the answer to the old G3 was effectively *yes*.

### 8.2.5 THE DISCRIMINATOR — there IS a field, and it splits cleanly

**Yes, the two types can be told apart in the data.** BuddyBoss's group-type taxonomy
`bp_group_type` (`wp_term_relationships` → `wp_term_taxonomy` → `wp_terms`, `object_id` = group id):

| `bp_group_type` | groups | **group subs** | share | What it is |
|---|---|---|---|---|
| **`loothing`** | 5 | **9,262** | **71.5%** | **TYPE 1 — LAYOUT / PLUMBING.** Repair And Restoration (31), New Builds (32), Tools/Spaces/Robots/Widgets (33), Business (34), Market Place (35). All created 2024-01-24, all `public`, ~1,851–1,854 subs each ≈ the whole membership |
| **`34507`** | 9 | **3,480** | 26.9% | **TYPE 2 — LOCAL LOOTHS.** Tri State NYC (38), SoCal (39), SW Ontario (40), DMV (41), Looth Troop PNW (42), Ireland (43), Middle Tennessee (45), Basque Country (46), Ohio (47) |
| `chat` | 4 | 199 | 1.5% | Neither — 2023 legacy social groups (General Chat, Music, Charla General, Dank Memes); `enable_forum=0` |
| `leadership` | 1 | 5 | — | Neither — Looth Group Partners (44) |
| *(no type)* | 1 | 2 | — | Neither — The Jannies (36), `hidden` |

**9,262 + 3,480 + 199 + 5 + 2 = 12,948 ✓** — reconciles exactly. **Zero groups carry more than one
type**, so the split is unambiguous: one group, one type, no overlap.

**Keeper's guess was right:** the overwhelming majority — **71.5%** — is Type 1 plumbing, which is
precisely why 12,948 looks absurd next to 1,519 topic subscriptions. It is not 12,948 people choosing
things; it is ~1,853 members × 5 layout groups they were enrolled in automatically.

### 8.2.6 ⚠️ THE FIELD IS SOUND BUT THE TERM NAMES ARE ACTIVELY MISLEADING

**A rule that cannot be applied is not a rule — so this must be said plainly.** The field works, but
its labels invert the meaning:

- **The plumbing is called `loothing`** — which reads like the core community activity, i.e. the
  semantic *opposite* of "inert layout scaffolding".
- **The real communities are called `34507`** — term id 1450, whose `name` **and** `slug` are both the
  literal string `34507`, with an **empty description**. It is an opaque numeric token that carries no
  meaning to anyone reading it.

**Consequences the build must respect:**

1. **Key on the term `slug`, never on the group name, and never on a human reading the label.** A
   maintainer tidying "34507" into "Local Looths" in wp-admin would silently change the slug and
   break the rule with no error.
2. **Implement as an ALLOW-LIST, not a deny-list.** Denying `loothing` fails *open*: any new or
   renamed type would start emailing people. Allowing only known-Type-2 slugs fails *closed* —
   unknown type ⇒ silent. Given Ian's rule is "layout groups must produce nothing", failing closed is
   the only safe direction.
3. **Which makes the seam trivially clean today:** Local Looths is not built, so **the allow-list
   ships EMPTY** and no group subscription produces any notification or email. That is exactly
   "design for it, do not build it" — the seam is one config entry, added the day Local Looths is
   real, and nothing has to be invented now.
4. **Recommend, not decided:** re-slug `34507` to something meaningful before Local Looths ships, so
   the allow-list entry is legible. That is a live data write and therefore **Ian's**, not this lane's.

### 8.2.7 Type 2 sizing, for whoever builds Local Looths later

Largest Type 2 audiences: Tri State NYC **846**, SoCal **845**, Looth Troop PNW **359**, DMV **358**,
SW Ontario **356**, Middle Tennessee **353**, Basque **342**; Ireland **10**, Ohio **11**.

**Worth flagging for that future lane:** the *only* group-linked-forum topic created in the last 90
days (2026-05-18) was in **DMV Looths (41)** — a **Type 2** group with 358 subscribers. So the sole
piece of live group-forum activity on the platform is in exactly the population that will one day be
wired up. It is dormant now, but it is not hypothetical.

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

### 9.2 The 1,519 existing subscriptions — ✅ RE-RULED 2026-07-29: **NO GRANDFATHERING, CUTOVER**

> **THIS IS THE CURRENT RULING AND IT REVERSES THE ONE BELOW.** Ian, 2026-07-29 via keeper:
> **default-OFF applies to the 1,519.** At cutover, legacy discussion emailing **stops**; members opt
> in fresh through the toggles. And the envelope emails ship through **our own send mechanism** —
> we **replace** the BuddyBoss/bbPress notify path, we do not wrap it.
>
> **Why this is now cheap, measured rather than argued (§8.1.7):** the reply path sent **6 emails to
> 6 people in 21 days**. That is the entire population a cutover interrupts. Not 383, not 1,519.
>
> **The cutover, in the order it must happen:**
> 1. **Ship our own sender** reading **our own store** (§5b) — nothing else changes yet.
> 2. **Disable the BB reply path.** The clean switch is the one §8.1.7 identified as governing it:
>    `_bbp_enable_subscriptions`. ⚠️ **But that option ALSO governs the 46 forum subscriptions**
>    (`bb_is_enabled_subscription()` switches `'topic'` and `'forum'` through the *same*
>    `bbp_is_subscriptions_active()` branch) — so flipping it kills the "New discussion" email too,
>    which is **29 of the 33 sends** and the one Ian called *legitimate and wanted* on 7/26.
>    **Therefore: do NOT flip the option.** Unhook the reply path specifically —
>    `remove_action('bbp_new_reply','bbp_notify_topic_subscribers',9999)` — which is surgical,
>    reversible, and leaves the forum path untouched. Recorded here because the obvious lever is
>    the wrong one, and it would be found the hard way.
> 3. **The 1,519 rows are then vestigial** — not deleted, just never read. No live data mutation, so
>    nothing is destroyed and the decision stays reversible.
>
> **Still Ian's to confirm:** whether the ✉ bit's STORE moves to ours (§5b) or stays the native table
> with our sender reading it. Only the first delivers "default OFF for everyone" without a live write.
> **Neither is built yet.** §9.1 also collapses into this: our own sender means the per-event posture
> is ours to choose, not BuddyBoss's.

<details><summary>SUPERSEDED — the 2026-07-28 ruling (option B), kept as the record</summary>

### 9.2-old The 1,519 existing subscriptions — RULED 2026-07-28: OPTION B *(superseded)*

> **IAN RULED (keeper-relayed, 2026-07-28): HONOUR EXISTING SUBSCRIPTIONS — SHOW THEM AS ON.**
> This is **option B** below, adopted as written. Anyone already carrying a topic/forum subscription
> sees the envelope already lit and can turn it off. Nothing changes silently.
> **"Default OFF" means off for anyone with no existing subscription** — it does not mean wiping
> people who have one. Wiping (D/E) and hiding are both explicitly rejected.
> **Binding build requirement from the ruling:** *the UI must tell the truth about what is actually
> going to happen to that member.* That makes **§8.1.3 mandatory work, not commentary** — the 40
> rows / 7 users that would read ON while sending nothing are a violation of this ruling, and so is
> the forum-subscription gap that reads OFF while mail arrives.
> **Scope of the ruling:** the 1,519 topic + 46 forum subscriptions **only**. It does **not** reach
> the 12,948 group subscriptions — see **§8.2**, which is a separate open question.

The options are retained below as the record of what was decided against.

Opt-in governs **new** follows and says nothing about subscriptions that already exist and are
emailing people today. Under §5's mapping these are all **✉ email** subscriptions — one toggle, not
two, which simplifies every option below. Mass-unsubscribing real members is off the table.

> ### ✅ ADOPTED: option B — grandfather, but surface and make exitable. Frame 7 shows it.
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
while it sits untouched. *(Since resolved: Ian ruled on the group populations 2026-07-28 — §8.2.4 —
and the path is now gated in code by `lg-discussion-group-gate.php`.)*

</details>

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

---

## 11. THE EXERCISE PASS — what is now EXECUTED, and what is still only WRITTEN

*thread-follow lane, 2026-07-29, on dev2. Everything below was run; nothing here is inferred from
reading source. The honest headline: **the whole server half is exercised and green; every real
CLICK is still unexercised** and is held behind keeper's memory gate, not behind any doubt about
the code.*

### 11.1 How branch code was run WITHOUT a serve window

The serving checkout `~/loothplatformv2-clean` was never touched — still `main`, still clean, no
symlink added, no nginx change, nothing written to `/var/www/dev`. The branch was run on **loopback
`php -S`, one server per FPM pool, each as the SAME UNIX USER nginx would use**, so the permissions
posture is identical to production rather than merely similar:

| Port | Serves | Runs as | Why that user |
|---|---|---|---|
| 8791 | the Hub (`bb-mirror/web`, router emulating `alias` + `try_files`) | `bb-mirror` | the Hub pool — and it still cannot read `wp-load.php`, exactly as in prod |
| 8792 | `follow.php` | `looth-dev` | the pool the endpoint is actually routed to (it needs WP) |
| 8793/4 | the §4 unsub page | `looth-dev` | WP pool; 8794 loads the plugin as a REAL mu-plugin via `WPMU_PLUGIN_DIR` |
| 8795 | the branch's `internal-notify.php` | `profile-app` | the bell receiver's own pool |

Against the **real** dev2 MySQL (`looth_import`) and the **real** PG (`looth`, `profile_app`). No
mocks, no stubs. Acting user throughout: `claude_admin` (1912) — deliberately not Ian's account.

**This harness is reusable by any lane that needs to exercise unmerged code on dev2.** It is
strictly safer than a serve window: it cannot detach the serving checkout, so it cannot delete the
mu-plugin/webroot symlink set the running system depends on.

### 11.2 EXERCISED — green

1. **`feed_follow_btns()` renders.** 14 topic cards → 28 buttons, both bits, from the branch's own
   PHP. Previously unexecuted markup.
2. **Batch GET.** Anon → `{authenticated:false, nonce:"", state:{}}` — inert, never an error.
   Authed → nonce + `email_master` + all 7 topics `{notify:false,email:false}`. **Default-OFF is now
   a runtime fact, not a source claim.**
3. **POST, both channels.** notify ON → ON again (idempotent, `ON CONFLICT` held) → email ON →
   **notify OFF with email SURVIVING** → email OFF. The fourth step is the one that matters: the two
   bits are provably independent in both directions.
4. **Guards, all seven exact:** anon POST 401 · bad nonce 403 · dead topic 404 · bad channel 400 ·
   missing `on` 400 · `remove_mention` declared 501 · PUT 405.
5. **Stores audited DIRECTLY**, not through the endpoint's own read (a tool that sanitises on read
   cannot audit the store): `forums.topic_follow` ← uid 1912 / topic 72039 (**first row ever**;
   the table was empty), and `wp_bb_notifications_subscriptions` ← id 15831, `type=topic`,
   `item_id=72039`. Two stores, one per bit, exactly as §5 rules.
6. **§4 unsubscribe, END TO END.** Link minted by the plugin's own builder against the real
   BuddyBoss salt → **GET renders the confirmation page and does NOT mutate** (verified by counting
   rows either side — this is the anti-prefetch property, and it holds) → POST removes the
   subscription → `undo=1` restores it → **three tamper cases all 403: bad signature, *different
   topic with a valid signature*, and different user.** The middle one is the proof that the topic
   id is genuinely inside the signed payload, which is the entire difference from BuddyBoss's
   blanket link.
7. **The token swap, in the real send path.** `bp_send_email('bbp-new-forum-reply', …)` produced a
   delivered message (mailpit) whose **only** unsubscribe link is ours — BuddyBoss's blanket link is
   gone. Replaced, not supplemented, as claimed.
8. **§6 coherence, proven at runtime.** "Stop ALL discussion emails" writes the same store
   `follow.php` reads for `email_master`; after the POST the endpoint returns `email_master:false`,
   and after `undo` it returns `true` again. The account page can never say "off" while mail
   arrives, and the UI can never render a lit envelope while nothing will send.
9. **Leg 4 + coalescing.** A followed-topic event through the branch receiver produced **ONE** row;
   a second event from a different actor coalesced into the same row with `actor_count=2` and the
   `target_url` re-pointed at the newest reply. `anchor_id` NULL folds to 0 under the existing
   index, so counting, read-reset and the 30-day prune are inherited with no new logic.

### 11.3 ⚠️ FOUND AND FIXED — the logged-out unsubscribe was one hook-ordering accident from broken

BuddyPress registers `bp_template_redirect` at priority **10** and, for a **logged-out** visitor,
302s to `/wp-login.php?…&bp-auth=1&action=bpnoaccess`. Our page was also at the default 10, so the
only thing keeping it alive was **registration order** — mu-plugins load before regular plugins, so
ours happened to run first.

That is luck, not design, and the audience it fails is exactly the one §4 exists for: someone
logged out, clicking unsubscribe from their inbox. The symptom is a login wall, which reads as "your
unsubscribe link is broken".

**Reproduced** (plugin registered last → 302 to wp-login) and **fixed** by moving to **priority 5**,
then **re-proven at 200 in BOTH orders**. Rationale is in the file header, not just here.

### 11.4 ⚠️ DEPLOY COUPLING — leg 4 and profile-app MUST ship in the same window

A third coupling for the list in `CLAUDE.md`, and the nastiest kind because it is **silent**.

`lg_notify_push()` fires the bell over loopback to
`/profile-api/v0/internal/notify`, and that receiver **validates the type against
`Notifications::HUB_TYPES`**. Measured against the currently-deployed (main) receiver:

```
{"ok":false,"error":"bad_type","allowed":["forum.reply_to_topic","forum.reply_to_reply",
                                          "forum.mention","reaction.on_post"]}   HTTP 400
```

`lg_notify_push()` **fails silent by design** ("a reply that posted must never fail because the bell
was down"), so the 400 is swallowed and the follower simply never gets a bell. **No error surfaces
to anyone.** Against the branch's receiver the identical payload returns `{"ok":true,"raised":true}`.

**Consequence for the cutover:** `lg-shared/notify-bridge.php` (leg 4) and
`profile-app/src/Notifications.php` (the widened `HUB_TYPES`) and the applied SQL `CHECK` are ONE
atomic unit. Ship leg 4 first and every followed-topic notification is dropped without a trace;
ship it in a window where profile-app's FPM pool has not reloaded, same result.

### 11.5 STILL UNEXERCISED — no click has happened

Named plainly rather than softened. **All of these are client-side JS and need a browser engine:**

- both toggles clicked on the **desktop feed card** and the **mobile feed card**
- the **discussion modal header** pair, and the **mobile sheet header** pair
- the notifications-panel **⋯ menu** (§3.5) — both bits, both themes
- the **chip-removal** path
- optimistic-update revert-on-failure, `MutationObserver` re-sync on filter swap / infinite scroll,
  and the `body.fc-save-anon` hide for anon

Held at 2026-07-29 on keeper's memory gate: the browser leg requires `free -m` **available ≥ 800MB**
and the box measured 737MB, then 546MB, at 4 working lanes. Not a code doubt — a seat.

*§11 written from execution on dev2, 2026-07-29. Every number above was produced by running the
thing, on the box named, as the user named.*

---

## 12. THE BROWSER LEG — real clicks, and the defect only a picture caught

*2026-07-29, dev2, engine granted by keeper. WebKit via playwright-core (no Chrome is
installed on this box — `chrome-dev.service` no longer exists; the cached
`~/.cache/ms-playwright/webkit-2311` plus the mentions lane's `playwright-core` is what
is actually available, and WebKit is also the honest proxy for Ian's iPhone).*

**25 assertions, 0 failures.** Harness committed: `tools/exercise-harness/browser-leg.js`.

### 12.1 ⚠️ THE ENVELOPE'S "ON" STATE WAS A SOLID BLACK BLOCK

The defect that justifies the whole leg. Every `aria-pressed` assertion was **passing**
while the icon was visibly broken — it took a screenshot to see it.

`forums.css` expressed the ON state as `fill: currentColor` on the icon, for **both**
toggles, in **four** places (`:624`, `:4137`, `#lg-dmodal`, and the mobile
`.lg-card-actions`). That is right for the bell — its outline is an **open path**, so
filling it reads as a filled bell, exactly the idiom Ian gated. It is **wrong for the
envelope**, whose outline is a `<rect>`: filling it floods the whole rectangle and
paints over the flap, so "emails on" rendered as a **solid dark block** in the feed.
On Ian's phone that is an instant reject, and it would have been read as a broken
build rather than a CSS bug.

**Fixed** at all four sites: the bell keeps `fill: currentColor`; the envelope tints
its rect (`fill-opacity: .18`) so it reads as active while the flap stays legible.
Before/after in `docs/atlas/thread-follow-shots/`.

**The lesson, for the craft gate:** a two-icon toggle pair cannot share one fill rule
when one glyph is an open path and the other is a closed shape. This is a defect class,
not a one-off — per `docs/CRAFT-STANDARD.md` it becomes a gate the second time it is
seen.

### 12.2 What the browser exercised

| Surface | Result |
|---|---|
| **Anon** | `body.lg-follow-anon` set, never marked authed, **no toggle visible** — inert, never an error |
| **Desktop card** (1280×900, `.fc-actions`) | baseline OFF/OFF → 🔔 flips **optimistically**, survives the round trip, **server agrees**; ✉ independent; 🔔 back OFF **with ✉ surviving** |
| **Modal header** (§2.3) | opens from the card title; cluster order measured as **title, 🔔, ✉, size, close**; toggles retargeted to the opened topic; ✉ **carries the state set on the card**; a click **writes through to the store** |
| **Mobile card** (390×844, touch, iOS UA) | toggles in `.lg-act-follow` inside the mobile bar per §2.2b; **44×44px** touch targets; tap on each writes through |
| **Toggle must not open the thread** | clicking a toggle leaves `#lg-dmodal` closed — `stopPropagation` holds |

Every UI assertion is re-checked against the **server**, because an optimistic UI that
flips and silently reverts would otherwise read as a pass. Zero JS errors throughout.

### 12.3 STILL UNEXERCISED — and why, precisely

Two of the four set-surfaces are **not on the Hub page at all**. Verified against the
**real served dev2 Hub**, not just the harness: `curl` of `https://dev2.loothgroup.com/hub/`
contains **no** `hub-polish.js`, **no** `social-modals.js`, and **no** `#looth-rep-sheet`.

- **§2.4 the mobile sheet header** (`.lrs-notify`/`.lrs-email` in `#looth-rep-sheet`) —
  lives in `webroot/hub-polish.js`, a docroot overlay that the Hub does not load.
- **§3.5 the notifications-panel ⋯ menu** — `lg-shared/social-modals.js` **is** referenced
  by the logged-in Hub page (via `_chrome.php:473`'s shared header) and the harness now
  serves the branch copy, and the bell button renders. But the panel populates from
  `/profile-api/v0/…`, which needs a profile-app JWT (`looth_id` cookie) **and** real
  notification rows for the viewer. Reaching it means **building** that surface, not
  testing it — so it is recorded UNEXERCISED rather than faked.
- **§3.6 chip-removal** — endpoint still returns the declared 501; nothing to click.

Whoever finishes these needs a profile-api-backed page, not merely another browser seat.

*§12 written from execution on dev2, 2026-07-29. 25 assertions green; the one defect
found is fixed and re-proven.*

---

## 13. ALL FOUR SET-SURFACES + THE ⋯ MENU — EXERCISED. And a correction to §12.3.

*2026-07-29, dev2, on keeper's shared `chrome-dev.service` (:9222) via CDP — our own
target, created and closed, so no other lane's tab was touched. Totals for this leg:
**14/14** the ⋯ menu, **9/9** dark + the muted note, **9/9** the mobile sheet.*

### 13.1 ⚠️ CORRECTION TO §12.3 — I WAS WRONG, AND THE REASON IS WORTH MORE THAN THE CLAIM

§12.3 said the ⋯ menu and the mobile sheet "are NOT on the Hub page at all", citing a
`curl` of the real served Hub that contained no `hub-polish.js` and no
`#looth-rep-sheet`. **The curl was accurate and the conclusion was wrong.**

`dev2.loothgroup.com.conf:47` carries a **server-level `sub_filter '</head>' …`** that
appends the theme-boot script and `<script src="/pwa.js" defer>`. `pwa.js` then injects
**thirteen** root-level overlays at runtime — including `app-settings.js` (which holds
§3.5's dark rules for the popover) and `hub-polish.js` (which owns §2.4's
`#looth-rep-sheet`). **None of it appears in the app's HTML**, because nginx adds the
loader and the loader adds the rest from JS.

So: grepping served markup for an overlay filename will always find nothing, and
"it isn't on this page" is the wrong inference. Both surfaces *are* on the Hub in
production. The harness was the thing that lacked them.

**Consequences, recorded because they generalise beyond this lane:**
- Any loopback/`php -S` harness that bypasses nginx **silently loses the entire overlay
  layer** — 13 JS files. The page renders and looks right, so nothing announces the loss.
  `tools/exercise-harness/hub-router.php` now reproduces the injection.
- A theme test that emulates `prefers-color-scheme` proves nothing here. This site's dark
  signal is `html[data-lguser-theme="dark"]` (`site-header.php:128`), and the ⋯ popover's
  dark rules ship inside `app-settings.js`. My first dark run "passed" **while rendering
  light**, on a weak assertion. Assert the *painted colour*.

### 13.2 §3.5 the ⋯ menu — 14/14

Surface stood up: branch `profile-app` on its own pool user, `/profile-api/v0/*` proxied
with nginx's own rewrite convention (`me/notifications/` → `me-notifications.php`), a real
`looth_id` JWT minted via `profile-app/bin/mint-dev-token.php`, and two real notification
rows raised through the branch's internal-notify.

Both discussion rows carry the ⋯ (`ref.kind` topic/reply, per `notifCanFollow`). Menu is
`role="menu"`, `aria-expanded` flips true and back, second ⋯ click closes it. **Ticks
mirror the store at every step, asserted against the store either side of each click:**
both off → 🔔 on (✉ untouched) → ✉ on → 🔔 off **with ✉ surviving**. Labels invert with
state ("Notify me about new replies" ⇄ "Stop notifications"). The note "You'll still be
notified when someone replies to you or mentions you" is present.

**Dark verified on painted colour:** menu `#1c1f22`, text `#e5e7e1`, the ⋯ itself sage
`#9cb37d` — and different from light (`#fbfbf8`).

**§8.1.3(a) honesty holds visually:** with the account master off, the ✉ item is
`is-muted` at 0.6 opacity and the menu *says* "Your account has discussion emails turned
off." rather than implying delivery.

### 13.3 §2.4 the mobile sheet — 9/9, plus two defects found

Reachable once `pwa.js` was injected. Opened through `window.lgOpenTopicMobile`
(`hub-polish.js:3714`) — the same entry point §2.4's router uses. Header order measured as
**`lrs-t, lrs-notify, lrs-email, lrs-x`** — title → state → dismiss, exactly as specified.
Tap wrote through to the store.

**⚠️ DEFECT 1 — the envelope flood, FIFTH SITE.** `hub-polish.js:3025` had the same
`.is-on .ico { fill: currentColor }` shared by both glyphs that §12.1 fixed in four places
in `forums.css`. I fixed those four and **missed this one**, because it lives in a
different file and is injected as a JS string. Fixed the same way: bell floods, envelope
tints its rect. **This class has now been found twice in two files — per
`docs/CRAFT-STANDARD.md` it must be a gate, and §13.4 states it.**

**⚠️ DEFECT 2 — touch targets under the minimum on the smallest screen.** Built at a flat
`32×32px` with no expanded hit area, while §2.4 itself asks for "≥44px effective" and the
card surface already measures 44×44 (§12.2). Fixed by keeping the 32px circle visually and
extending the hit area to 44×44 with an invisible `::after` — verified at `44px`.

### 13.4 THE GATE THIS LANE OWES `craft-gate.py`

> **A two-icon toggle pair must not share one `fill` rule when one glyph is an open path
> and the other is a closed shape.** Flooding an open path (bell, star) reads as "filled";
> flooding a closed outline (envelope `<rect>`) paints over its interior detail and renders
> as a solid block. Found 2026-07-29 in `forums.css` (4 sites) and `hub-polish.js` (1 site).
> Gate: for any `.is-on .ico{fill:currentColor}`, assert the icon's own `<rect>`/closed
> outline is not flood-filled.

Also worth a gate, cheaply: **effective touch target ≥44px** on any control inside
`#looth-rep-sheet` / `.lg-card-actions`.

### 13.5 ⚠️ THE EMAIL BIT HAS TWO BACKING STORES, AND THE READ PATH IS THE ONE §5 DOESN'T NAME

Found while resetting test state, and it materially affects the §9.2 cutover.

§5 says the ✉ bit lives in `wp_bb_notifications_subscriptions`. That is where it is
**written**. But `bbp_is_user_subscribed()` — the function `follow.php`, the BB mailer and
the ⋯ menu all read — resolves against the **legacy bbPress user-meta**
`wp_usermeta.wp__bbp_subscriptions` (a comma-joined topic-id list).

Measured on dev2, `bbp_add_user_subscription` / `bbp_remove_user_subscription` keep both in
step **only while they agree**: with a table row present, remove clears both; **with the
table row missing, remove is a no-op and the meta keeps the topic forever.** The UI then
shows ✉ ON, and the member's OFF click returns HTTP 200 and changes nothing — precisely the
"UI lies" class Ian ruled against in §8.1.3.

Divergence already exists in production, small but real:

| Box | users with `type='topic'` rows | users with `wp__bbp_subscriptions` meta |
|---|---|---|
| **live** | 381 | **384** |
| dev2 | 384 | 386 |

**So the obvious §9.2 lever is a trap.** Clearing `wp_bb_notifications_subscriptions` to
make the 1,519 "vestigial" would leave every one of those members with a stale meta entry
that still reads as subscribed and that the API can no longer clear. Any cutover must go
through `bbp_remove_user_subscription` (or clear **both** stores), never raw SQL on the
table — and the "1,519" figure should be re-derived with the meta cross-checked, since the
two stores do not agree today.

*§13 written from execution on dev2, 2026-07-29. Every colour, pixel size and row count
above was measured in the browser or queried from the database named.*

---

## 14. THE DEFECT THAT AUTOMATION COULD NOT SEE — a swallowed tap, and the gate for it

**Ian, from his phone, 2026-07-30 (keeper-relayed):** *"I see the buttons on mobile"* +
*"they don't seem to stay on when pushed."* Both halves have ONE cause, and it is not the
store, the endpoint, or the render.

### 14.1 What it was NOT — three premises killed with measurement, in this order

| Premise | Verdict |
|---|---|
| `forums.topic_subscription` is missing; a migration was lost in the reboot | **FALSE — that table never existed.** The store is `forums.topic_follow` (`schema.pg.sql`; `follow.php:20`) and it is present on dev2. `to_regclass()` on the wrong name returning NULL is not evidence of a lost migration. **No live migration should ever be staged for it.** |
| The render regressed in the 698f683 merge / keeper's conflict resolutions | **FALSE.** SSR emits both surfaces — 6 discussion cards, each with the desktop `.fc-actions` pair AND the mobile `.lg-act-follow` pair (24 `[data-follow]` buttons). Served `forums.css`/`forums.js` are byte-identical to the repo and parse clean. |
| The write fails / does not persist | **FALSE.** Over real HTTPS through nginx as a logged-in member: `POST notify` → row `(1912, 72330)` in `forums.topic_follow`; `POST email` → ok; `GET` readback → `{notify:true, email:true}`. Verified as Ian's own uid 1 too. |

The whole server half was green before anything was touched. **The bit was never written
because nothing was ever sent.**

### 14.2 What it was — `mobile-hub.js` ate the tap

`mobile-hub.js`'s long-press-to-react trigger resolved its hold target with
`el.closest('.fc-actions')`. The mobile follow pair is nested **inside** that container —
measured with a real HTML parser against the served markup, not assumed:

```
div.fc-actions > div.feed-card__actions.lg-card-actions
  > span.lg-act-follow > button.fc-notify[data-follow]
```

So the bell and the envelope were long-press targets. Holding one past `HOLD_MS` (380ms):

1. `pointerdown` starts the hold timer;
2. at 380ms `longPressed = true` and the **reaction palette opens** over the toggle;
3. the release `click` hits the **capture-phase** swallower, which calls
   `stopImmediatePropagation()`;
4. forums.js's `[data-follow]` delegate listens on the **bubble** phase — so it never runs.

Instrumented on a failing 600ms press (`fetch` wrapped, document listener attached):

```
POSTs to /follow:        []       ← nothing ever sent
clicks at document:      []       ← the click never reached the bubble phase
.fcr-palette open:       true     ← the long-press hijacked the gesture
aria-pressed:            'false'  ← the button never even flipped
```

A deliberate press on a 38px phone target crosses 380ms easily — and *more* easily when
the person is testing a control they are unsure about. **A quick flick always worked.**
That fast-passes / slow-fails split is the proof of mechanism: the only variable is the
threshold.

**Scope:** feed card only. The mobile sheet header (`#looth-rep-sheet > .lrs-card > .lrs-hd`,
appended to `body`) and the desktop modal sit outside every `holdTargetFrom` selector and
were never affected. Desktop never loads the module's mobile branch at all.

**Fix:** `holdTargetFrom()` bails on `[data-follow]` / `.lg-act-follow` before the broad
`.fc-actions` match. Deliberately narrow — `mobile-hub.js` is Buck's module, and his
"hold anywhere on the engagement row to react" behaviour is untouched everywhere else.
A press-and-hold is not a reaction gesture on a *subscribe* control.

### 14.3 Why §12's 25/25 and §13 both missed it — and would have missed it again

**Every synthetic click lands in single-digit milliseconds.** CDP
`Input.dispatchMouseEvent` as a pair, Playwright `.click()`, `el.click()` — none of them
can cross a 380ms hold. Every automated tap ever aimed at this control took a path a human
finger cannot take, so the defect was **structurally invisible to the entire test suite**.
It was not a coverage gap that more of the same testing would have closed.

This is the second tap defect on this exact row to reach Ian through a green suite (the
first was the §12 "dead taps" report, fixed in d68786d). Per `docs/CRAFT-STANDARD.md`,
that makes it a **gate**, not merely a fix.

### 14.4 The gate — `tools/gates/follow-longpress-gate.py`

Holds a **real 600ms press** (`touchStart` → *wait* → `touchEnd`) at 390×844 with touch
emulation and the full overlay stack, then checks the **store**, not the pixel — an
optimistic UI that flips and silently reverts must read as FAIL, and only Postgres can
tell the two apart.

| | result |
|---|---|
| unfixed (serving checkout @main) | **9 pass / 7 FAIL, exit 1** |
| fixed (this branch) | **16 pass / 0 fail, exit 0** |

Verified **red on the broken code first** — a gate never run against the defect it claims
to catch proves nothing.

**Two corrections to the gate itself, both found by distrusting a green line:**

1. **Its first draft passed VACUOUSLY.** It cleared the store *after* loading the page, so
   the button had already hydrated to ON and "aria-pressed is true" passed without the
   press doing anything. Every phase now clears state FIRST, reloads, and **asserts the OFF
   precondition** before pressing.
2. **It crashed with a traceback** when hydration lost its race mid-run, and reported that
   as red. Environmental failure is now **exit 2 / CANNOT RUN** per `run-all.sh`'s
   three-state convention — the same convention that exists because craft gate 2 sat "red"
   for weeks while it was in fact dead.

Held out of `run-all.sh`'s numbered sequence because it needs the exercise harness on
:8791/:8792 (not a standing service), with the bring-up recipe recorded there.
`PHP_CLI_SERVER_WORKERS` is not optional: single-threaded `php -S` serialises the ~19
overlay scripts, the page loses its hydration race, and the gate correctly returns exit 2
having proven nothing.

**The lesson worth carrying past this lane:** a surface is not the surface until the
overlay layer is on it (§12's lesson), *and* a gesture is not the gesture until it takes
as long as a human takes.

*§14 written from execution on dev2, 2026-07-30. Every figure above was measured in a real
engine or queried from the database named. All 7 suite gates green; dev2 left clean.*

---

## 15. CONSOLIDATING THE ACTION ROW — two variants, Ian's pick PENDING

**Ian, 2026-07-30 (via keeper), gated on the defect fixes in §14 which are now shipped:**
put 🔔 notifications, ✉ emails, **email FREQUENCY** (Off/Instant/Hourly/Daily/Weekly) and
**Save** behind ONE control that opens a small modal. Like / replies / Share stay inline.

**Mocks (both interactive, not flat frames):**
https://dev2.loothgroup.com/footer-mockups/threadfollow-consolidate/

### 15.1 The two variants

| | Row | Modal | Row controls |
|---|---|---|---|
| **A** | Like · replies · Share · **Follow** | Notifications, Emails, Frequency, **Save** | 4 |
| **B** *(recommended)* | Like · replies · Share · **Save** · **Follow** | Notifications, Emails, Frequency | 5 |

**The recommendation is B, and the reason generalises past this row:** the controls being
merged are not the same *kind*. Save is a reflex hit while scrolling, many times a session;
follow and email are a once-per-thread decision. Putting a frequent action behind a modal to
keep company with two rare ones spends a tap every time to buy width once. B also degrades
better — the bell is already icon-only, so if the row tightens again Save can shed its label
without losing meaning. **A wins if the row is expected to keep growing**: it is the only
version that reaches four controls, and it puts every state control in exactly one place.

### 15.2 THE CONSOLIDATED CONTROL IS NOT A ⋯ MENU — this is a constraint, not a preference

§2.3 ruled the ⋯ menu the wrong surface for follow, **twice**, and ruling 2 says the
affordance must be **visible, not buried**. Consolidation puts pressure on exactly that
ruling, so the mock pays it back explicitly: the control is a **labelled bell that carries its
own state** — lit orange when following, with a small ✉ badge when emails are on. A member
still reads a thread's state from the feed without opening anything. **Any future
implementation that reduces this to a generic overflow menu re-breaks a ruling that has
already been made twice.**

### 15.3 "Off" in the frequency list is the Emails toggle wearing a second hat

Off/Instant/Hourly/Daily/Weekly **plus** an Emails on/off toggle expresses one state twice: a
member can set Emails ON and frequency Off and have no way to know which wins. The mock wires
them as ONE state — choosing Off switches Emails off; switching Emails off snaps frequency to
Off; the frequency row dims when Emails is off so it never reads as a live setting that isn't.

**Open for Ian:** dropping "Off" from the list is the tighter design (the toggle owns on/off,
the segmented control only ever picks a cadence). "Off" was kept because it was specified.

### 15.4 ⚠️ FREQUENCY IS HALF A FEATURE — the sending side is NOT this lane's

Storing a cadence does nothing on its own. Instant/Hourly/Daily/Weekly require something to
**batch and send** the digest, which is **weekly-recap's**. Raised with them on the board
2026-07-30; three questions must be answered before building past the mock:

1. Is there an existing batching/digest scheduler to write this preference into, or is it
   still to be built?
2. **What granularity can actually be honoured** — is Hourly realistic, or should the control
   offer Daily/Weekly only? *Better to drop it before Ian settles on the list than after.*
3. **Where does the per-(member, topic) cadence live?** The 🔔 bit is `forums.topic_follow`
   (PG `looth`); the ✉ bit is the native BB subscription (MySQL). Cadence has **no home yet**,
   and the store should be agreed with the consuming lane rather than chosen unilaterally and
   handed over as a migration.

**Do not ship a cadence control that silently does nothing.** A member choosing "Daily" and
receiving instant mail — or nothing at all — is worse than not offering the choice, and it is
the same class of lie §8.1.3(a)'s `email_master` bit exists to prevent.

*§15 written 2026-07-30. Mocks published and Ian-gated; no code written against either variant.*

### 15.5 THE SENDER DOES NOT EXIST, AND CADENCE IS PROBABLY THE WRONG SHAPE

Two findings from reading the repo rather than waiting on the board, 2026-07-30.

**(a) `lg-weekly-digest` cannot host this.** It is an **editorial broadcast**: one issue
composed by hand, auto-populated from the last 7 days, sent through FluentCRM to List 3 /
tag `all` on a `wp_schedule_single_event` weekly cron (America/New_York). **It resolves its
audience by CRM tag and has no notion of who follows thread X.** Reusing it for per-thread
reply batching would be the wrong mechanism, not a shortcut.

So of the four cadences, **only Instant works today** (the existing per-reply email path).
Hourly / Daily / Weekly each need a per-member queue plus a sweep to flush it — infrastructure
that does not exist. The nearest existing idea is `notify-bridge`, which already coalesces to
ONE bell row per topic (§0 ruling 4); a reply digest is that same idea one level up —
coalesce *across* topics, per member, flush on a cadence.

**(b) Per-discussion cadence defeats the feature it implements.** A digest exists to batch
**across** threads. A cadence set per discussion means following six threads on Daily is six
daily emails — precisely what the member was trying to escape — and a thread on Hourly can
never share an email with a thread on Daily.

> **Recommendation: cadence is ONE account-level preference** — "email me about discussions I
> follow: Instant / Hourly / Daily / Weekly" — which the per-discussion modal **shows** rather
> than owns (`Emails · on — Daily (change)`). The per-thread control stays a clean on/off,
> which is what §0 ruling 1 already made it. One member, one digest, every followed thread in it.

Consequences, all favourable: storage collapses from (member × thread) to one row per member;
it sits with the account-level email prefs that already exist (§6 master/member); and the
sender only ever asks *"who is due now"* instead of *"which of this member's 40 threads are due"*.

**Therefore cadence does NOT belong on `forums.topic_follow`.** That table stays exactly what
it is — the per-(member, topic) 🔔 bit. Cadence belongs with the account email prefs, unless
weekly-recap would rather own the column so its sender reads one store. **Agree the home with
the consuming lane; do not pick one unilaterally and hand them a migration.**

**Open for Ian (on the mock page):** whether frequency lives in this modal (per-thread) or is
shown here and set once in account settings (per-member). **This does not affect the A/B pick.**
**Open for weekly-recap:** whether Hourly is worth offering at all — better Ian never sees it
than picks it and has it withdrawn.

---

## 16. THE "I DON'T SEE THE CONTROLS" REGRESSION — server side cleared, and one real live gap

**Charter, 2026-07-30 respawn (Ian, keeper-relayed):** *"I don't see the controls for it. I
had seen them before."* Two suspects were named. **Both are false, and the first is false in
a way that would have done damage if acted on.**

### 16.1 Suspect 1 — "`forums.topic_subscription` is missing, restore it" — WRONG TABLE

This premise was already killed once (§14.1) and came back in the respawn charter with an
instruction attached: apply the DDL to dev2 and stage it for live. **Doing so would have
created a table no code path reads or writes**, and left the actual gap (§16.4) in place.

`topic_subscription` has never existed on any box. The only occurrence of the string in the
repo is the **name of a trigger** — `topic_subscription_purge`, `schema.pg.sql:487` — which
is exactly what a grep for the table finds and misreads. The store is **`forums.topic_follow`**
(`schema.pg.sql:254`, `follow.php:93,195,198`), and on dev2 it is **present with 2 rows**.

> `to_regclass()` returning NULL proves the name you asked about is absent. It does not tell
> you the feature is broken, and it never tells you the name you asked about was the right one.
> **Confirm the name against the writer before you conclude a migration was lost.**

### 16.2 Suspect 2 — "the 698f683 merge / keeper's conflict resolutions dropped the render" — FALSE

Measured on the real dev2 origin through nginx (loopback + gate cookie), **as anon and as
Ian's own logged-in uid 1** — the logged-in check mattered, because an anon-only pass would
not have covered the surface he is actually looking at:

| surface | `[data-follow]` | `.lg-act-follow` | `fc-notify` |
|---|---|---|---|
| `/hub/` (anon) | 24 | 6 | 12 |
| `/hub/` (as uid 1, Ian) | **24** | **6** | **12** |
| `/hub/general/keeper-test-thread-follow-this-one-ian` | 2 | — | 1 |

24 = 6 discussion cards × (desktop pair + mobile pair). And the served files are not merely
equivalent, they are **byte-identical** to this branch: `_feed.php`, `_reply-render.php`,
`_single-topic.php`, `forums.css`, `mobile-hub.js` (long-press fix included) all `diff`-clean.
`hub-polish.js` differs only by other lanes' later work; its follow handling is unchanged.
All six fix commits are ancestors of the serving checkout.

**The markup, the CSS and the JS are all on the serve, and correct.**

### 16.3 What it therefore is — the overflow defect, already fixed at 18:17 and DEPLOYED at 18:39

The charter names `698f683` as the tip, which dates Ian's report to **before** the fix that
answers it. His symptom is the one already diagnosed in `765dbc3`: the desktop feed-card
action row was ~410px of content in a 349px column, and `flex-wrap: wrap` pushed the
toggles past the card's own `overflow: hidden` — **present in the DOM, painted nowhere.**
That also explains "I had seen them before" exactly: the first merge shipped them visible,
later work widened the row, and the toggles were the items at the end.

Timeline, from git and the filesystem:

```
18:17:03  765dbc3  CSS fix committed (.fc-actions flex-wrap:nowrap + 120px floor)
18:31:53  7eb4685  merged to main
18:39:33           forums.css lands on the serve  ← mtime, and ?v=1785436773 matches
19:25              measured: fix present in served CSS at :4625
```

**Cache staleness is ruled out, not assumed.** `forums.css` is requested as
`forums.css?v=<mtime>`, so the 18:39 write changed the URL; `sw.js` is network-first for
navigations and cache-first *only* for `/icons/`, so the service worker cannot pin an old
stylesheet. A reload gets the fix.

⚠️ **NOT YET PROVEN: that it PAINTS.** Everything above is served-bytes and cascade reading.
The one thing that would close this — a real engine at 1280 and at 390 counting *visible*
toggles — needs the browser seat, which was requested and queued behind shorty-react and had
not been granted when this was written. **An honest "not proven" beats a hedged claim:** the
server half is green, the paint half is inferred from the same CSS arithmetic that `765dbc3`
verified in a browser, and it is not re-verified post-merge.

### 16.4 ✅ THE ONE REAL DEFECT FOUND — `forums.topic_follow` is MISSING ON LIVE

Read-only on live: the table is **absent**, as are `subscription_purge_for_target()` and
both purge triggers. `forums.forum_subscription` is present. The follow code is not on live
yet, so **nothing is broken there today** — but shipping it without the migration gives:

- **read** (`follow.php:93`, inside `try/catch` at `:96`) — swallowed; toggles render
  **permanently OFF for everyone**;
- **write** (`:195/:198`, falling to the outer `catch` at `:218`) — **HTTP 500 on every click.**

A control that reads OFF, accepts the click and never persists is precisely the "UI lies"
class of §8.1.3 that §14 was spent eliminating.

**Staged for Ian's hands: `~/lane-outbox/thread-follow-LIVE-MIGRATION-20260730.md`** — additive,
idempotent, with verify and rollback. The purge trigger is deliberately held back as a
separate decision: on dev2 the 🔔 purge lives inside a function that *also* purges
`forum_subscription`, and installing it on live would change behaviour on a table this lane
does not own. **Nothing was run on live.**

### 16.5 The lesson — a grep hit is not a schema, and a charter is not evidence

Both suspects arrived as instructions with a confident shape. One named a table that never
existed; the other named a merge whose output turned out byte-identical to the branch. The
cheap checks that settled them — `to_regclass` on the name the *writer* uses, and `diff`
against the served file — cost minutes, and the instruction would have cost a live migration
for a table nothing reads.

Also worth carrying: **`/hub/` on `dev2.loothgroup.com` serves from `/srv/bb-mirror` →
`~/loothplatformv2-clean`.** `/home/buck/loothplatformv2` serves the `buck-dev2` host only and
contains **no follow code at all** — a trap that reads as "the feature was never deployed"
for anyone who greps the wrong tree. I walked into it for one step; the fix was reading which
`server_name` includes which strangler snippet.

*§16 written 2026-07-30 from measurement on dev2 and read-only queries on live.*

---

## 17. VARIANT A, BUILT — the consolidated control, the modal, and what is deliberately dark

**Ian, 2026-07-30, verbatim (keeper-relayed):** *"I like variant A because it gets the
card controls down a little bit."*

So §15.1's table resolves to **A**: the feed card's row is **React · replies · Share ·
Follow** (4), and **Notifications, Emails, Frequency and Save all move behind one
trigger**. §15's recommendation was B; Ian picked A on the row-width argument, which is
the reason §15.1 itself listed for A ("the only version that reaches four controls").
Recorded and built as chosen.

### 17.1 The mock is now IN THE REPO — and it was one reboot from gone

The artifact the decision rests on was hand-authored straight into the dev webroot and
tracked by **nothing** — `git log --all` found it in neither the monorepo nor
`~/projects`. It is now `footer-mockups/threadfollow-consolidate/index.html`, committed
byte-identical (`cmp`-verified) and deliberately un-annotated: the file is evidence of
what Ian saw, not a place to write down what he decided.

⚠️ **Deploy coupling, not yet done.** The published path is still the hand-authored
copy. `~/projects/footer-mockups/threadfollow-consolidate` can only become a symlink
into `~/loothplatformv2-clean` **after this branch merges** — the target does not exist
on main yet, so flipping it now would dangle the link and 404 the URL Ian is deciding
from. This joins the mu-plugin and webroot symlink couplings a plain `git pull` does not
handle.

### 17.2 What was built

| piece | where | note |
|---|---|---|
| `feed_follow_control()` | `_reply-render.php` | the labelled bell; `[data-follow-open]`, **not** `[data-follow]` |
| `paintControl()` | `forums.js` | aggregate state, driven from the **same `paint()`** as the toggles |
| the settings modal | `forums.js` | ONE instance, retargeted per topic |
| `.fc-follow` + `.lg-fm__*` | `forums.css` | the mock's values on real tokens |
| Save de-duplication | `hub-polish.js` | narrow guard, see 17.4 |

**The modal's rows are the REAL controls, not copies.** Notifications and Emails are
genuine `[data-follow]` buttons; Save is a genuine `.fc-save`. So all three ride the
existing delegates, the existing batch hydration, the existing optimistic-flip-and-revert
— and the §14 long-press fix with them. Reimplementing them would have been the third
implementation of a bit §0 ruling 8 says has exactly one.

**§15.2 is honoured, not eroded.** The control is a labelled bell carrying its own state:
lit orange (`--lg-follow-on`) whenever either bit is on, a ✉ badge when emails
specifically are on, label flipping to "Following". A member still reads a thread's state
off the feed without opening anything. **It is not a ⋯ menu and must not become one** —
that ruling has now been made three times.

### 17.3 ⚠️ FREQUENCY IS BUILT AND SWITCHED OFF — `FREQ_ENABLED = false`

The row is written, styled and data-driven, and it **does not ship**. §15.4's rule is
explicit: *do not ship a cadence control that silently does nothing.* There is no sender —
§15.5 established `lg-weekly-digest` is an editorial broadcast that resolves its audience
by CRM tag and has no notion of who follows thread X, so of Off/Instant/Hourly/Daily/Weekly
**only Instant is deliverable today**. Two questions are also still open with Ian: whether
"Off" stays in the list (§15.3), and whether cadence is per-thread at all (§15.5 argues it
should be ONE account-level preference this modal *shows* rather than owns).

The option list is therefore **not hardened** — it is an array, so enabling it is a
one-line flag flip *after* those answers land, and nobody has to first think about the
list under time pressure.

### 17.4 The Save duplicate that would have been a lie, caught before it shipped

`hub-polish.js` appends its own `.lg-act-save` to every mobile card. With Save also in the
modal that is **two save controls on one card, and they do not paint each other** — the
row's tracks `lgSavedSet`, the modal's `.fc-save` tracks forums.js's hydration. Saving from
the modal would have left the row's star dark: the §8.1.3 "UI lies" class, not a cosmetic
duplicate. Guarded narrowly — `hub-polish.js` skips its append only when the card carries
`[data-follow-open]`, so Buck's save behaviour is untouched on every card that does not have
the consolidated control.

### 17.5 The mobile-hide rule I nearly re-broke

`feed_follow_control()` emits **two** `.fc-follow` — one into `.fc-actions` (desktop), one
into `.lg-card-actions` (mobile) — exactly as the pair did. The first draft shipped without
a mobile-first hide for the desktop copy, which is **precisely the defect Ian caught on his
phone** ("two empty black squares below the action row", `forums.css:674-688`): at ≤640 the
desktop copy falls through to native `<button>` chrome. Caught by re-reading the rule that
documents the original, not by testing. `.feed-page .fc-actions > .fc-follow {display:none}`
at all widths, re-shown at ≥641, same child combinator and same reason as the pair's.

**This is the third time this exact rule has been needed** (`.fc-share`, the 🔔/✉ pair, now
`.fc-follow`). Any new control emitted into both `.fc-actions` and `.lg-card-actions` needs
it. That is a gate's worth of recurrence.

### 17.6 What is proven, and what is NOT

**Proven** — rendered through the exercise harness on this branch, as the real FPM pool users:

```
/hub/?type=discussions   36 .fc-follow  (18 cards x 2 surfaces)
                          0 .fc-save    (moved into the modal)
                          0 [data-follow] inline on cards
/hub/general/<topic>      2 [data-follow], 1 .fc-save, 0 .fc-follow  (topic page unchanged)
```
PHP lints clean, both JS files pass `node --check`, `forums.css` braces balance.

**NOT proven, and this is the honest limit:** the modal has never been opened in a browser.
Nothing here demonstrates that it paints, that the switches flip, that Save hydrates for the
right topic, that focus returns on close, or that Escape works. There is no jsdom on this box
and the browser seat was held by another lane throughout. `tools/gates/follow-visible-gate.py`
is written for exactly this and needs updating for the new control before it can be believed.
**A structural check is not a paint check** — that distinction is the whole subject of §16.

### 17.7 Deliberately NOT done — the other three surfaces

The consolidated control is on the **feed card only** (desktop + mobile). The standalone
topic page, the mobile reply sheet and the desktop `lg-dmodal` header still carry the
inline 🔔/✉ pair, unchanged and working.

That is a real inconsistency against §0 ruling 8, and it is flagged rather than silently
resolved: Ian's reason was *"it gets the card controls down"*, the mock he approved shows a
**card**, and those other surfaces are not width-constrained the way the card is. Switching
them would change surfaces he has not seen. **Open for Ian:** does the consolidated control
replace the pair everywhere, or is it the card's answer to a card's problem?

*§17 written 2026-07-30. Every count above came from the harness or a linter; every claim
about the browser is marked unproven because none was made in one.*

---

## 18. SAVE STAYS INLINE EVERYWHERE ELSE — the trap, the branch, and the red-first gate

**Ian, 2026-07-30, verbatim (keeper-relayed):** *"btw, we need to keep the save button on
all other post types."*

### 18.1 Why this is a trap and not a preference

The consolidation modal is **follow-shaped**, and follow exists only for `post_type` `topic`
(`follow.php:176`). So on an article or an event **there is no modal to move Save into**.
Move it unconditionally and Save does not relocate — it **disappears**, with no surface left
to reach it from. A member-facing regression on every article and event on the site, shipping
under the banner of a consolidation win.

### 18.2 The branch — and it is structural, not a conditional I added

Topic cards and content cards render from **two physically separate `.fc-actions` blocks**:

| | file:line | Save |
|---|---|---|
| content card row | `_feed.php:1505` | `_feed.php:1508` — `if (in_array($c_cpt, LG_HUB_REACT_TYPES, true)) feed_save_btn($c_cpt, $c_id);` **untouched** |
| topic card row | `_feed.php:1640` | removed — moved into the modal |
| mobile (both) | `hub-polish.js:509` | appends `.lg-act-save` **unless** the card carries `[data-follow-open]` |

`feed_action_bar()` **is** shared, but content cards call it as `feed_action_bar(0, 'Comment')`
— no topic id — so its follow branch (`$topicId > 0`) never fires there. The mobile guard keys
on the presence of the consolidated control, which is the same condition, so both paths agree
without either knowing about the other.

### 18.3 The gate — and the vacuous pass I nearly shipped in it

`follow-visible-gate.py` PHASE 1b asserts the negative on the mixed feed at both widths.

**The obvious way to write it is wrong.** "Count content cards that HAVE a save control, then
assert those are painted" **passes vacuously against the exact build it exists to catch**: move
Save unconditionally, every content card loses it, the count is zero, zero cards are asserted,
green. An emptiness guard only converts that into `CANNOT RUN` — not a pass, but not the red it
should be. This is the same trap the longpress gate's first draft fell into (§14.4), one gate
later, and it was caught by asking what the gate would *do* on the broken build rather than by
running it.

So cards are classed as content **by structure** — no `[data-follow-open]` — and *then* at least
one is required to still carry Save. Zero is a finding.

**VERIFIED RED-FIRST**, both builds served through the exercise harness as the real pool user:

| build | topic cards | content cards | content cards with inline Save | PHASE 1b |
|---|---|---|---|---|
| this branch | 6 | 12 | **7** | PASS |
| Save moved unconditionally | 6 | 12 | **0** | **FAIL — caught** |

The content-card count is **12 in both**, which is what makes this a real red rather than an
environmental one: the feed is intact, the topic side is unaffected, and only the asserted
property moves.

⚠️ **Two traps inside the red-first run itself**, both of which would have produced a fake proof:

1. **`hub-router.php` HARDCODES `$ROOT`** to the worktree (`:17`), so `php -S -t <other tree>`
   serves the worktree anyway. The first red run returned markup **byte-identical** to green and
   would have been reported as "the gate does not fire" — or, worse, a patch that "did nothing".
   The router's `$ROOT` must be repointed, not just the docroot flag.
2. Copying `bb-mirror/web` alone 500s — `index.php:25` requires `__DIR__/../config.php`. A 500
   yields *zero* cards, which reads as red for the wrong reason. **The whole `bb-mirror` parent
   must be copied**, and a red build that returns no cards at all should be distrusted before it
   is believed.

### 18.4 A PRE-EXISTING gap, flagged and not fixed

5 of 12 content cards (`post-type-videos`, `loothprint`) carry **no save control at all** on the
hub. **This is not variant A's doing** — the identical 5, same types, render the same way from
the serving checkout on `main`. `post-type-videos` appears both with and without Save, so it is a
card-*variant* difference, not a post-type rule.

It is left alone deliberately: Ian's instruction is to **keep** Save where it is, and this is a
pre-existing absence in someone else's render path. The gate therefore asserts "**some** content
card keeps Save", not "all" — asserting all would red-flag a condition this lane did not cause
and cannot fix. **Worth someone's attention, and it is not this lane's to fix silently.**

### 18.5 §15.4 ANSWERED — Hourly is dropped

**weekly-recap, 2026-07-30:** drop Hourly. The reason is measurement, not taste: **no member on
live has ever had two forum notifications in the same hour**, so an hourly digest is a strictly
worse Instant — it adds delay and batches nothing. Dropped from `FREQ_OPTIONS` before Ian ever
saw it, which §15.4 argued is the cheap moment to do it.

Frequency is now **Off · Instant · Daily · Weekly**, still `FREQ_ENABLED = false`, still an array
so §15.3 ("Off" in or out) stays a one-element data change and never a re-layout.

**Still open, and still Ian's:** §15.3. **Still open, and a contract not a choice:** where cadence
is stored — raised with weekly-recap on the board rather than picked unilaterally, per §15.4's own
rule and their framing that it is the difference between zero new state and a per-(member,thread)
ledger.

*§18 written 2026-07-30 from harness runs on both a green and a deliberately broken build.*
