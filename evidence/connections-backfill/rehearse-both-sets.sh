#!/usr/bin/env bash
# ============================================================
# Rehearses the COMBINATION Ian actually runs: set A (13/14/15, restore 271)
# followed by set B (10/11/12, correct 81), on one throwaway replica loaded with
# LIVE's own rows.
#
# rehearse-13-14-15.sh proves set A alone. This proves the two together:
#   * applying both leaves each tag table holding only its own rows
#   * the tag tables do not overlap, so the rollbacks stay independent
#   * rolling back ONE leaves the OTHER intact and correct
#   * POST-APPLY-CHECK.sql's "applied" branch actually runs
#
#   sudo -u postgres bash rehearse-both-sets.sh
# ============================================================
set -uo pipefail

DB=restore_rehearsal_both
SQL=/home/ubuntu/worktrees/connections-backfill/profile-app/sql
EV=/home/ubuntu/worktrees/connections-backfill/evidence/connections-backfill
DATA="${DATA:-/tmp/rehearse-data}"     # must be world-readable: this runs as postgres
A=connections_restore_20260728_all_acc
B=connections_restatus_20260728
PASS=0; FAIL=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1: $2"; PASS=$((PASS+1));
      else echo "  FAIL  $1: got '$2' expected '$3'"; FAIL=$((FAIL+1)); fi; }
q(){ psql -d $DB -Atc "$1" 2>&1; }

echo "=== replica from LIVE extracts ==="
psql -d postgres -qc "DROP DATABASE IF EXISTS $DB" >/dev/null
psql -d postgres -qc "CREATE DATABASE $DB" >/dev/null
psql -d $DB -q <<'DDL' >/dev/null
CREATE TABLE users (id bigint PRIMARY KEY, uuid uuid UNIQUE NOT NULL);
CREATE TABLE wp_user_bridge (user_id bigint NOT NULL REFERENCES users(id), wp_user_id bigint NOT NULL);
CREATE TABLE connections (
  id bigserial PRIMARY KEY,
  requester_uuid uuid NOT NULL REFERENCES users(uuid),
  addressee_uuid uuid NOT NULL REFERENCES users(uuid),
  status text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT connections_requester_uuid_addressee_uuid_key UNIQUE (requester_uuid, addressee_uuid),
  CONSTRAINT connections_check CHECK (requester_uuid <> addressee_uuid),
  CONSTRAINT connections_status_check CHECK (status = ANY (ARRAY['pending','accepted','blocked']))
);
CREATE FUNCTION touch_updated_at() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN NEW.updated_at = now(); RETURN NEW; END; $$;
CREATE TRIGGER connections_touch BEFORE UPDATE ON connections
  FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
DDL
psql -d $DB -qc "\copy users FROM '$DATA/L-users.csv' WITH (FORMAT csv)"           >/dev/null
psql -d $DB -qc "\copy wp_user_bridge FROM '$DATA/L-bridge.csv' WITH (FORMAT csv)" >/dev/null
psql -d $DB -qc "\copy connections (id,requester_uuid,addressee_uuid,status,created_at,updated_at) FROM '$DATA/L-conn.csv' WITH (FORMAT csv)" >/dev/null
psql -d $DB -qc "SELECT setval('connections_id_seq',(SELECT max(id) FROM connections))" >/dev/null
LOADED=$(q 'SELECT count(*) FROM connections')
if [ "${LOADED:-0}" -lt 10000 ]; then
  echo "  ABORT: replica has $LOADED rows -- is $DATA readable by postgres?"
  psql -d postgres -qc "DROP DATABASE IF EXISTS $DB" >/dev/null; exit 1
