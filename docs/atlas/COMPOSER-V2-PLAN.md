# COMPOSER-V2-PLAN — one architecture that works

*mentions lane, 2026-07-24. Companion to REPLY-SURFACES-AUDIT.md (read it first — the
gaps register G1–G12 is the requirements list for this plan). Judged by: can Ian read
this and believe it.*

## 0. Why v2 and not another patch round

The audit's numbers: **8 independent sheet lifecycles, 4 backdrop implementations,
3 scroll-lock idioms, 2 history schemes, ~7 composer variants, 2 write paths** — and
a week of phone-only failures that were all pairwise interactions between surfaces
that don't know about each other. We patched the mobile pair to green (candidate
334ffa4); every remaining gap (G8 desktop mint hole, G9 content-sheet scroll-bleed,
G2 two-tap, G6/G11 history guesswork) is another instance of the same root cause, G7.
Patching converges on this plan one incident at a time; building it converges on
purpose.

## 1. Architecture

### 1.1 `lg-sheet.js` — ONE sheet-lifecycle manager (invariants as code)

A single module owning every overlay surface. Public shape (final API TBD in review):

```js
const sheet = LgSheets.register('composer', { level: 'sheet' /* |modal|popover|system */ });
sheet.open({ onClose, restoreFocusTo });   // pushes onto THE stack
sheet.close();                              // pops; manager runs teardown
```

The manager — not the surfaces — owns:
- **The stack.** One array. Top sheet is interactive; every sheet below is
  automatically `pointer-events:none` + `aria-hidden` (the audit's behind-state
  invariant, enforced structurally instead of by `lgSyncSheetLock` convention).
  Backdrop is ONE shared element the manager positions under the top sheet — no
  per-surface backdrop divs, and it always carries `cursor:pointer` (receipt R1).
- **The z-registry.** Levels, not numbers: `system > popover > sheet > modal > chrome`.
  Within a level, stack order decides. Kills G10 and the two-regime ladder.
- **The scroll-lock.** Exactly one mechanism: the proven position:fixed +
  `top:-scrollY` + restore observer, refcounted by stack depth. `overflow:hidden`
  is deleted everywhere (fixes G9 for content sheet + lightbox by migration).
- **Teardown invariants.** On ANY pop (backdrop tap, swipe, Esc, back-gesture,
  programmatic): clear behind-state below, clear transforms, restore focus to the
  opener, sync the lock. `open()` starts from a scrubbed state (idempotent-open is
  the default, not a patch). Esc handling comes free for every sheet (G12).
- **History.** ONE owner: the manager maps stack state ↔ history entries and is the
  only `popstate` listener. Surfaces declare `deepLink: '?topic=…'` and never touch
  `history` themselves (kills G6, deletes the orphaned `__lgLbPop` guard, G11).

Size estimate: ~250 lines + ~40/surface adapter. Net LOC negative by a wide margin.

### 1.2 `lg-composer.js` — ONE composer, four modes

A single component with modes `reply | topic | edit-reply | edit-topic` (and later
`comment` for the archive comments system), replacing lcp, frm, ntm's write half,
rse, post-edit, fic, and fb-inline. One implementation of: text input, title row
(topic modes), photo tray, mention autocomplete mount, submit/moderation states,
draft preservation across accidental dismiss.

- **One write path: the mirror API only.** `POST/PUT /bb-mirror-api/v0/reply` (+ a
  thin `POST /bb-mirror-api/v0/topic` create endpoint to be added, wrapping the same
  in-process `rest_do_request` + post-insert kses-off re-mint + notify pattern
  reply.php already proves). Native BB REST is never called from a composer again —
  **G8 dies structurally**, and the mint/bell/moderation/throttle logic lives in
  exactly one server file.
  - *Interim stopgap before v2 lands (can ship independently):* a `bbp_new_reply`
    mu-plugin hook mirroring the proven `bbp_new_topic` one — mint idempotently;
    ring the bell only when reply.php didn't handle the request (reply.php sets a
    flag constant before `rest_do_request`; the hook checks it). Small, testable,
    closes the CRITICAL functional hole while v2 is built.
- **Mention autocomplete** stays the single `.lg-mnt` engine; on mobile it renders
  inline in the composer body as a flex child (receipt R5 — no viewport arithmetic),
  with tap-vs-scroll picking (touchend <10px/<700ms — R4) and the FB row design Ian
  already approved (name-first, 46px avatars, caption).

### 1.3 Mobile design — FB full-height, MOCK-FIRST

The round-3 lesson (receipt R7): geometry is a product decision, previs it like the
Local Looths mockups. Before any code: static HTML previs at true 390×844 (light+dark,
keyboard-up and keyboard-down states, mention-list open/closed, photo-tray states),
committed under `footer-mockups/composer-v2/` and screenshotted for a board post.
**Ian approves pictures, then we build.** Design constraints already ruled: full
height to the keyboard top (zero-gap dock, no autofill stage), header = identity left
+ primary Post right + explicit X (no ambiguous peek — the "double modal" cut),
mention list inline in the midsection, slim action row above the keyboard, autofill
hardening per receipt list (and the honest note: Safari's QuickType strip is OS chrome
we cannot remove).

