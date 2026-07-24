# REPLY-SURFACES-AUDIT — every composer/thread surface, from code truth

*mentions lane, 2026-07-24. Written after the mobile-mentions campaign (branch
`username-mentions-finish`) surfaced three iPhone-only failures that a green
headless-Chromium harness never saw. Citations are `file:line` at branch tip 334ffa4
(= a30ebfd ship candidate + origin/main). Companion: COMPOSER-V2-PLAN.md.*

**The one-sentence diagnosis:** we have ~8 independent composer/sheet implementations,
each with its own backdrop, its own open/close code, its own scroll-lock idea and its
own z-index, sharing nothing but the document they fight over; every mobile bug of the
last week is a pairwise interaction between two of them.

---

## 1. Surface inventory

### 1.1 lrs — mobile replies/discussion sheet (`#looth-rep-sheet`)
- **Where**: `webroot/hub-polish.js` — build+open `openRepliesSheet()` :3457, close
  `lrsClose()` :3170, submit `lrsSubmit()` :3671.
- **Entry**: mobile (≤640px) Reply action `.lg-act-replies` (:430–458 routes every
  reply-ish tap here), "View N replies" expanders (:4176ff), `?topic=` deep links via
  `window.lgOpenTopicMobile` (:3667), read-more intents.
- **Write path**: `lrsSubmit()` POSTs **`/bb-mirror-api/v0/reply`** (:3685) — the owned
  mirror endpoint (mints mentions + rings bells, §3).
- **Lifecycle**: full-viewport `position:fixed` at **z 2147483520** (:3004); open sets
  `body.style.overflow='hidden'` **and** the `lg-sheet-lock` class (:3631–3633 →
  observer §4); close is symmetric (:3170–3176) and clears any composer-set
  behind-state via `lgSetBehind(sh,false)` (:3173).
- **History entanglement**: pushes its own `?topic=` history entry (:3641–3649); the
  popstate handler (:3195–3220) must arbitrate between the lightbox, the composer
  sheet, and itself. This dance is fragile by construction — three parties guess at
  who owns the current history entry.

### 1.2 lcp — mobile composer sheet (`#looth-comp-sheet`)
- **Where**: `webroot/hub-polish.js` — build `ensureCompSheet()` :3710, open
  `openComposerSheet()` :3956, close `closeComposerSheet()` :4069, submit
  `lcpSubmit()` :4086, keyboard lift `lcpKb()` :3887.
- **Entry**: auto-opened ON TOP of lrs by a Reply-intent tap (:436
  `openRepliesSheet(card,{toReplies:true,focus:true})` → :3654 `openComposerSheet`),
  the lrs "Write a comment…" pill, per-reply Reply links, and EDIT reuse: the same
  sheet doubles as reply-editor and topic/OP-editor (`editReplyId`/`editTopicId`,
  :3987–4046).
- **Write path**: create → **`/bb-mirror-api/v0/reply`** POST (:4151); reply edit →
  same endpoint PUT (:4121); topic edit → same endpoint PUT w/ `topic_id` (:4085).
- **Lifecycle**: z **2147483560** (:3720); floating card over a full-screen
  `.lcp-back` scrim; on open: `lgScrubSheetState()` idempotent reset (:3959) then
  marks lrs as backdrop `lgSetBehind(lrs,true)` (:4003); on close: symmetric clear.
- **Dismiss paths** (all verified to tear down symmetrically, §5): backdrop tap
  (`[data-lcp-close]` :3811 — needs `cursor:pointer`, receipt R1), grab-pill swipe
  (:3820–3831), post-success, phone back-gesture (:3204–3208).

### 1.3 The mention autocomplete panel (`.lg-mnt`)
- **Where**: `bb-mirror/web/forums.js` — IIFE `lgMentionAutocomplete()` :286, panel
  build :292–363, editor matcher `editorOf()` :365 (covers `.ql-editor`,
  `textarea.rse-input/.fic-input/.lg-fb-replyinput/.lcp-input`, `#lrs-comp-input`,
  `#frm-content`, `#ntm-content`), suggest fetch :479 → `/profile-api/v0/mention-suggest`,
  insert `pick()` :493, render+position :401.
- **One panel, every composer**: a single body-appended `position:fixed` node at
  **z 2147483600** serves all surfaces; mobile gets the FB-style `.lg-mnt--sheet`
  styling (name-first rows, 46px avatars, helper caption).
- **KNOWN DEFECT carried in the ship candidate**: pick fires on `touchstart`
  (:359), so a scroll attempt inside the list insta-picks. The 3B tap-vs-scroll
  handler (pick on touchend <10px/<700ms) exists in branch history (`bd02b56`,
  reverted with round 3) and is encoded as a KNOWN-FAIL test in
  `tools/e2e-webkit/tests/reply-stack.spec.js`.

