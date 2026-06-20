<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Block model for profile-2.0 — block sets + the header-as-CEILING visibility
 * rule, and the establishing pilot block (profile-header / identity).
 *
 * THE load-bearing rule (Ian, FINAL — plan-profile-block-system.md "Visibility
 * model — FINAL" + "Schema — RESOLVED dev-final"): the header's visibility IS the
 * profile/practice's own visibility = the section CAP. Effective block visibility
 * = the MORE RESTRICTIVE of (header, block). Stored on the header
 * `profile_sections` row (key='header'.visibility) — no dedicated column.
 *
 * Visibility vocabulary: the DB literal is 'members' (plural). normalizeVis()
 * maps to the platform/JSON/UI 'member' (singular). This class is the ONE
 * normalize point — persistence keeps the existing literal, callers speak 'member'.
 */
final class Block
{
    /** DB-literal tri-state, ordered open→closed; index = restrictiveness rank. */
    public const VIS_ORDER  = ['public', 'members', 'private'];
    public const VIS_VALUES = ['public', 'members', 'private'];

    /** Header section key (where the ceiling vis lives) and its default. */
    public const HEADER_KEY     = 'header';
    public const HEADER_DEFAULT = 'members';   // Ian's deferred default; member-baseline

    /**
     * Block registry — which keys belong to which entity palette (mirrors the
     * composer: shared + entity-specific). storefront = practice-only.
     */
    public const SETS = [
        'shared'   => ['location', 'about', 'gallery'],
        'profile'  => ['profile-header', 'craft', 'connect', 'socials', 'practices'],
        'practice' => ['practice-header', 'hours', 'services', 'turnaround', 'staff'],
    ];

    /** The required header block key per entity. */
    public const HEADER = ['profile' => 'profile-header', 'practice' => 'practice-header'];

    /** Palette for an entity = header first, then shared, then entity-own. */
    public static function paletteFor(string $entity): array
    {
        if (!isset(self::SETS[$entity])) return [];
        return array_merge([self::HEADER[$entity]], self::SETS['shared'], self::SETS[$entity]);
    }

    // ---------- composable profile layout (owner-arranged blocks on /u/) ----------

    /**
     * The blocks an owner can order / add / remove on their profile. Keys MUST match
     * the `data-block` attribute each renderer emits (the DOM order-source for reorder).
     * `header` is pinned first + excluded. `label` drives the caddy chip; `removable`
     * lets us pin a block later (all true for now). Array order = the Phase-1 default.
     */
    public const LAYOUT_BLOCKS = [
        'about'       => ['label' => 'About',       'removable' => true],
        'location'    => ['label' => 'Location',    'removable' => true],
        'skills'      => ['label' => 'Skills',      'removable' => true],
        'services'    => ['label' => 'Services',    'removable' => true],
        'instruments' => ['label' => 'Instruments', 'removable' => true],
        'music'       => ['label' => 'Music',       'removable' => true],
        'gallery'     => ['label' => 'Gallery',     'removable' => true],
        'resume'      => ['label' => 'Resume',      'removable' => true],
        'connect'     => ['label' => 'Connections', 'removable' => true],
        'socials'     => ['label' => 'Links',       'removable' => true],
    ];

    /**
     * Catalog-backed chip blocks (Skills/Services/Instruments/Music) — each picks rows from a
     * catalog table into a per-user link table. One generic loader/saver/renderer/picker drives
     * all four. (Identifiers here are constants, never user input — safe to interpolate in SQL.)
     */
    public const CATALOG_BLOCKS = [
        'skills'      => ['catalog' => 'skill_catalog',      'link' => 'profile_skills',      'fk' => 'skill_id',      'kind' => 'skills'],
        'services'    => ['catalog' => 'service_catalog',    'link' => 'profile_services',    'fk' => 'service_id',    'kind' => 'services'],
        'instruments' => ['catalog' => 'instrument_catalog', 'link' => 'profile_instruments', 'fk' => 'instrument_id', 'kind' => 'instruments'],
        'music'       => ['catalog' => 'genre_catalog',      'link' => 'profile_genres',      'fk' => 'genre_id',      'kind' => 'genres'],
    ];

    /**
     * Whether a block currently holds content. Drives the opt-in default (below) and lets
     * the caddy show which available blocks would come back populated. Runs only when a
     * profile has no explicit layout yet (never-touched), so the extra loads are rare.
     */
    public static function blockHasContent(int $userId, string $key): bool
    {
        switch ($key) {
            case 'about':    $a = self::loadAbout($userId);    return $a !== null && trim((string)($a['text'] ?? '')) !== '';
            case 'location': $l = self::loadLocation($userId); return $l !== null && !empty($l['has']);
            case 'skills':
            case 'services':
            case 'instruments':
            case 'music':    $b = self::loadCatalogBlock($userId, $key); return $b !== null && !empty($b['items']);
            case 'gallery':  return !empty(self::loadGallery($userId)['images']);
            case 'resume':   $r = self::loadResume($userId); return $r !== null && !empty($r['url']);
            case 'connect':  $c = self::loadConnect($userId, $userId); return $c !== null && (int)($c['fields']['count'] ?? 0) > 0;
            case 'socials':  $s = self::loadSocials($userId);  return $s !== null && !empty($s['fields']['ordered']);
        }
        return false;
    }

    /**
     * Default block order when the user has no explicit layout.
     *
     * LAUNCH DEFAULT (Ian 6/12): a never-arranged profile auto-places ONLY the
     * Location block, and only when a location is actually set — every other
     * block is opt-in from the caddy, even when imported data could populate
     * it (the BB-friendship import was auto-surfacing a Connections grid on
     * ~1,200 profiles nobody had arranged). Surfacing is a member's choice;
     * the imported data itself stays intact for when they opt in.
     */
    public static function defaultLayout(int $userId): array
    {
        return self::blockHasContent($userId, 'location') ? ['location'] : [];
    }

    /**
     * Block keys not currently on the profile — the caddy's "available to add"
     * set, an ordered map `[key => label]` over the canonical LAYOUT_BLOCKS.
     */
    /**
     * Profile block keys deferred past launch (config-driven). Hidden from the
     * builder palette AND skipped at render. Empty unless the launch flag is set.
     */
    public static function launchHiddenBlocks(): array
    {
        return defined('LG_PROFILE_APP_LAUNCH_HIDDEN_BLOCKS')
            ? (array) LG_PROFILE_APP_LAUNCH_HIDDEN_BLOCKS
            : [];
    }

    public static function availableBlocks(int $userId): array
    {
        $present = array_flip(self::profileLayout($userId));
        $hidden  = array_flip(self::launchHiddenBlocks());
        $out = [];
        foreach (self::LAYOUT_BLOCKS as $k => $cfg) {
            if (!isset($present[$k]) && !isset($hidden[$k])) $out[$k] = (string)$cfg['label'];
        }
        return $out;
    }

    // ---------- generic catalog-chip blocks (skills / services / instruments / music) ----------

    /**
     * Assemble a catalog-chip block — the user's picked rows (active catalog only), plus the
     * block-level vis (profile_sections key=$key). Returns null for an unknown user/key.
     */
    public static function loadCatalogBlock(int $userId, string $key): ?array
    {
        $cfg = self::CATALOG_BLOCKS[$key] ?? null;
        if ($cfg === null) return null;
        $pg = Db::pg();
        $e = $pg->prepare('SELECT 1 FROM users WHERE id = :i');
        $e->execute([':i' => $userId]);
        if (!$e->fetchColumn()) return null;

        // $cfg values are constants from CATALOG_BLOCKS — safe to interpolate as identifiers.
        $st = $pg->prepare(
            "SELECT c.id, c.slug, c.name
             FROM {$cfg['link']} l JOIN {$cfg['catalog']} c ON c.id = l.{$cfg['fk']}
             WHERE l.user_id = :u AND c.active = true
             ORDER BY l.sort_order, c.name"
        );
        $st->execute([':u' => $userId]);
        $items = array_map(static fn($r) => [
            'id' => (int) $r['id'], 'slug' => $r['slug'], 'name' => $r['name'],
        ], $st->fetchAll());

        return [
            'block' => $key,
            'vis'   => self::normalizeVis(self::blockVisibility($userId, $key, 'members')),
            'items' => $items,
        ];
    }

    // ---------- header status lights (availability "widgets") ----------

    /**
     * Addable header widgets: little status lights. Each light has a key + ordered states,
     * each state a label + tone (go=green / stop=red / maybe=amber). Owner adds a light and
     * toggles its state; it renders as a glowing dot + label in the header. Extensible — add
     * more keys here and they appear in the "+ Status" picker automatically.
     */
    public const HEADER_LIGHTS = [
        'work' => [
            'label'  => 'Work',
            'states' => [
                'open'   => ['label' => 'Accepting new work', 'tone' => 'go'],
                'closed' => ['label' => 'Not accepting work', 'tone' => 'stop'],
            ],
        ],
        'collab' => [
            'label'  => 'Collaboration',
            'states' => [
                'open'   => ['label' => 'Open to collaborations', 'tone' => 'go'],
                'closed' => ['label' => 'Not open to collaborations', 'tone' => 'stop'],
            ],
        ],
        'tour' => [
            'label'  => 'Touring',
            'states' => [
                'available' => ['label' => 'Available for tour work', 'tone' => 'go'],
                'on_tour'   => ['label' => 'Currently on tour',        'tone' => 'maybe'],
                'closed'    => ['label' => 'Not available for tours',  'tone' => 'stop'],
            ],
        ],
    ];

    /**
     * Map a raw header_lights value ({key:state} as a JSON string, decoded array, or null)
     * to the canonical [{key,state,label,tone}] list — no DB hit. Lets batch callers (e.g.
     * the directory feed) resolve lights from a row they already SELECTed, instead of an
     * N+1 loadHeaderLights() per member.
     */
    public static function mapHeaderLights($raw): array
    {
        $map = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
        if (!is_array($map)) $map = [];
        $out = [];
        foreach (self::HEADER_LIGHTS as $key => $cfg) {            // canonical order
            if (!isset($map[$key])) continue;
            $state = (string) $map[$key];
            if (!isset($cfg['states'][$state])) continue;
            $out[] = ['key' => $key, 'state' => $state] + $cfg['states'][$state];
        }
        return $out;
    }

    /** The user's set lights, in canonical order: [{key,state,label,tone}]. */
    public static function loadHeaderLights(int $userId): array
    {
        $s = Db::pg()->prepare('SELECT header_lights FROM users WHERE id = :i');
        $s->execute([':i' => $userId]);
        $raw = $s->fetchColumn();
        if ($raw === false) return [];
        return self::mapHeaderLights($raw);
    }

    /** Lights not yet added — drives the "+ Status" picker. Returns [key => cfg]. */
    public static function availableLights(int $userId): array
    {
        $present = [];
        foreach (self::loadHeaderLights($userId) as $l) $present[$l['key']] = true;
        return array_filter(self::HEADER_LIGHTS, static fn($k) => !isset($present[$k]), ARRAY_FILTER_USE_KEY);
    }

    /** Set ($state) or remove ($state===null) a header light. Returns the updated list, or null on bad input. */
    public static function saveHeaderLight(int $userId, string $key, ?string $state): ?array
    {
        if (!isset(self::HEADER_LIGHTS[$key])) return null;
        if ($state !== null && !isset(self::HEADER_LIGHTS[$key]['states'][$state])) return null;

        $s = Db::pg()->prepare('SELECT header_lights FROM users WHERE id = :i');
        $s->execute([':i' => $userId]);
        $raw = $s->fetchColumn();
        if ($raw === false) return null;
        $map = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        if (!is_array($map)) $map = [];

        if ($state === null) unset($map[$key]);
        else                 $map[$key] = $state;

        Db::pg()->prepare('UPDATE users SET header_lights = :v::jsonb, updated_at = now() WHERE id = :i')
            ->execute([':v' => json_encode($map), ':i' => $userId]);
        return self::loadHeaderLights($userId);
    }

    /** Replace a user's selection for a catalog block (validated ⊂ active catalog, order preserved). */
    public static function saveCatalogSelection(int $userId, string $key, array $ids): ?array
    {
        $cfg = self::CATALOG_BLOCKS[$key] ?? null;
        if ($cfg === null) return null;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($x) => $x > 0)));

        $pg = Db::pg();
        $pg->beginTransaction();
        try {
            $pg->prepare("DELETE FROM {$cfg['link']} WHERE user_id = :u")->execute([':u' => $userId]);
            if ($ids) {
                $ph    = implode(',', array_fill(0, count($ids), '?'));
                $vstmt = $pg->prepare("SELECT id FROM {$cfg['catalog']} WHERE active = true AND id IN ($ph)");
                $vstmt->execute($ids);
                $valid = array_map('intval', $vstmt->fetchAll(\PDO::FETCH_COLUMN));
                $rank  = array_flip($ids);                                  // preserve caller's order
                usort($valid, static fn($a, $b) => ($rank[$a] ?? 0) <=> ($rank[$b] ?? 0));
                $ins = $pg->prepare("INSERT INTO {$cfg['link']} (user_id, {$cfg['fk']}, sort_order) VALUES (:u, :x, :s) ON CONFLICT DO NOTHING");
                foreach ($valid as $i => $id) $ins->execute([':u' => $userId, ':x' => $id, ':s' => $i]);
            }
            $pg->commit();
        } catch (\Throwable $e) {
            $pg->rollBack();
            throw $e;
        }
        return self::loadCatalogBlock($userId, $key);
    }

    /** Filter an arbitrary key list to known layout keys, in order, de-duped.
     *  Retired keys (freeform:<id>, dropoffs — removed 2026-06-11, Ian) fall out here. */
    private static function normalizeLayout(array $order): array
    {
        $seen = [];
        $out  = [];
        foreach ($order as $k) {
            if (!is_string($k)) continue;
            // back-compat: the old combined 'craft' block became Skills + Instruments.
            foreach ($k === 'craft' ? ['skills', 'instruments'] : [$k] as $kk) {
                if (isset(self::LAYOUT_BLOCKS[$kk]) && !isset($seen[$kk])) {
                    $seen[$kk] = true;
                    $out[]     = $kk;
                }
            }
        }
        return $out;
    }

    /**
     * The owner's chosen block order (header excluded — pinned at render). Reads
     * users.profile_layout; NULL → defaultLayout(). An explicit empty array (user
     * removed every block) is honoured as header-only.
     */
    public static function profileLayout(int $userId): array
    {
        $s = Db::pg()->prepare('SELECT profile_layout FROM users WHERE id = :i');
        $s->execute([':i' => $userId]);
        $raw = $s->fetchColumn();
        if ($raw === false) return [];                              // unknown user
        $order = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($order)) return self::defaultLayout($userId); // NULL / never-set → opt-in default
        return self::normalizeLayout($order);
    }

    /** Persist the owner's block order (validated ⊂ registry, de-duped). Returns it normalized. */
    public static function saveProfileLayout(int $userId, array $order): array
    {
        $clean = self::normalizeLayout($order);
        Db::pg()->prepare('UPDATE users SET profile_layout = :v::jsonb, updated_at = now() WHERE id = :i')
            ->execute([':v' => json_encode($clean), ':i' => $userId]);
        return $clean;
    }

    // ---------- the single normalize point: DB 'members' <-> UI 'member' ----------

    /** DB literal → UI/JSON canonical ('members' → 'member'). */
    public static function normalizeVis(string $vis): string
    {
        return $vis === 'members' ? 'member' : $vis;
    }

    /** UI/JSON canonical → DB literal ('member' → 'members'). */
    public static function denormalizeVis(string $vis): string
    {
        return $vis === 'member' ? 'members' : $vis;
    }

    /** Validate an incoming UI vis ('public'|'member'|'private'); returns DB literal or null. */
    public static function visFromInput($vis): ?string
    {
        if (!is_string($vis)) return null;
        $db = self::denormalizeVis($vis);
        return in_array($db, self::VIS_VALUES, true) ? $db : null;
    }

    // ---------- the header-ceiling rule ----------

    /**
     * Effective block visibility = MORE RESTRICTIVE of (header, block).
     * Inputs + output are DB literals. One function, every render path.
     */
    public static function effectiveVisibility(string $headerVis, string $blockVis): string
    {
        return self::VIS_ORDER[max(self::visRank($headerVis), self::visRank($blockVis))];
    }

    /**
     * Restrictiveness rank on VIS_ORDER. Unknown values (e.g. the exact-tier
     * 'on_request') FAIL CLOSED to the most restrictive rank — never under-gate.
     */
    public static function visRank(string $vis): int
    {
        $i = array_search($vis, self::VIS_ORDER, true);
        return $i === false ? count(self::VIS_ORDER) - 1 : $i;   // unknown → private rank
    }

    /**
     * The entity's header/ceiling visibility (DB literal). Lives on the
     * profile_sections row key='header'. Falls back to HEADER_DEFAULT.
     */
    public static function headerCeiling(int $userId): string
    {
        $s = Db::pg()->prepare(
            "SELECT visibility FROM profile_sections WHERE user_id = :u AND key = :k"
        );
        $s->execute([':u' => $userId, ':k' => self::HEADER_KEY]);
        $vis = $s->fetchColumn();
        return ($vis && in_array($vis, self::VIS_VALUES, true)) ? (string)$vis : self::HEADER_DEFAULT;
    }

    /**
     * Whole-profile gate decision from header vis + viewer role.
     *   'private' → owner-only; nothing renders to others.
     *   'gate'    → members-only; a logged-out visitor gets the join/sign-in gate.
     *   'render'  → render; blocks then refine DOWN per their own effective vis.
     * @param string $role 'me'|'admin'|'member'|'friend'|'public'
     */
    public static function gateDecision(string $role, string $headerVis): string
    {
        if ($role === 'me' || $role === 'admin') return 'render';   // admins see everything (Ian 6/12 ruling 4)
        switch ($headerVis) {
            case 'private': return 'private';                       // owner only
            case 'members': return $role === 'public' ? 'gate' : 'render';
            case 'public':  return 'render';                        // public peeks through
            default:        return $role === 'public' ? 'gate' : 'render';
        }
    }

    /**
     * Can a viewer of $role see a block of raw vis $blockVis, beneath a header of
     * $headerVis? Applies the ceiling, then the existing role check.
     */
    public static function canSee(string $role, string $headerVis, string $blockVis): bool
    {
        return Profile::canSee($role, self::effectiveVisibility($headerVis, $blockVis));
    }

    /**
     * UX: is the header capping this block's chosen vis? (block set more open than
     * the header allows) → drives the editor tooltip "Header is members-only, so
     * this block is limited to members."
     */
    public static function isCappedByHeader(string $headerVis, string $blockVis): bool
    {
        return self::effectiveVisibility($headerVis, $blockVis) !== $blockVis;
    }

    // ---------- pilot block: profile-header (identity) ----------

    /**
     * Assemble the profile-header block from the relational spine. The
     * establishing pattern: JSON shape ↔ relational mapping ↔ block-level (here
     * ceiling) pmp. Returns null if the user doesn't exist.
     *
     *   users.display_name / avatar_url / at_a_glance  → fields
     *   profile_socials (kind='web')                    → website
     *   profile_socials (other kinds)                   → socials[]
     *   profile_sections key='header' .visibility       → block vis (the ceiling)
     *   tier_badge: 'auto' — DERIVED at render from /whoami, never stored/drafted.
     *
     * `vis` is returned NORMALIZED ('member'); persistence stays 'members'.
     */
    public static function loadHeader(int $userId): ?array
    {
        $pg = Db::pg();
        $u = $pg->prepare('SELECT display_name, avatar_url, at_a_glance, banner_url FROM users WHERE id = :i');
        $u->execute([':i' => $userId]);
        $row = $u->fetch();
        if (!$row) return null;

        return [
            'block'   => 'profile-header',
            'subject' => 'person',
            'vis'     => self::normalizeVis(self::headerCeiling($userId)),
            'fields'  => [
                'display_name' => $row['display_name'],
                'avatar'       => $row['avatar_url'],    // null → initials fallback at render
                'at_a_glance'  => $row['at_a_glance'],   // single-source author bio
                'banner'       => $row['banner_url'],    // optional hero strip; null → no banner element
            ],
            'tier_badge' => 'auto',   // derived from /whoami at render; never stored
        ];
    }

    /**
     * Write the header's editable fields + the ceiling visibility. Returns the
     * persisted shape. $fields keys (all optional): at_a_glance (string|null),
     * visibility ('public'|'member'|'private' — the ceiling).
     * display_name stays in me-name; avatar in the avatar endpoint; socials in
     * me-socials — this writes the header-specific bits.
     */
    public static function saveHeader(int $userId, array $fields): array
    {
        $pg = Db::pg();

        if (array_key_exists('at_a_glance', $fields)) {
            $bio = $fields['at_a_glance'];
            $bio = ($bio === null || $bio === '') ? null : (string)$bio;
            $pg->prepare('UPDATE users SET at_a_glance = :b WHERE id = :u')
               ->execute([':b' => $bio, ':u' => $userId]);
        }

        if (array_key_exists('visibility', $fields)) {
            $vis = self::visFromInput($fields['visibility']);   // → DB literal
            if ($vis !== null) {
                $pg->prepare("
                    INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                    VALUES (:u, 'header', :v, '{}'::jsonb, 0)
                    ON CONFLICT (user_id, key) DO UPDATE
                       SET visibility = EXCLUDED.visibility, updated_at = now()
                ")->execute([':u' => $userId, ':v' => $vis]);
            }
        }

        return self::loadHeader($userId) ?? [];
    }

    // ---------- pilot block: location (two-tier, user-managed pin) ----------

    /** Exact-tier visibility values (never 'public' — a precise pin can't be open web). */
    public const EXACT_VIS_VALUES = ['members', 'private', 'on_request'];

    /** User-managed display precision for the pin (canon: exact → neighborhood → city). */
    public const PRECISION_VALUES  = ['exact', 'neighborhood', 'city'];
    public const PRECISION_DEFAULT = 'exact';

    /** Coarsening decimal places per precision (~111km/dp). Approximate tier is town-level. */
    private const DP_NEIGHBORHOOD = 2;   // ~1.1 km
    private const DP_CITY         = 1;   // ~11 km
    private const DP_APPROX       = 1;   // the always-coarse "near me"/map tier (town-level)

    /** Round a coordinate to $dp decimals (the no-stored-column coarsening). Null-safe. */
    public static function coarsen($coord, int $dp)
    {
        return $coord === null ? null : round((float)$coord, $dp);
    }

    /**
     * Assemble the location block from the spine — both tiers. The render layer
     * gates each tier; this returns the full editor shape (vis normalized to 'member').
     *
     *   approximate ← users.location_city/region/country + COARSE coord
     *                 (city-centroid derived by rounding the stored point; NO approx
     *                 column) + users.location_visibility.
     *   exact       ← users.lat/lng (the user-placed pin), at the chosen display
     *                 PRECISION, + users.location_address/postcode +
     *                 users.location_exact_visibility. Folds to null when the user
     *                 set precision='city' (no precise pin exposed).
     */
    /** Per-AUDIENCE precision levels (Ian's model): one address, two audience knobs. */
    public const LOCATION_PRECISION = ['private', 'state', 'city', 'street'];

    /**
     * Load the stored address + the two audience precision knobs. One address;
     * `members_precision` / `public_precision` each ∈ private|state|city|street
     * decide how precise the DISPLAY is for that audience. Owner always sees street.
     */
    public static function loadLocation(int $userId): ?array
    {
        $s = Db::pg()->prepare('SELECT location_text, lat, lng, location_country, location_region,
                                       location_city, location_postcode, location_address,
                                       location_members_precision, location_public_precision
                                FROM users WHERE id = :i');
        $s->execute([':i' => $userId]);
        $r = $s->fetch();
        if (!$r) return null;

        $lat = $r['lat'] !== null ? (float)$r['lat'] : null;
        $lng = $r['lng'] !== null ? (float)$r['lng'] : null;
        $place = [
            'address'  => $r['location_address'],
            'postcode' => $r['location_postcode'],
            'city'     => $r['location_city'],
            'region'   => $r['location_region'],
            'country'  => $r['location_country'],
            'lat'      => $lat,
            'lng'      => $lng,
            'text'     => $r['location_text'],
        ];
        $clean = fn($p, $d) => (is_string($p) && in_array($p, self::LOCATION_PRECISION, true)) ? $p : $d;

        // Owner-set extras (address detail / hours / note) live in profile_sections
        // key='location' data JSONB — no users-table column needed. Empty by default.
        $ex = ['address' => '', 'hours' => '', 'note' => ''];
        $xs = Db::pg()->prepare("SELECT data FROM profile_sections WHERE user_id = :i AND key = 'location'");
        $xs->execute([':i' => $userId]);
        if ($xr = $xs->fetch()) {
            $xd = json_decode((string)$xr['data'], true);
            if (is_array($xd)) {
                $ex['address'] = (string)($xd['address'] ?? '');
                $ex['hours']   = (string)($xd['hours']   ?? '');
                $ex['note']    = (string)($xd['note']    ?? '');
            }
        }

        return [
            'block'   => 'location',
            'subject' => 'person',
            'has'     => (bool)($place['address'] || $place['city'] || $place['region'] || $place['text'] || $lat !== null),
            'members_precision' => $clean($r['location_members_precision'] ?? null, 'city'),
            'public_precision'  => $clean($r['location_public_precision']  ?? null, 'private'),
            'place'   => $place,
            'address' => $ex['address'],
            'hours'   => $ex['hours'],
            'note'    => $ex['note'],
        ];
    }

    /**
     * Upsert the location block's owner-set extras (address detail / hours / note)
     * into profile_sections key='location' data JSONB. A null arg leaves that field
     * unchanged. The row's visibility is unused for gating (the location block follows
     * the precision model); we keep it 'members' as a harmless placeholder.
     */
    public static function saveLocationExtras(int $userId, ?string $addr, ?string $hours, ?string $note): array
    {
        $cur = self::loadLocation($userId);
        $a = $addr  !== null ? mb_substr(trim($addr),  0, self::DROPOFFS_ADDR_MAX)  : (string)($cur['address'] ?? '');
        $h = $hours !== null ? mb_substr(trim($hours), 0, self::DROPOFFS_HOURS_MAX) : (string)($cur['hours']   ?? '');
        $n = $note  !== null ? mb_substr(trim($note),  0, self::DROPOFFS_NOTES_MAX) : (string)($cur['note']    ?? '');
        $data = json_encode(['address' => $a, 'hours' => $h, 'note' => $n], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        Db::pg()->prepare("
            INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
            VALUES (:u, 'location', 'members', :d::jsonb, 10)
            ON CONFLICT (user_id, key) DO UPDATE SET data = EXCLUDED.data, updated_at = now()
        ")->execute([':u' => $userId, ':d' => $data]);
        return self::loadLocation($userId);
    }

    /**
     * What to DISPLAY at a given precision level. Null for 'private' / no data.
     * Else [text, lat, lng, zoom, kind] — kind 'exact' (marker) | 'coarse' (circle).
     * street = full address + exact pin · city = City, Region + town dot ·
     * state = Region, Country + region-level dot.
     */
    public static function locationDisplay(array $place, string $precision): ?array
    {
        if ($precision === 'private') return null;
        $lat = $place['lat']; $lng = $place['lng'];
        $city = (string)($place['city'] ?? ''); $region = (string)($place['region'] ?? '');
        $country = (string)($place['country'] ?? '');

        if ($precision === 'street') {
            $text = (string)($place['address'] ?: $place['text'] ?: trim(implode(', ', array_filter([$city, $region]))));
            if (!empty($place['postcode'])) $text = ($text !== '' ? $text . ' · ' : '') . $place['postcode'];
            if ($text === '' && $lat === null) return null;
            return ['text' => $text, 'lat' => $lat, 'lng' => $lng, 'zoom' => 15, 'kind' => 'exact'];
        }
        if ($precision === 'city') {
            $text = self::coarseText(trim(implode(', ', array_filter([$city, $region]))), $place, $lat);
            // 2-decimal (~1.1km), NOT 1 (~11km): 1-decimal landed city pins in the
            // East River (renderLocation cutover patch, Ian 5/26 — Evan Gluck's pin).
            return ['text' => $text, 'lat' => self::coarsen($lat, self::DP_NEIGHBORHOOD), 'lng' => self::coarsen($lng, self::DP_NEIGHBORHOOD), 'zoom' => 11, 'kind' => 'coarse'];
        }
        // state — same coarse-text rule as city.
        $text = self::coarseText(trim(implode(', ', array_filter([$region, $country]))), $place, $lat);
        return ['text' => $text, 'lat' => self::coarsen($lat, 0), 'lng' => self::coarsen($lng, 0), 'zoom' => 6, 'kind' => 'coarse'];
    }

    /**
     * Coarse-precision (city/state) text rule. Prefer the structured coarse label
     * ("City, Region" / "Region, Country"). Fall back to the row's literal text ONLY
     * for text-only rows (no pin) — those are snapshot-migrated coarse place names and
     * must render their literal text rather than an empty string. When a PIN is present
     * the literal text may be a VERBATIM STREET ADDRESS, which must NEVER surface at
     * coarse precision (privacy: the City/State dial promises not to leak the street).
     * If structured labels are missing on a pinned row, render no text — just the
     * coarsened map — never the verbatim string.
     */
    private static function coarseText(string $coarse, array $place, $lat): string
    {
        if ($coarse !== '') return $coarse;
        return $lat === null ? (string)($place['text'] ?? '') : '';
    }

    /** Validate an incoming precision level; returns it or null. */
    public static function precisionFromInput($p): ?string
    {
        return (is_string($p) && in_array($p, self::LOCATION_PRECISION, true)) ? $p : null;
    }

    /** Validate an incoming exact-tier vis ('member'|'private'|'on_request'); DB literal or null. */
    public static function exactVisFromInput($vis): ?string
    {
        if (!is_string($vis)) return null;
        $db = self::denormalizeVis($vis);   // 'member' → 'members'
        return in_array($db, self::EXACT_VIS_VALUES, true) ? $db : null;
    }

    // ---------- composable-block visibility (generic) ----------

    public const CRAFT_KEY    = 'craft';
    public const SOCIALS_KEY  = 'socials';
    public const CONNECT_KEY  = 'connect';

    /** Any composable block's visibility (DB literal) from its profile_sections row. */
    public static function blockVisibility(int $userId, string $key, string $default = 'members'): string
    {
        $s = Db::pg()->prepare('SELECT visibility FROM profile_sections WHERE user_id = :u AND key = :k');
        $s->execute([':u' => $userId, ':k' => $key]);
        $v = $s->fetchColumn();
        return ($v && in_array($v, self::VIS_VALUES, true)) ? (string)$v : $default;
    }

    /**
     * Set a composable block's visibility. $visInput is the UI vocabulary
     * ('public'|'member'|'private'); returns the persisted DB literal, or null if
     * invalid. Upserts the profile_sections row (data left untouched / '{}').
     */
    public static function saveBlockVisibility(int $userId, string $key, $visInput, int $sortOrder = 0): ?string
    {
        $vis = self::visFromInput($visInput);   // → DB literal
        if ($vis === null) return null;
        Db::pg()->prepare("
            INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
            VALUES (:u, :k, :v, '{}'::jsonb, :so)
            ON CONFLICT (user_id, key) DO UPDATE
               SET visibility = EXCLUDED.visibility, updated_at = now()
        ")->execute([':u' => $userId, ':k' => $key, ':v' => $vis, ':so' => $sortOrder]);
        return $vis;
    }

    // ---------- block: craft (what you make/do — search fuel) ----------

    /**
     * Assemble the craft block — instruments + skills + highlights (the
     * directory search-fuel), with one block-level vis (profile_sections key='craft').
     * Reuses Profile::loadFull (the canonical assembler) to avoid duplicating the
     * catalog joins. Returns null if the user doesn't exist.
     */
    public static function loadCraft(int $userId): ?array
    {
        $full = Profile::loadFull($userId);
        if (!$full) return null;
        $vis = $full['sections'][self::CRAFT_KEY]['visibility'] ?? 'members';
        $pick = fn(array $rows, array $keys) => array_map(
            fn($r) => array_intersect_key($r, array_flip($keys)), $rows
        );
        return [
            'block'   => 'craft',
            'subject' => 'person',
            'vis'     => self::normalizeVis($vis),
            'fields'  => [
                'instruments' => $pick($full['instruments'] ?? [], ['id', 'slug', 'name']),
                'skills'      => $pick($full['skills']      ?? [], ['id', 'slug', 'name', 'note']),
                'highlights'  => $pick($full['highlights']  ?? [], ['kind', 'slug', 'name']),
            ],
        ];
    }

    // ---------- block: about (free-text; shared, profile + practice) ----------

    public const ABOUT_KEY = 'about';

    /** Assemble the about block — free text + block vis (profile_sections key='about'). */
    public static function loadAbout(int $userId, string $key = 'about'): array
    {
        $s = Db::pg()->prepare("SELECT visibility, data FROM profile_sections WHERE user_id = :u AND key = :k");
        $s->execute([':u' => $userId, ':k' => $key]);
        $r = $s->fetch();
        $text = '';
        if ($r) { $d = json_decode((string)$r['data'], true) ?: []; $text = (string)($d['text'] ?? ''); }
        return [
            'block'   => 'about',
            'subject' => 'person',
            'vis'     => self::normalizeVis(($r && in_array($r['visibility'], self::VIS_VALUES, true)) ? $r['visibility'] : 'members'),
            'text'    => $text,
        ];
    }

    // ---------- block: drop-off locations (business drop-off points; structured list) ----------

    public const DROPOFFS_KEY       = 'dropoffs';
    public const DROPOFFS_MAX_ITEMS = 20;
    public const DROPOFFS_NAME_MAX  = 120;
    public const DROPOFFS_ADDR_MAX  = 250;
    public const DROPOFFS_HOURS_MAX = 250;
    public const DROPOFFS_NOTES_MAX = 1000;

    /**
     * Assemble the drop-off-locations block — an ordered list of business drop-off
     * points, each {name, address, hours, notes}, plus one block-level vis. Stored as
     * a single profile_sections row (key='dropoffs', data JSONB {items:[...]}); no
     * dedicated table / migration. Returns null only for an unknown user.
     */
    public static function loadDropoffs(int $userId, string $key = self::DROPOFFS_KEY): ?array
    {
        $pg = Db::pg();
        $e = $pg->prepare('SELECT 1 FROM users WHERE id = :i');
        $e->execute([':i' => $userId]);
        if (!$e->fetchColumn()) return null;

        $s = $pg->prepare("SELECT visibility, data FROM profile_sections WHERE user_id = :u AND key = :k");
        $s->execute([':u' => $userId, ':k' => $key]);
        $r = $s->fetch();
        $items = [];
        if ($r) {
            $d = json_decode((string)$r['data'], true) ?: [];
            foreach (($d['items'] ?? []) as $it) {
                if (!is_array($it)) continue;
                $items[] = [
                    'name'    => (string)($it['name'] ?? ''),
                    'address' => (string)($it['address'] ?? ''),
                    'hours'   => (string)($it['hours'] ?? ''),
                    'notes'   => (string)($it['notes'] ?? ''),
                    'lat'     => (isset($it['lat']) && is_numeric($it['lat'])) ? (float)$it['lat'] : null,
                    'lng'     => (isset($it['lng']) && is_numeric($it['lng'])) ? (float)$it['lng'] : null,
                ];
            }
        }
        return [
            'block'   => 'dropoffs',
            'subject' => 'person',
            'vis'     => self::normalizeVis(($r && in_array($r['visibility'], self::VIS_VALUES, true)) ? $r['visibility'] : 'members'),
            'items'   => $items,
        ];
    }

    /**
     * Replace the owner's drop-off list (and optionally its visibility) with the given
     * items. Each item is trimmed + length-capped; wholly-empty rows are dropped; the
     * list is capped at DROPOFFS_MAX_ITEMS. Upserts the profile_sections row, returns
     * the post-save shape (or null for an unknown user).
     */
    /**
     * Geocode a free-text drop-off address to [lat,lng] via Nominatim (OSM) —
     * same provider + User-Agent as bin/geocode.php and the location picker.
     * No API key. Returns null on miss/error; the caller leaves the pin off.
     */
    private static function geocodeDropoff(string $addr): ?array
    {
        $addr = trim($addr);
        if ($addr === '') return null;
        $host = getenv('LOOTH_NOMINATIM_HOST') ?: 'https://nominatim.openstreetmap.org';
        $url  = $host . '/search?' . http_build_query(['q' => $addr, 'format' => 'json', 'limit' => 1]);
        $ctx  = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "User-Agent: looth-profile-app/0.3 (admin: ian.davlin@gmail.com)
Accept: application/json
",
            'timeout'       => 5,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return null;
        $data = json_decode($resp, true);
        if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) return null;
        return ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
    }

    public static function saveDropoffs(int $userId, array $items, ?string $visInput = null, string $key = self::DROPOFFS_KEY): ?array
    {
        $pg = Db::pg();
        $e = $pg->prepare('SELECT 1 FROM users WHERE id = :i');
        $e->execute([':i' => $userId]);
        if (!$e->fetchColumn()) return null;

        // Preserve already-resolved pin coordinates: match incoming rows to the
        // stored ones by address, so editing name/hours/notes never re-geocodes.
        $prevCoords = [];
        $prevShape  = self::loadDropoffs($userId, $key);
        foreach (($prevShape['items'] ?? []) as $p) {
            $pa = mb_strtolower(trim((string)($p['address'] ?? '')));
            if ($pa !== '' && $p['lat'] !== null && $p['lng'] !== null) {
                $prevCoords[$pa] = ['lat' => (float)$p['lat'], 'lng' => (float)$p['lng']];
            }
        }

        $clean = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $name = mb_substr(trim((string)($it['name'] ?? '')),    0, self::DROPOFFS_NAME_MAX);
            $addr = mb_substr(trim((string)($it['address'] ?? '')), 0, self::DROPOFFS_ADDR_MAX);
            $hrs  = mb_substr(trim((string)($it['hours'] ?? '')),   0, self::DROPOFFS_HOURS_MAX);
            $note = mb_substr(trim((string)($it['notes'] ?? '')),   0, self::DROPOFFS_NOTES_MAX);
            if ($name === '' && $addr === '' && $hrs === '' && $note === '') continue;   // skip empty rows

            // Pin coords: client-supplied wins; else reuse the stored coord for an
            // unchanged address; else geocode the address once.
            $lat = (isset($it['lat']) && is_numeric($it['lat'])) ? (float)$it['lat'] : null;
            $lng = (isset($it['lng']) && is_numeric($it['lng'])) ? (float)$it['lng'] : null;
            if (($lat === null || $lng === null) && $addr !== '') {
                $ak = mb_strtolower($addr);
                if (isset($prevCoords[$ak])) {
                    $lat = $prevCoords[$ak]['lat']; $lng = $prevCoords[$ak]['lng'];
                } else {
                    $geo = self::geocodeDropoff($addr);
                    if ($geo !== null) { $lat = $geo['lat']; $lng = $geo['lng']; }
                }
            }

            $clean[] = ['name' => $name, 'address' => $addr, 'hours' => $hrs, 'notes' => $note, 'lat' => $lat, 'lng' => $lng];
            if (count($clean) >= self::DROPOFFS_MAX_ITEMS) break;
        }
        $data = json_encode(['items' => $clean], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($visInput !== null && self::visFromInput($visInput) !== null) {
            $pg->prepare("
                INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                VALUES (:u, :k, :v, :d::jsonb, 50)
                ON CONFLICT (user_id, key) DO UPDATE SET visibility = EXCLUDED.visibility, data = EXCLUDED.data, updated_at = now()
            ")->execute([':u' => $userId, ':k' => $key, ':v' => self::visFromInput($visInput), ':d' => $data]);
        } else {
            $pg->prepare("
                INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                VALUES (:u, :k, 'members', :d::jsonb, 50)
                ON CONFLICT (user_id, key) DO UPDATE SET data = EXCLUDED.data, updated_at = now()
            ")->execute([':u' => $userId, ':k' => $key, ':d' => $data]);
        }
        return self::loadDropoffs($userId, $key);
    }

    /**
     * Practice (business) Location block — a SELF-CONTAINED JSONB record in the
     * OWNER's profile_sections under 'location:p<id>' (NOT the users-table profile
     * model, which belongs to a person). One address, geocoded to a single pin;
     * owner-set hours + note; one block-level visibility. Default 'public' — a shop
     * wants to be found — still capped by the practice-header ceiling at render.
     */
    public static function loadPracticeLocation(int $ownerId, int $practiceId): array
    {
        $key = self::practiceBlockKey('location', $practiceId);
        $s = Db::pg()->prepare("SELECT visibility, data FROM profile_sections WHERE user_id = :u AND key = :k");
        $s->execute([':u' => $ownerId, ':k' => $key]);
        $r = $s->fetch();
        $d = ($r && is_string($r['data'])) ? (json_decode($r['data'], true) ?: []) : [];
        $addr  = (string)($d['address'] ?? '');
        $hours = (string)($d['hours']   ?? '');
        $note  = (string)($d['note']    ?? '');
        $lat   = (isset($d['lat']) && is_numeric($d['lat'])) ? (float)$d['lat'] : null;
        $lng   = (isset($d['lng']) && is_numeric($d['lng'])) ? (float)$d['lng'] : null;
        return [
            'block'   => 'location',
            'has'     => ($addr !== '' || $hours !== '' || $note !== ''),
            'address' => $addr, 'hours' => $hours, 'note' => $note,
            'lat'     => $lat, 'lng' => $lng,
            'vis'     => self::normalizeVis(($r && in_array($r['visibility'], self::VIS_VALUES, true)) ? $r['visibility'] : 'public'),
        ];
    }

    /**
     * Upsert the practice location. Geocodes the address (Nominatim, via the shared
     * drop-off helper) only when it actually changed, so editing hours/note never
     * re-geocodes. A missing key leaves that field unchanged; visibility optional.
     */
    public static function savePracticeLocation(int $ownerId, int $practiceId, array $in): array
    {
        $key = self::practiceBlockKey('location', $practiceId);
        $cur = self::loadPracticeLocation($ownerId, $practiceId);
        $addr  = array_key_exists('address', $in) ? mb_substr(trim((string)$in['address']), 0, self::DROPOFFS_ADDR_MAX)  : $cur['address'];
        $hours = array_key_exists('hours',   $in) ? mb_substr(trim((string)$in['hours']),   0, self::DROPOFFS_HOURS_MAX) : $cur['hours'];
        $note  = array_key_exists('note',    $in) ? mb_substr(trim((string)$in['note']),    0, self::DROPOFFS_NOTES_MAX) : $cur['note'];

        $lat = $cur['lat']; $lng = $cur['lng'];
        if ($addr !== $cur['address']) {
            $lat = null; $lng = null;
            if ($addr !== '') { $geo = self::geocodeDropoff($addr); if ($geo !== null) { $lat = $geo['lat']; $lng = $geo['lng']; } }
        }
        $data = json_encode(['address' => $addr, 'hours' => $hours, 'note' => $note, 'lat' => $lat, 'lng' => $lng],
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $vis = (array_key_exists('visibility', $in) && self::visFromInput($in['visibility']) !== null)
             ? self::visFromInput($in['visibility']) : null;
        if ($vis !== null) {
            Db::pg()->prepare("
                INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                VALUES (:u, :k, :v, :d::jsonb, 10)
                ON CONFLICT (user_id, key) DO UPDATE SET visibility = EXCLUDED.visibility, data = EXCLUDED.data, updated_at = now()
            ")->execute([':u' => $ownerId, ':k' => $key, ':v' => $vis, ':d' => $data]);
        } else {
            Db::pg()->prepare("
                INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                VALUES (:u, :k, 'public', :d::jsonb, 10)
                ON CONFLICT (user_id, key) DO UPDATE SET data = EXCLUDED.data, updated_at = now()
            ")->execute([':u' => $ownerId, ':k' => $key, ':d' => $data]);
        }
        return self::loadPracticeLocation($ownerId, $practiceId);
    }

    public const HOURS_NOTE_MAX = 500;

    /**
     * Practice (business) Hours block — a structured 7-day schedule (Mon..Sun),
     * each day {o:open, c:close, x:closed}, plus a free note. JSONB in the OWNER's
     * profile_sections under 'hours:p<id>'. Default visibility 'public' (a shop wants
     * its hours found), still capped by the practice-header ceiling at render.
     */
    public static function loadPracticeHours(int $ownerId, int $practiceId): array
    {
        $key = self::practiceBlockKey('hours', $practiceId);
        $s = Db::pg()->prepare("SELECT visibility, data FROM profile_sections WHERE user_id = :u AND key = :k");
        $s->execute([':u' => $ownerId, ':k' => $key]);
        $r = $s->fetch();
        $d = ($r && is_string($r['data'])) ? (json_decode($r['data'], true) ?: []) : [];
        $rawDays = (isset($d['days']) && is_array($d['days'])) ? $d['days'] : [];
        $days = []; $has = false;
        for ($i = 0; $i < 7; $i++) {
            $row = is_array($rawDays[$i] ?? null) ? $rawDays[$i] : [];
            $o = self::clampTime((string)($row['o'] ?? ''));
            $c = self::clampTime((string)($row['c'] ?? ''));
            $x = !empty($row['x']);
            $days[] = ['o' => $o, 'c' => $c, 'x' => $x];
            if ($x || $o !== '' || $c !== '') $has = true;
        }
        $note = (string)($d['note'] ?? '');
        if ($note !== '') $has = true;
        return [
            'block' => 'hours', 'has' => $has, 'days' => $days, 'note' => $note,
            'vis'   => self::normalizeVis(($r && in_array($r['visibility'], self::VIS_VALUES, true)) ? $r['visibility'] : 'public'),
        ];
    }

    /** Upsert the weekly hours. Accepts days[7] {o|open, c|close, x|closed} + note + visibility. */
    public static function savePracticeHours(int $ownerId, int $practiceId, array $in): array
    {
        $key = self::practiceBlockKey('hours', $practiceId);
        $inDays = (isset($in['days']) && is_array($in['days'])) ? $in['days'] : [];
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $row = is_array($inDays[$i] ?? null) ? $inDays[$i] : [];
            $days[] = [
                'o' => self::clampTime((string)($row['o'] ?? $row['open']  ?? '')),
                'c' => self::clampTime((string)($row['c'] ?? $row['close'] ?? '')),
                'x' => !empty($row['x']) || !empty($row['closed']),
            ];
        }
        $note = mb_substr(trim((string)($in['note'] ?? '')), 0, self::HOURS_NOTE_MAX);
        $data = json_encode(['days' => $days, 'note' => $note], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $vis = (array_key_exists('visibility', $in) && self::visFromInput($in['visibility']) !== null)
             ? self::visFromInput($in['visibility']) : null;
        if ($vis !== null) {
            Db::pg()->prepare("
                INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                VALUES (:u, :k, :v, :d::jsonb, 12)
                ON CONFLICT (user_id, key) DO UPDATE SET visibility = EXCLUDED.visibility, data = EXCLUDED.data, updated_at = now()
            ")->execute([':u' => $ownerId, ':k' => $key, ':v' => $vis, ':d' => $data]);
        } else {
            Db::pg()->prepare("
                INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                VALUES (:u, :k, 'public', :d::jsonb, 12)
                ON CONFLICT (user_id, key) DO UPDATE SET data = EXCLUDED.data, updated_at = now()
            ")->execute([':u' => $ownerId, ':k' => $key, ':d' => $data]);
        }
        return self::loadPracticeHours($ownerId, $practiceId);
    }

    /** HH:MM (24h) or empty. Anything malformed collapses to '' (treated as closed). */
    private static function clampTime(string $t): string
    {
        $t = trim($t);
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : '';
    }

    public const LINKS_LABEL_MAX = 60;
    public const LINKS_URL_MAX   = 400;
    public const LINKS_MAX_ITEMS = 20;

    /**
     * Practice (business) Links block — a free list of {label, url} (website +
     * socials), one JSONB blob in the OWNER's profile_sections under 'links:p<id>'.
     * URLs are sanitized to http(s) on save; default visibility 'public'.
     */
    public static function loadPracticeLinks(int $ownerId, int $practiceId): array
    {
        $key = self::practiceBlockKey('links', $practiceId);
        $s = Db::pg()->prepare("SELECT visibility, data FROM profile_sections WHERE user_id = :u AND key = :k");
        $s->execute([':u' => $ownerId, ':k' => $key]);
        $r = $s->fetch();
        $d = ($r && is_string($r['data'])) ? (json_decode($r['data'], true) ?: []) : [];
        $items = [];
        foreach ((array)($d['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $url = (string)($it['url'] ?? '');
            if ($url === '') continue;
            $items[] = ['label' => (string)($it['label'] ?? ''), 'url' => $url];
        }
        return [
            'block' => 'links', 'has' => !empty($items), 'items' => $items,
            'vis'   => self::normalizeVis(($r && in_array($r['visibility'], self::VIS_VALUES, true)) ? $r['visibility'] : 'public'),
        ];
    }

    /** Upsert the links list. Items: [{label, url}]; bad/empty URLs are dropped. */
    public static function savePracticeLinks(int $ownerId, int $practiceId, array $items, ?string $visInput = null): array
    {
        $key   = self::practiceBlockKey('links', $practiceId);
        $clean = [];
        foreach ($items as $it) {
            if (!is_array($it) || count($clean) >= self::LINKS_MAX_ITEMS) continue;
            $url = self::sanitizeLinkUrl((string)($it['url'] ?? ''));
            if ($url === '') continue;
            $label = mb_substr(trim((string)($it['label'] ?? '')), 0, self::LINKS_LABEL_MAX);
            if ($label === '') {
                $host  = (string)parse_url($url, PHP_URL_HOST);
                $label = mb_substr($host !== '' ? preg_replace('/^www\./', '', $host) : $url, 0, self::LINKS_LABEL_MAX);
            }
            $clean[] = ['label' => $label, 'url' => $url];
        }
        $data = json_encode(['items' => $clean], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $vis = ($visInput !== null && self::visFromInput($visInput) !== null) ? self::visFromInput($visInput) : null;
        if ($vis !== null) {
            Db::pg()->prepare("
                INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                VALUES (:u, :k, :v, :d::jsonb, 60)
                ON CONFLICT (user_id, key) DO UPDATE SET visibility = EXCLUDED.visibility, data = EXCLUDED.data, updated_at = now()
            ")->execute([':u' => $ownerId, ':k' => $key, ':v' => $vis, ':d' => $data]);
        } else {
            Db::pg()->prepare("
                INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                VALUES (:u, :k, 'public', :d::jsonb, 60)
                ON CONFLICT (user_id, key) DO UPDATE SET data = EXCLUDED.data, updated_at = now()
            ")->execute([':u' => $ownerId, ':k' => $key, ':d' => $data]);
        }
        return self::loadPracticeLinks($ownerId, $practiceId);
    }

    /** Normalize a user-entered URL to a safe absolute http(s) URL, or '' if invalid. */
    private static function sanitizeLinkUrl(string $u): string
    {
        $u = trim($u);
        if ($u === '') return '';
        if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
        $u = filter_var($u, FILTER_SANITIZE_URL);
        if ($u === false || !filter_var($u, FILTER_VALIDATE_URL)) return '';
        $scheme = strtolower((string)parse_url($u, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? mb_substr($u, 0, self::LINKS_URL_MAX) : '';
    }

    // ---------- block: resume (single PDF; profile-only) ----------

    /**
     * Assemble the resume block — versioned PDF URL + per-resume visibility.
     * Unlike most blocks the visibility lives on the users table (resume_visibility),
     * not a profile_sections row: resume is a singleton credential per user, not a
     * composable section with arbitrary data. Returns null for unknown user.
     */
    public static function loadResume(int $userId): ?array
    {
        $s = Db::pg()->prepare('SELECT resume_url, resume_version, resume_visibility FROM users WHERE id = :i');
        $s->execute([':i' => $userId]);
        $r = $s->fetch();
        if (!$r) return null;
        $vis = in_array($r['resume_visibility'], self::VIS_VALUES, true) ? $r['resume_visibility'] : 'members';
        return [
            'block'   => 'resume',
            'subject' => 'person',
            'vis'     => self::normalizeVis($vis),
            'url'     => $r['resume_url'] !== null ? (string)$r['resume_url'] : null,
            'version' => (int)($r['resume_version'] ?? 0),
        ];
    }

    // ---------- block: gallery (image grid; shared, profile + practice) ----------

    public const GALLERY_KEY            = 'gallery';
    public const GALLERY_URL_BASE       = '/profile-media/gallery';
    public const GALLERY_MAX            = 10;
    public const GALLERY_DISPLAY_MODES  = ['grid', 'carousel'];
    public const GALLERY_DISPLAY_DEFAULT = 'grid';

    private static function userUuid(int $userId): ?string
    {
        $s = Db::pg()->prepare('SELECT uuid FROM users WHERE id = :i');
        $s->execute([':i' => $userId]);
        $u = $s->fetchColumn();
        return $u === false ? null : strtolower((string)$u);
    }

    /** Assemble the gallery block — image list + block vis (profile_sections key='gallery'). */
    public static function loadGallery(int $userId): array
    {
        $s = Db::pg()->prepare("SELECT visibility, data FROM profile_sections WHERE user_id = :u AND key = 'gallery'");
        $s->execute([':u' => $userId]);
        $r = $s->fetch();
        $images = [];
        $title  = '';
        $mode   = self::GALLERY_DISPLAY_DEFAULT;
        if ($r) {
            $d = json_decode((string)$r['data'], true) ?: [];
            $title = (string)($d['title'] ?? '');
            $rawMode = (string)($d['display_mode'] ?? '');
            if (in_array($rawMode, self::GALLERY_DISPLAY_MODES, true)) $mode = $rawMode;
            foreach (($d['images'] ?? []) as $im) {
                if (is_array($im) && !empty($im['url'])) {
                    $images[] = ['url' => (string)$im['url'], 'caption' => (string)($im['caption'] ?? '')];
                }
            }
        }
        return [
            'block'        => 'gallery',
            'subject'      => 'person',
            'vis'          => self::normalizeVis(($r && in_array($r['visibility'], self::VIS_VALUES, true)) ? $r['visibility'] : 'members'),
            'title'        => $title,
            'display_mode' => $mode,
            'images'       => $images,
        ];
    }

    /**
     * Persist the gallery image list (used for add/remove/reorder/caption). Sanitizes
     * to URLs under THIS user's gallery dir (no foreign URLs). visInput optional.
     */
    public static function saveGalleryImages(int $userId, array $images, ?string $visInput = null, ?string $title = null, ?string $displayMode = null): array
    {
        $uuid   = self::userUuid($userId);
        $prefix = self::GALLERY_URL_BASE . '/' . $uuid . '/';
        $clean  = [];
        foreach ($images as $im) {
            if (!is_array($im)) continue;
            $url = (string)($im['url'] ?? '');
            if ($uuid === null || strpos($url, $prefix) !== 0) continue;     // only this user's images
            $clean[] = ['url' => $url, 'caption' => mb_substr((string)($im['caption'] ?? ''), 0, 200)];
            if (count($clean) >= self::GALLERY_MAX) break;
        }
        // Previous state — needed both for the keep-fallback on a partial PUT
        // (null params keep whatever's stored) AND to GC files dropped from the
        // gallery below (orphan cleanup).
        $prev = self::loadGallery($userId);
        $finalTitle = ($title === null)
            ? $prev['title']
            : mb_substr(trim($title), 0, 80);
        $finalMode = ($displayMode === null)
            ? $prev['display_mode']
            : (in_array($displayMode, self::GALLERY_DISPLAY_MODES, true) ? $displayMode : self::GALLERY_DISPLAY_DEFAULT);
        $payload = ['images' => $clean];
        if ($finalTitle !== '') $payload['title'] = $finalTitle;
        if ($finalMode !== self::GALLERY_DISPLAY_DEFAULT) $payload['display_mode'] = $finalMode;
        $data = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($visInput !== null && self::visFromInput($visInput) !== null) {
            Db::pg()->prepare("
                INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                VALUES (:u, 'gallery', :v, :d::jsonb, 40)
                ON CONFLICT (user_id, key) DO UPDATE SET visibility = EXCLUDED.visibility, data = EXCLUDED.data, updated_at = now()
            ")->execute([':u' => $userId, ':v' => self::visFromInput($visInput), ':d' => $data]);
        } else {
            Db::pg()->prepare("
                INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
                VALUES (:u, 'gallery', 'members', :d::jsonb, 40)
                ON CONFLICT (user_id, key) DO UPDATE SET data = EXCLUDED.data, updated_at = now()
            ")->execute([':u' => $userId, ':d' => $data]);
        }

        // GC files dropped from the gallery: any previously-stored URL not in the
        // new set is now orphaned on disk → unlink it + its resizer cache twins.
        // After the row is persisted, so a failed write never deletes live files.
        $newUrls = array_column($clean, 'url');
        foreach (($prev['images'] ?? []) as $pi) {
            $pu = (string)($pi['url'] ?? '');
            if ($pu !== '' && !in_array($pu, $newUrls, true)) {
                Media::unlinkUrl($pu);
            }
        }

        return self::loadGallery($userId);
    }

    // ---------- block: socials / links (website + platforms) ----------

    /**
     * Assemble the socials/links block — website (kind='web') + the other social
     * links, one block-level vis (profile_sections key='socials'). kind + url only.
     * NOTE: the inc-1 header also renders an inline social row; this is the
     * canonical managed list. Overlap flagged for coordinator ruling.
     */
    public static function loadSocials(int $userId): ?array
    {
        $pg = Db::pg();
        $exists = $pg->prepare('SELECT 1 FROM users WHERE id = :i');
        $exists->execute([':i' => $userId]);
        if (!$exists->fetchColumn()) return null;

        $website = null;
        $links   = [];
        $all     = [];   // every row in stored order (incl. web) — drives the block + reordering
        $q = $pg->prepare('SELECT kind, value FROM profile_socials WHERE user_id = :u ORDER BY sort_order, id');
        $q->execute([':u' => $userId]);
        while ($r = $q->fetch()) {
            $all[] = ['kind' => $r['kind'], 'url' => $r['value']];
            if ($r['kind'] === 'web' && $website === null) {
                $website = $r['value'];           // first web → header idrow convenience
            } else {
                $links[] = ['kind' => $r['kind'], 'url' => $r['value']];
            }
        }

        return [
            'block'   => 'socials',
            'subject' => 'person',
            'vis'     => self::normalizeVis(self::blockVisibility($userId, self::SOCIALS_KEY, 'members')),
            'fields'  => ['website' => $website, 'links' => $links, 'ordered' => $all],
        ];
    }

    // ---------- block: connect (the person's connections surface) ----------

    /** Max connection avatars previewed inline in the block. */
    private const CONNECT_PREVIEW = 12;

    /**
     * Assemble the connect block — the person's connections surface — built ON the
     * social-layer `Connections` backend (NOT re-implemented). Block-level pmp on
     * profile_sections key='connect', ceiling-capped by the header at render.
     *
     *   count        — accepted connections (the headline)
     *   connections  — up to CONNECT_PREVIEW hydrated {uuid,name,slug,avatar}
     *   mutuals      — shared accepted connections with the viewer (visitor view only)
     *   pending_in / pending_out — owner's request inbox/outbox counts (owner view only)
     *
     * The Connect / Message *actions* stay in the header slot (Social::renderProfileActions);
     * this block is the connections LIST/COUNT surface (division flagged for coordinator).
     *
     * @param int      $userId         subject (whose profile this is)
     * @param int|null $viewerUserId   the viewer's user id (for mutuals + owner inbox); null = anon
     */
    public static function loadConnect(int $userId, ?int $viewerUserId = null): ?array
    {
        $pg = Db::pg();
        $u = $pg->prepare('SELECT uuid FROM users WHERE id = :i');
        $u->execute([':i' => $userId]);
        $subjectUuid = $u->fetchColumn();
        if ($subjectUuid === false) return null;
        $subjectUuid = (string)$subjectUuid;

        $lists    = Connections::listFor($subjectUuid);
        $accepted = $lists['accepted'] ?? [];
        $isOwner  = ($viewerUserId !== null && $viewerUserId === $userId);

        // Reciprocal privacy (Ian, 2026-05-31): showing connection B in A's list
        // exposes B too — so a non-owner only sees connections whose OWN connect
        // block is visible to that audience. A connection with no connect block (→
        // member default) or a private one stays hidden on the public front even if
        // A's connect block is public. Owner always sees all of their own connections.
        if (!$isOwner && $accepted) {
            $audience = ($viewerUserId !== null) ? 'member' : 'public';
            $visMap   = self::connectVisFor(array_column($accepted, 'uuid'));
            $accepted = array_values(array_filter($accepted, static function ($b) use ($audience, $visMap) {
                $v = $visMap[$b['uuid']] ?? ['connect' => 'members', 'header' => 'members'];
                return self::canSee($audience, $v['header'], $v['connect']);
            }));
        }

        $shape = static fn(array $r): array => [
            'uuid'   => $r['uuid'],
            'name'   => $r['display_name'],
            'slug'   => $r['slug'] ?: (string)$r['uuid'],
            'avatar' => $r['avatar_url'],
        ];

        // Mutuals: shared accepted connections between viewer and subject (visitor view).
        $mutuals = [];
        if (!$isOwner && $viewerUserId !== null) {
            $vu = $pg->prepare('SELECT uuid FROM users WHERE id = :i');
            $vu->execute([':i' => $viewerUserId]);
            $viewerUuid = $vu->fetchColumn();
            if ($viewerUuid !== false) {
                $viewerSet = [];
                foreach (Connections::listFor((string)$viewerUuid)['accepted'] ?? [] as $r) {
                    $viewerSet[$r['uuid']] = true;
                }
                foreach ($accepted as $r) {
                    if (isset($viewerSet[$r['uuid']])) $mutuals[] = $shape($r);
                }
            }
        }

        $fields = [
            'count'       => count($accepted),
            'connections' => array_map($shape, array_slice($accepted, 0, self::CONNECT_PREVIEW)),
            'mutuals'     => $mutuals,
        ];
        if ($isOwner) {
            $fields['pending_in']  = count($lists['pending_in']  ?? []);
            $fields['pending_out'] = count($lists['pending_out'] ?? []);
        }

        return [
            'block'   => 'connect',
            'subject' => 'person',
            'vis'     => self::normalizeVis(self::blockVisibility($userId, self::CONNECT_KEY, 'members')),
            'fields'  => $fields,
        ];
    }

    /**
     * Batch: each uuid's OWN connect-block vis + header ceiling (DB literals), for the
     * reciprocal-privacy filter in loadConnect. Missing rows default to 'members'.
     * @return array<string,array{connect:string,header:string}>
     */
    private static function connectVisFor(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter($uuids)));
        if (!$uuids) return [];
        $ph = implode(',', array_fill(0, count($uuids), '?'));
        $st = Db::pg()->prepare(
            "SELECT u.uuid,
                    COALESCE(pc.visibility, 'members') AS connect_vis,
                    COALESCE(ph.visibility, 'members') AS header_vis
               FROM users u
               LEFT JOIN profile_sections pc ON pc.user_id = u.id AND pc.key = 'connect'
               LEFT JOIN profile_sections ph ON ph.user_id = u.id AND ph.key = 'header'
              WHERE u.uuid IN ($ph)"
        );
        $st->execute($uuids);
        $out = [];
        while ($r = $st->fetch()) {
            $out[(string)$r['uuid']] = ['connect' => (string)$r['connect_vis'], 'header' => (string)$r['header_vis']];
        }
        return $out;
    }

    // ---------- block: practice-header (the required /p/ header) ----------

    public const PRACTICE_HEADER_DEFAULT = 'members';

    /**
     * Where a practice's header (ceiling) vis lives WITHOUT new schema: a
     * namespaced row in the OWNER's profile_sections, key='practice-header:<id>'.
     * (A dedicated practice_sections table is the clean future home — flagged.)
     */
    private static function practiceHeaderKey(int $practiceId): string
    {
        return 'practice-header:' . $practiceId;
    }

    /**
     * The reorderable storefront blocks for a business (practice) page — the
     * practice analogue of LAYOUT_BLOCKS. practice-header is pinned (rendered
     * first) and the staff roster is auto/pinned (rendered last), so neither
     * appears here. WS3 widens this set (services, gallery, links, drop-offs).
     */
    public const PRACTICE_LAYOUT_BLOCKS = [
        'about'    => ['label' => 'About', 'removable' => true],
        'location' => ['label' => 'Location', 'removable' => true],
        'dropoffs' => ['label' => 'Drop-off Locations', 'removable' => true],
        'hours'    => ['label' => 'Hours', 'removable' => true],
        'links'    => ['label' => 'Links', 'removable' => true],
    ];

    /**
     * Storage key for a practice storefront block, living in the OWNER's
     * profile_sections (same no-migration convention as practiceHeaderKey).
     * e.g. 'about:p42'. Won't collide with profile keys or the freeform: prefix.
     */
    public static function practiceBlockKey(string $block, int $practiceId): string
    {
        return $block . ':p' . $practiceId;
    }

    /** Storage key for a practice's block ORDER, in the OWNER's profile_sections. */
    private static function practiceLayoutKey(int $practiceId): string
    {
        return 'practice-layout:' . $practiceId;
    }

    /**
     * The storefront block order for a practice page (header + staff excluded —
     * both pinned at render). Persisted WITHOUT new schema as {order:[...]} in
     * the OWNER's profile_sections under 'practice-layout:<id>'. NULL / never-set
     * → all registry blocks present (opt-in default = array_keys of the registry).
     */
    public static function practiceLayout(int $practiceId): array
    {
        $owner = self::practiceOwnerId($practiceId);
        if ($owner === null) return [];
        $s = Db::pg()->prepare('SELECT data FROM profile_sections WHERE user_id = :u AND key = :k');
        $s->execute([':u' => $owner, ':k' => self::practiceLayoutKey($practiceId)]);
        $raw = $s->fetchColumn();
        if ($raw === false) return array_keys(self::PRACTICE_LAYOUT_BLOCKS);   // never-set → default
        $d = is_string($raw) ? json_decode($raw, true) : null;
        $order = (is_array($d) && isset($d['order']) && is_array($d['order'])) ? $d['order'] : null;
        if ($order === null) return array_keys(self::PRACTICE_LAYOUT_BLOCKS);
        return self::normalizePracticeLayout($order);
    }

    /** Persist a practice's block order (validated subset of the registry, de-duped). */
    public static function savePracticeLayout(int $practiceId, array $order): array
    {
        $owner = self::practiceOwnerId($practiceId);
        if ($owner === null) return [];
        $clean = self::normalizePracticeLayout($order);
        Db::pg()->prepare("
            INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
            VALUES (:u, :k, 'members', :d::jsonb, 0)
            ON CONFLICT (user_id, key) DO UPDATE
               SET data = EXCLUDED.data, updated_at = now()
        ")->execute([
            ':u' => $owner,
            ':k' => self::practiceLayoutKey($practiceId),
            ':d' => json_encode(['order' => $clean]),
        ]);
        return $clean;
    }

    /** Filter an arbitrary key list to known practice layout keys, in order, de-duped. */
    private static function normalizePracticeLayout(array $order): array
    {
        $seen = [];
        $out  = [];
        foreach ($order as $k) {
            if (is_string($k) && isset(self::PRACTICE_LAYOUT_BLOCKS[$k]) && !isset($seen[$k])) {
                $seen[$k] = true;
                $out[]    = $k;
            }
        }
        return $out;
    }

    /** Practice blocks not currently placed — the caddy's "available to add" set [key=>label]. */
    public static function practiceAvailableBlocks(int $practiceId): array
    {
        $present = array_flip(self::practiceLayout($practiceId));
        $out = [];
        foreach (self::PRACTICE_LAYOUT_BLOCKS as $k => $cfg) {
            if (!isset($present[$k])) $out[$k] = (string) $cfg['label'];
        }
        return $out;
    }

    /** The practice owner's profile-app user id (practices.created_by). */
    public static function practiceOwnerId(int $practiceId): ?int
    {
        $s = Db::pg()->prepare('SELECT created_by FROM practices WHERE id = :p AND archived_at IS NULL');
        $s->execute([':p' => $practiceId]);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? null : (int) $v;
    }

    /** Owner = practices.created_by, or an explicit practice_members role='owner'. */
    public static function isPracticeOwner(int $practiceId, int $userId): bool
    {
        if ($userId <= 0) return false;
        if (self::practiceOwnerId($practiceId) === $userId) return true;
        $s = Db::pg()->prepare("SELECT 1 FROM practice_members WHERE practice_id = :p AND user_id = :u AND role = 'owner'");
        $s->execute([':p' => $practiceId, ':u' => $userId]);
        return (bool) $s->fetchColumn();
    }

    /** The practice header ceiling vis (DB literal). Stored on the owner's profile_sections. */
    public static function practiceHeaderCeiling(int $practiceId): string
    {
        $owner = self::practiceOwnerId($practiceId);
        if ($owner === null) return self::PRACTICE_HEADER_DEFAULT;
        return self::blockVisibility($owner, self::practiceHeaderKey($practiceId), self::PRACTICE_HEADER_DEFAULT);
    }

    /**
     * Assemble the practice-header block from `practices` + the owner's spine
     * (single-source avatar + city/region). vis normalized to 'member'. Null if
     * the practice doesn't exist / is archived.
     */
    public static function loadPracticeHeader(int $practiceId): ?array
    {
        $s = Db::pg()->prepare(
            'SELECT p.id, p.name, p.type, p.tagline, p.website, p.avatar_url AS practice_avatar,
                    o.avatar_url AS owner_avatar, o.location_city AS city, o.location_region AS region
             FROM practices p LEFT JOIN users o ON o.id = p.created_by
             WHERE p.id = :p AND p.archived_at IS NULL'
        );
        $s->execute([':p' => $practiceId]);
        $r = $s->fetch();
        if (!$r) return null;

        return [
            'block'       => 'practice-header',
            'subject'     => 'practice',
            'practice_id' => (int) $r['id'],
            'vis'         => self::normalizeVis(self::practiceHeaderCeiling($practiceId)),
            'fields'      => [
                'name'    => $r['name'],
                'type'    => $r['type'],
                'tagline' => $r['tagline'],
                'website' => $r['website'],
                'avatar'  => $r['owner_avatar'] ?: $r['practice_avatar'],   // owner single-source; practice as fallback
                'city'    => $r['city'],
                'region'  => $r['region'],
            ],
        ];
    }

    /**
     * Persist the practice header's ceiling visibility (the only editable bit here).
     * Caller must confirm ownership for the right status code; this re-checks as
     * defense-in-depth. Returns the re-assembled block, or null on permission /
     * invalid-vis failure.
     */
    public static function savePracticeHeader(int $practiceId, int $editorUserId, array $fields): ?array
    {
        if (!self::isPracticeOwner($practiceId, $editorUserId)) return null;
        $owner = self::practiceOwnerId($practiceId) ?? $editorUserId;
        if (array_key_exists('visibility', $fields)) {
            if (self::saveBlockVisibility($owner, self::practiceHeaderKey($practiceId), $fields['visibility'], 0) === null) {
                return null;   // invalid visibility
            }
        }
        return self::loadPracticeHeader($practiceId);
    }
}
