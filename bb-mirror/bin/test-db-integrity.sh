#!/usr/bin/env bash
#
# bb-mirror database-integrity tests — the delete paths and the mirror<->WordPress
# invariants. No browser engine, no serving database.
#
#   sudo -u looth-dev bin/test-db-integrity.sh
#
# Companion to bin/test-features.sh, which drives headless Chrome. These do not:
# they are pure DB/PHP and safe to run when no engine is available.
#
# WHAT THEY COVER (docs/atlas/MIRROR-ATTACHMENT-ORPHANS.md):
#   test-attachment-purge.php  the AFTER DELETE triggers that stand in for the
#                              CASCADE a polymorphic key cannot have. Six-image
#                              reply, topic cascade, and a negative control.
#   test-ghost-sweep.php       bb_mirror_sweep_ghosts(). Mostly asserts it does
#                              NOT delete: report-only, empty wp_posts, over-cap,
#                              and a ghost topic whose replies are still live.
#
# A GATE THAT CANNOT RUN IS LOUDER HERE THAN ONE THAT FAILS. A failing test is
# doing its job; a test that silently did not run is lying, and that is exactly
# how the craft gate stayed dead for weeks while everyone assumed it passed.
# CANNOT RUN exits 2 and says so in as many words. Failure exits 1.
set -uo pipefail
cd "$(dirname "$0")"

TEST_DB="${BBM_TEST_DB:-orphan_proof}"
FAILED=0
BLOCKED=0

hr(){ printf '%s\n' "----------------------------------------------------------------"; }

# ── Preflight. Anything wrong here is CANNOT RUN, never a pass. ──────────────
if ! command -v psql >/dev/null 2>&1; then
  echo "CANNOT RUN: psql not on PATH"; exit 2
fi
if ! psql -d "$TEST_DB" -Atc 'SELECT 1' >/dev/null 2>&1; then
  cat <<EOF
CANNOT RUN: cannot connect to scratch database '$TEST_DB' as $(whoami).

This is NOT a pass and NOT a failure — the tests did not execute.

These tests never touch the serving database. Create the scratch one once:
  sudo -u postgres  psql -c 'CREATE DATABASE $TEST_DB OWNER "looth-dev"'
  sudo -u looth-dev psql -d $TEST_DB -c 'CREATE SCHEMA forums'
  sudo -u looth-dev psql -d $TEST_DB -f ../schema.pg.sql

It must be OWNED BY looth-dev: test-ghost-sweep's negative control does DDL,
which needs table ownership rather than DML grants.
EOF
  exit 2
fi
TRIGGERS=$(psql -d "$TEST_DB" -Atc "SELECT count(*) FROM pg_trigger t
  JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
 WHERE NOT t.tgisinternal AND n.nspname='forums'
   AND t.tgname IN ('topic_attachment_purge','reply_attachment_purge');" 2>/dev/null)
if [ "$TRIGGERS" != "2" ]; then
  echo "CANNOT RUN: '$TEST_DB' has $TRIGGERS/2 purge triggers — apply ../schema.pg.sql to it first."
  echo "  (Without them every assertion would pass vacuously in the direction that matters least.)"
  exit 2
fi

echo "bb-mirror DB integrity — scratch database: $TEST_DB, user: $(whoami)"

for t in test-attachment-purge.php test-ghost-sweep.php; do
  hr; echo ">> $t"
  if [ ! -f "$t" ]; then
    echo "CANNOT RUN: $t is missing"; BLOCKED=1; continue
  fi
  php "$t"
  rc=$?
  case $rc in
    0) ;;
    2) echo ">> $t: CANNOT RUN (exit 2) — see its output above"; BLOCKED=1 ;;
    *) echo ">> $t: FAILED (exit $rc)"; FAILED=1 ;;
  esac
done

hr
# Blocked outranks failed in the summary, because "we do not know" is worse news
# than "we know it is broken".
if [ "$BLOCKED" = "1" ]; then
  echo "RESULT: CANNOT RUN — at least one test did not execute. This is not a pass."
  exit 2
fi
if [ "$FAILED" = "1" ]; then
  echo "RESULT: FAILED"
  exit 1
fi
echo "RESULT: PASS — all DB integrity tests green."
