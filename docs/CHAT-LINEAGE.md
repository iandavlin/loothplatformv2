# Chat Lineage Log

Append-only log of chat handoffs. When one chat is replaced by another for the same workstream (context burn, compaction, fresh start preferred, etc.), record the transition here.

**Format per entry:**

```
## YYYY-MM-DD HH:MM — <workstream>: <reason>

- **Previous:** <session-id> (last active: YYYY-MM-DD)
- **New:** <session-id>
- **Carried over:** what state crossed the boundary (handoff doc, key decisions, etc.)
- **Lost:** anything that didn't make the handoff and would be useful to know
```

Keep entries terse. The chats menu shows current state; this log shows history.

---

## 2026-05-28 10:53 — poller: promoted from terminal to tracked chat

- **Previous:** terminal sessions (ephemeral, multiple, none tracked)
- **New:** `7c518e34-15b9-44a6-a2f7-8cadcf41e3c4`
- **Carried over:** `docs/SESSION-HANDOFF.md` (current with all coordination addenda); shipped code on dev (user-context endpoint, looth_tier_changed action, PurgeNotifier); coordination contract awareness
- **Lost:** any in-conversation context from the original terminal sessions that wasn't promoted to the handoff doc

## 2026-05-28 ~11:30 — coordinator: clean handoff to successor

- **Previous:** `7deff0ff-4cf1-450b-9a5c-1e59ec7d5025`
- **New:** *(Ian to spawn fresh + capture ID)*
- **Reason:** context was getting full; clean handoff before forced compaction
- **Carried over:** all coordination canon in `STRANGLER-COORDINATION.md`, fresh `STRANGLER-SESSION-HANDOFF.md` snapshot, complete `CHATS-MENU.md` + `CHAT-LINEAGE.md`, two memory entries for relay formats + mobile lens canon in §3j just landed
- **Lost:** in-conversation context from prior coordinator session (~150 turns of negotiation, decision history, debugging). All material decisions are in the durable docs; lost context is the "how we got there" reasoning, not the destinations
- **Successor briefing:** `/home/ubuntu/projects/docs/briefing-coordinator-successor.md` (drafted alongside this rotation)

## 2026-05-28 11:11 — archive-poc: fresh session

- **Previous:** `e1421b41-c84f-419d-8b4a-1e424fbdb824` (FE editor design session originally, then absorbed coordination + postgres prep)
- **New:** `aec4f10b-e5b6-4db0-993b-75e0ee39233c`
- **Carried over:** SESSION-HANDOFF.md (postgres migration plan, schema.pg.sql shipped, dev pg up, backfill-pg dry-run clean); coord doc awareness via briefing-archive-poc-postgres.md as opener
- **Lost:** in-conversation context from prior session; pending the P3 reversal note ([reply-to-archive-poc-p3-reversal.md](reply-to-archive-poc-p3-reversal.md)) and UX request bundle ([reply-to-archive-poc-ux-requests.md](reply-to-archive-poc-ux-requests.md)) to be re-relayed since they may have only been pasted into the old session

---

## 2026-05-28 — BB-mirror: rotation mid-P5

- **Previous:** rotated session from mass rotation (ID pending)
- **New:** session ID pending — outliner title unchanged: *Reskin BB Forums and plan mobile app …*
- **Reason:** context burn mid-session
- **Carried over:** SESSION-HANDOFF.md; all hooks wired (topics, replies, edit, trash, merge/split, group hooks); live UI rehearsal + reconciliation cron are the outstanding P5 items
- **Lost:** in-conversation context; pushback exchange on P5 scope (hooks already wired, real work = live rehearsal + recon cron). Not in docs — coordinator has it.

## 2026-05-28 ~14:30 — coordinator: clean handoff to successor

- **Previous:** `c047417b-6581-4b1a-b2ae-62496b785bca`
- **New:** *(Ian to spawn fresh + capture ID)*
- **Reason:** context growing; clean handoff
- **Carried over:** all coordination canon in `STRANGLER-COORDINATION.md`, fresh `STRANGLER-SESSION-HANDOFF.md`, complete `CHATS-MENU.md` + `CHAT-LINEAGE.md`
- **Key facts not in prior docs:** live WP DB = `wp_loothgroup`; BB-mirror table names singular; profile-app needs fresh BUILD session (coordination chat is idle); messages alive on live (135/30d → full modal); setfacl pattern for secret file
- **Successor briefing:** `/home/ubuntu/projects/docs/briefing-coordinator-successor.md`

