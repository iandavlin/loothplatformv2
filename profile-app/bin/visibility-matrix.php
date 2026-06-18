<?php
declare(strict_types=1);

/**
 * bin/visibility-matrix.php — THE visibility regression gate (Ian 6/12).
 *
 * Drives REAL HTTP against the live dev site as the four viewer classes
 *   anon / member / owner / admin
 * across every surface the Visibility module guards:
 *   /u/<slug> SSR, /profile-api/v0/user + /users, directory list + pins,
 *   pins-public aggregate, me/location, /profile-media files (avatar,
 *   gallery, resume), and the hub search mask (archive-api search-suggest)
 * through three subject states:
 *   S1 public-finder OPT-IN  → named everywhere, gated sections enforced
 *   S2 MEMBERS-ONLY default  → off the anon stack, anonymous dot only,
 *                              radius math provably on coarsened coords
 *   S3 master-switch PRIVATE → owner+admin only, everywhere (flipped through
 *                              the real one-dial API so the fold is exercised)
 *
 * Fixture: profile user 1849 ('qa', bridged wp 1910, non-admin) becomes
 * 'Visibility Matrix QA' at a unique ocean coordinate (10.05, 10.05) so its
 * map cell (10.1, 10.1) is uniquely attributable. Idempotent — re-run any
 * time; ends parked in S2 (members-only) so the public finder stays clean.
 *
 * Run as ubuntu (needs sudo for token minting + fixture SQL):
 *   php /srv/profile-app/bin/visibility-matrix.php
 * GREEN RUN (exit 0) = the model holds. Any FAIL = a surface drifted.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

// Override for the LIVE acceptance run: LG_MATRIX_HOST=https://loothgroup.com
define('HOST', getenv('LG_MATRIX_HOST') ?: 'https://dev.loothgroup.com');
const SUBJ_ID  = 1849;            // profile-app user id   ('qa')
const SUBJ_WP  = 1910;            // bridged wp user id
const MEMBER_WP = 7;              // genuine non-admin member (read-only viewer)
const ADMIN_WP  = 1;              // Ian
const SLUG     = 'visibility-matrix-qa';
const NAME     = 'Visibility Matrix QA';
const LAT      = 10.05;           // unique, uninhabited cell -> (10.1, 10.1)
const LNG      = 10.05;

$UUID = '';                       // resolved in setup
$pass = 0; $fail = 0; $failures = [];

function sh(string $cmd): string { return trim((string)shell_exec($cmd . ' 2>/dev/null')); }
function pgq(string $sql): string { return sh('sudo -u profile-app psql profile_app -tAc ' . escapeshellarg($sql)); }
function lgq(string $sql): string { return sh('sudo -u postgres psql looth -tAc ' . escapeshellarg($sql)); }

function gate_token(): string {
    foreach (file('/etc/nginx/sites-available/dev.loothgroup.com.conf') as $l) {
        if (preg_match('/set \$loothdev_token "([^"]+)"/', $l, $m)) return $m[1];
    }
    fwrite(STDERR, "cannot read dev gate token\n"); exit(2);
}
function mint(int $wpId): string {
    $t = sh('sudo -u profile-app php /srv/profile-app/bin/mint-dev-token.php ' . $wpId . ' | tail -1');
    if ($t === '') { fwrite(STDERR, "mint failed for wp $wpId\n"); exit(2); }
    return $t;
}

$GATE   = gate_token();
$TOK    = [
    'anon'   => '',
    'member' => mint(MEMBER_WP),
    'owner'  => mint(SUBJ_WP),
    'admin'  => mint(ADMIN_WP),
];

/** [status, body] for $viewer hitting $path (method GET unless overridden). */
function req(string $viewer, string $path, string $method = 'GET', ?array $json = null): array {
    global $GATE, $TOK;
    $ch = curl_init(HOST . $path);
    $cookie = 'loothdev_auth=' . $GATE . ($TOK[$viewer] !== '' ? '; looth_id=' . $TOK[$viewer] : '');
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIE         => $cookie,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($json !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($json);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body];
}

