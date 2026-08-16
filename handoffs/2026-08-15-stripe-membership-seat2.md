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
| 56 committer (**now also: chat, questions, decisions, doorbell**) | 33 | **104** | |
| relay (**number not minted — asked**) | — | **24** | the shell property, idempotency, the attempt cap, the visible failure |
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

---

## IAN'S COCKPIT — the first cut (2026-08-16, ruled mid-session, ships before the relay)

Ian flipped the priority: **the general chat ships first, the lane relay
second**, because this half touches **no terminal at all**. A message is a
commit; being committed **is** being delivered. That is the whole reason it
could go out ahead of the relay.

**Five new fenced shapes** (`keeper_message`, `question_ask`, `question_answer`,
`decision_pose`, `decision_answer`) and four surfaces:

| Surface | What it is |
|---|---|
| **Chat panel** | Ian ↔ keeper, both directions committed, both actor-stamped. The old Ask-keeper panel was **repointed**, not duplicated — it used to route through the lane-thread shape, which means terminal delivery. |
| **Open questions rail** | Ian: *"I ask questions stream of consciousness and they wind up getting lost."* OPEN is derived from having no answer. Answered ones move to a drawer still showing question **and** answer. |
| **Desk decision boxes** | Real buttons + an always-present "Something else…", inside Your Desk. |
| **Doorbell** (`tools/keeper/board-doorbell.php`) | Keeper runs it as a background task; **its exit is the bell**, stall-watchdog pattern, relaunch order on every exit line. |

### The rules that carry the weight

- **An open question cannot be removed except by gaining an answer** — not a rule
  anyone remembers, but **the absence of any verb that removes one**. Gated:
  the question text is still present after being answered.
- **First answer wins, enforced in the COMMITTER**, not in either door. If each
  door checked "already answered" for itself there would be two implementations,
  and the first time they drifted a ruling would exist twice with different
  words. The answer records **which door** — months later "he pressed it on the
  board" and "he typed it in chat" are different evidence.
- **An answered desk box offers nothing to press.** Otherwise he presses it, the
  committer refuses under first-answer-wins, and the board looks broken while
  working exactly as designed.
- **The chat refetches, it never fabricates** — gated against the *client
  source*, because otherwise the rule holds only until someone "improves" the UX
  with an optimistic append.
- **The doorbell rings once per item then goes quiet**, and **keeper's own writes
  never ring keeper**. Its memory sits beside keeper, not in the repo: losing it
  costs one duplicate ring, the harmless direction.

### Still to build (Ian's parity roadmap, in order)
1. **Image paste** → dev-gated upload, outside the WP media library *and* outside
   git, committed as the existing `media_ref` shape; keeper reads from disk.
   (A WP upload gets an attachment post with a public URL — a board screenshot of
   an admin screen would become member-reachable.)
2. **Decision posing generalised to the chat** — mostly built; the mechanism
   already reads options from a committed file and never invents them.
3. **Paste-back** — the box itself. *The whitespace half is already done and
   gated* (see below).
4. **Near-live feel** — poll for new committed replies.

Then the **lane relay resumes**.

---

## NEXT CHARTER (keeper, 2026-08-16): invite links — Ian found a real hole

**The whitelist takes only EXISTING wp users**, so the most important
pre-cutover rehearsal — a fresh recruit going from nothing to a paid membership
— is currently **untestable**: a fresh person cannot even see the join page,
because it only reveals itself to logged-in listed members.

**This reverses `STRIPE-LANE-BRIEF.md` §6 "No tokened invite links"**, and the
reversal is recorded there rather than left as two documents disagreeing. The
old reasoning was wrong in one place: it assumed everyone we test with already
has an account.

### The design, four fences
1. **Scope**: a token admits the **join flow and nothing else** — an explicit
   page allowlist (join, regional-pricing, welcome). `manage-subscription` and
   `request-refund` stay shut: an invitee has no subscription to manage, and a
   token opening those is a bypass in an invite costume.
2. **Single-use means ONE ACCOUNT, not one page view.** Burning it on first open
   dies on a refresh or a back button — a support ticket, not a fence. It is
   consumed when the account is created on email match.
