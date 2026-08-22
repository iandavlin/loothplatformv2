<?php
/**
 * loothprint-gating — THE TRACKED CONFIG for "on a Loothprint, the tier gates
 * the FILE DOWNLOAD only".
 *
 * Ian, 2026-08-22, looking at a member's live Loothprint logged OUT (post 72801,
 * The Cleanup Stik): "The gating is off too. We only need to gate the file
 * download and it shouldn't look like the video gate. I think there is a block
 * for that already available."
 *
 * ON, this flag does three things and nothing else:
 *   1. `embed` stops auto-gating on `loothprint` / `loothcuts`, so the gallery,
 *      the write-up and the video are public — Renderer::autoGateTypes().
 *   2. A synthesized page's print file renders through the `download` BLOCK
 *      (the one Ian remembered) instead of a `callout variant=files`, so its
 *      gate card wears the download face and download words rather than
 *      falling through to the video card — Plugin::default_loothprint_layout().
 *   3. The OnShape CAD link stops carrying the post tier, so exactly ONE gate
 *      panel can appear on the page. See the ⚠️ below.
 *
 * ── WHY THE FLAG LIVES INSIDE THE PLUGIN ────────────────────────────────────
 * Same reasoning as config/download-block.php and config/license-block.php:
 * LIVE-DEPLOY.md rsyncs lg-layout-v2/ to live as a standalone plugin directory,
 * so a sibling path up into platform/ resolves to nothing there and the flag's
 * state would depend on the box.
 *
 * ⚠️ AND THERE IS A SECOND COPY OF THIS FILE, ON PURPOSE.
 * /loothprint/ is served by archive-poc/standalone/render.php against a
 * VENDORED COPY of this engine, so the reader — Renderer::loothprintGatingEnabled()
 * — resolves `dirname(__DIR__).'/config/loothprint-gating.php'` inside whichever
 * tree is executing. Both copies must exist and must agree. A missing file is
 * not an error: it reads as OFF, which is the safe direction but would silently
 * split the two render paths. Change one, change both.
 *
 * ⚠️ ONE RULING READ LITERALLY, AND IT IS REVERSIBLE IN ONE LINE.
 * Ian's ruling names the file download. The synthesizer also gated the OnShape
 * CAD link, which would put a SECOND gate panel on the page — against his third
 * point ("no duplicate gate panels"). Measured: 7 loothprints carry a CAD link.
 * Read literally, a link to a CAD service is not the file, so ON un-gates it.
 * If that is wrong, restore `if ($gate) $os['gated_tier'] = $gate;` in
 * Plugin::default_loothprint_layout() / ::default_loothcuts_layout().
 *
 * The env / $_SERVER override (LG_V2_LOOTHPRINT_GATING) is how a lane preview or
 * a gate run exercises the ON path without editing tracked config. Both are read
 * because a fastcgi_param lands in $_SERVER but not reliably in getenv().
 */

return array(

	/** OFF until Ian has looked at the running thing. */
	'enabled' => false,
);
