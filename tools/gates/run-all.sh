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
echo "=== GATE 1/16: visibility matrix (the privacy model) ==="
run "visibility matrix" php /srv/profile-app/bin/visibility-matrix.php
echo
echo "=== GATE 2/16: web-craft gate (images / weight / eager scripts) ==="
run "web-craft" python3 "$(dirname "$0")/craft-gate.py"
echo
echo "=== GATE 3/16: infra-sec gate (cookie auth / source disclosure / cdp) ==="
run "infra-sec" bash "$(dirname "$0")/infra-sec-gate.sh"
echo
echo "=== GATE 4/16: hub paragraph-collapse (content_html keeps its breaks) ==="
run "hub-paragraph" bash "$(dirname "$0")/hub-content-paragraph-gate.sh"
echo
echo "=== GATE 5/16: looth-auth-issue (non-REST mint bounce; recurs every DB reload) ==="
run "looth-auth" bash "$(dirname "$0")/looth-auth-issue-gate.sh"
echo
echo "=== GATE 6/16: event-date TZ (a UTC 'today' must not judge a site-local date) ==="
run "event-date-tz" bash "$(dirname "$0")/event-date-tz-gate.sh"
echo
echo "=== GATE 7/16: events tap NAVIGATES (Ian retired the mobile modal 2026-07-29) ==="
run "events-tap-navigates" bash "$(dirname "$0")/events-tap-navigates-gate.sh"
echo
echo "=== GATE 8/16: composer topic-meta (forum picker cloning + tags) ==="
run "composer-topic-meta" node "$(dirname "$0")/composer-topic-meta-test.js"
echo
echo "=== GATE 9/16: author socials RESOLVE, never mirror (byline drift class) ==="
run "author-socials-live" bash "$(dirname "$0")/author-socials-live-gate.sh"

