# Backlog 20 — directory location never exceeds City/State

**Branch** `directory-location` · **Seat** featured-members · **2026-08-15**
**Flag** `profile-app/config/directory-location.php` → `coarsen_list_location`, **OFF by default**

---

## 1. Read this first: this work exists TWICE

My predecessor in this seat already built backlog 20 and pushed it as **`cd0a2ed`
on branch `featured-members`** (unmerged, blocked on a gate number since 04:10).
My re-charter cut me a **new branch off main**, which does not contain that
commit, and told me I inherit the seat and not the conversation — so I rebuilt
the feature before finding their work on the board. The charter also pointed at
`handoffs/2026-08-15-featured-seed-undo.md`, **which does not exist on any
branch**. That is a respawn-flow gap, not a code problem, and it is the reason
two seats spent a night on one backlog item.

**I did not simply keep my own version.** I read their diff and reconciled;
each version was carrying half the answer. What landed here is the better half
of each, and the differences mattered.

## 2. The defect, measured

`dir_member_display()` in `profile-app/api/v0/directory-members.php` is the
shared coarsening point for **all three list surfaces** — the paginated list,
the map-pin feed, and the single-slug click-through stub. It rendered whatever
precision `Visibility::locationPrecision()` resolved, which meant:

| audience | what it got | measured |
|---|---|---|
| anon | rows whose `location_public_precision='street'` | **7 of 37** live rows are a full street address + postcode |
| member | rows at `location_members_precision='street'` | 14 members on live |
| **admin / owner** | **`street` for EVERY member, unconditionally** | **15–16 addresses per directory page**, ~1,900 members |

That last row is the one that reframes this. `precisionForAudience()` returns
`'street'` for `owner`/`me` and for `admin` regardless of the member's own dial
— correct for a single profile page, wrong for a list where every row gets it.
**This was never a 7-row public leak.** Credit where due: that was my
predecessor's find, not mine.

Ian's picture for the charter, member "Luke" (WP 2091), is **stale as a public
example**: his dials now read public=`private`, members=`city`, so his address
is not currently rendered to anon. His `location_text` still holds the full
street address, so the *store* is unfixed even though the *render* is currently
safe — and admins still saw it.

## 3. The fix, and why it is two clamps and not one

```php
$disp = Block::locationDisplay($place, $precision);
if (!Flags::bool(...)) return $disp;          // OFF: byte-identical
if ($disp === null) return null;              // hidden stays hidden
if ($precision === 'street') {                // (1) downgrade, re-render
    $precision = 'city';
    $disp = Block::locationDisplay($place, $precision);
}
if ($precision === 'city' || $precision === 'state') {
    $disp['text'] = Block::listLocationText($place, $precision);   // (2) structured only
}
```

**(1) Downgrade `street`→`city` before rendering** — my predecessor's approach,
and better than mine. It re-uses the existing city branch whole, so the **pin
coarsens with the text** (~1.1km, zoom 11, circle not marker). My original
capped only the text and left a zoom-15 exact marker on the house — which *is*
the address, drawn instead of spelled. I was wrong; theirs is what shipped.

**(2) Structured labels only** — my addition, which the downgrade alone does
not reach. A row with **no pin** never gets to the coarsening math at all:
`Block::coarseText()` falls straight through to the row's literal text. On live
6 rows have no pin and no structured city/region, and **2 of them hold a full
street address** rendered verbatim to every member and admin today
(`900 South 5th Street ste 103, Milwaukee…`, `2310 Ballan Road ANAKIE Vic 3213`).

It is a **hard rule, not an address-shaped heuristic**, deliberately: address
formats are not reliably detectable across countries and one miss is a leak.
Ian's wording — "City/State only *regardless of what the field holds*" — is
precisely the instruction not to trust that column.

`state` keeps its own level (`Region, Country`). Rewriting it to `City, Region`
would make a deliberately-vague row **more** precise; a privacy clamp may only
ever subtract.

