# stripe-membership — handoff, 2026-08-15 (seat 2, later still)

**Read the predecessor's `handoffs/2026-08-15-stripe-membership.md` and
`docs/STRIPE-LANE-BRIEF.md` first.** Both are still accurate except where this
file says otherwise. This file is only what changed on my watch.

Branch `stripe-membership`, rebased on `origin/main`.

---

## What I was given, and what happened to it

| Job | Outcome |
|---|---|
| **Charter extension (8/16): talk to lanes from the board** | **DONE, BOTH HALVES.** Board half built+gated; relay built+gated after keeper approved the design. Timer NOT armed — keeper's, and last. |
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
| committer (**now gate 56**, keeper minted it) | 33 | **56** | + the ambiguous index, + `lane_message` and its name fence, + `lane_receipt` and the forged-row fence |
| relay (**number not minted — asked**) | — | **20** | the shell property, idempotency, the attempt cap, the visible failure |
| 50 — work board (after the general chat) | 59 | **99** | + the general chat, and that it is not a second implementation |
| 50 — work board | 59 | **95** | + the endpoint driven over real HTTP, + the source-of-truth preference in three states, + the shipped archive, + the lane threads |

**FULL SUITE: ALL GATES GREEN, exit 0, 51 gates**, run on the rebased branch
(`fd70b5d`) *after* the reboot — the pre-reboot run was killed mid-flight and is
not quoted as a result.

> ⚠️ **One honest gap behind that "all green".** The **committer gate is not in
> `run-all.sh`**, because it has no number yet. The suite did not run it; I ran it
> standalone (48/48). Until keeper mints a number it protects nothing on anybody
> else's branch, and a future edit to the committer would sail past the merge
> train. That is the concrete cost of the missing number.

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

### The relay — BUILT (keeper approved the design 8/16, with one addition)

`tools/keeper/board-lane-relay.php` + `tools/gates/board-lane-relay-gate.php`
(20 assertions, **number not minted — asked keeper**).

**Keeper's addition is the spine: idempotent across a crash.** Every attempt is
receipted through the committer (`lane_receipt`), and a receipted message is
never sent again. **The receipt is committed AFTER `lane-say` returns, and that
order is deliberate** — die in between and the next pass re-delivers *once*,
never loops. The other order risks a message recorded as delivered that never
arrived, which is the failure nobody can see. It is committed rather than kept
in relay state so it outlives the process and its disk.

- **Failures are receipted too**, counted, abandoned after 3. Receipting only
  successes retries an undeliverable message forever — the wedge shape.
  Gated: a message *behind* a stuck one still goes out.
- **The message never meets a shell**, proven not claimed: the gate's fake
  `lane-say` records its whole argv *and* the bytes of the file, and asserts the
  text is in the FILE verbatim (backticks, `$()`) and **nowhere** on the command
  line.
- **Its own clone** (`/home/ubuntu/board-lane-relay-clone`), separate from the
  committer's — the committer `reset --hard`s before every write and a shared
  reader would occasionally parse mid-reset.
- **Fails safe pre-merge**: exit 3, "the committer is not deployed at …". It
  cannot run for real until this merges and the serve pulls.

**Two bugs the gates caught that nothing else would have:**
1. PHP's array union keeps the **left** key, so a parsed message inherited an
   empty `text` from its own initialiser — the relay delivered **empty
   messages** while every count and log line said they had gone out.
2. A multi-line failure reason could **forge a second receipt row** — mutation
   measured: 3 rows where 2 were expected. A forged `delivered` row would
   silently suppress a real message. Reasons are flattened to one line.

### The general chat — a hole I nearly shipped

I built the per-seat threads and was ready to call the extension done. Then I
checked whether the general chat *worked*: **keeper is not a lane.** It is absent
from the sentinel's seat list, so a chat rendered by the per-seat loop has **no
surface for keeper at all** — Ian had no way to reach keeper from the board, which
is the half he asks for most ("how's it all going?"). It now has its own rail, and
the gate asserts keeper is **absent** from the seat list so this cannot quietly
become a lane-loop artifact again.

**One renderer, not two.** Both surfaces go through `lgb_thread_box()`. Two would
drift, and the first thing to drift would be the failure states. The mutation that
proves it: give the general chat its own markup that forgets the NOT DELIVERED
banner, and exactly that assertion reddens.

> What caught it was asking *can Ian actually do the thing he asked for*, not
> *does my code render*. Same question that found the empty-message bug.

### ⚠️ The general chat's REPLY path is a convention, not code

