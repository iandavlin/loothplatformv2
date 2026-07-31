# Admin can edit ANY post, full functionality — SCOPE

> ## ⚠️ SUPERSEDED 2026-07-31 — do not act on the recommendation below
>
> Ian re-scoped this lane the same day: *"I can currently edit on the front end. That is
> fine. I need to be able to **compose** on the front end with a easy front end form."*
> Editing was not the problem. **The live scope is [FRONTEND-COMPOSE-SCOPE.md](FRONTEND-COMPOSE-SCOPE.md).**
>
> Options A, B and C below were all about reaching an *editor*, and none of them should
> be built. Kept because three findings still hold and are cited from the new scope:
>
> - **§1.3, the dead Elementor form estate** — still the main trap in this area, and it
>   matters *more* for compose than it did for edit.
> - **§4, the capability analysis** — still correct for editing, and the new scope's §6
>   contrasts against it deliberately: the same reasoning **inverts** for create, where
>   the natural capability is held by 1,820 of 1,824 users.
> - **§2, the per-type control inventory** — the field lists were right; what this
>   document got wrong was assuming they would have to be *rebuilt*. They already exist
>   as ACF groups, and `acf_form()` renders them today.
>
> One correction worth stating plainly rather than leaving implied: this document
> concluded a front-end composer would be a multi-week program. For the two types that
> matter first — loothprint and event — that is **wrong**. It measured the controls but
> never checked whether a renderer for them already existed. It does.

**Lane:** admin-edit-any (branch `admin-edit-any`, worktree `~/worktrees/admin-edit-any`)
**Backlog:** P1 #6. Ian 2026-07-30: *"I need to be able to edit any post as admin as
well. Full functionality on any post editing as admin."*
**Status:** SCOPE ONLY. No feature code written. Ian rules the phasing before any build.

Everything below is **measured**, not inferred, unless a line says otherwise. Every
probe is reproducible from `tools/admin-edit-any/`.

---

## TL;DR

The ask is one sentence and the honest answer is three:

1. **There is exactly ONE working front-end composer on this platform, and it makes
   discussions only.** Nothing else has a front-end create OR edit path — not video,
   not article, not event, not loothprint, not sponsor. Measured on dev2, deduced on
   live (§2).
2. **"The full composer" for the other five types is 7–28 ACF controls plus a
   layout-v2 block body each** — none of which the discussion composer has, or could
   be taught without becoming a different program (§3). Composer unification is
   therefore a **hard prerequisite** for the literal reading of the ask, and it is
   backlog #16, not this item (§4).
3. **The capability check must be `current_user_can('edit_post', $post_id)`.** The
   existing `can_edit_others` flag — the obvious thing to reuse — hands every forum
   moderator edit rights over every article, video, event, loothprint and sponsor
   post. Proven, §5. This is the one part of the item that is unambiguous and cheap.

There is a real delivery available this week that gives Ian *full* functionality on
*every* type with **zero new save paths** — see §6 Option A. It is not the front-end
composer; it is the affordance that reaches the editor that already has every control.

---

## 1. What the edit path is today, per type

### 1.1 The one type that works: `topic` (discussion)

Merged today by edit-post-parity. Two entry points, one destination.

| Surface | File:line | What it does |
|---|---|---|
| Desktop discussion modal | `bb-mirror/web/forums.js:4972-5005` | builds an Edit button, gates it, calls `window.lgNtmEditTopic` |
| Mobile reply sheet (OP) | `webroot/hub-polish.js:3591-3645` | same button, same call |
| The composer itself | `bb-mirror/web/forums.js:1923` `ntmOpenForEdit()`, exported at `:2012` | opens `#ntm-form` — the 4-step Where/Write/Photos/Review wizard — pre-filled, landing on Write |
| Authoritative body load | `bb-mirror/web/forums.js:1958-1990` | `GET /bb-mirror-api/v0/reply?topic_id=` — the stored `post_content`, never the rendered DOM |
| Existing photos | `bb-mirror/api/v0/topic-media.php` | loaded as removable thumbs |
| Save | `bb-mirror/api/v0/reply.php:192-210` (PUT) | author-or-moderator, author read from the **stored** post (IDOR-proof) |

