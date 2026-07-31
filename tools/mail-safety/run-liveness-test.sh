#!/usr/bin/env bash
# Mail-containment liveness test (backlog #15).
#
# Proves that lg-dev-mail-containment.php is a NO-OP on live, by running the
# REAL plugin against LIVE's REAL /etc/looth/env, at the REAL path, as the REAL
# pool user (looth-dev) — no PHP-level faking of the environment.
#
# HOW THE ENV IS SIMULATED WITHOUT TOUCHING THE BOX:
#   `unshare -m` gives us a PRIVATE mount namespace. The bind-mount over
#   /etc/looth/env is visible ONLY to the probe process and disappears when it
#   exits. Nothing on dev2 is modified, and live is never contacted.
#
# WHY THIS SHAPE: the defect is "mail silently swallowed and reported as SENT".
# The containment filter returns true even when mailpit is down, so asserting
# "wp_mail() returned true" is a guaranteed false PASS
# (lg-weekly-digest/dev/build-inbox-test.php:9-10 documents exactly that trap).
# We therefore assert on whether the plugin CLAIMS the send, not on send success.
#
#   ./run-liveness-test.sh before   # must reproduce the defect, else harness VOID
#   ./run-liveness-test.sh after    # must satisfy the full expectation table
set -uo pipefail

PHASE="${1:-after}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROBE="$HERE/containment-liveness-probe.php"
WORK="$(mktemp -d /tmp/mail-safety-XXXXXX)"
chmod 0755 "$WORK"
LOGDIR="$WORK/logs"; mkdir -p "$LOGDIR"; chmod 0777 "$LOGDIR"

# LIVE's real /etc/looth/env, byte-verified against live-ro on 2026-07-31.
# NOTE THE POINT OF THE WHOLE TICKET: LG_ENV is dev2 on live too. The ONLY key
# that differs between the two boxes is LG_PUBLIC_HOST.
cat > "$WORK/env.live" <<'EOF'
LG_ENV=dev2
LG_PUBLIC_HOST=loothgroup.com
LG_WP_PATH=/var/www/dev
LG_WP_USER=looth-dev
LG_MYSQL_DB=looth_import
LG_MYSQL_BILLING_DB=lg_membership
LG_PG_DB=looth
LG_PG_DB_PROFILE=profile_app
LG_GATE_COOKIE=loothdev_auth
EOF
sed 's/^LG_PUBLIC_HOST=.*/LG_PUBLIC_HOST=www.loothgroup.com/' "$WORK/env.live" > "$WORK/env.live-www"
sed 's/^LG_PUBLIC_HOST=.*/LG_PUBLIC_HOST=dev2.loothgroup.com/' "$WORK/env.live" > "$WORK/env.dev2"
chmod 0644 "$WORK"/env.*

# run_probe <env-file|__ABSENT__> <http-host|""> <lg-env-constant|"">
run_probe() {
    local envfile="$1" http_host="$2" lg_env="$3" mountcmd
    if [ "$envfile" = "__ABSENT__" ]; then
        mountcmd='mount -t tmpfs none /etc/looth'       # /etc/looth/env ceases to exist
    else
        mountcmd="mount --bind '$envfile' /etc/looth/env"
    fi
    sudo -n unshare -m bash -c "
        $mountcmd || exit 97
        exec sudo -n -u looth-dev env \
            PROBE_LOG_DIR='$LOGDIR' \
            PROBE_HTTP_HOST='$http_host' \
            PROBE_DEFINE_LG_ENV='$lg_env' \
            PROBE_SUBJECT='LG mail-safety probe [$PHASE]' \
            php '$PROBE'
    " 2>/dev/null
}

pass=0; fail=0

# check <name> <expected> <env-file> <http-host> <lg-env-const> <why>
check() {
    local name="$1" expect="$2" envfile="$3" host="$4" lgenv="$5" why="$6"
    local out verdict user simulate
    out="$(run_probe "$envfile" "$host" "$lgenv")"
    verdict="$(printf '%s' "$out" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["verdict"] ?? "ERROR";' 2>/dev/null)"
    user="$(printf '%s' "$out" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["running_as_user"] ?? "?";' 2>/dev/null)"
    simulate="$(printf '%s' "$out" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo !empty($d["fluentmail_simulate_emails"])?"yes":"no";' 2>/dev/null)"

    if [ "$verdict" = "$expect" ]; then
        printf '  \033[32mPASS\033[0m  %-22s %-8s (as %s, FLUENTMAIL_SIMULATE=%s)\n' "$name" "$verdict" "$user" "$simulate"
        pass=$((pass+1))
    else
        printf '  \033[31mFAIL\033[0m  %-22s got %-8s want %-8s  — %s\n' "$name" "$verdict" "$expect" "$why"
        [ -n "$out" ] && printf '        raw: %s\n' "$out"
        fail=$((fail+1))
    fi
}

echo
echo "=== mail-containment liveness test — phase: $PHASE ==="
echo "    plugin: platform/mu-plugins/lg-dev-mail-containment.php"
echo "    method: private mount namespace over /etc/looth/env, run as looth-dev"
echo

if [ "$PHASE" = "before" ]; then
    echo "  BEFORE control — the defect MUST reproduce or this harness is void:"
    check "live-sim(web)"  CONTAIN "$WORK/env.live" "loothgroup.com" "" \
          "today's code must swallow live mail — if it does not, the probe is not reaching the plugin"
    check "live-sim(cli)"  CONTAIN "$WORK/env.live" "" "" \
          "cron/wp-cli path on live must also reproduce"
    check "dev2-control"   CONTAIN "$WORK/env.dev2" "dev2.loothgroup.com" "" \
          "dev2 containment is the behaviour we must PRESERVE"
else
    echo "  AFTER — containment must be a no-op on live, unchanged on dev2:"
    check "live-sim(web)"   PROCEED "$WORK/env.live"     "loothgroup.com"      "" \
          "live must never be contained"
    check "live-sim(cli)"   PROCEED "$WORK/env.live"     ""                    "" \
          "live cron/wp-cli must never be contained"
    check "live-www"        PROCEED "$WORK/env.live-www" "www.loothgroup.com"  "" \
          "the www apex is live too"
    check "no-host-failsafe" PROCEED "__ABSENT__"        ""                    "dev2" \
          "host undeterminable => FAIL SAFE, do not swallow"
    check "dev2-control"    CONTAIN "$WORK/env.dev2"     "dev2.loothgroup.com" "" \
          "dev2 containment MUST still work — no regression"
    check "dev2-cli"        CONTAIN "$WORK/env.dev2"     ""                    "" \
          "dev2 cron/wp-cli must still be contained"
    check "spoofed-host"    CONTAIN "$WORK/env.dev2"     "loothgroup.com"      "" \
          "a forged Host header must NOT switch dev2 containment off"
fi

echo
if [ "$fail" -eq 0 ]; then
    echo "  RESULT: ${pass} passed, 0 failed"
    [ "$PHASE" = "before" ] && echo "  => defect reproduced; the harness can see it."
else
    echo -e "  \033[31mRESULT: ${pass} passed, ${fail} FAILED\033[0m"
fi
rm -rf "$WORK"
[ "$fail" -eq 0 ]
