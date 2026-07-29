<?php
/**
 * Duplicate-account merge tool.
 *
 * A member ends up with two WP accounts when the old provisioner, unable to
 * match a patron's Patreon email to an existing account, minted a fresh one
 * carrying that email. Login is email+password and the WP email must equal the
 * member's CURRENT Patreon email, so the account that can actually log in and
 * Patreon-verify is the one whose user_email matches the live patron record.
 * That account is the SURVIVOR; the other is the TWIN and gets retired.
 *
 *   Survivor rule (decided in pairs.json, not here):
 *     1. exactly one side's user_email == its live Patreon email  -> that side
 *     2. both sides match (member holds two Patreon accounts)     -> the
 *        active_patron wins; if both are active it is HELD for a human
 *     3. neither matches                                          -> HELD
 *
 * Nothing is dropped: every row the twin owns is moved to the survivor, except
 * rows that would collide with one the survivor already has, which are deleted
 * after their full contents are recorded so a rollback can recreate them.
 *
 * The twin is retired, never deleted: archived in profile_app, email parked so
 * WP's unique-email index frees it, capabilities stripped, and a
 * lg_merged_into marker left behind so a login attempt on the old address can
 * be pointed at the survivor.
 *
 * Four stores are involved and there is no distributed transaction, so the
 * journal is written and fsynced BEFORE the first write. Each store commits in
 * its own transaction; if a later store fails, the journal still describes
 * everything already done and --rollback undoes it.
 *
 *   php merge-dupes.php --dry-run [--pair=NAME|--all]      writes nothing
 *   php merge-dupes.php --apply --pair=NAME
 *   php merge-dupes.php --verify --pair=NAME
 *   php merge-dupes.php --rollback --journal=FILE
 *
 * --apply refuses a pair marked HOLD unless --force-hold is given, which is
 * there so Ian can merge one after deciding it by hand, not to batch them.
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

// ---------------------------------------------------------------- env / args

$OPT = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $OPT[$m[1]] = $m[2] ?? true;
    else fwrite(STDERR, "ignoring unknown argument: $a\n");
}
$MODE = null;
foreach (['dry-run', 'apply', 'rollback', 'verify'] as $m) if (isset($OPT[$m])) $MODE = $m;
if (!$MODE) { fwrite(STDERR, "one of --dry-run --apply --rollback --verify is required\n"); exit(2); }

if (is_file('/srv/lg-shared/lg-env.php')) require_once '/srv/lg-shared/lg-env.php';
$shared   = function_exists('lg_env') ? lg_env() : [];
$WP_PATH  = $shared['wp_path']          ?? '/var/www/dev';
$MY_DB    = $shared['mysql_db']         ?? 'looth_import';
$BILL_DB  = $shared['mysql_billing_db'] ?? 'lg_membership';
$PG_PROF  = $shared['pg_db_profile']    ?? 'profile_app';
$PG_MIRR  = $shared['pg_db']            ?? 'looth';
$ENV      = $shared['env']              ?? 'unknown';

$JOURNAL_DIR = $OPT['journal-dir'] ?? __DIR__ . '/journal';

// ------------------------------------------------------------------- DB open

/**
 * WP's own credentials are the only ones on the box for looth_import, but
 * postgres uses peer auth and reaching both profile_app and looth means
 * running as the `postgres` OS user, which cannot read wp-config.php. So the
 * wrapper (run-as-root.sh) lifts the credentials out of wp-config and hands
 * them over the environment; reading the file directly stays the fallback for
 * anyone running this as a user that can.
 */
function wp_creds(string $wpPath): array {
    if (getenv('LG_MY_USER') !== false) {
        return ['DB_NAME'     => getenv('LG_MY_NAME') ?: 'looth_import',
                'DB_USER'     => getenv('LG_MY_USER'),
                'DB_PASSWORD' => getenv('LG_MY_PASS') ?: '',
                'DB_HOST'     => getenv('LG_MY_HOST') ?: 'localhost'];
    }
    $f = rtrim($wpPath, '/') . '/wp-config.php';
    if (!is_readable($f)) throw new RuntimeException("cannot read $f (and LG_MY_USER is unset)");
    $src = file_get_contents($f);
    $out = [];
    foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $k) {
        if (!preg_match('/define\(\s*[\'"]' . $k . '[\'"]\s*,\s*[\'"](.*?)[\'"]\s*\)/s', $src, $m))
            throw new RuntimeException("no $k in wp-config.php");
        $out[$k] = $m[1];
    }
    return $out;
}

function pdo_mysql(string $db, array $c): PDO {
    $p = new PDO("mysql:host={$c['DB_HOST']};dbname=$db;charset=utf8mb4", $c['DB_USER'], $c['DB_PASSWORD']);
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $p->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    return $p;
}
function pdo_pg(string $db): PDO {
    $p = new PDO("pgsql:host=/var/run/postgresql;dbname=$db");
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $p;
}

/**
 * The poller owns lg_membership under its own credentials (/etc/lg-poller-db,
 * per membership-pages/config.php); the WP user has no grant on it. It is read
 * only to copy the live patron record into the journal, so a box where the
 * secret is unreadable still merges — it just records less.
 */
function poller_creds(): ?array {
    $f = getenv('LG_POLLER_DB_FILE') ?: '/etc/lg-poller-db';
    if (!is_readable($f)) return null;
    $c = [];
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $c[trim($k)] = trim($v, " \t'\"");
    }
    return isset($c['DB_USER']) ? $c + ['DB_HOST' => '127.0.0.1', 'DB_NAME' => 'lg_membership'] : null;
}

$creds = wp_creds($WP_PATH);
$DB = [
    'my'   => pdo_mysql($MY_DB, $creds),
    'bill' => null,
    'pg'   => pdo_pg($PG_PROF),
    'mir'  => pdo_pg($PG_MIRR),
];
if ($pc = poller_creds()) {
    try { $DB['bill'] = pdo_mysql($pc['DB_NAME'] ?: $BILL_DB, $pc); }
    catch (Throwable $e) { fwrite(STDERR, "note: lg_membership unreadable ({$e->getMessage()}); patron record omitted from journal\n"); }
} else {
    fwrite(STDERR, "note: no /etc/lg-poller-db; patron record omitted from journal\n");
}

