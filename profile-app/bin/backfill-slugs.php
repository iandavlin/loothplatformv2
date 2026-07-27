<?php
declare(strict_types=1);

/**
 * backfill-slugs.php — re-derive member profile URLs from the profile NAME.
 *
 * WHAT THIS IS FOR
 * ----------------
 * `/u/<slug>` resolves from ONE store: profile_app (Postgres) `users.slug`. This script
 * is the only supported way to bulk-change that column. It never touches WordPress.
 *
 * THE THREE RULINGS IT IMPLEMENTS (Ian)
 *   1. The URL follows the profile NAME. Members do not pick a handle. (2026-07-19)
 *   2. KEEP THE BUSINESS NAME — derive from display_name AS IT STANDS, no tail pruning.
 *      "Franklin Linker Linker Guitars LLC" -> `franklin-linker-linker-guitars`
 *      (the LLC falls off the 30-char cap, NOT off a prune rule). (2026-07-27)
 *   3. Every existing URL keeps working. Each change parks the OLD slug in
 *      `slug_history`, which u.php step 4 turns into a 301. Nothing ever 404s
 *      because of this script.
 *
 * IDEMPOTENCY / RESUME
 *   A row is only written when the proposed slug DIFFERS from the current one, and the
 *   collision domain excludes the member's own slug and own history. So running this
 *   twice is a no-op and can never double-suffix (`steve2` -> `steve22`). There is no
 *   separate resume file: if it dies halfway, re-run it — the rows already applied fall
 *   out of the candidate set on their own.
 *
 * USAGE
 *   sudo -u profile-app php backfill-slugs.php                        # dry run, scope=junk
 *   sudo -u profile-app php backfill-slugs.php --scope=repair
 *   sudo -u profile-app php backfill-slugs.php --scope=repair --apply
 *   ... [--limit=N] [--tsv=/path/out.tsv] [--html=/path/out.html]
 *
 * SCOPES — smallest blast radius first. Dry-run always reports BOTH tiers regardless,
 * so one report shows what each scope would do.
 *   junk    (default) members whose slug is a patreon_* placeholder or missing entirely.
 *   repair  junk + members whose current slug MISSPELLS their name (non-ASCII stripped
 *           rather than transliterated: `Åke Nathorst` -> `ke-nathorst`) or was derived
 *           from entity-damaged text (`&amp;` -> "amp").
 *
 * There is deliberately NO "re-derive everyone" scope. It was built, run, and rejected
 * on the evidence: because the canonical form of some names is already held by another
 * member, a blanket re-derivation proposed moving `iandavlin` -> `ian-davlin5` and
 * `charlesfox` -> `charles-fox2`. A member who already owns a clean unique handle can
 * only be made worse by re-deriving it. Healthy slugs are never candidates.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *   - Does not write WordPress. `user_nicename` stays patreon_* on purpose: legacy
 *     `/members/<nicename>/` already 301s to `/u/<nicename>`, which slug_history then
 *     301s to the current slug. Those links work; rewriting nicename would risk WP
 *     author archives and BuddyBoss URLs for no gain.
 *   - Does not repair `display_name`. Entities are decoded for DERIVATION only. Fixing
 *     the stored name is the parked name-cleanup lane.
 *   - Does not touch unbridged identities (no wp_user_bridge = not a member; their /u/
 *     404s by ghost containment) or archived rows.
 */

require dirname(__DIR__) . '/config.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Slug;

$APPLY = in_array('--apply', $argv, true);
$SCOPE = 'junk';
$LIMIT = 0;
$TSV   = null;
$HTML  = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--scope=')) $SCOPE = substr($a, 8);
    if (str_starts_with($a, '--limit=')) $LIMIT = (int) substr($a, 8);
    if (str_starts_with($a, '--tsv='))   $TSV   = substr($a, 6);
    if (str_starts_with($a, '--html='))  $HTML  = substr($a, 7);
}
if (!in_array($SCOPE, ['junk', 'repair'], true)) {
    fwrite(STDERR, "unknown --scope=$SCOPE (want junk|repair)\n");
    exit(2);
}

$pg = Db::pg();

/** A patreon_* import placeholder — the thing this lane exists to remove. */
$isJunk = fn(string $s): bool => (bool) preg_match('/^patreon[_-]?\d+$/i', trim($s));
$emailLocal = fn(string $e): string => (string) preg_replace('/\+.*$/', '', explode('@', $e)[0] ?? '');

