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
-- forum_subscription is. 'membership' is a dev2-ONLY role and is deliberately absent.
GRANT SELECT ON forums.topic_follow TO looth_ro;

COMMIT;
SQL_END

if [ "$APPLY" -eq 0 ]; then
  say "=== DRY RUN — nothing will be written. Step 1 SQL: ==="
  [ "$STEP1_NEEDED" -eq 1 ] && say "$SQL" || say "  (skipped — table already present)"
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
say "  ⚠️ 'membership' will NOT appear on live and that is CORRECT. dev2 has a"
say "     'membership' role that live does not have at all (checked pg_roles on both)."
say "     Its absence here is not a failure — do not go granting it."
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
