<?php
/**
 * archive-poc/api/v0/_flags.php — feature flags for the archive-poc surfaces.
 *
 * A TRACKED PHP FILE, required via __DIR__ — deliberately not env[] / getenv()
 * and not fastcgi_param. Two flag mechanisms have failed on this platform
 * before: a value set in an FPM pool is invisible to anything not served by
 * that pool, and a value set by fastcgi_param lands in $_SERVER only, never in
 * getenv(), so a getenv()-only read serves OFF on the very URL built to
 * demonstrate it. A constant in a file both consumers require has neither
 * failure mode, and it is the same file the gate reads, so the gate and the
 * running code can never disagree about what is switched on.
 *
 * Guarded with defined() so a gate (or a probe) can force the state for one
 * run by defining the constant before this file loads.
 */

declare(strict_types=1);

/**
 * Per-box overrides, loaded FIRST so their defined() guards win over the
 * tracked defaults below (and gate/probe pre-definitions win over both).
 * The file is untracked and gitignored: dev2 uses it to run a flag ON for
 * Ian's look while the tracked default stays false — so an ordinary live
 * pull can NEVER switch a member-facing behaviour on unverified. This is
 * the lesson of 2026-08-15: an "ON for dev2" edit to a tracked constant is
 * an ON for every box that pulls, whether anyone meant it or not.
 */
if (is_file(__DIR__ . '/_flags.local.php')) {
    require __DIR__ . '/_flags.local.php';
}

/**
 * Backlog 22 (Ian 2026-08-14): the daily Guitardle attempt is claimed
 * SERVER-SIDE against the member account at the START of a game, so a second
 * device or an incognito window gets the same day back rather than a fresh set
 * of tries. Carries the cross-device RESUME, and the two honest logged-out
 * copy lines ("Sign in to claim a spot" on the promo card, "Playing for fun"
 * in the game).
 *
 * OFF by default. The OFF path writes no claim row, reads finished results
 * with an explicit `moves IS NOT NULL`, and returns byte-identical JSON --
 * asserted per state by tools/gates/guitardle-claim-gate.py (gate 37).
 *
 * Turning it OFF again is a TWO-step procedure -- see the rollback note at the
 * bottom of archive-poc/sql/guitardle-claim.pg.sql.
 */
if (!defined('LG_GUITARDLE_DAILY_CLAIM')) {
    // ON since 2026-08-15 — Ian, after the dev2 preview + merge: "Flip it on
    // dev2" (decision box, keeper turn). Verified on the dev2 serve same night.
    define('LG_GUITARDLE_DAILY_CLAIM', true);
}

/**
 * The game's HOW TO PLAY overlay (Ian 2026-08-15, keeper split 2026-08-15).
 *
 * Deliberately its OWN flag rather than riding the fairness one. The rules are
 * pure help copy with no data path, and the game shipped with NO rules surface
 * at all -- the prototype's instructions overlay was dropped, assets/
 * icon-question.svg went unreferenced, and Hardcore (which DOUBLES weekly
 * points) was explained only by a title= tooltip, which touch never renders.
 * Tying that to the attempt-claim work would have meant members could not read
 * the rules until the fairness change was switched on, which is backwards: the
 * instructions are the safer of the two and should be free to go first.
 *
 * OFF by default. OFF keeps the button display:none and the overlay unreachable,
 * so the chrome is byte-identical -- asserted per state by gate 37.
 */
if (!defined('LG_GUITARDLE_HOW_TO_PLAY')) {
    // ON since 2026-08-15 — same ruling as the daily claim above; the rules
    // overlay was split to its own flag precisely so it could ship first.
    define('LG_GUITARDLE_HOW_TO_PLAY', true);
}