echo "=== GATE 10/16: react button RENDERED => endpoint ACCEPTS it (Ian's shorty 400) ==="
run "react-types" bash "$(dirname "$0")/react-types-cover-standalone-gate.sh"
echo
echo "=== GATE 11/16: /shop-layout-planner/ still SERVES the planner (live SEO url) ==="
# Defaults to dev2, where it self-reports CANNOT RUN until the standalone render
# lands (dev2 bounces every anon WP page into the BuddyBoss gate). Run it with
# --live to prove the production url is healthy, and with
# LG_SP_EXPECT_STANDALONE=1 once the standalone page is meant to be serving.
run "shop-planner-url" bash "$(dirname "$0")/shop-planner-url-gate.sh"
echo
echo "=== GATE 12/16: an ANON visitor can reach Sign in at every width (Ian's lockout) ==="
# Behaviour, not presence: "Sign in" was in the served HTML the whole time while
# 641-820px had no way in at all. Starts its own anonymous real-origin proxy and
# one incognito BrowserContext per width, so it never touches shared browser
# state. Bracketed widths (821/820, 641/640) are the point — 1440 and 390 were
# BOTH green on the day the band was dead.
run "anon-signin-reachable" python3 "$(dirname "$0")/anon-signin-reachable-gate.py"
echo
echo "=== GATE 13/16: follow-digest — flag OFF sends NOTHING (email is unrecallable) ==="
# Written BEFORE the sender and red on purpose; promoted here in the same window as
# the merge that defines LG_FOLLOW_DIGEST_ENABLED, per its own rule: "a gate that
# guards an unrecallable channel and is never promoted is worse than no gate — it
# reads as covered." Number minted from MAIN's count (12), not the branch's.
run "follow-digest" python3 "$(dirname "$0")/follow-digest-gate.py"
echo
echo "=== GATE 14/16: lane tooling in a deployed tree is ANON-UNREACHABLE ==="
# Second time source/behaviour was served to anybody who asked (after the
# /archive-api/v0/*.php disclosure). lg-weekly-digest/dev/ sat inside a PLUGIN
# directory, so the catch-all \.php$ handler RAN it for anonymous requests: one
# file booted WordPress and rendered a page, another opened Postgres and queried
# `notifications`, stopped only by a table GRANT.
# Starts its OWN gate-free origin from the repo vhost, because dev2's armed gate
# 403s every anonymous request and 403 is a PASS here — pointed at :443 this gate
# would go green having seen nothing.
run "dev-files-anon" python3 "$(dirname "$0")/dev-files-anon-unreachable-gate.py"
echo
echo "=== GATE 15/16: the cadence control is ABSENT when its flag is off ==="
# Number minted from MAIN's count (14), not the branch's — two lanes both minted
# "9/9" once and collided in this file.
#
# THE ABSENT HALF IS THE POINT. CLAUDE.md: "Gates assert what should be PRESENT;
# they cannot see what should be ABSENT" — and all six defects that reached Ian's
# phone through a green suite lived in that blind spot. The email-frequency control
# is the current occupant: it is SUPPOSED to be invisible until follow-digest's
# batcher genuinely delivers Daily and Weekly, and nobody writes an assertion for a
# thing that is meant not to be there.
#
# It needs no browser, which is why it can live in the numbered list while the four
# CDP gates below cannot: it diffs two surfaces that really exist on this box —
# /manage-subscription/ (flag off) against the lane preview (flag on) — over real
# HTTP with a minted session.
#
# ⚠️ IT NEEDS THE LANE PREVIEW UP, and says so rather than passing without it:
#   sudo tools/preview/lane-preview.sh up account-following
# Pointed at a missing preview it reports CANNOT RUN, never green.
#
# --prove is the red-first and it earned its keep on the first run: it caught TWO
# of this gate's own assertions being worthless. `"hidden" in html` passed against
# the surface that has no control at all (some other element on an 89KB page has a
# hidden attribute), and the "never writes the usermeta key" check went red against
# correct code because the file's own COMMENT names the key it deliberately avoids.
# Both are fixed; both would have shipped as green noise without the inversion pass.
# The HTTP phases run here. The BROWSER phase is opt-in so this gate never depends
# on chrome-dev being up, but it is the strongest assertion of the three and worth
# running by hand — flag ON in the HTML, control REMOVED from the member's DOM,
# proven non-vacuous by a stub that makes it reappear:
#   python3 tools/gates/cadence-control-gate.py --cdp http://127.0.0.1:9222 --prove
# (chrome-dev.service DOES now carry --host-resolver-rules, so it reaches nginx
#  directly instead of auditing a Cloudflare challenge page.)
run "cadence-control" python3 "$(dirname "$0")/cadence-control-gate.py" --prove
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
#
# following-section-gate is held out, and the reason CHANGED — the lane merged, so
# it is no longer "pointed at a branch". Two things keep it out of the numbered
# list, and only one of them is mine:
#
#   1. IT NEEDS AN ENGINE THIS BOX DOES NOT PROVIDE. chrome-dev.service carries no
#      --host-resolver-rules, so a browser gate here resolves dev2 over the public
#      edge and audits Cloudflare's challenge page. That is the SAME blocker that
#      makes craft gate 2 report CANNOT RUN. Fix the service and both become
#      runnable in the same move.
#   2. Runtime. The full sweep is ~24 real navigations and several minutes.
#      --quick exists for exactly this: same assertion CLASSES, smaller sweep —
#      destination still followed in a real browser and still must open the right
#      discussion, just one width over the visible rows; contrast still BOTH
#      themes; union, cross-surface and the flag assertions untouched.
#
# Standalone recipe (works today):
#   google-chrome-stable --headless=new --remote-debugging-port=9334 \
#     --user-data-dir=/tmp/chrome-preview/profile --disable-gpu --disable-dev-shm-usage \
#     "--host-resolver-rules=MAP dev2.loothgroup.com 127.0.0.1" about:blank &
#   python3 "$(dirname "$0")/following-section-gate.py" --cdp http://127.0.0.1:9334 --quick
#
# WHEN THE ENGINE IS FIXED, this becomes a numbered gate as:
#   run "following-section" python3 "$(dirname "$0")/following-section-gate.py" --quick
#
# messages-longpress-react-gate is held out because it needs a PROXY WITH MEMBER
# COOKIES and a member who is a PARTICIPANT IN A THREAD THAT HAS MESSAGES — state
# run-all.sh cannot manufacture. It is not flaky; it is red/green-proven both ways
# (2026-07-31: exit 1 against the serving checkout, exit 0 against the fix).
#
# It guards the THIRD long-press miss to reach Ian through a green suite, and the
# reason all three got through is the same: a CDP/Playwright .click() dispatches in
# single-digit ms and CANNOT cross a 380/480ms hold, so the gesture that breaks is
# the one no synthetic test performs. This gate presses with touchStart → a real
# wall-clock sleep → touchEnd.
#
# Recipe (⚠️ pick the port deliberately — see the 8899 warning above):
#
#   # a member who is IN a thread with messages; claude_admin (1912) is NOT, so its
#   # cookies make every assertion below CANNOT RUN rather than pass vacuously.
#   sudo -u looth-dev wp --path=/var/www/dev eval '
#     $u=get_user_by("email","<participant@example.com>"); $e=time()+604800;
#     echo LOGGED_IN_COOKIE."=".wp_generate_auth_cookie($u->ID,$e,"logged_in")."\n";
#     echo SECURE_AUTH_COOKIE."=".wp_generate_auth_cookie($u->ID,$e,"secure_auth")."\n";
#     echo "looth_id=".looth_auth_mint_jwt($u)."\n";' > /tmp/tf-gate/cookies-msg.txt
#   python3 tools/exercise-harness/endpoint-swap-proxy.py --port 8897 \
#     --cookies /tmp/tf-gate/cookies-msg.txt --gate "$LG_GATE_TOKEN" --rewrite-origin &
#   python3 tools/gates/messages-longpress-react-gate.py --url http://127.0.0.1:8897
#
# messages-header-footprint-gate is held out and additionally needs a DB FIXTURE, because
# dev2's largest organic message thread has FOUR people — not enough to show the defect it
# guards. Create it first, or the gate reports CANNOT RUN rather than quietly grading a
# 4-name header and reporting a smaller saving as if it were the answer:
#
#   sudo -u profile-app psql -d profile_app -f tools/gates/fixtures/messages-header-12name.sql
#   python3 tools/gates/messages-header-footprint-gate.py --url http://127.0.0.1:8897
#
# Red/green-proven 2026-07-31: exit 1 (1 passed, 8 failed, header 388px, no chip) against
# the serving checkout; exit 0 (9 passed) against the fix, header 100px.
#
# ⚠️ ITS REAL SUBJECT IS THE SAFETY LINE, NOT THE PIXELS. "Group · N people · everyone here
# sees your reply" used to be a CHILD of .lg-msg__peer-names, so the obvious way to clamp
# the header hides it — and then a group thread reads as a private 1:1 while replies reach
# people the header never named. The gate asserts the note is a SIBLING of the clamped
# element and is VISIBLE IN BOTH the collapsed and expanded states. Do not "simplify" it
# back into the names element.
#
# react-controls-reachable-gate is held out for the same reason (needs a cookie-carrying
# proxy, and leg B needs a member with a thread). Red/green-proven 2026-07-31: exit 1
# against the serving checkout, exit 0 against the fix. It guards TWO live defects that
# were both PRESENT, styled, and impossible to use:
#   A. the card react palette clipped by its own offset parent (.fcr overflow:hidden,
#      765dbc3) — reported by Ian as "a bad z" because a clipped popover and one painted
#      behind something are indistinguishable from outside;
#   B. the messages React control revealed by :hover ALONE, so on a touchscreen at
#      >=641px it could never be opened at all.
# In BOTH red runs a presence-style assertion PASSES ("the palette is open and has a real
# box"). Only elementFromPoint separates painted-and-reachable from merely present.
#
#   python3 tools/gates/react-controls-reachable-gate.py --url http://127.0.0.1:8897
#   # --leg a  (cards only, no thread needed)   --leg b  (messages only)
#
#   ⚠️ --rewrite-origin IS NOT OPTIONAL. profile-app's CSRF guard (_bootstrap.php)
#   rejects any Origin that is not a loothgroup.com host, so without it EVERY react
#   answers 403 csrf_origin_rejected and the gate reports the defect it exists to
#   detect — on a build that is fine. The looth_id line is not optional either: the
#   WP cookies alone authenticate the page but not /profile-api.

