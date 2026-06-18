<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') profile_app_json(405, ['error' => 'method_not_allowed']);

header('Cache-Control: public, max-age=300');

$pg = Db::pg();

$instruments = array_map(fn($r) => [
    'id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'],
    'type' => $r['type'], 'subtype' => $r['subtype'],
], $pg->query("SELECT id, slug, name, type, subtype FROM instrument_catalog WHERE active=true ORDER BY sort_order, name")->fetchAll());

$skills = array_map(fn($r) => [
    'id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'category' => $r['category'],
], $pg->query("SELECT id, slug, name, category FROM skill_catalog WHERE active=true ORDER BY category, sort_order, name")->fetchAll());

$credentials = array_map(fn($r) => [
    'id' => (int)$r['id'], 'slug' => $r['slug'], 'category' => $r['category'],
    'issuer' => $r['issuer'], 'program' => $r['program'],
], $pg->query("SELECT id, slug, category, issuer, program FROM credential_catalog WHERE active=true ORDER BY category, issuer, program")->fetchAll());

$scenes = $pg->query("SELECT slug, name FROM scene_tags WHERE active=true ORDER BY sort_order, name")->fetchAll();

$socialValidation = [
    'instagram' => ['format' => 'handle'],
    'youtube'   => ['format' => 'handle'],
    'bandcamp'  => ['format' => 'handle'],
    'web'       => ['format' => 'url',   'pattern' => '^https?://.+'],
    'email'     => ['format' => 'email', 'pattern' => '^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$'],
    'phone'     => ['format' => 'phone', 'pattern' => '^[\\d\\s\\-\\+\\(\\)\\.x]{4,}$'],
    'x'         => ['format' => 'handle'],
    'tiktok'    => ['format' => 'handle'],
    'facebook'  => ['format' => 'handle'],
    'patreon'   => ['format' => 'handle'],
];

