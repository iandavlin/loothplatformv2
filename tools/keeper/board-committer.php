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
    'docs/board-lanes/',      // Ian's messages TO a lane (the replies are never committed)
];

const INTENTS = [ 'reorder', 'note_append', 'media_ref', 'lane_message', 'lane_receipt', 'item_add', 'item_promote' ];

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

    /**
     * FENCE 1c — AN AMBIGUOUS INDEX CANNOT BE REORDERED SAFELY.
     *
     * The permutation rule assumes an id names exactly one line. IDS IN THIS
     * FILE ARE NOT UNIQUE: the index really did carry "9" twice (Shop Layout
     * Planner in P1, Advanced search in P2) until it was renumbered on
     * 2026-08-15, and nothing stops it happening again.
     *
     * Measured, not theorised: with "9" twice, `$rows` keeps only the SECOND
     * line while `$slots` keeps both positions — so the permutation check
     * PASSES, and the rewrite silently deletes one item and writes the other
     * twice. That is precisely the "a drag cannot add, drop or rename"
     * guarantee failing, quietly, on the one operation it exists to protect.
     *
     * So a duplicate id is refused rather than resolved. Guessing which "9" the
     * drag meant is not available to this code, and picking one would be a
     * coin-flip that destroys an item when it loses.
     */
    if ( count( $rows ) !== count( $slots ) ) {
        $seen = []; $dupes = [];
        foreach ( $ids as $id ) {
            if ( isset( $seen[ '#' . $id ] ) ) { $dupes[ '#' . $id ] = $id; }
            $seen[ '#' . $id ] = true;
        }
        refuse( 'the priority index uses the same id more than once, so a reorder cannot say which line it means — renumber the duplicate first', [
            'duplicate_ids' => array_values( $dupes ),
        ] );
    }

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

/**
 * Ian's message TO a lane. Ian, 2026-08-16: "I would like to be able to
 * interact with the lanes through the workboard."
 *
 * ONLY HIS HALF IS COMMITTED. The lanes' replies already live in the devmsg
 * store and are rendered from a snapshot — committing both directions would put
 * hundreds of commits a day on main and make the log useless. His words are the
 * durable half because they are instructions, and an instruction should be
 * findable from the item months later.
 *
 * THE LANE NAME IS THE DANGEROUS PART, because it is used twice: it becomes a
 * FILENAME here, and downstream it becomes a TMUX SESSION NAME that the relay
 * hands to lane-say. So it is validated to a strict token before it is either.
 * Note the fence deliberately does NOT consult a list of known lanes: a fence
 * that depends on a file being present fails open the day the file is missing,
 * and this one must fail closed. Offering only real lanes is the page's job.
 */
function applyLaneMessage( string $repo, string $lane, string $text, string $actor ): array
{
    if ( ! preg_match( '/^[a-z][a-z0-9-]{1,30}$/', $lane ) ) {
        refuse( 'that is not a lane name: ' . $lane );
    }
    if ( trim( $text ) === '' ) { refuse( 'empty message' ); }

    $rel = 'docs/board-lanes/' . $lane . '.md';
    if ( ! pathAllowed( $rel ) ) { refuse( 'path outside the fence: ' . $rel ); }

    $dir = $repo . '/docs/board-lanes';
    if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) { fail( 'could not create the lane directory' ); }

    if ( ! is_file( $repo . '/' . $rel ) ) {
        file_put_contents( $repo . '/' . $rel, "# Messages to " . $lane . "\n" );
    }

    /**
     * The body is QUOTED, exactly like a note — and here that is not only about
     * markdown. This text is on its way to a terminal, and a board message
     * containing backticks has already been command-substituted away twice on
     * this box. The relay is what actually defuses it (it delivers with
     * `lane-say -f`, from a file, so the text never becomes argv), but the
     * quoting keeps the stored form unambiguous about where the message ends.
     */
    $block = sprintf( "\n### %s — %s\n\n> %s\n",
        gmdate( 'Y-m-d H:i:s' ), $actor,
        str_replace( "\n", "\n> ", trim( $text ) ) );

    file_put_contents( $repo . '/' . $rel, $block, FILE_APPEND );
    return [ $rel ];
}

