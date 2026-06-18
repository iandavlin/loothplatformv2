#!/usr/bin/env bash
# dev2 pre-cut completeness audit — run ON dev2 as ubuntu (passwordless sudo).
# Read-only except one touch-test in /uploads (immediately removed). Re-runnable.
WP=/var/www/dev
GATE=qShCjBdCVXLie7wcQddsprkYj4SuaXu7UJeYAHHG
R=(--resolve dev2.loothgroup.com:443:127.0.0.1)
H=https://dev2.loothgroup.com
wpq(){ sudo -u looth-dev wp --path="$WP" "$@" 2>/dev/null | grep -v WP_CACHE_KEY_SALT; }
pgp(){ sudo -u profile-app psql -d profile_app -tAc "$1" 2>/dev/null; }
pgl(){ sudo -u looth-dev   psql -d looth       -tAc "$1" 2>/dev/null; }
PASS(){ printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
FAIL(){ printf '  \033[31mFAIL\033[0m  %s\n' "$1"; }
WARN(){ printf '  \033[33mWARN\033[0m  %s\n' "$1"; }
hdr(){ printf '\n== %s ==\n' "$1"; }
code(){ curl -s -o /dev/null -w '%{http_code}' "${R[@]}" -H "Cookie: loothdev_auth=$GATE" "$H$1"; }

echo "################ dev2 pre-cut completeness audit ################"

hdr "1. Plugin / mu-plugin symlink targets (the 'file does not exist' class)"
broke=0
for l in $(sudo find "$WP/wp-content/plugins" "$WP/wp-content/mu-plugins" -maxdepth 1 -type l 2>/dev/null); do
  t=$(readlink -f "$l"); [ -e "$t" ] || { FAIL "dangling symlink: $(basename "$l") -> $t"; broke=1; }
done
[ "$broke" = 0 ] && PASS "all plugin/mu-plugin symlinks resolve"
echo "  lg-* plugin status:"; wpq plugin list --field=name,status 2>/dev/null | grep -iE 'lg-' | sed 's/^/    /'

hdr "2. WP-pool strangler endpoints (catch env-branch 500s like auth.php)"
for ep in /bb-mirror-api/v0/auth /bb-mirror-api/v0/unread /bb-mirror-api/v0/mark-seen; do
  c=$(code "$ep"); case "$c" in 500|502|503) FAIL "$ep -> $c";; *) PASS "$ep -> $c";; esac
done

hdr "3. Identity bridge"
miss=$(wpq eval '$n=0;foreach(get_users(["fields"=>"ID"]) as $id)if(get_user_meta($id,"_looth_uuid",true)==="")$n++;echo $n;')
[ "$miss" = 0 ] && PASS "0 WP users missing _looth_uuid" || FAIL "$miss WP users missing _looth_uuid (real members? re-run dev2-bridge-fix)"

hdr "4. Backfill coverage (profile-app)"
echo "  avatars: $(pgp "SELECT 'real='||count(*) FILTER(WHERE avatar_url LIKE '/profile-media/%')||' gravatar='||count(*) FILTER(WHERE avatar_url LIKE '%gravatar%')||' none='||count(*) FILTER(WHERE coalesce(avatar_url,'')='') FROM users")"
echo "  slug_missing: $(pgp "SELECT count(*) FROM users WHERE coalesce(slug,'')=''")   connections: $(pgp "SELECT count(*) FROM connections" 2>/dev/null || echo '?')"

hdr "5. Charset (utf8mb4 — the emoji / materialize failure)"
cs=$(wpq db query "SELECT GROUP_CONCAT(TABLE_NAME,'.',COLUMN_NAME,'=',CHARACTER_SET_NAME SEPARATOR '  ') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN('wp_postmeta','wp_posts') AND COLUMN_NAME IN('meta_value','post_content')" --skip-column-names 2>/dev/null | tail -1)
echo "  $cs"
echo "$cs" | grep -q "meta_value=utf8mb4" && PASS "wp_postmeta.meta_value is utf8mb4" || FAIL "wp_postmeta.meta_value NOT utf8mb4 (emoji inserts 1366-fail)"

hdr "6. R2 uploads — read AND write"
img=$(wpq eval 'foreach(get_posts(["post_type"=>"event","post_status"=>"publish","numberposts"=>1,"fields"=>"ids"]) as $id)echo get_the_post_thumbnail_url($id,"full");')
p=$(echo "$img" | sed -E 's#https?://[^/]+##'); rc=$(code "$p")
[ "$rc" = 200 ] && PASS "R2 read: sample upload 200" || FAIL "R2 read: $p -> $rc"
if sudo -u looth-dev touch "$WP/wp-content/uploads/.audit-wt" 2>/dev/null; then PASS "R2 write: uploads writable"; sudo -u looth-dev rm -f "$WP/wp-content/uploads/.audit-wt"; else FAIL "R2 write: uploads NOT writable (mount --read-only? token?)"; fi

hdr "7. Content — events / materialization / discussion grant"
ev=$(wpq eval '$e=get_posts(["post_type"=>"event","post_status"=>"publish","numberposts"=>-1,"fields"=>"ids"]);$n=0;foreach($e as $id)if(!get_post_thumbnail_id($id))$n++;echo count($e)." events, ".$n." missing featured";')
echo "  $ev"
blobs=$(pgl "SELECT count(*) FROM discovery.article_blobs"); echo "  discovery.article_blobs: $blobs (0 = materialize never ran)"
g=$(sudo -u archive-poc psql -d looth -tAc "SELECT has_table_privilege('archive-poc','forums.topic','SELECT')" 2>/dev/null)
[ "$g" = t ] && PASS "archive-poc has forums.topic SELECT (front-page discussion grant e2ac627)" || FAIL "MISSING forums grant -> front page 500s for members"

hdr "8. Gates"
echo "  tools/gates/run-all.sh hardcodes dev.loothgroup.com — repoint needs a HOST override (separate task)."
echo "  Checks 1-7 above are the dev2-specific gate."

echo; echo "################ audit done — FAIL/red lines = pre-cut punch-list ################"
