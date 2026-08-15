<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Internal: the featured-member SELECTABLE POOL, for the WP admin dash.
 * Backlog 18 (Ian 8/11), design rulings 8/14 (docs/IAN-RULINGS-2026-08-14.md
 * item 6). Piece #2 of three.
 *
 * Same shape as internal-byline-socials.php / internal-recap.php: the WP admin
 * page renders server-side on the WP pool, which cannot reach the profile_app
 * database — it asks here instead. Loopback + X-LG-Internal-Auth, same as every
 * other /internal/ endpoint (Whoami::verifyInternalAuth against
 * /etc/lg-internal-secret).
 *
 * Returns EVERY member who has opted in, regardless of their CURRENT profile
 * visibility — not just the currently-eligible ones. Ian's ADMIN DASH ruling
 * is a pool the admin can SEE, and the dash mock (dash.html) draws exactly this
 * case: a member who opted in and later went Private stays listed, marked
 * `eligible: false`, so the dash can explain why Feature is unavailable rather
 * than have them silently vanish. Their opt-in is untouched; they return to
 * `eligible: true` the moment they go Public again — this endpoint has nothing
 * to write, it only ever reports the live state.
 *
 * GET (no body) → { pool: [ { uuid, slug, display_name, avatar_url, tagline,
 *                              location, eligible, opted_in_at,
 *                              completeness: {...} }, ... ] }
 *   Sorted oldest-opted-in-first, matching the dash mock.
 *
 * `tagline` and `location` here are NOT visibility-filtered — this is a
 * trusted internal admin surface, same posture as Whoami's fuller internal
 * payloads. The PUBLIC card (built elsewhere, when a member is actually
 * selected) is what applies the public-facing visibility rules.
 */

use Looth\ProfileApp\Completeness;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Whoami;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);
if (!Whoami::verifyInternalAuth()) profile_app_json(401, ['error' => 'bad_secret']);

$pg = Db::pg();
$rows = $pg->query(
    "SELECT id, uuid, slug, display_name, avatar_url, at_a_glance, business_name,
            location_city, location_region, profile_visibility, featured_opt_in_at
       FROM users
      WHERE featured_opt_in = true
      ORDER BY featured_opt_in_at ASC NULLS LAST"
)->fetchAll();

$pool = [];
foreach ($rows as $r) {
    $uid = (int) $r['id'];
    $tagline = trim((string) $r['at_a_glance']);
    if ($tagline === '') {
        $biz = Completeness::deEscape($r['business_name']);
        // Same "is it just a slice of the display name" test Completeness uses
        // for the score — a business_name that IS the display name's tail is
        // not a tagline, it is the same three words twice.
        if ($biz !== '' && !str_ends_with((string) $r['display_name'], $biz)) $tagline = $biz;
    }
    $loc = trim(implode(', ', array_filter([$r['location_city'], $r['location_region']])));

    $pool[] = [
        'uuid'          => $r['uuid'],
        'slug'          => $r['slug'],
        'display_name'  => $r['display_name'],
        'avatar_url'    => $r['avatar_url'],
        'tagline'       => $tagline,
        'location'      => $loc,
        'eligible'      => $r['profile_visibility'] === 'public',
        'opted_in_at'   => $r['featured_opt_in_at'],
        'completeness'  => Completeness::forUser($uid),
    ];
}

profile_app_json(200, ['pool' => $pool]);
