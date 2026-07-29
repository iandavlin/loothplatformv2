#!/usr/bin/env bash
# Apply the auto-mergeable pairs, one at a time, verifying each before moving on.
#
# Stops at the FIRST failure and leaves everything already done in place, because
# the manifest it writes is what makes those merges reversible. It never touches
# a HELD pair: the selection comes from --auto, and merge-dupes.php refuses a
# held pair anyway, so a hold cannot be reached even by mistake.
#
#   sudo tools/dupe-merge/apply-auto.sh --dry-run    # rehearse, writes nothing
#   sudo tools/dupe-merge/apply-auto.sh              # apply for real
#
# Every applied pair appends "<pair>\t<journal>" to the manifest:
#   tools/dupe-merge/journal/APPLIED-<timestamp>.tsv
# Hand that file to rollback-auto.sh to undo the whole batch in reverse order.
#
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ $EUID -eq 0 ]] || { echo "run with sudo" >&2; exit 2; }

DRY=0
[[ "${1:-}" == "--dry-run" ]] && DRY=1

mapfile -t PAIRS < <("$HERE/run-as-root.sh" --dry-run --auto --list 2>/dev/null)
if [[ ${#PAIRS[@]} -eq 0 ]]; then echo "no auto pairs selected — refusing" >&2; exit 2; fi
echo "auto pairs selected: ${#PAIRS[@]}"

if [[ $DRY -eq 1 ]]; then
  echo "--- rehearsal only, nothing will be written ---"
  "$HERE/run-as-root.sh" --dry-run --auto
  exit 0
fi

STAMP="$(date -u +%Y%m%d-%H%M%S)"
JDIR="$HERE/journal"
mkdir -p "$JDIR"; chown postgres "$JDIR" 2>/dev/null || true
MANIFEST="$JDIR/APPLIED-$STAMP.tsv"
: > "$MANIFEST"; chmod 640 "$MANIFEST"

echo "manifest: $MANIFEST"
ok=0
for name in "${PAIRS[@]}"; do
  printf '\n=== %s\n' "$name"
  out="$("$HERE/run-as-root.sh" --apply --pair="$name" 2>&1)"
  status=$?
  echo "$out" | sed -n '/journal:/p;/looth_import:/p;/profile_app:/p;/looth mirror:/p'
  if [[ $status -ne 0 ]]; then
    echo "$out" | tail -20
    echo "!! APPLY FAILED for '$name' (exit $status). Stopping."
    echo "   $ok pair(s) already applied and listed in $MANIFEST — they are still reversible."
    exit 1
  fi
  journal="$(sed -n 's/^journal: //p' <<<"$out" | head -1)"
  if [[ -z "$journal" ]]; then
    echo "!! applied but no journal path was printed for '$name'. Stopping — that merge is not reversible."
    exit 1
  fi
  printf '%s\t%s\n' "$name" "$journal" >> "$MANIFEST"

  # verify immediately: a merge that did not fully land must not be followed by another
  vout="$("$HERE/run-as-root.sh" --verify --pair="$name" 2>&1)"
  if ! grep -q 'VERIFY OK' <<<"$vout"; then
    echo "$vout" | tail -15
    echo "!! VERIFY FAILED for '$name'. Stopping. Roll back with:"
    echo "   sudo $HERE/rollback-auto.sh $MANIFEST"
    exit 1
  fi
  echo "  verify: OK"
  ok=$((ok+1))
done

printf '\n%d/%d pairs applied and verified.\nmanifest: %s\nrollback the whole batch: sudo %s/rollback-auto.sh %s\n' \
  "$ok" "${#PAIRS[@]}" "$MANIFEST" "$HERE" "$MANIFEST"
