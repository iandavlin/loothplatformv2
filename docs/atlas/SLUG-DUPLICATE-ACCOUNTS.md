# Duplicate accounts — measured on LIVE, 2026-07-29

**Nothing here has been merged, and nothing should be merged off a name match.** This is
evidence for Ian to rule on, per case or per pattern.

Ian's question was the right one: *"why aren't those slugs based on the user name? there are
perfectly good names there."* Chasing it changed the diagnosis. `/u/hxn7djggwx` was never a
missing-name problem — Katie McCartney has a perfectly good name. It is the **last resort of a
collision tiebreak**, and the collision exists because there are two accounts.

---

## First, two corrections to the framing

**The IDs in the brief are WP user IDs, not profile-app member IDs.** Both spaces are small
integers and they do not line up, so a command aimed at the wrong one hits an unrelated member.
Ian's four:

| WP id | profile-app member | who |
|---|---|---|
| 551 | **482** | Katie McCartney |
| 677 | **595** | Katie Mccartney |
| 907 | **790** | Jim Fenton |
| 912 | **795** | Jim Fenton |

(WP 551 as a *member* id is "admin admin"; WP 907 as a member id is "Kenny". Worth being exact.)

**The population is 51 groups / 110 accounts, not 52 / 113** — the difference is filter, not
disagreement:

| filter | groups | accounts |
|---|---|---|
| every row with a display_name | 62 | 134 |
| bridged (can log in) | 52 | 112 |
| **bridged AND not archived — real members today** | **51** | **110** |

---

## Q1/Q2 — classification, with the evidence that fired

| verdict | groups |
|---|---|
| **SAME HUMAN** (strong evidence) | **19** |
| SAME HUMAN (likely) | 1 |
| UNDECIDABLE — full name, no corroborating signal | 16 |
| DIFFERENT PEOPLE (likely) | 15 |

Evidence rules, strongest first. A verdict names which fired; nothing is inferred from the name
alone:

- **E1** identical/related email — same local-part, a `looth-NNN@invalid` placeholder, or one
  twin on **Apple Private Relay** (`privaterelay.appleid.com`). Private Relay is an *alias
  issued to the same person* by "Sign in with Apple" — not a different human's address. 46
  accounts use one; 4 of them sit in a duplicate group.
- **E2** identical Patreon avatar `image_url`
- **E3** one twin completely inert — no connections, messages, threads, socials, instruments,
  never claimed a profile
- **E4** same `location_text` · **E5** created within a day · **E6** shared name is a FULL name

**23 of the 51 groups are a bare first name** (David ×4, Paul ×4, Chris, Dave, Mark, Michael
×3). Those are the "DIFFERENT PEOPLE" bulk and deserve no action — two Daves is not a defect.
**28 are full names**, where coincidence is a much weaker explanation.

### The decisive negative result

**Not one group shares a Patreon ID.** 30 groups have two *different* Patreon IDs; 21 could not
be checked (no `patron_info` row). So every duplicate here is **two distinct Patreon accounts** —
not two member rows for one Patreon account. That matters: it means these were not created by
our import doubling up. The person really does have two Patreon accounts, or they are two people.

### Ian's two cases

| | member | Patreon | email | connections |
|---|---|---|---|---|
| **Katie** | 482 (wp 551) | `patreon_51130782` | katiemccarts@gmail.com | 7 |
| | 595 (wp 677) | `patreon_112642237` | hxn7djggwx@**privaterelay.appleid.com** | 6 |
| **Jim** | 790 (wp 907) | `patreon_130032537` | jim.fenton66@gmail.com | 8 |
| | 795 (wp 912) | `patreon_691393` | jf13fox@yahoo.com | 7 |

Katie's second account is an Apple Private Relay signup — the same person coming back through a
different door. **That is also the direct answer to Ian's question about `/u/hxn7djggwx`: it is
not a name at all, it is an Apple relay token**, which is exactly what publishing an email
local-part gets you.

Jim's two are a personal Gmail and a Yahoo address whose local-part (`jf13fox`) matches his
initials. Suggestive, not conclusive — classified SAME HUMAN on E6 + E5, and worth a human look.

**Neither twin in either pair is an empty shell.** Both hold connections. A merge is not a
delete.

---

## Q3 — what a duplicate costs today

Across all 110 duplicated accounts:

