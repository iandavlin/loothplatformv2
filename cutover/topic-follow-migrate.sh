#!/usr/bin/env bash
# topic-follow-migrate.sh — the TWO live migrations the follow feature needs.
#
#   STEP 1  looth.forums.topic_follow          (the 🔔 bit itself)      as bb-mirror
#   STEP 2  profile_app.notifications_type_check widened               as profile-app
#
# BOTH ARE REQUIRED. Step 1 alone gets the toggle working and leaves the BELL
# broken — different database, different role, different failure.
#
# Runbook: docs/runbooks/live-topic-follow-migration.md — READ IT FIRST.
#
# DRY RUN BY DEFAULT, like cutover/symlink-farm.sh. Pass --apply to write.
# Both steps are additive and idempotent, guard themselves independently, and the
# script ALWAYS reaches both — see the note at step 1's skip branch.
#
# There are NO ids, slugs or interpolated values anywhere below — the DDL is fixed
# text. That is deliberate: the failure this repo has already paid for was an empty
# variable inside a chained $(...) in a paste-block. Nothing here is substituted, so
# there is nothing to come out empty.
set -euo pipefail

APPLY=0
[ "${1:-}" = "--apply" ] && APPLY=1

PSQL_USER="bb-mirror"   # owns the forums schema on BOTH boxes (verified 2026-07-30)
DB="looth"

say() { printf '%s\n' "$*"; }

# ── Guard 1: am I the right user to be doing this? ────────────────────────────
if ! id -u "$PSQL_USER" >/dev/null 2>&1; then
  say "ABORT: unix user '$PSQL_USER' does not exist on this box."
  say "       This script is for the box that runs the forums schema."
  exit 1
fi

q() { sudo -u "$PSQL_USER" psql -d "$DB" -tAc "$1"; }

# ── Guard 2: report the CURRENT state before changing anything ───────────────
say "==============================================================================="
say "STEP 1 of 2 — forums.topic_follow"
say "==============================================================================="
BEFORE="$(q "select coalesce(to_regclass('forums.topic_follow')::text,'MISSING');")"
say "  forums.topic_follow : $BEFORE"
STEP1_NEEDED=1
if [ "$BEFORE" != "MISSING" ]; then
  say "  rows                : $(q "select count(*) from forums.topic_follow;")"
  say "  ALREADY PRESENT — skipping step 1."
  STEP1_NEEDED=0
  # ⚠️ DELIBERATELY NOT `exit 0`. An earlier draft returned here, which meant a
  # RE-RUN after a partially-completed migration reported "nothing to do" and
  # silently skipped step 2 — leaving the toggle working and the BELL broken, with
  # Ian believing he was finished. The two steps are independent; each guards
  # itself, and the script always reaches both.
else
  say "  (absent — step 1 will create it)"
fi
say ""

# ── The DDL. Verbatim from bb-mirror/schema.pg.sql:254-265, schema-qualified so it
#    does not depend on the caller's search_path. ──────────────────────────────
read -r -d '' SQL <<'SQL_END' || true
BEGIN;

CREATE TABLE IF NOT EXISTS forums.topic_follow (
  user_id     BIGINT      NOT NULL,
  topic_id    BIGINT      NOT NULL,
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, topic_id)
);

CREATE INDEX IF NOT EXISTS idx_topic_follow_topic
  ON forums.topic_follow (topic_id);

-- looth-dev (arwd) and profile-app (r) arrive AUTOMATICALLY from bb-mirror's DEFAULT
-- PRIVILEGES on the forums schema — verified byte-identical on dev2 and live
-- 2026-07-30 via pg_default_acl, so the app's WRITE access needs no grant here.
-- looth_ro does NOT arrive that way (that default belongs to role postgres, and this
-- table is created by bb-mirror), so it is granted per-table, exactly as
-- forum_subscription is.
GRANT SELECT ON forums.topic_follow TO looth_ro;

-- ═══ `membership` — READ THIS BEFORE "TIDYING" IT AWAY ═══════════════════════
-- This block used to say 'membership' was a dev2-ONLY role, deliberately absent
-- from live, and that granting it would be wrong. THAT WAS FALSE AND IT CAUSED A
-- LIVE OUTAGE on 2026-07-31.
--
-- It was true of the box, and false of the CODE. It was written from `pg_roles`
-- on both boxes — live genuinely had no such role — without asking whether any
-- code needed one. It did: membership-pages/lib/following-data.php:121 requires
-- bb-mirror/config.php and uses its DSN, and that DSN is
--   pgsql:host=/var/run/postgresql;dbname=looth
-- with NO user and NO password, so Postgres PEER-AUTHS as the OS user — and the
-- membership FPM pool runs as OS user `membership`. So the "Discussions you're
-- following" list connects as `membership` on every box.
--
-- On live that role did not exist, so every load logged
--     [following] pg connect failed: FATAL: role "membership" does not exist
-- and members saw "We can't reach the discussion index right now." Ian created the
-- role and these three grants by hand to stop it. This block is that fix, made
-- reproducible — deploy is ONE PULL.
--
-- THE LESSON, so the same reasoning error is not repeated: a role's absence from
-- one box is evidence about the BOX, never about whether the role is NEEDED. Check
-- what the app CONNECTS AS — the FPM pool's `user =` plus the DSN — not what
-- pg_roles happens to contain today.
-- The role + its grants are NOT in this block. They run UNCONDITIONALLY as step 1b
-- below, because this block is skipped whenever the table already exists — which is
-- exactly the state live was in. A role fix that only runs on a box that has never
-- been migrated would have missed the very box that needed it.

