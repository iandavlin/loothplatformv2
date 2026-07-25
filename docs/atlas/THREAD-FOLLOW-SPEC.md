# THREAD-FOLLOW-SPEC — following discussions, coalesced follow-ups, per-row mute

> **Status: SPEC + MOCK, Ian-gated — no build until Ian approves both.**
> Lane: threadfollow-spec (dev1, docs+mock only), 2026-07-25. Branch `threadfollow-spec`.
> Mock frames: `footer-mockups/threadfollow-notif-panel/` (desktop+mobile × light+dark).
> Cross-refs: NOTIFICATIONS-AUDIT.md (the survey this builds on), DISCUSSION-SURFACE-CANON.md,
> OPERATOR.md §4–5, REPO-MANDATE.md. Everything below cites current `main` (@aad6e3f).

---

## 0. The Ian-CONFIRMED lifecycle (the rulings, one screen)

1. **Involvement auto-subscribes.** Creating a topic, replying in it, or being @mentioned in it
   makes you follow that thread. No opt-in step, no setting to discover.
2. **Follow-ups are ONE coalesced counting row per thread** in the bell — never one row per
   reply. "Alice and 3 others replied in a discussion you follow", count climbing, link always
   pointing at the newest reply.
3. **Opt-out is a per-row ⋯ control IN the notifications panel.** Mute = unsubscribe from that
   thread's follow-ups. Muting does NOT opt you out of @mentions — you stay mentioned.
4. **Remove-my-mention = unlink + unfollow.** The stored mention anchor becomes plain text
   (no link to your profile), and you stop following the thread.
5. **Store = BB/bbPress NATIVE topic subscriptions.** Auto-subscribe writes the native
   subscription; mute writes the native unsubscribe. No new subscription store — the existing
   BB registry + its already-built PG mirror are the truth.
6. **Alerts are BELL-ONLY.** BB subscription emails go permanently OFF (a repo-tracked
   mu-plugin, not a box setting). The only email surface is the **weekly digest recap**:
   counts + sender names, **never content**.
7. **Deep links per the existing notify-bridge contract** — `/hub/?topic=<forum>/<topic>[&reply=<id>]`,
   nothing new.
8. **Parity:** the same notifications panel serves the desktop bell (right-side drawer) and the
   mobile sheet (full-width) — one implementation, CSS-responsive, both themes.

---

## 1. Current machinery (what already exists — read before touching anything)

### 1.1 The bell pipeline (live, one writer, one store)

```
WRITE PATHS                          BRIDGE                    STORE                    UI
reply.php:402-405 ─────────┐
(mobile-sheet replies)     │   lg-shared/notify-bridge.php    profile-app               site-header.php:872-888
bb-mirror-sync.php:225-231 ├─▶ lg_notify_on_reply():170  ──▶  internal-notify.php  ──▶  (#lg-notif-modal)
(native REST replies; G8)  │   lg_notify_on_topic():238      :97 pushHubEvent           social-modals.js:218
bb-mirror-sync.php:175-180 │   lg_notify_on_reaction():266   Notifications.php:105      (loadNotifications)
(new topics)               │   (dedup: mention > reply_to_   (ON CONFLICT coalesce
card-react.php ────────────┘    reply > reply_to_topic,       :122-133, actor_count,
                                one row per person/event)     unread-scoped)
```

- **Dedup rule** (notify-bridge.php:157-169): exactly ONE notification per person per event; the
  most specific type wins (mention → reply_to_reply → reply_to_topic); never your own action.
  The `$notified` set (notify-bridge.php:177) is the mechanism — §2.2 extends it.
- **Coalescing** (Notifications.php:94-133): unique index on
  `(user_uuid, type, target_kind, target_id, COALESCE(anchor_id,0))` scoped `WHERE is_read=false`
  (sql/2026-07-12-notifications-hub-events.sql:64-74). A second actor merges → `actor_count+1`,
  latest actor wins, `target_url` re-pointed. Once READ, the next event rings a FRESH row
  (Notifications.php:99-101). **`forum.reply_to_topic` with `anchor_id=0` already demonstrates
  the exact one-row-per-topic counting shape** (notify-bridge.php:211-223) — the follow-up row
  is the same shape with a different recipient set.
