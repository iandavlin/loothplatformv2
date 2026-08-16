# Backlog 41(c) — what keeper still maintains by hand, and how to stop

**Measured 2026-08-16 against the repo, not estimated.**

## The surface

**37 of the last 40 commits touching `docs/BACKLOG.md` are keeper's.** The
subjects show what that work actually is: stamping status, adding items, moving
items between bands, and close-outs — e.g. *"keeper: backlog 18 stamped
BUILT+IN-USE"*.

**The index currently carries 50 hand-maintained status markers:**

| Marker | Count |
|---|---|
| `UNOWNED` | 19 |
| `✅` | 11 |
| `OWNED` | 10 |
| `DONE` | 3 |
| `CLOSED` | 3 |
| `awaiting Ian` | 2 |
| `FIXED` | 1 |
| `BLOCKED` | 1 |

Every one of them is true only until reality moves and nobody notices.

## The failure this produces

**Backlog 18 sat a full day marked UNOWNED after Ian had personally used the
finished feature.** Nothing was broken; the only thing that could have corrected
it was somebody noticing, and noticing is not a mechanism. That is the entire
case for this work.

## The proposal — each marker replaced by a fact that already exists

| Marker | Derive from | Already built? |
|---|---|---|
| `DONE` / `✅` / `CLOSED` / `FIXED` | a **`Closes-Backlog: N`** commit trailer in a landed train | **Yes** — `tools/keeper/board-done-ledger.php` (41d). The item MOVES to `DONE.md`, so the marker stops existing rather than needing upkeep |
| `OWNED` / `UNOWNED` | a **branch attached to the card** (`docs/board-branches/<id>.md`) — work with a branch is owned, work without one is not | **Yes** — backlog 39's card→branch link, plus live branch state from the relay snapshot |
| `BLOCKED` / `awaiting Ian` | an **unanswered decision box** for that item (`docs/board-decisions/<id>.md` with no `#### answered`) | **Yes** — the decision store and its one-answer rule |

**Nothing in the right-hand column needs building.** Every derivation rests on a
store this programme already has, which is the argument for doing it now rather
than designing a status system.

## What stays a human act, correctly

- **Writing an item** — a backlog entry is a judgement about what matters, and
  should be.
- **Ranking** — position is rank, and rank is Ian's.
- **Ian's verification** — "he has looked at it and it is right" is not
  derivable from any commit, and a ledger claiming it would be lying. The
  `Ian-verified` column stays a deliberate stamp.

## Suggested order

1. **Land the ledger (41d)** and add `Closes-Backlog:` to the merge discipline —
   that alone removes the DONE/✅/CLOSED/FIXED class, 18 of the 50.
2. **Derive OWNED/UNOWNED** from attached branches; stop writing the words.
3. **Derive BLOCKED/awaiting-Ian** from open decision boxes.

After 1–3 the index carries **no status text at all** — the board renders status
from stores, and `BACKLOG.md` goes back to being a ranked list of what is left,
which is the only thing it is good at.
