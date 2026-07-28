<?php
declare(strict_types=1);

/**
 * backfill-slugs.php — give members a profile URL made of their NAME.
 *
 * `/u/<slug>` resolves from ONE store: profile-app Postgres `users.slug`. This script is
 * the only supported way to bulk-change that column. It never writes WordPress.
 *
 * THE RULINGS IT IMPLEMENTS (Ian)
 * -------------------------------
 *  R1  The slug is THE PROFILE DISPLAY NAME, CLEANED (2026-07-27, supersedes the 7/25
 *      chain in docs/atlas/PATREON-HANDLE-BACKFILL-DRYRUN.md). Not Patreon full_name,
 *      not vanity, not the email local-part. "Cleaned" = decode entities, strip
 *      punctuation, collapse whitespace, lowercase, dash-join, 30-char cap trimmed at a
 *      word boundary. It does NOT mean pruning the business tail.
 *
 *  R2  KEEP THE BUSINESS NAME. "Doug Lawrence Doug Lawrence Guitars" derives from that
 *      whole string. The business prune is a separate, PARKED thing.
 *
 *  R3  NO NUMERIC SUFFIXES AS THE ANSWER TO A COLLISION (2026-07-27). Two Daves do not
 *      become dave2/dave3. A contested handle is EXPANDED from a fuller identity
 *      (Patreon full_name -> vanity -> email local-part) into `dave-thurston`. A numeric
 *      suffix is a LAST RESORT that must be reported by name, and the target is zero.
 *
 *  R4  NEVER OVERWRITE A NAME THE MEMBER SUBMITTED (2026-07-27, HARD, outranks R3 where
 *      they conflict). API expansion is only licensed where we are inventing an identity
 *      for someone who never supplied one. It is NOT licence to "improve" a chosen name.
 *      See PROVENANCE below — this is the load-bearing safety property.
 *
 *  R5  Every existing URL keeps working. Each change parks the outgoing handle in
 *      `slug_history`, which u.php turns into a 301. Nothing 404s because of this script.
 *
 * PROVENANCE — how we decide a name is the member's own
 * -----------------------------------------------------
 * There is NO stored provenance. Measured, not assumed:
 *   - `users` has no source/origin/provenance column, and there is no audit table.
 *   - `updated_at` IS maintained by a `users_touch` trigger, but it is USELESS as an
 *     intent signal: 1,920 of 1,925 dev2 rows differ from `created_at`, because avatar,
 *     location and geocode backfills touched nearly every row.
 *   - `profiles.claimed_via` is 96% polluted the same way — 659 of 684 dev2 rows are
 *     `backfill_location`. Only `onboard|direct|menu` (24 rows) reflect a real person.
 *   - Name SHAPE cannot discriminate: zero members have a `patreon_*` or empty
 *     display_name. Everybody already looks like a human.
 *
 * So the only decisive test is comparing the stored display_name against what Patreon
 * holds: names were seeded FROM the Patreon import, so a name that DIFFERS from Patreon's
 * full_name was edited by the member and is theirs. That needs the creator token.
 *
 * Everything else is treated as USER-SUBMITTED — the conservative default — which means
 * it is derived from, never expanded from. Unknown provenance never licenses a rewrite.
 *
 * USAGE
 *   sudo -u profile-app php backfill-slugs.php --html=/path/report.html   # dry run
 *   sudo -u profile-app php backfill-slugs.php --apply
 *   [--hold-bare-names] [--scope=junk|repair] [--limit=N] [--tsv=] [--db-only] [--applied-since=MIN]
 *
 * --db-only skips the Patreon sweep (no creator token on this box): collisions then
 * cannot be expanded and are reported as needing a ruling rather than silently suffixed.
 */

require dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/lib/patreon-identity.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Slug;

$APPLY   = in_array('--apply', $argv, true);
$DB_ONLY = in_array('--db-only', $argv, true);
$SCOPE   = 'junk';
$LIMIT   = 0;
$SINCE   = 0;
$TSV     = null;
$HTML    = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--scope='))         $SCOPE = substr($a, 8);
    if (str_starts_with($a, '--limit='))         $LIMIT = (int) substr($a, 8);
    if (str_starts_with($a, '--tsv='))           $TSV   = substr($a, 6);
    if (str_starts_with($a, '--html='))          $HTML  = substr($a, 7);
    if (str_starts_with($a, '--applied-since=')) $SINCE = (int) substr($a, 16);
}
if (!in_array($SCOPE, ['junk', 'repair'], true)) {
    fwrite(STDERR, "unknown --scope=$SCOPE (want junk|repair)\n");
    exit(2);
}