// ── candidates ───────────────────────────────────────────────────────────────
// Members only: bridged (can actually log in) and not archived. An unbridged row is
// not a member — its /u/ 404s by ghost containment, so giving it a pretty slug would
// just be a prettier 404.
$cand = $pg->query("
    SELECT u.id, u.display_name, u.slug, u.primary_email, b.wp_user_id
    FROM users u
    JOIN wp_user_bridge b ON b.user_id = u.id
    WHERE u.archived_at IS NULL
    ORDER BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

// ── collision domain ─────────────────────────────────────────────────────────
// A candidate handle is taken if it is LIVE on another member, parked in another
// member's slug_history (retired handles are NEVER re-issued — that is link-hijack
// prevention, see Slug class docblock), or already claimed earlier in THIS batch.
$live = $pg->query('SELECT id, lower(slug) s FROM users WHERE slug IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
$hist = $pg->query('SELECT user_id, lower(slug) s FROM slug_history')->fetchAll(PDO::FETCH_ASSOC);
$ownerOf = [];                                   // lower(slug) => owning user_id
foreach ($live as $r) $ownerOf[$r['s']][] = (int) $r['id'];
foreach ($hist as $r) $ownerOf[$r['s']][] = (int) $r['user_id'];

$claim = function (string $base, int $selfId) use (&$ownerOf): array {
    $c = $base;
    for ($i = 2; $i <= 999; $i++) {
        $owners = $ownerOf[strtolower($c)] ?? [];
        // free, or already ours (own live slug / own retired handle — reclaimable)
        if (!$owners || !array_diff($owners, [$selfId])) break;
        $c = Slug::fit($base, Slug::MAX_LEN - strlen((string) $i)) . $i;
    }
    $ownerOf[strtolower($c)][] = $selfId;        // claim for batch-internal uniqueness
    return [$c, $c !== $base];
};

// ── derive ───────────────────────────────────────────────────────────────────
// A member is a candidate ONLY if their CURRENT slug is defective. This is the single
// most important rule in the script, and it was learned the hard way: an earlier
// version re-derived every member from their name, which proposed moving `iandavlin`
// -> `ian-davlin5` and `charlesfox` -> `charles-fox2`, because the canonical
// derivation of those names was already held by someone else. Re-deriving a member who
// already owns a clean, unique handle cannot improve their URL — it can only add a
// numeric suffix and burn a redirect. So "my derivation disagrees with the stored slug"
// is NOT a defect; only these four things are.
$defectOf = function (string $current, string $name) use ($isJunk): ?string {
    if ($current === '')       return '1-NO-SLUG';
    if ($isJunk($current))     return '2-PATREON-JUNK';

    $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $good    = Slug::fit(Slug::derive($name));

    // The test is deliberately "is the member WEARING the broken derivation", not
    // "does my derivation disagree with their slug". The looser test also fires on
    // members whose only difference is that their slug predates the 30-char cap
    // (`shawn-reams-north-jersey-guitar`, 31 chars) — re-deriving those just shortens
    // a perfectly good URL and spends a redirect to do it.
    $naiveOf = fn(string $t): string => Slug::fit(
        trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-')
    );

    // Non-ASCII letters DELETED instead of transliterated, and the member is still
    // wearing that result — their own name is misspelled in their own URL
    // (`Åke Nathorst` -> `ke-nathorst`, `Peter Ellström` -> `peter-ellstr-m`).
    if (preg_match('/[^\x20-\x7E]/', $decoded)
        && $naiveOf($decoded) !== $good
        && strcasecmp($current, $naiveOf($decoded)) === 0) {
        return '4-MANGLED-NON-ASCII';
    }

    // Slug derived from entity-damaged text, so it carries "amp" (from `&amp;`) where
    // the real name has "&". Only fires when the stored slug IS that polluted form.
    if ($name !== $decoded
        && $naiveOf($name) !== $good
        && strcasecmp($current, $naiveOf($name)) === 0) {
        return '5-ENTITY-DAMAGE';
    }

    return null;   // healthy — leave this member's URL alone
};

$rows = []; $healthy = 0;
foreach ($cand as $c) {
    $id      = (int) $c['id'];
    $current = trim((string) $c['slug']);
    $name    = (string) $c['display_name'];

    $cat = $defectOf($current, $name);
    if ($cat === null) { $healthy++; continue; }

    // Derivation chain. display_name is PRIMARY (Ian 7/25 23:45) — the same rule the
    // live rename applies, business tails and all. Email local-part only rescues a
    // member whose name yields nothing a URL can carry.
    $source = 'display-name';
    $base   = $isJunk($name) ? '' : Slug::deriveUsable($name);
    if ($base === '') {
        $base   = Slug::deriveUsable($emailLocal((string) $c['primary_email']));
        $source = 'email-local';
    }
    if ($base === '') {
        $base   = 'member';
        $source = 'fallback';
    }
    if ($source !== 'display-name') $cat = '3-UNSLUGIFIABLE';

    [$proposed, $suffixed] = $claim($base, $id);
    if (strcasecmp($current, $proposed) === 0) { $healthy++; continue; }

    $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $inJunk  = in_array($cat, ['1-NO-SLUG', '2-PATREON-JUNK', '3-UNSLUGIFIABLE'], true);

    $rows[] = [
        'cat'       => $cat,
        'scope'     => $inJunk ? 'junk' : 'repair',
        'user_id'   => $id,
        'wp_id'     => (string) ($c['wp_user_id'] ?? ''),
        'name'      => $name,
        'current'   => $current !== '' ? $current : '(none — 404s today)',
        'proposed'  => $proposed,
        'source'    => $source,
        'suffixed'  => $suffixed ? 'yes' : '',
        'truncated' => strlen(Slug::derive($decoded)) > Slug::MAX_LEN ? 'yes' : '',
        'changes'   => true,
    ];
}

// ── --applied-since=<minutes> ────────────────────────────────────────────────
// After an --apply run the candidate set is empty by design, so a dry-run report can no
// longer show what happened. This rebuilds the same report from what was actually
// written: slug_history holds the outgoing handle, users.slug the incoming one. It is
// the record of a real run — on the box it ran on.
$SINCE = 0;
foreach ($argv as $a) if (str_starts_with($a, '--applied-since=')) $SINCE = (int) substr($a, 16);
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
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $isJ = $isJunk((string) $r['was']);
        $rows[] = [
            'cat'       => $isJ ? '2-PATREON-JUNK' : '4-MANGLED-NON-ASCII',
            'scope'     => $isJ ? 'junk' : 'repair',
            'user_id'   => (int) $r['id'],
            'wp_id'     => (string) $r['wp_user_id'],
            'name'      => (string) $r['display_name'],
            'current'   => (string) $r['was'],
            'proposed'  => (string) $r['now'],
            'source'    => 'display-name',
            'suffixed'  => '',
            'truncated' => '',
            'changes'   => true,
        ];
    }
    $healthy = count($cand) - count($rows);
}

