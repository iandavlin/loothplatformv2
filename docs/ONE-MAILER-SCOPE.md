# ONE MAILER — scope, and what has to be true before BuddyBoss can be retired

Ian, 2026-08-08: *"I don't want users to get emails from two different systems. I want
just one from ours. I also don't want them to get emails from our legacy group system
for forums."*

Everything below was measured on **live, read-only, 2026-08-08**. Code paths were read
in the deployed BuddyBoss 2.20.0 tree on dev2 (same version as live). Where a number
comes from an earlier session it is marked as such and not re-derived.

Extends `docs/IAN-RULINGS-2026-08-03.md` §4 and §5. It does not restart that project.

**Terminology is `docs/EMAIL-GLOSSARY.md` (Ian, 2026-08-08).** "Digest" means the
editorial **Weekly Digest** and nothing else. What `lg-follow-digest.php` sends is the
**follow roundup** — cadences *instant* (a per-reply follow notification), *daily
roundup*, *weekly roundup*. Code names are unchanged; this doc uses the pinned terms.
The charter's A/B/C shorthand is dropped here in favour of them.

⚠️ Ian ran the group-sub sweep (ruling 5) partway through this work. §4 is written
against the **post-sweep** state and the draft SQL that preceded it has been removed —
it would have trampled his decision to keep the regional groups.

---

## 0. The headline, before the detail

Four things that were assumed going in turned out to be wrong. Three make the job
**smaller**; the fourth is the one that needs Ian:

1. **BuddyBoss's group mailer was already dead in its main leg before the sweep** — not
   by BB config, but by our own `lg-discussion-group-gate.php`. That is why 13,032 group
   subscriptions had produced no mail in the entire logged window.
2. **The "84% delivery" gap is an artifact**, not a leak. Nobody is silently unmailed.
   The real reconciliation is 22 matched / 3 / 4, and every one of the 7 is explained.
3. **We found a real double-send**, and it hit the one member the follow roundup is
   switched on for. That is a blocker for widening, and it is §5. Seven candidate
   mechanisms were tested and all seven are refuted.
4. **⚠️ What the sweep KEPT is probably not what it looks like.** 3,735 subscriptions
   across 15 groups were deliberately left armed, but per §3 the only thing they can
   still deliver is the group *activity-update* email — the discussion leg is dead for
   every group. One activity update in Tri State Looths mails **853 people** who never
   chose it. §4 lays out the two coherent options.

---

## 1. THE MAP — what BuddyBoss actually mails members

BB drives all member-activity mail off one table, `wp_bb_notifications_subscriptions`,
through one dispatcher, `bb_send_notifications_to_subscribers()`
(`bp-core/bb-core-subscriptions.php:1066`). There are exactly **three** subscription
types, and exactly **three** trigger sites in the whole plugin. This is the complete
set — enumerated from code, not from the log:

| type | rows | members | email sent | trigger | per-recipient email veto filter |
|---|---:|---:|---|---|---|
| `topic` | 1,515 | 381 | `bbp-new-forum-reply` — *"X replied to one of your forum discussions"* | new reply (`bp-forums/common/functions.php:1267`) | `bb_send_forums_subscribed_reply_email_notifications` |
| `forum` | 46 | 38 | `bbp-new-forum-topic` — *"New discussion: X"* | new discussion in a **non-group** forum (`functions.php:1434`) | `bb_send_forums_subscribed_discussion_email_notifications` |
| `group` | 13,032 | 1,830 | `groups-new-discussion`, `groups-new-activity` | new discussion in a **group-linked** forum (`functions.php:1434`); group activity update (`bp-groups/bp-groups-filters.php:1203`) | `bb_send_subscribed_group_email_notifications` |

Every one of those three filters has the same three properties that made the topic one
the right seam (see the comment block above `lg_fd_suppress_instant`): **per-recipient**,
**email-only** (the bell keeps working — `..._notifications` without `_email_` is a
separate filter), and **unclaimed** by us.

### The if/else that matters

`bbp_notify_forum_subscribers()` (`functions.php:1384-1417`) is **exclusive**:

