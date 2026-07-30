# Profile URL backfill — the apply, for Ian

**1,494 members get a URL made of their name. 111 are deliberately left alone.**

> **READY.** Every question you were asked is ruled and folded in
> (`docs/atlas/SLUG-RULING-QUEUE.md`). The duplicate merges are applied and verified on live.
> Nothing here is waiting on a decision — only on the merge, the deploy, and you running it.

Re-measured against **post-merge LIVE, 2026-07-29**, after the 30-pair duplicate merge.
Live's checkout is `main @1a0c804`. This branch is `slug-backfill @234b623`, 25 commits ahead of
`origin/main`. Every command below runs on **live, from a webmin terminal, as `ubuntu`**; the
role each one needs is stated on the line.

**Review page (dev-gated, needs your dev cookie):**
`https://dev2.loothgroup.com/v2/tests/output/slug-backfill-review-20260729.html`

## The numbers, before → after

| | before | after |
|---|---|---|
| members on a `patreon_<id>` URL | 1,634 | **140** |
| rows in `slug_history` | 2 | **1,496** |
| stale WordPress slug caches | 3 | **0** (338 purged in step 6) |
| snapshot rows (rollback gate) | — | **1,876** |

The 140 left over: 56 unresolved collisions · 41 contested bare names · 4 non-Latin · 4 too-short
· 4 whose name is an email · 2 held duplicate-name · 28 archived · 1 unbridged ghost. Itemised in
the ruling queue. **8 of those are a permanent floor** — the non-Latin and too-short members,
whose name cannot become a handle without inventing letters for them or letting them choose.

---

## ROLLBACK — read first, keep this open

Restores every slug and `slug_changed_at` exactly, from the snapshot taken in step 2. It does
not infer prior values.

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
   AND h.released_at >= TIMESTAMPTZ '2026-07-29 00:00:00+00';   -- ⚠ the DATE YOU APPLY
COMMIT;

SELECT count(*) FROM slug_history;   -- must be 2 afterwards: your own two from 2026-07-26
```

⚠ **If you apply on a later date, change that timestamp to that date.** It is what stops the
delete touching anything the run did not create. There are exactly **2** `slug_history` rows on
live right now, both yours from 2026-07-26 — so the "must be 2" check at the end is exact.

The apply only ever writes two things — `users.slug`/`slug_changed_at`, and one `slug_history`
row per member holding their OLD handle. The `UPDATE` undoes the first from the snapshot; the
`DELETE` undoes the second, matched on the snapshot's own slug so it can only remove rows this
run created. After a rollback, re-run step 6 to flush the reverted handles out of the WordPress
cache.

---

## 1. Prerequisite — the code must be DEPLOYED, not copied to /tmp

**Re-confirmed on live today, not assumed.** `backfill-slugs.php` calls `Slug::derive()`,
`Slug::deriveUsable()` and `Slug::fit()`. Live's deployed `src/Slug.php` contains exactly:
`change, check, checkShape, cooldownDaysLeft, currentSlugForRetired, norm` — **none of those
three.** They are added by this branch. `config.php` `require_once`s the *deployed* `Slug.php`,
so a `git show`-to-`/tmp` copy fatals on an undefined method. There are also **no slug scripts on
live at all** yet, and no `bin/lib/`.

So the apply needs the branch merged and pulled. `/srv/profile-app` is a single symlink into the
checkout, so one pull deploys all of it — no per-file symlink coupling for this change.

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

**Must print 1876** (verified on live today). If it errors or prints anything else, STOP —
without this table the apply is not reversible. (`sudo -u profile-app psql` is this repo's
established idiom, but live's `pg_hba.conf` is not readable to my role, so I have not proven that
exact invocation authenticates *on live*. That is why this step is gated on a number.)

## 3. Dry run — and check it still matches

```bash
# as: profile-app
sudo -u profile-app php /srv/profile-app/bin/backfill-slugs.php \
     --scope=repair --identity-from-wp --expand-bare-names --hold-contested-bare \
     --hold-duplicate-names --tsv=/tmp/preflight.tsv --html=/tmp/preflight.html
