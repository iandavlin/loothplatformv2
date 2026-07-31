# WEEKLY-DIGEST-RECAP

> **Status:** BUILT and FULLY VERIFIED on dev2, including the nginx leg and one real inbox test,
> both done inside a keeper-granted serve window on 2026-07-27 (§7.1) and the serve restored
> byte-for-byte afterwards. **Design DECIDED by Ian 2026-07-27 (§4) — closed, not an open
> question.** Nothing is deployed; the digest remains double-off and campaigns stay draft.
> **Lane:** weekly-digest-recap (branch `weekly-digest-recap`). **Box:** dev2. **Date:** 2026-07-27.
> Cross-refs: NOTIFICATIONS-AUDIT.md (**stale in one important way — see §2**),
> THREAD-FOLLOW-SPEC.md §2.6b (**overlaps this lane — see §6**), OPERATOR.md, SYSTEM-MAP.md.
>
> **Preview:** `https://dev2.loothgroup.com/mockups/wd-recap/` (dev gate; claimed browser).

---

## 0. What this is

The dynamic per-member section of the weekly digest — a personal recap of what happened to
*that member* this week, inside the digest they already get.

Ian's ruling (2026-07-25), which is as much about what we do NOT send:

- **No daily or per-event notification email, ever.** Real-time is the bell only.
- The email channel is **this one section**, in the weekly digest.
- **Counts and senders with deep links — never content.** "3 replies on your thread" and who,
  not the reply text. This is a privacy ruling, not a style one.
- BuddyBoss subscription emails stay permanently off. Nothing here is fed from them.

And (2026-07-27, refined): **the section is WHAT YOU MISSED, not "your week."** Anything already
cleared must not appear — a notification looked at, a connection made or actioned, a message read,
or an item already clicked through from a previous digest. Only fresh, still-outstanding things go
out. Three of those four are enforced today from columns that already exist (§9.1). **The fourth
is not built and is waiting on an Ian ruling (§9.2).**

---

## 1. Shape

```
  profile_app (PG)                  loopback, shared secret            WP / looth-dev
  ┌───────────────────┐             ┌──────────────────────┐          ┌────────────────────────┐
  │ notifications     │  Recap::    │ POST /profile-api/   │  curl    │ LG_WD_Recap_Source     │
  │  (unread, ≤7d)    │─forWpIds()─▶│   v0/internal/recap  │◀─────────│  ├ smart code callback │
  │ message_recipients│             │  {wp_user_ids[],days}│          │  ├ hydrate titles      │
  │  (unread_count)   │             └──────────────────────┘          │  │   (get_the_title)   │
  └───────────────────┘                                               │  └ LG_WD_Recap::render │
                                                                      └───────────┬────────────┘
                                                                                  │
                       FluentCRM campaign (ONE body, design_template: raw_html)   │
                       …<p>intro</p> ##lg_recap.section## <curated sections>… ◀───┘
                                    ▲ substituted PER RECIPIENT at send
```

| Piece | File | Role |
|---|---|---|
| Query | `profile-app/src/Recap.php` | unread + window, batched by wp id. Owns the "your week" definition. |
| Endpoint | `profile-app/api/v0/internal-recap.php` | loopback + shared secret, read-only. Read twin of `internal-notify.php`. |
| Route | `platform/nginx/strangler-profile-app.conf` | `^/profile-api/v0/internal/recap/?$` — **written, not yet live (§7)** |
| Fetch + seam | `lg-weekly-digest/includes/class-lg-wd-recap-source.php` | smart code registration, loopback client, title hydration |
| Render | `lg-weekly-digest/includes/class-lg-wd-recap.php` | rows → HTML. Enforces "never content" and "empty means absent". |
| Token | `lg-weekly-digest/templates/email.php` | emits `##lg_recap.section##` after the intro rule |
| Mode plumbing | `class-lg-wd-email-builder.php`, `class-lg-wd-sender.php` | resolve/strip the token on every non-campaign path |

---

## 2. What the notification store does and does not know

**Read this before designing anything that reads the bell. NOTIFICATIONS-AUDIT.md is a month
stale in exactly the way that matters, and the next person will otherwise assume less.**

That audit (2026-06-27) records `profile_app.notifications` as knowing about **connection
request/accept only** — its `message` type built but never written, Hub replies/mentions/reactions
notifying nobody (audit rows 6 and 7). A recap built on that description would say "you have 1
connection request" and nothing else.

**Since 2026-07-12 that is no longer true.** The notifications lane shipped `lg-shared/notify-bridge.php`
and `Notifications::pushHubEvent()`, and the store now also carries:

| Type | Written by | Carries |
|---|---|---|
| `forum.reply_to_topic` | notify-bridge, from bb-mirror's reply path | actor, `actor_count`, topic id, deep link |
| `forum.reply_to_reply` | ditto | + `anchor_id` = the reply |
| `forum.mention` | ditto (`lg_notify_find_mentions`) | + `anchor_id` |
| `reaction.on_post` | notify-bridge, from archive-poc's `card-react.php` | `target_kind` ∈ topic/reply/card |

Three properties of those rows are what made this lane cheap:

1. **`target_url` is already the canonical deep link.** The bridge stamps
   `/hub/?topic=<forum>%2F<topic>[&reply=<id>]` — the query-param modal form, not a legacy
   `/groups/` permalink — at the moment the event fires. **We reuse it. We did not build a
   second link system**, and we did not need a query layer over `forums.*` / `discovery.*` to
   reconstruct links.
2. **`actor_count` is already coalesced.** `pushHubEvent`'s upsert merges a second actor on the
   same target into one row and increments the count, scoped to UNREAD rows. "Ian Davlin and 1
   other" is read straight off the row; the recap does no batching of its own.
3. **`is_read` is real and maintained** by the bell UI, which is what makes "unread" a
   meaningful filter rather than a synonym for "recent".

**What it still does NOT know:**

- **DMs.** profile-app deliberately does not ring the bell for a new message ("no double-notify"),
  and the `message` notification type remains dead code. Unread DMs are therefore read separately
  from `message_recipients.unread_count`, and merged in by the renderer. This is a permanent
  split, not a gap to be closed by this lane.
- **Anything before 2026-07-12.** The bridge does not backfill. The first digests will be thinner
  than steady state, and on dev2 specifically there is barely any material at all (§5).
- **Whether a thread is public.** Rows carry no visibility flag. Ian ruled that titles are named
  (§4), which is safe for public forums; if a private or tier-gated forum ever needs excluding,
  the gate is `forums.forum.visibility` and it is a small addition to `Recap.php`, not a redesign.

**What WordPress cannot do:** read any of it. The WP pool runs as `looth-dev`, which holds **zero**
grants on the `profile_app` database (`select count(*) from information_schema.role_table_grants
where grantee='looth-dev'` → 0). That is precisely why notify-bridge POSTs over loopback instead
of writing PG, and it is why the read side is an endpoint and not a SELECT. Granting `looth-dev`
SELECT was considered and rejected: one lane should not punch a hole in the store-ownership
boundary the bridge deliberately maintains, to save a loopback call on a weekly cron.

---

## 3. The per-user seam (Ian's decision 1) — surveyed, then proven

The brief said: survey FluentCRM's smart-code API on the box first; if it cannot do this with a
per-subscriber callback, **stop and report** rather than inventing a second sender. It can.

The digest sends as ONE campaign with `design_template: raw_html` and a single `email_body`
(`LG_WD_Sender_FluentCRM::send`), so per-user content cannot be baked at compose time. Three
findings, in the order they had to be checked:

1. **A per-subscriber callback exists.** `FluentCrmApi('extender')->addSmartCode($key, $title,
   $codes, $callback)` (`Api/Classes/Extender.php:84`) registers
   `fluent_crm/smartcode_group_callback_<key>`, which `ShortcodeParser` dispatches to for any
   unknown group (`Parser/ShortcodeParser.php:160`) with `($code, $valueKey, $default, $subscriber)`.
