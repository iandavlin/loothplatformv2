#!/bin/bash
# gate-env.sh — the ONE place that answers "which box am I gating, and with what
# token" (docs/CRAFT-STANDARD.md).
#
# WHY THIS FILE EXISTS. Five gate entry points each hardcoded the same two facts
# — a hostname and an nginx conf path — so when the platform moved to dev2 and
# the tokens moved into a box-local include, EVERY gate that reads a token died
# on `cannot read dev gate token` and the CLAUDE.md gate law went unrunnable for
# every lane. A harness that dies on its own environment drift is itself a defect
# class; the cure is one resolver, not five copies of a path.
#
# NO HOSTNAME IS WRITTEN IN THIS FILE. The old `dev.loothgroup.com` pair is
# RETIRED (Ian, 2026-07-27) and is deliberately not kept as a fallback — a gate
# that can silently resolve to a dead host is worse than one that fails loudly.
# The box states its own identity instead, so the harness follows whatever box it
# is on without another edit the next time the platform moves.
#
# RESOLUTION, in order:
#   host    LG_GATE_HOST, else LG_PUBLIC_HOST from /etc/looth/env (the same file
#           lg-env.php / profile-auth.php read). NOT from `server_name`: this
#           box's vhost lists `loothgroup.com` first, which is LIVE.
#   vhost   LG_GATE_VHOST, else <sites-available>/<host>.conf. This is the conf a
#           gate greps for perimeter assertions (infra-sec's CDP-IN-PROD) — it is
#           NOT where the token lives, and conflating them would make that
#           assertion vacuously green.
#   token   LG_GATE_TOKEN, else the box-local snippet, else the resolved vhost
#           (only to tolerate a box predating the secret split).
#   fail    loudly, naming every path tried — never silently tokenless.
#
# EDGE-BYPASS PIN. The public edge is behind Cloudflare, which challenges
# non-browser clients (`cf-mitigated: challenge`, HTTP 403), so a curl gate hits
# a challenge page instead of our app. Exports LG_GATE_RESOLVE, a curl --resolve
# flag pinning the gate host to THIS BOX'S LAN ADDRESS — deliberately not
# 127.0.0.1. Both bypass Cloudflare, but loopback also makes every request
# INTERNAL (profile-app trusts REMOTE_ADDR 127.0.0.1/::1 as server-to-server),
# which turns off the anon 401 and the private-slug stripping the gates exist to
# assert. Pinning to loopback does not just weaken those checks, it makes them
# pass vacuously — the gate reports green while testing the wrong code path.
# It is NOT applied when the host was overridden by env (a deliberate LIVE
# acceptance run must reach the real edge); LG_GATE_NO_RESOLVE=1 forces it off;
# LG_GATE_ADDR overrides the address.
#
# Usage:
#   source "$(dirname "$0")/gate-env.sh"   # exports LG_GATE_* into a shell gate
#   bash gate-env.sh                       # prints KEY=VALUE for python/php gates

LG_ENV_FILE="${LG_ENV_FILE:-/etc/looth/env}"
NGINX_AVAILABLE="${NGINX_AVAILABLE:-/etc/nginx/sites-available}"
CANDIDATE_TOKEN_SRCS=( /etc/nginx/snippets/loothdev-tokens.conf )

lg_gate_die() { echo "GATE-ENV-ERROR  $1" >&2; return 1; }

