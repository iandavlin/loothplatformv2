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
# ⚠️ NEVER export gate-token env GLOBALLY here. Tried 2026-08-15: the
# visibility matrix reads LG_GATE_HOST for its base URL, a global export
# re-pointed it at the public host, and gate 1 went red in two consecutive
# suite runs while green standalone — a self-inflicted interferer that got a
# lane falsely suspected. Per-gate env goes INLINE on that gate's run line.
LG_GATE_TOKEN_MINTED="$(bash "$(dirname "$0")/gate-env.sh" 2>/dev/null | grep '^LG_GATE_TOKEN=' | cut -d= -f2)"

# ONE SUITE AT A TIME, box-wide (8/15, third concurrency bite in a day: two
# concurrent suites both ran the visibility matrix and trampled its fixture
# member's dials mid-mutation — matrix-vs-matrix). Gates that mutate shared
# fixtures cannot overlap; a second suite WAITS (up to 25 min), it does not
# interleave. Lock death releases automatically with the process.
exec 8>/tmp/lg-suite.lock
if ! flock -n 8; then
  echo "run-all: another suite holds the lock — waiting (max 25 min)…"
  flock -w 1500 8 || { echo "run-all: lock never freed — CANNOT RUN"; exit 2; }
fi

red=0
dead=0
run() {  # run <label> <command...>
  "${@:2}"
  local rc=$?
  case $rc in
    0) ;;
    2) dead=$((dead+1)); echo "  ^^ $1 produced NO VERDICT (exit 2)";;
    # Name the red HERE (8/15, fourth silent-red archaeology of the day): a
    # gate that exits 1 without printing leaves the whole suite red with no
    # culprit, and the final banner cannot say who.
    *) red=1; RED_GATES="${RED_GATES:-}$1(exit $rc) "; echo "  ^^ $1 RED (exit $rc)";;
  esac
}
echo "=== GATE 1/35: visibility matrix (the privacy model) ==="
run "visibility matrix" php /srv/profile-app/bin/visibility-matrix.php
echo
echo "=== GATE 2/35: web-craft gate (images / weight / eager scripts) ==="
run "web-craft" python3 "$(dirname "$0")/craft-gate.py"
echo
echo "=== GATE 3/35: infra-sec gate (cookie auth / source disclosure / cdp) ==="
run "infra-sec" bash "$(dirname "$0")/infra-sec-gate.sh"
echo
echo "=== GATE 4/35: hub paragraph-collapse (content_html keeps its breaks) ==="
run "hub-paragraph" bash "$(dirname "$0")/hub-content-paragraph-gate.sh"
echo
echo "=== GATE 5/35: looth-auth-issue (non-REST mint bounce; recurs every DB reload) ==="
run "looth-auth" bash "$(dirname "$0")/looth-auth-issue-gate.sh"
echo
echo "=== GATE 6/35: event-date TZ (a UTC 'today' must not judge a site-local date) ==="
run "event-date-tz" bash "$(dirname "$0")/event-date-tz-gate.sh"
echo
echo "=== GATE 7/35: events tap NAVIGATES (Ian retired the mobile modal 2026-07-29) ==="
run "events-tap-navigates" bash "$(dirname "$0")/events-tap-navigates-gate.sh"
echo
echo "=== GATE 8/35: composer topic-meta (forum picker cloning + tags) ==="
run "composer-topic-meta" node "$(dirname "$0")/composer-topic-meta-test.js"
echo
echo "=== GATE 9/35: author socials RESOLVE, never mirror (byline drift class) ==="
run "author-socials-live" bash "$(dirname "$0")/author-socials-live-gate.sh"

echo "=== GATE 10/35: react button RENDERED => endpoint ACCEPTS it (Ian's shorty 400) ==="
run "react-types" bash "$(dirname "$0")/react-types-cover-standalone-gate.sh"
echo
echo "=== GATE 11/35: /shop-layout-planner/ still SERVES the planner (live SEO url) ==="
# Defaults to dev2, where it self-reports CANNOT RUN until the standalone render
# lands (dev2 bounces every anon WP page into the BuddyBoss gate). Run it with
# --live to prove the production url is healthy, and with
# LG_SP_EXPECT_STANDALONE=1 once the standalone page is meant to be serving.
run "shop-planner-url" bash "$(dirname "$0")/shop-planner-url-gate.sh"
echo
echo "=== GATE 12/35: an ANON visitor can reach Sign in at every width (Ian's lockout) ==="
# Behaviour, not presence: "Sign in" was in the served HTML the whole time while
# 641-820px had no way in at all. Starts its own anonymous real-origin proxy and
# one incognito BrowserContext per width, so it never touches shared browser
# state. Bracketed widths (821/820, 641/640) are the point — 1440 and 390 were
# BOTH green on the day the band was dead.
run "anon-signin-reachable" python3 "$(dirname "$0")/anon-signin-reachable-gate.py"
echo
echo "=== GATE 13/35: follow-digest — flag OFF sends NOTHING (email is unrecallable) ==="
# Written BEFORE the sender and red on purpose; promoted here in the same window as
# the merge that defines LG_FOLLOW_DIGEST_ENABLED, per its own rule: "a gate that
# guards an unrecallable channel and is never promoted is worse than no gate — it
# reads as covered." Number minted from MAIN's count (12), not the branch's.
run "follow-digest" python3 "$(dirname "$0")/follow-digest-gate.py"
echo
echo "=== GATE 14/35: lane tooling in a deployed tree is ANON-UNREACHABLE ==="
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
echo "=== GATE 15/35: the cadence control is ABSENT when its flag is off ==="
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

echo "=== GATE 16/35: BuddyBoss group mail stays DEAD (an empty list is load-bearing) ==="
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

