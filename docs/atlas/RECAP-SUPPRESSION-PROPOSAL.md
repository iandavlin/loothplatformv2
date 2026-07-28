# RECAP-SUPPRESSION-PROPOSAL

> **Status: FINISHED PROPOSAL, measured on LIVE, awaiting Ian.** Axis 1 is shipped. **Axis 2:
> do not build** (measured — the surface does not exist). **Axis 3: build Rule 3b** (the trigger I
> set for it turned out to have already fired in June, and the design needs no new schema).
> **Lane:** weekly-digest-recap. **Date:** 2026-07-27, **revised 2026-07-28** after running the
> repro script end to end, which caught a material error in §1.3(b) — see the correction there.
> **All numbers are from LIVE**, read-only via `live-ro` (PG `profile_app` as `looth_ro`,
> MySQL `looth_import`) — not dev2, which holds different data.
> Cross-refs: THREAD-FOLLOW-SPEC.md @2cb9e3f (§1.3, §3.7, §6b, §8, §9.1, §9.2),
> WEEKLY-DIGEST-RECAP.md §9, EMAIL-AUDIT.md.

---

## 0. Ian's requirement

> Do not duplicate notifications in the email that have already been read — either read in one of
> those emails, or already read on the website.

Three axes, and they are genuinely different problems:

1. **Already read on the website** — bell read state.
2. **Already sent in a per-event email** — *harder*, because an item can be **unread in the bell and
   still have been emailed**. Read state cannot see this.
3. **Already sent in a previous digest** — needs a per-member watermark, not a date window, because
   a failed send silently re-arms a date window.

**Nothing existing solves any of them across channels.** The dedup at `lg-shared/notify-bridge.php`
:160-182 is *within* the bell pipeline and *per event* — it decides that a person who was both
mentioned and replied-to gets one bell row rather than two. It has no knowledge of email at all, and
never runs at digest time. It is not a partial answer to axis 2; it is a different question.

**The measurements change the answer, so they come first.**

---

## 1. What the stores actually say (LIVE, 2026-07-27 23:30 UTC)

### 1.1 The recap's real composition

Exact recap-shaped query (7-day window, `is_read = false`, plus the live `connections.status` test):

| Type | Listable | Suppressed by `is_read` | Total in window |
|---|---|---|---|
| `connection_request` | 92 | 0 | 92 |
| `connection_accept` | 19 | 5 | 24 |
| `reaction.on_post` | 16 | 13 | 29 |
| `forum.reply_to_topic` | 1 | 1 | 2 |
| `forum.mention` | 0 | 1 | 1 |
| **Total** | **128** | **20** | **148** |

Raw rows in the window are 152; the `connections.status` test drops 4 more before `is_read` is even
consulted. **Axis 1 removes 24 of 152 items — 15.8%.**

The composition is the headline for everything below: **111 of 128 listable items (87%) are
connection notifications.** One is a forum item.

> *Correcting my own earlier figure:* the previous revision of this doc reported 101 items / 21.8%
> read-suppressed. That was a true reading of an earlier 7-day window; the window has since slid over
> a burst of 92 connection requests, which dilutes the percentage. The absolute read-suppression
> count is stable (22 then, 20 now). The rate is not a constant and should not be quoted as one.

### 1.2 Axis 2 — already sent in a per-event email

`wp_fsmpt_email_logs` holds **6,669 rows covering exactly 14 days** (2026-07-13 08:52 →
2026-07-27 19:00). FluentSMTP logs *every* `wp_mail`, so this is the complete outbound record, not a
forum-only slice. Classified against the bell's types:

| Email class | Sends (14d) | Can it overlap a bell row? | Why |
|---|---|---|---|
| `New discussion:` (forum subscription) | 31 | **No** | The bell has no matching type — the recap can never list it |
| `mentioned you` | 4 | **Yes** | Same person, same event as `forum.mention` |
| `replied to` | 1 | **Yes** | When the topic author also holds the subscription |
| Connection request / accept | **0** | — | **No email sender exists** |
| Direct messages | **0** | — | **No email sender exists** |
| Reactions | **0** | — | **No email sender exists** |

**The structural reason for those three zeros, which is the important finding:** connections,
messaging and reactions are *our* features, living in `profile_app`. Nothing in the codebase ever
emails about them. Forums are the only surface BuddyBoss owns, so **the entire axis-2 surface is the
forum types — and forum items are 1 of 128 listable rows this week.**

> *Trap noted so nobody repeats it:* a naive `subject REGEXP 'group'` returns 5,689 hits. Every one
> is the string "The Looth Group" in the site name. That number means nothing.

