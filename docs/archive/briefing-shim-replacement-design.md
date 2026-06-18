# Coordinator → profile-app (lead) + lg-shell: design doc — mint looth_id at login, retire the shim

**Commission a DESIGN DOC first — not code.** This is auth; it gets designed and
dev-tested before anything ships. profile-app leads (owns the JWT machinery,
`buildForWpUserId`, the signing key, and the shim mu-plugin). lg-shell
coordinates (owns the login/auth surface).

## The change in one line

Issue the `looth_id` JWT at the moment WP establishes a session — so every
logged-in user has one — and retire the per-request shim + the per-page
`/whoami` loopback in favor of inline JWT verification.

## Why

- **Perf:** replaces a per-pageview HTTPS loopback (to re-derive identity from
  the WP cookie) with a once-per-login mint + inline RS256 verify. Removes WP
  from the per-request hot path for identity — matters most at mobile scale.
  **Measured tax (bb-mirror, 2026-05-29, warm loopback):** `wp-json/` REST
  bootstrap floor ~2.6s; `/whoami` 1.5–2.4s; this adds ~1.5s to every
  authenticated forum render *even with* the HTTP/1.1 fix already applied —
  it's pure WP-boot cost. archive-poc hit the same class. The shim kills it for
  every surface at once. (Interim: bb-mirror ships a /dev/shm whoami cache,
  removed when the shim lands.)
- **Reliability:** retires the shim, killing the regression class that just bit
  us (the 10:55 clobber).

## Hard scope boundary

This is **NOT** the auth-authority inversion. WP still verifies passwords and
owns the user store. We only *also* issue our token at WP's login moment. Keeping
that boundary is what makes this a **pre-cut, dev-testable** change that satisfies
the cutover invariant: *no novel auth on cut day; ship only dev-proven auth.*
The credential/authority inversion stays a separate post-cut project.

## What the design doc must cover

1. **Mint endpoint** — e.g. `POST /profile-api/v0/internal/mint-token`,
   shared-secret authed (`X-LG-Internal-Auth`), input `wp_user_id`, returns a
   signed `looth_id` JWT. Contract, error modes. **Signing key stays in
   profile-app** — WP calls this endpoint; WP never holds the private key.
2. **WP hooks — cover EVERY session-establishment path**, or some users get no
   token: `wp_login` (form + programmatic), `set_logged_in_cookie`, the
   membership-signup auto-login (poller `UserProvisioner`), password-reset
   auto-login. And `wp_logout` / `clear_auth_cookie` → clear the `looth_id`.
   Enumerate them; a missed entry point = a silently shim-dependent session.
3. **Cookie attributes** — domain, path, HttpOnly, Secure, SameSite, expiry
   (align with WP session + remember-me).
4. **Key distribution for inline verify** — RS256 means consumers verify with
   the PUBLIC key (no secret). Define how archive-poc / bb-mirror get the public
   key. Private key never leaves profile-app.
5. **Consumer migration** — archive-poc + bb-mirror switch from loopback-`/whoami`
   to inline JWT verify. **Transition plan:** during rollout, if no `looth_id`
   cookie present, fall back to the shim — so it's incremental and reversible,
   not a big-bang.
6. **Failure modes** — mint endpoint down at login MUST NOT block login. WP login
   still succeeds; `looth_id` is simply absent for that session; consumers fall
   back to the shim. Graceful degradation is a requirement, not a nicety.
7. **Shim retirement criteria** — once every session reliably mints a token (all
   entry points covered, fallback unused for N days on dev), retire the shim's
   whoami translation.
8. **Dev test plan** — prove on dev: log in via each entry point → `looth_id`
   present → consumers authenticate inline (no loopback) → logout clears it →
   mint-down → graceful shim fallback. Measure TTFB delta on a logged-in
   strangler page (loopback removed).

## Process

- Deliverable: the design doc (path your choice under profile-app/ or docs/).
  **Do not start the build until the design is reviewed.** Surface it to
  coordinator; cross-cutting bits (cookie contract, consumer verify) get a
  quick ratification pass, then build.
- lg-shell: review the WP-hook + login-surface sections — the cookie set/clear
  hooks live next to your auth-reskin work.
- archive-poc / bb-mirror: review §5 (the inline-verify + fallback) — you're the
  consumers who change.

Report back:
```
profile-app → coordinator: shim-replacement design doc ready for review
<path>
```

— coordinator