### Honest cost
4 live rows hold real place names (`Teesside, UK`, `NYC & Lower Westchester`,
`Sparta , New Jersey`, `Wisteria Design Group Seattle Washington`) and will show
**no location line on list surfaces** until those rows are geocoded into
structured columns. `profile-app/bin/regeocode-from-bb.php` is the existing
path. Their profile pages are untouched. Flag OFF, nothing changes at all.

## 4. Two real bugs the gate caught in my own fix

Neither was found by inspection:

1. The downgrade turned **"no location" into an empty location object** for the
   admin audience — `locationDisplay()`'s `street` branch returns `null` when it
   has neither text nor pin, but its `city`/`state` branches always return an
   array. 4 dev2 rows flipped from `{hidden:true}` to a `kind:coarse` object.
2. My first fix for that then **hid rows OFF had shown** — over-corrected in the
   other direction.

The rule is now stated as **shape preservation**: the clamp may only subtract
*precision*, never *presence*. Both directions are asserted.

## 5. Gate 44

`tools/gates/directory-location-gate.py` + `directory-location-probe.php`,
registered in `run-all.sh` as **GATE 44** (keeper-allocated 2026-08-15). Not 41:
the ledger line read "NEXT FREE: 41" while 41 and 42 were already registered
above and 43 was spoken for by the offline-shell gate — it under-counted by
three. Keeper corrects the line separately, so this branch does not touch it.

- **A** — the rule, deterministically, over synthetic places. Cannot go vacuous.
- **B** — the real endpoint, 3 audiences × 3 surfaces, both flag states.
- **C** — red-first, **asserted against the database**: if street-precision rows
  exist, OFF is *required* to still print them. Without that, a clamp that
  stopped reading the flag would just make the OFF observations go quiet and the
  gate would stay green while OFF silently stopped being a no-op.
- **D** — presence preserved both ways; every exact pin coarsens; no
  already-coarse pin moves; no non-location field changes.
- **E** — flag file exists, defaults false, clamp reachable only behind it.

**GREEN — 72 assertions.** Flag OFF proven **byte-identical to main** on all 8
surface × audience pairs.

The probe drives the **real endpoint file** (included, not re-implemented) and
moves the flag with `Flags::forTest()`, which is **CLI-only by enforcement** —
so both states are exercised without ever editing the tracked config on disk.
The member/admin bearer goes over **stdin, never a temp file**: it is a real
signed credential and `/tmp` is world-readable.

### Flag mechanism note
`cd0a2ed` reads its flag with `@include __DIR__ . '/../../../platform/config/…'`
— hand-counted dots plus a silent-failure include. That is the exact bug class
the same seat fixed hours earlier in `9845a84` (`me_featured_flag_on()` missed
its flag file and defaulted OFF on the live-flipped serve). This uses the app's
own `Flags` class instead, which resolves via `src/` and reads strictly `=== true`.

## 6. Open questions for Ian / keeper

1. **Gate number** — still outstanding.
2. **Which branch lands** — this one or `featured-members` (`cd0a2ed`).
3. **Shops — RULED, 2026-08-15: no exemption.** 6 of the 7 public street rows
   have a `business_name` (guitar shops that turned street precision on so
   customers could find them), so this was raised as a product question, not
   only a safety one. Ian: **truncate everyone uniformly**, reasoning *"I'm
   going to trust that they can fix their position as it suits them"* — members
   adjust their own location text — and a proper **business profile** is coming
   later as its own feature. **No code change was needed**: the clamp keys on
   precision alone and never had a `business_name` branch. Do not add one
   without a new ruling.
4. **The store is still unfixed.** This changes rendering only. Luke's row, and
   the 2 text-only address rows, still hold street addresses in `location_text`.

## 7. Not done
- Not merged, not pushed to `main`, flag never switched on anywhere.
- The member's own `/u/<slug>` profile page is deliberately untouched.
