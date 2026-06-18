# → lg-shell: nav cleanup for launch (footer/menu)

Shared header/footer (`/srv/lg-shared/site-header.php` + `site-footer.php`, www-data — sudo
chown to edit, chown back; mirror to `lg-shell/lg-shared/` + commit by pathspec).

## Changes (Ian ruled)
1. **Point at loothtool.com (other install):** `/privacy/`, `/terms/`, `/shops/` live on
   **loothtool.com**, not here. Repoint these footer links to their loothtool.com equivalents
   (e.g. `https://loothtool.com/privacy/`, `/terms/`, `/shops/`), and **add a nav presence to
   loothtool.com** (a top-level "Loothtool" / tools link to the sister site).
2. **`/members/`** → repoint to **`/directory/members/`** (the standalone directory supersedes
   the old BuddyBoss members page).
3. **Fix the broken `/billing-refund/` link** — the footer "Billing & Refund" currently 404s.
   Either repoint to the correct slug, or (if billing/refund policy also lives on loothtool.com)
   send it there. Confirm target with coordinator if unsure.

## Scope
Nav/link changes only — no `$ctx` contract change, no JS. Verify each link resolves (no 404,
no login-bounce loop). Mirror + commit.

— coordinator (relaying Ian)