```php
if ( bp_is_active('groups') && bb_is_enabled_subscription('group') && ! empty($item_id) ) {
        $type = 'group';    // → ALL group subscribers. 1,830 for the five main groups.
} else {
        $type = 'forum';    // → forum subscribers only. 38 members.
}
```

A new discussion in a group-linked forum goes to **group** subscribers and never to
forum subscribers. This is why the two populations look unrelated in the log.

### Volumes actually observed

⚠️ `wp_fsmpt_email_logs` only reaches back to **2026-07-25** (5,226 rows). Every
"observed" number below is bounded by that 14-day window — this is a delivery record,
not a history.

- **reply mail**: 26 sends in the last 7 days, to 9 distinct people. Small.
- **"New discussion:" mail**: 39 sends 07-26 → 08-08, to **18 distinct recipients**.
- **group mail**: **zero**, for the whole logged window. §3 explains why, and why that
  is not the same as "safe".

---

## 2. DELIVERABLE 2 — what the follow roundup must absorb before BB's mail can be switched off

Our follow roundup covers `type='topic'` and nothing else (`lg_fd_items_for()` joins
`type='topic'` literally). Taking the three in turn:

### 2.1 `topic` — replies. **Already covered.** No work.

The follow roundup replaces it exactly, per-recipient, and the allowlist is Ian-only
today. Widening is ruling 4 and is held pending §5 below and Ian's instant-vs-weekly-
roundup call.

### 2.2 `forum` — *"New discussion: X"*. **THE REAL GAP. The roundup must grow.**

38 members hold 46 forum subscriptions; 18 of them were mailed in the last two weeks.
This is a genuinely wanted signal, and that claim is load-bearing enough to have been
checked in the source rather than assumed:

- **Topics auto-subscribe you for posting.** `bp-forums/replies/functions.php:1006`
  calls `bbp_add_user_subscription( $author_id, $topic_id )` when you reply. That is
  where the 71% of topic subscribers who never chose email come from.
- **Forums have no equivalent.** The only callers of `bbp_add_user_forum_subscription()`
  are the explicit subscribe/unsubscribe handlers (`users/functions.php:952`, `:1056`) —
  i.e. somebody clicking the control. Nothing creates a `type='forum'` row as a side
  effect of posting, joining or registering.

So **all 38 chose it**, which is exactly the opposite of the group population and is why
these two get opposite recommendations.

**Recommendation: the roundup absorbs it, and it is a small change.** It already renders
"N new replies in a discussion you follow". It needs a second item kind — "N new
discussions in a forum you follow" — sharing the same watermark, the same cadence, the
same suppression seam (`bb_send_forums_subscribed_discussion_email_notifications`,
identical shape to the one we already own). No new schedule, no new store.

**Do not let it die unreplaced.** These 38 opted in explicitly and get roughly 3
messages a week; silently dropping them is the "mail black hole" failure the roundup was
built to avoid, just relocated.

### 2.3 `group` — **Recommendation: DIES UNREPLACED. Do not build a replacement.**

Ian said it in as many words: *"I also don't want them to get emails from our legacy
group system for forums."* Beyond that ruling, the evidence says nobody would miss it:

- **Nobody chose it.** All 13,032 rows are side effects of a group join, created by
  `BP_Groups_Member::save()` → `bb_create_group_subscription()`
  (`class-bp-groups-member.php:321`). Not one is an opt-in.
- **The subscription is per-GROUP, and the five main groups hold all 1,830 members.**
  So "subscribed" here means "will be told about every discussion on the site". That is
  the **Weekly Digest**'s job, and the Weekly Digest already does it — to ~1,860 people,
  editorially curated. A second, uncurated copy of the same firehose is precisely the
  "two systems" Ian is objecting to.
- **Its discussion leg has been off for weeks already** and nobody has reported a loss.

So the end state is: **the follow roundup covers topic + forum. Group mail is deleted,
not migrated.**

---

## 3. Why group mail is silent — and why that is NOT the same as safe

The group type has two legs. They are in very different states, and conflating them is
the trap here.

### Leg A — new discussion in a group forum: **hard-stopped by our own code.**

