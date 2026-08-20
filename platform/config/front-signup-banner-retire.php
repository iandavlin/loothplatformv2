<?php
/**
 * front-signup-banner-retire — THE TRACKED CONFIG for whether the logged-out
 * front page still carries the green "Join the Looth community" strip under the
 * header. Issue #169, asked for by Ian 2026-08-20.
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 * Ian 8/20, from his logged-out funnel walk, verbatim: "The secondary join on
 * the front page at the top banner can go away."
 *
 *   OFF  The banner renders exactly as it has: <aside class="signup-banner">
 *        with the title, body and amber CTA drawn from rows.json's
 *        `signup_banner` key. Today's behaviour, for everybody.
 *   ON   The anon banner is not emitted at all.
 *
 * WHY IT IS A "SECONDARY" JOIN, which is the whole point of the issue: on the
 * logged-out front page at 1440 there are THREE join doors above the fold — the
 * header's amber Join pill, this strip's amber "Join →", and the hero's "Join
 * Looth Group →" button. This retires the middle one. Measured, not assumed:
 * see footer-mockups/169-front-polish/before/front-dark-desktop.png.
 *
 * ⚠️ ── WHAT MUST NOT MOVE: THE MEMBER GREETING ───────────────────────────────
 * The banner is the `if` half of an if/elseif in archive-poc/web/index.php. The
 * `elseif ($is_member)` half renders the "Welcome back, <name>." greeting, which
 * is a DIFFERENT feature that happens to share the .signup-banner class (it adds
 * .signup-banner--member) and the same slot on the page. This flag gates the
 * ANON half only.
 *
 * That is not a stylistic note — it is the collateral this change could plausibly
 * break, so gate 80 asserts the greeting still renders for a signed-in viewer in
 * BOTH flag states rather than trusting the branch shape. The CSS is shared and
 * is deliberately left alone for the same reason: deleting .signup-banner rules
 * would take the member greeting's styling with it.
 *
 * ── THE BLAST RADIUS IS SMALL, AND THAT WAS CHECKED ──────────────────────────
 * Unlike the header partial (seven apps, every page), this markup lives in ONE
 * script that nginx routes exactly two URLs to — snippets/strangler-archive-poc
 * .conf has `location = /` and `location = /front-page/`, both pointing at
 * /srv/archive-poc/web/index.php. There is no third route and no other consumer.
 *
 * ── WHY A FLAG AT ALL, GIVEN THAT ────────────────────────────────────────────
 * The house rule, for the ordinary reason: the dev2 serve runs main, so nothing
 * is verifiable there until it is already merged. OFF is what lets this arrive
 * harmlessly, get looked at on the real serve, and only then be switched on.
 * It is also a REMOVAL of a member-facing call to action on the highest-traffic
 * anonymous page on the site — the kind of change that should be reversible by
 * flipping one file rather than by writing a revert.
 *
 * ── ON BYTE-PROVING OFF, STATED HONESTLY ─────────────────────────────────────
 * #165 could compare its OFF path to main byte-for-byte because it renders a
 * PARTIAL with a fixed ctx. This is a whole dynamic page — feeds, a featured
 * member, cache-busting mtimes — so a literal "the page is byte-identical"
 * claim would be false, and claiming it anyway is how an OFF-is-a-no-op
 * assertion becomes decoration.
 *
 * What gate 80 proves instead, which is the real question: ABSENT and OFF are
 * byte-identical to each other, and ON differs from OFF by EXACTLY the banner
 * <aside> and nothing else — the delta is extracted and compared, so an
 * unrelated change riding along on this flag cannot pass.
 *
 * ⚠️ ── `php -l` THE .local.php BEFORE YOU PLACE IT ──────────────────────────
 * The reader is defensive about every shape a wrong config can take — empty,
 * non-array, missing key, returning nothing, unreadable — and each falls back to
 * today's behaviour (banner shown). There is exactly ONE it cannot defend
 * against: a PHP SYNTAX ERROR, because `@` suppresses warnings, not parse
 * errors. Same property as every other flag in this directory:
 *
 *     php -l platform/config/front-signup-banner-retire.local.php
 *
 * ── OVERRIDES ARE FOR LANE PREVIEWS AND RED-FIRST LEGS ───────────────────────
 * LG_FRONT_SIGNUP_BANNER_RETIRE is read from getenv() AND $_SERVER. A
 * fastcgi_param lands in $_SERVER but not reliably in the environment, so a
 * getenv()-only reader would serve the OFF path on the very preview URL built
 * for Ian to click. Never a deploy mechanism — the deploy mechanism is the
 * .local.php beside this file, because dev2's FPM pool files are SYMLINKS INTO
 * THE SERVING CHECKOUT and an env[] flip dirties a tracked file in the one
 * checkout that must only ever pull.
 */

return array(

	/**
	 * OFF until Ian has seen the front page without the strip on the dev2 serve,
	 * logged out, and said so.
	 *
	 * Low blast radius when it does go on: it removes one <aside> from one
	 * script serving two URLs. It writes nothing, deletes nothing, grants
	 * nothing, and touches no signed-in viewer — the member greeting beside it
	 * is a separate branch and is asserted unchanged in both states.
	 */
	'enabled' => false,
);