$schema = [
    'version'  => 2,

    'payload_shape' => [
        'header' => [
            'display_name' => '<string>',
            'avatar_url'   => '<url|null>',
            'location'     => '<location_object>',
            'socials'      => '[<social_item>, …]',
            'highlights'   => '[<highlight_item>, …]   // max 3',
        ],
        'sections' => '{ <section_slug>: { "visibility": <enum>, "data": <jsonb> }, … }',
        'instruments' => '[ {instrument_id, sort_order}, … ]',
        'skills'      => '[ {skill_id, note?, sort_order}, … ]',
        'scenes'      => '[ <scene_slug>, … ]',
        'credentials' => '[ <credential_item>, … ]',
        'section_order' => '[ <section_slug>, … ]',
    ],

    'sections' => [
        'about' => [
            'kind' => 'text', 'mandatory' => false, 'default_visibility' => 'members',
            'fields' => [
                ['name' => 'text',       'type' => 'markdown', 'max_chars' => 8000],
                ['name' => 'visibility', 'type' => 'enum', 'values' => Profile::VIS_VALUES, 'default' => 'members'],
            ],
            'shipped_in' => 'slice-one',
        ],
        'instruments' => [
            'kind' => 'catalog_list', 'mandatory' => false, 'default_visibility' => 'public',
            'catalog' => 'instruments', 'item_schema' => [
                'instrument_id' => ['type' => 'int', 'fk' => 'instruments.id'],
                'sort_order'    => ['type' => 'int'],
            ],
            'endpoint' => 'PUT /me/instruments',
            'shipped_in' => 'slice-two',
        ],
        'skills' => [
            'kind' => 'catalog_list', 'mandatory' => false, 'default_visibility' => 'public',
            'catalog' => 'skills', 'item_schema' => [
                'skill_id'   => ['type' => 'int', 'fk' => 'skills.id'],
                'note'       => ['type' => 'string', 'max_chars' => 200, 'optional' => true],
                'sort_order' => ['type' => 'int'],
            ],
            'endpoint' => 'PUT /me/skills',
            'shipped_in' => 'slice-two',
        ],
        'credentials' => [
            'kind' => 'catalog_with_freetext', 'mandatory' => false,
            'default_visibility' => 'members',
            'catalog' => 'credentials',
            'item_schema' => [
                'catalog_id'   => ['type' => 'int',     'fk' => 'credentials.id', 'optional' => true],
                'raw_issuer'   => ['type' => 'string',  'max_chars' => 200, 'required' => true],
                'raw_program'  => ['type' => 'string',  'max_chars' => 200, 'required' => true],
                'identifier'   => ['type' => 'string',  'max_chars' => 200, 'optional' => true],
                'issued_at'    => ['type' => 'date',    'optional' => true],
                'expires_at'   => ['type' => 'date',    'optional' => true],
                'evidence_url' => ['type' => 'url',     'optional' => true],
                'visibility'   => ['type' => 'enum',    'values' => Profile::VIS_VALUES, 'default' => 'members'],
                'sort_order'   => ['type' => 'int'],
            ],
            'endpoints' => [
                'create' => 'POST /me/credentials',
                'update' => 'PATCH /me/credentials/<id>',
                'delete' => 'DELETE /me/credentials/<id>',
            ],
            'shipped_in' => 'slice-two',
        ],
        'scenes' => [
            'kind' => 'catalog_pillset', 'mandatory' => false, 'default_visibility' => 'public',
            'catalog' => 'scenes', 'item_schema' => ['scene_slug' => ['type' => 'string', 'fk' => 'scenes.slug']],
            'endpoint' => 'PUT /me/scenes',
            'shipped_in' => 'slice-two',
        ],
        'practices' => [
            'kind' => 'placeholder', 'mandatory' => false, 'default_visibility' => 'public',
            'available_in' => 'slice-three',
        ],
    ],

    'header_fields' => [
        'display_name' => ['type' => 'string', 'max_chars' => 120, 'mandatory' => true, 'endpoint' => 'PATCH /me/name'],
        'avatar_url'   => ['type' => 'url', 'editable' => false, 'note' => 'sourced from WP/BB; upload arrives later'],
        'location' => [
            'type' => 'composite', 'endpoint' => 'PUT /me/location',
            'fields' => [
                ['name' => 'nominatim', 'type' => 'nominatim_search_row',
                 'note' => 'Picker stores the picked result verbatim — text + lat/lng + components'],
                ['name' => 'text_only', 'type' => 'string',
                 'note' => 'Escape hatch for zero-result picker state. Saves text with null coords.'],
                ['name' => 'location_visibility', 'type' => 'enum',
                 'values'  => Profile::LOCATION_VIS_VALUES,
                 'default' => 'members',
                 'note'    => 'Single-field autosave from the visibility radio.'],
            ],
        ],
        'socials' => [
            'type' => 'list', 'endpoint' => 'PUT /me/socials',
            'item_schema' => [
                'kind'       => ['type' => 'enum', 'values' => Profile::SOCIAL_KINDS],
                'value'      => ['type' => 'string', 'validation_by_kind' => $socialValidation],
                'sort_order' => ['type' => 'int'],
            ],
        ],
        'highlights' => [
            'type' => 'list', 'endpoint' => 'PUT /me/highlights',
            'max_items' => Profile::HIGHLIGHTS_MAX,
            'item_schema' => [
                'kind'       => ['type' => 'enum', 'values' => Profile::HIGHLIGHT_KINDS,
                                 'note' => 'discriminator — ref_id points into the matching catalog'],
                'ref_id'     => ['type' => 'int', 'fk_by_kind' => ['instrument' => 'instruments.id', 'skill' => 'skills.id']],
                'sort_order' => ['type' => 'int'],
            ],
            'note' => 'Clicking a saved highlight chip navigates to /directory/members?inst=<slug> or ?skill=<slug>',
        ],
    ],

    'catalogs' => [
        'instruments' => $instruments,
        'skills'      => $skills,
        'credentials' => $credentials,
        'scenes'      => $scenes,
    ],

    'viewer_roles'               => ['me', 'friend', 'member', 'public'],
    'visibility_options'          => Profile::VIS_VALUES,
    'location_visibility_options' => Profile::LOCATION_VIS_VALUES,
    'social_kinds'                => Profile::SOCIAL_KINDS,
    'highlight_kinds'            => Profile::HIGHLIGHT_KINDS,

    'endpoints' => [
        'me_full'         => 'GET /profile-api/v0/me',
        'public_read'     => 'GET /profile-api/v0/user/<uuid>',
        'public_view'     => 'GET /u/<slug>',
        'directory_list'  => 'GET /profile-api/v0/directory/members?loc=...&radius=...&inst=...&skill=...&scene=...&cred=...',
        'directory_page'  => 'GET /directory/members',
        'claim'           => 'POST /profile-api/v0/me/claim',
        'section_order'   => 'PATCH /profile-api/v0/me/section-order',
        'name'            => 'PATCH /profile-api/v0/me/name',
        'about'           => 'PATCH /profile-api/v0/me/about',
        'location'        => 'PUT /profile-api/v0/me/location',
        'socials'         => 'PUT /profile-api/v0/me/socials',
        'instruments'     => 'PUT /profile-api/v0/me/instruments',
        'skills'          => 'PUT /profile-api/v0/me/skills',
        'scenes'          => 'PUT /profile-api/v0/me/scenes',
        'highlights'      => 'PUT /profile-api/v0/me/highlights',
        'credential_new'  => 'POST /profile-api/v0/me/credentials',
        'credential_edit' => 'PATCH /profile-api/v0/me/credentials/<id>',
        'credential_del'  => 'DELETE /profile-api/v0/me/credentials/<id>',
        'catalogs'        => 'GET /profile-api/v0/catalogs/{instruments|skills|scenes|credentials}',
        'reports'         => 'POST /profile-api/v0/reports',
    ],
];

profile_app_json(200, $schema);
