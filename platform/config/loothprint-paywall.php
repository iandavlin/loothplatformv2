<?php
/**
 * loothprint-paywall — THE TRACKED CONFIG for the member-facing paywall toggle
 * on the front-end Loothprint form.
 *
 * Ian, 2026-08-21, verbatim: "I would like the form to have a toggle for the user
 * to decide if behind the paywall. Default to behind the paywall. This should
 * toggle the tier selector looth-lite or public."
 *
 * ── WHY THIS IS ITS OWN FILE AND NOT A KEY IN frontend-compose.php ───────────
 * Compose is already ON on dev2 (via its gitignored .local.php) and OFF on live.
 * Folding the toggle into that file would mean either launching it with compose
 * on live's next flip, or forcing two independent decisions to move together.
 * They are separate rulings, so they are separate switches.
 *
 * A tracked PHP file read relative to __DIR__, never an env var, and never an FPM
 * `env[]`. Three recorded reasons on this box: WP cron carries no Environment= at
 * all; an fpm fastcgi_param lands in $_SERVER but not in getenv(); and dev2's pool
 * files are symlinks into the SERVING CHECKOUT, so a pool env flip dirties tracked
 * files there and a later `pull --ff-only` can refuse.
 *
 * ── THE PREVIEW OVERRIDE HAS ITS OWN NAME, DELIBERATELY ──────────────────────
 * ⚠️ LG_FC_PAYWALL_PREVIEW, *not* LG_FC_PREVIEW. Copying the compose reader
 * line-for-line would have inherited LG_FC_PREVIEW — and
 * platform/nginx/lane-preview-frontend-compose.conf already sets that as a
 * fastcgi_param in two places, so the literal copy would have silently armed the
 * PAYWALL on every compose lane-preview URL. A flag that switches on because a
 * different feature's preview is running is not a flag.
 *
 * ── WHAT OFF MEANS ───────────────────────────────────────────────────────────
 * OFF renders no control and performs NO tier write: the form's bytes and the
 * post's terms are both exactly what they are today. That is asserted per-state
 * (absent / OFF / ON) by gate 35 rather than hardcoded, so flipping this default
 * needs no gate edit.
 */

return array(

	/**
	 * OFF until Ian has looked at the running thing.
	 *
	 * Member-facing and it CHANGES WHAT GETS PUBLISHED, which is a stronger reason
	 * to arrive inert than the usual one. Measured 2026-08-21: the front-end form
	 * writes no tier term at all today, so every loothprint composed there lands
	 * PUBLIC — while 161 of 174 loothprints on this box are looth-lite. Switching
	 * this on flips the default for every new member post from public to
	 * paywalled, which is exactly what Ian ruled and exactly why it is his flip
	 * and not a lane's.
	 */
	'enabled' => false,
);
