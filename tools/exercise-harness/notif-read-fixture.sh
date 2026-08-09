#!/bin/bash
# fixture.sh — the 12-notification member for the recap-read-timer controls.
# wp:1912 = claude-admin-qa = uuid 4e9620c9-42eb-59ca-b350-85dceca5e801
# Snapshots the member's REAL rows first so the store is restored exactly as found.
set -uo pipefail
U=4e9620c9-42eb-59ca-b350-85dceca5e801
A=4d8bc072-b862-53c8-8087-33912a0dedf9      # actor: gerryhayes
SNAP=/tmp/rrt-exercise/snapshot.csv
Q() { sudo -n -u postgres psql -d profile_app -Atc "$1"; }

case "${1:-}" in
snapshot)
  sudo -n -u postgres psql -d profile_app -Atc "COPY (SELECT id,user_uuid,actor_uuid,type,thread_id,connection_id,is_read,created_at,read_at,target_kind,target_id,anchor_id,target_url,actor_count FROM notifications WHERE user_uuid='$U' ORDER BY id) TO STDOUT WITH CSV" > "$SNAP"
  echo "snapshot: $(wc -l < "$SNAP") real rows -> $SNAP" ;;
seed)
  Q "DELETE FROM notifications WHERE user_uuid='$U';" >/dev/null
  Q "INSERT INTO notifications (user_uuid,actor_uuid,type,is_read,created_at,target_kind,target_id,target_url,actor_count)
     SELECT '$U','$A','forum.reply_to_topic',false, now() - (g || ' hours')::interval,
            'topic', 900000+g, '/hub/?topic=' || (900000+g), 1
       FROM generate_series(1,12) g;" >/dev/null
  echo "seeded: $(Q "SELECT count(*) FROM notifications WHERE user_uuid='$U';") rows, $(Q "SELECT count(*) FROM notifications WHERE user_uuid='$U' AND NOT is_read;") unread" ;;
reset)   # back to all-unread without changing ids
  Q "UPDATE notifications SET is_read=false, read_at=NULL WHERE user_uuid='$U';" >/dev/null
  echo "reset: $(Q "SELECT count(*) FROM notifications WHERE user_uuid='$U' AND NOT is_read;") unread" ;;
state)
  echo "id|target_id|is_read"
  Q "SELECT id||'|'||target_id||'|'||is_read FROM notifications WHERE user_uuid='$U' ORDER BY id;" ;;
counts)
  Q "SELECT count(*) FILTER (WHERE NOT is_read) || ' unread / ' || count(*) || ' total' FROM notifications WHERE user_uuid='$U';" ;;
ids)
  Q "SELECT string_agg(id::text, ',' ORDER BY created_at DESC) FROM notifications WHERE user_uuid='$U';" ;;
restore)
  Q "DELETE FROM notifications WHERE user_uuid='$U';" >/dev/null
  if [ -s "$SNAP" ]; then
    sudo -n -u postgres psql -d profile_app -c "\\copy notifications (id,user_uuid,actor_uuid,type,thread_id,connection_id,is_read,created_at,read_at,target_kind,target_id,anchor_id,target_url,actor_count) FROM '$SNAP' WITH CSV" >/dev/null
  fi
  echo "restored: $(Q "SELECT count(*) FROM notifications WHERE user_uuid='$U';") rows" ;;
*) echo "usage: fixture.sh snapshot|seed|reset|state|counts|ids|restore"; exit 64 ;;
esac