2. **It runs per recipient.** `CampaignEmail::getEmailBody()` applies
   `fluent_crm/parse_campaign_email_text` to every CampaignEmail row
   (`Models/CampaignEmail.php:277`), and that filter is `Parser::parse($text, $subscriber)`
   (`Hooks/filters.php:27`).
3. **`raw_html` bypasses the per-campaign body cache — and this was the real risk.** For block
   templates, `getParsedEmailBody()` memoises ONE parsed body per campaign in a static
   (`CampaignEmail.php:405-415`), which would have baked one member's recap into everyone's
   email. For `raw_html` the code takes `$this->campaign->email_body` fresh per row
   (`:259-261`) and never consults the static. **The digest sends raw_html, so we are on the
   safe side of that branch — but anyone who later "improves" the digest onto a block template
   silently breaks per-user rendering.** That trap is recorded in the class docblock.

**Known limit, cosmetic:** FluentCRM caches the click-tracking URL map per campaign when the body
has no conditionals (`getCampaignUrls`, `:455-459`), so per-recipient recap links are **not
click-tracked** — the map is built from whichever recipient rendered first. The links themselves
are correct and work for everyone; only the click metric is missing. Not worth defeating the
cache for.

**Token discipline.** `##lg_recap.section##` reaches FluentCRM only on the campaign path.
`LG_WD_Email_Builder::build()` takes a mode (`token` | `render` | `strip`) and **defaults to
`strip`**, so a forgotten mode fails safe instead of putting literal `##lg_recap.section##` in
front of a member. Preview renders the previewing admin's own recap; a test send renders the test
recipient's if that address is a member; the wp_mail fallback resolves for its single recipient.

---

## 4. The design — DECIDED, closed

Ian saw the frames and picked the recommended design on **2026-07-27**. He asked for the
alternatives to be **dropped rather than left as options**, so they are gone from the renderer:
there is no layout flag, no filter, and nothing to flip. They are recorded here only so nobody
proposes them again as if they were new.

**What ships:**

| | Decision |
|---|---|
| Discussion titles | **Named.** "2 new replies on your discussion — *"Suggest an alternative to concave fret file"* — Doug Proper and 1 other." A public forum title is already public — it is on the Hub, in search, and in the digest's own "From the Forum" section. It is not message content, which is what the ruling forbids. |
| Links | **Per row.** Each row links to its own target, taken from the bridge's stamped `target_url`. |
| Placement | **Top**, directly under the intro rule, above the curated content. |
| Greeting | **The member's profile name, first token only** — see §4.1. |
| Reactions | ~~**In**, batched~~ — **SUPERSEDED 2026-07-28.** Ian's to-do ruling removed reactions entirely: nothing is owed on a reaction. The 07-27 decision about SHAPE stands; its decision about this type's ADMISSION does not. |
| Length | **Caps at 8 rows**, tail rolled into "N more updates waiting for you" — never truncated silently. |

**Rejected, do not re-propose:** counts-and-senders without titles; inert rows behind a single
"Open the Hub" button (this was THREAD-FOLLOW-SPEC.md §2.6b's proposal — see §6); the section
placed below the curated content.

### 4.2 TWO REGISTERS — fresh items NAMED, stale items COUNTED (Ian, 2026-07-28)

> "If it's fired once and not been resolved, leave it out of the next email. Or perhaps we throw a
> number at it like the fresh ones have a name and the stale ones have a collective number like
> **You have 6 connection requests**."

The section now has two voices, in this order:

| Register | What it draws | Example |
|---|---|---|
| **Named** | everything new **this week** | *"Brian Carnett wants to connect"* |
| **Counted** | everything still unresolved from **before** this week, one line per type | *"You have 3 connection requests waiting"* |

**It solves both failure modes at once, which is why it beats either rule on its own.** Re-naming
the same item every week is nagging; dropping it entirely loses the one thing that actually needs
the member. A count nags nobody and loses nothing.

**IT NEEDS NO NEW STATE — the fixed 7-day window IS the fresh/stale line.** Inside the window an item
is new, so it gets named; outside it, it was in a previous email, so it gets counted. No per-item
`named_at` stamp and no per-member send record. That last point matters: the send record *was*
Rule 3b in RECAP-SUPPRESSION-PROPOSAL.md, and Ian declined it, so a design needing it would have been
dead on arrival.

**Resolved-state decides when to STOP counting**, and only that — see §9.1's boxed note for why
`is_read` cannot do that job for a connection request, and why that is load-bearing rather than
fastidious.

**Copy is Ian's and is not inflated.** One short line, no explanation. Counted rows carry **no url**:
a count is not a thing you can click, and deep-linking "3 connection requests" to any one of them
would be a lie about which. Note the **singular is the common case** — measured on live, 224 of the
237 members with anything stale have exactly one item, and nobody has more than three, so his example
of 6 is above today's ceiling.

**One open copy question, drawn rather than argued** (previs §2b): when both registers are present
the counted line sits under a named row and its number does *not* include that row. Options are
A (as built), B ("3 *more*"), or C — add "more" only when a named row of that type sits above it,
which gives the 224 panel A and the 56 panel B for two lines of renderer. **A ships until Ian says
otherwise.**

### 4.1 The greeting

"Here's your week, Dave." — and with no name on file, "Here's your week."

**This is not a new convention.** The platform already greets members on the front feed
(`archive-poc/web/index.php:504-518`, "Welcome back, <first>."), and that code carries a rationale
this section inherits rather than re-decides:

- **First whitespace token only.** The legacy name-field system backfilled BUSINESS names into
  profile `display_name` for many members — "Buck Van Laarhoven VL Guitar Repair", or the longest
  in the store at 71 characters, `Dave Staudte (rhymms with "Howdy") NB Guitar Repair (New
  Braunfels, TX)`. The first word is the only token reliably the *person* and not the business.
  Greeting someone with 71 characters of shop name is exactly what the convention prevents. Long
  names still appear in the ROWS, where they are actor names and unavoidable; the deck's
  long-name frame is the one to check for wrapping.
- **No name -> no name.** The front feed drops to a name-less greeting rather than substituting
  anything, and so does this. **Never `user_login`, never `user_nicename`, never a Patreon
  handle:** a member who set their own name must see that name, and a member who set none must not
  be shown a machine one. (Guard, not a common path — of 1,851 live profiles, 0 have an empty
  `display_name` and 0 have a bare Patreon handle in it.)

**Source:** `profile_app.users.display_name`, carried on the recap payload by `Recap::forWpIds()`.
That is the same row `/u/` renders — the profile's own name, not WP's copy of it.

**Entity damage is decoded before render.** 20 live `display_name`s carry HTML entities from the
legacy import — "Georgios Gerogiannis Rupicapra, Wood &amp; Voltage", "Dan Wolf &amp; Steve Baker
Chicago Fret Works Guitar &amp; Amp Repair". `clean_name()` decodes once (the store is
singly-encoded — checked: 0 rows double-encoded) and `esc_html()` re-encodes correctly for the
email. Greeting someone "Wood &amp; Voltage" would be worse than not greeting them.

**Markup note:** the greeting is a `<div>`, not a `<p>`, on purpose. The section's invariant is
that it contains NO prose markup at all (`<p>`, `<br>`, `<blockquote>`, `<img>`) — that is what
pasted content would drag in — and the verify asserts exactly that with no carve-outs. A carve-out
for "our own paragraph" is where a future leak would hide.

## 5. Deep links, and the one that does not exist

- **Hub thread** — `/hub/?topic=<forum>%2F<topic>[&reply=<id>]`, taken verbatim from the row's
  `target_url`. Canonical query-param modal form; not `/hub/<forum>/<topic>/`, not a legacy
  `/groups/` or `/members/` permalink.
