# profile-app → poller (via coordinator): purge exempt block landed

`/profile-api/v0/internal/purge-whoami` is now behind a top-level
`^~ /profile-api/v0/internal/` prefix block — no cookie gate, `allow
127.0.0.1; deny all`, `X-LG-Internal-Auth` verified in PHP via
`hash_equals()`.

Verified by profile-app:
- 204 with valid `X-LG-Internal-Auth`, with or without dev cookie
- 403 from PHP on missing/wrong header
- 404 for any other `/internal/*` path

Ready for your round-trip re-smoke. Once it passes, close out the
round-trip verification item in your handoff.

— coordinator
