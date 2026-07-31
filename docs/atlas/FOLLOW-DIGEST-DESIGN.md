# FOLLOW-DIGEST — the batcher that makes the cadence control honest

> **Status: DESIGN NOTE, seam offered to thread-follow + weekly-recap. No feature code yet.**
> Lane: `follow-digest` (dev2), branch `follow-digest` off `main@b3f82f8`. Written 2026-07-31.
>
> **This document exists because a control was correctly withheld.** thread-follow built the
> frequency segmented control (`bb-mirror/web/forums.js:4374`) and shipped it dark behind
> `FREQ_ENABLED = false`, because *"storing a cadence does nothing without a batcher, and there
> is no sender."* THREAD-FOLLOW-SPEC §15.4's rule — **do not ship a cadence control that
> silently does nothing** — is what this lane discharges. The control becomes showable when the
> sending becomes real, and not one commit before.
>
> Cross-refs: `THREAD-FOLLOW-SPEC.md` §15.3–15.5 (the questions this answers), `NOTIF-EMAIL-STATE.md`
> (the whole-system page), `WEEKLY-DIGEST-RECAP.md` + `RECAP-SUPPRESSION-PROPOSAL.md` (weekly-recap's
> territory, deliberately untouched).

---

## 0. Ian's rulings this lane is built to (2026-07-31)

| # | Ruling | Consequence |
|---|---|---|
| 1 | **"Build the batcher so Daily/Weekly really work."** Chosen over shipping Instant-only, and over showing a control that lies. | The deliverable is a **real sender**, not a setting. |
| 2 | **Cadence is ONE ACCOUNT-LEVEL setting.** A member picks a cadence once; it applies to every discussion they follow. | Kills the per-(member, thread) ledger §15.5 feared. **One row per member.** Per-thread cadence was offered and **declined** — do not build it. |
| 3 | **Show the frequency control only when the batcher lands.** | `FREQ_ENABLED` flips on **my** signal, relayed through keeper to Ian. I do not flip it myself. |
| 4 | The list is **INSTANT / DAILY / WEEKLY**. | Hourly out on weekly-recap's measurement; "Off" out because the ✉ toggle owns on/off. |

Ruling 2 is **not a reversal** — it is THREAD-FOLLOW-SPEC §15.5(b)'s own recommendation, now ruled.
The spec argued per-discussion cadence *defeats the feature it implements* (following six threads on
Daily is six daily emails). Ian agreed. Nothing is being overturned; an open question is closed.

### 0.1 One superseded ruling, named rather than left to trip someone

`NOTIF-EMAIL-STATE.md` §1 records Ian, 2026-07-25: *"**No daily or per-event notification email,
ever.** Real time is the bell only… BuddyBoss subscription emails stay permanently off."*

That is **stale**, and it is the first section of the document a new keeper is told to read first,
so it is corrected here rather than left to contradict a build. The trail:

| date | ruling | effect |
|---|---|---|
| 07-25 | bell only; email channel is the weekly digest | ✉ per-discussion email does not exist |
| 07-27 | **two toggles per discussion, one of them ✉ Emails for new replies** | per-event email is back, opt-in |
| 07-29 | ruling 10bis — **the envelope emails ship through our own send mechanism** | we replace the BB path, not wrap it |
| 07-31 | **cadence Instant/Daily/Weekly, account-level, with a real batcher** | *daily* email exists, opt-in |

Each step is a deliberate later decision by Ian, not drift. **§1 of NOTIF-EMAIL-STATE should be
amended when this lands** — flagged for keeper; I have not edited another lane's summary page.

---

## 1. THE MEASUREMENT FIRST — because it changes what the email should look like

> Rule from the charter: **prove the recipient set before the content.** Sending the right email
> to the wrong people is worse than sending nothing. All figures LIVE, read-only via `live-ro`,
> DB `looth_import`, 2026-07-31.

### 1.1 The mean is ~zero. The tail is 15 emails in a day.

Running **the actual digest query** (replies in topics a member subscribes to, excluding their own)
over the trailing 30 days against all 1,519 live topic subscriptions:

| | |
|---|---|
| distinct members with **any** item in 30 days | **8** |
| non-empty **member-days** in the whole month, whole membership | **11** |
| heaviest single member | **2 items / 2 threads / 30 days** |
| platform-wide replies, 30d | 129 across 46 topics |

