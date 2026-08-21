<?php
/**
 * compose-limits-probe.php — the assertion body of gate 88, run INSIDE a real
 * WordPress that is loading the BRANCH's lg-frontend-compose.php.
 *
 * Prints one `R|<id>|PASS|FAIL|<detail>` line per assertion; the python gate
 * scores them. Everything it creates is keyed to the run tag and destroyed in the
 * teardown at the bottom, whatever happens above it.
 *
 * ⚠️ IT ASSERTS BEHAVIOUR, NEVER A SETTING. "the field carries max_size 64" is the
 * assertion that would have passed on this feature's actual defect — ACF's own
 * validator never runs on this form (see lg_fc_upload_prefilter's header). So the
 * size legs push a real file array through the real filter and read the refusal.
 */

$TAG = getenv('LG186_TAG') ?: 'notag';
$OUT = [];
function R(string $id, bool $ok, string $detail = ''): void {
    printf("R|%s|%s|%s\n", $id, $ok ? 'PASS' : 'FAIL', $detail);
}

/* ── §0 WHICH FILE IS UNDER TEST, AND IS THE FEATURE EVEN LOADED ───────────────
   A gate that cannot say which file it measured is a gate that measured main. */
if (!function_exists('lg_fc_upload_prefilter')) {
    R('0.loaded', false, 'lg_fc_upload_prefilter does not exist — the branch mu-plugin was not loaded at all');
    return;
}
$rf   = new ReflectionFunction('lg_fc_upload_prefilter');
$file = $rf->getFileName();
R('0.loaded', true, 'under test: ' . $file);
$want = getenv('LG186_PLUGIN');
R('0.branch', $want && realpath($file) === realpath($want),
  'expected ' . ($want ?: '(unset)'));

$ON = lg_fc_enabled();
R('0.flagstate', true, 'lg_fc_enabled() = ' . ($ON ? 'ON' : 'OFF'));

$LIM = lg_fc_limits();
R('0.numbers', $LIM['photos'] === 10 && $LIM['photo_b'] === 10485760 && $LIM['file_b'] === 67108864,
  sprintf('photos=%d photo_b=%d file_b=%d', $LIM['photos'], $LIM['photo_b'], $LIM['file_b']));

/* ── the per-run fixtures ─────────────────────────────────────────────────────
   PID-keyed, never a fixed account or post: two gates running at once must not
   see each other's rows (feedback-gate-probe-must-be-per-run). */
$user = get_user_by('login', 'lg186probe-' . $TAG);
if (!$user) {
    $uid = wp_insert_user([
        'user_login' => 'lg186probe-' . $TAG,
        'user_pass'  => wp_generate_password(24),
        'user_email' => 'lg186probe-' . $TAG . '@example.invalid',
        'role'       => 'looth1',
    ]);
    $user = is_wp_error($uid) ? null : get_userdata($uid);
}
if (!$user) { R('0.fixture', false, 'could not make the per-run member'); return; }

$POST = wp_insert_post([
    'post_type' => 'loothprint', 'post_status' => 'draft',
    'post_title' => 'lg186 probe ' . $TAG, 'post_author' => $user->ID,
]);
$OTHER = wp_insert_post([
    'post_type' => 'loothprint', 'post_status' => 'draft',
    'post_title' => 'lg186 other ' . $TAG, 'post_author' => $user->ID,
]);
R('0.fixture', $POST > 0 && $OTHER > 0, "post=$POST other=$OTHER member=" . $user->ID);

/* Make a real file on disk and a real attachment row parented to $parent.
 *
 * ⚠️ $stamp = false HAS TO FIGHT THE REAL HOOK, and the gate caught this on its
 * first run rather than my noticing it. wp_insert_attachment() fires
 * `add_attachment`, which is where lg_fc_stamp_upload() lives — and because the
 * size legs above leave $_REQUEST['post_id'] pointing at our post, a file this
 * probe meant to create WITHOUT a stamp was born with one. The "legacy file is
 * unreachable" assertion then failed for a reason that had nothing to do with the
 * collector. So the unstamped case clears the request context for the duration of
 * the insert AND deletes any stamp afterwards: the state under test is "a file
 * that predates this feature", and it has to actually be that. */