`platform/mu-plugins/lg-discussion-group-gate.php` hooks
`bbp_forum_subscription_user_ids` and returns `[]` unless the group's `bp_group_type` is
in `lg_discussion_group_allow()` — **which is empty on purpose** (Ian, 2026-07-28:
"design for it, do not build it"). An empty recipient list makes
`bbp_notify_forum_subscribers()` bail *before* dispatching, so nothing is queued.

That gate is doing far more work than its name suggests. It is the sole reason 1,830
members are not receiving mail for every discussion on the platform, and it was written
for an unrelated reason (Local Looths). **It should be recorded as the group-mail kill
switch it actually is**, because right now a future lane adding one slug to that
allow-list would turn on a 1,830-person mailer as a side effect and nothing would warn
them.

### Leg B — group activity update: **UNGATED. This is the latent blast.**

`bp_groups_posted_update` → `bb_subscription_send_subscribe_group_notifications()`
(`bp-groups-filters.php:1203`) calls the dispatcher **directly**. It does not pass
through `bbp_forum_subscription_user_ids`, so our gate never sees it. Nothing we own
gates it. BB's own defaults leave it on (`bb_enable_group_subscriptions` = `1` on live,
verified).

Is it reachable? Yes:

```
wp_bp_activity, component='groups':   activity_update  38 rows, newest 2026-04-30
```

Rare, but real, and members can do it — the `activity` component is active. The last one
predates the email log entirely, so **this path has never been observed either way.** I
am not claiming it is broken and I am not claiming it works.

What I am claiming is the shape of the risk. Before ruling 5's sweep it was: *one group
activity update in Repair And Restoration mails 1,830 people, and the only thing standing
in the way is that nobody has happened to post one since 30 April.*

**The sweep removed that for the five layout groups and left it standing for the fifteen
kept ones.** Post-sweep the same sentence reads: one activity update in Tri State Looths
(NYC) mails 853 people. Smaller, still ungated, and now the largest unreviewed email
surface on the platform — which is why §4 asks Ian to decide about it rather than
treating the sweep as the end of the job.

---

## 4. DELIVERABLE 1 — the sweep RAN while this was being written. Here is what is left.

`docs/IAN-RULINGS-2026-08-03.md` §5 records it: Ian deactivated `bp-auto-group-join`
himself, then swept the **five forum-layout groups** — 9,297 subscriptions to status 0 —
and **kept the regional Looths, Jannies, Partners and chat groups by explicit
instruction**. Verified independently here over `live-ro`, 2026-08-08:

```
type='group'   status=0   9,297 rows / 1,830 members     <- swept
type='group'   status=1   3,735 rows / 1,007 members     <- KEPT, and still armed
wp_lg_group_unsub_20260808                9,297 rows     <- the rollback set, intact
wp_bp_groups_members (confirmed)         12,877 rows     <- untouched, layout survives
```

**The sweep SQL that was drafted here has been deleted rather than left in place.** It
swept all 13,032 and would have trampled a decision Ian had already taken. Anyone
needing the shape of that statement should read ruling 5 and
`wp_lg_group_unsub_20260808`, not this file.

### So: does the bridge-breaker still need building? **No — and it never was the fix.**

The join-veto mu-plugin would only stop *new* subscriptions. The risk was always the
rows already there, and 9,297 of them are now disarmed by one `UPDATE`. Nothing in the
original D1 build would have improved on that.

### ⚠️ But what Ian kept is probably not what he thinks he kept

This is the finding worth his attention, and it follows from §3 rather than from the
sweep. The 3,735 armed rows are spread across 15 groups:

| group | armed subs | | group | armed subs |
|---|---:|---|---|---:|
| 38 Tri State Looths (NYC) | 853 | | 21 General Chat | 97 |
| 39 SoCal Looths | 852 | | 24 Dank Memes | 53 |
| 42 Looth Troop PNW | 366 | | 22 Music | 35 |
| 41 DMV Looths | 365 | | 23 Charla General | 14 |
| 40 SW Ontario Looths | 363 | | 47 Ohio Local Looths | 11 |
| 45 Middle Tennessee Looths | 360 | | 43 Looths of Ireland | 10 |
| 46 Basque Country Looths | 349 | | 44 Looth Group Partners | 5 |
| | | | 36 The Jannies | 2 |

