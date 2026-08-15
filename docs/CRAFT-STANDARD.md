# Web-craft standard (Ian 6/12: "figure out how this gets fixed permanently")

The disease this kills: basics (image sizing, eager scripts, cache headers)
were re-discovered and re-fixed surface by surface — ~13 rounds on images
alone — because each session's lesson died with its context and "verified"
meant "the screenshot looks right." Screenshots can't see weight.

## The law

1. **Discovered twice → becomes a gate.** Any defect class found a second
   time MUST be encoded as a mechanical check in `tools/gates/` before it is
   fixed the second time. Fixes without gates are rent; gates are ownership.
2. **Gates run, not get remembered.** `tools/gates/run-all.sh` is the one
   entry point. Run it before any push that touches a user-facing surface,
   and as the cut acceptance gate (LIVE-DEPLOY-PLAN Phase D). A red gate
   blocks the push the same way a red visibility matrix does.
3. **Done = green gates**, not a screenshot.

## The craft checklist (what the craft gate enforces)

- **Images**: every same-origin content image goes through the resizer
  (`/img.php?s=…&w=…`) — never a raw `/wp-content/uploads/` original — with
  `srcset` (≥2 widths, browser picks by slot × DPR) and `width`/`height`
  attrs (layout reservation). No image ships >1.7× its rendered pixels.
- **Scripts**: no eager heavyweights a viewer can't use — editors (quill),
  composers, admin tooling load on intent (click/focus), never for anon.
- **Weight**: a page's image transfer stays under budget (gate: 1.5 MB);
  total transfer under 2.5 MB.
- **Caching**: versioned static assets (`?v=`) ship long-lived
  `Cache-Control` (nginx d0457fc pattern).
- **Page furniture**: HTML that must not cache (front page) says so; pages
  carry exactly one h1; lazy-load below-the-fold media.

## Documented exceptions (decided, not missed)

The checklist above is the default. An image that departs from it gets an entry
HERE, with the measurement that forced it — so the next reader can tell a
considered trade from an oversight, and doesn't "fix" it back into a defect.

### YouTube thumbnails on discussion cards — `mqdefault`, no `srcset`

`bb-mirror/web/forums/_feed.php` renders a YouTube facade on a discussion card
whose body leads with a YouTube link. When the discussion has no attached photo
of its own, the thumb is `https://i.ytimg.com/vi/<id>/mqdefault.jpg` — 320×180,
cross-origin, **one width, no `srcset`**. That is deliberate:

- **Sharper costs a broken card.** The higher-resolution rungs do not always
  exist. Measured over all 13 YouTube discussions on dev2 (2026-07-30), `hq720`
  and `maxresdefault` return **404 on one of them**. A 404 in a `srcset`
  candidate is a *broken image*, not a graceful fallback — the browser does not
  retry another candidate. One reliably-soft card beats an occasionally-empty one.
- **The 4:3 rungs bake in letterboxing.** `hqdefault` (480×360) and `sddefault`
  (640×480) are 4:3 canvases with black bars burned into the pixels, so they put
  bars *inside* the cover box. `mqdefault` is the only always-present rung that is
  true 16:9.
- **Weight.** Those rungs run 58–206 KB each against a 1.5 MB PAGE-IMG-BUDGET,
  and one forum page can carry 7+ facades. They *do* count: `i.ytimg.com` sends
  `timing-allow-origin: *`, so Resource Timing reports a real `transferSize` and
  the gate's budget sees the bytes. `mqdefault` is 5–19 KB.

**Known cost: it is soft on a high-DPR phone.** ~1× on desktop, ~0.3× on a 3×
phone. That is known, not missed. Do not add `srcset` to "fix" it without first
re-measuring rung availability — the reason this is one width is availability,
not laziness.

The escape hatch already works: a discussion that carries its own attached photo
uses *that* as the thumb, and that path is fully compliant — resizer, `srcset`,
`width`/`height`.

### Not an exception: `img.php?s=bb_medias/…` without dims

`/hub/<forum>/` ships a topic cover through the resizer *without* `width`/`height`.
It is a per-attachment data gap, not a missing code path: on
`/hub/share-your-repair-content/`, **4 of the 5** `bb_medias` covers carry both
attrs and one (`bb_medias/2026/06/1000024061.jpg`) carries neither, so
`lg_cover_dims()` is resolving for most rows and coming back empty for that one.
All five `<img>` tags are **byte-identical between `main` and the
`discussion-card-video` branch** (verified 2026-07-30) — pre-existing, not
introduced by the facade. That page is not in the craft gate's
`PAGES`, so the gate has never seen it. Left alone on purpose (Ian, 2026-07-30):
adding the page would turn a *shared* gate red for a defect the lane did not
introduce and block every other lane. Tracked on the backlog instead; fix the
dims and add the page in the same change.

## The class that keeps getting through: presence is not reachability

Twice now Ian has reported a control he could not use while every check we had
was green, because the check asked whether the element **existed** and he was
asking whether he could **use** it:

1. **2026-07-30, the 🔔/✉ toggles.** 24 `[data-follow]` nodes in the DOM;
   `.fc-actions` wrapped to a second line and the card's `overflow: hidden` ate
   them on 17 of 18 cards. Gated by `follow-visible-gate.py`.
2. **2026-07-31, Sign in on the anon front page.** `wp-login` ×2 in the served
   HTML; `.lg-chrome__signin` was `display:none` from 820px down while its
   stand-in only appeared from 640px down, so **641–820px had no way in at all**
   — and it was live. Gated by `anon-signin-reachable-gate.py`.

Neither was covered, moved or missing from the markup. Both were rendered and
then hidden by CSS resolved across *two* stylesheets, which no static check of
either file can see. So:

- **Assert the goal, not the element.** "A signed-out visitor can reach Sign in"
  survives a redesign that moves the control; "`.lg-chrome__signin` is visible"
  gets edited away by the change that breaks the user.
- **Measure paint and hit-test, in a real engine.** Styled + sized + in-viewport
  + `elementFromPoint` at its own centre returns it. `querySelectorAll().length`
  is not evidence and must never be reported as if it were.
- **Bracket every breakpoint the behaviour crosses.** 1440 and 390 were BOTH
  green on the day the whole band between them was dead. Sampling "desktop and
  phone" is how a responsive lockout hides.

## Existing gates

