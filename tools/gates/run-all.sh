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
# 35b — the PAYWALL TOGGLE on that same form (#179, Ian 2026-08-21: "a toggle for
# the user to decide if behind the paywall... Default to behind the paywall").
# Under 35 rather than a new number: it is a control ON this form, and two lanes
# have already collided by both minting the next free one.
#
# ⚠️ ITS FLAG/CONTROL LEG IS DEPLOY-COUPLED AND SAYS SO. lg-frontend-compose.php is
# a mu-plugin symlinked out of the serving checkout, so dev2 runs MAIN's copy: until
# a merge AND a pull, the served form cannot carry the control however correct the
# branch is. That leg prints NOT DEPLOYED — never a red (the box being behind is not
# a defect) and never a green (that would be a vacuous pass on the claim under test).
# The rule leg and the served-bytes leg both run regardless.
run "loothprint-paywall" python3 "$(dirname "$0")/loothprint-paywall-gate.py"
# 35c — the re-bake ordering the toggle depends on. Stubbed hook harness, no box
# state, because that mu-plugin is ALSO symlinked out of the serving checkout.
run "materializer-queue" php "$(dirname "$0")/materializer-queue-harness.php"

# THE GATE-NUMBER LEDGER (single source of truth; keeper mints, lanes never):
#   34 stripe (34a webhook + 34b pages + 34c price + 34d sweep) · 35 compose/v2 · 36 dark-anon · 37 guitardle claim · 38 insert
#   path · 39 featured-members · 40 guitardle score-integrity — NEXT FREE: 45. (43 offline-shell, 44 directory-location; cd0a2ed stays unmerged - superseded by 88c0fac.)
#   ⚠️ THAT 'NEXT FREE: 45' IS STALE — 45, 48, 49, 50, 51, 53 and 56 are all
#   registered above it. Minting from it duplicates; ask keeper, who mints.
#   62 flag-register (keeper, 2026-08-16). 46 compose-media + 47 compose-dark
#   were self-minted by a lane and keeper BLESSED them as-is on 8/16 —
#   they predate enforcement and renumbering working gates is churn.
# Gate 38 runs on the real stored-layout corpus via direct mysql; needs
# neither Redis nor a WP bootstrap.
echo "=== GATE 38: v2 insert path — OFF is identity, an insert only SURFACES declared meta ==="
run "license-insert" python3 "$(dirname "$0")/license-insert-gate.py"

echo "=== GATE 39: featured members — schema constraints, completeness parity, flag-off, no admin override, completion-never-blocks (#107) ==="
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

# Gate number 55 — requested from keeper 2026-08-15; 55 verified free on main
# (54 is the weekly-front seat's, on an unmerged branch). Lanes never mint.
echo "=== GATE 60: no player is served the same puzzle twice in a cycle ==="
# Backlog 35. Ian caught this on LIVE 2026-08-15: members got DAN ERLEWINE on
# day 12 (23 June) and again on day 65 (15 August).
#
# THE OBVIOUS DIAGNOSIS IS WRONG, AND THE WRONG FIX HERE IS DESTRUCTIVE.
# sequence.json is a clean no-repeat permutation of all 285 ids and the
# no-repeat-until-all-played mechanism works exactly as designed — reshuffling
# it would re-serve phrases players have already had. What defeated it was one
# PHRASE TEXT under TWO IDS: 180 and 233 were both "Dan Erlewine". The sequence
# never repeated an id; it served two different ids that read the same.
#
# So the gated property is not "the sequence has no repeated id" — that was
# true the whole time the bug was live. It is what a player experiences: walk
# the FULL CYCLE ON BOTH TRACKS and see no phrase twice. Both tracks matter:
# logged-out runs +142, so a duplicate can bite one track years before the
# other, and this pair had already hit members twice while both entries were
# still in the logged-out track's future. Gating one track calls it clean.
#
# It also asserts the library stays pure ASCII and free of any character PCRE
# \R splits on but JS split("\n") does not (bare CR, \v, \f, U+0085, U+2028/9).
# That is not housekeeping: _guitardle-puzzle.php splits the CSV on \R with no
# /u flag, so such a character cuts a row in half for the SERVER while the
# client parses it whole — the server would then judge a different puzzle than
# the player's board was drawn from, silently.
#
# Static file read: no browser, no network, no WordPress — cannot flake.
# Red-first: tools/gates/guitardle-phrase-uniqueness-redfirst.py breaks all
# thirteen behaviours on a snapshot copy and requires each to redden, with an
# unmutated control that must stay green and a loud failure on any no-op
# mutation.
run "guitardle-phrase-uniqueness" python3 "$(dirname "$0")/guitardle-phrase-uniqueness-gate.py"

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

# ⚠️ ALSO REGISTERED BEFORE THE EARLY EXIT, for the reason the block above
# states: it was appended at the end first, and the 2026-08-20 suite duly
# reported it as SKIPPED — a gate that does not run is not coverage, and main
# is red often enough that "below the exit" means "never".
echo "=== GATE 77: the lanes page cannot lie about a lane ==="
# #151/#155/#156/#159/#160/#164. The page's entire job is telling Ian the truth
# about what is running, and on 8/19 it told him a lane that had been building
# for 25 minutes was 'finished & freeable' AND 'APPROVED, NOT STARTED' at the
# same time. All three misreads were ONE line: `unique == 0` meant done, so a
# branch cut minutes ago read as finished, left the seats table, and its issue
# then had no seat to be found at. A page that is wrong is worse than no page,
# because he acts on it.
#
# ⚠️ ASSERTIONS MATCH MARKUP, NEVER PROSE. Grepping the rendered page for
# "APPROVED, NOT STARTED" during this build reported a hit, and the hit was the
# lane's own plan comment quoted inside a <details> block — a page that RENDERS
# a plan mentioning a defect is not a page EXHIBITING it
# (feedback-red-first-that-stays-green).
#
# EVERY ABSENCE IS PAIRED WITH A LIVENESS ASSERTION
# (feedback-absence-assertion-needs-liveness): "the running lane is not
# freeable" is trivially true if nothing is ever freeable, so a finished seat
# is in the fixture and must STILL be offered; "no issue reads NOT STARTED" is
# trivially true if that block never renders, so an issue with no seat anywhere
# is in the fixture and must STILL be flagged. The red-first run caught two
# assertions that were decoration for exactly this reason and they are fixed.
#
# Five legs: fixtures through the real renderer; the descriptor as a pure
# function; quiet-when-healthy AND loud-when-blind; the worker probe against
# the REAL tmux server in a per-run session; the seat classification against
# REAL git in a throwaway repo under a per-run path
# (feedback-gate-probe-must-be-per-run), which backdates a branch's reflog
# because a fixture that cannot be old cannot prove the young-branch guard is a
# guard and not a constant; and #156's endpoint with `msg` shimmed so the gate
# never posts to the real board.
#
# The tmux leg is the one that earns its keep: the working-detector has now
# drifted TWICE in a single day — 8/20 morning when the CLI dropped "esc to
# interrupt", and 8/20 afternoon when a raised thinking effort began appending
# " · thinking with xhigh effort" INSIDE the parens, so anchoring on "tokens\)"
# read every deep-thinking lane as idle.
#
# Red-first: 26 mutations, each against a SNAPSHOT copy (never `git checkout --`,
# feedback-mutation-harness-must-snapshot-not-checkout), each reddening its
# NAMED assertion, plus two no-op mutations that must stay green.
# No browser, no network, no DB, no writes to /var/www and no worktrees on the
# real box, so it cannot flake under load and cannot go vacuously green behind
# a locked-out browser.
run "lanes-page-truth" python3 "$(dirname "$0")/lanes-page-truth-gate.py"
echo

