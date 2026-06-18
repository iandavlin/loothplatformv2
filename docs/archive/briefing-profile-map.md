# Briefing — profile-app MAP lane (directory map)

You own the **member directory map** in profile-app. Ian is running a **parallel editor chat
in the same tree** — so the #1 rule is **stay in your files** (below) and **commit by pathspec**.
Ian drives; work inline, keep this chat small/focused.

## You OWN (edit freely)
- `profile-app/web/directory-members.php` — the `/directory/members/` page + its map render.
- The **map JS/CSS** that page pulls in (Leaflet init, pin plotting, the location/pin data feed).
- Map-specific styles for the directory grid + map.

## DO NOT TOUCH (shared — the editor chat or coordinator owns these)
- `config.php`, `src/Profile.php`, `src/Block.php`, any `src/*.php` data layer, `sql/*` schema.
- The editor's files: `web/u.php`, `edit.js`, `edit.css`, `_render.php`, `_render_blocks.php`.
- **Never apply schema** yourself. If your work needs a shared file or a schema change → **stop
  and route through Ian** (the editor chat may be mid-edit on it). Two profile-app turns must
  not edit the same file.

## State / context
- `/directory/members/` is standalone (profile-app), already live. The map plots each member's
  **user-managed pin** at their chosen **precision** (exact→neighborhood→city) and **per-tier
  visibility** — never an exact pin the user didn't choose to expose. Coarse "near-me" coords
  are derived (city centroid); the exact `lat/lng` is gated. (See `Block::loadLocation` for the
  two-tier model — read-only reference; don't edit Block.php here.)

## Done =
node/php -l clean · the map renders the right pins at the right precision · commit **by pathspec**
(only your files) · run PHP as `sudo -u profile-app php` if you test data (peer-auth DB).

Coordinator holds the contract (`docs/STRANGLER-SESSION-HANDOFF.md`); cross-cutting → via Ian.
