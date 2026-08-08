# The bridge gap, measured — notif-bridge lane, 2026-08-08

Resumes `docs/atlas/RECAP-NOTIF-BRIDGE-TRACE.md` (recap-notif-bridge, `ba57ad4`). That
trace is upheld in full: for the five notifications Ian reported missing, the bridge
**fired**, and the rows were raised and then deleted. Nothing below contradicts it.

This measures the part the trace did not reach: **is there still an under-reach, and
where.** There is, and it is in a different leg. Everything here is measured on LIVE,
read-only, on 2026-08-08.

---

## 0. The headline

`lg_notify_on_reply()` has four legs. Legs 1–3 (mention, reply-to-reply, reply-to-topic)
are **sound** — they reconcile 7/7 against the access log. Leg 4, "everyone who follows
this topic", reads a store that holds **12 rows** while the real follow population is
**1,515 rows across 381 members**. It is a two-stores problem, not a firing problem.

Ruling 6 makes this the critical path: the bell is now the composer's default follow
channel, and leg 4 is how a follow becomes a bell.

---

## 1. Legs 1–3 reconcile EXACTLY. The bridge fires.

Every reply published on live 2026-08-07..08, against
`POST /profile-api/v0/internal/notify` in `dev.loothgroup.access.log`. Expected pushes
computed from the four legs; topic/parent authorship from `wp_posts`, followers from
`forums.topic_follow`.

| reply | posted (UTC) | author | topic (author) | reply_to (author) | expected | observed | verdict |
|---|---|---|---|---|---|---|---|
| 72586 | 08-07 02:34:23 | 1413 | 72584 (2092) | — | 1 (leg 3) | 1 @02:34:23 | ✓ |
| 72587 | 08-07 15:23:07 | 1773 | 68119 (1773 = self) | 72390 (27) | 1 (leg 2) | 1 @15:23:09 | ✓ |
| 72588 | 08-07 15:47:14 | 160 | 72472 (160 = self) | 72583 (665) | 1 (leg 2) | 1 @15:47:16 | ✓ |
| 72589 | 08-07 17:42:10 | 627 | 72472 (160) | — | 1 (leg 3) | 1 @17:42:12 | ✓ |
| 72592 | 08-08 01:41:02 | 2092 | 72584 (2092 = self) | 72586 (1413) | 1 (leg 2) | 1 @01:41:04 | ✓ |
| 72600 | 08-08 17:14:26 | 1185 | 72584 (2092) | — | 1 (leg 3) | 1 @17:14:27 | ✓ |
| 72602 | 08-08 20:59:38 | 1358 | 72595 (1491) | — | 1 (leg 3) | 1 @20:59:39 | ✓ |

**7 replies, 7 expected pushes, 7 observed, all `200 25` = `{"ok":true,"raised":true}`.**
(The 25-byte read-out is the trace's, §1d — curl sends no `Accept-Encoding`, so nginx
does not gzip the internal call.) Leg-4 skips above are correct: the only follower of
72472 is 160, who is already claimed by legs 2/3 in both of its replies.

So: **path, timing, slug resolution AND firing are all ruled out.** The three the trace
eliminated, plus the one it could not.

## 2. Leg 4 reads the wrong store — the actual gap

`lg_notify_topic_followers()` (`lg-shared/notify-bridge.php:263-283`) queries
`forums.topic_follow` in the `looth` database. BuddyBoss's own follow store is
`wp_bb_notifications_subscriptions` in MySQL. They are **different stores with
different contents**, and only the small one rings the bell.

```
forums.topic_follow                                12 rows      (live, all of it)
wp_bb_notifications_subscriptions type='topic'  1,515 rows / 381 members
```

Measured consequence, 2026-07-25..08-08 (15 days) on live:

| BuddyBoss event | rows | recipients | our counterpart | our rows |
|---|---|---|---|---|
| `bb_forums_subscribed_reply` — a reply in a discussion you follow | 33 | 8 | `forum.followed_topic` | **2** |
| `bb_forums_subscribed_discussion` — a new topic in a forum you follow | 40 | 18 | *(none exists)* | **0** |
| `bbp_new_reply` | 85 | 33 | `forum.reply_to_topic` + `_reply` | 49 |
| `bb_new_mention` | 3 | 3 | `forum.mention` | 7 |

`bb_forums_subscribed_reply` is confirmed as the follow-a-discussion event by
BuddyBoss's own settings map — `bp-core-functions.php:7669`,
`'notification_forums_following_reply' => 'bb_forums_subscribed_reply'`.
`bb_forums_subscribed_discussion` is the **forum**-level one
(`bp-forums/common/functions.php:1414-1417`, fed by `bbp_get_forum_subscribers($forum_id)`)
— we have no equivalent event at all.

### It is Ian's own account, pair-by-pair

Not a totals argument (`trap-delivery-log-and-sub-table-lie-about-the-past`) — the
same member, both stores:

```
Ian (wp 1) — BuddyBoss topic subscriptions:  66434, 72472, 72554
Ian (wp 1) — forums.topic_follow:                   72447, 72554
```

