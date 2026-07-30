<?php
/**
 * bb-mirror/bin/test-attachment-purge.php — regression test for the attachment
 * orphan leak (see docs/atlas/MIRROR-ATTACHMENT-ORPHANS.md).
 *
 * THE DEFECT: `forums.attachment` is polymorphic (parent_kind + parent_id), so it
 * cannot carry a foreign key, so it never got the ON DELETE CASCADE every other
 * child of topic/forum has. Deleting a reply stranded its image rows; deleting a
 * topic or forum cascaded away whole subtrees whose attachment rows nothing
 * removed. Closed by AFTER DELETE triggers in schema.pg.sql.
 *
 * This is encoded as a test rather than left as a one-off because the class was
 * found twice — first by the reply-images-count lane (whose "max 11 images per
 * reply" phantom was orphan pollution and nothing else), then again here.
 *
 *   sudo -u looth-dev php bin/test-attachment-purge.php
 *   sudo -u looth-dev BBM_TEST_DB=orphan_proof php bin/test-attachment-purge.php
 *
 * WHAT IT TOUCHES. It drives the REAL materializers (lib/materializers.php) over
 * REAL WordPress rows and issues the VERBATIM delete statement from
 * api/v0/_sync.php, but writes its mirror rows to a SCRATCH database — never to
 * the serving `looth` — by defining bb_mirror_db() before config.php can
 * (config.php guards on function_exists). The WP fixture is inserted with $wpdb
 * directly rather than wp_insert_post, so the bb-mirror-sync mu-plugin hooks do
 * NOT fire: a dispatch would POST to the real endpoint and write to `looth`. The
 * fixture is removed in a finally block.
 *
 * SETUP for the scratch database (once). The database is OWNED BY looth-dev —
 * same idiom as bin/init-db.php, and necessary because step 4 does DDL (it drops
 * and recreates a trigger), which needs table ownership, not just DML grants:
 *   sudo -u postgres  psql -c 'CREATE DATABASE orphan_proof OWNER "looth-dev"'
 *   sudo -u looth-dev psql -d orphan_proof -c 'CREATE SCHEMA forums'
 *   sudo -u looth-dev psql -d orphan_proof -f schema.pg.sql
 */

const BBM_TEST_FORUM = 3823;   // a real dev2 forum; the fixture hangs off it

$TEST_DB = getenv('BBM_TEST_DB') ?: 'orphan_proof';

function bb_mirror_db(bool $readonly = true): PDO {
    global $TEST_DB;
    if ($TEST_DB === 'looth') {
        fwrite(STDERR, "REFUSING to run against the serving database `looth`.\n");
        exit(2);
    }
    $pdo = new PDO('pgsql:host=/var/run/postgresql;dbname=' . $TEST_DB, null, null);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec("SET search_path = forums, public");
    return $pdo;
}

require __DIR__ . '/../config.php';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST']   ??= LG_BB_MIRROR_HOST;
$_SERVER['REQUEST_URI'] ??= '/';
require LG_BB_MIRROR_WP_LOAD;
require_once __DIR__ . '/../lib/materializers.php';

global $wpdb;
$db  = bb_mirror_db(readonly: false);
$now = gmdate('Y-m-d H:i:s');
$FAIL = 0;

