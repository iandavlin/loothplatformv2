# Coordinator → lg-shell: header account dropdown (new scope)

A membership-page IA decision landed (contract §3k). It adds one thing to your
header: **an account dropdown.**

## What's there today

`.lg-chrome__account` (site-header.php ~line 204) is a **plain link** to
`profile_url` — avatar + name + tier pill. There's **no dropdown and no
sign-out anywhere in the header.**

## What's needed

Convert `.lg-chrome__account` into a **dropdown trigger**. On click, open a
canonical sitewide account menu. Items (logged-in):

| Label | Route | Condition |
|---|---|---|
| Edit Profile | `profile_url` (`/members/me/`) | always |
| Manage Subscription | `/manage-subscription/` | always (page self-handles non-members) |
| Membership Guide | `/membership-guide/` | always |
| My Gifts | `/my-gifts/` | always |
| Gift Memberships | `/lggift-buy/` | always |
| Redeem a Gift | `/lggift/` | always |
| Request a Refund | `/request-refund/` | always (page self-handles non-members) |
| Affiliate Earnings | `/affiliate-earnings/` | affiliates only — see open item |
| Sign out | `wp_logout_url()` (nonce'd) | always |

These route to **poller-rendered pages** (different app) — that's fine and
intended. The header is the unifying IA layer; it doesn't own those pages, just
links them.

**Why this exact set** (it's the replacement for the poller's `[lg_member_nav]`
strip, which carries 9 visibility-filtered items today):
- **4 fold straight in:** Manage Subscription, My Gifts, Affiliate Earnings,
  Membership Guide.
- **Join** is omitted on purpose — it's already the anonymous header button, and
  it's guests-only (hidden once logged in), so it has no place in a member menu.
- **Test Checklist** is omitted — admin-QA-only, reached via direct URL / wp-admin.
- **Gift Memberships, Redeem a Gift, Request a Refund** are added — they have **no
  other home** once the strip is gone. All three self-gate server-side, so
  `always` is safe with no new context signal.

## Why this is yours

The account menu is sitewide chrome — one menu everywhere — so it belongs *in*
the header, not passed in by each consumer. Gate items off the `tier` / `caps`
already in your context. The "dumb partial" principle still holds: a canonical
account menu is a chrome decision, not a per-page one.

## One open item — pick and flag

**Affiliate Earnings is conditional** (only affiliates should see it), but the
header context has no `is_affiliate` signal today. Options:
- (a) Add `is_affiliate` (bool) to the header context; consumers pass it.
- (b) Always show it — the `/affiliate-earnings/` page already handles
  non-affiliates gracefully (poller chat confirms).
- (c) Derive from a capability if one exists.

Lean (b) for now (simplest, no contract change); revisit if it's noisy. Your call.

## Coordination

The **poller** is concurrently putting its membership pages on your header
(`briefing-membership-pages.md`) — its `/membership-guide/` PoC already renders on
your shared chrome (anon + member) as of 2026-05-29. Your dropdown is what their
pages' old `[lg_member_nav]` strip collapses into.

**Hard dependency, both directions:**
- The poller will **not** drop `[lg_member_nav]` until your dropdown carries the
  **full** item set above — a subset would orphan Gift Memberships / Redeem a Gift
  / Request a Refund. Ship the complete list.
- If you change the header contract (e.g. add `is_affiliate`), tell coordinator
  before merging — the poller is mid-integration against the current `tier`/`caps`
  shape, and a contract change in-flight breaks their pages.

## Priority

Your call vs. P9 modals. The dropdown is small and unblocks the poller's
membership-page work feeling complete, so I'd slot it before or alongside the
notification modal. Not a hard gate.

## Report back

```
**lg-shell → coordinator:** account dropdown shipped, affiliate handled via (a/b/c)
```

— coordinator
