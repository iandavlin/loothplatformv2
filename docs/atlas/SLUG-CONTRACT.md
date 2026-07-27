# SLUG-CONTRACT.md — how a member's profile URL is decided

**Status: authoritative, 2026-07-27.** Written because the "members pick a handle" question
has already been re-litigated once and the answer keeps getting rebuilt from scratch.
If this disagrees with an older doc, THIS wins.

---

## 1. The rulings (Ian — binding, do not re-open)

| # | Ruling | Date |
|---|---|---|
| 1 | **The URL follows the profile NAME.** Members never choose a separate handle. Renaming re-derives the slug. This killed the editable-slug backlog item. | 2026-07-19 |
| 2 | **Keep the business name.** Derive from `display_name` as it stands — no tail pruning. "Franklin Linker Linker Guitars LLC" → `franklin-linker-linker-guitars`. Business-name pruning is a separate, PARKED lane. | 2026-07-27 |
| 3 | **Every existing URL keeps working.** A shared/indexed `/u/patreon_178784349` must 301, never 404. | standing |
| 4 | **Live writes are Ian's.** Tools produce a dry-run and a command; they never write live themselves. | standing |

---

## 2. Where the slug actually lives

**`/u/<slug>` resolves from ONE store: profile-app Postgres `users.slug`.** That is the
single authority. Everything else is a copy, and every copy has been stale at least once.

| store | role | authoritative? |
|---|---|---|
| PG `profile_app.users.slug` | what `/u/` resolves against (`profile-app/web/u.php`) | **YES** |
| PG `profile_app.slug_history` | every handle a member has ever held → powers the 301 | yes, for old URLs |
| WP `_looth_slug` usermeta | **cache** feeding the JWT `slug` claim → shared-header "My Profile" link | no — pure cache |
| WP `user_nicename` | legacy `/members/<nicename>/` + weekly-digest fallback | no |

> **The counting trap.** `wp_users.user_nicename` still reads `patreon_*` for ~1,634
> accounts, which makes it look like 90% of members have a Patreon URL. They do not —
> `user_nicename` does not resolve `/u/`. Measure `users.slug` in Postgres or you will be
> wrong by two orders of magnitude in *either* direction. (`profile_app.users` is also not
> the member base on its own: it carries unbridged and archived rows. Join
> `wp_user_bridge` and filter `archived_at IS NULL`.)

---

## 3. How a slug is derived

One implementation, `Slug::derive()` + `Slug::fit()` in `profile-app/src/Slug.php`. Every
mint site calls it — `Provision::ensureSlug` (new member), `Provision::maybeSyncSlugFromName`
(rename), `bin/backfill-slugs.php` (repair). **Do not write a second copy**: a backfill that
derives differently from the rename path is a split-brain that reappears the moment someone
renames.

1. **Decode entities.** Raw rows carry storage damage (`Wood &amp; Voltage`). Undecoded,
   `&amp;` slugifies to the *word* "amp". Decoded for derivation **only** — `display_name`
   is never rewritten (that is the parked name-cleanup lane).
2. **Transliterate, don't strip.** `Any-Latin; Latin-ASCII; Lower()`. Deleting non-ASCII
   does not fail loudly — it silently misspells the member's own name in their URL. This is
   not hypothetical: `Åke Nathorst` → `ke-nathorst`, `Peter Ellström` → `peter-ellstr-m`,
   `João` → `jo-o`. It also emptied CJK names entirely, which is how four members kept a
   `patreon_*` URL through a backfill that was supposed to remove them.
3. **Apostrophes vanish, not split.** `Nikki’s Guitar Shop` → `nikkis-guitar-shop`.
4. **Everything else → `-`**, collapse, trim.
5. **Fit to 30 chars** at a word boundary. This is where Ruling 2 meets reality: the
   business tail is derived in full and then truncated — "…Guitars LLC" loses the LLC to the
   *cap*, not to a prune rule.
6. **Reject** anything `checkShape()` refuses (under 3 chars, all-digits, reserved word).

**Fallback chain:** `display_name` → email local-part → `member`. Email is a rescue for a
name that yields nothing, not a preference.

**Collisions:** numeric suffix — `steve`, `steve2`, `steve3`. The suffix rides *inside* the
30-char cap. A handle is taken if it is live on another member **or parked in another
member's `slug_history`** — retired handles are never re-issued, because whoever took your
old handle would inherit every link that ever pointed at you.

---

## 4. History and the 301

`slug_history` holds every handle a member has ever held, unique on `lower(slug)`.

