# Ian's rulings — 2026-08-03

Four decisions taken in one sitting, plus one defect they surfaced. Recorded here
because they existed only in a chat transcript, and a lane that cannot quote the
decision it is building against will re-litigate it or guess.

Quote the relevant line in the commit body when you act on one of these.

---

## 1. Weekly recap scope — **"What you missed" (unread only)**

Ian chose to KEEP the current unread-only behaviour over time-windowing it.

He chose this having been shown the argument against, so do not re-open it as
though it were an oversight. It is an editorial call about what the word "recap"
means, and it is his to make.

### ⚠️ The defect it leaves open — NOT ruled on, and still real

`webroot/bottom-nav.js:1125` fires `markAllNotifsRead` 700ms after the mobile
notification sheet renders, POSTing `{action:'read_all'}` — **every** row, not the
visible eight. Under unread-only that empties the recap, and under "empty means send
no email" it cancels the member's digest entirely.

So the member most engaged with the bell is the member most reliably unmailed: a
weekly recap inversely correlated with engagement. That is also why Ian's own recap
came up empty — not a bridge defect and not a renderer defect.

The protection already exists one arm over: `Recap.php:110-113` refuses to consult
`is_read` for connection requests for exactly this reason. It was written and never
extended to hub rows.

**Ian picked the framing, not the bug.** The offered option that paired unread-only
WITH fixing the timer was not the one taken, so the timer stands. Filed as backlog
item 4.1. Fixing it makes his chosen framing behave the way the label promises.

---

## 2. DM emoji picker — **Variant 1**

From the two mocks at `/footer-mockups/emoji-picker/`. Branch `emoji-picker` holds
the mock (`ae29c0e`); no implementation yet.

⚠️ Confirm which variant the page labels "1" before building — the ruling is the
label Ian saw, not an index into any array.

---

## 3. Front-end compose — **Option A, single screen**

The lane's own recommendation, over Option C (the 3-step wizard approved for
discussions on 7/30). Mock at `/footer-mockups/frontend-compose/`, scope in
`docs/FRONTEND-COMPOSE-SCOPE.md`, branch `admin-edit-any`.

Note the re-scope that precedes this: Ian, earlier — *"I can currently edit on the
front end. That is fine. I need to be able to COMPOSE on the front end with a easy
front end form."* Editing was never the problem.

---

## 4. Follow-digest allowlist — **widen to all members** (ordered; NOT yet done)

Ian ruled widen. Measuring before flipping found the flip would have **silenced
follow email for all 1824 accounts** — suppressed by one code path, invisible to the
other. Three defects fixed in `f0943d6`, live at `0e80c5b`.

**The widening itself has NOT been applied.** It remains a one-line diff:

```php
// platform/config/follow-digest.php
'allowlist' => 'all-members',      // currently '1:ian.davlin@gmail.com'
```

### ✅ VERIFIED END-TO-END ON LIVE, 2026-08-07 — the sender is not the risk

Ian said email "didn't seem to be working". It was. Proven on live, in order:

  - `wp_mail()` -> Amazon SES delivers. Test message arrived in his inbox.
  - Resolver finds him: `daily due: 1`, and only him.
  - Content query found 4 qualifying replies (authors 627/160/665, his own excluded).
  - `[lg-fd] daily flush: 1 sent`, and **Ian confirmed the email arrived**.
  - Watermark advanced to `2026-08-07 17:42:10` — the timestamp of the LAST INCLUDED
    reply, not `now`, so nothing falls through the gap between query and send.

Two things that looked like bugs and were not:
  - A manual `wp cron event run lg_fd_send` sends NOTHING outside the window.
    `lg_fd_tick()` only flushes when the local hour equals LG_FD_DAILY_HOUR (8, i.e.
    08:00 America/New_York). The Aug 4 send was 12:58 UTC = 08:58 local. To test off
    -schedule call `lg_fd_flush('daily', 0)` directly.
  - `sudo wp --allow-root` FAILS: Postgres is peer-authed and `root` has no role, so
    the link store is unreachable. The sender then REFUSED to send and left the
    watermark unadvanced — the guard working. Run as `looth-dev`, the same user
    lg-wp-cron.service uses.

