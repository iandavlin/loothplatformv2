#!/usr/bin/env bash
# dev2-idle-shutdown.sh -- dev2's own idle auto-off.
#
# dev2 ONLY. Never install on dev1 (it has its own daemon) and NEVER on live.
# A runtime guard refuses to stop any instance whose Name tag is not dev2*.
#
# IDLE = all FOUR signals quiet for IDLE_THRESHOLD_MIN minutes (recency, not
# presence -- buck drives dev2 from a desktop Claude over short-lived ssh
# commands, so "is a session open right now" would strand him):
#
#   1. SSH   -- no sshd "Accepted" line in the window
#   2. FILES -- no file mtime churn under the watched repo/worktree dirs
#   3. WEB   -- no non-bot /__heartbeat hit (a real visible browser tab;
#               nginx sub_filter fires it on mousemove/click/keydown only)
#   4. PROCS -- no interactive work: headless chrome, /tmp/ut-* CDP harness,
#               wp-cli, or a claude runner
#
# System timers (lg-wp-cron every 60s, bb-mirror-reconcile,
# lg-person-vis-refresh, ...) must NEVER count as activity or the box never
# sleeps. They are excluded structurally: a process only counts if its cgroup
# is under user.slice. Timer-run units live under system.slice.
#
# On idle: a COUNTDOWN_SECS countdown (wall + log). Any new ssh accept -- or
# any other signal going busy, or a hold -- cancels it. Then the box stops
# itself via `aws ec2 stop-instances` on an instance id resolved from IMDSv2
# at stop time (ids change on every rebuild -- never hardcode one).
#
# The box NEVER powers itself off: this account cannot read
# InstanceInitiatedShutdownBehavior, so `poweroff` might terminate rather than
# stop. If the AWS call is impossible, the daemon logs loudly and stays up --
# every failure path fails toward "leave the box running".
#
# Modes:
#   (no args)      run the daemon loop
#   --check-once   evaluate the four signals once, print + log, exit 0 if
#                  idle-eligible / 1 if busy. NEVER stops anything.
#   --status       show hold + countdown state
#
# Log: /var/log/dev2-idle-shutdown.log     Hold helper: idle-hold

set -uo pipefail

# ---- config (every value overridable; the unit passes the AWS_* ones) -------
LOGFILE=${LOGFILE:-/var/log/dev2-idle-shutdown.log}
INTERVAL=${INTERVAL:-60}
IDLE_THRESHOLD_MIN=${IDLE_THRESHOLD_MIN:-45}
COUNTDOWN_SECS=${COUNTDOWN_SECS:-300}
COUNTDOWN_TICK=${COUNTDOWN_TICK:-10}

HOLD_FILE=${HOLD_FILE:-/tmp/no-idle-shutdown}
DRYRUN_FILE=${DRYRUN_FILE:-/tmp/dev2-idle-dryrun}
COUNTDOWN_FILE=${COUNTDOWN_FILE:-/tmp/dev2-idle-countdown}
CANCEL_FILE=${CANCEL_FILE:-/tmp/dev2-idle-cancel}
CPU_STATE_FILE=${CPU_STATE_FILE:-/tmp/dev2-idle-cpu-state}

# signal sources
SSH_SOURCE=${SSH_SOURCE:-journal}            # journal | file:<path of epochs>
SSH_JOURNAL_UNIT=${SSH_JOURNAL_UNIT:-ssh}
HEARTBEAT_LOG=${HEARTBEAT_LOG:-/var/log/nginx/heartbeat.log}
WATCH_DIRS=${WATCH_DIRS:-/home/buck/loothplatformv2:/home/buck/worktrees:/home/ubuntu/worktrees:/home/ubuntu/loothplatformv2-clean}
CHROME_EXE_DIR=${CHROME_EXE_DIR:-/opt/lg-chrome/}
UT_GLOB=${UT_GLOB:-/tmp/ut-*}
WP_BIN=${WP_BIN:-/usr/local/bin/wp}
CLAUDE_NAME=${CLAUDE_NAME:-claude}
CLAUDE_PAT=${CLAUDE_PAT:-claude-code}
CHROME_CPU_BUSY_SECS=${CHROME_CPU_BUSY_SECS:-2}

# Timer units that must never register as activity. The user.slice rule below
# already excludes them; this is the belt to that braces.
EXCLUDED_UNITS=${EXCLUDED_UNITS:-lg-wp-cron|bb-mirror-reconcile|lg-person-vis-refresh|phpsessionclean|sysstat-collect|fwupd-refresh|motd-news|apt-daily|apt-daily-upgrade|man-db|certbot|update-notifier-download|systemd-tmpfiles-clean|dpkg-db-backup|logrotate|dev2-idle-shutdown}

