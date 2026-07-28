#!/usr/bin/env bash
# ============================================================
# Rehearsal harness for the accepted-only POPULATION restore (files 13/14/15).
#
# Builds a THROWAWAY replica on dev2 from extracts of LIVE's own connections /
# users / wp_user_bridge, with the real DDL (unique constraint, check
# constraints, FKs, connections_touch trigger). Rehearsing against dev2's own
# data would be a weaker test -- dev2 is not live, and the payload was built
# from live -- so the replica is loaded with live's rows instead.
#
# Runs the full matrix: dry run writes nothing, apply inserts, apply is
# idempotent, dry run verifies, rollback returns the table byte-identically,
# and every guard aborts (truncated / non-accepted / wrong database / unknown
# uuid / opposite-direction duplicate).
#
# Read-only with respect to live and to dev2's real profile_app. Drops its own
# database at the end.
#
#   sudo -u postgres bash rehearse-13-14-15.sh
# ============================================================
set -uo pipefail

DB=restore_rehearsal_1415
SQL=/home/ubuntu/worktrees/connections-backfill/profile-app/sql
# NOTE: this runs as `postgres`, so DATA must be world-readable. A 0700 scratch
# dir makes \copy fail and the replica loads EMPTY -- every assertion then
# "passes" against an empty table. Staged to plain /tmp with explicit 0755/0644.
DATA="${DATA:-/tmp/rehearse-data}"
TAG=connections_restore_20260728_all_acc
WORK=$(mktemp -d); trap 'rm -rf "$WORK"' EXIT
PASS=0; FAIL=0
ok(){ if [ "$2" = "$3" ]; then echo "  PASS  $1: $2"; PASS=$((PASS+1));
      else echo "  FAIL  $1: got '$2' expected '$3'"; FAIL=$((FAIL+1)); fi; }
q(){ psql -d $DB -Atc "$1" 2>&1; }

echo "=== building replica from LIVE extracts ==="
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
psql -d $DB -qc "\copy users FROM '$DATA/L-users.csv' WITH (FORMAT csv)"          >/dev/null
psql -d $DB -qc "\copy wp_user_bridge FROM '$DATA/L-bridge.csv' WITH (FORMAT csv)" >/dev/null
psql -d $DB -qc "\copy connections (id,requester_uuid,addressee_uuid,status,created_at,updated_at) FROM '$DATA/L-conn.csv' WITH (FORMAT csv)" >/dev/null
psql -d $DB -qc "SELECT setval('connections_id_seq', (SELECT max(id) FROM connections))" >/dev/null
echo "  users=$(q 'SELECT count(*) FROM users')  bridge=$(q 'SELECT count(*) FROM wp_user_bridge')  connections=$(q 'SELECT count(*) FROM connections')"

# An EMPTY replica passes almost every assertion below for the wrong reason --
# the dry run happily reports 271 will-insert against an empty table. Refuse to
# run unless the load actually landed.
LOADED=$(q 'SELECT count(*) FROM connections')
if [ "${LOADED:-0}" -lt 10000 ]; then
  echo "  ABORT: replica has $LOADED connections rows, expected ~10953."
  echo "         \\copy probably failed -- is $DATA readable by the postgres user?"
  psql -d postgres -qc "DROP DATABASE IF EXISTS $DB" >/dev/null; exit 1
fi

HASH="SELECT md5(string_agg(id||'|'||requester_uuid||'|'||addressee_uuid||'|'||status||'|'||created_at||'|'||updated_at, E'\n' ORDER BY id)) FROM connections"
BASE=$(q "$HASH"); BASEN=$(q "SELECT count(*) FROM connections")
DUPQ="SELECT count(*) FROM connections a JOIN connections b ON a.requester_uuid=b.addressee_uuid AND a.addressee_uuid=b.requester_uuid"
BASEDUP=$(q "$DUPQ")   # live carries 10 pre-existing (5 bidirectional pairs, the shape-(c) set)
echo "  baseline hash=$BASE  rows=$BASEN"

