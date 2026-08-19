#!/usr/bin/env bash
# lanes — the worktree status table (handoff-2 spec, approved by Ian 8/18).
#
# Read-only, no state, no memory: every run recomputes from git. Quiet when
# healthy — the deploy block and the push column render only when something is
# wrong, so SILENCE ONLY EVER MEANS HEALTHY. The one inversion of that rule a
# failed live read could cause is handled by printing UNKNOWN loudly instead.
#
#   lanes            table + deploy agreement check (one ssh to live, 6s cap)
#   lanes --no-live  skip the live ssh (tight loops)
#   lanes --agents   append the old tmux view (WORKING/parked per session) —
#                    on trial for the column-cull week, then keep or kill
#
# Install: sudo ln -sf /home/ubuntu/keeper-repo/tools/lanes-status.sh /usr/local/bin/lanes
set -euo pipefail

REPO="/home/ubuntu/keeper-repo"
SERVE="/home/ubuntu/loothplatformv2-clean"

# Ceilings — Ian's ruling 8/18 (handoff-4): two numbers, not one. Disk allows
# 9+ seats (~100MB each); these are the ruled caps, and the binding limits are
# the 2-core box and attention, not storage.
SEAT_CEILING=6     # worktrees existing
WORKING_CAP=2      # lanes generating at once — and 1 while Ian is actively on dev2

NO_LIVE=0; AGENTS=0; JSON=0; ALL=0
for a in "$@"; do case "$a" in --no-live) NO_LIVE=1;; --agents) AGENTS=1;; --json) JSON=1;; --all) ALL=1;; esac; done

# Ian's ruling 8/18: fetch before the unbacked count — remote-tracking refs go
# stale, and a branch pushed hours ago must never read as unbacked. A failed
# fetch is flagged, never silent (the count may then overstate).
FETCH_OK=true
timeout 20 git -C "$REPO" fetch origin --prune --quiet 2>/dev/null || FETCH_OK=false
UNBACKED=$(git -C "$REPO" rev-list --count --branches --not --remotes=origin 2>/dev/null || echo 0)
UNB_LINES=""
if [[ "$UNBACKED" -gt 0 ]]; then
    for b in $(git -C "$REPO" for-each-ref --format='%(refname:short)' refs/heads); do
        n=$(git -C "$REPO" rev-list --count "$b" --not --remotes=origin 2>/dev/null || echo 0)
        [[ "$n" -gt 0 ]] && UNB_LINES+="$n $b"$'\n'
    done
    UNB_LINES=$(printf '%s' "$UNB_LINES" | sort -rn)
fi

