# REPLY-SURFACES-AUDIT — every composer/thread surface, from code truth

*mentions lane, 2026-07-24. Written after the mobile-mentions campaign (branch
`username-mentions-finish`) surfaced three iPhone-only failures that a green
headless-Chromium harness never saw. Citations were `file:line` at branch tip 334ffa4
(= a30ebfd ship candidate + origin/main). Companion: COMPOSER-V2-PLAN.md.*

> **RE-VERIFIED 2026-07-26 at main 432f80b (composer-v2 phase 3 lane).** Every §1/§2
> citation was re-checked against the tree; phases 0–2 moved most of them. All line
> numbers below are now 432f80b. Four substantive corrections, not just drift:
>
> 1. **Two native-create surfaces were MISSING from the inventory and the §2 matrix** —
>    `fc-composer` (§1.12) and the single-topic page reply form (§1.13). Both POST
>    native BB REST. G8's blast radius was **five** create surfaces, not three.
> 2. **`fic` and `rse` are dev-only** — both live inside the `?proto=cards` /
>    `localStorage.lg_card_proto` block (forums.js:950–1240, gate at :956). §1.6/§1.7
>    read as live surfaces; they are not reachable by a normal user.
> 3. **G8 is currently closed BY STOPGAP** — the phase-0 `bbp_new_reply` mint+bell hook
>    shipped (platform/mu-plugins/bb-mirror-sync.php:203–230) with the
>    `$GLOBALS['lg_bb_mirror_reply_owned']` double-fire guard set at reply.php:333.
>    Native creates mint and ring today. Phase 3's job is to make that hook *unnecessary*.
> 4. **G1 is FIXED** — the mention panel now records touchstart and picks on touchend
>    (<10px/<700ms) at forums.js:364/373. §1.3's "KNOWN DEFECT" no longer holds.
>
> Also stale and corrected in place: §6's three convention helpers are DELETED (phase 1
> made them structural in `LgSheets`, hub-polish.js:3020); the lrs/lcp history dance of
> §1.1/§1.2 is now manager-owned. Still true as written: G9, G10, G11 (`__lgLbPop` is
> read at hub-polish.js:3145 and :5652 and set nowhere), G12.

**The one-sentence diagnosis:** we have ~8 independent composer/sheet implementations,
each with its own backdrop, its own open/close code, its own scroll-lock idea and its
own z-index, sharing nothing but the document they fight over; every mobile bug of the
last week is a pairwise interaction between two of them.

---

## 1. Surface inventory

### 1.1 lrs — mobile replies/discussion sheet (`#looth-rep-sheet`)
- **Where**: `webroot/hub-polish.js` — build+open `openRepliesSheet()` :3615, close
  `lrsClose()` :3340, submit `lrsSubmit()` :3824.
- **Entry**: mobile (≤640px) Reply action `.lg-act-replies` (built :394, wired :419,
  routed :430ff), "View N replies" expanders (:5041ff), `?topic=` deep links via
  `window.lgOpenTopicMobile` (:3822), read-more intents.
- **Write path**: `lrsSubmit()` POSTs **`/bb-mirror-api/v0/reply`** (:3838) — the owned
  mirror endpoint (mints mentions + rings bells, §3).
- **Lifecycle**: full-viewport `position:fixed` at **z 2147483520** (:3164). **Phase-1
  correction:** open/close no longer set locks or behind-state themselves — `lrsClose()`
  delegates to `window.LgSheets.close('lrs', …)` (:3341) and open to
  `LgSheets.open('lrs', {url})` (:3805). Lock, shared backdrop (:3037, z derived :3073),
  behind-state and teardown are all manager-owned.
- **History**: no longer a three-way dance — `LgSheets` is the single history owner and
  the only popstate listener; lrs declares its deep-link URL (`sh.__lgTopicUrl`, :3803)
  and the manager maps stack ↔ history with a self-pop guard (:3025–3029). The §4f
  `?topic=` contract is unchanged. *(G6 closed for lrs/lcp; `__lgLbPop` — G11 — is still
  read at :3145/:5652 and set nowhere.)*

### 1.2 lcp — THE composer (`#looth-comp-sheet`) — **now composer v2** (phase 2)
- **Phase-2 correction:** this is no longer "the mobile composer sheet". Phase 2
  (cea6ab3) replaced lcp's internals with composer v2 — Quill 2, full-height FB sheet,
  attachment strip, tag picker, link panel. The sheet **id `lcp` was kept** for stack
  semantics only (:3865). The `lgc*` family IS the shared composer component; it is what
  phase 3 gives a desktop modal skin.
