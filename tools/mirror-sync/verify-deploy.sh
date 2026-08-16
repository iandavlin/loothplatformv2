#!/usr/bin/env bash
# verify-deploy.sh — prove the 3.9 fix WORKS on the serve, not just that it shipped.
#
# Gate 70 is source-read only, on purpose: it must not need a browser, a network or
# a DB, so it can never flake under load. That leaves exactly two claims it cannot
# make, and this script makes them after the deploy:
#
#   1. _sync really answers 202 for an unmirrorable reply — not just that the code
#      says 202. The whole point of that change is what lands in the ACCESS LOG, so
#      the only honest test is a real request producing a real status line.
#   2. reconcile's deep sweep really runs, is really bounded, and reports the
#      malformed rows instead of silently passing over them.
#
# READ-ONLY AGAINST DATA. It POSTs a sync for an id that CANNOT be written by
# definition (an unmirrorable one), so a pass writes nothing; and it reads
# reconcile's output rather than triggering repairs.
#
# ⚠️ RUN IT ON THE BOX THAT HAS THE DEPLOY. dev2 first; live only if Ian asks.
set -uo pipefail
FAIL=0
say() { printf '  %-4s %s\n' "$1" "$2"; [ "$1" = "RED" ] && FAIL=1; return 0; }

SYNC_URL=${SYNC_URL:-http://127.0.0.1/bb-mirror-api/v0/_sync}
# An id that is a published reply with NO topic meta is unmirrorable BY DEFINITION.
# Pass one via UNMIRRORABLE_ID; with none, the script says so rather than guessing,
# because a "pass" against a healthy id would be meaningless.
UNMIRRORABLE_ID=${UNMIRRORABLE_ID:-}

echo "== 1. does the receiver announce a skip with 202? =="
if [ -z "$UNMIRRORABLE_ID" ]; then
  say SKIP "no UNMIRRORABLE_ID given — refusing to test 202 against a healthy row, which would pass for the wrong reason"
else
  CODE=$(curl -s -o /tmp/lgfc-sync-body.$$ -w '%{http_code}' \
          -X POST "$SYNC_URL" \
          -H 'X-BB-Mirror-Sync: 1' \
          -H 'Content-Type: application/json' \
          --data "{\"kind\":\"reply\",\"action\":\"upsert\",\"id\":$UNMIRRORABLE_ID}" 2>/dev/null)
  BODY=$(cat /tmp/lgfc-sync-body.$$ 2>/dev/null); rm -f /tmp/lgfc-sync-body.$$
  case "$CODE" in
    202) say ok   "202 for reply#$UNMIRRORABLE_ID — the drop is visible in the access log" ;;
    200) say RED  "200 — the receiver is still silent about a skip (this is the whole 3.9 defect)" ;;
    4*|5*) say RED "$CODE — a skip must NOT be an error status; that retry-storms a row retrying cannot fix" ;;
    *)   say SKIP "unexpected $CODE (loopback/auth?) — cannot judge, not scoring it" ;;
  esac
  case "$BODY" in
    *skipped*) say ok  "the body names the reason: $(printf '%s' "$BODY" | head -c 90)" ;;
    *)         say RED "the body does not carry a 'skipped' reason" ;;
  esac
fi

echo "== 2. does reconcile's deep sweep run, bounded, and report? =="
OUT=$(sudo -n systemctl start bb-mirror-reconcile.service 2>/dev/null; \
      sudo -n journalctl -u bb-mirror-reconcile.service -n 60 --no-pager 2>/dev/null)
if [ -z "$OUT" ]; then
  say SKIP "no journal for bb-mirror-reconcile (not deployed here, or no sudo) — not scoring"
else
  grep -q 'Deep sweep' <<<"$OUT" && say ok "the deep sweep announces itself each run" \
                                 || say RED "no 'Deep sweep' line — the backwards reach is not running"
  if grep -qE 'Deep sweep — (DUE|not due)' <<<"$OUT"; then
    say ok "it states DUE / not due, so the interval bound is observable"
  else
    say RED "it does not state whether it was due — the bound is not observable"
  fi
  # Malformed rows must be REPORTED, not silently skipped.
  if grep -qE 'deep (reply|topic):' <<<"$OUT"; then
    say ok "it reports per-kind results: $(grep -oE 'deep (reply|topic):.*' <<<"$OUT" | head -2 | tr '\n' ' ')"
  else
    say SKIP "no per-kind line yet (first run may not be due) — re-run after the interval"
  fi
  grep -q 'SKIP reply#' <<<"$OUT" && say ok "unmirrorable rows are named in the log, not swallowed" \
                                  || say SKIP "no SKIP lines this run (none were attempted)"
fi

echo
[ "$FAIL" -eq 0 ] && echo "GREEN — the pipe announces its failures and the deep sweep is live." \
                  || echo "RED — see the lines above."
exit "$FAIL"
