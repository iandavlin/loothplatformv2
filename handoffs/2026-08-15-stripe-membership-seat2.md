# stripe-membership — handoff, 2026-08-15 (seat 2, later still)

**Read the predecessor's `handoffs/2026-08-15-stripe-membership.md` and
`docs/STRIPE-LANE-BRIEF.md` first.** Both are still accurate except where this
file says otherwise. This file is only what changed on my watch.

Branch `stripe-membership`, rebased on `origin/main`.

---

## What I was given, and what happened to it

| Job | Outcome |
|---|---|
| **Charter extension (8/16): talk to lanes from the board** | **BOARD HALF DONE**, relay half designed and posted for keeper's review, not built. |
| **1. Wire the board's write layer** | **DONE AND DEPLOYED.** Drag-rank, notes, decisions — all three land through the committer. |
| *(the charter's fallback)* **The board history view** | **DONE** — I was never blocked, so it became the next item rather than the substitute. |
| **2. Rotate the leaked sandbox key** | **CANCELLED BY IAN** mid-session: *"keep sandbox keys. not worth rotating."* I touched no key. |
| **3. The stranded `GerryHayesTest` note** | **VERIFIED AND ACTED ON** — it is genuinely ours; it is on the dev2 list. |

---

## JOB 3 — GerryHayesTest is ours (and how that was settled)

I did not take the stranded note on trust; a sentence of unverified origin in an
input box is not authority. Five checks, and the second is the one that decides
it:

1. **Gerry Hayes is staff** — wp user 4, `administrator` + `bbp_keymaster` on
   **dev2 and live**, and on this box's team roster (`packs-team/gerry.zip`,
   a `msg` user).
2. **`GerryHayesTest` (854) does not exist on live at all.** Live has only his
   staff account and his real member account (853). Same fingerprint as all
   three staged fixtures — none of those are on live either.
3. Created `2024-04-24 19:41:38`, **three minutes after** his own Patreon-linked
   member account 853 (`19:38:26`). Somebody walking the join flow twice.
4. Plain `subscriber`, **no membership tier**; his real accounts carry `looth3`
   and `looth2`.
5. Zero posts, and `last_activity` **equals its registration second** — never
   used since the minute it was made.

**The predecessor's objection is answered, not overridden.** They held it back
because `@hey.com` is a real-looking personal address. But the list already
carried `1887 qa-disposable` on `ian.davlin+qaadmin1@gmail.com` — a real,
deliverable personal inbox belonging to a team member. A real-looking address was
never the disqualifier; **belonging to a real member** was, and this belongs to
staff.

Added via `LGMS\CohortAllowlist::add(854)` — the canonical writer, because it
also stamps the companion `_added` map the dash reads. A hand `wp option update`
would have left the two disagreeing. List is now `[854,1887,1938,2047]`.

**The safety catch was re-measured AFTER the add**, not assumed to have survived
it: lifecycle absent, identity gate absent, `pages_live` 0, `testgroup_pages`
absent, `frozen` 1. All four fixtures are loaded and **nothing is unlocked**.

---

## JOB 1 — the write layer

### It is deployed and running, right now

`board-committer.socket` + `board-committer@.service` (committed at
`deploy/board-committer/`), socket-activated, one short-lived process per
connection, running as `ubuntu`. **Enabled**, and proven to survive a restart.

**A UNIX socket, not the loopback port the design named** — a deliberate upgrade:
a port is reachable by every user on this box, a socket is reachable by exactly
the users its mode names. Proven **both ways**: the `looth-dev` pool that serves
the board *can* call it and land a real commit; `buck` gets `Permission denied`.

> **The blast radius, stated rather than glossed:** the whole WordPress stack
> runs as `looth-dev`, so any PHP on that site can reach this socket. A token
> would not help — the same user could read it. That is not a hole being papered
> over; it is why the committer's fences exist. The worst a compromised WP can do
> through here is reorder `BACKLOG.md` or append a quoted note.

### ⚠️ ONE FILE TO DELETE AT MERGE

`/etc/systemd/system/board-committer@.service.d/10-pre-merge.conf`, then
`sudo systemctl daemon-reload`.

Until this branch lands, neither the listener nor the committer exists in the
serving checkout, so that drop-in points both at **this worktree** — which dies
with the lane. The listener **logs a PRE-MERGE line to the journal on every
call** while it is in force, so a forgotten override announces itself instead of
quietly serving a stale tree. After merge the unit runs from
`~/loothplatformv2-clean`, which is pull-only and therefore always a commit of
main.

### Two defects found — both by gating, neither by reading

**1. The committer could silently DELETE an item.** Ids in the priority index are
**not unique** (the file carried `9` twice until 2026-08-15). With a duplicate,
the permutation check **passes** while the rewrite keeps only one of the two
lines and writes it over **both** slots. Measured before fixing, not reasoned
about. An ambiguous index is now **refused** and the duplicate **named** —
guessing which `9` a drag meant is a coin flip that destroys an item when it
loses. Red-first: fence removed → 3 assertions bite.