`u.php` resolves in this order: exact match → case-insensitive match (301 to canonical
casing) → numeric `/u/<id>` → **`slug_history` → 301 to the current slug** → 404.

So a rename is safe by construction, and so is a backfill: the outgoing handle is parked in
the same transaction that takes the new one. Mentions are additionally safe because they
store the member's **uuid**, not the handle, and resolve at render.

**Verified end to end on dev2, 2026-07-27:** all 33 slugs changed by this lane 301→200 on
their old URL (33/33), plus 59/60 sampled from the earlier 1,629-row backfill.

### The one case where an old URL still fails

A retired URL 301s correctly and then **404s at the destination** if the member is
*unbridged* (no `wp_user_bridge` row). Ghost containment says an identity that cannot log in
is not a member, so its `/u/` 404s — old URL and new URL alike. On dev2 this is **7 members**,
and it includes `patreon_178784349` (Bryan Hutchinson), the very URL cited as the
must-never-break example.

This is **not** caused by slug work and cannot be fixed by it — the profile is unreachable at
*every* address. It belongs to the PARKED identity-reconcile lane. Anyone auditing Ruling 3
should expect these 7 and not chase them.

---

## 5. What happens on rename

`Provision::maybeSyncSlugFromName()` — **unconditional** (Ruling 1). Every display-name
change re-derives the slug, dedupes it, parks the old one in `slug_history`, and stamps
`slug_changed_at`. Best-effort and fully guarded: a slug is non-critical, so a failure logs
and the name change still stands.

**Known gap (open):** nothing invalidates the WP `_looth_slug` cache when the slug changes,
and the JWT carrying the `slug` claim lives 30 days. So after a rename or a backfill, the
member's own "My Profile" link points at their *old* URL until the token is re-minted. It
still resolves (301), so it looks fine and stays broken quietly — on dev2, **216 of 267
mirrors (81%) were stale**, all still serving retired `patreon_*` URLs.

Neither process can fix this alone: profile-app reads PG but is SELECT-only on WP MySQL;
WordPress can write usermeta but has no PG access. Hence
`bin/purge-stale-looth-slug-mirror.php` — it reads both and emits the WP-side SQL. Deleting
the stale row self-heals, because `profile-auth.php` re-resolves a *missing* mirror from the
internal slug endpoint and re-stamps it.

*Proper fix, not yet built:* invalidate the mirror at the moment the slug changes. Until then,
**run the purge after any bulk slug change.**

---

## 6. Running a backfill

```bash
# 1. look (writes nothing, ever)
sudo -u profile-app php profile-app/bin/backfill-slugs.php --scope=repair \
     --html=/var/www/dev/slug-dry-run.html

# 2. apply
sudo -u profile-app php profile-app/bin/backfill-slugs.php --scope=repair --apply

# 3. ALWAYS — the WP cache is now stale for everyone changed
sudo -u profile-app php profile-app/bin/purge-stale-looth-slug-mirror.php --sql > /tmp/m.sql
sudo wp --allow-root --path=/var/www/dev db query < /tmp/m.sql
```

`--scope=junk` (default) = missing or `patreon_*` slugs. `--scope=repair` = that plus names
misspelled by the old stripper. Idempotent: re-running is a no-op and cannot double-suffix,
which is also the resume path if it dies halfway.

**There is deliberately no "re-derive everyone" scope.** It was built, run, and rejected on
the evidence: because the canonical form of some names is already held by someone else, a
blanket re-derivation proposed moving `iandavlin` → `ian-davlin5` and `charlesfox` →
`charles-fox2`. A member who already owns a clean, unique handle can only be made worse by
re-deriving it. **Only defective slugs are candidates** — "my derivation disagrees with the
stored slug" is not a defect.

---

## 7. Deliberately out of scope

- **`user_nicename`** stays `patreon_*`. `/members/<nicename>/` 301s to `/u/<nicename>`,
  which `slug_history` 301s again to the current URL — verified. Those links work untouched,
  and rewriting nicename would put WP author archives and BuddyBoss URLs at risk for nothing.
- **Repairing `display_name`** (the `&amp;` rows). Decoded for derivation; never written back.
- **Unbridged and archived rows.** Not members.
- **The 12 members wearing an email-derived slug** (`ianhatesguitars`, `stuguitarsetups`).
  Ruling 1 arguably applies, but name-derivation makes several *worse*. Listed in the dry-run
  under "Considered and NOT proposed" for Ian to rule on.
