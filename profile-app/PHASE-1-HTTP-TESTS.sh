#!/usr/bin/env bash
# profile-app — Phase 1 HTTP smoke tests (inc 1 + inc 2 + inc 3).
#
# Coordinator's step (write-only artifact — author does NOT run it). Mints a real
# looth_id via the dev CLI and exercises every /me block endpoint over the wire.
# Mirror of the curls in PHASE-1-INCREMENT-3-TEST.md §3, packaged to run in one go.
#
#   bash profile-app/PHASE-1-HTTP-TESTS.sh [wp_user_id]   # default 1918 (fixture user id 3)
#
# Notes:
#  - Mint runs as the profile-app role (key + DB peer auth). Token → STDOUT.
#  - Auth is the Bearer header. If the dev cookie gate intercepts /profile-api/,
#    export GATE="loothdev_auth=<value>" and it'll be sent as a cookie too.
#  - /me/craft is a NEW endpoint — until its nginx route + allowlist entry land
#    (PHASE-1-INCREMENT-3-TEST.md §4) it will 403 at nginx. Everything else routes.

set -uo pipefail

WP_USER_ID="${1:-1918}"
BASE="https://dev.loothgroup.com/profile-api/v0"
MINT="/home/ubuntu/projects/profile-app/bin/mint-dev-token.php"

TOKEN="$(sudo -u profile-app php "$MINT" "$WP_USER_ID")" || { echo "mint failed"; exit 1; }
AUTH=(-H "Authorization: Bearer $TOKEN")
[ -n "${GATE:-}" ] && AUTH+=(--cookie "$GATE")
JSON=(-H 'Content-Type: application/json')

hr() { printf '\n=== %s ===\n' "$1"; }
req() { echo "+ curl $*"; curl -sS -w '\n[HTTP %{http_code}]\n' "$@"; }

hr "inc1 · header — GET then PATCH (at_a_glance + ceiling vis)"
req "${AUTH[@]}" "$BASE/me/header"
req "${AUTH[@]}" "${JSON[@]}" -X PATCH "$BASE/me/header" \
  -d '{"at_a_glance":"Repairs, setups, restorations","visibility":"public"}'
req "${AUTH[@]}" "$BASE/me/header"

hr "inc2 · location — GET, exact-vis / precision / pin writes, conflict guard"
req "${AUTH[@]}" "$BASE/me/location"
req "${AUTH[@]}" "${JSON[@]}" -X PUT "$BASE/me/location" -d '{"location_exact_visibility":"member"}'
req "${AUTH[@]}" "${JSON[@]}" -X PUT "$BASE/me/location" -d '{"precision":"neighborhood"}'
req "${AUTH[@]}" "${JSON[@]}" -X PUT "$BASE/me/location" -d '{"pin":{"lat":43.55,"lng":-80.25}}'
echo "# expect 400 conflicting_fields:"
req "${AUTH[@]}" "${JSON[@]}" -X PUT "$BASE/me/location" \
  -d '{"pin":{"lat":43.5,"lng":-80.2},"nominatim":{"display_name":"x","lat":"43","lon":"-80"}}'
req "${AUTH[@]}" "$BASE/me/location"

hr "inc3 · socials — GET, visibility-only autosave, items write"
req "${AUTH[@]}" "$BASE/me/socials"
req "${AUTH[@]}" "${JSON[@]}" -X PUT "$BASE/me/socials" -d '{"visibility":"member"}'
req "${AUTH[@]}" "${JSON[@]}" -X PUT "$BASE/me/socials" \
  -d '{"items":[{"kind":"web","value":"maxmonteguitars.com"},{"kind":"instagram","value":"@maxmonte"}]}'
echo "# expect 400 invalid_visibility:"
req "${AUTH[@]}" "${JSON[@]}" -X PUT "$BASE/me/socials" -d '{"visibility":"bogus"}'

hr "inc3 · craft — GET + PATCH vis  (403 until the nginx route lands)"
req "${AUTH[@]}" "$BASE/me/craft"
req "${AUTH[@]}" "${JSON[@]}" -X PATCH "$BASE/me/craft" -d '{"visibility":"public"}'

echo; echo "done. 200 + assembled-block JSON on each; 400s where annotated."
