#!/usr/bin/env bash
# lane-watch — keeper's automatic lane-sweep loop. Detached background process:
# runs independently of the chat, EXITS (re-invoking keeper via task-notify) the
# moment a lane needs attention, so keeper self-checks without Ian asking.
# WORKING = a tmux session whose pane shows the active-generation marker.
# Ian's rule 7/30. Relaunched by keeper each time it fires.
#   $1 = baseline WORKING count
set -uo pipefail
BASE="${1:-3}"; OUTBOX=/home/ubuntu/lane-outbox
LAST=$(ls -t "$OUTBOX" 2>/dev/null | head -1)
count_working() {
  local n=0 s
  for s in $(tmux list-sessions -F '#{session_name}' 2>/dev/null); do
    if tmux capture-pane -t "$s" -p 2>/dev/null | grep -q 'esc to interrupt'; then n=$((n+1)); fi
  done
  echo "$n"
}
for i in $(seq 1 30); do
  W=$(count_working); SWAP=$(free -m | awk '/^Swap:/{print $3}'); NOW=$(ls -t "$OUTBOX" 2>/dev/null | head -1)
  [ "${W:-0}" -lt "$BASE" ] && { echo "WAKE: a lane parked/finished (working=$W < baseline $BASE)"; exit 0; }
  [ "${SWAP:-0}" -gt 1200 ] && { echo "WAKE: swap pressure (${SWAP}MB)"; exit 0; }
  [ "$NOW" != "$LAST" ] && { echo "WAKE: new lane-outbox result ($NOW)"; exit 0; }
  sleep 60
done
echo "HEARTBEAT: 30min quiet, $BASE lanes still working"
