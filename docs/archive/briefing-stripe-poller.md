# Briefing — lg-patreon-stripe-poller chat

Coordination doc: `/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`.
You stay sole writer of looth1..4 roles. Two small additions and one
direction-of-travel note.

## 1. Expose a user-context endpoint

profile-app's `/whoami` will call this on cache miss.

**Shape:**

```
GET /wp-json/looth-internal/v1/user-context/{wp_user_id}
```

**Response:**

```json
{
  "tier": "lite",
  "provenance": "paid",
  "capabilities": {
    "edit_posts": false,
    "manage_options": false,
    "edit_archive_poc": false,
    "moderate_forums": false
  }
}
```

**Notes:**

- **GET, not POST.** Pure lookup — must be cacheable, idempotent,
  curl-debuggable. 30s cache OK on the consumer side.
- `tier` values: `public | lite | pro`. Map looth1→public, looth2→lite,
  looth3→pro, looth4→pro. **Must match §1 of the coordination doc
  exactly** — if you need to deviate, push back on the doc first.
- `provenance` values: `paid | comp | lapsed | new`. See §1 of the
  coordination doc for the mapping. Used by billing-aware UIs only.
- `capabilities` computed via `user_can($wp_user_id, $cap)` for the
  four flags listed. Narrow set — only flags a consumer actually
  checks. Add more as consumers ask.
- `moderate_forums` = "user has bbp_moderator OR bbp_keymaster OR
  administrator capability."
- Auth via shared-secret header. **Mirror archive-poc's existing
  pattern** — please confirm: what env var / constant / file carries
  archive-poc's shared secret today? profile-app wants to reuse the
  same convention so we're not inventing a third.

## 2. Arbiter purge hook (cache invalidation)

When Arbiter writes a role change, ping profile-app to purge the
`/whoami` cache for that user.

**Shape (profile-app-side, you call this):**

```
POST /profile-api/v0/internal/purge-whoami
Content-Type: application/json
X-Looth-Internal-Auth: <shared secret, same key as above>

{ "wp_user_id": 1234 }
```

- Fire-and-forget (don't block the Arbiter write on the response)
- Idempotent on profile-app's side
- Symmetrical direction: poller→profile-app for invalidation,
  profile-app→poller for tier lookup. Same shared-secret key for both.

## 3. Direction of travel (post-cutover, no rush)

See §3e of the coordination doc. The poller will eventually shrink to
a thin shim — webhook reception, polling loop, and Stripe state move
to a standalone service (own systemd unit, own user, no WP filesystem
access). WP-side keeps just the `wp_capabilities` write + footer modal
hook. Not blocking cutover; queue as roadmap. Security driver: stop
carrying Stripe keys in the WP attack surface.

## Open questions to answer back

1. **Shared-secret pattern** — what env var / constant / file carries
   archive-poc's shared secret today? Confirm profile-app should
   mirror it (same name/location convention, different value).
2. **Response shape confirmation** — `{ tier, provenance, capabilities }`
   as above, with tier ∈ `public|lite|pro` and provenance ∈
   `paid|comp|lapsed|new` — anything you'd change?
3. **Arbiter purge hook** — OK with `POST /profile-api/v0/internal/purge-whoami`
   shape, fired on every role write? Any concerns under the timestamp-based
   poller you rewrote this session (e.g. burst writes during a poll tick)?

## Other notes

- The lg-stripe checklist work is unaffected. Keep going on that thread
  — these coordination additions are small and orthogonal.
- `/wp-json/looth/v1/whoami` (note: NOT looth-internal) is a separate
  WP shim that proxies to profile-app's `/profile-api/v0/whoami`. That
  shim is profile-app's responsibility, not yours. Don't confuse them.

Report back to the coordinator chat (Ian routes) when you've read and
have positions on the three open questions.