# ⚠️ GATES 17 AND 18 REACH NO VERDICT FROM A LANE WORKTREE — ATTRIBUTED 2026-08-09
# (frontend-compose lane). Both are main's, neither is caused by the branch that
# happens to be running them, and both need per-gate attribution rather than being
# read as "my diff broke something".
#
#   FROM A WORKTREE, both die identically:
#       Fatal error: Cannot redeclare lg_pfs_target() (previously declared in
#       /home/ubuntu/loothplatformv2-clean/platform/mu-plugins/lg-preserve-forum-subscription.php:92)
#       in /home/ubuntu/worktrees/<lane>/platform/mu-plugins/lg-preserve-forum-subscription.php:115
#     The probe requires the mu-plugin by a path relative to ITS OWN repo root,
#     while WordPress has already loaded the same file from the SERVING checkout.
#     Two paths, one set of function names. So this is structural: gates 17 and 18
#     cannot run from ANY lane worktree, on any branch, and the surrounding gates
#     going green tells you nothing about these two.
#
#   FROM ~/loothplatformv2-clean, gate 17 gets past that and stops for a DIFFERENT,
#   pre-existing reason — its own negative control:
#       DEAD  negative control did not reproduce the reply data loss
#             (REPLY_AFTER=subscribed) ... so a green below would be unearned.
#     That is the gate behaving correctly. The repair it guards is now shipped and
#     ON (LG_PRESERVE_FORUM_SUBSCRIPTION = true, live @ 10ea816) and is loaded by
#     WordPress from the docroot, so the probe cannot un-load it to stage the
#     "repair absent" control it needs. The gate that proved the fix is the thing
#     the fix now prevents from proving itself. Needs a probe that can neutralise
#     the repair in-process — not a lane's problem to solve mid-charter, but it
#     should not be mistaken for a regression either.
#
echo "=== GATE 17/35: participation must never silently UNSUBSCRIBE you (P0 data loss) ==="
# Numbered 17 because 16 is this same branch's group-mail gate and MAIN is still on 15.
# If another lane lands a gate first, keep BOTH and renumber on merge — two lanes both
# minted "9/9" once and collided in this file.
#
# THE NEGATIVE CONTROL IS THE POINT. "Still subscribed after posting" is trivially true
# on a box where the reply never posted or forums are off, so this gate runs its probe
# THREE times in three processes: repair absent (must reproduce the data loss), flag ON
# (must preserve), flag OFF (must match absent exactly). If today's code does not fail,
# the probe is not exercising the defect and the gate reports DEAD rather than a green
# it has not earned.
#
# Both routes, because BuddyBoss resolves "whose subscription" differently on each:
# /reply and /topics/<id> act on the CURRENT USER, /reply/<id> on the REPLY'S AUTHOR.
# The first draft assumed the post author throughout and repaired nothing on two of
# three routes — this gate is what caught it.
run "subscription-preserved" python3 "$(dirname "$0")/subscription-preserved-gate.py"

echo "=== GATE 18/35: ruling 6 defaults — bell ticked, EMAIL UNTICKED (consent) ==="
# 18 because 16 and 17 are this same branch's; MAIN is still on 15. Keep BOTH and
# renumber on merge if another lane lands one first.
#
# THE DEFAULT IS THE ASSERTION MOST LIKELY TO ROT. "Email unticked" is not cosmetic:
# it is the difference between a member who chose email and one who was signed up by
# posting, and it inverts silently the moment a composer passes `subscribe` by reflex.
# So the gate pins both halves — the bell row MUST appear, the roundup row MUST NOT.
#
# Four modes, one process each (the flag is a constant): absent / off / on-default /
# on-email. OFF is asserted against ABSENT rather than against "nothing was written",
# because `subscribe` is BuddyBoss's own parameter and our flag neither gates it nor
# should. The claim being proven is "this feature changes nothing when off".
#
# Does NOT assert a bell notification is delivered — that is the bridge's contract.
# All six assertions were reddened by mutation before this was committed.
run "post-follow-controls" python3 "$(dirname "$0")/post-follow-controls-gate.py"
echo

echo "=== GATE 19/35: a rendered control CARRIES ITS BEHAVIOUR (the UI-lies class) ==="
# Numbered from MAIN, which is on 18. Two lanes both minted a "9/9" once and collided;
# rebase before pushing and on conflict KEEP BOTH and renumber.
#
# Backlog 4.4 + 4.3 (Ian 8/8): DMs dead from the mobile profile tray, and the 3-dots
# menu dead. ONE cause. profile-app renders the social widget and shipped its
# behaviour as an inline <script>; the tray RELOCATES that markup into another page,
# where an inline script can never run. Seven controls rendered, none wired.
#
# WHY A GATE AND NOT A TEST: every natural assertion here is about PRESENCE, and the
# defect lives entirely in ABSENCE. The buttons are in the DOM, styled, hit-testable —
# a presence check is GREEN on the broken state. So this asserts the PAIRING (markup
# present => its wiring is present and reachable), per flag state, off the rendered
# widget, and pairs every absence claim with a liveness claim: the widget renders ''
# for an anonymous viewer and for your own profile, and every "no stamp" assertion is
# vacuously true against ''.
#
# It also diffs the two live copies of the wiring (the OFF heredoc vs the ON asset)
# and goes RED on drift — that comparison is the only thing making a flagged
# migration with two copies safe.
#
# All 13 assertions were reddened by mutation first
# (tools/gates/social-actions-wired-redfirst.sh). That pass caught THREE real gate
# bugs: a substring check that matched the neighbouring getAttribute call instead of
# the selector under test, a call-site check that was satisfied by the function's own
# DEFINITION, and two mutations that silently did nothing. The harness now fails loud
# on a no-op or syntax-invalid mutation.
run "social-actions-wired" python3 "$(dirname "$0")/social-actions-wired-gate.py"
echo

echo "=== GATE 20/35: a sitemapped discussion lands on THE HUB, with its text in the HTML ==="
# 20 numbered from MAIN, which reached 19 while this lane was in flight
# (its own 19 is the UI-lies gate). RE-MINTED AT REBASE, not at first write —
# this branch and main both held a "19" for a few hours, which is precisely
# the collision the rule exists to stop. Keep BOTH on conflict and renumber — two lanes both
# minting the same number is how run-all.sh collided last time. Rebase before
# running; on conflict keep BOTH and renumber.
#
# TWO HALVES THAT FAIL INDEPENDENTLY, which is why both are gated. e9ddc28 put
# 1,352 discussions in the sitemap, all pointing at /hub/<forum>/<topic>/. The
# obvious fix — route that URL at the feed and let forums.js §4f open the modal —
# gives Ian the right picture and silently destroys the SEO half, because §4f's
# cold path fetches the body AFTER load: a crawler would read an empty modal and
# nothing would look wrong to anyone running JS.
#   A. CONTENT — OP body + reply text in `curl`, no JS, no cookies; title in <title>
#   B. LAYOUT  — the hub feed grid with a discussion modal already open on it
# Half A also guards the REPLACEMENT: the legacy page did server-render its
# content, and that must not regress on the way out.
#
# Plus the visibility masks, differentially against the fragment API (audit H6 was
# that leak on this exact permalink), and a hidden-forum topic 404ing through both
# the landing route and the fragment API.
#
# READS the flag state rather than hardcoding it, so an OFF default does not redden
# every other lane. LG_TL_REQUIRE_ON=1 promotes "legacy layout served" to a finding
# — throw it when the default flips. LG_TL_PREFIX gates a lane preview instead.
run "hub-topic-landing" python3 "$(dirname "$0")/hub-topic-landing-gate.py"

