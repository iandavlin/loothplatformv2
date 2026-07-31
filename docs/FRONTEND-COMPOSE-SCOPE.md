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

### Not proven yet, and I am naming it rather than hedging

§3.1 proves the meta → page synthesis on a post shaped like the form's output. It does
**not** yet prove a real `acf_form()` **submit** writes exactly that meta — the render is
measured, the round-trip is not. That is the first thing to prove in the build, and it is
the one place this scope could still be wrong.

---

## 8. Reproducing

`tools/admin-edit-any/compose-probe.sh` re-runs every measurement here: the compose-path
inventory, the `acf_form()` render, the synth-vs-hand-authored split, the end-to-end
draft proof, the authorship counts and the create-capability population on both boxes.
