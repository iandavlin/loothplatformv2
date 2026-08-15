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
    /** Registered picker names. Keep alphabetized. bin/lint-block.php reads
     *  THIS list (M7) rather than keeping its own copy — three registries had
     *  already drifted apart: the linter knew a 'gallery-items' that nothing
     *  implements while rejecting the 'gallery' that blocks/gallery actually
     *  declares and lg-fe-editor.js actually runs.
     *
     *  ⚠️ `gallery` and `embed-url` are FRONT-END-EDITOR ONLY: lg-fe-editor.js
     *  runs them, but there is no render()/sanitize() arm below, so the admin
     *  metabox cannot edit those props. Pre-existing gap, recorded not fixed. */
    public const KNOWN = ['embed-url', 'file', 'gallery', 'image', 'license-choice', 'rich-text'];

    /** Manifest prop names a picker owns. These are excluded from the metabox's
     *  generic-field walker so the picker has exclusive control. */
    public static function owned_props(?string $name): array
    {
        return match ($name) {
            'image'          => ['image_id', 'url'],
            'rich-text'      => ['html'],
            'license-choice' => ['code'],
            'file'           => ['file_id', 'url'],
            default          => [],
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
            'image'          => self::render_image($block, $namePrefix, $domSuffix),
            'rich-text'      => self::render_rich_text($block, $namePrefix, $domSuffix),
            'license-choice' => self::render_license_choice($block, $namePrefix, $domSuffix),
            'file'           => self::render_file($block, $namePrefix, $domSuffix),
            default          => '',
        };
    }

    /** Read picker-owned form fields out of POST and return props to merge into
     *  the saved block. */
    public static function sanitize(string $name, array $post): array
    {
        return match ($name) {
            'image'          => self::sanitize_image($post),
            'rich-text'      => self::sanitize_rich_text($post),
            'license-choice' => self::sanitize_license_choice($post),
            'file'           => self::sanitize_file($post),
            default          => [],
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

    /* ── license-choice picker ────────────────────────────────────────── */

    /**
     * Licence chooser. The four Creative Commons licences as radio CHOICES,
     * plus the default "follow the post" option that clears `code` back to ''.
     *
     * This picker is the reason the license block exists. The licence used to
     * live in a callout's inline-editable `body`, which meant the only way to
     * change it was to retype the sentence — so a typo silently became the
     * licence, and nothing on the page knew what the licence meant.
     */
    private static function render_license_choice(array $block, string $namePrefix, string $domSuffix): string
    {
        $current = is_string($block['code'] ?? null) ? strtolower(trim((string) $block['code'])) : '';
        if ($current !== '' && !Licenses::is_valid($current)) $current = '';

        $field = $namePrefix . '[code]';
        $group = 'lg_v2_license_' . $domSuffix;

        ob_start();
        ?>
        <div class="lg-v2-mb-picker lg-v2-mb-picker--license" data-picker="license-choice">
            <?php foreach (Licenses::picker_choices() as $choice):
                $code = (string) $choice['code'];
                $id   = $group . '_' . ($code === '' ? 'follow' : str_replace('-', '_', $code));
            ?>
                <p class="lg-v2-mb-license-choice">
                    <label for="<?php echo esc_attr($id); ?>">
                        <input type="radio"
                               id="<?php echo esc_attr($id); ?>"
                               name="<?php echo esc_attr($field); ?>"
                               value="<?php echo esc_attr($code); ?>"
                               <?php checked($current, $code); ?> />
                        <strong><?php echo esc_html((string) $choice['label']); ?></strong>
                        <span class="description"><?php echo esc_html((string) $choice['hint']); ?></span>
                    </label>
                </p>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Read the chosen licence back. An unrecognized code collapses to '' —
     * "follow the post" — rather than being stored: a bad code must never be
     * persisted as if it were a licence.
     */
    private static function sanitize_license_choice(array $post): array
    {
        $code = isset($post['code']) && is_string($post['code']) ? strtolower(trim($post['code'])) : '';
        if ($code !== '' && !Licenses::is_valid($code)) $code = '';
        return ['code' => $code];
    }

    /* ── file picker ──────────────────────────────────────────────────── */

    /**
     * Attachment picker for the `download` block — any mime type, not just
     * images. The block had NO editor affordance at all before this: empty
     * inline_editable_props and a null picker, so the one control a member most
     * needs (swap the print file) could not be reached from the page.
     *
     * Clearing the file is a real choice, not a mistake: an EMPTY file_id means
     * "follow the post", and the download block then resolves the post's own
     * print file at render. That is the state that cannot go stale, so the UI
     * says so rather than treating empty as broken.
     */
    private static function render_file(array $block, string $namePrefix, string $domSuffix): string
    {
        $id   = (int) ($block['file_id'] ?? 0);
        $name = $namePrefix . '[file_id]';

        $filename = '';
        if ($id > 0) {
            $path = function_exists('get_attached_file') ? (string) get_attached_file($id) : '';
            $filename = $path !== '' ? basename($path) : (string) get_the_title($id);
        }

        ob_start();
        ?>
        <div class="lg-v2-mb-picker lg-v2-mb-picker--file" data-picker="file">
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $id); ?>" data-lg-file-id />
            <p data-lg-file-label>
                <?php if ($id > 0): ?>
                    <strong><?php echo esc_html($filename); ?></strong>
                <?php else: ?>
                    <em>Following the post’s own file.</em>
                <?php endif; ?>
            </p>
            <p>
                <button type="button" class="button" data-lg-file-pick><?php echo $id > 0 ? 'Change file' : 'Choose a file'; ?></button>
                <button type="button" class="button-link" data-lg-file-clear
                        <?php echo $id > 0 ? '' : 'style="display:none"'; ?>>Follow the post instead</button>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** 0 is meaningful here — it is "follow the post" — so it is STORED, not
     *  dropped the way the image picker drops an unset id. */
    private static function sanitize_file(array $post): array
    {
        $id = isset($post['file_id']) ? (int) $post['file_id'] : 0;
        return ['file_id' => max(0, $id)];
    }
}