# Crawlers can reach /__heartbeat (it is gate-exempt), but only a real browser
# runs the JS that POSTs it. This filter drops the ones that fake a hit.
BOT_UA_PAT=${BOT_UA_PAT:-bot|crawl|spider|slurp|facebookexternalhit|bingpreview|curl/|wget|python-requests|Go-http-client|libwww|okhttp|uptime|pingdom|monitoring}

# stop path
AWS_BIN=${AWS_BIN:-/usr/local/bin/aws}
AWS_SHARED_CREDENTIALS_FILE=${AWS_SHARED_CREDENTIALS_FILE:-/home/looth-dev/.aws/credentials}
AWS_CONFIG_FILE=${AWS_CONFIG_FILE:-/home/looth-dev/.aws/config}
AWS_DEFAULT_REGION=${AWS_DEFAULT_REGION:-us-east-1}
export AWS_SHARED_CREDENTIALS_FILE AWS_CONFIG_FILE AWS_DEFAULT_REGION
REQUIRE_NAME_PREFIX=${REQUIRE_NAME_PREFIX:-dev2}
INSTANCE_ID_OVERRIDE=${INSTANCE_ID_OVERRIDE:-}   # test hook; skips IMDS

CUTOFF=0   # recomputed each pass

log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" >> "$LOGFILE" 2>/dev/null; }
say() { [[ ${VERBOSE:-0} == 1 ]] && printf '%s\n' "$1"; log "$1"; }

