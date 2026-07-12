# GROUPS — mini-hubs, opt-in/out, group chats

**Status: DESIGN + MOCKS. No feature code written. Nothing merged.**
Lane: `groups-design` off main `6d21925`. Author: groups-design lane, 2026-07-12.
Mocks: `~/projects/groups-design-lane/mocks/` (CDP-injected over the real dev2 serve — the site
header, fonts and colour tokens in every shot are the real ones).

---

## 0. OPEN QUESTIONS FOR IAN — please answer these; everything else follows

Each has my recommendation. **Q1 is the one that changes the build.**

| # | Question | My recommendation |
|---|---|---|
| **Q1** | **Opting OUT of a subject — does it (a) hide that subject's posts from your MAIN Hub feed, or (b) only mute notifications?** | **(a) hide from the main Hub feed**, and mute notifications with it. That is what BuddyBoss's "leave a subject you don't care about" meant, and it is the only version that gives a member a reason to press the button. The mini-hub still shows everything, so nothing becomes *unreachable* — it just leaves your firehose. See §2. |
| **Q2** | A private chapter, seen by a non-member: invisible, teaser, or request-to-join? | **Teaser + request-to-join** (mock 04). Chapters are how you recruit locally — an invisible chapter can't grow. Show name, blurb, member count, organisers. Hide posts, members, chat. |
| **Q3** | Who may create a chapter? | **Staff only, for now.** A chapter is a real-world commitment (someone has to host the meetup). Members *request* one. Revisit once there are >15 chapters. |
| **Q4** | Can members create their own groups? | **No, not in v1.** You already carry 4 abandoned social groups (General Chat, Dank Memes, Music, Charla General — see §5). Member-created groups would multiply that. Ship mini-hubs first, see whether anyone asks. |
| **Q5** | Does a group chat notify all 1,841 members by default? | **No — off by default in any room over ~50 members** (mock 05). Mentions-only stays on. An unread badge still appears. Chapters (5–829) can default ON below the threshold. This is a *safety* default, not a preference: 1,841 × every message is an email/push incident. |
| **Q6** | Should non-discussion content (videos, articles, loothprints) belong to a group? | **Not in v1.** They genuinely have no group today — see §3, this is the single biggest scope trap. Discussions are group-aware for free; CPTs are not. |
| **Q7** | Part D: which 3 chapters did you mean? | I think I found them without needing you to name them — **Middle Tennessee, Ohio, Basque Country**, and I can prove it (§6). Please confirm. |

---

## 1. What I verified (the groundwork was right in outline, wrong in three places)

Everything below was checked against dev2 Postgres + WP, not assumed.

**Confirmed:**
- `forums.bp_group` — 20 groups, syncing today.
- `forums.forum_subscription` — exists, well-shaped, **empty and unused.** Natural home for opt-in.
- **GAP 1 real:** group membership is not mirrored to Postgres. Rosters live in WP
  `wp_bp_groups_members`.

**Corrections — these change the cost:**

1. **`forums.forum.effective_group_id` already exists and is already materialized.** A recursive
   CTE in `bb-mirror/lib/materializers.php:181` walks `parent_forum_id` up to the owning group.
   46 of 55 forums have it. **The forum→group mapping the groundwork called "the biggest single
   unknown" is already solved and running in production.**

2. **GAP 2 is not what it looked like.** `discovery.content_item` holds **zero discussions** — it is
   WP CPTs only (352 videos, 166 loothprints, 65 articles…). The Hub feed is a **UNION of two
   branches** (`bb-mirror/web/forums/_feed.php:716`):
   - `forums.topic` → has `forum_id` → `effective_group_id`. **Group-aware for free.**
   - `discovery.content_item` → videos/articles/etc. `forum_label` is **empty** — the code says so
     itself (`_hub-filters.php:12`).

   So "group content also appears in the main Hub, filterable by group" is **nearly free for
   discussions** and **not applicable to CPTs**, because a video never belonged to a group in the
   first place. That is Q6. Do not budget a big indexing project for a gap that isn't there.

3. **The Hub already has a Category filter** — and it is the bug. `bb_mirror_cat_key()`
   (`bb-mirror/web/_chrome.php:35`) decides a post's category by **string-sniffing the forum slug**
   (`str_contains($slug,'repair')`…), with `effective_group_id` sitting right there unused. Every
   defect in Part D falls out of this one function. **Replacing `bb_mirror_cat_key()` with
   `effective_group_id` is the keystone change** — it fixes the categories, kills "General", and
   makes the Hub group-aware, all at once.

