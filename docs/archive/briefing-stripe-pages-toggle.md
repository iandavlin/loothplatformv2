# Lane briefing — admin toggle for the Stripe/purchase pages

You're a focused **membership-pages** lane. One feature. Work in the canonical tree
(`/home/ubuntu/projects/membership-pages/`) — **NOT** a worktree (worktree isolation is shelved; dev
serves live from main). Commit by pathspec; coordinator reviews + the git-tsar pushes.

## Goal
An **admin-only on/off toggle** that turns the Stripe purchase pages public (live) or admin-only
(pre-launch), instead of today's hardcoded gating. Ian builds the Stripe op privately pre-launch and
wants to flip it live without a code edit.

## Current state
`membership-pages/web/router.php` registers pages with a hardcoded `visibility` (see
[[project_stripe_qa_gate_bridge_block]]):
- `visibility:'admin'` (manage_options-only): `lgjoin`, `lggift-buy`, `lggift`, `my-gifts`,
  `test-checklist`, `membership-guide` (+ others) → non-admins get the "isn't available yet" stub.
- `visibility:'public'`: `join` (Patreon funnel). `visibility:'member'`: `manage-subscription`.
The admin gate is `_admin-gate.php` checking `$ctx['capabilities']['manage_options']`.

## Build
- A single WP option flag, e.g. `lg_stripe_pages_live` (default **off** = admin-only).
- When **off**: the purchase pages stay `admin`-gated (today's behavior — safe pre-launch).
- When **on**: the purchase pages serve **public** (or their intended real visibility — confirm which
  pages flip vs which stay member-gated; `test-checklist` should likely STAY admin always).
- Make the router read the flag instead of a hardcoded `'admin'` for the flippable set; keep
  `test-checklist` admin-only regardless.
- **The toggle UI:** a checkbox in the existing poller/member admin settings
  (`/wp-admin/?page=lg-member-sync`, e.g. a "Stripe pages live" switch), `manage_options` only,
  nonce'd. Don't invent a new admin page if an existing tab fits.

## Decide with Ian (via coordinator) before finalizing
- Exactly **which** pages flip public on "live" vs stay member/admin (the list above is a starting set).
- Whether the flag is global or per-page.

## Verify (dev)
- Flag off → `/lgjoin/` etc. show the admin stub to a non-admin, work for an admin (today's behavior).
- Flip on → same pages load for a logged-out/non-admin visitor.
- `test-checklist` stays admin-only in both states.
- Toggling persists + takes effect with no code edit.

## Protocol
Burn in-lane; ping coordinator (via Ian) only for the which-pages-flip decision. Commit by pathspec
(`membership-pages/...`), clean increments after tested change. Report:
`DONE · FILES · VERIFIED (both flag states) · DECISION-NEEDED · BLOCKED`.
