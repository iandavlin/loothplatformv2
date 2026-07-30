#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# backfill-profile-app-emails-dryrun.sh
#
# Audit §7 item 5. DRY RUN ONLY — this script issues SELECTs and NOTHING else.
# It never writes to any database. It prints the drift report and then emits the
# APPLY and ROLLBACK statements for Ian to run by hand. All live writes are his.
#
# WHY THIS EXISTS
#   profile-sync.php hooks `user_register` only, never `profile_update`, so a WP
#   email change is never forwarded to profile-app. The receiver
#   (Provision::applyEmailChange) exists and is correct but has no caller, and on
#   live nginx there is no route for it either. Consequence: members whose email
#   moved carry the OLD address as profile-app `primary_email`. This backfills
#   what that hook would have sent — and only that.
#
# WHAT IT MIRRORS
#   Provision::applyEmailChange (profile-app/src/Provision.php:339):
#     1. users.uuid is NEVER touched — identity stays stable, JWT `sub` holds.
#     2. UPDATE users SET primary_email = <new>  — but ONLY when no other row
#        already holds that address (primary_email is UNIQUE NOT NULL).
#     3. INSERT the new address into email_aliases, re-pointing on conflict.
#        The old address stays an alias, which is the intended history.
#
# USAGE   ./backfill-profile-app-emails-dryrun.sh          (run on live, or via
#                                                           ssh live-ro 'bash -s')
# ---------------------------------------------------------------------------
set -euo pipefail

MYSQL=(mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import)
PSQL=(psql -h 127.0.0.1 -U looth_ro -d profile_app -A -F'|' -t)

# --- guard: refuse to run anywhere but live -------------------------------
SITEURL="$("${MYSQL[@]}" -e "SELECT option_value FROM wp_options WHERE option_name='siteurl';" 2>/dev/null || true)"
if [[ "$SITEURL" != "https://loothgroup.com" ]]; then
    echo "REFUSING: siteurl is '${SITEURL:-<unreadable>}', expected https://loothgroup.com."
    echo "This report is only meaningful against live data."
    exit 1
fi

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

# --- read-only pulls -------------------------------------------------------
"${MYSQL[@]}" -e "SELECT ID, LOWER(TRIM(user_email)) FROM wp_users ORDER BY ID;" \
    | tr '\t' '|' > "$TMP/wp.txt"

"${PSQL[@]}" -c "SELECT b.wp_user_id, u.id, u.uuid, lower(trim(u.primary_email))
                 FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
                 ORDER BY b.wp_user_id;" > "$TMP/pa.txt"

# every address currently used as a primary_email, and every alias — so the
# report can tell a clean move from one that would break the UNIQUE constraint
"${PSQL[@]}" -c "SELECT lower(trim(primary_email)), id,
                        CASE WHEN archived_at IS NULL THEN 'live' ELSE 'archived' END,
                        COALESCE(slug,'')
                 FROM users;" > "$TMP/primaries.txt"
"${PSQL[@]}" -c "SELECT email_normalized, user_id FROM email_aliases;" > "$TMP/aliases.txt"
"${PSQL[@]}" -c "SELECT u.id FROM users u LEFT JOIN wp_user_bridge b ON b.user_id=u.id
                 WHERE b.wp_user_id IS NULL;" > "$TMP/unbridged.txt"

python3 - "$TMP" <<'PY'
import sys, os
T = sys.argv[1]

def rows(name):
    out = []
    with open(os.path.join(T, name)) as fh:
        for line in fh:
            line = line.rstrip("\n")
            if line.strip():
                out.append(line.split("|"))
    return out

wp        = {int(r[0]): (r[1] if len(r) > 1 else "") for r in rows("wp.txt")}
pa        = {int(r[0]): (int(r[1]), r[2], r[3]) for r in rows("pa.txt")}
primaries = {}
for e, uid, state, slug in rows("primaries.txt"):
    primaries[e] = (int(uid), state, slug)
