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
- **"Your week" = UNREAD, last 7 days** — not a duplicate of what they already read
  in-app, and never resurfacing old news.
- Ian picked the recommended layout and wants it **personalised with the member's
  profile name** (the cleaned display name the `/u/` page shows — not the WP login,
  not the Patreon handle).
- Empty means absent: a member with nothing that week gets no section at all.

## 4. What is OPEN — and §9.1 is the keystone

**§9.1 — per-event vs digest.** Undecided, and it is not a style question: it decides
whether these are **one feature or two**.

- If per-thread ✉ email is its own channel, then a member with ✉ on a thread *and* a
  weekly recap covering discussion activity **receives the same reply twice** — once
  per-event, once in the digest. Something must then exclude what the other already
  sent.
- If per-thread ✉ email *is* a digest subscription, there is one channel, and the ✉
  toggle simply decides which discussions appear in that member's recap section.

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
| digest recap | partially built — recap source/renderer/sender classes on the branch. Owes the curl→nginx→FPM leg for `/internal/recap` and a real inbox test to Ian, both needing a serve window. |
| account prefs | Weekly Digest + Event Reminders **ship today** (bf9e3a1). |
| legacy BB email | still wired; the kill is an Ian gate, not a code change. |

Previs, both behind the dev gate:
`/v2/tests/output/threadfollow/index.html` · `/mockups/wd-recap/index.html`

## 6. What the next keeper should do, in order

1. **Get §9.1 gated.** Everything else is downstream of it, and it can be decided from
   the two frames above without writing anything.
2. **Then §9.2** — it is the only place where members are receiving mail nobody
   designed, and it has a frame with the member-visible consequence spelled out.
3. Only then spin a thread-follow **build** lane. The spec names the endpoint, the
   store and the surfaces, so it is a build brief, not a research one.
4. Keep the recap lane moving meanwhile — it is not blocked by any of the above,
   provided it states its source boundary explicitly so a §9.1 ruling is a scope edit
   rather than a re-architecture.
