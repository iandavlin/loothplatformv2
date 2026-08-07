#!/bin/bash
# redfirst.sh — break each assertion in notif-read-seen-gate and prove it goes RED
# FOR THE REASON IT CLAIMS. An assertion that has never failed is decoration.
#
# restore() restores from a SNAPSHOT taken at start, NOT from `git checkout -- `.
# Twice, checkout-from-HEAD silently discarded work newer than the last commit, and
# every case then reported "did not go red" because the code under test had been
# reverted out from under it — one harness bug wearing the costume of ten findings.
set -uo pipefail
B=/home/ubuntu/worktrees/recap-read-timer
SNAP=/tmp/rrt-exercise/snap
cd "$B" || exit 1
GATE="python3 tools/gates/notif-read-seen-gate.py"

FILES=(
  profile-app/src/Notifications.php
  profile-app/src/Recap.php
  profile-app/config/notifications.php
  webroot/bottom-nav.js
)

rm -rf "$SNAP"; mkdir -p "$SNAP"
for f in "${FILES[@]}"; do mkdir -p "$SNAP/$(dirname "$f")"; cp "$f" "$SNAP/$f"; done
restore() { for f in "${FILES[@]}"; do cp "$SNAP/$f" "$f"; done; }
trap restore EXIT

mutate() { python3 -c "
import sys
p, old, new = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(p).read()
if old not in s:
    print('  MUTATION DID NOT APPLY: pattern absent from ' + p); sys.exit(9)
open(p, 'w').write(s.replace(old, new, 1))
" "$@"; }

case_run() {   # case_run <label> <expect-substring>
  local label="$1" expect="$2" out rc fails
  out=$($GATE 2>&1); rc=$?
  fails=$(printf '%s\n' "$out" | grep -c "^  FAIL")
  printf '%-52s exit=%s fails=%-3s ' "$label" "$rc" "$fails"
  if [ "$rc" = "1" ] && printf '%s\n' "$out" | grep -q "FAIL.*$expect"; then
    echo "RED as intended"
  else
    echo "*** NOT RED FOR '$expect' -- ASSERTION IS DECORATION ***"
    printf '%s\n' "$out" | grep -E "^  (FAIL|CANNOT)" | head -4
  fi
  restore
}

N=profile-app/src/Notifications.php
J=webroot/bottom-nav.js
C=profile-app/config/notifications.php
R=profile-app/src/Recap.php
SQL="AND id = ANY(string_to_array(:ids, \\',\\')::bigint[])"

echo "baseline (unmutated) must be GREEN:"
$GATE >/dev/null 2>&1 && echo "  green, exit 0" || echo "  *** BASELINE NOT GREEN ***"
echo

# 1. The absent half. Keep :ids bound — PDO errors on an unused placeholder and that
#    reads as CANNOT RUN, not as a finding — but make the predicate always true.
mutate "$N" "$SQL" "AND (id = ANY(string_to_array(:ids, \\',\\')::bigint[]) OR true)" \
  && case_run "1. markReadMany sweeps instead of scoping" "UNSEEN rows are STILL UNREAD"

# 2. Owner scoping removed.
mutate "$N" "WHERE user_uuid = :v AND is_read = false
                $SQL" "WHERE (user_uuid = :v OR true) AND is_read = false
                $SQL" \
  && case_run "2. markReadMany not owner-scoped" "FOREIGN id marks nothing"

# 3/4. The flag branch pinned in each direction.
mutate "$N" "        if (self::readSeenOnly()) {" "        if (false) {" \
  && case_run "3. applySeenRead ignores the ARMED flag" "ON: policy is 'seen'"
mutate "$N" "        if (self::readSeenOnly()) {" "        if (true) {" \
  && case_run "4. OFF stops sweeping (no longer a no-op)" "OFF: the 4 unseen rows are ALSO read"

# 5. The dwell becomes unclearable again — the RED-B half of the defect.
mutate "$J" "    clearTimeout(notifDwellTimer); notifDwellTimer = null;
" "" \
  && case_run "5. closing the sheet no longer cancels the dwell" "CANCELS the dwell timer"

# 6. The client goes back to sweeping on the timer — the original defect.
mutate "$J" "            if ((d && d.read_policy) === 'seen') markNotifsSeenRead(seenIds);
            else markAllNotifsRead();" "            markAllNotifsRead();" \
  && case_run "6. the dwell posts read_all again (the defect)" "posts read_seen, not read_all"

# 7. The fire-time open re-check removed.
mutate "$J" "            if (!document.querySelector('#' + NOTIF_ID + '.is-open')) return;
" "" \
  && case_run "7. dwell no longer re-checks the sheet is open" "re-checks the sheet is OPEN"

# 8. See-all stops asking for the whole store, so the badge becomes unreachable.
#    NOTE "limit=200" also appears in a COMMENT in this function — which is exactly
#    what caught the gate asserting on prose instead of code the first time round.
mutate "$J" "(showAll ? '?limit=200' : '')" "''" \
  && case_run "8. See-all no longer fetches the whole store" "badge stays reachable"

# 9. Flag armed with no recorded decision.
mutate "$C" "'read_seen_only' => false," "'read_seen_only' => true," \
  && case_run "9. flag ARMED with no recorded decision" "recorded in the decision doc"

# 10. The consequence: the recap stops honouring is_read for hub rows.
mutate "$R" "OR (n.connection_id IS NULL AND n.is_read = false)" "OR (n.connection_id IS NULL)" \
  && case_run "10. recap stops honouring is_read for hub rows" "RECAP is EMPTY after a sweep"

echo
restore
echo "tree restored; git status:"; git status --short