echo "=== GATE 16/16: BuddyBoss group mail stays DEAD (an empty list is load-bearing) ==="
# Number minted from MAIN's count (15), not the branch's — two lanes both minted
# "9/9" once and collided in this file.
#
# WHAT IT WATCHES IS AN ABSENCE NOBODY DECLARED. lg-discussion-group-gate.php was
# written for Local Looths, and its empty allow-list is the only reason a new
# discussion in a group forum mails NOBODY. Post-sweep (ruling 5) the kept groups
# still hold 3,735 armed subscriptions, topped by Tri State Looths at 853 — so a lane
# shipping Local Looths would add one slug for entirely correct reasons and mail 853
# people, with every other gate staying green because they assert what is PRESENT.
#
# It does not forbid that change; it makes it impossible to make BY ACCIDENT.
# ONE-MAILER-SCOPE.md §4 has the two coherent options and what each costs.
#
# All six assertions were proven breakable by mutation before this was committed,
# including one that first shipped green while its target was deleted (a file-wide
# `return [];` matched a different function). Pure static analysis — no box, no
# cookies, no CDP, so it cannot go DEAD for environmental reasons.
run "group-mail-dead" python3 "$(dirname "$0")/group-mail-dead-gate.py"

if [ "$red" -ne 0 ]; then echo "############ GATES RED — do not push ############"; exit 1; fi
if [ "$dead" -ne 0 ]; then
  echo "############ GATES INCOMPLETE — $dead gate(s) COULD NOT RUN ############"
  echo "Nothing red, but $dead gate(s) reached no verdict, so this is NOT green."
  echo "Fix the environment and re-run before treating this as a pass."
  exit 2
fi
echo "############ ALL GATES GREEN ############"
