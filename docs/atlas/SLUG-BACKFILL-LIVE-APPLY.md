# Profile-URL backfill — LIVE apply runbook

**Status: dry run complete and verified against LIVE. Not applied. Every write here is Ian's.**

Row-level report (carries 4 member email addresses — deliberately NOT in this repo and NOT
behind the dev gate, which is a shared credential rather than per-person auth):

    ~/lane-reports/slug-backfill/LIVE-dryrun-2026-07-28.html      (0600, open in code-server)
    ~/lane-reports/slug-backfill/LIVE-dryrun-2026-07-28.tsv

---

## Where these numbers came from

| | |
|---|---|
| Box | **LIVE — `loothgroup.com`** |
| Proved by | `wp_options.siteurl` = `https://loothgroup.com` (not the `looth_dev` decoy) |
| Postgres | `profile_app` — 1,876 users, 1,634 patreon slugs |
| Export | `LIVE-loothgroup.com-2026-07-28-members.tsv` + `…-owners-WITH-slug_history.tsv` |
| Taken | 2026-07-28, streamed over `ssh live-ro`, **zero bytes written to live** |
| Deriver | `backfill-slugs.php --scope=repair --db-only`, offline mode (cannot write) |

These are **not** dev2 numbers. dev2 was cleaned on 2026-07-17 and holds ~5 patreon slugs
against live's 1,634; a dev2 run of this tool reports zero and proves nothing.

## What the run says

| | count |
|---|---|
| Active members (bridged, not archived) | 1,836 |
| **Would change** | **1,526** |
| Need a human ruling (not written) | 107 |
| ├─ name collides with another member | 99 |
| └─ no honest slug derivable | 8 |
| Bare first names — flagged, not blocked | 136 |
| Numeric suffixes that would be minted | **0** |

Every changing row is category `2-PATREON-JUNK`: the member's URL is a Patreon id
(`/u/patreon_19682448`) and their display name yields a real handle
(`/u/franklin-linker-linker-guitars`). The 107 ruling rows are written to the report and
skipped by the apply — `--db-only` means collisions are never resolved by guessing.

## The retired-handle gap is now CLOSED, and it was empty in effect

