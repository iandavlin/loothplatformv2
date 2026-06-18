<?php
declare(strict_types=1);

namespace Looth\ProfileApp;

/**
 * Thin wrapper around the libmaxminddb extension that gracefully degrades
 * when the GeoLite2 database is missing or the extension isn't loaded.
 *
 * Used to compute an IP-biased viewbox for the Nominatim search proxy —
 * we'd rather skip biasing than fail the lookup entirely.
 */
final class GeoIP
{
    public const DB_PATH_DEFAULT = '/var/lib/GeoIP/GeoLite2-City.mmdb';

    /** Resolve an IPv4/IPv6 to [lat, lng] or null if no usable signal. */
    public static function lookup(string $ip): ?array
    {
        $dbPath = getenv('GEOLITE2_CITY_DB') ?: self::DB_PATH_DEFAULT;
        if (!is_readable($dbPath)) return null;
        if (!class_exists(\MaxMind\Db\Reader::class) && !extension_loaded('maxminddb')) return null;

        try {
            if (class_exists(\MaxMind\Db\Reader::class)) {
                $reader = new \MaxMind\Db\Reader($dbPath);
                $rec    = $reader->get($ip);
                $reader->close();
            } else {
                $reader = maxminddb_open($dbPath);
                $rec    = maxminddb_get($reader, $ip);
            }
            if (!is_array($rec)) return null;
            $lat = $rec['location']['latitude']  ?? null;
            $lng = $rec['location']['longitude'] ?? null;
            if ($lat === null || $lng === null) return null;
            return [(float)$lat, (float)$lng];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build a ~500km bounding box around (lat, lng) suitable for Nominatim's
     * `viewbox` param (left,top,right,bottom = west,north,east,south).
     * ~500km at mid-latitudes ≈ 4.5° lat, 5–7° lng — fudge with cos(lat).
     */
    public static function viewboxAround(float $lat, float $lng, float $kmRadius = 500.0): string
    {
        $dLat = $kmRadius / 111.32;
        $dLng = $kmRadius / max(1.0, 111.32 * cos(deg2rad($lat)));
        $west  = $lng - $dLng;
        $east  = $lng + $dLng;
        $north = $lat + $dLat;
        $south = $lat - $dLat;
        return sprintf('%.4f,%.4f,%.4f,%.4f', $west, $north, $east, $south);
    }

    /** First public IP in X-Forwarded-For, falling back to REMOTE_ADDR. */
    public static function callerIp(): string
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        foreach (explode(',', $xff) as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $candidate;
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
