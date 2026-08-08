# ONE MAILER — scope, and what has to be true before BuddyBoss can be retired

Ian, 2026-08-08: *"I don't want users to get emails from two different systems. I want
just one from ours. I also don't want them to get emails from our legacy group system
for forums."*

Everything below was measured on **live, read-only, 2026-08-08**. Code paths were read
in the deployed BuddyBoss 2.20.0 tree on dev2 (same version as live). Where a number
comes from an earlier session it is marked as such and not re-derived.

Extends `docs/IAN-RULINGS-2026-08-03.md` §4. It does not restart that project.

---

## 0. The headline, before the detail

Three things that were assumed going in turned out to be wrong, and all three make the
job **smaller**:

1. **BuddyBoss's group mailer is already dead in its main leg** — not by BB config, but
   by our own `lg-discussion-group-gate.php`. That is why 13,032 group subscriptions
   have produced no mail.
2. **The "84% delivery" gap is an artifact**, not a leak. Nobody is silently unmailed.
   The real reconciliation is 22 matched / 3 / 4, and every one of the 7 is explained.
3. **But we found a real double-send**, and it hit the one member the follow-digest is
   switched on for. That is a blocker for widening, and it is §5.

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

## 2. DELIVERABLE 2 — what C must absorb before B can be switched off

Our follow-digest covers `type='topic'` and nothing else (`lg_fd_items_for()` joins
`type='topic'` literally). Taking the three in turn:

### 2.1 `topic` — replies. **Already covered.** No work.

C replaces it exactly, per-recipient, and the allowlist is Ian-only today. Widening is
ruling 4 and is held pending §5 below and Ian's instant-vs-weekly call.

### 2.2 `forum` — *"New discussion: X"*. **THE REAL GAP. C must grow.**

38 members hold 46 forum subscriptions; 18 of them were mailed in the last two weeks.
This is a genuinely wanted signal — a forum subscription is an explicit act (there is no
"you posted here so you're subscribed" side effect for forums, unlike topics), so unlike
the 71% of topic subscribers who never chose email, **these 38 all chose it**.

**Recommendation: C absorbs it, and it is a small change.** The digest already renders
"N new replies in a discussion you follow". It needs a second item kind — "N new
discussions in a forum you follow" — sharing the same watermark, the same cadence, the
same suppression seam (`bb_send_forums_subscribed_discussion_email_notifications`,
identical shape to the one we already own). No new schedule, no new store.

**Do not let it die unreplaced.** These 38 opted in explicitly and get roughly 3
messages a week; silently dropping them is the "mail black hole" failure the digest was
built to avoid, just relocated.

### 2.3 `group` — **Recommendation: DIES UNREPLACED. Do not build a replacement.**

Ian said it in as many words: *"I also don't want them to get emails from our legacy
group system for forums."* Beyond that ruling, the evidence says nobody would miss it:

- **Nobody chose it.** All 13,032 rows are side effects of a group join, created by
  `BP_Groups_Member::save()` → `bb_create_group_subscription()`
  (`class-bp-groups-member.php:321`). Not one is an opt-in.
- **The subscription is per-GROUP, and the five main groups hold all 1,830 members.**
  So "subscribed" here means "will be told about every discussion on the site". That is
  the weekly digest's job, and the weekly digest already does it — to ~1,860 people,
  editorially curated. A second, uncurated copy of the same firehose is precisely the
  "two systems" Ian is objecting to.
- **Its discussion leg has been off for weeks already** and nobody has reported a loss.

So the end state is: **C covers topic + forum. Group mail is deleted, not migrated.**

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

What I am claiming is the shape of the risk: **one group activity update in Repair And
Restoration mails 1,830 people, and the only thing standing in the way is that nobody
has happened to post one since 30 April.**

---

## 4. DELIVERABLE 1, RE-SCOPED — the sweep is enough; do not build the bridge-breaker

Ian deactivated `bp-auto-group-join` on live himself (confirmed: absent from
`active_plugins`, 38 plugins remain). That kills the registration firehose — a new
member no longer gets 12 memberships and 12 subscriptions on day one.

**Recommendation: run the sweep. Do NOT build the join-veto mu-plugin.** Reasons, in
order of weight:

1. **The veto does nothing about the 13,032 rows that already exist**, and those rows
   are the entire risk in §3.2. It only prevents new ones.
2. **The refill is now small.** Weekly new group-subscription rows over the last ten
   weeks ran 24–180, but always in multiples of 12 across 2–15 users — the auto-join
   signature. With auto-join off, what remains is manual joins only.
3. **The sweep already closes leg B.** `bb_send_notifications_to_subscribers()` resolves
   recipients with `bb_get_subscription_users([... 'status' => true])`, so a `status=0`
   row is invisible to the sender. Setting status 0 disarms the blast directly.
4. It is one `UPDATE` Ian runs once, versus a new mu-plugin with a flag, a gate, a
   red-first proof and a deploy coupling — for a strictly smaller effect.

### The one thing the sweep does not do: it decays

