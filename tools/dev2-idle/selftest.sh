#!/usr/bin/env bash
# selftest.sh -- exercise platform/dev2/dev2-idle-shutdown.sh against fixtures.
#
# Safe to run anywhere, including dev1. It never reads a real signal and never
# stops a box: every path the daemon touches is redirected into a throwaway
# sandbox, `aws` is a mock that records its argv, and the dry-run hook is on.
#
#   ./selftest.sh [path/to/dev2-idle-shutdown.sh]
#
# Exit 0 = all assertions passed.
set -uo pipefail

DAEMON=${1:-"$(cd "$(dirname "${BASH_SOURCE[0]}")/../../platform/dev2" && pwd)/dev2-idle-shutdown.sh"}
[[ -x "$DAEMON" || -r "$DAEMON" ]] || { echo "cannot find daemon at $DAEMON" >&2; exit 2; }

SB=$(mktemp -d /tmp/dev2-idle-selftest.XXXXXX)
trap 'rm -rf "$SB"' EXIT
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
hdr() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# ---- sandbox ---------------------------------------------------------------
mkdir -p "$SB/watch" "$SB/bin" "$SB/nochrome"
: > "$SB/ssh-epochs"
: > "$SB/log"

# heartbeat fixture: one ancient hit, so the file exists but is out of window
hb_line() { printf '127.0.0.1 - - [%s] "POST /__heartbeat HTTP/2.0" 204 0 "https://dev2.loothgroup.com/hub/" "%s"\n' \
            "$(date -d "${1:-now}" '+%d/%b/%Y:%H:%M:%S %z')" "${2:-Mozilla/5.0 (X11; Linux x86_64) Chrome/150.0.0.0}"; }
hb_line "3 hours ago" > "$SB/heartbeat.log"
touch -d "3 hours ago" "$SB/heartbeat.log"

# mock aws: records argv, answers describe-instances with $FAKE_NAME
cat > "$SB/bin/aws" <<'MOCK'
#!/usr/bin/env bash
echo "$*" >> "$AWS_CALLS"
if [[ "$*" == *describe-instances* ]]; then echo "${FAKE_NAME:-dev2}"; exit 0; fi
if [[ "$*" == *stop-instances* ]]; then echo "MOCK STOP EXECUTED" >&2; exit 0; fi
exit 0
MOCK
chmod +x "$SB/bin/aws"
export AWS_CALLS="$SB/aws-calls"; : > "$AWS_CALLS"

