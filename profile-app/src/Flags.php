<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Flags — the reader for profile-app's tracked config files.
 *
 * One job: turn `config/<name>.php` into an array, once per request, without
 * exploding when the file is absent. A missing config file must read as "every
 * flag off" rather than as a fatal — a box that has not pulled yet keeps serving
 * exactly what it served before.
 *
 * WHY NOT getenv(): see the header of config/notifications.php. The three contexts
 * this code runs in (FPM, loopback ingest, the digest's recap pull) do not share an
 * environment, and two of them have essentially none.
 *
 * ⚠️ `bool()` is deliberately strict — `=== true`. A flag file that returns the
 * STRING "false" (a real hazard when a value is ever templated or hand-edited) must
 * read as OFF, and a loose cast would read it as ON. For an unrecallable side
 * effect the failure has to land on the safe side of the fence.
 */
final class Flags
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** @return array<string, mixed> */
    public static function all(string $name): array
    {
        if (isset(self::$cache[$name])) return self::$cache[$name];

        // Reject anything that isn't a plain config basename, so this can never be
        // steered at another file by a caller that grows a parameter later.
        if (!preg_match('/^[a-z0-9-]+$/', $name)) return self::$cache[$name] = [];

        $path = __DIR__ . '/../config/' . $name . '.php';
        if (!is_file($path)) return self::$cache[$name] = [];

        $cfg = require $path;
        return self::$cache[$name] = is_array($cfg) ? $cfg : [];
    }

    /** Strictly-true boolean read. Anything else — absent, null, 0, "false" — is OFF. */
    public static function bool(string $name, string $key): bool
    {
        return (self::all($name)[$key] ?? null) === true;
    }

    /** Test seam: forget what was read, so a proof can flip a file and re-read it. */
    public static function resetCache(): void
    {
        self::$cache = [];
    }

    /**
     * Test seam: pin a config in memory, so a red-first can exercise BOTH flag
     * states in one process without editing the tracked file.
     *
     * ⚠️ CLI ONLY, enforced rather than documented. A harness that mutates the
     * tracked config on disk is how one lane wiped uncommitted work under test
     * (feedback-mutation-harness-must-snapshot-not-checkout); an in-memory override
     * has no such failure mode. And refusing to work under any web SAPI means this
     * cannot become a request-time switch by accident — a flag that an HTTP caller
     * can move is not a flag.
     *
     * @param array<string, mixed> $values
     */
    public static function forTest(string $name, array $values): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('Flags::forTest is CLI-only');
        }
        self::$cache[$name] = $values;
    }
}
