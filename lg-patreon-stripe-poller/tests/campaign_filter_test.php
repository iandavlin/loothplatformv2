<?php
/**
 * Guards the HIGH fix: a Patreon patron of a FOREIGN campaign must not be
 * eligible for provisioning. The OAuth onboard reads memberships across every
 * creator the user backs, so this predicate is the only thing standing between
 * "any active patron of anyone" and "a paid Looth tier."
 *
 * Regression target: the literal `false &&` that disabled the filter at
 * lg-patreon-onboard.php — re-introducing it (or weakening this predicate)
 * turns the test red.
 */

declare(strict_types=1);

define( 'LGPO_TESTING', true );

require __DIR__ . '/_assert.php';
require dirname( __DIR__ ) . '/includes/campaign-filter.php';

const OURS    = '4833198';
const FOREIGN = '99999999';

// The vulnerability: a patron on a foreign campaign must NOT match.
lgms_eq( false, lgpo_membership_matches_campaign( FOREIGN, OURS ), 'foreign-campaign patron is rejected' );

// A membership in our campaign matches.
lgms_eq( true,  lgpo_membership_matches_campaign( OURS, OURS ), 'own-campaign patron is accepted' );

// A membership with no campaign id (malformed payload) is rejected when ours is set.
lgms_eq( false, lgpo_membership_matches_campaign( '', OURS ), 'membership with no campaign id is rejected' );

// Fail-open ONLY when lgpo_campaign_id is unconfigured, so a mis-set option
// can't lock out every real patron. (Cut checklist: configure it on live.)
lgms_eq( true,  lgpo_membership_matches_campaign( FOREIGN, '' ), 'unconfigured campaign fails open' );
lgms_eq( true,  lgpo_membership_matches_campaign( '', '' ), 'unconfigured campaign fails open (empty member)' );

lgms_done( 'campaign_filter' );
