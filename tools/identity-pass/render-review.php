<?php
declare(strict_types=1);

/**
 * REVIEW-PAGE renderer for the identity-cleanup dry-run (keeper task 18:00 7/26).
 *
 * Reads the TSV emitted by profile-app/bin/backfill-patreon-handles-dryrun.php and
 * renders ONE self-contained static HTML page (inline CSS/JS, no CDN, no fonts,
 * no network) for Ian to skim every proposed decision before gating the apply.
 *
 * Rows are grouped by PRIMARY decision, each row exactly once, in review order:
 *   splits      name_source business-prune (the bulk: current -> proposed + biz)
 *   decode      flag decode-only          (mechanical entity cure, visually calm)
 *   needs-ian   flag anchor-too-sparse / anchor-mismatch (NO proposal — eyes only)
 *   handle      handle change only
 *   capped      name trimmed to the 40-cap
 *
 * The page is a generated ARTIFACT, not hand-authored product: this tool is the
 * tracked source (monorepo law). Output goes behind the dev2 cookie gate — member
 * names never leave the box. Dark mode per the standing merge gate: every color
 * is a token, both themes defined, no hardcoded white.
 *
 *   php render-review.php --tsv=/path/report.tsv --out=/path/review.html
 *
 * This tool reads the TSV and writes the HTML file. Nothing else, ever.
 */

$TSV = null; $OUT = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--tsv=')) $TSV = substr($a, 6);
    if (str_starts_with($a, '--out=')) $OUT = substr($a, 6);
}
if (!$TSV || !$OUT) { fwrite(STDERR, "usage: php render-review.php --tsv=IN.tsv --out=OUT.html\n"); exit(1); }
$fh = fopen($TSV, 'r');
if ($fh === false) { fwrite(STDERR, "cannot read $TSV\n"); exit(1); }

$header = fgetcsv($fh, 0, "\t", '"', '\\');
if (!$header || !in_array('user_id', $header, true)) { fwrite(STDERR, "not a generator TSV (no user_id column)\n"); exit(1); }
$rows = [];
while (($r = fgetcsv($fh, 0, "\t", '"', '\\')) !== false) {
    if (count($r) !== count($header)) continue;
    $rows[] = array_combine($header, $r);
}
fclose($fh);
if (!$rows) { fwrite(STDERR, "no rows in $TSV\n"); exit(1); }

$h = fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$has = fn(array $r, string $flag): bool => in_array($flag, array_filter(explode(',', $r['flags'] ?? '')), true);

// ── group by primary decision (priority order; every row exactly once) ───────
$groups = ['splits' => [], 'decode' => [], 'sparse' => [], 'mismatch' => [], 'handle' => [], 'capped' => [], 'other' => []];
$flagTotals = [];
foreach ($rows as $r) {
    foreach (array_filter(explode(',', $r['flags'] ?? '')) as $f) $flagTotals[$f] = ($flagTotals[$f] ?? 0) + 1;
    if (($r['name_source'] ?? '') === 'business-prune')  { $groups['splits'][] = $r; continue; }
    if ($has($r, 'decode-only'))                          { $groups['decode'][] = $r; continue; }
    if ($has($r, 'anchor-too-sparse'))                    { $groups['sparse'][] = $r; continue; }
    if ($has($r, 'anchor-mismatch'))                      { $groups['mismatch'][] = $r; continue; }
    if (($r['name_proposed'] ?? '') === '' && ($r['slug_proposed'] ?? '') !== '') { $groups['handle'][] = $r; continue; }
    if ($has($r, 'name-capped'))                          { $groups['capped'][] = $r; continue; }
    $groups['other'][] = $r;
}
$lowConfTotal = count(array_filter($rows, fn($r) => ($r['low_confidence'] ?? '') === 'YES'));

