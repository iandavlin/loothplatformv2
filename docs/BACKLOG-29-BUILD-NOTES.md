# The work board (backlog 29) — what to settle before building

**Written 2026-08-15 by the stripe-membership lane, at mock stage.** Nothing is
built. Ian has ruled the *shape* — now **one surface**: a ranked drag list with
per-item alerts, a modal per item, and an embedded chat (§1b supersedes the
earlier desk-plus-list). These are the things that decide the *build*, found
while drawing it.

Mocks: **`concept.html` (the CONCEPT — the board as Ian's primary interface,
the one to build toward)**, `/footer-mockups/wip-board/` (round 1, shape),
`rank.html` (round 2,
drag-to-rank), `board.html` (round 3, one surface) and
**`final.html` (round 4 — THE ONE TO BUILD; supersedes 1–3)**.

> ⚠️ There is still no numbered entry for this in `docs/BACKLOG.md` — the file
> stops at 28. Scope here comes from keeper's brief and Ian's three rounds of
> rulings, not from a written backlog item.

---

## 0. STATUS — phase 1 is BUILT (2026-08-15)

Ian's nod: *"It's good enough to start building though. We can work through the
issues as they come up."*

**Shipped:** `webroot/wip-board.php` — read-only. `docs/BACKLOG.md` rendered as
its own PRIORITY INDEX, badges derived from each entry's text, lane lights and a
capacity strip off `~/.sentinel-status.json` (keeper's widened stamp, which
landed and is being read live). No WordPress boot: the sources are a markdown
file and a JSON stamp.

**Gate:** `tools/gates/work-board-gate.php`, 24 assertions, five mutations
measured. **Number still pending from keeper** — the ledger currently disagrees
with itself ("next free 41" in one place, "next free 43" in another), so this
lane has not minted one.

### The bug phase 1 nearly shipped, because it is a good one

The board **silently dropped every completed item**. Five P0 entries rendered as
three, and nothing logged.

Cause: `preg_split('/\R/', …)`. Without the `/u` flag, PCRE's `\R` also matches
the single byte **0x85** (NEL) — and 0x85 is the **third byte of `✅`**
(`E2 9C 85`). So the split cut in half every line containing a tick, leaving
fragments that are not valid UTF-8; `preg_match` with `/u` then returns **false**
on such a fragment *silently*, not as an error.

Fixed by splitting on newlines explicitly, never with `\R`. The gate's §1 now
asserts that **every** index line in the file reaches the render, checked against
an independent parse — because "the parser works" is not the property that
matters; "nothing is lost" is.

A second, separate loss was caught the same way: the parser matched only numeric
ids, dropping `E1`–`E5` and `S1`–`S3` — including a security item marked
awaiting Ian.

**Phase 2 is unchanged** and still carries everything below.

---

## 0b. THE AMBITION RAISED — the board as Ian's primary interface

Ian, 2026-08-15: *"a practical board that I would like to use to great effect.
Maybe even transition away from vs."* That changes the target: not a status page
but the place he works from. Concept drawn at `concept.html`; five principles,
all visible on it:

1. **The top answers three questions** before he scrolls — what waits on him,
   who is working, and **what changed since he last looked**. The digest is
   derived from git + board + stamp history, never typed.
2. **Plain English everywhere.** Project names, not lane names. Relative times.
   **No item numbers on the page at all** — they stay in the file, where they
   are our problem and not his.
3. **Every row wears its single next action** without a click, in words: *set
   the price*, *look at it*, *being worked on*, *nothing needed*. The page is
   readable without touching anything, which is what makes it a desk rather
   than a dashboard.
4. **The four things that would let VS Code go:** decision boxes as tappable
   buttons on the desk (always with a *Something else…*), a chat per item,
   pictures opening in the page, and live-command blocks with a copy button and
   somewhere to paste the output back.
5. **A trust chip** beside the stamp age — *all clear*, or the number red and
   their names. A stale page must not be able to look healthy.

**Build order, and why:** the desk and its buttons first. That is the part that
changes his day; everything else is presentation of work he can already see.

