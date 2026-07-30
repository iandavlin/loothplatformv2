#!/bin/bash
# db-snapshot.sh — everything about a topic that an EDIT must not disturb.
#
# The acceptance clause this serves is the negative one: "saving preserves everything
# edit doesn't touch (replies, reactions, timestamps beyond modified)". A negative is
# not provable by looking at the UI — a relocated reply, a dropped reaction and a
# rewritten post_date all render exactly like success. So this dumps the state to a
# stable, sorted, diffable text file; run it either side of a save and diff.
#
# Deliberately dumps MORE than the feature touches. The interesting failures are the
# ones nobody predicted, and a column left out of the snapshot is a column the diff
# can never flag. post_date is in here precisely because nothing should ever write it.
#
#   usage: db-snapshot.sh <topic_id> <out-file>
set -uo pipefail
TOPIC="${1:?topic id}"
OUT="${2:?output file}"
WP="sudo -u looth-dev wp --path=/var/www/dev"

q() { $WP db query "$1" --skip-column-names 2>/dev/null | grep -v '^PHP Warning'; }

{
echo "### TOPIC ROW ($TOPIC)"
q "SELECT ID, post_author, post_date, post_date_gmt, post_modified, post_modified_gmt,
          post_status, post_type, post_parent, menu_order, comment_count, post_name,
          MD5(post_content) AS content_md5, post_title
   FROM wp_posts WHERE ID=$TOPIC"

echo
echo "### REPLIES (post_parent=$TOPIC, real replies only, never revisions)"
q "SELECT ID, post_author, post_date_gmt, post_modified_gmt, post_status, post_parent,
          menu_order, MD5(post_content) AS content_md5
   FROM wp_posts WHERE post_parent=$TOPIC AND post_type='reply' ORDER BY ID"

echo
echo "### REPLY _bbp_forum_id (the denormalised copy a forum move must carry along)"
q "SELECT p.ID, pm.meta_key, pm.meta_value
   FROM wp_posts p JOIN wp_postmeta pm ON pm.post_id=p.ID
   WHERE p.post_parent=$TOPIC AND p.post_type='reply' AND pm.meta_key='_bbp_forum_id'
   ORDER BY p.ID"

echo
echo "### TOPIC POSTMETA (all of it — _bbp_forum_id, counts, last-active, _lg_anon)"
q "SELECT meta_key, meta_value FROM wp_postmeta
   WHERE post_id=$TOPIC AND meta_key NOT LIKE '\_edit_lock%' ORDER BY meta_key, meta_value"

echo
echo "### TAGS (topic-tag terms, the thing a wrong omission silently wipes)"
q "SELECT t.term_id, t.slug, t.name, tt.taxonomy
   FROM wp_term_relationships tr
   JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
   JOIN wp_terms t ON t.term_id=tt.term_id
   WHERE tr.object_id=$TOPIC ORDER BY t.slug"

echo
echo "### REACTIONS on the topic and its replies"
# Columns are (user_id, reaction_id, item_type, item_id) — there is NO post_id. The
# first cut of this query selected one, and because wp-cli writes SQL errors to stderr
# the section rendered as a clean EMPTY LIST: "no reactions" and "your query is broken"
# looked identical. Hence the count line below — an assertion about the instrument
# rather than the data, so a silent failure of this query can never again read as a
# fixture with nothing on it.
# They also do NOT hang off the post. They hang off the ACTIVITY record (item_type
# 'activity', item_id = the topic's _bbp_activity_id), so a query keyed on the topic ID
# returns nothing however healthy the data is. Following the link matters beyond
# bookkeeping: that activity row is groups-component with item_id=31, the GROUP behind
# the forum — so a forum move has a plausible route to orphaning it, and every reaction
# on the post with it.
q "SELECT r.id, r.user_id, r.reaction_id, r.item_type, r.item_id, r.date_created
   FROM wp_bb_user_reactions r
   WHERE r.item_id IN (SELECT meta_value FROM wp_postmeta
                       WHERE meta_key='_bbp_activity_id'
                         AND post_id IN (SELECT ID FROM wp_posts
                                         WHERE ID=$TOPIC OR post_parent=$TOPIC))
   ORDER BY r.id"
echo "-- reaction rows found:"
q "SELECT COUNT(*) FROM wp_bb_user_reactions r
   WHERE r.item_id IN (SELECT meta_value FROM wp_postmeta
                       WHERE meta_key='_bbp_activity_id'
                         AND post_id IN (SELECT ID FROM wp_posts
                                         WHERE ID=$TOPIC OR post_parent=$TOPIC))"

echo
echo "### ACTIVITY ROW (what those reactions actually hang off)"
q "SELECT id, user_id, component, type, item_id, secondary_item_id, hide_sitewide, is_spam
   FROM wp_bp_activity
   WHERE id IN (SELECT meta_value FROM wp_postmeta WHERE meta_key='_bbp_activity_id'
                AND post_id IN (SELECT ID FROM wp_posts WHERE ID=$TOPIC OR post_parent=$TOPIC))
   ORDER BY id"

echo
echo "### REVISION COUNT (a save may add one; nothing may delete one)"
q "SELECT COUNT(*) FROM wp_posts WHERE post_parent=$TOPIC AND post_type='revision'"

echo
echo "### FORUM COUNTERS (both sides of any move must stay consistent)"
q "SELECT p.ID, p.post_title, pm.meta_key, pm.meta_value
   FROM wp_posts p JOIN wp_postmeta pm ON pm.post_id=p.ID
   WHERE p.post_type='forum' AND p.ID IN (3837, 3823)
     AND pm.meta_key IN ('_bbp_topic_count','_bbp_reply_count','_bbp_total_topic_count')
   ORDER BY p.ID, pm.meta_key"

echo
echo "### MIRROR (postgres) — topic + replies as the forum front-end actually sees them"
# Schema is 'forums' and auth is PEER, so this must run as the bb-mirror role; as
# ubuntu it fails with 'role does not exist'. This is the half the reconcile sweep
# cannot self-heal: bbp_move_topic_handler rewrites reply forum_id WITHOUT bumping
# post_modified_gmt, and the sweep only revisits rows whose modified time moved — so
# a reply left behind in the old forum here would stay wrong indefinitely.
sudo -u bb-mirror psql -d looth -A -F'|' -t -c \
  "SELECT 'topic', id, forum_id, status,
          to_char(modified_at AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS'), title
     FROM forums.topic WHERE id=$TOPIC
   UNION ALL
   SELECT 'reply', id, forum_id, status,
          to_char(modified_at AT TIME ZONE 'UTC','YYYY-MM-DD HH24:MI:SS'), ''
     FROM forums.reply WHERE topic_id=$TOPIC
   ORDER BY 1 DESC, 2" 2>&1 | grep -v '^psql:' || echo "(pg unreadable)"
} > "$OUT"

echo "snapshot -> $OUT ($(wc -l < "$OUT") lines)"
