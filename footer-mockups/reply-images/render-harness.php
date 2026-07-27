<?php
/**
 * Render the REAL reply stub, from REAL rows, with the REAL query.
 *
 * No serve window is needed to prove this: it requires the shipping
 * _reply-render.php and runs the shipping _topic-replies.php LATERAL against the
 * live mirror, then prints the HTML each reply would produce. Used for two jobs:
 *
 *   1. verification — assert tile counts, srcset candidates, intrinsic
 *      dimensions and the +N affordance against real data
 *   2. the previs frames — so the mockups are the actual shipped markup, not a
 *      hand-written imitation of it
 *
 *   php render-harness.php stubs <reply_id> [<reply_id> ...]   # HTML per reply
 *   php render-harness.php synth <n> [<n> ...]                 # N-image stubs
 *   php render-harness.php over  <shown> <total>               # the +N case
 *
 * Run as a role that can read the mirror, e.g.
 *   sudo -u postgres php render-harness.php stubs 58510
 */
declare(strict_types=1);

// The stub renderer asks "may this viewer post?" to decide whether to scrub
// contacts and emit edit affordances. CLI has no cookie; declare the answer
// BEFORE the include so its function_exists guard defers to us. Members are
// logged in — that is the path being previewed.
function lg_bb_mirror_can_post(): bool { return true; }
function lg_bb_mirror_can_moderate(): bool { return false; }

require __DIR__ . '/../../bb-mirror/config.php';
require __DIR__ . '/../../bb-mirror/web/forums/_reply-render.php';

/** The shipping LATERAL out of _topic-replies.php, verbatim in shape. */
function harness_rows(PDO $db, array $ids): array
{
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "
        SELECT r.id AS reply_id, COALESCE(r.author_name,'Anonymous') AS author_name,
               r.author_id, p.slug AS author_slug, p.avatar_url,
               LEFT(r.content_text, 200) AS excerpt, r.created_at,
               reply_img.imgs AS reply_images, reply_img.total AS reply_image_total
          FROM forums.reply r
          LEFT JOIN forums.person p ON p.id = r.author_id
          LEFT JOIN LATERAL (
            SELECT json_agg(json_build_object('url', a.url, 'w', a.width, 'h', a.height)) AS imgs,
                   (SELECT count(*) FROM forums.attachment ax
                     WHERE ax.parent_kind='reply' AND ax.parent_id=r.id) AS total
              FROM (SELECT url, width, height FROM forums.attachment
                     WHERE parent_kind='reply' AND parent_id=r.id
                     ORDER BY position ASC, id ASC
                     LIMIT " . LG_REPLY_IMG_MAX . ") a
          ) reply_img ON true
         WHERE r.id IN ($ph)";
    $st = $db->prepare($sql);
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $r['reply_images']      = !empty($r['reply_images'])
            ? (array) json_decode((string)$r['reply_images'], true) : [];
        $r['reply_image_total'] = (int)($r['reply_image_total'] ?? 0);
        $r['_visibility_masked'] = true;    // pre-masked; skip the WP-side re-mask
        $out[(int)$r['reply_id']] = $r;
    }
    return $out;
}

/** Real attachments, any parent, for synthesising an N-image reply. */
function harness_pool(PDO $db, int $n): array
{
    $st = $db->query("
        SELECT url, width AS w, height AS h FROM forums.attachment
         WHERE mime = 'image/jpeg' AND width > 0 AND height > 0
           AND url LIKE '%dev2.loothgroup.com%'
         ORDER BY id LIMIT 40");
    $all = $st->fetchAll();
    $out = [];
    for ($i = 0; $i < $n; $i++) $out[] = $all[$i % max(1, count($all))];
    return $out;
}

$db   = bb_mirror_db();
$mode = $argv[1] ?? 'stubs';
$args = array_slice($argv, 2);

$render = function (array $r): void {
    bb_mirror_render_reply_stub($r, false, false, true, null);
    echo "\n";
};

if ($mode === 'stubs') {
    foreach (harness_rows($db, array_map('intval', $args)) as $r) $render($r);
} elseif ($mode === 'synth') {
    foreach ($args as $n) {
        $n = (int)$n;
        $render([
            'reply_id' => 900000 + $n, 'author_name' => 'Steve Kramer',
            'author_slug' => 'stevekramer', 'author_id' => 1372,
            'avatar_url' => null, 'created_at' => '2026-07-24 10:00:00+00',
            'excerpt' => 'I did a repair on this Martin and had to sand the entire side down. '
                       . 'Here is how it went — pictures in order.',
            'reply_images' => harness_pool($db, $n), 'reply_image_total' => $n,
            '_visibility_masked' => true,
        ]);
    }
} elseif ($mode === 'over') {
    $shown = (int)($args[0] ?? 6); $total = (int)($args[1] ?? 11);
    $render([
        'reply_id' => 999999, 'author_name' => 'Steve Kramer',
        'author_slug' => 'stevekramer', 'author_id' => 1372,
        'avatar_url' => null, 'created_at' => '2026-07-24 10:00:00+00',
        'excerpt' => 'A legacy reply posted before the cap — it holds ' . $total
                   . ' photos and none of them were ever visible.',
        'reply_images' => harness_pool($db, $shown), 'reply_image_total' => $total,
        '_visibility_masked' => true,
    ]);
} else {
    fwrite(STDERR, "unknown mode: $mode\n");
    exit(2);
}
