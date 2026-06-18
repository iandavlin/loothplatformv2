<?php
/**
 * POST /push/subscribe  — reconciled 2026-06-06 (buck).
 *
 * Two concurrent buck sessions shipped a subscribe sink. Consolidated onto the
 * single canonical handler at /push-subscribe.php (the path the live push.js
 * client POSTs to and which was verified end-to-end). That handler is the richer
 * one: it records user_uuid (profile-app linkage for the sender's targeting) and
 * uses a race-safe INSERT ... ON DUPLICATE KEY UPDATE.
 *
 * This documented /push/subscribe path is kept working by delegating to it, so
 * there is ONE code path and either URL behaves identically. The b64url key
 * validation from this file's original version was folded into the canonical
 * handler.
 */
require __DIR__ . '/../push-subscribe.php';
