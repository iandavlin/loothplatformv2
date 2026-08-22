#!/usr/bin/env bash
# lanes-poke-worker — deliver the lanes page's taps to keeper (#156, #202).
#
# Runs AS ubuntu, fired by platform/systemd/lanes-poke.path when the web user
# appends to the spool. This split is not ceremony: the board is sqlite under
# group `devmsg` (ubuntu alone), and php-fpm runs as looth-dev, so the endpoint
# physically cannot write the board. It queues; this delivers.
#
# TWO VERBS on one spool (#202). The second was added rather than given its own
# spool + .path + .service because that is three more deploy steps a `git pull`
# does not do, and this box's scar tissue is mostly missing deploy steps — the
# poke half of #156 sat undeployed for two days and queued into a spool nothing
# drained. #178's Decision 3, taken again for the same reason.
#
#     <ts> <seat>              a poke   — "this agent looks idle"    (#156)
#     <ts> decide <id> <key>   an answer — Ian picked an option      (#202)
#
# The old shape is untouched and still validated the same way, so a pre-#202
# worker meeting a decide line rejects it on the seat charset (it contains
# spaces) rather than mis-delivering it as a seat name. The name of this file
# stays `lanes-poke-worker.sh` because lanes-poke.service names that path, and
# renaming it would need a systemd edit and a daemon-reload — i.e. exactly the
# deploy step being avoided.
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
#
# ⚠ AND AN ANSWER GETS ITS OWN WAKE FILE, ~/.keeper-decisions, NOT ~/.keeper-
# pokes. The poke alert says "Ian flagged these seats as IDLE", which would be a
# flat lie about a man answering a question — and this page's oldest law is that
# two different things must never render alike. One watermark each, one ALERT
# sentence each (stall-watchdog.sh).
set -uo pipefail

SPOOL="$HOME/.lanes-poke-request"
POKES="$HOME/.keeper-pokes"
DECIDES="$HOME/.keeper-decisions"     # #202: its OWN wake file — see below
# Overridable so gate 77 can drive this worker without writing the real board.
# A gate that has to touch the live board to prove itself is a gate nobody runs.
MSG_CMD="${LG_MSG_CMD:-msg}"
DECIDE_CLI="${LG_DECIDE_CLI:-$(dirname "$0")/decisions/lg-decide.py}"

# Store text reaches a board message, and a board message goes through a shell
# on some send paths — backticked words are command-substituted away before msg
# ever sees them (it has bitten two lanes; the 8/15 case replaced a redis-cli
# recovery command with the literal word OK). Keeper authors the questions, so
# this is defence in depth rather than a trust boundary, but the cost is one
# `tr` and the failure mode is silent corruption of the one sentence that says
# what Ian decided.
clean() { printf '%s' "$1" | tr -d '`$\\' | tr '\n' ' ' | cut -c1-160; }

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
    ts="${line%% *}"; rest="${line#* }"
    [[ "$ts" =~ ^[0-9]{1,12}$ ]] || ts=$(date +%s)
    when=$(date -d "@$ts" '+%H:%M' 2>/dev/null || date '+%H:%M')

    # ── verb 2 (#202): Ian answered a decision box on the page ──────────────
    if [ "${rest%% *}" = "decide" ]; then
        args="${rest#decide }"
        qid="${args%% *}"; key="${args#* }"
        # Validated AGAIN here. The endpoint validates too, but this side is
        # what composes a message and names a file, and a spool file is not a
        # trust boundary.
        [[ "$qid" =~ ^[0-9a-z][0-9a-z-]{2,39}$ ]] || continue
        [[ "$key" =~ ^[a-z0-9][a-z0-9_-]{0,15}$ ]] || continue
        case "$qid" in *..*) continue;; esac

        qtext=$(python3 "$DECIDE_CLI" show "$qid" --json 2>/dev/null \
                | python3 -c 'import json,sys
try:
    q = json.load(sys.stdin)
    print((q.get("question") or "")[:160])
    print(q.get("issue") or "")
except Exception:
    print(""); print("")' 2>/dev/null)
        qline=$(clean "$(printf '%s' "$qtext" | sed -n 1p)")
        qissue=$(printf '%s' "$qtext" | sed -n 2p | tr -dc '0-9')
        [ -n "$qline" ] || qline="(the question text could not be read)"
        issue_bit=""
        [ -n "$qissue" ] && issue_bit=" It is about issue $qissue."

        # ⚠ THE MARK AND THE MESSAGE ARE ONE ACT, AND THE MARK COMES FIRST.
        # lg-decide answer is the atomic first-answer-wins claim shared with the
        # chat channel, so if it refuses, Ian's tap lost a race and there is
        # nothing to deliver but the news of the race.
        err=$(mktemp)
        if rec=$(python3 "$DECIDE_CLI" answer "$qid" "$key" --via page 2>"$err"); then
            label=$(clean "$(printf '%s' "$rec" | python3 -c 'import json,sys
try: print(json.load(sys.stdin).get("label") or "")
except Exception: print("")' 2>/dev/null)")
            [ -n "$label" ] || label="$key"
            $MSG_CMD send ubuntu "IAN ANSWERED A DECISION BOX ON THE LANES PAGE (ian-via-page), $when UTC. Question: $qline$issue_bit His answer: $label. This is an IAN ACT and is exactly as binding as an answer he typed in chat - the store is marked, the box is closed in both channels, and he is expecting you to act on it. Question id $qid, option $key." >/dev/null 2>&1
            printf '%s %s %s\n' "$ts" "$qid" "$key" >> "$DECIDES"
        else
            # ⚠ NOTHING VANISHES, INCLUDING A LOST RACE. He pressed a button and
            # is owed an outcome; a silently discarded tap is indistinguishable
            # from a tap that worked, which is the failure this page's oldest
            # law is written against.
            why=$(clean "$(cat "$err" 2>/dev/null)")
            $MSG_CMD send ubuntu "Ian tapped an answer on the lanes page, $when UTC, but it did NOT take: $why Question: $qline$issue_bit He chose $key and the page told him so - if that disagrees with what you already have, HIS TAP IS THE ONE TO RECONCILE, not to ignore. Question id $qid." >/dev/null 2>&1
        fi
        rm -f "$err"
        continue
    fi

    # ── verb 1 (#156): a seat looks idle ────────────────────────────────────
    seat="$rest"
    # Validated AGAIN here. The endpoint validates too, but this side is what
    # composes a message, and a spool file is not a trust boundary.
    [[ "$seat" =~ ^[0-9A-Za-z][0-9A-Za-z._-]{0,63}$ ]] || continue
    case "$seat" in *..*) continue;; esac

    # No backticks in this text, ever: a board message goes through a shell on
    # some send paths and backticked words are command-substituted away before
    # msg ever sees them (it has bitten two lanes).
    $MSG_CMD send ubuntu "POKE from Ian on the lanes page, $when UTC: seat $seat looks idle to him. Check that agent now - either nudge it with lane-say, or park the seat and tell him which you did. He pressed a button expecting an answer." >/dev/null 2>&1

    printf '%s %s\n' "$ts" "$seat" >> "$POKES"
done