echo
echo "=== 1. 13-DRYRUN writes nothing ==="
OUT=$(psql -d $DB -f "$SQL/2026-07-28-connections-restore-all-13-DRYRUN.sql" 2>&1)
ok "WILL INSERT"            "$(sed -n 's/^ WILL INSERT (after guards) *| *//p' <<<"$OUT" | tr -d ' ')" "271"
ok "every row accepted"     "$(sed -n 's/^ every row is accepted *| *//p'      <<<"$OUT" | tr -d ' ')" "true"
ok "UUID MATCH"             "$(sed -n 's/^ UUID MATCH (must say true) *| *//p' <<<"$OUT" | tr -d ' ')" "true"
ok "uuids not in users"     "$(sed -n 's/^ payload uuids NOT found in users *| *//p' <<<"$OUT" | tr -d ' ')" "0"
ok "already present same"   "$(sed -n 's/^ already present, same direction *| *//p' <<<"$OUT" | tr -d ' ')" "0"
ok "already present opp"    "$(sed -n 's/^ already present, OPPOSITE dir *| *//p'    <<<"$OUT" | tr -d ' ')" "0"
ok "rows touching Ian"      "$(sed -n 's/^ rows touching Ian *| *//p'          <<<"$OUT" | tr -d ' ')" "0"
ok "table unchanged"        "$(q "$HASH")" "$BASE"

echo
echo "=== 2. 14-APPLY inserts 271 and tags them ==="
psql -d $DB -q -f "$SQL/2026-07-28-connections-restore-all-14-APPLY.sql" >/dev/null 2>&1
ok "tagged"                 "$(q "SELECT count(*) FROM $TAG")" "271"
ok "table grew by 271"      "$(q 'SELECT count(*) FROM connections')" "$((BASEN+271))"
ok "all inserted accepted"  "$(q "SELECT count(*) FROM connections c JOIN $TAG t ON t.connection_id=c.id WHERE c.status<>'accepted'")" "0"
ok "created_at preserved"   "$(q "SELECT count(*) FROM connections c JOIN $TAG t ON t.connection_id=c.id WHERE c.created_at > '2026-07-01'")" "0"
ok "no NEW reciprocal dupes" "$(q "$DUPQ")" "$BASEDUP"

echo
echo "=== 3. 14-APPLY again is idempotent ==="
psql -d $DB -q -f "$SQL/2026-07-28-connections-restore-all-14-APPLY.sql" >/dev/null 2>&1
ok "still 271 tagged"       "$(q "SELECT count(*) FROM $TAG")" "271"
ok "table still +271"       "$(q 'SELECT count(*) FROM connections')" "$((BASEN+271))"

echo
echo "=== 4. 13-DRYRUN as verify ==="
OUT=$(psql -d $DB -f "$SQL/2026-07-28-connections-restore-all-13-DRYRUN.sql" 2>&1)
ok "WILL INSERT now 0"      "$(sed -n 's/^ WILL INSERT (after guards) *| *//p' <<<"$OUT" | tr -d ' ')" "0"
ok "already present 271"    "$(sed -n 's/^ already present, same direction *| *//p' <<<"$OUT" | tr -d ' ')" "271"

echo
echo "=== 5. 15-ROLLBACK returns the table byte-identically ==="
psql -d $DB -q -f "$SQL/2026-07-28-connections-restore-all-15-ROLLBACK.sql" >/dev/null 2>&1
ok "rows back to baseline"  "$(q 'SELECT count(*) FROM connections')" "$BASEN"
ok "hash byte-identical"    "$(q "$HASH")" "$BASE"
ok "tag table dropped"      "$(q "SELECT to_regclass('public.$TAG') IS NULL")" "t"