// ── per-row markup ───────────────────────────────────────────────────────────
$chip = fn(string $t, string $cls = '') => '<span class="chip ' . $cls . '">' . $h($t) . '</span>';
$rowHtml = function (array $r, string $kind) use ($h, $chip, $has): string {
    $id    = 'u' . $r['user_id'];
    $lc    = ($r['low_confidence'] ?? '') === 'YES';
    $flags = array_filter(explode(',', $r['flags'] ?? ''));
    $search = strtolower(implode(' ', [$id, $r['user_id'], $r['name_now'], $r['name_proposed'],
        $r['biz_captured'], $r['biz_existing'], $r['slug_now'], $r['slug_proposed'], $r['flags']]));

    $o  = '<article class="row' . ($lc ? ' lc' : '') . ($kind === 'needs' ? ' needs' : '')
        . '" id="' . $id . '" data-s="' . $h($search) . '" data-lc="' . ($lc ? '1' : '0') . '">';
    $o .= '<div class="rowtop"><a class="cite" href="#' . $id . '">#' . $id . '</a>';
    if ($lc) $o .= '<span class="badge lcbadge">LOW-CONF</span>';
    if ($kind === 'needs') $o .= '<span class="badge nobadge">NO PROPOSAL</span>';
    foreach ($flags as $f) $o .= $chip($f, 'flag');
    $o .= '</div>';

    // name line: current -> proposed (or current only, for eyes-only rows)
    $o .= '<div class="names">';
    $o .= '<span class="now">' . $h($r['name_now']) . '</span>';
    if (($r['name_proposed'] ?? '') !== '') $o .= '<span class="arr" aria-hidden="true">→</span><span class="prop">' . $h($r['name_proposed']) . '</span>';
    $o .= '</div>';

    if (($r['biz_captured'] ?? '') !== '') {
        $o .= '<div class="bizline">→ business: <span class="biz">' . $h($r['biz_captured']) . '</span>';
        if ($has($r, 'biz-col-occupied')) $o .= ' <span class="occ">column already holds: “' . $h($r['biz_existing']) . '”</span>';
        $o .= '</div>';
    }
    if (($r['slug_proposed'] ?? '') !== '') {
        $o .= '<div class="slugline">@' . $h($r['slug_now']) . '<span class="arr" aria-hidden="true">→</span>@' . $h($r['slug_proposed'])
            . ($r['handle_source'] !== '' ? ' <span class="src">(' . $h($r['handle_source']) . ')</span>' : '') . '</div>';
    } elseif (($r['slug_now'] ?? '') !== '') {
        $o .= '<div class="slugline muted">@' . $h($r['slug_now']) . ' (unchanged)</div>';
    }
    return $o . '</article>';
};

// ── sections ─────────────────────────────────────────────────────────────────
$sections = [
    ['splits', 'Name + business splits', $groups['splits'],
     'The Patreon anchor leads the current name; the tail relocates to business_name. Current → proposed, business captured underneath.', ''],
    ['decode', 'Decode-only', $groups['decode'],
     'The stored value carries raw HTML entities (they render literally on the site today). The proposal is the pure decode — no restructuring, handle untouched. Mechanical and safe.', 'calm'],
    ['sparse', 'Needs Ian — anchor too sparse', $groups['sparse'],
     'The Patreon anchor is a SINGLE word (e.g. just “Sam”). Splitting on it would have stolen the surname into business, so the generator refuses: nothing is proposed. Your call, row by row.', 'needs'],
    ['mismatch', 'Needs Ian — anchor mismatch', $groups['mismatch'],
     'The Patreon API name does not lead the profile name, so no split can be anchored: nothing is proposed. Eyes only.', 'needs'],
    ['handle', 'Handle-only', $groups['handle'],
     'The name stays; only the handle changes (junk re-mints and >30 re-trims, derived from the unchanged name).', ''],
    ['capped', 'Name capped at 40', $groups['capped'],
     'Names over the forward 40-cap, trimmed at a word boundary. No split, no business.', ''],
];
if ($groups['other']) $sections[] = ['other', 'Other', $groups['other'], 'Rows that fit no group above — should be empty; if not, tell the lane.', ''];

$navHtml = ''; $bodyHtml = '';
foreach ($sections as [$sid, $title, $g, $blurb, $tone]) {
    if (!$g && $sid === 'other') continue;
    $navHtml  .= '<a href="#' . $sid . '" data-nav="' . $sid . '">' . $h($title) . ' <b>' . count($g) . '</b></a>';
    $bodyHtml .= '<section id="' . $sid . '" class="' . $tone . '"><h2>' . $h($title)
               . ' <span class="count" data-count="' . $sid . '">' . count($g) . '</span></h2>'
               . '<p class="blurb">' . $h($blurb) . '</p>';
    foreach ($g as $r) $bodyHtml .= $rowHtml($r, $tone === 'needs' ? 'needs' : $tone);
    $bodyHtml .= '</section>';
}

$total = count($rows);
$stamp = date('Y-m-d H:i');
$srcName = basename($TSV);
$flagLine = implode(' · ', array_map(fn($k, $v) => "$k $v", array_keys($flagTotals), $flagTotals));

