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
    define('LG_GUITARDLE_DAILY_CLAIM', false);
}
