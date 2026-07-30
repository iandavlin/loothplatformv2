<?php
/**
 * Plugin Name: LG Author Socials
 * Description: Byline social links read the member's CURRENT profile (profile-app
 *              `profile_socials`) instead of the frozen WP ACF `author_*` usermeta
 *              mirror. Fixes the drift class where a link a member deleted kept
 *              rendering on their posts and events.
 * Version:     0.1.0
 *
 * ## The bug this closes (P0, member-reported 2026-07-01)
 *
 * `lg-layout-v2` post-header / post-footer built the author link rail from ACF
 * `author_*` usermeta. That read is LIVE — the values were simply stale, because
 * members edit their links in profile-app, whose store was seeded from ACF once on
 * 2026-05-29 and never synced again. A Linktree one member deleted rendered on his
 * events for two months, and his Facebook link disagreed with his profile.
 * Full evidence: docs/SOCIAL-LINKS-DRIFT-AUDIT.md.
 *
 * We resolve rather than mirror. A mirror needs every writer to remember to sync;
 * this needs nothing to be remembered, which is the whole point — the mirror is
 * what broke.
 *
 * ## Exposure gate — LG_AUTHOR_SOCIALS_ALL_MEMBERS
 *
 * ON, by Ian's call 2026-07-30: "Flag on. No one is going to want to be a private
 * author." The byline is a PUBLISHING surface — links an author attaches to work
 * they chose to publish — and the members-only default those handles sit behind is
 * an unset default, not a member decision.
 *
 * The scope is smaller than "153 members with socials" suggests. 93 members have
 * ever authored anything carrying a byline; 24 of them already publish socials. So
 * ON newly affects ~69 AUTHORS. The other ~60 have profile socials but have never
 * published, so the flag does nothing for them until they do.
 *
 * OFF was not the conservative option it looked like. Eligibility under OFF needs
 * `author_*` ACF meta, which is settable only through the legacy in-post "Manage
 * social icons" modal — the very mechanism that caused this bug. OFF therefore
 * freezes the feature at 24 people forever and hands every FUTURE author a
 * permanently dead byline.
 *
 * Block-level visibility is not consulted: applying a members-only default would
 * blank the rail for every logged-out reader. See the audit doc §Visibility.
 */

if (!defined('ABSPATH')) exit;

/** Resolve for every member, not just those carrying legacy ACF meta (Ian 2026-07-30). */
if (!defined('LG_AUTHOR_SOCIALS_ALL_MEMBERS')) {
    define('LG_AUTHOR_SOCIALS_ALL_MEMBERS', true);
}

/** ACF slots that make an author "already publishing socials on the byline". */
const LG_AUTHOR_SOCIALS_LEGACY_KEYS = [
    'author_website', 'author_instagram', 'author_facebook',
    'author_youtube', 'author_linktree',
];

/**
 * profile_socials kind => the post-header/post-footer slot key it renders as.
 * Kinds absent here still render, under their own key, via the generic icon.
 */
const LG_AUTHOR_SOCIALS_KIND_SLOT = [
    'web'       => 'website',
    'instagram' => 'instagram',
    'facebook'  => 'facebook',
    'youtube'   => 'youtube',
    'linktree'  => 'linktree',
];

/** Human label per kind, for the link's title/aria text. */
const LG_AUTHOR_SOCIALS_LABEL = [
    'web' => 'Website', 'instagram' => 'Instagram', 'facebook' => 'Facebook',
    'youtube' => 'YouTube', 'linktree' => 'Linktree', 'x' => 'X',
    'tiktok' => 'TikTok', 'patreon' => 'Patreon', 'bandcamp' => 'Bandcamp',
];

if (!function_exists('lg_author_socials_is_eligible')) {
/**
 * Should this author's byline resolve from the profile store?
 *
 * Filter `lg_author_socials_eligible` overrides per author — the single seam for
 * a staged rollout (e.g. allow-list a few members before flipping the constant).
 */
function lg_author_socials_is_eligible(int $user_id): bool {
    $eligible = (bool) LG_AUTHOR_SOCIALS_ALL_MEMBERS;
    if (!$eligible) {
        foreach (LG_AUTHOR_SOCIALS_LEGACY_KEYS as $k) {
            if (trim((string) get_user_meta($user_id, $k, true)) !== '') { $eligible = true; break; }
        }
    }
    return (bool) apply_filters('lg_author_socials_eligible', $eligible, $user_id);
}
}

