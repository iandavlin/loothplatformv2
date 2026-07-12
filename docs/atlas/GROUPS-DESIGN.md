# GROUPS — mini-hubs, opt-in/out, group chats

**Status: DESIGN + MOCKS. No feature code written. No data changed. Nothing merged.**
Lane: `groups-design` off main `6d21925`. Author: groups-design lane, 2026-07-12.
Mocks: `~/projects/groups-design-lane/mocks/` (CDP-injected over the real dev2 serve — the site
header, fonts and colour tokens in every shot are the real ones).

> **Revised 2026-07-12 after Ian's ruling:** chapters are **NOT private**. BuddyBoss "private" was
> only ever a crude opt-in mechanism, not a privacy intent. **Everything is public and browsable;
> subscription is purely a FEED filter; joining is one-tap self-serve with no approval.** The
> private-chapter / teaser / request-to-join design is deleted. Separately: **The Jannies, Dank Memes
> and Music are DEFUNCT** — archive them, do not surface them, do not publish their contents.
> This ruling **simplifies the product and complicates the data migration** — see §6.

---

## 0. OPEN QUESTIONS FOR IAN

Ian's ruling closed the big one (was Q2: private-chapter visibility — **moot, chapters are public**).
What's left, each with my recommendation. **Q1 still gates the build.**

| # | Question | My recommendation |
|---|---|---|
| **Q1** | **Opting OUT of a subject — does it (a) hide that subject's posts from your MAIN Hub feed, or (b) only mute notifications?** | **(a) hide from the main Hub feed**, and mute notifications with it. Your ruling makes this cleaner, not harder: because everything stays public and browsable, hiding a subject from your feed costs a member *nothing* — the content is still one click away at `/g/<slug>`, still searchable, still linkable. The feed filter is now purely about **your firehose**, with no access consequences at all. See §2. |
| **Q2** | **Is the group CHAT open to non-members too, or does it need the one-tap join?** *(new — falls out of your ruling)* | **Join required to post; readable by anyone.** Reading keeps faith with "everything public + browsable"; requiring the tap to *post* is what makes the roster mean anything and gives the chat room a defined membership. It's one tap, so it isn't a barrier. |
| **Q3** | Who may create a chapter? | **Staff only, for now.** A chapter is a real-world commitment (someone hosts the meetup). Members *request* one. Revisit past ~15 chapters. |
| **Q4** | Can members create their own groups? | **No, not in v1.** You are about to archive three dead groups (§5). Member-created groups would manufacture more. Ship mini-hubs, see if anyone asks. |
| **Q5** | Does a group chat notify all 1,841 members by default? | **No — off by default in any room over ~50 members** (mock 05). Mentions-only stays on; an unread badge still shows. Chapters below the threshold can default ON. This is a *safety* default, not a preference: 1,841 × every message is an email/push incident. |
| **Q6** | Should non-discussion content (videos, articles, loothprints) belong to a group? | **Not in v1.** They genuinely have no group today — see §1.2. Discussions are group-aware for free; CPTs are not. |
| **Q7** | Part D: which 3 chapters did you mean? | I believe I found them without needing you to name them — **Middle Tennessee, Ohio, Basque Country** — and I can prove it (§6). Please confirm. |

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

## 4. Group chats must be ROOMS, not DM threads

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

## 7. Costed build plan

Each phase ships on its own and is useful on its own. Sizes are relative, not calendar.

| # | Phase | Size | Why here | Risk |
|---|---|---|---|---|
| **0** | **Group-awareness keystone** — replace `bb_mirror_cat_key()` string-sniffing with `effective_group_id`; fix the wizard grouping (§6.1). | **S** | Pure repo change, no new data. Fixes Ian's "3 chapters in General", HK-052, the double-GENERAL. **§6.2 makes it a hard prerequisite for publishing the chapters** — skip it and the bug goes from 3 chapters to 10. | Low. Category keys change → check the Hub filter chips + facet counts + any saved filter URLs. |
| **1** | **Publish the chapters + archive the defunct** — the ruling's data migration, by **explicit id** (§6.3, §6.5). | **S** | Ian's ruling. Cheap, but **only safe after Phase 0.** | **Med — this is the one that can embarrass you.** A predicate-based migration publishes Dank Memes + Music. Allow-list only; verify The Jannies is still `hidden` afterwards. |
| **2** | **Membership sync** — `forums.bp_group_member` + handlers on the `groups_join_group`/`groups_leave_group` hooks that **already fire**. | **S** | Everything gates on "am I in this group", and this is the write path the one-tap Join button uses. ~12,400 rows. | Low. Backfill + reconcile like any other mirror. |
| **3** | **Subscriptions + main-Hub filter** — `forum_subscription` gains `target_kind='group'`; two toggles; one `NOT IN` in the feed. | **M** | Delivers "manage your participation" and the opt-out Ian wants. **Blocked on Q1.** | Med. Touches the feed UNION + the anon microcache path. Anon must fail open. |
| **4** | **Mini-hubs** `/g/<slug>` | **M** | Now cheap: feed component, filter, and membership all exist. | Low–Med. Simplified by the ruling — no private-chapter gating to build. |
| **5** | **Group chats (rooms)** — `message_threads.group_id`, derived membership, `room_read_state` watermark, notifications off by default. | **L** | The real prize, and per §5 the thing chapters actually need. | **High.** The only phase with a new write path at scale. **Pilot on General Chat + Charla General (~111 people, no forum) before pointing it at a 1,841-member subject.** |

**Named unknowns:**
- **Q1 gates phase 3.** Don't start it before Ian answers.
- **Q2 (chat open to non-members?)** gates the room's read ACL in phase 5.
- **Ordering is a constraint, not a preference:** Phase 0 **before** Phase 1 (§6.2).
- **Mobile.** Every surface needs its mobile counterpart in the same phase — the parity gate is
  standing policy, and the discussion surface is the modal on both viewports.
- **Phase 5 read-state at scale** is the one genuinely unproven piece. The watermark model is sound
  on paper; load-test it before a 1,841-member room goes live.
- *(Closed in this lane: "are the hidden group's topics leaking?" — no, verified, §6.5.)*

---

## 8. Mocks

`~/projects/groups-design-lane/mocks/` — mobile **390** + desktop **1280**, CDP-injected over the
live dev2 serve (real header, real fonts, real colour tokens). Source: `mocks/src/mock.js`,
`mock-hubchip.js`.

| file | what |
|---|---|
| `01-minihub-chapter-socal-*` | Chapter mini-hub — SoCal Looths, 827 members, **public**, joined |
| `02-minihub-subject-repair-*` | **Same component**, subject group — Repair And Restoration, 1,841 |
| `03-optout-state-*` | **Opted OUT**: still a member, hidden from the main Hub, fully reachable here |
| `04-nonmember-browsable-*` | **Non-member view** — fully readable, one-tap Join, no approval *(replaces the deleted private/teaser mock)* |
| `05-group-chat-room-*` | Group chat room — WhatsApp-flat, avatar stack, notifications off by default |
| `06-mainhub-group-chip-*` | The **real** Hub, decorated: group chip on every card. Dashed outline = the free-text category chip it replaces |
| `00-real-hub-1280` | Untouched baseline, for comparison |

In mock 06 the group chip reads **REPAIR AND RESTORATION** (the group) while the chip it replaces
reads **ACOUSTIC REPAIR** (a sub-forum). Different things, and the design keeps both: **group chip =
which group**, existing label = which sub-forum.
