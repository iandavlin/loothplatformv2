<?php
/**
 * author-archive-icon — backlog 27. Ian looked at the mock (a 7th icon in
 * the profile's existing social-icon palette, styled like its neighbours,
 * linking to the Hub's by-author filtered view) and answered "Icon good,"
 * plus ONE refinement (Ian 8/16): "can we make the icon vis 0 if no
 * authorship of either discussions or cpts" — a zero-authorship profile
 * shows no icon at all.
 *
 * Consumed by `profile-app/web/_render_blocks.php`. Visibility is decided by
 * bb-mirror/api/v0/author-activity.php, a loopback call that runs the ONE
 * shared predicate (hub_author_activity_count, bb-mirror/web/forums/
 * _hub-filters.php) the Hub's own author banner already uses — never a
 * second, independently-written count. Keeper 2026-08-16: "whatever query
 * the hub author-filter would run to fill that member's archive is the
 * thing that decides the icon, never a parallel count" — the failure mode
 * that predicate-sharing kills is an icon leading to an empty archive, or a
 * hidden icon on a member who does have posts, because two counting paths
 * quietly disagreed.
 *
 * OFF (default): _render_blocks.php never calls the loopback endpoint and
 * never emits the icon — the header links rail renders exactly as it did
 * before this file existed, byte-identical, gated by the same
 * getenv()/$_SERVER override pair every flag in this codebase uses (a lane
 * preview sets fastcgi_param, which lands in $_SERVER but not reliably in
 * getenv()).
 */
return ['enabled' => true];
