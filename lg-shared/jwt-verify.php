<?php
/**
 * Shared looth_id verify helper.
 *
 * Every strangler PHP consumer can:
 *     require_once '/srv/lg-shared/jwt-verify.php';
 *     $claims = lg_shared_verify_looth_id($_COOKIE['looth_id'] ?? null);
 *
 * Single function, no class, NO composer/autoloader dependency — it verifies the
 * RS256 signature with ext-openssl directly, so consumers WITHOUT firebase/php-jwt
 * (e.g. bb-mirror, archive-poc) can use it as-is. Same verification
 * profile-app/src/Auth.php performs via firebase/php-jwt, against the same
 * public key (/etc/looth/jwt-public.pem, 644 world-readable).
 *
 * Returns the decoded claims array on success, or NULL on ANY failure:
 * missing/blank token, missing key file, malformed JWT, non-RS256 alg, bad
 * signature, expired (exp) or not-yet-valid (nbf). NULL is the caller's signal
 * to fall back to the whoami shim — this function NEVER throws.
 *
 * Claim shape it returns (STRANGLER-COORDINATION §0c): identity + stable display
 *   iss, sub (user_uuid), wp_user_id, display_name, avatar_url, slug, iat, exp
 * Deliberately absent: tier (read the lg_tier cookie) and capabilities
 * (reconcile via /whoami only when a sensitive gate is actually hit).
 */

if (!function_exists('lg_shared_verify_looth_id')) {

    /** base64url → raw bytes; false on invalid input. */
    function lg_shared_b64url_decode(string $in)
    {
        $rem = strlen($in) % 4;
        if ($rem) $in .= str_repeat('=', 4 - $rem);
        return base64_decode(strtr($in, '-_', '+/'), true);
    }

    /**
     * @param string|null $token   the looth_id JWT (cookie value or Bearer token)
     * @param string      $keyPath PEM public key path (override only in tests)
     * @return array|null  claims on success, null on any failure
     */
    function lg_shared_verify_looth_id(?string $token, string $keyPath = '/etc/looth/jwt-public.pem'): ?array
    {
        if (!is_string($token) || $token === '') return null;

        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$h64, $p64, $s64] = $parts;

        $headerJson  = lg_shared_b64url_decode($h64);
        $payloadJson = lg_shared_b64url_decode($p64);
        $sig         = lg_shared_b64url_decode($s64);
        if ($headerJson === false || $payloadJson === false || $sig === false) return null;

        // Pin the algorithm to RS256 BEFORE verifying — blocks the classic
        // alg:"none" and HS256-with-public-key-as-secret downgrade attacks.
        $header = json_decode($headerJson, true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256') return null;

        $pubkey = @file_get_contents($keyPath);
        if (!is_string($pubkey) || $pubkey === '') return null;

        // Signature covers the literal "<header>.<payload>" string.
        $ok = openssl_verify($h64 . '.' . $p64, $sig, $pubkey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) return null;

        $claims = json_decode($payloadJson, true);
        if (!is_array($claims)) return null;

        $now = time();
        if (isset($claims['exp']) && $now >= (int)$claims['exp']) return null;  // expired
        if (isset($claims['nbf']) && $now <  (int)$claims['nbf']) return null;  // not yet valid

        return $claims;
    }

}
