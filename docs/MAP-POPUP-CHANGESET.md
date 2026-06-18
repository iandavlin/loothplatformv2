# Map pin popup → full member card — change set for Ian's review

**Status:** PROPOSED, no code written. Approach approved (Ian 6/15: shared canonical
card renderer + lazy-fetch-by-slug). This doc is the concrete plan to OK before code.

**Goal:** hovering a desktop pin / tapping a mobile pin shows the SAME full member
card the sidebar renders (avatar, name, location, instrument pills, lights, social,
Connect/Message) — not the current name+address stub. One markup source, server-
rendered, both surfaces inherit → can't drift.

**Root cause being fixed** (verified live): canonical `plotPins` opens a free-floating
`L.popup` via `openOn(dirMap)`; the overlay's rich-popup hook assumed marker-bound
popups (`_source`/`bindPopup`) and is dead. We move the card INTO the canonical popup
so the overlay hook is no longer needed. Full detail in MAP.md.

---

## Piece 1 — `?slug=` filter on `/directory/members` (SHARED — profile-app lane)

**File:** `profile-app/api/v0/directory-members.php`
**Cross-lane:** YES — ANNOUNCE to the profile-app lane before touching. This is the
only cross-lane piece.

**Change:** when `?slug=<slug>` is present, constrain the existing list query to that
one member and return the SAME `items[]` shape (array of 1) the list already returns.

- Add to `$listWheres`: `u.slug = :slug` (param-bound); `page_size = 1`.
- Reuse the EXISTING list pipeline unchanged — highlights, links (scrape-proof
  rules), connect state, lights, and `dir_member_display` precision all already run
  on the result rows. No new render path, no new privacy surface: a slug-fetch passes
  through the identical `Visibility::locationPrecision` coarsening, so it can never
  expose more than the list/pin already does. Anon still gets no uuid, no contact PII.
- Returns `{items:[<one full card item>]}` so the client uses the identical parser.

**Why server-side, not a new endpoint:** the list builder already emits the exact
item shape `renderResults`/`dirCardHTML` consume. A slug filter is ~3 lines and
inherits every privacy guard for free. A separate endpoint would duplicate that.

---

## Piece 2 — canonical `plotPins` popup = full card (MY lane)

**File:** `profile-app/web/directory-members.php`

**2a. Extract the card renderer (single markup source):**
Pull the per-item `.dir-card` template out of `renderResults` (currently the
`items.map(it => \`…\`)` body, L293-343) into a pure `function dirCardHTML(it)`
returning the card string. `renderResults` becomes `items.map(dirCardHTML).join('')`.
The popup calls `dirCardHTML(fullItem)` for one member. ONE template, zero drift.

**2b. Popup keeps its current binding model (do NOT re-bind to markers):**
Unchanged: `const popup = L.popup({offset, closeOnClick:false}).setContent(stub)`,
opened via `openPin = () => popup.setLatLng(...).openOn(dirMap)`. We change only the
CONTENT, never the binding. The stub (name + location) stays as the instant/loading
state shown the moment the popup opens; once the lazy-fetch resolves we
`popup.setContent(dirCardHTML(item)); popup.update();` (only if that slug's popup is
still the open one).

**2c. Wire popup-internal actions:** the full card carries `<a href="/u/slug">` (card
body) + Connect/Message buttons. The card link navigates (desired). Connect/Message
need the same handlers the sidebar uses (`dirHandleConnect`, `lg:open-dm`) — bind them
on the swapped-in popup node (relocating the wiring the overlay's dead clone handler
used to do, directory-desktop.js:613-617).

---

## Piece 3 — lazy-fetch trigger + caching (MY lane, same file)

**Trigger (both surfaces inherit, because `plotPins` is canonical/shared):**
- Desktop: the existing `m.on('mouseover', openPin)` (L584) already fires on hover.
  Wrap so it (i) opens the stub immediately, (ii) calls `ensureCard(slug)`. A short
  intent debounce (~130ms, the value the overlay used) so a quick mouse-pass doesn't
  fire a fetch.
- Mobile: pin TAP already routes to `openPin` via the same canonical click handlers
  (L586/605). No mobile-specific code — tap inherits the full-card popup automatically.
  (The mobile "two-stage tap" is for sidebar CARDS, not pins; pins are unaffected.)

**Caching — `const cardCache = {}` (slug → item), module scope:**
- `ensureCard(slug)`: return cached item, else fetch `/directory/members?slug=` once
  (in-flight promise dedupe so repeated hovers don't stack requests), cache, resolve.
- PRE-SEED: every member already rendered into the sidebar list is full data we
  already hold — seed `cardCache` from each `renderResults` batch, so visible members
  need NO fetch (instant full card) and only off-list pins fetch on demand.

---

## Piece 4 — DO NOT reintroduce the click-through-nav bug

The manual-popup refactor (the thing that caused this) exists for a reason
(directory-members.php:579-581): `L.popup … openOn(dirMap)` + `closeOnClick:false`
lets the marker CLICK handler test `dirMap.hasLayer(popup) ? navigate : openPin`
(L585-586) — popup open → click navigates to `/u/slug`; popup closed → click opens it.

Guardrails for this change:
- KEEP the manual `L.popup`/`openOn`/`closeOnClick:false` model. Do NOT switch to
  `m.bindPopup` — that reintroduces the pre-click-close race the comment warns about.
- KEEP the `dirMap.hasLayer(popup)` click gate exactly as-is. We only swap CONTENT.
- Popup-internal clicks (card link, Connect) are on the popup DOM, separate from the
  marker; verify they don't bubble into the marker/map click gate. Card link =
  normal `<a>` navigation; Connect/Message `preventDefault` + `stopPropagation`
  (as the overlay's old handler did).
- Gated dots (no slug, members-only) and drop-off pins keep their existing popups —
  the full-card swap applies ONLY to `pinMarkerBySlug` entries (non-gated, has slug).

---

## Piece 5 — overlay cleanup (MY lane, follow-up in same change set)

Once `plotPins` owns the card popup, the overlay's dead rich-popup code is redundant
and should be removed to prevent double-handling/confusion:
`directory-desktop.js` — `hoverOpenPopup`, the `popupopen` clone handler,
`cardHTMLBySlug`/`snapshotCards`, the `attachPinHover` popup bits. Overlay edit →
diff + re-capture into `projects/webroot/` in the same change, run gates.

---

## Files touched (summary)
| File | Lane | Change |
|---|---|---|
| `profile-app/api/v0/directory-members.php` | SHARED — **announce** | `?slug=` filter |
| `profile-app/web/directory-members.php` | map | `dirCardHTML` extract; popup full-card + lazy-fetch + cache + preseed |
| `webroot/directory-desktop.js` (+ live `/var/www/dev/`) | overlay | remove dead rich-popup code; diff+recapture |

## Verify plan
chrome-dev-login (uncontested — ping coord first): hover desktop pin → full card;
tap mobile pin → full card; off-list pin fetches once then cached; visible member =
no fetch (preseed); click-through nav still works (popup open→click=navigate);
anon precision unchanged (no PII/uuid leak). Then `tools/gates/run-all.sh`, present
commits + diffstat — NO push without Ian.

## Open question for Ian
None blocking — approach locked. Flag only: the `?slug=` filter touches the shared
profile-app endpoint; I'll announce to that lane before editing it.
