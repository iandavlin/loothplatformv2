# RECAP-SUPPRESSION-PROPOSAL

> **Status: FINISHED PROPOSAL, measured on LIVE, awaiting Ian.** Axis 1 is shipped; axes 2 and 3
> are designed and costed here, and **the measurements argue against building most of it.**
> **Lane:** weekly-digest-recap. **Date:** 2026-07-27 (re-measured 23:30 UTC after respin).
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

**(b) A per-recipient send can fail — and does on this platform.** `wp_fc_campaign_emails` carries a
status per recipient: **32,983 sent, 1,122 scheduled, 65 failed** all-time. The failures are real
infrastructure, not test noise:

- campaign **283: 6 recipients**, `SimpleEmailService::sendRawEmail(): Sender - SignatureDoesNotMatch`
- campaign **38: 59 recipients**, `Message body empty`

**No weekly digest has ever had a failed recipient** — campaign 379 (Weekly Digest, July 27) is
1,858 sent, 1,858 recipients. So the failure mode is *proven possible on this exact table and this
exact sender*, and *never yet observed on this campaign type*. Under a pure date window a member
whose send failed loses those items permanently and silently.

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

### Rule 3 — previous digest: **fix the window, not the architecture. Floor it.**

The previous revision proposed a per-member watermark. The measurements in §1.3 say the drift costs
about one item a month and digest sends have never failed, so I am proposing the cheap correct fix
and keeping the expensive one on the shelf with a named trigger.

**Rule 3a (recommended, build this): derive the window from the last successful digest send.**

Instead of a constant 7 days, the window is `[time of the previous digest campaign's send, now]`.
This eliminates the drift bands in §1.3(a) entirely — no gap, no overlap, by construction — and it
self-corrects if a week is skipped, because the window simply grows to cover the gap.

**This is a call-site change, not a schema change.** The lookback is already a parameter threaded end
to end: `Recap_Source::fetch( array $wp_user_ids, int $days = 7 )`
(`class-lg-wd-recap-source.php:173`) → `internal-recap.php:65` → `Recap::forWpIds($ids, $days)`,
clamped 1..90 at the endpoint. Today `payload_for()` at line 159 calls `fetch([$id])` and takes the
default. The change is to pass a computed value there.

**It must ship with the floor**, or the first run under the new rule reaches back to whenever the
previous campaign was and exposes §1.3(c)'s backlog. The floor: the window never starts earlier than
the day this ships, and it is additionally clamped by the endpoint's existing 1..90 guard. Members
begin clean; the 349-item historical backlog is never mailed. Given that backlog is 343 of 349 stale
connection rows from June and early July, not mailing it is also the right product answer.

**Rule 3b (do not build yet): the per-member watermark.**

The exact form, so it is on record and costed:

- A table `digest_renders(user_uuid, campaign_id, max_notification_id, rendered_at, confirmed_at)`.
- At render time, record the highest `notifications.id` put into that member's email.
  `notifications.id` is a `bigint` sequence (max 888, 604 rows) — a monotonic key is the right
  watermark, not a timestamp, because it is immune to clock and timezone drift.
- After the send, a reconciler sets `confirmed_at` from
  `wp_fc_campaign_emails.status = 'sent'` for that `(campaign_id, subscriber_id)`. **That column is
  the only honest "this member's send succeeded" signal that exists** — §1.3(b) is the proof it
  distinguishes real outcomes.
- The member's watermark is `max(max_notification_id)` over **confirmed** rows only.

**Its failure mode is the safe one:** an unconfirmed render does not advance the watermark, so the
member sees the item again next week. Re-showing is recoverable; silent loss is not.

**Why it waits:** it buys exactness over Rule 3a only when a *digest* send fails per-recipient, which
has never happened in 32,983 sends of this campaign type. Storage would be ~97k rows a year, which is
nothing; the cost is the reconciler and the two-phase render/confirm coupling. **Trigger to build it:
the first weekly-digest campaign that records a `failed` row in `wp_fc_campaign_emails`.** That is a
one-line check anyone can run.

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
  unwritten in code. Rule 3a is the only one I am asking to build.
- **No serve window was held for this work** — it is measurement and design only. The live reads were
  all through `live-ro`, read-only.
