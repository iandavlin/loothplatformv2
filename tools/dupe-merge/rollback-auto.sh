#!/usr/bin/env bash
# Undo a batch produced by apply-auto.sh, in reverse order.
#
#   sudo tools/dupe-merge/rollback-auto.sh tools/dupe-merge/journal/APPLIED-<stamp>.tsv
#
# Reverse order matters: later merges were planned against the state the earlier
# ones left behind. It keeps going if one pair reports rows already absent —
# that is the tolerant-but-loud rollback path, and it is the right behaviour
# when undoing a batch that stopped part-way — but it stops on a hard error and
# tells you which pairs are still merged.
#
# Never pick a journal with `ls | tail -1`. Journals are named per pair, so that
# sorts alphabetically rather than by time and rolls back the wrong merge.
#
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MANIFEST="${1:?usage: rollback-auto.sh <APPLIED-*.tsv>}"
[[ $EUID -eq 0 ]] || { echo "run with sudo" >&2; exit 2; }
[[ -r "$MANIFEST" ]] || { echo "cannot read $MANIFEST" >&2; exit 2; }

mapfile -t LINES < <(tac "$MANIFEST")
echo "rolling back ${#LINES[@]} pair(s), newest first"

done_n=0
for line in "${LINES[@]}"; do
  name="${line%%$'\t'*}"
  journal="${line#*$'\t'}"
  printf '\n=== %s\n' "$name"
  if [[ ! -r "$journal" ]]; then
    echo "!! journal missing: $journal — cannot reverse this pair. Stopping."
    echo "   $done_n pair(s) rolled back; the rest are still merged."
    exit 1
  fi
  if ! "$HERE/run-as-root.sh" --rollback --journal="$journal" 2>&1 | sed -n '/restored/p;/note:/p;/rolled back/p'; then
    echo "!! ROLLBACK FAILED for '$name'. Stopping."
    echo "   $done_n pair(s) rolled back; the rest are still merged."
    exit 1
  fi
  done_n=$((done_n+1))
done

printf '\n%d/%d pair(s) rolled back.\n' "$done_n" "${#LINES[@]}"
echo "Confirm the box is clean:"
echo "  mysql looth_import -e \"SELECT COUNT(*) FROM wp_users WHERE user_email LIKE 'merged-%@retired.invalid';\""
echo "  mysql looth_import -e \"SELECT COUNT(*) FROM wp_usermeta WHERE meta_key IN ('lg_merged_into','lg_prior_email');\""
echo "  sudo -u postgres psql -d profile_app -tAc \"SELECT count(*) FROM users WHERE primary_email LIKE 'merged-%';\""
