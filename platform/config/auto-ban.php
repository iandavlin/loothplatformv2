<?php
/**
 * auto-ban (#162, Ian 8/20: "can we just add it to a file of known offenders and
 * nip it at our webserver?" — narrowed the same day to "this should only block
 * ips that try several different logins in one block").
 *
 * ON  = lg-login-monitor's credential-stuffing detector, at the same moment it
 *       sends its alert, appends the offending address to the ban store. Same
 *       threshold, same window, no new detection anywhere.
 * OFF = nothing is written. Not a smaller file, not an empty file — no file. The
 *       alert email is byte-identical in both states and gate 84 asserts that.
 *
 * ⚠️ THIS FLAG DOES NOT BLOCK ANYONE. It only decides whether the file fills up.
 * Enforcement needs a SECOND, independent key: the box-local nginx snippet
 * /etc/nginx/snippets/lg-auto-ban-*.conf, which only tools/infra/install-auto-ban.sh
 * creates. Live is protected by that snippet's ABSENCE exactly like every other
 * flag here is protected by a missing .local.php.
 *
 * That split is the point, not an accident: Ian asked to see the ban file behave
 * on dev2 before anything is blocked, and this is the state that lets him — the
 * detector filling a file that nginx is not yet reading.
 *
 * A tracked PHP FILE, not an env var, for the two recorded reasons: WP cron
 * carries no Environment= at all, and an FPM fastcgi_param lands in $_SERVER but
 * never in getenv(). Gitignored .local.php beside this wins per-key.
 */
return array(
	// Whether the detector appends to the ban store at all. Member-facing
	// consequence (a login door that refuses someone), so OFF is the house rule.
	'enabled'     => false,

	// How long a ban lasts. Ian's ruling: bans EXPIRE, because five failures from
	// one address is usually an attacker but sometimes a guitar show's wifi, and
	// a forever-file accumulates innocents. 24h.
	'ban_seconds' => 86400,

	// Hard cap on the rendered deny list. Not a performance guess — a bound on
	// how much nginx config a compromised or runaway writer could ever produce.
	// Oldest bans fall off first.
	'max_entries' => 500,
);