# ---- hold file -------------------------------------------------------------
# numeric content = epoch expiry (self-expiring; the daemon deletes it once
# past due). Anything else, including an empty `touch`, holds indefinitely.
hold_active() {
    [[ -f "$HOLD_FILE" ]] || return 1
    local content now expiry
    content=$(tr -d '[:space:]' < "$HOLD_FILE" 2>/dev/null || true)
    if [[ "$content" =~ ^[0-9]+$ ]]; then
        expiry=$(( 10#$content )); now=$(date +%s)
        if (( now < expiry )); then
            log "  HOLD: timed hold until $(date -d "@${expiry}" '+%Y-%m-%d %H:%M:%S') -- skipping"
            return 0
        fi
        rm -f "$HOLD_FILE"
        log "  HOLD: timed hold expired ($(date -d "@${expiry}" '+%Y-%m-%d %H:%M:%S')) -- ${HOLD_FILE} deleted, checks resume"
        return 1
    fi
    log "  HOLD: indefinite hold (${HOLD_FILE} present) -- skipping"
    return 0
}

# ---- proc helpers ----------------------------------------------------------
proc_exe()        { readlink -f "/proc/$1/exe" 2>/dev/null; }
proc_cmd()        { tr '\0' ' ' < "/proc/$1/cmdline" 2>/dev/null; }
# ps field as a bare integer; $2 is the fallback when the pid is already gone
ps_int()          { local v; v=$(ps -o "$1=" -p "$3" 2>/dev/null | tr -dc '0-9'); printf '%s' "${v:-$2}"; }
in_user_slice()   { grep -q 'user\.slice' "/proc/$1/cgroup" 2>/dev/null; }
in_excluded_unit(){ grep -qE "system\.slice/(${EXCLUDED_UNITS})\.service" "/proc/$1/cgroup" 2>/dev/null; }

# A process counts as interactive work only if it is really that program (its
# /proc/<pid>/exe says so) AND it lives in a user session. Matching on cmdline
# alone is a trap: any shell that merely MENTIONS the pattern -- including the
# pgrep that hunts for it -- matches itself.
interactive_pid() {
    local pid=$1
    [[ $pid == "$$" ]] && return 1
    in_user_slice "$pid" || return 1
    in_excluded_unit "$pid" && return 1
    return 0
}

# ---- signal 1: ssh ---------------------------------------------------------
check_ssh() {
    local n=0 since
    if [[ "$SSH_SOURCE" == file:* ]]; then
        local f=${SSH_SOURCE#file:}
        [[ -r "$f" ]] && n=$(awk -v c="$CUTOFF" '$1+0 >= c' "$f" 2>/dev/null | wc -l)
    else
        since=$(date -d "@${CUTOFF}" '+%Y-%m-%d %H:%M:%S')
        n=$(journalctl -u "$SSH_JOURNAL_UNIT" --since "$since" --no-pager 2>/dev/null | grep -c 'Accepted ')
    fi
    if (( n > 0 )); then
        log "  SSH: ${n} accept(s) within ${IDLE_THRESHOLD_MIN}m -- BUSY"; return 1
    fi
    log "  SSH: no accepts within ${IDLE_THRESHOLD_MIN}m -- quiet"; return 0
}

# ---- signal 2: file mtime churn -------------------------------------------
check_files() {
    local d found dirs=()
    IFS=: read -ra dirs <<< "$WATCH_DIRS"
    for d in "${dirs[@]}"; do
        [[ -d "$d" ]] || continue
        found=$(find "$d" -xdev -mmin "-${IDLE_THRESHOLD_MIN}" -type f \
                ! -name '*.lock' ! -name '*.swp' -print -quit 2>/dev/null)
        if [[ -n "$found" ]]; then
            log "  FILES: recent write under ${d} (e.g. ${found}) -- BUSY"; return 1
        fi
    done
    log "  FILES: no writes within ${IDLE_THRESHOLD_MIN}m -- quiet"; return 0
}

# ---- signal 3: real browser tab (/__heartbeat) -----------------------------
# Deliberately NOT a scan of the raw access log: WP cron, autosave, the PWA
# service worker and crawlers all hit it with no human present (dev1 learned
# this and removed its access-log scan). /__heartbeat only fires from JS on a
# visible tab, so a gate-blocked bot 403 can never trip it.
check_web() {
    local f hit ts epoch
    for f in "$HEARTBEAT_LOG" "${HEARTBEAT_LOG}.1"; do
        [[ -r "$f" ]] || continue
        [[ -n $(find "$f" -mmin "-${IDLE_THRESHOLD_MIN}" -print -quit 2>/dev/null) ]] || continue
        hit=$(tac "$f" 2>/dev/null | grep -viE "$BOT_UA_PAT" | head -1)
        [[ -n "$hit" ]] || continue
        ts=$(sed -nE 's/.*\[([^]]+)\].*/\1/p' <<< "$hit")   # 09/Jul/2026:00:36:31 +0000
        ts=${ts//\// }                                       # 09 Jul 2026:00:36:31 +0000
        ts=${ts/:/ }                                         # 09 Jul 2026 00:36:31 +0000
        epoch=$(date -d "$ts" +%s 2>/dev/null) || continue
        if (( epoch >= CUTOFF )); then
            log "  WEB: human heartbeat at $(date -d "@${epoch}" '+%H:%M:%S') ($(basename "$f")) -- BUSY"
            return 1
        fi
    done
    log "  WEB: no non-bot /__heartbeat within ${IDLE_THRESHOLD_MIN}m -- quiet"; return 0
}

# ---- signal 4: interactive work processes ----------------------------------
# Headless chrome that is merely LEAKED (alive, zero CPU, cold profile dir) is
# not work -- counting it would pin the box awake forever, which is exactly the
# leak housekeeping keeps reaping. So chrome counts only when it is doing
# something: burning CPU, freshly started, or writing its CDP profile dir.
check_procs() {
    local pid exe cmd d total=0 prev=0 delta pids=()

    # /tmp/ut-* CDP harness dirs with fresh writes
    for d in $UT_GLOB; do
        [[ -d "$d" ]] || continue
        if [[ -n $(find "$d" -xdev -mmin "-${IDLE_THRESHOLD_MIN}" -print -quit 2>/dev/null) ]]; then
            log "  PROCS: active CDP harness dir ${d} -- BUSY"; return 1
        fi
    done

    # chrome under /opt/lg-chrome
    local cpu age young=0
    for pid in $(pgrep -f "$CHROME_EXE_DIR" 2>/dev/null); do
        exe=$(proc_exe "$pid"); [[ "$exe" == "$CHROME_EXE_DIR"* ]] || continue
        interactive_pid "$pid" || continue
        pids+=("$pid")
        cpu=$(ps_int times 0 "$pid");        total=$(( total + cpu ))
        age=$(ps_int etimes 999999 "$pid");  (( age < IDLE_THRESHOLD_MIN * 60 )) && young=$pid
    done
    if (( ${#pids[@]} > 0 )); then
        prev=$(tr -dc '0-9' < "$CPU_STATE_FILE" 2>/dev/null); prev=${prev:-0}
        printf '%s\n' "$total" > "$CPU_STATE_FILE"
        if (( young > 0 )); then
            log "  PROCS: chrome pid ${young} started within window -- BUSY"; return 1
        fi
        delta=$(( total - prev ))
        if (( delta >= CHROME_CPU_BUSY_SECS )); then
            log "  PROCS: chrome burning CPU (+${delta}s this interval, ${#pids[@]} pids) -- BUSY"; return 1
        fi
        log "  PROCS: ${#pids[@]} chrome pid(s) alive but idle (+${delta}s CPU, cold profile dir) -- not counted"
    else
        rm -f "$CPU_STATE_FILE"
    fi

    # wp-cli run by a human (timer-run wp lives in system.slice -> excluded)
    for pid in $(pgrep -f "$WP_BIN" 2>/dev/null); do
        exe=$(proc_exe "$pid"); [[ "$(basename "${exe:-}")" == php* ]] || continue
        cmd=$(proc_cmd "$pid"); [[ "$cmd" == *"$WP_BIN"* ]] || continue
        interactive_pid "$pid" || continue
        log "  PROCS: interactive wp-cli pid ${pid} -- BUSY"; return 1
    done

    # a claude runner in a user session
    for pid in $(pgrep -x "$CLAUDE_NAME" 2>/dev/null; pgrep -f "$CLAUDE_PAT" 2>/dev/null); do
        exe=$(proc_exe "$pid")
        case "$(basename "${exe:-}")" in node|bun|claude) ;; *) continue ;; esac
        interactive_pid "$pid" || continue
        log "  PROCS: claude runner pid ${pid} -- BUSY"; return 1
    done

    log "  PROCS: no interactive work -- quiet"; return 0
}

# ---- verdict ---------------------------------------------------------------
is_idle() {
    CUTOFF=$(( $(date +%s) - IDLE_THRESHOLD_MIN * 60 ))
    local s=0 f=0 w=0 p=0
    check_ssh   || s=1
    check_files || f=1
    check_web   || w=1
    check_procs || p=1
    SIG_SUMMARY="ssh=$( ((s)) && echo busy || echo quiet) files=$( ((f)) && echo busy || echo quiet) web=$( ((w)) && echo busy || echo quiet) procs=$( ((p)) && echo busy || echo quiet)"
    (( s + f + w + p == 0 ))
}

# ---- stop ------------------------------------------------------------------
imds_instance_id() {
    [[ -n "$INSTANCE_ID_OVERRIDE" ]] && { printf '%s\n' "$INSTANCE_ID_OVERRIDE"; return 0; }
    local tok id
    tok=$(curl -s -X PUT 'http://169.254.169.254/latest/api/token' \
          -H 'X-aws-ec2-metadata-token-ttl-seconds: 60' --max-time 3 2>/dev/null)
    [[ -n "$tok" ]] || return 1
    id=$(curl -s -H "X-aws-ec2-metadata-token: $tok" --max-time 3 \
         'http://169.254.169.254/latest/meta-data/instance-id' 2>/dev/null)
    [[ "$id" =~ ^i-[0-9a-f]+$ ]] || return 1
    printf '%s\n' "$id"
}

# Refuses on every uncertainty: no id, no tag, wrong tag. Never powers off.
do_stop() {
    local id name
    if ! id=$(imds_instance_id); then
        log "!! STOP ABORTED: cannot resolve own instance-id from IMDSv2 -- staying up"; return 1
    fi
    name=$("$AWS_BIN" ec2 describe-instances --instance-ids "$id" \
           --query 'Reservations[].Instances[].Tags[?Key==`Name`].Value|[0][0]' \
           --output text 2>>"$LOGFILE")
    if [[ -z "$name" || "$name" == "None" ]]; then
        log "!! STOP ABORTED: cannot read Name tag of ${id} -- staying up"; return 1
    fi
    if [[ "$name" != "$REQUIRE_NAME_PREFIX"* ]]; then
        log "!! SAFETY GUARD: Name tag '${name}' (${id}) is not ${REQUIRE_NAME_PREFIX}* -- REFUSING to stop."
        log "!! This daemon is dev2-only. It must not be installed on dev1 or live."
        return 1
    fi
    if [[ -f "$DRYRUN_FILE" ]]; then
        log ">>> DRY-RUN: would run: ${AWS_BIN} ec2 stop-instances --instance-ids ${id}  (name=${name}) -- NOT executed <<<"
        return 0
    fi
    log ">>> stopping ${id} (${name}) <<<"
    "$AWS_BIN" ec2 stop-instances --instance-ids "$id" >> "$LOGFILE" 2>&1 \
        && log ">>> stop-instances accepted <<<" \
        || { log "!! stop-instances FAILED -- staying up"; return 1; }
}

announce() {
    command -v wall >/dev/null 2>&1 && wall -n "$1" 2>/dev/null || true
}

cleanup_countdown() { rm -f "$COUNTDOWN_FILE" "$CANCEL_FILE"; }

# ---- one-shot modes --------------------------------------------------------
case "${1:-}" in
    --check-once)
        VERBOSE=1
        if is_idle; then
            say "RESULT: idle_eligible=yes  ${SIG_SUMMARY}  (threshold=${IDLE_THRESHOLD_MIN}m)"; exit 0
        fi
        say "RESULT: idle_eligible=no   ${SIG_SUMMARY}  (threshold=${IDLE_THRESHOLD_MIN}m)"; exit 1
        ;;
    --status)
        if [[ -f "$HOLD_FILE" ]]; then echo "hold: present ($(cat "$HOLD_FILE" 2>/dev/null))"; else echo "hold: none (armed)"; fi
        if [[ -f "$COUNTDOWN_FILE" ]]; then echo "countdown: RUNNING, stop at $(date -d "@$(cat "$COUNTDOWN_FILE")" '+%H:%M:%S')"; else echo "countdown: none"; fi
        [[ -f "$DRYRUN_FILE" ]] && echo "dry-run: ON (no stop will be executed)"
        exit 0
        ;;
    "") ;;
    *) echo "usage: $0 [--check-once|--status]" >&2; exit 2 ;;
esac

# ---- daemon loop -----------------------------------------------------------
log "=== dev2-idle-shutdown started (interval=${INTERVAL}s, idle=${IDLE_THRESHOLD_MIN}m, countdown=${COUNTDOWN_SECS}s$([[ -f "$DRYRUN_FILE" ]] && echo ', DRY-RUN')) ==="
cleanup_countdown
rm -f "$CPU_STATE_FILE"

while true; do
    sleep "$INTERVAL"
    log "--- check ---"

    if hold_active; then cleanup_countdown; continue; fi

    if ! is_idle; then
        log "  RESULT: active -- ${SIG_SUMMARY}"
        cleanup_countdown
        continue
    fi

    log ">>> ALL QUIET ${IDLE_THRESHOLD_MIN}m (${SIG_SUMMARY}) -- ${COUNTDOWN_SECS}s countdown <<<"
    announce "dev2: idle ${IDLE_THRESHOLD_MIN}m -- auto-stop in $(( COUNTDOWN_SECS / 60 )) min. Any ssh login cancels it, or run: idle-hold 1h"
    stop_at=$(( $(date +%s) + COUNTDOWN_SECS ))
    echo "$stop_at" > "$COUNTDOWN_FILE"

    elapsed=0
    while (( elapsed < COUNTDOWN_SECS )); do
        sleep "$COUNTDOWN_TICK"
        elapsed=$(( elapsed + COUNTDOWN_TICK ))
        remaining=$(( COUNTDOWN_SECS - elapsed ))

        if [[ -f "$CANCEL_FILE" ]]; then
            log ">>> COUNTDOWN CANCELLED by ${CANCEL_FILE} <<<"; cleanup_countdown; break
        fi
        if hold_active; then
            log ">>> COUNTDOWN CANCELLED -- hold set <<<"; announce "dev2: auto-stop cancelled (hold)."; cleanup_countdown; break
        fi

        # ssh is the cheap, high-stakes check -- every tick. buck logging in
        # mid-countdown must never lose the box.
        CUTOFF=$(( $(date +%s) - IDLE_THRESHOLD_MIN * 60 ))
        if ! check_ssh; then
            log ">>> COUNTDOWN CANCELLED -- ssh login <<<"; announce "dev2: auto-stop cancelled (ssh login)."; cleanup_countdown; break
        fi
        # the full sweep is heavier; every 3rd tick is enough
        if (( elapsed % (COUNTDOWN_TICK * 3) == 0 )) && ! is_idle; then
            log ">>> COUNTDOWN CANCELLED -- activity (${SIG_SUMMARY}) <<<"; announce "dev2: auto-stop cancelled (activity)."; cleanup_countdown; break
        fi

        (( remaining % 60 == 0 )) && log "  COUNTDOWN: ${remaining}s remaining"
        (( remaining == 60 )) && announce "dev2: auto-stop in 60s. 'idle-hold 1h' cancels."
        echo "$stop_at" > "$COUNTDOWN_FILE"
    done

    if [[ -f "$COUNTDOWN_FILE" ]]; then
        log ">>> COUNTDOWN COMPLETE <<<"
        cleanup_countdown
        if do_stop; then
            [[ -f "$DRYRUN_FILE" ]] && { log "  (dry-run: daemon keeps running)"; continue; }
            exit 0
        fi
        # refused / failed -- do not hammer AWS every minute
        log "  stop did not happen; backing off ${IDLE_THRESHOLD_MIN}m before re-checking"
        sleep $(( IDLE_THRESHOLD_MIN * 60 ))
    fi
done
