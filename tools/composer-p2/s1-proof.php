<?php
declare(strict_types=1);
/**
 * OFFLINE PROOF — composer-v2 phase 2, step S1 (server prereqs):
 *   (a) mention render-source swap: anchor TEXT = member's CURRENT NAME
 *       (handles-invisible, Ian final 2026-07-26), href stays /u/<current-slug>
 *   (b) anon leak-scrub parity: mention anchors neutralize even with name text
 *   (c) mention-suggest second line: lg_profile_mention_context() derivation
 *
 * No DB writes, no serve flip, no engine. Identity reads go over the loopback
 * /profile-api/v0/users endpoint (unchanged by this branch — display_name was
 * already in its payload), exactly the path the render code uses in production.
 *
 *   php /home/ubuntu/worktrees/composer-v2-p2/tools/composer-p2/s1-proof.php
 */

define('LG_BB_MIRROR_HOST', getenv('LG_BB_MIRROR_PUBLIC_HOST') ?: 'dev2.loothgroup.com');

require_once dirname(__DIR__, 2) . '/bb-mirror/web/forums/_reply-render.php';
require_once dirname(__DIR__, 2) . '/bb-mirror/web/_anon-scrub.php';
require_once dirname(__DIR__, 2) . '/profile-app/api/v0/_mention-context.php';

$fails = 0;
function ok(bool $cond, string $what): void
{
    global $fails;
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $what . "\n";
    if (!$cond) $fails++;
}

// ── fixture identity: sanctioned test member wp690 (Markus), read live over loopback ──
$fetch = function (string $qs): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://127.0.0.1/profile-api/v0/users?' . $qs,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_HTTPHEADER     => ['Host: ' . LG_BB_MIRROR_HOST],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $d = json_decode((string) $body, true);
    return (array) ($d['items'] ?? []);
};
$who = $fetch('wp_ids=690')[0] ?? null;
if (!$who || empty($who['uuid']) || empty($who['slug'])) {
    echo "ABORT: loopback users endpoint did not resolve wp690 — cannot prove\n";
    exit(1);
}
$uuid = (string) $who['uuid'];
$slug = (string) $who['slug'];
$name = trim((string) ($who['display_name'] ?? ''));
ok($name !== '', "fixture member has a display_name ('$name')");

$db = new PDO('sqlite::memory:');   // resolve_mentions signature only; never queried

// ── (a) render-source swap ──────────────────────────────────────────────────
// OUR minted shape: uuid anchor, frozen @slug text
$in  = '<p>hey <a class="bp-suggestions-mention" data-lg-uuid="' . $uuid . '" href="{{mention_user_id_690}}">@frozen-old-handle</a> look</p>';
$out = bb_mirror_resolve_mentions($in, $db);
ok(str_contains($out, '>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>'), 'uuid anchor TEXT = current display name');
ok(!str_contains($out, '@' . $slug) && !str_contains($out, 'frozen-old-handle'), 'no handle text survives (frozen or current)');
ok(str_contains($out, 'href="/u/' . rawurlencode($slug) . '"'), 'href = /u/<current slug> (slugs live invisibly as URL keys)');
ok(str_contains($out, 'bp-suggestions-mention'), 'canonical class kept (snippet -> bb-mention -> anon-scrub chain intact)');

// LEGACY BuddyBoss shape: {{mention_user_id_N}} href only
$in2  = '<p><a href="{{mention_user_id_690}}">@ancient</a></p>';
$out2 = bb_mirror_resolve_mentions($in2, $db);
ok(str_contains($out2, '>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>'), 'legacy wp-id anchor TEXT = current display name');

// GONE member: dead uuid → plain text, humanized out of handle shape
$in3  = '<p><a class="bp-suggestions-mention" data-lg-uuid="00000000-0000-4000-8000-000000000000" href="#">@doug-lawrence</a></p>';
$out3 = bb_mirror_resolve_mentions($in3, $db);
ok(!str_contains($out3, '<a'), 'gone member: no dead link');
ok(str_contains($out3, 'Doug Lawrence') && !str_contains($out3, '@doug-lawrence'), 'gone member: frozen slug humanized ("Doug Lawrence"), no @handle');

// ordinary links untouched
$in4  = '<p><a href="https://example.com">a link</a></p>';
ok(bb_mirror_resolve_mentions($in4, $db) === $in4, 'ordinary anchors byte-identical');

// ── (b) anon scrub parity with name-text mentions ───────────────────────────
$body = '<p>ask <a class="bp-suggestions-mention" href="/u/' . $slug . '" rel="nofollow">' . htmlspecialchars($name) . '</a> about it</p>';
$scr  = lg_scrub_anon_contacts($body);
ok(!str_contains($scr, $name) && str_contains($scr, '@member'), 'anon scrub: mention NAME neutralized to @member (no-@ body caught by the mention fast-path)');
ok(str_contains($scr, '/u/' . $slug), 'anon scrub: anchor kept (text-only neutralization, old parity)');
$scr2 = lg_scrub_anon_contacts('<p>mail me a@b.co or ping @somehandle</p>');
ok(!str_contains($scr2, 'a@b.co') && !str_contains($scr2, '@somehandle'), 'anon scrub: email + typed @handle rules still fire');
$plain = '<p>no identities here</p>';
ok(lg_scrub_anon_contacts($plain) === $plain, 'anon scrub: clean body byte-identical (fast-path)');
ok(lg_scrub_anon_contacts($scr) === $scr, 'anon scrub: idempotent on scrubbed output');

// ── (c) suggest second line ─────────────────────────────────────────────────
$C = 'lg_profile_mention_context';
ok($C(['business_name' => 'Sage Guitar Works', 'location_city' => 'Austin']) === 'Sage Guitar Works', 'context: business name wins');
ok($C(['location_city' => 'Austin', 'location_region' => 'TX']) === 'Austin, TX', 'context: city precision = "City, Region"');
ok($C(['location_members_precision' => 'state', 'location_city' => 'Austin', 'location_region' => 'TX']) === 'TX', 'context: state precision drops the city');
ok($C(['location_members_precision' => 'state', 'location_country' => 'USA']) === 'USA', 'context: state precision falls back to country');
ok($C(['location_members_precision' => 'street', 'location_city' => 'Austin', 'location_region' => 'TX', 'location_address' => '1 Main St']) === 'Austin, TX', 'context: street precision still surfaces only city (no address in a picker row)');
ok($C(['location_members_precision' => 'private', 'location_city' => 'Austin']) === null, 'context: members-precision private = no line');
ok($C(['location_visibility' => 'private', 'location_city' => 'Austin']) === null, 'context: location_visibility private = no line');
ok($C([]) === null, 'context: nothing set = no line');
ok($C(['business_name' => '  ', 'location_city' => 'Austin', 'location_region' => '']) === 'Austin', 'context: blank business falls through; lone city has no dangling comma');

echo $fails === 0 ? "ALL GREEN\n" : "$fails FAILURES\n";
exit($fails === 0 ? 0 : 1);