| # | Gate | What it guards | Needs |
|---|---|---|---|
| 1 | `profile-app/bin/visibility-matrix.php` | the entire visibility model (67 asserts) | — |
| 2 | `tools/gates/craft-gate.py` | the checklist above, over real pages as anon+member | **a browser on CDP :9222** |
| 3 | `tools/gates/infra-sec-gate.sh` | cookie auth / source disclosure / cdp exposure | loopback |
| 4 | `tools/gates/hub-content-paragraph-gate.sh` | `content_html` keeps its paragraph breaks | — |
| 5 | `tools/gates/looth-auth-issue-gate.sh` | non-REST mint bounce (recurs on every DB reload) | loopback |
| 6 | `tools/gates/event-date-tz-gate.sh` | a UTC "today" must not judge a site-local stored date | — |
| 7 | `tools/gates/events-tap-navigates-gate.sh` | an events tap navigates; the retired mobile modal stays retired | — |
| 8 | `tools/gates/composer-topic-meta-test.js` | forum picker cloning + tags on the composer | node |
| 9 | `tools/gates/author-socials-live-gate.sh` | bylines RESOLVE socials from the profile store, never mirror ACF | loopback |
| 10 | `tools/gates/react-types-cover-standalone-gate.sh` | a rendered react button's type is one the endpoint ACCEPTS | loopback |
| 11 | `tools/gates/shop-planner-url-gate.sh` | `/shop-layout-planner/` still answers 200 **and still mounts the planner** | loopback, or `ssh live-ro` with `--live` |
| 12 | `tools/gates/anon-signin-reachable-gate.py` | an ANON visitor can **reach** Sign in in ≤1 tap at every width — visible *and* hit-tested, not merely in the DOM | **a browser on CDP :9222** |
| 13 | `tools/gates/follow-digest-gate.py` | **PROMOTED into the sequence by this merge** (it was HELD OUT and red on purpose while the sender did not exist). The follow-digest flag's OFF state sends NOTHING: no cron armed, no cadence stored, the suppression filter proven to be the identity function, and the resolver proven to return zero recipients | wp-cli |
| 14 | `tools/gates/dev-files-anon-unreachable-gate.py` | lane/dev tooling inside a deployed plugin tree is **unreachable by an anonymous request** — 404/403, not "absent from my checkout" | starts its own **gate-free** nginx (dev2's armed gate would 403 everything and false-pass) |
| 15 | `tools/gates/cadence-control-gate.py` | the account-level email-frequency control is **PRESENT when `LG_FOLLOWING_CADENCE` is on and ABSENT when it is off** — and still hidden when the flag is on but the sender would not serve that member. Also: `hidden` actually hides (the UA rule loses to `display:flex`), and the page's only cadence write is follow-digest's transport, never usermeta and never `follow.php` | needs the lane preview up: `sudo tools/preview/lane-preview.sh up account-following` |
| 16 | `tools/gates/group-mail-dead-gate.py` | BuddyBoss's **group** mailer stays dead, and the thing keeping it dead is an **empty list nobody declared load-bearing** — `lg_discussion_group_allow()` defaults to `[]` inside `lg-discussion-group-gate.php`, written for Local Looths, and it is the only reason a new discussion in a group forum mails nobody. Post-sweep the kept groups still hold 3,735 armed subscriptions (Tri State Looths: 853), so adding one slug for entirely correct reasons turns on a mass mailer while every other gate stays green. Also holds the sole-claimant assertion on `bb_send_forums_subscribed_reply_email_notifications`, so a second hook cannot silently undo the follow roundup's suppression. Does not forbid the change — makes it impossible to make BY ACCIDENT; see `docs/ONE-MAILER-SCOPE.md` §4 | **none** — pure static analysis, so it cannot go DEAD for environmental reasons |
| 17 | `tools/gates/subscription-preserved-gate.py` | **participation must never silently unsubscribe you.** BuddyBoss reads a MISSING subscription field as "the member unticked the box" and deletes their subscription; our composer replaced BB's form in June 2026 and sends no such field, so posting a reply — or editing a discussion — destroyed the author's subscription on a save that succeeded. Same class as edit-post-parity. Runs its probe in THREE processes: repair absent (**must reproduce the loss** — the negative control, without which a green is unearned and the gate reports DEAD), flag ON (must preserve), flag OFF (must match absent exactly). Covers `/reply`, `/reply/<id>` and `/topics/<id>`, which resolve "whose subscription" differently | `wp-cli` as `looth-dev`, and a published topic on the box |
| 18 | `tools/gates/post-follow-controls-gate.py` | **ruling 6's defaults: 🔔 bell TICKED, ✉ email UNTICKED.** A post at the defaults must create the `forums.topic_follow` row and must **NOT** create the `type='topic'` roundup row — "email unticked" is the difference between a member who chose email and one signed up by posting, and it inverts silently if a composer ever passes `subscribe` by reflex. Four modes (absent / off / on-default / on-email); OFF is asserted against **absent**, not against "nothing written", because `subscribe` is BuddyBoss's own param which our flag neither gates nor should. Does not assert bell *delivery* — that is the notification bridge's contract | `wp-cli` as `looth-dev`, a published topic, and Postgres `forums.topic_follow` |
| 19 | `tools/gates/social-actions-wired-gate.py` | **a rendered control CARRIES ITS BEHAVIOUR — the UI-lies class.** Backlog 4.4 + 4.3 (Ian 8/8): DMs dead from the mobile profile tray and the 3-dots menu dead, ONE cause — profile-app renders the social widget and shipped its behaviour as an inline `<script>`, and the tray RELOCATES that markup into another page where an inline script can never run. Seven controls rendered, none wired. **Why a gate and not a test:** every natural assertion here is about PRESENCE and the defect lives entirely in ABSENCE — the buttons are in the DOM, styled and hit-testable, so a presence check is GREEN on the broken state. It asserts the PAIRING (markup present ⇒ its wiring is present and reachable) per flag state, off the rendered widget, and pairs every absence claim with a liveness claim, because the widget renders `''` for an anonymous viewer and for your own profile — against which every "no stamp" assertion is vacuously true. It also diffs the two live copies of the wiring (the OFF heredoc vs the ON asset) and goes RED on drift, which is the only thing making a flagged migration with two copies safe | *(see the gate header — owned by the social-actions lane)* |
| 20 | `tools/gates/hub-topic-landing-gate.py` | **a sitemapped discussion URL lands on THE HUB with its modal open, and carries its text in the SERVER HTML.** e9ddc28 listed 1,352 discussions in the sitemap, every URL the permalink `/hub/<forum>/<topic>/` — which rendered the legacy standalone page, so the sitemap turned a fallback into Google's front door (Ian: *"does this look like the fucking hub with a fucking modal open?"*). **TWO HALVES THAT FAIL INDEPENDENTLY**, which is the whole reason both are gated: the obvious fix — route that URL at the feed and let forums.js §4f open the modal — gives Ian the right picture and silently destroys the SEO half, because §4f's cold path fetches the body AFTER load, so a crawler reads an empty modal and **nothing looks wrong to anyone running JS**. A: OP body + reply text in `curl`, no JS, no cookies, title in `<title>` — this half also guards the REPLACEMENT, since the legacy page did server-render its content and that must not regress on the way out. B: the hub feed grid with a modal already open on it. Plus the visibility masks **differentially against the fragment API** (audit H6 was that leak on this exact permalink) and a hidden-forum topic 404ing through **both** the landing route and the fragment API — the fixture self-guards by checking the sitemap first, since a 404 assertion against a public topic is a green that measures nothing. READS the flag state rather than hardcoding it; `LG_TL_REQUIRE_ON=1` promotes "legacy layout served" to a finding (throw it when the default flips), `LG_TL_PREFIX` gates a lane preview instead | **none** — curl + the dev gate cookie, ground truth self-sourced from `/bb-mirror-api/v0/topic` and `?replies=`, so no DB role |
| 21 | `tools/gates/notif-read-seen-gate.py` | marking notifications read is scoped to **what the member actually SAW** — and, the decisive half, **rows they did NOT see are STILL UNREAD**. Also: owner scoping (a foreign id marks nothing), `markAllRead` still sweeps, the recap keeps unseen rows and is emptied by a sweep, and `applySeenRead` measured under **BOTH** values of `read_seen_only` | `sudo -u profile-app php` + the `profile_app` DB. No browser: it runs in a transaction that is **never committed** |
| 30 | `tools/gates/legacy-url-redirect-gate.py` | **a legacy forum URL reaches its OWN canonical permalink, not a generic landing.** Ian: *"are all our old links going to work in legacy posts etc?"* The obvious check is GREEN ON THE BROKEN STATE — every legacy shape already 301s *somewhere*, so "does it redirect?" proves nothing. Google reads a **many-to-one** 301 as a soft 404: no authority transferred, no consolidation, and the old URL keeps its own index entry, which is the reported symptom. (A blanket `Disallow` makes it worse — a disallowed URL cannot be recrawled, so it stays indexed with no content.) So the gate asserts the **destination, per URL**, resolved from Postgres rather than hardcoded. Also asserts the two failures a redirect map makes in opposite directions: a reply in a **non-public** forum must NOT resolve (a redirect target is an existence oracle), and an **unknown** id must still land somewhere rather than 404 a crawler. `topic-tag` and `page/<n>` deliberately keep landing on bare `/hub/` and are not asserted — a tag has no indexable per-URL target, because the hub's tag view is `/hub/?…` and live robots.txt `Disallow: /hub/?`, so a 301 into it would aim Google at a URL it is forbidden to fetch. That is a recorded decision, not an oversight (`docs/LEGACY-URL-REDIRECT-STATE.md`) | `sudo -u postgres psql` for ground truth; curl + the dev gate cookie |
| 31 | `tools/gates/hub-category-page-gate.py` | **`/hub/<category>/` renders THE HUB and declares which URL it is.** Ian, 2026-08-11: the category pages were never rebuilt. One premise corrected before the gate was written — the page is **not** a wholesale legacy view, it already renders the new feed cards; what it *also* renders, and neither `/hub/` nor a topic landing does, is the legacy **left category tree** (`nav-tree` 84 on `/hub/general/`, 0 on `/hub/`, 0 on a landing). Same condition as the rail: `_chrome.php` shows the legacy nav only when `__bb_hub_rail` is empty and `_feed.php` sets it **only on the unscoped branch**. **Presence AND absence** — "no nav-tree" alone is satisfied by a blank page, a 500 or a redirect, so every rail check is paired with "the hub rail is really there". Two halves with different lifecycles, gated differently on purpose: the **canonical** is not member-visible so it ships unflagged and is asserted unconditionally; the **rail** is member-visible so it ships behind `LG_HUB_CATEGORY_RAIL` (OFF default) and the gate READS which rail is served, reporting OFF as a state rather than a finding. `LG_HC_REQUIRE_RAIL=1` demands it | `sudo -u postgres psql` for the category list; curl + the dev gate cookie |
| 32 | `tools/gates/stale-page-redirect-gate.py` | **retired pages 301 per-URL to a destination that ANSWERS.** Ian's 2026-08-11 rulings: `/shop-organisation/` → `/hub/shop-organisation/`, `/featured-content/` → `/hub/`, `/merch/` → loothtool.com, **`/shop/` left alone** (alive, linked from homepage/front/hub/events). Per-URL and never blanket, because Google reads many-to-one as a soft 404. **It asserts the destination RESPONDS, not merely that a Location header is emitted** — and that is the whole reason it exists rather than the conf being "obviously fine". `/merch/` points at a domain we do **not** control, and measuring before shipping found `https://loothtool.com/` = 200 but `https://www.loothtool.com/` = **522** (Cloudflare origin timeout). The www form is the one most people would type into a conf, and it would have 301'd every visitor and Googlebot into a dead end. An external target can also rot later with nobody touching our code — a conf comment cannot notice that; this can | curl + the dev gate cookie, plus one unauthenticated external fetch |

> **Rows 19 and 22 are now filled in** (hub-seo-landing, 2026-08-12). This note previously said both were missing and "left for their owners to describe"; they had been open long enough that later lanes numbered past them, which is exactly how a table with holes becomes the thing people mint duplicate numbers from — three collisions had already merged cleanly by the time it was noticed. Both rows now TRANSCRIBE each gate's own header rather than guessing at its rationale, so they are the owner's words even though the owner did not type them here. `run-all.sh` remains the authoritative roster, and every row number in this table is now aligned to the `=== GATE n/N ===` banner of the gate it describes.
| 26 | `tools/gates/notif-renderer-parity-gate.sh` | every type the store can HOLD has a **sentence on both bells**. The required set is read from `Notifications.php` (TYPES + HUB_TYPES), not by diffing the two renderers — that would go green the day a type is added to neither. `forum.followed_topic` shipped 2026-07-28 with a desktop sentence and none on mobile, whose `default:` renders a bare actor name; eleven days on live, and both rows it ever produced went to real members, one of them Ian | none (source parity); `--prove` red-firsts against the real pre-fix file at `862feb9` |
| 27 | `tools/gates/notif-dismiss-gate.sh` | the bell's **delete/dismiss contract** and **leg 4's follow stores**, per flag state. Today's `deleteAll` really destroys; dismissal keeps the row, hides it, and still lets the NEXT reply through; the `ON CONFLICT` arbiter matches the index that actually exists in all four pairings *including the failing one*; and leg 4 honours `status=0` so the 8/8 group-sub sweep cannot be undone through the bell | passwordless sudo to `profile-app` and `looth-dev` (peer auth — neither database is reachable as `ubuntu`); reports **CANNOT RUN**, never RED, without it |
| 28 | `tools/gates/notif-bell-delivery-gate.sh` | **a FOLLOW actually produces a BELL ROW**, end to end. IAN-RULINGS §6 assigns this contract to the bridge, not the composer, and Ian made the bell the DEFAULT follow channel — so gate 18 (composer writes `topic_follow`), the followers proof (leg 4 reads it) and gate 20 (a row renders) were three green gates either side of a seam nobody crossed. Crosses two Postgres databases, two OS users and an HTTP hop, so it cannot be a unit test. Writes real rows on dev2, cleans up under a `trap`, and **asserts the cleanup**; refuses to run anywhere but dev2 (keyed on `LG_PUBLIC_HOST`, since `LG_ENV` says "dev2" on live too). Found a real defect on its first run — see the Host note below | passwordless sudo to `looth-dev` **and** `profile-app`; dev2 only |
| 29 | `tools/gates/notif-endpoint-gate.sh` | the bell's **DELETE endpoint honours the flag, per state** — the model proof covers `delete()`/`dismiss()`, but what runs when Ian flips `dismiss_instead_of_delete` is the ENDPOINT, and a mis-wired branch there means the old destructive behaviour (or a 500) on a live bell. Issues REAL authenticated requests against the **working tree's** copy, because curl would test the serving checkout i.e. `main`. Asserts the response is **byte-identical in both states**, so no surface breaks on the flip, and that a second dismiss still reads `not_found` rather than 500. Phase 0 is its liveness control: a GET must return the payload AND a bogus token must be refused. Flips a tracked file by **snapshot-and-restore with the md5 asserted**, never `git checkout --` | passwordless sudo to `profile-app`; the migration applied (else phase 2 reports CANNOT RUN) |

> **Two rows both said "13" until 2026-08-01** — dev-files-anon and follow-digest,
> minted by different lanes in the same window. Same collision as the "9/9" one in
> `run-all.sh`. **Mint a gate number from `origin/main`'s count, never your
> branch's**, and re-check it immediately before you push.

**Gate 15 asserts an ABSENCE, which is the half this codebase kept missing.** Gates
assert what should be PRESENT; all six defects that reached Ian's phone through a
green suite were things that should have been absent and were not. A control that is
meant to be invisible is precisely what nobody writes an assertion for. It proves
both directions by diffing two surfaces that really exist — the live page and the
lane preview — and its `--prove` pass runs every predicate against the surface it was
written to REJECT. That pass immediately caught two of the gate's own assertions
being worthless, which is the argument for having it.

**Gate 21 is the second time the SAME absent-half class cost a member their digest**,
which is exactly the trigger this document defines for minting a gate. The class is
"a read that was never a read": on 2026-07-29 the recap's two registers disagreed
about what "still outstanding" meant, and on 2026-08-07 the mobile sheet's 700ms
timer marked a member's whole store read after rendering eight rows. In both cases
"the rows the member saw are read" was true and green throughout; nobody had written
down "the rows the member did NOT see are still unread". Its red-first pass
(`lib/notif-read-seen-redfirst.sh`, ten inversions) caught the gate matching its OWN
COMMENT PROSE — `limit=200` appears in both the fetch and the comment explaining it —
so the check now strips comments before reading code. Full account:
`docs/RECAP-READ-TIMER.md`.

**Gate 13 is written BEFORE the feature it guards, and that is the point.** Every
other gate here encodes a defect class found twice. Email is unrecallable, so the
second occurrence is not survivable — this one is gated before the first. It was held
out of the runner only because a deliberately-red gate in the shared sequence would
block every other lane's push for a feature that does not exist; **the commit that
defines `LG_FOLLOW_DIGEST_ENABLED` must promote it into the sequence in the same
commit.** A gate guarding an unrecallable channel that is never promoted is worse
than no gate, because it reads as covered.

It also carries the answer to the trap that makes most OFF-state gates worthless:
**an absence assertion passes vacuously whenever the machinery that would produce the
presence is itself missing.** "No cron event is scheduled" is true on a box with no
WordPress at all. So every absence assertion in gate 13 is paired with a *liveness*
assertion proving the mechanism exists and is reachable first. Absence without
liveness is not evidence.

All thirteen run from `tools/gates/run-all.sh`. Two more are deliberately HELD OUT of
the runner because they pass standalone but flake red in sequence (CDP under load,
and loopback `/whoami` tripping infra's `limit_req` zone) — see the note at the
foot of `run-all.sh` for how to run `forum-visibility-gate.sh` and
`editor-rail-reachable-gate.sh` by hand.

### A synthetic click cannot perform the gesture that breaks

`tools/gates/messages-longpress-react-gate.py` (HELD OUT — it needs a proxy with member
cookies and a member who is a participant in a thread that has messages; red/green-proven
both ways on 2026-07-31) encodes the class that has now reached Ian **three** times:

1. `mobile-hub.js` `holdTargetFrom()` claiming the 🔔/✉ follow toggles (`8405055`);
2. the same shape again on the consolidated Follow pill;
3. the messages action sheet closing on **its own trailing click** — which made
   reacting to a message on a phone impossible, not merely awkward.

The common cause is not carelessness, it is the harness: **a CDP/Playwright `.click()`
dispatches in single-digit milliseconds and can never cross a 380/480ms hold threshold.**
Every automated tap ever run against these controls took a path a human finger cannot
take, so the defect was not missed — it was *unreachable*. Press with `touchStart` → a
real wall-clock sleep → `touchEnd`, and assert the STORE, not the pixel.

It also carries the absence lesson in its sharpest form: on the broken build the sheet
DID open, and a presence check said so (that assertion passes in the red run). The
load-bearing assertion is that it is **still** open once the finger lifts.

### "Bad z-index" is usually a CLIP, and both look identical from outside

`tools/gates/react-controls-reachable-gate.py` (HELD OUT; red/green-proven 2026-07-31)
guards two live defects that were each **present, correctly styled, and impossible to
use** — the same class as the Sign-in lockout above, found twice more in one day:

* **The card react palette** was `position:absolute; bottom:calc(100% - 2px)`, i.e. it
  opened *upward, entirely outside* `.fcr` — and `765dbc3` had put `overflow:hidden` on
  `.fcr` to let the action row shrink. Its own offset parent clipped it away. Ian
  reported it as *"the react button modal on all cards has a bad z"*, and that reading is
  completely reasonable: **a clipped popover and one painted behind something look the
  same.** `z-index: 20` was being honoured the entire time. When a popover "has a bad z",
  check `overflow` on every ancestor up to the stacking context *before* touching
  `z-index` — and fix it by moving the clip onto the in-flow child that actually needed
  clipping, so the layout guarantee the `overflow:hidden` was bought for survives.
* **The messages React control** was revealed by `:hover` alone, so on a touchscreen at
  ≥641px — where the *desktop* modal renders — no hover event ever fires and the only
  route to reacting was unreachable. Gate the **hover capability** (`hover: none`), never
  a width breakpoint: absence of hover is the cause, and a large touchscreen is exactly
  what a width query misses.

In both RED runs a presence-style assertion **passes** ("the palette is open and has a
real box"). `elementFromPoint` is the only check that separates *painted and reachable*
from *present*.

**A gate that CANNOT RUN is not a gate that passed, and not one that failed.**
Gate 2 drives a real Chrome; with no engine on :9222 it reports one `GATE-ERROR`
per page and exits 1, which is indistinguishable from finding real violations —
it spent weeks looking red while it was in fact dead. Treat "no engine" as *no
verdict* and say so, rather than reporting a pass or a failure it never reached.
`origin/events-fix-verify` carries the three-state fix (exit 2 = CANNOT RUN);
until that lands, check for the engine before you believe gate 2 either way.

## Why this works when 13 fixes didn't

The visibility model stopped leaking the week it became ONE function plus a
test that fails. Nothing else in this project has ever stopped a recurrence.
This document exists to make that the default move, not the last resort.
| 23 | `tools/gates/sw-fetch-bounded-gate.py` | a service-worker navigation **always SETTLES** — the decisive input is a `fetch` that neither resolves nor rejects, which is what Ian's spinning tab actually was. Also: the one retry is not traded away for the deadline, dev-gated paths are **not intercepted at all**, a gate 403 becomes a **claim prompt** instead of raw nginx output, and a 403 shell asset no longer kills the whole install. Asserts the harness's OWN fidelity first — a `vm` context lacks `setTimeout`/`URL`, and without them cases pass down paths no browser takes | node. No browser, no nginx, no DB |
| 25 | `tools/gates/mirror-reconcile-poison-gate.php` | **one bad row must not wedge the mirror sweep.** `bin/reconcile.php` is the only safety net under a fire-and-forget realtime sync, and it walked its delta with a bare `foreach` while writing the `last_reconcile_at` bookmark *after* the walk. A rewound bookmark pulled reply 71720 (its `_bbp_topic_id` points at an **attachment**) into the window on 2026-07-29 23:20 UTC; the FK threw, nothing caught it, and because the bookmark write was never reached the next run rewalked the same window and died the same way — 3,084 times over 11 days. Cost: 16% of replies posted in that window never reached the hub, each rescued only when its author happened to **edit** the post, worst case 2d22h. Asserts the walk survives a throwing row *and records it*, that reconcile goes through `bb_mirror_walk_ids` (lexed, not grepped — a comment quoting the old shape must not satisfy it), and that no tail step can skip the bookmark. Paired with the tripwire `tools/mirror-sync/watch-mirror-sync.sh`, because the gate proves the code is right and only the watch proves live is | **none** — static + in-process, so it cannot go DEAD for environmental reasons |
| 22 | `tools/gates/back-pill-navigates-gate.py` | **a rendered NAV control must actually navigate** (backlog 3.8, Ian ruled option D 2026-08-09). Same class as row 19 one step further along: 19 guards markup that arrives without its behaviour, this guards a control that is present, IS wired, and still takes you nowhere — a dead href, a wrong target, a listener that `preventDefault`s and forgets to navigate. Ian's 3.8 complaint was a back button that existed and could not be reached; shipping one that is reachable and inert would be the same sentence with a new subject. Also asserts the flag's OFF state is a real no-op | *(see the gate header — owned by the mobile-bugs lane)* |
| 24 | `tools/gates/sw-no-offline-shell-gate.py` | **while the origin is UP, a navigation through the service worker must render THE REAL PAGE, never the offline shell.** Encoded because the class bit three times (Ian 2026-06-25 / 08-09 / 08-11): blank spin, then "You're offline", on a URL that answers 200 server-side. Deliberately split from `sw-fetch-bounded-gate`, which uses a stubbed worker in node to ask "does the handler SETTLE when the network never answers?" — a hung fetch is something a browser cannot stage. This one is the opposite half: a REAL browser, a REAL registered service worker, a REAL page | a browser on CDP |
| 37 | `tools/gates/guitardle-claim-gate.py` | **the daily Guitardle attempt is ONE allowance per MEMBER ACCOUNT, claimed at the START of a game.** Ian 2026-08-14: *"fixing the guitardle giving more chances on different devices"* — the Weekly Top 5 has claimable spots, so this is fairness, not a quirk. **The obvious gate would have been GREEN on the defect.** "A member cannot record two results in one day" was ALREADY TRUE: `UNIQUE (wp_user_id, play_date)` + `ON CONFLICT DO NOTHING` has held since June, and 7 days of live traffic produced 93 successful POSTs and exactly 93 rows — zero duplicate writes. The leak was never the RECORDING, it was **ABANDONING**: nothing was written until `handleWin`/`handleLoss`, and the mid-game snapshot lived in `localStorage`, i.e. per DEVICE. So a player could reveal letters until the phrase was readable, close the tab (no row, no lock, **no trace**), reopen in incognito and solve it cold in one move for 10 points — 20 with hardcore. One POST, one row, indistinguishable from honest play. Live evidence: WP 197 was 27 plays / 27 wins / every win ≤4 moves / **7 of them in a single move**, against a field whose best average is 4.1. So the assertion that bites is *a SECOND start-claim for the same (member, day) must claim NOTHING* — proven red against `origin/main`'s endpoint, which `LG_GDLE_ENDPOINT` re-runs. Drives the **working tree's** endpoint via `guitardle-claim-probe.php` with a real WP session cookie, because curl would reach `/srv` i.e. the serving checkout and test `main`. Phase 0 proves the door answers as a known member before any "no row was written" is trusted; the flag-OFF promo render is compared **byte-for-byte against `origin/main`** (rendered from the same directory so `__DIR__` and the `?v=` cache-bust match). Also gates the two logged-out copy rulings and the How-to-Play overlay, which had to be **built** — the served game had no rules surface at all and Hardcore's only explanation was a `title=` tooltip, invisible on touch | passwordless sudo to `looth-dev` **and** `postgres`; wp-cli; the migration applied (else CANNOT RUN); creates/reuses a `gdle_gate_probe` subscriber and deletes every row it writes, asserted |
| 33 | `tools/gates/buck-surface-guard.sh` | **THE FENCE (Ian, 2026-08-11): our work must never modify Buck's files.** Buck's surfaces are hands-off — not ours to fix and not ours to report — *unless* one is leaking data or eating resources, which is a talk-to-Ian-first situation. Fails if the changes about to land touch any path containing `buck`, excluding the guard and its own companion notes. Runs in the suite against a lane's branch (`origin/main...HEAD`) **and should be run by keeper before any merge** — it caught this lane editing `strangler-bb-mirror-buck.conf`, which was reverted to `origin/main` verbatim. Note it is a GUARD, not a numbered gate, in `run-all.sh`: it deliberately carries no `=== GATE n/N ===` banner | **none** — pure `git diff`, so it cannot go DEAD for environmental reasons |
| 34 | `tools/gates/stripe-testgroup-pages-gate.php` (34b) + `lg-patreon-stripe-poller/deploy/remediation/test-soft-launch-allowlist.php` (34a) | **the Stripe soft launch reaches a hand-picked list and NOBODY else.** Ian 2026-08-14: the launch runs through the EXISTING member pages unlocked for a list, not a bespoke page. Two halves because the feature has two: 34a covers the GRANT (which webhook events may move a membership; 39 assertions, merged 8/11), 34b covers the PAGES (who can reach join/gift/refund/regional at all; 54). They address the SAME option, so they cannot drift. **34a was merged on 8/11 and never wired into `run-all.sh` — it went un-run in every nightly until 8/15; a merged gate absent from that file does not exist.** 34b asserts PER STATE and reads the flag off the box rather than assuming one: flag off/absent (the default) refuses a LISTED member exactly as today, which is what makes the off state byte-identical; flag on with the list absent/empty/malformed still refuses everyone; and with both armed the list is the ONLY discriminator. An administrator is never gated behind the list — Ian builds the Stripe op privately and must not lock himself out. Also asserts no page's GO-LIVE audience moved, and that the QA surface stays admin-only in both columns. **Two mutations found holes in the GATE rather than the code:** a fail-open list mutation stayed green because the section only proved one viewer was refused ("nobody got in" can be true by accident; "the list is empty" cannot — fixed by asserting the reader's output directly), and an admin-lockout mutation stayed green because it was a **no-op** (the gate falls through to another that also admits admins). **34c** (`tools/gates/stripe-price-control-gate.php`, 49) gates the dash price control: setting a price is THREE writes — create it in Stripe, record it in our own `prices` table, repoint new joins — and only the middle one looks optional. Skip it and `lgjoin.php`'s already-subscribed INNER JOIN loses an existing member, offering them a second subscription; so §3 runs the JOIN PAGE'S OWN QUERY rather than checking a row exists. Also asserts the charter's sandbox-only rule IN CODE (a live key refuses), that a failed local write leaves new joins pointing at the previous working price, that existing subscribers are grandfathered and their old price stays resolvable, and that it ships with NO price set because the number is Ian's. Two of its nine mutations found a hole in the GATE not the code, both the same one: an unexpected throw killed the run with no FAIL line (the gate-2 failure mode), fixed with `mustSet()`. Gates the standalone `membership-pages` app, NOT the shortcodes — an nginx regex shadows those, so the obvious file is dead code | **none** — no network, no browser, no DB; every option is stubbed through the app's own `function_exists` seams |
| 35 | `tools/gates/compose-gate.py` | **front-end compose + edit reaches the right people and nobody else — and OFF is inert.** Number ALLOCATED BY KEEPER (34 is the stripe seat's). I had minted 34 from `run-all.sh` myself and it was already spoken for — lanes do not mint gate numbers, and this table cannot be the source either: it carries duplicate row numbers after the 2026-08-11 merges. Asserts PER STATE, reading the feature's own `lg_fc_enabled()` off the box rather than assuming one — the flag lives in the shared `platform/config/frontend-compose.php` so bb-mirror's type toggle and the WordPress form cannot disagree, which is the same UI-lies class gate 19 guards. **Flag OFF/absent:** the route is byte-identical to a before-state recorded while the feature did not exist, checked for an **ALLOWED** user — anon 404s in both states, so an anon-only probe cannot tell them apart, and that hole made the first version pass against a flag that was ON. **Flag ON:** the allowed member gets a real form; a refused member and an anon get none; a refused POST is non-2xx **and writes no row**; the **OWNER** gets the edit form and a **STRANGER** does not (IDOR); a stranger's edit POST does not move `post_modified` — the store is the witness, not the status code; and `embed=1` serves the furniture-free variant the hub composer frames, with `X-Frame-Options` proven not DENY. Every assertion falsified by mutation before being trusted — including letting a stranger genuinely hijack a throwaway post once the ownership check was disabled, because the store-witness half had been passing for the wrong reason (no valid nonce) and was not yet earning its place | wp-cli, and the dev gate token via `gate-env.sh` |
| 39 | `tools/gates/featured-member-gate.py` | **backlog 18 (Ian 8/11), design rulings 2026-08-14.** Two schema constraints proven by trying to VIOLATE them rather than reading the DDL: `featured_opt_in_at` must be NULL unless `featured_opt_in` is true, and `discovery.featured_history` may never hold two OPEN rows (Ian's ONE AT A TIME ruling — a partial unique index on `ended_at IS NULL`). Re-runs `Completeness.php` against **every** public member and diffs it row-by-row against `tools/profile-completeness-report.sql` — the two are separate implementations of the same eight-item score with no shared source, and building the class found a real divergence (32 members' `business_name` carries a stray backslash from a double-escaping artifact; Postgres's `LIKE` silently absorbs it as an escape char so the SQL side was accidentally right and a naive PHP comparison was not) — this keeps that fix from silently regressing. Flag-off is read from the SHIPPED SOURCE (same style as row 22): the card markup in `u.php` and the `member_uuid` resolution ternary in `index.php` must each be textually reachable only through the flag check — **the first cut of the index.php check was too loose** (it only confirmed `$lg_fm_on` appeared *somewhere* after the `if`, which stayed true even after the ternary gating the actual resolution was deleted, since the variable is still computed a few lines above regardless) and was only caught by red-firing that exact mutation, not by inspection. Also asserts `me-featured.php` never lands in `Auth::ADMIN_EDIT_AS_ENDPOINTS` — Ian's consent ruling is "never inferred... no override, not even for admins," and that allowlist is the one place it could quietly regress | passwordless sudo to `profile-app`/`postgres` for the DB + PHP-harness checks; the live-route check (`§E`) needs `LG_GATE_HOST`/`LG_GATE_COOKIE` and reports CANNOT RUN, never RED, without them — expected pre-merge |
> mirror-sync lane sat unmerged and main gained two more gates. Both times the
