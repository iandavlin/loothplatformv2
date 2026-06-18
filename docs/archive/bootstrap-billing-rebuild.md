# Bootstrap — billing-rebuild lane

You own the **billing-rebuild** lane. Goal: take WordPress off the **entitlement
authority** path so WP becomes an admin/authoring console only.

## Read first (in order)
1. `docs/design-membership-rebuild.md` — the full plan. The KEYSTONE box at top
   is the whole reason this lane exists. Read it twice.
2. `docs/STRANGLER-COORDINATION.md` §1 (tier vocab), §2 (`/whoami` contract +
   internal-channel auth), §3e (poller-out-of-WP), §3i (one-postgres-N-schemas).

## The current split (grounded — confirm it yourself before changing anything)
- **Money engine** `/srv/lg-stripe-billing/` — standalone, holds Stripe keys (env),
  Stripe checkout + webhooks + `EntitlementManager` + `GiftCode`. STAYS where it is.
- **Poller plugin** `lg-patreon-stripe-poller` (in WP) — `PatreonSourceReader`,
  `Arbiter` (picks winning tier), `RoleSourceWriter` (writes `wp_capabilities`),
  the internal `user-context` tier endpoint. THIS is what comes out of WP.
- **profile-app** `Whoami.php` — reads tier via loopback to the WP user-context
  endpoint. Never touches Patreon/Stripe.

## Build order (each step independently shippable + dev-soakable; all POST-CUT)
1. **Relocate the poller** (behaviour-identical). Arbiter + Patreon reader +
   role-writer + user-context endpoint → standalone `billing-svc`. Tier still
   written to `wp_capabilities`; profile-app loopback just repoints. Lowest risk.
2. **Invert tier authority** — billing-svc writes tier into a profile-app
   `member_tier` table (sole writer, internal channel). `/whoami` reads locally;
   the WP loopback retires. `wp_capabilities` becomes a mirror or drops.
3. **Standalone checkout + account pages** call billing-svc directly; WP billing
   pages retire.
4. WP = admin only (modulo the accepted BB forum-write residual — out of scope).

## YOUR FIRST TURN — scaffold + port, NO live repoint (scoped tight)
Do ONLY this; do not touch the live entitlement path yet:
- Scaffold `billing-svc` as a standalone service skeleton (mirror
  `/srv/lg-stripe-billing/` conventions: own dir under the repo, own pg schema
  `billing` per §3i, FPM pool + systemd unit *files* — not installed).
- **Port `Arbiter` + `PatreonSourceReader` logic as framework-free PHP** into
  billing-svc (copy, don't move — WP plugin keeps running untouched). Strip the
  WP-isms (`get_user_meta`, `wp_capabilities` writes) behind a small interface so
  the same arbitration logic can target either WP roles (today) or the
  profile-app `member_tier` table (step 2).
- Unit-test the arbitration: same source rows → same winning tier as the WP
  Arbiter. This is the proof the extraction is faithful.
- Write `billing-svc/MIGRATION-NOTES.md`: what's ported, the interface seam, and
  exactly what step-2 repoint will touch.

**Security — bake it into the scaffold from turn 1 (design doc §5b is binding):**
- The interface seam (WP roles today → `member_tier` later) is the future
  entitlement-grant path — design it as **single-writer** from the start.
- The ported Arbiter MUST **fail closed**: null/empty/ambiguous sources → public
  (`looth1`), never a paid tier. Write the unit test that asserts this FIRST, before
  the happy-path tests — it's the security-critical default (§5b-C).
- Plan the `billing` pg schema with a **single writer role** + an immutable
  **audit table** `(user_uuid, old, new, source, event_id, actor, ts)` for every
  grant (§5b-A/E), and an `event_id` column so applies are idempotent/replay-safe.
- Note (don't build yet) which internal endpoints step-1/step-2 add so the
  coordinator can keep them loopback-only (§5b-D). No public location blocks, ever.
- Keep Stripe creds OUT of anything profile-app can reach (§5b-B) — the scaffold
  must not introduce a path from the fat app to payment secrets.

**Do NOT** in this turn: repoint profile-app's loopback, install the systemd unit,
write to `wp_capabilities` from outside WP, or change any live behaviour. This is
a dark-launch scaffold proving the lift.

## Discipline
- WRITE-ONLY turns (sandbox blocks git/`php -l`/apply). Coordinator commits by
  pathspec + lints + tests after.
- Edit in the repo; never hand-edit deployed copies (§0).
- Payment-adjacent code: when unsure, STOP and flag the coordinator rather than
  guess. Nothing in this lane changes live money flow without an explicit,
  separately-tested step.
- Shared files: `profile-app/` is also touched by profile-2.0 + shim — flag the
  coordinator before editing `config.php` / `Whoami.php`.

## Report-back
```
**billing-rebuild → coordinator:** <one-line status>
<changed files for pathspec commit> · <what coordinator must test> · <flags>
```

— coordinator
