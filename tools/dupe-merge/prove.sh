#!/usr/bin/env bash
# End-to-end proof for one pair: fingerprint -> merge -> verify -> rollback ->
# fingerprint again, and fail unless the restored fingerprint is byte-identical
# to the original.
#
# The fingerprint is every row in every table the merge can touch that belongs
# to either account, sorted and hashed — not a row count, because a count can
# match while the contents are wrong.
#
#   sudo tools/dupe-merge/prove.sh "inaki font"
#
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PAIR="${1:?usage: prove.sh <pair-name>}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

[[ $EUID -eq 0 ]] || { echo "run with sudo" >&2; exit 2; }

read -r SURV TWIN SUUID TUUID SPG TPG < <(
  python3 - "$HERE/pairs.json" "$PAIR" <<'PY'
import json,sys
ps=json.load(open(sys.argv[1]))
m=[p for p in ps if sys.argv[2].lower() in p['name'].lower()]
if len(m)!=1: sys.exit(f"need exactly one pair, matched {len(m)}")
p=m[0]
print(p['survivor'],p['twin'],p['survivor_uuid'],p['twin_uuid'],p['survivor_pg'],p['twin_pg'])
PY
)
echo "pair='$PAIR'  survivor=$SURV  twin=$TWIN"

WP_PATH="$(sed -n 's/^LG_WP_PATH=//p' /etc/looth/env)"; WP_PATH="${WP_PATH:-/var/www/dev}"
grab() { sed -n "s/.*define(\s*['\"]$1['\"]\s*,\s*['\"]\(.*\)['\"]\s*).*/\1/p" "$WP_PATH/wp-config.php" | head -1; }
MYU="$(grab DB_USER)"; MYP="$(grab DB_PASSWORD)"; MYN="$(grab DB_NAME)"; MYH="$(grab DB_HOST)"

my() { mysql -h"$MYH" -u"$MYU" -p"$MYP" -N -B "$MYN" -e "$1"; }
pg() { sudo -u postgres psql -d profile_app -tAc "$1"; }
mir() { sudo -u postgres psql -d looth -tAc "$1"; }

fingerprint() {
  local out="$1"
  : > "$out"
  # every wp table the merge remaps, full row contents for both accounts
  for spec in \
    "wp_posts:post_author:ID" "wp_comments:user_id:comment_ID" "wp_bp_activity:user_id:id" \
    "wp_bb_notifications_subscriptions:user_id:id" "wp_bb_topic_relationships:user_id:id" \
    "wp_bb_user_reactions:user_id:id" "wp_bb_poll_votes:user_id:id" \
    "wp_bb_xprofile_visibility:user_id:id" "wp_bp_document:user_id:id" \
    "wp_bp_groups_members:user_id:id" "wp_bp_invitations:user_id:id" "wp_bp_media:user_id:id" \
    "wp_bp_notifications:user_id:id" "wp_bp_messages_recipients:user_id:id" \
    "wp_bp_messages_messages:sender_id:id" "wp_bp_xprofile_data:user_id:id" \
    "wp_ulike:user_id:id" "wp_ulike_comments:user_id:id" "wp_ulike_forums:user_id:id" \
    "wp_fc_subscribers:user_id:id" "wp_statistics_visitor:user_id:ID"
  do
    IFS=: read -r t c pk <<<"$spec"
    echo "## $t" >> "$out"
    my "SELECT md5(GROUP_CONCAT(x ORDER BY x SEPARATOR '|')) FROM (
           SELECT CONCAT_WS(':', $pk, $c) x FROM $t WHERE $c IN ($SURV,$TWIN)) z;" >> "$out"
  done
  echo "## wp_bp_friends" >> "$out"
  my "SELECT md5(GROUP_CONCAT(x ORDER BY x SEPARATOR '|')) FROM (
        SELECT CONCAT_WS(':', id, initiator_user_id, friend_user_id) x FROM wp_bp_friends
        WHERE initiator_user_id IN ($SURV,$TWIN) OR friend_user_id IN ($SURV,$TWIN)) z;" >> "$out"
  echo "## wp_users" >> "$out"
  my "SELECT CONCAT_WS(':',ID,user_login,user_email,user_status) FROM wp_users WHERE ID IN ($SURV,$TWIN) ORDER BY ID;" >> "$out"
  echo "## wp_usermeta_caps" >> "$out"
  my "SELECT CONCAT_WS(':',user_id,meta_key,meta_value) FROM wp_usermeta
       WHERE user_id IN ($SURV,$TWIN) AND meta_key IN ('wp_capabilities','lg_merged_into','lg_prior_email') ORDER BY user_id,meta_key;" >> "$out"

  echo "## pg_connections" >> "$out"
  pg "SELECT md5(string_agg(x,'|' ORDER BY x)) FROM (
        SELECT id||':'||requester_uuid||':'||addressee_uuid||':'||status AS x FROM connections
        WHERE requester_uuid IN ('$SUUID','$TUUID') OR addressee_uuid IN ('$SUUID','$TUUID')) z;" >> "$out"
  echo "## pg_msgs" >> "$out"
  pg "SELECT md5(string_agg(id||':'||sender_uuid,'|' ORDER BY id)) FROM messages
       WHERE sender_uuid IN ('$SUUID','$TUUID');" >> "$out"
  echo "## pg_recipients" >> "$out"
  pg "SELECT md5(string_agg(thread_id||':'||user_uuid,'|' ORDER BY thread_id)) FROM message_recipients
       WHERE user_uuid IN ('$SUUID','$TUUID');" >> "$out"
  echo "## pg_notifications" >> "$out"
  pg "SELECT md5(string_agg(id||':'||user_uuid||':'||coalesce(actor_uuid::text,''),'|' ORDER BY id)) FROM notifications
       WHERE user_uuid IN ('$SUUID','$TUUID') OR actor_uuid IN ('$SUUID','$TUUID');" >> "$out"
  echo "## pg_aliases" >> "$out"
  pg "SELECT md5(string_agg(email_normalized||':'||user_id,'|' ORDER BY email_normalized)) FROM email_aliases
       WHERE user_id IN ($SPG,$TPG);" >> "$out"
  echo "## pg_users" >> "$out"
  pg "SELECT id||':'||primary_email||':'||coalesce(archived_at::text,'-') FROM users WHERE id IN ($SPG,$TPG) ORDER BY id;" >> "$out"

  echo "## mir_reply" >> "$out"
  mir "SELECT md5(string_agg(id||':'||author_id||':'||coalesce(author_name,'')||':'||coalesce(author_slug,''),'|' ORDER BY id))
        FROM forums.reply WHERE author_id IN ($SURV,$TWIN);" >> "$out"
  echo "## mir_topic" >> "$out"
  mir "SELECT md5(string_agg(id||':'||author_id||':'||coalesce(author_name,''),'|' ORDER BY id))
        FROM forums.topic WHERE author_id IN ($SURV,$TWIN);" >> "$out"
  echo "## mir_cardreact" >> "$out"
  mir "SELECT md5(string_agg(x,'|' ORDER BY x)) FROM (
        SELECT post_type||':'||item_id||':'||coalesce(user_wp_id::text,'')||':'||coalesce(actor_key::text,'') AS x
        FROM discovery.card_reactions WHERE user_wp_id IN ($SURV,$TWIN)) z;" >> "$out"
  echo "## mir_content" >> "$out"
  mir "SELECT md5(string_agg(id||':'||coalesce(author_id::text,'')||':'||coalesce(author_name,''),'|' ORDER BY id))
        FROM discovery.content_item WHERE author_id IN ($SURV,$TWIN);" >> "$out"
}

