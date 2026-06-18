<?php
/**
 * PSR-4 autoloader for the LG\LayoutV2 namespace.
 *
 * Maps LG\LayoutV2\Foo to src/Foo.php. Used by both the CLI harness
 * (bin/*.php) and the WP plugin entry (Phase 2). In WP, Composer's
 * autoloader could replace this — but standalone PHP files mean zero
 * external dependencies.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'LG\\LayoutV2\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;

    $rel = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) require_once $path;
});