3. **Expiry** stamped at mint, checked every hit, so an old link in an inbox is
   dead even if nobody revoked it.
4. **Audit**: the account is stamped `invite-created` and auto-listed on email
   match, so *how* a member got in is answerable later rather than inferred.

**Dual rail intact**: the step/welcome handoff a fresh account gets must be the
**rail-agnostic** one — a fresh Stripe joiner must not receive Patreon-shaped
onboarding.

### ⚠️ THE THING THAT WILL BREAK IT
**These pages are gated TWICE.** The router decides who may reach a page, then
every page file re-checks on its own authority — that is exactly why the soft
launch looked broken on 8/15 when only the router was changed. The invite check
belongs in **`lg_membership_testgroup_gate_or_exit`**, the shared rule both
doors already delegate to. Put it in the router alone and a fresh invitee
reaches the join page and is thrown out by the page, which reads as a broken
token when the token is fine.

### Gates (keeper's list, plus one)
- an unused invite admits exactly the join flow and nothing else;
- a used or expired invite admits nothing;
- a fresh account from an invite lands listed;
- **and with the invite feature switched OFF the whole thing is byte-identical
  to today** — the assertion that lets it merge safely before cutover.

Sandbox only until Ian's cutover, as ever.

### One design unknown I resolved for you, and one I did not

**Resolved:** `$ctx` does **not** carry the requested slug (checked
`membership-pages/lib/whoami.php` — it has `authenticated`, `wp_user_id`,
`capabilities`, no slug). So the scope fence cannot read the page from `$ctx`.
Do **not** solve that by adding a slug argument to
`lg_membership_prelaunch_gate_or_exit($ctx)` — every page file calls it and you
would be changing a signature in ~6 places, which is how one page gets missed
and silently keeps the old rule. Put a slug resolver **inside the invite
module**, mirroring the router's own resolution, so both doors get the same
answer without any call site changing. That is the same principle that fixed the
two-door bug in the first place.

**Not resolved, and it is yours to decide:** whether the token lives in the URL
query (`/join?lginv=…`) or is exchanged once for a short-lived cookie on first
hit. The query form is simpler and is what "invite URL" implies, but it means the
token sits in browser history, in any referrer, and in Ian's inbox forever — and
it is a gate bypass, however scoped. The cookie exchange costs one redirect and
keeps the bypass out of history. **I did not pick one**, because it is a
security posture call above my pay grade on a live-money path, and because
picking it silently in code is exactly how such a choice stops being visible.

### ⚠️ WHY I STOPPED HERE RATHER THAN STARTING IT

The design above is complete and the fences are specified — but this feature is a
**scoped gate bypass on the payment path**, and I was near the end of a very long
session. A half-wired bypass is the single worst thing to leave in a tree: it
either fails closed and looks broken, or fails open and nobody notices. Every
other piece this session was safe to leave mid-flight; this one is not. So it is
captured to be **executed cleanly from the top**, not resumed from the middle.

### The whitespace bug that phase 3 would have hit
`ltrim($l, '> ')` strips a character **class** — every leading `>` *and* every
leading space — so quoted terminal output came back with its indentation
deleted. Stack traces, `systemctl` output and diffs are all indentation. Now
strips exactly one `> `, proven with an exact round trip and gated both ways.

---

## Where the merge conflict actually was (I got this wrong twice)

I warned that dark-board and this branch would collide on `wip-board.php`. **They
did not.** Main does not carry dark-board's rules yet. The real collision was
with `9efd372`, the **watch-only live terminals** lane, which added a watch link
to the same team-row markup this lane had refactored into a shared renderer.

**Resolved by keeping BOTH** — their watch link and this lane's renderer are
different features that happened to share a region. Ian's watch-only ruling is
untouched, with a comment at the site saying so. Branch is rebased onto main,
zero behind, all gates green.

## Two self-corrections worth inheriting

1. **Never background a `git rebase` in a worktree you are still editing.** I
   did; it stopped on the conflict, left me on a **detached HEAD** mid-replay,
   and the suite chained behind it ran against a half-rebased tree. I caught it
   only because a gate count dropped 75 → 56 and I chased the number instead of
   shrugging. Nothing was lost (everything was pushed), but the suite result
   would have been meaningless had I trusted it.
