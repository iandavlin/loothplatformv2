# SLUG-PLACEHOLDER-RECURRENCE.md — why 146 members are still on `/u/patreon_<id>`

**Status: scoping complete, 2026-07-31. No code written yet, no live writes.**
Measured against LIVE (`siteurl = https://loothgroup.com`) over `ssh live-ro`, read-only.

Ian reported: "Scott" lives at `loothgroup.com/u/patreon_188933584`. He thought the
slug backfill fixed this. This document says what actually happened, because **the
headline finding contradicts the bug report**:

> **Of the 146, the number that are safe to auto-derive today is ZERO.**
> The backfill did not miss them. Ian's own rulings of 2026-07-29 held them back.

---

## 0. Ian's extra check first — IS ANYTHING DESTROYING GOOD SLUGS?

**No. Three checks, all clean.** There is no active destroyer, so it is safe to proceed.

| # | check | result | evidence |
|---|---|---|---|
| 1 | A member on a `patreon_` slug who has a **human** slug parked in `slug_history` (a good handle retired and replaced) | **0 rows** | join `users`→`slug_history` where current slug is patreon-shaped and the parked one is not |
| 2 | Do all 146 have `slug_changed_at IS NULL`? | **146 / 146 NULL** | consistent with "auto-minted placeholder, never deliberately set, never upgraded" |
| 3 | Can the `line 316` path write `patreon_<id>` back over a human slug? | **In code YES — on live it has never fired (0 rows)** | see below |

### Check 3 in detail — a latent hole, not an active one

`Provision::maybeSyncSlugFromName()` (the rename path, writes at `Provision.php:316`)
derives via `self::slugify()` → `Slug::derive()`, and **never calls `isPatreonJunk()`**:

```
$ grep -n isPatreonJunk profile-app/src/Provision.php
179:                if (self::isPatreonJunk((string) $cand)) continue;   # ensureSlug ONLY
241:    private static function isPatreonJunk(string $s): bool           # the definition
```

The docblock at `Provision.php:241-244` claims *"both the forward-fix (ensureSlug) **and
the name-change auto-sync** treat them as 'no handle set'"*. **That claim is false** —
there is no such guard in `maybeSyncSlugFromName`. And the derivation happily passes a
placeholder through:

```
patreon_188933584   derive=patreon-188933584   deriveUsable=patreon-188933584   checkShape=NULL (ok)
```

So if a member's `display_name` were ever set to `patreon_<id>`, the rename path would
overwrite their human slug with `patreon-188933584` **and park the good handle in
slug_history**. That is destruction, not omission.

**It has never happened on live**, and the fingerprint is distinctive — `derive()` turns
the underscore into a **dash**, so a clobbered slug reads `patreon-<id>` while every
genuine placeholder reads `patreon_<id>`:

| probe | live count |
|---|---|
| slugs matching `^patreon-[0-9]+$` (the clobber fingerprint) | **0** |
| `display_name` that is itself patreon-shaped (the only input that could trigger it) | **0** |

It is unreachable today only because no member's display name looks like a placeholder.
**Close it anyway** — it is one `isPatreonJunk` call, and the docblock already promises it.

---

## 1. Why the backfill left 146 — it was told to

The apply ran **2026-07-29** (1,494 `slug_history` rows released that day), taking live
from 1,634 patreon slugs to 146. Its own runbook predicted a residual of *108*, so 146
looked like 38 rows of overshoot. It is not. Cross-referencing today's candidates against
the apply-time report `~/lane-reports/slug-backfill/LIVE-RULED-2026-07-29.tsv` by user_id:

| what the 7/29 run recorded for them | count |
|---|---|
| `7-HELD-CONTESTED-BARE` | **40** |
| `8-HELD-DUPLICATE-NAME` | 1 |
| `3-COLLISION-NEEDS-RULING` | 1 |

Those are the two flags Ian added on 2026-07-29 (`--hold-contested-bare`,
`--hold-duplicate-names`), quoted from the tool's own source:

> *A bare first name that other members also carry goes to NOBODY. `/u/matt`, `/u/jeff` and
> the rest stay unallocated and free for a future flow where a member actively asks for one.*
> — `backfill-slugs.php:606-608`

**Scott is one of them.** `Scott` derives to the bare handle `scott`, and **eleven** live
members' names derive to that same first token. Handing `/u/scott` to member 1387 means
handing the site's scarcest handles to whichever Patreon import happened to carry a first
name only — the exact allocation Ian refused.

### The full 146, bucketed

| bucket | count | status |
|---|---|---|
| archived rows | 34 | **not members** — contract §8, out of scope |
| unbridged ghost | 1 | **not a member** — cannot log in |
| **live members** | **111** | held, see below |

Running the one deriver offline over the live export (`--from-tsv`, the documented
no-split-brain path) gives 113 candidates — the 111 above plus 2 whose `display_name` is
itself empty/placeholder, so they fall outside Ian's `146` filter:

| category | count | why it is not safe |
|---|---|---|
| contested bare first name | **41** | Ian 7/29: goes to nobody |
| duplicate-name account | **1** | Ian 7/29: may be about to merge |
| `3-COLLISION-NEEDS-RULING` | 57 | two members clean to one handle |
| `0d-NAME-IS-AN-EMAIL` | 6 | never publish an email as a URL |
| `0-NO-HONEST-SLUG` | 4 | would require latinizing the name |
| `0b-NAME-TOO-SHORT` | 4 | under 3 chars |
| **safe to auto-derive** | **0** | — |

