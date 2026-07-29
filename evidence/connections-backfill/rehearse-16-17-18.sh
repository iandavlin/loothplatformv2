#!/usr/bin/env bash
# ============================================================
# Rehearsal for the IAN-ONLY wrong-status canary (files 16/17/18).
#
# Throwaway replica on dev2 loaded with LIVE's connections / users /
# wp_user_bridge and the real DDL. Proves the canary alone, its guards, and --
# the part that only matters for a canary -- that running it FIRST does not
# break the full 81-row set (files 10/11/12) that follows it. The canary's 23
# are a strict SUBSET of that 81.
#
#   sudo -u postgres bash rehearse-16-17-18.sh
# (DATA must be world-readable: this runs as postgres.)
# ============================================================
set -uo pipefail

DB=restore_rehearsal_ian23
SQL=/home/ubuntu/worktrees/connections-backfill/profile-app/sql
DATA="${DATA:-/tmp/rehearse-data}"
TAG=connections_restatus_20260729_ian_only
TAG81=connections_restatus_20260728
IAN=f20ad778-1e5e-5508-853b-ad928c499f2f
WORK=$(mktemp -d); trap 'rm -rf "$WORK"' EXIT
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
# status-only hash: updated_at CANNOT round-trip through an UPDATE (connections_touch)
HASH="SELECT md5(string_agg(id||'|'||requester_uuid||'|'||addressee_uuid||'|'||status||'|'||created_at, E'\n' ORDER BY id)) FROM connections"
BASE=$(q "$HASH")
IANACC="SELECT count(*) FROM connections WHERE status='accepted' AND (requester_uuid='$IAN' OR addressee_uuid='$IAN')"
IANOUT="SELECT count(*) FROM connections WHERE status='pending' AND requester_uuid='$IAN'"
IANIN="SELECT count(*) FROM connections WHERE status='pending' AND addressee_uuid='$IAN'"
A0=$(q "$IANACC"); O0=$(q "$IANOUT"); I0=$(q "$IANIN")
echo "  rows=$LOADED  Ian accepted=$A0 pendingOUT=$O0 pendingIN=$I0"

echo
echo "=== 1. 16-DRYRUN writes nothing ==="
OUT=$(psql -d $DB -f "$SQL/2026-07-29-connections-restatus-ian-16-DRYRUN.sql" 2>&1)
ok "pairs in canary"     "$(sed -n 's/^ pairs in this canary *| *//p'          <<<"$OUT" | tr -d ' ')" "23"
ok "UUID MATCH"          "$(sed -n 's/^ UUID MATCH (must say true) *| *//p'    <<<"$OUT" | tr -d ' ')" "true"
ok "every pair is Ian's" "$(sed -n 's/^ every pair touches Ian *| *//p'        <<<"$OUT" | tr -d ' ')" "true"
ok "WILL FLIP"           "$(sed -n 's/^ WILL FLIP pending -> accepted *| *//p' <<<"$OUT" | tr -d ' ')" "23"
ok "gone since measure"  "$(sed -n 's/^ gone since measurement *| *//p'        <<<"$OUT" | tr -d ' ')" "0"
ok "table unchanged"     "$(q "$HASH")" "$BASE"

echo
echo "=== 2. 17-APPLY flips 23 and records prior_status ==="
psql -d $DB -q -f "$SQL/2026-07-29-connections-restatus-ian-17-APPLY.sql" >/dev/null 2>&1
ok "tagged 23"           "$(q "SELECT count(*) FROM $TAG")" "23"
ok "prior_status recorded" "$(q "SELECT count(*) FROM $TAG WHERE prior_status='pending'")" "23"
ok "all now accepted"    "$(q "SELECT count(*) FROM connections c JOIN $TAG t ON t.connection_id=c.id WHERE c.status<>'accepted'")" "0"
ok "Ian accepted +23"    "$(q "$IANACC")" "$((A0+23))"
ok "Ian pendingOUT -23"  "$(q "$IANOUT")" "$((O0-23))"
ok "Ian pendingIN same"  "$(q "$IANIN")"  "$I0"

echo
echo "=== 3. 17-APPLY again is idempotent ==="
psql -d $DB -q -f "$SQL/2026-07-29-connections-restatus-ian-17-APPLY.sql" >/dev/null 2>&1
ok "still 23"            "$(q "SELECT count(*) FROM $TAG")" "23"
ok "Ian accepted stable" "$(q "$IANACC")" "$((A0+23))"

