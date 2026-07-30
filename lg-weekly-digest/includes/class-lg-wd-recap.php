<?php
defined( 'ABSPATH' ) || exit;

/**
 * LG_WD_Recap — the per-member "Your week" section of the weekly digest.
 *
 * IAN'S RULING (2026-07-25): the email channel is THIS ONE SECTION. No daily mail,
 * no per-event mail, ever — real-time is the bell only. And inside the section:
 * COUNTS AND SENDERS WITH DEEP LINKS, NEVER CONTENT. "3 replies on your thread"
 * and who; not the reply text. That is a privacy ruling, not a style one, so it is
 * enforced HERE, in the renderer, rather than left to the caller: this class is
 * never handed a body/snippet field and has nowhere to put one.
 *
 * WHERE THE MATERIAL COMES FROM. The recap is built from the BELL — the
 * `profile_app.notifications` table — plus unread DMs. That is a deliberate choice
 * and it only became possible on 2026-07-12: before the notifications lane shipped
 * notify-bridge.php, the store knew about connection request/accept and NOTHING
 * else (see NOTIFICATIONS-AUDIT.md §1, still written that way). It now also carries
 * forum.reply_to_topic / forum.reply_to_reply / forum.mention / reaction.on_post,
 * each with an actor, a coalesced actor_count, and — the part that matters most
 * here — a stamped CURRENT-system deep link in `target_url`. We do not rebuild
 * those links; we reuse the ones the bridge already computed.
 *
 * DMs are the exception that proves it: profile-app deliberately does NOT ring the
 * bell for a new message ("no double-notify"), so unread DMs are read separately
 * from message_recipients.unread_count and merged in here.
 *
 * "YOUR WEEK" = UNREAD, LAST 7 DAYS (Ian, 2026-07-27). Not a replay of what the
 * member already read in the bell or cleared off the DM badge, and never
 * resurfacing old news. The unread filter is applied at the source; the window is
 * applied at the source too. This class trusts what it is given.
 *
 * EMPTY MEANS ABSENT. A member with nothing gets NO section — not a "you have 0
 * notifications" block. render() returns '' and the caller emits nothing.
 */
class LG_WD_Recap {

	/** Section heading. */
	const HEADING = 'Your week';

	/** Most rows we will draw before rolling the tail into one "N more" line. */
	const MAX_ROWS = 8;

