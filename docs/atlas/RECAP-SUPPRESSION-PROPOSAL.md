# RECAP-SUPPRESSION-PROPOSAL

> **Status: RULED AND BUILT — 2026-07-28. This document RECORDS the rulings; it does not re-argue
> them.** Four came in one evening and together they changed what the digest *is*:
>
> | | Ruling | Built as |
> |---|---|---|
> | **A** | **Fixed 7-day window** — not per-member (Rule 3b declined) | `LG_WD_Recap_Source::WINDOW_DAYS`, a declared constant |
> | **B** | **The digest is a TO-DO LIST, not a news feed** | `INCLUDED_TYPES`, one admission question |
> | **C** | **Empty means send NO EMAIL AT ALL** | `recipients_with_something_waiting()`, before `subscribe()` |
> | **D** | **Fresh items NAMED, stale-unresolved COUNTED** | `rows_from_stale()`, the second register |
>
> Axis 1 (read on the website) shipped earlier and survives, narrowed by B. **Axis 2 (already
> per-event emailed): still do not build** — on VOLUME, not on structure; the overlap rate remains
> **untested, not zero**. Axis 3 is closed by ruling A.
>
> ⚠️ **REVISED 2026-07-30 — I retract a claim from the 07-28 revision.** It said axis 2 was *retired
> permanently, under every §9.1 outcome*, because the digest cannot carry followed-thread activity.
> **The digest cannot carry `forum.followed_topic`, but it NAMES `forum.reply_to_topic`, and that is
> the type that overlaps** — BB's reply mailer excludes only the *replier*, never the topic author.
> The recommendation ("do not build") is unchanged; its justification is now volume with a live
> trigger, not impossibility. **§4.1b and §4.1c are the correction, and it is a dependency on the
> thread-follow lane rather than a number to watch.**
>
> **Tests:** `verify-source-boundary`, `verify-window-fixed`, `verify-empty-means-no-send`,
> `verify-two-registers` — 4/4.
> **The pictures are up — §3:** https://dev2.loothgroup.com/v2/tests/output/wd-recap/index.html
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

## 1. What the stores actually say (LIVE, re-measured 2026-07-28 against the RULED design)

> **EVERY FIGURE IN THIS SECTION WAS RECOMPUTED after the 07-28 rulings.** The earlier numbers were
> measured against the unfiltered set — every bell type, `is_read` as the only outstanding-test — and
> that set no longer exists. Where a number moved I say so and why, rather than overwriting it
> quietly; the ratios in particular move a lot, because the two largest excluded types were also the
> two most likely to be already-read.

### 1.1 The recap's real composition

**As ruled** — 7-day window, to-do types only, outstanding = edge `pending` for connection requests
and `is_read = false` for forum types:

| Type | Named this week | Stale, still unresolved | Ruled |
|---|---|---|---|
| `connection_request` | 96 | **257** | IN — they must accept or decline |
| `forum.mention` | 4 | **0** | IN — addressed directly |
| `forum.reply_to_reply` | 4 | **0** | IN |
| `forum.reply_to_topic` | 2 | **0** | IN |
| **Total admitted** | **106** | **257** | |
| `connection_accept` | — | — | **OUT** — nothing is owed |
| `reaction.on_post` | — | — | **OUT** — nothing is owed |

Counted against bridged members only, which is what the digest can actually mail.

> **These are a DATED SNAPSHOT, not constants — re-derive them, do not quote them.**
> `dev/measure-suppression-axes.sh` prints all of it in one read-only run. **Three runs on three
> consecutive days**, which is what makes the trend readable rather than a single reading:
>
> | | 07-28 | 07-29 | **07-30** |
> |---|---|---|---|
> | `connection_request` named / stale | 96 / 257 | 94 / 259 | **88 / 244** |
> | forum items named (mention+r2t+r2r) | 4+2+4 | 3+2+1 | **4+2+1** |
> | **total mailed** | **280** | **277** | **258** |
> | — named only | 43 | 38 | **33** |
> | — **counted only** | **181** | **181** | **166** |
> | — both | 56 | 58 | **59** |
> | would be mailed WITHOUT the counted register | 99 | 96 | **92** |
>
> ⚠️ **A CLAIM I MADE HERE ON 07-29 IS NOW WITHDRAWN.** I wrote that 181 counted-only *"on both days,
> identical"* was **"the load-bearing number ... and it is stable."** The third reading is **166**.
> **Two equal readings are not a demonstration of stability** — I generalised a trend from n=2, which
> is the same species of error as inferring the stale split from a total (below). The shape claim that
> *does* survive all three days is the weaker and sufficient one: **the counted-only group is the
> majority of the recipient list every time** (65% / 65% / 64%), and without the counted register the
> list falls by roughly two thirds. That is the load-bearing fact; 181 was never a constant.

