#!/bin/bash
# roundtrip-probe.sh — post a real loothprint THROUGH THE ROUTE, then prove the
# page builds itself from what was posted, then delete it.
#
# WHY THIS EXISTS. docs/FRONTEND-COMPOSE-SCOPE.md closed the "does ACF's save
# handler write what the page-builder reads" question by driving acf_save_post()
# directly. It said plainly what remained: "that OUR OWN ROUTE calls that save
# path correctly" was unprovable before the route existed. It exists now, so this
# closes it — over HTTP, through /compose/, with a real nonce, exactly as a
# member's browser posts.
#
# The specific risk being retired: ACF forms post by field KEY and ACF stores by
# field NAME. A mismatch anywhere writes nothing at all, the synthesizer finds
# empty meta, and the result is a blank post while every piece still looks
# correct in isolation. Reading the code cannot rule that out; posting can.
#
# SAFETY. The post is created and FORCE-DELETED in the same run, and the run ends
# by proving no probe row survived. loothprint is the open tier so it publishes
# for real — that is the behaviour under test — and the window is seconds. dev2's
# mirror outbox timer is installed-but-disabled on purpose, so a published post
# here does not dispatch anywhere.
set -uo pipefail
cd "$(dirname "$0")/../.."

WP="sudo -n wp --allow-root --path=/var/www/dev"
LOGIN="${1:-bangers}"
TITLE="LGFC-ROUNDTRIP-PROBE"
nowarn() { grep -v '^PHP Warning:\|^PHP Deprecated:\|^Warning:'; }

source tools/gates/gate-env.sh || exit 2

echo "=== 0. a member, and material that already exists on the box ==="
read -r UID_ ZIP_ID IMG_ID TYPE_TERM TOPIC_TERM <<<"$(
$WP eval '
  $u = get_user_by("login", "'"$LOGIN"'");
  $zip = get_posts(["post_type"=>"attachment","posts_per_page"=>1,"post_mime_type"=>"application/zip","fields"=>"ids"]);
  $img = get_posts(["post_type"=>"attachment","posts_per_page"=>1,"post_mime_type"=>"image/jpeg","fields"=>"ids"]);
  $t1 = get_terms(["taxonomy"=>"loothprint_type","hide_empty"=>false,"number"=>1,"fields"=>"ids"]);
  $t2 = get_terms(["taxonomy"=>"shared_category","hide_empty"=>false,"number"=>1,"fields"=>"ids"]);
  echo $u->ID." ".($zip[0]??0)." ".($img[0]??0)." ".($t1[0]??0)." ".($t2[0]??0);
' 2>/dev/null | nowarn)"
echo "  user=$LOGIN($UID_) zip=$ZIP_ID img=$IMG_ID type_term=$TYPE_TERM topic_term=$TOPIC_TERM"
[ "$UID_" -gt 0 ] || { echo "CANNOT RUN: no such user"; exit 2; }
[ "$ZIP_ID" -gt 0 ] && [ "$IMG_ID" -gt 0 ] || { echo "CANNOT RUN: no zip/image attachment on the box"; exit 2; }

COOKIE="$($WP eval '
  $u = get_user_by("login","'"$LOGIN"'"); $e = time()+600;
  echo LOGGED_IN_COOKIE."=".wp_generate_auth_cookie($u->ID,$e,"logged_in").";"
     . SECURE_AUTH_COOKIE."=".wp_generate_auth_cookie($u->ID,$e,"secure_auth");' 2>/dev/null | nowarn)"

JAR="loothdev_auth=$LG_GATE_TOKEN; $COOKIE"
URL="$LG_GATE_HOST/compose/?type=loothprint"