## 2. What does NOT change

The mention pipeline (mint → uuid-anchored storage → current-slug render → bell) is
proven and untouched. The lrs thread READ surface stays (it's a renderer, not a
composer). Desktop layouts keep their current look; desktop adopts the same manager +
composer with a modal skin. Handles remain read-only (Ian ruling 2026-07-19).

## 3. Migration order (strangler, one surface per window)

| Phase | Converts | Why this order | Exit test |
|---|---|---|---|
| 0 | Stopgap `bbp_new_reply` hook (G8) + tap-vs-scroll cherry-pick (G1) onto the candidate | CRITICAL functional hole + known UX defect; both small | WebKit suite + desktop-create mint/bell probe |
| 1 | `lg-sheet.js` manager; adapt **lrs+lcp** to it (highest-traffic, best-tested pair — the WebKit suite already covers their contracts) | prove the manager where the receipts are | full e2e-webkit green incl. KNOWN-FAIL flipping |
| 2 | Composer v2 previs → Ian approval → build reply mode; replace **lcp** | the FB design lands where Ian wants it | previs sign-off, then phone pass |
| 3 | Replace **frm + fic + fb-inline** with composer v2 (desktop modal skin); delete native-REST create paths | kills G8 structurally | desktop reply mint+bell e2e |
| 4 | **ntm** topic mode + mirror topic-create endpoint | topics unified | topic mint+bell e2e |
| 5 | Migrate **content sheet + lightbox + lp-sheet** onto the manager (scroll-lock + z + history only) | fixes G9 with zero UX change | iOS scroll-bleed spot-check |
| 6 | rse/post-edit → composer edit modes; delete dead code (`__lgLbPop`, per-surface locks) | cleanup | grep-level deletion audit |

Each phase is a normal lane window: branch, serve-flip verify, keeper review, Ian
gate where UX-visible (phases 2–4).

## 4. Risk register

| Risk | Mitigation |
|---|---|
| The manager becomes a 9th lifecycle instead of THE lifecycle | Phase-1 exit = lrs+lcp fully adapted, their old lifecycle code DELETED same commit |
| Quill/BB-media coupling in frm/ntm resists the unified composer | Composer keeps a per-mode "editor engine" seam (plain textarea on mobile, Quill on desktop) — already today's split |
| History-owner refactor breaks deep links (`?topic=` contract with forums.js §4f) | The §4f contract is the spec; e2e test cold-load + forward-nav + back before/after |
| Mirror topic-create endpoint drifts from BB behavior (anon flag, media, throttle) | Wrap `rest_do_request` in-process exactly like reply.php; reuse its patterns verbatim |
| WebKit harness ≠ iOS still bites (R8) | Harness is the gate for regressions only; Ian's phone remains the product gate; GH-Actions macOS iOS-Simulator tier (below) when CI budget allows |
| RAM on dev2 (3.8GB) vs Playwright | workers:1, one browser, kill between phases (box law) |

## 5. Verification ladder

1. **`tools/e2e-webkit/` (BUILT, this commit)** — Playwright **WebKit** (same engine
   family as iOS Safari), iPhone-13 profile, touch, real public URL + real auth
   cookies. The campaign's receipts are encoded as executable contracts in
   `tests/reply-stack.spec.js`; the a30ebfd candidate's known defect (G1) is a
   deliberate KNOWN-FAIL test. Run: `cd tools/e2e-webkit && npm test`.
   Honest current status vs candidate 334ffa4: see `results.json` — recorded in the
   board post, not hand-waved here.
2. **Ian's phone** — the product gate. Nothing UX-visible ships without it (R8).
3. **Next tier (not built)**: GH-Actions `macos-14` runner + `xcrun simctl` iOS
   Simulator running real Mobile Safari via WebDriver/Appium against dev2 — true
   iOS WebKit + real keyboard behaviors in CI. Costs macOS runner minutes; propose
   enabling per-release rather than per-push.

## 6. Effort estimate (lane-windows, honest)

| Phase | Est. | Notes |
|---|---|---|
| 0 stopgap | 0.5–1 window | hook + cherry-pick + e2e run |
| 1 manager + lrs/lcp | 2–3 windows | the hard one; includes deleting old code |
| 2 composer v2 previs + reply mode | 2 windows + Ian approval latency | previs is cheap, build is the window |
| 3 desktop unification | 1–2 windows | mostly deletion + skin |
| 4 ntm/topic | 1 window | endpoint + mode |
| 5 misc sheets onto manager | 1 window | mechanical |
| 6 cleanup | 0.5 window | deletion + grep audit |
| **Total** | **~8–10 windows** | serialized; phases 0 and 2-previs can start immediately |
