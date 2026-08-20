<?php

declare(strict_types=1);

namespace LGSB\Core;

use LGSB\Contracts\PatreonStandingProbe;

/**
 * "We should disallow double payment source for the same user." — Ian, 2026-08-19.
 *
 * The decision, on its own, so it can be tested without standing up Slim: given
 * a buyer's email and whether this is a gift, should checkout refuse?
 *
 * WHAT IT REFUSES, and the three things it deliberately does not:
 *
 *   - A GIFT IS NEVER REFUSED, and is never even asked about. A member paying
 *     on Patreon who buys a membership for somebody else is not paying twice.
 *     Not asking (rather than asking and ignoring) also means a slow or dead
 *     WordPress can never delay a gift checkout.
 *   - A CHECKOUT WITH NO EMAIL is not refused. There is no member to attribute
 *     it to, so there is no double payment to prevent, and guessing would block
 *     strangers.
 *   - AN UNKNOWN ANSWER is not refused. See PatreonStandingProbe: null means the
 *     flag is off or WordPress did not answer, and both must leave the site
 *     selling exactly as it does today.
 *
 * ⚠️ WHAT THIS DOOR CANNOT DO, stated rather than left to be discovered. It
 * knows an EMAIL and nothing else — this endpoint is reachable without a
 * WordPress session, which is the whole reason the probe exists. A member who
 * buys under a different address than the one carrying their Patreon linkage is
 * not recognised and is not refused.
 *
 * The other two doors do not have that hole: the WordPress checkout route and
 * the /lgjoin/ page both key on the SESSION's user id, which cannot be typed in.
 * /lgjoin/ also pre-fills a signed-in member's address as a hidden field, so
 * reaching this case at all means posting to the API directly.
 *
 * It is not closable here, and closing it is not obviously right either —
 * refusing a stranger because a DIFFERENT account somewhere pays Patreon would
 * be worse. The residue is caught after the fact: IdentityMatcher links the
 * purchase to the member, and the Dual Payers screen (#149) shows Ian anyone
 * who ends up on both rails.
 */
final class DoublePayGuard
{
    public function __construct(private readonly PatreonStandingProbe $probe) {}

    /**
     * @return array{error:string,patreon_active:bool,manage_url:?string}|null
     *         null = let them buy.
     */
    public function refusalFor(?string $email, bool $isGift): ?array
    {
        if ($isGift) {
            return null;
        }
        $email = $email !== null ? trim($email) : '';
        if ($email === '') {
            return null;
        }

        $standing = $this->probe->activeFor($email);
        if ($standing === null || empty($standing['active'])) {
            return null;
        }

        // The words come from WordPress, so the API and the join page cannot
        // describe two different ways to leave Patreon. The fallback exists
        // only for an answer that is active-but-mute, which should not happen.
        $message = isset($standing['message']) && is_string($standing['message']) && $standing['message'] !== ''
            ? $standing['message']
            : 'Your membership is already paid through Patreon, so buying here would charge you twice.';

        return [
            'error'          => $message,
            'patreon_active' => true,
            'manage_url'     => isset($standing['manage_url']) && is_string($standing['manage_url'])
                ? $standing['manage_url']
                : null,
        ];
    }
}
