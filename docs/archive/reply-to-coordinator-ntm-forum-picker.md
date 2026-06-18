# Reply to hub-coord — NEW-POST forum picker (ntm composer)

**Lane:** Forum "New post" form (ntm-* composer)
**Status:** BLOCKED in-lane — root cause is OUT of my two scoped files. Investigated + verified; no code committed. Routing per Ian: write up for coord.

## What Ian asked for (the composer's forum picker)
1. Should be able to pick **one** forum, not two.
2. **Drop the chips** — use a **list** with very apparent **category → leaf** structure.
3. Make the **modal bigger** so the editor isn't cramped.

## Root cause — it's not in _chrome.php / forums.js
My scoped slice (`_chrome.php #ntm-form` + `forums.js §4`) only renders the *fallback native form*. The composer the user actually sees is rebuilt at runtime by:

**`/var/www/dev/hub-polish.js` → `fbStyleComposer()` (owner: `buck`)**

- Runs on every composer open, **all widths** (no `max-width:640` guard — see call at line ~1136). The file header says "mobile/app viewport ≤640" but this function is NOT gated, so it leaks to desktop too.
- It hides the native `#ntm-forum` select + title + tags, injects the avatar header, and builds the **"Add to" chip picker** (lines **747–781**).
- `fbMakeChip` chips are **toggle / multi-select**; `syncChips()` (line ~775) does:
  `forumSel.value = on[0]` (first chip = forum) and `tagsIn.value = on.map(...).join(', ')` (the *rest* of the on-chips become tags).
  → Toggling two chips reads as "posting to two forums." **This is the "select two forums" bug.**

I confirmed in the real browser (CDP, admin cookies, `/hub/share-your-repair-content/`): native `#ntm-forum` is `display:none`; the visible picker is `.lg-fbc-chips`.

Because of this, anything I do in `_chrome.php`/`forums.js` is overridden (I tried a radio-list there — it just made the chip builder read 0 options and render an empty "Add to". Fully reverted; my files are clean.)

## Proposed fix (owner action needed)

### A. hub-polish.js `fbStyleComposer()` — chips → single-select list  (buck)
Replace the chip block (≈ lines **747–781**: `chipWrap` build, `fbMakeChip`, the `forumSel.children` loop, and `syncChips`) with a **radiogroup list** that mirrors the same optgroup → leaf nesting:

```js
// Single-select forum list (category header + leaf radio rows).
var listWrap = document.createElement('div'); listWrap.className = 'lg-fbc-forumlist';
listWrap.innerHTML = '<div class="lg-fbc-chips__h">Post to</div>';
var listBody = document.createElement('div'); listBody.className = 'lg-fbc-fl';
listWrap.appendChild(listBody);

function fbMakeRow(o) {
  var lab = document.createElement('label'); lab.className = 'lg-fbc-fl__leaf';
  var inp = document.createElement('input');
  inp.type = 'radio'; inp.name = 'lg-fbc-forum'; inp.value = o.value;
  if (o.value === forumSel.value) inp.checked = true;       // preselect current/General
  inp.addEventListener('change', function () { forumSel.value = inp.value; });
  var t = document.createElement('span'); t.textContent = o.textContent.trim();
  lab.appendChild(inp); lab.appendChild(t); return lab;
}
[].slice.call(forumSel.children).forEach(function (node) {
  if (node.tagName === 'OPTGROUP') {
    var h = document.createElement('div'); h.className = 'lg-fbc-fl__cat'; h.textContent = node.label || '';
    var rows = [].slice.call(node.children).filter(function (o) { return o.value; }).map(fbMakeRow);
    if (rows.length) { listBody.appendChild(h); rows.forEach(function (r) { listBody.appendChild(r); }); }
  } else if (node.tagName === 'OPTION' && node.value) {
    listBody.appendChild(fbMakeRow(node));
  }
});
form.insertBefore(listWrap, actionsRow === form ? submit : actionsRow);
```

Notes:
- **Single-select** by construction (radio group) → fixes "two forums."
- **Decouples tags from forum.** The chip version stuffed extra forums into `tagsIn`; with a single list, leave `#ntm-tags` as-is (it's hidden in this composer — if you still want quick tags, that's a separate decision). Don't re-derive tags from the forum choice.
- Keep the existing `submit` click handler (auto-title from first line + default forum 3837) unchanged.

### B. CSS — list styling + bigger modal  (hub-coord, in forums.css; or inject in hub-polish's `<style>`)
hub-polish already injects its own `lg-fbc-*` styles; add list rules alongside, e.g.:
```css
.lg-fbc-forumlist { margin:12px 0 4px; }
.lg-fbc-fl { max-height:230px; overflow:auto; border:1px solid var(--lg-line,#e3ddd0); border-radius:10px; background:#fff; }
.lg-fbc-fl__cat { position:sticky; top:0; background:#fff; padding:8px 12px 4px; font:700 10.5px/1 sans-serif; letter-spacing:.07em; text-transform:uppercase; color:var(--lguser-accent,#6b7c52); }
.lg-fbc-fl__cat:not(:first-child){ border-top:1px solid var(--lg-line,#e3ddd0); margin-top:2px; padding-top:8px; }
.lg-fbc-fl__leaf { display:flex; align-items:center; gap:8px; padding:7px 12px; font:500 14px/1.2 sans-serif; cursor:pointer; }
.lg-fbc-fl__leaf:hover, .lg-fbc-fl__leaf:has(input:checked){ background:var(--lguser-pill,#eef2e3); }
.lg-fbc-fl__leaf:has(input:checked){ font-weight:700; }
```
Bigger modal (forums.css, `.ntm-dialog` ~line 2210): `width: min(760px, calc(100vw - var(--s8)))` and bump `.ntm-form .ql-editor { min-height:200px; max-height:420px }` + `.ntm-editor { min-height:220px }`.

### C. Open question for Ian/coord
The file header claims fbStyleComposer is a ≤640 mobile pass, but it's ungated and runs on desktop. If the fb-style composer was only ever meant for mobile, the alternative fix is to **gate `fbStyleComposer()` behind `matchMedia('(max-width:640px)')`** — then desktop falls back to the native form, which the ntm lane CAN own and style as a list. Buck owns that call either way.

## Report card
- **DONE:** root-caused; concrete patch (A) + CSS (B) drafted; verified in real browser.
- **FILES:** `/var/www/dev/hub-polish.js` `fbStyleComposer()` ~L707–800 (buck); `bb-mirror/web/forums.css` `.ntm-dialog`/`.ntm-editor` (hub-coord). My scoped files unchanged (clean).
- **VERIFIED:** CDP @ `/hub/share-your-repair-content/` — native picker hidden, `.lg-fbc-chips` is the live picker, multi-toggle confirmed.
- **NEEDS-ENGINE:** none.
- **BLOCKED:** yes — all three asks are outside `_chrome.php`/`forums.js`; need buck (hub-polish.js) + hub-coord (forums.css) to apply, or a greenlight for me to edit cross-lane.
