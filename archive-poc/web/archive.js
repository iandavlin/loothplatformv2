// archive-poc Variant C frontend. Vanilla, no build step.

const API = '/archive-api/v0';

const KIND_LABELS = {
  article: 'Articles',
  video: 'Videos',
  loothprint: 'Loothprints',
  loothcuts: 'Loothcuts',
  document: 'Documents',
  event: 'Events',
  discussion: 'Discussions',
  profile: 'Profiles',
  benefit: 'Benefits',
  'sponsor-post': 'Sponsor Posts',
  'shorty': 'Shorts',
  'useful_links': 'Useful Links',
  misc: 'Misc',
};
const KIND_ORDER = ['article','video','loothprint','loothcuts','document','discussion','profile','benefit','sponsor-post','shorty','useful_links']; // 'event' + 'misc' intentionally omitted — not user-facing search types

const state = {
  q: '',
  kind: '',           // '' = all
  tier: [],           // ['public','lite','pro']
  tag: [],            // tag slugs
  author: 0,          // 0 = no author filter, else author_id (int)
  authorLabel: '',    // cached label for the active author (for renderMeta)
  sort: 'newest',
  limit: 24,
  page: 1,            // 1-indexed
  offset: 0,          // derived: (page - 1) * limit
  total: 0,
  peopleTotal: 0,     // count of authors with posts/discussions (People tab)
  facets: { kind: [], tier: [], tag: [], author: [] },
};

// ---- DOM ------------------------------------------------------------------
const $q          = document.getElementById('q');
const $sort       = document.getElementById('sort');
const $tabs       = document.getElementById('tabs');
const $tagSec     = document.getElementById('rail-sec-tag');
const $pills      = document.getElementById('tag-pills');
const $authorSec  = document.getElementById('rail-sec-author');
const $authorPills= document.getElementById('author-pills');
const $tierSec    = document.getElementById('rail-sec-tier');
const $tierPills  = document.getElementById('tier-pills');
const $cards      = document.getElementById('cards');
const $meta       = document.getElementById('results-meta');
const $activeFilt = document.getElementById('active-filters');
const $authorBan  = document.getElementById('author-banner');
const $pagination = document.getElementById('pagination');
const $loadmsg    = document.getElementById('loadmore-status');
const $railToggle = document.getElementById('rail-toggle');
const $railClose  = document.getElementById('rail-close');
const $railClear  = document.getElementById('rail-clear');

// ---- URL sync -------------------------------------------------------------
function syncFromURL() {
  const p = new URLSearchParams(location.search);
  state.q      = p.get('q')    || '';
  state.kind   = p.get('kind') || '';
  state.tier   = (p.get('tier') || '').split(',').filter(Boolean);
  state.tag    = (p.get('tag')  || '').split(',').filter(Boolean);
  state.author = parseInt(p.get('author') || '0', 10) || 0;
  state.sort   = p.get('sort') || 'newest';
  state.page   = Math.max(1, parseInt(p.get('page') || '1', 10) || 1);
  state.offset = (state.page - 1) * state.limit;
  $q.value = state.q;
  const _chromeQ = document.getElementById('chrome-q');
  if (_chromeQ) _chromeQ.value = state.q;
  $sort.value = state.sort;
  const _sortRail = document.getElementById('sort-rail');
  if (_sortRail) _sortRail.value = state.sort;
}
function syncToURL() {
  const p = new URLSearchParams();
  if (state.q)    p.set('q', state.q);
  if (state.kind) p.set('kind', state.kind);
  if (state.tier.length) p.set('tier', state.tier.join(','));
  if (state.tag.length)  p.set('tag', state.tag.join(','));
  if (state.author)      p.set('author', state.author);
  if (state.sort && state.sort !== 'newest') p.set('sort', state.sort);
  if (state.page > 1)    p.set('page', state.page);
  const qs = p.toString();
  const url = qs ? `?${qs}` : location.pathname;
  history.replaceState(null, '', url);
}

// ---- API fetch ------------------------------------------------------------
async function fetchSearch() {
  const p = new URLSearchParams();
  if (state.q)    p.set('q', state.q);
  // 'people' is a pseudo-kind (matching authors, not a content kind) — don't
  // send it as kind; instead ask for the paginated people list via ?people=1.
  if (state.kind && state.kind !== 'people') p.set('kind', state.kind);
  if (state.kind === 'people') p.set('people', '1');
  if (state.tier.length) p.set('tier', state.tier.join(','));
  if (state.tag.length)  p.set('tag',  state.tag.join(','));
  if (state.author)      p.set('author_id', state.author);
  p.set('sort', state.sort);
  p.set('limit', state.limit);
  p.set('offset', state.offset);

  $loadmsg.textContent = 'loading…';
  const t0 = performance.now();
  const r = await fetch(`${API}/search?${p}`, { credentials: 'same-origin' });
  if (!r.ok) {
    $loadmsg.textContent = `error: HTTP ${r.status}`;
    return;
  }
  const data = await r.json();
  const elapsed = Math.round(performance.now() - t0);

  state.facets      = data.facets || { kind: [], tier: [], tag: [], author: [] };
  state.peopleTotal = data.people_total || 0;
  // On the People tab, pagination is over people; otherwise over content.
  state.total = (state.kind === 'people') ? (data.people_total || 0) : data.total;

  // Cache the active author's label so renderMeta can show it even when the
  // current page's facet list happens not to include the selected author.
  if (state.author) {
    const hit = (state.facets.author || []).find(a => a.v === state.author);
    if (hit) state.authorLabel = hit.label;
  } else {
    state.authorLabel = '';
  }

  renderTabs();
  renderTagPills();
  renderAuthorPills();
  renderTierPills();
  renderActiveFilters();
  renderAuthorBanner();
  if (state.kind === 'people') {
    renderPeople(data.people || []);
    renderMeta(data, elapsed, state.total);
    renderPagination();   // people paginate too
  } else {
    renderResults(data.items);
    renderMeta(data, elapsed);
    renderPagination();
  }
  $loadmsg.textContent = '';
}

// ---- Render ---------------------------------------------------------------
function renderTabs() {
  const totalAll = state.facets.kind.reduce((a, b) => a + b.n, 0);
  const tabs = [{ v: '', n: totalAll, label: 'All' }];
  for (const k of KIND_ORDER) {
    const f = state.facets.kind.find(x => x.v === k);
    if (f) tabs.push({ v: k, n: f.n, label: KIND_LABELS[k] || k });
  }
  // "People" pseudo-kind — authors with posts or discussions in the current
  // result set. Count comes from the server (people_total), so it's accurate
  // beyond the 20-row author-facet cap.
  const peopleN = state.peopleTotal || 0;
  if (peopleN || state.kind === 'people') {
    tabs.push({ v: 'people', n: peopleN, label: 'People' });
  }
  $tabs.innerHTML = tabs.map(t => {
    const active = (t.v === state.kind);
    return `<button class="tab${active ? ' is-active' : ''}" data-kind="${t.v}">${t.label} <span class="n">${t.n}</span></button>`;
  }).join('');
}

// People view — render matching authors as cards (avatar + name + post count).
// Clicking a card filters the archive to that author's content.
function renderPeople(authors) {
  $cards.innerHTML = '';
  if (!authors.length) {
    $cards.innerHTML = `<div class="empty">No people match. Try a different search.</div>`;
    return;
  }
  const frag = document.createDocumentFragment();
  for (const a of authors) {
    const card = document.createElement('button');
    card.type = 'button';
    card.className = 'person-card';
    card.setAttribute('data-card-author', a.v);
    card.setAttribute('data-card-author-name', a.label || '');
    // Real avatar → show the photo; otherwise a sage circle with the initial.
    const avatar = a.avatar_url
      ? `<span class="person-card__avatar" style="background-image:url('${escapeHtml(a.avatar_url)}')"></span>`
      : `<span class="person-card__avatar">${escapeHtml((a.label || '?').trim().charAt(0).toUpperCase())}</span>`;
    card.innerHTML =
      avatar +
      `<span class="person-card__body">` +
        `<span class="person-card__name">${escapeHtml(a.label || 'Member')}</span>` +
        `<span class="person-card__count">${a.n} post${a.n === 1 ? '' : 's'}</span>` +
      `</span>`;
    frag.appendChild(card);
  }
  $cards.appendChild(frag);
}

function renderTagPills() {
  const tags = state.facets.tag.slice(0, 24);
  if (tags.length === 0) { $tagSec.hidden = true; return; }
  $tagSec.hidden = false;
  $pills.innerHTML = tags.map(t => {
    const active = state.tag.includes(t.v);
    return `<button class="pill${active ? ' is-active' : ''}" data-tag="${t.v}">${escapeHtml(t.label)} <span class="n">${t.n}</span></button>`;
  }).join('');
}

function renderTierPills() {
  // Fixed two-option set: Looth Lite + Looth Pro. We display them even when
  // a tier facet count is zero (so the user can still click to see why).
  const LABELS = { lite: 'Looth Lite', pro: 'Looth Pro' };
  const byV = Object.fromEntries((state.facets.tier || []).map(t => [t.v, t.n]));
  const opts = ['lite', 'pro'];
  $tierSec.hidden = false;
  $tierPills.innerHTML = opts.map(v => {
    const active = state.tier.includes(v);
    const n = byV[v] || 0;
    return `<button class="pill${active ? ' is-active' : ''}" data-tier="${v}">${LABELS[v]} <span class="n">${n}</span></button>`;
  }).join('');
}

function renderAuthorPills() {
  const authors = (state.facets.author || []).slice(0, 12);
  // Hide if there's nothing useful to filter by (one or zero distinct authors).
  if (authors.length < 2) { $authorSec.hidden = true; $authorPills.innerHTML = ''; return; }
  $authorSec.hidden = false;
  $authorPills.innerHTML = authors.map(a => {
    const active = state.author === a.v;
    return `<button class="pill${active ? ' is-active' : ''}" data-author-id="${a.v}">${escapeHtml(a.label)} <span class="n">${a.n}</span></button>`;
  }).join('');
}

// Cache of fetched author profiles so flipping pages or toggling other filters
// doesn't refetch. Cleared implicitly when state.author changes to a new id.
const _authorCache = new Map();
const SOCIAL_ICONS = {
  website:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>',
  instagram: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>',
  facebook:  '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 10h3l.5-3H13V5c0-.9.3-1.5 1.6-1.5H17V.8C16.6.7 15.3.5 13.9.5 11 .5 9.1 2.3 9.1 5.6V7H6v3h3.1v8H13v-8z"/></svg>',
  youtube:   '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21.6 7.2s-.2-1.4-.8-2c-.7-.8-1.5-.8-1.9-.8C16 4.2 12 4.2 12 4.2s-4 0-6.9.2c-.4.1-1.2.1-1.9.8-.6.6-.8 2-.8 2S2.2 8.8 2.2 10.5v1.5c0 1.7.2 3.3.2 3.3s.2 1.4.8 2c.7.8 1.7.7 2.1.8 1.6.2 6.7.2 6.7.2s4 0 6.9-.2c.4-.1 1.2-.1 1.9-.8.6-.6.8-2 .8-2s.2-1.7.2-3.3v-1.5c0-1.7-.2-3.3-.2-3.3zM10 14V8l5 3-5 3z"/></svg>',
  linktree:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18"/><path d="M5 8l7-5 7 5"/><path d="M5 14l7 5 7-5"/></svg>',
};

