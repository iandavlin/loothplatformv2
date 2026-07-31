#!/bin/bash
# compose-probe.sh — re-runs every measurement in docs/FRONTEND-COMPOSE-SCOPE.md.
#
# READ-ONLY except for section 5, which creates ONE draft, measures it and
# force-deletes it inside a single wp eval. It is never published, so it cannot
# reach the hub feed, the Postgres mirror or Ian. If that call is interrupted
# between insert and delete, the leftover is a DRAFT titled "PROBE — delete me".
#
# Needs root for wp-cli (the box rule). The live section is DB-only via ssh live-ro.
set -uo pipefail

WP="sudo -n wp --allow-root --path=/var/www/dev"
# wp-cli emits two DISABLE_WP_CRON redefinition warnings per call on this box —
# noise from the box config, not from these probes.
nowarn() { grep -vE '^(PHP )?(Warning|Deprecated):' ; }
hr() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

hr "1. The only working front-end compose path, and its shape"
echo "form markup:      bb-mirror/web/_chrome.php:317  (#ntm-form)"
echo "desktop wizard:   bb-mirror/web/forums.js:2106   buildNtmWizard()"
grep -n "min-width:641px" "$(dirname "$0")/../../bb-mirror/web/forums.js" | head -2 \
  | sed 's/^/  DESKTOP-ONLY GUARD  /'
echo "  -> below 641px the wizard returns null and the FLAT form is served."

hr "2. Which types have an 'Add Post' ACF form, and is their page SYNTHESIZED?"
# The two facts that decide the slice. A type that is synthesized renders from
# postmeta alone; a type that is not needs a hand-authored layout blob, so a form
# for it produces a post with no page.
$WP eval '
$synth = ["event","loothprint","loothcuts","useful_links","document","member-benefit"];
$all   = ["loothprint","event","loothcuts","member-benefit","document","useful_links",
          "post-type-videos","post-imgcap","sponsor-post","shorty"];
printf("%-20s %-13s %-14s %-12s\n","post_type","synthesized","has ACF form","form fields");
foreach ($all as $t) {
  $n = 0;
  foreach (acf_get_field_groups(["post_type" => $t]) as $g) {
    if (strpos($g["title"], "Add Post") !== false || strpos($g["title"], "Sponsor Add Post") !== false) {
      $f = acf_get_fields($g["key"]); $n = is_array($f) ? count($f) : 0;
    }
  }
  printf("%-20s %-13s %-14s %-12s\n", $t,
    in_array($t, $synth, true) ? "YES" : "no", $n ? "YES" : "no", $n ?: "-");
}' 2>/dev/null | nowarn

hr "3. The easy form already exists — and renders WITHOUT Elementor"
$WP eval '
echo "acf_form():      ".(function_exists("acf_form")?"available":"MISSING")."\n";
echo "acf_form_head(): ".(function_exists("acf_form_head")?"available":"MISSING")."\n";
global $shortcode_tags;
$f = array_filter(array_keys($shortcode_tags), function ($t) {
  return stripos($t,"frontend") !== false || stripos($t,"acf_frontend") !== false; });
echo "non-Elementor shortcodes: ".($f ? implode(", ", $f) : "(none)")."\n\n";
wp_set_current_user(get_users(["role"=>"administrator","number"=>1,"fields"=>["ID"]])[0]->ID);
ob_start();
acf_form([
  "id" => "probe", "post_id" => "new_post",
  "new_post" => ["post_type" => "loothprint", "post_status" => "draft"],
  "field_groups" => ["group_6547d86d9073b"],       // Add Post - Loothprints
]);
$h = ob_get_clean();
echo "acf_form() rendered bytes: ".strlen($h)."\n";
echo "has <form>: ".(strpos($h,"<form")!==false?"YES":"no");
echo "   has nonce: ".(strpos($h,"_acf_nonce")!==false||strpos($h,"_acfnonce")!==false?"YES":"no")."\n";
preg_match_all("/acf-field-([a-z-]+)/", $h, $t);
echo "field TYPES rendered: ".implode(", ", array_unique($t[1]))."\n";
' 2>/dev/null | nowarn

hr "4. The form's fields vs the page-builder's inputs — same data model?"
$WP eval '
$collect = [];
foreach (acf_get_fields("group_6547d86d9073b") as $f) $collect[] = $f["name"];
echo "FORM collects (".count($collect)."):\n   ".implode(", ", $collect)."\n\n";
echo "RENDERER default_loothprint_layout() reads (Plugin.php:344-407):\n";
echo "   post_content, loothprint_more_images, loothprint_3d_file,\n";
echo "   loothprint_video_instructions, loothprint_onshape_link,\n";
echo "   loothprint_creative_commons, loothprint_buy_me_a_coffee, featured image, tier\n";
' 2>/dev/null | nowarn

