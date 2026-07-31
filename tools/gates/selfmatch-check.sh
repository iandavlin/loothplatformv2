#!/usr/bin/env bash
# selfmatch-check.sh — flag probes that can match their own command line.
#
# Encodes the "probes that answer about themselves" class from
# docs/CRAFT-STANDARD.md. Twice now a check has read evidence it created itself:
#   pgrep -af "chrome.*host-resolver-rules"  -> matched its OWN argv, reported
#                                               a resolver that was not there
#   msg inbox | grep "seat is yours"         -> matched MY OWN message asking
#                                               for the seat
# Neither failed. Both returned confident, specific, wrong answers.
#
# WHAT IT FLAGS: `pgrep -f` / `pgrep -af` (matches the full command line,
# including its own) where `pgrep -x` (name only) is almost always meant.
#
# NOT WIRED INTO run-all.sh ON PURPOSE. Gate numbering collides when two lanes
# mint the same number off their own branches (it happened: two lanes both
# minted "9/9"). Numbering is done from MAIN after a rebase, so keeper folds
# this in rather than this branch guessing a free slot.
#
#   tools/gates/selfmatch-check.sh [path...]
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
paths=("${@:-$ROOT/tools $ROOT/docs}")

hits=0
while IFS= read -r line; do
  # skip this file and anything explicitly annotated as deliberate
  case "$line" in
    *selfmatch-check.sh*)      continue ;;
    *"selfmatch-ok"*)          continue ;;
  esac
  echo "  $line"
  hits=$((hits + 1))
# Code only. docs/CRAFT-STANDARD.md DESCRIBES this trap; a checker that flags
# the documentation of the thing it checks for is its own kind of self-match.
done < <(grep -rnE --include=*.sh --include=*.py --include=*.js --include=*.mjs \
         "pgrep +(-[a-z]*f[a-z]* )" ${paths[@]} 2>/dev/null \
         | grep -v "pgrep -x")

if [ "$hits" -gt 0 ]; then
  echo
  echo "FAIL: $hits use(s) of 'pgrep -f', which matches the probe's OWN command line."
  echo "      Use 'pgrep -x <name>' (name only), or read /proc/<pid>/cmdline."
  echo "      If a full-command match is genuinely required, append the comment"
  echo "      'selfmatch-ok' on that line to record that it was considered."
  exit 1
fi

echo "PASS: no self-matching process probes found."
