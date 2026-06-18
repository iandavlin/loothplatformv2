# Bootstrap — shim-replacement chat

You own the **shim-replacement** lane. **Pre-cut REQUIRED** (Ian, 2026-05-29):
the new stack must be fast on day one — no slow first experience. Read this,
then the design doc.

## Goal
**Mint a `looth_id` JWT at WP login → surfaces verify the JWT inline → retire
the per-page `/whoami` loopback.** Today every authenticated render on a
strangler surface does a synchronous loopback to `/wp-json/looth/v1/whoami`,
which boots all of WordPress — **measured ~1.5s+ per render on bb-mirror** (the
`wp-json/` bootstrap floor is 2.6s), and it taxes *every* surface, not just the
forum. This kills it for all of them at once.

## Read first
- `docs/briefing-shim-replacement-design.md` — the full design (mint endpoint,
  WP hooks, consumer inline-verify, key distribution, failure modes, test plan).
  It's ~90% of your spec.
- `docs/STRANGLER-COORDINATION.md` §2 (`/whoami`), §0 (commit discipline).

## Your scope
- **Mint endpoint** (profile-app): `POST /profile-api/v0/internal/mint-token`,
  shared-secret authed, `wp_user_id` → signed `looth_id` JWT. profile-app
  already has the RS256 signing + `buildForWpUserId` (slice 3.5) — thin add.
  **Signing key stays in profile-app**; WP calls this endpoint.
- **WP login hooks** (mu-plugin): `wp_login` / `set_logged_in_cookie` / the
  membership-signup auto-login (poller `UserProvisioner`) / password-reset →
  mint → set the `looth_id` cookie. `wp_logout` / `clear_auth_cookie` → clear.
  **Cover EVERY entry point** — a missed one leaves those sessions
  shim-dependent.
- **Key distribution + consumer-verify pattern**: RS256 → consumers verify with
  the PUBLIC key (no secret). Define how consumers get the public key; provide a
  small verify helper.
- **Prove it on bb-mirror first** (it's the measured surface) — show the
  loopback dies, TTFB drops.

**Out of your hands (lanes adopt in-lane):** archive-poc + the shared-header
path adopt the inline-verify *pattern* you provide (public key + helper) in
their own lanes — you don't reach into every consumer's code. You ship the core
+ the pattern + the bb-mirror proof; coordinator relays the pattern to the other
consumers.

## Transition safety (non-negotiable)
- **Additive:** consumers PREFER inline JWT, **fall back to the shim if no
  `looth_id` cookie.** Nothing breaks during rollout; the loopback retires only
  after a soak shows all active sessions mint tokens.
- **Mint-down must NOT block login** — WP login still succeeds, cookie just
  absent, consumer falls back to shim. Graceful degradation required.

## Build order
1. Quick design-confirm (briefing's 90% there) → 2. mint endpoint → 3. WP login
hooks (all entry points) → 4. bb-mirror inline-verify + fallback → prove
loopback dies → 5. provide the pattern for archive-poc + shared path (lanes
adopt) → 6. soak → retire shim.

## Auth invariant (the cut depends on it)
**Dev-built, dev-proven, SOAKED before the cut.** Live is Claude-free — no live
changes. This must be running clean on dev well before the flip, NOT debuting on
cut day.

## Coordination (route cross-lane through coordinator)
- **profile-app code**: the mint endpoint is additive (new internal endpoint),
  but the profile-2.0 chat also edits `profile-app/` — coordinate on shared
  files (`config.php`, the whoami resolver). Low collision (mostly new code).
- **lg-shell**: the WP-login hooks sit near its auth-reskin surface — coordinate.
- **bb-mirror / archive-poc**: changing their whoami-fetch — they have in-flight
  work; coordinate so it doesn't collide.

## Repo + §0
Everything's in the **looth-platform** repo (`/home/ubuntu/projects`). Edit in
the repo, **commit at end of each change set + push**, deploy to target. Don't
hand-edit deployed copies.

## When you spawn
Capture your session ID + outliner title, report to coordinator for roster + lineage.

## Report-back format
```
**shim-replacement → coordinator:** <one-line status>
<path to your handoff / the changed files>
```

— coordinator