**Membership sync is cheaper than it looks:** `bb-mirror-sync.php` **already hooks
`groups_join_group` / `groups_leave_group`** (lines 250–254) — they just refresh `member_count`
today. Adding a `bp_group_member` mirror is a new table + a handler on hooks that already fire.
~12,400 rows total. Small.

---

## 2. Subscription semantics — THE CRUX (Q1)

**The problem in one line: the two group types have opposite defaults, and one UI has to serve both.**

| | Subject groups | Local Looth chapters |
|---|---|---|
| Members | ~1,841 = **everybody** (auto-joined by BuddyBoss) | 5–829, self-selected |
| Real meaning of "join" | meaningless — you're already in | a real act |
| So the control is really… | **opt-OUT** ("stop showing me Business") | **opt-IN** ("let me into SoCal") |
| Default | in | out |

That asymmetry is the whole design problem. My resolution: **do not model it as one "membership"
flag. Model it as two independent things:**

- **Membership** — *am I in this group?* (chapters: opt-in, gated. subjects: everyone, always.)
- **Subscription** — *does this group's content reach me?* Two toggles, per group:
  - **Show in my Hub** — content appears in the main Hub feed. *(default ON)*
  - **Notify me** — new posts / chat. *(default OFF in big groups — Q5)*

Then "leaving" a subject is not leaving at all — it is flipping **Show in my Hub** off. You stay a
member, the mini-hub still works, the content is still findable in search and by visiting `/g/`.
It just stops filling your feed. That is what a member actually wants when they say "I don't care
about Market Place," and it is reversible in one tap. Mock 03 is exactly this state.

**Storage:** `forums.forum_subscription` — already the right shape. `target_kind` is an enum, so add
a `'group'` value; rows are written only on **deviation from default**. Absent row = default.
That means zero backfill: 1,841 members × 5 subjects = 9,205 rows we never have to write.

**The Hub query change** is then one `NOT IN`:
```sql
-- in the topic branch of the feed UNION
AND f.effective_group_id NOT IN (
  SELECT target_id FROM forums.forum_subscription
   WHERE user_id = :me AND target_kind = 'group' AND muted_from_hub
)
```
Anonymous / logged-out: no rows, no filter, sees everything. Fails open, which is right for SEO —
and the `/hub/` 60s anon microcache (OPERATOR §7) stays valid because anon output is unchanged.

⚠️ **The one trap:** if you pick (a), a member who opts out of *all five* subjects has an almost
empty Hub, because subjects are where nearly all the content is. Mitigate: never let the last one
be removed silently — show "You've hidden every subject. Your Hub will be quiet." with an undo.

---

## 3. Mini-hub

**URL: `/g/<group-slug>`.** Short, shareable, and it reads as a place. `/hub/?group=x` reads as a
filter and buries the group's identity in a query string; a chapter is not a filter, it's a room
with a door. Use the **group** slug (`socal-looths`), not the forum slug — the forum slugs are
already polluted (the real Middle Tennessee forum is `middle-tennessee-looths-2`, because an orphan
duplicate squats the clean slug — §6).

**One component, two configurations** (mocks 01 vs 02 are the same code, different data):

- **Banner** — cover, group badge, name, type pill (`LOCAL LOOTH` / `SUBJECT`), privacy pill,
  member count, description, and the actions: `Joined ▾` / `Join` / `Request to join`,
  `Group chat`, `Invite`.