- **Where**: `webroot/hub-polish.js` — build `ensureCompSheet()` :3932, open
  `openComposerSheet()` :4806, close `closeComposerSheet()` :4894, submit
  `lcpSubmit()` :4922, Quill init `lgcInitQuill()` :4747, mention insert
  `lgcInsertMention()` :4387, attachment strip :4208/:4253/:4265, edit-media load
  `lgcLoadEditMedia()` :4875, dock `lgcDock()` :4195.
- **Open API (the phase-3 seam)** — `openComposerSheet({tid, fid, replyTo, replyToName,
  title, editReplyId, editTopicId, bodyText, focus})` (:4806–4870). Modes resolve at
  :4841ff: topic-edit → reply-edit → create(+draft restore). **This already covers every
  mode frm implements**, which is why phase 3 is skin + delete rather than new build.
- **Entry**: Reply-intent tap (:430ff → `openRepliesSheet(card,{toReplies:true,
  focus:true})` → :3805ff), the lrs "Write a comment…" pill, per-reply Reply links, and
  EDIT reuse (same sheet is reply-editor and topic/OP-editor).
- **Write path**: create → **`/bb-mirror-api/v0/reply`** POST (:5007); reply edit → same
  endpoint PUT (:4976); topic edit → same endpoint PUT w/ `topic_id` (:4940).
- **Lifecycle**: z **2147483560** (:3941). **Manager-owned** — registered at :4899 with
  `escClose:true` (G12 closed for lcp), its own history entry `{lgLcp:1}` (:4906) so
  phone-back closes composer-then-thread, and an `onClose` (:4907) that owns *only*
  content concerns: draft preservation on accidental dismiss vs. clear on `post`/explicit
  ✕. No lock, backdrop or behind-state code remains in the surface.
- **Sibling sheets on the same manager** (built phase 2, absent from the original audit):
  tag picker `#looth-tag-sheet` z 2147483570 (:4053, registered :4508) and link panel
  `#looth-link-sheet` z 2147483580 (:4090, registered :4684). Both are composer-owned
  second/third stack layers and are **shared components phase 3 must not fork**.

### 1.2a Deleted by phase 1 — `lgSyncSheetLock` / `lgSetBehind` / `lgScrubSheetState`
The three convention helpers this audit's §6 enumerated are **gone** (tombstone comment
at hub-polish.js:4745). Their invariants are structural in `LgSheets` (:3020). §6 is
retained below as history; read it as "what phase 1 replaced", not as current state.

### 1.3 The mention autocomplete panel (`.lg-mnt`)
- **Where**: `bb-mirror/web/forums.js` — IIFE `lgMentionAutocomplete()` :286, panel CSS
  :313ff, panel node :351, editor matcher `editorOf()` :390 (covers `.ql-editor`,
  `textarea.rse-input/.fic-input/.lg-fb-replyinput/.lcp-input`, `#lrs-comp-input`,
  `#frm-content`, `#ntm-content`), suggest fetch :505 → `/profile-api/v0/mention-suggest`,
  insert `pick()` :520.
- **One panel, every composer**: a single body-appended `position:fixed` node at
  **z 2147483600** (:313) serves all surfaces; mobile gets the FB-style `.lg-mnt--sheet`
  styling (name-first rows, 46px avatars, helper caption).
- ~~**KNOWN DEFECT**: pick fires on `touchstart`~~ — **G1 FIXED (re-verified 2026-07-26).**
  The panel now records touchstart (:364, passive) and picks on **touchend** only when
  the gesture stayed <10px / <700ms (:373) — receipt R4's correct shape. A scroll attempt
  inside the list no longer insta-picks. The KNOWN-FAIL test in
  `tools/e2e-webkit/tests/reply-stack.spec.js` should now be a PASS test.
- **Phase-3 note**: `editorOf()` is the shared engine's surface whitelist. Every selector
  phase 3 deletes (`.fic-input`, `.lg-fb-replyinput`) must come out of this list in the
  same commit, or the mention engine keeps advertising editors that no longer exist.

### 1.4 ntm — new-topic modal (`#ntm-overlay`)
- **Where**: `bb-mirror/web/forums.js` — form :1702, open `ntmShowOverlay()` :1815, close
  `ntmHideOverlay()` :1876; desktop ≥641px reshapes into a step wizard (`ntmWiz` :1724).
