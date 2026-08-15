<?php
/**
 * archive-poc/api/v0/_guitardle-puzzle.php — the puzzle, resolved SERVER-SIDE.
 *
 * Backlog 25 (option A, keeper 2026-08-15). Until now the game was a static
 * client app: it fetched assets/sequence.json and assets/guitardle_phrases.csv
 * into the browser and decided the win with `guessed === PHRASE_LETTERS` in JS.
 * That means the ANSWER KEY IS PUBLIC -- 285 phrases and the full fixed
 * sequence, so today and every future day is computable by anyone. Server-side
 * scoring alone would not have fixed the leaderboard: a player reading the key
 * genuinely solves in one move, and the server would honestly score it 10
 * points, 20 with hardcore.
 *
 * So the phrase has to stop reaching the client. This file is the server's copy
 * of loadPhrase(), and it must agree with game.js EXACTLY -- a disagreement
 * means the server judges a different puzzle than the player saw, which is a
 * worse failure than the hole being closed. It is cross-checked against the
 * client's own arithmetic by the gate.
 *
 * Reads the same two assets off disk. They stay on disk (and, for now, stay
 * served) because the flag-OFF path still needs them.
 */

declare(strict_types=1);

const LG_GDLE_ASSETS = __DIR__ . '/../../web/guitardle/assets';
const LG_GDLE_VOWELS = ['A', 'E', 'I', 'O', 'U'];

/** sequence.json: {startDate, sequence: [phraseId, ...]}. */
function lg_gdle_sequence(): ?array {
    static $seq = null;
    if ($seq !== null) return $seq ?: null;
    $raw = @file_get_contents(LG_GDLE_ASSETS . '/sequence.json');
    $j   = $raw === false ? null : json_decode($raw, true);
    if (!is_array($j) || empty($j['sequence']) || empty($j['startDate'])) {
        $seq = false;
        return null;
    }
    return $seq = $j;
}

/**
 * Active phrases by id. Mirrors game.js's parser exactly, including the reason
 * it is written that way: a phrase may contain commas, so id is the FIRST
 * field, active is the LAST, and the phrase is everything between re-joined.
 */
function lg_gdle_phrases(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    $raw = @file_get_contents(LG_GDLE_ASSETS . '/guitardle_phrases.csv');
    if ($raw === false) return $map;
    $lines = preg_split('/\R/', trim($raw));
    foreach (array_slice($lines, 1) as $line) {   // skip the header row
        if ($line === '') continue;
        $parts  = explode(',', $line);
        if (count($parts) < 3) continue;
        $id     = (int) trim($parts[0]);
        $active = trim($parts[count($parts) - 1]);
        $phrase = trim(implode(',', array_slice($parts, 1, count($parts) - 2)));
        if ($active === '1') $map[$id] = $phrase;
    }
    return $map;
}

/**
 * Which phrase a given LOCAL day serves.
 *
 * The half-sequence shift for logged-out players is Ian's 6/11 ruling and is
 * reproduced verbatim: logged-out runs half a sequence ahead so the two
 * audiences never share a day's phrase. Server-driven play only ever runs for
 * members, but the shift stays parameterised so this function can answer for
 * either track -- the gate uses that to prove it matches the client on BOTH.
 */
function lg_gdle_phrase_id(string $localDate, bool $member): ?int {
    $seq = lg_gdle_sequence();
    if ($seq === null) return null;
    $start = strtotime($seq['startDate'] . ' 00:00:00 UTC');
    $day   = strtotime($localDate . ' 00:00:00 UTC');
    if ($start === false || $day === false) return null;
    $len     = count($seq['sequence']);
    $elapsed = (int) floor(($day - $start) / 86400) + ($member ? 0 : intdiv($len, 2));
    $idx     = (($elapsed % $len) + $len) % $len;
    return (int) $seq['sequence'][$idx];
}

function lg_gdle_phrase(int $phraseId): ?string {
    $map = lg_gdle_phrases();
    return isset($map[$phraseId]) ? strtoupper($map[$phraseId]) : null;
}

/** Letters only — what a guess is compared against (game.js PHRASE_LETTERS). */
function lg_gdle_letters(string $phrase): string {
    return preg_replace('/[-\s]/', '', $phrase);
}

/**
 * The board SHAPE: one entry per word, each a list of 'letter' | 'hyphen'
 * slots. This is all the client gets before it has earned anything — enough to
 * draw the tiles, and nothing that identifies the phrase.
 */
function lg_gdle_shape(string $phrase): array {
    $out = [];
    foreach (preg_split('/\s+/', trim($phrase)) as $word) {
        $slots = [];
        foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $slots[] = ($ch === '-') ? 'hyphen' : 'letter';
        }
        if ($slots) $out[] = $slots;
    }
    return $out;
}

/**
 * Hardcore reveal budget. Mirrors game.js loadPhrase(): the full-reveal cost
 * (1 per distinct consonant, 2 per distinct vowel) minus 3, floor 5 -- generous,
 * but the whole phrase can never be revealed, so the one guess stays a real
 * guess.
 */
function lg_gdle_move_cap(string $phrase): int {
    $letters  = lg_gdle_letters($phrase);
    $distinct = array_unique(preg_split('//u', $letters, -1, PREG_SPLIT_NO_EMPTY));
    $cost     = 0;
    foreach ($distinct as $L) $cost += in_array($L, LG_GDLE_VOWELS, true) ? 2 : 1;
    return max($cost - 3, 5);
}

/** 0-based positions of $letter across the phrase's LETTER slots. */
function lg_gdle_positions(string $phrase, string $letter): array {
    $letters = lg_gdle_letters($phrase);
    $out     = [];
    $len     = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        if ($letters[$i] === $letter) $out[] = $i;
    }
    return $out;
}

/**
 * Moves implied by a position. Verified against real gameplay 2026-08-15:
 * 2 consonants + one vowel bought-only + one vowel bought-and-placed gave
 * state.moves = 5, and this returns 5.
 *
 * The subtle part, and the one a re-implementation gets wrong: game.js DELETES
 * a vowel from purchasedVowels when it is placed, so `purchased` holds only
 * vowels bought and not yet placed. A placed vowel therefore costs 2 and lives
 * in `revealed`, not `purchased`.
 */
function lg_gdle_moves(array $revealed, array $purchased): int {
    $n = 0;
    foreach ($revealed as $L) $n += in_array($L, LG_GDLE_VOWELS, true) ? 2 : 1;
    return $n + count($purchased);
}
