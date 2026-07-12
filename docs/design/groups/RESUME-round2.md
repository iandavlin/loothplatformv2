# RESUME — groups-design lane (round 2: navigation + posting flow)

**Paused 2026-07-12 ~15:10 UTC for the dev1 box resize.** Nothing is in flight. Nothing to restore.

---

## 1. State at pause — everything is safe

| | |
|---|---|
| **Branch** | `groups-design` @ **`eb92ab4`** (off main `6d21925`) — **pushed to origin**, `origin/groups-design..HEAD` is empty |
| **Worktree** | dev2 `~/worktrees/groups-design` — **clean**, `git status` empty. Nothing uncommitted. |
| **dev2 state left by ME** | **None.** No overlay, no symlink swap, no FPM reload, no chromes. This lane is design-only and shoots CDP-injected mocks against the untouched serve. Verified at pause: no chrome processes (exe-checked, not `pgrep -f` — that self-matches), no `/srv` symlink of mine. |
| **Round-2 file edits** | **Zero.** Round 2 got as far as recon only. `DESIGN.md` is still the round-1 doc. |

⚠️ **Someone else's overlay IS open on dev2 — not mine, do not touch.** The **notifications lane**
opened an overlay window at 15:00 (announced ~45–60 min): `/srv/{profile-app,lg-shared,bb-mirror,archive-poc}`
are symlinked to `~/worktrees/notifications/*`, plus `/var/www/dev/{bottom-nav.js,pwa.js}`. **dev2 is
serving their branch, not main.** They restore byte-identical and announce on the board. If dev2 looks
wrong to the next person, that is why — check the board before "fixing" it.

---

## 2. What round 2 is (the new task)

Keeper, 2026-07-12 15:03 — **chats are ON HOLD** (Ian: *"I think everything can be done from
discussions"*). The group **chat room is deferred, not cancelled**: build nothing, design no room
schema / read-state / notification-volume guard. Discussions become the **single** content surface for
a group. **Ian likes the mini-hub pages** (mocks approved in principle).

**The one open question, now mine:**

- **(a) Discovery — how does a member REACH a mini-hub?** Candidates: group chip on every Hub post ·
  a "Groups" entry in the Nav tray · the You tab ("my chapters / my subjects") · fold it into the
  parked `hub-picker-in-tray` (currently a CONTENT-TYPE picker — is GROUP a second axis in the same
  picker, or a separate door?) · a location-suggested chapter on first visit.
- **(b) Ian's idea — should "+ New post" in the Hub take you to the local-Looth / subject MINI-HUB and
  you compose THERE (in context), instead of the current "Forum (pick one)" tree?** Evaluate honestly.
  Upside: kills the forum tree + the phantom "GENERAL", gives the poster context, makes the group a
  real destination. Risk: an extra step for someone who just wants to post to the Hub generally — **so
  what is the path for non-group-specific content?** Ties to my own round-1 finding that some forums
  have **no group** (Quick Questions = 181 topics) → you likely need a **real "General" group** for this
  model to be universal (I already recommended exactly that: round-1 DESIGN.md §6.6 item 6).

Deliverable: **options + a recommendation + mocks of the nav entry points and the new-post→mini-hub
flow, both viewports.** Then **STOP for Ian.**

---

## 3. Round-2 recon — DONE, and worth keeping (this is the expensive part; do not re-derive)

All read from the clean checkout `~/loothplatformv2-clean` @ main `6d21925` (**not** the overlaid serve).

### 3.1 🔑 The composer ALREADY supports "compose in context" — Ian's idea is a *small* change

`bb-mirror/web/_chrome.php:308–312` — the new-post form carries **`data-current-forum`**, and
**`_chrome.php:270–282`** already computes it from the URL: `?fid=<n>`, or the active 1-segment forum
slug. **The composer pre-selects a forum from page context today.**

**So "+ New post on a mini-hub composes into that group" does not need a new composer.** It needs the
mini-hub to *supply the context* the composer already reads. That materially changes the cost of
option (b) — it is assembly, not a build. **This is the single most important finding of round 2.**

### 3.2 The forum picker query (the thing Ian wants to kill)

`_chrome.php:253–261`:
```sql
SELECT f.id, f.slug, f.title, f.parent_forum_id, f.menu_order, p.title AS parent_title
  FROM forum f LEFT JOIN forum p ON p.id = f.parent_forum_id
 WHERE f.visibility='public' AND f.status='open' AND f.forum_type='forum'
   AND f.id NOT IN (67251, 3876)                                        -- the band-aid (see §6.5)
   AND f.id NOT IN (SELECT parent_forum_id FROM forum WHERE parent_forum_id IS NOT NULL)  -- leaves only
 ORDER BY COALESCE(f.parent_forum_id, f.id), f.menu_order ASC          -- interleaves the parentless
```
Leaf forums only (you never post to a container). The `ORDER BY COALESCE(...)` is what interleaves
parentless forums between the parented runs, which is why the run-length header logic emits
**"General" more than once** — round-1 DESIGN.md §6.1. Label bug is `_chrome.php:323`.

### 3.3 The Nav tray — and a ruling that constrains the answer to (a)

`webroot/bottom-nav.js:55–63` — the tray's "Go to" grid is a **plain data array** (`DESTS`: Home, Hub,
Events, Members, Shop, Sponsors, Loothtool), rendered at **L748**, in the house `.lt-sheet` idiom.
Adding a tile is trivial.

