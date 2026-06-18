<?php
declare(strict_types=1);

/**
 * bin/fix-divergent-locations.php — repair imported location SECTIONS whose
 * structured data contradicts the member's own words (Ian 6/12 go: "fix their
 * location section, don't just move the pin").
 *
 * Root cause (the karrikercustoms case): BuddyPress stored the typed text and
 * a separately-cached geocode pin (usermeta geocode_96); when a member moved,
 * the text updated but the cached pin never re-geocoded. Our snapshot copied
 * both literally, importing the disagreement: location_text says one place,
 * city/region/lat/lng say another.
 *
 * What this does, per flagged member (docs/map-divergent-2026-06-12.json):
 *   1. source of truth = the member's CURRENT BB "Your Location" text
 *      (xprofile field 96, read live; falls back to users.location_text) —
 *      always the member's own words, never a geocoder rewrite.
 *   2. geocode that text (Nominatim, same pattern as bin/geocode.php).
 *   3. only when the stored pin is > 25 mi from where the text geocodes
 *      (or the pin is missing) → rewrite the STRUCTURED section so it all
 *      agrees: location_text (their words), city/region/country/postcode,
 *      lat/lng. Cosmetic name differences (Montréal vs Montreal) are skipped.
 *
 * HARD GUARDS:
 *   - place_result IS NULL only — a member who picked in the new editor is
 *     NEVER touched (their pick wins; the editor is the self-correct path).
 *   - place_result / place_id are NOT written: the "never picked via editor"
 *     signal stays honest (it drives the members-only default).
 *   - privacy dials / precisions untouched.
 *
 * Dry-run by default; --apply writes. Run as ubuntu (shells out to
 * `sudo mysql` for the live BB read and `sudo -u profile-app psql` happens
 * via PDO — config.php connects per-process user, so run the WRITES via:
 *   sudo -u profile-app php bin/fix-divergent-locations.php [--apply]
 */

require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;

const NOMINATIM_UA = 'looth-profile-app/0.3 (admin: ian.davlin@gmail.com)';
const SLEEP_USEC   = 1100000;        // ≤1 rps per Nominatim policy
const THRESHOLD_MI = 25.0;
const AUDIT_FILE   = '/home/ubuntu/projects/docs/map-divergent-2026-06-12.json';

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }
$apply = in_array('--apply', $argv, true);

function nominatim(string $q): ?array {
    $url = 'https://nominatim.openstreetmap.org/search?'
         . http_build_query(['q' => $q, 'format' => 'json', 'limit' => 1, 'addressdetails' => 1]);
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => 'User-Agent: ' . NOMINATIM_UA . "\r\nAccept: application/json\r\n",
        'timeout' => 15,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return null;
    $d = json_decode($resp, true);
    return is_array($d) && !empty($d) ? $d[0] : null;
}

function haversine_mi(float $la1, float $lo1, float $la2, float $lo2): float {
    $r = 3958.8;
    $dLa = deg2rad($la2 - $la1); $dLo = deg2rad($lo2 - $lo1);
    $a = sin($dLa/2)**2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo/2)**2;
    return $r * 2 * asin(min(1.0, sqrt($a)));
}

/** Member's current BB "Your Location" (their own words), '' if none. */
function bb_text(int $wpId): string {
    $out = shell_exec('sudo mysql -N -e ' . escapeshellarg(
        "SELECT value FROM wp_bp_xprofile_data WHERE user_id=$wpId AND field_id=96 LIMIT 1"
    ) . ' looth_import 2>/dev/null');
    return trim((string)$out);
}

$audit = json_decode((string)file_get_contents(AUDIT_FILE), true);
if (!is_array($audit) || !$audit) { fwrite(STDERR, "cannot read audit file\n"); exit(2); }
$emails = array_values(array_unique(array_filter(array_map(
    static fn($r) => strtolower(trim((string)($r['email'] ?? ''))), $audit))));

