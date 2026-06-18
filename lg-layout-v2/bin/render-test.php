#!/usr/bin/env php
<?php
/**
 * render-test.php — PHP harness for the v2 render pipeline.
 *
 * Runs the real LG\LayoutV2\Pipeline against each fixture, writes the four
 * output artifacts, and diffs against the committed baselines. No WordPress —
 * the pipeline runs identically here and in the plugin (Phase 2 wraps the
 * same code in WP hooks).
 *
 * Usage:
 *   bin/render-test.php --all                       # every fixture
 *   bin/render-test.php --fixture=simple-article    # one fixture
 *   bin/render-test.php --block=image-caption       # all fixtures using a block
 *   bin/render-test.php --update-snapshots          # accept current output as baseline
 *   bin/render-test.php --editor                    # render in editor mode
 *
 * Output: tests/output/<fixture>/{rendered.html, bundle.css, variables-resolved.json, validation.log}
 * Compares against tests/expected/<fixture>/*.
 *
 * Exit code: 0 = all pass, 1 = any fail.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Autoload.php';

use LG\LayoutV2\Manifest;
use LG\LayoutV2\Pipeline;
use LG\LayoutV2\Theme;
use LG\LayoutV2\TierResolver;

$ROOT          = dirname(__DIR__);
$FIXTURES_DIR  = $ROOT . '/tests/fixtures';
$EXPECTED_DIR  = $ROOT . '/tests/expected';
$OUTPUT_DIR    = $ROOT . '/tests/output';
$MEDIA_PATH    = $FIXTURES_DIR . '/_media.json';

Manifest::configure($ROOT . '/blocks');

$args = parseArgs(array_slice($argv, 1));
$updateSnapshots = !empty($args['update-snapshots']);
$editorMode      = !empty($args['editor']);

if (!is_dir($FIXTURES_DIR)) {
    fwrite(STDERR, "render-test: no tests/fixtures/ at $FIXTURES_DIR\n");
    exit(2);
}

/* Select fixtures */
$allFixtures = array_filter(
    glob($FIXTURES_DIR . '/*.json') ?: [],
    fn($f) => basename($f)[0] !== '_' && !preg_match('/\.(dash|viewer)\.json$/', basename($f))
);
sort($allFixtures);

$fixtures = [];
if (!empty($args['all'])) {
    $fixtures = $allFixtures;
} elseif (!empty($args['fixture'])) {
    $f = $FIXTURES_DIR . '/' . $args['fixture'] . '.json';
    if (!file_exists($f)) { fwrite(STDERR, "render-test: fixture not found: $f\n"); exit(2); }
    $fixtures = [$f];
} elseif (!empty($args['block'])) {
    $needle = '"type": "' . $args['block'] . '"';
    $fixtures = array_filter($allFixtures, fn($f) => str_contains((string) file_get_contents($f), $needle));
    if (!$fixtures) { fwrite(STDERR, "render-test: no fixture references block '{$args['block']}'\n"); exit(2); }
} else {
    fwrite(STDERR, "usage: bin/render-test.php --all | --fixture=<name> | --block=<name> [--update-snapshots] [--editor]\n");
    exit(2);
}

@mkdir($OUTPUT_DIR, 0755, true);

/* Media resolver — backed by tests/fixtures/_media.json so fixtures don't need a DB */
$mediaMap = is_file($MEDIA_PATH) ? (json_decode((string) file_get_contents($MEDIA_PATH), true) ?: []) : [];
$mediaResolver = static function (int $id) use ($mediaMap): array {
    $key = (string) $id;
    if (isset($mediaMap[$key]) && is_array($mediaMap[$key])) return $mediaMap[$key];
    return ['id' => $id, 'url' => "(media $id)", 'alt' => '', 'mime' => '', 'sizes' => []];
};

$brandTokens = Theme::defaultValues();

$passed = 0;
$failed = 0;
foreach ($fixtures as $fixturePath) {
    $name = basename($fixturePath, '.json');
    $result = runFixture($name, $fixturePath, $FIXTURES_DIR, $OUTPUT_DIR, $EXPECTED_DIR, $brandTokens, $mediaResolver, $editorMode, $updateSnapshots);

    if ($result['status'] === 'pass') {
        $passed++;
        fwrite(STDOUT, "  ✓ $name\n");
    } elseif ($result['status'] === 'snapshot-written') {
        $passed++;
        fwrite(STDOUT, "  ⊕ $name  (first run — snapshot written, please review)\n");
    } else {
        $failed++;
        fwrite(STDOUT, "  ✗ $name\n");
        foreach ($result['diffs'] as $d) fwrite(STDOUT, "      $d\n");
    }
}

fwrite(STDOUT, "\nrender-test: $passed passed, $failed failed\n");
if ($failed > 0 && !$updateSnapshots) {
    fwrite(STDOUT, "\nTo accept current output as the new baseline, re-run with --update-snapshots\n");
}
exit($failed === 0 ? 0 : 1);

/* ─────────────────────────────────────────────────────────────────
   Per-fixture run
───────────────────────────────────────────────────────────────── */

