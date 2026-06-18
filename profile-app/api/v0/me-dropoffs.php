<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * RETIRED 2026-06-11 (Ian): the Drop-off Locations block is removed from the
 * PROFILE block model (palette, renderer dispatch, editor). This endpoint only
 * served the profile block — practice/storefront drop-offs are a separate
 * surface (me-practice-block?block=dropoffs) and are untouched. Existing
 * profile 'dropoffs' rows stay in profile_sections (inert; normalizeLayout
 * prunes the key from layouts) in case of a product reversal.
 */
profile_app_json(410, ['error' => 'gone', 'detail' => 'the profile drop-offs block was removed 2026-06-11']);