**So the overlappable email volume is 5 sends in 14 days**, against a digest list of 1,858
recipients.

**And the measured overlap is zero — but it is UNTESTED, not CONFIRMED.** This distinction is the
whole point and I am keeping it explicit, because the stronger claim is the tempting one:

- Only **three forum bell rows have ever existed on live**: ids 789 and 790 (both 2026-07-25
  02:39:55) and 825 (2026-07-27 15:44:45). `forum.mention` and `forum.reply_to_topic` did not write
  before 2026-07-25.
- The **last overlappable email was 2026-07-24 22:39:56** — **four hours before the first bell row
  of a type that could overlap it.**

**The comparable window therefore contains zero mention events.** Not "I looked and found no
overlap" — there was nothing that *could* have overlapped. I have no measurement of the overlap rate
in either direction, and I am not going to dress a structural expectation up as a finding.

What I *can* say without overreaching: **the overlap is bounded above by the overlappable email
volume**, because an item that was never emailed cannot be duplicated by email. That bound —
**5 in 14 days, ≈2.5 per week, across 1,858 recipients** — does not depend on when the bell started,
because the email side ran for the whole 14 days (sends on 07-13, 07-18, 07-21, 07-23, 07-24).

**One forward-looking caveat, offered as a caveat and not a number:** the mention *minter* shipped
2026-07-23 (username-mentions lane), and two of the four mention emails fall after it. Autocompleted
mentions resolve where hand-typed ones did not, so the mention rate may rise. That is a reason to
re-measure, not a reason to build now.

### 1.3 Axis 3 — already sent in a previous digest

Two distinct failures live here, and **the one that is structurally certain is not the one that was
feared.**

**(a) The window does not align with the cadence — measured.** The digest is a campaign whose send
time drifts by hours. A constant 7-day lookback cannot meet a drifting send time, so every interval
either loses a band or duplicates one:

| Transition | Gap between sends | Effect | Items actually in the band |
|---|---|---|---|
| 342 → 345 (06-29 → 07-06) | 7d 03h32m | **Lost** 3h32m | 0 |
| 345 → 347 (07-06 → 07-13) | 6d 23h19m | **Double-shown** 41m | **1** |
| 347 → 364 (07-13 → 07-20) | 6d 23h37m | **Double-shown** 23m | 0 |
| 364 → 379 (07-20 → 07-27) | 7d 00h57m | **Lost** 57m | 0 |

**Four transitions, four misalignments, and a total real-world cost of one item.** The defect is
certain and permanent; the damage at current volume (0.71 notifications/hour platform-wide) is
approximately one item per month. I am reporting both halves because quoting only the first would
justify machinery the second does not.

**(b) A per-recipient send can fail — and it has already happened to a weekly digest.**

> **CORRECTION — I got this wrong in the previous revision and the error was material.** I wrote
> "no weekly digest has ever had a failed recipient", and named campaign 283 as a non-digest. **283
> is `Weekly Digest — June 1, 2026`.** I had matched campaign titles on `LIKE '%Week of%'`, got an
> empty result, and read that empty result as a real zero. The live titles read `Weekly Digest —
> June 1, 2026`. That is precisely the trap I had written into
> `dev/measure-suppression-axes.sh` as a warning to others — an empty result from a wrong pattern is
> indistinguishable from a genuine zero — and I fell into it in the same document. It was caught by
> running that script end to end, which is the entire reason it exists.

`wp_fc_campaign_emails` carries a status per recipient: **32,983 sent, 1,122 scheduled, 65 failed**
all-time. Across the **19 weekly digest campaigns** on record (2026-03-16 → 2026-07-27):

| | |
|---|---|
| Digests with every recipient sent | **18** |
| Digests with failed recipients | **1** — `Weekly Digest — June 1, 2026` |
| Recipients lost on that send | **6 of 1,739 (0.34%)** |
| Cause | `SimpleEmailService::sendRawEmail(): Sender - SignatureDoesNotMatch` |
| Ever successfully re-sent | **No** — all six still read `failed`, eight weeks later |

The six are real named members (`johnthebaker18@`, `ian@evansguitarlab`, `lpdude45@`, `ljinlay2@`,
`lfrobisonjr@`, `t.l.wolf@`).

**The magnitude that matters is not 0.34%.** All six failed inside one second with an identical
cause. This is a **correlated** failure — one bad sender signature — not a per-recipient random
one. It took out six because six were unlucky in the batch, but nothing about a signature error
bounds it at six; the same fault could take the whole list. **A date window converts any such event
into permanent, silent loss for everyone it touches**, and there is no signal afterwards that it
happened.