// --------------------------------------------------------------- the surface

/**
 * Every looth_import column that pointed at one of the 76 duplicate accounts
 * when this was measured against live. Tables with no rows for the population
 * are deliberately absent — the list is measured, not guessed. `uniq` names
 * the columns that, together with the user column, carry a unique index, so a
 * twin row that would collide with a survivor row is dropped instead of moved.
 */
const MY_REMAP = [
    ['wp_posts',                           'post_author',       'ID',          []],
    ['wp_comments',                        'user_id',           'comment_ID',  []],
    ['wp_bp_activity',                     'user_id',           'id',          []],
    ['wp_bb_notifications_subscriptions',  'user_id',           'id',          []],
    ['wp_bb_topic_relationships',          'user_id',           'id',          []],
    ['wp_bb_user_reactions',               'user_id',           'id',          []],
    ['wp_bb_poll_votes',                   'user_id',           'id',          []],
    ['wp_bb_xprofile_visibility',          'user_id',           'id',          ['field_id']],
    ['wp_bp_document',                     'user_id',           'id',          []],
    ['wp_bp_groups_members',               'user_id',           'id',          ['group_id']],
    ['wp_bp_invitations',                  'user_id',           'id',          []],
    ['wp_bp_media',                        'user_id',           'id',          []],
    ['wp_bp_notifications',                'user_id',           'id',          []],
    ['wp_bp_messages_recipients',          'user_id',           'id',          ['thread_id']],
    ['wp_bp_messages_messages',            'sender_id',         'id',          []],
    ['wp_bp_xprofile_data',                'user_id',           'id',          ['field_id']],
    ['wp_bm_message_recipients',           'user_id',           'id',          ['thread_id']],
    ['wp_bm_user_roles_index',             'user_id',           null,          ['role']],
    ['wp_wc_customer_lookup',              'user_id',           'customer_id', []],
    ['wp_fc_subscribers',                  'user_id',           'id',          []],
    ['wp_fluentform_submissions',          'user_id',           'id',          []],
    ['wp_lg_push_subscriptions',           'wp_user_id',        'id',          []],
    ['wp_statistics_visitor',              'user_id',           'ID',          []],
    ['wp_ulike',                           'user_id',           'id',          []],
    ['wp_ulike_comments',                  'user_id',           'id',          []],
    ['wp_ulike_forums',                    'user_id',           'id',          []],
];

/** profile_app: uuid-keyed columns, then id-keyed ones. */
const PG_UUID_REMAP = [
    ['messages',           'sender_uuid', 'id', []],
    ['message_threads',    'created_by',  'id', []],
    ['message_recipients', 'user_uuid',   null, ['thread_id']],
    ['message_reactions',  'user_uuid',   'id', ['message_id', 'emoji']],
    // notifications.user_uuid is deliberately absent: see plan_notifications().
    ['notifications',      'actor_uuid',  'id', []],
];
const PG_ID_REMAP = [
    ['email_aliases',       'user_id', null, ['email_normalized']],
    ['profile_instruments', 'user_id', 'id', []],
    ['profile_skills',      'user_id', 'id', []],
    ['profile_socials',     'user_id', 'id', []],
];

/**
 * The forum mirror denormalises the author, and reconcile only revisits rows
 * whose post_modified_gmt moved — so an author change has to be written here
 * directly or the public forum keeps showing the twin's name forever.
 *
 * The fifth element names the extra uuid-valued columns that identify the same
 * person alongside the wp id; `actor_key` holds the uuid and is the column the
 * unique index actually uses.
 */
const MIRROR_REMAP = [
    ['forums.reply',                'author_id',    'id', [],                                    []],
    ['forums.topic',                'author_id',    'id', [],                                    []],
    ['discovery.content_item',      'author_id',    'id', [],                                    []],
    ['discovery.comments',          'author_wp_id', 'id', [],                                    ['user_uuid']],
    ['discovery.card_reactions',    'user_wp_id',   null, ['post_type','item_id','actor_key'],   ['user_uuid','actor_key']],
    ['discovery.saved_posts',       'user_wp_id',   null, ['post_type','item_id','actor_key'],   ['user_uuid','actor_key']],
    ['discovery.comment_reactions', 'user_wp_id',   null, ['comment_id','user_wp_id'],           ['user_uuid']],
    ['discovery.guitardle_results', 'wp_user_id',   'id', ['wp_user_id','play_date'],            []],
];
/** discovery.likes is keyed only by uuid, so it is remapped on its own. */
const MIRROR_UUID_REMAP = [
    ['discovery.likes', 'user_uuid', null, ['post_type','item_id','user_uuid']],
];

// ------------------------------------------------------------- plan building

