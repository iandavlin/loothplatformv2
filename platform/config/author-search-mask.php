<?php
/**
 * author-search-mask — THE TRACKED CONFIG for which mask the Hub's AUTHOR SEARCH
 * applies to a logged-out visitor.
 *
 * Consumed by `bb-mirror/web/forums/_suggest.php` (lg_author_search_feed_mask());
 * asserted by `tools/gates/author-search-mask-gate.py`. Arrives by `git pull`.
 *
 * ── WHY A PHP FILE AND NOT AN ENV VAR ────────────────────────────────────────
 * Same reasoning and the same read idiom as platform/config/post-follow.php,
 * which is read from a file at the SAME directory depth
 * (bb-mirror/web/forums/ -> repo root is three levels up). It also honours the
 * getenv() + $_SERVER override pair, because `fastcgi_param` lands in $_SERVER
 * and NOT reliably in getenv(), which is how a lane preview URL can otherwise
 * serve the OFF path.
 *
 * ── THE DEFECT (backlog 27, folded in by keeper 2026-08-15) ──────────────────
 * The Hub author search is effectively DEAD for logged-out visitors.
 * `/hub/?suggest=author&q=erlewine` returns ZERO on dev2 AND live for a man with
 * 54 posts. Measured, three viewers, same query: anon 0, member 1, admin 1; on
 * broad queries anon gets 0-3 where a member gets 8 — 8 being the endpoint's own
 * LIMIT. Ian has never seen it broken because he is always signed in, which is
 * why it reads to him as a missing button rather than a broken search.
 *
 * THE CAUSE IS A MASK APPLIED ONE LEG TOO WIDE. The FEED masks by
 * `discussion_visibility` only where `card_type === 'topic'` (_feed.php: its own
 * comment says "content cards are CPTs, never anonymous") — a CONTENT byline is
 * always published. The SEARCH applied that same condition to the WHOLE UNION,
 * topics AND content, so it hid content authors the feed prints by name on the
 * front page this minute. Measured: of the bylines a logged-out visitor sees on
 * the unfiltered Hub, Doug Proper has 69 content rows and 0 topics, James
 * Roadman 37 and 0, Michael Bashkin 30 and 0 — every one of them unsearchable to
 * that same visitor. They were hidden by a DISCUSSIONS privacy setting they never
 * used for discussions: `discussion_visibility` is 'member' on 506 of 517 rows
 * purely because that is the default.
 */

return array(

	/**
	 * OFF: today's behaviour, unchanged — the `discussion_visibility = 'public'`
	 *      condition is applied ONCE, on the outer query, over the whole union.
	 *      A logged-out visitor can be suggested 4 authors out of 432.
	 * ON:  the condition moves INTO THE TOPIC LEG only, which is exactly what the
	 *      feed already does. The content leg is left alone, so an author whose
	 *      byline the feed publishes becomes searchable.
	 *
	 * ⚠️ ON IS A VISIBILITY INCREASE, which is why it ships OFF and gated. It can
	 * only ever surface a name the FEED is already printing publicly — never a
	 * forum author who kept `discussion_visibility` at 'member'. The gate asserts
	 * BOTH halves: a content author becomes searchable AND a topic-only author at
	 * 'member' stays hidden. The second is the one that proves this matched the
	 * feed rather than simply opening the gate.
	 *
	 * It also fixes the COUNT for free. With the condition inside the leg, an
	 * author with both kinds (Dave Slimmer: 29 content, 14 topics at 'member')
	 * contributes only the 29 a logged-out visitor can actually see, instead of
	 * advertising 43.
	 *
	 * SIGNED-IN IS UNAFFECTED IN BOTH STATES: the condition is anon-only, so a
	 * member still gets the endpoint's full LIMIT either way.
	 */
	'enabled' => false,

);
