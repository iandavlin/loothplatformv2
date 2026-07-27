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
	 * Layout knobs. Defaults are the SHIPPING recommendation; the alternates exist
	 * so the design frames can render the same real data both ways for a decision
	 * (docs/atlas/WEEKLY-DIGEST-RECAP.md §4).
	 *
	 *  titles      true  → name the discussion ("on 'Suggest an alternative…'")
	 *                      false → counts + senders only, no title
	 *  deep_links  true  → every row links to its own target (bridge's target_url)
	 *                      false → no per-row links, one "Open the Hub" button
	 *  reactions   true  → include reaction rows (batched, one row per target)
	 */
	public static function defaults(): array {
		return [ 'titles' => true, 'deep_links' => true, 'reactions' => true ];
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Render the section, or '' when the member has nothing this week.
	 *
	 * @param array $payload  Normalised recap payload — see LG_WD_Recap_Source.
	 * @param array $opts     Layout knobs, see defaults().
	 */
	public static function render( array $payload, array $opts = [] ): string {
		$opts = array_merge( self::defaults(), $opts );
		$rows = self::build_rows( $payload, $opts );

		// EMPTY MEANS ABSENT. No heading, no panel, no zero-state.
		if ( ! $rows ) {
			return '';
		}

		$body = '';
		foreach ( $rows as $i => $row ) {
			$body .= self::render_row( $row, $opts, $i === 0 );
		}

		$html = self::render_heading();

		$html .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation"'
			. ' style="background-color:#FFFDF7;border:1px solid #E6DFCE;border-radius:6px;margin-bottom:10px;">'
			. '<tr><td style="padding:4px 16px;">'
			. '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">'
			. $body
			. '</table>'
			. '</td></tr></table>';

		// When per-row deep links are OFF the rows are inert, so the section needs
		// exactly one way back into the product or it is a dead end.
		if ( empty( $opts['deep_links'] ) ) {
			$html .= self::render_hub_button();
		}

		return '<div style="margin-bottom:28px;">' . $html . '</div>';
	}

	// ── Row construction ──────────────────────────────────────────────────────

	/**
	 * Turn the payload into ordered display rows.
	 *
	 * Order is by importance-to-the-member, not by timestamp: someone talking TO
	 * you (mention, reply, DM) outranks someone reacting to you, and connection
	 * requests sit last because they are an action you take at your leisure. A
	 * strict recency sort buries a mention under three reactions.
	 */
	public static function build_rows( array $payload, array $opts ): array {
		$opts  = array_merge( self::defaults(), $opts );
		$notes = $payload['notifications'] ?? [];
		$dms   = $payload['dms'] ?? [];

		$mentions = [];
		$replies  = [];
		$reacts   = [];
		$conns    = [];

		foreach ( $notes as $n ) {
			$type = (string) ( $n['type'] ?? '' );
			$row  = self::row_from_notification( $n, $opts );
			if ( ! $row ) {
				continue;
			}
			if ( $type === 'forum.mention' ) {
				$mentions[] = $row;
			} elseif ( $type === 'forum.reply_to_topic' || $type === 'forum.reply_to_reply' ) {
				$replies[] = $row;
			} elseif ( $type === 'reaction.on_post' ) {
				if ( ! empty( $opts['reactions'] ) ) {
					$reacts[] = $row;
				}
			} elseif ( $type === 'connection_request' || $type === 'connection_accept' ) {
				$conns[] = $row;
			}
		}

		$msgs = self::rows_from_dms( $dms );

		$rows = array_merge( $mentions, $replies, $msgs, $reacts, $conns );

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
	private static function row_from_notification( array $n, array $opts ): ?array {
		$type    = (string) ( $n['type'] ?? '' );
		$actor   = self::clean_name( (string) ( $n['actor_name'] ?? '' ) );
		$count   = max( 1, (int) ( $n['actor_count'] ?? 1 ) );
		$url     = (string) ( $n['target_url'] ?? '' );
		$title   = self::clean_name( (string) ( $n['title'] ?? '' ) );
		$actors  = self::actor_phrase( $actor, $count );
		$isHub   = in_array( $type, [ 'forum.reply_to_topic', 'forum.reply_to_reply', 'forum.mention', 'reaction.on_post' ], true );

		if ( $isHub && $url === '' ) {
			return null;
		}
		if ( $actor === '' ) {
			return null;
		}

		$where = ( ! empty( $opts['titles'] ) && $title !== '' ) ? $title : '';

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

			case 'reaction.on_post':
				$what = self::reaction_what( (string) ( $n['target_kind'] ?? '' ) );
				$lead = $count > 1
					? self::n( $count ) . ' people reacted to ' . $what
					: $actor . ' reacted to ' . $what;
				$sub = $where !== '' ? self::quote( $where ) : '';
				if ( $count > 1 && $sub !== '' ) {
					$sub .= ' — ' . $actors;
				} elseif ( $count > 1 ) {
					$sub = $actors;
				}
				break;

			case 'connection_request':
				$lead = $actor . ' wants to connect';
				$sub  = '';
				break;

			case 'connection_accept':
				$lead = $actor . ' accepted your connection request';
				$sub  = '';
				break;

			default:
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

	private static function render_row( array $row, array $opts, bool $first ): string {
		$border = $first ? '' : 'border-top:1px solid #EFE9DA;';
		$lead   = esc_html( $row['lead'] );
		$sub    = $row['sub'] !== '' ? esc_html( $row['sub'] ) : '';
		$url    = (string) $row['url'];

		$dot = '<td width="18" valign="top" style="' . $border . 'padding:12px 0 12px 0;">'
			. '<div style="width:7px;height:7px;border-radius:50%;background-color:' . self::dot_color( $row['kind'] ) . ';margin-top:7px;"></div>'
			. '</td>';

		$leadHtml = '<span style="font-size:16px;font-weight:700;color:#2B2318;line-height:1.45;">' . $lead . '</span>';

		if ( ! empty( $opts['deep_links'] ) && $url !== '' ) {
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

	private static function render_hub_button(): string {
		$href = esc_url( self::absolute( '/hub/' ) );
		return '<table cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin:2px 0 0;"><tr>'
			. '<td style="background-color:#ECB351;border-radius:4px;padding:10px 20px;">'
			. '<a href="' . $href . '" style="font-size:14px;font-weight:700;color:#2B2318;'
			. 'text-decoration:none;letter-spacing:.5px;">Open the Hub &rarr;</a>'
			. '</td></tr></table>';
	}

	/** A quiet per-kind accent so the eye can sort the list without extra words. */
	private static function dot_color( string $kind ): string {
		switch ( $kind ) {
			case 'forum.mention':       return '#FE6A4F';  // CORAL — someone addressed you by name
			case 'message':             return '#ECB351';  // GOLD
			case 'reaction.on_post':    return '#D4E0B8';  // MINT_LIGHT — the quietest signal
			case 'connection_request':
			case 'connection_accept':   return '#87986A';  // MINT_DARK
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

	private static function reaction_what( string $kind ): string {
		switch ( $kind ) {
			case 'reply': return 'your reply';
			case 'card':  return 'your post';
			default:      return 'your discussion';
		}
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
