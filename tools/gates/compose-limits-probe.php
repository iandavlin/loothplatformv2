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
R('0.numbers', $LIM['photos'] === 10 && $LIM['photo_b'] === 10485760 && $LIM['file_b'] === 134217728,
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
$bigzip = lg186_prefilter(129 * 1024 * 1024, 'application/zip', 'wp_handle_sideload_prefilter');
$okzip  = lg186_prefilter(120 * 1024 * 1024, 'application/zip', 'wp_handle_sideload_prefilter');
if ($ON) {
    R('A.file.refused', !empty($bigzip['error']), (string) ($bigzip['error'] ?? '(none)'));
    R('A.file.says128', !empty($bigzip['error']) && strpos((string) $bigzip['error'], '128MB') !== false, '');
    /* ⚠️ 65MB MUST NOW PASS. It is over FPM's 64M upload_max_filesize, which is
       exactly the number Ian was first given as a hard ceiling and which the
       chunker walks straight past. If this ever reddens, someone has "restored"
       a limit the box does not actually impose. */
    $sixtyfive = lg186_prefilter(65 * 1024 * 1024, 'application/zip', 'wp_handle_sideload_prefilter');
    R('A.file.65mb_passes', empty($sixtyfive['error']),
      'over FPM 64M but under Ian\'s 128MB — the chunker makes this legal');
    R('A.file.under_ok', empty($okzip['error']), (string) ($okzip['error'] ?? 'passed'));
    /* An STL is NOT refused: members upload bare STLs today (48 of them) and this
       lane was asked for a count and a size, not a type change. */
    $stl = lg186_prefilter(1024, 'application/octet-stream', 'wp_handle_sideload_prefilter');
    R('A.file.stl_allowed', empty($stl['error']), 'this lane must not silently start refusing STLs');
} else {
    R('A.file.off_inert', empty($bigzip['error']), 'flag OFF must not refuse');
}

/* ── §G REFUSED BEFORE THE BYTES, NOT AFTER ──────────────────────────────────
   wp-content/uploads is a SYMLINK to an rclone FUSE mount of Cloudflare R2, and
   the chunker's spool (wp-content/bfu-temp) is the box's ROOT DISK — measured at
   84% used with 4.6G free, against a 5GB effective member limit. The prefilter
   above refuses only on the LAST chunk, once the whole file is already assembled
   locally: perfect for R2 (not one byte reaches the mount) and far too late for
   the spool. lg_fc_chunk_refusal() is the early decision, kept pure so it can be
   asserted here without a request. */
$cap_f = $LIM['file_b'];
$cap_p = $LIM['photo_b'];
R('G.early.first_chunk_over', lg_fc_chunk_refusal('big.zip', 0, $cap_f + 1) !== '',
  'a first chunk already over the cap must be refused outright');
R('G.early.accumulates', lg_fc_chunk_refusal('big.zip', $cap_f - 100, 200) !== '',
  'the cap is on the RUNNING TOTAL, not on one chunk');
R('G.early.under_passes', lg_fc_chunk_refusal('big.zip', $cap_f - 100, 50) === '',
  'a chunk that keeps the total under the cap must pass');
R('G.early.says_the_number', strpos(lg_fc_chunk_refusal('big.zip', 0, $cap_f + 1), '128MB') !== false,
  'the early refusal must name the limit too');
/* The cap is chosen from the NAME here, because plupload sends every chunk as
   application/octet-stream and a mime-based guess would put photos under the
   print-file cap. */
R('G.early.photo_by_extension', lg_fc_chunk_cap('shot.JPG') === $cap_p,
  'an image extension must take the photo cap, case-insensitively');
R('G.early.zip_by_extension', lg_fc_chunk_cap('print.zip') === $cap_f, '');
R('G.early.stl_by_extension', lg_fc_chunk_cap('thing.stl') === $cap_f,
  'a bare STL is a print file, not a photo');
/* A photo just over the PHOTO cap must be refused even though it is far under
   the file cap — the two caps must not collapse into one. */
R('G.early.photo_cap_distinct', lg_fc_chunk_refusal('shot.jpg', 0, $cap_p + 1) !== ''
    && lg_fc_chunk_refusal('print.zip', 0, $cap_p + 1) === '',
  'the photo cap and the print-file cap must stay separate');
/* ⚠️ AND IT MUST ACTUALLY BE HOOKED, EARLY. Everything above asserts the pure
   decision function; none of it would notice the add_action being deleted, or
   being registered at a late priority where BFU has already appended the chunk.
   has_action() returns the PRIORITY, so this asserts earliness, not just
   presence — the entire point of the guard is that it runs before the write. */
R('G.early.hooked_at_1', has_action('wp_ajax_bfu_chunker', 'lg_fc_chunk_guard') === 1,
  'must be registered on the chunker action at priority 1, before BFU appends: got '
  . var_export(has_action('wp_ajax_bfu_chunker', 'lg_fc_chunk_guard'), true));

/* ── §H THE GUARD ITSELF, RUN. ────────────────────────────────────────────────
   ⚠️ EVERYTHING IN §G IS ABOUT A PURE FUNCTION AND A HOOK NAME. None of it would
   notice lg_fc_chunk_guard() refusing a PERFECTLY LEGAL upload — and because it
   sits at priority 1 on the chunker's own action, a bug there does not degrade
   the limits, it breaks EVERY compose upload on the site. So the guard is
   actually executed, both ways.

   ⚠️ AND IT HAS TO BE RUN AS A LOGGED-IN MEMBER OR IT IS VACUOUS. The guard
   returns early for anyone without upload_files, so a probe running as uid 0
   would sail past "it did not refuse" having measured the auth check instead of
   the size. The capability is asserted FIRST, for exactly that reason. */
$who_was = get_current_user_id();
wp_set_current_user($user->ID);
R('H.liveness_member_can_upload', current_user_can('upload_files'),
  'without this the two assertions below would pass by refusing nothing for the wrong reason');

/* ⚠️ wp_send_json() ENDS IN A BARE `die` UNLESS wp_doing_ajax() IS TRUE, and no
   wp_die_* handler can catch that one. Filtering only the handlers left the ON run
   dying silently at this exact line — 56 assertions became 26 and the gate still
   said GREEN, because a probe that stops early emits fewer PASSes and no FAILs.
   That is what the Z.end sentinel below now exists to catch. Arming
   wp_doing_ajax sends wp_send_json down the wp_die() path instead, where the
   handler filter reaches it. */
$boom = static function () { return static function () { throw new RuntimeException('WPDIE'); }; };
add_filter('wp_doing_ajax', '__return_true', 99);
foreach (['wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler'] as $h) {
    add_filter($h, $boom, 99);
}
$run_guard = static function (int $size) {
    $_REQUEST['post_id'] = $GLOBALS['lg186_post'];
    $_REQUEST['name']    = 'guardprobe.zip';
    $_REQUEST['chunk']   = 0;
    $_FILES['async-upload'] = ['name' => 'guardprobe.zip', 'type' => 'application/octet-stream',
                               'tmp_name' => '/dev/null', 'error' => 0, 'size' => $size];
    ob_start();
    try { lg_fc_chunk_guard(); $out = 'returned'; }
    catch (Throwable $e) { $out = 'refused'; }
    ob_end_clean();
    unset($_FILES['async-upload']);
    return $out;
};
$GLOBALS['lg186_post'] = $POST;
$legal = $run_guard(2 * 1024 * 1024);
$over  = $run_guard($LIM['file_b'] + 1);
R('H.legal_upload_passes', $legal === 'returned',
  'a 2MB zip must go straight through the guard — got ' . $legal);
R('H.oversize_is_stopped', $ON ? ($over === 'refused') : ($over === 'returned'),
  ($ON ? 'an oversize first chunk must be stopped here' : 'flag OFF must not stop it') . ' — got ' . $over);
foreach (['wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler'] as $h) {
    remove_filter($h, $boom, 99);
}
remove_filter('wp_doing_ajax', '__return_true', 99);
wp_set_current_user($who_was);


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

/* ── §C2 IS THE VALIDATOR ACTUALLY WIRED INTO ACF? ────────────────────────────
   ⚠️ §B AND §C ABOVE ARE NOT ENOUGH ON THEIR OWN, and the distinction is the
   difference between a gate and a decoration. Calling apply_filters() with the
   hook name ourselves proves the CALLBACK refuses when called — it says nothing
   about whether ACF ever calls it. A filter registered on a mistyped hook, or
   scoped to a name that does not match $field['_name'], passes everything above
   and refuses NOTHING on a real submission. So this drives ACF's own dispatcher
   with a real $_POST payload: acf_validate_save_post() fires the action,
   ACF_Validation::acf_validate_save_post() reads $_POST['acf'], and
   acf_validate_values() resolves each field and applies the name filter.

   It also covers something worth knowing: `required` is set on the write-up at
   RENDER time (acf/prepare_field), and that hook does NOT run during validation —
   so ACF's own required check is absent here, and the refusal can only be coming
   from our own filter. */
$c2_att = lg186_att($POST, "c2-$TAG.jpg", 'image/jpeg', true);
$post_backup = $_POST ?? [];
foreach ([['<p></p>', 'empty'], ['<p>It holds a fret rocker.</p>', 'real']] as [$body, $lbl]) {
    acf_reset_validation_errors();
    $_POST['acf'] = [
        '_post_content'       => $body,
        'field_6547dafd3f5d6' => array_fill(0, 11, (string) $c2_att),
    ];
    acf_validate_save_post(false);
    $blob = (string) json_encode(acf_get_validation_errors());
    $hit_body  = strpos($blob, 'Tell people about it') !== false;
    $hit_count = strpos($blob, 'you can add up to 10') !== false;
    if ($lbl === 'empty') {
        R('C2.acf_reaches_writeup', $ON ? $hit_body : !$hit_body,
          "ACF's dispatcher must reach our refusal: " . substr($blob, 0, 200));
        R('C2.acf_reaches_count', $ON ? $hit_count : !$hit_count,
          "eleven photos through ACF's dispatcher: " . substr($blob, 0, 200));
    } else {
        R('C2.real_body_accepted', !$hit_body, 'a real write-up must raise no write-up error');
    }
}
acf_reset_validation_errors();
$_POST = $post_backup;
/* ⚠️ REMOVED BEFORE §D RUNS. It is stamped and unreferenced, so leaving it here
   would make the collector below take TWO files and D.collect.count would read 2
   — the probe inventing the very over-deletion it exists to rule out. */
wp_delete_attachment($c2_att, true);

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

/* ⚠️ THE GALLERY FIXTURE USES THE REAL SERIALIZED SHAPE, and that is the whole
   point of it. On this box post 61698 stores a:6:{i:61697;s:5:"69502";…} — the
   KEY is one attachment id and the VALUE is a different one, and only the value
   is a file the post uses. A fixture keyed 0,1,2 cannot tell a values-only walk
   from one that reads keys too: the red-first proved exactly that, with the
   keys-reading mutation staying GREEN against the tidy fixture. So the unused
   file's id is planted as the KEY here. Values-only leaves it collectable; a walk
   that reads keys wrongly preserves it and D.collect.unused_gone goes red. */
update_post_meta($POST, 'loothprint_more_images', [$A['unused'] => (string) $A['gallery']]);
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

/* ── §D2 IS THE COLLECTOR WIRED TO THE SAVE? ──────────────────────────────────
   Same gap as §C2, one layer down: §D calls lg_fc_collect_unused() directly, so
   it proves the RULE. It does not prove that pressing Post ever reaches it.
   The collection is deliberately deferred to `shutdown` (lg-article-materializer
   writes _lg_layout_v2 after the post is inserted, so a collector running inside
   the save decides "unused" against meta that is not finished being written), and
   WordPress registers shutdown_action_hook via register_shutdown_function in
   wp-settings.php:164 — which PHP runs even on ACF's redirect+exit.

   Asserted by COUNTING the shutdown callbacks either side of acf/save_post rather
   than by firing `shutdown` here, because firing it would run every other
   plugin's shutdown handler early inside a probe. */
$before_n = 0;
foreach (($GLOBALS['wp_filter']['shutdown']->callbacks ?? []) as $prio => $cbs) { $before_n += count($cbs); }
do_action('acf/save_post', $POST);
$after_n = 0;
foreach (($GLOBALS['wp_filter']['shutdown']->callbacks ?? []) as $prio => $cbs) { $after_n += count($cbs); }
R('D2.queued_on_save', $ON ? ($after_n === $before_n + 1) : ($after_n === $before_n),
  "shutdown callbacks $before_n -> $after_n on acf/save_post");
/* And only ONCE, however many times the save fires. */
do_action('acf/save_post', $POST);
$again_n = 0;
foreach (($GLOBALS['wp_filter']['shutdown']->callbacks ?? []) as $prio => $cbs) { $again_n += count($cbs); }
R('D2.queued_once', $again_n === $after_n, "a second save must not queue a second collection ($after_n -> $again_n)");

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

/* ⚠️ THE SENTINEL. It asserts nothing about the feature — it asserts that THIS
   FILE RAN TO THE END. A probe that dies half way emits fewer PASSes and zero
   FAILs, which scores as a clean green: exactly what happened when wp_send_json's
   bare `die` cut the flag-ON run from 56 assertions to 26 and the gate reported
   GREEN anyway. The gate now refuses to score a run that does not end here. */
R('Z.end', true, 'probe ran to completion');
