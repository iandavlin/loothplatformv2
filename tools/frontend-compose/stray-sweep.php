<?php
/**
 * stray-sweep.php — WHAT IS ALREADY ORPHANED, counted. It deletes nothing unless
 * two explicit flags say so, and it is deliberately NOT wired to a schedule.
 *
 *   sudo -u looth-dev wp --path=/var/www/dev eval-file tools/frontend-compose/stray-sweep.php
 *
 * #186. Ian asked for "in and out" — the live half of that is
 * lg_fc_collect_unused(), which is stamp-scoped and can only ever touch files the
 * compose form itself uploaded. This tool is the OTHER half: the backlog that
 * predates the stamp, which no automatic rule may touch because deleting a
 * member's file is irreversible and these files belong to work that is already
 * published.
 *
 * ⚠️ CATEGORY B IS THE DANGEROUS ONE AND IT IS WHY THIS IS A REPORT. Run
 * unrestricted, the "delete what the post does not use" rule wants to remove 67
 * attachments across 36 HEALTHY PUBLISHED loothprints. They really are unused —
 * one post carries six superseded FretSander zips — but that is a decision about
 * other people's work, not a cleanup a lane may take on its own authority.
 *
 * TO ACTUALLY DELETE, both of these must be set, and only ever with Ian's word:
 *   LG186_APPLY=orphans   the parent post no longer exists
 *   LG186_APPLY=unused    parented to a loothprint that does not reference it
 *   LG186_CONFIRM=yes
 *
 * The reference test is NOT reimplemented here: it calls the very functions the
 * live collector uses, so this report and the running rule can never drift apart.
 */

global $wpdb;

$APPLY   = getenv('LG186_APPLY') ?: '';
$CONFIRM = getenv('LG186_CONFIRM') === 'yes';
$DO      = ($APPLY !== '' && $CONFIRM);

if (!function_exists('lg_fc_referenced_ids')) {
    echo "CANNOT RUN: lg-frontend-compose.php is not loaded, so the reference test\n"
       . "            this report depends on does not exist. Category B would be\n"
       . "            computed by a DIFFERENT rule from the live one, which is worse\n"
       . "            than not computing it.\n";
    return;
}
if ($APPLY !== '' && !$CONFIRM) {
    echo "REFUSED: LG186_APPLY is set but LG186_CONFIRM is not 'yes'. Nothing done.\n\n";
}

echo "STRAY SWEEP — " . ($DO ? "APPLYING '$APPLY'" : "DRY RUN, nothing will be deleted") . "\n";
echo str_repeat('=', 72) . "\n\n";

/* ── CATEGORY A: the parent post is gone ──────────────────────────────────────
   ⚠️ A MISSING PARENT IS NOT PROOF THE FILE IS UNUSED. An attachment whose post
   was deleted can still be embedded in somebody else's page, and deleting it
   would break a live article. Each one is checked against every other post
   before it is counted as safe. */
$orphans = $wpdb->get_col(
    "SELECT a.ID FROM {$wpdb->posts} a
     WHERE a.post_type = 'attachment' AND a.post_parent <> 0
       AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = a.post_parent)");

$safe_a = []; $held_a = [];
foreach ($orphans as $aid) {
    $aid  = (int) $aid;
    $file = (string) get_post_meta($aid, '_wp_attached_file', true);
    $stem = $file !== '' ? preg_replace('/\.[a-z0-9]+$/i', '', basename($file)) : '';
    $used = false;
    if ($stem !== '') {
        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type <> 'attachment' AND post_status <> 'trash'
               AND post_content LIKE %s", '%' . $wpdb->esc_like($stem) . '%')) > 0;
    }
    if (!$used) {
        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value = %d", $aid)) > 0;
    }
    if ($used) { $held_a[] = $aid; } else { $safe_a[] = [$aid, $file]; }
}

printf("CATEGORY A — the post they belonged to no longer exists\n");
printf("  found ................ %d\n", count($orphans));
printf("  still used elsewhere . %d  (HELD BACK — deleting these would break a live page)\n", count($held_a));
printf("  safe to remove ....... %d\n\n", count($safe_a));
foreach (array_slice($safe_a, 0, 10) as [$id, $f]) printf("    #%-7d %s\n", $id, $f ?: '(no file)');
if (count($safe_a) > 10) printf("    … and %d more\n", count($safe_a) - 10);
echo "\n";

/* ── CATEGORY B: parented to one of our posts, which does not reference it ──── */
$types  = array_keys(lg_fc_types());
$in     = "'" . implode("','", array_map('esc_sql', $types)) . "'";
$posts  = $wpdb->get_col("SELECT ID FROM {$wpdb->posts}
                          WHERE post_type IN ($in) AND post_status NOT IN ('auto-draft','inherit')");
$safe_b = []; $by_post = [];
foreach ($posts as $pid) {
    $pid  = (int) $pid;
    $atts = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_parent=%d", $pid));
    if (!$atts) continue;
    $refs = lg_fc_referenced_ids($pid);
    $text = null;
    foreach ($atts as $aid) {
        $aid = (int) $aid;
        if (isset($refs[$aid])) continue;
        if ($text === null) $text = lg_fc_referenced_text($pid);
        $file = (string) get_post_meta($aid, '_wp_attached_file', true);
        $stem = $file !== '' ? preg_replace('/\.[a-z0-9]+$/i', '', basename($file)) : '';
        if (($stem !== '' && strpos($text, $stem) !== false)
            || preg_match('/\b' . $aid . '\b/', $text)) continue;
        if ((int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta}
                 WHERE meta_key='_thumbnail_id' AND meta_value=%d AND post_id<>%d", $aid, $pid)) > 0) continue;
        $safe_b[] = [$aid, $file, $pid];
        $by_post[$pid] = ($by_post[$pid] ?? 0) + 1;
    }
}
printf("CATEGORY B — parented to a %s that does not use them\n", implode('/', $types));
printf("  found ................ %d, across %d posts\n", count($safe_b), count($by_post));
printf("  ⚠️ these sit on posts that are PUBLISHED and healthy. Ian's call, not a lane's.\n\n");
arsort($by_post);
foreach (array_slice($by_post, 0, 8, true) as $pid => $n)
    printf("    post %-7d %2d unused   %s\n", $pid, $n, get_the_title($pid));
if (count($by_post) > 8) printf("    … and %d more posts\n", count($by_post) - 8);

/* ── the only place anything is deleted ───────────────────────────────────── */
echo "\n" . str_repeat('=', 72) . "\n";
if (!$DO) {
    printf("DRY RUN. Nothing was deleted. Would remove: %d (A) + %d (B) = %d files.\n",
           count($safe_a), count($safe_b), count($safe_a) + count($safe_b));
    return;
}
$list = $APPLY === 'orphans' ? array_column($safe_a, 0)
      : ($APPLY === 'unused' ? array_column($safe_b, 0) : null);
if ($list === null) { echo "REFUSED: LG186_APPLY must be 'orphans' or 'unused'.\n"; return; }
$gone = 0;
foreach ($list as $aid) { if (wp_delete_attachment((int) $aid, true)) $gone++; }
printf("DELETED %d of %d in category '%s'.\n", $gone, count($list), $APPLY);