- **Write**: create POSTs **native BB REST** `/wp-json/buddyboss/v1/topics` (:1722
  `ntmRestBase` default, :2404 fetch). Edit routes to the mirror (reply.php topic PUT).
  Anon: `_lg_anon` still rides topic create (:2401) — **topics keep anon; replies do not.**
- **Lifecycle**: `ntm-active` body class (:1817/:1878) → the position:fixed lock
  observer (§4) — the ONE surface family that was iOS-correct from the start.
- **State machine**: `ntmAuthState` idle|loading|anon|authed (:1716); Quill lazy-init.
- **Scope**: phase **4**, not phase 3.

### 1.5 frm — desktop reply/edit modal (`#frm-overlay`)
- **Where**: `bb-mirror/web/forums.js` — form :2487, open `frmOpen()` :2612, close
  `frmClose()` :2648, submit handler :2803.
- **Entry**: delegate at :2792 on `.feed-card__reply-cta[data-frm-open]`,
  `.reply-stub__reply`, **and `.fc-composer__rich`** (the pencil in §1.12's composer) —
  including the reply CTA the desktop dmodal injects (:4377).
- **Write**: **create POSTs native BB REST** `/reply` (:2487 `frmRestBase` default, :2905
  fetch) — see G8. Reply-edit PUT `/bb-mirror-api/v0/reply` (:2867); topic-edit PUT
  (:2825) + `topic-media` sync (:2841).
- **Dead code found 2026-07-26**: :2902–2904 still builds `_lg_anon` from
  `#frm-anon-check`, an element that **no longer exists** — the reply anon toggle was
  removed 2026-06-10 (`_chrome.php`:415–419) and reply.php refuses it server-side
  (:367). Retargeting frm to the mirror therefore loses **no** anon behaviour.
- **Lifecycle**: reuses the `ntm-active` body class (:2644/:2650) → iOS-correct lock.

### 1.6 rse — inline reply edit (`.rse-*`) — **DEV-ONLY**
- **Where**: forums.js — editor scaffold :1099, PUT `/bb-mirror-api/v0/reply` :1127.
  Inline (no modal/lock). Preserves inline `<img>` on edit unlike create composers.
- **Correction (2026-07-26)**: this sits **inside the `?proto=cards` block**
  (forums.js:950, gate `if (!protoOn || !feed) return;` :956, block ends :1240). It is
  reachable only with `localStorage.lg_card_proto === '1'`. The original audit presented
  it as a live surface; it is not.

### 1.7 fic — inline card quick-reply (`.fic-*`) — **DEV-ONLY**
- **Where**: forums.js — `protoMountComposer()` :1167, mounted on card expand :1237.
- **Write**: **POSTs native BB REST** `/reply` (:970 `protoReplyBase` default, :1190
  fetch) — see G8.
- **Correction (2026-07-26)**: same `?proto=cards` gate as §1.6. Not user-reachable.

### 1.8 lg-dmodal — desktop discussion modal (`#lg-dmodal`)
- **Where**: forums.js IIFE :4015–4560; `window.lgDmodalOpen` :4559; close :4251.
- **Composition**: does NOT own a composer — injects a `data-frm-open` reply CTA
  (:4377) that delegates to frm (§1.5), so its write path is frm's. **This is the
  desktop-skin seam for phase 3: repoint that one CTA and every dmodal reply moves.**
- **Lifecycle**: z **8800** (forums.css); own history entry `{lgDm:1}` (§4f block
  :4816–5113); reply edit/delete via the owned endpoint (:4162/:4187).

### 1.9 fb-inline — in-thread quick reply (`.lg-fb-replyinput`)
- **Where**: `webroot/hub-polish.js` — `openReplyBox()` :874, submit ~:908–930.
- **Where it lives**: inside the lrs thread's FB-styled reply columns. Routing at :847 —
  **≤640px goes to lcp/composer v2; >640px falls to `openReplyBox`** (and :863 is the
  no-sheet/no-card last resort). So this is the *desktop* in-thread reply box.
- **Write**: **POSTs native BB REST** `/wp-json/buddyboss/v1/reply` (:925) — see G8.

### 1.12 fc-composer — persistent card reply composer — **MISSING FROM THE ORIGINAL AUDIT**
- **Where**: forums.js IIFE :3912–4003; `REPLY_BASE` :3914; markup server-rendered in
  `bb-mirror/web/forums/_feed.php`:1643–1659.
- **What it is**: the always-on single-line "Add a reply…" input under every feed card
  (the "reply is lost" fix). **Authed only, NOT proto-gated**, desktop-only by CSS
  (`display:none` ≤640) — i.e. a fully live desktop create surface.
- **Write**: **POSTs native BB REST** `/reply` (:3986) — **G8, unlisted until now.**
- **Two doors**: the plain input posts native directly; the `.fc-composer__rich` pencil
  (`_feed.php`:1653) delegates to **frm** via the :2792 delegate. Phase 3 must claim
  BOTH or the fast path keeps bypassing the composer.

### 1.13 Single-topic page reply form (`.reply-form-wrap`) — **MISSING FROM THE ORIGINAL AUDIT**
- **Where**: forums.js §3b :3021–3377 (inside the file's top-level IIFE); markup
  `bb-mirror/web/forums/_single-topic.php`:543.
- **What it is**: the server-rendered reply form on the canonical topic page — its own
  Quill instance (:3056), its own `lgComposerTray` photo tray (:3044), reply-to threading
  (`parent_reply_id` :3033).
- **Write**: **POSTs native BB REST** `/reply` (:3027 `restBase` from
  `wrap.dataset.bbRestBase`, :3354 fetch) — **G8, unlisted until now.**
- **STRUCTURAL CONSTRAINT for phase 3**: composer v2 lives in `hub-polish.js`, which is
  **path-gated to `/hub`** (:46 `onHubPath()`, injected site-wide via `/pwa.js`). This
  page is not under `/hub`, so composer v2 is **not loadable here** without relocating
  the module out of hub-polish.js. Retargeting its fetch to the mirror API closes G8 on
  this surface without the skin; full v2 conversion is a larger, separable job.

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

**Re-derived 2026-07-26 at 432f80b.** Mint/bell columns now reflect the shipped phase-0
stopgap: the `bbp_new_reply` hook (platform/mu-plugins/bb-mirror-sync.php:203) gives
*every* reply create path mint + bell, and stands down when reply.php owns the write
(`$GLOBALS['lg_bb_mirror_reply_owned']`, reply.php:333).

| Surface | Live? | Create | Edit | Mint? | Bell? |
|---|---|---|---|---|---|
| lrs sheet reply | mobile | MIRROR POST (hub-polish.js:3838) | — | YES (reply.php:334 pre + :374 post-insert kses-off re-mint) | YES (reply.php:404 → `lg_notify_on_reply`) |
| lcp / composer v2 | mobile | MIRROR POST (:5007) | MIRROR PUT (:4940/:4976) | YES / YES (edit re-mints) | YES |
| New topic (ntm) | both | **NATIVE** `/topics` (forums.js:2404) | MIRROR PUT topic branch | YES via `bbp_new_topic` hook (bb-mirror-sync.php:128) | YES (`lg_notify_on_topic`) |
| **frm desktop reply** | desktop | **NATIVE** `/reply` (forums.js:2487,:2905) | MIRROR PUT (:2867/:2825) | via STOPGAP hook | via STOPGAP hook |
| **fc-composer** *(new)* | desktop | **NATIVE** `/reply` (forums.js:3914,:3986) | — | via STOPGAP hook | via STOPGAP hook |
| **fb-inline thread reply** | desktop | **NATIVE** `/reply` (hub-polish.js:925) | — | via STOPGAP hook | via STOPGAP hook |
| **single-topic form** *(new)* | topic page | **NATIVE** `/reply` (forums.js:3027,:3354) | — | via STOPGAP hook | via STOPGAP hook |
| **fic inline comment** | **dev-only** (`?proto=cards`) | **NATIVE** `/reply` (forums.js:970,:1190) | — | via STOPGAP hook | via STOPGAP hook |
| rse | **dev-only** (`?proto=cards`) | — | MIRROR PUT (:1127) | YES | (edit does not re-notify — correct) |
| post-edit | topic page | — | MIRROR PUT (:3260) | YES | (edit does not re-notify — correct) |
| Content comments | both | archive comments DB (iframe) | — | n/a (separate system) | n/a |

> **G8 STATUS 2026-07-26 — closed by stopgap, not by architecture.** The paragraph below
> describes the hole as it stood on 2026-07-24. Since then the phase-0 `bbp_new_reply`
> mu-plugin hook shipped, so desktop replies **do** mint and **do** ring today. What
> remains open is the *architecture*: five surfaces still POST native BB REST, so the
> correctness of every desktop reply depends on a mu-plugin hook rather than on there
> being one write path. Phase 3 retires the dependency. Note also that the blast radius
> was **understated** — §1.12 `fc-composer` and §1.13 the single-topic form were never
> inventoried, and both post native.

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

> **Re-verified 2026-07-26 — partially superseded by phase 1.** Corrections:
> **§4.1** add `#looth-tag-sheet` z 2,147,483,570 (hub-polish.js:4053) and
> `#looth-link-sheet` z 2,147,483,580 (:4090), both phase-2 composer layers; the lrs/lcp
> ladder is now mediated by the manager's shared backdrop, which derives its z from the
> top sheet (:3073) instead of a hand-picked number. The `.lg-mnt` == `#lg-lb` collision
> (G10) still stands at :313 / :5356.
> **§4.2** mechanism 1 (position:fixed observer) now covers lrs/lcp/tag/link via
> `LgSheets`; the lrs belt-and-braces `overflow:hidden` duplicate is **gone**. Mechanism
> 2 survives **only** on the content sheet (:2186/:2196) and mobile lightbox
> (:5377/:5386) — G9 unchanged, still iOS-broken on those two.
> **§4.3** Esc is now free for every registered sheet (`escClose`, :3125) — **G12 closed
> for lrs/lcp/tag/link**, still open on any surface not yet on the manager.
> **§4.4** lrs/lcp no longer arbitrate: `LgSheets` is the sole popstate owner. `__lgLbPop`
> (G11) remains read at :3145/:5652 and set nowhere.


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

## 6. Teardown invariants — HISTORICAL (candidate 334ffa4; superseded by phase 1)

> **All three helpers below were DELETED by phase 1** (tombstone: hub-polish.js:4745).
> `LgSheets` (hub-polish.js:3020) now owns the stack, the single shared backdrop
> (:3037), the scroll-lock, behind-state, focus restore, Esc (`escClose`) and history
> (single popstate owner + self-pop guard :3025). Item 4's prediction came true — read
> this section as the "before" picture.

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
| **G8** | ~~Desktop/inline reply CREATE bypasses mint + bell entirely~~ → **DOWNGRADED 2026-07-26**: functionally closed by the phase-0 `bbp_new_reply` stopgap hook (bb-mirror-sync.php:203, guard reply.php:333). **Architecturally open**: FIVE surfaces still POST native — frm (forums.js:2905), fc-composer (:3986), single-topic form (:3354), fb-inline (hub-polish.js:925), fic (:1190, dev-only). Correctness rides a hook, not a single write path | §2 matrix | **HIGH (architectural)** — was CRITICAL/functional |
| G1 | ~~Dropdown list-scroll insta-picks (touchstart-pick)~~ — **FIXED**: touchstart recorded (forums.js:364), pick on touchend <10px/<700ms (:373) | re-verified 2026-07-26 | CLOSED |
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

---

## Addendum (2026-07-26): the invisible-door composition bug — a G7 specimen in the wild

Ian: "cannot react to replies." Diagnosis: TWO individually-approved designs composed
into an invisible door. (1) app-mobile-fixes.js:53 hides `.fcr-add` on all mobile
(Buck 2026-06-08 — press-and-hold the LIKE opens the picker; the add-button is
redundant *wherever a Like exists*). (2) fbStyleReply (hub-polish.js, Ian 2026-06-25)
replaces the fake Like with the real `.fcr` bar inside the lrs sheet. Composition: in
the sheet there is no Like, and on a ZERO-reaction reply the real bar's only child is
the hidden add-button → the bar computes 0×0 — nothing visible, tappable, or
long-pressable. Desktop unaffected (verified working end-to-end). Fix: sheet-scoped
`display:inline-flex!important` on `#looth-rep-sheet .fcr-add` — the add-button IS the
affordance where the Like doesn't exist; Buck's hide stands everywhere else; the
own-reply inline-important hide survives by cascade.

LESSON (the audit's thesis, again, empirically): neither change was wrong; the SURFACE
COMPOSITION was unowned. Each design assumed a context invariant ("a Like exists" /
"the bar is visible") that the other silently broke. Until composer-v2 gives these
surfaces one owner, every cross-surface style/behavior change needs a "who assumes
this element exists/shows?" sweep — grep the OTHER surface files for the class you
are hiding or replacing.
