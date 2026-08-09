#!/bin/bash
# notif-endpoint-gate.sh — the DELETE endpoint honours the flag, per state.
#
#     bash tools/gates/notif-endpoint-gate.sh
#
# notif-bridge lane, 2026-08-09.
#
# ── THE GAP THIS CLOSES ─────────────────────────────────────────────────────
# notif-dismiss-proof.php proves the MODEL: delete() destroys, dismiss() keeps. But
# when Ian flips `dismiss_instead_of_delete` on live, the thing that runs is the
# ENDPOINT — me-notifications.php's DELETE branch — and nothing exercised that. A
# mis-wired branch there means he flips the flag and gets the old destructive
# behaviour (or a 500) on a live bell, which is the worst available outcome for the
# one deliverable that is waiting on him.
#
# ── IT FLIPS A TRACKED FILE, WHICH NEEDS SAYING OUT LOUD ───────────────────
# The flag lives in profile-app/config/notifications.php by design (no env reaches
# every context). Testing the ON state therefore means editing a tracked file. That
# is done as a SNAPSHOT-AND-RESTORE — copy aside, restore under a trap, and assert
# the md5 matches afterwards — which is precisely what
# feedback-mutation-harness-must-snapshot-not-checkout prescribes. It never runs
# `git checkout --`, which would wipe uncommitted work in the tree under test.
#
# Exit 0 = green, 1 = RED, 2 = CANNOT RUN.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CFG="$ROOT/profile-app/config/notifications.php"
RUNNER="$ROOT/tools/gates/lib/notif-endpoint-request.php"
fail=0; did=0
ok() { did=$((did+1)); if [ "$1" = "1" ]; then echo "  PASS  $2"; else fail=$((fail+1)); echo "  RED   $2${3:+  — $3}"; fi; }
phase() { echo; echo "=== $1 ==="; }

[ -r "$CFG" ] || { echo "CANNOT RUN: no $CFG"; exit 2; }
[ -r "$RUNNER" ] || { echo "CANNOT RUN: no $RUNNER"; exit 2; }
sudo -n -u profile-app true 2>/dev/null || { echo "CANNOT RUN: no passwordless sudo to profile-app"; exit 2; }

# ── ⚠️ REFUSE TO START FROM AN UNEXPECTED STATE ────────────────────────────
# The restore below runs under a `trap`, and a trap does not run when the BOX DIES.
# dev2 reboots routinely (Ian, 8/8), so "interrupted mid-flip" is a real state, not a
# hypothetical — and it leaves a MEMBER-FACING FLAG SET TO true IN A TRACKED FILE.
# Commit that by accident and the feature ships on by default, which is the one thing
# the flag discipline exists to prevent.
#
# So: compare against git HEAD before touching anything. That catches the crashed-run
# leftover AND stops this gate clobbering someone's deliberate in-progress edit —
# flipping-and-restoring on top of an unexpected state is wrong either way. It reports
# CANNOT RUN rather than RED, because a dirty tree is a missing environment, not a
# finding.
#
# (Belt: notif-dismiss-proof.php phase 6 also asserts the shipped default is false, so
# a leftover flip goes RED in gate 21 even if nobody runs this gate again.)
if git -C "$ROOT" rev-parse --git-dir >/dev/null 2>&1; then
  if ! git -C "$ROOT" diff --quiet -- "$CFG" 2>/dev/null; then
    echo "CANNOT RUN: $CFG differs from git HEAD."
    echo "  If a previous run was interrupted (a reboot skips the restore trap), the"
    echo "  flag may be left flipped. Inspect, then: git checkout -- $CFG"
    git -C "$ROOT" diff -- "$CFG" | sed 's/^/    /' | head -12
    exit 2
  fi
fi

SNAP="$(mktemp)"; cp -p "$CFG" "$SNAP"
SNAP_MD5="$(md5sum "$CFG" | cut -d' ' -f1)"
restore() { cp -p "$SNAP" "$CFG"; rm -f "$SNAP"; }
trap restore EXIT

