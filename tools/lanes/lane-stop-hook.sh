#!/usr/bin/env bash
# lane-stop-hook.sh — Stop hook that keeps a LANE working until its charter is
# explicitly done or blocked (2026-08-15, built after Ian: "how do we fix the
# lanes stopping"). The community-standard pattern: block the stop, inject the
# reason, agent gets another turn. Loop-capped so a genuinely stuck lane can
# still park.
#
# Fires ONLY inside lane sessions: spin/respawn scripts export LG_LANE=1.
# Keeper, Ian's chats, and anything else are untouched (hook no-ops).
#
# A lane parks LEGITIMATELY by either:
#   - keeper listing it in ~/.lane-park-ok            (keeper-granted)
#   - writing .lane-state/DONE or .lane-state/BLOCKED (one-line reason inside,
#     board-reported first) in its worktree            (lane-declared)
set -u

[ "${LG_LANE:-0}" = "1" ] || exit 0

INPUT=$(cat 2>/dev/null || true)
SESSION=$(tmux display-message -p '#S' 2>/dev/null || basename "$PWD")

# Keeper-granted park? Same rule as the watchdog (8/15): newest entry wins
# and it must be UNEXPIRED — the naive any-line grep let stale entries
# silently disarm this hook fleet-wide within hours of shipping it.
OKEXP=$(awk -v l="$SESSION" '$1==l {print $2}' "$HOME/.lane-park-ok" 2>/dev/null | tail -1)
if [ -n "$OKEXP" ] && [ "$OKEXP" -gt "$(date +%s)" ] 2>/dev/null; then exit 0; fi

# Lane-declared done/blocked/question? A QUESTION is a first-class stop (Ian,
# 2026-08-15: "If it stops to ask a question, we should answer the question")
# — the lane parks immediately with zero pushes, and the watchdog escalates
# the question to keeper on its next sweep instead of a generic timer alert.
if [ -f .lane-state/DONE ] || [ -f .lane-state/BLOCKED ] || [ -f .lane-state/QUESTION ]; then exit 0; fi

# Loop cap: if we already pushed 3 times in the last 10 minutes, let it park —
# a lane that cannot progress after three pushes needs the watchdog + keeper,
# not a fourth push. (This also defuses any tight stop/block oscillation.)
CAPDIR="$HOME/.lane-say"; mkdir -p "$CAPDIR"
CAPFILE="$CAPDIR/stopblocks-$SESSION"
NOW=$(date +%s)
RECENT=$(awk -v now="$NOW" '$1 > now-600' "$CAPFILE" 2>/dev/null | wc -l)
if [ "${RECENT:-0}" -ge 3 ]; then exit 0; fi
echo "$NOW" >> "$CAPFILE"

cat <<'JSON'
{"decision": "block",
 "reason": "Your charter is not marked done or blocked, so parking is not available yet. Do these in order: (1) run `msg inbox | tail -30` — keeper instructions may be waiting unread, and acting on those outranks everything; (2) if you have a QUESTION for keeper or Ian, that is a VALID stop: post the question to the board, then `mkdir -p .lane-state && echo 'your question, one line' > .lane-state/QUESTION` and stop — you park immediately and keeper is alerted to ANSWER, never to nudge you back to work; (3) otherwise continue the next item on your own plan; (4) if the charter is genuinely complete or you are hard-blocked: board-report, write .lane-state/DONE (or BLOCKED naming WHO unblocks you), and stop again. Never simulate being done to escape this hook; keeper reads .lane-state files against the board. When keeper answers your question or unblocks you, DELETE the .lane-state file before resuming work."}
JSON
exit 0
