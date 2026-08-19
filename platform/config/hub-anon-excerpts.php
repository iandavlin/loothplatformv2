<?php
/**
 * hub-anon-excerpts — SHORT teasers for LOGGED-OUT hub viewers (#81, Ian 8/19).
 *
 * Why: the hub feed served ~440-char excerpts + a teaser reply to anonymous
 * crawlers, so Google satisfied discussion searches ON THE FEED and credited
 * /hub/ instead of the discussion's own landing page (his 8/19 screenshot).
 * ON = anon sees ~150-char teasers and no teaser reply; the full text lives
 * only on the canonical landing page (and for members, everywhere, unchanged).
 *
 * A gitignored hub-anon-excerpts.local.php beside this wins per-key — dev2
 * flips without a tracked edit; live is protected by absence (the register's
 * documented pattern).
 */
return array(
	'enabled' => false,
);