pgapp() { sudo -n -u profile-app psql -d profile_app -tAc "$1" 2>/dev/null; }

# Does this database carry the migration? Without it the ON case cannot be exercised
# and reporting RED would be reporting a missing environment as a finding.
HASCOL=$(pgapp "SELECT count(*) FROM information_schema.columns WHERE table_name='notifications' AND column_name='dismissed_at';" | tr -d ' ')

WPID=$(pgapp "SELECT b.wp_user_id FROM wp_user_bridge b JOIN users u ON u.id=b.user_id ORDER BY b.wp_user_id LIMIT 1;" | tr -d ' ')
[ -n "${WPID:-}" ] || { echo "CANNOT RUN: no bridged member"; exit 2; }
UUID=$(pgapp "SELECT u.uuid FROM users u JOIN wp_user_bridge b ON b.user_id=u.id WHERE b.wp_user_id=$WPID;" | tr -d ' ')

TOKEN=$(sudo -n -u profile-app php "$ROOT/profile-app/bin/mint-dev-token.php" "$WPID" 2>/dev/null | tr -d '[:space:]')
[ -n "${TOKEN:-}" ] || { echo "CANNOT RUN: could not mint a looth_id for wp:$WPID"; exit 2; }
echo "member wp:$WPID  migration_applied=$([ "$HASCOL" = "1" ] && echo yes || echo NO)  token=${#TOKEN} bytes"

TARGET=991001
seed() {  # -> prints the new row id
  pgapp "DELETE FROM notifications WHERE user_uuid='$UUID' AND target_id=$TARGET;" >/dev/null
  pgapp "INSERT INTO notifications (user_uuid, type, target_kind, target_id, target_url)
         VALUES ('$UUID','forum.reply_to_topic','topic',$TARGET,'/hub/?topic=proof/endpoint')
         RETURNING id;" | grep -E '^[0-9]+$' | tail -1
  # psql prints its "INSERT 0 1" status line alongside the RETURNING value, so an
  # unfiltered capture yields a MULTI-LINE id that silently becomes 0 downstream.
  # Same shape as `wp db query`'s trailing blank line — filter to digits, always.
}
row_state() {  # -> gone | present | dismissed
  local n d
  n=$(pgapp "SELECT count(*) FROM notifications WHERE id=$1;" | tr -d ' ')
  [ "$n" = "0" ] && { echo gone; return; }
  d=$(pgapp "SELECT dismissed_at IS NOT NULL FROM notifications WHERE id=$1;" | tr -d ' ')
  [ "$d" = "t" ] && echo dismissed || echo present
}
set_flag() { sed -i "s/'dismiss_instead_of_delete' => .*/'dismiss_instead_of_delete' => $1,/" "$CFG"; }
call() { sudo -n -u profile-app php "$RUNNER" "$ROOT" "$TOKEN" DELETE "$1" 2>/dev/null; }

# ── PHASE 0 — the runner reaches a REAL authenticated endpoint ──────────────
phase 'PHASE 0 — liveness: the runner is really executing the endpoint'
# Every assertion below reads a JSON body. If the runner silently produced nothing —
# bad token, wrong path, a fatal — the string comparisons would all fail in ways that
# look like product defects. So prove the happy path first.
out=$(sudo -n -u profile-app php "$RUNNER" "$ROOT" "$TOKEN" GET "" 2>/dev/null)
ok "$(echo "$out" | grep -q '"unread"' && echo 1 || echo 0)" \
   "GET returns the bell payload (auth + bootstrap + CSRF guard all passed)" "$(echo "$out" | head -c 120)"
