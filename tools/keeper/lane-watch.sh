#!/usr/bin/env bash
# lane-watch — the keeper's automatic lane-sweep loop. Runs in background and
# EXITS (re-invoking keeper) the moment a lane needs attention, so keeper never
# waits to be asked "are the lanes working?". Relaunched by keeper each time it
# fires. Ian's rule 7/30: put yourself on the check-lanes loop automatically.
#   args: $1 = expected WORKING count (baseline)
set -uo pipefail
BASE="${1:-3}"
OUTBOX=/home/ubuntu/lane-outbox
LAST=$(ls -t "$OUTBOX" 2>/dev/null | head -1)
for i in $(seq 1 30); do          # ~30 min max, then heartbeat back to keeper
    W=$(~/keeper-repo/tools/lanes/../../tools/lanes/lane-list.sh 2>/dev/null | grep -c WORKING 2>/dev/null || tmux list-sessions 2>/dev/null >/dev/null && lanes 2>/dev/null | grep -c WORKING)
    SWAP=$(free -m | awk '/^Swap:/{print $3}')
    NOW=$(ls -t "$OUTBOX" 2>/dev/null | head -1)
    if [ "${W:-0}" -lt "$BASE" ]; then echo "WAKE: a lane parked/finished (WORKING=$W < $BASE)"; exit 0; fi
    if [ "${SWAP:-0}" -gt 1200 ]; then echo "WAKE: swap pressure ($SWAP MB)"; exit 0; fi
    if [ "$NOW" != "$LAST" ]; then echo "WAKE: new lane-outbox result ($NOW)"; exit 0; fi
    sleep 60
done
echo "HEARTBEAT: 30min quiet, all $BASE lanes still working"
