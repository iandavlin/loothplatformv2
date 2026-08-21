<?php
/**
 * tester-unlock — THE TRACKED CONFIG for the anonymous tester's unlock link.
 * Issue #180, asked for by Ian 2026-08-21.
 *
 * The mechanism, the fences and the reasoning all live in one place —
 * lg-shared/tester-unlock.php. Read that before changing anything here. This
 * file is only the two values, and the tracked default is OFF twice over.
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 * ARMED, one shareable URL marks one browser with a cookie, and for that browser
 * only the header's Join points at /lgjoin/ instead of patreon.com — and the
 * join-flow door admits it. Every other visitor, anonymous or signed-in, sees
 * exactly what they see today.
 *
 * Ian, 2026-08-21, verbatim:
 *
 *     "dev2 join goes to stripe rather than patreon with a fresh incognito. I
 *      need it to go to patreon unless the user has some kind of token url or
 *      something to unlock the whitelisted pages."
 *
 * ── IT ONLY MEANS ANYTHING IN 'allowlist' ────────────────────────────────────
 * This widens header-join-stripe's 'allowlist' state; it does not add a fourth.
 * In 'off' — which is that flag's tracked default — 'off' still means NOBODY and
 * an unlock cookie changes nothing. In 'on' everybody already gets /lgjoin/ and
 * the cookie is redundant. Both boxes are meant to sit in 'allowlist' during the
 * soft launch (dev2 already does), so arming this is ONE act, not two — but if
 * you arm it on a box whose header says 'off', nothing will happen and nothing
 * is broken. Gate 85 reports that pairing rather than asserting it, so it can
 * never redden a lane with nothing to do with it.
 *
 * ── OFF IS BYTE-IDENTICAL, AND THAT IS GATED ─────────────────────────────────
 * With this file absent, malformed, disabled, or enabled-but-hashless, every
 * function in the reader returns "no" before reading a cookie, so the header
 * renders byte-for-byte what origin/main renders. Gate 85 proves it with cmp
 * rather than by repeating this paragraph — including with the nginx microcache
 * change present, since that lands in the same merge and an OFF state proven
 * only against the old conf would be proving the wrong tree.
 *
 * ── WHY THE HASH AND NOT THE TOKEN ───────────────────────────────────────────
 * token_sha256 is sha256 of the token, and the token is what the URL and the
 * cookie carry. Reading this file — or a backup of it, or a stray copy in a
 * paste buffer — hands nobody a working link. The raw token never enters the
 * repo, and gate 85 asserts that structurally.
 *
 * ── ARMING A BOX (never here — always the .local.php beside this file) ───────
 *   1. mint:   openssl rand -hex 24
 *   2. hash:   printf %s "<token>" | sha256sum
 *   3. write platform/config/tester-unlock.local.php (gitignored) with
 *      'enabled' => true and 'token_sha256' => '<the hash>'
 *   4. php -l platform/config/tester-unlock.local.php   ← DO NOT SKIP. The
 *      reader is defensive about every wrong VALUE but cannot survive a parse
 *      error, and this reaches every page of seven apps.
 *   5. hand out https://<host>/lgjoin/?lgtester=<token>
 *
 * ROTATE by replacing the hash — every cookie already out there stops matching
 * on the next request. TURN OFF with 'enabled' => false, or by emptying the
 * hash; either is instant and total. A visitor can always clear their own mark
 * with /lgjoin/?lgtester=off, which needs no token and works even after a
 * rotation.
 *
 * ⚠️ THE TOKEN IS NOT A PAYWALL AND WAS NEVER MEANT TO BE. Ian, same day: "It's
 * ok. No one can sign up on the join page unless they are white listed." A
 * forwarded link admitting a stranger to LOOK at the join page is an accepted
 * outcome. What must stay true is that looking is not buying — see the reader's
 * docblock for what actually enforces that today, which is NOT a whitelist.
 */

return array(

	/**
	 * OFF until Ian has clicked the real link on the dev2 serve, in a fresh
	 * incognito window. The house rule, for the house reason: the serve runs
	 * main, so nothing is verifiable there until it is merged, and OFF is what
	 * lets this arrive harmlessly first.
	 *
	 * Turning it on writes nothing, deletes nothing and grants nothing — it
	 * changes one href for one browser and admits that browser to a page it can
	 * already be admitted to by four other means.
	 */
	'enabled' => false,

	/**
	 * sha256 of the unlock token. EMPTY IN THE REPO, ALWAYS.
	 *
	 * Empty is not "unset, so allow" — it is a dead config that can never match
	 * any cookie. 'enabled' => true with no hash is therefore still OFF, so a
	 * half-placed override fails closed rather than open.
	 */
	'token_sha256' => '',
);