**2. A drag was refused in any project that had ever finished anything.** Done
rows are not draggable, so a drag returns the **open** rows — and the server was
comparing that against the project's whole membership. My hand test passed only
because it happened to pick the one project with nothing done in it. The gate
driving a real project found it in one run.

### The design decision keeper may want to overrule

The committer pushes to **main**; the board is served from `loothplatformv2-clean`,
which only ever pulls, and **nothing on this box pulls it on a timer** (checked).
So a drag would land and then appear to **vanish** on the next reload — exactly
the failure the build notes named.

I did **not** make the committer pull the serving checkout, even though a pull is
the sanctioned operation there: a card drag would then deploy every other lane's
merged commits as a side effect, and a pull does not carry the mu-plugin symlink
step. That is keeper's and Ian's call, not a side effect of a UI gesture.

Instead **the board reads main's copy when the served one is behind, and says so
on the page.** Not a second source of truth — the clone is `reset --hard` to
`origin/main` at the start of every write, so it is main or it is nothing.
**Compared by content hash, not size:** a reorder rewrites the same lines in a
different order and changes **no bytes at all**.

### Shapes and how they map

| Ian's gesture | Intent sent | Note |
|---|---|---|
| Drag inside a project | `reorder` | The project's items keep the **slots** they already occupy; only their order within those slots changes. Cannot fail the permutation rule by construction, and a drag in Membership cannot disturb Guitardle. |
| Add a note | `note_append` | |
| Press a decision button (or *Something else…*) | `note_append` | **A decision is a note, deliberately.** The build notes required that a ruling in the thread and one on the Decisions tab be the *same event*; writing the same store guarantees it rather than maintaining it. |

**Decision options are READ, never invented** — from `docs/board-decisions/<id>.md`,
one option per `- ` line. Where nobody asked a question, **no buttons appear**.
BACKLOG.md cannot derive "Retract to free"; that is a question somebody asked, and
inventing one would put words in Ian's mouth.

**The actor is a server-side constant** (`LGB_ACTOR`). An actor read from the
request is not an identity, it is a text field — and the committer would stamp a
forged name into the commit and believe it.

---

## Gates

| Gate | Was | Now | |
|---|---|---|---|
| committer (**number still owed**) | 33 | **37** | + the ambiguous index |
| 50 — work board | 59 | **80** | + the endpoint driven over real HTTP, + the source-of-truth preference in three states |

Gate 50's *"phase 1 cannot write"* section is now *"the page writes only through
the committer"* — an assertion kept past the point where it was true is how a
gate starts blocking the merge train instead of protecting it.

Four mutations measured on the new page-side assertions (size-vs-content, the
write header, a client-named actor, done items back in the drag) — each bites
only its own target; control green.

**Every gate render is now pinned to a known backlog file.** My freshness change
made unpinned renders read whatever the *box* held, and four assertions went red
on a page that was working correctly. A gate that does not say which file it
reads is measuring the box, not the page.

---

## What I did to main, in full

Six commits through the committer while proving the path, **net content change
zero** (`docs/BACKLOG.md` byte-identical to `bed879d`, checked by diff):
a test note, its removal, and two reorder-and-restore pairs.

Two mistakes of mine, both fixed at the root:
- the first transport proof **committed and pushed when I meant to rehearse**,
  because the committer takes `--dry-run` on a command line and a socket has no
  command line. The listener now takes `dry_run` in the request body.
- a later probe hit the **real** socket instead of a dead one. Discipline since:
  every test either passes `dry_run` or points `LGB_SOCKET` at a socket that does
  not exist.

---

## The history view — DONE too (it was the fallback; I was not blocked, so it became the next item)

The census was right and the cause is worth knowing. Thirty date-headed sections
below `## ✅ SHIPPED TO LIVE` reached the page **not at all**. Not dropped by
accident: `lgb_parse_details` takes the first token of a heading as the item id,
and `"2026-08-01 — …"` yields **`2026`** — so all thirty collapsed onto that one
key and the last one silently won. No item has id 2026, so the archive was
unreachable. Nothing errored. Same shape as the ✅ bug that halved the ticked
items.

A **separate** parser, not a widened one: the two answer different questions, and
the archive is keyed by DATE, which is not an id and must not be made to look
like one. Provably disjoint, and the gate asserts no date leaked into the index.
Grouped by date, newest first, collapsed by default, counts counted, and an
absent archive **says so** rather than drawing a zero.

**Two bugs in my own gate**, both caught only by red-first and both recorded in
the commit: a `substr()` that cut through `🔴` and `—` and reported two headings
missing from a board showing all thirty; and an empty-state assertion matching
the bare string `hist--none`, which the **stylesheet** carries on every render —
so it was true on a page with no such element, and the mutation that should have
reddened it passed silently. This gate's own footer records learning that once
already, with `f--bad`.

> **A harness warning worth more than either.** My first mutation run reported
> **no reds at all** and I nearly wrote that up as "the assertions are
> decoration". In fact two anchors had never matched (one silently mutated a
> *different function*) and the third was vacuous because I had hardcoded the
> **true** value. A no-op mutation must fail LOUD. Mine does now.

