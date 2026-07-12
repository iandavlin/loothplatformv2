#!/usr/bin/env bash
# lanes — one-glance status of every Claude lane (tmux session) on this box.
# WORKING = actively generating; parked = waiting (parked lanes are DEAF: they
# do not see board posts — a grant/ruling needs a tmux send-keys nudge).
# On dev1 (keeper box) it also sweeps dev2 over SSH and tails the board.
# Install: sudo cp tools/lanes-status.sh /usr/local/bin/lanes && sudo chmod +x /usr/local/bin/lanes
for s in $(tmux list-sessions -F "#{session_name}" 2>/dev/null); do
  if tmux capture-pane -t "$s" -p 2>/dev/null | grep -q "esc to interrupt"; then st="WORKING"; else st="parked "; fi
  line=$(tmux capture-pane -t "$s" -p -S -30 2>/dev/null \
        | grep -vE "^\s*$|^─|^❯|bypass permissions|tmux |Auto-update|/clear to save|control this session" \
        | tail -1 | sed "s/^[ ●✻⎿·]*//" | cut -c1-90)
  printf "%-14s %s  %s\n" "$s" "$st" "$line"
done
tmux list-sessions >/dev/null 2>&1 || echo "(no lanes on this box)"
# dev1-only extras (keeper box internal hostname):
if [ "$(hostname)" = "ip-172-31-81-87" ]; then
  echo "── dev2:"
  ssh -i /home/ubuntu/projects/lg-stripe-billing/claude-keypair.pem -o ConnectTimeout=8 \
      ubuntu@34.193.244.53 "/usr/local/bin/lanes" 2>/dev/null || echo "(dev2 unreachable)"
  echo "── board (last 3 posts):"
  msg inbox 2>/dev/null | grep -aE "^\s*\*?\s*\[20" | tail -3 | cut -c1-130
fi
