#!/usr/bin/env bash
# watch-mirror-sync — the lag tripwire for the bbPress -> Postgres forum mirror.
# Runs on dev2, reads live ONLY over ssh live-ro. Cron: every 15 minutes.
#
# WHY THIS EXISTS (backlog 3.9, measured on live 2026-08-09)
#
# The hub serves discussions from the mirror, and replies were going invisible for
# hours or days. Root cause: bb-mirror-reconcile.service — the ONLY safety net
# under a fire-and-forget realtime sync — had been fataling on a foreign-key
# violation every 10 minutes since 2026-07-29 23:20 UTC. Eleven days. Nothing
# watched that unit, so nothing said a word. Meanwhile 11 of the 70 replies posted
# in that window (16%) never reached the hub, and each one was rescued only when
# its author happened to EDIT the post — one of them 2d22h later.
#
# So the alerting rule this encodes is: the mirror is not "probably fine because
# the code is deployed". Two independent things must be TRUE AND OBSERVED —
# the healer is alive, and the data actually matches.
#
# WHAT AN UNSYNCED REPLY LOOKS LIKE — measured, not assumed. It has NO ROW AT ALL.
# Every forums.reply row on live has a non-null sync_at (0 nulls of 5,282), so a
# lag query over the mirror is structurally blind to the exact failure it is meant
# to catch: the invisible replies are the ones that were never inserted. That is
# why check 2 walks WP -> mirror and not the other way round.
#
# TRANSPORT: dev2 cannot send email (see dupe-alarm.sh / watch-roundup.sh). Alerts
# go to the on-box msg board + this log + the Ian-action flag. A dead watch must
# never read as "all clear", so an ssh failure alerts too.
#
# Kill switch if it ever goes haywire: comment the cron line. It is read-only
# against live — it writes nothing anywhere except its own log and baseline.

set -uo pipefail

MSG=/usr/local/bin/msg
# Overridable so redfirst-watch.sh can replay captures against its own scratch
# state instead of the real log and baseline. Cron passes none of these.
LOG=${MIRROR_WATCH_LOG:-/home/ubuntu/.mirror-sync-watch.log}
BASELINE=${MIRROR_WATCH_BASELINE:-/home/ubuntu/.mirror-sync-watch.baseline}
FLAG=/tmp/claude-ian-action

# MEASURED at 5 min (the charter's number): the realtime path lands in ~2s when it
# works, so anything past 5 min means that dispatch was dropped. Every run logs the
# count, so the drop rate is a tracked number rather than a memory.
LAG_MINUTES=5
# ALERTED at 15. Reconcile's timer is OnUnitActiveSec=10min, so a dropped sync is
# EXPECTED to surface between 5 and ~11 minutes later — that is the safety net
# working, not an incident, and paging on it would train everyone to mute this
# watch within a day. Past 15 min the net itself is not catching, which is exactly
# the 2026-07-29 failure. Lower this only alongside a fix to the dispatch drop.
ALERT_LAG_MINUTES=15
# The bookmark must move every reconcile cycle (timer: OnUnitActiveSec=10min).
# Two missed cycles plus slack: anything older means the healer is not healing.
BOOKMARK_MAX_AGE_MIN=30
# Missing rows newer than this stay loud on EVERY run — that is live breakage.
# Older ones alert once, when first seen, then sit in the log as known backlog.
URGENT_AGE_HOURS=24

note()  { echo "$(date -u '+%F %T') $1" >> "$LOG"; }
alert() {
    note "ALERT: $1"
    # WATCH_DRY_RUN=1 scores the thresholds without touching the alert channel —
    # for redfirst-watch.sh, which fires every check on purpose. The channel
    # itself is proven separately by --selftest, so neither path is assumed.
    if [ "${WATCH_DRY_RUN:-0}" = "1" ]; then
        echo "ALERT: $1"
        return
    fi
    "$MSG" send ubuntu "mirror-sync-watch: $1" || note "ALERT DELIVERY FAILED (msg rc=$?)"
    touch "$FLAG"
}