Only **72554** is in both. So for 72472 — which he subscribed to on 2026-08-03 — live
holds BuddyBoss notifications `186141` (reply 72588, 08-07 15:47) and `186144` (reply
72589, 08-07 17:42), and our bell raised nothing, because leg 4 could not see the
follow. Same for 66434.

That is the missing-bell symptom, still live, reproduced on the reporter's own account.

### Scale note before anyone flips this on

`wp_bb_notifications_subscriptions` is not evenly spread: **user 779 alone holds 340
topic subscriptions** (next highest: 646 with 49). Unioning the stores makes 779's bell
ring for every reply in 340 discussions. Coalescing bounds it to one unread row per
topic, but this is a real blast radius and it is why the fix ships flag-OFF.

## 3. The mobile bell cannot render what leg 4 raises

`webroot/bottom-nav.js:1036-1047` — the label switch handles four types and
`forum.followed_topic` is not one of them:

```js
case 'forum.reply_to_topic': …   case 'forum.reply_to_reply': …
case 'forum.mention': …          case 'reaction.on_post': …
default: return ntEsc(who);      // ← a followed_topic row renders as a BARE NAME
```

The desktop twin does handle it — `lg-shared/social-modals.js:190`, *"replied in a
discussion you follow"*, added by the thread-follow lane on 2026-07-28. The mobile
file's own comment at `:1027` claims it *"Mirrors social-modals.js's notifActors()/
notifText() so both surfaces say the same sentence."* It does not. The type landed
after the mobile renderer was written and the mirror was never re-checked.

Confirmed on LIVE's served asset, not just in the repo:
`grep -c "forum.followed_topic" /var/www/dev/bottom-nav.js` → **0**
(`/var/www/dev/bottom-nav.js` → `loothplatformv2-clean/webroot/bottom-nav.js`).

**Both existing `forum.followed_topic` rows on live went to Ian (id 1010, read) and
member 1953 (id 966, unread).** So this has already rendered on the reporter's phone as
a name with no sentence.

⚠️ **This is a prerequisite, not a parallel item.** Fixing leg 4 without fixing the
label ships 381 members a stream of bare names on mobile. Order matters.

## 4. Two further under-reach classes — real, latent, NOT currently firing

Recorded so they are not rediscovered as new:

1. **A moderated reply never rings, ever.** `bb-mirror/api/v0/reply.php:537-542` returns
   `reply_out(202)` for `pending`/`spam` **before** the bell call at `:551`. Approval
   later is a status transition — it fires neither `bbp_new_reply` nor reply.php — so the
   notification is lost permanently, not deferred. Currently dormant: live holds 5,271
   replies, **all** `publish`, 0 pending/spam. It arms the moment moderation is enabled.
2. **No retry, no dead-letter.** `lg_notify_push()` is fire-and-forget with
   `CURLOPT_TIMEOUT => 2` (`notify-bridge.php:80`). A slow or restarting profile-app
   drops the notification forever; the only trace is an `error_log` line nothing reads.
   Correct as a *liveness* choice — a reply must not fail because the bell is down — but
   it means the delivery guarantee is "best effort", which is worth saying out loud now
   that the bell is a default channel rather than a nicety.

## 5. What this lane built — and what is owed

**D1 — delete = dismiss (Ian-ruled). BUILT, flag OFF.**
`profile-app/config/notifications.php` → `dismiss_instead_of_delete`. Row kept and
stamped `dismissed_at`, hidden from the bell, and the recap counts unread AND
undismissed. 36 assertions in `profile-app/bin/notif-dismiss-proof.php`.

> ⚠️ **Honest scope note.** Under "counts unread AND undismissed" this changes NO
> member's recap *content* — a dismissed item was already absent back when dismissing
> deleted it. What changes is that the event SURVIVES: auditable, and recoverable by a
> future policy. If the intent was to *recover* the items the bell eats, the recap rule
> would have to count dismissed-but-unread rows, and that contradicts the ruling as
> recorded. Ian's call, not this lane's.

**D2 — BUILT, flag OFF**, in the order the §3 prerequisite forces:
- `bottom-nav.js` gains the `forum.followed_topic` sentence (not flagged — the OFF
  state of a flag here would mean keeping a known-broken string in front of members).
- `lg_notify_topic_followers()` splits into a native half and a BuddyBoss half,
  unioned. `platform/config/notify-bridge.php` → `bell_follows_bb_subscriptions`.
  14 assertions in `lg-shared/bin/notif-followers-proof.php`.

Gates 16 and 17 (`tools/gates/`), both in `run-all.sh`.

### ⏳ OWED FROM IAN — two flags, and neither is keeper's to flip

1. `dismiss_instead_of_delete` — needs the migration run on live first (he runs the
   SQL), then the flip. See the note above about what it does and does not change.
2. `bell_follows_bb_subscriptions` — the real product question. It reads an EMAIL
   follow as a BELL follow, and ruling 6 made those separate controls on purpose. For:
   71% of those 1,515 rows were auto-subscribed by replying and never chosen (ruling
   4), so treating them as "wants to hear about this" is truer to intent. Against: it
   is still an inference about consent, and inferring consent is how the mail problem
   started. Plus the blast radius is lopsided — user 779 alone holds 340 of them.

### Not in scope, filed not fixed

Both §4 items — the moderated reply that never rings, and the absent retry/dead-letter.

