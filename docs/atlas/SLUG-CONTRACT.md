# SLUG-CONTRACT.md — how a member's profile URL is decided

**Status: authoritative, 2026-07-27.** Supersedes the derivation chain in
`PATREON-HANDLE-BACKFILL-DRYRUN.md` (2026-07-25). Written because this question has
already been re-litigated three times in one day; if this disagrees with an older doc,
THIS wins.

---

## 1. The rulings (Ian — binding)

| # | Ruling | Date |
|---|---|---|
| R1 | **The slug is the profile DISPLAY NAME, cleaned.** Not Patreon `full_name`, not `vanity`, not the email local-part. "Cleaned" = decode entities, fold Latin diacritics, strip punctuation, collapse whitespace, lowercase, dash-join, 30-char cap at a word boundary. | 2026-07-27 |
| R2 | **Keep the business name.** "Doug Lawrence Doug Lawrence Guitars" derives from the whole string. The business-tail prune is a separate, PARKED thing. | 2026-07-27 |
| R3 | **No numeric suffixes as the answer to a collision.** Two Daves do not become `dave2`/`dave3`. A contested handle is EXPANDED from a fuller identity into `dave-thurston`. A numeric suffix is a last resort that must be reported by name; **the target is zero.** | 2026-07-27 |
| R4 | **Never overwrite a name the member submitted.** HARD — outranks R3 where they conflict. API expansion is licensed only where we are inventing an identity for someone who never supplied one. It is not licence to "improve" a chosen name. | 2026-07-27 |
| R5 | **The URL follows the name**, members never pick a handle; every existing URL keeps working via `slug_history` → 301. | 07-19 / standing |
| R6 | **Live writes are Ian's.** Dry-run first, always. | standing |

---

## 2. Where the slug actually lives

**`/u/<slug>` resolves from ONE store: `profile_app.users.slug` (Postgres).**

| store | role | authoritative? |
|---|---|---|
| PG `users.slug` | what `/u/` resolves against (`profile-app/web/u.php`) | **YES** |
| PG `slug_history` | every handle ever held → powers the 301 | yes, for old URLs |
| WP `_looth_slug` usermeta | **cache** feeding the JWT slug claim → header "My Profile" link | no |
| WP `user_nicename` | legacy `/members/` + digest fallback | no |

> **The counting trap.** `user_nicename` reads `patreon_*` for ~1,633 live accounts and
> does NOT resolve `/u/`. Measure `users.slug` in Postgres. Also: **dev2 is not a
> rehearsal environment for this** — a cleanup already ran there (dev2 PG has ~11 patreon
> slugs; LIVE has ~1,640), so a dev2 dry-run looks empty and proves nothing about
> population. Reason from live reads.

---

## 3. How a slug is derived

One implementation: `Slug::derive()` + `Slug::fit()`. Every mint site calls it —
`Provision::ensureSlug`, `Provision::maybeSyncSlugFromName`, `bin/backfill-slugs.php`.
Do not write a second copy: a backfill that derives differently from the rename path is a
split-brain that reappears the moment someone renames.

1. **Decode entities.** Raw rows carry `Wood &amp; Voltage`; undecoded, `&amp;`
   slugifies to the word "amp". Decoded for derivation **only** — `display_name` is
   never rewritten (that is the parked name-cleanup lane, and R4).
2. **Fold Latin diacritics — `Latin-ASCII` only, never `Any-Latin`.** Deleting non-ASCII
   silently misspells a member's own name (`Åke Nathorst` → `ke-nathorst`,
   `Peter Ellström` → `peter-ellstr-m`). Folding fixes that. **Romanizing is a different
   act**: `Any-Latin` would turn `祁磊` into `qi-lei`, and we never latinize a member's
   name. A non-Latin name therefore derives to `''` and is **surfaced for a decision,
   never guessed at**.
3. **Apostrophes vanish, not split** — `Nikki’s` → `nikkis`.
4. Everything else → `-`, collapse, trim, **fit to 30 chars** at a word boundary. This is
   where R2 lands: the business tail is derived in full, then truncated by the *cap*.
5. **Reject** what `checkShape()` refuses (under 3 chars, all-digits, reserved).

---

## 4. Collisions (R3) — and why provenance gates them

A handle is taken if it is live on another member **or parked in another member's
`slug_history`** (retired handles are never re-issued, or whoever takes your old handle
inherits every link that pointed at you).

On a collision the resolver asks Patreon for a fuller identity — `full_name` → `vanity` →
`email` → account email — and derives something distinguishing. **`dave-thurston`, never
`dave2`.** The API is the collision resolver only; it is *not* the name source (R1).
That keeps it to ~47 lookups rather than 1,633.

**But R4 gates it.** Expansion is only licensed where nobody supplied the name.

### How we know a name is the member's own — measured, not assumed

**There is no stored provenance.** Verified on the schema:

- `users` has **no** source/origin/provenance column; there is **no audit table**.
- `updated_at` **is** maintained by a `users_touch` trigger — and is **useless** as an
  intent signal: **1,920 of 1,925** dev2 rows differ from `created_at`, because avatar,
  location and geocode backfills touched nearly every row.
- `profiles.claimed_via` is polluted the same way: **659 of 684** dev2 rows are
  `backfill_location`. Only `onboard|direct|menu` (**24 rows**) reflect a real person.
- Name **shape** cannot discriminate: **zero** members have a `patreon_*` or empty
  `display_name`. Everyone already looks like a human.

So the verdicts are:

