<?php
/**
 * profile-app — sponsor brand-store migration (sponsor-pages v2, Lane A).
 *
 * Lifts the 5 real sponsors' `brand_*` user-meta (ACF group
 * "Sponsor Brand Information" #33147) out of WP and into profile-app's
 * Postgres `sponsor` table. Attachment IDs (logo, hero, gallery) are RESOLVED
 * to absolute URLs at migration time and stored as URLs — so profile-app never
 * has to reach into WP media at read time (same discipline as backfill-avatars).
 *
 * Resolution mirrors wp_get_attachment_url(): uploads baseurl + '/' +
 * _wp_attached_file. (The post guid is stale on collided uploads — do NOT use
 * it.) Talks to MySQL directly via PDO like the other bin/ migrations; no WP
 * bootstrap.
 *
 * Idempotent + re-runnable: upserts on slug, so a re-run refreshes every column
 * (re-resolves URLs too — useful if media moves). Safe to run repeatedly.
 *
 * Usage:
 *   sudo -u profile-app php bin/migrate-sponsors.php            # dry-run
 *   sudo -u profile-app php bin/migrate-sponsors.php --commit
 *
 * The set (drop The Guitar Specialist #33492 — a tester). slug is canonical,
 * NOT derived, so it stays stable regardless of brand_name churn.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

use Looth\ProfileApp\Db;

$COMMIT = in_array('--commit', $argv, true);
echo $COMMIT ? "*** COMMIT MODE — mutations will be written ***\n"
             : "(dry-run; pass --commit to write)\n";

/** wp_user_id => canonical slug. Authoritative set; tester #33492 excluded. */
const SPONSORS = [
    739  => 'total-vise',
    808  => 'gluboost',
    476  => 'strings-micro-factory',
    1503 => 'go-acoustic-audio',
    733  => 'stewmac',
];

