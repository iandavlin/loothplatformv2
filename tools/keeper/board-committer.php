#!/usr/bin/env php
<?php
/**
 * board-committer.php — the ONLY thing allowed to write the board's files.
 *
 * Backlog 29, phase 2. Keeper's ruling, 2026-08-15: a committer service running
 * as `ubuntu` with its OWN clone, called over a localhost socket by the web
 * pool, with no git credentials anywhere near the web user.
 *
 * WHY A SERVICE AT ALL. The board is served from `~/loothplatformv2-clean`,
 * which only ever pulls — the rule that outranks everything on this box, and
 * the one that left nginx dead in July when it was broken. Verified: that
 * checkout is `ubuntu:ubuntu` with no write bit for others, and every PHP-FPM
 * pool runs as a non-ubuntu user. So the page CANNOT write there, and must not.
 * This process can, in a clone of its own, and hands the result to git.
 *
 * ┌─ THE FOUR FENCES ────────────────────────────────────────────────────────┐
 * │ 1. ALLOWLISTED SHAPES ONLY. Three intents exist: reorder the priority     │
 * │    index, append to an item's notes, reference a board-media file.        │
 * │    Anything else — any other shape, any other path — is refused LOUDLY    │
 * │    and audited. There is no general "write this file" verb, because a     │
 * │    service that can write anything is not a fence, it is a hole with a    │
 * │    socket.                                                                │
 * │ 2. EVERY COMMIT STAMPS THE ACTOR and appends an audit line — including    │
 * │    refusals, which are the interesting ones.                             │
 * │ 3. THE BUCK FENCE runs before every commit. Buck's files are never ours.  │
 * │ 4. NOTHING IS PUSHED THAT DID NOT PASS. A refusal leaves the clone        │
 * │    untouched; a failed fence aborts before `git commit`, not after.       │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The REORDER intent is deliberately the strictest: it must be a PERMUTATION of
 * the ids already in the file. Not a superset, not a subset — the same items in
 * a different order. Ian dragging a card cannot add work, delete work, or
 * rename anything, no matter what the page posts.
 *
 *   board-committer.php <<< '{"intent":"reorder","actor":"ian-via-board","order":[...]}'
 *   board-committer.php --dry-run <<< '…'      # validate + diff, commit nothing
 *
 * Exit 0 applied, 1 refused, 2 internal failure, 3 cannot run.
 */

declare(strict_types=1);

/** The service's OWN clone — never the serving checkout, never a lane worktree. */
const CLONE_DIR = '/home/ubuntu/board-committer-clone';
const AUDIT     = '/home/ubuntu/.board-committer-audit.log';
const BRANCH    = 'main';

/** Fence 1: the only paths this service may touch, ever. */
const ALLOWED_PATHS = [
    'docs/BACKLOG.md',
    'docs/board-notes/',      // per-item notes and chat threads
    'docs/board-media/',      // media references (the files live outside git)
];

const INTENTS = [ 'reorder', 'note_append', 'media_ref' ];

/* ---------------------------------------------------------------------- */

