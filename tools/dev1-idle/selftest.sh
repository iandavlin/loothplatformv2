#!/usr/bin/env bash
# Sandbox selftest for platform/dev1/idle-shutdown-daemon.sh.
#
# Runs the REAL daemon script -- not a copy, not a reimplementation -- with
# every path and command redirected into a throwaway sandbox. A forked test
# copy would drift from what ships; this cannot.
#
# NOTHING CAN STOP AN INSTANCE. IDLE_STOP_CMD is a mock that writes a marker
# file, IDLE_MAIL_CMD swallows the mail, and no live path (/var/log,
# /tmp/no-idle-shutdown, /run/idle-shutdown, the systemd unit) is touched or
# read. The live daemon keeps running untouched throughout.
#
# Safety rules this obeys (keeper, 2026-07-26 18:24, after dev1's tmux server
# died taking two lanes with it):
#   * tmux fixtures live on a PRIVATE socket (-L lg-idle-selftest), never the
#     shared default socket every lane runs on. Only that private server is
#     ever killed.
#   * fixtures are CHEAP -- a renamed /bin/bash and a renamed /bin/sleep. No
#     real claude is ever spawned as a test fixture.
#   * RAM cost is a few MB (see BUDGET below); headroom is checked before any
#     fixture starts and the run aborts if the box is tight.
#
# Usage: tools/dev1-idle/selftest.sh [-k]      (-k keeps the sandbox for
#                                               inspection instead of wiping it)
set -uo pipefail

DAEMON="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/platform/dev1/idle-shutdown-daemon.sh"
[[ -x "$DAEMON" || -r "$DAEMON" ]] || { echo "cannot find daemon at $DAEMON"; exit 2; }

KEEP=0; [[ "${1:-}" == "-k" ]] && KEEP=1
SOCKET="lg-idle-selftest"          # PRIVATE tmux socket, never the default
MIN_FREE_MB=400                    # refuse to manufacture load below this
BUDGET="~10MB (one bash spinner + a few sleeps)"

SB=$(mktemp -d /tmp/idle-selftest.XXXXXX)
PASS=0; FAIL=0

# ---------------------------------------------------------------- sandbox ---
mkdir -p "$SB/bin" "$SB/home/.claude/projects" "$SB/home/projects" "$SB/quiet"
# Cheap fixtures. comm must equal the name so the daemon's pgrep -x matches,
# and exe must contain it so its /proc/<pid>/exe confirmation passes.
cp /bin/bash  "$SB/bin/lg-fakeworker"   # stands in for claude (a WORKER_COMM)
cp /bin/bash  "$SB/bin/lg-fakebuild"    # NOT a worker comm: only findable via tmux
cp /bin/sleep "$SB/bin/lg-fakeengine"   # stands in for headless chrome
# `w -hs` is the one activity source with no env knob, so stub it on PATH to
# keep the TTY signal deterministic (a real SSH login mid-run would otherwise
# flip every case to "active").
printf '#!/bin/sh\nexit 0\n' > "$SB/bin/w"; chmod +x "$SB/bin/w"
printf '#!/bin/sh\necho "MOCK-STOP called: $*" >> %s/stop-called\n' "$SB" > "$SB/bin/mock-stop"
printf '#!/bin/sh\ncat > %s/mail-sent\n' "$SB" > "$SB/bin/mock-mail"
chmod +x "$SB/bin/mock-stop" "$SB/bin/mock-mail"

export PATH="$SB/bin:$PATH"

cleanup() {
    pkill -x lg-fakeworker 2>/dev/null
    pkill -x lg-fakebuild  2>/dev/null
    pkill -x lg-fakeengine 2>/dev/null
    # ONLY the private selftest server. Never the default socket.
    tmux -L "$SOCKET" kill-server 2>/dev/null
    rm -f "/tmp/tmux-$(id -u)/$SOCKET"   # kill-server leaves the socket file behind
    if (( KEEP )); then echo "sandbox kept: $SB"; else rm -rf "$SB"; fi
}
trap cleanup EXIT INT TERM

# ------------------------------------------------------------------ harness --
# Fixtures MUST be started through this. A background fixture inherits this
# script's stdout, and an orphaned one (bash -c 'sleep 600' leaves the sleep
# behind when the bash is killed) holds that pipe open long after the test
# ends -- which hangs any caller reading our output. Detach the fds, and reap
# children before the parent so nothing is orphaned in the first place.
FIXTURES=()
fixture() {  # fixture <cmd...>  -> sets FIX_PID
    "$@" >/dev/null 2>&1 </dev/null &
    FIX_PID=$!
    FIXTURES+=("$FIX_PID")
}
unfixture() {
    local p
    for p in "$@"; do
        pkill -P "$p" 2>/dev/null      # children first, or they outlive the parent
        kill "$p" 2>/dev/null
        wait "$p" 2>/dev/null
    done
}

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$*"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$*"; }