Keeping a group subscription reads as "these members can still be told about activity in
their regional group". **It does not currently mean that.** Per §3, the group type has
two legs, and for *every* group on the platform:

- **the discussion leg is dead** — `lg-discussion-group-gate.php` returns an empty
  recipient list for every group, because its allow-list is empty on purpose. So a new
  discussion in Tri State Looths notifies nobody, kept subscription or not.
- **the activity leg is live and ungated** — `bp_groups_posted_update` reaches the
  dispatcher directly.

So what those 3,735 rows actually buy is **exactly one thing: the group activity-update
email**. Not discussions. One activity update in Tri State Looths mails **853 people**,
none of whom chose the subscription (they were auto-joined), and that is the largest
single unreviewed email surface left on the platform.

**Ian should decide knowing that.** Two coherent options, and this lane recommends the
first:

1. **Sweep the remainder too.** It makes the kept groups consistent with the swept ones,
   and costs nothing that is currently working — because nothing currently works through
   them. Same statement shape as ruling 5's, same reversibility, new backup table.
2. **Keep them and make them mean something** — i.e. add the regional groups' type slug
   to `lg_discussion_group_allow()` so the discussion leg turns on for them. That is a
   product decision to start mailing ~850 people per regional discussion, and it should
   be taken deliberately, never as a side effect of a lane editing that allow-list.

What must not happen is the current state persisting *unexamined*, where the kept rows
look like a considered exception but deliver only the one notification nobody evaluated.

### The residual decay, now small and precise

`bp-auto-group-join` is off, so registration no longer refills anything. What remains is
manual joins, and only into the 15 kept groups — every join still creates a `status=1`
row via `BP_Groups_Member::save()`. If option 1 is taken, that decay is the argument for
the durable version: a ~15-line tracked mu-plugin hooking
`bb_send_subscribed_group_email_notifications` and returning `false`, which cannot decay
because it kills the mail at the send seam rather than the subscription store. It also
covers the ungated activity leg by construction.

Do **not** reach for `bb_enable_group_subscriptions` instead. Filtering that to false
flips the §1 exclusive if/else, so group-forum discussions would start mailing *forum*
subscribers instead of nobody — more mail, not less.

### ✅ THE MECHANISM THE SWEEP RELIES ON IS PROVEN

Ruling 5's sweep, and any repeat of it, rests on "`status=0` makes the sender blind to
those rows". That was inferred from `bb_send_notifications_to_subscribers()` resolving
recipients with `status => true`. It is now demonstrated on dev2 — group 36, counting
**queued background jobs**, which is the dispatcher's real output and needs no email
template to render:

```
ARMED    (status=1):  dispatcher sees 2 recipient(s) | queue 0 -> 1 | JOBS QUEUED: 1
SWEPT    (status=0):  dispatcher sees 0 recipient(s) | queue 1 -> 1 | JOBS QUEUED: 0
RESTORED (status=1):  dispatcher sees 2 recipient(s) | queue 1 -> 2 | JOBS QUEUED: 1
```

ARMED and RESTORED are the point: "0 jobs queued" is also true on a box where the mailer
is broken for unrelated reasons, so the absence assertion is vacuous without proof the
machinery is live. RESTORED additionally exercises the rollback direction. dev2 was left
exactly as found.

The test flushed the object cache between phases, deliberately and from the outset, so
it does **not** measure the un-flushed behaviour. That is why any repeat sweep must run
`wp cache flush` afterwards as `looth-dev`: BB caches subscription lookups and a raw
`UPDATE` fires none of its invalidation hooks. Skipping it makes a sweep look like it did
nothing.

## 5. ⚠️ FINDING — the follow roundup double-sent to the one member it is on for

This is the part Ian needs before widening, and it is the opposite of the reassuring
result in `IAN-RULINGS-2026-08-03.md` §4.

**Three replies were mailed to Ian twice — once instantly by BuddyBoss, once in our
daily roundup.** Proven from the delivery record:

| reply | posted (UTC) | BB instant mail | in his 08-07 roundup |
|---|---|---|---|
| 72583 | 2026-08-06 18:56:09 | `fsmpt` 10796 | yes |
| 72588 | 2026-08-07 15:47:14 | `fsmpt` 10810 | yes |
| 72589 | 2026-08-07 17:42:10 | `fsmpt` 10813 | yes |

