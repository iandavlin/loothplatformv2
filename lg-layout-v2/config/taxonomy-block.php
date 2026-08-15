<?php
/**
 * taxonomy-block — THE TRACKED CONFIG for showing a post's Loothprint Type and
 * Content Topic on the page.
 *
 * Same reasoning and the same in-plugin location as config/license-block.php:
 * LIVE-DEPLOY.md rsyncs lg-layout-v2/ to live standalone, so a sibling path up
 * into platform/ resolves to nothing there.
 *
 * ── WHAT THIS FLAG DOES *NOT* DO, AND WHY THAT IS DELIBERATE ────────────────
 * It only makes the SYNTHESIZER emit the block — which is 4 posts, the only
 * loothprints without a stored layout.
 *
 * The other 168 have stored layouts and would need the block INSERTED into them.
 * The licence work could swap a block that was already there; this would ADD
 * content to 157 pages that has never appeared on any of them (measured: 125
 * carry both taxonomies, 29 type only, 3 topic only, 11 neither). Where those
 * chips belong on the page — above the footer, under the header, or not at all
 * — is a design decision with a picture attached, and Ian's to make. A lane
 * switching it on unilaterally is exactly the "UI appears without anyone
 * choosing it" failure.
 *
 * So: the block exists, it is correct, and it is reachable. Extending it to the
 * stored layouts waits on his answer.
 */

return array(

	/** OFF until Ian has looked at the running thing. */
	'enabled' => false,
);
