<?php
/**
 * /wip-board.php — THE WORK BOARD, phase 1 (read-only).
 *
 * Backlog 29. Ian, 2026-08-15, on the round-4 mock: "It's good enough to start
 * building though. We can work through the issues as they come up." So this
 * ships the half that is safe to ship — the board renders, nothing writes.
 *
 * PHASE 2 LANDED (2026-08-15, later): drag-to-rank inside a project, notes on
 *   an item, and decision buttons where a question was actually asked. All three
 *   write, and NONE of them writes here — see "THE WRITE LAYER" below. The page
 *   still boots no WordPress and still holds no credentials of any kind.
 *
 * WHAT PHASE 1 WAS
 *   docs/BACKLOG.md rendered as the ranked list it already claims to be, with
 *   derived badges, a light per lane and a server capacity strip. Read-only:
 *   no drag, no commits, no chat. Those were phase 2, and every one of them
 *   writes, so they got the fences a write deserves. The chat is still to come.
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
 * WHICH COPY OF THE BACKLOG THIS PAGE SHOWS — and why it is not always the
 * serving checkout's.
 *
 * The committer commits and pushes to main. The serving checkout only ever
 * PULLS, and nothing on this box pulls it on a timer — checked, there is no
 * such unit. So between Ian's drag and the next deploy, main and the serve
 * disagree, and a page that reads only the serve would show his drag land, then
 * show it GONE on the next reload. That is exactly the failure this build was
 * told to design against: the screen said done and the store disagreed.
 *
 * So when the committer's clone is readable and its copy DIFFERS, the board
 * reads the clone — which is main, the truth the committer writes to — and says
 * so on the page. It is not a second source of truth: the clone is reset hard
 * to origin/main at the start of every write, so it is main or it is nothing.
 * The serving checkout stays the fallback, and the gate's fixture override
 * (LGB_BACKLOG) still outranks both, so a test never silently reads the box.
 */
$LGB_MAIN_COPY = getenv('LGB_MAIN_COPY') ?: '/home/ubuntu/board-committer-clone/docs/BACKLOG.md';
$LGB_AHEAD     = false;
// Compared by CONTENT HASH, not by size. A reorder rewrites the same lines in a
// different sequence — identical byte count, different file — so a size check
// would be blind to the single case this exists for.
if (getenv('LGB_BACKLOG') === false && is_readable($LGB_MAIN_COPY) && is_readable($BACKLOG)
    && @md5_file($LGB_MAIN_COPY) !== @md5_file($BACKLOG)) {
    $LGB_AHEAD = true;
    $BACKLOG   = $LGB_MAIN_COPY;
}

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

/**
 * THE SHIPPED ARCHIVE — the half of BACKLOG.md the board could not see.
 *
 * The census found it: below `## ✅ SHIPPED TO LIVE` the file carries 30
 * date-headed sections, and none of them reached the board. Not dropped by
 * accident either — `lgb_parse_details` takes the first token of a heading as
 * the item id, and "2026-08-01 — …" yields "2026", so every archived section
 * collapsed onto that one key and the last one silently won. No item has id
 * 2026, so the whole lot was unreachable.
 *
 * That is the real content of Ian's "the board doesn't have all of the backlog":
 * the board showed what is LEFT and nothing of what was DONE.
 *
 * Kept deliberately separate from lgb_parse_details rather than folded into it.
 * The two answer different questions — "what is this item?" versus "what
 * happened, and when?" — and the archive is keyed by DATE, which is not an id
 * and must not be made to look like one.
 *
 * @return array<int,array{date:string,title:string,body:string}> newest first
 */
