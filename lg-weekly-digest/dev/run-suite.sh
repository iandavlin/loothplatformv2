#!/usr/bin/env bash
# run-suite.sh — every digest regression test, with CANNOT-RUN reported LOUDER than RED.
#
#   bash lg-weekly-digest/dev/run-suite.sh
#
# ── WHY THE THREE-STATE RESULT, AND WHY IT IS NOT FUSSINESS ──────────────────
#
# Keeper adopted this rule box-wide on 2026-07-28, from profile-audit: a test that
# CANNOT RUN must be louder than one that FAILS, because a failing test is doing its
# job and a dead one is lying. The craft gate stayed dead for weeks while everyone
# assumed it was passing.
#
# THIS SUITE DEMONSTRATED THE FAILURE MODE THE SAME DAY. Run from the wrong directory,
# all five tests printed:
#
#     Pass --path=`path/to/wordpress` or run `wp core download`.
#
# ...and nothing else. No assertion ran. Skim that in a scroll-back and it reads like
# a warning attached to a pass — the word FAIL never appears. The fix is not "remember
# to cd"; it is to make the harness state a verdict that cannot be misread, and to
# pass --path so the trap cannot recur.
#
# Exit: 0 all green · 1 something RED · 2 something CANNOT RUN (the worst outcome,
# because it means the suite currently proves nothing at all).

set -uo pipefail

# Overridable so the CANNOT-RUN branch can be PROVEN rather than assumed:
#   LG_WP_PATH=/nonexistent bash run-suite.sh   -> must exit 2, not 0 and not 1
WP_PATH="${LG_WP_PATH:-/var/www/dev}"
DEV="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

TESTS=(
  verify-source-boundary            # the to-do admission test
  verify-window-fixed               # the fixed 7-day window
  verify-two-registers              # fresh NAMED / stale COUNTED
  verify-empty-means-no-send        # the recipient filter's logic
  verify-recipient-filter-at-scale  # the same filter over the real subscriber set
  verify-per-recipient              # the REAL smart-code callback, one body -> N emails
  verify-missed-exclusions          # edge status, not is_read, decides (needs the PG role)
  verify-signup-audience            # Ian ruling 6: members TOLD, never silently added; list 3 never written
  verify-signup-page                # the public page's BUILD: six rulings, scoped CSS, anon-safe preview
  verify-unsubscribe-nonmember      # the opt-out link resolves for a contact with NO wp_users row
  verify-preview-flag-off           # flag OFF: the section is ABSENT and nothing else moves
  verify-recap-flag-off             # the MASTER switch: OFF = pre-feature body AND recipients
  verify-preview-frames-the-email   # the iframe shows the EMAIL, not the strangler's front page
  verify-preview-frame-fits         # ...and the framed email is not wider than its frame
)

# RUNNER IS PER-TEST. verify-missed-exclusions talks to Postgres through profile-app's
# own config, and the WP pool (looth-dev) holds ZERO grants on profile_app — run it
# under wp-cli and it fatals on the first query. It went unnoticed precisely because
# it was not in this list.
runner_for() {
  case "$1" in
    verify-missed-exclusions) echo "sudo -u profile-app php" ;;
    # Renders THIS BRANCH's template against a stub, so it can prove the OFF state
    # BEFORE the flag is deployed. Under wp eval-file it would exercise the SERVING
    # class, which has no flag yet, and report CANNOT RUN until after the merge it
    # exists to justify.
    verify-preview-flag-off)  echo "php" ;;
    verify-recap-flag-off)    echo "php" ;;
    *)                        echo "sudo -u looth-dev wp --path=$WP_PATH eval-file" ;;
  esac
}

