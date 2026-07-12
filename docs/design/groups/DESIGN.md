# GROUPS — mini-hubs, opt-in/out, navigation & posting

**Status: DESIGN + MOCKS. No feature code written. No data changed. Nothing merged.**
Lane: `groups-design` off main `6d21925`. Author: groups-design lane, 2026-07-12 (round 2).
Mocks: `~/projects/groups-design-lane/mocks/` (CDP-injected over the real dev2 serve — the site
header, fonts and colour tokens in every shot are the real ones).

> **Round 2, 2026-07-12 — two rulings from Ian:**
>
> 1. **CHATS ARE ON HOLD.** *"I think everything can be done from discussions."* The group chat room
>    is **deferred, not cancelled.** No room schema, no read-state model, no notification-volume guard
>    — **§4 is now a stub.** Discussions become the **single content surface** for a group, carrying
>    both durable announcements and throwaway chatter. **This removes the only genuinely novel piece
>    of the build** (see the re-cost in §7) — but it lands one requirement on the composer that is
>    easy to miss, and if we miss it the chapters stay as empty as they are today. **See §B.5.**
> 2. **Ian likes the mini-hubs** (chapters and subjects). Approved in principle.
>
> **The live question is now NAVIGATION + POSTING** — how members reach a mini-hub, and whether
> "+ New post" should take them there to compose in context. **That is §A and §B, and it is what
> this round is for.** Everything from §1 on is round-1 work, still standing.

> **Round 1, 2026-07-12 — Ian's earlier ruling:** chapters are **NOT private**. BuddyBoss "private"
> was only ever a crude opt-in mechanism, not a privacy intent. **Everything is public and browsable;
> subscription is purely a FEED filter; joining is one-tap self-serve with no approval.** The
> private-chapter / teaser / request-to-join design is deleted. Separately: **The Jannies, Dank Memes
> and Music are DEFUNCT** — archive them, do not surface them, do not publish their contents.

---

## 0. OPEN QUESTIONS FOR IAN

Each with my recommendation. **Q1 and the new Q8 gate the build.** Q2 and Q5 are now **moot** —
chats are on hold.

| # | Question | My recommendation |
|---|---|---|
| **Q1** | **Opting OUT of a subject — does it (a) hide that subject's posts from your MAIN Hub feed, or (b) only mute notifications?** *(still the crux, still unanswered)* | **(a) hide from the main Hub feed**, and mute notifications with it. Your ruling makes this cleaner, not harder: because everything stays public and browsable, hiding a subject from your feed costs a member *nothing* — the content is still one click away at `/g/<slug>`, still searchable, still linkable. The feed filter is now purely about **your firehose**, with no access consequences at all. See §2. |
| **Q8** | **NEW, and it gates §B: for a SUBJECT, does the poster still pick a sub-forum?** Repair And Restoration has **9** of them (Acoustic Repair, Finish Repair, Neck Reset Database…). Composing "in the group" does not make that choice disappear — it only scopes it. | **Keep the sub-forum, scoped to the group** (a 2–9 item flat list, shown only when the group has more than one). Flattening 9 sub-forums into one Repair bucket is a real IA change that throws away a working taxonomy — and members already navigate by it. **Chapters skip this step entirely** (1 group = 1 forum), which is why your idea works perfectly there. See §B.2. |
| **Q9** | **NEW: do you want ONE picker or TWO doors?** Is GROUP a second axis inside the parked content-type picker, or its own entry? | **Its own door, sibling to it.** A *type* (Videos) is a lens on the firehose; a *group* (SoCal Looths) is a **place** with members and a join button. One sheet containing both teaches members that SoCal Looths is a kind of content. See §A.3 — I mock the merged alternative too, in case you disagree. |
| **Q10** | **NEW, and it is the one to answer even if you answer nothing else: "group" now means TWO things.** Group **messaging** shipped tonight — members can make "a group" with a name, an owner and a member list. This design calls chapters and subjects "groups" too. **Same word, two features, same month.** | **Rename ours, not theirs.** Messages keeps "group" (it shipped, it's the ordinary meaning). Ours get the names they already have: **Chapters** and **Subjects** — nav tray tile = "Chapters", You sheet = "My chapters & subjects". "Group" survives only as the internal schema word (`bp_group`), which no member ever sees. **This costs one string today and a rename across every surface + the URLs if we ship first and decide later.** See **§C.1**. |
| **Q3** | Who may create a chapter? | **Staff only, for now.** A chapter is a real-world commitment (someone hosts the meetup). Members *request* one. Revisit past ~15 chapters. |
| **Q4** | Can members create their own groups? | **No, not in v1.** You are about to archive three dead groups (§5). Member-created groups would manufacture more. Ship mini-hubs, see if anyone asks. |
| **Q6** | Should non-discussion content (videos, articles, loothprints) belong to a group? | **Not in v1.** They genuinely have no group today — see §1.2. Discussions are group-aware for free; CPTs are not. This is also why the group chip lands on discussion cards only (§A.2). |
| **Q7** | Part D: which 3 chapters did you mean? | I believe I found them without needing you to name them — **Middle Tennessee, Ohio, Basque Country** — and I can prove it (§6). Please confirm. |
| ~~Q2~~ | ~~Is the group chat open to non-members?~~ | **MOOT** — chats deferred. |
| ~~Q5~~ | ~~Does a group chat notify all 1,841 members by default?~~ | **MOOT** — chats deferred. |

---

# PART 2 — NAVIGATION & POSTING (round 2: the live question)

## A. NAVIGATION — how a member reaches a mini-hub

### A.1 There is no single door, because there are three different intents

The question "how do they get there?" only looks like one question. It's three, and they want
different answers:

| the member is thinking… | who they are | the right door | cost |
|---|---|---|---|
| *"what group is this post from? show me more like it"* | anyone, mid-scroll | **group CHIP on the Hub card** | **~0 — the chip slot already exists** |
| *"what groups even exist? is there one near me?"* | new / unaffiliated | **a Groups directory, `/groups/`** | S |
| *"take me to my chapter"* | a returning member | **You sheet → "My groups"** | S |

**Recommendation: ship all three.** They are each cheap, they don't compete, and no one of them
covers the other two. But **the chip carries the volume** — it is the only door that requires zero
nav learning, and it is also the cheapest. If you ship one thing, ship the chip.

### A.2 The chip is nearly free, and it is already half-built

`bb-mirror/web/forums/_feed.php:1570` — discussion cards **already render a `.fc-cat-chip`**, populated
from `forum_title` (the leaf sub-forum, e.g. "ACOUSTIC REPAIR"). **The slot exists and is already
filled.** Phase 0 (`effective_group_id`, §7) is what makes it group-aware.

**Design: keep both chips.** They are different facts and members want both:
- **Group chip** — *which place this came from* → tappable, goes to `/g/<slug>`
- **Existing sub-forum label** — *which shelf inside it*

**CPT cards get no group chip**, and that is correct, not an omission: video/article cards render the
chip from `content_forum_label`, which is **empty** (`_hub-filters.php:12` says so itself). A video
never belonged to a group. Consistent with Q6.

### A.3 Keeper's picker question: is GROUP a second axis in the content-type picker?

> **Updated 2026-07-12 22:4x — the picker is no longer parked. `hub-picker-in-tray` (variant A,
> sibling-sheet + the house slide motion) is MERGED and live on main `778900c`.** That does not change
> the recommendation; it makes it **cheaper and more concrete**. The content-type sheet now exists as a
> real control with a real motion, so "Groups gets a sibling sheet" is **the same idiom, opened from a
> different tile** — not a new pattern to invent. The N3a/N3b mocks below are shot against **the real
> merged picker**, not a mock of it.

**Recommendation: no — a separate door, sibling to it. The now-merged `hub-picker-in-tray` control
stays exactly as it shipped, with no rework and nothing duplicated.**

First, the thing worth knowing: **the content-type axis already exists on BOTH surfaces.**
- **Desktop:** `lg-shared/site-header.php:364,595–610` — "The Hub" nav item already opens a submenu
  (`lg-hubmenu`) listing `$hub_types` → `/hub/?type=<key>`.
- **Mobile:** the merged `hub-picker-in-tray` sheet is that same idea, from the Nav tray's Hub tile.

So the pattern is established: **the Hub has lenses hanging off it.** The temptation is to hang groups
there too. Don't — because **a type and a group are not the same kind of thing**:

| | content **type** | **group** |
|---|---|---|
| what it is | a **lens** on the firehose | a **place** |
| has an identity, members, a description, a Join button? | no | **yes** |
| correct URL | `/hub/?type=videos` — a filter | `/g/socal-looths` — a destination |

Merging them into one sheet **teaches the member that "SoCal Looths" is a kind of content, like
"Videos".** It isn't, and every later confusion follows from that first lesson. (It also makes the
sheet 5 types + ~16 groups = ~21 rows.)