**Two judgement calls made in the drawing.** The desk comes first on the phone
rather than the digest, because on a phone he is nearly always checking one
thing. And the digest states what it filtered — *"81 changes reached the site
today. These are the four you'd care about"* — rather than implying four is all
that happened.

---

## 1. The hard constraint: the board cannot commit the obvious way

**`~/loothplatformv2-clean` is the SERVING CHECKOUT and only ever pulls.** That
is the rule that outranks everything else on this box, and the one that left
nginx dead after a reboot on 2026-07-26 when it was broken.

Checked, not assumed:

- the checkout is `ubuntu:ubuntu`, `drwxrwxr-x` — no write bit for others;
- every PHP-FPM pool runs as a **non-ubuntu** user (`membership`, `looth-dev`,
  `events`, `profile-app`, `bb-mirror`, `archive-poc`, `tool-dev`, `www-data`).

So a page served from the serving tree **physically cannot** write to
`docs/BACKLOG.md` there — and must not, even if the permissions were loosened.

### The three ways round it

| | Shape | Trade |
|---|---|---|
| **A** | Page writes an **intent file** to a spool dir; keeper applies it on its next pass | Safest, no new privilege. But Ian's drag does not land until keeper runs. |
| **B** ⭐ | A small **committer service** as `ubuntu` with its **own clone**, called over loopback with a shared secret; it edits, commits, pushes; the serve picks it up on the next pull | **Recommended.** Keeps the serving checkout pull-only, and the drag lands in seconds. |
| **C** | Let the web user own a **second clone that can push** | Simplest, and the most privilege — a web-facing user that can push to main. |

**B is recommended because it is not a new trust model**, only a new caller: the
billing app already calls the poller over loopback with a shared secret
(`WpSync::trigger` → `/wp-json/lg-member-sync/v1/sync-customer`). Same pattern,
same failure handling, nothing novel to reason about.

---

## 1b. The reshape (Ian, round 3) — and the chat bridge

Ian collapsed the desk-plus-list layout into **one surface**:

1. the ranked drag list **is** the board — no separate desk section;
2. every item carries an **alert** when there is work for him
   (*"some way to alert that there is work to do on the item"*);
3. clicking an item *"opens a modal with the work for me to do or the decisions
   for me to make"* — mockups, decision buttons, branches, notes, in the overlay;
4. an **embedded Claude chat** in the page, *"like there is on vs code"*.

### TWO CHATS, not one (Ian, 2026-08-15 — supersedes the shrunken button)

His words: *"There should be a general chat on the page for overview and then
sub chats on each board item/project."* So the side panel does **not** shrink to
a button. There are two distinct surfaces, and they answer different questions:

| | **General chat** | **Per-item threads** |
|---|---|---|
| Question it answers | *"How is everything?"* — overview, cross-cutting, "what should I look at first", anything with no obvious home | *"Why is THIS stuck?"* — bound to one item forever |
| Where it lives | A full surface on the page, not a demoted control | A tab inside each item's modal |
| What it leaves behind | A running conversation about the whole board | The item's own history, the way PR comments stay with the code |

**Both bridge to keeper**, over the same `msg` relay, for the same reason: it is
the brain already running the lanes, so it can answer *"how is everything"*
without being briefed first.

**Why two rather than one clever one.** A single chat would force every question
to be about something, and *"how's it all going?"* is the question he actually
asks most. A per-item thread cannot hold that, and a general chat cannot hold
*"why did we point the archive door at the Map?"* — which needs to be findable
from the item three months later. Round 4's mock demoted the general chat to a
button on the assumption that the item threads absorbed it; that assumption was
wrong, and he corrected it.

**Consequence for the build:** the general chat needs its own durable store
(one running thread), separate from the per-item threads, and both need the same
write fences as everything else that writes.

### The chat bridges to KEEPER, not to a fresh session

Recommended, and drawn that way: the chat relays through the existing `msg`
board to **keeper**, whose messages wake it and whose replies stream back.

**Why keeper rather than a separate Claude session:** keeper is already running
every lane, so it is the same brain that knows *why* an item is stuck. A fresh
session is a stranger who must be briefed before it can help — and briefing it
means duplicating the state keeper already holds, which is how two sources of
truth get born.

