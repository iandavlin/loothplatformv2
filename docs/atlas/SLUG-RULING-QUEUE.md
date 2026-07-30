# The ruling queue — ALL FOUR QUESTIONS DECIDED

Ian ruled every open question on **2026-07-29 (evening)**, via keeper decision boxes. Nothing on
this page is waiting on him any more. It is kept as the record of what was decided and why, and
re-measured against **post-merge LIVE (loothgroup.com), 2026-07-29** so the numbers beside each
ruling are the ones the apply will actually produce.

Source of the numbers: `~/lane-reports/slug-backfill/LIVE-guarded-2026-07-29.{tsv,html}`
(post-merge export, email guard in place). Every figure below is **LIVE**, not dev2.

| | before the rulings | now |
|---|---|---|
| members in the run | 1,836 | **1,806** (30 merged twins retired) |
| the apply writes | 1,482 | **1,494** |
| awaiting a ruling | 107 | **0 open questions** — 111 rows deliberately left alone |

---

## Question 1 — the collisions were a DUPLICATE-ACCOUNT question — RULED: resolve upstream

**Ruled exactly as Option A recommended: merge upstream, then re-run.** Applied on live
2026-07-29 by the dupe-merge lane. **30 pairs merged**, each retired twin now carrying
`merged-<wp_id>@retired.invalid` and `archived_at` set. Verified read-only from live by this
lane: 30 such rows exist, all archived, all still bridged.

> The merge lane's own report says 29. Live says 30 — Michael Minton (survivor member 721 /
> wp 828, twin member 1164 / wp 1313) was ruled and merged after that report was written.
> This lane trusts the box, not the memo.

It worked, and this is what it bought:

| | pre-merge | post-merge |
|---|---|---|
| collisions needing a ruling | 98 | **56** |
| accounts held for sharing a display_name | 5 | **2** |
| members the apply acts on | 1,482 | **1,494** |

Every one of those movements is accounted for, member by member — no row changed category for
any reason other than the merge:

- **28** rows left the plan entirely (the merged twins; the other 2 of the 30 were never
  candidates, their slugs were not Patreon ids)
- **19** collisions dissolved and now get their real name — `/u/katie-mccartney`,
  `/u/jim-bonnell`, `/u/michael-minton`, `/u/aaron-lucas`, …
- **2** duplicate-name holds released
- **1** collision (member 310, "Kyle") became a *contested bare name* instead: his twin merged,
  so the collision died, but "Kyle" is a bare first name other members share — so the standing
  bare-name ruling picks him up. This is why the contested-bare hold went 40 → 41.

### Four look-alike pairs were ruled LEFT ALONE — likely distinct people

`mcneill 1340/338`, `goulart 890/814`, `morrissey 1130/1129`, `fox 603/799` (WP ids). Their
collisions therefore **stand**, and they are routed through normal collision handling: all four
sit in the run with **no proposal**, keeping their Patreon URL.

Confirmed in the post-merge plan — McNeill, Goulart and Morrissey (both accounts each) are
`3-COLLISION-NEEDS-RULING` with an empty proposal; Charles Fox is `8-HELD-DUPLICATE-NAME`; the
Fox twin (wp 799) proposes no change at all.

> A note kept from the merge evidence because it is still true and still Ian's: four of these
> members hold **two live pledges** (Cox ≈$10/mo, Morrissey ≈$264/yr, Goulart ≈$264/yr, Bonnell
> $11/mo + $132/yr). That is a billing conversation, not a slug one.

### Four pairs remain unmerged and unruled — and none of them blocks this

Ira Cox (895/566, "contact the member first"), Derek Taylor (1574/676), Kurt Smith (1333/1154),
Vincent Jaeger (1690/1516) — the three poller-id-crossed pairs. They are held exactly like the
four above: no proposal, Patreon URL kept. **The apply does not touch them and does not wait
for them.**

---

## Question 2 — the 4 non-Latin names — RULED: LEAVE

| member | name | script |
|---|---|---|
| 271 | 순간의미학 | Korean |
| 838 | 祁磊 | Chinese |
| 1373 | 博祥 游 | Chinese |
| 1411 | ビック | Japanese |

**They keep their current URLs until a choose-your-own-handle feature exists.** No romanization.
The contract is intact: `Slug::derive` folds Latin-ASCII only and never `Any-Latin`, so the
deriver refuses rather than guessing at someone's name.

Category `0-NO-HONEST-SLUG`, 4 rows, no proposal. Confirmed present and unchanged post-merge.

---

## Question 3 — the 4 short names — RULED: LEAVE

`BB` (406), `G` (1102), `KJ` (1325), `Bo` (1553). They derive to perfectly good Latin — `bb`,
`g`, `kj`, `bo` — and fail only the 3-character floor (`Slug::MIN_LEN`).

**Same route as Q2: they keep their current URLs until a member can choose their own handle.**
The floor is not lowered. Lowering a global minimum to serve 4 people would open the shortest
handles on the site to whoever asks next, and `g` would still fail at 1 character.