# ── deploy line: main / dev2 serve / live. Invisible when all agree. ─────────
MAIN=$(git -C "$REPO" rev-parse main)
DEV2=$(git -C "$SERVE" rev-parse HEAD 2>/dev/null || echo MISSING)
LIVE="(skipped)"
if [[ $NO_LIVE -eq 0 ]]; then
    # Fix #1 (handoff-2 reply) wants rev-parse so packed refs can't cause a
    # false UNKNOWN. Measured 8/18: rev-parse fails for the read-only live user
    # (git "dubious ownership" — the repo belongs to ubuntu). So: rev-parse
    # first (works the day live adds safe.directory for looth-ro), then the
    # loose ref, then packed-refs — loose before packed, matching git's own
    # precedence. UNKNOWN only when every read genuinely fails.
    # #143: live's load + disk ride the SAME ssh call — zero extra connections
    LIVE_RAW=$(timeout 8 ssh live-ro '
        (git -C /home/ubuntu/loothplatformv2-clean rev-parse main 2>/dev/null \
        || cat /home/ubuntu/loothplatformv2-clean/.git/refs/heads/main 2>/dev/null \
        || sed -n "s|^\([0-9a-f]\{40\}\) refs/heads/main$|\1|p" /home/ubuntu/loothplatformv2-clean/.git/packed-refs 2>/dev/null | head -1)
        echo "RES $(cut -d" " -f1 /proc/loadavg) $(df -h / | awk "NR==2{print \$5}")"
    ') || true
    LIVE=$(printf '%s\n' "$LIVE_RAW" | head -1)
    LIVE_RES=$(printf '%s\n' "$LIVE_RAW" | sed -n 's/^RES //p')
    [[ -z "$LIVE" || "$LIVE" == RES* ]] && LIVE="UNKNOWN"
fi
GAP=0
[[ "$DEV2" != "$MAIN" ]] && GAP=1
[[ $NO_LIVE -eq 0 && "$LIVE" != "$MAIN" ]] && GAP=1
# ── rows: one per worktree, straight from git ────────────────────────────────
ROWS=""          # sortkey|folder|branch|behind|unique|push|status|rawpush|slug|scratch|mismatch
SHOW_PUSH=0      # push column materializes only when a lane has unpushed work
SEATS_USED=0     # real (non-scratch) worktree seats, parent excluded
WT_BRANCHES=""   # branches that HAVE a worktree (parked zone excludes these)
COLL_BRANCHES="" # seat branches with unique work (collision candidates)
folder=""
while IFS= read -r line; do
    case "$line" in
        "worktree "*) folder="${line#worktree }" ;;
        "detached")
            scr=false; [[ "$folder" != /home/ubuntu/* ]] && scr=true
            ROWS+="999999|${folder#/home/ubuntu/}|(detached)|-|-|-|detached — investigate|NR|detached|$scr|false|"$'\n' ;;
        "branch refs/heads/"*)
            branch="${line#branch refs/heads/}"
            scr=false; [[ "$folder" != /home/ubuntu/* ]] && scr=true
            WT_BRANCHES+=" $branch"
            # mismatch = the folder-name-lies hazard: `git worktree remove`
            # takes a PATH, and this is exactly where the wrong one gets removed
            mm=false; [[ "$(basename "$folder")" != "$branch" ]] && mm=true
            if [[ "$branch" == "main" ]]; then
                # the parent checkout is not a seat; the deploy line covers it
                ROWS+="-1|${folder#/home/ubuntu/}|main|0|0|0|— (parent checkout)|0|parent|false|false|"$'\n'
                continue
            fi
            lr=$(git -C "$REPO" rev-list --left-right --count "origin/main...$branch" 2>/dev/null | tr '\t' ' ' || true)
            [[ -z "$lr" ]] && lr="? ?"
            behind="${lr%% *}"; unique="${lr##* }"
            if git -C "$REPO" rev-parse --verify -q "refs/remotes/origin/$branch" >/dev/null; then
                push=$(git -C "$REPO" rev-list --count "origin/$branch..$branch")
            else
                push="NO REMOTE"
            fi
            subj=$(git -C "$REPO" log -1 --format=%s "$branch" 2>/dev/null || echo "")
            # Status: spec's five rules. Precedence note: AT RISK outranks
            # re-cut — the loud flag must never be masked by a big behind count.
            if   [[ "$unique" == "0" ]]; then status="done — seat freeable"; slug="done"
            elif [[ "$subj" == "STOOD DOWN: "* ]]; then status="stood down"; slug="stood-down"
            elif [[ "$push" == "NO REMOTE" ]]; then status="AT RISK — work on one disk only"; slug="at-risk"
            elif [[ "$behind" =~ ^[0-9]+$ && "$behind" -gt 300 ]]; then status="re-cut, don't rebase"; slug="re-cut"
            else status="live lane"; slug="live"
            fi
            # push cell: loud only when work is actually unpushed. A merged
            # branch with no remote has nothing at risk — stays quiet.
            cell="$push"
            if [[ "$push" == "NO REMOTE" ]]; then
                if [[ "$unique" == "0" ]]; then cell="-"; else SHOW_PUSH=1; fi
            elif [[ "$push" != "0" ]]; then SHOW_PUSH=1; fi
            [[ "$scr" == false ]] && SEATS_USED=$((SEATS_USED + 1))
            [[ "$scr" == false && "$unique" =~ ^[0-9]+$ && "$unique" -gt 0 ]] && COLL_BRANCHES+=" $branch"
            rid="$(git -C "$folder" config --worktree lane.riders 2>/dev/null || true)"
            ROWS+="$behind|${folder#/home/ubuntu/}|$branch|$behind|$unique|$cell|$status|${push/NO REMOTE/NR}|$slug|$scr|$mm|$rid"$'\n' ;;
    esac
done < <(git -C "$REPO" worktree list --porcelain)

# ── collisions: one file touched by two lanes merges clean and can still be
#    wrong. Intersect changed-file lists across seats with unique work. ───────
COLLISIONS=""    # file|branch branch...
if [[ -n "${COLL_BRANCHES// /}" ]]; then
    declare -A FMAP=()
    for b in $COLL_BRANCHES; do
        while IFS= read -r f; do [[ -n "$f" ]] && FMAP["$f"]+=" $b"; done \
            < <(git -C "$REPO" diff --name-only "main...$b" 2>/dev/null)
    done
    for f in "${!FMAP[@]}"; do
        [[ $(wc -w <<<"${FMAP[$f]}") -gt 1 ]] && COLLISIONS+="$f|${FMAP[$f]# }"$'\n'
    done
fi

# ── parked: branches with NO worktree whose tip subject starts "PARKED: ".
#    Explicitly marked ONLY — anything else is just a branch (cleanup footer),
#    never a junk-drawer zone. Age is the cost of parking; behind>300 means
#    the parking has expired in practice. ─────────────────────────────────────
PARKED=""        # branch|reason|days|behind|expired
NOW_TS=$(date +%s)
while IFS='|' read -r b subj ts; do
    [[ " $WT_BRANCHES " == *" $b "* ]] && continue
    [[ "$subj" == "PARKED: "* ]] || continue
    days=$(( (NOW_TS - ts) / 86400 ))
    pbh=$(git -C "$REPO" rev-list --left-right --count "origin/main...$b" 2>/dev/null | tr '\t' ' ')
    pbh=${pbh%% *}; [[ "$pbh" =~ ^[0-9]+$ ]] || pbh=0
    pex=false; [[ "$pbh" -gt 300 ]] && pex=true
    PARKED+="$b|${subj#PARKED: }|$days|$pbh|$pex"$'\n'
done < <(git -C "$REPO" for-each-ref --format='%(refname:short)|%(subject)|%(committerdate:unix)' refs/heads)

# ── --json: machine output for the eventual interface. No quiet rules here —
#    machines get every field, always; hiding is a human-display concern. ─────
if [[ $JSON -eq 1 ]]; then
    dev2_json="null"; [[ "$DEV2" != "MISSING" ]] && dev2_json="\"$DEV2\""
    live_json="null"; live_state="skipped"
    if [[ $NO_LIVE -eq 0 ]]; then
        if [[ "$LIVE" == "UNKNOWN" ]]; then live_state="unknown"; else live_json="\"$LIVE\""; live_state="ok"; fi
    fi
    printf '{\n  "deploy": {"main": "%s", "dev2": %s, "live": %s, "live_state": "%s", "in_sync": %s},\n' \
        "$MAIN" "$dev2_json" "$live_json" "$live_state" "$([[ $GAP -eq 0 ]] && echo true || echo false)"
    printf '  "capacity": {"seats_used": %d, "seat_ceiling": %d, "working_cap": %d},\n' \
        "$SEATS_USED" "$SEAT_CEILING" "$WORKING_CAP"
    # #143: resource sample per render — dev2 local (free), live from the ride-along
    D2LOAD=$(cut -d' ' -f1 /proc/loadavg)
    D2DISK=$(df -h / | awk 'NR==2{print $5}')
    D2MEMU=$(free -m | awk '/^Mem:/{print $3}'); D2MEMT=$(free -m | awk '/^Mem:/{print $2}')
    D2SWAP=$(free -m | awk '/^Swap:/{print $3}')
    LIVE_JSON="null"
    if [[ -n "${LIVE_RES:-}" ]]; then
        read -r LLOAD LDISK <<<"$LIVE_RES"
        [[ -n "$LLOAD" && -n "$LDISK" ]] && LIVE_JSON="{\"load\": $LLOAD, \"disk\": \"$LDISK\"}"
    fi
    printf '  "resources": {"dev2": {"load": %s, "mem_used_m": %s, "mem_total_m": %s, "swap_m": %s, "disk": "%s"}, "live": %s},\n' \
        "$D2LOAD" "$D2MEMU" "$D2MEMT" "$D2SWAP" "$D2DISK" "$LIVE_JSON"
    printf '  "unbacked": {"total": %d, "fetch_ok": %s, "branches": [' "$UNBACKED" "$FETCH_OK"
    ufirst=1
    while read -r n b; do
        [[ -z "$b" ]] && continue
        [[ $ufirst -eq 0 ]] && printf ', '
        ufirst=0
        printf '{"branch": "%s", "count": %s}' "$b" "$n"
    done <<<"$UNB_LINES"
    printf ']},\n  "collisions": ['
    cfirst=1
    while IFS='|' read -r f bs; do
        [[ -z "$f" ]] && continue
        [[ $cfirst -eq 0 ]] && printf ', '
        cfirst=0
        blist=""; for b in $bs; do blist+="\"$b\", "; done
        printf '{"file": "%s", "branches": [%s]}' "${f//\"/\\\"}" "${blist%, }"
    done <<<"$COLLISIONS"
    printf '],\n  "parked": ['
    pfirst=1
    while IFS='|' read -r b reason days pbh pex; do
        [[ -z "$b" ]] && continue
        [[ $pfirst -eq 0 ]] && printf ', '
        pfirst=0
        printf '{"branch": "%s", "reason": "%s", "days": %s, "behind": %s, "expired": %s}' \
            "$b" "${reason//\"/\\\"}" "$days" "$pbh" "$pex"
    done <<<"$PARKED"
    printf '],\n  "lanes": [\n'
    first=1
    while IFS='|' read -r _ f b behind unique _ _ rawpush slug scratch mismatch riders; do
        [[ -z "$f" ]] && continue
        [[ $first -eq 0 ]] && printf ',\n'
        first=0
        bh="null"; [[ "$behind" =~ ^[0-9]+$ ]] && bh="$behind"
        un="null"; [[ "$unique" =~ ^[0-9]+$ ]] && un="$unique"
        if [[ "$rawpush" == "NR" ]]; then up="null"; nr="true"; else up="$rawpush"; nr="false"; fi
        rjson=""; for rr in $riders; do rjson+="$rr, "; done
        printf '    {"folder": "%s", "branch": "%s", "behind": %s, "unique": %s, "unpushed": %s, "no_remote": %s, "status": "%s", "scratch": %s, "mismatch": %s, "riders": [%s]}' \
            "$f" "$b" "$bh" "$un" "$up" "$nr" "$slug" "$scratch" "$mismatch" "${rjson%, }"
    done < <(printf '%s' "$ROWS" | sort -t'|' -k1,1n)
    printf '\n  ]\n}\n'
    exit 0
fi

# ── human view ───────────────────────────────────────────────────────────────
printf "seats %d/%d · working cap %d (1 while Ian is actively on dev2)\n\n" \
    "$SEATS_USED" "$SEAT_CEILING" "$WORKING_CAP"

if [[ $GAP -eq 1 ]]; then
    echo "DEPLOY GAP:"
    echo "  main  ${MAIN:0:7}"
    if [[ "$DEV2" == "MISSING" ]]; then echo "  dev2  MISSING (serving checkout unreadable)"; else
        echo "  dev2  ${DEV2:0:7}$([[ "$DEV2" != "$MAIN" ]] && echo '   <- differs from main')"; fi
    if [[ $NO_LIVE -eq 0 ]]; then
        if [[ "$LIVE" == "UNKNOWN" ]]; then echo "  live  UNKNOWN — read failed; NOT proof of health"; else
            echo "  live  ${LIVE:0:7}$([[ "$LIVE" != "$MAIN" ]] && echo '   <- differs from main')"; fi
    fi
    echo
fi

[[ "$FETCH_OK" == false ]] && { echo "WARN: fetch failed — unbacked count may be stale"; echo; }
if [[ "$UNBACKED" -gt 0 ]]; then
    echo "UNBACKED: $UNBACKED commit(s) exist ONLY on this box:"
    while read -r n b; do [[ -n "$b" ]] && echo "  $n  $b"; done <<<"$UNB_LINES"
    echo
fi
if [[ -n "$COLLISIONS" ]]; then
    echo "COLLISIONS (same file, more than one lane):"
    while IFS='|' read -r f bs; do [[ -n "$f" ]] && echo "  $f  <- $bs"; done <<<"$COLLISIONS"
    echo
fi

# ── print, sorted by behind ascending (freshest first) ───────────────────────
if [[ $SHOW_PUSH -eq 1 ]]; then
    printf "%-34s %-24s %7s %7s %10s  %s\n" "FOLDER" "BRANCH" "BEHIND" "UNIQUE" "UNPUSHED" "STATUS"
else
    printf "%-34s %-24s %7s %7s  %s\n" "FOLDER" "BRANCH" "BEHIND" "UNIQUE" "STATUS"
fi
printf '%s' "$ROWS" | sort -t'|' -k1,1n | while IFS='|' read -r _ f b behind unique cell status _ _ scratch mismatch _; do
    [[ -z "$f" ]] && continue
    # scratch worktrees (outside /home/ubuntu) are noise on every run — hidden
    # unless --all; JSON always carries them, flagged, for machines to filter
    [[ "$scratch" == "true" && $ALL -eq 0 ]] && continue
    if [[ "$mismatch" == "true" ]]; then f="≠ $f"; status="$status — FOLDER≠BRANCH"; fi
    if [[ $SHOW_PUSH -eq 1 ]]; then
        printf "%-34s %-24s %7s %7s %10s  %s\n" "$f" "$b" "$behind" "$unique" "$cell" "$status"
    else
        printf "%-34s %-24s %7s %7s  %s\n" "$f" "$b" "$behind" "$unique" "$status"
    fi
done

# ── parked zone: deliberately set down, no seat, no cost — but drift accrues ─
if [[ -n "$PARKED" ]]; then
    echo
    echo "── parked (branch kept, seat freed — deliberately marked):"
    while IFS='|' read -r b reason days pbh pex; do
        [[ -z "$b" ]] && continue
        line="  $b · parked ${days}d · behind $pbh · \"$reason\""
        [[ "$pex" == true ]] && line+="  ⚠ PARKING EXPIRED — re-cut on resume"
        echo "$line"
    done <<<"$PARKED"
fi

# ── --agents: the old tmux view, unchanged (trial column) ────────────────────
if [[ $AGENTS -eq 1 ]]; then
    echo
    echo "── agents (tmux):"
    found=0
    for s in $(tmux list-sessions -F "#{session_name}" 2>/dev/null); do
        found=1
        if tmux capture-pane -t "$s" -p 2>/dev/null | grep -q "esc to interrupt"; then st="WORKING"; else st="parked "; fi
        last=$(tmux capture-pane -t "$s" -p -S -30 2>/dev/null \
              | grep -vE "^\s*$|^─|^❯|bypass permissions|tmux |Auto-update|/clear to save|control this session" \
              | tail -1 | sed "s/^[ ●✻⎿·]*//" | cut -c1-90)
        printf "%-20s %s  %s\n" "$s" "$st" "$last"
    done
    [[ $found -eq 0 ]] && echo "(no agent sessions)"
fi