Every future manual group join re-creates a `status=1` row. If Ian wants group mail dead
*permanently* rather than dead *today* — and his wording says he does — the durable
version is **not** the originally-charted join-veto. It is a ~15-line tracked mu-plugin
hooking `bb_send_subscribed_group_email_notifications` and returning `false`, which:

- kills group email at the send seam regardless of what the subscription rows say, so it
  cannot decay;
- covers **both** legs, including the ungated activity leg;
- leaves the bell alone (`bb_send_subscribed_group_notifications`, without `_email_`, is
  a separate filter) — same split Ian chose for replies;
- changes no routing. Filtering `bb_enable_group_subscriptions` to false would look
  tempting and is **wrong**: it flips the §1 if/else, so group-forum discussions would
  start mailing forum subscribers instead of nobody. That is more mail, not less.

I have not built it. It is a follow-up if Ian wants it, and it is genuinely small.

### THE SWEEP — for Ian to run on live

All live writes are Ian's. Counts verified 2026-08-08: **13,032 rows, 1,830 members,
and zero `type='group'` rows currently at status 0** — so the snapshot below is an exact
rollback set, not an approximation.

Membership is untouched. This statement never names `wp_bp_groups_members`.

```sql
-- 0. BEFORE — record these two numbers before doing anything.
SELECT COUNT(*) FROM wp_bb_notifications_subscriptions WHERE type='group' AND status=1;  -- expect 13032
SELECT COUNT(*) FROM wp_bb_notifications_subscriptions WHERE type='group' AND status=0;  -- expect 0

-- 1. SNAPSHOT the exact set being changed, so rollback cannot over-reach.
CREATE TABLE lg_group_sub_sweep_20260808 AS
  SELECT id FROM wp_bb_notifications_subscriptions WHERE type='group' AND status=1;
SELECT COUNT(*) FROM lg_group_sub_sweep_20260808;   -- MUST equal 13032. Stop if it does not.

-- 2. THE SWEEP.
UPDATE wp_bb_notifications_subscriptions s
  JOIN lg_group_sub_sweep_20260808 k ON k.id = s.id
   SET s.status = 0;

-- 3. AFTER — expect status 0 => 13032, and NO status 1 rows for type='group'.
SELECT status, COUNT(*) FROM wp_bb_notifications_subscriptions WHERE type='group' GROUP BY status;

-- 4. Untouched-control: these two must be identical to before.
SELECT type, status, COUNT(*) FROM wp_bb_notifications_subscriptions
 WHERE type IN ('topic','forum') GROUP BY type, status;   -- expect topic/1/1515, forum/1/46
```

**Then flush the object cache**, as `looth-dev` on live:

```bash
wp cache flush
```

BB invalidates its subscription cache on `bb_create_subscription`, which a raw `UPDATE`
does not fire. Skipping this makes the sweep look like it did nothing.

**Rollback** — restores exactly the rows the sweep changed and nothing else:

```sql
UPDATE wp_bb_notifications_subscriptions s
  JOIN lg_group_sub_sweep_20260808 k ON k.id = s.id
   SET s.status = 1;
-- then: wp cache flush
```

Nothing is deleted, so who-was-subscribed history survives in full. Keep
`lg_group_sub_sweep_20260808` until Ian is satisfied; it is 13,032 integers.

---

## 5. ⚠️ FINDING — the follow-digest double-sent to the one member it is on for

This is the part Ian needs before widening, and it is the opposite of the reassuring
result in `IAN-RULINGS-2026-08-03.md` §4.

**Three replies were mailed to Ian twice — once instantly by BuddyBoss, once by our
digest.** Proven from the delivery record:

| reply | posted (UTC) | BB instant mail | in his 08-07 digest |
|---|---|---|---|
| 72583 | 2026-08-06 18:56:09 | `fsmpt` 10796 | yes |
| 72588 | 2026-08-07 15:47:14 | `fsmpt` 10810 | yes |
| 72589 | 2026-08-07 17:42:10 | `fsmpt` 10813 | yes |

The digest side is not inferred: the rulings doc records that flush as 4 replies by
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
  digests carry them. Not a defect. (The same three are the "before" half of §5.)
- **3 sent-not-owed** — all `fradenburgh@gmail.com` (user 160), all on topic 72472, all
  on 08-02/08-03. He holds no subscription to 72472 **now**, but he was mailed then and
  later posted in the thread himself. He unsubscribed. Snapshot drift, not a leak.
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

**B is a trustworthy baseline for C to match**, with one caveat now on the record: BB's
instant path can duplicate (once in 26 sends, ~4%), most plausibly a background-updater
re-dispatch. C's watermark design cannot do this — the watermark only advances on a send
— which is a point in favour of the consolidation Ian is asking for.

---

## 7. Sequence, if Ian approves

1. **Sweep** the 13,032 group subscriptions (§4). Immediate, reversible, closes the
   ungated activity leg. Ian runs it.
2. **Understand the §5 double-send** before widening the follow-digest allowlist. Blocks
   ruling 4.
3. **Grow C to cover `forum`** subscriptions — the 38 explicit opt-ins (§2.2).
4. *Then* B has nothing left to send, and switching it off is a formality rather than a
   cutover.

Steps 1 and 3 are independent of each other and of step 2.

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
