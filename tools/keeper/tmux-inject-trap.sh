#!/usr/bin/env bash
# Forensic passthrough shim for tmux — the unsigned-standby hunt (2026-07-29).
# Symlinked at /usr/local/bin/tmux (which precedes /usr/bin in PATH) so every
# bare `tmux` invocation logs its caller lineage, then behaves EXACTLY like tmux.
# A caller using the absolute /usr/bin/tmux path bypasses this; the log proves
# identity when the standby injects, and proves nothing when it goes quiet.
# Remove by deleting the symlink. Log: ~/.tmux-inject.log
LOG=/home/ubuntu/.tmux-inject.log
{
    printf '%s ppid=%s[' "$(date -u '+%F %T')" "$PPID"
    tr '\0' ' ' </proc/$PPID/cmdline 2>/dev/null
    GP=$(ps -o ppid= -p "$PPID" 2>/dev/null | tr -d ' ')
    printf '] gppid=%s[' "${GP:-?}"
    [ -n "$GP" ] && tr '\0' ' ' </proc/$GP/cmdline 2>/dev/null
    printf '] :: tmux %s\n' "$*"
} >> "$LOG" 2>/dev/null
exec /usr/bin/tmux "$@"
