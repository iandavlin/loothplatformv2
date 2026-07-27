# COMPOSER-V2 PHASE 3 — deletion inventory (posted BEFORE the deletion commit)

*composer-v2-p3 lane, 2026-07-26, at main 432f80b + the audit re-verification (08452e2).
Keeper's phase-3 instruction #2: list every entry point and write path being removed,
with grep evidence that nothing else calls it, and post it before deleting anything.
Deleting create paths is the irreversible half.*

**Nothing in this document has been deleted yet.** This is the proposal.

---

## 0. What phase 3 actually is, restated from code truth

The plan (§3, row 3) says: *replace frm + fic + fb-inline with composer v2 wearing a
desktop modal skin; delete native-REST create paths; kills G8 structurally.*

Re-verification changed two premises:

- **G8 is already functionally closed** by the phase-0 `bbp_new_reply` mint+bell hook
  (`platform/mu-plugins/bb-mirror-sync.php:203`, guard `reply.php:333`). Desktop replies
  mint and ring today. Phase 3's win is *architectural*: retire the dependency on a
  mu-plugin hook for correctness.
- **There are FIVE native-create surfaces, not three.** `fc-composer` and the
  single-topic page reply form were never inventoried (now audit §1.12 / §1.13). Phase 3
  as literally scoped (frm + fic + fb-inline) leaves two native create paths alive — so
  the hook would still be load-bearing and **the stated purpose would not be met**. This
  is the open scope question in §5.

The composer component itself needs no new build. `openComposerSheet()`
(`hub-polish.js:4806`) already accepts `{tid, fid, replyTo, replyToName, title,
editReplyId, editTopicId, bodyText, focus}` and already implements every mode frm has.
Phase 3 is genuinely skin + repoint + delete.

---

## 1. Entry points to REPOINT (not delete) — the skin half

These stay as user-visible affordances; only their handler changes to composer v2.

| # | Entry point | File:line | Today | After |
|---|---|---|---|---|
| E1 | `.feed-card__reply-cta[data-frm-open]`, `.reply-stub__reply`, `.fc-composer__rich` — one delegate | `forums.js:2792` | `frmOpen(trigger)` | `openComposerSheet({…})` |
| E2 | dmodal-injected reply CTA | `forums.js:4377` | rides E1 → frm | rides E1 → composer v2 |
| E3 | fc-composer plain input + `.fc-composer__send` | `forums.js:3912–4003` | posts native inline | opens composer v2 |
| E4 | fb-inline desktop route | `hub-polish.js:847` | `openReplyBox()` @ >640px | `openComposerSheet()` |
| E5 | fb-inline last resort (no sheet/card ctx) | `hub-polish.js:869` | `openReplyBox()` | `openComposerSheet()` |
| E6 | optimistic-stub "Reply" wiring | `hub-polish.js:963` | `openReplyBox()` | `openComposerSheet()` |

**E1 is the load-bearing one**: because the dmodal composes by *injecting* a
`data-frm-open` CTA rather than owning a composer (audit §1.8), repointing that single
delegate moves every desktop dmodal reply at once.

---

## 2. Write paths to DELETE — the irreversible half

Every one of these is a **CREATE** path posting native BuddyBoss REST. No edit path is
touched (all edits already PUT the owned mirror endpoint and stay exactly as they are).

| # | Write path | File:line | Surface | Live? |
|---|---|---|---|---|
| W1 | `fetch(frmRestBase + '/reply')` | `forums.js:2905` | frm desktop reply modal | **live desktop** |
| W2 | `fetch(REPLY_BASE + '/reply')` | `forums.js:3986` | fc-composer | **live desktop** |
| W3 | `fetch('/wp-json/buddyboss/v1/reply')` | `hub-polish.js:925` | fb-inline | **live desktop** |
| W4 | `fetch(protoReplyBase + '/reply')` | `forums.js:1190` | fic | dev-only (`?proto=cards`) |
| W5 | `fetch(restBase + '/reply')` | `forums.js:3354` | single-topic page form | **live** — *see §5* |