/**
 * Read the PRIORITY INDEX as (line number → id), in file order.
 *
 * IDS ARE DOTTED INTEGER PAIRS, NOT DECIMALS, and that is the whole reason this
 * returns strings and does its arithmetic on the parts. The file really carries
 * `3.10`, and `(float) "3.10" === (float) "3.1"` — so any numeric handling of an
 * id silently merges 3.1 with 3.10, and minting "the next child" by adding 0.1
 * would hand out a number that already exists. Parent and child are parsed and
 * incremented as separate integers, always.
 *
 * @return array{lines:string[],slots:array<int,string>,end:?int}
 */
function readIndex( string $repo ): array
{
    $path = $repo . '/docs/BACKLOG.md';
    if ( ! is_readable( $path ) ) { fail( 'BACKLOG.md unreadable in the clone' ); }
    $lines = explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", (string) file_get_contents( $path ) ) );

    $inIndex = false; $band = null; $slots = []; $end = null;
    foreach ( $lines as $i => $l ) {
        if ( ! $inIndex ) { if ( str_starts_with( $l, '## PRIORITY INDEX' ) ) { $inIndex = true; } continue; }
        if ( str_starts_with( $l, '---' ) || ( str_starts_with( $l, '## ' ) && ! str_starts_with( $l, '## PRIORITY' ) ) ) { break; }
        if ( preg_match( '/^\*\*(.+?)\*\*\s*$/u', $l ) ) { $band = $i; continue; }
        if ( $band !== null && preg_match( '/^([A-Z]?\d+(?:\.\d+)?)\s*[.)]?\s+/u', $l, $m ) ) {
            $slots[ $i ] = $m[1];
            $end = $i;
        }
    }
    if ( $slots === [] ) { fail( 'no PRIORITY INDEX rows found — refusing to write a file I cannot read' ); }
    return [ 'lines' => $lines, 'slots' => $slots, 'end' => $end ];
}

/**
 * The next free TOP-LEVEL number, taken from the file itself.
 *
 * Ian's rule, via keeper: POSITION IS RANK, NUMBER IS A PERMANENT NAME. So a new
 * item takes a number nobody has ever had — max + 1, never a gap-fill. Reusing a
 * retired number would make an old reference silently point at new work, which
 * is the one thing a permanent name must never do.
 */
function nextTopNumber( array $slots ): int
{
    $max = 0;
    foreach ( $slots as $id ) {
        if ( preg_match( '/^(\d+)$/', $id, $m ) ) { $max = max( $max, (int) $m[1] ); }
        // A child's parent counts too: 11.6 means the name "11" is taken even
        // when no item 11 exists, and the file really is like that today.
        if ( preg_match( '/^(\d+)\.\d+$/', $id, $m ) ) { $max = max( $max, (int) $m[1] ); }
    }
    return $max + 1;
}

/** The next free child of a parent — integer arithmetic on the part after the dot. */
function nextChildNumber( array $slots, string $parent ): int
{
    $max = -1;
    foreach ( $slots as $id ) {
        if ( preg_match( '/^' . preg_quote( $parent, '/' ) . '\.(\d+)$/', $id, $m ) ) {
            $max = max( $max, (int) $m[1] );
        }
    }
    return $max + 1;
}

/** One line of Ian's own words, safe to sit in the index. */
function cleanTitle( string $t ): string
{
    // Flattened to ONE line: a title with a newline in it would create a second
    // index row that no id owns, and the board would render half a sentence as
    // an item. Bold markers are stripped from the start because a line that
    // begins **like this** is how the file marks a BAND, and a title must never
    // be able to forge one.
    $t = trim( (string) preg_replace( '/\s+/u', ' ', $t ) );
    $t = ltrim( $t, "*# \t" );
    return trim( $t );
}

/**
 * ADD AN ITEM. Ian: "Could I add things. Add headers and sub items."
 *
 * ADDITIVE ONLY, and that is enforced by construction rather than by care: this
 * function INSERTS a line and touches nothing else. No existing line is edited,
 * moved or renumbered — which is the invariant keeper named, and the one the
 * gate checks by comparing the whole id list before and after.
 *
 * A new item lands at the BOTTOM of the index, because position is rank and
 * nobody but Ian may decide that something outranks existing work. He drags it
 * up, through the reorder shape that already exists.
 */
