# RECAP-SUPPRESSION-PROPOSAL

> **Status: PROPOSAL, measured, awaiting Ian.** Nothing built beyond axis 1, which was already
> shipped in the recap lane. **Lane:** weekly-digest-recap. **Date:** 2026-07-27.
> **All numbers are from LIVE**, read-only via `live-ro` (PG `profile_app` as `looth_ro`,
> MySQL `looth_import`) — not dev2, which holds different data.
> Cross-refs: THREAD-FOLLOW-SPEC.md @2cb9e3f (§1.3, §3.7, §6b, §8, §9.1, §9.2),
> WEEKLY-DIGEST-RECAP.md §9, EMAIL-AUDIT.md.

---

## 0. Ian's requirement

> Do not duplicate notifications in the email that have already been read — either read in one of
> those emails, or already read on the website.

Three axes, and they are not the same problem. **The measurements change the answer**, so they come
first.

---

## 1. What the stores actually say (LIVE, 2026-07-27)

### Axis 1 — read on the website

The bell carries read state and the recap already filters on it. At the shipping 7-day window:

| | Items | Members |
|---|---|---|
| In window | 101 | — |
| **Already read → suppressed** | **22 (21.8%)** | — |
| Still unread → listed | 79 | 53 |

**One in five items is already suppressed by the rule that shipped.** By type, reactions are the
most-read class (13 of 29 read); connection requests the least (2 of 42).

### Axis 2 — already sent in a per-event email

Per-event forum mail on live, 14 days (`wp_fsmpt_email_logs`, retention 14d):

| Class | Sends | Recipients | Overlaps the recap? |
|---|---|---|---|
| `New discussion:` (forum subscription) | 31 | 19 | **No** — the bell has no matching type, so the recap can never list it |
| `mentioned you` | 3 | 3 | **Yes** — same person, same event as `forum.mention` |
| `replied to` | 1 | 1 | **Yes** — when the topic author also holds the subscription |
| Reactions | 0 | — | No email path exists |

So of 35 per-event forum emails in 14 days, **4 are in classes that can overlap at all**, to 4
recipients.

I checked those 4 against the bell. **Three had no bell row; one had a row that was already read.**
That reads like "axis 2 is a non-problem" — and I nearly reported it that way. **It is not a safe
conclusion, and here is the confound:** on live, `forum.mention` and `forum.reply_to_topic` only
began writing on **2026-07-25**. Every one of those emails predates bell coverage. The comparable
window is **three days long and quiet**, which is not a measurement of steady state.

**So axis 2 has to be reasoned structurally, not measured yet:**

| Event | Email goes to | Bell row goes to | Overlap |
|---|---|---|---|
| Mention | the mentioned member | the mentioned member | **Guaranteed**, once both are live |
| Reply in a followed topic | topic subscribers | the topic **author** | **Whenever the author is also subscribed** — and per spec §8, 736 of 1,519 topic subscriptions (48%) are exactly that |
| New discussion in a followed forum | forum subscribers | *nobody* | Never |

**Conclusion: the overlap is real and will be routine, but it is not visible in today's data.**
That is an argument about *how* to suppress, not *whether* — see §2.

### Axis 3 — already sent in a previous digest

The feared failure is repetition. **The measured failure is the opposite.**

With a pure 7-day window and a weekly cadence, an item unread on day 8 falls out of the window
forever. It is shown once (fine) — **unless that send was skipped or failed, in which case it is
never shown at all.** Silent loss, not repetition.