After W1–W5, `grep -n "buddyboss/v1/reply"` over `webroot/` + `bb-mirror/web/` must
return **zero reply-CREATE call sites**. That grep is the deletion gate, and it is the
condition under which the G8 stopgap hook stops being load-bearing.

### Grep evidence that nothing else calls these

Verbatim output at 08452e2 (re-run this before the deletion commit; it is the gate):

```
$ grep -rn "buddyboss/v1/reply\|restBase + '/reply'\|REPLY_BASE +\|protoReplyBase + '/reply'\|frmRestBase + '/reply'" \
    bb-mirror/web/forums.js webroot/hub-polish.js
webroot/hub-polish.js:897:  // …then POST to /wp-json/buddyboss/v1/reply      <- comment only
webroot/hub-polish.js:925:        return fetch('/wp-json/buddyboss/v1/reply', {   <- W3
webroot/hub-polish.js:3724:          fetch(LRS_REPLY_BASE + '/media/upload', {     <- upload, NOT a create
webroot/hub-polish.js:4318:        fetch(LRS_REPLY_BASE + '/media/upload', {       <- upload, NOT a create
bb-mirror/web/forums.js:1190:          fetch(protoReplyBase + '/reply', {        <- W4
bb-mirror/web/forums.js:2905:      fetch(frmRestBase + '/reply', {               <- W1
bb-mirror/web/forums.js:3354:    fetch(restBase + '/reply', {                    <- W5
bb-mirror/web/forums.js:3986:      fetch(REPLY_BASE + '/reply', {                <- W2
```

Eight hits, and every one is accounted for: **five reply-CREATE call sites (W1–W5), two
`/media/upload` calls, one comment.** No other caller of any of them.

Adjacent things deliberately excluded, so the gate isn't misread:
- `forums.js:2404` `ntmRestBase + '/topics'` — topic-create, **phase 4**, untouched.
- All `/media/upload` calls (`hub-polish.js:3724,:4318`; `forums.js:1054,:1259`, the
  `lgComposerTray` uses) — uploads stay native on every surface; they were never the
  G8 hole.
- The base *definitions* (`forums.js:970,:2487,:3027,:3914`) don't match this pattern;
  they go with their creates and are listed per-row in the table above.

After the deletion the same grep must show **zero `'/reply'` creates** — only the
`/media/upload` lines may remain.

### Dead code deleted in the same pass

| # | Dead code | File:line | Evidence it is dead |
|---|---|---|---|
| D1 | frm `_lg_anon` branch | `forums.js:2902–2904` | reads `#frm-anon-check`, which **does not exist** — removed 2026-06-10 (`_chrome.php:415–419`); server refuses it (`reply.php:367`). Only `#frm-anon` (a *state* panel) matches the grep. Deleting loses no behaviour. |
| D2 | `editorOf()` whitelist entries for deleted editors | `forums.js:394` | `textarea.fic-input` and `textarea.lg-fb-replyinput` name editors that cease to exist. Must come out in the same commit or the shared mention engine advertises dead surfaces. |
| D3 | `.lg-fb-replybox` / `.lg-fb-replyinput` CSS | `hub-polish.js:3238,:3240`; `forums.css` (minified block) | styles only the box W3/E4–E6 remove |

---

## 3. What is explicitly NOT being touched

Stated so the diff can be read against it:

