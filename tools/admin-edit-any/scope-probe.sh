#!/bin/bash
# scope-probe.sh — re-runs every measurement in docs/ADMIN-EDIT-ANY-SCOPE.md.
#
# WHY THIS EXISTS. The scope document makes claims that decide whether a multi-week
# program gets started ("there is no front-end composer for these types", "gating on
# can_edit_others is a privilege escalation"). A claim that cannot be re-run is a
# claim that rots silently — and the two facts that matter most here are both
# INSTALL STATE (which plugins are active, which roles hold which caps), which is
# exactly the kind of thing that changes without a commit.
#
# READ-ONLY. Nothing here writes to WordPress. The over-grant simulation injects
# capabilities through a `user_has_cap` FILTER, in memory, for the duration of one
# eval — deliberately NOT via WP_User::add_role, which would persist to the DB.
#
# Needs root for wp-cli (the box rule). The dev2 fetch section needs the gate token
# and is skipped with a loud message if gate-env.sh can't resolve one.
set -uo pipefail

WP="sudo -n wp --allow-root --path=/var/www/dev"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# wp-cli prints two DISABLE_WP_CRON redefinition warnings on this box on every call;
# they are noise from the box's config, not from these probes.
nowarn() { grep -v '^PHP Warning:' ; }
hr() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

hr "1. Post types in play, and the capability set each one registers"
# The whole security argument turns on this: `topic` maps to edit_others_TOPICS,
# every content type maps to edit_others_POSTS. One meta cap covers both.
$WP eval '
foreach (["topic","loothprint","post-type-videos","post-imgcap","sponsor-post","event"] as $t) {
  $o = get_post_type_object($t);
  if (!$o) { printf("%-20s (not registered)\n", $t); continue; }
  printf("%-20s label=%-14s edit_others=%s\n", $t, trim($o->label), $o->cap->edit_others_posts);
}' 2>/dev/null | nowarn

hr "2. Body model — which types are layout-v2 block posts, which are post_content"
$WP db query "
SELECT p.post_type,
       COUNT(DISTINCT p.ID) AS posts,
       SUM(CASE WHEN m.meta_id IS NOT NULL THEN 1 ELSE 0 END) AS with_layout_v2
FROM wp_posts p
LEFT JOIN wp_postmeta m ON m.post_id = p.ID AND m.meta_key = '_lg_layout_v2'
WHERE p.post_type IN ('topic','loothprint','post-type-videos','post-imgcap','sponsor-post','event')
  AND p.post_status IN ('publish','draft','private','archived')
GROUP BY p.post_type ORDER BY p.post_type;" --skip-plugins --skip-themes 2>/dev/null | nowarn

hr "3. What 'full functionality' means — ACF control count + taxonomies per type"
$WP eval '
foreach (["topic","loothprint","post-type-videos","post-imgcap","sponsor-post","event"] as $t) {
  $n = 0;
  if (function_exists("acf_get_field_groups")) {
    foreach (acf_get_field_groups(["post_type" => $t]) as $g) {
      $f = acf_get_fields($g["key"]); $n += is_array($f) ? count($f) : 0;
    }
  }
  printf("%-20s acf_fields=%-4d taxonomies=%s\n", $t, $n, implode(",", get_object_taxonomies($t)) ?: "-");
}' 2>/dev/null | nowarn

hr "3b. The primary create controls, verbatim (this is the list a composer must reproduce)"
$WP eval '
foreach (["Add Post - Loothprints","Add Post - Video Post","Add Post - Article",
          "Sponsor Add Post - Sponsor Post","Add Post - Event"] as $title) {
  foreach (acf_get_field_groups() as $g) {
    if ($g["title"] !== $title) continue;
    echo "\n== " . $title . "\n";
    foreach ((array) acf_get_fields($g["key"]) as $f) {
      echo "   - " . $f["label"] . " [" . $f["type"] . "]" . (!empty($f["required"]) ? " *req" : "") . "\n";
    }
  }
}' 2>/dev/null | nowarn

hr "4. The Frontend Admin / Elementor estate — pages exist, but does the engine?"
# The pages below LOOK like a complete front-end authoring feature. They are dead:
# their form widgets live in _elementor_data, and Elementor is not running.
$WP db query "
SELECT ID, post_name FROM wp_posts
WHERE post_type='page' AND post_status='publish'
  AND (post_name LIKE 'edit-%' OR post_name LIKE 'add-%')
ORDER BY post_name;" --skip-plugins --skip-themes 2>/dev/null | nowarn
echo
echo "-- the engine those pages need --"
$WP plugin list --name=elementor --fields=name,status 2>/dev/null | nowarn
$WP plugin list --name=frontend-admin-pro --fields=name,status 2>/dev/null | nowarn
$WP theme list --status=active --fields=name,version 2>/dev/null | nowarn