async function renderAuthorBanner() {
  if (!state.author) { $authorBan.hidden = true; $authorBan.innerHTML = ''; return; }
  const id = state.author;

  if (!_authorCache.has(id)) {
    // Render skeleton immediately so it doesn't pop in late.
    $authorBan.hidden = false;
    $authorBan.innerHTML = '<div class="author-banner__avatar" aria-hidden="true"></div><div class="author-banner__body"><p class="author-banner__name">Loading…</p></div>';
    try {
      const r = await fetch(`/wp-json/looth/v1/author/${id}`, { credentials: 'include' });
      if (!r.ok) throw new Error('HTTP ' + r.status);
      _authorCache.set(id, await r.json());
    } catch (_) {
      _authorCache.set(id, null);
    }
    // Bail if user changed filter while we were fetching.
    if (state.author !== id) return;
  }

  const data = _authorCache.get(id);
  if (!data) { $authorBan.hidden = true; $authorBan.innerHTML = ''; return; }

  // Backfill label so the active-filter pill shows the name not the raw ID.
  if (state.author === id && data.name && !state.authorLabel) {
    state.authorLabel = data.name;
    renderActiveFilt();
  }

  const socials = data.socials || {};
  const socialHtml = Object.keys(SOCIAL_ICONS)
    .filter(k => socials[k])
    .map(k => `<a href="${escapeHtml(socials[k])}" target="_blank" rel="noopener" aria-label="${k}" title="${k}">${SOCIAL_ICONS[k]}</a>`)
    .join('');

  const profileUrl = data.looth_profile || data.profile_url || '#';
  $authorBan.hidden = false;
  // Bio (single-source author bio) renders inline under the name; empty/null → nothing.
  $authorBan.innerHTML = `
    <img class="author-banner__avatar" src="${escapeHtml(data.avatar_url || '')}" alt="" loading="lazy">
    <div class="author-banner__body">
      <h2 class="author-banner__name"><a href="${escapeHtml(profileUrl)}">${escapeHtml(data.name || 'Member')}</a></h2>
      ${data.bio ? `<p class="author-banner__bio">${escapeHtml(data.bio)}</p>` : ''}
    </div>
    ${socialHtml ? `<div class="author-banner__socials">${socialHtml}</div>` : ''}
  `;
}

// Author bio modal — opens when the info button on the banner is clicked.
(function authorBioModal() {
  const modal = document.getElementById('author-bio-modal');
  const body  = document.getElementById('author-bio-body');
  if (!modal || !body) return;

  function open() {
    if (!state.author) return;
    const data = _authorCache.get(state.author);
    if (!data) return;
    const socials = data.socials || {};
    const socialHtml = Object.keys(SOCIAL_ICONS)
      .filter(k => socials[k])
      .map(k => `<a href="${escapeHtml(socials[k])}" target="_blank" rel="noopener" aria-label="${k}" title="${k}">${SOCIAL_ICONS[k]}</a>`)
      .join('');
    const profileUrl = data.looth_profile || data.profile_url || '#';
    body.innerHTML = `
      <div class="author-bio-modal__head">
        <img class="author-bio-modal__avatar" src="${escapeHtml(data.avatar_url || '')}" alt="">
        <h2 class="author-bio-modal__name" id="author-bio-name"><a href="${escapeHtml(profileUrl)}" style="color:inherit;text-decoration:none">${escapeHtml(data.name || 'Member')}</a></h2>
      </div>
      ${data.bio ? `<p class="author-bio-modal__bio">${escapeHtml(data.bio)}</p>` : ''}
      ${socialHtml ? `<div class="author-banner__socials">${socialHtml}</div>` : ''}
    `;
    modal.hidden = false;
    modal.removeAttribute('aria-hidden');
    document.body.classList.add('lg-modal-open');
  }
  function close() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('lg-modal-open');
  }

  document.addEventListener('click', e => {
    if (e.target.closest('[data-author-bio]')) { e.preventDefault(); open(); }
    if (e.target.closest('[data-author-bio-close]')) close();
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !modal.hidden) close();
  });
})();

function renderActiveFilters() {
  const chips = [];
  const X = '<svg class="chip__x-icon" viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
  const mk = (label, val, action) =>
    `<span class="chip">${label} <b>${escapeHtml(val)}</b><button class="chip__x" data-clear="${action}" aria-label="Remove ${escapeHtml(label)} filter">${X}</button></span>`;

  if (state.q)              chips.push(mk('', state.q, 'q'));
  if (state.kind)           chips.push(mk('Type', state.kind === 'people' ? 'People' : (KIND_LABELS[state.kind] || state.kind), 'kind'));
  for (const slug of state.tag) {
    chips.push(`<span class="chip">Tag <b>${escapeHtml(slug)}</b><button class="chip__x" data-clear="tag:${escapeHtml(slug)}" aria-label="Remove tag ${escapeHtml(slug)}">${X}</button></span>`);
  }
  if (state.author)         chips.push(mk('Author', state.authorLabel || ('#' + state.author), 'author'));
  if (state.tier.length)    chips.push(mk('Tier', state.tier.join(','), 'tier'));

  const hasFilters = chips.length > 0;
  if ($railClear) $railClear.hidden = !hasFilters;
  if (!hasFilters) { $activeFilt.hidden = true; $activeFilt.innerHTML = ''; return; }
  chips.push('<button class="chip__clear" data-clear="all">Clear all</button>');
  $activeFilt.hidden = false;
  $activeFilt.innerHTML = chips.join('');
}

function renderPagination() {
  const totalPages = Math.max(1, Math.ceil(state.total / state.limit));
  if (totalPages <= 1) { $pagination.hidden = true; $pagination.innerHTML = ''; return; }
  $pagination.hidden = false;

  // Build a window: 1, …, current-2..current+2, …, last. Always show first/last,
  // collapse stretches >1 into a gap.
  const cur = state.page;
  const wanted = new Set([1, totalPages, cur - 1, cur, cur + 1, cur - 2, cur + 2]);
  const pages = [...wanted].filter(n => n >= 1 && n <= totalPages).sort((a, b) => a - b);

  const parts = [];
  parts.push(`<button data-page="${cur - 1}"${cur === 1 ? ' disabled' : ''} aria-label="Previous page">‹</button>`);
  let last = 0;
  for (const n of pages) {
    if (n - last > 1) parts.push('<span class="gap">…</span>');
    parts.push(`<button data-page="${n}"${n === cur ? ' class="is-current" aria-current="page"' : ''}>${n}</button>`);
    last = n;
  }
  parts.push(`<button data-page="${cur + 1}"${cur === totalPages ? ' disabled' : ''} aria-label="Next page">›</button>`);
  $pagination.innerHTML = parts.join('');
}

function renderResults(items) {
  $cards.innerHTML = '';
  if (!items.length) {
    $cards.innerHTML = `<div class="empty">No results. Try a different query or tab.</div>`;
    return;
  }
  const frag = document.createDocumentFragment();
  for (const it of items) frag.appendChild(renderCard(it));
  $cards.appendChild(frag);
}

function renderCard(it) {
  const a = document.createElement('a');
  a.className = 'card';
  a.href = it.url || '#';

  const thumb = (it.thumb_url && !it.thumb_broken)
    ? it.thumb_url
    : 'https://loothgroup.com/wp-content/uploads/2024/11/Featured-Image-Fallback-2.webp';

  const tier = (it.tier || 'public').toLowerCase();
  const tierLabel = tier === 'pro' ? 'Pro' : tier === 'lite' ? 'Lite' : 'Public';
  const date = it.published_at ? new Date(it.published_at * 1000).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '';

  // Meta bits — show more, gated by data presence not by kind. Tiny inline
  // SVGs so layout is fixed-width (no width jitter while icons paint).
  const ICON = {
    chat:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    eye:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
    clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
  };
  let metaBits = [];
  if (it.author && it.author.name) {
    const aid = it.author.id || 0;
    metaBits.push(aid
      ? `<button type="button" class="author author--link" data-card-author="${aid}" data-card-author-name="${escapeHtml(it.author.name)}">${escapeHtml(it.author.name)}</button>`
      : `<span class="author">${escapeHtml(it.author.name)}</span>`);
  }
  if (date) metaBits.push(`<span>${date}</span>`);
  if (it.reply_count) metaBits.push(`<span class="stat" title="${it.reply_count} ${it.reply_count === 1 ? 'reply' : 'replies'}">${ICON.chat}${it.reply_count}</span>`);
  if (it.view_count)  metaBits.push(`<span class="stat" title="${it.view_count} views">${ICON.eye}${it.view_count}</span>`);
  if (it.duration_min) metaBits.push(`<span class="stat" title="${it.duration_min} min">${ICON.clock}${it.duration_min}m</span>`);

  // Heart — uses the same [data-like] click handler as the activity cards.
  // For search results, `activity_id` will be wired up once the search API
  // returns it (sqlite index needs the column). Until then, the button is
  // visual only when activity_id is absent — clicks fall through to opening
  // the linked post.
  const liked   = !!it.liked_by_me;
  const likeNum = it.like_count || 0;
  const likeBtn = it.activity_id
    ? `<button type="button" class="card__like${liked ? ' is-liked' : ''}" data-like data-activity-id="${it.activity_id}" aria-pressed="${liked}" aria-label="Like">
         <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-4.35-9.5-9.18C.87 8.4 2.92 4.5 6.6 4.5c1.86 0 3.4 1 4.4 2.5 1-1.5 2.54-2.5 4.4-2.5 3.68 0 5.73 3.9 4.1 7.32C19 16.65 12 21 12 21z"/></svg>
         <span data-react-total>${likeNum}</span>
       </button>`
    : (likeNum ? `<span class="card__like" aria-label="${likeNum} likes" style="cursor:default">
         <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 21s-7-4.35-9.5-9.18C.87 8.4 2.92 4.5 6.6 4.5c1.86 0 3.4 1 4.4 2.5 1-1.5 2.54-2.5 4.4-2.5 3.68 0 5.73 3.9 4.1 7.32C19 16.65 12 21 12 21z"/></svg>
         <span>${likeNum}</span>
       </span>` : '');

  // Tag pills (clickable filter). Cap to 6 to avoid the card foot wrapping
  // into a tag wall.
  const tags = Array.isArray(it.tags) ? it.tags.slice(0, 6) : [];
  const tagHtml = tags
    .map(t => `<button type="button" class="card__tag" data-card-tag="${escapeHtml(t.slug || t.v || '')}">${escapeHtml(t.label || t.slug || t.v || '')}</button>`)
    .join('');

  a.innerHTML = `
    <img class="card__img" src="${thumb}" alt="" loading="lazy" onerror="this.onerror=null;this.src='https://loothgroup.com/wp-content/uploads/2024/11/Featured-Image-Fallback-2.webp'">
    <div class="card__body">
      <h3 class="card__title">${escapeHtml(it.title || '(untitled)')}</h3>
      <div class="card__meta">${metaBits.join('<span class="dot">·</span>')}</div>
      <p class="card__excerpt">${escapeHtml(it.excerpt || '')}</p>
      ${tagHtml ? `<div class="card__tags">${tagHtml}</div>` : ''}
      <div class="card__foot">
        <span class="badge badge--${tier}">${tierLabel}</span>
        <span class="badge badge--kind">${escapeHtml(KIND_LABELS[it.kind] || it.kind)}</span>
        ${it.kind === 'discussion' && it.forum    ? `<span class="badge badge--forum">${escapeHtml(it.forum)}</span>` : ''}
        ${it.kind === 'discussion' && it.subforum ? `<span class="badge badge--subforum">${escapeHtml(it.subforum)}</span>` : ''}
        ${likeBtn}
      </div>
    </div>`;
  return a;
}

