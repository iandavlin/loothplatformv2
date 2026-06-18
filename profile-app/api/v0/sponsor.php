<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Db;

/**
 * Sponsor brand record — PUBLIC read, no auth (sponsor pages are public).
 *
 *   GET /profile-api/v0/sponsor/<slug>   → by slug   (rewrite → ?slug=)
 *   GET /profile-api/v0/sponsor?wp_id=NN → by content-link wp_user_id
 *   GET /profile-api/v0/sponsor?email=.. → by poller bridge email
 *
 * Consumed by the Lane-B v2 blocks (brand-hero, gallery, contact-form, …) and
 * the brand-color theming vars. slug wins, then wp_id, then email.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    profile_app_json(405, ['error' => 'method_not_allowed']);
}

$slug  = $_GET['slug']  ?? '';
$wpId  = $_GET['wp_id'] ?? '';
$email = $_GET['email'] ?? '';

if (is_string($slug) && $slug !== '') {
    if (!preg_match('/^[a-z0-9-]{1,64}$/', $slug)) {
        profile_app_json(400, ['error' => 'invalid_slug']);
    }
    $st = Db::pg()->prepare('SELECT * FROM sponsor WHERE slug = :v');
    $st->execute([':v' => $slug]);
} elseif (is_string($wpId) && $wpId !== '') {
    if (!ctype_digit($wpId)) profile_app_json(400, ['error' => 'invalid_wp_id']);
    $st = Db::pg()->prepare('SELECT * FROM sponsor WHERE wp_user_id = :v');
    $st->execute([':v' => (int)$wpId]);
} elseif (is_string($email) && $email !== '') {
    $st = Db::pg()->prepare('SELECT * FROM sponsor WHERE lower(email) = lower(:v)');
    $st->execute([':v' => $email]);
} else {
    profile_app_json(400, ['error' => 'slug_wp_id_or_email_required']);
}

$row = $st->fetch();
if (!$row) profile_app_json(404, ['error' => 'not_found']);

profile_app_json(200, [
    'slug'         => $row['slug'],
    'wp_user_id'   => (int) $row['wp_user_id'],
    'email'        => $row['email'],
    'name'         => $row['name'],
    'display_name' => $row['display_name'],
    'logo_url'     => $row['logo_url'],
    'hero'         => [
        'url'     => $row['hero_url'],
        'caption' => $row['hero_caption'],
        'title'   => $row['hero_title'],
        'youtube' => $row['hero_youtube'],
    ],
    'about'        => $row['about'],
    'website'      => $row['website'],
    'colors'       => [
        'primary'   => $row['color_primary'],
        'secondary' => $row['color_secondary'],
        'header'    => $row['color_header'],
    ],
    'social'       => [
        'facebook'  => $row['social_facebook'],
        'instagram' => $row['social_instagram'],
        'youtube'   => $row['social_youtube'],
    ],
    'gallery_urls' => json_decode($row['gallery_urls'] ?? '[]', true) ?: [],
    'tag_url'      => $row['tag_url'],
    'forum_url'    => $row['forum_url'],
]);
