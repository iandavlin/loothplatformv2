#!/usr/bin/env bash
# live-signals.sh -- prove the four signals read correctly against the REAL box.
#
# Run this on dev2 before arming the daemon, and after any rebuild. Unlike
# selftest.sh (fixtures, runs anywhere), this reads real journals, real nginx
# logs, and real processes.
#
# It is still safe: the daemon is only ever invoked with --check-once, which
# cannot stop anything and makes no aws call. Nothing is installed. The only
# writes are inside a scratch dir under /tmp, removed on exit.
#
#   sudo -v && ./live-signals.sh [path/to/dev2-idle-shutdown.sh]
#
# Takes ~4 minutes: it waits for the 60s lg-wp-cron timer to fire, and ages a
# stand-in process past a shortened idle window.
set -uo pipefail

D=${1:-"$(cd "$(dirname "${BASH_SOURCE[0]}")/../../platform/dev2" && pwd)/dev2-idle-shutdown.sh"}
[[ -r "$D" ]] || { echo "cannot find daemon at $D" >&2; exit 2; }
P=$(mktemp -d /tmp/dev2-idle-live.XXXXXX)
trap 'sudo -n systemctl stop dev2-idle-live-timer.service 2>/dev/null; pkill -f "$P/" 2>/dev/null; sudo -n rm -rf "$P"' EXIT
mkdir -p "$P/empty" "$P/nochrome"
sudo -n touch "$P/log" && sudo -n chmod 666 "$P/log"
clearlog() { : > "$P/log"; }

# every signal neutralised; each test re-enables exactly the one it examines
N=( LOGFILE="$P/log" HOLD_FILE="$P/hold" CPU_STATE_FILE="$P/cpu"
    SSH_SOURCE="file:/dev/null" WATCH_DIRS="$P/empty" HEARTBEAT_LOG="$P/nohb"
    CHROME_EXE_DIR="$P/nochrome/" UT_GLOB="$P/no-ut-*" WP_BIN="$P/nowp"
    CLAUDE_NAME=__none__ CLAUDE_PAT=__none__ IDLE_THRESHOLD_MIN=45 )
run() { sudo -n env "${N[@]}" "$@" bash "$D" --check-once 2>/dev/null; }
procline() { grep "PROCS:" "$P/log" | tail -1; }

echo "################ A. the CDP sweep / headless chrome reads BUSY ################"
echo "  chrome pids under /opt/lg-chrome : $(pgrep -f /opt/lg-chrome/ | wc -l)"
echo "  /tmp/ut-* CDP harness dirs       : $(ls -d /tmp/ut-* 2>/dev/null | wc -l)"
clearlog; run CHROME_EXE_DIR=/opt/lg-chrome/ UT_GLOB='/tmp/ut-*'; echo "  exit=$? (1=busy, required while a harness runs)"
procline
echo
echo "-- chrome alone, CDP dirs ignored (exercises the chrome branch itself):"
clearlog; run CHROME_EXE_DIR=/opt/lg-chrome/; echo "  exit=$?"; procline

echo
echo "################ B. system timers must NEVER hold the box ################"
echo "-- b1. catch the real lg-wp-cron wp-cli and show the cgroup that excludes it"
found=""
for _ in $(seq 70); do
  for pid in $(pgrep -f '/usr/local/bin/wp' 2>/dev/null); do
    exe=$(sudo -n readlink -f "/proc/$pid/exe" 2>/dev/null)
    case "$(basename "${exe:-}")" in
      php*) found="pid=$pid exe=$exe $(cat /proc/$pid/cgroup 2>/dev/null)"; break 2 ;;
    esac
  done
  sleep 1
done
[[ -n "$found" ]] && echo "   CAUGHT: $found" || echo "   (none fired in 70s -- the synthetic proof below still stands)"

echo
echo "-- b2. the SAME synthetic wp-cli under system.slice vs user.slice"
printf '#!/usr/bin/env php\n<?php sleep(40);\n' > "$P/wp"; chmod +x "$P/wp"

sudo -n systemd-run --unit=dev2-idle-live-timer --collect --quiet "$P/wp" 2>&1 | head -1
sleep 2; spid=$(pgrep -f "$P/wp" | head -1)
echo "   [system.slice] pid=$spid $(cat /proc/$spid/cgroup 2>/dev/null)"
clearlog; out=$(run WP_BIN="$P/wp"); echo "     $out"
[[ "$out" == *"procs=quiet"* ]] && echo "     PASS: timer-run wp-cli ignored -- the box can still sleep." \
                                || echo "     FAIL: a 60s timer would pin the box awake forever."
