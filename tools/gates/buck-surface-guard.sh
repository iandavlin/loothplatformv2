#!/usr/bin/env bash
# THE FENCE (Ian, 2026-08-11): our work must never modify Buck's files.
# Buck's surfaces are hands-off — not ours to fix or report — UNLESS one is
# leaking data or eating resources, which is a talk-to-Ian-first situation.
#
# This guard fails if the changes about to land touch any of Buck's files.
# It runs in the standard gate suite (against a lane's branch) and should also
# be run by keeper before any merge.
#
# Usage: buck-surface-guard.sh [git-diff-range]   (default: origin/main...HEAD)
set -uo pipefail

RANGE="${1:-origin/main...HEAD}"

# Buck names all his files with "buck" in the path. Exclude this guard and its
# companion sign so they don't flag themselves.
hits=$(git diff --name-only "$RANGE" 2>/dev/null \
  | grep -i 'buck' \
  | grep -v 'tools/gates/buck-surface-guard.sh' \
  | grep -v 'platform/nginx/OWNERSHIP-NOTE.md' \
  | grep -v 'docs/BUCK-FENCE.md' || true)

if [ -n "$hits" ]; then
  echo "BLOCKED — this work changes files that belong to Buck. Leave them alone:"
  echo "$hits" | sed 's/^/  - /'
  echo
  echo "If one of these genuinely must change (only because it leaks data or eats"
  echo "resources), stop and raise it with Ian first — do not edit it in a lane."
  exit 1
fi

echo "Buck-surface guard: clean (nothing of Buck's is touched)."
exit 0
