<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_render.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Profile;

$viewer = Auth::currentUser();

if (!$viewer) {
    // If the user has a WP session cookie but no looth_id yet (the
    // direct-link / bookmark / email-link case), bounce through the WP
    // mu-plugin's issue endpoint to mint and 302 right back. Invisible hop.
    $hasWpSession = false;
    foreach ($_COOKIE as $name => $_) {
        if (strpos($name, 'wordpress_logged_in_') === 0) { $hasWpSession = true; break; }
    }
    if ($hasWpSession) {
        $return = $_SERVER['REQUEST_URI'] ?? '/profile/edit';
        // Non-REST mint endpoint (wp-auth lane, 7821c3e) — see config.php note.
        header('Location: /looth-auth/issue?return=' . urlencode($return));
        exit;
    }
    looth_render_login_interstitial('/profile/edit');
    exit;
}

// No one should ever sit on the slug-less /profile/edit fallback. The instant we
// know the viewer's slug — read straight from Postgres, no cookie/cache/JWT-claim
// dependency — send them to their canonical /u/<slug> (which IS the inline editor
// for the owner). This makes the menu-link slug-claim timing irrelevant: even a
// stale "/profile/edit" link lands the member on their real profile. (Ian 6/16.)
$slug = (string) ($viewer['slug'] ?? '');
if ($slug === '') {
    try {
        $st = \Looth\ProfileApp\Db::pg()->prepare('SELECT slug FROM users WHERE id = :i');
        $st->execute([':i' => (int) $viewer['id']]);
        $slug = trim((string) $st->fetchColumn());
    } catch (\Throwable $e) {
        $slug = '';
    }
}
if ($slug !== '') {
    header('Location: /u/' . rawurlencode($slug), true, 302);
    exit;
}

if (!Profile::hasClaimed((int)$viewer['id'])) {
    looth_render_claim_interstitial($viewer);
    exit;
}

$full = Profile::loadFull((int)$viewer['id']);
$role = 'me';
looth_render_editor($full, 'editor', $role);
