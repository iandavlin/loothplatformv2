<?php
/**
 * EditorRest — REST endpoints for the front-end inline editor.
 *
 * Phase 4, slice 2. Four operations, all under /lg-layout-v2/v1/blocks/:
 *   POST update  — mutate schema.props of the block at `path`
 *   POST insert  — add a new block at `parent_path`.blocks[`index`]
 *   POST delete  — remove the block at `path`
 *   POST move    — move from `from` to `to_parent`.blocks[`to_index`]
 *
 * Path format: array of segments addressing a node in the layout JSON.
 *   - Root block i:                       [i]
 *   - Block j in column c of root block i: [i, "columns", c, "blocks", j]
 * Nested columns are rejected by the validator, so depth is bounded at 2.
 *
 * Security: every endpoint re-checks FeEditor::can_edit() against the
 * payload's post_id (NOT the referrer), gated behind a wp_rest nonce.
 * Styling-related fields are silently dropped — only `schema.props`
 * fields declared in the block's manifest are mutable here.
 *
 * Cache invalidation: on every successful save we update post meta
 * (which fires Plugin::on_post_meta_changed → clears per-post cache)
 * AND bump lg_layout_v2_cache_epoch so any cached HTML key changes.
 */

declare(strict_types=1);

namespace LG\LayoutV2;