/**
 * Backlog 24 — a result must not be lost to an EXPIRED NONCE.
 *
 * Measured on live over 7 days: 101 finished games POSTed, 8 came back 403,
 * from 8 distinct IPs across 6 days. A WP nonce lives ~12h; the game sits in a
 * front-page iframe people leave open, so a tab opened last night and played
 * this morning carries a dead nonce. postScore() ended in `.catch(() => {})`,
 * so the player saw their win card, believed it counted, and it never reached
 * the board. Roughly 1 game in 12, landing hardest on the members who play most
 * -- the opposite fairness failure to the one this lane started with.
 *
 * With the flag ON every nonce-bearing call re-fetches a fresh nonce on a 403
 * and retries ONCE. It matters for `start` as much as for the finish: a
 * start-claim lost to a stale nonce means the day is never claimed, and the
 * whole allowance fix silently does not apply to that game.
 *
 * OFF by default; OFF keeps the original fire-and-forget behaviour exactly.
 */
if (!defined('LG_GUITARDLE_SCORE_RETRY')) {
    define('LG_GUITARDLE_SCORE_RETRY', false);
}

/**
 * Backlog 25 (option A, keeper 2026-08-15) — SERVER-DRIVEN PLAY.
 *
 * The leaderboard's inputs were all client-supplied: moves, won and hardcore
 * came straight out of the POST body, and hardcore DOUBLES points, so anyone
 * holding their own nonce could post a 20-point day. But server-side scoring
 * alone would not have fixed it, because THE ANSWER KEY IS PUBLIC: the game
 * fetched sequence.json and guitardle_phrases.csv into the browser and decided
 * the win in JS, so all 285 phrases and the full fixed sequence -- today and
 * every future day -- are readable by anyone. A player reading the key
 * genuinely solves in one move, and an honest server would score it 20.
 *
 * So the phrase stops reaching the client. With this ON:
 *   - the client is given the board SHAPE only (word lengths), never letters
 *   - each reveal is a server call that returns positions and counts the move
 *   - the guess is judged server-side against the phrase
 *   - moves / won / hardcore are what the SERVER watched happen; the values in
 *     the POST body are ignored entirely
 *
 * Requires LG_GUITARDLE_DAILY_CLAIM, whose claim row is the play session.
 *
 * RESIDUE, deliberately not closed here and reported as its own backlog item:
 * the two asset files must KEEP being served while this flag is OFF, because
 * the old path needs them -- so the key stays readable until the flag is ON
 * everywhere and the assets are then restricted. That is a two-stage deploy.
 */
if (!defined('LG_GUITARDLE_SERVER_PLAY')) {
    // Tracked default is FALSE. dev2 runs it ON via _flags.local.php (Ian,
    // 2026-08-15 decision box) — kept out of this tracked file so assembling
    // live paste 2 could not silently switch cheat-proofing on for live.
    // The live flip is its own paste line once Ian has looked at dev2.
    define('LG_GUITARDLE_SERVER_PLAY', false);
}

/**
 * Backlog 26 (keeper 2026-08-15) — the LOGGED-OUT game stops fetching the
 * answer key too.
 *
 * Server play closed the member half, but it could not remove
 * assets/sequence.json and guitardle_phrases.csv, because the logged-out game
 * still fetches them to draw its board and judge its own guess. Those two files
 * are 285 phrases plus the FIXED order, so a member who opens them solves in
 * one move for a score the server now records HONESTLY -- ~140 points a week
 * measured against a real weekly leader on 62. Server-side scoring did not fix
 * that; only removing the files does.
 *
 * ON: the logged-out client asks guitardle-puzzle.php for ONE day's phrase on
 * ITS OWN track. No sequence, no library, no other day, no member track.
 *
 * THIS FLAG DOES NOT DELETE THE FILES, and must not: it is stage one of two.
 * The assets can only be removed once BOTH this and LG_GUITARDLE_SERVER_PLAY
 * are on everywhere -- until then a member on the legacy path still needs its
 * own track's letters to draw a board at all. Pulling them early is a blank
 * game, not a degraded one.
 */
if (!defined('LG_GUITARDLE_DAY_PUZZLE')) {
    define('LG_GUITARDLE_DAY_PUZZLE', false);
}
