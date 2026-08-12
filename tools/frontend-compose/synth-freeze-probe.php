<?php
/**
 * synth-freeze-probe.php — does editing a synthesized loothprint's PAGE freeze it
 * away from its FIELDS?
 *
 * Run: sudo -n wp --allow-root --path=/var/www/dev eval-file tools/frontend-compose/synth-freeze-probe.php
 *
 * WHY THIS MATTERS TO THE EDIT SLICE. Ian asked for front-end EDIT of a member's
 * own loothprints, which this lane built at /compose/?id=. But managed singles
 * ALREADY carry an "Edit page" button (FeEditor::render_header_button), shown to
 * the post's author, which opens the layout-v2 block editor and saves through
 * EditorRest.php:290 -> update_post_meta(_lg_layout_v2).
 *
 * And Plugin::load_layout() says explicit meta WINS over synthesis. A loothprint's
 * page is synthesized from its fields, so if the page editor writes a blob, the
 * page should stop tracking the fields — and every later edit through the form
 * would appear to do nothing. That is the "UI lies" class, arrived at from the
 * other direction.
 *
 * Asserted on a throwaway DRAFT, never published, force-deleted at the end.
 */
if (!defined('ABSPATH')) { fwrite(STDERR, "must run under wp eval-file\n"); exit(2); }
function tally(?bool $ok=null): array { static $p=0,$f=0; if($ok!==null){$ok?$p++:$f++;} return [$p,$f]; }
function ck(string $what,$got,$want): void {
    $ok = $got === $want; tally($ok);
    printf("  %-4s %-56s got=%-9s want=%s\n", $ok?'PASS':'FAIL', $what, var_export($got,true), var_export($want,true));
}

$g=null; foreach (acf_get_field_groups() as $x) if ($x['title']==='Add Post - Loothprints') $g=$x;
if(!$g){echo "CANNOT RUN: field group missing\n";exit(2);}
$keys=[]; foreach(acf_get_fields($g['key']) as $f) $keys[$f['name']]=$f['key'];
$img=get_posts(['post_type'=>'attachment','posts_per_page'=>1,'post_mime_type'=>'image/jpeg','fields'=>'ids']);

$pid = wp_insert_post(['post_type'=>'loothprint','post_status'=>'draft',
                       'post_title'=>'LGFC-SYNTHFREEZE-PROBE','post_author'=>1,
                       'post_content'=>'ORIGINAL BODY']);
if(!$pid||is_wp_error($pid)){echo "CANNOT RUN: no draft\n";exit(2);}
update_field($keys['loothprint_more_images'], [(int)$img[0]], $pid);
printf("probe draft #%d (draft, never published)\n\n", $pid);

echo "-- 1. before any page edit: the layout is SYNTHESIZED from the fields --\n";
$l = \LG\LayoutV2\Plugin::load_layout($pid);
$blocks = array_map(fn($b)=>$b['type']??'?', $l['blocks']??[]);
printf("     blocks: %s\n", implode(', ', $blocks) ?: '(none)');
ck('has a wysiwyg block built from post_content', in_array('wysiwyg',$blocks,true), true);
ck('no _lg_layout_v2 meta stored yet', empty(get_post_meta($pid, LG_LAYOUT_V2_META_KEY, true)), true);

echo "\n-- 2. the FIELDS still drive it: change the body, the layout follows --\n";
wp_update_post(['ID'=>$pid,'post_content'=>'CHANGED BODY']);
$l = \LG\LayoutV2\Plugin::load_layout($pid);
$body = '';
foreach (($l['blocks']??[]) as $b) if (($b['type']??'')==='wysiwyg') $body = (string)($b['html'] ?? $b['content'] ?? '');
ck('layout reflects the NEW body', strpos($body,'CHANGED BODY')!==false, true);

echo "\n-- 3. now simulate the PAGE editor saving (EditorRest.php:290) --\n";
$frozen = ['blocks'=>[['type'=>'wysiwyg','html'=>'<p>FROZEN BY THE PAGE EDITOR</p>']]];
update_post_meta($pid, LG_LAYOUT_V2_META_KEY, wp_slash(wp_json_encode($frozen)));
$l = \LG\LayoutV2\Plugin::load_layout($pid);
$blocks = array_map(fn($b)=>$b['type']??'?', $l['blocks']??[]);
printf("     blocks now: %s\n", implode(', ', $blocks) ?: '(none)');
ck('the stored blob WINS over synthesis', count($blocks), 1);

echo "\n-- 4. THE POINT: change a field again — does the page still follow? --\n";
wp_update_post(['ID'=>$pid,'post_content'=>'CHANGED AGAIN, AFTER THE FREEZE']);
$l = \LG\LayoutV2\Plugin::load_layout($pid);
$body=''; foreach (($l['blocks']??[]) as $b) if (($b['type']??'')==='wysiwyg') $body=(string)($b['html'] ?? $b['content'] ?? '');
$follows = strpos($body,'CHANGED AGAIN')!==false;
ck('field edits STILL reach the page after a page edit', $follows, true);
printf("     rendered body is now: %s\n", trim(strip_tags($body)));

wp_delete_post($pid, true);
printf("\nprobe force-deleted; surviving: %s\n", get_post($pid)?'YES — CLEAN BY HAND':'none');
[$p,$f]=tally();
printf("\n%s  pass=%d fail=%d\n", $f?'SYNTH-FREEZE PROBE: HAZARD CONFIRMED':'SYNTH-FREEZE PROBE: no hazard', $p, $f);
exit(0);
