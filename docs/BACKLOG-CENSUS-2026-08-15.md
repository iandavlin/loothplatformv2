# Backlog census — what the board shows, and what it doesn't

**Measured 2026-08-15 by the stripe-membership lane.** Read-only; the file is
untouched. Ian: *"the wip board doesn't have all of the backlog."*

**He is right that the board doesn't show the whole file — but not for the
reason the numbers suggested, and no live work is missing.** The detail matters,
because the proposed fix (renumbering ids, one row per item) would not have
addressed the actual gap.

---

## The headline

| | |
|---|---|
| Index rows in the file | **49** |
| Rows the board renders | **49** |
| **Live items missing from the board** | **0** |
| Detail sections not on the board | 31 — **30 of them the shipped archive**, plus its divider |

Every one of the 7 live detail sections has an index row and is on the board.
The 31 that aren't are the entries **deliberately cleared from the index when
they shipped** — the file says so itself, in a divider at line 266:
*"✅ SHIPPED TO LIVE — cleared from the index."*

---

## Correcting the two numbers that prompted this

**"~59 item-shaped lines vs 49 rendered."** The extra ~10 are **not items**. A
line-shape count catches numbered *prose* inside detail sections — sub-points
like *"1. The 12 carousel elements were data-lg-carousel-prev/next…"* and
*"2. Those nav arrows are display:none at 390px…"*. There are **14** such lines
and every one is explanatory prose, not work. Counting them as items is a
measurement artefact, not invisible backlog.

**"ids 1/2/3/9 reused."** `1`, `2` and `3` are those same prose sub-points, not
ids. **`9` is a real collision** and was already found and handled:
*Shop Layout Planner* (P1) and *Advanced search* (P2) genuinely share it. The
board keys rows individually so they open separately, and gate 50 asserts a
duplicated id opens **different** items — but the file still carries the clash,
and `"bump 9"` remains ambiguous for keeper and every lane.

---

## The gap that IS real

**The shipped archive is invisible on the board.** 30 sections covering
2026-07-29 → 2026-08-01 — everything that shipped and was cleared out of the
index. Nothing on the board shows it, so "what did we already do?" is a question
the board cannot currently answer.

That is a legitimate thing to want and a fair reading of *"doesn't have all of
the backlog"*. It is also **not** a data defect: those items were removed from
the index on purpose, and putting them back into the active list would undo the
"done clears itself" ruling made the same day.

**Recommended instead:** the board grows a **history** view — the archive, newest
first, read from those same date-headed sections. Nothing needs renumbering for
it, and the active list stays clean.

---

## What I recommend for the file itself

1. **Fix the `9` collision.** One real defect, worth a small commit: renumber one
   of the two, map old → new in the commit body. Everything else keyed by id
   then behaves.
2. **Do not renumber anything else.** The other "duplicate ids" do not exist —
   they are prose. Renumbering on a bad measurement risks breaking references in
   handoffs, commits and memory files that name items by id.
3. **Leave the archive where it is.** It is correctly out of the index; the board
   should learn to show it rather than the file learn to re-list it.

---

## The standing rule this supports

*No work without a row.* Worth adopting — and note the census shows the current
file already honours it for live work: every live item has exactly one index row,
and the board shows all 49. The rule's value is keeping it that way as charters
and keeper tasks arrive.

---

## How this was measured

`docs/BACKLOG.md` parsed the same way the board parses it (`webroot/wip-board.php`),
then cross-checked against an independent pass that walks every `##` heading and
asks whether its id appears in the index. Line-shape detection was run
deliberately *loosely first* — that is how the 14 prose sub-points surfaced as
false positives rather than being quietly folded into a total.