// Delegated click handler — intercepts in-card filter triggers (author + tag
// pills) so they apply the filter instead of opening the card link.
$cards.addEventListener('click', (e) => {
  const authorBtn = e.target.closest('[data-card-author]');
  if (authorBtn) {
    e.preventDefault(); e.stopPropagation();
    const id   = parseInt(authorBtn.dataset.cardAuthor, 10) || 0;
    const name = authorBtn.dataset.cardAuthorName || '';
    if (id) {
      state.author = id;
      state.authorLabel = name;
      // Picking a person from the People tab → leave that view and show ALL of
      // their content (drop the name query, like the search modal's People links).
      if (state.kind === 'people') {
        state.kind = '';
        state.q = '';
        if ($q) $q.value = '';
      }
      applyAndFetch();
    }
    return;
  }
  const tagBtn = e.target.closest('[data-card-tag]');
  if (tagBtn) {
    e.preventDefault(); e.stopPropagation();
    const slug = tagBtn.dataset.cardTag;
    if (slug && !state.tag.includes(slug)) {
      state.tag.push(slug);
      applyAndFetch();
    }
  }
});

function renderMeta(data, elapsed, peopleCount) {
  const filters = [];
  if (state.q) filters.push(`q=<b>"${escapeHtml(state.q)}"</b>`);
  if (state.kind && state.kind !== 'people') filters.push(`kind=<b>${escapeHtml(state.kind)}</b>`);
  if (state.tier.length) filters.push(`tier=<b>${state.tier.join(',')}</b>`);
  if (state.tag.length)  filters.push(`tag=<b>${state.tag.map(escapeHtml).join(',')}</b>`);
  if (state.author)      filters.push(`author=<b>${escapeHtml(state.authorLabel || ('#' + state.author))}</b>`);
  const filtStr = filters.length ? ' · ' + filters.join(', ') : '';

  // People view: count authors, not content rows, and no pagination.
  if (state.kind === 'people') {
    const n = peopleCount || 0;
    $meta.innerHTML = `<b>${n.toLocaleString()}</b> ${n === 1 ? 'person' : 'people'}${filtStr} <span class="ms">· ${elapsed}ms total · ${data.meta.elapsed_ms}ms server</span>`;
    return;
  }

  const totalPages = Math.max(1, Math.ceil(data.total / state.limit));
  const pageStr = totalPages > 1 ? ` · page <b>${state.page}</b>/${totalPages}` : '';
  $meta.innerHTML = `<b>${data.total.toLocaleString()}</b> result${data.total === 1 ? '' : 's'}${filtStr}${pageStr} <span class="ms">· ${elapsed}ms total · ${data.meta.elapsed_ms}ms server</span>`;
}

// ---- Helpers --------------------------------------------------------------
function escapeHtml(s) {
  return (s == null ? '' : String(s)).replace(/[&<>"']/g, c => (
    {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]
  ));
}
function debounce(fn, ms) {
  let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

// ---- Wiring --------------------------------------------------------------
function applyAndFetch({ resetPage = true, scroll = false } = {}) {
  if (resetPage) {
    state.page = 1;
    state.offset = 0;
    // By default we DON'T scroll on filter/search changes — yanking the page up
    // on every tab/tag/typing toggle is jarring. Pagination scrolls on its own
    // (goToPage). Callers can opt in with scroll:true if ever needed.
    if (scroll) {
      const layout = document.querySelector('.grid-layout');
      if (layout) {
        const top = layout.getBoundingClientRect().top + window.scrollY - 80; // leave room for sticky chrome
        window.scrollTo({ top: Math.max(0, top), behavior: 'instant' });
      }
    }
  }
  syncToURL();
  fetchSearch();
}

function goToPage(n) {
  const totalPages = Math.max(1, Math.ceil(state.total / state.limit));
  const target = Math.max(1, Math.min(totalPages, n | 0));
  if (target === state.page) return;
  state.page = target;
  state.offset = (target - 1) * state.limit;
  syncToURL();
  fetchSearch();
  // Scroll to top of grid so the user sees the new page.
  const top = document.getElementById('subfilters');
  if (top) top.scrollIntoView({ block: 'start', behavior: 'smooth' });
}

// Mode flip: search input or tab click → grid mode. Esc / empty filters → discover.
function enterGrid() {
  if (document.body.classList.contains('view-grid')) return;
  document.body.classList.remove('view-discover');
  document.body.classList.add('view-grid');
  $sort.hidden = false;
  // Hiding the discover content shrinks the document; the browser would
  // otherwise clamp the user's scrollTop to the new (much shorter) max,
  // leaving them parked at the bottom of the grid.
  window.scrollTo({ top: 0, behavior: 'instant' });
}
function enterDiscover() {
  if (window.__LG_SEARCH_PAGE__) {
    // Search page has no discover feed — "discover" means browse all content.
    enterGrid();
    fetchSearch();
    return;
  }
  document.body.classList.remove('view-grid');
  document.body.classList.add('view-discover');
  $sort.hidden = true;
  history.replaceState(null, '', location.pathname);
}

// Chrome inline search input — focusing or typing opens the search modal.
// The modal handles the search; the chrome input just seeds it.
const $chromeQ = document.getElementById('chrome-q');
if ($chromeQ) {
  const openSearchModal = () => {
    const searchModal = document.getElementById('search-modal');
    if (!searchModal || !searchModal.hidden) return;
    const ev = new CustomEvent('lg:open-search-modal', { bubbles: true });
    document.dispatchEvent(ev);
  };
  $chromeQ.addEventListener('focus', openSearchModal);
  $chromeQ.addEventListener('keydown', e => {
    if (e.key === 'Escape') { $chromeQ.value = ''; $q.value = ''; enterDiscover(); }
    else openSearchModal();
  });
  // Mirror programmatic resets back to chrome from main search.
  $q.addEventListener('input', () => { if ($chromeQ.value !== $q.value) $chromeQ.value = $q.value; });
}

$q.addEventListener('input', debounce(e => {
  state.q = e.target.value.trim();
  if (state.q === '' && state.kind === '' && state.tag.length === 0 && state.tier.length === 0) {
    enterDiscover();
    return;
  }
  enterGrid();
  if (state.q && state.sort === 'newest') { state.sort = 'relevance'; $sort.value = 'relevance'; }
  if (!state.q && state.sort === 'relevance') { state.sort = 'newest'; $sort.value = 'newest'; }
  applyAndFetch({ scroll: false });   // don't yank the page up while typing
}, 200));

$q.addEventListener('keydown', e => {
  if (e.key === 'Escape') { $q.value = ''; state.q = ''; enterDiscover(); }
});

$sort.addEventListener('change', e => { state.sort = e.target.value; applyAndFetch(); });

// Visible sort control (rail variant) — mirrors the hidden #sort.
const $sortRail = document.getElementById('sort-rail');
if ($sortRail) {
  $sortRail.value = state.sort;
  $sortRail.addEventListener('change', e => {
    state.sort = e.target.value;
    if ($sort) $sort.value = e.target.value;
    applyAndFetch();
  });
}

$tabs.addEventListener('click', e => {
  const b = e.target.closest('button.tab'); if (!b) return;
  state.kind = b.dataset.kind || '';
  state.tag = []; // reset tags when changing kind
  enterGrid();
  applyAndFetch();
});

$pills.addEventListener('click', e => {
  const b = e.target.closest('button.pill'); if (!b) return;
  const slug = b.dataset.tag;
  const idx = state.tag.indexOf(slug);
  if (idx >= 0) state.tag.splice(idx, 1); else state.tag.push(slug);
  applyAndFetch();
});

$authorPills.addEventListener('click', e => {
  const b = e.target.closest('button.pill'); if (!b) return;
  const id = parseInt(b.dataset.authorId, 10) || 0;
  // Toggle: clicking the active author clears the filter.
  state.author = (state.author === id) ? 0 : id;
  state.authorLabel = state.author ? (b.textContent.replace(/\s+\d+\s*$/, '').trim()) : '';
  applyAndFetch();
});

$tierPills.addEventListener('click', e => {
  const b = e.target.closest('button.pill'); if (!b) return;
  const v = b.dataset.tier;
  const i = state.tier.indexOf(v);
  if (i >= 0) state.tier.splice(i, 1); else state.tier.push(v);
  applyAndFetch();
});

$pagination.addEventListener('click', e => {
  const b = e.target.closest('button[data-page]'); if (!b || b.disabled) return;
  goToPage(parseInt(b.dataset.page, 10));
});

// Active-filter chip ✕ buttons. data-clear values:
//   "kind" | "author" | "tier" | "tag:<slug>" | "all"
$activeFilt.addEventListener('click', e => {
  const b = e.target.closest('[data-clear]'); if (!b) return;
  const v = b.dataset.clear;
  if (v === 'all') {
    state.q = ''; state.kind = ''; state.tag = []; state.author = 0; state.authorLabel = ''; state.tier = [];
    if ($q) { $q.value = ''; $q.dispatchEvent(new Event('input', { bubbles: true })); }
  } else if (v === 'q')     { state.q = ''; if ($q) { $q.value = ''; } }
  else if (v === 'kind')    state.kind = '';
  else if (v === 'author')  { state.author = 0; state.authorLabel = ''; }
  else if (v === 'tier')    { state.tier = []; }
  else if (v.startsWith('tag:')) {
    const slug = v.slice(4);
    const i = state.tag.indexOf(slug);
    if (i >= 0) state.tag.splice(i, 1);
  }
  // If we've cleared everything AND there's no query, drop back to discover.
  if (!state.q && !state.kind && !state.tag.length && !state.author && !state.tier.length) {
    enterDiscover();
    return;
  }
  applyAndFetch();
});

// Mobile drawer: toggle body.rail-open
if ($railClear) $railClear.addEventListener('click', () => {
  // Same effect as the chip-strip "Clear all".
  $activeFilt.querySelector('[data-clear="all"]')?.click()
    || (() => {
      state.q = ''; state.kind = ''; state.tag = []; state.author = 0; state.authorLabel = ''; state.tier = [];
      if ($q) $q.value = '';
      enterDiscover(); return;
      applyAndFetch();
    })();
});

if ($railToggle) $railToggle.addEventListener('click', () => {
  const open = document.body.classList.toggle('rail-open');
  $railToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
});
if ($railClose) $railClose.addEventListener('click', () => {
  document.body.classList.remove('rail-open');
  if ($railToggle) $railToggle.setAttribute('aria-expanded', 'false');
});
// Tap the scrim (the ::after overlay) — listen on body, dismiss when click is outside rail
document.addEventListener('click', e => {
  if (!document.body.classList.contains('rail-open')) return;
  const rail = document.getElementById('grid-rail');
  if (rail && !rail.contains(e.target) && e.target !== $railToggle && !$railToggle?.contains(e.target)) {
    document.body.classList.remove('rail-open');
    if ($railToggle) $railToggle.setAttribute('aria-expanded', 'false');
  }
});

// Initial: honor URL params. If any filter is present we land in grid view.
// Otherwise the SSR'd discover rows stay visible and we DO NOT call /search
// (saves a round-trip + keeps initial render byte-for-byte what Googlebot saw).
// On the dedicated search page (window.__LG_SEARCH_PAGE__) with no query,
// show the empty state (#discover prompt) rather than an empty grid.
syncFromURL();
const ssrPresent = !!window.__ROWS__ && document.querySelector('#rows .row');
const hasFilters = state.q || state.kind || state.tier.length || state.tag.length || state.author;
if (ssrPresent && !hasFilters) {
  enterDiscover();
} else {
  enterGrid();
  fetchSearch();
}


// Chrome behaviors: mobile menu toggle + header search button focuses #q.
(function () {
  const ham = document.querySelector('[data-mobile-toggle]');
  const chrome = document.querySelector('.lg-chrome');
  if (ham && chrome) {
    ham.addEventListener('click', () => {
      const open = chrome.hasAttribute('data-mobile-open');
      if (open) chrome.removeAttribute('data-mobile-open');
      else      chrome.setAttribute('data-mobile-open', '');
      ham.setAttribute('aria-expanded', !open);
    });
  }
  const searchBtn = document.querySelector('[data-chrome-search]');
  const qInput = document.getElementById('q');
  if (searchBtn && qInput) {
    searchBtn.addEventListener('click', () => {
      qInput.scrollIntoView({block:'start', behavior:'smooth'});
      qInput.focus();
    });
  }
})();


/* ===== Rail hover arrows ===== */
(function initRailArrows() {
  const rows = document.querySelectorAll('.row');
  rows.forEach(row => {
    const rail = row.querySelector('.rail');
    if (!rail) return;
    if (row.querySelector('.row__arrow')) return; // already wired
    if (row.classList.contains('row--activity')) return; // activity row has its own .acard-nav

    const prev = document.createElement('button');
    prev.type = 'button';
    prev.className = 'row__arrow row__arrow--prev';
    prev.setAttribute('aria-label', 'Scroll left');
    prev.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>';

    const next = document.createElement('button');
    next.type = 'button';
    next.className = 'row__arrow row__arrow--next';
    next.setAttribute('aria-label', 'Scroll right');
    next.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>';

    function update() {
      const max = rail.scrollWidth - rail.clientWidth;
      prev.disabled = rail.scrollLeft <= 4;
      next.disabled = rail.scrollLeft >= max - 4;
    }
    function step(direction) {
      // Scroll by ~90% of viewport-width of the rail (matches Netflix feel)
      const dist = Math.max(300, Math.round(rail.clientWidth * 0.9));
      rail.scrollBy({ left: dist * direction, behavior: 'smooth' });
    }
    prev.addEventListener('click', () => step(-1));
    next.addEventListener('click', () => step(1));
    rail.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update, { passive: true });

    row.appendChild(prev);
    row.appendChild(next);
    update();
  });
})();