Category `0b-NAME-TOO-SHORT`, 4 rows, no proposal. Confirmed present and unchanged post-merge.

---

## Question 4 — who gets `/u/matt`? — RULED: NOBODY, and the conservative default STANDS

**A contested bare first name goes to nobody.** `/u/matt`, `/u/jeff`, `/u/steve` and the rest
stay unallocated for a future flow where a member actively asks for one. The flag is
`--hold-contested-bare` and it is **on** in the apply command.

**All 136 single-token handles remain held** — the conservative default stands, as ruled.

Why nobody rather than someone: Patreon holds a `last_name` for only **10%** of bare-name
members against **91%** of everyone else, so the surname is genuinely unrecoverable. "Matt" is
not a truncation we can undo; it is the only name that exists for him anywhere. With no basis to
choose between 19 Matts, the honest answer to no-basis is that nobody gets it — rather than
letting whichever Patreon import happened to carry a first name only decide it.

Currently **41** members are held this way (40 + Kyle, see Q1).

**14 bare names were expanded and are NOT held**, because a real surname is not an import
accident: `/u/jeff-ferk`, `/u/steve-mcdonald`, `/u/tim-staver`, `/u/neil-walwer`,
`/u/pete-marten`, `/u/max-bierman`, plus 8 uncontested expansions that also repair a damaged
stored name (`powersdj1 .` → `/u/dave-powers`, `CRC` → `/u/clancey-compton`, `alsato` →
`/u/al-sato`). One member keeps their own choice: 732 `Seb` → `/u/meeloo`, their self-chosen
Patreon vanity, honoured over a generic first name.

---

## Question 5 — NEW, found while re-running: a display_name that IS an email address

**Not previously in this queue, and it needed no ruling to fix — it needed stopping.**

Four members in the apply set have an **email address stored as their display_name**. R1 says
the slug is the display name cleaned, so the deriver worked exactly as designed and proposed:

| member | wp | display_name | would have become |
|---|---|---|---|
| 965 | 1102 | mdoran2000@aol.com | `/u/mdoran2000-aol-com` |
| 998 | 1139 | ziemerjp@gmail.com | `/u/ziemerjp-gmail-com` |
| 1120 | 1267 | richard.c.miller@ntlworld.com | `/u/richard-c-miller-ntlworld-com` |
| 1812 | 1415 | kvern@adm.dtu.dk | `/u/kvern-adm-dtu-dk` |

This is the **second door** onto the harm `--allow-email-derived-slugs` already guards, and that
flag could never have closed it: nothing on this path reaches for an email, the email is already
the name. Per `docs/CRAFT-STANDARD.md` a defect class found twice is encoded, not re-fixed — so
it is now a category, `0d-NAME-IS-AN-EMAIL`, held with no proposal. **Acting-on 1,498 → 1,494**
(commit `c6aba90`).

**Two more members already have this live today**, and the check is placed to surface them:
`/u/alrightguybellsouth-net` (member 1876) and `/u/thomadkinstelus-net` (member 1898), both
**200 OK on live**. Their stored slug already agrees with their name, so the ordinary defect
test returns "healthy" and they would never have appeared in any report. This run cannot fix
them — the cure is a real name, not a new slug — but it must not hide them.

**Measured, not assumed:** all six addresses **already render on the public, unauthenticated
site** as those members' display names (fetched from live: `/u/patreon_147222904` serves
`mdoran2000@aol.com`; `/u/alrightguybellsouth-net` serves `alrightguy@bellsouth.net`).

**For Ian, and NOT blocking the apply:** six members are publicly displaying their email address
as their name on loothgroup.com right now. The backfill no longer makes it worse. Making it
better means asking those six for a name, which is a message to a member, not a script.

---

## What is left on a Patreon URL after all of this

| | count |
|---|---|
| on a `patreon_<id>` URL today | 1,634 |
| the apply gives a real name to | **1,494** |
| **left afterwards** | **140** |

The 140 are fully accounted for — measured on live, not inferred:

| | count | why it stays |
|---|---|---|
| unresolved collisions | 56 | two members clean to one handle; no basis to pick |
| contested bare names | 41 | Q4 — nobody gets `/u/matt` |
| non-Latin names | 4 | Q2 — we never latinize |
| names under the length floor | 4 | Q3 — floor not lowered |
| display_name is an email | 4 | Q5 — never publish an email |
| held duplicate-name | 2 | Fox pair + Seb |
| **archived accounts** | **28** | merged twins — not candidates, and correctly so |
| **unbridged ghost** | **1** | cannot log in; ghost-containment rule keeps it out |
| | **140** | |

Of these, **8 are a permanent floor** under every option (the 4 non-Latin + 4 too-short) —
members whose own name cannot become a handle without either inventing letters for them or
letting them choose. That floor is correct, not a shortfall. The rest resolve when a duplicate
is merged, a member picks a handle, or a member supplies a real name.

*Emails behind the pair analysis: `~/lane-reports/slug-backfill/`, mode 0600, deliberately not
in this repo.*
