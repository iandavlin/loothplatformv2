<?php
/**
 * loothprint-gating-probe.php — the WordPress half of GATE 94.
 *
 * Driven by tools/gates/loothprint-gating-gate.py, never run by hand. Three
 * modes, chosen by LG199_MODE:
 *
 *   setup     mint a per-run Loothprint shaped exactly like live post 72801 —
 *             two photos, one print ZIP, one build video, tier looth-lite, and
 *             NO stored layout so it takes the synthesizer. Prints POST_ID=<n>.
 *   measure   render it through THIS TREE's engine as anon and as a member and
 *             print one JSON object of what came out.
 *   teardown  delete the post and its attachments.
 *
 * ⚠️ IT MEASURES THE TREE IT LIVES IN. lg-layout-v2 is symlinked into the
 * serving checkout, so a plain `wp eval-file` measures MAIN however correct the
 * branch is. The driver runs wp with --skip-plugins=lg-layout-v2 and this file
 * requires the branch's own entry point, then echoes back the file the engine
 * actually loaded so the gate can assert it. Nothing on the serve is modified.
 *
 * ⚠️ NO FILE IS WRITTEN TO THE UPLOADS BUCKET. The attachments are rows with
 * `_wp_attached_file` set and no bytes behind them — wp_get_attachment_url() and
 * wp_attachment_is_image() both answer from the row, which is all this needs. A
 * gate that uploaded to R2 on every run would be writing to a shared store to
 * prove a render.
 *
 * @see tools/gates/loothprint-gating-gate.py for what is asserted and why.
 */

/* No `declare(strict_types=1)` here, deliberately: `wp eval-file` eval()s this
   file, and a strict_types declaration must be the very first statement in a
   script — inside an eval it never is, and WordPress reports it as a critical
   error with the real cause three frames down. */

use LG\LayoutV2\Plugin;
use LG\LayoutV2\Pipeline;
use LG\LayoutV2\Manifest;
use LG\LayoutV2\Renderer;
use LG\LayoutV2\TierResolver;
use LG\LayoutV2\WpMedia;

$TREE = (string) (getenv('LG199_TREE') ?: '');
$MODE = (string) (getenv('LG199_MODE') ?: 'measure');
$PID  = (int) (getenv('LG199_PID') ?: 0);
$TAG  = (string) (getenv('LG199_TAG') ?: ('lg199-' . getmypid()));

if ($TREE === '' || !is_file("$TREE/lg-layout-v2/lg-layout-v2.php")) {
    fwrite(STDERR, "PROBE-ERROR no engine at $TREE/lg-layout-v2\n");
    exit(2);
}
require_once "$TREE/lg-layout-v2/lg-layout-v2.php";
Manifest::configure("$TREE/lg-layout-v2/blocks");

/**
 * The gating flag, asked for in a way that SURVIVES A TREE THAT HAS NO SUCH FLAG.
 *
 * ⚠️ This is what makes the gate's red-first honest. Pointed at main,
 * Renderer::loothprintGatingEnabled() does not exist yet, and calling it would
 * fatal — so the gate would report CANNOT RUN, which run-all.sh treats as "no
 * verdict" and which proves nothing about main's behaviour. Degrading to false
 * instead means main answers every leg as the OFF build it is, and the gate
 * comes back RED with findings rather than an environment error.
 */
function lg199_flag(): bool
{
    return method_exists('LG\LayoutV2\Renderer', 'loothprintGatingEnabled')
        ? Renderer::loothprintGatingEnabled() : false;
}

/** A rows-only attachment: real enough for URL + mime, no bytes in the bucket. */
function lg199_attach(string $tag, string $name, string $mime, int $parent = 0): int
{
    $id = wp_insert_post([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_title'     => $name,
        'post_name'      => sanitize_title("$tag-$name"),
        'post_mime_type' => $mime,
        'post_parent'    => $parent,
        'post_author'    => 1,
    ], true);
    if (is_wp_error($id)) return 0;
    update_post_meta((int) $id, '_wp_attached_file', "lg199-probe/$tag-$name");
    update_post_meta((int) $id, '_lg199_probe', $tag);
    return (int) $id;
}

if ($MODE === 'setup') {
    $pid = wp_insert_post([
        'post_type'    => 'loothprint',
        'post_status'  => 'publish',
        'post_title'   => "GATE 94 probe $TAG",
        'post_name'    => "gate94-probe-$TAG",
        'post_author'  => 1,
        /* The anchor is deliberate: scrubGatedAnchors used to strip it whenever
           the post carried a tier, which is wrong once the video is public. */
        'post_content' => '<p>Probe body. <a href="https://youtu.be/qLVbPWYBjK0">build video</a></p>',
    ], true);
    if (is_wp_error($pid)) { fwrite(STDERR, "PROBE-ERROR " . $pid->get_error_message() . "\n"); exit(2); }
    $pid = (int) $pid;
    update_post_meta($pid, '_lg199_probe', $TAG);

    $img1 = lg199_attach($TAG, 'photo-one.jpg', 'image/jpeg', $pid);
    $img2 = lg199_attach($TAG, 'photo-two.png', 'image/png',  $pid);
    $zip  = lg199_attach($TAG, 'the-print.zip', 'application/zip', $pid);
    if (!$img1 || !$img2 || !$zip) { fwrite(STDERR, "PROBE-ERROR attachments\n"); exit(2); }

    /* ⚠️ THE ZIP IS IN more_images ON PURPOSE. Ian pinned the ghost tile on the
       print file being collected into the gallery. Measured on the live post it
       came from, that was NOT the cause — but a gallery that is handed a
       non-image must still render two tiles, not three, so the probe hands it
       one and the gate asserts the count. */
    update_post_meta($pid, 'loothprint_more_images', [(string) $img1, (string) $img2, (string) $zip]);
    update_post_meta($pid, 'loothprint_3d_file', $zip);
    update_post_meta($pid, 'loothprint_video_instructions', 'https://youtu.be/qLVbPWYBjK0');
    update_post_meta($pid, 'loothprint_creative_commons', 'BY NC ND (Credit given to creator, Non-Commercial only, No Derivatives)');
    update_post_meta($pid, 'loothprint_onshape_link', 'https://cad.onshape.com/documents/gate94probe');
    set_post_thumbnail($pid, $img1);
    wp_set_object_terms($pid, 'looth-lite', 'tier', false);
    delete_post_meta($pid, '_lg_layout_v2');

    echo "POST_ID=$pid\nIMG1=$img1\nIMG2=$img2\nZIP=$zip\n";
    exit(0);
}