// ── OFFLINE MODE ─────────────────────────────────────────────────────────────
// --from-tsv/--owners-tsv run the derivation against a read-only EXPORT instead of a
// live connection. This exists because the box that must be reported on (live) and the
// box that can run this code are not always the same box, and the alternative —
// reimplementing the derivation in SQL to run over there — is precisely the split-brain
// this lane keeps closing. Same code, exported data, and it CANNOT write: offline mode
// forces dry-run because there is no connection to write through.
$FROM = null; $OWNERS = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--from-tsv='))   $FROM   = substr($a, 11);
    if (str_starts_with($a, '--owners-tsv=')) $OWNERS = substr($a, 13);
}
$OFFLINE = $FROM !== null;
if ($OFFLINE) { $APPLY = false; $SINCE = 0; }

$readTsv = function (string $path): array {
    $rows = [];
    $fh = fopen($path, 'r');
    if ($fh === false) { fwrite(STDERR, "cannot read $path\n"); exit(2); }
    $head = fgetcsv($fh, 0, "\t", '"', '\\');
    while (($r = fgetcsv($fh, 0, "\t", '"', '\\')) !== false) {
        if ($r === [null] || $r === false) continue;
        $rows[] = array_combine($head, array_pad(array_slice($r, 0, count($head)), count($head), ''));
    }
    fclose($fh);
    return $rows;
};

$pg = $OFFLINE ? null : Db::pg();
$isJunk     = fn(string $s): bool => (bool) preg_match('/^patreon[_-]?\d+$/i', trim($s));
$emailLocal = fn(string $e): string => (string) preg_replace('/\+.*$/', '', explode('@', $e)[0] ?? '');

