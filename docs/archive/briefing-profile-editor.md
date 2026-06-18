# Briefing — profile-app EDITOR lane (/u/ FE editor + View-as)

You own the **front-end profile editor** in profile-app. Ian is running a **parallel map chat
in the same tree** — so the #1 rule is **stay in your files** (below) and **commit by pathspec**.
Ian drives; work inline, keep this chat small/focused.

## You OWN (edit freely)
- `profile-app/web/u.php` — the `/u/<slug>` page + editor entry (owner self-view, View-as).
- `web/edit.php`, `web/edit.js`, `web/edit.css` — the FE editor (block edit, drag-reorder, palette).
- `web/_render.php`, `web/_render_blocks.php` — the editor/profile render + the View-as toggle.

## DO NOT TOUCH (shared — the map chat or coordinator owns these)
- `config.php`, `src/Profile.php`, `src/Block.php`, any `src/*.php` data layer, `sql/*` schema.
- The map's files: `web/directory-members.php` + its map JS/CSS.
- **Never apply schema** yourself. If your work needs a shared file or a schema change → **stop
  and route through Ian** (the map chat may be mid-edit). Two profile-app turns must not edit
  the same file.

## Known issue carried in (already diagnosed by coordinator)
**The gap between the View-as control bar and the profile header card won't take.** Ruled out:
**it is NOT shell's CSS** — `/srv/lg-shared/site-header.css` is cleanly scoped to `.lg-chrome*`
(no `*{}`, no bare `body/section/div` rules, no global `margin:0` reset, no `@layer`). So the
cause is **profile-app's own editor CSS**. Most likely, in order:
1. **Margin-collapse** between the control bar's `margin-bottom` and the header card's
   `margin-top` → use padding, or `display:flow-root`/`overflow` on the container, or flex/grid `gap`.
2. **The control bar is `position:sticky/fixed`** → its margin doesn't create flow space; put
   `margin-top`/`padding-top` on the header card instead.
3. A **flex/grid parent with `gap:0`** controlling spacing → a child margin won't win; set the gap.
Inspect the computed `position` / `margin` / parent `display` of those two elements first
(chrome-dev-login skill) — that pins which of the three it is.

## Done =
node/php -l clean · the gap renders · View-as + block edit still work · commit **by pathspec**
(only your files) · run PHP as `sudo -u profile-app php` if testing (peer-auth DB).

Coordinator holds the contract (`docs/STRANGLER-SESSION-HANDOFF.md`); cross-cutting → via Ian.
