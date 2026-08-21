<?php

declare(strict_types=1);

namespace LGSB\Contracts;

/**
 * Asks the WordPress side WHO MAY BUY right now (#181).
 *
 * This app cannot answer that itself and must not try: its DB user holds
 * `ALL ON lg_membership` and `USAGE ON *.*`, so `wp_options` and `wp_users`
 * are closed to it, and the soft-launch cohort is a list of WordPress user
 * ids. Reproducing it here would be a second list, free to drift from the one
 * the webhook fence, the entitlement sweep and the header pill all key on.
 * One list, owned by the plugin that owns the rail.
 *
 * ⚠️ NULL MEANS "I DO NOT KNOW", AND HERE THAT REFUSES.
 *
 * This is the deliberate opposite of PatreonStandingProbe, which sits three
 * files away and looks almost identical, so the difference is worth stating
 * plainly. That probe asks "is this buyer ALREADY paying elsewhere" — a
 * question whose unknown answer must never block a sale, because failing
 * closed there would mean a WordPress hiccup stops every purchase on the site
 * to prevent a rare double charge.
 *
 * This probe asks "is this buyer INVITED" during a soft launch whose entire
 * purpose is that strangers cannot buy. An unknown answer that let the buyer
 * through would reopen #181 exactly — and it would reopen it precisely when
 * WordPress is unhealthy, which is not a coincidence an attacker has to be
 * clever to arrange. So unknown refuses, with its own status (503) and its own
 * sentence, never the 403's.
 *
 * The cost is stated rather than hidden: while WordPress cannot answer, nobody
 * can start a checkout. That is survivable because the join page's own flow
 * already cannot proceed without WordPress — its JS requires
 * `POST /wp-json/lg-member-sync/v1/auth` to succeed before it ever calls this
 * app — so the only caller a fail-open would actually serve is one posting
 * straight at the API, which is the caller this exists to refuse.
 */
interface CheckoutAudienceProbe
{
    /**
     * @return array{state:string,allowed:bool,message:?string}|null
     *         null = unknown. The caller must REFUSE, not proceed.
     *         state is one of 'off' | 'allowlist' | 'on'.
     */
    public function decide(?string $email): ?array;
}