aliases   = {r[0]: int(r[1]) for r in rows("aliases.txt")}
unbridged = {int(r[0]) for r in rows("unbridged.txt")}

clean, conflicted, placeholder, blank = [], [], [], []
for wp_id, (uid, uuid, old) in sorted(pa.items()):
    new = wp.get(wp_id, "")
    if not new:
        if old != new:
            blank.append((wp_id, uid, uuid, old, new))
        continue
    if old == new:
        continue
    if old.endswith("@invalid"):
        placeholder.append((wp_id, uid, uuid, old, new))
        continue
    owner = primaries.get(new)
    rec = (wp_id, uid, uuid, old, new, owner, aliases.get(new))
    (conflicted if owner and owner[0] != uid else clean).append(rec)

def hdr(t): print("\n" + t + "\n" + "-" * len(t))

print("=" * 100)
print("PROFILE-APP EMAIL BACKFILL — DRY RUN.  NOTHING WAS WRITTEN.")
print("=" * 100)
print(f"bridged pairs compared : {len(pa)}")
print(f"genuine drift          : {len(clean) + len(conflicted)}   "
      f"({len(clean)} clean, {len(conflicted)} blocked by a UNIQUE conflict)")
print(f"out of scope           : {len(placeholder)} legacy looth-N@invalid placeholders, "
      f"{len(blank)} with a blank WP email")

hdr("A. CLEAN — profile-app primary_email moves to the WP address")
print(f"{'wp':>5}  {'pg':>5}  {'uuid':36}  {'old primary_email':38}  new primary_email")
for wp_id, uid, uuid, old, new, _o, _a in clean:
    print(f"{wp_id:>5}  {uid:>5}  {uuid:36}  {old:38}  {new}")

hdr("B. BLOCKED — the WP address is already primary on ANOTHER profile-app row")
if not conflicted:
    print("  (none)")
for wp_id, uid, uuid, old, new, owner, _a in conflicted:
    oid, state, slug = owner
    tag = "unbridged" if oid in unbridged else "BRIDGED"
    print(f"{wp_id:>5}  {uid:>5}  {uuid:36}  {old:38}  {new}")
    print(f"        blocked by pg id {oid} ({state}, {tag}, slug='{slug}')")
print("\n  primary_email is UNIQUE NOT NULL, so these CANNOT take the new address")
print("  until the blocking row releases it. applyEmailChange() handles exactly")
print("  this case by keeping primary_email as-is and re-pointing the alias only —")
print("  option B1 below does the same. B2 is the fuller repair, Ian's call.")

hdr("APPLY — option A (the clean ones). Run inside a transaction.")
print("BEGIN;")
for wp_id, uid, uuid, old, new, _o, _a in clean:
    print(f"-- wp#{wp_id}  {old} -> {new}")
    print(f"UPDATE users SET primary_email = '{new}' WHERE id = {uid} AND primary_email = '{old}';")
    print(f"INSERT INTO email_aliases (email_normalized, user_id, source) VALUES ('{new}', {uid}, 'wp')")
    print(f"  ON CONFLICT (email_normalized) DO UPDATE SET user_id = EXCLUDED.user_id;")
print("-- verify, then COMMIT (or ROLLBACK if the counts look wrong):")
print(f"SELECT count(*) FROM users WHERE id IN ({','.join(str(r[1]) for r in clean)}) "
      f"AND primary_email IN ({','.join(chr(39)+r[4]+chr(39) for r in clean)});"
      f"  -- expect {len(clean)}")
print("COMMIT;")

hdr("ROLLBACK — option A")
print("BEGIN;")
for wp_id, uid, uuid, old, new, _o, alias_owner in clean:
    print(f"-- wp#{wp_id}  revert {new} -> {old}")
    print(f"UPDATE users SET primary_email = '{old}' WHERE id = {uid} AND primary_email = '{new}';")
    if alias_owner is None:
        print(f"DELETE FROM email_aliases WHERE email_normalized = '{new}' AND user_id = {uid};")
    else:
        print(f"UPDATE email_aliases SET user_id = {alias_owner} WHERE email_normalized = '{new}';")