- **Card / post** — the row's stored path (e.g. `/post-type-videos/<slug>/`).
- **Profile** — `/u/<slug>`, from `users.slug` (PG is the authority; not `user_nicename`).
- Every link is absolutised at render (`home_url()`) and UTM-tagged through
  `LG_WD_Email_Builder::add_utm()`, so recap clicks are attributable like every other digest link.

**There is no URL that opens a DM thread.** The messages surface is a modal opened from the header
button (`data-lg-msg-link`, `site-header.php:478`); `social-modals.js` reads no query param and no
hash — verified, not assumed. So a DM row points at the **sender's profile** (`/u/<slug>`), which
is a real page one tap from "Message"; a group thread has no single profile to point at and
renders unlinked rather than arbitrarily picking a peer.

**The fix, for whoever wants it:** a `?dm=<thread-uuid>` trigger in `social-modals.js` that calls
the existing `openThread()`. The pattern is already established on the box — `bottom-nav.js`
handles `#search` (:854) and `?compose=1` (:1353). It is a Hub change, not a digest change, so
this lane flagged it rather than doing it. When it lands, `rows_from_dms()` swaps one line.

---

## 6. Relationship to the thread-follow lane

THREAD-FOLLOW-SPEC.md (`threadfollow-spec` @c7b8099) §2.6b designs a digest recap too. The lanes
must not ship two of them. How they line up:

| | thread-follow §2.6b | this lane |
|---|---|---|
| Scope | replies in threads you **follow** | **everything unread**: replies, mentions, reactions, DMs, connections |
| Source | `forums.forum_subscription` ⋈ replies, via a new `bb-mirror-api/v0/follow-recap` | `profile_app.notifications` + `message_recipients`, via `internal-recap` |
| Links | one "Open the Hub" | **DECIDED: per-row deep links** (§4) |
| Titles | open question (§6 Q3) | **DECIDED: named** (§4) — the same question, now answered |
| Per-user mechanism | "**Feasibility = dev2 verify**" | **answered: it works** (§3) |

**The unifying fact:** thread-follow's proposed `forum.followed_topic` is just another
`Notifications::pushHubEvent()` type, landing in the same table this recap already reads. So the
recommendation stands: thread-follow should drop its own `follow-recap` endpoint and let the bell
be the one spine, which is what NOTIFICATIONS-AUDIT.md §4.3 argues for too.

### 6a. Cadence — answered for thread-follow, 2026-07-30 (their deploy was gated on it)

They asked three questions on the board. Recorded here because a board post scrolls away and this is
a cross-lane contract.

**1. Is there a scheduler to write a cadence preference into? No** — and their instinct not to reuse
the newsletter for per-thread reply batching is right. But their conclusion that Hourly/Daily/Weekly
are *all* new infrastructure is too pessimistic, because of the next point.

**2. A DIGEST DOES NOT NEED A QUEUE.** Their model of this plugin as an editorial broadcast resolving
its audience by CRM tag is out of date: `class-lg-wd-sender.php:162` calls
`recipients_with_something_waiting()`, which computes each subscriber's pending items, and the body is
`raw_html` + smart codes so every recipient gets their own substitution. It does this with **no queue,
no per-member send record and no table of its own** (there is no `CREATE TABLE` in the plugin), because:

> **WINDOW == CADENCE.** The window is a fixed 7 days (`WINDOW_DAYS`, `class-lg-wd-recap-source.php:91`)
> and the send is weekly, so *"what is in the window"* **is** *"what I have not already told you"*.
> No state has to exist to know that.

A digest needs no queue when its events are already durably stored with timestamps. Their followed-thread
rows land in that same store, so the **read side already exists and is proven at scale**; what is
genuinely new for them is a second send *trigger*.

**3. PER-MEMBER CADENCE, NOT PER-THREAD.** Their UX argument (follow six threads on Daily ⇒ six daily
emails, the thing the member was escaping) is right on its own. The mechanical argument decides it:

| | consequence |
|---|---|
| **per-member** | window == cadence still holds — one tick for all of a member's threads. **No new state.** |
| **per-thread** | a member's threads tick differently, so *"have I already told them about this reply"* is no longer derivable from one window → needs a **per-(member,thread) last-sent ledger**, written every send, reconciled on cadence-change and unfollow. |

Per-thread *is* the migration they said they would rather not hand over. Cadence should sit with the
existing account-level email prefs; `topic_follow` stays the per-(member,topic) bell bit; the modal
**shows** cadence rather than owning it.

**4. DROP HOURLY.** Live, all-time (`profile_app.notifications`): 15 `forum.*` rows across 14
recipients — mention 5, reply_to_reply 5, reply_to_topic 5; `forum.followed_topic` **0** (not on live
yet); 568 rows of all types. **Busiest member-HOUR = 1.** Day = 2, week = 2. No member has ever had two
forum notifications in the same hour, so an Hourly digest would contain exactly one item for everyone
who has ever existed here — **mathematically indistinguishable from Instant, except later.** Better Ian
never sees it than picks it and it is withdrawn. Propose Instant / Daily / Weekly.
*Honest limit:* 15 rows over 5 days is a small sample of a feature that has barely started. It proves
Hourly ships with nothing to do **today**, not that it never could.

**5. THE OVERLAP GETS WORSE, and it is joint.** This digest admits `forum.reply_to_topic`,
`forum.reply_to_reply` and `forum.mention`; their per-event mail covers the same rows; a followed-thread
digest is a **third** channel on them. Rate is **UNTESTED, not zero**. Not being solved here, because
Rule 5 means suppression can *delete* a digest rather than shorten it (§4.1b) — flagged so it is a
decision when volume arrives, not a surprise.

> **CORRECTION (2026-07-27).** An earlier revision of this section said the recap would pick a new
> type up "for free". **That was wrong, and the opposite is true** — verified in the code, not
> assumed. `LG_WD_Recap::INCLUDED_TYPES` is an ALLOW-LIST (§6.1): a type absent from it renders
> nothing, enforced in `build_rows()` and again in `row_from_notification()`. Adding
> `forum.followed_topic` to the digest is a deliberate one-line edit, never automatic. That is the
> safer property and it is why the SS9.1 double-send cannot happen by accident.

### 6.1 The source boundary — exactly what this recap covers

Stated explicitly so that when SS9.1 (per-event email vs digest for discussion activity) is ruled
on, the change is a scope edit and not a re-architecture. **No de-duplication has been built,
deliberately — there is no rule yet to de-duplicate against.**

> **REWRITTEN 2026-07-28. The admission rule changed from a list to a TEST.** Ian ruled that the
> digest is a **to-do list, not a news feed** — "just things that require attention from the user
> like a connection request etc." So the question for any type, existing or new, is one question:
>
> ### **DOES THIS WAIT ON THE MEMBER?**
>
> If nothing is owed by them, it does not belong, however pleasant it is to hear.

| Included | From | Why it passes the test |
|---|---|---|
| `connection_request` | bell | **they must accept or decline** — the archetype |
| `forum.mention` | bell | someone addressed **you** directly and may be waiting |
| `forum.reply_to_topic` | bell | a reply on a discussion **you authored** |
| `forum.reply_to_reply` | bell | a reply to **your** reply |
| unread DMs | `message_recipients` | the most literally-waiting-on-you thing the platform has |

| **Removed 2026-07-28** | Why it fails the test |
|---|---|
| `connection_accept` | they already have the connection. **Nothing is owed.** 147 rows all-time |
| `reaction.on_post` | someone liked something. **Nothing is owed.** 53 rows all-time |

Both were **deleted, not disabled** — render arms, `dot_color` arms, the `reaction_what()` helper
and the `reactions` bucket all went with them. A render arm for a type the boundary refuses reads
like a live feature.

**Excluded — everything else, by default.** In particular:

- **`forum.followed_topic` is STRUCTURALLY EXCLUDED — this is stronger than it used to be.** It was
  previously "absent from the allow-list, one line away whenever wanted". Under the to-do test it
  **fails on its merits**: a reply in a thread you merely *follow* does not wait on you — you are an
  observer, not the addressee. It is excluded for the same reason `reaction.on_post` was removed.
  Still regression-tested to render nothing.
