<?php
/**
 * flag-boot — force a feature flag to a chosen state for one WP-CLI run.
 *
 * Loaded via `wp --require=`, which runs BEFORE WordPress boots. Both mu-plugins guard
 * their own define with `if ( ! defined( ... ) )`, so a constant defined here WINS.
 *
 * ── WHY THIS EXISTS, AND WHY THE GATES BROKE WITHOUT IT ──────────────────────
 * The probes used to `require_once` the mu-plugin under test and define its constant
 * themselves. That works exactly until the feature is DEPLOYED — at which point WordPress
 * has already loaded the file from the serving checkout and the probe dies with
 * "Cannot redeclare lg_pfs_target()". Both gates reported NO VERDICT the moment their own
 * code shipped, which is the worst possible time for a gate to stop working.
 *
 * So flag state now comes from here (pre-boot, always available) instead of from the
 * probe's own require (only available pre-deploy). The probes require the mu-plugin ONLY
 * as a fallback for a box that has not pulled it yet.
 *
 * Values come from the environment because `wp --require` takes no arguments:
 *   LG_BOOT_PFS=0|1  → LG_PRESERVE_FORUM_SUBSCRIPTION   (the P0 repair)
 *   LG_BOOT_PFC=0|1  → LG_POST_FOLLOW_CONTROLS          (ruling 6's controls)
 * Unset means "leave it alone" — the box's own configured value stands.
 */

if ( '' !== (string) getenv( 'LG_BOOT_PFS' ) ) {
	define( 'LG_PRESERVE_FORUM_SUBSCRIPTION', '1' === getenv( 'LG_BOOT_PFS' ) );
}
if ( '' !== (string) getenv( 'LG_BOOT_PFC' ) ) {
	define( 'LG_POST_FOLLOW_CONTROLS', '1' === getenv( 'LG_BOOT_PFC' ) );
}
