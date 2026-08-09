<?php
/**
 * clobber-probe.php — does an ACF edit form DESTROY the fields it does not render?
 *
 * Run: sudo -n wp --allow-root --path=/var/www/dev eval-file tools/frontend-compose/clobber-probe.php
 *
 * WHY THIS HAS TO BE ANSWERED BEFORE THE EDIT SLICE IS BUILT. The compose route is
 * CREATE-ONLY on purpose, and the plugin header says why: ACF was believed to have
 * the same shape as the BuddyBoss subscription defect —
 *
 *     "a field that is RENDERED and submitted empty is SAVED as empty. On an edit
 *      form, dropping a field from the field list — exactly what this design does
 *      with `featured_image` — would wipe it."
 *
 * Ian has now asked for front-end EDIT of a member's own loothprints. That takes
 * the structural immunity away, so the claim stops being background and becomes
 * the load-bearing safety question: our form deliberately omits `featured_image`
 * (the mock removes it), and a member's hero image is not ours to lose.
 *
 * TWO DIFFERENT HAZARDS ARE BEING CONFUSED IN THAT SENTENCE, and this probe
 * separates them, because the answer changes what the edit form has to do:
 *
 *   A. OMITTED — the field is not in the form at all, so nothing is posted for it.
 *   B. RENDERED-BUT-EMPTY — the field IS in the form and the member clears it.
 *
 * B is unambiguous and is the member's own intent. A is the one that decides
 * whether an edit form may show a subset of the fields. Asserted here on a real
 * throwaway post through ACF's real save handler, then force-deleted.
 */

if (!defined('ABSPATH')) { fwrite(STDERR, "must run under wp eval-file\n"); exit(2); }

function ck(string $what, $got, $want): void {
    static $n = 0;
    $ok = $got === $want;
    lgfc_tally($ok);
    printf("  %-4s %-58s got=%-22s want=%s\n", $ok ? 'PASS' : 'FAIL', $what,
        var_export($got, true), var_export($want, true));
}
function lgfc_tally(?bool $ok = null): array {
    // function-static, not `global` — wp eval-file runs in FUNCTION scope and a
    // global-based counter silently splits in two (recorded trap).
    static $p = 0, $f = 0;
    if ($ok !== null) { $ok ? $p++ : $f++; }
    return [$p, $f];
}

$group = null;
foreach (acf_get_field_groups() as $g) {
    if ($g['title'] === 'Add Post - Loothprints') { $group = $g; break; }
}
if (!$group) { echo "CANNOT RUN: loothprint field group not found\n"; exit(2); }
$keys = [];
foreach (acf_get_fields($group['key']) as $f) { $keys[$f['name']] = $f['key']; }

$img = get_posts(['post_type'=>'attachment','posts_per_page'=>2,'post_mime_type'=>'image/jpeg','fields'=>'ids']);
if (count($img) < 1) { echo "CANNOT RUN: no image attachment on the box\n"; exit(2); }

$pid = wp_insert_post([
    'post_type'   => 'loothprint',
    'post_status' => 'draft',           // never published — cannot reach the feed
    'post_title'  => 'LGFC-CLOBBER-PROBE',
    'post_author' => 1,
]);
if (!$pid || is_wp_error($pid)) { echo "CANNOT RUN: could not create the probe draft\n"; exit(2); }
printf("probe draft #%d created (draft, never published)\n\n", $pid);

// ── seed it the way a member's existing loothprint looks ─────────────────────
set_post_thumbnail($pid, (int) $img[0]);
update_field($keys['loothprint_more_images'], [(int) $img[0]], $pid);
update_field($keys['loothprint_creative_commons'], 'BY (Credit given to creator)', $pid);
update_field($keys['loothprint_onshape_link'], 'https://cad.onshape.com/SEEDED', $pid);

printf("seeded:  thumbnail=#%s  gallery=%s  licence=%s  onshape=%s\n\n",
    get_post_thumbnail_id($pid),
    json_encode(array_map(fn($a) => is_array($a) ? $a['ID'] : $a, (array) get_field('loothprint_more_images', $pid))),
    substr((string) get_field('loothprint_creative_commons', $pid), 0, 26),
    get_field('loothprint_onshape_link', $pid));

// ── HAZARD A: save an edit that OMITS most fields entirely ───────────────────
// Exactly what our field list does with featured_image. Only the licence is
// posted; the thumbnail, the gallery and the onshape link are not in $_POST at
// all, which is what "not rendered in the form" looks like to ACF.
echo "-- HAZARD A: a save that OMITS fields (they are not in the form) --\n";
$_POST['acf'] = [ $keys['loothprint_creative_commons'] => 'BY SA (Credit given to creator, Adaptations shared with same terms)' ];
acf_save_post($pid);
unset($_POST['acf']);

ck('featured image SURVIVES an omitting save', (int) get_post_thumbnail_id($pid), (int) $img[0]);
$g = (array) get_field('loothprint_more_images', $pid);
ck('gallery SURVIVES an omitting save', count($g), 1);
ck('onshape link SURVIVES an omitting save',
   (string) get_field('loothprint_onshape_link', $pid), 'https://cad.onshape.com/SEEDED');
ck('the field that WAS posted did change',
   substr((string) get_field('loothprint_creative_commons', $pid), 0, 5), 'BY SA');

// ── HAZARD B: the field IS rendered and submitted empty ──────────────────────
echo "\n-- HAZARD B: a save that RENDERS the field and posts it EMPTY --\n";
$_POST['acf'] = [ $keys['loothprint_onshape_link'] => '' ];
acf_save_post($pid);
unset($_POST['acf']);
ck('an emptied field IS cleared (the member meant it)',
   (string) get_field('loothprint_onshape_link', $pid), '');
ck('and an untouched field is STILL untouched', (int) get_post_thumbnail_id($pid), (int) $img[0]);

// ── clean up ─────────────────────────────────────────────────────────────────
wp_delete_post($pid, true);
printf("\nprobe draft force-deleted; surviving: %s\n", get_post($pid) ? 'YES — CLEAN BY HAND' : 'none');

[$p, $f] = lgfc_tally();
printf("\n%s  pass=%d fail=%d\n", $f ? 'CLOBBER PROBE RED' : 'CLOBBER PROBE GREEN', $p, $f);
echo $f
    ? "\n=> An edit form MUST render the complete field set, or explicitly preserve what it omits.\n"
    : "\n=> ACF only writes what is POSTED. An edit form may safely show a SUBSET of the\n"
    . "   fields; omission is not destruction. The destructive case is the member\n"
    . "   clearing a field that is on screen, which is their own intent.\n";
exit($f ? 1 : 0);
