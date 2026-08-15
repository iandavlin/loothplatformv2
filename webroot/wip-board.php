<?php
/**
 * /wip-board.php — THE WORK BOARD, phase 1 (read-only).
 *
 * Backlog 29. Ian, 2026-08-15, on the round-4 mock: "It's good enough to start
 * building though. We can work through the issues as they come up." So this
 * ships the half that is safe to ship — the board renders, nothing writes.
 *
 * WHAT PHASE 1 IS
 *   docs/BACKLOG.md rendered as the ranked list it already claims to be, with
 *   derived badges, a light per lane and a server capacity strip. Read-only:
 *   no drag, no commits, no chat. Those are phase 2 and every one of them
 *   writes, so they get the fences a write deserves.
 *
 * WHY IT NEEDS NO FLAG
 *   This is a DEV-FACING surface behind nginx's dev-gate cookie. It renders no
 *   member content and is reachable by nobody who is not already past the gate.
 *   The flag rule exists for member-facing surfaces; this is not one. Phase 2's
 *   WRITE endpoints are a different matter and are fenced as writes.
 *
 * WHY IT BOOTS NO WORDPRESS
 *   It needs none: the sources are a markdown file and a JSON stamp. Booting WP
 *   would add a database, a theme and a plugin stack to a page that reads two
 *   files. Note `__DIR__` resolves THROUGH the docroot symlink back into the
 *   repo — normally the trap that breaks `wp-load` requires, here exactly what
 *   is wanted, because the file to read is the repo's own BACKLOG.md.
 *
 * THE ONE RULE THIS PAGE OBEYS ABOVE ALL: DERIVED, NEVER TYPED.
 *   Every badge, count, light and bar is computed from a source of truth at
 *   render time. Nothing here is maintained by hand, because a hand-maintained
 *   board becomes one more thing to keep in sync and a stale badge is worse
 *   than no badge — it actively misleads. Where a thing CANNOT be derived
 *   honestly it is not shown, and where a source is missing the page says so
 *   rather than drawing a comforting zero.
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------- *
 * Sources. Both are read-only and both may legitimately be absent.
 * ---------------------------------------------------------------------- */

/** The repo root, reached through the docroot symlink (see header). */
$REPO     = dirname(__DIR__);

/**
 * Test seam, in the same spirit as StripeLifecycle::$confirmFactory: the gate
 * points these at fixtures so it can exercise a MISSING and a MALFORMED
 * sentinel without touching the real one. Env vars are readable only by
 * whoever can set the process environment — a web request cannot.
 */
$BACKLOG  = getenv('LGB_BACKLOG') ?: $REPO . '/docs/BACKLOG.md';

/**
 * The sentinel stamp. Keeper is widening the old text stamp
 * (`<epoch> <time> working=N`) into this JSON contract:
 *   { ts, load1, mem_avail_mb, swap_used_mb, disk_pct, lanes:[{name,state}] }
 * Until that lands the JSON will not exist, and the strip says so instead of
 * inventing numbers — a capacity strip that flatters the box is worse than none.
 */
$SENTINEL = getenv('LGB_SENTINEL') ?: '/home/ubuntu/.sentinel-status.json';

/* ---------------------------------------------------------------------- *
 * BACKLOG.md → the ranked list
 *
 * The file already opens with "PRIORITY INDEX (the order — edit THIS to
 * re-rank…)", and keeper and every lane already treat that list as the
 * ranking. So the board renders THAT, not a second list of its own.
 * ---------------------------------------------------------------------- */

/**
 * @return array{bands:array<int,array{name:string,items:array<int,array>}>,error:?string}
 */