// ── tokens: every color a var, both themes, no hardcoded white (merge gate) ──
$darkTokens = <<<CSS
  --bg: #14161a; --bg-panel: #1c1f25; --bg-row: #21252c; --ink: #d7dae0; --ink-muted: #8b919c;
  --line: #30353e; --accent: #7fb069; --accent-ink: #14161a; --prop: #a3c9f9; --now: #9aa1ac;
  --warn-bg: #3a2f1b; --warn-ink: #e8c574; --need: #c96f6f; --need-bg: #2a1e1e;
  --calm-bg: #1c2420; --chipbg: #2a2f38; --occ: #d99;
CSS;

$html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
. '<meta name="viewport" content="width=device-width, initial-scale=1">'
. '<meta name="robots" content="noindex, nofollow">'
. '<title>Identity cleanup — review (' . $total . ' rows)</title><style>'
. ':root{--bg:#f6f6f3;--bg-panel:#fdfdfb;--bg-row:#fbfbf8;--ink:#23262b;--ink-muted:#6b7280;'
. '--line:#dcdfd8;--accent:#4c7a3d;--accent-ink:#f6f6f3;--prop:#1d4ed8;--now:#6b7280;'
. '--warn-bg:#f5ecd4;--warn-ink:#8a6d1d;--need:#b3423b;--need-bg:#f9efee;'
. '--calm-bg:#eff5ef;--chipbg:#ecefe8;--occ:#a33;}'
. 'html[data-theme=dark]{' . $darkTokens . '}'
. '@media (prefers-color-scheme: dark){html:not([data-theme=light]){' . $darkTokens . '}}'
. '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);'
. 'font:15px/1.45 system-ui,-apple-system,sans-serif;-webkit-text-size-adjust:100%}'
. 'header{padding:16px 16px 8px}h1{font-size:20px;margin:0 0 4px}'
. '.meta{color:var(--ink-muted);font-size:12.5px}'
. '.cards{display:flex;flex-wrap:wrap;gap:8px;padding:8px 16px}'
. '.card{background:var(--bg-panel);border:1px solid var(--line);border-radius:10px;'
. 'padding:8px 12px;text-decoration:none;color:var(--ink);font-size:13.5px}'
. '.card b{display:block;font-size:19px}.card.needscard b{color:var(--need)}'
. '.flagtotals{padding:0 16px 8px;color:var(--ink-muted);font-size:12px}'
. 'nav{position:sticky;top:0;z-index:5;background:var(--bg-panel);border-block:1px solid var(--line);'
. 'display:flex;gap:2px;align-items:center;padding:6px 10px;overflow-x:auto;white-space:nowrap}'
. 'nav a{color:var(--ink);text-decoration:none;padding:5px 9px;border-radius:8px;font-size:13px;flex:none}'
. 'nav a:hover{background:var(--chipbg)}nav a b{color:var(--accent)}'
. 'nav input{margin-left:auto;flex:1 1 130px;min-width:110px;background:var(--bg-row);color:var(--ink);'
. 'border:1px solid var(--line);border-radius:8px;padding:5px 9px;font-size:13px}'
. 'nav label{flex:none;font-size:12px;color:var(--ink-muted);display:flex;gap:4px;align-items:center;padding:0 4px}'
. '#themebtn{flex:none;background:var(--chipbg);color:var(--ink);border:1px solid var(--line);'
. 'border-radius:8px;padding:5px 9px;font-size:12.5px;cursor:pointer}'
. 'section{padding:10px 16px 4px}section.calm .row{background:var(--calm-bg)}'
. 'h2{font-size:16px;margin:14px 0 2px}.count{color:var(--accent)}'
. '.blurb{color:var(--ink-muted);font-size:13px;margin:2px 0 10px;max-width:70ch}'
. 'section.needs .blurb{color:var(--need)}'
. '.row{background:var(--bg-row);border:1px solid var(--line);border-radius:10px;'
. 'padding:8px 12px;margin:0 0 8px;scroll-margin-top:56px}'
. '.row.needs{border-left:4px solid var(--need)}.row:target{outline:2px solid var(--accent)}'
. '.rowtop{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:3px}'
. '.cite{color:var(--ink-muted);text-decoration:none;font-size:12px;font-family:ui-monospace,monospace}'
. '.cite:hover{color:var(--accent)}'
. '.badge{font-size:10.5px;font-weight:700;letter-spacing:.4px;border-radius:6px;padding:2px 7px}'
. '.lcbadge{background:var(--warn-bg);color:var(--warn-ink)}'
. '.nobadge{background:var(--need-bg);color:var(--need)}'
. '.chip{font-size:11px;background:var(--chipbg);color:var(--ink-muted);border-radius:6px;padding:2px 7px}'
. '.names{display:flex;flex-wrap:wrap;gap:4px 10px;align-items:baseline}'
. '.now{color:var(--now)}.prop{color:var(--prop);font-weight:600}'
. '.arr{color:var(--ink-muted)}'
. '.bizline{font-size:13.5px;margin-top:2px}.biz{color:var(--accent);font-weight:600}'
. '.occ{color:var(--occ);font-size:12.5px}'
. '.slugline{font-size:12.5px;color:var(--ink-muted);font-family:ui-monospace,monospace;margin-top:2px}'
. '.slugline .src{font-family:inherit}.muted{opacity:.75}'
. '.hid{display:none}footer{padding:16px;color:var(--ink-muted);font-size:12px}'
. '@media (max-width:640px){.names{flex-direction:column;gap:1px}.arr{display:none}'
. '.prop::before{content:"→ "}body{font-size:14px}}'
. '</style></head><body>'
. '<header><h1>Identity cleanup — combined pass review</h1>'
. '<div class="meta">' . $total . ' rows · generated ' . $h($stamp) . ' from ' . $h($srcName)
. ' · DRY RUN, nothing applied · ' . $lowConfTotal . ' low-confidence rows</div></header>'
. '<div class="cards">';
foreach ($sections as [$sid, $title, $g, , $tone]) {
    if (!$g && $sid === 'other') continue;
    $html .= '<a class="card' . ($tone === 'needs' ? ' needscard' : '') . '" href="#' . $sid . '"><b>'
           . count($g) . '</b>' . $h($title) . '</a>';
}
$html .= '</div><div class="flagtotals">flag totals (overlapping, across all groups): ' . $h($flagLine) . '</div>'
. '<nav>' . $navHtml
. '<input id="q" type="search" placeholder="filter: name, @handle, #id, flag…" autocomplete="off">'
. '<label><input type="checkbox" id="lconly"> low-conf only</label>'
. '<button id="themebtn" type="button">theme</button></nav>'
. $bodyHtml
. '<footer>Cite any row back to the lane by its #u-number link. Generated by tools/identity-pass/render-review.php — the page is an artifact; the tool is the source.</footer>'
. '<script>(function(){'
. 'var d=document,q=d.getElementById("q"),lc=d.getElementById("lconly"),tb=d.getElementById("themebtn");'
. 'var rows=[].slice.call(d.querySelectorAll(".row"));'
. 'function apply(){var t=q.value.trim().toLowerCase(),only=lc.checked,n={};'
. 'rows.forEach(function(r){var hit=(!t||r.getAttribute("data-s").indexOf(t)>-1)&&(!only||r.getAttribute("data-lc")==="1");'
. 'r.classList.toggle("hid",!hit);if(hit){var s=r.parentNode.id;n[s]=(n[s]||0)+1}});'
. '[].forEach.call(d.querySelectorAll("section"),function(s){var c=n[s.id]||0;'
. 's.classList.toggle("hid",c===0);var el=s.querySelector("[data-count]");if(el)el.textContent=c;});}'
. 'q.addEventListener("input",apply);lc.addEventListener("change",apply);'
. 'var KEY="lg-review-theme";function setT(v){if(v){d.documentElement.setAttribute("data-theme",v);'
. 'localStorage.setItem(KEY,v);}else{d.documentElement.removeAttribute("data-theme");localStorage.removeItem(KEY);}}'
. 'var saved=localStorage.getItem(KEY);if(saved)setT(saved);'
. 'tb.addEventListener("click",function(){var cur=d.documentElement.getAttribute("data-theme");'
. 'var dark=cur?cur==="dark":matchMedia("(prefers-color-scheme: dark)").matches;setT(dark?"light":"dark");});'
. '})();</script></body></html>';

if (file_put_contents($OUT, $html) === false) { fwrite(STDERR, "cannot write $OUT\n"); exit(1); }
fwrite(STDERR, "wrote $OUT (" . number_format(strlen($html)) . " bytes, $total rows)\n");
foreach ($groups as $k => $g) if ($g) fwrite(STDERR, "  $k: " . count($g) . "\n");
