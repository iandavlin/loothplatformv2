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
> per-event emailed): still do not build** — measured, the surface does not exist; the overlap rate
> remains **untested, not zero**. Axis 3 is closed by ruling A.
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
> `dev/measure-suppression-axes.sh` prints all of it in one read-only run. Re-run
> 2026-07-29, one day later, for comparison: connection_request 94 named / 259 stale,
> forum items 3+2+1, **277 mailed (38 named-only, 181 counted-only, 58 both)**. The
> 7-day window slides daily, so the named counts move; what has NOT moved is the shape
> — **181 counted-only on both days, identical.** That is the load-bearing number,
> because it is the population that receives an email *only* because the counted
> register exists, and it is stable.

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

## 2. The rules, as ruled and built

### Rule 1 — read on the website: **SHIPPED, and NARROWED by the to-do ruling**

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

**Still flagged, not re-argued:** this suppresses the *whole* email, so **1,383 of 1,663 subscribed
members receive nothing**, including Upcoming Events, the videos and loothprint, and the
`sponsor-post` surface the plugin supports. It changes what the weekly digest *is*, not just who it
greets. Built as ruled; keeper has the numbers.

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

**One genuine simplification falls out of it, in their favour.** §9.1's option B — an explicit ✉
toggle earning per-event mail while the digest also covers the same events — **can no longer produce
a double-send through this digest**, because the digest cannot carry followed-thread activity at all.
Rule 2 (suppress-what-was-already-emailed) is therefore **retired permanently, under every §9.1
outcome**, not just under A and C. The seam I posted to them on 07-28 is now simpler than I
described it: they do not need to tell me which way §9.1 goes.

### 4.2 Two more rulings that reach into their spec

- **Empty means send NO EMAIL AT ALL.** A member whose only digest content would have been a
  followed-thread summary does not get a quieter email; they get none. Any §3.7 design that assumes
  "the digest goes out weekly and we add a section to it" no longer holds — **the digest now goes to
  280 of 1,663 members**, and only to those with something owed.
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
- **Nothing here is built.** Rule 1 was already shipped; Rules 2 and 3 are written, costed and
  unwritten in code. **Rule 3b is the one I am asking to build.**
- **Rule 3b's window query is demonstrated, not deployed.** I ran it against live and it returns the
  correct reach-back for the six real casualties and the correct no-reach-back for healthy controls
  (§ Rule 3b). That proves the *data supports it*. It has not been wired into
  `Recap_Source::payload_for()`, and no send has been rendered through it.
- **Rule 3b's frames render a simulated failure, not an observed one.** Grace's rows are real and her
  two-week spread is real; her sends did not fail. The frames show the *shape* of the loss using a
  member who has the right data, not a member it happened to. The six it did happen to are named in
  §1.3(b) and their loss is eight weeks old, so there is nothing left to render for them.
- **I do not know whether the June 1 failure recurs on any schedule.** One event in 19 digests is not
  a rate. The argument for 3b rests on the failure being *correlated and silent*, not on frequency.
- **No serve window was held for this work** — it is measurement and design only. The live reads were
  all through `live-ro`, read-only, on the LIVE box (not dev2; the two hold different data).