**So: Groups gets its own Nav-tray tile (mobile) and its own entry (desktop), adjacent to the
content-type picker, both hanging off the Hub.** Adjacent in the nav, not merged in one control.

*The fair counter-argument, stated honestly:* fewer doors is simpler, and "narrow the Hub down" is one
mental model. **If you prefer one sheet, the cheapest honest version is one sheet with two clearly
titled sections** — "Show me" (types) and "Go to a group" (places) — with group rows visually distinct
(avatar + member count) so they cannot be mistaken for content types. **I mock this alternative too**
(mock N3b) so you can look at both rather than take my word for it.

### A.4 The prior ruling that decides where "my groups" lives

`webroot/bottom-nav.js:50–54`, **Ian/keeper 2026-06-24**, verbatim in the code: the Nav tray is
**PLACES ONLY** — *"Messages + Alerts are personal, not destinations, so they live in the You sheet
… NOT here."*

That ruling already splits this question, so I'm honouring it rather than re-litigating it:
- **A Groups directory** (browse what exists) is a **place** → **Nav tray tile.**
- **"My groups"** (my chapters, my subjects) is **personal** → **You sheet**, next to Messages and
  Notifications.

Same content, two doors, and the existing IA already says which is which.

### A.5 The location-suggested chapter — defer, and here is the actual blocker

It needs an IP→region signal, and the **only** one we have is the **Cloudflare visitor-location
managed transform** — which **Ian has not enabled yet**. The `dir-map-geoinit` lane (`@0ba9933`,
pushed, not merged) is **already blocked on exactly that toggle** and ships as a deliberate no-op
until it flips.

**Don't build a second consumer of a signal that isn't on.** When Ian enables it, the natural home is
a one-time dismissible banner on the Groups directory — *"Looks like you're near SoCal Looths — 827
members"* — **not** permanent nav furniture. Cheap to add then; pointless to build now.

---

## B. POSTING — should "+ New post" take you to the mini-hub?

**Ian's idea is right, and the data says it is *exactly* right for chapters and only *half* right for
subjects.** Here is the honest evaluation he asked for.

### B.1 The finding that decides it

I counted the **postable leaf forums per group** (live dev2 Postgres):

| group | postable leaf forums |
|---|---|
| **Repair And Restoration** | **9** — Acoustic Repair, Electric Repair, Finish Repair, Neck Reset Database, Touring Tech, … |
| New Builds | 7 |
| Tools, Spaces, Robots and Widgets | 6 |
| Business | 5 |
| Market Place | 2 — `SELL! SELL! SELL!` / `BUY! BUY! BUY!` |
| **every chapter** (SoCal, Ohio, Basque, Tri State…) | **1** — the chapter's own forum |

- **Chapters: 1 group = 1 forum.** Composing from the mini-hub is a **zero-choice post.** There is
  nothing to pick, ever. **Ian's idea works perfectly here, with no picker at all.**
- **Subjects: composing "in the group" does NOT remove the choice.** Repair still has 9 sub-forums and
  somebody still has to choose between "Acoustic Repair" and "Finish Repair". What it does is
  **scope** it: a 55-leaf interleaved tree with a phantom GENERAL becomes a **9-item flat list, inside
  a place you are already standing in.** That is a big win — but it is **not** "no picker", and the
  design must not pretend otherwise. **→ Q8.**

**A compounding trap worth naming out loud:** *"General" is also a REAL sub-forum.* Repair has a leaf
literally called **General**; Business has **General Business**. So today's tree shows a **phantom**
GENERAL *header* **and** a **real** General *leaf*. No wonder the thing reads as broken.

### B.2 The honest cost of the literal version

"+ New post" → **navigate to a mini-hub** → post is **more steps, not fewer**, for the member who just
wants to post something. It turns a **one-tap** composer into navigate-then-compose, and it does that
to **the highest-frequency action on the site.** That is a real regression, and it's the thing to
design around rather than accept.

### B.3 RECOMMENDATION — get Ian's win without the extra step

**Two doors into ONE composer:**

1. **From the Hub, "+ New post" stays exactly where it is — one tap, no navigation.** What changes is
   the *forum tree inside the modal*, which becomes a **group-first picker**:
   - **Step 1 — "Where does this go?"** → a flat list of ~16 **groups** (5 subjects · your chapters ·
     General). Real names. **No tree. No phantom GENERAL.**
   - **Step 2 — only when the group has more than one forum** → its sub-forums (2–9 items).
     **Chapters skip this entirely.**
