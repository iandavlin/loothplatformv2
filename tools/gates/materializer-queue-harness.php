<?php
/**
 * materializer-queue-harness.php — proves the #179 re-bake fix, without WordPress.
 *
 * WHY A STUB HARNESS AND NOT A GATE ON THE BOX. lg-article-materializer.php is a
 * mu-plugin symlinked out of the SERVING CHECKOUT, so dev2 runs main's copy and a
 * branch edit is invisible there — the recorded trap where a lane "verifies on
 * dev2" and is really testing main. The behaviour under test is pure ordering, so
 * it is driven directly here with the same hook sequence ACF actually produces.
 *
 * THE DEFECT, restated so this file stands alone. The dispatcher de-dupes one
 * bake per (post, action) per request — necessary, because save hooks fire
 * several times per edit — but it USED TO SEND INLINE. Measured order for a
 * front-end compose save (ACF form-front.php: wp_update_post at :289, the
 * acf/save_post chain at :391):
 *
 *   1. wp_after_insert_post  -> bake SENT; the endpoint boots its own WordPress
 *                               and reads the post as it is at that instant
 *   2. acf/save_post 25      -> lg_fc_promote_draft, which also sends mail
 *   3. acf/save_post 26      -> the paywall toggle writes the `tier` term
 *                               -> bake dispatch SWALLOWED by the de-dupe
 *
 * So the member's paywall choice never reached the blob, and on the member path
 * step 2's wp_mail makes it deterministic rather than a race.
 *
 * Run:  php tools/gates/materializer-queue-harness.php
 * Exit: 0 all pass, 1 a real defect, 2 CANNOT RUN.
 */

declare(strict_types=1);

/* The real box always has lg-layout-v2 active, so the dispatcher reads its
   MANAGED_CPTS. Declared here with the real value rather than falling through to
   the plugin's hardcoded fallback, which does NOT contain loothprint. */
namespace LG\LayoutV2 {
    class Plugin {
        public const MANAGED_CPTS = [
            'post-imgcap', 'post-type-videos', 'sponsor-post', 'sponsor-page', 'event',
            'loothprint', 'loothcuts', 'useful_links', 'document', 'member-benefit', 'shorty',
        ];
    }
}

