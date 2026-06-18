# Briefing — comments + reactions lane (fresh, 2026-06-05)

You own the **content comments and reactions/likes backends** going forward — consolidating two
dev-proven lanes (comments-db + stream reactions) into one. Both backends already work; your job is
the open follow-ons + keeping them coherent as the Hub feed wires into them.

## Sanity-check the box first
`curl -s ifconfig.me` → `50.19.198.38` and `whoami` → not root means you're ON the dev box; act
locally, don't SSH. Code lives in the canonical tree `/home/ubuntu/projects/archive-poc/`.

## What already shipped + is dev-proven (DON'T rebuild)
**Comments** (handoff: `docs/SESSION-HANDOFF-comments-db.md`, commit 99c00cb):
- `discovery.comments` keyed `(post_type, item_id)`, self-ref `parent_id` threading, `user_uuid`
  author. Read endpoint `api/v0/comments.php` — WP-free, **34ms cold / 20ms warm**, returns the
  styled modal iframe HTML. Write endpoint `api/v0/comment-post.php` — boots WP **deliberately**
  (gate = WP login cookie, not /whoami, because unbridged members are anon to /whoami). CSRF = WP
  nonce. Backfill `bin/backfill-comments.php` (`--all` at cutover).
- Hub feed wires the modal inline (hub lane did this); `discovery.comments` SELECT granted to bb-mirror.

**Reactions / likes** (stream lane, commit 049e7d9):
- `discovery.likes` + `POST /archive-api/v0/like` (IDOR-proof, HMAC CSRF). Entitlement-gated download
  via X-Accel. Anon-gated + authed-e2e proven.

## Open items — your queue
1. **Widen comment coverage?** (Ian decision pending) — current 7 types omit 4 that also have
   comments: loothcuts, useful_links, member-benefit, sponsor-post (~21 dev rows). Widening = extend
   `LG_COMMENTS_TYPES` (one array in `api/v0/_comments.php`) + re-run backfill. Nothing else changes.
   **Ask coordinator/Ian before widening.**
2. **Commit the cross-cutting grant** — `GRANT SELECT ON discovery.comments TO "bb-mirror"` is applied
   on dev + added to `sql/comments.pg.sql`, but that schema-file edit is **uncommitted** (lane-owned
   archive-poc subtree the git-tsar doesn't sweep). Commit it; flag for the cutover grant list.
3. **Modal header count from the store** — the `💬 N` count still comes from WP-baked `comments_count`
   at materialize. Small follow-on: read it from `discovery.comments`. In sync on dev today.
4. **Reactions-on-comments** (fast-follow, NOT built per Ian) — schema supports it against the comment
   surrogate `id`. Build only if Ian greenlights.
5. **Stream card** `web/_render-stream-card.php` still points at `?lg_comments=1` — the store backs it;
   wiring is the stream/hub UI's call, coordinate before touching.
6. **Full backfill runs at cutover** (`--all`), not dev. Dev keeps the small fixture.

## Cross-lane boundaries
- **Hub lane** wires comments/reactions INTO the feed cards — you own the endpoints + store, not the
  feed markup. Coordinate via coordinator (Ian) on contract changes only; burn in-lane work freely.
- The write path's WP-cookie gate is the agreed model — don't "fix" it to /whoami (breaks unbridged
  members). See `feedback_gate_posting_on_wp_cookie_not_whoami`.

## Working rules
Commit in clean, tested increments after real change (commit ≠ push; coordinator + Ian gate the push).
Edit only comments/reactions-lane code + your own handoff; other lanes' docs are read-only; route
cross-lane replies via Ian.

## Report back (to coordinator)
`DONE · FILES · VERIFIED (what you proved + how) · DECISION-NEEDED · BLOCKED`.