## 2026-05-28 evening — coordinator: successor session active (ID uncaptured)

- **Previous:** `c047417b` (the ~14:30 successor)
- **New:** `34c73878-3c14-41f6-b56f-8d5195ea47e4` (confirmed via transcript grep — this session's .jsonl is the one that ran the doc audit)
- **Work done this session:** drove P1/P2/P3/P6/P7c to ✅; ratified blue-green
  cutover model (fresh EC2 + DNS swing); cut CF-purge + user-comms at launch;
  set `dev.loothgroup.com/` front page → `/archive-poc/`; drafted legacy-post
  → lg-layout-v2 gating pointers; ran full doc audit (archived 33 consumed
  relays to `relays-archive/2026-05-28/`, rotated this handoff).
- **⚠️ Open:** coordinator session ID needs capturing in `CHATS-MENU.md` row 1
  + here. Ian to provide.

## 2026-05-28 evening — membership-pages: assigned to poller (NOT a new lane)

Briefly considered a separate chat; corrected to **poller's purview**. The
poller owns `Shortcodes.php` + `Pages.php` and already drove these pages
through the test-checklist — UI included. A separate chat would have
triple-coordinated (lg-shell + poller + cutover); poller owning it removes the
shortcode-markup boundary entirely.

- **Task:** put the Stripe/membership WP pages on the unified `/srv/lg-shared/`
  header (mu-plugin `template_include` swap), dev-testable, cutover-ready.
- **Briefing (now a poller task doc):** `/home/ubuntu/projects/docs/briefing-membership-pages.md`
- **If poller chat context is full:** rotate it (fresh poller chat carrying
  this task), not a new lane.

## 2026-05-29 — poller: rotation for context (carries membership-pages task)

- **Previous:** `0981c23e-ab73-47ba-9065-aa9d542c94fb`
- **New:** *(pending — Ian to spawn + capture)*
- **Reason:** context full after shipping user-context endpoint, action+purge,
  P4, Patreon adapter, Arbiter stripe guard, round-trip verify + backlog burn.
  Fresh chat carries the new membership-pages task cleanly.
- **Carried over:** refreshed `SESSION-HANDOFF.md` (top summary: active task +
  P8 pending + shipped-this-lane index + open security findings; original
  2026-05-17 content preserved below); `briefing-membership-pages.md` (task);
  `notes-for-rotated-chat-membership-pages.md` (135 lines tacit knowledge —
  PAGES registry shape, BB allowlist coupling, fragile-vs-clean shortcodes,
  `[lg_member_nav]` cleanup landmine, body-class chrome deps, CDP
  submit_button() shadowing, PoC sequence rationale).
- **Still owed on the lane:** P8 dormant smoke (not started); the 4 open
  security findings (subscriber author-caps, Fluent SMTP plaintext key, etc.).
- **Opener (in order):** `briefing-membership-pages.md` →
  `notes-for-rotated-chat-membership-pages.md` → `SESSION-HANDOFF.md`.

## 2026-05-29 — profile-app: chat a847d1aa RETIRED (clean), profile-2.0 → fresh chat

- **Previous:** `a847d1aa-8252-4c06-8d90-3e470d3cc265` — carried slice 0→3.5,
  `/whoami` + WP-session auth bridge, cross-lane coordination, and the
  cutover-prep backfills.
- **New:** `1c98b564-ae29-4bc2-af2d-b06f80498aa4` (spawned 2026-05-29 21:25 by
  coordinator, via `claude --session-id` background opener seeded with
  `docs/bootstrap-profile-2.0.md` + the marching orders + retirement handoff +
  `plan-profile-block-system.md` + `spec-block-identity-location.md`. Opening
  turn constrained to **Phase 0 — MOCKUPS FIRST**: mock the composer
  sidebar-palette editor + block-model profile page + typed practice page into
  `/var/www/dev/mockups/`, surface for reaction. NO build/schema/migration this
  turn, per the marching orders' design-confirm cadence.)
- **Reason:** profile-2.0 is a multi-week arc; clean break from the dense
  slice-history chat. Retired-not-resumed.
- **Carried over (committed):** backfills `23fe81b` — `bin/migrate-socials.php`
  (xprofile-266 primary + ACF author_* fallback, three-tier precedence, mapping
  twitter→x / reddit→web / linktree-new-kind, kind+url only), `location_address`
  fold into `snapshot-location-from-bb.php`, schema `2026-05-29-block-system-precursors.sql`
  (location_address + linktree CK). Retirement handoff `76052eb` at
  `profile-app/SESSION-HANDOFF.md` — slice-4 prod checklist + 4 carry-forward
  surprises (xprofile camelCase `youTube`; dual-column same-source location;
  per-kind not all-or-nothing precedence; no-per-row-vis is a settled invariant).
  Dev rehearsal green: walk `20260529T194240Z` (165 xprofile + 45 ACF + 2 kept, 4 linktree).
- **Lost:** in-conversation context; substance is in the handoff + committed code.

## 2026-05-29 — shim-replacement: lane spawned (new, not a replacement)

- **Previous:** *(none — net-new lane, P12)*
- **New:** `d9380b73-df4d-4836-8d54-735c0bf09b33`
- **Outliner title:** *shim-replacement* (lane-reported; code-server auto-title resolves on open)
- **Reason:** P12 pre-cut-required lane — mint `looth_id` JWT at WP login, retire the per-page `/whoami` loopback. Dedicated chat per Ian (fast first experience, dev-built+soaked before flip).
- **Spawned by:** coordinator, via `claude --session-id` background opener seeded with `docs/bootstrap-shim-replacement.md` + `docs/briefing-shim-replacement-design.md`. Opening turn constrained to design-confirm only (no build until design reviewed, per briefing).
- **Carried over:** the two kickoff docs (bootstrap + design briefing, ~90% spec); STRANGLER-COORDINATION.md §2/§0 awareness.

## Entries below this line should be appended chronologically as handoffs happen.

---

## 2026-05-28 ~12:00 — mass rotation: all active chats refreshed

All 7 chats rotated within the same session (context management). Session IDs for new sessions not yet captured — Ian to provide UUIDs when available.

| Workstream | Previous ID | New outliner title | New ID |
|---|---|---|---|
| coordinator | `7deff0ff-4cf1-450b-9a5c-1e59ec7d5025` | *Review briefing coordinator successor …* | pending |
| profile-app | `a847d1aa…` | *Profile app next session planning* | pending |
| BB-mirror | `ed723d17…` | *Reskin BB Forums and plan mobile app …* | pending |
| poller | `7c518e34-15b9-44a6-a2f7-8cadcf41e3c4` | *Promote briefing poller to chat* | pending |
| archive-poc | `aec4f10b-e5b6-4db0-993b-75e0ee39233c` | *Briefing archive POC with Postgres* | pending |
| cutover | unknown | *Review briefing cutover …* | pending |
| lg-shell | *(first session)* | *Review LG shell briefing document* | pending |

- **Carried over:** all durable docs (STRANGLER-COORDINATION.md, each chat's SESSION-HANDOFF.md, CHATS-MENU, CHAT-LINEAGE). Relay queue delivered before rotation.
- **Lost:** in-conversation context from prior sessions. Substance is in the handoff docs.

---

## 2026-05-28 ~11:10 — workstream rename: lg-bp-mirror → lg-shell

Not a chat replacement (no prior chat existed), but a scope expansion + rename worth logging.

- **Previous identity:** `lg-bp-mirror` (modal layer + REST + auth reskin)
- **New identity:** `lg-shell` (everything above + the shared header partial previously assigned to archive-poc as P3)
- **Why:** the modals attach to the header (bell, message icon are IN the header), share design tokens, share data sources. One chat owning the whole shell = one coordination point. archive-poc gets P3 off their plate and stays content-focused.
- **Artifacts renamed:** `briefing-lg-bp-mirror.md` → `briefing-lg-shell.md`; `lg-bp-mirror/` dir → `lg-shell/`; coord doc + menu updated
- **Side effect:** P3 reversal note sent to archive-poc ([reply-to-archive-poc-p3-reversal.md](reply-to-archive-poc-p3-reversal.md))

## 2026-06-04 — coordinator: clean handoff to fresh successor

- **Previous:** `34c73878-3c14-41f6-b56f-8d5195ea47e4` (last active: 2026-06-03 PM)
- **New:** *(Ian to spawn fresh + capture ID)*
- **Reason:** context fullness; clean handoff before forced compaction
- **Carried over:** `STRANGLER-COORDINATION.md` (contract), live `LANE-LEDGER.md`, the
  `handoff-coordinator-2026-06-03-pm.md` snapshot, current `CHATS-MENU.md` + this log. Fresh
  successor briefing at `docs/briefing-coordinator-successor.md` (rewritten 2026-06-04; prior
  05-28 copy archived to `strangler-handoffs/2026-06-04-coordinator-successor-prior.md`)
- **Lost:** in-conversation reasoning from the retired session; all destinations are in the docs

## 2026-06-05 — coordinator: clean handoff + decommission

- **Previous:** this session (retired, context full)
- **New:** *(Ian to spawn fresh + capture ID)*
- **Reason:** big session — archive-poc PG read-cutover landed, Hub-unification project kicked off; clean handoff
- **Carried over:** fresh `briefing-coordinator-successor.md` (rewritten 2026-06-05, project-focused), `DB-STATE-AUDIT-2026-06-05.md`, `hub-filter-nav-spec.md`, §4 cutover model rewritten to in-place promotion, all lane briefings in docs/. Prior successor briefing archived to `strangler-handoffs/2026-06-05-coordinator-successor-prior.md`
- **Live state at handoff:** archive-poc reads on Postgres (proven, faster, SQLite intact); poc lane on the `_sync.php`→PG port (gate for SQLite retirement); hub lane parked on poc; ~9 commits committed-not-pushed awaiting Ian's push sign-off; bridge enabled on dev
- **Lost:** in-session reasoning; all destinations are in the docs

## 2026-06-05 PM — comments-db + reactions/stream → consolidated comments+reactions lane

- **Retired:** `3df42b5c-7f6c-4969-a5a0-e355a8a91ca7` (comments-db) + `b2bb9043-2839-473c-be0f-ddc665a2e79c` (reactions/stream)
- **New:** `1c86c753-6716-44cb-b047-e888f09d3bf6` (comments-reactions) — briefing `docs/briefing-comments-reactions.md`
- **Reason:** both backends dev-proven; folded into one lane to own comments + reactions/likes going forward (Ian asked for a fresh chat)
- **Carried over:** `SESSION-HANDOFF-comments-db.md` open items → the fresh briefing's queue; new lane handoff `SESSION-HANDOFF-comments-reactions.md`
- **Landed first session:** dd248c5 (bb-mirror comments grant committed), 3dfda18 (badge count reads live store, not WP bake) — committed, not pushed

## 2026-06-07 — coordinator: clean handoff to fresh successor

- **Previous:** this session (retired, context full after a large session)
- **New:** *(Ian to spawn fresh + capture ID)*
- **Reason:** big session — Hub mobile/desktop split, reactions backend + BB backfill, profile-app launch P1–P6, paywall=(b) decision; clean handoff
- **Carried over:** fresh `briefing-coordinator-successor.md` (rewritten 2026-06-07), `hub-mobile-desktop-split.md` (the architecture), `hub-deploy-roadmap.md`, `CHATS-MENU.md`, the `project_paywall_model` + reaction-count memories. Prior successor briefing archived to `strangler-handoffs/2026-06-07-coordinator-successor-prior.md`
- **Live state at handoff:** Hub deploy-ready; 100 commits committed-not-pushed (Ian's gate — the open line); profile P1–P6 landed via coordinator-as-Buck's-hands; reactions real + WP-free (BB backfill runs --all at cutover); paywall=(b) profiles-free; cooler-card prompts ready; login skin pending
- **Lost:** in-session reasoning; all destinations are in the docs

## 2026-06-07 PM — Hub consolidation + engine edit/delete

- **hub → hub-COORD** (`a5a33224-dc0c-4086-b78d-83f96de9f56e`, title *Hub Desktop Surface — resume → hub-coord*): promoted to drive all of Hub solo; **folds in** the old hub-fold (`9645be99`) + reactions-SURFACE (`0ad40ab7`) lanes. Owns all `bb-mirror/web/`. Charter: `briefing-hub-coord.md`.
- **comments+reactions ENGINE (edit+delete)** (session pending — Ian confirm; cand. `6c51fab9`/`d0ba32af`): successor to `1c86c753`; shipped comment edit + soft-delete (`5b262c0`) + modal UI wiring (`a652b1c`).
- **Reason:** Ian low on budget, mostly working Hub — collapse to two working chats (hub-coord consumer + engine provider, zero file overlap) to kill the `forums.*` collision + cut context re-reading.
- **Model change logged:** Hub gating reversed to locked-teasers (not absence) — memory `project_hub_gating_teaser_model`.

## 2026-06-07 PM (II) — three-chat consolidation

- **card-surface chat** (*Hub cooler-card composer finishing*) → **folded into hub-COORD** (`a5a33224`).
  Reason: a 2nd Hub-desktop chat in `forums.*` reintroduced the collision + the flat-contract-drift that
  broke mobile (4 unannounced `fc-*` regions). End-state = ONE Hub-desktop owner = single source of
  contract changes. Its composer avatar+pencil+placeholder landed clean (`f7666c4`).
- **Buck sub-coord** (`b1b940d4`) → **buck-COORD** (refreshed charter `briefing-buck-coord.md`, session
  pending Ian spawn). Dedicated handler for ALL Buck work (unprivileged-diff model, APP_ROOT guard,
  tokens/CDP, profile-app 640 split, mobile chips→radio). Holds the flat-contract↔mobile announce boundary.
- **Net working roster:** hub-COORD (Hub desktop, one contract source) · buck-COORD (all Buck/mobile +
  profile-app) · comments+reactions ENGINE (backend). Plus standing lanes (poller, archive-poc, etc.).
- **Lesson logged:** contract-change announcements between desktop↔mobile are now a buck-COORD standing
  duty; Buck made mobile self-healing (`.feed-card > * {grid-column:1/-1}`) so drift no longer hard-breaks.

## 2026-06-07 PM (III) — coordinator handoff (decommission)

- **Retiring:** coordinator `eb7073b6-ce45-4e6e-b461-d95267ba06bb` (this session) — decommissioned clean by Ian.
- **Successor:** fresh coord *(Ian to spawn + capture ID)* via `briefing-coordinator-successor.md` (rewritten 2026-06-07 PM).
- **Also spawned this handoff:** new profile-page lane + new hub-coord (briefings ready; report IDs to successor).
- **State at handoff:** dev healthy (Hub/login/loothprint 200). Focus = cutover/dev2 prep. Open: lg-layout-v2 3 tasks (download renderer = the loothprint fix), discussion-visibility feature (default member; routed Buck/profile/hub-coord), lg-snippets folded+uncommitted, the "doesn't ride git" cut playbook. Unpushed-to-main read 0 (confirm w/ Ian). Parked: per-post anon button, loothprint user-submission, post-notifications (sonar bell decision open).
- **Lost:** in-session reasoning; all destinations are in the docs + memory.

## 2026-06-09 00:22 — Hub-desktop + profile-desktop + map-desktop: folded into buck-COORD

- **Previous:** hub-COORD `a5a33224-cccc-...` (Hub desktop / all `bb-mirror/web/`), profile-page lane (profile `u.php`/`_render_blocks.php`), map-desktop lane (directory/map desktop)
- **New:** buck-COORD (single owner of desktop+mobile for Hub feed, profile page, directory/map)
- **Reason:** Ian 6/8 — one owner per surface kills the desktop↔mobile `fc-*` contract-announce dance (root of the mobile-flash/gray-box regressions). Transfer ran AFTER the 13-commit push (clean pushed tree, no in-flight).
- **Carried over:** `docs/handoff-desktop-to-buck.md` (full inventory + conventions + checklist) = buck-COORD's absorb-briefing; both features (discussion-visibility + anon Phase 1) shipped + on origin/main before the flip.
- **Lost:** nothing material — all three lanes' work was committed + pushed; conventions are in the handoff doc + the split docs.

## 2026-06-12 ~12:00 — coordinator handoff (compaction)

- **Retiring:** the fable coordinator session (ran the 6/12 visibility/front-page day; wrote
  `SESSION-HANDOFF-visibility-refactor.md` at Ian's direction before compaction).
- **Successor:** coordinator `570f18a3-5649-4c1a-8063-d28c5839f7b5` (this entry's author; opener "I need a new coord").
- **State at handoff:** main @ 5086aba, unpushed 0. Working tree: front-page lane's in-flight
  "Add to calendar" chooser (archive.css/js, uncommitted), guitardle fast-follow note in
  archive-poc/SESSION-HANDOFF.md (uncommitted), index.sqlite dual-write churn, plus the front-page
  lane's report `reply-to-coordinator-front-page.md` (untracked, awaiting processing).
- **The line:** the VISIBILITY REFACTOR (`SESSION-HANDOFF-visibility-refactor.md`) — four locked
  rulings, one Visibility.php module, master switch, file-store auth, matrix test = definition of
  done. No more surface-by-surface patches.
- **Open Ian decisions parked:** map data fixes (1 wrong-state + 16 state-coarse, /tmp/map-divergent.json);
  6 junk-slug shorties need titles; LIVE-NOW banner Zoom link + ICS-keeps-Zoom (front-page lane flags);
  anon map tile as join funnel (idea).
- **Lost:** in-session reasoning; destinations are in the handoff doc + memory.