function lgb_parse_backlog(string $path): array
{
    if (!is_readable($path)) {
        return ['bands' => [], 'error' => 'docs/BACKLOG.md is not readable from here.'];
    }
    // Split on newlines EXPLICITLY, never with \R.
    //
    // This cost a real bug: PCRE's \R, without the /u flag, also matches the
    // single byte 0x85 (NEL) — and 0x85 is the THIRD BYTE of "✅" (E2 9C 85).
    // So `preg_split('/\R/', …)` cut in half every line containing a tick,
    // leaving two fragments that are not valid UTF-8. `preg_match` with /u then
    // returns FALSE on such a fragment — silently, not as an error — so every
    // completed item simply vanished from the board with nothing to show why.
    $raw   = str_replace([ "\r\n", "\r" ], "\n", (string) file_get_contents($path));
    $lines = explode("\n", $raw);

    $bands   = [];
    $current = null;
    $inIndex = false;

    foreach ($lines as $line) {
        if (!$inIndex) {
            if (str_starts_with($line, '## PRIORITY INDEX')) { $inIndex = true; }
            continue;
        }
        // The index ends at the first horizontal rule or the next H2.
        if (str_starts_with($line, '---') || (str_starts_with($line, '## ') && !str_starts_with($line, '## PRIORITY'))) {
            break;
        }
        // A group header is any bold line. Most are priority bands (**P0 — …**)
        // but the file also groups by project (**EMAIL PROJECT …**, **SECURITY /
        // HYGIENE**), and those carry real items too. The first cut matched only
        // P-bands and numeric ids, and SILENTLY DROPPED 11 entries — including a
        // security item marked awaiting Ian. A board that quietly loses items is
        // worse than no board, because it gets trusted.
        if (preg_match('/^\*\*(.+?)\*\*\s*$/u', $line, $m)) {
            if ($current !== null) { $bands[] = $current; }
            $head = trim($m[1]);
            if (preg_match('/^(P\d)\s*[—-]\s*(.+)$/u', $head, $b)) {
                $current = ['name' => $b[1], 'label' => trim($b[2]), 'items' => []];
            } else {
                $current = ['name' => '', 'label' => $head, 'items' => []];
            }
            continue;
        }
        // Item line: the file uses numeric ids (4.2, 27, 3.10) AND letter-prefixed
        // ones (E1…E5 for the email project, S1/S2 for security).
        if ($current !== null && preg_match('/^([A-Z]?\d+(?:\.\d+)?)\s*[.)]?\s+(.*)$/u', $line, $m)) {
            $current['items'][] = lgb_item($m[1], trim($m[2]));
        }
    }
    if ($current !== null) { $bands[] = $current; }

    return ['bands' => $bands, 'error' => $bands === [] ? 'No PRIORITY INDEX found in docs/BACKLOG.md.' : null];
}

/**
 * One index line → a card, with every badge DERIVED from the line's own text.
 *
 * Deliberately conservative. Where the source does not say plainly, nothing is
 * claimed: there is no guessed "2 decisions" here, because a number that might
 * be wrong is worse than a flag that is merely coarse. Phase 2's modal shows
 * the enumerated questions, which is where a real count can come from.
 */
function lgb_item(string $id, string $text): array
{
    $plain = strip_tags($text);

    // Owner: the file's own "→ lane" / "→ MERGED" convention.
    $owner = null;
    if (preg_match('/→\s*([A-Za-z0-9\/_-]+)/u', $plain, $m)) { $owner = $m[1]; }

    $done = (bool) preg_match('/✅|MERGED|LIVE @|SHIPPED|CLOSED/u', $plain);

    // "Needs Ian" — a boolean, not a count, because the index does not enumerate
    // questions and inventing a number would be typing dressed as deriving.
    $needsIan = (bool) preg_match('/awaiting Ian|needs Ian|his look|Ian\'s (?:ruling|mock|call|go|per-member)|UNOWNED and|pending Ian/iu', $plain);

    // A picture exists to look at.
    $look = (bool) preg_match('#/footer-mockups/|Mock incl|Frames:|mocked#u', $plain);

    // Blocked / bit-him markers the file already uses in prose.
    $blocked = (bool) preg_match('/BLOCK(?:S|ED|ING)|bit Ian|bit him|MISSION CRITICAL|P0 data loss/iu', $plain);

    $unowned = (bool) preg_match('/\bUNOWNED\b/u', $plain);

    // The title is the sentence up to the first heavy separator — enough to
    // recognise the item without reproducing the whole entry.
    $title = preg_split('/\s+(?:—|–|→|\(|::)/u', $plain)[0] ?? $plain;
    $title = trim(mb_substr($title, 0, 120));

    return compact('id', 'title', 'owner', 'done', 'needsIan', 'look', 'blocked', 'unowned') + ['raw' => $plain];
}

/* ---------------------------------------------------------------------- *
 * The sentinel stamp → lane lights + capacity strip
 * ---------------------------------------------------------------------- */

/** @return array{ok:bool,why:?string,data:?array,age:?int} */
function lgb_sentinel(string $path): array
{
    if (!is_readable($path)) {
        return ['ok' => false, 'why' => 'no reading yet — the sentinel has not written ' . basename($path), 'data' => null, 'age' => null];
    }
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw)) {
        return ['ok' => false, 'why' => 'the sentinel file is not valid JSON', 'data' => null, 'age' => null];
    }
    $age = isset($raw['ts']) ? max(0, time() - (int) $raw['ts']) : null;
    return ['ok' => true, 'why' => null, 'data' => $raw, 'age' => $age];
}