function runFixture(
    string $name,
    string $fixturePath,
    string $fixturesDir,
    string $outDir,
    string $expectedDir,
    array $brandTokens,
    callable $mediaResolver,
    bool $editorMode,
    bool $updateSnapshots
): array {
    $layout = json_decode((string) file_get_contents($fixturePath), true);
    if (!is_array($layout)) return ['status' => 'fail', 'diffs' => ['fixture is not valid JSON']];

    /* Optional sidecar files */
    $dashOverrides = loadSidecar($fixturesDir, $name, 'dash');
    $viewerJson    = loadSidecar($fixturesDir, $name, 'viewer');

    $viewer = $viewerJson ?: TierResolver::anonymous();
    $ctx = [
        'viewer'         => $viewer,
        'editor_mode'    => $editorMode,
        'media_resolver' => $mediaResolver,
    ];

    try {
        $result = Pipeline::run($layout, $brandTokens, $dashOverrides, $ctx);
    } catch (\Throwable $e) {
        return ['status' => 'fail', 'diffs' => ['pipeline threw: ' . $e->getMessage()]];
    }

    /* Build validation log */
    $log = $result['validation']
        ? implode("\n", array_map(fn($e) => "{$e['path']}: {$e['msg']}" . (!empty($e['fatal']) ? ' [FATAL]' : ''), $result['validation']))
        : 'OK — layout valid';

    /* Write outputs */
    $fixOutDir = "$outDir/$name";
    @mkdir($fixOutDir, 0755, true);
    file_put_contents("$fixOutDir/rendered.html", $result['html']);
    file_put_contents("$fixOutDir/bundle.css",     $result['css']);
    file_put_contents("$fixOutDir/variables-resolved.json", json_encode($result['variables'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    file_put_contents("$fixOutDir/validation.log", $log . "\n");

    /* preview.html — viewable standalone page (links bundle.css). Not part of
       the snapshot set; meant for humans, not diffing. */
    file_put_contents("$fixOutDir/preview.html", buildPreviewPage($name, $result));

    /* First-run snapshot capture, or explicit update */
    $fixExpectedDir = "$expectedDir/$name";
    if (!is_dir($fixExpectedDir) || $updateSnapshots) {
        @mkdir($fixExpectedDir, 0755, true);
        foreach (['rendered.html', 'bundle.css', 'variables-resolved.json', 'validation.log'] as $artifact) {
            copy("$fixOutDir/$artifact", "$fixExpectedDir/$artifact");
        }
        return ['status' => 'snapshot-written'];
    }

    /* Diff */
    $diffs = [];
    foreach (['rendered.html', 'bundle.css', 'variables-resolved.json', 'validation.log'] as $artifact) {
        $a = file_get_contents("$fixOutDir/$artifact");
        $b = file_get_contents("$fixExpectedDir/$artifact");
        if ($a !== $b) {
            $aLen = strlen($a);
            $bLen = strlen($b);
            $diffs[] = "$artifact: differs (expected $bLen bytes, got $aLen)";
        }
    }
    return $diffs ? ['status' => 'fail', 'diffs' => $diffs] : ['status' => 'pass', 'diffs' => []];
}

function loadSidecar(string $fixturesDir, string $name, string $kind): array
{
    $path = "$fixturesDir/$name.$kind.json";
    if (!is_file($path)) return [];
    $raw = json_decode((string) file_get_contents($path), true);
    return is_array($raw) ? $raw : [];
}

function buildPreviewPage(string $fixture, array $result): string
{
    $css = $result['css'];
    $html = $result['html'];
    $title = "lg-layout-v2 · $fixture preview";
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>$title</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="bundle.css" />
  <style>
    /* Page chrome around the article — purely for the preview surface,
       NOT part of the article's CSS bundle. */
    body { margin: 0; background: #f0eee8; font-family: 'Jost', system-ui, sans-serif; color: #323532; }
    .lg-preview-bar { padding: 10px 20px; background: #2f3d2c; color: #fff; font-size: 12px; font-family: monospace; display: flex; gap: 16px; align-items: center; }
    .lg-preview-bar a { color: #ecb351; text-decoration: none; }
    .lg-preview-bar a:hover { text-decoration: underline; }
    .lg-preview-bar .sep { color: #87986a; }
    .lg-article { max-width: 740px; margin: 32px auto; padding: 0 16px; }
  </style>
</head>
<body>
  <div class="lg-preview-bar">
    <span>lg-layout-v2 preview · <strong>$fixture</strong></span>
    <span class="sep">·</span>
    <a href="rendered.html">raw HTML</a>
    <span class="sep">·</span>
    <a href="bundle.css">CSS bundle</a>
    <span class="sep">·</span>
    <a href="variables-resolved.json">variables</a>
    <span class="sep">·</span>
    <a href="validation.log">validation</a>
    <span class="sep">·</span>
    <a href="../">↑ all fixtures</a>
  </div>
  $html
</body>
</html>
HTML;
}

function parseArgs(array $argv): array
{
    $out = [];
    foreach ($argv as $a) {
        if ($a === '--all') $out['all'] = true;
        elseif ($a === '--update-snapshots') $out['update-snapshots'] = true;
        elseif ($a === '--editor') $out['editor'] = true;
        elseif (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) $out[$m[1]] = $m[2];
    }
    return $out;
}
