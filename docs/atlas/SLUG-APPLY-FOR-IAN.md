# Profile URL backfill — the apply, for Ian

**1,487 members get a URL made of their name. 40 are deliberately left alone.**
Live is `main @0995f2b`. Every command below runs on **live, from a webmin terminal, as
`ubuntu`**; the role each one needs is stated on the line.

---

## ROLLBACK — read first, keep this open

Restores every slug and `slug_changed_at` exactly, from the snapshot taken in step 2.
It does not infer prior values.

```sql
-- run as: sudo -u profile-app psql -d profile_app
BEGIN;
UPDATE users u
   SET slug = r.slug, slug_changed_at = r.slug_changed_at
  FROM slug_rollback_20260729 r
 WHERE r.id = u.id AND u.slug IS DISTINCT FROM r.slug;

DELETE FROM slug_history h
 USING slug_rollback_20260729 r
 WHERE h.user_id = r.id
   AND lower(h.slug) = lower(r.slug)
   AND h.released_at >= TIMESTAMPTZ '2026-07-29 00:00:00+00';
COMMIT;

SELECT count(*) FROM slug_history;   -- must be 2 afterwards: Ian's own two from 2026-07-26
```

The apply only ever writes two things — `users.slug`/`slug_changed_at`, and one
`slug_history` row per member holding their OLD handle. The `UPDATE` undoes the first from
the snapshot; the `DELETE` undoes the second, matched on the snapshot's own slug so it can
only remove rows this run created. After a rollback, re-run step 6 to flush the reverted
handles out of the WordPress cache.

---

## 1. Prerequisite — the code must be DEPLOYED, not copied to /tmp

**This is the one thing that cannot be worked around, and it is worth stating plainly
because the obvious shortcut fails.** Copying the scripts to `/tmp` with `git show` does not
work: `backfill-slugs.php` calls `Slug::derive()`, `Slug::deriveUsable()` and `Slug::fit()`,
and **none of those three exist in the `Slug.php` deployed on live** — they are added by this
branch (`6d638bb`, `d61f6c7`). `config.php` `require_once`s the *deployed* `src/Slug.php`, so
a `/tmp` copy would fatal on an undefined method. Checked on the box, not assumed.

So the apply needs the branch merged and pulled. `/srv/profile-app` is a single symlink into
the checkout, so one pull deploys all of it — no per-file symlink coupling for this change.

```bash
# after slug-backfill is merged to main:
lg-deploy
ls -l /srv/profile-app/bin/backfill-slugs.php \
      /srv/profile-app/bin/verify-slug-plan.php \
      /srv/profile-app/bin/lib/patreon-identity.php
grep -c 'function deriveUsable' /srv/profile-app/src/Slug.php   # must be 1, not 0
```

**Steps 2 and 7 are pure SQL and need none of this** — they can be run today.

## 2. Snapshot — the rollback depends on it

```bash
# as: profile-app
sudo -u profile-app psql -d profile_app -c \
  "CREATE TABLE slug_rollback_20260729 AS SELECT id, slug, slug_changed_at FROM users;"
sudo -u profile-app psql -d profile_app -c "SELECT count(*) FROM slug_rollback_20260729;"
```

**Must print 1876.** If it errors or prints anything else, STOP — without this table the
apply is not reversible. (`sudo -u profile-app psql` is this repo's established idiom, but
live's `pg_hba.conf` is not readable to my role, so I have not proven that exact invocation
authenticates *on live*. That is why this step is gated on a number.)

## 3. Dry run — and check it still matches

```bash
# as: profile-app
sudo -u profile-app php /srv/profile-app/bin/backfill-slugs.php \
     --scope=repair --identity-from-wp --expand-bare-names --hold-contested-bare \
     --tsv=/tmp/preflight.tsv --html=/tmp/preflight.html
sudo -u profile-app php /srv/profile-app/bin/verify-slug-plan.php --plan=/tmp/preflight.tsv
```

Expect:

```
patreon api: wp_usermeta patreon_latest_patron_info — ~1585 identities (no API call)
--expand-bare-names: expanded 14 bare first name(s) from stored identity
--hold-contested-bare: withholding 40 contested bare handle(s); acting on 1487
members=1836  changing=1527  NEED-RULING=106  acting-on=1487  mode=DRY RUN (no writes)
VERDICT: no member-harming conflict found
```

**If the verifier exits 1, stop.** Matching counts alone are not enough — the totals can hold
while individual rows move underneath them, which is why the second command re-checks every
proposal against live ownership. `--identity-from-wp` only actually loads when there are
collisions to resolve, so the `patreon api:` line showing ~1,585 identities **is** the proof
it ran; dev2 has no collisions, so that path could not be exercised there.

## 4. Apply

```bash
# as: profile-app
sudo -u profile-app php /srv/profile-app/bin/backfill-slugs.php \
     --scope=repair --identity-from-wp --expand-bare-names --hold-contested-bare --apply
```

Expect `applied=1487 failed=0`. One transaction per member, so a failure at row 900 keeps the
899 before it and re-running resumes. Each old `patreon_<id>` URL is parked in `slug_history`,
which is what keeps every shared and indexed link alive as a 301.

Smaller first bite: add `--limit=25`. The same rollback covers it.

## 5. Verify

```bash
# as: profile-app
sudo -u profile-app psql -d profile_app -c \
  "SELECT count(*) FROM users WHERE slug ~* '^patreon[_-]?[0-9]+$';"   -- 1634 -> 147 exactly
sudo -u profile-app psql -d profile_app -c "SELECT count(*) FROM slug_history;"  -- 2 -> 1489
```

```bash
# as: ubuntu. Pin the LAN IP — 127.0.0.1 returns 000, and a plain public curl gets
# Cloudflare-challenged into a 403 that reads as an outage.
curl -sk -o /dev/null -w '%{http_code}\n' --resolve loothgroup.com:443:172.31.67.175 \
     https://loothgroup.com/u/franklin-linker-linker-guitars   # 404 -> 200
curl -sk -o /dev/null -w '%{http_code}\n' --resolve loothgroup.com:443:172.31.67.175 \
     https://loothgroup.com/u/patreon_19682448                 # 200 -> 301
curl -sk -o /dev/null -w '%{http_code}\n' --resolve loothgroup.com:443:172.31.67.175 \
     https://loothgroup.com/u/matt                             # 404 both before and after
```

That last one is the ruling working: `/u/matt` must stay a 404.

## 6. Same window — flush the WordPress cache

WordPress caches the slug in `_looth_slug` usermeta and nothing invalidates it. It feeds the
"My Profile" link in the site header, so until this runs, changed members click through to
their own retired URL. It still resolves via the 301, which is exactly why it went unnoticed.

```bash
# as: profile-app to generate, ROOT to run
sudo -u profile-app php /srv/profile-app/bin/purge-stale-looth-slug-mirror.php
sudo -u profile-app php /srv/profile-app/bin/purge-stale-looth-slug-mirror.php --sql \
     > /tmp/slug-mirror.sql
sudo wp --allow-root --path=/var/www/dev db query < /tmp/slug-mirror.sql
```