echo
echo "=== 6. opposite-direction re-request is SKIPPED, not duplicated ==="
# plant the reverse of one payload pair as a pending row, exactly as a member
# re-requesting in the other direction would create.
R=$(head -2 "$DATA/payload271.tsv" | tail -1)
RU=$(cut -f4 <<<"$R"); AU=$(cut -f5 <<<"$R")
psql -d $DB -qc "INSERT INTO connections (requester_uuid,addressee_uuid,status) VALUES ('$AU','$RU','pending')" >/dev/null
psql -d $DB -q -f "$SQL/2026-07-28-connections-restore-all-14-APPLY.sql" >/dev/null 2>&1
ok "inserted 270 not 271"   "$(q "SELECT count(*) FROM $TAG")" "270"
ok "planted pair skipped"   "$(q "SELECT count(*) FROM connections WHERE requester_uuid='$RU' AND addressee_uuid='$AU'")" "0"
ok "no NEW reciprocal dupes" "$(q "$DUPQ")" "$BASEDUP"
psql -d $DB -q -f "$SQL/2026-07-28-connections-restore-all-15-ROLLBACK.sql" >/dev/null 2>&1
psql -d $DB -qc "DELETE FROM connections WHERE requester_uuid='$AU' AND addressee_uuid='$RU' AND status='pending'" >/dev/null
ok "back to baseline"       "$(q "$HASH")" "$BASE"

echo
echo "=== 7. guards abort and change nothing ==="
# truncated paste
sed '0,/^  ('"'"'/{/^  ('"'"'/d}' "$SQL/2026-07-28-connections-restore-all-14-APPLY.sql" > "$WORK/trunc.sql"
E=$(psql -d $DB -f "$WORK/trunc.sql" 2>&1)
ok "truncated aborts"       "$(grep -c 'file is truncated, ABORTING' <<<"$E")" "1"
ok "  unchanged"            "$(q "$HASH")" "$BASE"
ok "  no tag table"         "$(q "SELECT to_regclass('public.$TAG') IS NULL")" "t"
# a non-accepted row smuggled in
sed "0,/,'accepted',/s//,'pending',/" "$SQL/2026-07-28-connections-restore-all-14-APPLY.sql" > "$WORK/pend.sql"
E=$(psql -d $DB -f "$WORK/pend.sql" 2>&1)
ok "non-accepted aborts"    "$(grep -c 'in an ACCEPTED-ONLY file' <<<"$E")" "1"
ok "  unchanged"            "$(q "$HASH")" "$BASE"
# wrong database: point the bridge at a different uuid for wp_user_id=1
OLD=$(q "SELECT u.id FROM users u JOIN wp_user_bridge b ON b.user_id=u.id WHERE b.wp_user_id=1")
NEW=$(q "SELECT id FROM users WHERE id<>$OLD ORDER BY id LIMIT 1")
psql -d $DB -qc "UPDATE wp_user_bridge SET user_id=$NEW WHERE wp_user_id=1" >/dev/null
E=$(psql -d $DB -f "$SQL/2026-07-28-connections-restore-all-14-APPLY.sql" 2>&1)
ok "wrong database aborts"  "$(grep -c 'wrong database, ABORTING' <<<"$E")" "1"
ok "  unchanged"            "$(q "$HASH")" "$BASE"
psql -d $DB -qc "UPDATE wp_user_bridge SET user_id=$OLD WHERE wp_user_id=1" >/dev/null
# an unknown uuid in the payload -- rewrite the first payload row's requester
python3 - "$SQL/2026-07-28-connections-restore-all-14-APPLY.sql" "$WORK/unk.sql" <<'PY'
import re,sys
src,dst=sys.argv[1],sys.argv[2]
out=[];done=False
for line in open(src):
    if not done and line.startswith("  ('") and "'accepted'" in line:
        line=re.sub(r"^  \('[0-9a-f-]+'::uuid",
                    "  ('00000000-0000-5000-8000-000000000000'::uuid",line)
        done=True
    out.append(line)
assert done, "no payload row matched"
open(dst,'w').writelines(out)
PY
E=$(psql -d $DB -f "$WORK/unk.sql" 2>&1)
ok "unknown uuid aborts"    "$(grep -cE 'do not exist in users|violates foreign key' <<<"$E")" "1"
ok "  unchanged"            "$(q "$HASH")" "$BASE"
ok "  no tag table"         "$(q "SELECT to_regclass('public.$TAG') IS NULL")" "t"

echo
psql -d postgres -qc "DROP DATABASE $DB" >/dev/null
echo "=== replica dropped ==="
echo "PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
