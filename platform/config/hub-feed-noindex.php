<?php
/**
 * hub-feed-noindex (#81, Ian 8/19 ruling: strangers see the member-identical
 * hub — no teasers). ON = the bare /hub/ carries <meta robots noindex,follow>
 * for crawlers only; every modal-open per-topic address stays indexable, so
 * Google's links migrate there. Humans see zero difference in any state.
 * Gitignored .local.php beside this wins per-key; live protected by absence.
 */
return array(
	'enabled' => false,
);
