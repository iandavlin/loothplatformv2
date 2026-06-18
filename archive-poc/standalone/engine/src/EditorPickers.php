<?php
/**
 * EditorPickers — registry of UI for non-scalar block props.
 *
 * The MetaBox + (future) inline editor generate most fields by walking each
 * block's manifest props. But some props don't fit a text input: image
 * attachments need a media picker, files need an attachment picker, URLs need
 * an embed-preview, columns need a nested layout editor, etc.
 *
 * Each picker is a named handler registered here. A block's manifest declares
 * which picker it wants via `editor.custom_picker`; the editor framework
 * dispatches to the matching entry in this class.
 *
 * Adding a new picker = new method here + UI hook in admin-metabox.js. The
 * blocks that use it just reference the picker by name in their manifest —
 * no per-block PHP.
 *
 * Read docs/MANIFEST.md#editor before adding a picker.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class EditorPickers
{
    /** Registered picker names. Keep alphabetized. The block-linter checks
     *  manifest.editor.custom_picker against this list. */
    public const KNOWN = ['image', 'rich-text'];

    /** Manifest prop names a picker owns. These are excluded from the metabox's
     *  generic-field walker so the picker has exclusive control. */
    public static function owned_props(?string $name): array
    {
        return match ($name) {
            'image'     => ['image_id', 'url'],
            'rich-text' => ['html'],
            default     => [],
        };
    }

    /** Render the picker UI as HTML.
     *
     *  $block       — the current block JSON
     *  $namePrefix  — everything *before* the prop key in the field name,
     *                 e.g. "lg_v2_blocks[2]" for a root slot or
     *                 "lg_v2_blocks[2][children][1]" for a nested child
     *  $domSuffix   — slug-safe disambiguator for DOM IDs (wp_editor needs
     *                 globally unique IDs), e.g. "2" or "2_1" */
    public static function render(string $name, array $block, string $namePrefix = 'lg_v2_blocks[0]', string $domSuffix = '0'): string
    {
        return match ($name) {
            'image'     => self::render_image($block, $namePrefix, $domSuffix),
            'rich-text' => self::render_rich_text($block, $namePrefix, $domSuffix),
            default     => '',
        };
    }

    /** Read picker-owned form fields out of POST and return props to merge into
     *  the saved block. */
    public static function sanitize(string $name, array $post): array
    {
        return match ($name) {
            'image'     => self::sanitize_image($post),
            'rich-text' => self::sanitize_rich_text($post),
            default     => [],
        };
    }

    /* ── image picker ─────────────────────────────────────────────────── */

    /**
     * Image picker. Stores the attachment ID under the `image_id` key (NOT
     * `id`) to match what blocks/image/render.php currently reads. The
     * manifest's declared prop name is `id`; that mismatch is tracked
     * separately and intentionally not touched in this commit.
     */
    private static function render_image(array $block, string $namePrefix, string $domSuffix): string
    {
        $id   = (int) ($block['image_id'] ?? 0);
        $alt  = (string) ($block['alt'] ?? '');
        $thumbUrl = $id > 0 ? (string) (wp_get_attachment_image_url($id, 'medium') ?: '') : '';
        $name = $namePrefix . '[image_id]';

        ob_start();
        ?>
        <div class="lg-v2-mb-picker lg-v2-mb-picker--image" data-picker="image">
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $id); ?>" data-lg-image-id />

            <div class="lg-v2-mb-thumb" data-lg-image-preview>
                <?php if ($thumbUrl !== ''): ?>
                    <img src="<?php echo esc_url($thumbUrl); ?>" alt="<?php echo esc_attr($alt); ?>" />
                <?php else: ?>
                    <span class="lg-v2-mb-thumb__empty">No image selected.</span>
                <?php endif; ?>
            </div>

            <p>
                <button type="button" class="button" data-lg-image-pick>
                    <?php echo $id > 0 ? 'Change image' : 'Choose image'; ?>
                </button>
                <button type="button" class="button-link" data-lg-image-clear
                        <?php echo $id > 0 ? '' : 'style="display:none"'; ?>>Remove</button>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function sanitize_image(array $post): array
    {
        $id = isset($post['image_id']) ? (int) $post['image_id'] : 0;
        if ($id <= 0) return [];

        $out = ['image_id' => $id];
        /* Pre-resolve URL so the renderer doesn't have to look it up at every
           cache miss. Falls back to the renderer's resolver if absent. */
        $url = (string) (wp_get_attachment_image_url($id, 'full') ?: '');
        if ($url !== '') $out['url'] = $url;
        return $out;
    }

    /* ── rich-text picker ─────────────────────────────────────────────── */

    /**
     * Rich-text picker. Uses wp_editor() to mount TinyMCE on a textarea.
     * The editor's ID is a fixed slug; the metabox emits one picker per
     * block panel and assumes there's only one rich-text picker active on
     * the page (single-block scope). When multi-block lands the slug will
     * need per-block disambiguation.
     */
    private static function render_rich_text(array $block, string $namePrefix, string $domSuffix): string
    {
        $html = is_string($block['html'] ?? null) ? $block['html'] : '';
        /* Each editor instance needs a unique DOM id, otherwise wp_editor()
           silently fails to mount the second one. domSuffix encodes the
           full path (e.g. "2_1" for child 1 of root 2) so nested editors
           don't collide. */
        $editorId = "lg_v2_block_html_$domSuffix";
        $textareaName = $namePrefix . '[html]';

        ob_start();
        ?>
        <div class="lg-v2-mb-picker lg-v2-mb-picker--rich-text" data-picker="rich-text">
            <?php
            wp_editor($html, $editorId, [
                'textarea_name' => $textareaName,
                'textarea_rows' => 12,
                'media_buttons' => true,
                'teeny'         => false,
                'tinymce' => [
                    'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo',
                    'toolbar2' => '',
                    'block_formats' => 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4',
                ],
                'quicktags' => ['buttons' => 'strong,em,link,ul,ol,li,h2,h3,img,blockquote'],
            ]);
            ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Sanitize the rich-text HTML with wp_kses_post — strips <script>, event
     * handlers, and other dangerous markup while keeping standard editorial
     * HTML (p / h2 / h3 / ul / ol / a / strong / em / blockquote / etc.).
     */
    private static function sanitize_rich_text(array $post): array
    {
        $raw = $post['html'] ?? '';
        if (!is_string($raw)) return [];
        $clean = wp_kses_post(trim($raw));
        if ($clean === '') return [];
        return ['html' => $clean];
    }
}
