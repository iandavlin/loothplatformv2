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

**STATE, 2026-08-09.** Three things have shipped since this doc was started, so read it
as a live record rather than a proposal: the group-sub sweep ran (§4), the
unsubscribe-on-post repair is deployed and ON on dev2 (§8), and ruling 6's controls are
deployed but inert (§10). §11 lists what is left and who owns each piece.

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

### ⚠️ The wgbluetone anomaly — the baseline is one member

Closing the last open thread from the reconciliation. `wgbluetone1@gmail.com` (user 779)
kept appearing in every list, and the reason is not a defect — it is scale:

```
topic subscriptions held by 779        340  of 1,515 platform-wide   = 22.4%
reply notifications sent to 779         18  of 33 in the logged window = 54.5%
next-highest subscriber                 49  subscriptions
replies 779 has EVER posted              4  (last: 2026-05-21)
```

**One member holds 22% of every topic subscription on the platform and receives more
than half of all reply email.** That is the single most important thing to know before
quoting any per-member average from §6: "26 reply emails in 7 days" is really *18 to one
person and 8 to everyone else*. Blast-radius and volume reasoning for the roundup must
not average over that — widening the allowlist changes almost nothing for 380 members
and changes one member's inbox a great deal.

They did not get there by posting: 4 replies, ever. They subscribe to new discussions
deliberately, and at remarkable rate.

### And that makes them an independent witness to ruling 6's June break

Ruling 6 dated the break from the *posting* pathway — 194 June replies producing 4 new
subscriptions. 779 never used that pathway. Their subscribing broke at the same moment
anyway, and discussion volume rules out the obvious alternative explanation:

| month | discussions created | 779 subscribed to | coverage |
|---|---:|---:|---:|
| 2026-01 | 30 | 18 | 60% |
| 2026-02 | 45 | 31 | 69% |
| 2026-03 | 57 | 31 | 54% |
| 2026-04 | 25 | 15 | 60% |
| 2026-05 | 53 | 36 | 68% |
| **2026-06** | **40** | **1** | **2.5%** |
| **2026-07** | **38** | **0** | **0%** |
| 2026-08 | 13 | 5 | 38% |

Discussion creation did **not** fall in June or July (40 and 38, both normal). Their
coverage fell off a cliff and then partially recovered in August.

So the June composer cutover took away **two** subscribe routes, not one: the implicit
post→follow checkbox that ruling 6 restores, *and* an explicit subscribe affordance that
at least one member was using on ~60% of all new discussions. Ruling 6 addresses the
first. **Nothing in this lane addresses the second**, and it is worth Ian knowing that
the ✉ control on a discussion — not just the one on the composer — is load-bearing for
the members who use it most.

The August partial recovery is consistent with the thread-follow ✉ control reaching them,
but the logged window is too short to prove which affordance they used, and I am not
going to assert a mechanism I cannot see. What is proven is the shape: a steady ~60%
habit, a two-month gap that discussion volume does not explain, and a partial return.

**BuddyBoss's native mail is a trustworthy baseline for the roundup to match**, with one caveat now on the record: BB's
instant path can duplicate (once in 26 sends, ~4%), most plausibly a background-updater
re-dispatch. The roundup's watermark design cannot do this — the watermark only advances on a send
— which is a point in favour of the consolidation Ian is asking for.

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

### THE FIX — built, gated, and it needs one deploy step a pull does not do

`platform/mu-plugins/lg-preserve-forum-subscription.php`. It reads the member's
current subscription state before BuddyBoss's handler runs and passes `subscribe` to
match, which makes both of BB's branches no-ops. It **preserves**; it never subscribes
anyone — restoring the ticked-by-default checkbox is ruling 6 and a separate surface,
and an explicit `subscribe` param always wins so ruling 6 composes cleanly on top.

Proven on dev2 through the real routes, `tools/gates/subscription-preserved-gate.py`:

```
negative control (repair absent) : reply UNSUBSCRIBES, topic edit UNSUBSCRIBES   ← the defect
flag ON                          : reply PRESERVES,    topic edit PRESERVES      ← the repair
flag OFF                         : identical to absent on both routes            ← the no-op
```

**The flag defaults ON, and that is a deliberate deviation** from the house
flag-OFF rule. That rule protects against unverifiable *features*; this is a repair for
ongoing data destruction, and OFF-by-default would ship the destruction. Precedent:
edit-post-parity (`b99570b`) landed as a direct P0 repair. The gate reads the constant
rather than assuming it, so flipping it needs no gate edit.

### ✅ SHIPPED — and the deploy coupling was honoured

