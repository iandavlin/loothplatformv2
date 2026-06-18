# BATCH-04 Results — 2026-05-28

Raw paste-back from live (54.157.13.77). Run by Ian.

---

## #37 — mu-plugins/looth-roles.php

```php
<?php
/**
 * Plugin Name: Looth Roles
 * Description: Registers looth1-looth4 as a must-use plugin so they survive any other plugin being deactivated.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
    $roles = [
        'looth1' => 'Looth 1',
        'looth2' => 'Looth 2',
        'looth3' => 'Looth 3',
        'looth4' => 'Looth 4',
    ];
    foreach ( $roles as $slug => $name ) {
        if ( ! get_role( $slug ) ) {
            add_role( $slug, $name, [ 'read' => true ] );
        }
    }
}, 1 );
```

---

## #38 — Code-snippet #44 "Patreon Tier Toggler"

```php
/**
 * ───────────────────────── CONFIG ─────────────────────────
 * Supports multiple post types using the same access logic.
 */

// Array of all CPTs using Patreon-tier gating
if ( ! defined( 'LOOTH_CPT_LIST' ) ) {
    define( 'LOOTH_CPT_LIST', [ 'post-type-videos','post-imgcap','loothprint','member-benefit','shorty','loothcuts','useful_links','event','document' ] );
}

// Shared taxonomy slug (must be registered)
if ( ! defined( 'LOOTH_TAX_SLUG' ) ) {
    define( 'LOOTH_TAX_SLUG', 'tier' );
}

// Post meta key used by Patreon plugin to gate content
if ( ! defined( 'LOOTH_META_KEY' ) ) {
    define( 'LOOTH_META_KEY', 'patreon-level' );
}

// Term slug to tier mapping (shared across CPTs)
if ( ! defined( 'LOOTH_TERM_TIER_MAP' ) ) {
    define( 'LOOTH_TERM_TIER_MAP', [
        'free'          => 0,
        'looth-lite'    => 1,
        'looth-pro'     => 7,
        'patron-saint'  => 7,
    ]);
}

/**
 * Sync required Patreon tier based on assigned taxonomy terms.
 */
add_action(
    'set_object_terms',
    static function ( int $object_id, array $terms, array $tt_ids, string $taxonomy ) {
        $post_type = get_post_type( $object_id );
        if ( ! in_array( $post_type, LOOTH_CPT_LIST, true ) || $taxonomy !== LOOTH_TAX_SLUG ) {
            return;
        }
        $slugs = array_filter( array_map(
            static function ( $term ) use ( $taxonomy ) {
                if ( is_int( $term ) || ctype_digit( (string) $term ) ) {
                    $term_obj = get_term( (int) $term, $taxonomy );
                    return ( ! is_wp_error( $term_obj ) && $term_obj ) ? $term_obj->slug : '';
                }
                return sanitize_title( $term );
            },
            (array) $terms
        ) );
        $tier = 0;
        foreach ( $slugs as $slug ) {
            if ( isset( LOOTH_TERM_TIER_MAP[ $slug ] ) ) {
                $tier = max( $tier, (int) LOOTH_TERM_TIER_MAP[ $slug ] );
            }
        }
        $existing = get_post_meta( $object_id, LOOTH_META_KEY, true );
        if ( (string) $existing !== (string) $tier ) {
            update_post_meta( $object_id, LOOTH_META_KEY, $tier );
        }
    },
    10,
    4
);
```

---

## #39 — lg-patreon-onboard file list + role-writing grep

Files found:
```
/var/www/html/wp-content/plugins/lg-patreon-onboard/lg-patreon-onboard.php
/var/www/html/wp-content/plugins/lg-patreon-onboard/includes/class-lgpo-sync-cron.php
/var/www/html/wp-content/plugins/lg-patreon-onboard/includes/class-lgpo-sync-engine.php
```

Role-writing files (grep):
```
/var/www/html/wp-content/plugins/lg-patreon-onboard/lg-patreon-onboard.php
/var/www/html/wp-content/plugins/lg-patreon-onboard/includes/class-lgpo-sync-engine.php
/var/www/html/wp-content/plugins/lg-patreon-onboard/includes/class-lgpo-sync-cron.php
```

---

## #40 — Sync Engine location

Found at:
```
/var/www/html/wp-content/plugins/lg-patreon-onboard/includes/class-lgpo-sync-engine.php
```

(Full body captured separately in BATCH-04B-sync-engine-body.md)

---

## #41 — lgpo_patreon_auto_sync cron handler

```
/var/www/html/wp-content/plugins/lg-patreon-onboard/includes/class-lgpo-sync-cron.php:17:    private const CRON_HOOK = 'lgpo_patreon_auto_sync';
```

---

## #42 — lg-looth4-expiry

704 lines. Header:
```php
<?php
/**
 * Plugin Name: LG Looth4 Expiry
 * Description: Adds an optional expiry date/time to looth4 users. Expired users are demoted to looth1. Patreon sync bypass is handled by lg-patreon-sync.
 * Version: 1.0.0
 * Author: Ian Davlin
 */

defined( 'ABSPATH' ) || exit;

define( 'LG_L4E_META_EXPIRES', 'looth4_expires_at' );   // stored as Y-m-d H:i:s UTC
define( 'LG_L4E_CRON_HOOK',    'lg_looth4_expiry_check' );
```

---

## #43 — Collision grep

No output — no files in lg-* plugins, mu-plugins, or buddyboss-theme-child hardcode strangler URLs (`/profile/edit`, `/directory/members`, `/u/$`, `/p/$`). **Clean.**

---

## #44 — URL redirect chains

```
=== /profile/edit ===
Status: 301
Redirect: https://loothgroup.com/edit-efficiency-consultant/

=== /u/some-slug ===
Status: 302
Redirect: https://loothgroup.com/wp-login.php?redirect_to=https%3A%2F%2Floothgroup.com%2Fu%2Fsome-slug&bp-auth=1&action=bpnoaccess

=== /directory/members ===
Status: 301
Redirect: https://loothgroup.com/members/
```

---

## #45 — Patreon plugins

```
lg-patreon-onboard
```

Only one. No separate Patreon SDK plugin.

---

## Analyst notes

- `looth-roles.php` = role registration only, not a writer. Safe.
- Snippet #44 = **content** gating (sets `patreon-level` post meta on CPTs). Not user role writing.
- Role writes all flow through `class-lgpo-sync-engine.php` via `$user->set_role()`.
- `payment_source` usermeta ('patreon'/'stripe') is the coexistence guard.
- `/u/some-slug` currently owned by BP (302 to wp-login). nginx `^~` intercept will take it cleanly.
- No URL collisions with strangler routes.