// Interesting cases FIRST — a 1,600-row report is useless if the 9 rows that need a
// human decision are on page 40. Sort by category, then by whether it changes.
usort($rows, fn($a, $b) => [$a['cat'], -$a['changes'], $a['user_id']]
                       <=> [$b['cat'], -$b['changes'], $b['user_id']]);

$act = array_values(array_filter($rows, fn($r) =>
    $SCOPE === 'repair' ? true : $r['scope'] === 'junk'));
if ($LIMIT > 0) $act = array_slice($act, 0, $LIMIT);

// ── summary ──────────────────────────────────────────────────────────────────
$byCat = [];
foreach ($rows as $r) $byCat[$r['cat']] = ($byCat[$r['cat']] ?? 0) + 1;
ksort($byCat);

fwrite(STDERR, sprintf("members=%d  healthy-left-alone=%d  defective=%d  scope=%s  acting-on=%d  mode=%s\n",
    count($cand), $healthy, count($rows), $SCOPE, count($act),
    $APPLY ? 'APPLY (WRITES)' : 'DRY RUN (no writes)'));
foreach ($byCat as $k => $v) fwrite(STDERR, sprintf("  %-22s %d\n", $k, $v));

// ── apply ────────────────────────────────────────────────────────────────────
// One transaction PER MEMBER, not one for the batch: a failure on row 900 must not
// roll back the 899 good ones (that is what makes re-running the resume path).
$applied = 0; $failed = 0;
if ($APPLY) {
    foreach ($act as $r) {
        try {
            $pg->beginTransaction();

            // Re-read under lock: another process may have changed this slug since we
            // built the plan. If it no longer matches what we planned from, skip.
            $st = $pg->prepare('SELECT slug FROM users WHERE id = :i FOR UPDATE');
            $st->execute([':i' => $r['user_id']]);
            $old = trim((string) ($st->fetchColumn() ?: ''));
            if (strcasecmp($old, $r['proposed']) === 0) { $pg->rollBack(); continue; }

            // Reclaiming a handle this member previously held: drop it from history so
            // it is never simultaneously live AND retired.
            $pg->prepare('DELETE FROM slug_history WHERE user_id = :u AND lower(slug) = lower(:s)')
               ->execute([':u' => $r['user_id'], ':s' => $r['proposed']]);

            // Park the outgoing handle — THIS is what keeps every shared/indexed link
            // alive as a 301 (Ruling 3).
            if ($old !== '') {
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
    fwrite(STDERR, "NOTE: WP `_looth_slug` usermeta is a CACHE of this column and is now stale\n"
                 . "      for every member changed. Run bin/purge-stale-looth-slug-mirror.php next.\n");
}

// ── report ───────────────────────────────────────────────────────────────────
$cols = ['cat', 'scope', 'user_id', 'wp_id', 'name', 'current', 'proposed', 'source', 'suffixed', 'truncated'];
$out  = $rows;

if ($TSV) {
    $fh = fopen($TSV, 'w');
    fputcsv($fh, $cols, "\t", '"', '\\');
    foreach ($out as $r) fputcsv($fh, array_map(fn($c) => (string) $r[$c], $cols), "\t", '"', '\\');
    fclose($fh);
    fwrite(STDERR, "tsv: $TSV\n");
}

if ($HTML) {
    $LABEL = [
        '1-NO-SLUG'           => ['No slug at all — /u/ 404s today', 'These members have no profile URL. Nothing to redirect FROM, so there is no link risk in giving them one.'],
        '2-PATREON-JUNK'      => ['Patreon placeholder URL', 'The point of the lane: a numeric import id standing in for a name. Old URL 301s forever.'],
        '3-UNSLUGIFIABLE'     => ['Name yields no URL — fell back', 'display_name has nothing a URL can carry even after transliteration, so the email local-part was used. Ruling 1 cannot be honoured for these; review each one.'],
        '4-MANGLED-NON-ASCII' => ['Name is MISSPELLED in the current URL', 'The old deriver deleted non-ASCII letters instead of transliterating them, so these members\' URLs spell their names wrong. Transliteration fixes them.'],
        '5-ENTITY-DAMAGE'     => ['Stored name carries HTML entities', 'Raw rows contain literal &amp;. Decoded for derivation only — display_name is NOT rewritten (that is the parked name-cleanup lane).'],
    ];
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $f = fopen($HTML, 'w');
    fwrite($f, '<!doctype html><meta charset="utf-8"><title>Slug backfill — dry run</title>'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>body{font:15px/1.55 system-ui,sans-serif;margin:0;padding:28px 20px 60px;max-width:1180px;margin:0 auto;color:#1f2320;background:#fbfaf7}'
        . 'h1{font-size:26px;margin:0 0 6px}h2{font-size:18px;margin:34px 0 4px}'
        . '.sub{color:#666;margin:0 0 22px}.why{color:#555;margin:0 0 12px;max-width:80ch}'
        . 'table{border-collapse:collapse;width:100%;font-size:13.5px;margin-top:8px}'
        . 'th,td{border:1px solid #e2e0d9;padding:6px 9px;text-align:left;vertical-align:top}'
        . 'th{background:#f2f0ea;font-weight:700}td.old{color:#a33;font-family:ui-monospace,monospace}'
        . 'td.new{color:#186a3b;font-family:ui-monospace,monospace;font-weight:600}'
        . 'tr:nth-child(even) td{background:#fff}.tot{font-weight:700}'
        . '.k{display:inline-block;background:#eef2ec;border:1px solid #d5ddd2;border-radius:5px;padding:1px 7px;font-size:12px;margin-right:6px}'
        . '@media(max-width:700px){table{font-size:12px}th,td{padding:4px 6px}}</style>');
    $isRecord = $SINCE > 0;
    fwrite($f, '<h1>Profile URL backfill — ' . ($isRecord ? 'record of an applied run' : 'dry run') . '</h1>'
        . '<p class="sub">Generated ' . $h(date('Y-m-d H:i T')) . ' &middot; ' . count($cand)
        . ' active bridged members &middot; <b>' . count($out) . '</b> '
        . ($isRecord
            ? 'URLs were CHANGED on this box. Old addresses 301 to the new ones.'
            : 'would change &middot; nothing has been written.')
        . '</p>');
    fwrite($f, '<p class="why"><b>How to read this.</b> Every change parks the old URL in <code>slug_history</code>, '
        . 'so the old address 301-redirects to the new one permanently — no link, bookmark or search result breaks. '
        . 'Sections are ordered most-interesting first. The <span class="k">scope</span> column says which run tier '
        . 'acts on that row: <code>--scope=junk</code> (safest) or <code>--scope=repair</code>.</p>'
        . '<p class="why"><b>Members with a healthy URL are not touched at all.</b> ' . (int) $healthy . ' of the '
        . count($cand) . ' active members already have a clean, unique handle and are absent from this report. '
        . 'A full re-derivation was tried and rejected: because the canonical form of some names is already taken, '
        . 'it proposed moving <code>iandavlin</code> to <code>ian-davlin5</code> and <code>charlesfox</code> to '
        . '<code>charles-fox2</code> — a worse URL and a wasted redirect. Only defective slugs are candidates.</p>');
    fwrite($f, '<p>');
    foreach ($byCat as $k => $v) fwrite($f, '<span class="k">' . $h($k) . ' &middot; ' . $v . '</span>');
    fwrite($f, '</p>');

    foreach ($LABEL as $catKey => $meta) {
        $sub = array_values(array_filter($out, fn($r) => $r['cat'] === $catKey));
        if (!$sub) continue;
        fwrite($f, '<h2>' . $h($meta[0]) . ' <span class="k">' . count($sub) . '</span></h2>');
        fwrite($f, '<p class="why">' . $h($meta[1]) . '</p>');
        fwrite($f, '<table><tr><th>scope</th><th>member</th><th>current URL</th><th>proposed URL</th><th>from</th><th>notes</th></tr>');
        foreach ($sub as $r) {
            $notes = trim(($r['suffixed'] ? 'collision-suffixed ' : '') . ($r['truncated'] ? 'truncated at 30' : ''));
            fwrite($f, '<tr><td>' . $h($r['scope']) . '</td><td>' . $h($r['name'])
                . '</td><td class="old">/u/' . $h($r['current']) . '</td><td class="new">/u/' . $h($r['proposed'])
                . '</td><td>' . $h($r['source']) . '</td><td>' . $h($notes) . '</td></tr>');
        }
        fwrite($f, '</table>');
    }

    // ── considered and NOT proposed ──────────────────────────────────────────
    // Ruling 1 says the URL follows the NAME, and these members are wearing an
    // email-derived handle instead. They are still NOT proposed, because deriving
    // from the name makes several of them worse, not better. That is a judgement
    // call about real members' URLs, so it goes in front of Ian rather than into
    // an --apply run.
    $notProposed = [];
    foreach ($cand as $c) {
        $cur = strtolower(trim((string) $c['slug']));
        if ($cur === '') continue;
        $local     = $emailLocal((string) $c['primary_email']);
        $emailSlug = Slug::fit(Slug::derive((string) $c['primary_email']));
        $localSlug = Slug::fit(Slug::derive($local));
        $nameSlug  = Slug::fit(Slug::derive((string) $c['display_name']));
        if ($nameSlug !== '' && strcasecmp($cur, $nameSlug) !== 0
            && (strcasecmp($cur, $emailSlug) === 0 || strcasecmp($cur, $localSlug) === 0)) {
            $notProposed[] = ['name' => (string) $c['display_name'], 'cur' => $cur, 'would' => $nameSlug];
        }
    }
    if ($notProposed) {
        fwrite($f, '<h2>Considered and NOT proposed — needs your ruling <span class="k">' . count($notProposed) . '</span></h2>');
        fwrite($f, '<p class="why">These members\' URLs come from their email address, not their name, so Ruling 1 '
            . 'arguably applies. They are excluded because name-derivation makes several of them <b>worse</b> '
            . '(<code>ianhatesguitars</code> would become <code>hates</code>; <code>stuguitarsetups</code> would become '
            . '<code>stu-guitar</code>). Say the word and they can be folded in — as a group or one at a time.</p>');
        fwrite($f, '<table><tr><th>member</th><th>current URL</th><th>would become</th></tr>');
        foreach ($notProposed as $r) {
            fwrite($f, '<tr><td>' . $h($r['name']) . '</td><td class="old">/u/' . $h($r['cur'])
                . '</td><td>/u/' . $h($r['would']) . '</td></tr>');
        }
        fwrite($f, '</table>');
    }

    fwrite($f, '<h2>Also deliberately left alone</h2><p class="why">'
        . '<b>Slugs longer than the 30-char cap</b> (e.g. <code>shawn-reams-north-jersey-guitar</code>, 31 chars) '
        . 'predate the cap being enforced at mint. They resolve fine; re-deriving them only shortens a working URL. '
        . '<b>WordPress <code>user_nicename</code></b> stays <code>patreon_*</code> for ~1,634 accounts on purpose — '
        . '<code>/members/&lt;nicename&gt;/</code> already 301s to <code>/u/&lt;nicename&gt;</code>, which slug history '
        . '301s again to the current URL, so those links work untouched.</p>');
    fclose($f);
    fwrite(STDERR, "html: $HTML\n");
}

if (!$APPLY) fwrite(STDERR, "\nNO WRITES PERFORMED. Re-run with --apply to write.\n");