if [ "${1:-}" = "--selftest" ]; then
    alert "SELFTEST — alert path works. No mirror anomaly involved."
    exit 0
fi

# --hypothetical <file> replays a captured payload instead of reading live, so the
# thresholds can be proven to FIRE on a known-bad shape without waiting for live to
# break again. See tools/mirror-sync/redfirst-watch.sh.
REPLAY=""
if [ "${1:-}" = "--hypothetical" ]; then
    REPLAY="${2:-}"
    [ -r "$REPLAY" ] || { echo "--hypothetical needs a readable capture file"; exit 2; }
fi

# ---------------------------------------------------------------------------
# Collect. One ssh round trip; a hung link must not hang cron forever.
# ---------------------------------------------------------------------------
if [ -n "$REPLAY" ]; then
    RAW=$(cat "$REPLAY")
else
    RAW=$(timeout 120 ssh live-ro '
      echo "##UNIT##"
      systemctl is-failed bb-mirror-reconcile.service 2>/dev/null
      echo "##BOOKMARK##"
      psql -h 127.0.0.1 -U looth_ro -d looth -At -c \
        "select value from forums.sync_state where key = '"'"'last_reconcile_at'"'"'" 2>/dev/null
      echo "##WPREPLIES##"
      mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import -e \
        "SELECT ID, UNIX_TIMESTAMP(post_date_gmt), UNIX_TIMESTAMP(post_modified_gmt) \
           FROM wp_posts WHERE post_type = '"'"'reply'"'"' AND post_status = '"'"'publish'"'"'" 2>/dev/null
      echo "##PGREPLIES##"
      psql -h 127.0.0.1 -U looth_ro -d looth -At -F" " -c \
        "select id, extract(epoch from created_at)::bigint, extract(epoch from modified_at)::bigint, \
                extract(epoch from sync_at)::bigint from forums.reply" 2>/dev/null
      echo "##END##"
    ' 2>/dev/null) || {
        alert "cannot reach live (ssh live-ro failed) — the mirror watch is BLIND, not clear"
        exit 1
    }
fi

# A truncated payload must not be scored as "nothing wrong". Every section marker
# has to be present or we know nothing.
for marker in '##UNIT##' '##BOOKMARK##' '##WPREPLIES##' '##PGREPLIES##' '##END##'; do
    grep -q -- "$marker" <<<"$RAW" || {
        alert "live payload is truncated (missing $marker) — the watch reached no verdict"
        exit 1
    }
done

UNIT=$(sed -n '/##UNIT##/,/##BOOKMARK##/p' <<<"$RAW" | sed -n 2p | tr -d '[:space:]')
BOOKMARK=$(sed -n '/##BOOKMARK##/,/##WPREPLIES##/p' <<<"$RAW" | sed -n 2p | tr -d '[:space:]')

# ---------------------------------------------------------------------------
# 1. Is the healer alive? The check that would have caught 2026-07-29 in 10 min.
# ---------------------------------------------------------------------------
# WATCH_NOW_EPOCH pins "now" so a replayed capture scores identically forever.
# Cron never sets it; without it this is just the clock.
NOW=${WATCH_NOW_EPOCH:-$(date -u +%s)}

if [ "$UNIT" = "failed" ]; then
    alert "bb-mirror-reconcile.service is FAILED on live — the mirror's only safety net is down; journalctl -u bb-mirror-reconcile on live"
fi

if [[ "$BOOKMARK" =~ ^[0-9]+$ ]]; then
    AGE_MIN=$(( (NOW - BOOKMARK) / 60 ))
    note "reconcile unit='${UNIT:-?}' bookmark_age=${AGE_MIN}min"
    if [ "$AGE_MIN" -gt "$BOOKMARK_MAX_AGE_MIN" ]; then
        alert "reconcile bookmark (last_reconcile_at) is ${AGE_MIN} min old, cap ${BOOKMARK_MAX_AGE_MIN} — the delta walk is not completing, so nothing is healing dropped syncs"
    fi
else
    alert "could not read forums.sync_state.last_reconcile_at on live (got '${BOOKMARK:-empty}') — cannot tell whether the healer runs"