COMMIT;
SQL_END

# ── Step 1b — the ROLE, always. ────────────────────────────────────────────────
# Deliberately NOT gated on STEP1_NEEDED. `topic_follow` existing tells you nothing
# about whether `membership` exists: on live the table was created first and the role
# was missing for hours afterwards. Both statements below are idempotent, so running
# this on every invocation costs nothing and closes that gap.
#
# ⚠️ TWO DIFFERENT ROLES RUN THIS, AND SWAPPING THEM BREAKS IT BOTH WAYS.
#
#   CREATE ROLE → must be `postgres`. `bb-mirror` has neither SUPERUSER nor
#     CREATEROLE (pg_roles, dev2, 2026-07-31), and running the DO block as
#     bb-mirror fails with:
#         ERROR: permission denied to create role
#         DETAIL: Only roles with the CREATEROLE attribute may create roles.
#     Measured, not assumed — the first draft of this step did exactly that.
#
#   GRANT → must be `bb-mirror`, the schema owner. Not for permission reasons
#     (postgres could) but because THE GRANTOR IS RECORDED IN THE ACL. dev2 and
#     live both read `membership=r/"bb-mirror"`; granting as postgres would write
#     `membership=r/postgres` and the two boxes would no longer be byte-identical,
#     which is the very property the verification below asserts.
read -r -d '' SQL_ROLE_SUPER <<'SQL_ROLE_END' || true
DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'membership') THEN
    CREATE ROLE "membership" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
  END IF;
END $$;
SQL_ROLE_END

read -r -d '' SQL_ROLE_OWNER <<'SQL_ROLE_END' || true
-- Mirrors dev2 exactly (verified byte-identical against pg_class.relacl, both boxes,
-- 2026-07-31). SELECT only: the page READS the follow list and never writes it — the
-- writer is bb-mirror's own role via follow.php.
GRANT USAGE  ON SCHEMA forums       TO "membership";
GRANT SELECT ON forums.forum        TO "membership";
GRANT SELECT ON forums.topic        TO "membership";
GRANT SELECT ON forums.topic_follow TO "membership";
SQL_ROLE_END
SQL_ROLE="$SQL_ROLE_SUPER
$SQL_ROLE_OWNER"

if [ "$APPLY" -eq 0 ]; then
  say "=== DRY RUN — nothing will be written. Step 1 SQL: ==="
  [ "$STEP1_NEEDED" -eq 1 ] && say "$SQL" || say "  (skipped — table already present)"
  say ""
  say "=== DRY RUN — step 1b SQL (ALWAYS runs, table present or not): ==="
  say "$SQL_ROLE"
  say ""
  say "=== DRY RUN — step 2 would widen profile_app.notifications_type_check to accept"
  say "    'forum.followed_topic' (see the runbook; source of truth is"
  say "    profile-app/sql/2026-07-28-followed-topic.sql). Nothing written."
  say ""
  say "Re-run with --apply to execute both steps."
  exit 0
fi

if [ "$STEP1_NEEDED" -eq 1 ]; then
  say "=== APPLYING step 1 ==="
  printf '%s\n' "$SQL" | sudo -u "$PSQL_USER" psql -d "$DB" -v ON_ERROR_STOP=1
fi

say "=== APPLYING step 1b — membership role + grants (idempotent, always) ==="
printf '%s\n' "$SQL_ROLE_SUPER" | sudo -u postgres     psql -d "$DB" -v ON_ERROR_STOP=1
printf '%s\n' "$SQL_ROLE_OWNER" | sudo -u "$PSQL_USER" psql -d "$DB" -v ON_ERROR_STOP=1

# ── Verify against the REAL table on the REAL box. A tool that sanitises on read
#    cannot audit the store, so this queries Postgres directly. ────────────────
say ""
say "=== AFTER ==="
AFTER="$(q "select coalesce(to_regclass('forums.topic_follow')::text,'MISSING');")"
say "  forums.topic_follow : $AFTER"
[ "$AFTER" = "forums.topic_follow" ] || { say "FAILED: table still absent."; exit 1; }

