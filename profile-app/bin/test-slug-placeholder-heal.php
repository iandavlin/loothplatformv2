<?php
declare(strict_types=1);

/**
 * GATE — placeholder-slug self-heal (LG_SLUG_HEAL_PLACEHOLDER).
 *
 *   sudo -u profile-app php bin/test-slug-placeholder-heal.php            # OFF pass
 *   sudo -u profile-app php bin/test-slug-placeholder-heal.php --heal-on  # ON pass
 *
 * Both passes must be run; tools/gates/run-all.sh runs them as a pair. Exit 0 = green.
 *
 * WHY THE FLAG IS SET HERE AND NOT IN THE ENVIRONMENT
 * ---------------------------------------------------
 * `sudo` strips the environment, so a gate that switched behaviour on a getenv() would
 * silently exercise the OFF path while reporting that it had tested ON — a false GREEN on
 * the exact claim under test. The constant is therefore defined from argv BEFORE config.php
 * (which uses `if (!defined(...))`), and the run PRINTS the value it actually resolved.
 * A pass that cannot say which mode it ran in is not evidence.
 *
 * WHY THE OFF PASS ASSERTS LIVENESS TOO
 * -------------------------------------
 * "flag OFF ⇒ the slug does not change" is trivially true on a box with no database, no
 * fixture, or a typo'd user id. So the OFF pass first proves the fixture is real and the
 * heal machinery is reachable (the row exists, is bridged, carries a placeholder slug and a
 * usable name — i.e. every precondition the heal needs), and only then asserts that nothing
 * moved. An absence assertion without a liveness assertion is vacuous.
 *
 * WHAT IT DEFENDS
 * ---------------
 * The recurrence (Provision.php ensureSlug's `slug IS NULL OR slug = ''` guard treating a
 * `patreon_<id>` placeholder as a real handle) AND the four refusals that keep an unattended
 * heal from making a ruling: contested bare first name, duplicate display_name, already-held
 * handle, no honest derivation. Plus the latent clobber hole in maybeSyncSlugFromName.
 *
 * Fixtures are created and removed by this script. It writes ONLY to rows whose email
 * matches the reserved `slugheal-*@gate.invalid` pattern.
 */

$HEAL_ON = in_array('--heal-on', $argv, true);
if ($HEAL_ON) define('LG_SLUG_HEAL_PLACEHOLDER', true);

require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;
use Looth\ProfileApp\Identity;
use Looth\ProfileApp\Provision;
use Looth\ProfileApp\Slug;

// The run states which mode it truly resolved — never what it intended.
$MODE = LG_SLUG_HEAL_PLACEHOLDER ? 'ON' : 'OFF';
if ($HEAL_ON !== (bool) LG_SLUG_HEAL_PLACEHOLDER) {
    fwrite(STDERR, "[FAIL] asked for --heal-on but LG_SLUG_HEAL_PLACEHOLDER resolved OFF "
        . "(config.php defined it first?) — refusing to report a result\n");
    exit(1);
}
echo "=== placeholder-heal gate — LG_SLUG_HEAL_PLACEHOLDER=$MODE ===\n";

try { $pg = Db::pg(); } catch (Throwable $e) {
    fwrite(STDERR, "[FAIL] postgres unavailable — run as the profile-app role ({$e->getMessage()})\n");
    exit(1);
}

$ok = true;
$say = function (bool $pass, string $what, string $detail = '') use (&$ok): void {
    if (!$pass) $ok = false;
    printf("[%s] %s%s\n", $pass ? 'OK' : 'FAIL', $what, $detail === '' ? '' : "  — $detail");
};

// ── fixtures ────────────────────────────────────────────────────────────────
// Reserved domain so cleanup can never reach a real member.
const DOM  = '@gate.invalid';
const WPBASE = 2100000000;

$emailFor = fn(string $k): string => "slugheal-$k" . DOM;

$cleanup = function () use ($pg): void {
    $ids = $pg->query("SELECT id FROM users WHERE primary_email LIKE 'slugheal-%" . DOM . "'")
              ->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return;
    $in = implode(',', array_map('intval', $ids));
    foreach (['slug_history', 'profiles', 'wp_user_bridge', 'email_aliases'] as $t) {
        $pg->exec("DELETE FROM $t WHERE user_id IN ($in)");
    }
    $pg->exec("DELETE FROM users WHERE id IN ($in)");
};
$cleanup();
register_shutdown_function($cleanup);

/** Provision a fixture member, then force it into the pre-name placeholder state. */
$seed = function (string $key, int $wpOffset, ?string $name, ?string $slug) use ($pg, $emailFor): int {
    $email = $emailFor($key);
    $res   = Provision::ensure(WPBASE + $wpOffset, $email, null);
    $uid   = (int) $res['user_id'];
    // Force the exact starting state: the placeholder slug, and (for the subject) no name
    // yet — which is precisely how a Patreon connect lands before the name arrives.
    $pg->prepare('UPDATE users SET slug = :s, display_name = :n, slug_changed_at = NULL WHERE id = :i')
       ->execute([':s' => $slug, ':n' => $name, ':i' => $uid]);
    $pg->prepare('DELETE FROM slug_history WHERE user_id = :i')->execute([':i' => $uid]);
    return $uid;
};

$slugOf = fn(int $id): string => (string) $pg->query("SELECT COALESCE(slug,'') FROM users WHERE id = $id")->fetchColumn();

// ── the subject: a placeholder slug whose real name arrives later ───────────
$PLACEHOLDER = 'patreon_990000001';
$SUBJECT_NAME = 'Wilhelmina Ashgrove';           // unique, two tokens, no contest, free
$WANT = 'wilhelmina-ashgrove';
$subject = $seed('subject', 1, null, $PLACEHOLDER);