sudo -u profile-app php /srv/profile-app/bin/verify-slug-plan.php --plan=/tmp/preflight.tsv
```

Expect:

```
--expand-bare-names: expanded 14 bare first name(s) from stored identity
--hold-duplicate-names: withholding 2 account(s) sharing a display_name; acting on 1535
--hold-contested-bare: withholding 41 contested bare handle(s); acting on 1494
members=1806  changing=1537  NEED-RULING=70  scope=repair  acting-on=1494  mode=DRY RUN (no writes)
patreon api: wp_usermeta patreon_latest_patron_info — 1585 identities (no API call)
VERDICT: no member-harming conflict found
```

> **`1585` on live is correct — my dry run said `1586`.** I ran the plan on dev2 against a live
> export, so that one line reflects dev2's `wp_usermeta`. I compared the two identity maps
> directly rather than hand-waving it: **0** Patreon ids present on live are missing from dev2,
> and **0** of the 1,584 shared ids differ in `full_name`, `vanity` or `email`. dev2 carries one
> extra id (`69938503`) that matches no live member or handle. The derivation is therefore
> identical; only the count differs.

The verifier also prints **`E. proposal ends in -<digits> : 2 (advisory)`** —
`/u/michael-levesque-studio-46` and `/u/diamondboat-1`. Both are digits those members genuinely
have in their names ("Studio 46", "Diamondboat 1"), not a suffix we minted. That is expected and
is not a stop.

**If the verifier exits non-zero, stop.** Matching counts alone are not enough — totals can hold
while individual rows move underneath them, which is why the second command re-checks every
proposal against live ownership. `--identity-from-wp` only actually loads when there are
collisions to resolve, so the `patreon api:` line **is** the proof it ran.

## 4. Apply

```bash
# as: profile-app
sudo -u profile-app php /srv/profile-app/bin/backfill-slugs.php \
     --scope=repair --identity-from-wp --expand-bare-names --hold-contested-bare \
     --hold-duplicate-names --apply
```

Expect `applied=1494 failed=0`. One transaction per member, so a failure at row 900 keeps the 899
before it and re-running resumes. Each old `patreon_<id>` URL is parked in `slug_history`, which
is what keeps every shared and indexed link alive as a 301.

Smaller first bite: add `--limit=25`. The same rollback covers it.

**Do NOT add `--allow-email-derived-slugs`.** It is in no command here and must not be.

## 5. Verify

```bash
# as: profile-app
sudo -u profile-app psql -d profile_app -c \
  "SELECT count(*) FROM users WHERE slug ~* '^patreon[_-]?[0-9]+$';"   -- 1634 -> 140 exactly
sudo -u profile-app psql -d profile_app -c "SELECT count(*) FROM slug_history;"  -- 2 -> 1496
```

```bash
# as: ubuntu. Pin the LAN IP — 127.0.0.1 returns 000, and a plain public curl gets
# Cloudflare-challenged into a 403 that reads as an outage.
for u in /u/katie-mccartney /u/patreon_112642237 /u/matt /u/ian-davlin; do
  printf '%-28s %s\n' "$u" \
    "$(curl -sk -o /dev/null -w '%{http_code}' --resolve loothgroup.com:443:172.31.67.175 \
        "https://loothgroup.com$u")"
done
```

Every "before" below was measured on live today, so these are real transitions, not guesses:

| URL | before | after | what it proves |
|---|---|---|---|
| `/u/katie-mccartney` | 404 | **200** | a merge-freed collision now resolves |
| `/u/patreon_112642237` | 200 | **301** | the old link still works |
| `/u/matt` | 404 | **404** | the bare-name ruling held |
| `/u/ian-davlin` | 200 | **200** | control — proves a 404 above means "no such handle", not "check broken" |

That control line matters. Without it, a harness that silently fails returns 404 for everything
and the `/u/matt` row looks like a pass.

## 6. Same window — flush the WordPress cache

WordPress caches the slug in `_looth_slug` usermeta and nothing invalidates it. It feeds the
"My Profile" link in the site header, so until this runs, changed members click through to their
own retired URL. It still resolves via the 301, which is exactly why it went unnoticed.

```bash
# as: profile-app to generate, ROOT to run
sudo -u profile-app php /srv/profile-app/bin/purge-stale-looth-slug-mirror.php
sudo -u profile-app php /srv/profile-app/bin/purge-stale-looth-slug-mirror.php --sql \
     > /tmp/slug-mirror.sql
