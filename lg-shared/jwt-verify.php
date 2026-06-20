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
 * signature, expired (exp) or not-yet-valid (nbf), OR wrong issuer (iss).
 * NULL is the caller's signal to fall back to the whoami shim — never throws.
 *
 * ISSUER PINNING (added 2026-06-20): the token must be issued for THIS box
 * (iss === 'https://' . lg_env()['host']). Without it, a looth_id minted by LIVE
 * (iss=https://loothgroup.com, cookie scoped .loothgroup.com) leaks DOWN into a
 * subdomain box (dev2.loothgroup.com), verifies fine (shared key), and can't be
 * cleared by that box's logout (which only clears .dev2.loothgroup.com) → the user
 * stays "logged in" after logout. The check rejects cross-host tokens. If the env
 * host can't be determined (no /etc/looth/env), the check is skipped — behavior is
 * byte-identical to before, so non-env boxes/tests are unaffected.
 *
 * Claim shape it returns (STRANGLER-COORDINATION §0c): identity + stable display
 *   iss, sub (user_uuid), wp_user_id, display_name, avatar_url, slug, iat, exp
 */

if (!function_exists('lg_shared_verify_looth_id')) {

    /** base64url → raw bytes; false on invalid input. */
    function lg_shared_b64url_decode(string $in)
    {
        $rem = strlen($in) % 4;
        if ($rem) $in .= str_repeat('=', 4 - $rem);
        return base64_decode(strtr($in, '-_', '+/'), true);
    }

    /** Expected issuer for THIS box ('https://<host>'), or null if undeterminable. */
    function lg_shared_looth_expected_iss(): ?string
    {
        if (is_file('/srv/lg-shared/lg-env.php')) {
            require_once '/srv/lg-shared/lg-env.php';
            if (function_exists('lg_env')) {
                $h = (string)(lg_env()['host'] ?? '');
                if ($h !== '') return 'https://' . $h;
            }
        }
        return null;
    }

    /** All values for a given cookie name from the raw Cookie header (handles dupes). */
    function lg_shared_all_cookie_values(string $name): array
    {
        $raw = $_SERVER['HTTP_COOKIE'] ?? '';
        if ($raw === '') return [];
        $out = [];
        foreach (explode(';', $raw) as $pair) {
            $pair = trim($pair);
            $eq = strpos($pair, '=');
            if ($eq === false) continue;
            if (substr($pair, 0, $eq) !== $name) continue;
            $out[] = rawurldecode(substr($pair, $eq + 1));
        }
        return $out;
    }

    /** Verify signature + alg + exp/nbf ONLY (no issuer). Internal. */
    function lg_shared_verify_looth_id_sig(?string $token, string $keyPath): ?array
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

    /**
     * @param string|null $token   the looth_id JWT (cookie value or Bearer token)
     * @param string      $keyPath PEM public key path (override only in tests)
     * @return array|null  claims on success, null on any failure
     */
    function lg_shared_verify_looth_id(?string $token, string $keyPath = '/etc/looth/jwt-public.pem'): ?array
    {
        $expIss = lg_shared_looth_expected_iss();

        // The passed token first.
        $claims = lg_shared_verify_looth_id_sig($token, $keyPath);
        if ($claims !== null && ($expIss === null || ($claims['iss'] ?? '') === $expIss)) {
            return $claims;
        }

        // Passed token is missing or issued for ANOTHER host (e.g. a live
        // .loothgroup.com cookie leaking into this subdomain). Scan every looth_id
        // cookie for one issued for THIS box, so a parallel live login can't mask
        // the real session and logout actually drops auth.
        if ($expIss !== null) {
            foreach (lg_shared_all_cookie_values('looth_id') as $t) {
                if ($t === $token) continue;
                $c = lg_shared_verify_looth_id_sig($t, $keyPath);
                if ($c !== null && ($c['iss'] ?? '') === $expIss) return $c;
            }
        }
        return null;
    }

}
