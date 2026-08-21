<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use LGSB\Contracts\CheckoutAudienceProbe;
use LGSB\Contracts\SettingsStore;

/**
 * Asks WordPress, over the shared-secret channel this app already uses for
 * /sync-customer, /patreon-standing and the gift mailer.
 *
 * THERE IS NO SWITCH IN THIS APP, and that is the point — the state lives in
 * one `wp_options` row and is REPORTED here, never mirrored. A copy of the
 * state in this app's `.env` would be a second switch, free to disagree with
 * the first, and the disagreement would be invisible until the night it
 * mattered.
 *
 * ⚠️ EVERY NON-ANSWER IS A REFUSAL, INCLUDING 404. Its sibling
 * HttpPatreonStandingProbe treats a 404 as "the flag is off, stay out of the
 * way", because there the route's absence IS the off state. Here the route is
 * registered unconditionally and answers `state: "off"` when the audience is
 * off, so a 404 is never a legitimate answer — it means a flushed rewrite, a
 * deactivated plugin, a namespace typo or a half-finished deploy, and every one
 * of those must not read as permission. The one thing that opens this door is
 * WordPress saying so.
 *
 * ⚠️ THE 401 THAT MAKES THIS ROUTE UNIQUE. Measured on dev2 2026-08-21, from
 * 127.0.0.1, with the correct secret: every other shared-secret route in this
 * namespace answers 401 `bb_rest_authorization_required`, because BuddyBoss's
 * `bb-enable-private-rest-apis` pre-empts the REST stack before any route's own
 * permission_callback. `/checkout-audience` is exempted from that restriction
 * by the plugin that registers it — the narrow repair, using BuddyBoss's own
 * documented hook. If this probe ever starts returning null across the board,
 * check that exemption first: it is the single most likely cause, and the log
 * line names the HTTP code so the answer is one grep away.
 */
final class HttpCheckoutAudienceProbe implements CheckoutAudienceProbe
{
    public function __construct(private readonly SettingsStore $settings) {}

    public function decide(?string $email): ?array
    {
        $url    = $this->settings->getCheckoutAudienceUrl();
        $secret = $this->settings->getSyncSharedSecret();
        if ($url === '' || $secret === '') {
            return null;   // unconfigured is unknown, and unknown refuses
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
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-LGMS-Token: ' . $secret,
            ],
            CURLOPT_POSTFIELDS => json_encode(['email' => (string) $email]),
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

        if ($code !== 200 || !is_string($body)) {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['state']) || !is_string($decoded['state'])) {
            return null;
        }

        $state = strtolower(trim($decoded['state']));
        // A state we do not recognise is not a state we may act on. Reading an
        // unknown value as anything other than "unknown" is how a future
        // fourth state would silently behave like whichever branch we guessed.
        if (!in_array($state, ['off', 'allowlist', 'on'], true)) {
            return null;
        }
        if (!array_key_exists('allowed', $decoded)) {
            return null;
        }

        return [
            'state'   => $state,
            'allowed' => (bool) $decoded['allowed'],
            'message' => isset($decoded['message']) && is_string($decoded['message'])
                ? $decoded['message']
                : null,
        ];
    }
}