fi
BASEN=$LOADED
HASH="SELECT md5(string_agg(id||'|'||requester_uuid||'|'||addressee_uuid||'|'||status||'|'||created_at, E'\n' ORDER BY id)) FROM connections"
BASE=$(q "$HASH")
IANQ="SELECT count(*) FROM connections WHERE status='accepted' AND (requester_uuid='f20ad778-1e5e-5508-853b-ad928c499f2f' OR addressee_uuid='f20ad778-1e5e-5508-853b-ad928c499f2f')"
IAN0=$(q "$IANQ")
echo "  rows=$BASEN  Ian accepted=$IAN0"

echo
echo "=== apply A (restore 271) then B (correct 81), the real order ==="
psql -d $DB -q -f "$SQL/2026-07-28-connections-restore-all-14-APPLY.sql" >/dev/null 2>&1
psql -d $DB -q -f "$SQL/2026-07-28-connections-restatus-11-APPLY.sql"    >/dev/null 2>&1
ok "A tagged 271"            "$(q "SELECT count(*) FROM $A")" "271"
ok "B tagged 81"             "$(q "SELECT count(*) FROM $B")" "81"
ok "tag tables DISJOINT"     "$(q "SELECT count(*) FROM $A a JOIN $B b ON a.connection_id=b.connection_id")" "0"
ok "rows = base + 271"       "$(q 'SELECT count(*) FROM connections')" "$((BASEN+271))"
ok "all A rows accepted"     "$(q "SELECT count(*) FROM connections c JOIN $A t ON t.connection_id=c.id WHERE c.status<>'accepted'")" "0"
ok "all B rows accepted"     "$(q "SELECT count(*) FROM connections c JOIN $B t ON t.connection_id=c.id WHERE c.status<>'accepted'")" "0"
ok "Ian accepted +23"        "$(q "$IANQ")" "$((IAN0+23))"
ok "no NEW reciprocal dupes" "$(q 'SELECT count(*) FROM connections a JOIN connections b ON a.requester_uuid=b.addressee_uuid AND a.addressee_uuid=b.requester_uuid')" "10"

echo
echo "=== POST-APPLY-CHECK.sql applied branch ==="
OUT=$(psql -d $DB -f "$EV/POST-APPLY-CHECK.sql" 2>&1)
ok "reports A 271"           "$(grep -c 'rows tagged                      : 271' <<<"$OUT")" "1"
ok "reports B 81"            "$(grep -c 'rows tagged                      : 81'  <<<"$OUT")" "1"
ok "reports overlap 0"       "$(grep -c 'tag-table OVERLAP                : 0'   <<<"$OUT")" "1"
ok "reports no NOT-accepted" "$(grep -c 'NOT accepted         : 0'               <<<"$OUT")" "1"
ok "reports created_at kept" "$(grep -c 'NEW created_at: 0'                      <<<"$OUT")" "1"

echo
echo "=== rolling back ONE must not disturb the OTHER ==="
psql -d $DB -q -f "$SQL/2026-07-28-connections-restore-all-15-ROLLBACK.sql" >/dev/null 2>&1
ok "A gone"                  "$(q "SELECT to_regclass('public.$A') IS NULL")" "t"
ok "B STILL 81"              "$(q "SELECT count(*) FROM $B")" "81"
ok "B rows still accepted"   "$(q "SELECT count(*) FROM connections c JOIN $B t ON t.connection_id=c.id WHERE c.status<>'accepted'")" "0"
ok "Ian still +23"           "$(q "$IANQ")" "$((IAN0+23))"
ok "rows back to base"       "$(q 'SELECT count(*) FROM connections')" "$BASEN"

psql -d $DB -q -f "$SQL/2026-07-28-connections-restatus-12-ROLLBACK.sql" >/dev/null 2>&1
ok "B gone"                  "$(q "SELECT to_regclass('public.$B') IS NULL")" "t"
ok "Ian back to baseline"    "$(q "$IANQ")" "$IAN0"
# status round-trips exactly; updated_at cannot (connections_touch stamps now())
ok "status hash = baseline"  "$(q "$HASH")" "$BASE"

echo
psql -d postgres -qc "DROP DATABASE $DB" >/dev/null
echo "=== replica dropped ==="
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
