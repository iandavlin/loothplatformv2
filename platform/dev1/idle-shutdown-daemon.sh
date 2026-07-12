#!/usr/bin/env bash
set -euo pipefail

LOGFILE="/var/log/idle-shutdown.log"
INTERVAL=60
IDLE_THRESHOLD=10
OVERRIDE_FILE="/tmp/no-idle-shutdown"
DRYRUN_FILE="/tmp/idle-shutdown-dryrun"
COUNTDOWN_FILE="/tmp/idle-shutdown-countdown"
CANCEL_FILE="/tmp/idle-shutdown-cancel"
STOP_CMD="/snap/bin/aws ec2 stop-instances --instance-ids i-01e54ed6c9a4ba91e"
ACTIVITY_FILE="/tmp/last-ccdev-activity"
HOME_DIR="/home/ubuntu"
COUNTDOWN_SECS=300
EMAIL_TO="ian.davlin@gmail.com"
EMAIL_SENT_FILE="/tmp/idle-shutdown-email-sent"
EMAIL_IDLE_THRESHOLD=60  # minutes before sending idle email

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
    local hb_log="/var/log/nginx/heartbeat.log"
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
    conv_found=$(find /home/*/.claude/projects/ -maxdepth 3 -name "*.jsonl" -mmin -"${IDLE_THRESHOLD}" 2>/dev/null | head -1)
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
        | msmtp "$EMAIL_TO" 2>>"$LOGFILE" && log "  EMAIL: sent" || log "  EMAIL: failed"
    touch "$EMAIL_SENT_FILE"
}

is_idle() {
    local tty_idle=false files_idle=false browser_idle=false
    check_tty_idle          && tty_idle=true
    check_file_activity     && files_idle=true
    check_browser_heartbeat && browser_idle=true
    # All three signals must be idle. JSONL mtime updates on Claude conversations
    # are a true activity signal — they only happen on real user input. The
    # earlier "intentionally not blocking" comment was from when code-server's
    # WebSocket noise produced false positives; that was already fixed by
    # removing the access-log scan inside check_file_activity itself.
    $tty_idle && $files_idle && $browser_idle
}

cleanup_countdown() {
    rm -f "$COUNTDOWN_FILE" "$CANCEL_FILE"
}

log "=== idle-shutdown daemon started (interval=${INTERVAL}s, threshold=${IDLE_THRESHOLD}m, email=${EMAIL_IDLE_THRESHOLD}m, hold-ttl aware) ==="
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
