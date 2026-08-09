<?php
/**
 * tier-probe.php — exercise the GATED tier, which no request can currently reach.
 *
 * Run: sudo -n wp --allow-root --path=/var/www/dev eval-file tools/frontend-compose/tier-probe.php
 *
 * WHY THIS EXISTS. gate 19 proves the OPEN tier end to end over HTTP: an ordinary
 * member gets the loothprint form, someone without the capabilities does not, and
 * a refused POST writes no row. It cannot prove the GATED tier, because only one
 * type is registered and it is open. So lg_fc_on_allow_list() and the 'pending'
 * fallback in lg_fc_post_status() — the two pieces that stop ~1,850 accounts
 * publishing a video unreviewed (scope §6) — have never executed at all.
 *
 * Untested code that guards an escalation is the worst kind to leave untested,
 * and "no request can reach it yet" is exactly the reasoning that lets it ship
 * broken and only fail the day someone registers a gated type. This runs the
 * branch directly against a temporarily-registered gated type, in memory.
 *
 * It registers NOTHING persistent: the type is injected via the same filter a real
 * second type would use, for the duration of this process only. No posts, no
 * options, no meta.
 */

if (!defined('ABSPATH')) { fwrite(STDERR, "must run under wp eval-file\n"); exit(2); }

$plugin = dirname(__DIR__, 2) . '/platform/mu-plugins/lg-frontend-compose.php';
if (!is_readable($plugin)) { echo "CANNOT RUN: $plugin unreadable\n"; exit(2); }
if (!function_exists('lg_fc_may_compose')) { require_once $plugin; }

// A gated type, injected through the registry's own filter, for this process
// only. This is the same door the `event` slice will use, so the probe exercises
// the real mechanism rather than a stand-in for it. Nothing is persisted.
add_filter('lg_fc_types', function (array $t): array {
    $t['probe_gated'] = ['tier' => 'gated', 'synth' => true, 'title' => 'Probe',
                         'sub' => '', 'submit' => '', 'foot' => '', 'fields' => [],
                         'comments' => ['label' => '', 'acf_label' => '']];
    return $t;
});

/**
 * TALLY VIA A STATIC, NOT VIA global — and this is not style.
 *
 * `wp eval-file` runs the file in FUNCTION scope, so the $pass/$fail declared at
 * the top of this file are LOCALS, and `global $pass` inside a helper binds to a
 * different, empty variable. The first version of this probe did exactly that:
 * ck() incremented the globals, the inline checks incremented the locals, and the
 * verdict line read the locals — so it printed "pass=3" while ten checks ran, and,
 * far worse, ANY ck() FAILURE WOULD HAVE INCREMENTED A $fail THE VERDICT NEVER
 * READ. The probe could not go red. Recorded box trap, walked straight into.
 *
 * A function-static is scope-proof: one counter, reachable from everywhere that
 * matters, with no dependence on how the file is executed.
 */
function lg_fc_tally(?bool $ok = null): array {
    static $pass = 0, $fail = 0;
    if ($ok !== null) { $ok ? $pass++ : $fail++; }
    return [$pass, $fail];
}
function ck(string $what, $got, $want) {
    $ok = $got === $want;
    lg_fc_tally($ok);
    printf("  %-4s %-62s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $what,
        var_export($got, true), var_export($want, true));
}

// Find one real account per shape, so this is not a test of invented users.
$admin = null; $member = null; $nocaps = null;
foreach (get_users(['number' => 600, 'fields' => ['ID']]) as $u) {
    $id = (int) $u->ID;
    if (!$admin  && user_can($id, 'edit_others_posts')) { $admin = $id; continue; }
    if (!$member && user_can($id, 'edit_posts') && user_can($id, 'upload_files')
        && !user_can($id, 'edit_others_posts')) { $member = $id; continue; }
    if (!$nocaps && !user_can($id, 'edit_posts')) { $nocaps = $id; }
}
if (!$admin || !$member || !$nocaps) { echo "CANNOT RUN: could not find one account of each shape\n"; exit(2); }
printf("accounts: allow-listed=%s(%d)  ordinary=%s(%d)  no-caps=%s(%d)\n\n",
    get_userdata($admin)->user_login, $admin,
    get_userdata($member)->user_login, $member,
    get_userdata($nocaps)->user_login, $nocaps);

echo "-- the allow-list itself (never executed by any request today) --\n";
ck('edit_others_posts holder IS on the allow-list', lg_fc_on_allow_list(get_userdata($admin)), true);
ck('ordinary member is NOT on the allow-list',      lg_fc_on_allow_list(get_userdata($member)), false);
ck('no-caps account is NOT on the allow-list',      lg_fc_on_allow_list(get_userdata($nocaps)), false);

echo "\n-- the OPEN tier, for contrast (this one gate 19 also covers over HTTP) --\n";
ck('ordinary member MAY compose loothprint', lg_fc_may_compose('loothprint', $member), true);
ck('no-caps account may NOT',                lg_fc_may_compose('loothprint', $nocaps), false);
ck('signed-out (user 0) may NOT',            lg_fc_may_compose('loothprint', 0), false);
ck('an unregistered type is refused',        lg_fc_may_compose('not_a_type', $admin), false);

echo "\n-- post_status: the SAFETY VALVE under the refusal (scope §6) --\n";
ck('open tier publishes for an ordinary member', lg_fc_post_status('loothprint', $member), 'publish');

echo "\n-- the GATED branch, through lg_fc_post_status() ITSELF --\n";
// Not re-implemented here: the probe type is injected into lg_fc_types() via its
// own filter above, so the REAL function takes its real gated path. Asserting the
// allow-list result again instead would only re-test lg_fc_on_allow_list() and
// prove nothing about the branch that consumes it.
ck('gated tier: allow-listed author publishes',
   lg_fc_post_status('probe_gated', $admin),  'publish');
ck('gated tier: NON-allow-listed falls back to PENDING, not publish',
   lg_fc_post_status('probe_gated', $member), 'pending');
ck('gated tier: a no-caps account also lands PENDING',
   lg_fc_post_status('probe_gated', $nocaps), 'pending');
ck('gated tier: may_compose refuses the ordinary member',
   lg_fc_may_compose('probe_gated', $member), false);
ck('gated tier: may_compose ADMITS the allow-listed author',
   lg_fc_may_compose('probe_gated', $admin),  true);

echo "\n-- the constant that widens the list --\n";
printf("  LG_FRONTEND_COMPOSE_ALLOW = %s\n",
    defined('LG_FRONTEND_COMPOSE_ALLOW') ? var_export(LG_FRONTEND_COMPOSE_ALLOW, true) : '(undefined)');
echo "  (empty by default: the list is exactly the edit_others_posts holders until Ian names more)\n";

[$pass, $fail] = lg_fc_tally();
printf("\n%s  pass=%d fail=%d\n", $fail ? 'TIER PROBE RED' : 'TIER PROBE GREEN', $pass, $fail);
exit($fail ? 1 : 0);
