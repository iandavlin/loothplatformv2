# PATREON-HANDLE-BACKFILL — dry-run generator + dev2 report

*mentions lane, 2026-07-25. Ian ruling (board 22:33): junk `patreon_*` handles are
re-minted from identity pulled FRESH via the creator token — full name → vanity →
email local-part (+tag stripped) — cleaned + @steve2-suffixed. DRY-RUN ONLY; apply is
a separate Ian-gated step.*

## The generator
`profile-app/bin/backfill-patreon-handles-dryrun.php` — run as `profile-app`. Writes
NOTHING. Pulls the full campaign members sweep (paginated, `fields[user]=email,
full_name,vanity` — vanity is this script's addition over the poller's sweep),
derives per the ruling chain with an offline email fallback for members no longer in
the campaign, and suffixes against live slugs ∪ slug_history ∪ batch-proposed (the
batch-internal term matters at live scale). `--db-only` for boxes without creds;
`--tsv=` for a file copy.

## dev2 run (2026-07-25, real API: 3,765 users / 19 pages)

| user | wp | patreon_id | name | junk handle | in API | source | proposed |
|---|---|---|---|---|---|---|---|
| 271 | 302 | 98047089 | 순간의미학 | patreon_98047089 | NO (left) | offline-email | jhw864 |
| 838 | 962 | 98763989 | 祁磊 | patreon_98763989 | yes | api-email | categuitarfactory |
| 1373 | 1550 | 187108814 | 博祥 游 | patreon_187108814 | yes | api-email | b9403045 |
| 1411 | 1588 | 190548826 | ビック | patreon_190548826 | yes | api-email | vpjp15 |
| 1946 | 1000 | 54921530 | Doug Lawrence Doug Lawrence Guitars | patreon54921530 | yes | api-full-name | doug-lawrence |

Sources: 1× api-full-name, 3× api-email, 1× offline-email. **0 suffixes needed;
0 rows found a vanity** (none of these five set one — the chain held its order).
CJK display names stay exactly as written (we never latinize the shown name — only
the handle derives from ASCII sources).

## Live plan (Ian gates)
Live still carries the ~1,600 junk handle population (dev2 was test-re-minted last
week; deploy stayed handle-inert — keeper audit 7/25). Same generator runs there at
apply-eve → full TSV to Ian → on his approval, the APPLY step (not yet written) runs
the same derivation transactionally with slug_history parking per handle, journal +
revert.sql per the identity-reconcile discipline.
