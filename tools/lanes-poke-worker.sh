#!/usr/bin/env bash
# lanes-poke-worker — deliver the lanes page's "Poke keeper" taps (#156).
#
# Runs AS ubuntu, fired by platform/systemd/lanes-poke.path when the web user
# appends to the spool. This split is not ceremony: the board is sqlite under
# group `devmsg` (ubuntu alone), and php-fpm runs as looth-dev, so the endpoint
# physically cannot write the board. It queues; this delivers.
#
# Delivery is TWO things, because one of them alone has failed before:
#   1. a board message to keeper — keeper reads the board at every report, so
#      this is the durable record with the seat and the time in it;
#   2. a line in ~/.keeper-pokes, which stall-watchdog.sh watches. The watchdog
#      wakes keeper by EXITING, so a poke reaches a live keeper in one loop
#      instead of waiting for the next sweep.
#
# ⚠ It does NOT touch /tmp/claude-ian-action. That file is keeper's ding for
# IAN ("he has a button"); firing it here would ding Ian for his own tap. The
# issue asks for "a wake file the keeper's next sweep cannot miss" — that is
# ~/.keeper-pokes, and the watchdog makes it better than a sweep.
set -uo pipefail

SPOOL="$HOME/.lanes-poke-request"
POKES="$HOME/.keeper-pokes"

[ -f "$SPOOL" ] || exit 0

# Lock the SPOOL ITSELF, not a side file: the endpoint appends under flock() on
# this same inode, so a poke cannot land in the window between reading and
# truncating. Truncating with `: >` keeps the inode and its 0666 mode — a fresh
# file would come back 0644 and the web user could never queue again.
exec 9<>"$SPOOL" || exit 0
flock -x -w 10 9 || exit 0
mapfile -t LINES < "$SPOOL"
: > "$SPOOL"
flock -u 9
exec 9>&-

# The truncate above is itself a write, so the path unit fires us once more and
# that run finds an empty spool and exits here. Harmless, and cheaper than
# teaching systemd to tell the two writes apart.
[ ${#LINES[@]} -gt 0 ] || exit 0

for line in "${LINES[@]}"; do
    [ -n "$line" ] || continue
    ts="${line%% *}"; seat="${line#* }"
    # Validated AGAIN here. The endpoint validates too, but this side is what
    # composes a message, and a spool file is not a trust boundary.
    [[ "$seat" =~ ^[0-9A-Za-z][0-9A-Za-z._-]{0,63}$ ]] || continue
    case "$seat" in *..*) continue;; esac
    [[ "$ts" =~ ^[0-9]{1,12}$ ]] || ts=$(date +%s)
    when=$(date -d "@$ts" '+%H:%M' 2>/dev/null || date '+%H:%M')

    # No backticks in this text, ever: a board message goes through a shell on
    # some send paths and backticked words are command-substituted away before
    # msg ever sees them (it has bitten two lanes).
    msg send ubuntu "POKE from Ian on the lanes page, $when UTC: seat $seat looks idle to him. Check that agent now - either nudge it with lane-say, or park the seat and tell him which you did. He pressed a button expecting an answer." >/dev/null 2>&1

    printf '%s %s\n' "$ts" "$seat" >> "$POKES"
done
