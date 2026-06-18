# SESSION HANDOFF — Visibility refactor: BUILT + GREEN (2026-06-12 pm)

The refactor specced in `handoffs/2026-06-12-visibility-refactor-spec.md` is
**done, live on dev, matrix-proven**. Successor sessions: the enforcement
point is `profile-app/src/Visibility.php` — if a visibility question comes up,
the answer is "route it through Visibility and add a matrix assertion",
never a per-surface patch.

## Definition of done — MET

```
php /srv/profile-app/bin/visibility-matrix.php   →   MATRIX GREEN  pass=55 fail=0
```

Real HTTP, four viewers (anon / member / owner / admin), three subject states
(public opt-in / members-only default / master private), across: /u/ SSR,
user + users APIs, directory list + pins, pins-public, me/location,
/profile-media files (avatar / gallery / resume), hub search-suggest. Re-run
any time as the regression gate. Fixture: profile user 1849 ('qa', wp 1910),
parked members-only so the public finder stays clean.

## What shipped (commits on main, in order)

- `3100bd1` A: `users.profile_visibility` migration + **src/Visibility.php**
  (the ONE module: viewer struct, audience×vis truth table, master switch,
  section decision, location precision rule, file classes). Profile::canSee +
  Block::gateDecision delegate to it; role 'admin' renders everything.
- `7535ede` B: /u/ + /user API answer **404** for private profiles (existence
  not probeable); admin role threaded; **/users identity API locked** (logged-in
  + loopback only; payload carries profile_visibility; private ⇒ slug:null).
- `2178b0c` C: directory/map through the module. Anon stack = **opt-ins only**;
  anon map = anonymous ~11km **dots** with ONE generic message (Ian 6/12 pm
  ruling: "dots for anon, stack vis only" — teaser CARDS removed, which also
  mooted the unexplained card-render bug). **Trilateration guard**: radius +
  distance run on the coarsened point per audience, never true coords.
  Members-private location = no pin for anyone below admin… and no anon dot
  (public never sees more than members). pins-public same filters.
- `2196441` D: **/profile-media auth** (media.php + X-Accel-Redirect; nginx
  live conf patched + reference snippet). avatars/banners public chrome;
  gallery → section vis; resumes → resume_visibility; unknown class closed;
  denials 404. THE gallery/resume hole is closed.
- `7dfcf25` E: **ONE DIAL** — the existing profile-visibility chip drives the
  master switch (me-header writes both columns atomically). No new UI control.
  Copy updated (control bar + privacy-sheet.js, the latter lives in
  /var/www/dev, buck-owned, patched in place).
- `53f2d9b` F: **hub search mask** — search-suggest JOINs forums.person; anon
  needs discussion_visibility='public' (fail-closed on missing row); master-
  private is never a hit for anyone. archive-poc role granted SELECT on
  forums.person.
- `e9981dc` + follow-up: the matrix harness (now 55 asserts, incl. the parked
  "Public sees on the anon profile page" item — works).

On **bespoke-cutover** (worktree ~/worktrees/bespoke-cutover): `93b3ab5` —
forums.person.profile_visibility cache column + bb_mirror_person_vis_batch +
bin/backfill-profile-visibility.php (ran: 501 resolved). Buck notified via msg.

## Decisions made WITH Ian this session (do not relitigate)

1. **One dial**: chip Private = master switch (owner-only everywhere, admins
   excepted). No separate toggle.
2. **Identity API locked** ("yes, if we still function as expected" — loopback
   callers verified working: archive-poc comments, bb-mirror person-sync).
3. **Hub search fixed in this refactor** (verified leak: anon q=david returned
   member names; now anon=[]).
4. **Anon finder = dots + vis-only stack** (supersedes the morning's
   per-member teaser-card form of ruling; "never named" holds, "never absent"
   now satisfied by the map dots).

## Since written (same day, all PUSHED with Ian's go)

- Everything above pushed (main through `7b4e7b1`, bespoke-cutover `93b3ab5`).
- Map data REPAIRED (`f0883a0`): evidence-guarded section fix from each
  member's own BB text — 119/126 verified coherent, 1 genuine fix
  (karrikercustoms → Titusville PA), geocoder hallucinations refused. Script:
  `bin/fix-divergent-locations.php` (idempotent). Audit JSON preserved at
  docs/map-divergent-2026-06-12.json.
- **Admin front-end editing** (`7b4e7b1`): admins open ANY profile in the real
  editor (?admin_edit=1); saves carry ?as=<uuid>, honored at ONE choke point
  (Auth::requireUser) — admin-only, profile-content allowlist (social
  endpoints excluded), audit-logged. Matrix now 60 asserts, GREEN.

## Still open / parked
- Master-switch flips reach the hub-search cache via person-sync/backfill
  (`bb-mirror/bin/backfill-profile-visibility.php [id]`) — eventual, same
  contract as the discussion mask. A synchronous push at flip time is a
  possible later nicety.
- Buck has 6/5-era coordinator asks sitting unread in `msg` (practice-block
  WS3 land, push notifications, footer mobile CSS) — not this lane's scope,
  left unread for the coordinator.
- whoami-anon (unbridged member) sees the anon mask in hub search — same
  known tradeoff as the Hub author mask, fix is the bridge, not the mask.