hr "5. SECURITY: the role matrix behind the over-grant"
$WP eval '
printf("%-18s %-9s %-10s %-18s %-15s %s\n","role","moderate","keep_gate","edit_others_posts","manage_options","edit_others_topics");
foreach (wp_roles()->roles as $k => $r) {
  $c = $r["capabilities"];
  if (empty($c["moderate"]) && empty($c["keep_gate"]) && empty($c["edit_others_posts"]) && empty($c["manage_options"])) continue;
  printf("%-18s %-9d %-10d %-18d %-15d %d\n", $k,
    !empty($c["moderate"]), !empty($c["keep_gate"]), !empty($c["edit_others_posts"]),
    !empty($c["manage_options"]), !empty($c["edit_others_topics"]));
}' 2>/dev/null | nowarn

hr "5b. SECURITY: gating on can_edit_others vs on edit_post (simulated bbp_moderator)"
# IN-MEMORY ONLY — capabilities injected through the user_has_cap filter. No DB write.
$WP eval '
$mod_caps = get_role("bbp_moderator")->capabilities;
add_filter("user_has_cap", function ($allcaps) use ($mod_caps) {
  foreach ($mod_caps as $c => $on) if ($on) $allcaps[$c] = true;
  return $allcaps;
}, 99);
$u = get_users(["role__not_in" => ["administrator","editor","shop_manager"], "number" => 1, "fields" => ["ID"]])[0];
wp_set_current_user($u->ID);
// The exact expression from bb-mirror/api/v0/auth.php:54-56.
$can_edit_others = current_user_can("moderate") || current_user_can("keep_gate") || current_user_can("manage_options");
echo "simulated bbp_moderator: auth.php can_edit_others = " . ($can_edit_others ? "TRUE" : "false") . "\n\n";
printf("%-20s %-8s %-26s %-20s\n", "post_type", "post_id", "gate on can_edit_others", "gate on edit_post");
foreach (["topic","loothprint","post-type-videos","post-imgcap","sponsor-post","event"] as $t) {
  $ps = get_posts(["post_type" => $t, "numberposts" => 1, "post_status" => "any", "author__not_in" => [$u->ID]]);
  if (!$ps) continue;
  printf("%-20s %-8d %-26s %-20s\n", $t, $ps[0]->ID,
    $can_edit_others ? "EDIT ALLOWED" : "denied",
    current_user_can("edit_post", $ps[0]->ID) ? "EDIT ALLOWED" : "denied");
}' 2>/dev/null | nowarn

hr "5c. The correct check, both directions, against real posts by another author"
$WP eval '
$u   = get_users(["role__not_in" => ["administrator","editor","shop_manager"], "number" => 1, "fields" => ["ID","user_login"]])[0];
$adm = get_users(["role" => "administrator", "number" => 1, "fields" => ["ID","user_login"]])[0];
echo "non-admin: {$u->user_login} (" . implode(",", (new WP_User($u->ID))->roles) . ")   admin: {$adm->user_login}\n\n";
printf("%-20s %-8s %-10s %-10s\n", "post_type", "post_id", "nonadmin", "admin");
foreach (["topic","loothprint","post-type-videos","post-imgcap","sponsor-post","event"] as $t) {
  $ps = get_posts(["post_type" => $t, "numberposts" => 1, "post_status" => "any", "author__not_in" => [$u->ID]]);
  if (!$ps) continue;
  $id = $ps[0]->ID;
  wp_set_current_user($u->ID);   $a = current_user_can("edit_post", $id) ? "CAN" : "cannot";
  wp_set_current_user($adm->ID); $b = current_user_can("edit_post", $id) ? "CAN" : "cannot";
  printf("%-20s %-8d %-10s %-10s\n", $t, $id, $a, $b);
}' 2>/dev/null | nowarn

hr "5d. Who is actually over-granted right now (dev2)"
$WP eval '
$bad = [];
foreach (get_users(["fields" => ["ID","user_login"], "number" => -1]) as $u) {
  $ux  = new WP_User($u->ID);
  $ceo = $ux->has_cap("moderate") || $ux->has_cap("keep_gate") || $ux->has_cap("manage_options");
  if ($ceo && !$ux->has_cap("edit_others_posts")) $bad[] = $u->user_login . " [" . implode(",", $ux->roles) . "]";
}
echo "users where can_edit_others=TRUE but edit_others_posts=FALSE: " . count($bad) . "\n";
foreach ($bad as $b) echo "   " . $b . "\n";
echo "(empty = the escalation is LATENT, not live. One bbp_moderator appointment makes it real.)\n";
' 2>/dev/null | nowarn