echo "=== GATE 21/35: marking notifications read is scoped to what was SEEN ==="
# 21 minted from MAIN, which reached 20 while this lane was in flight (hub-topic-landing
# took 20). THIRD number this gate has held: 16 at first write, 20 at the first
# rebase, 21 now. Two of those were live collisions — with main's group-mail gate
# and then its hub-topic-landing gate — and the SECOND one auto-merged CLEANLY,
# leaving two blocks both saying "20/20" with no conflict raised. Grep the roster,
# do not trust a quiet merge. On conflict KEEP BOTH gates and renumber; never drop one.
#
# ANOTHER ABSENT-HALF GATE, and the second time this exact class has cost a member
# their digest. "A read that was never a read":
#   2026-07-29  the recap's two registers disagreed on "still outstanding", so a
#               member who had merely LOOKED at a connection request dropped out of
#               one and not the other. Empty means no email, so they got nothing.
#   2026-08-07  bottom-nav.js posted {action:'read_all'} 700ms after the mobile
#               sheet rendered EIGHT rows — marking the member's WHOLE store read.
#               The recap is "what you missed", unread only (IAN-RULINGS §1), so one
#               glance at the bell cancelled the digest. Backlog 4.1.
#
# The assertion missing both times was never "the rows they saw are read" — that was
# always green. It was "the rows they did NOT see are STILL UNREAD".
#
# No browser and no nginx: it drives the real model against the real profile_app
# database inside ONE transaction that is NEVER committed, so it mutates no member
# data (verified: zero rows left behind). It also measures BOTH values of the
# read_seen_only flag, because a master flag otherwise neuters the tests that already
# covered the thing it gates.
#
# Red-firsted, all ten inversions, each going red for the reason it claims:
#   bash "$(dirname "$0")/lib/notif-read-seen-redfirst.sh"
# That pass caught this gate asserting on its OWN COMMENT PROSE ("limit=200" appears
# in both the fetch and the comment explaining it), and a placeholder doc heading that
# satisfied the very flag tripwire written to require one. Both would have shipped green.
#
# The end-to-end browser proof is NOT here — it needs a bespoke three-server harness
# (endpoint-swap-proxy + two php -S). It is recorded in docs/RECAP-READ-TIMER.md.
run "notif-read-seen" python3 "$(dirname "$0")/notif-read-seen-gate.py"
echo

echo "=== GATE 22/35: a rendered NAV control must actually NAVIGATE ==="
# Numbered from MAIN's 21. A number can collide through a CLEAN auto-merge, so the
# roster was grepped for duplicates before minting rather than trusting a quiet rebase.
#
# Backlog 3.8, Ian's ruling of 2026-08-09 (option D of four mockups). Gate 19 guards
# markup that arrives WITHOUT its behaviour; this guards the step after — a control
# that is present, is wired, and still takes you nowhere. Ian's complaint was a back
# button that existed and could not be reached; one that is reachable and inert is
# the same sentence with a new subject, and "it renders" is exactly the assertion
# that would miss it.
#
# Also asserts the two things that make THIS control honest rather than merely
# present: it must not be offered while you are already on the Hub (a control that
# lies about where it takes you), and its hidden state must carry pointer-events:none
# — it slides out at opacity 0, so without that it leaves an INVISIBLE tap target
# over the page, which is the mirror image of the bug.
#
# All 10 assertions were reddened by mutation first
# (tools/gates/back-pill-navigates-redfirst.sh). That pass caught this gate reading
# the code NEXT TO the thing under test: the path-guard check tested only that
# "location.pathname" appeared somewhere in the function, and stayed GREEN through
# the mutation that deleted the actual return. Same shape as the bug gate 19's
# inversion pass found. It now matches the guard itself.
run "back-pill-navigates" python3 "$(dirname "$0")/back-pill-navigates-gate.py"

