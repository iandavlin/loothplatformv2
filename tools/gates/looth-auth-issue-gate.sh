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

# Host / token from the shared resolver — never hardcode them here again
# (see tools/gates/gate-env.sh).
. "$(dirname "$0")/gate-env.sh" || exit 2   # 2 = CANNOT RUN (no host/token), not RED
HOST="$LG_GATE_HOST"
GATE="$LG_GATE_TOKEN"
WP="/var/www/dev"

# ---- the control member, named ONCE ----
# One fixture, not two: the member whose session we mint below is also the owner of
# the profile we bounce back to, so there is a single identity to keep alive.
CTRL_WP_UID="${LG_GATE_CTRL_UID:-7}"

# ---- the return target, resolved FROM THE BOX ----
# NO SLUG IS WRITTEN IN THIS FILE. It used to hardcode `/u/patreon_84629041`, a
# dev1-era handle that was RETIRED on dev2 (slug_history, user 6, 2026-07-17) and
# now 301s. The gate stayed GREEN anyway, because it only asserted that the
# `?return=` parameter echoed back into Location — never that the target resolved.
# A gate that green-lights a dead URL is not testing routing at all. That is the
# same "box identity hardcoded in the harness" defect the host/token resolver
# already had to cure (gate-env.sh), which under docs/CRAFT-STANDARD.md makes it a
# defect class found twice, i.e. one that must be encoded rather than re-fixed.
RET_SLUG="${LG_GATE_RET_SLUG:-}"
if [ -z "$RET_SLUG" ]; then
  # psql as `profile-app` — the role that reaches profile_app by peer auth, the same
  # way bin/mint-dev-token.php does. Live slug only: never a slug_history handle.
  RET_SLUG=$(sudo -u profile-app psql -d profile_app -At -c \
    "SELECT u.slug FROM users u JOIN wp_user_bridge b ON b.user_id = u.id
      WHERE b.wp_user_id = ${CTRL_WP_UID} AND u.slug IS NOT NULL AND u.slug <> ''
        AND u.archived_at IS NULL LIMIT 1" 2>/dev/null | tail -1)
fi
if [ -z "$RET_SLUG" ]; then
  echo "GATE-ERROR  no live slug for control wp_user_id=$CTRL_WP_UID (bridged, unarchived)."
  echo "            Set LG_GATE_RET_SLUG=<slug>, or LG_GATE_CTRL_UID=<wp uid>."
  exit 2
fi
RET="/u/$RET_SLUG"
ISSUE="$HOST/looth-auth/issue?return=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$RET")"
fails=()

# ---- 0. the return target must actually RESOLVE ----
# Without this the ISSUE-RETURN assertion below is vacuous: any string echoes back.
# 200 only — a 301 means the handle was retired out from under the fixture, which is
# exactly how the old hardcoded slug rotted silently.
ret_code=$(curl -s $LG_GATE_RESOLVE -o /dev/null -w '%{http_code}' -b "loothdev_auth=$GATE" "$HOST$RET")
if [ "$ret_code" != "200" ]; then
  fails+=("ISSUE-RET-FIXTURE return target $RET returned HTTP ${ret_code:-<none>} (expected 200; 301 = handle retired to slug_history, so ISSUE-RETURN below would pass vacuously)")
fi

# A logged-in member, bound to a REAL session token (an unregistered token does
# not validate, so is_user_logged_in() would be false — the control must be a
# true session). $CTRL_WP_UID is the same bridged member that owns $RET above.
# wp-cli as looth-dev, NOT www-data: /etc/looth/live-wp-keys.php is
# root:looth-dev 0640, so www-data cannot bootstrap WP and this mint came back
# empty — the gate then died on "could not mint control member cookie".
read MLIN MLIV < <(sudo -u looth-dev wp --path="$WP" eval '
  $uid='"$CTRL_WP_UID"'; $exp=time()+3600;
  $t=WP_Session_Tokens::get_instance($uid)->create($exp);
  echo LOGGED_IN_COOKIE." ".wp_generate_auth_cookie($uid,$exp,"logged_in",$t);
' 2>/dev/null | tail -1)
[ -n "${MLIV:-}" ] || { echo "GATE-ERROR  could not mint control member cookie"; exit 2; }

hdrs() { curl -s $LG_GATE_RESOLVE -D - -o /dev/null --max-redirs 0 -b "$1" "$ISSUE"; }

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
loc3=$(curl -s $LG_GATE_RESOLVE -D - -o /dev/null --max-redirs 0 -b "loothdev_auth=$GATE; $MLIN=$MLIV" \
        "$HOST/looth-auth/issue?return=https://evil.example/x" | grep -i '^location:' | tr -d '\r' | awk '{print $2}')
if printf '%s' "$loc3" | grep -qi 'evil.example'; then
  fails+=("ISSUE-OPENREDIR  off-host return honored ('$loc3') — must fall back to a same-origin path")
fi

echo "looth-auth-issue-gate: code=$code return='$loc' (fixture $ret_code) mint=$mint  anon_code=$code2"
if [ "${#fails[@]}" -ne 0 ]; then
  echo "==================== LOOTH-AUTH-ISSUE GATE RED (${#fails[@]}) ===================="
  for f in "${fails[@]}"; do echo "  $f"; done
  exit 1
fi
echo "==================== LOOTH-AUTH-ISSUE GATE GREEN ===================="