> *I got this table wrong first time and it is worth saying how.* I published a per-type stale
> breakdown of 253/3/1/2 that I had **inferred from the total rather than measured** — the total was
> real, the split was not. Measured, **every single stale item is a connection request**. The reason
> is structural and I should have seen it: `forum.*` notifications only began writing on 2026-07-25,
> so no forum item is old enough to have gone stale yet. A breakdown that "looks about right" is
> exactly the kind of number that survives into a decision.

**THE COUNTED REGISTER IS CURRENTLY 100% CONNECTION REQUESTS.** That will change as forum items age
past a window for the first time, and it is worth watching: the copy "You have 2 replies to your
comments waiting" has never once been rendered against real data.

**What the to-do test removed, measured:** of 135 items listable under the old rules this week,
**35 were `connection_accept` (20) or `reaction.on_post` (15)** — 26% of the section, gone because
nothing waits on the member. All-time the two are 147 and 53 rows.

**The composition headline survives and is stronger: 96 of 106 named items (91%) are connection
requests.** Ten are forum items. Any argument here leaning on "the recap is mostly connection
traffic" still holds.

> *Numbers that moved, named explicitly.* The previous revision reported **128 listable / 15.8%
> read-suppressed** against the unfiltered set. Neither figure survives, and not because the store
> changed:
> - **128 → 106 named.** The to-do filter removed 35 items, and the resolved-test admitted several
>   `connection_request` rows that `is_read` had been hiding (91 under the old test, 96 under the new
>   one — a member who glanced at the bell had not thereby answered anybody).
> - **"Axis 1 removes 15.8%" is no longer a meaningful statistic** and should not be quoted. For
>   connection requests `is_read` is not the suppressor any more — the edge status is — so the
>   read-suppression rate now describes only the ten forum items, which is far too small a base to
>   express as a percentage.
> - **An earlier board post of mine said 96 members would be mailed on named items alone.** That was
>   measured with the old `is_read` test and a separately-computed DM leg. Recomputed consistently
>   under the resolved-test it is **99**. The conclusion it supported — that the counted register
>   roughly triples the recipient list — is unchanged and if anything understated.
> - **The stale register did not exist when that revision was written.** 257 items that the old
>   design simply discarded are now counted rather than dropped.

### 1.1b Who actually receives a digest

The rulings interact here, and the interaction is the whole answer — measured on live, list 3,
**1,663 subscribed**:

| | Members mailed |
|---|---|
| Named items only — if the counted register did not exist | **99** |
| **As built, with the counted register** | **280** |
| — named only | 43 |
| — **counted only** | **181** |
| — both registers | 56 |

(43 + 181 + 56 = 280; 43 + 56 = the 99 who have anything named.)

**181 of 280 recipients get only a counted line.** The counted register is not a refinement on the
design; it is the majority of the recipient list. Without it, "empty means send nothing" would have
silenced every one of those members while the renderer was perfectly able to draw their row.

**Stale volume is small and the singular is the common case:** 257 rows across 237 members —
**224 have exactly one, 6 have two, 7 have three, and nobody has more.** Ian's example copy ("You
have 6 connection requests") is above today's ceiling; the line most members will actually read is
"You have 1 connection request waiting".

> *One earlier figure retired for a different reason.* A previous revision reported a backlog of
> "349 items older than the window, worst member 19". That used `is_read` as the outstanding-test and
> included accepts and reactions. Under the resolved-test the same question answers **257 items,
> worst member 3** — most of those old requests had in fact been accepted, which `is_read` could not
> see. The 19 was never wrong for the question it answered; it is the wrong question now.

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
**5 in 14 days, ≈2.5 per week, across 1,858 real recipients** — does not depend on when the bell started,
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

## 2. The rules, as ruled and built

### Rule 1 — read on the website: **SHIPPED, and NARROWED by the to-do ruling**

Exclude `is_read` at the source, in `Recap::forWpIds()`. Alongside it, and already shipped in the
same WHERE clause: a connection notification is suppressed once the **edge itself** is no longer
outstanding (`connections.status` read live), because a bell row stays unread even after the member
accepts on the profile.

This is the axis that carries the requirement.

> **The "24 of 152, 15.8%" headline that stood here has been REMOVED, and §1.1 is the reason.** That
> section retires the figure explicitly — *"'Axis 1 removes 15.8%' is no longer a meaningful statistic
> and should not be quoted"* — because for `connection_request` the suppressor is now the edge status,
> not `is_read`, so the read-suppression rate describes only the handful of forum items. **This
> document was quoting, as Rule 1's headline, a number it had already told the reader not to quote.**
> There is no replacement percentage, deliberately: the base is single-digit and a ratio on it would
> be noise dressed as a measurement.

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
| Listable items this week the rule could possibly touch | **7 named of 95** (2026-07-30; was 1 of 128 under the pre-ruling count) |
| Overlappable emails in 14 days | **5** |
| Forum bell rows that have ever existed on live | **3** at first measurement; the type is days old |
| Overlap events observed | **0 — and UNTESTED, not confirmed (§1.2)** |
| Cost to build, **as at 2026-07-30** | One nullable column. **The cross-system identity contract is no longer part of the price** — see §4.1c |