The roundup side is not inferred: the rulings doc records that flush as 4 replies by
authors 627/160/665, and the only 4 candidates are {72565, 72583, 72588, 72589} — authors
160, 665, 160, 627. The watermark then advanced to `2026-08-07 17:42:10`, which is
reply 72589's exact `post_date_gmt`.

That is precisely the outcome `lg_fd_suppress_instant()` exists to prevent — its own
comment calls it *"the same lie in a new costume"*.

### What is proven, and what is not

**Proven:**
- Ian's cadence is `daily`, so suppression should have fired for all three.
- He holds **no** BuddyBoss notification-preference usermeta at all, so BB's own
  per-user opt-out (`bb_is_notification_enabled`) is not the explanation.
- The **deployed** code suppresses correctly. Probed on dev2 against the real DB:
  `ENABLED true / allowed true / cadence weekly / filter hooked at 10 / FILTER RESULT
  false`. So this is not a plain logic bug in the filter.
- The behaviour changed at a deploy boundary. Live pulled `0e80c5b` at **2026-08-05
  19:50:12 UTC**. The three replies Ian was correctly *not* instant-mailed (72539,
  72545, 72565) are all before it; the three he was double-mailed are all after it.
  Six replies, three each side, boundary exact.

**Not proven, and deliberately not guessed:** the mechanism. Seven candidates were
tested and **all seven are refuted**. Recorded individually so the next person does not
re-run them:

| # | hypothesis | verdict | evidence |
|---|---|---|---|
| 1 | plain logic bug in `lg_fd_suppress_instant` | **refuted** | dev2 probe against the real DB: `ENABLED true / allowed true / cadence weekly / hooked at 10 / FILTER RESULT false` |
| 2 | the filter does not fire in BB's **background updater** (the comment's own caveat) | **refuted** | reproduced the real chain on dev2 — `bbp_notify_topic_subscribers()` on a live reply, 2 subscribers → `BACKGROUND`, then `handle()` invoked directly. Witness at p99: `recipient=4 send_mail=true` → `wp_mail` fired; `recipient=1 send_mail=false` → no mail. **Suppression works there, and the non-allowlisted control still got mailed.** |
| 3 | per-user BB notification preference | **refuted** | Ian holds no `notification_*` usermeta at all; `bb_is_notification_enabled` falls through to enabled |
| 4 | `f0943d6` (the commit at the boundary) changed suppression | **refuted** | the diff is behaviour-identical for a member already holding a cadence and a watermark |
| 5 | tracked config unreadable by the FPM user → fail-closed `enabled=false` | **refuted** | `namei -l` on live: `/home/ubuntu` is `drwxr-x--x`, which grants traversal, and every component below is world-readable. Confirmed independently by #7. |
| 6 | a second mu-plugin re-enabling the mail | **refuted** | only `lg-discussion-unsub.php` mentions the filter on live, and only in a comment; it hooks `bp_get_email` / `bp_email_set_tokens` / `template_redirect` |
| 7 | live is running a different build than the one read here | **refuted** | `md5sum` of live's deployed `lg-follow-digest.php` == local `0e80c5b` == local `HEAD`: `16b88fab23c1a552fc91ba3ca60205b6` |

Live's `debug.log` is current (380 lines across 08-06/08-07) and other mu-plugins log to
it, yet it contains **zero** `[lg-fd]` lines across 04-Jul → 08-Aug. `lg_fd_config()`
logs loudly on an unreadable config, so that silence is what independently kills #5.

What is left is what read-only access cannot reach: live's own execution of that filter.
The next step is instrumentation, not more inference — a temporary `error_log()` in
`lg_fd_suppress_instant` recording `(recipient, cadence, allowed, returned)` would name
the cause on the first reply that lands. That is a live deploy and therefore Ian's.

**Consequence for widening:** at allowlist = Ian, this is one person getting three
duplicate emails. At `all-members` it is the same defect across 381. **Widening should
wait until this is understood**, and that is a stronger reason to hold than the
instant-vs-weekly question in ruling 4.

---

## 6. DELIVERABLE 3 — the 84% question, answered: there is no leak

