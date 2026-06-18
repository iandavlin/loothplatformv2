<?php
/**
 * Plugin Name: Looth Feed Sidebar Tweaks
 * Description: Makes the /feed/ sidebars scroll independently of the main column
 *              on desktop, and stack normally on mobile. Pairs with the trimmed
 *              widget config (Who's Online / Roadman / Recent-* removed).
 * Version:     1.0.0
 */
if (!defined('ABSPATH')) exit;

add_action('wp_head', function () {
    // Only apply on BB activity-feed pages — don't pollute the rest of the site.
    if (!function_exists('bp_is_activity_directory') || !bp_is_activity_directory()) return;
    ?>
    <style id="lg-feed-sidebar-tweaks">
      /* Each sidebar column scrolls independently from main content.
         BB readylaunch uses .bb-rl-activity-sidebar wrappers; falls back to
         BB's classic .bb-secondary-sidebar / .bb-tertiary-sidebar selectors. */
      @media (min-width: 992px) {
        .bb-rl-activity-feed-sidebar,
        .activity-secondary-sidebar,
        .activity-tertiary-sidebar,
        .bb-secondary-sidebar,
        .bb-tertiary-sidebar {
          position: sticky;
          top: 80px;                              /* clear of the fixed header */
          max-height: calc(100vh - 100px);
          overflow-y: auto;
          overflow-x: hidden;
          padding-right: 6px;                     /* room for scrollbar */
          scrollbar-width: thin;                  /* Firefox */
          scrollbar-color: #c8c2b4 transparent;
        }
        .bb-rl-activity-feed-sidebar::-webkit-scrollbar,
        .activity-secondary-sidebar::-webkit-scrollbar,
        .activity-tertiary-sidebar::-webkit-scrollbar,
        .bb-secondary-sidebar::-webkit-scrollbar,
        .bb-tertiary-sidebar::-webkit-scrollbar {
          width: 6px;
        }
        .bb-rl-activity-feed-sidebar::-webkit-scrollbar-thumb,
        .activity-secondary-sidebar::-webkit-scrollbar-thumb,
        .activity-tertiary-sidebar::-webkit-scrollbar-thumb,
        .bb-secondary-sidebar::-webkit-scrollbar-thumb,
        .bb-tertiary-sidebar::-webkit-scrollbar-thumb {
          background: #c8c2b4; border-radius: 3px;
        }
      }
      /* Mobile: undo sticky, let everything stack normally */
      @media (max-width: 991px) {
        .bb-rl-activity-feed-sidebar,
        .activity-secondary-sidebar,
        .activity-tertiary-sidebar,
        .bb-secondary-sidebar,
        .bb-tertiary-sidebar {
          position: static;
          max-height: none;
          overflow: visible;
        }
      }
    </style>
    <?php
}, 99);
