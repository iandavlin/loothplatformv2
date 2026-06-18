# Strangler program — fresh-eyes code audit (2026-06-13)

**Method:** 14 independent agents read the *actual source* (docs/handoffs deliberately ignored) across every slice — nginx gate, profile-app, archive-poc, bb-mirror, the poller, lg-shared chrome, and the WP mu-plugin bridges — across three lenses (security / architecture+cut-readiness / quality). Every medium-or-higher finding was then handed to a separate **adversarial verifier** that re-read the code and tried to *refute* it.

**Numbers:** 78 raw findings → 54 medium+ verified → **24 confirmed clean, 18 partial (real but overstated), 12 outright refuted.** 24 low/"this is solid" notes. (68 agents, ~4.2M tokens, 87 min.)

The 56% correction rate matters and is discussed in the capability section at the end.

---

## Bottom line

**Not cut-ready.** The code is *better built than the bug count suggests* — per-request security primitives (JWT verify, SQL parameterization, loopback locks, owner-scoped mutations) are largely solid. The danger is structural and specific:

> **A whole layer of real holes is currently hidden by the dev cookie gate, and goes live the instant that gate is removed at cut.** ~6 of the confirmed findings are "fine today, exposed the moment `/hub/` and the new front page become public."

Most of the confirmed high-severity items are **one-line or few-line fixes** — this is not a rewrite, it's a pre-cut checklist. But two are CRITICAL data leaks, and they must be closed before go-live.

---

## CONFIRMED — fix before cut

### 🔴 CRITICAL

**C1 — Gated content leaks to anonymous viewers via the card excerpt + search.**
`archive-poc/bin/indexer.php:405-409`, `web/_render-main-row.php:130`, `api/v0/search.php:147`
The renderers correctly suppress the video play-button and thumbnail for gated cards — but then emit `<p class="acard__excerpt">` unconditionally, and the excerpt is the **first 220 chars of the full body**. For video posts that includes the **raw YouTube embed URL**. Verified in the live DB: **145 members-only video IDs and the opening prose of every gated article are scrapeable from the public front page and search HTML.** This is the exact payload the entire gating apparatus exists to protect.
*Fix:* gate the `excerpt` field (render + search/suggest JSON) behind the `$is_gated` check already computed; stop baking full-body excerpts for gated items in the indexer.

**C2 — Hidden/private forum bodies are readable by anyone via the lazy fragments.**
`bb-mirror/web/forums/_topic-body.php:26`, `_topic-replies.php:42`
Every other read path filters `forum.visibility='public'`. These two — reachable anonymously as `/hub/?body=<id>` and `?replies=<id>` — do **not**. DB confirms **17 published topics inside 10 hidden/private forums** (e.g. "WEBSITE CHANGES TO BE MADE", staff-only threads) whose full HTML an anon can enumerate by id. Masked by the cookie gate today; **directly exposed at cut.**
*Fix:* add the same `JOIN forums.forum … WHERE visibility='public'` both other paths use; 404 otherwise. Add a gate test.

### 🟠 HIGH

**H1 — `Authorization: Bearer …` header bypasses the nginx cookie gate** for the entire profile-app `/me/*` surface (`platform/nginx/strangler-profile-app.conf:218-225`). Any request with a `Bearer ` prefix is treated as gate-passed; the PHP JWT check becomes the *sole* barrier — and there is **no rate-limit zone** in front of `/me/*` or `/whoami`. Verified live (junk bearer → 401 from PHP, not 403 from the gate).

**H2 — Two live JWT minters with divergent `sub`.** `profile-app/src/Mint.php` claims to be the sole RS256 signer, but `wp-content/mu-plugins/profile-auth.php:45-59` *still mints in-process* on `wp_login`, signing with `/etc/looth/jwt-private.pem` and setting `sub = compute_uuid(email)` — the email-recompute the new minter was built to kill. The key is readable by both www-data and profile-app (ACL). **A member who changed their email gets a token whose `sub` no longer matches their stored uuid → silently rendered anonymous on their own profile** (the "logout-as-stranger" bug, still reachable). Pick ONE minter before cut.

**H3 — Patreon campaign check is dead-code-disabled.** `lg-patreon-onboard.php:891`: `if ( false && … $member_campaign_id !== $campaign_id )`. The leading literal `false` short-circuits the per-campaign filter, so **any active patron of *any* creator** completes OAuth and is auto-provisioned a **paid Looth tier**. git blame shows it was committed already-disabled. Direct paywall bypass. *Fix: delete `false &&`.*