- **Type vocabulary**: PHP allowlist `Notifications::HUB_TYPES` (Notifications.php:38-43) +
  DB CHECK `notifications_type_check` (sql/2026-07-12-notifications-hub-events.sql:49-53).
  Both must widen for a new type — a 2-line delta, designed for this
  (sql comment :28: "target_kind is deliberately NOT an enum/CHECK").
- **Ingest door**: internal-notify.php — loopback + shared secret (:47-49), wp_id→uuid bridge
  (:78-87), unbridged recipients skipped silently (:91-94 — this is what keeps the shared
  anonymous-posting account bell-less), site-relative `target_url` enforced (:70-72).
- **Read/delete contract** (me-notifications.php:10-15): GET list, POST `read`/`read_all`,
  DELETE `?id=` / `?all=1`. Click-through marks the ONE row read (social-modals.js:200-213,
  246-251); per-row × is real server-side delete (:257-277).
- **Badges**: me-social-counts.php returns true ints; UI caps at 9+ (social-modals.js:137-147).
- **Retention**: 30-day prune by age regardless of read state (Notifications.php:273-287).

### 1.2 The subscription machinery (exists, currently INERT)

- **Native store**: BuddyBoss Platform 2.20 forum subscriptions — MySQL
  `wp_bb_notifications_subscriptions` (NOTIFICATIONS-AUDIT.md §2.3). The bbPress-compat write
  API (`bbp_add_user_subscription` / `bbp_remove_user_subscription` / `bbp_get_topic_subscribers`)
  fronts it. ⚠️ exact function/action names on the deployed BB build are a **build-time dev2
  verify** (§6) — this lane has no dev2 access, and OPERATOR.md forbids asserting unverified.
- **Mirror**: BB's subscribe/unsubscribe UI handler action is already synced —
  bb-mirror-sync.php:324-329 (`bbp_subscriptions_handler`) → _sync.php:103-125 → PG
  `forums.forum_subscription` (schema.pg.sql:209-217, PK `(user_id, target_kind, target_id)`).
  The audit calls this mirror "a natural hook point for a native Hub subscribe-notify feature"
  (NOTIFICATIONS-AUDIT.md §3.2) — that is exactly what this spec does.
- **Nothing reads any of it today** — subscriptions drive only the orphaned legacy BB email path.

### 1.3 The email landmine (why "permanently off" is in the rulings)

Every Hub reply replays native BB REST in-process (reply.php:335-342), which still writes legacy
`wp_bp_notifications` rows and arms the BB subscriber/digest email path
(`bb_forums_subscribed_reply` / `_discussion`, `bb_digest_email_notifications_hook` cron —
NOTIFICATIONS-AUDIT.md §1 row 5, §3.2 "live landmine"). When the audit was written there was no
cron driver, so the landmine was dormant. **Since 2026-07-04 `lg-wp-cron.timer` ticks WP cron
every minute (OPERATOR.md §4) — the landmine is now armed on any box whose mail gates open.**
On live, BuddyBoss per-event notification emails are among the things that DO email members
(OPERATOR.md §5). Auto-subscribing every involved member (§2.1) would multiply that fan-out.
**§2.5's kill mu-plugin is therefore a hard precondition of the auto-subscribe write — they ship
in the same change, kill first.**

### 1.4 The deep-link + surface contract (unchanged, cited for completeness)

- URL shape: `/hub/?topic=<forum-slug>/<topic-slug>[&reply=<id>]` — built by
  notify-bridge.php:45-56, encoded like forums.js `shareUrl()` (:4743).
- Router: forums.js §4f (:4659-4762) — desktop ≥641 opens the §4e dmodal, ≤640 the
  `#looth-rep-sheet` via `lgOpenTopicMobile`; `&reply=` anchors + highlights the exact reply
  (:4696-4737).
- Panel: ONE `lg-social-modal` drawer for both surfaces — right-side 400px on desktop
  (site-header.css:452-460), full-width ≤480 (site-header.css:743-745). Dark theming is injected
  by webroot/app-settings.js:255-268 under `html[data-lguser-theme="dark"]` (:145).

---

## 2. The spec

### 2.1 Auto-subscribe on involvement

One shared helper in lg-shared (rides notify-bridge.php — same file, same load points):

```php
lg_follow_on_involve(int $user_id, int $topic_id): void
```