**Cost, and it must be said on the page:** replies are **seconds, occasionally up
to a minute** when a lane is mid-run. That is person-paced, not instant. The mock
states this in fine print, because a chat that *feels* broken is worse than one
that is honestly a little slow.

### Round 4, Ian's last two changes — both load-bearing

**1. Every decision carries an "Other…" free-text option.** He caught its
absence. Two buttons quietly assert that those are the only two answers, and
often they are not — which is exactly why the chat's own decision boxes always
carry one.

Build it as a **first-class answer, not an escape hatch**: what he types is
recorded as *his ruling in his words*, lands in the item's thread, and the lane
works to that rather than to whichever button was nearest. The worked example on
the mock is him rejecting both options on the author-archive question with *"only
list authors with 2+ posts"* — a perfectly reasonable answer neither button
offered.

**2. The chat moves INTO the item.** Each item gets its own thread (a tab in its
modal), kept with it forever — the pull-request-comments pattern. The side panel
shrinks to one **Ask keeper** button for questions belonging to no item.

Why it earns its place: comments on a PR work because they are stuck to the code
they are about. In three months, *"why did we point it at the Map instead of
building the index?"* is answered by opening the item rather than by anyone
remembering. It also means **a decision and the reasoning behind it live in the
same place** — the thing that goes missing first when a ruling is made in chat.

Consequences for the build:

- the thread is **per-item and durable**, so it needs a store keyed to the item —
  and, like the ranking, it must survive being written from a page that cannot
  write to the serving checkout (§1);
- a ruling made **in the thread** and one made **on the Decisions tab** must be
  the same event, or the two views will disagree about what was decided;
- the item's chat badge count comes from the thread — derived, per the rule below.

### Ian's two extra rails — lane lights and server capacity

Added to round 4: **a light per lane** (working / parked / waiting-on-Ian) and a
**server capacity strip** — *"so we can see if we are maxing out."*

Same law as the badges: **derived, never typed.** The source is the sentinel
stamp at `~/.sentinel-status`, which the build widens into a small JSON (per-lane
states + load/mem/swap/disk) that the page polls.

**⚠ The stamp is not yet what the rail needs.** Verified at drawing time: the
file exists and was 4 minutes old, but its content is
`<epoch> <time> working=0` / `? total=6` — while `lanes` showed all six seats
parked. So whatever it counts today is not the per-lane state this rail wants.
**The build must widen the stamp, not merely read it**, and the widening belongs
to whoever owns the sentinel — not to the board.

**Thresholds to mark on the bars** (so a number means something without a
legend): load **4.0** = the throttle line on 2 cores; swap **1 GB** = the stop
line; disk red at **90%**.

**Measured at drawing (dev2, 2026-08-15 15:44), not illustrative:** load 0.54 of
2 cores · memory 2.0 GB free of 7.8 · swap 0.4 GB of 2.0 · **disk 91% used, 2.6
GB free of 29**.

**The disk figure is a live problem, not a mock detail.** 91% is already over the
red line, so the first thing this rail would tell Ian is true and needs acting
on — reported to keeper separately rather than left to surface as a design
flourish. Rough split at the time: `/home` 11 GB, `/var` 7.7 GB, `/usr` 4.7 GB.
Nothing deleted — what goes is not this lane's call.

### Image support in the item thread (Ian, 2026-08-15) — a build requirement

Paste or drop a screenshot into any item's chat thread, as VS Code's chat does.
**No new mock round** — it is an attach affordance on the input already drawn.

*Why it matters, in Ian's pattern rather than as a feature:* his screenshots are
the fleet's best bug reports. Every one of them currently arrives by a side
channel and has to be described back into a lane. This puts the picture on the
item, permanently, next to the decision it caused.

**Spec as given:** upload endpoint dev-gated · stored in a board-media dir on the
monorepo serving path, **never the WP media library** · size cap a few MB · the
bridge message carries the **file path** so keeper reads the image natively · the
thread renders it inline.

#### The WP-media prohibition has a concrete reason — keep it