**Building a live schema change to suppress an item class that produces single-digit rows a week is
still out of proportion** — and that, not structural impossibility, is the entire argument. The ~93%
of the recap that is connection traffic has no email path to overlap with, because connections live in
`profile_app` and nothing emails about them.

> ⚠️ **THE TWO CONDITIONS THAT WERE HERE HAVE BEEN REPLACED — see §4.1c for why.** They were
> *"Ian rules §9.1 option B"* and *"forum types exceed 10% of listable items"*. The first was
> answered the wrong way round (§9.1's outcome is no longer the discriminator, because the overlap
> arrives through `forum.reply_to_topic` regardless of it); the second was a volume threshold on a
> question that turns out to be a **dependency on another lane's ship date**. Watching a percentage
> would have let the defect ship while the percentage was still small.

**What to do in the meantime — nothing, and say so in the code.** A comment at the suppression site
recording that axis 2 was measured, costed and declined, with the trigger to revisit, is worth more
than a column nobody stamps.

### Rule 3 — previous digest: **IAN RULED THE FIXED 7-DAY WINDOW, 2026-07-28**

**This section is a record, not a proposal.** Two alternatives were built up and measured; Ian
declined both, having looked at the comparison frames. The measurements are kept because they are
the reason the question was asked and because §1.3's defect analysis is still true — the window
*does* drift, and a failed send *is* silent. He weighed that against predictability and chose
predictability. **Do not reopen it.**

| | Was proposed | Outcome |
|---|---|---|
| **3a** | window starts at the previous campaign's send time (global) | **Declined** |
| **3b** | window starts at the last digest THAT MEMBER RECEIVED | **Declined** — this lane's recommendation, and keeper's |
| **Ruled** | **a constant 7 days, for everyone, every send** | **Built** — `LG_WD_Recap_Source::WINDOW_DAYS` |

**What was built to make it a decision rather than a default.** The window had been a default
argument (`fetch( $ids, $days = 7 )`) threaded through three layers, because 3a and 3b both wanted a
value computed per send. Both declined, that parameter became flexibility nobody uses — and the kind
that quietly becomes a second window. It is gone; `fetch()` takes ids only. The endpoint keeps its
`days` parameter, because `/internal/recap` is a general read API and dev verification legitimately
drives it at other widths. **The digest's window has exactly one writer.**
Guarded by `dev/verify-window-fixed.php`.

**THE CONSEQUENCE HE ACCEPTED, recorded so it is designed around rather than rediscovered as a bug:
a member who misses one digest never hears about that week.** Items older than seven days never
return to the email. **Do not widen the window to compensate.**

> **What softened this, and it was not a compromise on the window.** Ruling D — fresh items NAMED,
> stale items COUNTED — means an unresolved item older than the window is no longer *lost*, it is
> *counted*. The named row is gone forever, exactly as ruled; the obligation is not. That is why the
> §1.3(b) casualties matter less than they did when this section recommended 3b, and it is a better
> outcome than 3b would have produced: 3b would have re-named old items, which is the nagging the
> section exists to prevent.

### Rule 4 — the digest is a TO-DO LIST (Ian, 2026-07-28)

**One question decides admission: does this WAIT ON THE MEMBER?** In: `connection_request`,
`forum.mention`, `forum.reply_to_topic`, `forum.reply_to_reply`, unread DMs. Out: `connection_accept`
and `reaction.on_post` — nothing is owed on either. Removed the arms, the dot colours, the
`reaction_what()` helper and the `reactions` bucket rather than leaving them unreachable behind the
allow-list. Guarded by `dev/verify-source-boundary.php`, which now asserts both removed types stay
out — they shipped until 07-28 and are the regression most likely to walk back in.

### Rule 5 — empty means send NO EMAIL AT ALL (Ian, 2026-07-28)

Not the digest minus the section. `recipients_with_something_waiting()` runs before
`$campaign->subscribe()`, so a member with nothing never gets a `CampaignEmail` row.

**It fails OPEN, and that is load-bearing.** `post()` returned `['recaps' => []]` on every failure
path — no secret, curl error, non-200, bad body — which is byte-for-byte what a healthy source
returns when nobody has anything. Harmless while an empty payload only cost a section; under this
ruling both mean "mail nobody" and one of them is an outage. The transport outcome is now recorded
(`source_answered()`) and the filter asks the transport rather than inferring from the shape of the
result. **A first version of that check tested `$payloads === []`, which can never fire** — `fetch()`
normalises every requested id to `[]` — so it failed CLOSED, silently. One unreachable endpoint
would have mailed nobody and looked exactly like a quiet week.