**H4 — Activity feed trusts the cookie *name*, not its value.** `wp-content/mu-plugins/archive-poc-sync.php:179-182` sets `$is_member=true` if any cookie key starts with `wordpress_logged_in_` — `wp_validate_auth_cookie()` is never called. Verified live: `curl -b 'wordpress_logged_in_x=junk'` is served the member bucket. The route is `permission_callback => __return_true` (public at cut). **Anon sets one junk cookie → unlocks tier-gated + private-group activity rows.**

**H5 — Live AWS IAM key hardcoded in `wp-config.php:121-122`** (`AKIA…`, real & active, not a placeholder). nginx blocks web access to wp-config, but dev is being promoted **in-place to live**, so this dev secret becomes the live secret, readable by the same www-data pool every app runs as. Rotate + move to env/instance-role.

**H6 — Member-only discussion authors leak on the single-topic permalink.** `bb-mirror/web/forums/_single-topic.php` selects `author_name/slug/id` and renders a live `/u/<slug>` link, but **never calls the `mask_visibility()` the feed uses**. 508 person rows are `discussion_visibility='member'`. At cut, any anon opening a topic permalink sees the real name + clickable profile of members who set their discussions member-only.

**H7 — Reply CREATE has no CSRF nonce.** `bb-mirror/api/v0/reply.php` — the PUT/DELETE branches verify `wp_verify_nonce`, the POST branch does not, and its in-process `rest_do_request()` also bypasses WP's REST nonce middleware. A logged-in member can have a forum reply forged as them. The nonce is already minted by `auth.php`; the create path just never checks it.

**H8 — `content_item` + `article_blobs` have no reconcile cron.** Both are kept fresh *only* by fire-and-forget `wp_remote_post(timeout=1, blocking=false)` loopback calls on save. bb-mirror has a 10-min belt-and-suspenders `reconcile.php`; the highest-traffic read path has **nothing**. Any dropped sync (FPM saturated, mid-deploy 502) is **permanent**. Add a delta-walk reconcile timer.

**H9 — `backfill-pg.php:80` TRUNCATEs the live read-store outside its transaction.** `TRUNCATE content_item, … CASCADE` self-commits at line 80; the repopulate transaction doesn't open until line 333. This is *also the de-facto recovery tool after a DB reload.* **Any error mid-run blanks the entire site feed with no rollback.** Move the TRUNCATE inside the txn (or build-temp-then-swap).

**H10 — The SQLite "emergency revert" store is a trap, not a safety net.** It's advertised as a fresh fallback, but it's badly diverged from PG (1967 rows incl. 1262 *dropped* forum discussions vs PG's 708 content-only) and only self-heals per-edit. Flipping back to it at cut would serve a 3-week-stale, discussion-padded index missing every recent post. Either decommission it before cut, or run a full rebuild immediately pre-cut.

---

## PARTIAL — real defect, but the finder overstated severity/scope

The verifier confirmed the mechanism but corrected the blast radius. Still worth doing, lower priority:

- **CDP DevTools proxy** (`/cdp/` → chrome:9222) behind only the cookie — **real dev-box RCE/SSRF** (verifier created a browser tab through the public URL), but the cut plan already excludes chrome-dev, so it's a dev-hygiene/token issue, not a live-critical. Delete the block + prune token-bearing `.bak` configs.
- **Live snippets bake the dev gate into every route** — real coupling, but a *documented sequenced step* (live sets `map … default 1` before include). Only bites if the deploy order is wrong.
- **`/whoami` trusts client-supplied `X-LG-WP-User-Id`** behind one reused secret with no network ACL — real, but the scary "erase/mint over the internet" sub-claim was **refuted** (those endpoints are `127.0.0.1`-locked).
- **`item.php` returns 800 chars of gated body** — confirmed but it's the same leak as C1 by another door; fix together.
- **`person` cache on recyclable WP id** — privacy half is mitigated by the 15-min vis-refresh timer; drops to low/med.
- **`MemberTools` 'customer' tier → looth1**, **Arbiter non-atomic role swap**, **single rclone R2 mount SPOF** — all real but medium at most (Arbiter preserves looth1, so it never strands a user role-less; rclone has a 4G vfs cache + auto-restart).

## REFUTED — false positives the adversarial pass caught (and why)

These would have been in a naive report; the verifier killed them against the code:

- "Arbiter strands Stripe cancellations" — **nothing in the codebase ever writes `payment_source='stripe'`**, so the guard never fires.
- "www-data collapse breaks PG peer-auth" / "tree under /home/ubuntu is one chmod from outage" — premised on this being prod; **it's the dev box**, and the cut plan doesn't change the FPM pool user.
- "~24 gate checks become world-open no-ops at cut" — assumes a single global toggle; the actual cut is sequenced.
- "Slug collision serves the wrong post" — table is `PRIMARY KEY(post_id)` with `ON CONFLICT(post_id)`; mechanics inverted.
- "deploy/ snippets are stale and would 404 the API" — nginx serves the *canonical* files via symlink; `deploy/` isn't used.
- "Member-only discussion *bodies* render to anon" — **design-intent call, not a bug:** discussion cards hardcode `data-gated=0`; the code intends discussion bodies to be public and masks only the author. → **Confirm with Ian** whether that's the intended product behavior (the finder's instinct that member-only should hide the body is a reasonable alternative).

