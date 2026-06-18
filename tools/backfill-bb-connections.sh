#!/bin/bash
# Backfill BuddyBoss friendships -> profile-app connections (idempotent).
# Run on dev 2026-06-11 (10,377 imported); RE-RUN AT CUTOVER against live data —
# rows skipped today (~1,018) are users not yet in wp_user_bridge (unprovisioned),
# self-pairs, and reverse-duplicate pairs; the ON CONFLICT guard makes re-runs safe.
# Literal snapshot per the migration rule: confirmed->accepted, else pending;
# initiator = requester; original date_created preserved.
set -euo pipefail
WP_PATH="${WP_PATH:-/var/www/dev}"
CSV=$(mktemp)
sudo -u www-data wp --path="$WP_PATH" db query \
  "SELECT initiator_user_id, friend_user_id, is_confirmed, date_created FROM wp_bp_friends" \
  --skip-column-names | tr '\t' ',' | sed '/^$/d' > "$CSV"
echo "source rows: $(wc -l < "$CSV")"
sudo -u postgres psql -d profile_app <<SQL
BEGIN;
CREATE TEMP TABLE bb_friends_import (initiator_wp int, friend_wp int, confirmed int, created timestamptz);
\copy bb_friends_import FROM '$CSV' WITH (FORMAT csv)
INSERT INTO connections (requester_uuid, addressee_uuid, status, created_at, updated_at)
SELECT ur.uuid, ua.uuid,
       CASE WHEN f.confirmed = 1 THEN 'accepted' ELSE 'pending' END,
       f.created, f.created
  FROM bb_friends_import f
  JOIN wp_user_bridge br ON br.wp_user_id = f.initiator_wp
  JOIN users ur          ON ur.id = br.user_id
  JOIN wp_user_bridge ba ON ba.wp_user_id = f.friend_wp
  JOIN users ua          ON ua.id = ba.user_id
 WHERE ur.uuid <> ua.uuid
ON CONFLICT (requester_uuid, addressee_uuid) DO NOTHING;
COMMIT;
SQL
rm -f "$CSV"
sudo -u postgres psql -d profile_app -At -c "SELECT status, count(*) FROM connections GROUP BY status ORDER BY status;"