say "  rows                : $(q "select count(*) from forums.topic_follow;")"
say "  columns             : $(q "select string_agg(column_name||' '||data_type,', ' order by ordinal_position) from information_schema.columns where table_schema='forums' and table_name='topic_follow';")"
say "  indexes             : $(q "select string_agg(indexname,', ') from pg_indexes where schemaname='forums' and tablename='topic_follow';")"
say "  grants (non-owner)  : $(q "select coalesce(string_agg(grantee||':'||privilege_type,','),'(none)') from information_schema.role_table_grants where table_schema='forums' and table_name='topic_follow' and grantee<>'bb-mirror';")"
say ""
say "EXPECTED:"
say "  columns  user_id bigint, topic_id bigint, created_at timestamp with time zone"
say "  indexes  idx_topic_follow_topic, topic_follow_pkey"
say "  grants   MUST include looth_ro:SELECT  (from the explicit GRANT above)"
say "           MUST include looth-dev INSERT/SELECT/UPDATE/DELETE and profile-app:SELECT"
say "           (these arrive AUTOMATICALLY from bb-mirror's DEFAULT PRIVILEGES on the"
say "            forums schema — verified byte-identical on dev2 and live 2026-07-30)."
say ""
say "  ⚠️ 'membership' MUST appear as membership:SELECT. This reversed on 2026-07-31."
say "     This script used to assert the opposite — that 'membership' was dev2-only and"
say "     its absence on live was CORRECT. That was written from pg_roles on both boxes"
say "     without asking whether any code needed the role. It did: the Manage Account"
say "     following list peer-auths as OS user 'membership' via the membership FPM pool,"
say "     so live logged FATAL: role \"membership\" does not exist on every page load and"
say "     members saw an error box. If it is missing from the list above, the DO block"
say "     did not run — STOP and fix it, do not deploy the feature over it."
say ""
say "  ⚠️ If looth-dev is ABSENT from that list, STOP. The default privileges did not"
say "     fire, and the API will be able to READ but not WRITE — every toggle appears"
say "     to work and then reverts on reload. Report it; do not paper over it with an"
say "     ad-hoc grant before finding out why the default did not apply."
say ""
say "==============================================================================="
say "STEP 2 of 2 — profile_app.notifications_type_check"
say "==============================================================================="
# ⚠️ A SECOND MIGRATION, IN A DIFFERENT DATABASE, AS A DIFFERENT ROLE. Creating
# topic_follow alone gets the TOGGLE working and leaves the BELL broken: leg 4
# (lg-shared/notify-bridge.php) raises type 'forum.followed_topic', and live's
# notifications_type_check does not list it, so the INSERT violates the CHECK and
# internal-notify.php:106-108 catches it and returns HTTP 500 db_error. A member
# would follow a thread successfully and then never be told anything.
# Source of truth: profile-app/sql/2026-07-28-followed-topic.sql.
P_USER="profile-app"
P_DB="profile_app"
if ! id -u "$P_USER" >/dev/null 2>&1; then
  say "ABORT: unix user '$P_USER' does not exist — cannot complete step 2."
  say "       Step 1 SUCCEEDED. The toggle works; the BELL does not. Do not stop here."
  exit 1
fi
pq() { sudo -u "$P_USER" psql -d "$P_DB" -tAc "$1"; }

HAS="$(pq "select position('forum.followed_topic' in pg_get_constraintdef(oid))>0 from pg_constraint where conname='notifications_type_check';")"
say "  constraint already accepts forum.followed_topic : ${HAS:-<constraint not found>}"
if [ "$HAS" = "t" ]; then
  say "  ALREADY WIDENED — nothing to do."
else
  say "  widening it (DROP-then-ADD in one transaction; idempotent)…"
  sudo -u "$P_USER" psql -d "$P_DB" -v ON_ERROR_STOP=1 <<'SQL2'
BEGIN;
ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check;
ALTER TABLE notifications ADD  CONSTRAINT notifications_type_check CHECK (type IN (
    'message', 'connection_request', 'connection_accept',
    'forum.reply_to_topic', 'forum.reply_to_reply', 'forum.mention', 'reaction.on_post',
    'forum.followed_topic'
));
COMMIT;
SQL2
fi
say ""
say "  VERIFY (must contain forum.followed_topic):"
say "  $(pq "select pg_get_constraintdef(oid) from pg_constraint where conname='notifications_type_check';")"
say ""
say "BOTH STEPS DONE. Now smoke it through the real UI — see the runbook's"
say "verification section. Creating the objects proves the store exists, not that a"
say "member can follow a thread and actually hear about it."