This is the path that had **two content-destroying defects fixed today** (`83db275`,
`ec90ffc`, `5cf2c73`). Both were in the seed/save round-trip: a flattened DOM scrape
overwriting real markup, and Quill's internal DOM serialised back as `post_content`.
Neither was visible on screen; both were found by reading the database after a real
Save. **Any generalisation of this path re-opens that failure class**, which is the
single strongest argument in this document against reusing it for other types.

### 1.2 The five types that have NO front-end edit path

`post-type-videos` (video), `post-imgcap` (article), `event`, `loothprint`,
`sponsor-post`.

They appear in the hub feed as **content cards** — `bb-mirror/web/forums/_feed.php:1432`
onward, markup at `:1480-1483`. The card carries `data-post-type` and `data-item-id`,
which is exactly what an edit affordance would need. It does **not** carry
`data-author-id` (the discussion card at `:1647` does) — so today the feed cannot even
tell you whose post it is without another read.

There is **no CPT write endpoint anywhere in the monorepo.** Full API inventory:

- `bb-mirror/api/v0/` — `reply.php` (topics + replies), `topic-media.php`,
  `set-forum-image.php`, `follow.php`, `auth.php`, `topic.php`, `unread.php`,
  `mark-seen.php`, `seo-redirect.php`, `_sync.php`, `_mention-ingest.php`
- `archive-poc/api/v0/` — reads, plus writes for *engagement only*: `like.php`,
  `card-react.php`, `comment-post/edit/delete.php`, `save-post.php`
  (`save-post.php` is bookmark-a-card, not save-a-post — header comment, line 2)

Nothing writes a CPT. Nothing reads one for editing.

### 1.3 The dead estate — and why it looks like a path but isn't

WordPress holds a **complete set of front-end Add Post and Edit Post pages**, built on
`frontend-admin-pro` (active on both boxes) inside Elementor:

```
add-your-own-loothprint (20157)   edit-loothprint (17323)
add-video-post (7270)             edit-post-edit-video-post (39166)
add-image-caption-post (15394)    edit-image-caption-post (15429)
add-post-shorty (30356)           edit-post-shorties (38343)
add-post-regular (18519)          edit-regular-post (18530)
add-your-own-loothcut (7166)      edit-loothcut (20166)
add-post-useful-link (29804)      edit-useful-link (30327)
sponsor-add-sponsor-post (33512)  edit-post-edit-your-sponsor-post (66621)
                                  edit-post-edit-your-sponsor-product (57898)
```

Every edit page uses the same contract — `?post_id=<ID>`, `post_to_edit=url_query` —
and `administrator` is in every one of their `by_role` allow-lists. On paper this is
the entire feature, already built, needing only a link.

**It is dead.** Measured:

- **Elementor is `inactive` on dev2** (`wp plugin list`) and **absent from live's
  `active_plugins`** row. Both boxes run the `twentytwentyfive` block theme.
- The form widgets live in `_elementor_data` postmeta. With Elementor off, that meta
  is never rendered.
- Fetched `/edit-loothprint/?post_id=72155` on dev2 as a real admin (WP auth cookie,
  gate token, `--resolve` past Cloudflare): **188 KB of page, 0 `elementor-widget-`
  nodes, 0 `acf-form-data`, 0 `acf-field` inputs.** Instructional prose renders; the
  form does not exist. Same for a real non-admin member.
- On live the page 302s anon to `wp-login.php` (BuddyBoss members-only), so it is
  **not directly measured there**. The root cause is a DB fact — Elementor is not in
  live's `active_plugins` and Elementor content cannot render without Elementor — so
  it is almost certainly equally dead. *Treat as deduced, not proven.* Ian can settle
  it in one click while logged in.

**Do not cost this feature as "turn Elementor back on."** Re-activating a page builder
that the theme cutover retired, on both boxes, to resurrect ~17 pages of stale
Elementor markup, is a larger and riskier change than building the affordance in §6 —
and it would drag every one of those pages' layouts back into the live theme.

