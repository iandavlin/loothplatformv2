# Coordinator → membership/poller: membership pages STANDALONE, not WP-templated (2026-05-29)

> **Course-correction.** The earlier task put the membership pages on the shared
> header via a **`template_include` swap on WP pages**. Per the launch invariant
> (`STRANGLER-COORDINATION.md §0b`, Ian): **all launch pages are served
> standalone, NOT WP-templated.** A WP-templated page boots WordPress every load
> (slow); the shim doesn't fix that. So the membership pages become a
> **standalone surface** like archive-poc/bb-mirror — nginx → standalone PHP →
> `require site-header.php` — not WP pages.

## The honest scope
This is the **heaviest** of the standalone conversions. But:

**The money-engine is ALREADY standalone** — `lg-stripe-billing` (Slim app at
`/billing/`) does the Stripe transactions (`CheckoutController`,
`RedeemController`, `GiftActionController`, `WebhookController`). You're not
pulling the payment core out; it's out. The membership *pages* are the UI layer.

## Decomposition (makes it tractable)
- **Display / account pages** (membership-guide, manage-subscription *view*,
  my-gifts, affiliate-earnings) → standalone PHP reading membership state
  **directly from `lg_membership`** (read-only, no WP boot) + `site-header.php`.
  Medium. Do these first — they're the bulk of the "fast page" win.
- **Transactional flows** (join/checkout, gift-buy, redeem, refund) → **lean on
  the billing app's existing standalone surfaces** rather than porting the form
  logic. The standalone membership page renders the entry UI + hands off to
  `/billing/` (already standalone). Don't re-port what the Slim app already does.

## Contract + identity
- `require_once /srv/lg-shared/site-header.php`, pass the **full consumer
  contract incl. `active_nav` + `logout_url`** (§0a — the gap that just bit
  events-landing).
- Identity (for personal pages: manage-sub, my-gifts) via `/whoami` — and the
  **shim-replacement** (chat `d9380b73`) will make that inline/fast. Coordinate
  with it on the verify pattern; don't build a competing identity path.

## Reality flag (for coordinator/Ian, not blocking you)
Membership-standalone likely rivals profile-2.0 as a pre-cut long pole. Ship the
**display pages first** (the fast-page win), route transactions to billing, and
we'll sequence the heavier transactional UI deliberately. Flag your estimate
back so the cut timeline is honest.

## Repo + §0
All in looth-platform. New standalone surface lives under its own dir (mirror
archive-poc's layout) + an nginx route + FPM pool. Edit in repo, commit at end
+ push, deploy to target. The old `template_include` mu-plugin approach is
retired — don't ship it.

— coordinator
