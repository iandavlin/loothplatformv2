# Live DB hygiene audit — 2026-07-26

Lane `live-db-hygiene` (dev1). Two read-only audits of the **live** box
(`54.146.118.131`). All evidence gathered as `looth-ro` over `ssh live-ro` — no sudo,
no psql, MySQL via that account's `~/.my.cnf`. **Nothing on live was changed by this
audit.** Every remediation below is bash for Ian to run.

**Credential handling:** no credential, key, token, hash or salt value is reproduced
anywhere in this document. Secrets are named by option name / env key / file path and
described by byte length only. Where a severity call depended on a non-secret config
flag (`STRIPE_MODE`, `APP_ENV`, `DB_NAME`) that flag was read; no secret value was.

Server: MariaDB 10.11.14. `log_bin = OFF`. `performance_schema = OFF`.

---

## ITEM 1 — the `looth_dev` decoy database

### Bottom line

`looth_import` is production. `looth_dev` is the **dev.loothgroup.com** database, frozen
2026-06-13 10:10:30, riding along on the live box because the live box was built from the
dev2 AMI. It is **provably write-dead**, has **one** remaining live reference (a vestigial
GRANT), and is safe to decommission — but it holds ~8 published rows that exist nowhere
else, so a dump must precede any drop.

Critically: **`looth_import` is NOT a copy-forward of `looth_dev`, and `looth_dev` is not a
copy of `looth_import`.** Each holds content the other has never held at any ID. The
pre-Jun-13 count gap (19,960 vs 19,871) is **not missing production data** — see 1B.

### 1A — what still references `looth_dev`

**They are two different sites.** This is the single fastest way to tell them apart:

| | `looth_dev` | `looth_import` |
|---|---|---|
| `wp_options.siteurl` | `https://dev.loothgroup.com` | `https://loothgroup.com` |
| newest post | 2026-06-13 10:10:30 | 2026-07-26 15:05:47 |
| total posts | 19,974 | 20,454 |
| tables / size | 291 / 815 MB | 293 / 1045 MB |
| all tables `create_time` | 2026-06-17 (uniform) | 2026-07-01 (uniform) |
| `wp_posts` AUTO_INCREMENT | 71666 (stuck) | 72341 |
| `information_schema` `update_time` | **NULL on all 291 tables** | 2026-07-26 19:35:10 |

Uniform per-database `create_time` = two bulk operations, not organic growth. `looth_dev`'s
tables materialised **2026-06-17** (a filesystem-level image — the dev2 AMI the live box was
cut from), four days after its content froze. `looth_import`'s materialised **2026-07-01
01:22**, 25 minutes after the MySQL instance started (uptime at audit = 2,227,512 s ≈ 25.8 d,
i.e. ~2026-07-01 00:57) — the live cut restore.

**Write-dead, proven.** No account anywhere holds INSERT/UPDATE/DELETE/DROP on `looth_dev`:

```
SELECT User,Host,Db,Insert_priv,Update_priv,Delete_priv,Drop_priv FROM mysql.db WHERE Db LIKE 'looth_dev%';
profile-app  localhost  looth_dev  N  N  N  N        <-- the ONLY row, SELECT only
```

…and all 291 tables have `update_time IS NULL` across the entire 25.8-day life of the live
instance (`SELECT COUNT(*), SUM(update_time IS NOT NULL) … = 291, 0`). Zero writes since the
box went into production.

**The one live reference: `GRANT SELECT ON looth_dev.* TO 'profile-app'@'localhost'`.**
It is vestigial. `/srv` is **100% readable** to `looth-ro` (5271/5271 files) and contains
`looth_dev` in only two places, neither an active consumer:

- `/srv/lg-stripe-billing/.env.bak-20260602-233115` → `WP_DB_NAME=looth_dev` (stale backup)
- `/srv/lg-stripe-billing/db/setup-database.sql` → repo DDL, not applied live

The **active** `/srv/lg-stripe-billing/.env` reads `WP_DB_NAME=looth_import`. That matches the
repo record of the fix: `docs/archive/handoff-coordinator-2026-06-03.md:58` — *"The split-brain
(events/profile-app/billing on stale `looth_dev`) was fixed this session — all repointed to
`looth_import`. `looth_dev` … is now vestigial/droppable."*

