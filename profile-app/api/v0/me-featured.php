<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Featured-member opt-in — backlog 18 (Ian 8/11), all design rulings 8/14
 * (docs/IAN-RULINGS-2026-08-14.md item 6). Piece #1 of three: this is the
 * MEMBER'S OWN consent + the completeness meter that goes with it.
 *
 *   GET → { featured_opt_in, featured_opt_in_at, completeness: {...} }  (self)
 *   PUT → { featured_opt_in: bool } sets it.
 *
 * Consent is explicit, opt-in, default false, NEVER inferred — the whole
 * reason this is its own column rather than derived from anything else (see
 * the migration, sql/2026-08-15-featured-opt-in.sql). Ian ruled "accept any
 * %% — tick welcomed at any completeness, no floor", so PUT never refuses a
 * true based on the score; `completeness` is returned on EVERY call (opted in
 * or not) so the caller can show the card preview either side of the tickbox,
 * per the ruling's "show the card preview" half.
 *
 * Gated: while platform/config/featured-members.php is off, PUT refuses —
 * nobody can opt in to a feature whose UI does not exist, and no admin action
 * downstream (the dash pool, the front-page resolve) can ever see a fresh
 * consent from a build that never shipped. GET stays open; it is read-only
 * and every value defaults false, so there is nothing an OFF flag needs to
 * hide from a member reading their own row.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Completeness;
use Looth\ProfileApp\Db;

function me_featured_flag_on(): bool
{
    $cfg = @include __DIR__ . '/../../../platform/config/featured-members.php';
    $on  = is_array($cfg) && !empty($cfg['enabled']);
    foreach ([getenv('LG_FEATURED_MEMBERS'), $_SERVER['LG_FEATURED_MEMBERS'] ?? false] as $o) {
        if ($o !== false && $o !== '') $on = ($o === '1' || $o === 'true');
    }
    return $on;
}

$user   = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];
$pg     = Db::pg();
$uid    = (int) $user['id'];

if ($method === 'GET') {
    $st = $pg->prepare('SELECT featured_opt_in, featured_opt_in_at FROM users WHERE id = :i');
    $st->execute([':i' => $uid]);
    $row = $st->fetch();
    if ($row === false) profile_app_json(404, ['error' => 'not_found']);

    profile_app_json(200, [
        'featured_opt_in'    => (bool) $row['featured_opt_in'],
        'featured_opt_in_at' => $row['featured_opt_in_at'],
        'completeness'       => Completeness::forUser($uid),
    ]);
}

if ($method !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

if (!me_featured_flag_on()) {
    profile_app_json(403, ['error' => 'feature_disabled']);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in) || !array_key_exists('featured_opt_in', $in)) {
    profile_app_json(400, ['error' => 'featured_opt_in_required']);
}
$want = $in['featured_opt_in'];
if (!is_bool($want)) profile_app_json(400, ['error' => 'featured_opt_in_must_be_bool']);

// Stamp featured_opt_in_at only on a real false->true TRANSITION (not every
// idempotent PUT true) — see the migration's column comment. An untick always
// nulls it; the check constraint requires that pairing.
$cur = $pg->prepare('SELECT featured_opt_in FROM users WHERE id = :i');
$cur->execute([':i' => $uid]);
$was = (bool) $cur->fetchColumn();

if ($want) {
    if ($was) {
        $up = $pg->prepare('UPDATE users SET updated_at = now() WHERE id = :i');
    } else {
        $up = $pg->prepare('UPDATE users SET featured_opt_in = true, featured_opt_in_at = now(), updated_at = now() WHERE id = :i');
    }
} else {
    $up = $pg->prepare('UPDATE users SET featured_opt_in = false, featured_opt_in_at = NULL, updated_at = now() WHERE id = :i');
}
$up->execute([':i' => $uid]);

$st = $pg->prepare('SELECT featured_opt_in, featured_opt_in_at FROM users WHERE id = :i');
$st->execute([':i' => $uid]);
$row = $st->fetch();

profile_app_json(200, [
    'ok'                  => true,
    'featured_opt_in'     => (bool) $row['featured_opt_in'],
    'featured_opt_in_at'  => $row['featured_opt_in_at'],
    'completeness'        => Completeness::forUser($uid),
]);
