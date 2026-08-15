<?php
/**
 * download-block — THE TRACKED CONFIG for routing a synthesized page's print
 * file through the `download` block instead of a prose `callout` variant files.
 *
 * Same reasoning as config/license-block.php, including why it lives inside the
 * plugin rather than in platform/config: LIVE-DEPLOY.md rsyncs lg-layout-v2/ to
 * live as a standalone plugin directory, so a sibling path up into platform/
 * resolves to nothing there and the flag's state would depend on the box.
 *
 * ── THIS ONE IS SMALL, AND THAT IS THE FINDING ──────────────────────────────
 * The charter sized this as "the loothprint page does not use the download
 * block". Measured, that is wrong: ALL 168 stored loothprint layouts already
 * emit `download` with a `file_id`, none bakes a URL, and every one of those
 * file_ids still matches the form's current loothprint_3d_file — zero drift.
 * Only 4 loothprints have no stored layout and therefore take the synthesizer.
 *
 * So this flag governs FOUR POSTS. The part that actually closes the stale-ZIP
 * defect is not flagged at all, because it is not member-visible until a file
 * is replaced: blocks/download/render.php now treats an EMPTY file_id as
 * "follow the post" and resolves the print file at render. That turns the drift
 * from "absent today" into "structurally impossible".
 *
 * Kept separate from license-block.php on purpose so Ian can accept the licence
 * change without also accepting this one.
 */

return array(

	/** OFF until Ian has looked at the running thing. */
	'enabled' => false,
);