On that window alone the honest conclusion would be *"Daily batches nothing"* — the same argument
that killed Hourly. **That conclusion is wrong, and the 365-day window is why:**

| | |
|---|---|
| busiest single **topic-day** | **15 replies** (topic 71525, 2026-06-08) |
| next four | 13, 10, 10, 9 |
| busiest single **member-day**, across all followed topics | **20** (user 779, who holds 335 subs and distorts every average) |

**So the feature earns its keep in the tail, not the mean.** A member following one hot thread gets
**15 emails in a day** on Instant. That is precisely the person who wants Daily. The 30-day window
was quiet; a month is not a long enough lens for a community this size, and I am recording my own
first read as wrong so nobody re-derives it from the same query.

### 1.2 Two consequences for the design, both load-bearing

1. **The one-item digest is the NORMAL case, not the degenerate one.** An email whose body reads
   *"3 new replies across 2 discussions"* is absurd when it is almost always one reply in one
   thread. The template must read correctly at **n=1** first and scale up, not the reverse. This is
   a design constraint, not a nicety — it is what most recipients will actually receive.
2. **Day-one volume is ZERO, not 1,519.** Ruling 10bis is a **cutover with no grandfathering**: at
   cutover legacy discussion emailing stops and members opt in fresh. So the ✉ population resets to
   empty and grows by clicks. Every number above is an **upper bound from legacy data**, not a
   forecast. The batcher therefore has to be **correct by construction rather than tuned to
   volume** — there is no volume to tune against, and the blast radius on day one is nil.

### 1.3 The pre-existing double-send, sized — I must not make it worse

**734 of 1,519** topic subscriptions are held by the member who **authored that topic**. That is the
population `NOTIF-EMAIL-STATE.md` §4's 07-30 correction is about: BB's reply mailer removes only the
*replier*, so a member holding ✉ on their own topic gets the reply as a per-event email **and** as a
named `forum.reply_to_topic` row in weekly-recap's digest.

**This lane does not fix that and does not worsen it** — batching changes the *timing* of the
per-event leg, never its existence. Recorded because 734 is much larger than the "5 overlappable
sends in 14 days" figure the deferral was based on, and because ruling 2 puts a cadence control in
front of exactly those members. **Flagged to weekly-recap, not folded into my scope.**

---

## 2. THE SEAM — one store, agreed, not handed over as a migration

> THREAD-FOLLOW-SPEC §15.4 q3 asked the consuming lane to agree the cadence home rather than have
> one chosen unilaterally. This is that answer. **thread-follow writes it; follow-digest reads it.**

### 2.1 Recommendation: WP usermeta on the WP pool

```
meta_key   lg_disc_email_cadence      value ∈ {instant, daily, weekly}    (absent ⇒ instant)
meta_key   lg_disc_digest_watermark   value = UTC 'Y-m-d H:i:s'           (see §4)
```

**Why here, in order of weight:**

1. **The sender's whole question is answerable in ONE MySQL join with no cross-database hop.**
   "Who is due, and what did they miss" needs cadence (usermeta), the ✉ bit
   (`wp_bb_notifications_subscriptions`) and the replies (`wp_posts`) — all three are MySQL on the
   WP pool. Putting cadence in PG `looth` would make the sender straddle two databases to answer a
   single question, for no gain.
2. **It is one row per member, which is what usermeta is.** Ruling 2 collapsed the storage from
   (member × thread) to (member). A whole table for one scalar per member is heavier than the fact.
3. **It sits with the account email prefs, which is where the control now lives.** Ruling 2 makes
   this an account setting; the account page is WP. §6's own note says the Discussion-emails master
   is *"naturally usermeta"* — so the master and the cadence end up in **one** store rather than two.
4. **BB's own notification preferences are already usermeta** (`bb_forums_subscribed_reply`), so the
   suppression filter (§3) reads its two inputs from the same place.
5. `meta_key` is indexed; at ~1,850 users the "who is due" scan is trivial.

**Explicitly NOT `forums.topic_follow`.** THREAD-FOLLOW-SPEC §15.5 is right: that table is the
per-(member, topic) 🔔 bit and cadence is neither per-topic nor about the bell. **I am not adding a
column to it and thread-follow is not being handed a migration.**

**Rejected alternatives**, so they are not re-proposed: a new PG table in `looth` (straddles
databases for the hot query, above); a FluentCRM custom field (that is the weekly digest's world —
§2.2); a column on the native BB subscriptions table (per-topic shape, exactly the thing ruling 2
killed).