- **Activity in threads you merely follow, or any subscription-derived feed.** The recap has no
  subscription source at all — it never queries `forums.forum_subscription`. Every row it draws is
  one where the member is the *addressee* of a bell row.
- Any future notification type, until someone adds it on purpose.

> **AND SINCE 2026-07-30 "excluded by default" IS NO LONGER ALLOWED TO BE SILENT.** The allow-list
> made the digest *safe* against a new type; it did not make the answer *decided*. When thread-follow
> added `forum.followed_topic`, this digest excluded it correctly and silently — the right answer by
> accident, and nothing here would have said so had the right answer been "include it".
> `LG_WD_Recap::DECIDED_EXCLUDED` now records every refused type **with its reason**, and
> `dev/verify-source-boundary.php` reads profile-app's own vocabulary out of source (`TYPES` **and**
> `HUB_TYPES` — reading only the first made every forum exclusion look stale, which is how that bug
> was caught) and fails if any type sits in neither list. **Adding a notification type anywhere on
> the platform now turns this suite red until someone writes down what the digest should do with
> it.** Today: 8 types, 4 included, 4 excluded on purpose, 0 undecided.

**§9.1 DOES NOT CHANGE THIS DOCUMENT'S SCOPE**, which is a genuine simplification and is all that
survives of the paragraph that stood here. The digest's admission rule is the to-do test, and that
test answers `forum.followed_topic` independently of how per-event email is configured.

| If Ian rules… | The change here |
|---|---|
| digest owns discussion activity | **needs a separate ruling that a followed-thread reply waits on you** — his own test says it does not |
| per-event owns it | **nothing** — `forum.followed_topic` is excluded on the merits |
| digest owns it, per-event goes quiet | same as the first row: not a scope edit any more |

> ### ⚠️ THE DOUBLE-SEND CONCLUSION THAT STOOD HERE IS WITHDRAWN (2026-07-30)
>
> It read: *"it cannot happen under any configuration, so no de-duplication is needed under any §9.1
> outcome — not just under some of them."* **The first half of the reasoning is sound and the
> conclusion does not follow from it.**
>
> True: a followed-thread reply cannot double-send, because `forum.followed_topic` is excluded.
> **Also true, and omitted:** this digest **NAMES `forum.reply_to_topic`** — a reply on a discussion
> *you authored* — and BB's reply mailer excludes only the **replier**, never the topic author (its
> own comment says otherwise and is wrong: `class-bp-forums-notification.php:989`). A member with the
> ✉ bit on their own topic therefore receives the same reply as **both** a per-event email and a
> named row here.
>
> **De-duplication is deferred on volume, not unnecessary by construction** — and the trigger is the
> ✉ toggle shipping on that row type, which THREAD-FOLLOW-SPEC §3.5 puts one click from these very
> rows. See RECAP-SUPPRESSION-PROPOSAL.md **§4.1b/§4.1c**. The visible consequence is drawn as
> previs section 4, because suppression here does not shorten an email — under "empty sends nothing"
> it cancels it.

**Preferences.** This section is part of the weekly digest, so the **digest toggle governs it** and
this lane invents nothing. The account page's Weekly Digest switch (bf9e3a1) is FluentCRM **list
membership** (list 3, via `lg_weekly_member_state` / `lg_weekly_member_toggle`), and the campaign
resolves its recipients from that list — so a member who turns the digest off never renders a
recap, automatically. Note for the record: the brief referred to thread-follow §6 proposing
"account = master switch per KIND of email"; the spec at c7b8099 does not contain that — §7
explicitly defers the prefs matrix as future work ("audit §4.2's prefs matrix remains future
work"). So there is no competing model to collide with today, and no new settings row was added.

---

## 7. What was verified, and the one leg that was not

Run on dev2, read-only, nothing sent, no campaign created.

| Check | How | Result |
|---|---|---|
| Smart code substitutes per subscriber | `Parser::parse()` on 3 real subscribers with a stub callback | 3 distinct bodies |
| `Recap::forWpIds` against real PG | run as the `profile-app` role, 6 wp ids incl. an unbridged one | matches `psql` ground truth; unbridged correctly absent |
| PG `text[]` parsing | 8 literals incl. embedded comma, `\"`, `\\`, NULL | 8/8 |
| Endpoint, real FPM pool | `cgi-fcgi` → `/run/php/php8.3-fpm-profile-app.sock` | 200 + correct JSON |
| Endpoint negative paths | bad secret / no ids / 501 ids / bad JSON | `forbidden`, `missing_wp_user_ids`, `too_many_ids`, `invalid_json` |
| Window is real | same call at `days:7` vs `days:365` | 7d returns empty for members whose activity is older |
| **Per-recipient, end to end** | ONE campaign body → `Parser::parse()` for **5 real subscribers** | **5 of 5 distinct**; token left in none |
| **Empty means absent** | wp:1170 (nothing this window) | no section; body **byte-identical** to the no-recap body |
| Never content | rendered sections vs. the verbatim stored text of the replies members were notified about | no stored text, no prose markup, in any section |
| The artifact, looked at | real email HTML rendered in headless Chrome at 390 / 720 | correct at both widths |
| Greeting uses the profile name | 5 real members rendered from one campaign | "Markus", "Doug", "Tony", "Jim", and "Dave" from a 71-char display_name |
| Longest real name (71 ch) | `Dave Staudte (rhymms with "Howdy") NB Guitar Repair (New Braunfels, TX)`, wp:32, at 390px | greeting reads "Dave"; long ACTOR names wrap across lines, no overflow, no truncation |
| Entity damage decodes | stored `Dan Wolf &amp; Steve Baker … Guitar &amp; Amp Repair` rendered in the long-name frame | real ampersands on screen |

### 7.1 The serve window (2026-07-27) — both owed legs closed

Keeper granted an exclusive window. On this box **the serve IS the repo**: `/srv/profile-app` and
`/etc/nginx/snippets/strangler-profile-app.conf` are symlinks into `~/loothplatformv2-clean`, so
the change landed as two new untracked files plus two dirtied tracked files inside the serving
checkout — bounded, and restored after.

| Check | Result |
|---|---|
| `curl → nginx → FPM`, loopback + correct secret | **HTTP 200**, correct JSON — the leg cgi-fcgi could not reach |
| Wrong secret, loopback | 403 `forbidden` |
| Correct secret, **non**-loopback (LAN IP) | 403 — nginx `allow 127.0.0.1; deny all` working as designed |
| `GET` instead of `POST` | 405 `method_not_allowed` |
| Sibling `/internal/notify` after the change | still 400 on a bad body — the nested regex location did not shadow it |
| **One real email to Ian** | SES returned a **MessageId**; **mailpit shows 0 hits** for the subject, so it left the box |
| Greeting in that email | **"Here's your week, Ian."** — name fetched live from the endpoint for wp:1 |

**Why the test email is labelled.** Ian's own bell is 100% read (0 unread notifications, 0 unread
DMs), so his *truthful* recap is the EMPTY case — no section, which demonstrates nothing about the
greeting. Marking his real rows unread to manufacture a demo would have been a write to the live
store outside the window's authorised paths, and a lie about his account. So the **greeting is
real** (live endpoint, real `display_name`) and the **rows are real stored activity borrowed from
other members**, with a banner in the email saying so.

