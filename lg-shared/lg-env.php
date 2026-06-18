<?php
/**
 * Shared env/host source of truth.
 *
 * Reads /etc/looth/env (simple KEY=VALUE) once, memoized, and hands every
 * strangler app the box's env + public host:
 *
 *     require_once '/srv/lg-shared/lg-env.php';
 *     $shared = function_exists('lg_env') ? lg_env() : [];
 *     $env  = $shared['env']  ?? <app's existing detection>;   // shared wins, existing = fallback
 *     $host = $shared['host'] ?? <app's existing host derivation>;
 *
 * CONTRACT — reversible + box-safe:
 *   - If /etc/looth/env is ABSENT or unreadable, lg_env() returns [] and every
 *     caller falls through to its own detection => the box behaves EXACTLY as
 *     it did before this file existed. (Both dev1 and dev2 carry the file now —
 *     dev1=dev/dev.loothgroup.com, dev2=dev2/dev2.loothgroup.com — so only the
 *     VALUES differ per box and the code is identical; the absent-safety path
 *     still covers any box brought up without it.)
 *   - Keys: LG_ENV -> ['env'], LG_PUBLIC_HOST -> ['host'], plus the box LAYOUT
 *     so apps stop guessing it from the env string (the prod box keeps the dev
 *     layout while its host is loothgroup.com): LG_WP_PATH -> ['wp_path'],
 *     LG_WP_USER -> ['wp_user'], LG_MYSQL_DB -> ['mysql_db'],
 *     LG_MYSQL_BILLING_DB -> ['mysql_billing_db'], LG_PG_DB -> ['pg_db'],
 *     LG_PG_DB_PROFILE -> ['pg_db_profile'], LG_GATE_COOKIE -> ['gate_cookie'].
 *     A missing/empty key is omitted (gate_cookie keeps an explicit empty value)
 *     so the caller's ?? fallback still covers it.
 *
 * At the cut, flipping the TWO values in /etc/looth/env (dev2 -> live and
 * dev2.loothgroup.com -> loothgroup.com) is the whole environment switch; the
 * CODE stays byte-identical on every box, so dev1/dev2/prod run one binary.
 *
 * No class, no composer/autoloader — same zero-dependency style as
 * jwt-verify.php so bb-mirror / archive-poc / events / membership can
 * require it as-is.
 */

if (!function_exists('lg_env')) {

    function lg_env(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];                       // absent/unreadable => empty => callers fall back
        $file  = '/etc/looth/env';
        if (!is_readable($file)) return $cache;

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            $val = preg_replace('/^([\'"])(.*)\1$/', '$2', $val);   // strip optional quotes
            if ($key === 'LG_ENV' && $val !== '') {
                $cache['env'] = $val;
            } elseif ($key === 'LG_PUBLIC_HOST' && $val !== '') {
                // host can feed curl 'Host:' headers downstream — sanitize to
                // hostname[:port] (defense in depth; the file is root-owned).
                $cache['host'] = preg_replace('/[^A-Za-z0-9.\-:]/', '', $val);
            } elseif ($key === 'LG_WP_PATH' && $val !== '') {
                $cache['wp_path'] = $val;
            } elseif ($key === 'LG_WP_USER' && $val !== '') {
                $cache['wp_user'] = $val;
            } elseif ($key === 'LG_MYSQL_DB' && $val !== '') {
                $cache['mysql_db'] = $val;
            } elseif ($key === 'LG_MYSQL_BILLING_DB' && $val !== '') {
                $cache['mysql_billing_db'] = $val;
            } elseif ($key === 'LG_PG_DB' && $val !== '') {
                $cache['pg_db'] = $val;
            } elseif ($key === 'LG_PG_DB_PROFILE' && $val !== '') {
                $cache['pg_db_profile'] = $val;
            } elseif ($key === 'LG_GATE_COOKIE') {
                // present-but-empty is meaningful (the prod box runs gate-free)
                $cache['gate_cookie'] = $val;
            }
        }
        return $cache;
    }
}