### 2.2 What the seam obliges each lane to do

| lane | owns | contract |
|---|---|---|
| **thread-follow** | the control (`forums.js` `fmFreqRow`, `FREQ_ENABLED`) and the **write** | Writes cadence through the existing `follow.php` endpoint, self-scoped (`$uid` from session, never the body). Label it unmistakably as **account-wide** — Ian: *"give the control in the modal, but make it known it's a global setting."* |
| **follow-digest** (me) | the store definition, the **read**, the sender, the suppression filter, the flag | Never edits the control's markup without announcing the hunk. Signals keeper when the batcher genuinely delivers; **does not flip `FREQ_ENABLED` itself**. |
| **weekly-recap** | `lg-weekly-digest` entire | **Untouched.** No section added to their campaign, no second toggle minted inside their class, `lg_wd_send_digest` (Mondays 13:00 UTC) not modified. |

**Why this is a new email CLASS and not a control inside weekly-recap's:** `NOTIF-EMAIL-STATE.md`
§6b's shared contract is *account = one master per email **class**; per-thread = membership of that
class; a recap is content inside a class, never a class of its own.* Discussion email is a different
class from the weekly digest — different trigger, different audience resolution, different opt-in —
so it gets its own master and its own cadence. That is the contract being **honoured**, not bent.

### 2.3 One API change thread-follow needs from me, stated precisely

`follow.php`'s existing envelope (`GET ?topics=` → `{authenticated, nonce, state:{...}}`) gains one
**account-level** field alongside the per-topic map, so the modal can render `Emails · on — Daily`
without a second round trip:

```
GET  /bb-mirror-api/v0/follow?topics=12,44
  → { authenticated:true, nonce:"…", cadence:"daily",
      state:{ "12":{notify:true,email:false}, "44":{…} } }

POST /bb-mirror-api/v0/follow      { cadence: 'instant'|'daily'|'weekly' }
  → self-scoped, validated against the allow-list, 400 on anything else.
```

`cadence` is **absent from the response entirely while the flag is OFF**, so the dark control cannot
accidentally render a live-looking value. That absence is gateable, and §5 gates it.

---

## 3. WHAT INSTANT MEANS NOW — and the finding that makes this lane harder than it reads

**You cannot build Daily/Weekly without touching the Instant path.** If a member picks Daily and
BB's native per-reply sender keeps running for them, they get instant mail **and** a daily digest —
the same lie in a new costume. Daily/Weekly must **suppress** the native send for that member, not
merely add a batch on top. *"Instant is unchanged"* is true only for members whose cadence **is**
Instant — which, since absent ⇒ instant, is everyone on day one.

### 3.1 The suppression hook — verified in the deployed source, and it is clean

Read from **live's deployed BuddyBoss 2.20.0**, `bp-forums/classes/class-bp-forums-notification.php`,
inside `BP_Forums_Notification::bb_send_forums_subscribed_reply()` (:989), in the per-recipient loop:

```php
$filter_args = array( 'type' => $type_key, 'reply_id' => $reply_id, 'group_id' => $group_id,
                      'recipient_user_id' => $user_id, 'sender_id' => …, 'author_id' => … );

$send_mail         = (bool) apply_filters( 'bb_send_forums_subscribed_reply_email_notifications', $send_mail, $filter_args );   // ← :1069
$send_notification = (bool) apply_filters( 'bb_send_forums_subscribed_reply_notifications',       $send_notification, $filter_args );
```

Three properties make this the right seam rather than a hack:

1. **Per-recipient.** `$filter_args['recipient_user_id']` is exactly the granularity ruling 2 needs.
2. **Email only.** `$send_notification` is a *separate* filter, so suppressing a member's instant
   **email** leaves their in-app notification untouched. The bell keeps working at real time, which
   is what Ian wanted the bell for all along.
3. **Unclaimed.** Verified across the monorepo: no `add_filter` on this hook exists. The one
   adjacent filter we do own — `lg-discussion-group-gate.php:112`,
   `bbp_forum_subscription_user_ids` — is the **forum/new-discussion** path and is disjoint.