| verdict | signal | may we expand? |
|---|---|---|
| `machine-seeded` | display_name is *character-for-character* Patreon's `full_name`, or is itself a `patreon_*` placeholder | **yes** |
| `user-submitted` | claimed via `onboard`/`direct`/`menu` | no |
| `indeterminate` | everything else | **no** — conservative default |

**The inference we deliberately refuse to make:** that `Dave` differing from Patreon's
`Dave Thurston` proves the member shortened it. The import may simply have taken a first
name. Asserting it would block the exact case R3 wants expanded, so `indeterminate` is
reported honestly instead of guessed.

Consequence: with no reliable signal, **most collisions land in front of Ian.** The
dry-run still computes and shows the expansion it *would* apply, so approving is one
look — "needs a ruling" with no proposal attached is not something anyone can approve.

### Ghosts squat on the best handles

31 clean handles on dev2 (`steve`, `jim`, `ted`, `ian-davlin`) are held by **unbridged**
identities — profiles that cannot log in and whose `/u/` 404s by ghost containment. They
still block the handle, which is why a real Dave Thurston can end up offered `dthurston`.
Releasing them belongs to the PARKED identity-reconcile lane. **Re-measure on live.**

---

## 5. History and the 301

`u.php` resolves: exact → case-insensitive (301 to canonical) → numeric `/u/<id>` →
**`slug_history` → 301 to current** → 404. The outgoing handle is parked in the same
transaction that takes the new one, so a rename and a backfill are both safe by
construction. Mentions store the **uuid**, not the handle.

**Verified end to end on dev2:** 33/33 changed slugs 301→200 on their old URL, plus 59/60
sampled from the earlier backfill.

### The one case where an old URL still fails

A retired URL 301s correctly and then **404s at the destination** when the member is
unbridged — ghost containment. On dev2 that is **7 members**, including
`patreon_178784349`, the URL cited as must-never-break. This is **not** caused by slug
work and cannot be fixed by it; the profile is unreachable at every address. Parked
identity-reconcile lane. Expect these and do not chase them.

---

## 6. What happens on rename

`Provision::maybeSyncSlugFromName()` — unconditional (R5). Re-derives, dedupes, parks the
old handle, stamps `slug_changed_at`. Best-effort: a failure logs and the name change
still stands.

**Known gap (open):** nothing invalidates the WP `_looth_slug` cache when the slug
changes, and the JWT carrying the claim lives 30 days. After a rename or backfill the
member's own "My Profile" link points at their old URL. It still 301s, so it looks fine
and stays broken quietly — on dev2 **216 of 267 mirrors (81%)** were stale, all serving
retired `patreon_*` URLs. Neither process can fix it alone (profile-app is SELECT-only on
WP MySQL; WordPress has no PG), hence `bin/purge-stale-looth-slug-mirror.php`, which reads
both and emits the WP-side SQL. Deleting self-heals via profile-auth's re-resolve.
**Run it after any bulk slug change.**

---

## 7. Running it

```bash
# 1. look — writes nothing, ever
sudo -u profile-app php profile-app/bin/backfill-slugs.php --scope=repair \
     --html=/var/www/dev/slug-dry-run.html --tsv=/tmp/slugs.tsv

# 2. apply (Ian only, on his word)
sudo -u profile-app php profile-app/bin/backfill-slugs.php --scope=repair --apply

# 3. ALWAYS — the WP cache is now stale for everyone changed
sudo -u profile-app php profile-app/bin/purge-stale-looth-slug-mirror.php --sql > /tmp/m.sql
sudo wp --allow-root --path=/var/www/dev db query < /tmp/m.sql
```

`--db-only` skips the Patreon sweep (no creator token → collisions cannot be expanded and
are reported as needing a ruling, never silently suffixed). `--api-fixture=<json>` feeds a
recorded identity map so the collision resolver can be rehearsed off-live.
`--applied-since=<min>` rebuilds the report from what a run actually wrote.

Idempotent: re-running is a no-op and cannot double-suffix — which is also the resume path.

### Reporting on a box you cannot run code on

The box that must be reported on (LIVE) and the box that can run the deriver are often not
the same box. Do **not** reimplement the cleaning rules in SQL to run over there — that is
the split-brain this contract exists to prevent. Export and run the one deriver:

```bash
# on LIVE, as looth-ro — SELECTs only, cannot write
bash profile-app/bin/export-for-slug-dryrun.sh /tmp/live
# copy the two TSVs to the box with the code, then
sudo -u profile-app php profile-app/bin/backfill-slugs.php --scope=repair \
     --from-tsv=/tmp/live-members.tsv --owners-tsv=/tmp/live-owners.tsv \
     --html=/tmp/live-dryrun.html
```

Offline mode is verified byte-identical to a direct connection on the same data, forces
dry-run (there is no connection to write through), and stamps the report with the export
it came from — a report that cannot say where its rows came from will be mistaken for
production by whoever opens it next.

The export script prints `siteurl` first, because on the live box **`looth_dev` is a
decoy** — it is dev.loothgroup.com's own frozen database. The live WP DB is `looth_import`.

**There is deliberately no "re-derive everyone" scope.** It was built, run and rejected on
the evidence: because the canonical form of some names is already held, it proposed
`iandavlin` → `ian-davlin5` and `charlesfox` → `charles-fox2`. A member who already owns a
clean unique handle can only be made worse. **Only defective slugs are candidates** —
"my derivation disagrees with the stored slug" is not a defect.

---

## 8. Deliberately out of scope

- **`user_nicename`** stays `patreon_*`. `/members/<nicename>/` 301s to `/u/<nicename>`,
  which `slug_history` 301s again — verified. Those links work untouched.
- **Repairing `display_name`.** Decoded for derivation; never written back (R4).
- **Unbridged and archived rows.** Not members.