# A test is GREEN only if it exits 0 AND prints its own success sentinel. Exit code
# alone is not enough: wp-cli exits 0 in situations where the file never ran.
declare -A SENTINEL=(
  [verify-source-boundary]='BOUNDARY HOLDS'
  [verify-window-fixed]='WINDOW IS FIXED AT 7'
  [verify-two-registers]='TWO REGISTERS OK'
  [verify-empty-means-no-send]='EMPTY MEANS NO SEND'
  [verify-recipient-filter-at-scale]='RECIPIENT FILTER HOLDS AT SCALE'
  [verify-per-recipient]='PER-RECIPIENT SEAM HOLDS'
  [verify-missed-exclusions]='EDGE STATUS IS THE AUTHORITY'
  [verify-signup-audience]='SIGNUP AUDIENCE HOLDS'
  [verify-signup-page]='SIGNUP PAGE OK'
  [verify-unsubscribe-nonmember]='NONMEMBER UNSUBSCRIBE OK'
  [verify-preview-flag-off]='PREVIEW FLAG OFF IS A NO-OP'
  [verify-recap-flag-off]='RECAP FLAG OFF IS A NO-OP'
  [verify-preview-frames-the-email]='PREVIEW FRAMES THE EMAIL'
  [verify-preview-frame-fits]='PREVIEW FRAME FITS THE EMAIL'
)

green=0; red=0; dead=0
declare -a DEAD_DETAIL=()

printf '%-38s %s\n' "TEST" "VERDICT"
printf '%s\n' "----------------------------------------------------------------"

for t in "${TESTS[@]}"; do
  f="$DEV/$t.php"
  if [ ! -r "$f" ]; then
    printf '%-38s %s\n' "$t" "!! CANNOT RUN — file missing"
    DEAD_DETAIL+=("$t: $f is not readable")
    dead=$((dead+1)); continue
  fi

  out="$( $(runner_for "$t") "$f" 2>&1 )"
  code=$?
  clean="$(printf '%s' "$out" | grep -v 'DISABLE_WP_CRON')"

  if printf '%s' "$clean" | grep -q "${SENTINEL[$t]}"; then
    printf '%-38s %s\n' "$t" "GREEN"
    green=$((green+1))
  elif [ "$code" -eq 2 ] || printf '%s' "$clean" | grep -qE 'CANNOT RUN|does not seem to be a WordPress|Error establishing|PHP Fatal|not found|No such file|FluentCRM not loaded|Pass --path'; then
    # The test never got to assert anything. This is the loud case.
    #
    # EXIT CODE 2 IS CHECKED FIRST, AND IT IS THE AUTHORITATIVE SIGNAL. This file's
    # own header has always documented "2 = CANNOT RUN", but the classification below
    # used to read only the output TEXT — so a test that exited 2 deliberately (the
    # _load-under-test.php refusal, say) was reported as RED, the quieter verdict,
    # which is the exact inversion this harness exists to prevent. The string patterns
    # stay as a fallback for tools that die while exiting 0 or 255, like wp-cli.
    printf '%-38s %s\n' "$t" "!! CANNOT RUN — see detail below"
    DEAD_DETAIL+=("$t (exit $code): $(printf '%s' "$clean" | grep -vE '^\s*$' | tail -3)")
    dead=$((dead+1))
  else
    printf '%-38s %s\n' "$t" "RED (exit $code)"
    printf '%s\n' "$clean" | grep -E 'FAIL' | sed 's/^/      /'
    red=$((red+1))
  fi
done

echo
if [ "$dead" -gt 0 ]; then
  echo "=================================================================="
  echo " $dead TEST(S) COULD NOT RUN. THE SUITE PROVES NOTHING RIGHT NOW."
  echo " This is worse than a failure: a red test is working, a dead one is"
  echo " silent. Fix these before reading any GREEN above as meaningful."
  echo "=================================================================="
  for d in "${DEAD_DETAIL[@]}"; do echo "  - $d"; done
  exit 2
fi

if [ "$red" -gt 0 ]; then
  echo "$red RED, $green GREEN — a real regression. Read the FAIL lines above."
  exit 1
fi

echo "ALL $green GREEN."
echo
echo "What this suite does NOT cover, stated so a green run is not over-read:"
echo "  - no digest has been rendered end to end through the recipient filter"
echo "  - the sender's 'nobody has anything -> no campaign at all' early return"
echo "    is unexercised; proving it needs a real send, which is Ian's to run"
echo "  - these run against DEV2 data; the LIVE population figures are in"
echo "    docs/atlas/RECAP-SUPPRESSION-PROPOSAL.md §1"