/* ===== Hero search proxies to main search ===== */
(function wireHeroSearch() {
  const heroInput = document.getElementById('q-hero');
  const mainInput = document.getElementById('q');
  if (!heroInput || !mainInput) return;
  heroInput.addEventListener('input', () => {
    mainInput.value = heroInput.value;
    mainInput.dispatchEvent(new Event('input', { bubbles: true }));
  });
  heroInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      mainInput.focus();
      mainInput.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
})();


/* ===== Back-to-Discover affordance ===== */
(function backToDiscover() {
  const $body = document.body;
  const $topbarStandalone = document.querySelector('.topbar.topbar--standalone');
  const $heroSearch = document.getElementById('q-hero');
  const $mainSearch = document.getElementById('q');

  function clearAndGoHome() {
    if ($mainSearch) { $mainSearch.value = ''; $mainSearch.dispatchEvent(new Event('input', { bubbles: true })); }
    if ($heroSearch) { $heroSearch.value = ''; }
    // Force-clear other filter state too
    if (typeof state !== 'undefined') {
      state.q = ''; state.kind = ''; state.tag = []; state.tier = [];
    }
    if (typeof enterDiscover === 'function') enterDiscover();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  window.__lgBackToDiscover = clearAndGoHome;

  // Wire chrome logo + "Front page" link + grid-mode back pill → clear & go home
  document.querySelectorAll('.lg-chrome__logo, .lg-chrome__menu a[href$="/front-page/"], .lg-chrome__menu a[href="/"], .lg-chrome__back').forEach(el => {
    el.addEventListener('click', (e) => {
      if (window.location.pathname.replace(/\/$/, '') === '/front-page') {
        e.preventDefault();
        clearAndGoHome();
      }
    });
  });

  // Inject a back pill into the standalone topbar (shows only in view-grid via CSS)
  if ($topbarStandalone && !$topbarStandalone.querySelector('.back-pill')) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'back-pill';
    btn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg> Discover';
    btn.addEventListener('click', clearAndGoHome);
    $topbarStandalone.insertBefore(btn, $topbarStandalone.firstChild);
  }
})();


/* ===== Chrome magnifier → opens the same search modal as the sidebar CTA.
   Was: toggled an inline drop-down topbar. Now: unified one-modal UX. */
(function chromeSearchToModal() {
  const $btn = document.querySelector('[data-chrome-search]');
  if (!$btn) return;
  // Tag so the existing [data-action="open-search-modal"] click delegation
  // (further down in this file) picks it up. No bespoke handler needed.
  $btn.setAttribute('data-action', 'open-search-modal');
})();

