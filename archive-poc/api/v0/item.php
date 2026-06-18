<?php
require __DIR__ . '/_bootstrap.php';

$id = param_int('id');
if ($id <= 0) {
    // try parsing /archive-api/v0/item/<id>
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/item/(\d+)#', $uri, $m)) $id = (int) $m[1];
}
if ($id <= 0) send_json(['error' => 'missing id'], 400);

$stmt = $db->prepare("SELECT " . lg_card_select($db) . " FROM content_item ci WHERE ci.id = ?");
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) send_json(['error' => 'not found'], 404);

$ts = $db->prepare("SELECT t.slug, t.label FROM content_tag ct JOIN tag t ON t.id=ct.tag_id WHERE ct.content_id = ?");
$ts->execute([$id]);
$tags = $ts->fetchAll();

// Viewer tier (anon→public, admin→pro). The 800-char body_preview and the
// excerpt are gated body prose; a viewer below the content's tier must get
// neither (gate_payload NULLs both when gated). One cached whoami call.
$viewer_tier = lg_archive_poc_viewer_tier(lg_archive_poc_whoami());

send_json(lg_archive_poc_gate_payload([
    'id'            => (int)$r['id'],
    'kind'          => $r['kind'],
    'subkind'       => $r['subkind'],
    'cpt'           => $r['cpt'],
    'title'         => $r['title'],
    'slug'          => $r['slug'],
    'url'           => $r['url'],
    'excerpt'       => $r['excerpt'],
    'body_preview'  => mb_substr((string)$r['body_text'], 0, 800),
    'thumb_url'     => $r['thumb_url'] ?: null,
    'thumb_broken'  => (int)$r['thumb_broken'] === 1,
    'tier'          => $r['tier'],
    'author'        => $r['author_id'] ? ['id' => (int)$r['author_id'], 'name' => $r['author_name']] : null,
    'published_at'  => (int)$r['published_at'],
    'last_activity' => $r['last_activity'] !== null ? (int)$r['last_activity'] : null,
    'reply_count'   => (int)$r['reply_count'],
    'like_count'    => (int)$r['like_count'],
    'view_count'    => (int)$r['view_count'],
    'duration_min'  => $r['duration_min'] !== null ? (int)$r['duration_min'] : null,
    'has_download'  => (int)$r['has_download'] === 1,
    'tags'          => $tags,
], $viewer_tier));
