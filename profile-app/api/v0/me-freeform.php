<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * RETIRED 2026-06-11 (Ian): freeform sections are removed from the profile
 * block model — palette entry, "+ New section" affordance, renderer and the
 * Block machinery are all gone (mirror-the-UI rule). Existing freeform rows
 * stay in profile_sections (inert, never listed or rendered) in case of a
 * product reversal; a cut-day sweep can purge them.
 */
profile_app_json(410, ['error' => 'gone', 'detail' => 'freeform sections were removed 2026-06-11']);