2. **Do not gate a suite run on "load < 4".** A suite *with Chrome* is itself
   above 4 on 2 cores, so that condition can essentially never fire while any
   lane is running one — I starved my own suite four times while every other
   lane got theirs through. **The flock mutex is already the box-wide
   serialiser** for suites specifically. The load rule remains right for phases
   that add load on top of what is there; it is wrong for the one heavy phase
   that carries its own serialiser.

## The assertion bug this session produced SEVEN times

An assertion matching a **string that also lives in prose** — a CSS rule, a code
comment, a container class, or a neighbouring `case`. Every one was green for a
reason unrelated to the property it named, and one blamed a *working* page:

`hist--none` (stylesheet) · `NOT DELIVERED` (JS comment) · `proj--unsorted`
(stylesheet, pre-existing) · lane-name refusals (refused downstream by accident)
· `item_add … 'id' =>` with `/s` (crossed into `item_promote`) · `w2__opts`
contains `w2__opt` · the doorbell's own docblock saying "no checkout, no reset".

**The cure is always the same**: strip comments before asserting about code,
assert the markup that can only be *output* (`class="x">text`), and check a red
is red **for the reason it claims**. Worth a line in CRAFT-STANDARD.

---

## IAN'S VISIBILITY BUG (2026-08-16) — FIXED, and the reported cause was wrong

He tested as **Mikelle (1953)**, correctly listed, and saw no Stripe entries.
The trace handed to me said membership whoami never fetches capabilities. **It
does.** Measured rather than read:

- a minted session for 1953 returns a payload carrying `manage_options`,
  `edit_posts`, `moderate_forums` and more — caps are fetched and cached fine;
- `/manage-subscription/` as Mikelle returns **200, 57KB of real page**, not the
  stub — the page gate is fine too.

**The bug was one line.** The menu keys the Stripe entries on the
`stripe_testgroup` capability (`lg-shared/site-header.php:110`); the **poller**
computes it (`InternalRestController` — `manage_options` OR `inCohort`); but
`profile-app/src/Whoami.php::capabilitiesFor()` rebuilds the caps array from an
**explicit allowlist** — three named keys plus a hardcoded pass-through of
exactly two extras — and `stripe_testgroup` was in neither. profile-app received
the capability and **dropped it on the floor**, so a correctly-listed member
could reach the pages while seeing no way in to them.

> **The trap that list IS, and it will bite again:** a named pass-through
> silently discards every capability nobody remembered to name, and the discard
> is **indistinguishable from the capability being false**. Anything the header
> learns to key on must be added there too, and nothing enforces that.

Fixed and unit-checked both directions (listed → `true`, unlisted → `false`;
before, **missing for both**, which is why it read as false everywhere).

### ⚠️ STILL OWED ON THIS: the real-page gate leg (keeper specified it, I did not build it)

Gate 34b's menu leg drives `menuFor()` with a **synthetic** `stripe_testgroup`
cap, so it could never notice that the real caps array never contains that key —
the harness-must-run-as-the-real-user failure, one layer further out than the
8/15 soft-launch bug.

**Build it as keeper specified**, with the probes keeper named on 8/16: **854
`GerryHayesTest`** as the listed probe (plain subscriber, **no**
`manage_options`, on the list — it sidesteps the admin-branch trap) and **2455
`viz-test-nobody`** as the unlisted probe. Drive `/manage-subscription/` **over
HTTP as a minted session** for each and assert the five entries render / are
absent.

> **It must READ the deployed state, not hardcode it.** The HTTP leg measures
> whichever copy of profile-app is *deployed*. Until `145a2c3` merges and the
> serve pulls, the deployed copy still drops `stripe_testgroup` — so a leg that
> hardcodes "entries render" goes RED on main and blocks every lane. Probe
> whoami for the capability first and assert per-state, the same rule as
> [[feedback-gate-reads-the-flag-not-a-hardcoded-state]].

