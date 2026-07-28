# NOTIFICATIONS + EMAIL — one page for the whole system

*Keeper wrap-up, 2026-07-27, written as the keeper role moved to dev2. This ties
together four documents and two lanes that were each only half the picture:
`NOTIFICATIONS-AUDIT.md` (what exists), `THREAD-FOLLOW-SPEC.md` (per-discussion
opt-in), `WEEKLY-DIGEST-RECAP.md` (the per-member email section), and the account
email prefs that already ship. If you are picking this up, read this first and then
go to whichever of those you need.*

## 1. The strategy, in Ian's words (2026-07-25)

- **No daily or per-event notification email, ever.** Real time is the **bell only**.
- **The email channel is the weekly digest**, carrying a dynamic per-member section.
- That section is **counts and senders with deep links, never content**. This is a
  privacy ruling, not a style one.
- **BuddyBoss subscription emails stay permanently off.**

## 2. What actually exists today (measured, see NOTIFICATIONS-AUDIT)

Three disconnected worlds, and the one members see is not the one that fires:

1. **profile-app bell** — PG `profile_app.notifications`, in-app only, and it knows
   about **connection requests and accepts only**. Its `message` type is never
   written. DMs badge but do not notify.
2. **Legacy BuddyBoss** — `wp_bp_notifications`, 66k rows, still being written by the
   Hub reply path, read by **no** current UI, and its digest cron would send real mail
   nobody designed for the Hub era.
3. **Transactional/membership mail** — poller, event reminders, weekly digest.

**The gap in one line:** the Hub, where the community actually lives, has no
notification surface of its own. Replies, mentions and reactions notify nobody
useful. That gap is what thread-follow and the digest recap between them close.

## 3. What is DECIDED (do not relitigate)

**Thread-follow** (`THREAD-FOLLOW-SPEC.md`, branch `threadfollow-spec`):
- **Opt-in only.** Nothing auto-subscribes — not authoring, not replying, not being
  @mentioned.
- **Two independent toggles per discussion**: 🔔 notifications, ✉ emails. Both default
  OFF.
- **Card control = two icons** (the single-expanding-control fallback is deleted).
- Desktop: beside the size control in the modal header.
- **Mobile card: right end of `.lg-card-actions`, icon-only, 44px targets** (Ian
  2026-07-27). The point of a card control is opting in *without* opening the thread,
  so it cannot live only inside the thread.
- **Mobile sheet: circular 34px buttons between `.lrs-t` and `.lrs-x`** (Ian
  2026-07-27), so the order reads title → state → dismiss, matching desktop. The
  beneath-the-header row is rejected and deleted.
- Unset from the thread, the notif-row ⋯, or an unsubscribe link in the email.

**Weekly digest recap** (`WEEKLY-DIGEST-RECAP.md`, branch `weekly-digest-recap`):
- **Per-recipient via a FluentCRM smart code** — the digest sends as ONE campaign with
  a single `email_body`, so per-member content cannot be baked at compose time. The
  template already proves the substitution path with `##crm.unsubscribe_url##`.
- **THE DIGEST IS A TO-DO LIST, NOT A NEWS FEED (Ian, 2026-07-28).** One question
  admits a type: *does this wait on the member?* In: connection requests, mentions,
  replies to your topics and your comments, unread DMs. **Out: connection acceptances
  and reactions** — nothing is owed on either, and both were deleted rather than
  disabled.
- **TWO REGISTERS.** New this week is **named**; still-unresolved from before is
  **counted** — "You have 3 connection requests waiting". The fixed 7-day window is
  the fresh/stale line, so this needs no new state.
- **Outstanding is not the same as unread.** A connection request is suppressed by the
  edge's own status, never by `is_read` — a member who glanced at the bell has not
  answered anybody, and the mobile sheet auto-marks everything read 700ms after it
  opens (`bottom-nav.js:1128`).
- Ian picked the recommended layout and wants it **personalised with the member's
  profile name** (the cleaned display name the `/u/` page shows — not the WP login,
  not the Patreon handle).
- **Empty means SEND NOTHING (Ian, 2026-07-28)** — not the digest minus the section.
  A member with nothing waiting gets no email at all. Measured on live: **280 of 1,663
  subscribed members** would be mailed, and **181 of those only because of a counted
  line**.

## 4. What is OPEN — **§9.1 IS NO LONGER THE KEYSTONE (2026-07-28)**

