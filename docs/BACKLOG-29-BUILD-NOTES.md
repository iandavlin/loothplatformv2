# The work board (backlog 29) — what to settle before building

**Written 2026-08-15 by the stripe-membership lane, at mock stage.** Nothing is
built. Ian has ruled the *shape* — now **one surface**: a ranked drag list with
per-item alerts, a modal per item, and an embedded chat (§1b supersedes the
earlier desk-plus-list). These are the things that decide the *build*, found
while drawing it.

Mocks: `/footer-mockups/wip-board/` (round 1, shape), `rank.html` (round 2,
drag-to-rank), `board.html` (round 3, one surface) and
**`final.html` (round 4 — THE ONE TO BUILD; supersedes 1–3)**.

> ⚠️ There is still no numbered entry for this in `docs/BACKLOG.md` — the file
> stops at 28. Scope here comes from keeper's brief and Ian's three rounds of
> rulings, not from a written backlog item.

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