**(c) The cost of a watermark with no floor.** If the window were replaced by "everything still
outstanding since your last successful digest" with no starting floor, the first send would carry
the whole historical backlog:

| | Value |
|---|---|
| Items older than the window, still listable | **349** |
| Members affected | **273** |
| Average per member | **1.28** |
| Members with ≥5 items | 4 |
| Members with ≥10 items | 3 |
| **Worst single member** | **19 items** |

Composition: 262 `connection_request`, 81 `connection_accept`, 6 `reaction.on_post` — almost
entirely stale social requests from June and early July.

> *Correcting my own earlier figure again:* the previous revision said "271 members, avg 2.8 each".
> 347 ÷ 271 is 1.28, not 2.8 — that was my arithmetic error, and it made the backlog sound roughly
> twice as alarming as it is. The honest shape is **269 members get one to four stale items, and
> three members get a genuinely bad email.** The floor is still right, but it is a floor against a
> bad experience for three people, not against a mass re-notification event.

---

## 2. The proposal

### Rule 1 — read on the website: **SHIPPED, keep, no change requested**

Exclude `is_read` at the source, in `Recap::forWpIds()`. Alongside it, and already shipped in the
same WHERE clause: a connection notification is suppressed once the **edge itself** is no longer
outstanding (`connections.status` read live), because a bell row stays unread even after the member
accepts on the profile.

**Together: 24 of 152 items suppressed, 15.8%.** This is the axis that carries the requirement.

### Rule 2 — already per-event emailed: **DO NOT BUILD. Re-measure in a fortnight.**

This is a reversal of the previous revision, and the measurement is why.

**The reasoning that stands:** if it were built, the right instrument is a stamp, not a log scrape.
`wp_fsmpt_email_logs` is the wrong instrument for three reasons all visible in §1.2 — retention is
14 days (a monthly view is impossible), matching is by **subject string**, and it records a
*recipient address*, not *which item the mail was about*. It can only ever say "some mention mail
went to this person that week". The correct shape is one nullable column,
**`notifications.emailed_at`**, stamped by whatever sends the per-event mail, excluded in the same
WHERE clause that already handles `is_read`. Exact, permanent, item-granular.

**The reason not to build it:**

| | |
|---|---|
| Listable items this week the rule could possibly touch | **1 of 128** |
| Overlappable emails in 14 days | **5** |
| Forum bell rows that have ever existed on live | **3** |
| Overlap events observed | **0 (and untestable — see §1.2)** |
| Cost to build | A live schema change, **plus** making BuddyBoss's own sender and our notify-bridge agree on event identity — they share no key today (spec §1.3) |

**Building a live schema change and a cross-system identity contract to suppress an item class that
produced three rows in its entire history is out of proportion.** The 87% of the recap that is
connection traffic has no email path to overlap with, and cannot acquire one without new work that
would itself be the moment to revisit this.

**Two conditions that should reopen it**, either of which is a real trigger rather than a hunch:

1. **Ian rules §9.1 option B** — an explicit ✉ toggle earns per-event mail *and* the digest keeps
   covering discussion activity. That is the only §9.1 outcome where both channels deliberately
   cover the same events. Under options A or C ("digest owns per-reply follow-ups") the overlap
   disappears by construction and rule 2 is permanently unnecessary.
2. **Forum bell volume becomes non-trivial** — say, forum types exceed 10% of listable items in a
   week. Re-run §1.2's classification then; it is four queries and they are recorded here.

**What to do in the meantime — nothing, and say so in the code.** A comment at the suppression site
recording that axis 2 was measured, costed and declined, with the trigger to revisit, is worth more
than a column nobody stamps.

### Rule 3 — previous digest: **replace the constant window with the member's own last send. Floor it.**

> **This section was rewritten after the correction in §1.3(b).** The previous revision recommended
> a global window (3a) and shelved the per-member one (3b) on the grounds that digest sends had never
> failed. **They have.** With that corrected, and with 3b turning out to need no new schema at all,
> the two collapse into a single recommendation: **build 3b.** 3a is retained below only because it
> is the fallback if Ian wants the smaller change, and because its call-site analysis is what 3b uses
> too.

**Rule 3a (fallback, global): derive the window from the last digest send.**

Instead of a constant 7 days, the window is `[time of the previous digest campaign's send, now]`.
This eliminates the drift bands in §1.3(a) entirely — no gap, no overlap, by construction — and it
self-corrects if a week is skipped, because the window simply grows to cover the gap. **It does not
help the six members in §1.3(b)**, because a global window cannot know that one member's send failed.