echo "=== GATE 23/35: a service-worker navigation must always SETTLE (never spin) ==="
# 23 minted from MAIN, which reached 22 while this lane was in flight. Grep the roster
# for duplicates after every rebase — a gate collision can AUTO-MERGE CLEANLY, leaving
# two blocks printing the same number with no conflict raised (that happened to gate 21
# two days ago). On conflict KEEP BOTH and renumber; never drop one.
#
# THE CLASS, twice: AN UNBOUNDED WAIT PRESENTED TO THE USER AS "LOADING".
#   2026-06-25  one dropped navigation dead-ended the user on offline.html, no retry.
#   2026-08-09  Ian, twice in a day. A tab spinning on a request that PROVABLY never
#               reached nginx (no access-log entry) while every SW-bypassing path
#               answered in ms; the phone showed offline.html plus raw gate 403s.
# The 2026-06-25 retry could not help: it is .catch-guarded, and a hung fetch never
# rejects. Backlog 3.10, audit in docs/PWA-SW-AUDIT.md.
#
# The missing assertion is a LIVENESS one, which no presence-style check can express:
# not "the handler returns the right page" (it did) but "the handler ALWAYS returns,
# within a bounded time, for every input INCLUDING one that never answers".
#
# It drives the REAL webroot/sw.js inside a stubbed ServiceWorkerGlobalScope
# (lib/sw-handler-harness.js), so it tests the shipped file and not a paraphrase, and
# the decisive input is a fetch stubbed to `new Promise(() => {})` — which is what a
# hung request IS and what a browser makes awkward to stage. No browser, no nginx, no
# DB, so it cannot flake on CDP or limit_req.
#
# ⚠️ THE HARNESS ASSERTS ITS OWN FIDELITY FIRST. A vm context gets V8 intrinsics only:
# URL, Response, AbortController and setTimeout are absent unless injected. Without
# setTimeout the retry's promise executor THROWS, which rejects, which lands in the very
# catch that serves offline.html — so a case "passes" down a path no browser takes. And
# without URL the flag reads as absent and every flag-ON assertion is vacuous. Phase 0
# checks both before believing anything else.
#
# Red-firsted: 9 inversions + 2 controls, all accounted for.
#   bash "$(dirname "$0")/lib/sw-fetch-bounded-redfirst.sh"
run "sw-fetch-bounded" python3 "$(dirname "$0")/sw-fetch-bounded-gate.py"
echo
echo "=== GATE 24/35: with the origin UP, a nav must render the PAGE, not the offline shell ==="
# 24 minted from MAIN's 22 plus this lane's 23. Grep the roster for duplicates after every
# rebase — a gate collision can auto-merge CLEANLY (that happened to gate 21), and the
# Buck fence now lives in this same region, so check its position too: the fence is a
# WHOLE-DIFF check and must stay LAST, after every numbered gate.
#
# BITTEN THREE TIMES, which is why this is encoded before the next fix:
#   2026-06-25  a dropped navigation dead-ended the user on offline.html, no retry.
#   2026-08-09  blank spin on a request that never reached nginx; phone showed the shell.
#   2026-08-11  Ian clicked a discussion URL that answers 200 server-side and got a blank
#               spin then the "You're offline" shell.
#
# THE COMPANION SPLIT, and it is deliberate:
#   gate 23 (node)  "does the handler SETTLE when the network never answers?" — a hung
#                   fetch cannot be staged in a browser, so it needs a stub.
#   gate 24 (this)  "with the server plainly reachable, does the worker put the REAL PAGE
#                   on screen?" — a stub cannot answer that, because the stub IS what
#                   decides what the network returns. Real browser, real registered
#                   worker, real page, real origin.
#
# It asserts BOTH halves: the shell markers are ABSENT *and* the real content is PRESENT.
# "Shell absent" alone passes on a blank page, and a blank page was literally the other
# half of what Ian saw.
#
# ⚠️ It audits WHATEVER NGINX SERVES, i.e. the SERVING CHECKOUT (main), not a lane's
# branch — the right default for a regression tripwire, but a green here is NOT evidence
# about an unmerged fix. Swap /sw.js through endpoint-swap-proxy.py for that.
#
# RED-FIRST, and it earned it: `--prove` registers a deliberately broken worker (scoped to
# a dev-gated fixture dir, so it can never touch /hub/ or a member) that always serves the
# shell, and asserts this gate CATCHES it. Verified: both assertions went red.
#   python3 tools/gates/sw-no-offline-shell-gate.py --prove
#
# ONE BROWSER AT A TIME — the box is 2-core (Ian, cost, 2026-08-11).
run "sw-no-offline-shell" python3 "$(dirname "$0")/sw-no-offline-shell-gate.py"
echo
echo "=== GATE 25/35: one bad row must not wedge the mirror sweep (the silent-stall class) ==="
# Re-minted TWICE while this lane sat unmerged: 20 -> 21 -> 23. main gained 27
# commits and two more gates in between. On collision the rule is KEEP BOTH and
# renumber your own, never someone else's. Re-check the number immediately before
# pushing, and GREP THE ROSTER for duplicates afterwards — a gate number can
# collide through a perfectly CLEAN auto-merge, with no conflict to warn you.
#
# Backlog 3.9 (Ian 8/9): hub replies going invisible for hours. bb-mirror-reconcile
# — the ONLY safety net under a fire-and-forget realtime sync — had been dying on a
# foreign-key violation every 10 minutes since 2026-07-29 23:20 UTC. Eleven days.
# The bookmark write sits AFTER the walk, so each death also guaranteed the next
# one: same window, same poisoned row, same exit 255, for 3,084 runs.
#
# The measured cost: 11 of the 70 replies posted in that window (16%) never reached
# the hub, and every one was rescued only when its AUTHOR happened to edit the post
# — one of them 2d22h later. `systemctl status` said "failed" the whole time and
# nobody was watching that unit, which is why this merge also ships
# tools/mirror-sync/watch-mirror-sync.sh.
#
# Pure static + in-process behaviour: no DB, no browser, no network, so it cannot
# go DEAD for environmental reasons. All 7 assertions were reddened by mutation
# first (tools/gates/mirror-reconcile-poison-redfirst.sh) — a pass that caught TWO
# decorative assertions in this very gate: a str_contains() bookmark check that
# passed happily on 'last_reconcile_at_DISABLED', and a fixture whose exception
# escaped the gate itself so a real regression exited 255 with no verdict.
run "mirror-reconcile-poison" php "$(dirname "$0")/mirror-reconcile-poison-gate.php"
echo
echo "=== GATE 26/35: every notification type has a SENTENCE on BOTH bells ==="
# Numbered from MAIN, which is on 19. These two were minted as 16/17 while main was
# on 15 and renumbered on merge — on conflict KEEP BOTH and renumber, never drop one.
#
# forum.followed_topic shipped 2026-07-28 with a sentence on the DESKTOP bell and
# none on the mobile one, whose `default:` renders a bare actor name. Eleven days on
# live, and BOTH rows that type has ever produced went to real members — one of them
# Ian. The mobile file's comment claimed the two surfaces mirrored each other; a
# comment is not an assertion.
#
# The required set is read from Notifications.php (TYPES + HUB_TYPES), NOT by diffing
# the renderers against each other — that would go green the day a type is added to
# neither. It then EXECUTES both label functions, because a `case` label is only
# evidence of a sentence: `case 'forum.x': return esc(who);` would satisfy a label
# scan and be exactly the defect. This gate under-asserted for an hour when its own
# regex dropped connection_request/connection_accept, so it now cross-checks the two
# directions against each other and prints the required set on every run.
run "notif-renderer-parity" bash "$(dirname "$0")/notif-renderer-parity-gate.sh"
echo
echo "=== GATE 27/35: bell dismiss/delete contract + leg 4's follow stores ==="
# Runs two red-first proofs as the OS users that own their databases (peer auth —
# neither is reachable as `ubuntu`), and reports CANNOT RUN rather than RED when that
# sudo is unavailable, per the exit-code-3 rule above.
#
# Both proofs arm each flag state THEMSELVES, so flipping either flag must not turn
# this red. Between them they hold: that today's deleteAll really destroys; that
# dismissal keeps the row, hides it, and still lets the NEXT reply through (the
# permanent-deafness trap); that the ON CONFLICT arbiter matches the index that
# actually exists in all four pairings INCLUDING the failing one; that leg 4 honours
# status=0 so the 8/8 group-sub sweep cannot be undone through the bell; and that the
# tracked configs actually LOAD — "the file did not load" and "the flag is off" are
# opposite states that empty() reads identically.
run "notif-dismiss" bash "$(dirname "$0")/notif-dismiss-gate.sh"
echo
echo "=== GATE 28/35: a FOLLOW actually produces a BELL ROW (end to end) ==="
# IAN-RULINGS §6, verbatim: "do not let its gate claim bell notifications ARRIVE —
# that is the bridge's contract, not the composer's." Gate 18 proves the composer
# WRITES forums.topic_follow; nothing proved the other half, and Ian made the bell the
# DEFAULT follow channel, so a follow row is worth nothing until it becomes a
# notification a member can see. Three green gates either side of a seam nobody
# crossed.
#
# Crosses two Postgres databases, two OS users and an HTTP hop, so it can never be a
# unit test. It WRITES REAL ROWS on dev2 and cleans up under a trap, with the cleanup
# itself asserted; it refuses to run anywhere but dev2, keyed on LG_PUBLIC_HOST
# because LG_ENV says "dev2" on live too.
#
# It earned its place on its first run: RED on phase 2 while phase 1 sat green, which
# is how it found that lg_notify_push() fell back to `Host: localhost` for every
# caller outside reply.php — delivering notifications to an unmatched vhost, silently.
run "notif-bell-delivery" bash "$(dirname "$0")/notif-bell-delivery-gate.sh"
echo
echo "=== GATE 29/35: the bell's DELETE endpoint honours the flag, per state ==="
# The dismiss proof covers the MODEL. When Ian flips dismiss_instead_of_delete on
# live, what runs is the ENDPOINT — and nothing exercised that branch, so a mis-wire
# there means he flips the flag and gets the old destructive behaviour, or a 500, on
# a live bell. That is the worst outcome available for the one deliverable waiting on
# him, so it is asserted rather than reasoned about.
#
# Issues REAL authenticated requests (minted looth_id, the endpoint's own _bootstrap,
# the CSRF guard) against the WORKING TREE's copy — curl would test the serving
# checkout, i.e. main, whatever branch you are on. Phase 0 is the liveness control: a
# GET must return the payload AND a bogus token must be refused, so the auth is
# earned rather than assumed.
#
# It flips a TRACKED file to reach the ON state, by snapshot-and-restore with the
# md5 asserted afterwards — never `git checkout --`, which would wipe uncommitted
# work in the tree under test.
run "notif-endpoint" bash "$(dirname "$0")/notif-endpoint-gate.sh"
echo
# THE FENCE: our work must not touch Buck's files (Ian, 2026-08-11).
run "buck-surface-fence" bash "$(dirname "$0")/buck-surface-guard.sh"