```php
// The entire suppression, and its OFF state is the identity function.
add_filter( 'bb_send_forums_subscribed_reply_email_notifications', function ( $send, $args ) {
    if ( ! LG_FOLLOW_DIGEST_ENABLED )        return $send;          // ← proven no-op
    $uid = (int) ( $args['recipient_user_id'] ?? 0 );
    if ( ! $uid )                            return $send;
    return lg_fd_cadence( $uid ) === 'instant' ? $send : false;     // batcher will carry it
}, 10, 2 );
```

⚠️ **This runs inside BuddyBoss's background updater**, not the request:
`bb_send_notifications_to_subscribers` sets `$background_process = true` whenever
`total > 1` and hands the send off in chunks of 20 (`bb-core-subscriptions.php:1135-1163`). The
filter still fires — but a suppression decision is made **at flush time, not post time**, so a
member who switches cadence between the two can have one reply fall either way. Named as a known,
bounded race; it cannot double-send (the batcher is watermark-driven, §4), only mis-time one item.

---

## 4. WHAT A DIGEST CONTAINS, AND THE WATERMARK

### 4.1 The query

For a due member `U` with watermark `W`:

```sql
SELECT r.ID, r.post_parent AS topic_id, r.post_author, r.post_date_gmt
FROM   wp_posts r
JOIN   wp_bb_notifications_subscriptions s
       ON s.item_id = r.post_parent AND s.type = 'topic' AND s.status = 1 AND s.user_id = U
WHERE  r.post_type   = 'reply'
  AND  r.post_status = 'publish'
  AND  r.post_date_gmt > W
  AND  r.post_author <> U          -- never your own reply
ORDER  BY r.post_date_gmt
LIMIT  51;                         -- 50 shown + 1 to detect "and N more"
```

Content follows the standing privacy ruling — **counts, thread titles and sender display names, with
deep links; never reply body text** (`NOTIF-EMAIL-STATE.md` §1, a privacy ruling not a style one).
Deep links use the existing contract `/hub/?topic=<forum-slug>/<topic-slug>&reply=<id>`.

`post_status='publish'` is doing real work: it is what keeps a reply that was trashed, spammed or
left pending between post time and flush time **out** of the digest. Instant has no equivalent
protection — a batcher is strictly safer here, which is worth stating in the feature's favour.

### 4.2 The watermark is per-member state, and that is a deliberate difference from weekly-recap

weekly-recap's digest uses a **fixed 7-day window** and explicitly needs no new state. **Mine cannot**,
and the reason is ruling 1: a missed run must not silently drop replies.

> **DECISION: watermark, roll forward. A skipped run loses nothing.**
> The next successful run covers everything since the last **successful send**, not since the last
> scheduled time. A box that was down for two days sends a two-day digest, not nothing.

This is the difference between a decision and an accident, which the charter asked me to name.
Rejected alternative: a fixed 24h/7d window, which is simpler and **silently drops** every reply
that lands during an outage. For an unrecallable channel, "silently drops" is the wrong failure.

**Advance the watermark only on a send the sender believes succeeded**, and advance it to the
`post_date_gmt` of the newest item actually included — never to `now()`, or replies landing between
the query and the send are consumed without being sent.

### 4.3 ⚠️ THE FIRST-SEND FLOOD — the dominant risk in the whole design

A member who turns Daily on for the first time has **no watermark**. Read naively that is epoch, and
their first digest is *the entire reply history of every thread they follow* — for the 335-sub
account, thousands of items, unrecallable.

**This is the single most dangerous line in the feature, and it is one line:**

```
Set the watermark to NOW at the moment cadence is first written, and on any transition
into daily|weekly. Never default it to epoch. A digest is never a backfill.
```

The `LIMIT 51` cap bounds the damage but does not remove it — a capped flood is still a flood of
things the member already read months ago. **§5 gates this directly** (a member with a fresh cadence
and old replies in followed threads must receive **nothing**), because it is exactly the class of
defect a presence-only gate cannot see.

---

## 5. THE FLAG, AND WHY ITS OFF STATE IS UNUSUALLY EASY TO PROVE

`LG_FOLLOW_DIGEST_ENABLED`, **defaulted OFF**, copying `LG_AUTHOR_SOCIALS_ALL_MEMBERS`
(`platform/mu-plugins/lg-author-socials.php`) and `LG_THREAD_FOLLOW_ENABLED`
(`bb-mirror/config.php:461`).

**OFF is a byte-identical no-op, and here it is provable rather than asserted** — the OFF path is
the *identity function* on the one filter, and **no cron event is registered at all**:

