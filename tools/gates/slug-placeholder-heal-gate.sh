#!/bin/bash
# slug-placeholder-heal-gate.sh — LG_SLUG_HEAL_PLACEHOLDER, both states.
#
# 0 = green, 1 = RED, 2 = CANNOT RUN (run-all.sh's three-state convention).
#
# Runs the gate TWICE, because each pass alone is a lie:
#   OFF  — proves the flag is a real no-op (and that the fixture/machinery is LIVE, so the
#          absence assertion is not vacuous on a box where nothing could have happened).
#   ON   — proves the heal actually fires, parks the old handle for the 301, and REFUSES
#          the four cases that are a human ruling, not an automatic decision.
#
# RESOLVES THE SCRIPT FROM ITS OWN TREE, not /srv. On dev2 /srv/profile-app symlinks into
# loothplatformv2-clean, which serves MAIN — so a gate that hardcoded /srv would test main's
# bytes while reporting on the branch, which is how a lane "verifies" work it never ran.
# Relative resolution means this tests whichever checkout it ships in, branch or deployed.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
T="$HERE/../../profile-app/bin/test-slug-placeholder-heal.php"

[ -r "$T" ] || { echo "CANNOT RUN: $T not readable"; exit 2; }

# Postgres is reachable only as the profile-app role. Re-exec under it when we are not
# already that user; if sudo is unavailable this is NO VERDICT, never a pass.
AS=(php "$T")
if [ "$(id -un)" != "profile-app" ]; then
  sudo -n -u profile-app true 2>/dev/null || { echo "CANNOT RUN: cannot become profile-app"; exit 2; }
  AS=(sudo -u profile-app php "$T")
fi

rc=0
for mode in "" "--heal-on"; do
  out="$("${AS[@]}" $mode 2>&1)"; c=$?
  echo "$out"
  # The script refuses to report a verdict if the mode it resolved is not the mode asked
  # for, and prints the resolved value — so a run that silently fell back to OFF is RED,
  # not a quiet green on an untested path.
  [ $c -ne 0 ] && rc=1
  echo
done
exit $rc
