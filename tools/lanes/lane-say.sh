#!/usr/bin/env bash
# lane-say — deliver a message to a Claude lane (tmux session) AND PROVE IT ARRIVED.
#
# WHY THIS EXISTS. On 2026-07-27 seven instructions were lost across five lanes and
# a sub-keeper spent an entire day inert because it never received its own charter.
# Every one of them had been "sent". Nothing checked. Text can land in a lane's input
# box in a state that cannot be submitted from outside at all — Enter, Escape, a
# redraw, a resize and a bracketed-paste terminator were all measured against it and
# none of them work; only wiping the box and retyping does. So delivery is not
# "send and hope", it is send-then-verify-then-retry-then-shout.
#
#   lane-say <session> <message>            message as an argument
#   lane-say <session> -f <file>            message from a file (use this for charters)
#   lane-say --box dev2 <session> ...       target a session on dev2 instead of here
#   lane-say --quiet ...                    only speak on failure (for timers)
#   NO_FOOTER=1 lane-say ...                suppress the anti-park footer (rare;
#                                           only when continuing is WRONG, e.g. a stand-down)
#
# Exit 0 = verified delivered. Exit 1 = NOT delivered, and it says so loudly.
# NEVER treat a non-zero exit as cosmetic: it means a lane did not hear you.
set -uo pipefail

DEV2_IP="34.193.244.53"
DEV2_KEY="/home/ubuntu/projects/lg-stripe-billing/claude-keypair.pem"
BOX="local"; QUIET=0; SESSION=""; MSG=""; MSGFILE=""

while [ $# -gt 0 ]; do
    case "$1" in
        --box)   BOX="$2"; shift 2 ;;
        --quiet) QUIET=1; shift ;;
        -f)      MSGFILE="$2"; shift 2 ;;
        -*)      echo "lane-say: unknown option $1" >&2; exit 2 ;;
        *)       if [ -z "$SESSION" ]; then SESSION="$1"; else MSG="${MSG:+$MSG }$1"; fi; shift ;;
    esac
done

[ -n "$SESSION" ] || { echo "usage: lane-say [--box dev2] [--quiet] <session> <message> | -f <file>" >&2; exit 2; }
if [ -n "$MSGFILE" ]; then
    [ -r "$MSGFILE" ] || { echo "lane-say: cannot read $MSGFILE" >&2; exit 2; }
    MSG="$(cat "$MSGFILE")"
fi
[ -n "$MSG" ] || { echo "lane-say: empty message" >&2; exit 2; }

# ---- the anti-park footer -------------------------------------------------
# WHY THIS IS APPENDED TO EVERY MESSAGE. On 2026-07-28 all seven lanes were
# found parked with not one project finished. Each had done a chunk, reported
# it, and stopped — waiting for an acknowledgement nobody had promised. Ian:
# "if they are parked and not finished with a project, we've got an issue."
# The fault was keeper's: instructions said what to do, never what to do AFTER.
#
# A one-off broadcast cannot fix this: a respun lane has no memory of it, and
# the next charter may forget it. Putting it HERE means every instruction a lane
# ever receives carries it — there is no path to a lane that skips this file.
# Keep it SHORT; it rides on top of every message ever sent.
#
# NO_FOOTER=1 suppresses it, for the rare message where continuing is wrong
# (e.g. telling a lane to stand down so its RAM can be reclaimed).
if [ "${NO_FOOTER:-0}" != "1" ]; then
    MSG="$MSG

--- standing rule, appended to every keeper message ---
DO NOT PARK ON THIS MESSAGE. Act on it, then CONTINUE with the next thing on
your own plan. Reporting is not stopping — keeper reads the board and your pane
on a timer, so you lose nothing by carrying on. Park ONLY when genuinely
blocked, and then state in one line WHO blocks you and WHAT would unblock it.
If you are blocked on Ian, keep working on anything that does not depend on his
answer — there is almost always something."
fi

# tmux, here or over there. Everything below is written once and runs on both boxes.
if [ "$BOX" = "dev2" ]; then
    TM() { ssh -o ConnectTimeout=15 -o BatchMode=yes -i "$DEV2_KEY" "ubuntu@$DEV2_IP" tmux "$@"; }
    TM_STDIN() { ssh -o ConnectTimeout=15 -o BatchMode=yes -i "$DEV2_KEY" "ubuntu@$DEV2_IP" tmux "$@"; }
