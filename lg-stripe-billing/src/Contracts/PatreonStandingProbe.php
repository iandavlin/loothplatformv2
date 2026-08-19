<?php

declare(strict_types=1);

namespace LGSB\Contracts;

/**
 * Asks the WordPress side whether a buyer is ALREADY being charged on Patreon.
 *
 * This app cannot answer that itself: its database user holds `lg_membership`
 * and nothing else, so `wp_users` and `wp_options` are closed to it, and the
 * `lg_patreon_members` table it *can* reach would only give it a second,
 * drifting definition of "paying". One definition lives in the plugin that owns
 * the Patreon rail; this contract is how the checkout door borrows it.
 *
 * NULL IS A REAL ANSWER and the most important one: "I do not know." The flag
 * being off, the route being absent, a timeout, a misconfigured URL — all of
 * them are null, and a null lets the buyer through. Failing CLOSED would mean a
 * WordPress hiccup stops every sale on the site; failing open means the rare
 * miss, which the #149 sweep and the Dual Payers admin tab both surface.
 */
interface PatreonStandingProbe
{
    /**
     * @return array{active:bool,tier?:?string,message?:?string,manage_url?:?string}|null
     *         null = unknown; do not act on it.
     */
    public function activeFor(?string $email): ?array;
}
