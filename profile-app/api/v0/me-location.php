<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';   // not in config.php's require list (yet)

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

$user   = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];

// GET → the assembled two-tier location block (mirrors me-header GET).
if ($method === 'GET') {
    $block = Block::loadLocation((int)$user['id']);
    if ($block === null) profile_app_json(404, ['error' => 'not_found']);
    // Removing the Location section from the profile opts the owner off the
    // map entirely (the directory enforces that server-side). Surface it here
    // so own-pin consumers (front-page You pin) honor it too — additive flags,
    // the editor's GET shape is unchanged (Ian 6/12).
    //   in_layout  — 'location' is in the effective layout (default or saved)
    //   opted_out  — the owner SAVED a layout that omits location (deliberate
    //                stow). A never-customized profile whose default simply
    //                lacks the section is NOT an opt-out — those members get
    //                the join-the-map nudge instead of the silent teaser.
    $block['in_layout'] = in_array('location', Block::profileLayout((int)$user['id']), true);
    $st = Db::pg()->prepare('SELECT (profile_layout IS NOT NULL)::int FROM users WHERE id = :i');
    $st->execute([':i' => (int)$user['id']]);
    $block['opted_out'] = !$block['in_layout'] && (bool)(int)$st->fetchColumn();
    profile_app_json(200, $block);
}

if ($method !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);

// Three accepted shapes — at most one per call:
//
//   1. Picker pick (Nominatim row):
//      { nominatim: { display_name, lat, lon, address: {...} } }
//
//   2. Text-only escape hatch (zero-results "save anyway"):
//      { text_only: "<freeform text>" }
//
//   3. Visibility toggle (single-field autosave):
//      { location_visibility: 'public' | 'members' | 'private' }
//
// Any combination is allowed in one call (the editor sends them separately
// in practice, but we don't enforce that here).

$nominatim   = $in['nominatim']           ?? null;
$textOnly    = $in['text_only']           ?? null;
$visibility  = $in['location_visibility'] ?? null;

$set    = [];
$params = [':id' => (int)$user['id']];

if (is_array($nominatim)) {
    $parsed = parse_nominatim($nominatim);
    if ($parsed === null) profile_app_json(400, ['error' => 'invalid_nominatim_row']);
    $set[] = 'location_text     = :text';
    $set[] = 'lat               = :lat';
    $set[] = 'lng               = :lng';
    $set[] = 'location_country  = :country';
    $set[] = 'location_region   = :region';
    $set[] = 'location_city     = :city';
    $set[] = 'location_postcode = :postcode';
    $set[] = 'place_id          = NULL';
    $set[] = 'place_result      = :raw::jsonb';
    $params += [
        ':text'     => $parsed['text'],
        ':lat'      => $parsed['lat'],
        ':lng'      => $parsed['lng'],
        ':country'  => $parsed['country'],
        ':region'   => $parsed['region'],
        ':city'     => $parsed['city'],
        ':postcode' => $parsed['postcode'],
        ':raw'      => json_encode($nominatim, JSON_UNESCAPED_SLASHES),
    ];
}

if (is_string($textOnly) && trim($textOnly) !== '') {
    if (!empty($set)) profile_app_json(400, ['error' => 'conflicting_fields']);
    $set[] = 'location_text     = :text';
    $set[] = 'lat               = NULL';
    $set[] = 'lng               = NULL';
    $set[] = 'location_country  = NULL';
    $set[] = 'location_region   = NULL';
    $set[] = 'location_city     = NULL';
    $set[] = 'location_postcode = NULL';
    $set[] = 'place_id          = NULL';
    $set[] = 'place_result      = NULL';
    $params[':text'] = trim($textOnly);
}

if (is_string($visibility)) {
    if (!in_array($visibility, Profile::LOCATION_VIS_VALUES, true)) {
        profile_app_json(400, ['error' => 'invalid_visibility']);
    }
    $set[] = 'location_visibility = :vis';
    $params[':vis'] = $visibility;
}

// --- increment 2: the location block's exact tier + user-managed pin ---
//
//   4. Exact-tier visibility (single-field autosave; members|private|on_request,
//      accepts the UI 'member'):  { location_exact_visibility: 'member'|'private'|'on_request' }
//   5. Display precision (exact → neighborhood → city):  { precision: '...' }
//   6. User-MANAGED pin placement (drag/drop the exact point):  { pin: { lat, lng } }
//      Conflicts with nominatim/text_only (all touch lat/lng) — at most one per call.

