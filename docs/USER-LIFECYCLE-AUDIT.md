# User Lifecycle Audit + Unification Plan

**Date:** 2026-06-04 · **Owner:** coordinator · **Status:** audit complete, plan proposed (not yet built)

Scope: how a human becomes — and stops being — a fully-identified, logged-in, tier-correct,
recognized-everywhere user across the four stores that share that job. Triggered by the Patreon
OAuth onboard findings (member connected, landed anon) + Ian's call for a system-wide delete.

> Decisions captured 2026-06-04 (Ian): authored content **tombstones** (not deleted) on a normal
> member removal; tests need a **full nuke**; both must be **actions in the WP Users dashboard**.

---

## 1. The system — four stores + a bridge

| Store | Holds | Role |
|---|---|---|
| **WordPress** (`wp_users`, `wp_usermeta`, caps) | account, password, role (looth1-4), authored posts/comments | identity authority (today) + role store |
| **Poller** (`lg-patreon-stripe-poller`, `lg_membership` DB) | tier sources (`lg_role_sources`), Patreon snapshot (`lg_patreon_members`), Stripe customers/subs/entitlements/gifts | tier truth (Arbiter is sole role writer) |
| **profile-app** (Postgres `profile_app`) | profile identity (`users`), `wp_user_bridge`, profile sub-tables, social graph; serves `/whoami` | identity card + fast viewer resolution |
| **profile-app media** (`/srv/profile-app-media/`) | avatar / banner / gallery / resume **files** | per-user binary assets |
| **discovery** (Postgres `discovery`) | `person`, `content_item.author_id` | search/feed denormalized author data |

**The bridge (`wp_user_bridge`, 1,613 rows) is the linchpin.** No bridge row → `/whoami` returns
anon for a logged-in user (`Whoami::buildForWpUserId` JOIN yields nothing →
`profile-app/src/Whoami.php:102`). Missing profile-app `users` row → JWT path also anon
(`Whoami.php:117`).

---

## 2. Lifecycle map — who does what, and the gaps

### CREATE — 7 paths, inconsistent promises

| Path | WP acct | Auth cookie | Role | lg_patreon_members | bridge | profile identity |
|---|---|---|---|---|---|---|
| Patreon OAuth onboard (`lg-patreon-onboard.php:974`) | ✓ | **✗** (pw-reset email) | ✓ Arbiter | **✗** | ✗ (later, if Stripe) | ✓ via `user_register` |
| Patreon sweep (`class-lgpo-sync-engine.php`) | ✗ match-only | ✗ | ✓ | ✓ upsert | ✗ | ✗ |
| gift-auth (`RestController.php:1541-1589`) | ✓ | ✓ | ✓ direct looth1 (no Arbiter) | ✗ | ✗ | ✓ via `user_register` |
| Stripe (`UserProvisioner.php:39-67`) | ✓ | ✗ | ✓ Arbiter | ✗ | ✓ explicit | ✓ via `user_register` |
| WP admin "Add User" | ✓ | ✗ | admin-picked (no Arbiter) | ✗ | ✗ | ✓ via `user_register` |
| Affiliate (`Admin.php:218`) | ✓ | ✗ | set_role (no Arbiter) | ✗ | ✗ | ✓ via `user_register` |
| WP native register | ✓ | ✗ | default | ✗ | ✗ | ✓ via `user_register` |

Profile identity is created off the shared `user_register` hook → `platform/mu-plugins/profile-sync.php:41`
→ non-blocking POST to `profile-app/api/v0/user-created.php` (creates `users` + `wp_user_bridge` +
`email_aliases` in one txn). **Non-blocking, 1s timeout, no retry** → if profile-app is slow/down at
that instant, WP user exists with no bridge → anon until a manual reconcile.

### LOGIN / identity resolution

- JWT `looth_id` minted **in-process on WP**, hook `wp_login` (`platform/mu-plugins/profile-auth.php:83`),
  with an `init`-hook backstop (`:99`) that re-mints if the cookie is missing. `sub = UUIDv5(email)`.
- profile-app's own mint endpoint (`api/v0/internal-mint-token.php`) exists but is **not wired** —
  the WP in-process mint is what runs.
- `lg_tier` cookie set every HTML pageview on `send_headers` (`lg-viewer-tier.php:54`), 1-day TTL,
  fast first-paint hint only.
- Consumers: archive-poc → direct `/profile-api/v0/whoami`; bb-mirror → WP shim
  `/wp-json/looth/v1/whoami` (45s tmpfs cache). All fail **open** to anon.

### TIER / role

