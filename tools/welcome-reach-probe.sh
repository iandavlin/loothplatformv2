#!/usr/bin/env bash
# Does the welcome MODAL actually reach a member who is owed one?
#
# The trigger fix makes the one-shot fire. That is worth nothing if the modal
# never renders on the surfaces a new member actually lands on. 118 members have
# carried an UNCONSUMED _lg_pending_welcome stamp for months, which is either
# "they never came back" or "the modal has nowhere to print" — and those want
# opposite fixes.
#
# The modal prints on wp_footer. The hub and profiles are strangler surfaces, so
# the question is whether wp_footer runs there at all.
#
# ⚠️ THE TRAP THIS IS BUILT AGAINST: "the modal appears nowhere" is exactly what a
# BROKEN PROBE looks like — a bad cookie, a gate 403, a member without the stamp.
# So nothing here is believed until the probe proves itself: the session must
# authenticate, and the modal must render on at least ONE surface. An absence
# assertion without a liveness assertion is worth nothing.
#
# Probe member is PID-keyed and deleted at the end, whatever happens: a fixed test
# account gives false results the moment another lane writes concurrently.
set -uo pipefail
cd "$(dirname "$0")/.."
WP="/var/www/dev"
HOST="dev2.loothgroup.com"
RES="--resolve ${HOST}:443:127.0.0.1"
LOGIN="lgreach$$"

wpc() { sudo -n wp --allow-root --path="$WP" "$@" 2>/dev/null | grep -v "PHP Warning\|already defined"; }

GATE=$(bash tools/gates/gate-env.sh 2>/dev/null | grep '^LG_GATE_TOKEN=' | cut -d= -f2)
[ -z "$GATE" ] && { echo "CANNOT RUN — no dev-gate token"; exit 2; }

UID_=$(wpc user create "$LOGIN" "${LOGIN}@example.invalid" --role=looth3 --porcelain)
case "$UID_" in ''|*[!0-9]*) echo "CANNOT RUN — could not create probe member: $UID_"; exit 2;; esac
cleanup() { wpc user delete "$UID_" --yes >/dev/null 2>&1; }
trap cleanup EXIT

wpc user meta update "$UID_" _lg_pending_welcome looth3 >/dev/null
STAMP=$(wpc user meta get "$UID_" _lg_pending_welcome)
[ "$STAMP" = "looth3" ] || { echo "CANNOT RUN — the stamp did not stick (got '$STAMP')"; exit 2; }

COOKIE=$(LG_SHOT_UID="$UID_" sudo -n -E wp --allow-root --path="$WP" eval-file tools/preview/mint-wp-session.php 2>/dev/null | grep -o '{.*}')
CNAME=$(echo "$COOKIE" | python3 -c "import json,sys;print(json.load(sys.stdin)['cookie'])")
CVAL=$(echo "$COOKIE" | python3 -c "import json,sys;print(json.load(sys.stdin)['value'])")
[ -z "$CVAL" ] && { echo "CANNOT RUN — could not mint a session"; exit 2; }

echo "probe member #$UID_ ($LOGIN), looth3, _lg_pending_welcome=looth3"
echo "======================================================================="
printf "%-26s %6s %8s %8s %8s\n" "surface" "http" "loggedin" "wp_footer" "MODAL"

AUTHED=0; MODAL_ANYWHERE=0
for U in "/" "/hub/" "/events/" "/directory/members/" "/members/${LOGIN}/" "/sponsors/"; do
  BODY=$(curl -s --max-time 25 $RES \
      -H "Cookie: loothdev_auth=${GATE}; ${CNAME}=${CVAL}" \
      -w '\n__HTTP__%{http_code}' "https://${HOST}${U}" 2>/dev/null)
  CODE=$(printf '%s' "$BODY" | tail -1 | sed 's/^__HTTP__//')
  HTML=$(printf '%s' "$BODY" | sed '$d')

  # Logged-in tell: WP emits a logout nonce / the member's own login for a
  # signed-in request. Either is enough; both absent means the cookie failed.
  LI=no
  printf '%s' "$HTML" | grep -qE "wp-logout|logout_url|${LOGIN}" && LI=yes
  [ "$LI" = yes ] && AUTHED=1

  # Does wp_footer emit ANYTHING here? wp_footer is where the modal prints, so a
  # surface with no wp_footer output cannot show it whatever the stamp says.
  WF=no
  printf '%s' "$HTML" | grep -qE "wp-includes/js|wp-emoji|/wp-json/" && WF=yes

  MD=no
  printf '%s' "$HTML" | grep -q "lg-welcome-modal" && { MD=YES; MODAL_ANYWHERE=1; }

  printf "%-26s %6s %8s %8s %8s\n" "$U" "$CODE" "$LI" "$WF" "$MD"
done

echo "======================================================================="
if [ "$AUTHED" = 0 ]; then
  echo "INCONCLUSIVE — the session never authenticated on ANY surface."
  echo "Every 'no modal' above is what a broken cookie looks like, not a finding."
  exit 2
fi
echo "liveness: the session DID authenticate — the absences above are real absences"
if [ "$MODAL_ANYWHERE" = 1 ]; then
  echo "RESULT: the modal CAN render. Where it is missing is surface-specific."
else
  echo "RESULT: the modal rendered on NONE of these surfaces, for a member who is"
  echo "        owed one and whose stamp is present. The one-shot has nowhere to"
  echo "        print on the pages a new member actually lands on."
fi
