# Can Patreon's stored identity solve `/u/matt`? — measured on LIVE, 2026-07-28

**Short answer: it fixes 6 of the 46, and proves the other 40 are not fixable from any data we
hold. Vanity-first would fix none of them and would make 34 other members worse.**

Source: `wp_usermeta.patreon_latest_patron_info` on LIVE (`looth_import`), 1,585 rows, already
synced — no API access needed. Read via the existing `looth_ro` grant; **no grant was widened**.
Only `id`, `first_name`, `last_name`, `full_name`, `vanity` were extracted; **email and
image_url were deliberately left on the live box.** Nothing was written to live. Derivation uses
the shipped `Slug` class, fed through the `--api-fixture` shape `backfill-slugs.php` already
accepts, so no second deriver exists to disagree with the first.

---

## Q1 — coverage. What does Patreon actually hold for these members?

| | all 136 bare names | the 46 contested |
|---|---|---|
| has a `last_name` | **14** | **6** |
| has a `vanity` | **0** | **0** |
| has both | 0 | 0 |
| **has NEITHER** | **122** | **40** |

## The finding that reframes the whole question

The premise was that the derivation only saw "Matt" and could not see a surname. **For this
group, Patreon does not have the surname either.**

(Denominators are members with a `patreon_latest_patron_info` row: 129 of the 136 bare names,
1,360 of the 1,390 hyphenated. The 1,585 rows do not cover every member.)

| population | Patreon `last_name` present |
|---|---|
| members whose slug is a **bare first name** | **14 / 129 — 10%** |
| members whose slug is **hyphenated** | **1,240 / 1,360 — 91%** |

That gap is the answer. A bare first name in our store is not an import accident that dropped
the surname — it is a faithful copy of a Patreon profile that only ever had a first name. The
member gave Patreon "Matt" and nothing else.

So for 122 of the 136, "Matt" is not a truncation of a fuller name we could recover. It is the
only name that exists for them anywhere in our data or Patreon's.

---

## Q2 — does `last_name` resolve the contests, or create new ones?

**It resolves 6, cleanly, with zero new collisions.** All six land on a free handle, and each
frees a hotly contested bare name:

| member | our display_name | current proposal | with surname | frees `/u/x` for |
|---|---|---|---|---|
| 1550 | `Jeff` | `/u/jeff` | **`/u/jeff-ferk`** | 15 other Jeffs |
| 1554 | `Steve` | `/u/steve` | **`/u/steve-mcdonald`** | 14 other Steves |
| 1229 | `Tim` | `/u/tim` | **`/u/tim-staver`** | 8 other Tims |
| 1544 | `Neil` | `/u/neil` | **`/u/neil-walwer`** | 5 other Neils |
| 1539 | `Pete` | `/u/pete` | **`/u/pete-marten`** | 4 other Petes |
| 65 | `Max _` | `/u/max` | **`/u/max-bierman`** | 2 other Maxes |

For the rest of the contested set:

| outcome | count |
|---|---|
| resolve cleanly to a free surname handle | **6** |
| Patreon's `full_name` derives to the *same bare handle* (no surname there either) | **38** |
| no usable `full_name` at all | 2 |
| **new collisions created by the rule** | **0** |

Two Matt Smiths never materialise — the rule is safe. It simply has almost nothing to work with.

---

## Q3 — vanity-first: the delta

**Vanity is the strongest *provenance* signal and the weakest *name* source, and the data is
lopsided enough to settle it.** Only **37 of 1,585 members (2.3%)** have one; 1,548 are
explicitly null.

| population | members with a vanity |
|---|---|
| the 46 contested | **0** |
| the 136 bare names | **0** |
| the 107 awaiting a ruling | **1** |
| the 1,526 already changing | 35 |

**How many of the 46 move under vanity-first: zero.** It cannot help them — none has one.

Across the whole run it would move **34 members who already get a good name-derived handle**,
and this is what it would do to them:

| member | display_name | current proposal | vanity-first |
|---|---|---|---|
| 33 | Guinevere Gracewood-East… | `/u/guinevere-gracewood-easther` | `/u/poseidon` |
| 216 | Bob H Abernathy III Stras… | `/u/bob-h-abernathy-iii-strasburg` | `/u/roarschachmuckracken` |
| 120 | Michael Bashkin Bashkin… | `/u/michael-bashkin-bashkin` | `/u/luthieronluthier` |
| 153 | Jason Verlinde | `/u/jason-verlinde` | `/u/vintageamps` |
| 427 | Ted Woodford | `/u/ted-woodford` | `/u/twoodfrd` |

Some are defensible (`twoodfrd`, `tadyka`, `urquidiguitars` — trade names). Several replace a
named luthier's own name with a pseudonym. As a *global* rule it contradicts R1 (the slug is the
display name, cleaned) and works against the point of the lane, which is URLs made of people's
names.