### 1.4 Summary table

| Type | Front-end CREATE | Front-end EDIT | Body model | ACF fields | Taxonomies |
|---|---|---|---|---|---|
| `topic` discussion | ✅ `#ntm-form` wizard | ✅ (today, `lgNtmEditTopic`) | `post_content` | 0 | 1 (`topic-tag`) |
| `loothprint` | ❌ (dead page) | ❌ (dead page) | layout-v2 (168/170) | 28 | 5 |
| `post-type-videos` | ❌ (dead page) | ❌ (dead page) | layout-v2 (358/361) | 28 | 6 |
| `post-imgcap` article | ❌ (dead page) | ❌ (dead page) | layout-v2 (66/72) | 20 | 6 |
| `sponsor-post` | ❌ (dead page) | ❌ (dead page) | layout-v2 (17/18) | 7 | 3 |
| `event` | ❌ none at all | ❌ none at all | layout-v2 (15/84) | 11 | 4 |

layout-v2 counts = posts carrying `_lg_layout_v2` meta / total published.

---

## 2. What "full functionality" costs, per type

"Every control the create flow offers" is not a figure of speech — the controls are
enumerable. These are the primary ACF create groups, verbatim:

**Loothprint** (12 in the primary group, 28 across all groups)
Title `post_title`* · Summary `post_content` · Featured image* · Gallery of the print
in action `gallery`* · **3D file upload ZIP** `file`* · Video instructions `oembed` ·
Onshape project link `url` · Type of Loothprint `taxonomy`* · Content Topic
`taxonomy`* · Buy-Me-A-Coffee link `url` · **Creative Commons licence** `radio`* ·
Commenting toggle

**Video post** (9 primary, 28 total)
Title* · Video featured image* · Video URL/description · Legacy related-links radio ·
**Transcript `wysiwyg`** · **Related video links `repeater`** · Video category
`taxonomy`* · Content category `taxonomy`* · Published date

**Article** (3 primary, 20 total)
Add-repeater-fields toggle · **Sections `repeater`** (the article body itself) ·
Category `taxonomy`*

**Sponsor post** (5 primary, 7 total)
Title* · Publish-on `post_date` · Content* · Featured image* · Comments radio

**Event** (7 primary, 11 total)
Event tier `taxonomy` · **Start date** `date_picker`* · **Time of event**
`time_picker`* · Featured image · Zoom URL · Region `taxonomy` · Language `taxonomy`

*Shared across most types on top of the above:* excerpt, PDF viewer (3), tag edit,
extra-content parts (2), related links (2), files (2), gallery (2), Buy-Me-A-Coffee
(2), change author, change post type, paywall/tier, free-content block (3).

### Is the discussion composer reusable?

No. The wizard offers **forum picker, title, Quill body, photo tray, tags** — five
controls, over a plain `post_content` body, against a bbPress-shaped API
(`bbp_media`, `topic-tag`, forum-as-`post_parent`).

Reusing it for any content type means building, from nothing:

- a **CPT read+write API** with per-type validation (does not exist)
- an **ACF control renderer** on the front end for `gallery`, `file`, `oembed`,
  `repeater`, `date_picker`, `time_picker`, `wysiwyg`, `taxonomy`, `featured_image`,
  `post_date` — the repeater and file/gallery fields being the expensive ones
- a **layout-v2 block editor on the front end**. Today layout-v2's authoring UI is a
  **wp-admin meta box** (`lg-layout-v2/src/MetaBox.php:39-46`); there is no front-end
  block surface at all, and the backlog notes its *backend* add-block UX is itself
  being fixed right now.
- per-type **taxonomy pickers** (3–6 each) and tier/paywall handling

Rough shape, and I want to be honest that this is an estimate and not a measurement:
the API + control renderer is the bulk, then roughly a type at a time. Multi-week, and
it lands squarely on top of a layout-v2 editor that is mid-repair. **It is a program,
not a task.**

---

## 3. Is composer unification a prerequisite?