if (!function_exists('lg_author_socials_fetch')) {
/**
 * Ask profile-app for this author's current links.
 *
 * Returns ['authoritative' => bool, 'links' => [['kind','url'],...]] or null when
 * the lookup could not be performed at all (no secret, transport error, non-200).
 * A null is NOT "no links" — callers must fall back to the legacy ACF rail rather
 * than blank a byline because a loopback call failed.
 *
 * Cached in the object cache for 5 minutes, and memoised per request: a single
 * post renders the same author in both the header and the footer.
 */
function lg_author_socials_fetch(int $user_id): ?array {
    static $memo = [];
    if (array_key_exists($user_id, $memo)) return $memo[$user_id];

    $ck     = 'lg_author_socials_' . $user_id;
    $cached = wp_cache_get($ck, 'lg_author_socials');
    if (is_array($cached)) return $memo[$user_id] = $cached;

    $secret = '';
    if (is_readable('/etc/lg-internal-secret')) {
        $secret = trim((string) file_get_contents('/etc/lg-internal-secret'));
    }
    if ($secret === '') return $memo[$user_id] = null;

    $u = get_userdata($user_id);
    if (!$u) return $memo[$user_id] = null;

    $res = wp_remote_post('https://127.0.0.1/profile-api/v0/internal/byline-socials', [
        'method'    => 'POST',
        'timeout'   => 2,          // a byline must never hold a page open
        'blocking'  => true,
        'sslverify' => false,
        'headers'   => [
            'Host'                => $_SERVER['HTTP_HOST'] ?? 'dev.loothgroup.com',
            'Content-Type'        => 'application/json',
            'X-LG-Internal-Auth'  => $secret,
        ],
        'body' => wp_json_encode(['lookups' => [[
            'key'   => (string) $user_id,
            'uuid'  => (string) get_user_meta($user_id, '_looth_uuid', true),
            'email' => (string) $u->user_email,
        ]]]),
    ]);

    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        // Cache the failure briefly so a profile-app outage can't turn every post
        // render into a 2s loopback timeout.
        wp_cache_set($ck, ['authoritative' => false, 'links' => [], '_failed' => true],
                     'lg_author_socials', 60);
        return $memo[$user_id] = null;
    }

    $body = json_decode((string) wp_remote_retrieve_body($res), true);
    $row  = $body['results'][(string) $user_id] ?? null;
    if (!is_array($row)) return $memo[$user_id] = null;

    $out = [
        'authoritative' => !empty($row['authoritative']),
        'links'         => is_array($row['links'] ?? null) ? $row['links'] : [],
    ];
    wp_cache_set($ck, $out, 'lg_author_socials', 300);
    return $memo[$user_id] = $out;
}
}

if (!function_exists('lg_author_socials_generic_svg')) {
/** Fallback glyph for kinds the byline blocks carry no icon for. */
function lg_author_socials_generic_svg(): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
         . ' stroke-linecap="round" stroke-linejoin="round">'
         . '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/>'
         . '<path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>';
}
}

/**
 * Replace the ACF-built rail with profile-store truth.
 *
 * Runs on the filter both byline blocks expose AFTER building their link list, so
 * the computed slots the blocks add themselves (member profile, "all posts by")
 * are preserved — we only swap the social slots. `$slots` carries the block's own
 * icon/title definitions so a resolved link keeps the block's artwork.
 */
add_filter('lg_layout_v2_author_links', function (array $links, $author_id, array $slots, array $hidden) {
    $author_id = (int) $author_id;
    if ($author_id < 1) return $links;
    if (!lg_author_socials_is_eligible($author_id)) return $links;

    $resolved = lg_author_socials_fetch($author_id);
    // Lookup failed, or the member has never curated the profile store: keep the
    // legacy rail. Only an authoritative answer may retire a link.
    if ($resolved === null || !$resolved['authoritative']) return $links;

    $social_slots = array_keys(LG_AUTHOR_SOCIALS_KIND_SLOT);
    $social_keys  = array_values(LG_AUTHOR_SOCIALS_KIND_SLOT);

    // Drop every ACF-sourced social link; keep computed ones (bp_profile,
    // author_archive, looth_group_profile — not member-editable social links).
    $kept = array_values(array_filter($links, static function ($l) use ($social_keys) {
        return !in_array((string) ($l['key'] ?? ''), $social_keys, true);
    }));

    /* profile_socials has NO unique constraint on (user_id, kind) — a member may
       legitimately list two websites, and 7 members on live do. The ACF model this
       replaces had one slot per kind, so a naive map collapses them to two icons
       with the identical title "Website". Keep both links (dropping a member's
       second site would be silent data loss) and disambiguate the title by host. */
    $kind_counts = [];
    foreach ($resolved['links'] as $l) {
        $k = (string) ($l['kind'] ?? '');
        if ($k !== '') $kind_counts[$k] = ($kind_counts[$k] ?? 0) + 1;
    }

    $fresh = [];
    $seen  = [];
    foreach ($resolved['links'] as $l) {
        $kind = (string) ($l['kind'] ?? '');
        $url  = trim((string) ($l['url'] ?? ''));
        if ($kind === '' || $url === '') continue;
        $slot_key = LG_AUTHOR_SOCIALS_KIND_SLOT[$kind] ?? $kind;
        if (in_array($slot_key, $hidden, true)) continue;   // per-post hide still wins
        if (isset($seen[$url])) continue;                   // exact dupe rows add nothing
        $seen[$url] = true;

        $slot  = $slots[$slot_key] ?? null;
        $title = (string) ($slot['title'] ?? (LG_AUTHOR_SOCIALS_LABEL[$kind] ?? ucfirst($kind)));
        if (($kind_counts[$kind] ?? 0) > 1) {
            $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
            $host = preg_replace('/^www\./i', '', $host);
            if ($host !== '') $title .= ' — ' . $host;
        }

        $fresh[] = [
            'key'   => $slot_key,
            'url'   => $url,
            'title' => $title,
            'svg'   => (string) ($slot['svg'] ?? lg_author_socials_generic_svg()),
        ];
    }

    // Resolved socials lead, in the member's own profile order; computed slots trail.
    return array_merge($fresh, $kept);
}, 10, 4);

/**
 * The in-post "Manage social icons" modal writes ACF usermeta
 * (lg-layout-v2/src/EditorRest.php). For a resolved author that write no longer
 * reaches the byline, so drop our cache and let the next render re-read the
 * profile store rather than show an edit that silently did nothing.
 */
add_action('updated_user_meta', function ($meta_id, $user_id, $meta_key) {
    if (in_array((string) $meta_key, LG_AUTHOR_SOCIALS_LEGACY_KEYS, true)) {
        wp_cache_delete('lg_author_socials_' . (int) $user_id, 'lg_author_socials');
    }
}, 10, 3);
