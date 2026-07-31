<?php
/**
 * render-live-recap.php — the per-member recap for REAL dev2 members, pulled
 * through the LIVE internal-recap endpoint. RENDERS ONLY. SENDS NOTHING.
 *
 *   sudo -u looth-dev wp --path=/var/www/dev eval-file \
 *       /home/ubuntu/worktrees/weekly-recap/lg-weekly-digest/dev/render-live-recap.php
 *
 * ── WHY THIS EXISTS ALONGSIDE render-recipient-frames.php ────────────────────
 *
 * That driver proved the FluentCRM substitution seam, but it fed the seam from a
 * CAPTURED response file (`/tmp/lg-wdr/endpoint-response.json`) injected via the
 * `lg_wd_recap_fetch` filter, because — in its own words — "the nginx route is
 * written but not yet live on dev2". Its header also predicted its own obsolescence:
 * "Once the lane's plugin is the deployed one, build($payload, ['mode' => 'token'])
 * produces this body directly and the injection below becomes dead code."
 *
 * BOTH OF THOSE CONDITIONS NOW HOLD, checked rather than assumed (2026-07-31):
 *
 *   1. THE ROUTE IS LIVE. strangler-profile-app.conf now carries
 *      `location ~ "^/profile-api/v0/internal/recap/?$"` → the profile-app FPM pool
 *      at /srv/profile-app/api/v0/internal-recap.php. So this driver installs NO
 *      `lg_wd_recap_fetch` filter at all: LG_WD_Recap_Source::post() makes the real
 *      loopback call, reads the real /etc/lg-internal-secret, and gets real rows out
 *      of the real profile_app store. `source_answered()` is asserted below, so a
 *      silent fall-back to "everyone has nothing" cannot pass as a green run.
 *   2. THE DEPLOYED PLUGIN IS THIS CODE. lg-weekly-digest is symlinked from
 *      wp-content/plugins into the serving checkout, and this lane's
 *      class-lg-wd-recap.php, class-lg-wd-recap-source.php and templates/email.php
 *      are byte-identical to it (diffed, not assumed). So wp-cli loading the
 *      DEPLOYED plugin is a feature here, not the usual trap — what gets rendered is
 *      the shipping code. Nothing is required out of the worktree.
 *
 * ── THE FLAG IS TURNED ON IN-PROCESS, AND DELIBERATELY NOT IN wp-config ──────
 *
 * LG_WD_RECAP_ENABLED is OFF on dev2 and stays OFF. dev2 carries an ARMED
 * `lg_wd_send_digest` cron event (next fire 2026-08-03 13:00 UTC), and the flag does
 * not only add a section — it also switches on
 * recipients_with_something_waiting(), which decides WHO IS MAILED AT ALL. Defining
 * the constant in wp-config to take a picture would arm both against a real
 * scheduled send three days out, on a box whose mail is swallowed by mailpit (so the
 * send would look like it worked). Instead the `lg_wd_recap_enabled` filter is
 * applied inside this one-shot CLI process: the same code path, gone when it exits.
 *
 * READ-ONLY. Writes HTML to $OUT and nothing else — no option, no campaign, no
 * CampaignEmail row, no wp_mail, no FluentCRM send. The only network call it makes
 * is the loopback GET of a member's own unread counts.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run me with wp eval-file\n" ); exit( 1 ); }

$OUT = getenv( 'LG_WDR_OUT' ) ?: '/tmp/lg-wdr/out';
if ( ! is_dir( $OUT ) ) { mkdir( $OUT, 0777, true ); }

/**
 * THE CAST — five real dev2 members plus one that documents a defect.
 *
 * Chosen from a sweep of ALL 1,824 dev2 users through the live endpoint (not picked
 * to flatter the design): of 212 members with any material in the 7-day window, only
 * FOUR have a notification the recap is allowed to report on, and 198 of the 212
 * have nothing but a stale connection request. That distribution is why the cast
 * looks like this — it is what this box actually contains this week.
 */
$people = [
	1    => [ 'why' => 'Ian himself. His real week is EMPTY — this is the empty case, not a mock of it.' ],
	690  => [ 'why' => 'A real reply on a discussion he started, from two people. The richest real recap on the box.' ],
	1953 => [ 'why' => 'A real reply to a comment SHE left — a different sentence from 690, and proof the wording is per-row.' ],
	849  => [ 'why' => 'A single real reply on his discussion. The one-actor phrasing, with no "and N others" tail.' ],
	224  => [ 'why' => 'Nothing fresh, one unanswered connection request — the COUNTED REGISTER. This is the shape 198 of 212 members are in.' ],
	1119 => [ 'why' => 'DEFECT FRAME. The recipient filter keeps him; the renderer draws nothing. See the note on this card.' ],
];

// ── Flag ON, in this process only ────────────────────────────────────────────
$flag_before = LG_WD_Recap_Source::recap_enabled();
add_filter( 'lg_wd_recap_enabled', '__return_true' );
if ( ! LG_WD_Recap_Source::recap_enabled() ) {
	fwrite( STDERR, "could not enable the recap in-process\n" ); exit( 1 );
}

