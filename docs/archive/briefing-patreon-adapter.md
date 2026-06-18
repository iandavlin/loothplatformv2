# Briefing — Patreon adapter (added to poller chat's scope)

Cutover discovery: live runs Patreon, not Stripe. `lg-patreon-onboard`
(plus `lg-looth4-expiry`, `mu-plugins/looth-roles.php`, code-snippet
#44) is the active role-writer there. The poller plugin doesn't exist
on live.

Ian's direction: ship the strangler to live now with Stripe DORMANT,
flip Stripe on later once pricing decisions land. Full pattern in
[STRANGLER-COORDINATION.md §3h](/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md).

## Your new piece: the Patreon adapter

`/whoami` calls `GET /wp-json/looth-internal/v1/user-context/{wp_user_id}`.
On dev, your existing `InternalRestController` answers from the poller's
source rows. **On live (cutover day 1), the same URL needs to return
the same shape but read from `lg-patreon-onboard`'s data.**

You own it because:
- You already own the `user-context` contract
- You wrote the `InternalRestController` pattern
- The adapter is structurally the same — read source(s), derive tier,
  derive provenance, compute capabilities, return JSON

## Scope

1. **New file** `src/Wp/PatreonAdapterController.php` (or whatever name
   matches your conventions) — same REST endpoint signature, same
   shared-secret auth, same response shape as `InternalRestController`.
2. **Tier derivation** — read whatever metadata `lg-patreon-onboard`
   writes to determine current looth tier. (Cutover chat's BATCH-04
   will surface the exact mechanism — pasted output should land in
   `/home/ubuntu/projects/cutover/LIVE-INVENTORY.md` shortly.)
3. **Provenance derivation** — Patreon-paid users are `paid`. looth4
   admin grants from `lg-looth4-expiry` are `comp`. Lapsed Patreon
   users are `lapsed`. Brand-new users with no Patreon record are `new`.
4. **Plugin packaging** — the adapter ships as part of the poller
   plugin (one plugin, two controllers, environment picks which is
   active). Alternative: tiny separate plugin `lg-patreon-internal-rest`
   that lives only on live. **Your call** — single-plugin is fewer
   moving parts; separate-plugin is cleaner isolation. My lean:
   single-plugin with both controllers registered, only the one whose
   data source exists actually responds. But you know the codebase.

## What you do NOT need to do

- Build a new Patreon polling system. `lg-patreon-onboard` already
  does the polling; you're just reading what it wrote.
- Touch the Stripe side of the poller. It ships to live dormant
  (no Stripe creds → no Stripe source rows → no Stripe behavior).
- Modify `lg-patreon-onboard`. Read-only consumer of its data.

## When to start

Wait for cutover chat's BATCH-04 output to land in
`/home/ubuntu/projects/cutover/LIVE-INVENTORY.md`. That's what tells
you the exact data shape `lg-patreon-onboard` writes (user meta?
custom table? options?). Adapter design depends on it.

## Coordination expectations

- `user-context` response shape MUST match dev (`{tier, provenance, capabilities}`)
- Header still `X-LG-Internal-Auth`, secret still `/etc/lg-internal-secret`
- Tier vocabulary unchanged (`public | lite | pro`)
- Provenance enum unchanged (`paid | comp | lapsed | new`)
- Capabilities unchanged (edit_posts, manage_options, edit_archive_poc,
  moderate_forums)

If you find a case where Patreon data can't be cleanly mapped to the
existing enum, **flag it to coordinator before deviating** — same enum
needs to keep working dev↔live, or consumers see drift.

## Reporting

Update your SESSION-HANDOFF.md when adapter ships on dev (running against
the existing poller's data as a sanity test) and again when it's verified
on live against real `lg-patreon-onboard` data.

When ready, ping coordinator. We'll roll the cutover-sequence step that
unblocks profile-app's `/whoami` on live.