`GRANT SELECT ON public.slug_history TO looth_ro` landed. The table holds **2 rows**, both
`user_id 4` (Ian's own rename test, released 2026-07-26): `iandavlin`, `irwin-dublin`.

The owners export gained exactly those two lines, and the resulting plan is **byte-identical**
to the 2026-07-27 run made while blind to them — same md5 `09dbf0ec…`. The gap was real and
is now *proven* harmless rather than assumed harmless. No proposal lands on a retired handle.

## Independent safety check on all 1,526 proposals

Checked against the complete owners file with a second implementation, not the tool's own
collision logic:

| check | result |
|---|---|
| A. proposal already held by another user | **0** |
| B. two members proposed the same handle | **0** |
| C. proposal lands on a retired handle | **0** |
| D. malformed slug shape (empty, `--`, edge dashes, non-`[a-z0-9-]`) | **0** |
| E. proposal ends in digits | 2 — **both legitimate**: "Diamondboat 1" → `diamondboat-1`, "Michael Levesque Studio 46 Guitars Inc." → `michael-levesque-studio-46`. The contract bans *disambiguation* suffixes (`dave2`), not digits a member actually has in their name. |

## Correction: the ghost-squat diagnosis is inverted on live

A cross-lane report described members stranded because an unbridged ghost squats their
human-readable handle, citing `/u/franklin-linker-linker-guitars`. **Measured on live, that is
not what is happening**, and the numbers quoted with it were dev2's.

| claim | live truth |
|---|---|
| ghost holds the member's human-readable handle | ghost holds **`<the member's own slug>-<their wp_user_id>`** — id 1854 holds `patreon_19682448-224`; Franklin is member 201, slug `patreon_19682448`, wp_id 224 |
| Franklin is served from `/u/patreon_19682448-224` | he is served from `/u/patreon_19682448`; the `-224` row is unbridged, so that URL 404s by ghost containment |
| 8 members affected | **7** — there is no user id 1858; the sequence has a gap |
| all bridged 2026-06-11 | **none** of the 7 were; 181 other users were bridged that day |
| 90 ghost rows / 87 holding handles | that is **dev2**. Live: **29 unbridged / 26 holding a handle**, 25 of the 26 test/QA (`qa_*`, `tst-staple-*`, `deltest_*`) |

`/u/franklin-linker-linker-guitars` does 404 — but **nobody holds it** (zero `franklin` handles
in the complete owners export). It 404s because the backfill has never run on live. All 7 are
ordinary `2-PATREON-JUNK`, derive cleanly, collide with nothing.

**So this backfill has no dependency on the parked ghost-cleanup lane.** The squat reading
would have invented one. The 7 ghosts are archived duplicates (archived 2026-07-14) that hit
the slug uniqueness constraint and got wp-id-suffixed; they are counted as handle *owners*
(correctly) and excluded from candidates (correctly).

One of the seven, member 1585, is **not** in the plan: their slug is `tmcdonough8`, which is not
defective under any rule. Re-deriving a handle a member may well have chosen can only make it
worse, so it is left alone. That is the rule working, not a miss.

---

# THE APPLY

## Rollback — read this first, keep it before you run anything

The rollback depends on a snapshot that **must be taken before the apply** (step 1 below).
With it, the restore is exact — it does not infer prior values:

```sql
-- ROLLBACK: restores every slug and slug_changed_at to its pre-apply value.
BEGIN;
UPDATE users u
   SET slug = r.slug, slug_changed_at = r.slug_changed_at
  FROM slug_rollback_20260728 r
 WHERE r.id = u.id AND u.slug IS DISTINCT FROM r.slug;

DELETE FROM slug_history h
 USING slug_rollback_20260728 r
 WHERE h.user_id = r.id
   AND lower(h.slug) = lower(r.slug)
   AND h.released_at >= TIMESTAMPTZ '2026-07-27 00:00:00+00';
COMMIT;
```

Why it is complete: the apply's only writes are (a) `users.slug` + `slug_changed_at`, and
(b) one `slug_history` row per member holding their OLD slug. The `UPDATE` undoes (a) from the
snapshot. The `DELETE` undoes (b), matched on the snapshot's own slug so it can only remove
rows this run created. The `released_at` guard is dated after Ian's two 2026-07-26 rows, so
**those two survive a rollback** — check `SELECT count(*) FROM slug_history;` returns 2 after.

The apply also contains a `DELETE FROM slug_history WHERE user_id=… AND slug=<proposed>`. For
this run that is a **no-op on every row** — check C above found no proposal matching any
existing history entry — so the rollback has nothing to restore there.

Then re-issue the mirror purge from step 5 to flush the reverted handles back out of WP.

## 0a. Two members' profile links are broken on live RIGHT NOW — this is independent

Found while exercising the purge against live's real mirror data. Two members' WP `_looth_slug`
cache holds the handle of their **archived ghost duplicate**, and that handle 404s. The "My
Profile" link in the site header sends them to a dead page today:

| WP user | cached handle | that URL | their real URL |
|---|---|---|---|
| 1431 (member 1265, Bryan Hutchinson) | `patreon_178784349-1431` | **404** | `/u/patreon_178784349` → 200 |
| 1768 (member 1585, Tom McDonough) | `tmcdonough8-1768` | **404** | `/u/tmcdonough8` → 200 |

Measured against live's origin (`--resolve` to the LAN IP). **This needs no backfill, no deploy
and no ruling** — it is two stale cache rows. The mirror self-heals from Postgres once the stale
value is gone:

```sql
DELETE FROM wp_usermeta WHERE meta_key = '_looth_slug' AND user_id IN (1431, 1768);
```

Run against `looth_import` on live. Safe to do before or after everything below; doing it now
fixes two members immediately. (A third row, wp#1, caches Ian's retired `iandavlin` — that one
301s correctly, so it is cosmetic and the full purge in step 5 covers it.)

## 0. Prerequisite — the script is NOT on live yet

`/srv/profile-app/bin/` has no `backfill-slugs.php`. Live is `main @6ef25e3`; this work is on
`slug-backfill @33ac2a5`, unmerged. It must be merged and deployed first.

`/srv/profile-app` is a single symlink to `…/loothplatformv2-clean/profile-app`, so a plain
pull deploys it — **no mu-plugin symlink coupling for this change.**

```bash
lg-deploy                                    # on live
ls -l /srv/profile-app/bin/backfill-slugs.php /srv/profile-app/bin/purge-stale-looth-slug-mirror.php
```

## 1. Snapshot — this is what makes the rollback above work

```bash
sudo -u profile-app psql -d profile_app -c \
  "CREATE TABLE slug_rollback_20260728 AS SELECT id, slug, slug_changed_at FROM users;"
sudo -u profile-app psql -d profile_app -c "SELECT count(*) FROM slug_rollback_20260728;"
```

**Must print 1876. If it errors or prints anything else, STOP — do not run step 3.** Without
this table the rollback above has nothing to restore from, and the apply is irreversible.

(`sudo -u profile-app psql -d profile_app` is this repo's established idiom for reaching that
database, but I could not read live's `pg_hba.conf` as the read-only role, so I have not proven
that exact invocation authenticates *on live*. That is precisely why this step is gated on a
number rather than assumed to have worked.)

## 2. Pre-flight — re-derive from live truth and confirm it still matches this report

My report is a **photograph of live at 2026-07-28**. The apply does not read it; it re-derives
from the database at the moment it runs. If members joined or renamed in between, the plan
moves. This step is how you find that out before writing, not after:

```bash
sudo -u profile-app php /srv/profile-app/bin/backfill-slugs.php \
     --scope=repair --db-only --tsv=/tmp/preflight.tsv
sudo -u profile-app php /srv/profile-app/bin/verify-slug-plan.php --plan=/tmp/preflight.tsv
```

Expect `members=1836  changing=1526  NEED-RULING=107  mode=DRY RUN (no writes)`, then
`VERDICT: no member-harming conflict found` (exit 0). **If the verifier exits 1, stop.**

Matching counts are NOT sufficient on their own — the totals can stay identical while the
individual rows move underneath them, which is why the second command re-checks the actual
proposals against live ownership rather than comparing summary numbers. Run against the live
database it also covers the retired-handle case (C) that an offline export cannot test.

## 3. Apply

```bash
sudo -u profile-app php /srv/profile-app/bin/backfill-slugs.php --scope=repair --db-only --apply
```

One transaction per member, so a failure at row 900 keeps the 899 good ones and re-running
resumes. Expect `applied=1526 failed=0`. Each member's old `patreon_<id>` URL is retired into
`slug_history`, which is what keeps every shared and indexed link alive as a 301.

Want a smaller first bite? `--limit=25` applies the first 25 only; the same rollback covers it.

## 4. Verify

```bash
sudo -u profile-app psql -d profile_app -c \
  "SELECT count(*) FROM users WHERE slug ~* '^patreon[_-]?[0-9]+$';"   -- 1634 -> 108
sudo -u profile-app psql -d profile_app -c "SELECT count(*) FROM slug_history;"  -- 2 -> 1528
```

**Do not smoke-test this with a plain public curl** — Cloudflare bot-challenges it into a 403
that reads as an outage. Pin to the origin, on the box:

```bash
# on live. Pin to the LAN IP, NOT 127.0.0.1 — the loopback pin returns 000 (measured).
curl -sk -o /dev/null -w '%{http_code}\n' --resolve loothgroup.com:443:172.31.67.175 \
     https://loothgroup.com/u/franklin-linker-linker-guitars    # 404 -> 200
curl -sk -o /dev/null -w '%{http_code}\n' --resolve loothgroup.com:443:172.31.67.175 \
     https://loothgroup.com/u/patreon_19682448                  # 200 -> 301
```

That proves the **origin**; it deliberately bypasses the edge, so it is not a cache check.

The residual 108 is exact, not approximate: the 107 members awaiting a ruling, plus 1
patreon-shaped handle held by an archived/unbridged row that is not a candidate. They are
*supposed* to remain. (Member 1585 is not among them — `tmcdonough8` is not patreon-shaped.)

**The 301 machinery is already live** — verified against deployed bytes, not my branch:
`/srv/profile-app/web/u.php:75` calls `Slug::currentSlugForRetired()`, which resolves a retired
handle out of `slug_history` and issues a 301. No deploy is needed for old links to keep working;
the backfill is what populates the table it reads.

## 5. Follow-up, same window: the WP mirror goes stale

WordPress caches the slug in `_looth_slug` usermeta. Nothing invalidates it, and it feeds the
"My Profile" link in the shared header — so after the apply, members click through to their own
retired URL. It still resolves (the 301 covers it), which is exactly why this went unnoticed.

Measured on live today: **426 mirror rows, 3 stale now, 332 stale the moment step 3 lands.**
340 of the 426 currently hand out a `patreon_` URL.

```bash
sudo -u profile-app php /srv/profile-app/bin/purge-stale-looth-slug-mirror.php        # report
sudo -u profile-app php /srv/profile-app/bin/purge-stale-looth-slug-mirror.php --sql \
     > /tmp/slug-mirror.sql
sudo wp --allow-root --path=/var/www/dev db query < /tmp/slug-mirror.sql
```

**Expect `mirrors=426  in-sync=94  STALE=332  no-pg-row=0`**, and a single `DELETE … user_id IN
(…)` listing exactly 332 ids. If STALE is wildly off that number, stop — the apply and the purge
disagree about what changed.

`--path=/var/www/dev` looks wrong on a production box and is **correct**: live's vhost is
`server_name loothgroup.com www.loothgroup.com; root /var/www/dev;`. The path name is inherited
from the shared image. Do not "fix" it to `/var/www/html` — that is the stock nginx placeholder.
Live's `LG_MYSQL_DB` is `looth_import`, so the script reads the right database and not the
`looth_dev` decoy; both were checked on the box.

It only *emits* SQL — profile-app has SELECT-only on the WP MySQL database, so it reads both
stores and writes neither. Deleting rather than rewriting is deliberate: a missing mirror is
re-resolved and re-stamped on the member's next pageview, so it self-heals with no second source
of truth.

**The `--sql` branch is now exercised.** dev2 has 0 stale mirrors, so a run there proves only the
clean path — a SQL generator whose output nobody has ever seen is how a bad `DELETE` reaches
production. It now takes `--truth-tsv=` / `--mirror-tsv=` (same idea as `backfill-slugs.php
--from-tsv`), so live's real mirror can be replayed offline against no database at all:

```bash
php purge-stale-looth-slug-mirror.php --truth-tsv=<members.tsv> --mirror-tsv=<mirror.tsv> [--sql]
```

Replaying live's 426 mirror rows gives `STALE=3` against today's slugs and `STALE=332` against
post-apply slugs — matching an independent calculation exactly, so two implementations agree on
the 332 above. The emitted SQL is one `DELETE` with 332 ids, all integers, no other tokens.

## What this does NOT do

- Does not touch the 107 members needing a ruling.
- Does not touch any member whose current handle is already clean.
- Does not clean up ghosts. It does not need to — see the correction above.
- Does not resolve the 41 same-name pairs. 92 of the 99 collisions are pairs sharing an
  identical display name with different emails (personal vs business). Whether a pair is one
  human or two is not determinable from anything this lane holds. Resolve that upstream as a
  merge question and the ruling queue drops from 107 to roughly 15.