$mysqlSocket = '/var/run/mysqld/mysqld.sock';
$mysqlUser   = posix_getpwuid(posix_geteuid())['name'] ?? 'profile-app';
$wp = new PDO('mysql:unix_socket=' . $mysqlSocket . ';dbname=' . LG_PROFILE_APP_MYSQL_DB,
              $mysqlUser, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pg = Db::pg();

$UPLOADS_BASE = 'https://' . LG_PROFILE_APP_HOST . '/wp-content/uploads';

// ── helpers ───────────────────────────────────────────────────────
$metaStmt = $wp->prepare(
    'SELECT meta_key, meta_value FROM wp_usermeta WHERE user_id = :u'
);
$attStmt = $wp->prepare(
    "SELECT meta_value FROM wp_postmeta WHERE post_id = :p AND meta_key = '_wp_attached_file' LIMIT 1"
);

/** Resolve a single attachment id to an absolute URL, or null. */
$resolveAtt = function (?string $id) use ($attStmt, $UPLOADS_BASE): ?string {
    $id = trim((string)$id);
    if ($id === '' || !ctype_digit($id)) return null;
    $attStmt->execute([':p' => (int)$id]);
    $file = $attStmt->fetchColumn();
    if (!$file) return null;
    return $UPLOADS_BASE . '/' . ltrim((string)$file, '/');
};

/** trim() a meta value to null when empty. */
$nz = function ($v): ?string {
    if ($v === null) return null;
    $v = trim((string)$v);
    return $v === '' ? null : $v;
};

$upsert = $pg->prepare(<<<SQL
    INSERT INTO sponsor (
        slug, wp_user_id, email, name, display_name,
        logo_url, hero_url, hero_caption, hero_title, hero_youtube,
        about, website, color_primary, color_secondary, color_header,
        social_facebook, social_instagram, social_youtube,
        gallery_urls, tag_url, forum_url
    ) VALUES (
        :slug, :wp_user_id, :email, :name, :display_name,
        :logo_url, :hero_url, :hero_caption, :hero_title, :hero_youtube,
        :about, :website, :color_primary, :color_secondary, :color_header,
        :social_facebook, :social_instagram, :social_youtube,
        :gallery_urls, :tag_url, :forum_url
    )
    ON CONFLICT (slug) DO UPDATE SET
        wp_user_id       = EXCLUDED.wp_user_id,
        email            = EXCLUDED.email,
        name             = EXCLUDED.name,
        display_name     = EXCLUDED.display_name,
        logo_url         = EXCLUDED.logo_url,
        hero_url         = EXCLUDED.hero_url,
        hero_caption     = EXCLUDED.hero_caption,
        hero_title       = EXCLUDED.hero_title,
        hero_youtube     = EXCLUDED.hero_youtube,
        about            = EXCLUDED.about,
        website          = EXCLUDED.website,
        color_primary    = EXCLUDED.color_primary,
        color_secondary  = EXCLUDED.color_secondary,
        color_header     = EXCLUDED.color_header,
        social_facebook  = EXCLUDED.social_facebook,
        social_instagram = EXCLUDED.social_instagram,
        social_youtube   = EXCLUDED.social_youtube,
        gallery_urls     = EXCLUDED.gallery_urls,
        tag_url          = EXCLUDED.tag_url,
        forum_url        = EXCLUDED.forum_url
SQL);

foreach (SPONSORS as $wpId => $slug) {
    // Pull all of this user's meta into a flat key=>value map (last wins).
    $metaStmt->execute([':u' => $wpId]);
    $m = [];
    foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $m[$row['meta_key']] = $row['meta_value'];
    }

    // Resolve the gallery (ACF stores a serialized array of attachment ids).
    $gallery = [];
    if (!empty($m['brand_image_gallery_'])) {
        $arr = @unserialize($m['brand_image_gallery_'], ['allowed_classes' => false]);
        if (is_array($arr)) {
            foreach ($arr as $attId) {
                $url = $resolveAtt((string)$attId);
                if ($url !== null) $gallery[] = $url;
            }
        }
    }

    $rec = [
        ':slug'             => $slug,
        ':wp_user_id'       => $wpId,
        ':email'            => $nz($m['brand_email'] ?? null),
        ':name'             => $nz($m['brand_name'] ?? null),
        ':display_name'     => $nz($m['brand_name_'] ?? null),
        ':logo_url'         => $resolveAtt($m['brand_logo'] ?? null),
        ':hero_url'         => $resolveAtt($m['brand_hero_image'] ?? null),
        ':hero_caption'     => $nz($m['brand_hero_image_caption'] ?? null),
        ':hero_title'       => $nz($m['brand_hero_image_title_'] ?? null),
        ':hero_youtube'     => $nz($m['brand_hero_youtube_link_'] ?? null),
        ':about'            => $nz($m['brand_about_'] ?? null),
        ':website'          => $nz($m['brand_website'] ?? null),
        ':color_primary'    => $nz($m['brand_primary_color'] ?? null),
        ':color_secondary'  => $nz($m['brand_secondary_color_'] ?? null),
        ':color_header'     => $nz($m['brand_third_color_header_color'] ?? null),
        ':social_facebook'  => $nz($m['brand_facebook'] ?? null),
        ':social_instagram' => $nz($m['brand_instagram'] ?? null),
        ':social_youtube'   => $nz($m['brand_youtube'] ?? null),
        ':gallery_urls'     => json_encode($gallery, JSON_UNESCAPED_SLASHES),
        ':tag_url'          => $nz($m['brand_tag'] ?? null),
        // forum_url preferred; fall back to the bbp shortcode if the url is empty.
        ':forum_url'        => $nz($m['sponsor_forum_url'] ?? null)
                                 ?? $nz($m['Sponsor_Forum_Shortcode'] ?? null),
    ];

    printf(
        "%-22s wp=%-5d email=%-26s logo=%s hero=%s gallery=%d\n",
        $slug, $wpId,
        $rec[':email'] ?? '—',
        $rec[':logo_url'] ? 'Y' : '—',
        $rec[':hero_url'] ? 'Y' : '—',
        count($gallery)
    );

    if ($COMMIT) $upsert->execute($rec);
}

echo $COMMIT ? "done.\n" : "(dry-run complete — pass --commit to write)\n";