echo
echo "=== GATE 30/35: a legacy URL reaches its OWN permalink, not a generic landing ==="
# 24 RE-MINTED AT REBASE, not at first write. Main went 22 → 23 while this lane
# was in flight (the Buck fence), so the 23 I pushed would have collided. FOURTH
# time this number has moved under this lane in two days — the rule is re-check
# immediately before pushing, and keep BOTH on conflict, which is what happened
# here: the fence and this gate both survive.
#
# Ian: "are all our old links going to work in legacy posts etc?"
#
# WHY THE OBVIOUS CHECK IS GREEN ON THE BROKEN STATE: every legacy shape already
# 301s SOMEWHERE, so "does it redirect?" proves nothing. Google reads a
# many-to-one 301 as a SOFT 404 — no authority transferred, no consolidation, and
# the old URL keeps its own index entry. So this asserts the DESTINATION per URL,
# resolved from the DB rather than hardcoded.
#
# It also asserts the two things a redirect map gets wrong in opposite
# directions: a reply in a NON-PUBLIC forum must not resolve (a redirect target
# is an existence oracle), and an unknown id must still land somewhere rather
# than 404 a crawler.
#
# topic-tag and page/<n> deliberately keep landing on bare /hub/ and are NOT
# asserted — see the gate header and docs/LEGACY-URL-REDIRECT-STATE.md. That is a
# current decision, not an oversight.
run "legacy-url-redirect" python3 "$(dirname "$0")/legacy-url-redirect-gate.py"

echo
echo "=== GATE 31/35: /hub/<category>/ looks like THE HUB and says which URL it is ==="
# 25 minted from MAIN at push time. Ian, 2026-08-11: the category pages were never
# rebuilt — legacy forum-style view, no canonical, no robots meta, and Google
# lists them.
#
# ONE PREMISE CORRECTED BEFORE WRITING IT: the page is NOT a wholesale legacy
# view — it already renders the new feed cards. What it also renders, and neither
# /hub/ nor a topic landing does, is the legacy LEFT CATEGORY TREE. Measured:
# nav-tree 84 on /hub/general/, 0 on /hub/, 0 on a landing. Same condition as the
# rail: _chrome.php shows the legacy nav only when __bb_hub_rail is empty, and
# _feed.php sets that rail ONLY on the unscoped branch.
#
# PRESENCE *AND* ABSENCE: "no nav-tree" alone is satisfied by a blank page, a 500
# or a redirect. Every rail check pairs it with "the hub rail is really there".
#
# TWO HALVES, GATED DIFFERENTLY ON PURPOSE. The canonical is not member-visible,
# so it ships unflagged and is asserted unconditionally. The rail IS
# member-visible, so it ships behind LG_HUB_CATEGORY_RAIL (OFF default) and the
# gate READS which rail is served — OFF is a state, not a finding, so an OFF
# default does not redden every other lane. LG_HC_REQUIRE_RAIL=1 demands it.
run "hub-category-page" python3 "$(dirname "$0")/hub-category-page-gate.py"
echo
echo "=== GATE 32/35: retired pages 301 per-URL to a destination that ANSWERS ==="
# Ian's rulings 2026-08-11: /shop-organisation/ -> /hub/shop-organisation/ ,
# /featured-content/ -> /hub/ , /merch/ -> loothtool.com , /shop/ LEFT ALONE.
#
# Per-URL, never a blanket redirect: Google reads many-to-one as a soft 404.
#
# ⚠️ IT ASSERTS THE DESTINATION RESPONDS, not merely that we emit a Location.
# /merch/ points at a domain WE DO NOT CONTROL, and measured before shipping:
# https://loothtool.com/ = 200 but https://www.loothtool.com/ = 522 (dead). The
# www form is the one most people would type into a conf, and it would have 301'd
# every visitor and Googlebot into a Cloudflare timeout. An external target can
# also rot later with nobody touching our code — a conf comment cannot catch
# that; this can.
run "stale-page-redirect" python3 "$(dirname "$0")/stale-page-redirect-gate.py"
echo
echo "=== GATE 37: ONE daily Guitardle allowance per MEMBER, claimed at START ==="
# Backlog 22 (Ian 2026-08-14): "fixing the guitardle giving more chances on
# different devices". The Weekly Top 5 has claimable spots, so this is fairness,
# not a quirk.
#
# THE OBVIOUS GATE WOULD HAVE BEEN GREEN ON THE DEFECT. "A member cannot record
# two results in one day" was already true — UNIQUE (wp_user_id, play_date) has
# held since June, and 7 days of live traffic gave 93 successful POSTs and
# exactly 93 rows. The leak was ABANDONING: nothing was written until the game
# ended and the mid-game snapshot was per-DEVICE, so you could read the phrase
# on one device, close the tab leaving no trace, and solve it cold elsewhere in
# one move. One POST, one row, indistinguishable from honest play.
#
# So the assertion that bites is: a SECOND start-claim for the same member and
# day must claim NOTHING. Proven red against origin/main's endpoint before the
# fix landed (LG_GDLE_ENDPOINT re-runs that).
#
# It drives the WORKING TREE's endpoint with a real WP session cookie — curl
# would reach /srv, i.e. the serving checkout, and test main. Phase 0 proves the
# door answers as a known member before any "no row was written" is believed,
# and the flag-OFF promo render is compared BYTE-FOR-BYTE against origin/main.
run "guitardle-claim" python3 "$(dirname "$0")/guitardle-claim-gate.py"