**Still flagged, not re-argued:** this suppresses the *whole* email, so most of the list receives
nothing, including Upcoming Events, the videos and loothprint, and the `sponsor-post` surface the
plugin supports. It changes what the weekly digest *is*, not just who it greets. Built as ruled;
keeper has the numbers.

### 🔴 5.1 THE FILTER HAS A VICTIM CLASS I NEVER MEASURED: 195 NON-MEMBERS. **MERGE BLOCKER.**

**Found 2026-07-30 while scoping the public signup page, which is how it surfaced at all: the signup
page recruits into a list this filter can never pass.**

**THE DIGEST'S REAL AUDIENCE IS 1,858, NOT 1,663.** The live campaign proves it — `wp_fc_campaign_emails`
for campaign **379** (*Weekly Digest — July 27, 2026*) holds **1,858 rows**, and the arithmetic is exact:

| | |
|---|---|
| List 3 *Weekly News Letter*, `subscribed` | **1,663** |
| List 7 *Non Member Weekly Email Subscriber*, **not also on list 3** | **+ 195** |
| **= campaign 379's actual recipients** | **1,858** ✓ |

> ⚠️ **I CORRECTED THIS FIGURE THE WRONG WAY EARLIER TODAY AND AM REVERTING MYSELF.** This document
> originally said 1,858; this morning I "fixed" it to 1,663 on the grounds that 1,663 was the measured
> subscriber count. **1,663 was the measurement of the wrong question** — it counts list 3 only, and
> the digest has always gone to list 3 **and list 7**. The original figure was right and my correction
> introduced the error. A number that agrees with one query is not thereby correct; the campaign's own
> recipient rows are the authority, and I did not consult them until today.

**WHY THIS BLOCKS THE MERGE.** Two independent faults, either sufficient on its own:

1. **The plugin never resolves list 7 at all.** `class-lg-wd-sender.php:107` resolves
   `getSubscriberIdsBySegmentSettings( $subscriber_settings )`, and `$subscriber_settings` is built at
   :49-54 from `$settings['fcrm_list_id']` — **one** list, and live's option is integer `3`. The live
   campaigns carry `[{list:3},{list:7}]`, so **that second list was added outside the plugin** (the
   FluentCRM UI). My filter therefore runs over list 3 only and the 195 are invisible to it.
2. **Even if they were resolved, Rule 5 drops every one of them — by design, in my own comment.**
   `class-lg-wd-recap-source.php:228-231`: *"A subscriber with no WP account has no bell rows and no
   DMs by definition. Nothing can be waiting on them, so they are dropped."* Measured on live: of the
   **204** `subscribed` list-7 people, **188 have no `wp_users` row at all.** They are non-members.
   **A non-member can never have a to-do item, so `recipients_with_something_waiting()` must return
   zero for them forever.** It is not a bug in the filter; it is the filter working.

**So shipping this branch as-is removes 195 real people from the weekly digest** — silently, with no
bounce and no error, and they are the only cohort who subscribed *purely for the content*.

**THE DECISION IS IAN'S AND IT IS NOT THE ONE HE HAS ALREADY RULED.** Rule 5 was ruled about
**members** with an empty to-do list. Nobody asked whether it should apply to **non-members who have
no to-do list by construction**. The two readings give opposite answers:

| Reading | Consequence |
|---|---|
| Rule 5 applies to everyone | The 195 never hear from us again. The public signup page becomes a form that subscribes people to silence. |
| **Rule 5 governs the RECAP SECTION's audience, not the digest's** | Non-members keep receiving Upcoming Events / videos / loothprint with **no recap section** — which is what they signed up for, and what they got on 27 July. |

**I recommend the second**, and it costs one condition: *suppress only subscribers who COULD have had
a recap* — i.e. apply the to-do test to bridged members, and leave a non-member's digest alone. That
also makes the signup page honest. **Not built; awaiting his word, because it narrows a ruling.**

### Rule 6 — fresh items NAMED, stale items COUNTED (Ian, 2026-07-28)

> "The fresh ones have a name and the stale ones have a collective number like *You have 6 connection
> requests*."

**It needs no new state, which is what makes it cheap — and it is why it could be built at all after
3b was declined.** The fixed window IS the fresh/stale line: inside it, new this week, name it;
outside it, it was in a previous email, count it. No per-item stamp and no per-member send record —
the send record *was* 3b.