# run_daemon <case-name> <seconds> -- starts the real script against a fresh
# per-case state dir, lets it take a few passes, stops it. Echoes the log path.
run_daemon() {
    local name=$1 secs=$2 dir="$SB/case-$1"
    mkdir -p "$dir/state" "$dir/run"
    (
        cd /
        IDLE_LOGFILE="$dir/log" \
        IDLE_INTERVAL=5 \
        IDLE_THRESHOLD=1 \
        IDLE_COUNTDOWN_SECS=10 \
        IDLE_EMAIL_THRESHOLD=1 \
        IDLE_STOP_CMD="$SB/bin/mock-stop" \
        IDLE_MAIL_CMD="$SB/bin/mock-mail" \
        IDLE_EMAIL_TO="selftest@invalid" \
        IDLE_OVERRIDE_FILE="${HOLD_FILE:-$dir/state/hold}" \
        IDLE_DRYRUN_FILE="$dir/state/dryrun" \
        IDLE_COUNTDOWN_FILE="$dir/state/countdown" \
        IDLE_CANCEL_FILE="$dir/state/cancel" \
        IDLE_ACTIVITY_FILE="$dir/state/ssh-activity" \
        IDLE_EMAIL_SENT_FILE="$dir/state/email-sent" \
        IDLE_HOME_DIR="$SB/home" \
        IDLE_CLAUDE_HOME_GLOB="$SB/quiet/" \
        IDLE_HEARTBEAT_LOG="$dir/state/no-such-heartbeat" \
        IDLE_WORKER_STATE_DIR="$dir/run" \
        IDLE_WORK_JIFFIES=60 \
        IDLE_WORKER_COMMS="lg-fakeworker" \
        IDLE_ENGINE_COMMS="lg-fakeengine" \
        IDLE_TMUX_SOCKET_GLOB="/tmp/tmux-$(id -u)/$SOCKET" \
        bash "$DAEMON" >/dev/null 2>"$dir/stderr" &
        echo $! > "$dir/pid"
    )
    # NOTE: stdout/stderr above MUST be redirected. run_daemon is called inside
    # $(...), and a backgrounded child holding that pipe open would block the
    # command substitution forever.
    sleep "$secs"
    local p; p=$(cat "$dir/pid" 2>/dev/null)
    if [[ -n "$p" ]]; then kill "$p" 2>/dev/null; sleep 0.3; kill -9 "$p" 2>/dev/null; fi
    echo "$dir/log"
}

# assertions over a case log
saw()     { grep -q -- "$2" "$1"; }
expect()  { # <log> <pattern> <description>
    if saw "$1" "$2"; then ok "$3"; else bad "$3 (no '$2' in $(basename "$(dirname "$1")")/log)"; fi
}
refute()  { # <log> <pattern> <description>
    if saw "$1" "$2"; then bad "$3 (unexpected '$2')"; else ok "$3"; fi
}

# ------------------------------------------------------------- preflight ----
say "PREFLIGHT"
free_mb=$(free -m | awk '/^Mem:/{print $7}')
echo "  available RAM: ${free_mb}MB (need >= ${MIN_FREE_MB}MB); fixture budget: $BUDGET"
if (( free_mb < MIN_FREE_MB )); then
    echo "  ABORT: not enough headroom to manufacture load safely."
    exit 3
fi
if [[ -S "/tmp/tmux-$(id -u)/$SOCKET" ]]; then tmux -L "$SOCKET" kill-server 2>/dev/null; fi
echo "  daemon under test: $DAEMON"
echo "  sandbox:          $SB"
echo "  stop command:     MOCKED ($SB/bin/mock-stop) -- no instance can stop"

# ------------------------------------------------------------------ cases ---
say "CASE 1  quiet box -> counts down and reaches the (mocked) stop"
log=$(run_daemon quiet 32)
expect "$log" "WORKER: 0 candidate" "no workers seen"
expect "$log" "ALL IDLE" "declared idle"
expect "$log" "COUNTDOWN COMPLETE" "countdown ran to completion"
if [[ -f "$SB/stop-called" ]]; then ok "stop path fired (mock captured it, nothing really stopped)"
else bad "stop path never fired"; fi
if [[ -f "$SB/mail-sent" ]]; then ok "idle email went to the mock, not to Ian"
else bad "idle email path did not fire"; fi
rm -f "$SB/stop-called" "$SB/mail-sent"

say "CASE 2  worker burning CPU -> active, never counts down"
fixture "$SB/bin/lg-fakeworker" -c 'while :; do :; done'; spin=$FIX_PID
log=$(run_daemon busy 22)
unfixture $spin
expect "$log" "burned" "CPU burn detected"
expect "$log" "server active" "reported active"
refute "$log" "ALL IDLE" "never started a countdown"