> **This section said "everything else is downstream of §9.1". That is no longer true,
> and the correction makes §9.1 SMALLER, not more urgent.**
>
> The double-send it worried about **cannot happen under any §9.1 outcome.** Ian's
> to-do ruling excludes `forum.followed_topic` from the digest *on its merits* — a
> reply in a thread you merely follow does not wait on you; you are an observer, not
> the addressee. So the digest cannot carry followed-thread activity at all, whatever
> per-event email does. There is nothing to de-duplicate against and no volume at
> which that changes. See RECAP-SUPPRESSION-PROPOSAL.md §4.1.
>
> **§9.1 can now be ruled on its own merits, with no weekly-digest consequence to
> weigh.** It is still worth ruling — it decides what per-event email does — it is
> simply no longer a precondition for anything in the digest.

**§9.1 — per-event vs digest**, kept for the record of what it was *thought* to decide:

- ~~If per-thread ✉ email is its own channel, a member with ✉ on a thread *and* a
  weekly recap covering discussion activity receives the same reply twice.~~
  **Cannot occur — the digest never covers followed-thread activity.**
- If per-thread ✉ email *is* a digest subscription, there is one channel — but note
  the digest would still not carry it, so this shape needs a separate ruling that a
  followed-thread reply waits on the member.

Note the mail Ian liked was the **forum-subscription "new discussion"** path (46 subs,
fires when someone starts a thread), not the **topic-subscription "new reply"** path
(1,519 subs, fires on every reply). One switch was covering two different things.

**§9.2 — the 1,519 topic + 46 forum subscriptions.** These are emailing real members
**today**. 72% were created by involvement rather than deliberate choice, 93% are
dormant, ~49 members are actually affected. The lane's recommendation is to change no
data and instead make every one of them visible and one tap from off; the alternative
also retires the dormant ones. This blocks **shipping**, not building: the moment a
member sees an ✉ switch reading "off" while mail keeps arriving, the feature has lied.

**§6 — account coexistence.** Proposed rule: the account page is a master switch **per
kind** of email; per-discussion decides which discussions are in that kind; master OFF
means nothing is sent whatever the individual discussions say. Today's account card
(`manage-subscription.php`, shipped bf9e3a1) has two switches, Weekly Digest and Event
Reminders, both FluentCRM-list backed.

**There is NO code conflict between the two lanes** — checked 2026-07-27:
`weekly-digest-recap` touches only `lg-weekly-digest/*` and the atlas, and §6 is a
drawn frame. Earlier keeper notes claiming they were "designing the same page" were
wrong. The overlap is content (double-reporting), not markup.

## 5. What is BUILT

| | state |
|---|---|
| thread-follow | **spec + mocks only.** No `.php`, no `.js`, no `follow.php` on any serve. |
| digest recap | **built to all four 07-28 rulings**, suite 4/4 (`verify-source-boundary`, `verify-window-fixed`, `verify-empty-means-no-send`, `verify-two-registers`). The curl→nginx→FPM leg and the real inbox test were both closed in the 07-27 serve window. **Owes only a real send** — nothing has run end to end through the recipient filter and the sender's no-campaign-at-all early return is unexercised. That is Ian's to run. |
| account prefs | Weekly Digest + Event Reminders **ship today** (bf9e3a1). |
| legacy BB email | still wired; the kill is an Ian gate, not a code change. |

Previs, both behind the dev gate:
`/v2/tests/output/threadfollow/index.html` · `/v2/tests/output/wd-recap/index.html`
(the recap previs moved off `/mockups/` — that path wrote into dev2's docroot, which
is pull-only; `/v2/tests/output/` is gitignored inside the serving checkout and needs
no serve window.)

## 6. What the next keeper should do, in order

1. ~~**Get §9.1 gated.** Everything else is downstream of it.~~ **NO LONGER THE
   BLOCKER — see §4.** Rule it when convenient, on its own merits. Nothing in the
   digest waits on it.
2. **Then §9.2** — it is the only place where members are receiving mail nobody
   designed, and it has a frame with the member-visible consequence spelled out.
3. Only then spin a thread-follow **build** lane. The spec names the endpoint, the
   store and the surfaces, so it is a build brief, not a research one.
4. The recap lane is **built to Ian's four rulings and blocked only on a real send**,
   which is his. Its source boundary is stated explicitly in WEEKLY-DIGEST-RECAP.md
   §6.1 — but note that boundary is now a TEST ("does this wait on the member?") and
   not a list, so admitting a new type is a ruling, not a scope edit.