hr "5. END TO END — a post carrying ONLY what the form collects becomes a page"
echo "(creates one DRAFT, measures it, force-deletes it — never published)"
$WP eval '
$admin = get_users(["role"=>"administrator","number"=>1,"fields"=>["ID"]])[0]->ID;
wp_set_current_user($admin);
$id = wp_insert_post([
  "post_type" => "loothprint", "post_status" => "draft",
  "post_title" => "PROBE — delete me", "post_author" => $admin,
  "post_content" => "A test jig I printed to hold a nut blank while slotting.",
], true);
if (is_wp_error($id)) { echo "insert failed: ".$id->get_error_message()."\n"; exit; }
echo "draft #$id created\n";
try {
  // Exactly what the ACF form writes, using the form-s own field names.
  update_post_meta($id, "loothprint_more_images", [72152, 72153]);
  update_post_meta($id, "loothprint_3d_file", 72154);
  update_post_meta($id, "loothprint_video_instructions", "https://youtu.be/QAAh5wLQJhY");
  update_post_meta($id, "loothprint_onshape_link", "https://cad.onshape.com/documents/example");
  update_post_meta($id, "loothprint_creative_commons", "CC BY-NC-SA");
  update_post_meta($id, "loothprint_buy_me_a_coffee", "https://buymeacoffee.com/example");
  $r = new ReflectionMethod("LG\\LayoutV2\\Plugin", "default_loothprint_layout");
  $r->setAccessible(true);
  $L = $r->invoke(null, get_post($id));
  echo "\nsynthesized ".count($L["blocks"])." blocks from ONLY what the form collects:\n";
  foreach ($L["blocks"] as $b)
    echo "   - ".$b["type"].(isset($b["variant"]) ? ":".$b["variant"] : "")
        .(isset($b["gated_tier"]) ? "  [gated:".$b["gated_tier"]."]" : "")."\n";
  $m = new ReflectionMethod("LG\\LayoutV2\\Plugin", "manages");
  $p = get_post($id); $p->post_status = "publish";      // in memory only
  echo "\nmanages() once published: ".($m->invoke(null, $p) ? "YES — v2 renders it" : "no — legacy template")."\n";
} finally {
  wp_delete_post($id, true);
  echo "draft #$id force-deleted: ".(get_post($id) ? "STILL THERE — CLEAN UP BY HAND" : "gone")."\n";
}' 2>/dev/null | nowarn

hr "6. Which types are actually member-authored (this picks the slice)"
$WP db query "
SELECT p.post_type,
       COUNT(*) AS posts,
       COUNT(DISTINCT p.post_author) AS distinct_authors,
       SUM(CASE WHEN p.post_date > DATE_SUB(NOW(), INTERVAL 365 DAY) THEN 1 ELSE 0 END) AS last_year
FROM wp_posts p
WHERE p.post_type IN ('loothprint','post-type-videos','post-imgcap','sponsor-post','event','shorty','topic')
  AND p.post_status IN ('publish','draft','private')
GROUP BY p.post_type ORDER BY distinct_authors DESC;" --skip-plugins --skip-themes 2>/dev/null | nowarn

hr "7. SECURITY: who would pass a natural create-capability gate?"
# For EDIT, current_user_can('edit_post', $id) discriminated perfectly. For CREATE
# the equivalent does not discriminate at all — that inversion is the whole point.
$WP eval '
printf("%-16s %-12s %-15s %-14s\n","role","edit_posts","publish_posts","upload_files");
foreach (["administrator","editor","author","contributor","subscriber",
          "looth1","looth2","looth3","looth4","bbp_participant"] as $r) {
  $x = get_role($r); if (!$x) continue; $c = $x->capabilities;
  printf("%-16s %-12d %-15d %-14d\n", $r,
    !empty($c["edit_posts"]), !empty($c["publish_posts"]), !empty($c["upload_files"]));
}
echo "\ncreate_posts for loothprint maps to: ".get_post_type_object("loothprint")->cap->create_posts."\n";
$all = get_users(["fields"=>["ID"],"number"=>-1]); $n = 0;
foreach ($all as $u) if ((new WP_User($u->ID))->has_cap("edit_posts")) $n++;
echo "users holding edit_posts: ".$n." of ".count($all)."   <- the size of the hole\n";
' 2>/dev/null | nowarn

hr "8. LIVE — does the same population exist there? (read-only)"
if timeout 30 ssh -o BatchMode=yes live-ro true 2>/dev/null; then
  timeout 60 ssh live-ro "mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import -e \"
    SELECT 'total_users', COUNT(*) FROM wp_users
    UNION ALL SELECT 'looth_tier_members', COUNT(*) FROM wp_usermeta
      WHERE meta_key='wp_capabilities' AND meta_value REGEXP 'looth[1-4]'
    UNION ALL SELECT 'subscribers', COUNT(*) FROM wp_usermeta
      WHERE meta_key='wp_capabilities' AND meta_value LIKE '%subscriber%';\"" 2>/dev/null
  echo "-- do live's roles carry the create caps? --"
  timeout 60 ssh live-ro "mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import \
    -e \"SELECT option_value FROM wp_options WHERE option_name='wp_user_roles';\"" 2>/dev/null \
  | python3 -c "
import sys, re
s = sys.stdin.read()
for role in ['looth3','subscriber','author']:
    m = re.search(r'\"'+role+r'\";a:\d+:\{s:\d+:\"name\";s:\d+:\"[^\"]*\";s:\d+:\"capabilities\";a:\d+:\{(.*?)\}\}', s, re.S)
    if not m:
        print(f'{role:12s} not found'); continue
    caps = m.group(1)
    have = [c for c in ['edit_posts','publish_posts','upload_files','edit_others_posts']
            if '\"'+c+'\";b:1' in caps]
    print(f'{role:12s} holds: {have}')
"
else
  echo "SKIPPED — live-ro unreachable from this box."
fi

hr "done"
echo "Every number above is what docs/FRONTEND-COMPOSE-SCOPE.md cites. A disagreement"
echo "between this output and that document means the document is stale — fix the"
echo "document, not this script."
