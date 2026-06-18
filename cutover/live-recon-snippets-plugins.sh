#!/usr/bin/env bash
# LIVE RECON — read-only. Captures live's DB-stored state so keep/drop is judged
# against LIVE's real data, not dev's drift. Run ON LIVE. Writes nothing to the
# DB or filesystem outside the OUT dir. Ian runs; pastes/uploads OUT back to coord.
#
# ⚠️ OUTPUT IS SENSITIVE: section 4 dumps active snippet CODE, which can embed API
#    keys / secrets. Treat the OUT dir + .tgz as SECRET — private upload to coord
#    only, NEVER a shared channel, and delete after review.
#
#   bash live-recon-snippets-plugins.sh           # uses WP=/var/www/html, web user www-data
#   WP=/path/to/wp WPUSER=www-data bash live-recon-snippets-plugins.sh
#
# REVIEW GATE: coord reviews this before Ian runs it. READ-ONLY — verify:
#   - only `wp ... list/get/query SELECT`, no update/delete/activate/deactivate.
set -uo pipefail

WP="${WP:-/var/www/html}"
WPUSER="${WPUSER:-www-data}"
OUT="${OUT:-/tmp/live-recon-$(date +%Y%m%d-%H%M%S)}"
mkdir -p "$OUT"
wpc(){ sudo -u "$WPUSER" wp --path="$WP" "$@"; }

echo "WP=$WP  USER=$WPUSER  OUT=$OUT"

# --- 1. Active theme (template + stylesheet) ---
{ echo "template=$(wpc option get template 2>/dev/null)"
  echo "stylesheet=$(wpc option get stylesheet 2>/dev/null)"
  wpc theme list --fields=name,status,version 2>/dev/null
} > "$OUT/active-theme.txt"

# --- 2. Active + must-use + ALL plugins (status drives the carry/drop call) ---
wpc plugin list --fields=name,status,version 2>/dev/null > "$OUT/plugins-all.txt"
wpc plugin list --status=active   --field=name 2>/dev/null > "$OUT/plugins-active.txt"
wpc plugin list --status=must-use --field=name 2>/dev/null > "$OUT/plugins-mustuse.txt"

# --- 3. Code-snippets: inventory (id/active/scope/name) — READ-ONLY SELECT ---
# active: 1=active, 0=inactive, -1=trashed (single-site table is wp_snippets).
wpc db query "SELECT id, active, scope, name FROM wp_snippets ORDER BY active DESC, id;" \
  --skip-column-names 2>/dev/null > "$OUT/snippets-inventory.tsv"

# --- 4. Active snippet CODE (so we can assign each a fold-target). READ-ONLY. ---
# One file per active snippet: <id>__<sanitized-name>.php
mkdir -p "$OUT/snippets-active-code"
ids=$(wpc db query "SELECT id FROM wp_snippets WHERE active=1;" --skip-column-names 2>/dev/null)
for id in $ids; do
  name=$(wpc db query "SELECT name FROM wp_snippets WHERE id=$id;" --skip-column-names 2>/dev/null \
         | tr -cs 'A-Za-z0-9' '-' | sed 's/^-//;s/-$//' | cut -c1-50)
  wpc db query "SELECT code FROM wp_snippets WHERE id=$id;" --skip-column-names 2>/dev/null \
    > "$OUT/snippets-active-code/${id}__${name}.php"
done

# --- 5. Shortcode-in-content scan: which snippet/theme shortcodes are actually used ---
# (catches the theme-drop risk + tells us which shortcode snippets are load-bearing)
for sc in tlg_sponsor_page_url looth_author_archive_link my_acf_repeater looth_gallery \
          bt_build_review mp2t_author_icons; do
  n=$(wpc db query "SELECT COUNT(*) FROM wp_posts WHERE post_status='publish' AND post_content LIKE '%[$sc%';" \
        --skip-column-names 2>/dev/null)
  echo "[$sc] used in $n published posts"
done > "$OUT/shortcode-usage.txt"

echo "=== DONE. Bundle for coord: ==="
echo "  tar czf ${OUT}.tgz -C $(dirname "$OUT") $(basename "$OUT")   # then upload ${OUT}.tgz"
ls -R "$OUT"
