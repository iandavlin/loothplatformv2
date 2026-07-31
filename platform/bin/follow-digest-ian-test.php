<?php
/**
 * follow-digest-ian-test.php — build (and, only when told, send) THE ONE authorised
 * follow-digest email to Ian.
 *
 *   Build only, print what would be sent, write the HTML:   php <this file>
 *   Actually send it:                                       php <this file> --send
 *
 * AUTHORISATION, and it is narrow on purpose: Ian authorised **ONE email to ONE
 * address**, 2026-07-31, relayed by keeper. Not "the send path enabled". Not a list of
 * one. So:
 *
 *   - THE RECIPIENT IS A CONSTANT, not an argument. There is no CLI override, no env
 *     override, and no code path here that can iterate a recipient set. keeper's
 *     instruction was explicit: if a path *can* iterate recipients, do not use it.
 *   - lg_fd_due_recipients() — the sender's real resolver — is NEVER CALLED by this
 *     file. That function can return many people; this errand is one person.
 *   - It BUILDS BY DEFAULT and sends only on an explicit --send.
 *
 * ── WHY NOT wp_mail, AND WHY THIS IS NOT A "TEST THAT PASSED" ────────────────
 * dev2's containment (lg-dev-mail-containment.php) swallows wp_mail into mailpit and
 * RETURNS TRUE. A green send test on this box is a convincing false positive and is
 * not evidence — the trap lg-weekly-digest/dev/build-inbox-test.php documents. This
 * file therefore bypasses wp_mail entirely and hands the message straight to the SES
 * API, reusing the exact client that has demonstrably delivered from this box.
 *
 * The proof of delivery is TWO things, not either: a real SES MessageId, AND mailpit
 * showing ZERO hits for the subject. A MessageId alone does not prove containment was
 * bypassed — it proves SES accepted something.
 *
 * ── THE CREDENTIALS TRAP, GUARDED RATHER THAN REDISCOVERED ───────────────────
 * FluentSMTP stores the SES secret ENCRYPTED: ~208 chars raw in the fluentmail-settings
 * option, 40 after fluentMailGetSettings() decrypts it. Signing with the raw value
 * fails SignatureDoesNotMatch — an error that reads like a credentials problem and
 * sends you looking in the wrong place. Cost a lane an hour. Guarded below.
 * Connection is selected BY PROVIDER, never "the default": this box also has a mailpit
 * SMTP connection, and following the default would deliver to a local sink while
 * reporting success. Both rules are lifted from platform/bin/keeper-notify.php, which
 * is the proven path.
 *
 * ── WHAT IS REAL IN THIS EMAIL, AND WHAT WAS SEEDED ──────────────────────────
 * The REPLIES are real: real posts by real members, on dev2, none of them written for
 * this demo. NOTHING IS BORROWED from another member's activity.
 *
 * What WAS seeded, at Ian's explicit request (2026-07-31, "fake my admin account some
 * activity please"): user 1's SUBSCRIPTIONS to four dev2 discussions, and his cadence.
 * The banner says so in the email itself, because an email that claimed nothing had
 * been touched would be false — and the whole point of this lane is that the UI must
 * not lie. An earlier draft of the banner did claim exactly that; it was caught before
 * sending, and it is the reason this section exists.
 *
 * ⚠️ DEV2 ONLY. Nothing was written to live — live is read-only from this box, and his
 * live bell state is untouched. The seeded rows and their teardown are recorded in the
 * lane report.
 *
 * The HTML is produced by lg_fd_render() — the REAL template function from
 * platform/mu-plugins/lg-follow-digest.php, not a lookalike. What Ian opens is what
 * the sender emits.
 */

declare(strict_types=1);

/** ⚠️ ONE ADDRESS. A constant, deliberately — see the header. */
const IAN = 'ian.davlin@gmail.com';

const OUT = '/tmp/lg-fd/ian-digest.html';

$send = in_array('--send', $_SERVER['argv'] ?? [], true);