- Writes the **native** subscription (bbp-compat add call → `wp_bb_notifications_subscriptions`)
  AND dispatches the mirror explicitly:
  `bb_mirror_sync_dispatch('subscription', $topic_id, 'subscribe', ['user_id' => $user_id])`.
  Explicit dispatch is required — `bbp_subscriptions_handler` (bb-mirror-sync.php:324) is the
  **UI form-handler** action and does NOT fire on programmatic writes. (If dev2 verify finds a
  low-level `bbp_add_user_subscription`/`bb_create_subscription` action that fires reliably,
  hook the dispatch there instead and the helper stays one call — build-time choice.)
- **Idempotent** (subscribe when already subscribed = no-op) and **fire-and-forget** (a posted
  reply must never fail on follow bookkeeping — same contract as the bell, notify-bridge.php:25-27).

Call sites (mirror the bell's own write points exactly):

| Involvement | Where | Note |
|---|---|---|
| Topic author | bb-mirror-sync.php `bbp_new_topic` hook (:148-181), after the mint | **Skip `_lg_anon` topics** (:128-130) — never subscribe the shared anon account (it's unbridged anyway, internal-notify.php:91-94, but don't write junk subscriptions either) |
| Replier | reply.php after publish (:395-405) AND the native-path `bbp_new_reply` hook (bb-mirror-sync.php:201-232) | The `lg_bb_mirror_reply_owned` double-fire guard (:204) already serializes these two paths; put the call next to the existing bell call in each |
| Mentioned | inside `lg_notify_on_reply` (:180-192) and `lg_notify_on_topic` (:245-255) mention legs | Subscribe each resolved mention recipient; a mention in a thread means you're involved in it |

**Re-involvement re-subscribes.** If you muted a thread and later reply in it (or are mentioned
again), you follow again — the rule is "involvement always subscribes", with no hidden
muted-forever memory. Predictable, storeless, and consistent with rulings 1+3. (Flagged for Ian
veto in §7 — if he wants sticky mutes, that needs a small "muted" tombstone store and the spec
gains a §2.1b.)

### 2.2 Follow-up fan-out: `forum.followed_topic`, ONE counting row per thread

New event type `forum.followed_topic` — added to `Notifications::HUB_TYPES`
(Notifications.php:38-43) and the DB CHECK (migration widens
sql/2026-07-12-notifications-hub-events.sql:49-53 by one value; the coalescing unique index
:64-74 needs **no change**).

New leg **4** in `lg_notify_on_reply` (after :211-223), reusing the `$notified` dedup set:

```
4. Everyone SUBSCRIBED to the topic, minus everyone already rung (mention,
   parent-reply author, topic author) and the replier:
     type        = forum.followed_topic
     target_kind = 'topic', target_id = topic_id
     anchor_id   = 0            ← NULL in the dedup key → ONE row per topic per user
     target_url  = lg_notify_topic_url(topic_id, reply_id)   ← newest reply, re-pointed on coalesce
```

- Coalescing, counting, read-resets-the-row, prune — **all inherited unchanged** from the
  existing `pushHubEvent` machinery (§1.1). This is deliberately the `reply_to_topic` shape
  (notify-bridge.php:213-223) fanned to subscribers instead of the author.
- Subscriber read: `bbp_get_topic_subscribers($topic_id)` on the WP pool (reply.php and the
  mu-plugin both run there with bbPress loaded). NOT the PG mirror — the native store is the
  truth (ruling 5); the mirror is for PG-side reads like the digest recap (§2.6).
- Fan-out cost: one loopback POST per recipient (notify-bridge.php:62-98, 2s timeout,
  fire-and-forget). Fine at expected thread sizes; if dev2 verify (§6) finds monster
  subscriber lists, add a batch array form to internal-notify — contract change, Ian-visible,
  not silently done.
- Bell copy (social-modals.js `notifText`, :160-173, new case):
  `forum.followed_topic` → `notifActors(n) + ' replied in a discussion you follow'` —
  "Alice and 3 others replied in a discussion you follow", via the existing actor_count
  sentence builder (:153-159).

**Recipient-set invariant (unchanged):** one person, one row per event — a subscribed topic
author still gets `reply_to_topic`, a subscribed mentioned member still gets `mention`. The
most-specific-wins ladder just grows a fourth, least-specific rung.

### 2.3 The ⋯ mute control (the mock's subject)

**Placement.** Every hub-event row (`ref.kind` = `topic`|`reply`) gets a ⋯ (overflow) button
between the body and the existing × — `[text/time] [⋯] [×]`. Same 26px round hover-target
styling as `.lg-notif__clear` (site-header.css:560-566). Non-hub rows (connection events) get
no ⋯ — nothing to mute.

**Menu.** A small anchored popover (not a new modal layer), one or two items:

| Row type | Items |
|---|---|
| `forum.followed_topic`, `forum.reply_to_topic`, `forum.reply_to_reply`, `reaction.on_post` (kind topic/reply) | **Mute this thread** |
| `forum.mention` | **Mute this thread** + **Remove my mention** |

- Under the menu title, one line of quiet copy: *"Muting stops follow-ups for this discussion.
  You'll still be notified when someone mentions you."* — ruling 3 spelled out where the user
  acts on it.
- **Mute** → `POST /bb-mirror-api/v0/follow` `{topic_id, action:'mute'}` (§2.4's endpoint) →
  native unsubscribe + mirror dispatch (`'unsubscribe'`). On success the client swaps the row's
  ⋯ to a one-shot "Muted ✓" state (row itself stays — it's still a truthful record and its
  deep link still works; the user can × it as ever). No future follow-up refires: the next
  reply simply finds them absent from the subscriber set.
- The client already knows `topic_id`: `ref.id` IS the topic id for every forum.* type
  (Notifications.php:190-196; reply events store topic in `target_id`, reply in `anchor_id` —
  notify-bridge.php:188-206).
- **A11y/parity**: `aria-haspopup="menu"`, Esc closes, click-outside closes, ⋯ has
  `stopPropagation` like the × (social-modals.js:237-243) so opening the menu never navigates
  the row; ≥44px effective touch target ≤480. One implementation, both widths, both themes
  (dark rides the app-settings.js injection block, §1.4).

### 2.4 The WP-side endpoint: `bb-mirror-api/v0/follow.php`

The bell store (profile-app/PG) cannot write BB subscriptions (MySQL/WP) — mute must land on
the WP pool, exactly where reply.php lives. Same auth posture as reply.php (:5, :81-84):
cookie-authed, `get_current_user_id()` or 401. The caller mutates only their OWN subscription
(`$uid` from the session, never from the body).

```
POST /bb-mirror-api/v0/follow          cookie-authed, self-scoped
  {topic_id:int, action:'mute'}              → native unsubscribe + mirror dispatch
  {topic_id:int, action:'follow'}            → native subscribe   + mirror dispatch   (undo / future UI)
  {topic_id:int, action:'remove_mention', reply_id?:int}   → §2.5
GET  /bb-mirror-api/v0/follow?topic_id=N     → {following:bool}   (cheap state read for future surfaces)
```

Plumbing (both are the established per-endpoint pattern):
- nginx rewrite line in strangler-bb-mirror.conf (:103-111, alongside `reply`).
- The write-freeze map (lg-write-freeze-map.conf:7-10) already catches ALL bb-mirror-api
  writes by prefix — mute is correctly frozen during a freeze, like replies. No change.

### 2.5 Remove-my-mention = unlink + unfollow

Server side (`action:'remove_mention'`), acting user = the MENTIONED member only:

1. Resolve the caller's mention identity: wp uid → the stored anchor is
   `<a class="bp-suggestions-mention" data-lg-uuid="<uuid>" href="{{mention_user_id_N}}">@<slug></a>`
   (_mention-ingest.php:15-27) — match on `{{mention_user_id_<their-wp-id>}}` and/or their uuid,
   **never on the @slug text** (slugs change; ids don't).
2. Rewrite the stored `post_content` of the mentioning post(s) in the topic (or just
   `reply_id` if given): replace each matching anchor with its inner text (`@slug` as plain
   text — visible, but no link, no identity attributes). kses-off `wp_update_post`, exactly the
   re-mint precedent (reply.php:377-385, bb-mirror-sync.php:166-173) — the save hooks re-fire so
   the PG mirror carries the unlinked content automatically. Idempotent: no matching anchor →
   no-op success.
3. Unfollow: same native unsubscribe + mirror dispatch as mute.
4. The client then deletes the mention row via the EXISTING
   `DELETE /profile-api/v0/me/notifications/?id=` (me-notifications.php:61-79) — no new
   bell-side API.

The mention re-minter never resurrects it: minting only converts *resolvable tokens*
(_mention-ingest.php:28-30) — plain text `@slug` with no `@`-context… correction: a bare
`@slug` IS a resolvable token, so step 2 must break re-mint-ability: store the text as
`@ slug`? No — **ruling-faithful form**: replace the anchor with the display name WITHOUT the
`@` sigil (e.g. `Kevin Smith` styled as plain text). No token, nothing to re-mint, the sentence
still reads. This detail is in the mock's remove-mention menu copy and flagged in §7.

### 2.6 Bell-only: the email posture

- **New mu-plugin `platform/mu-plugins/lg-bb-subscription-email-off.php`** (repo-tracked,
  symlink-deployed like its siblings — MONOREPO IS LAW):
  - Unschedules + blanks the BB digest cron (`bb_digest_email_notifications_hook`) so the
    1-minute `lg-wp-cron.timer` can never fire it.
  - Removes/blanks the immediate subscription-email senders
    (`bb_forums_subscribed_reply` / `_discussion` path).
  - Exact hook/filter names = dev2 verify (§6); the mu-plugin carries a header comment listing
    what it kills and why (audit §3.2), and logs once per request-class if it finds an expected
    hook absent (so a BB upgrade that renames hooks is noticed, not silently unprotected).
- **Ships in the same change as §2.1, kill first** (§1.3). On dev2 the mail double-lock already
  contains accidents; live has no such net for BB per-event mail (OPERATOR.md §5).
- No per-event email, no immediate email, ever, for follow-ups. The bell (+ §2.6b digest recap)
  is the whole delivery surface. This implements audit Phase 0 + the in-app leg of Phase 1
  (NOTIFICATIONS-AUDIT.md §4.4) for forum events.

### 2.6b Weekly digest recap — counts + senders, never content

- **Surface**: one section in the existing weekly digest email (lg-weekly-digest, FluentCRM
  campaign to list 3 — class-lg-wd-sender.php:29-52; live cadence Mon 09:00, OPERATOR.md §5).
- **Copy shape** (the whole point — recap, not alert, zero content):
  > **Your discussions** — 12 new replies this week across 3 discussions you follow, from
  > Doug Proper, Sharon Fisher and 4 others. *[Open the Hub →]*
  - Counts + sender display names ONLY. Never reply text, never topic titles of
    private-forum threads (titles of public threads MAY be listed — Ian call, §7).
  - One "Open the Hub" link (`/hub/`), not per-thread deep links, keeps the email inert.
- **Data**: entirely from the PG mirror, no WP round-trips:
  `forums.forum_subscription` (schema.pg.sql:209) ⋈ replies-in-window ⋈ `person` (names).
  Exposed as an internal loopback endpoint `bb-mirror-api/v0/follow-recap?wp_user_id=N`
  (loopback+deny-all like _sync — strangler-bb-mirror.conf:113-120 pattern).
- **Per-recipient rendering** inside a FluentCRM broadcast campaign is the one genuinely new
  mechanism here (the digest today is one body for all). Candidate: FluentCRM custom smartcode
  rendered per-subscriber at send. **Feasibility = dev2 verify (§6).** If unsupported, the
  recap section ships generic ("Discussions you follow had new activity this week") in v1 and
  per-user counts become a follow-up — the bell experience does not depend on this.
- BB's own digest stays dead regardless (§2.6).

### 2.7 What deliberately does NOT change

- Deep-link contract, reply anchor, read-on-clickthrough, per-row ×, Clear all, badge counts,
  30-day prune, unbridged-recipient skip, "never notify yourself", mention > reply dedup ladder.
- The legacy BB notification rows keep being written by the in-process REST replay (audit
  Phase 3 retires them; out of scope here).
- No notification-preferences UI. The ⋯ mute is the only control this ships (audit §4.2's
  prefs matrix remains future work; this spec is compatible with it — mute is just the native
  subscription bit).

---

## 3. Delta summary (what a build lane actually touches)

| # | File | Change |
|---|---|---|
| 1 | `profile-app/sql/` new migration | widen `notifications_type_check` with `forum.followed_topic` (pattern: 2026-07-12-notifications-hub-events.sql:49-53) |
| 2 | `profile-app/src/Notifications.php:38-43` | add `forum.followed_topic` to `HUB_TYPES` |
| 3 | `lg-shared/notify-bridge.php` | `lg_follow_on_involve()` helper; mention-leg subscribe calls; new leg 4 fan-out in `lg_notify_on_reply` (:170) |
| 4 | `platform/mu-plugins/bb-mirror-sync.php` | subscribe calls in the topic (:148) + reply (:201) hooks |
| 5 | `bb-mirror/api/v0/reply.php` | subscribe call next to the bell call (:402-405) |
| 6 | `bb-mirror/api/v0/follow.php` **new** | mute/follow/remove_mention + state read (§2.4-2.5) |
| 7 | `bb-mirror/api/v0/follow-recap.php` **new** | loopback recap counts (§2.6b) |
| 8 | `platform/nginx/strangler-bb-mirror.conf:103-111` | 2 rewrite lines (follow, follow-recap) + loopback block for follow-recap |
| 9 | `platform/mu-plugins/lg-bb-subscription-email-off.php` **new** | the permanent BB email kill (§2.6) |
| 10 | `lg-shared/social-modals.js` | `notifText` case (:160), ⋯ button + popover in `renderNotifItem` (:184), mute/remove handlers |
| 11 | `lg-shared/site-header.css` | ⋯ + popover styles (light), ~30 lines |
| 12 | `webroot/app-settings.js:255-268` | dark rules for the popover, ~4 lines |
| 13 | `lg-weekly-digest` | recap section hook (§2.6b, mechanism pending verify) |

No new stores. One new event type. Two new WP-pool endpoints. One kill mu-plugin.

---

## 4. Mock frames (this lane's second deliverable)

`footer-mockups/threadfollow-notif-panel/mock.html` — the REAL panel markup
(site-header.php:872-888) + REAL tokens/styles (site-header.css:440-574, app-settings.js dark
block :255-268), rows in every state the spec creates:

- a `forum.followed_topic` coalesced row ("… and 3 others replied in a discussion you follow", unread)
- the ⋯ popover OPEN on that row: **Mute this thread** + the stays-mentioned copy
- a `forum.mention` row with its two-item menu variant (Mute / Remove my mention)
- ordinary reply/reaction/connection rows around them (connection row has NO ⋯ — §2.3)
- a "Muted ✓" post-action state

Frames: `shots/desktop-light.png`, `desktop-dark.png` (1280, right-drawer), `mobile-light.png`,
`mobile-dark.png` (390, full-width sheet).

---

## 5. Verify plan for the build lane (dev2, at build time — NOT verified by this lane)

1. BB 2.20 subscription internals: exact write/read function names
   (`bbp_add_user_subscription` / `bb_create_subscription` / `bbp_get_topic_subscribers`),
   actual backing table (`wp_bb_notifications_subscriptions` per audit §2.3), and which
   low-level action (if any) fires on programmatic writes → decides §2.1's dispatch shape.
2. The BB email senders: confirm `bb_digest_email_notifications_hook` is scheduled under
   `lg-wp-cron.timer`; enumerate the immediate subscription-mail hooks → finalize mu-plugin §2.6.
   Verify on dev2 via mailpit that a subscribed non-involved user gets ZERO email on a reply
   with the mu-plugin active.
3. Fan-out scale: `SELECT count(*) … wp_bb_notifications_subscriptions GROUP BY topic` top-N →
   batch-POST decision (§2.2).
4. FluentCRM per-subscriber dynamic content (smartcode/callback) → §2.6b mechanism or fallback.
5. E2E per DISCUSSION-SURFACE-CANON: reply as B in A's followed thread → A gets ONE
   `followed_topic` row; reply as C → same row, "and 1 other", link at C's reply; A reads →
   next reply = fresh row; A mutes → no new rows, A still gets a mention row when @mentioned;
   remove-my-mention → anchor unlinked in stored + mirrored content, A unsubscribed, no re-mint
   on the next edit pass. Both widths, both themes, through the real serve.

## 6. Open questions for Ian (small, none block the mock)

1. **Re-involvement after mute re-subscribes** (§2.1) — confirm, or ask for sticky mutes
   (adds a tombstone store).
2. **Unlinked mention rendering** (§2.5): plain display name without the `@` (spec'd, re-mint-proof)
   vs. keeping a literal `@slug` (would re-link on the next content re-mint — not recommended).
3. **Digest recap**: may public-forum topic TITLES appear in the recap, or names+counts only (§2.6b)?
4. **Muted-row disposition** (§2.3): spec keeps the row with a "Muted ✓" state; alternative is
   auto-removing it.

---

*Written from static study of main@aad6e3f on dev1. Every claim about dev2/live behavior above
is cited to NOTIFICATIONS-AUDIT.md / OPERATOR.md rather than asserted fresh; everything tagged
"verify" in §5 must be proven on dev2 before the build lane asserts it.*