$pg = Db::pg();
$ph = implode(',', array_fill(0, count($emails), '?'));
$st = $pg->prepare("
    SELECT u.id, u.slug, u.display_name, u.primary_email, u.location_text,
           u.location_city, u.location_region, u.lat, u.lng,
           (u.place_result IS NOT NULL) AS picked, b.wp_user_id
    FROM users u
    LEFT JOIN wp_user_bridge b ON b.user_id = u.id
    WHERE lower(u.primary_email) IN ($ph) AND u.archived_at IS NULL
    ORDER BY u.id");
$st->execute($emails);
$rows = $st->fetchAll();

printf("%d audited emails -> %d matched members (%s)\n\n",
    count($emails), count($rows), $apply ? 'APPLY' : 'dry-run');

$upd = $pg->prepare("
    UPDATE users SET
        location_text = :t, lat = :la, lng = :ln,
        location_city = :ci, location_region = :re,
        location_country = :co, location_postcode = :po,
        updated_at = now()
    WHERE id = :i AND place_result IS NULL");

$n = ['fixed' => 0, 'ok' => 0, 'picked' => 0, 'no_text' => 0, 'no_match' => 0, 'coarse_text' => 0, 'unevidenced' => 0];

foreach ($rows as $r) {
    $who = sprintf('#%d %s', $r['id'], $r['slug'] ?: $r['display_name']);
    if (!empty($r['picked'])) { $n['picked']++; echo "SKIP picked-in-editor  $who\n"; continue; }

    $src = $r['wp_user_id'] ? bb_text((int)$r['wp_user_id']) : '';
    if ($src === '') $src = trim((string)$r['location_text']);
    if ($src === '') { $n['no_text']++; echo "SKIP no source text    $who\n"; continue; }

    $hit = nominatim($src);
    usleep(SLEEP_USEC);
    if ((!$hit || !isset($hit['lat'], $hit['lon'])) && substr_count($src, ',') >= 1) {
        // Full street addresses often miss at limit=1 — retry without the
        // house/street part so the locality can still verify the pin.
        $fallback = trim(implode(',', array_slice(explode(',', $src), 1)));
        if ($fallback !== '') { $hit = nominatim($fallback); usleep(SLEEP_USEC); }
    }
    if (!$hit || !isset($hit['lat'], $hit['lon'])) {
        $n['no_match']++; echo "SKIP geocode no-match  $who  («{$src}»)\n"; continue;
    }
    $nla = (float)$hit['lat']; $nln = (float)$hit['lon'];
    $ad  = $hit['address'] ?? [];
    $city = (string)($ad['city'] ?? $ad['town'] ?? $ad['village'] ?? $ad['hamlet'] ?? $ad['municipality'] ?? $ad['county'] ?? '');
    $reg  = (string)($ad['state'] ?? $ad['province'] ?? $ad['region'] ?? '');
    $ctry = (string)($ad['country'] ?? '');
    $post = (string)($ad['postcode'] ?? '');

    $dist = ($r['lat'] !== null && $r['lng'] !== null)
        ? haversine_mi((float)$r['lat'], (float)$r['lng'], $nla, $nln)
        : INF;

    if ($dist <= THRESHOLD_MI) { $n['ok']++; continue; }   // cosmetic / already coherent

    // EVIDENCE GUARDS (the don't-enrich rule). A divergent fix must be
    // backed by the member's own words, or it doesn't happen:
    //  - country-level result (no city/region) never overrides a more
    //    specific pin — "España" doesn't contradict a Madrid pin.
    //  - the resolved city or region name must literally appear in the
    //    member's text; a bare-street guess ("2105 W LIVINGSTON ST" →
    //    Allentown?!) is a geocoder hallucination, not evidence.
    if ($city === '' && $reg === '') {
        $n['coarse_text']++; echo "SKIP text coarser than pin  $who  («{$src}»)\n"; continue;
    }
    $evidenced = ($city !== '' && mb_stripos($src, $city) !== false)
              || ($reg  !== '' && mb_stripos($src, $reg)  !== false);
    if (!$evidenced) {
        $n['unevidenced']++; echo "SKIP unevidenced geocode  $who  («{$src}» -> {$city}, {$reg})\n"; continue;
    }

    printf("%s %-34s «%s»\n      old: %-28s (%.4f, %.4f)\n      new: %-28s (%.4f, %.4f)   moved %s mi\n",
        $apply ? 'FIX ' : 'WOULD-FIX', $who, $src,
        trim(($r['location_city'] ?: '?') . ', ' . ($r['location_region'] ?: '?')),
        (float)($r['lat'] ?? 0), (float)($r['lng'] ?? 0),
        trim(($city ?: '?') . ', ' . ($reg ?: '?')), $nla, $nln,
        is_finite($dist) ? sprintf('%.0f', $dist) : 'n/a (no old pin)');

    if ($apply) {
        $upd->execute([
            ':t' => $src, ':la' => $nla, ':ln' => $nln,
            ':ci' => $city ?: null, ':re' => $reg ?: null,
            ':co' => $ctry ?: null, ':po' => $post ?: null,
            ':i'  => (int)$r['id'],
        ]);
    }
    $n['fixed']++;
}

printf("\nsummary: %s=%d coherent=%d picked-skipped=%d no-text=%d no-match=%d coarse-text=%d unevidenced=%d\n",
    $apply ? 'fixed' : 'would-fix', $n['fixed'], $n['ok'], $n['picked'], $n['no_text'], $n['no_match'],
    $n['coarse_text'], $n['unevidenced']);
