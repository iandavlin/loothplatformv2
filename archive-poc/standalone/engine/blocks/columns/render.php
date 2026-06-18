<?php
/**
 * blocks/columns/render.php
 *
 * $args  — parsed props (validated against manifest schema)
 * $ctx   — render context (viewer, editor_mode, manifests, …)
 *
 * Emits a .lg-columns wrapper containing one .lg-columns__col per entry
 * in the block's `columns` array. Each bucket is `{ "blocks": [...] }`;
 * children of that bucket render inside that column wrapper. No
 * distribution math — buckets are the source of truth.
 *
 * Defaults to 2 empty columns if the prop is missing or empty so the
 * block always renders something visible (e.g. for a newly-inserted
 * columns block in editor mode).
 *
 * Validator rejects nested columns, so we don't re-check depth here.
 */

use LG\LayoutV2\Renderer;

/** @var array $args */
/** @var array $ctx */

$columns = is_array($args['columns'] ?? null) ? $args['columns'] : [];
if (empty($columns)) {
    $columns = [['blocks' => []], ['blocks' => []]];   /* sane default */
}

/* Clamp to 2 or 3 visible columns. Extras (data carries 4+) are silently
   ignored at render — the manifest contract is 2|3 and the metabox UI
   doesn't allow more. */
$colCount = count($columns);
if ($colCount > 3) { $columns = array_slice($columns, 0, 3); $colCount = 3; }
if ($colCount < 2) { $columns[] = ['blocks' => []]; $colCount = 2; }

$variant = is_string($args['variant'] ?? null) ? strtolower((string) $args['variant']) : 'variant-1';
if (!in_array($variant, ['variant-1', 'variant-2', 'variant-3'], true)) $variant = 'variant-1';

$depth = (int) ($args['_depth'] ?? 1);
$ind   = Renderer::indent($depth);
?>
<?php $myPath = $ctx['__path'] ?? []; ?>
<?= $ind ?><div class="lg-columns lg-columns--<?= $colCount ?> lg-columns--<?= $variant ?>">
<?php foreach ($columns as $colIdx => $col):
    $colBlocks = is_array($col['blocks'] ?? null) ? $col['blocks'] : [];
    $childPath = [...$myPath, 'columns', $colIdx, 'blocks'];
?>
<?= $ind ?>  <div class="lg-columns__col">
<?= Renderer::renderChildren($colBlocks, $ctx, $depth + 2, $childPath) ?>
<?= $ind ?>  </div>
<?php endforeach; ?>
<?= $ind ?></div>