function cols(PDO $db, string $table): array {
    // schema-qualified names arrive as forums.reply
    $parts = explode('.', $table);
    $t = array_pop($parts);
    $s = $parts ? $parts[0] : null;
    $q = $db->prepare(
        "SELECT column_name FROM information_schema.columns
          WHERE table_name = ? " . ($s ? "AND table_schema = ?" : "") . " ORDER BY ordinal_position");
    $q->execute($s ? [$t, $s] : [$t]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Rows the twin owns in one table, split into what can move and what would
 * collide. Collisions are returned with their full contents so rollback can
 * put them back exactly.
 *
 * $sets is every column to rewrite, not just the one identifying the twin:
 * discovery.card_reactions, for instance, carries user_wp_id, user_uuid and a
 * denormalised actor_key that all name the same person, and actor_key is the
 * one in the unique index. Collision is therefore tested against the values
 * the row will have AFTER the rewrite, never the ones it has now.
 */
function plan_remap(PDO $db, string $table, string $col, ?string $pk, array $uniq, $twin, $surv, array $sets = []): array {
    $q = $db->prepare("SELECT * FROM $table WHERE $col = ?");
    $q->execute([$twin]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['move' => [], 'drop' => [], 'pk' => $pk, 'uniq' => $uniq, 'sets' => $sets];
    if (!$sets) $sets = [$col => $surv];

    $key = function (array $r) use ($uniq, $sets) {
        return implode("\x1f", array_map(
            fn($u) => (string)(array_key_exists($u, $sets) ? $sets[$u] : $r[$u]), $uniq));
    };
    $existing = [];
    if ($uniq) {
        $s = $db->prepare("SELECT * FROM $table WHERE $col = ?");
        $s->execute([$surv]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // survivor rows already hold their own values, so read them as-is
            $existing[implode("\x1f", array_map(fn($u) => (string)$r[$u], $uniq))] = true;
        }
    }
    $move = $drop = [];
    foreach ($rows as $r) {
        $k = $uniq ? $key($r) : null;
        if ($uniq && isset($existing[$k])) $drop[] = $r;
        else { $move[] = $r; if ($uniq) $existing[$k] = true; }
    }
    return ['move' => $move, 'drop' => $drop, 'pk' => $pk, 'uniq' => $uniq, 'sets' => $sets];
}

/**
 * connections carries UNIQUE(requester_uuid, addressee_uuid) — directional, so
 * A->B and B->A are both storable and the constraint alone will not stop a
 * merge creating a duplicate of a relationship in the opposite direction. Both
 * shapes are found here. A twin edge whose survivor counterpart is only
 * 'pending' while the twin's was 'accepted' upgrades the survivor's row rather
 * than losing the accepted friendship.
 */
function plan_connections(PDO $pg, string $tu, string $su): array {
    $q = $pg->prepare("SELECT * FROM connections WHERE requester_uuid = ? OR addressee_uuid = ?");
    $q->execute([$tu, $tu]);
    $twinEdges = $q->fetchAll(PDO::FETCH_ASSOC);

    $q = $pg->prepare("SELECT * FROM connections WHERE requester_uuid = ? OR addressee_uuid = ?");
    $q->execute([$su, $su]);
    $survEdges = $q->fetchAll(PDO::FETCH_ASSOC);

    $byPair = [];   // exact directed key
    $survNbr = [];  // other-uuid => survivor edge (either direction)
    foreach ($survEdges as $e) {
        $byPair[$e['requester_uuid'] . '>' . $e['addressee_uuid']] = $e;
        $other = $e['requester_uuid'] === $su ? $e['addressee_uuid'] : $e['requester_uuid'];
        $survNbr[$other] = $e;
    }

    $move = $dropExact = $dropRev = $dropSelf = $upgrade = [];
    foreach ($twinEdges as $e) {
        $other = $e['requester_uuid'] === $tu ? $e['addressee_uuid'] : $e['requester_uuid'];
        if ($other === $su) { $dropSelf[] = $e; continue; }   // twin<->survivor becomes a self-edge

        $newReq = $e['requester_uuid'] === $tu ? $su : $e['requester_uuid'];
        $newAdr = $e['addressee_uuid'] === $tu ? $su : $e['addressee_uuid'];

        $counterpart = null; $kind = null;
        if (isset($byPair[$newReq . '>' . $newAdr])) { $counterpart = $byPair[$newReq . '>' . $newAdr]; $kind = 'exact'; }
        elseif (isset($byPair[$newAdr . '>' . $newReq])) { $counterpart = $byPair[$newAdr . '>' . $newReq]; $kind = 'reversed'; }

        if ($counterpart) {
            if ($e['status'] === 'accepted' && $counterpart['status'] !== 'accepted') {
                $upgrade[] = ['id' => $counterpart['id'], 'from' => $counterpart['status'], 'to' => 'accepted'];
            }
            if ($kind === 'exact') $dropExact[] = $e; else $dropRev[] = $e;
        } else {
            $move[] = ['row' => $e, 'requester_uuid' => $newReq, 'addressee_uuid' => $newAdr];
            $byPair[$newReq . '>' . $newAdr] = ['id' => $e['id'], 'status' => $e['status'],
                                                 'requester_uuid' => $newReq, 'addressee_uuid' => $newAdr];
        }
    }
    return compact('move', 'dropExact', 'dropRev', 'dropSelf', 'upgrade');
}

/**
 * The twin's own notifications are unread badges naming the twin, and they sit
 * behind three partial unique indexes (per connection, per message thread, per
 * unread target) that a straight remap can collide with. They are deleted and
 * recorded rather than moved — the survivor gains nothing from the twin's
 * badges, and a badge pointing at a connection we are about to dedupe would be
 * stale anyway.
 *
 * Separately, notifications.connection_id is ON DELETE CASCADE, so removing a
 * duplicate connection silently takes other members' notifications with it.
 * Those rows are captured here so rollback can put them back; without this the
 * merge is not reversible, which the dev2 proof caught.
 */
function plan_notifications(PDO $pg, string $tu, array $connDeleteIds): array {
    $q = $pg->prepare("SELECT * FROM notifications WHERE user_uuid = ?");
    $q->execute([$tu]);
    $own = $q->fetchAll(PDO::FETCH_ASSOC);
    $ownIds = array_column($own, 'id');

    $cascade = [];
    if ($connDeleteIds) {
        $in = implode(',', array_fill(0, count($connDeleteIds), '?'));
        $q = $pg->prepare("SELECT * FROM notifications WHERE connection_id IN ($in)");
        $q->execute($connDeleteIds);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r)
            if (!in_array($r['id'], $ownIds, false)) $cascade[] = $r;
    }
    return ['own' => $own, 'cascade' => $cascade];
}

function build_plan(array $DB, array $p): array {
    $plan = ['pair' => $p, 'my' => [], 'pg' => [], 'mir' => [], 'meta' => []];
    $T = (int)$p['twin']; $S = (int)$p['survivor'];

    foreach (MY_REMAP as [$t, $c, $pk, $u]) {
        $r = plan_remap($DB['my'], $t, $c, $pk, $u, $T, $S);
        if ($r['move'] || $r['drop']) $plan['my'][] = ['table' => $t, 'col' => $c, 'pk' => $pk, 'uniq' => $u] + $r;
    }
    // wp_bp_friends is two columns on one row and can produce a self-friendship
    foreach (['initiator_user_id', 'friend_user_id'] as $c) {
        $r = plan_remap($DB['my'], 'wp_bp_friends', $c, 'id', [], $T, $S);
        $keep = [];
        foreach ($r['move'] as $row) {
            $ni = $row['initiator_user_id'] == $T ? $S : $row['initiator_user_id'];
            $nf = $row['friend_user_id']    == $T ? $S : $row['friend_user_id'];
            if ($ni == $nf) $r['drop'][] = $row; else $keep[] = $row;
        }
        $r['move'] = $keep;
        if ($r['move'] || $r['drop']) $plan['my'][] = ['table' => 'wp_bp_friends', 'col' => $c, 'pk' => 'id', 'uniq' => []] + $r;
    }

    $tu = $p['twin_uuid']; $su = $p['survivor_uuid'];
    $plan['pg']['connections'] = plan_connections($DB['pg'], $tu, $su);
    $k = $plan['pg']['connections'];
    $delIds = array_column(array_merge($k['dropExact'], $k['dropRev'], $k['dropSelf']), 'id');
    $plan['pg']['notifications'] = plan_notifications($DB['pg'], $tu, $delIds);
    foreach (PG_UUID_REMAP as [$t, $c, $pk, $u]) {
        $r = plan_remap($DB['pg'], $t, $c, $pk, $u, $tu, $su);
        if ($r['move'] || $r['drop']) $plan['pg']['remap'][] = ['table' => $t, 'col' => $c, 'pk' => $pk, 'uniq' => $u] + $r;
    }
    foreach (PG_ID_REMAP as [$t, $c, $pk, $u]) {
        $r = plan_remap($DB['pg'], $t, $c, $pk, $u, (int)$p['twin_pg'], (int)$p['survivor_pg']);
        if ($r['move'] || $r['drop']) $plan['pg']['remap'][] = ['table' => $t, 'col' => $c, 'pk' => $pk, 'uniq' => $u] + $r;
    }
    foreach (MIRROR_REMAP as [$t, $c, $pk, $u, $uuidCols]) {
        $sets = [$c => $S];
        foreach ($uuidCols as $uc) $sets[$uc] = $su;
        $r = plan_remap($DB['mir'], $t, $c, $pk, $u, $T, $S, $sets);
        if ($r['move'] || $r['drop']) $plan['mir'][] = ['table' => $t, 'col' => $c, 'pk' => $pk, 'uniq' => $u] + $r;
    }
    foreach (MIRROR_UUID_REMAP as [$t, $c, $pk, $u]) {
        $r = plan_remap($DB['mir'], $t, $c, $pk, $u, $tu, $su);
        if ($r['move'] || $r['drop']) $plan['mir'][] = ['table' => $t, 'col' => $c, 'pk' => $pk, 'uniq' => $u] + $r;
    }

    // prior values for the retirement itself
    $q = $DB['my']->prepare("SELECT ID, user_login, user_email, user_status, display_name FROM wp_users WHERE ID IN (?,?)");
    $q->execute([$T, $S]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $plan['meta']['wp_users'][$r['ID']] = $r;

    $q = $DB['my']->prepare("SELECT meta_key, meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key IN ('wp_capabilities','lg_merged_into','lg_merged_at','lg_prior_email')");
    $q->execute([$T]);
    $plan['meta']['twin_usermeta'] = $q->fetchAll(PDO::FETCH_KEY_PAIR);

    $q = $DB['pg']->prepare("SELECT id, primary_email, archived_at, slug FROM users WHERE id IN (?,?)");
    $q->execute([(int)$p['twin_pg'], (int)$p['survivor_pg']]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $plan['meta']['pg_users'][$r['id']] = $r;

    if ($DB['bill']) {
        $q = $DB['bill']->prepare("SELECT * FROM lg_patreon_members WHERE wp_user_id IN (?,?)");
        $q->execute([$T, $S]);
        $plan['meta']['patron'] = $q->fetchAll(PDO::FETCH_ASSOC);
    }

    // author display fields the mirror denormalises
    $q = $DB['mir']->prepare("SELECT id, display_name, slug FROM forums.person WHERE id = ?");
    $q->execute([$S]);
    $plan['meta']['survivor_person'] = $q->fetch(PDO::FETCH_ASSOC) ?: null;

    // The apply rewrites author_name/author_slug for every row the survivor
    // ends up owning, including the ones it already owned, so their previous
    // values have to be recorded or a rollback leaves the survivor's own
    // history renamed. The dev2 proof caught this.
    foreach (['forums.reply', 'forums.topic'] as $tbl) {
        $q = $DB['mir']->prepare("SELECT id, author_name, author_slug FROM $tbl WHERE author_id IN (?,?)");
        $q->execute([$T, $S]);
        $plan['meta']['mirror_author_prior'][$tbl] = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ([['discovery.content_item', 'author_id'], ['discovery.comments', 'author_wp_id']] as [$tbl, $ac]) {
        $q = $DB['mir']->prepare("SELECT id, author_name FROM $tbl WHERE $ac IN (?,?)");
        $q->execute([$T, $S]);
        $plan['meta']['mirror_author_prior'][$tbl] = $q->fetchAll(PDO::FETCH_ASSOC);
    }

    return $plan;
}

// ----------------------------------------------------------------- reporting

function count_plan(array $plan): array {
    $c = ['my_move' => 0, 'my_drop' => 0, 'pg_move' => 0, 'pg_drop' => 0, 'mir_move' => 0, 'mir_drop' => 0,
          'conn_move' => 0, 'conn_drop' => 0, 'conn_upgrade' => 0, 'posts' => 0];
    foreach ($plan['my'] as $t) {
        $c['my_move'] += count($t['move']); $c['my_drop'] += count($t['drop']);
        if ($t['table'] === 'wp_posts') $c['posts'] = count($t['move']);
    }
    foreach ($plan['pg']['remap'] ?? [] as $t) { $c['pg_move'] += count($t['move']); $c['pg_drop'] += count($t['drop']); }
    foreach ($plan['mir'] as $t) { $c['mir_move'] += count($t['move']); $c['mir_drop'] += count($t['drop']); }
    $c['notif_drop'] = count($plan['pg']['notifications']['own'] ?? []);
    $c['notif_cascade'] = count($plan['pg']['notifications']['cascade'] ?? []);
    $k = $plan['pg']['connections'];
    $c['conn_move'] = count($k['move']);
    $c['conn_drop'] = count($k['dropExact']) + count($k['dropRev']) + count($k['dropSelf']);
    $c['conn_upgrade'] = count($k['upgrade']);
    return $c;
}

function print_plan(array $plan, bool $verbose): void {
    $p = $plan['pair']; $c = count_plan($plan);
    $hold = $p['hold'] ? ('  ** HOLD: ' . implode(',', $p['hold']) . ' **') : '';
    printf("\n=== %s%s\n", strtoupper($p['name']), $hold);
    printf("  survivor  wp=%-5d pg=%-5d %-34s  patron=%s\n", $p['survivor'], $p['survivor_pg'], $p['survivor_wp_email'], $p['survivor_patron_status'] ?: '-');
    printf("  retire    wp=%-5d pg=%-5d %-34s  patron=%s\n", $p['twin'], $p['twin_pg'], $p['twin_wp_email'] ?: '(none)', $p['twin_patron_status'] ?: '-');
    printf("  rule      %s\n", $p['rule']);
    printf("  email     %s  -> survivor  (%s)\n", $p['win_email'],
        $p['email_changes'] ? 'CHANGES survivor user_email' : 'already correct, no change');
    printf("  moves     posts=%d  other-wp-rows=%d  pg-rows=%d  mirror-rows=%d\n",
        $c['posts'], $c['my_move'] - $c['posts'], $c['pg_move'], $c['mir_move']);
    printf("  conns     move=%d  drop-duplicate=%d  status-upgrade=%d\n", $c['conn_move'], $c['conn_drop'], $c['conn_upgrade']);
    $conf = [];
    if ($c['my_drop'])  $conf[] = "{$c['my_drop']} wp row(s) collide -> dropped";
    if ($c['pg_drop'])  $conf[] = "{$c['pg_drop']} pg row(s) collide -> dropped";
    if ($c['mir_drop']) $conf[] = "{$c['mir_drop']} mirror row(s) collide -> dropped";
    $k = $plan['pg']['connections'];
    if ($k['dropRev'])  $conf[] = count($k['dropRev']) . " reversed-direction duplicate connection(s)";
    if ($k['dropSelf']) $conf[] = count($k['dropSelf']) . " twin<->survivor connection(s) -> would self-link, dropped";
    if ($c['notif_drop'])    $conf[] = "{$c['notif_drop']} twin notification(s) deleted (recorded)";
    if ($c['notif_cascade']) $conf[] = "{$c['notif_cascade']} notification(s) cascade-deleted with dropped connections (recorded)";
    printf("  conflicts %s\n", $conf ? implode('; ', $conf) : 'none');
    if ($p['notes']) printf("  notes     %s\n", implode(',', $p['notes']));

    if ($verbose) {
        foreach ($plan['my'] as $t)
            if ($t['move'] || $t['drop']) printf("      wp  %-38s move=%-4d drop=%d\n", $t['table'] . '.' . $t['col'], count($t['move']), count($t['drop']));
        foreach ($plan['pg']['remap'] ?? [] as $t)
            printf("      pg  %-38s move=%-4d drop=%d\n", $t['table'] . '.' . $t['col'], count($t['move']), count($t['drop']));
        foreach ($plan['mir'] as $t)
            printf("      mir %-38s move=%-4d drop=%d\n", $t['table'] . '.' . $t['col'], count($t['move']), count($t['drop']));
    }
}

// ------------------------------------------------------------------- execute

function journal_write(string $dir, array $plan): string {
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $f = sprintf('%s/%s-%d-into-%d-%s.json', $dir,
        preg_replace('/[^a-z0-9]+/', '-', strtolower($plan['pair']['name'])),
        $plan['pair']['twin'], $plan['pair']['survivor'], gmdate('Ymd-His'));
    $fh = fopen($f, 'w');
    fwrite($fh, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fflush($fh); fsync($fh); fclose($fh);   // durable before the first write
    return $f;
}

/**
 * Identify one recorded row again: by primary key when there is one, else by
 * the columns that actually form its unique key. Matching on every column
 * instead would drag booleans and timestamps into the bind list, and PDO's
 * pgsql driver turns a PHP false into an empty string that postgres rejects.
 */
function row_where(array $r, ?string $pk, array $keyCols = []): array {
    if ($pk) return ["$pk = ?", [$r[$pk]]];
    $where = []; $vals = [];
    foreach ($keyCols as $k) {
        if (!array_key_exists($k, $r)) continue;
        if ($r[$k] === null) { $where[] = "$k IS NULL"; }
        else { $where[] = "$k = ?"; $vals[] = $r[$k]; }
    }
    if (!$where) throw new RuntimeException('row_where: no primary key and no key columns');
    return [implode(' AND ', $where), $vals];
}

/**
 * Columns the database computes for itself. A GENERATED ALWAYS column (the
 * mirror's actor_key, derived from user_uuid, and content_item.tsv) rejects
 * any direct write, so it is excluded from updates and inserts — it follows
 * the column it is derived from. An IDENTITY ALWAYS column rejects an explicit
 * insert too, but a rollback has to restore the original id, so those inserts
 * say OVERRIDING SYSTEM VALUE. Read from the catalog rather than hardcoded, so
 * a schema change cannot quietly invalidate the list.
 */
function generated_cols(PDO $db, string $table): array {
    static $cache = [];
    $ck = spl_object_id($db) . '|' . $table;
    if (isset($cache[$ck])) return $cache[$ck];
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') return $cache[$ck] = ['always' => [], 'identity' => []];
    $parts = explode('.', $table); $t = array_pop($parts); $sch = $parts ? $parts[0] : 'public';
    $q = $db->prepare("SELECT column_name, is_generated, identity_generation
                         FROM information_schema.columns WHERE table_schema = ? AND table_name = ?");
    $q->execute([$sch, $t]);
    $out = ['always' => [], 'identity' => []];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['is_generated'] === 'ALWAYS')            $out['always'][]   = $r['column_name'];
        if ($r['identity_generation'] === 'ALWAYS')     $out['identity'][] = $r['column_name'];
    }
    return $cache[$ck] = $out;
}

/** postgres will not accept PHP's false as a boolean bind; 't'/'f' it does. */
function bindable(array $row): array {
    foreach ($row as $k => $v) if (is_bool($v)) $row[$k] = $v ? 't' : 'f';
    return $row;
}

/**
 * Forward: drop the colliding rows, then rewrite every column in `sets` on the
 * rows that move. Restore: put the recorded values back on those same rows and
 * re-insert the dropped ones. The journal holds each row exactly as it was, so
 * restore reads its target values straight off the record.
 */
function apply_remap(PDO $db, array $t, bool $restore = false): array {
    $done = ['moved' => 0, 'dropped' => 0];
    $pk   = $t['pk'];

    $keyCols = array_values(array_unique(array_merge($t['uniq'] ?? [], array_keys($t['sets']))));
    if (!$restore) {
        foreach ($t['drop'] as $r) {
            [$w, $v] = row_where($r, $pk, $keyCols);
            $q = $db->prepare("DELETE FROM {$t['table']} WHERE $w");
            $q->execute($v);
            $done['dropped']++;
        }
    }
    $gen = generated_cols($db, $t['table']);
    foreach ($t['move'] as $r) {
        $cols = []; $vals = [];
        foreach ($t['sets'] as $c => $val) {
            if (in_array($c, $gen['always'], true)) continue;   // follows its source column
            $cols[] = "$c = ?"; $vals[] = $restore ? $r[$c] : $val;
        }
        if (!$cols) continue;
        // With no surrogate key the row can only be found by its contents, which
        // on the way back are the post-move ones.
        $target = $r;
        if ($restore) foreach ($t['sets'] as $c => $val) $target[$c] = $val;
        [$w, $wv] = row_where($target, $pk, $keyCols);
        $q = $db->prepare("UPDATE {$t['table']} SET " . implode(', ', $cols) . " WHERE $w");
        $q->execute(array_merge($vals, $wv));
        $n = $q->rowCount();
        // A move that touches nothing means the row is gone since the plan was
        // built — a stale plan or an unnoticed cascade. Stop rather than report
        // a merge that did not happen.
        if ($n === 0) {
            $what = sprintf("%s: expected to rewrite 1 row of %s but matched none (%s)",
                $restore ? 'rollback' : 'apply', $t['table'],
                json_encode($pk ? [$pk => $target[$pk]] : array_intersect_key($target, array_flip($keyCols))));
            // Forward must stop: a vanished row means the plan is stale. Rollback
            // keeps going — it may be undoing a run that failed part-way, where
            // some of these rows were never written in the first place.
            if (!$restore) throw new RuntimeException("$what — plan is stale, nothing further applied");
            fwrite(STDERR, "  note: $what (already absent, continuing)\n");
        }
        $done['moved'] += $n;
    }
    if ($restore) foreach ($t['drop'] as $r) { reinsert($db, $t['table'], $r, true); $done['dropped']++; }
    return $done;
}

function do_apply(array $DB, array $plan, string $journalDir): void {
    $p = $plan['pair'];
    $T = (int)$p['twin']; $S = (int)$p['survivor'];
    $f = journal_write($journalDir, $plan);
    echo "journal: $f\n";

    // ---- looth_import
    $DB['my']->beginTransaction();
    try {
        foreach ($plan['my'] as $t) apply_remap($DB['my'], $t);
        if ($p['email_changes']) {
            $q = $DB['my']->prepare("UPDATE wp_users SET user_email = ? WHERE ID = ?");
            $q->execute([$p['win_email'], $S]);
        }
        // park the twin's address so WP's unique-email index frees it
        $park = sprintf('merged-%d@retired.invalid', $T);
        $q = $DB['my']->prepare("UPDATE wp_users SET user_email = ? WHERE ID = ?");
        $q->execute([$park, $T]);
        foreach ([['lg_merged_into', (string)$S],
                  ['lg_merged_at', gmdate('c')],
                  ['lg_prior_email', (string)$plan['meta']['wp_users'][$T]['user_email']]] as [$k, $v]) {
            $q = $DB['my']->prepare("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (?,?,?)");
            $q->execute([$T, $k, $v]);
        }
        // strip capabilities: retired, not deleted, and restorable from the journal
        $q = $DB['my']->prepare("UPDATE wp_usermeta SET meta_value = ? WHERE user_id = ? AND meta_key = 'wp_capabilities'");
        $q->execute(['a:0:{}', $T]);
        $DB['my']->commit();
        echo "  looth_import: ok\n";
    } catch (Throwable $e) { $DB['my']->rollBack(); throw $e; }

    // ---- profile_app
    $DB['pg']->beginTransaction();
    try {
        // remaps first: a cascade from a connection delete must not remove a row
        // the remap expects to find (dev2 proof caught exactly that)
        foreach ($plan['pg']['remap'] ?? [] as $t) apply_remap($DB['pg'], $t);
        foreach ($plan['pg']['notifications']['own'] as $n) {
            $q = $DB['pg']->prepare("DELETE FROM notifications WHERE id = ?"); $q->execute([$n['id']]);
        }
        $k = $plan['pg']['connections'];
        foreach ($k['move'] as $m) {
            $q = $DB['pg']->prepare("UPDATE connections SET requester_uuid = ?, addressee_uuid = ? WHERE id = ?");
            $q->execute([$m['requester_uuid'], $m['addressee_uuid'], $m['row']['id']]);
        }
        foreach ($k['upgrade'] as $u) {
            $q = $DB['pg']->prepare("UPDATE connections SET status = ? WHERE id = ?"); $q->execute([$u['to'], $u['id']]);
        }
        foreach (array_merge($k['dropExact'], $k['dropRev'], $k['dropSelf']) as $e) {
            $q = $DB['pg']->prepare("DELETE FROM connections WHERE id = ?"); $q->execute([$e['id']]);
        }
        $q = $DB['pg']->prepare("UPDATE users SET archived_at = now(), primary_email = ? WHERE id = ?");
        $q->execute([sprintf('merged-%d@retired.invalid', $T), (int)$p['twin_pg']]);
        $DB['pg']->commit();
        echo "  profile_app: ok\n";
    } catch (Throwable $e) { $DB['pg']->rollBack(); throw $e; }

    // ---- forum mirror
    $DB['mir']->beginTransaction();
    try {
        foreach ($plan['mir'] as $t) apply_remap($DB['mir'], $t);
        $person = $plan['meta']['survivor_person'];
        if ($person) {
            foreach (['forums.reply', 'forums.topic'] as $tbl) {
                $q = $DB['mir']->prepare("UPDATE $tbl SET author_name = ?, author_slug = ? WHERE author_id = ?");
                $q->execute([$person['display_name'], $person['slug'], $S]);
            }
        }
        // discovery caches the author name too and re-syncs on the same watermark
        $sname = $plan['meta']['wp_users'][$S]['display_name'] ?? ($person['display_name'] ?? null);
        if ($sname !== null) {
            $q = $DB['mir']->prepare("UPDATE discovery.content_item SET author_name = ? WHERE author_id = ?");
            $q->execute([$sname, $S]);
            $q = $DB['mir']->prepare("UPDATE discovery.comments SET author_name = ? WHERE author_wp_id = ?");
            $q->execute([$sname, $S]);
        }
        $DB['mir']->commit();
        echo "  looth mirror: ok\n";
    } catch (Throwable $e) { $DB['mir']->rollBack(); throw $e; }

    echo "merged. rollback with: php " . basename(__FILE__) . " --rollback --journal=$f\n";
}

function do_rollback(array $DB, array $plan): void {
    $p = $plan['pair'];
    $T = (int)$p['twin']; $S = (int)$p['survivor'];

    $DB['mir']->beginTransaction();
    try {
        foreach (array_reverse($plan['mir']) as $t) apply_remap($DB['mir'], $t, true);
        foreach ($plan['meta']['mirror_author_prior'] ?? [] as $tbl => $rows) {
            $hasSlug = in_array($tbl, ['forums.reply', 'forums.topic'], true);
            foreach ($rows as $r) {
                $q = $DB['mir']->prepare($hasSlug
                    ? "UPDATE $tbl SET author_name = ?, author_slug = ? WHERE id = ?"
                    : "UPDATE $tbl SET author_name = ? WHERE id = ?");
                $q->execute($hasSlug ? [$r['author_name'], $r['author_slug'], $r['id']] : [$r['author_name'], $r['id']]);
            }
        }
        // author_name/slug re-derive on the next reconcile; restore what we know
        $DB['mir']->commit(); echo "  looth mirror: restored\n";
    } catch (Throwable $e) { $DB['mir']->rollBack(); throw $e; }

    $DB['pg']->beginTransaction();
    try {
        $prior = $plan['meta']['pg_users'][(string)$p['twin_pg']] ?? $plan['meta']['pg_users'][$p['twin_pg']] ?? null;
        if ($prior) {
            $q = $DB['pg']->prepare("UPDATE users SET archived_at = ?, primary_email = ? WHERE id = ?");
            $q->execute([$prior['archived_at'], $prior['primary_email'], (int)$p['twin_pg']]);
        }
        $k = $plan['pg']['connections'];
        // connections first: the notifications below carry an FK to them
        foreach (array_merge($k['dropExact'], $k['dropRev'], $k['dropSelf']) as $e) reinsert($DB['pg'], 'connections', $e);
        foreach ($k['upgrade'] as $u) {
            $q = $DB['pg']->prepare("UPDATE connections SET status = ? WHERE id = ?"); $q->execute([$u['from'], $u['id']]);
        }
        foreach ($k['move'] as $m) {
            $q = $DB['pg']->prepare("UPDATE connections SET requester_uuid = ?, addressee_uuid = ? WHERE id = ?");
            $q->execute([$m['row']['requester_uuid'], $m['row']['addressee_uuid'], $m['row']['id']]);
        }
        foreach ($plan['pg']['notifications']['cascade'] as $n) reinsert($DB['pg'], 'notifications', $n);
        foreach ($plan['pg']['notifications']['own'] as $n) reinsert($DB['pg'], 'notifications', $n);
        foreach (array_reverse($plan['pg']['remap'] ?? []) as $t) apply_remap($DB['pg'], $t, true);
        $DB['pg']->commit(); echo "  profile_app: restored\n";
    } catch (Throwable $e) { $DB['pg']->rollBack(); throw $e; }

    $DB['my']->beginTransaction();
    try {
        $tw = $plan['meta']['wp_users'][(string)$T] ?? $plan['meta']['wp_users'][$T];
        $q = $DB['my']->prepare("UPDATE wp_users SET user_email = ? WHERE ID = ?");
        $q->execute([$tw['user_email'], $T]);
        if ($p['email_changes']) {
            $sv = $plan['meta']['wp_users'][(string)$S] ?? $plan['meta']['wp_users'][$S];
            $q = $DB['my']->prepare("UPDATE wp_users SET user_email = ? WHERE ID = ?");
            $q->execute([$sv['user_email'], $S]);
        }
        $caps = $plan['meta']['twin_usermeta']['wp_capabilities'] ?? null;
        if ($caps !== null) {
            $q = $DB['my']->prepare("UPDATE wp_usermeta SET meta_value = ? WHERE user_id = ? AND meta_key = 'wp_capabilities'");
            $q->execute([$caps, $T]);
        }
        $q = $DB['my']->prepare("DELETE FROM wp_usermeta WHERE user_id = ? AND meta_key IN ('lg_merged_into','lg_merged_at','lg_prior_email')");
        $q->execute([$T]);
        foreach (array_reverse($plan['my']) as $t) apply_remap($DB['my'], $t, true);
        $DB['my']->commit(); echo "  looth_import: restored\n";
    } catch (Throwable $e) { $DB['my']->rollBack(); throw $e; }
    echo "rolled back.\n";
}

/** $skipExisting lets a rollback re-run over rows that are already back. */
function reinsert(PDO $db, string $table, array $row, bool $skipExisting = true): void {
    $gen = generated_cols($db, $table);
    foreach ($gen['always'] as $c) unset($row[$c]);      // cannot be written at all
    $row  = bindable($row);
    $cols = array_keys($row);
    $isPg = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    $override = ($isPg && array_intersect($cols, $gen['identity'])) ? ' OVERRIDING SYSTEM VALUE' : '';
    $sql  = "INSERT INTO $table (" . implode(',', $cols) . ")$override VALUES (" .
            implode(',', array_fill(0, count($cols), '?')) . ")";
    if ($skipExisting) $sql .= $isPg ? ' ON CONFLICT DO NOTHING' : '';
    if ($skipExisting && !$isPg) $sql = preg_replace('/^INSERT INTO/', 'INSERT IGNORE INTO', $sql);
    $q = $db->prepare($sql);
    $q->execute(array_values($row));
}

/** Both halves present on the survivor, nothing left on the twin. */
function do_verify(array $DB, array $p): void {
    $T = (int)$p['twin']; $S = (int)$p['survivor'];
    $bad = 0;
    foreach (MY_REMAP as [$t, $c, , ]) {
        $q = $DB['my']->prepare("SELECT COUNT(*) FROM $t WHERE $c = ?"); $q->execute([$T]);
        if ($n = (int)$q->fetchColumn()) { printf("  LEFTOVER %-40s %d row(s) still on twin\n", "$t.$c", $n); $bad++; }
    }
    $q = $DB['pg']->prepare("SELECT COUNT(*) FROM connections WHERE requester_uuid = ? OR addressee_uuid = ?");
    $q->execute([$p['twin_uuid'], $p['twin_uuid']]);
    if ($n = (int)$q->fetchColumn()) { printf("  LEFTOVER connections %d\n", $n); $bad++; }
    foreach ([['messages','sender_uuid'],['message_recipients','user_uuid'],['notifications','user_uuid'],
              ['notifications','actor_uuid'],['message_threads','created_by']] as [$t, $c]) {
        $q = $DB['pg']->prepare("SELECT COUNT(*) FROM $t WHERE $c = ?"); $q->execute([$p['twin_uuid']]);
        if ($n = (int)$q->fetchColumn()) { printf("  LEFTOVER %-30s %d\n", "$t.$c", $n); $bad++; }
    }
    $q = $DB['pg']->prepare("SELECT COUNT(*) FROM email_aliases WHERE user_id = ?"); $q->execute([(int)$p['twin_pg']]);
    if ($n = (int)$q->fetchColumn()) { printf("  LEFTOVER email_aliases %d\n", $n); $bad++; }
    foreach (MIRROR_REMAP as [$t, $c, , ]) {
        $q = $DB['mir']->prepare("SELECT COUNT(*) FROM $t WHERE $c = ?"); $q->execute([$T]);
        if ($n = (int)$q->fetchColumn()) { printf("  LEFTOVER %-40s %d\n", "$t.$c", $n); $bad++; }
    }
    $q = $DB['my']->prepare("SELECT user_email FROM wp_users WHERE ID = ?"); $q->execute([$S]);
    $em = $q->fetchColumn();
    if (strtolower((string)$em) !== strtolower($p['win_email'])) { printf("  EMAIL survivor has %s, expected %s\n", $em, $p['win_email']); $bad++; }
    $q = $DB['pg']->prepare("SELECT archived_at IS NOT NULL FROM users WHERE id = ?"); $q->execute([(int)$p['twin_pg']]);
    if (!$q->fetchColumn()) { echo "  TWIN not archived in profile_app\n"; $bad++; }
    echo $bad ? "  VERIFY FAILED ($bad problem(s))\n" : "  VERIFY OK — twin drained, survivor holds both halves, email correct, twin archived\n";
}

// ---------------------------------------------------------------------- main

$pairs = json_decode(file_get_contents(__DIR__ . '/pairs.json'), true);
if (!$pairs) { fwrite(STDERR, "cannot read pairs.json\n"); exit(2); }

if ($MODE === 'rollback') {
    $jf = $OPT['journal'] ?? null;
    if (!$jf || !is_readable($jf)) { fwrite(STDERR, "--journal=FILE required and must be readable\n"); exit(2); }
    $plan = json_decode(file_get_contents($jf), true);
    printf("env=%s  rolling back %s (%d -> %d)\n", $ENV, $plan['pair']['name'], $plan['pair']['twin'], $plan['pair']['survivor']);
    do_rollback($DB, $plan);
    exit(0);
}

$want = $OPT['pair'] ?? null;
$sel  = array_values(array_filter($pairs, fn($p) => $want === null || stripos($p['name'], (string)$want) !== false));
if (!$sel) { fwrite(STDERR, "no pair matches --pair=$want\n"); exit(2); }
if ($MODE !== 'dry-run' && count($sel) > 1) { fwrite(STDERR, "--pair must select exactly one pair for $MODE (matched " . count($sel) . ")\n"); exit(2); }

printf("env=%s  wp=%s  mysql=%s/%s  pg=%s/%s  mode=%s\n", $ENV, $WP_PATH, $MY_DB, $BILL_DB, $PG_PROF, $PG_MIRR, $MODE);

if ($MODE === 'verify') { printf("\n=== VERIFY %s\n", strtoupper($sel[0]['name'])); do_verify($DB, $sel[0]); exit(0); }

$tot = ['posts' => 0, 'my_move' => 0, 'pg_move' => 0, 'mir_move' => 0, 'conn_move' => 0, 'conn_drop' => 0];
foreach ($sel as $p) {
    if ($MODE === 'dry-run' && !isset($OPT['all']) && $want === null && $p['action'] === 'HOLD') {
        // still shown, just never counted as mergeable
    }
    $plan = build_plan($DB, $p);
    print_plan($plan, isset($OPT['verbose']));
    $c = count_plan($plan);
    foreach ($tot as $k => $_) $tot[$k] += $c[$k] ?? 0;

    if ($MODE === 'apply') {
        if ($p['hold'] && !isset($OPT['force-hold'])) {
            fwrite(STDERR, "\nREFUSING: {$p['name']} is HELD (" . implode(',', $p['hold']) . "). Decide it by hand, then re-run with --force-hold.\n");
            exit(3);
        }
        do_apply($DB, $plan, $JOURNAL_DIR);
    }
}

if ($MODE === 'dry-run') {
    $auto = count(array_filter($sel, fn($p) => $p['action'] === 'AUTO'));
    printf("\n---- %d pair(s): %d auto, %d held ----\n", count($sel), $auto, count($sel) - $auto);
    printf("would move: %d forum/other posts, %d other wp rows, %d profile_app rows, %d mirror rows, %d connections\n",
        $tot['posts'], $tot['my_move'] - $tot['posts'], $tot['pg_move'], $tot['mir_move'], $tot['conn_move']);
    printf("would drop %d duplicate connection(s). NOTHING WAS WRITTEN.\n", $tot['conn_drop']);
}