echo
echo "=== 1. GET the form and take its REAL nonce and field keys ==="
BODY=$(curl -s -H "Cookie: $JAR" $LG_GATE_RESOLVE "$URL")
NONCE=$(printf '%s' "$BODY" | grep -o 'name="_acf_nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
FORMID=$(printf '%s' "$BODY" | grep -o 'name="_acf_form" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
K_IMG=$(printf '%s' "$BODY"  | grep -o 'name="acf\[field_[a-z0-9]*\]\[\]"' | head -1 | sed 's/.*acf\[//;s/\].*//')
echo "  nonce=${NONCE:0:12}…  form=$FORMID"
[ -n "$NONCE" ] && [ "$FORMID" = "lg-fc-loothprint" ] || { echo "FAIL: no nonce, or the form settings are not the registered ID"; exit 1; }

# Field keys, read from the field group rather than guessed.
read -r KIMG KZIP KCAT KTOP KCC <<<"$($WP eval '
  foreach (acf_get_field_groups() as $g) {
    if ($g["title"] !== "Add Post - Loothprints") continue;
    $m = [];
    foreach (acf_get_fields($g["key"]) as $f) $m[$f["name"]] = $f["key"];
    echo $m["loothprint_more_images"]." ".$m["loothprint_3d_file"]." "
       . $m["loothprint_category"]." ".$m["content_topic_broad_terms"]." "
       . $m["loothprint_creative_commons"];
  }' 2>/dev/null | nowarn)"

BEFORE=$($WP eval 'global $wpdb; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=\"loothprint\"");' 2>/dev/null | nowarn)

echo
echo "=== 2. POST it, exactly as the browser would ==="
CODE=$(curl -s -o /tmp/lgfc-rt-resp.html -w '%{http_code}' -H "Cookie: $JAR" $LG_GATE_RESOLVE \
  --data-urlencode "_acf_nonce=$NONCE" \
  --data-urlencode "_acf_post_id=new_post" \
  --data-urlencode "_acf_screen=acf_form" \
  --data-urlencode "_acf_form=$FORMID" \
  --data-urlencode "acf[_post_title]=$TITLE" \
  --data-urlencode "acf[_post_content]=A probe body written by roundtrip-probe.sh." \
  --data-urlencode "acf[$KIMG][]=$IMG_ID" \
  --data-urlencode "acf[$KZIP]=$ZIP_ID" \
  --data-urlencode "acf[$KCAT][]=$TYPE_TERM" \
  --data-urlencode "acf[$KTOP][]=$TOPIC_TERM" \
  --data-urlencode "acf[$KCC]=BY NC SA (Credit given to creator, Non-Commercial only, Adaptations shared with same terms)" \
  --data-urlencode "acf[loothprint_video_instructions]=" \
  --data-urlencode "lg_fc_comments=closed" \
  "$URL")
echo "  HTTP $CODE"

echo
echo "=== 3. what actually landed in the store, and does the page build itself ==="
$WP eval '
  $p = get_page_by_title("'"$TITLE"'", OBJECT, "loothprint");
  if (!$p) { echo "  NO POST CREATED — the round trip FAILED\n"; exit(1); }
  printf("  post #%d  type=%s  status=%s  author=%d (%s)  comments=%s\n",
    $p->ID, $p->post_type, $p->post_status, $p->post_author,
    get_userdata($p->post_author)->user_login, $p->comment_status);

  $reads = ["post_content","loothprint_more_images","loothprint_3d_file",
            "loothprint_creative_commons"];
  $missing = 0;
  foreach ($reads as $k) {
    $v = ($k === "post_content") ? $p->post_content : get_field($k, $p->ID);
    $ok = !empty($v);
    if (!$ok) $missing++;
    printf("    %-30s %s  %s\n", $k, $ok ? "WRITTEN" : "EMPTY  ",
      substr(is_scalar($v) ? (string)$v : json_encode(is_array($v) && isset($v[0]["ID"]) ? array_column($v,"ID") : $v), 0, 44));
  }
  printf("    %-30s %s\n", "featured image (derived)",
    get_post_thumbnail_id($p->ID) ? "SET #".get_post_thumbnail_id($p->ID) : "NOT SET");
  printf("    %-30s %s\n", "loothprint_type terms", implode(",", wp_get_post_terms($p->ID,"loothprint_type",["fields"=>"names"])) ?: "none");

  // Static API — verified against lg-layout-v2/src/Plugin.php rather than
  // guessed. An earlier draft called ::instance()->get_layout(), which does not
  // exist; with stderr suppressed the fatal printed NOTHING and the run read as
  // "the synthesis section was skipped" rather than "the probe is broken".
  $layout = \LG\LayoutV2\Plugin::load_layout($p->ID);
  $blocks = [];
  foreach (($layout["blocks"] ?? []) as $b) $blocks[] = $b["type"] . (isset($b["variant"]) ? ":".$b["variant"] : "");
  printf("  synthesized %d blocks: %s\n", count($blocks), implode(", ", $blocks));
  printf("  manages(): %s\n", \LG\LayoutV2\Plugin::manages($p) ? "YES — v2 renders this page" : "NO");
  echo $missing ? "  ".$missing." field(s) the page-builder reads were NOT written\n"
                : "  fields the page-builder reads that the save path did NOT write: 0\n";
' 2>&1 | nowarn

echo
echo "=== 4. clean up, and prove it is gone ==="
$WP eval '
  $p = get_page_by_title("'"$TITLE"'", OBJECT, "loothprint");
  if ($p) { wp_delete_post($p->ID, true); echo "  force-deleted #".$p->ID."\n"; }
  $still = get_page_by_title("'"$TITLE"'", OBJECT, "loothprint");
  echo "  probe row surviving: " . ($still ? "YES — CLEAN UP BY HAND" : "none") . "\n";
' 2>/dev/null | nowarn
AFTER=$($WP eval 'global $wpdb; echo (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=\"loothprint\"");' 2>/dev/null | nowarn)
echo "  loothprint rows before=$BEFORE after=$AFTER (must match)"
