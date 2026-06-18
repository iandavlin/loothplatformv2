<?php
/**
 * WP-side applier for the `_looth_uuid` usermeta backfill. Not run directly —
 * invoked by bin/backfill-looth-uuid.sh, which first dumps the authoritative
 * Postgres snapshot (run as the profile-app role) to a TSV, then:
 *
 *     wp --path=/var/www/dev eval-file bin/backfill-looth-uuid.php <tsv> [--dry-run]
 *
 * TSV lines are "<wp_user_id>\t<uuid>" — uuid is profile-app users.uuid, the
 * immutable identity seed (NOT a recompute; an email-changed user's stored uuid
 * is the create-time value a recompute would miss). Writes go through
 * update_user_meta() so the object cache stays correct. Idempotent: a second run
 * writes nothing. Ends with the GATE — every bridged live-WP user must have
 * _looth_uuid == users.uuid — and exits non-zero if it fails.
 */

$tsv    = $args[0] ?? '';
$dryRun = in_array('dry-run', $args, true);   // dashless: WP-CLI eats --flags

if ($tsv === '' || ! is_readable($tsv)) {
    WP_CLI::error("usage: eval-file backfill-looth-uuid.php <tsv> [dry-run]  (unreadable: '{$tsv}')");
}

$pairs = [];
foreach (file($tsv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    [$idStr, $uuid] = array_pad(explode("\t", $line, 2), 2, '');
    $wpId = (int) $idStr;
    $uuid = trim($uuid);
    if ($wpId > 0 && $uuid !== '') {
        $pairs[] = [$wpId, $uuid];
    }
}

WP_CLI::log(sprintf('backfill-looth-uuid: %d bridged identities, mode=%s', count($pairs), $dryRun ? 'DRY-RUN' : 'WRITE'));

$c = ['written' => 0, 'fixed' => 0, 'ok' => 0, 'no_wp_user' => 0];
foreach ($pairs as [$wpId, $uuid]) {
    if (! get_userdata($wpId)) { $c['no_wp_user']++; continue; }
    $cur = (string) get_user_meta($wpId, '_looth_uuid', true);
    if ($cur === '') {
        if (! $dryRun) update_user_meta($wpId, '_looth_uuid', $uuid);
        $c['written']++;
    } elseif ($cur !== $uuid) {
        if (! $dryRun) update_user_meta($wpId, '_looth_uuid', $uuid);
        $c['fixed']++;
    } else {
        $c['ok']++;
    }
}
WP_CLI::log(sprintf(
    '  written=%d fixed=%d already-ok=%d skipped(no-wp-user)=%d',
    $c['written'], $c['fixed'], $c['ok'], $c['no_wp_user']
));

// ---- GATE ----------------------------------------------------------------
$pass = 0; $fail = 0; $missing = 0; $examples = [];
foreach ($pairs as [$wpId, $uuid]) {
    if (! get_userdata($wpId)) { continue; }   // no live WP account → out of scope
    $cur = (string) get_user_meta($wpId, '_looth_uuid', true);
    if ($cur === '') {
        $missing++;
        if (count($examples) < 5) $examples[] = "missing wp_user_id={$wpId}";
    } elseif ($cur === $uuid) {
        $pass++;
    } else {
        $fail++;
        if (count($examples) < 5) $examples[] = "mismatch wp_user_id={$wpId} meta={$cur} pg={$uuid}";
    }
}

$green = ($fail === 0 && ($dryRun || $missing === 0));
WP_CLI::log(sprintf('GATE: pass=%d mismatch=%d missing=%d -> %s', $pass, $fail, $missing, $green ? 'GREEN' : 'RED'));
foreach ($examples as $e) WP_CLI::log("    {$e}");

if (! $green) {
    WP_CLI::halt(1);
}
WP_CLI::success('every bridged WP user has _looth_uuid == profile-app users.uuid');
