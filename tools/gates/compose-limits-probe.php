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

/* ⚠️ THE MEMBER IS UNMADE EVEN IF THIS PROBE NEVER REACHES ITS TEARDOWN.
   #189 found two `lg186probe-*` accounts still on the box. Several assertions
   below `return` early on a bad fixture, and every one of those jumps straight
   past the teardown at the bottom. A leaked per-run account is not harmless: the
   whole point of keying fixtures to the PID is that two runs cannot see each
   other, and accounts that accumulate make a later run's counts wrong for
   reasons nobody can trace back to here. A shutdown function runs on every exit
   path, including the early ones — the teardown at the bottom still does the
   thorough job, and this is the floor beneath it. */
$LG186_UID = (int) $user->ID;
register_shutdown_function(static function () use ($LG186_UID) {
    if (!get_userdata($LG186_UID)) { return; }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($LG186_UID);
});

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
   Run read-only over the REAL corpus. The property being protected is that the
   collector can only ever reach files THIS FORM created, on the post it created
   them for — which is what puts the 65 historical leftovers on 36 published
   loothprints structurally out of its reach.

   ⚠️ THIS LEG USED TO REQUIRE ZERO STAMPED ROWS OUTSIDE THE RUN'S OWN FIXTURES,
   AND THAT WAS ONLY TRUE UNTIL SOMEBODY USED THE FORM. Measured 2026-08-21:
   eleven stamped attachments on ONE member's in-progress auto-draft, uploaded
   through the real compose form on the serve minutes earlier. Nothing was wrong
   — that is the feature working — but the gate called them "files the collector
   could delete" and went RED, which blocks every lane on a box where a member is
   composing. It is restated rather than loosened: a stamp must AGREE with the
   attachment's post_parent, and that parent must be one of the types this form
   composes. A stamp pointing anywhere else is a real defect and still fails. */
global $wpdb;
$types = array_map(static fn($t) => "'" . esc_sql($t) . "'", array_keys(lg_fc_types()));
$bad = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} m
     JOIN {$wpdb->posts} a ON a.ID = m.post_id AND a.post_type = 'attachment'
     LEFT JOIN {$wpdb->posts} p ON p.ID = a.post_parent
     WHERE m.meta_key = %s
       AND ( m.meta_value + 0 <> a.post_parent
             OR p.ID IS NULL
             OR p.post_type NOT IN (" . implode(',', $types) . ") )",
    LG_FC_UPLOAD_STAMP));
$live = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", LG_FC_UPLOAD_STAMP));
R('E.stamp_agrees_with_parent', $bad === 0,
  "$bad of $live stamped attachment(s) name a post that is not their own parent, or is "
  . "not a type this form composes — each of those is a file the collector could reach "
  . "on the wrong post");

/* AND THE OTHER HALF, which is the one the 36 posts depend on: nothing that
   predates this feature can carry a stamp, because a stamp is only ever written
   by lg_fc_stamp_upload() on add_attachment. Asserted on the corpus rather than
   remembered: every stamped row must be NEWER than the oldest loothprint. */
$legacy = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} m
     JOIN {$wpdb->posts} a ON a.ID = m.post_id AND a.post_type = 'attachment'
     WHERE m.meta_key = %s AND a.post_date_gmt < %s",
    LG_FC_UPLOAD_STAMP, '2026-08-21 00:00:00'));
R('E.legacy_unreachable', $legacy === 0,
  "$legacy stamped attachment(s) predate this feature — none can exist, because the stamp "
  . "is only ever written on add_attachment, and this is what keeps the 65 historical "
  . "leftovers on 36 published loothprints out of the collector's reach");

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