print("COMMIT;")

hdr("APPLY — option B1 (blocked rows, applyEmailChange-faithful: alias only)")
print("BEGIN;")
for wp_id, uid, uuid, old, new, owner, _a in conflicted:
    print(f"-- wp#{wp_id}  primary stays '{old}' (blocked by pg id {owner[0]}); alias added")
    print(f"INSERT INTO email_aliases (email_normalized, user_id, source) VALUES ('{new}', {uid}, 'wp')")
    print(f"  ON CONFLICT (email_normalized) DO UPDATE SET user_id = EXCLUDED.user_id;")
print("COMMIT;")
print("\n-- ROLLBACK for B1:")
print("BEGIN;")
for wp_id, uid, uuid, old, new, owner, alias_owner in conflicted:
    if alias_owner is None:
        print(f"DELETE FROM email_aliases WHERE email_normalized = '{new}' AND user_id = {uid};")
    else:
        print(f"UPDATE email_aliases SET user_id = {alias_owner} WHERE email_normalized = '{new}';")
print("COMMIT;")

hdr("APPLY — option B2 (fuller repair: free the address, then move it)")
print("-- Only for blockers that are ARCHIVED + UNBRIDGED orphans. Parks the")
print("-- orphan on a placeholder so nothing is deleted and B2 stays reversible.")
print("-- NOTE the prefix: the legacy convention is looth-<WP id>@invalid and those")
print("-- already run up past looth-1890@invalid, which OVERLAPS the pg-id range")
print("-- used here. 'looth-orphan-<pg id>@invalid' cannot collide with it.")
print("BEGIN;")
for wp_id, uid, uuid, old, new, owner, _a in conflicted:
    oid, state, slug = owner
    if state != "archived" or oid not in unbridged:
        print(f"-- wp#{wp_id}: SKIP — blocker pg id {oid} is {state}/"
              f"{'unbridged' if oid in unbridged else 'BRIDGED'}, not a safe orphan. Escalate.")
        continue
    park = f"looth-orphan-{oid}@invalid"
    if park in primaries:
        print(f"-- wp#{wp_id}: SKIP — placeholder '{park}' is ALREADY IN USE "
              f"(pg id {primaries[park][0]}). Escalate rather than guess.")
        continue
    print(f"-- wp#{wp_id}: free '{new}' from archived orphan pg id {oid} (slug='{slug}')")
    print(f"UPDATE users SET primary_email = '{park}' WHERE id = {oid} AND archived_at IS NOT NULL;")
    print(f"UPDATE users SET primary_email = '{new}' WHERE id = {uid} AND primary_email = '{old}';")
    print(f"INSERT INTO email_aliases (email_normalized, user_id, source) VALUES ('{new}', {uid}, 'wp')")
    print(f"  ON CONFLICT (email_normalized) DO UPDATE SET user_id = EXCLUDED.user_id;")
print("COMMIT;")
print("\n-- ROLLBACK for B2:")
print("BEGIN;")
for wp_id, uid, uuid, old, new, owner, alias_owner in conflicted:
    oid, state, slug = owner
    if state != "archived" or oid not in unbridged:
        continue
    print(f"UPDATE users SET primary_email = '{old}' WHERE id = {uid} AND primary_email = '{new}';")
    if alias_owner is None:
        print(f"DELETE FROM email_aliases WHERE email_normalized = '{new}' AND user_id = {uid};")
    else:
        print(f"UPDATE email_aliases SET user_id = {alias_owner} WHERE email_normalized = '{new}';")
    print(f"UPDATE users SET primary_email = '{new}' WHERE id = {oid};")
print("COMMIT;")

print("\n" + "=" * 100)
print("END OF DRY RUN — no database was modified by this script.")
print("=" * 100)
PY