### 1.4 ntm — new-topic modal (`#ntm-overlay`)
- **Where**: `bb-mirror/web/forums.js` :1652–2024; open `ntmShowOverlay()` :1770, close
  `ntmHideOverlay()` :1831; desktop ≥641px reshapes into a 4-step wizard (:1899–1950).
- **Write**: create POSTs **native BB REST** `/wp-json/buddyboss/v1/topics` (:1677
  `restBase` default, :2359 fetch). Edit routes to the mirror (reply.php topic PUT).
- **Lifecycle**: `ntm-active` body class (:1772/:1833) → the position:fixed lock
  observer (§4) — the ONE surface family that was iOS-correct from the start.
- **State machine**: `ntmAuthState` idle|loading|anon|authed (:1839); Quill lazy-init.

### 1.5 frm — desktop reply/edit modal (`#frm-overlay`)
- **Where**: `bb-mirror/web/forums.js` :2428–2914; open `frmOpen()` :2567, close :2603.
- **Entry**: `.feed-card__reply-cta[data-frm-open]` delegate (:2747) — including the
  reply CTA the desktop dmodal injects (forums.js:4203).
- **Write**: **create POSTs native BB REST** `/reply` (:2442 default, :2860 fetch) —
  see G8. Reply-edit PUT `/bb-mirror-api/v0/reply` (:2619); topic-edit PUT (:2672).
- **Lifecycle**: reuses the `ntm-active` body class (:2599) → iOS-correct lock.

### 1.6 rse — inline reply edit (`.rse-*`)
- **Where**: forums.js :959–1096; entry `.reply-stub__edit` delegate; PUT
  `/bb-mirror-api/v0/reply` (:1082). Inline (no modal/lock). Preserves inline `<img>`
  on edit (:1064–1066) unlike create composers.

### 1.7 fic — inline card quick-reply (`.fic-*`)
- **Where**: forums.js :1122–1160; mounted by `protoMountComposer()` on card expand.
- **Write**: **POSTs native BB REST** `/reply` (:925 base, :1145 fetch) — see G8.

### 1.8 lg-dmodal — desktop discussion modal (`#lg-dmodal`)
- **Where**: forums.js :3995–4405; `window.lgDmodalOpen` :4385; close :4077.
- **Composition**: does NOT own a composer — injects a `data-frm-open` reply CTA
  (:4203) that delegates to frm (§1.5), so its write path is frm's.
- **Lifecycle**: z **8800** (forums.css:4283); own history entry `{lgDm:1}`
  (:4801, popstate :4813–4829); scroll restore :4080.

### 1.9 fb-inline — in-thread quick reply (`.lg-fb-replyinput`)
- **Where**: `webroot/hub-polish.js` :759–930; `openReplyBox()` :874, submit :896–930.
- **Where it lives**: inside the lrs thread's FB-styled reply columns (≥641px path;
  mobile per-comment Reply taps route to lcp instead, :850).
- **Write**: **POSTs native BB REST** `/wp-json/buddyboss/v1/reply` (:915ff) — see G8.

### 1.10 Content-comment composers (articles/videos)
- **Where**: `#looth-content-sheet` (hub-polish.js :1970–2200, z 2147483550) wraps a
  same-origin **iframe** whose comment form is server-rendered (archive-poc
  comments.php) — a whole separate write path (comments DB, not bbPress) that only
  gets CSS polish from the sheet (:2061–2142). Out of scope for the mention pipeline
  today; in scope for COMPOSER-V2's single-composer goal.
- Sibling: `#looth-lp-sheet` (loothprint, z 2147483500) — same pattern.

### 1.11 post-edit — inline OP editor in the dmodal
- forums.js :3120–3310; PUT `/bb-mirror-api/v0/reply` (:3215); strips `<img>` (:3207).

---

## 2. Write-path matrix