	/**
	 * ── THE SOURCE BOUNDARY ──────────────────────────────────────────────────
	 *
	 * The complete, explicit list of what this recap reports on. Nothing else in
	 * the bell reaches the digest: a notification type absent from this map is
	 * dropped, silently and by default. **New types do NOT flow in automatically.**
	 *
	 * That property is deliberate and it matters beyond tidiness. The open SS9.1
	 * question — per-event email vs digest for discussion activity — is not ruled
	 * on. If thread-follow ships `forum.followed_topic` while per-thread email is
	 * also on, the same reply could reach a member twice: once per event, once
	 * here. Because this map is an ALLOW-LIST rather than a deny-list, that cannot
	 * happen by accident — the digest stays silent about any type nobody has added
	 * on purpose.
	 *
	 * So when Ian rules, the change is one line in this map:
	 *   - "digest owns discussion activity"  → add 'forum.followed_topic' => 'replies'
	 *   - "per-event owns it"                → change nothing; we already exclude it
	 *   - "digest owns it, per-event is off for followed threads" → still one line
	 *     here, plus the per-event side turning itself off. No re-architecture.
	 *
	 * DO NOT build de-duplication against a rule that does not exist yet.
	 *
	 * Value = the display bucket, which also fixes ordering (see build_rows): things
	 * said TO you outrank things done to your posts.
	 *
	 * ── THE TO-DO TEST (Ian, 2026-07-28). THIS IS NOW THE ADMISSION RULE. ────────
	 *
	 * **The digest is a to-do list, not a news feed.** His words: "we don't need
	 * connection acceptance. Just things that require attention from the user like a
	 * connection request etc."
	 *
	 * So the question for any type, existing or new, is one question:
	 *
	 *              DOES THIS WAIT ON THE MEMBER?
	 *
	 * If nothing is owed by them, it does not belong here — however pleasant it is
	 * to hear. Two types were removed on that test 2026-07-28:
	 *
	 *   connection_accept   they already have the connection; nothing is owed. Telling
	 *                       them a week later is noise. (147 rows all-time on live.)
	 *   reaction.on_post    someone liked something. Nothing is owed. (53 all-time.)
	 *
	 * TWO ADMISSIONS I JUDGED RATHER THAN WAS TOLD, both flagged to keeper, both one
	 * line to reverse if the call goes the other way:
	 *
	 *   forum.reply_to_reply  KEPT. Ian's list named reply_to_topic and mention; this
	 *                         one appeared in neither the include nor the exclude
	 *                         list, and it is already in. On the test it behaves
	 *                         exactly like reply_to_topic — someone has said something
	 *                         to you in a conversation you are in, and may be waiting
	 *                         on an answer. Excluding it while including reply_to_topic
	 *                         would be an odd asymmetry. (4 rows all-time.)
	 *   unread DMs            KEPT, and they are not in this map at all — they come
	 *                         from message_recipients (see rows_from_dms). An unread
	 *                         message is the most literally-waiting-on-you thing the
	 *                         platform has, so the test admits them easily.
	 */
	const INCLUDED_TYPES = [
		'forum.mention'        => 'mentions',     // someone addressed you directly — Ian ruled IN
		'forum.reply_to_topic' => 'replies',      // a reply on a discussion YOU authored — Ian ruled IN
		'forum.reply_to_reply' => 'replies',      // a reply to YOUR reply — same test, see above
		'connection_request'   => 'connections',  // they must accept or decline — the archetype
	];

	/**
	 * Every platform notification type this digest has DELIBERATELY refused, with
	 * the reason it fails Ian's to-do test.
	 *
	 * ── WHY A DENY-LIST NEXT TO AN ALLOW-LIST IS NOT REDUNDANT ──────────────────
	 * INCLUDED_TYPES already makes the digest safe: anything not in it is excluded,
	 * so a new platform type can never leak into a member's email. Safe is not the
	 * same as DECIDED. A type added to profile-app tomorrow would be excluded by
	 * accident, silently, and nobody would ever be asked whether it belongs — and
	 * the answer for something like a moderation action or an event reminder is not
	 * obviously "no".
	 *
	 * So these two lists TOGETHER must account for every type
	 * `profile-app/src/Notifications.php::TYPES` can write. dev/verify-source-boundary
	 * reads that array from source and fails if any type appears in neither — which
	 * turns "someone added a notification type" from a silent event into a red
	 * suite, at the moment it is cheapest to decide.
	 *
	 * This is a REGISTER, not a mechanism: nothing reads it at render time.
	 */
	const DECIDED_EXCLUDED = [
		'connection_accept'    => 'they already have the connection — nothing is owed (147 rows all-time)',
		'reaction.on_post'     => 'someone liked something — nothing is owed (53 rows all-time)',
		'forum.followed_topic' => 'a reply in a thread you merely WATCH does not wait on you; you are an observer, not the addressee (thread-follow, 2026-07-28)',
		'message'              => 'dead code in profile-app — unread DMs are read from message_recipients instead, not from the bell',
	];

	/**
	 * Buckets in render order. Unread DMs are spliced in after `replies` — they are
	 * not a notification type at all (profile-app deliberately does not ring the bell
	 * for a new message), so they are read from message_recipients instead.
	 */
	const BUCKET_ORDER = [ 'mentions', 'replies', '__dms__', 'connections' ];

