<?php

declare(strict_types=1);

namespace LGSB\Core;

use LGSB\Contracts\CheckoutAudienceProbe;

/**
 * WHO MAY MINT A CHECKOUT SESSION (#181, Ian: "fix before go-live").
 *
 * The decision on its own, so it can be tested without standing up Slim: given
 * the email a caller supplied, should this app refuse — and with which of the
 * two refusals?
 *
 * WHAT IT REFUSES, and it is a shorter list of exceptions than DoublePayGuard's
 * on purpose:
 *
 *   - A GIFT IS FENCED LIKE ANYTHING ELSE (approved 2026-08-21). DoublePayGuard
 *     exempts gifts because buying for somebody else is not double-paying —
 *     true, and irrelevant here. During a soft launch a stranger buying a gift
 *     is still a stranger transacting, and the redemption path reaches
 *     `Sync::customer` -> the Arbiter just as a direct purchase does. So this
 *     guard takes no `isGift` argument at all: there is no exception to forget
 *     to apply, and no signature that invites one to be added later.
 *
 *   - A CHECKOUT WITH NO EMAIL IS REFUSED, which is the exact inversion of the
 *     guard beside it, and the single most important line in this file. That
 *     guard passes an anonymous checkout correctly — with nobody named there is
 *     no double payment to prevent. Here, an anonymous poster naming nobody IS
 *     the caller #181 was opened for: `POST /billing/v1/checkout` carries no
 *     auth, `/billing/v1/products` hands out the real price ids, and that pair
 *     minted a live Stripe session for anyone who asked.
 *
 *   - AN UNKNOWN ANSWER IS REFUSED, with a 503 and a different sentence. See
 *     CheckoutAudienceProbe for why the sibling's fail-open is right there and
 *     wrong here.
 *
 * ⚠️ WHAT THIS DOOR CANNOT DO, stated rather than left to be discovered — the
 * same hole DoublePayGuard names, and it lands harder here. This endpoint is
 * reachable without a WordPress session, so it knows an EMAIL and nothing else,
 * and an email can be typed. A stranger who types a cohort member's address
 * gets past THIS check.
 *
 * It is not closable here, and it does not need to be, because it is not the
 * only check. `UserProvisioner::findOrProvision` asks the same question inside
 * WordPress at the moment an account would actually be created, and a typed
 * address buys nothing there: the buyer is provisioned against the customer
 * record Stripe returns, and a cohort member's address resolves to the cohort
 * member's account, not to the stranger. So the worst this hole yields is a
 * stranger paying for somebody else's membership — visible, refundable, and
 * loudly logged at both ends.
 *
 * That division is deliberate: this door exists to refuse HONESTLY AND EARLY,
 * before Stripe is touched and before anyone is charged. The door that exists
 * to be UNBYPASSABLE is the provision fence.
 */
final class CheckoutAudienceGuard
{
    /** HTTP status for "you are not in the test group". */
    public const STATUS_REFUSED = 403;
    /** HTTP status for "we could not find out". Deliberately not 403. */
    public const STATUS_UNKNOWN = 503;

    private const FALLBACK_REFUSAL = 'Memberships are not open for sale yet. We are running a small, '
        . 'invited test group first — if you would like to be part of it, get in touch.';

    private const UNKNOWN_MESSAGE = 'We could not verify access to checkout just now. '
        . 'Please try again in a moment.';

    public function __construct(private readonly CheckoutAudienceProbe $probe) {}

    /**
     * @return array{error:string,audience:string,status:int}|null
     *         null = let them buy.
     */
    public function refusalFor(?string $email): ?array
    {
        $decision = $this->probe->decide($email);

        if ($decision === null) {
            return [
                'error'    => self::UNKNOWN_MESSAGE,
                'audience' => 'unknown',
                'status'   => self::STATUS_UNKNOWN,
            ];
        }

        // `off` is today exactly: nothing was consulted, nobody is refused.
        // `on` is general availability.
        if ($decision['state'] === 'off' || $decision['state'] === 'on') {
            return null;
        }

        if (!empty($decision['allowed'])) {
            return null;
        }

        // The words come from WordPress, so the API, the join page and the
        // WordPress checkout route cannot describe one refusal three ways. The
        // fallback covers an answer that refuses but says nothing, which should
        // not happen.
        $message = isset($decision['message']) && is_string($decision['message']) && $decision['message'] !== ''
            ? $decision['message']
            : self::FALLBACK_REFUSAL;

        return [
            'error'    => $message,
            'audience' => $decision['state'],
            'status'   => self::STATUS_REFUSED,
        ];
    }
}
