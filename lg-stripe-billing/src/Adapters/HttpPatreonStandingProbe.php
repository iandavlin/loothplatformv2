<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use LGSB\Contracts\PatreonStandingProbe;
use LGSB\Contracts\SettingsStore;

/**
 * Asks WordPress, over the shared-secret channel this app already uses for
 * /sync-customer and the gift mailer.
 *
 * THE FLAG IS THE 404. There is no switch in this app. The plugin registers
 * /patreon-standing only while `lgms_double_pay_block` is on, so with the flag
 * off this probe gets a WordPress rest_no_route 404, returns null, and checkout
 * behaves exactly as it did before. That is what keeps one option row in charge
 * of all three doors instead of three settings that can disagree.
 *
 * The honest cost of that choice, stated rather than buried: with the flag OFF
 * a non-gift checkout still makes one loopback request that 404s. It is pinned
 * to 127.0.0.1 and capped at two seconds on a path that already round-trips to
 * Stripe. Blanking LGMS_PATREON_STANDING_URL disables the probe outright — that
 * is an emergency valve, not the flag, and the flag register says so.
 */
final class HttpPatreonStandingProbe implements PatreonStandingProbe
{
    public function __construct(private readonly SettingsStore $settings) {}

    public function activeFor(?string $email): ?array
    {
        $email = $email !== null ? trim($email) : '';
        if ($email === '') {
            return null;
        }

        $url    = $this->settings->getPatreonStandingUrl();
        $secret = $this->settings->getSyncSharedSecret();
        if ($url === '' || $secret === '') {
            return null;
        }

        // Same scheme guard as WpSync: refuse anything but http/https in case
        // curl was built with gopher://, file://, dict:// and friends.
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-LGMS-Token: ' . $secret,
            ],
            CURLOPT_POSTFIELDS => json_encode(['email' => $email]),
        ]);
        // See WpGiftMailer::resolveToLoopback — Cloudflare bot-challenges
        // server-to-server PHP-curl calls, so resolution is pinned to origin
        // nginx rather than going out and back.
        $host = $parts['host'] ?? '';
        if ($host !== '') {
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:127.0.0.1"]);
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 404 = flag off, no route. Anything else non-200 = WordPress could not
        // answer. Both are "unknown", and unknown never blocks a sale.
        if ($code !== 200 || !is_string($body)) {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !array_key_exists('active', $decoded)) {
            return null;
        }

        return [
            'active'     => (bool) $decoded['active'],
            'tier'       => isset($decoded['tier']) && is_string($decoded['tier']) ? $decoded['tier'] : null,
            'message'    => isset($decoded['message']) && is_string($decoded['message']) ? $decoded['message'] : null,
            'manage_url' => isset($decoded['manage_url']) && is_string($decoded['manage_url']) ? $decoded['manage_url'] : null,
        ];
    }
}