/** Thresholds, stated once so the bars and the prose cannot disagree. */
const LGB_LOAD_THROTTLE = 4.0;   // 2 cores: above this we throttle lanes
const LGB_SWAP_STOP_MB  = 1024;  // above this we stop and clear
const LGB_DISK_RED_PCT  = 90;

function lgb_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); }

$backlog  = lgb_parse_backlog($BACKLOG);
$sentinel = lgb_sentinel($SENTINEL);

$totalItems = 0; $needsYou = 0;
foreach ($backlog['bands'] as $b) {
    foreach ($b['items'] as $it) {
        $totalItems++;
        if (($it['needsIan'] || $it['look']) && !$it['done']) { $needsYou++; }
    }
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Work board</title>
<style>
  :root{
    --bg:#f6f3ee; --panel:#fffdf9; --ink:#1f1d1a; --ink-soft:#4a463f; --ink-mute:#8a8478;
    --line:#e6e0d4; --line-soft:#efeae0; --accent:#b9450b; --accent-soft:#f4d8c4;
    --good:#5a7a3a; --warn:#b8860b; --blocked:#8a3208; --chrome:#262320; --chrome-ink:#e8e2d3; --chrome-mute:#a49c8c;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
       font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  .app{background:var(--chrome);color:var(--chrome-ink);padding:11px 18px;display:flex;
       align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
  .app__t{font-weight:700}
  .app__r{color:var(--chrome-mute);font-size:.8rem}
  .wrap{max-width:1100px;margin:0 auto;padding:16px 18px 70px}

  .rails{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin:0 0 14px}
  @media (max-width:760px){.rails{grid-template-columns:1fr}}
  .rail{background:#fbf8f2;border:1px solid var(--line-soft);border-radius:9px;padding:10px 12px}
  .rail__h{font-size:.7rem;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-mute);
           margin:0 0 8px;display:flex;justify-content:space-between;gap:8px}
  .lane{display:flex;align-items:center;gap:7px;font-size:.8rem;margin:0 0 4px}
  .lane__d{width:8px;height:8px;border-radius:99px;flex:none}
  .d--working{background:var(--good)} .d--parked{background:var(--ink-mute)}
  .d--waiting{background:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
  .lane__n{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .lane__s{color:var(--ink-mute);font-size:.74rem}
  .meter{margin:0 0 8px}
  .meter:last-child{margin-bottom:0}
  .meter__l{display:flex;justify-content:space-between;font-size:.76rem;margin:0 0 3px}
  .bar{height:7px;border-radius:99px;background:#eae4d8;position:relative;overflow:hidden}
  .bar__f{position:absolute;left:0;top:0;bottom:0;border-radius:99px}
  .f--ok{background:var(--good)} .f--warn{background:var(--warn)} .f--bad{background:var(--blocked)}
  .bar__t{position:absolute;top:-2px;bottom:-2px;width:2px;background:var(--ink);opacity:.45}
  .thr{font-size:.68rem;color:var(--ink-mute);margin-top:3px}
  .thr b{color:var(--blocked)}
  .absent{font-size:.8rem;color:var(--ink-soft);background:#fdf3f0;border:1px solid #eccfc4;
          border-radius:8px;padding:9px 11px}

  .band__h{display:flex;align-items:baseline;gap:9px;margin:16px 0 8px;padding-top:10px;border-top:2px solid var(--line)}
  .band__n{font-size:.78rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase}
  .band__l{font-size:.75rem;color:var(--ink-mute);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .band__c{font-size:.74rem;color:var(--ink-mute)}
  .P0 .band__n{color:#8a3208} .P1 .band__n{color:#7a5c04} .P2 .band__n{color:var(--ink-soft)} .P3 .band__n{color:var(--ink-mute)}

  .row{display:flex;align-items:center;gap:9px;background:#fff;border:1px solid var(--line);
       border-radius:8px;padding:8px 11px;margin:0 0 6px}
  .row--done{opacity:.62}
  .row__n{font-family:ui-monospace,Menlo,monospace;font-size:.75rem;color:var(--ink-mute);flex:none;min-width:2.8em}
  .row__t{font-size:.85rem;flex:1;min-width:0}
  .row__b{display:flex;gap:5px;flex:none;flex-wrap:wrap}
  .row__o{font-size:.71rem;color:var(--ink-mute);flex:none}
  .bdg{font-size:.66rem;font-weight:700;padding:2px 7px;border-radius:99px;white-space:nowrap;
       display:inline-flex;align-items:center;gap:4px}
  .bdg::before{content:"";width:6px;height:6px;border-radius:99px;background:currentColor}
  .bdg--decide{background:#fbe3d8;color:var(--accent)}
  .bdg--look{background:#f7ecd5;color:var(--warn)}
  .bdg--blocked{background:#f3dcd4;color:var(--blocked)}
  .bdg--unowned{background:#eee9df;color:var(--ink-mute)}
  .bdg--done{background:#e8efe2;color:var(--good)}

  .foot{margin-top:26px;padding-top:14px;border-top:1px solid var(--line);font-size:.76rem;color:var(--ink-mute);line-height:1.6}
  .foot b{color:var(--ink-soft)}
  .err{background:#fdf3f0;border:1px solid #eccfc4;border-radius:9px;padding:12px 14px;color:#7a3a22;margin:0 0 14px}
</style>
</head>
<body>

<div class="app">
  <div class="app__t">Work board</div>
  <div class="app__r">
    <?= (int) $totalItems ?> items ·
    <?= (int) $needsYou ?> want you ·
    read-only (phase 1)
  </div>
</div>

<div class="wrap">

<?php if ($backlog['error'] !== null): ?>
  <div class="err"><b>The backlog could not be read.</b><br><?= lgb_h($backlog['error']) ?></div>
<?php endif; ?>

  <div class="rails">
    <!-- lane lights -->
    <div class="rail">
      <div class="rail__h"><span>The teams</span><span>
        <?= $sentinel['ok'] && isset($sentinel['data']['lanes']) ? count((array) $sentinel['data']['lanes']) . ' seats' : '—' ?>
      </span></div>
      <?php if ($sentinel['ok'] && !empty($sentinel['data']['lanes'])): ?>
        <?php foreach ((array) $sentinel['data']['lanes'] as $lane):
              $st = (string) ($lane['state'] ?? 'parked');
              $cls = in_array($st, ['working', 'parked', 'waiting'], true) ? $st : 'parked'; ?>
          <div class="lane">
            <span class="lane__d d--<?= lgb_h($cls) ?>"></span>
            <span class="lane__n"><?= lgb_h((string) ($lane['name'] ?? '?')) ?></span>
            <span class="lane__s"><?= lgb_h($st) ?></span>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="absent"><?= lgb_h($sentinel['why'] ?? 'no lane data') ?>.<br>
          Showing nothing rather than a comforting zero.</div>
      <?php endif; ?>
    </div>

    <!-- capacity -->
    <div class="rail">
      <div class="rail__h"><span>The server</span><span>
        <?= $sentinel['ok'] && $sentinel['age'] !== null ? 'stamped ' . (int) floor($sentinel['age'] / 60) . ' min ago' : '—' ?>
      </span></div>
      <?php if ($sentinel['ok']):
            $d = $sentinel['data'];
            $load = isset($d['load1']) ? (float) $d['load1'] : null;
            $mem  = isset($d['mem_avail_mb']) ? (int) $d['mem_avail_mb'] : null;
            $swap = isset($d['swap_used_mb']) ? (int) $d['swap_used_mb'] : null;
            $disk = isset($d['disk_pct']) ? (int) $d['disk_pct'] : null; ?>

        <?php if ($load !== null): $pct = min(100, $load / (LGB_LOAD_THROTTLE * 2) * 100); ?>
          <div class="meter">
            <div class="meter__l"><b>Load</b><span><?= lgb_h(number_format($load, 2)) ?> of 2 cores</span></div>
            <div class="bar"><div class="bar__f <?= $load >= LGB_LOAD_THROTTLE ? 'f--bad' : ($load >= LGB_LOAD_THROTTLE * .6 ? 'f--warn' : 'f--ok') ?>"
                 style="width:<?= (int) $pct ?>%"></div><div class="bar__t" style="left:50%"></div></div>
            <div class="thr">marker = <?= LGB_LOAD_THROTTLE ?>, where we throttle lanes</div>
          </div>
        <?php endif; ?>

        <?php if ($mem !== null): ?>
          <div class="meter">
            <div class="meter__l"><b>Memory</b><span><?= lgb_h(number_format($mem / 1024, 1)) ?> GB free</span></div>
            <div class="bar"><div class="bar__f <?= $mem < 500 ? 'f--bad' : ($mem < 1500 ? 'f--warn' : 'f--ok') ?>"
                 style="width:<?= (int) max(2, min(100, 100 - ($mem / 7800 * 100))) ?>%"></div></div>
          </div>
        <?php endif; ?>

        <?php if ($swap !== null): ?>
          <div class="meter">
            <div class="meter__l"><b>Swap</b><span><?= lgb_h(number_format($swap / 1024, 1)) ?> GB of 2.0</span></div>
            <div class="bar"><div class="bar__f <?= $swap >= LGB_SWAP_STOP_MB ? 'f--bad' : ($swap >= LGB_SWAP_STOP_MB * .6 ? 'f--warn' : 'f--ok') ?>"
                 style="width:<?= (int) min(100, $swap / 2047 * 100) ?>%"></div><div class="bar__t" style="left:50%"></div></div>
            <div class="thr">marker = 1 GB, where we stop and clear</div>
          </div>
        <?php endif; ?>

        <?php if ($disk !== null): ?>
          <div class="meter">
            <div class="meter__l"><b>Disk</b><span><?= (int) $disk ?>% used</span></div>
            <div class="bar"><div class="bar__f <?= $disk >= LGB_DISK_RED_PCT ? 'f--bad' : ($disk >= 80 ? 'f--warn' : 'f--ok') ?>"
                 style="width:<?= (int) min(100, $disk) ?>%"></div>
                 <div class="bar__t" style="left:<?= LGB_DISK_RED_PCT ?>%"></div></div>
            <?php if ($disk >= LGB_DISK_RED_PCT): ?>
              <div class="thr"><b><?= (int) $disk ?>% — over the <?= LGB_DISK_RED_PCT ?>% line. Needs clearing.</b></div>
            <?php else: ?>
              <div class="thr">marker = <?= LGB_DISK_RED_PCT ?>%</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="absent"><?= lgb_h($sentinel['why'] ?? 'no reading') ?>.<br>
          A capacity strip that invented numbers would be worse than none.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- the ranked list -->
  <?php foreach ($backlog['bands'] as $band): ?>
    <div class="band__h <?= lgb_h($band['name']) ?>">
      <span class="band__n"><?= lgb_h($band['name']) ?></span>
      <span class="band__l"><?= lgb_h($band['label']) ?></span>
      <span class="band__c"><?= count($band['items']) ?></span>
    </div>
    <?php foreach ($band['items'] as $it): ?>
      <div class="row<?= $it['done'] ? ' row--done' : '' ?>" title="<?= lgb_h(mb_substr($it['raw'], 0, 400)) ?>">
        <span class="row__n"><?= lgb_h($it['id']) ?></span>
        <span class="row__t"><?= lgb_h($it['title']) ?></span>
        <span class="row__b">
          <?php if ($it['done']): ?><span class="bdg bdg--done">done</span><?php endif; ?>
          <?php if (!$it['done'] && $it['needsIan']): ?><span class="bdg bdg--decide">needs you</span><?php endif; ?>
          <?php if (!$it['done'] && $it['look']): ?><span class="bdg bdg--look">look</span><?php endif; ?>
          <?php if (!$it['done'] && $it['blocked']): ?><span class="bdg bdg--blocked">blocking</span><?php endif; ?>
          <?php if (!$it['done'] && $it['unowned']): ?><span class="bdg bdg--unowned">unowned</span><?php endif; ?>
        </span>
        <span class="row__o"><?= $it['owner'] !== null ? lgb_h($it['owner']) : '' ?></span>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <div class="foot">
    <b>Phase 1 — read-only.</b> Everything here is read from
    <code>docs/BACKLOG.md</code> and the sentinel stamp at render time. Nothing on
    this page is typed or kept in sync by hand: the order is the file's own
    PRIORITY INDEX, and the badges are derived from each item's text
    (<b>needs you</b> = the entry says it is awaiting you; <b>look</b> = a mock
    exists; <b>blocking</b>, <b>unowned</b>, <b>done</b> = the file's own markers).
    Deliberately no invented counts — the index does not enumerate questions, so
    a number here would be typing dressed as deriving. Phase 2 adds the drag-to-rank,
    the per-item work modal with its thread, and the keeper chat; all of those
    write, and get fenced as writes.
  </div>
</div>
</body>
</html>