| Surface | Create | Edit | Mint? | Bell? |
|---|---|---|---|---|
| lrs sheet reply | MIRROR POST (hub-polish.js:3685) | — | YES (reply.php:329 pre + :373 post-insert kses-off re-mint) | YES (reply.php:399 → `lg_notify_on_reply`) |
| lcp sheet reply/edit | MIRROR POST (:4171) | MIRROR PUT (:4105/:4141) | YES / YES (edit re-mints, reply.php:165,:237) | YES |
| New topic (ntm) | **NATIVE** `/wp-json/buddyboss/v1/topics` (forums.js:2359) | MIRROR PUT topic branch | YES via `bbp_new_topic` mu-plugin hook (bb-mirror-sync.php:148, post-insert kses-off) | YES (`lg_notify_on_topic`, notify-bridge.php:238) |
| **frm desktop reply** | **NATIVE** `/reply` (forums.js:2442,:2860) | MIRROR PUT (:2619/:2672) | **NO on create** | **NO on create** |
| **fic inline comment** | **NATIVE** `/reply` (forums.js:925,:1145) | — | **NO** | **NO** |
| **fb-inline thread reply** | **NATIVE** `/reply` (hub-polish.js:896–930) | — | **NO** | **NO** |
| rse / post-edit | — | MIRROR PUT (:1082/:3215) | YES | (edit does not re-notify — correct) |
| Content comments | archive comments DB (iframe) | — | n/a (separate system) | n/a |

**G8 — THE TOP FINDING. The native-create hole is still open on desktop/inline paths.**
Any surface that posts CREATE to native BB REST bypasses reply.php entirely: no mention
mint (BuddyBoss only links `@wp_nicename`, so renamed members' mentions die as plain
text) **and no bell at all** — not even reply-to-topic, because `lg_notify_on_reply` is
only invoked from reply.php:399. This campaign fixed the MOBILE sheets (e771ad5
retarget) and TOPIC create (`bbp_new_topic` hook, 364a070), but **frm (every desktop
reply, including from the dmodal), fic, and fb-inline still post native** — desktop
replies today mint nothing and ring nobody. Keeper Ruling A (2026-07-20) prescribed a
`bbp_new_reply` hook; the mentions lane implemented only the topic half, reasoning
"reply-create already routes reply.php" — true only for mobile. **Fix options:**
(a) `bbp_new_reply` mu-plugin hook mirroring the topic hook (mint is idempotent so
double-mint with reply.php is harmless; the BELL must be guarded — reply.php should set
a request flag before `rest_do_request` so the hook skips notifying when reply.php will);
(b) retarget frm/fic/fb-inline to the mirror API like the mobile sheets. COMPOSER-V2
picks (b) as the end state (§ plan), with (a) as the interim stopgap.

---

## 3. The mention pipeline (write→store→render→bell), for reference
1. Autocomplete inserts `@slug` (forums.js:493) from `/profile-api/v0/mention-suggest`.
2. Write side mints the canonical anchor
   `<a class="bp-suggestions-mention" data-lg-uuid="<uuid>" href="{{mention_user_id_N}}">@slug</a>`
   via `lg_bb_mirror_mint_mentions()` (`bb-mirror/api/v0/_mention-ingest.php:100`),
   resolving over loopback `mention-resolve`. BB REST sanitizes pre-mint anchors away,
   hence the **post-insert kses-off re-mint** pattern (reply.php:373–379; mu-plugin
   :160–171 for topics). Idempotent by design.
3. Render side resolves uuid → CURRENT slug (`_reply-render.php`), so renames never
   break mentions (uuid-anchored, keeper ruling 2026-07-19).
4. `notify-bridge.php` parses `{{mention_user_id_N}}` (:126) → profile-app bell
   (forum.mention rows; PG `profile_app.notifications`).

---

## 4. Cross-cutting mechanics (z-ladder, scroll-locks, focus)

### 4.1 The z-index ladder (measured)
| Layer | z | Cite |
|---|---|---|
| `.fcr-palette` (reaction pickers) | 20 (context-relative) | hub-polish.js:3137, forums.css:1491 |
| `#lg-dmodal` desktop modal | 8,800 | forums.css:4283 |
| `#lg-imglb` forums image lightbox | 9,100 | forums.css:4454 |
| `#looth-tabbar` | 2,147,481,200 | hub-polish.js (mobile chrome) |
| `#looth-pwa-banner` | 2,147,483,000 | " |
| `#looth-lp-sheet` | 2,147,483,500 | hub-polish.js:2368 |
| `#looth-rep-sheet` (lrs) | 2,147,483,520 | :3004 |
| `#looth-content-sheet` | 2,147,483,550 | :1993 |
| `#looth-comp-sheet` (lcp) | 2,147,483,560 | :3720 |
| `.lg-mnt` mention panel | 2,147,483,600 | forums.js:306 |
| `#lg-lb` mobile lightbox | 2,147,483,600 | hub-polish.js:4523 |
| `.lg-lightbox` (forums native) | 2,147,483,646 | forums.css:4892 |
| `#lg-mnt-debug` | 2,147,483,647 | hub-polish.js:5195 |

