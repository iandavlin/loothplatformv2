<?php
/**
 * verify-signup-audience.php — prove Ian's ruling 6 against the REAL FluentCRM.
 *
 *   sudo -u looth-dev wp --path=/var/www/dev eval-file \
 *     lg-weekly-digest/dev/verify-signup-audience.php
 *
 * ── WHAT THIS IS ACTUALLY TESTING, AND WHY IT USES REAL ROWS ────────────────
 *
 * The rule is about WHO a given address is, so a fixture proves nothing: the
 * whole failure mode is misclassifying a real member as a stranger. Every
 * address below is therefore a REAL dev2 row, selected by SQL for the property
 * under test, not invented.
 *
 * READ-ONLY. It calls lg_weekly_signup_audience(), which writes nothing, and it
 * NEVER calls the handler — the handler sends JSON and exits, and its 'new'
 * branch really would create a contact. The classifier is the whole decision;
 * the branch bodies are then asserted by reading the source, below.
 *
 * BOX: dev2. list 3 = "Weekly News Letter" (1,723 here), list 7 = "Non Member
 * Weekly Email Subscriber" (294 here). Live holds different numbers — say which
 * box any figure came from.
 */
if (!defined('ABSPATH')) { fwrite(STDERR, "wp eval-file\n"); exit(1); }

// LG_SIGNUP_MU lets the RED-PROOF point this at a deliberately-broken copy. A
// guard that has only ever been seen green is not known to be a guard at all —
// see the red-proof block at the foot of this file for how to fire it.
$MU = getenv('LG_SIGNUP_MU')
    ?: '/home/ubuntu/worktrees/weekly-digest-recap/platform/mu-plugins/lg-event-reminders.php';

/**
 * LOADING MY BYTES WITHOUT COLLIDING WITH THE DEPLOYED COPY.
 *
 * The mu-plugin is symlinked into the docroot, so WP has ALREADY loaded the
 * serving checkout's version — which predates this branch and does not contain
 * lg_weekly_signup_audience(). A plain require_once of the branch file therefore
 * fatals on "Cannot redeclare lg_evr_user()".
 *
 * So: extract JUST the function under test from the branch source, by brace
 * matching, and eval that. It is the real source text of the thing being tested
 * — not a reimplementation, which would only prove the test agrees with itself.
 * If the extraction fails the run ABORTS rather than silently testing nothing.
 */
if (!function_exists('lg_weekly_signup_audience')) {
    $src   = (string) file_get_contents($MU);
    $start = strpos($src, 'function lg_weekly_signup_audience');
    if ($start === false) { fwrite(STDERR, "cannot find lg_weekly_signup_audience in $MU\n"); exit(2); }
    $open = strpos($src, '{', $start);
    $depth = 0; $end = null;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    if ($end === null) { fwrite(STDERR, "unbalanced braces extracting the function\n"); exit(2); }
    if (!defined('LG_WEEKLY_NONMEMBER_LIST_ID')) define('LG_WEEKLY_NONMEMBER_LIST_ID', 7);
    if (!defined('LG_WEEKLY_MEMBER_LIST_ID'))    define('LG_WEEKLY_MEMBER_LIST_ID', 3);
    eval(substr($src, $start, $end - $start + 1));
    echo "loaded lg_weekly_signup_audience() from BRANCH bytes (" . ($end - $start + 1) . " chars)\n\n";
}
if (!function_exists('FluentCrmApi')) { fwrite(STDERR, "FluentCRM not loaded\n"); exit(2); }

global $wpdb;
$api  = FluentCrmApi('contacts');
$fail = 0;
$pick = function (string $sql) use ($wpdb) { return $wpdb->get_var($sql); };

/** Pick one REAL address per case, straight from the store. */
$cases = [];