function out( array $p, int $code = 0 ): void
{
    fwrite( STDOUT, json_encode( $p, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n" );
    exit( $code );
}

/** A refusal is a first-class outcome: named, audited, and non-zero. */
function refuse( string $why, array $extra = [] ): void
{
    audit( $GLOBALS['ACTOR'] ?? '-', $GLOBALS['INTENT'] ?? '-', 'REFUSED: ' . $why );
    out( [ 'ok' => false, 'refused' => true, 'why' => $why ] + $extra, 1 );
}

function fail( string $why ): void
{
    audit( $GLOBALS['ACTOR'] ?? '-', $GLOBALS['INTENT'] ?? '-', 'FAILED: ' . $why );
    out( [ 'ok' => false, 'refused' => false, 'error' => $why ], 2 );
}

function audit( string $actor, string $intent, string $result ): void
{
    @file_put_contents( AUDIT, sprintf(
        "%s actor=%s intent=%s result=%s\n", gmdate( 'c' ), $actor ?: '-', $intent ?: '-', $result
    ), FILE_APPEND | LOCK_EX );
    @chmod( AUDIT, 0600 );
}

/**
 * Put the clone back exactly as it was.
 *
 * `git checkout --` alone is NOT enough: it restores tracked files and leaves
 * NEW ones behind as untracked litter, which then trips the dirty-clone refusal
 * on every subsequent call. Cleaning is scoped to the fenced paths, so this can
 * never tidy away anything it was not allowed to create.
 */
function restoreClone(): void
{
    run( 'git checkout -- .', CLONE_DIR );
    foreach ( ALLOWED_PATHS as $p ) {
        if ( str_ends_with( $p, '/' ) ) { run( 'git clean -fdq -- ' . escapeshellarg( $p ), CLONE_DIR ); }
    }
}

function run( string $cmd, ?string $cwd = null ): array
{
    $full = $cwd !== null ? 'cd ' . escapeshellarg( $cwd ) . ' && ' . $cmd : $cmd;
    $out = []; $rc = 0;
    exec( $full . ' 2>&1', $out, $rc );
    return [ 'rc' => $rc, 'out' => implode( "\n", $out ) ];
}

/** Fence 1, enforced on the actual paths git is about to see. */
function pathAllowed( string $rel ): bool
{
    if ( str_contains( $rel, '..' ) ) { return false; }
    foreach ( ALLOWED_PATHS as $ok ) {
        if ( str_ends_with( $ok, '/' ) ? str_starts_with( $rel, $ok ) : $rel === $ok ) { return true; }
    }
    return false;
}

/* ---------------------------------------------------------------------- *
 * The intents
 * ---------------------------------------------------------------------- */

/**
 * Rewrite the PRIORITY INDEX in the given order.
 *
 * THE STRICT ONE. The submitted order must be a PERMUTATION of the ids already
 * in the file — same multiset, different sequence. That single rule is what
 * makes a drag safe: it cannot add an item, drop one, or smuggle in edited
 * text, because nothing but line ORDER is taken from the request. The line
 * bodies are the file's own, moved.
 */
function applyReorder( string $repo, array $order ): array
{
    $path = $repo . '/docs/BACKLOG.md';
    if ( ! is_readable( $path ) ) { fail( 'BACKLOG.md unreadable in the clone' ); }

    $raw   = str_replace( [ "\r\n", "\r" ], "\n", (string) file_get_contents( $path ) );
    $lines = explode( "\n", $raw );

    $inIndex = false; $band = null;
    $rows = [];      // '#id' => the file's own line (prefixed: see below)
    $ids  = [];      // ids in file order, as STRINGS
    $slots = [];     // the line numbers the index occupies, in order
    foreach ( $lines as $i => $l ) {
        if ( ! $inIndex ) { if ( str_starts_with( $l, '## PRIORITY INDEX' ) ) { $inIndex = true; } continue; }
        if ( str_starts_with( $l, '---' ) || ( str_starts_with( $l, '## ' ) && ! str_starts_with( $l, '## PRIORITY' ) ) ) { break; }
        if ( preg_match( '/^\*\*(.+?)\*\*\s*$/u', $l ) ) { $band = $i; continue; }
        if ( $band !== null && preg_match( '/^([A-Z]?\d+(?:\.\d+)?)\s*[.)]?\s+/u', $l, $m ) ) {
            // KEYS ARE PREFIXED because PHP silently turns a numeric-string
            // array key into an INTEGER. Without the prefix, id "27" becomes
            // int 27 while the request's "27" stays a string, the strict
            // comparison below fails, and array_diff — which compares loosely —
            // reports nothing wrong. The gate caught exactly that: a refusal
            // claiming "not a permutation" with an EMPTY list of what was
            // missing or invented. Most backlog ids are plain numbers, so this
            // would have broken reorder for nearly every item.
            $rows[ '#' . $m[1] ] = $l;
            $ids[]   = $m[1];
            $slots[] = $i;
        }
    }
    if ( $rows === [] ) { fail( 'no PRIORITY INDEX rows found — refusing to rewrite a file I cannot read' ); }

    $have = array_map( 'strval', $ids );
    sort( $have ); $want = array_map( 'strval', $order ); sort( $want );
    if ( $have !== $want ) {
        refuse( 'the submitted order is not a permutation of the file\'s items — a drag may reorder, never add, drop or rename', [
            'missing' => array_values( array_diff( $have, $order ) ),
            'unknown' => array_values( array_diff( $order, $have ) ),
        ] );
    }

    // Same line bodies, new sequence. Nothing from the request reaches the file.
    foreach ( $slots as $n => $lineNo ) { $lines[ $lineNo ] = $rows[ '#' . (string) $order[ $n ] ]; }
    file_put_contents( $path, implode( "\n", $lines ) );
    return [ 'docs/BACKLOG.md' ];
}

/** Append a note to one item's file. Text is written as a fenced block, never interpreted. */
function applyNote( string $repo, string $id, string $text, string $actor ): array
{
    if ( ! preg_match( '/^[A-Z]?\d+(\.\d+)?$/', $id ) ) { refuse( 'that is not an item id: ' . $id ); }
    if ( trim( $text ) === '' ) { refuse( 'empty note' ); }

    $rel = 'docs/board-notes/' . $id . '.md';
    if ( ! pathAllowed( $rel ) ) { refuse( 'path outside the fence: ' . $rel ); }

    $dir = $repo . '/docs/board-notes';
    if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) { fail( 'could not create the notes directory' ); }

    // The body is quoted, not merged into the document: a note cannot forge a
    // heading, a list item, or anything else the board parses.
    $block = sprintf( "\n### %s — %s\n\n> %s\n",
        gmdate( 'Y-m-d H:i' ), $actor,
        str_replace( "\n", "\n> ", trim( $text ) ) );

    if ( ! is_file( $repo . '/' . $rel ) ) {
        file_put_contents( $repo . '/' . $rel, "# Notes — item " . $id . "\n" );
    }
    file_put_contents( $repo . '/' . $rel, $block, FILE_APPEND );
    return [ $rel ];
}

/** Record a media reference. The FILE lives outside git; only the pointer is committed. */
function applyMediaRef( string $repo, string $id, string $ref, string $actor ): array
{
    if ( ! preg_match( '/^[A-Z]?\d+(\.\d+)?$/', $id ) ) { refuse( 'that is not an item id: ' . $id ); }
    if ( ! preg_match( '#^[a-zA-Z0-9._/-]+$#', $ref ) || str_contains( $ref, '..' ) ) {
        refuse( 'unsafe media reference: ' . $ref );
    }
    $rel = 'docs/board-media/' . $id . '.md';
    if ( ! pathAllowed( $rel ) ) { refuse( 'path outside the fence: ' . $rel ); }

    $dir = $repo . '/docs/board-media';
    if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) { fail( 'could not create the media directory' ); }
    if ( ! is_file( $repo . '/' . $rel ) ) {
        file_put_contents( $repo . '/' . $rel, "# Media — item " . $id . "\n" );
    }
    file_put_contents( $repo . '/' . $rel,
        sprintf( "\n- %s — %s — `%s`\n", gmdate( 'Y-m-d H:i' ), $actor, $ref ), FILE_APPEND );
    return [ $rel ];
}

