# → poller: standalone /manage-subscription/ — read-only Patreon membership (launch-critical)

> ⚠️ **REFINEMENT (Ian, 2026-06-01): admins SEE the Stripe stuff; users don't.**
> "Stripe dormant" ≠ "Stripe invisible" — it's the *testable* half of "dormant but testable":
> - **Regular users** → Patreon read-only view (as built below).
> - **Admins** (`capabilities.manage_options` from the whoami ctx your `lib/whoami.php` already
>   caches) → ALSO render the **Stripe section**: the plan-switch / payment-method / cancel-timing /
>   existing-account controls + Stripe subscription info from the legacy `[lg_manage_subscription]`
>   shortcode. Gate that whole block behind the admin check. This is how Stripe gets *tested* at cut
>   without exposing it to members.
> The Patreon read-only part is done + staged; this is the increment to add before deploy.
> (Same admin-gated pattern will apply to the other money pages later — not now.)

The ONLY launch-critical money page. At cut Stripe is dormant, so for **users** this is
**read-only**: their current **Patreon** membership. No user-facing Stripe, no form, **no nonce**.

## Build
- New standalone surface mirroring the `/membership-guide/` PoC shape: reuse
  `membership-pages/config.php` + `lib/whoami.php` (cached whoami + §0a ctx), `web/`.
- **Read the user's membership from the poller DB direct (PDO)** — tier + source + status
  from the Patreon/subscription/entitlement repos (`src/Repos/SubscriptionRepo`,
  `EntitlementRepo`, `Patreon/PatreonSourceReader`). Render the current membership.
- **"Manage on Patreon" link out** — Patreon billing is managed on Patreon's site
  (`get_option('lgpo_patreon_link')` or the Patreon account URL). No in-app mutation.
- Wear the shared header (`lg_shared_render_site_header($ctx)`).
- nginx: add a `^~ /manage-subscription/` location in `strangler-membership.conf` (copy the
  `/membership-guide/` block — own FPM pool, no WP boot). WP-templated version stays as
  rollback (remove the nginx location → falls back).

## Out of scope (Stripe-A-later, NOT now)
Stripe checkout, plan changes, cancel, gifts, affiliate, refunds — all dormant at cut.
This page just *shows* the Patreon membership; "change it" = the Patreon link.

## Done
node/php -l clean · loads at `/manage-subscription/` showing a real member's Patreon tier ·
shared header · WP fallback intact. (Coordinator commits by pathspec + applies/tests.)

— coordinator (relaying Ian)