// ── candidates ───────────────────────────────────────────────────────────────
// Bridged (can actually log in) and not archived. An unbridged identity is not a member
// — its /u/ 404s by ghost containment, so a prettier slug would just be a prettier 404.
$cand = $OFFLINE ? $readTsv($FROM) : $pg->query("
    SELECT u.id, u.display_name, u.slug, u.primary_email, u.slug_changed_at,
           b.wp_user_id, p.claimed_via
    FROM users u
    JOIN wp_user_bridge b ON b.user_id = u.id
    LEFT JOIN profiles p  ON p.user_id = u.id
    WHERE u.archived_at IS NULL
    ORDER BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

// ── ownership map (for collision detection) ──────────────────────────────────
// A handle is taken if it is live on another member OR parked in another member's
// slug_history — retired handles are never re-issued, or whoever took your old handle
// inherits every link that ever pointed at you. Archived and unbridged rows are INCLUDED
// here even though they are not candidates: a ghost that cannot log in still squats on
// the handle, and pretending otherwise would mint a duplicate.
$ownerOf = [];
if ($OFFLINE) {
    foreach ($OWNERS !== null ? $readTsv($OWNERS) : [] as $r) {
        $ownerOf[strtolower((string) $r['slug'])][] = (int) $r['owner_id'];
    }
} else {
    foreach ($pg->query('SELECT id, lower(slug) s FROM users WHERE slug IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ownerOf[$r['s']][] = (int) $r['id'];
    }
    foreach ($pg->query('SELECT user_id, lower(slug) s FROM slug_history')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ownerOf[$r['s']][] = (int) $r['user_id'];
    }
}
$freeFor = function (string $slug, int $selfId) use (&$ownerOf): bool {
    $o = $ownerOf[strtolower($slug)] ?? [];
    return !$o || !array_diff($o, [$selfId]);
};

// ── is this slug defective? ──────────────────────────────────────────────────
// A member is a candidate ONLY if their CURRENT slug is defective. Learned the hard way:
// an earlier version re-derived everyone and proposed `iandavlin` -> `ian-davlin5` and
// `charlesfox` -> `charles-fox2`, because the canonical form of those names was already
// held by someone else. A member who already owns a clean unique handle can only be made
// WORSE by re-deriving it. "My derivation disagrees with the stored slug" is not a defect.
$defectOf = function (string $current, string $name) use ($isJunk): ?string {
    if ($current === '')   return '1-NO-SLUG';
    if ($isJunk($current)) return '2-PATREON-JUNK';

    $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $good    = Slug::fit(Slug::derive($name));
    $naiveOf = fn(string $t): string => Slug::fit(
        trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-')
    );

    // Latin letters DELETED instead of folded, and the member is still wearing the
    // result — their own name misspelled in their own URL (`Åke` -> `ke-nathorst`).
    if (preg_match('/[^\x20-\x7E]/', $decoded)
        && $naiveOf($decoded) !== $good && $good !== ''
        && strcasecmp($current, $naiveOf($decoded)) === 0) {
        return '4-MANGLED-NAME';
    }
    // Slug derived from entity-damaged text, so it carries "amp" where the name has "&".
    if ($name !== $decoded && $naiveOf($name) !== $good && $good !== ''
        && strcasecmp($current, $naiveOf($name)) === 0) {
        return '5-ENTITY-DAMAGE';
    }
    return null;
};

// ── PASS 1: derive, and record why each member is in or out ──────────────────
$plan = [];      // rows we intend to change
$skips = [];     // reason => count, so the report can explain every member left alone
$wanted = [];    // base => [row indexes] — contention detection
foreach ($cand as $c) {
    $id      = (int) $c['id'];
    $current = trim((string) $c['slug']);
    $name    = (string) $c['display_name'];

    $defect = $defectOf($current, $name);
    if ($defect === null) { $skips['healthy — slug already reads as their name'] = ($skips['healthy — slug already reads as their name'] ?? 0) + 1; continue; }

    // R1: the slug IS the display name, cleaned. Nothing else is consulted here.
    $base = Slug::deriveUsable($name);

    if ($base === '') {
        // deriveUsable() returns '' for TWO unrelated reasons, and they need OPPOSITE
        // rulings. Collapsing them mislabels one of the groups: on live 2026-07-28, 4 of
        // these 8 were plain Latin initials ("BB", "G", "KJ", "Bo") filed under "name has
        // no Latin characters", which invites a ruling about romanization that would do
        // nothing for them. Ask each group its own question.
        $rawDerived = Slug::fit(Slug::derive($name));
        $shape      = $rawDerived === '' ? null : Slug::checkShape($rawDerived);

        if ($rawDerived === '') {
            // Non-Latin script, punctuation-only, or emoji. We NEVER latinize a member's
            // name (PATREON-HANDLE-BACKFILL-DRYRUN.md 7/25), so there is no honest
            // derivation. Surfaced for a human decision — never guessed at.
            $cat    = '0-NO-HONEST-SLUG';
            $why    = 'display name has no Latin characters to derive from';
            $action = 'NEEDS RULING — cannot derive without latinizing the name';
        } elseif ($shape === 'too_short') {
            $cat    = '0b-NAME-TOO-SHORT';
            $why    = sprintf('derives to "%s" — under the %d-character minimum', $rawDerived, Slug::MIN_LEN);
            $action = 'NEEDS RULING — allow a short handle, or leave the Patreon URL';
        } else {
            $cat    = '0c-SHAPE-REJECTED';
            $why    = sprintf('derives to "%s" — rejected by shape rule: %s', $rawDerived, (string) $shape);
            $action = 'NEEDS RULING — the derived handle is not a legal slug';
        }

        $plan[] = [
            'cat' => $cat, 'user_id' => $id, 'wp_id' => (string) $c['wp_user_id'],
            'name' => $name, 'current' => $current !== '' ? $current : '(none — 404s today)',
            'proposed' => '', 'why' => $why, 'action' => $action,
            'defect' => $defect, 'row' => $c,
        ];
        continue;
    }

    $i = count($plan);
    $plan[] = [
        'cat' => $defect, 'user_id' => $id, 'wp_id' => (string) $c['wp_user_id'],
        'name' => $name, 'current' => $current !== '' ? $current : '(none — 404s today)',
        'proposed' => $base, 'base' => $base,
        'why' => $defect === '2-PATREON-JUNK' ? 'URL is a Patreon id, not a name'
               : ($defect === '1-NO-SLUG' ? 'no URL at all — /u/ 404s today'
               : ($defect === '4-MANGLED-NAME' ? 'name is misspelled in the current URL'
               : 'slug derived from entity-damaged text')),
        'action' => 'derive from display name', 'defect' => $defect, 'row' => $c,
    ];
    $wanted[strtolower($base)][] = $i;
}

// ── PASS 2: who is contested? ────────────────────────────────────────────────
// Contested = more than one member cleans to the same handle, OR the handle already
// belongs to somebody else (live or retired).
$contested = [];
foreach ($plan as $i => $p) {
    if (($p['proposed'] ?? '') === '') continue;
    $group = $wanted[strtolower($p['proposed'])] ?? [];
    if (count($group) > 1 || !$freeFor($p['proposed'], $p['user_id'])) $contested[$i] = true;
}

// A bare first name that does NOT currently collide is one signup away from the same
// problem. Ian wants the COUNT, and to decide himself — never expanded silently.
$fragile = [];
foreach ($plan as $i => $p) {
    if (isset($contested[$i]) || ($p['proposed'] ?? '') === '') continue;
    if (!str_contains($p['proposed'], '-')) $fragile[] = $p;
}

// ── PASS 3: provenance, then expand ONLY where we are inventing an identity ──
$api = [];
$apiStatus = 'skipped (--db-only)';
// --api-fixture=<json> substitutes a recorded identity map for the live sweep. The
// creator token only exists on LIVE, so without this the collision resolver cannot be
// rehearsed anywhere else — and dev2's population cannot exercise it either. Same shape
// the sweep returns: { "<patreon_user_id>": {full_name, vanity, email}, ... }
foreach ($argv as $a) {
    if (!str_starts_with($a, '--api-fixture=')) continue;
    $j = json_decode((string) @file_get_contents(substr($a, 14)), true);
    if (is_array($j)) { $api = $j; $apiStatus = 'FIXTURE — ' . count($j) . ' identities (not live data)'; }
    else fwrite(STDERR, "api-fixture unreadable — ignoring\n");
}
if (!$api && !$DB_ONLY && $contested) {
    try {
        $my = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . LG_PROFILE_APP_MYSQL_DB,
            posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app', '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $api = looth_patreon_identity_sweep($my, $apiStatus);
    } catch (Throwable $e) {
        $apiStatus = 'UNAVAILABLE (' . $e->getMessage() . ')';
    }
} elseif (!$contested) {
    $apiStatus = 'not needed — no collisions';
}

/**
 * R4. Returns [verdict, evidence]. Conservative by construction: anything we cannot
 * positively identify as machine-seeded is treated as the member's own.
 */
$provenance = function (array $row, ?array $ident) use ($isJunk): array {
    $name = (string) $row['display_name'];

    // Strongest positive signal for MACHINE: the stored name is still, character for
    // character, what Patreon holds. Nobody has touched it.
    if ($ident !== null && trim((string) $ident['full_name']) !== ''
        && strcasecmp(Slug::derive($name), Slug::derive((string) $ident['full_name'])) === 0) {
        return ['machine-seeded', 'display name is still exactly the Patreon import value'];
    }
    if ($isJunk($name)) {
        return ['machine-seeded', 'display name is itself a patreon_* placeholder'];
    }
    // Strongest positive signal for USER: they came through onboarding or the menu.
    // (claimed_via is 96% `backfill_location` on dev2, which proves nothing — only
    // these three values reflect a real person.)
    if (in_array((string) $row['claimed_via'], ['onboard', 'direct', 'menu'], true)) {
        return ['user-submitted', 'member claimed their profile via ' . $row['claimed_via']];
    }
    // The tempting-but-WRONG inference lives here. "Dave" differing from Patreon's
    // "Dave Thurston" is NOT proof the member shortened it — the import may simply have
    // taken a first name. Claiming otherwise would block the exact case Ian wants
    // expanded. We do not know, and say so.
    if ($ident !== null && trim((string) $ident['full_name']) !== '') {
        return ['indeterminate', 'stored name is shorter than / differs from Patreon\'s "'
            . trim((string) $ident['full_name']) . '" — cannot tell who wrote it'];
    }
    return ['indeterminate', 'no signal either way — no provenance is recorded anywhere'];
};

$suffixLastResort = 0;
foreach ($plan as $i => &$p) {
    if (!isset($contested[$i])) continue;

    preg_match('/(\d+)/', (string) ($p['row']['slug'] ?? ''), $m);
    $ident = $api[$m[1] ?? ''] ?? null;
    [$verdict, $evidence] = $provenance($p['row'], $ident);
    $p['provenance'] = $verdict;

    // Work out the expansion for EVERY contested row, whoever wrote the name. Ian asked
    // to see exactly who is affected; "needs a ruling" with no proposal attached is not
    // something anyone can approve. Whether we may ACT on it is the separate question
    // below. Fuller identity first, email local-part last.
    $found = '';
    $src   = '';
    foreach ([
        'patreon full name' => (string) ($ident['full_name'] ?? ''),
        'patreon vanity'    => (string) ($ident['vanity'] ?? ''),
        'patreon email'     => $ident ? (string) ($ident['email'] ?? '') : '',
        'account email'     => (string) $p['row']['primary_email'],
    ] as $label => $raw) {
        if ($raw === '') continue;
        $try = Slug::deriveUsable(str_contains($raw, '@') ? (string) preg_replace('/\+.*$/', '', explode('@', $raw)[0]) : $raw);
        if ($try === '' || strcasecmp($try, (string) $p['base']) === 0) continue;
        if (!$freeFor($try, $p['user_id'])) continue;
        $found = $try; $src = $label; break;
    }
    $p['suggested'] = $found;
    $p['why'] = 'clashes on "' . $p['base'] . '" — already held; ' . $evidence;

    if ($verdict === 'machine-seeded' && $found !== '') {
        // Nobody supplied this name, so we are inventing an identity rather than
        // rewriting a chosen one. Expansion is licensed (R3).
        $p['cat']      = '6-COLLISION-EXPANDED';
        $p['proposed'] = $found;
        $p['action']   = 'expanded from ' . $src . ' (no numeric suffix)';
        $ownerOf[strtolower($found)][] = $p['user_id'];
        continue;
    }

    // Everything else stops here. R4 outranks R3: we may not reach for Patreon to
    // "improve" a name that might be the member's own, and R3 forbids inventing dave2.
    // The suggestion is shown so Ian can approve it in one look — it is not applied.
    $p['cat']      = '3-COLLISION-NEEDS-RULING';
    $p['proposed'] = '';
    if ($found !== '') {
        $p['action'] = $verdict === 'user-submitted'
            ? 'NEEDS RULING — could be /u/' . $found . ' (from ' . $src . '), but this name looks like the member\'s own'
            : 'NEEDS RULING — could be /u/' . $found . ' (from ' . $src . '); nobody knows who wrote the current name';
    } else {
        $suffixLastResort++;
        $p['action'] = 'NEEDS RULING — nothing available to expand from; only a numeric suffix would work';
    }
}
unset($p);

// Uncontested rows keep their derived base; record provenance for the report.
foreach ($plan as $i => &$p) {
    if (isset($p['provenance'])) continue;
    preg_match('/(\d+)/', (string) ($p['row']['slug'] ?? ''), $m);
    [$v, ] = $provenance($p['row'], $api[$m[1] ?? ''] ?? null);
    $p['provenance'] = $v;
}
unset($p);

// Interesting cases FIRST — the rows needing a human decision must not be on page 40.
usort($plan, fn($a, $b) => [$a['cat'], $a['user_id']] <=> [$b['cat'], $b['user_id']]);

// ── --applied-since: rebuild the report from what a real run actually wrote ──
if ($SINCE > 0) {
    $st = $pg->prepare("
        SELECT h.slug AS was, u.slug AS now, u.display_name, u.id, b.wp_user_id
        FROM slug_history h
        JOIN users u ON u.id = h.user_id
        JOIN wp_user_bridge b ON b.user_id = u.id
        WHERE h.released_at > now() - (:m || ' minutes')::interval
        ORDER BY h.slug
    ");
    $st->execute([':m' => $SINCE]);
    $plan = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $plan[] = [
            'cat' => $isJunk((string) $r['was']) ? '2-PATREON-JUNK' : '4-MANGLED-NAME',
            'user_id' => (int) $r['id'], 'wp_id' => (string) $r['wp_user_id'],
            'name' => (string) $r['display_name'], 'current' => (string) $r['was'],
            'proposed' => (string) $r['now'], 'why' => 'applied on this box',
            'action' => 'applied', 'provenance' => '', 'defect' => '',
        ];
    }
}

$actionable = array_values(array_filter($plan, fn($p) => $p['proposed'] !== ''));
$needRuling = array_values(array_filter($plan, fn($p) => $p['proposed'] === ''));

$act = array_values(array_filter($actionable, fn($p) =>
    $SCOPE === 'repair' ? true : in_array($p['cat'], ['1-NO-SLUG', '2-PATREON-JUNK', '6-COLLISION-EXPANDED'], true)));

// --hold-bare-names: apply everything EXCEPT the single-token handles.
//
// Handing out /u/matt is the one irreversible-feeling decision in this run. It is the most
// contested handle on the site, it goes to whichever member's display_name happens to be
// just "Matt", and on live 2026-07-28 not ONE of those winners has any record of having
// typed their own name — all indeterminate, none claimed via onboard|direct|menu. So the
// allocation is driven by which Patreon import carried a first name only.
//
// That is not proof the name is an import artifact (R4: we do not infer that, and refusing
// to is why the no-suffix rule exists). It is a reason for a HUMAN to decide before the
// handles are gone, rather than after. Off by default: this changes nothing unless asked for.
if (in_array('--hold-bare-names', $argv, true)) {
    $before = count($act);
    $act = array_values(array_filter($act, fn($p) => str_contains($p['proposed'], '-')));
    fwrite(STDERR, sprintf("--hold-bare-names: holding back %d single-token handle(s); acting on %d\n",
        $before - count($act), count($act)));
}
if ($LIMIT > 0) $act = array_slice($act, 0, $LIMIT);

// ── summary ──────────────────────────────────────────────────────────────────
$byCat = [];
foreach ($plan as $p) $byCat[$p['cat']] = ($byCat[$p['cat']] ?? 0) + 1;
ksort($byCat);
fwrite(STDERR, sprintf("members=%d  changing=%d  NEED-RULING=%d  scope=%s  acting-on=%d  mode=%s\n",
    count($cand), count($actionable), count($needRuling), $SCOPE, count($act),
    $APPLY ? 'APPLY (WRITES)' : 'DRY RUN (no writes)'));
fwrite(STDERR, "patreon api: $apiStatus\n");
foreach ($byCat as $k => $v) fwrite(STDERR, sprintf("  %-28s %d\n", $k, $v));
fwrite(STDERR, sprintf("  %-28s %d\n", 'bare-first-name (fragile)', count($fragile)));
if ($suffixLastResort) fwrite(STDERR, "  numeric-suffix-would-be-needed  $suffixLastResort  (target is ZERO)\n");

// ── apply ────────────────────────────────────────────────────────────────────
// One transaction PER MEMBER: a failure at row 900 must not roll back the 899 good ones.
// That is also the resume path — re-running drops applied rows from the candidate set.
if ($APPLY) {
    $applied = 0; $failed = 0;
    foreach ($act as $r) {
        try {
            $pg->beginTransaction();
            $st = $pg->prepare('SELECT slug FROM users WHERE id = :i FOR UPDATE');
            $st->execute([':i' => $r['user_id']]);
            $old = trim((string) ($st->fetchColumn() ?: ''));
            if (strcasecmp($old, $r['proposed']) === 0) { $pg->rollBack(); continue; }

            $pg->prepare('DELETE FROM slug_history WHERE user_id = :u AND lower(slug) = lower(:s)')
               ->execute([':u' => $r['user_id'], ':s' => $r['proposed']]);
            if ($old !== '') {
                // R5: this is what keeps every shared and indexed link alive as a 301.
                $pg->prepare('INSERT INTO slug_history (user_id, slug) VALUES (:u, :s)
                              ON CONFLICT (lower(slug)) DO NOTHING')
                   ->execute([':u' => $r['user_id'], ':s' => $old]);
            }
            $pg->prepare('UPDATE users SET slug = :s, slug_changed_at = now() WHERE id = :i')
               ->execute([':s' => $r['proposed'], ':i' => $r['user_id']]);
            $pg->commit();
            $applied++;
        } catch (Throwable $e) {
            if ($pg->inTransaction()) $pg->rollBack();
            $failed++;
            fwrite(STDERR, "FAILED user_id={$r['user_id']} -> {$r['proposed']}: " . $e->getMessage() . "\n");
        }
    }
    fwrite(STDERR, "applied=$applied failed=$failed\n");
    fwrite(STDERR, "NEXT: WP `_looth_slug` is a CACHE of this column and is now stale for\n"
                 . "      everyone changed — run bin/purge-stale-looth-slug-mirror.php.\n");
}

// ── report ───────────────────────────────────────────────────────────────────
$cols = ['cat', 'user_id', 'wp_id', 'name', 'current', 'proposed', 'provenance', 'why', 'action'];
if ($TSV) {
    $fh = fopen($TSV, 'w');
    fputcsv($fh, $cols, "\t", '"', '\\');
    foreach ($plan as $p) fputcsv($fh, array_map(fn($c) => (string) ($p[$c] ?? ''), $cols), "\t", '"', '\\');
    fclose($fh);
    fwrite(STDERR, "tsv: $TSV\n");
}

if ($HTML) {
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $LABEL = [
        '0-NO-HONEST-SLUG'          => ['Name has no Latin characters — needs your ruling', 'Non-Latin script, punctuation or emoji. We never latinize a member\'s name, so there is no honest derivation. Options: leave the Patreon URL, let the member choose, or rule that these may be romanized.'],
        '0b-NAME-TOO-SHORT'         => ['Name is shorter than the minimum handle', 'These derive to perfectly good Latin — they are just under the ' . Slug::MIN_LEN . '-character floor. A DIFFERENT question from the non-Latin group above: nothing needs romanizing, you only need to say whether a 2-letter handle is allowed. Options: lower the floor, pad from a fuller identity, or leave the Patreon URL.'],
        '0c-SHAPE-REJECTED'         => ['Derived handle is not a legal slug', 'The name derives to Latin, but the result breaks a shape rule (digits only would shadow /u/<member-id>, or the charset the nginx route can match).'],
        '3-COLLISION-NEEDS-RULING'  => ['Collision we may NOT resolve on our own', 'Two members clean to the same handle. A numeric suffix is ruled out, and where the name is the member\'s own we may not reach for Patreon to "expand" it either. These need your call.'],
        '1-NO-SLUG'                 => ['No URL at all — /u/ 404s today', 'Nothing to redirect FROM, so there is no link risk in giving them one.'],
        '2-PATREON-JUNK'            => ['Patreon id instead of a name', 'The point of the lane. The old URL 301s forever.'],
        '6-COLLISION-EXPANDED'      => ['Collision resolved by EXPANDING the name', 'Clashed, and the stored name was still the raw Patreon import (nobody supplied it), so a fuller identity was used — dave-thurston, never dave2.'],
        '4-MANGLED-NAME'            => ['Name is misspelled in the current URL', 'The old deriver deleted accented letters instead of folding them, so these URLs spell members\' names wrong.'],
        '5-ENTITY-DAMAGE'           => ['Slug built from entity-damaged text', 'Raw rows carry literal &amp;. Decoded for derivation only — display_name is never rewritten.'],
    ];
    $f = fopen($HTML, 'w');
    fwrite($f, '<!doctype html><meta charset="utf-8"><title>Profile URL backfill</title>'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>body{font:15px/1.55 system-ui,sans-serif;max-width:1200px;margin:0 auto;padding:28px 20px 60px;color:#1f2320;background:#fbfaf7}'
        . 'h1{font-size:26px;margin:0 0 6px}h2{font-size:18px;margin:34px 0 4px}.sub{color:#666;margin:0 0 20px}'
        . '.why{color:#555;margin:0 0 12px;max-width:82ch}table{border-collapse:collapse;width:100%;font-size:13.5px;margin-top:8px}'
        . 'th,td{border:1px solid #e2e0d9;padding:6px 9px;text-align:left;vertical-align:top}th{background:#f2f0ea}'
        . 'td.old{color:#a33;font-family:ui-monospace,monospace}td.new{color:#186a3b;font-family:ui-monospace,monospace;font-weight:600}'
        . 'tr:nth-child(even) td{background:#fff}.k{display:inline-block;background:#eef2ec;border:1px solid #d5ddd2;border-radius:5px;padding:1px 7px;font-size:12px;margin:0 6px 6px 0}'
        . '.warn{background:#fff6e5;border:1px solid #e8d5a8;border-radius:8px;padding:12px 14px;margin:16px 0}'
        . '@media(max-width:700px){table{font-size:12px}th,td{padding:4px 6px}}</style>');
    fwrite($f, '<h1>Profile URL backfill</h1><p class="sub">' . $h(date('Y-m-d H:i T')) . ' &middot; '
        . count($cand) . ' active members &middot; <b>' . count($actionable) . '</b> would change &middot; <b>'
        . count($needRuling) . '</b> need your ruling &middot; nothing has been written.</p>');
    // Provenance banner. An offline run is only as live as the export it was fed, and a
    // report that does not say where its rows came from is one that WILL be mistaken for
    // live data by whoever opens it next.
    if ($OFFLINE) {
        fwrite($f, '<div class="warn" style="background:#fdecea;border-color:#e8b4ae"><b>Read this first — where these rows came from.</b> '
            . 'Generated OFFLINE from the export <code>' . $h(basename((string) $FROM)) . '</code>'
            . ($OWNERS ? ' (+ <code>' . $h(basename((string) $OWNERS)) . '</code>)' : '')
            . '. It is exactly as live as that file and no more. If that export did not come from the '
            . 'production database, <b>these are not production numbers.</b></div>');
    }
    fwrite($f, '<div class="warn"><b>How collisions are handled.</b> No member is ever given a numeric suffix. '
        . 'Where two members clean to the same handle, the name is expanded from a fuller identity '
        . '(<code>dave-thurston</code>, not <code>dave2</code>) — but only when the stored name was still the raw '
        . 'Patreon import. <b>A name the member typed is theirs</b>, and is never expanded or overwritten; those '
        . 'collisions are listed for you instead. Numeric suffixes still needed: <b>' . (int) $suffixLastResort
        . '</b> (target zero).</div>');
    fwrite($f, '<p>');
    foreach ($byCat as $k => $v) fwrite($f, '<span class="k">' . $h($k) . ' &middot; ' . $v . '</span>');
    fwrite($f, '<span class="k">patreon api &middot; ' . $h($apiStatus) . '</span></p>');

    foreach ($LABEL as $catKey => $meta) {
        $sub = array_values(array_filter($plan, fn($p) => $p['cat'] === $catKey));
        if (!$sub) continue;
        fwrite($f, '<h2>' . $h($meta[0]) . ' <span class="k">' . count($sub) . '</span></h2>');
        fwrite($f, '<p class="why">' . $h($meta[1]) . '</p>');
        fwrite($f, '<table><tr><th>member</th><th>current URL</th><th>proposed URL</th><th>name is</th><th>why</th></tr>');
        foreach ($sub as $p) {
            fwrite($f, '<tr><td>' . $h($p['name']) . '</td><td class="old">/u/' . $h($p['current']) . '</td>'
                . '<td class="new">' . ($p['proposed'] !== '' ? '/u/' . $h($p['proposed']) : '<i>' . $h($p['action']) . '</i>')
                . '</td><td>' . $h($p['provenance'] ?? '') . '</td><td>' . $h($p['why']) . '</td></tr>');
        }
        fwrite($f, '</table>');
    }

    if ($fragile) {
        fwrite($f, '<h2>Bare first names that do NOT collide yet <span class="k">' . count($fragile) . '</span></h2>');
        fwrite($f, '<p class="why">These resolve cleanly today, but each is one new signup away from the same '
            . 'collision. They are <b>not</b> being expanded — that would mean rewriting a name nobody asked us to '
            . 'touch. Flagged so you can decide whether to expand them now or leave them.</p>');
        fwrite($f, '<table><tr><th>member</th><th>would become</th></tr>');
        foreach ($fragile as $p) fwrite($f, '<tr><td>' . $h($p['name']) . '</td><td class="new">/u/' . $h($p['proposed']) . '</td></tr>');
        fwrite($f, '</table>');
    }

    fwrite($f, '<h2>Members left alone, and why</h2><p class="why">A migration that cannot explain its own skips '
        . 'is one nobody can approve. Every active member not listed above falls into one of these:</p><table>'
        . '<tr><th>reason</th><th>members</th></tr>');
    foreach ($skips as $why => $n) fwrite($f, '<tr><td>' . $h($why) . '</td><td>' . $n . '</td></tr>');
    fwrite($f, '<tr><td>WordPress <code>user_nicename</code> left as <code>patreon_*</code> — '
        . '<code>/members/&lt;nicename&gt;/</code> already 301s to <code>/u/</code>, which slug history 301s again, '
        . 'so those links work untouched</td><td>all</td></tr>');
    fwrite($f, '<tr><td>unbridged identities (cannot log in — <code>/u/</code> 404s by ghost containment) '
        . 'and archived rows</td><td>excluded</td></tr></table>');
    fclose($f);
    fwrite(STDERR, "html: $HTML\n");
}

if (!$APPLY) fwrite(STDERR, "\nNO WRITES PERFORMED. Re-run with --apply to write.\n");