Measured over the last 400 board messages: every lane prefixes its posts with its
own name, so **per-lane threads are healthy**. But only **4 of 400** start with
`keeper ->` — keeper answers lanes through `lane-say` into a terminal, which never
touches the devmsg store and therefore can never appear in Ian's general chat.
**So Ian can ask and see no reply even when keeper acted.** The fix is keeper
adopting the convention of answering *on the board* when the question came from
Ian. Raised; keeper's to settle before Ian uses it.

(Also measured: the 62 unattributed messages are all `mirror-sync-watch` alerts —
robot noise. Leaving unattributed messages out of the threads drops nothing Ian
needs. That validated the design rather than changing it.)

### Write shapes v2 — `item_add` and `item_promote` (Ian, 8/16, keeper-queued after the relay)

His words: *"Could I add things. Add headers and sub items. Or promote sub items
to headers."* Keeper's design note is the invariant: **position is rank, number
is a permanent name** — an add or a promotion must never renumber anything.

- **Additive by construction**: `item_add` inserts one line and touches nothing
  else. A new item lands at the **bottom** — nobody but Ian decides what
  outranks existing work — and he drags it up through the reorder shape.
- **The number is `max+1`, never a gap-fill.** Reusing a retired number makes an
  old reference silently point at new work. A parent counts as taken even when no
  item holds it (the real file has children of `11` and `4` with no item 11 or 4).
- **IDS ARE DOTTED INTEGERS, NOT DECIMALS.** The file carries `3.10`, and
  `(float)"3.10" === (float)"3.1"` — any numeric handling merges them, and
  "next child + 0.1" hands out a number that already exists. Gated against the
  real 3.9/3.10 pair.
- **Promotion leaves a pointer**: content moves verbatim to the new number, the
  old line becomes `4.2. → promoted to 36`. No name is ever retired.
- **The page never mints a number** — it sends a title and an optional parent;
  the committer mints from the file inside the same read-and-write. Gated, and
  proven to bite by making the page send one.

Gate 56: 56 → **75**. Gate 50: 99 → **105**.

### The dark-mode pass — measured, and it does NOT need redoing for this lane

dark-anon-sweep took the board's contrast (276 findings → 0) on branch
`dark-board`, cut from main. **My first train was already on main**, so their pass
covered nearly all of this lane's surfaces — both opacity cases they found
(`.grip`, `.hist__dc`) are this lane's classes.

**Exactly four classes are branch-only**: `.askk`, `.askk__w`, `.newitem`,
`.newitem__t`. Three are layout-only. The fourth uses their flagged
opacity-on-inherited-ink pattern — but at `.7`, not `.35`, which over their new
rail background `#202426` composites to `#aaaca9` = **6.84:1**, clearing the bar.
(Their `.35` cases measured 2.78:1; the difference is entirely the opacity value.)

**So nothing here should reopen their zero** — but that figure is my arithmetic,
not their instrument, so **re-run their probe once after this merges**. Our diffs
touch the same file in different regions (theirs ~line 965 in the head block,
mine in the second block at ~1404): it should auto-merge, and a clean auto-merge
is precisely the case where a new class arrives without a dark rule.

### ⚠️ If you touch the styles, read this first

- **There are TWO `<style>` blocks**: the original in the head, and a second one
  carrying all **38** classes this lane added (threads, messages, the NOT
  DELIVERED banner, the archive, the write-layer confirmations, the ahead chip).
  A pass that edits only the head block leaves every new surface untouched.
- **The head block uses theme tokens** (`var(--accent)`, `var(--bg)`, …); the
  second block **hardcodes** colours, including two literal `#4a9eff` where
  `var(--accent)` exists. Most of it is translucent overlay + `color: inherit`
  and so adapts by construction, but the hardcoded accent is a real token
  violation and is **this lane's to fix**.
- **dark-anon-sweep is taking a dark-mode contrast pass on the board** (keeper,
  8/16). Their diff stays in the style block, this lane's stays in PHP. I posted
  the above to them and am staying out of the style block until they answer —
  and warned that a pass run against **main** would measure a board missing all
  38 classes, because this branch is not merged.

### Left for keeper
- **A gate number** for the relay gate (committer got 56).
- **The timer, LAST**: merge → serve pull → hand-run the relay once clean →
  *then* arm. Armed ahead of its code, it reddens `systemctl --failed` forever
  and kills the alert channel — the mirror outbox timer did exactly that.
- Cadence: I suggested **30s** (person-paced for a chat, cheap).

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
