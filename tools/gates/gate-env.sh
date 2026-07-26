#!/bin/bash
# gate-env.sh — the ONE place that answers "which box am I gating, and with what
# token" (docs/CRAFT-STANDARD.md).
#
# WHY THIS FILE EXISTS. Five gate entry points each hardcoded the same two facts
# — `https://dev.loothgroup.com` and
# `/etc/nginx/sites-available/dev.loothgroup.com.conf` — so when this box became
# dev2 and the tokens moved into a box-local include, EVERY gate that reads a
# token died on `cannot read dev gate token` and the CLAUDE.md gate law went
# unrunnable for every lane. A harness that dies on its own environment drift is
# itself a defect class; the cure is one resolver with fallbacks, not five
# copies of a path.
#
# RESOLUTION, in order, each step falling back rather than replacing:
#   vhost   LG_GATE_VHOST, else the first CANDIDATE_VHOSTS that exists.
#           This is the conf a gate greps for perimeter assertions (e.g.
#           infra-sec's CDP-IN-PROD check) — it is NOT necessarily where the
#           token lives, and the two must not be conflated: pointing a conf
#           grep at the token snippet would make that assertion vacuously
#           green, i.e. blind.
#   host    LG_GATE_HOST, else derived from the vhost FILENAME (dev2.loothgroup
#           .com.conf -> https://dev2.loothgroup.com). Not from `server_name`:
#           this box's dev2 vhost lists `loothgroup.com` first, which is LIVE.
#   token   LG_GATE_TOKEN, else the first of CANDIDATE_TOKEN_SRCS that actually
#           yields a `set $loothdev_token "…";` line. The box-local snippet is
#           preferred; the legacy vhost still works on a box that has it.
#   fail    loudly, naming every path tried — never silently tokenless.
#
# LOOPBACK PIN. The public edge is behind Cloudflare, which challenges
# non-browser clients (`cf-mitigated: challenge`, HTTP 403), so a curl gate hits
# a challenge page instead of our app. Exports LG_GATE_RESOLVE, a curl
# --resolve flag pinning the gate host to 127.0.0.1, exactly as the CDP runbook
# already pins Chrome via --host-resolver-rules. It is NOT applied when the host
# was overridden by env (a deliberate LIVE acceptance run must reach the real
# edge), and LG_GATE_NO_RESOLVE=1 forces it off.
#
# Usage:
#   source "$(dirname "$0")/gate-env.sh"   # exports LG_GATE_* into a shell gate
#   bash gate-env.sh                       # prints KEY=VALUE for python/php gates

CANDIDATE_VHOSTS=(
    /etc/nginx/sites-available/dev2.loothgroup.com.conf
    /etc/nginx/sites-available/dev.loothgroup.com.conf
)
CANDIDATE_TOKEN_SRCS=(
    /etc/nginx/snippets/loothdev-tokens.conf
    /etc/nginx/sites-available/dev2.loothgroup.com.conf
    /etc/nginx/sites-available/dev.loothgroup.com.conf
)

lg_gate_die() { echo "GATE-ENV-ERROR  $1" >&2; return 1; }

lg_gate_resolve_env() {
    local tried v src tok

    # ---- vhost ----
    LG_GATE_VHOST="${LG_GATE_VHOST:-}"
    if [ -z "$LG_GATE_VHOST" ]; then
        for v in "${CANDIDATE_VHOSTS[@]}"; do
            [ -r "$v" ] && { LG_GATE_VHOST="$v"; break; }
        done
    fi
    if [ -z "$LG_GATE_VHOST" ]; then
        tried=$(printf '%s, ' "${CANDIDATE_VHOSTS[@]}"); tried=${tried%, }
        lg_gate_die "no nginx vhost conf found. Tried: $tried. Set LG_GATE_VHOST to this box's conf." || return 1
    fi

    # ---- host (env override disables the loopback pin: a LIVE run must reach the edge) ----
    local host_from_env=0
    if [ -n "${LG_GATE_HOST:-}" ]; then
        host_from_env=1
    else
        LG_GATE_HOST="https://$(basename "$LG_GATE_VHOST" .conf)"
    fi
    LG_GATE_DOMAIN="${LG_GATE_HOST#*://}"; LG_GATE_DOMAIN="${LG_GATE_DOMAIN%%/*}"

    # ---- token ----
    if [ -z "${LG_GATE_TOKEN:-}" ]; then
        for src in "${CANDIDATE_TOKEN_SRCS[@]}"; do
            [ -r "$src" ] || continue
            tok=$(grep -oP '(?<=set \$loothdev_token ")[^"]+' "$src" | head -1)
            [ -n "$tok" ] && { LG_GATE_TOKEN="$tok"; LG_GATE_TOKEN_SRC="$src"; break; }
        done
    else
        LG_GATE_TOKEN_SRC="LG_GATE_TOKEN env"
    fi
    if [ -z "${LG_GATE_TOKEN:-}" ]; then
        tried=$(printf '%s, ' "${CANDIDATE_TOKEN_SRCS[@]}"); tried=${tried%, }
        lg_gate_die "cannot read dev gate token (a 'set \$loothdev_token \"…\";' line). Tried: $tried. Set LG_GATE_TOKEN to override." || return 1
    fi

    # ---- loopback pin ----
    if [ "${LG_GATE_NO_RESOLVE:-0}" = "1" ] || [ "$host_from_env" = "1" ]; then
        LG_GATE_RESOLVE=""
    else
        LG_GATE_RESOLVE="--resolve ${LG_GATE_DOMAIN}:443:127.0.0.1"
    fi
    # For CDP gates: Chrome resolves navigations itself, so the operator must
    # launch it with this flag or every navigation lands on a CF challenge.
    LG_GATE_CHROME_RESOLVER="--host-resolver-rules=MAP ${LG_GATE_DOMAIN} 127.0.0.1"

    export LG_GATE_VHOST LG_GATE_HOST LG_GATE_DOMAIN LG_GATE_TOKEN \
           LG_GATE_TOKEN_SRC LG_GATE_RESOLVE LG_GATE_CHROME_RESOLVER
}

lg_gate_resolve_env || { [ "${BASH_SOURCE[0]}" = "$0" ] && exit 1; return 1; }

# Executed (not sourced) → emit KEY=VALUE for the python / php gates to parse.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    printf 'LG_GATE_VHOST=%s\n'            "$LG_GATE_VHOST"
    printf 'LG_GATE_HOST=%s\n'             "$LG_GATE_HOST"
    printf 'LG_GATE_DOMAIN=%s\n'           "$LG_GATE_DOMAIN"
    printf 'LG_GATE_TOKEN=%s\n'            "$LG_GATE_TOKEN"
    printf 'LG_GATE_TOKEN_SRC=%s\n'        "$LG_GATE_TOKEN_SRC"
    printf 'LG_GATE_RESOLVE=%s\n'          "$LG_GATE_RESOLVE"
    printf 'LG_GATE_CHROME_RESOLVER=%s\n'  "$LG_GATE_CHROME_RESOLVER"
fi
