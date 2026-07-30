#!/usr/bin/env bash
# topic-follow-migrate.sh — create forums.topic_follow (the 🔔 per-thread follow bit).
#
# Runbook: docs/runbooks/live-topic-follow-migration.md — READ IT FIRST.
#
# DRY RUN BY DEFAULT, like cutover/symlink-farm.sh. Pass --apply to write.
# Additive and idempotent: CREATE TABLE IF NOT EXISTS + CREATE INDEX IF NOT EXISTS
# + one GRANT. It drops nothing and alters no existing table.
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
say "=== BEFORE ==="
BEFORE="$(q "select coalesce(to_regclass('forums.topic_follow')::text,'MISSING');")"
say "  forums.topic_follow : $BEFORE"
if [ "$BEFORE" != "MISSING" ]; then
  ROWS="$(q "select count(*) from forums.topic_follow;")"
  say "  rows                : $ROWS"
  say ""
  say "ALREADY PRESENT — nothing to do. This script is idempotent; re-running is safe"
  say "but pointless. If you expected it missing, you are on the wrong box."
  exit 0
fi
say "  (absent — the migration below will create it)"
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

-- looth-dev / profile-app / membership arrive via this schema's DEFAULT PRIVILEGES.
-- looth_ro does NOT — it is granted per-table, exactly as forum_subscription is
-- (verified on live 2026-07-30: forum_subscription grants looth_ro:SELECT).
GRANT SELECT ON forums.topic_follow TO looth_ro;

COMMIT;
SQL_END

if [ "$APPLY" -eq 0 ]; then
  say "=== DRY RUN — nothing was written. This is the SQL --apply would run: ==="
  say "$SQL"
  say ""
  say "Re-run with --apply to execute it."
  exit 0
fi

say "=== APPLYING ==="
printf '%s\n' "$SQL" | sudo -u "$PSQL_USER" psql -d "$DB" -v ON_ERROR_STOP=1

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
say "EXPECTED, matching dev2:"
say "  columns  user_id bigint, topic_id bigint, created_at timestamp with time zone"
say "  indexes  topic_follow_pkey, idx_topic_follow_topic"
say "  grants   MUST include looth_ro:SELECT and looth-dev's INSERT/SELECT/UPDATE/DELETE."
say "           If looth-dev is absent, the schema's DEFAULT PRIVILEGES did not apply —"
say "           the API could then read but not write. Say so; do not 'fix' it by"
say "           granting ad hoc without checking why the default did not fire."
say ""
say "DONE. Now smoke it through the real UI — see the runbook's verification section."