/* ══ §I #189 — THE FORM'S OWN UPLOADER, AND THE STORAGE MODEL IT MUST NOT MOVE ══
 *
 * The whole feature is a RENDER swap. So the assertions that matter are:
 * (1) the swap reaches the renderer and NOTHING else,
 * (2) the markup it produces decodes, through PHP's own form parser, into the
 *     exact value shape ACF stores today,
 * (3) the media modal is gone from the ENQUEUE, not merely unstyled,
 * (4) the write-up editor still gets TinyMCE, and
 * (5) the browser is still pointed at #186's chunker and no second route.
 *
 * Every one of these is a defect this lane actually made and had to find. The
 * gate exists so the next person finds them in a second, not in a screenshot.
 */

/* ── §I1 THE SWAP REACHES THE RENDERER ─────────────────────────────────────── */
$GLOBALS['lg_fc_editing'] = 0;
$fld_gal  = acf_get_field('loothprint_more_images');
$fld_file = acf_get_field('loothprint_3d_file');
if (!$fld_gal || !$fld_file) {
    R('I1.fields', false, 'the two upload fields do not resolve — nothing below can mean anything');
} else {
    R('I1.fields', true, $fld_gal['key'] . ' (' . $fld_gal['type'] . ') · '
                       . $fld_file['key'] . ' (' . $fld_file['type'] . ')');
    /* ⚠️ THROUGH acf_prepare_field(), NOT lg_fc_relabel() DIRECTLY, and the first
       version of this leg got it wrong. Calling our filter by hand proves the
       CALLBACK swaps a type; it proves nothing about the dispatch, and it leaves
       $field['name'] as the bare field name — acf_prepare_field() is what
       rewrites it to acf[<key>], so the §I2 decode came back NULL and looked
       like a markup bug. Driving ACF's own chain is the same choice §C2 made
       for validation, and for the same reason. */
    add_filter('acf/prepare_field', 'lg_fc_relabel', 20);
    $sw_gal  = acf_prepare_field($fld_gal);
    $sw_file = acf_prepare_field($fld_file);
    remove_filter('acf/prepare_field', 'lg_fc_relabel', 20);
    R('I1.name_is_acf_keyed', ($sw_gal['name'] ?? '') === 'acf[' . $fld_gal['key'] . ']',
      'the prepared input name is ' . var_export($sw_gal['name'] ?? null, true)
      . ' — this is what ACF\'s save handler reads');
    R('I1.gallery_becomes_ours', ($sw_gal['type'] ?? '') === 'lg_fc_photos',
      'prepare-time type: ' . ($sw_gal['type'] ?? '(none)'));
    R('I1.file_becomes_ours', ($sw_file['type'] ?? '') === 'lg_fc_printfile',
      'prepare-time type: ' . ($sw_file['type'] ?? '(none)'));

    /* ⚠️ AND THE STORED FIELD IS UNTOUCHED. This is the line between a UI swap and
       a storage change: validation and update load the field through
       acf/load_field, so a fresh read must still say gallery/file. If this ever
       goes red, ACF's own update_value is no longer running and the ids are being
       written by something else. */
    R('I1.stored_type_untouched',
      acf_get_field('loothprint_more_images')['type'] === 'gallery'
      && acf_get_field('loothprint_3d_file')['type'] === 'file',
      'a fresh acf_get_field still reads gallery/file — the swap is render-only');

    R('I1.renderers_hooked',
      has_action('acf/render_field/type=lg_fc_photos', 'lg_fc_render_photos') !== false
      && has_action('acf/render_field/type=lg_fc_printfile', 'lg_fc_render_printfile') !== false,
      'both renderers answer the type variations the swap dispatches to');
}

/* ── §I2 THE MARKUP DECODES TO TODAY'S VALUE SHAPE ──────────────────────────
   ⚠️ THIS IS THE LEG THAT PROTECTS THE FOUR THINGS THE ISSUE NAMES. It does not
   read the markup with a regex and hope: it feeds the rendered hidden inputs
   through parse_str(), which IS the decoder PHP uses on a real POST, and asserts
   the array that comes out.

   It caught a real bug in this lane's first build: the print-file tile posted
   `acf[key][]`. ACF's file update_value runs the value through acf_idval(), and
   acf_idval(['54773']) looks for an 'ID' key, finds none and returns 0 — so the
   field would have SAVED EMPTY while the tile on screen showed the file. */
