<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

use PDO;

/**
 * Profile read/write helpers. Owns the viewer-role + precision logic so the
 * editor preview and the public read endpoint stay in sync.
 */
final class Profile
{
    public const VIS_VALUES        = ['public', 'members', 'private'];
    public const LOCATION_VIS_VALUES = ['public', 'members', 'private'];
    public const SOCIAL_KINDS      = ['instagram','youtube','bandcamp','web','email','phone','x','tiktok','facebook','patreon','linktree'];

    /** Viewer role can see section of given visibility. Delegates to Visibility — the one truth table. */
    public static function canSee(string $role, string $visibility): bool
    {
        // role: 'me' | 'admin' | 'friend' | 'member' | 'public'
        if ($role === 'friend') return $visibility !== 'private';   // legacy friend graph: !private
        return Visibility::audienceCanSee($role, $visibility);
    }

    /** Explicit claim. Slice 1.5 dropped the auto-seed; About starts inactive. */
    public static function claim(int $userId, ?string $via = null): bool
    {
        $stmt = Db::pg()->prepare('
            INSERT INTO profiles (user_id, claimed_via)
            VALUES (:u, :v)
            ON CONFLICT (user_id) DO NOTHING
        ');
        $stmt->execute([':u' => $userId, ':v' => $via]);
        return $stmt->rowCount() > 0;
    }

    public static function hasClaimed(int $userId): bool
    {
        $s = Db::pg()->prepare('SELECT 1 FROM profiles WHERE user_id=:u');
        $s->execute([':u' => $userId]);
        return (bool) $s->fetchColumn();
    }

    public static function setSectionOrder(int $userId, array $order): array
    {
        $known = self::knownSectionKeys();
        $clean = [];
        $seen  = [];
        foreach ($order as $key) {
            if (!is_string($key) || !in_array($key, $known, true) || isset($seen[$key])) continue;
            $clean[] = $key;
            $seen[$key] = true;
        }
        $pg = Db::pg();
        $pg->prepare('UPDATE profiles SET section_order = :o WHERE user_id = :u')
           ->execute([':o' => '{' . implode(',', $clean) . '}', ':u' => $userId]);
        return $clean;
    }

    public static function knownSectionKeys(): array
    {
        // Sections the editor renders cards for. 'practices' is still a
        // placeholder card until slice 3 ships.
        return ['about', 'instruments', 'skills', 'credentials', 'scenes', 'practices'];
    }

    public const HIGHLIGHT_KINDS = ['instrument', 'skill'];
    public const HIGHLIGHTS_MAX  = 3;

    // ── Launch guardrails: per-section item caps + field-length caps ──────────
    // Generous ceilings so real profiles are never blocked, but unbounded/abusive
    // payloads (which would bloat the JSONB and the page) are rejected with 400.
    public const INSTRUMENTS_MAX   = 40;
    public const SKILLS_MAX        = 40;
    public const SCENES_MAX        = 30;
    public const SOCIALS_MAX       = 20;
    public const CREDENTIALS_MAX   = 30;
    public const DROPOFFS_MAX      = 20;
    public const ABOUT_TEXT_MAX    = 8000;   // about/bio body
    public const SKILL_NOTE_MAX    = 200;    // per-skill note
    public const DROPOFF_FIELD_MAX = 500;    // each drop-off name/address/hours/notes

    /** Return the editor-shaped profile (everything, no role filtering). */
    public static function loadFull(int $userId): array
    {
        $pg = Db::pg();
        $u = $pg->prepare('SELECT * FROM users WHERE id = :i');
        $u->execute([':i' => $userId]);
        $user = $u->fetch();
        if (!$user) return [];

        $sections = [];
        $sStmt = $pg->prepare('SELECT key, visibility, data, sort_order FROM profile_sections WHERE user_id=:u ORDER BY sort_order, key');
        $sStmt->execute([':u' => $userId]);
        while ($r = $sStmt->fetch()) {
            $sections[$r['key']] = [
                'visibility' => $r['visibility'],
                'data'       => json_decode($r['data'], true) ?: [],
                'sort_order' => (int)$r['sort_order'],
            ];
        }

        $socials = [];
        $soStmt = $pg->prepare('SELECT kind, value, sort_order FROM profile_socials WHERE user_id=:u ORDER BY sort_order, id');
        $soStmt->execute([':u' => $userId]);
        while ($r = $soStmt->fetch()) {
            $socials[] = ['kind' => $r['kind'], 'value' => $r['value'], 'sort_order' => (int)$r['sort_order']];
        }

        // Slice 2 sections: instruments, skills, scenes (joined to catalogs).
        $instruments = [];
        $iq = $pg->prepare('
            SELECT i.id, i.slug, i.name, i.type, i.subtype, pi.sort_order
            FROM profile_instruments pi JOIN instrument_catalog i ON i.id = pi.instrument_id
            WHERE pi.user_id = :u ORDER BY pi.sort_order, i.sort_order, i.name
        ');
        $iq->execute([':u' => $userId]);
        while ($r = $iq->fetch()) {
            $instruments[] = ['id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'],
                              'type' => $r['type'], 'subtype' => $r['subtype'], 'sort_order' => (int)$r['sort_order']];
        }

        $skills = [];
        $sq = $pg->prepare('
            SELECT s.id, s.slug, s.name, s.category, ps.note, ps.sort_order
            FROM profile_skills ps JOIN skill_catalog s ON s.id = ps.skill_id
            WHERE ps.user_id = :u ORDER BY ps.sort_order, s.sort_order, s.name
        ');
        $sq->execute([':u' => $userId]);
        while ($r = $sq->fetch()) {
            $skills[] = ['id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'],
                         'category' => $r['category'], 'note' => $r['note'], 'sort_order' => (int)$r['sort_order']];
        }

        $scenes = [];
        $scq = $pg->prepare('
            SELECT t.slug, t.name FROM profile_scenes ps JOIN scene_tags t ON t.slug = ps.scene_slug
            WHERE ps.user_id = :u ORDER BY t.sort_order, t.name
        ');
        $scq->execute([':u' => $userId]);
        while ($r = $scq->fetch()) $scenes[] = ['slug' => $r['slug'], 'name' => $r['name']];

        $credentials = [];
        $cq = $pg->prepare('
            SELECT pc.id, pc.catalog_id, pc.raw_issuer, pc.raw_program, pc.identifier,
                   pc.issued_at, pc.expires_at, pc.evidence_url, pc.visibility, pc.sort_order,
                   cc.slug AS catalog_slug, cc.category AS catalog_category
            FROM profile_credentials pc LEFT JOIN credential_catalog cc ON cc.id = pc.catalog_id
            WHERE pc.owner_type = \'profile\' AND pc.owner_id = :u
            ORDER BY pc.sort_order, pc.id
        ');
        $cq->execute([':u' => $userId]);
        while ($r = $cq->fetch()) {
            $credentials[] = [
                'id'           => (int)$r['id'],
                'catalog_id'   => $r['catalog_id'] !== null ? (int)$r['catalog_id'] : null,
                'catalog_slug' => $r['catalog_slug'],
                'category'     => $r['catalog_category'],
                'raw_issuer'   => $r['raw_issuer'],
                'raw_program'  => $r['raw_program'],
                'identifier'   => $r['identifier'],
                'issued_at'    => $r['issued_at'],
                'expires_at'   => $r['expires_at'],
                'evidence_url' => $r['evidence_url'],
                'visibility'   => $r['visibility'],
                'sort_order'   => (int)$r['sort_order'],
            ];
        }

        $highlights = [];
        $hq = $pg->prepare('
            SELECT h.kind, h.ref_id, h.sort_order,
                   CASE WHEN h.kind = \'instrument\' THEN i.slug ELSE s.slug END AS slug,
                   CASE WHEN h.kind = \'instrument\' THEN i.name ELSE s.name END AS name
            FROM profile_highlights h
            LEFT JOIN instrument_catalog i ON h.kind=\'instrument\' AND i.id = h.ref_id
            LEFT JOIN skill_catalog      s ON h.kind=\'skill\'      AND s.id = h.ref_id
            WHERE h.user_id = :u
            ORDER BY h.sort_order
        ');
        $hq->execute([':u' => $userId]);
        while ($r = $hq->fetch()) {
            $highlights[] = [
                'kind'       => $r['kind'],
                'ref_id'     => (int)$r['ref_id'],
                'slug'       => $r['slug'],
                'name'       => $r['name'],
                'sort_order' => (int)$r['sort_order'],
            ];
        }

        // Pull profile-row metadata (section_order, claimed_via).
        $pStmt = $pg->prepare('SELECT section_order, claimed_via, claimed_at FROM profiles WHERE user_id=:u');
        $pStmt->execute([':u' => $userId]);
        $profile = $pStmt->fetch() ?: null;
        $sectionOrder = [];
        if ($profile && $profile['section_order']) {
            $raw = $profile['section_order'];
            if (is_string($raw)) {
                // Postgres array literal "{a,b,c}" — strip braces, split.
                $raw = trim($raw, '{}');
                $sectionOrder = $raw === '' ? [] : array_map(fn($s) => trim($s, '"'), explode(',', $raw));
            } elseif (is_array($raw)) {
                $sectionOrder = $raw;
            }
        }

        return [
            'user_id'       => $userId,
            'uuid'          => $user['uuid'],
            'display_name'  => $user['display_name'],
            'business_name' => $user['business_name'] ?? null,
            'slug'          => $user['slug'] ?: ((string)(int)$user['id']),
            'avatar_url'    => $user['avatar_url'],
            'at_a_glance'   => $user['at_a_glance'] ?? null,   // single-source author bio (spine add)
            'emails' => [
                'primary' => $user['primary_email'],
                'billing' => $user['billing_email'],
                'contact' => $user['contact_email'],
            ],
            'location' => [
                'text'         => $user['location_text'],
                'place_id'     => $user['place_id'],
                'lat'          => $user['lat']  !== null ? (float)$user['lat']  : null,
                'lng'          => $user['lng']  !== null ? (float)$user['lng']  : null,
                'country'      => $user['location_country'],
                'region'       => $user['location_region'],
                'city'         => $user['location_city'],
                'postcode'     => $user['location_postcode'],
                'place_result' => $user['place_result'] ? json_decode($user['place_result'], true) : null,
                'visibility'   => $user['location_visibility'] ?? 'members',
                'address'          => $user['location_address']           ?? null,   // exact tier (increment 1)
                'exact_visibility' => $user['location_exact_visibility']  ?? 'private',
                'pin_precision'    => $user['location_pin_precision']     ?? 'exact', // increment 2
            ],
            'sections'      => $sections,
            'socials'       => $socials,
            'instruments'   => $instruments,
            'skills'        => $skills,
            'scenes'        => $scenes,
            'credentials'   => $credentials,
            'highlights'    => $highlights,
            'member_since'  => $user['member_since'],
            'claimed'       => $profile !== null,
            'claimed_via'   => $profile['claimed_via']  ?? null,
            'claimed_at'    => $profile['claimed_at']   ?? null,
            'section_order' => $sectionOrder,
        ];
    }

    /**
     * Visibility gate for a location block. Replaces the old precision-shave
     * model: location is either fully visible or fully hidden, no rounding.
     *
     * $viewerUserId === 0 means anonymous.
     * Admins (resolved by caller) bypass — pass them through as if they were
     * the subject.
     */
    public static function canSeeLocation(int $viewerUserId, int $subjectUserId, string $visibility): bool
    {
        switch ($visibility) {
            case 'public':  return true;
            case 'members': return $viewerUserId !== 0;
            case 'private': return $viewerUserId === $subjectUserId;
            default:        return false;
        }
    }

    /** Apply viewer-role filter, returning the public-shape JSON. */
    public static function renderForViewer(array $full, string $role, int $viewerUserId = 0, int $subjectUserId = 0): array
    {
        $visibility = $full['location']['visibility'] ?? 'members';
        $canSeeLoc  = $role === 'me'
            ? true
            : self::canSeeLocation($viewerUserId, $subjectUserId, $visibility);

        $sections = [];
        // Anonymous viewers never get a section-location's EXACT street + precise
        // pin (F1, Ian 2026-06-11). The main location block coarsens by audience via
        // its precision picker; these section blocks (location:p4 …) had no picker
        // and dumped data verbatim, leaking e.g. a home address + 7-decimal coords to
        // anyone. Safe default: public sees approximate (street dropped, pin rounded
        // ~1km); logged-in members see exact. A per-section picker (so a storefront
        // can opt back into public-exact) is the follow-up.
        $anon = ($role === 'public');
        foreach ($full['sections'] as $key => $s) {
            if (!self::canSee($role, $s['visibility'])) continue;
            $data = $s['data'];
            if ($anon && is_array($data) && strncmp((string)$key, 'location', 8) === 0) {
                $data = self::coarsenLocationData($data);
            }
            $sections[$key] = ['visibility' => $s['visibility'], 'data' => $data];
        }

        // Contact links require LOGIN (Ian 2026-06-11: "must be scrape proof") —
        // never to anonymous viewers even when the socials block is 'public', so a
        // scraper can't harvest emails/handles per-uuid after harvesting uuids from
        // the directory. Logged-in viewers still honor the block's own visibility.
        $socialsVis = $full['sections']['socials']['visibility'] ?? 'public';
        $socials = ($role !== 'public' && self::canSee($role, $socialsVis)) ? ($full['socials'] ?? []) : [];

        // Slice 2 sections: instruments, skills, scenes are 'public' by default
        // (no per-row visibility). Credentials have per-row visibility.
        $instruments = $full['instruments'] ?? [];
        $skills      = $full['skills']      ?? [];
        $scenes      = $full['scenes']      ?? [];
        $credentials = array_values(array_filter($full['credentials'] ?? [],
            fn($c) => self::canSee($role, $c['visibility'])));
        $highlights  = $full['highlights']  ?? [];

        return [
            'uuid'          => $full['uuid'],
            'display_name'  => $full['display_name'],
            'business_name' => $full['business_name'] ?? null,
            'slug'          => $full['slug'],
            'avatar_url'    => $full['avatar_url'],
            'role'          => $role,
            'location'     => self::renderLocation($full['location'], $canSeeLoc, $anon),
            'sections'     => $sections,
            'socials'      => $socials,
            'instruments'  => $instruments,
            'skills'       => $skills,
            'scenes'       => $scenes,
            'credentials'  => $credentials,
            'highlights'   => $highlights,
            'member_since' => $full['member_since'],
        ];
    }

    /**
     * Coarsen a section-location's data for anonymous viewers (F1): drop the
     * exact typed street address, round the pin to ~1km (2 decimals) so a home
     * address can't be read off a public profile. Business hours / notes stay.
     * Logged-in members are never passed through here (they see exact).
     */
    public static function coarsenLocationData(array $data): array
    {
        unset($data['address'], $data['street_number'], $data['route'], $data['postcode']);
        foreach (['lat', 'lng'] as $k) {
            if (isset($data[$k]) && is_numeric($data[$k])) {
                $data[$k] = round((float) $data[$k], 2);
            }
        }
        $data['precision'] = 'coarse';   // consumer hint: pin is approximate
        return $data;
    }

    /**
     * Emit a location block if the viewer is permitted, else a hidden stub.
     * Privacy is the visibility gate, not a coordinate-fuzzing math problem —
     * granularity lives in what the user typed into the picker — EXCEPT for
     * anonymous viewers (F1 extension, mirrors coarsenLocationData): anon gets
     * a ~1km pin (2-decimal lat/lng) and never street-level postcode, so a
     * public-visibility profile can't leak a 7-decimal home pin to scrapers.
     *
     * NULL-component safety (cutover patch): text-only rows (the picker's
     * "save as text only" escape hatch) have NULL city/region/country/lat/lng.
     * Every component read is null-coalesced, and `display` composes
     * "City, Region" falling back to the raw text — consumers render that and
     * never build ", " garbage from NULLs.
     */
    public static function renderLocation(array $loc, bool $canSee, bool $anon = false): array
    {
        if (!$canSee || empty($loc['text'])) {
            return ['visibility' => $loc['visibility'] ?? 'private', 'hidden' => true];
        }
        $lat = isset($loc['lat']) && $loc['lat'] !== null ? (float)$loc['lat'] : null;
        $lng = isset($loc['lng']) && $loc['lng'] !== null ? (float)$loc['lng'] : null;
        if ($anon) {
            if ($lat !== null) $lat = round($lat, 2);
            if ($lng !== null) $lng = round($lng, 2);
        }
        $city   = $loc['city']   ?? null;
        $region = $loc['region'] ?? null;
        $out = [
            'visibility' => $loc['visibility'] ?? 'members',
            'text'       => $loc['text'],
            'display'    => trim(implode(', ', array_filter([(string)$city, (string)$region])))
                                ?: (string)$loc['text'],
            'place_id'   => $loc['place_id'] ?? null,
            'lat'        => $lat,
            'lng'        => $lng,
            'country'    => $loc['country'] ?? null,
            'region'     => $region,
            'city'       => $city,
            'postcode'   => $anon ? null : ($loc['postcode'] ?? null),
        ];
        if ($anon) $out['precision'] = 'coarse';   // consumer hint: pin is approximate
        return $out;
    }

    /**
     * Parse a Google Maps PlaceResult into typed columns. Returns:
     * [text, place_id, lat, lng, country, region, city, postcode, precision]
     */
    public static function parsePlaceResult(array $pr): array
    {
        $components = $pr['address_components'] ?? [];
        $byType = [];
        foreach ($components as $c) {
            foreach (($c['types'] ?? []) as $t) {
                if (!isset($byType[$t])) $byType[$t] = $c;
            }
        }
        $get = function($key, $field='long_name') use ($byType) {
            return $byType[$key][$field] ?? null;
        };

        $city = $get('locality') ?: $get('postal_town') ?: $get('sublocality') ?: $get('administrative_area_level_2');
        $region = $get('administrative_area_level_1') ?: $get('administrative_area_level_2');
        $country = $get('country');
        $postcode = $get('postal_code');

        $lat = $pr['geometry']['location']['lat'] ?? null;
        $lng = $pr['geometry']['location']['lng'] ?? null;
        if (is_array($lat)) $lat = null;  // some shapes
        if (is_array($lng)) $lng = null;

        // Determine declared precision based on which components are present.
        $hasStreet = $get('street_number') || $get('route');
        $precision = $hasStreet ? 'address' : ($city ? 'city' : ($region ? 'region' : 'country'));

        return [
            'text'      => $pr['formatted_address'] ?? null,
            'place_id'  => $pr['place_id'] ?? null,
            'lat'       => $lat !== null ? (float)$lat : null,
            'lng'       => $lng !== null ? (float)$lng : null,
            'country'   => $country,
            'region'    => $region,
            'city'      => $city,
            'postcode'  => $postcode,
            'precision' => $precision,
        ];
    }
}