/* ---------------------------------------------------------------------- *
 * Main
 * ---------------------------------------------------------------------- */

$dryRun = in_array( '--dry-run', array_slice( $_SERVER['argv'], 1 ), true );
$body   = (string) stream_get_contents( STDIN );
$req    = json_decode( $body, true );
if ( ! is_array( $req ) ) { $GLOBALS['ACTOR'] = '-'; refuse( 'body is not JSON' ); }

$GLOBALS['ACTOR']  = (string) ( $req['actor']  ?? '' );
$GLOBALS['INTENT'] = (string) ( $req['intent'] ?? '' );
$actor  = $GLOBALS['ACTOR'];
$intent = $GLOBALS['INTENT'];

if ( $actor === '' || ! preg_match( '/^[a-z0-9-]{3,40}$/', $actor ) ) {
    refuse( 'every write must name its actor (fence 2)' );
}
// FENCE 1, first gate: an unknown intent never reaches a file.
if ( ! in_array( $intent, INTENTS, true ) ) {
    refuse( 'unknown intent "' . $intent . '" — allowed: ' . implode( ', ', INTENTS ) );
}
if ( ! is_dir( CLONE_DIR . '/.git' ) ) {
    audit( $actor, $intent, 'CANNOT RUN: no clone' );
    out( [ 'ok' => false, 'error' => 'the committer clone is not set up at ' . CLONE_DIR ], 3 );
}

// Always start from a clean, current tree — never commit on top of a surprise.
$r = run( 'git status --porcelain', CLONE_DIR );
if ( trim( $r['out'] ) !== '' ) { fail( 'the clone is dirty; refusing to build a commit on unknown changes' ); }
$r = run( 'git fetch -q origin && git reset -q --hard origin/' . BRANCH, CLONE_DIR );
if ( $r['rc'] !== 0 ) { fail( 'could not sync the clone: ' . $r['out'] ); }

