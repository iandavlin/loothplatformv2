#!/usr/bin/env bash
set -euo pipefail

# Every setting below may be overridden from the environment. systemd starts
# this unit with a clean env, so in production the defaults always win; the
# overrides exist so tools/dev1-idle/selftest.sh can run the REAL script in a
# sandbox (own log, own state, mocked STOP_CMD) instead of a forked copy.
LOGFILE="${IDLE_LOGFILE:-/var/log/idle-shutdown.log}"
INTERVAL="${IDLE_INTERVAL:-60}"
IDLE_THRESHOLD="${IDLE_THRESHOLD:-10}"
OVERRIDE_FILE="${IDLE_OVERRIDE_FILE:-/tmp/no-idle-shutdown}"
DRYRUN_FILE="${IDLE_DRYRUN_FILE:-/tmp/idle-shutdown-dryrun}"
COUNTDOWN_FILE="${IDLE_COUNTDOWN_FILE:-/tmp/idle-shutdown-countdown}"
CANCEL_FILE="${IDLE_CANCEL_FILE:-/tmp/idle-shutdown-cancel}"
STOP_CMD="${IDLE_STOP_CMD:-/snap/bin/aws ec2 stop-instances --instance-ids i-01e54ed6c9a4ba91e}"
ACTIVITY_FILE="${IDLE_ACTIVITY_FILE:-/tmp/last-ccdev-activity}"
HOME_DIR="${IDLE_HOME_DIR:-/home/ubuntu}"
# Every team home's Claude conversation dir, and the browser heartbeat log.
CLAUDE_HOME_GLOB="${IDLE_CLAUDE_HOME_GLOB:-/home/*/.claude/projects/}"
HEARTBEAT_LOG="${IDLE_HEARTBEAT_LOG:-/var/log/nginx/heartbeat.log}"
COUNTDOWN_SECS="${IDLE_COUNTDOWN_SECS:-300}"
EMAIL_TO="${IDLE_EMAIL_TO:-ian.davlin@gmail.com}"
MAIL_CMD="${IDLE_MAIL_CMD:-msmtp}"
EMAIL_SENT_FILE="${IDLE_EMAIL_SENT_FILE:-/tmp/idle-shutdown-email-sent}"
EMAIL_IDLE_THRESHOLD="${IDLE_EMAIL_THRESHOLD:-60}"  # minutes before sending idle email

# --- worker activity (tmux panes / claude / headless engines) --------------
# State lives under /run: it is wiped on boot, which is what we want since it
# holds pids. Both files are recreated on the first pass after a (re)start.
WORKER_STATE_DIR="${IDLE_WORKER_STATE_DIR:-/run/idle-shutdown}"
WORKER_CPU_STATE="${WORKER_STATE_DIR}/worker-cpu"
WORKER_STAMP="${WORKER_STATE_DIR}/last-worker-activity"
WORKER_PASS_STAMP="${WORKER_STATE_DIR}/last-pass"
# CPU jiffies per 60s that count as real work -- see check_worker_activity().
# 150 sits between what was measured on this box: parked claude 12-84 j/min,
# working claude 348-1296 j/min, leaked chrome a flat 0.
WORK_JIFFIES="${IDLE_WORK_JIFFIES:-150}"
# Process comms that ARE the work (exact comm, verified through /proc/pid/exe).
WORKER_COMMS="${IDLE_WORKER_COMMS:-claude}"
# Engines: only ever counted alongside a live worker (they leak at 0% CPU).
ENGINE_COMMS="${IDLE_ENGINE_COMMS:-chrome}"
TMUX_SOCKET_GLOB="${IDLE_TMUX_SOCKET_GLOB:-/tmp/tmux-*/*}"
# A pane sitting at one of these is a prompt, not work.
SHELL_CMDS="${IDLE_SHELL_CMDS:-bash sh zsh dash fish tmux}"

WATCH_DIRS=(
    "${HOME_DIR}/.claude"
    "${HOME_DIR}/projects"
)

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $1" >> "$LOGFILE"; }