hr "6. Does a Frontend Admin edit page actually render a form on dev2?"
# Fetched as a REAL admin and a REAL member — an anon fetch cannot see this, since
# the page is members-only and would 302 before the form question is even reached.
if source "$REPO/tools/gates/gate-env.sh" >/dev/null 2>&1; then
  cookie_for() {
    $WP eval "
      \$u = get_user_by('login', '$1'); if (!\$u) exit(1);
      \$e = time() + 600;
      echo LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie(\$u->ID, \$e, 'logged_in') . ';'
         . SECURE_AUTH_COOKIE . '=' . wp_generate_auth_cookie(\$u->ID, \$e, 'secure_auth');
    " 2>/dev/null | nowarn
  }
  ADMIN_USER="${LG_PROBE_ADMIN:-$($WP eval 'echo get_users(["role"=>"administrator","number"=>1,"fields"=>["user_login"]])[0]->user_login;' 2>/dev/null | nowarn)}"
  MEMBER_USER="${LG_PROBE_MEMBER:-$($WP eval 'echo get_users(["role__not_in"=>["administrator","editor","shop_manager"],"number"=>1,"fields"=>["user_login"]])[0]->user_login;' 2>/dev/null | nowarn)}"
  PROBE_POST="${LG_PROBE_POST:-$($WP eval 'echo get_posts(["post_type"=>"loothprint","numberposts"=>1,"post_status"=>"publish"])[0]->ID;' 2>/dev/null | nowarn)}"
  echo "page=/edit-loothprint/?post_id=$PROBE_POST  admin=$ADMIN_USER  member=$MEMBER_USER"
  echo
  printf "%-8s %-10s %-16s %-16s %s\n" "as" "bytes" "elem_widgets" "acf-form-data" "acf_field_inputs"
  for pair in "ADMIN:$ADMIN_USER" "MEMBER:$MEMBER_USER"; do
    label="${pair%%:*}"; user="${pair#*:}"
    c="$(cookie_for "$user")"
    out="$(curl -s $LG_GATE_RESOLVE -H "Cookie: loothdev_auth=$LG_GATE_TOKEN; $c" \
           "$LG_GATE_HOST/edit-loothprint/?post_id=$PROBE_POST")"
    printf "%-8s %-10s %-16s %-16s %s\n" "$label" "${#out}" \
      "$(grep -o 'elementor-widget-' <<<"$out" | wc -l)" \
      "$(grep -o 'acf-form-data'     <<<"$out" | wc -l)" \
      "$(grep -oE 'class="acf-field ' <<<"$out" | wc -l)"
  done
  echo
  echo "0 form inputs for BOTH = the page renders its prose and no form. The estate is dead."
else
  echo "SKIPPED — gate-env.sh could not resolve a host/token on this box."
  echo "         Set LG_GATE_HOST and LG_GATE_TOKEN to run the fetch section."
fi

hr "7. LIVE install state (read-only, via ssh live-ro)"
# Deliberately DB-only. /edit-loothprint/ 302s anon to wp-login on live, so the
# form question cannot be answered there without a logged-in session — which is
# Ian's to run. What IS answerable is whether the engine is even installed.
if timeout 30 ssh -o BatchMode=yes live-ro true 2>/dev/null; then
  echo "-- is Elementor in live's active_plugins? --"
  timeout 60 ssh live-ro "mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import \
    -e \"SELECT option_value FROM wp_options WHERE option_name='active_plugins';\"" 2>/dev/null \
    | tr ';' '\n' | grep -o '"[a-z0-9._/-]*"' | tr -d '"' \
    | grep -iE 'elementor|frontend-admin' || echo "   elementor: NOT PRESENT"
  echo "-- live theme --"
  timeout 60 ssh live-ro "mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import \
    -e \"SELECT option_name, option_value FROM wp_options WHERE option_name IN ('template','stylesheet');\"" 2>/dev/null
  echo "-- live: is anyone in the over-granted class? --"
  timeout 60 ssh live-ro "mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import -e \"
    SELECT 'bbp_moderator', COUNT(*) FROM wp_usermeta WHERE meta_key='wp_capabilities' AND meta_value LIKE '%bbp_moderator%'
    UNION ALL SELECT 'keymaster_not_admin', COUNT(*) FROM wp_usermeta WHERE meta_key='wp_capabilities' AND meta_value LIKE '%bbp_keymaster%' AND meta_value NOT LIKE '%administrator%'
    UNION ALL SELECT 'administrator', COUNT(*) FROM wp_usermeta WHERE meta_key='wp_capabilities' AND meta_value LIKE '%administrator%'
    UNION ALL SELECT 'editor', COUNT(*) FROM wp_usermeta WHERE meta_key='wp_capabilities' AND meta_value LIKE '%editor%';\"" 2>/dev/null
else
  echo "SKIPPED — live-ro unreachable from this box."
fi

hr "done"
echo "Every number above is what docs/ADMIN-EDIT-ANY-SCOPE.md cites. A disagreement"
echo "between this output and that document means the document is stale — fix the"
echo "document, not this script."