Expect `mirrors=426  in-sync=98  STALE=328` (measured by replaying live's real mirror rows against this exact 1,487-member plan). `--path=/var/www/dev`
**is correct on live** — the vhost really is `server_name loothgroup.com …; root /var/www/dev;`
and `LG_MYSQL_DB=looth_import`. Do not "fix" it to `/var/www/html`, which is the stock nginx
placeholder. Deleting rather than rewriting is deliberate: a missing mirror is re-resolved from
Postgres on the member's next pageview, so it self-heals.

## 7. Independent of all the above — two members are broken right now

Their `_looth_slug` cache holds the handle of their *archived ghost duplicate*, which 404s, so
their "My Profile" link is a dead page today. **No deploy, no backfill, no ruling needed:**

```sql
-- as ROOT: sudo wp --allow-root --path=/var/www/dev db query
DELETE FROM wp_usermeta WHERE meta_key = '_looth_slug' AND user_id IN (1431, 1768);
```

wp#1431 is member 1265 (Bryan Hutchinson), wp#1768 is member 1585 (Tom McDonough). Safe to run
before everything else.

---

## The 40 being left alone, and why

Nobody gets a contested bare first name. `/u/matt`, `/u/jeff`, `/u/steve` and the rest stay
free for a future flow where a member asks for one. These 40 keep their `patreon_<id>` URL.

| member | display_name | handle withheld | member | display_name | handle withheld |
|---|---|---|---|---|---|
| 1416 | Matt | `/u/matt` | 1295 | James | `/u/james` |
| 1387 | Scott | `/u/scott` | 1480 | Dan | `/u/dan` |
| 1357 | Sam | `/u/sam` | 1459 | Aaron | `/u/aaron` |
| 1483 | joseph | `/u/joseph` | 1485 | Tony | `/u/tony` |
| 1541 | Adam | `/u/adam` | 1444 | Martin | `/u/martin` |
| 1441 | Dennis | `/u/dennis` | 1431 | Geoff | `/u/geoff` |
| 1350 | Tommy | `/u/tommy` | 1381 | Blake | `/u/blake` |
| 1317 | ARTHUR | `/u/arthur` | 1308 | Ian | `/u/ian` |
| 1285 | Gerhard | `/u/gerhard` | 1783 | Colin | `/u/colin` |
| 1784 | George | `/u/george` | 1804 | Brock | `/u/brock` |

…and 20 more, all the same shape. The full list with the count of other members sharing each
first name is in the report (`/tmp/preflight.html`, section **"Held by ruling"**) and in
`--tsv` as category `7-HELD-CONTESTED-BARE`.

**Why they are held:** Patreon holds a `last_name` for only 10% of bare-name members against
91% of everyone else, so the surname is not recoverable and "Matt" is not a truncation we can
undo — it is the only name that exists for him anywhere. With no basis to choose between 19
Matts, nobody gets it rather than the import accident deciding.

**Six were resolved and are NOT in this list**, because a real surname is not an accident:

| member | was | now | frees |
|---|---|---|---|
| 1550 Jeff | `/u/jeff` | `/u/jeff-ferk` | 15 other Jeffs |
| 1554 Steve | `/u/steve` | `/u/steve-mcdonald` | 14 other Steves |
| 1229 Tim | `/u/tim` | `/u/tim-staver` | 8 other Tims |
| 1544 Neil | `/u/neil` | `/u/neil-walwer` | 5 other Neils |
| 1539 Pete | `/u/pete` | `/u/pete-marten` | 4 other Petes |
| 65 Max | `/u/max` | `/u/max-bierman` | 2 other Maxes |

Eight more bare names expanded the same way without being contested, several repairing a
damaged stored name: `powersdj1 .` → `/u/dave-powers`, `CRC` → `/u/clancey-compton`, `alsato`
→ `/u/al-sato`. Their `display_name` is never rewritten — only the URL improves.

One member keeps their own choice: 732 `Seb` → **`/u/meeloo`**, their self-chosen Patreon
vanity, which the run honours over a generic first name.

## Still not decided, and not blocking

- **106 members await a ruling** — 99 collisions and 7 others. 41 of those groups are members
  sharing a byte-identical display name across a personal and a business email; that is a
  duplicate-account question, not a naming one. `docs/atlas/SLUG-RULING-QUEUE.md`.
- **Email-derived slugs stay OFF.** `--allow-email-derived-slugs` is not in any command above
  and must not be added. It would give 40 members a public URL built from their email address
  — `/u/hxn7djggwx`, `/u/jf13fox`. Ruling pending on delete-vs-flag;
  `docs/atlas/SLUG-PATREON-IDENTITY-DELTA.md`.
