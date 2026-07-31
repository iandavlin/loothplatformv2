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

hr "5b. THE ROUND-TRIP — a REAL ACF submit writes what the page-builder reads"
# The specific risk: ACF forms post by field KEY and ACF stores by field NAME. A
# mismatch writes nothing, the page-builder finds empty meta, and the pipeline
# silently yields a blank post while every piece still looks right in isolation.
# So this drives acf_save_post() — the handler a submitted acf_form() actually
# runs — posting by key, exactly as the form does.
$WP eval '
$admin = get_users(["role"=>"administrator","number"=>1,"fields"=>["ID"]])[0]->ID;
wp_set_current_user($admin);
$id = wp_insert_post(["post_type"=>"loothprint","post_status"=>"draft",
  "post_author"=>$admin,"post_title"=>"PROBE submit — delete me"], true);
if (is_wp_error($id)) { echo "insert failed\n"; exit; }
echo "draft #$id\n";
try {
  $_POST["acf"] = [
    "field_69ffa70fe8835" => "A test jig for holding nut blanks while slotting.",
    "field_6547dafd3f5d6" => [72152, 72153],
    "field_6547dc013f5d7" => 72154,
    "field_65b0f1bfc93cd" => "https://youtu.be/QAAh5wLQJhY",
    "field_65be1d2a06e06" => "https://cad.onshape.com/documents/example",
    "field_654d2a1394295" => "https://buymeacoffee.com/example",
    "field_6564e26df56ba" => "BY NC SA (Credit given to creator, Non-Commercial only, Adaptations shared with same terms)",
  ];
  acf_save_post($id);                      // the real submit handler
  $need = ["post_content","loothprint_more_images","loothprint_3d_file",
           "loothprint_video_instructions","loothprint_onshape_link",
           "loothprint_creative_commons","loothprint_buy_me_a_coffee"];
  $miss = 0;
  echo "\n";
  foreach ($need as $k) {
    $v  = ($k === "post_content") ? get_post($id)->post_content : get_post_meta($id, $k, true);
    $ok = !($v === "" || $v === null || $v === false || $v === []);
    if (!$ok) $miss++;
    printf("  %-32s %-8s %s\n", $k, $ok ? "WRITTEN" : "EMPTY",
      is_array($v) ? "[".implode(",", $v)."]" : substr((string)$v, 0, 44));
  }
  $r = new ReflectionMethod("LG\\LayoutV2\\Plugin", "default_loothprint_layout");
  $r->setAccessible(true);
  $L = $r->invoke(null, get_post($id));
  echo "\n  synthesizer built ".count($L["blocks"])." blocks from the SUBMITTED values\n";
  echo "  fields the page-builder reads that the save path did NOT write: ".$miss."\n";
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

hr "9. DO THE DOCS' file:line CITATIONS STILL RESOLVE? (drifted once already)"
# On 2026-07-31 main moved 96 commits past the tree this scope was measured on and
# forums.js grew 318 lines, pushing the discussion-edit entry point +222. The stale
# citation did NOT dangle — it landed on the follow-bell markup, which reads as if
# the edit door had been deleted. A citation that resolves to the WRONG code is worse
# than one that resolves to nothing, so this checks the symbol, not the line's
# existence. Same family as the box's "verify the thing, not the thing next to it".
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
cite_check() {   # file : line : symbol-that-must-appear-within-6-lines
  local f="$1" ln="$2" sym="$3" hit
  if [ ! -f "$REPO/$f" ]; then printf '  %-46s FILE MISSING\n' "$f:$ln"; return 1; fi
  hit=$(sed -n "$((ln>3?ln-3:1)),$((ln+6))p" "$REPO/$f" | grep -c -- "$sym")
  if [ "$hit" -gt 0 ]; then printf '  %-46s OK      (%s)\n' "$f:$ln" "$sym"
  else
    printf '  %-46s DRIFTED (%s not near line) -> ' "$f:$ln" "$sym"
    grep -n -- "$sym" "$REPO/$f" | head -1 | cut -d: -f1 | sed 's/^/now ~line /'
    return 1
  fi
}
# TWO KNOWN-EXPECTED DRIFTS UNTIL THIS BRANCH REBASES, and they are left visible on
# purpose rather than pinned to whichever tree happens to be checked out. The two
# entry-point citations below are resolved against origin/main @c259885, because that
# is the tree the build lands on. This branch is 96 commits behind main, so on THIS
# worktree they sit ~222 / ~7 lines earlier and correctly report DRIFTED. That output
# IS the rebase reminder. After the rebase both must read OK — if either still says
# DRIFTED then main moved again and the docs need re-resolving, not silencing.
#
# LIMITATION, stated because the output invites the wrong reading: on a miss this
# reports the symbol's FIRST occurrence in the file, not the nearest one, so the
# "now ~line N" hint can point at a definition rather than at the call site you want.
# Treat it as "go look", not as the corrected citation.
rc=0
cite_check bb-mirror/web/forums.js      1923 "function ntmOpenForEdit"   || rc=1
cite_check bb-mirror/web/forums.js      2012 "window.lgNtmEditTopic ="   || rc=1
cite_check bb-mirror/web/forums.js      2106 "function buildNtmWizard"   || rc=1
cite_check bb-mirror/web/forums.js      5194 "lgNtmEditTopic"            || true   # main-resolved; see above
cite_check webroot/hub-polish.js        3598 "lgNtmEditTopic"            || true   # main-resolved; see above
cite_check lg-layout-v2/src/Plugin.php   257 "\$synth = \['event'"      || rc=1
cite_check lg-layout-v2/src/Plugin.php   344 "default_loothprint_layout" || rc=1
cite_check lg-layout-v2/src/MetaBox.php   39 "add_action('add_meta_boxes'" || rc=1
if [ "$rc" -eq 0 ]; then
  # Deliberately does NOT say "all resolve" — the two main-resolved lines above may
  # read DRIFTED on an un-rebased branch, and a summary that contradicts the rows
  # printed right above it is worth less than no summary at all.
  echo "  No UNEXPECTED drift. (The 2 main-resolved rows above are expected to read"
  echo "  DRIFTED until this branch rebases onto main — read them, do not skim them.)"
else
  echo "  ^^ UNEXPECTED DRIFT — FIX THE DOCS before quoting them to anyone."
fi

hr "done"
echo "Every number above is what docs/FRONTEND-COMPOSE-SCOPE.md cites. A disagreement"
echo "between this output and that document means the document is stale — fix the"
echo "document, not this script."