// --- Activity strip: auto-advance + arrows + lazy-load ---
(function () {
  const strip = document.querySelector('.row--activity');
  if (!strip) return;
  const rail = strip.querySelector('[data-activity-rail]');
  const prev = strip.querySelector('.acard-nav--prev');
  const next = strip.querySelector('.acard-nav--next');
  if (!rail) return;

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const HARD_CAP = 60;
  const AUTO_MS  = 7000;
  let oldestWhen = Math.min(
    ...[...rail.querySelectorAll('.acard time[datetime]')]
      .map(t => Math.floor(new Date(t.getAttribute('datetime')).getTime() / 1000))
      .filter(n => !isNaN(n))
  );
  let lazyLoading = false;
  let lazyExhausted = false;

  function advance(dir) {
    const cards = rail.querySelectorAll('.acard');
    if (!cards.length) return;
    const stride = cards[0].getBoundingClientRect().width + 14; // gap
    const atEnd = rail.scrollLeft + rail.clientWidth >= rail.scrollWidth - 4;
    if (dir > 0 && atEnd) {
      rail.scrollTo({left: 0, behavior: 'smooth'});  // loop
    } else if (dir < 0 && rail.scrollLeft <= 0) {
      rail.scrollTo({left: rail.scrollWidth, behavior: 'smooth'});
    } else {
      rail.scrollBy({left: stride * dir, behavior: 'smooth'});
    }
  }

  if (prev) prev.addEventListener('click', () => advance(-1));
  if (next) next.addEventListener('click', () => advance(1));

  // Which axis the rail scrolls on: vertical in the desktop band, horizontal
  // on mobile. Null when it doesn't overflow at all.
  const railAxis = () => (rail.scrollHeight - rail.clientHeight > 20) ? 'y'
                       : (rail.scrollWidth  - rail.clientWidth  > 20) ? 'x' : null;

  // One-time "wiggle" on load: a few quick damped down-bobs so it's obvious the
  // rail scrolls. Tweens scrollTop directly (behavior:'smooth' is too slow for a
  // snappy wiggle). Skipped under reduced-motion. Waits for cards to lay out.
  (function wiggleOnLoad() {
    if (reducedMotion) return;
    setTimeout(() => {
      const a = railAxis();
      if (!a) return;
      const prop = a === 'y' ? 'scrollTop' : 'scrollLeft';
      const amp = 30, dur = 420, t0 = performance.now();
      const step = (now) => {
        const t = (now - t0) / dur;
        if (t >= 1) { rail[prop] = 0; return; }
        // |sin| over 1.5 cycles → three quick down-bobs, damped to rest.
        rail[prop] = Math.abs(Math.sin(t * Math.PI * 3)) * amp * (1 - t);
        requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    }, 450);
  })();

  // Auto-advance (paused on hover, focus-within, or reduced motion)
  let timer = null;
  function start() {
    if (reducedMotion || timer) return;
    timer = setInterval(() => advance(1), AUTO_MS);
  }
  function stop() { if (timer) { clearInterval(timer); timer = null; } }
  strip.addEventListener('mouseenter', stop);
  strip.addEventListener('mouseleave', start);
  strip.addEventListener('focusin', stop);
  strip.addEventListener('focusout', start);
  document.addEventListener('visibilitychange', () => document.hidden ? stop() : start());
  start();

  // Click-to-load-more: append a button card at the end of the rail.
  // Each click fetches 10 more items via the endpoint and appends them before the button.
  let loading = false;
  let exhausted = false;
  const PAGE_SIZE = 20;

  function makeLoadMoreButton() {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'acard acard--loadmore';
    btn.setAttribute('aria-label', 'Load more activity');
    btn.innerHTML = '<span class="acard__loadmore-inner"><svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg><span class="acard__loadmore-label">Load more</span></span>';
    btn.addEventListener('click', loadMore);
    return btn;
  }

  async function loadMore() {
    if (loading || exhausted) return;
    const cards = rail.querySelectorAll('.acard:not(.acard--loadmore)');
    if (cards.length >= HARD_CAP) {
      exhausted = true;
      const existing = rail.querySelector('.acard--loadmore');
      if (existing) existing.remove();
      return;
    }
    loading = true;
    const btn = rail.querySelector('.acard--loadmore');
    if (btn) {
      btn.disabled = true;
      btn.querySelector('.acard__loadmore-label').textContent = 'Loading…';
    }
    try {
      const r = await fetch(`/wp-json/looth/v1/activity?limit=${PAGE_SIZE}&before=${oldestWhen}`, { credentials: 'same-origin' });
      if (!r.ok) throw new Error('http ' + r.status);
      const data = await r.json();
      const items = data.items || [];
      if (!items.length) {
        exhausted = true;
        if (btn) btn.remove();
        return;
      }
      const frag = document.createDocumentFragment();
      // Mirror SSR grouping: consecutive text-only cards stack into pairs.
      let buf = [];
      const flushBuf = () => {
        if (buf.length >= 2) {
          const stack = document.createElement('div');
          stack.className = 'acard-stack';
          buf.forEach(c => stack.appendChild(renderActivityCard(c, true)));
          frag.appendChild(stack);
        } else if (buf.length === 1) {
          frag.appendChild(renderActivityCard(buf[0], false));
        }
        buf = [];
      };
      for (const it of items) {
        if (it.when && it.when < oldestWhen) oldestWhen = it.when;
        const meta = classifyActivity(it);
        if (meta.variant === 'text') {
          buf.push(it);
          if (buf.length === 2) flushBuf();
        } else {
          flushBuf();
          frag.appendChild(renderActivityCard(it, false));
        }
      }
      flushBuf();
      // Insert new cards BEFORE the load-more button, keeping it at the end
      if (btn) rail.insertBefore(frag, btn);
      else rail.appendChild(frag);
      enrichExternalCards();
    } catch (e) {
      console.warn('[activity] loadMore failed:', e);
    } finally {
      loading = false;
      const btnAfter = rail.querySelector('.acard--loadmore');
      if (btnAfter) {
        btnAfter.disabled = false;
        btnAfter.querySelector('.acard__loadmore-label').textContent = 'Load more';
      }
    }
  }

  // Inject the load-more button at end on initial render
  rail.appendChild(makeLoadMoreButton());

  // --- Client-side image enrichment for cards the server can't reach ---
  // Reddit/Instagram/etc. block AWS IPs; the user's browser isn't blocked.
  // Looks for text-variant update cards pointing at those hosts and promotes
  // them to image cards after fetching the og image client-side.
  async function enrichExternalCards() {
    const cards = rail.querySelectorAll('.acard.acard--text[href*="reddit.com"]:not(.acard--enriched)');
    for (const card of cards) {
      card.classList.add('acard--enriched');
      const href = card.getAttribute('href');
      // Reddit JSON: append .json to the post URL; works cross-origin (CORS *)
      const jsonUrl = href.replace(/\/$/, '') + '.json';
      try {
        const r = await fetch(jsonUrl, { credentials: 'omit', headers: { 'Accept': 'application/json' } });
        if (!r.ok) continue;
        const data = await r.json();
        const post = data?.[0]?.data?.children?.[0]?.data;
        if (!post) continue;
        let imgUrl = post?.preview?.images?.[0]?.source?.url
          || (post?.thumbnail && post.thumbnail.startsWith('http') ? post.thumbnail : null);
        if (!imgUrl) continue;
        imgUrl = imgUrl.replace(/&amp;/g, '&');
        promoteToImageCard(card, imgUrl, post.title || null);
      } catch (e) { /* silently fail — card stays text */ }
    }
  }

  function promoteToImageCard(card, imgUrl, betterTitle) {
    const wrap = document.createElement('div');
    wrap.className = 'acard__img-wrap';
    wrap.innerHTML = `<img class="acard__img" src="${escapeHtml(imgUrl)}" alt="" loading="lazy" width="560" height="320">`;
    const body = card.querySelector('.acard__body');
    if (body) card.insertBefore(wrap, body);
    card.classList.remove('acard--text', 'acard--compact');
    card.classList.add('acard--image');
    // If the only title we have is the raw URL, swap in the post title
    if (betterTitle) {
      const titleEl = card.querySelector('.acard__title');
      if (titleEl && /^https?:\/\//.test(titleEl.textContent.trim())) {
        titleEl.textContent = betterTitle.length > 80 ? betterTitle.slice(0, 77) + '…' : betterTitle;
      }
    }
    // Pop out of acard-stack — image cards stand alone
    const stack = card.closest('.acard-stack');
    if (stack && stack.parentNode) {
      stack.parentNode.insertBefore(card, stack.nextSibling);
      if (!stack.children.length) stack.remove();
    }
  }

  enrichExternalCards();

  const KIND_LABELS = {
    article:'Articles', video:'Videos', loothprint:'Loothprints', loothcuts:'Loothcuts', document:'Documents',
    event:'Events', discussion:'Discussions', profile:'Profiles',
    benefit:'Benefits', 'sponsor-post':'Sponsor Posts', 'shorty':'Shorts', 'useful_links':'Useful Links', misc:'Misc',
  };
  const YT_RE = /(?:(?:m\.|www\.)?youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,15})/;

  function classifyActivity(it) {
    const is_sticky = it.kind === 'sticky';
    let has_image = !!it.image_url;
    let yt_id = it.yt_id || null;
    if (!yt_id) {
      const turl = it && it.target && it.target.url;
      if (turl) {
        const m = turl.match(YT_RE);
        if (m) yt_id = m[1];
      }
    }
    if (yt_id && !has_image) {
      it.image_url = `https://i.ytimg.com/vi/${yt_id}/hqdefault.jpg`;
      has_image = true;
    }
    const variant = is_sticky ? 'sticky' : (has_image ? 'image' : 'text');
    return { is_sticky, has_image, variant, yt_id };
  }

  function renderActivityCard(it, compact) {
    const meta = classifyActivity(it);
    const target = it.target || {};
    const targetKind = target.kind || 'misc';
    const tier = (it.tier || 'public').toLowerCase();
    const user = it.user || {};
    const dt = it.when ? new Date(it.when * 1000) : null;
    const dateText = dt ? dt.toLocaleString(undefined, {month:'short', day:'numeric'}) : '';
    const isoDate = dt ? dt.toISOString() : '';
    // Tier gating (mirror of the SSR card): gated when the item's tier outranks
    // the viewer's. Suppresses the inline play button so a lower tier can't play
    // gated video, and adds the overlay class.
    const TIER_RANK = { public: 0, lite: 1, pro: 2 };
    const viewerTier = (window.__LG_VIEWER_TIER__ || 'public').toLowerCase();
    const isGated = (TIER_RANK[tier] || 0) > (TIER_RANK[viewerTier] || 0);
    const a = document.createElement('a');
    let cls = `acard acard--${meta.variant} acard--kind-${targetKind}`;
    if (meta.yt_id) cls += ' acard--youtube';
    if (compact) cls += ' acard--compact';
    if (isGated) cls += ` acard--gated acard--gated-${tier}`;
    a.className = cls;
    a.href = target.url || '#';
    a.innerHTML = `
      ${meta.is_sticky ? '<span class="acard__pin">📌 Pinned</span>' : ''}
      ${meta.has_image ? `<div class="acard__img-wrap">
        <img class="acard__img" src="${escapeHtml(isGated && /ytimg\.com/.test(it.image_url || '') ? 'https://loothgroup.com/wp-content/uploads/2024/11/Featured-Image-Fallback-2.webp' : it.image_url)}" alt="" loading="lazy" width="560" height="320">
        ${meta.yt_id && !isGated ? `<button type="button" class="acard__play" data-yt-play="${escapeHtml(meta.yt_id)}" aria-label="Play video"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></button>` : ''}
        ${isGated ? `<span class="acard__gate" aria-label="${tier} member content"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>` : ''}
      </div>` : ''}
      <div class="acard__body">
        ${user.name ? `<div class="acard__head">
          ${user.avatar_url ? `<img class="acard__avatar" src="${escapeHtml(user.avatar_url)}" alt="" width="32" height="32">` : ''}
          <span class="acard__user">
            <span class="acard__name">${escapeHtml(user.name)}</span>
            <span class="acard__action">${escapeHtml(it.action || '')}</span>
          </span>
        </div>` : ''}
        ${target.title ? `<h3 class="acard__title">${escapeHtml(target.title)}</h3>` : ''}
        ${it.excerpt ? `<p class="acard__excerpt">${escapeHtml(it.excerpt)}</p>` : ''}
        <div class="acard__foot">
          ${tier !== 'public' ? `<span class="badge badge--${tier}">${tier === 'pro' ? 'Pro' : 'Lite'}</span>` : ''}
          ${targetKind ? `<span class="badge badge--kind">${escapeHtml(KIND_LABELS[targetKind] || targetKind)}</span>` : ''}
          ${isoDate ? `<time class="acard__when" datetime="${isoDate}">${dateText}</time>` : ''}
        </div>
      </div>`;
    return a;
  }

})();

// --- Click-to-play, unified for activity (acard) + rail (rcard) video cards ---
// Facade → iframe only on demand. The iframe OVERLAYS the thumbnail (kept in the
// DOM), so "stop" is just removing the iframe. Only ONE plays at a time: opening
// a new player tears down the previous one. The play button sits inside the
// card's <a>, so cancel navigation when it's the click target. Document-level
// since both the activity strip and several rails are on the page.
(function () {
  let active = null;   // the currently-playing iframe, if any
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.acard__play[data-yt-play], .rcard__play[data-yt-play]');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    const id   = btn.getAttribute('data-yt-play');
    const wrap = btn.closest('.acard__img-wrap, .rcard__img-wrap');
    if (!id || !wrap) return;

    // One at a time: stop whatever was playing (removing the iframe reveals its
    // thumbnail again, since we only overlaid it).
    if (active) { active.remove(); active = null; }
    if (wrap.querySelector('iframe')) return;

    const isRail = wrap.classList.contains('rcard__img-wrap');
    const iframe = document.createElement('iframe');
    iframe.className = isRail ? 'rcard__video' : 'acard__video';
    iframe.src = `https://www.youtube.com/embed/${encodeURIComponent(id)}?autoplay=1&rel=0&modestbranding=1`;
    iframe.title = 'Video';
    iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
    iframe.allowFullscreen = true;
    iframe.referrerPolicy = 'strict-origin-when-cross-origin';
    // Overlay the thumbnail (img-wrap is position:relative).
    iframe.style.cssText = 'position:absolute; inset:0; width:100%; height:100%; border:0; background:#000; z-index:5;';
    wrap.appendChild(iframe);
    active = iframe;
  });
})();

