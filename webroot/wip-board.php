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

/**
 * The DETAIL sections — everything below the index, keyed by item id.
 *
 * The file's own shape: `## <id> <title> …` headings, each running until the
 * next `##`. The index says what the order is; these say what the work IS. The
 * modal shows one, so an item can be opened without leaving the board or
 * hunting through a 64 KB markdown file.
 *
 * @return array<string,array{heading:string,body:string}>
 */
function lgb_parse_details(string $path): array
{
    if (!is_readable($path)) { return []; }
    $raw   = str_replace([ "\r\n", "\r" ], "\n", (string) file_get_contents($path));
    $lines = explode("\n", $raw);

    $out = []; $curId = null; $curHead = ''; $buf = [];
    $flush = static function () use (&$out, &$curId, &$curHead, &$buf): void {
        if ($curId !== null) {
            $out[$curId] = ['heading' => $curHead, 'body' => trim(implode("\n", $buf))];
        }
    };
    foreach ($lines as $line) {
        if (str_starts_with($line, '## ')) {
            $flush();
            $head = trim(substr($line, 3));
            // The id is the first token, after any leading tick.
            $probe = ltrim($head, "✅ \t");
            $curId = preg_match('/^([A-Z]?\d+(?:\.\d+)?)/u', $probe, $m) ? $m[1] : null;
            $curHead = $head; $buf = [];
            continue;
        }
        if ($curId !== null) { $buf[] = $line; }
    }
    $flush();
    return $out;
}

/* ---------------------------------------------------------------------- *
 * Ian's desk — the top strip
 *
 * Ian, 2026-08-15, looking at the live board: "is that on the wip list?" — his
 * own queue was nowhere on the page. docs/IAN-DESK.md is keeper-maintained and
 * is the truth; this only renders it. Same law as everything else here: the
 * file says what waits on him, the board never keeps its own copy.
 * ---------------------------------------------------------------------- */

/**
 * @return array{items:array<int,array{lead:string,rest:string,optional:bool}>,present:bool}
 */
function lgb_desk(string $repo): array
{
    $f = $repo . '/docs/IAN-DESK.md';
    if (!is_readable($f)) { return ['items' => [], 'present' => false]; }

    $raw   = str_replace([ "\r\n", "\r" ], "\n", (string) file_get_contents($f));
    $items = [];
    // Bullets can wrap, so join continuation lines before splitting on "- ".
    $joined = (string) preg_replace('/\n(?!\s*[-#*]|\n)\s+/', ' ', $raw);
    foreach (explode("\n", $joined) as $line) {
        if (!str_starts_with(ltrim($line), '- ')) { continue; }
        $t = trim(substr(ltrim($line), 2));
        $optional = false;
        if (preg_match('/^\*\(Optional\)\*\s*/i', $t)) {
            $optional = true;
            $t = (string) preg_replace('/^\*\(Optional\)\*\s*/i', '', $t);
        }
        $lead = '';
        if (preg_match('/^\*\*(.+?)\*\*\s*/s', $t, $m)) {
            $lead = trim($m[1]);
            $t    = trim(substr($t, strlen($m[0])));
        }
        if ($lead === '' && $t === '') { continue; }
        $items[] = ['lead' => $lead, 'rest' => trim($t), 'optional' => $optional];
    }
    return ['items' => $items, 'present' => true];
}

/** Turn bare URLs into links; everything else stays literal text. */
function lgb_linkify(string $s): string
{
    return (string) preg_replace(
        '#(https?://[^\s<)]+)#',
        '<a href="$1" target="_blank" rel="noopener">$1</a>',
        lgb_h($s)
    );
}

/* ---------------------------------------------------------------------- *
 * Items → PROJECTS
 *
 * Ian: "nested and have names of the projects rather than the p0 etc." The
 * P-band still decides ORDER and badge colour; it just stops being a heading.
 * The map itself lives in docs/board-projects.php — explicit and committed,
 * because a wrong grouping hides work under a name its owner would never look
 * under. Anything unmatched goes to "unsorted", ON THE BOARD, so a gap in the
 * map is visible rather than absorbed.
 * ---------------------------------------------------------------------- */

