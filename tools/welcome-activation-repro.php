<?php
/**
 * REPRODUCTION — the welcome one-shot fires for one joining rail and not the other.
 *
 * Run:  sudo -n wp --allow-root --path=/var/www/dev eval-file tools/welcome-activation-repro.php
 *
 * WHY THIS EXISTS. Arbiter::sync computes $oldTier from the member's CURRENT
 * WORDPRESS ROLES and stamps the one-shot only on a transition INTO a paid tier
 * (isUpgradeToPaid). The two production account-creation paths do opposite things
 * with the role, so they land on opposite sides of that test:
 *
 *   lg-patreon-onboard.php:1615   wp_insert_user([... 'role' => $wp_role])
 *                                 → the paid role is already on the account before
 *                                   the arbiter ever runs, so oldTier === winning,
 *                                   isUpgradeToPaid() is false, NO WELCOME. EVER.
 *   UserLifecycle.php:231         wp_insert_user([...])  with NO role
 *                                 → the arbiter applies the tier itself, sees a
 *                                   real null → looth3 transition, WELCOME FIRES.
 *
 * So this is not a dormant feature: it is a live DUAL-RAIL VIOLATION. Two members
 * who paid the same money get a different product depending on the rail they came
 * in through, which Ian's standing ruling forbids.
 *
 * Measured on live 2026-08-15: 1,847 members; 1,225 hold a paid tier; 16 welcome
 * emails EVER, newest 21 June; 33 joined since 1 July and exactly ONE of them has
 * a pending stamp — consistent with the Stripe-shaped path working and the
 * Patreon-shaped one not.
 *
 * ⚠️ ON DEV2 THE `mailed` COLUMN IS ALWAYS "no" AND THAT IS NOT A FINDING.
 * pre_wp_mail is filtered on this box (dev-mail containment), so wp_mail returns
 * false and WelcomeMailer never stamps _lg_welcome_email_sent_at. Live has sent
 * 16, so the mailer itself works. Read the `welcome_stamp` column, not `mailed`.
 *
 * Probe accounts are keyed to the PID — a fixed test account makes any concurrent
 * writer produce a false result — and are deleted in a shutdown handler whatever
 * happens.
 */
/**
 * Reproduce the welcome asymmetry on dev2. Per-run accounts keyed to the PID
 * (a fixed test account makes any concurrent writer produce false results),
 * deleted at the end whatever happens.
 */
$pid = getmypid();
$made = [];
register_shutdown_function(function () use (&$made) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($made as $id) { wp_delete_user($id); }
    fwrite(STDERR, "cleanup: removed " . count($made) . " probe account(s)\n");
});

function probe(string $label, bool $roleAtCreation, array &$made, int $pid): array {
    $login = "lgprobe{$pid}" . substr(md5($label), 0, 6);
    $args  = ['user_login'=>$login, 'user_email'=>$login.'@example.invalid',
              'user_pass'=>wp_generate_password(24,true,true), 'display_name'=>$login];
    if ($roleAtCreation) $args['role'] = 'looth3';      // the PATREON shape
    $id = wp_insert_user($args);
    if (is_wp_error($id)) return ['label'=>$label,'error'=>$id->get_error_message()];
    $made[] = (int)$id;
    if (!$roleAtCreation) {
        // the STRIPE / email-first shape: account exists with no paid role, the
        // grant arrives afterwards and the arbiter is what applies it
        (new WP_User($id))->set_role('subscriber');
    }
    \LGMS\RoleSourceWriter::report((int)$id, 'patreon', 'looth3');
    $res = \LGMS\Arbiter::sync((int)$id);
    return [
        'label'   => $label,
        'old_tier'=> $res['old_tier'] ?? '(none)',
        'winning' => $res['winning_tier'] ?? '(none)',
        'pending' => (string) get_user_meta((int)$id, '_lg_pending_welcome', true),
        'mailed'  => (string) get_user_meta((int)$id, '_lg_welcome_email_sent_at', true),
    ];
}
$rows = [
    probe('PATREON shape  (role applied AT creation)', true,  $made, $pid),
    probe('STRIPE  shape  (role granted AFTER creation)', false, $made, $pid),
];
foreach ($rows as $r) {
    printf("%-42s old=%-8s win=%-8s welcome_stamp=%-8s mailed=%s\n",
        $r['label'], $r['old_tier'] ?? '?', $r['winning'] ?? '?',
        ($r['pending'] ?? '') === '' ? 'NONE' : $r['pending'],
        ($r['mailed'] ?? '') === '' ? 'no' : 'yes');
}
