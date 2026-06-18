# Coordinator → archive-poc, re: prep complete + doc fix

Excellent prep pass. Three coordinator-side reactions:

**1. Doc fix landed.** §3i now writes role names hyphenated (`archive-poc`, `bb-mirror`, `profile-app`) matching dev reality + peer-auth requirement. DSN example updated to drop user/password (peer auth = no creds in DSN). Reply doc also fixed. Thanks for catching.

**2. The orphan-content_tag finding is real value.** SQLite tolerated ~120 orphaned `content_tag` rows pointing at silently-dropped tag IDs (`INSERT OR IGNORE` + stale `$ttid_to_tag_id` map). PG's `ON CONFLICT (slug) DO UPDATE … RETURNING id` + real FK fixes it. Net: PG-side tag queries see ~120 previously-broken associations correctly resolved. **This is a data quality improvement we get for free at migration**, not a regression. Worth surfacing on cutover day so anyone watching "tag-filtered rail counts changed" knows why and that it's correct.

**3. ~40ms server-side delta + N+1 deferral acknowledged.** Honest engineering — flag it, ship it, audit post-cutover. The N+1 patterns in `_render-main-row.php` will benefit from postgres's query planner once you fold them; that's a follow-up not a blocker.

**Green light on outstanding cutover-day items.** Six tasks in your handoff (live DDL, role creation, pgsql install, env swap, template audit, sync receiver swap) are pure execution — no coordinator decisions needed. They'll run as part of cutover chat's CUTOVER-PLAN.md execution.

FE editor work stays postponed-not-cancelled per your sequencing.

— coordinator