> ### ⚠️ IAN, 2026-07-30: *"That didn't have any notifs activity."* HE IS RIGHT, AND SO IS THE LINE ABOVE.
>
> The paragraph above is literally true and substantively misleading, which is the worst kind of
> accurate. The rows were real *in the database sense* and meaningless *in the human sense*.
>
> **Reconstructed, not inferred.** The 07-27 renderer was loaded out of git (`ac2c4fa`) under a
> renamed class and run against the borrowed payload. The four rows it drew are provably **the same
> four rows the 07-27 build had** — each carries `created_at` inside 2026-07-20..27, and each is
> **still unread**, which is why today's fetch returns them unchanged:
>
> | wp | type | created_at |
> |---|---|---|
> | 690 | `reaction.on_post` | 2026-07-24 04:04 |
> | 197 | `reaction.on_post` | 2026-07-24 14:46 |
> | 197 | `reaction.on_post` | 2026-07-25 23:31 |
> | 690 | `forum.reply_to_topic` | 2026-07-26 19:12 |
>
> **What that renders as — the actual text of what he opened:**
>
> > 2 new replies on your discussion *“NOTIFLANE test topic — click-through gate”* — Ian Davlin The Looth Group and 1 other
> > Ian Davlin The Looth Group reacted to your discussion *“NOTIFLANE test topic — click-through gate”*
> > Claude Admin reacted to your reply *“Suggest an alternative to concave fret file”*
> > Ian Davlin The Looth Group reacted to your post *“Proper Loothing: Back in the Saddle Again!”*
>
> **Every actor is Ian himself or a bot. Three of the four rows are reactions. The subject is a test
> topic.** A section existed; no activity did. *"That didn't have any notifs activity"* is an exact
> description of that email, and the record above should never have implied otherwise by saying
> "real stored activity" without saying **whose** and **what**.
>
> The whole platform held **8 notification rows** in that entire week (6 `reaction.on_post`,
> 2 `forum.reply_to_topic`). The demo had to borrow because Ian's recap was empty, and what it
> borrowed was empty of meaning too. **`reaction.on_post` was still INCLUDED on 07-27** — Ian removed
> it the next day — so today's renderer draws only 1 of those 4 rows.
>
> **This is not a defect in the send.** It is the design being demonstrated over a store with nothing
> in it, and it is the same finding as §10.4 arriving from a third direction — see the scoping note there.

**The send bypasses `wp_mail` on purpose.** dev2's containment mu-plugin swallows `wp_mail` into
mailpit and returns `true` — a convincing false positive. `build-inbox-test.php` only *builds*;
the send is a direct SES call. **Trap for next time:** FluentSMTP stores the SES secret
**encrypted** (208 chars raw in `fluentmail-settings`, 40 after `fluentMailGetSettings()` decrypts
it). Signing with the raw option value fails `SignatureDoesNotMatch`.

**Restore proof** — `HEAD fa67f026…`, `tree 74b39757…`, branch `main`, `git status --porcelain`
empty, all matching the sealed baseline; `nginx -t` green **after** the restore, then a full
`systemctl restart nginx` plus a smoke of `/`, `/hub/`, `/u/<slug>` (200) and `/internal/recap`
(404 — correctly gone again).

## 9. "What you missed" — the exclusion rules

### 9.1 The three that are free (BUILT)

All enforced in `Recap::forWpIds()`, at the source, per recipient — so the smart code naturally
emits nothing for a member who is up to date, and no renderer can widen the rule by accident.

| Cleared by | Signal | Status |
|---|---|---|
| Looking at it in the bell | `notifications.is_read` / `read_at`, maintained by `Notifications::markRead()` | enforced |
| Reading the message | `message_recipients.unread_count`, senders scoped by `last_read_at` | enforced |
| Making or actioning the connection | `connections.status`, read **live** | enforced (new) |

The connection one is the one that was actually missing, and it needed more than `is_read`: **a
connection row stays unread in the bell even after the member has gone to the profile and accepted
it.** Measured on this box — **3 unread `connection_request` rows whose edge is already
`accepted`** (Bryan Parris wp:18, Brent Gable wp:120, John Catches wp:1884). Each would have told a
member "X wants to connect" about someone they are already connected to. So the edge's live status
is the authority, not the bell row:

- `connection_request` → listed only while the edge is still `pending` (still theirs to action)
- ~~`connection_accept` → listed only while the edge is still `accepted`~~ — **the type was removed
  entirely on 2026-07-28**; nothing is owed once the connection exists.

> **AND `is_read` NO LONGER SUPPRESSES A CONNECTION REQUEST AT ALL (2026-07-28).** The two-register
> ruling needs a *resolved* signal, not a *seen* one, to know when to stop counting an item — a
> member who looked at a request and did not answer it still owes an answer. So for
> `connection_request` the edge status is now the **only** test; `is_read` is not consulted.
>
> **This is load-bearing, not tidying.** `bottom-nav.js:1128` auto-fires `markAllNotifsRead()` 700ms
> after the mobile notification sheet renders — glancing at your notifications on a phone marks every
> one of them read whether you acted or not. Desktop has no equivalent (checked all 24 docroot
> `.js`). If this were ever "simplified" back onto `is_read`, a glance would silence a member's whole
> digest, because empty now means no email at all. The row above is kept because it is still true for
> the forum types, which have no cheap resolution signal.

Verified: all 3 stale rows now excluded; a control member with a genuinely pending request still
sees it.

### 9.1a The empty case — **SUPERSEDED: empty now means NO EMAIL AT ALL**

> **Ian ruled 2026-07-28: a member with nothing waiting on them gets no email whatsoever, not the
> digest minus the section.** "An email from us should mean something genuinely wants them." What
> this section used to say — that the empty section is the common case and must render clean — is
> still TRUE at the renderer, but it is no longer what a member experiences, because they are
> dropped before a `CampaignEmail` row exists.

**Where it is enforced:** `LG_WD_Recap_Source::recipients_with_something_waiting()`, called from the
sender before `$campaign->subscribe()`. The renderer's empty-string behaviour is retained as the
belt-and-braces behind it (a member reaching the render with an empty payload must still emit
nothing) and its byte-identical proof is repointed, not deleted — see
`dev/verify-per-recipient.php` and `dev/verify-empty-means-no-send.php`.

**It fails OPEN on purpose.** If the recap source cannot be reached, everyone is kept and the send
goes out whole. Failing closed would mean one unreachable endpoint silently mails nobody — and that
failure is indistinguishable from a quiet week, with no bounce and no error.

**Measured on live 2026-07-28 (list 3, 1,663 subscribed):** **280 members would be mailed** — 43 on
named items only, **181 on a counted line only**, 56 on both. The counted register is the majority
of the recipient list, so `stale` had to join the "is this payload empty" test or 181 members would
have been silenced while the renderer could perfectly well draw their row.

> ⚠️ **THE DENOMINATOR BELOW WAS WRONG AND THE CORRECTION MAKES THIS FLAG STRONGER (2026-07-30).**
> It read *"1,383 of 1,663"*, computed as 1,663 − 280. **The digest's real audience is 1,858** —
> list 3 (1,663) **plus 195 list-7 non-members** — proven by campaign 379's own 1,858 recipient rows.
> So **1,578 receive nothing, not 1,383**, and the 195 I had omitted are exactly the cohort that
> *cannot* be in the 280 because they have no account and therefore no to-do list. See
> RECAP-SUPPRESSION-PROPOSAL.md **§5.1** — it is the open merge blocker.

**Flagged, not re-argued:** this suppresses the *whole* email, so 1,578 of 1,858 recipients receive
nothing — including Upcoming Events, the videos and loothprint, which have nothing to do with
anyone's to-do list.

### 9.1b ⚠️ KEPT BY THE FILTER, DRAWN AS NOTHING — the rule above does not hold today (2026-07-31)

**Found while rendering real members on dev2 for Ian's "can we test it" ask, not predicted by
review.** `dev/verify-kept-but-empty.php` reproduces it and exits RED.

**The filter and the renderer answer the same question by different rules.**