Defects: **COLLISION** `.lg-mnt` == `#lg-lb` at 2,147,483,600 (DOM order decides —
latent); the ladder is two disconnected regimes (8.8k vs 2.1B) that only work because
the surfaces rarely co-exist; every value is hand-picked with no registry.

### 4.2 Scroll-lock — three competing mechanisms
1. **iOS-correct**: the position:fixed observer (hub-polish.js:5150–5180), keyed on
   body classes `['ntm-active','hub-fmodal-lock','lg-sheet-lock']`. Used by: ntm, frm
   (both via `ntm-active`), lrs+lcp (via `lg-sheet-lock`, this campaign).
2. **iOS-broken**: bare `body.style.overflow='hidden'` with per-surface save vars —
   content sheet (`lgCsScroll` :2186/:2196), mobile lightbox (`lgScrollY`
   :4544/:4553), and lrs's belt-and-braces duplicate (:3631/:3174). **The content
   sheet and lightbox scroll-bleed on iOS today** (same defect class Ian reported on
   the reply stack — receipt R6, un-fixed on those two surfaces).
3. **touchmove preventDefault** islands: content-sheet overscroll (:2159), lrs drag
   (:3521–3531), lcp grab (:3827), lightbox pan (:4572), global pinch (:5140).

### 4.3 Focus/keyboard
visualViewport listeners: content sheet `setVVH` (:1147), lrs composer lift
(:3596–3598), lcp `lcpKb` (:3893–3895). Focus-on-open: lcp `ta.focus` (:4067,
synchronous within the tap so iOS shows the keyboard), fb-inline (:890). Blur-to-
dismiss keyboard: lcp non-input chrome tap (:3814–3820). **Esc closes**: content
sheet (:2201), lightbox (:4628), dmodal (:4072) — **not lrs/lcp** (back-gesture only).

### 4.4 History/popstate
Entries: content `{lgCs:1}` (:2187), lrs `{lgRs:1,lgTopic}` (:3650), dmodal `{lgDm:1}`
(forums.js:4801). Arbitration is by mutual sniffing (:3195–3220, forums.js:4813–4829)
including a check of `window.__lgLbPop` that **nothing sets anymore** (the lightbox
stopped pushing history 2026-06-26 — orphaned guard, G11).

### 4.5 Lifecycle duplication count
Eight independently-implemented open/close lifecycles (lrs, lcp, content, lp-sheet,
mobile lightbox in hub-polish.js; ntm, frm, dmodal + forums lightbox in forums.js),
four backdrop implementations, three scroll-lock idioms, two history schemes.
**This is G7, the root cause**: every invariant is convention, re-implemented per
surface, and every mobile bug of this campaign was a pairwise interaction.

---

## 5. iOS receipts — every real-device failure of this campaign (2026-07-23/24)

Each of these was **green in headless-Chromium emulation and failed on Ian's iPhone**.
They are the reason COMPOSER-V2-PLAN's verification tier is WebKit-first.

- **R1 — plain `<div>` overlays drop taps without `cursor:pointer`.** The `.lcp-back`
  backdrop tap silently no-oped → the translucent composer stayed `is-open` as an
  invisible full-screen tap-eater ("hidden modal"). Fix: `cursor:pointer` on every
  tappable overlay (hub-polish.js:3730). Refs: react-modal#333; WebKit bug 154807.
- **R2 — `inert` is not reliably clearable in practice.** Only `<dialog>.showModal()`
  escapes an inert ancestor; a half-run clear path leaves a dead layer. Fix: class-based
  `pointer-events:none` (`lg-sheet-behind`) + `lgSetBehind()` (:3928) + idempotent-open
  scrub (:3944) + root invariant (§6). Refs: MDN inert; caniuse (iOS 15.5+).
- **R3 — a `transform`ed ancestor traps `position:fixed` descendants** (containing
  block + new stacking context) — the keyboard-lift `translateY` left un-reset broke
  the reopened panel. Fix: reset transform on close. Refs: MDN position; Meyer 2011.
- **R4 — iOS event order is `touchend → mousedown → blur → click`:** blur fires
  before the synthetic mousedown, so mousedown-pick races the focusout dismiss.
  AND the overcorrection receipt: **touchSTART-pick kills scrolling** (any drag
  insta-picks — RELOOK-3B). Correct: pick on touchEND with <10px movement, <700ms.
- **R5 — keyboard compression collapses fixed-position height math.** The panel's
  `maxHeight` computed against a keyboardless emulator viewport → one-row list on
  device. Correct: inline flex child (`flex:1; min-height:0`) inside the sheet body,
  never viewport arithmetic.
- **R6 — `body{overflow:hidden}` is ignored by iOS WebKit** (scroll-bleed + sheet
  dragging off-screen). Correct: the position:fixed observer (§4).
