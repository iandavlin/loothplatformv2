#!/usr/bin/env bash
# approved-watcher — #138. The `approved` label is the start button.
#
# PHASE A (Ian approved 8/19, live since): every 5 minutes
# (platform/systemd/approved-watcher.timer, dev2 only) any OPEN issue newly
# carrying `approved` rings Ian's bell file and posts a board line for keeper's
# next pass. State = one issue number per line in ~/.approved-acks.
#
# PHASE B (probation ended by Ian's ruling 8/20, verbatim: "If a lane goes Idle
# waiting for me to make a decision, I want the next work in line to start up
# while I'm screwing around"): the watcher SPINS the lane itself — but ONLY for
# PRE-STAGED work. Approval alone still just rings.
#
# Every guard below has a scar behind it. In refusal order:
#
#   HOLDS (global, one decision for the whole tick)
#     H1  keeper-quiet — the manual hold. /tmp for the session, ~ because /tmp
#         does not survive a reboot and a hold that evaporates is worse than none.
#     H2  the fleet is DOWN — manifest non-empty, zero lane sessions. That is the
#         post-reboot signature, and respawn-fleet is DELIBERATELY MANUAL so that
#         a reboot never relights paid sessions with nobody watching. This watcher
#         must not become the thing that does it.
#     H3  1-min load over 4 — keeper's standing rule after dev2 hit load 15 on two
#         cores and Ian's own pages timed out into the offline shell.
#     H4  at the WORKING cap. Counted with lanes-status.sh's OWN detector, through
#         lanes-status.sh itself: forking a second definition of "working" is the
#         one thing #138's charter forbids by name (the 8/20 CLI change moved that
#         signature twice in one day).
#
#   PER ISSUE
#     G1  CHARTER REQUIRED. Exactly one ~/lane-prompts/<n>-*.md. None ⇒ ring only:
#         an agent told what it is only AFTER it starts can miss the message and
#         supervise nothing for a whole day (7/27). Two ⇒ refuse and name both;
#         #107 has two on disk right now, so this branch is live, not theoretical.
#     G2  The charter NAMES the seat. Lane = its basename. Nothing is slugified,
#         invented, or generated from an issue body.
#     G3  ONE SPIN PER ISSUE EVER — ~/.autospin-log, plus a backstop: a branch that
#         already exists with no worktree is a PRIOR lane (parked or merged), and
#         resuming one is a keeper decision, never a re-spin. That backstop is
#         load-bearing on day one: nine parked branches have open+approved issues
#         whose charters are still on disk, and without it, arming this watcher
#         re-spins the lot.
#     G4  A live tmux session for the seat ⇒ it is already running.
#     G5  A worktree must be ON its own branch (folder==branch is a LANE-RULES
#         promise). Missing ⇒ cut it exactly as keeper does, off origin/main, and
#         only under the seat ceiling.
#
#   MODE
#     DRY-RUN IS THE DEFAULT. ~/.autospin-mode must contain `live` to arm. The flip
#     is a FILE, not a code edit — keeper flips it after watching one correct
#     decision. Dry-run announces once per issue and consumes NOTHING, so the first
#     real spin still happens after the flip.
#     On a live spin the log line is written BEFORE tmux is touched: a crash or a
#     half-started session can then never become a spin loop. A failed spin is a
#     keeper problem on the board — the safe direction to fail in.
set -euo pipefail

# ── Overridable ONLY so gate 82 can drive THIS EXACT SCRIPT against fixtures —
#    no network, no real seat, no claude process, and no writing to the real
#    /tmp/keeper-quiet (a gate that set a box-wide hold would be a defect of its
#    own). Production never sets any of these. Same precedent lanes-status.sh set
#    with LG_LANES_* for gate 77.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATE_DIR="${LG_AW_STATE_DIR:-$HOME}"
PROMPTS="${LG_AW_PROMPTS:-$HOME/lane-prompts}"
WORKTREES="${LG_AW_WORKTREES:-$HOME/worktrees}"
REPO="${LG_AW_REPO:-$HOME/keeper-repo}"
LANES_CMD="${LG_AW_LANES_CMD:-bash $HERE/lanes-status.sh --json --no-live}"
# ⚠ NOT written as "${LG_AW_SESSIONS_CMD:-tmux list-sessions -F #{session_name}}".
#   The } of tmux's format string CLOSES the parameter expansion early, and the
#   leftover } is then appended as a separate word. Measured: production would
#   have run `tmux list-sessions -F '#{session_name' '}'`, which errors, so the
#   session list would be EMPTY FOREVER — G4 could never fire and the fleet-down
#   hold would fire on every tick. Silent, and in the safe-looking direction.
SESSIONS_CMD="${LG_AW_SESSIONS_CMD:-}"
[[ -n "$SESSIONS_CMD" ]] || SESSIONS_CMD="tmux list-sessions -F #{session_name}"
SPIN_CMD="${LG_AW_SPIN_CMD:-}"
MSG_CMD="${LG_AW_MSG_CMD:-msg send ubuntu}"
QUIET_FILES="${LG_AW_QUIET_FILES:-/tmp/keeper-quiet $HOME/.keeper-quiet}"
MANIFEST="${LG_AW_MANIFEST:-$HOME/.fleet-manifest}"
BELL="${LG_AW_BELL:-/tmp/claude-ian-action}"
ISSUES_JSON="${LG_AW_ISSUES_JSON:-}"
LOAD="${LG_AW_LOAD:-$(cut -d' ' -f1 /proc/loadavg)}"
LOAD_MAX="${LG_AW_LOAD_MAX:-4}"