function lgb_projects(string $repo): array
{
    $f = $repo . '/docs/board-projects.php';
    if (!is_readable($f)) { return ['projects' => [], 'rules' => []]; }
    $cfg = require $f;
    return is_array($cfg) ? $cfg + ['projects' => [], 'rules' => []] : ['projects' => [], 'rules' => []];
}

/** First matching rule wins; null means unsorted, which is a visible state. */
function lgb_project_for(array $it, array $cfg): ?string
{
    foreach ($cfg['rules'] as $rule) {
        $idOk    = !isset($rule['ids'])   || in_array($it['id'], (array) $rule['ids'], true);
        $titleOk = !isset($rule['title']) || (bool) preg_match($rule['title'], $it['raw']);
        if ($idOk && $titleOk && (isset($rule['ids']) || isset($rule['title']))) {
            return (string) $rule['project'];
        }
    }
    return null;
}

/** P0 sorts above P1 and so on; unknown bands sort last. Priority, made silent. */
function lgb_weight(string $band): int
{
    return preg_match('/^P(\d)$/', $band, $m) ? (int) $m[1] : 9;
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
$GLOBALS['LGB_ROW'] = 0;   // row keys; see the payload note below
$details  = lgb_parse_details($BACKLOG);
$projCfg  = lgb_projects($REPO);
$desk     = lgb_desk($REPO);
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

  /* ONE CONTINUOUS LIST (Ian: "one surface like the order"). A band change is a
     thin marker in the flow, not a wall across it — the eye should read top to
     bottom as one ranking, with the band available as context rather than as a
     section it has to cross. */
  .band__h{display:flex;align-items:center;gap:8px;margin:14px 0 6px}
  .band__h:first-of-type{margin-top:2px}
  .band__dot{width:9px;height:9px;border-radius:99px;flex:none}
  .band__n{font-size:.7rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--ink-mute)}
  .band__l{font-size:.72rem;color:var(--ink-mute);flex:1;min-width:0;overflow:hidden;
           text-overflow:ellipsis;white-space:nowrap;border-bottom:1px solid var(--line-soft);
           padding-bottom:2px;margin-bottom:-2px}
  .band__c{font-size:.72rem;color:var(--ink-mute)}
  .P0 .band__dot{background:#8a3208} .P1 .band__dot{background:#b8860b}
  .P2 .band__dot{background:#8a8478} .P3 .band__dot{background:#cfc7b8}
  .P0 .band__n{color:#8a3208} .P1 .band__n{color:#7a5c04}

  /* HUMAN TITLES LEAD. The engineering id is a suffix you can find when you
     want it, not the first thing you read. */
  .row__t{font-size:.9rem;font-weight:500;flex:1;min-width:0}
  .row__n{font-family:ui-monospace,Menlo,monospace;font-size:.68rem;color:#a9a294;flex:none;
          margin-left:2px;font-weight:400}

  /* YOUR DESK — the top strip. Ian's own queue, above everything, because
     "is that on the wip list?" meant it was not and should have been. */
  .desk{background:#fffaf3;border:1px solid var(--accent-soft);border-left:4px solid var(--accent);
        border-radius:10px;padding:12px 15px;margin:0 0 14px}
  .desk__h{display:flex;align-items:baseline;gap:9px;margin:0 0 9px}
  .desk__t{font-size:.95rem;font-weight:700;color:var(--accent)}
  .desk__c{font-size:.74rem;color:var(--ink-mute)}
  .desk__i{display:flex;gap:9px;align-items:flex-start;padding:6px 0;border-top:1px solid #f2e2d3}
  .desk__i:first-of-type{border-top:0}
  .desk__b{width:7px;height:7px;border-radius:99px;background:var(--accent);flex:none;margin-top:7px}
  .desk__x{flex:1;min-width:0;font-size:.86rem;line-height:1.5}
  .desk__x b{color:var(--ink)}
  .desk__x span{color:var(--ink-soft)}
  .desk__i--opt .desk__b{background:var(--ink-mute);opacity:.6}
  .desk__i--opt .desk__x{color:var(--ink-mute)}
  .desk__i--opt .desk__x b{color:var(--ink-soft);font-weight:600}
  .desk__opt{font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-mute);
             background:#f0ece2;border-radius:99px;padding:1px 7px;margin-left:6px;white-space:nowrap}
  .desk--empty{background:#f2f6ee;border-color:#d6e3ca;border-left-color:var(--good)}
  .desk--empty .desk__t{color:var(--good)}
  .desk a{color:var(--accent)}

  /* PROJECT ACCORDION. Priority lives in the dot's colour and in the sort —
     never in a heading. */
  .proj{border:1px solid var(--line);border-radius:10px;background:#fff;margin:0 0 8px;overflow:hidden}
  .proj[open]{box-shadow:0 1px 3px rgba(0,0,0,.05)}
  .proj__h{cursor:pointer;list-style:none;display:flex;align-items:center;gap:10px;
           padding:11px 13px;background:#fbf8f2;user-select:none}
  .proj__h::-webkit-details-marker{display:none}
  .proj__h::before{content:"▸";color:var(--ink-mute);font-size:.8rem;flex:none}
  .proj[open] .proj__h::before{content:"▾"}
  .proj__h:hover{background:#f7f2e8}
  .proj__dot{width:9px;height:9px;border-radius:99px;flex:none;background:var(--ink-mute)}
  .proj__n{font-size:.93rem;font-weight:700;flex:1;min-width:0;overflow:hidden;
           text-overflow:ellipsis;white-space:nowrap}
  .proj__roll{display:flex;align-items:center;gap:8px;flex:none}
  .proj__c{font-size:.76rem;color:var(--ink-soft)}
  .proj__d{font-size:.73rem;color:var(--ink-mute)}
  .proj__b{padding:10px 13px 12px}
  .proj__empty{font-size:.79rem;color:var(--ink-mute);padding:2px 0 4px}
  .proj--unsorted{border-color:var(--accent-soft);background:#fffaf3}
  .proj--unsorted .proj__h{background:#fff3e8}
  .proj--unsorted .proj__n{color:var(--accent)}

  /* Priority, as colour only — P0 hottest, P3 coolest. */
  .w0{background:#8a3208} .w1{background:#b8860b} .w2{background:#8a8478} .w3,.w9{background:#cfc7b8}
  .row.w0{border-left:3px solid #8a3208} .row.w1{border-left:3px solid #b8860b}
  .row.w2{border-left:3px solid #d8d0c0} .row.w3,.row.w9{border-left:3px solid var(--line)}

  /* DONE COLLAPSES AWAY. Open work is the list; finished work is a drawer. */
  .donebox{margin-top:20px;border-top:1px solid var(--line);padding-top:12px}
  .donebox>summary{cursor:pointer;font-size:.8rem;color:var(--ink-mute);list-style:none;
                   display:flex;align-items:center;gap:7px;padding:4px 0}
  .donebox>summary::-webkit-details-marker{display:none}
  .donebox>summary::before{content:"▸";font-size:.75rem;transition:none}
  .donebox[open]>summary::before{content:"▾"}
  .donebox>summary:hover{color:var(--ink-soft)}
  .donebox .row{opacity:.62}

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
  .bdg--decide{background:var(--accent);color:#fff;box-shadow:0 1px 3px rgba(185,69,11,.35)}
  .bdg--look{background:#f7ecd5;color:var(--warn)}
  .bdg--blocked{background:#f3dcd4;color:var(--blocked)}
  .bdg--unowned{background:#eee9df;color:var(--ink-mute)}
  .bdg--done{background:#e8efe2;color:var(--good)}

  .row--open{cursor:pointer}
  .row--open:hover{border-color:var(--accent);background:#fffdf8}
  .row--open:focus{outline:2px solid var(--accent);outline-offset:1px}

  /* the work modal — read-only in phase 1 */
  .scrim{position:fixed;inset:0;background:rgba(31,29,26,.45);display:none;align-items:flex-start;
         justify-content:center;padding:32px 18px;z-index:50;overflow:auto}
  .scrim.on{display:flex}
  .modal{background:#fff;border-radius:12px;max-width:760px;width:100%;box-shadow:0 18px 44px rgba(0,0,0,.3);
         display:flex;flex-direction:column;max-height:calc(100vh - 64px)}
  .modal__h{padding:14px 18px;border-bottom:1px solid var(--line);display:flex;gap:12px;align-items:flex-start}
  .modal__t{font-size:1rem;font-weight:700;line-height:1.35;flex:1}
  .modal__x{border:0;background:none;color:var(--ink-mute);font-size:1.3rem;line-height:1;cursor:pointer;padding:0 2px}
  .modal__b{padding:15px 18px;overflow:auto}
  .modal__b pre{white-space:pre-wrap;word-wrap:break-word;font:13px/1.62 ui-monospace,Menlo,monospace;
                margin:0;color:var(--ink-soft)}
  .modal__b a{color:var(--accent)}
  .modal__meta{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 12px}
  .phase2{margin:14px 0 0;padding:10px 12px;background:#fbf8f2;border:1px solid var(--line-soft);
          border-radius:8px;font-size:.79rem;color:var(--ink-mute)}
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

  <!-- YOUR DESK. Rendered from docs/IAN-DESK.md, which keeper maintains — the
       board holds no copy of its own. Ian asked "is that on the wip list?" of
       something waiting on him, and it was not on the page at all. -->
  <?php if ($desk['present']): ?>
    <?php if ($desk['items'] === []): ?>
      <div class="desk desk--empty">
        <div class="desk__h"><span class="desk__t">Your desk</span></div>
        <div class="desk__x">Nothing waits on you.</div>
      </div>
    <?php else:
      $needed = 0; foreach ($desk['items'] as $d) { if (!$d['optional']) { $needed++; } } ?>
      <div class="desk">
        <div class="desk__h">
          <span class="desk__t">Your desk</span>
          <span class="desk__c"><?= (int) $needed ?> waiting on you<?php
            $opt = count($desk['items']) - $needed;
            if ($opt > 0) { echo ' · ' . (int) $opt . ' optional'; } ?></span>
        </div>
        <?php foreach ($desk['items'] as $d): ?>
          <div class="desk__i<?= $d['optional'] ? ' desk__i--opt' : '' ?>">
            <span class="desk__b"></span>
            <span class="desk__x">
              <?php if ($d['lead'] !== ''): ?><b><?= lgb_linkify($d['lead']) ?></b><?php endif; ?>
              <?php if ($d['optional']): ?><span class="desk__opt">optional</span><?php endif; ?>
              <?php if ($d['rest'] !== ''): ?> <span><?= lgb_linkify($d['rest']) ?></span><?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- ACCORDION OF NAMED PROJECTS (Ian, on the live board: "nested and have
       names of the projects rather than the p0 etc." + "I'd like the sub tasks
       in an accordion"). Panels are INDEPENDENT — opening one does not close
       another, because comparing two projects is a normal thing to want.
       Priority is still here; it just sorts and colours instead of shouting. -->
  <?php
    $groups = [];   // key => ['name'=>, 'order'=>, 'open'=>[], 'done'=>[]]
    foreach ($backlog['bands'] as $band) {
        $w = lgb_weight($band['name']);
        foreach ($band['items'] as $it) {
            $key  = lgb_project_for($it, $projCfg);
            $meta = $key !== null && isset($projCfg['projects'][$key])
                ? $projCfg['projects'][$key]
                : ['name' => 'Unsorted — not in the project map', 'order' => 9999];
            $k = $key ?? '_unsorted';
            if (!isset($groups[$k])) { $groups[$k] = $meta + ['open' => [], 'done' => [], 'minw' => 9]; }
            $it['_w'] = $w;
            if ($it['done']) { $groups[$k]['done'][] = $it; }
            else {
                $groups[$k]['open'][] = $it;
                $groups[$k]['minw'] = min($groups[$k]['minw'], $w);
            }
        }
    }
    // Projects with the most urgent OPEN work first; then the resting order.
    // Unsorted always sits last so it reads as a gap, not as a project.
    uasort($groups, static function (array $x, array $y): int {
        if ($x['name'] === $y['name']) { return 0; }
        $xu = str_starts_with($x['name'], 'Unsorted'); $yu = str_starts_with($y['name'], 'Unsorted');
        if ($xu !== $yu) { return $xu ? 1 : -1; }
        return [$x['minw'], $x['order']] <=> [$y['minw'], $y['order']];
    });
    foreach ($groups as $g) { usort($g['open'], static fn ($p, $q) => $p['_w'] <=> $q['_w']); }

    $renderRow = function (array $it): void {
        $rowKey = 'r' . (++$GLOBALS['LGB_ROW']); ?>
        <div class="row row--open<?= $it['done'] ? ' row--done' : '' ?> w<?= (int) ($it['_w'] ?? 9) ?>"
             data-item="<?= lgb_h($rowKey) ?>" tabindex="0" role="button" title="Open this item">
          <span class="row__t"><?= lgb_h($it['title']) ?></span>
          <span class="row__b">
            <?php if (!$it['done'] && $it['needsIan']): ?><span class="bdg bdg--decide">needs you</span><?php endif; ?>
            <?php if (!$it['done'] && $it['look']): ?><span class="bdg bdg--look">look</span><?php endif; ?>
            <?php if (!$it['done'] && $it['blocked']): ?><span class="bdg bdg--blocked">blocking</span><?php endif; ?>
            <?php if (!$it['done'] && $it['unowned']): ?><span class="bdg bdg--unowned">unowned</span><?php endif; ?>
            <?php if ($it['done']): ?><span class="bdg bdg--done">done</span><?php endif; ?>
          </span>
          <span class="row__o"><?= $it['owner'] !== null ? lgb_h($it['owner']) : '' ?></span>
          <span class="row__n"><?= lgb_h($it['id']) ?></span>
        </div>
      <?php };
  ?>

  <?php foreach ($groups as $g):
        $needs = 0; foreach ($g['open'] as $o) { if ($o['needsIan'] || $o['look']) { $needs++; } }
        $unsorted = str_starts_with($g['name'], 'Unsorted'); ?>
    <details class="proj<?= $unsorted ? ' proj--unsorted' : '' ?>"<?= ($needs > 0 || $unsorted) ? ' open' : '' ?>>
      <summary class="proj__h">
        <span class="proj__dot w<?= (int) $g['minw'] ?>"></span>
        <span class="proj__n"><?= lgb_h($g['name']) ?></span>
        <span class="proj__roll">
          <?php if ($needs > 0): ?><span class="bdg bdg--decide"><?= (int) $needs ?> need you</span><?php endif; ?>
          <span class="proj__c"><?= count($g['open']) ?> open</span>
          <?php if ($g['done'] !== []): ?><span class="proj__d"><?= count($g['done']) ?> done</span><?php endif; ?>
        </span>
      </summary>
      <div class="proj__b">
        <?php if ($g['open'] === []): ?>
          <div class="proj__empty">nothing open here</div>
        <?php endif; ?>
        <?php foreach ($g['open'] as $it) { $renderRow($it); } ?>
        <?php if ($g['done'] !== []): ?>
          <details class="donebox">
            <summary>done (<?= count($g['done']) ?>)</summary>
            <?php foreach ($g['done'] as $it) { $renderRow($it); } ?>
          </details>
        <?php endif; ?>
      </div>
    </details>
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

  <!-- The work modal. READ-ONLY in phase 1: it shows what an item IS. The
       decisions, the per-item thread, images and "Other" are phase 2, and every
       one of those writes. -->
  <div class="scrim" id="lgb-scrim" role="dialog" aria-modal="true" aria-labelledby="lgb-title">
    <div class="modal">
      <div class="modal__h">
        <div class="modal__t" id="lgb-title"></div>
        <button class="modal__x" id="lgb-close" aria-label="Close">&#10005;</button>
      </div>
      <div class="modal__b">
        <div class="modal__meta" id="lgb-meta"></div>
        <pre id="lgb-body"></pre>
        <div class="phase2">Read-only for now. Answering decisions, the per-item
          thread, images and drag-to-rank all write, so they come with phase 2.</div>
      </div>
    </div>
  </div>

  <!-- Detail bodies, embedded rather than fetched: it keeps the page free of
       query input (which the gate asserts, and which is a real property for a
       surface with no auth of its own beyond the dev gate). -->
  <script type="application/json" id="lgb-details"><?php
    // ONE ENTRY PER ITEM, always. Only 7 of the file's 39 detail sections are
    // id-headed — the other 30 are the date-headed shipped archive — so keying
    // the modal on detail alone would have made 42 of 49 items unopenable.
    // What every item DOES have is its full index line, which the row itself
    // truncates to fit. So the modal always has something true to show, and the
    // detail section is a bonus when the file carries one.
    // KEYED PER ROW, NOT PER ID — because ids are not unique. The index really
    // does carry "9" twice (Shop Layout Planner in P1, Advanced search in P2),
    // so an id-keyed map silently collapses them and both rows open the second
    // one's text. Two rows, one payload, wrong content, no error.
    $payload = []; $n = 0;
    foreach ($backlog['bands'] as $b) {
        foreach ($b['items'] as $it) {
            $d = $details[$it['id']] ?? null;
            $payload['r' . (++$n)] = [
                'heading' => $it['id'] . ' · ' . $it['title'],
                'line'    => $it['raw'],
                'band'    => $b['name'] !== '' ? $b['name'] : $b['label'],
                'owner'   => $it['owner'],
                'detail'  => $d['body']    ?? '',
                'dhead'   => $d['heading'] ?? '',
            ];
        }
    }
    echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
  ?></script>
  <script>
  (function () {
    var data = {};
    try { data = JSON.parse(document.getElementById('lgb-details').textContent || '{}'); } catch (e) {}
    var scrim = document.getElementById('lgb-scrim'),
        title = document.getElementById('lgb-title'),
        body  = document.getElementById('lgb-body'),
        meta  = document.getElementById('lgb-meta');

    function esc(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

    function open(id, row) {
      var d = data[id]; if (!d) return;
      title.textContent = d.dhead || d.heading || id;
      var text = d.line || '';
      if (d.detail) { text += '\n\n────────\n\n' + d.detail; }
      // Links are made clickable, everything else stays literal text.
      body.innerHTML = esc(text).replace(
        /(https?:\/\/[^\s<)]+|\/[a-z0-9][\w./-]*\/)/gi,
        function (m) { return '<a href="' + m + '" target="_blank" rel="noopener">' + m + '</a>'; });
      meta.innerHTML = '';
      row.querySelectorAll('.bdg').forEach(function (b) { meta.appendChild(b.cloneNode(true)); });
      scrim.classList.add('on');
      document.getElementById('lgb-close').focus();
    }
    function close() { scrim.classList.remove('on'); }

    document.querySelectorAll('.row[data-item]').forEach(function (row) {
      row.addEventListener('click', function () { open(row.getAttribute('data-item'), row); });
      row.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(row.getAttribute('data-item'), row); }
      });
    });
    document.getElementById('lgb-close').addEventListener('click', close);
    scrim.addEventListener('click', function (e) { if (e.target === scrim) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  })();
  </script>
</div>
</body>
</html>
