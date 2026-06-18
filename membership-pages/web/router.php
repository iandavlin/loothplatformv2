<?php
/**
 * router.php — single front controller for ALL membership-page slugs.
 *
 * Mirrors archive-poc's single render.php: ONE nginx location matches every
 * membership slug and fastcgi-passes here with the slug as a param; this file
 * dispatches by slug to the matching page file. Replaces the former
 * one-nginx-block-per-slug birds-nest (Ian 2026-06-03: NO nginx birds-nest).
 *
 * Responsibilities (kept here so the page files stay dumb body-renderers):
 *   1. Resolve the slug (fastcgi_param LG_MS_SLUG, with a REQUEST_URI fallback).
 *   2. Look it up in the PAGES registry → [file, visibility].
 *   3. Build the shared-header ctx and apply the pre-launch admin gate per the
 *      registry's visibility ('admin' = manage_options-only; 'member' / 'public'
 *      pass through). This is the AUTHORITATIVE gate — page files may also gate
 *      defensively (harmless: an admin passes both, a non-admin exits here first).
 *   4. include the page file, which renders its own verbatim shortcode body
 *      wrapped in the shared shell.
 *
 * The page files remain self-contained front controllers (they re-require
 * config / whoami / header / footer — all idempotent via include-guards), so a
 * single file can still be smoke-tested in isolation if needed.
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/whoami.php';
require '/srv/lg-shared/site-header.php';
require '/srv/lg-shared/site-footer.php';
require __DIR__ . '/_admin-gate.php';

/**
 * slug => [page file (relative to web/), prelaunch_visibility, live_visibility]
 *
 * TWO visibility columns + a global flag (`lgms_stripe_pages_live`, default off)
 * pick which column applies — so Ian can flip the Stripe purchase pages public
 * at go-live with NO code edit (toggle in the poller's WP-admin settings):
 *   - flag OFF  → use prelaunch_visibility ('admin' = manage_options-only while
 *                 the Stripe op is built privately).
 *   - flag ON   → use live_visibility (the page's real go-live audience).
 *
 * visibility value (each column):
 *   'admin'  — manage_options-only. The only enforced gate in this router.
 *   'member' — logged-in audience; passes the router gate (the page gates its
 *              own member content). manage-subscription is the standing example.
 *   'public' — anyone past the dev cookie gate (Patreon funnel + the transient
 *              post-checkout landings + the Stripe purchase funnels at go-live).
 *
 * test-checklist is 'admin' in BOTH columns → stays admin-only regardless of the
 * flag (internal QA surface, never public). Pages that are already live (join,
 * manage-subscription) carry the same value in both columns — the flag is a
 * no-op for them.
 *
 * NOTE: the live_visibility values for the flippable purchase pages are pending
 * Ian's which-pages-flip decision (DECISION-NEEDED); the flag mechanism is final.
 */
const LG_MS_PAGES = [
    //                                  [ file,                                prelaunch,  live      ]
    // built surfaces (verbatim ports already landed)
    'membership-guide'               => ['membership-guide.php',               'admin',  'public'],
    'manage-subscription'            => ['manage-subscription.php',            'member', 'member'],  // already live; flag no-op
    // PUBLIC both columns (Ian 2026-06-12): logged-OUT patrons must reach the
    // connect instructions — it's the landing for "already a patron?" links.
    'connect-your-patreon'           => ['connect-your-patreon.php',           'public', 'public'],
    'affiliate-earnings'             => ['affiliate-earnings.php',             'admin',  'member'],
    'request-refund'                 => ['request-refund.php',                 'admin',  'member'],
    'welcome'                        => ['welcome.php',                        'admin',  'public'],  // transient post-checkout landing
    'regional-pricing-not-available' => ['regional-pricing-not-available.php', 'admin',  'public'],  // transient
    'join'                           => ['join.php',                           'public', 'public'],  // already live; flag no-op

    // scaffolded surfaces (shell + gate live; verbatim body port pending)
    'lgjoin'                         => ['lgjoin.php',                         'admin',  'public'],
    'lggift-buy'                     => ['lggift-buy.php',                     'admin',  'public'],
    'lggift'                         => ['lggift.php',                         'admin',  'public'],
    'my-gifts'                       => ['my-gifts.php',                       'admin',  'member'],
    'test-checklist'                 => ['test-checklist.php',                 'admin',  'admin'],   // QA surface — admin ALWAYS
];

/* ── Resolve the slug ─────────────────────────────────────────────────── */
$slug = (string) ($_SERVER['LG_MS_SLUG'] ?? '');
if ($slug === '') {
    // Fallback when invoked without the fastcgi param (CLI smoke / direct hit):
    // take the first path segment of the request URI.
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    $slug = trim($path, '/');
    if (str_contains($slug, '/')) {
        $slug = explode('/', $slug, 2)[0];
    }
}
$slug = (string) preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

/* ── Dispatch ─────────────────────────────────────────────────────────── */
if (!isset(LG_MS_PAGES[$slug])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "membership-pages: no such surface\n";
    exit;
}

[$file, $prelaunch_vis, $live_vis] = LG_MS_PAGES[$slug];
$target = __DIR__ . '/' . $file;
if (!is_readable($target)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    error_log("membership-pages router: missing page file for slug '$slug' ($target)");
    echo "membership-pages: surface unavailable\n";
    exit;
}

// The global admin toggle picks which visibility column applies. Default off
// (fail-safe → prelaunch/admin) so a DB hiccup never exposes a half-built page.
$visibility = lg_membership_stripe_pages_live() ? $live_vis : $prelaunch_vis;

$ctx = lg_membership_header_ctx('');                 // §0a: no top-nav slot for membership
if ($visibility === 'admin') {
    lg_membership_admin_gate_or_exit($ctx);          // non-admins get a stub page + exit
}

require $target;
