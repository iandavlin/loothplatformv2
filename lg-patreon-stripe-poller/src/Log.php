<?php

declare(strict_types=1);

namespace LGMS;

/**
 * The poller's operational log.
 *
 * Replaces `@file_put_contents( LGMS_PLUGIN_DIR . 'tick.log', … )`, which on
 * live wrote nothing at all and said nothing about it (audit R4):
 *
 *   - LGMS_PLUGIN_DIR resolves through the mu-plugin symlink to the serving
 *     checkout, owned `ubuntu:ubuntu` mode `drwxrwxr-x`.
 *   - WP runs as `looth-dev` (groups: looth-dev, www-data, loothdevs). Others
 *     get `r-x`. No write.
 *   - The `@` swallowed the failure, so a pipeline touching member roles every
 *     five minutes on production had ZERO operational record, and nothing was
 *     logged about the fact that nothing could be logged.
 *
 * Two rules follow, and they are the whole design:
 *
 *   1. **Never silently drop a line.** If the file cannot be written, the line
 *      goes to `error_log()` instead. Losing the pretty formatting is fine;
 *      losing the event is not.
 *   2. **Never throw.** Logging failure must not be able to kill a tick that
 *      is mid-way through moving somebody's membership.
 *
 * Path resolution, first that works:
 *   1. `LGMS_LOG_DIR` — a constant, not an env var. WP-Cron runs with NO
 *      environment (lg-wp-cron.service carries no Environment=), so a getenv()
 *      knob would read as unset in exactly the context that matters most.
 *   2. `wp_upload_dir()['basedir'] . '/lg-logs'` — owned by the web user by
 *      construction, and outside the repo so it can never dirty the serving
 *      checkout.
 *   3. none — everything falls through to error_log().
 */
final class Log
{
    /** Rotate at 8 MB: a 5-minute tick writing ~15 lines is slow to grow. */
    private const MAX_BYTES = 8388608;

    private static ?string $dir      = null;
    private static bool    $resolved = false;
    private static bool    $disabled = false;
    private static ?string $reason   = null;

    /**
     * Absolute path of a named log file, or null when nothing is writable.
     * Resolution happens once per request; a write failure disables the file
     * for the rest of the request rather than re-warning on every line.
     */
    public static function file( string $name = 'tick.log' ): ?string
    {
        if ( self::$disabled ) {
            return null;
        }
        if ( ! self::$resolved ) {
            self::$resolved = true;
            self::$dir      = self::resolveDir();
        }
        return self::$dir === null ? null : self::$dir . '/' . $name;
    }

    /**
     * Append one line. Adds no trailing newline of its own — callers pass
     * their own, matching the sprintf() call sites this replaced.
     */
    public static function line( string $message, string $name = 'tick.log' ): void
    {
        $path = self::file( $name );

        if ( $path === null ) {
            self::fallback( $message );
            return;
        }

        try {
            self::rotate( $path );

            // No `@`. A failure here must be visible — that is the entire bug
            // being fixed. LOCK_EX because WP-Cron and REST /run-now can tick
            // concurrently (the DB advisory lock stops the work, not the log).
            $bytes = file_put_contents( $path, $message, FILE_APPEND | LOCK_EX );

            if ( $bytes === false ) {
                self::$disabled = true;
                self::$reason   = 'write failed: ' . $path;
                error_log( 'LGMS\Log: cannot write ' . $path . ' — falling back to error_log for the rest of this request' );
                self::fallback( $message );
            }
        } catch ( \Throwable $e ) {
            // A log must never be able to take down a membership write.
            self::$disabled = true;
            self::$reason   = $e->getMessage();
            self::fallback( $message );
        }
    }

    /** Health probe — for gates, the admin screen, and the launch checklist. */
    public static function status(): array
    {
        $path     = self::file();
        $writable = false;
        if ( $path !== null ) {
            $writable = file_exists( $path ) ? is_writable( $path ) : is_writable( dirname( $path ) );
        }
        return [
            'path'     => $path,
            'writable' => $writable,
            'exists'   => $path !== null && file_exists( $path ),
            'size'     => ( $path !== null && file_exists( $path ) ) ? filesize( $path ) : 0,
            'reason'   => self::$reason,
        ];
    }

    /** Test seam: forget the resolved path so a new LGMS_LOG_DIR takes effect. */
    public static function reset(): void
    {
        self::$dir = null; self::$resolved = false; self::$disabled = false; self::$reason = null;
    }

    private static function fallback( string $message ): void
    {
        error_log( 'LGMS tick: ' . rtrim( $message, "\n" ) );
    }

    /**
     * Pick a directory we can actually write, and prove it by writing.
     * `is_dir()`/`file_exists()` are not proof: a dangling symlink, a
     * root-owned directory, or a full disk all pass a existence check and then
     * fail the write — which is the failure mode this class exists to end.
     */
    private static function resolveDir(): ?string
    {
        $candidates = [];

        if ( defined( 'LGMS_LOG_DIR' ) && is_string( LGMS_LOG_DIR ) && LGMS_LOG_DIR !== '' ) {
            $candidates[] = rtrim( LGMS_LOG_DIR, '/' );
        }

        if ( function_exists( 'wp_upload_dir' ) ) {
            $up = wp_upload_dir( null, false );
            if ( is_array( $up ) && empty( $up['error'] ) && ! empty( $up['basedir'] ) ) {
                $candidates[] = rtrim( (string) $up['basedir'], '/' ) . '/lg-logs';
            }
        }

        foreach ( $candidates as $dir ) {
            if ( self::prepare( $dir ) ) {
                return $dir;
            }
        }

        error_log( 'LGMS\Log: no writable log directory (tried: ' . ( $candidates ? implode( ', ', $candidates ) : 'none' ) . ')' );
        return null;
    }

    /** Create if needed, then PROVE writability with a real write. */
    private static function prepare( string $dir ): bool
    {
        if ( ! is_dir( $dir ) ) {
            // Deliberately not guarded by file_exists(): a dangling symlink is
            // not a directory but does occupy the name, and mkdir's failure is
            // the honest signal.
            if ( ! @mkdir( $dir, 0775, true ) && ! is_dir( $dir ) ) {
                return false;
            }
            // Uploads is web-served; keep the logs out of reach.
            @file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
            @file_put_contents( $dir . '/index.html', '' );
        }

        $probe = $dir . '/.writable-probe';
        if ( @file_put_contents( $probe, "ok\n" ) === false ) {
            return false;
        }
        @unlink( $probe );
        return true;
    }

    private static function rotate( string $path ): void
    {
        if ( ! file_exists( $path ) || filesize( $path ) < self::MAX_BYTES ) {
            return;
        }
        // Single generation: the previous file is the only history worth the
        // disk on a box with 3.8GB and no log shipping.
        @rename( $path, $path . '.1' );
    }
}