sudo wp --allow-root --path=/var/www/dev db query < /tmp/slug-mirror.sql
```

Expect **`mirrors=434  in-sync=95  STALE=338  no-pg-row=1`**.

**This number was recomputed for the 1,494 plan — it is not the 328 the previous draft carried.**
That figure was measured against the older 1,487-member plan, and this step drives a DELETE, so
it gets recomputed rather than inherited. Replayed live's real 434 mirror rows against this exact
plan: the generated SQL is **one** `DELETE`, touching only `_looth_slug`, naming **338** user ids,
and all 338 exist on live. Deleting rather than rewriting is deliberate — a missing mirror is
re-resolved from Postgres on the member's next pageview, so it self-heals.

`--path=/var/www/dev` **is correct on live** — the vhost really is
`server_name loothgroup.com …; root /var/www/dev;` and `LG_MYSQL_DB=looth_import`. Do not "fix"
it to `/var/www/html`, which is the stock nginx placeholder.

## 7. Independent of all the above — two members have a dead profile link RIGHT NOW

Their `_looth_slug` cache holds a handle that 404s, so their "My Profile" link is a dead page
today. **No deploy, no backfill, no ruling needed.** Verified on live today:

| wp | member | cached (dead) | their real URL |
|---|---|---|---|
| 1431 | 1265 Bryan Hutchinson | `/u/patreon_178784349-1431` → **404** | `/u/patreon_178784349` → 200 |
| 1768 | 1585 Tom McDonough | `/u/tmcdonough8-1768` → **404** | `/u/tmcdonough8` → 200 |

```sql
-- as ROOT: sudo wp --allow-root --path=/var/www/dev db query
DELETE FROM wp_usermeta WHERE meta_key = '_looth_slug' AND user_id IN (1431, 1768);
```

Safe to run before everything else. (Your own row, wp#1, is also stale — cached `iandavlin`,
actual `ian-davlin` — but it **301s**, so your link works. Step 6 tidies it.)

---

## What is being left alone, and why

**41 contested bare first names.** Nobody gets `/u/matt`, `/u/jeff`, `/u/steve`. They stay free
for a future flow where a member actively asks. Patreon holds a `last_name` for only 10% of
bare-name members against 91% of everyone else, so the surname is genuinely unrecoverable — with
no basis to choose between 19 Matts, the honest answer is nobody, rather than letting the import
accident decide. Full list in the review page under **"Held by ruling"**, and in `--tsv` as
`7-HELD-CONTESTED-BARE`.

**14 bare names were expanded instead**, because a real surname is not an accident:
`/u/jeff-ferk`, `/u/steve-mcdonald`, `/u/tim-staver`, `/u/neil-walwer`, `/u/pete-marten`,
`/u/max-bierman`, plus 8 uncontested expansions that also repair a damaged stored name
(`powersdj1 .` → `/u/dave-powers`, `CRC` → `/u/clancey-compton`, `alsato` → `/u/al-sato`). No
`display_name` is ever rewritten — only the URL improves. One member keeps their own choice:
732 `Seb` → `/u/meeloo`, their self-chosen Patreon vanity.

**56 collisions and 2 duplicate-name holds** stay put, including the four pairs you ruled
LEFT ALONE (McNeill, Goulart, Morrissey, Fox) and the four still unmerged (Cox, Taylor, Smith,
Jaeger). None of them blocks this run.

**8 non-Latin / too-short names** keep their URLs until a member can choose a handle, as ruled.

## One thing you should know that is NOT part of this apply

**Six members are publicly displaying an email address as their name on loothgroup.com right
now** — their stored `display_name` *is* an email. Four of them were in the apply set, and the
run would have turned that into a permanent, 301-backed public URL
(`/u/mdoran2000-aol-com`). That is now blocked (`0d-NAME-IS-AN-EMAIL`, commit `c6aba90`), which
is why acting-on is 1,494 and not 1,498.

Two of the six already have it as their live URL — `/u/alrightguybellsouth-net` and
`/u/thomadkinstelus-net`, both 200 today. **This run cannot fix those**: the cure is a real name,
not a new slug. Fixing it means asking those six members for a name, which is a message from you,
not a script. Detail in `docs/atlas/SLUG-RULING-QUEUE.md` §Q5.

*Reports carrying member emails: `~/lane-reports/slug-backfill/`, mode 0600, deliberately not in
this repo.*