// 1. A member on list 3.
$cases['member_on_list'] = $pick("
    SELECT s.email FROM {$wpdb->prefix}fc_subscribers s
      JOIN {$wpdb->prefix}fc_subscriber_pivot p
        ON p.subscriber_id=s.id AND p.object_id=3 AND p.object_type LIKE '%Lists%'
      JOIN {$wpdb->users} u ON u.user_email=s.email
     LIMIT 1");

// 2. A WP user who is NOT on list 3 — the case Ian's spec does not name.
//    `u.user_email <> ''` is NOT decoration: 31 dev2 wp_users rows carry a BLANK
//    email, an unordered LIMIT 1 picked one, and the fixture came back NULL — which
//    the harness reported as "NO FIXTURE FOUND", i.e. as an untestable case rather
//    than as a bad query. A fixture selector needs the same scepticism as the code.
$cases['member_off_list'] = $pick("
    SELECT u.user_email FROM {$wpdb->users} u
      LEFT JOIN {$wpdb->prefix}fc_subscribers s ON s.email=u.user_email
      LEFT JOIN {$wpdb->prefix}fc_subscriber_pivot p
        ON p.subscriber_id=s.id AND p.object_id=3 AND p.object_type LIKE '%Lists%'
     WHERE p.subscriber_id IS NULL AND u.user_email <> ''
     ORDER BY u.ID
     LIMIT 1");

// 3. On list 7 and holding NO WP account — a true non-member already signed up.
$cases['already_nonmember'] = $pick("
    SELECT s.email FROM {$wpdb->prefix}fc_subscribers s
      JOIN {$wpdb->prefix}fc_subscriber_pivot p
        ON p.subscriber_id=s.id AND p.object_id=7 AND p.object_type LIKE '%Lists%'
      LEFT JOIN {$wpdb->users} u ON u.user_email=s.email
     WHERE u.ID IS NULL
     LIMIT 1");

// 4. Nobody at all. Not random: a fixed address that must not exist, asserted.
$cases['new'] = 'lg-signup-verify-nobody@example.invalid';

echo "=== classifier: every case is a REAL dev2 row (except 'new') ===\n";
foreach ($cases as $expect => $email) {
    if (!$email) { printf("  %-20s NO FIXTURE FOUND — cannot test\n", $expect); $fail++; continue; }

    if ($expect === 'new' && $api->getContact($email)) {
        printf("  %-20s FIXTURE IS NOT ABSENT (%s exists) — test invalid\n", $expect, $email);
        $fail++; continue;
    }

    $got = lg_weekly_signup_audience($email, $api->getContact($email))['kind'];
    $ok  = ($got === $expect);
    printf("  %-20s -> %-20s %s   <%s>\n", $expect, $got, $ok ? 'OK' : 'FAIL', $email);
    if (!$ok) $fail++;
}

echo "\n=== the classifier must not have written anything ===\n";
foreach ([3, 7] as $listId) {
    $n = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriber_pivot
          WHERE object_id=%d AND object_type LIKE '%%Lists%%'", $listId));
    printf("  list %d membership after the run: %d\n", $listId, $n);
}
echo "  (compare with the before-figures in the commit message; equal = no write)\n";

/**
 * THE STRUCTURAL ASSERTION, and it is the one that actually enforces Ian's
 * "never duplicated onto the MEMBER list": no amount of branch testing can prove
 * a write does not exist somewhere in the file, so read the source and check
 * that the member list id is never handed to a WRITE call.
 */
echo "\n=== structural: list 3 is READ but never WRITTEN in the signup handler ===\n";
/**
 * SCOPE THE SOURCE TO THE HANDLER, BY BRACE MATCHING.
 *
 * The first version used strstr(), which returns everything from the handler to
 * END OF FILE — and the file continues with the members' Weekly Digest toggle,
 * which legitimately DOES write list 3. So the assertion reported 4 write calls
 * and a member-list write, both of them true of the file and neither of them
 * true of the handler. It was failing on code it was never meant to read.
 */
$src   = (string) file_get_contents($MU);
$hStart = strpos($src, 'function lg_weekly_signup_handler');
if ($hStart === false) { fwrite(STDERR, "cannot find the handler\n"); exit(2); }
$hOpen = strpos($src, '{', $hStart);
$d = 0; $hEnd = null;
for ($i = $hOpen, $n = strlen($src); $i < $n; $i++) {
    if ($src[$i] === '{') $d++;
    elseif ($src[$i] === '}') { $d--; if ($d === 0) { $hEnd = $i; break; } }
}
if ($hEnd === null) { fwrite(STDERR, "unbalanced braces scoping the handler\n"); exit(2); }
$fn = substr($src, $hStart, $hEnd - $hStart + 1);
printf("  scoped to the handler body only: %d chars (not to EOF)\n", strlen($fn));

/**
 * STRIP COMMENTS BEFORE SCANNING, VIA THE TOKENIZER.
 *
 * The first version regexed the raw text and counted TWO write calls where the
 * code has one — because the handler's own comment says the words
 * "attachLists([7])" and "attachLists/detachLists" while EXPLAINING that they are
 * not called. A source assertion that reads prose will be defeated by any honest
 * comment about the thing it forbids, and it fails in the LOUD direction here only
 * by luck. token_get_all is the right instrument; a regex over source never was.
 */
$stripped = '';
foreach (token_get_all("<?php " . $fn) as $t) {
    if (is_array($t)) {
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
        $stripped .= $t[1];
    } else {
        $stripped .= $t;
    }
}
printf("  comments stripped: %d chars of code remain\n", strlen($stripped));
$fn = $stripped;
foreach (['attachLists', 'detachLists', 'createOrUpdate'] as $writer) {
    // Any write call inside the handler that names the member list or a bare 3.
    $bad = preg_match('/' . $writer . '\s*\(\s*\[?\s*(LG_WEEKLY_MEMBER_LIST_ID|3)\s*[\],)]/', $fn);
    printf("  %-16s naming the member list: %s\n", $writer, $bad ? 'FOUND — FAIL' : 'none  OK');
    if ($bad) $fail++;
}
$writesInHandler = preg_match_all('/(attachLists|detachLists|createOrUpdate)\s*\(/', $fn);
printf("  total write calls in the handler: %d (expect 1 — the 'new' branch only)\n", $writesInHandler);
if ($writesInHandler !== 1) $fail++;

// The suite requires each test to print its OWN sentinel — exit 0 alone is not
// enough, because wp-cli exits 0 in cases where the file never ran at all.
echo "\n" . ($fail === 0 ? "SIGNUP AUDIENCE HOLDS\n" : "$fail FAILURE(S)\n");
exit($fail === 0 ? 0 : 1);