# Hold override: ${OVERRIDE_FILE} suspends auto-off while present.
#   numeric content            = epoch expiry; once past due the file is
#                                DELETED here and checks resume (logged)
#   non-numeric/empty content  = indefinite hold (legacy `touch` behavior)
# Manage by hand with /usr/local/bin/idle-hold. Returns 0 while held.
hold_active() {
    [[ -f "$OVERRIDE_FILE" ]] || return 1
    local content now expiry
    content=$(tr -d '[:space:]' < "$OVERRIDE_FILE" 2>/dev/null || true)
    if [[ "$content" =~ ^[0-9]+$ ]]; then
        expiry=$(( 10#$content ))
        now=$(date +%s)
        if (( now < expiry )); then
            log "  OVERRIDE: timed hold until $(date -d "@${expiry}" '+%Y-%m-%d %H:%M:%S') -- skipping"
            return 0
        fi
        rm -f "$OVERRIDE_FILE"
        log "  OVERRIDE: timed hold expired ($(date -d "@${expiry}" '+%Y-%m-%d %H:%M:%S')) -- ${OVERRIDE_FILE} deleted, checks resume"
        return 1
    fi
    log "  OVERRIDE: indefinite hold (${OVERRIDE_FILE} present) -- skipping"
    return 0
}

check_tty_idle() {
    local sessions
    sessions=$(w -hs 2>/dev/null || true)
    if [[ -z "$sessions" ]]; then
        log "  TTY: no sessions -- idle"
        return 0
    fi
    while IFS= read -r line; do
        local idle
        idle=$(echo "$line" | awk '{print $3}')
        if [[ "$idle" =~ ^([0-9]+)\.([0-9]+)s$ ]]; then
            log "  TTY: session active (idle=${idle})"
            return 1
        elif [[ "$idle" =~ ^([0-9]+):([0-9]+)$ ]]; then
            local hours="${BASH_REMATCH[1]}" mins="${BASH_REMATCH[2]}"
            local total_mins=$(( hours * 60 + mins ))
            if (( total_mins < IDLE_THRESHOLD )); then
                log "  TTY: session active (idle=${idle}, ${total_mins}m)"
                return 1
            fi
        elif [[ "$idle" =~ ^([0-9]+)m$ ]]; then
            if (( ${BASH_REMATCH[1]} < IDLE_THRESHOLD )); then
                log "  TTY: session active (idle=${idle})"
                return 1
            fi
        fi
    done <<< "$sessions"
    log "  TTY: all sessions idle ${IDLE_THRESHOLD}+ min"
    return 0
}

check_browser_heartbeat() {
    # Dedicated heartbeat endpoint (legacy)
    local hb_log="$HEARTBEAT_LOG"
    if [[ -f "$hb_log" ]]; then
        local recent
        recent=$(find "$hb_log" -mmin -"${IDLE_THRESHOLD}" 2>/dev/null)
        if [[ -n "$recent" ]]; then
            last_hit=$(date -r "$hb_log" '+%Y-%m-%d %H:%M:%S')
            log "  HEARTBEAT: browser activity within ${IDLE_THRESHOLD}m (last: ${last_hit})"
            return 1
        fi
    fi

    # NOTE: removed access-log scan. Background WP heartbeat / autosave / cron
    # all hit the access log without any human present. The /__heartbeat
    # snippet (now also injected on dev.loothgroup + dev.loothtool) is the
    # only signal that proves a real visible tab with mouse/keyboard input.
    return 0
}

check_file_activity() {
    # Check SSH activity touch file
    if [[ -f "$ACTIVITY_FILE" ]]; then
        local age
        age=$(find "$ACTIVITY_FILE" -mmin -"${IDLE_THRESHOLD}" 2>/dev/null)
        if [[ -n "$age" ]]; then
            log "  FILES: recent SSH activity (${ACTIVITY_FILE})"
            return 1
        fi
    fi

    # Check Claude conversation files (.jsonl) across ALL team home dirs
    local conv_found
    conv_found=$(find $CLAUDE_HOME_GLOB -maxdepth 3 -name "*.jsonl" -mmin -"${IDLE_THRESHOLD}" 2>/dev/null | head -1)
    if [[ -n "$conv_found" ]]; then
        local who
        who=$(echo "$conv_found" | sed 's|/home/\([^/]*\)/.*|\1|')
        log "  FILES: recent Claude conversation activity ($who)"
        return 1
    fi

    # NOTE: removed raw code-server access-log check.
    # Reason: code-server's internal WebSocket/AJAX keepalives fire even with
    # an idle tab, so they generated false "active" signals. Real user activity
    # is now captured by the smart heartbeat (/__heartbeat) injected into each
    # per-user code-server's HTML, which only fires on visible-tab + mousemove/
    # click/keydown. That hits /var/log/nginx/heartbeat.log which the heartbeat
    # check above already watches.

    # Check user project dirs (exclude locks, telemetry, heartbeat)
    for dir in "${WATCH_DIRS[@]}"; do
        if [[ -d "$dir" ]]; then
            local found
            found=$(find "$dir" -maxdepth 4 -mmin -"${IDLE_THRESHOLD}" -type f \
                ! -name "*.lock" ! -name "heartbeat" ! -name "*Telemetry*" \
                ! -name "*.log" ! -path "*/code-server/*" \
                2>/dev/null | head -1)
            if [[ -n "$found" ]]; then
                log "  FILES: recent activity in ${dir} (e.g. $(basename "$found"))"
                return 1
            fi
        fi
    done
    log "  FILES: no recent activity -- idle"
    return 0
}

# --- worker activity -------------------------------------------------------
# Why this exists: on 2026-07-26 at 02:59 the box powered off mid-work and took
# every lane with it. To the checks above, "busy" and "idle" look identical --
# a lane grinding through a long tool call writes no .jsonl and touches no
# watched file, so TTY/FILES/HEARTBEAT all read idle while real work is going
# on. This check asks the process table directly instead of inferring.
#
# Traps this deliberately avoids (memory reference_proc_activity_detection_traps,
# plus two more measured on dev1 while writing it):
#
#   * `pgrep -f claude` matches the hunting command's OWN cmdline. Workers are
#     matched on comm (pgrep -x) and confirmed through /proc/<pid>/exe; cmdline
#     is never evidence.
#   * cgroup slice does NOT discriminate here. On dev2, timers live in
#     system.slice and human work in user.slice -- on dev1 the claude workers
#     are children of code-server and therefore sit in system.slice too.
#     Filtering on user.slice would drop every real worker on this box.
#   * a leaked headless chrome sits at 0% CPU forever (one was doing exactly
#     that while this was written). Engines are only ever counted alongside a
#     live worker, so a leak on its own can never pin the box awake.
#   * a PARKED claude is not free either: it burns ~1 jiffy/s doing nothing.
#     Presence therefore cannot mean "busy". Measured on dev1: parked ~54
#     jiffies/min vs 348-1160 for one actually working. WORK_JIFFIES sits
#     between the two, and is compared per process TREE -- never summed across
#     workers, or five parked lanes would add up to "busy" indefinitely.
#   * `pgrep -c` prints 0 AND exits 1, so `$(pgrep -c ... || echo 0)` prints
#     "0" twice. Nothing here pipes through pgrep -c.

declare -A PROC_PPID PROC_COMM PROC_JIFF PROC_CJIFF PROC_START PROC_KIDS

# One pass over /proc. comm can contain spaces and parens, so fields are taken
# relative to the LAST ')' rather than by splitting the whole line.
scan_proc() {
    PROC_PPID=(); PROC_COMM=(); PROC_JIFF=(); PROC_CJIFF=(); PROC_START=(); PROC_KIDS=()
    local d pid line comm rest
    local -a f
    for d in /proc/[0-9]*; do
        pid=${d#/proc/}
        [[ -r "$d/stat" ]] || continue
        line=$(< "$d/stat") 2>/dev/null || continue
        [[ -n "$line" ]] || continue
        comm=${line#*(}; comm=${comm%)*}
        rest=${line##*) }
        read -r -a f <<< "$rest"
        # f[] is 0-indexed from field 3 (state): ppid=f[1] utime=f[11]
        # stime=f[12] cutime=f[13] cstime=f[14] starttime=f[19]
        [[ -n "${f[19]:-}" ]] || continue
        PROC_PPID[$pid]=${f[1]}
        PROC_COMM[$pid]=$comm
        PROC_JIFF[$pid]=$(( f[11] + f[12] ))
        PROC_CJIFF[$pid]=$(( f[13] + f[14] ))
        PROC_START[$pid]=${f[19]}
        PROC_KIDS[${f[1]}]+="$pid "
    done
}

# CPU charged to a whole process tree: the root's own time, plus the time of
# children it has already reaped (cutime/cstime), plus the time of descendants
# still running. A build that exits between passes moves from the third term to
# the second, so the total never goes backwards.
tree_jiffies() {
    # NOTE: these MUST be separate statements. Bash expands every word of a
    # `local a=$1 b=$((...$a...))` command BEFORE performing any of its
    # assignments, so the arithmetic would read $root while it is still unset
    # -- which under `set -u` aborts the function and silently returns nothing.
    local root=$1
    local total=$(( PROC_JIFF[$root] + PROC_CJIFF[$root] ))
    local -a queue=( ${PROC_KIDS[$root]:-} )
    local cur
    while (( ${#queue[@]} )); do
        cur=${queue[0]}; queue=( "${queue[@]:1}" )
        [[ -n "${PROC_JIFF[$cur]:-}" ]] || continue
        total=$(( total + PROC_JIFF[$cur] ))
        queue+=( ${PROC_KIDS[$cur]:-} )
    done
    echo "$total"
}

# Is $1 really the binary we think it is? cmdline lies, /proc/<pid>/exe does
# not. Unreadable exe (a process we do not own) counts as a match on purpose:
# staying awake on doubt is the cheap mistake, shutting down mid-work is not.
exe_matches() {
    local pid=$1 want=$2 exe
    exe=$(readlink "/proc/$pid/exe" 2>/dev/null) || return 0
    [[ -z "$exe" || "$exe" == *"$want"* ]]
}

# ROOTS: process trees whose CPU burn counts as work.
collect_roots() {
    ROOTS=()
    local -A seen=()
    local pid comm want sock ppid pcmd s

    # (b) worker binaries -- claude and anything else in WORKER_COMMS
    for want in $WORKER_COMMS; do
        for pid in $(pgrep -x "$want" 2>/dev/null || true); do
            if exe_matches "$pid" "$want" && [[ -n "${PROC_START[$pid]:-}" ]]; then
                if [[ -z "${seen[$pid]:-}" ]]; then seen[$pid]=1; ROOTS+=("$pid"); fi
            fi
        done
    done
    WORKER_ROOTS=${#ROOTS[@]}

    # (a) tmux panes running something other than a bare shell prompt. The
    # pane's own pid is the shell; its foreground job is a descendant, which
    # tree_jiffies() already walks into.
    for sock in $TMUX_SOCKET_GLOB; do
        [[ -S "$sock" ]] || continue
        while IFS='|' read -r ppid pcmd; do
            [[ -n "$ppid" && -n "${PROC_START[$ppid]:-}" ]] || continue
            for s in $SHELL_CMDS; do
                if [[ "$pcmd" == "$s" ]]; then continue 2; fi
            done
            if [[ -z "${seen[$ppid]:-}" ]]; then seen[$ppid]=1; ROOTS+=("$ppid"); fi
        done < <(tmux -S "$sock" list-panes -a -F '#{pane_pid}|#{pane_current_command}' 2>/dev/null || true)
    done
    if (( ${#ROOTS[@]} > WORKER_ROOTS )); then WORKER_ROOTS=${#ROOTS[@]}; fi

    # (c) engines. ONLY alongside a live worker: a leaked headless chrome sits
    # at 0% CPU forever, and counting it on its own would keep the box up until
    # someone noticed the bill.
    if (( WORKER_ROOTS == 0 )); then return 0; fi
    for want in $ENGINE_COMMS; do
        for pid in $(pgrep -x "$want" 2>/dev/null || true); do
            if exe_matches "$pid" "$want" && [[ -n "${PROC_START[$pid]:-}" ]]; then
                if [[ -z "${seen[$pid]:-}" ]]; then seen[$pid]=1; ROOTS+=("$pid"); fi
            fi
        done
    done
}

# Returns 0 = idle (nothing working), 1 = active. Same convention as the checks
# above so is_idle() can just AND them together.
check_worker_activity() {
    local now; now=$(date +%s)
    mkdir -p "$WORKER_STATE_DIR" 2>/dev/null || true
    scan_proc

    # An attached tmux client means a terminal is literally connected to this
    # box right now. Nothing else needs proving.
    local sock clients
    for sock in $TMUX_SOCKET_GLOB; do
        [[ -S "$sock" ]] || continue
        clients=$(tmux -S "$sock" list-clients -F '#{client_tty}' 2>/dev/null || true)
        if [[ -n "$clients" ]]; then
            log "  WORKER: tmux client attached on ${sock##*/} -- active"
            echo "$now" > "$WORKER_STAMP"
            return 1
        fi
    done

    ROOTS=(); WORKER_ROOTS=0
    collect_roots

    # If the state dir is unusable we cannot measure a delta at all. Fall back
    # to presence alone and say so: an unwritable /run is an anomaly, and
    # keeping the box up wrongly costs money, while shutting it down wrongly
    # costs someone's work.
    if [[ ! -d "$WORKER_STATE_DIR" || ! -w "$WORKER_STATE_DIR" ]]; then
        if (( WORKER_ROOTS > 0 )); then
            log "  WORKER: ${WORKER_STATE_DIR} unwritable -- DEGRADED, holding on presence of ${WORKER_ROOTS} worker(s)"
            return 1
        fi
        log "  WORKER: ${WORKER_STATE_DIR} unwritable -- DEGRADED, but no workers present -- idle"
        return 0
    fi

    # Scale the threshold to the window we actually measured, not to INTERVAL:
    # the countdown loop re-checks every 10s, and billing a 60s threshold to a
    # 10s sample would make those re-checks six times harder to trip.
    local elapsed=$INTERVAL lastpass
    if [[ -f "$WORKER_PASS_STAMP" ]]; then
        lastpass=$(< "$WORKER_PASS_STAMP")
        if [[ "$lastpass" =~ ^[0-9]+$ ]] && (( now > lastpass )); then
            elapsed=$(( now - lastpass ))
        fi
    fi
    echo "$now" > "$WORKER_PASS_STAMP"
    local need=$(( WORK_JIFFIES * elapsed / 60 ))
    if (( need < 1 )); then need=1; fi

    local -A prev=()
    local p st j
    if [[ -f "$WORKER_CPU_STATE" ]]; then
        while read -r p st j; do prev["${p}:${st}"]=$j; done < "$WORKER_CPU_STATE"
    fi

    local started="" best=0 bestpid="" key delta pid
    : > "${WORKER_CPU_STATE}.new"
    for pid in ${ROOTS[@]+"${ROOTS[@]}"}; do
        st=${PROC_START[$pid]:-}
        [[ -n "$st" ]] || continue
        j=$(tree_jiffies "$pid")
        # is_idle() calls this function on the left of &&, which suppresses
        # `set -e` for everything inside it -- a failure here would be silent.
        # Refuse to record a measurement we do not trust; the pid then reads as
        # "new" next pass, which errs toward keeping the box up.
        if [[ ! "$j" =~ ^[0-9]+$ ]]; then continue; fi
        echo "$pid $st $j" >> "${WORKER_CPU_STATE}.new"
        key="${pid}:${st}"
        if [[ -z "${prev[$key]+set}" ]]; then
            # No baseline: this worker appeared since the last pass. Something
            # starting up IS activity, and there is nothing to diff against.
            started="${PROC_COMM[$pid]}($pid)"
            continue
        fi
        delta=$(( j - prev[$key] ))
        if (( delta > best )); then best=$delta; bestpid=$pid; fi
    done
    mv -f "${WORKER_CPU_STATE}.new" "$WORKER_CPU_STATE"

    if [[ -n "$started" ]]; then
        log "  WORKER: new worker since last pass: ${started} -- active"
        echo "$now" > "$WORKER_STAMP"
        return 1
    fi

    if (( best >= need )); then
        log "  WORKER: ${PROC_COMM[$bestpid]}(${bestpid}) burned ${best} jiffies this pass (>= ${need}) -- active"
        echo "$now" > "$WORKER_STAMP"
        return 1
    fi

    # Work often pauses -- a lane waiting on the API burns nothing for a
    # stretch. Carry the last real work forward for IDLE_THRESHOLD minutes so
    # those gaps do not read as idle. This bounds itself: once the work truly
    # stops, the stamp goes stale and the box is free to shut down.
    if [[ -f "$WORKER_STAMP" ]]; then
        local last age
        last=$(< "$WORKER_STAMP")
        if [[ "$last" =~ ^[0-9]+$ ]]; then
            age=$(( (now - last) / 60 ))
            if (( age < IDLE_THRESHOLD )); then
                log "  WORKER: no burn now, but real work ${age}m ago (< ${IDLE_THRESHOLD}m) -- active"
                return 1
            fi
        fi
    fi

    log "  WORKER: ${#ROOTS[@]} candidate(s), ${WORKER_ROOTS} worker(s), none doing work (max ${best} < ${need}) -- idle"
    return 0
}

# Check if idle long enough to send email (separate threshold)
check_email_idle() {
    # Don't send if already sent this idle period
    if [[ -f "$EMAIL_SENT_FILE" ]]; then
        return 1
    fi

    local conv_found
    conv_found=$(find "${HOME_DIR}/.claude/projects/" -maxdepth 3 -name "*.jsonl" -mmin -"${EMAIL_IDLE_THRESHOLD}" 2>/dev/null | head -1)
    if [[ -z "$conv_found" ]]; then
        return 0  # idle long enough for email
    fi
    return 1
}

send_idle_email() {
    log "  EMAIL: sending idle notification"
    printf "Subject: Devbox Idle - %s\nFrom: %s\nTo: %s\n\nYour devbox (ip-172-31-81-87) has been idle for %d+ minutes.\n\nIt will auto-shutdown after %d minutes of inactivity.\n\nTo keep it alive, interact with Claude Code or touch /tmp/no-idle-shutdown on the server.\n" \
        "$(date '+%H:%M %Z')" "$EMAIL_TO" "$EMAIL_TO" "$EMAIL_IDLE_THRESHOLD" "$IDLE_THRESHOLD" \
        | $MAIL_CMD "$EMAIL_TO" 2>>"$LOGFILE" && log "  EMAIL: sent" || log "  EMAIL: failed"
    touch "$EMAIL_SENT_FILE"
}

is_idle() {
    local tty_idle=false files_idle=false browser_idle=false worker_idle=false
    check_tty_idle          && tty_idle=true
    check_file_activity     && files_idle=true
    check_browser_heartbeat && browser_idle=true
    check_worker_activity   && worker_idle=true
    # All four signals must be idle. JSONL mtime updates on Claude conversations
    # are a true activity signal — they only happen on real user input. The
    # earlier "intentionally not blocking" comment was from when code-server's
    # WebSocket noise produced false positives; that was already fixed by
    # removing the access-log scan inside check_file_activity itself.
    # WORKER is the 2026-07-26 addition: the other three infer activity from
    # side effects, and a lane deep in one long tool call produces none.
    $tty_idle && $files_idle && $browser_idle && $worker_idle
}

cleanup_countdown() {
    rm -f "$COUNTDOWN_FILE" "$CANCEL_FILE"
}

log "=== idle-shutdown daemon started (interval=${INTERVAL}s, threshold=${IDLE_THRESHOLD}m, email=${EMAIL_IDLE_THRESHOLD}m, hold-ttl aware, worker-aware: comms='${WORKER_COMMS}' engines='${ENGINE_COMMS}' work>=${WORK_JIFFIES}j/60s) ==="
cleanup_countdown
rm -f "$EMAIL_SENT_FILE"

while true; do
    sleep "$INTERVAL"
    log "--- check ---"

    if hold_active; then
        cleanup_countdown
        continue
    fi

    if is_idle; then
        # Send idle email after EMAIL_IDLE_THRESHOLD minutes
        if check_email_idle; then
            send_idle_email
        fi

        if [[ -f "$DRYRUN_FILE" ]]; then
            log ">>> DRY-RUN: ALL IDLE -- WOULD start countdown (dry-run active) <<<"
            continue
        fi

        log ">>> ALL IDLE -- starting ${COUNTDOWN_SECS}s countdown <<<"
        shutdown_at=$(( $(date +%s) + COUNTDOWN_SECS ))
        echo "$shutdown_at" > "$COUNTDOWN_FILE"

        elapsed=0
        while (( elapsed < COUNTDOWN_SECS )); do
            sleep 10
            elapsed=$(( elapsed + 10 ))
            remaining=$(( COUNTDOWN_SECS - elapsed ))

            if [[ -f "$CANCEL_FILE" ]]; then
                log ">>> COUNTDOWN CANCELLED by user <<<"
                cleanup_countdown
                break 
            fi

            if hold_active; then
                log ">>> COUNTDOWN CANCELLED -- hold set <<<"
                cleanup_countdown
                break
            fi

            log "  COUNTDOWN: ${remaining}s remaining -- re-checking activity"
            if ! is_idle; then
                log ">>> COUNTDOWN CANCELLED -- activity detected <<<"
                cleanup_countdown
                break
            fi

            echo "$shutdown_at" > "$COUNTDOWN_FILE"
        done

        if [[ -f "$COUNTDOWN_FILE" ]]; then
            log ">>> COUNTDOWN COMPLETE -- stopping instance <<<"
            cleanup_countdown
            $STOP_CMD >> "$LOGFILE" 2>&1
            log ">>> stop-instances command sent <<<"
            exit 0
        fi
    else
        log "  RESULT: server active -- no shutdown"
        cleanup_countdown
        rm -f "$EMAIL_SENT_FILE"  # Reset email flag when active
    fi
done