The charter's framing was *42 replies / 31 owed / 26 sent — name the missing 5.* The
missing 5 do not exist. The 31 was an over-count, and re-measuring gives 25 owed against
26 sent — a gap in the other direction.

The over-count comes from one thing: **`wp_bb_notifications_subscriptions` is a mutable
current snapshot with no history.** Joining today's subscriptions against a week of past
sends attributes present-day subscribers to replies that predate their subscription.

```
replies, 7d                                                42
owed, naive (subscriber x reply, author excluded)          31
owed, requiring the subscription to PREDATE the reply      25   <- the honest number
reply-notification emails actually sent, same window       26   (all status 'sent')
```

Matching pair-by-pair on recipient address and timestamp: **22 matched, 3 owed-not-sent,
4 sent-not-owed.** All seven are accounted for:

- **3 owed-not-sent** — all Ian, replies 72539 / 72545 / 72565, all before the 08-05
  deploy. **This is our own suppression working as designed**, and his 08-04 and 08-07
  daily roundups carry them. Not a defect. (The same three are the "before" half of §5.)
- **3 sent-not-owed** — all `fradenburgh@gmail.com` (user 160), all on topic 72472, all
  on 08-02/08-03. He holds no subscription to 72472 **now**, but he was mailed then and
  later posted in the thread himself.

  ⚠️ **I first wrote this up as "he unsubscribed". That was wrong, and the truth is a
  live defect** — see §8. He was mailed as a subscriber of 72472 through 08-03 18:52
  local, replied himself at 08-04 02:17 UTC, and the reply is what removed his
  subscription. Not snapshot drift and not a member decision: our reply path strips the
  subscription of anyone who replies to a discussion they were following. The
  reconciliation arithmetic is unchanged; the explanation for these three is not.
- **1 sent-not-owed** — **a genuine duplicate send.** `fsmpt` 10818 and 10819, to
  `wgbluetone1@gmail.com`, 50 seconds apart, **byte-identical** (same length 11,854, same
  MD5 `4391d18f…`), for a single reply (72592, the only reply in that window). BB sent
  the same notification twice.

So, against the four candidate causes in the charter:

- **per-user notification prefs** — ruled out for the affected member (no meta rows).
- **BB dedupe** — no evidence of it; the opposite was observed (a duplicate).
- **moderation** — not implicated in any of the seven.
- **a real leak** — **no.** Every recipient of every send was, or provably had been, a
  subscriber. Nobody was mailed who should not have been, and nobody was silently
  dropped.

**BuddyBoss's native mail is a trustworthy baseline for the roundup to match**, with one caveat now on the record: BB's
instant path can duplicate (once in 26 sends, ~4%), most plausibly a background-updater
re-dispatch. The roundup's watermark design cannot do this — the watermark only advances on a send
— which is a point in favour of the consolidation Ian is asking for.

---

## 7. Sequence, if Ian approves

0. ~~Sweep the group subscriptions~~ — **DONE**, ruling 5. 9,297 rows disarmed.
1. **Decide what to do about the 3,735 kept rows** (§4): sweep them too, or turn the
   discussion leg on for those groups on purpose. Doing neither leaves 853 people
   reachable by the one notification nobody evaluated. Ian's call, and it is the only
   item here that is his rather than a lane's.
2. **Understand the §5 double-send** before widening the follow roundup's allowlist. Blocks
   ruling 4, and blocks it harder than the instant-vs-weekly question does.
3. **Grow the follow roundup to cover `forum`** subscriptions — the 38 explicit opt-ins
   (§2.2).
4. *Then* BuddyBoss's native mail has nothing left to send, and switching it off is a
   formality rather than a cutover.

Steps 1, 2 and 3 are mutually independent. 3 is the only one that is a build, and it is
small; 1 is a decision plus one statement; 2 needs a live deploy to instrument.

---

## Facts worth not re-deriving

- `wp_fsmpt_email_logs` reaches back only to **2026-07-25**. It is a delivery record, not
  a history — a 60-day query silently answers about 14 days.
- Live's MySQL session runs **UTC** (`NOW()` = `UTC_TIMESTAMP()`), but `fsmpt.created_at`
  is written **site-local** (UTC−4). Comparing the two without the offset skews 4 hours.
