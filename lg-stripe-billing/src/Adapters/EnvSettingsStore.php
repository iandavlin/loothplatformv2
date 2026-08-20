<?php

declare(strict_types=1);

namespace LGSB\Adapters;

use LGSB\Contracts\SettingsStore;
use LGSB\Core\BulkPricer;

final class EnvSettingsStore implements SettingsStore
{
    public function getSecretKey(): string
    {
        return self::env('STRIPE_SECRET_KEY');
    }

    public function getPublishableKey(): string
    {
        return self::env('STRIPE_PUBLISHABLE_KEY');
    }

    public function getCheckoutReturnUrl(): string
    {
        $base = rtrim(self::env('APP_BASE_URL'), '/');
        return $base . '/v1/return?session_id={CHECKOUT_SESSION_ID}';
    }

    public function getHomeUrl(): string
    {
        return self::env('APP_HOME_URL');
    }

    public function getSyncEndpointUrl(): string
    {
        return self::env('LGMS_SYNC_URL');
    }

    public function getGiftMailUrl(): string
    {
        return self::env('LGMS_GIFT_MAIL_URL');
    }

    public function getSyncSharedSecret(): string
    {
        return self::env('LGMS_SHARED_SECRET');
    }

    public function getPatreonStandingUrl(): string
    {
        $explicit = trim(self::env('LGMS_PATREON_STANDING_URL'));
        if (strcasecmp($explicit, 'off') === 0) {
            return '';   // emergency valve: stop probing without touching WordPress
        }
        if ($explicit !== '') {
            return $explicit;
        }
        // Otherwise DERIVED, so no box needs an env edit for #150 to work: the
        // two routes are siblings in the same REST namespace. Deriving is what
        // keeps `lgms_double_pay_block` the only switch — an env var that had
        // to be set per box would be a second one, free to disagree.
        $sync = self::env('LGMS_SYNC_URL');
        if ($sync === '' || !str_ends_with($sync, '/sync-customer')) {
            return '';
        }
        return substr($sync, 0, -strlen('/sync-customer')) . '/patreon-standing';
    }

    public function getWebhookSecret(): string
    {
        return self::env('STRIPE_WEBHOOK_SECRET');
    }

    public function getBulkDiscountTiers(): array
    {
        return BulkPricer::fromEnvString(self::env('BULK_DISCOUNT_TIERS'))->tiers();
    }

    public function getRegionalFailUrl(): string
    {
        $url = self::env('APP_REGIONAL_FAIL_URL');
        return $url !== '' ? $url : $this->getHomeUrl();
    }

    public function getReturnSuccessUrl(): string
    {
        $url = self::env('APP_RETURN_SUCCESS_URL');
        return $url !== '' ? $url : $this->getHomeUrl();
    }

    private static function env(string $key): string
    {
        $v = $_ENV[$key] ?? getenv($key);
        return is_string($v) ? $v : '';
    }
}
