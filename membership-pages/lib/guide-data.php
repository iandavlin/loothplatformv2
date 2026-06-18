<?php
/**
 * /membership-guide/ data layer — reads wp_options directly (read-only).
 *
 * The WP plugin (lg-patreon-stripe-poller) stores all dynamic guide content
 * in wp_options under `lgms_guide_*` keys, written by an admin UI in
 * Settings → Membership Guide. We read those values via PDO instead of
 * booting WP so the page render doesn't pay the ~2.6s WP-boot tax.
 *
 * Options keys (canonical list, mirror of MembershipGuide.php OPT_* consts):
 *   lgms_guide_preview_cards     serialized array of preview card configs
 *   lgms_guide_starter_cards     serialized array of starter card configs
 *   lgms_guide_elders            serialized list of elder records
 *   lgms_guide_loothalong_url    string
 *   lgms_guide_feed_video_url    string
 *   lgms_guide_feed_poster_id    int (WP attachment ID)
 *   lgms_guide_archive_demo_url  string
 *   lgms_guide_forums_demo_url   string
 *   lgms_guide_forums_image_url  string
 *   lgms_guide_screenshots       serialized array
 *   lgms_guide_recurring_shows   serialized array
 *
 * IMPORTANT: WP stores these as PHP-serialized strings (via update_option).
 * We unserialize() them; the schema is dictated by the admin UI in
 * MembershipGuide.php — DO NOT depend on internal structure beyond the
 * top-level keys we explicitly use here. If the admin UI rewrites the
 * shape, this loader needs an update (or, ideally, the plugin learns to
 * also write a JSON sidecar). Track in SESSION-HANDOFF.
 */

declare(strict_types=1);

if (!function_exists('lg_membership_guide_load_options')) {
/**
 * Pull the lgms_guide_* options in one prepared statement. Caches in static
 * for the request lifetime. PHP-unserialize-aware (WP option storage format).
 *
 * @return array<string, mixed> keys = the OPT_* names (sans `lgms_guide_` prefix)
 */
function lg_membership_guide_load_options(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $keys = [
        'lgms_guide_preview_cards',
        'lgms_guide_starter_cards',
        'lgms_guide_elders',
        'lgms_guide_loothalong_url',
        'lgms_guide_feed_video_url',
        'lgms_guide_feed_poster_id',
        'lgms_guide_archive_demo_url',
        'lgms_guide_forums_demo_url',
        'lgms_guide_forums_image_url',
        'lgms_guide_screenshots',
        'lgms_guide_recurring_shows',
    ];

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $sql = "SELECT option_name, option_value
            FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "options
            WHERE option_name IN ($placeholders)";

    try {
        $stmt = lg_membership_db()->prepare($sql);
        $stmt->execute($keys);
    } catch (Throwable $e) {
        // Don't crash the page render on a DB blip — return empties.
        // The page will show its default content; the chrome still renders.
        return $cache = [];
    }

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $name = (string)$row['option_name'];
        $val  = (string)$row['option_value'];
        // wp_options stores serialized arrays. unserialize will return the
        // original scalar if val is a plain string. Suppress notices on
        // malformed data (returns false → we skip).
        $maybe = @unserialize($val, ['allowed_classes' => false]);
        if ($maybe === false && $val !== 'b:0;') {
            $maybe = $val;  // scalar string — use as-is
        }
        $short = preg_replace('/^lgms_guide_/', '', $name);
        $out[$short] = $maybe;
    }
    return $cache = $out;
}
}

if (!function_exists('lg_membership_guide_resolve_attachment_url')) {
/**
 * Resolve a WP attachment ID to its source URL by reading wp_postmeta
 * (_wp_attached_file). Falls back to a constructed uploads URL.
 * Returns '' if the ID resolves to nothing.
 */
function lg_membership_guide_resolve_attachment_url(int $attachment_id): string {
    if ($attachment_id <= 0) return '';

    try {
        $stmt = lg_membership_db()->prepare(
            "SELECT meta_value FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "postmeta
             WHERE post_id = ? AND meta_key = '_wp_attached_file' LIMIT 1"
        );
        $stmt->execute([$attachment_id]);
        $rel = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
    return $rel !== '' ? LG_MEMBERSHIP_UPLOADS_BASE . $rel : '';
}
}
