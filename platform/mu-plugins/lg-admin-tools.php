<?php
/**
 * Plugin Name: LG Admin Tools — Looth nav page
 * Description: Adds a top-level "Looth" item to the WP admin menu (left sidebar)
 *              that opens a single page listing every Looth dash / app / tool,
 *              grouped by initiative. One stop for everything we maintain.
 *
 * Scope: admins only (manage_options).
 * Storage: none — pure links/markup. Edit this file to add/remove cards.
 */

if (!defined('ABSPATH')) exit;

final class LG_Admin_Tools
{
    public const PAGE_SLUG   = 'lg-tools';
    public const CAPABILITY  = 'manage_options';

    /** Card definitions. Each card is one initiative. Order = display order. */
    private static function cards(): array
    {
        return [
            'archive_poc' => [
                'title' => 'Archive POC',
                'tag'   => 'new front page',
                'blurb' => "The new front page / new-website foundation. SSR'd by its own PHP-FPM pool, reads from a SQLite index. Search, activity stream, member map all wired through here.",
                'groups' => [
                    'Dashes' => [
                        ['Front Page Config', admin_url('admin.php?page=lg-archive-poc-config'), 'rows · sponsors · CTAs · looths', false],
                    ],
                    'App' => [
                        ['Front page',     '/archive-poc/',          'discover · search · activity',  true],
                        ['Public preview', '/archive-poc/?as=public','as logged-out visitor',         true],
                    ],
                    'Endpoints' => [
                        ['Activity feed', '/wp-json/looth/v1/activity?limit=3', 'JSON', true],
                        ['Members geo',   '/wp-json/looth/v1/members-geo',      'JSON (members only)', true],
                        ['Search API',    '/archive-api/v0/search?q=test',       'JSON (cookie-gated)', true],
                    ],
                ],
                'paths' => '<code>/home/ubuntu/projects/archive-poc/</code> · mu-plugin: <code>archive-poc-sync.php</code>',
            ],

            'layout_v2' => [
                'title' => 'LG Layout v2',
                'tag'   => 'content engine',
                'blurb' => "Managed-CPT layout engine. Posts described by a JSON layout in <code>_lg_layout_v2</code> postmeta; blocks dispatched to <code>blocks/*/render.php</code>; CSS bundled and cached.",
                'groups' => [
                    'Dashes' => [
                        ['Block Styles', admin_url('admin.php?page=lg-layout-v2'), 'global defaults · per-block · brand palette', false],
                    ],
                    'Edit a layout' => [
                        ['post-imgcap',      admin_url('edit.php?post_type=post-imgcap'),      'Articles', false],
                        ['post-type-videos', admin_url('edit.php?post_type=post-type-videos'), 'Videos',   false],
                    ],
                ],
                'paths' => '<code>/home/ubuntu/projects/lg-layout-v2/</code>',
            ],

            'membership' => [
                'title' => 'Membership',
                'tag'   => 'Stripe + Patreon',
                'blurb' => "Patreon polls the Patreon API for active patrons + their tiers, syncs to BB roles. lg-stripe-billing handles the standalone billing portal.",
                'groups' => [
                    'Dashes' => [
                        ['Affiliates / Patrons', admin_url('admin.php?page=lg-affiliates'),  'lg-patreon-stripe-poller', false],
                        ['Member Sync',          admin_url('admin.php?page=lg-member-sync'), 'tier resolution + audit',   false],
                    ],
                    'Apps' => [
                        ['Billing portal', '/billing/', 'lg-stripe-billing', true],
                    ],
                ],
                'paths' => 'plugin: <code>lg-patreon-stripe-poller</code> · standalone: <code>/srv/lg-stripe-billing/</code>',
            ],

            'comms' => [
                'title' => 'Comms',
                'tag'   => 'Weekly Email + Showrunner',
                'blurb' => "Weekly email digest + the Showrunner → events CPT bridge that turns the Google Sheet of upcoming sessions into bookable WP events.",
                'groups' => [
                    'Dashes' => [
                        ['Weekly Digest', admin_url('admin.php?page=lg-weekly-digest'), 'compose · schedule · history', false],
                        ['Events CPT',    admin_url('edit.php?post_type=event'),         'showrunner-driven',            false],
                    ],
                ],
                'paths' => 'mu-plugin: <code>loothdev-sheets-bridge.php</code> · doc: <code>/home/ubuntu/projects/docs/showrunner-wp-bridge-CUTOVER.md</code>',
            ],

            'apps' => [
                'title' => 'Standalone Apps + Tools',
                'tag'   => 'thumb · mailpit · etc',
                'blurb' => "Standalone tools that don't live in WP — thumbnails, member directory, dev utilities.",
                'groups' => [
                    'Apps' => [
                        ['Thumb-app editor', '/thumb/editor.html',                  'thumbnail composer', true],
                        ['LG Apps registry', admin_url('admin.php?page=lg-apps'),    'WP-admin',           false],
                    ],
                    'Dev / Ops' => [
                        ['Mailpit',   '/mailpit/',     'captured outbound mail', true],
                        ['Heartbeat', '/__heartbeat',  'nginx health',           true],
                    ],
                ],
                'paths' => 'thumb-app: <code>/srv/thumb-app/</code>',
            ],
        ];
    }

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
    }

    public static function register_menu(): void
    {
        // Top-level menu — high in the order, sage-tinted icon.
        add_menu_page(
            'Looth Tools',
            'Looth',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'render_page'],
            'dashicons-screenoptions',
            3  // Just under Dashboard (2), above the separator (4)
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden');
        $cards = self::cards();
        ?>
        <div class="wrap lg-tools">
            <h1>Looth Tools</h1>
            <p class="description">Every dash, app, and tool we maintain. Click into any of them.</p>

            <div class="lg-tools__grid">
                <?php foreach ($cards as $key => $card): ?>
                    <section class="lg-tools__card" data-card="<?php echo esc_attr($key); ?>">
                        <header class="lg-tools__head">
                            <h2 class="lg-tools__title"><?php echo esc_html($card['title']); ?></h2>
                            <?php if (!empty($card['tag'])): ?>
                                <span class="lg-tools__tag"><?php echo esc_html($card['tag']); ?></span>
                            <?php endif; ?>
                        </header>
                        <p class="lg-tools__blurb"><?php echo wp_kses_post($card['blurb']); ?></p>

                        <?php foreach ($card['groups'] as $groupLabel => $links): ?>
                            <h3 class="lg-tools__group"><?php echo esc_html($groupLabel); ?></h3>
                            <ul class="lg-tools__list">
                                <?php foreach ($links as [$label, $href, $hint, $external]): ?>
                                    <li>
                                        <a href="<?php echo esc_url($href); ?>"
                                           <?php echo $external ? 'target="_blank" rel="noopener"' : ''; ?>>
                                            <?php echo esc_html($label); ?>
                                            <?php if ($external): ?><span class="lg-tools__ext" aria-hidden="true">↗</span><?php endif; ?>
                                        </a>
                                        <?php if ($hint): ?>
                                            <span class="lg-tools__hint"><?php echo esc_html($hint); ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endforeach; ?>

                        <?php if (!empty($card['paths'])): ?>
                            <p class="lg-tools__paths"><?php echo wp_kses_post($card['paths']); ?></p>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>

        <style>
            .lg-tools .description { margin-bottom: 24px; color: #50575e; }
            .lg-tools__grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
                gap: 16px;
                margin: 0;
            }
            .lg-tools__card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 6px;
                padding: 18px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.04);
                display: flex;
                flex-direction: column;
            }
            .lg-tools__head {
                display: flex;
                align-items: baseline;
                gap: 10px;
                flex-wrap: wrap;
                margin-bottom: 4px;
            }
            .lg-tools__title { margin: 0; font-size: 16px; font-weight: 600; }
            .lg-tools__tag {
                background: #f0f6fc;
                color: #2271b1;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 11px;
                font-weight: 500;
            }
            .lg-tools__blurb {
                margin: 4px 0 14px;
                color: #50575e;
                font-size: 13px;
                line-height: 1.45;
            }
            .lg-tools__blurb code { font-size: 12px; }
            .lg-tools__group {
                margin: 12px 0 4px;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                color: #646970;
                font-weight: 600;
            }
            .lg-tools__list { margin: 0; padding: 0; list-style: none; }
            .lg-tools__list li {
                padding: 4px 0;
                border-bottom: 1px dashed #e5e5e5;
                font-size: 13px;
            }
            .lg-tools__list li:last-child { border-bottom: 0; }
            .lg-tools__list a {
                text-decoration: none;
                font-weight: 500;
                color: #2271b1;
            }
            .lg-tools__list a:hover { text-decoration: underline; }
            .lg-tools__hint {
                color: #8c8f94;
                font-size: 11px;
                margin-left: 6px;
            }
            .lg-tools__ext { font-size: 10px; opacity: 0.7; }
            .lg-tools__paths {
                margin: 14px 0 0;
                padding-top: 10px;
                border-top: 1px solid #f0f0f1;
                color: #8c8f94;
                font-size: 11px;
            }
            .lg-tools__paths code {
                background: transparent;
                padding: 0;
                font-size: 11px;
            }
        </style>
        <?php
    }
}

LG_Admin_Tools::boot();