- **Tabs** — Feed · Chat · Members · About.
- **Feed** — the group's own content: the same feed-card component as the main Hub, so this is a
  *view*, not a silo (Ian's point 2). A post made here appears in the main Hub too, carrying the
  group chip (mock 06).
- **Sidebar — "Your participation"** — the two toggles from §2. This is the "manage your
  participation" surface Ian asked for, and it lives where the content is, which is the only place
  a member will ever look for it.

**Private chapter, non-member** (mock 04): banner + description + member count + organisers, posts
blurred behind a `Request to join`. Answers Q2.

**Composer:** posting from `/g/socal-looths` pre-selects that group's forum — which, incidentally,
is the fix for the wizard bug in §6.

---

## 4. Group chats must be ROOMS, not DM threads

**The concrete reason** (not a vibe — I read the schema):
- `message_recipients` carries a **denormalized `unread_count` per recipient**. A message to a
  1,841-member thread = **1,841 UPDATEs**, every message.
- Enumerating 1,841 rows in `message_recipients` on thread creation, then rendering a peer list from
  them, is the mobile messenger's current model. It does not survive this.

**But the notification table is already room-shaped.** `notifications` has a *collapsing* unique
index — `uq_notifications_message UNIQUE (user_uuid, thread_id) WHERE type='message'`. One row per
user per thread, no matter how busy. Notification volume is already bounded. That is a strong
signal to **reuse `message_threads` rather than build a parallel rooms table.**

### Recommended model

```
message_threads.group_id  bigint NULL   -- non-null  ⇒  this thread IS a group room
```
- **Membership: DERIVED, never enumerated.** "Can I read room R?" = "am I in group G?"
  (`wp_bp_groups_members` → `wp_user_bridge` → `users.uuid`; the bridge exists, 1,835 rows).
  **No `message_recipients` rows for rooms at all.**
- **Read state: a watermark, not a counter.**
  ```
  room_read_state(user_uuid, thread_id, last_read_message_id, muted bool, PRIMARY KEY(user_uuid, thread_id))
  ```
  One row per user per room, **written only when that user reads.** Unread = `COUNT(messages WHERE
  id > last_read_message_id)`, cheap on the existing `idx_messages_thread (thread_id, created_at)`.
  A message send becomes **1 INSERT**, not 1,841 UPDATEs.
- **Notifications: opt-in** above the size threshold (Q5). Mentions-only stays on.
- **Who may post:** any member. **Moderation:** group organisers (`is_admin` in
  `wp_bp_groups_members` — already there) can delete a message and mute a member.
- **Shape: WhatsApp-flat.** Group name + avatar stack in the header, one message list, no threading
  (mock 05). Ian's call and I agree — threading is a power-user idiom.

**The read-state table is the whole trick.** Everything else follows from not materializing 1,841
rows per room.

---

## 5. The thing nobody has mentioned: 4 groups have no forum, and chapters are empty

Two facts that should shape expectations before anyone builds a mini-hub:

**(a) Four groups have no forum at all** — `General Chat` (97 members), `Dank Memes` (53), `Music`
(36), `Charla General` (14). They were BuddyBoss *social* groups: an activity feed, no forum. A
mini-hub for these has **nothing to put in the Feed tab.** They are, however, *exactly* what group
chat is for. **Recommendation: these four launch as chat-only mini-hubs** (Feed tab hidden). They're
a free, low-risk pilot for group chat — 200 people total, no forum migration needed.

**(b) The chapters are empty, and I found where their content went.** Chapter forums hold almost no
topics (SoCal **0**, Tri State **0**, PNW 0, Basque 0, MT 0, DMV 3, Ohio 1). It did not go to the
wrong forum — **it went into BuddyBoss group *activity updates* (wall posts), which bb-mirror has
never mirrored.** Across all ten chapters, ever: **33 wall posts, 4 topics, 1 share.** Newest is
2026-04-30.

So: **the chapters are not a thriving thing being rendered badly. They are ~38 posts of lifetime
activity.** Do not budget a content migration. And note what that implies — a chapter's members
never wanted a *forum*, they wanted a *place to chat*. **The chapter mini-hub's centre of gravity
should be the chat room, not the feed.** That is the strongest argument in this document for doing
group chat early rather than last.

---

## 6. PART D — the "3 chapters in General" defect. Found, proven, and it is one bug.

**I can reproduce Ian's complaint exactly, and HK-052 is the same bug.**

I ran the New-post wizard's real query and real grouping code against real data. It emits **8
headers, of which TWO are labelled "GENERAL", containing 1 and 3 forums** — *precisely* HK-052's
"two separate top-level groups both labelled 'GENERAL' (counts 1 and 3)". And the second one is:

```
HEADER[8]: GENERAL
      - Middle Tennessee Looths      (id=58440, eff_gid=NULL)   ← orphan duplicate
      - Ohio Local Looths            (id=60681, eff_gid=47)     ← a real chapter
      - Basque Country Looths        (id=60683, eff_gid=46)     ← a real chapter
```

**Three local Looth chapters, under a heading called GENERAL, in the composer you use to post a
discussion to the Hub.** That is Ian's sentence, verbatim, on screen. (Q7 — please confirm.)

### Root cause — one line of code, not bad data

`bb-mirror/web/_chrome.php:323`:
```php
$label = $pid !== null ? $f['parent_title'] : 'General';
```
The wizard groups forums by **`parent_forum_id`** and calls anything parentless **"General"**.
But **chapter forums are parentless by design** — they attach to their group via `group_id`, not via
a parent forum. So every chapter falls into "General". And because the query orders by
`COALESCE(f.parent_forum_id, f.id)`, parentless forums are *interleaved* between the parented runs,
and the run-length header logic emits **"General" more than once**.

Only 3 of the 10 chapters show up because the other 7 are `visibility='private'` and filtered out.
The 3 visible ones are the two **public** chapters (Ohio, Basque) plus the orphan duplicate.

**There is already a band-aid in the query** — `AND f.id NOT IN (67251, 3876)` hardcodes two orphans
out of the list. Someone hit this before and papered over it.

### The fix (and it is the same keystone change as §1.3)

**Group by `effective_group_id`, which already exists**, falling back to parent, and label from
`bp_group.name`. Chapters then head up under their own names (or a `LOCAL LOOTHS` heading); only
genuinely group-less forums land in "General"; and grouping by a stable key rather than run-length
means a label can never appear twice. Same change fixes `bb_mirror_cat_key()`, the Hub category
filter, and the wizard, together.

### ⚠️ Where the fix must live — the sync-side trap

**`forum.group_id` is synced FROM WP**, derived from postmeta `_bbp_group_ids`
(`materializers.php:235`). **Any UPDATE I make in Postgres is overwritten on the next bb-mirror
sync.** So:

- **Render/grouping bugs → fix in the repo** (`_chrome.php`, `_hub-filters.php`). Safe, reversible,
  no data change. **This is where ~all the value is.**
- **Data defects (the orphan forums) → fix in WP**, then let the sync carry it to Postgres. Never
  hand-patch Postgres.

### The data defects (9 orphans, not 5 — the groundwork undercounted)

| id | forum | topics | verdict |
|---|---|---|---|
| **58440** | Middle Tennessee Looths | 0 | **Orphan duplicate.** No group, no parent. Real chapter is 58442. **It is `public` while the real chapter is `private`**, and it **squats the clean slug** (forcing the real one to `middle-tennessee-looths-2`). → **delete in WP** |
| 67251 | Anonymous Questions | 0 | closed, empty → **delete** (already hardcoded out of the wizard) |
| 34044 | **Sponsor Fourms** | 0 (8 via children) | **misspelled**, and it's a *category* with 4 sponsor sub-forums (34046, 50121, 58199, 67776) that inherit its orphanhood → **rename to "Sponsor Forums" + attach to a group** |
| 3876 | Quick Questions | **181** | genuinely group-less. **Do not delete.** → give it a real home (see below) |
| 4052 | Suggestion Box / Bug Reporting | 10 | genuinely group-less → same |

**A near-miss worth recording, because it shows the fragility:** `23813 the-jannies-3` and
`23819 change-log` (16 topics) belong to **The Jannies — a `hidden` moderator group** — and
`bb_mirror_cat_key()` *does* sort them into **`general`**, because their slugs match none of its
string patterns. I initially wrote this up as a live disclosure. **It is not — I checked.** Both
forums carry `visibility='hidden'`, and the feed filters `visibility='public'` at every one of its
eight query sites, so they never render; an anonymous fetch of `/hub/` returns zero hits for their
titles. (My first pass ran the category function over *all* forums regardless of visibility, which
manufactured a leak that isn't there. Flagging the correction rather than quietly dropping it.)

The point that survives: **the only thing standing between a hidden group's content and the public
Hub is a `visibility` column that a category function knows nothing about.** `bb_mirror_cat_key()`
is happy to file moderator content under "General" — it is simply saved by a filter applied
elsewhere. Group-awareness makes that safety *structural* instead of incidental.

And `48439 looth-group-partners` is **explicitly hardcoded out** of the chapter category
(`&& $slug !== 'looth-group-partners'`) and so falls into General too.

### Proposed cleanup — reversible, staged, NOT executed

Nothing below has been run. It needs keeper + Ian sign-off, and Q7 confirmed.

1. **Repo-only, zero data risk (do this first, it fixes the visible bug):** group by
   `effective_group_id` in `_chrome.php` + `_hub-filters.php`. Reversible by `git revert`.
2. **WP data, reversible:** rename `Sponsor Fourms` → `Sponsor Forums` (a post title edit).
3. **WP data, needs sign-off:** delete forum **58440** (0 topics, 0 replies — verified). Frees the
   clean slug. Recovery: it's a WP post; trash, don't purge.
4. **WP data, needs a decision:** attach `Quick Questions` (181 topics!), `Suggestion Box`, and the
   Sponsor tree to groups — or accept a *single*, correctly-labelled "General" bucket. **This is a
   product call, not a cleanup:** those 181 topics are real content and "General" may be an honest
   name for them. My recommendation: create a real **"General"** group so it stops being the
   null-case fallback and becomes an actual, joinable, opt-out-able group like any other.

Verification for each: `SELECT` the before-state, apply in WP, run `bb-mirror` reconcile, re-run the
wizard-render script (`/tmp/wiz.php`, reproduced in this branch) and confirm exactly **one** General
header and no chapter under it.

---

## 7. Costed build plan

Each phase ships on its own and is useful on its own. Sizes are relative, not calendar.

| # | Phase | Size | Why here | Risk |
|---|---|---|---|---|
| **0** | **Group-awareness keystone** — replace `bb_mirror_cat_key()` string-sniffing with `effective_group_id`; fix the wizard grouping; Part D §6.1. | **S** | Pure repo change, no new data. Fixes Ian's "3 chapters in General", HK-052, the double-GENERAL, and the hidden-group leak — and *every later phase depends on it*. | Low. Category keys change → check the Hub filter chips + any saved links. |
| **1** | **Membership sync** — `forums.bp_group_member` + handlers on the `groups_join_group`/`groups_leave_group` hooks that **already fire**. | **S** | Everything gates on "am I in this group". ~12,400 rows. | Low. Backfill + reconcile like any other mirror. |
| **2** | **Subscriptions + main-Hub filter** — `forum_subscription` gains `target_kind='group'`; two toggles; one `NOT IN` in the feed. | **M** | Delivers Ian's "manage your participation" and the opt-out he actually wants. **Blocked on Q1.** | Med. Touches the Hub feed UNION + the anon microcache path. Anon must fail open. |
| **3** | **Mini-hubs** `/g/<slug>` | **M** | Now cheap: the feed component, the filter, and membership all exist. Chapters + subjects + the 4 chat-only groups. | Med. Private-chapter leak-safety is URL-and-data-layer (DISCUSSION-SURFACE-CANON) — a private group's topic must be unfetchable at its direct URL, not merely unrendered. |
| **4** | **Group chats (rooms)** — `message_threads.group_id`, derived membership, `room_read_state` watermark, notifications off by default. | **L** | The real prize, and per §5(b) the thing chapters actually need. | **High.** The only phase with a new write path at scale. Pilot it on the 4 forum-less social groups (~200 people) before pointing it at a 1,841-member subject. |

**Named unknowns:**
- **Q1 gates phase 2.** Don't start it before Ian answers.
- **Mobile.** Every surface here needs the mobile counterpart at the same time — the parity gate is
  standing policy, and the discussion surface is the modal on both viewports.
- **Phase 4 read-state at scale** is the one genuinely unproven piece. The watermark model is sound
  on paper; it wants a load test before a 1,841-member room goes live.
- **Phase 0 changes category keys.** `bb_mirror_cat_key()`'s output currently feeds the Hub filter
  chips. Swapping to group ids changes those keys — check for saved/bookmarked filter URLs and the
  facet-count query before shipping.
- *(Closed during this lane: "are the hidden group's topics leaking?" — no, verified. §6.)*

---

## 8. Mocks

`~/projects/groups-design-lane/mocks/` — mobile 390 + desktop 1280, CDP-injected over the live dev2
serve (real header, real fonts, real colour tokens). Source: `mocks/src/mock.js`, `mock-hubchip.js`.

| file | what |
|---|---|
| `01-minihub-chapter-socal-*` | Chapter mini-hub — SoCal Looths, 827 members, private |
| `02-minihub-subject-repair-*` | **Same component**, subject group — Repair And Restoration, 1,841 |
| `03-optout-state-*` | Opted OUT: still a member, hidden from main Hub, reachable here |
| `04-private-chapter-nonmember-*` | Private chapter seen by a non-member — teaser + request to join |
| `05-group-chat-room-*` | Group chat room — WhatsApp-flat, avatar stack, notifications off by default |
| `06-mainhub-group-chip-*` | The **real** Hub, decorated: group chip on every card. Dashed outline = the free-text category chip it replaces |
| `00-real-hub-1280` | Untouched baseline, for comparison |

Note in 06 that the group chip reads **REPAIR AND RESTORATION** (the group) while the chip it
replaces reads **ACOUSTIC REPAIR** (a sub-forum). They are different things and the design keeps
both: **group chip = which group**, existing label = which sub-forum.
