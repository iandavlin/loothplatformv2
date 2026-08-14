# Backlog

Items Ian has asked for that no lane owns yet. When a lane picks one up, move the
line into that lane's charter and note the lane name here.

## PRIORITY INDEX (the order — edit THIS to re-rank; tell keeper "bump X")

*Owner shown as `→ lane` when a seat is on it, `→ MERGED` when it is in main.*

**P0 — live/member-facing bugs**
4.4 + 4.3 Mobile profile tray: DMs AND the 3-dots menu — ✅ ONE cause (all 7 social controls were inert), flag ON, Ian-VERIFIED on his phone on dev2 8/9 ("workds" — Message + 3-dots tapped for real). Files already on live from the 021ff38 pull; the ON flip reaches live at his next lg-deploy
4.2 Logged-out mobile bottom dash: "+" implies you can post → anon-mobile-dash (fix BUILT, flag off; awaiting Ian's mock look + keeper merge)
4.1 Recap: the mobile bell's 700ms mark-all-read empties the recap → recap-read-timer (fix BUILT; in keeper's merge queue)
4.0 Weekly digest: discussion images SOMETIMES missing → digest-images (fix BUILT: bp_media resolution; in keeper's merge queue)
4.5 Participation silently UNSUBSCRIBES you from the discussion → ✅ MERGED + LIVE @ 10ea816 (was eroding the 381 followers since June)

**P1 — wanted now / deploy-blocking**
4.6 Mobile sponsor sheet → ✅ CLOSED 8/9, NOT a defect: tested in a browser, everything works; my static read counted attributes as elements and desktop-only arrows as mobile controls
3.6 [hidden] DEFEATED BY CSS — TWO confirmed instances, both fixed on mobile-bugs and PROVEN end-to-end at 390 AND 1280: .post__menu (Edit/Delete dropdown open on every topic page for anyone who can edit) and .reply-form__replying-to ("↩ replying to" banner shown when you are not replying). NOT mobile-specific, as I first filed it. Cause: `hidden` is only a UA display:none, so the component's own display:flex beat it while its JS toggle kept flipping an attribute with no visual effect. Sweep tool + 6 unverified candidates: tools/gates/hidden-attr-honoured-sweep.py — 6 candidates now MEASURED (browser-hidden-attr-audit.py): NONE currently misrender; 4 are masked by a hidden ancestor (latent), 2 were not rendered on the surfaces checked. So the sweep stays OUT of run-all.sh — reddening on latent-only candidates would assert more than is true
3.7 Mobile: discussion embed → ✅ LIVE @ 021ff38 (Ian ran lg-deploy 8/9; keeper smoke-verified ON the live box: bit emitted, guard served, social-actions stamp absent). Ian-VERIFIED on his phone on dev2 first ("looks good", test-3). His words on scope: "THE MAIn problem was no imbed in the modal." Card half → item 7, not a bug
3.8 Mobile/PWA post → hub BACK NAV → ✅ BUILT as Ian's HYBRID refinement 8/9 ("can we have the back button be the appear on scroll hub button but have it in the lower left?"): option C's lower-left thumb corner + appear-on-scroll — HIDDEN at the top and while scrolling down, revealed on the way back up. NOT plain D, which sat visible at rest. webroot/bottom-nav.js beside the tray Back it promotes, flag platform/config/back-pill.php OFF, gate 22 (14 assertions, all reddened). Verified on real /hub/ paths incl. a hit-tested tap landing on /hub/. MERGED 8/9, flag OFF; awaiting Ian on the serve. Frames: /footer-mockups/post-back-nav/
3.10 PWA service worker: fetches HANG or fall back to offline.html — bit Ian TWICE on 8/9 trying to view mocks (phone: offline.html + gate-403s from the installed app's separate cookie jar; desktop Chrome: eternal blank spin, request provably never reached nginx while every SW-bypassing path answered in ms). Same sw.js serves LIVE members. → recap-read-timer (assigned 8/9, keeper recommendation). Needs: sw.js fetch-handler audit (timeout + network-first for uncached paths), SW scoped out of /footer-mockups/, installed-app cookie-jar gap fail-open
3.9 bb-mirror realtime sync SILENTLY STALLS — UNOWNED, measured 8/9 on live (forums.reply sync_at−created_at, realtime era only): typical stall 10–30min, worst 23h47m (72589) and 2d22h (72560 — a member's reply invisible on the hub for ~3 days). The instant path is proven capable (2s) and something (reconcile?) heals late, so this is an intermittent stall, not a dead pipe. Member-facing. Found 8/9 while proving Ian's "erroneous" notif email was in fact correct. Needs: root cause + a lag tripwire (alert when sync_at−created_at > 5min on new replies)
3.5 SEO/sitemap → hub-seo-landing. Landing FLIPPED ON dev2 8/9. ⚠️ Ian caught a real gap: permalink serves 200+content (Googlebot OK) but has NO canonical tag, while hub-polish.js pushState's the bar to the robots-BLOCKED /hub/?topic= form. FIX IN FLIGHT: self-referencing <link rel=canonical> + og:url = the absolute sitemap permalink, + a canonical assertion added to gate 20 (it only checked content-served, not canonical-present — the blind spot). BLOCKS Search Console submit until fixed. Remaining: per-URL legacy redirects, /merch disposition (/shop is ALIVE)
6. Front-end COMPOSE → frontend-compose. RULED 8/9: (1) copy = lane's new wording; (2) allow-list = ALL MEMBERS (no gated tier); (3) entry point = INSIDE the hub "new post" composer as a TYPE TOGGLE (discussion ↔ loothprint), each rendering its own form — NOT a separate A/B entry. Ian's vision verbatim: "this form should be part of the compose package in the new post in the hub… toggle between discussion and loothprint… have two different forms." (4) NEW SCOPE: members front-end EDIT loothprints they authored (not just create). Confirmed from branch: the loothprint form IS acf_form() pointed at the live loothprint ACF field group (12 primary/28 total fields) — a renderer on existing groups, not a new form. Mock incl. separate entry-point.html page
5. Notifications: quick-reply modal (default) w/ full-post link — UNOWNED, 9 commits on origin/notif-quickreply
7. Discussion hub cards: play video inline ★ — ✅ CLOSED BY RULING (Ian 8/9, three answers in one night, each asked directly with full context). (a) His 3.7 complaint was the reader sheet, not the card: "THE MAIn problem was no imbed in the modal." (b) Scroll-autoplay, with the measured costs in front of him: TAP-TO-PLAY STAYS everywhere — do NOT build autoplay as an improvement. (c) Mobile cards keep opening the sheet (video one tap deep): "Keep as-is." The 6/17 desktop-only facade ruling stands in full. Reopens only by a new ruling from Ian himself
8. Front page: latest weekly email for logged-out users — UNOWNED
9. Shop Layout Planner — apps index page ONLY (standalone render SHIPPED to live) — UNOWNED
18. FEATURED MEMBERS — opt-in consent + admin dash + auto-publish (Ian 8/11, "fairly soon type of thing") — UNOWNED. THE FLOW: (1) member ticks a TICKBOX in their profile = explicit agreement "to be included as a potential featured member" → joins the SELECTABLE pool. Consent is opt-in, OFF by default, EXPLICIT — never inferred (email-project consent lesson); per-member flag (usermeta/profile-app field). (2) an ADMIN DASH shows the selectable pool + tracks featured HISTORY (who + when). (3) admin SELECTS a member in the dash → they are AUTOMATICALLY added to the front page (no separate publish step; deselect removes them). Front page reads only opted-in + currently-selected. Scope: profile tickbox is member-facing (flag it); admin dash is admin-only; front-page block is public. Mock the tickbox + dash + a sample front-page card for Ian BEFORE build

**EMAIL PROJECT — Ian's rulings 4-7, see docs/IAN-RULINGS-2026-08-03.md**
E1 Follow roundup general release → ✅ LIVE @ fd0d196, all members, daily default, watched by cron
E2 Legacy group email → ✅ DEAD (plugin deactivated + 9,297-row sweep, 8/8)
E3 Post→follow controls (bell ticked, email unticked) → one-mailer, server half BUILT flag-off
E4 Bell delivery gap + delete=dismiss → notif-bridge
E5 Weekly Recap, scoped to bell-only types → PARKED behind E4 by design

**P2 — polish / UX**
11.5 Emoji picker for the messages composer (Ian 8/1, Variant 1 ruled) → emoji-picker-build
11.6 Notifications: filter by type + bulk delete of that type (Ian 8/1) — UNOWNED
9. Advanced search: dynamic facet narrowing — UNOWNED
10. Add-discussion modal: resizable + text scaling — UNOWNED
12. Post header: title legibility over text thumbnails — UNOWNED
13. PWA launch animation/message — UNOWNED
13.5 Craft gate RED: finder/anon Optimum.png oversized + raw → ✅ FIXED + LIVE @ 10ea816 (107KB → 3KB via the real resizer)
14. Craft gate: /hub/share-your-repair-content/ cover missing width/height (pre-existing) — UNOWNED
15. Mail-containment: gate on host not LG_ENV (latent security) — UNOWNED, fix exists on origin/mail-safety

**SECURITY / HYGIENE**
S1 /v2/ serves 177 non-PHP files to anon on live (docs, fixtures; no secrets, PHP 403s) — blocked on the untracked live vhost, see dev-live-split
S2 /footer-mockups/ anon-readable on live — one `sudo rm` of the symlink, awaiting Ian
S3 dev2's vhost is a REGULAR FILE, not the repo symlink — tracked nginx changes have silently missed dev2 since lane-preview.sh sed -i'd it

**P3 — big builds (scope first)**
19. NEW-MEMBER PROFILES ARRIVE ALIVE (Ian 8/12, from the empty-directory screenshot) — UNOWNED. Auto-creation works (12/12 newest have profiles, all 'onboard' same-day) but 9/12 are BARE SHELLS: placeholder avatar, no location/skills/instruments — a directory of identical placeholder faces reads as a dead community to prospects. Build: sign-up step (or first-login prompt) asking photo + city + what-you-do so the shell arrives filled. Member-facing → flag, mocks for Ian first. Note: May's one-time backfill_location (659 rows) is why OLD members look fuller; new joiners since get nothing.
20. DIRECTORY SHOWS CITY/STATE ONLY (Ian 8/12, member-safety) — UNOWNED. Member 'Luke' (WP 2091) typed his full home street address into the location field and the public directory renders it verbatim. Render city/state only in directory rows regardless of what the field holds (full text can stay on the member's own profile if they chose it, but list views truncate to locality). Small, discrete, gate-able.
21. DARK MODE x LOGGED-OUT surfaces — PROMOTED P1-equivalent (Ian 8/14, two rulings same hour: "the dark mode needs some love for the login stuff" + "we have a ton of instructions and fields not ready for primetime in dark for logged out") — QUEUED: first seat that frees takes this BEFORE its next item. Scope: every logged-out surface in dark — sign-in, join banner/buttons, sign-up flow, gate/claim pages, and especially FORM FIELDS + INSTRUCTION/HELP TEXT (his "ton"). Sweep every surface a logged-out visitor meets in dark: sign-in page, join banner + Join buttons, anon front page, the gate/claim pages, sign-up flow. KNOWN SEEDS (already measured, unfixed): the "+" circle at 1.85:1 dark contrast (anon-mobile-dash lane finding — same class it fixed on its pill); sage tints not re-pointed in dark (messages-search dark-gate lesson). Dark contrast has now bitten 3+ times -> per the craft law this wave must ENCODE A GATE: anon-surface dark-mode contrast assertions, red-first. Fix wave after the sweep, flagged where member-visible.
16. Front-end authoring for all post types ★ vision
17. Stripe membership: audit → build

---
*Full item details below, newest-first. The index above is the running order.*

## 4.4 + 4.3 Mobile profile tray: DMs and the 3-dots menu — FIXED, FLAG OFF, awaiting Ian

Ian 8/8: *"we have a problem with dms on mobile when accessing from the user's
profile tray."* / *"The 3 dots for the profile menu don't work either."*

**ONE CAUSE, and wider than both tickets.** profile-app renders the whole social
widget and shipped its behaviour as an inline `<script>`; the mobile tray RELOCATES
that markup (fetches /u/, lifts `.lg-profile` out with DOMParser, injects it into
another page) where an inline script can never run. **All seven controls** —
Connect, Message, Accept, Decline, Cancel, Mute, Remove connection — rendered and
did nothing. Ian hit two because there were seven to hit.

Reproduced 390×844 as a member, with a control that passes: the SAME buttons on the
full /u/ page work. Fixed by having the server stamp `data-lg-social-src` and ship
the wiring as one file both entry paths load. Flag `platform/config/social-actions.php`,
**OFF by default**; OFF proven byte-identical (9677 bytes, md5 09b2216239e1…).
Gate 19. Branch `mobile-bugs`.

**"Profile tray" had two readings and only one was broken** — the bottom You-sheet's
Messages row opens the messenger fine. Measured, not assumed.

⚠️ **Deploy coupling:** `webroot/lg-social-actions.js` is a NEW webroot file;
`install-symlinks.sh --new-only` must run in the same window as the pull or the
flag-ON path 404s.

Pictures for Ian: https://dev2.loothgroup.com/footer-mockups/mobile-profile-tray/

## ✅ 4.6 Mobile sponsor sheet — CLOSED, NOT A DEFECT (tested 8/9, my static read was wrong)

I filed this from a static sweep and it does not survive contact with a browser.
Closing it with the measurements, and with why the static method misled me, because
the method is the reusable part.

Measured at 390x844, the same sponsor (`/sponsors/stewmac/`) standalone vs opened as
a sheet from `/sponsors/` (which does NOT load the v2 bundle):

| control | standalone | in the sheet |
|---|---|---|
| Play video | tap → 1 iframe | tap → **1 iframe** |
| carousels | native scroll | **native scroll** |
| Send message | native form submit | native form submit |

**Why the static case was wrong, in three ways, all of the same shape — counting
markup is not counting behaviour:**

1. The "12 carousel/gallery elements" were 12 `data-lg-carousel-prev/next`
   **attributes**, not elements. `class="...lg-carousel..."` occurs **zero** times on
   the page. A regex over HTML counted attribute names and I read it as components.
2. Those nav arrows are `display:none` at 390px — **desktop only, by design**. A
   control that is not rendered at the width under test cannot be the defect, and I
   never checked.
3. The mobile carousel needs **no JS at all**: the track is `overflow-x:auto` with
   `scroll-snap-type: x mandatory`, scrollWidth 724 > clientWidth 358. A member swipes
   it. The v2 bundle wires the *arrows*, which mobile never shows. So "the bundle is
   absent on the list page" — which is true — implies nothing about mobile behaviour.

NOT TESTED, deliberately: whether the contact form is AJAX-enhanced on the standalone
page and would fall back to a full-page navigation inside the sheet. Finding out means
submitting a contact form, i.e. sending mail, and the worst case is a navigation
instead of an inline success — not a dead control. Not worth the send.

The sweep that produced this was still worth running — it is how 3.7's second surface
was found. The lesson is narrower: **a relocated-markup lead is not evidence until a
control is tapped at the width the member uses.**

## 4.2 Logged-out mobile bottom dash — the "+" lies, and Sign in hides (P0) — Ian 8/5

Ian: *"It's currently showing the + icon for adding a post and it's not entirely
clear that you cant post logged out. In general I want logged out users to be able to
log in easily and see the login easily, but I still want logged out users to be able
to tool around and see all of the stuff that might get them to join."*

**TWO REQUIREMENTS THAT PULL AGAINST EACH OTHER — hold both.**
  1. Signing in must be OBVIOUS on mobile when logged out.
  2. Logged-out visitors must still ROAM FREELY. This is the top of the funnel; it
     is what converts. Do NOT solve requirement 1 by gating browsing, adding an
     interstitial, or bouncing anonymous users to a login wall.

The specific defect: the bottom dash shows the "+" compose affordance to logged-out
visitors. Tapping it cannot do what it promises. Either it does nothing (dead
control) or it dumps them at a login — both are the UI-lies class, and the second
spends the click a stranger was willing to give you.

Replace the "+" slot with Sign in for anonymous visitors. That solves both
requirements with the same pixel: the lying control disappears and the thing Ian
wants visible takes its place, in the highest-traffic spot on the screen.

⚠️ THE SIGN-IN LOCKOUT IS THIS EXACT SURFACE'S HISTORY. An anon nav overlay once hid
every link INCLUDING Sign in, and the gate that now guards it
(`an ANON visitor can reach Sign in at every width`) exists because of it. Extend
that gate rather than writing a new one, and assert the "+" is ABSENT for anon —
absence is the half that gets skipped and it is the actual bug here.

Mock first, phone widths, both themes. Ian decides from pictures.

## 4.1 Recap emptied by the mobile bell (P0) — surfaced 8/3

Ian ruled the recap stays "what you missed" (unread only) — see
docs/IAN-RULINGS-2026-08-03.md §1. He ruled on the FRAMING; this defect was not
part of what he chose, and it makes that framing misfire.

`webroot/bottom-nav.js:1125` fires markAllNotifsRead 700ms after the mobile notif
sheet renders, POSTing {action:'read_all'} for EVERY row, not the visible eight.
Under unread-only that empties the recap; under "empty means send no email" it
cancels the member's digest. The member most engaged with the bell is the one most
reliably unmailed.

The fix already exists one arm over and was never extended: Recap.php:110-113
refuses to consult is_read for connection_request for exactly this reason.

Mark read what was actually SEEN, not the whole store. Red-first: a member with 12
notifications who opens the sheet and closes it must still have unread rows.

## 4.0 Weekly digest: discussion images SOMETIMES missing (P0, LIVE) — Ian 8/3

Ian, from live: *"images from discussions are now sometimes not making it into the
weekly digest."*

**"NOW" AND "SOMETIMES" ARE BOTH LOAD-BEARING — do not drop either.**

*Now* means this is a REGRESSION: it used to work. Bisect against the digest's own
sent history rather than reasoning from current source — find a past send where the
image IS present and one where it is not, and diff the discussions behind them, not
the code first.

*Sometimes* means a whole-feature explanation is already wrong. Something about
PARTICULAR discussions decides it. Candidates worth separating before touching code,
because they fail identically in the email and differently in the fix: image attached
to the topic vs. embedded in the body; first-post image vs. a later reply's; an
uploaded attachment vs. a hotlinked/remote URL; a private or hidden source forum;
and the resizer — `/img.php?w=` needs the source readable by the mail renderer, which
runs as a different user than the web request that uploaded it.

**IAN NARROWED IT 8/5: "the images are mostly affected in the discussion section of
the email."** That is the strongest clue on the table — the digest renders several
section types through the SAME card templates, so an image failure confined to the
discussion section is unlikely to be the renderer and much more likely to be how a
DISCUSSION's image is resolved. Discussions are bbPress topics mirrored into
Postgres; other sections come from content_item. Different source, different image
field, same card. Start by diffing how the two resolve a thumbnail, not by reading
the card template.

Get a FAILING and a PASSING discussion side by side from a real send before forming a
theory. This is the weekly digest (editorial, FluentCRM), NOT the follow-digest — two
different projects that share a word.

## 3.6 Post page: the Edit/Delete overflow menus render ALREADY OPEN on mobile (P1) — found 8/9

Found while capturing 390px frames for 3.8. **Not reported by Ian yet, but he will hit
it on every post he opens on his phone** — it only shows for people who can edit, and
he can edit everything.

On a fresh load of a topic page at 390x844, with **zero interaction**, the "..."
overflow menu on each post is already visible. Measured `visibility:visible`,
`display:flex`, two of them on a one-reply thread, sitting over the post body:

```
ZERO interaction since load.
 VISIBLE menu w/ Edit : BUTTON.post__menu-item post__edit-btn           vis=visible disp=flex y=402
                        BUTTON.post__menu-item post__menu-item--danger  vis=visible disp=flex y=784
```

Markup is `bb-mirror/web/forums/_single-topic.php` (the "FB-style ... overflow menu
(Edit + Delete) for one post" block). Scoped to viewers with edit rights, which is
exactly why an anonymous or plain-member check calls this page clean — the same blind
spot that let 4.4/4.3 through.

Reproduce: `tools/exercise-harness/browser-backnav-captures.py` hides them on purpose
so the 3.8 mockups are readable; drop that line and they are there.

⚠️ Whatever fixes it needs an assertion for the CLOSED state. CLAUDE.md's standing
point applies squarely: gates assert what should be PRESENT and cannot see what should
be ABSENT, and a menu that is supposed to be invisible until tapped is the absent case.

## ✅ 3.9 Hub: "Newest" sorted as TRENDING — FIXED, LIVE @ 0e80c5b (Ian 8/3)

Ian: *"toggling newest is producing the same results as trending. Which is to say
the that any activity on the post pushes it to the top."* Confirmed on live by Ian
after deploy: "It looks like it worked."

Cause: `bb-mirror/web/forums/_feed.php` ordered the default sort by
`t.last_active_at`, so Newest and Trending were one ordering. Both feeds fixed —
per-forum (`new` and `old`) and the site-wide union, whose `event_time` IS
`last_active_at`. The union already sorted `old` by `created_at` while sorting
`new` by activity; that asymmetry is what gave it away.

⚠️ LESSON WORTH KEEPING: dev2 CANNOT reproduce this class. Every dev2 topic has
`created_at == last_active_at` because nobody replies here, so both orderings are
byte-identical and the fix reads as a no-op. Live had 1031 topics with
`last_active_at > created_at`. Any activity-vs-creation ordering bug must be proven
against live data.

## ✅ SHIPPED TO LIVE — cleared from the index 2026-08-01

- **anon front page nav overlay hides every link INCLUDING Sign in — signed-out members cannot get back in** — anon-frontdoor, live @ e8362fa — gated 10/10 widths
- **Reacting on a shorty fails on LIVE** — shorty-react, live — proven through the real button 9/9
- **BUG: fresh reply prefilled with last reply's data — *edit-post-parity fixing*** — edit-post-parity, live — Ian: keep drafts per topic
- **Profile social links go stale on posts/events (member-reported)** — profile-social-links, live — bylines resolve live, flag ON
- **Follow-controls consolidation modal — *deploy gate; thread-follow building*** — thread-follow variant A, live — modal + card v3
- **Group chat header: collapse + text scale** — react-fix, live — 148px→80px clamp + '+N more', text 15px
- **Follow/topic-activity email (follow-digest)** — batcher proven end-to-end to Ian's inbox; cadence control built, flag OFF
- **Messages: react reachable + sizing** — react-fix, live


## 2026-08-01 — Notifications: filter by type + bulk delete (Ian)

Ian 8/1: let a member FILTER their notifications by type — e.g. select "accepted your
connection request" — and then DELETE ALL of that type at once. Today the bell is a flat
list with no way to clear a class of noise in bulk.

Shape: a type filter on the notifications panel (the types are already first-class —
`connection_request`, `connection_accept`, `forum.reply_to_topic`, `forum.reply_to_reply`,
`forum.mention`, `forum.followed_topic`, `reaction.on_post` — see profile-app/src/
Notifications.php and the notifications_type_check constraint). Filter the list to one
type, then a bulk action that dismisses/deletes every row of that type for that member.

⚠️ Two things to get right:
1. DELETE vs DISMISS. `notifications` is member-deletable and 30-day-pruned — an inbox,
   not a ledger (recap-notif-bridge, 7/31). A bulk delete is destructive and permanent for
   that member. Decide whether "delete" means soft-dismiss (recoverable, keeps the
   occurred-record) or a real row delete, and say which in the UI.
2. THE RECAP READS THIS STORE. The weekly-digest recap is sourced from
   `profile_app.notifications`. Bulk-deleting a type would silently empty that member's
   recap for those items. Coordinate with whatever owns the recap before shipping.

Member-facing: mock first (phone + desktop), flag OFF-default, gate the bulk action
affects ONLY the selected type and ONLY that member. Deploy by pull.

## 2026-08-01 — Emoji picker for messages (Ian)

Ian 8/1: add an emoji picker to the messages composer (DM/group chat). Members should be
able to insert emoji into a message. Pairs with the recent messages sizing + react work
(react-fix). Mock first, phone + desktop, dev gate. Deploy by pull.

## 2026-07-31 — Mobile: discussion embed broken + card video won't play (Ian)

Ian 7/31: on MOBILE, the embed isn't working for discussions, and the video can't be
played from the card.

**SPLIT — the two halves are different animals and only one is a bug.**

**(1) EMBEDS — FIXED, flag OFF** (`platform/config/sheet-embeds.php`, gate 19,
branch `mobile-bugs`). Surface is the mobile READER SHEET, not the card and not the
standalone page. It writes server HTML in three places and never called
`bbProcessEmbeds`; the desktop reader modal calls it on the identical data path.
Measured at 390px: sheet players 0→1, bare link 1→0, with the standalone page as a
passing control in both states. OFF byte-identical (242500 bytes, md5 34e182df…).
Same defect class as 4.4/4.3.

**(2) CARD VIDEO — NOT a regression, and NOT started.** It is a REVERSAL of Ian's own
2026-06-17 ruling (`docs/atlas/DISCUSSION-CARD-VIDEO.md`, "Desktop only, on purpose").
Four coupled off-switches, all deliberate. A cover tap cannot both play the video and
open the discussion, so **Ian must pick before any code**: does the cover play inline
with title/body still opening the sheet, and is "play" tap-to-play or the old
scroll-autoplay-muted engine? Gets its own flag once ruled.

First reproduced by the mobile-embed lane (`docs/atlas/MOBILE-EMBED-FINDINGS.md`).

## 2026-07-31 — Craft gate RED on finder/anon: Optimum.png oversized + raw (pre-existing)

Found by the craft gate on 2026-07-31, the first run after the Chrome resolver was fixed
so the gate could actually reach our origin (it had been reporting CANNOT RUN, and before
that it had reported PASS while auditing Cloudflare's challenge page).

```
IMG-OVERSIZE  finder/anon  .../uploads/2024/11/Optimum.png?w=96  natural=1000px rendered=42px@1x (107KB)
IMG-RAW       finder/anon  .../uploads/2024/11/Optimum.png?w=96  (107KB)
```

**Pre-existing, NOT a regression.** The finder page was untouched across the entire
c57b70f..main deploy window, and the image was uploaded **2024-11-27** — eight months
earlier. Ian's call, 7/31: deploy anyway rather than block a live sign-in lockout fix on
an eight-month-old image.

**The fix:** route it through the resizer (`/img.php?w=`) with a sensible width plus
`srcset` and explicit width/height, per CRAFT-STANDARD. 107KB delivered for a 42px render.

**Worth checking the class, not just this instance** — one page carrying two violations
suggests others may too, and the craft gate only covers pages someone thought to add to
its PAGES list. A sweep of uploads referenced at small render sizes would find the rest.

## 2026-07-31 — Sitemap / SEO: Google indexes the MIRRORED FORUM and a defunct merch page (Ian)

Ian 7/31: "we need to work on our site map. Google is showing results like the mirroring
forum rather than the hub and our defunct merch page."

**Measured read-only on LIVE, 2026-07-31 — the sitemap contains 2,391 URLs and ZERO
discussions:**

| sitemap | urls | contents |
|---|---:|---|
| `sitemap-static.xml` | 2 | the hub INDEX and `/` |
| `sitemap-content.xml` | 655 | 304 videos, 168 loothprints, 67 imgcaps, 39 useful_links, 30 shorty |
| `sitemap-profiles.xml` | 1734 | 1733 member profiles |

**Not one thread/topic URL is submitted.** The hub appears once, as an index page. So
Google has no canonical URL to index for any discussion — and falls back to whatever it
can crawl, which is the MIRRORED LEGACY FORUM. That is the direct cause of Ian's first
complaint, and it is a sitemap OMISSION, not a ranking problem.

`robots.txt` `Allow: /` with targeted Disallows (`/archive-api/`, `/profile-api/`,
`/bb-mirror-api/`, `/claim`, `/thumb/`, `/mailpit/`, faceted hub filters). **Nothing
disallows the mirrored forum paths**, so they are freely crawlable and are the only
discussion URLs Google can see.

**The defunct merch pages are LIVE and returning 200:** `/shop/` and `/merch/` both
answer 200 (`/store/` and `/product/` 404). They are reachable, therefore indexable.

### The work, in the order it should be done

1. **Add discussions to the sitemap** — canonical `/hub/<forum>/<topic>/` URLs. This is
   the fix for "the mirroring forum ranks instead of the hub". Coordinate with whoever
   owns hub URL construction; `lg_following_topic_url()` (`membership-pages/lib/following-data.php:251`)
   already resolves a topic to its canonical hub URL and knows the hidden-group-forum
   404 trap — reuse it rather than writing a fourth URL builder.
2. **Decide what happens to the mirrored forum paths** — `Disallow` them, canonicalise
   them at the hub equivalent, or 301 them. Canonical tags are the gentler option; a
   blanket Disallow on already-indexed URLs leaves them in the index without content.
   ⚠️ Ian on that page: *"it has no bearing on actual ui"* — it is legacy, so removing
   it from search is safe from a product standpoint.
3. **`/shop/` and `/merch/`** — 410, 301 to something current, or noindex. Ian called
   them defunct; pick deliberately, because a 301 keeps whatever authority they have and
   a 410 discards it.

**Do not fix by deleting pages that are live and earning traffic without checking first**
— same rule as `/shop-layout-planner/`. Measure what each URL currently gets before
changing its status code.

## 2026-07-31 — 🔴 MISSION CRITICAL: anon front page — nav overlay hides EVERY link, including Sign in (Ian)

Ian 7/31, from an incognito window on `dev2.loothgroup.com`: **"add to backlog as mission
critical that this page break has no sign in option."**

**A logged-out visitor cannot sign in.** A white block renders over the whole nav, so the
menu items paint as unreadable grey-on-white and none of them can be used:
`The Hub · Events · The Map · Sponsors · Loothtool · Sign in`

**The affordance is NOT missing — it is COVERED.** Measured on the served anon page:
`wp-login` ×2 and "Sign in" ×2 are present in the HTML, and the link sits *inside* that
nav list immediately after Loothtool. So this is a RENDERING failure hiding a working
control, not an absent feature. Do not "add a sign-in link" — find what paints over the
menu.

What a logged-out visitor CAN reach: `Connect Patreon`, `Join`, `Weekly email`. All three
are acquisition paths. **There is no path for an EXISTING member who is signed out** —
they are offered only ways to join something they already belong to.

**Why mission critical:** every returning member who arrives logged-out is locked out of
the platform at the front door, and the only visible options tell them to sign up again.
This is on `/`, the highest-traffic page, for the least-authenticated visitor.

Scope note: `/` is served by archive-poc's discovery feed, NOT WordPress — so this is
chrome/CSS in the standalone render path, not a theme problem. Check z-index/stacking on
the chrome header and any overlay or mobile-menu panel that may be mounting open.

## 2026-07-31 — Shop Layout Planner: standalone render + an APPS INDEX page (Ian)

Ian 7/31: "we are getting traffic to our old luthier workshop layout app." Three things:

1. **Render `/shop-layout-planner/` standalone.** It is a WordPress page today, so it
   paints in whatever theme is active — currently `twentytwentyfive`, the stock default.
   Nothing on this platform renders from a theme (Ian, 7/30: *"we dont render from the
   fucking theme"*). Copy the pattern in `membership-pages/web/manage-subscription.php`:
   own markup, own CSS, served directly. See [[standalone-rendering-not-theme]].
2. **KEEP THE URL.** `https://loothgroup.com/shop-layout-planner/` is taking real
   traffic and must not move or 404. Whatever replaces the page has to answer on that
   exact path.
3. **Build an APPS INDEX page** — a list page for Looth-made apps, with only the planner
   on it for now, so future apps have a home rather than each inventing a URL.

**Measured on live 2026-07-31 (read-only):**
- `/shop-layout-planner/` → **HTTP 200**, WP page ID **68840**, and its `post_title` is
  **EMPTY** — likely why the page reads as unfinished.
- A second page exists: ID **63845**, slug `shop-planner-page`, title "Shop Planner
  Page". Probably a superseded duplicate — establish which one traffic actually hits
  before touching either. Deleting the wrong one breaks a live URL.
- Related but not the app: `acf-field` `field_bt_shop_layout` ("Upload Shop Layout
  PDF"), topic 56217 `workshop-layout`, attachment 61556 `shop-layout-planner-modal`.
  The modal attachment name suggests the planner may currently be a modal rather than a
  page — confirm what actually renders before rebuilding it.

**Scope first, then build.** Find what serves the planner today and whether it is a
modal, an embed, or page content. Do not assume it is page content because a page
exists.

Live writes are Ian's. The URL is live and earning traffic — treat a redirect or a 404
here as a member-facing outage, not a refactor detail.

## 2026-07-30 — Craft gate: /hub/share-your-repair-content/ topic cover has no width/height (keeper)

Found by discussion-card-video 7/30 while measuring its own branch; **pre-existing,
not introduced by that lane, and byte-identical on main**. A topic cover on
`/hub/share-your-repair-content/` ships without `width`/`height`
(`img.php?s=bb_medias/…`), which is an IMG-NODIMS defect. That page is **not** in
the craft gate's PAGES list, which is why the gate is green today.

Ian 7/30: **leave it** — the lane deliberately did not add the page, because adding
it turns the *shared* craft gate red for a defect it did not cause and blocks every
other lane. Logged here instead of fixed in-lane.

Whoever picks this up: add the page to the craft gate's PAGES **and** fix the dims
in the same change, so the gate never sits red. Confirm byte-identical-on-main
still holds first.

## 2026-07-30 — Front page: show the latest weekly email to LOGGED-OUT users (Ian)

Ian 7/30: surface the most-recent weekly email on the FRONT PAGE for logged-out
visitors. Reuse what already exists — the signup page renders the latest sent
issue dynamically via LG_WD_Signup_Page::latest_sent_issue_id() +
LG_WD_Issue::get_data() through the same email builder, cached 1h
(class-lg-wd-signup-page.php). The front page is archive-poc's discovery feed
(served at / and /front-page/; widgets driven by config.json overlaying
web/defaults.php). So: add a front-page block/widget, shown only when
logged-out, that embeds the latest issue preview (same dynamic source, honor the
1h cache) with a signup CTA. Gate to anon (members already get the digest / the
Hub). Craft gates apply (public surface). Pairs with the public signup page just
shipped.

## 2026-07-30 — Notifications: quick-reply-in-modal vs go-to-post (Ian) — MEDIUM-LOW effort

Ian 7/30 (refined): tapping a notification DEFAULTS to opening a reply MODAL —
the modal shows the reply that generated the notif + a composer, AND carries a
link/button to the full post. No two-action row; the modal is the default, the
full-post link lives inside it. KEEPER ESTIMATE: not hard, most pieces exist —
- The notif ⋯ menu already exists (the hook for a "Reply" action / two-action row).
- openComposerSheet is reusable in reply mode (no new composer).
- Each notif already resolves to its source reply/comment (context to show).
New work: two-action affordance on the notif row (View → post, Reply → modal),
the modal showing the source reply as context + composer, submit → existing
reply-to endpoint. Reuses composer + reply POST. Coordinate with thread-follow
(owns notif surface) and the composer reset-vs-prefill fix. Member-facing, high
value now that follow-notifs are live.

## 2026-07-30 — BUG: a fresh reply is pre-populated with the LAST reply's data (Ian)

Ian 7/30: starting a NEW reply opens the composer already filled with the
previous reply's content. The composer isn't clearing its state between uses —
stale body/photos/fields carry over. Real member-facing bug: risks posting the
wrong or duplicated content. Likely the reused composer (openComposerSheet) not
reset on open for a fresh reply (vs edit, which SHOULD prefill). Fix: on open-
for-new-reply, hard-reset all fields; only prefill when opening in edit mode.
NOTE for the edit-post-parity/composer lane: this is the same composer you're
working — the reset-vs-prefill distinction matters (new = empty, edit = filled).

## 2026-07-30 — Advanced search: dynamic facet narrowing (Ian)

Ian 7/30: as options are picked in advanced search, REDUCE the remaining
options to only those that would actually return content. E.g. pick author
"Dan Erlewine" → the tags, content-topics, type, etc. facets update to show
ONLY values that co-occur with his content (non-empty result guaranteed).
Dynamic, live as each facet is chosen — no dead-end combinations that return
zero. Needs: facet counts recomputed against the current filter set on each
pick (server endpoint returning available values+counts for the remaining
facets), and the UI disabling/hiding zero-result options. Applies to the adv
search surface (the Advanced Search fold). Member-facing polish.

## 2026-07-30 — Admin can edit ANY post, full functionality (Ian)

Ian 7/30: "I need to be able to edit any post as admin as well. Full
functionality on any post editing as admin." Admin (or can_edit_others) edits
ANY post type — discussion, video, article, event, loothprint, sponsor — from
the front end with the FULL composer (every control creating it offers). Today
edit is wired for discussions/OP (author or mod); this generalizes it to all
types for admins. Depends on the composer-unification decision below (one
composer that handles every type + create/edit + desktop/mobile). Pairs with
the front-end-authoring-all-types vision.

## 2026-07-30 — Add-discussion modal: resizable, with text scaling to modal size (Ian)

Ian 7/30: make the "New post" (add-discussion) 4-step modal RESIZABLE, and have
the TEXT SIZE scale with the modal size — bigger modal → bigger text, so it
stays comfortable when enlarged rather than tiny text in a big box. Applies to
the discussion composer wizard (Where/Write/Photos/Review). Pairs with the
edit-discussion work (edit reuses this same modal). Likely a resize handle +
type scale bound to modal width (clamp/container units), both light and dark.

## 2026-07-30 — Follow controls: collapse the cramped row into a modal + email frequency (Ian)

Ian, looking at the topic action row (Like / 8 replies / Share / bell / envelope
/ Save all crammed on mobile): "this is really cramped. Should we have a button
that pops a modal?" AND: email frequency controls so users pick cadence —
"1 per hour, 1 per day, 1 per week or something."

KEEPER SYNTHESIS (recommended): these are one feature. Replace the inline
personal-action icons with a SINGLE control that opens a small modal/sheet
holding everything: notify on/off, email on/off, email frequency (Off / Instant
/ Hourly digest / Daily / Weekly), AND **Save/bookmark** (Ian added Save to the
consolidation 7/30). Like / N-replies / Share stay inline; the set-once personal
stuff (follow, email, save) collapses behind one button.

DESIGN NUANCE for the mock: Save is a TAP-OFTEN action while follow/email are
SET-ONCE — burying Save fully behind a modal may add friction. Mock BOTH:
(a) everything in the modal, vs (b) Save stays a one-tap on the row while
notify/email/frequency consolidate. Ian picks from the pictures. Timing: lane
mocks this AFTER the desktop-flash fix + orange on-state ship (Ian's call 7/30).

Cross-lane: thread-follow owns the control + the topic_follow store (add a
`frequency` column). weekly-recap/digest owns the SENDING — hourly/daily/weekly
means batching follow-emails into digests per the user's choice, not one mail
per event. Coordinate on the board. Instant = the current per-event path;
Off = no email. Ship the modal first (de-cram), frequency second.

## 2026-07-30 — Front-end authoring for all post types via layout-v2, gated (Ian) ★ vision

Ian's direction, 7/30. Near-term ask: **add loothprints as a post type USERS can
post from the front end** (today discussions are the main user-postable type;
loothprints join them). Full vision: **author EVERY post type from the front end
through the layout-v2 block system** — the same block/forms UX, not wp-admin.

Gating model Ian wants:
- Most post types (video, article, event, sponsor-post, etc.) → **admin-gated**
  front-end forms.
- **Discussions and loothprints** → open to members (the un-gated, user-facing
  authoring path).
- **Prefer a USER WHITELIST over the WP `author` role** to decide who can post
  the gated types — explicit allow-list of trusted users, not a role grant.

Notes for whoever scopes this: the stack already has a "Frontend Admin" plugin
(seen in wp-admin sidebar) and layout-v2 owns the block model (_lg_layout_v2
meta; see the layoutv2-ian handoff — its backend add-block UX is itself being
fixed). This is a multi-phase build: (1) loothprints front-end form first, (2)
generalize the layout-v2 authoring surface to the front end per type, (3) the
whitelist-based gate. Big item — Ian rules the phasing before any build.

## 2026-07-30 — Discussion hub cards: PLAY a video inline like a video post (Ian) ★ wants this

Ian restated 7/30: "I want to be able to play a video in a discussion in the hub
card like a video post would have happen." A discussion whose body carries a
YouTube/Instagram/Facebook link should show that media EMBEDDED and PLAYABLE on
its hub card — the exact inline-play facade (thumb + play button → iframe on
click) video posts already get. Fine if the embed is driven by a link pasted in the discussion
posting modal (the composer already shows "paste a YouTube/Vimeo/Instagram link
on its own line to embed it" — so IN-BODY embed on the post page likely already
works; the gap is the HUB CARD).

What exists (verified in _feed.php:1048-1075): the card inline-play facade
(thumb + play button, iframe swapped in on click by forums.js) is built ONLY for
card_type='content' with content_kind IN ('video','shorty') — sourced from the
engine's stored yt_id (ACF youtube_link → v2 embed block). DISCUSSION cards
(card_type='topic') are excluded, and the yt_id extraction only runs for
video/shorty. So a discussion with a YT link in its body gets no card embed today.

Scope: (1) extract an embeddable id from a discussion's body/first-embed at index
or render time; (2) let the topic-card path carry the same facade; (3) IG/FB are
NOT youtube — they need their own oembed/id handling, more than the yt regex.
DISCUSSIONS ONLY per Ian. Verify the in-body-on-post-page case first and report
whether it already works, so the ask narrows to just the card.

## 2026-07-30 — PWA apps need a launch animation/message (Ian)

The installed PWA apps can take a while to fire up now; there's no feedback
during the cold start — it reads as a hang. Add a splash/loading animation or
"starting up…" message on launch (service-worker/app-shell boot). Applies to the
Add-to-Home-Screen PWA path (webroot/pwa.js, sw.js). Small, member-facing polish.

## 2026-07-30 — Front-page admin pencils: KEEP THE BUTTON (Ian) — CLOSED, no work

front-page-editor shipped the edit pencils behind an "Edit page" button, and
asked whether they should instead be always-on for admins (a one-line flip).
**Ian ruled 2026-07-30: keep the button.** The reason is the reason it was built
that way — editor code loads only on intent, so "visitors get zero editor bytes"
stays provable rather than asserted, and the page keeps its chrome. Recorded so
a later lane doesn't "improve" it into always-on in good faith. No work owed.

## 2026-07-29 — Reacting on a shorty fails on LIVE (Ian, screenshot w/ console)

On live, e.g. /shorty/dan-erlewine-stewmac-purfling-jig-mod/: tapping a
reaction emoji fails — console shows POST /archive-api/v0/card-react returning
**400**, twice, from inject_main.js. The react strip renders (lg-pf-react__opt
buttons) but the endpoint rejects the payload. Suspects: archive-poc's
card-react endpoint validating something live's page doesn't send (auth/token
shape, card id format for shorties), or shorty cards minting ids the endpoint
refuses. Verify on live read-only first; the fix likely lives in archive-poc
(web or api/v0/card-react). Member-visible on live — high priority next seat.

## 2026-07-29 — Group chat header: verbose member list + cramped text (Ian, screenshot)

Seen on Partners Chat (8 people): the panel header lists every member's full
display name inline — half the panel is names before the first message. Two
asks: (1) past a few participants, collapse the name roll to a count ("Group -
8 people") plus the MANAGE-MEMBERS button as the way to see/edit who's in the
chat — no verbose list; (2) the chat must respect the member's text size and
scale its container accordingly — at larger text it's awfully cramped (fixed
container + growing text). Candidate owner: next messages-surface lane (the
side-chat popout Ian references in that thread is already shipped; this is the
group-header + type-scale layer).

## 2026-07-29 — Profile social links go stale on posts/events (member report: Massimiliano Monterosso, 7/1 email)

Member removed the Linktree link from his profile, but it still shows on the
EVENTS he posted; and the FB link on his video POST differs from his current
profile. Shape of the defect: post/event surfaces carry a SNAPSHOT of the
author's social links taken at creation instead of reading current profile
truth at render — same drift class the mirror work fixed elsewhere. Fix
direction: bylines/social rows on posts + events render live from the profile
store (or re-sync on profile change via the new profile_update pipeline).
Audit which surfaces snapshot links (video posts, events, others?) and whether
a backfill is needed for existing content. Member-visible, member-reported —
prioritize into the next content-surface seat.

## 2026-07-29 — Stripe membership: back on the roadmap — AUDIT FIRST, then build (Ian)

Ian: "It's time to start building that out. We probably need to audit the whole
thing at this point." Audit scope before any build: the poller's Stripe half
(Tick→Sync→UserProvisioner — POLLER-ONBOARDING-AUDIT §3: an email-keyed latent
minter runs every 5 min for 3 live customers; if Stripe onboarding is coming it
must route through shared Patreon-id-aware identity matching, never email-only),
the lg-stripe-billing Slim companion app (cross-cutting handoff in
docs/SESSION-HANDOFF.md), the lg_membership schema on live (customers 4,
wp_user_bridge 3, entitlements 7, subscriptions 3; lg_role_sources 44 stripe
rows), the origin/stripe-poller-audit branch (dev2 Stripe sandbox-safety doc,
6/20), webhook + key state, and Patreon coexistence (the sweep's
payment_source=stripe skip). Deliverable: atlas audit + a build plan Ian can
rule on. CHARTER CANDIDATE: stripe-audit lane — next free seat.

## 2026-07-29 — Post header: title illegible over thumbnails that contain text (Ian, screenshot)

On post/video pages, the hero overlays the post title on the thumbnail. When the
thumbnail has its own baked-in text (e.g. AGBC video cards: "Offset soundhole,
modern guitar design" behind the title "Acoustic Guitar Builders Club: Offset
Soundhole…"), the two layers of text fight and the title goes hard to read.
Ian wants the header text more legible in that case. Candidate treatments (lane
decides, Ian picks from pictures): stronger scrim/gradient behind the title
block, blur/darken the image region under the text, or a busy-image detection
that switches to a solid band. Applies to the hub post hero; check video posts
especially (thumbnails almost always carry text).

## 2026-07-29 — Public weekly-email signup page (Ian)

A page where someone WITHOUT a WP account signs up for the weekly email. No WP
user may be required or created by signing up. Stack notes: the weekly digest and
its recipient rules are the weekly-digest-recap lane's domain (fold this into its
respawn charter — candidate owner); FluentCRM exists on the stack and live mail
rides FluentSMTP→SES; public-facing page → craft gates apply. Needs: the page,
a store for non-member subscribers, unsubscribe, and the digest sender reading
that store alongside members.

## 2026-07-29 — ~~Events mobile: banner cut off + time listed twice~~ → CLOSED by the events-mobile lane

RULING (Ian, later 7/29): on MOBILE, don't open the event modal/sheet at all —
clicking an event goes straight through to the event's post page. The ruling as
recorded added "the crop + duplicate-time fixes then apply to whatever surfaces
still use the sheet (desktop)" — **that clause resolves to nothing, and it matters
that it does: there is no desktop sheet.** `webroot/events-mobile.js` is the only
thing that ever built one and it hard-returns above `max-width:640px`; desktop has
navigated to the post page since Buck wrote it. The two other scripts `/pwa.js`
loads on `/events` without a mobile gate (`events-live.js`, `loothalong.js`) carry
no modal, no `preventDefault`, no card interception. So the ruling makes mobile
match desktop, and nothing is left unfixed on desktop.

Original report: on the mobile event sheet ("Looth Pro — Intermediate Inlay with
CNC", live 72363): (1) the banner was cut off — the AUG-2 chip sat on the artwork
and the bottom crop ate the "August 2nd, 3PM" typeset into the image;
(2) "Sunday, August 2, 2026 · 3:00 PM ET" rendered TWICE, under the title and
again below the PRO chip.

**Both diagnosed, then closed by the ruling itself** — branch `events-mobile`,
which retires the sheet and so deletes the surface both defects lived on. Verified
they do not migrate to the destination: under 768px `post-header/shell.css` runs
the hero at `height:auto; object-fit:unset` (uncropped), and the event header
states the date once. Causes, for the record: `.lev-cover` pinned `height:170px`
around a 16:9 poster (81 image px off top and bottom, 22.6% of the height), and
the description selector led with `.lg-event-header__detail`, whose only `<p>` is
the byte-identical date line — so it also meant the real blurb never rendered at
all. Ian's ruling is now gated (`tools/gates/events-tap-navigates-gate.sh`, GATE
7/7) because it is encoded in an absence and a future lane would undo it in good
faith.

**One residual — RULED 2026-07-30, Ian: the EVENT POST PAGE.** "Add to calendar"
is gone from the mobile events flow; it lived only in the sheet, and the event
post page has no calendar affordance. Ian chose the event post page over the
landing card, because that is where every tap now lands, so one build serves
mobile and desktop alike and it adds no chrome back to the landing surface he
just had stripped. Scoped as a small work item, not its own lane. Not started.

**Two findings handed to the post-header item below** ("title illegible over
thumbnails that contain text") rather than filed separately, since they are the
same hero on the same posters:
1. That item's fix is **already solved on mobile, in-tree** — under 768px the
   shell drops the overlay entirely (`object-fit:unset`, scrim hidden) and puts
   the title on a cream ground BELOW the photo. Its comment says why: "the
   overlay-on-photo treatment was unreadable on narrow viewports (title + chip
   collided over a busy image)". One of that item's own candidate treatments — a
   solid band — is what mobile already does, so the desktop question is really
   "adopt the mobile answer, or scrim harder".
2. Separately, `.lg-post-header__photo` is a **raw one-size upload**: no
   `/img.php?w=`, no `srcset`, no width/height, `loading="eager"
   fetchpriority="high"`. 263KB of poster at full size on a phone, and the missing
   dimensions reflow the page on load. Breaks the standing image rule in
   CLAUDE.md, and Ian's ruling just put it on the mobile critical path.
   `post-header` is used by EVERY post, so whoever takes the legibility item
   should take this too.

   **RULED 2026-07-30, Ian: its OWN LANE, spins at the next free memory slot.**
   Not folded into events-mobile (that branch is approved and merge-ready, and
   this is cross-cutting work on a block every post renders) and not deferred —
   the mobile-critical-path argument carried. Charter still to be written.

## 2026-07-29 — Mirror dispatch must be durable → CHARTERED: mirror-dispatch lane

Ian approved 7/29 (spins after merger night). Charter: ~/lane-prompts/mirror-dispatch.md.
Outbox queue + reconcile reverse pass + WP-native delete hook; proves §9's hop.
Root cause: docs/atlas/MIRROR-ATTACHMENT-ORPHANS.md §10.

## 2026-07-29 — Mail containment must gate on host, not LG_ENV (keeper)

`platform/mu-plugins/lg-dev-mail-containment.php` treats `LG_ENV=dev2` as proof
of a dev box — but live IS `LG_ENV=dev2` by design (prod-at-cut layout;
bb-mirror/config.php:38-44 documents the decoupling). Dormant today only because
the plugin is not in live's curated mu-plugin symlink set. Fix: require the
public-host check too (`LG_PUBLIC_HOST`/request host ≠ loothgroup.com) before
containing anything, so an accidental future symlink on live stays a no-op.
Same review should sweep any other tool using LG_ENV as a dev/live SAFETY key
rather than a LAYOUT key.

## 2026-07-30 — Edit wizard: two seed loops race, and the empty one can win (UNOWNED)

Found by the edit-post-parity lane while proving photo add/remove. **Pre-existing on
main — measured equal on main's own assets and on the branch, so it is not a regression
from that lane, and that lane does not own the surface.** Not started; needs an owner.

**Symptom.** Saving the edit wizard is rejected with *"Sorry, Your discussion cannot be
empty."* while the member's text is visibly on screen. The post is NOT damaged — the
save is refused and the stored body is untouched — but the edit cannot be completed and
the message is nonsense to the member.

**Mechanism (demonstrated, not inferred).** The wizard seeds its editor TWICE:

- `bb-mirror/web/forums.js:1937` — optimistic seed from the caller's `bodyHtml`, the
  rendered OP scraped off the page, to stop the editor sitting blank for one round trip.
- `bb-mirror/web/forums.js:1979` — authoritative seed from the server payload.

`ntmSeedBody()` (`forums.js:1893`) retries every 100ms up to 30 times waiting for
`ntmQuill`, so **both calls own an independent retry loop and there is no generation
guard between them** — unlike the payload path, which does carry one (`ntmEditId !== want`,
`forums.js:1962`). Whichever loop fires last wins. And the body of the seed is:

    ntmQuill.setContents([], 'silent');                    // clear FIRST, unconditionally
    if (html) { ...dangerouslyPasteHTML(html, 'silent'); } // paste ONLY if non-empty

So a late optimistic seed carrying an EMPTY body clears the authoritative content and
pastes nothing. `ntmGetContent()` (`forums.js:2060`) then returns `''`, and the submit at
`forums.js:2560` does `if (content) payload.content = content;` — the field is **omitted
entirely**, and bbPress rejects the whole edit.

**Discriminating test that proves it is this and not something else** — identical flow,
same seam, same waits, main's own assets, only the caller's `bodyHtml` differs:

| `bodyHtml` passed | result |
|---|---|
| `''` (empty) | 2/6 rejected, editor measured at 0 chars by the Review step |
| `'<p>NONEMPTY…</p>'` | **0/6 rejected**, editor 69 chars in all six |

**Who actually hits it.** Both production call sites pass the scraped OP —
`webroot/hub-polish.js:3624` and `bb-mirror/web/forums.js:4777`, both
`lgNtmEditTopic(tid, forumId, title, body.innerHTML)` — so `bodyHtml` is normally
non-empty and the common case is safe. The exposure is **posts whose rendered OP scrapes
empty** (image-only posts, or any door where the OP has not rendered when Edit is
pressed). Narrower than "one edit in three", but real, and it fails in the most alarming
way available.

**Second, unmeasured half — worth the owner's attention.** Even with a NON-empty scrape,
a late optimistic seed substitutes the RENDERED body for the STORED one, and the two are
not the same document: the optimistic path strips images (`forums.js:1932`). If that seed
lands last and the member saves, the rendered/stripped version could be written over the
stored body. Not observed and not measured — flagged because it is the same race with a
worse outcome than a refused save.

**Suggested fix (not started, owner's call).** Give the seeds a generation counter like
the payload path already has, so a superseded optimistic seed cancels instead of firing
late; and/or make `ntmSeedBody` not clear when it has nothing to paste.

**Repro.** `tools/edit-post-parity/photo-add-remove.py` documents it in its header and
trips it about a third of runs. The isolating harness (empty-vs-non-empty seed, branch
vs main) is in the lane's scratch and can be moved into `tools/` on request.

**SEPARATE and still unexplained:** `Error: Cookie check failed`
(WP `rest_cookie_invalid_nonce`) on save, seen 2/6 in the non-empty run **with a healthy
69-char body**, so it is NOT this bug. The intercepted PUT carried a valid 10-char nonce
and a logged-in cookie. Unowned, unreproduced on demand.

**Also spotted, small and independent:** `bb-mirror/web/forums/_search.php:66` joins the
forum for reply hits through the denormalised `r.forum_id` and then filters
`f.visibility = 'public'` on it — i.e. the visibility check runs against the forum a
moved thread LEFT. Live is clean today (0 public→non-public moves among the 70 drifted
topics, measured read-only 2026-07-30), so there is no leak now, but the guard is luck
rather than construction. Joining through `t.forum_id` would make it structural.

## 2026-07-29 — Edit post must equal Add post (Ian)

The edit-post modal today offers only title, body text, and image removal. It
must be fully functioning like the original add-post modal: pick forum, add
tags, and everything else the composer offers. Ian's stated preference: **reuse
the add-post mechanic completely** rather than maintaining a second, lesser
modal — open the same composer pre-filled with the post being edited.

Acceptance sketch: editing a post exposes every control that creating one does
(forum picker, tags, formatting, images/embeds), through the same code path as
add-post; saving preserves anything edit doesn't touch (replies, reactions,
timestamps beyond modified). Unowned — needs a lane.

**Status 2026-07-30 — BUILT AND PROVEN; the API half is already merged to main
(7825a27, Ian "looks good"). Remaining delta is two JS files on branch
`edit-post-parity`. Awaiting keeper's dev2-serve verification, then merge.**

Ian's rulings that shaped it, both binding:
- **The desktop/mobile split STAYS.** Edit reuses the composer CREATE uses *on the
  same viewport* — desktop edit opens the 4-step wizard on Write, mobile edit opens
  the same flat form mobile create opens. Parity is per-viewport, not one composer
  everywhere. Proven through the real controls, not the seams:
  `tools/edit-post-parity/create-edit-parity.py`, 39/39, both viewports.
- **Per-topic drafts STAY (2026-07-30).** An unfinished reply comes back when you
  return to that topic. This is NOT the stale-composer bug that was fixed alongside
  it — see COMPOSER-V2-PLAN §1.2 for the table of which is which, and do not
  "fix" the draft restore.

Saving verified in the DB rather than the UI, across a real forum round trip
(3837 → 3823 → 3837): replies, reactions, the activity row, `post_date`, author,
counts and every reply row held; the move carried both replies' `_bbp_forum_id`,
both forums' counters and the postgres mirror with it.