function orphans(PDO $db): int {
    return (int)$db->query("SELECT count(*) FROM forums.attachment a
      WHERE (a.parent_kind='reply' AND NOT EXISTS(SELECT 1 FROM forums.reply r WHERE r.id=a.parent_id))
         OR (a.parent_kind='topic' AND NOT EXISTS(SELECT 1 FROM forums.topic t WHERE t.id=a.parent_id))")
      ->fetchColumn();
}
function att(PDO $db, string $kind, int $id): int {
    $s = $db->prepare("SELECT count(*) FROM forums.attachment WHERE parent_kind=? AND parent_id=?");
    $s->execute([$kind, $id]); return (int)$s->fetchColumn();
}
function check(string $what, int $got, int $want): void {
    global $FAIL;
    $ok = $got === $want;
    if (!$ok) $FAIL++;
    printf("  [%s] %-52s got %d, want %d\n", $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m", $what, $got, $want);
}

// The triggers must actually be installed, or every assertion below passes
// vacuously in the one direction that matters least.
$installed = (int)$db->query("SELECT count(*) FROM pg_trigger t
    JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
   WHERE NOT t.tgisinternal AND n.nspname='forums'
     AND t.tgname IN ('topic_attachment_purge','reply_attachment_purge')")->fetchColumn();
printf("scratch db: %s   purge triggers installed: %d\n", $TEST_DB, $installed);
if ($installed !== 2) {
    fwrite(STDERR, "Expected 2 purge triggers in $TEST_DB, found $installed. Apply schema.pg.sql first.\n");
    exit(2);
}
$baseline = orphans($db);
if ($baseline !== 0) {
    fwrite(STDERR, "Scratch db has $baseline pre-existing orphans; clear them so 0 is unambiguous.\n");
    exit(2);
}

$mk = function (string $type, string $title, string $content, array $meta) use ($wpdb, $now) {
    $wpdb->insert($wpdb->posts, [
        'post_author' => 1, 'post_date' => $now, 'post_date_gmt' => $now,
        'post_content' => $content, 'post_title' => $title, 'post_status' => 'publish',
        'comment_status' => 'closed', 'ping_status' => 'closed',
        'post_name' => sanitize_title($title), 'post_modified' => $now,
        'post_modified_gmt' => $now, 'post_parent' => 0, 'guid' => '', 'post_type' => $type,
    ]);
    $id = (int)$wpdb->insert_id;
    foreach ($meta as $k => $v) $wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => $k, 'meta_value' => $v]);
    return $id;
};

// SIX images is the shape reply galleries shipped to live at 6ef25e3 — the whole
// reason this leak got up to six times wider.
$six = '';
for ($i = 1; $i <= 6; $i++) {
    $six .= '<img src="https://cdn.example.invalid/zz-test/six-' . $i . '.jpg" alt="six ' . $i . '" />';
}
$topic_id = $mk('topic', 'ZZ TEST orphan-proof topic',
    '<img src="https://cdn.example.invalid/zz-test/cover.jpg" alt="cover" />',
    ['_bbp_forum_id' => BBM_TEST_FORUM]);
$reply_id = $mk('reply', 'ZZ TEST orphan-proof reply', $six,
    ['_bbp_topic_id' => $topic_id, '_bbp_forum_id' => BBM_TEST_FORUM]);
echo "WP fixture: topic $topic_id, reply $reply_id (6 inline images)\n";

try {
    echo "\n1. materialize through the real materializers\n";
    bb_mirror_upsert_forum(BBM_TEST_FORUM, $db);
    bb_mirror_upsert_topic($topic_id, $db);
    bb_mirror_upsert_reply($reply_id, $db);
    check('topic cover mirrored',        att($db, 'topic', $topic_id), 1);
    check('reply mirrored with 6 images', att($db, 'reply', $reply_id), 6);
    check('no orphans yet',              orphans($db), 0);

    echo "\n2. delete the reply — verbatim statement from api/v0/_sync.php\n";
    $kind = 'reply'; $id = $reply_id;
    $db->prepare("DELETE FROM $kind WHERE id = ?")->execute([$id]);
    check('all 6 reply images purged',   att($db, 'reply', $reply_id), 0);
    check("topic's own cover untouched", att($db, 'topic', $topic_id), 1);
    check('no orphans left behind',      orphans($db), 0);

    echo "\n3. delete the topic — the CASCADE case a PHP-side fix cannot reach\n";
    bb_mirror_upsert_reply($reply_id, $db);
    check('reply images restored',       att($db, 'reply', $reply_id), 6);
    $kind = 'topic'; $id = $topic_id;
    $db->prepare("DELETE FROM $kind WHERE id = ?")->execute([$id]);
    check('cascaded reply images purged', att($db, 'reply', $reply_id), 0);
    check('topic cover purged',           att($db, 'topic', $topic_id), 0);
    check('no orphans left behind',       orphans($db), 0);
} finally {
    foreach ([$topic_id, $reply_id] as $pid) {
        $wpdb->delete($wpdb->postmeta, ['post_id' => $pid]);
        $wpdb->delete($wpdb->posts,    ['ID' => $pid]);
    }
    $left = (int)$wpdb->get_var("SELECT count(*) FROM {$wpdb->posts} WHERE post_title LIKE 'ZZ TEST orphan-proof%'");
    echo "\nWP fixture removed; ZZ TEST rows remaining: $left\n";
    if ($left !== 0) { echo "  \033[31mWARNING\033[0m fixture rows left in WP\n"; $FAIL++; }
}

// Sanity: this test is only meaningful if it can fail. Drop the triggers and the
// same reply delete must strand all 6 rows.
// wp-load turns display_errors off, so an uncaught PDOException here exits 255
// with no message at all — which is how this step first appeared to "pass".
// Catch it and say what actually went wrong.
try {
echo "\n4. negative control — drop the triggers, the leak must return\n";
$db->exec("DROP TRIGGER IF EXISTS reply_attachment_purge ON forums.reply");
$rid = 999000001;
$db->exec("INSERT INTO forums.forum (id,slug,title,created_at,modified_at)
           VALUES (999000000,'zz-neg','ZZ neg',now(),now()) ON CONFLICT (id) DO NOTHING");
$db->exec("INSERT INTO forums.topic (id,forum_id,slug,title,created_at,modified_at)
           VALUES (999000000,999000000,'zz-neg','ZZ neg',now(),now()) ON CONFLICT (id) DO NOTHING");
$db->exec("INSERT INTO forums.reply (id,topic_id,forum_id,created_at,modified_at)
           VALUES ($rid,999000000,999000000,now(),now()) ON CONFLICT (id) DO NOTHING");
$db->exec("INSERT INTO forums.attachment (parent_kind,parent_id,url)
           SELECT 'reply',$rid,'neg-'||g FROM generate_series(1,6) g");
$db->exec("DELETE FROM reply WHERE id = $rid");
check('leak returns without triggers', att($db, 'reply', $rid), 6);
$db->exec("DELETE FROM forums.attachment WHERE parent_id IN ($rid, 999000000)");
$db->exec("DELETE FROM forums.forum WHERE id = 999000000");
$db->exec("CREATE TRIGGER reply_attachment_purge AFTER DELETE ON forums.reply
             FOR EACH ROW EXECUTE FUNCTION forums.attachment_purge_for_parent('reply')");
echo "  triggers restored\n";
} catch (Throwable $e) {
    $FAIL++;
    printf("  [\033[31mFAIL\033[0m] negative control could not run: %s\n", $e->getMessage());
    echo "         (needs table ownership for the DDL — see SETUP at the top of this file;\n";
    echo "          the scratch database must be OWNER \"looth-dev\")\n";
    // Leave the DB usable even if we bailed mid-way.
    try {
        $db->exec("DELETE FROM forums.attachment WHERE parent_id IN (999000001, 999000000)");
        $db->exec("DELETE FROM forums.forum WHERE id = 999000000");
        $db->exec("CREATE TRIGGER reply_attachment_purge AFTER DELETE ON forums.reply
                     FOR EACH ROW EXECUTE FUNCTION forums.attachment_purge_for_parent('reply')");
    } catch (Throwable $ignored) {}
}

printf("\n%s\n", $FAIL === 0 ? "\033[32mALL CHECKS PASSED\033[0m" : "\033[31m$FAIL CHECK(S) FAILED\033[0m");
exit($FAIL === 0 ? 0 : 1);