$g1 = lg186_att($POST, "i2-a-$TAG.jpg", 'image/jpeg', true);
$g2 = lg186_att($POST, "i2-b-$TAG.jpg", 'image/jpeg', true);
$f1 = lg186_att($POST, "i2-c-$TAG.zip", 'application/zip', true);
$A['i2a'] = $g1; $A['i2b'] = $g2; $A['i2c'] = $f1;

function lg189_decode(string $html): array {
    /* Every hidden input in document order, turned into the query string a
       browser would send, then decoded by PHP itself. */
    if (!preg_match_all('/<input[^>]*type="hidden"[^>]*>/i', $html, $m)) { return []; }
    $pairs = [];
    foreach ($m[0] as $tag) {
        if (stripos($tag, 'disabled') !== false) { continue; }
        preg_match('/\bname="([^"]*)"/i', $tag, $n);
        preg_match('/\bvalue="([^"]*)"/i', $tag, $v);
        if (!isset($n[1])) { continue; }
        $pairs[] = urlencode(html_entity_decode($n[1])) . '=' . urlencode(html_entity_decode($v[1] ?? ''));
    }
    parse_str(implode('&', $pairs), $out);
    return $out;
}

function lg189_prepare(array $f): array {
    add_filter('acf/prepare_field', 'lg_fc_relabel', 20);
    $p = acf_prepare_field($f);
    remove_filter('acf/prepare_field', 'lg_fc_relabel', 20);
    return $p;
}
$fld_gal['value'] = [$g1, $g2];
ob_start(); lg_fc_render_photos(lg189_prepare($fld_gal)); $html_gal = ob_get_clean();
$dec_gal = lg189_decode($html_gal);
$got_gal = $dec_gal['acf'][$fld_gal['key']] ?? null;
R('I2.gallery_decodes_to_a_list', $got_gal === [(string) $g1, (string) $g2],
  'parse_str over the rendered inputs gives ' . var_export($got_gal, true)
  . ' (want the two ids, in order)');

$fld_file['value'] = $f1;
ob_start(); lg_fc_render_printfile(lg189_prepare($fld_file)); $html_file = ob_get_clean();
$dec_file = lg189_decode($html_file);
$got_file = $dec_file['acf'][$fld_file['key']] ?? null;
R('I2.printfile_decodes_to_a_SCALAR', $got_file === (string) $f1,
  'parse_str gives ' . var_export($got_file, true) . ' — an ARRAY here means acf_idval() '
  . 'returns 0 and the field saves EMPTY while the tile shows the file');

/* An emptied control must still POST, or ACF never learns the member cleared it
   and the old value survives. The empty sentinel is what carries that. */
$fld_gal['value'] = [];
ob_start(); lg_fc_render_photos(lg189_prepare($fld_gal)); $html_none = ob_get_clean();
$dec_none = lg189_decode($html_none);
R('I2.empty_gallery_still_posts',
  array_key_exists($fld_gal['key'], $dec_none['acf'] ?? []) && $dec_none['acf'][$fld_gal['key']] === '',
  'an emptied strip posts an empty value: ' . var_export($dec_none['acf'][$fld_gal['key']] ?? '(absent)', true));
$fld_file['value'] = 0;
ob_start(); lg_fc_render_printfile(lg189_prepare($fld_file)); $html_nofile = ob_get_clean();
$dec_nofile = lg189_decode($html_nofile);
R('I2.empty_printfile_still_posts',
  ($dec_nofile['acf'][$fld_file['key']] ?? null) === '',
  'an emptied slot posts an empty value: ' . var_export($dec_nofile['acf'][$fld_file['key']] ?? '(absent)', true));

/* ⚠️ AND THE VALUE ACTUALLY SAVES. Decoding proves the shape; this proves ACF's
   own update_value accepts it and writes the same rows it writes today. */
