#!/usr/bin/env bash
# keeper lane-lifecycle watcher v4.2 — run under the keeper session's Monitor.
# Emits: new board posts (non-mirror), session-set changes, console-prompt
# stalls on the NEXT 60s tick (content-detected), plain idle at 4 min.
SP="${KEEPER_SCRATCH:-/tmp/keeper-watch}"; mkdir -p "$SP"
msg inbox 2>/dev/null | grep -E '^\* \[' | grep -viE 'mirror-sync-watch' > $SP/board.state
tmux ls 2>/dev/null | cut -d: -f1 | sort > $SP/tmux.state
declare -A last same alerted prompted
while true; do
  msg inbox 2>/dev/null | grep -E '^\* \[' | grep -viE 'mirror-sync-watch' > $SP/board.new
  grep -Fxv -f $SP/board.state $SP/board.new 2>/dev/null | tail -5
  mv $SP/board.new $SP/board.state
  tmux ls 2>/dev/null | cut -d: -f1 | sort > $SP/tmux.new
  cmp -s $SP/tmux.state $SP/tmux.new || echo "lane session set changed: now [$(tr '\n' ' ' < $SP/tmux.new)]"
  mv $SP/tmux.new $SP/tmux.state
  for s in $(tmux ls 2>/dev/null | cut -d: -f1); do
    pane=$(tmux capture-pane -p -t "$s" 2>/dev/null)
    if echo "$pane" | grep -qE 'proceed\?|❯ 1\. Yes|Tell Claude what to change|Do you want to|approve with this feedback'; then
      [ "${prompted[$s]:-0}" != 1 ] && { echo "lane $s: STUCK AT A CONSOLE PROMPT (fast path — check now)"; prompted[$s]=1; }
    else prompted[$s]=0; fi
    h=$(echo "$pane" | md5sum | cut -d' ' -f1)
    if [ "$h" = "${last[$s]:-}" ]; then same[$s]=$(( ${same[$s]:-0} + 1 )); else
      [ "${same[$s]:-0}" -ge 8 ] && alerted[$s]=0
      same[$s]=0
    fi
    last[$s]=$h
    if [ "${same[$s]:-0}" -eq 4 ] && [ "${alerted[$s]:-0}" != 1 ]; then
      echo "lane $s: went idle 4 min (no prompt visible — check for DONE report)"
      alerted[$s]=1
    fi
  done
  sleep 60
done