/* ── THE CONTENT IS NOT TRANSCRIBED — IT IS THE REAL COLLECTOR'S OUTPUT ───────
 * Ian asked (2026-07-31, via keeper) for his dev2 admin account to be given some
 * activity, so the demo no longer needs borrowed rows or an apology for them. That
 * makes this a genuine end-to-end run rather than a rendering of numbers I typed in:
 *
 *   - user 1 was subscribed to four real dev2 discussions THROUGH follow.php, the
 *     real write path, over HTTP with real cookies and a real nonce — not by INSERT.
 *     Proving the sender against state the API cannot produce would prove nothing.
 *   - the cadence was written by lg_fd_set_cadence(), the real writer.
 *   - the rows below come from lg_fd_items_for(), the real collector, reading dev2's
 *     real posts.
 *   - lg_fd_render() produces the HTML.
 *
 * ⚠️ THE ACTIVITY IS SEEDED ON DEV2 AND IS NOT LIVE BEHAVIOUR. Nothing was written to
 * live; live is read-only from this box. The teardown is recorded in the lane report.
 *
 * ⚠️ STILL NOT CALLED: lg_fd_due_recipients(). It can return many people and this
 * errand is authorised for exactly one. The recipient is the constant above. */

// Bootstrap WP FIRST — we need the real posts, the real titles and the real permalinks,
// and later the SES client. Root, because the docroot is owned by looth-dev.
/* ⚠️ RUN AS looth-dev, NOT root. Postgres uses peer auth, so as root the role is
 * "root", which does not exist — lg_following_pg() returns null, every hub deep link
 * resolves to '' and the digest renders with NO clickable discussions while looking
 * completely normal. Measured, not theorised: that is exactly what the first rebuild
 * after the deep-link fix produced.
 *   sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php <this file> [--send]
 * looth-dev owns the docroot, so wp-load.php is readable to it. */
$wpLoad = '/var/www/dev/wp-load.php';
if (!is_readable($wpLoad)) { fwrite(STDERR, "cannot read $wpLoad (run as looth-dev)\n"); exit(65); }
$whoami = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?';
if ($whoami === 'root') {
	fwrite(STDERR, "refusing to run as root: Postgres peer auth would fail (role \"root\"),\n"
		. "every hub link would silently resolve to empty, and the digest would look normal.\n"
		. "Run: sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php " . __FILE__ . "\n");
	exit(73);
}
define('WP_USE_THEMES', false);
$pd = ini_get('display_errors'); $pl = ini_get('log_errors');
ini_set('display_errors', '0'); ini_set('log_errors', '0');
require_once $wpLoad;
ini_set('display_errors', (string) $pd); ini_set('log_errors', (string) $pl);

require_once dirname(__DIR__) . '/mu-plugins/lg-follow-digest.php';

if (!LG_FOLLOW_DIGEST_ENABLED) {
	fwrite(STDERR, "LG_FOLLOW_DIGEST is not 1 — the collector would refuse and this would\n"
		. "silently build an empty email. Re-run with LG_FOLLOW_DIGEST=1.\n");
	exit(70);
}

$ian = get_userdata(1);
if (!$ian || strcasecmp((string) $ian->user_email, IAN) !== 0) {
	fwrite(STDERR, sprintf("user 1 is %s, not %s — refusing to build a digest for the wrong account\n",
		$ian ? $ian->user_email : 'missing', IAN));
	exit(71);
}

// ── Build. The REAL template function, the REAL subject builder. ─────────────
$batch = lg_fd_items_for(1);
if (!$batch['items']) {
	fwrite(STDERR, "the real collector returned NOTHING — there is no digest to send.\n"
		. "That is the correct 'empty means send nothing' behaviour, not a bug; seed the\n"
		. "activity first. Refusing to send an empty email.\n");
	exit(72);
}
$watermark = lg_fd_watermark(1);
$subject = '[dev2] ' . lg_fd_subject($batch);
$html    = lg_fd_render($ian, $batch, 'daily');