# ⚠️ REGISTERED BEFORE THE EARLY EXIT BELOW, DELIBERATELY. That `exit 1` is a
# TERMINAL block in the middle of this file, so anything registered after it
# NEVER RUNS on any run where an earlier gate is red — silently, with no line
# saying gates were skipped. This gate was appended after it and therefore did
# not run in the 2026-08-16 suite at all, while the summary named only the one
# red gate. A gate that does not run is not coverage.
# Gate number 59 — ASSIGNED BY KEEPER 2026-08-16. I had used 56 as a working
# placeholder; 56 was already taken (board committer, minted at the stripe
# merge). A placeholder is still self-minting — ask keeper first.
echo "=== GATE 59: a guessed letter is a HIT or a MISS, and a resumed board PAINTS ==="
# Ian, playing on dev2 2026-08-16: "it's keeping track of letters that are in
# the puzzle, but not the guesses that were misses. The letter only stays lit
# for a correct letter." Then: "refreshing on dt lights all letters, but the
# correct letter just selected from mobile isn't there."
#
# NOTHING WAS EVER LOST — the server row was complete throughout, and this gate
# asserts that too, because a lost move would be a FAIRNESS bug rather than a
# paint one. Every symptom was the client drawing a correct record wrongly:
#   - positions=[] means BOTH "vowel bought" and "miss", so every miss was
#     filed as a purchase — and .purchased is styled for VOWELS ONLY, so a
#     missed consonant rendered untouched AND stayed tappable;
#   - server play draws tiles with data-i, legacy with data-letter, and BOTH
#     restores used the data-letter painter — zero matches, zero paint, while
#     the same loop lit every letter including misses.
#
# KEYED TO STATE, NOT WIDTH. The obvious desktop-vs-mobile gate would be the
# wrong axis and would PASS: there is no width branching in this code and both
# widths measure identically. What differs is state — live play, after a
# refresh, and across two devices — so those are the phases.
#
# Two devices are two ISOLATED BROWSER CONTEXTS, not two tabs: tabs share
# cookies and localStorage, which hides the very bug under test.
#
# Asserts THIS TREE's client, substituted over CDP, because dev2 serves main.
# LG_GDLE_SERVED=1 measures the served code instead — that is the red-first
# direction, and how this gate was proven: 24 assertions red on main, measured
# 2026-08-16 rather than remembered. Three artifacts cited three DIFFERENT
# numbers for this one measurement (here 4, CRAFT-STANDARD 14, the commit body
# 19); 19 was correct for the original four-phase gate, the others never were.
# Re-measure before quoting it -- phases 5 and 6 changed the count.
run "guitardle-letter-state" python3 "$(dirname "$0")/guitardle-letter-state-gate.py"
echo "=== GATE 54: the weekly email on the logged-out front page ==="
# Number 54 ASSIGNED BY KEEPER 2026-08-15. The lane charter said "next 52",
# which was ALREADY STALE — profiles-alive holds 51, notif-quickreply-v2 holds
# 52, dark-anon-sweep holds 53 — so it was re-asked rather than minted. Checked
# free on origin/main again after merging main in, immediately before taking it.
#
# Backlog 8: Ian 2026-07-30, ruled 2026-08-15 after the mock ("build it and let
# me see it on dev2"). Option A — the latest SENT issue's own stored sections
# rendered as the front page's own cards, for logged-out visitors only.
#
# THE ASSERTION THAT EARNS THIS GATE is the one aimed at a SILENT failure: the
# `tier` taxonomy stores looth-lite/looth-pro, the CSS is .rcard--gated-lite. An
# unmapped slug yields .rcard--gated-looth-lite, which matches no rule — so a
# member-only card renders with NO PADLOCK and reads as free content. Nothing
# throws, and "a badge is present" passes on the broken output.
#
# Static: renders the partial in-process and calls the feed's predicates by
# reflection. No browser, no network, no DB — cannot flake, and it runs the same
# on a worktree as on main. Contrast is measured separately by
# tools/preview/weekly-front-shots.py, deliberately not folded in here.
#
# Red-first: tools/gates/weekly-front-redfirst.sh breaks ELEVEN behaviours and
# requires each to redden on its own assertion. Two of them were caught being
# decoration and fixed — [E] and [F] were grepping the source for words the
# file's own docblock satisfied, and [D] tested a card type that renders no
# prose at all, so it passed however the guard was mutated.
run "weekly-front" python3 "$(dirname "$0")/weekly-front-gate.py"

# Number 61 minted by keeper 2026-08-16. REGISTERED ONLY NOW, after train 2
# DEPLOYED the board palette to the serve — this gate reads the SERVE, so
# registering it before that deploy would have put a guaranteed-red gate in front
# of every lane on the box. Merged was never enough; deployed was the condition.
#
# Asserts /wip-board.php clears AA in dark on all four states (app-dark x os-dark,
# desktop x mobile), with collapsed <details> EXPANDED first — the board hides
# most of itself, and a first-render probe measured 3 of 9 thread boxes.
#
# The LIGHT pass encodes Ian's ruling rather than remembering it: non-text
# (border) contrast must be clean, while the light muted-ink TEXT cells he
# accepted on 2026-08-16 are counted and reported, never failed. A gate that
# reds forever on an accepted baseline teaches everyone to ignore it.

# ⚠️ REGISTERED ABOVE THE RED-EXIT DELIBERATELY. This is a static corpus read
# — no browser, no network, seconds — and it guards an email that GOES OUT to
# members. A gate below the marker does not run when anything earlier is red,
# which is exactly how gate 57 once executed nothing while looking covered.
# Cheap + member-facing belongs above; the expensive tail belongs below.
echo "=== GATE 72: the weekly digest's discussion cards keep their images ==="
# Ian, 2026-08-03: "images from discussions are now sometimes not making it into
# the weekly digest", narrowed 08-05 to "mostly the discussion section". A topic
# resolved a thumb from a featured image (bbPress topics never have one) and the
# first inline <img> in post_content — nothing else. The hub composer STRIPS
# inline previews and stores images as BuddyBoss media, so every composer-made
# discussion lost its image. Measured on live 2026-08-16: of 68 image-bearing
# discussions in 90 days, 55 lose their image today.
#
# DATA-SHAPED, SO IT RUNS ON THE REAL CORPUS, NOT A FIXTURE. A seeded row proves
# the code can read a row we wrote; 40 real failing-class topics prove it reads
# what the composer actually produces. It REPORTS the corpus it measured and goes
# CANNOT RUN (2) if the failing class disappears, so it cannot pass vacuously.
#
# It asserts BOTH flag states: 0 of 40 resolve with the flag OFF (the bug,
# reproduced) and 40 of 40 with it ON, while 40 passing-class cards stay
# BYTE-IDENTICAL between the two — the fix cannot reach a card that already
# worked. Proven able to fail: deleting the resolver reddens it (exit 1).
#
# ⚠️ dev2 holds 2825 bp_media ROWS AND ZERO MEDIA FILES, so this gate proves the
# URL is resolved and emitted — it can never prove the image LOADS. Only live can.
run "digest-forum-images" bash "$(dirname "$0")/../../lg-weekly-digest/dev/verify-discussion-media-thumbs.sh"

# ⚠️ PLACED ABOVE THE MID-FILE RED EXIT DELIBERATELY. The marker below warns that
# anything registered under it never runs on a red suite — and main was red on 7
# unrelated gates the day this landed, so down there it would have asserted
# nothing in practice. It costs well under a second (pure source + config, no DB,
# no browser, no network), so there is no reason for it to be skippable.
echo
echo "=== GATE 74: categorize-last — all THREE flag states, and five couplings ==="
# #129 ledger 44. The composer's required "Where" step is gone and an OPTIONAL tag
# step arrives at the end (Ian 8/16 ruled; Option C on 8/19: "add in the tags and
# maybe popping up a new modal with a decent heirarchical layout"). Member-facing
# change to the most-used write surface here, so it ships behind a flag OFF.
#
# The failure class this guards is NOT the ON path. It is that "flag OFF is a no-op"
# gets asserted by nobody and quietly drifts. So the gate READS the flag rather than
# hardcoding a state — flipping the default needs no edit here — and asserts:
#   absent  both readers fail CLOSED when the tracked config is unreadable
#   OFF     the original Where-step markup is present VERBATIM, and the taxonomy is
#           NOT extended to topics (an ungated attach is a schema change, not a no-op)
#   ON      the pre-made forum choice, the tag field, the Skip control, the endpoint
#
# Plus five couplings that each have a scar in this repo:
#   1 the two non-postable forum lists (bb-mirror's constant, the WP literal) AGREE
#   2 the landing forum is CONFIG not a constant — dev2 73564, live's twin differs
#   3 the applier's reply loop bumps post_modified_gmt AND dispatches: a change that
#     does not bump it never reaches the mirror, confirmed for exactly this operation
#   4 both flag readers consult getenv() AND $_SERVER, or a lane-preview
#     fastcgi_param serves OFF on the very URL built for Ian to click
#   5 the MOBILE composer is guarded. "mobile is the flat form served unchanged" is
#     true of forums.js and false of what a phone renders — hub-polish.js builds its
#     own 4-step wizard whose step 1 was "Title & forum"
#
# Pure source + config: no DB, no browser, no network, so it cannot flake under load
# and cannot go vacuously green behind a locked-out browser.
#
# RED-FIRST (tools/gates/categorize-last-redfirst.sh, 11 checks, mutating SNAPSHOT
# copies — never a checkout): 9 mutations each red for their OWN stated reason, and
# both the baseline and a no-op whitespace mutation stay GREEN.
run "categorize-last" python3 "$(dirname "$0")/categorize-last-gate.py"