acf_update_value([(string) $g1, (string) $g2], $POST, acf_get_field('loothprint_more_images'));
acf_update_value((string) $f1, $POST, acf_get_field('loothprint_3d_file'));
R('I2.gallery_saves_ids', get_post_meta($POST, 'loothprint_more_images', true) === [(string) $g1, (string) $g2],
  var_export(get_post_meta($POST, 'loothprint_more_images', true), true));
R('I2.printfile_saves_id', (string) get_post_meta($POST, 'loothprint_3d_file', true) === (string) $f1,
  var_export(get_post_meta($POST, 'loothprint_3d_file', true), true));

/* ── §I3 NO MODAL, AND NO OTHER CONTROL ─────────────────────────────────────── */
R('I3.no_gallery_markup', stripos($html_gal, 'acf-gallery') === false,
  'the swapped render emits no ACF gallery markup');
R('I3.no_file_uploader_markup', stripos($html_file, 'acf-file-uploader') === false,
  'nor ACF\'s file control');
R('I3.has_a_visible_file_input',
  preg_match('/<input[^>]*type="file"[^>]*class="lgfc-up__file"/i', $html_gal) === 1
  && stripos($html_gal, 'lgfc-up__zone') !== false,
  'the drop-zone carries a real file input — the keyboard path and the no-drag fallback');
R('I3.drop_zone_wording_is_markup',
  stripos($html_gal, 'Drag photos here') !== false && stripos($html_file, 'Drag your print file here') !== false,
  'the zone text is real markup a screen reader reaches, not CSS ::before content');

/* THE DEQUEUE, BEHAVIOURALLY. wp_enqueue_media() is called for real and then the
   two hooks are run, exactly as they run on the page. A CSS-hidden modal would
   pass every visual check and fail Ian's actual ask, so the assertion is about
   the SCRIPT QUEUE.

   ⚠️ AND THE HOOKS ARE ASSERTED BY NAME AND PRIORITY, because WHEN this runs is
   half the fix. The wysiwyg enqueues the uploader during the BODY, so a dequeue
   on wp_enqueue_scripts fires before there is anything to dequeue and does
   nothing at all — while still passing any assertion that only asks whether the
   function works when called. (The first version of this leg read
   `has_action(...) === false || === 1`, which is true either way: a tautology
   that the red-first would have caught and I caught first by reading it.) */
lg_fc_close_uploader_door();
R('I3.hooked_at_wp_footer_1', has_action('wp_footer', 'lg_fc_drop_media_modal') === 1,
  'the templates unhook runs at wp_footer:1, before wp_print_media_templates at 10; got '
  . var_export(has_action('wp_footer', 'lg_fc_drop_media_modal'), true));
R('I3.hooked_at_footer_scripts_0',
  has_action('wp_print_footer_scripts', 'lg_fc_drop_media_modal') === 0,
  'and the dequeue runs at wp_print_footer_scripts:0, the last moment before the '
  . 'footer scripts print; got '
  . var_export(has_action('wp_print_footer_scripts', 'lg_fc_drop_media_modal'), true));
R('I3.not_on_enqueue_scripts',
  has_action('wp_enqueue_scripts', 'lg_fc_drop_media_modal') === false,
  'and NOT on wp_enqueue_scripts, which fires long before the wysiwyg enqueues '
  . 'anything and would be a dequeue of nothing');

if (function_exists('wp_enqueue_media') && !did_action('wp_enqueue_media')) {
    wp_enqueue_media();
}
R('I3.media_was_enqueued', wp_script_is('media-editor', 'enqueued'),
  'wp_enqueue_media() really did enqueue media-editor — without this the next '
  . 'assertion is vacuously green, which is how the first version of it passed');
lg_fc_drop_media_modal();
R('I3.media_is_gone',
  !wp_script_is('media-editor', 'enqueued') && !wp_script_is('media-audiovideo', 'enqueued')
  && !wp_style_is('media-views', 'enqueued'),
  'and lg_fc_drop_media_modal() takes the roots back off the queue');
