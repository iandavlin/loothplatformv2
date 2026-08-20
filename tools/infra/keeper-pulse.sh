#!/usr/bin/env bash
# keeper-pulse — 20-second vitals logger, born the night of 8/19-20.
#
# Why: the box's freezes are shorter than sar's 10-minute samples. When Ian's
# editor locks, this log answers "was the SERVER slow at that second, and if
# so from what?" — load, cpu split (incl. steal, the Amazon-throttle
# fingerprint), memory, swap, and whether nginx answered locally and how fast.
#
# Reads /var/log/keeper-pulse.log — one line per 20s, self-truncating at 50k
# lines (~11 days). Installed as keeper-pulse.service (unit in this dir).
set -uo pipefail

LOG=/var/log/keeper-pulse.log

cpu_snap() { awk '/^cpu /{print $2+$3+$4+$6+$7+$8, $5, $9}' /proc/stat; }

while :; do
    read -r busy1 idle1 steal1 <<<"$(cpu_snap)"
    sleep 20
    read -r busy2 idle2 steal2 <<<"$(cpu_snap)"
    total=$(( (busy2-busy1) + (idle2-idle1) ))
    [ "$total" -gt 0 ] || total=1
    busy_pct=$(( 100 * (busy2-busy1) / total ))
    steal_pct=$(( 100 * (steal2-steal1) / total ))

    load="$(cut -d' ' -f1-3 /proc/loadavg)"
    mem="$(awk '/MemAvailable/{printf "%dM", $2/1024}' /proc/meminfo)"
    swap="$(awk '/SwapFree/{f=$2} /SwapTotal/{t=$2} END{printf "%dM", (t-f)/1024}' /proc/meminfo)"

    t0=$(date +%s%3N)
    code="$(curl -s -o /dev/null -m 5 -w '%{http_code}' --resolve dev2.loothgroup.com:443:127.0.0.1 https://dev2.loothgroup.com/lanes/ 2>/dev/null || echo 000)"
    ms=$(( $(date +%s%3N) - t0 ))

    printf '%s load=%s cpu=%s%% steal=%s%% memfree=%s swapused=%s lanes=%s in %sms\n' \
        "$(date -u '+%m-%d %H:%M:%S')" "$load" "$busy_pct" "$steal_pct" "$mem" "$swap" "$code" "$ms" >> "$LOG"

    if [ "$(wc -l < "$LOG" 2>/dev/null || echo 0)" -gt 50000 ]; then
        tail -n 40000 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
    fi
done