### The last candidate standing, and why it also fails

Exactly one row derived to a full, unheld, uncontested handle: member **530 "Charles Fox"**
→ `charles-fox`. `--hold-duplicate-names` withholds it, and the data says correctly:

| id | display_name | slug | state |
|---|---|---|---|
| 530 | `Charles Fox` | `patreon_5944146` | live, bridged |
| 700 | `charles fox` | **`charlesfox`** | live, bridged |

Two live bridged accounts, the same human name, and 700 **already holds** `charlesfox`.
Minting `charles-fox` for 530 creates two near-identical permanent handles — plus a
`slug_history` 301 — for what may be one person with two accounts. That is the
duplicate-merge question (`SLUG-DUPLICATE-ACCOUNTS.md`), not a slug question.

**So: 0 safe, 111 needing a human ruling, 35 not members. There is no safe subset to
backfill.** The deliverable for Ian is a ruling queue, not a SQL script.

---

## 2. A SECOND BUG: re-running the tool silently REVERSES Ian's ruling

`--hold-contested-bare` counts how contested a name is **within the current run's own
candidate set**, not across the member population:

```php
// backfill-slugs.php:610-613
foreach ($actionable as $p) {                       // <-- only THIS run's candidates
    $firstNameCount[explode('-', $p['proposed'])[0]] = ...;
}
... if ($bare && ($firstNameCount[$p['proposed']] ?? 0) > 1) { hold }
```

On 7/29 the pool was 1,526 rows, so `scott` appeared 11 times and was held. **Today the
pool is 42 rows, so `scott` appears once — and the flag holds nothing:**

```
--hold-contested-bare: withholding 0 contested bare handle(s); acting on 41
```

The hold is self-erasing: the more successful the previous apply, the fewer candidates
remain, and the less contested every survivor looks. **A second `--apply` with the exact
flags Ian approved would hand out all 41 bare handles he ruled nobody gets.**

Measured properly — first token of *every* live member's derived name — all 41 are still
contested, several heavily: `matt` ×20, `tom` ×16, `dan` ×14, `james` ×11, `sam` ×11,
**`scott` ×11**, `aaron` ×10, `joel` ×10.

**Fix:** count the contest against all live members + all held handles (the owners export),
never against the run's own shortlist. Until that lands, **do not re-run the backfill on
live** — this is a foot-gun aimed at the scarcest handles on the site.

---

## 3. The recurrence — confirmed at `Provision.php:202`

```php
// Provision::ensureSlug — profile-app/src/Provision.php:169
if (trim((string) $cur->fetchColumn()) !== '') return;   // already slugged
...
// :202
UPDATE users SET slug = :s WHERE id = :i AND (slug IS NULL OR slug = '')
```

A `patreon_<id>` placeholder is neither NULL nor empty, so both the early return and the
`WHERE` guard treat it as a **real, member-owned slug** and refuse to upgrade it. The
placeholder is minted at first provision when no human name exists yet; the name arrives
later; nothing ever re-derives.

Member 1387 (Scott) is the exact shape: created `2026-05-25`, `updated_at`
`2026-07-31 08:00:35` — **the name landed this morning and the slug did not move.**

`isPatreonJunk` at `:179` already knows a placeholder is not a handle — it skips one as a
*source* candidate. The bug is that the same knowledge is not applied to the *existing
slug*. It is a one-place inconsistency, not a missing concept.

### Where the name-arrival path actually is

No new wiring is needed, and the obvious wiring is a dead end:

- `platform/mu-plugins/profile-sync.php:130-137` — the `profile_update` hook forwards
  **email** changes only. It returns at `:135` when the email is unchanged, so a WP
  display-name change **never reaches profile-app at all**.
- `Provision::maybeSyncSlugFromName` has exactly **one** caller —
  `api/v0/me-name.php:63`, the member editing their own name in the profile app.
- The path names actually travel is `Provision::ensure()`, whose
  `ON CONFLICT (uuid) DO UPDATE SET display_name = COALESCE(users.display_name, ...)`
  (`:84`) fills a previously-NULL name on any re-provision — the poller's blocking
  `ensureProfileIdentity` → `hooks/user-created`. `ensureSlug()` is called immediately
  after at `:126` **and returns early at `:169`.**

So the name arrival and the slug re-derive already happen in the same function call,
three lines apart. Fixing the guard makes new members self-heal on the next poller sweep
with no new hook.

---

## 4. What this lane should now do

1. **Fix the guard** (`ensureSlug`) so a `patreon_<id>` placeholder counts as "no handle" —
   re-derive only when a usable human name exists, uniqueness-guarded, and **respecting
   the contested-bare and duplicate-name holds**. Flag-gated, default OFF.
2. **Close the latent clobber hole** — one `isPatreonJunk` call in
   `maybeSyncSlugFromName`, which its own docblock already promises.
3. **Fix `--hold-contested-bare`** to count against the population, not the shortlist.
   Until then the backfill must not be re-run on live.
4. **No backfill SQL.** There is no safe subset. What Ian gets is the ruling queue —
   41 contested bare names, 57 collisions, 6 email-names, 4 unlatinizable, 4 too short,
   1 duplicate pair.

**Every number here is from live, read-only. Nothing was written to live.**
Row-level detail carries member emails and is kept outside the repo at
`~/lane-reports/slug-provision/` (0600).
