<?php
/**
 * ArchivePocDash — admin authoring surface for the archive-poc front page.
 *
 * Lives as a submenu under the LG Layout v2 top-level menu. Edits the four
 * config arrays (sponsors, local_looths, cta_member, cta_public) that drive
 * the archive-poc landing page sidebar, then POSTs the result to the
 * archive-poc loopback webhook:
 *
 *   POST https://127.0.0.1/archive-api/v0/_config
 *   Header: X-LG-Config-Secret: <contents of /etc/lg-archive-poc-secret>
 *   Body:   { sponsors: [...], local_looths: [...], cta_member: [...], cta_public: [...] }
 *
 * The webhook writes the payload atomically to <archive-poc>/config.json.
 * Archive-poc's index.php overlays it on top of the PHP-constant defaults.
 *
 * GET on the same endpoint returns the currently saved JSON — used to seed
 * the form on each page load (so what you see IS what's running).
 *
 * Unknown row fields (e.g. `icon` SVG markup, `attr` raw HTML) are NOT
 * exposed in the form but ARE preserved round-trip: the form re-emits them
 * as hidden inputs, so save doesn't strip them.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class ArchivePocDash
{
    public const PARENT_SLUG  = Dash::MENU_SLUG;          // 'lg-layout-v2'
    public const PAGE_SLUG    = 'lg-archive-poc-config';
    public const CAPABILITY   = 'manage_options';
    public const NONCE_ACTION = 'lg_archive_poc_dash_save';
    public const SAVE_ACTION  = 'lg_archive_poc_dash_save';

    public const SECRET_FILE   = '/etc/lg-archive-poc-secret';
    public const WEBHOOK_URL   = 'https://127.0.0.1/archive-api/v0/_config';
    // Loopback Host header is resolved per-box via resolve_host() (LG_ARCHIVE_POC_DASH_HOST
    // override → dev/live detection); there is no hardcoded host constant.
    public const EXPORT_ACTION = 'lg_archive_poc_dash_export';
    public const IMPORT_ACTION = 'lg_archive_poc_dash_import';

    /** Row types where deleting is forbidden from the dash. Activity-strip is
     *  the band's anchor; if a user removes it the receiver re-injects it
     *  anyway, but we hide the delete button so the UI matches the contract. */
    public const PINNED_ROW_TYPES = ['activity-strip'];

    /** Field schemas: what columns each section renders + their input type. */
    public const SECTIONS = [
        'rows' => [
            'title'  => 'Front-Page Rows',
            'desc'   => 'Top-down order of rows on the archive-poc landing page. The <code>activity-strip</code> row is always present (server-enforced) — you can move it but not delete it. The <code>query</code> field is per-type JSON (see shape reference below); blank means no query.',
            'fields' => [
                'id'       => ['label' => 'ID',       'type' => 'text', 'placeholder' => 'unique-slug'],
                'title'    => ['label' => 'Title',    'type' => 'text', 'placeholder' => 'Display title'],
                'type'     => ['label' => 'Type',     'type' => 'select', 'options' => ['', 'static', 'tag-random', 'events-upcoming', 'activity-strip', 'cta-bar', 'sponsors', 'local-looths', 'hero', 'video-promo']],
                'layout'   => ['label' => 'Layout',   'type' => 'select', 'options' => ['', 'rail', 'grid', 'billboard', 'events', 'activity', 'cta-bar', 'local-looths', 'sponsors', 'discussions', 'video-promo']],
                'column'   => ['label' => 'Column',   'type' => 'select', 'options' => ['', 'main', 'left', 'right']],
                'audience' => ['label' => 'Audience', 'type' => 'select', 'options' => ['both', 'members', 'public']],
                'tag'      => ['label' => 'Tag',      'type' => 'tag-list', 'placeholder' => 'start typing to search…'],
                'query'    => ['label' => 'Query (JSON)', 'type' => 'json', 'placeholder' => '{"limit":12}'],
            ],
        ],
        'sponsors' => [
            'title'  => 'Sponsors',
            'desc'   => 'Tiles in the right pane of the activity band. `bg` should match the logo image\'s native background so the tile reads as one card.',
            'fields' => [
                'name' => ['label' => 'Name',    'type' => 'text',  'placeholder' => 'Total Vise'],
                'url'  => ['label' => 'URL',     'type' => 'url',   'placeholder' => 'https://…'],
                'logo' => ['label' => 'Logo',    'type' => 'url',   'placeholder' => 'https://…/logo.webp'],
                'bg'   => ['label' => 'BG',      'type' => 'color', 'placeholder' => '#fff'],
            ],
        ],
        'local_looths' => [
            'title'  => 'Local Looths',
            'desc'   => 'Group avatars + names that used to live in the sidebar (currently unrendered but kept in the data for future use).',
            'fields' => [
                'name'   => ['label' => 'Name',   'type' => 'text', 'placeholder' => 'NYC Looths'],
                'url'    => ['label' => 'URL',    'type' => 'url',  'placeholder' => 'https://…'],
                'avatar' => ['label' => 'Avatar', 'type' => 'url',  'placeholder' => 'https://…/avatar.png'],
            ],
        ],
        'cta_member' => [
            'title'  => 'CTAs — Members',
            'desc'   => 'Buttons in the activity band\'s left pane shown to logged-in visitors. `action` of <code>open-search-modal</code> / <code>open-member-map</code> opens that modal instead of navigating.',
            'fields' => [
                'label'  => ['label' => 'Label',   'type' => 'text',   'placeholder' => 'Add Forum Post'],
                'url'    => ['label' => 'URL',     'type' => 'text',   'placeholder' => 'https://… or #fragment'],
                'style'  => ['label' => 'Style',   'type' => 'select', 'options' => ['primary', 'secondary', 'ghost']],
                'action' => ['label' => 'Action',  'type' => 'select', 'options' => ['', 'open-search-modal', 'open-member-map', 'open-forum-modal']],
            ],
        ],
        'cta_public' => [
            'title'  => 'CTAs — Anonymous',
            'desc'   => 'Buttons shown to logged-out visitors. Same shape as the member CTAs.',
            'fields' => [
                'label'  => ['label' => 'Label',   'type' => 'text',   'placeholder' => 'Join Looth Group'],
                'url'    => ['label' => 'URL',     'type' => 'text',   'placeholder' => 'https://… or #fragment'],
                'style'  => ['label' => 'Style',   'type' => 'select', 'options' => ['primary', 'secondary', 'ghost']],
                'action' => ['label' => 'Action',  'type' => 'select', 'options' => ['', 'open-search-modal', 'open-member-map', 'open-forum-modal']],
            ],
        ],
    ];

    public static function boot(): void
    {
        add_action('admin_menu',                        [self::class, 'register_page'], 11);
        add_action('admin_post_' . self::SAVE_ACTION,   [self::class, 'handle_save']);
        add_action('admin_post_' . self::EXPORT_ACTION, [self::class, 'handle_export']);
        add_action('admin_post_' . self::IMPORT_ACTION, [self::class, 'handle_import']);
    }

    public static function register_page(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            'Archive POC — Front Page',
            'Archive POC',
            self::CAPABILITY,
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    /* ── GET current saved config from webhook (seeds the form) ───────── */

    /**
     * Fetch from the loopback webhook. When $effective is true, ask for
     * defaults+overlay merged (used by the form so it pre-populates with
     * what's actually rendering, not a blank slate). When false, return
     * only the saved overlay (used by export).
     */
    private static function fetch_current_config(bool $effective = false): array
    {
        $url = self::WEBHOOK_URL . ($effective ? '?effective=1' : '');
        $resp = wp_remote_get($url, [
            'timeout'   => 2,
            'sslverify' => false,
            'headers'   => ['Host' => self::resolve_host()],
        ]);
        if (is_wp_error($resp)) return [];
        $body = (string) wp_remote_retrieve_body($resp);
        if ($body === '' || $body === '{}') return [];
        $j = json_decode($body, true);
        return is_array($j) ? $j : [];
    }

    private static function resolve_host(): string
    {
        // Allow live to override via constant: define('LG_ARCHIVE_POC_DASH_HOST', 'loothgroup.com')
        if (defined('LG_ARCHIVE_POC_DASH_HOST')) return (string) constant('LG_ARCHIVE_POC_DASH_HOST');
        // Default detection: dev.* hosts → dev.loothgroup.com, else live host.
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (str_contains($host, 'dev.') || str_contains($host, 'claude.loothgroup')) {
            return 'dev.loothgroup.com';
        }
        return 'loothgroup.com';
    }

    private static function read_secret(): string
    {
        if (!is_readable(self::SECRET_FILE)) return '';
        return trim((string) @file_get_contents(self::SECRET_FILE));
    }

    /* ── Render the form ──────────────────────────────────────────────── */

    public static function render_page(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden');

        // Seed the form with the EFFECTIVE config (defaults + overlay) so
        // empty sections show real values, not a blank placeholder that would
        // wipe defaults on save.
        $current = self::fetch_current_config(/*effective*/ true);
        $secret  = self::read_secret();
        $notice  = isset($_GET['updated']) ? (string) $_GET['updated'] : '';
        $error   = isset($_GET['err'])     ? (string) $_GET['err']     : '';

        ?>
        <div class="wrap lg-archive-poc-dash">
            <h1>Archive POC — Front Page</h1>
            <p class="description">
                Edits the four config arrays that drive the
                <a href="https://<?php echo esc_attr(self::resolve_host()); ?>/archive-poc/" target="_blank" rel="noopener">archive-poc</a>
                front page. Saved values overlay the PHP defaults in <code>web/index.php</code>.
                Unknown row fields (icon SVGs, custom attrs) are preserved on save even though they're not editable here.
            </p>

            <?php if ($secret === ''): ?>
                <div class="notice notice-error">
                    <p><strong>Webhook secret missing.</strong> Saves will fail until <code><?php echo esc_html(self::SECRET_FILE); ?></code> exists and is readable by the web user.</p>
                </div>
            <?php endif; ?>

            <?php if ($notice === '1'): ?>
                <div class="notice notice-success is-dismissible"><p>Saved and pushed to archive-poc.</p></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="notice notice-error"><p>Save failed: <?php echo esc_html(urldecode($error)); ?></p></div>
            <?php endif; ?>

            <?php /* Download / upload / shape — for LLM-assisted authoring. */ ?>
            <div class="lg-io">
                <details class="lg-io__shape">
                    <summary><strong>JSON shape</strong> (for LLM-assisted edits — show this to your model along with the export)</summary>
                    <pre class="lg-io__pre"><?php echo esc_html(self::shape_doc()); ?></pre>
                </details>

                <div class="lg-io__buttons">
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::EXPORT_ACTION), self::EXPORT_ACTION)); ?>"
                       class="button">⬇ Download current config.json</a>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          enctype="multipart/form-data" class="lg-io__upload">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::IMPORT_ACTION); ?>">
                        <?php wp_nonce_field(self::IMPORT_ACTION); ?>
                        <label class="button">
                            <input type="file" name="config_json" accept="application/json,.json" style="display:none"
                                   onchange="this.form.submit()">
                            ⬆ Upload &amp; replace
                        </label>
                    </form>
                </div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <?php foreach (self::SECTIONS as $key => $spec): ?>
                    <?php self::render_section($key, $spec, $current[$key] ?? []); ?>
                <?php endforeach; ?>

                <p>
                    <button type="submit" class="button button-primary">Save &amp; Push</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" class="button">Discard changes</a>
                </p>
            </form>
        </div>

        <style>
            .lg-archive-poc-dash .lg-io { margin: 16px 0; padding: 12px 16px; background: #f0f6fc; border: 1px solid #c3d4e3; border-radius: 6px; }
            .lg-archive-poc-dash .lg-io__buttons { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
            .lg-archive-poc-dash .lg-io__upload { margin: 0; }
            .lg-archive-poc-dash .lg-io__upload .button { cursor: pointer; }
            .lg-archive-poc-dash .lg-io__shape summary { cursor: pointer; font-size: 13px; }
            .lg-archive-poc-dash .lg-io__pre { background: #1d2327; color: #c6e8d6; padding: 12px; border-radius: 4px; overflow-x: auto; font-size: 12px; line-height: 1.5; margin: 8px 0 0; }
            .lg-archive-poc-dash .lg-section { margin: 24px 0; padding: 16px; background: #fff; border: 1px solid #ccd0d4; border-radius: 6px; }
            .lg-archive-poc-dash .lg-section h2 { margin: 0 0 4px; font-size: 16px; }
            .lg-archive-poc-dash .lg-section .lg-section__desc { margin: 0 0 12px; color: #555; font-size: 13px; }
            .lg-archive-poc-dash .lg-section .lg-section__desc code { font-size: 11px; }
            .lg-archive-poc-dash .lg-rows { display: flex; flex-direction: column; gap: 8px; }
            .lg-archive-poc-dash .lg-row { display: flex; gap: 8px; align-items: center; padding: 8px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; }
            .lg-archive-poc-dash .lg-row__field { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
            .lg-archive-poc-dash .lg-row__field label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.04em; }
            .lg-archive-poc-dash .lg-row__field input, .lg-archive-poc-dash .lg-row__field select, .lg-archive-poc-dash .lg-row__field textarea { width: 100%; }
            .lg-archive-poc-dash .lg-row__field input[type=color] { padding: 2px; height: 28px; }
            .lg-archive-poc-dash .lg-row__field textarea { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; resize: vertical; min-height: 36px; }
            .lg-archive-poc-dash .lg-row--pinned { background: #fff4d8; border-color: #d4b65a; }
            .lg-archive-poc-dash .lg-row__pinned-tag { font-size: 11px; color: #8a6d1e; padding: 4px 8px; }
            .lg-archive-poc-dash .lg-row__handle { cursor: move; color: #999; padding: 0 4px; }
            .lg-archive-poc-dash .lg-row__nav { display: flex; flex-direction: column; gap: 2px; }
            .lg-archive-poc-dash .lg-row__move { background: transparent; border: 1px solid #c3c4c7; color: #50575e; cursor: pointer; padding: 0 4px; font-size: 9px; line-height: 14px; border-radius: 2px; min-width: 18px; }
            .lg-archive-poc-dash .lg-row__move:hover:not([disabled]) { background: #2271b1; color: #fff; border-color: #2271b1; }
            .lg-archive-poc-dash .lg-row__move[disabled] { opacity: 0.3; cursor: default; }
            .lg-archive-poc-dash .lg-row__delete { background: transparent; border: 0; color: #b32d2e; cursor: pointer; padding: 4px 8px; font-size: 18px; }
            .lg-archive-poc-dash .lg-row__delete:hover { color: #fff; background: #b32d2e; border-radius: 3px; }
            .lg-archive-poc-dash .lg-section__add { margin-top: 8px; }
        </style>

        <script>
        (function () {
            // Vanilla add / remove / reorder. Each section has a <template>
            // holding a blank row; clicking + clones it. Names use [idx] so
            // POST arrives as an indexed array — reindex after any mutation.
            document.querySelectorAll('.lg-archive-poc-dash .lg-section').forEach(section => {
                const rows = section.querySelector('.lg-rows');
                const tpl  = section.querySelector('template.lg-row-template');

                // Rewrite the [N] segment in every input name to match its
                // current DOM position. Also toggles up/down button disabled
                // state based on whether the row is at an end.
                function reindex() {
                    const all = rows.querySelectorAll('.lg-row');
                    all.forEach((row, i) => {
                        row.querySelectorAll('[name]').forEach(el => {
                            // Match digits OR the __IDX__ placeholder cloned
                            // from the <template> — newly added rows arrive
                            // with the placeholder and need first-time naming.
                            el.name = el.name.replace(/\[(?:\d+|__IDX__)\]/, '[' + i + ']');
                        });
                        const up   = row.querySelector('.lg-row__move--up');
                        const down = row.querySelector('.lg-row__move--down');
                        if (up)   up.disabled   = (i === 0);
                        if (down) down.disabled = (i === all.length - 1);
                    });
                }

                section.querySelector('.lg-section__add').addEventListener('click', () => {
                    const clone = tpl.content.cloneNode(true);
                    // __IDX__ in the template gets replaced when we reindex below.
                    rows.appendChild(clone);
                    reindex();
                });

                rows.addEventListener('click', (e) => {
                    const row = e.target.closest('.lg-row');
                    if (!row) return;
                    if (e.target.closest('.lg-row__delete')) {
                        if (rows.querySelectorAll('.lg-row').length <= 1 &&
                            !confirm('Remove the last row? The section will be empty.')) return;
                        row.remove();
                        reindex();
                    } else if (e.target.closest('.lg-row__move--up')) {
                        const prev = row.previousElementSibling;
                        if (prev && prev.classList.contains('lg-row')) {
                            rows.insertBefore(row, prev);
                            reindex();
                        }
                    } else if (e.target.closest('.lg-row__move--down')) {
                        const next = row.nextElementSibling;
                        if (next && next.classList.contains('lg-row')) {
                            rows.insertBefore(next, row);
                            reindex();
                        }
                    }
                });

                // Keyboard: Alt+↑ / Alt+↓ moves a row when an input inside it
                // is focused. Cheap accessibility-friendly reorder.
                rows.addEventListener('keydown', (e) => {
                    if (!e.altKey) return;
                    if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
                    const row = e.target.closest('.lg-row');
                    if (!row) return;
                    e.preventDefault();
                    const sibling = e.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;
                    if (!sibling || !sibling.classList.contains('lg-row')) return;
                    if (e.key === 'ArrowUp') rows.insertBefore(row, sibling);
                    else                     rows.insertBefore(sibling, row);
                    reindex();
                    // Keep focus on the input the user was typing in.
                    e.target.focus();
                });

                reindex(); // initial state — set disabled on first/last
            });
        })();
        </script>
        <?php
    }

    private static function render_section(string $key, array $spec, array $rows): void
    {
        // Hoist common query.* values up to top-level row fields so the
        // dedicated form inputs (Tag, etc.) get prefilled from saved config.
        // The reverse happens on save in handle_save().
        if ($key === 'rows') {
            foreach ($rows as &$r) {
                if (is_array($r['query'] ?? null) && isset($r['query']['tag']) && !isset($r['tag'])) {
                    $r['tag'] = (string) $r['query']['tag'];
                }
            }
            unset($r);
        }

        // Build a "blank row" template using __IDX__ as a placeholder so JS can
        // insert new rows by cloning + reindexing.
        $blankRow = self::render_row_html($key, '__IDX__', [], $spec['fields']);
        $rowsToRender = $rows ?: [[]]; // always show at least one row to start
        ?>
        <div class="lg-section" data-section="<?php echo esc_attr($key); ?>">
            <h2><?php echo esc_html($spec['title']); ?></h2>
            <p class="lg-section__desc"><?php echo wp_kses_post($spec['desc']); ?></p>

            <?php /* Datalist for tag-list inputs — shared across all rows in this section */ ?>
            <?php if ($key === 'rows'): $tagOpts = self::fetch_top_tags(); ?>
                <datalist id="lg-archive-tags">
                    <?php foreach ($tagOpts as $tag): ?>
                        <option value="<?php echo esc_attr($tag['slug']); ?>" label="<?php echo esc_attr($tag['label'] . ' (' . $tag['n'] . ')'); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            <?php endif; ?>

            <div class="lg-rows">
                <?php foreach ($rowsToRender as $i => $row): ?>
                    <?php echo self::render_row_html($key, (string) $i, $row, $spec['fields']); ?>
                <?php endforeach; ?>
            </div>

            <button type="button" class="button lg-section__add">+ Add row</button>

            <template class="lg-row-template"><?php echo $blankRow; ?></template>
        </div>
        <?php
    }

    /** Top tags for the dash's tag-list datalist. Cached in a transient. */
    private static function fetch_top_tags(): array
    {
        $cached = get_transient('lg_archive_poc_top_tags');
        if (is_array($cached)) return $cached;

        $url = 'https://127.0.0.1/archive-api/v0/search?limit=1';
        $resp = wp_remote_get($url, [
            'timeout'   => 2,
            'sslverify' => false,
            'headers'   => ['Host' => self::resolve_host()],
        ]);
        if (is_wp_error($resp)) return [];
        $body = (string) wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        if (!is_array($data) || !is_array($data['facets']['tag'] ?? null)) return [];

        $tags = [];
        foreach ($data['facets']['tag'] as $t) {
            if (empty($t['v'])) continue;
            $tags[] = [
                'slug'  => (string) $t['v'],
                'label' => (string) ($t['label'] ?? $t['v']),
                'n'     => (int) ($t['n'] ?? 0),
            ];
        }
        // Cap to top 60 — keeps the datalist usable without overwhelming.
        $tags = array_slice($tags, 0, 60);
        set_transient('lg_archive_poc_top_tags', $tags, HOUR_IN_SECONDS);
        return $tags;
    }

    private static function render_row_html(string $section, string $index, array $row, array $fields): string
    {
        // For the `rows` section we lock the delete button when the row's
        // `type` is in PINNED_ROW_TYPES (activity-strip). Per-row, not
        // per-section, so an LLM-uploaded list with the strip in the middle
        // still flags it correctly.
        $isPinned = $section === 'rows' && in_array($row['type'] ?? '', self::PINNED_ROW_TYPES, true);

        ob_start();
        ?>
        <div class="lg-row<?php echo $isPinned ? ' lg-row--pinned' : ''; ?>">
            <?php foreach ($fields as $fieldKey => $fspec): ?>
                <div class="lg-row__field">
                    <label><?php echo esc_html($fspec['label']); ?></label>
                    <?php
                        $rawVal = $row[$fieldKey] ?? '';
                        if (($fspec['type'] ?? '') === 'json' && is_array($rawVal)) {
                            $val = $rawVal ? wp_json_encode($rawVal, JSON_UNESCAPED_SLASHES) : '';
                        } else {
                            $val = (string) $rawVal;
                        }
                        $name = "config[{$section}][{$index}][{$fieldKey}]";
                    ?>
                    <?php if (($fspec['type'] ?? '') === 'select'): ?>
                        <select name="<?php echo esc_attr($name); ?>">
                            <?php foreach (($fspec['options'] ?? []) as $opt): ?>
                                <option value="<?php echo esc_attr($opt); ?>" <?php selected($val, $opt); ?>>
                                    <?php echo esc_html($opt === '' ? '— none —' : $opt); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif (($fspec['type'] ?? '') === 'json'): ?>
                        <textarea name="<?php echo esc_attr($name); ?>"
                                  placeholder="<?php echo esc_attr($fspec['placeholder'] ?? '{}'); ?>"
                                  rows="2" data-json-field><?php echo esc_textarea($val); ?></textarea>
                    <?php elseif (($fspec['type'] ?? '') === 'color'): ?>
                        <input type="text"
                               name="<?php echo esc_attr($name); ?>"
                               value="<?php echo esc_attr($val); ?>"
                               placeholder="<?php echo esc_attr($fspec['placeholder'] ?? ''); ?>">
                    <?php elseif (($fspec['type'] ?? '') === 'tag-list'): ?>
                        <input type="text"
                               name="<?php echo esc_attr($name); ?>"
                               value="<?php echo esc_attr($val); ?>"
                               placeholder="<?php echo esc_attr($fspec['placeholder'] ?? ''); ?>"
                               list="lg-archive-tags"
                               autocomplete="off">
                    <?php else: ?>
                        <input type="<?php echo esc_attr($fspec['type'] ?? 'text'); ?>"
                               name="<?php echo esc_attr($name); ?>"
                               value="<?php echo esc_attr($val); ?>"
                               placeholder="<?php echo esc_attr($fspec['placeholder'] ?? ''); ?>">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php /* Preserve unknown fields (icon SVG, attr raw HTML, etc.) as hidden inputs */ ?>
            <?php foreach ($row as $rk => $rv): ?>
                <?php if (isset($fields[$rk])) continue; ?>
                <?php if (!is_scalar($rv) && $rv !== null) continue; ?>
                <input type="hidden"
                       name="<?php echo esc_attr("config[{$section}][{$index}][{$rk}]"); ?>"
                       value="<?php echo esc_attr((string) $rv); ?>">
            <?php endforeach; ?>

            <div class="lg-row__nav">
                <button type="button" class="lg-row__move lg-row__move--up"   aria-label="Move row up"   tabindex="-1">▲</button>
                <button type="button" class="lg-row__move lg-row__move--down" aria-label="Move row down" tabindex="-1">▼</button>
            </div>
            <?php if ($isPinned): ?>
                <span class="lg-row__pinned-tag" title="Always rendered — cannot be removed">📌 pinned</span>
            <?php else: ?>
                <button type="button" class="lg-row__delete" aria-label="Remove row">×</button>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── Shape reference (for LLM-assisted edits) ─────────────────────── */

    /** Returns a human-readable JSON-ish schema description showing the four
     *  section keys and their field names. Designed to be pasted into an LLM
     *  prompt alongside the downloaded config so the model knows the contract. */
    private static function shape_doc(): string
    {
        $out  = "/**\n * archive-poc front-page config — config.json shape.\n";
        $out .= " *\n";
        $out .= " * File is read by web/index.php. Each top-level key is OPTIONAL and overlays\n";
        $out .= " * the PHP defaults baked into index.php. Missing key → defaults. Empty array → render nothing.\n";
        $out .= " *\n";
        $out .= " * Row order = array order. No `id` is needed for sponsors/looths/CTAs (they're anonymous rows).\n";
        $out .= " * For the `rows` key, `id` IS required — it's the row's stable handle.\n";
        $out .= " *\n";
        $out .= " * The `activity-strip` row is server-enforced: if you omit it from `rows`, the receiver\n";
        $out .= " * re-injects it at position 0. To pin it elsewhere, include it explicitly in your `rows` array.\n";
        $out .= " *\n";
        $out .= " * Unknown row fields (e.g. `icon` SVG HTML in CTAs, `attr` raw HTML) are preserved as-is.\n";
        $out .= " */\n{\n";
        foreach (self::SECTIONS as $key => $spec) {
            $out .= '  "' . $key . '": [   // ' . $spec['title'] . "\n";
            $out .= "    {\n";
            foreach ($spec['fields'] as $fk => $fspec) {
                $type = $fspec['type'] ?? 'text';
                if ($type === 'select') {
                    $opts = implode(' | ', array_map(fn($o) => '"' . $o . '"', $fspec['options']));
                    $out .= '      "' . $fk . '": ' . $opts . ",\n";
                } elseif ($type === 'json') {
                    $hint = $fspec['placeholder'] ?? '{}';
                    $out .= '      "' . $fk . '": ' . $hint . ",   // nested object — per-type keys (see notes below)\n";
                } else {
                    $hint = $fspec['placeholder'] ?? '';
                    $out .= '      "' . $fk . '": "' . $hint . '"' . ($type === 'color' ? '   // hex like #fff' : '') . ",\n";
                }
            }
            $out .= "    }\n";
            $out .= "  ],\n";
        }
        $out .= "}\n\n";
        $out .= "/* `query` shapes per row `type`:\n";
        $out .= " *   tag-random       — { \"tag\": \"frets\", \"limit\": 12, \"sort\": \"newest\" }\n";
        $out .= " *   static           — { \"sort\": \"newest|most-liked|active\", \"limit\": 10, \"max_age_days\": 14, \"exclude_kinds\": [\"discussion\"] }\n";
        $out .= " *   events-upcoming  — { \"limit\": 20 }\n";
        $out .= " *   activity-strip   — { \"limit\": 15 }   (always-on band; never delete)\n";
        $out .= " *   hero / cta-bar / sponsors / local-looths — no query needed\n";
        $out .= " */\n";
        return $out;
    }

    /* ── Export: stream current config.json as a download ─────────────── */

    public static function handle_export(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', '', ['response' => 403]);
        check_admin_referer(self::EXPORT_ACTION);

        $current = self::fetch_current_config();
        $json    = wp_json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="archive-poc-config.json"');
        echo $json;
        exit;
    }

    /* ── Import: accept an uploaded JSON file + push as-is ────────────── */

    public static function handle_import(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', '', ['response' => 403]);
        check_admin_referer(self::IMPORT_ACTION);

        if (empty($_FILES['config_json']['tmp_name']) || !is_uploaded_file($_FILES['config_json']['tmp_name'])) {
            self::redirect_back('no%20file%20uploaded');
            return;
        }
        $raw = @file_get_contents($_FILES['config_json']['tmp_name']);
        if ($raw === false) {
            self::redirect_back('upload%20unreadable');
            return;
        }
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            self::redirect_back('not%20valid%20JSON%20object');
            return;
        }
        // Keep only the four known section keys to keep the file clean.
        $payload = [];
        foreach (array_keys(self::SECTIONS) as $k) {
            if (isset($parsed[$k]) && is_array($parsed[$k])) $payload[$k] = $parsed[$k];
        }
        if (empty($payload)) {
            self::redirect_back('no%20known%20section%20keys%20found');
            return;
        }

        $secret = self::read_secret();
        if ($secret === '') { self::redirect_back('secret%20file%20not%20readable'); return; }

        $resp = wp_remote_post(self::WEBHOOK_URL, [
            'method'    => 'POST',
            'timeout'   => 4,
            'sslverify' => false,
            'headers'   => [
                'Host'                => self::resolve_host(),
                'Content-Type'        => 'application/json',
                'X-LG-Config-Secret'  => $secret,
            ],
            'body'      => wp_json_encode($payload),
        ]);
        if (is_wp_error($resp)) { self::redirect_back(urlencode($resp->get_error_message())); return; }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            self::redirect_back('webhook%20HTTP%20' . $code . '%20%E2%80%94%20' . urlencode((string) wp_remote_retrieve_body($resp)));
            return;
        }
        self::redirect_back('', true);
    }

    /* ── Save + push to webhook ───────────────────────────────────────── */

    public static function handle_save(): void
    {
        if (!current_user_can(self::CAPABILITY)) wp_die('Forbidden', '', ['response' => 403]);
        check_admin_referer(self::NONCE_ACTION);

        $raw = isset($_POST['config']) && is_array($_POST['config']) ? wp_unslash($_POST['config']) : [];

        // Build clean payload. A row is kept only if it has an "identifier"
        // field set (id / label / name / url) — rejects empty placeholder
        // rows whose select defaults would otherwise mis-flag them as content.
        // `json`-typed fields get parsed back into arrays before the payload
        // goes to the webhook (which expects nested `query` objects).
        $identifierFields = ['id', 'label', 'name', 'url'];
        $payload = [];
        foreach (self::SECTIONS as $sectionKey => $sectionSpec) {
            $section = is_array($raw[$sectionKey] ?? null) ? $raw[$sectionKey] : [];
            $cleanRows = [];
            foreach ($section as $row) {
                if (!is_array($row)) continue;
                $cleanRow = [];
                foreach ($row as $rk => $rv) {
                    if (!is_string($rk)) continue;
                    if (!is_scalar($rv) && $rv !== null) continue;
                    $val = (string) $rv;
                    $fieldType = $sectionSpec['fields'][$rk]['type'] ?? null;

                    if ($fieldType === 'json') {
                        $trimmed = trim($val);
                        if ($trimmed === '') {
                            // Blank textarea = empty object/no query; omit.
                            continue;
                        }
                        $parsed = json_decode($trimmed, true);
                        if (!is_array($parsed)) {
                            self::redirect_back(urlencode("invalid JSON in {$sectionKey}.{$rk}: " . substr($trimmed, 0, 60)));
                            return;
                        }
                        $cleanRow[$rk] = $parsed;
                        continue;
                    }

                    // Drop blanks for unknown fields; keep blanks for known
                    // fields so a deliberately-empty `bg`/`action` survives.
                    if ($val === '' && !isset($sectionSpec['fields'][$rk])) continue;
                    $cleanRow[$rk] = $val;
                }
                // Merge top-level Tag field into query.tag (reverse of hoist
                // in render_section). Empty tag → remove from query.
                if ($sectionKey === 'rows') {
                    $tagVal = isset($cleanRow['tag']) ? trim((string) $cleanRow['tag']) : '';
                    $q = is_array($cleanRow['query'] ?? null) ? $cleanRow['query'] : [];
                    if ($tagVal !== '') {
                        $q['tag'] = $tagVal;
                    } else {
                        unset($q['tag']);
                    }
                    if (!empty($q)) $cleanRow['query'] = $q;
                    else            unset($cleanRow['query']);
                    unset($cleanRow['tag']);
                }

                $hasIdentifier = false;
                foreach ($identifierFields as $idf) {
                    if (!empty($cleanRow[$idf])) { $hasIdentifier = true; break; }
                }
                if ($hasIdentifier) $cleanRows[] = $cleanRow;
            }
            $payload[$sectionKey] = $cleanRows;
        }

        $secret = self::read_secret();
        if ($secret === '') {
            self::redirect_back('secret%20file%20not%20readable');
            return;
        }

        $resp = wp_remote_post(self::WEBHOOK_URL, [
            'method'    => 'POST',
            'timeout'   => 4,
            'sslverify' => false,
            'headers'   => [
                'Host'                => self::resolve_host(),
                'Content-Type'        => 'application/json',
                'X-LG-Config-Secret'  => $secret,
            ],
            'body'      => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp)) {
            self::redirect_back(urlencode($resp->get_error_message()));
            return;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            $body = (string) wp_remote_retrieve_body($resp);
            self::redirect_back('webhook%20HTTP%20' . $code . '%20%E2%80%94%20' . urlencode($body));
            return;
        }

        self::redirect_back('', true);
    }

    private static function redirect_back(string $err, bool $ok = false): void
    {
        $args = ['page' => self::PAGE_SLUG];
        if ($ok)   $args['updated'] = 1;
        if ($err)  $args['err']     = $err;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