function applyItemAdd( string $repo, ?string $parent, string $title, string $actor ): array
{
    $title = cleanTitle( $title );
    if ( $title === '' ) { refuse( 'an item needs a title' ); }
    if ( mb_strlen( $title ) > 300 ) { refuse( 'that title is too long for one index line' ); }

    $idx   = readIndex( $repo );
    $lines = $idx['lines'];

    if ( $parent !== null && $parent !== '' ) {
        if ( ! preg_match( '/^\d+$/', $parent ) ) { refuse( 'a sub-item\'s parent is a plain number, not: ' . $parent ); }
        $id = $parent . '.' . nextChildNumber( $idx['slots'], $parent );
        // Sit with its siblings if it has any, so the file reads the way the
        // numbering claims. Otherwise fall to the bottom like any new item.
        $at = null;
        foreach ( $idx['slots'] as $line => $sid ) {
            if ( preg_match( '/^' . preg_quote( $parent, '/' ) . '\.\d+$/', $sid ) ) { $at = $line; }
        }
        $at ??= $idx['end'];
    } else {
        $id = (string) nextTopNumber( $idx['slots'] );
        $at = $idx['end'];
    }

    if ( in_array( $id, $idx['slots'], true ) ) {
        // Cannot happen with max+1, and is checked anyway: handing out a number
        // that is already in use is the one failure this must never have.
        fail( 'refusing to mint ' . $id . ' — the file already has it' );
    }

    array_splice( $lines, (int) $at + 1, 0, [ $id . '. ' . $title ] );
    file_put_contents( $repo . '/docs/BACKLOG.md', implode( "\n", $lines ) );
    $GLOBALS['MINTED'] = $id;
    return [ 'docs/BACKLOG.md' ];
}

/**
 * PROMOTE A SUB-ITEM to a top-level item. Ian: "Or promote sub items to headers."
 *
 * The content moves VERBATIM to a newly minted number, and the old line becomes
 * a POINTER rather than disappearing. Nothing is renumbered and no name is
 * retired: 4.2 still resolves, and now says where it went. A reference written
 * three months ago still lands somewhere true, which is what makes a number a
 * permanent name rather than a slot.
 */
function applyItemPromote( string $repo, string $id, string $actor ): array
{
    if ( ! preg_match( '/^\d+\.\d+$/', $id ) ) {
        refuse( 'only a sub-item can be promoted, and that is not one: ' . $id );
    }
    $idx   = readIndex( $repo );
    $lines = $idx['lines'];

    $at = null;
    foreach ( $idx['slots'] as $line => $sid ) { if ( $sid === $id ) { $at = $line; break; } }
    if ( $at === null ) { refuse( 'no such sub-item in the index: ' . $id ); }

    // Everything after the id and its separator is HIS words, and travels as-is.
    if ( ! preg_match( '/^' . preg_quote( $id, '/' ) . '\s*[.)]?\s+(.*)$/u', $lines[ $at ], $m ) ) {
        fail( 'could not read the body of ' . $id );
    }
    $body = rtrim( $m[1] );
    if ( str_contains( $body, 'promoted to ' ) ) { refuse( $id . ' has already been promoted' ); }

    $new = (string) nextTopNumber( $idx['slots'] );

    $lines[ $at ] = $id . '. → promoted to ' . $new;
    array_splice( $lines, (int) $idx['end'] + 1, 0, [ $new . '. ' . $body ] );

    file_put_contents( $repo . '/docs/BACKLOG.md', implode( "\n", $lines ) );
    $GLOBALS['MINTED'] = $new;
    return [ 'docs/BACKLOG.md' ];
}

/**
 * A DELIVERY RECEIPT — what makes the relay idempotent across a crash.
 *
 * Keeper's ruling, 2026-08-16: a delivered message gets a committed receipt in
 * this same fenced store, and the relay skips anything receipted. So a crash
 * between `lane-say` returning and the receipt landing can at worst re-deliver
 * ONCE — never loop. That is the whole reason this is a committed fact rather
 * than a file in the relay's own state: the relay may die, its disk may be
 * rebuilt, and the record of what a lane has already been told must outlive it.
 *
 * A FAILURE IS RECEIPTED TOO, and that is not an afterthought. If only successes
 * were recorded, an undeliverable message would be retried on every pass
 * forever — which is precisely the watermark that advances only on success, the
 * shape that wedged bb-mirror-reconcile for 11 days and 3,084 runs. Receipting
 * failures lets the relay count attempts and give up, and lets the board show
 * NOT DELIVERED instead of a message that looks sent.
 */