Merged to main and pulled into the serving checkout, with **both** mu-plugin symlinks
created (verified non-dangling, 2026-08-09). Confirmed from the box rather than from
intent: gate 17 now reports `exercised the deployed copy of the repair`, and the flag
reads ON. **Participation no longer unsubscribes anyone on dev2.**

⚠️ **LIVE STILL NEEDS THE SYMLINK.** The mu-plugin symlink *set* is not in the repo —
`wp-content/mu-plugins/` links each file individually, so a pull alone leaves the repair
INERT with nothing to notice. On live, in the same window as the pull:

```bash
ln -s /home/ubuntu/loothplatformv2-clean/platform/mu-plugins/lg-preserve-forum-subscription.php \
      /var/www/dev/wp-content/mu-plugins/lg-preserve-forum-subscription.php
ln -s /home/ubuntu/loothplatformv2-clean/platform/mu-plugins/lg-post-follow-controls.php \
      /var/www/dev/wp-content/mu-plugins/lg-post-follow-controls.php
```

The second is ruling 6's feature and arrives **inert** (`platform/config/post-follow.php`
is `enabled => false`), so linking it early is safe and saves a second deploy window.

### How many of the 381 have been eroded? **The honest answer is: unknowable.**

Asked, and worth stating plainly rather than answering with a number that looks solid.
There is no subscription history table, and `wp_fsmpt_email_logs` only reaches back to
2026-07-25 — so the only members who can be *proven* to have held a subscription and
lost it are those who received a subscriber-only notification inside that 14-day window
and then replied.

**That query returns exactly one: Neil Fradenburgh, topic 72472.** Not because one
member was affected, but because the evidence window is 14 days wide against a defect
that has been running since June.

The suggestive aggregate, flagged as such: repliers still holding a subscription to the
topic they replied in ran **5/61 (8%)** since 07-25 against **161/523 (31%)** for
Jan–May. It is consistent with erosion and it is *not* proof — "no row now" is also the
expected state for someone who never subscribed. Anyone quoting a number for this
should quote 1 (proven) and the 8%/31% shape, never a figure derived from the 381.

---

## 9. RULING 7 — the Weekly Recap, scoped to bell-only types

Ian, 2026-08-08: the Weekly Recap survives, carrying **only notification types that have
no email channel of their own**. That scoping dissolves the roundup-versus-recap
overlap by construction rather than by a dedup rule — a type is in exactly one channel.

*(Nothing was removed from this doc for it: the roundup/recap dedup problem was never in
scope here. Recorded so "dropped from scope" is not read as a deletion that happened.)*

### The inventory, measured — not recalled

Every type the bell actually holds on live, against whether an email exists for it.
Counts are the logged window, 2026-07-25 → 08-08, from `profile_app.notifications` and
`wp_fsmpt_email_logs`:

| type | notifs | emails | email channel | recap? |
|---|---:|---:|---|---|
| `connection_request` | 258 | **0** | `friends-request` template EXISTS but never fires | ✅ **bell-only** |
| `reaction.on_post` | 71 | **0** | none — no template exists at all | ✅ **bell-only** |
| `connection_accept` | 44 | **0** | `friends-request-accepted` exists, never fires | ✅ **bell-only** |
| `forum.reply_to_reply` | 29 | — | `bbp-new-forum-reply` → the follow roundup | ❌ has email |
| `forum.reply_to_topic` | 20 | — | `bbp-new-forum-reply` → the follow roundup | ❌ has email |
| `forum.mention` | 7 | **2** | `new-mention` — and it demonstrably fires | ❌ has email |
| `forum.followed_topic` | 2 | — | the follow roundup, by definition | ❌ has email |

Those seven are the complete set: `lg-shared/notify-bridge.php` is the only pusher, and
its five forum/reaction types plus profile-app's two connection types are everything the
store holds.

**So the recap's remit is three types: connection requests, connection accepts, and
reactions.** Between them they are the *majority* of all bell traffic — 373 of 431 rows
in the window — so a bell-only recap is not a rump. It is most of what members actually
receive, and none of it reaches them by email today.

### ⚠️ Two of the three are emailless by WIRING, not by design

This distinction decides whether the list can be hardcoded, and it cannot.

- **`reaction.on_post` is structurally emailless** — BuddyBoss has no reaction email
  template. Nothing could send one without new work.
- **The connection types are emailless only because our flow bypasses BuddyBoss.**
  `friends-request` and `friends-request-accepted` are live, publishable templates with
  real subjects. Connections live in profile-app (`src/Connections.php`) and never enter
  BB's friends component, so the templates sit unused — 258 requests, zero emails.

