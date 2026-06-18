<?php
/**
 * MetaBox — post-edit screen authoring surface for v2 layouts.
 *
 * Renders one panel below the post content area on every managed CPT. The
 * panel is generated from each block's manifest (props → form fields), with
 * specialized props (image, file, URL, etc.) dispatched through EditorPickers.
 *
 * Scope (single-block phase):
 *   - Exactly one block per layout. The block's type is selectable via a
 *     dropdown showing every block whose manifest declares editor.insertable.
 *   - No add/remove/reorder. Multi-block needs the inline editor (Phase 4).
 *
 * Save path:
 *   - Hooks save_post. Reassembles { schema:1, _meta:{}, blocks:[<one>] }.
 *   - Validates via Validator::validate(). Errors stash in a transient and
 *     surface on next render.
 *   - Writes _lg_layout_v2; Plugin::on_post_meta_changed already invalidates
 *     the rendered cache.
 *
 * The post content area (Classic editor / Gutenberg) is left alone for now.
 * It still holds the WpRenderer anon-cache HTML; authors must edit only via
 * this metabox. Moving the cache off post_content is a known follow-up.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class MetaBox
{
    public const ID            = 'lg-layout-v2-blocks';
    public const NONCE_ACTION  = 'lg_layout_v2_metabox_save';
    public const NONCE_NAME    = '_lg_layout_v2_metabox_nonce';
    public const TRANSIENT_ERR = 'lg_layout_v2_mb_errors_';

    public static function boot(): void
    {
        add_action('add_meta_boxes', [self::class, 'register']);
        add_action('save_post',      [self::class, 'save'], 10, 2);
    }

    public static function register(): void
    {
        foreach (Plugin::MANAGED_CPTS as $cpt) {
            add_meta_box(
                self::ID,
                'LG Layout v2 — Blocks',
                [self::class, 'render'],
                $cpt,
                'normal',
                'high'
            );
        }
    }

    /* ── Render ──────────────────────────────────────────────────────── */

    public static function render(\WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        $layout = Plugin::load_layout($post->ID);
        $blocks = is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];

        $insertable = self::insertable_blocks();

        /* (array) cast on a missing transient (false) yields [false], which
           then renders as a ghost "?: " row. Default to [] explicitly. */
        $stored = get_transient(self::TRANSIENT_ERR . $post->ID);
        $errors = is_array($stored) ? $stored : [];
        if ($errors) delete_transient(self::TRANSIENT_ERR . $post->ID);

        ?>
        <style>
            .lg-v2-mb-slots { display:flex; flex-direction:column; gap:12px; margin:12px 0; }
            .lg-v2-mb-empty { padding:18px; border:1px dashed #c3c4c7; background:#f6f7f7; text-align:center; color:#666; }
            .lg-v2-mb-slot { border:1px solid #c3c4c7; background:#fff; border-radius:4px; padding:12px 14px; }
            .lg-v2-mb-slot__hdr { display:flex; align-items:center; gap:12px; padding-bottom:8px; margin-bottom:10px; border-bottom:1px dashed #dcdcde; }
            .lg-v2-mb-slot__hdr h3 { margin:0; font-size:14px; text-transform:uppercase; letter-spacing:0.04em; color:#1d2327; flex:0 0 auto; }
            .lg-v2-mb-slot__ctrls { margin-left:auto; display:flex; gap:6px; align-items:center; }
            .lg-v2-mb-slot__ctrls .button { padding:0 8px; min-height:26px; line-height:24px; }
            .lg-v2-mb-remove { color:#b32d2e; }
            .lg-v2-mb-remove:hover { color:#a00; }
            .lg-v2-mb-add { display:flex; align-items:center; gap:10px; padding:12px 14px; background:#f6f7f7; border:1px dashed #c3c4c7; border-radius:4px; }
            .lg-v2-mb-add label { display:flex; align-items:center; gap:8px; }
            .lg-v2-mb-field { display:flex; flex-direction:column; gap:4px; margin:8px 0; }
            .lg-v2-mb-field > label { font-size:13px; }
            .lg-v2-mb-thumb img { max-width:240px; height:auto; display:block; border-radius:3px; }
            .lg-v2-mb-thumb__empty { color:#777; font-style:italic; }
            /* Columns block: stacked per-column sub-sections so the author
               sees exactly which column each child lives in. Each column
               is its own bordered card. Add/Remove Column controls sit at
               the bottom of the columns block. */
            .lg-v2-mb-columns { margin:12px 0 0; display:flex; flex-direction:column; gap:10px; }
            .lg-v2-mb-column { padding:10px 12px 12px; border-left:3px solid #c3c4c7; background:#fbfbfb; border-radius:0 4px 4px 0; }
            .lg-v2-mb-column__hdr { font-size:12px; text-transform:uppercase; letter-spacing:0.04em; color:#3c4145; margin-bottom:8px; }
            .lg-v2-mb-column > .lg-v2-mb-slots { margin:0 0 10px; }
            .lg-v2-mb-column .lg-v2-mb-add { background:#f0f1f3; }
            .lg-v2-mb-column-ops { display:flex; gap:8px; margin-top:6px; }
            .lg-v2-mb-move-col { font-size:11px; padding:0 6px; min-height:24px; line-height:22px; }
            /* Import-JSON widget: collapsed by default to keep the metabox
               surface clean for normal authoring; expanded when an author
               (or a tool that generated JSON elsewhere) wants to paste a
               whole layout in one shot. */
            .lg-v2-mb-import { margin:0 0 14px; padding:10px 12px; background:#f6f7f7; border:1px solid #c3c4c7; border-radius:4px; }
            .lg-v2-mb-import summary { cursor:pointer; font-weight:600; font-size:13px; padding:2px 0; }
            .lg-v2-mb-import textarea { width:100%; min-height:140px; margin-top:8px; font-family:Menlo,Consolas,monospace; font-size:12px; }
            .lg-v2-mb-import__hint { font-size:12px; color:#666; margin:6px 0 0; }
            .lg-v2-mb-import__ops { display:flex; gap:8px; margin-top:8px; }
            /* Repeater UI for array_of_objects props (items rows etc.) */
            .lg-v2-mb-repeater { margin:8px 0 12px; }
            .lg-v2-mb-repeater__hdr { display:block; font-size:13px; margin-bottom:6px; }
            .lg-v2-mb-repeater__rows { display:flex; flex-direction:column; gap:6px; }
            .lg-v2-mb-repeater__add { margin-top:8px; }
            .lg-v2-mb-row { display:grid; grid-template-columns:auto 1fr; gap:10px; padding:8px 10px; background:#fbfbfb; border:1px solid #dcdcde; border-radius:4px; }
            .lg-v2-mb-row__ctrls { display:flex; flex-direction:column; gap:3px; align-items:stretch; }
            .lg-v2-mb-row__btn { padding:0 6px !important; min-height:22px !important; line-height:20px !important; font-size:12px; }
            .lg-v2-mb-row__fields { display:grid; grid-template-columns:140px 1fr 1fr; gap:6px 10px; align-items:start; }
            .lg-v2-mb-row__field { display:flex; flex-direction:column; gap:2px; font-size:12px; min-width:0; }
            .lg-v2-mb-row__field > span { color:#50575e; font-size:11px; letter-spacing:0.02em; text-transform:uppercase; }
            .lg-v2-mb-row__field input, .lg-v2-mb-row__field select { padding:3px 6px !important; font-size:13px !important; width:100% !important; min-height:26px !important; line-height:1.3 !important; }
            .lg-v2-mb-row__field--description { grid-column:1 / -1; }
            .lg-v2-mb-row__icon-wrap { display:grid; grid-template-columns:1fr auto; gap:6px; align-items:center; }
            .lg-v2-mb-row__icon-preview { display:inline-grid; place-items:center; width:24px; height:24px; color:#b8842b; }
            .lg-v2-mb-row__icon-preview svg { width:18px; height:18px; display:block; }
            .lg-v2-mb-row__extras { grid-column:1 / -1; margin-top:4px; }
            .lg-v2-mb-row__extras > summary { font-size:12px; cursor:pointer; color:#50575e; padding:2px 0; }
            .lg-v2-mb-row__extras[open] > summary { margin-bottom:4px; }
            .lg-v2-mb-row__extras .lg-v2-mb-row__fields { padding-top:4px; border-top:1px dashed #dcdcde; }
        </style>
        <div class="lg-v2-mb">
            <p class="description">
                Source of truth is <code>_lg_layout_v2</code> post meta — the post content area above is ignored.
                Use ↑/↓ to reorder; Remove to delete a block; + Add Block at the bottom to append a new one.
            </p>

            <?php if ($errors):
                $anyFatal = false;
                foreach ($errors as $e) { if (!empty($e['fatal'])) { $anyFatal = true; break; } }
            ?>
                <div class="notice <?php echo $anyFatal ? 'notice-error' : 'notice-success'; ?> inline">
                    <?php if ($anyFatal): ?>
                        <p><strong>Save was rejected — validator errors:</strong></p>
                    <?php endif; ?>
                    <ul>
                        <?php foreach ($errors as $e): ?>
                            <li><code><?php echo esc_html((string) ($e['path'] ?? '?')); ?></code>: <?php echo esc_html((string) ($e['msg'] ?? '')); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php
            /* Live layout JSON: pre-fill the textarea with the current
               stored layout so the field doubles as an export surface.
               Authors can read/copy/edit it in place, then Import & Replace
               to apply edits. Download button writes the same text out
               as a .json file via a tiny inline blob handler. */
            $currentLayoutJson = $layout && is_array($layout)
                ? (string) json_encode($layout, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : '';
            $downloadName = 'lg-layout-v2-post-' . (int) $post->ID . '.json';
            ?>
            <details class="lg-v2-mb-import">
                <summary>Layout JSON (view / edit / replace / download)</summary>
                <p class="lg-v2-mb-import__hint">
                    Live mirror of this post's stored layout. Edit and hit <strong>Import &amp; Replace</strong> to apply
                    (validated first — fatal errors surface above and reject the save). <strong>Download JSON</strong>
                    saves the current layout as a file. Matches the schema in <code>docs/LAYOUT-JSON.md</code>;
                    same operation as <code>wp lg-layout-v2 import</code> / <code>export</code>.
                </p>
                <textarea name="lg_v2_import_json" id="lg_v2_import_json" placeholder='{"schema":1,"blocks":[…]}' spellcheck="false"><?php echo esc_textarea($currentLayoutJson); ?></textarea>
                <div class="lg-v2-mb-import__ops">
                    <button type="submit" name="lg_v2_action" value="import_json"
                            class="button button-primary"
                            onclick="return confirm('Replace this post’s entire layout with the pasted JSON?');">
                        Import &amp; Replace
                    </button>
                    <button type="submit" name="lg_v2_action" value="validate_json"
                            class="button">
                        Validate only
                    </button>
                    <button type="button" class="button" id="lg_v2_download_json"
                            data-lg-download-name="<?php echo esc_attr($downloadName); ?>">
                        Download JSON
                    </button>
                </div>
            </details>
            <script>
            /* Bound at parse time — inline onclick handlers were getting
               eaten by admin-side restrictions on some hosts, so wire it
               via addEventListener with a guarded once-flag. */
            (function () {
                var btn = document.getElementById('lg_v2_download_json');
                if (!btn || btn._lgBound) return;
                btn._lgBound = true;
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var t = document.getElementById('lg_v2_import_json');
                    if (!t || !t.value) {
                        alert('Nothing to download — this post has no stored v2 layout yet.');
                        return;
                    }
                    var name = btn.getAttribute('data-lg-download-name') || 'lg-layout-v2.json';
                    /* Prefer Blob URL when available; some admin plugins
                       clobber window.URL so we fall back to a data: URL
                       which works even when URL.createObjectURL is gone. */
                    var href = null;
                    try {
                        if (window.URL && typeof window.URL.createObjectURL === 'function') {
                            href = window.URL.createObjectURL(
                                new Blob([t.value], { type: 'application/json' })
                            );
                        }
                    } catch (err) { href = null; }
                    if (!href) {
                        href = 'data:application/json;charset=utf-8,'
                             + encodeURIComponent(t.value);
                    }
                    var a = document.createElement('a');
                    a.href     = href;
                    a.download = name;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    if (href.indexOf('blob:') === 0) {
                        setTimeout(function () {
                            try { window.URL.revokeObjectURL(href); } catch (e) {}
                        }, 1000);
                    }
                });
            })();
            </script>

            <?php
            /* Legacy source pane — read-only dump of post_content and the
               raw post meta the legacy importer would have read from. Helps
               when dialing in a converted post: you can see the source the
               Mapper worked from alongside the converted layout above. */
            $legacyContent = (string) $post->post_content;
            $allMeta       = get_post_meta($post->ID);
            $metaFiltered  = [];
            $skipKeys      = [
                LG_LAYOUT_V2_META_KEY,
                '_edit_lock', '_edit_last',
                'lg_layout_v2_rendered_at',
            ];
            if (defined('LG_LAYOUT_V2_RENDERED_AT_META')) {
                $skipKeys[] = LG_LAYOUT_V2_RENDERED_AT_META;
            }
            foreach ($allMeta as $k => $vArr) {
                if (in_array($k, $skipKeys, true)) continue;
                /* get_post_meta($id) returns each value wrapped in an array.
                   Unwrap singletons; keep arrays as arrays. Maybe-unserialize
                   so structured ACF fields show as objects, not as serialized
                   strings. */
                $vals = [];
                foreach ((array) $vArr as $raw) {
                    $vals[] = maybe_unserialize($raw);
                }
                $metaFiltered[$k] = count($vals) === 1 ? $vals[0] : $vals;
            }
            ksort($metaFiltered);
            $metaJson = (string) wp_json_encode(
                $metaFiltered,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            );
            $hasLegacy = $legacyContent !== '' || !empty($metaFiltered);
            ?>
            <?php
            /* Combined download payload: one JSON file with post_content +
               full meta blob, so dial-in is a single click instead of
               copy-paste of two textareas. */
            $legacyBundle = (string) wp_json_encode([
                'post_id'      => (int) $post->ID,
                'post_type'    => (string) $post->post_type,
                'post_status'  => (string) $post->post_status,
                'post_title'   => (string) $post->post_title,
                'post_content' => $legacyContent,
                'meta'         => $metaFiltered,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $legacyDownloadName = 'lg-legacy-source-post-' . (int) $post->ID . '.json';
            ?>
            <details class="lg-v2-mb-import">
                <summary>Legacy source — post_content + ACF/meta (read-only) <?php echo $hasLegacy ? '' : '<em style="font-weight:400;color:#777;"> · empty</em>'; ?></summary>
                <p class="lg-v2-mb-import__hint">
                    Snapshot of the data lg-legacy-import worked from when this post was converted. Useful for dial-in:
                    compare against the layout JSON above to see what the converter saw vs. what it produced.
                </p>
                <fieldset class="lg-v2-mb-field">
                    <label for="lg_v2_legacy_content"><strong>post_content</strong> (WYSIWYG body)</label>
                    <textarea id="lg_v2_legacy_content" readonly spellcheck="false"
                              style="width:100%; min-height:160px; font-family:Menlo,Consolas,monospace; font-size:12px;"
                              placeholder="(empty)"><?php echo esc_textarea($legacyContent); ?></textarea>
                </fieldset>
                <fieldset class="lg-v2-mb-field">
                    <label for="lg_v2_legacy_meta"><strong>post meta</strong> (ACF fields + WP internals, minus the v2 layout itself)</label>
                    <textarea id="lg_v2_legacy_meta" readonly spellcheck="false"
                              style="width:100%; min-height:200px; font-family:Menlo,Consolas,monospace; font-size:12px;"
                              placeholder="(empty)"><?php echo esc_textarea($metaJson); ?></textarea>
                </fieldset>
                <textarea id="lg_v2_legacy_bundle" hidden><?php echo esc_textarea($legacyBundle); ?></textarea>
                <div class="lg-v2-mb-import__ops">
                    <button type="button" class="button button-primary" id="lg_v2_download_legacy"
                            data-lg-download-name="<?php echo esc_attr($legacyDownloadName); ?>">
                        Download legacy source JSON
                    </button>
                </div>
            </details>
            <script>
            (function () {
                var btn = document.getElementById('lg_v2_download_legacy');
                if (!btn || btn._lgBound) return;
                btn._lgBound = true;
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var t = document.getElementById('lg_v2_legacy_bundle');
                    if (!t || !t.value) { alert('No legacy source for this post.'); return; }
                    var name = btn.getAttribute('data-lg-download-name') || 'lg-legacy-source.json';
                    var href = null;
                    try {
                        if (window.URL && typeof window.URL.createObjectURL === 'function') {
                            href = window.URL.createObjectURL(new Blob([t.value], { type: 'application/json' }));
                        }
                    } catch (err) { href = null; }
                    if (!href) href = 'data:application/json;charset=utf-8,' + encodeURIComponent(t.value);
                    var a = document.createElement('a');
                    a.href = href; a.download = name;
                    document.body.appendChild(a); a.click(); document.body.removeChild(a);
                    if (href.indexOf('blob:') === 0) {
                        setTimeout(function () { try { window.URL.revokeObjectURL(href); } catch (e) {} }, 1000);
                    }
                });
            })();
            </script>

            <div class="lg-v2-mb-slots" data-lg-mb-root>
                <?php if (!$blocks): ?>
                    <p class="lg-v2-mb-empty"><em>No blocks yet. Add one below.</em></p>
                <?php endif; ?>
                <?php foreach ($blocks as $i => $b):
                    $type = sanitize_key((string) ($b['type'] ?? ''));
                    if (!isset($insertable[$type])) continue;   /* unknown block type — skip in UI, save preserves */
                    self::render_block_slot([(int) $i], $type, is_array($b) ? $b : [], $insertable);
                endforeach; ?>
            </div>

            <?php self::render_add_block_ui($insertable, /* containerPath */ ''); ?>
        </div>

        <script>
        /* Repeater UI behavior. One script wires every [data-lg-repeater]
           on the page: add row from the <template>, delete, move up/down,
           live-update the icon preview from the inline SVG map. Field-name
           collisions are avoided by giving each row a UUID-ish index when
           added; PHP doesn't care about index *values*, only __pos order. */
        (function () {
            var ICONS = <?php echo wp_json_encode(Icons::all()); ?>;
            var addedCounter = 0;

            document.querySelectorAll('[data-lg-repeater]').forEach(function (rep) {
                if (rep._lgWired) return; rep._lgWired = true;
                var rowsEl = rep.querySelector('[data-lg-repeater-rows]');
                var tpl    = rep.querySelector('[data-lg-repeater-template]');
                var addBtn = rep.querySelector('[data-lg-repeater-add]');

                addBtn.addEventListener('click', function () {
                    var idx = 'n' + (++addedCounter);
                    var html = (tpl.innerHTML || '').replace(/__INDEX__/g, idx);
                    var wrap = document.createElement('div');
                    wrap.innerHTML = html.trim();
                    var row = wrap.firstElementChild;
                    if (!row) return;
                    rowsEl.appendChild(row);
                    bindRow(row);
                    renumber(rowsEl);
                });

                rowsEl.querySelectorAll('[data-lg-row]').forEach(bindRow);
            });

            function bindRow(row) {
                if (row._lgWired) return; row._lgWired = true;
                var del = row.querySelector('[data-lg-row-delete]');
                var up  = row.querySelector('[data-lg-row-up]');
                var dn  = row.querySelector('[data-lg-row-down]');
                if (del) del.addEventListener('click', function () {
                    var parent = row.parentElement;
                    row.remove();
                    if (parent) renumber(parent);
                });
                if (up) up.addEventListener('click', function () {
                    var prev = row.previousElementSibling;
                    if (prev) row.parentElement.insertBefore(row, prev);
                    renumber(row.parentElement);
                });
                if (dn) dn.addEventListener('click', function () {
                    var next = row.nextElementSibling;
                    if (next) row.parentElement.insertBefore(next, row);
                    renumber(row.parentElement);
                });
                var sel = row.querySelector('[data-lg-icon-select]');
                var preview = row.querySelector('[data-lg-icon-preview]');
                if (sel && preview) {
                    sel.addEventListener('change', function () {
                        var svg = ICONS[sel.value] || ICONS['link'] || '';
                        preview.innerHTML = svg;
                    });
                }
            }

            function renumber(rowsEl) {
                if (!rowsEl) return;
                rowsEl.querySelectorAll('[data-lg-row]').forEach(function (row, i) {
                    var p = row.querySelector('[data-lg-row-pos]');
                    if (p) p.value = i;
                });
            }
        })();
        </script>
        <?php
    }

    /** Render one block slot. $path is the data path to this slot:
     *  [2] for root slot 2, or [2,'columns',0,'blocks',1] for child 1 of
     *  column 0 of root 2. Used to build both field names and action
     *  values. $siblingCols is the column count of the enclosing columns
     *  block when this slot is inside one — drives the "→ Col N" buttons. */
    private static function render_block_slot(array $path, string $type, array $b, array $insertable, ?int $siblingCols = null): void
    {
        try {
            $m = Manifest::get($type);
        } catch (\RuntimeException $e) {
            echo '<p class="notice notice-error"><code>' . esc_html($e->getMessage()) . '</code></p>';
            return;
        }

        $picker     = $m['editor']['custom_picker'] ?? null;
        $owned      = EditorPickers::owned_props($picker);
        $namePrefix = self::path_to_name_prefix($path);
        $action     = self::path_to_action_suffix($path);
        $slotIndex  = (string) end($path);   /* this slot's own index within its container */
        ?>
        <section class="lg-v2-mb-slot" data-slot="<?php echo esc_attr($action); ?>" data-type="<?php echo esc_attr($type); ?>">
            <header class="lg-v2-mb-slot__hdr">
                <h3><?php echo esc_html($type); ?></h3>
                <input type="hidden" name="<?php echo esc_attr($namePrefix . '[type]'); ?>"
                       value="<?php echo esc_attr($type); ?>" />
                <input type="hidden" name="<?php echo esc_attr($namePrefix . '[__pos]'); ?>"
                       value="<?php echo esc_attr($slotIndex); ?>"
                       class="lg-v2-mb-pos" />
                <?php if (!empty($b['id']) && is_string($b['id'])): ?>
                    <input type="hidden" name="<?php echo esc_attr($namePrefix . '[id]'); ?>"
                           value="<?php echo esc_attr($b['id']); ?>" />
                <?php endif; ?>
                <span class="lg-v2-mb-slot__ctrls">
                    <button type="button" class="button lg-v2-mb-move-up"  title="Move up">↑</button>
                    <button type="button" class="button lg-v2-mb-move-down" title="Move down">↓</button>
                    <?php
                    /* "→ Col N" buttons: appear only when this slot is
                       inside a columns block. One button per *other*
                       column. The current column is excluded. Three-col
                       case yields two buttons; two-col case yields one. */
                    if ($siblingCols !== null) {
                        $currentCol = null;
                        for ($i = 0; $i < count($path) - 1; $i++) {
                            if ($path[$i] === 'columns') { $currentCol = (int) $path[$i + 1]; break; }
                        }
                        if ($currentCol !== null) {
                            for ($t = 0; $t < $siblingCols; $t++) {
                                if ($t === $currentCol) continue;
                                $moveAction = "move_block_{$action}_to_c{$t}";
                                ?>
                                <button type="submit" name="lg_v2_action"
                                        value="<?php echo esc_attr($moveAction); ?>"
                                        class="button lg-v2-mb-move-col"
                                        title="Move this block to column <?php echo $t + 1; ?>">→ Col <?php echo $t + 1; ?></button>
                                <?php
                            }
                        }
                    }
                    ?>
                    <button type="submit" name="lg_v2_action"
                            value="remove_block_<?php echo esc_attr($action); ?>"
                            class="button-link lg-v2-mb-remove"
                            onclick="return confirm('Remove this block?');">Remove</button>
                </span>
            </header>

            <?php if ($picker): ?>
                <?php echo EditorPickers::render($picker, $b, $namePrefix, $action); ?>
            <?php endif; ?>

            <?php foreach ($m['schema']['props'] as $propName => $propDef):
                if (in_array($propName, $owned, true)) continue;
                if ($propName === 'blocks') continue;   /* children rendered as nested slots, not a scalar field */
                $ptype = (string) ($propDef['type'] ?? 'string');
                if (in_array($ptype, ['array', 'object'], true)) continue;   /* structural; handled by block-specific UI */
                if ($ptype === 'array_of_objects') {
                    self::render_repeater($namePrefix, $propName, $propDef, is_array($b[$propName] ?? null) ? $b[$propName] : []);
                    continue;
                }
                self::render_scalar_field($namePrefix, $propName, $propDef, $b[$propName] ?? null);
            endforeach; ?>

            <?php if ($type === 'columns'): ?>
                <?php self::render_columns_section($path, $b, $insertable); ?>
            <?php endif; ?>
        </section>
        <?php
    }

    /** Per-column sub-sections inside a columns slot. Each column bucket
     *  gets its own stacked section showing its children + an Add UI
     *  scoped to that column. The columns block also gets Add/Remove
     *  Column controls (capped at 2..3). */
    private static function render_columns_section(array $parentPath, array $b, array $insertable): void
    {
        $columnsData = is_array($b['columns'] ?? null) ? $b['columns'] : [];
        if (empty($columnsData)) $columnsData = [['blocks' => []], ['blocks' => []]];
        $colCount = count($columnsData);

        /* No nested columns at the data level; filter the picker too. */
        $nestedInsertable = $insertable;
        unset($nestedInsertable['columns']);

        $rootSuffix = self::path_to_action_suffix($parentPath);
        ?>
        <div class="lg-v2-mb-columns">
            <?php foreach ($columnsData as $colIdx => $col):
                $colBlocks = is_array($col['blocks'] ?? null) ? $col['blocks'] : [];
                $colPath   = array_merge($parentPath, ['columns', (int) $colIdx, 'blocks']);
                $colSuffix = $rootSuffix . '_c' . (int) $colIdx;
            ?>
                <div class="lg-v2-mb-column">
                    <div class="lg-v2-mb-column__hdr"><strong>Column <?php echo (int) $colIdx + 1; ?></strong></div>
                    <div class="lg-v2-mb-slots">
                        <?php if (!$colBlocks): ?>
                            <p class="lg-v2-mb-empty"><em>Empty column. Add a block below.</em></p>
                        <?php endif; ?>
                        <?php foreach ($colBlocks as $i => $child):
                            $ctype = sanitize_key((string) ($child['type'] ?? ''));
                            if (!isset($nestedInsertable[$ctype])) continue;
                            $childPath = array_merge($colPath, [(int) $i]);
                            self::render_block_slot($childPath, $ctype, is_array($child) ? $child : [], $insertable, $colCount);
                        endforeach; ?>
                    </div>
                    <?php self::render_add_block_ui($nestedInsertable, $colSuffix); ?>
                </div>
            <?php endforeach; ?>

            <div class="lg-v2-mb-column-ops">
                <?php if ($colCount < 3): ?>
                    <button type="submit" name="lg_v2_action"
                            value="add_column_<?php echo esc_attr($rootSuffix); ?>"
                            class="button">+ Add column</button>
                <?php endif; ?>
                <?php if ($colCount > 2): ?>
                    <button type="submit" name="lg_v2_action"
                            value="remove_column_<?php echo esc_attr($rootSuffix); ?>"
                            class="button"
                            onclick="return confirm('Remove the last column? Its blocks will be deleted.');">− Remove column</button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /** Add-block UI. The select's name and action value are suffixed by
     *  the container path so the save handler knows where to insert.
     *  Empty path = root. For a column, path is e.g. "2_c0" (root 2,
     *  column 0). */
    private static function render_add_block_ui(array $insertable, string $containerPath): void
    {
        $selectName  = 'lg_v2_add_block_type' . ($containerPath !== '' ? "_$containerPath" : '');
        $actionValue = 'add_block' . ($containerPath !== '' ? "_$containerPath" : '');
        $label       = $containerPath !== '' ? '+ Add to this column' : '+ Add Block';
        ?>
        <div class="lg-v2-mb-add">
            <label><strong>Add a block:</strong>
                <select name="<?php echo esc_attr($selectName); ?>">
                    <option value="">— choose type —</option>
                    <?php foreach ($insertable as $name => $desc): ?>
                        <option value="<?php echo esc_attr($name); ?>">
                            <?php echo esc_html($name); ?> — <?php echo esc_html($desc); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" name="lg_v2_action" value="<?php echo esc_attr($actionValue); ?>" class="button button-secondary">
                <?php echo esc_html($label); ?>
            </button>
        </div>
        <?php
    }

    /** path → "lg_v2_blocks[2]" or "lg_v2_blocks[2][columns][0][blocks][1]" */
    private static function path_to_name_prefix(array $path): string
    {
        $s = 'lg_v2_blocks';
        foreach ($path as $segment) $s .= '[' . $segment . ']';
        return $s;
    }

    /** path → "2" or "2_c0_1" — used in action values + DOM data-attrs.
     *  Literal 'columns' segments become a "c" prefix on the next index;
     *  literal 'blocks' segments are dropped (numeric indices alone are
     *  unambiguous at that level). */
    private static function path_to_action_suffix(array $path): string
    {
        $parts = [];
        $pendingCMarker = false;
        foreach ($path as $segment) {
            if ($segment === 'columns') { $pendingCMarker = true; continue; }
            if ($segment === 'blocks')  { continue; }
            if ($pendingCMarker) {
                $parts[] = 'c' . (string) $segment;
                $pendingCMarker = false;
            } else {
                $parts[] = (string) $segment;
            }
        }
        return implode('_', $parts);
    }

    /** Generic input/textarea for a scalar prop, namespaced under a name prefix. */
    private static function render_scalar_field(string $namePrefix, string $name, array $propDef, $value): void
    {
        $type = (string) ($propDef['type'] ?? 'string');
        $desc = (string) ($propDef['description'] ?? '');
        $isLongText = in_array($name, ['caption', 'description', 'body'], true)
            || ($propDef['format'] ?? '') === 'html';
        $field = $namePrefix . '[' . $name . ']';

        ?>
        <p class="lg-v2-mb-field">
            <label>
                <strong><?php echo esc_html($name); ?></strong>
                <?php if ($desc !== ''): ?>
                    <br /><span class="description"><?php echo esc_html($desc); ?></span>
                <?php endif; ?>
            </label>
            <?php if ($type === 'boolean'):
                /* Boolean: real checkbox + hidden companion so unchecked POSTs
                   as 0 (not absent). The hidden input goes FIRST so PHP's
                   $_POST takes the checkbox's value when checked.
                   Determine "checked" — value can be true/false/1/0/null.
                   If null (no value stored), fall back to manifest default. */
                $effective = $value;
                if ($effective === null) $effective = $propDef['default'] ?? false;
                $isChecked = filter_var($effective, FILTER_VALIDATE_BOOLEAN);
            ?>
                <input type="hidden" name="<?php echo esc_attr($field); ?>" value="0" />
                <label style="display:inline-flex;align-items:center;gap:6px;">
                    <input type="checkbox" name="<?php echo esc_attr($field); ?>" value="1"
                           <?php checked($isChecked); ?> />
                    <span class="description">Enabled</span>
                </label>
            <?php elseif ($isLongText): ?>
                <textarea name="<?php echo esc_attr($field); ?>" rows="2" class="widefat"
                ><?php echo esc_textarea((string) ($value ?? '')); ?></textarea>
            <?php elseif (!empty($propDef['enum']) && is_array($propDef['enum'])): ?>
                <select name="<?php echo esc_attr($field); ?>">
                    <?php foreach ($propDef['enum'] as $opt): ?>
                        <option value="<?php echo esc_attr((string) $opt); ?>" <?php selected((string) $opt, (string) $value); ?>>
                            <?php echo esc_html((string) $opt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="<?php echo $type === 'integer' ? 'number' : 'text'; ?>"
                       name="<?php echo esc_attr($field); ?>"
                       value="<?php echo esc_attr((string) ($value ?? '')); ?>"
                       class="widefat" />
            <?php endif; ?>
        </p>
        <?php
    }

    /** Repeater UI for an array_of_objects prop. Each row is rendered with
     *  the item sub-schema's prop list as inline fields, plus up/down/delete
     *  controls and a __pos hidden input for stable ordering across saves.
     *  A <template> sibling holds an empty row for the JS "+ Add row" button. */
    private static function render_repeater(string $namePrefix, string $propName, array $propDef, array $rows): void
    {
        $itemProps = is_array($propDef['items']['props'] ?? null) ? $propDef['items']['props'] : [];
        $field     = $namePrefix . '[' . $propName . ']';
        $desc      = (string) ($propDef['description'] ?? '');
        ?>
        <div class="lg-v2-mb-field lg-v2-mb-repeater" data-lg-repeater>
            <label class="lg-v2-mb-repeater__hdr">
                <strong><?php echo esc_html($propName); ?></strong>
                <?php if ($desc !== ''): ?>
                    <br /><span class="description"><?php echo esc_html($desc); ?></span>
                <?php endif; ?>
            </label>
            <div class="lg-v2-mb-repeater__rows" data-lg-repeater-rows>
                <?php foreach (array_values($rows) as $i => $row):
                    self::render_repeater_row($field, (string) $i, $itemProps, is_array($row) ? $row : [], $i);
                endforeach; ?>
            </div>
            <button type="button" class="button lg-v2-mb-repeater__add" data-lg-repeater-add>+ Add row</button>
            <template data-lg-repeater-template><?php
                self::render_repeater_row($field, '__INDEX__', $itemProps, [], 0);
            ?></template>
        </div>
        <?php
    }

    /** Single repeater row. $idx may be a real integer index or the literal
     *  string `__INDEX__` for the template (JS swaps it on insert). */
    private static function render_repeater_row(string $field, string $idx, array $itemProps, array $row, int $pos): void
    {
        $rowName = $field . '[' . $idx . ']';
        /* Pick a sensible primary set (icon/label/url/description) and put
           the rest behind a details disclosure so the row stays scannable.
           Variants only use a subset; "extras" lets authors fill in the
           variant-specific fields without crowding the common case. */
        $primary = ['icon', 'label', 'url', 'description'];
        $present = array_keys($itemProps);
        $extras  = array_values(array_diff($present, $primary));
        ?>
        <div class="lg-v2-mb-row" data-lg-row>
            <input type="hidden" name="<?php echo esc_attr($rowName . '[__pos]'); ?>"
                   value="<?php echo esc_attr((string) $pos); ?>" data-lg-row-pos />

            <div class="lg-v2-mb-row__ctrls">
                <button type="button" class="button lg-v2-mb-row__btn" data-lg-row-up    title="Move up">↑</button>
                <button type="button" class="button lg-v2-mb-row__btn" data-lg-row-down  title="Move down">↓</button>
                <button type="button" class="button lg-v2-mb-row__btn lg-v2-mb-remove"
                        data-lg-row-delete title="Remove row">&times;</button>
            </div>

            <div class="lg-v2-mb-row__fields">
                <?php foreach ($primary as $pk):
                    if (!isset($itemProps[$pk])) continue;
                    self::render_repeater_field($rowName, $pk, $itemProps[$pk], $row[$pk] ?? null);
                endforeach; ?>

                <?php if ($extras): ?>
                    <details class="lg-v2-mb-row__extras"<?php
                        /* Auto-open extras if any of them have values, so
                           authors don't lose track of variant-specific
                           fields they've already filled in. */
                        foreach ($extras as $ek) if (isset($row[$ek]) && $row[$ek] !== '') { echo ' open'; break; }
                    ?>>
                        <summary>More fields (<?php echo esc_html(implode(', ', $extras)); ?>)</summary>
                        <div class="lg-v2-mb-row__fields">
                            <?php foreach ($extras as $ek):
                                self::render_repeater_field($rowName, $ek, $itemProps[$ek], $row[$ek] ?? null);
                            endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /** Single field inside a repeater row. `icon`-named string props get a
     *  dropdown sourced from Icons::keys(); everything else is a text input
     *  (or select when the propDef has an enum). */
    private static function render_repeater_field(string $rowName, string $name, array $propDef, $value): void
    {
        $field = $rowName . '[' . $name . ']';
        $type  = (string) ($propDef['type'] ?? 'string');
        $desc  = (string) ($propDef['description'] ?? '');
        ?>
        <label class="lg-v2-mb-row__field lg-v2-mb-row__field--<?php echo esc_attr($name); ?>"
               <?php if ($desc !== ''): ?>title="<?php echo esc_attr($desc); ?>"<?php endif; ?>>
            <span><?php echo esc_html($name); ?></span>
            <?php if ($name === 'icon'): ?>
                <span class="lg-v2-mb-row__icon-wrap">
                    <select name="<?php echo esc_attr($field); ?>" data-lg-icon-select>
                        <?php foreach (Icons::keys() as $k): ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($k, (string) ($value ?? 'link')); ?>>
                                <?php echo esc_html($k); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="lg-v2-mb-row__icon-preview" data-lg-icon-preview><?php
                        echo Icons::svg((string) ($value ?? 'link'));
                    ?></span>
                </span>
            <?php elseif (!empty($propDef['enum']) && is_array($propDef['enum'])): ?>
                <select name="<?php echo esc_attr($field); ?>">
                    <?php foreach ($propDef['enum'] as $opt): ?>
                        <option value="<?php echo esc_attr((string) $opt); ?>" <?php selected((string) $opt, (string) ($value ?? '')); ?>>
                            <?php echo esc_html((string) $opt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="<?php echo $type === 'integer' ? 'number' : 'text'; ?>"
                       name="<?php echo esc_attr($field); ?>"
                       value="<?php echo esc_attr((string) ($value ?? '')); ?>" />
            <?php endif; ?>
        </label>
        <?php
    }

    /* ── Save ────────────────────────────────────────────────────────── */

    public static function save(int $post_id, \WP_Post $post): void
    {
        /* Diagnostic: log every save() entry with the relevant POST keys so
           we can tell whether button submits are reaching us with the
           expected action value. Remove once metabox import is reliable. */
        $diag = [
            'lg_v2_action'       => $_POST['lg_v2_action']               ?? '(unset)',
            'has_textarea'       => isset($_POST['lg_v2_import_json']),
            'textarea_len'       => isset($_POST['lg_v2_import_json']) ? strlen((string) wp_unslash($_POST['lg_v2_import_json'])) : 0,
            'has_nonce'          => isset($_POST[self::NONCE_NAME]),
            'is_autosave'        => wp_is_post_autosave($post_id) ? 'y' : 'n',
            'is_revision'        => wp_is_post_revision($post_id) ? 'y' : 'n',
            'post_type'          => $post->post_type,
            'lg_v2_post_keys'    => implode(',', array_filter(array_keys($_POST), fn($k) => str_starts_with($k, 'lg_v2_'))),
        ];
        self::dbg('ENTRY: ' . json_encode($diag));

        /* Standard guards. */
        if (wp_is_post_autosave($post_id)) return;
        if (wp_is_post_revision($post_id)) return;
        if (!in_array($post->post_type, Plugin::MANAGED_CPTS, true)) return;
        if (!isset($_POST[self::NONCE_NAME])) return;
        if (!wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $manifests = Manifest::all();
        $action    = isset($_POST['lg_v2_action']) ? (string) $_POST['lg_v2_action'] : '';

        /* Import JSON: replaces the entire layout from a pasted JSON blob.
           Handled before slot parsing because the slots in the form are
           the old layout, irrelevant if we're replacing wholesale. Same
           contract as `wp lg-layout-v2 import` — validates, refuses on
           fatal, surfaces errors via the same transient the slot path
           uses. */
        if ($action === 'import_json' || $action === 'validate_json') {
            self::handle_json_import($post_id, $action, $manifests);
            return;
        }

        /* 1. Parse the existing slots from POST. Each carries a __pos for
              physical ordering (JS bumps these on move up/down). */
        $rawBlocks = is_array($_POST['lg_v2_blocks'] ?? null) ? wp_unslash($_POST['lg_v2_blocks']) : [];

        self::dbg(sprintf(
            'save: post=%d action=%s incoming_slots=%d',
            $post_id, $action !== '' ? $action : '(update)', count($rawBlocks)
        ));

        $parsed = self::parse_slots_recursive($rawBlocks, $manifests, /* atRoot */ true);

        /* 2. Apply slot-level actions. Grammar:
                add_block                       → append at root
                add_block_{N}_c{C}              → append inside root[N].columns[C].blocks
                remove_block_{N}                → remove root[N]
                remove_block_{N}_c{C}_{M}       → remove root[N].columns[C].blocks[M]
                add_column_{N}                  → push empty bucket onto root[N].columns
                remove_column_{N}               → pop the last bucket off root[N].columns */
        if (preg_match('/^remove_block_(\d+)(?:_c(\d+)_(\d+))?$/', $action, $m)) {
            $rootSlot = (int) $m[1];
            $colIdx   = isset($m[2]) ? (int) $m[2] : null;
            $childIdx = isset($m[3]) ? (int) $m[3] : null;
            if ($colIdx === null) {
                $parsed = array_values(array_filter($parsed, fn($p) => $p['slot'] !== $rootSlot));
            } else {
                foreach ($parsed as &$p) {
                    if ($p['slot'] !== $rootSlot || ($p['block']['type'] ?? '') !== 'columns') continue;
                    if (!isset($p['block']['columns'][$colIdx]['blocks']) || !is_array($p['block']['columns'][$colIdx]['blocks'])) continue;
                    $p['block']['columns'][$colIdx]['blocks'] = array_values(array_filter(
                        $p['block']['columns'][$colIdx]['blocks'],
                        fn($_, $i) => $i !== $childIdx,
                        ARRAY_FILTER_USE_BOTH
                    ));
                    break;
                }
                unset($p);
            }
        }
        if (preg_match('/^add_block(?:_(\d+)_c(\d+))?$/', $action, $m)) {
            $rootSlot = isset($m[1]) ? (int) $m[1] : null;
            $colIdx   = isset($m[2]) ? (int) $m[2] : null;
            $selectKey = $rootSlot === null
                ? 'lg_v2_add_block_type'
                : "lg_v2_add_block_type_{$rootSlot}_c{$colIdx}";
            $addType = isset($_POST[$selectKey]) ? sanitize_key((string) $_POST[$selectKey]) : '';
            if (isset($manifests[$addType])) {
                $newBlock = ['type' => $addType, 'id' => 'b_' . wp_generate_password(6, false)];
                if ($rootSlot === null) {
                    $parsed[] = [
                        'pos'   => count($parsed),
                        'block' => $newBlock,
                        'slot'  => count($parsed),
                    ];
                } else {
                    if ($addType === 'columns') {
                        self::dbg("save: refused to add nested columns inside slot $rootSlot col $colIdx");
                    } else {
                        foreach ($parsed as &$p) {
                            if ($p['slot'] !== $rootSlot || ($p['block']['type'] ?? '') !== 'columns') continue;
                            $p['block']['columns']                       = $p['block']['columns']                       ?? [];
                            $p['block']['columns'][$colIdx]              = $p['block']['columns'][$colIdx]              ?? [];
                            $p['block']['columns'][$colIdx]['blocks']    = $p['block']['columns'][$colIdx]['blocks']    ?? [];
                            $p['block']['columns'][$colIdx]['blocks'][]  = $newBlock;
                            break;
                        }
                        unset($p);
                    }
                }
            }
        }
        if (preg_match('/^move_block_(\d+)_c(\d+)_(\d+)_to_c(\d+)$/', $action, $m)) {
            $rootSlot = (int) $m[1];
            $fromCol  = (int) $m[2];
            $childIdx = (int) $m[3];
            $toCol    = (int) $m[4];
            foreach ($parsed as &$p) {
                if ($p['slot'] !== $rootSlot || ($p['block']['type'] ?? '') !== 'columns') continue;
                if (!isset($p['block']['columns'][$fromCol]['blocks'][$childIdx])) break;
                if (!isset($p['block']['columns'][$toCol]))                          break;
                $moved = $p['block']['columns'][$fromCol]['blocks'][$childIdx];
                unset($p['block']['columns'][$fromCol]['blocks'][$childIdx]);
                $p['block']['columns'][$fromCol]['blocks'] = array_values($p['block']['columns'][$fromCol]['blocks']);
                $p['block']['columns'][$toCol]['blocks']   = $p['block']['columns'][$toCol]['blocks'] ?? [];
                $p['block']['columns'][$toCol]['blocks'][] = $moved;
                break;
            }
            unset($p);
        }
        if (preg_match('/^add_column_(\d+)$/', $action, $m)) {
            $rootSlot = (int) $m[1];
            foreach ($parsed as &$p) {
                if ($p['slot'] !== $rootSlot || ($p['block']['type'] ?? '') !== 'columns') continue;
                $p['block']['columns']   = $p['block']['columns'] ?? [];
                if (count($p['block']['columns']) >= 3) break;   /* enforce 2|3 cap */
                $p['block']['columns'][] = ['blocks' => []];
                break;
            }
            unset($p);
        }
        if (preg_match('/^remove_column_(\d+)$/', $action, $m)) {
            $rootSlot = (int) $m[1];
            foreach ($parsed as &$p) {
                if ($p['slot'] !== $rootSlot || ($p['block']['type'] ?? '') !== 'columns') continue;
                if (!isset($p['block']['columns']) || !is_array($p['block']['columns'])) break;
                if (count($p['block']['columns']) <= 2) break;   /* enforce 2|3 floor */
                array_pop($p['block']['columns']);
                break;
            }
            unset($p);
        }

        $blocks = array_map(fn($p) => $p['block'], $parsed);

        $layout = [
            'schema' => 1,
            '_meta'  => ['title' => $post->post_title, 'post_id' => $post_id],
            'blocks' => $blocks,
        ];

        $errors = Validator::validate($layout, $manifests);
        $fatal  = array_filter($errors, fn($e) => !empty($e['fatal']));
        if ($fatal) {
            self::dbg('save: rejected with ' . count($fatal) . ' fatal validation error(s)');
            set_transient(self::TRANSIENT_ERR . $post_id, array_values($fatal), 60);
            return;
        }

        update_post_meta($post_id, LG_LAYOUT_V2_META_KEY, $layout);
        self::dbg(sprintf('save: persisted %d blocks (%s)', count($blocks),
            implode(', ', array_map(fn($b) => $b['type'], $blocks))));

        /* Legacy cleanup: pre-meta-cache, WpRenderer stored rendered HTML in
           post_content. New posts no longer hit that path, but existing v2
           posts still carry rendered HTML there which clutters the WYSIWYG
           view + post revisions. Clear it on every save so the migration
           happens organically. Safe to do unconditionally: post_content has
           no other meaning on v2-managed posts. */
        if ($post->post_content !== '') {
            remove_action('save_post', [self::class, 'save'], 10);
            wp_update_post(['ID' => $post_id, 'post_content' => '']);
            add_action('save_post', [self::class, 'save'], 10, 2);
        }
    }

    /** Handle the Import / Validate-only paths from the paste-JSON widget.
     *  Parses the textarea, validates, optionally writes meta + bumps the
     *  global render cache epoch. Errors land in the same transient the
     *  rest of save() uses so they render at the top of the metabox on
     *  the next page load. */
    private static function handle_json_import(int $post_id, string $action, array $manifests): void
    {
        $raw = isset($_POST['lg_v2_import_json']) ? (string) wp_unslash($_POST['lg_v2_import_json']) : '';
        $raw = trim($raw);
        if ($raw === '') {
            set_transient(self::TRANSIENT_ERR . $post_id, [['path' => '/', 'msg' => 'Import textarea was empty.', 'fatal' => true]], 60);
            self::dbg("import_json: empty textarea, no-op");
            return;
        }
        $layout = json_decode($raw, true);
        if (!is_array($layout)) {
            set_transient(self::TRANSIENT_ERR . $post_id, [['path' => '/', 'msg' => 'Pasted content is not valid JSON: ' . json_last_error_msg(), 'fatal' => true]], 60);
            self::dbg("import_json: json_decode failed — " . json_last_error_msg());
            return;
        }
        $errors = Validator::validate($layout, $manifests);
        $fatal  = array_values(array_filter($errors, fn($e) => !empty($e['fatal'])));
        if ($fatal) {
            set_transient(self::TRANSIENT_ERR . $post_id, $fatal, 60);
            self::dbg(sprintf('import_json: rejected with %d fatal error(s)', count($fatal)));
            return;
        }
        if ($action === 'validate_json') {
            /* Validate-only: surface a friendly "passed" notice via the
               same transient channel. The render code distinguishes by
               the absence of `fatal` on every entry. */
            $count = count(is_array($layout['blocks'] ?? null) ? $layout['blocks'] : []);
            set_transient(self::TRANSIENT_ERR . $post_id, [['path' => '/', 'msg' => "Validate-only: JSON is valid ($count blocks). Click Import & Replace to apply.", 'fatal' => false]], 30);
            self::dbg(sprintf('validate_json: clean (%d blocks), no write', $count));
            return;
        }
        /* Real import. */
        update_post_meta($post_id, LG_LAYOUT_V2_META_KEY, $layout);
        update_option('lg_layout_v2_cache_epoch', time(), true);
        self::dbg(sprintf('import_json: replaced layout with %d blocks', count($layout['blocks'] ?? [])));
    }

    /** Single error_log entry point with consistent prefix. error_log routes
     *  to wp-content/debug.log when WP_DEBUG_LOG is true, else to PHP's own
     *  error log (php-fpm pool log for this site). */
    private static function dbg(string $msg): void
    {
        error_log('[lg-layout-v2 metabox] ' . $msg);
    }

    /** Walk a raw $_POST slot array. Returns [{pos, block, slot}, …] sorted
     *  by __pos. For columns blocks (root-level only — validator forbids
     *  nesting), recurses into raw['columns'][*]['blocks'] and stores the
     *  parsed children under block['columns'][i]['blocks']. */
    private static function parse_slots_recursive(array $rawSlots, array $manifests, bool $atRoot): array
    {
        $parsed = [];
        foreach ($rawSlots as $slotKey => $raw) {
            if (!is_array($raw)) {
                self::dbg("  slot[$slotKey]: non-array, skipped");
                continue;
            }
            $type = sanitize_key((string) ($raw['type'] ?? ''));
            if (!isset($manifests[$type])) {
                self::dbg("  slot[$slotKey]: unknown type '$type', dropped");
                continue;
            }
            $pos   = isset($raw['__pos']) ? (int) $raw['__pos'] : (int) $slotKey;
            $block = self::build_block_from_raw($type, $raw, $manifests[$type]);

            if ($type === 'columns' && $atRoot && isset($raw['columns']) && is_array($raw['columns'])) {
                $cols = [];
                /* PHP form arrays preserve numeric-string keys; iterate in
                   submitted order. We don't sort columns themselves (no
                   __pos at the column level — the user reorders columns
                   via Add/Remove Column, not move arrows). */
                foreach ($raw['columns'] as $colKey => $colRaw) {
                    if (!is_array($colRaw)) continue;
                    $rawChildren = is_array($colRaw['blocks'] ?? null) ? $colRaw['blocks'] : [];
                    $childParsed = self::parse_slots_recursive($rawChildren, $manifests, /* atRoot */ false);
                    $cols[] = ['blocks' => array_map(fn($p) => $p['block'], $childParsed)];
                }
                if ($cols) $block['columns'] = $cols;
            }

            if ($type === 'image' && empty($block['image_id'])) {
                self::dbg("  slot[$slotKey]: image with no image_id (stub or unset picker)");
            }
            $parsed[] = ['pos' => $pos, 'block' => $block, 'slot' => (int) $slotKey];
        }
        usort($parsed, fn($a, $b) => $a['pos'] - $b['pos']);
        return $parsed;
    }

    /** Build one block array from its raw POST slice + manifest. */
    private static function build_block_from_raw(string $type, array $raw, array $m): array
    {
        $block = [
            'type' => $type,
            'id'   => isset($raw['id']) && is_string($raw['id']) && $raw['id'] !== '' ? $raw['id'] : 'b_' . wp_generate_password(6, false),
        ];

        $picker = $m['editor']['custom_picker'] ?? null;
        if ($picker) {
            $pickerProps = EditorPickers::sanitize($picker, $raw);
            $block = array_merge($block, $pickerProps);
        }

        $owned = EditorPickers::owned_props($picker);
        foreach ($m['schema']['props'] as $propName => $propDef) {
            if (in_array($propName, $owned, true)) continue;
            $ptype = (string) ($propDef['type'] ?? 'string');

            /* array_of_objects: repeater rows come in as
                  $raw[$propName][$i] = ['__pos'=>N, 'icon'=>'…', 'label'=>'…', …]
               Sort by __pos so drag/up-down order is preserved, then
               sanitize each row's keys against the items sub-schema. */
            if ($ptype === 'array_of_objects') {
                if (array_key_exists($propName, $raw) && is_array($raw[$propName])) {
                    $itemPropDefs = $propDef['items']['props'] ?? [];
                    $parsed = [];
                    foreach ($raw[$propName] as $rkey => $rowRaw) {
                        if (!is_array($rowRaw)) continue;
                        $pos = isset($rowRaw['__pos']) ? (int) $rowRaw['__pos'] : (int) $rkey;
                        $cleanRow = [];
                        foreach ($itemPropDefs as $rk => $rdef) {
                            if (!array_key_exists($rk, $rowRaw)) continue;
                            $rv = $rowRaw[$rk];
                            if (!is_scalar($rv)) continue;
                            $rt = (string) ($rdef['type'] ?? 'string');
                            if ($rt === 'integer')     $cleanRow[$rk] = (int) $rv;
                            elseif ($rt === 'boolean') $cleanRow[$rk] = (bool) $rv;
                            else {
                                $s = sanitize_text_field((string) $rv);
                                if ($s !== '') $cleanRow[$rk] = $s;
                            }
                        }
                        /* Drop rows where every value equals its manifest
                           default — covers the "added a row then submitted
                           without typing anything" case where the icon
                           select still posts its default key. */
                        $hasContent = false;
                        foreach ($cleanRow as $rk => $rv) {
                            $default = $itemPropDefs[$rk]['default'] ?? '';
                            if ((string) $rv !== (string) $default) { $hasContent = true; break; }
                        }
                        if ($hasContent) $parsed[] = ['pos' => $pos, 'row' => $cleanRow];
                    }
                    usort($parsed, fn($a, $b) => $a['pos'] - $b['pos']);
                    $block[$propName] = array_map(fn($p) => $p['row'], $parsed);
                }
                continue;
            }

            if (!array_key_exists($propName, $raw)) continue;
            /* Array/object props are structural (e.g. columns.columns,
               future containers). Their content lives at named sub-paths
               and is handled by parse_slots_recursive — skip here. */
            if ($ptype === 'array' || $ptype === 'object') continue;

            $v = $raw[$propName];
            if (!is_scalar($v) && $v !== null) continue;
            if ($ptype === 'integer') {
                $iv = (int) $v;
                if ($iv !== 0) $block[$propName] = $iv;
            } elseif ($ptype === 'boolean') {
                $block[$propName] = (bool) $v;
            } elseif (($propDef['format'] ?? '') === 'html') {
                /* HTML-format string props (e.g. callout body): preserve
                   author tags; strip dangerous markup via wp_kses_post. */
                $sv = wp_kses_post(trim((string) $v));
                if ($sv !== '') $block[$propName] = $sv;
            } else {
                $sv = sanitize_textarea_field((string) $v);
                if ($sv !== '') $block[$propName] = $sv;
            }
        }
        return $block;
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    /** name → one-line description for every block manifest with editor.insertable. */
    private static function insertable_blocks(): array
    {
        $out = [];
        foreach (Manifest::all() as $name => $m) {
            if (empty($m['editor']['insertable'])) continue;
            $out[$name] = (string) ($m['description'] ?? '');
        }
        return $out;
    }
}
