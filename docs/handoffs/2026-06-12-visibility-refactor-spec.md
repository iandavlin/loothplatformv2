# SESSION HANDOFF — Visibility refactor (profile-app + finder)

Written 2026-06-12 morning, by the fable coordinator session, at Ian's
direction: **"no patches, refactor only — handoff before compaction."**
Successor: read this + memory `project_visibility_model_final` before
touching ANYTHING visibility-related. Ian is at the end of his patience with
surface-by-surface patching — the next session builds the refactor below,
end to end, and proves it with the matrix test. Do not ship partial fixes.

## Ian's FOUR FINAL RULINGS (6/12, locked — do not relitigate)

1. **Profile master switch**: public (default) / **private = OWNER-ONLY** —
   invisible to members too (directory, map, search, profile page). Admins
   excepted. Does not exist yet (`users.profile_visibility` to be added).
2. **Imported/never-touched members default = MEMBERS-ONLY.** The public
   luthier finder is explicit **opt-in** ("Public sees" dial, or the
   front-page "Put me on the map" CTA). DATA PASS ALREADY RUN: 1896 rows
   `location_public_precision -> 'private'` (signal: `place_result IS NULL`
   = never picked via the editor). 3 genuine opt-ins remain public.
3. **Location keeps the two-audience precision dials** (Members see / Public
   sees × city/region/private). No simplification rework.
4. **Admins see everything** (existing exception stands: location dialed
   'private' hides the exact pin even from admins).

Plus the UX ruling for the finder: **logged-out gets the SAME full UI as
members** (two-pane, filters, map) — non-public members appear as anonymous
**"join to see" teaser cards + coarse gated pins**, never absent, never named.

## State as of this handoff (all pushed, main @ 9cbe326)

- Anon directory API (`directory-members.php`): non-consented members emit as
  `{gated:true}` items (no name/slug/avatar/uuid) — VERIFIED at the API.
  Named cards/pins only for opt-ins. Anon payloads strip `uuid` everywhere.
  Teaser PINS (coarse, rounded, message) ride the pre-existing gated-pin path.
- **KNOWN GAP (unresolved, goes to the refactor):** gated teaser CARDS don't
  paint in the browser — API emits them, the served page contains the
  `it.gated` renderer (`renderResults`), but only named cards render
  (2 children in #dir-results). Prime suspect: the buck overlay
  `directory-desktop.js` (v11, runs for anon since the finder reopened)
  re-rendering or filtering the list. Debug WITHIN the refactor.
- `pins-public` aggregate endpoint exists (no-auth, cells+counts only) — used
  by nothing currently (finder uses the full flow); keep for non-finder
  surfaces (front-page anon tile candidate).
- fp-map (front page tile): 3 member states working (on-map / never-set →
  IP-area no-pin map + CTA / stowed → teaser + CTA, no IP guess);
  "Put me on the map" CTA re-adds the section via me/layout. me/location
  carries `in_layout` + `opted_out` (additive flags).
- Members map/directory enforcement (logged-in) verified correct:
  `dir_member_display` is the single coarsening path; stowed section = off
  map for everyone incl. admins.
- Ian's own data fixed (was a dev-test 'Salem OR' pick; now Ridgefield NJ);
  audit artifacts: /tmp/map-divergent.json — 1 wrong-state pin
  (karrikercustoms: BP says Titusville PA, pin Rocklin CA) + 16 state-coarse
  rows AWAIT IAN'S GO before touching (member data = hold).

## THE REFACTOR (what the next session builds — whole, not piecemeal)

1. **One module**: `profile-app/src/Visibility.php` —
   `Visibility::can(viewer, subjectUserId, what)` where `what` ∈ profile |
   section(key) | location(precision-resolved) | file(class, path).
   Implements: master switch (owner-only private), section visibility
   (public/members/private), location dials, admin bypass, owner always.
   EVERY read path calls it: `u.php` SSR render, `directory-members.php`
   (list + pins), `pins-public.php`, `me-location` consumers, user/users
   APIs, search/suggest, and the FILE STORE (below). Kill the per-surface
   copies (`dir_member_display` keeps its coarsening math but delegates the
   decision).
2. **Master switch**: `users.profile_visibility` ('public' default |
   'private') + owner toggle in the privacy slider panel (CANONICAL panel,
   parity both surfaces per standing rule) + enforcement via the module
   (private → absent from every list/map/search; /u/ page → join/sign-in
   prompt for everyone but owner+admin).
3. **File store auth**: /profile-media currently serves EVERYTHING to anyone
   past the dev cookie (gallery + resumes included — THE standing hole).
   Front-controller (media.php) + nginx internal alias (X-Accel-Redirect):
   avatars/banners public; gallery → gallery section visibility; resumes →
   `users.resume_visibility`; unknown classes fail closed. uuid in the path
   identifies the subject.
4. **Matrix test** (definition of done): script (bin/visibility-matrix.php or
   curl harness) driving REAL HTTP as 4 viewers — anon, member, owner, admin
   (mint via `sudo -u profile-app php profile-app/bin/mint-dev-token.php
   <wp_user_id>`; wp 1 = Ian = profile user 4) — against every surface:
   /u/<slug> render, directory list+pins, pins-public, me/location, file
   URLs. Assert presence/absence per the matrix (sections × visibility ×
   viewer). Keep it as a regression gate. GREEN RUN = done; show Ian the run.

## Standing context a successor needs

- Repo serves LIVE: /srv/profile-app + /srv/archive-poc symlink into
  ~/projects — edits are live on save; php -l / node --check before save.
- Tree is contested (multiple sessions + buck temp-key merges) — COMMIT
  TESTED INCREMENTS IMMEDIATELY; an uncommitted archive.css edit was already
  clobbered once today. Commit ≠ push; Ian gates pushes (he has said "commit
  and push" repeatedly today — confirm per batch).
- Buck's overlays live in /var/www/dev/*.js (live = source of truth);
  coordinate via `msg send buck`. directory-desktop.js v11 currently runs
  for anon. Backups → /var/www/dev-bak-archive/.
- Tier gating is a SEPARATE one-rule system (whoami-only) — don't entangle.
- CDP verify: chrome-dev-login skill; cookies = dev gate (`loothdev_auth`
  from the nginx conf) + `looth_id` JWT from mint-dev-token.
- Related today (done, don't redo): Join→Patreon split (/connect-your-patreon
  public), What's-New blurb + bullets, maker→looth copy, shorty facade,
  guitardle DECOM'D (fast-follow; `_gdle-promo.php` live-ready — don't touch),
  hub pinned columns + stable pagination + filters modal, no-cache HTML on
  the front page.
- Open Ian decisions parked: 6 junk-slug shorties need human titles;
  map data fixes (1 wrong-state + 16 state-coarse) await his go; "Public
  sees" rendering on the public PROFILE page (it renders nothing for anon
  today — fold into the refactor's matrix).
