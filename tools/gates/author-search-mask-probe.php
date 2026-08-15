<?php
/**
 * author-search-mask-probe — the PHP half of tools/gates/author-search-mask-gate.py.
 *
 * Drives the REAL _suggest.php author branch (included, never re-implemented) and
 * prints its JSON on stdout.
 * Two things make this honest:
 *   - Under CLI, lg_bb_mirror_whoami() returns null by its own first line, so this
 *     is ALWAYS the anonymous audience — which is exactly the audience the
 *     backlog-27 defect is about. It cannot accidentally test as a member.
 *   - The flag moves via $_SERVER, the documented override, so both states are
 *     exercised without ever editing the tracked config on disk.
 *
 * Must run as the bb-mirror user: bb_mirror_db() uses peer auth.
 *
 * argv: <repo-root> <off|on> <query>
 */
$root = $argv[1]; $state = $argv[2] ?? 'off'; $q = $argv[3] ?? 'erlewine';
// 'on' uses the documented $_SERVER override. 'off' FORCES the old behaviour via a
// test-only constant, because the override is one-way (it can only turn the flag ON)
// and without a forced-off the gate would break the day the tracked default flips —
// the "a gate must READ the flag, not hardcode the state" rule.
if ($state === 'on')  { $_SERVER['LG_AUTHOR_SEARCH_MASK'] = '1'; }
if ($state === 'off') { define('LG_AUTHOR_SEARCH_MASK_FORCE_OFF', true); }
require_once $root . '/bb-mirror/config.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['suggest' => 'author', 'q' => $q];
include $root . '/bb-mirror/web/forums/_suggest.php';