bad=$(sudo -n -u profile-app php "$RUNNER" "$ROOT" "not-a-real-token" GET "" 2>/dev/null)
ok "$(echo "$bad" | grep -q 'auth_required' && echo 1 || echo 0)" \
   "…and a bogus token is REFUSED, so the auth above was earned" "$(echo "$bad" | head -c 80)"

# ── PHASE 1 — flag OFF: the endpoint really deletes ─────────────────────────
phase 'PHASE 1 — flag OFF: DELETE ?id= destroys the row (today, unchanged)'
set_flag false
id=$(seed)
ok "$([ -n "$id" ] && echo 1 || echo 0)" "seeded row id=$id"
resp=$(call "id=$id")
ok "$(echo "$resp" | grep -q '"ok":true' && echo 1 || echo 0)" "endpoint answered {\"ok\":true}" "$resp"
st=$(row_state "$id")
ok "$([ "$st" = "gone" ] && echo 1 || echo 0)" "the row is GONE" "state=$st"

# ── PHASE 2 — flag ON: the same call dismisses instead ──────────────────────
if [ "$HASCOL" != "1" ]; then
  echo
  echo "CANNOT RUN phase 2: dismissed_at absent — apply"
  echo "profile-app/sql/2026-08-08-notification-dismiss.sql first."
  restore; trap - EXIT
  exit $([ $fail -eq 0 ] && echo 2 || echo 1)
fi
phase 'PHASE 2 — flag ON: the SAME call keeps the row and hides it'
set_flag true
id=$(seed)
resp2=$(call "id=$id")
ok "$(echo "$resp2" | grep -q '"ok":true' && echo 1 || echo 0)" "endpoint answered {\"ok\":true}" "$resp2"
st=$(row_state "$id")
ok "$([ "$st" = "dismissed" ] && echo 1 || echo 0)" "the row is KEPT and stamped dismissed" "state=$st"
# The wire contract must not change with the flag, or the surfaces break on the flip.
ok "$([ "$resp" = "$resp2" ] && echo 1 || echo 0)" \
   "the RESPONSE IS BYTE-IDENTICAL in both states" "off=[$resp] on=[$resp2]"

# ── PHASE 3 — Clear-all, the tap that used to destroy a week ────────────────
phase 'PHASE 3 — Clear-all (?all=1) dismisses rather than destroys'
id=$(seed)
resp3=$(call "all=1")
ok "$(echo "$resp3" | grep -q '"deleted"' && echo 1 || echo 0)" \
   "the {\"deleted\":N} key is preserved, so no client breaks on the flip" "$resp3"
st=$(row_state "$id")
ok "$([ "$st" = "dismissed" ] && echo 1 || echo 0)" "the row SURVIVED Clear-all" "state=$st"

# ── PHASE 4 — the deny model is unchanged ──────────────────────────────────
phase 'PHASE 4 — a second dismiss of the same id is still a 404, not a 500'
resp4=$(call "id=$id")
ok "$(echo "$resp4" | grep -q 'not_found' && echo 1 || echo 0)" \
   "already-dismissed reads as not_found, same as already-deleted" "$resp4"

pgapp "DELETE FROM notifications WHERE user_uuid='$UUID' AND target_id=$TARGET;" >/dev/null

# ── Restore, asserted ──────────────────────────────────────────────────────
phase 'RESTORE — the tracked config is byte-identical to how it started'
restore; trap - EXIT
now_md5="$(md5sum "$CFG" | cut -d' ' -f1)"
ok "$([ "$now_md5" = "$SNAP_MD5" ] && echo 1 || echo 0)" \
   "config md5 unchanged ($SNAP_MD5)" "now $now_md5"
ok "$(grep -q "'dismiss_instead_of_delete' => false," "$CFG" && echo 1 || echo 0)" \
   "and it is back to the shipped default (false)"

echo
[ $fail -eq 0 ] && echo "GREEN — all $did assertions passed" || echo "RED — $fail of $did assertions failed"
exit $([ $fail -eq 0 ] && echo 0 || echo 1)
