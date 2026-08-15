#!/usr/bin/env bash
# stripe-rehearsal.sh — the pretend-money walk, end to end, on dev2.
#
# Ian's soft launch, rehearsed with the validated SANDBOX key: a whitelisted
# member joins, pays, gets their membership, and it is taken away again when
# the subscription dies. Real Stripe objects in TEST MODE, real webhook
# signature verification, real Arbiter, real wp_capabilities.
#
# WHAT IS REAL AND WHAT IS STOOD IN FOR — said plainly, because a rehearsal
# that overstates itself is worse than none:
#   REAL  — the Stripe customer, payment method, subscription, invoice and
#           refund (all livemode:false); the event bodies; our signature check;
#           the lifecycle; the Arbiter; the role write.
#   STOOD IN — the browser click-through (Stripe.js needs a browser) and
#           Stripe's own webhook DELIVERY (dev2 sits behind Cloudflare and a
#           dev gate, so Stripe cannot reach it). Both are replaced by taking
#           the REAL event Stripe generated and posting it to our endpoint,
#           signed. The payload is Stripe's, not a fixture.
#
# SAFETY. Every option this touches is snapshotted first and restored by an
# EXIT trap, so a failure mid-walk still puts dev2 back. Written after a
# self-inflicted incident where an ad-hoc mutation left a live key wired for
# ~45 seconds: when a test writes to real state, the restore must be
# structural, not remembered.
#
# Usage:  bash tools/keeper/stripe-rehearsal.sh [--keep]

set -uo pipefail
WP="sudo -u looth-dev -H wp --path=/var/www/dev"
KEEP="${1:-}"
pass=0; fail=0
ok(){ pass=$((pass+1)); echo "  ok   $1"; }
bad(){ fail=$((fail+1)); echo "  FAIL $1"; }
step(){ echo; echo "$1"; }

K=$($WP option get lgms_stripe_secret_key 2>/dev/null | grep -v Warning | tr -d '\n\r ')
case "$K" in
  sk_test_*) ;;
  sk_live_*|rk_live_*) echo "REFUSING: a LIVE key is wired. Rehearsal is sandbox-only."; exit 1;;
  *) echo "CANNOT RUN: no usable Stripe key wired (see tools/keeper/stripe-broker.php)"; exit 3;;
esac

# ---- snapshot every option we will touch ----------------------------------
OPTS=(lgms_stripe_lifecycle lgms_identity_gate lgms_stripe_lifecycle_allowlist
      lgms_stripe_webhook_secret lgms_stripe_price_id lgms_stripe_testgroup_pages)
declare -A BEFORE
for o in "${OPTS[@]}"; do
  v=$($WP option get "$o" 2>/dev/null | grep -v Warning | tr -d '\n\r')
  BEFORE[$o]="$v"
done

restore() {
  echo
  echo "restoring dev2 to the state it was found in…"
  for o in "${OPTS[@]}"; do
    if [ -z "${BEFORE[$o]}" ]; then
      $WP option delete "$o" >/dev/null 2>&1
    else
      printf '%s' "${BEFORE[$o]}" | $WP option update "$o" >/dev/null 2>&1
    fi
  done
  for o in "${OPTS[@]}"; do
    now=$($WP option get "$o" 2>/dev/null | grep -v Warning | tr -d '\n\r')
    [ "$now" = "${BEFORE[$o]}" ] || echo "  !! $o did not restore (was '${BEFORE[$o]}', now '$now')"
  done
  echo "  restored."
}
[ "$KEEP" = "--keep" ] || trap restore EXIT

echo "STRIPE REHEARSAL — the pretend-money walk (sandbox only)"
echo "  key: $(echo "$K" | cut -c1-8)… (test mode)"

# ---- arm ------------------------------------------------------------------
step "[0] arming the soft launch (all of this is put back at the end)"
WHSEC="whsec_rehearsal_$(date +%s)"
printf '%s' "$WHSEC"  | $WP option update lgms_stripe_webhook_secret >/dev/null 2>&1
printf '1'            | $WP option update lgms_stripe_lifecycle      >/dev/null 2>&1
printf '1'            | $WP option update lgms_identity_gate         >/dev/null 2>&1
printf '1'            | $WP option update lgms_stripe_testgroup_pages >/dev/null 2>&1
$WP option update lgms_stripe_lifecycle_allowlist '[2047]' --format=json >/dev/null 2>&1
ok "lifecycle ON, identity gate ON, test group = the probe member only"

echo "$K" > /dev/null   # key never printed beyond its prefix
export REHEARSAL_KEY="$K" REHEARSAL_WHSEC="$WHSEC"
