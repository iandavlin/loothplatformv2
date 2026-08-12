#!/usr/bin/env python3
"""compare-mirror.py — score a bb-mirror capture: WP replies vs mirror rows.

Reads the marker-delimited payload watch-mirror-sync.sh collects from live on
stdin, and prints one machine-readable line per finding. Split out of the watch
so the thresholds can be exercised against a captured payload (see
redfirst-watch.sh) instead of only against whatever live happens to be doing.

WP IS THE SOURCE OF TRUTH IN EVERY CHECK. An unsynced reply has no mirror row at
all — measured on live 2026-08-09: 0 of 5,282 forums.reply rows have a null
sync_at, while 4 published WP replies have no row whatsoever. So a lag query over
the mirror cannot see the failure it is meant to catch, and the walk has to go
WP -> mirror.

argv: <now_epoch> <lag_minutes> <urgent_age_hours>
stdout:
  COUNTS wp=<n> pg=<n> missing=<n> urgent=<n> laggy=<n> stale=<n>
  URGENT <reply_id> <age_minutes>      posted recently, no mirror row: invisible NOW
  OLD    <reply_id> <age_hours>        long-missing, known-backlog candidate
  LAGGY  <reply_id> <lag_minutes>      mirrored, but far too late
  STALE  <reply_id> <behind_minutes>   mirror content older than WP's
  FATAL  <message>                     refuse to score; the capture is not trustworthy
"""
import sys


def main() -> int:
    if len(sys.argv) < 4:
        print("FATAL usage: compare-mirror.py <now_epoch> <lag_minutes> <urgent_age_hours>")
        return 0

    now, lag_min, urgent_h = (int(sys.argv[1]), int(sys.argv[2]), int(sys.argv[3]))
    lag = lag_min * 60

    section, wp, pg = None, {}, {}
    for line in sys.stdin.read().splitlines():
        s = line.strip()
        if s.startswith("##"):
            section = s
            continue
        if not s:
            continue
        parts = s.split()
        try:
            if section == "##WPREPLIES##" and len(parts) >= 3:
                wp[int(parts[0])] = (int(parts[1]), int(parts[2]))
            elif section == "##PGREPLIES##" and len(parts) >= 4:
                pg[int(parts[0])] = (int(parts[1]), int(parts[2]), int(parts[3]))
        except ValueError:
            continue

    # Refuse to read an empty source side as "nothing is missing" — that is the
    # same class as bb_mirror_sweep_ghosts()'s abort_empty_wp guard. A failed
    # mysql call returning nothing must never score as a clean mirror.
    if not wp:
        print('FATAL zero published replies read from WP — refusing to read that as "nothing missing"')
        return 0
    if not pg:
        print("FATAL zero rows read from the mirror — the capture or the DB read failed")
        return 0

    # Posted in WP, no row in the mirror: invisible on the hub right now. Only
    # count rows old enough that a healthy sync would already have landed.
    missing = [(i, wp[i][0]) for i in sorted(wp) if i not in pg and now - wp[i][0] > lag]
    urgent = [(i, t) for i, t in missing if now - t <= urgent_h * 3600]
    old = [(i, t) for i, t in missing if now - t > urgent_h * 3600]

    # Mirrored, but far too late: the realtime dispatch dropped it and something
    # slower healed it. Recent window only — old lag is history, not an incident.
    laggy = [
        (i, pg[i][2] - wp[i][0])
        for i in sorted(wp)
        if i in pg and now - wp[i][0] <= urgent_h * 3600 and pg[i][2] - wp[i][0] > lag
    ]

    # Mirror content older than WP's: an EDIT's sync was dropped. The row exists,
    # so nothing else would ever notice; members just read a stale version.
    stale = [(i, wp[i][1] - pg[i][1]) for i in sorted(wp) if i in pg and wp[i][1] - pg[i][1] > lag]

    print(
        f"COUNTS wp={len(wp)} pg={len(pg)} missing={len(missing)} "
        f"urgent={len(urgent)} laggy={len(laggy)} stale={len(stale)}"
    )
    for i, t in urgent:
        print(f"URGENT {i} {(now - t) // 60}")
    for i, t in old:
        print(f"OLD {i} {(now - t) // 3600}")
    for i, d in laggy[:10]:
        print(f"LAGGY {i} {d // 60}")
    for i, d in stale[:10]:
        print(f"STALE {i} {d // 60}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
