<?php
/**
 * verify-unsubscribe-nonmember.php — the unsubscribe link must WORK for a
 * non-member, i.e. for a contact with no WordPress account.
 *
 *   sudo -u looth-dev wp --path=/var/www/dev eval-file <this file>
 *
 * ── WHY "FLUENTCRM ALREADY HANDLES IT" WAS NOT AN ANSWER ────────────────────
 * templates/email.php has carried `##crm.unsubscribe_url##` since before this
 * branch and it demonstrably works — for members. Ian's backlog names unsubscribe
 * as a deliverable of the public signup page, and the new audience is a shape
 * nothing on this stack had before: on a list, subscribed, and with NO wp_users row
 * at all. If that link resolved via the WP user, every address the signup page
 * collects would get an email it cannot get off — the worst outcome for them and a
 * CAN-SPAM problem for us. That is worth ten minutes of proof.
 *
 * ── I GOT THE SEAM WRONG ON THE FIRST ATTEMPT, AND THE TEST SAID SO ─────────
 * My first version parsed the body with `Parser::parse($body, $subscriber)` and
 * every assertion failed — for members too, which is what showed the premise rather
 * than the feature was wrong. ShortcodeParser DEFERS the four url keys on purpose:
 *
 *     $urlKeys = ['unsubscribe_url','manage_subscription_url',
 *                 'unsubscribe_html','manage_subscription_html'];
 *     if (in_array($valueKey, $urlKeys)) { return $matches[0]; // replace later }
 *
 * The second pass is `parseCrmValue()`, which is what this now drives. (The branch
 * already knew this — dev/build-inbox-test.php:90 says the token is deferred — and
 * a test that contradicts a note already in the tree is the test's bug, not a
 * finding.)
 *
 * ── READ-ONLY, AND MEASURED RATHER THAN ASSUMED ─────────────────────────────
 * The URL embeds fluentCrmGetContactManagedHash($id), which CREATES a
 * `_secure_managed_hash` subscriber-meta row when one is missing. All 189
 * account-less list-7 contacts on dev2 already have one, so this reads. Rather than
 * reason about that, the test SNAPSHOTS the meta-row count and the fixture's own
 * hash value and asserts both are unchanged afterwards.
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "wp eval-file\n" ); exit( 1 ); }

$L = '/home/ubuntu/worktrees/weekly-digest-recap';

foreach ( [ '\FluentCrm\App\Models\Subscriber',
            '\FluentCrm\App\Services\Libs\Parser\ShortcodeParser' ] as $c ) {
	if ( ! class_exists( $c ) ) { fwrite( STDERR, "CANNOT RUN: $c missing\n" ); exit( 2 ); }
}

global $wpdb;
$fail = 0;
$ck = function ( string $what, $got, $exp ) use ( &$fail ) {
	$ok = $got === $exp;
	printf( "  %-58s %s\n", $what, $ok ? 'OK' : 'FAIL got=' . var_export( $got, true ) );
	if ( ! $ok ) { $fail++; }
};

$NM = (int) ( ( get_option( 'lg_wd_settings' ) ?: [] )['fcrm_nonmember_list_id'] ?? 7 );

/**
 * Real fixtures, selected BY THE PROPERTY UNDER TEST from real rows — the same
 * discipline verify-signup-audience needed, and for the same reason: a hand-made
 * contact agrees with whatever I assumed the shape was. Blank emails are excluded
 * because an unordered pick already produced one useless fixture on this branch.
 */
$pick = [];
foreach ( \FluentCrm\App\Models\Subscriber::whereHas( 'lists', function ( $q ) use ( $NM ) {
		$q->where( 'object_id', $NM );
	} )->where( 'status', 'subscribed' )->limit( 500 )->get() as $s ) {
	if ( empty( $s->email ) || (int) ( $s->user_id ?? 0 ) > 0 ) { continue; }
	if ( get_user_by( 'email', $s->email ) ) { continue; }      // a member on the wrong list
	$pick[] = $s;
	if ( count( $pick ) === 2 ) { break; }
}
if ( count( $pick ) < 2 ) {
	fwrite( STDERR, "CANNOT RUN: need two account-less subscribed contacts on list $NM\n" );
	exit( 2 );
}
[ $a, $b ] = $pick;
printf( "fixtures: subscribers #%d and #%d on list %d, neither has a wp_users row (DEV2)\n\n",
	$a->id, $b->id, $NM );

// ── Snapshot, so "read-only" is measured and not claimed ─────────────────────
$meta_tbl    = $wpdb->prefix . 'fc_subscriber_meta';
$rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $meta_tbl" );
$hash_before = (string) $wpdb->get_var( $wpdb->prepare(
	"SELECT value FROM $meta_tbl WHERE subscriber_id=%d AND `key`='_secure_managed_hash'", $a->id ) );

