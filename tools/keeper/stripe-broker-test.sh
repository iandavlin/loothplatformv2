#!/usr/bin/env bash
# stripe-broker-test.sh — the no-leak proof for stripe-broker.php.
#
# THE PROPERTY UNDER TEST is the only reason the broker exists: an operator, or
# a model of any tier, can drive key operations and the key material never
# enters the conversation. So this does the blunt, literal thing — it reads the
# real key values, runs every subcommand, and greps the output for them.
#
# THIS SCRIPT NEVER PRINTS A KEY EITHER. It holds values in shell variables to
# grep for, and prints only PASS/FAIL and masked prefixes.
#
# Exit 0 all green, 1 a leak or a broken guard.

set -uo pipefail
BROKER="$(dirname "$0")/stripe-broker.php"
WP="sudo -u looth-dev -H wp --path=/var/www/dev"
pass=0; fail=0
ok(){ pass=$((pass+1)); echo "  ok   $1"; }
bad(){ fail=$((fail+1)); echo "  FAIL $1"; }

echo "stripe-broker — no-leak proof"

# ---- gather the real secrets to hunt for (never printed) -------------------
SANDBOX=$($WP option get pmpro_sandbox_stripe_connect_secretkey 2>/dev/null | grep -v Warning | tr -d '\n\r ')
LIVE=$($WP option get pmpro_live_stripe_connect_secretkey 2>/dev/null | grep -v Warning | tr -d '\n\r ')
WIRED=$($WP option get lgms_stripe_secret_key 2>/dev/null | grep -v Warning | tr -d '\n\r ')

if [ -z "$SANDBOX" ] && [ -z "$LIVE" ]; then
  echo "CANNOT RUN: no stored keys on this box to test against"; exit 3
fi
echo "  (hunting for ${#SANDBOX}-char sandbox and ${#LIVE}-char live values; neither is printed)"

leak_check() {                       # $1 = label, $2 = captured output
  local label="$1" body="$2" leaked=0
  for secret in "$SANDBOX" "$LIVE" "$WIRED"; do
    [ -n "$secret" ] && [ ${#secret} -ge 12 ] || continue
    case "$body" in *"$secret"*) leaked=1;; esac
  done
  if [ "$leaked" -eq 0 ]; then ok "$label leaks no key"; else bad "$label LEAKED A KEY"; fi
}

# ---- §1 every subcommand, stdout AND stderr -------------------------------
echo
echo "[1] no subcommand emits a key, on either stream"
for sub in list validate status; do
  OUT=$(php "$BROKER" "$sub" --actor=leak-test 2>&1)
  leak_check "$sub" "$OUT"
done

OUT=$(php "$BROKER" wire nosuchcandidate --actor=leak-test 2>&1); leak_check "wire(unknown)" "$OUT"
OUT=$(php "$BROKER" 2>&1);                                        leak_check "no-args usage" "$OUT"
OUT=$(php "$BROKER" wire dce-test --actor=leak-test 2>&1);        leak_check "wire(absent candidate)" "$OUT"

# ---- §2 the live-key refusal ----------------------------------------------
#
# WIRED INTO A SCRATCH OPTION, ALWAYS. Proving this guard means being willing to
# let the bad thing happen if it is broken — and on 2026-08-15, red-firing it
# against the REAL target did exactly that: a live payments key sat in the
# lifecycle config for ~45 seconds. Nothing read it (lifecycle off, frozen on,
# no charge made), but the lesson stands: when the code under test WRITES, the
# test must send the write somewhere disposable.
SCRATCH=lgms_broker_test_scratch
export STRIPE_BROKER_TARGET="$SCRATCH"

echo
echo "[2] a LIVE key is refused before cutover — the guard that matters"
CUT=$($WP option get lgms_stripe_cutover_done 2>/dev/null | grep -v Warning | tr -d '\n\r ')
if [ -n "$CUT" ] && { [ "$CUT" = "1" ] || [ "$CUT" = "true" ]; }; then
  echo "  SKIPPED: cutover marker is set on this box, so live wiring is legitimately allowed"
else
  OUT=$(php "$BROKER" wire live --actor=leak-test 2>&1); RC=$?
  case "$OUT" in *REFUSED*) ok "wiring the LIVE key is refused";; *) bad "wiring the LIVE key was NOT refused";; esac
  [ "$RC" -ne 0 ] && ok "...and it exits non-zero" || bad "...but it exited 0, which reads as success"
  leak_check "wire(live) refusal" "$OUT"
  SCRATCHVAL=$($WP option get "$SCRATCH" 2>/dev/null | grep -v Warning | tr -d '\n\r ')
  [ -z "$SCRATCHVAL" ] && ok "...and nothing was written, not even to the scratch target" \
                       || bad "...but something WAS written despite the refusal"
  REAL=$($WP option get lgms_stripe_secret_key 2>/dev/null | grep -v Warning | tr -d '\n\r ')
  [ "$REAL" = "$WIRED" ] && ok "...and the REAL config is untouched by this test at all" \
                         || bad "...but the REAL config changed — the test is unsafe"
fi
unset STRIPE_BROKER_TARGET
$WP option delete "$SCRATCH" >/dev/null 2>&1

# ---- §3 masking is actually masking ---------------------------------------
echo
echo "[3] what it prints instead"
OUT=$(php "$BROKER" validate --actor=leak-test 2>&1)
case "$OUT" in *'"prefix": "sk_test_"'*) ok "reports the 8-char prefix (the MODE, which is not secret)";;
                                      *) bad "no masked prefix in validate output";; esac
case "$OUT" in *'"sha8"'*) ok "reports a sha8 so two stores can be compared without reading either";;
                        *) bad "no sha8 fingerprint";; esac
case "$OUT" in *'"mode": "live"'*) ok "names a live key as live, so an operator can see the risk";;
                                *) bad "live mode not surfaced";; esac
case "$OUT" in *'"verdict": "ALIVE"'*) ok "says alive/dead, which is the question worth asking";;
                                    *) bad "no alive/dead verdict";; esac

# ---- §4 the audit trail ----------------------------------------------------
echo
echo "[4] every invocation is audited"
A=/home/ubuntu/.stripe-broker-audit.log
if [ -r "$A" ]; then
  N=$(grep -c "actor=leak-test" "$A" 2>/dev/null || echo 0)
  [ "$N" -ge 6 ] && ok "this run left $N audit lines" || bad "only $N audit lines for this run"
  grep -q "REFUSED:live-key-pre-cutover" "$A" 2>/dev/null && ok "the live refusal is in the audit, not just on screen" \
    || echo "  --   (no live refusal audited; skipped above?)"
  leak_check "the audit log itself" "$(cat "$A")"
  PERM=$(stat -c %a "$A"); [ "$PERM" = "600" ] && ok "audit log is 0600" || bad "audit log is $PERM, should be 0600"
else
  bad "no audit log at $A"
fi

echo
echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ] || { echo "RED — the broker is not holding."; exit 1; }
echo "GREEN — no subcommand emits a key, a live key is refused pre-cutover, and every call is audited."