function lg186_att(int $parent, string $name, string $mime, bool $stamp): int {
    $up  = wp_get_upload_dir();
    $sub = '/lg186/';
    @mkdir($up['basedir'] . $sub, 0755, true);
    $rel = ltrim($sub, '/') . $name;
    file_put_contents($up['basedir'] . '/' . $rel, str_repeat('x', 64));

    $held = $_REQUEST['post_id'] ?? null;
    if (!$stamp) { unset($_REQUEST['post_id']); }
    $id = wp_insert_attachment([
        'post_title'     => $name,
        'post_mime_type' => $mime,
        'post_status'    => 'inherit',
    ], $up['basedir'] . '/' . $rel, $parent);
    if ($held !== null) { $_REQUEST['post_id'] = $held; } else { unset($_REQUEST['post_id']); }

    update_post_meta($id, '_wp_attached_file', $rel);
    if ($stamp) { update_post_meta($id, LG_FC_UPLOAD_STAMP, $parent); }
    else        { delete_post_meta($id, LG_FC_UPLOAD_STAMP); }
    return (int) $id;
}

/* ── §A SIZE, PROVED BY EXCEEDING IT ──────────────────────────────────────────
   Pushed through wp_handle_sideload_prefilter, which is the hook the Big File
   Uploads chunker actually reaches — NOT wp_handle_upload_prefilter, which is the
   one ACF listens on and the one this form never fires. */
$_REQUEST['post_id'] = $POST;

function lg186_prefilter(int $bytes, string $mime, string $hook): array {
    return apply_filters($hook, [
        'name' => 'probe', 'type' => $mime, 'tmp_name' => '/dev/null',
        'error' => 0, 'size' => $bytes,
    ]);
}
foreach (['wp_handle_sideload_prefilter' => 'sideload', 'wp_handle_upload_prefilter' => 'upload'] as $hook => $lbl) {
    $big = lg186_prefilter(11 * 1024 * 1024, 'image/jpeg', $hook);
    $ok  = lg186_prefilter(9 * 1024 * 1024, 'image/jpeg', $hook);
    if ($ON) {
        R("A.photo.$lbl.refused", !empty($big['error']), (string) ($big['error'] ?? '(none)'));
        R("A.photo.$lbl.says10", !empty($big['error']) && strpos((string) $big['error'], '10MB') !== false,
          'the refusal must name the limit');
        R("A.photo.$lbl.under_ok", empty($ok['error']), (string) ($ok['error'] ?? 'passed'));
    } else {
        R("A.photo.$lbl.off_inert", empty($big['error']),
          'flag OFF must not refuse: ' . (string) ($big['error'] ?? 'passed'));
    }
}
$bigzip = lg186_prefilter(65 * 1024 * 1024, 'application/zip', 'wp_handle_sideload_prefilter');
$okzip  = lg186_prefilter(60 * 1024 * 1024, 'application/zip', 'wp_handle_sideload_prefilter');
if ($ON) {
    R('A.file.refused', !empty($bigzip['error']), (string) ($bigzip['error'] ?? '(none)'));
    R('A.file.says64', !empty($bigzip['error']) && strpos((string) $bigzip['error'], '64MB') !== false, '');
    R('A.file.under_ok', empty($okzip['error']), (string) ($okzip['error'] ?? 'passed'));
    /* An STL is NOT refused: members upload bare STLs today (48 of them) and this
       lane was asked for a count and a size, not a type change. */
    $stl = lg186_prefilter(1024, 'application/octet-stream', 'wp_handle_sideload_prefilter');
    R('A.file.stl_allowed', empty($stl['error']), 'this lane must not silently start refusing STLs');
} else {
    R('A.file.off_inert', empty($bigzip['error']), 'flag OFF must not refuse');
}

/* An upload aimed at a post that is NOT ours must be untouched in either state. */
$_REQUEST['post_id'] = 0;
$foreign = lg186_prefilter(900 * 1024 * 1024, 'image/jpeg', 'wp_handle_sideload_prefilter');
R('A.scope.foreign_untouched', empty($foreign['error']),
  'a 900MB upload outside our post types must pass: ' . (string) ($foreign['error'] ?? 'passed'));