**For the literal reading of the ask — full front-end composer, every type: YES,
strictly.** There is no half of it that can be built around. You cannot edit a
loothprint "fully" on the front end without the front-end loothprint composer, and
that composer does not exist in any form, for create or edit.

And that composer is **backlog #16** — *"Front-end authoring for all post types via
layout-v2, gated"*, filed the same day, flagged **★ vision**, with the standing
instruction: *"Big item — Ian rules the phasing before any build."* Backlog #6 already
says it *"Depends on the composer-unification decision below."* That dependency is
real and this scope confirms it rather than discovering it.

**So I am not going to half-build around it.** The choice is Ian's, and it is a choice
between three genuinely different things, not three sizes of the same thing.

---

## 4. The capability check — get this right (PROVEN)

This is the part of the item that is cheap, unambiguous and security-relevant, and it
holds no matter which option Ian picks.

### 4.1 The wrong answer that is sitting right there

`bb-mirror/api/v0/auth.php:54-56` computes the flag every existing edit affordance
gates on:

```php
$can_edit_others = current_user_can('moderate')
    || current_user_can('keep_gate')
    || current_user_can('manage_options');
```

That is a **forum-moderator** predicate. `bbp_moderator` and `bbp_keymaster` both hold
`moderate`; neither holds `edit_others_posts`. Simulated in memory on dev2 (caps
injected via a `user_has_cap` filter — no DB write):

```
simulated bbp_moderator: auth.php can_edit_others = TRUE

post_type            post_id  gate on can_edit_others    gate on edit_post
topic                72375    EDIT ALLOWED               EDIT ALLOWED
loothprint           72155    EDIT ALLOWED               denied
post-type-videos     72343    EDIT ALLOWED               denied
post-imgcap          72193    EDIT ALLOWED               denied
sponsor-post         71443    EDIT ALLOWED               denied
event                72345    EDIT ALLOWED               denied
```

Gating admin-edit-any on `can_edit_others` hands **every forum moderator** edit rights
over **every article, video, event, loothprint and sponsor post** on the site. That is
a privilege escalation, not a UX bug.

**Today it is latent, not live.** dev2: 8 administrators, 7 `bbp_keymaster` (all also
admins), 0 `bbp_moderator`, 0 `editor`; the over-granted set is currently **empty**.
Live: 5 administrators, **0** `bbp_moderator`, **0** non-admin `bbp_keymaster`, 0
`editor` — also empty. It is one role assignment away from being real, and appointing
a forum moderator is exactly the kind of routine act nobody would connect to article
editing.

### 4.2 The wrong answer that looks safer

A role check (`administrator`) is also wrong: it misses `editor` and `shop_manager`
(both hold `edit_others_posts` on this install), and it hard-codes a role list that
`user-role-editor` — an active plugin here — exists to change.

### 4.3 The right answer

```php
current_user_can('edit_post', $post_id)
```

The **meta** capability. WordPress's `map_meta_cap` resolves it per post type against
that type's registered cap set, and per post (own vs others', published vs draft, the
`tier`/paywall filters). Verified from the live registry: `topic` maps to
`edit_others_topics`; all five content types use the default `post` cap set, so they
map to `edit_others_posts`. One line, correct for all six.

Proven both directions on dev2 against real posts authored by someone else:

```
post_type            post_id  own?   nonadmin(looth3)  admin
topic                72375    other  cannot            CAN
loothprint           72155    other  cannot            CAN
post-type-videos     72343    other  cannot            CAN
post-imgcap          72193    other  cannot            CAN
sponsor-post         71443    other  cannot            CAN
event                72345    other  cannot            CAN
```

Two supporting facts:

- **`frontend-admin-pro` already agrees.** Its own server-side write gate is
  `current_user_can('edit_post', $post_id)`
  (`main/frontend/forms/actions/post.php:1212`, also `main/admin/module.php:481`,
  `class-delete-object.php:106`). We would be matching the plugin's contract, not
  inventing one.
- **The codebase already has the pattern.** `bb-mirror/api/v0/topic-media.php:109`
  uses `current_user_can('edit_topic', $topic_id)` alongside the mod flag. The topic
  PUT at `reply.php:207` uses author-or-mod only — defensible for topics, since
  moderators genuinely hold `edit_others_topics`, but it is the pattern that must
  **not** be copied outward to CPTs.

