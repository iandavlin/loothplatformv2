# Location address text — plan

> **BUILT 2026-08-18, commit `5515757`** — approved by Ian via keeper. Built as
> written: the four files below, flag `prefer_typed_address` defaulted OFF, gate
> **73** asserting absent/OFF/ON. Not merged and not flipped — keeper merges, the
> flip is Ian's. Verified on the real row (user 190): OFF prints the old home
> address exactly as today, ON prints his shop; City/State identical in both.

## 1. What I'm solving

John Wilmink (Thomas Muse Guitars) edited his profile location to his shop. He
typed the address and dragged the map pin. The map moved and is correct. The
address text under it still shows his **home** address. He wants it to read
`5425 Warner Rd. #4, Valley View, Ohio 44125`. Reported 47 days ago.

## 2. What I found

His typed address **is** saved correctly. It is in `users.location_text`.

The profile prints a different column. At "Street address" precision,
`Block::locationDisplay()` (`profile-app/src/Block.php:645`) reads
`users.location_address` first, and only falls back to `location_text` if that
is empty.

`users.location_address` has exactly **one** writer in the whole codebase: the
one-time BuddyBoss import script (`profile-app/bin/snapshot-location-from-bb.php`).
No editor endpoint ever updates it — `me-location.php` does not mention it once.
So for any member who has changed their location since the import, that column is
frozen on their old address, and it wins over the new one.

The pin is unaffected, because the map reads `lat`/`lng` and the reverse-geocoded
city/region. That is why the map is right and only the text is wrong.

**How I verified it.** I ran the real render function against the real database
for his account (user 190), on dev2:

| | |
|---|---|
| what he typed (`location_text`) | `5425 Warner Rd. #4 Valley View, Ohio 44125` |
| what the page prints (`location_address`) | `4706 Pershing Ave, Parma, OH 44134, USA` |

I read live too (read-only). Same account, same two values. **Four members on
live are showing a wrong address right now**: ids 190, 590, 598, 1323. Twelve
more have the same split but sit at City/State precision, where this column is
not read, so they look fine.

## 3. Files I expect to touch

- `profile-app/src/Block.php` — at street precision, print what the member typed.
- `profile-app/api/v0/me-location.php` — write `location_address` when a member
  saves, so the column stops going stale for everyone else.
- `profile-app/config/location-address.php` — **new**, the off-switch.
- `tools/gates/` — one gate, asserting the flag absent / OFF / ON.

## 4. The off-switch

A new tracked config file `profile-app/config/location-address.php`, copying
`profile-app/config/directory-location.php` exactly. One key,
`prefer_typed_address`, **default false**, read with
`Flags::bool('location-address', 'prefer_typed_address')`.

- **OFF** — today's behaviour, byte for byte. Nothing on any profile changes.
- **ON** — the member's typed address wins over the frozen import column.

It arrives by `git pull`. No env var, no reload.

## 5. How Ian checks it on his phone

1. With the flag **OFF**, open
   `https://dev2.loothgroup.com/u/john-wilmink-thomas-muse-guitars` signed in.
   Location still reads *4706 Pershing Ave, Parma* — unchanged. That is the proof
   OFF is safe to merge.
2. Flip the flag **ON**. Reload the same page. It now reads
   *5425 Warner Rd. #4 Valley View, Ohio 44125*. The map does not move.

**One honest caveat.** On live his saved text is `5425 Warner Rd Valley View Ohio
44125` — no "#4", no commas. The fix shows what he typed, so live will show that,
not the exact line he asked for. To get his exact wording he needs to retype it
once in his profile — or you update that one row. Worth telling him.

## 6. The other branch — read before merging

The `directory-location` branch is **not** a prerequisite. My fix stands alone.

But it touches the same area and should not be merged blind. Its new
`listLocationText()` prints `place['address']` at street level — the same frozen
import column. If it lands as-is, those four members get their old home address
printed into directory rows and map-pin popups too. That is worth a look before
it goes in; I have not changed it.

## 7. What I'm NOT doing

- Not the wider location-dials rebuild (backlog 20 / `directory-location`).
- Not changing the privacy ladder. City and State keep printing structured
  labels only, and never the typed line.
- Not backfilling or repairing the other rows.
- Not touching live data. All live writes are yours.