	/**
	 * Types whose row is a link into the Hub and is therefore worthless without a
	 * resolved `target_url`. Connection rows are not: they name a person, and the
	 * profile link is resolved separately.
	 */
	const REQUIRES_TARGET_URL = [ 'forum.mention', 'forum.reply_to_topic', 'forum.reply_to_reply' ];

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Render the section, or '' when the member has nothing this week.
	 *
	 * THE SHAPE IS SETTLED (Ian, 2026-07-27, from the frames): discussion titles are
	 * named, every row carries its own deep link, and the section sits at the top of
	 * the digest.
	 *
	 * ONE CLAUSE OF THAT RULING WAS SUPERSEDED THE NEXT DAY, and it is written out
	 * rather than quietly dropped: 2026-07-27 also settled "reactions are included,
	 * batched". Ian's to-do ruling of 2026-07-28 removed reactions entirely — nothing
	 * is owed on a reaction. The 07-27 decision about SHAPE stands; its decision about
	 * one type's ADMISSION does not. See INCLUDED_TYPES above.
	 *
	 * The layout knobs the frames
	 * used to draw the alternatives are GONE ON PURPOSE — Ian asked for the chosen
	 * design to be built and the alternative dropped rather than left as an option,
	 * so nobody re-litigates it from a config flag. The alternatives survive only as
	 * history in docs/atlas/WEEKLY-DIGEST-RECAP.md §4.
	 *
	 * @param array $payload Normalised recap payload — see LG_WD_Recap_Source.
	 */
	public static function render( array $payload ): string {
		$rows = self::build_rows( $payload );

		// EMPTY MEANS ABSENT. No heading, no panel, no zero-state, no greeting.
		if ( ! $rows ) {
			return '';
		}

		$body = '';
		foreach ( $rows as $i => $row ) {
			$body .= self::render_row( $row, $i === 0 );
		}

		$html = self::render_heading() . self::render_greeting( $payload );

		$html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation"'
			. ' style="background-color:#FFFDF7;border:1px solid #E6DFCE;border-radius:6px;margin-bottom:10px;">'
			. '<tr><td style="padding:4px 16px;">'
			. '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">'
			. $body
			. '</table>'
			. '</td></tr></table>';

		return '<div style="margin-bottom:28px;">' . $html . '</div>';
	}

	// ── Row construction ──────────────────────────────────────────────────────

	/**
	 * Turn the payload into ordered display rows.
	 *
	 * Order is by importance-to-the-member, not by timestamp: someone talking TO you
	 * (mention, reply, DM) outranks a connection request, which sits last because it
	 * is an action you take at your leisure. A strict recency sort would bury a
	 * mention under three connection requests, and this week 91 of 97 admitted rows
	 * platform-wide ARE connection requests — so the ordering is doing real work, not
	 * decorating an even mix.
	 */
	public static function build_rows( array $payload ): array {
		$notes = $payload['notifications'] ?? [];
		$dms   = $payload['dms'] ?? [];

		$buckets = array_fill_keys( self::BUCKET_ORDER, [] );

		foreach ( $notes as $n ) {
			$type = (string) ( $n['type'] ?? '' );

			// THE SOURCE BOUNDARY, enforced in one place. A type nobody put in the
			// map is not ours to report on, and is dropped here.
			$bucket = self::INCLUDED_TYPES[ $type ] ?? null;
			if ( $bucket === null ) {
				continue;
			}

			$row = self::row_from_notification( $n );
			if ( $row ) {
				$buckets[ $bucket ][] = $row;
			}
		}

		$buckets['__dms__'] = self::rows_from_dms( $dms );

		$rows = array_merge( ...array_values( $buckets ) );

		// ── THE COUNTED REGISTER (Ian, 2026-07-28) ───────────────────────────
		// Fresh items are NAMED above. Anything still unresolved from before the
		// window is COLLAPSED into one line per type, appended after the named rows
		// — "the fresh ones have a name and the stale ones have a collective
		// number". It sits last on purpose: a count is a reminder, not news, and it
		// must never push a named item off the end.
		$rows = array_merge( $rows, self::rows_from_stale( $payload['stale'] ?? [] ) );

		// A busy week must not turn the digest into a wall. Keep the highest-value
		// rows (the order above already ranks them) and roll the tail into one line
		// rather than truncating silently — a recap that quietly drops six things is
		// worse than one that says it did.
		if ( count( $rows ) > self::MAX_ROWS ) {
			$hidden = count( $rows ) - self::MAX_ROWS;
			$rows   = array_slice( $rows, 0, self::MAX_ROWS );
			$rows[] = [
				'lead' => self::n( $hidden ) . ' more ' . ( $hidden === 1 ? 'update' : 'updates' ) . ' waiting for you',
				'sub'  => '',
				'url'  => '/hub/',
				'kind' => 'more',
			];
		}

		return $rows;
	}