switch ( $intent ) {
    case 'reorder':
        $order = $req['order'] ?? null;
        if ( ! is_array( $order ) || $order === [] ) { refuse( 'reorder needs an order' ); }
        $touched = applyReorder( CLONE_DIR, array_map( 'strval', $order ) );
        $summary = 'reordered the priority index';
        break;
    case 'note_append':
        $touched = applyNote( CLONE_DIR, (string) ( $req['id'] ?? '' ), (string) ( $req['text'] ?? '' ), $actor );
        $summary = 'note on item ' . (string) ( $req['id'] ?? '?' );
        break;
    case 'media_ref':
        $touched = applyMediaRef( CLONE_DIR, (string) ( $req['id'] ?? '' ), (string) ( $req['ref'] ?? '' ), $actor );
        $summary = 'media reference on item ' . (string) ( $req['id'] ?? '?' );
        break;
    default:
        refuse( 'unreachable' );
}

// FENCE 1, second gate: whatever git ACTUALLY sees changed must be in the fence.
// The intent handlers are trusted to be careful; this trusts nothing.
//
// `git status --porcelain -uall`, NOT `git diff` — a brand-new file is
// UNTRACKED, and git diff does not list it. The first cut used git diff, so
// appending a note to an item that had none read as "that changed nothing",
// refused, and left the new file behind as untracked litter — which then made
// the clone dirty and refused every call after it. One missing flag, cascading.
$r = run( 'git status --porcelain -uall', CLONE_DIR );
$changed = [];
foreach ( explode( "\n", trim( $r['out'] ) ) as $line ) {
    $line = trim( $line );
    if ( $line === '' ) { continue; }
    // "XY path" — and a rename reads "R  old -> new"; take what git ends with.
    $p = trim( substr( $line, 2 ) );
    if ( str_contains( $p, ' -> ' ) ) { $p = substr( $p, strpos( $p, ' -> ' ) + 4 ); }
    $changed[] = trim( $p, '"' );
}
$changed = array_values( array_unique( array_filter( $changed ) ) );
$outside = array_values( array_filter( $changed, static fn ( string $p ): bool => ! pathAllowed( $p ) ) );
if ( $outside !== [] ) {
    restoreClone();
    refuse( 'a write landed outside the fence — nothing committed', [ 'outside' => $outside ] );
}
if ( $changed === [] ) {
    restoreClone();
    refuse( 'that changed nothing' );
}

// FENCE 3: Buck's files are never ours.
$buck = CLONE_DIR . '/tools/gates/buck-surface-guard.sh';
if ( is_readable( $buck ) ) {
    $r = run( 'bash ' . escapeshellarg( $buck ), CLONE_DIR );
    if ( $r['rc'] !== 0 ) {
        restoreClone();
        refuse( 'the buck fence refused this change', [ 'buck' => $r['out'] ] );
    }
} else {
    restoreClone();
    fail( 'the buck fence is missing from the clone — refusing to commit without it' );
}

if ( $dryRun ) {
    $diff = run( 'git diff --stat', CLONE_DIR );
    restoreClone();
    audit( $actor, $intent, 'dry-run ok' );
    out( [ 'ok' => true, 'dry_run' => true, 'would_change' => $changed, 'stat' => trim( $diff['out'] ) ] );
}

// FENCE 2: the actor is in the commit, not only in the log.
$msg = sprintf( "board: %s\n\nWritten from the work board by %s.\nIntent: %s. Fences: paths, actor, buck.\n",
    $summary, $actor, $intent );
$tmp = tempnam( sys_get_temp_dir(), 'bcm' );
file_put_contents( $tmp, $msg );

$r = run( 'git add -- ' . implode( ' ', array_map( 'escapeshellarg', $changed ) ), CLONE_DIR );
if ( $r['rc'] !== 0 ) { restoreClone(); fail( 'git add failed: ' . $r['out'] ); }

$r = run( 'git -c user.name="Work board" -c user.email=board@loothgroup.com commit -q -F ' . escapeshellarg( $tmp ), CLONE_DIR );
@unlink( $tmp );
if ( $r['rc'] !== 0 ) { run( 'git reset -q --hard origin/' . BRANCH, CLONE_DIR ); fail( 'commit failed: ' . $r['out'] ); }

$sha = trim( run( 'git rev-parse --short HEAD', CLONE_DIR )['out'] );
$r   = run( 'git push -q origin HEAD:' . BRANCH, CLONE_DIR );
if ( $r['rc'] !== 0 ) {
    run( 'git reset -q --hard origin/' . BRANCH, CLONE_DIR );
    fail( 'push rejected, local commit rolled back: ' . $r['out'] );
}

audit( $actor, $intent, 'ok ' . $sha . ' ' . implode( ',', $changed ) );
out( [ 'ok' => true, 'commit' => $sha, 'changed' => $changed, 'summary' => $summary ] );