$_REQUEST['post_id'] = $POST;

/* ── §B THE PHOTO COUNT, SERVER-SIDE ─────────────────────────────────────────
   ACF's gallery validate_value checks min and NEVER max, so this is the only
   thing between an eleven-photo submission and the database. */
$fld = ['label' => 'Show it off', '_name' => 'loothprint_more_images', 'key' => 'k', 'type' => 'gallery', 'required' => 1];
$v11 = apply_filters('acf/validate_value/name=loothprint_more_images', true, array_fill(0, 11, 7), $fld, 'x');
$v10 = apply_filters('acf/validate_value/name=loothprint_more_images', true, array_fill(0, 10, 7), $fld, 'x');
if ($ON) {
    R('B.count.11_refused', is_string($v11), var_export($v11, true));
    R('B.count.says10', is_string($v11) && strpos($v11, '10') !== false, (string) $v11);
    R('B.count.10_ok', $v10 === true, var_export($v10, true));
} else {
    R('B.count.off_inert', $v11 === true, 'flag OFF must not refuse');
}

/* ── §C THE WRITE-UP IS REQUIRED ──────────────────────────────────────────────
   <p></p> is the case ACF's own required check PASSES, because it is a non-empty
   string. If this leg ever goes green on it, the field is required in name only. */
$cf = ['label' => 'Tell people about it', '_name' => '_post_content', 'key' => '_post_content', 'type' => 'wysiwyg', 'required' => 1];
$cases = ['empty' => '', 'p_tags' => '<p></p>', 'nbsp' => '<p>&nbsp;</p>', 'br' => '<p><br /></p>'];
foreach ($cases as $lbl => $val) {
    $r = apply_filters('acf/validate_value/name=_post_content', true, $val, $cf, 'x');
    if ($ON) { R("C.writeup.$lbl.refused", is_string($r), var_export($r, true)); }
    else     { R("C.writeup.$lbl.off_inert", $r === true, 'flag OFF must not refuse'); }
}
$real = apply_filters('acf/validate_value/name=_post_content', true, '<p>It holds a fret rocker.</p>', $cf, 'x');
R('C.writeup.real_ok', $real === true, var_export($real, true));

/* ── §D THE STAMP AND THE COLLECTOR — keeper's evidence items 2 and 3 ─────────
   Every reference kind gets its own file. If any of these is collected, the scan
   is wrong and a member has lost a file. */
$A = [];
$A['gallery']   = lg186_att($POST,  "g-$TAG.jpg",   'image/jpeg', true);
$A['zipfield']  = lg186_att($POST,  "z-$TAG.zip",   'application/zip', true);
$A['thumb']     = lg186_att($POST,  "t-$TAG.jpg",   'image/jpeg', true);
$A['repeater']  = lg186_att($POST,  "r-$TAG.jpg",   'image/jpeg', true);
$A['inbody']    = lg186_att($POST,  "b-$TAG.jpg",   'image/jpeg', true);
$A['layout']    = lg186_att($POST,  "l-$TAG.jpg",   'image/jpeg', true);
$A['othersthumb'] = lg186_att($POST, "o-$TAG.jpg",  'image/jpeg', true);
$A['unused']    = lg186_att($POST,  "u-$TAG.zip",   'application/zip', true);
$A['unstamped'] = lg186_att($POST,  "n-$TAG.zip",   'application/zip', false);

/* THE STAMP ITSELF, asserted both ways — it is the only thing standing between
   the collector and every legacy file on the site, so "it fires" and "it does not
   fire outside our posts" are both worth stating. */
R('D.stamp.applied', (int) get_post_meta($A['gallery'], LG_FC_UPLOAD_STAMP, true) === $POST,
  'an upload into our post must carry the stamp');
R('D.stamp.absent_outside', get_post_meta($A['unstamped'], LG_FC_UPLOAD_STAMP, true) === '',
  'an attachment created outside the upload context must NOT be stamped');

