# gate-token.sh — ONE resolver for the dev-gate cookie value. Source, don't run.
#
# WHY THIS EXISTS (profile-audit, 2026-07-28): four of the five gates in
# run-all.sh each carried their own copy of
#     grep -oP '(?<=set \$loothdev_token ")[^"]+' /etc/nginx/sites-available/dev.loothgroup.com.conf
# That file NO LONGER EXISTS. The gate moved to a cookie map in the BOX-LOCAL
#     /etc/nginx/conf.d/loothdev-auth.conf
#       map $cookie_loothdev_auth $loothdev_dev_ok { default 0; "<token>" 1; }
# so gates 1, 2, 3 and 5 all died at "cannot read dev gate token" and run-all.sh
# reported RED for every lane, for a config relocation. A gate that CANNOT RUN
# looks identical to a gate that FAILS, which is how this survived: everyone read
# the red as someone else's problem.
#
# One copy now. If the conf moves again, fix it here, not in five places.
#
# NOTE the REPO copy of platform/nginx/loothdev-auth.conf is the gate-free LIVE
# posture and never contains a token. Only the box-local conf.d file is armed.
#
# Usage:
#   . "$(dirname "$0")/lib/gate-token.sh"
#   GATE=$(gate_token) || { echo "GATE-ERROR $GATE_TOKEN_ERR"; exit 1; }
#
# Override for a box where neither path applies:  LG_DEV_GATE_TOKEN=<value>

#
# ALSO PROVIDES gate_curl() — see the bottom of this file. Same drift family: the
# gates curl https://dev.loothgroup.com by name, and that name resolves to an IP
# THIS BOX CANNOT REACH, so a gate hangs on connect with no output at all rather
# than failing. infra-sec-gate ran 7 minutes and printed nothing.

GATE_TOKEN_ERR=''

gate_token() {
    if [ -n "${LG_DEV_GATE_TOKEN:-}" ]; then printf '%s' "$LG_DEV_GATE_TOKEN"; return 0; fi

    local t=''
    # current: cookie map in the box-local conf.d
    if [ -r /etc/nginx/conf.d/loothdev-auth.conf ]; then
        t=$(grep -oP 'map\s+\$cookie_loothdev_auth.*?"\K[^"]+' \
              /etc/nginx/conf.d/loothdev-auth.conf | head -1)
    fi
    # legacy: set $loothdev_token in the vhost (kept so this works on an older box)
    if [ -z "$t" ] && [ -r /etc/nginx/sites-available/dev.loothgroup.com.conf ]; then
        t=$(grep -oP '(?<=set \$loothdev_token ")[^"]+' \
              /etc/nginx/sites-available/dev.loothgroup.com.conf | head -1)
    fi

    if [ -z "$t" ]; then
        GATE_TOKEN_ERR="cannot read dev gate token — tried conf.d/loothdev-auth.conf then sites-available/dev.loothgroup.com.conf; override with LG_DEV_GATE_TOKEN"
        return 1
    fi
    printf '%s' "$t"
}

# ---------------------------------------------------------------------------
# gate_curl — curl with the box's reachability facts baked in. Use INSTEAD of
# bare `curl` in every gate that talks to dev.loothgroup.com.
#
# Two measured facts (dev2, 2026-07-28):
#  1. dev.loothgroup.com resolves to 50.19.198.38, which this box cannot reach.
#     A bare curl HANGS on connect — no output, no error, no failure. That is
#     worse than red: the gate looks like it is still working.
#  2. Pinned, the cert offered is CN=buck-dev2.loothgroup.com, so peer
#     verification fails -> -k.
#
# Pin to the box's INTERNAL ip, NOT 127.0.0.1. Loopback is treated as an
# internal/trusted caller by at least one app path (profile-app
# api/v0/users.php:18 skips the anon 401 and skips slug-stripping), so a
# loopback-pinned gate tests a code path real users never take.
#
# --max-time is mandatory: a gate that hangs is a gate that lies.
gate_host_ip() {
    ip -4 addr show scope global 2>/dev/null | grep -oP 'inet \K[0-9.]+' | head -1
}

gate_curl() {
    local ip; ip=$(gate_host_ip)
    [ -n "$ip" ] || ip=127.0.0.1
    curl -k --resolve "dev.loothgroup.com:443:$ip" --max-time "${GATE_CURL_TIMEOUT:-20}" "$@"
}