### ✅ DELIVERY LOGGING WORKS — an earlier note here said it did not. It was WRONG.

Keeper searched for `%fsmtp%` and found nothing, and wrote up "logging is on but the
table does not exist" as a pre-widening blocker. **FluentSMTP's own constant is
`FLUENT_MAIL_DB_PREFIX = 'fsmpt_'`** (boot.php:8) — a transposition in the plugin
itself. The table is `wp_fsmpt_email_logs`, it holds 5530 rows, and it has been
logging all along.

So there IS a full delivery record, queryable read-only, and it is how you answer
"did that actually send?" without excavating syslog:

```sql
SELECT id, status, LEFT(subject,60), created_at
  FROM wp_fsmpt_email_logs WHERE `to` LIKE '%someone@example.com%'
 ORDER BY id DESC LIMIT 10;      -- note: `to` is a reserved word, backtick it
```
⚠️ `created_at` is SITE-LOCAL (America/New_York), not UTC. 14:57 local = 18:57 UTC.

It also settled a second false alarm. Keeper suspected BuddyBoss's per-reply
"instant" mail was silently failing for the 384 members on that path. It is not:
row 10813 `[The Looth Group] Karl Borum...` at 13:42:11 local is exactly reply 72589
(17:42:10 UTC). Instant mail sends, and it is logged.

**Net effect on widening: the observability objection is withdrawn.** Both paths are
logged and both are proven to deliver.

Remaining, and unchanged: the sender needs Postgres reachable from the sending
process. At one recipient a failure is one delayed email; at 384 the whole run
refuses — correctly, and it DOES log the refusal, but nothing pages anyone.

### ⚠️ WHO THE SUBSCRIBERS ACTUALLY ARE — and why this REVISES ruling 4's premise

Ian asked, 2026-08-07: *"what exactly are those 384 members actually signed up for?"*
and *"Are they individual discussion[s]?"* Measured on live:

```
type    members   rows      per-member topic subs
topic     381     1515      1 topic  185 (49%)   6-20   53 (14%)
group    1830    13032      2-5      138 (36%)   21+     5 ( 1%)
forum      38       46
```

Individual discussions, yes — `lg_fd_items_for()` joins `type='topic'`, so the 1830
group subscribers are NOT in scope. And **half of the 381 follow exactly one topic.**

How they got there:

```
subscribed to a topic they POSTED IN          1082  (71%)
subscribed to a topic they never posted in     433  (29%)
```

**71% never chose email.** They replied to a discussion and were subscribed as a side
effect of participating. So the typical subscriber is someone who asked a question in
one thread and is told when it is answered — which is exactly the case where INSTANT
is right and WEEKLY is harmful. Ask a question, get answered in twenty minutes, find
out next Tuesday.

### ❌ THE PREMISE OF RULING 4's 'weekly first' WAS MINE, AND IT WAS WRONG

On 8/3 keeper told Ian an `instant` default would "start a per-reply email to every
member who ever tapped the bell", and he reasonably chose weekly to avoid that blast.

**That blast cannot happen.** Those members ALREADY receive per-reply mail from
BuddyBoss — proven by `wp_fsmpt_email_logs` row 10813 (2026-08-07), which is the
instant notification for reply 72589. `lg_fd_suppress_instant()` returns `$send_mail`
untouched when the cadence is `instant`, so an instant default adds NOTHING; it
preserves exactly what already happens.

Which inverts the choice:
  - default `instant` ⇒ widening is a TRUE no-op. No member's mail changes. The
    cadence control appears and lets them opt DOWN. Zero blast radius by construction.
  - default `weekly`  ⇒ widening silently moves 381 members from "told when answered"
    to "told within a week", and 71% of them never asked for email either way.

