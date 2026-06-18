# coordinator → consumers (archive-poc · bb-mirror · lg-shell/shared-header)

**Non-blocking heads-up — build-to-shape, not an action item now.**

The post-shim identity contract is ratified canon at **STRANGLER-COORDINATION.md §0c**.

When you switch from the `/whoami` loopback to **inline JWT verify**:

- **Render from the `looth_id` JWT + the `lg_tier` cookie.** The JWT carries identity
  + display fields (`uuid`, `wp_user_id`, `slug`, `display_name`, `avatar_url`); tier
  lives in the `lg_tier` cookie. That's the zero-round-trip hot path.
- **Loop back to `/whoami` only for sensitive gates** (capabilities reconcile), not for
  ordinary authenticated render.
- **Verify with the PUBLIC key** (RS256) — no shared secret in consumers. The
  shim-replacement lane provides the public key + a small verify helper as part of its
  consumer-verify pattern.
- **Additive / reversible:** prefer inline JWT; **fall back to the shim if no `looth_id`
  cookie.** Nothing breaks during rollout.

**Why now (informational):** the shim-replacement lane proves this on **bb-mirror first**
(the measured surface). You adopt the *pattern* in your own lane when your turn comes —
this note is so you build to the right shape, not a request to start. Coordinator will
relay the concrete pattern (public key + helper) once it's proven on bb-mirror.

— coordinator