if [ "$red" -ne 0 ]; then
  echo "############ GATES RED — do not push ############"
  echo "RED GATES: ${RED_GATES:-unknown}"
  # NO SILENT CAPS (keeper, 2026-08-16, from guitardle-fairness's finding): this
  # exit is MID-FILE, so on a red run every gate registered below it never
  # executes — and until today the summary never said so, which let gate 57 (and
  # 50/56 on any red suite) report nothing while looking covered. Enumerate the
  # skipped gates from the file itself so the list cannot drift as gates move.
  skipped="$(awk '/^# RED-EXIT-SKIP-MARKER/{f=1;next} f' "$0" | grep -oE '^run "[^"]+"' | cut -d'"' -f2 | tr '\n' ' ')"
  echo "SKIPPED (registered after this early exit, NO VERDICT): ${skipped:-none}"
  exit 1
fi
# RED-EXIT-SKIP-MARKER — the red-exit above prints every run-line below this as
# SKIPPED. Keep this marker immediately after that exit block; registering a
# gate below here means it does not run when an earlier gate is red.

# GATE 56 — the board committer's four fences (minted by keeper at the seat-2
# merge; the seat named the gap itself: unnumbered, the gate protected nothing
# on anyone else's branch). 48 assertions against a throwaway clone.

# Minted by keeper at the 4.1 merge. Runs against a THROWAWAY clone with a local
# bare origin, a FAKE lane-say that records its whole argv AND the bytes of the
# file it was handed, and a temp snapshot — it never touches the real clone, the
# real fleet or the real board.
#
# The properties, each one a lesson this box already paid for: the message is
# handed over as a FILE so backticks cannot be command-substituted on the way
# (it has eaten a redis-cli recovery command here); every attempt is receipted so
# a crash between lane-say and the receipt re-delivers at most ONCE and never
# loops; failures are receipted too and capped, so one undeliverable message
# cannot wedge the queue; and if receipts cannot be written it delivers NOTHING
# rather than repeating forever.
#
# ⚠️ The fake lane-say cannot prove what the REAL one does with those bytes. That
# last link was verified by hand on 2026-08-16 against a real tmux session —
# backticks and $() arrived verbatim. Re-verify by hand if lane-say's delivery
# mechanics ever change; a gate cannot spawn a real seat.

echo "=== GATE 51: new members arrive alive — the profile-setup step, flag OFF, and NO nudge ==="
# Backlog 19 (Ian 8/12 from the empty-directory screenshot; ruled 8/15, Option A
# with four sharpenings). One skippable screen at /profile-setup/ asking for the
# three fields a directory row actually shows.
#
# THE ASSERTION THAT MATTERS MOST IS AN ABSENCE. Ian: "No nudging on that
# matter." There is no banner, no dismissible card, no percentage chase — and an
# absence is exactly what creeps back in as a well-meaning improvement, so the
# gate scans five member-facing trees for one and pairs that scan with a LIVENESS
# self-test (the detector must first fire on a synthetic nudge, or a broken
# detector would pass on an empty repo and prove nothing).
#
# DUAL RAIL is asserted BEHAVIOURALLY, not by string presence: the Stripe rail's
# switch must be DERIVED from the shared config's enabled key. The first
# red-first run caught the loose version staying green against a hardcoded
# Patreon-only build — precisely the regression Ian's dual-wield ruling forbids.
#
# Flag OFF is the default and is proven a no-op per-state (config ABSENT,
# present-and-OFF, overridden ON) by RUNNING the reader, not reading it.
# Companion: profile-setup-redfirst.py mutates the source 12 ways and requires a
# RED from each — run it after touching either the feature or this gate.
run "profile-setup" python3 "$(dirname "$0")/profile-setup-gate.py"
echo
echo "=== GATE 52: tap a notification, get a reply modal — and OFF is UNREACHABLE ==="
# Number 52 ASSIGNED BY KEEPER 2026-08-15. Not read off this file: when this was
# minted, 50 was already here and 51 belonged to a lane that had NOT yet merged —
# so the highest number visible was not the next free one. Gate 51 has since
# landed directly above, which is the proof rather than the refutation: the
# number was safe only because it came from keeper. That is how the old "9/9"
# collision happened, and it is why 52 survived a rebase onto a main that had
# meanwhile taken 51 and 53.
#
# Backlog 5, Ian's layout A (2026-07-30): the quote is THEIR reply, never the
# member's own post, with a full-post link beside the composer.
#
# THE ABSENCES ARE THE POINT. Three defects reached Ian on 7/30 through gates that
# asserted only what should be PRESENT — a composer that kept the previous reply's
# text, a reaction row that grew a composer it should never have had, and a flag
# whose OFF state quietly did something. So this runs the SHIPPED state (flag off)
# and asserts the quote branch is unreachable rather than merely uncalled, and that
# the response is BYTE-IDENTICAL to origin/main with the parameter sent.
#
# AND THE ABSENCE IS PROVED NON-VACUOUS, which is the assertion that makes the rest
# worth anything: `--expect-off --force-flag-on` MUST exit 1. Verified on the
# replanted tree — it fails on exactly the two OFF assertions and nothing else.
#   off:                    25 pass / 0 fail
#   on:                     25 pass / 0 fail  (incl. a reply from another topic
#                           refused — r.topic_id scoping is what stops the
#                           visibility gate being walked around)
#   off --force-flag-on:    22 pass / 2 fail, exit 1  ← the counter-proof
run "notif-quickreply" python3 "$(dirname "$0")/notif-quickreply-gate.py" --expect-off

echo "=== GATE 62: a branch that ADDS a flag must REGISTER it in docs/FLAGS.md ==="
# docs/FLAGS.md has stated since 2026-08-09, in its own header, that "any merge
# that adds, flips, or retires a flag updates this file IN THE SAME COMMIT —
# keeper refuses the merge otherwise." NOTHING ENFORCED IT, and a rule with no
# gate decays: on 2026-08-16 a single sweep found SIX unregistered flags across
# FIVE branches, including two of my own. When six lanes break the same rule
# independently, the rule was unenforced rather than disobeyed.
#
# CRAFT-STANDARD's law is why this exists rather than a reminder: a defect class
# discovered TWICE must be encoded as a gate. This class turned up four times in
# one lane in one session.
#
# ⚠️ IT CATCHES PROSE THAT IS *MISSING*, NEVER PROSE THAT IS *WRONG*. A docblock
# naming a mechanism that has been deleted sails straight past this — that is how
# the compose flag came to teach the dead pool-env mechanism for a whole day.
# Stated here so nobody reads a green as "the flag docs are correct".
#
# WHAT COUNTS AS A FLAG is deliberately narrow: read from getenv()/$_SERVER,
# define()d to a BOOLEAN expression, or a brand-new tracked platform/config/*.php
# carrying an 'enabled' key. The first draft treated any new LG_* symbol as a flag
# and RED-ed on LG_FC_DRAFT_META (a post-meta key) and LG_FC_DRAFT_TTL_DAYS (an
# integer) — a gate that reds on non-defects blocks every lane, which is the exact
# harm this one exists to prevent. A missed flag is a defect this gate FAILED to
# catch; a false RED is a defect it CAUSES.
#
# RED-FIRST FROM REAL HISTORY rather than a mutation, which is stronger because the
# red is a defect that actually shipped:
#   notif-quickreply-v2 @ 8b988b5  RED  exit 1, naming both symbols
#   notif-quickreply-v2 @ 51cb578  GREEN exit 0, after the row was added
# and it stays green on branches that introduce no flag (seo-canonical-hub) and on
# branches whose new LG_* symbols are constants (compose-draft-first).
#
# Reads only git — no browser, no network, no DB, so it cannot flake under load.
# Merged branches give an empty diff vs their own merge-base and pre-FLAGS.md
# branches have no register: both exit 2, never 1, so neither can redden a train.
run "flag-register" python3 "$(dirname "$0")/flag-register-gate.py"

echo "=== GATE 63: the welcome one-shot reaches BOTH rails, fires once, never retro-fires ==="
# The rail-agnostic first-activation welcome. Arbiter::sync used to stamp the
# one-shot only on a TRANSITION into a paid tier, which is really a question about
# whether the paid role was applied before or after the account was created — and
# the two production creation paths sit on opposite sides of it. Same money, same
# tier, different product by rail, which is exactly what Ian's dual-wield ruling
# forbids.
#
# THE HEADLINE IS ASSERTED AS SYMMETRY, not as "the welcome fires": the two
# creation SHAPES must end in the SAME outcome, and the gate says nothing about
# which. That survives the mechanism moving, and it is red exactly while the
# defect exists.
#
# THE DANGEROUS HALF IS RETRO-FIRE. 1,109 currently-paying members carry no
# welcome marker, so a fix keyed on "paid and never welcomed" mails eleven hundred
# people on the first sweep, unrecallably. §D asserts the cutover fence
# BEHAVIOURALLY against a member shaped like those 1,109, and does so WITHOUT
# running any backfill — a backfill is something a person has to remember to run,
# which makes it a plan, not a guard.
#
# It READS THE FLAG rather than hardcoding a state, so it stays correct the day
# the default flips. Mail assertions read STAMP INTENT, never delivery: pre_wp_mail
# is filtered on dev2 and the poller's mail is separately suppressed on live.
# Companion: welcome-activation-redfirst.py mutates 10 ways and requires a RED
# from each, plus a no-op control and a prose control that must stay GREEN.
run "welcome-activation" python3 "$(dirname "$0")/welcome-activation-gate.py"

echo "=== GATE 55: directory location cap — backlog 20, list views never exceed City/State ==="
# Ian 8/15, via keeper: found live via member Luke (WP 2091) — an admin
# browsing the directory saw every member's raw street address on every row.
# Number not yet allocated — gate numbers are ALLOCATED BY KEEPER, never
# self-minted (the exact 36-vs-36 collision featured-member-gate hit above is
# what that rule exists to prevent). Runs regardless: the assertions are real
# and red-first proven against real live rows (Luke + Michael Swisher) even
# before the banner has a final number.
run "directory-location-cap" python3 "$(dirname "$0")/directory-location-cap-gate.py"
echo
echo "=== GATE 46: compose media — abandon leaves ZERO orphans, each post its own library ==="
# Ian, 2026-08-15: no orphans ("obviously"), and "each post has its own library".
# MEASURED BEFORE IT EXISTED: uploading through the real picker and abandoning
# left an attachment with post_parent 0, the file on disk and nothing referencing
# it. Neither this build nor WordPress core sweeps unattached media, and a
# subscriber has upload_files, so an abandoning member could fill the library.
#
# Assertion 4 counts the SITE-WIDE unattached total, not just the rows the gate
# made: a gate that only counts its own rows cannot see an orphan produced by a
# path it did not model. Assertion 6 exists because wp_delete_auto_drafts()
# removes the post and LEAVES its attachments. 7b PLANTS two cron entries and
# proves the flag-off heal takes the whole HOOK, not just the next occurrence.
#
# ⚠️ DEPLOY COUPLING: this reports CANNOT RUN (exit 2, counted as no-verdict and
# NOT as red) until the serve carries draft-first — the docroot mu-plugin is a
# symlink into the serving checkout, so it needs the merge AND the pull.
run "compose-media" python3 "$(dirname "$0")/compose-media-gate.py"
echo
echo "=== GATE 47: the compose form meets WCAG AA in DARK, and has no bright surfaces ==="
# Ian, 2026-08-15: "compose works well. Needs some dark mode love." Dark contrast
# has bitten this platform 3+ times, which is why it is a gated class. It reads
# the RENDERED page rather than a hex-pair list, because the form's colours are
# ACF/WP stylesheets meeting the site tokens and cannot be written down ahead.
#
# ⚠️ REPOINTED at the STANDALONE form. It measured the hub's injected modal until
# Ian ruled the form leaves the modal (main 4dbb192 killed the embed); the
# contrast concern moved with the surface rather than going away.
run "compose-dark-1280" python3 "$(dirname "$0")/../frontend-compose/dark-contrast-sweep.py" --width 1280
run "compose-dark-390"  python3 "$(dirname "$0")/../frontend-compose/dark-contrast-sweep.py" --width 390

echo "=== GATE 57: notifications — filter by type, and a typed clear touches ONE type and ONE member ==="
# Backlog 11.6 (Ian 8/1). Number 57 from keeper 2026-08-16, never self-minted.
# Drives the REAL endpoint with a REAL second member present, because "only that
# member" cannot be tested with one account — a single-account test passes on
# code that clears the whole table. Probe members are per-run and PID-keyed, and
# setup repairs on ENTRY as well as tearing down, so a killed run cannot leave
# fake members in the directory.
run "notif-type-filter" python3 "$(dirname "$0")/notif-type-filter-gate.py"

echo "=== GATE 58: the featured card may only repeat what the member's profile publishes ==="
# Ian 2026-08-16: "the text for my profile isn't anywhere on my profile." It was a
# VISIBILITY LEAK — at_a_glance renders behind the header block, which defaults to
# members-only, so the public front-page card was printing member-scoped text.
# Number 58 from keeper. Resolves both fields exactly as the card does, so it
# asserts what ships rather than a parallel idea of it.
run "featured-card-text" python3 "$(dirname "$0")/featured-card-text-onprofile-gate.py"

echo "=== GATE 64: hub author-filter banner — the Advanced Search modal's in-place apply must swap it too ==="
# Backlog 38 (Ian 8/16), P0. Number 64 from keeper. Ian reported the green
# author banner missing for one author and present for another; disproved
# name fragility (reproduces identically for both, via real clicks through
# the real suggest dropdown) and root-caused it to forums.js's fmodalApply —
# it swaps the feed cards, modal body and chip bar in place but never the
# banner, which sits outside all three. Flagged OFF by default
# (platform/config/hub-author-banner-swap.php); reads this branch's tree via
# the featured-members lane preview, so it must be UP for this to run
# (tools/preview/lane-preview.sh up featured-members).
run "hub-author-banner-swap" python3 "$(dirname "$0")/hub-author-banner-swap-gate.py"

echo "=== GATE 65: author archive icon — one predicate, two consumers, vis-0 both ways ==="
# Backlog 27 (Ian 8/16). Number 65 from keeper. Ian ruled the mock: one more
# icon in the profile's existing social palette, no pill, no new row; then
# refined it — the icon shows only for members who have authored at least
# one discussion or CPT post. Visibility comes from hub_author_activity_count()
# (bb-mirror/web/forums/_hub-filters.php), the SAME predicate the Hub author
# banner already uses — never a parallel count, so the icon and its
# destination can never disagree. Flagged OFF by default
# (platform/config/author-archive-icon.php); reads this branch's tree via
# the featured-members lane preview, so it must be UP for this to run.
run "author-archive-icon" python3 "$(dirname "$0")/author-archive-icon-gate.py"

echo "=== GATE 66: hub author filter — a display name's own comma broke it entirely ==="
# Found diagnosing backlog 38, not reported separately. hub_url() joined
# multiple selected authors with implode(',', ...) and the parser split back
# on the same character, so any ONE display name with its own comma sliced
# into fragments matching neither the real author (measured live: "John
# Lehmann, Old Naples Guitars" -> 2 bogus banners, 0 cards; 6 authors on
# live carry a comma this way). Number 66 from keeper. Flagged OFF by
# default (platform/config/hub-author-comma-fix.php); reads this branch's
# tree via the featured-members lane preview, so it must be UP for this to run.
run "hub-author-comma" python3 "$(dirname "$0")/hub-author-comma-gate.py"

echo "=== GATE 68: the compose page wears the site chrome, in BOTH themes ==="
# Ian, 2026-08-16, testing /compose/ live: "can we get the header and footer so it
# looks like a normal page?" Keeper's ruling alongside it: standalone pages MIMIC
# the chrome (nothing renders from the WP theme), and the presence gets gated in
# BOTH THEMES from birth.
#
# LIVENESS FIRST, and it is what makes the rest mean anything: a locked-out browser
# serves a styled 403 that looks identical in every theme, so a chrome assertion
# against it fails for the wrong reason — and a presence-only assertion could even
# PASS on it.
#
# VISIBLE, NOT PRESENT. Computed display plus a real layout box, never a class name
# in the HTML. Chrome sitting in the DOM at height 0 is exactly the
# "presence is not reachability" shape that has bitten this box twice.
#
# BOTH THEMES because a token-coloured chrome can be perfectly present and
# invisible in one of them — this same form produced one of those this week
# (white ink on a token that flips lightness in dark).
#
# THE EMBED LEG IS AN ABSENCE ASSERTION, PAIRED WITH A LIVENESS ONE: "no chrome" is
# trivially true of a 404, so it requires the form first and then judges the
# absence, and SKIPS rather than scoring if that route is unreachable.
#
# RED-FIRST FROM REAL STATE, not a mutation — run against the serve before the
# chrome shipped: liveness ok in BOTH themes, header and footer RED in both, embed
# leg ok non-vacuously, 4 of 5, exit 1.
run "compose-chrome" python3 "$(dirname "$0")/compose-chrome-gate.py"

echo "=== GATE 69: the loothprint EDIT DOOR — one pill in the dock, and only for the entitled ==="
# ⚠️ RE-AIMED 2026-08-21 (#179), NOT RENUMBERED — same control, new shape, so its
# gate moved with it rather than a second number being minted (the #172 precedent).
# Ian superseded his own Option A: "I don't think we need the option for text and
# data. It can be one button that kicks to the form they filled out", and the night
# before: "could we put it in the bottom stack of sticky buttons ?"
#
# IT NOW NEEDS THREE VIEWERS. "Members one, admins two" is unfalsifiable against a
# single account: the old gate's "entitled" user held edit_archive_poc, so it could
# never have caught a build that showed everyone both doors. The post's OWN author
# WITHOUT the cap must get exactly 1, a cap-holder exactly 2, a stranger 0.
#
# IT ALSO CARRIES #179's PILL-FAMILY ASSERTIONS, because they are about the same
# control: one height, one radius, a shared edge on the axis the dock is laid out
# on, a phone row that fits and does not wrap — and HK-027 asserted against the
# leftmost RENDERED BODY TEXT rather than the article wrapper box, which has padding
# and would have passed a dock sitting on the words.
# ⚠️ It deliberately does NOT assert 641-700px, where MAIN IS ALREADY RED: measured
# 2026-08-21, body text starts at x=41.6/44.0 against a dock right edge of 82, so the
# compact stack already covers ~40px of copy at the bottom of its own media query.
# Pre-existing, reported not fixed by #179; asserting it here would make this gate
# red for another bug and block every lane.
#
# --path lets it measure a lane preview: /srv/archive-poc is a symlink into the
# serving checkout, so the default path always reads MAIN.
#
# (Historic, for the record: the shape Ian picked on 2026-08-16 was a two-line
# choice — "Details & files" and "Page text". That is what this gate used to
# assert.)
#
# THE DOOR IS WHAT THIS GUARDS, deliberately. The form and its permission check
# existed for months and were reachable by NOBODY; the way in is the part that was
# missing and therefore the part most likely to be lost again.
#
# SIGNED-IN LIVENESS, not just page liveness: the control renders only for
# edit_archive_poc or the author, and that identity comes from a /whoami loopback
# that is INTERMITTENT for a minted cookie — measured, the same tree gave items=2
# then items=0. Without it "no menu" and "not recognised" are one observation and
# the gate reports a coin toss. Signed-out => exit 2, never a fail.
#
# A REAL POST ID, not merely a link: the first build read a post_context key that
# does not exist and would have shipped id=0 — a compose form for nothing.
#
# PAGE TEXT MUST STILL BE THERE: the ruling ADDED a door, it did not remove one.
#
# THE STRANGER LEG is an absence assertion paired with liveness on the same page,
# because "no button" is trivially true of a 404.
#
# ⚠️ IT READS THE EFFECTIVE FLAG FROM THE SERVING CHECKOUT, NOT FROM THIS REPO, and
# prints which directory it used. The box-local override is gitignored and exists
# only beside the deployed app, so a repo-relative read always answers the tracked
# default — this gate reported exactly that disagreement on its first run and
# blamed the app, which was right. Reading the TRACKED file from the repo is
# correct when you are asserting what ships; reading the EFFECTIVE state is not.
run "loothprint-edit-door" python3 "$(dirname "$0")/loothprint-edit-door-gate.py"

echo "=== GATE 70: the mirror pipe is LOUD, and reconcile can reach backwards ==="
# Backlog 3.9. Two guarantees, each of which has already failed in production.
#
# A SKIP IS NOT A SUCCESS. bb_mirror_upsert_reply() returns without writing when a
# reply is unmirrorable — correctly, because throwing there wedged live's reconcile
# for 11 days from 2026-07-29. But _sync then answered 200 for a write and a drop
# alike, so the 2026-08-09 analysis read 290 _sync POSTs, saw every one return 200,
# and could not tell the 11 replies that vanished from the 61 that landed.
#
# ⚠️ IT ASSERTS 202 SPECIFICALLY, NOT "not 200". A 4xx/5xx looks like a stricter
# fix and is a worse one: the WP hook is fire-and-forget, so an error status turns
# an unmirrorable row into a retry storm against a condition retrying cannot fix.
# That mutation reds on purpose.
#
# AND IT ASSERTS THE UPSERT STILL NEVER THROWS. Loud is not fatal — "fixing" the
# silence by throwing trades a two-month stale row for an eleven-day outage.
#
# BACKWARDS REACH: the delta walk upserts WHERE post_modified_gmt >= bookmark - 60,
# so anything that diverges and ages out is invisible forever. Measured on live
# 2026-08-16: all five diverged replies were 60-73 days older than the bookmark.
# The deep sweep must exist, be interval-bounded on its OWN state key, repair on a
# MODIFIED-TIME difference (not just absence, or stale edits stay permanent), use
# the poison-tolerant walker, and REFUSE AN EMPTY WP READ — zero rows must never
# read as total divergence.
#
# Source-read only: no browser, no network, no DB, so it cannot flake under load.
# ⚠️ Honest limit, in its docstring: a skip note DISABLED in place (if(false)) still
# passes, because this leg reads source rather than executing. Deletion is caught.
run "mirror-sync-loud" python3 "$(dirname "$0")/mirror-sync-loud-gate.py"

echo "=== GATE 71: every sitemap-listed URL serves OPEN content to a logged-out visitor ==="
# The standing gate keeper promised Ian (backlog 40, job 3): sample real URLs
# from the live /sitemap.xml (static/content/profiles/discussions) and prove
# each one is actually open to anon, using a CONTENT-PRESENCE discriminator —
# the page's own DB-sourced title/name appearing in an anon fetch — never a
# login-string check (a locked-out page and a genuinely open page both
# routinely contain "Sign in" somewhere in shared header chrome, so that
# check proves nothing either way).
#
# FIRST REAL RUN FOUND TWO THINGS. One was a gate bug: WordPress's
# wptexturize() swaps plain ASCII punctuation for typographic equivalents on
# render (' - ' -> ' – ' en-dash), so a DB title with a plain hyphen no
# longer substring-matched its own correctly-rendered page — fixed by
# normalizing dash/quote variants on both sides before comparing. The other
# was real: /u/ut-test-alice, a leaked test-fixture profile, sitemapped and
# 404ing for anon — a third independent instance of the fixture-hygiene class
# stripe-membership separately reported the same night. That stays a real RED
# here on purpose: a sitemapped 404 is not "open" regardless of whether the
# cause is a visibility lock or a leaked fixture row, and special-casing it
# away would defeat the gate's own point.
run "sitemap-anon-open" python3 "$(dirname "$0")/sitemap-anon-open-gate.py"

echo "=== GATE 73: the profile prints the address the MEMBER TYPED, ladder unmoved ==="
# John Wilmink retyped his location to his shop and dragged the pin. The pin moved
# and was right; the address TEXT kept showing his HOME address, for 47 days. His
# typed value was stored correctly the whole time in users.location_text — the block
# printed users.location_address instead, a column whose ONLY writer in the repo is
# the one-time BuddyBoss import. Nothing maintained it, so it froze on the
# pre-import address the moment a member edited and, being read first, beat what
# they had just typed. Four live members were mis-rendering (190, 590, 598, 1323).
#
# ⚠️ THE LEG THAT MATTERS MOST IS NOT THE FIX, IT IS THE LADDER. "Print what the
# member typed" sits one careless edit away from printing a street address to an
# audience who chose City. So every state asserts city/state still print the
# STRUCTURED label AND that neither address string appears in them — a leak
# assertion paired with a liveness one, since "no street address" is trivially true
# of an empty string (feedback-absence-assertion-needs-liveness).
#
# Reads the flag rather than hardcoding a state, so flipping the default needs no
# edit here. Pure function under test: no DB, no browser, no network, so it cannot
# flake under load and cannot go vacuously green behind a locked-out browser.
#
# RED-FIRST, three mutations, each into a COPY of the tree (never a checkout --):
#   revert the Block.php fix        RED exit 1, naming the home address it printed
#   ungate one location_address write RED exit 1, "3 writes but 2 flag guards"
#   make coarseText fall through    RED exit 1, 12 findings, ladder leak in all 3 states
run "location-address" python3 "$(dirname "$0")/location-address-gate.py"

echo "=== GATE 75: one payment source per member — no double-paying at any door ==="
# Ian, 8/19, verbatim: "We should disallow double payment source for the same
# user." Before this, a member paying $5 or $11 on Patreon RIGHT NOW could walk
# through checkout and be charged a second time with no warning anywhere. The
# Stripe side of that rule already existed ("starting a second subscription
# would bill you twice") and had been Patreon-blind since the day it was written.
#
# THREE doors, not the two the plan named. Re-verification found a third:
# POST /wp-json/lg-member-sync/v1/me/checkout-session minted subscription
# sessions for logged-in members with no Patreon check at all. It is asserted
# here so it cannot go back to being the unwatched one.
#
# ⚠️ IT ASSERTS BY REFLECTION THAT IT IS TESTING THIS BRANCH. lg-stripe-billing's
# composer autoloader maps LGSB\ into the SERVING CHECKOUT, so a lane running
# this on dev2 would otherwise be measuring main and calling it verified
# (trap-harness-and-serve-answer-from-main). Source-level assertions run on
# comment-stripped tokens, so prose can never satisfy one
# (feedback-red-first-that-stays-green).
#
# Reads the flag rather than hardcoding a state: absent / OFF / ON are all
# exercised, so flipping the default needs no edit here. OFF is asserted as a
# real ABSENCE — the WordPress route does not exist — paired with the ON leg
# beside it proving that absence was not a dead code path.
#
# The /lgjoin/ HTTP leg REPORTS rather than asserts while every membership-pages
# surface on dev2 404s at the nginx door (an unrelated infra defect attributed on
# issue #150). A dead surface must not pass as a green one
# (trap-locked-out-browser-goes-vacuously-green).
#
# RED-FIRST: twelve mutations, listed at the foot of the gate file, each applied
# against a file SNAPSHOT (never `git checkout --`) and each reddening its NAMED
# assertion; two no-op mutations confirmed to redden nothing, so the copy
# assertion compares words and not bytes. No DB, no browser, no network on the
# asserting legs, so it cannot flake under load.
run "double-pay-block" php "$(dirname "$0")/double-pay-block-gate.php"
echo

echo "=== GATE 76: the Stripe rail grants the tier that was PAID for ==="
# Ian, 8/19: "I've decided I want to be able to have multiple tiers." That
# ruling REPLACES the 8/08 one — "move to ONE tier for the stripe memberships
# and have ALL tiered content open to the one tier through stripe" — which was
# implemented faithfully as a hardcoded constant and is still quoted verbatim
# in StripeLifecycle's docblock.
#
# ⚠️ THE OBVIOUS ASSERTION IS A VACUOUS GREEN, and that is the single most
# important thing to know before editing this gate. StripeLifecycle::TIER was
# already the literal 'looth3', so "a PRO purchase grants looth3" PASSED on the
# very defect — it cannot tell a RESOLVED looth3 from a CONSTANT one. Every tier
# assertion here is therefore written against the tier the constant is NOT: the
# one that bites is "a LITE purchase grants looth2"
# (feedback-red-first-that-stays-green).
#
# The defect direction was also the opposite of the expected one. Nobody was
# under-granted: a member buying Looth LITE at $5 was granted looth3 — Pro. And
# it is not additive, because grantMembershipFromSubscription revokes by source
# and re-inserts on any ref change, so the constant OVERWRITES the correctly-
# resolved looth2 entitlement the Slim return path writes.
#
# OFF IS PROVEN, NOT ASSERTED. The lgms_multi_tier OFF leg runs against a
# database with NO products or prices tables, so any price lookup on that path
# is a hard SQL error rather than a quiet pass — an absence assertion with a
# liveness assertion beside it (feedback-absence-assertion-needs-liveness).
#
# §8 asserts the join-page consequence rather than a row count: retiring a
# superseded price must not make a member still billing on it VANISH from
# lgjoin's already-subscribed lookup, which is the double-charge shape.
#
# RED-FIRST record is at the foot of the gate file. No DB, no browser, no
# network — SQLite in memory only — so it cannot flake under load.
run "stripe-multi-tier" php "$(dirname "$0")/stripe-multi-tier-gate.php"
echo

echo "=== GATE 79: who the header's Join sends to OUR join page, and can they get there ==="
# Ian, 2026-08-20, verbatim on #165: "can you Wire the header on Dev2 to have
# the stripe menuing that a logged out user would see?" The anon header's Join
# went straight to patreon.com — his own 6/12 ruling, right for a Patreon-only
# world. Behind platform/config/header-join-stripe.php, default 'off'.
#
# THREE STATES since #170 — Ian 8/20: "We need the join button in the header to
# still go to patreon unless a test user is there on live." 'off' = nobody,
# 'allowlist' = the Stripe soft-launch cohort while signed in, 'on' = everybody.
# The middle state is what makes live safe to arm, and it rides a per-viewer
# capability an anonymous ctx never carries, so the logged-out page stays
# byte-identical to 'off' and stays cacheable. Asserted by cmp, not argued.
#
# ⚠️ THE OBVIOUS ASSERTION IS NEARLY WORTHLESS HERE, which is the whole reason
# this gate is shaped the way it is. "Flag ON means href=/lgjoin/" is TRUE of a
# build where /lgjoin/ hands an anonymous visitor "This page isn't available
# yet" — the MEASURED state of dev2 the day this landed, because that page's
# audience is picked by a DIFFERENT switch (`lgms_stripe_pages_live`). §E
# asserts the destination admits anon whenever the flag is ON, and reports
# rather than asserts while it is OFF, so it cannot redden an unrelated lane.
# The two flip in the same window or Join is wired perfectly and lands nowhere.
#
# OFF IS BYTE-PROVEN, NOT ARGUED: the partial is rendered from this branch and
# from `git show origin/main:lg-shared/site-header.php` with the same anonymous
# ctx and compared BYTE FOR BYTE — for the ABSENT config too, and for an AUTHED
# ctx in every state including ON. Red-first mutation 7 is a pure-whitespace
# edit that changes no behaviour and is caught by nothing else, which is exactly
# the defect lane 129 found in its own OFF path.
#
# THE CONTRACT IS ROUTE-AGNOSTIC, like gate 12's: "an anon visitor can reach
# Join", never "this pill is visible". At <=640 on the hub, forums.css hides the
# whole header aside by design and the PWA account sheet IS the anon door — so
# that sheet's Join is asserted there, including the rule that an internal href
# must NOT open a new tab (an unconditional target="_blank" throws a member out
# of the installed PWA to buy a membership in a browser tab).
#
# ⚠️ KNOWN_MAIN_GAPS: 821px is REPORTED, NOT SCORED. Measured on main — the Join
# pill sits at x=845 in an 821px viewport, entirely past the right edge; the nav
# collapses at <=820, so 821-904 is a band where the full nav and the anon
# cluster cannot share a row. Same class as gate 12's 641-820 sign-in dead band,
# one band over, and pre-existing. The allowance SELF-EXPIRES: if 821 ever
# passes, the gate FAILS telling you to delete the entry. Every other width is a
# hard assertion, so the list cannot grow silently.
#
# §A-C and §E are pure source + php + one HTTP GET; only §D drives a browser.
# Red-first: tools/gates/header-join-redfirst.sh — 12 mutations each reddening
# its OWN named assertion, 2 no-ops proven inert, against file SNAPSHOTS and
# never `git checkout --`.
run "header-join" python3 "$(dirname "$0")/header-join-gate.py"
echo

echo "=== GATE 80: the front page drops its SECOND join, and the funnel reads in dark ==="
# #169 + #171, both from Ian's logged-out walk of 2026-08-20. "The secondary join
# on the front page at the top banner can go away" and "dark mode is sucking on
# the patreon stuff."
#
# ⚠️ THE ISSUE NAMED THE WRONG CONTROL. #171 names the header Connect Patreon
# pill in dark; measured, that pill is 11.34:1 in dark and has no dark defect at
# all. Its outline DID fail — at 2.72:1, in LIGHT. So this gate grades BOTH
# THEMES on every surface: shaped around the charter's hypothesis it would have
# gone green on the phantom and missed the real thing one theme over.
#
# What was actually broken was /lgjoin — the page #165 wires anon Join to.
# membership-pages/web/lg-shortcodes.css had ZERO dark rules (measured: the
# string appeared no times), so all four Subscribe buttons rendered #e5e7e1 on
# #ffffff = 1.25:1, invisible. That is the SAME defect class join.css already
# fixed for /connect-your-patreon/, missed because the two pages render the same
# .lg-join__* names from TWO DIFFERENT stylesheets — which is why §C asserts BOTH
# copies carry the block.
#
# §B renders the real script rather than grepping it: a source grep for the flag
# passes on a file that reads it into a variable nothing consumes. OFF is proven
# byte-identical to origin/main (72,054 both ways) against a SNAPSHOT SIBLING,
# never `git checkout --`. Every absence is paired with a liveness assertion —
# "no banner" is trivially true of a 500 — and the MEMBER greeting sharing that
# if/elseif is asserted present in BOTH states.
#
# §D adapts instead of assuming: it fetches the served stylesheet and compares it
# to this worktree's, so it grades what the box serves once merged and injects
# the branch's REAL FILE BYTES before that, saying which mode it is in. A browser
# gate that silently graded main would be worse than none
# (trap-harness-and-serve-answer-from-main).
#
# ⚠️ MOTION IS KILLED AS CSS, BEFORE THE PROBE WALKS. `transition: background
# 0.15s` on .lg-join__buy means getComputedStyle can return an INTERPOLATED value
# mid-fade that is nearly #ffffff — indistinguishable from the real defect. The
# first version of this gate stopped motion after probing and reported four false
# 1.25:1 findings on a working branch.
#
# Red-first: tools/gates/front-banner-patreon-dark-redfirst.sh — 11 mutations
# each reddening its OWN named assertion plus 2 no-ops proven inert, every one
# against a file SNAPSHOT restored by an EXIT trap.
run "front-banner-patreon-dark" python3 "$(dirname "$0")/front-banner-patreon-dark-gate.py"
echo "=== GATE 82: approval starts work by itself — for PRE-STAGED work, and nothing else ==="
# #138 phase B. Ian's ruling 8/20 (verbatim): "If a lane goes Idle waiting for me
# to make a decision, I want the next work in line to start up while I'm
# screwing around." tools/approved-watcher.sh now spins the lane itself.
#
# ⚠️ THE FAILURE MODE IS THE REVERSE OF THE OBVIOUS ONE. A lane that fails to
# start is visible in five minutes. A watcher that starts one it should not is
# not: nine parked branches on this box wear open+approved issues whose charters
# are still on disk, so a watcher missing its one-spin-ever backstop re-spins the
# lot, on two cores, at once, with nobody watching. Every refusal is asserted.
#
# NO NETWORK, NO REAL SEAT, NO CLAUDE PROCESS — issues are a fixture, the spin
# command is a recorder, and state/prompts/worktrees/manifest/bell/quiet all
# redirect into a per-run temp dir. THE QUIET PATH ESPECIALLY: a gate that
# touched the real /tmp/keeper-quiet would set a box-wide hold on the whole
# fleet, and the window before it cleared is a fleet that silently stops working.
#
# EVERY ABSENCE IS PAIRED WITH A LIVENESS. "It did not spin" is equally true of a
# broken script and an empty fixture, so each refusal runs a control in the same
# harness where the same issue DOES spin, one condition apart. Leg 7 applies that
# to the script's own PRODUCTION defaults, which no other leg ever executes —
# every other leg drives it through LG_AW_* overrides.
#
# Red-first: tools/gates/approved-autospin-redfirst.sh — 19 mutations each
# reddening its OWN named assertion, 2 no-ops proven inert, on file SNAPSHOTS and
# never `git checkout --`. It found three things, all fixed: a cap checked twice
# (the second copy made the first unprovable), a fixture where nothing could spin
# even with the guard deleted, and a wrong claim about which leg catches what.
run "approved-autospin" python3 "$(dirname "$0")/approved-autospin-gate.py"
echo
echo "=== GATE 83: a members-only one-liner does not reach the crawler-visible head ==="
# #166, Ian 8/20: "Fix meta leak." A profile's <meta name="description">,
# og:description and twitter:description carried users.at_a_glance VERBATIM to
# logged-out visitors, crawlers and link unfurls while the rendered body
# correctly withheld it behind the members gate. 42 members on LIVE, 28 on dev2.
#
# NOT ONE OF THE 42 HAD CHOSEN members-only. It is the platform default 1,917 of
# 1,933 members have never opened — the head and the body simply disagreed about
# what that default meant, which is what makes it a defect and not a policy.
#
# The same class lived on /p/ (practice tagline AND about, under
# PRACTICE_HEADER_DEFAULT). Found twice, so gated: one gate, four surfaces.
#
# ⚠️ §B IS NOT OPTIONAL PADDING. Deleting all three meta tags outright satisfies
# the leak check perfectly, and 14 live members have a legitimately public
# one-liner that is good SEO. The fix is a CEILING, not a deletion, and only the
# public-header side of the bracket can tell those apart. Every absence check is
# likewise paired with a liveness one: >=2 description tags AND the generic
# fallback still naming the member, so "the leak is gone" and "the page is gone"
# cannot look the same.
#
# ⚠️ IT AUDITS WHAT LG_GATE_HOST SERVES, WHICH IS MAIN. /u/ and /p/ are symlinked
# out of the serving checkout. A LANE must run tools/preview/lane-preview.sh up
# <lane> and set LG_MGL_PREFIX=/preview/<lane>, or it will be told about main.
#
# §E is the head-side half of gate 39 §G7's fence: #107's consent lets the
# FEATURED CARD republish a members-only one-liner; a tick is not permission to
# put the line in Google. §G7 asserts the body, §E asserts the head.
#
# Red-first: tools/gates/meta-glance-leak-redfirst.sh, plus 4 mutations on file
# SNAPSHOTS (never `git checkout --`) — revert-the-ceiling, withhold-from-
# everyone, launder-the-consent, and a whitespace no-op proven inert.
run "meta-glance-leak" python3 "$(dirname "$0")/meta-glance-leak-gate.py"
echo

echo "=== GATE 84: the stuffing detector bans at the LOGIN DOOR — and nothing else does ==="
# #162. Ian 8/20: "can we just add it to a file of known offenders and nip it at
# our webserver?" — narrowed the same day to "this should only block ips that try
# several different logins in one block".
#
# ⚠️ THE OBVIOUS IMPLEMENTATION TAKES THE SITE OFF THE INTERNET. Neither box
# restores real client IPs, so $remote_addr is a CLOUDFLARE EDGE NODE; a deny
# list keyed on it bans the edge and with it every visitor behind that edge. The
# real client is in CF-Connecting-IP — a header anyone can forge by connecting
# straight to the world-knockable origin, which would let an attacker put an
# address of their choosing behind our own deny rule. §C and §G8 are those two
# facts, asserted: the address that gets banned is what the connection PROVES.
#
# Runs the real mu-plugins against a stubbed WordPress, and the real generated
# nginx config on a THROWAWAY unprivileged nginx on a per-run port with the door
# pointed at a socket that does not exist — 403 means refused, 502 means it
# reached the hand-off to PHP. No network, no root, no DB, no WordPress, no FPM,
# nothing shared with the box, so two suites at once cannot make each other red.
#
# Every absence is paired with a liveness control one condition away, because
# "nothing was banned" is equally true of a working guard and a harness that can
# never ban anything. Red-first: tools/gates/auto-ban-redfirst.py, 32 mutations
# each reddening its OWN named assertion plus 4 no-ops proven inert, on file
# snapshots and never `git checkout --`. It found a real renderer bug (an empty
# env value read as unset, so the nginx test could not be switched off and every
# offline render rolled itself back) and two ways the gate had defeated itself.
run "auto-ban" python3 "$(dirname "$0")/auto-ban-gate.py"
echo

# 85 — THE ANONYMOUS TESTER'S UNLOCK LINK (#180). Ian 8/21: "I need it to go to
# patreon unless the user has some kind of token url or something to unlock the
# whitelisted pages." ⚠️ THE ASSERTION THAT BITES IS THE REFUSAL: "a browser
# holding the token sees /lgjoin/" is satisfied by a build that shows it to
# EVERYBODY, which is the state Ian was complaining about. So the grant is one
# assertion and the refusals are seven, plus 'off' must keep meaning NOBODY.
# OFF is byte-proven with cmp against origin/main across 3 states x 3 viewers x
# 2 config shapes, on a tree that already carries the microcache change. §E
# measures the funnel's refusal AS IT ACTUALLY IS and reports the mechanism
# rather than asserting a whitelist that does not exist. No browser, no DB, no
# WordPress, no FPM, per-run port — it cannot flake under load or go vacuously
# green. Red-first 25/25 (22 mutations, 3 no-ops); it found one assertion that
# passed on its own defect and one mutation absorbed by a duplicate guard.
run "tester-unlock" python3 "$(dirname "$0")/tester-unlock-gate.py"

echo "=== GATE 86: the soft-launch cohort is REAL in the checkout path (#181) ==="
# Ian 8/21, decision box: "Fix before go-live". Lane 180 measured that NOTHING in
# the signup or checkout path consulted the cohort. Three unrelated accidents did
# the refusing, and the load-bearing one on live -- an EMPTY Stripe catalogue --
# is removed on purpose at go-live. Reproduced on dev2 before the fix, anon and
# cookieless, with a real price id from the public /billing/v1/products list:
# POST /billing/v1/checkout -> 200 + a live Stripe client secret.
#
# ONE OPTION, THREE DOORS, TWO HALVES. lgms_checkout_audience is off/allowlist/on
# and reads the ONE cohort list through StripeLifecycle::inCohort(). The MINT half
# refuses early and honestly at all three doors; the PROVISION half, inside
# UserProvisioner::findOrProvision, is the backstop that cannot be routed around
# because it reads the option in-process with no network between question and
# answer.
#
# ⚠️ THE DEFAULT IS `allowlist` -- ENFORCING -- and it is the deliberate exception
# to flags-default-dark: the enforcing state must be the state the boxes run, or
# it is never exercised before the night it has to work.
#
# ⚠️ THE ASSERTION THAT MEASURES NOTHING is "a cohort member can buy" -- true of
# the broken code too, since everybody could buy. The ones that bite are the
# refusals (B2, B3, C1). Same family as #148's "a PRO purchase grants looth3".
#
# ⚠️ THE FENCE SITS ONE LINE BELOW THE EXISTING-BRIDGE EARLY RETURN, so an
# already-bridged member is untouched in every state and their sweeps keep
# landing -- grants AND retractions (C3/C4, driven through the REAL Arbiter).
#
# looth4 IS RESPECTED (Ian 8/21: "looth4 is the everything bypass the stripe side
# of membeship needs to respect"). §I runs the REAL Arbiter over a comp holder
# with no Stripe customer and asserts the role survives, with a liveness leg
# proving the same sweep DOES demote a non-comp member.
#
# RED-FIRST: 20 mutations, 2 no-op controls, all caught -- see
# tools/gates/checkout-audience-redfirst.py. Two were found by that run and
# fixed: B8c could not see the guard's two sentences being made identical, and
# one "mutation" changed no decision at all.
run "checkout-audience" php "$(dirname "$0")/checkout-audience-gate.php"
echo

echo "=== GATE 87: the header's account chip is ONE LINE, and the name survives it ==="
# #173. Ian 8/20, signed in as "Massimiliano Monterosso Maxmonte Guitars":
# "Verbose names in the profile icon in the header? Maybe do a ....."  Again 8/21
# as "Ian Davlin The Looth Group": "Something changed in the header. We are
# stacking words that used to be inline."
#
# ⚠️ SECOND DISCOVERY OF THE CLASS, which is why it is a gate. site-header.css's
# own <=820 rule says "the display name is the first thing to drop (it's what
# tips a busy admin aside into a two-line wrap)" -- that was #1, answered by
# hiding the name on tablets. This is the same defect at desktop widths.
#
# ⚠️ THE OBVIOUS ASSERTION IS GREEN ON THE BROKEN STATE. The header BAR never
# changed height: .lg-chrome__inner is height:60px FIXED. What grew was the
# button inside it -- 40px -> 49 -> 62 -> 88 -- and it spilled OUT. So "the
# header is still 60px" is true of Ian's screenshot. This measures the BUTTON
# and the LINE COUNT instead.
#
# §D is the leg that earns the gate its keep: a 71-character name must add NO
# horizontal scroll over a 3-character one. The max-width is derived from the
# logo, the nav's min-content and the aside, none of which a gate can pin down
# forever, so the CONSEQUENCE is asserted rather than the number -- add a
# seventh nav item and this reddens instead of the constant silently going bad.
#
# The Join pill is the second aggravator (#170): it costs ~76px of headroom, so
# every cell runs with AND without it. Ian's own name with the pill is asserted
# by itself, because that is the exact combination that produced the report.
#
# NO WORDPRESS, NO DB, NO LOGIN, NO NETWORK beyond the loopback CDP port: the
# header is EXECUTED by php in hermetic temp trees with normalised mtimes (the
# partial embeds a filemtime cache-buster, so an un-normalised copy would read
# as a byte leak). Every browser cell asserts liveness first -- a blank page
# satisfies "nothing wrapped" perfectly. Exits 2, never 0, if Chrome is down.
#
# It opens its OWN CDP target and closes it; it never navigates to dev2, never
# touches cookies, and sets the theme as an attribute on its own document, so
# it cannot poison the shared chrome profile.
#
# RED-FIRST: 10 mutations + 2 no-op controls -- tools/gates/header-name-clamp-redfirst.py.
# M10 is labelled SOURCE-ONLY on purpose: the <=820 rule is subsumed by the
# <=1000 one, so no browser cell can see it go.
run "header-name-clamp" python3 "$(dirname "$0")/header-name-clamp-gate.py"
echo

echo "=== GATE 89: comp timers run out, and the already-overdue are HELD ==="
# #183. Ian 8/21: "comp timers need to work." They had not for at least 41 days:
# lg-looth4-expiry enforced looth4 expiry before the cutover and did not survive
# it -- measured both sides 8/21 (no file under live's wp-content, absent from
# active_plugins, recently_activated empty, no cron event in the 13,182-byte
# cron option, no ACF field, no snippet, no option naming the key). Two live
# timers lapsed in July with nothing watching, and nothing could SET one either.
#
# ⚠️ THE TWO HALVES MUST PASS TOGETHER. §E runs a REAL armed sweep over the two
# genuinely overdue accounts (1829 2026-07-28, 1865 2026-07-11) and requires
# their roles byte-identical with no role operation even attempted -- Ian ruled
# they are LEFT ALONE. §F requires a timer at/after the cutover to actually
# demote. Either section alone is satisfied by a sweep broken in one direction.
#
# The timezone is asserted against a HOSTILE process zone: the gate sets PHP's
# default to America/New_York, which is what both boxes run, so a reader that
# dropped its explicit UTC zone reddens §B. The values ARE UTC -- the old
# plugin's own source says so, and both live rows' minute-of-day matches their
# UTC registration, two for two.
#
# §G keeps Arbiter::sync the only writer of wp_capabilities: CompExpiry and the
# admin screen are asserted to contain no add_role/remove_role/set_role at all.
#
# RED-FIRST: 30 mutations + 2 no-op controls, 32/32 -- tools/gates/comp-expiry-redfirst.py.
# Three holes it found in this gate, all closed: the flag-off cases were MASKED
# BY THE CUTOVER (they passed with the flag check deleted); no case reached the
# stripe coexistence guard; and "UTC appears somewhere" passed on the table
# header after the visible field label was removed.
run "comp-expiry" php "$(dirname "$0")/comp-expiry-gate.php"
echo

if [ "$red" -ne 0 ]; then echo "############ GATES RED — do not push ############"; exit 1; fi
if [ "$dead" -ne 0 ]; then
  echo "############ GATES INCOMPLETE — $dead gate(s) COULD NOT RUN ############"
  echo "Nothing red, but $dead gate(s) reached no verdict, so this is NOT green."
  echo "Fix the environment and re-run before treating this as a pass."
  exit 2
fi
echo "############ ALL GATES GREEN ############"