The consequence: **a future lane wiring connection emails would silently take those two
types out of the recap's remit**, and a hardcoded list would keep putting them in — a
member getting the same connection request by email and again in the recap, which is the
exact double-send this project exists to end. The recap must therefore derive
"has an email channel" from a live check, and any change to it should turn a gate red.

### The consent guard — bell-only is a property of the TYPE, never of the member

Recorded because the mistake is easy and the cost is high. "No email channel" must mean
*this type has no email route at all*. It must never mean *this member declined the ✉*.

A member who set their follow roundup to off, or who unticked ✉ on a discussion, has
made a decision about a type that **does** have an email channel. Sweeping their reply
notifications into the recap because "they aren't getting an email for it" would route
around an opt-out and mail them the very thing they declined — dressed as a different
product. An opt-out is not recap fodder.

Concretely, the recap's filter is on `notifications.type` and nothing else. It must not
consult `lg_disc_email_cadence`, the `type='topic'` subscription rows, or any other
per-member email preference when deciding what is eligible.

## 10. RULING 6 — the post→follow controls: BUILT, shipped inert

Ian, 2026-08-08 and re-amended the same day: both surfaces carry both controls,
**🔔 Notifications ticked, ✉ Emails present but unticked.** Merged and deployed, with
`platform/config/post-follow.php` at `enabled => false`, so nothing about a member's
experience has changed yet.

| piece | where | state |
|---|---|---|
| the 🔔 write | `platform/mu-plugins/lg-post-follow-controls.php` | shipped, flag OFF |
| the ✉ write | **none needed** — BuddyBoss's own `subscribe` param | — |
| the controls | `bb-mirror/web/_chrome.php` (both forms) + `forums.css` | shipped, build-time gated |
| the params | `lgPostFollowParams()` in `forums.js` | shipped |
| the flag | `platform/config/post-follow.php`, read by BOTH runtimes | `false` |
| the proof | gate 18, 10 assertions | green |

**The ✉ half needed no server code at all**, which was worth establishing before writing
any: BuddyBoss's REST endpoints already accept `subscribe`, and passing it writes exactly
the row the follow roundup reads. The P0 repair (§8) already stands down when an explicit
`subscribe` arrives, so the two compose without either knowing about the other.

**One flag, two runtimes.** The write is a WordPress mu-plugin; the UI is bb-mirror's
forums app. A constant in each would let them disagree — a rendered control that silently
does nothing, or a param nobody sends. `platform/config/post-follow.php` is read by both,
which makes those states unreachable, and gate 18 asserts the *wiring* as well as the
value so removing the shared read is itself red.

### What is left, and who owns it

- **Ian**: look at the mock (`/footer-mockups/post-follow-checkbox/`) and say whether the
  strip is right. It is drawn on both surfaces, both themes, at 320px. Then flipping
  `enabled` to `true` is a one-line diff and needs no gate edit.
- **Not verified on a serve.** bb-mirror serves from the serving checkout, so the branch
  UI was never reachable on dev2 without a lane preview. Now that it is merged the
  markup is deployed but gated off; a preview with `LG_POST_FOLLOW=1` would let Ian click
  the real control rather than the mock. Worth doing before the flip, not after.
- ⚠️ **The mock shows checkboxes because that is what shipped.** It originally drew the
  follow modal's switches. The mock is what Ian approves, so it was changed to match the
  build rather than the other way round — if he prefers the switch look, that is CSS only.

---

## 11. Sequence — what is done, and what is waiting on whom

**Done and deployed (dev2):**

0. ~~Sweep the group subscriptions~~ — ruling 5, 9,297 rows disarmed.
1. ~~Repair the unsubscribe-on-post defect~~ — §8, live on dev2 with the flag ON.
2. ~~Build ruling 6's controls~~ — §10, shipped inert behind `enabled => false`.
3. ~~Inventory the bell-only types for ruling 7~~ — §9.

**Waiting on Ian, in the order they matter:**

1. **The §5 double-send** blocks widening the roundup's allowlist. Seven mechanisms
   refuted; the next step is instrumentation on live, which is a deploy and therefore his.
2. **The 3,735 kept group subscriptions** (§4) — sweep the remainder, or turn the
   discussion leg on for those groups deliberately. Doing neither leaves 853 people
   reachable by the one notification nobody evaluated.
3. **Ruling 6's flip** — after he has looked at the control (§10).
4. **Live deploy of the P0 repair** — needs the pull *and* the symlink (§8).

**Still a lane's to build, none of it blocked:**

- **Grow the roundup to cover `forum` subscriptions** — the 38 explicit opt-ins (§2.2).
  This is the last thing standing between here and "BuddyBoss has nothing left to send".
- **A live check for ruling 7's type list** (§9) rather than a hardcoded three.

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