function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else     { $fail++; $failures[] = $label . ($detail !== '' ? " — $detail" : ''); echo "  FAIL  $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

/** Does the directory LIST (big page) contain the subject for this viewer? */
function in_list(string $viewer): bool {
    [, $b] = req($viewer, '/profile-api/v0/directory/members?page_size=200&page=1&sort=joined_desc');
    $d = json_decode($b, true) ?: [];
    foreach (($d['items'] ?? []) as $it) if (($it['display_name'] ?? '') === NAME) return true;
    // fixture joins newest -> page 1 of joined_desc; belt-and-braces page 2
    [, $b] = req($viewer, '/profile-api/v0/directory/members?page_size=200&page=2&sort=joined_desc');
    $d = json_decode($b, true) ?: [];
    foreach (($d['items'] ?? []) as $it) if (($it['display_name'] ?? '') === NAME) return true;
    return false;
}
/** ['named' => bool, 'dot' => bool] — subject's named pin / a gated dot at its cell. */
function pin_state(string $viewer, string $extra = ''): array {
    [, $b] = req($viewer, '/profile-api/v0/directory/members?pins=1' . $extra);
    $d = json_decode($b, true) ?: [];
    $named = false; $dot = false;
    foreach (($d['pins'] ?? []) as $p) {
        if (!empty($p['gated'])) {
            if (abs((float)$p['lat'] - 10.1) < 0.001 && abs((float)$p['lng'] - 10.1) < 0.001) $dot = true;
        } elseif (($p['slug'] ?? '') === SLUG) {
            $named = true;
        }
    }
    return ['named' => $named, 'dot' => $dot];
}
function public_cell(): bool {
    [, $b] = req('anon', '/profile-api/v0/directory/pins-public');
    $d = json_decode($b, true) ?: [];
    foreach (($d['cells'] ?? []) as $c) {
        if (abs((float)$c[0] - 10.1) < 0.001 && abs((float)$c[1] - 10.1) < 0.001) return true;
    }
    return false;
}
function suggest_has(string $viewer): bool {
    [, $b] = req($viewer, '/archive-api/v0/search-suggest?q=' . rawurlencode('Visibility Matrix'));
    $d = json_decode($b, true) ?: [];
    foreach (($d['authors'] ?? []) as $a) if (($a['name'] ?? '') === NAME) return true;
    return false;
}
function person_backfill(): void {
    global $GATE;
    sh('sudo -u bb-mirror env LG_LOOTHDEV_GATE_TOKEN=' . escapeshellarg($GATE)
        . ' php /srv/bb-mirror/bin/backfill-profile-visibility.php ' . SUBJ_WP);
}

// ───────────────────────────── fixture setup (idempotent) ───────────────────
echo "== setup fixture (user " . SUBJ_ID . " -> '" . NAME . "' @ " . LAT . "," . LNG . ")\n";
$UUID = pgq("SELECT uuid FROM users WHERE id = " . SUBJ_ID);
if ($UUID === '') { fwrite(STDERR, "fixture user missing\n"); exit(2); }

pgq("UPDATE users SET slug = '" . SLUG . "', display_name = '" . NAME . "',
     location_text = 'Matrix Reef', location_city = 'Matrix Reef', location_region = 'Atlantic',
     location_country = 'XX', lat = " . LAT . ", lng = " . LNG . ",
     location_members_precision = 'city', location_public_precision = 'city',
     profile_visibility = 'public', resume_visibility = 'members',
     resume_url = '/profile-media/resumes/' || uuid || '/qa.pdf', resume_version = 1,
     avatar_url = '/profile-media/avatars/' || uuid || '/1.jpg', profile_layout = NULL
     WHERE id = " . SUBJ_ID);
pgq("INSERT INTO profiles (user_id) VALUES (" . SUBJ_ID . ") ON CONFLICT DO NOTHING");
pgq("INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
     VALUES (" . SUBJ_ID . ", 'header', 'public', '{}', 0)
     ON CONFLICT (user_id, key) DO UPDATE SET visibility = 'public'");
pgq("INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
     VALUES (" . SUBJ_ID . ", 'about', 'public', '{\"text\":\"Matrix fixture about text\"}', 1)
     ON CONFLICT (user_id, key) DO UPDATE SET visibility = 'public', data = '{\"text\":\"Matrix fixture about text\"}'");
pgq("INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
     VALUES (" . SUBJ_ID . ", 'gallery', 'members',
             '{\"title\":\"QA\",\"images\":[{\"url\":\"/profile-media/gallery/" . $UUID . "/qa.png\",\"caption\":\"\"}]}', 2)
     ON CONFLICT (user_id, key) DO UPDATE SET visibility = 'members',
             data = '{\"title\":\"QA\",\"images\":[{\"url\":\"/profile-media/gallery/" . $UUID . "/qa.png\",\"caption\":\"\"}]}'");

// media fixtures (1x1 png / tiny pdf / avatar)
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
foreach (['gallery' => 'qa.png', 'resumes' => 'qa.pdf', 'avatars' => '1.jpg'] as $class => $file) {
    sh('sudo mkdir -p /srv/profile-app-media/' . $class . '/' . $UUID);
    $tmp = '/tmp/vmqa-' . $file;
    file_put_contents($tmp, $class === 'resumes' ? "%PDF-1.4\n%matrix-qa\n%%EOF\n" : $png);
    sh('sudo cp ' . escapeshellarg($tmp) . ' /srv/profile-app-media/' . $class . '/' . $UUID . '/' . $file);
    sh('sudo chown -R profile-app:loothdevs /srv/profile-app-media/' . $class . '/' . $UUID);
    unlink($tmp);
}

// person rows: discovery.person is what search-suggest NAME-matches;
// forums.person carries the visibility flags it JOINs. Seed both (same id).
lgq("INSERT INTO discovery.person (id, display_name, slug, avatar_url)
     VALUES (" . SUBJ_WP . ", '" . NAME . "', '" . SLUG . "', NULL)
     ON CONFLICT (id) DO UPDATE SET display_name = '" . NAME . "', slug = '" . SLUG . "'");
lgq("INSERT INTO forums.person (id, display_name, slug, avatar_url)
     VALUES (" . SUBJ_WP . ", '" . NAME . "', '" . SLUG . "', NULL)
     ON CONFLICT (id) DO UPDATE SET display_name = '" . NAME . "', slug = '" . SLUG . "'");
lgq("UPDATE forums.person SET discussion_visibility = 'public' WHERE id = " . SUBJ_WP);
pgq("UPDATE users SET discussion_visibility = 'public' WHERE id = " . SUBJ_ID);
person_backfill();

// ───────────── S0: launch default layout (never-arranged profile) ───────────
echo "\n== S0 LAUNCH DEFAULT (profile_layout NULL -> Location only, Ian 6/12)\n";
[, $b] = req('member', '/u/' . SLUG);
check('S0 default layout: location renders',       strpos($b, 'lg-block--location') !== false);
check('S0 default layout: about NOT auto-placed',   strpos($b, 'Matrix fixture about text') === false);
check('S0 default layout: gallery NOT auto-placed', strpos($b, 'data-block="gallery"') === false);
check('S0 default layout: connect NOT auto-placed', strpos($b, 'lg-block--connect') === false);

// Starting-state defaults (Ian 6/12 pm: "member is the default"): a brand-new
// users row must begin members-only — public finder strictly opt-in.
check('S0 column default: Public-sees starts private',
      strpos(pgq("SELECT column_default FROM information_schema.columns
                  WHERE table_name='users' AND column_name='location_public_precision'"), 'private') !== false);

// The rest of the matrix asserts SECTION-VISIBILITY enforcement, which needs
// the blocks ON the layout — so the fixture becomes an "arranged" profile.
pgq("UPDATE users SET profile_layout = '[\"about\",\"location\",\"gallery\"]'::jsonb WHERE id = " . SUBJ_ID);

// ─────────────────────────── S1: public-finder opt-in ───────────────────────
echo "\n== S1 OPT-IN (chip public, Public-sees=city, gallery=members, resume=members, about=public)\n";

foreach (['anon', 'member', 'owner', 'admin'] as $v) {
    [$c, $b] = req($v, '/u/' . SLUG . ($v === 'owner' ? '?view=me' : ''));
    check("S1 page $v 200 + named", $c === 200 && strpos($b, NAME) !== false, "code=$c");
}
[, $b] = req('anon', '/u/' . SLUG);
check('S1 page anon: about visible',        strpos($b, 'Matrix fixture about text') !== false);
check('S1 page anon: location at the Public-sees dial (city)', strpos($b, 'Matrix Reef') !== false);
check('S1 page anon: gallery (members) hidden', strpos($b, 'data-block="gallery"') === false);
[, $b] = req('member', '/u/' . SLUG);
check('S1 page member: gallery visible',    strpos($b, 'data-block="gallery"') !== false);

[$c, $b] = req('anon', '/profile-api/v0/user/' . $UUID);
$d = json_decode($b, true) ?: [];
check('S1 api user anon 200', $c === 200, "code=$c");
[$c, $b] = req('member', '/profile-api/v0/user/' . $UUID);
check('S1 api user member 200', $c === 200, "code=$c");

check('S1 dir list anon: present',   in_list('anon'));
check('S1 dir list member: present', in_list('member'));
$p = pin_state('anon');
check('S1 pins anon: NAMED pin, no dot', $p['named'] && !$p['dot'], json_encode($p));
check('S1 pins-public: cell present', public_cell());

check('S1 file avatar anon 200',    req('anon',   '/profile-media/avatars/' . $UUID . '/1.jpg')[0] === 200);
check('S1 file gallery anon 404',   req('anon',   '/profile-media/gallery/' . $UUID . '/qa.png')[0] === 404);
check('S1 file gallery member 200', req('member', '/profile-media/gallery/' . $UUID . '/qa.png')[0] === 200);
check('S1 file gallery owner 200',  req('owner',  '/profile-media/gallery/' . $UUID . '/qa.png')[0] === 200);
check('S1 file resume anon 404',    req('anon',   '/profile-media/resumes/' . $UUID . '/qa.pdf')[0] === 404);
check('S1 file resume member 200',  req('member', '/profile-media/resumes/' . $UUID . '/qa.pdf')[0] === 200);

check('S1 me/location anon 401',  req('anon',  '/profile-api/v0/me/location')[0] === 401);

// Stale-token self-heal (Danny West bug, 6/12): WP session + INVALID looth_id
// must bounce to re-mint, not render the member as a stranger forever.
$wpck = trim((string)shell_exec('sudo -u www-data wp --path=/var/www/dev eval ' . escapeshellarg(
    '$e=time()+600; echo LOGGED_IN_COOKIE."=".urlencode(wp_generate_auth_cookie(' . SUBJ_WP . ',$e,"logged_in"));')));
if ($wpck !== '' && strpos($wpck, '=') !== false) {
    [$wn, $wv] = explode('=', $wpck, 2);
    $ch = curl_init(HOST . '/u/' . SLUG);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIE => 'loothdev_auth=' . $GATE . '; ' . $wn . '=' . rawurldecode($wv) . '; looth_id=STALE.GARBAGE.TOKEN']);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $loc  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    check('S1 stale looth_id + WP session bounces to re-mint',
          $code === 302 && strpos($loc, '/looth-auth/issue') !== false, "code=$code loc=$loc");
} else {
    check('S1 stale-token bounce (wp cookie mint failed)', false, 'could not mint wp cookie');
}
check('S1 me/location owner 200', req('owner', '/profile-api/v0/me/location')[0] === 200);

// Admin front-end edit (act-as surface, Ian 6/12): admins only, profile-content
// endpoints only, the editor page opens for any profile.
[$c, ] = req('admin', '/profile-api/v0/me/about?as=' . $UUID, 'PATCH', ['text' => 'Matrix fixture about text']);
check('S1 admin act-as PATCH about 200', $c === 200, "code=$c");
check('S1 member act-as PATCH 403', req('member', '/profile-api/v0/me/about?as=' . $UUID, 'PATCH', ['text' => 'x'])[0] === 403);
check('S1 anon act-as PATCH 401',   req('anon',   '/profile-api/v0/me/about?as=' . $UUID, 'PATCH', ['text' => 'x'])[0] === 401);
check('S1 admin act-as social endpoint 403 (not actable)', req('admin', '/profile-api/v0/me/messages?as=' . $UUID)[0] === 403);
[$c, $b] = req('admin', '/u/' . SLUG . '?admin_edit=1');
check('S1 admin front-end editor opens', $c === 200 && strpos($b, 'lg-shell--owner') !== false && strpos($b, 'Admin edit') !== false, "code=$c");

check('S1 hub search anon: found (discussion public)', suggest_has('anon'));
check('S1 hub search member: found', suggest_has('member'));

// ─────────────────────── S2: members-only (no public opt-in) ────────────────
echo "\n== S2 MEMBERS-ONLY (Public-sees=private — the imported-member default)\n";
pgq("UPDATE users SET location_public_precision = 'private' WHERE id = " . SUBJ_ID);

check('S2 dir list anon: ABSENT',    !in_list('anon'));
check('S2 dir list member: present', in_list('member'));
$p = pin_state('anon');
check('S2 pins anon: dot only (no name)', !$p['named'] && $p['dot'], json_encode($p));
$p = pin_state('member');
check('S2 pins member: named (members city)', $p['named'] && !$p['dot'], json_encode($p));
// pins-public aggregates the SAME population as the finder's anon dots
// (Ian 6/12 pm): a members-only member IS an anonymous cell.
check('S2 pins-public: cell present (anonymous aggregate)', public_cell());

// Trilateration guard: anon radius runs on the COARSENED point (10.1,10.1),
// ~4.9 mi from the true (10.05,10.05). A 2-mile probe centered on the TRUE
// coords must MISS; a 10-mile probe must HIT.
$p = pin_state('anon', '&lat=' . LAT . '&lng=' . LNG . '&radius=2');
check('S2 anon 2mi probe at TRUE coords: dot absent (coarse radius)', !$p['dot'], json_encode($p));
$p = pin_state('anon', '&lat=' . LAT . '&lng=' . LNG . '&radius=10');
check('S2 anon 10mi probe: dot present', $p['dot'], json_encode($p));

// page is still reachable by slug (members-only is a FINDER state, not the master switch)
check('S2 page anon 200 (header public)', req('anon', '/u/' . SLUG)[0] === 200);

// ───────────── S3: master switch PRIVATE — through the real one-dial API ────
echo "\n== S3 MASTER PRIVATE (owner flips the ONE DIAL via PATCH /me/header)\n";
[$c, ] = req('owner', '/profile-api/v0/me/header', 'PATCH', ['visibility' => 'private']);
check('S3 one-dial PATCH 200', $c === 200, "code=$c");
check('S3 fold: users.profile_visibility=private',
      pgq("SELECT profile_visibility FROM users WHERE id = " . SUBJ_ID) === 'private');
person_backfill();

check('S3 page anon 404',   req('anon',   '/u/' . SLUG)[0] === 404);
check('S3 page member 404', req('member', '/u/' . SLUG)[0] === 404);
check('S3 page owner 200',  req('owner',  '/u/' . SLUG . '?view=me')[0] === 200);
check('S3 page admin 200',  req('admin',  '/u/' . SLUG)[0] === 200);

check('S3 api user member 404', req('member', '/profile-api/v0/user/' . $UUID)[0] === 404);
check('S3 api user admin 200',  req('admin',  '/profile-api/v0/user/' . $UUID)[0] === 200);

check('S3 dir list member: ABSENT', !in_list('member'));
check('S3 dir list admin: present', in_list('admin'));
$p = pin_state('anon');
check('S3 pins anon: NO dot (gone entirely)', !$p['named'] && !$p['dot'], json_encode($p));
check('S3 pins-public: cell ABSENT (master private)', !public_cell());

check('S3 file gallery member 404', req('member', '/profile-media/gallery/' . $UUID . '/qa.png')[0] === 404);
check('S3 file gallery admin 200',  req('admin',  '/profile-media/gallery/' . $UUID . '/qa.png')[0] === 200);
check('S3 file resume member 404',  req('member', '/profile-media/resumes/' . $UUID . '/qa.pdf')[0] === 404);
check('S3 file avatar anon 200 (chrome)', req('anon', '/profile-media/avatars/' . $UUID . '/1.jpg')[0] === 200);

[, $b] = req('member', '/profile-api/v0/users?uuids=' . $UUID);
$d = json_decode($b, true) ?: [];
$it = ($d['items'] ?? [])[0] ?? [];
check('S3 users API member: slug stripped + flagged private',
      ($it['profile_visibility'] ?? '') === 'private'
      && array_key_exists('slug', $it) && $it['slug'] === null, json_encode($it));
check('S3 users API anon (external): 401', req('anon', '/profile-api/v0/users?uuids=' . $UUID)[0] === 401);

check('S3 hub search member: ABSENT', !suggest_has('member'));
check('S3 hub search anon: ABSENT',   !suggest_has('anon'));

// ───────────────────────────── restore + park in S2 ─────────────────────────
echo "\n== restore (one-dial back to public; park fixture members-only)\n";
[$c, ] = req('owner', '/profile-api/v0/me/header', 'PATCH', ['visibility' => 'public']);
check('restore one-dial PATCH 200', $c === 200, "code=$c");
check('restore fold: users.profile_visibility=public',
      pgq("SELECT profile_visibility FROM users WHERE id = " . SUBJ_ID) === 'public');
person_backfill();
check('restore page member 200', req('member', '/u/' . SLUG)[0] === 200);
check('restore dir list anon still ABSENT (parked members-only)', !in_list('anon'));

// ───────────────────────────────── summary ──────────────────────────────────
echo "\n==================== MATRIX " . ($fail === 0 ? 'GREEN' : 'RED') . " ====================\n";
echo "pass=$pass fail=$fail\n";
foreach ($failures as $f) echo "  FAILED: $f\n";
exit($fail === 0 ? 0 : 1);