if ($MODE === 'teardown') {
    $q = get_posts(['post_type' => ['loothprint', 'attachment'], 'post_status' => 'any',
                    'numberposts' => -1, 'fields' => 'ids',
                    'meta_query' => [['key' => '_lg199_probe', 'value' => $TAG]]]);
    foreach ($q as $id) wp_delete_post((int) $id, true);
    echo "DELETED=" . count($q) . "\n";
    exit(0);
}

/* ── measure ─────────────────────────────────────────────────────────────── */
if ($PID <= 0) { fwrite(STDERR, "PROBE-ERROR no LG199_PID\n"); exit(2); }

$post = get_post($PID);
if (!$post instanceof WP_Post) { fwrite(STDERR, "PROBE-ERROR post $PID gone\n"); exit(2); }

$layout = Plugin::load_layout($PID);
/* ⚠️ LIVENESS. A null or empty layout renders zero of everything, and every
   "the video is not gated" style assertion downstream would pass having measured
   nothing at all. Refuse instead. */
if (!is_array($layout) || count($layout['blocks'] ?? []) < 4) {
    fwrite(STDERR, "PROBE-ERROR synthesizer produced no usable layout for $PID\n");
    exit(2);
}

$tier = '';
foreach (wp_get_object_terms($PID, 'tier', ['fields' => 'slugs']) ?: [] as $s) {
    if (is_string($s) && $s !== '' && $s !== 'public') { $tier = $s; break; }
}

$blocks = [];
foreach ($layout['blocks'] as $b) {
    $blocks[] = [
        'type'       => (string) ($b['type'] ?? '?'),
        'id'         => (string) ($b['id'] ?? ''),
        'gated_tier' => (string) ($b['gated_tier'] ?? ''),
        'file_id'    => (int) ($b['file_id'] ?? 0),
        'image_ids'  => array_map('intval', (array) ($b['image_ids'] ?? [])),
    ];
}

function lg199_render(array $layout, int $pid, string $ptype, string $tier, array $viewer, bool $editor): array
{
    $ctx = [
        'viewer' => $viewer, 'editor_mode' => $editor, 'can_edit' => false,
        'media_resolver' => [WpMedia::class, 'resolve'],
        'post_id' => $pid, 'post_type' => $ptype, 'post_tier' => $tier,
    ];
    $html = (string) (Pipeline::run($layout, [], [], $ctx)['html'] ?? '');

    preg_match_all('~data-lg-gate="([a-z]+)"~', $html, $g);
    preg_match_all('~<div class="lg-gallery__tile">~', $html, $t);
    preg_match_all('~lg-gallery__tile--placeholder~', $html, $ph);
    preg_match('~lg-gate-cta__eyebrow">([^<]*)~', $html, $eb);
    preg_match('~lg-gate-cta__headline">([^<]*)~', $html, $hl);

    return [
        'gate_variants'   => $g[1],
        'tiles'           => count($t[0]),
        'placeholders'    => count($ph[0]),
        'gate_eyebrow'    => $eb[1] ?? '',
        'gate_headline'   => $hl[1] ?? '',
        /* The real embed payload, not merely the word youtube: data-yt-id is
           emitted only by the embed block itself. */
        'video_payload'   => (strpos($html, 'data-yt-id') !== false && strpos($html, 'qLVbPWYBjK0') !== false),
        'zip_href'        => (bool) preg_match('~href="[^"]*\.zip"~i', $html),
        'prose_yt_anchor' => (bool) preg_match('~<a\s[^>]*href="https://youtu\.be/qLVbPWYBjK0"~', $html),
        'download_row'    => (strpos($html, 'lg-download') !== false),
        'bytes'           => strlen($html),
    ];
}

$anon   = TierResolver::anonymous();
$member = ['is_admin' => false, 'logged_in' => true, 'tiers' => ['looth-lite']];

echo json_encode([
    'engine_file' => (new ReflectionClass(Renderer::class))->getFileName(),
    'flag'        => lg199_flag(),
    'post_id'     => $PID,
    'post_tier'   => $tier,
    'blocks'      => $blocks,
    'anon'        => lg199_render($layout, $PID, $post->post_type, $tier, $anon, false),
    'member'      => lg199_render($layout, $PID, $post->post_type, $tier, $member, false),
    'anon_editor' => lg199_render($layout, $PID, $post->post_type, $tier, $anon, true),
], JSON_UNESCAPED_SLASHES) . "\n";