### 4.4 Rules that hold regardless

- The client gate is **convenience**. Every write re-checks server-side, on the
  **stored** post's author — never a client-supplied one. `reply.php:205-206` already
  documents this as IDOR-proofing; keep it.
- Gate the **absence**: a non-admin must not get the affordance on someone else's
  post, on every type, on both viewports. That assertion must go red against today's
  build before it goes green.
- One display-layer wrinkle worth an assertion if the Frontend Admin estate is ever
  revived: the loothprint and sponsor-post forms list `subscriber`, `guest`,
  `looth2/3/4` in `by_role`, so a plain member passes the *display* gate and is only
  stopped at *submit*. Harmless for public content, but it is a form pre-filled with
  someone else's post, and it should be asserted rather than assumed.

---

## 5. Options for Ian

Named so they can be picked from, not ranked into a foregone conclusion.

### Option A — the affordance, into the editor that already has every control
**RECOMMENDED as phase 1.**

An Edit control appears on every post — content cards in the hub feed, and the
standalone content pages — for, and only for, a viewer who passes
`current_user_can('edit_post', $id)` computed **server-side per card**. It opens the
real editor for that post.

- **"Full functionality" is not approximated, it is guaranteed** — it is literally the
  same editor the post was created in, with all 7–28 ACF controls and the layout-v2
  block UI.
- **Zero new save paths.** The content-destruction failure class (§1.1) cannot recur,
  because nothing new writes a post.
- Small: an affordance, a server-computed per-card cap, a flag, and gates.
- **Honest cost:** it is wp-admin, not the front end. It is the fastest route from
  "Ian is looking at a bad post" to "Ian has fixed it", on every type, this week — but
  it is not the front-end composer, and I am not going to describe it as one.

### Option B — front-end composer for all types
The literal ask. Requires the whole of §2 — CPT API, front-end ACF control renderer,
front-end layout-v2 block editor, per-type taxonomy pickers. This **is** backlog #16,
and #16 says Ian rules the phasing first. Multi-week; lands on a layout-v2 editor
currently mid-repair.

### Option C — front-end quick-edit, common fields only
A front-end modal on every type covering title, excerpt, cover image, tags, tier and
status, with "Open full editor" for everything else. Real front-end editing, small
build, covers the common admin fix.
**But it needs a new CPT write endpoint — i.e. it re-opens the content-destruction
failure class** — and it is explicitly *not* "full functionality", which is the phrase
Ian used. Flagging it because it is the obvious middle and I do not want it chosen by
accident: it buys the least per unit of risk of the three.

**My recommendation:** ship **A** behind a flag now so Ian can edit anything today,
and treat **B** as the backlog #16 program it already is. I would not build **C**.

---

## 6. Non-negotiables carried into whichever option is picked

- **Mock before building.** Phone width included, committed, URL to keeper. The
  affordance mock is in `docs/mocks/admin-edit-any/` — Ian picks the placement before
  any of this is built.
- **Flag it, defaulted OFF**, copying `LG_AUTHOR_SOCIALS_ALL_MEMBERS`
  (`platform/mu-plugins/lg-author-socials.php`). Flag-OFF proven byte-identical
  no-op, and the OFF state **gated** — that missing assertion is the whole documented
  failure class.
- **Gate the absences.** A non-admin must not see the affordance on someone else's
  post, on every type, on both viewports, as the *real user* — an anon or admin-only
  harness structurally cannot see this. Every assertion red against today's build
  first.
- **Nothing merges until Ian has clicked the real control.** A green suite is not
  evidence.

---

## 7. Reproducing the probes

`tools/admin-edit-any/scope-probe.sh` re-runs every measurement in this document:
post-type/cap registry, the role matrix, the over-grant simulation, the `edit_post`
truth table, the layout-v2 body-model counts, the ACF control inventory, and the
dev2 fetch of a Frontend Admin edit page as admin / member / anon.
