# Front-end compose, with an easy form — SCOPE

**Lane:** admin-edit-any (re-scoped 2026-07-31), branch `admin-edit-any`
**Ian, verbatim:** *"I can currently edit on the front end. That is fine. I need to be
able to compose on the front end with a easy front end form."*
**Status:** SCOPE ONLY. No feature code. Ian picks the slice before any build.

Supersedes the editing scope in `docs/ADMIN-EDIT-ANY-SCOPE.md`, which answered the
question as it was originally put to this lane. Two findings from it still stand and are
carried forward here: the dead Elementor form estate (§1.3 there, §2.2 here) and the
capability analysis, which turns out to have the **opposite** shape for create than it
had for edit (§6).

Everything below is measured. `tools/admin-edit-any/compose-probe.sh` re-runs it.

### RE-VALIDATED 2026-07-31 22:40 UTC against `origin/main` @`c259885` — 96 commits on

The scope was measured against a tree that main has since moved 96 commits past, and Ian
is being asked to decide from it. Re-checked rather than assumed. **Every load-bearing
finding holds; two citations had drifted and are corrected.**

| Claim | Re-measured | Verdict |
|---|---|---|
| The easy forms are alive | 51 acf-field-groups, **42 active**; `Add Post - Loothprints` and `Add Post - Event` both `publish` | HOLDS |
| The renderer is alive, Elementor-free | `acf_form()` **YES**, `acf_form_head()` **YES**, ACF **6.7.1** | HOLDS |
| The synthesis pipeline | `lg-layout-v2` `Plugin.php` / `MetaBox.php` **untouched** by all 96 commits | HOLDS |
| Capability inverts for create | dev2 **1824** users, **1820** hold `edit_posts`, **1819** `publish_posts` — unchanged | HOLDS |
| Correct edit cap still discriminates | `loothprint`→`edit_others_posts` via the live registry | HOLDS |
| Backlog cross-reference | front-end authoring is still item **#16** | HOLDS |
| Gate numbering | main's `run-all.sh` now carries gates **1–14**; `compose-gate.py` is held out and **unnumbered**, so it cannot collide | HOLDS |
| No lane collision | the 5 backlog items added since (mobile embed, craft-gate finder, sitemap/SEO, anon nav overlay, shop planner) touch no compose surface | HOLDS |

**The two drifts, both in the SUPERSEDED edit doc's §1.1 and both now fixed there.**
main's `forums.js` grew 318 lines and pushed the discussion-edit entry point **+222**:
`4972-5005` → **`5194-5227`**. `webroot/hub-polish.js` moved **+7**: `3591-3645` →
**`3598-3652`**. This is worth more than tidiness: at the *old* line, main's `forums.js`
now holds the follow-bell markup, so anyone following the stale citation reads working
code as if the edit door had been deleted — the same "verify the thing, not the thing
next to it" trap in a new disguise. `forums.js:1923 ntmOpenForEdit` and `:2012` are
**unmoved**, and `buildNtmWizard` is at **2106** as documented.

A rebase onto main is therefore a prerequisite for the build, but nothing in the scope's
conclusions or its recommended slice changes.

---

## TL;DR

**This is much smaller than the last scope implied, for the two types that matter first,
and it is that small for a specific reason: the easy forms already exist, and so does the
renderer that turns them into pages. Only the front door is missing.**

1. **One front-end compose path exists today: the discussion wizard.** Nothing else has
   a working front-end create form (§1).