**This is a call-site change, not a schema change.** The lookback is already a parameter threaded end
to end: `Recap_Source::fetch( array $wp_user_ids, int $days = 7 )`
(`class-lg-wd-recap-source.php:173`) → `internal-recap.php:65` → `Recap::forWpIds($ids, $days)`.
Today `payload_for()` at line 159 calls `fetch([$id])` and takes the default. The change is to pass
a computed value there.

**The 1..90 clamp is already enforced at the source** — `Recap.php:98`, `max(1, min(90, $days))`,
inside `forWpIds()` rather than at the endpoint, on the same principle as the `is_read` filter: every
caller gets the same answer and no call site can widen the window by accident. Rule 3a therefore
cannot produce an unbounded lookback even if the computed value is wrong. (I checked
`internal-recap.php` first, saw it clamp only the value it *echoes* in the response, and thought the
query ran unclamped. It does not — the guard is one layer down, deliberately.)

**It must still ship with an explicit floor**, because the 90-day clamp is far wider than the
backlog: a first run reaching back to the previous campaign under the new rule would expose
§1.3(c)'s 349 items. The floor: the window never starts earlier than the day this ships. Members
begin clean; the historical backlog is never mailed. Given it is 343 of 349 stale connection rows
from June and early July, not mailing it is also the right product answer.

**Rule 3b (BUILD IT — the trigger has already fired, and it is far cheaper than I first costed).**

The previous revision shelved this behind a trigger: *"the first weekly-digest campaign that records
a `failed` row."* §1.3(b) shows that trigger was **already met on 2026-06-01**, and I only missed it
because of a bad `LIKE` pattern. So the trigger is not a future condition — it is history.

**And the design I proposed for it was more machinery than the job needs.** I specified a new table
`digest_renders(...)`, a post-send reconciler, and a two-phase render/confirm coupling. **None of
that is necessary**, because FluentCRM *already* stores per-recipient send status, and the recap
*already* renders per recipient. The window start can simply be read:

> **A member's window starts at the send time of the most recent weekly digest that member actually
> received.**

One batched query against tables that already exist — no new schema, no reconciler, no two-phase
commit:

```sql
SELECT e.subscriber_id, s.user_id AS wp_id, MAX(c.created_at) AS window_start
  FROM wp_fc_campaigns c
  JOIN wp_fc_campaign_emails e ON e.campaign_id = c.id
  JOIN wp_fc_subscribers    s ON s.id = e.subscriber_id
 WHERE c.title LIKE 'Weekly Digest%' AND e.status = 'sent'
   AND e.subscriber_id IN (…this send's recipients…)
 GROUP BY 1, 2;
```

**Demonstrated on live against the six real casualties.** Asked "what window would these members get
at the next digest (June 22)?":

| Member | Window start | |
|---|---|---|
| subscriber 964 | **2026-05-25** | reaches back past their failed June 1 send |
| subscriber 1884 | **2026-05-25** | reaches back past their failed June 1 send |
| subscriber 1000 (healthy control) | 2026-06-01 | correctly starts at the send they received |
| subscriber 1001 (healthy control) | 2026-06-01 | correctly starts at the send they received |

**It self-heals per member, with no state of our own to keep correct.** A member whose send failed
automatically gets a window covering what they missed; a member whose send succeeded does not repeat.
This subsumes Rule 3a — the drift bands in §1.3(a) also disappear, because the window start is a real
send time rather than a constant offset.

**Two honest caveats:**

1. **It matches campaigns by title string** (`LIKE 'Weekly Digest%'`), and I objected to string
   matching in Rule 2. The objection is not symmetric and I want to be explicit about why: there,
   the string had to identify **which item a mail was about**, which a subject line fundamentally
   cannot do. Here it identifies **which campaign is the digest** — admin-controlled, stable across
   19 campaigns, and verifiable at a glance. If Ian prefers it exact, the digest can tag its own
   campaigns and the `LIKE` becomes an id lookup; that is a small change, not a redesign.
2. **`wp_fc_subscribers.user_id` is NULL for some contacts** — 2 of the 6 casualties, in fact. That
   gap is already solved: `Recap_Source::wp_user_id_for()`
   (`class-lg-wd-recap-source.php:130`) falls back to matching on email, which is how the weekly
   list was built in the first place. Reuse it; do not re-derive it.

**The floor still applies**, unchanged and for the same reason — see Rule 3a.

### 2.1 What this does not cover — the fourth axis, still open