// The same refusal the real sender makes, enforced here too — an email whose links
// could not be resolved must not go out, and this one cannot be edited afterwards.
if (function_exists('lg_fd_links_degraded') && lg_fd_links_degraded()) {
	fwrite(STDERR, "REFUSING: the link store was unreachable, so every discussion link\n"
		. "would be missing. Fix that before sending — do NOT send a linkless digest.\n");
	exit(74);
}
$hub = preg_match_all('~href="[^"]*?/hub/\?topic=~', $html);
$bad = preg_match_all('~href="[^"]*?/(forums|groups)/~', $html);
if ($bad) { fwrite(STDERR, "REFUSING: $bad link(s) point at the legacy forum Ian rejected.\n"); exit(75); }
if (!$hub) { fwrite(STDERR, "REFUSING: no hub deep links in the rendered digest.\n"); exit(76); }

/* The banner. It must be exactly true. An earlier draft said "nothing on your account
 * was changed to build this" — which was true when the rows were read from live, and
 * became FALSE the moment the subscriptions were seeded on dev2 at his request. Sending
 * that would have put a false statement in his inbox from the lane whose entire premise
 * is that the UI must not lie. Corrected here. */
$banner = '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation"'
	. ' style="background-color:#FFF4DA;border:1px solid #ECB351;border-radius:6px;margin:0 0 18px;"><tr>'
	. '<td style="padding:12px 16px;font:13px/1.55 Arial,Helvetica,sans-serif;color:#5C4E3A;">'
	. '<b>Test send &mdash; follow-digest lane, dev2.</b> The replies below are <b>real</b>: real '
	. 'posts by real members, none written for this demo. What was <b>seeded on dev2 at your '
	. 'request</b> is that your admin account now follows these four discussions &mdash; so this '
	. 'is dev2 behaviour, not live. <b>Your live account was not touched.</b> It covers everything '
	. 'since <b>' . esc_html($watermark) . '</b>, so it is a catch-up rather than one quiet day &mdash; '
	. 'which is the roll-forward rule working: a missed run loses nothing. Every word, count and '
	. 'link below was produced by the real sender, not a mock.'
	. '</td></tr></table>';

/* ⚠️ ANCHOR ON THE SUMMARY RULE, NOT ON THE ROWS TABLE. The first attempt matched
 * `<table width="100%" … role="presentation">` — but the OUTER page wrapper opens with
 * exactly that same string, so strpos found the wrapper and the banner rendered
 * OUTSIDE the white card, floating above the masthead on the page background. It read
 * as a broken email rather than a labelled one. Anchoring on the gold summary rule is
 * unambiguous (one occurrence) and puts the banner where it belongs: inside the card,
 * directly under the summary. Same technique build-inbox-test.php uses. */
$pos = strpos($html, 'border-bottom:2px solid #ECB351');
$end = $pos !== false ? strpos($html, '</p>', $pos) : false;
if ($end === false) { fwrite(STDERR, "could not find the summary rule to place the banner\n"); exit(1); }
$end += 4;
if (substr_count($html, 'border-bottom:2px solid #ECB351') !== 1) {
	fwrite(STDERR, "summary-rule anchor is not unique — refusing to guess where the banner goes\n"); exit(1);
}
$html = substr($html, 0, $end) . $banner . substr($html, $end);

/* ⚠️ CHECK THE WRITE. The first version used a bare file_put_contents() into a 0700
 * directory owned by another user (it had been created by an earlier root run), so the
 * write FAILED, the script reported the byte count anyway, and the verification step
 * then read a STALE file and reported zero hub links on a build that had eight. My own
 * tooling lied to me about my own fix. The send itself was never at risk — it uses the
 * in-memory $html — but a check that reads the wrong bytes is worse than no check. */
