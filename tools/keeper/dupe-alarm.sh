#!/usr/bin/env bash
# dupe-alarm — daily duplicate-Patreon-ID check against LIVE (read-only).
# The poller bug's signature is one Patreon id attached to two WP accounts;
# this is the definition-level test from docs/atlas/POLLER-ONBOARDING-AUDIT.md §1.6.
#
# TRANSPORT: dev2 CANNOT SEND EMAIL (verified 2026-07-29: no sendmail/msmtp/mail
# binary, no SMTP credential in lg-secrets-helper). Alerts go to the on-box msg
# board + log + the Ian-action flag. An alarm that must reach Ian's INBOX belongs
# inside the poller on live, which has a proven mail path (lgpo_alert_failure).
# A dead alarm must never read as "all clear" — failures alert too.
# --selftest proves the alert path end to end.
set -uo pipefail

MSG=/usr/local/bin/msg
LOG=/home/ubuntu/.dupe-alarm.log
FLAG=/tmp/claude-ian-action

alert() {
    echo "$(date -u '+%F %T') ALERT: $1" >> "$LOG"
    "$MSG" send ubuntu "dupe-alarm: $1" || echo "$(date -u '+%F %T') ALERT DELIVERY FAILED (msg rc=$?)" >> "$LOG"
    touch "$FLAG"
}

if [ "${1:-}" = "--selftest" ]; then
    alert "SELFTEST — if keeper can read this on the board, the alarm path works. No duplicates involved."
    echo "selftest sent to board + log + flag"
    exit 0
fi

SQL="SELECT meta_value, COUNT(*) c FROM wp_usermeta WHERE meta_key='lgpo_patreon_user_id' AND meta_value<>'' GROUP BY meta_value HAVING c>1;"
OUT=$(ssh -o BatchMode=yes -o ConnectTimeout=20 live-ro "mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import -e \"$SQL\"" 2>&1)
RC=$?

if [ $RC -ne 0 ]; then
    alert "CHECK FAILED to query live (rc=$RC): $OUT"
    exit 1
fi

if [ -n "$OUT" ]; then
    alert "DUPLICATE Patreon id on live (id/count): $OUT — the old poller bug's exact signature. Investigate before merging anything."
    exit 2
fi

echo "$(date -u '+%F %T') clean" >> "$LOG"
exit 0