Ian already has this one (WEEKLY-DIGEST-RECAP.md §9, and the header comment in
`profile-app/src/Recap.php`): **nothing marks a notification read when a member clicks a link in the
email.** Recap links go straight to `/hub/?topic=` and never touch `is_read`; `markRead` is called
only from `/me-notifications`, the bell modal. So an email-only reader keeps seeing the same items.

The obvious fix — a click-clear redirect — is deliberately **not** built, because mail-security
scanners follow every link with no human involved, and on this platform's own click data 7-10% of
apparent clickers are machines hitting 10-20 links inside four seconds. A GET that cleared items
would wipe a member's whole recap before they opened it, and the failure would look exactly like the
feature working. **Awaiting Ian's ruling.** Rules 1-3 do not resolve it and do not depend on it.

---

## 3. The visible consequence

Rule 1 rendered against two real live members, before and after
(`lg-weekly-digest/dev/frames/`, published when a serve window allows):

| Member | Items in window | Without suppression | **With suppression** |
|---|---|---|---|
| Doug Proper (wp:197) | 14 (6 read) | 9 rows (8 + "6 more waiting") | **8 rows** |
| **Ian Davlin (wp:1)** | 5 (**all** read) | 5 rows | **NO SECTION AT ALL** |

Ian's own live account is the clean demonstration: five things happened to him that week, he read all
five on the site, and the correct email says nothing. That empty case is proven byte-identical to a
no-recap body.

---

## 4. What this amends, said explicitly

**THREAD-FOLLOW-SPEC.md §3.7 is superseded**, flagged rather than quietly diverged from. §3.7
describes the recap as subscription-derived (`forums.forum_subscription` ⋈ replies), generic copy
("12 new replies across 3 discussions you follow"), a single *Open the Hub* link, with per-recipient
rendering listed as an open feasibility question.

| §3.7 (v1) | Built |
|---|---|
| Source: `forums.forum_subscription` ⋈ replies, via a new `bb-mirror-api/v0/follow-recap` | Source: the **bell** (`profile_app.notifications`) + unread DMs, via `internal-recap` |
| Scope: threads you follow | Scope: things addressed to **you** — allow-list; **`forum.followed_topic` deliberately excluded** |
| One "Open the Hub" link | **Per-row deep links** (Ian's call from the frames) |
| Generic counts, no titles | **Discussion titles named** (Ian's call) |
| Per-recipient rendering: open question | **Proven** — one campaign, five real subscribers, five distinct emails |

**Nothing in §3.7's intent is lost** — a member who follows a thread still gets a weekly summary the
moment `forum.followed_topic` joins the allow-list, which is one line. The difference is the spine:
the bell, not a second subscription query.

**§6b's two asks of this lane, answered:**

1. **The recap mints no account-level toggle.** It is governed solely by existing `lg-pref-weekly` /
   list-3 membership. A member who turns the weekly digest off renders no recap, automatically.
2. **`#lg-email-prefs` stays append-only markup.** This lane did not touch `manage-subscription.php`
   at all. §6's fourth `.lg-pref-row` sibling plus one `wire()` call lands unchanged. **No merge
   conflict from this lane.**

I also adopt §6b's shared contract: *account = one master per email class; per-thread = membership of
that class; a recap is content inside a class, never a class of its own.*

---

## 5. What I could not prove

- **Axis 2's overlap rate does not exist as a measurement.** The comparable window contains zero
  mention events (§1.2). The upper bound (5 overlappable emails / 14 days) is measured and holds; the
  actual rate is unknown and I am not estimating it.
- **The mention rate after the autocomplete minter is unmeasured** — two of four mention emails
  postdate it, which is a signal, not a trend.
- **Group subscriptions (12,948 rows / 1,853 users)** are untouched here, as in the spec. If group
  discussion mail ever overlaps the recap, this proposal does not cover it.
- **Nothing here is built.** Rule 1 was already shipped; Rules 2 and 3 are written, costed and
  unwritten in code. **Rule 3b is the one I am asking to build.**
- **Rule 3b's window query is demonstrated, not deployed.** I ran it against live and it returns the
  correct reach-back for the six real casualties and the correct no-reach-back for healthy controls
  (§ Rule 3b). That proves the *data supports it*. It has not been wired into
  `Recap_Source::payload_for()`, and no send has been rendered through it.
- **I do not know whether the June 1 failure recurs on any schedule.** One event in 19 digests is not
  a rate. The argument for 3b rests on the failure being *correlated and silent*, not on frequency.
- **No serve window was held for this work** — it is measurement and design only. The live reads were
  all through `live-ro`, read-only, on the LIVE box (not dev2; the two hold different data).