**But read the comment at L50–54 first** — Ian/keeper, 2026-06-24: the Nav tray is
**PLACES ONLY**. *"Messages + Alerts are personal, not destinations, so they live in the You sheet,
NOT here."*

**That ruling already answers half of (a) for me, and I should honour it rather than re-litigate it:**
- **"Groups" as a browsable directory** = a *place* → **Nav tray tile.**
- **"My chapters / my subjects"** = *personal* → **You sheet**, next to Messages/Notifications.

That is the existing information architecture, not my invention. The recommendation should land on the
right side of it — and say so explicitly, because it's the kind of thing that looks arbitrary unless
you cite the ruling.

### 3.4 The parked picker — do not duplicate it

`hub-picker-in-tray` lane is **PARKED** (branch `@ffa13ca`, variant A "sibling-sheet" + the house
slide motion, awaiting a verify window). Its picker opens a **CONTENT-TYPE** sheet from the Nav tray's
Hub tile. Keeper: *"Do not duplicate it; propose how GROUP nav relates to it."* → The real question is
whether GROUP is a **second axis in the same sheet** (type × group) or a **separate door**. My instinct
before mocking: content-type and group are **orthogonal filters**, and cramming both into one sheet is
how you get a control nobody understands — but I owe this an honest mock either way.

---

## 4. What is LEFT (exact next steps, in order)

1. **Re-cost the build plan** — say plainly what dropped out with the chat room. Round-1 §7 Phase 5
   (group chats, size **L**, the only High-risk phase, the only new write path at scale) **is gone.**
   What remains is assembly of parts we already own. Rewrite §4 (rooms) and §7 (plan) accordingly, and
   keep the model **extensible** so a room can be added later — but design none of it.
2. **Write the options + recommendation** for (a) nav/discovery and (b) new-post→mini-hub, using §3.1
   (composer already context-aware) and §3.3 (places-vs-personal ruling) as the load-bearing facts.
   Answer the honest risk in (b): **the escape hatch for non-group-specific content** — tie it to the
   real "General" group I already recommended.
3. **Mocks.** Harness is ready: `~/projects/groups-design-lane/mocks/src/mock.js` + `mock-hubchip.js`
   (CDP runtime-injection over the real dev2 serve — real header, real fonts, real colour tokens).
   **Ports 9700+, NO overlay, mobile 390 + desktop 1280.** Shot set to produce: nav entry points
   (tray tile / You-sheet "my groups" / Hub-card group chip) + the **new-post → mini-hub compose flow**
   step by step + whatever the picker answer turns out to be.
   ⛔ **BLOCKED until the notifications lane restores dev2** (§1) — their branch changes the nav tray
   and bottom-nav.js, which is *exactly* the surface I'm mocking. Shooting during their window would
   produce mocks of their branch, not main. **Watch the board for their restore, then shoot.**
4. **Update `DESIGN.md`** (round-1 doc stays; add the nav + posting-flow section, re-cost §7, fold the
   chat room into a "deferred, kept extensible" note). Copy to `docs/atlas/`.
5. **Commit + push** to `groups-design`. **Do NOT merge.**
6. **Post to the board. STOP for Ian.**

## 5. Still-open questions from round 1 (unchanged — Ian has not answered these)

- **Q1 (gates the build): opting OUT of a subject — hide its posts from the main Hub feed, or only mute
  notifications?** My rec: **hide from the feed**. Still the crux.
- **Q7: which 3 chapters did Ian mean?** I believe I proved it without him: **Middle Tennessee, Ohio,
  Basque Country** (§6). Please confirm.
- Q2 (chat open to non-members) is now **moot** — chats deferred.
- **Hard ordering constraint that survives everything:** the §6.1 grouping fix **must** land *before*
  the chapters are made public, or the "chapters under GENERAL" bug goes from **3 chapters to 10**.