lg_gate_resolve_env() {
    local tried src tok

    # ---- host (explicit override disables the pin: a LIVE run must reach the edge) ----
    local host_from_env=0
    if [ -n "${LG_GATE_HOST:-}" ]; then
        host_from_env=1
    else
        local pub=""
        [ -r "$LG_ENV_FILE" ] && pub=$(sed -n 's/^LG_PUBLIC_HOST=//p' "$LG_ENV_FILE" | head -1 | tr -d '"'"'"' \r')
        if [ -z "$pub" ]; then
            lg_gate_die "no LG_PUBLIC_HOST in $LG_ENV_FILE. Set LG_GATE_HOST, or fix the box env." || return 1
        fi
        LG_GATE_HOST="https://$pub"
    fi
    LG_GATE_DOMAIN="${LG_GATE_HOST#*://}"; LG_GATE_DOMAIN="${LG_GATE_DOMAIN%%/*}"

    # ---- vhost (named FOR the resolved host, not guessed from a list) ----
    # Kept DISTINCT from the token source: infra-sec greps this conf for perimeter
    # assertions (CDP-IN-PROD), and pointing that grep at the token snippet would
    # make the assertion vacuously green, i.e. blind.
    if [ -z "${LG_GATE_VHOST:-}" ]; then
        LG_GATE_VHOST="$NGINX_AVAILABLE/${LG_GATE_DOMAIN}.conf"
    fi
    if [ ! -r "$LG_GATE_VHOST" ]; then
        lg_gate_die "vhost conf not readable: $LG_GATE_VHOST (derived from host $LG_GATE_DOMAIN). Set LG_GATE_VHOST." || return 1
    fi

    # ---- token ----
    # deploy-one-pull deliberately moved the secret OUT of the tracked vhost into a
    # box-local snippet (the repo carries only loothdev-tokens.conf.example), so the
    # snippet is the source of record. The resolved vhost is tried second only to
    # tolerate a box that predates that split — never put the secret back in the
    # tracked conf to make this pass.
    if [ -z "${LG_GATE_TOKEN:-}" ]; then
        for src in "${CANDIDATE_TOKEN_SRCS[@]}" "$LG_GATE_VHOST"; do
            [ -r "$src" ] || continue
            tok=$(grep -oP '(?<=set \$loothdev_token ")[^"]+' "$src" | head -1)
            [ -n "$tok" ] && { LG_GATE_TOKEN="$tok"; LG_GATE_TOKEN_SRC="$src"; break; }
        done
    else
        LG_GATE_TOKEN_SRC="LG_GATE_TOKEN env"
    fi
    if [ -z "${LG_GATE_TOKEN:-}" ]; then
        tried=$(printf '%s, ' "${CANDIDATE_TOKEN_SRCS[@]}" "$LG_GATE_VHOST"); tried=${tried%, }
        lg_gate_die "cannot read dev gate token (a 'set \$loothdev_token \"…\";' line). Tried: $tried. Set LG_GATE_TOKEN to override." || return 1
    fi

    # ---- edge-bypass pin ----
    # Pin to this box's own LAN address, NOT 127.0.0.1. Both skip the Cloudflare
    # challenge, but loopback also makes the request INTERNAL: profile-app treats
    # REMOTE_ADDR in {127.0.0.1, ::1} as a trusted server-to-server caller
    # (api/v0/users.php $lgUsersInternal), which skips the anon 401 and stops
    # stripping a private profile's slug. A gate pinned to loopback therefore
    # cannot see an external-visibility regression at all — it silently exercises
    # the internal code path and reports green. Proved on dev2 2026-07-27: anon
    # GET /profile-api/v0/users via 127.0.0.1 → 200, via the LAN IP → 401.
    # LG_GATE_ADDR overrides; LG_GATE_NO_RESOLVE=1 turns the pin off entirely.
    if [ -z "${LG_GATE_ADDR:-}" ]; then
        LG_GATE_ADDR=$(ip -4 -o addr show scope global 2>/dev/null \
                       | awk '{split($4,a,"/"); print a[1]; exit}')
        [ -z "$LG_GATE_ADDR" ] && LG_GATE_ADDR=127.0.0.1
    fi
    if [ "$LG_GATE_ADDR" = "127.0.0.1" ]; then
        echo "GATE-ENV-WARN  no non-loopback address found; pinning to 127.0.0.1, which makes every" >&2
        echo "               request INTERNAL — external-visibility assertions will pass vacuously." >&2
    fi
    if [ "${LG_GATE_NO_RESOLVE:-0}" = "1" ] || [ "$host_from_env" = "1" ]; then
        LG_GATE_RESOLVE=""
    else
        LG_GATE_RESOLVE="--resolve ${LG_GATE_DOMAIN}:443:${LG_GATE_ADDR}"
    fi
    # For CDP gates: Chrome resolves navigations itself, so the operator must
    # launch it with this flag or every navigation lands on a CF challenge.
    LG_GATE_CHROME_RESOLVER="--host-resolver-rules=MAP ${LG_GATE_DOMAIN} ${LG_GATE_ADDR}"

    export LG_GATE_VHOST LG_GATE_HOST LG_GATE_DOMAIN LG_GATE_TOKEN LG_GATE_ADDR \
           LG_GATE_TOKEN_SRC LG_GATE_RESOLVE LG_GATE_CHROME_RESOLVER
}

lg_gate_resolve_env || { [ "${BASH_SOURCE[0]}" = "$0" ] && exit 2; return 1; }

# Executed (not sourced) → emit KEY=VALUE for the python / php gates to parse.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    printf 'LG_GATE_VHOST=%s\n'            "$LG_GATE_VHOST"
    printf 'LG_GATE_HOST=%s\n'             "$LG_GATE_HOST"
    printf 'LG_GATE_DOMAIN=%s\n'           "$LG_GATE_DOMAIN"
    printf 'LG_GATE_TOKEN=%s\n'            "$LG_GATE_TOKEN"
    printf 'LG_GATE_TOKEN_SRC=%s\n'        "$LG_GATE_TOKEN_SRC"
    printf 'LG_GATE_ADDR=%s\n'            "$LG_GATE_ADDR"
    printf 'LG_GATE_RESOLVE=%s\n'          "$LG_GATE_RESOLVE"
    printf 'LG_GATE_CHROME_RESOLVER=%s\n'  "$LG_GATE_CHROME_RESOLVER"
fi
