<?php
declare(strict_types=1);
/**
 * Backlog 38 — the Advanced Search modal's in-place author-filter apply
 * (bb-mirror/web/forums.js fmodalApply) swaps the feed cards, the modal body
 * and the chip bar back into the live DOM on a pick, but never the green
 * author banner (.hub-author-hdr) — it sits OUTSIDE all three (server order:
 * sponsor rail, banner, #hub-feed-results). Picking an author from the
 * dropdown while the modal stays open leaves the banner exactly as it was:
 * absent if none was showing, stale if a different author's was already up.
 * A hard navigation to the same URL always renders it correctly — reproduced
 * on dev2 via real clicks through the actual suggest dropdown for BOTH
 * Patrick Niedermeyer and Rick Liftig identically, so this is a client-side
 * DOM-swap omission, not the name-fragility Ian's report first suggested.
 *
 * ON: _feed.php wraps the author-header loop in <div id="hub-author-headers">
 * so it is one addressable unit, and _chrome.php emits
 * window.LG_HUB_AUTHOR_BANNER_SWAP = true so fmodalApply extends its existing
 * old/new swap (the same pattern already used for .hub-chipbar) to cover it.
 *
 * OFF (default): neither line runs — the wrapper div is never added (gate
 * proves the served markup byte-identical to before this file existed) and
 * fmodalApply's swap list is unchanged, so the bug ships exactly as found
 * until Ian has looked at the fix on the real dev2 serve.
 */
return ['enabled' => true];
