#!/usr/bin/env bash
# redfirst-watch.sh — prove every threshold in watch-mirror-sync.sh actually FIRES,
# and — just as important — that a healthy mirror produces SILENCE.
#
# A watch nobody has seen fire is a decoration; a watch that fires on everything is
# noise people learn to mute. Both halves are asserted here.
#
# The captures are SYNTHETIC and pinned to a fixed clock (WATCH_NOW_EPOCH), so
# these cases replay identically forever and need no live access. Case 2 and case
# 3 reproduce the exact live incident this work came from: reply 72589, posted
# 2026-08-07 17:42:10 UTC, which did not reach the mirror until 2026-08-08
# 17:29:08 — 23h47m invisible on the hub.
#
# Exit: 0 = every case behaved, 1 = at least one did not (the finding).

set -uo pipefail
cd "$(dirname "$0")" || exit 2

WATCH=./watch-mirror-sync.sh
[ -r "$WATCH" ] || { echo "CANNOT RUN: $WATCH unreadable"; exit 2; }

TMP=$(mktemp -d /tmp/mirror-watch-redfirst.XXXXXX) || exit 2
trap 'rm -rf "$TMP"' EXIT

# Pinned clock: 2026-08-08 12:00:00 UTC — inside the real 72589 outage window.
NOW=1786291200
H=3600
fails=0

