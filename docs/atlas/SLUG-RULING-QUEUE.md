# The 107 members awaiting a ruling — decision frame

Measured on **LIVE (loothgroup.com), 2026-07-28**, from the dry run at
`~/lane-reports/slug-backfill/LIVE-dryrun-2026-07-28.html`. These are the members the backfill
deliberately will **not** touch. The other 1,526 need no decision.

There are three separate questions here, not one queue. Answering them in this order collapses
the queue fastest, because the first one absorbs 92 of the 107.

---

## Question 1 — the 99 collisions are a DUPLICATE-ACCOUNT question, not a naming one

99 rows contend for **50 handles**: 38 pairs, 4 triples, 1 group of four, and 7 single members
blocked by a handle someone outside the candidate set already holds.

**Of the 43 multi-member groups, zero are two different people who want the same handle.**
41 groups are members sharing a byte-identical display name. The 2 that differ, differ only as
text — the same person written twice:

| handle | the "different" names |
|---|---|
| `david-foster-fostino-guitars` | `David Foster Fostino Guitars and Ukuleles` vs `David Foster Fostino Guitars &amp; Ukuleles` — `and` vs `&`, one of them entity-damaged |
| `roberto-reani-reani-guitars` | `Roberto Reani Reani guitars` vs `Roberto Reani Reani Guitars Roberto Reani` — the name doubled |

So there is no "who gets the handle" fight anywhere in this queue. Every group is **one identity
appearing two-to-four times in the data**. 35 of the 41 same-name groups have their members on
different email domains (typically a personal address and a business one).

**What this lane cannot tell you, and will not guess:** whether a pair is one human with two
accounts, or two humans who share a name. Nothing in `users`, `profiles` or the bridge records
it — see the refused-inference note in the contract. It is not a slug question.

**Options:**

| | |
|---|---|
| **A — resolve upstream as a merge, then re-run (recommended)** | Treat these as the duplicate-identity problem they are. Every group that merges stops being a collision, and the ruling queue drops from **107 to about 15**. Costs nothing here: the backfill is re-runnable and picks up the survivors automatically. |
| B — rule them one at a time | 50 individual decisions, each needing the same evidence a merge review would need anyway. |
| C — leave all 99 on Patreon URLs | No decision needed, but these members keep the ugly URL indefinitely, including the 2 above that are plainly one person. |

**Recommendation: A.** These 92 rows are not waiting on a naming rule; they are waiting on
someone to say whether those are duplicate accounts. Merging is also the only option that fixes
the underlying data rather than routing around it.

---

## Question 2 — 4 names have no Latin characters

| id | name | script |
|---|---|---|
| 271 | 순간의미학 | Korean |
| 838 | 祁磊 | Chinese |
| 1373 | 博祥 游 | Chinese |
| 1411 | ビック | Japanese |

We never latinize a member's name, so there is no honest derivation and the deriver refuses
rather than romanizing. (Deliberate: `Slug::derive` folds Latin-ASCII only, never `Any-Latin`,
which would turn 祁磊 into someone's guess at 祁磊.)

**Options:** leave them on the Patreon URL (status quo, no harm added) · let each member choose
their own handle when they next visit · rule that these may be romanized, which reverses a
standing contract decision and should not be done implicitly.

**Recommendation: leave them, and route it into whatever lets a member pick their own handle.**
It is their name; they are the right party to transliterate it.

---

## Question 3 — 4 names are shorter than the handle minimum

`BB` (406), `G` (1102), `KJ` (1325), `Bo` (1553). These derive to perfectly good Latin —
`bb`, `g`, `kj`, `bo` — and fail only the 3-character floor (`Slug::MIN_LEN`).

**This was previously reported as part of the non-Latin group, which was wrong** and would have
drawn a ruling about romanization that does nothing for them. They are now split out; nothing
here needs transliterating.

**Options:** lower the floor to 2 (note `g` would still fail at 1, and short handles are the
most contested namespace on any site) · pad from a fuller identity where one exists · leave them
on the Patreon URL.

**Recommendation: leave them for now.** It is 4 members, and lowering a global floor to serve 4
people opens the shortest handles on the site to whoever asks next. Worth revisiting only
alongside a general "claim your handle" flow.

---

---

## Question 4 — who gets `/u/matt`? (this one is decided BY APPLYING, so decide it first)

Not in the 107. These 136 members are in the **apply** set, and the apply hands out the most
contested handles on the site as a side effect.

136 proposals are a single token — `matt`, `jeff`, `steve`, `tom`. **46 of them are also the
first name of other members in the same run**, who get the hyphenated form:

| handle | goes to | others in this run sharing that first name |
|---|---|---|
| `/u/matt` | member 1416, display_name `Matt` | 18 |
| `/u/jeff` | member 1550, display_name `Jeff` | 15 |
| `/u/steve` | member 1554, display_name `Steve` | 14 |
| `/u/tom` | member 417, display_name `Tom` | 14 |
| `/u/dan` | member 1480, display_name `Dan` | 12 |

**Not one of those 46 has any record of choosing their display name.** All 136 are provenance
`indeterminate`; of the 46 contested, 38 have no `claimed_via` at all and 8 have
`backfill_location`, which is a location backfill and not a name claim. Zero claimed via
`onboard`, `direct` or `menu`.

So the winner of `/u/matt` is decided by which Patreon import happened to carry a first name
only. **This is not proof those names are import artifacts** — R4 says we do not infer that, and
refusing to infer it is exactly why the no-suffix rule exists. It is a reason for a person to
decide before the handles are gone rather than after: once assigned and 301ing, taking one back
costs a member their working URL.

**Options:**

| | |
|---|---|
| **A — apply the other 1,390 now, hold the 136 (recommended)** | `--apply --hold-bare-names`. The 1,390 unambiguous members get their names today; the contested namespace waits for a decision that is genuinely yours. Costs those 136 members nothing they are not already living with — they keep the Patreon URL they have had all along. |
| B — apply all 1,526 as planned | Fastest, and defensible: R1 says the slug is the display name cleaned, and "Matt" *is* his name as stored. Accepts that the prime handles go by import accident. |
| C — hold the 136 and expand them from Patreon identity later | Would yield `matt-brennan` rather than `matt`, and leave `/u/matt` for someone who asks. Needs the creator token, which is not on this box — so it is a second pass, not a variant of this one. |

**Recommendation: A.** It is the only option that keeps every door open: nothing is spent, the
1,390 clear cases land immediately, and C stays available. The flag is off by default and holds
all 136 single-token handles (the conservative superset of the 46 genuinely contested).

---

## What answering these is worth

| | now | after Q1 | after all three |
|---|---|---|---|
| members on a Patreon-id URL | 1,634 | 1,634 | — |
| …after the backfill applies | 108 | ~16 | ~8 |

The 8 that remain under every option are members whose own name cannot become a handle without
either inventing letters for them or letting them choose. That floor is correct, not a shortfall.

*Emails behind the pair analysis: `~/lane-reports/slug-backfill/GHOST-AND-DUPES-LIVE-2026-07-28.txt`,
mode 0600, deliberately not in this repo.*