| | asks |
|---|---|
| `recipients_with_something_waiting()` | *is the payload non-empty?* — a **shape** test on whatever the endpoint returned (`fetch()`'s `empty(notifications) && empty(dms) && empty(stale)` normalisation) |
| `LG_WD_Recap::render()` | *does any of it survive the **source boundary**?* — `INCLUDED_TYPES` for live rows, and the `$labels` map in `rows_from_stale()` for counted ones |

`/internal/recap` is a **general read API and does not apply the digest's boundary**. It returns
every type the bell stores — including the ones §6.1 deliberately refuses on Ian's to-do test
(`reaction.on_post`, `connection_accept`, both in `LG_WD_Recap::DECIDED_EXCLUDED`).

So a member whose entire week is a reaction or a connection *acceptance* has a **non-empty payload
and an empty section**. The filter keeps them; the renderer draws nothing; they are mailed a digest
whose personal section is missing — **the exact outcome §9.1a forbids**.

**Measured on dev2, 2026-07-31** (lists 3+7, 1,816 subscribed, live endpoint, 7-day window):

| | |
|---|---|
| filter keeps | **309** (suppresses 1,507) |
| …kept and drew a section | 90 |
| …kept, no WP account — **correct**, kept on purpose per Ian 2026-07-30 | 214 |
| …kept, **is a member, drew nothing** | **5** |

The five: `wp 8` Dan Erlewine, `wp 16` Thom Abell, `wp 135` Michael Bashkin, `wp 171` Sam Hochberg
(all `connection_accept`), `wp 423` Luke Heaton (`reaction.on_post`).

**Why no existing test sees it.** Every other recap test asserts what should be **present**. This is
an *absence* that only appears when two components — each correct in isolation — are compared. It is
the same blind spot as the box's standing rule about gates.

**The fix is one line, at the filter, and it is not mine to merge unreviewed.** `build_rows()` is
already `public static`, so the filter can ask the renderer instead of guessing from shape:

```php
// class-lg-wd-recap-source.php, recipients_with_something_waiting()
- if ( ! empty( $payloads[ $wp ] ) ) {
+ if ( $payloads[ $wp ] && LG_WD_Recap::build_rows( $payloads[ $wp ] ) ) {
      $keep[] = $sub_id;
  }
```

Do **not** instead teach `/internal/recap` the digest's boundary: it is a general read API with
other callers, and `dev/verify-missed-exclusions.php` legitimately drives it at other widths. The
boundary belongs to the digest and must stay in one place (§6.1).

**Scale check before this is treated as urgent or as trivial:** 5 of 309 on dev2 — but dev2 gets a
trickle of traffic and `connection_accept` is one of the highest-volume types on live (147 rows
all-time, §6.1). The live number should be measured with the same script before the flag is turned
on, not extrapolated from here.

### 9.2 The one that is NOT free — click-through from a previous digest (**NOT BUILT, needs Ian**)

**The problem is real and verified.** Nothing marks a notification read when a member clicks a link
in the *email*. Recap links go straight to `/hub/?topic=…`, and `Notifications::markRead()` is
called from exactly one place — `/profile-api/v0/me-notifications`, the bell modal. So a member who
reads only by email never clears anything and sees the same items re-listed every week: precisely
the nag this section exists to kill.

The obvious fix is a per-item signed seen-then-redirect endpoint. **It must not be built on the
obvious design, and here is the evidence — from this platform's own data, not general principle.**

**Mail scanners already click every link we send.** FluentCRM's click tracking is itself a GET that
mutates state (`RedirectionHandler::redirect` → `trackUrlClick` → 307), with **no bot, user-agent
or prefetch guard of any kind** — `grep HTTP_USER_AGENT` across `fluent-crm/app` returns nothing.
So the historical click table is a natural experiment in exactly this hazard. Campaign 266 (Weekly
Digest, 25 May, 97 apparent clickers):

| Distinct URLs hit by one subscriber | Subscribers |
|---|---|
| 1–3 (plausible humans) | 85 |
| 4–5 | 2 |
| **10** | **8** |
| **12 and 20** | **2** |

The eleven at the top are machines, and the timing proves it: **10, 12 and 20 distinct links hit in
0–4 seconds**, all inside a three-minute band right after the send, from datacenter IP ranges with
no reverse DNS (`135.232.20.x`, `74.179.70.x`, `48.209.223.38`, `68.218.165.88`, `72.153.231.x` —
behaviour consistent with enterprise mail-security link detonation; I could not attribute a vendor,
so I am not claiming one). No human clicks twenty links in two seconds. Campaign 265 shows the same
at 6.6%. Campaigns showing 0% are the ones with few tracked links — the signature cannot express
itself where there is nothing to follow (campaign 283 had 18 URLs and a per-subscriber max of 3).

**So: where our email carried many links, 7–10% of apparent "clickers" were machines following
every single one.** A recap carries up to 8 links. For those members a naive click-clear would wipe
the entire section within seconds of delivery, before they ever opened the mail — **and their next
digest would be empty, which looks exactly like "they are up to date."** The failure is silent, and
it is biased toward members whose employer runs mail security: the professional end of this guild.

**Options, and what the evidence supports:**

| # | Design | Holds up? |
|---|---|---|
| A | Plain signed GET clears the item | **No.** This is the measured failure above. |
| B | GET renders a tiny interstitial; a **POST** / `fetch` from it clears | Scanners run no JS and submit no forms. Costs one visible hop. |
| C | Land on the Hub as today; clear only if the **member's own session** is present | No new hop; scanners carry no session cookie. Fails for logged-out readers — exactly the email-only members this is for. |
| D | Clear on **arrival in-app**: mark read when the deep-linked topic actually opens in the Hub modal | No email-side mutation at all. The signal is "they arrived", observed where a session exists. |
| E | Do nothing; accept re-listing until read in-app | Zero risk, keeps the nag. |

**Recommendation: D, with C as the cheap fallback.** What we actually want to know is *did the
member arrive at the thing* — and that is observable **in the app**, where a session exists and no
scanner reaches, rather than inferred from an email hop a scanner forges indistinguishably. It
needs no new endpoint, no signed token and no interstitial: the deep link already lands on
`/hub/?topic=…&reply=…`, and the modal open is the natural place to mark the matching notification
read. B is the right answer *if* Ian wants the clear to happen even when the member never reaches
the app — and it must then be POST-on-landing, never a bare GET.

**A must not ship in any form.** Signing the URL does not help: a signed URL sitting in an inbox is
precisely what the scanner fetches.

---

## 8. Deploy notes

1. `lg-weekly-digest` on dev2 is a **symlink to the serving checkout**
   (`/var/www/dev/wp-content/plugins/lg-weekly-digest → ~/loothplatformv2-clean/lg-weekly-digest`),
   so the plugin deploys with a normal pull. It was a real-directory copy at one point — check
   `ls -la` before assuming, on any box.
2. `platform/nginx/strangler-profile-app.conf` is symlinked from the serving checkout too, so the
   new location arrives with the pull, but needs `nginx -t && systemctl reload nginx`.
3. `profile-app/src/Recap.php` is loaded from `config.php`'s explicit `require_once` list (there
   is no autoloader) — the line is added; a new class without it 500s.
4. After repointing anything under `/srv/*`, `systemctl reload php8.3-fpm` — opcache and the
   realpath cache pin the old target.
5. The digest is still **double-off** (`enabled=false`, `cron_mode=draft_and_notify`) and the
   campaign path leaves campaigns in `draft`. Turning the digest on is a separate, deliberate act.

### 8.1 The public signup page — what a pull does NOT do (2026-07-30)

The page itself deploys with a plain pull: `[lg_weekly_signup]` lives in
`lg-weekly-digest/includes/class-lg-wd-signup-page.php` + `templates/signup-page.php`, and the plugin
is symlinked into the docroot **as a whole directory** (verified: `lg-weekly-digest ->
/home/ubuntu/loothplatformv2-clean/lg-weekly-digest`), so a new file inside it needs **no new
symlink**. That was the reason for putting it there rather than in a new mu-plugin — mu-plugins are
symlinked individually, and creating that link in the same window as the pull is the one coupling a
pull does not handle.

**Three things a pull cannot do, none of them mine, all of them one line.** Until the first two are
done the page is not reachable by the people it is for, and the craft gate cannot audit it.

