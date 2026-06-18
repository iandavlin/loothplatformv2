# Cutover "keep-on-live" checklist (2026-06-07)

Check off what survives to live **at cut**. Legend: ✅ recommend keep · 🗑️ recommend drop · ❓ your call.
Migration note per item where it **doesn't ride a git pull** (DB-stored snippets, /etc, secrets).

---

## 1. Custom lg-* plugins (the strangler — keep all)
- [ ] ✅ **lg-layout-v2** — the v2 render engine (managed CPTs)
- [ ] ✅ **lg-apps** — strangler glue / app loader
- [ ] ✅ **lg-anonymous-authors** — anon forum authors (your "anon authors")
- [ ] ✅ **lg-patreon-stripe-poller** — Patreon + Stripe tier truth (the Arbiter)
- [ ] ✅ **lg-legacy-import** — legacy → v2 converter
- [ ] ❓ **lg-weekly-digest** — weekly email digest (keep if you still want it)
- [ ] ❓ **lg-recent-posts-widget** — recent-posts widget (keep if used)
- [ ] ✅ **event-reminder-and-cleaner** — event reminders (feeds lg-push)

## 2. Standalone strangler apps (served from repo / /srv — keep all)
- [ ] ✅ **archive-poc** — content/archive + comments/reactions store (PG)
- [ ] ✅ **bb-mirror** — the Hub feed
- [ ] ✅ **profile-app** — identity/profiles (own PG)
- [ ] ✅ **events** — events surface
- [ ] ✅ **membership-pages** — purchase/membership pages
- [ ] ✅ **/srv/lg-shared** — the canonical header/footer (lg-shell)
- [ ] ✅ **/srv/lg-stripe-billing** — Stripe billing backend
- [ ] ✅ **/srv/lg-push** — web-push delivery
- [ ] ✅ **/srv/lg-sudo-queue**, **/srv/profile-app-media**, **/srv/thumb-app** — support svcs

## 3. BuddyBoss stack — ❓ THE decision (keep until forum-WRITE is replaced)
Forum posting still rides BuddyBoss's REST API; reads are all off it. Keep for now, retire later.
- [ ] ❓ **buddyboss-platform** + **buddyboss-platform-pro**
- [ ] ❓ **bp-auto-group-join**, **bp-bulk-delete**, **bp-maps-for-members**, **bp-messages-tool**,
  **bp-xprofile-location**, **bp-xprofile-custom-field-types** — BP add-ons (most likely retire as
  profile-app + Hub fully replace them — review each)

## 4. Fluent stack
- [ ] ✅ **fluentform** + **fluentformpro** — powers the anon posting form (Form 38)
- [ ] ❓ **fluent-crm** + **fluentcampaign-pro** — email marketing (keep if you run campaigns)
- [ ] ✅ **fluent-smtp** — outbound mail (keep — needed for real email at cut)

## 5. Supporting plugins (mostly keep — confirm)
- [ ] ✅ **advanced-custom-fields-pro** — ACF (content depends on it)
- [ ] ✅ **code-snippets** — runs the DB snippets (see §7)
- [ ] ✅ **redis-cache** — object cache
- [ ] ✅ **relevanssi** — search (confirm still used vs PG search)
- [ ] ✅ **wp-ulike** — legacy likes (source for the reaction backfill; retire after?)
- [ ] ✅ **user-role-editor** — roles (tier lives in WP roles)
- [ ] ❓ **classic-editor**, **acf-quickedit-fields**, **admin-menu-editor**, **advanced-post-queries**,
  **ewww-image-optimizer**, **file-upload-types**, **tuxedo-big-file-uploads**, **wp-force-logout**,
  **bp-xprofile-custom-field-types**, **wp-sheet-editor-premium**, **bulk-edit-user-profiles…** — admin/
  utility; keep the ones you actively use, drop the rest

## 6. Inactive → 🗑️ drop at cut (don't carry dead weight)
- [ ] 🗑️ **elementor** + **elementor-pro** + **dynamic-content-for-elementor** + **search-filter-elementor**
  — Elementor era, being retired
- [ ] 🗑️ **woocommerce** + **woo-stripe-payment** + **printful-shipping-for-woocommerce** — unless shop is coming back
- [ ] 🗑️ **lg-member-sync.deprecated**, **lg-patreon-sync.deprecated**, **lg-stripe-membership.deprecated**
  — superseded by the poller
- [ ] 🗑️ **all-in-one-wp-migration**, **akismet**, **autoptimize**, **burst-statistics**, **devbox-keepalive**,
  **frontend-admin-pro**, **frontend-pdf**, **search-filter** / **-pro**, **post-expirator**,
  **temporary-login-without-password**, **claude-member-directory-v7**, **different-home-for-logged-in…**,
  **buddyboss-sharing**, **dynamic-shortcodes**, **lg-media-tags**, **hello** — confirm none are load-bearing, then drop

## 7. Code-snippets (DB-stored — ⚠️ NOT in git; export/import at cut)
~30 active snippets live in `wp_snippets`. They must be exported and re-imported at cut (or moved into a
plugin). Review each — keep the live functionality, drop the Elementor/legacy query helpers:
- [ ] ✅ **Forum Posting Form** + **Admin - Field For Anonymous Forum Posts** — anon posting
- [ ] ✅ **Patreon Tier Toggler**
- [ ] ✅ **Wordpress Login Branding Etc** — login skin
- [ ] ✅ **`[tlg_sponsor_page_url]`**, **Author Link For Archive `[looth_author_archive_link]`** — shortcodes in use
- [ ] ✅ **Force Remember Me On Login**, **Force Log Out**, **Log Out Looth 1 Users Immediately**, **remove wp-admin from guest role**, **Hide User Email Change in Buddyboss**, **Stick Member Profile URL** — auth/role behaviors
- [ ] ✅ **Email for new pending posts** — moderation notice (pairs with user-submitted loothprints)
- [ ] ❓ **Buddy Boss Theme Color Wrangler**, **Forum Breadcrumbs** (×2), **Change WooCommerce Bread Crumbs**, **Custom Sidebar Widgets** — theme/BB-era; keep only if still rendering
- [ ] 🗑️ **`[my_acf_repeater]` for Elementor**, the **Custom query / LOOP / Freebies / Sponsor POSTS/PRODUCTS by Author** ACF-loop helpers, **change youtube aspect ratio**, **google api to ACF**, **list tags with archives** — Elementor/legacy-loop era; drop if those pages are gone

## 8. Integrations (keep — verify creds/secrets re-applied at cut)
- [ ] ✅ **Stripe** — via the poller + lg-stripe-billing (keys are /etc secrets — re-apply)
- [ ] ✅ **Patreon** — via the poller (OAuth creds re-apply)
- [ ] ✅ **R2 / rclone uploads** — point at LIVE bucket at cut (dev token is dev-scoped)
- [ ] ❓ **Showrunner sheets → event CPT bridge** — keep if you're running it

---

### Cut-day "doesn't ride git" reminders (from this audit)
- **Code-snippets** (§7) are DB-stored → export/import at cut, or fold into a plugin.
- **Plugin active/inactive STATE** is DB → set it on the cut DB (the fork's search-replace won't change which plugins are active vs dev).
- **Secrets** (Stripe/Patreon/VAPID/JWT/HMAC/bridge) live in `/etc` → re-apply.
- **The loose `/var/www` files** (mobile-hub.*, pwa.js, sw.js) → still need the into-git fix.
