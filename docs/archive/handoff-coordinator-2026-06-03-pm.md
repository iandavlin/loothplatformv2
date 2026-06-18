# Coordinator handoff — 2026-06-03 (PM)  ·  supersedes the AM handoff

Big session. Read this top section before touching anything; lane detail lives in
**docs/LANE-LEDGER.md** (the live board) and the per-lane briefings in docs/.

## ⚠️ Critical state — know these first

1. **VERIFY FIRST: did Buck's dropoff-clusters merge break `/directory`?** I merged
   `buck/dropoff-clusters-rebased` (7afb514, 14 files, clean FF) and pushed. The post-merge
   smoke of `/profile-api/v0/directory-members` returned **404** — *likely just the wrong
   endpoint path in my test, not a break, but UNCONFIRMED.* First action: confirm the
   `/directory` page + its API render on dev (the merge added a `banner_url` SELECT — make sure
   the column exists / query doesn't 500). If broken, it's a follow-up commit, not an unpush.

2. **Two 🔴 secrets STILL unrotated** (flagged at session start, never done): CF creds (pasted in
   an earlier chat) + a **plaintext AWS key (`AKIA…`) in `/var/www/dev/wp-config.php`**.

3. **Uncommitted lane work on main.** Pushed this session: the siteurl fix (DB), several batches,
   and all Buck merges (dropoffs-map 8f28bcd, memberview c43d0ee, guitar eb2d19a, dropoff-clusters
   7afb514). STILL uncommitted in the tree: the whoami lane's `archive.js` repoint + `stream-more`/
   `rows-more` gating fix + the pilot_pro PG bridge; perf-czar's ingest-script image fix; the
   lightbox `engine/assets/lg-front.js`; comments-lean's dequeue. Review-before-push still applies.

4. **devmsg panel was patched — needs a window reload to take effect.** I edited the installed
   extension (`~/.local/share/code-server/extensions/loothgroup.devmsg-0.2.0/src/extension.js`) to
   live-refresh OPEN thread panels on activity in *either* direction (it only watched incoming +
   focused → CLI-sent messages went stale). Syntax-checked OK. **Persist-follow-up:** the fix is in
   the installed copy; rebuild the vsix (`/opt/devmsg-extension`) + reinstall to survive a reinstall
   and reach other team users.

5. **Open decisions parked for Ian:**
   - **pro-gate** (`buck/profile-public-pro-gate` 53b2a0a): policy APPROVED + fail-closed kept, but
     **TESTING-not-canonical** (it changes the "Ian, FINAL" header-ceiling model). Buck is wiring his
     preview's autoload at the branch's `src/` to test the clamp+403 as **pilot_pro (1883→pro)**.
     Awaiting his test result → then merge.
   - Buck merge policy is now standing (saved memory): auto-merge trivial/clobber-clean + report;
     HOLD policy/privacy/member-data/FINAL-model for Ian.

## Lane board (detail in LANE-LEDGER.md)

- **whoami/gating** 🟢 keystone live (poller active; whoami repointed WP-shim→JWT, ~590→5ms; lg_tier
  pagination bypass fixed). OPEN: index.php/search.php anon-lg_tier hardening; the SHORTINIT
  lean-comments + lean-whoami endpoints (cross-cutting).
- **stream** 🟢 wide phase + authed e2e PASSED (pilot_pro). bbPress inline-reply BLOCKED on bb-mirror
  shipping a reply-write endpoint. Likes/video/download/gating all working.
- **perf-czar** 🟢 baseline logged (PERF-BASELINE.md). Image fix CODE-done, NOT re-ingested
  (deferred, dev-fixtures). /lgjoin parked (rides the stripe-pages migration).
- **comments-lean** ⚪ greenlit to PROTOTYPE a SHORTINIT read-only comments endpoint (3.3s→~50ms
  target). Coordinator owns its nginx location; do NOT ship without review.
- **v2-lightbox** 🟢 fixed (standalone renderer now ships lg-front.js). CUTOVER must-dos: deploy
  carries `archive-poc/standalone/engine/assets/lg-front.js`; vendor-sync includes assets/.
- **conversion** 🟡 running (aggregate dry-run greenlit; parser committed).
- **stripe-pages standalone** ⚪ JUST DISPATCHED (docs/relay-stripe-pages-standalone-and-shell.md,
  updated with the no-birds-nest ROUTER architecture). Menu DONE (shell, don't touch). Gap: 5 missing
  page files + single router + one nginx location. Coordinator applies the nginx via sudo-queue.
- **hub user-DB drift** ⚪ JUST DISPATCHED (docs/handoff-hub-userdb-drift.md) — forum user renders as
  "T", + lost hub functionality. Diagnose data-drift vs render-bug first.
- **Buck branches** — dropoffs-map/memberview/guitar/dropoff-clusters MERGED; pro-gate HELD (above).

## Infra I changed this session
- **siteurl fix:** dev WP DB carried live URLs from the CF reload → `wp search-replace` + cache flush.
  Fixed logout/login/whoami. (Memory updated: this is step-1 after any reload.)
- **nginx:** `/wp-json/looth-internal/` locked to localhost (allow 127.0.0.1/::1; deny all);
  `rows-more` added to the archive-api exec regex (source-disclosure closed). DEFERRED: the
  rows-more clean-URL + stream-more `?cursor` both drop args under the alias+rewrite — same nginx
  fix, mine, before cutover.

## Coordinator-owned pending
- Apply the stripe-pages single-router nginx location (when that lane delivers) via sudo-queue.
- nginx args-under-alias fix (rows-more clean URL + stream-more cursor).
- Rotate the two secrets (CF + wp-config AWS key).
- SHORTINIT comments-endpoint nginx location (when comments-lean delivers).

## Memories written/updated (auto-load via MEMORY.md)
feedback_db_reload_stale_sessions (siteurl step) · project_lg_shell_header_keeper ·
project_activity_stream_launch · project_whoami_shim_bootstrap_cost (RESOLVED) ·
feedback_buck_merge_policy.

Lanes burn in-lane; cross-cutting (header/whoami/nginx/secrets) routes to coordinator.
Header/footer = lg-shell's; consumers populate $ctx only.