# ── 34 ─────────────────────────────────────────────────────────────────────
# The Stripe soft launch. Gate 34 has TWO halves because the feature does:
#   * the GRANT — who Stripe's webhook may transition — lives in the WordPress
#     plugin and is asserted by the poller's own red-first harness (39).
#   * the PAGES — who can reach join / gift / refund / regional at all — live in
#     the standalone membership-pages app, which never boots WordPress, so
#     nothing the grant harness asserts says anything about what a URL serves.
# Both address the SAME list (lgms_stripe_lifecycle_allowlist), which is why
# they share a number. Neither needs the network or a browser.
#
# NOTE FOR WHOEVER READS THIS NEXT: the allowlist merged into main on 8/11 but
# nobody wired its gate in, so it went un-run in every nightly until 8/15. A
# merged gate that is not in this file does not exist.
echo "=== GATE 34a/35: Stripe test-group — the GRANT (empty list = nobody) ==="
run "stripe-testgroup-grant" php "$(dirname "$0")/../../lg-patreon-stripe-poller/deploy/remediation/test-soft-launch-allowlist.php"

echo "=== GATE 34b/35: Stripe test-group — the PAGES (flag off = today's site) ==="
run "stripe-testgroup-pages" php "$(dirname "$0")/stripe-testgroup-pages-gate.php"

# 34c — the price control (Ian 2026-08-15: "I'd like to be able to set the
# price. In the dash."). Setting a price is THREE writes and only the middle
# one looks optional: create it in Stripe, record it in our own prices table,
# repoint new joins. Skip the middle and an existing subscriber vanishes from
# the join page's already-subscribed check, which offers them a second
# subscription. Also asserts the charter's sandbox-only rule in code.
echo "=== GATE 34c/35: Stripe price — one action or none, sandbox only ==="
run "stripe-price-control" php "$(dirname "$0")/stripe-price-control-gate.php"

# 34d — the OTHER grant path. The soft-launch list guarded the webhook only; a
# redeemed gift reaches the same Arbiter write via the billing app -> the
# unconditionally-registered /sync-customer route -> Sync::customer, and the
# five-minute cron sweep gets there on its own regardless. lgms_stripe_frozen
# does not stop it (that guards the Stripe POLL, a different pass). So before
# this, a gift to somebody NOT on the list let them in anyway.
echo "=== GATE 34d/35: Stripe test-group fences the SWEEP (the gift path) ==="
run "stripe-testgroup-sweep" php "$(dirname "$0")/stripe-testgroup-sweep-gate.php"

# ── 35 ─────────────────────────────────────────────────────────────────────
# Front-end compose + edit (backlog 6; Ian ruled Option A 2026-08-03, all-members
# and front-end edit 2026-08-09). PER-STATE, and that is the point: it READS the
# feature's own lg_fc_enabled() off the box rather than assuming a state, so
# flipping the default needs no edit here.
#   flag OFF/absent -> asserts the route is BYTE-IDENTICAL to the recorded
#                      before-state, for an ALLOWED user (anon 404s either way,
#                      so an anon-only probe cannot tell the states apart).
#   flag ON         -> the form reaches the allowed member and NOBODY else; a
#                      refused POST writes no row; the OWNER gets the edit form
#                      and a STRANGER does not (IDOR); a stranger's edit POST is
#                      non-2xx AND does not move post_modified; and embed=1 serves
#                      the furniture-free variant that the hub composer's type
#                      toggle frames.
# --denied must be a user the tier genuinely refuses. Loothprint is open to ALL
# MEMBERS by Ian's ruling, so that means someone without edit_posts/upload_files
# (erin.vogel: customer,bbp_participant) — NOT an ordinary member, who is supposed
# to get this form. --owner/--stranger/--post drive the edit half.
echo "=== GATE 35/35: front-end compose+edit reaches the right people, and OFF is inert ==="
run "compose" python3 "$(dirname "$0")/compose-gate.py" \
    --type loothprint --allowed bangers --denied erin.vogel \
    --owner patreon_77159883 --stranger bangers --post 72155

# THE GATE-NUMBER LEDGER (single source of truth; keeper mints, lanes never):
#   34 stripe (34a webhook + 34b pages + 34c price + 34d sweep) · 35 compose/v2 · 36 dark-anon · 37 guitardle claim · 38 insert
#   path · 39 featured-members · 40 guitardle score-integrity — NEXT FREE: 45. (43 offline-shell, 44 directory-location; cd0a2ed stays unmerged - superseded by 88c0fac.)
# Gate 38 runs on the real stored-layout corpus via direct mysql; needs
# neither Redis nor a WP bootstrap.
echo "=== GATE 38: v2 insert path — OFF is identity, an insert only SURFACES declared meta ==="
run "license-insert" python3 "$(dirname "$0")/license-insert-gate.py"

