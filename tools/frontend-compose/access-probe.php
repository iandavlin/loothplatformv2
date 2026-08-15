<?php
/**
 * access-probe.php — who may compose, now that Ian has ruled ALL MEMBERS.
 *
 * Run: sudo -n wp --allow-root --path=/var/www/dev eval-file tools/frontend-compose/access-probe.php
 *
 * WAS tier-probe.php, which proved the GATED tier and its 'pending' fallback.
 * Ian ruled all-members on 2026-08-09 and that whole path is deleted, so the
 * assertions that covered it are deleted too rather than left passing against
 * code that no longer exists — a test that outlives its subject is worse than no
 * test, because it still reports GREEN.
 *
 * What is left to prove is smaller but not nothing, and none of it is covered by
 * gate 19's HTTP run at flag-OFF:
 *   - an ordinary member IS admitted (that is the ruling)
 *   - the FUNCTIONAL FLOOR still refuses accounts that could not post anyway
 *     (no upload_files => the required gallery cannot be filled)
 *   - user 0 is refused
 *   - an UNKNOWN type is refused — proven by registering a throwaway type
 *     through the registry filter, so no second type has to ship to test it
 *   - every admitted member PUBLISHES; there is no pending path left to
 *     accidentally hold a post back
 *
 * It registers NOTHING persistent: no posts, no options, no meta.
 */

if (!defined('ABSPATH')) { fwrite(STDERR, "must run under wp eval-file\n"); exit(2); }

$plugin = dirname(__DIR__, 2) . '/platform/mu-plugins/lg-frontend-compose.php';
if (!is_readable($plugin)) { echo "CANNOT RUN: $plugin unreadable\n"; exit(2); }
if (!function_exists('lg_fc_may_compose')) { require_once $plugin; }

// A throwaway registered type, injected through the registry's own filter for
// this process only, so "a registered type is admitted" and "an unknown type is
// refused" can both be asserted without shipping a second member-facing form.
add_filter('lg_fc_types', function (array $t): array {
    $t['probe_type'] = ['synth' => true, 'title' => 'Probe', 'sub' => '',
                        'submit' => '', 'foot' => '', 'fields' => [],
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
printf("accounts: privileged=%s(%d)  ordinary=%s(%d)  no-caps=%s(%d)\n\n",
    get_userdata($admin)->user_login, $admin,
    get_userdata($member)->user_login, $member,
    get_userdata($nocaps)->user_login, $nocaps);

echo "-- ALL MEMBERS (Ian, 2026-08-09) --\n";
ck('ordinary member MAY compose (the ruling)',    lg_fc_may_compose('loothprint', $member), true);
ck('so may an edit_others_posts holder',          lg_fc_may_compose('loothprint', $admin),  true);
ck('a registered throwaway type is admitted too', lg_fc_may_compose('probe_type', $member), true);
ck('lg_fc_on_allow_list is GONE, not dormant',    function_exists('lg_fc_on_allow_list'), false);

echo "\n-- the functional floor (NOT policy — see the plugin header) --\n";
ck('no upload_files => refused (gallery is REQUIRED)', lg_fc_may_compose('loothprint', $nocaps), false);
ck('signed-out (user 0) refused',                      lg_fc_may_compose('loothprint', 0), false);
ck('an UNREGISTERED type refused',                     lg_fc_may_compose('not_a_type', $admin), false);

echo "\n-- post_status: every admitted member publishes, no pending path --\n";
ck('ordinary member publishes', lg_fc_post_status('loothprint', $member), 'publish');
ck('so does everyone else',     lg_fc_post_status('probe_type', $nocaps), 'publish');

[$pass, $fail] = lg_fc_tally();
printf("\n%s  pass=%d fail=%d\n", $fail ? 'ACCESS PROBE RED' : 'ACCESS PROBE GREEN', $pass, $fail);
exit($fail ? 1 : 0);