**The static half is already built** (34b, 79 assertions): the central
computation passes the capability through, and **every capability the header
keys on survives profile-app's allowlist** — a cross-check that fails the day
someone teaches the header a new capability and forgets the other end. **Red-first against the
pre-fix state** — revert the one-line pass-through and it must go red.

The session-minting recipe is proven and is what I used for the trace:
`wp eval 'echo wp_generate_auth_cookie($uid, time()+3600, "logged_in");'` plus
`COOKIEHASH`, then `curl -k -H "Host: dev2.loothgroup.com" -H "Cookie:
wordpress_logged_in_<HASH>=<COOKIE>" https://127.0.0.1/manage-subscription/`.
**Use a listed NON-ADMIN member** — 1953 has `manage_options`, so she passes the
admin branch and proves nothing about the list.

**Ian can re-test as Mikelle only after this merges and the serve pulls** — the
serve reads `loothplatformv2-clean`, so the fix is not live on dev2 yet.

---

## NEXT CHARTER: DESK AUTOMATION (Ian, 2026-08-16) — and the dependency that will bite

Ian: *"are you hand populating my desk? Is there a way to do it mechanically?"*
The desk becomes **derived**: (1) lane board posts addressed `-> Ian` render as
desk items; (2) decisions render as the desk boxes; (3) keeper items go through
the committer. `docs/IAN-DESK.md` retires to a fallback. Gate: a lane's `-> Ian`
post appears on the desk within one refresh.

**Item 2 IS ALREADY DONE** — the decisions store is committed and the desk boxes
render from it on this branch today. Item 3 is the committer, already built.

### ⚠️ ITEM 1 HAS A HARD DEPENDENCY — re-verified, not assumed

**The board cannot read the msg store.** The page runs as the `looth-dev` pool
and `/var/lib/devmsg/messages.db` is `devmsg`-group; tested as that exact user,
it **cannot open it**. So lane posts cannot be read by the page directly, however
the render is written.

**Do NOT solve it by adding `looth-dev` to `devmsg`.** That group has **write**
on the database — it would let any PHP on the WordPress site send messages as
`ubuntu`. A far larger door than the one being opened, opened to fix a rendering
problem.

**The answer already exists**: the relay writes a **world-readable snapshot** for
exactly this airlock reason, and the desk should take its lane items from that,
the same way the lane threads already do. So item 1 depends on the snapshot
writer — the relay half, built and gated (24) but **not yet armed**. Either wait
for the relay, or extend the snapshot with a small separate writer that carries
`-> Ian` posts.

---

## /srv/lg-stripe-billing IS NOW A SYMLINK (2026-08-16) — the cutover, done unhurried

It was the last real directory among the `/srv` apps; every sibling
(profile-app, membership-pages) already symlinked into the serving checkout.

**It was NOT ancient, and that matters for the record.** `diff -rq` between the
June directory and the monorepo copy found the code **content-identical** — same
`composer.json`, same `composer.lock`, only `.gitignore` and `PICKUP.md` differ.
The P1 that looked like staleness was a one-character nginx alias bug. Anyone
re-reading the original diagnosis should know the directory was never serving
old code.

**How it was done, and why each step:**
- `vendor/`, `.env` and `logs/` are **already gitignored in that app**, so placing
  them in the serving checkout does **not** dirty a pull-only tree. Verified
  clean after.
- `vendor/` was **copied, not composer-installed** — the two `composer.lock`
  files are byte-identical, so the copy is exactly the dependency set that was
  already serving. No network, no resolver surprises.
- `.env` carries Stripe keys. It is gitignored and was copied, never committed.
- `logs/` is owned by **www-data**, which is what the billing pool runs as.
- **Proved before swapping**: a probe on a spare port pointed at the
  serving-checkout copy returned 200 with the real catalogue. Only then was the
  directory renamed and the symlink made.
- **Rollback exists**: `/srv/lg-stripe-billing.bak-20260816-183554`. Delete it
  once a few days have passed without incident — it holds a `.env` with keys, so
  it is not litter to leave forever.

**Verified after**: `/billing/v1/products` 200 JSON, all five of Ian's pages
serve Mikelle the real page, and profile-api / hub / manage-subscription
unaffected.