Two backfill scripts still say `looth_dev` but only in **comments/docstrings**, not in any DSN
(`/srv/profile-app/bin/backfill.php:6-7`, `/srv/archive-poc/bin/backfill.php:12`).

**WP itself has never pointed at it.** `wp-config.php` is unreadable to `looth-ro`, but two of
its backups are (see Item 2 finding #1) and both carry `DB_NAME=looth_import` as far back as
2026-06-17. Corroborated independently: the WP account `looth_dev_user` — *named* for the dead
database — holds grants on **`looth_import` only**. The decoy naming runs all the way down to
the MySQL account.

Also clear: **no cron, no systemd unit, no nginx conf** references it. `/etc/cron.d` holds only
`certbot`/`e2scrub_all`/`php`/`sysstat`; no `/etc/systemd/system` unit mentions it; no backup
job targets it (`/var/backups` holds only dpkg/apt/alternatives rotations).

**Limit of this proof, stated plainly:** `performance_schema = OFF`, so I could not instrument
*reads*. I proved **no writes** and **one SELECT-only grant**. A read by `profile-app` is
therefore *possible but unevidenced*. That limit is why the recommendation below revokes and
renames before it drops — it converts an unproven-read risk into a loud, reversible failure.

Two readable `/var/www/dev/.well-known/*.txt` backfill artifacts mention `looth_dev` and are
**publicly served on production** (HTTP 200, ~19 KB each). Scanned: they contain **no
credentials** — the only match is the comment `READ-ONLY on looth_dev. SELECT only.` and a
SQLite PDO DSN. Not a leak; noted only because serving 19 KB of internal source publicly is
avoidable, and because they are two more places the decoy name is written down.

### 1B — how the two databases actually relate

**Proven.** They share a common ancestor and then diverged **in both directions**:

- Identical oldest post in both: `2020-05-07 16:58:41`.
- 19,878 IDs present in both; of those only **30** differ in `post_type`/`post_date`/`post_name`
  — >99.8% of overlapping history is identical.
- **`looth_dev` exclusively holds 96 rows** (min ID 50797, max 71659; 2025-05-16 → 2026-06-13):
  77 `revision`, 4 `sponsor-post`, 3 `reply`, 3 `weekly_email`, 2 `post`, 2 `attachment`,
  2 `event`, 1 each `post-type-videos`/`topic`/`post-imgcap`.
- **`looth_import` exclusively holds** (below `looth_dev`'s max ID) 3 rows: 2 membership pages
  + 1 `oembed_cache`.

**The decisive evidence — mutually exclusive content at the *same* IDs.** IDs 71413–71423 hold
in `looth_import` eleven membership/billing pages all stamped `2026-06-03 07:37:0x` (one second
apart = script-created): `lgjoin`, `lggift-buy`, `lggift`, `manage-subscription`,
`regional-pricing-not-available`, `welcome`, `my-gifts`, `membership-guide`,
`affiliate-earnings`, `test-checklist`, `request-refund`. The same IDs in `looth_dev` hold
forum `reply`/`attachment` rows dated `2026-06-02 11:02 → 13:44` (spread over hours = organic
activity).

Neither set exists in the other database **at any ID** — I checked by slug for the pages and by
`post_date`+`post_type`+`post_parent` for the replies; both came back empty. Two independent
ID-minting streams occupied the same range. All 30 mismatches are confined to **IDs
71413–71474** plus 3 isolated pre-71413 rows that are production edits landing after the split
(e.g. ID 67625, same type, `post_date` 2026-02-14 in dev vs 2026-06-11 in production).

**That settles the keeper's question:** the 19,960 vs 19,871 pre-Jun-13 gap is dev-side junk
(77 pruned revisions + auto-drafts + trashed weekly digests + duplicate sponsor-posts) on one
side, against 11 real membership pages + production edits on the other. It is **not** evidence
that `looth_import` lost anything, and `looth_import` is **not** a promotion of `looth_dev`.

**What I could NOT prove.** Above ID 71474 the two databases *re-converge* — of 163 shared IDs
in 71424–71665, 145 are identical, including production content dated 2026-06-09 → 06-13 at
production's own IDs. Independent minting cannot produce that. The only mechanism consistent
with all of the evidence is an **additive, ID-preserving, conflict-skipping copy of production
rows into `looth_dev`** for roughly 06-09 → 06-13 (a top-off) — which also explains why the 11
membership pages never landed in dev: 9 of 11 IDs were already occupied by dev's own replies,
and the copy was evidently scoped to a later window so the 2 free IDs (71414, 71423) were never
filled either.

**I cannot prove that mechanism, and I am not asserting it as fact.** `log_bin = OFF` so no
binary logs exist; `looth_dev` arrived as a filesystem image so no per-row history survived; and
`docs/CUT-DAY-DATA-TOPOFF.md` documents cut-day top-offs of the **profile-app/PG/social** data,
not a MySQL row copy into `looth_dev`. Settling it needs the dev2-side tooling logs from the
2026-06-09 → 06-13 window, which are not reachable from live. The conclusions in 1A and the
"not a copy-forward" finding do **not** depend on it.

### 1C — recommended decommission

**Recommendation: revoke → rename → drop, in three reversible steps with a soak between
each.** Not straight dump-and-drop.

Why rename rather than drop directly: the *name* is the actual defect — it burned a full mail
investigation (three wrong answers) precisely because it looks authoritative. Renaming to
`looth_dev_ARCHIVED_20260613` is metadata-only, instant, trivially reversible, and makes any
hidden consumer fail **loudly** (`Unknown database`) instead of silently reading 6-week-old
data. Combined with revoking the last grant, that closes the read-risk I could not instrument
away, without an irreversible step. Then drop once it has been quiet.

Pre-validated so Ian hits no blocker: all **291 objects are BASE TABLEs**, and there are
**0 views, 0 triggers, 0 routines, 0 events** — so `RENAME TABLE` carries everything. Collation
is `utf8mb4 / utf8mb4_unicode_520_ci`, identical to `looth_import`; the archive DB must be
created with that exact collation (`docs/CUT-FROM-SCRATCH.md:383` warns cross-DB JOINs break
otherwise).

**Do not skip the dump:** ~8 *published* rows exist only in `looth_dev` — `event` 71172
"Marketing Club - Building a Modern Guitar Brand on Social" (+ attachment 71173), `topic` 50797
"Bevel-up vs. Bevel-down gouges…", `sponsor-post` 70265/70270/71540, and 3 published replies.
Almost certainly dev-authored and never promoted, but Ian should eyeball that event before the
final drop.

#### Step 1 — dump, verify, revoke the last grant (fully reversible, do first)

```bash
set -euo pipefail
TS=$(date -u +%Y%m%d-%H%M%SZ)
OUT=/var/backups/looth_dev-DECOMM-$TS.sql.gz

# pre-state for the record
sudo mysql -e "SELECT COUNT(*) tables FROM information_schema.tables WHERE table_schema='looth_dev';
               SELECT COUNT(*) posts, MAX(post_date) newest FROM looth_dev.wp_posts;
               SELECT User,Host,Db FROM mysql.db WHERE Db='looth_dev';"

# dump WITH routines/triggers/events and CREATE DATABASE, no locking on a live server
sudo bash -c "mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
  --routines --triggers --events --databases looth_dev | gzip -9 > '$OUT'"

# verify the dump before touching anything
sudo gzip -t "$OUT" && echo "gzip OK"
echo -n "CREATE TABLE count (expect 291): "; sudo zgrep -c '^CREATE TABLE' "$OUT"
sudo sha256sum "$OUT" | sudo tee "$OUT.sha256"
sudo chmod 0600 "$OUT" "$OUT.sha256"        # dump contains live member PII + hashes

# revoke the one remaining reference
sudo mysql -e "REVOKE SELECT ON \`looth_dev\`.* FROM 'profile-app'@'localhost'; FLUSH PRIVILEGES;"
sudo mysql -e "SELECT User,Host,Db FROM mysql.db WHERE Db LIKE 'looth_dev%';"   # expect EMPTY
```

**Smoke after Step 1** (this is where a hidden `looth_dev` reader would surface):

```bash
curl -sS -o /dev/null -w 'home %{http_code}\n'      https://loothgroup.com/
curl -sS -o /dev/null -w 'profile %{http_code}\n'   https://loothgroup.com/u/
curl -sS -o /dev/null -w 'whoami %{http_code}\n'    https://loothgroup.com/whoami
curl -sS -o /dev/null -w 'billing %{http_code}\n'   https://loothgroup.com/billing/
curl -sS -o /dev/null -w 'events %{http_code}\n'    https://loothgroup.com/events/
sudo tail -50 /var/log/php*-fpm.log 2>/dev/null | grep -i "unknown database\|access denied" || echo "no DB errors"
sudo grep -ri "looth_dev" /var/log/nginx/*error* 2>/dev/null | tail -5 || echo "clean"
```
Plus one real admin login and one profile page render. If anything breaks, Step 1 reverses with
a single `GRANT SELECT ON looth_dev.* TO 'profile-app'@'localhost';`.

#### Step 2 — after a 7-day soak: rename to a dead name (instant, reversible)

```bash
set -euo pipefail
NEW=looth_dev_ARCHIVED_20260613

# guard: RENAME TABLE does not carry these — all must be 0
sudo mysql -N -e "SELECT
 (SELECT COUNT(*) FROM information_schema.views    WHERE table_schema='looth_dev'),
 (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='looth_dev'),
 (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema='looth_dev'),
 (SELECT COUNT(*) FROM information_schema.events   WHERE event_schema='looth_dev');"

sudo mysql -e "CREATE DATABASE \`$NEW\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
sudo mysql -N -e "SELECT CONCAT('RENAME TABLE \`looth_dev\`.\`',table_name,'\` TO \`$NEW\`.\`',table_name,'\`;')
                  FROM information_schema.tables WHERE table_schema='looth_dev' AND table_type='BASE TABLE';" \
  | sudo tee /root/rename-looth_dev.sql >/dev/null
sudo bash -c "mysql < /root/rename-looth_dev.sql"
sudo mysql -e "SELECT COUNT(*) should_be_0 FROM information_schema.tables WHERE table_schema='looth_dev';"
sudo mysql -e "DROP DATABASE \`looth_dev\`;"   # now an empty shell
sudo mysql -e "SELECT COUNT(*) archived_tables FROM information_schema.tables WHERE table_schema='$NEW';"  # 291
```
Re-run the Step 1 smoke block. Reverse by renaming back (same script with the names swapped).

#### Step 3 — after a second clean 7-day soak: drop

```bash
sudo mysql -e "DROP DATABASE \`looth_dev_ARCHIVED_20260613\`;"
sudo mysql -e "SHOW DATABASES;"    # expect: information_schema, lg_membership, looth_dev_TOMBSTONE, looth_import, mysql, performance_schema, sys
```
The Step 1 dump + its `.sha256` is the archive. Keep it off-box too if it is the only copy —
it contains 1,836 members' PII and password hashes.

#### Tombstone

Three places, because the failure mode was a *human/agent* reading the wrong name:

1. **This document** — the durable record.
2. **A MySQL breadcrumb**, so anyone who goes looking for `looth_dev` finds the explanation
   instead of silence:

```bash
sudo mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS `looth_dev_TOMBSTONE`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
CREATE TABLE IF NOT EXISTS `looth_dev_TOMBSTONE`.`README` (
  id TINYINT PRIMARY KEY, note TEXT NOT NULL
);
REPLACE INTO `looth_dev_TOMBSTONE`.`README` (id,note) VALUES (1,
'looth_dev was the dev.loothgroup.com database (siteurl=https://dev.loothgroup.com). It rode onto
this box in the dev2 AMI, froze 2026-06-13 10:10:30, took zero writes, and was decommissioned
2026-07-26 (dump: /var/backups/looth_dev-DECOMM-*.sql.gz).
PRODUCTION IS looth_import (siteurl=https://loothgroup.com). The MySQL account named
looth_dev_user is granted on looth_import, NOT on looth_dev -- the name is a decoy.
looth_import is NOT a copy-forward of looth_dev; they diverged in both directions.
Do not query looth_dev for anything. See docs/LIVE-DB-HYGIENE-AUDIT.md in loothplatformv2.');
SQL
```

3. **Stop the runbook recreating it.** The repo still rebuilds the decoy on a fresh cut —
   these need editing (flagged, not changed by this lane; `CUT-FROM-SCRATCH.md` is a keeper-owned
   runbook and this is Ian's call):
   - `docs/CUT-FROM-SCRATCH.md:371` — lists `looth_dev` as a database to carry
   - `docs/CUT-FROM-SCRATCH.md:388` — `GRANT ALL PRIVILEGES ON looth_dev.* TO 'looth_dev_user'` (note: live never applied this — live has no such grant)
   - `docs/CUT-FROM-SCRATCH.md:397` — `GRANT SELECT ON looth_dev.* TO 'profile-app'` (this **is** the grant Step 1 revokes)
   - `lg-stripe-billing/db/setup-database.sql:18-19` — grants `lg_membership` SELECT on `looth_dev.wp_users`/`wp_usermeta` (not applied live)
   - `/srv/profile-app/bin/backfill.php:6-7`, `/srv/archive-poc/bin/backfill.php:12` — comments to correct
   - Consider renaming the MySQL account `looth_dev_user` → `looth_wp` in a later pass; it is
     the last load-bearing piece of the decoy naming.

---

## ITEM 2 — `looth-ro` credential exposure

The keeper's finding is real and confirmed: **`fluentmail-settings`** (1292 bytes,
`autoload=yes`) in `looth_import.wp_options` is readable by `looth-ro`. But the account's
blast radius is materially wider than that one row, and one finding below **upgrades** the SES
exposure from "key ID leak" to a plausible full-credential compromise.

The root cause is one grant:

```
GRANT SELECT, PROCESS, SHOW VIEW ON *.* TO `looth_ro`@`localhost`
```

`ON *.*` includes the `mysql` schema. A "look, don't touch" auditor was given the whole server.

Not internet-facing: every sensitive path tested returns **HTTP 403** from nginx
(`/wp-config.php`, all three `wp-config.php.bak-*`, `/billing/.env`, `/.env`). This is a
**local-account** exposure. It still voids the read-only guarantee, because of #1.

### Findings, worst first

**#1 — P0: `looth-ro` can obtain WRITE credentials for production WordPress.**
`wp-config.php` is correctly protected (`0660 looth-dev:loothdevs`, no `looth-ro` ACL — read
denied). But two of its backups carry an **explicit per-user ACL granting `looth-ro`**:

```
/var/www/dev/wp-config.php.bak-presalts-20260620-035726       user:looth-ro:r--    READABLE
/var/www/dev/wp-config.php.bak-pre-livesalts-20260617-175737  user:looth-ro:r-x    READABLE
/var/www/dev/wp-config.php                                    (no looth-ro entry)  denied
/var/www/dev/wp-config.php.bak-debugoff-20260704              (no looth-ro entry)  denied
/var/www/dev/wp-config.php.bak-dedupe-20260704                (no looth-ro entry)  denied
```

Those are deliberate `setfacl` grants, not a mode accident. Both files contain
`DB_NAME`/`DB_USER`/`DB_PASSWORD` and all eight WP secrets (`AUTH_KEY`, `SECURE_AUTH_KEY`,
`LOGGED_IN_KEY`, `NONCE_KEY`, `AUTH_SALT`, `SECURE_AUTH_SALT`, `LOGGED_IN_SALT`, `NONCE_SALT`).

That `DB_PASSWORD` belongs to **`looth_dev_user`, which holds SELECT/INSERT/UPDATE/DELETE on
`looth_import`** — production. So the read-only audit account can escalate to **read-write on
live WordPress**. Treat the password as current until rotated; the filenames
("presalts"/"pre-livesalts") indicate the **salts** were rotated after these snapshots, so
cookie-forgery risk from them is probably stale, but that is an inference from a filename, not
a verified fact.

**#2 — P0: global `SELECT` includes `mysql`, giving a path to DB root.**
`SELECT COUNT(*) FROM mysql.global_priv` → **9**. All nine MySQL accounts' password hashes are
readable, including `root@localhost` and the `mysql@localhost` superuser (both
`Super_priv=Y`, `Grant_priv=Y`). Offline cracking of any of those is a straight
auditor → DB-root escalation. `SHOW GRANTS FOR CURRENT_USER()` additionally prints
`looth_ro`'s own hash back to it.

**#3 — P1: SES key (the original finding), and how #1 makes it worse.**
`fluentmail-settings` holds the Amazon SES **access key ID in plaintext**; the secret is
encrypted in that row. On its own the access key ID is not usable — SES SigV4 needs the
secret — so the standalone risk is disclosure of the AWS account/identity and a narrowed
target. **But** FluentSMTP derives its encryption from WP's salts, and those salts are in the
ACL-readable wp-config backups (#1). If the salts in those backups still match the running
`wp-config.php`, the encrypted secret becomes decryptable and this is a **full SES credential
compromise**. I did not attempt decryption and did not read either value, so treat the
coupling as **to be verified, not proven** — verify by comparing the salts in the running
config against the backups (Ian, locally; do not export them).

Blast radius of a working SES credential: send-as-`loothgroup.com` with correct
SPF/DKIM alignment — i.e. authentic-looking phishing at the 1,836 live members whose addresses
are in the same reachable database; burn the sending quota; wreck domain sender reputation and
deliverability (hard to undo); read SES sending stats. Anything beyond that depends on the IAM
policy attached to that key, which is not enumerable from the box.

**#4 — P1: `/srv/lg-stripe-billing/.env` is world-readable *and* ACL'd to `looth-ro`.**

```
-rw-rw-r--+ www-data www-data /srv/lg-stripe-billing/.env
    user:looth-ro:r--   other::r--        <-- any local account on the box
-rw-rw-r--+ www-data www-data /srv/lg-stripe-billing/.env.bak-20260602-233115   (same)
```
Keys present (names only): `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `DB_PASSWORD`,
`LGMS_SHARED_SECRET`, `JWT_PUBLIC_KEY_PATH`, `LGMS_SYNC_URL`, `LGMS_GIFT_MAIL_URL`.
Severity clamp: `STRIPE_MODE=test` and `APP_ENV=dev`, so the Stripe keys are test-mode — no
direct money risk. Real risk is `LGMS_SHARED_SECRET` (forge WP↔billing sync calls →
entitlement manipulation) and `DB_PASSWORD` for the write-capable `lg_membership` account.
`/etc/looth/jwt-public.pem` is also readable — that one is a public key, benign.

**#5 — P2: live Patreon OAuth credentials readable.** In `looth_import.wp_options`:
`patreon-client-secret` (64 B), `patreon-client-id` (64 B), `patreon-creators-access-token`
(43 B), `patreon-creators-refresh-token` (43 B), `lgpo_client_secret` (64 B),
`lgpo_creator_access_token` (43 B), `lgpo_creator_refresh_token` (43 B). The production
Patreon app authenticates against **live**. A refresh token is durable access; creator scope
exposes the patron list and PII.

**#6 — P2: member PII and password hashes.** `looth_import.wp_users` → 1,836 rows, all with
readable `user_pass` and `user_email`. Plus `wp_usermeta` and the BuddyBoss xprofile tables.
Offline cracking + a complete member list. GDPR-relevant.

**#7 — P2: the billing database is fully readable.** `lg_membership` — `customers`,
`entitlements`, `gift_codes`, `affiliates`, `affiliate_conversions`, `admin_action_log`,
`banned_emails`.

**#8 — P2: more plugin secrets in `wp_options`**, by name: `bb-pusher-app-secret`,
`ninja_forms_oauth_client_secret`, `ninja-forms-views-secret`, `_fluentform_security_salt`,
`bp-emails-unsubscribe-salt`, `ai1wm_secret_key` (All-in-One WP Migration — can enable remote
full-site export), `content_control_debug_log_token`, `gmail_smtp_options`, plus ~15 vendor
license keys.

**#9 — P3: `PROCESS`.** `SHOW PROCESSLIST` / `information_schema.PROCESSLIST` across all
users exposes in-flight query text — any credential or PII passed as a literal in a query.
An auditor does not need it; without it they still see their own threads.

### The structural point

**No GRANT can fix #3, #5 and #8.** MySQL has no row-level privileges, so *any* account that
can `SELECT wp_options` reads every plugin secret in it. Tightening grants shrinks the
surface; it does not solve secrets-in-`wp_options`. The durable fixes are rotation plus moving
secrets out of `wp_options` behind the existing secrets helper (`docs/SECRETS-DASH.md`,
`docs/ROTATE-SECRETS.md`), or exposing auditors a view that excludes secret-bearing
`option_name`s rather than the base table.

### Recommendation

Do **both** — tighten the grant *and* rotate. They fix different things: the grant stops
future reads; rotation handles the fact that these values have been reachable by an account
whose whole purpose is being handed out for "safe" access, for an unknown period, with no
read auditing (`performance_schema = OFF`, no binlog). Anything reachable by `looth-ro`
should be considered disclosed.

Order matters: **fix the filesystem ACLs first** (#1 is the escalation), then the grant, then
rotate.

#### Step 1 — kill the filesystem escalation (do this first, it is the P0)

```bash
# strip the looth-ro ACLs from the wp-config backups
sudo setfacl -x u:looth-ro /var/www/dev/wp-config.php.bak-presalts-20260620-035726
sudo setfacl -x u:looth-ro /var/www/dev/wp-config.php.bak-pre-livesalts-20260617-175737
sudo chmod 0640 /var/www/dev/wp-config.php.bak-*

# better: wp-config backups do not belong in the webroot at all
sudo mkdir -p /root/wp-config-archive && sudo chmod 0700 /root/wp-config-archive
sudo mv /var/www/dev/wp-config.php.bak-* /root/wp-config-archive/

# lock the billing env (world-readable today) + drop the stale backup
sudo setfacl -x u:looth-ro /srv/lg-stripe-billing/.env
sudo chmod 0640 /srv/lg-stripe-billing/.env
sudo chown www-data:www-data /srv/lg-stripe-billing/.env
sudo rm -f /srv/lg-stripe-billing/.env.bak-20260602-233115   # stale; also names looth_dev

# find any other ACL handed to looth-ro
sudo getfacl -R -s /srv /var/www/dev /etc/looth 2>/dev/null | grep -B4 'looth-ro' | head -40

# verify from the auditor's side (should all be 'denied')
ssh live-ro 'for f in /var/www/dev/wp-config.php.bak-presalts-20260620-035726 \
  /var/www/dev/wp-config.php.bak-pre-livesalts-20260617-175737 /srv/lg-stripe-billing/.env; do
  printf "%s: " "$f"; head -c1 "$f" >/dev/null 2>&1 && echo READABLE || echo denied; done'
```

#### Step 2 — tighten `looth-ro` to what an auditor actually needs

```bash
sudo mysql -e "
REVOKE ALL PRIVILEGES, GRANT OPTION ON *.* FROM 'looth_ro'@'localhost';
GRANT SELECT, SHOW VIEW ON \`looth_import\`.*  TO 'looth_ro'@'localhost';
GRANT SELECT, SHOW VIEW ON \`lg_membership\`.* TO 'looth_ro'@'localhost';
FLUSH PRIVILEGES;
SHOW GRANTS FOR 'looth_ro'@'localhost';"
```
Deliberately: **no `PROCESS`**, **no `mysql` schema**, **no `looth_dev`** (aligns with Item 1).
`information_schema` stays readable automatically, so table/size inventories still work.
Verify the escalation path is closed:

```bash
ssh live-ro 'mysql -e "SELECT COUNT(*) FROM mysql.global_priv;" 2>&1 | tail -1;   # expect Access denied
             mysql -e "SHOW PROCESSLIST;"           2>&1 | tail -1;               # own threads only
             mysql -e "SELECT COUNT(*) FROM looth_import.wp_posts;" 2>&1 | tail -1'  # still works
```

Optional tier-2, if `looth-ro` is handed to anyone outside the core: expose views instead of
base tables — a `wp_users` view without `user_pass`, and a `wp_options` view filtered to
exclude secret-bearing `option_name`s — and grant SELECT on the views only. More upkeep (new
plugin secrets need adding to the filter), so only worth it if the account leaves the team.

#### Step 3 — rotate what was reachable

Priority order. **Rotate `looth_dev_user`'s DB password first** — that is the write path into
production.

1. `looth_dev_user` MySQL password — must be updated in lockstep in `wp-config.php`,
   `/etc/lg-events-db` and `/etc/lg-membership-db` (per `docs/CUT-FROM-SCRATCH.md:752` all
   three carry it) or WP/events/membership-pages 500 immediately. Do it in a maintenance
   window and `SET PASSWORD` + edit all three + reload FPM together.
2. **SES key** — create a new IAM access key, update `fluentmail-settings` via
   the FluentSMTP UI (not raw SQL, so the secret is re-encrypted correctly), send a test
   through the real emitter, *then* delete the old key in IAM. Do this regardless of the #3
   verification result: a credential reachable by an untrusted reader is compromised. While in
   IAM, confirm that identity is scoped to `ses:SendEmail`/`SendRawEmail` only.
3. `LGMS_SHARED_SECRET` + `lg_membership` DB password (both in the world-readable `.env`).
4. Patreon: rotate the client secret and re-run the creator OAuth flow to invalidate the
   access/refresh tokens (#5). Note the per-env app rule — the **prod** Patreon app is the
   live one.
5. Lower priority (#8): `bb-pusher-app-secret`, `ninja_forms_oauth_client_secret`,
   `ninja-forms-views-secret`, `ai1wm_secret_key`. Confirm All-in-One WP Migration's remote
   export is disabled.
6. WP salts — only if Step 3's verification shows the backup salts still match the running
   config. Rotating salts logs every member out, so it needs Ian's timing call.

Not required: WP member password hashes (#6) are bcrypt; forced reset is disproportionate
absent evidence of exfiltration. Worth noting the exposure in the security log.

---

## Evidence appendix — commands used

All run as `looth-ro` via `ssh live-ro`, read-only:

```sql
SHOW DATABASES; SHOW GRANTS FOR CURRENT_USER();
SELECT table_schema, COUNT(*), ROUND(SUM(data_length+index_length)/1024/1024), MAX(update_time), MAX(create_time)
  FROM information_schema.tables WHERE table_schema IN ('looth_dev','looth_import','lg_membership') GROUP BY table_schema;
SELECT Host,Db,User,Select_priv,Insert_priv,Update_priv,Delete_priv FROM mysql.db;
SELECT Host,Db,User,Table_name,Table_priv FROM mysql.tables_priv;
SELECT option_name, option_value FROM looth_{dev,import}.wp_options WHERE option_name IN ('siteurl','home','blogname','admin_email');
-- ID-level lineage
SELECT COUNT(*) FROM looth_dev.wp_posts d JOIN looth_import.wp_posts i ON i.ID=d.ID;              -- 19878
SELECT COUNT(*) FROM looth_dev.wp_posts d LEFT JOIN looth_import.wp_posts i ON i.ID=d.ID WHERE i.ID IS NULL;  -- 96
SELECT ... WHERE d.post_type<>i.post_type OR d.post_name<>i.post_name OR d.post_date<>i.post_date; -- 30, min ID 71413
SELECT ID,post_type,post_date,post_name FROM looth_dev.wp_posts WHERE post_name IN ('lgjoin',...); -- empty
-- exposure enumeration (NAMES + LENGTH ONLY, no values ever selected)
SELECT option_name, LENGTH(option_value), autoload FROM looth_import.wp_options WHERE option_name REGEXP '...';
SELECT COUNT(*) FROM mysql.global_priv;   -- 9
```
Shell: `getfacl` on the config/env files; `grep -rIl looth_dev /srv /var/www/dev /etc/*`;
`find /srv -type f -readable | wc -l` (5271/5271) and `/var/www/dev` (47422/47425 — the 3
unreadable are `wp-config.php` and two of its backups); `curl -o /dev/null -w %{http_code}`
for public reachability (bodies discarded, never saved).