final class EditorRest
{
    public const NAMESPACE = 'lg-layout-v2/v1';

    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        $args_post_path = [
            'post_id' => ['required' => true, 'type' => 'integer'],
            'path'    => ['required' => true, 'type' => 'array'],
        ];
        register_rest_route(self::NAMESPACE, '/blocks/update', [
            'methods'             => 'POST',
            'permission_callback' => [self::class, 'permission_check'],
            'callback'            => [self::class, 'handle_update'],
            'args'                => $args_post_path + [
                'props' => ['required' => true, 'type' => 'object'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/blocks/insert', [
            'methods'             => 'POST',
            'permission_callback' => [self::class, 'permission_check'],
            'callback'            => [self::class, 'handle_insert'],
            'args'                => [
                'post_id'     => ['required' => true, 'type' => 'integer'],
                'parent_path' => ['required' => true, 'type' => 'array'],
                'index'       => ['required' => true, 'type' => 'integer'],
                'block'       => ['required' => true, 'type' => 'object'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/blocks/delete', [
            'methods'             => 'POST',
            'permission_callback' => [self::class, 'permission_check'],
            'callback'            => [self::class, 'handle_delete'],
            'args'                => $args_post_path,
        ]);
        register_rest_route(self::NAMESPACE, '/blocks/move', [
            'methods'             => 'POST',
            'permission_callback' => [self::class, 'permission_check'],
            'callback'            => [self::class, 'handle_move'],
            'args'                => [
                'post_id'   => ['required' => true, 'type' => 'integer'],
                'from'      => ['required' => true, 'type' => 'array'],
                'to_parent' => ['required' => true, 'type' => 'array'],
                'to_index'  => ['required' => true, 'type' => 'integer'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/user-meta/update', [
            'methods'             => 'POST',
            'permission_callback' => [self::class, 'user_meta_permission_check'],
            'callback'            => [self::class, 'handle_user_meta_update'],
            'args'                => [
                'user_id'  => ['required' => true, 'type' => 'integer'],
                'meta_key' => ['required' => true, 'type' => 'string'],
                'value'    => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }

    /** Keys the FE editor is allowed to write via the user-meta endpoint.
     *  Bio, contact, and social links — never roles, capabilities, or anything
     *  security-relevant. Add to this list deliberately. */
    public const USER_META_ALLOWED = [
        'author_about',
        'author_website', 'author_instagram', 'author_facebook',
        'author_youtube', 'author_linktree', 'author_looth_group_profile',
    ];

    /** Admins can edit anyone's allowed meta; non-admin users can edit only
     *  their own. The meta key must be on the allowlist. */
    public static function user_meta_permission_check(\WP_REST_Request $req): bool
    {
        $user_id  = (int) $req->get_param('user_id');
        $meta_key = (string) $req->get_param('meta_key');
        if ($user_id <= 0 || $meta_key === '') return false;
        if (!in_array($meta_key, self::USER_META_ALLOWED, true)) return false;
        if (current_user_can('edit_users')) return true;
        if (current_user_can('manage_options')) return true;
        return get_current_user_id() === $user_id;
    }

    public static function handle_user_meta_update(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        $user_id  = (int) $req->get_param('user_id');
        $meta_key = (string) $req->get_param('meta_key');
        $value    = (string) $req->get_param('value');

        /* Light sanitization — strip script-tag content; allow basic
           formatting markers (the bio gets rendered as plain text via
           htmlspecialchars on the way back out, so HTML doesn't execute,
           but stripping <script>/<iframe> here keeps the stored value
           clean for any other consumer). */
        $clean = wp_kses_post($value);
        update_user_meta($user_id, $meta_key, $clean);

        return new \WP_REST_Response([
            'ok'       => true,
            'user_id'  => $user_id,
            'meta_key' => $meta_key,
            'value'    => $clean,
        ], 200);
    }

    /**
     * Permission gate: same predicate as FeEditor — admin OR post_author of
     * THIS specific post id (from the payload, not the referrer). The
     * wp_rest nonce is enforced by WP's cookie auth handler when present.
     */
    public static function permission_check(\WP_REST_Request $req): bool
    {
        $post_id = (int) $req->get_param('post_id');
        if ($post_id <= 0) return false;
        $post = get_post($post_id);
        return FeEditor::can_edit($post);
    }

    /* ── Handlers ──────────────────────────────────────────────────── */

    public static function handle_update(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        return self::with_layout($req, function (array &$layout) use ($req) {
            $path  = self::normalize_path($req->get_param('path'));
            $props = (array) $req->get_param('props');

            $block = &self::deref($layout, $path);
            if ($block === null) return new \WP_Error('lg_path_not_found', 'block not found at path', ['status' => 404]);

            $type = (string) ($block['type'] ?? '');
            if (!in_array($type, Manifest::list(), true)) return new \WP_Error('lg_unknown_type', 'unknown block type', ['status' => 422]);
            $manifest = Manifest::get($type);

            /* Only allow keys declared in schema.props. Drop styling /
               unknown keys silently — they would be rejected by the
               validator anyway, but dropping is friendlier. */
            $allowed = array_keys($manifest['schema']['props'] ?? []);
            foreach ($props as $k => $v) {
                if (!in_array($k, $allowed, true)) continue;
                $block[$k] = self::sanitize_value($v);
            }

            /* Block-level fields (not part of schema.props). gated_tier is
               structural — gates visibility, not styling — so the editor
               needs to set it. Empty string / null clears the gate. */
            if ($req->has_param('gated_tier')) {
                $tier = $req->get_param('gated_tier');
                if ($tier === null || $tier === '') {
                    unset($block['gated_tier']);
                } else {
                    $block['gated_tier'] = sanitize_key((string) $tier);
                }
            }
            return null;
        });
    }

    public static function handle_insert(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        return self::with_layout($req, function (array &$layout) use ($req) {
            $parent = self::normalize_path($req->get_param('parent_path'));
            $index  = max(0, (int) $req->get_param('index'));
            $block  = (array) $req->get_param('block');

            $type = (string) ($block['type'] ?? '');
            if (!in_array($type, Manifest::list(), true)) return new \WP_Error('lg_unknown_type', 'unknown block type', ['status' => 422]);

            $bucket = &self::children_bucket($layout, $parent);
            if ($bucket === null) return new \WP_Error('lg_bad_parent', 'parent path does not point to a container', ['status' => 422]);

            $index = min($index, count($bucket));
            array_splice($bucket, $index, 0, [self::sanitize_new_block($block)]);
            return null;
        });
    }

    public static function handle_delete(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        return self::with_layout($req, function (array &$layout) use ($req) {
            $path = self::normalize_path($req->get_param('path'));
            if (!$path) return new \WP_Error('lg_root', 'cannot delete the root', ['status' => 422]);

            $leaf = array_pop($path);
            $bucket = &self::children_bucket_from_path($layout, $path);
            if ($bucket === null || !isset($bucket[$leaf])) {
                return new \WP_Error('lg_path_not_found', 'block not found at path', ['status' => 404]);
            }
            array_splice($bucket, (int) $leaf, 1);
            return null;
        });
    }

    public static function handle_move(\WP_REST_Request $req): \WP_REST_Response|\WP_Error
    {
        return self::with_layout($req, function (array &$layout) use ($req) {
            $from      = self::normalize_path($req->get_param('from'));
            $to_parent = self::normalize_path($req->get_param('to_parent'));
            $to_index  = max(0, (int) $req->get_param('to_index'));

            if (!$from) return new \WP_Error('lg_root', 'cannot move the root', ['status' => 422]);

            /* Pluck */
            $leaf = array_pop($from);
            $src  = &self::children_bucket_from_path($layout, $from);
            if ($src === null || !isset($src[$leaf])) {
                return new \WP_Error('lg_src_not_found', 'source block not found', ['status' => 404]);
            }
            $moved = $src[(int) $leaf];
            array_splice($src, (int) $leaf, 1);

            /* Adjust dest path for index shift caused by the pluck. Two
               shared-container cases matter:
                 (a) source and dest are both at root: if dest_root > src_root, decrement.
                 (b) source and dest are both in the same column.blocks[]: same rule.
               Anything cross-container is unaffected. */
            $to_parent = self::shift_for_pluck($to_parent, $from, (int) $leaf);

            $dest = &self::children_bucket($layout, $to_parent);
            if ($dest === null) {
                /* Roll back the pluck so the layout isn't half-mutated. */
                array_splice($src, (int) $leaf, 0, [$moved]);
                return new \WP_Error('lg_bad_dest', 'destination is not a container', ['status' => 422]);
            }
            $to_index = min($to_index, count($dest));
            array_splice($dest, $to_index, 0, [$moved]);
            return null;
        });
    }

    /* ── Shared pipeline ───────────────────────────────────────────── */

    /**
     * Load → mutate (via $mutator) → validate → save → bump epoch.
     * $mutator returns null on success or a WP_Error to abort.
     */
    private static function with_layout(\WP_REST_Request $req, callable $mutator): \WP_REST_Response|\WP_Error
    {
        $post_id = (int) $req->get_param('post_id');
        $layout  = Plugin::load_layout($post_id);
        if ($layout === null) return new \WP_Error('lg_no_layout', 'post has no v2 layout', ['status' => 404]);

        $err = $mutator($layout);
        if ($err instanceof \WP_Error) return $err;

        $errors = Validator::validate($layout);
        if (Validator::hasFatal($errors)) {
            return new \WP_Error('lg_invalid', 'mutation produced an invalid layout', [
                'status' => 422,
                'errors' => $errors,
            ]);
        }

        /* update_post_meta returns false both on DB error AND on no-op (when
           the new value equals the stored one — a WP gotcha). Distinguish by
           comparing first: a no-op is success from the caller's perspective. */
        $encoded = wp_json_encode($layout);
        $current = get_post_meta($post_id, LG_LAYOUT_V2_META_KEY, true);
        if ($current !== $encoded) {
            $stored = update_post_meta($post_id, LG_LAYOUT_V2_META_KEY, wp_slash($encoded));
            if ($stored === false) return new \WP_Error('lg_save_failed', 'could not persist layout', ['status' => 500]);
        }

        /* Defense in depth: Plugin::on_post_meta_changed already deletes
           the per-post rendered cache. Bump the global epoch too so any
           other cache layer keyed by the epoch invalidates as well. */
        update_option('lg_layout_v2_cache_epoch', time());

        return new \WP_REST_Response([
            'ok'       => true,
            'warnings' => array_values(array_filter($errors, fn($e) => empty($e['fatal']))),
        ], 200);
    }

    /* ── Path resolution ───────────────────────────────────────────── */

    /** Coerce inbound path segments: ints stay ints, strings stay strings. */
    private static function normalize_path(mixed $path): array
    {
        if (!is_array($path)) return [];
        $out = [];
        foreach ($path as $seg) {
            if (is_int($seg)) { $out[] = $seg; continue; }
            if (is_string($seg) && ($seg === 'columns' || $seg === 'blocks')) {
                $out[] = $seg; continue;
            }
            if (is_string($seg) && ctype_digit($seg)) { $out[] = (int) $seg; continue; }
            /* Unknown segment — keep as string; downstream lookup will fail safely. */
            $out[] = $seg;
        }
        return $out;
    }

    /** Return a reference to the block addressed by $path, or null. */
    private static function &deref(array &$layout, array $path)
    {
        $null = null;
        if (!$path) return $null;
        $blocks = &$layout['blocks'];
        if (!is_array($blocks)) return $null;
        $ref = &$blocks[(int) $path[0]];
        for ($i = 1; $i < count($path); $i += 2) {
            $kind  = $path[$i] ?? null;       /* 'columns' or 'blocks' */
            $index = $path[$i + 1] ?? null;
            if ($kind === 'columns') {
                if (!isset($ref['columns'][(int) $index])) return $null;
                $col = &$ref['columns'][(int) $index];
                $i++;
                $kind2 = $path[$i + 1] ?? null;
                $idx2  = $path[$i + 2] ?? null;
                if ($kind2 !== 'blocks' || !isset($col['blocks'][(int) $idx2])) return $null;
                $ref = &$col['blocks'][(int) $idx2];
                $i++;
            } else {
                return $null;
            }
        }
        return $ref;
    }

    /**
     * Reference to the children-array a new child would live in, given a
     * parent path. Empty path → root layout.blocks. Path to a block →
     * that block's children container (only `columns` blocks have one,
     * and only via a specific column).
     *
     * For inserting into a column, parent_path must end in
     * [..., "columns", c] — the column itself, not its `blocks` array.
     */
    private static function &children_bucket(array &$layout, array $parent)
    {
        $null = null;
        if (!$parent) return $layout['blocks'];

        /* Parent ends in ["columns", c] → return that column's blocks. */
        if (count($parent) >= 2 && $parent[count($parent) - 2] === 'columns') {
            $col_idx = (int) $parent[count($parent) - 1];
            $stem = array_slice($parent, 0, count($parent) - 2);
            $owner = &self::deref($layout, $stem);
            if ($owner === null || !isset($owner['columns'][$col_idx])) return $null;
            return $owner['columns'][$col_idx]['blocks'];
        }
        /* Otherwise unsupported (no other block types nest children). */
        return $null;
    }

    /**
     * Like children_bucket, but takes a path that already includes the
     * 'blocks' segment as its last element (handy for delete/move where
     * the leaf index was just popped off).
     */
    private static function &children_bucket_from_path(array &$layout, array $path)
    {
        $null = null;
        if (!$path) return $layout['blocks'];
        if (end($path) !== 'blocks') return $null;
        $without_blocks = array_slice($path, 0, count($path) - 1);
        return self::children_bucket($layout, $without_blocks);
    }

    /**
     * Adjust a destination parent path after the source has been plucked.
     * $src_parent is `from` with the leaf already popped (the source's
     * container path); $src_leaf is the plucked index. If $dest_parent
     * passes through that same container at an index > $src_leaf, that
     * index has shifted down by one — decrement it.
     */
    private static function shift_for_pluck(array $dest_parent, array $src_parent, int $src_leaf): array
    {
        $depth = count($src_parent);
        if (count($dest_parent) <= $depth) return $dest_parent;

        /* Must share the same container path up to $depth. */
        for ($i = 0; $i < $depth; $i++) {
            if ($dest_parent[$i] !== $src_parent[$i]) return $dest_parent;
        }
        if (!is_int($dest_parent[$depth])) return $dest_parent;
        if ($dest_parent[$depth] > $src_leaf) $dest_parent[$depth]--;
        return $dest_parent;
    }

    /* ── Sanitization ──────────────────────────────────────────────── */

    /** Coerce a prop value to a safe scalar/array; strip tags from strings. */
    private static function sanitize_value(mixed $v): mixed
    {
        if (is_string($v))  return wp_kses_post($v);
        if (is_numeric($v)) return $v + 0;
        if (is_bool($v))    return $v;
        if (is_array($v))   return array_map([self::class, 'sanitize_value'], $v);
        return null;
    }

    /** Sanitize a newly-inserted block: keep type + props, drop everything else. */
    private static function sanitize_new_block(array $block): array
    {
        $clean = ['type' => (string) ($block['type'] ?? '')];
        $allowed = [];
        if (in_array($clean['type'], Manifest::list(), true)) {
            $manifest = Manifest::get($clean['type']);
            $allowed  = array_keys($manifest['schema']['props'] ?? []);
        }
        foreach ($allowed as $k) {
            if (array_key_exists($k, $block)) $clean[$k] = self::sanitize_value($block[$k]);
        }
        /* For a freshly-inserted columns block, seed the columns container
           so the validator doesn't reject it for missing structure. */
        if ($clean['type'] === 'columns') {
            $count = is_array($block['columns'] ?? null) ? max(2, min(3, count($block['columns']))) : 2;
            $clean['columns'] = array_fill(0, $count, ['blocks' => []]);
        }
        return $clean;
    }
}
