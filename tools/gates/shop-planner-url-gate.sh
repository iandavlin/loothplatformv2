#!/bin/bash
# shop-planner-url-gate.sh — /shop-layout-planner/ must keep serving the planner
# (docs/CRAFT-STANDARD.md: a defect class found twice becomes a gate).
#
# THE DEFECT CLASS THIS EXISTS FOR — "the new page renders, so we shipped it".
#   /shop-layout-planner/ is a LIVE url earning organic Google traffic. The work
#   that motivated this gate replaces its WordPress render with a standalone
#   nginx-served front controller. Every cheap check you would reach for first is
#   blind to the way that breaks:
#     - "does my new PHP file render?"   — yes, at a path nobody visits.
#     - "did the nginx conf pass -t?"    — yes; a MISSING location also passes.
#     - "is the page 200?"               — a stock-theme 200, a redirect chain
#                                          ending 200, and an empty 200 all look
#                                          identical to `curl -w %{http_code}`.
#   So this gate asserts the URL *and its payload* together: the exact path, no
#   redirect, the planner actually mounted, and the indexed copy still present.
#
# THE INVARIANT — true BEFORE the standalone work and AFTER it, deliberately:
#   GET /shop-layout-planner/ (anonymous) returns 200 on that exact path, and the
#   body carries (a) the planner canvas + its engine, and (b) the <h1> copy Google
#   ranks. Both the current WP render and the standalone render satisfy this. A
#   gate written to only pass on the NEW shape could not have caught the old URL
#   breaking — which is the whole failure mode.
#
# THE ABSENCE HALF (LG_SP_EXPECT_STANDALONE=1). Gates here assert what should be
# PRESENT and are structurally blind to what should be ABSENT — the blind spot
# every one of Ian's phone-found misses lived in. Once the standalone render is
# meant to be live, the stock theme must be GONE from the payload; that is only
# checkable as an absence, so it is opt-in rather than silently skipped.
#
# WHY dev2 CANNOT PROVE THE *CURRENT* PAGE (measured 2026-07-31, do not re-derive):
#   dev2 serves every WP-rendered page to an anonymous visitor as a 302 into the
#   BuddyBoss members gate (bp-auth=1&action=bpnoaccess) — /privacy/ and /terms/
#   included, so it is site-wide config, not this page. dev2's rows for 68840 and
#   63845 are byte-for-byte the same as live's. Therefore, pre-standalone, dev2
#   reports CANNOT RUN rather than red: a 302 there is dev2 being dev2, not a
#   regression. AFTER the standalone lands, the page is served by nginx ahead of
#   WordPress, so it becomes anon-reachable on dev2 exactly like /manage-subscription/
#   and /hub/ already are (both measured 200 anon) — and this gate starts biting.
#
# THREE STATES, not two (run-all.sh's rule): 0 = GREEN, 2 = CANNOT RUN, 1 = RED.
# Reporting red for a gate that never executed is indistinguishable from a real
# finding, which is how gate 2 sat dead-looking-red for weeks.
#
# Usage:
#   bash tools/gates/shop-planner-url-gate.sh              # dev2 (LG_GATE_HOST)
#   bash tools/gates/shop-planner-url-gate.sh --live       # live, via ssh live-ro
#   LG_SP_EXPECT_STANDALONE=1 bash tools/gates/shop-planner-url-gate.sh
set -uo pipefail

PATH_UNDER_TEST="/shop-layout-planner/"
EXPECT_STANDALONE="${LG_SP_EXPECT_STANDALONE:-0}"
TARGET="dev2"
[ "${1:-}" = "--live" ] && TARGET="live"

fail()  { echo "SHOP-PLANNER-URL  RED     $1" >&2; exit 1; }
skip()  { echo "SHOP-PLANNER-URL  CANNOT RUN  $1" >&2; exit 2; }
note()  { echo "SHOP-PLANNER-URL  ..       $1"; }

BODY=$(mktemp); CODE=""; LOC=""
trap 'rm -f "$BODY"' EXIT