update_post_meta($POST, 'loothprint_more_images', [(string) $A['gallery']]);
update_post_meta($POST, 'loothprint_3d_file', (string) $A['zipfield']);
update_post_meta($POST, '_thumbnail_id', (string) $A['thumb']);
/* THE ONE THAT WAS FOUND THE HARD WAY. This repeater row is a reference kind the
   first scanner did not know existed; it is here because it is the regression
   test that matters most. */
update_post_meta($POST, 'post_related_links_repeater_0_related_link_image', (string) $A['repeater']);
update_post_meta($POST, '_lg_layout_v2', serialize([
    'schema' => 1,
    'blocks' => [['type' => 'gallery', 'id' => 'g', 'image_ids' => [$A['layout']]]],
]));
update_post_meta($OTHER, '_thumbnail_id', (string) $A['othersthumb']);
wp_update_post(['ID' => $POST, 'post_content' => '<p>see <img src="/wp-content/uploads/lg186/b-' . $TAG . '.jpg"></p>']);

$gone = lg_fc_collect_unused($POST);
foreach ($A as $kind => $id) {
    $alive = (bool) get_post($id);
    if ($kind === 'unused') {
        R('D.collect.unused_gone', $ON ? !$alive : $alive,
          $ON ? 'a stamped, unreferenced upload must be collected' : 'flag OFF must collect nothing');
    } elseif ($kind === 'unstamped') {
        R('D.keep.unstamped', $alive, 'an UNSTAMPED file must never be collected — this is what protects every legacy attachment');
    } else {
        R('D.keep.' . $kind, $alive, 'referenced via ' . $kind . ' — must survive publish');
    }
}
R('D.collect.count', $ON ? ($gone === 1) : ($gone === 0), "collector removed $gone");

/* ── §E THE 36-POST GUARANTEE, as an assertion rather than a memory ───────────
   Run read-only over the REAL corpus: no attachment on any pre-existing post
   carries a stamp, so the collector cannot reach one however it is called. */
global $wpdb;
$strays = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} m
     JOIN {$wpdb->posts} a ON a.ID = m.post_id AND a.post_type = 'attachment'
     WHERE m.meta_key = %s AND a.post_parent NOT IN (%d, %d)",
    LG_FC_UPLOAD_STAMP, $POST, $OTHER));
R('E.legacy_unreachable', $strays === 0,
  "$strays stamped attachment(s) outside this run's fixtures — every one is a file the collector could delete");

/* ── §F TRASH IS NOT DELETION ────────────────────────────────────────────────
   The explicit ruling, asserted: a member's undo must still have its files. */
$T  = wp_insert_post(['post_type' => 'loothprint', 'post_status' => 'publish',
                      'post_title' => 'lg186 trash ' . $TAG, 'post_author' => $user->ID]);
$TA = lg186_att($T, "trash-$TAG.zip", 'application/zip', true);
wp_trash_post($T);
R('F.trash_keeps_files', (bool) get_post($TA), 'trashing must not destroy a file — the bin is the undo');
wp_untrash_post($T);
R('F.untrash_restores', (bool) get_post($TA), 'and the restored post still has it');
wp_delete_post($T, true);
R('F.delete_takes_files', $ON ? !get_post($TA) : (bool) get_post($TA),
  $ON ? 'permanent delete must take the files' : 'flag OFF must leave them');

/* ── teardown ────────────────────────────────────────────────────────────────
   Runs whatever happened above. A probe that leaves rows behind poisons the next
   run's §E, which would then blame the feature for the harness's mess. */
foreach (array_merge(array_values($A), [$TA]) as $id) {
    if (get_post($id)) { wp_delete_attachment((int) $id, true); }
}
foreach ([$POST, $OTHER, $T] as $p) {
    if ($p && get_post($p)) {
        foreach (get_children(['post_parent' => $p, 'post_type' => 'attachment',
                               'numberposts' => -1, 'fields' => 'ids']) as $c) {
            wp_delete_attachment((int) $c, true);
        }
        wp_delete_post($p, true);
    }
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($user->ID);
$left = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", LG_FC_UPLOAD_STAMP));
R('Z.teardown_clean', $left === 0, "$left stamped row(s) left behind");