2. **From a mini-hub, "+ New post" composes in context** — group already locked in, the composer reads
   **"Posting to SoCal Looths"**, and **for a chapter there is no picker at all.** *This is Ian's
   idea* — and it is where members who arrived via a chip will naturally post.

**Why this is cheap — the composer is ALREADY context-aware.** `bb-mirror/web/_chrome.php:308–312`
carries **`data-current-forum`**, and lines 270–282 already derive it from `?fid=` or the active forum
slug. **It pre-selects a forum from page context today.** The mini-hub does not need a new composer —
**it just has to supply the context the composer already reads.**

**Net: the mini-hub becomes the natural place to post — *pulled*, not *forced*.** Ian gets the real
destination he wants; the member who just wants to post doesn't pay a navigation tax to get it.

### B.4 The universality gap — this is why you need a real "General" group

The group-first composer only works if **every postable forum has a group.** Today **7 do not**:

| group-less leaf | topics | where it should go |
|---|---|---|
| **Quick Questions** | **181 (!)** | a real **General** group |
| Suggestion Box / Bug Reporting | 10 | a real **General** group |
| Total Vise · StewMac · Strings Micro Factory · Go Acoustic Audio | — | a **Sponsors** group (they are children of the misspelled `Sponsor Fourms` container and inherit its orphanhood — §6.5) |
| Middle Tennessee Looths (58440) | 0 | **delete** — the orphan duplicate (§6.5) |

→ **Create a real "General" group** (exactly the round-1 recommendation, §6.6 item 6) and a
**"Sponsors" group.** Then "General" stops being the null-case fallback that swallows anything
unclassified, and becomes an actual, joinable, opt-out-able group like every other.

**This is a hard prerequisite for the group-first composer** — without it the picker has content it
cannot represent, and you are back to a phantom bucket. Keeper spotted this dependency and was right:
the two questions really are one.

### B.5 ⚠️ The consequence of "chats are on hold" that is easy to miss — and it decides whether chapters live

Ian: *"everything can be done from discussions."* **For that to be true in practice, the composer has
to get lighter, and right now it does the opposite.**

**The evidence (round-1 §5):** across all ten chapters, **ever**, there are **33 wall posts, 4 forum
topics, 1 share** — ~38 posts of lifetime activity, newest 2026-04-30. **Chapter members overwhelmingly
chose the lightweight BuddyBoss activity wall-post over a forum topic.** They did not want a forum;
they wanted somewhere to say *"anyone around Saturday?"*

**And the current composer requires a title.** `_chrome.php:337–339` — `#ntm-title-in` is marked
`required`, with four more fields below it (body, tags, quick-tags, anon). **That five-field "New
topic" form is exactly the friction that kept the chapters empty.** If discussions are now the single
surface, and the discussion composer still demands a title for *"anyone around Saturday?"*, then
**chapters will stay as empty as they are today, for precisely the reason they are empty today.**

**Recommendation: for chapter posts, make the title OPTIONAL** — auto-derive it from the first line of
the body when it's blank — so a chapter discussion can be **one field and one tap**. Subjects keep the
required title (a titled topic is right for "Neck reset on a '58 Martin"). **This is what "discussions
absorb the chatter" has to mean mechanically**, and it is the one place where Ian's chat-deferral
ruling actually lands work on the build rather than removing it. **It is small — and without it, the
ruling doesn't hold.**

---

## C. ⚠️ GROUP MESSAGING SHIPPED TONIGHT — and it lands on this design in three ways

While this lane was paused, **group messaging merged and deployed** (main `778900c`+): multi-party
message threads with a **member-manager modal** (roster, add/remove, leave), **custom group names**,
an **owner badge** and **ownership transfer**. I did not know this when I wrote §A and §B. It changes
three things, and **one of them is a decision Ian has to make before any of this ships.**

### C.1 🔴 The word "group" now means two different things — and that is a real problem

As of tonight, a Looth member can create **"a group"** in Messages: it has **a name they chose**,
**members**, **an owner**, and a **member-manager modal** where you add and remove people.

This design also calls its chapters and subjects **"groups"**: they have **a name**, **members**, an
**organiser**, and (Phase 2) a **membership mirror**.

**Two things, same word, both shipping in the same month, and they are not the same thing:**