	/**
	 * One bell row → one display row, or null when it cannot be rendered honestly.
	 *
	 * A Hub row with no target_url is DROPPED rather than rendered unlinked: the
	 * bridge only omits the URL when it could not resolve the slugs, which means we
	 * do not actually know where the thing lives. Same rule the bell itself uses —
	 * never a dead link, and here, never a row that names something unreachable.
	 */
	private static function row_from_notification( array $n ): ?array {
		$type    = (string) ( $n['type'] ?? '' );
		$actor   = self::clean_name( (string) ( $n['actor_name'] ?? '' ) );
		$count   = max( 1, (int) ( $n['actor_count'] ?? 1 ) );
		$url     = (string) ( $n['target_url'] ?? '' );
		$title   = self::clean_name( (string) ( $n['title'] ?? '' ) );
		$actors  = self::actor_phrase( $actor, $count );
		if ( in_array( $type, self::REQUIRES_TARGET_URL, true ) && $url === '' ) {
			return null;
		}
		if ( $actor === '' ) {
			return null;
		}

		$where = $title;   // titles are named — Ian's call, no longer optional

		switch ( $type ) {
			case 'forum.mention':
				$lead = $count > 1
					? self::n( $count ) . ' people mentioned you'
					: $actor . ' mentioned you';
				$sub = $where !== '' ? 'in ' . self::quote( $where ) : 'in a discussion';
				if ( $count > 1 ) {
					$sub .= ' — ' . $actors;
				}
				break;

			case 'forum.reply_to_topic':
				$lead = $count > 1
					? self::n( $count ) . ' new replies on your discussion'
					: '1 new reply on your discussion';
				$sub = ( $where !== '' ? self::quote( $where ) . ' — ' : '' ) . $actors;
				break;

			case 'forum.reply_to_reply':
				$lead = $count > 1
					? self::n( $count ) . ' replies to your comment'
					: '1 reply to your comment';
				$sub = ( $where !== '' ? 'in ' . self::quote( $where ) . ' — ' : '' ) . $actors;
				break;

			case 'connection_request':
				$lead = $actor . ' wants to connect';
				$sub  = '';
				break;

			// `reaction.on_post` and `connection_accept` had arms here until
			// 2026-07-28. They are gone rather than left unreachable behind the
			// allow-list: Ian's to-do test excluded both, and a render arm for a type
			// the boundary refuses is dead weight that reads like a live feature.
			// Restoring either means restoring its INCLUDED_TYPES line too.

			default:
				// Unreachable: build_rows already dropped anything outside
				// INCLUDED_TYPES. Kept as the second half of the belt-and-braces —
				// the boundary should hold even if this method is called directly.
				return null;
		}

		return [ 'lead' => $lead, 'sub' => $sub, 'url' => $url, 'kind' => $type ];
	}