---

## CHARTER EXTENSION (Ian, 2026-08-16): talking to the lanes from the board

His words: *"I would like to be able to interact with the lanes through the
workboard."* **The board half is BUILT, gated and committed. The relay half is
DESIGNED AND POSTED, deliberately not built** — keeper asked to review the loop
first, and it is theirs to run.

### The loop

**Outbound (Ian → lane):** thread on the team row → the page's existing write
layer → committer, new fenced shape **`lane_message`** → `docs/board-lanes/<lane>.md`
on main → keeper's relay picks it up → delivers with **`lane-say -f`** → records
the outcome.

**Inbound (lane → board): NOT COMMITTED.** The lanes already post to the devmsg
sqlite; the relay snapshots replies to a JSON file the board reads.

### Why the asymmetry — this is the design, not a shortcut

His messages are **instructions**: they belong in git, actor-stamped and
permanent, findable from the item months later. Lane chatter is high-volume — I
posted a dozen messages to keeper in one session — and committing it would put
hundreds of commits a day on main and make `git log` useless for everything
else. The snapshot is the pattern the **lane lights and capacity strip already
use**, so it is not a new trust model. It also **kills the feedback loop for
free**: a reply that is never committed can never trigger a delivery.

The web user **cannot** read `/var/lib/devmsg/messages.db` (it is `devmsg`-group)
and I deliberately did **not** propose adding it — that group has **write**, so
it would let any PHP on the WordPress site send messages as `ubuntu`.

### The three traps this is designed against, by name

1. **Backticks get executed.** A board message through a shell is
   command-substituted — it has bitten twice here, once eating a `redis-cli`
   recovery command. Ian will paste commands into these threads, so it is the
   normal case, not an edge case. `lane-say -f` takes the message **from a
   file**, so it never becomes argv. **That is the only delivery form to build.**
   The committer stores backticks **verbatim** and the gate asserts it.
2. **A watermark that advances only on success wedges forever** (11 days /
   3,084 runs on bb-mirror-reconcile). The relay's watermark must advance
   **past** an undeliverable message with the failure recorded.
3. **Do not arm the timer before the code exists** — the mirror outbox timer did
   that and reddened `systemctl --failed` forever, killing the alert channel.
   Timer goes in **last**, after the script is merged and hand-run clean once.

### What is already true on the board

`lane_message` writes only to `docs/board-lanes/<lane>.md`. **The lane name is
fenced in the committer, not just the page** — it becomes a filename *and* a tmux
session name. Traversal, shell metacharacters, spaces/capitals: all refused, and
the gate asserts the **reason**, because with the fence removed two of those
still refused *by accident downstream* and looked green.

**A commit is the relay's inbox, not the lane's ear.** So the page says
**queued**, never "sent"; a failed delivery renders as **NOT DELIVERED** with the
reason; and an absent relay renders as **absent**, not as a quiet lane. All three
gated, each broken to prove it bites.

### Left for keeper
- **Confirm the relay runs as `ubuntu`** (it must — devmsg group) and pick a
  cadence. I suggested **30s**: person-paced for a chat, cheap.
- Then the relay script (`tools/keeper/board-lane-relay.php` — I proposed writing
  it into the repo so it is reviewable and gated rather than hand-authored on the
  box), and the timer **last**.

---

## The exact next action

**The relay half, once keeper answers.** Then **the two phase-2 surfaces still unbuilt:** the **keeper chat** (general +
per-item, both bridging over `msg`) and **images in the item thread**. Both are
specced in `docs/BACKLOG-29-BUILD-NOTES.md` §1b, both write, and the write path
they need now exists and is fenced.

**The one link not yet exercised:** nginx → the page's POST branch, which cannot
be tested until this merges (the serve answers from main). Everything either side
of it is proven — `looth-dev` reaching the socket, and the page's own logic over
real HTTP. Check it first thing after merge.

## Still owed by keeper
1. **A gate number** for `board-committer-gate.php` (37 assertions). Not minted here.
   The `run-all.sh` ledger comment still reads *NEXT FREE: 45* while 45, 48, 49,
   50 and 53 are registered in that same file — guitardle-fairness hit this too.
2. **Delete the pre-merge drop-in at merge** (above).
3. **The go-live blocker, unchanged:** add `lg-member-sync/v1` to
   `bb-enable-private-rest-apis-public-content` on dev2 and live, or Stripe's
   webhooks get a 401 before any of our code runs.
4. **A ruling on band-crossing drags:** the index is grouped by P-band headings
   and a drag rewrites slots, so dragging an item to the top of its project can
   carry it under a higher band heading — its P-band changes. I think that is
   correct for a priority index and left it that way, but nobody has ruled.

## Still awaiting Ian
- **Concept nod** — `/footer-mockups/wip-board/concept.html`.
- **Aron Bach ruling**, Monday 18th.
- The four decisions in the brief's §6 (price, the over-tiered four, who is in
  the Test Group, when the pages switch on).