echo
echo "=== 4. 16-DRYRUN as verify ==="
OUT=$(psql -d $DB -f "$SQL/2026-07-29-connections-restatus-ian-16-DRYRUN.sql" 2>&1)
ok "WILL FLIP now 0"     "$(sed -n 's/^ WILL FLIP pending -> accepted *| *//p' <<<"$OUT" | tr -d ' ')" "0"
ok "already accepted 23" "$(sed -n 's/^ already accepted (skipped) *| *//p'    <<<"$OUT" | tr -d ' ')" "23"

echo
echo "=== 5. the canary does NOT break the 81 that follows it ==="
psql -d $DB -q -f "$SQL/2026-07-28-connections-restatus-11-APPLY.sql" >/dev/null 2>&1
ok "81-set flips only 58 more" "$(q "SELECT count(*) FROM $TAG81")" "58"
ok "tag tables DISJOINT"       "$(q "SELECT count(*) FROM $TAG a JOIN $TAG81 b ON a.connection_id=b.connection_id")" "0"
ok "Ian accepted still +23"    "$(q "$IANACC")" "$((A0+23))"
# undo the 81 leg so the canary rollback is tested in isolation
psql -d $DB -q -f "$SQL/2026-07-28-connections-restatus-12-ROLLBACK.sql" >/dev/null 2>&1
ok "81 reverted, canary intact" "$(q "SELECT count(*) FROM $TAG")" "23"
ok "Ian accepted still +23"     "$(q "$IANACC")" "$((A0+23))"

echo
echo "=== 6. 18-ROLLBACK restores the PRIOR STATUS exactly ==="
psql -d $DB -q -f "$SQL/2026-07-29-connections-restatus-ian-18-ROLLBACK.sql" >/dev/null 2>&1
ok "Ian accepted back"   "$(q "$IANACC")" "$A0"
ok "Ian pendingOUT back" "$(q "$IANOUT")" "$O0"
ok "tag table dropped"   "$(q "SELECT to_regclass('public.$TAG') IS NULL")" "t"
ok "status hash = baseline" "$(q "$HASH")" "$BASE"

echo
echo "=== 7. guards abort and change nothing ==="
sed '0,/^  ('"'"'/{/^  ('"'"'/d}' "$SQL/2026-07-29-connections-restatus-ian-17-APPLY.sql" > "$WORK/trunc.sql"
E=$(psql -d $DB -f "$WORK/trunc.sql" 2>&1)
ok "truncated aborts"    "$(grep -c 'file is truncated, ABORTING' <<<"$E")" "1"
ok "  unchanged"         "$(q "$HASH")" "$BASE"
# a pair that is not Ian's, smuggled in
python3 - "$SQL/2026-07-29-connections-restatus-ian-17-APPLY.sql" "$WORK/notian.sql" <<'PY'
import re,sys
src,dst=sys.argv[1],sys.argv[2]
out=[];done=False
for line in open(src):
    if not done and line.startswith("  ('f20ad778"):
        line=re.sub(r"^  \('f20ad778-[0-9a-f-]+'::uuid",
                    "  ('502849b6-cccb-5e29-b1b2-1691436e3c4d'::uuid",line); done=True
    out.append(line)
assert done
open(dst,'w').writelines(out)
PY
E=$(psql -d $DB -f "$WORK/notian.sql" 2>&1)
ok "non-Ian pair aborts"  "$(grep -c 'do not touch Ian' <<<"$E")" "1"
ok "  unchanged"          "$(q "$HASH")" "$BASE"
ok "  no tag table"       "$(q "SELECT to_regclass('public.$TAG') IS NULL")" "t"
# wrong database
OLD=$(q "SELECT u.id FROM users u JOIN wp_user_bridge b ON b.user_id=u.id WHERE b.wp_user_id=1")
NEW=$(q "SELECT id FROM users WHERE id<>$OLD ORDER BY id LIMIT 1")
psql -d $DB -qc "UPDATE wp_user_bridge SET user_id=$NEW WHERE wp_user_id=1" >/dev/null
E=$(psql -d $DB -f "$SQL/2026-07-29-connections-restatus-ian-17-APPLY.sql" 2>&1)
ok "wrong database aborts" "$(grep -c 'wrong database, ABORTING' <<<"$E")" "1"
ok "  unchanged"           "$(q "$HASH")" "$BASE"
psql -d $DB -qc "UPDATE wp_user_bridge SET user_id=$OLD WHERE wp_user_id=1" >/dev/null

echo
psql -d postgres -qc "DROP DATABASE $DB" >/dev/null
echo "=== replica dropped ==="
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