namespace {

define('ABSPATH', __DIR__);           // the plugin's own "don't run me directly" guard

$HOOKS  = [];      // hook => [prio => [callables]]
$SENDS  = [];      // every loopback POST the plugin attempted, in order
$TYPE   = 'loothprint';
$STATUS = 'publish';
$PHASE  = 'during-request';

function add_action($hook, $cb, $prio = 10, $args = 1) {
    global $HOOKS; $HOOKS[$hook][$prio][] = $cb;
}
function do_action($hook, ...$a) {
    global $HOOKS;
    if (empty($HOOKS[$hook])) return;
    $byPrio = $HOOKS[$hook]; ksort($byPrio);
    foreach ($byPrio as $cbs) foreach ($cbs as $cb) $cb(...$a);
}
function get_post_type($id = 0)          { global $TYPE;  return $TYPE; }
function wp_is_post_revision($id)        { return false; }
function wp_is_post_autosave($id)        { return false; }
function wp_json_encode($v)              { return json_encode($v); }
function get_option($k, $d = false)      { return $d; }
function wp_remote_post($url, $args) {
    global $SENDS, $STATUS, $PHASE;
    $SENDS[] = ['body' => json_decode($args['body'], true), 'status' => $STATUS, 'phase' => $PHASE];
    return [];
}

require __DIR__ . '/../../platform/mu-plugins/lg-article-materializer.php';

$fails = 0; $n = 0;
function reset_request(): void {
    /* A fresh PROCESS is the only honest way to reset a `static` queue, so each
       case runs in its own subprocess — see the dispatcher at the bottom. */
}
function chk(string $label, bool $ok, string $detail = ''): void {
    global $fails, $n; $n++;
    echo ($ok ? "  ok   " : "  RED  ") . $label . ($detail ? "  [$detail]" : "") . "\n";
    if (!$ok) $fails++;
}

$case = $argv[1] ?? '';

if ($case === 'compose-save') {
    echo "CASE 1 — a front-end compose save: the paywall term must reach the bake\n";
    global $SENDS, $PHASE;
    do_action('wp_after_insert_post', 4242);                       // ACF's wp_update_post
    chk("nothing is baked before the request has finished writing", count($SENDS) === 0,
        "sends=" . count($SENDS));
    do_action('updated_post_meta', 1, 4242, '_lg_layout_v2');      // ACF field writes
    do_action('set_object_terms', 4242, [], [], 'tier');           // the paywall write, prio 26
    chk("…still nothing, after the tier term is written", count($SENDS) === 0,
        "sends=" . count($SENDS));
    $PHASE = 'shutdown';
    do_action('shutdown');
    chk("exactly ONE bake, and it happens AFTER the tier write", count($SENDS) === 1,
        "sends=" . count($SENDS));
    chk("…and it is an upsert for the right post",
        ($SENDS[0]['body']['post_id'] ?? 0) === 4242 && ($SENDS[0]['body']['action'] ?? '') === 'upsert',
        json_encode($SENDS[0]['body'] ?? null));
    chk("…sent at shutdown, not mid-request",
        ($SENDS[0]['phase'] ?? '') === 'shutdown', $SENDS[0]['phase'] ?? '-');

} elseif ($case === 'ordinary-save') {
    echo "CASE 2 — an ordinary single save must not double-bake\n";
    global $SENDS, $PHASE;
    do_action('wp_after_insert_post', 77);
    do_action('updated_post_meta', 1, 77, '_lg_layout_v2');
    do_action('added_post_meta',   2, 77, '_thumbnail_id');
    do_action('set_object_terms',  77, [], [], 'tier');
    do_action('wp_after_insert_post', 77);      // hooks really do fire twice
    $PHASE = 'shutdown';
    do_action('shutdown');
    chk("five re-bake triggers collapse to ONE loopback POST", count($SENDS) === 1,
        "sends=" . count($SENDS));

} elseif ($case === 'first-publish') {
    echo "CASE 3 — first publish: the bake must see a PUBLISHED post, not the auto-draft\n";
    global $SENDS, $STATUS, $PHASE;
    $STATUS = 'auto-draft';
    do_action('wp_after_insert_post', 9001);    // fires while the row is still a draft
    $STATUS = 'publish';                        // lg_fc_promote_draft, acf/save_post 25
    do_action('set_object_terms', 9001, [], [], 'tier');
    $PHASE = 'shutdown';
    do_action('shutdown');
    chk("the post is baked", count($SENDS) === 1, "sends=" . count($SENDS));
    chk("…and it was PUBLISHED by the time the bake was sent",
        ($SENDS[0]['status'] ?? '') === 'publish', $SENDS[0]['status'] ?? '-');

} elseif ($case === 'delete-after-row-gone') {
    echo "CASE 4 — a delete queued before the row vanishes must still be sent\n";
    global $SENDS, $TYPE, $PHASE;
    do_action('before_delete_post', 555);       // row still exists, type resolvable
    $TYPE = false;                              // …and by shutdown it does not
    $PHASE = 'shutdown';
    do_action('shutdown');
    chk("the delete survives the row disappearing", count($SENDS) === 1,
        "sends=" . count($SENDS));
    chk("…and it is a delete",
        ($SENDS[0]['body']['action'] ?? '') === 'delete', json_encode($SENDS[0]['body'] ?? null));

} elseif ($case === 'unmanaged') {
    echo "CASE 5 — an unmanaged post type is never queued at all\n";
    global $SENDS, $TYPE, $PHASE;
    $TYPE = 'page';
    do_action('wp_after_insert_post', 12);
    $PHASE = 'shutdown';
    do_action('shutdown');
    chk("a page save sends nothing", count($SENDS) === 0, "sends=" . count($SENDS));

} else {
    /* Each case needs a virgin `static` queue, so each runs in its own process. */
    $cases = ['compose-save', 'ordinary-save', 'first-publish', 'delete-after-row-gone', 'unmanaged'];
    $bad = 0;
    foreach ($cases as $c) {
        $out = []; $rc = 0;
        exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($c) . ' 2>&1', $out, $rc);
        echo implode("\n", $out) . "\n";
        if ($rc !== 0) $bad++;
    }
    if ($bad) {
        echo "\nRED — $bad of " . count($cases) . " cases failed.\n";
        exit(1);
    }
    echo "\nGREEN — the bake is queued to shutdown: the paywall term reaches it, an\n"
       . "ordinary save still bakes once, a first publish is baked as PUBLISHED, and a\n"
       . "delete survives its own row disappearing.\n";
    exit(0);
}

exit($fails ? 1 : 0);
}