else
    TM() { tmux "$@"; }
    TM_STDIN() { tmux "$@"; }
fi

say() { [ "$QUIET" = 1 ] || echo "$@"; }
pane() { TM capture-pane -p -t "$SESSION" 2>/dev/null; }

TM has-session -t "$SESSION" 2>/dev/null || { echo "lane-say: FAILED — no session '$SESSION' on $BOX" >&2; exit 1; }

# The input line is whatever follows the last prompt marker. If something is already
# sitting there we keep a copy before clearing: that stranded text is often the only
# record of an instruction somebody tried to give, and losing it silently is the very
# failure this script exists to end.
stranded() {
    pane | awk '/^❯/{line=$0} END{
        sub(/^❯[[:space:]]*/,"",line);
        gsub(/[[:space:]]+$/,"",line);
        print line
    }'
}

PRIOR="$(stranded)"
case "$PRIOR" in
    ""|"Try "*|"Press up to edit queued messages"|"Ignore this"*) PRIOR="" ;;
esac
if [ -n "$PRIOR" ]; then
    mkdir -p "$HOME/.lane-say"
    STAMP="$HOME/.lane-say/stranded-${SESSION}-$$.txt"
    printf '%s\n' "$PRIOR" > "$STAMP"
    say "lane-say: NOTE — '$SESSION' already had unsent text in its box; saved to $STAMP"
    say "          text was: $PRIOR"
fi

deliver() {
    TM send-keys -t "$SESSION" C-u >/dev/null 2>&1
    sleep 1
    # Long or multi-line messages go through a paste buffer: send-keys is not a safe
    # transport for a charter-sized payload. Short ones go literally.
    if [ ${#MSG} -gt 400 ] || printf '%s' "$MSG" | grep -q $'\n'; then
        printf '%s' "$MSG" | TM_STDIN load-buffer - >/dev/null 2>&1
        TM paste-buffer -t "$SESSION" >/dev/null 2>&1
    else
        TM send-keys -t "$SESSION" -l "$MSG" >/dev/null 2>&1
    fi
    sleep 1
    TM send-keys -t "$SESSION" Enter >/dev/null 2>&1
    sleep 4
}

# Verified = the box no longer holds our message. A lane that is thinking, a lane that
# queued it, and a lane that already answered are all successes; the failure we care
# about is text still sitting there unread.
verified() {
    local now; now="$(stranded)"
    case "$now" in
        ""|"Try "*|"Press up to edit queued messages") return 0 ;;
    esac
    # Still holding the head of our message = not delivered.
    local head; head="$(printf '%s' "$MSG" | head -c 40)"
    case "$now" in
        *"$head"*) return 1 ;;
    esac
    return 0
}

deliver
if verified; then
    say "lane-say: delivered to '$SESSION' on $BOX"
    # Stamp the delivery so the watchdog can tell "parked having ANSWERED
    # keeper" from "parked having gone quiet ON an instruction" — twice on
    # 2026-08-15 a verified-delivered message was never absorbed and the lane
    # idled on stale beliefs until a generic parked-long fired.
    mkdir -p "$HOME/.lane-say" && date +%s > "$HOME/.lane-say/sent-$SESSION.ts"
    exit 0
fi

say "lane-say: first attempt did not take on '$SESSION' — retrying once"
deliver
if verified; then
    say "lane-say: delivered to '$SESSION' on $BOX (second attempt)"
    mkdir -p "$HOME/.lane-say" && date +%s > "$HOME/.lane-say/sent-$SESSION.ts"
    exit 0
fi

# Loud on purpose. A silent failure here is how a lane sits idle for six hours.
echo "lane-say: *** FAILED *** '$SESSION' on $BOX did NOT receive the message." >&2
echo "lane-say: the box still holds it. Nothing outside the session can submit text" >&2
echo "lane-say: in this state — attach and press Enter, or clear it and resend." >&2
echo "lane-say: message was: $(printf '%s' "$MSG" | head -c 200)" >&2
exit 1
