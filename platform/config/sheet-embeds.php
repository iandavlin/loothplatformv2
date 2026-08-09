<?php
/**
 * sheet-embeds — THE TRACKED CONFIG for embeds in the MOBILE READER SHEET.
 * Backlog 3.7 (Ian 7/31), first reproduced by the mobile-embed lane.
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 * On a phone, every discussion tap opens #looth-rep-sheet — it is the only way to
 * read a discussion from the hub. The sheet assigns server HTML straight into the
 * DOM in three places (webroot/hub-polish.js: the OP body, the pre-paint excerpt
 * clone, and the replies) and none of them calls bbProcessEmbeds, the function that
 * turns a bare provider link into a player. So a discussion whose body is a YouTube
 * link reads as a naked URL on a phone and as a video everywhere else.
 *
 * The desktop reader modal calls bbProcessEmbeds on the IDENTICAL data path
 * (bb-mirror/web/forums.js, the dmB / excerpt / full assignments). This is a missing
 * call with a precedent sitting next to it, not a new behaviour.
 *
 *   OFF  the three assignments behave exactly as they do today.
 *   ON   each one is followed by bbProcessEmbeds on the node just written.
 *
 * ── HOW THE BIT REACHES THE CLIENT, AND WHY OFF IS BYTE-IDENTICAL ───────────
 * hub-polish.js is a static docroot overlay; it cannot read PHP. bb-mirror's
 * _chrome.php emits the bit next to window.LG_FORUM_BASE — but ONLY WHEN ON. With
 * the flag off no global is emitted, not even a false one, so the served page is
 * byte-for-byte what it is today and hub-polish.js's guard reads `undefined` and
 * short-circuits. An emitted `= false` would have been a behavioural no-op but not a
 * byte-identical one, and byte-identical is the house rule.
 *
 * Same shape as platform/config/social-actions.php: one flag, read in one runtime,
 * with the client's behaviour following from what the server actually sent rather
 * than from a second copy of the flag that can drift.
 *
 * ── OVERRIDES ARE FOR LANE PREVIEWS ─────────────────────────────────────────
 * getenv() for a pool or a CLI harness, $_SERVER for a single nginx location — a
 * fastcgi_param lands in $_SERVER but not reliably in the environment, so reading
 * only getenv() would serve the OFF path on the very preview URL built for Ian.
 */

return array(

	/**
	 * ON 2026-08-09, Ian's flip (decision box: "sheet-embeds"), after confirming
	 * this half is his actual 3.7 complaint: "THE MAIn problem was no imbed in
	 * the modal." Flipped for his real-phone check on the dev2 serve; reverts to
	 * false if his look fails. Reached the serve merged-OFF first per the house
	 * rule (byte-identical, gate 19), which is what made this a one-line flip.
	 *
	 * Lower-risk than most flags here — it renders a player where a bare link is
	 * today, writes nothing, and deletes nothing.
	 *
	 * ⚠️ SCOPE: this is 3.7's EMBED half only. The other half of that backlog line —
	 * "the video can't be played from the card" — is NOT this flag and must not be
	 * folded into it. That one is a REVERSAL of Ian's own 2026-06-17 ruling
	 * (docs/atlas/DISCUSSION-CARD-VIDEO.md, "Desktop only, on purpose"), because a
	 * cover tap cannot both play the video and open the discussion. It needs a
	 * ruling from him before any code, and it gets its own flag when it does.
	 */
	'enabled' => true,
);