A WordPress upload is not a private file: it gets an **attachment post** with its
own public URL, and it surfaces in media search and galleries. A board screenshot
— which may show an admin screen, a member's data, or an unreleased feature —
would then be reachable from a member surface. *Board internals must not enter a
member-facing store.*

#### ⚠ "On the serving path" and "web-writable" are in tension — resolve it the way this box already does

Verified: `~/loothplatformv2-clean/webroot` is `ubuntu:ubuntu`, mode `drwxrwxr-x`
— **no write bit for others**, and every FPM pool runs as a non-ubuntu user. So
the upload endpoint **cannot write into the serving checkout**, exactly as the
ranking commits cannot (§1). And screenshots should not be committed to git
anyway — binaries bloat the repo forever.

The box already solves this shape: nginx serves several apps by `alias` to a path
**outside** the checkout (`/srv/lg-stripe-billing/public`, `/srv/thumb-app/`,
`/srv/lg-layout-v2/`). So:

- serve `board-media` by **alias from a directory outside the repo**, not from a
  folder inside the checkout;
- own it so the **web user can write** and **`ubuntu` (keeper) can read** — the
  bridge hands keeper a path, so keeper's own user must be able to open it;
- keep it **out of git**. The repo's `.gitignore` already has a *"bulk /
  non-source (not version-worthy)"* section for exactly this kind of content.

#### Two more constraints worth writing down now

- **Disk.** The box was at **91%** when this was specced. A few MB per screenshot
  with no retention rule is a slow leak on a full disk — cap the file size *and*
  decide a retention or a total budget at build time, not after.
- **What the thread stores is the path, not the bytes.** That keeps the thread
  cheap to render and lets keeper read the original natively; it also means a
  deleted file must render as a clear "image no longer stored", never as a broken
  image or a silent gap.

### The badge must be DERIVED, never typed

A badge reading "2 decisions" is only worth having if it is **counted from the
item itself**. Hand-maintained badges make the board one more thing to keep in
sync, and a stale badge is worse than no badge — it actively misleads. Same class
as gate 34b passing while the feature was broken: the display agreed with an
assumption instead of with the store.

---

## 2. The failure mode to design against

The drag will be **optimistic** — the card moves under Ian's finger before the
commit lands. If the commit then fails, **he has seen it move and the fleet never
learns**. That is [[trap-refused-save-reads-as-preserved]] in a new costume: the
screen says done, the store disagrees.

So, before it ships:

- the board must **show the commit landing**, and say so loudly when it does not;
- a failed re-rank must **snap back**, not sit there looking applied;
- gate it by making the committer fail on purpose and asserting the UI does not
  claim success — the assertion is on the STORE (`git log` / the file), never on
  the pixel.

Related: the same class already has a gate elsewhere in this repo, and the
lesson from gate 34b this week was that asserting a *decision* is not asserting
*reachability*. Here, asserting the drop handler ran is not asserting the file
changed.

---

## 3. Three questions only Ian can answer

Drawn onto `rank.html` so he can settle them by looking:

1. **Does dragging across a band change priority** (P1 → P0), or are bands fixed
   and a drag only re-orders within one? Assumed **across changes it**, since
   otherwise nothing can be promoted without editing the file by hand.
2. **"Something you can be updated on"** — drawn as a *since you last looked*
   strip. It could equally mean something that **reaches** him (email / phone
   notification when a lane needs him). One word settles it.
3. **Who else may drag?** Assumed **Ian only**; lanes and keeper keep editing the
   file as they do now, and his drag wins.

---

## 4. Why the file-backed model is the right one

`docs/BACKLOG.md` opens with this line, verbatim:

> `## PRIORITY INDEX (the order — edit THIS to re-rank; tell keeper "bump X")`

Keeper and every lane already treat that list as **the** ranking. So a drag is
not a widget update — it rewrites the list the fleet already obeys, and replaces
the "tell keeper bump X" ritual with the thing itself. Every re-rank keeps a git
history of who moved what and when.

Real shape as it stands today: four bands — **P0** (5 rankable lines), **P1**
(13), **P2** (20), **P3** (30).