| surface | OFF | ON |
|---|---|---|
| suppression filter | registered, `return $send` unchanged | returns `false` for daily/weekly members |
| scheduled sender | **not scheduled — no event exists** | one event (§6) |
| `follow.php` `cadence` field | **absent from the response** | present |
| frequency control | absent (thread-follow's `FREQ_ENABLED`) | present |

### 5.1 The gate asserts ABSENCE first — the missing assertion that has cost six misses

Per `CRAFT-STANDARD.md`'s law and the standing rule that gates assert what should be PRESENT and are
blind to what should be ABSENT, `tools/gates/follow-digest-gate.py` must assert, **red-first against
today's build**:

| # | assertion | why it exists |
|---|---|---|
| 1 | flag OFF ⇒ `wp_next_scheduled('lg_fd_send')` is **false** | an armed sender ahead of its code is the mirror-dispatch trap |
| 2 | flag OFF ⇒ the filter returns its input for every cadence value | the no-op claim, tested not asserted |
| 3 | flag OFF ⇒ `follow.php` response has **no** `cadence` key | the dark control cannot render a live-looking value |
| 4 | flag OFF ⇒ frequency control **absent** from the DOM; ON ⇒ **present** | keeper's presence-AND-absence gate |
| 5 | fresh cadence + old replies ⇒ **zero** recipients | §4.3, the flood |
| 6 | cadence=daily ⇒ instant filter returns **false** for that member, and **`$send_notification` is untouched** | suppression must not take the bell with it |
| 7 | a member's **own** reply never appears in their own digest | the oldest bug in every digest ever written |

Gate number minted **from `main`, not this branch**, per the two-lanes-both-minted-9/9 collision.

### 5.2 ⚠️ dev2 cannot prove a send, so the suite must not pretend to

`lg-dev-mail-containment.php` swallows `wp_mail` into mailpit and **returns true**. A green send test
on this box is a convincing false positive and is **not evidence**. Therefore:

- Every gate above asserts on the **recipient set and the store**, never on "mail was sent."
- The one thing dev2 genuinely proves is the **negative**: flag OFF ⇒ zero recipients resolved, zero
  events scheduled. That is real, and it is the assertion that matters most for an unrecallable channel.
- A real delivery test is **Ian's**, on his own address, deliberate and one-shot — the same posture
  `lg-weekly-digest/dev/build-inbox-test.php` documents.

---

## 6. THE SCHEDULE — and a second sender IS being armed, said plainly

The charter requires this stated explicitly rather than buried: **yes, this design adds a second
scheduled sender.** It is not near `lg_wd_send_digest` and does not touch it.

| | existing (weekly-recap's) | **new (mine)** |
|---|---|---|
| hook | `lg_wd_send_digest` | `lg_fd_send` |
| when | Mondays 13:00 UTC | daily 08:00 member-local-ish (America/New_York), weekly Sundays |
| audience | FluentCRM list 3 + 7, by CRM tag | resolved from usermeta cadence + ✉ subscriptions |
| registered | always | **only while `LG_FOLLOW_DIGEST_ENABLED`** |

Driven by the existing `lg-wp-cron.timer` (verified: 1-minute tick, `wp cron event run --due-now`,
present and active on **both** dev2 and live). No new systemd unit, no new timer.

**Registered only while the flag is ON** — and *unscheduled on flag-off*, because the
mirror-dispatch lane's outbox timer is the precedent: a sender armed ahead of its code reddens
`systemctl --failed` forever and kills the alert channel. Gate assertion 1 is exactly this.

---

## 7. What is NOT in scope, so it is not discovered as a gap later

- **The §6 account-level "Discussion emails" master switch.** Proposed in THREAD-FOLLOW-SPEC, not
  ruled. My scope is cadence. The design composes with it (both usermeta, master checked before
  cadence) but I am not inventing a ruling Ian has not made.
- **Replacing BB's reply sender outright** (ruling 10bis's *"our own send mechanism"*). Suppress-and-
  batch reaches the same member-visible outcome for daily/weekly without a rewrite; full replacement
  is a larger lane and should be its own.
- **The 46 forum / 12,948 group subscriptions.** Different path, different hook, §8.1.3(b) and
  §8.2 — untouched.
- **The 734-row double-send** (§1.3). Flagged to weekly-recap; not worsened here.