function lgb_parse_history(string $path): array
{
    if (!is_readable($path)) { return []; }
    $raw   = str_replace([ "\r\n", "\r" ], "\n", (string) file_get_contents($path));
    // Split on newlines EXPLICITLY, never \R — see lgb_parse_backlog.
    $lines = explode("\n", $raw);

    $out = []; $cur = null; $buf = [];
    $flush = static function () use (&$out, &$cur, &$buf): void {
        if ($cur !== null) { $cur['body'] = trim(implode("\n", $buf)); $out[] = $cur; }
    };
    foreach ($lines as $line) {
        if (str_starts_with($line, '## ')) {
            $flush(); $cur = null; $buf = [];
            $head = trim(substr($line, 3));
            $probe = ltrim($head, "✅ \t");
            // A DATE heading, and only a date heading. An item id like "4.2"
            // cannot match this, and a date cannot match the id parser — so a
            // section belongs to exactly one of the two views, never both.
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*[—–-]\s*(.+)$/u', $probe, $m)) {
                $cur = ['date' => $m[1], 'title' => trim($m[2]), 'body' => ''];
            }
            continue;
        }
        if ($cur !== null) { $buf[] = $line; }
    }
    $flush();

    // Newest first — the question this view answers is "what happened lately".
    usort($out, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
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

/* ---------------------------------------------------------------------- *
 * PHASE 2 — THE WRITE LAYER
 *
 * Ian's board points 2/3/4: drag to rank, notes, and decision buttons on the
 * items that need him. All three WRITE, and none of them writes here.
 *
 * THE PAGE NEVER TOUCHES GIT. Not "should not" — cannot: this runs as the
 * looth-dev pool, the serving checkout is ubuntu-owned with no write bit for
 * others, and the git credentials live with ubuntu. Every write is handed to
 * the committer service over its socket, and the committer's four fences decide
 * what happens. This file's job is to turn a gesture into an INTENT and to
 * report honestly what came back.
 *
 * WHY A POST BRANCH RATHER THAN A SECOND WEBROOT FILE. A new file in webroot/
 * needs its own symlink into the docroot, and that symlink set is not in the
 * repo — it is one of the two couplings a deploy pull does NOT handle, and a
 * missed one leaves a dangling link. wip-board.php is already symlinked and
 * already behind the dev gate, so the write path inherits both.
 *
 * CSRF: the dev gate is the real fence — nobody reaches this page without the
 * gate cookie. On top of that, every write must carry the X-LGB-Write header,
 * which a cross-site form post cannot set without a CORS preflight this
 * endpoint never answers. Stated rather than assumed: this is not a member
 * surface and carries no member data.
 * ---------------------------------------------------------------------- */

/** The committer's socket. Env-overridable for the gate, like LGB_BACKLOG. */
$LGB_SOCKET = getenv('LGB_SOCKET') ?: '/run/board-committer.sock';

/**
 * THE ACTOR IS DERIVED HERE, NEVER SENT BY THE CLIENT.
 *
 * Fence 2 refuses a write that does not name its actor, and an actor the page
 * accepts from the request is not an identity — it is a text field. The board
 * is behind the dev gate and the person on the other side of it is Ian, so the
 * actor is a server-side constant and a forged one is not possible.
 */
const LGB_ACTOR = 'ian-via-board';

/** Talk to the committer. Returns its own JSON answer, refusals included. */
function lgb_commit(array $intent, string $sock): array
{
    $fp = @stream_socket_client('unix://' . $sock, $errno, $err, 30);
    if (!$fp) {
        return ['ok' => false, 'error' => 'the committer service is not answering (' . ($err ?: 'no socket') . ')',
                'transport' => true];
    }
    stream_set_timeout($fp, 60);
    fwrite($fp, json_encode($intent, JSON_UNESCAPED_SLASHES));
    stream_socket_shutdown($fp, STREAM_SHUT_WR);
    $raw = stream_get_contents($fp);
    fclose($fp);

    $res = json_decode((string) $raw, true);
    if (!is_array($res)) {
        return ['ok' => false, 'error' => 'the committer gave an answer this page could not read', 'transport' => true];
    }
    return $res;
}

/**
 * The file's PRIORITY INDEX as a flat list, IN FILE ORDER, each entry carrying
 * its project and whether the board considers it done.
 *
 * Parsed with the PAGE's own parser — the same one that renders the rows — so
 * the order a drag is computed against is the order Ian is looking at. A second
 * parser here would be a second truth, and the two would drift the way the row
 * key and the payload key drifted before they were derived once.
 *
 * KEYED BY POSITION, NEVER BY ID. Ids in this file are not unique — the index
 * really did carry "9" twice — and an id-keyed map silently collapses the pair,
 * which is how the modal once opened the wrong item's text. A list indexed by
 * where the line actually sits cannot do that.
 *
 * @return array<int,array{id:string,proj:?string,done:bool}>
 */
function lgb_index_map(string $backlogPath, string $repo): array
{
    $parsed = lgb_parse_backlog($backlogPath);
    $cfg    = lgb_projects($repo);
    $out = [];
    foreach ($parsed['bands'] as $band) {
        foreach ($band['items'] as $it) {
            $out[] = ['id'   => (string) $it['id'],
                      'proj' => lgb_project_for($it, $cfg),
                      'done' => (bool) $it['done']];
        }
    }
    return $out;
}

/**
 * A drag INSIDE one project → a whole-file order the committer will accept.
 *
 * The committer demands a PERMUTATION of every id in the index, and Ian only
 * ever reorders within one project. So: the project's items keep the exact
 * SLOTS they already occupy in the file, and only which id sits in which of
 * those slots changes. Every other line stays where it was.
 *
 * That is what makes this safe to hand a strict fence — the result cannot fail
 * the permutation rule by construction, because it is the same multiset with
 * some positions swapped. It also means a drag inside "Membership" can never
 * disturb the order of "Guitardle", which is the property Ian would notice.
 *
 * @return array{ok:bool,order?:string[],why?:string}
 */
function lgb_project_reorder(array $map, ?string $project, array $submitted): array
{
    /**
     * DONE ITEMS ARE NOT IN THE DRAG, so they must not be in the comparison.
     *
     * The board renders completed items inside a collapsed "done" box and does
     * not make them draggable — so what comes back from a drag is the project's
     * OPEN rows. Comparing that against the project's whole membership refused
     * every drag in any project that had ever finished anything, with a message
     * blaming a stale board. Caught by the gate driving a real project; the
     * hand test before it had happened to pick the one project with nothing
     * done in it.
     *
     * Done items keep their slots untouched, which is also the behaviour you
     * would want: finishing something does not move it in the ranking.
     */
    $slots = [];   // positions in the global list this drag may rewrite
    foreach ($map as $i => $row) {
        if ($row['proj'] === $project && !$row['done']) { $slots[] = $i; }
    }
    if ($slots === []) { return ['ok' => false, 'why' => 'that project has no open items to rank']; }

    $have = array_map(static fn (int $i): string => $map[$i]['id'], $slots);
    $want = array_map('strval', $submitted);
    $h = $have; $w = $want; sort($h); sort($w);
    if ($h !== $w) {
        // The board's copy of the file and the committer's can differ — the
        // serve lags main until the next pull. Say which, rather than a generic
        // failure: "your board is stale" and "that drag was malformed" need
        // different actions from Ian.
        return ['ok' => false, 'why' => 'the dragged list does not match the items this board is showing — its copy of the backlog may be behind main'];
    }

    $order = array_map(static fn (array $r): string => $r['id'], $map);
    foreach ($slots as $n => $pos) { $order[$pos] = $want[$n]; }
    return ['ok' => true, 'order' => array_values($order)];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $reply = static function (array $p, int $code = 200): void {
        http_response_code($code);
        echo json_encode($p, JSON_UNESCAPED_SLASHES);
        exit;
    };

    if (($_SERVER['HTTP_X_LGB_WRITE'] ?? '') === '') {
        $reply(['ok' => false, 'error' => 'this endpoint only answers the board itself'], 403);
    }
    $req = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($req)) { $reply(['ok' => false, 'error' => 'body is not JSON'], 400); }

    $action = (string) ($req['action'] ?? '');
    $dry    = !empty($req['dry_run']);
    $map    = lgb_index_map($BACKLOG, $REPO);

    switch ($action) {
        case 'reorder':
            $submitted = $req['order'] ?? null;
            if (!is_array($submitted) || $submitted === []) {
                $reply(['ok' => false, 'error' => 'a reorder needs an order'], 400);
            }
            // '' is a real value here: it is the UNSORTED group, which is a
            // visible state on this board and must be draggable like any other.
            $project = array_key_exists('project', $req) && $req['project'] !== ''
                ? (string) $req['project'] : null;
            $built = lgb_project_reorder($map, $project, $submitted);
            if (!$built['ok']) { $reply(['ok' => false, 'error' => $built['why']], 409); }
            $res = lgb_commit(['intent' => 'reorder', 'actor' => LGB_ACTOR,
                               'order' => $built['order'], 'dry_run' => $dry], $LGB_SOCKET);
            break;

        /**
         * A message to a lane. The lane name is NOT trusted from the request
         * beyond a shape check here — the committer re-validates it, because
         * downstream it becomes a tmux session name and a filename, and a fence
         * that only exists on the page is a fence a curl walks around.
         */
        case 'lane_message':
            $lane = (string) ($req['lane'] ?? '');
            $text = trim((string) ($req['text'] ?? ''));
            if ($text === '') { $reply(['ok' => false, 'error' => 'an empty message is not a message'], 400); }
            $res = lgb_commit(['intent' => 'lane_message', 'actor' => LGB_ACTOR,
                               'lane' => $lane, 'text' => $text, 'dry_run' => $dry], $LGB_SOCKET);
            break;

        case 'note':
            $id   = (string) ($req['id'] ?? '');
            $text = trim((string) ($req['text'] ?? ''));
            if ($text === '') { $reply(['ok' => false, 'error' => 'an empty note is not a note'], 400); }
            $res = lgb_commit(['intent' => 'note_append', 'actor' => LGB_ACTOR,
                               'id' => $id, 'text' => $text, 'dry_run' => $dry], $LGB_SOCKET);
            break;

        /**
         * A DECISION IS A NOTE, deliberately — not a fourth intent.
         *
         * The build notes settled it: "a ruling made in the thread and one made
         * on the Decisions tab must be the same event, or the two views will
         * disagree about what was decided". Making the decision buttons write
         * the SAME store as the thread is how that is guaranteed rather than
         * maintained. It also keeps the committer's allowlist at three shapes;
         * a fourth verb would be a fourth thing to fence.
         *
         * "Other…" is a first-class answer, per Ian's round-4 correction: what
         * he types is recorded as HIS words, not as a footnote to a button he
         * did not press.
         */
        case 'decision':
            $id     = (string) ($req['id'] ?? '');
            $option = trim((string) ($req['option'] ?? ''));
            $words  = trim((string) ($req['text'] ?? ''));
            if ($option === '' && $words === '') {
                $reply(['ok' => false, 'error' => 'a decision needs an answer'], 400);
            }
            $text = $option !== '' && $option !== 'Other'
                ? 'DECISION: ' . $option . ($words !== '' ? "\n" . $words : '')
                : 'DECISION (his own words): ' . $words;
            $res = lgb_commit(['intent' => 'note_append', 'actor' => LGB_ACTOR,
                               'id' => $id, 'text' => $text, 'dry_run' => $dry], $LGB_SOCKET);
            break;

        default:
            $reply(['ok' => false, 'error' => 'unknown action'], 400);
    }

    // The committer's own answer, passed through. A refusal is NOT reshaped into
    // a success with a warning — the UI has to be able to tell them apart, which
    // is the whole lesson of "a refused save reads as preserved everything".
    $reply($res, !empty($res['ok']) ? 200 : 409);
}