## Notable mediums

`/v2/` autoindex exposes the full lg-layout-v2 source tree and serves `.php` as plaintext · `set-forum-image` stores an arbitrary attacker URL with no host allowlist · public shared-secret endpoints `/run-now` `/send-gift-codes` not IP-restricted · `audit_log` keyed on a non-existent column → rows orphaned on every user-nuke · no CSRF defense on profile-app mutations beyond SameSite=Lax · `render.php` hardcodes the dev `looth` PG DSN · **`whoami` fails *open* to `tier=public` when the tier source is down** (low-rated but it's the dangerous direction — gated content could leak during a poller outage; worth a look).

## What's genuinely solid (don't let the list scare you)

- **FPM pool isolation** — per-app unix users, archive-poc literally can't read wp-config.
- **JWT verification** — RS256 pinned, `alg:none`/key-confusion rejected, exp/nbf checked, fail-closed.
- **profile-app mutations** — every `me-*` endpoint is JWT-owner-scoped (no IDOR); `media.php` is traversal-safe; the truly-internal endpoints are double-gated (127.0.0.1 ACL + `hash_equals` secret).
- **Tier law** — sourced server-side from the poller, the forgeable `lg_tier` cookie is correctly *never* a gate; `?as=` preview override rejected for non-admins.
- **SQL** — parameterized across PG + SQLite everywhere checked; no injection surface found.
- **Standalone renderer gating, write-path authz, the loopback-locked sync bridges** — all built correctly.

The engineering instincts are right. The failures cluster at the **seams** (two minters, two stores, mask-in-feed-not-on-permalink) and at the **cut boundary** (things the dev gate hides today).

---

## Capability verdict — is this beyond me?

Honest answer, in two parts, because they have different answers:

**Per-slice / per-bug: comfortably within capability.** Every confirmed finding came with exact `file:line` evidence, and several were *reproduced live end-to-end* (the CDP tab creation, the Bearer-bypass curl, the junk-cookie member unlock, the gated excerpt pulled from the live DB). The adversarial verifier was itself a capable reader — it caught subtle real bugs *and* correctly distinguished real-from-overstated. "Audit this app," "find the bug in this file," "fix this" — not a problem.

**Whole-system, single-context, unsupervised: no — not reliably, and the run proves it.** The headline number is the tell: **a single pass over this system is wrong or materially overstated about half the time.** 12 of 54 findings were outright false; another 18 were real but inflated. And the errors were **not random — they clustered in exactly the cross-cutting cut-readiness reasoning**, where you have to hold the deploy plan + the nginx symlink reality + the FPM config + the actual data in your head simultaneously. The finders that read *one slice deeply* were reliable; the ones reasoning *across seams* overstated. That's the honest shape of the limit: I can hold any one slice, but I cannot hold this entire strangler (4 apps, two datastores mid-migration, the WP bridge layer, ~400 PHP files) coherently in one head and be trusted on the cross-component calls.

**So the complexity is past what I can do in a single pass — but not past what I can do as an *orchestrator*.** The decompose-then-adversarially-verify structure isn't a nicety here; it's load-bearing. Without it you'd have gotten a confident report that was ~1/3 wrong, with the wrong parts being the scary-sounding cross-cutting ones that waste your time or drive a bad cut decision. With it, the noise got filtered before it reached you.

**Two caveats I won't hide:**
1. *Who verifies the verifier?* In a few cases the verifier downgraded severity by leaning on the cut-plan docs (which I'd told finders to ignore). Those "the plan handles it" verdicts are only as good as the plan actually being executed in order. Treat them as "true if the runbook is followed," not "safe."
2. *Some calls are yours, not mine.* The member-only-discussion-body question is a product decision the two agents disagreed on because it's a contract, not a fact. The final arbiter on intent has to be you.

**Net:** for a system at this scale, the right operating model is me-as-orchestrator-of-many-verified-passes **plus you on the design-intent and sequencing calls** — not me as a single mind that holds it all. Used that way, this is well within reach. Used as a single pass, it's past the reliability line, and you should not trust an un-verified one-shot answer about this codebase — including from me.