function applyLaneReceipt( string $repo, string $lane, string $id, string $outcome, string $why, string $actor ): array
{
    if ( ! preg_match( '/^[a-z][a-z0-9-]{1,30}$/', $lane ) ) {
        refuse( 'that is not a lane name: ' . $lane );
    }
    // The id names a message by its POSITION and a hash of its body. Position
    // alone would be silently wrong if the file were ever hand-edited; the hash
    // makes an edited message read as NEW (re-deliver once) rather than as
    // already-delivered, which is the safe direction to fail in.
    if ( ! preg_match( '/^\d{3}-[0-9a-f]{8}$/', $id ) ) {
        refuse( 'that is not a message id: ' . $id );
    }
    if ( ! in_array( $outcome, [ 'delivered', 'failed' ], true ) ) {
        refuse( 'a receipt is delivered or failed, not: ' . $outcome );
    }

    $rel = 'docs/board-lanes/' . $lane . '.receipts.md';
    if ( ! pathAllowed( $rel ) ) { refuse( 'path outside the fence: ' . $rel ); }

    $dir = $repo . '/docs/board-lanes';
    if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) { fail( 'could not create the lane directory' ); }
    if ( ! is_file( $repo . '/' . $rel ) ) {
        file_put_contents( $repo . '/' . $rel, "# Delivery receipts — " . $lane . "\n" );
    }

    // The reason is flattened to ONE line and capped: it comes from a
    // subprocess's stderr, and a receipt file whose rows can span lines is a
    // receipt file the relay cannot parse back reliably.
    $why = trim( preg_replace( '/\s+/u', ' ', $why ) ?? '' );
    if ( mb_strlen( $why ) > 200 ) { $why = mb_substr( $why, 0, 200 ) . '…'; }

    file_put_contents( $repo . '/' . $rel, sprintf( "- %s · %s · %s · %s · %s\n",
        gmdate( 'Y-m-d H:i:s' ), $id, $outcome, $actor, $why !== '' ? $why : '-' ), FILE_APPEND );
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
    case 'lane_message':
        $touched = applyLaneMessage( CLONE_DIR, (string) ( $req['lane'] ?? '' ), (string) ( $req['text'] ?? '' ), $actor );
        $summary = 'message to ' . (string) ( $req['lane'] ?? '?' );
        break;
    case 'item_add':
        $touched = applyItemAdd( CLONE_DIR, isset( $req['parent'] ) ? (string) $req['parent'] : null,
                                 (string) ( $req['title'] ?? '' ), $actor );
        $summary = 'added item ' . (string) ( $GLOBALS['MINTED'] ?? '?' );
        break;
    case 'item_promote':
        $touched = applyItemPromote( CLONE_DIR, (string) ( $req['id'] ?? '' ), $actor );
        $summary = 'promoted ' . (string) ( $req['id'] ?? '?' ) . ' to ' . (string) ( $GLOBALS['MINTED'] ?? '?' );
        break;
    case 'lane_receipt':
        $touched = applyLaneReceipt( CLONE_DIR, (string) ( $req['lane'] ?? '' ), (string) ( $req['id'] ?? '' ),
                                     (string) ( $req['outcome'] ?? '' ), (string) ( $req['why'] ?? '' ), $actor );
        $summary = 'receipt for ' . (string) ( $req['id'] ?? '?' ) . ' on ' . (string) ( $req['lane'] ?? '?' );
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
$reply = [ 'ok' => true, 'commit' => $sha, 'changed' => $changed, 'summary' => $summary ];
// The minted number goes back to the caller: the page has to be able to tell
// Ian what his new item is CALLED, and it must be the number the file actually
// took rather than one the page guessed at.
if ( isset( $GLOBALS['MINTED'] ) ) { $reply['id'] = (string) $GLOBALS['MINTED']; }
out( $reply );