# capture <file> <unit> <bookmark_epoch> <wp lines...> -- <pg lines...>
# WP line: "<id> <created_epoch> <modified_epoch>"
# PG line: "<id> <created_epoch> <modified_epoch> <sync_epoch>"
capture() {
    local out="$1" unit="$2" bookmark="$3"; shift 3
    {
        echo "##UNIT##";     echo "$unit"
        echo "##BOOKMARK##"; echo "$bookmark"
        echo "##WPREPLIES##"
        while [ "${1:-}" != "--" ] && [ $# -gt 0 ]; do echo "$1"; shift; done
        shift 2>/dev/null || true
        echo "##PGREPLIES##"
        while [ $# -gt 0 ]; do echo "$1"; shift; done
        echo "##END##"
    } > "$out"
}

# expect <label> <capture> <grep-pattern|NONE>
expect() {
    local label="$1" cap="$2" want="$3"
    printf '\n--- %s\n' "$label"

    local out
    out=$(WATCH_DRY_RUN=1 WATCH_NOW_EPOCH="$NOW" \
          MIRROR_WATCH_LOG="$TMP/log" MIRROR_WATCH_BASELINE="$TMP/baseline.$RANDOM" \
          bash "$WATCH" --hypothetical "$cap" 2>&1)

    if [ "$want" = "NONE" ]; then
        if grep -q '^ALERT:' <<<"$out"; then
            echo "    ALERTED ON A HEALTHY MIRROR — this watch would cry wolf:"
            sed 's/^/      /' <<<"$out"
            fails=$((fails + 1))
        else
            echo "    silent, as required"
        fi
        return
    fi

    if grep -qi -- "$want" <<<"$out"; then
        echo "    FIRED as required: $(grep -i -m1 -- "$want" <<<"$out" | cut -c1-120)"
    else
        echo "    DID NOT FIRE (wanted /$want/) — this threshold is decoration. Got:"
        sed 's/^/      /' <<<"${out:-<no output>}"
        fails=$((fails + 1))
    fi
}

echo "=== red-first: watch-mirror-sync thresholds ==="

# ---------------------------------------------------------------------------
# 0. NEGATIVE CONTROL. Healthy mirror: healer alive, bookmark fresh, every WP
#    reply mirrored promptly. Must be completely silent, or nothing below means
#    anything.
# ---------------------------------------------------------------------------
capture "$TMP/healthy" active $((NOW - 300)) \
    "72589 $((NOW - 20*H)) $((NOW - 20*H))" \
    "72613 $((NOW - 2*H))  $((NOW - 2*H))" \
    -- \
    "72589 $((NOW - 20*H)) $((NOW - 20*H)) $((NOW - 20*H + 2))" \
    "72613 $((NOW - 2*H))  $((NOW - 2*H))  $((NOW - 2*H + 2))"
expect "healthy mirror is SILENT (negative control)" "$TMP/healthy" NONE

# ---------------------------------------------------------------------------
# 1. THE INCIDENT, as it actually looked at 2026-08-08 12:00 UTC: reply 72589 was
#    posted 18h earlier and had no mirror row at all. This is the case the whole
#    lane exists for — a member posted and the hub showed nothing.
# ---------------------------------------------------------------------------
capture "$TMP/invisible" active $((NOW - 300)) \
    "72589 $((NOW - 18*H)) $((NOW - 18*H))" \
    "72613 $((NOW - 2*H))  $((NOW - 2*H))" \
    -- \
    "72613 $((NOW - 2*H)) $((NOW - 2*H)) $((NOW - 2*H + 2))"
expect "reply posted 18h ago with NO mirror row (the 8/7 case)" "$TMP/invisible" "MISSING from the mirror"

# ---------------------------------------------------------------------------
# 2. The same reply after its late heal: mirrored, but 23h47m after it was
#    posted. The row exists, so every presence check on earth is green — only a
#    LAG check can see it.
# ---------------------------------------------------------------------------
capture "$TMP/laggy" active $((NOW - 300)) \
    "72589 $((NOW - 23*H)) $((NOW - 23*H))" \
    -- \
    "72589 $((NOW - 23*H)) $((NOW - 23*H)) $NOW"
expect "reply mirrored 23h late (the 8/7 heal)" "$TMP/laggy" "took over 5 min to reach the mirror"

# 2b. THE OTHER HALF of that threshold. A 7-minute lag is a dropped realtime
#     dispatch that reconcile caught on its next 10-minute tick — the safety net
#     doing its job. It is COUNTED in the log but must NOT page anyone, or this
#     watch gets muted in a day and the next real outage runs unseen.
capture "$TMP/lag7" active $((NOW - 300)) \
    "72600 $((NOW - 2*H)) $((NOW - 2*H))" \
    -- \
    "72600 $((NOW - 2*H)) $((NOW - 2*H)) $((NOW - 2*H + 420))"
expect "a 7-min lag (reconcile catching a drop) is SILENT" "$TMP/lag7" NONE

# ---------------------------------------------------------------------------
# 3. The healer itself. Either signal alone is enough: a failed unit, or a
#    bookmark that stopped moving. Live had BOTH for 11 days and nothing said so.
# ---------------------------------------------------------------------------
capture "$TMP/unitfailed" failed $((NOW - 300)) \
    "72613 $((NOW - 2*H)) $((NOW - 2*H))" -- \
    "72613 $((NOW - 2*H)) $((NOW - 2*H)) $((NOW - 2*H + 2))"
expect "reconcile unit FAILED on live" "$TMP/unitfailed" "safety net is down"

capture "$TMP/frozen" active $((NOW - 11*24*H)) \
    "72613 $((NOW - 2*H)) $((NOW - 2*H))" -- \
    "72613 $((NOW - 2*H)) $((NOW - 2*H)) $((NOW - 2*H + 2))"
expect "reconcile bookmark frozen 11 days (the real wedge)" "$TMP/frozen" "bookmark .* is .* min old"

# ---------------------------------------------------------------------------
# 4. A dropped EDIT. The row is present and its sync_at is recent, so checks 1-3
#    are all green while members read superseded text.
# ---------------------------------------------------------------------------
capture "$TMP/stale" active $((NOW - 300)) \
    "71432 $((NOW - 48*H)) $((NOW - 2*H))" -- \
    "71432 $((NOW - 48*H)) $((NOW - 40*H)) $((NOW - 40*H))"
expect "mirror content older than WP (dropped edit)" "$TMP/stale" "are STALE"

# ---------------------------------------------------------------------------
# 5. The watch must never score a broken read as a clean mirror.
# ---------------------------------------------------------------------------
capture "$TMP/empty" active $((NOW - 300)) -- \
    "72613 $((NOW - 2*H)) $((NOW - 2*H)) $((NOW - 2*H + 2))"
expect "zero WP rows is REFUSED, not read as 'nothing missing'" "$TMP/empty" "refusing to read that as"

capture "$TMP/trunc" active $((NOW - 300)) \
    "72613 $((NOW - 2*H)) $((NOW - 2*H))" -- \
    "72613 $((NOW - 2*H)) $((NOW - 2*H)) $((NOW - 2*H + 2))"
grep -v '##END##' "$TMP/trunc" > "$TMP/trunc2"
expect "a truncated payload is BLIND, not clear" "$TMP/trunc2" "truncated"

printf '\n=== summary ===\n'
if [ "$fails" -ne 0 ]; then
    echo "$fails case(s) misbehaved — the watch is not trustworthy yet"
    exit 1
fi
echo "every threshold fires on its defect, and a healthy mirror stays silent"
exit 0
