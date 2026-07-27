# ANON GATE — every door that can reach a composer or a focused input

*anon-gate lane, 2026-07-27, at `webroot/hub-polish.js` after the sheet-composer fix.
Keeper: "Two gates for six-plus entry points is the actual defect; a third gate that
fixes only Ian's exact tap would leave the same class of bug behind."*

**Rule this encodes:** READING is public — anon may open the replies sheet and read the
thread, and gating that would be a regression in the opposite direction. WRITING is
gated. So the sheet opens for everyone; the composer inside it does not exist for anon.

---

## A. Composer doors — `openComposerSheet()`, gated at `hub-polish.js:4971`

The gate is on the FUNCTION, not on its callers, so every present and future call site
inherits it. Anon never gets composer DOM built at all.

| # | Call site | Reached from | Anon result |
|---|---|---|---|
| A1 | `:850` | reply-to-a-comment, inside the lrs sheet | gate → modal |
| A2 | `:863` | reply-to-a-comment, feed-card teaser | gate → modal |
| A3 | `:3604` | OP/topic edit (`editTopicId`) | gate → modal |
| A4 | `:3795` | the lrs `.lrs-comp` bar click | **bar not built** (§C) |
| A5 | `:3843` | `openRepliesSheet(..., {focus:true})` — **Ian's tap** | gate → modal, no caret |
| A6 | `:5919` | reply edit (`editReplyId`) | gate → modal |

## B. fb-inline composer doors — `openReplyBox()`, gated at `hub-polish.js:878`

A second write-capable composer with its own textarea and submit. Gating only the sheet
would have left this open.

| # | Call site | Reached from | Anon result |
|---|---|---|---|
| B1 | `:847` | desktop route out of `openReplyComposer` | gate → modal |
| B2 | `:869` | last resort, no sheet/card context | gate → modal |
| B3 | `:967` | Reply on an optimistically-inserted stub | gate → modal |

## C. Sheet doors — `openRepliesSheet()`, **deliberately NOT gated**

Nine call sites. All of them open the thread for anon, by design. What changed is what
the sheet CONTAINS: for anon the composer bar is not built at all — no `#lrs-comp-input`,
no `#lrs-comp-send`, no `#lrs-comp-photo`, no `#lrs-comp-file`, nothing focusable — and
in its place sits a `#lrs-signin` button (`:3665`) that opens the modal (`:3727`). The
one-time composer wiring block is skipped for anon (`:3726`), and the per-open composer
reset is guarded, so the absent elements cannot throw.

| # | Call site | Trigger | `focus:true`? |
|---|---|---|---|
| C1 | `:436` | mobile card Reply tap — **Ian's path** | **YES** → A5 |
| C2 | `:861` | reply-to-comment, opens thread behind composer | no |
| C3 | `:1704` | reply-stub tap | no |
| C4 | `:2315` | card-body tap | no |
| C5 | `:3857` | `window.lgOpenTopicMobile` — `?topic=` deep link (forums.js §4f, `:4933–4940`) | caller-supplied |
| C6 | `:5215` | "View N replies" | no |
| C7 | `:5228` | read-mode → OP body | no |
| C8 | `:5233` | load-more | no |
| C9 | `:5463` | collapsed-thread expand | no |

Only C1 requests focus, and it routes through the A5 gate. C5 forwards whatever opts a
deep link supplies — also A5-gated, since it is the same function.

## D. Server-gated doors (no client gate needed — verified anon 0 / member 7,7,2,1,2)

These are server-rendered and emitted only when `lg_bb_mirror_can_post()` is true, so
anon never receives the markup. This is why **desktop was never broken**.

| Surface | Markup gate |
|---|---|
| `fc-composer` (persistent card composer) | `_feed.php:1645` |
| desktop reply CTA `[data-frm-open]` | `_feed.php:1555` |
| new-topic CTAs `[data-ntm-open]`, `.lg-newpost`, `.forum-header__new-post` | `_feed.php:1300/:1341/:1367/:1372` |
| per-reply Reply buttons | `_reply-render.php:529/:543` |
| topic-page reply form | `_single-topic.php:543` (+ its own anon sign-in link) |

These same selectors are now the client's **fallback** signal (§E).

## E. The flag, and why "absent" changed meaning

`_chrome.php` stamps `data-lg-can-post="0|1"` on `<body>` from `lg_bb_mirror_can_post()`.

`lgCanPost()` resolution order:
1. attribute `"1"` → can post
2. attribute `"0"` → cannot
3. **absent** → derive from `.fc-composer,[data-frm-open],[data-ntm-open],.lg-newpost,.forum-header__new-post`

Step 3 previously returned **true** ("permissive; the submit check still catches it").
That was wrong, and it is the interesting failure of this lane: it makes a **partial
deploy** silently reopen the hole. Ship `hub-polish.js` without `_chrome.php` and every
anon gets a live composer while the suite stays green — because the suite asserted the
permissive branch was correct. The derived fallback keeps the original promise (never
block a real member on stale HTML — they have the markers) without the hole (anon never
has them). Those markers predate the attribute, so they work on old HTML.

## F. Not in scope for the client gate

- **`ntm` topic create** (forums.js) — server-gated markup (§D) plus its own anon state
  panel; its Sign in link now carries `redirect_to` (`_chrome.php`).
- **`frm` desktop reply modal** — server-gated markup, plus an existing anon state panel
  (`_chrome.php:394`), also now carrying `redirect_to`.
- **Every server defence stays**: no composer markup to anon, BuddyBoss REST 401s
  anonymous writes, `reply.php` re-checks caps, anon contact/mention scrub untouched.
  This is a UX layer on top of a gate, never a replacement for one.
