#!/usr/bin/env bash
# export-for-slug-dryrun.sh — READ-ONLY export that feeds backfill-slugs.php --from-tsv.
#
# WHY: the box that must be reported on (LIVE) and the box that can run the derivation
# are not always the same box. Rather than reimplement the cleaning rules in SQL over
# there — a second implementation is exactly the split-brain this lane keeps closing —
# export the raw rows and run the ONE deriver against them.
#
# This script SELECTs and nothing else. It cannot write. Run it on live as looth-ro:
#
#   bash export-for-slug-dryrun.sh /tmp/live
#   # then copy /tmp/live-members.tsv + /tmp/live-owners.tsv to the box with the code:
#   sudo -u profile-app php backfill-slugs.php --scope=repair \
#        --from-tsv=/tmp/live-members.tsv --owners-tsv=/tmp/live-owners.tsv \
#        --html=/tmp/live-dryrun.html --tsv=/tmp/live-dryrun.tsv
#
# TRAP (OPERATOR.md): on the live box `looth_dev` is a DECOY — it is dev.loothgroup.com's
# own frozen database. The live WP DB is `looth_import`; `siteurl` is the fastest tell.
# This script only touches Postgres, but the check is printed so the operator can confirm
# they are on the box they think they are on before trusting anything downstream.

set -euo pipefail
OUT="${1:-/tmp/live}"
PSQL=(psql -h 127.0.0.1 -U looth_ro -d profile_app -At -F$'\t')

echo "== identity check ==" >&2
"${PSQL[@]}" -c "SELECT 'profile_app users: ' || count(*) FROM users" >&2
"${PSQL[@]}" -c "SELECT 'patreon slugs: ' || count(*) FROM users WHERE slug ~* '^patreon[_-]?[0-9]+\$'" >&2
if command -v mysql >/dev/null && [ -r /home/looth-ro/.my.cnf ]; then
  echo -n "wp siteurl (must be the LIVE host, not dev): " >&2
  mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import \
    -e "SELECT option_value FROM wp_options WHERE option_name='siteurl'" >&2 || true
fi

# Candidates: real members only — bridged (can log in) and not archived.
"${PSQL[@]}" -c "COPY (
  SELECT 'id','display_name','slug','primary_email','slug_changed_at','wp_user_id','claimed_via'
  UNION ALL
  SELECT u.id::text, u.display_name, COALESCE(u.slug,''), u.primary_email,
         COALESCE(u.slug_changed_at::text,''), b.wp_user_id::text, COALESCE(p.claimed_via,'')
  FROM users u
  JOIN wp_user_bridge b ON b.user_id = u.id
  LEFT JOIN profiles p  ON p.user_id = u.id
  WHERE u.archived_at IS NULL
) TO STDOUT WITH (FORMAT csv, DELIMITER E'\t')" > "${OUT}-members.tsv"

# Ownership: EVERY held handle, live or retired, including archived and unbridged rows.
# A ghost that cannot log in still squats on its handle; leaving it out would mint
# duplicates. slug_history is included because a retired handle is never re-issued.
#
# But the reader role may not be able to SELECT it. On LIVE 2026-07-27, `looth_ro`
# held per-table grants made BEFORE slug_history existed (added 2026-07-12), so this
# COPY died with "permission denied for table slug_history" — and under `set -e` that
# aborted the run after members.tsv was already written, leaving a 0-byte owners file
# that looks like "no handles are held". A half-written export that reads as complete
# is worse than one that stops, so probe first and degrade OUT LOUD: the gap goes in
# the FILENAME, so it travels with the artifact into whatever report is built from it.
if "${PSQL[@]}" -c "SELECT 1 FROM slug_history LIMIT 1" >/dev/null 2>&1; then
  HIST_SQL="UNION ALL SELECT user_id::text, lower(slug) FROM slug_history"
  OWNERS_OUT="${OUT}-owners.tsv"
else
  HIST_SQL=""
  OWNERS_OUT="${OUT}-owners-NO-slug_history.tsv"
  cat >&2 <<'WARN'

  !! slug_history is NOT READABLE by this role — RETIRED handles are MISSING from the
     owners export. The collision check downstream cannot see a handle that was already
     retired, so it may propose one. Do NOT --apply from a report built on this file
     until the gap is closed or proven empty. One SELECT-only grant fixes it:

         GRANT SELECT ON TABLE public.slug_history TO looth_ro;   -- run on LIVE

WARN
fi
"${PSQL[@]}" -c "COPY (
  SELECT 'owner_id','slug'
  UNION ALL
  SELECT id::text, lower(slug) FROM users WHERE slug IS NOT NULL AND slug <> ''
  $HIST_SQL
) TO STDOUT WITH (FORMAT csv, DELIMITER E'\t')" > "$OWNERS_OUT"

echo "wrote ${OUT}-members.tsv ($(($(wc -l < "${OUT}-members.tsv")-1)) members)" >&2
echo "wrote $OWNERS_OUT  ($(($(wc -l < "$OWNERS_OUT")-1)) held handles)" >&2
[ -n "$HIST_SQL" ] || echo "  ^ RETIRED handles are NOT in that file — see the warning above." >&2
echo "NO WRITES PERFORMED." >&2