	/** Unread DM threads → one display row PER SENDER (see below). */
	private static function rows_from_dms( array $dms ): array {
		// COALESCE BY SENDER, NOT BY THREAD. Two people can hold two threads with
		// each other (a legacy BB thread and a native one both survived the
		// migration), and rendering per-thread produced the literal line "1 unread
		// message from Ian Davlin" twice in the first frame — which reads as a bug
		// to the member even though both rows were true. What they care about is
		// who is waiting on them, so a person appears exactly once.
		$bySender = [];
		foreach ( $dms as $d ) {
			$unread = (int) ( $d['unread'] ?? 0 );
			if ( $unread < 1 ) {
				continue;
			}
			$senders = array_values( array_filter( array_map(
				[ __CLASS__, 'clean_name' ],
				(array) ( $d['senders'] ?? [] )
			) ) );
			if ( ! $senders ) {
				continue;
			}
			$slugs = array_values( array_filter( (array) ( $d['sender_slugs'] ?? [] ) ) );

			// Group threads share one row keyed by the whole peer set; a 1:1 thread
			// keys on the one sender, so both of that person's threads land together.
			$key = implode( '|', $senders );
			if ( ! isset( $bySender[ $key ] ) ) {
				$bySender[ $key ] = [ 'unread' => 0, 'senders' => $senders, 'slugs' => $slugs ];
			}
			$bySender[ $key ]['unread'] += $unread;
		}

		// Busiest correspondent first — the person waiting on you most.
		usort( $bySender, fn( $a, $b ) => $b['unread'] <=> $a['unread'] );

		$rows = [];
		foreach ( $bySender as $g ) {
			// NO DEEP LINK INTO THE THREAD, DELIBERATELY. The messages surface is a
			// modal opened from the header button (`data-lg-msg-link`,
			// site-header.php:478); social-modals.js reads no query param and no
			// hash, so there is NO URL that opens a given DM thread today. Rather
			// than invent one that lands nowhere, the row points at the SENDER'S
			// PROFILE — a real page, one tap from "Message". A group thread has no
			// single profile to point at, so it renders unlinked rather than
			// arbitrarily picking a peer. WEEKLY-DIGEST-RECAP.md §5 spells out the
			// small `?dm=<uuid>` change that would make this row land in the thread.
			$url = ( count( $g['senders'] ) === 1 && count( $g['slugs'] ) === 1 )
				? '/u/' . rawurlencode( (string) $g['slugs'][0] )
				: '';

			$rows[] = [
				'lead' => $g['unread'] > 1
					? self::n( $g['unread'] ) . ' unread messages from ' . self::name_list( $g['senders'] )
					: '1 unread message from ' . self::name_list( $g['senders'] ),
				'sub'  => '',
				'url'  => $url,
				'kind' => 'message',
			];
		}

		return $rows;
	}

	// ── Rendering ─────────────────────────────────────────────────────────────

	/**
	 * The gold-rule heading, byte-for-byte the treatment
	 * LG_WD_Email_Builder::render_group_header() uses, so the recap reads as part of
	 * the digest rather than a bolted-on block.
	 */
	private static function render_heading(): string {
		return '<div style="margin-bottom:8px;">'
			. '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation"><tr>'
			. '<td style="padding:0;white-space:nowrap;">'
			. '<span style="font-family:Georgia,\'Times New Roman\',serif;font-size:20px;font-weight:700;'
			. 'color:#2B2318;text-transform:uppercase;letter-spacing:2px;">' . esc_html( self::HEADING ) . '</span>'
			. '</td>'
			. '<td width="100%" style="padding-left:14px;"><div style="height:2px;background:#ECB351;"></div></td>'
			. '</tr></table></div>';
	}

