# Handoff — `hub-editdel` lane (Hub forum Edit/Delete + comment-card pass)

**Branch:** `hub-editdel`  ·  **HEAD:** `93ba52d`  ·  **base:** `abe8bbd` (off `main`)
**State:** DEPLOYED to the **dev2 preview** (serve clone `loothplatformv2-serve`, live at
`dev2.loothgroup.com`).  **NOT merged to `main`.**  18 commits, 7 files, +1007/-147.

## What this lane delivers
Facebook-style **Edit + Delete** on every Hub forum **post (topic)** and **reply**, for the
**author** (own) and **admins/mods** (all), on **desktop AND mobile**, all routed through ONE owned,
author-or-mod, IDOR-proof endpoint — plus a comment-card visual pass ("Direction A").

### Backend — `bb-mirror/api/v0/reply.php` (+ `auth.php`)
- Added **TOPIC EDIT** (`PUT {topic_id,title,content}`) — was the one missing write. reply.php now
  owns all four: topic edit/delete + reply edit/delete (author-or-mod, `wp_rest` nonce, kses).
- Added **GET `?reply_id=N`** → a reply's photo set (for the edit composer's removable thumbs).
- Reply-edit PUT now manages **photos**: `media_ids` (add) + `keep_media_ids` (keep; others removed)
  via BuddyBoss's own `bp_media_forums_new_post_media_save`. Photo-only edits allowed.
- **Admin see-all fix:** `can_edit_others` / write gate use real caps —
  `moderate || keep_gate || manage_options` (the old `current_user_can('administrator')` is a role
  name, not a cap). auth.php + reply.php kept in lockstep so UI-reveal and server-enforcement agree.

### Desktop — `bb-mirror/web/forums.js` + `forums.css` + `forums/_single-topic.php`
- Permalink (`_single-topic.php`): FB ⋯/Edit menu on OP + every reply (`lg_post_menu()`),
  author/admin-gated, wired to the owned endpoint.
- Discussion modal (`#lg-dmodal`): React + Reply + **Edit** as 3 uniform grouped buttons; Edit opens
  an Edit/Delete dropdown (`position:fixed`, click-out dismiss). "No self-react" on own replies.
- Reply **Edit opens the canonical Quill composer** (`lgFrmEditReply`) pre-filled, with add/remove
  photos; fixed the empty-editor bug (seed waited for Quill instead of the hidden textarea).

### Mobile — `webroot/hub-polish.js` (sheet `#looth-rep-sheet` / composer `#looth-comp-sheet`)
- Mirrors desktop: reply Delete/Edit use the **owned endpoint** (native BB was mods-only + orphaned
  media). One **"Edit" button → Edit/Delete popup** (replaces the pencil/trash icons).
- **Edit uses mobile's own reply composer** (`openComposerSheet`), pre-filled, with existing photos
  loaded as removable thumbs **below the text** + add new; Save → owned PUT.
- Fixed own-reply reveal (subtree MutationObserver).
- Cache-bust: `hub-polish.js?v=214` (injected by `pwa.js`, which is served `no-cache`).

## Media-orphan audit (asked + answered)
Both edit-remove and full delete clean up fully — `bp_media_delete` → `BP_Media::delete` →
`wp_delete_attachment(force)` (removes bp_media row + WP attachment + file), and reply/topic delete
fires `before_delete_post` → `bb_forums_delete_topic_reply_media_attachments`. Mobile WAS the orphan
risk (it round-tripped images as inline `<img>`); now fixed to use real attachments. **No orphans on
either surface.**

## Deploy / cache mechanics (for the dev2 preview)
- bb-mirror files served from `/srv/bb-mirror` → `loothplatformv2-serve/bb-mirror`; deployed by
  file-copy. `forums.js`/`forums.css` cache-bust via `filemtime` `?v` (auto on copy).
- Overlays served from `loothplatformv2-serve/webroot` (symlinked into `/var/www/dev`).
  `hub-polish.js` is injected by `pwa.js` with a hardcoded `?v`; `pwa.js` is `no-cache`, so bumping
  the injected version (now 214) ships it. (Bumped in `webroot/pwa.js`.)

## Verification
- **Desktop:** verified by Ian — permalink + modal Edit/Delete, React/Reply/Edit row, edit opens
  composer pre-filled, add/remove photos, photo loads in.
- **Mobile:** deployed (`v214`); pending Ian's final pass on the Edit→popup→composer flow.
- Static: `php -l` + `node --check` clean on every change.

## Known limitations / queued follow-ups (not done)
1. **OP (topic) Edit on mobile/desktop** — replies are fully composer-wired; the OP's edit path is the
   older one (desktop modal OP uses inline edit/delete; mobile OP edit via the topic composer). Could
   be unified to the same pattern.
2. **`@patreon_<id>` mentions** render as the raw login slug instead of a display name (reply-to seed).
3. **Legacy replies** with empty/missing stored `content_html` seed an empty editor (data backfill,
   not a UI bug).
4. **Mobile edit is plain-text** (mobile's composer is a textarea) — rich formatting from a desktop
   edit flattens if re-edited on mobile. By design ("use mobile's reply input system").

## Merge notes
- 6 of 7 files are **conflict-free** vs current `main`.
- `webroot/pwa.js` has a **trivial 1-spot conflict**: `main` bumped the *bottom-nav* `?v`; this branch
  bumped the *hub-polish* `?v`. Resolution = keep BOTH bumps.
- Merge only when Ian signs off as final (lane protocol). Recommend final mobile verification first.
