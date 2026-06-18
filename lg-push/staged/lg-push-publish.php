<?php
/**
 * STAGED Looth push publish trigger — Trigger A.
 *
 * ★ DO NOT place in wp-content/mu-plugins/ until go-live (coordinator/Ian step).
 *   Staged here at /srv/lg-push/staged/ so nothing auto-activates.
 *
 * On a fresh publish of a Hub content CPT it ENQUEUES a push into wp_lg_push_queue;
 * the root-cron drainer (/srv/lg-push/run-queue.php) reads the queue and sends, so
 * this hook (running as www-data inside WP) never touches the VAPID private key.
 * Debounced so a bulk import / re-publish of old posts does not fan out a per-item
 * notification.
 *
 * GO-LIVE: copy to /var/www/dev/wp-content/mu-plugins/lg-push-publish.php and
 * confirm the $cpts list matches the real Hub content CPT slugs.
 */

if (!defined('ABSPATH')) return;

add_action('transition_post_status', function ($new, $old, $post) {
    if ($new !== 'publish' || $old === 'publish') return;          // only fresh publishes

    // TODO(go-live): confirm these against the real Hub content CPT registrations.
    $cpts = ['post-imgcap', 'post-type-videos', 'sponsor-post', 'event'];
    if (!in_array($post->post_type, $cpts, true)) return;

    // Debounce back-dated bulk imports: only notify for genuinely-new posts.
    $created = strtotime(($post->post_date_gmt ?: $post->post_date) . ' UTC');
    if ($created && (time() - $created) > 3600) return;

    global $wpdb;
    $url = parse_url((string) get_permalink($post), PHP_URL_PATH) ?: '/hub/';
    $payload = wp_json_encode([
        'title' => 'New on Looth',
        'body'  => get_the_title($post),
        'url'   => $url,
        'icon'  => '/icons/icon-192.png',
        'tag'   => 'content-' . $post->ID,
    ]);

    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->prefix}lg_push_queue
            (payload, target_type, target_id, status, attempts, created_at)
         VALUES (%s, 'all', NULL, 'pending', 0, %s)",
        $payload,
        gmdate('Y-m-d H:i:s')
    ));
}, 10, 3);