2. **The per-type "easy forms" are already written** — as ACF field groups, with
   member-facing labels and instructions, designed for exactly this ("Add one or more
   image(s) of your print in action", "Creative Commons Use License (leave default if
   unsure)"). And `acf_form()` — ACF Pro's own front-end form API, active, Elementor-free —
   renders the loothprint one **today**: 23KB, real form, nonce, every field type it needs
   (§2.3). We are not building a form renderer.
3. **For loothprint and event the whole pipeline already exists.** layout-v2
   *synthesizes* the standalone page from exactly the postmeta those forms collect. Proven
   end to end on a throwaway draft: a post carrying only what the form writes produced the
   full 9-block page, and `manages()` confirmed v2 renders it (§3).
4. **Video, article and sponsor are a different job** — their pages are built from a
   hand-authored layout blob, not synthesized, so a form alone yields a post with no page
   (§3.2). Don't put them in the same slice.
5. **Recommended first slice: loothprint, then event** (§4). Ian's own backlog names
   loothprint as the near-term ask, and the data agrees — 38 distinct authors, 47 posts
   last year.
6. **The capability check inverts, and this one is not latent.** For editing,
   `edit_post` discriminated perfectly. For creating, the natural cap — `edit_posts` —
   is held by **1,820 of 1,824 users on dev2**, and by `subscriber` and every `looth`
   tier on **live** too. Gate a compose form on it and essentially the whole membership
   can publish (§6).

**Yes, this is backlog #16** — phases 1 and 2 of it, in its own words. §5 says so plainly
and names the slice.

---

## 1. What front-end compose exists today, per type

### 1.1 The one that works: discussions

| Piece | File:line |
|---|---|
| The form markup | `bb-mirror/web/_chrome.php:317-402` — `#ntm-form` |
| Its controls | forum radio-list `:336`, title `:362`, Quill body `:366`, tags `:372`, quick-tags `:377`, anonymous toggle `:387`, submit `:392` |
| Desktop wizard shell | `bb-mirror/web/forums.js:2106` `buildNtmWizard()` |
| Open triggers | `bb-mirror/web/forums.js:2524-2527` — any `[data-ntm-open]`; feed button at `bb-mirror/web/forums/_feed.php:1340` |
| Mobile entry | `webroot/bottom-nav.js:330` `openComposer()` fires the same trigger |

Six controls, one post type.

### 1.2 Everything else: nothing

`post-type-videos`, `post-imgcap`, `event`, `loothprint`, `sponsor-post`, `shorty` have
**no working front-end create path**. There is no CPT write endpoint in the monorepo
(inventory in the superseded doc, §1.2).

### ⚠️ SMALL BUT LOAD-BEARING: §2.1's "mobile is already the flat form" IS STALE

§2.1 argues for the single screen partly on the grounds that `buildNtmWizard()`
returns null below 641px, so "the mobile experience is already the flat form, and
it is the one Ian's members mostly use." Photographed on a phone today
(`/hub/?compose=1`, 390px), the composer shows **"STEP 1 OF 4 · TITLE & FORUM"**
with its own Cancel/Next header — mobile is stepped, by some path that is not
`buildNtmWizard` (grepping `TITLE & FORUM` finds nothing in `webroot/` or
`bb-mirror/web/`, so it comes from elsewhere and I have not chased it).

It does not change the ruling — Ian chose the single screen for loothprint on its
own merits — but it removes one of the arguments the scope used to get there, and
anyone re-deriving that argument from §2.1 would be building on a stale fact.

---

### ⚠️ THIRD PASS, SAME DAY — I OVERCLAIMED IN THE SECOND, AND HERE IS THE WITHDRAWAL

The second correction said members "actually use" the eight live pages, and told
Ian the two-tier gate is therefore a RESTRICTION on today rather than a new limit.
**That is not supported and I have withdrawn it**, on his page and here.

What broke it: the member-authored posts do not look like form submissions.
Checking `post-type-videos` #72508 (posted 2026-08-08 by an ordinary member) and
the four next-newest, all show the same signature:

* **2 of the 9 fields** the video field group collects — only `video_category` and
  `content_category_`. A real ACF submit writes every rendered field, empties
  included, plus its `_fieldname` key-mirror.
* **no `_edit_last`, no `_edit_lock`, zero revisions** — nobody edited them in
  wp-admin either.
* a full `_lg_layout_v2` blob (23KB, ten blocks, *including a transcript*) while
  the group's own `transcript_` field is **absent from the post**. The content is
  in the blob, not in the fields.

That is the signature of something creating these programmatically and crediting
the member, not of a member filling in a form. The AUTHORSHIP counts in the second
correction stand; the inference about HOW those posts were made does not.

**AND THEN I WATCHED IT HAPPEN.** Mid-investigation, `post-type-videos` went 368 →
369. The new row, #72766 "Electric Guitar Builders Club: Curved Frets", was
authored by `patreon_94888385`, carried `_thumbnail_id`, `_lg_layout_v2` and
`patreon-level` and **not one ACF field**, and was created on a `lg-wp-cron.timer`
tick (that timer fires every minute and runs due WP cron events; the WP cron array
holds `lgpo_patreon_auto_sync`). Nobody was at a form: the only submission in that
window was mine, as a different member, with a different title, and it created
nothing.

So the answer to "what writes `_lg_layout_v2` for a new member-attributed video" is
**the scheduled Patreon sync**, not a human and not a member. I have not pinpointed
the exact writing function — the repo grep still only shows `lg-legacy-import`
Cli.php:187 and EditorButton.php:290 — so the last inch is unproven, but the
pipeline is not in doubt.

⚠️ **A TIMING TRAP inside this, worth the line:** WP's clock on this box runs FOUR
HOURS behind server local (`date` said 17:54, `current_time('mysql')` said 13:54).
I first read #72766's 13:53 timestamp as "hours ago, before my probe" and nearly
filed it as unrelated background. It was forty seconds old. Compare post
timestamps against `current_time()`, never against `date`.

**So the live question is narrower and still open: do those eight forms actually
submit?** Rendering is proven. Submitting is not.

⚠️ **AND MY ATTEMPT TO SETTLE IT WAS THE WRONG SHAPE — do not repeat it.** I POSTed
the form's own hidden fields back to `/add-video-post/` as an ordinary member:
HTTP 200, zero posts created, and the response did not echo the submitted title.
That reads like "the form refuses members" and it means nothing of the sort.
frontend-admin submits over **admin-ajax.php** (`wp_ajax_fea`, `acf_frontend`,
`frontend_admin`), so a plain page POST is simply never handled. A null result from
the wrong channel is not evidence of a closed door.

Settling it means reproducing frontend-admin's AJAX contract — its own action,
nonce and payload shape — and force-deleting whatever it creates. That is a
contained job but a real one, and it is **not on the critical path**: the two-tier
gate is correct in both worlds (it closes an old hole, or it prevents a new one),
and nothing in the loothprint slice depends on the answer.

---

### ⚠️ SECOND CORRECTION, 2026-08-09 — §1.3 IS WRONG ABOUT ALL NINE PAGES, AND §6 DESCRIBES THE STATUS QUO

The first correction below found that `/add-your-own-loothprint/` is alive. Having
been wrong once in that direction, I checked the rest rather than assuming the one
was an exception. **Eight of the nine are live**, measured as an ordinary member
(`bangers`), each rendering a real ACF form with real field keys and `_acf_form`:

| page | forms | inputs | distinct field keys |
|---|---|---|---|
| add-your-own-loothprint | 1 | 95 | 21 |
| add-video-post | 1 | 88 | 20 |
| add-image-caption-post | 1 | 104 | 27 |
| add-post-shorty | 1 | 25 | 10 |
| add-post-regular | 1 | 49 | 5 |
| add-your-own-loothcut | 1 | 76 | 30 |
| add-post-useful-link | 1 | 17 | 5 |
| sponsor-add-sponsor-post | 1 | 18 | 4 |
| add-new-content | 0 | 0 | 0 — genuinely dead |
| /user-post-dashboard/ | 0 | 0 | 0 — genuinely dead |

⚠️ **A DETECTION TRAP, recorded because I fell into it in the same session.** My
first sweep reported all nine dead, because I grepped for `acff[field_…]` while the
real markup is `acff[file_data][field_…]`. That is the same class of error as the
original measurement it was correcting — a pattern that cannot match, reported as
an absence. Detect with several independent signals (`<form`, `<input` count,
`field_[0-9a-f]{13}`, `_acf_form`) and disbelieve a clean zero.

**AND MEMBERS ACTUALLY USE THEM.** Posts by authors WITHOUT `edit_others_posts`:

| type | member posts (24mo) | last 90d | newest |
|---|---|---|---|
| post-type-videos | 182 | 22 | **2026-08-08** |
| loothprint | 94 | 6 | 2026-07-19 |
| post-imgcap | 36 | 7 | 2026-07-26 |
| sponsor-post | 17 | 3 | 2026-06-08 |
| event | 8 | 0 | 2026-03-01 |

A video posted **yesterday** by an ordinary member, and those accounts carry recent
`last_login` timestamps, so this is not an import artefact.

**WHAT THAT DOES TO §6.** §6 warns that gating a compose form on the natural
capability would let "essentially the entire membership publish a video, an
article or a sponsor post, live, unreviewed… ~1,850 live accounts on day one."
**That is not a risk this build would introduce. It is the situation today.**
The two-tier gate is therefore a RESTRICTION relative to the status quo, not a
liberalisation — which is the opposite of how the scope frames the decision, and
Ian should be told that before he rules on the allow-list.

⚠️ **NOT PROVEN, and it matters:** that those posts were created *through those
pages*. Members are redirected out of `/wp-admin/` pages (measured: 302 for a
member, 200 for an admin) while `admin-ajax.php` answers 200 for both, so the
front end is the plausible route — but I have not traced a single post to a page.

**§3.2 ("video/article/sponsor need a layout generator") — STILL STANDS, and the
operational cost is worse than it reads.** Every member-authored video (90),
article (13) and sponsor post (9) in the last 12 months carries a `_lg_layout_v2`
blob and is managed by v2 — including yesterday's, a 23KB 10-block layout with a
transcript. So they are not pageless. But only two code paths write that meta —
`lg-legacy-import/src/Cli.php:187` and `EditorButton.php:290` — and neither is an
automatic on-save hook. A new post cannot have come from the importer. So either
someone materialises these by hand after each submission, or there is a path I did
not find. **Named, not resolved.** If it is the former, "build a generator" is not
new work so much as replacing a person.

---

### ⚠️ CORRECTED 2026-08-09 BY THE BUILD LANE — §1.3 AND THE COPY CLAIM ARE WRONG

Two claims this scope rests on did not survive contact with the build. Both are
corrected in place rather than quietly worked around, because the mock Ian
approved repeats them and he is entitled to know what he was told.

**1. `/add-your-own-loothprint/` IS NOT DEAD.** §1.3 says the nine "Add Post"
pages return "188 KB of instructional prose and **zero form inputs**". Measured
today as an ordinary member (`bangers`), that page returns a **working ACF form**:
95 inputs, the real field keys (`field_6547dafd3f5d6` = `loothprint_more_images`,
`field_6547dc013f5d7` = `loothprint_3d_file`, `field_6564e26df56ba` = the
licence), the real labels, and frontend-admin's `acff[...]` naming. A member can
post a Loothprint through it right now.

What is true is that it is **unusable, not absent**: unstyled browser defaults,
no platform chrome, and **11,353 px tall at 1280 px** — 23,896 px on a phone —
with every licence explained twice and a wall of help text under the form.
Photographed at `/footer-mockups/frontend-compose-build/`.

This matters to how the work is described. "Only the front door is missing" is
the wrong framing: the door exists and it is horrible. The build is a
**replacement**, and the honest headline is 11,353 px → 1,695 px, not
nothing → something. It also means there is an existing surface to retire or
redirect once the flag goes on — which this lane has NOT done and which nobody
should assume is handled.

Why the original measurement disagreed is not established. It was taken "as a
real admin" and this one as a member, so an admin-only difference is plausible
but unproven — named rather than guessed at.

**2. THE MOCK'S COPY IS NOT THE EXISTING COPY.** The mock's lede claims "every
label and hint below is the copy that already exists for this post type", and its
evidence table cites two ACF strings that appear nowhere in the drawn form.
Checked field by field against the live group: essentially every label is new
wording. The mock drew the *spirit* of the ACF copy, not the copy.

The build ships the mock's words — those are the ones Ian ruled on — with each
ACF original recorded beside it in `lg_fc_types()` and the full swap drawn for
him side by side on the comparison page. Reverting any row is a one-line edit.

---

### 1.3 The estate that looks like one (carried forward — this is still the main trap)

WordPress holds nine front-end "Add Post" pages (`add-your-own-loothprint`,
`add-video-post`, `add-image-caption-post`, `add-post-shorty`, `add-post-regular`,
`add-your-own-loothcut`, `add-post-useful-link`, `sponsor-add-sponsor-post`,
`add-new-content`) plus a `/user-post-dashboard/`. **They are dead**: their forms were
Elementor widgets stored in `_elementor_data`, and Elementor is inactive on dev2 and
absent from live's `active_plugins`; both boxes are on `twentytwentyfive`. Measured — a
fetch of one as a real admin returns 188 KB of instructional prose and **zero form
inputs**.

**But the pages being dead does not mean the forms are lost.** The Elementor widget was
only ever a *renderer* pointed at an ACF field group. The field groups are alive, active
and unchanged — which is the whole reason §2 is cheap.

---

## 2. What "an easy form" means, against what exists

### 2.1 Is the discussion wizard the model to generalise? No — its flat form is.

The wizard is **not** a general mechanism. `buildNtmWizard()` (`forums.js:2106`):

- is **desktop-only** — `if (!window.matchMedia('(min-width:641px)').matches) return null;`
  at `:2107`. On a phone it returns null and you get the flat form, all controls on one
  scroll. **The mobile experience is already the flat form**, and it is the one Ian's
  members mostly use.
- is **hand-wired to six specific element IDs** (`:2129-2141`) — it captures
  `#ntm-forum-label`, `label[for=ntm-title-in]`, the Quill mount's previous sibling,
  `#ntm-content`, `#ntm-tags`, `#ntm-quicktags`, `.ntm-anon`, `.ntm-row` and physically
  moves those nodes into four panes. There is nothing schema-driven about it. Pointing it
  at another post type means rewriting it, not configuring it.
- adds **three clicks to a five-field form**, and Ian already has an open complaint about
  that modal (backlog #10: *"resizable, with text scaling"* — he finds it cramped).

So: generalising the *wizard* would be building a bespoke thing a second time. **The flat
single-screen form is the model** — which is convenient, because that is also what
`acf_form()` produces.

### 2.2 The easy forms are already written, per type

These are not hypothetical field lists. They are the ACF groups behind the old front-end
pages, written for members in members' language:

**Add Post – Loothprints** (12 fields, **6** required — Title, the gallery, the 3D file,
Type, Content Topic, and the licence, which ships with a sensible default already
selected. The mock asks for **five** things because it puts Type and Content Topic in one
question; that is a design choice in the mock, not a property of the field group.)
Title of your Loothprint\* · Summary · Featured image · *"Add one or more image(s) of
your print in action"*\* · **3D File Upload ZIP File**\* · Video instructions for
use/build · Onshape Project Link · Type of Loothprint\* · Content Topic\* · *"Link to
your Buy Me A Coffee or other 'leave me a tip' site (optional)"* · *"Creative Commons Use
License (leave default if unsure)"*\* · Commenting

**Add Post – Event** (7 fields, 2 required)
Event Tier · Start Date\* · Time Of Event\* · Featured Image · Zoom URL for Virtual
Event · Region · Language

That copy is the "easy" Ian is asking for, and somebody already did the work of writing
it. Re-authoring these from scratch would be throwing away the best asset in this item.

### 2.3 And the renderer exists, and is Elementor-free — MEASURED

Both of these are active right now and neither needs Elementor:

- **`acf_form()` / `acf_form_head()`** — ACF Pro's own front-end form API
- **`[frontend_admin]` and `[acf_frontend]` shortcodes** — frontend-admin-pro's
  non-Elementor path

Rendering "Add Post – Loothprints" through `acf_form()` as an admin, right now:

```
acf_form() rendered bytes: 23116
fields rendered: 22
has form tag: YES    has nonce: YES
field TYPES present: text, wysiwyg, featured-image, gallery, file, oembed,
                     url, taxonomy, radio, allow-comments
```

Every control the type needs — including the gallery, the 3D-file upload and the
oembed — renders. **The "build an ACF control renderer for the front end" cost from the
previous scope is zero.** It was already paid.

---

## 3. Does a form-created post actually become a page?

This is the question that separates the types, and it is the one that decides the slice.

### 3.1 loothprint and event: yes, already, end to end

`lg-layout-v2/src/Plugin.php:257` — six CPTs are **synthesized**:

```php
$synth = ['event', 'loothprint', 'loothcuts', 'useful_links', 'document', 'member-benefit'];
```

with the comment *"Synthesized CPTs get a default layout built from postmeta at render
time, so any published post of these types is managed — no explicit `_lg_layout_v2` meta
needed."*

`default_loothprint_layout()` (`Plugin.php:344-407`) reads:
`post_content`, `loothprint_more_images`, `loothprint_3d_file`,
`loothprint_video_instructions`, `loothprint_onshape_link`,
`loothprint_creative_commons`, `loothprint_buy_me_a_coffee`, featured image, tier.

**Every one of those is a field the form collects, by the same name.** The form and the
page-builder were built against one data model.

Proven end to end on a draft created, measured and force-deleted inside a single call —
never published, so it never reached the feed or the mirror:

```
draft #72415 created
synthesized 9 blocks from ONLY what the form collects:
   - post-header
   - wysiwyg
   - gallery
   - embed
   - callout:files
   - callout:links
   - callout:note
   - callout:links
   - post-footer
manages() once published: YES — v2 renders it
draft #72415 force-deleted: gone
```

The download callout also picked up `[gated: looth-lite]` automatically from the post's
tier — so the paywall behaves without the author touching anything.

### 3.1b event: also yes — but it is proven a DIFFERENT WAY, and that matters

Event was originally recommended as the second type on the strength of being in the
same `$synth` list. That is true but it is not sufficient, and checking it properly
turned up a methodological trap worth recording.

**`default_event_layout()` reads almost nothing.** It touches exactly one field —
`zoom_url_for_looth_group_virtual_event`, and only to *strip* it out of the body, so
that the only route to the Zoom link is the gated CTA rather than the public prose.
It emits `post-header → event-header → wysiwyg → post-footer`.

The date, the time, the Zoom CTA and the region are consumed **by the `event-header`
block renderer**, not by the synthesizer (`blocks/event-header/render.php:43-53`).

> **So the method that proved loothprint would have produced a FALSE NEGATIVE for
> event.** Reflecting on the synthesizer shows almost no field consumption and would
> read as "event does not work". The consumption is one layer further down. Anyone
> re-verifying this — including me, later — has to render the block, not inspect the
> layout function. This is the box's own "verify the thing, not the thing next to
> it" rule, arriving in a new disguise.

Proven the right way, on a throwaway draft, through ACF's real save handler and then
by rendering the actual block:

```
  events_start_date_and_time_                '20260815'
  time_of_event                              '19:30:00'
  zoom_url_for_looth_group_virtual_event     'https://zoom.us/j/PROBE'

  event-header rendered 706 bytes
  month    PRESENT   AUG
  day      PRESENT   15
  time     PRESENT   7:30 PM
  CTA      PRESENT   Join on Zoom
```

Field names line up exactly with what the form collects — `events_start_date_and_time_`
(trailing underscore and all), `time_of_event`,
`zoom_url_for_looth_group_virtual_event`, `region`. So **event stands as the
recommended second type**, on evidence rather than on list membership.

One loose end, flagged not hidden: the form also collects `language_`, and I did not
find a consumer for it. Harmless — an unread field — but it means the event form has
one control that currently does nothing, and the build should either wire it or drop
it from the form rather than ship a dead input.

**A probe bug worth recording too**, because it reported healthy code as broken: the
first render attempt returned 0 bytes and looked like "the block does not work for a
form-created event". The block reads `$ctx['post_id']`; passing a bare `$post_id` is
useless because line 19 overwrites it with `(int) ($ctx['post_id'] ?? 0)` = 0, so no
meta was read and the block's own "nothing resolvable → don't emit an empty box"
guard fired. The harness was wrong, not the code.

### 3.2 video, article, sponsor, shorty: no — their pages are hand-built

Not in `$synth`. Their standalone page comes from an explicit `_lg_layout_v2` block
document that a human authors. The difference is visible in the data — blob sizes:

| type | synthesized? | blob size | shape |
|---|---|---|---|
| loothprint | **yes** | 864–1294 bytes | formulaic: header, writeup, images, download, links, footer |
| post-imgcap (article) | no | 2163–13089 bytes | bespoke editorial prose, per post |

A loothprint's layout is near-templatable, which is exactly why it *can* be synthesized.
An article's isn't. A front-end form for article/video/sponsor would produce a post with
**no page** unless a blob generator is built too — a real, bounded, but separate job.

### 3.3 Readiness table

| Type | Synthesized | ACF form exists | Front-end compose is… |
|---|---|---|---|
| loothprint | ✅ | ✅ 12 fields | **wiring** |
| event | ✅ | ✅ 7 fields | **wiring** |
| loothcuts | ✅ | ✅ 13 fields | wiring |
| member-benefit | ✅ | ✅ 10 fields | wiring |
| document | ✅ | ✅ 4 fields | wiring |
| useful_links | ✅ | ❌ none | wiring + a field group |
| post-type-videos | ❌ | ✅ 9 fields | needs a layout generator |
| post-imgcap | ❌ | ✅ 3 fields | needs a layout generator |
| sponsor-post | ❌ | ✅ 5 fields | needs a layout generator |
| shorty | ❌ | ✅ 3 fields | needs a layout generator |

---

## 4. Which type first

**Loothprint, then event.** Not all six, and not video/article despite their volume.

- **Ian's own backlog already says so.** #16: *"Near-term ask: **add loothprints as a
  post type USERS can post from the front end**."*
- **The data agrees.** Distinct authors / posts in the last year:
  `topic` 386/426 (already has a composer) · `post-type-videos` 54/120 ·
  **`loothprint` 38/47** · `post-imgcap` 23/16 · `event` 10/14 · `shorty` 7/3 ·
  `sponsor-post` 6/11.
  Video is higher-volume but is contributor-published content, and #16 puts it in the
  **admin-gated** tier — a different gate and a different urgency. Loothprint is the
  highest-volume type in the *open* tier.
- **It is the cheapest and the most complete**, because it is synthesized (§3.1).
- **Event is the natural second**: also synthesized, only 7 fields, and its form is
  almost entirely date/time/place — the easiest possible second proof that the pattern
  generalises past one type.

Video / article / sponsor should be a **separate, later item** with the layout-generator
question scoped on its own. Saying otherwise would hide a real cost inside an easy one.

---

## 5. Is this backlog #16? Yes — and here is the slice

Backlog #16, *"Front-end authoring for all post types via layout-v2, gated ★ vision"*,
describes exactly this and phases it:

> *(1) loothprints front-end form first, (2) generalize the layout-v2 authoring surface
> to the front end per type, (3) the whitelist-based gate. Big item — Ian rules the
> phasing before any build.*

**What I am proposing is phase 1, plus the part of phase 3 that phase 1 cannot ship
without.** The honest framing:

- **Phase 1 (recommended now)** — loothprint front-end compose, then event. Small,
  because §2 and §3 are already built.
- **Phase 2 (separate)** — video/article/sponsor, gated by the layout-generator question.
  This is where "generalize the authoring surface" actually costs something.
- **Phase 3 (partly unavoidable now)** — the gate. §6 explains why some of it must land
  in phase 1 rather than after it.

The item's own instruction is that **Ian rules the phasing**, so this is a
recommendation, not a decision.

---

## 6. The capability check for CREATE — it inverts, and it is not latent

For **editing**, `current_user_can('edit_post', $id)` discriminated perfectly (previous
scope, §4). For **creating**, the equivalent natural check does not discriminate at all.

`create_posts` for loothprint, event, video, article and sponsor maps to **`edit_posts`**.
And on this platform:

```
role             edit_posts   publish_posts   upload_files
administrator    1            1               1
author           1            1               1
contributor      1            0               1
subscriber       1            1               1
looth1..looth4   1            1               1
bbp_participant  0            0               0

users holding edit_posts: 1820 of 1824   (dev2)
```

Confirmed on **live** as well — `looth3`, `subscriber` and `author` all carry
`edit_posts`, `publish_posts` and `upload_files`; 1,700 looth-tier members plus 150
subscribers out of 1,837 users.

**So a front-end compose form gated on the natural WordPress capability would let
essentially the entire membership publish a video, an article or a sponsor post, live,
unreviewed.** Unlike the editing escalation — which was real but latent, with an empty
affected set — **this one is ~1,850 live accounts on day one.**

This is not a misconfiguration to fix. Members *must* hold these caps to submit
loothprints at all. The correct reading is that **the capability system carries no usable
signal for who may create which type**, which is precisely why Ian's backlog already
says:

> *"**Prefer a USER WHITELIST over the WP `author` role** to decide who can post the
> gated types — explicit allow-list of trusted users, not a role grant."*

### What I would build

| Type tier | Types | Who may compose | What they get |
|---|---|---|---|
| **Open** | discussion, loothprint | any signed-in member (not blocked) | published |
| **Gated** | video, article, event, sponsor | explicit allow-list | published |
| **Everyone else, gated types** | — | not offered the form at all | — |

Two properties that make this safe regardless of how the list is administered:

1. **`post_status` is the real safety valve.** `acf_form()`'s `new_post.post_status` is
   ours to set. Anything from an author who is not on the allow-list should land as
   **`pending`**, never `publish`. Then even a gate bug is a moderation queue, not a
   live page.
2. **The server re-checks on submit**, on the stored post type, never on a client-supplied
   one — the same IDOR-proofing `reply.php:205` already documents for discussions. The
   client gate is only there to avoid showing a form that will be refused.

**Open question for Ian, and it is genuinely his:** who administers the allow-list, and
does a member-submitted loothprint publish immediately or land pending? I would default to
**publish for loothprint** (it is already the open tier, and members submit them today via
the dashboard) and **pending for everything gated**. Say if that is wrong.

---

## 7. What is actually left to build (loothprint slice)

Small, and deliberately boring:

1. A **front-end route** that calls `acf_form_head()` then `acf_form()` for the type —
   one page, behind the flag.
2. The **gate** from §6, server-side, plus the `post_status` rule.
3. **Chrome**: `acf_form()`'s default styling is admin-ish. Making it look like the
   platform, at 390px, is where "easy" is actually won or lost. This is the part that
   deserves the design attention, and it is what the mock is for.
4. **The flag**, defaulted OFF, copying `LG_AUTHOR_SOCIALS_ALL_MEMBERS`
   (`platform/mu-plugins/lg-author-socials.php`); flag-OFF a proven byte-identical
   no-op, and the OFF state gated.
5. **Gates**, red-first: a non-allow-listed member must not get the gated-type form or
   be able to POST to it; a signed-out visitor must get neither; and a submitted
   loothprint must render as a real page (§3.1's synthesis, asserted through the real
   submit rather than by reflection).

### The round-trip — CLOSED 2026-07-31, no longer an open risk

This section previously said the submit half was unproven. It is now measured, because
it was the one place the scope could have been wrong and it did not need Ian's decision.

The real risk was specific: **ACF forms post by field _key_ and ACF stores by field
_name_.** A mismatch anywhere in that mapping writes nothing at all, the page-builder
finds empty meta, and the whole pipeline silently produces a blank post — while every
individual piece still looks correct in isolation.

Driving ACF's actual save handler — `acf_save_post()`, the same one a submitted
`acf_form()` runs after nonce verification, posting by field key exactly as the form
does — on a throwaway draft:

```
META AS STORED BY THE REAL SAVE PATH, vs what the page-builder reads:
  post_content                     WRITTEN  A test jig for holding nut blanks while slot
  loothprint_more_images           WRITTEN  [72152,72153]
  loothprint_3d_file               WRITTEN  72154
  loothprint_video_instructions    WRITTEN  https://youtu.be/QAAh5wLQJhY
  loothprint_onshape_link          WRITTEN  https://cad.onshape.com/documents/example
  loothprint_creative_commons      WRITTEN  BY NC SA (Credit given to creator, Non-Comme
  loothprint_buy_me_a_coffee       WRITTEN  https://buymeacoffee.com/example

synthesizer built 9 blocks from the SUBMITTED values:
   post-header, wysiwyg, gallery, embed, callout:files,
   callout:links, callout:note, callout:links, post-footer

fields the page-builder reads that the save path did NOT write: 0
```

**Zero gaps.** Submit → meta → synthesized page is proven end to end, on the real save
path, for every field the renderer consumes.

What remains unproven is only what cannot be proven before the build exists: that our
own route calls that save path correctly, and that the gate refuses the right people.
`tools/gates/compose-gate.py` already asserts both, and is **red today by design**.

---

## 8. Reproducing

`tools/admin-edit-any/compose-probe.sh` re-runs every measurement here: the compose-path
inventory, the `acf_form()` render, the synth-vs-hand-authored split, the end-to-end
draft proof, the authorship counts and the create-capability population on both boxes.

---

## 9. Deploying this (written by the build lane, 2026-08-09)

A pull is NOT enough, because the mu-plugin symlink SET is not in the repo
(CLAUDE.md). In the same window as the pull:

```bash
git -C ~/loothplatformv2-clean pull --ff-only origin main
sudo ln -sfn /home/ubuntu/loothplatformv2-clean/platform/mu-plugins/lg-frontend-compose.php \
             /var/www/dev/wp-content/mu-plugins/lg-frontend-compose.php
```

Without the second line the pull leaves the feature absent — and gate 19 would
then report the flag-OFF no-op as GREEN, because "no plugin at all" and "plugin
loaded with the flag off" produce the identical 404. Confirm it is actually
LOADED before trusting that green:

```bash
sudo -n wp --allow-root --path=/var/www/dev eval \
  'echo function_exists("lg_fc_enabled") ? "loaded, flag=".var_export(lg_fc_enabled(),true) : "NOT LOADED";'
```

**To arm it for a look (dev2 only, box-local, untracked):**

```bash
# The flag now lives in the SHARED tracked config, read by both WordPress and
# bb-mirror. For a look, flip it there (and put it back), or use the lane preview
# at /preview/frontend-compose/ which arms it for that path only.
sed -i "s/'enabled' => false/'enabled' => true/" \
  ~/loothplatformv2-clean/platform/config/frontend-compose.php
```

Then `https://dev2.loothgroup.com/compose/?type=loothprint`. Delete that file to
disarm. The tracked default stays OFF until Ian says otherwise.

**The lane left the box clean**: no temp flag, and no symlink into its worktree.
A symlink from the running docroot into `~/worktrees/<lane>` breaks the moment the
worktree is removed, which is a worse failure than the feature being absent.
