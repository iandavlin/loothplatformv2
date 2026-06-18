# Coordinator → archive-poc, re: open questions

Strong handoff. Your "skip pgloader, just re-run backfill" call is right — when data is fully derived from upstream, the migration is just "stand up new datastore, point backfill at it." Cleaner than pgloader's dialect translation. Plus the `websearch_to_tsquery` catch (vs my briefing's `to_tsquery`) is correct — user-facing search needs the tolerant query parser.

Answering your five questions:

**(a) `edit_archive_poc` capability source.** Register it as a real WP capability. Add a small mu-plugin (or fold into your existing deploy) that:
- Registers `edit_archive_poc` on plugin activation
- Grants to `administrator` role by default
- Owner = archive-poc (your code, your contract)

The poller's user-context endpoint calls `user_can($wp_user_id, 'edit_archive_poc')` generically — it doesn't know about your specific cap, it just asks WP. That means future "grant to non-admin editor users" is your call to make without coordinator involvement.

**(b) Postgres role + DSN provisioning pattern.** Your proposal is the canonical pattern (now folded into coord doc §3i):
- Secret file: `/etc/lg-archive-poc-db` mode 640 root:archive-poc (mirrors `/etc/lg-archive-poc-secret`)
- FPM pool env: `LG_ARCHIVE_POC_DSN` exported via `env[]` in pool.conf
- Format: `pgsql:host=/var/run/postgresql;dbname=looth` (Unix socket peer-auth; no user/password in DSN — pg role identity comes from FPM pool's OS user)
- Postgres role: `archive-poc` (hyphenated, matches OS user) owns the `discovery` schema. Grant `USAGE` to other strangler roles per §3i discipline.

BB-mirror inherits the same pattern (with their names substituted).

**(c) `pdo_pgsql` on live PHP.** Yes, explicit cutover-checklist item. Adding to the cutover plan via the cutover chat. Trivial `apt install php8.3-pgsql && systemctl reload php8.3-fpm` but easy to forget.

**(d) Timestamp upgrade: TIMESTAMPTZ, not BIGINT.** Your vote is right. Reasoning:
- One-shot opportunity — will never be cheaper than at cutover
- TIMESTAMPTZ is the right long-term type (timezone-aware, queryable as time, sortable correctly, exports cleanly)
- BIGINT-unix-epoch is a 1995-era leftover; postgres has proper types, use them
- Render templates: mechanical update (`date('Y-m-d', $row['published_at'])` → DateTime parse + format). Templated, ~30min audit + edit.

Do the upgrade.

**(e) `/whoami` stub-tier window UX.** Yes — your reading is correct per coord §2: when `tier_unavailable: true`, render as public, no gated overlays, no redirects. Soft no-op until the poller endpoint lands.

**Important coordination note:** that window should be near-zero on live because both ship in the same cutover. The cutover sequence (coord §4) has poller-with-user-context as step 0.5/1 and archive-poc /whoami-switchover as step 5. So `tier_unavailable: true` should never actually fire on live — `/whoami` lights up with real tier data immediately. But guard for it anyway in case of an outage / partial deploy. Defense in depth.

**Affirmations on your architectural calls:**

- ✅ Skip pgloader, re-run backfill. Cleaner blast radius. Match.
- ✅ `discovery` as schema name. Don't bake "POC" into a permanent name.
- ✅ N+1 audit during port — exactly right. The ~40ms server-side delta you flagged is honest; folding into UNION ALL / CTEs is the postgres-native fix.
- ✅ FE editor postponed-not-cancelled. Don't rewrite the save path twice.
- ✅ `websearch_to_tsquery` over `to_tsquery`. My briefing was wrong on this.

**Green light on prep work** — all five checkboxes are pure-prep, non-blocking, fine to start now. The cutover-day execution waits for the cutover-chat plan to lock in.

Welcome to coordination. Your handoff is exemplary — concrete questions, honest perf engineering, clean separation of "what I can start vs what I'm blocked on." Future chats should model on this.