// --- Event card: "Add to calendar" — chooser menu (Google / Outlook / .ics) ---
(function () {
  function pad(n) { return String(n).padStart(2, '0'); }
  function utcStamp(ts) {
    const d = new Date(ts * 1000);
    return d.getUTCFullYear() + pad(d.getUTCMonth()+1) + pad(d.getUTCDate())
         + 'T' + pad(d.getUTCHours()) + pad(d.getUTCMinutes()) + pad(d.getUTCSeconds()) + 'Z';
  }
  function icsEscape(s) {
    return String(s || '').replace(/[\\,;]/g, m => '\\' + m).replace(/\r?\n/g, '\\n');
  }
  function readEvent(btn) {
    const start = parseInt(btn.dataset.start, 10);
    return {
      title:    btn.dataset.title || 'Event',
      start:    start,
      end:      parseInt(btn.dataset.end, 10) || (start + 3600),
      url:      btn.dataset.url || '',
      location: btn.dataset.location || '',
    };
  }
  function googleUrl(ev) {
    const p = new URLSearchParams({ action: 'TEMPLATE', text: ev.title, dates: utcStamp(ev.start) + '/' + utcStamp(ev.end) });
    if (ev.url) p.set('details', ev.url);
    if (ev.location) p.set('location', ev.location);
    return 'https://calendar.google.com/calendar/render?' + p.toString();
  }
  function outlookUrl(ev, host) {
    const iso = ts => new Date(ts * 1000).toISOString();
    const p = new URLSearchParams({ rru: 'addevent', subject: ev.title, startdt: iso(ev.start), enddt: iso(ev.end) });
    if (ev.url) p.set('body', ev.url);
    if (ev.location) p.set('location', ev.location);
    return 'https://' + host + '/calendar/0/action/compose?' + p.toString();
  }
  function buildIcs(ev) {
    const now = Math.floor(Date.now() / 1000);
    return [
      'BEGIN:VCALENDAR',
      'VERSION:2.0',
      'PRODID:-//Looth Group//archive-poc//EN',
      'BEGIN:VEVENT',
      'UID:lg-' + ev.start + '-' + Math.random().toString(36).slice(2,8) + '@loothgroup.com',
      'DTSTAMP:' + utcStamp(now),
      'DTSTART:' + utcStamp(ev.start),
      'DTEND:' + utcStamp(ev.end),
      'SUMMARY:' + icsEscape(ev.title),
      ev.url ? 'URL:' + icsEscape(ev.url) : null,
      ev.location ? 'LOCATION:' + icsEscape(ev.location) : null,
      ev.url ? 'DESCRIPTION:' + icsEscape(ev.url) : null,
      'END:VEVENT',
      'END:VCALENDAR',
    ].filter(Boolean).join('\r\n');
  }
  function downloadIcs(ev) {
    const blob = new Blob([buildIcs(ev)], { type: 'text/calendar;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = ev.title.replace(/[^a-z0-9-_]+/gi, '-').slice(0, 60) + '.ics';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 0);
  }

  // Menu lives on <body>, NOT inside the card — the ecard is one big <a>,
  // so any element inside it would navigate to the event post on click.
  let menu = null;
  function closeMenu() { if (menu) { menu.remove(); menu = null; } }
  function openMenu(btn) {
    closeMenu();
    const ev = readEvent(btn);
    menu = document.createElement('div');
    menu.className = 'cal-menu';
    menu.setAttribute('role', 'menu');
    menu._btn = btn;
    const items = [
      ['Google Calendar', googleUrl(ev)],
      ['Outlook.com',     outlookUrl(ev, 'outlook.live.com')],
      ['Office 365',      outlookUrl(ev, 'outlook.office.com')],
    ];
    for (const [label, href] of items) {
      const a = document.createElement('a');
      a.className = 'cal-menu__item';
      a.href = href;
      a.target = '_blank';
      a.rel = 'noopener';
      a.setAttribute('role', 'menuitem');
      a.textContent = label;
      a.addEventListener('click', closeMenu);
      menu.appendChild(a);
    }
    const dl = document.createElement('button');
    dl.type = 'button';
    dl.className = 'cal-menu__item';
    dl.setAttribute('role', 'menuitem');
    dl.textContent = 'Apple / other (.ics)';
    dl.addEventListener('click', () => { downloadIcs(ev); closeMenu(); });
    menu.appendChild(dl);
    document.body.appendChild(menu);
    const r = btn.getBoundingClientRect();
    const w = menu.offsetWidth;
    menu.style.top  = (r.bottom + window.scrollY + 4) + 'px';
    menu.style.left = Math.max(8, Math.min(r.left + window.scrollX,
      window.scrollX + document.documentElement.clientWidth - w - 8)) + 'px';
  }

  document.addEventListener('click', (e) => {
    if (menu && menu.contains(e.target)) return;   // menu items handle themselves
    const btn = e.target.closest('[data-ics]');
    if (!btn) { closeMenu(); return; }
    e.preventDefault();        // don't follow parent <a>
    e.stopPropagation();
    if (menu && menu._btn === btn) { closeMenu(); return; }   // toggle off
    openMenu(btn);
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });
})();

// --- "Report a bug or suggestion" modal ([data-feedback] CTA) ---
// POSTs to /front-page/ (handled server-side at the top of index.php) so the
// destination address never appears client-side. The CTA's href (Hub
// composer) stays as the no-JS fallback.
(function () {
  let modal = null;
  function close() { if (modal) { modal.remove(); modal = null; } }
  function open() {
    close();
    modal = document.createElement('div');
    modal.className = 'fb-modal';
    modal.innerHTML =
      '<div class="fb-modal__card" role="dialog" aria-modal="true" aria-labelledby="fb-title">' +
        '<h3 class="fb-modal__title" id="fb-title">Report a bug or suggestion</h3>' +
        '<form class="fb-modal__form">' +
          '<div class="fb-modal__kinds" role="radiogroup" aria-label="Type">' +
            '<label class="fb-kind"><input type="radio" name="kind" value="bug" checked> Bug</label>' +
            '<label class="fb-kind"><input type="radio" name="kind" value="suggestion"> Suggestion</label>' +
          '</div>' +
          '<textarea name="message" rows="5" required maxlength="5000" ' +
            'placeholder="What\'s broken, missing, or worth building?"></textarea>' +
          '<input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" class="fb-hp">' +
          '<p class="fb-modal__status" hidden></p>' +
          '<div class="fb-modal__actions">' +
            '<button type="button" class="fb-btn fb-btn--ghost" data-cancel>Cancel</button>' +
            '<button type="submit" class="fb-btn">Send</button>' +
          '</div>' +
        '</form>' +
      '</div>';
    document.body.appendChild(modal);
    const form   = modal.querySelector('form');
    const status = modal.querySelector('.fb-modal__status');
    modal.addEventListener('click', (e) => {
      if (e.target === modal || e.target.closest('[data-cancel]')) close();
    });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('[type=submit]');
      btn.disabled = true; btn.textContent = 'Sending…';
      const fd = new FormData(form);
      let ok = false, err = '', hard = false;
      try {
        const res = await fetch('/wp-json/looth/v1/bug-report', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'include',
          body: JSON.stringify({ message: fd.get('message') || '', page_url: location.pathname })
        });
        const j = await res.json().catch(() => ({}));
        ok = !!j.ok; err = j.message || ''; hard = res.status >= 500;
      } catch (_) { hard = true; }
      if (ok) {
        form.innerHTML = '<p class="fb-modal__done">Thanks — sent.</p>';
        setTimeout(close, 1800);
      } else {
        btn.disabled = false; btn.textContent = 'Send';
        status.hidden = false;
        if (hard) {
          status.innerHTML = 'Oh no, there\'s a bug with bug reporting. Please email <a href="mailto:ian.davlin@gmail.com">ian.davlin@gmail.com</a>.';
        } else {
          status.textContent = err || 'Could not send — try again later.';
        }
      }
    });
    modal.querySelector('textarea').focus();
  }
  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-feedback]');
    if (!a) return;
    e.preventDefault();
    open();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
})();