R('I3.media_templates_gone', has_action('wp_footer', 'wp_print_media_templates') === false,
  'the footer media templates are unhooked too');
/* ── §I4 THE WRITE-UP EDITOR SURVIVES IT ────────────────────────────────────
   ⚠️ THE DEFECT THIS CATCHES SHIPPED FOR AN HOUR AND A 26-ASSERTION BROWSER
   SUITE WENT GREEN OVER IT. Arming ACF's enqueue_uploader latch stops
   wp_enqueue_media() AND print_uploader_scripts() — and the hidden
   wp_editor('','acf_content') that second one prints is the ONLY thing that
   brings TinyMCE to this page, because ACF's front-end wysiwyg hand-renders a
   textarea and clones its settings. Latch it and the write-up is a bare box.

   acf_raw_setting() READS the latch without arming it; acf_has_done() would arm
   it and the gate would cause the very state it is testing for. §I3 has already
   called lg_fc_close_uploader_door(). */
R('I4.latch_not_armed',
  !acf_raw_setting('has_done_ACF_Assets::enqueue_uploader'),
  'lg_fc_close_uploader_door() must NOT arm ACF\'s uploader latch — arming it '
  . 'takes TinyMCE with it (#185\'s defect class, by a different route)');
R('I4.toolbar_still_registered',
  has_action('acf/enqueue_uploader') !== false,
  'ACF\'s wysiwyg callback is still on acf/enqueue_uploader — that is where '
  . 'acf.data.toolbars, including lgfc_light, is localized');
$tb = apply_filters('acf/fields/wysiwyg/toolbars', []);
R('I4.lgfc_light_exists', isset($tb['lgfc_light']),
  'the light toolbar is still declared: ' . implode(',', array_keys($tb)));

/* ── §I5 THE BROWSER IS POINTED AT #186's CHUNKER, AND NOWHERE ELSE ─────────
   "A second upload route with its own limits" is how a field declaring
   mime_types=zip came to hold 48 .stl files. The config the JS reads is asserted
   here, not described. */
$cfg = lg_fc_upload_config($POST);
R('I5.action_is_the_chunker', ($cfg['action'] ?? '') === 'bfu_chunker',
  'the JS posts action=' . var_export($cfg['action'] ?? null, true));
R('I5.url_is_admin_ajax', strpos((string) ($cfg['url'] ?? ''), 'admin-ajax.php') !== false,
  (string) ($cfg['url'] ?? '(none)'));
R('I5.nonce_is_media_form', (bool) wp_verify_nonce($cfg['nonce'] ?? '', 'media-form'),
  'the nonce verifies as media-form — the one BFU\'s check_admin_referer tests. '
  . 'wp_enqueue_media() no longer runs, so _wpPluploadSettings does not exist '
  . 'and this is where the uploader gets it');
R('I5.post_id_is_the_composing_post', (int) ($cfg['post_id'] ?? 0) === $POST,
  'uploads are parented to ' . var_export($cfg['post_id'] ?? null, true)
  . ' — post_parent and the #186 stamp both key on this');
R('I5.guard_still_at_priority_1', has_action('wp_ajax_bfu_chunker', 'lg_fc_chunk_guard') === 1,
  'the priority-1 chunk guard still meets every byte this uploader sends');
R('I5.prefilter_on_both_hooks',
  has_filter('wp_handle_sideload_prefilter', 'lg_fc_upload_prefilter') !== false
  && has_filter('wp_handle_upload_prefilter', 'lg_fc_upload_prefilter') !== false,
  'and so does the size prefilter, on both hooks');
R('I5.chunk_smaller_than_bfu', (int) ($cfg['chunk_b'] ?? 0) > 0 && (int) $cfg['chunk_b'] <= 4 * 1024 * 1024,
  sprintf('chunk = %s. It is the OVERSHOOT BOUND: the guard refuses at the first '
          . 'chunk that crosses the cap, and the spool is on the root disk',
          lg_fc_mb((int) ($cfg['chunk_b'] ?? 0))));

