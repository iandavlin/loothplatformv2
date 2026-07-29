#!/usr/bin/env node
/**
 * composer-topic-meta-test — logic check for the composer's topic-meta controls
 * (forum picker + tags + quick-tags) added for edit/add parity, Ian 2026-07-29.
 *
 * WHY THIS EXISTS. The forum <select> in the edit composer is BUILT BY CLONING the
 * add-post picker (#ntm-forum), so it inherits that markup's shape: category <div>s
 * and leaf <label>s are FLAT SIBLINGS, and a leaf's category is "the nearest
 * .ntm-fl__cat above it". That walk is the kind of thing that keeps working until
 * someone nests the markup, and then it fails by putting every forum under one
 * wrong heading — which looks fine until a member files their post in the wrong
 * place. The picker's grouping has already been fixed twice for exactly this class
 * of bug (the duplicated "General" header; the two-forums-selected bug).
 *
 * WHAT IT RUNS. The REAL functions, extracted from webroot/hub-polish.js by name —
 * not a copy. If someone edits them, this runs the edited version. They are driven
 * against a DOM shim holding the forum list as the server actually renders it.
 *
 * Fixture: tools/gates/fixtures/ntm-forum.json, generated from a live fetch of
 * /hub/ (see --regen). Regenerate it when the forum list changes; the test asserts
 * on SHAPE (grouping, order, safety) rather than on any particular forum existing.
 *
 * Run: node tools/gates/composer-topic-meta-test.js
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC = path.join(ROOT, 'webroot', 'hub-polish.js');
const FIXTURE = path.join(__dirname, 'fixtures', 'ntm-forum.json');

/* ── the smallest DOM that these functions actually touch ──────────────────── */
class El {
  constructor(tag) {
    this.tagName = String(tag).toUpperCase();
    this.children = [];
    this.parentNode = null;
    this.hidden = false;
    this._text = '';
    this._attrs = {};
    this._classes = new Set();
    this.classList = {
      add: (c) => this._classes.add(c),
      remove: (c) => this._classes.delete(c),
      contains: (c) => this._classes.has(c),
      toggle: (c, on) => (on ? this._classes.add(c) : this._classes.delete(c)),
    };
    this.dataset = {};
    this._listeners = {};
  }
  set className(v) { this._classes = new Set(String(v).split(/\s+/).filter(Boolean)); }
  get className() { return [...this._classes].join(' '); }
  setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'class') this.className = v; }
  getAttribute(k) { return k === 'class' ? this.className : (k in this._attrs ? this._attrs[k] : null); }
  removeAttribute(k) { delete this._attrs[k]; }
  addEventListener(type, fn) { (this._listeners[type] = this._listeners[type] || []).push(fn); }
  dispatch(type) { (this._listeners[type] || []).forEach((fn) => fn.call(this, { type })); }
  get firstChild() { return this.children[0] || null; }
  // HTMLOptionElement.value reflects the `value` content attribute (HTML spec), so
  // `option[value="…"]` selectors match after `opt.value = x`. Without this the shim
  // reports a false duplicate-option failure that a browser would never produce.
  get value() { return this._attrs.value !== undefined ? this._attrs.value : (this._value || ''); }
  set value(v) { this._value = String(v); this._attrs.value = String(v); }
  appendChild(c) { c.parentNode = this; this.children.push(c); return c; }
  insertBefore(c, ref) {
    c.parentNode = this;
    const i = ref ? this.children.indexOf(ref) : -1;
    if (i < 0) this.children.push(c); else this.children.splice(i, 0, c);
    return c;
  }
  set innerHTML(v) { if (v === '') this.children = []; }
  get textContent() {
    if (this.children.length) return this.children.map((c) => c.textContent).join('');
    return this._text;
  }
  set textContent(v) { this._text = String(v); this.children = []; }
  get previousElementSibling() {
    if (!this.parentNode) return null;
    const i = this.parentNode.children.indexOf(this);
    return i > 0 ? this.parentNode.children[i - 1] : null;
  }
  _descendants() {
    const out = [];
    const walk = (n) => n.children.forEach((c) => { out.push(c); walk(c); });
    walk(this);
    return out;
  }
  matches(sel) {
    if (sel.startsWith('.')) return this._classes.has(sel.slice(1));
    if (sel.startsWith('#')) return this._attrs.id === sel.slice(1);
    const m = /^(\w+)?\[([\w-]+)="([^"]*)"\]$/.exec(sel);
    if (m) {
      if (m[1] && this.tagName !== m[1].toUpperCase()) return false;
      return this.getAttribute(m[2]) === m[3];
    }
    return this.tagName === sel.toUpperCase();
  }
  querySelectorAll(sel) { return this._descendants().filter((n) => n.matches(sel)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
  closest(sel) {
    let n = this;
    while (n) { if (n.matches && n.matches(sel)) return n; n = n.parentNode; }
    return null;
  }
}
// <select>.value is NOT a reflected attribute — it is the selected option's value,
// so it must shadow El's reflecting accessor rather than inherit it.
class SelectEl extends El {
  constructor() { super('select'); this._sel = ''; }
  get value() { return this._sel; }
  set value(v) { this._sel = String(v); }
}

/* ── build #ntm-forum exactly as the server renders it: flat cat/leaf siblings ── */
function buildForumList(fixture) {
  const list = new El('div');
  list.setAttribute('id', 'ntm-forum');
  for (const row of fixture.rows) {
    if (row.kind === 'cat') {
      const d = new El('div');
      d.className = 'ntm-fl__cat';
      d.textContent = row.label;
      list.appendChild(d);
    } else {
      const label = new El('label');
      label.className = 'ntm-fl__leaf';
      const input = new El('input');
      input.setAttribute('type', 'radio');
      input.setAttribute('name', 'forum_id');
      input.value = String(row.id);
      input.setAttribute('value', String(row.id));
      const span = new El('span');
      span.className = 'ntm-fl__title';
      span.textContent = row.title;
      label.appendChild(input);
      label.appendChild(span);
      list.appendChild(label);
    }
  }
  return list;
}

function buildSheet() {
  const sh = new El('div');
  const meta = new El('div'); meta.setAttribute('id', 'lgc-meta');
  const row = new El('div');
  const sel = new SelectEl(); sel.setAttribute('id', 'lgc-forum');
  row.appendChild(sel);
  const tags = new El('input'); tags.setAttribute('id', 'lgc-tags'); tags.value = '';
  const qtags = new El('div'); qtags.setAttribute('id', 'lgc-qtags');
  meta.appendChild(row); meta.appendChild(tags); meta.appendChild(qtags);
  sh.appendChild(meta);
  return sh;
}

/* ── extract the REAL functions from hub-polish.js ─────────────────────────── */
function extract(src, names) {
  const out = [];
  for (const name of names) {
    const start = src.indexOf(`\n  function ${name}(`);
    if (start < 0) throw new Error(`could not find function ${name}() in hub-polish.js`);
    // brace-match from the first { after the signature
    let i = src.indexOf('{', start), depth = 0, end = -1;
    for (let j = i; j < src.length; j++) {
      const c = src[j];
      if (c === '{') depth++;
      else if (c === '}') { depth--; if (depth === 0) { end = j + 1; break; } }
    }
    if (end < 0) throw new Error(`unbalanced braces reading ${name}()`);
    out.push(src.slice(start, end));
  }
  return out.join('\n');
}

const NAMES = [
  'lgcForumOptionSource', 'lgcFillForumSelect', 'lgcBuildQuickTags',
  'lgcTagList', 'lgcSetTagList', 'lgcToggleTag', 'lgcSyncQuickTags',
  'lgcTopicForumOffered', 'lgcTopicTagsOffered', 'lgcTopicEditPayload',
];

function loadUnderTest(forumList, quicktagsEl) {
  const body = extract(fs.readFileSync(SRC, 'utf8'), NAMES);
  const document = {
    getElementById: (id) => (id === 'ntm-forum' ? forumList : id === 'ntm-quicktags' ? quicktagsEl : null),
    createElement: (t) => (t === 'select' ? new SelectEl() : new El(t)),
  };
  // eslint-disable-next-line no-new-func
  const factory = new Function('document', 'El', `${body}\n return {${NAMES.join(',')}};`);
  return factory(document, El);
}

/* ── assertions ───────────────────────────────────────────────────────────── */
let pass = 0, fail = 0;
const ok = (cond, msg) => { if (cond) { pass++; } else { fail++; console.log('  FAIL  ' + msg); } };

if (process.argv.includes('--regen')) {
  console.log('--regen: fetch /hub/ and rebuild fixtures/ntm-forum.json by hand; see header.');
  process.exit(0);
}
if (!fs.existsSync(FIXTURE)) {
  console.log(`composer-topic-meta-test: MISSING FIXTURE ${FIXTURE}`);
  process.exit(2);
}
const fixture = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'));
const leaves = fixture.rows.filter((r) => r.kind === 'leaf');
const cats = fixture.rows.filter((r) => r.kind === 'cat');

const forumList = buildForumList(fixture);
const qtSrc = new El('div');
qtSrc.setAttribute('id', 'ntm-quicktags');
['councilyes', 'weeklyyes'].forEach((t) => {
  const b = new El('button'); b.className = 'ntm-qtag'; b.dataset.tag = t; b.setAttribute('data-tag', t);
  qtSrc.appendChild(b);
});
const F = loadUnderTest(forumList, qtSrc);

console.log(`composer-topic-meta-test — fixture: ${leaves.length} forums, ${cats.length} categories`);

/* 1. every postable forum survives the clone — a dropped option is a forum a
      member silently cannot file into. */
{
  const sh = buildSheet();
  ok(F.lgcFillForumSelect(sh, 0) === true, 'fill returns true when the picker exists');
  const sel = sh.querySelector('#lgc-forum');
  const opts = sel._descendants().filter((n) => n.tagName === 'OPTION');
  ok(opts.length === leaves.length, `every forum cloned (got ${opts.length}, want ${leaves.length})`);
  const ids = opts.map((o) => o.value).sort();
  const want = leaves.map((l) => String(l.id)).sort();
  ok(JSON.stringify(ids) === JSON.stringify(want), 'cloned option values match the picker exactly');
  const titles = opts.map((o) => o.textContent);
  ok(titles.every((t) => t && t !== 'undefined'), 'every option carries its forum title');
}

/* 2. grouping: one optgroup per contiguous category run, each leaf under the
      nearest heading above it. This is the previousElementSibling walk. */
{
  const sh = buildSheet();
  F.lgcFillForumSelect(sh, 0);
  const sel = sh.querySelector('#lgc-forum');
  const groups = sel.children.filter((n) => n.tagName === 'OPTGROUP');
  ok(groups.length === cats.length, `one optgroup per category (got ${groups.length}, want ${cats.length})`);
  ok(groups.every((g) => g.children.length > 0), 'no empty optgroup');
  // expected mapping straight off the fixture order
  const expect = [];
  let cur = null;
  for (const r of fixture.rows) {
    if (r.kind === 'cat') cur = r.label;
    else expect.push([cur, String(r.id)]);
  }
  const got = [];
  groups.forEach((g) => g.children.forEach((o) => got.push([g.label, o.value])));
  ok(JSON.stringify(got) === JSON.stringify(expect), 'each forum lands under its own category heading');
  ok(sel.children.every((n) => n.tagName === 'OPTGROUP'), 'no orphan option outside a group');
}

/* 3. the safety that stops a body-only save from relocating a post: a topic whose
      forum is NOT in the postable list (archived/container forums predate the rule)
      keeps a selectable entry, and it is the one selected. */
{
  const sh = buildSheet();
  F.lgcFillForumSelect(sh, 999999);
  const sel = sh.querySelector('#lgc-forum');
  ok(sel.value === '999999', 'an unlisted current forum stays selected');
  const kept = sel._descendants().filter((n) => n.tagName === 'OPTION' && n.value === '999999');
  ok(kept.length === 1, 'unlisted current forum gets exactly one option');
  ok(sel.children[0] === kept[0], 'the unlisted option is first, not buried in a group');
}

/* 4. a listed forum selects without inventing a duplicate. */
{
  const sh = buildSheet();
  const target = String(leaves[Math.floor(leaves.length / 2)].id);
  F.lgcFillForumSelect(sh, parseInt(target, 10));
  const sel = sh.querySelector('#lgc-forum');
  ok(sel.value === target, 'a listed forum is preselected');
  const dupes = sel._descendants().filter((n) => n.tagName === 'OPTION' && n.value === target);
  ok(dupes.length === 1, 'no duplicate option for an already-listed forum');
}

/* 5. refilling (a second edit in the same session) must not accumulate options. */
{
  const sh = buildSheet();
  F.lgcFillForumSelect(sh, 0);
  F.lgcFillForumSelect(sh, 0);
  const sel = sh.querySelector('#lgc-forum');
  const opts = sel._descendants().filter((n) => n.tagName === 'OPTION');
  ok(opts.length === leaves.length, `refill is idempotent (got ${opts.length}, want ${leaves.length})`);
}

/* 6. no picker on the page = report false, so the caller hides the row and leaves
      the forum alone rather than blocking the edit. */
{
  const G = loadUnderTest(null, null);
  const sh = buildSheet();
  ok(G.lgcFillForumSelect(sh, 0) === false, 'no #ntm-forum reports false rather than throwing');
}

/* 7. tags round-trip, and the quick-tag pills are a VIEW of the field. */
{
  const sh = buildSheet();
  F.lgcBuildQuickTags(sh);
  const qt = sh.querySelector('#lgc-qtags');
  const pills = qt.children;
  ok(pills.length === 2, 'both quick-tags cloned from #ntm-quicktags');

  F.lgcSetTagList(sh, ['vintage', 'councilyes']);
  ok(sh.querySelector('#lgc-tags').value === 'vintage, councilyes', 'tags render comma-separated');
  ok(JSON.stringify(F.lgcTagList(sh)) === '["vintage","councilyes"]', 'tags parse back identically');
  const council = pills.find((p) => p.getAttribute('data-tag') === 'councilyes');
  const weekly = pills.find((p) => p.getAttribute('data-tag') === 'weeklyyes');
  ok(council.classList.contains('is-on'), 'a tag typed in the field lights its pill');
  ok(council.getAttribute('aria-pressed') === 'true', 'pill state is exposed to a11y');
  ok(!weekly.classList.contains('is-on'), 'an absent tag leaves its pill off');

  F.lgcToggleTag(sh, 'councilyes');
  ok(JSON.stringify(F.lgcTagList(sh)) === '["vintage"]', 'un-pressing a pill removes only that tag');
  ok(!council.classList.contains('is-on'), 'pill clears with the tag');
  F.lgcToggleTag(sh, 'weeklyyes');
  ok(JSON.stringify(F.lgcTagList(sh)) === '["vintage","weeklyyes"]', 'pressing a pill appends the tag');

  // messy human input must not create empty or duplicate tags
  sh.querySelector('#lgc-tags').value = ' a ,, b ,';
  ok(JSON.stringify(F.lgcTagList(sh)) === '["a","b"]', 'blank and trailing commas are dropped');

  // clearing is a real state, distinct from "never offered" (server: absent = keep)
  F.lgcSetTagList(sh, []);
  ok(sh.querySelector('#lgc-tags').value === '', 'tags can be cleared to empty');
  ok(JSON.stringify(F.lgcTagList(sh)) === '[]', 'cleared tags read as an empty list, not [""]');

  // case-insensitive matching: "CouncilYes" typed by hand still lights the pill
  F.lgcSetTagList(sh, ['CouncilYes']);
  ok(council.classList.contains('is-on'), 'pill matching is case-insensitive');
  F.lgcToggleTag(sh, 'councilyes');
  ok(JSON.stringify(F.lgcTagList(sh)) === '[]', 'toggling off removes a differently-cased tag');
}

/* 8. THE DESTRUCTIVE CONTRACT. The server reads an ABSENT key as "leave this
      alone", so what the payload OMITS matters more than what it sets:
        topic_tags: [] sent when tags were never shown -> wipes the post's tags
        forum_id sent when no picker was shown         -> relocates the post
      Both look like an ordinary successful save. These assert on key PRESENCE,
      not just values, because `undefined` and "absent" are the same in JS but
      very different once JSON.stringify drops the key. */
{
  // a composer that offered both controls
  const sh = buildSheet();
  F.lgcFillForumSelect(sh, 3823);
  F.lgcSetTagList(sh, ['vintage', 'martin']);
  ok(F.lgcTopicForumOffered(sh) === true, 'forum counts as offered when shown and set');
  ok(F.lgcTopicTagsOffered(sh) === true, 'tags count as offered when the meta row is visible');
  let p = F.lgcTopicEditPayload(sh, 72306, 'T', '<p>b</p>');
  ok('forum_id' in p && p.forum_id === 3823, 'forum_id sent when offered');
  ok('topic_tags' in p && JSON.stringify(p.topic_tags) === '["vintage","martin"]',
     'topic_tags sent when offered');
  ok(p.topic_id === 72306 && p.title === 'T' && p.content === '<p>b</p>', 'core fields intact');

  // deliberately cleared tags — PRESENT and empty is a real instruction
  F.lgcSetTagList(sh, []);
  p = F.lgcTopicEditPayload(sh, 72306, 'T', '<p>b</p>');
  ok('topic_tags' in p && p.topic_tags.length === 0,
     'cleared tags send an EMPTY ARRAY, not an omitted key');

  // the meta row hidden = this composer never showed tags (a reply-mode open, or a
  // page with no picker). Sending topic_tags here would wipe them.
  const hidden = buildSheet();
  hidden.querySelector('#lgc-meta').hidden = true;
  ok(F.lgcTopicTagsOffered(hidden) === false, 'hidden meta row = tags not offered');
  p = F.lgcTopicEditPayload(hidden, 72306, 'T', '<p>b</p>');
  ok(!('topic_tags' in p), 'topic_tags OMITTED when never offered — tags survive');
  ok(!('forum_id' in p), 'forum_id OMITTED when never offered — post is not relocated');

  // picker row hidden because the page carried no #ntm-forum to clone
  const noPicker = buildSheet();
  const G = loadUnderTest(null, null);
  ok(G.lgcFillForumSelect(noPicker, 0) === false, 'no picker to clone');
  noPicker.querySelector('#lgc-forum').parentNode.hidden = true;
  ok(G.lgcTopicForumOffered(noPicker) === false, 'hidden forum row = not offered');
  p = G.lgcTopicEditPayload(noPicker, 72306, 'T', '<p>b</p>');
  ok(!('forum_id' in p), 'a page without the picker cannot relocate the post');
  ok(JSON.parse(JSON.stringify(p)).forum_id === undefined,
     'and the key really is gone after JSON round trip');
}

/* 9. SAVE-ARMING — the most destructive single line in the feature.
      The composer opens EMPTY and fills in from a server fetch. If Save is armed
      before the payload lands, one tap writes that emptiness over a real post. So
      `editLoading` must dominate: no combination of other inputs may arm Save while
      the stored body is still in flight.
      lgcRecalcPost is extracted with its collaborators stubbed, so this tests the
      REAL arming logic rather than a restatement of it. */
{
  const src = fs.readFileSync(SRC, 'utf8');
  const body = extract(src, ['lgcRecalcPost']);
  const mk = (opts) => {
    const sh = buildSheet();
    const post = new El('button'); post.setAttribute('id', 'lgc-post'); post.disabled = false;
    const title = new El('input'); title.setAttribute('id', 'lgc-title');
    title.value = opts.title === undefined ? 'A title' : opts.title;
    sh.appendChild(post); sh.appendChild(title);
    if (opts.forum !== undefined) {
      F.lgcFillForumSelect(sh, opts.forum || 3823);
      if (opts.forum === 0) sh.querySelector('#lgc-forum').value = '';
    } else {
      sh.querySelector('#lgc-forum').parentNode.hidden = true;
    }
    sh.__lcpCtx = { editTopicId: 72306, editLoading: !!opts.loading, keepMedia: [] };
    // eslint-disable-next-line no-new-func
    const fn = new Function('lgcSyncPhotoCount', 'lgcHasContent', 'lcpUploading', 'El',
                            `${body}\n return lgcRecalcPost;`)(
      () => {}, () => opts.content !== false, opts.uploading ? 1 : 0, El);
    fn(sh);
    return post.disabled;
  };

  ok(mk({ loading: false, content: true, forum: 3823 }) === false,
     'Save ARMS once the payload landed and everything is filled');
  ok(mk({ loading: true, content: true, forum: 3823 }) === true,
     'Save is INERT while the stored body is still loading');
  // NOT a domination test — with an empty body and title Save is inert anyway, so
  // this would pass with the editLoading guard deleted. The domination case is the
  // assertion above it (loading + everything else valid). Kept as a plain
  // nothing-is-ready check, named for what it actually proves.
  ok(mk({ loading: true, content: false, title: '', forum: 0 }) === true,
     'nothing ready at all is inert');
  ok(mk({ loading: false, content: false, forum: 3823 }) === true,
     'Save is inert with an empty body');
  ok(mk({ loading: false, content: true, title: '', forum: 3823 }) === true,
     'Save is inert with an empty title (the server requires one)');
  ok(mk({ loading: false, content: true, title: '   ', forum: 3823 }) === true,
     'a whitespace-only title does not count');
  ok(mk({ loading: false, content: true, forum: 0 }) === true,
     'Save is inert when the picker is shown but nothing is chosen');
  ok(mk({ loading: false, content: true }) === false,
     'but a page with NO picker still lets the member edit their text');
  ok(mk({ loading: false, content: true, forum: 3823, uploading: true }) === true,
     'Save is inert while a photo is still uploading');
}

console.log(`\ncomposer-topic-meta-test: pass=${pass} fail=${fail}`);
if (fail) { console.log('==================== COMPOSER TOPIC-META TEST RED ===================='); process.exit(1); }
console.log('==================== COMPOSER TOPIC-META TEST GREEN ====================');