The feature's value is letting members turn the volume DOWN themselves, not quieting
them by default. **This needs Ian's call before widening — it is not keeper's to
reverse, because he ruled weekly on 8/3 in good faith on bad information.**

### NO, WIDENING DOES NOT SEND A BACKLOG — asked by Ian, verified in code

A member who has never been enrolled has NO watermark, and:
  - `lg_fd_ensure_enrolled()` stamps the watermark `gmdate('Y-m-d H:i:s')` — NOW,
    never epoch — at the moment their first mail is suppressed.
  - `lg_fd_items_for()` returns EMPTY when the watermark is `''`:
    `if ( '' === $since ) { return $empty; }   // REFUSE rather than backfill. §4.3.`

So the first digest covers from enrolment FORWARD. Nobody receives a history dump of
everything they missed before widening. A 50-item-per-member cap bounds the tail, and
it sets a `capped` flag rather than truncating silently.

### Before flipping

Re-run the measurement that caught the black hole rather than
trusting that the fix held — under an all-members allowlist, a member with no
explicit cadence must come back BOTH suppressed AND due:

```bash
sudo -n env LG_FOLLOW_DIGEST_ALLOWLIST=all-members wp eval '...'   # see f0943d6 body
```

⚠️ `tools/gates/follow-digest-gate.py` asserts this file does NOT contain
`all-members`. That tripwire is deliberate. Flipping it will turn the gate red, and
the correct response is to convert the assertion into "all-members requires a
recorded decision" — citing this file — **not** to delete the check.

---

## 5. ONE MAILER — Ian, 2026-08-08 (supersedes the framing of ruling 4)

Verbatim: "I don't want users to get emails from two different systems. I want just
one from ours. I also don't want them to get emails from our legacy group system for
forums."

So the end state is: BuddyBoss's member-activity mail fully retired; our follow-digest
is the only activity mailer. Ruling 4's widening becomes step one of this, not the
whole project. The one-mailer lane (charter ~/lane-prompts/one-mailer.md) owns the
scope: break the join→subscribe bridge first, then scope what our digest must absorb
("New discussion" for forum subs, group notifications) before BB mail can die.

THE BRIDGE, proven on live 8/8: bp-auto-group-join (active) hooks user_register and
joins every new member to the 12 groups whose groupmeta aj_new_registrations =
'all_members'; BuddyBoss then auto-creates the type='group' email subscription on
join. Newest member (uid 2092, onboarded that morning) held 12 memberships + 12 email
subs on day one. A one-time unsub sweep therefore DECAYS — the bridge must break
first, then the 13,032 existing type='group' rows get swept (status=0, reversible,
Ian runs it).

⚠️ wp_bp_groups_members drives the forum LAYOUT and must survive untouched. Only the
notification-subscription side effect dies.

### ✅ EXECUTED 2026-08-08 — the group-sub sweep, verified

Ian deactivated bp-auto-group-join on live himself (kills the registration
firehose), then ran the sweep: the 5 forum-layout groups (31 Repair, 32 New Builds,
33 Tools, 34 Business, 35 Market Place) went 9,297 → 0 email subscriptions.
KEPT by his instruction: all regional Looths groups (esp. 41 DMV = 365, untouched),
Jannies, Partners, chat groups. Keeper verified independently over live-ro:
layout_subs_left=0, dmv=365, flipped=9297=backup, memberships 12,877 untouched so
the layout survives.

Before-state facts that matter later: no status=0 rows existed on 31–35 before the
sweep (nobody had manually muted), so a rollback cannot trample hand-set state.
Rollback = flip status back to 1 for exactly the ids in wp_lg_group_unsub_20260808.

⏳ OWED: drop table wp_lg_group_unsub_20260808 after a few quiet days (it IS the
rollback — do not drop early). Layout/posting were proven membership-independent
before the sweep: no serving code reads wp_bp_groups_members, publish_topics passes
for non-members, all forums visibility=public.