**It is right about one member.** Ruling row 732, display_name `Seb`, vanity `meeloo` — a real
self-chosen handle for someone whose stored name cannot be derived from. That is exactly the
case the existing expansion chain (`full_name` → `vanity` → `email`) is already built to catch;
it just never had the data. **Recommendation: keep vanity where it is — a fallback used when
there is nothing better — rather than promoting it above a real name.**

---

## Q4 — where our store and Patreon disagree (flagged, not acted on)

15 of the 136. Notable because in several, **our stored name is the damaged one**:

| member | ours | Patreon `full_name` |
|---|---|---|
| 168 | `powersdj1 .` | Dave Powers |
| 65 | `Max _` | Max Bierman |
| 704 | `CRC` | Clancey Compton |
| 822 | `alsato` | Al Sato |
| 341 | `UptheArsenal` | David *(no last_name)* |
| 1547 | `Tadyka` | Tadyka Mykyta |
| 100 | `Kawai` | Kawai at Coast 'Ukulele |
| 744 | `Holger` | Holger (Rausch Guitars) |

Plus 1229 Tim/Tim Staver, 1539 Pete/Pete Marten, 1544 Neil/Neil Walwer, 1550 Jeff/Jeff Ferk,
1551 Dane/Dane Johnson, 1554 Steve/Steve McDonald, 1560 Tadd/Tadd Garcia.

**Nothing here has been copied into our store, and nothing should be without a separate ruling.**
R4 stands: we do not overwrite a name a member may have chosen. Note the distinction — giving
member 1550 the slug `/u/jeff-ferk` does **not** rewrite their display_name, and their current
URL is `patreon_<id>`, so no existing handle is taken away from them either. Choosing between
`/u/jeff` and `/u/jeff-ferk` is choosing what to give someone, not what to take.

---

## The thing this exercise actually caught — read this before anything else

**Supplying the identity data switches on a path that gives 40 members a public URL made from
their email address.** It is not new code and nobody chose it: the collision-expansion chain
ends `full_name → vanity → patreon email → account email`, and the applied branch requires a
`machine-seeded` verdict, which **cannot be reached without an identity map**. So it had never
executed. The moment the data exists, it fires.

| member | display_name | would have been given |
|---|---|---|
| 595 | Katie Mccartney | **`/u/hxn7djggwx`** |
| 212 | Ethan Hughes | `/u/hughese1` |
| 721 | Michael Minton | `/u/mminton01` |
| 795 | Jim Fenton | `/u/jf13fox` |
| 1178 | Robert Vogt | `/u/ravogt359` |

An email local-part is not a name, and publishing it puts part of a member's private address in
a permanent public URL. **Now gated behind `--allow-email-derived-slugs` (off by default)**;
without it these stay a suggestion for a human, exactly as they were. `/u/hxn7djggwx` is the
whole argument.

## Final measured outcome

Recommended run — `--api-fixture=… --expand-bare-names` (email gating left ON, i.e. default):

| | baseline | with identity data |
|---|---|---|
| would change | 1,526 | **1,527** |
| need a ruling | 107 | **106** |
| bare first names expanded to a surname | 0 | **14** |
| contested bare handles freed | 0 | **6** (`jeff` `steve` `tim` `neil` `pete` `max`) |
| self-chosen vanity honoured | 0 | **1** (732 `Seb` → `/u/meeloo`) |
| **public URLs derived from an email** | 0 | **0** |

With `--allow-email-derived-slugs` it becomes 1,591 changing / 42 ruling — but 40 of those are
email-derived. **The ruling queue does not drop from 107 to 42 for free; that discount is paid
for in members' email addresses.**

Fourteen expansions also repair members whose *stored* name is the damaged one — `powersdj1 .` →
`/u/dave-powers`, `CRC` → `/u/clancey-compton`, `alsato` → `/u/al-sato`, `Max _` →
`/u/max-bierman`. Their display_name is untouched; only the URL improves.

## What this means for the decision

| | members helped | cost |
|---|---|---|
| **surname rule for bare names** | **6 of 46** — and it frees `/u/jeff`, `/u/steve`, `/u/tim`, `/u/neil`, `/u/pete`, `/u/max` | none measurable: 0 new collisions |
| vanity-first, globally | 0 of 46 | 34 members lose a name-based handle for a pseudonym |
| vanity as a fallback (**already how it works**) | 1 ruling row (732 `Seb` → `/u/meeloo`) | none |
| do nothing | 0 | 46 contested handles allocated by which import carried a first name |

**Recommendation: run with `--api-fixture=<map> --expand-bare-names`, leave email gating ON, and
hold the remainder with `--hold-bare-names`.** That takes the 6 contested handles off the table,
honours the one member who chose their own, repairs 14 damaged names, and publishes nobody's
email address.

The honest limit: **this data does not solve `/u/matt`.** It solves 6 of the 46. For the other
40, Patreon holds a first name and nothing else, so no rule can distinguish them — the question
of whether one Matt should hold `/u/matt` while 18 others do not remains a judgement, not a
derivation. What changed is that it is now a judgement about 40 people instead of 46, and we can
say with evidence that no further data exists to improve it.