- **R7 — "double modal" is a DESIGN read, not just a stacking bug.** The round-3
  full-bleed card left a 10% peek; the thread sheet's rounded top edge + grab pill
  above the composer's own edge + pill read as two stacked modals. Layer-audit
  (run-layers/) enumerated all 9 fixed/abs layers — no ghost; the peek WAS the
  double modal. Lesson: audit the visual read, not only interactivity.
- **R8 — emulation ≠ device, structurally.** Chromium honors overflow:hidden, has no
  real keyboard, no Safari autofill strip, different event order. Any mobile-sheet
  change verified only in Chromium emulation is UNVERIFIED on iOS.

**On-phone diagnostics:** `?lgdebug=1` (hub-polish.js:5194ff) renders a
pointer-events:none overlay reporting sheets' open/display/pe/inert, behind/inert
counts, body lock, dropdown state, and `elementFromPoint`@center — DOM truth from a
real device without devtools.

---

## 6. Teardown invariants as they exist today (candidate 334ffa4)
1. **Lock**: `lg-sheet-lock` ⇔ (lrs open ∨ lcp open) — `lgSyncSheetLock()` :3908,
   called from every open/close.
2. **Behind**: lrs is `lg-sheet-behind` ONLY while lcp is open — enforced at the ROOT
   in `lgSyncSheetLock` (:3919–3926), so no dismiss path can leave the thread inert
   (reactions-dead insurance).
3. **Idempotent open**: `lgScrubSheetState()` (:3944) force-clears behind/aria/inert/
   transform before applying fresh state on BOTH sheets' opens.
4. These are **convention, not architecture** — each new surface must remember to call
   them. COMPOSER-V2 makes them code (a single lifecycle manager owns them).

---

## 7. GAPS register (candidate 334ffa4)

| # | Gap | Evidence | Severity |
|---|---|---|---|
| **G8** | **Desktop/inline reply CREATE bypasses mint + bell entirely** (frm, fic, fb-inline post native BB REST; `lg_notify_on_reply` fires only from reply.php) — desktop replies mint nothing and ring nobody | §2 matrix; forums.js:2860,:1145; hub-polish.js:896–930 | **CRITICAL (functional)** |
| G1 | Dropdown list-scroll insta-picks (touchstart-pick) | forums.js:359; KNOWN-FAIL test in e2e-webkit | HIGH (mobile UX) |
| G2 | Reply-intent auto-opens composer OVER thread → first reaction tap dismisses composer instead ("two-tap" feel; suspected source of Ian's "reactions dead") | hub-polish.js:436→:3655 | MED (needs Ian ruling: keep auto-open?) |
| G9 | Content sheet + mobile lightbox still use iOS-broken `overflow:hidden`-only locks — they scroll-bleed on iPhone today (same class as R6) | hub-polish.js:2186,:4544 | MED |
| G10 | z-index collision: `.lg-mnt` == `#lg-lb` at 2147483600; no z registry | forums.js:306; hub-polish.js:4523 | LOW (latent) |
| G3 | Moderation 202 treated as plain success on mobile sheets — "awaiting review" never shown | lrsSubmit/lcpSubmit read only `res.ok` | LOW |
| G4 | `--lg-panel-*` theme tokens not loaded on /hub/ — every hub component needs literal dark overrides | forums.js dark block; site-header.php:139 | MED (theming debt) |
| G5 | ~~Mobile inner-reply reactions unwired~~ — **VERIFIED NOT A GAP**: replies render flat (nesting is metadata), `.fcr` bars render + enhance for ALL stubs (_reply-render.php:569; hub-polish.js:750,:789; forums.js:4135). Ian's "reactions dead" is explained by R1/G2, not missing wiring | agent-verified | CLOSED |
| G6 | History/popstate three-way arbitration is guess-based | hub-polish.js:3195–3220 | MED (latent) |
| G11 | `window.__lgLbPop` guard checked but never set (lightbox stopped pushing history 2026-06-26) — dead defensive code that implies a contract that no longer exists | hub-polish.js:3199; forums.js:4819 | LOW |
| G12 | No Esc-close on lrs/lcp (hardware-keyboard mobile + accessibility) | §4.3 | LOW |
| G7 | ~8 independent sheet lifecycles, no shared manager — every invariant is convention | §1 + §4.5 | **ROOT CAUSE** |

---

*Verification appendix: `tools/e2e-webkit/` — Playwright WebKit harness (iPhone-13
profile, touch, real public URL) wiring this audit's receipts into executable
contracts; see COMPOSER-V2-PLAN §5 for the verification ladder.*