And the backlog that would be exposed if we naively switched to a watermark ("everything unread
since your last successful digest"):

| | Items | Members |
|---|---|---|
| Listable in the 7-day window | 77 | ~53 |
| **Listable but aged out** | **347** | **271** (avg 2.8 each) |

Composition of that backlog: **262 connection requests, 81 connection accepts, 6 reactions.**
Almost entirely social, months old, and every one of it would land in a single send.

---

## 2. The proposal

### Rule 1 — read on the website (SHIPPED, keep)

Exclude `is_read` at the source. Already live in `Recap::forWpIds()`. **21.8% of items suppressed.**
No change requested.

Alongside it, and already shipped in the same place: a connection notification is suppressed once
the **edge itself** is no longer outstanding (`connections.status`), because the bell row stays
unread even after a member accepts on the profile. That caught 3 real rows on dev2.

### Rule 2 — already per-event emailed: **stamp it, do not scrape the log**

**Do not suppress by reading `wp_fsmpt_email_logs`.** It is the wrong instrument, for three reasons
that are all visible above: retention is 14 days (a monthly view is impossible), matching is by
**subject string** (`LIKE '%mentioned you%'`), and it records a recipient address, **not which item
the mail was about** — so it can never say "this notification was emailed", only "some mention mail
went to this person that week".

**Instead: one nullable column, `notifications.emailed_at`,** stamped by whatever sends the
per-event mail. The recap then excludes `emailed_at IS NOT NULL` in the same WHERE clause it
already uses for `is_read`. Exact, permanent, no retention limit, no string matching, and it
answers the right question at item granularity.

**Honest caveat:** the per-event sender today is BuddyBoss's own path (spec §1.3), which knows
nothing about our bell rows. Stamping means the notify-bridge and the BB sender have to agree on
identity for the same event. That is real work, and it is **only worth doing if Ian rules that both
channels stay on**. Which is why:

> **If §9.1 is ruled "digest owns per-reply follow-ups" (option A or C), rule 2 costs nothing and
> should not be built** — the overlap disappears by construction, because the two channels stop
> covering the same events. **Rule 2 is only needed under §9.1 option B** (an explicit ✉ toggle
> earns per-event mail *and* the digest keeps covering discussion activity).

That makes this proposal's second axis **contingent on a decision Ian already has in front of him**,
rather than a new question.

### Rule 3 — previous digest: a watermark **with a floor**

Replace the pure date window with a per-member watermark: *everything still outstanding since your
last **successful** digest send.* Not a date range — a stored per-member high-water mark, advanced
only when that member's send actually succeeded. This fixes the silent-loss failure a date window
has, and it cannot repeat.

**It must ship with a floor, or the first send is a mass re-notification: 347 items to 271
members.** The watermark starts at the day it ships and never reaches backwards. Members begin
clean; the historical backlog is simply never mailed. Given that backlog is 343 of 347 stale
connection rows from June and July, not mailing it is also the *right* product answer.

---

## 3. The visible consequence

Rule 1 rendered against two real live members, before and after
(`lg-weekly-digest/dev/frames/`, published when a serve window allows):

| Member | Items in window | Without suppression | **With suppression** |
|---|---|---|---|
| Doug Proper (wp:197) | 14 (6 read) | 9 rows (8 + "6 more waiting") | **8 rows** |
| **Ian Davlin (wp:1)** | 5 (**all** read) | 5 rows | **NO SECTION AT ALL** |

Ian's own live account is the clean demonstration: five things happened to him this week, he read
all five on the site, and the correct email says nothing.

---

## 4. What this amends, said explicitly

**THREAD-FOLLOW-SPEC.md §3.7 is superseded**, and I am flagging it rather than quietly diverging
from a document Ian has signed off. §3.7 describes the recap as: subscription-derived
(`forums.forum_subscription` ⋈ replies), generic copy ("12 new replies across 3 discussions you
follow"), a single *Open the Hub* link, per-recipient rendering listed as an open feasibility
question, with a generic-section fallback.

What was built and Ian gated on 2026-07-27 differs on every one of those points:

| §3.7 (v1) | Built |
|---|---|
| Source: `forums.forum_subscription` ⋈ replies, via a new `bb-mirror-api/v0/follow-recap` | Source: the **bell** (`profile_app.notifications`) + unread DMs, via `internal-recap` |
| Scope: threads you follow | Scope: things addressed to **you** — `INCLUDED_TYPES` allow-list; **`forum.followed_topic` deliberately excluded** |
| One "Open the Hub" link | **Per-row deep links** (Ian's call from the frames) |
| Generic counts, no titles | **Discussion titles named** (Ian's call) |
| Per-recipient rendering: open question | **Proven** — one campaign, five real subscribers, five distinct emails |

**Nothing in §3.7's intent is lost** — a member who follows a thread still gets a weekly summary
of it the moment `forum.followed_topic` is added to the allow-list, which is one line. The
difference is the spine: the bell, not a second subscription query.

**§6b's two asks of this lane, answered:**

1. **The recap mints no account-level toggle.** It is governed solely by the existing
   `lg-pref-weekly` / list-3 membership. A member who turns the weekly digest off renders no recap,
   automatically, because the campaign resolves recipients from that list.
2. **`#lg-email-prefs` stays append-only markup.** This lane did not touch
   `manage-subscription.php` at all — no config array, no REST-driven render. §6's fourth
   `.lg-pref-row` sibling plus one `wire()` call lands unchanged. **No merge conflict from this
   lane.**

I also adopt §6b's shared contract: *account = one master per email class; per-thread = membership
of that class; a recap is content inside a class, never a class of its own.*

---

## 5. What I could not close

- **Axis 2 is not measurable yet** — three days of comparable data. The structural argument stands;
  the number does not exist. Anyone who wants it should re-measure after a fortnight of both paths
  running.
- **Group subscriptions (12,948 rows / 1,853 users)** are untouched here, as in the spec. If group
  discussion mail overlaps the recap the same way, this proposal does not cover it.
- **`notifications.emailed_at` is a schema change** to a live store and is not written, proposed
  only.
