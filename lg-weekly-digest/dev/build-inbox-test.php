<?php
/**
 * build-inbox-test.php — build the ONE authorised test email for Ian.
 *
 *   cd /var/www/dev && sudo -u looth-dev wp eval-file <this file>
 *
 * Writes /tmp/lg-wdr/ian-mail.html + /tmp/lg-wdr/ian-mail.subject. SENDS NOTHING —
 * the send is a separate, deliberate step (direct SES; see the lane report). This
 * script does not call wp_mail and must not: dev2's mail containment would swallow
 * it into mailpit and return true, which reads as a successful send and is not one.
 *
 * HONESTY, BECAUSE THIS LANDS IN A REAL INBOX:
 *
 *   The GREETING is real. It comes from the live /profile-api/v0/internal/recap
 *   endpoint for wp:1 over the real nginx chain, so the name in the email is
 *   whatever profile_app.users.display_name actually holds for Ian right now.
 *
 *   The ROWS are real but BORROWED, and the email says so in a banner. Ian's own
 *   bell is currently 100% read (0 unread notifications, 0 unread DMs), so his
 *   truthful recap this week is the EMPTY case — no section at all, which would
 *   demonstrate nothing about the greeting he asked to see. Rather than mark his
 *   real notifications unread to manufacture a demo (a write to the live store,
 *   outside this window's four authorised paths, and a lie about his account), the
 *   rows are taken verbatim from other members' real stored activity, fetched
 *   through the same live endpoint, and the email is labelled.
 *
 * The substitution itself is the REAL path: one campaign body carrying
 * ##lg_recap.section##, parsed by FluentCRM's Parser::parse against Ian's actual
 * subscriber record — the same call CampaignEmail::getEmailBody() makes at send.
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "run me with wp eval-file\n" ); exit( 1 ); }

$LANE = '/home/ubuntu/worktrees/weekly-digest-recap';
$SRC  = '/tmp/lg-wdr/mail-src.json';
$OUT  = '/tmp/lg-wdr/out';
$IAN  = 1;

if ( ! defined( 'LG_WD_RECAP_SMARTCODE' ) ) { define( 'LG_WD_RECAP_SMARTCODE', '##lg_recap.section##' ); }
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap.php';
require_once $LANE . '/lg-weekly-digest/includes/class-lg-wd-recap-source.php';

$src = json_decode( (string) file_get_contents( $SRC ), true );
if ( empty( $src['ok'] ) ) { fwrite( STDERR, "bad endpoint payload at $SRC\n" ); exit( 1 ); }
$recaps = $src['recaps'];

// Ian's REAL identity from the live endpoint. Greeting comes from this and nothing else.
$mine = $recaps[ (string) $IAN ] ?? null;
if ( ! is_array( $mine ) ) { fwrite( STDERR, "no endpoint row for wp:$IAN\n" ); exit( 1 ); }
$real_rows = count( $mine['notifications'] ) + count( $mine['dms'] );

// Borrow real rows from other members so the section has something to draw.
$merged = [ 'display_name' => $mine['display_name'], 'notifications' => [], 'dms' => [] ];
foreach ( [ '690', '197', '1891' ] as $donor ) {
	foreach ( ( $recaps[ $donor ]['notifications'] ?? [] ) as $n ) { $merged['notifications'][] = $n; }
}
foreach ( ( $recaps['1138']['dms'] ?? [] ) as $d ) { $merged['dms'][] = $d; }

add_filter( 'lg_wd_recap_fetch', fn( $pre, $want ) => in_array( $IAN, $want, true ) ? [ $IAN => $merged ] : [], 10, 2 );
LG_WD_Recap_Source::register_smartcode();

// ── The campaign body, token and all ─────────────────────────────────────────
$issue_id = 72147;
$payload  = LG_WD_Query::build_payload_from_issue( LG_WD_Issue::get_data( $issue_id ) );
$campaign = LG_WD_Email_Builder::build( $payload );   // deployed plugin = main's builder

$pos = strpos( $campaign, 'border-bottom:2px solid #ECB351' );
$end = $pos !== false ? strpos( $campaign, '</p>', $pos ) : false;
if ( $end === false ) { fwrite( STDERR, "intro anchor not found\n" ); exit( 1 ); }
$end += 4;

$banner = '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation"'
	. ' style="background-color:#FFF4DA;border:1px solid #ECB351;border-radius:6px;margin:0 0 18px;"><tr>'
	. '<td style="padding:12px 16px;font-size:13px;color:#5C4E3A;line-height:1.55;">'
	. '<b>Test send &mdash; weekly-digest-recap lane.</b> The greeting below is real: the name came from your '
	. 'profile record over the live recap endpoint. The rows are <b>real stored activity borrowed from other '
	. 'members</b>, because your own bell is currently all-read (' . (int) $real_rows . ' unread items), so your '
	. 'truthful recap this week would be no section at all. Nothing about your account was changed to build this.'
	. '</td></tr></table>';

$campaign = substr( $campaign, 0, $end ) . "\n" . $banner . LG_WD_RECAP_SMARTCODE . substr( $campaign, $end );

// ── The REAL per-recipient substitution, against Ian's actual subscriber ──────
$sub = \FluentCrm\App\Models\Subscriber::where( 'user_id', $IAN )->first();
if ( ! $sub ) { $sub = \FluentCrm\App\Models\Subscriber::where( 'email', 'ian.davlin@gmail.com' )->first(); }
if ( ! $sub ) { fwrite( STDERR, "no FluentCRM subscriber for Ian\n" ); exit( 1 ); }

$html = \FluentCrm\App\Services\Libs\Parser\Parser::parse( $campaign, $sub );

// Outside a campaign, FluentCRM defers ##crm.unsubscribe_url## for later and nothing
// resolves it — so point it at the real preferences page rather than mail a live
// token or a dead literal.
$html = str_replace( '##crm.unsubscribe_url##', esc_url( home_url( '/manage-subscription/' ) ), $html );

$subject = '[dev2 test] ' . LG_WD_Email_Builder::build_subject( $payload );

file_put_contents( "$OUT/ian-mail.html", $html );
file_put_contents( "$OUT/ian-mail.subject", $subject );

// Report exactly what will be sent.
preg_match( '~Here&rsquo;s your week[^<]*~', $html, $g );
printf( "subscriber   : #%d %s\n", $sub->id, $sub->email );
printf( "display_name : %s   (from the live endpoint)\n", $mine['display_name'] );
printf( "greeting     : %s\n", $g ? html_entity_decode( $g[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : 'NONE — greeting missing!' );
printf( "rows drawn   : %d\n", substr_count( $html, 'border-radius:50%' ) );
printf( "token left   : %s\n", strpos( $html, LG_WD_RECAP_SMARTCODE ) !== false ? 'YES (BUG)' : 'no' );
printf( "unsub literal: %s\n", strpos( $html, '##crm.' ) !== false ? 'YES (BUG)' : 'no' );
printf( "subject      : %s\n", $subject );
printf( "bytes        : %d  ->  %s/ian-mail.html\n", strlen( $html ), $OUT );
