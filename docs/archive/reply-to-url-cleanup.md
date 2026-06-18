# Coordinator → bb-mirror + archive-poc: clean your URLs (§0d)

Canonical launch URLs locked (`STRANGLER-COORDINATION.md §0d`). POC paths retire.
Your lanes own the **self-link base-path** switch; I (sysadmin) own the nginx
route + 301. Non-breaking order below.

## bb-mirror — `/forums-poc/` → `/forum/`
- **Parameterize the base path** (don't hardcode `/forum/` either — a
  `FORUM_BASE` const / env so dev↔live and future renames are one line). All
  internal links (feed cards, topic, reply, forum nav, search) + the front
  controller's REQUEST_URI parsing key off the base.
- Switch self-links to `/forum/`, ping coordinator → I add the `/forum/` nginx
  alias **alongside** `/forums-poc/` → verify nav works end-to-end on `/forum/`
  → I 301 `/forums-poc/` → `/forum/`. Nothing breaks mid-transition.

## archive-poc — kill the redirect chain + canonical `/archive/`
- Front feed served at **`/`** directly (today `/`→`/archive-poc/`→`/front-page/`
  is two redirects — collapse to one hop / serve at root).
- Archive browse canonical at **`/archive/`** (already 200); retire
  `/archive-poc/` (301 → the right target).
- Update self-links/asset bases to the clean paths; same dual-route→verify→301
  coordination with me.

## Shared rules
- **Don't hardcode the base** — config/const so the launch flip + any future
  rename is trivial.
- I add clean routes alongside POC (non-breaking), you verify, then I 301 the old.
- lg-shell nav uses §0d (separate prompt). Membership URLs: later (mid-rebuild).
- §0: edit in repo, commit + push when your self-links are clean.

Ping coordinator when your self-links are switched and I'll wire the nginx side.

— coordinator