| | a **Messages** group *(shipped tonight)* | a **Hub** group *(this design)* |
|---|---|---|
| who makes it | **any member**, ad-hoc, in seconds | **staff** (Q3/Q4 — members can't) |
| who's in it | **people you picked** | **anyone who taps Join** — up to **1,841** |
| what it's for | a private conversation | a **public place** with a feed and a directory |
| can you find it? | no — it's yours | **yes, that's the point** (`/g/<slug>`, the directory) |

A member who reads *"Join SoCal Looths — 827 members"* three weeks after being taught that a "group"
is the thing they made with four friends **has been taught the wrong thing by us.** And the collision
is not cosmetic — it's in the nav: "My groups" in the You sheet (§A.4) would sit **directly next to
Messages**, which now also contains things called groups.

**→ Q10 (new, and it gates the nav copy — it is cheap now and expensive later).** My recommendation:
**keep "group" for the Messages feature** (it's shipped, members already see it, and it's the ordinary
meaning of the word) **and give this design's things their real names, which they already have:**
**Chapters** (places) and **Subjects** (topics). They are **not** two flavours of one noun to a member
— a member joins *a chapter* or follows *a subject*. So:

- Nav tray tile → **"Chapters"** (a place — and it's the location-shaped thing anyway, §A.5)
- You sheet → **"My chapters & subjects"**, *not* "My groups"
- The directory → `/chapters/` and the subject list lives on the Hub as filters
- **"Group" survives only as our internal schema word** (`bp_group`, `effective_group_id`) — where it
  is already the BuddyBoss term and no member ever sees it.

**The cost of deciding this now is zero — it's a string.** The cost of deciding it after the directory,
the chip and the You-sheet entry ship is a rename across every surface plus the URLs. **This is the one
thing in this round I'd ask Ian to answer even if he answers nothing else.**

### C.2 The member-manager modal is the idiom I was about to reinvent — reuse it

The shipped modal is **exactly** the control a mini-hub needs for its roster: avatar rows, a member
count, an **owner chip**, add/remove, and **Leave group**. The mini-hub's Members tab and its
**Join/Leave** button should be **the same component with a different data source**, not a lookalike.

- **It lowers Phase 4** (mini-hubs) — the roster is no longer a new build, it's a second caller.
- **It sets the ownership vocabulary for free.** Group messaging just established that
  `created_by` **means owner (mutable)**, that an ownerless thread is legal, and that **site admins can
  appoint an owner**. A chapter has an **organiser** — the exact same shape. Phase 2's membership
  mirror should adopt that vocabulary rather than mint a parallel one (BuddyBoss's `is_admin` flag in
  `wp_bp_groups_members` is already the same fact, §4).

### C.3 It does NOT revive the chat room — and it's worth being precise about why

The obvious reading is: *group messaging shipped, so a chapter chat is now free — just make a thread.*
**It isn't, and the reason is in §4's retained analysis, which this validates rather than obsoletes.**

Group messaging is built on `message_recipients` — **one row per member per thread, with a
denormalized `unread_count`**. That is correct and cheap for the **4-person thread it was designed
for**. A **1,841-member Repair chapter room would be 1,841 UPDATEs per message**, and its member
manager would render 1,841 rows. **The shipped feature does not scale to a group this design's size,
and it was never asked to.**

So the ruling stands unchanged: **chats stay deferred; discussions are the single surface.** What *has*
changed is the **revival path** — if Ian ever un-defers it, the cheapest version is **not** a new rooms
table but the §4 model (a `group_id` on `message_threads` + a **read-state watermark** instead of
recipient rows), and the FE is now **already built** — the shipped thread UI, member manager and naming
would all be reused. **Deferring it got cheaper tonight. Building it did not.**

**One real opportunity, though, and it's small:** §5 found two surviving groups with **no forum at
all** — `General Chat` (97 members) and `Charla General` (14). Those are **~111 people who want a
conversation, not a forum** — and a **111-member thread is within what shipped tonight can actually
carry.** If Ian wants to see a chapter-shaped chat work before committing to rooms, **that is the
pilot**, and it needs no new backend.

---

## 1. What I verified (the groundwork was right in outline, wrong in three places)

Checked against dev2 Postgres + WP. Not assumed.

**Confirmed:** `forums.bp_group` (20 groups, syncing today); `forums.forum_subscription` exists,
is well-shaped, and is **empty/unused** — the natural home for the feed filter; **GAP 1 is real**
(group membership is not mirrored to Postgres; rosters live in WP `wp_bp_groups_members`).

**Corrections — these change the cost:**

1. **`forums.forum.effective_group_id` already exists and is already materialized.** A recursive CTE
   in `bb-mirror/lib/materializers.php:181` walks `parent_forum_id` up to the owning group; 46 of 55
   forums have it. **The forum→group mapping the brief called "the biggest single unknown" is
   already solved and running in production.**

2. **GAP 2 is not what it looked like.** `discovery.content_item` holds **zero discussions** — it's
   WP CPTs only (352 videos, 166 loothprints, 65 articles…). The Hub feed is a **UNION of two
   branches** (`bb-mirror/web/forums/_feed.php:716`):
   - `forums.topic` → has `forum_id` → `effective_group_id`. **Group-aware for free.**
   - `discovery.content_item` → videos/articles/etc. `forum_label` is **empty** — the code says so
     itself (`_hub-filters.php:12`).

   So "group content also appears in the main Hub, filterable by group" is **nearly free for
   discussions** and **not applicable to CPTs**, because a video never belonged to a group. That's
   Q6. Don't budget a large indexing project for a gap that isn't there.

3. **The Hub already has a Category filter — and it is the bug.** `bb_mirror_cat_key()`
   (`bb-mirror/web/_chrome.php:35`) decides a post's category by **string-sniffing the forum slug**
   (`str_contains($slug,'repair')`…), with `effective_group_id` sitting right there unused. Nearly
   every defect in Part D falls out of this one function. **Replacing `bb_mirror_cat_key()` with
   `effective_group_id` is the keystone change** — it fixes the categories, kills the "General"
   bucket, and makes the Hub group-aware, all at once.

**Membership sync is cheaper than it looks:** `bb-mirror-sync.php` **already hooks
`groups_join_group` / `groups_leave_group`** (lines 250–254) — today they only refresh
`member_count`. Adding a `bp_group_member` mirror is a new table plus a handler on hooks that
already fire. ~12,400 rows. Small. **And with one-tap join, these hooks are exactly the write path
the Join button will use.**

---

## 2. Subscription semantics — simplified by the ruling (Q1)

Ian's ruling **collapses the hardest part of this design.** The old tension was that "leaving" a
group might make content unreachable, so the opt-out button carried a hidden cost. With everything
public and browsable, that cost is gone. So:

**Model membership and subscription as two independent things:**

- **Membership** — *am I in this group?* One tap, self-serve, **no approval**. It sets the roster,
  gates posting/chat (Q2), and drives "my groups" in the nav.
- **Subscription** — *does this group's content reach my main Hub feed?* **Purely a feed filter.**
  It has **no access consequences whatsoever** — hiding Market Place from your feed does not hide
  Market Place from you.

The two group types still have opposite *defaults*, and that's fine because it's now only a default,
not a permissions model:

| | Subject groups | Local Looth chapters |
|---|---|---|
| Members | ~1,841 = **everybody** (auto-joined by BuddyBoss) | 5–829, self-selected |
| So the control really is… | **opt-OUT** ("stop showing me Business") | **opt-IN** ("add SoCal to my feed") |
| Default | in | out |

"Leaving" a subject is therefore **not leaving at all** — it's flipping **Show in my Hub** off. You
stay a member, the mini-hub still works, the content stays searchable and linkable. It just stops
filling your feed. That is what a member means by "I don't care about Market Place", and it's
reversible in one tap. Mock 03 is exactly this pair of states.

**Storage:** `forums.forum_subscription` — already the right shape. `target_kind` is an enum, so add
a `'group'` value. Write rows **only on deviation from default**; absent row = default. Zero
backfill: the 9,205 rows for "1,841 members × 5 subjects, all opted in" never need to exist.

**The Hub query change is one `NOT IN`:**
```sql
-- in the topic branch of the feed UNION
AND f.effective_group_id NOT IN (
  SELECT target_id FROM forums.forum_subscription
   WHERE user_id = :me AND target_kind = 'group' AND muted_from_hub
)
```
Anonymous/logged-out: no rows, no filter, sees everything. Fails open — right for SEO, and the
`/hub/` 60s anon microcache (OPERATOR §7) stays valid because anon output is unchanged.

⚠️ **The one remaining trap:** a member who opts out of *all five* subjects has an almost empty Hub,
because subjects hold nearly all the content. Don't let the last one go silently — show "You've
hidden every subject. Your Hub will be quiet," with an undo.

---

## 3. Mini-hub

**URL: `/g/<group-slug>`.** Short, shareable, and it reads as a place. `/hub/?group=x` reads as a
filter and buries the group's identity in a query string. Use the **group** slug (`socal-looths`),
not the forum slug — forum slugs are already polluted (the real Middle Tennessee forum is
`middle-tennessee-looths-2`, because an orphan duplicate squats the clean slug — §6).

**One component, two configurations** (mocks 01 and 02 are the same code, different data):

- **Banner** — cover, badge, name, type pill (`LOCAL LOOTH` / `SUBJECT`), member count, description,
  and the actions: `Join` / `Joined ▾`, `Group chat`, `Share`.
- **Tabs** — Feed · Chat · Members · About.
- **Feed** — the group's own content, using the same feed-card component as the main Hub, so this is
  a **view, not a silo** (Ian's point 2). A post made here also appears in the main Hub, carrying the
  group chip (mock 06).
- **Sidebar — "Your participation"** — the feed toggle + notify toggle from §2. This is the "manage
  your participation" surface Ian asked for, and it sits where the content is, which is the only
  place a member will look for it.

**Non-member view (mock 04):** identical page, **fully readable**, with a one-tap `Join` where
`Joined ▾` would be. No teaser, no blur, no gate, no approval. This mock replaces the deleted
private-chapter design.

**Composer:** posting from `/g/socal-looths` pre-selects that group's forum — which is incidentally
the fix for the wizard bug in §6.

---

## 4. Group chats — ⛔ DEFERRED (Ian, 2026-07-12). Nothing below is being built.

> **Ian: "Lets go ahead and put the chats on hold. I think everything can be done from discussions."**
>
> **Deferred, not cancelled. Build none of it.** No room schema, no `room_read_state`, no
> notification-volume guard, no membership-derivation code. **Discussions are the single content
> surface for a group** — see **§B.5** for the one requirement this lands on the composer, which is
> load-bearing and easy to miss.
>
> **What this removed from the build:** the room was the **only genuinely novel piece** — the only new
> write path at scale, the only High-risk phase, and the only thing in this design that could not be
> assembled from parts we already own. See the re-cost in **§7**.
>
> **Keeping it extensible (keeper's ask), at zero cost today:** the only thing phases 0–5 must
> preserve is that **group identity lives in one place** — `forums.bp_group` + `effective_group_id` —
> and that **membership is mirrored** (Phase 2). A room, if it ever comes, keys off that same group id
> (`message_threads.group_id`) and derives its roster from that same membership mirror. **Nothing in
> the current plan forecloses it, and no schema is being added for it now.**
>
> The analysis below is **retained as-is, for whenever the room comes back.** It is not a plan of
> record. Skip to §5 unless you're reviving chats.

<details>
<summary>Retained (not being built): why a group chat must be a ROOM, not a DM thread</summary>

**The concrete reason** (from the schema, not a hunch):
- `message_recipients` carries a **denormalized `unread_count` per recipient.** A message to a
  1,841-member thread = **1,841 UPDATEs**, every message.
- Enumerating 1,841 rows on thread creation and rendering a peer list from them is the current
  messenger's model. It does not survive this.

**But the notification table is already room-shaped.** `notifications` has a *collapsing* unique
index — `uq_notifications_message UNIQUE (user_uuid, thread_id) WHERE type='message'`. One row per
user per thread no matter how busy. Notification volume is already bounded. Strong signal to
**reuse `message_threads` rather than build a parallel rooms table.**

### Recommended model

```
message_threads.group_id  bigint NULL   -- non-null  ⇒  this thread IS a group room
```
- **Membership: DERIVED, never enumerated.** "Can I post to room R?" = "am I in group G?"
  (`wp_bp_groups_members` → `wp_user_bridge` → `users.uuid`; the bridge exists, 1,835 rows).
  **No `message_recipients` rows for rooms at all.**
- **Read state: a watermark, not a counter.**
  ```
  room_read_state(user_uuid, thread_id, last_read_message_id, muted bool,
                  PRIMARY KEY (user_uuid, thread_id))
  ```
  One row per user per room, **written only when that user reads.** Unread =
  `COUNT(messages WHERE id > last_read_message_id)` — cheap on the existing
  `idx_messages_thread (thread_id, created_at)`. **A send becomes 1 INSERT, not 1,841 UPDATEs.**
- **Notifications: opt-in** above the size threshold (Q5). Mentions-only stays on.
- **Who may post:** members (Q2 — one tap to become one). **Moderation:** group organisers
  (`is_admin` in `wp_bp_groups_members` — already there) can delete a message and mute a member.
- **Shape: WhatsApp-flat.** Group name + avatar stack in the header, one message list, **no
  threading** (mock 05). Ian's call, and I agree — threading is a power-user idiom.

**The read-state watermark is the whole trick.** Everything else follows from refusing to
materialize 1,841 rows per room.

</details>

---

## 5. Group inventory — three are DEFUNCT, and two have no forum

Per Ian (2026-07-12): **The Jannies, Dank Memes and Music are defunct. Archive; do not surface; do
not publish their contents.**

| group | members | state | action |
|---|---|---|---|
| **The Jannies** | 2 | `hidden` moderator group, forums `23813`+`23819`, **16 topics** (internal to-do lists, "Money") | **Archive. Keep `hidden`.** Its forums are already `visibility='hidden'` and the feed filters `visibility='public'` at all 8 query sites — verified not leaking. **Do not flip it public. Do not migrate its topics.** |
| **Dank Memes** | 53 | `private`, **no forum**, 0 activity | Archive. Do not surface. |
| **Music** | 36 | `private`, **no forum**, 0 activity | Archive. Do not surface. |

⚠️ **That leaves a live trap — see §6.3.** Dank Memes and Music are themselves `status='private'`.
So a bulk "make the chapters public" migration written as *"flip every private group to public"*
would **publish the two groups Ian just told us to bury.** The migration must be an **explicit
allow-list of chapter ids**, never a predicate on `status='private'`.

**And two surviving groups have no forum at all:** `General Chat` (97 members) and `Charla General`
(14). They were BuddyBoss *social* groups — activity feed, no forum. A mini-hub for these has
**nothing to put in the Feed tab**, but they are *exactly* what group chat is for.
**Recommendation: launch them as chat-only mini-hubs** (Feed tab hidden). ~111 people, no forum
migration, no scale risk — **the ideal pilot for phase 4 before a 1,841-member room.**

**The chapters are empty, and I found where their content went.** Chapter forums hold almost no
topics (SoCal **0**, Tri State **0**, PNW 0, Basque 0, MT 0, DMV 3, Ohio 1). It didn't go to the
wrong forum — **it went into BuddyBoss group *activity updates* (wall posts), which bb-mirror has
never mirrored.** Across all ten chapters, ever: **33 wall posts, 4 topics, 1 share.** Newest
2026-04-30.

So the chapters are not a thriving thing being rendered badly — they're **~38 posts of lifetime
activity.** Don't budget a content migration. And note what it implies: **a chapter's members never
wanted a forum, they wanted a place to chat.** The chapter mini-hub's centre of gravity should be
the **chat room**, not the feed. That is the strongest argument here for doing group chat *early*.

---

## 6. PART D — the "3 chapters in General" defect. Found, proven, one bug.

**Ian's complaint and HK-052 are the same bug, and I can reproduce it.**

I ran the New-post wizard's real query and real grouping code against real data. It emits **8
headers, of which TWO are labelled "GENERAL", containing 1 and 3 forums** — *precisely* HK-052's
"two separate top-level groups both labelled 'GENERAL' (counts 1 and 3)". The second is:

```
HEADER[8]: GENERAL
      - Middle Tennessee Looths      (id=58440, eff_gid=NULL)   ← orphan duplicate
      - Ohio Local Looths            (id=60681, eff_gid=47)     ← a real chapter
      - Basque Country Looths        (id=60683, eff_gid=46)     ← a real chapter
```

**Three local Looth chapters, under a heading called GENERAL, in the composer you use to post a
discussion to the Hub.** Ian's sentence, verbatim, on screen. (Q7 — please confirm.)

### 6.1 Root cause — one line of code, not bad data

`bb-mirror/web/_chrome.php:323`:
```php
$label = $pid !== null ? $f['parent_title'] : 'General';
```
The wizard groups forums by **`parent_forum_id`** and calls anything parentless **"General"**. But
**chapter forums are parentless by design** — they attach to their group via `group_id`, not a parent
forum. So every chapter falls into "General". And because the query orders by
`COALESCE(f.parent_forum_id, f.id)`, parentless forums are *interleaved* between the parented runs,
so the run-length header logic emits **"General" more than once.**

Only 3 of 10 chapters appear because the other 7 are `visibility='private'` and filtered out. The 3
visible are the two **public** chapters (Ohio, Basque) plus the orphan duplicate.

There is already a band-aid in the query — `AND f.id NOT IN (67251, 3876)` hardcodes two orphans out
of the list. Someone hit this before and papered over it.

**Fix:** group by `effective_group_id` (which already exists), falling back to parent, labelled from
`bp_group.name`. Chapters then head up under their own names; only genuinely group-less forums land
in "General"; and grouping by a stable key instead of run-length means a label **cannot** appear
twice. The same change fixes `bb_mirror_cat_key()`, the Hub category filter, and the wizard together.

### 6.2 ⚠️ SEQUENCING — the ruling makes this bug WORSE if you don't fix it first

Today only **3** chapters sit under GENERAL, because 7 are private and filtered out of the wizard.
**Making the chapters public — which Ian's ruling requires — puts all 10 into that list. Under
GENERAL.** The bug goes from 3 chapters to 10.

**So: the §6.1 grouping fix (Phase 0) MUST land BEFORE the chapters are made public.** That ordering
is not a preference, it's a constraint. It also means Phase 0 is now on the critical path for the
ruling itself, not just a cleanup nicety.

### 6.3 ⚠️ The visibility migration must be an explicit allow-list

To make chapters public, these need flipping in **WP** (group status *and* forum visibility):

| group id | group | forum id |
|---|---|---|
| 38 | Tri State Looths (NYC) | 60685 |
| 39 | SoCal Looths | 58444 |
| 40 | SW Ontario Looths | 58450 |
| 41 | DMV Looths | 58452 |
| 42 | Looth Troop PNW | 58446 |
| 43 | Looths of Ireland | 58448 |
| 44 | Looth Group Partners | 48439 |
| 45 | Middle Tennessee Looths | 58442 |

*(46 Basque and 47 Ohio are already public.)*

**MUST NOT be touched by this migration:** `24 Dank Memes` and `22 Music` — **also `private`, and
defunct**; and `36 The Jannies` — `hidden`, defunct, 16 internal topics. **A migration expressed as
`UPDATE … WHERE status='private'` would publish Dank Memes and Music.** Use the id list above.

### 6.4 Where the fix must live — the sync-side trap

**`forum.group_id` and `visibility` are synced FROM WP**, derived from postmeta `_bbp_group_ids`
(`materializers.php:235`). **Any UPDATE in Postgres is overwritten on the next bb-mirror sync.** So:

- **Render/grouping bugs → fix in the repo** (`_chrome.php`, `_hub-filters.php`). Safe, reversible,
  no data change. **This is where nearly all the value is.**
- **Data defects (orphan forums, the visibility flip) → fix in WP**, then let the sync carry it to
  Postgres. **Never hand-patch Postgres.**

### 6.5 The data defects (9 orphans, not 5 — the groundwork undercounted)

| id | forum | topics | verdict |
|---|---|---|---|
| **58440** | Middle Tennessee Looths | 0 | **Orphan duplicate.** No group, no parent. Real chapter is 58442. It is **`public` while the real chapter is `private`**, and it **squats the clean slug** (forcing the real one to `middle-tennessee-looths-2`). → **delete in WP** (trash, don't purge) |
| 67251 | Anonymous Questions | 0 | closed, empty → **delete** (already hardcoded out of the wizard) |
| 34044 | **Sponsor Fourms** | 0 (8 via children) | **misspelled**, and it's a *category* whose 4 sponsor sub-forums (34046, 50121, 58199, 67776) inherit its orphanhood → **rename to "Sponsor Forums" + attach to a group** |
| 3876 | Quick Questions | **181** | genuinely group-less. **Do not delete.** → needs a real home |
| 4052 | Suggestion Box / Bug Reporting | 10 | genuinely group-less → same |

Also `48439 looth-group-partners` is **explicitly hardcoded out** of the chapter category
(`&& $slug !== 'looth-group-partners'`) and so falls into General too — fixed for free by §6.1.

**A near-miss I'm flagging on myself:** I first wrote up The Jannies' 16 topics as **leaking onto
the public Hub**, because `bb_mirror_cat_key()` does file them under `general`. **It is not a leak —
I checked.** Both forums are `visibility='hidden'`, the feed filters `visibility='public'` at all
eight of its query sites, and an anonymous `/hub/` fetch returns zero hits for their titles. My first
pass ran the category function over *all* forums regardless of visibility and manufactured a leak
that isn't there. The point that survives: **the only thing between a hidden group's content and the
public Hub is a `visibility` column the category function knows nothing about.** Group-awareness
makes that safety structural instead of incidental — which matters *more* now that a
visibility-flipping migration is on the table (§6.3).

### 6.6 Proposed cleanup — reversible, staged, NOT executed

Nothing below has been run. Needs keeper + Ian sign-off, and Q7 confirmed.

1. **Repo-only, zero data risk — do this FIRST (§6.2 makes it a hard prerequisite):** group by
   `effective_group_id` in `_chrome.php` + `_hub-filters.php`. Reversible with `git revert`.
2. **WP data, reversible:** rename `Sponsor Fourms` → `Sponsor Forums` (a post title edit).
3. **WP data, needs sign-off:** delete forum **58440** (0 topics, 0 replies — verified). Frees the
   clean slug. Recovery: it's a WP post — trash, don't purge.
4. **WP data, the ruling's migration:** publish the 8 chapters by **explicit id** (§6.3). **Only
   after step 1.**
5. **WP data, archival:** The Jannies / Dank Memes / Music — archive, keep out of every group list.
6. **A product call, not a cleanup:** `Quick Questions` (181 topics!), `Suggestion Box`, and the
   Sponsor tree have no group. My recommendation: **create a real "General" group** so it stops
   being the null-case fallback and becomes an actual, joinable, opt-out-able group like any other.

Verification for each: `SELECT` the before-state, apply in WP, run the bb-mirror reconcile, re-run
the wizard-render script (`docs/design/groups/verify-wizard-headers.php`) and confirm **exactly one**
General header with **no chapter under it**.

---

## 7. Costed build plan — RE-COST after the chat deferral

Each phase ships on its own and is useful on its own. Sizes are relative, not calendar.

### What dropped out, and what that means

**The old Phase 5 (group chat rooms) is gone.** It was size **L**, the **only High-risk phase**, and
the **only new write path at scale** — the one piece that could not be assembled from things we
already own (rooms cannot enumerate 1,841 members; per-user read state; notification volume). It was
also the only item carrying a *"load-test this before it goes live"* warning.

**With it gone, there is no unproven piece left in this build.** Everything that remains is **assembly
of parts that already exist:** `effective_group_id` (already materialized), the feed card component
(exists), `forum_subscription` (exists, correctly shaped, empty), the **context-aware composer**
(exists — §B.3), and the BuddyBoss join/leave hooks (**already firing today**, §1).

The deferral **adds** exactly one small thing: **§B.5, the optional title for chapter posts** —
without which "everything can be done from discussions" is not true in practice.

### The plan

| # | Phase | Size | Why here | Risk |
|---|---|---|---|---|
| **0** | **Group-awareness keystone** — replace `bb_mirror_cat_key()` string-sniffing with `effective_group_id`; fix the wizard grouping (§6.1). | **S** | Pure repo change, no new data. Fixes Ian's "3 chapters in General", HK-052, the double-GENERAL — **and it is what makes the group chip (§A.2) and the group-first composer (§B.3) possible at all.** **§6.2 makes it a hard prerequisite for publishing the chapters.** | Low. Category keys change → check the Hub filter chips + facet counts + any saved filter URLs. |
| **1** | **Homes for the group-less + the ruling's migration** — create a real **General** group and a **Sponsors** group (§B.4); publish the 8 chapters by **explicit id**; archive the 3 defunct (§6.3, §6.5). | **S** | **§B.4 makes this a hard prerequisite for the group-first composer** — the picker cannot represent a forum with no group. Also delivers Ian's public-chapters ruling. **Only safe after Phase 0.** | **Med — the one that can embarrass you.** A predicate-based migration (`WHERE status='private'`) **publishes Dank Memes + Music**, which Ian just told us to bury. **Allow-list of ids only**; verify The Jannies is still `hidden` afterwards. |
| **2** | **Membership sync** — `forums.bp_group_member` + handlers on the `groups_join_group` / `groups_leave_group` hooks that **already fire**. | **S** | Everything gates on "am I in this group", and this is the write path the one-tap Join button uses. ~12,400 rows. | Low. Backfill + reconcile like any other mirror. |
| **3** | **Nav / discovery** — the **group chip** on discussion cards (§A.2) · the **Groups directory** `/groups/` · **You-sheet "My groups"** (§A.4). | **S–M** | **The answer to Ian's question (a).** The chip is near-free and carries the volume; ship it first even if the other two slip. Needs Phase 0 for `effective_group_id`, Phase 2 for "my". | Low. Additive UI. Nav-tray tile is a one-line `DESTS` entry (`bottom-nav.js:55`). |
| **4** | **Mini-hubs `/g/<slug>` + compose-in-context** — the landing page, and "+ New post" there posting into that group (§B.3 door 2). | **M** | **The answer to Ian's question (b), and Ian's approved mocks.** Cheap now: the feed component, the filter, membership and **a composer that already reads page context** all exist — **and as of tonight the roster + Join/Leave is the shipped member-manager modal with a different data source, not a new build (§C.2).** | Low–Med. No private-chapter gating to build (round-1 ruling). |
| **5** | **Group-first composer** — replace the forum tree with group → (sub-forum) (§B.3 door 1); **optional title for chapter posts (§B.5)**. | **M** | Kills the forum tree and the phantom GENERAL for everyone, not just people standing on a mini-hub. **Blocked on Q8.** **Requires Phase 1** (§B.4). | Med. It is the highest-frequency action on the site — regressing it is expensive. Ship behind a flag; watch post-completion rate. |
| **6** | **Subscriptions + main-Hub feed filter** — `forum_subscription` gains `target_kind='group'`; two toggles; one `NOT IN` in the feed. | **M** | Delivers "manage your participation" and the opt-out. **Blocked on Q1.** | Med. Touches the feed UNION + the anon microcache path. **Anon must fail open.** |
| ~~7~~ | ~~**Group chat rooms**~~ | ~~**L**~~ | ⛔ **DEFERRED (Ian).** Kept extensible at zero cost — §4. | ~~High~~ |

### Named unknowns and hard constraints

- **Ordering is a constraint, not a preference:** **Phase 0 before Phase 1** (§6.2 — skip it and the
  "chapters under GENERAL" bug goes from 3 chapters to **10** the moment you publish them), and
  **Phase 1 before Phase 5** (§B.4 — the composer cannot represent a group-less forum).
- **Q1 gates Phase 6. Q8 gates Phase 5.** Don't start either before Ian answers.
- **Mobile.** Every surface needs its mobile counterpart **in the same phase** — the parity gate is
  standing policy, and the discussion surface is the modal on both viewports.
- **The location-suggested chapter is blocked on Ian's Cloudflare transform toggle**, same as the
  `dir-map-geoinit` lane (§A.5). Not scheduled.
- **The genuine risk has moved.** It used to be Phase 5 (rooms at scale). It is now **Phase 5's
  composer change** — not because it's technically hard, but because it touches **the single most
  frequent action on the site.** That's a UX risk, not a scale risk, and it wants a flag and a metric
  rather than a load test.
- *(Closed in this lane: "are the hidden group's topics leaking?" — no, verified, §6.5.)*

---

## 8. Mocks

`~/projects/groups-design-lane/mocks/` — mobile **390** + desktop **1280**, CDP-injected over the
untouched dev2 serve (real header, real fonts, real colour tokens). No overlay, ports 9700+.

### 8.1 Round 1 — the mini-hubs *(shot; Ian has approved these in principle)*

Source: `mocks/src/mock.js`, `mock-hubchip.js`.

| file | what |
|---|---|
| `01-minihub-chapter-socal-*` | Chapter mini-hub — SoCal Looths, 827 members, **public**, joined |
| `02-minihub-subject-repair-*` | **Same component**, subject group — Repair And Restoration, 1,841 |
| `03-optout-state-*` | **Opted OUT**: still a member, hidden from the main Hub, fully reachable here |
| `04-nonmember-browsable-*` | **Non-member view** — fully readable, one-tap Join, no approval |
| `05-group-chat-room-*` | ⛔ **VOID** — chats deferred (§4). Retained only as a record of the deferred design. |
| `06-mainhub-group-chip-*` | The **real** Hub, decorated: group chip on every card |
| `00-real-hub-1280` | Untouched baseline, for comparison |

### 8.2 Round 2 — navigation + posting *(SHOT 2026-07-12 23:0x — `mocks/round2/`, 15 PNGs)*

Source: `mocks/src/nav.js` + `cdp.js` + `shoot.sh` (written and ready to run).

**These mocks DECORATE the real components rather than rebuilding them** — they open the *real* Nav
tray / *real* You sheet / *real* composer and inject only the delta, **by cloning a real node and
re-labelling it**. So the styling in every shot is genuinely the house styling, not my imitation of
it. The only surface built from scratch is the Groups directory, because that page doesn't exist yet.

| shot | what it shows |
|---|---|
| **`P0-composer-TODAY-forum-tree`** | ⚠️ **THE BASELINE — no mock at all: the real composer, with its real forum list open.** Chapters stranded under a phantom **GENERAL**, which appears **twice** (HK-052, ringed red). **390** = the flat list, with Middle Tennessee / Ohio / Basque Country under GENERAL — **Ian's complaint, verbatim, on screen**. **1280** = the desktop accordion, where the duplicate reads as **GENERAL (1)** and **GENERAL (3)**. **This is the shot that makes the case.** |
| `P1-composer-step1-groups` | **Step 1** — groups, flat, real names, no tree, no phantom GENERAL. Includes the new **General** group (§B.4). |
| `P2-composer-step2-subforums-scoped` | **Step 2** — Repair's **9 real sub-forums**, scoped to the group. **The honest half of Ian's idea** (§B.1) — chapters skip this screen entirely. |
| `P3-composer-in-context-minihub` | **Ian's idea, realised** — "+ New post" *on* the mini-hub: "Posting to SoCal Looths", **zero picking**, and the **title is optional** (§B.5). |
| `N1-hub-card-group-chip` | The real Hub, decorated: **group chip** (tappable → `/g/<slug>`) *plus* the existing sub-forum chip, **kept**. Group = which place; sub-forum = which shelf. The groups are the **real parents from Postgres** (BUSINESS → "General Business"), not decoration — and the chip lands on **discussion cards only**: the video/loothprint cards in the same shot are deliberately bare, because they have no group (Q6). |
| `N2-groups-directory` | `/groups/` — the "what exists / is there one near me?" door. Chapters + Subjects, member counts, one-tap Join. |
| `N3a-navtray-groups-tile-RECOMMENDED` | **My recommendation** — Groups as **its own tile** in the Nav tray, sibling to the content-type picker. |
| `N3b-navtray-merged-picker-ALTERNATIVE` | **The alternative, shown honestly** — one sheet, two titled sections. Fewer doors, but it sits "SoCal Looths" in the same control as "Videos". **I don't recommend it; Ian should see it anyway** (Q9). |
| `N4-you-sheet-my-groups` | **"My groups" in the You sheet** — because the Nav tray is *places only* per Ian/keeper 2026-06-24 (§A.4). |

Mobile 390 for all; desktop 1280 for all except the tray/You-sheet shots, which are mobile-only
surfaces (their desktop counterpart is the header nav + the existing `lg-hubmenu`).

### 8.3 What shooting them actually taught us — three findings that are NOT cosmetic

Shooting against the real serve turned up things reading the code did not. All three are now in the
mocks, and **two of them are real costs the build has to carry.**

1. **The "3 chapters under GENERAL" defect is on BOTH composers, and the desktop one is worse.**
   The desktop composer is a **different component** from mobile's — an accordion of categories with
   leaf counts — so it renders the duplicate as literally **`GENERAL … 1`** and **`GENERAL … 3`**,
   which is *word for word* HK-052's "two top-level groups both labelled GENERAL (counts 1 and 3)".
   **P0-1280 is that shot.** (Mobile shows the same bug as a flat list — **P0-390**, with Middle
   Tennessee / Ohio / Basque Country stranded under a phantom GENERAL. That is Ian's sentence on
   screen, and it independently **confirms Q7**.) Whatever fixes §6.1 must fix **both** components.

2. **⚠️ The group chip does not fit on a mobile card for free.** At 390 the card's badge row has no
   room for another pill: the group chip shoved the DISCUSSION badge on top of the date. **The badge
   row has to wrap** (`flex-wrap`, +1 row of height on every discussion card). It is still cheap —
   but §A.2's "the chip slot already exists" was *slightly* too breezy, and this is the correction.

3. **The composer is the 4-step wizard now, not the old modal.** `hub-polish.js` keeps the real forum
   list (`#ntm-forum`) but hides it behind its own trigger. **Good news for §B.3:** the wizard's
   step 1 is *already* "Where does this go?" — so the group-first picker is a **content swap inside a
   step that already exists**, not a new flow. Ian's idea got cheaper again.

*(Harness notes, for whoever runs this next: `cdp.js` now fails loudly — a mock that navigates the
page used to close the devtools socket and exit **0 with no output**, which reads exactly like a
skipped shot. And screenshots are **viewport-only**: a full-page capture of the Hub feed blows Node's
WebSocket message cap. Both are fixed in-tree.)*