- **The shared composer and everything it owns** — `lgc*` (`hub-polish.js:3897–5007`),
  the mention pipeline (`.lg-mnt`, `forums.js:286`), the attachment strip (:4208/:4253/
  :4265), the link panel (`#looth-link-sheet`, :4090/:4684) and the tag picker
  (`#looth-tag-sheet`, :4053/:4508). **One composer is the thesis.** If any desktop need
  turns into a per-surface branch inside these, the lane stops and reports (keeper #3).
- **Every EDIT path.** frm reply-edit (`:2867`) and topic-edit (`:2825`), rse (`:1127`),
  post-edit (`:3260`), the composer's own edit PUTs (`hub-polish.js:4940/:4976`). All
  already hit the owned mirror endpoint.
- **`ntm`** — new-topic create is phase 4.
- **`rse` / `post-edit` / `__lgLbPop` / per-surface locks** — phase 6 cleanup.
- **The content sheet + mobile lightbox `overflow:hidden` locks (G9)** — phase 5.
- **`/media/upload`** — stays native everywhere.
- **`LgSheets`** — the manager is phase-1 work and is not modified, only registered
  against.

---

## 4. Parity and verification obligations (keeper #4, #5)

- **Parity gate**: every control the desktop skin ships must have its mobile counterpart
  in the same change. The composer is one component, so parity is mostly structural —
  but the desktop modal skin must not introduce a desktop-only control.
- **Dark legs both themes** — the desktop modal skin needs its `html[data-lguser-theme=
  "dark"]` overrides in the same commit (the composer's existing dark block is at
  `hub-polish.js:4027`ff; `--lg-panel-*` tokens are NOT loaded on /hub — G4).
- **Real-touch discipline stands on desktop.** Synthetic clicks lied twice today. The
  exit test (desktop reply mint + bell e2e) gets a real-input verification, and the
  mint/bell legs are asserted against the store, not against a 200 response
  (`sanitize-on-read → audit the store`).
- **`tools/e2e-webkit/`**: `composer-v2.spec.js` + `composer-link.spec.js` are the
  contracts that must stay green. `reply-stack.spec.js` carries a **KNOWN-FAIL for G1
  that is now fixed** (audit §1.3) — it should be flipped to a PASS assertion.

---

## 5. RESOLVED — keeper's ruling 2026-07-26

**Scope: ALL FIVE.** W1 frm, W2 fc-composer, W3 fb-inline get the full composer-v2 claim
+ native create deleted; W4 fic deleted (dev-only); **W5 single-topic form is
retarget-only** — its fetch moves to `/bb-mirror-api/v0/reply`, its own Quill UI is left
alone, because composer v2 is path-gated to `/hub` and that page is not. Gate must read
**zero** native `'/reply'` creates.

**Stopgap: KEEP as backstop.** The `bbp_new_reply` hook stays, re-commented from
"stopgap" to "backstop": it stops being load-bearing (which is what "the stopgap stops
being needed" asked for) while still covering any future native path for free.

*Original question retained below for the record.*

## 5a. The question as posed (superseded by the ruling above)

The plan names three surfaces. Re-verification found five. The two extras are not
optional extras — they decide whether phase 3 meets its stated purpose.

**W2 `fc-composer`** — live desktop, on `/hub`, composer v2 *is* loadable there.
Recommend: **in scope, full claim.** It also has two doors today (plain input posts
native; the `.fc-composer__rich` pencil delegates to frm). Claiming only the pencil and
leaving the fast path native would preserve the exact split phase 3 exists to end.

**W5 single-topic page form** — live, but **not on `/hub`**, and composer v2 lives in
`hub-polish.js`, which is path-gated to `/hub` (`hub-polish.js:46`). Composer v2 is
therefore **not loadable on that page** without relocating the module out of
hub-polish.js — a much larger job that would touch every phase-1/2 surface.
Recommend: **retarget-only** — point its existing fetch at `/bb-mirror-api/v0/reply` and
leave its own Quill UI alone. That closes G8 on the surface, costs ~one line, forks no
behaviour, and leaves the full v2 conversion as a separable later phase.

If keeper prefers strict plan scope (frm + fic + fb-inline only), that is a coherent
call — but then two native create paths remain, the stopgap hook stays load-bearing, and
phase 3 should not be described as killing G8 structurally. The lane will do whichever;
it will not quietly pick.

**Secondary question — retire the stopgap hook, or keep it as insurance?** Once the grep
gate in §2 is zero, the hook is unnecessary. It is also idempotent, guarded, cheap, and
would silently cover any *future* native path someone adds. Recommend **keeping it**,
re-commented from "stopgap" to "backstop". "The stopgap stops being needed" is satisfied
by it no longer being load-bearing; deleting it buys nothing and removes a net.