// LIVENESS — every precondition the heal needs is genuinely present.
$live = $pg->query("SELECT u.id, u.slug, (b.user_id IS NOT NULL) AS bridged
                    FROM users u LEFT JOIN wp_user_bridge b ON b.user_id = u.id
                    WHERE u.id = $subject")->fetch(PDO::FETCH_ASSOC);
$say((bool) $live, 'liveness: fixture row exists');
$say(($live['slug'] ?? '') === $PLACEHOLDER, 'liveness: subject starts on the placeholder', (string) ($live['slug'] ?? ''));
$say((bool) ($live['bridged'] ?? false), 'liveness: subject is bridged (a real member)');
$say(Slug::deriveUsable($SUBJECT_NAME) === $WANT, 'liveness: the name derives to a usable handle', $WANT);
$say(!$pg->query("SELECT 1 FROM users WHERE lower(slug)='$WANT'")->fetchColumn(), 'liveness: target handle is free');

// THE RECURRENCE: the name arrives on a re-provision, exactly as the poller does it.
Provision::ensure(WPBASE + 1, $emailFor('subject'), $SUBJECT_NAME);
$after = $slugOf($subject);

if ($MODE === 'OFF') {
    $say($after === $PLACEHOLDER, 'flag OFF: slug is untouched — byte-identical no-op', $after);
    $say(!$pg->query("SELECT 1 FROM slug_history WHERE user_id = $subject")->fetchColumn(),
         'flag OFF: nothing parked in slug_history');
} else {
    $say($after === $WANT, 'flag ON: placeholder healed to the human handle', $after);
    // The old URL must keep working — this is the contract's whole redirect guarantee.
    $parked = (string) $pg->query("SELECT COALESCE(slug,'') FROM slug_history WHERE user_id = $subject")->fetchColumn();
    $say(strcasecmp($parked, $PLACEHOLDER) === 0, 'flag ON: old placeholder parked in slug_history', $parked);
    $say(Slug::currentSlugForRetired($PLACEHOLDER) === $WANT,
         'flag ON: /u/' . $PLACEHOLDER . ' still resolves (301 target)', (string) Slug::currentSlugForRetired($PLACEHOLDER));
}

// ── the four refusals — only meaningful with the flag ON ────────────────────
if ($MODE === 'ON') {
    // R-1 contested bare first name (Ian 2026-07-29: /u/matt goes to NOBODY).
    $seed('rival-bare', 11, 'Zorbo Fenwick', 'zorbo-fenwick');
    $bare = $seed('bare', 12, null, 'patreon_990000012');
    Provision::ensure(WPBASE + 12, $emailFor('bare'), 'Zorbo');
    $say($slugOf($bare) === 'patreon_990000012',
         'refuses a CONTESTED BARE first name (another member is a Zorbo)', $slugOf($bare));

    // R-2 duplicate display_name — the merge question, not a slug question.
    $seed('rival-dup', 13, 'Quillon Marchetti', 'quillon-m-other');
    $dup = $seed('dup', 14, null, 'patreon_990000014');
    Provision::ensure(WPBASE + 14, $emailFor('dup'), 'Quillon Marchetti');
    $say($slugOf($dup) === 'patreon_990000014',
         'refuses a DUPLICATE display_name (may be one human, two accounts)', $slugOf($dup));

    // R-3 handle already held — R3 bans resolving this with a numeric suffix.
    $seed('holder', 15, 'Someone Entirely Else', 'draxton-vell');
    $taken = $seed('taken', 16, null, 'patreon_990000016');
    Provision::ensure(WPBASE + 16, $emailFor('taken'), 'Draxton Vell');
    $say($slugOf($taken) === 'patreon_990000016',
         'refuses an ALREADY-HELD handle rather than minting draxton-vell2', $slugOf($taken));

    // R-4 no honest derivation — never latinize (SLUG-CONTRACT §3).
    $cjk = $seed('cjk', 17, null, 'patreon_990000017');
    Provision::ensure(WPBASE + 17, $emailFor('cjk'), '祁磊');
    $say($slugOf($cjk) === 'patreon_990000017',
         'refuses a name with NO honest slug (never latinizes)', $slugOf($cjk));

    // A clean, already-human slug must never be re-derived by the heal.
    $human = $seed('human', 18, 'Bartholomew Quenneville', 'bartholomew-q');
    Provision::ensure(WPBASE + 18, $emailFor('human'), 'Bartholomew Quenneville');
    $say($slugOf($human) === 'bartholomew-q',
         'leaves an existing HUMAN slug alone (only placeholders heal)', $slugOf($human));
}

// ── the latent clobber hole (guarded regardless of the flag) ────────────────
// maybeSyncSlugFromName had no isPatreonJunk guard, so a placeholder-shaped display_name
// would overwrite a real handle AND park it in slug_history, where it is never re-issued.
$victim = $seed('victim', 20, 'Perpetua Lindqvist', 'perpetua-lindqvist');
Provision::maybeSyncSlugFromName($victim, 'Perpetua Lindqvist', 'patreon_188933584');
$say($slugOf($victim) === 'perpetua-lindqvist',
     'a patreon_<id> display_name CANNOT clobber a human slug', $slugOf($victim));
$say(!$pg->query("SELECT 1 FROM slug_history WHERE user_id = $victim")->fetchColumn(),
     'the clobber attempt parked nothing in slug_history');

echo $ok ? "\nGREEN — placeholder-heal gate passed ($MODE)\n" : "\nRED — placeholder-heal gate FAILED ($MODE)\n";
exit($ok ? 0 : 1);
