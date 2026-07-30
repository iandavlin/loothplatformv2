<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;
use Looth\ProfileApp\Whoami;

/**
 * Internal: resolve a member's CURRENT social links for a WP post/event byline.
 *
 * Why this exists (P0, member-reported 2026-07-01): the byline rail in
 * lg-layout-v2's post-header/post-footer read WP ACF `author_*` usermeta, while
 * the member edits their links in profile-app (`profile_socials`). The two stores
 * were seeded from each other once, on 2026-05-29, and have never synced since —
 * so a Linktree a member deleted kept rendering on his events for two months.
 * See docs/SOCIAL-LINKS-DRIFT-AUDIT.md.
 *
 * The fix is for the byline to READ the profile store rather than mirror it, and
 * this is that read. It deliberately lives here, not in a WP-side PDO query,
 * because profile-app owns the rule that turns a stored value into an href
 * (Profile::socialUrl). Duplicating that rule in WP would recreate exactly the
 * two-implementations-drift class this bug is.
 *
 * AUTHORITY RULE — per user, not per kind: if a member has ANY profile_socials
 * row, `authoritative` comes back true and that set is COMPLETE. A kind missing
 * from it must render nothing, which is what retires a link the member deleted.
 * Members with no rows at all come back authoritative=false so the caller can
 * keep its legacy ACF fallback instead of blanking a byline.
 *
 * Contact PII (email/phone) is never returned: a byline is a public page, and
 * these are the kinds Ian's 2026-06-11 "must be scrape proof" rule keeps off
 * bulk surfaces. Block-level visibility is deliberately NOT applied — see the
 * audit doc §"Visibility": 150 of 153 members have it unset (default 'members'),
 * so honouring it here would blank the rail for every logged-out reader. The
 * CALLER decides who is eligible; this endpoint answers truthfully.
 *
 * POST { "lookups": [ {"key":"717", "uuid":"...", "email":"..."}, ... ] }
 *   key   — opaque, echoed back so the caller can map results home
 *   uuid  — preferred (immutable; survives an email change)
 *   email — fallback when the caller has no uuid
 *
 * 200 { "results": { "<key>": { "authoritative": bool,
 *                               "links": [ {"kind","url"}, ... ] } } }
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}
if (!Whoami::verifyInternalAuth()) {
    profile_app_json(403, ['error' => 'forbidden']);
}

$in      = json_decode(file_get_contents('php://input') ?: '', true);
$lookups = (is_array($in) && is_array($in['lookups'] ?? null)) ? $in['lookups'] : null;
if ($lookups === null) profile_app_json(400, ['error' => 'lookups_required']);
if (count($lookups) > 200) profile_app_json(400, ['error' => 'too_many_lookups']);

/** Kinds that never belong on a public byline. */
const BYLINE_EXCLUDED_KINDS = ['email', 'phone'];

// ── Resolve each lookup to a profile-app user id ───────────────────────────
$byUuid = [];   // uuid  => [keys]
$byMail = [];   // email => [keys]
foreach ($lookups as $l) {
    if (!is_array($l)) continue;
    $key = (string) ($l['key'] ?? '');
    if ($key === '') continue;
    $uuid  = trim((string) ($l['uuid'] ?? ''));
    $email = strtolower(trim((string) ($l['email'] ?? '')));
    if ($uuid !== '' && preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
        $byUuid[strtolower($uuid)][] = $key;   // pg returns uuid lower-cased
    } elseif ($email !== '') {
        $byMail[$email][] = $key;
    }
}

$pg      = Db::pg();
$userKey = [];   // profile-app user id => [keys]

if ($byUuid) {
    $ph = implode(',', array_fill(0, count($byUuid), '?'));
    $st = $pg->prepare("SELECT id, uuid FROM users WHERE uuid::text IN ($ph)");
    $st->execute(array_keys($byUuid));
    while ($r = $st->fetch()) {
        foreach ($byUuid[strtolower((string) $r['uuid'])] ?? [] as $k) {
            $userKey[(int) $r['id']][] = $k;
        }
    }
}
if ($byMail) {
    $ph = implode(',', array_fill(0, count($byMail), '?'));
    $st = $pg->prepare("SELECT id, LOWER(primary_email) AS em FROM users WHERE LOWER(primary_email) IN ($ph)");
    $st->execute(array_keys($byMail));
    while ($r = $st->fetch()) {
        foreach ($byMail[(string) $r['em']] ?? [] as $k) {
            $userKey[(int) $r['id']][] = $k;
        }
    }
}

// ── Fetch links ───────────────────────────────────────────────────────────
$results = [];
foreach ($lookups as $l) {
    if (is_array($l) && ($k = (string) ($l['key'] ?? '')) !== '') {
        $results[$k] = ['authoritative' => false, 'links' => []];
    }
}

if ($userKey) {
    $ids = array_keys($userKey);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pg->prepare(
        "SELECT user_id, kind, value FROM profile_socials
         WHERE user_id IN ($ph) ORDER BY user_id, sort_order, id"
    );
    $st->execute($ids);
    while ($r = $st->fetch()) {
        $uid  = (int) $r['user_id'];
        $kind = (string) $r['kind'];
        // A row of ANY kind — including an excluded one — proves the member has
        // curated this store, so the set is authoritative even if every
        // renderable link is filtered out below. Without this, a member whose
        // only entry is an email address would fall back to stale ACF values.
        foreach ($userKey[$uid] ?? [] as $k) $results[$k]['authoritative'] = true;
        if (in_array($kind, BYLINE_EXCLUDED_KINDS, true)) continue;
        $url = Profile::socialUrl($kind, (string) $r['value']);
        if ($url === '') continue;
        foreach ($userKey[$uid] ?? [] as $k) {
            $results[$k]['links'][] = ['kind' => $kind, 'url' => $url];
        }
    }
}

profile_app_json(200, ['results' => (object) $results]);
