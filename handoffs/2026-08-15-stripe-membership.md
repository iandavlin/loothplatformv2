# stripe-membership — handoff, 2026-08-15 (late)

Written at context limit. Branch `stripe-membership`, **clean and fully pushed**.
Everything below was verified on dev2 rather than remembered; where it wasn't,
it says so.

**Read `docs/STRIPE-LANE-BRIEF.md` first** — it is the lane's durable memory.
This file is only what changed today and what to do next.

---

## THE EXACT NEXT ACTION

**Wire the board's write layer to the committer service** — Ian's board points
2/3/4: drag-rank *within* projects, act on sublists, decision buttons on
needs-you items.

The service is built, fenced and gated. What it does **not** have yet is a
caller. Before that can be tested end to end, keeper owes two things (below).

If those are still outstanding, the useful unblocked work is the **board history
view** — the census found the shipped archive (30 date-headed sections) is
invisible on the board, and that is the one real gap in *"the board doesn't have
all of the backlog."*

---

## The committer service — state

`tools/keeper/board-committer.php` + `tools/gates/board-committer-gate.php`.

**BUILT AND GATED. NOT DEPLOYED, NOT CALLED BY ANYTHING.**

| Fence | Status |
|---|---|
| 1. Allowlisted shapes only (`reorder`, `note_append`, `media_ref`) | **enforced twice** — on the intent, and again on the paths git *actually* reports changed |
| 2. Actor named, stamped **in the commit** not just the audit log | enforced; a write with no actor is refused |
| 3. Buck fence before every commit | enforced; its **absence is a hard failure**, not a skip |
| 4. Nothing survives a refusal | clone restored (incl. untracked cleanup, scoped to fenced paths); a failed push rolls the commit back |

**Reorder is the strict one:** the submitted order must be a **permutation** of
the ids already in the file. A drag cannot add, drop or rename — only line
*order* comes from the request; the line bodies are the file's own, moved.

Gate: **33 assertions**, against a throwaway clone with a local bare origin —
never touches the real clone, pushes nowhere. Mutations measured: fence 1
removed → 1 red (a `switch` default still refuses, so there are two layers);
permutation rule dropped → 5 red; actor requirement dropped → 2 red.

**Two real bugs the gate caught before anything shipped** — worth knowing because
both were silent:
- PHP coerces numeric-string array keys to **integers**, so id `"27"` became int
  `27` while the request kept the string. Strict compare failed, `array_diff`
  (loose) reported nothing — a refusal saying *"not a permutation"* with an
  **empty** list of what was missing. Most ids are plain numbers; reorder was
  broken for nearly every item. Keys are now prefixed.
- **`git diff --name-only` does not list untracked files.** The first note on an
  item read as "changed nothing", was refused, and left the file as untracked
  litter — which then made the clone dirty and refused every later call.
  Enumeration is now `git status --porcelain -uall`.

### Owed by keeper before it can run
1. **A gate number** for `board-committer-gate.php` — not minted here.
2. **Deploy step**: create `/home/ubuntu/board-committer-clone`, and wrap the
   script in a localhost-only listener for the web pool. Deliberately not done
   here: all the risk is in the fences, none in the transport.

---

## Stripe — wired vs not

| | State |
|---|---|
| Sandbox key | **WIRED** (`sk_test_`, acct `1LJOi5Hg6gcIV22b`). Check it with `tools/keeper/stripe-broker.php status` — **never a raw DB query** (see the incident below) |
| Prices | **STAGED**: $9/month + $99/year on Looth PRO. Verified at three ends — Stripe (`livemode:false`), our `prices` table, and the join wiring |
| dev2 test group | **STAGED**: 3 fixtures only — 2047 `gdle_gate_probe`, 1938 `qa-gift-rcpt`, 1887 `qa-disposable`. **No real member**, per Ian |
| lifecycle / identity gate / pages switch | **ALL OFF.** Staged ≠ unlocked: a listed fixture still gets today's stub (checked) |
| Live | untouched. Live's list comes later from Ian by name |

**Deliberately excluded, awaiting keeper:** user 854 `GerryHayesTest` — reads
like a fixture but its address is a real-looking personal one (`@hey.com`), so
it fails *"never a real member on dev2."*

### The go-live blocker keeper owes
**Stripe's webhooks cannot reach us.** BuddyBoss restricts the whole REST API to
authenticated callers, and `lg-member-sync/v1` is not on its exemption list, so
Stripe gets a **401 before any of our code runs** — silently, since nothing of
ours executes. Fix is one admin line: add `lg-member-sync/v1` to the WP option
`bb-enable-private-rest-apis-public-content` (already carries `looth/v1`), on
**dev2 and live**. Proven on dev2: 401 → 400 invalid-signature.

---

## Gates owned by this lane

| Gate | Assertions | Covers |
|---|---|---|
| 34a | 39 | the webhook grant (empty list = nobody) |
| 34b | 74 | the pages **and** the menu whitelist, both halves |
| 34c | 61 | the price control, incl. the two cadences |
| 34d | 33 | the entitlement sweep fence (the gift path) + dual-rail |
| 50 | 59 | the work board — nothing dropped, row↔modal mapping, copy bridge, read-only |
| *(pending)* | 33 | the committer's fences |

---

## Two incidents, both self-caught — read these

1. **I wired the LIVE key into the real config for ~45 seconds** while red-first
   testing the broker's live-key guard. Blast radius nil (lifecycle off, frozen
   on, only read-only Stripe calls). **Lesson, now built into the design:** when
   the code under test *writes*, the test must write somewhere disposable —
   `STRIPE_BROKER_TARGET` exists for exactly that.
2. **I leaked the sandbox key into my transcript** via a raw `db query` on a
   status check — having built the broker that afternoon precisely so keys never
   enter conversations. A tool only helps if it's the thing you reach for.
   **~~Rotation is owed~~ — RULED OUT BY IAN, 2026-08-15: *"keep sandbox keys.
   not worth rotating."*** The key stays as it is: it is sandbox-mode
   (`sk_test_`), it buys nothing, and he judged the rotation not worth the time.
   **Do not re-open this** — a later seat reading "rotation is owed" and doing it
   would be redoing work he has already declined. The LESSON is untouched and
   still binds: read a key through `stripe-broker.php`, never a raw `db query`.
   (The separate **live**-key rotation in the brief's §6 is Ian's own and is
   still open — different key, different decision.)

---

## Still awaiting Ian

- **Concept nod** — `/footer-mockups/wip-board/concept.html` (the board as his
  primary interface). His design asks outrank the history view.
- **Aron Bach ruling**, Monday 18th.

## Rulings already banked today (do not re-ask)
Cadences (monthly + yearly), prices ($9/$99), dev2 fixtures only, two chats
(general + per-item), project-nested accordion, done clears itself, copy-for-chat
bridge, renumber **only** the id-9 collision (done: Advanced search → 32).