// === Search modal — faceted suggest (Authors / Posts / Discussions) ===
(function () {
  const modal   = document.getElementById('search-modal');
  if (!modal) return;
  const input   = modal.querySelector('#search-modal-q');
  const results = modal.querySelector('#search-modal-results');
  const moreBtn = modal.querySelector('#search-modal-more');

  const FALLBACK_IMG = 'https://loothgroup.com/wp-content/uploads/2024/11/Featured-Image-Fallback-2.webp';
  const KIND_LABEL   = { article:'Article', video:'Video', loothprint:'Loothprint',
                         event:'Event', profile:'Profile', benefit:'Benefit', misc:'Post' };

  let currentQuery = '';
  let timer = null;

  function open(seedValue) {
    modal.hidden = false;
    modal.removeAttribute('aria-hidden');
    document.body.classList.add('search-modal-open');
    if (seedValue && input.value !== seedValue) {
      input.value = seedValue;
      clearTimeout(timer);
      timer = setTimeout(() => runSuggest(input.value), 180);
    }
    setTimeout(() => { input.focus(); input.select(); }, 30);
    if (!input.value) renderHint();
  }
  function close() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('search-modal-open');
  }
  function archiveUrl(params) {
    const u = new URL('/archive/', location.origin);
    Object.entries(params).forEach(([k, v]) => u.searchParams.set(k, v));
    return u.toString();
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
      ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function renderHint() {
    results.innerHTML = '<div class="search-modal__hint">Search articles, videos, discussions, authors…</div>';
    moreBtn.hidden = true;
  }
  function renderEmpty() {
    results.innerHTML = '<div class="search-modal__empty">No results found.</div>';
    moreBtn.hidden = true;
  }

  function renderSections(data) {
    const q        = data.q || '';
    const authors  = data.authors  || [];
    const posts    = data.posts    || [];
    const discs    = data.discussions || [];
    const hasAny   = authors.length || posts.length || discs.length;
    if (!hasAny) { renderEmpty(); return; }

    let html = '';

    // ---- Authors ----
    if (authors.length) {
      html += '<div class="search-modal__section">';
      html += '<h3 class="search-modal__section-head">People</h3>';
      html += '<div class="search-modal__author-list">';
      authors.forEach(a => {
        const avatar = a.avatar_url || FALLBACK_IMG;
        const href   = archiveUrl({ author: a.id }); // no q — show all their posts
        const initial = (a.name || '?')[0].toUpperCase();
        html += `<a class="search-modal__author" href="${esc(href)}">
          <span class="search-modal__author-avatar" style="background-image:url('${esc(avatar)}')">
            <span class="search-modal__author-initial">${esc(initial)}</span>
          </span>
          <span class="search-modal__author-body">
            <span class="search-modal__author-name">${esc(a.name)}</span>
            <span class="search-modal__author-count">${a.post_count} post${a.post_count !== 1 ? 's' : ''}</span>
          </span>
        </a>`;
      });
      html += '</div></div>';
    }

    // ---- Posts ----
    if (posts.length) {
      html += '<div class="search-modal__section">';
      html += `<h3 class="search-modal__section-head">Posts</h3>`;
      posts.forEach(it => {
        const thumb = (it.thumb_url && !it.thumb_broken) ? it.thumb_url : FALLBACK_IMG;
        const kind  = KIND_LABEL[it.kind] || it.kind || '';
        html += `<a class="search-modal__hit" href="${esc(it.url || '#')}">
          <img class="search-modal__hit-img" src="${esc(thumb)}" alt="" loading="lazy"
               onerror="this.onerror=null;this.src='${FALLBACK_IMG}'">
          <div class="search-modal__hit-body">
            <h4 class="search-modal__hit-title">${esc(it.title || '(untitled)')}</h4>
            <div class="search-modal__hit-meta">
              ${kind ? `<span class="kind">${esc(kind)}</span>` : ''}
              ${it.author_name ? `<span>${esc(it.author_name)}</span>` : ''}
            </div>
          </div>
        </a>`;
      });
      if (data.posts_total > posts.length) {
        html += `<a class="search-modal__see-all" href="${esc(archiveUrl({ q }))}">
          See all ${data.posts_total.toLocaleString()} posts →</a>`;
      }
      html += '</div>';
    }

    // ---- Discussions ----
    if (discs.length) {
      html += '<div class="search-modal__section">';
      html += '<h3 class="search-modal__section-head">Discussions</h3>';
      discs.forEach(it => {
        html += `<a class="search-modal__disc" href="${esc(it.url || '#')}">
          <svg class="search-modal__disc-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round" width="14" height="14">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
          </svg>
          <span class="search-modal__disc-title">${esc(it.title || '(untitled)')}</span>
          ${it.reply_count ? `<span class="search-modal__disc-replies">${it.reply_count}</span>` : ''}
        </a>`;
      });
      if (data.discussions_total > discs.length) {
        html += `<a class="search-modal__see-all" href="${esc(archiveUrl({ q, kind: 'discussion' }))}">
          See all ${data.discussions_total.toLocaleString()} discussions →</a>`;
      }
      html += '</div>';
    }

    results.innerHTML = html;
    moreBtn.hidden = true;
  }

  async function runSuggest(q) {
    currentQuery = q;
    if (!q || q.trim().length < 2) { renderHint(); return; }
    try {
      const r = await fetch(`/archive-api/v0/search-suggest?q=${encodeURIComponent(q)}`, { credentials: 'same-origin' });
      if (!r.ok) throw new Error('http ' + r.status);
      const data = await r.json();
      if (currentQuery !== q) return;
      renderSections(data);
    } catch (e) {
      results.innerHTML = '<div class="search-modal__empty">Search unavailable. Try again.</div>';
      moreBtn.hidden = true;
    }
  }

  // Open triggers: magnifier button, chrome search focus, custom event
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-action="open-search-modal"]');
    if (trigger) { e.preventDefault(); open(); return; }
    const closer = e.target.closest('[data-search-modal-close]');
    if (closer && modal.contains(closer)) { e.preventDefault(); close(); return; }
  });
  document.addEventListener('lg:open-search-modal', (e) => {
    const seed = e.detail && e.detail.q;
    open(seed || document.getElementById('chrome-q')?.value || '');
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.hidden) close();
  });

  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => runSuggest(input.value), 180);
  });
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const q = input.value.trim();
      if (q) { close(); location.href = archiveUrl({ q }); }
    }
  });

  moreBtn.hidden = true;
})();

// ===== Activity like buttons (BB-backed) =====
// Bootstraps identity via the profile-app /whoami endpoint, then proxies heart
// clicks through /wp-json/looth/v1/activity/{id}/like.
(function () {
  let me = null;          // whoami payload once resolved
  let mePromise = null;

  // Lazy identity bootstrap. Resolves identity from the looth_id JWT cookie via
  // profile-app DIRECT (/profile-api/v0/whoami, ~11ms warm) instead of the WP
  // shim (/wp-json/looth/v1/whoami) which boots the full WP+BuddyBoss stack on
  // every call (~600ms floor — the main thing that made the front page feel
  // slow). Still kept OFF the initial load path: prefetched on first hover over
  // the activity strip or when the browser goes idle (whichever first), and
  // awaited on click as a fallback so a fast first click still resolves.
  function ensureMe() {
    if (!mePromise) {
      mePromise = fetch('/profile-api/v0/whoami', { credentials: 'include' })
        .then(r => r.json())
        .catch(() => ({ authenticated: false }))
        .then(d => (me = d));
    }
    return mePromise;
  }
  const stripEl = document.querySelector('.row--activity');
  if (stripEl) stripEl.addEventListener('pointerenter', ensureMe, { once: true });
  (window.requestIdleCallback || (f => setTimeout(f, 2500)))(ensureMe);

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-like]');
    if (!btn) return;
    // Capture phase + stopImmediatePropagation: prevent the outer .acard <a>
    // from navigating, and prevent any other delegated card handlers from firing.
    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();

    const who = me || await ensureMe();
    // profile-app returns `authenticated`; tolerate the legacy `logged_in`.
    if (!who || !(who.authenticated || who.logged_in)) {
      // Drop visitor on the login page; ?redirect_to brings them back here.
      const back = encodeURIComponent(location.pathname + location.search);
      location.href = '/wp-login.php?redirect_to=' + back;
      return;
    }
    if (btn.disabled) return;

    const id     = btn.getAttribute('data-activity-id');
    const liked  = btn.classList.contains('is-liked');
    const totalE = btn.parentElement.querySelector('[data-react-total]');
    const cur    = totalE ? parseInt(totalE.textContent, 10) || 0 : 0;

    // Optimistic toggle
    btn.classList.toggle('is-liked');
    btn.setAttribute('aria-pressed', String(!liked));
    if (totalE) totalE.textContent = String(Math.max(0, cur + (liked ? -1 : 1)));
    btn.disabled = true;

    fetch(`/wp-json/looth/v1/activity/${id}/like`, {
      method: 'POST',
      credentials: 'include',
    })
      .then(r => r.ok ? r.json() : Promise.reject(r))
      .then(d => {
        btn.classList.toggle('is-liked', !!d.liked_by_me);
        btn.setAttribute('aria-pressed', String(!!d.liked_by_me));
        if (totalE) totalE.textContent = String(d.count || 0);
      })
      .catch(() => {
        // Revert on failure
        btn.classList.toggle('is-liked', liked);
        btn.setAttribute('aria-pressed', String(liked));
        if (totalE) totalE.textContent = String(cur);
      })
      .finally(() => { btn.disabled = false; });
  }, true); // capture phase
})();