echo "--- 1. fingerprint BEFORE"
fingerprint "$WORK/before.txt"
grep -c . "$WORK/before.txt" >/dev/null

echo "--- 2. counts BEFORE (twin side must be non-empty for this to prove anything)"
BEFORE_TWIN_POSTS=$(my "SELECT COUNT(*) FROM wp_posts WHERE post_author=$TWIN;")
BEFORE_SURV_POSTS=$(my "SELECT COUNT(*) FROM wp_posts WHERE post_author=$SURV;")
BEFORE_TWIN_CONN=$(pg "SELECT COUNT(*) FROM connections WHERE requester_uuid='$TUUID' OR addressee_uuid='$TUUID';")
BEFORE_TWIN_MIRROR=$(mir "SELECT COUNT(*) FROM forums.reply WHERE author_id=$TWIN;")
echo "    twin posts=$BEFORE_TWIN_POSTS  survivor posts=$BEFORE_SURV_POSTS  twin conns=$BEFORE_TWIN_CONN  twin mirror replies=$BEFORE_TWIN_MIRROR"

echo "--- 3. APPLY"
"$HERE/run-as-root.sh" --apply --pair="$PAIR" --force-hold | tee "$WORK/apply.log"
JOURNAL=$(grep -oE '/[^ ]*\.json' "$WORK/apply.log" | head -1)
echo "    journal=$JOURNAL"

echo "--- 4. counts AFTER: twin drained, survivor holds both halves"
AFTER_TWIN_POSTS=$(my "SELECT COUNT(*) FROM wp_posts WHERE post_author=$TWIN;")
AFTER_SURV_POSTS=$(my "SELECT COUNT(*) FROM wp_posts WHERE post_author=$SURV;")
AFTER_TWIN_MIRROR=$(mir "SELECT COUNT(*) FROM forums.reply WHERE author_id=$TWIN;")
echo "    twin posts=$AFTER_TWIN_POSTS (want 0)  survivor posts=$AFTER_SURV_POSTS (want $((BEFORE_TWIN_POSTS+BEFORE_SURV_POSTS)))  twin mirror replies=$AFTER_TWIN_MIRROR (want 0)"
fail=0
[[ "$AFTER_TWIN_POSTS" -eq 0 ]] || { echo "    FAIL twin still owns posts"; fail=1; }
[[ "$AFTER_SURV_POSTS" -eq $((BEFORE_TWIN_POSTS+BEFORE_SURV_POSTS)) ]] || { echo "    FAIL survivor post count wrong"; fail=1; }
[[ "$AFTER_TWIN_MIRROR" -eq 0 ]] || { echo "    FAIL mirror still credits twin"; fail=1; }

echo "--- 5. tool's own verify"
"$HERE/run-as-root.sh" --verify --pair="$PAIR"

echo "--- 6. ROLLBACK"
"$HERE/run-as-root.sh" --rollback --journal="$JOURNAL"

echo "--- 7. fingerprint AFTER ROLLBACK, compared byte-for-byte"
fingerprint "$WORK/after.txt"
if diff -u "$WORK/before.txt" "$WORK/after.txt" > "$WORK/diff.txt"; then
  echo "    RESTORE EXACT — fingerprints identical"
else
  echo "    FAIL restore differs:"; cat "$WORK/diff.txt"; fail=1
fi

[[ $fail -eq 0 ]] && echo "PROOF PASSED" || { echo "PROOF FAILED"; exit 1; }