`lg_role_sources` (per-source opinions: stripe / patreon / manual_admin) → **Arbiter** picks the
winning tier → writes WP caps → fires `looth_tier_changed` **only on transition** →
`PurgeNotifier` POSTs `/profile-api/v0/internal/purge-whoami`. `/whoami` reads tier live from
`/wp-json/looth-internal/v1/user-context/{id}` (30s Redis cache). `lg_patreon_members` is a display
snapshot, **not** authoritative.

### DELETE — three partial tools, none complete, none tombstones

| Tool | WP | BuddyPress | lg_membership + Stripe | profile-app | media | discovery | content |
|---|---|---|---|---|---|---|---|
| WP dash Delete | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ | orphan |
| TestChecklist wipe (`TestChecklist.php:226`) | ✓ | ✓ | ✓ | **✗** | **✗** | ✗ | orphan |
| MemberTools nuke (`MemberTools.php:413`) | ✓ | **✗** | ✓ (+Stripe cancel) | **✗** | **✗** | ✗ | orphan |

profile-app tables are all `ON DELETE CASCADE` off `users` — but **nothing ever deletes the
`users` row**, and no `deleted_user` hook exists anywhere (grep-confirmed).

---

## 3. Gap register

| # | Gap | Location | Severity |
|---|---|---|---|
| G1 | OAuth onboard never logs the member in | `lg-patreon-onboard.php:974-1012` | high (UX: lands anon) |
| G2 | `MemberTools::doSetTier` + gift-auth don't fire `looth_tier_changed` → stale `/whoami` | `MemberTools.php:346`, `RestController.php:1552` | med |
| G3 | WP-dash / REST role edits bypass `lg_role_sources` → next Arbiter tick can clobber | no hook on `profile_update`/`set_user_role` | med |
| G4 | Email change diverges JWT `sub` (recomputed from email) from stored `users.uuid` → anon | `profile-auth.php:51` vs `Whoami.php:113` | high (silent) |
| G5 | No `deleted_user` fan-out → every delete orphans poller + profile-app + media + discovery | none | high |
| G6 | No complete teardown anywhere; the 3 partial ones disagree on coverage + key (email vs wp_id) | — | high |
| G7 | `user_register` → profile-sync is fire-and-forget, no retry → bridge can silently miss | `profile-sync.php:41` | med |
| G8 | No single create front-door → 7 paths keep different subsets of promises | — | structural |

---

## 4. Needs — tested against the system

### Admin needs

| Need | Today | Verdict |
|---|---|---|
| Delete a member cleanly from the place I manage users (WP Users) | only the WP-only delete is there; complete tools are buried on plugin tabs + keyed by email | ❌ |
| Keep forum threads/comments intact when removing a member | nothing tombstones | ❌ |
| Fully wipe a test user across all systems | 3 tools, each partial; none touch profile-app + media | ❌ |
| Manually set/comp a tier and have it stick + reflect immediately | writes DB, but no cache purge (G2) and can be clobbered (G3) | ⚠️ |
| See a user's true cross-system state | partial (membership panel on user-edit) | ⚠️ |
| Impersonate / View-As | ✓ (`lgms-admin-view-as-toggle.php`) | ✅ |
| Audit admin actions | ✓ (`admin_action_log`, `audit_log`) | ✅ |

### User needs

| Need | Today | Verdict |
|---|---|---|
| Sign up via Patreon and be logged in + recognized | account made, not logged in, may miss bridge | ❌ (G1) |
| Edit profile once → reflects everywhere | ✓ single-source spine | ✅ |
| Correct tier shown right after a change | up to 30s stale on admin/gift paths | ⚠️ (G2) |
| Change my email without losing my identity | breaks JWT → anon | ❌ (G4) |
| Be deleted/forgotten on request (GDPR) | no complete, self-service-able teardown | ❌ (G6) |
| Not see "[deleted user]" garbage in threads | n/a — no tombstone yet | ❌ |

---

## 5. Unification plan

**Principle:** one canonical **`UserLifecycle`** service is the *only* code that creates or tears
down a user. WordPress is the **trigger surface** (Users screen + hooks); the service fans out to
every store. No path keeps its own private subset of promises.

### Phase 1 — Canonical teardown + WP Users dash actions  ⟵ unblocks "nuke for tests"
- New `UserLifecycle::teardown($wpUserId, $mode)` with `$mode ∈ {tombstone, nuke}`, keyed on
  **`wp_user_id`** (not email). Folds the existing `doNuke` + `wipeQueries` + `eraseBuddypressFootprint`
  into one path and **adds the two missing systems**: profile-app (via a new internal
  `POST /profile-api/v0/internal/erase-user` that deletes the `users` row → CASCADE) and the media
  files under `/srv/profile-app-media/{avatars,banners,gallery,resumes}/<uuid|id>`, plus discovery
  `person` / `content_item`.