ACKS="$STATE_DIR/.approved-acks"
SPUN="$STATE_DIR/.autospin-log"
DRYLOG="$STATE_DIR/.autospin-dryrun"
REFUSED="$STATE_DIR/.autospin-refused"
HOLDF="$STATE_DIR/.autospin-hold"
MODEF="$STATE_DIR/.autospin-mode"
mkdir -p "$STATE_DIR"
touch "$ACKS" "$SPUN" "$DRYLOG" "$REFUSED"

# Board posts are shell-evaluated on send: a backticked span is command-
# substituted AWAY before msg ever sees it, and it has bitten two lanes. Strip
# the characters that execute, here, once, for every post this script makes.
post() {
    local body
    body="$(printf '%s' "$1" | tr -d '`$"\\')"
    $MSG_CMD "$body" >/dev/null 2>&1 || true
}

# ── issues: the fixture path exists so the gate never touches the network and
#    can never burn rate limit or flake on an API blip (gate 77's rule).
fetch_issues() {
    if [[ -n "$ISSUES_JSON" ]]; then cat "$ISSUES_JSON"; return 0; fi
    local token
    token="$(grep '^LG_GITHUB_ISSUES_TOKEN=' /etc/looth/env 2>/dev/null | cut -d= -f2 || true)"
    [[ -n "$token" ]] || return 0
    curl -s -m 20 -H "Authorization: Bearer $token" \
        'https://api.github.com/repos/iandavlin/loothplatformv2/issues?labels=approved&state=open&per_page=50' \
        || true
}

ISSUES="$(fetch_issues | python3 -c '
import json, sys
try:
    data = json.load(sys.stdin)
except Exception:
    sys.exit(0)
if not isinstance(data, list):
    sys.exit(0)
for i in data:
    if isinstance(i, dict) and i.get("number"):
        print("%d\t%s" % (i["number"], (i.get("title") or "")[:70]))
' || true)"

# ── PHASE A — the bell. Unchanged behaviour: approval alone rings, nothing more.
while IFS=$'\t' read -r n title; do
    [[ -n "${n:-}" ]] || continue
    if grep -qx "$n" "$ACKS"; then continue; fi
    echo "$n" >> "$ACKS"
    post "approved-watcher: issue #$n is APPROVED and awaiting keeper — $title"
    touch "$BELL"
done <<< "$ISSUES"

[[ -n "${ISSUES//[[:space:]]/}" ]] || exit 0

# ── PHASE B ───────────────────────────────────────────────────────────────────

# refuse <n> <reason> — posted ONCE per (issue, reason). A refusal that reposts
# every five minutes trains keeper to stop reading the board, which costs more
# than the refusal it was announcing.
refuse() {
    local key="$1|$2"
    if grep -qxF "$key" "$REFUSED"; then return 0; fi
    printf '%s\n' "$key" >> "$REFUSED"
    post "approved-watcher: NOT spinning #$1 — $2"
}

# Holds are EDGE-TRIGGERED: announced when the reason changes (including when it
# clears), never on every tick.
announce_hold() {
    local now="${1:-}" was=""
    [[ -f "$HOLDF" ]] && was="$(cat "$HOLDF")"
    [[ "$now" == "$was" ]] && return 0
    printf '%s' "$now" > "$HOLDF"
    if [[ -n "$now" ]]; then
        post "approved-watcher: auto-spin HELD — $now"
    else
        post "approved-watcher: auto-spin hold cleared — watching again"
    fi
}