| | |
|---|---|
| connections held | **559**, across 109 of the 110 accounts |
| messages sent | 21, from 3 accounts |
| message threads joined | 9 |
| profile socials | 4, on 2 accounts |
| instruments / practices | 0 / 0 |
| **claimed a profile** | **22 of 110** |

So the cost is **almost entirely connections**. Nearly every duplicate holds some, which means:

- a member's connections are **split across two accounts**, and neither shows the full set;
- whoever connected to the "wrong" twin has an edge to someone who never reads it — the twin has
  no claimed profile and, in the `@invalid` cases, no reachable email;
- a merge must **union the edges**, not pick a side. Deleting the lesser twin would silently
  destroy real connections on 109 accounts.

Content loss risk beyond that is small: 3 accounts hold messages, nobody holds practices.

---

## The bigger finding: 115 accounts with no real email address

Not in the brief, and it dwarfs the duplicate question.

| | |
|---|---|
| accounts whose `primary_email` is `looth-NNN@invalid` | **115** |
| created 2026-05-26 / 2026-06-11 | 114 / 1 |
| **bridged — they can log in** | **114** |
| have a Patreon record (`patron_info`) | **101** |
| have an avatar | 115 |
| hold connections | **112 accounts, 216 connections** (83 accepted, 133 pending) |
| sent a message | 1 |
| **ever claimed a profile** | **0** |
| duplicate a real account's display_name | 17 |

These are **not phantoms** — 101 are real Patreon patrons with avatars, and other members have
*accepted* 83 connection requests involving them. But not one has ever claimed a profile, and
none has a reachable email address, so they cannot receive a notification or reset a password.
They can still sign in through Patreon OAuth, which is presumably how some acquired connections.

14 of the 51 duplicate groups are exactly this shape: **one `@invalid` shadow beside one real
account.** So a meaningful slice of the "duplicates" are not two Patreon accounts at all — they
are one member plus an import artifact from 2026-05-26 holding some of their edges.

**This is its own investigation and I have not attempted a cause.** What is certain is that 115
members exist who cannot be emailed, and 216 connections are attached to them.

---

## Q4 — what the slug rule should be, given the answer

1. **Do not allocate a permanent public URL to an account that may be about to merge.** A slug
   change also mints a `slug_history` row — a 301 for an account that should not exist. Cheap to
   avoid now, unpickable later.
2. **The email fallback should never have been reachable, and this proves why.** It exists only
   to break a tie between two accounts, so every time it fires it is papering over a duplicate
   with a member's private address. `/u/hxn7djggwx` is an Apple relay token; `/u/jf13fox` is
   Jim's Yahoo local-part. `--allow-email-derived-slugs` stays OFF.
3. **The apply is almost entirely decoupled from this — measured, not assumed.** Of the 110
   duplicated accounts:

   | where they sit in the plan | count |
   |---|---|
   | already held as a collision ruling | **93** |
   | not candidates (their slug is already fine) | 12 |
   | **would have been given a URL by the apply** | **5** |

   The system was already holding 93 of them back, because two accounts with the same name
   derive the same slug and collide. Only 5 slip through — the first mover of a pair, who wins
   the handle uncontested *precisely because* their twin is stuck in the ruling queue. Those 5
   are exactly Ian's concern, so `--hold-duplicate-names` now withholds them:

   | member | name | would have taken | connections |
   |---|---|---|---|
   | 123 | Mark Adams | `/u/mark-adams` | 16 |
   | 530 | Charles Fox | `/u/charles-fox` | 15 |
   | 732 | Seb | `/u/meeloo` | 10 |
   | 383 | Matt Rubendall | `/u/matt-rubendall` | 4 |
   | 1755 | Matthew McIntosh | `/u/matthew-mcintosh` | 2 |

   **So holding the whole backfill for this investigation costs 1,482 members their fix in
   order to protect 5.** That is Ian's call, but the coupling is 5 rows, not the whole run.
4. **After a merge the question dissolves.** If Katie's two accounts become one, nobody is
   competing for `katie-mccartney` — she gets it. The tiebreak was never the problem.

**None of the 40 withheld bare first names is a duplicate** (checked: 0 of 40). The two holds
are independent populations, so resolving duplicates will not shrink the 40 — I said it might
before measuring, and it does not.

*Row-level evidence including full email addresses:
`~/lane-reports/slug-backfill/LIVE-dupes-2026-07-29.tsv` (0600, not in this repo).*