echo "=== GATE 39: featured members — schema constraints, completeness parity, flag-off, no admin override ==="
# Backlog 18 (Ian 8/11), rulings 2026-08-14. Roster number allocated by
# keeper 2026-08-15 — first as 36, RENUMBERED to 39 the same night when
# keeper caught a collision with dark-anon-sweep also wiring 36 (not minted
# from this branch either time — exactly the two-lanes-both-mint-9/9 failure
# this convention exists to avoid, caught by keeper instead of repeated). No "/N"
# suite-total in the banner on purpose: that total is a cosmetic echo string
# other lanes are concurrently bumping for their own gates tonight (34, 35),
# and it feeds no counter in this script — see run(), which tracks red/dead
# by exit code only.
run "featured-member" env LG_GATE_HOST=dev2.loothgroup.com LG_GATE_COOKIE="$LG_GATE_TOKEN_MINTED" python3 "$(dirname "$0")/featured-member-gate.py"
echo
# Gate number 40 assigned by keeper 2026-08-15 (ledger: 38 v2 insert path,
# 39 taken, 40 this — next free is 41).
echo "=== GATE 40: a finished Guitardle result survives an EXPIRED NONCE ==="
# Backlog 24. Live, 7 days: 101 finished games POSTed, 8 came back 403 across 8
# IPs and 6 days. A WP nonce lives ~12h and the game sits in a front-page iframe
# people leave open, so a tab opened last night carries a dead one — and every
# call ended in `.catch(() => {})`, so the player saw their win card and it never
# reached the board. ~1 game in 12, hitting the members who play most.
#
# TWO HALVES, because neither proves anything alone: the SERVER half shows a
# stale nonce really is answered bad_csrf and records nothing, and that the same
# result resent with a fresh nonce records with its real score; the CLIENT half
# SLICES the shipped refreshNonce/postWithNonce out of game.js and evaluates that
# source against a stubbed network.
#
# Deliberately NOT a browser test — a browser dep would flake on a 2-core box and
# a DEAD gate blocks every lane. Slicing the real source rather than
# re-implementing it means the harness cannot drift from what ships; if those
# functions are renamed it reports CANNOT RUN instead of passing vacuously.
run "guitardle-nonce-retry" python3 "$(dirname "$0")/guitardle-nonce-retry-gate.py"
echo
# Gate number 41 assigned by keeper 2026-08-15 (42 pre-assigned to backlog 26;
# next free 43).
echo "=== GATE 41: the Guitardle board is scored on what the SERVER watched ==="
# Backlog 25, option A. Two facts drove the shape: moves/won/hardcore all came
# out of the POST body and hardcore DOUBLES points, so anyone with their own
# nonce could post a 20-point day; AND server-side scoring alone would not have
# fixed it, because the answer was public. Not just "the CSV is on a public URL"
# — measured in a browser, the legacy board put the phrase in the DOM: 18 tiles,
# all 18 carrying data-letter, so "POLYURETHANEFINISH" read straight off the
# BLANK tiles.
#
# THE ASSERTION MOST LIKELY TO SAVE SOMEONE IS NOT ABOUT THE EXPLOIT. Phase 2:
# the server now carries its own copy of loadPhrase(), and if that ever drifts
# from game.js the server judges a DIFFERENT PUZZLE than the player saw — every
# honest player loses, which is worse than the hole being closed. So the phrase
# id and letters are recomputed INDEPENDENTLY in Python from the raw assets and
# compared with the PHP resolver, on BOTH audience tracks.
run "guitardle-serverplay" python3 "$(dirname "$0")/guitardle-serverplay-gate.py"
echo
# Gate number 42 PRE-ASSIGNED by keeper 2026-08-15 (next free 43).
echo "=== GATE 42: the puzzle LIBRARY and SEQUENCE never reach a browser ==="
# Backlog 26. Gate 41 stopped the phrase reaching server-driven MEMBERS, but
# could not remove assets/guitardle_phrases.csv and assets/sequence.json,
# because the logged-out game still fetches them to draw its board and judge its
# own guess. Those files are 285 phrases plus the FIXED order — every future
# day, and the member track, computable by anyone who opens them. Quantified on
# live: ~140 points a week against a real weekly leader on 62.
#
# It does NOT claim a logged-out board stops holding its own day's phrase — it
# must, it judges its own guess, and an anon result is never recorded anyway.
# What goes is the LIBRARY and the ORDER.
#
# TWO ASSERTIONS EARN THIS GATE. There is deliberately no aud= on the day
# endpoint and it goes looking for one anyway (serving the member track there
# would restore gate 41's hole through a door needing no login). And it asserts
# the assets are STILL PRESENT: this is stage ONE of two, and pulling them
# before both flags are on everywhere is a blank board, not a degraded one.
run "guitardle-daypuzzle" python3 "$(dirname "$0")/guitardle-daypuzzle-gate.py"

# Gate number 36 assigned by keeper 2026-08-15 (ledger: 34 stripe, 35 compose/
# v2, 36 dark-anon, 37 guitardle, 38 v2 insert path). Bare number, no "/N" —
# matching gate 38's own precedent rather than the old shared-denominator
# convention, which turned every new gate into a 30+-hunk merge conflict
# across every prior "GATE n/N" banner (hit exactly that trying to land this
# one after the roster moved twice underneath it).
echo "=== GATE 36: anon dark-mode contrast — sign-in/join/sign-up path clears AA ==="
# Backlog 21, Ian 2026-08-14: "the dark mode needs some love for the login
# stuff" + "a ton of instructions and fields not ready for primetime in dark
# for logged out". Measured on /wp-login.php, /join, /lgjoin and the front
# page, in BOTH dark paths (app-dark: the visitor picked Dark; os-dark:
# prefers-color-scheme, since dark here is a resolved app theme rather than a
# media query) at desktop and mobile.
#
# RED-FIRST ON PURPOSE (charter METHOD: gate before fixing). Confirmed live,
# 2026-08-14: the login page's three-card skin (lg-snippets/snippets/86.php)
# had no dark styling at all — the card stayed white while the page around it
# force-darkened; the mobile "+" compose icon is 1.85:1 (--lg-sage-d repoints
# to a LIGHT colour under dark while the icon's stroke stays hardcoded
# white); and /join's intro paragraph inherited the nginx boot script's
# body{color:#e5e7e1!important} while its own card background never moved,
# landing near-invisible pale-on-white. The login page, /join and the shared
# pre-launch stub fixes have since landed (unflagged — none of the three is
# member-visible, a logged-in member never sees them in normal use); the "+"
# icon fix has ALSO landed, behind LG_DARK_POST_ICON_FIX (webroot/bottom-
# nav.js), OFF by default and held pending keeper's ruling on whether a pure
# dark-mode colour correction needs a flag at all.
run "anon-dark-contrast" python3 "$(dirname "$0")/anon-dark-contrast-gate.py"

echo "=== GATE 44: directory location — backlog 20, no list surface prints more than City/State ==="
# Ian 8/12, member safety. Number 44 ALLOCATED BY KEEPER 2026-08-15, never
# self-minted. Worth recording WHY it is not 41: the ledger line below still
# read "NEXT FREE: 41" while 41 and 42 were already registered above (they
# landed with guitardle score-integrity without the line being updated), and 43
# was already spoken for by the offline-shell gate on an unmerged branch. So the
# ledger under-counted by three, and minting from it would have collided with a
# gate already on main. Keeper is correcting the line to next-free 45 separately
# — deliberately NOT touched here, so the two edits cannot conflict.
#
# Scope note for whoever renumbers this: the leak was never just the 7 public
# rows. Visibility::precisionForAudience() hands 'street' to the OWNER and to
# every ADMIN unconditionally, so an admin's directory page rendered 15-16 full
# street addresses per page across ~1,900 members. The gate covers anon,
# member and admin for exactly that reason.
run "directory-location" python3 "$(dirname "$0")/directory-location-gate.py"