SESSIONS="$($SESSIONS_CMD 2>/dev/null || true)"
LANE_SESSIONS=0
while read -r s; do
    [[ -n "${s:-}" && -d "$WORKTREES/$s" ]] && LANE_SESSIONS=$((LANE_SESSIONS + 1))
done <<< "$SESSIONS"

HOLD=""
for q in $QUIET_FILES; do
    [[ -e "$q" ]] && HOLD="keeper-quiet is set ($q) — no lane starts itself while that file exists"
done
if [[ -z "$HOLD" && -s "$MANIFEST" && "$LANE_SESSIONS" -eq 0 ]]; then
    HOLD="the fleet is DOWN ($(wc -l < "$MANIFEST") lane(s) in the manifest, zero live sessions). Reboot signature — respawn-fleet is deliberately manual, so the watcher will not relight the box"
fi
if [[ -z "$HOLD" ]] && awk -v l="$LOAD" -v m="$LOAD_MAX" 'BEGIN{exit !(l+0 > m+0)}'; then
    HOLD="1-min load is $LOAD (over $LOAD_MAX) — spinning into that is how Ian's own pages timed out on 8/15"
fi

# ── pass 1: which issues are VIABLE, before anything expensive is measured.
#    lanes-status.sh runs a git fetch, so it is not paid on a tick with nothing
#    to spin — which is almost every tick.
VIABLE_N=(); VIABLE_LANE=(); VIABLE_WT=(); VIABLE_CHARTER=()
while IFS=$'\t' read -r n title; do
    [[ -n "${n:-}" ]] || continue
    if grep -qx "$n" "$SPUN"; then continue; fi              # G3: one spin ever

    shopt -s nullglob
    charters=( "$PROMPTS/$n"-*.md )
    shopt -u nullglob
    (( ${#charters[@]} == 0 )) && continue                   # G1: bell only
    if (( ${#charters[@]} > 1 )); then
        names=""; for c in "${charters[@]}"; do names+="$(basename "$c") "; done
        refuse "$n" "two charters name this issue ($names) — the charter names the seat, so keeper picks which one, not the watcher"
        continue
    fi
    lane="$(basename "${charters[0]}" .md)"

    # G4: somebody is already sitting there
    if printf '%s\n' "$SESSIONS" | grep -qxF "$lane"; then continue; fi

    wt="$WORKTREES/$lane"
    if [[ -d "$wt" ]]; then
        # G5: folder==branch is a promise LANE-RULES makes; never repair it here,
        # because the repair is a checkout and that is the "which folder am I in"
        # hazard the promise exists to prevent.
        br="$(git -C "$wt" rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')"
        if [[ "$br" != "$lane" ]]; then
            refuse "$n" "the worktree at $wt is on branch $br, not $lane — folder and branch must match (LANE-RULES); re-cut it, never checkout"
            continue
        fi
    else
        # G3 backstop: a branch with no worktree is a lane that ALREADY RAN.
        if git -C "$REPO" show-ref --verify -q "refs/heads/$lane" \
        || git -C "$REPO" show-ref --verify -q "refs/remotes/origin/$lane"; then
            refuse "$n" "branch $lane already exists with no worktree — that is a prior lane (parked or merged), and resuming one is keeper's decision, never a re-spin"
            continue
        fi
    fi

    VIABLE_N+=("$n"); VIABLE_LANE+=("$lane"); VIABLE_WT+=("$wt")
    VIABLE_CHARTER+=("${charters[0]}")
done <<< "$ISSUES"

if (( ${#VIABLE_N[@]} == 0 )); then
    # Nothing to spin ⇒ a hold is not costing anything ⇒ do not announce one.
    exit 0
fi

# ── H4: the cap, from lanes-status.sh's own JSON and its own DETECTOR — the
#    8/20 CLI change moved the "working" signature twice in one day, and a second
#    definition of it living here is the one thing #138's charter forbids by name.
#    Read only when nothing else already holds and something is actually waiting:
#    lanes-status runs a git fetch, and that is not paid on a quiet tick.
WORKING=""; CAP=""; SEATS=""; CEIL=""
if [[ -z "$HOLD" ]]; then
CAPJSON="$($LANES_CMD 2>/dev/null || true)"
read -r WORKING CAP SEATS CEIL <<< "$(printf '%s' "$CAPJSON" | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    print("ERR ERR ERR ERR"); sys.exit(0)
c = d.get("capacity", {})
w = sum(1 for l in d.get("lanes", []) if l.get("agent") == "working")
print(w, c.get("working_cap", "ERR"), c.get("seats_used", "ERR"), c.get("seat_ceiling", "ERR"))
' || echo "ERR ERR ERR ERR")"
    if [[ "$WORKING" == ERR || -z "${CAP:-}" || "$CAP" == ERR ]]; then
        # A cap that cannot be read is NOT a cap of zero and NOT a free pass:
        # silence here would mean "healthy", and it is not.
        HOLD="lanes-status returned no readable capacity — refusing to spin without a countable cap"
    elif (( WORKING >= CAP )); then
        HOLD="at the working cap ($WORKING/$CAP) — ${#VIABLE_N[@]} staged lane(s) waiting for a seat"
    fi
fi

# ONE hold decision, ONE announcement per tick. The cap used to be checked twice —
# once here and once in the loop below — and the second copy silently made the
# first UNTESTABLE: red-first mutated this one and the gate stayed green because
# the other still caught it. Redundant guards do not add safety, they subtract
# provability.
announce_hold "$HOLD"
[[ -n "$HOLD" ]] && exit 0

MODE="dry-run"
if [[ -f "$MODEF" ]] && grep -qx "live" "$MODEF"; then MODE="live"; fi

# ── act ───────────────────────────────────────────────────────────────────────
for i in "${!VIABLE_N[@]}"; do
    n="${VIABLE_N[$i]}"; lane="${VIABLE_LANE[$i]}"; wt="${VIABLE_WT[$i]}"
    charter="${VIABLE_CHARTER[$i]}"

    if (( WORKING >= CAP )); then
        announce_hold "at the working cap ($WORKING/$CAP) — $((${#VIABLE_N[@]} - i)) staged lane(s) still waiting"
        break
    fi

    seat_note="worktree pre-staged"
    if [[ ! -d "$wt" ]]; then
        if [[ "$SEATS" == ERR || "$CEIL" == ERR ]] || (( SEATS >= CEIL )); then
            refuse "$n" "$lane needs a worktree cut and seats are $SEATS/$CEIL — the seat ceiling is a ruled number, so keeper frees one first"
            continue
        fi
        seat_note="worktree to be cut from origin/main"
    fi

    if [[ "$MODE" == "dry-run" ]]; then
        if grep -qx "$n" "$DRYLOG"; then continue; fi
        echo "$n" >> "$DRYLOG"
        # NOTE what this does NOT do: it does not write $SPUN. A dry run that
        # consumed the one-spin-ever record would mean the first REAL spin never
        # happens — the flip would arm a watcher with nothing left to fire at.
        post "approved-watcher DRY-RUN: WOULD SPIN $lane for #$n — charter $(basename "$charter"), $seat_note, working $WORKING/$CAP, seats $SEATS/$CEIL. Nothing was spun. Arm it with: echo live > $MODEF"
        continue
    fi

    # LIVE. Record FIRST — see the header: this ordering is what makes a crash
    # cost one lost spin instead of an unbounded spin loop.
    echo "$n" >> "$SPUN"

    if [[ ! -d "$wt" ]]; then
        if ! git -C "$REPO" worktree add -b "$lane" "$wt" origin/main >/dev/null 2>&1; then
            refuse "$n" "could not cut a worktree for $lane off origin/main — recorded as spun so nothing loops; keeper's hands now"
            continue
        fi
        # A worktree cut from origin/main TRACKS MAIN. A bare `git push` from the
        # lane would then target main and bypass the wall entirely (75a0fb6, hit
        # live by lanes 165 and 170). The upstream must BE origin/<lane>.
        if ! git -C "$wt" push -q -u origin "$lane" >/dev/null 2>&1; then
            refuse "$n" "cut $wt but could not push -u origin $lane — its upstream is NOT origin/$lane, so it is not safe to spin; keeper's hands"
            continue
        fi
    fi

    # A stale hand-raise from a previous occupant would make the page report the
    # fresh lane as already DONE. Keeper's own spin wrappers clear it; so does this.
    rm -rf "$wt/.lane-state"

    if [[ -n "$SPIN_CMD" ]]; then
        ok=0; $SPIN_CMD "$lane" "$wt" "$n" || ok=$?
    else
        ok=0
        tmux new-session -d -s "$lane" \
            "cd '$wt' && export LG_LANE=1 && exec bash '$HERE/lanes/spin-lane.sh' '$lane'" || ok=$?
    fi

    if (( ok != 0 )); then
        refuse "$n" "the spin of $lane failed (exit $ok) — recorded as spun so nothing loops; keeper's hands now"
        continue
    fi

    WORKING=$((WORKING + 1))
    post "approved-watcher: SPUN $lane for #$n — charter $(basename "$charter"), $seat_note, working now $WORKING/$CAP. One spin per issue ever; recorded in $SPUN."
done
