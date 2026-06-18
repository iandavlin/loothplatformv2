<?php
/**
 * Pure, side-effect-free campaign-eligibility predicate for Patreon onboarding.
 *
 * The OAuth onboard reads a patron's *identity* membership list, which spans
 * EVERY creator they back — not just us. We must only provision off a
 * membership that belongs to OUR campaign, or any active patron of any creator
 * would be granted a paid Looth tier. (LGPO's API sync is already campaign-
 * scoped server-side; this guards the OAuth path, which is not.)
 *
 * Kept in its own no-bootstrap file so it can be unit-tested without loading
 * WordPress. Do NOT add side effects here.
 *
 * @package LG_Patreon_Onboard
 */

defined( 'ABSPATH' ) || defined( 'LGPO_TESTING' ) || exit;

if ( ! function_exists( 'lgpo_membership_matches_campaign' ) ) {
    /**
     * True when a membership belongs to the campaign we provision for.
     *
     * An empty configured campaign id means "not configured" → fail OPEN
     * (match anything) so a mis-set option can't lock out every real patron.
     * With a campaign id set, a foreign-campaign membership is rejected.
     *
     * @param string $member_campaign_id   Campaign id on the patron's membership.
     * @param string $configured_campaign_id Our campaign id (lgpo_campaign_id).
     */
    function lgpo_membership_matches_campaign( string $member_campaign_id, string $configured_campaign_id ): bool {
        if ( $configured_campaign_id === '' ) {
            return true;
        }
        return $member_campaign_id === $configured_campaign_id;
    }
}