sudo -n systemctl stop dev2-idle-live-timer.service 2>/dev/null; sleep 1

setsid "$P/wp" </dev/null >/dev/null 2>&1 &
sleep 2; upid=$(pgrep -f "$P/wp" | head -1)
echo "   [user.slice]   pid=$upid $(cat /proc/$upid/cgroup 2>/dev/null)"
clearlog; out=$(run WP_BIN="$P/wp"); echo "     $out"
[[ "$out" == *"procs=busy"* ]] && echo "     PASS: a human's wp-cli counted -- buck's work holds the box." \
                              || echo "     FAIL: a human's wp-cli was ignored."
pkill -f "$P/wp" 2>/dev/null

echo
echo "################ C. one ssh accept resets the clock (real journal) ################"
since=$(date -d '45 min ago' '+%Y-%m-%d %H:%M:%S')
naccept=$(sudo -n journalctl -u ssh --since "$since" --no-pager 2>/dev/null | grep -c 'Accepted ')
echo "  real 'Accepted' lines since ${since}: ${naccept}"
clearlog; run SSH_SOURCE=journal; echo "  exit=$? (1=busy: this very ssh session IS the accept)"
grep "SSH:" "$P/log" | tail -1

echo
echo "################ D. a real browser tab is seen via /__heartbeat ################"
echo "  last real heartbeat line:"
sudo -n tail -1 /var/log/nginx/heartbeat.log 2>/dev/null | cut -c1-110 | sed 's/^/    /'
clearlog; run HEARTBEAT_LOG=/var/log/nginx/heartbeat.log; echo "  exit=$?"
grep "WEB:" "$P/log" | tail -1

echo
echo "################ E. a LEAKED chrome (alive, aged, 0 CPU) must NOT count ################"
cp /bin/sleep "$P/chrome"          # a real exe under CHROME_EXE_DIR that burns no CPU
setsid "$P/chrome" 400 </dev/null >/dev/null 2>&1 &
sleep 2; cpid=$(pgrep -f "$P/chrome" | head -1)
L=( "${N[@]}" ); L+=( CHROME_EXE_DIR="$P/" IDLE_THRESHOLD_MIN=1 )
echo "  stand-in pid=$cpid exe=$(readlink -f /proc/$cpid/exe)"
clearlog; sudo -n env "${L[@]}" bash "$D" --check-once 2>/dev/null | sed 's/^/  fresh: /'
procline
echo "  aging it 70s past the 1-minute window (it burns no CPU -- that is the leak)..."
sleep 70
sudo -n env "${L[@]}" bash "$D" --check-once >/dev/null 2>&1     # seed the CPU baseline
sleep 12
clearlog; sudo -n env "${L[@]}" bash "$D" --check-once 2>/dev/null | sed 's/^/  aged:  /'
echo "  age=$(ps -o etimes= -p $cpid | tr -d ' ')s cpu=$(ps -o times= -p $cpid | tr -d ' ')s alive=$(ps -o comm= -p $cpid)"
grep "PROCS:" "$P/log" | sed 's/^/    /'
grep -q "alive but idle" "$P/log" && echo "  PASS: the daemon SAW it and declined to count it (not a silent no-match)." \
                                  || echo "  FAIL: chrome was never matched -- this test proved nothing."
pkill -f "$P/chrome" 2>/dev/null

echo
echo "################ F. all four signals REAL ################"
clearlog
sudo -n env LOGFILE="$P/log" HOLD_FILE="$P/hold" CPU_STATE_FILE="$P/cpu" bash "$D" --check-once 2>/dev/null
echo "  exit=$?"
grep -E "SSH:|FILES:|WEB:|PROCS:" "$P/log" | sed 's/^/    /'

echo
echo "################ G. nothing stopped, nothing installed ################"
# pgrep -c prints 0 AND exits 1 when there is no match; capture, do not `|| echo`
naws=$(pgrep -fc 'aws[ ]ec2' 2>/dev/null)   # bracket: pattern cannot match itself
echo "  aws ec2 processes spawned      : ${naws:-0}"
echo "  dev2-idle-shutdown unit present: $(systemctl list-unit-files 2>/dev/null | grep -c dev2-idle-shutdown)"
echo "  /usr/local/bin/dev2-idle-*     : $(ls /usr/local/bin/dev2-idle-* 2>/dev/null | wc -l)"
echo "  leftover live-timer units      : $(systemctl list-units --all 2>/dev/null | grep -c dev2-idle-live-timer)"
echo "  instance state                 : running (you are reading this over ssh)"
