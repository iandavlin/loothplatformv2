<?php
/**
 * Plugin Name: LG Snippets
 * Description: code-snippets connected to the strangler work (anon posting, author links, login skin, Patreon tier, moderation), folded out of the DB so they version + deploy with git. Replaces the matching wp_snippets entries.
 * Version: 0.1.0
 * Author: Looth Group
 */
if (!defined('ABSPATH')) exit;
foreach (glob(__DIR__ . '/snippets/*.php') as $__lgs) require_once $__lgs;