/**
 * The per-item THREAD and its decision options.
 *
 * Both live beside the backlog the board is showing — so if this page is
 * reading main's copy because the serve is behind (see above), it reads main's
 * notes too. A board showing today's order beside yesterday's thread would be
 * the same lie in a smaller place.
 *
 * DECISION OPTIONS ARE READ, NEVER INVENTED. The mock draws item-specific
 * buttons ("Retract to free", "Give him a grace period") and BACKLOG.md cannot
 * derive those — they are a question somebody asked. So a lane poses one by
 * committing docs/board-decisions/<id>.md, one option per "- " line, and the
 * board renders exactly those. Where no file exists there is no invented
 * question: the item gets the plain note box and nothing that pretends to be a
 * decision. That is the derived-never-typed rule applied to the one surface
 * where guessing would put words in Ian's mouth.
 */
function lgb_board_dir(string $backlogPath, string $what): string
{
    return dirname($backlogPath) . '/' . $what;
}

/** @return array{thread:string,options:string[]} */
function lgb_item_extras(string $backlogPath, string $id): array
{
    $thread = '';
    $nf = lgb_board_dir($backlogPath, 'board-notes') . '/' . $id . '.md';
    if (preg_match('/^[A-Z]?\d+(\.\d+)?$/', $id) && is_readable($nf)) {
        $thread = (string) file_get_contents($nf);
    }
    $options = [];
    $df = lgb_board_dir($backlogPath, 'board-decisions') . '/' . $id . '.md';
    if (preg_match('/^[A-Z]?\d+(\.\d+)?$/', $id) && is_readable($df)) {
        foreach (explode("\n", (string) file_get_contents($df)) as $line) {
            if (str_starts_with(trim($line), '- ')) { $options[] = trim(substr(trim($line), 2)); }
        }
    }
    return ['thread' => $thread, 'options' => $options];
}

/**
 * A LANE'S THREAD — Ian's half, and the lanes' half, from two different places.
 *
 * Ian, 2026-08-16: "I would like to be able to interact with the lanes through
 * the workboard."
 *
 * HIS messages are committed (docs/board-lanes/<lane>.md) because they are
 * instructions and belong in git, permanently and actor-stamped. THEIR replies
 * are NOT: the lanes already post to the devmsg store, and committing that side
 * too would put hundreds of commits a day on main. The relay snapshots them to
 * a JSON file the way keeper already snapshots lane states for the light rail —
 * an established pattern on this box, not a new one.
 *
 * The web user cannot read the devmsg database itself (it is `devmsg`-group,
 * and that group has WRITE — putting the whole WordPress stack in it would let
 * any PHP on the site send messages as ubuntu). So the board reads the
 * snapshot, and where there is no snapshot it says so rather than implying a
 * quiet lane.
 */
$LGB_THREADS = getenv('LGB_THREADS') ?: '/home/ubuntu/.board-threads.json';

/** @return array{sent:array<int,array{when:string,who:string,text:string}>} */
function lgb_lane_sent(string $backlogPath, string $lane): array
{
    $f = lgb_board_dir($backlogPath, 'board-lanes') . '/' . $lane . '.md';
    if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $lane) || !is_readable($f)) { return ['sent' => []]; }

    $out = []; $cur = null; $buf = [];
    $flush = static function () use (&$out, &$cur, &$buf): void {
        if ($cur !== null) {
            $cur['text'] = trim(implode("\n", array_map(
                static fn (string $l): string => ltrim($l, '> '), $buf)));
            $out[] = $cur;
        }
    };
    foreach (explode("\n", (string) file_get_contents($f)) as $line) {
        if (str_starts_with($line, '### ')) {
            $flush(); $cur = null; $buf = [];
            $head = trim(substr($line, 4));
            if (preg_match('/^(\S+ \S+)\s*—\s*(.+)$/u', $head, $m)) {
                $cur = ['when' => $m[1], 'who' => trim($m[2]), 'text' => ''];
            }
            continue;
        }
        if ($cur !== null && str_starts_with($line, '>')) { $buf[] = $line; }
    }
    $flush();
    return ['sent' => $out];
}

/**
 * @return array{ok:bool,why:?string,replies:array<int,array{when:string,text:string}>,
 *               delivery:?array{ok:bool,why:string,when:string}}
 */
function lgb_lane_replies(string $path, string $lane): array
{
    if (!is_readable($path)) {
        return ['ok' => false, 'why' => 'the relay has not written a snapshot yet', 'replies' => [], 'delivery' => null];
    }
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw)) {
        return ['ok' => false, 'why' => 'the thread snapshot is not valid JSON', 'replies' => [], 'delivery' => null];
    }
    $l = $raw['lanes'][$lane] ?? null;
    return ['ok' => true, 'why' => null,
            'replies'  => is_array($l['replies'] ?? null) ? $l['replies'] : [],
            // A FAILED DELIVERY MUST BE VISIBLE. lane-say exiting non-zero means
            // a lane did not hear him; a thread that looked sent anyway would be
            // the worst lie this page could tell.
            'delivery' => is_array($l['delivery'] ?? null) ? $l['delivery'] : null];
}