**Resolved-state still decides when to STOP counting**, and that is the part to defend. `is_read`
cannot end a count: a member who looked at a connection request and did not answer it still owes an
answer. For `connection_request` the edge's own status is the authority. Forum types have no cheap
resolution signal — they live in another database — so `is_read` remains their only stop condition.
**That asymmetry is deliberate.**

**Why the edge-status stop condition is not optional:** `bottom-nav.js:1128` auto-fires
`markAllNotifsRead()` 700ms after the mobile notification sheet renders. Glancing at your
notifications on a phone marks every one of them read whether you acted or not. Desktop has no
equivalent (checked all 24 docroot `.js`). If `connection_request` were ever "simplified" back onto
`is_read`, a glance would silence a member's whole digest.

Guarded by `dev/verify-two-registers.php`, which also asserts an excluded type cannot enter through
the counted register.

### Rule 7 — verbosity guardrail: **ALREADY BUILT, TWICE. Build no third.**

Ian ruled `forum.reply_to_reply` IN "but we need to have some verbosity guardrails built in".
Measured before building, and the measurement says the constraint is already satisfied.

**My first measurement was the wrong unit and I nearly reported it.** I counted raw reply EVENTS from
the mirror over two years: 17.5% of member-weeks over 3, worst week 20. But the bell coalesces before
the digest ever sees it — `notify-bridge.php` sets the dedup key deliberately:

| type | `anchor_id` | effect |
|---|---|---|
| `reply_to_topic` | `0` | **one row per topic**, `actor_count` climbs |
| `reply_to_reply` | parent reply id | **one row per comment of yours** that got replies |
| `forum.mention` | the reply | one row each |

Ten replies on your discussion are already one bell row, not ten. Re-measured in **bell rows**, the
unit the digest actually renders:

| | raw events (wrong unit) | **bell rows** |
|---|---|---|
| avg per member-week | 2.3 | **1.53** |
| weeks over 3 rows | 274 (17.5%) | **80 (5.1%)** |
| weeks over the existing 8-row cap | — | **6 in two years (0.4%)** |
| worst week ever | 20 | **12** |

The second guardrail is `LG_WD_Recap::MAX_ROWS = 8` with an "N more waiting" overflow, so the worst
week in two years renders as 8 named rows plus one line.

**Collapse-by-thread was proposed and is rejected on the measurement.** On raw events it looked
decisive (busy weeks: 6.0 events across 1.7 topics). On bell rows it is weak — busy weeks are
**5.3 rows across 3.0 topics**, and after collapsing **27 of the 80 still exceed 3 rows** — because
the bridge already collapsed the within-thread case and what remains is genuinely cross-thread. It
would also merge "replied to your discussion" with "replied to your comment" into one vaguer line.
Information lost, ~2 rows saved, in 5% of member-weeks.

**The one component I could not measure, said plainly:** mentions. Each is its own bell row and
counting historical mentions needs content parsing for `@slug`. It is also the component most likely
to grow, because the autocomplete minter only shipped 2026-07-23. **If a wall ever appears, mentions
are where it comes from, not replies.**


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

## 3. The visible consequence — **published, side by side**

> **https://dev2.loothgroup.com/v2/tests/output/wd-recap/index.html** (dev-gated)

Three real live members, each panel the email the shipping renderer produces. The **"before" side is
the renderer as it actually stood on 2026-07-27**, loaded from git history (`f48561f`) in its own
process — not a redrawing of it, because both versions declare `LG_WD_Recap` and only one can exist
per process. Source in `dev/previs/`, frames in `dev/frames/`, extract `dev/extract-ruled-frames.sh`,
render `dev/render-ruled-frames.php`, published by `dev/publish-previs.sh`.

| Member | 27 July | **As ruled** | What it shows |
|---|---|---|---|
| Doug Proper (wp:197) | 9 rows (at the overflow) | **2 rows** | **Ruling B** — seven were reactions and acceptances; nothing was owed on any of them |
| Beau Hannam (wp:94) | 2 rows | **1 named + 1 counted** | **Ruling D** — "Brian Carnett wants to connect", then "You have 3 connection requests waiting" |
| Gerry Hayes (wp:4) | **no section at all** | **1 counted row** | **Rulings C+D** — nothing happened to him this week, but three people wait on him. Without the counted register he would now get *no email at all* |

Gerry is the case worth looking at twice: **181 of the 280 members mailed this week are in his
position**, where a counted line is the entire recap. The counted register is not a footnote on the
design, it is the majority of the recipient list.

> **THE PREVIOUS VERSION OF THIS SECTION HAS BEEN REPLACED, NOT AMENDED.** It showed Rule 1
> before/after and a Rule 3b comparison, all rendered with reactions and connection acceptances in
> the email. Every one of those was decided the other way on 07-28. A previs that argues for a
> declined design is worse than none, so the frames and their two render scripts were deleted rather
> than left beside the new ones.

