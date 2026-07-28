<?php
/**
 * bb-mirror/bin/rematerialize.php — re-run the materializer for specific ids.
 *
 * The reconcile timer only walks posts modified since its bookmark, so a row
 * that drifted and has not been edited since is never revisited. This is the
 * targeted repair for those: it re-reads WordPress (authoritative) and rewrites
 * the mirror row, exactly as _sync.php would on an edit.
 *
 *   sudo -u looth-dev wp eval-file bin/rematerialize.php reply:71432
 *   sudo -u looth-dev wp eval-file bin/rematerialize.php apply reply:71432 topic:71489
 *
 * Note the BARE keyword: wp-cli claims any `--flag` for itself before eval-file's
 * script sees it, so it is `apply`, not `--apply`.
 *
 * DRY RUN IS THE DEFAULT and prints the before/after of every field it
 * would change, so the diff is read before it is applied rather than after.
 *
 * Rollback: the dry run and the apply both print the BEFORE values. Restoring is
 * a plain UPDATE back to those, and the printed block is the rollback statement —
 * see docs/atlas/MIRROR-ATTACHMENT-ORPHANS.md §7 for the worked example.
 *
 * It writes ONLY the ids named on the command line. There is no "all" mode on
 * purpose: a bulk re-materialize is what reconcile is for.
 */

require __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
if (!function_exists('get_post')) {
    fwrite(STDERR, "Run via: sudo -u looth-dev wp eval-file " . __FILE__ . " -- <args>\n");
    exit(2);
}

require_once __DIR__ . '/../lib/materializers.php';

// BARE KEYWORDS, not --flags: wp-cli's own parser claims anything starting with
// `--` before eval-file's script ever sees it ("unknown --dry-run parameter"),
// and `--` does not stop it either. DRY RUN IS THE DEFAULT — same convention as
// bin/fix-attachment-orphans.sh; you have to type `apply` to write.
$argv_in = $args ?? ($argv ?? []);
$targets = [];
$apply   = false;
foreach ($argv_in as $a) {
    $a = (string)$a;
    if ($a === 'dry-run' || $a === '--dry-run') { $apply = false; continue; }
    if ($a === 'apply'   || $a === '--apply')   { $apply = true;  continue; }
    if (preg_match('/^(topic|reply|forum):(\d+)$/', $a, $m)) {
        $targets[] = [$m[1], (int)$m[2]];
    }
}
if (!$targets) {
    fwrite(STDERR, "usage: wp eval-file bin/rematerialize.php [dry-run|apply] kind:id [kind:id ...]\n"
                 . "       kind = topic|reply|forum. Default is dry-run.\n");
    exit(2);
}

$db = bb_mirror_db(readonly: false);

// The fields worth showing a diff for: identity and attribution. Everything else
// the materializer rewrites is derived from the same WP row.
$WATCH = [
    'topic' => ['author_id', 'author_name', 'author_slug', 'title', 'status'],
    'reply' => ['author_id', 'author_name', 'author_slug', 'status'],
    'forum' => ['title', 'slug'],
];

function snapshot(PDO $db, string $kind, int $id, array $cols): ?array {
    $sel = implode(', ', $cols);
    $st  = $db->prepare("SELECT $sel FROM $kind WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

echo ($apply ? "APPLY" : "DRY RUN — nothing will be written") . "\n\n";
$changed = 0;

foreach ($targets as [$kind, $id]) {
    $cols   = $WATCH[$kind];
    $before = snapshot($db, $kind, $id, $cols);

    // What WordPress — the authority — currently says.
    $p = get_post($id);
    printf("%s %d\n", strtoupper($kind), $id);
    if (!$p) {
        echo "  WordPress: post does not exist. The materializer would DELETE this mirror row.\n";
    } elseif ($p->post_type !== $kind) {
        echo "  WordPress: post is a '{$p->post_type}', not a '{$kind}'. The materializer "
           . "would DELETE this mirror row (it belongs in the other table).\n";
    } else {
        printf("  WordPress: post_author=%d, post_status=%s\n", (int)$p->post_author, $p->post_status);
    }
    echo "  mirror BEFORE: " . ($before ? json_encode($before) : "(no row)") . "\n";

    if (!$apply) {
        echo "  (dry run — not written)\n\n";
        continue;
    }

    $db->beginTransaction();
    try {
        switch ($kind) {
            case 'topic': bb_mirror_upsert_topic($id, $db); break;
            case 'reply': bb_mirror_upsert_reply($id, $db); break;
            case 'forum': bb_mirror_upsert_forum($id, $db); break;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        echo "  \033[31mFAILED\033[0m " . $e->getMessage() . "\n";
        echo "  (transaction rolled back; mirror row unchanged)\n\n";
        continue;
    }

    $after = snapshot($db, $kind, $id, $cols);
    echo "  mirror AFTER:  " . ($after ? json_encode($after) : "(row removed)") . "\n";
    if (json_encode($before) !== json_encode($after)) {
        $changed++;
        // The rollback statement, printed at the moment it becomes needed.
        if ($before) {
            $sets = [];
            foreach ($before as $c => $v) {
                $sets[] = "$c = " . ($v === null ? 'NULL' : $db->quote((string)$v));
            }
            echo "  ROLLBACK: UPDATE forums.$kind SET " . implode(', ', $sets) . " WHERE id = $id;\n";
        }
    } else {
        echo "  (no change)\n";
    }
    echo "\n";
}

echo ($apply ? "$changed row(s) changed.\n" : "Dry run complete — nothing written.\n");
