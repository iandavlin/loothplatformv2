#!/bin/bash
# run-all.sh — EVERY quality gate, one entry point (docs/CRAFT-STANDARD.md).
# Run before pushing user-facing changes; the cut's Phase D acceptance gate.
# Add new gates HERE — a defect class found twice MUST become a gate.
set -uo pipefail

# THREE STATES, not two. A gate that CANNOT RUN is louder than one that fails:
# reporting "red" for a gate that never executed is indistinguishable from real
# findings, and gate 2 spent weeks looking red while it was in fact dead. By
# convention here: exit 0 = green, exit 1 = RED (real findings), exit 2 = CANNOT
# RUN (no verdict — missing engine, unmintable cookies, an unresponsive CDP).
red=0
dead=0
run() {  # run <label> <command...>
  "${@:2}"
  case $? in
    0) ;;
    2) dead=$((dead+1)); echo "  ^^ $1 produced NO VERDICT (exit 2)";;
    *) red=1;;
  esac
}
echo "=== GATE 1/10: visibility matrix (the privacy model) ==="
run "visibility matrix" php /srv/profile-app/bin/visibility-matrix.php
echo
echo "=== GATE 2/10: web-craft gate (images / weight / eager scripts) ==="
run "web-craft" python3 "$(dirname "$0")/craft-gate.py"
echo
echo "=== GATE 3/10: infra-sec gate (cookie auth / source disclosure / cdp) ==="
run "infra-sec" bash "$(dirname "$0")/infra-sec-gate.sh"
echo
echo "=== GATE 4/10: hub paragraph-collapse (content_html keeps its breaks) ==="
run "hub-paragraph" bash "$(dirname "$0")/hub-content-paragraph-gate.sh"
echo
echo "=== GATE 5/10: looth-auth-issue (non-REST mint bounce; recurs every DB reload) ==="
run "looth-auth" bash "$(dirname "$0")/looth-auth-issue-gate.sh"
echo
echo "=== GATE 6/10: event-date TZ (a UTC 'today' must not judge a site-local date) ==="
run "event-date-tz" bash "$(dirname "$0")/event-date-tz-gate.sh"
echo
echo "=== GATE 7/10: events tap NAVIGATES (Ian retired the mobile modal 2026-07-29) ==="
run "events-tap-navigates" bash "$(dirname "$0")/events-tap-navigates-gate.sh"
echo
echo "=== GATE 8/10: composer topic-meta (forum picker cloning + tags) ==="
run "composer-topic-meta" node "$(dirname "$0")/composer-topic-meta-test.js"
echo
echo "=== GATE 9/10: author socials RESOLVE, never mirror (byline drift class) ==="
run "author-socials-live" bash "$(dirname "$0")/author-socials-live-gate.sh"

echo "=== GATE 10/10: react button RENDERED => endpoint ACCEPTS it (Ian's shorty 400) ==="
run "react-types" bash "$(dirname "$0")/react-types-cover-standalone-gate.sh"
echo
# FOUR CDP/loopback gates are HELD OUT of the runner — they pass standalone but
# flake RED in-sequence (CDP under load / loopback /whoami trips infra's
# limit_req zone). Run them manually:
#   bash /srv/bb-mirror/bin/forum-visibility-gate.sh          # bb-mirror forum-visibility (C2/H6)
#   bash "$(dirname "$0")/editor-rail-reachable-gate.sh"      # profile editor rail reachable @768 (CDP)
#   python3 "$(dirname "$0")/follow-longpress-gate.py"        # a REAL timed press reaches its click (see below)
#   python3 "$(dirname "$0")/follow-visible-gate.py"          # the follow controls are PAINTED, and Save survives elsewhere
#
# follow-visible-gate needs the REAL-ORIGIN PROXY, not the loopback harness — the
# defects it guards (a row overflowing past overflow:hidden) depend on the vhost's
# sub_filter, which the loopback harness does not reproduce. Recipe:
#
#   python3 tools/exercise-harness/real-origin-proxy.py --port 8896 \
#     --cookies /tmp/tf-gate/cookies.txt &
#   python3 tools/gates/follow-visible-gate.py --url http://127.0.0.1:8896
#
#   ⚠️ PICK THE PORT DELIBERATELY. 8899 is what every recipe in this repo reaches
#   for first and it is routinely already held by another lane's proxy — binding it
#   would silently measure THEIR branch through THEIR route swap and report it as
#   yours. `ss -ltnp | grep 889` before you choose.
#
# follow-longpress-gate needs the exercise harness up first — it is held out
# because it depends on that harness, NOT because it is flaky. Recipe:
#
#   BR=/home/ubuntu/worktrees/<lane>   # or the serving checkout, to reproduce a regression
#   sudo -u looth-dev wp --path=/var/www/dev eval '
#     $u=1912;$e=time()+604800;
#     echo LOGGED_IN_COOKIE."=".wp_generate_auth_cookie($u,$e,"logged_in")."\n";
#     echo SECURE_AUTH_COOKIE."=".wp_generate_auth_cookie($u,$e,"secure_auth")."\n";' \
#     | grep wordpress > /tmp/tf-gate/cookies.txt
#   setsid sudo -u bb-mirror env PHP_CLI_SERVER_WORKERS=6 LG_BB_MIRROR_ENV=dev2 \
#     LG_APPROOT=$BR/bb-mirror/web LG_WEBROOT=$BR/webroot LG_SHARED=$BR/lg-shared \
#     php -S 127.0.0.1:8791 -t $BR/bb-mirror/web /tmp/tf-gate/router.php &
#   setsid sudo -u looth-dev env PHP_CLI_SERVER_WORKERS=4 LG_BB_MIRROR_ENV=dev2 \
#     php -S 127.0.0.1:8792 -t $BR/bb-mirror/api/v0 &
#
# PHP_CLI_SERVER_WORKERS is not optional: a single-threaded `php -S` serialises the
# ~19 overlay scripts and the page loses its hydration race, which the gate then
# (correctly) reports as exit 2 — no verdict, and no proof of anything.
#
# ⚠️ hub-router.php HARDCODES its $ROOT to a lane's worktree (:17). `php -S -t <dir>`
# does NOT repoint it — serve a different tree and you will still be served the
# worktree, byte for byte. Reproducing a regression against the SERVING CHECKOUT, or
# against a deliberately-broken copy for a red-first check, means editing $ROOT in
# your copy of the router. Measured 2026-07-30: a "broken" build came back identical
# to green and would have been reported as "the patch did nothing".
# Copy the WHOLE bb-mirror parent too, not just web/ — index.php:25 requires
# __DIR__/../config.php, and a 500 renders zero cards, which reads as red for
# entirely the wrong reason.
if [ "$red" -ne 0 ]; then echo "############ GATES RED — do not push ############"; exit 1; fi
if [ "$dead" -ne 0 ]; then
  echo "############ GATES INCOMPLETE — $dead gate(s) COULD NOT RUN ############"
  echo "Nothing red, but $dead gate(s) reached no verdict, so this is NOT green."
  echo "Fix the environment and re-run before treating this as a pass."
  exit 2
fi
echo "############ ALL GATES GREEN ############"