$parser = new \FluentCrm\App\Services\Libs\Parser\ShortcodeParser();
$body   = 'BEFORE <a href="##crm.unsubscribe_url##">Unsubscribe</a> AFTER';

echo "--- the deferred pass resolves for a contact with NO WordPress account ---\n";
$out = $parser->parseCrmValue( $body, $a );
$ck( 'no literal token survives', str_contains( $out, '##crm.unsubscribe_url##' ), false );
preg_match( '#href="([^"]*)"#', $out, $m );
$url = html_entity_decode( $m[1] ?? '' );
printf( "  resolved: %s\n", $url === '' ? '(EMPTY)' : $url );
$ck( 'absolute http(s) URL', (bool) preg_match( '#^https?://#', $url ), true );
$ck( 'routes to fluentcrm unsubscribe', (bool) preg_match( '#route=unsubscribe#', $url ), true );
$ck( 'carries a per-contact secure_hash', (bool) preg_match( '#secure_hash=[^&]{16,}#', $url ), true );
// The hash is <md5>__<contactId>: the identity in the link is the SUBSCRIBER, which
// is why having no WP user cannot break it.
$ck( 'the hash is scoped to this subscriber id',
	(bool) preg_match( '#secure_hash=[a-f0-9]{32}__' . (int) $a->id . '\b#', $url ), true );

echo "--- two contacts get two different links (not one shared opt-out) ---\n";
preg_match( '#href="([^"]*)"#', $parser->parseCrmValue( $body, $b ), $m2 );
$url_b = html_entity_decode( $m2[1] ?? '' );
$ck( 'distinct URLs', $url !== '' && $url_b !== '' && $url !== $url_b, true );

echo "--- a MEMBER still resolves too (no regression for the 1,663) ---\n";
$member = \FluentCrm\App\Models\Subscriber::where( 'user_id', '>', 0 )
	->where( 'status', 'subscribed' )->first();
if ( $member ) {
	$mo = $parser->parseCrmValue( $body, $member );
	$ck( 'member token resolves', str_contains( $mo, '##crm.unsubscribe_url##' ), false );
	preg_match( '#href="([^"]*)"#', $mo, $m3 );
	$ck( 'member link differs from the non-member link',
		html_entity_decode( $m3[1] ?? '' ) !== $url, true );
} else {
	echo "  (no subscribed member on this box to compare against)\n";
}

echo "--- the identity in the link owes nothing to WordPress ---\n";
// Structural, against the SHIPPED generator: if a future FluentCRM keyed this on a
// WP user, non-members would break silently and this is the line that would say so.
$src = (string) file_get_contents(
	WP_PLUGIN_DIR . '/fluent-crm/app/Services/Libs/Parser/ShortcodeParser.php' );
if ( preg_match( '#case "unsubscribe_url":(.*?)case "#s', $src, $g ) ) {
	$gen = $g[1];
	$ck( 'generator uses the subscriber id', str_contains( $gen, 'subscriber->id' ), true );
	$ck( 'generator names no WP user', (bool) preg_match( '#user_id|get_user_by|wp_get_current_user#', $gen ), false );
} else {
	printf( "  %-58s %s\n", 'CANNOT READ the shipped generator', 'DEAD' );
	$fail++;
}

echo "--- the shipped template is what was tested ---\n";
$tpl = (string) file_get_contents( $L . '/lg-weekly-digest/templates/email.php' );
$ck( 'templates/email.php emits this exact token', str_contains( $tpl, '##crm.unsubscribe_url##' ), true );
$ck( 'and renders it as an href', (bool) preg_match( '#<a href="<\?php echo \$unsubscribe#', $tpl ), true );

echo "--- NOTHING WAS WRITTEN ---\n";
$rows_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $meta_tbl" );
$hash_after = (string) $wpdb->get_var( $wpdb->prepare(
	"SELECT value FROM $meta_tbl WHERE subscriber_id=%d AND `key`='_secure_managed_hash'", $a->id ) );
$ck( 'subscriber-meta row count unchanged', $rows_after, $rows_before );
$ck( "fixture #{$a->id}'s managed hash unchanged", $hash_after, $hash_before );

echo "\n--- what this does NOT prove ---\n";
echo "  That CLICKING the link completes an opt-out — that is FluentCRM's own\n";
echo "  ExternalPages handler and needs a real request. What is proven is that a\n";
echo "  distinct, absolute, subscriber-scoped link is GENERATED, by the shipped\n";
echo "  code, for a contact that has no WordPress account.\n";

echo $fail ? "\n$fail FAILED\n" : "\nNONMEMBER UNSUBSCRIBE OK\n";
exit( $fail ? 1 : 0 );