# Every knob pointed at the sandbox. CHROME_EXE_DIR/UT_GLOB/WP_BIN/CLAUDE_*
# aim at paths nothing matches, so signal 4 is quiet unless a test says so.
env_common=(
  LOGFILE="$SB/log"
  HOLD_FILE="$SB/hold"
  DRYRUN_FILE="$SB/dryrun"
  COUNTDOWN_FILE="$SB/countdown"
  CANCEL_FILE="$SB/cancel"
  CPU_STATE_FILE="$SB/cpu-state"
  SSH_SOURCE="file:$SB/ssh-epochs"
  HEARTBEAT_LOG="$SB/heartbeat.log"
  WATCH_DIRS="$SB/watch"
  CHROME_EXE_DIR="$SB/nochrome/"
  UT_GLOB="$SB/ut-*"
  WP_BIN="$SB/nowp"
  CLAUDE_NAME="__no_such_proc__"
  CLAUDE_PAT="__no_such_proc__"
  AWS_BIN="$SB/bin/aws"
  INSTANCE_ID_OVERRIDE="i-0000000000selftest"
  IDLE_THRESHOLD_MIN=1
  AWS_CALLS="$AWS_CALLS"
)
check() { env "${env_common[@]}" "$@" bash "$DAEMON" --check-once 2>/dev/null; }
reset_signals() {
  : > "$SB/ssh-epochs"; rm -rf "$SB"/ut-* "$SB"/watch/*; rm -f "$SB/cpu-state"
  hb_line "3 hours ago" > "$SB/heartbeat.log"; touch -d "3 hours ago" "$SB/heartbeat.log"
}

# ============================================================================
hdr "1. truth table -- all four quiet is the ONLY path to idle-eligible"
reset_signals
out=$(check); rc=$?
(( rc == 0 )) && [[ "$out" == *"idle_eligible=yes"* ]] \
  && ok "baseline: all quiet -> idle_eligible=yes  [$out]" \
  || bad "baseline should be idle-eligible, got rc=$rc [$out]"

reset_signals; date +%s >> "$SB/ssh-epochs"
out=$(check); rc=$?
(( rc == 1 )) && [[ "$out" == *"ssh=busy"* ]] \
  && ok "ssh busy alone blocks idle  [$out]" || bad "ssh busy not blocking [$out]"

reset_signals; touch "$SB/watch/somefile"
out=$(check); rc=$?
(( rc == 1 )) && [[ "$out" == *"files=busy"* ]] \
  && ok "files busy alone blocks idle  [$out]" || bad "files busy not blocking [$out]"

reset_signals; hb_line now >> "$SB/heartbeat.log"
out=$(check); rc=$?
(( rc == 1 )) && [[ "$out" == *"web=busy"* ]] \
  && ok "web busy alone blocks idle  [$out]" || bad "web busy not blocking [$out]"

reset_signals; mkdir -p "$SB/ut-9999"; touch "$SB/ut-9999/chrome.log"
out=$(check); rc=$?
(( rc == 1 )) && [[ "$out" == *"procs=busy"* ]] \
  && ok "CDP harness dir alone blocks idle  [$out]" || bad "procs busy not blocking [$out]"

hdr "2. a bot cannot hold the box awake via /__heartbeat"
reset_signals; hb_line now "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)" >> "$SB/heartbeat.log"
out=$(check); rc=$?
(( rc == 0 )) && [[ "$out" == *"web=quiet"* ]] \
  && ok "fresh Googlebot heartbeat ignored  [$out]" || bad "bot UA counted as human [$out]"
hb_line now >> "$SB/heartbeat.log"
out=$(check)
[[ "$out" == *"web=busy"* ]] && ok "a human hit after the bot IS counted  [$out]" \
  || bad "human heartbeat missed after bot line [$out]"

hdr "3. a single ssh command resets the clock"
reset_signals
if check >/dev/null; then ok "quiet before the ssh accept"; else bad "should be quiet before any accept"; fi
date +%s >> "$SB/ssh-epochs"          # one short-lived command, buck-style
if check >/dev/null; then bad "one accept did NOT reset the clock"; else ok "one accept -> busy again (clock reset)"; fi

hdr "4. timed hold is honored, expires, and auto-deletes"
echo $(( $(date +%s) + 6 )) > "$SB/hold"
: > "$SB/log"
env "${env_common[@]}" INTERVAL=2 COUNTDOWN_SECS=6 COUNTDOWN_TICK=2 \
  bash "$DAEMON" >/dev/null 2>&1 &
D=$!
sleep 5
grep -q "HOLD: timed hold until" "$SB/log" && ok "hold honored while live" || bad "hold not honored"
sleep 6
grep -q "timed hold expired" "$SB/log" && ok "hold expiry logged" || bad "hold expiry not logged"
[[ ! -f "$SB/hold" ]] && ok "hold file auto-deleted" || bad "hold file still present"
kill $D 2>/dev/null; wait $D 2>/dev/null

hdr "5. dry-run: countdown completes, guard runs, stop is LOGGED not executed"
reset_signals; rm -f "$SB/hold"; touch "$SB/dryrun"; : > "$SB/log"; : > "$AWS_CALLS"
env "${env_common[@]}" INTERVAL=2 COUNTDOWN_SECS=6 COUNTDOWN_TICK=2 FAKE_NAME=dev2 \
  bash "$DAEMON" >/dev/null 2>&1 &
D=$!
for _ in $(seq 30); do grep -q "DRY-RUN: would run" "$SB/log" && break; sleep 1; done
kill $D 2>/dev/null; wait $D 2>/dev/null
grep -q "COUNTDOWN COMPLETE" "$SB/log" && ok "countdown ran to completion" || bad "countdown never completed"
grep -q "DRY-RUN: would run.*stop-instances.*NOT executed" "$SB/log" \
  && ok "stop was LOGGED: $(grep -o 'would run:.*' "$SB/log" | head -1)" || bad "no dry-run stop line"
grep -q "stop-instances" "$AWS_CALLS" && bad "aws stop-instances WAS CALLED (must not be)" \
  || ok "aws stop-instances never invoked (mock argv log clean)"
grep -q "describe-instances" "$AWS_CALLS" && ok "guard did read the Name tag first" || bad "guard never called describe"

hdr "6. safety guard refuses to stop a box not named dev2*"
reset_signals; : > "$SB/log"; : > "$AWS_CALLS"; rm -f "$SB/dryrun"
env "${env_common[@]}" INTERVAL=2 COUNTDOWN_SECS=6 COUNTDOWN_TICK=2 FAKE_NAME=loothgroup2-4-live \
  bash "$DAEMON" >/dev/null 2>&1 &
D=$!
for _ in $(seq 30); do grep -q "SAFETY GUARD" "$SB/log" && break; sleep 1; done
kill $D 2>/dev/null; wait $D 2>/dev/null
grep -q "SAFETY GUARD.*is not dev2\* -- REFUSING to stop" "$SB/log" \
  && ok "refused: $(grep -o 'SAFETY GUARD.*' "$SB/log" | head -1)" || bad "guard did not fire on a non-dev2 name"
grep -q "stop-instances" "$AWS_CALLS" && bad "stop-instances called on a non-dev2 box!" \
  || ok "no stop attempted against the non-dev2 box"

hdr "7. countdown cancels the moment an ssh accept lands"
reset_signals; : > "$SB/log"; touch "$SB/dryrun"
env "${env_common[@]}" INTERVAL=2 COUNTDOWN_SECS=30 COUNTDOWN_TICK=2 \
  bash "$DAEMON" >/dev/null 2>&1 &
D=$!
for _ in $(seq 20); do grep -q "countdown <<<" "$SB/log" && break; sleep 1; done
if grep -q "countdown <<<" "$SB/log"; then
  ok "countdown started"
  date +%s >> "$SB/ssh-epochs"      # buck logs in mid-countdown
  for _ in $(seq 10); do grep -q "CANCELLED -- ssh login" "$SB/log" && break; sleep 1; done
  grep -q "CANCELLED -- ssh login" "$SB/log" && ok "ssh accept cancelled the countdown" \
    || bad "countdown survived an ssh accept"
  [[ ! -f "$SB/countdown" ]] && ok "countdown file cleared" || bad "countdown file left behind"
else
  bad "countdown never started"
fi
kill $D 2>/dev/null; wait $D 2>/dev/null
grep -q "stop-instances" "$AWS_CALLS" && bad "a stop leaked out during the cancel test" \
  || ok "no stop executed anywhere in this run"

# ============================================================================
printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
(( FAIL == 0 ))