	/**
	 * "Here's your week, Dave." — or, with no name to use, "Here's your week."
	 *
	 * MATCHES THE EXISTING CONVENTION, does not invent a second one. The platform
	 * already greets members on the front feed (archive-poc/web/index.php:504-518,
	 * "Welcome back, <first>."), and that code carries a rationale this section must
	 * inherit rather than re-decide:
	 *
	 *   FIRST WHITESPACE TOKEN ONLY. The legacy name-field system backfilled BUSINESS
	 *   names into profile display_name for many members — "Buck Van Laarhoven VL
	 *   Guitar Repair", "Dave Staudte (rhymms with "Howdy") NB Guitar Repair (New
	 *   Braunfels, TX)" — so the first word is the only token reliably the PERSON and
	 *   not the business. Greeting someone with 71 characters of shop name is exactly
	 *   what that convention exists to prevent, which is also why the long-name frame
	 *   in the deck shows a short greeting above a long row.
	 *
	 *   NO NAME → NO NAME. The front feed drops to a name-less greeting rather than
	 *   substituting anything; so does this. Never user_login, never user_nicename,
	 *   never a Patreon handle — a member who set their own name must see that name,
	 *   and a member who set none must not be shown a machine one. (For the record:
	 *   zero of the 1,851 live profiles have an empty display_name and zero have a
	 *   bare patreon handle in it, so this is a guard, not a common path.)
	 *
	 * The name is decoded before use — 20 live display_names carry HTML entity damage
	 * ("Georgios Gerogiannis Rupicapra, Wood &amp; Voltage"), and greeting someone
	 * "Wood &amp; Voltage" would be worse than not greeting them. clean_name() does
	 * the decode; esc_html() at the end re-encodes correctly for the email.
	 */
	private static function render_greeting( array $payload ): string {
		$name = self::clean_name( (string) ( $payload['display_name'] ?? '' ) );

		$first = '';
		if ( $name !== '' ) {
			$parts = preg_split( '~\s+~', $name );
			$first = is_array( $parts ) ? trim( (string) $parts[0] ) : '';
		}

		$line = $first !== ''
			? 'Here&rsquo;s your week, ' . esc_html( $first ) . '.'
			: 'Here&rsquo;s your week.';

		// A <div>, not a <p>, on purpose: the section's invariant is that it contains
		// NO prose markup at all (<p>, <br>, <blockquote>, <img>), which is what
		// pasted content would drag in, and the verify asserts exactly that with no
		// carve-outs. A carve-out for "our own paragraph" is where a future leak
		// would hide. Renders identically in every email client.
		return '<div style="font-size:15px;color:#5C4E3A;line-height:1.5;margin:0 0 12px;">' . $line . '</div>';
	}

	private static function render_row( array $row, bool $first ): string {
		$border = $first ? '' : 'border-top:1px solid #EFE9DA;';
		$lead   = esc_html( $row['lead'] );
		$sub    = $row['sub'] !== '' ? esc_html( $row['sub'] ) : '';
		$url    = (string) $row['url'];

		$dot = '<td width="18" valign="top" style="' . $border . 'padding:12px 0 12px 0;">'
			. '<div style="width:7px;height:7px;border-radius:50%;background-color:' . self::dot_color( $row['kind'] ) . ';margin-top:7px;"></div>'
			. '</td>';

		$leadHtml = '<span style="font-size:16px;font-weight:700;color:#2B2318;line-height:1.45;">' . $lead . '</span>';

		if ( $url !== '' ) {
			$href     = esc_url( self::absolute( $url ) );
			$leadHtml = '<a href="' . $href . '" style="font-size:16px;font-weight:700;color:#2B2318;'
				. 'line-height:1.45;text-decoration:none;border-bottom:1px solid #ECB351;">' . $lead . '</a>';
		}

		$subHtml = $sub !== ''
			? '<div style="font-size:14px;color:#5C4E3A;line-height:1.5;margin-top:3px;">' . $sub . '</div>'
			: '';

		return '<tr>' . $dot
			. '<td valign="top" style="' . $border . 'padding:12px 0 12px 10px;">'
			. $leadHtml . $subHtml
			. '</td></tr>';
	}

