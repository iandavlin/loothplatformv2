# Relay → buck-COORD — mobile composer broken by the ntm forum-picker markup change

> **RESOLVED 2026-06-07 19:23 — Buck shipped Option A (converge) concurrently.** `hub-polish.js` v58→v59
> (pwa.js bumped to `?v=59`): mobile `fbStyleComposer()` now SHOWS the native `.ntm-forumlist` instead of
> hiding it + building duplicate chips; `forumSel` dropped from the hide list; `ensureForum()` checks the
> General `#3837` radio when none checked; submit uses it; light CSS scoped to `.lg-fbc #ntm-forum.ntm-forumlist`.
> **Integration re-verified (CDP, gate cookie):** 390px = native list visible (37 leaves/8 cats), General
> preselected on `/hub/` root (unpostable case gone), clicking a 2nd radio keeps exactly 1 checked (two-forums
> bug gone), 0 duplicate chips. 1280px = `lg-fbc` not applied (gate holds), native list untouched. Both green.
> **Push-hold on `ab8a172` can release** (combined dev state is mobile-safe). **Cutover dep:** `hub-polish.js`
> ≥v59 is a deployed asset NOT in this git repo — it must ship to the cut box alongside the `ab8a172` markup
> or mobile breaks there (same class as the engine's "/etc nginx routes re-apply at cut" note).
> Original ask below, kept for the record.

---

**From:** Hub RESULTS & INTEGRATION chat
**Severity:** mobile-blocking (users can't create a post from `/hub/` on a phone)
**Verified:** real Chrome via CDP, admin cookies, served in-place from `bb-mirror/web/` (nginx `alias`).
**Push status:** the offending desktop commit `ab8a172` is committed-not-pushed; I'm **holding the push** until your fix lands so the batch ships mobile-safe.

## What changed (the shared-markup contract)
`bb-mirror/web/forums/_chrome.php` `#ntm-form` no longer renders a native `<select id="ntm-forum">`.
It now renders a **single-select radiogroup list** (desktop ask: "pick one forum, category→leaf list, bigger modal"):

```
<div class="ntm-forumlist" id="ntm-forum" role="radiogroup">
  <div class="ntm-fl__cat">General</div>
  <label class="ntm-fl__leaf">
    <input type="radio" name="forum_id" value="{id}" data-slug="{slug}" required>
    <span class="ntm-fl__title">{title}</span>
  </label>
  ...
</div>
```

**Selected forum = the checked radio**, NOT `forumSel.value`.
`forums.js` is already converted to read it (`input[name="forum_id"]:checked`).

## Why mobile broke
`/var/www/dev/hub-polish.js` `fbStyleComposer()` (gated `≤640`) still treats `#ntm-forum` as a `<select>`:
- L738 `forumSel.value = '3837'` → no-op expando on a `<div>` (never checks a radio).
- L764 `forumSel.children` loop expects `<optgroup>`/`<option>` → finds `.ntm-fl__cat` / `.ntm-fl__leaf` → **builds 0 chips**.
- It still `display:none`s `#ntm-forum`, so the working radiogroup is hidden too.

**Verified @ 390px:** `/hub/` root → 0 chips, 0 checked radios, native list hidden → `ntmGetForum()` returns null → "Please choose a forum", **unpostable**. On a forum page (1 preselected radio) you can submit to that forum but can't change it.

## The fix (your call — two clean options)

**Option A (simplest, recommended): stop rebuilding chips; let the native radiogroup show on mobile.**
The list is already a clean single-select category→leaf picker. In `fbStyleComposer()`: detect the new markup and bail out of the chip block + the `display:none` on `#ntm-forum`:
```js
var forumSel = document.getElementById('ntm-forum');
var isNewList = forumSel && forumSel.classList.contains('ntm-forumlist'); // radiogroup, not <select>
// ...when hiding native fields, skip forumSel if isNewList...
// ...skip the whole chip-builder + syncChips block if isNewList (the list IS the picker)...
```
Then style `.ntm-forumlist`/`.ntm-fl__*` in `mobile-hub.css` to taste. Forum value flows through the checked radio automatically (forums.js reads it).

**Option B: rebuild chips from the radios, single-select.**
Iterate `forumSel.querySelectorAll('.ntm-fl__leaf input[type=radio]')`, group by the preceding `.ntm-fl__cat`, and on chip click do `radio.checked = true` (single-select) instead of `forumSel.value = …`. Don't stuff extra forums into `#ntm-tags`.

## After you land it
Tell me (route via Ian) and I'll re-verify the **combined** composer at 390px + 1180px, then release the push hold on `ab8a172` (+ the two composer commits on top: `8ada85b` palette pin, `3dafde7` quick-tags) as one mobile-safe batch.

## Standing note (the announce rule)
`#ntm-form` is shared markup. Per `docs/hub-mobile-desktop-split.md`, shape changes to it are announced to both lanes *before* landing. This one didn't reach you — that's the gap, not the architecture. Future shared-markup changes ping buck-COORD first.
