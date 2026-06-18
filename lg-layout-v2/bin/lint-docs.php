#!/usr/bin/env php
<?php
/**
 * lint-docs.php — verify the docs/ tree obeys conventions from docs/README.md:
 *
 *   1. Every .md file in docs/ (recursively) is listed in docs/README.md's Index table.
 *   2. Every doc has a "See also" section at the bottom.
 *   3. "See also" links are reciprocal — A→B implies B→A.
 *   4. No broken intra-doc links (every [text](path.md) resolves to a real file).
 *   5. Every per-block doc in docs/blocks/ is referenced from docs/BLOCKS.md.
 *
 * Exit code: 0 if clean, 1 if any rule violated.
 *
 * Usage:  bin/lint-docs.php
 *         bin/lint-docs.php --fix-discoverable     (future — auto-add missing index entries)
 */

declare(strict_types=1);

$ROOT     = dirname(__DIR__);
$DOCS_DIR = $ROOT . '/docs';

if (!is_dir($DOCS_DIR)) {
    fwrite(STDERR, "lint-docs: no docs/ at $DOCS_DIR\n");
    exit(1);
}

$violations = [];

/* ── Collect all .md files in docs/ (recursive) ─────────────────── */
$mdFiles = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($DOCS_DIR, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($iter as $f) {
    if ($f->isFile() && strtolower($f->getExtension()) === 'md') {
        $rel = substr($f->getPathname(), strlen($DOCS_DIR) + 1);
        $mdFiles[] = $rel;
    }
}
sort($mdFiles);

/* ── Load README.md ─────────────────────────────────────────────── */
$readme = $DOCS_DIR . '/README.md';
if (!file_exists($readme)) {
    $violations[] = "missing docs/README.md — the index";
}
$readmeContent = file_exists($readme) ? file_get_contents($readme) : '';

/* ── Rule 1: every doc listed in README's Index table ───────────── */
foreach ($mdFiles as $rel) {
    if ($rel === 'README.md') continue;                              /* index doesn't list itself */
    if (str_starts_with($rel, 'blocks/') && $rel !== 'blocks/_template.md') continue;  /* per-block docs are indexed in BLOCKS.md, not README */
    if (!str_contains($readmeContent, "($rel)")) {
        $violations[] = "rule 1: docs/$rel is not linked from docs/README.md's Index";
    }
}

/* ── Rule 2 + 3: every doc has "See also" footer; links are reciprocal ── */
$seeAlsoGraph = [];  /* file → [targets] */
foreach ($mdFiles as $rel) {
    $path = $DOCS_DIR . '/' . $rel;
    $content = file_get_contents($path);

    if (!preg_match('/\*\*See also\*\*/i', $content)) {
        $violations[] = "rule 2: docs/$rel has no 'See also' section";
        $seeAlsoGraph[$rel] = [];
        continue;
    }

    /* Extract the "See also" section (everything from "**See also**" to end) */
    $pos = stripos($content, '**See also**');
    $tail = substr($content, $pos);

    /* Find [text](path) links in the tail */
    preg_match_all('/\[([^\]]+)\]\(([^)]+\.md)(?:#[^)]*)?\)/', $tail, $matches);

    $targets = [];
    foreach ($matches[2] as $linkPath) {
        /* Resolve relative to the linking doc */
        $dir = dirname($rel);
        $resolved = ($dir === '.') ? $linkPath : $dir . '/' . $linkPath;
        $resolved = normalizePath($resolved);
        $targets[] = $resolved;
    }
    $seeAlsoGraph[$rel] = array_unique($targets);
}

/* Reciprocity check (relaxed): for every A→B in A's See-also,
   require that B mentions A *anywhere* in its body — not specifically in B's
   See-also. This lets index docs (BLOCKS.md, README.md) link to many child
   docs (blocks/*.md) without each child being repeated in the index's See-also. */
$bodyMentions = [];  /* file → set of files it links to anywhere */
foreach ($mdFiles as $rel) {
    $path = $DOCS_DIR . '/' . $rel;
    $stripped = stripCodeSegments(file_get_contents($path));
    preg_match_all('/\[([^\]]+)\]\(([^)]+\.md)(?:#[^)]*)?\)/', $stripped, $m);
    $dir = dirname($rel);
    $set = [];
    foreach ($m[2] as $linkPath) {
        if (str_starts_with($linkPath, 'http')) continue;
        $resolved = ($dir === '.') ? $linkPath : $dir . '/' . $linkPath;
        $set[normalizePath($resolved)] = true;
    }
    $bodyMentions[$rel] = $set;
}

foreach ($seeAlsoGraph as $from => $tos) {
    foreach ($tos as $to) {
        if (!isset($bodyMentions[$to])) {
            $violations[] = "rule 4: docs/$from links to docs/$to which doesn't exist";
            continue;
        }
        /* Exempt: docs whose basename starts with `_` are templates. Anyone can
           link to them; they don't need to know about every linker. */
        $toBase = basename($to);
        if (str_starts_with($toBase, '_')) continue;
        if (!isset($bodyMentions[$to][$from])) {
            $violations[] = "rule 3: docs/$from links to docs/$to but docs/$to never mentions docs/$from (no reciprocal awareness)";
        }
    }
}

/* ── Rule 4: every intra-doc [text](path.md) link resolves ──────── */
foreach ($mdFiles as $rel) {
    $path = $DOCS_DIR . '/' . $rel;
    $content = stripCodeSegments(file_get_contents($path));

    preg_match_all('/\[([^\]]+)\]\(([^)]+\.md)(?:#[^)]*)?\)/', $content, $matches);
    $dir = dirname($rel);
    foreach ($matches[2] as $linkPath) {
        if (str_starts_with($linkPath, 'http')) continue;
        $resolved = ($dir === '.') ? $linkPath : $dir . '/' . $linkPath;
        $resolved = normalizePath($resolved);
        $fullPath = $DOCS_DIR . '/' . $resolved;
        if (!file_exists($fullPath)) {
            $violations[] = "rule 4: docs/$rel links to docs/$resolved which doesn't exist";
        }
    }
}

/* ── Rule 5: every per-block doc referenced from BLOCKS.md ─────── */
$blocksIndex = $DOCS_DIR . '/BLOCKS.md';
if (file_exists($blocksIndex)) {
    $blocksContent = file_get_contents($blocksIndex);
    foreach ($mdFiles as $rel) {
        if (!str_starts_with($rel, 'blocks/')) continue;
        if ($rel === 'blocks/_template.md') continue;  /* template is referenced from README + BLOCKS, but is opt-in */
        $expected = "(" . substr($rel, strlen('blocks/'));   /* the link in BLOCKS.md is relative to docs/ */
        $expected2 = "($rel)";
        if (!str_contains($blocksContent, $expected) && !str_contains($blocksContent, $expected2)) {
            $violations[] = "rule 5: docs/$rel is not linked from docs/BLOCKS.md";
        }
    }
}

/* ── Report ─────────────────────────────────────────────────────── */
if (!$violations) {
    fwrite(STDOUT, "lint-docs: " . count($mdFiles) . " docs · all conventions clean\n");
    exit(0);
}

fwrite(STDERR, "lint-docs: " . count($violations) . " violation(s) in " . count($mdFiles) . " docs\n\n");
foreach ($violations as $v) {
    fwrite(STDERR, "  ✗ $v\n");
}
exit(1);

/* ── Helpers ────────────────────────────────────────────────────── */

/** Strip fenced code blocks and inline-code spans so the link-matcher
 * doesn't catch markdown that's intentionally shown as code. */
function stripCodeSegments(string $s): string {
    $s = preg_replace('/```.*?```/s', '', $s);   // fenced blocks
    $s = preg_replace('/`[^`]*`/', '', $s);      // inline code
    return $s;
}

function normalizePath(string $p): string {
    $parts = explode('/', $p);
    $out = [];
    foreach ($parts as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($out); continue; }
        $out[] = $seg;
    }
    return implode('/', $out);
}