**One open copy question, shown on the page rather than argued here.** When both registers are
present, "You have 3 connection requests waiting" sits directly under a named request, and the 3
does *not* include the named one. `"3 more connection requests"` removes the ambiguity in one word.
Ian's copy is unchanged in the build; the frame shows the real rendering so the call can be made by
looking. It only reads oddly for the 56 of 280 members who have both registers.

**Publishing took no serve window and dirtied nothing.** `location ^~ /v2/` aliases to
`/srv/lg-layout-v2/`, and `tests/output/` is gitignored there (`lg-layout-v2/.gitignore:5`), so the
publish adds no line to the serving checkout's porcelain — `publish-previs.sh` asserts that and
refuses to finish otherwise. **The gate was proven from the LAN IP, not loopback** (403 with no
cookie, 200 with the dev cookie), because these frames carry real members' names and
`geo $loothdev_src_local` authorizes `127.0.0.1` outright — a loopback 200 is the gate not running.

---

## 4. What this amends, said explicitly

**THREAD-FOLLOW-SPEC.md §3.7 is superseded**, flagged rather than quietly diverged from. §3.7
describes the recap as subscription-derived (`forums.forum_subscription` ⋈ replies), generic copy
("12 new replies across 3 discussions you follow"), a single *Open the Hub* link, with per-recipient
rendering listed as an open feasibility question.