This also reframes the open cadence question: once BB's instant mail is retired
rather than preserved, the default cadence is a pure product choice, not a
compatibility one. Still Ian's call, still open.

---

## 6. Post→follow: RESTORE the ticked-by-default checkbox — Ian, 2026-08-08

THE FINDING THAT FORCED THE QUESTION. Posting stopped subscribing members in June:
194 June replies → 4 new subscriptions, 138 July replies → 0. Not a disabled
setting (_bbp_enable_subscriptions is still 1) — BB's "auto"-subscribe was only ever
a TICKED-BY-DEFAULT CHECKBOX on its native reply form (replies/functions.php:996
reads $_POST['bbp_topic_subscription']). Our composer replaced that form in June and
sends no such field, so the ask-a-question→get-told-when-answered loop has been
severed for every post made through our path since. This is also the real anatomy of
the "71% never chose email" cohort: they didn't tick a box, they didn't UN-tick it.

RULING: our composer and reply box get a "Follow this discussion" checkbox, ticked
by default — restoring pre-June behaviour, now feeding the ROUNDUP (daily default,
one email/day max, Manage Account control to silence). Chosen over author-only
auto-follow and over unticked-by-default.

⚠️ HAZARD FOR THE IMPLEMENTER, verified in BB source: on BB's own save path, an
already-subscribed member replying WITHOUT the field is UNSUBSCRIBED (the first
branch at :1001). Our endpoint evidently never executes that block (repliers kept
their subs all summer) — but any change that runs more of BB's save chain must gate
against it: "an existing subscriber replying with the box unticked stays subscribed
unless they untick it deliberately" needs to be asserted RED-FIRST.

AMENDED SAME DAY — Ian: "Can we add bell and email to the composer?" So the
composer and reply box carry BOTH follow controls, in the same 🔔/✉ vocabulary the
follow modal and Manage Account already use — one visual language everywhere:

  🔔 bell follow — TICKED BY DEFAULT (Ian, superseding the email-ticked default
     minutes after setting it: "give people the option and make bell the
     default"). Writes forums.topic_follow. Posting = you see answers in your
     bell; email is a deliberate extra tick.
  ✉  email follow — PRESENT, UNTICKED by default. Writes the type='topic'
     subscription row the roundup reads.

⚠️ THE CONSEQUENCE OF BELL-AS-DEFAULT, stated when ruled: the default channel for
"your question was answered" is now the bell — and bell delivery rides the
notification bridge, which is the KNOWN GAP (BuddyBoss reply events under-reach
profile_app.notifications). Until the bridge is fixed, a default-settings poster
may get neither email (unticked) nor a reliable bell ping. Bell-as-default
therefore PROMOTES THE BRIDGE FIX to the top of this project's order — it is no
longer a recap-side nicety, it is the delivery path of the composer's default.

⚠️ Bell DELIVERY is only as good as the notification bridge, which is a known gap
(BuddyBoss reply events under-reach profile_app.notifications — recap-notif-bridge
lane, blocked on a ruling). The composer control writes the follow row correctly
either way; do not let its gate claim bell notifications ARRIVE — that is the
bridge's contract, not the composer's.

Owner: one-mailer lane. Member-facing ⇒ flag OFF-default, both surfaces (new-topic
composer + reply box), mock first at phone widths in both themes.

---

## Standing note on gate colour

`tools/gates/run-all.sh` currently ends RED on a **pre-existing** failure:
`FAIL finder/anon (2 violations, imgs 289KB)` — backlog 13.5, an image-weight
violation dating to Nov 2024. All 14 other gates are green.

Do not read that red as "this branch broke something", and do not read it as
permission to push past a red either. Check WHICH gate before either conclusion —
the suite prints per-page verdicts, and `hub/anon` and `hub/member` both pass.
