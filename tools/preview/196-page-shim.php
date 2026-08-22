<?php
/**
 * 196-page-shim.php — make the lane preview of /switch-billing/ wear the
 * BRANCH's header instead of main's.
 *
 * Every membership page requires '/srv/lg-shared/site-header.php', and
 * /srv/lg-shared is a symlink into the SERVING CHECKOUT — so a plain preview
 * renders the branch's page under MAIN'S header code
 * (trap-harness-and-serve-answer-from-main). This loads the branch's partials
 * FIRST; both files wrap every definition in `if (!function_exists(...))`, so
 * the page's own `require '/srv/…'` still runs and defines nothing. Nothing is
 * patched, nothing is stubbed, and the router below is the real one.
 *
 * ⚠️ IT DOES NOT — AND MUST NOT — MAKE THE PREVIEW'S HEADER SAY "Switch", AND
 * THE REASON IS THE POINT. The swap keys on $caps['patreon_paying'], which is
 * computed in WORDPRESS by the poller's InternalRestController and carried here
 * over /whoami. dev2's WordPress runs from the serving checkout, which serves
 * main, and main does not compute that capability at all. So on this preview a
 * real member's real ctx correctly carries no such capability and the header
 * correctly falls closed to Join — the fail-closed behaviour working, observed.
 *
 * Faking the capability here would turn the one honest preview into a mock of
 * itself. The menu is looked at through /preview/196-switch-menu/header/, which
 * renders the same partial against a stated ctx; the wiring between the two is
 * what gate 93 asserts. Both halves land in the same pull, so this gap exists
 * only until the merge.
 *
 * PREVIEW-ONLY. It is reachable solely through
 * platform/nginx/lane-preview-196-switch-menu.conf, itself behind the dev gate,
 * and no shipped code path includes it.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/lg-shared/site-header.php';
require dirname(__DIR__, 2) . '/lg-shared/site-footer.php';
require dirname(__DIR__, 2) . '/membership-pages/web/router.php';
