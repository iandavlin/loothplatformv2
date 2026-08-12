# Ian's rulings — 2026-08-11 (keeper, quotable)

1. **Sitemap is FINAL as-is — do not change the list.** Ian wants Google pointed
   only at what we choose; reviewed the four sub-sitemaps (static 2 / content 655
   / profiles 1742 / discussions 1336) and ruled: profiles STAY (all 1,742),
   nothing else trimmed. Remaining SEO work is the FENCE only: legacy/never-
   rebuilt pages (hub category views, stale WP pages /featured-content/ /merch/,
   dead /shop-organisation/ sitelink) must noindex or redirect. ("Keep profiles
   listed" / "List is fine otherwise.")

2. **Category pages: rebuild properly** — new hub look + correct Google
   instructions; assigned to the hub-seo lane as its next charter.

3. **Buck fence** — our work never touches Buck's files; enforced by
   tools/gates/buck-surface-guard.sh in run-all.sh (committed earlier today).

4. **Featured members** (backlog item 18) — opt-in tickbox + admin dash +
   auto-publish to front page; "fairly soon."

5. **Stale-page forwards (fence work, ruled via decision box):**
   - `/featured-content/` → forward (301) to **the Hub** (`/hub/`).
   - `/merch/` → forward (301) to **loothtool.com** (Ian's exact word: "loothtool.com";
     pending since July, now ruled). Lane should confirm the canonical form
     (https + working) before shipping the redirect.