- **tombstone:** cancel Stripe subs; erase identity in WP (PII fields), poller, profile-app, media,
  BP social, discovery; **reassign** `wp_posts`/`wp_comments`/forum replies to a sentinel
  `[deleted member]` user; keep a tombstone marker.
- **nuke:** everything tombstone does **+** delete authored content + media + discovery rows. Refuses
  admins and user 1. This is the test path.
- **Expose in WP Users:** per-row actions "Tombstone member" / "Nuke member" + a bulk action, behind
  a JS confirm (nuke = type-to-confirm). `manage_options` only.
- Retire the email-keyed wipe + member-tools nuke once parity is proven (or make them thin wrappers).

### Phase 2 — `deleted_user` safety net
- `add_action('deleted_user', ...)` → `UserLifecycle::teardown($id, 'nuke-orphans')` so even a native
  WP delete fans out the cross-store cleanup (can't tombstone content — WP already handled posts by
  then — but never leaves orphans).

### Phase 3 — Canonical onboarding (single create front-door)
- `UserLifecycle::provision($email, $opts)` = the only creator: WP account → role (via Arbiter) →
  bridge + profile identity (**blocking**, with retry — fixes G7) → optional `wp_set_auth_cookie`.
- Route every creator through it. **OAuth onboard sets the auth cookie** (fixes G1) — and routes the
  login through whatever fires `wp_login` so the JWT mints (else cookie-but-no-JWT = still anon).

### Phase 4 — Close tier-writer gaps
- Fire `looth_tier_changed` from `MemberTools::doSetTier` + gift-auth (G2).
- Hook `set_user_role`/`profile_update` to write a `manual_admin` source + run Arbiter so WP-dash
  edits stop getting clobbered (G3).

### Phase 5 — Email-change identity stability
- Stop recomputing JWT `sub` from email; carry the stored `users.uuid`, or sync the profile UUID on
  WP email change (G4). Decide with shim-replacement lane (it owns the mint).

**Sequencing note:** Phase 1 is independent and serves the urgent test-nuke need. Phases 3-5 are the
tri-lane (poller + profile-app + shim) design pass and should move together. Phase 1+2 = teardown;
3-5 = create/identity.

---

## 6. Blast-radius reference (what a complete teardown must touch)

- **WP:** `wp_users`, `wp_usermeta`; authored `wp_posts` + `wp_comments` (tombstone: reassign / nuke: delete)
- **BuddyPress:** `bp_activity`, `bp_friends`, `bp_groups_members`, `bp_messages_recipients`, `bp_notifications`, `bp_xprofile_data`, `bp_user_blogs`
- **lg_membership:** `customers`, `wp_user_bridge`, `subscriptions` (Stripe-cancel first), `orders`, `order_items`, `entitlements`, `gift_codes`, `gift_recipients_pending`, `admin_action_log`, `audit_log`, `pending_sessions`, `lg_role_sources`, `lg_patreon_members`; optional `banned_emails`
- **profile-app (`profile_app`):** delete `users` row → CASCADE clears `email_aliases`, `wp_user_bridge`, `profiles`, `profile_sections/socials/instruments/skills/scenes/credentials/highlights/genres/services`, `connections`, `messages`, `message_recipients`, `notifications`, `practice_members`
- **profile-app media:** `/srv/profile-app-media/{avatars,banners,gallery,resumes}/`
- **discovery (`discovery`):** `person`, `content_item.author_id` (tombstone: blank / nuke: delete)
- **forums/bb-mirror:** persons + authored topics/replies (tombstone: blank author / nuke: delete)

---

## Key file:line index

- OAuth onboard create: `lg-patreon-onboard.php:974-1012`
- Stripe provision + bridge: `lg-patreon-stripe-poller/src/Wp/UserProvisioner.php:39-79`
- Arbiter (role writer): `lg-patreon-stripe-poller/src/Arbiter.php:60-106`
- Tier-change purge: `lg-patreon-stripe-poller/src/PurgeNotifier.php:30`
- profile identity webhook: `platform/mu-plugins/profile-sync.php:41` → `profile-app/api/v0/user-created.php:31-56`
- JWT mint: `platform/mu-plugins/profile-auth.php:83,99`
- lg_tier cookie: `platform/mu-plugins/lg-viewer-tier.php:54`
- whoami anon fall-through: `profile-app/src/Whoami.php:102,117`
- Existing teardown: `MemberTools.php:413-482` (nuke), `TestChecklist.php:226-449` (wipe), `RestController.php` `eraseBuddypressFootprint()`
- View-As: `platform/mu-plugins/lgms-admin-view-as-toggle.php`
