<?php
/**
 * profile-bio — THE TRACKED FLAG for sourcing author bios from the PROFILE
 * instead of WP usermeta `author_about` (Ian's ruling, 2026-08-16).
 *
 * Consumed by platform/lib/lg-profile-bio.php; asserted by
 * tools/gates/featured-card-text-onprofile-gate.py.
 *
 * OFF: lg_profile_bio() returns NULL and every caller keeps exactly the
 *      behaviour it has today (author_about, then WP description). Inert.
 * ON:  callers take the profile's published About / at-a-glance / business_name,
 *      under gate 58's visibility rules.
 *
 * ⚠️ DO NOT FLIP THIS UNTIL EVERY READER IS WIRED AND VERIFIED. `author_about`
 * still feeds the author box under every post, the post header, the editor field
 * and archive-poc-sync. Flipping this while only some callers are converted
 * leaves the site saying two different things about the same person — which is
 * the exact defect being fixed, just with newer wiring. Retiring the usermeta
 * key comes AFTER that, not before: erasing it while five things still read it
 * would blank the author box site-wide.
 */

return array(
	'enabled' => false,
);