| # | Step | Box | Whose | State |
|---|---|---|---|---|
| 1 | Page **68595** (`/weekly-email-sign-up/`) content: `[fluentform id="5"]` → `[lg_weekly_signup]` | dev2, then live | keeper on dev2 / **Ian on live** | **DONE on dev2** 2026-07-30 · live outstanding |
| 2 | Add `/weekly-email-sign-up/` to the **`bp-enable-private-network-public-content`** allow-list (67 entries today) — without it the page **302s anonymous visitors to wp-login**, and its entire audience is anonymous | dev2, then live | keeper on dev2 / **Ian on live** | **DONE on dev2** (67 → 68) · live outstanding |
| 3 | Add `"wdsignup": ("/weekly-email-sign-up/", ["anon"])` to `tools/gates/craft-gate.py` PAGES | repo | this lane, **after** step 2 | entry **added**; gate **not yet run** (needs the browser seat) |

> **Step 2 is not cosmetic and it is not a redirect nuisance.** The craft gate audits an `anon`
> viewer. If the page 302s to wp-login the gate will happily audit **the login page** and report
> green — a pass over the wrong document. So step 2 must land before step 3 means anything.

**Verified on dev2 after steps 1+2** (anon, dev gate passed, from the LAN IP `172.31.78.94` — a
loopback curl authorizes itself and proves nothing): `/weekly-email-sign-up/` returns **200**, was a
302 to `wp-login.php?...action=bpnoaccess` before. Title `Weekly Email Sign Up – The Looth Group`,
exactly one `<form>` on the page and it is ours (`id="lgws-form"`, with `lgws-email`, the `lgws-website`
honeypot and `lgws-said`), and no raw `[lg_weekly_signup]` left unrendered. *A first probe searched for
`lg-wd-signup` and found nothing — a class name I guessed rather than read. Check the template for the
markers before concluding a page is broken.*

> ### ⚠️ STEP 1 ON LIVE: `wp_update_post()` WILL REPORT FAILURE **AFTER** WRITING THE CONTENT
>
> Page 68595 carries `_wp_page_template = page-fullwidth.php`, and the active theme is
> `twentytwentyfive`, which offers only `page-no-title`. **dev2 and live hold exactly the same stale
> value** (checked read-only on live: same theme, same meta, same content) — this is not a dev2 quirk.
>
> `wp_update_post()` merges the post's *existing* `page_template` back into its own input and then
> validates it, so any content-only update to this page returns
> `WP_Error('invalid_page_template')` — **but the content has already been written by then.**
> Proven on dev2 on a throwaway page carrying the same meta: returned `WP_Error`, and the row read
> back `CHANGED`. Probe page deleted.
>
> So on live: **do not retry, and do not assume nothing happened.** Read the row back before acting on
> the error. The clean way is to write the one column and leave the meta alone —
> `$wpdb->update($wpdb->posts, ['post_content'=>'[lg_weekly_signup]'], ['ID'=>68595])` then
> `clean_post_cache(68595)`. Changing the template meta to satisfy the validator would alter how the
> page renders *and* make dev2 disagree with live.
>
> Also note `post_modified` renders in **site-local time (EDT)** while `post_modified_gmt` is UTC —
> four hours apart. Reading the local column alone made a write that had just happened look four hours
> old, and briefly made it look like somebody else had done it.

**The old page is not deleted by any of this.** Page 68595 keeps FluentForm 5 in its revision history;
step 1 is a content change, reversible by restoring the shortcode. Recorded here because the previous
content was `[fluentform id="5"]` and nothing else.

---

## 10. The public signup page (Ian, 2026-07-29 → built 2026-07-30)

> **"A page where someone WITHOUT a WP account signs up for the weekly email. No WP user may be
> required or created."** — `docs/BACKLOG.md`. Four named deliverables: the page, a store, unsubscribe,
> and the digest sender reading that store alongside members.

**Designed INTO the digest, not beside it.** The whole point of the folding is that a non-member's
email is the *same email*, minus the part that could not apply to them:

| Piece | Where it is | State |
|---|---|---|
| the page | `[lg_weekly_signup]` → `includes/class-lg-wd-signup-page.php` + `templates/signup-page.php` | **built** |
| the store | FluentCRM list **7**, *Non Member Weekly Email Subscriber* | already existed; the page is the only thing that writes it |
| the write | `wp_ajax_nopriv_lg_weekly_signup`, `platform/mu-plugins/lg-event-reminders.php` | **built** (Ian's ruling 6) |
| unsubscribe | FluentCRM `##crm.unsubscribe_url##`, already in `templates/email.php` | **proven for account-less contacts** |
| the sender reading it | audience is now `[{list:3},{list:7}]` + the to-do filter keeps the account-less | **built** |

### 10.1 Why a non-member's email needs no special rendering

The per-recipient seam already produced the right answer before this lane touched it, which is worth
stating because it is why "fold it in" was cheap rather than a rewrite:

- `LG_WD_Recap_Source::render_for_subscriber()` returns `''` when the subscriber maps to no WP user;
- `LG_WD_Recap::render()` treats empty as **absent** — no heading, no panel, no zero-state, no greeting;
- so `##lg_recap.section##` resolves to nothing and the body is **byte-identical to the curated
  digest**. There is no non-member template, and there is no "Hi," addressed to nobody.

That is exactly Ian's model: *the email announces this week's public content to everyone on the list.*

### 10.2 The four answers the form can give

The endpoint owns this copy, so the page does not duplicate it — the page supplies only the headings.

| State | Who | Written |
|---|---|---|
| `already_member` | on list 3 | **nothing** |
| `member_needs_prefs` | has a WP account, not on list 3 — **233 on live** | **nothing** |
| `already_signed_up` | already on list 7 | **nothing** |
| `pending` | nobody | list 7 only, + double opt-in |

**The member list is never written from this path, on any branch.**

### 10.3 The sample email, and a blind spot it revealed

The page frames the most recent **sent** issue, rendered at request time (transient, 1h) with
`mode => strip`. A committed snapshot was the first design and was wrong: rendered on dev2 it carries
dev2 upload URLs, so on live it shows broken images, and the page's own claim — *"rendered by the same
code that sends it"* — would go false the first week nobody regenerated it.

> **⚠️ IT FRAMED THE FRONT PAGE. Fixed 2026-07-30 @9418ff5 — this paragraph described the intent, not
> the behaviour, for as long as the page existed.** `preview_url()` was built from `home_url('/')`, and
> `maybe_serve_preview()` hangs off `template_redirect`. **archive-poc's strangler owns `/` and answers
> before WordPress routes**, so the handler never ran and the iframe got the discovery feed. Measured,
> both anon, both **200**:
>
> | URL | bytes | `<title>` | unsubscribe markers |
> |---|---|---|---|
> | `/?lg_wd_email_preview=1` | 75,458 | Looth Group — Lutherie Community | **0** |
> | `/weekly-email-sign-up/?lg_wd_email_preview=1` | 37,321 | The Looth Group — Week of July 30, 2026 | 2 |
>
> The URL is now built from the **host page's permalink** (the handler keys on the query var alone, so
> any WP-routed permalink serves it, and it stays same-origin because it is the same page). If no
> permalink resolves the section is **dropped** — falling back to `home_url('/')` *is* the bug.
> Regression test: `dev/verify-preview-frames-the-email.php`, which runs the **negative control first**
> and reports CANNOT RUN if it cannot tell the two documents apart.
>
> Nothing routine could have caught it: 200 status, real markup, a real iframe — and per the blind spot
> below, the gate cannot look inside a frame anyway. What found it was comparing **byte counts**
> (75KB served vs 35KB built in-process), then reading `<title>`.