	/** A quiet per-kind accent so the eye can sort the list without extra words. */
	private static function dot_color( string $kind ): string {
		switch ( $kind ) {
			case 'forum.mention':       return '#FE6A4F';  // CORAL — someone addressed you by name
			case 'message':             return '#ECB351';  // GOLD
			case 'connection_request':  return '#87986A';  // MINT_DARK
			default:                    return '#87986A';
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Make a stored site-relative path absolute for email. The bridge stamps
	 * site-relative URLs on purpose (they survive a host flip); an email has no
	 * origin, so they must be absolutised at render — and only then UTM-tagged, so
	 * recap clicks are attributable like every other digest link.
	 */
	private static function absolute( string $path ): string {
		$url = ( strpos( $path, 'http' ) === 0 ) ? $path : home_url( $path );
		return class_exists( 'LG_WD_Email_Builder' ) ? LG_WD_Email_Builder::add_utm( $url ) : $url;
	}

	/**
	 * Display names reach us HTML-encoded in places (profile_app stores e.g.
	 * "Catches Guitars &amp; Banjos"). Decode once here so esc_html() at render
	 * produces "&amp;" and not "&amp;amp;".
	 */
	private static function clean_name( string $s ): string {
		return trim( html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/** "Doug Proper and 2 others" — the bell's own sentence shape. */
	private static function actor_phrase( string $actor, int $count ): string {
		if ( $actor === '' ) {
			return '';
		}
		if ( $count <= 1 ) {
			return $actor;
		}
		$others = $count - 1;
		return $actor . ' and ' . ( $others === 1 ? '1 other' : self::n( $others ) . ' others' );
	}

	private static function name_list( array $names ): string {
		$n = count( $names );
		if ( $n === 1 ) {
			return $names[0];
		}
		if ( $n === 2 ) {
			return $names[0] . ' and ' . $names[1];
		}
		return $names[0] . ', ' . $names[1] . ' and ' . self::n( $n - 2 ) . ' others';
	}

	/**
	 * The counted register: one row per type for items already named in an earlier
	 * email and still not dealt with.
	 *
	 * COPY IS IAN'S AND IS DELIBERATELY NOT INFLATED — "You have 6 connection
	 * requests waiting". One short line, no explanation, no apology for mentioning
	 * it again. The whole value of a count is that it costs the reader nothing.
	 *
	 * THE SINGULAR IS THE COMMON CASE, not the edge: measured on live 2026-07-28,
	 * 224 of 237 members with anything stale have exactly ONE item, and the largest
	 * anyone has is three. So "You have 1 connection request waiting" is the line
	 * most people will actually read, and it has to be grammatical rather than an
	 * afterthought. Ian's example of 6 is above today's ceiling.
	 *
	 * These rows carry no url. A count is not a thing you can click — deep-linking
	 * "6 connection requests" to any one of them would be a lie about which. The
	 * section's own heading already links to the Hub.
	 */
	private static function rows_from_stale( array $stale ): array {
		$labels = [
			'connection_request'   => [ 'connection request',  'connection requests'  ],
			'forum.mention'        => [ 'mention',             'mentions'             ],
			'forum.reply_to_topic' => [ 'reply to a discussion of yours',
			                            'replies to your discussions' ],
			'forum.reply_to_reply' => [ 'reply to a comment of yours',
			                            'replies to your comments' ],
		];

		$rows = [];
		// Same order as the named rows, so the eye reads one list, not two.
		foreach ( self::BUCKET_ORDER as $bucket ) {
			foreach ( self::INCLUDED_TYPES as $type => $b ) {
				if ( $b !== $bucket || ! isset( $labels[ $type ] ) ) {
					continue;
				}
				$n = (int) ( $stale[ $type ] ?? 0 );
				if ( $n < 1 ) {
					continue;
				}
				$rows[] = [
					'lead' => 'You have ' . self::n( $n ) . ' '
						. ( $n === 1 ? $labels[ $type ][0] : $labels[ $type ][1] ) . ' waiting',
					'sub'  => '',
					'url'  => '',
					'kind' => 'stale',
				];
			}
		}
		return $rows;
	}

	/** Curly quotes around a discussion title — the digest sets Georgia elsewhere and
	 *  straight quotes read as code in an email. esc_html() runs later, over this. */
	private static function quote( string $s ): string {
		return '“' . $s . '”';
	}

	private static function n( int $i ): string {
		return number_format_i18n( $i );
	}
}
