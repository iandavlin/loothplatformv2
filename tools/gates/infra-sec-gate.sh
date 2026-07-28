#!/bin/bash
# infra-sec-gate.sh — infra / perimeter security gate (docs/CRAFT-STANDARD.md).
#
# Encodes the defect classes the infra fresh-eyes audit found 2026-06-13, so a
# regression on any of them fails the gate instead of shipping silently:
#
#   AUTH-COOKIE-NAME  a junk `wordpress_logged_in_*` cookie must NOT be served
#                     the member activity bucket. Gate membership on a VALIDATED
#                     cookie (wp_validate_auth_cookie), never the cookie NAME.
#                     Asserted via the X-LG-Activity-Audience response header;
#                     a valid member cookie is the positive control (proves the
#                     header actually discriminates — else the gate is blind).
#   V2-AUTOINDEX      /v2/ must not enumerate the lg-layout-v2 source tree.
#   V2-PHP-SOURCE     /v2/*.php must not be served as readable plaintext source.
#   CDP-IN-PROD       the Chrome remote-debug proxy route + debug port must be
#                     absent from the prod-bound nginx conf (dev is promoted
#                     in-place to live).
#
# Run as ubuntu on dev (mints a positive-control cookie via sudo wp-cli).
# Invoked by tools/gates/run-all.sh. Exit 0 = GREEN, non-zero = RED.
set -uo pipefail

# Host / token / vhost come from the shared resolver — never hardcode them here
# again (see tools/gates/gate-env.sh for why). $CONF stays the VHOST, because
# the CDP-IN-PROD assertion below greps the perimeter conf itself: pointing it
# at the token snippet would make that check vacuously green.
. "$(dirname "$0")/gate-env.sh" || exit 2   # 2 = CANNOT RUN (no host/token), not RED
HOST="$LG_GATE_HOST"
CONF="$LG_GATE_VHOST"
GATE="$LG_GATE_TOKEN"
ACT="$HOST/wp-json/looth/v1/activity?limit=1"
fails=()

audience() {  # $1 = extra cookie(s) appended to the gate cookie
  curl -s $LG_GATE_RESOLVE -D - -o /dev/null -b "loothdev_auth=$GATE${1:+; $1}" "$ACT" \
    | grep -i '^X-LG-Activity-Audience:' | tr -d '\r' | awk '{print tolower($2)}'
}

# ---- 1. junk logged-in cookie must resolve to the PUBLIC bucket ----
a_junk=$(audience "wordpress_logged_in_x=junkjunkjunk")
a_anon=$(audience "")
# wp-cli as looth-dev, NOT www-data: /etc/looth/live-wp-keys.php is root:looth-dev
# 0640, so www-data cannot bootstrap WP at all and the positive control came back
# empty — which trips AUTH-COOKIE-CTRL below, i.e. the gate reports itself blind.
WPC=$(sudo -u looth-dev wp --path=/var/www/dev eval \
      '$e=time()+3600; echo LOGGED_IN_COOKIE."=".wp_generate_auth_cookie(1912,$e,"logged_in");' 2>/dev/null | tail -1)
a_valid=$(audience "$WPC")

if [ "$a_junk" != "public" ]; then
  fails+=("AUTH-COOKIE-NAME  junk wordpress_logged_in_* served '${a_junk:-<no-header>}' bucket (expected public)")
fi
if [ "$a_valid" != "member" ]; then
  fails+=("AUTH-COOKIE-CTRL  valid member cookie served '${a_valid:-<no-header>}' (expected member — header not discriminating, gate would be blind)")
fi

# ---- 2. /v2/ no autoindex, no PHP source ----
for d in "/v2/" "/v2/src/"; do
  code=$(curl -s $LG_GATE_RESOLVE -o /tmp/.v2body -w '%{http_code}' -b "loothdev_auth=$GATE" "$HOST$d")
  if [ "$code" = "200" ] && grep -qi "Index of" /tmp/.v2body; then
    fails+=("V2-AUTOINDEX      $d enumerates the source tree (autoindex must be off)")
  fi
done
rm -f /tmp/.v2body
code_php=$(curl -s $LG_GATE_RESOLVE -o /dev/null -w '%{http_code}' -b "loothdev_auth=$GATE" "$HOST/v2/lg-layout-v2.php")
if [ "$code_php" != "403" ]; then
  fails+=("V2-PHP-SOURCE     /v2/lg-layout-v2.php returned $code_php (must be 403 — never serve source)")
fi

# ---- 3. Chrome remote-debug proxy must be gone from the prod conf ----
if grep -nE '9222|/cdp/|cdp-launcher' "$CONF" >/dev/null 2>&1; then
  fails+=("CDP-IN-PROD       Chrome remote-debug proxy route/port present in $CONF (must be absent)")
fi

echo "infra-sec-gate: token=ok  audience junk='$a_junk' anon='$a_anon' valid='$a_valid'"
if [ "${#fails[@]}" -ne 0 ]; then
  echo "==================== INFRA-SEC GATE RED (${#fails[@]}) ===================="
  for f in "${fails[@]}"; do echo "  $f"; done
  exit 1
fi
echo "==================== INFRA-SEC GATE GREEN ===================="
