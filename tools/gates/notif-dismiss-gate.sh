#!/bin/bash
# notif-dismiss-gate.sh — the bell's delete/dismiss contract, and its OFF state.
#
#     bash tools/gates/notif-dismiss-gate.sh
#
# notif-bridge lane, 2026-08-08. A thin runner over the two red-first proofs, because
# both must run as a DIFFERENT OS USER than the suite (peer auth: profile_app is owned
# by `profile-app`, WP by `looth-dev`) and neither is reachable as `ubuntu`.
#
# ── WHAT IT DEFENDS, AND WHY IT READS THE FLAG RATHER THAN A FIXED STATE ────
# feedback-gate-reads-the-flag-not-a-hardcoded-state: a gate that hardcodes "dismiss
# is off" turns red the day Ian turns it on and blocks every lane. So the proofs
# assert PER STATE — they arm each flag themselves, in-process, and check the
# behaviour that state owes. This runner only decides whether they could run at all.
#
# Exit 0 = green, 1 = RED, 2 = CANNOT RUN (the suite's three-state convention).
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
rc=0

run_as() {  # run_as <user> <label> <script...>
  local user="$1" label="$2"; shift 2
  if ! sudo -n -u "$user" true 2>/dev/null; then
    echo "  CANNOT RUN: no passwordless sudo to $user for $label"
    return 2
  fi
  local out
  out="$(sudo -n -u "$user" php "$@" 2>&1)"
  local code=$?
  # Surface the verdict lines and anything red; the full transcript is noise here.
  echo "$out" | grep -E '^(  RED|GREEN|RED|CANNOT RUN|victim |fixture )' | sed 's/^/  /'
  return $code
}

echo "--- delete = dismiss (profile_app) ---"
run_as profile-app "the dismiss proof" "$ROOT/profile-app/bin/notif-dismiss-proof.php"
case $? in
  0) ;;
  2) dead=1 ;;
  *) rc=1 ;;
esac

echo "--- leg 4 follow stores (WP) ---"
run_as looth-dev "the followers proof" "$ROOT/lg-shared/bin/notif-followers-proof.php"
case $? in
  0) ;;
  2) dead=1 ;;
  *) rc=1 ;;
esac

# A gate that could not run must say so with exit 2, never exit 1 — reporting a
# missing environment as a finding is what once blocked every lane on this box
# (trap-gate-exit-code-3-blocks-every-lane).
if [ $rc -eq 0 ] && [ "${dead:-0}" = "1" ]; then
  echo "NO VERDICT"
  exit 2
fi

[ $rc -eq 0 ] && echo "GREEN" || echo "RED"
exit $rc
