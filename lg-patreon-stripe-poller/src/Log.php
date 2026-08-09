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
 *      REFUSED if it resolves inside the web root (see resolveDir).
 *   2. syslog, ident `lgms-poller` — `journalctl -t lgms-poller`.
 *
 * `wp_upload_dir()` is deliberately NOT a candidate: on live that path is an
 * R2 bucket over rclone FUSE **and** is publicly served (HTTP 200). See
 * resolveDir() for the full reasoning.
 */
final class Log
{
    /** Rotate at 8 MB: a 5-minute tick writing ~15 lines is slow to grow. */
    private const MAX_BYTES = 8388608;

    private static ?string $dir      = null;
    private static bool    $resolved = false;
    private static bool    $disabled = false;
    private static ?string $reason   = null;
    /** Where lines are actually going: file | syslog | error_log | none. */
    private static string  $sink     = 'none';
    private static int     $emitted  = 0;

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

            if ( $bytes !== false ) { self::$sink = 'file'; self::$emitted++; }

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
            'sink'     => self::$sink,
            'emitted'  => self::$emitted,
        ];
    }

    /** Test seam: forget the resolved path so a new LGMS_LOG_DIR takes effect. */
    public static function reset(): void
    {
        self::$dir = null; self::$resolved = false; self::$disabled = false; self::$reason = null;
        self::$sink = 'none'; self::$emitted = 0;
    }

    /**
     * Where a line goes when there is no log file: syslog.
     *
     * NOT error_log(). On live the looth-dev pool sets
     * `php_value[error_log] = /var/www/dev/wp-content/debug.log`, and that file
     * answers **HTTP 200** — routing member emails there would be the same
     * exposure by a different door. syslog is local, never web-served, and
     * rotates on its own. Read it with `journalctl -t lgms-poller`.
     */
    private static function fallback( string $message ): void
    {
        $line = 'LGMS tick: ' . rtrim( $message, "\n" );
        self::$emitted++;
        if ( function_exists( 'openlog' ) && openlog( 'lgms-poller', LOG_PID | LOG_ODELAY, LOG_DAEMON ) ) {
            syslog( LOG_INFO, $line );
            closelog();
            self::$sink = 'syslog';
            return;
        }
        self::$sink = 'error_log';
        error_log( $line );
    }

    /**
     * Pick a directory we can actually write, prove it by writing, and REFUSE
     * anything inside the web root.
     *
     * The uploads directory was the original choice here and it was wrong on
     * live, in two independent ways found by checking rather than assuming:
     *
     *   1. `wp-content/uploads` on live is a **Cloudflare R2 bucket mounted
     *      over rclone FUSE**, not local disk. Object storage has no real
     *      append, so a 5-minutely appending log means constant
     *      read-modify-reupload, and LOCK_EX is not dependable there.
     *   2. It is **publicly served** — `wp-content/uploads/...` answers HTTP 200
     *      on live. This log carries member emails and provisioning detail, so
     *      that is a data exposure. The `.htaccess` deny that used to be written
     *      here was decorative: **live runs nginx, which never reads .htaccess.**
     *
     * So a candidate is rejected outright if it resolves inside ABSPATH. That
     * encodes the lesson instead of patching the one path that tripped it.
     */
    private static function resolveDir(): ?string
    {
        $candidates = [];

        if ( defined( 'LGMS_LOG_DIR' ) && is_string( LGMS_LOG_DIR ) && LGMS_LOG_DIR !== '' ) {
            $candidates[] = rtrim( LGMS_LOG_DIR, '/' );
        }

        $rejected = [];
        foreach ( $candidates as $dir ) {
            if ( self::insideWebRoot( $dir ) ) {
                $rejected[] = $dir . ' (inside the web root — would be publicly fetchable)';
                continue;
            }
            if ( self::prepare( $dir ) ) {
                return $dir;
            }
            $rejected[] = $dir . ' (not writable)';
        }

        if ( $rejected ) {
            error_log( 'LGMS\Log: no usable log directory — ' . implode( '; ', $rejected ) . '. Falling back to syslog.' );
        }
        return null;   // -> syslog, which is never web-served
    }

    /**
     * True when $dir resolves inside the web root. Compares REAL paths so a
     * symlink out of the docroot (or into it) is judged by where it lands, not
     * by how it is spelled — live's uploads path is exactly such a symlink.
     */
    private static function insideWebRoot( string $dir ): bool
    {
        if ( ! defined( 'ABSPATH' ) ) {
            return false;
        }
        $root = realpath( ABSPATH );
        if ( $root === false ) {
            return false;
        }
        // Resolve the nearest existing ancestor, since $dir may not exist yet.
        $probe = $dir;
        while ( $probe !== '' && $probe !== '/' && ! file_exists( $probe ) ) {
            $probe = dirname( $probe );
        }
        $real = realpath( $probe );
        if ( $real === false ) {
            return false;
        }
        return $real === $root || strpos( $real, rtrim( $root, '/' ) . '/' ) === 0;
    }

    /** Create if needed, then PROVE writability with a real write. */
    private static function prepare( string $dir ): bool
    {
        if ( ! is_dir( $dir ) ) {
            // Deliberately not guarded by file_exists(): a dangling symlink is
            // not a directory but does occupy the name, and mkdir's failure is
            // the honest signal.
            if ( ! @mkdir( $dir, 0770, true ) && ! is_dir( $dir ) ) {
                return false;
            }
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
