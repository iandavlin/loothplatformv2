#!/usr/bin/env bash
# watch-roundup — morning-after check on the follow roundup's general release.
# Runs on dev2, reads live ONLY over ssh live-ro. Cron: daily 12:20 UTC, i.e.
# ~20 min after the 08:00 America/New_York flush window.
#
# Born 2026-08-08, the day the allowlist widened to all-members (fd0d196).
# Ian: "Do we have logging set up such that we can tell if anything is going
# haywire and shut it down immediately?" This is that watch, made durable so it
# does not depend on a chat session being awake. Kill switch, if it fires:
# 'enabled' => false in platform/config/follow-digest.php, arrives by pull.
#
# TRANSPORT: dev2 cannot send email (no sendmail/msmtp — see dupe-alarm.sh).
# Alerts go to the on-box msg board + log + the Ian-action flag, same pattern.
# A dead watch must never read as "all clear": ssh failure alerts too.
set -uo pipefail

MSG=/usr/local/bin/msg
LOG=/home/ubuntu/.roundup-watch.log
FLAG=/tmp/claude-ian-action

# The whole membership is ~381 topic subscribers and min_interval means one
# roundup per member per day, so a morning volume above this is structurally
# impossible without a bug. Generous on purpose: a cap that fires on a good day
# teaches people to ignore it.
MAX_SANE_SENDS=100

note()  { echo "$(date -u '+%F %T') $1" >> "$LOG"; }
alert() {
    note "ALERT: $1"
    "$MSG" send ubuntu "roundup-watch: $1" || note "ALERT DELIVERY FAILED (msg rc=$?)"
    touch "$FLAG"
}

if [ "${1:-}" = "--selftest" ]; then
    alert "SELFTEST — alert path works. No roundup anomaly involved."
    exit 0
fi

TODAY_UTC=$(date -u '+%Y-%m-%d')

# One ssh round-trip for everything; a hung link must not hang cron forever.
RAW=$(timeout 90 ssh live-ro '
  echo "##FLUSH##"; grep "\[lg-fd\]" /var/log/syslog | grep "'"$TODAY_UTC"'" | tail -8
  echo "##BAD##";   grep "\[lg-fd\]" /var/log/syslog | grep "'"$TODAY_UTC"'" | grep -cE "REFUSING|SEND-LAYER BLOCK|wp_mail returned false"
  echo "##SENT##";  mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import -e \
    "select count(*), count(distinct \`to\`) from wp_fsmpt_email_logs where subject like \"%new repl%discussion%\" and created_at > date_sub(now(), interval 1 day)"
  echo "##ENROLLED##"; mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import -e \
    "select count(*) from wp_usermeta where meta_key=\"lg_disc_digest_watermark\" and meta_value<>\"\""
' 2>/dev/null) || { alert "cannot reach live (ssh live-ro failed) — the watch is BLIND, not clear"; exit 1; }

FLUSH=$(sed -n '/##FLUSH##/,/##BAD##/p' <<<"$RAW" | grep -E "flush: [0-9]+ due" || true)
BAD=$(sed -n '/##BAD##/,/##SENT##/p' <<<"$RAW" | sed -n 2p)
SENT_LINE=$(sed -n '/##SENT##/,/##ENROLLED##/p' <<<"$RAW" | sed -n 2p)
SENT=$(awk '{print $1}' <<<"$SENT_LINE"); DISTINCT=$(awk '{print $2}' <<<"$SENT_LINE")
ENROLLED=$(sed -n '/##ENROLLED##/,$p' <<<"$RAW" | sed -n 2p)

note "flush='${FLUSH:-none}' bad=${BAD:-?} sent24h=${SENT:-?} distinct=${DISTINCT:-?} enrolled=${ENROLLED:-?}"

# 1. The flush must have run by now (we fire 20 min after its window). The
#    zero-sent logging shipped in 83dd7d9 precisely so this check can exist:
#    "ran and found nothing" logs, so a missing line means DID NOT RUN.
[ -z "$FLUSH" ] && alert "no daily-flush log line for $TODAY_UTC — the 08:00 flush did not run (timer? cron event? enabled flag?)"

# 2. Loud failure classes in today's log.
[ "${BAD:-0}" -gt 0 ] 2>/dev/null && alert "$BAD refusal/block/mail-failure line(s) in today's lg-fd log — read syslog on live"

# 3. Volume: structurally impossible numbers mean a bug, not popularity.
[ "${SENT:-0}" -gt "$MAX_SANE_SENDS" ] 2>/dev/null && alert "$SENT roundup emails in 24h (cap $MAX_SANE_SENDS) — investigate before the next flush"

# 4. Duplicates: min_interval + the watermark make >1/member/day impossible.
if [ -n "${SENT:-}" ] && [ -n "${DISTINCT:-}" ] && [ "$SENT" -gt "$DISTINCT" ]; then
    alert "duplicate roundups: $SENT sends to only $DISTINCT recipients in 24h — the once-a-day guarantee is broken"
fi

exit 0