| §3.7 (v1) | Built |
|---|---|
| Source: `forums.forum_subscription` ⋈ replies, via a new `bb-mirror-api/v0/follow-recap` | Source: the **bell** (`profile_app.notifications`) + unread DMs, via `internal-recap` |
| Scope: threads you follow | Scope: things that **wait on you** — `forum.followed_topic` **structurally excluded**, see §4.1 |
| One "Open the Hub" link | **Per-row deep links** (Ian's call from the frames) |
| Generic counts, no titles | **Discussion titles named** (Ian's call) |
| Per-recipient rendering: open question | **Proven** — one campaign, five real subscribers, five distinct emails |

### 4.1 **A claim I made here is now FALSE, and the thread-follow lane is building on it**

The previous revision of this section said:

> *"Nothing in §3.7's intent is lost — a member who follows a thread still gets a weekly summary the
> moment `forum.followed_topic` joins the allow-list, which is one line."*

**That was true when I wrote it and the to-do ruling of 2026-07-28 makes it false.** The allow-list
is no longer a list of types we have got round to adding; it is the answer to a question:

> **Does this WAIT ON THE MEMBER?**

**A reply in a thread you merely follow does not wait on you.** You are an observer, not the
addressee — nothing is owed, and nobody is blocked on your response. On Ian's own test
`forum.followed_topic` is not "not yet admitted", it is **structurally excluded**, in the same way
and for the same reason as `reaction.on_post`. Adding it would contradict the ruling, not extend it.

**So the "one line" is no longer available**, and I am flagging it loudly rather than letting that
lane discover it at merge:

| | Before 07-28 | **After the to-do ruling** |
|---|---|---|
| `forum.followed_topic` in the digest | one line away, whenever wanted | **needs Ian to rule that a followed-thread reply waits on you** — which his own test says it does not |
| §3.7's weekly follow summary | deferred | **has no home in this digest as ruled** |

That does not kill §3.7's intent, but it moves where it can live. A followed-thread summary is
*news*, and Ian has just ruled this email is not a news feed. It needs either its own vehicle or an
explicit ruling that carves out an exception. **That is a thread-follow decision to take to Ian, not
a weekly-recap one** — I am only saying that the door I previously said was open is shut.

### 4.1b ⚠️ **I RETRACT "RULE 2 IS RETIRED PERMANENTLY". IT WAS WRONG WHEN I WROTE IT.** (2026-07-30)

The previous revision of this section ended with a claim I was pleased with, and it does not survive
contact with the thread-follow spec at `origin/thread-follow` @7fed875:

> *"§9.1's option B ... can no longer produce a double-send through this digest, because the digest
> cannot carry followed-thread activity at all. Rule 2 is therefore **retired permanently, under
> every §9.1 outcome**. They do not need to tell me which way §9.1 goes."*

**The error is a substitution.** I proved something true about **`forum.followed_topic`** and then
stated it about **axis 2 as a whole**. The digest admits *three other* forum types, and every one of
them has a live per-event email path:

| Admitted type | Waits on you because | Per-event email that covers the SAME event |
|---|---|---|
| `forum.reply_to_topic` | a reply on a discussion **you authored** | BB "New reply" to topic subscribers |
| `forum.reply_to_reply` | a reply to **your** comment | same sender, same trigger |
| `forum.mention` | you were **addressed** | BB "mentioned you" (4 sends / 14d, §1.2) |

**`forum.followed_topic` is excluded. `forum.reply_to_topic` is ADMITTED — and it is the one that
overlaps.** My own §1.2 says so in its own table (`replied to` → *"Yes — when the topic author also
holds the subscription"*), so §4.1 contradicted §1.2 of this same document. Nobody had to move for me
to be wrong; I just did not read across my own sections.

**Verified in the real sender, not inferred.** `bb_send_forums_subscribed_reply`
(`bp-forums/classes/class-bp-forums-notification.php:989`) removes exactly one recipient:

```php
$author_id = ... ?: bbp_get_reply_author_id( $reply_id );
// Remove topic author from the users.      ← THE COMMENT IS WRONG
unset( $r['user_ids'][ array_search( $author_id, $r['user_ids'], true ) ] );
```

The comment says *topic* author; the value is `bbp_get_reply_author_id()` — the **replier**. **The
topic author is never excluded.** So a member with the ✉ bit on a topic **they authored** is emailed
for every reply, and `notify-bridge.php:212-223` ("*3. Reply to the topic → the topic author*") mints
`forum.reply_to_topic` for that same reply, which `INCLUDED_TYPES` **names** in the digest. One reply,
one person, both channels.

**And their design does not merely permit this — it hands the member a button for it.** §3.5's
notification-row ⋯ menu offers **"Email me"** on precisely `forum.reply_to_topic`,
`forum.reply_to_reply` and `forum.mention` rows. The affordance that creates the overlap sits *on the
digest's own admitted types*, one click from the rows the digest names.

**So the honest status of Rule 2 is unchanged in its recommendation and completely changed in its
reason:**

| | Previous revision | **Now** |
|---|---|---|
| Build it? | No | **No — unchanged** |
| Why not | *"structurally impossible; no configuration can double-send"* | **volume: 5 overlappable sends / 14 days, and the overlap rate is untested (§1.2)** |
| Status | *retired permanently* | **deferred, with a live trigger** |
| Does §9.1's outcome matter to me? | *"no — do not bother telling me"* | **yes, and so does §9.2 — see §4.1c** |

**A structural argument and a volume argument fail differently, and that is the whole cost of the
error.** A structural impossibility needs no monitoring. A volume judgement needs a trigger and a
re-measure, and I had deleted both — including out of the measurement script, which said *"no volume
at which that changes."* Restored: `dev/measure-suppression-axes.sh` §6.

### 4.1c §9.2's re-ruling makes Rule 2 *cheaper*, which is the other half of the reversal

Ian re-ruled §9.2 on 2026-07-29: **we ship our own sender and replace the BB reply path.** That
removes the larger of my two stated costs. §2/Rule 2 priced the build as *"a live schema change,
**plus** making BuddyBoss's own sender and our notify-bridge agree on event identity — they share no
key today"*. **Once the sender is ours, that second cost is gone**: our sender is driven by our own
store and can stamp the very notification row it just mailed. `notifications.emailed_at` stops being
a cross-system identity contract and becomes one write in code we own, next to the row that caused it.

**That does not make it worth building today** — 7 forum rows across 258 mailed members (§1.1), and
an untested overlap rate, do not justify a live schema change. It does mean the two conditions in
§2/Rule 2 should be replaced, because both were written against the wrong obstacle:

> **THE TRIGGER, restated.** Reopen Rule 2 when **thread-follow's ✉ toggle ships on the digest's
> admitted types** (§3.5's menu) **or our own sender ships** (§9.2) — whichever lands first, and
> *before* it lands, because after that the overlap is a member-visible defect rather than a
> measurement. It is no longer a volume threshold to watch; it is a **dated dependency on another
> lane**.

**What I owe the thread-follow lane, corrected.** I told them on 07-28 they need not tell me which
way §9.1 goes. **That was wrong and I have retracted it to them on the board.** They own the
affordance that creates the overlap; I own what is in the mail. Neither of us can size this alone.

### 4.1d 🔴 **THE TRIGGER HAS FIRED. Measured 2026-07-30, hours after I wrote §4.1c.**

§4.1c set the trigger as *"thread-follow's ✉ toggle shipping on the digest's admitted types —
whichever lands first, and **before** it lands."* **It has landed in `main`.** thread-follow merged at
`e84dae7`; `origin/main` is `9e0895f`.

**The affordance is real, and it is one click from a row this digest names:**

| Step | Where | Evidence in `origin/main` |
|---|---|---|
| 1 | Someone replies to a topic the member authored | `notify-bridge.php:212-223` mints `forum.reply_to_topic` — **unconditionally**, no opt-in |
| 2 | That row renders with a ⋯ button | `social-modals.js` `notifCanFollow()` gates on `ref.kind === 'topic'\|'reply'` — a `reply_to_topic` row's `target_kind` **is** `topic`, so it qualifies |
| 3 | The menu offers **"Email me about new replies"** | `social-modals.js:264` — `lbl = on ? 'Stop emails' : 'Email me about new replies'` |
| 4 | It writes the native BB subscription | `bb-mirror/api/v0/follow.php`, `channel:'email'` → `wp_bb_notifications_subscriptions` |
| 5 | Every later reply emails them **and** re-mints the bell row | BB's mailer excludes only the replier (§4.1b); the row fires regardless of both bits — the menu's own note says so |
| 6 | The digest **names** that row | `LG_WD_Recap::INCLUDED_TYPES['forum.reply_to_topic']` |

**So the double-send is now reachable in six steps with no configuration, on the digest's single
most-overlappable type — and the menu is offered on the very row the digest will duplicate.**

> **THE ONE PIECE OF LUCK, and it is the whole reason this is still a decision and not an incident:
> `follow.php` IS NOT ON LIVE.** Checked directly — `/srv/bb-mirror/api/v0/` on the live box holds
> ten endpoints and `follow.php` is not among them; `main`'s tip commit says in terms *"UNDEPLOYED —
> tonight's merges staged for live"*. **No member can create the overlap yet.** I said the trigger had
> to be caught before it landed; it landed in `main` but not on live, so the window is still open by
> exactly one deploy.

**WHAT I RECOMMEND NOW, and it is NOT the suppression.** The suppression still needs a floor and
still has no volume to justify it. But there is something cheaper that expires when that deploy
happens:

> **Stamp what was emailed, before the toggle reaches live. Do not build the suppression yet.**
>
> Without a stamp, the moment members can opt in we begin **destroying the only evidence that could
> ever size this**. `wp_fsmpt_email_logs` cannot stand in — 14-day retention, subject-string matching,
> and it records a *recipient address*, not *which item the mail was about* (§2/Rule 2). Every day
> after that deploy is a day of unmeasurable overlap, and §1.2's "untested, not zero" becomes
> permanent rather than temporary.

**And the hook to do it already exists, which is what makes this cheap rather than the schema-plus-
identity-contract I originally priced.** `bb_send_forums_subscribed_reply_email_notifications`
(`class-bp-forums-notification.php`, in the per-recipient loop) receives **`reply_id`**,
**`recipient_user_id`** and `type` on **every** subscribed-reply send. A pure observer on that filter
— returning `$send_mail` untouched — can stamp the matching notification row. **No change to
BuddyBoss's logic, no new sender, and it works whether or not §9.2's own-sender lands later.**

**What is still Ian's, because it is a live schema change:** one nullable
`notifications.emailed_at`. **I have not built it and am not going to without his word.** Boarded
with the measurement, the hook and the deploy window.

### 4.2 Two more rulings that reach into their spec

- **Empty means send NO EMAIL AT ALL.** A member whose only digest content would have been a
  followed-thread summary does not get a quieter email; they get none. Any §3.7 design that assumes
  "the digest goes out weekly and we add a section to it" no longer holds — **the digest now goes to
  280 of 1,858 recipients** (not 1,663 — see §5.1), and only to those with something owed.
- **`forum.reply_to_topic` and `forum.reply_to_reply` ARE admitted**, so a member still hears weekly
  about replies **to their own** topics and comments. The line between that and §3.7 is exactly the
  to-do test: replies *to you* wait on you; replies *near you* do not.

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
- **I do not know whether the June 1 send failure recurs on any schedule.** One event in 19 digests is
  not a rate. §1.3(b)'s argument rests on that failure being *correlated and silent*, not on frequency
  — and Ian ruled the fixed window anyway, having seen it.
- **The counted register's copy has never been rendered against a non-connection type.** §1.1 and the
  measurement script both check for this; as of 2026-07-30 it is still 100% `connection_request`, so
  *"You have 2 replies to your comments waiting"* remains unexercised against real data.
- **No serve window was held for this work** — it is measurement, design and gitignored previs only.
  The live reads were all through `live-ro`, read-only, on the LIVE box (not dev2; the two hold
  different data).

> **§5 WAS FOUR BULLETS LONGER AND EVERY ONE OF THEM WAS STALE (removed 2026-07-30).** They read
> *"Nothing here is built"*, *"Rule 3b is the one I am asking to build"*, and two caveats about Rule
> 3b's window query and its frames. **All four describe a document that no longer exists**: Ian
> declined 3a and 3b on 07-28, the fixed window was built, and §3 records that 3b's frames were
> *deleted* — so §5 was carrying caveats about artefacts that are gone, in the section whose entire
> job is to be the honest ledger. A stale "what I could not prove" is worse than none, because it is
> the section a reader trusts most. Kept as this note rather than silently dropped.