echo "=== GATE 48: author-search mask — backlog 27, the search uses the FEED's mask ==="
# Ian 8/15 via keeper, folded into 27. The Hub author search was effectively dead
# for LOGGED-OUT visitors: ?suggest=author&q=erlewine returned 0 on dev2 AND live
# for a man with 54 posts, because the discussion_visibility condition was applied
# to the whole union instead of the topic leg the FEED applies it to — so it hid
# content authors whose bylines the feed prints by name on the front page.
# Number 48 ALLOCATED BY KEEPER 2026-08-15, never self-minted; ledger next 49.
# Verified 45-49 were free on main before taking it rather than trusting the
# ledger line, which under-counted by three the last time it was read.
# All six mutations of the product code were run and each went red for its own
# reason — including the two that only fire because THE GATE ITSELF was fixed
# first (a call-site check instead of a bare-name grep, which the function's own
# definition satisfied; and a fixture with a real public/non-public tier mix,
# without which the count assertion could not fail).
run "author-search-mask" python3 "$(dirname "$0")/author-search-mask-gate.py"

echo "=== GATE 43: wp-admin never renders the PWA offline shell ==="
# Backlog 28. Ian, 2026-08-15: a slow admin click showed him the game-like
# "You're offline" page on a DASHBOARD url. He was not offline and it is not our
# surface — wp-admin should fail the way wp-admin fails, with the browser's own
# error, so the symptom names the real problem instead of blaming the network.
#
# Why the worker sees admin URLs at all: pwa.js registers with scope '/', so it
# controls EVERY same-origin navigation. wp-admin never loads pwa.js and does not
# need to — a registration made on a member page covers the whole origin.
#
# THE ASSERTION THAT EARNS IT runs every check on the LIVE hostname as well as
# dev2. sw.js already had BYPASS_PREFIXES and the obvious fix was to add
# '/wp-admin' to it — a fix that is INERT ON LIVE, because isBypassed() opens
# with `if (!RESILIENT || !IS_DEV2) return false`. A dev2-only gate would have
# blessed it. Paired with the opposite half: a MEMBER page under the same
# failure must STILL get the shell, or "intercept nothing" scores full marks by
# breaking offline support for everybody.
run "sw-admin-bypass" python3 "$(dirname "$0")/sw-admin-bypass-gate.py"
echo "=== GATE 45: contrast defects that fail in BOTH themes ==="
# Number 45 ASSIGNED BY KEEPER 2026-08-15, verified free on origin/main before
# use. Lanes do not mint gate numbers — two have collided doing it.
#
# WHY IT IS SEPARATE FROM 36. The dark-anon sweep kept surfacing findings that
# were not dark defects at all: a near-white foreground on a HARDCODED mid-tone
# fill with no dark variant, so it renders identically in either theme. Three
# instances found with the same instrument — bb_mirror_avatar()'s crc32 palette
# (3 of 8 colours fail), loothalong.js's crew avatars (#b98a3e at 2.85:1
# against the 3:1 icon bar), and the PWA install button's LIGHT half (3.12:1)
# whose dark half gate 36's wave already fixed. By the craft law a class found
# twice becomes a gate; this reached three. Fixing any of it behind a flag
# named "dark" would mislabel it permanently, which is the whole reason for a
# second gate rather than a wider first one.
#
# Measures each surface TWICE — resolved light and resolved dark, same probe as
# gate 36 and the sweep — and counts an element only when it fails in BOTH,
# matched by selector. Owns the six anon surfaces gate 36 does not, so the two
# gates never both own a page. Ratchet, same as 36: asserts no regression past
# BASELINE rather than zero, because the class is larger than one wave and a
# zero assertion would block every other lane's train for disclosed debt.
run "theme-independent-contrast" python3 "$(dirname "$0")/theme-independent-contrast-gate.py"

echo "=== GATE 49: every copy of a paired feature flag agrees ==="
# Number 49 ASSIGNED BY KEEPER 2026-08-15, verified free on origin/main first.
#
# A DEFECT I DESIGNED IN. The dark-anon flags are per-file module-local vars,
# not one shared window global — correct, and it stays: pwa.js documents that a
# dynamically-injected defer script has no guaranteed order against the
# sync-injected ones, so a shared window.LG_* would be read before it was
# written on some loads. Local copies have no such race. What they DO have is
# this: I defended the pattern with a comment calling it "one grep away from
# flipping every copy at once", and on 2026-08-15 the flip happened without the
# grep. LG_DARK_BORDER_FIX went true in app-settings.js and stayed false in four
# other files, so dark mode served TWO border colours at once, side by side on
# hub surfaces where both files style the same page. Worse than either whole
# state. A design whose safety depends on a comment being obeyed is not safe;
# this is that comment turned into a mechanical check.
#
# Asserts only that copies AGREE — never that a flag is on or off — so it stays
# correct as flags flip in either direction and nobody edits it to ship a
# feature. Static source read: no browser, no network, cannot flake. Red-fired
# against the REAL live half-state before the fix, not a synthetic one.
run "paired-flag-agreement" python3 "$(dirname "$0")/paired-flag-agreement-gate.py"

echo "=== GATE 53: .lgpo-subtext keeps a dark-theme ink (and OFF stays a no-op) ==="
# Number 53 ASSIGNED BY KEEPER 2026-08-15 (52 is frontend-compose's; ledger next 54).
#
# THE DEFECT THE CHARTER DID NOT NAME. The re-chartered dark-anon-sweep seat was
# sent to fix "invisible accordion labels at 1.21:1" on bpnoaccess. Measured in
# all four dark states, that defect does not exist — 86.php moves the summary ink
# AND the .lg-acc background together. What WAS on that surface was the mirror
# image, in a different plugin: .lgpo-subtext, hardcoded #666, on the dark card
# #1b1e21 = 2.92:1. The card follows the theme because 86.php owns it; that one
# piece of ink did not, because 86.php's block never reaches another plugin's
# markup. A fix in 86.php was structurally incapable of touching it.
#
# Static render, no browser, no network — cannot flake. Asserts BOTH flag states
# independently (OFF adds nothing, ON emits an AA-clearing rule) and only REPORTS
# the shipped default, so flipping the default in either direction needs no edit
# here. Red-first: tools/gates/dark-onboard-subtext-redfirst.sh breaks all five
# assertions and requires each to redden.
run "dark-onboard-subtext" python3 "$(dirname "$0")/dark-onboard-subtext-gate.py"

if [ "$red" -ne 0 ]; then echo "############ GATES RED — do not push ############"; echo "RED GATES: ${RED_GATES:-unknown}"; exit 1; fi

echo "=== GATE 50: the work board renders EVERY item, and phase 1 cannot write ==="
run "work-board" php "$(dirname "$0")/work-board-gate.php"

if [ "$red" -ne 0 ]; then echo "############ GATES RED — do not push ############"; exit 1; fi
if [ "$dead" -ne 0 ]; then
  echo "############ GATES INCOMPLETE — $dead gate(s) COULD NOT RUN ############"
  echo "Nothing red, but $dead gate(s) reached no verdict, so this is NOT green."
  echo "Fix the environment and re-run before treating this as a pass."
  exit 2
fi
echo "############ ALL GATES GREEN ############"