if (array_key_exists('location_exact_visibility', $in)) {
    $ev = Block::exactVisFromInput($in['location_exact_visibility']);   // → DB literal
    if ($ev === null) {
        profile_app_json(400, ['error' => 'invalid_exact_visibility', 'allowed' => ['member', 'private', 'on_request']]);
    }
    $set[] = 'location_exact_visibility = :evis';
    $params[':evis'] = $ev;
}

if (array_key_exists('precision', $in)) {
    if (!in_array($in['precision'], Block::PRECISION_VALUES, true)) {
        profile_app_json(400, ['error' => 'invalid_precision', 'allowed' => Block::PRECISION_VALUES]);
    }
    $set[] = 'location_pin_precision = :prec';
    $params[':prec'] = $in['precision'];
}

// Per-audience precision (Ian's model): private|state|city|street.
if (array_key_exists('members_precision', $in)) {
    if (Block::precisionFromInput($in['members_precision']) === null) {
        profile_app_json(400, ['error' => 'invalid_members_precision', 'allowed' => Block::LOCATION_PRECISION]);
    }
    $set[] = 'location_members_precision = :mprec';
    $params[':mprec'] = $in['members_precision'];
}
if (array_key_exists('public_precision', $in)) {
    if (Block::precisionFromInput($in['public_precision']) === null) {
        profile_app_json(400, ['error' => 'invalid_public_precision', 'allowed' => Block::LOCATION_PRECISION]);
    }
    $set[] = 'location_public_precision = :pprec';
    $params[':pprec'] = $in['public_precision'];
}

if (array_key_exists('pin', $in)) {
    if ($nominatim !== null || (is_string($textOnly) && trim($textOnly) !== '')) {
        profile_app_json(400, ['error' => 'conflicting_fields']);   // pin + place/text all set lat/lng
    }
    $pin = $in['pin'];
    if (!is_array($pin) || !isset($pin['lat'], $pin['lng']) || !is_numeric($pin['lat']) || !is_numeric($pin['lng'])) {
        profile_app_json(400, ['error' => 'invalid_pin']);
    }
    $plat = (float)$pin['lat'];
    $plng = (float)$pin['lng'];
    if ($plat < -90 || $plat > 90 || $plng < -180 || $plng > 180) {
        profile_app_json(400, ['error' => 'pin_out_of_range']);
    }
    $set[] = 'lat = :plat';
    $set[] = 'lng = :plng';
    $params[':plat'] = $plat;
    $params[':plng'] = $plng;
}

// Owner-set extras (address detail / hours / note) — stored in profile_sections
// key='location' data JSONB, not the users table. May be the sole change in a call.
$gotDetails = false;
if (array_key_exists('details', $in) && is_array($in['details'])) {
    $d = $in['details'];
    Block::saveLocationExtras(
        (int)$user['id'],
        array_key_exists('address', $d) ? (string)$d['address'] : null,
        array_key_exists('hours',   $d) ? (string)$d['hours']   : null,
        array_key_exists('note',    $d) ? (string)$d['note']    : null
    );
    $gotDetails = true;
}

if (empty($set) && !$gotDetails) profile_app_json(400, ['error' => 'no_fields']);

if (!empty($set)) {
    $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id';
    Db::pg()->prepare($sql)->execute($params);
}

// Return the re-assembled block so the editor (and the round-trip test) can read
// back both tiers in one call.
profile_app_json(200, ['ok' => true, 'location' => Block::loadLocation((int)$user['id'])]);


/**
 * Map a Nominatim search-result row to our typed location columns.
 *
 * Nominatim shape (with addressdetails=1):
 *   { lat, lon, display_name, address: {
 *       city|town|village|hamlet, state, country, postcode, country_code, …
 *     } }
 */
function parse_nominatim(array $row): ?array
{
    if (!isset($row['display_name'])) return null;
    $lat = isset($row['lat']) ? (float)$row['lat'] : null;
    $lng = isset($row['lon']) ? (float)$row['lon'] : null;
    $a = $row['address'] ?? [];
    $city = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['hamlet'] ?? $a['suburb'] ?? null;
    return [
        'text'     => (string)$row['display_name'],
        'lat'      => $lat,
        'lng'      => $lng,
        'country'  => $a['country']  ?? null,
        'region'   => $a['state']    ?? $a['region'] ?? null,
        'city'     => $city,
        'postcode' => $a['postcode'] ?? null,
    ];
}
