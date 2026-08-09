# `lg-stripe-billing` — the auth gap, and what it blocks

**2026-08-09, `stripe-build` lane.** Found while attempting the checkout-metadata half
of the identity gate (`STRIPE-IDENTITY-AND-LIFECYCLE-DESIGN.md` §3.2). Read-only: no
code changed, no live surface touched, no Stripe API call made.

**Not exploitable on live today.** It becomes exploitable the moment the documented
next deployment step runs. That is why it is written down now rather than later.

---

## 1. The finding

`POST /v1/portal` takes **an email address and nothing else**, and returns a Stripe
Billing Portal URL for whichever customer holds that email.

`src/Http/Controllers/CheckoutController.php:197-219`:

```php
$email = trim((string) ($body['email'] ?? ''));
$customer = $this->customers->findByEmail($email);
$result   = $this->checkout->createPortalSession($customer->id);
return self::json($response, $result);   // { url: "https://billing.stripe.com/..." }
```

There is no session, no token, no signature, and **no middleware on the route group**
(`config/routes.php:20-27`; the app adds only routing, body-parsing and error
middleware in `src/App.php:31-37`).

**The codebase's own convention is the evidence.** `routes.php` annotates the endpoints
that *are* protected:

```php
// Cron-driven reconciliation of orphaned Stripe sessions.
// Auth via X-LGMS-Token; called from the WP plugin's Tick::run.
$g->post('/reconcile-pending', [ReconciliationController::class, 'reconcile']);

// Affiliate management (server-to-server, X-LGMS-Token auth)
$g->get( '/affiliates', [AffiliateController::class, 'list']);
```

So the app already knows how to authenticate an endpoint. `/v1/portal` simply is not
one of them — and it is the endpoint that hands out billing-portal sessions.

A Stripe Billing Portal session typically permits viewing invoices and billing history,
updating or removing the payment method, and cancelling the subscription — the exact set
depends on the portal configuration in the Stripe dashboard, which I did not read (no
dashboard access). **Member email addresses are not secret**, so the only thing standing
between an attacker and another member's billing controls is knowing their address.

## 2. Reachability — why this is latent, not live

| Where | Routed? | Verdict |
|---|---|---|
| **live** | **No.** `grep -rl billing /etc/nginx/` returns nothing; `POST /billing/v1/portal` → **HTTP 404** | **Not reachable. Not exploitable today.** |
| **dev2** | Yes — `location ^~ /billing/` → `/srv/lg-stripe-billing/public` | Reachable, behind the dev gate |

Both re-verified 2026-08-09.

**The trigger is already written into the plan.** The 7/30 audit's Phase 3, item 13:

> *"Add the `/billing/` nginx location to live's vhost, mirroring dev2's trailing-slash
> form."*

That step — and nothing else — turns this from latent into live. It must not ship before
`/v1/portal` is authenticated.

## 3. What it blocks: the identity gate cannot be completed

The identity gate (`lgms_identity_gate`, merged, OFF) resolves a Stripe customer to a WP
account through `IdentityMatcher`, whose second and strongest non-authoritative claim is
`customers.metadata.wp_user_id` — "an explicit bridge, asserted at checkout by a
logged-in member."

Two things are true today:

1. **Nothing ever writes `customers.metadata`.** `PdoCustomerRepository::create()` inserts
   `uuid, stripe_customer_id, email, name, country` and no metadata; there is no
   metadata write anywhere in the repository. So `IdentityMatcher` branches 2 and 3 are
   **structurally unreachable** — the gate can only ever fall through to email, or refuse.
2. **`/v1/checkout` is unauthenticated too.** Every input comes from
   `$request->getParsedBody()`.

So I cannot simply pass `wp_user_id` into the checkout body and store it. **A
client-supplied `wp_user_id` is not an explicit bridge — it is an account-takeover
primitive.** An attacker could pay for a membership while claiming any WP user id, and
`writeBridge` would bind their Stripe customer to that member's account. It would not
grant login, but it would let an attacker attach their subscription to someone else's
account, consume that account's 1:1 bridge slot, and — once retraction lands — cause a
cancellation to fire retraction against a member who never bought anything.

**Both problems need the same missing thing: a trustworthy server-side answer to "which
WP member is making this request".**

## 4. Recommendation

**I have not built this.** The lane charter says *"Coordinate with keeper before touching
any live auth surface"*, and inventing an authentication mechanism for the billing app is
squarely that. The options, for Ian and keeper to rule on:

1. **Short-lived signed handoff token (recommended).** WP mints a token bound to the
   logged-in user (`wp_user_id`, short TTL, HMAC with the existing `lgms_shared_secret`);
   the browser passes it to `/v1/checkout`; the Slim app verifies and *derives*
   `wp_user_id` server-side. Reuses machinery the app already has (`X-LGMS-Token`), keeps
   the id un-forgeable, and closes `/v1/portal` with the same primitive.
2. **Server-to-server checkout.** The browser talks only to WP; WP calls the billing app
   with `X-LGMS-Token` and the identity. Strongest, but a bigger change to the checkout
   flow.
3. **Portal by emailed magic link.** Solves `/v1/portal` alone and is the standard pattern
   for "manage my billing" without a session, but does nothing for checkout identity.

Whichever is chosen, two things should be true before Phase 3 routes `/billing/` on live:

- `/v1/portal` requires proof of identity, not an email.
- `wp_user_id` reaching `customers.metadata` is **server-derived only**, never echoed
  from a request body — otherwise the identity gate's strongest claim becomes its
  weakest.

## 5. Also worth a sweep

`/v1/checkout`, `/v1/redeem`, `/v1/affiliate-click` and `/v1/config` are likewise
unauthenticated. Most are legitimately public (a checkout must be startable by an
anonymous visitor), and I have **not** assessed them individually — this doc is about
`/v1/portal`, which is the one that reads another party's record and returns a
credentialed URL for it. A proper pass over the whole route table belongs with whoever
takes the auth decision.

## 6. Provenance

- Code read at `stripe-build` re-baselined to `origin/main` (`6fa03c4`).
- Live/dev2 nginx and reachability checked 2026-08-09 on-box over loopback with a `Host`
  header — never a public curl, which Cloudflare would challenge into a misleading 403.
- **Nothing was written to live. No auth surface was modified. No Stripe API call made.**