/* THE WORDING TRAVELS, THE FORMATTER IS MIRRORED. The client refuses before the
   bytes leave, in the same sentence the server refuses with — so the templates
   must be the server's own, with both numbers still to fill. The gate's own
   §J (python) runs the SHIPPED JS formatter against lg_fc_mb() for real byte
   values, which is the half this side cannot see. */
R('I5.refusal_templates_are_the_servers',
  ($cfg['say']['photo_big'] ?? '') === lg_fc_size_refusal_template(true)
  && ($cfg['say']['file_big'] ?? '') === lg_fc_size_refusal_template(false),
  'the browser is handed the server\'s own sentences, not a second wording');
R('I5.templates_have_both_numbers',
  substr_count((string) ($cfg['say']['photo_big'] ?? ''), '%s') === 2,
  'each carries %s twice — the actual size and the limit');
R('I5.server_refusal_names_the_number',
  strpos(lg_fc_size_refusal(true, 12 * 1024 * 1024, $LIM['photo_b']), '10MB') !== false
  && strpos(lg_fc_size_refusal(true, 12 * 1024 * 1024, $LIM['photo_b']), '12MB') !== false,
  lg_fc_size_refusal(true, 12 * 1024 * 1024, $LIM['photo_b']));

/* THE BYTE FORMATTER EXISTS ON BOTH SIDES, so it is held to agreement rather
   than trusted. The probe emits what lg_fc_mb() says for a spread of real byte
   values; the python gate runs the SHIPPED JS mb() over the same values in node
   and compares. This is the one place a duplication was accepted — the wording
   travels from PHP, only the formatter is mirrored — and this is the price. */
$mb = [];
foreach ([0, 999, 1048576, 1310720, 1415578, 9003535, 10485760, 12586447,
          134217728, 5242880000] as $b) {
    $mb[(string) $b] = lg_fc_mb((int) $b);
}
echo 'MB|' . wp_json_encode($mb) . "\n";

/* ── §I6 THE FLAG ──────────────────────────────────────────────────────────
   The uploader has no flag of its own and must not grow one: it is reached only
   through lg_fc_render(), which is reached only through lg_fc_route(), which
   lg_fc_enabled() gates. So the honest OFF assertion is that the ROUTE is shut,
   not that the renderer refuses. */
R('I6.route_follows_the_flag', lg_fc_enabled() === $ON,
  $ON ? 'flag ON — /compose/ is served and the uploader is reachable'
      : 'flag OFF — lg_fc_route() never renders, so the swap never happens and '
        . 'nothing on this page changes for anybody');
R('I6.no_second_flag',
  ($cfg['post_id'] ?? null) !== null && !array_key_exists('enabled', $cfg),
  'the uploader carries no switch of its own — one flag, one door');

/* stamps made by §I2's fixtures are cleaned by the teardown below, which walks
   $A and every child of $POST. */

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
/* ⚠️ COUNTS THIS RUN'S OWN ROWS, NOT EVERY ROW ON THE BOX. The original counted
   all of them and required zero, which was a teardown assertion only while
   nobody had ever used the form — a member composing on the serve made it RED
   for reasons that had nothing to do with the harness. */
$left = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN (%d,%d,%d)",
    LG_FC_UPLOAD_STAMP, $POST, $OTHER, $T));
R('Z.teardown_clean', $left === 0, "$left stamped row(s) from this run left behind");

/* ⚠️ THE SENTINEL. It asserts nothing about the feature — it asserts that THIS
   FILE RAN TO THE END. A probe that dies half way emits fewer PASSes and zero
   FAILs, which scores as a clean green: exactly what happened when wp_send_json's
   bare `die` cut the flag-ON run from 56 assertions to 26 and the gate reported
   GREEN anyway. The gate now refuses to score a run that does not end here. */
R('Z.end', true, 'probe ran to completion');
