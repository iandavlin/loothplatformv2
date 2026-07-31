#!/bin/bash
# weekly-signup-url-gate.sh — /weekly-email-sign-up/ must keep serving the SIGNUP PAGE
# on that EXACT path (docs/CRAFT-STANDARD.md; shape copied from shop-planner-url-gate.sh).
#
# THE REQUIREMENT THIS ENCODES — Ian, 2026-07-31: "I need the url to remain the same."
# That URL is in sent emails and in members' saved links, and a broken signup link is a
# lost member who never tells you. So the invariant is the PATH plus its PAYLOAD:
#
#   GET /weekly-email-sign-up/  ->  200 on that exact path, NO redirect, and the body
#   carries our signup form (#lgws-form, the email field and the honeypot).
#
# TRUE BEFORE THE STANDALONE WORK AND AFTER IT, deliberately. A gate written to pass
# only on the new shape could not catch the old address breaking, which is the whole
# failure it exists for. Every cheap check is blind to how this breaks:
#   "does my new PHP render?"  — yes, at a path nobody visits.
#   "did nginx -t pass?"       — yes; A MISSING location passes -t just as happily.
#   "is it 200?"               — a themed 200, a redirect chain ending 200 and an
#                                empty 200 are identical to curl -w %{http_code}.
#
# THE ABSENCE HALF (LG_WS_EXPECT_STANDALONE=1). Gates assert what should be PRESENT and
# are blind to what should be ABSENT — the blind spot every one of Ian's phone-found
# misses lived in. Once standalone is meant to be live, the WordPress theme must be GONE
# from the payload. Only checkable as an absence, so it is opt-in rather than silently
# skipped.
#
# ⚠️ LIVE IS ALREADY BROKEN FOR ANONYMOUS VISITORS, measured 2026-07-31 — do not
# re-derive: live returns 302 -> wp-login.php?...bp-auth=1&action=bpnoaccess, because
# /weekly-email-sign-up/ was never added to bp-enable-private-network-public-content.
# So on live this gate reports CANNOT RUN before the conversion (a 302 there is a
# known pre-existing defect, not a regression I caused) and starts biting after —
# because nginx will answer ahead of WordPress and BuddyBoss never sees the request.
#
# THREE STATES (run-all.sh's rule): 0 = GREEN, 1 = RED, 2 = CANNOT RUN. Reporting red
# for a gate that never executed is indistinguishable from a real finding.
#
# Usage:
#   bash tools/gates/weekly-signup-url-gate.sh                    # dev2
#   bash tools/gates/weekly-signup-url-gate.sh --live             # live, via ssh live-ro
#   LG_WS_EXPECT_STANDALONE=1 bash tools/gates/weekly-signup-url-gate.sh
set -uo pipefail

PATH_UNDER_TEST="/weekly-email-sign-up/"
EXPECT_STANDALONE="${LG_WS_EXPECT_STANDALONE:-0}"
TARGET="dev2"
[ "${1:-}" = "--live" ] && TARGET="live"

fail() { echo "WEEKLY-SIGNUP-URL  RED         $1" >&2; exit 1; }
skip() { echo "WEEKLY-SIGNUP-URL  CANNOT RUN  $1" >&2; exit 2; }
note() { echo "WEEKLY-SIGNUP-URL  ..          $1"; }

BODY="$(mktemp)"; trap 'rm -f "$BODY"' EXIT

if [ "$TARGET" = "live" ]; then
    # Loopback on the live box presents a dev2 cert, so -k is required or curl
    # returns a bare 000 that reads as an outage. Never a plain public curl:
    # Cloudflare bot-challenges it into a 403 that also reads as an outage.
    OUT=$(timeout 60 ssh live-ro "curl -sk -o /tmp/wsg.$$ -w '%{http_code} %{redirect_url}' \
          --resolve loothgroup.com:443:127.0.0.1 'https://loothgroup.com$PATH_UNDER_TEST'; \
          echo; cat /tmp/wsg.$$; rm -f /tmp/wsg.$$" 2>/dev/null) \
        || skip "ssh live-ro failed — no verdict"
    CODE=$(printf '%s' "$OUT" | head -1 | awk '{print $1}')
    LOC=$(printf '%s'  "$OUT" | head -1 | awk '{print $2}')
    printf '%s' "$OUT" | tail -n +2 > "$BODY"
else
    ENVSH="$(dirname "$0")/gate-env.sh"
    [ -r "$ENVSH" ] || skip "gate-env.sh missing"
    eval "$(bash "$ENVSH" | sed 's/^\([A-Z_]*\)=\(.*\)$/export \1="\2"/')" \
        || skip "gate-env.sh could not resolve the box"
    W=$(curl -sk -o "$BODY" -w '%{http_code} %{redirect_url}' \
        --resolve "${LG_GATE_DOMAIN}:443:${LG_GATE_ADDR}" \
        -H "Cookie: loothdev_auth=${LG_GATE_TOKEN}" \
        "${LG_GATE_HOST}${PATH_UNDER_TEST}") || skip "curl failed against dev2"
    CODE=$(echo "$W" | awk '{print $1}'); LOC=$(echo "$W" | awk '{print $2}')
fi

[ -n "$CODE" ] || skip "no HTTP status came back — nothing was audited"

# The known pre-existing live defect is a CANNOT RUN, not a red: it is not caused by
# this work and reporting it as a regression would bury a real one later.
if [ "$TARGET" = "live" ] && [ "$CODE" = "302" ] && printf '%s' "$LOC" | grep -q "bpnoaccess"; then
    skip "live 302s anon into the BuddyBoss members gate (pre-existing: the slug is not in
             bp-enable-private-network-public-content). Not a regression — no verdict on the payload."
fi

# ── THE INVARIANT ────────────────────────────────────────────────────────────
[ "$CODE" = "200" ] || fail "expected 200 on $PATH_UNDER_TEST, got $CODE${LOC:+ -> $LOC}"
[ -z "$LOC" ]       || fail "$PATH_UNDER_TEST redirected to $LOC. Ian: the url must remain the same."

BYTES=$(wc -c < "$BODY")
[ "$BYTES" -gt 2000 ] || fail "body is only ${BYTES}B — a 200 that carries nothing"

for marker in 'id="lgws-form"' 'id="lgws-email"' 'id="lgws-website"'; do
    grep -q -- "$marker" "$BODY" || fail "the signup form is missing from the payload ($marker). A 200 is not a page."
done
note "200 on the exact path, no redirect, signup form present (${BYTES}B)"

# ── THE ABSENCE HALF ─────────────────────────────────────────────────────────
THEMES=$(grep -o 'wp-content/themes/' "$BODY" | wc -l)
INCS=$(grep -o 'wp-includes/'        "$BODY" | wc -l)
if [ "$EXPECT_STANDALONE" = "1" ]; then
    [ "$THEMES" -eq 0 ] || fail "STILL THEMED: $THEMES wp-content/themes refs. Ian asked for standalone a hundred times."
    [ "$INCS"   -eq 0 ] || fail "STILL WORDPRESS-CHROMED: $INCS wp-includes refs in the payload."
    note "absence half: 0 theme refs, 0 wp-includes refs — standalone"
else
    note "absence half NOT asserted (LG_WS_EXPECT_STANDALONE unset); currently themes=$THEMES wp-includes=$INCS"
fi

echo "WEEKLY-SIGNUP-URL  GREEN       $TARGET $PATH_UNDER_TEST"
exit 0
