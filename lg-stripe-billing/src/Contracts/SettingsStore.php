<?php

declare(strict_types=1);

namespace LGSB\Contracts;

/**
 * Slim's view of plugin configuration.
 *
 * Trimmed to only what the user-facing API needs. Tier/price/region
 * state lives in the database (ProductRepository); webhook secrets,
 * mail config, and CRM tagging live in the polling WP plugin.
 */
interface SettingsStore
{
    public function getSecretKey(): string;

    public function getPublishableKey(): string;

    public function getCheckoutReturnUrl(): string;

    public function getHomeUrl(): string;

    /** URL of the WP plugin's sync-customer REST endpoint. Empty string = disabled. */
    public function getSyncEndpointUrl(): string;

    /** URL of the WP plugin's send-gift-codes REST endpoint. Empty string = disabled. */
    public function getGiftMailUrl(): string;

    /** Shared secret for the X-LGMS-Token header. Empty string = disabled. */
    public function getSyncSharedSecret(): string;

    /**
     * URL of the WP plugin's patreon-standing REST endpoint (#150). Derived
     * from the sync URL when unset, so there is no second switch to forget —
     * the FLAG is the wp_option `lgms_double_pay_block`, which decides whether
     * that route exists at all. Empty string = the probe is disabled outright
     * (an emergency valve, not the flag).
     */
    public function getPatreonStandingUrl(): string;

    /**
     * URL of the WP plugin's checkout-audience REST endpoint (#181). Derived
     * from the sync URL when unset, so no box needs an env edit — the single
     * switch is the wp_option `lgms_checkout_audience`, which the route
     * REPORTS rather than being registered by.
     *
     * ⚠️ Empty string does NOT mean "disabled" here, unlike the sibling above.
     * It means the probe cannot ask, which is an unknown answer, and unknown
     * REFUSES. There is no emergency valve on this one on purpose: a valve that
     * silently opens a soft launch to the whole internet is not a valve, it is
     * the defect. To stop enforcing, set the option to `off` in WordPress,
     * where the change is visible and attributable.
     */
    public function getCheckoutAudienceUrl(): string;

    /** Stripe webhook signing secret (whsec_…). Empty string = signature check skipped. */
    public function getWebhookSecret(): string;

    /**
     * Bulk discount tiers parsed from BULK_DISCOUNT_TIERS env var ("10:10,20:20,50:30").
     * Sorted descending by min_qty. Empty array = no bulk discounts.
     *
     * @return array<array{min:int,pct:int}>
     */
    public function getBulkDiscountTiers(): array;

    /**
     * URL of the WP page shown when a regional billing-country check fails.
     * Sourced from APP_REGIONAL_FAIL_URL; falls back to getHomeUrl() if unset.
     * The return handler appends query params: reason=region_mismatch&region_tag=...
     */
    public function getRegionalFailUrl(): string;

    /**
     * URL of the WP page shown after a successful checkout completion.
     * Sourced from APP_RETURN_SUCCESS_URL; falls back to getHomeUrl() if unset.
     * The return handler appends query params: kind=subscription|gift|...&tier=...
     */
    public function getReturnSuccessUrl(): string;

}