@mkdir(dirname(OUT), 0700, true);
$wrote = @file_put_contents(OUT, $html);
if ($wrote === false || $wrote !== strlen($html)) {
	fwrite(STDERR, sprintf("could not write %s (wrote %s of %d bytes) — refusing to continue, "
		. "because the next step would verify a stale file.\n", OUT, var_export($wrote, true), strlen($html)));
	exit(77);
}

$threads = count(array_unique(array_column($batch['items'], 'topic_id')));
$people  = count(array_unique(array_column($batch['items'], 'post_author')));
printf("recipient  : %s   (hard-coded constant; no override exists)\n", IAN);
printf("subject    : %s\n", $subject);
printf("content    : %d replies / %d discussions / %d members\n",
	count($batch['items']), $threads, $people);
printf("source     : lg_fd_items_for(1) — the REAL collector, real dev2 posts\n");
printf("watermark  : %s\n", $watermark);
printf("borrowed   : NONE — seeded on dev2 at Ian's request, stated in the banner\n");
printf("links      : %d hub deep link(s), 0 legacy-forum links — DESTINATION checked, not just presence\n", $hub);
printf("bytes      : %d -> %s\n", strlen($html), OUT);
printf("resolver   : lg_fd_due_recipients() NOT called — this errand cannot iterate recipients\n");

if (!$send) {
	printf("\nBUILD ONLY. Nothing was sent. Re-run with --send to deliver the one authorised message.\n");
	exit(0);
}

// ── Send. Direct SES, one address, bypassing wp_mail. WP is already booted. ──
if (!function_exists('fluentMailGetSettings') || !function_exists('fluentMailSesConnection')) {
	fwrite(STDERR, "FluentSMTP helpers unavailable\n"); exit(66);
}

// BY PROVIDER, never "the default" — the default could be mailpit, which would
// swallow this and report success.
$ses = null;
foreach ((fluentMailGetSettings()['connections'] ?? []) as $conn) {
	$p = $conn['provider_settings'] ?? [];
	if (($p['provider'] ?? '') === 'ses') { $ses = $p; break; }
}
if ($ses === null) { fwrite(STDERR, "no SES connection; refusing to fall back\n"); exit(67); }

$region = (string) ($ses['region'] ?? '');
$sender = (string) ($ses['sender_email'] ?? '');
$access = (string) ($ses['access_key'] ?? '');
$secret = (string) ($ses['secret_key'] ?? '');

if (strlen($secret) !== 40) {
	fwrite(STDERR, sprintf("SES secret is %d chars, expected 40. ~208 means STILL ENCRYPTED — "
		. "fluentMailGetSettings() decrypts it; signing with the raw value yields "
		. "SignatureDoesNotMatch.\n", strlen($secret)));
	exit(68);
}

$driver = fluentMailSesConnection([
	'sender_email' => $sender, 'access_key' => $access,
	'secret_key'   => $secret, 'region'     => $region,
]);

$cls = '\FluentMail\App\Services\Mailer\Providers\AmazonSes\SimpleEmailServiceMessage';
if (!class_exists($cls)) { fwrite(STDERR, "SES message class missing\n"); exit(67); }

$m = new $cls();
$m->setFrom($sender);
$m->addTo(IAN);                       // ← the only recipient, and it is a constant
$m->setSubject($subject);
$m->setMessageFromString(
	"Your follow digest. This message is HTML; your client is showing the plain-text part.",
	$html
);

// false/false: return the response object instead of funnelling the real error into
// the client's own handler and getting a bare `false` back — three different faults
// otherwise produce one useless message.
$res = $driver->sendEmail($m, false, false);

if (!is_array($res) || empty($res['MessageId'])) {
	fwrite(STDERR, "SEND NOT ACCEPTED: " . json_encode($res) . "\n");
	exit(69);
}
printf("\nACCEPTED BY SES\n  to        : %s\n  from      : %s (region %s)\n  MessageId : %s\n",
	IAN, $sender, $region, $res['MessageId']);
printf("  ⚠️ now verify mailpit shows ZERO hits for this subject — a MessageId alone\n");
printf("     does not prove containment was bypassed.\n");