---

## LANDED AND VERIFIED ON dev2 (2026-08-16 evening)

Train 4.1 is deployed and the relay is armed. **Verified on the box, not assumed:**

- the board-wide same-tick refresh is **live** on dev2 (`board_state` +
  `setInterval(poll)` both present in the served file);
- the relay is **running** — snapshot 16 seconds old when checked;
- **Ian's desk is auto-populating: 12 items**, including the featured-members
  posts that previously never reached him because a hand lagged. That is the
  whole point of desk automation, working end to end.
- `branches` and `lanes` in the snapshot are **empty, and correctly so** —
  nothing is attached to a card yet and no lane message has been sent. An empty
  section here is the honest state, not a failure.

**Gate 67 is minted (the relay gate) and now registered in `run-all.sh`.** Until
this lands it protected nothing outside this branch — the gap this lane named
about its own work and then had to keep naming.

---

## INVITES — BUILT END TO END (Ian ruled URL token, 2026-08-16)

**Ships OFF** (`lgms_stripe_invites_on` absent). Arming it is a deliberate act.

| Half | Where |
|---|---|
| **Admission** | `membership-pages/web/_invites.php`, checked in `lg_membership_testgroup_gate_or_exit` — the ONE gate both doors delegate to, and the module is required BY that gate so no include order can leave them disagreeing. It is the LAST check, so an invite only ever widens. |
| **Mint / spend** | `lg-patreon-stripe-poller/src/Invites.php` + a `user_register` hook. Spent when an ACCOUNT is created, matched on EMAIL. |
| **Admin** | The cohort tab mints a link; shown once, never recoverable. |

**The fences, all gated (34b, 116):** scope is the join flow only —
`manage-subscription`, `request-refund`, `affiliate-earnings` stay shut; spent
and expired admit nobody; every failure returns the *same* false so a prober
learns nothing; the raw token is **never stored** (sha256 key), so a database
read cannot be replayed; a second live invite per email is refused; and with the
flag off the check returns before reading anything — byte-identical to today.

**To arm on dev2 when Ian wants to walk a fresh join:**
`wp option update lgms_stripe_invites_on 1` (dev2-local, no tracked file).

---

## BOARD MECHANICS — backlog 41, all four parts (2026-08-16 night)

Ian: *"My desk is now really verbose… more mechanical and less hand curated by
keeper… completed work still listed on my desk."*

| Part | What landed |
|---|---|
| **(a) compact desk** | One line per item — seat, type, snippet, age — body in the modal. The type is derived from evidence (open decision box → `decision`, trailing `?` → `question`, else `update`); no cleverer classifier, because guessing an ask-type from prose is typing dressed as deriving. |
| **(b) mechanical retirement** | An item leaves when its decision was answered **after** the ask, its seat's branch **merged** after the ask, or it was **dismissed** (committed `desk_dismiss`, never hand-removed). Retired is **marked with its reason**, not deleted. |
| **(c) hand-curation audit** | `docs/BACKLOG-41-HAND-CURATION-AUDIT.md` — measured: 37 of the last 40 backlog commits are keeper's, and the index carries **50** status markers. Each maps to a store that already exists. |
| **(d) done-ledger** | `tools/keeper/board-done-ledger.php` writes `docs/DONE.md` from `Closes-Backlog:` trailers; completed items MOVE out of BACKLOG.md; the board renders it. |

### The two guards worth keeping in mind

- **Write before delete.** The ledger writes its line *before* removing the
  backlog index line, so the worst case is a duplicate record rather than lost
  work. Gated; swapping the order reddens it.
- **The timestamp guard.** A decision answered *before* an ask must not retire
  it, or an old ruling silently closes a fresh question — the desk would eat
  exactly the asks that matter most.

### ⚠️ The ledger is inert without merge discipline

It fires **only** on `Closes-Backlog: N` trailers in landed commits. Without them
it is correct and silent forever — the safe failure, not the useful one. This
branch carries trailers for **39** and **41**, so the first real ledger entries
write themselves at its merge; keep adding them or the file stays empty and the
feature quietly isn't one.

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