say "CASE 3  leaked 0%-CPU engine ALONE -> must NOT hold the box up"
fixture "$SB/bin/lg-fakeengine" 300; eng=$FIX_PID
log=$(run_daemon engine-alone 32)
expect "$log" "WORKER: 0 candidate" "engine not counted without a worker"
expect "$log" "ALL IDLE" "still went idle"
expect "$log" "COUNTDOWN COMPLETE" "countdown completed despite the leak"
unfixture $eng
rm -f "$SB/stop-called"

say "CASE 4  same engine ALONGSIDE a live worker -> counted, box stays up"
fixture "$SB/bin/lg-fakeengine" 300; eng=$FIX_PID
fixture "$SB/bin/lg-fakeworker" -c 'while :; do :; done'; spin=$FIX_PID
log=$(run_daemon engine-plus-worker 22)
# The verdict line alone cannot show this: with a worker burning CPU the
# daemon is active every pass, so the idle summary never prints. Look at the
# tracked-pid state instead -- the engine's pid is in it only if the engine was
# collected as a root, which only happens alongside a live worker.
if grep -q "^${eng} " "$(dirname "$log")/run/worker-cpu" 2>/dev/null
then ok "engine tracked as a root because a worker is alive (pid $eng)"
else bad "engine was not collected even though a worker was alive"; fi
unfixture $spin $eng
expect "$log" "server active" "reported active"

say "CASE 5  PARKED worker (present, 0% CPU) -> idle once the grace expires"
fixture "$SB/bin/lg-fakeworker" -c 'sleep 300; true'; parked=$FIX_PID
log=$(run_daemon parked 80)
unfixture $parked
expect "$log" "new worker since last pass" "presence alone stamped once, at first sight"
expect "$log" "real work .*m ago" "grace window held it briefly"
expect "$log" "none doing work" "then correctly read idle -- presence != busy"

say "CASE 6  tmux pane running work on a PRIVATE socket -> active"
# lg-fakebuild is deliberately NOT in WORKER_COMMS, so the only way the daemon
# can see it is through the tmux pane scan. Whole command is ONE argument --
# tmux joins extra args and re-splits them through a shell.
tmux -L "$SOCKET" new-session -d -s work "$SB/bin/lg-fakebuild -c 'while :; do :; done'"
log=$(run_daemon tmux-work 22)
tmux -L "$SOCKET" kill-server 2>/dev/null
expect "$log" "server active" "pane doing work read as active (found via tmux, not pgrep)"
refute "$log" "ALL IDLE" "no countdown while the pane worked"

say "CASE 7  tmux pane sitting at a bare shell prompt -> idle"
tmux -L "$SOCKET" new-session -d -s idle
log=$(run_daemon tmux-shell 32)
tmux -L "$SOCKET" kill-server 2>/dev/null
expect "$log" "ALL IDLE" "a prompt is not work"
expect "$log" "COUNTDOWN COMPLETE" "countdown completed"
rm -f "$SB/stop-called"

say "CASE 8  hold file present -> checks skipped entirely (7/08 TTL regression)"
mkdir -p "$SB/case-hold/state"
date -d '+1 hour' +%s > "$SB/case-hold/hold"
log=$(HOLD_FILE="$SB/case-hold/hold" run_daemon hold 12)
expect "$log" "OVERRIDE: timed hold until" "hold honored"
refute "$log" "ALL IDLE" "no countdown while held"

# --------------------------------------------------- live calibration -------
# Read-only: no fixtures, no load. Shows where the REAL processes on this box
# fall relative to the threshold, which is the number that actually matters.
say "CALIBRATION (read-only, live processes -- no load manufactured)"
# Read the shipped default out of the daemon itself so this cannot drift from it.
DEF_JIFF=$(sed -n 's/^WORK_JIFFIES="${IDLE_WORK_JIFFIES:-\([0-9]*\)}".*/\1/p' "$DAEMON")
DEF_JIFF=${DEF_JIFF:-150}
snap() { local p=$1; [[ -r /proc/$p/stat ]] || return 0; local l; l=$(< /proc/$p/stat)
         local r=${l##*) }; local -a f; read -r -a f <<< "$r"; echo $(( f[11]+f[12] )); }
declare -A t0
for p in $(pgrep -x claude 2>/dev/null) $(pgrep -x chrome 2>/dev/null | head -3); do t0[$p]=$(snap "$p"); done
sleep 10
printf '  %-8s %-10s %s\n' PID JIFF/60s VERDICT
for p in "${!t0[@]}"; do
    now=$(snap "$p"); [[ -n "$now" ]] || continue
    per_min=$(( (now - t0[$p]) * 6 ))
    comm=$(cat "/proc/$p/comm" 2>/dev/null || echo "?")
    if (( per_min >= DEF_JIFF )); then v="WORKING (>= ${DEF_JIFF} shipped threshold)"; else v="parked/leaked (< ${DEF_JIFF})"; fi
    printf '  %-8s %-10s %s  [%s]\n' "$p" "$per_min" "$v" "$comm"
done

# ----------------------------------------------------------------- result ---
say "RESULT: $PASS passed, $FAIL failed"
(( FAIL == 0 ))