if [ "$TARGET" = "live" ]; then
    # Live is behind Cloudflare, which challenges non-browser clients into a 403
    # that reads identically to success and to an outage — so never a plain public
    # curl. Resolve to loopback ON the live box instead. Read-only; no live writes.
    command -v ssh >/dev/null 2>&1 || skip "ssh not available for --live"
    OUT=$(ssh -o BatchMode=yes -o ConnectTimeout=10 live-ro \
            "curl -sk --resolve loothgroup.com:443:127.0.0.1 \
                  -o /tmp/sp-gate.html -w '%{http_code} %{redirect_url}' \
                  'https://loothgroup.com${PATH_UNDER_TEST}'; \
             echo; cat /tmp/sp-gate.html; rm -f /tmp/sp-gate.html" 2>/dev/null) \
        || skip "cannot reach live via 'ssh live-ro' (read-only host alias)"
    CODE=$(printf '%s' "$OUT" | head -1 | awk '{print $1}')
    LOC=$(printf  '%s' "$OUT" | head -1 | awk '{print $2}')
    printf '%s' "$OUT" | tail -n +2 > "$BODY"
    WHERE="live (loothgroup.com, via ssh live-ro)"
else
    GE="$(dirname "$0")/gate-env.sh"
    [ -r "$GE" ] || skip "gate-env.sh not readable at $GE"
    # shellcheck source=/dev/null
    source "$GE" || skip "gate-env.sh could not resolve host/token for this box"
    read -r CODE LOC < <(curl -sk $LG_GATE_RESOLVE \
        -b "loothdev_auth=$LG_GATE_TOKEN" \
        -o "$BODY" -w '%{http_code} %{redirect_url}' \
        "${LG_GATE_HOST}${PATH_UNDER_TEST}"; echo)
    WHERE="$LG_GATE_HOST (dev gate cookie: loothdev_auth)"
fi

note "target: $WHERE"
note "GET ${PATH_UNDER_TEST} -> ${CODE}${LOC:+  ->  $LOC}"

# --- pre-standalone dev2: a BuddyBoss bounce is dev2 being dev2, not a regression
if [ "$TARGET" = "dev2" ] && [ "$CODE" = "302" ] && [[ "$LOC" == *"bpnoaccess"* ]]; then
    skip "dev2 bounces EVERY anon WP page into the BuddyBoss members gate
                  (/privacy/ and /terms/ do it too — site-wide, not this page).
                  Nothing to assert until /shop-layout-planner/ is served by nginx
                  ahead of WordPress. Re-run after the standalone location lands;
                  use --live to prove the URL is currently healthy in production."
fi

# --- the URL itself -----------------------------------------------------------
[ "$CODE" = "200" ] || fail "expected 200 on ${PATH_UNDER_TEST}, got ${CODE}${LOC:+ (-> $LOC)}.
                  This URL earns organic traffic; a redirect or 404 here is a
                  member-facing outage, not a refactor detail."

# --- the payload: an empty or wrong 200 must not read as success ---------------
BYTES=$(wc -c < "$BODY")
[ "$BYTES" -gt 2000 ] || fail "200 but only ${BYTES} bytes — that is an empty/placeholder page, not the planner."

need() {
    local marker="$1" why="$2"
    grep -qF -- "$marker" "$BODY" \
        || fail "missing '${marker}' — ${why}"
}

# The planner is injected as a MODAL (lg-apps/apps/shop-planner/app.php:55, echoed
# on wp_footer by lg-apps.php:84); [shop_planner] alone only emits a button. So the
# canvas id is the honest "the app is really on this page" marker — a page that kept
# the button but lost the modal would still look fine to a button-only check.
need 'lgsp-layoutCanvas'            'the planner canvas is not on the page. The app did not mount.'
need 'shop-planner.js'              'the 62KB planner engine is not referenced. The page cannot work.'
need 'Luthier Shop Layout Planner'  'the indexed <h1> copy is gone. This is what Google ranks the URL for.'

# --- the absence half (opt-in; gates are otherwise blind to it) ----------------
if [ "$EXPECT_STANDALONE" = "1" ]; then
    if grep -qF 'twentytwentyfive' "$BODY"; then
        fail "the stock WordPress theme is STILL in the payload, so this is not a
                  standalone render — nginx is not routing ${PATH_UNDER_TEST} to the
                  front controller (a missing location passes 'nginx -t' silently),
                  or the worker did not reload. Check the WORKER start time, not the
                  master's:  ps -eo lstart,cmd | grep '[n]ginx: worker'"
    fi
    note "absence check: stock theme is gone (standalone render confirmed)"
else
    grep -qF 'twentytwentyfive' "$BODY" \
        && note "note: still the WordPress theme render (expected pre-standalone).
                  Set LG_SP_EXPECT_STANDALONE=1 once the standalone page is meant to be live."
fi

echo "SHOP-PLANNER-URL  GREEN   ${PATH_UNDER_TEST} serves the planner (${BYTES} bytes, 200, no redirect)"
exit 0