- `bb_subscriptions_validate_before_save` (`class-bb-subscriptions.php:310`) is the veto
  seam for subscription *creation* — return false and `save()` returns before the INSERT.
  `BP_Core_Notification_Abstract::$no_validate === true` distinguishes an auto-created
  group subscription from a member clicking Subscribe (set only at
  `class-bp-groups-member.php:1578` and `:1638`). Unused by us — recorded because it was
  the intended mechanism for the bridge-breaker, and finding it is most of that work.
- The membership row is inserted **before** `bb_create_group_subscription()` is called
  (`class-bp-groups-member.php:306` vs `:321`), so vetoing the subscription can never
  affect membership.


---

## 8. ⚠️ FINDING — replying through our own box UNSUBSCRIBES you

Ruling 6 flags this as a hazard for the implementer and says *"Our endpoint evidently
never executes that block (repliers kept their subs all summer)"*. **That parenthetical
is wrong. The block executes, and it has been firing all along.**

Proven in-process on dev2 against the deployed code, using the exact call
`bb-mirror/api/v0/reply.php:485-491` makes:

```
BEFORE  subscribed=true
reply created id=72429
AFTER   subscribed=false
>>> HAZARD CONFIRMED: replying through our path UNSUBSCRIBED an existing subscriber.
```

**The chain, all in the deployed tree.** `reply.php` posts in-process to
`/buddyboss/v1/reply`; `create_item()` fires `do_action('bbp_new_reply')`; and
`bbp_update_reply` is hooked to that action at priority 10
(`bp-forums/core/actions.php:177`). `bbp_update_reply:996-1008` then runs:

```php
$subscribed = bbp_is_user_subscribed( $author_id, $topic_id );
$subscheck  = ( ! empty($_POST['bbp_topic_subscription']) && 'bbp_subscribe' === $_POST['bbp_topic_subscription'] );
if ( true === $subscribed && false === $subscheck ) { bbp_remove_user_subscription( $author_id, $topic_id ); }
```

We send no `subscribe` param, so `class-bp-rest-reply-endpoint.php:2748` never sets
`$_POST['bbp_topic_subscription']`, so `$subscheck` is false, so an existing subscriber
is removed. **Editing a discussion does the same thing** — confirmed separately through
the composer's topic route (`/buddyboss/v1/topics/<id>`), which carries the identical
block at `class-bp-rest-topics-endpoint.php:1622`.

⚠️ The first attempt at that second test errored on a missing `parent` param and
reported "hazard did NOT fire". An errored request proves nothing; it was re-run with
the param and *then* confirmed. Recorded because the false negative was convincing.

### Why this matters more than the missing checkbox

Ruling 6's framing is that posting stopped *subscribing* people. It is worse: posting
*unsubscribes* them. Participation is what stops you hearing about the thread — the
exact inverse of the loop the ruling restores. It also erodes the existing 381, silently,
every time one of them takes part.

**Live corroboration**, which I already had and had misread (§6): Neil Fradenburgh
received the subscriber notifications for replies 72478/72511/72539 on topic 72472 —
mail that goes *only* to `type='topic'` subscribers of that topic, so he provably held
the row — then replied at 08-04 02:17 UTC and holds no row now.

Aggregate, **suggestive but not conclusive** (there is no history table, so "no row now"
is also the expected state for someone who never subscribed): repliers still holding a
subscription to the topic they replied in ran **5/61 (8%)** since 07-25, against
**161/523 (31%)** for Jan–May.

### The design constraint it imposes on deliverable 4

Ian has ruled ✉ **unticked** by default. The naive implementation of "unticked" is
`subscribe: false` — and that is precisely what fires the removal branch. So:

> **ticked ⇒ send `subscribe: true`. Unticked ⇒ send no `subscribe` field at all.**

Unticking a follow box on one reply is not the same act as unfollowing a discussion; the
🔔/✉ controls in the follow modal remain the deliberate way out. This is the assertion
the hazard gate exists to hold, and it must be broken red-first before it is trusted.

This is a live member-facing defect independent of deliverable 4's feature work, so the
one-line repair could ship ahead of the checkbox if Ian wants it sooner.
