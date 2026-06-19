<?php
/**
 * Looth secrets manifest — credential/bucket model (v2). Scope: R2 + Patreon API.
 *
 * NO VALUES here; git-tracked. Box paths derive from /etc/looth/env.
 *
 * Key idea: a CREDENTIAL is shown ONCE even when it lives in several places.
 * Each entry carries a `locations` array; the helper READS the first present
 * location and WRITES every location (so rotating a key updates all its twins
 * at once — no divergence). `buckets` maps a credential to the R2 buckets it
 * serves (for the bucket-first view).
 *
 * Entry:
 *   label, group (r2|patreon|cloudflare), sensitivity (high|medium|low),
 *   writable (bool), note?, buckets? (string[]),
 *   locations => [ {kind, ...}, ... ]   // 1+ places this same value lives
 * Location kinds:
 *   env_kv    => path, key
 *   rclone    => path, remote, field
 *   wp_option => option
 *   file      => path            (whole-file value, e.g. CF token)
 *
 * Bucket inventory (display) is declared in lg_secrets_buckets(); when a CF API
 * token is present the helper's `buckets` command supersedes it with live data.
 */

function lg_secrets_env(): array {
    static $env = null;
    if ($env !== null) return $env;
    $env = [];
    foreach (@file('/etc/looth/env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
    return $env;
}

/** R2 account id = the S3 endpoint subdomain. */
function lg_secrets_r2_account(): string {
    return lg_secrets_env()['LG_R2_ACCOUNT'] ?? '2b34fc01f7fc32230a76c1490ac64b13';
}

/** Known bucket inventory (fallback when no CF token). credential = manifest cred id. */
function lg_secrets_buckets(): array {
    return [
        ['name' => 'loothgroup-2-0-profile-dev',   'env' => 'dev',  'cred' => 'cred-profile', 'verified' => true],
        ['name' => 'loothgroup-uploads-dev',       'env' => 'dev',  'cred' => 'cred-profile', 'verified' => true],
        ['name' => 'loothgroup-backups',           'env' => 'ops',  'cred' => 'cred-backups', 'verified' => false],
        ['name' => 'loothgroup2-0-profile-bucket', 'env' => 'live', 'cred' => 'cred-live',    'verified' => false],
        ['name' => 'loothgroup2-0',                'env' => 'live', 'cred' => 'cred-live',    'verified' => false],
        ['name' => 'loothgroup',                   'env' => 'live', 'cred' => 'cred-live',    'verified' => false],
        ['name' => 'test-dev',                     'env' => 'test', 'cred' => 'cred-test',    'verified' => false],
    ];
}

function lg_secrets_manifest(): array {
    $RC = lg_secrets_env()['LG_RCLONE_CONF'] ?? '/home/ubuntu/.config/rclone/rclone.conf';
    $PR = '/etc/looth/profile-r2';
    $m  = [];

    // ───────── R2 credential: profile/uploads (token lives in 2 places) ─────────
    $m['cred-profile-key'] = [
        'label' => 'Profile/uploads — access key', 'group' => 'r2',
        'sensitivity' => 'medium', 'writable' => true,
        'buckets' => ['loothgroup-2-0-profile-dev', 'loothgroup-uploads-dev'],
        'note' => 'one token used by profile-r2 + rclone[r2up]',
        'locations' => [
            ['kind' => 'env_kv', 'path' => $PR, 'key' => 'key'],
            ['kind' => 'rclone', 'path' => $RC, 'remote' => 'r2up', 'field' => 'access_key_id'],
        ],
    ];
    $m['cred-profile-secret'] = [
        'label' => 'Profile/uploads — secret', 'group' => 'r2',
        'sensitivity' => 'high', 'writable' => true,
        'buckets' => ['loothgroup-2-0-profile-dev', 'loothgroup-uploads-dev'],
        'locations' => [
            ['kind' => 'env_kv', 'path' => $PR, 'key' => 'secret'],
            ['kind' => 'rclone', 'path' => $RC, 'remote' => 'r2up', 'field' => 'secret_access_key'],
        ],
    ];

    // ───────── R2 credential: backups (rclone r2backups + cfbk twins) ─────────
    $m['cred-backups-key'] = [
        'label' => 'Backups — access key', 'group' => 'r2',
        'sensitivity' => 'medium', 'writable' => true, 'buckets' => ['loothgroup-backups'],
        'note' => 'rclone[r2backups] + rclone[cfbk]; 403 from dev (live/IP-scoped)',
        'locations' => [
            ['kind' => 'rclone', 'path' => $RC, 'remote' => 'r2backups', 'field' => 'access_key_id'],
            ['kind' => 'rclone', 'path' => $RC, 'remote' => 'cfbk',      'field' => 'access_key_id'],
        ],
    ];
    $m['cred-backups-secret'] = [
        'label' => 'Backups — secret', 'group' => 'r2',
        'sensitivity' => 'high', 'writable' => true, 'buckets' => ['loothgroup-backups'],
        'locations' => [
            ['kind' => 'rclone', 'path' => $RC, 'remote' => 'r2backups', 'field' => 'secret_access_key'],
            ['kind' => 'rclone', 'path' => $RC, 'remote' => 'cfbk',      'field' => 'secret_access_key'],
        ],
    ];

    // ───────── R2 credential: live (rclone r2live) ─────────
    $m['cred-live-key'] = [
        'label' => 'LIVE — access key', 'group' => 'r2',
        'sensitivity' => 'medium', 'writable' => true,
        'buckets' => ['loothgroup2-0-profile-bucket', 'loothgroup2-0', 'loothgroup'],
        'note' => 'live bucket token; 403 from dev by design',
        'locations' => [['kind' => 'rclone', 'path' => $RC, 'remote' => 'r2live', 'field' => 'access_key_id']],
    ];
    $m['cred-live-secret'] = [
        'label' => 'LIVE — secret', 'group' => 'r2',
        'sensitivity' => 'high', 'writable' => true,
        'buckets' => ['loothgroup2-0-profile-bucket', 'loothgroup2-0', 'loothgroup'],
        'locations' => [['kind' => 'rclone', 'path' => $RC, 'remote' => 'r2live', 'field' => 'secret_access_key']],
    ];

    // ───────── R2 credentials flagged DEAD (401 everywhere from dev) ─────────
    $m['cred-r2-key'] = [
        'label' => 'rclone[r2] — access key', 'group' => 'r2',
        'sensitivity' => 'low', 'writable' => true, 'note' => 'DEAD: 401 on all buckets — replace or remove',
        'locations' => [['kind' => 'rclone', 'path' => $RC, 'remote' => 'r2', 'field' => 'access_key_id']],
    ];
    $m['cred-r2-secret'] = [
        'label' => 'rclone[r2] — secret', 'group' => 'r2',
        'sensitivity' => 'medium', 'writable' => true, 'note' => 'DEAD token',
        'locations' => [['kind' => 'rclone', 'path' => $RC, 'remote' => 'r2', 'field' => 'secret_access_key']],
    ];
    $m['cred-test-key'] = [
        'label' => 'rclone[r2test] — access key', 'group' => 'r2',
        'sensitivity' => 'low', 'writable' => true, 'note' => 'DEAD: 401 on all buckets', 'buckets' => ['test-dev'],
        'locations' => [['kind' => 'rclone', 'path' => $RC, 'remote' => 'r2test', 'field' => 'access_key_id']],
    ];
    $m['cred-test-secret'] = [
        'label' => 'rclone[r2test] — secret', 'group' => 'r2',
        'sensitivity' => 'medium', 'writable' => true, 'note' => 'DEAD token',
        'locations' => [['kind' => 'rclone', 'path' => $RC, 'remote' => 'r2test', 'field' => 'secret_access_key']],
    ];

    // ───────── Cloudflare REST API token (enables live bucket discovery) ─────────
    $m['cf-api-token'] = [
        'label' => 'Cloudflare API token (R2 Read)', 'group' => 'cloudflare',
        'sensitivity' => 'high', 'writable' => true,
        'note' => 'account-scoped, Workers R2 Storage: Read. Powers live bucket list + usage.',
        'locations' => [['kind' => 'file', 'path' => '/etc/looth/cf-api-token']],
    ];

    // ───────── Patreon API (poller lgpo_*) ─────────
    $patreon = [
        'lgpo-client-id'        => ['Patreon client ID',             'lgpo_client_id',             'medium', true],
        'lgpo-client-secret'    => ['Patreon client secret',         'lgpo_client_secret',         'high',   true],
        'lgpo-access-token'     => ['Patreon creator access token',  'lgpo_creator_access_token',  'high',   true],
        'lgpo-refresh-token'    => ['Patreon creator refresh token', 'lgpo_creator_refresh_token', 'high',   true],
        'lgpo-token-expires-at' => ['Patreon token expires (unix)',  'lgpo_creator_token_expires_at', 'low', false],
    ];
    foreach ($patreon as $id => [$label, $opt, $sens, $w]) {
        $m[$id] = [
            'label' => $label, 'group' => 'patreon', 'sensitivity' => $sens, 'writable' => $w,
            'locations' => [['kind' => 'wp_option', 'option' => $opt]],
        ];
    }

    return $m;
}
