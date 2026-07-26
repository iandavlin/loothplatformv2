<?php
declare(strict_types=1);

/**
 * OFFLINE PROOF for the identity-cleanup dry-run's pure derivation logic
 * (profile-app/bin/backfill-patreon-handles-dryrun.php). No DB, no API, no serve —
 * the closures below MIRROR the generator's (keep in sync by hand; the generator
 * is DB-coupled so it can't be included here). Run: php derive-proof.php
 */

const NAME_MAX = 40;
const SLUG_MAX = 30;   // = Slug::MAX_LEN
const SLUG_MIN = 3;    // = Slug::MIN_LEN

$clean = function (string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
};
$squash = fn(string $s): string => preg_replace('/\s+/u', ' ', trim(mb_strtolower($s))) ?? '';

$nameFit = function (string $s, int $max = NAME_MAX): string {
    $s = trim($s);
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max);
    if (!preg_match('/\s/u', mb_substr($s, $max, 1))) {   // cut lands mid-word → back up
        $sp = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp >= 2) $cut = mb_substr($cut, 0, $sp);
    }
    return rtrim($cut, " \t|,/·:;–—-&");   // & joins the set (generator sync, 3a80cfb)
};

$slugFit = function (string $s, int $max = SLUG_MAX): string {
    if (strlen($s) <= $max) return $s;
    $cut = substr($s, 0, $max);
    if (($s[$max] ?? '') !== '-') {                       // cut lands mid-word → back up
        $dash = strrpos($cut, '-');
        if ($dash !== false && $dash >= SLUG_MIN) $cut = substr($cut, 0, $dash);
    }
    return rtrim($cut, '-');
};

$split = function (string $name, string $anchor) use ($squash): ?array {
    $n = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $a = trim(preg_replace('/\s+/u', ' ', $anchor) ?? '');
    if ($n === '') return null;
    if ($a !== '' && mb_strlen($a) >= 3) {
        if ($squash($n) === $squash($a)) return null;
        $words = preg_split('/\s+/u', $a) ?: [];
        $pat = '/^\s*' . implode('\s+', array_map(fn($w) => preg_quote($w, '/'), $words))
             . '[\s\-–—|,\/:·]+(\S.*)$/iu';
        if (preg_match($pat, $n, $m)) {
            $tail   = trim($m[1]);
            $person = trim(mb_substr($n, 0, mb_strlen($n) - mb_strlen($m[1])), " \t|,/·:;–—-");
            if ($tail !== '') return [$person, $tail, 'anchored'];
        }
        return [$n, '', 'anchor-mismatch'];
    }
    if (preg_match('/^(.{2,80}?)\s*(?:[|–—·]|\s[-\/]\s)\s*(\S.*)$/u', $n, $m)) {
        return [trim($m[1]), trim($m[2]), 'separator'];
    }
    return null;
};

// batch-suffix mirror (owner-aware domain)
$owners = [];
$takenByOther = function (string $slug, int $self) use (&$owners): bool {
    foreach ($owners[strtolower($slug)] ?? [] as $o) if ($o !== $self) return true;
    return false;
};
$suffix = function (string $base, int $self) use (&$owners, $takenByOther, $slugFit): string {
    $c = $slugFit($base);
    for ($i = 2; $i <= 999; $i++) {
        if (!$takenByOther($c, $self)) break;
        $c = $slugFit($base, SLUG_MAX - strlen((string) $i)) . $i;
    }
    $owners[strtolower($c)][] = $self;
    return $c;
};

// ── fixtures ─────────────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
$T = function (string $label, $got, $want) use (&$pass, &$fail): void {
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("%s %s%s\n", $ok ? 'ok ' : 'FAIL', $label,
        $ok ? '' : ' — got ' . var_export($got, true) . ' want ' . var_export($want, true));
};

// The Doug case (board 00:16): doubled business name, anchor splits it clean.
$T('doug anchored split', $split('Doug Lawrence Doug Lawrence Guitars', 'Doug Lawrence'),
   ['Doug Lawrence', 'Doug Lawrence Guitars', 'anchored']);

// Separator right after the anchor.
$T('pipe separator after anchor', $split('Steve | Steve\'s Guitar Shack', 'Steve'),
   ['Steve', 'Steve\'s Guitar Shack', 'anchored']);

// Name IS the person — no tail, no split.
$T('name equals anchor', $split('Jane Doe', 'Jane Doe'), null);
$T('name equals anchor case/space', $split('  jane   DOE ', 'Jane Doe'), null);

// Anchor exists but does not lead the name.
$T('anchor mismatch', $split('Axe Master 9000', 'Bob Smith'),
   ['Axe Master 9000', '', 'anchor-mismatch']);

// No anchor: explicit separator heuristic.
$T('em-dash heuristic', $split('Maria Gonzalez — Gonzalez Lutherie', ''),
   ['Maria Gonzalez', 'Gonzalez Lutherie', 'separator']);
$T('spaced-hyphen heuristic', $split('Ken Loach - Loach Amps', ''),
   ['Ken Loach', 'Loach Amps', 'separator']);

// No anchor, no separator: leave alone (plain multi-word names never split blind).
$T('no separator no split', $split('Christopher Alexander Montgomery', ''), null);
$T('unspaced hyphen not a separator', $split('Anna-Maria Weber', ''), null);

// Anchor with regex specials survives preg_quote.
$T('anchor regex specials', $split('J.R. "Bob" Dobbs | SubGenius Guitars', 'J.R. "Bob" Dobbs'),
   ['J.R. "Bob" Dobbs', 'SubGenius Guitars', 'anchored']);

// nameFit: word-boundary cap at 40, separators stripped from the cut edge.
$T('nameFit under cap unchanged', $nameFit('Doug Lawrence'), 'Doug Lawrence');
$T('nameFit word boundary',
   $nameFit('Bartholomew Featherstonehaugh Windermere Fitzgerald III'),
   'Bartholomew Featherstonehaugh Windermere');
$T('nameFit no boundary hard cut', $nameFit(str_repeat('a', 55)), str_repeat('a', 40));

// slugFit: 30-cap at the last dash; the 34-char dev2 stray shape.
$T('slugFit doug doubled', $slugFit('doug-lawrence-doug-lawrence-guitars'),
   'doug-lawrence-doug-lawrence');
$T('slugFit under cap unchanged', $slugFit('doug-lawrence'), 'doug-lawrence');
$T('slugFit single word hard cut', $slugFit(str_repeat('x', 40)), str_repeat('x', 30));

// CJK cleans to empty → chain falls through (api-email leg proven on dev2 7/26).
$T('cjk cleans empty', $clean('山田太郎'), '');

// suffix: collision takes steve2; base+suffix stays inside the 30-cap.
$owners = ['steve' => [999]];
$T('suffix collision', $suffix('steve', 1), 'steve2');
$owners = [];
$long = 'abcdefghij-abcdefghij-abcdefgh';           // exactly 30
$owners[strtolower($long)] = [999];
$got = $suffix($long, 1);
$T('suffix stays inside cap', strlen($got) <= 30 && str_ends_with($got, '2'), true);
// own slug never blocks you
$owners = ['mine' => [7]];
$T('own slug never blocks', $suffix('mine', 7), 'mine');

printf("\n%d ok, %d fail\n", $pass, $fail);
exit($fail ? 1 : 0);