$backlog  = lgb_parse_backlog($BACKLOG);
$history  = lgb_parse_history($BACKLOG);
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
    --line:#8a8478; --line-soft:#efeae0; --accent:#b9450b; --accent-soft:#f4d8c4;
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
  .lane__watch{margin-left:auto;font-size:.72rem;color:var(--accent,#6b7d4f);text-decoration:none;border:1px solid currentColor;border-radius:9px;padding:0 .5em;opacity:.75}
  .lane__watch:hover{opacity:1}
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

  /* COPY-FOR-CHAT. Ian: "it should have a copy and paste section for me to
     bring back here into vs" — a block he can paste into the keeper chat and
     answer in place. Render-only; the board writes nothing. */
  .cpy{border:0;background:#fff;color:var(--ink-soft);border:1px solid var(--line);border-radius:7px;
       font-size:.72rem;font-weight:600;padding:4px 9px;cursor:pointer;white-space:nowrap;flex:none}
  .cpy:hover{border-color:var(--accent);color:var(--accent)}
  .cpy--done{border-color:var(--good);color:var(--good)}
  .cpy--big{font-size:.78rem;padding:6px 12px}
  .cpybox{margin:12px 0 0;background:#fbf8f2;border:1px solid var(--line);border-radius:9px;padding:11px 13px}
  .cpybox__h{display:flex;justify-content:space-between;align-items:center;gap:9px;margin:0 0 8px}
  .cpybox__t{font-size:.72rem;letter-spacing:.07em;text-transform:uppercase;color:var(--ink-mute)}
  .cpybox pre{margin:0;white-space:pre-wrap;word-wrap:break-word;
              font:12.5px/1.6 ui-monospace,Menlo,monospace;color:var(--ink-soft)}

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

/* ===== DARK PALETTE — work board (dark-anon-sweep, Ian 2026-08-16) ===== */
html[data-lguser-theme="dark"]{
  --bg:#15171a; --panel:#1b1e21; --ink:#e5e7e1; --ink-soft:#cdd0ca; --ink-mute:#a8ada6;
  --line:#2c312d; --line-soft:#242826; --accent:#e8a07a; --accent-soft:#3a2a20;
  --good:#b6c79a; --warn:#e8c073; --blocked:#f0937a;
  --chrome:#0f1113; --chrome-ink:#e8e2d3; --chrome-mute:#b3ab9b;
}
html[data-lguser-theme="dark"] .rail{background:#202426}
html[data-lguser-theme="dark"] .bar{background:#2c312d}
html[data-lguser-theme="dark"] .absent{background:#2e211c}
html[data-lguser-theme="dark"] .P3 .band__dot{background:#4a4f4a}
html[data-lguser-theme="dark"] .desk{background:#1e2124}
html[data-lguser-theme="dark"] .rail{background:#202426}
html[data-lguser-theme="dark"] .bar{background:#2c312d}
html[data-lguser-theme="dark"] .absent{background:#2e211c}
html[data-lguser-theme="dark"] .P3 .band__dot{background:#4a4f4a}
html[data-lguser-theme="dark"] .desk{background:#1e2124}
html[data-lguser-theme="dark"] .desk__opt{background:#242826}
html[data-lguser-theme="dark"] .desk--empty{background:#243024}
html[data-lguser-theme="dark"] .cpy{background:#1b1e21}
html[data-lguser-theme="dark"] .cpybox{background:#202426}
html[data-lguser-theme="dark"] .proj{background:#1b1e21}
html[data-lguser-theme="dark"] .proj__h{background:#202426}
html[data-lguser-theme="dark"] .proj__h:hover{background:#242826}
html[data-lguser-theme="dark"] .proj--unsorted{background:#1e2124}
html[data-lguser-theme="dark"] .proj--unsorted .proj__h{background:#2e2a1f}
html[data-lguser-theme="dark"] .w3{background:#4a4f4a}
html[data-lguser-theme="dark"] .w9{background:#4a4f4a}
html[data-lguser-theme="dark"] .row{background:#1b1e21}
html[data-lguser-theme="dark"] .bdg--look{background:#2e2a1f}
html[data-lguser-theme="dark"] .bdg--blocked{background:#2e211c}
html[data-lguser-theme="dark"] .bdg--unowned{background:#262b30}
html[data-lguser-theme="dark"] .bdg--done{background:#243024}
html[data-lguser-theme="dark"] .row--open:hover{background:#1b1e21}
html[data-lguser-theme="dark"] .modal{background:#1b1e21}
html[data-lguser-theme="dark"] .phase2{background:#202426}
html[data-lguser-theme="dark"] .err{background:#2e211c}
html[data-lguser-theme="dark"] .err{background:#2e211c}/* OPACITY, not colour: .grip and .hist__dc fade the inherited ink. On a light
   page 35% of near-black still reads; on dark, 35% of #e5e7e1 composites to
   #323637 = 1.37:1. No colour override can fix an opacity — raise the opacity. */
html[data-lguser-theme="dark"] .grip{opacity:.8}
html[data-lguser-theme="dark"] .hist__dc{opacity:.85}
/* badge inks re-paired against their NEW dark fills */
html[data-lguser-theme="dark"] .bdg--done{color:#b6c79a}
html[data-lguser-theme="dark"] .bdg--look{color:#e8c073}
html[data-lguser-theme="dark"] .bdg--unowned{color:#cdd0ca}
html[data-lguser-theme="dark"] .bdg--decide{background:#8a3f1d;color:#ffffff}
html[data-lguser-theme="dark"] .cpy{color:var(--ink-soft);border-color:var(--line)}
html[data-lguser-theme="dark"] textarea::placeholder,
html[data-lguser-theme="dark"] input::placeholder{color:#9aa097}
/* The DONE fade is deliberate — completed rows recede, and that meaning is kept.
   But .62 of a muted ink reads 3.66:1 on the dark panel, so the fade is raised
   just enough to clear AA rather than removed. Light is untouched: there the
   same .62 sits on a white panel and already passes. */
html[data-lguser-theme="dark"] .row--done,
html[data-lguser-theme="dark"] .donebox .row{opacity:.82}
/* text/ink pairs that only appear once panels are expanded */
html[data-lguser-theme="dark"] .row__o,
html[data-lguser-theme="dark"] .thrbox__no{color:var(--ink-mute)}
html[data-lguser-theme="dark"] .grip{opacity:.8;color:var(--ink)}
html[data-lguser-theme="dark"] .bdg--unowned{background:#262b30;color:#cdd0ca}
html[data-lguser-theme="dark"] .bdg--done{background:#243024;color:#b6c79a}
html[data-lguser-theme="dark"] .lane__watch{color:#e8a07a}
html[data-lguser-theme="dark"] .thrbox__in{background:#15171a;color:var(--ink);border-color:var(--line)}
html[data-lguser-theme="dark"] .thrbox__in::placeholder{color:#9aa097}
/* .thrbox__no{opacity:.6} — the "no messages yet" note. Same opacity-not-colour
   shape as .grip: .6 of a muted ink reads 3.4:1 on the dark panel. Raised in
   dark only; light keeps its .6 and already passes there. */
html[data-lguser-theme="dark"] .thrbox__no{opacity:.85}
/* Card border 2c312d on the page 15171a measures 1.35:1 — under the 3:1 non-text
   bar (WCAG 1.4.11, "how you find the box"). Found by the paired-token swatch
   sheet, NOT by any page sweep: the contrast probe flags borders on FORM FIELDS,
   not on cards, so no amount of re-running gate 36 or the sweep would have
   produced it. Repointed to the same #767c76 the search-wrapper family already
   uses — 3.79:1 on the panel, an established value rather than a new one. */
html[data-lguser-theme="dark"]{--line:#767c76}
</style>
</head>
<body>

<div class="app">
  <div class="app__t">Work board</div>
  <div class="app__r">
    <?= (int) $totalItems ?> items ·
    <?= (int) $needsYou ?> want you
  </div>
</div>
<?php if ($LGB_AHEAD): ?>
  <!-- Said out loud rather than smoothed over: the board is showing main
       because the serving copy has not caught up yet. A page that quietly
       reads a different file than it claims to is how a stale board gets
       trusted. -->
  <div class="ahead">Showing the latest board from <b>main</b> — the copy this
    site serves is behind and catches up on the next deploy.</div>
<?php endif; ?>

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
          <?php
            $lname = (string) ($lane['name'] ?? '?');
            $ok    = (bool) preg_match('/^[a-z][a-z0-9-]{1,30}$/', $lname);
            $sent  = $ok ? lgb_lane_sent($BACKLOG, $lname)['sent'] : [];
            $rep   = $ok ? lgb_lane_replies($LGB_THREADS, $lname) : ['ok' => false, 'why' => 'unnamed seat', 'replies' => [], 'delivery' => null];
            $nmsg  = count($sent) + count($rep['replies']);
          ?>
          <details class="lane lane--thr">
            <summary class="lane__s2">
              <span class="lane__d d--<?= lgb_h($cls) ?>"></span>
              <span class="lane__n"><?= lgb_h($lname) ?></span>
              <span class="lane__s"><?= lgb_h($st) ?></span>
              <?php if ($nmsg > 0): ?><span class="lane__b"><?= (int) $nmsg ?></span><?php endif; ?>
            </summary>
            <div class="thrbox" data-lane="<?= lgb_h($lname) ?>">
              <?php if ($ok): ?>
                <a class="lane__watch" target="_blank" rel="noopener"
                   href="/lane-view/?arg=<?= lgb_h(rawurlencode($lname)) ?>">watch this seat's terminal (read-only)</a>
              <?php endif; ?>
              <?php if (!$ok): ?>
                <div class="thrbox__no">This seat has no usable name, so there is nothing to address.</div>
              <?php else: ?>
                <?php if ($rep['delivery'] !== null && empty($rep['delivery']['ok'])): ?>
                  <!-- lane-say exiting non-zero means a lane did not hear him.
                       Never let that read as sent. -->
                  <div class="thrbox__bad">NOT DELIVERED — <?= lgb_h((string) ($rep['delivery']['why'] ?? 'no reason given')) ?></div>
                <?php endif; ?>
                <div class="thrbox__log">
                  <?php foreach ($sent as $m): ?>
                    <div class="msg msg--out"><span class="msg__w"><?= lgb_h($m['when']) ?></span><?= lgb_h($m['text']) ?></div>
                  <?php endforeach; ?>
                  <?php foreach ($rep['replies'] as $m): ?>
                    <div class="msg msg--in"><span class="msg__w"><?= lgb_h((string) ($m['when'] ?? '')) ?></span><?= lgb_h((string) ($m['text'] ?? '')) ?></div>
                  <?php endforeach; ?>
                  <?php if ($sent === [] && $rep['replies'] === []): ?>
                    <div class="thrbox__no"><?= $rep['ok'] ? 'Nothing yet.' : lgb_h((string) $rep['why']) . '.' ?></div>
                  <?php endif; ?>
                </div>
                <textarea class="thrbox__in" rows="2" placeholder="Message <?= lgb_h($lname) ?>…"></textarea>
                <button class="thrbox__go">Send</button>
                <div class="thrbox__say" hidden></div>
              <?php endif; ?>
            </div>
          </details>
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
            // THE KEY IS ASSIGNED HERE, ONCE, AND NOWHERE ELSE.
            //
            // Ian hit this on the live board: he clicked a row in one project
            // and got a different item's modal. Cause — the key was a COUNTER
            // incremented in two separate loops: the payload was built walking
            // the file's P-bands, the rows were rendered walking the sorted
            // project accordions. Same name "r7", different item in each. Two
            // orders, one counter, and every modal off by however far the two
            // walks had diverged.
            //
            // Deriving it once and carrying it on the item makes the two
            // physically incapable of disagreeing, which is the only fix worth
            // having: re-syncing two counters would just be the same bug
            // waiting for the next sort order to change.
            $it['_key'] = 'r' . (++$GLOBALS['LGB_ROW']);
            // The project key travels with the item for the SAME reason the row
            // key does: a drag has to tell the server which group it reordered,
            // and re-deriving that at render time is how two walks drift apart.
            $it['_proj'] = ($k === '_unsorted') ? '' : $k;
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
        $rowKey = (string) ($it['_key'] ?? ''); ?>
        <div class="row row--open<?= $it['done'] ? ' row--done' : '' ?> w<?= (int) ($it['_w'] ?? 9) ?>"
             data-item="<?= lgb_h($rowKey) ?>"
             data-id="<?= lgb_h((string) $it['id']) ?>"
             data-project="<?= lgb_h((string) ($it['_proj'] ?? '')) ?>"
             <?= $it['done'] ? '' : 'draggable="true"' ?>
             tabindex="0" role="button" title="Open this item">
          <?php if (!$it['done']): ?><span class="grip" aria-hidden="true" title="Drag to rank">⠿</span><?php endif; ?>
          <span class="row__t"><?= lgb_h($it['title']) ?></span>
          <span class="row__b">
            <?php if (!$it['done'] && $it['needsIan']): ?><span class="bdg bdg--decide">needs you</span><?php endif; ?>
            <?php if (!$it['done'] && $it['look']): ?><span class="bdg bdg--look">look</span><?php endif; ?>
            <?php if (!$it['done'] && $it['blocked']): ?><span class="bdg bdg--blocked">blocking</span><?php endif; ?>
            <?php if (!$it['done'] && $it['unowned']): ?><span class="bdg bdg--unowned">unowned</span><?php endif; ?>
            <?php if ($it['done']): ?><span class="bdg bdg--done">done</span><?php endif; ?>
          </span>
          <?php if (!$it['done'] && ($it['needsIan'] || $it['look'])): ?>
            <button class="cpy" data-copy="<?= lgb_h($rowKey) ?>"
                    title="Copy this for the chat">Copy for chat</button>
          <?php endif; ?>
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

  <?php
  /**
   * WHAT SHIPPED — the archive, finally on the board.
   *
   * Ian: "the board doesn't have all of the backlog." This is the half that was
   * missing. It is not a nice-to-have: a board that shows only what is LEFT
   * makes a month of finished work look like nothing happened, and the question
   * it answers — "what have you actually done for me lately" — is one he asks.
   *
   * Grouped by date, newest first, collapsed by default so it never competes
   * with the open work above it. Counts are counted, never typed.
   */
  $byDate = [];
  foreach ($history as $h) { $byDate[$h['date']][] = $h; }
  ?>
  <?php if ($history !== []): ?>
    <details class="hist">
      <summary class="hist__h">
        <span class="hist__t">What shipped</span>
        <span class="hist__c"><?= count($history) ?> items · <?= count($byDate) ?> days</span>
      </summary>
      <?php foreach ($byDate as $date => $items): ?>
        <div class="hist__d">
          <div class="hist__dh"><?= lgb_h($date) ?><span class="hist__dc"><?= count($items) ?></span></div>
          <?php foreach ($items as $h): ?>
            <details class="hist__i">
              <summary><?= lgb_h($h['title']) ?></summary>
              <pre class="hist__b"><?= lgb_h($h['body']) ?></pre>
            </details>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </details>
  <?php else: ?>
    <!-- Said rather than drawn as an empty box: a missing source must never
         render as a comforting zero. -->
    <div class="hist hist--none">No shipped archive found in docs/BACKLOG.md.</div>
  <?php endif; ?>

  <div class="foot">
    <b>Reading is derived; writing is fenced.</b> Everything here is read from
    <code>docs/BACKLOG.md</code> and the sentinel stamp at render time. Nothing on
    this page is typed or kept in sync by hand: the order is the file's own
    PRIORITY INDEX, and the badges are derived from each item's text
    (<b>needs you</b> = the entry says it is awaiting you; <b>look</b> = a mock
    exists; <b>blocking</b>, <b>unowned</b>, <b>done</b> = the file's own markers).
    Deliberately no invented counts — the index does not enumerate questions, so
    a number here would be typing dressed as deriving.
    <b>Drag a row</b> to rank it inside its project, and open an item to add a
    note or record a decision. This page cannot write to the site it is served
    from — every change is handed to the committer service, which alone holds
    the keys, and it will only ever touch the priority order, an item's notes,
    and an item's media list. If it refuses, the row snaps back and this page
    says so; nothing here reports a save the file did not take. Still to come:
    the keeper chat and images in the thread.
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
        <!-- COPY FOR CHAT. Ian: "it should have a copy and paste section for me
             to bring back here into vs." Preformatted so he can answer in place
             and paste the whole thing back. Render-only — no write path. -->
        <div class="cpybox">
          <div class="cpybox__h">
            <span class="cpybox__t">Copy this into the chat and answer it there</span>
            <button class="cpy cpy--big" id="lgb-copy">Copy</button>
          </div>
          <pre id="lgb-copytext"></pre>
        </div>
        <!-- PHASE 2 — the write surfaces. Every one of these ends at the
             committer service; none of them touches git from this page. -->
        <div class="w2" id="lgb-decbox" hidden>
          <div class="w2__h">Your decision</div>
          <div class="w2__opts" id="lgb-decopts"></div>
          <!-- "Something else…" is a FIRST-CLASS ANSWER, not an escape hatch:
               Ian caught its absence on round 4, and what he types is recorded
               as his ruling in his own words rather than as a note on whichever
               button was nearest. -->
          <textarea id="lgb-decother" rows="2" placeholder="Something else… (your own words)"></textarea>
          <button class="w2__go" id="lgb-decsend">Record my decision</button>
        </div>

        <div class="w2">
          <div class="w2__h">Thread <span class="w2__c" id="lgb-threadc"></span></div>
          <pre class="w2__thread" id="lgb-thread"></pre>
          <textarea id="lgb-note" rows="2" placeholder="Add a note to this item…"></textarea>
          <button class="w2__go" id="lgb-notesend">Add note</button>
        </div>

        <!-- The write layer's only voice. It says what the STORE did, never
             what the page hoped: a commit sha on success, the committer's own
             refusal text on failure. -->
        <div class="w2__say" id="lgb-say" hidden></div>
      </div>
    </div>
  </div>

  <!-- Detail bodies, embedded rather than fetched: it keeps the page free of
       query input (which the gate asserts, and which is a real property for a
       surface with no auth of its own beyond the dev gate). -->
  <style>
    .grip{cursor:grab;opacity:.35;font-size:13px;letter-spacing:-2px;padding-right:2px}
    .row[draggable="true"]:hover .grip{opacity:.8}
    .row.drag{opacity:.4}
    .row.over{box-shadow:inset 0 2px 0 var(--acc,#4a9eff)}
    .row.pending{opacity:.6}
    .w2{margin-top:14px;padding-top:12px;border-top:1px solid rgba(128,128,128,.25)}
    .w2__h{font-weight:600;font-size:13px;margin-bottom:6px}
    .w2__c{font-weight:400;opacity:.6}
    .w2__thread{white-space:pre-wrap;max-height:210px;overflow:auto;font-size:12px;
                background:rgba(128,128,128,.08);padding:8px;border-radius:6px;margin:0 0 8px}
    .w2 textarea{width:100%;box-sizing:border-box;font:inherit;font-size:13px;padding:6px;
                 border-radius:6px;border:1px solid #8a8478;background:transparent;color:inherit}
    .w2__opts{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
    .w2__opt{font:inherit;font-size:12px;padding:5px 10px;border-radius:999px;cursor:pointer;
             border:1px solid rgba(128,128,128,.45);background:transparent;color:inherit}
    .w2__opt[aria-pressed="true"]{background:rgba(74,158,255,.18);border-color:#4a9eff}
    .w2__go{margin-top:8px;font:inherit;font-size:12px;padding:6px 12px;border-radius:6px;cursor:pointer;
            border:1px solid rgba(128,128,128,.45);background:transparent;color:inherit}
    .w2__go[disabled]{opacity:.5;cursor:default}
    .w2__say{margin-top:10px;font-size:12px;padding:8px;border-radius:6px}
    .w2__say--ok{background:rgba(60,160,90,.15)}
    .w2__say--no{background:rgba(200,70,70,.16)}
    .ahead{font-size:12px;padding:6px 10px;border-radius:6px;margin:8px 0;
           background:rgba(74,158,255,.14)}
    .hist{margin:18px 0 0;border-top:1px solid rgba(128,128,128,.25);padding-top:10px}
    .hist__h{cursor:pointer;display:flex;gap:10px;align-items:baseline;font-weight:600;font-size:13px}
    .hist__c{font-weight:400;opacity:.6;font-size:12px}
    .hist__d{margin:10px 0 0 4px}
    .hist__dh{font-size:12px;font-weight:600;opacity:.75;display:flex;gap:8px;align-items:baseline}
    .hist__dc{font-weight:400;opacity:.6}
    .hist__i{margin:3px 0 3px 10px;font-size:13px}
    .hist__i summary{cursor:pointer;padding:2px 0}
    .hist__b{white-space:pre-wrap;font-size:12px;margin:4px 0 8px;padding:8px;border-radius:6px;
             background:rgba(128,128,128,.08);max-height:340px;overflow:auto}
    .hist--none{font-size:12px;opacity:.7}
    .lane--thr>summary{list-style:none;cursor:pointer}
    .lane--thr>summary::-webkit-details-marker{display:none}
    .lane__s2{display:flex;gap:8px;align-items:center}
    .lane__b{margin-left:auto;font-size:11px;padding:1px 6px;border-radius:999px;background:rgba(74,158,255,.22)}
    .thrbox{margin:6px 0 10px 16px;font-size:12px}
    .thrbox__log{max-height:200px;overflow:auto;margin-bottom:6px}
    .msg{padding:4px 6px;border-radius:6px;margin:3px 0;white-space:pre-wrap}
    .msg--out{background:rgba(74,158,255,.16)}
    .msg--in{background:rgba(128,128,128,.12)}
    .msg__w{display:block;font-size:10px;opacity:.55}
    .thrbox__in{width:100%;box-sizing:border-box;font:inherit;font-size:12px;padding:5px;border-radius:6px;
                border:1px solid #8a8478;background:transparent;color:inherit}
    .thrbox__go{margin-top:5px;font:inherit;font-size:11px;padding:4px 10px;border-radius:6px;cursor:pointer;
                border:1px solid rgba(128,128,128,.45);background:transparent;color:inherit}
    .thrbox__no{opacity:.6}
    .thrbox__bad{background:rgba(200,70,70,.18);padding:5px 7px;border-radius:6px;margin-bottom:5px}
    .thrbox__say{margin-top:5px;padding:5px 7px;border-radius:6px}
  </style>
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
    // Built from the SAME grouped items the rows come from, using the SAME
    // stored key — so a row and its modal cannot drift apart again.
    $payload = [];
    foreach ($groups as $g) {
        foreach ([ $g['open'], $g['done'] ] as $bucket) {
            foreach ($bucket as $it) {
                $d = $details[$it['id']] ?? null;
                $extras = lgb_item_extras($BACKLOG, (string) $it['id']);
                $payload[(string) $it['_key']] = [
                    'heading' => $it['id'] . ' · ' . $it['title'],
                    'line'    => $it['raw'],
                    'band'    => $g['name'],
                    'owner'   => $it['owner'],
                    'detail'  => $d['body']    ?? '',
                    'dhead'   => $d['heading'] ?? '',
                    // Phase 2: what a write needs. The id is carried explicitly
                    // rather than parsed back out of the heading.
                    'id'      => (string) $it['id'],
                    'proj'    => (string) ($it['_proj'] ?? ''),
                    'needs'   => (bool) (!$it['done'] && $it['needsIan']),
                    'thread'  => $extras['thread'],
                    'options' => $extras['options'],
                ];
            }
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
      document.getElementById('lgb-copytext').textContent = blockFor(id);
      meta.innerHTML = '';
      row.querySelectorAll('.bdg').forEach(function (b) { meta.appendChild(b.cloneNode(true)); });
      fillWrite(d);
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
    /**
     * The paste block. Deliberately plain text with a blank answer line: the
     * point is that he pastes it into the chat, types under it, and keeper has
     * both the question and the answer in one message — no hunting for which
     * item "yes" referred to.
     */
    function blockFor(id) {
      var d = data[id]; if (!d) return '';
      var out = 'BOARD ITEM ' + (d.heading || id) + '\n';
      if (d.band)  { out += 'Project: ' + d.band + '\n'; }
      if (d.owner) { out += 'Team: ' + d.owner + '\n'; }
      out += '\n' + (d.line || '').trim() + '\n';
      if (d.detail) {
        var det = d.detail.trim();
        out += '\n' + (det.length > 900 ? det.slice(0, 900) + '\n…(trimmed)' : det) + '\n';
      }
      out += '\nMy answer:\n';
      return out;
    }

    function copy(text, btn) {
      var done = function () {
        if (!btn) return;
        var was = btn.textContent;
        btn.textContent = 'Copied'; btn.classList.add('cpy--done');
        setTimeout(function () { btn.textContent = was; btn.classList.remove('cpy--done'); }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { fallback(text); done(); });
      } else { fallback(text); done(); }
    }
    // Older browsers, and any context where the clipboard API is blocked —
    // a copy button that silently does nothing would be worse than none.
    function fallback(text) {
      var t = document.createElement('textarea');
      t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
      document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(t);
    }

    document.querySelectorAll('.cpy[data-copy]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        e.stopPropagation();          // copying must not also open the modal
        copy(blockFor(b.getAttribute('data-copy')), b);
      });
    });
    document.getElementById('lgb-copy').addEventListener('click', function () {
      copy(document.getElementById('lgb-copytext').textContent, this);
    });
    /* ------------------------------------------------------------------ *
     * PHASE 2 — the write layer, page side.
     *
     * ONE RULE RUNS THROUGH ALL OF IT: nothing here reports success on its own
     * authority. Every message the user sees comes from what the committer
     * ANSWERED — a commit sha, or its own refusal text. The screen is not
     * allowed to agree with the gesture; it may only agree with the store.
     * ------------------------------------------------------------------ */
    var cur = null;                 // the item the modal is showing
    var say = document.getElementById('lgb-say');

    function tell(ok, text) {
      say.hidden = false;
      say.className = 'w2__say ' + (ok ? 'w2__say--ok' : 'w2__say--no');
      say.textContent = text;
    }

    function post(payload) {
      return fetch(location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-LGB-Write': '1' },
        body: JSON.stringify(payload)
      }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'the board could not read the reply' }; }); })
        .catch(function () { return { ok: false, error: 'the board could not reach the write service' }; });
    }

    /** The committer's answer, in Ian's language, success or refusal alike. */
    function landed(res) {
      if (res && res.ok && res.commit) { return 'Saved — commit ' + res.commit + '.'; }
      if (res && res.ok)               { return 'Saved.'; }
      var why = (res && (res.why || res.error)) || 'it did not say why';
      return 'NOT saved — ' + why;
    }

    function fillWrite(d) {
      cur = d;
      say.hidden = true;
      var thread = document.getElementById('lgb-thread'),
          cnt    = document.getElementById('lgb-threadc');
      thread.textContent = d.thread || '';
      thread.style.display = d.thread ? '' : 'none';
      cnt.textContent = d.thread ? '' : '— nothing yet';
      document.getElementById('lgb-note').value = '';

      // Decisions appear ONLY where a question was actually asked. No file, no
      // buttons — the board never invents an option for him to press.
      var box = document.getElementById('lgb-decbox'),
          opts = document.getElementById('lgb-decopts');
      opts.innerHTML = '';
      document.getElementById('lgb-decother').value = '';
      if (d.options && d.options.length) {
        box.hidden = false;
        d.options.forEach(function (o) {
          var b = document.createElement('button');
          b.className = 'w2__opt'; b.type = 'button';
          b.textContent = o; b.setAttribute('aria-pressed', 'false');
          b.addEventListener('click', function () {
            opts.querySelectorAll('.w2__opt').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
            b.setAttribute('aria-pressed', 'true');
          });
          opts.appendChild(b);
        });
      } else { box.hidden = true; }
    }

    function busy(btn, on) { btn.disabled = on; btn.textContent = on ? 'Saving…' : btn.dataset.was; }

    document.getElementById('lgb-notesend').dataset.was = 'Add note';
    document.getElementById('lgb-notesend').addEventListener('click', function () {
      var t = document.getElementById('lgb-note').value.trim();
      if (!cur || !t) { return; }
      var btn = this; busy(btn, true);
      post({ action: 'note', id: cur.id, text: t }).then(function (res) {
        busy(btn, false);
        tell(!!(res && res.ok), landed(res));
        if (res && res.ok) {
          // Show it in the thread immediately — but only AFTER the store said
          // yes, which is the whole difference between this and an optimistic
          // update that survives a refusal.
          var th = document.getElementById('lgb-thread');
          th.style.display = '';
          th.textContent = (th.textContent || '') + '\n### just now — you\n\n> ' + t + '\n';
          document.getElementById('lgb-note').value = '';
        }
      });
    });

    document.getElementById('lgb-decsend').dataset.was = 'Record my decision';
    document.getElementById('lgb-decsend').addEventListener('click', function () {
      if (!cur) { return; }
      var picked = document.querySelector('#lgb-decopts .w2__opt[aria-pressed="true"]'),
          words  = document.getElementById('lgb-decother').value.trim();
      if (!picked && !words) { tell(false, 'Pick an option, or write what you want instead.'); return; }
      var btn = this; busy(btn, true);
      post({ action: 'decision', id: cur.id,
             option: picked ? picked.textContent : 'Other', text: words }).then(function (res) {
        busy(btn, false);
        tell(!!(res && res.ok), landed(res));
      });
    });

    /* ---- DRAG TO RANK, within one project ---------------------------------
     *
     * The snap-back is the point. The card moves under his finger before the
     * commit lands, so if the commit is refused and the card SITS there looking
     * applied, the page has told him something the file does not say. So the
     * order is photographed before the drag, and a refusal puts it back exactly
     * — and says so.
     */
    var dragging = null, before = null;

    function rowsIn(box) { return Array.prototype.filter.call(box.children, function (n) {
      return n.classList && n.classList.contains('row'); }); }

    document.querySelectorAll('.proj__b').forEach(function (box) {
      box.addEventListener('dragstart', function (e) {
        var row = e.target.closest ? e.target.closest('.row[draggable="true"]') : null;
        if (!row || !box.contains(row)) { return; }
        dragging = row;
        before = rowsIn(box).slice();          // the photograph
        row.classList.add('drag');
        try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', row.dataset.id); } catch (x) {}
      });

      box.addEventListener('dragover', function (e) {
        if (!dragging || !box.contains(dragging)) { return; }
        var over = e.target.closest ? e.target.closest('.row') : null;
        if (!over || over === dragging || !box.contains(over)) { return; }
        // Same project only — a drag must never silently re-file an item.
        if (over.dataset.project !== dragging.dataset.project) { return; }
        e.preventDefault();
        var rs = rowsIn(box);
        box.insertBefore(dragging, rs.indexOf(over) > rs.indexOf(dragging) ? over.nextSibling : over);
      });

      box.addEventListener('drop', function (e) { e.preventDefault(); });

      box.addEventListener('dragend', function () {
        if (!dragging || !box.contains(dragging)) { dragging = null; return; }
        var row = dragging, snapshot = before;
        dragging = null; before = null;
        row.classList.remove('drag');

        var now = rowsIn(box);
        if (snapshot && now.length === snapshot.length && now.every(function (n, i) { return n === snapshot[i]; })) {
          return;                              // put back where it started
        }
        var order = now.map(function (n) { return n.dataset.id; });
        row.classList.add('pending');
        post({ action: 'reorder', project: row.dataset.project || '', order: order }).then(function (res) {
          row.classList.remove('pending');
          if (res && res.ok) {
            flash(box, true, landed(res));
          } else {
            snapshot.forEach(function (n) { box.appendChild(n); });   // SNAP BACK
            flash(box, false, landed(res));
          }
        });
      });
    });

    /** The board's own voice for a drag — the modal is not open during one. */
    function flash(box, ok, text) {
      var n = box.querySelector('.w2__say--drag');
      if (!n) {
        n = document.createElement('div');
        n.className = 'w2__say w2__say--drag';
        box.insertBefore(n, box.firstChild);
      }
      n.className = 'w2__say w2__say--drag ' + (ok ? 'w2__say--ok' : 'w2__say--no');
      n.textContent = text;
      if (ok) { setTimeout(function () { if (n.parentNode) { n.parentNode.removeChild(n); } }, 4000); }
    }

    /* ---- MESSAGE A LANE ---------------------------------------------------
     *
     * Same rule as every other write on this page: the message is reported as
     * sent only when the STORE says so. And "committed" is not "delivered" —
     * the commit is the relay's inbox, not the lane's ear — so the wording says
     * queued, and the thread shows NOT DELIVERED if the relay comes back unable
     * to reach the seat. A board that said "sent" when a lane was down would be
     * the same lie as a refused save reading as preserved.
     */
    document.querySelectorAll('.thrbox').forEach(function (box) {
      var btn = box.querySelector('.thrbox__go'),
          ta  = box.querySelector('.thrbox__in'),
          out = box.querySelector('.thrbox__say');
      if (!btn || !ta) { return; }
      btn.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();     // must not toggle the row
        var text = ta.value.trim();
        if (!text) { return; }
        btn.disabled = true; btn.textContent = 'Sending…';
        post({ action: 'lane_message', lane: box.dataset.lane, text: text }).then(function (res) {
          btn.disabled = false; btn.textContent = 'Send';
          out.hidden = false;
          if (res && res.ok) {
            out.className = 'thrbox__say w2__say--ok';
            out.textContent = 'Queued for ' + box.dataset.lane + ' — commit ' + (res.commit || '?')
                            + '. It reaches the seat on the relay\'s next pass.';
            var log = box.querySelector('.thrbox__log'), d = document.createElement('div');
            d.className = 'msg msg--out'; d.textContent = text;
            log.appendChild(d); log.scrollTop = log.scrollHeight;
            ta.value = '';
          } else {
            out.className = 'thrbox__say w2__say--no';
            out.textContent = 'NOT queued — ' + ((res && (res.why || res.error)) || 'it did not say why');
          }
        });
      });
      // Typing in the box must not collapse the row.
      ta.addEventListener('click', function (e) { e.stopPropagation(); });
    });

    document.getElementById('lgb-close').addEventListener('click', close);
    scrim.addEventListener('click', function (e) { if (e.target === scrim) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  })();
  </script>
</div>
</body>
</html>