> **The craft gate cannot see inside an iframe.** `craft-gate.py` collects `querySelectorAll('img')`
> and the resource timeline in the **top frame only**; a frame has its own document and its own
> timeline. So the heaviest thing on this page is invisible to IMG-RAW, IMG-OVERSIZE and the KB
> budget, and the page would have gone green while shipping full-size uploads into a 624px column —
> **measured on dev2 for the July 13 issue: five images, 577KB, the largest 308KB.** The preview now
> routes uploads through `/img.php?w=600` (a bucket from the resizer's own `ALLOWED_W`; an unlisted
> width silently becomes 800). **Not applied to the sent email** — real inboxes fetch with no cookies
> and `img.php` sits behind the dev gate, so that would break images in every recipient's client.
>
> **And the gate had a second, worse blind spot — it greened over a page it never loaded.**
> `craft-gate.py` requires a browser launched with `$LG_GATE_CHROME_RESOLVER`, but the box's only
> engine, `chrome-dev.service`, does not carry `--host-resolver-rules`. Pointed at it, every surface
> loads as Cloudflare's `<title>Just a moment...</title>` — small, imageless, no eager scripts — so
> **every check passes by construction**. Proven by running the gate's own `check()` over that payload:
> **0 violations, verdict PASS.** Fixed @09a15da: `wrong_document()` refuses an edge-challenge title or
> a near-empty body and exits **2 CANNOT RUN**. Run the gate by stopping the service and launching
> chrome with the resolver; do not `pkill` (systemd restarts it in 3s).

### 10.3a The frame was 368px too narrow — variant B (Ian, 2026-07-30)

> **"email frame b what the lane recommends is good."** — Ian, approving the option this lane put
> forward after seeing it drawn.

Framing the right document was not the same as framing it readably. His words on finding it:
*"the email is now in a container where it floats left and right to see the whole thing with out
having the text cut off. This sucks."*

| | declared where | px |
|---|---|---|
| `.email-container` | `templates/email.php:68` | max-width **960** |
| `.email-wrapper` padding | `templates/email.php:64` | `24px 16px` → **32** horizontal |
| **the document therefore needs** | | **992** |
| `.lgws .mail` | `templates/signup-page.php` | max-width **624** |
| **overflow** | | **368** |

> ### ⚠️ CORRECTED 2026-07-31 — THERE WAS NO OVERFLOW. THE TABLE ABOVE IS ARITHMETIC, NOT BEHAVIOUR.
>
> Measured in a real browser (lane-preview + CDP) at **360/414/480/520/600/700/768/900/1100/1440**,
> reading `scrollWidth` vs `clientWidth` on the page **and inside the iframe**:
>
> **The email fits its box exactly at every width** — 258/258, 312/312, 403/403, 603/603 … and the
> widest element inside it never exceeds the frame. **The shipped 624px page does not pan sideways.**
>
> `.email-container` is `max-width:960px; width:100%` and its own 768px media query makes it fluid, so
> it renders at whatever the frame gives it and can never exceed it. The "992 needed / 368 over" is a
> subtraction of declared numbers the layout never reaches.
>
> **Variant B is kept, and re-justified:** at 1280 it hands the email **990px instead of 603px** — more
> of the design at full type size. That is a **legibility** change. It is **not** an overflow fix, and
> **on a phone it does nothing at all**: `max-width:992px` never binds at 390px.
>
> **So Ian's words are not yet explained.** Best remaining hypothesis, held as a hypothesis: on a phone
> the email renders into ~260–350px when it is designed for 600–960px — not clipped, *cramped*. Zooming
> to read it produces the left-right movement he described, and "text cut off" is what heavy wrapping at
> 260px looks like. If that is right the fix is not frame width but what a phone is shown at all.
> **Nothing has been built on that guess.**
>
> The lesson worth keeping: `dev/verify-preview-frame-fits.php` was green the whole time and its own
> header states the limit that turned out to be the whole story — *it compares declared widths, not
> rendered ones*. **A gate written from a theory certifies the theory, not the page.**

**The frame had been sized to the email's CONTENT COLUMN; the email is a 992px DOCUMENT.** That also
explains the wide black gutters in his screenshot — the width was available, the box was not taking it.
Fixed to 992. The *"Scroll inside the message to read the whole issue"* caption is **deleted**, along
with its now-dead `.fcap` rule: it existed only to excuse the clipping, and a caption that explains a
defect is the defect wearing a label.

**`max-width`, never `width` — load-bearing for the phone.** Below 992 the box collapses to the
viewport and the email's own 768/480 breakpoints take over. A hard width would hold the box open at
992 inside a 390px screen and move the panning *outside* the iframe: the same defect, one level up.

**The standard this was decided by, and worth keeping:** *A is the evidence, not my explanation of it.
If A pans and B does not, the fix is right regardless of my reasoning.* Show the defect, then show it
gone — the mock carried a live 624px panel, a live 992px panel and a **390px phone panel**, all
framing the same real email.

**Two things I nearly got wrong, both caught by measuring rather than reasoning:**
- A `<td width="624">` in the served email looked like the culprit. It is inside an `<!--[if mso]>`
  conditional — **Outlook-only, inert in every browser**. Strip conditionals the way a browser does and
  there are **zero** cells over 400px. I would have "fixed" a cell no browser renders.
- The text sheared in his screenshot — *"…Winding Pickups with Tom Bra…"* — is an **`alt` attribute**,
  so that image was not painting for him. Flagged at the time as *possibly a second defect*.
  **RESOLVED 2026-07-30: it is a dev2 DATA gap, not a code defect, and it does not follow to live.**
  Two of the preview's nine images fail on dev2 — `/img.php` 302s to the original (its documented
  fallback for a source it cannot handle) and the original **404s**, because those uploads do not
  exist on this box. The browser then paints the alt text, which is what he saw.
  **On live all three checked URLs return 200**, including `/img.php` **with no cookie** — the
  resizer is public there; the dev gate is a dev2-only thing. So the preview renders correctly for
  the anonymous audience the page is actually for.
  *Consequence worth carrying: part of what Ian judged this page by is dev2's missing uploads rather
  than the page. Do not "fix" broken preview images on dev2 by changing the resizer.*

**Guarded by `dev/verify-preview-frame-fits.php`**, which parses both numbers out of the shipping
templates so it cannot drift from what serves, and additionally asserts the phone half (max-width with
no hard width; the ≤480px breakpoint resetting `.event-img`, since the cards ship `<img width="240">`
with no max-width). *It proves the rules that make the phone case work are present — not that a phone
rendered it.*

> **Both failure branches were proven red before the green was trusted, and one was VACUOUS.**
> Narrowing the frame back to 624 failed correctly. Adding a hard `width` beside the max-width
> **passed** — the check was `[^-]width`, which needs a character before `width` and so could never
> match `.mail{width:…`, the exact case it was written for. Fixed to a lookbehind and re-proven red.
> **An assertion that cannot fail is worse than no assertion.**

**GATE RESULT FOR THIS PAGE, 2026-07-30, chrome launched WITH the resolver: `wdsignup/anon` PASSES** —
imgs 39KB, total 969KB against a 2500KB budget. The 969KB is BuddyBoss chrome present on every anon
page (104 resources); this page's own contribution is the 39KB site logo. **§8.1 step 3 is done.**
Not over-read: `codemirror.min.js` (57KB) and `buddypress-activity-post-form.min.js` (30KB) load for an
anonymous visitor here, which CLAUDE.md's *editors load on intent* rule forbids — but `EDITOR_MARKERS`
only matches `quill`, so the gate cannot see it. Platform-wide BuddyBoss behaviour, not this page's.

### 10.4 Still open — one word from Ian, and it is not a blocker for this page

*"Everyone on the list"* and Rule 5 (*"empty means send NO EMAIL AT ALL"*) point opposite ways for a
**member with nothing waiting**. Rule 5 is Ian's own 07-28 ruling, so it has not been retired on
inference: as built, an account-less subscriber is always kept and a member with an empty to-do list
is still dropped. If Ian says *"everyone on the list, always"*, the change is deleting one branch.