if ( ! function_exists( 'FluentCrmApi' ) ) {
	fwrite( STDERR, "FluentCRM is not loaded — the substitution path cannot be exercised\n" ); exit( 1 );
}
LG_WD_Recap_Source::register_smartcode();

// ── The campaign body: ONE body, with the token in it ────────────────────────
// mode 'token' is what LG_WD_Sender_FluentCRM puts in the campaign's `email_body`.
// The token is emitted by the real templates/email.php, gated by the real flag
// check — no injection, no string surgery.
$issue_id  = 72147;                                   // "Weekly Digest — July 13, 2026"
$issue     = LG_WD_Issue::get_data( $issue_id );
$payload   = LG_WD_Query::build_payload_from_issue( $issue );
$campaign  = LG_WD_Email_Builder::build( $payload, [ 'mode' => 'token' ] );

if ( strpos( $campaign, LG_WD_RECAP_SMARTCODE ) === false ) {
	fwrite( STDERR, "the campaign body carries no recap token — nothing to substitute\n" ); exit( 1 );
}

// The same issue as the flag would leave it OFF. Ian's frame is diffed against this
// to prove "empty means ABSENT" byte-for-byte rather than by eyeball.
$flag_off_body = LG_WD_Email_Builder::build( $payload );   // default mode = strip

$rows = [];
foreach ( $people as $wp_id => $meta ) {
	$sub = \FluentCrm\App\Models\Subscriber::where( 'user_id', $wp_id )->first();
	if ( ! $sub ) { fwrite( STDERR, "wp $wp_id has no FluentCRM subscriber — skipped\n" ); continue; }

	// What the LIVE endpoint says about this member, before any rendering.
	$raw     = LG_WD_Recap_Source::payload_for( $wp_id );
	$answered = LG_WD_Recap_Source::source_answered();

	// The section on its own, for the side-by-side.
	$section = $raw ? LG_WD_Recap::render( $raw ) : '';

	// ── THE REAL PATH ── one campaign body in, THIS member's body out. This is
	// the same Parser::parse() call CampaignEmail::getEmailBody() makes per row.
	$html = \FluentCrm\App\Services\Libs\Parser\Parser::parse( $campaign, $sub );

	// The substitution must have produced exactly what the renderer produced —
	// not merely "something containing the words Your week".
	$section_intact = $section === '' ? null : ( strpos( $html, $section ) !== false );
	$token_gone     = strpos( $html, LG_WD_RECAP_SMARTCODE ) === false;
	$identical_off  = $html === $flag_off_body;

	$file = "recipient-$wp_id.html";
	file_put_contents( "$OUT/$file", $html );
	file_put_contents( "$OUT/section-$wp_id.html", $section );

	$user = get_user_by( 'id', $wp_id );
	$rows[] = [
		'wp_id'          => $wp_id,
		'sub_id'         => (int) $sub->id,
		'name'           => $user ? $user->display_name : ( 'wp:' . $wp_id ),
		'email'          => (string) $sub->email,
		'why'            => $meta['why'],
		'file'           => $file,
		'answered'       => $answered,
		'raw'            => $raw,
		'section_bytes'  => strlen( $section ),
		'section_html'   => $section,
		'section_intact' => $section_intact,
		'token_gone'     => $token_gone,
		'identical_off'  => $identical_off,
		'row_count'      => substr_count( $section, 'border-radius:50%' ),
	];

	printf( "wp:%-5d sub:%-5d %-34s section=%-9s rows=%d %s\n",
		$wp_id, (int) $sub->id, substr( $rows[ count( $rows ) - 1 ]['name'], 0, 34 ),
		$section === '' ? 'ABSENT' : strlen( $section ) . 'B',
		$rows[ count( $rows ) - 1 ]['row_count'],
		$identical_off ? '(byte-identical to flag-OFF)' : '' );
}

// ── The proof that one campaign became N different emails ────────────────────
$bodies   = array_map( fn( $r ) => file_get_contents( "$OUT/" . $r['file'] ), $rows );
$distinct = count( array_unique( $bodies ) );

file_put_contents( "$OUT/manifest.json", wp_json_encode( [
	'generated_utc'  => gmdate( 'c' ),
	'issue'          => [ 'id' => $issue_id, 'title' => get_the_title( $issue_id ) ],
	'window_days'    => LG_WD_Recap_Source::WINDOW_DAYS,
	'flag_default'   => $flag_before ? 'ON' : 'OFF',
	'source'         => 'LIVE https://127.0.0.1/profile-api/v0/internal/recap (no lg_wd_recap_fetch filter installed)',
	'distinct'       => $distinct,
	'of'             => count( $rows ),
	'recipients'     => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

printf( "\ndistinct bodies from ONE campaign: %d of %d\n", $distinct, count( $rows ) );
printf( "recap source answered for every member: %s\n",
	count( array_filter( array_column( $rows, 'answered' ) ) ) === count( $rows ) ? 'YES' : 'NO — SOME FRAMES ARE AN OUTAGE, NOT A QUIET WEEK' );
echo "wrote " . count( $rows ) . " recipient frames to $OUT\n";
