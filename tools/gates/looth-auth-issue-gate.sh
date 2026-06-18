#!/bin/bash
# looth-auth-issue-gate.sh — the non-REST looth_id mint bounce (docs/CRAFT-STANDARD.md).
#
# Encodes the cut-critical defect the profile-app lane proved 2026-06-15 and that
# RECURS ON EVERY DB RELOAD (the cut imports a DB), so a regression fails here
# instead of silently shipping "logged-in members can't edit their own profile":
#
#   ISSUE-NON-REST   GET /looth-auth/issue must mint+302 for a plain navigation
#                    (cookie, NO wp_rest nonce). The old target,
#                    /wp-json/looth/auth/issue, fails two ways that both come
#                    back after a reload: BuddyBoss's REST gate (wp_option
#                    bb-enable-private-rest-apis) → 401, and WP REST cookie-auth
#                    needing a nonce a navigation never has → wp-login. The
#                    non-REST handler (mu-plugins/looth-auth-issue.php) has
#                    neither failure; this gate proves it stays that way.
#
# Positive control: a VALID member session cookie must yield a `looth_id`
# Set-Cookie (proves the handler actually minted — else the gate is blind to a
# minter regression and only checks routing).
#
# Run as ubuntu on dev (mints a control session via sudo wp-cli). Exit 0 = GREEN.
set -uo pipefail

HOST="https://dev.loothgroup.com"
CONF="/etc/nginx/sites-available/dev.loothgroup.com.conf"
WP="/var/www/dev"
RET="/u/patreon_84629041"          # any existing same-origin path; we assert it round-trips
ISSUE="$HOST/looth-auth/issue?return=$(python3 -c 'import urllib.parse;print(urllib.parse.quote("/u/patreon_84629041"))')"
fails=()

GATE=$(grep -oP '(?<=set \$loothdev_token ")[^"]+' "$CONF" | head -1)
[ -n "${GATE:-}" ] || { echo "GATE-ERROR  cannot read dev gate token from $CONF"; exit 1; }

# A logged-in member, bound to a REAL session token (an unregistered token does
# not validate, so is_user_logged_in() would be false — the control must be a
# true session). User 7 = a bridged member with a _looth_uuid mirror.
read MLIN MLIV < <(sudo -u www-data wp --path="$WP" eval '
  $uid=7; $exp=time()+3600;
  $t=WP_Session_Tokens::get_instance($uid)->create($exp);
  echo LOGGED_IN_COOKIE." ".wp_generate_auth_cookie($uid,$exp,"logged_in",$t);
' 2>/dev/null)
[ -n "${MLIV:-}" ] || { echo "GATE-ERROR  could not mint control member cookie"; exit 1; }

hdrs() { curl -s -D - -o /dev/null --max-redirs 0 -b "$1" "$ISSUE"; }

# ---- 1. logged-in member, NO nonce → 302 back to ?return, with a looth_id ----
H=$(hdrs "loothdev_auth=$GATE; $MLIN=$MLIV")
code=$(printf '%s' "$H" | awk 'NR==1{print $2}')
loc=$(printf '%s' "$H" | grep -i '^location:' | tr -d '\r' | awk '{print $2}')
mint=$(printf '%s' "$H" | grep -ci '^set-cookie: looth_id=')

if [ "$code" != "302" ]; then
  fails+=("ISSUE-NON-REST   member nav returned HTTP ${code:-<none>} (expected 302; 401 = BB REST gate caught a route that must be non-REST)")
fi
if [ "$loc" != "$RET" ]; then
  fails+=("ISSUE-RETURN     redirected to '${loc:-<none>}' (expected '$RET' — same-origin return must round-trip)")
fi
if [ "$mint" -lt 1 ]; then
  fails+=("ISSUE-MINT-CTRL  no looth_id Set-Cookie for a valid member (handler routed but did not mint — gate would be blind to a minter break)")
fi

# ---- 2. logged-OUT → 302 to wp-login (route is live + non-REST even w/o auth) ----
H2=$(hdrs "loothdev_auth=$GATE")
code2=$(printf '%s' "$H2" | awk 'NR==1{print $2}')
loc2=$(printf '%s' "$H2" | grep -i '^location:' | tr -d '\r' | awk '{print $2}')
if [ "$code2" != "302" ] || ! printf '%s' "$loc2" | grep -q 'wp-login.php'; then
  fails+=("ISSUE-LOGGEDOUT  anon hit returned HTTP ${code2:-<none>} loc='${loc2:-<none>}' (expected 302 → wp-login)")
fi

# ---- 3. off-host ?return must NOT be honored (open-redirect guard) ----
loc3=$(curl -s -D - -o /dev/null --max-redirs 0 -b "loothdev_auth=$GATE; $MLIN=$MLIV" \
        "$HOST/looth-auth/issue?return=https://evil.example/x" | grep -i '^location:' | tr -d '\r' | awk '{print $2}')
if printf '%s' "$loc3" | grep -qi 'evil.example'; then
  fails+=("ISSUE-OPENREDIR  off-host return honored ('$loc3') — must fall back to a same-origin path")
fi

echo "looth-auth-issue-gate: code=$code return='$loc' mint=$mint  anon_code=$code2"
if [ "${#fails[@]}" -ne 0 ]; then
  echo "==================== LOOTH-AUTH-ISSUE GATE RED (${#fails[@]}) ===================="
  for f in "${fails[@]}"; do echo "  $f"; done
  exit 1
fi
echo "==================== LOOTH-AUTH-ISSUE GATE GREEN ===================="