fi

# ---------------------------------------------------------------------------
# 2-4. Does the data actually match? WP is the source of truth in every check.
# ---------------------------------------------------------------------------
CMP=$(python3 "$(dirname "$0")/compare-mirror.py" "$NOW" "$LAG_MINUTES" "$URGENT_AGE_HOURS" <<<"$RAW")

if grep -q '^FATAL' <<<"$CMP"; then
    alert "$(grep '^FATAL' <<<"$CMP" | cut -d' ' -f2-)"
    exit 1
fi

note "$(grep '^COUNTS' <<<"$CMP" || echo 'COUNTS unreadable')"

# 2a. Recent missing rows: loud on every run until they are fixed.
URGENT_IDS=$(grep '^URGENT ' <<<"$CMP" | awk '{print $2}')
if [ -n "$URGENT_IDS" ]; then
    OLDEST=$(grep '^URGENT ' <<<"$CMP" | awk '{print $3}' | sort -rn | head -1)
    alert "$(wc -w <<<"$URGENT_IDS" | tr -d ' ') reply(s) posted in the last ${URGENT_AGE_HOURS}h are MISSING from the mirror — invisible on the hub, oldest ${OLDEST} min. ids: $(tr '\n' ' ' <<<"$URGENT_IDS")"
fi

# 2b. Older missing rows: alert the first time each id is seen, then log only.
#     Live carries 4 orphaned June replies whose WP parentage is broken (71433,
#     71720, 71722, 71728 -> a deleted or non-topic parent); they are unmirrorable
#     until Ian repairs the data, and a watch that shouts about them every 15
#     minutes is a watch everyone learns to ignore.
# comm needs BOTH sides in the same collation, and it is the default lexical one —
# feeding it `sort -n` output silently mis-pairs and manufactures "new" ids.
OLD_IDS=$(grep '^OLD ' <<<"$CMP" | awk '{print $2}' | sort)
touch "$BASELINE" 2>/dev/null || true
NEW_OLD=$(comm -23 <(echo "$OLD_IDS") <(sort "$BASELINE" 2>/dev/null) 2>/dev/null | tr -d ' ')
if [ -n "$NEW_OLD" ]; then
    alert "newly detected long-missing reply(s) in the mirror: $(tr '\n' ' ' <<<"$NEW_OLD") — check WP parentage (_bbp_topic_id / _bbp_reply_to)"
fi
[ -n "$OLD_IDS" ] && echo "$OLD_IDS" > "$BASELINE"

# 3. Slow syncs in the recent window. Counted at LAG_MINUTES, alerted at
#    ALERT_LAG_MINUTES — see the threshold comments at the top.
LAGGY_N=$(grep -c '^LAGGY ' <<<"$CMP")
if [ "${LAGGY_N:-0}" -gt 0 ]; then
    WORST=$(grep '^LAGGY ' <<<"$CMP" | awk '{print $3}' | sort -rn | head -1)
    note "lag: $LAGGY_N repl(y|ies) over ${LAG_MINUTES}min in the last ${URGENT_AGE_HOURS}h, worst ${WORST}min (dispatch drops healed by reconcile)"
    if [ "${WORST:-0}" -gt "$ALERT_LAG_MINUTES" ]; then
        alert "$LAGGY_N repl(y|ies) in the last ${URGENT_AGE_HOURS}h took over ${LAG_MINUTES} min to reach the mirror, worst ${WORST} min — past the ${ALERT_LAG_MINUTES} min reconcile cadence, so the safety net is NOT catching the drops"
    fi
fi

# 4. Stale content — the row is there, so nothing else would ever notice.
STALE_N=$(grep -c '^STALE ' <<<"$CMP")
if [ "${STALE_N:-0}" -gt 0 ]; then
    IDS=$(grep '^STALE ' <<<"$CMP" | awk '{print $2}' | tr '\n' ' ')
    alert "$STALE_N mirrored repl(y|ies) are STALE — WP has a newer edit the mirror never received. ids: $IDS"
fi

exit 0