// === Member Map modal ================================================
// Lazy-loads Leaflet + Leaflet.markercluster on first open. Fetches
// /wp-json/looth/v1/members-geo (members-only) and clusters the markers.
// Anonymous users → redirect to wp-login (same pattern as [data-like]).
(function () {
  const modal = document.getElementById('member-map-modal');
  if (!modal) return;
  const mapEl    = modal.querySelector('#member-map');
  const statusEl = modal.querySelector('#member-map-status');
  const countEl  = modal.querySelector('#member-map-count');

  // Site logo as the avatar fallback — used when the API returns null or
  // when the <img> fails to load (broken local avatar, dead gravatar URL).
  const FALLBACK_AVATAR = 'https://loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu-100x100.png';
  const LEAFLET_CSS    = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css';
  const LEAFLET_JS     = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js';
  const CLUSTER_CSS    = 'https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.css';
  const CLUSTER_DEF    = 'https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css';
  const CLUSTER_JS     = 'https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js';

  let map = null;
  let clusterLayer = null;
  let loaded = false;
  let loading = false;

  function loadCss(href) {
    return new Promise((resolve, reject) => {
      if (document.querySelector(`link[data-lg-href="${href}"]`)) return resolve();
      const link = document.createElement('link');
      link.rel = 'stylesheet'; link.href = href;
      link.dataset.lgHref = href;
      link.onload = () => resolve();
      link.onerror = () => reject(new Error('css load failed: ' + href));
      document.head.appendChild(link);
    });
  }
  function loadJs(src) {
    return new Promise((resolve, reject) => {
      if (document.querySelector(`script[data-lg-src="${src}"]`)) return resolve();
      const s = document.createElement('script');
      s.src = src; s.async = true;
      s.dataset.lgSrc = src;
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('js load failed: ' + src));
      document.head.appendChild(s);
    });
  }

  function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => (
      {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]
    ));
  }

  function open() {
    modal.hidden = false;
    modal.removeAttribute('aria-hidden');
    document.body.classList.add('lg-modal-open');
    if (!loaded && !loading) initMap();
    else if (map) setTimeout(() => map.invalidateSize(), 50);
  }
  function close() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('lg-modal-open');
  }

  function setStatus(text, isError) {
    if (!statusEl) return;
    if (!text) { statusEl.hidden = true; return; }
    statusEl.hidden = false;
    statusEl.textContent = text;
    statusEl.classList.toggle('member-map__status--error', !!isError);
  }

  function renderTeaser() {
    // Replace map + status with the teaser image + sign-in CTA.
    if (mapEl) mapEl.hidden = true;
    setStatus('', false);
    if (countEl) countEl.textContent = '';
    if (modal.querySelector('.member-map__teaser')) return; // already rendered
    const back = encodeURIComponent(location.pathname + location.search);
    const teaser = document.createElement('div');
    teaser.className = 'member-map__teaser';
    teaser.innerHTML =
      '<img class="member-map__teaser-img" src="/archive-poc/member-map-teaser.webp" ' +
      'alt="Looth members across the globe" width="1140" height="748">' +
      '<div class="member-map__teaser-cta">' +
      '<p>Looth members across the globe — log in to explore.</p>' +
      '<a class="cta-btn cta-btn--primary" href="/wp-login.php?redirect_to=' + back + '">Log in to see the map</a>' +
      '</div>';
    const anchor = mapEl ? mapEl.nextSibling : null;
    (mapEl && mapEl.parentNode ? mapEl.parentNode : modal).insertBefore(teaser, anchor);
  }

  function getViewerTier() {
    const m = document.cookie.match(/(?:^|; )lg_tier=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : 'public';
  }

  async function initMap() {
    loading = true;

    // Anonymous viewer → teaser, skip Leaflet + members-geo entirely.
    if (getViewerTier() === 'public') {
      renderTeaser();
      loaded = true;
      loading = false;
      return;
    }

    setStatus('Loading map…', false);
    try {
      await Promise.all([loadCss(LEAFLET_CSS), loadCss(CLUSTER_CSS), loadCss(CLUSTER_DEF)]);
      await loadJs(LEAFLET_JS);
      await loadJs(CLUSTER_JS);
    } catch (e) {
      setStatus('Could not load map library. Check your connection.', true);
      loading = false;
      return;
    }

    let payload;
    try {
      const r = await fetch('/wp-json/looth/v1/members-geo', { credentials: 'include' });
      if (r.status === 401 || r.status === 403) {
        // Cookie said member but endpoint disagrees — fall back to teaser.
        renderTeaser();
        loaded = true;
        loading = false;
        return;
      }
      if (!r.ok) throw new Error('http ' + r.status);
      payload = await r.json();
    } catch (e) {
      setStatus('Member map is unavailable right now.', true);
      loading = false;
      return;
    }

    const members = Array.isArray(payload && payload.members) ? payload.members : [];
    countEl.textContent = members.length ? `${members.length.toLocaleString()} members` : '';

    const L = window.L;
    map = L.map(mapEl, { worldCopyJump: true }).setView([20, 0], 2);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);

    clusterLayer = L.markerClusterGroup({
      showCoverageOnHover: false,
      spiderfyOnMaxZoom: true,
      maxClusterRadius: 50,
    });

    const valid = [];
    members.forEach(m => {
      const lat = Number(m.lat), lng = Number(m.lng);
      if (!isFinite(lat) || !isFinite(lng)) return;
      const avatar = m.avatar || FALLBACK_AVATAR;
      const url = m.url || '#';
      // Avatar pin — the <img> only fetches when markercluster inserts
      // this marker into the DOM (i.e. when its cluster is expanded or
      // the user zooms past the cluster-merge threshold). loading="lazy"
      // is belt-and-braces in case any markers materialize off-screen.
      const icon = L.divIcon({
        className: 'member-pin-avatar',
        html: `<img src="${escHtml(avatar)}" alt="" loading="lazy"
                    onerror="this.onerror=null;this.src='${FALLBACK_AVATAR}'">`,
        iconSize: [30, 30],
        iconAnchor: [15, 15],
        popupAnchor: [0, -16],
      });
      const marker = L.marker([lat, lng], { icon, title: m.name || '' });
      marker.bindPopup(
        `<div class="member-popup">
           <img class="member-popup__avatar" src="${escHtml(avatar)}" alt=""
                onerror="this.onerror=null;this.src='${FALLBACK_AVATAR}'">
           <div class="member-popup__body">
             <p class="member-popup__name">${escHtml(m.name || 'Member')}</p>
             <a class="member-popup__link" href="${escHtml(url)}" target="_blank" rel="noopener">View profile →</a>
           </div>
         </div>`
      );
      clusterLayer.addLayer(marker);
      valid.push([lat, lng]);
    });
    map.addLayer(clusterLayer);

    if (valid.length) {
      map.fitBounds(valid, { padding: [40, 40], maxZoom: 6 });
    }

    setStatus('', false);
    loaded = true;
    loading = false;
    setTimeout(() => map.invalidateSize(), 50);
  }

  // Wire CTA + close handlers
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-action="open-member-map"]');
    if (trigger) {
      e.preventDefault();
      // The modal's /members-geo endpoint is dead (audit M1), so it only ever
      // said "unavailable" — until that endpoint exists, the real map lives at
      // /directory/members/. Navigate there instead (Buck 2026-06-11). The
      // modal + loader code below stays dormant for when the endpoint lands.
      window.location.assign('/directory/members/');
      return;
    }
    const closer = e.target.closest('[data-member-map-close]');
    if (closer && modal.contains(closer)) { e.preventDefault(); close(); return; }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.hidden) close();
  });
})();

// ============================================================================
// Inline member map (data-init="members-geo")
// Lazy-loads Leaflet + fetches /wp-json/looth/v1/members-geo when the container
// scrolls into view. Reuses the same JSON shape as the modal map. Only fires
// for logged-in viewers — PHP guards what gets rendered server-side.
// ============================================================================
(function () {
  const containers = document.querySelectorAll('.vpromo__map[data-init="members-geo"]');
  if (!containers.length) return;

  const LEAFLET_CSS = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css';
  const LEAFLET_JS  = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js';
  const CLUSTER_CSS = 'https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.css';
  const CLUSTER_DEF = 'https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css';
  const CLUSTER_JS  = 'https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js';
  const FALLBACK_AVATAR = 'https://loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu-100x100.png';

  function loadCss(href) {
    return new Promise((resolve, reject) => {
      if (document.querySelector('link[data-lg-href="' + href + '"]')) return resolve();
      const link = document.createElement('link');
      link.rel = 'stylesheet'; link.href = href; link.dataset.lgHref = href;
      link.onload = () => resolve();
      link.onerror = () => reject(new Error('css ' + href));
      document.head.appendChild(link);
    });
  }
  function loadJs(src) {
    return new Promise((resolve, reject) => {
      if (document.querySelector('script[data-lg-src="' + src + '"]')) return resolve();
      const s = document.createElement('script');
      s.src = src; s.async = true; s.dataset.lgSrc = src;
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('js ' + src));
      document.head.appendChild(s);
    });
  }
  function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
  }

  let payloadPromise = null;
  function fetchMembers() {
    if (!payloadPromise) {
      payloadPromise = fetch('/wp-json/looth/v1/members-geo', { credentials: 'include' })
        .then(r => r.ok ? r.json() : Promise.reject(r));
    }
    return payloadPromise;
  }

  let leafletPromise = null;
  function ensureLeaflet() {
    if (!leafletPromise) {
      leafletPromise = Promise.all([loadCss(LEAFLET_CSS), loadCss(CLUSTER_CSS), loadCss(CLUSTER_DEF)])
        .then(() => loadJs(LEAFLET_JS))
        .then(() => loadJs(CLUSTER_JS));
    }
    return leafletPromise;
  }

  async function mount(container) {
    if (container.dataset.mounted === '1') return;
    container.dataset.mounted = '1';
    try {
      await ensureLeaflet();
      const payload = await fetchMembers();
      const members = Array.isArray(payload && payload.members) ? payload.members : [];
      const L = window.L;
      const map = L.map(container, { worldCopyJump: true, scrollWheelZoom: false }).setView([20, 0], 2);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      }).addTo(map);
      const cluster = L.markerClusterGroup({
        showCoverageOnHover: false, spiderfyOnMaxZoom: true, maxClusterRadius: 50,
      });
      const bounds = [];
      members.forEach(m => {
        const lat = Number(m.lat), lng = Number(m.lng);
        if (!isFinite(lat) || !isFinite(lng)) return;
        const avatar = m.avatar || FALLBACK_AVATAR;
        const icon = L.divIcon({
          className: 'member-pin-avatar',
          html: '<img src="' + escHtml(avatar) + '" alt="" loading="lazy" onerror="this.onerror=null;this.src=\'' + FALLBACK_AVATAR + '\'">',
          iconSize: [30, 30], iconAnchor: [15, 15], popupAnchor: [0, -16],
        });
        const marker = L.marker([lat, lng], { icon, title: m.name || '' });
        marker.bindPopup(
          '<div class="member-popup">' +
          '<img class="member-popup__avatar" src="' + escHtml(avatar) + '" alt="" onerror="this.onerror=null;this.src=\'' + FALLBACK_AVATAR + '\'">' +
          '<div class="member-popup__body">' +
          '<p class="member-popup__name">' + escHtml(m.name || 'Member') + '</p>' +
          '<a class="member-popup__link" href="' + escHtml(m.url || '#') + '" target="_blank" rel="noopener">View profile →</a>' +
          '</div></div>'
        );
        cluster.addLayer(marker);
        bounds.push([lat, lng]);
      });
      map.addLayer(cluster);
      if (bounds.length) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 5 });
      setTimeout(() => map.invalidateSize(), 50);
    } catch (e) {
      container.innerHTML = '<div class="vpromo__map-error">Map unavailable.</div>';
    }
  }

  // Lazy-mount when the container scrolls near the viewport.
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries, obs) => {
      entries.forEach(en => {
        if (en.isIntersecting) {
          mount(en.target);
          obs.unobserve(en.target);
        }
      });
    }, { rootMargin: '200px' });
    containers.forEach(c => io.observe(c));
  } else {
    containers.forEach(mount);
  }
})();

// ===== Rail "Show more" — delegated click handler ====================
(() => {
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.rail-more');
    if (!btn || btn.disabled) return;
    const rowId  = btn.dataset.rowId;
    const offset = parseInt(btn.dataset.offset, 10) || 0;
    if (!rowId) return;

    btn.disabled = true;
    btn.classList.add('is-loading');
    try {
      const r = await fetch(
        `/archive-api/v0/rows-more?row_id=${encodeURIComponent(rowId)}&offset=${offset}`,
        { credentials: 'same-origin' }
      );
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const data = await r.json();
      if (data.items_html) {
        // Insert new cards BEFORE the arrow button so it stays at the end.
        btn.insertAdjacentHTML('beforebegin', data.items_html);
      }
      if (data.has_more) {
        btn.dataset.offset = String(data.next_offset);
        btn.disabled = false;
        btn.classList.remove('is-loading');
      } else {
        btn.remove();
      }
    } catch (err) {
      btn.disabled = false;
      btn.classList.remove('is-loading');
      btn.classList.add('is-error');
      console.error('[rail-more]', err);
    }
  });
})();

// ===== Chrome badge counts — REMOVED (HK-003) ========================
// A `chromeCounts` IIFE used to live here and poll BuddyBoss
// (/wp-json/buddyboss/v1/{messages,notifications}) to fill [data-lg-msg-count] and
// [data-lg-notif-count]. Those routes are DEAD — the forum is strangled to the Hub
// and messaging moved to profile-app — so it read 0 and *hid the badges*.
//
// That made it the second writer of spans lg-shared/social-modals.js already owns,
// and it landed LAST: refreshCounts() set the badge to "1" at ~2.1s, this poll then
// re-hid it (idle + every 60s + on every visibilitychange). A member with a genuine
// unread DM and unread notifications saw the badge blink on, then vanish — the
// root cause of "badges never appear" (sweep HK-003).
//
// One store, one writer: /me/social-counts/ is the single source, social-modals.js
// (loaded by site-header.php on every page that renders the header, including this
// one) is the sole writer of all three badge spans. Do not add a second poller here
// — if one is ever needed it must read /me/social-counts/ and MUST NOT write the
// spans. Lane: notifications, 2026-07-12.
