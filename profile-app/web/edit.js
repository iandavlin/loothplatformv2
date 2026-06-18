(function () {
  'use strict';

  const BOOT = window.LOOTH_BOOT || {};
  const API  = BOOT.apiBase || '/profile-api/v0';
  const MODE = BOOT.mode || 'editor';
  const $    = (sel, ctx) => (ctx || document).querySelector(sel);
  const $$   = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));
  const escH = s => (s||'').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  // ─── role toggle ───────────────────────────────────────────────────────
  const canSee = { me: () => true, member: v => v==='public'||v==='members', public: v => v==='public' };
  let currentRole = BOOT.role || 'me';
  const roleBar = $('#role');

  function applyRole() {
    $$('.section').forEach(s => { s.dataset.hidden = canSee[currentRole](s.dataset.vis) ? '0' : '1'; });
    // Defensive coalesce — BOOT.profile, .location, or .grants can each be
    // undefined for profiles that don't have a location set yet. Previously
    // `loc.grants.friends` threw a TypeError on load for such users.
    const profile = BOOT.profile || {};
    const loc     = profile.location || {};
    const grants  = loc.grants || {};
    const grant = currentRole === 'me' ? grants.friends
                : (grants[currentRole === 'member' ? 'members' : 'public']);
    const dispLoc = $('#disp-loc');
    if (dispLoc && loc.text) {
      switch (grant) {
        case 'hidden':  dispLoc.textContent = '(hidden)'; break;
        case 'country': dispLoc.textContent = loc.country || ''; break;
        case 'region':  dispLoc.textContent = [loc.region, loc.country].filter(Boolean).join(', '); break;
        case 'city':    dispLoc.textContent = [loc.city, loc.region, loc.country].filter(Boolean).join(', '); break;
        case 'address': default: dispLoc.textContent = loc.text;
      }
    }
  }
  roleBar?.addEventListener('click', e => {
    const b = e.target.closest('button[data-role]'); if (!b) return;
    roleBar.querySelectorAll('button').forEach(x => x.classList.remove('on'));
    b.classList.add('on'); currentRole = b.dataset.role; applyRole();
  });

  // ─── save indicator ────────────────────────────────────────────────────
  const saveind = $('#saveind');
  let saveTimer = null;
  function setSaveStatus(text, klass) {
    if (!saveind) return;
    saveind.textContent = text || ''; saveind.className = 'saveind ' + (klass || '');
    if (saveTimer) clearTimeout(saveTimer);
    if (text) saveTimer = setTimeout(() => { saveind.textContent = ''; saveind.className = 'saveind'; }, 2500);
  }
  async function apiCall(method, path, body) {
    setSaveStatus('saving…');
    try {
      const res = await fetch(API + path, {method, credentials:'include',
        headers:{'Content-Type':'application/json'}, body: body ? JSON.stringify(body) : undefined});
      if (res.status === 401) {
        const r = await fetch('/wp-json/looth/auth/refresh', {method:'POST', credentials:'include'});
        if (r.ok) {
          const res2 = await fetch(API + path, {method, credentials:'include',
            headers:{'Content-Type':'application/json'}, body: body ? JSON.stringify(body) : undefined});
          if (res2.ok) { setSaveStatus('saved', 'ok'); return res2.json(); }
        }
        setSaveStatus('signed out', 'err'); return null;
      }
      const data = await res.json();
      if (!res.ok) { setSaveStatus('error: ' + (data.error || res.status), 'err'); return null; }
      setSaveStatus('saved', 'ok');
      return data;
    } catch (e) { setSaveStatus('network error', 'err'); return null; }
  }

  // ─── modals ────────────────────────────────────────────────────────────
  function openModal(k) { const bd = document.getElementById('b-'+k); if (bd) bd.classList.add('open'); }
  function closeModal(k){ const bd = document.getElementById('b-'+k); if (bd) bd.classList.remove('open'); }
  function closeAll()   { $$('.backdrop.open').forEach(b => b.classList.remove('open')); }
  $$('button[data-modal]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); openModal(b.dataset.modal); }));
  $$('.section[data-active="0"]').forEach(s => s.addEventListener('click', () => openModal(s.dataset.modal)));
  $$('.loc-empty[data-modal]').forEach(el => el.addEventListener('click', e => { e.stopPropagation(); openModal(el.dataset.modal); }));
  $$('[data-close]').forEach(b => b.addEventListener('click', () => b.closest('.backdrop').classList.remove('open')));
  $$('.backdrop').forEach(bd => bd.addEventListener('click', e => { if (e.target === bd) bd.classList.remove('open'); }));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAll(); });

  if (MODE !== 'editor') return;

  // ─── catalogs (lazy-loaded once on first picker open) ──────────────────
  const catalogs = {};
  async function loadCatalog(kind) {
    if (catalogs[kind]) return catalogs[kind];
    const r = await fetch(API + '/catalogs/' + kind, {credentials:'include'});
    const d = await r.json();
    catalogs[kind] = d.items || [];
    return catalogs[kind];
  }

  // ─── save handlers (slice-1 ones) ──────────────────────────────────────
  const SAVE = {
    name: async () => {
      const v = $('#f-name').value.trim();
      const biz = ($('#f-biz')?.value ?? '').trim();
      const r = await apiCall('PATCH', '/me/name', {display_name: v, business_name: biz});
      if (!r) return;
      $('#disp-name').textContent = r.display_name;
      $('#avi-text').textContent = r.display_name.split(/\s+/).map(w => w[0]).slice(0,2).join('').toUpperCase();
      BOOT.profile.display_name = r.display_name;
      const bizEl = $('#disp-biz');
      if (bizEl) {
        if (biz === '') { bizEl.hidden = true; bizEl.textContent = ''; }
        else { bizEl.hidden = false; bizEl.textContent = biz; }
      }
      BOOT.profile.business_name = biz === '' ? null : biz;
      closeModal('name');
    },
    about: async () => {
      const text = $('#f-about').value, vis = $('#f-about-vis').value;
      const r = await apiCall('PATCH', '/me/about', {text, visibility: vis});
      if (!r) return;
      $('#about-body').innerHTML = escH(text).replace(/\n/g,'<br>');
      const sec = $('#about');
      sec.dataset.vis = r.visibility; sec.dataset.active = text.trim() === '' ? '0' : '1';
      const chip = sec.querySelector('.vis'); chip.dataset.v = r.visibility; chip.textContent = '👁 ' + r.visibility;
      document.querySelector('.tab[data-anchor="about"]')?.classList.toggle('on', text.trim()!=='');
      BOOT.profile.sections.about = {visibility: r.visibility, data:{text}};
      closeModal('about'); applyRole();
    },
    socials: async () => {
      const items = $$('.row', $('#socials-edit')).map((r,i) => ({
        kind: r.querySelector('.k').value, value: r.querySelector('.v').value, sort_order: i
      })).filter(it => it.value.trim() !== '');
      const r = await apiCall('PUT', '/me/socials', {items});
      if (!r) return;
      const row = $('#socials-row'); const pencil = row.querySelector('.pencil');
      $$('a', row).forEach(a => a.remove());
      const glyphs = {instagram:'📷',youtube:'▶',bandcamp:'🎵',web:'🔗',email:'✉',phone:'📞',x:'𝕏',tiktok:'♪',facebook:'ƒ',patreon:'P',linktree:'🌳'};
      r.items.forEach(it => { const a = document.createElement('a'); a.href='#';
        a.innerHTML = `<span class="glyph">${glyphs[it.kind]||'🔗'}</span>${escH(it.value)}`;
        row.insertBefore(a, pencil); });
      BOOT.profile.socials = r.items; closeModal('socials');
    },
    // Location: no Save button — picker and visibility radio autosave
    // independently. Kept as a no-op so `[data-save="location"]` (if it
    // ever resurfaces from an older render) doesn't error.
    location: async () => { closeModal('location'); },
    instruments: async () => {
      const ids = $$('#inst-picker input[type=checkbox]:checked').map(i => parseInt(i.value, 10));
      const items = ids.map((id, i) => ({instrument_id: id, sort_order: i}));
      const r = await apiCall('PUT', '/me/instruments', {items});
      if (!r) return;
      // Rerender chips + section active state.
      const picked = ids.map(id => catalogs.instruments.find(c => c.id === id)).filter(Boolean);
      $('#instruments-chips').innerHTML = picked.map(c => `<span class="chip">${escH(c.name)}</span>`).join('');
      const sec = $('#instruments'); sec.dataset.active = picked.length ? '1' : '0';
      document.querySelector('.tab[data-anchor="instruments"]')?.classList.toggle('on', picked.length>0);
      BOOT.profile.instruments = picked.map((c, i) => ({id:c.id, slug:c.slug, name:c.name, type:c.type, subtype:c.subtype, sort_order:i}));
      closeModal('instruments');
    },
    skills: async () => {
      const items = $$('#skill-picker .skill-row').filter(r => r.querySelector('input[type=checkbox]').checked).map((r, i) => ({
        skill_id: parseInt(r.dataset.id, 10),
        note: r.querySelector('input[type=text]').value.trim() || null,
        sort_order: i,
      }));
      const res = await apiCall('PUT', '/me/skills', {items});
      if (!res) return;
      const picked = items.map(it => {
        const c = catalogs.skills.find(x => x.id === it.skill_id);
        return c ? {id:c.id, slug:c.slug, name:c.name, category:c.category, note:it.note, sort_order:it.sort_order} : null;
      }).filter(Boolean);
      $('#skills-chips').innerHTML = picked.map(c => `<span class="chip">${escH(c.name)}${c.note?` <em class="ink-mute">— ${escH(c.note)}</em>`:''}</span>`).join('');
      const sec = $('#skills'); sec.dataset.active = picked.length ? '1' : '0';
      document.querySelector('.tab[data-anchor="skills"]')?.classList.toggle('on', picked.length>0);
      BOOT.profile.skills = picked;
      closeModal('skills');
    },
    scenes: async () => {
      const slugs = $$('#scene-picker input:checked').map(i => i.value);
      const r = await apiCall('PUT', '/me/scenes', {slugs});
      if (!r) return;
      const picked = slugs.map(s => catalogs.scenes.find(c => c.slug === s)).filter(Boolean);
      $('#scenes-chips').innerHTML = picked.map(c => `<span class="chip">${escH(c.name)}</span>`).join('');
      const sec = $('#scenes'); sec.dataset.active = picked.length ? '1' : '0';
      document.querySelector('.tab[data-anchor="scenes"]')?.classList.toggle('on', picked.length>0);
      BOOT.profile.scenes = picked;
      closeModal('scenes');
    },
    credentials: async () => {
      const body = {
        catalog_id: $('#cred-catalog-id').value ? parseInt($('#cred-catalog-id').value, 10) : null,
        raw_issuer:  $('#cred-issuer').value.trim(),
        raw_program: $('#cred-program').value.trim(),
        issued_at:   $('#cred-issued').value  || null,
        expires_at:  $('#cred-expires').value || null,
        visibility:  $('#cred-vis').value,
      };
      if (!body.raw_issuer || !body.raw_program) { setSaveStatus('issuer + program required', 'err'); return; }
      const r = await apiCall('POST', '/me/credentials', body);
      if (!r) return;
      // Append to in-place list + reset form.
      const item = {id: r.id, raw_issuer: body.raw_issuer, raw_program: body.raw_program,
                    expires_at: body.expires_at, visibility: body.visibility,
                    catalog_id: body.catalog_id, sort_order: 0};
      BOOT.profile.credentials.push(item);
      renderCredentialsList();
      const sec = $('#credentials'); sec.dataset.active = '1';
      document.querySelector('.tab[data-anchor="credentials"]')?.classList.add('on');
      ['cred-search','cred-issuer','cred-program','cred-issued','cred-expires'].forEach(id => $('#'+id).value='');
      $('#cred-catalog-id').value = '';
    },
    highlights: async () => {
      const items = $$('#hl-picker input:checked').slice(0, 3).map((i, idx) => ({
        kind: i.dataset.kind, ref_id: parseInt(i.value, 10), sort_order: idx,
      }));
      const r = await apiCall('PUT', '/me/highlights', {items});
      if (!r) return;
      const enriched = items.map(it => {
        const list = it.kind === 'instrument' ? catalogs.instruments : catalogs.skills;
        const c = list.find(x => x.id === it.ref_id);
        return c ? {kind:it.kind, ref_id:c.id, slug:c.slug, name:c.name} : null;
      }).filter(Boolean);
      const row = $('#highlights-row'); const pencil = row.querySelector('.pencil');
      $$('a.hl, .add-hl', row).forEach(x => x.remove());
      enriched.forEach(h => { const a = document.createElement('a'); a.className='hl';
        a.href = h.kind === 'instrument' ? `/directory/members?inst=${h.slug}` : `/directory/members?skill=${h.slug}`;
        a.textContent = h.name; row.insertBefore(a, pencil); });
      BOOT.profile.highlights = enriched; closeModal('highlights');
    },
  };
  $$('[data-save]').forEach(b => b.addEventListener('click', () => { const fn = SAVE[b.dataset.save]; if (fn) fn(); }));

  // ─── socials modal: seed + add-row ─────────────────────────────────────
  const SOCIAL_KINDS = ['instagram','youtube','bandcamp','web','email','phone','x','tiktok','facebook','patreon','linktree'];
  function addSocialRow(item) {
    const wrap = $('#socials-edit'); const row = document.createElement('div'); row.className='row';
    row.innerHTML = `<select class="k">${SOCIAL_KINDS.map(k => `<option value="${k}" ${k===item.kind?'selected':''}>${k}</option>`).join('')}</select>
      <input class="v" type="text" value="${escH(item.value||'')}" placeholder="@handle or URL">
      <button class="x" title="Remove">✕</button>`;
    row.querySelector('.x').addEventListener('click', () => row.remove());
    wrap.appendChild(row);
  }
  (BOOT.profile.socials || []).forEach(addSocialRow);
  if (!(BOOT.profile.socials || []).length) addSocialRow({kind:'instagram', value:''});
  $('#add-social')?.addEventListener('click', () => addSocialRow({kind:'web', value:''}));

  // ─── Location picker (Nominatim autocomplete) ──────────────────────────
  //
  // Picker is the source of truth: typing into #f-loc fires a debounced
  // search through our /me/location/search proxy (IP-biased viewbox via
  // GeoLite2 server-side). Picking a row immediately POSTs to /me/location
  // and updates the header. No post-hoc geocoding, no save button.
  (() => {
    const input  = $('#f-loc');
    const picker = $('#loc-picker');
    const empty  = $('#loc-empty-state');
    if (!input || !picker) return;

    let timer = null, lastQ = '', hover = -1, items = [];

    function clearPicker() { picker.hidden = true; picker.innerHTML = ''; items = []; hover = -1; empty.hidden = true; }
    function paint() {
      picker.innerHTML = items.map((it, i) =>
        `<div class="ta-item${i===hover?' on':''}" data-i="${i}"><b>${escH(it.short)}</b>
           <div class="ann">${escH(it.display_name)}</div></div>`).join('');
      $$('.ta-item', picker).forEach(el => {
        el.addEventListener('mouseenter', () => { hover = +el.dataset.i; paint(); });
        el.addEventListener('mousedown',  e  => { e.preventDefault(); pickIndex(+el.dataset.i); });
      });
    }
    async function pickIndex(i) {
      const row = items[i]; if (!row) return;
      input.value = row.display_name;
      clearPicker();
      const r = await apiCall('PUT', '/me/location', {nominatim: row.raw});
      if (!r) return;
      $('#disp-loc').textContent = row.short;
      $('#disp-loc').classList.remove('loc-empty');
      Object.assign(BOOT.profile.location, {
        text: row.display_name, lat: row.lat, lng: row.lng,
      });
      setSaveStatus('saved', 'ok');
    }
    async function query(q) {
      const r = await apiCall('GET', '/me/location/search?q=' + encodeURIComponent(q));
      if (!r) return;
      if (lastQ !== q) return;   // stale
      items = r.items || [];
      if (!items.length) { picker.hidden = true; empty.hidden = false; return; }
      hover = 0;
      paint();
      picker.hidden = false;
      empty.hidden  = true;
    }

    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      lastQ = q;
      if (q === '') { clearPicker(); return; }
      timer = setTimeout(() => query(q), 250);
    });
    input.addEventListener('keydown', e => {
      if (picker.hidden) return;
      if (e.key === 'ArrowDown') { hover = Math.min(items.length-1, hover+1); paint(); e.preventDefault(); }
      else if (e.key === 'ArrowUp')   { hover = Math.max(0, hover-1); paint(); e.preventDefault(); }
      else if (e.key === 'Enter')     { e.preventDefault(); pickIndex(hover); }
      else if (e.key === 'Escape')    { clearPicker(); }
    });
    document.addEventListener('click', e => {
      if (!e.target.closest('#loc-picker') && e.target !== input) clearPicker();
    });

    // Text-only escape hatch.
    $('#loc-text-only')?.addEventListener('click', async () => {
      const q = input.value.trim(); if (!q) return;
      const r = await apiCall('PUT', '/me/location', {text_only: q});
      if (!r) return;
      $('#disp-loc').textContent = q;
      $('#disp-loc').classList.remove('loc-empty');
      Object.assign(BOOT.profile.location, {text: q, lat: null, lng: null});
      clearPicker();
      setSaveStatus('saved (text only)', 'ok');
    });

    // Visibility radio autosave.
    $$('input[name="loc-vis"]').forEach(r => r.addEventListener('change', async () => {
      const v = $('input[name="loc-vis"]:checked')?.value;
      if (!v) return;
      const ok = await apiCall('PUT', '/me/location', {location_visibility: v});
      if (!ok) return;
      BOOT.profile.location.visibility = v;
      setSaveStatus('visibility saved', 'ok');
    }));
  })();

  // ─── Instruments picker (lazy build) ───────────────────────────────────
  $('button[data-modal="instruments"]')?.addEventListener('click', async () => {
    const list = await loadCatalog('instruments');
    const pickedIds = new Set((BOOT.profile.instruments||[]).map(i => i.id));
    $('#inst-picker').innerHTML = list.map(c => `<label class="picker-item">
      <input type="checkbox" value="${c.id}" ${pickedIds.has(c.id)?'checked':''}><span>${escH(c.name)}</span></label>`).join('');
  });
  $('.section#instruments')?.addEventListener('click', async (e) => {
    if (e.target.closest('.pencil')) return;
    if ($('.section#instruments').dataset.active === '0') {
      const list = await loadCatalog('instruments');
      const pickedIds = new Set((BOOT.profile.instruments||[]).map(i => i.id));
      $('#inst-picker').innerHTML = list.map(c => `<label class="picker-item">
        <input type="checkbox" value="${c.id}" ${pickedIds.has(c.id)?'checked':''}><span>${escH(c.name)}</span></label>`).join('');
    }
  });

  // ─── Skills picker ─────────────────────────────────────────────────────
  async function buildSkillPicker() {
    const list = await loadCatalog('skills');
    const picked = new Map((BOOT.profile.skills||[]).map(s => [s.id, s.note || '']));
    const byCat = {};
    list.forEach(s => { (byCat[s.category]=byCat[s.category]||[]).push(s); });
    $('#skill-picker').innerHTML = Object.entries(byCat).map(([cat, items]) =>
      `<div class="picker-group"><h5>${escH(cat)}</h5>
       ${items.map(s => `<div class="skill-row" data-id="${s.id}">
         <label><input type="checkbox" ${picked.has(s.id)?'checked':''}> ${escH(s.name)}</label>
         <input type="text" placeholder="optional note" value="${escH(picked.get(s.id)||'')}">
       </div>`).join('')}
       </div>`).join('');
  }
  $('button[data-modal="skills"]')?.addEventListener('click', buildSkillPicker);
  $('.section#skills')?.addEventListener('click', e => { if (!e.target.closest('.pencil') && $('.section#skills').dataset.active==='0') buildSkillPicker(); });

  // ─── Scenes picker ─────────────────────────────────────────────────────
  async function buildScenePicker() {
    const list = await loadCatalog('scenes');
    const picked = new Set((BOOT.profile.scenes||[]).map(s => s.slug));
    $('#scene-picker').innerHTML = list.map(s => `<label class="pill-pick ${picked.has(s.slug)?'on':''}">
      <input type="checkbox" value="${escH(s.slug)}" ${picked.has(s.slug)?'checked':''}> ${escH(s.name)}</label>`).join('');
    $$('#scene-picker label').forEach(l => l.querySelector('input').addEventListener('change', e => l.classList.toggle('on', e.target.checked)));
  }
  $('button[data-modal="scenes"]')?.addEventListener('click', buildScenePicker);
  $('.section#scenes')?.addEventListener('click', e => { if (!e.target.closest('.pencil') && $('.section#scenes').dataset.active==='0') buildScenePicker(); });

  // ─── Credentials picker (existing list + add form) ─────────────────────
  function renderCredentialsList() {
    const wrap = $('#cred-existing');
    if (!wrap) return;
    const creds = BOOT.profile.credentials || [];
    wrap.innerHTML = creds.length ? '<h4 class="modal-subh">Existing</h4><ul class="cred-edit-list">' +
      creds.map(c => `<li><b>${escH(c.raw_issuer)}</b> — ${escH(c.raw_program)}
        ${c.expires_at?`<span class="ann">expires ${c.expires_at}</span>`:''}
        <button class="x" data-del-cred="${c.id}">✕</button></li>`).join('') + '</ul>' : '';
    $$('[data-del-cred]', wrap).forEach(b => b.addEventListener('click', async () => {
      const id = b.dataset.delCred;
      const r = await apiCall('DELETE', '/me/credentials/' + id);
      if (r) {
        BOOT.profile.credentials = BOOT.profile.credentials.filter(c => c.id != id);
        renderCredentialsList();
        // Re-render list in section body.
        const list = $('#cred-list');
        if (list) list.innerHTML = BOOT.profile.credentials.map(c =>
          `<li><b>${escH(c.raw_issuer)}</b> — ${escH(c.raw_program)}${c.expires_at?` <span class="ann">expires ${escH(c.expires_at)}</span>`:''}</li>`).join('');
        const sec = $('#credentials'); sec.dataset.active = BOOT.profile.credentials.length ? '1' : '0';
      }
    }));
    // Refresh body list too.
    const list = $('#cred-list');
    if (list) list.innerHTML = (BOOT.profile.credentials||[]).map(c =>
      `<li><b>${escH(c.raw_issuer)}</b> — ${escH(c.raw_program)}${c.expires_at?` <span class="ann">expires ${escH(c.expires_at)}</span>`:''}</li>`).join('');
  }
  $('button[data-modal="credentials"]')?.addEventListener('click', renderCredentialsList);
  $('.section#credentials')?.addEventListener('click', e => { if (!e.target.closest('.pencil') && $('.section#credentials').dataset.active==='0') renderCredentialsList(); });

  // Credential typeahead
  let credSearchTimer = null;
  $('#cred-search')?.addEventListener('input', e => {
    clearTimeout(credSearchTimer);
    const q = e.target.value.trim();
    if (q === '') { $('#cred-typeahead').hidden = true; return; }
    credSearchTimer = setTimeout(async () => {
      const r = await fetch(API + '/catalogs/credentials?q=' + encodeURIComponent(q), {credentials:'include'});
      const d = await r.json();
      const items = d.items || [];
      const ta = $('#cred-typeahead');
      if (!items.length) { ta.hidden = true; return; }
      ta.innerHTML = items.map(it => `<div class="ta-item" data-id="${it.id}" data-issuer="${escH(it.issuer)}" data-program="${escH(it.program)}">
        <b>${escH(it.issuer)}</b> — ${escH(it.program)} <span class="ann">${escH(it.category)}</span></div>`).join('');
      ta.hidden = false;
      $$('.ta-item', ta).forEach(el => el.addEventListener('click', () => {
        $('#cred-catalog-id').value = el.dataset.id;
        $('#cred-issuer').value = el.dataset.issuer;
        $('#cred-program').value = el.dataset.program;
        $('#cred-search').value = el.dataset.issuer + ' — ' + el.dataset.program;
        ta.hidden = true;
      }));
    }, 200);
  });

  // ─── Highlights picker ─────────────────────────────────────────────────
  function buildHighlightsPicker() {
    const userInsts  = BOOT.profile.instruments || [];
    const userSkills = BOOT.profile.skills      || [];
    const pickedSet  = new Set((BOOT.profile.highlights||[]).map(h => `${h.kind}:${h.ref_id}`));
    const html = [];
    if (userInsts.length)  html.push('<div class="picker-group"><h5>Instruments</h5>' +
      userInsts.map(i => `<label><input type="checkbox" data-kind="instrument" value="${i.id}" ${pickedSet.has('instrument:'+i.id)?'checked':''}> ${escH(i.name)}</label>`).join('') + '</div>');
    if (userSkills.length) html.push('<div class="picker-group"><h5>Skills</h5>' +
      userSkills.map(s => `<label><input type="checkbox" data-kind="skill" value="${s.id}" ${pickedSet.has('skill:'+s.id)?'checked':''}> ${escH(s.name)}</label>`).join('') + '</div>');
    if (!html.length) html.push('<p class="ink-mute" style="font-size:13px">Add some instruments or skills first — highlights pull from those.</p>');
    $('#hl-picker').innerHTML = html.join('');
    // Cap at 3.
    $$('#hl-picker input').forEach(cb => cb.addEventListener('change', () => {
      const checked = $$('#hl-picker input:checked');
      if (checked.length > 3) { cb.checked = false; setSaveStatus('max 3 highlights', 'err'); }
    }));
  }
  $('button[data-modal="highlights"]')?.addEventListener('click', buildHighlightsPicker);

  // ─── drag-to-reorder ──────────────────────────────────────────────────
  let dragSrc = null;
  $$('.section').forEach(sec => {
    const grip = sec.querySelector('.grip'); if (!grip) return;
    grip.addEventListener('mousedown', () => sec.setAttribute('draggable','true'));
    grip.addEventListener('mouseup',   () => sec.removeAttribute('draggable'));
    sec.addEventListener('dragstart', e => { dragSrc=sec; sec.classList.add('dragging'); e.dataTransfer.effectAllowed='move'; e.dataTransfer.setData('text/plain', sec.id); });
    sec.addEventListener('dragend', async () => {
      sec.classList.remove('dragging'); sec.removeAttribute('draggable');
      $$('.section.drag-over').forEach(x => x.classList.remove('drag-over'));
      await apiCall('PATCH', '/me/section-order', {order: $$('.section').map(s => s.dataset.kind)});
    });
    sec.addEventListener('dragover', e => { if (!dragSrc || dragSrc === sec) return; e.preventDefault(); sec.classList.add('drag-over'); });
    sec.addEventListener('dragleave', () => sec.classList.remove('drag-over'));
    sec.addEventListener('drop', e => { e.preventDefault(); if (!dragSrc || dragSrc === sec) return; sec.parentNode.insertBefore(dragSrc, sec); sec.classList.remove('drag-over'); });
  });

  // ─── rail drawer (mobile): the section nav slides in below 780px ────────
  // Below 780px the rail is a fixed off-canvas drawer (CSS); the .rail-toggle
  // hamburger in the topbar reveals it. Above 780px these controls are
  // display:none and the rail is the static column — this wiring is inert.
  const railEl       = document.getElementById('lg-rail');
  const railToggle   = document.getElementById('lg-rail-toggle');
  const railBackdrop = document.getElementById('lg-rail-backdrop');
  const railClose    = document.getElementById('lg-rail-close');
  function setRail(open){
    if (!railEl) return;
    railEl.classList.toggle('open', open);
    railBackdrop?.classList.toggle('open', open);
    railToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  railToggle?.addEventListener('click',   () => setRail(!railEl.classList.contains('open')));
  railBackdrop?.addEventListener('click', () => setRail(false));
  railClose?.addEventListener('click',    () => setRail(false));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') setRail(false); });

  // ─── rail tab scroll ──────────────────────────────────────────────────
  $$('.tab[data-anchor]').forEach(t => t.addEventListener('click', () => {
    const el = document.getElementById(t.dataset.anchor); if (el) el.scrollIntoView({behavior:'smooth', block:'start'});
    $$('.tab.active').forEach(x => x.classList.remove('active')); t.classList.add('active');
    setRail(false);   // close the mobile drawer after picking a section
  }));

  if (new URLSearchParams(location.search).get('just_claimed') === '1') {
    setTimeout(() => openModal('about'), 250);
  }
  applyRole();

  // ─── slice-3: practices ────────────────────────────────────────────────
  let PRACTICES = (BOOT.practices || []).slice();

  function renderPracticeSection() {
    const sec = $('#my-practices');
    if (!sec) return;
    if (!PRACTICES.length) {
      sec.innerHTML = '';
      $('#practices').dataset.active = '0';
      document.querySelector('.tab[data-anchor="practices"]')?.classList.remove('on');
      return;
    }
    sec.innerHTML = PRACTICES.map(p => {
      const loc = p.location_text ? ` <span class="ann">— ${escH(p.location_text)}</span>` : '';
      return `<div class="pr-item" data-uuid="${escH(p.uuid)}">
        <span class="pr-name"><a href="/p/${escH(p.slug)}">${escH(p.name)}</a></span>
        ${loc}
        <span class="ann">${escH(p.role)}</span>
      </div>`;
    }).join('');
    $('#practices').dataset.active = '1';
    document.querySelector('.tab[data-anchor="practices"]')?.classList.add('on');
  }

  function renderPracticeModalList() {
    const list = $('#pr-list'); if (!list) return;
    if (!PRACTICES.length) {
      list.innerHTML = '<div class="empty">You’re not attached to any practices yet.</div>';
      return;
    }
    list.innerHTML = PRACTICES.map(p => `
      <div class="pr-item" data-uuid="${escH(p.uuid)}">
        <span class="pr-name">${escH(p.name)}</span>
        <span class="ann">${escH(p.role)}</span>
        <span class="pr-actions">
          ${p.role === 'owner' ? `<button class="btn" data-pr-edit="${escH(p.uuid)}">Edit</button>` : ''}
          <button class="btn" data-pr-leave="${escH(p.uuid)}">Leave</button>
        </span>
      </div>
    `).join('');
  }

  $$('button[data-modal="practices"]').forEach(b => b.addEventListener('click', renderPracticeModalList));
  // Click anywhere on an empty practices section card → open the manage modal.
  // (Active sections already get their click via the pencil button.)
  $('#practices')?.addEventListener('click', e => {
    if (e.target.closest('a, button')) return;
    if ($('#practices').dataset.active === '0') {
      openModal('practices'); renderPracticeModalList();
    }
  });

  $('#pr-create')?.addEventListener('click', async () => {
    const name = $('#pr-new-name').value.trim();
    if (!name) { alert('Name is required'); return; }
    const body = {
      name,
      tagline: $('#pr-new-tag').value.trim() || null,
      location_text: $('#pr-new-loc').value.trim() || null,
      location_visibility: 'public',
    };
    const r = await apiCall('POST', '/me/practices', body);
    if (!r) return;
    PRACTICES.push({uuid: r.uuid, slug: r.slug, name, tagline: body.tagline,
      about: null, website: null, location_text: body.location_text,
      location_visibility: 'public', role: 'owner'});
    $('#pr-new-name').value = ''; $('#pr-new-tag').value = ''; $('#pr-new-loc').value = '';
    renderPracticeModalList(); renderPracticeSection();
  });

  document.addEventListener('click', async e => {
    const leaveBtn = e.target.closest('[data-pr-leave]');
    if (leaveBtn) {
      const uuid = leaveBtn.dataset.prLeave;
      if (!confirm('Leave this practice? (You can be re-added later.)')) return;
      const r = await apiCall('DELETE', '/me/practices/' + uuid);
      if (!r) return;
      PRACTICES = PRACTICES.filter(p => p.uuid !== uuid);
      renderPracticeModalList(); renderPracticeSection();
      return;
    }
    const editBtn = e.target.closest('[data-pr-edit]');
    if (editBtn) {
      const uuid = editBtn.dataset.prEdit;
      const p = PRACTICES.find(x => x.uuid === uuid); if (!p) return;
      $('#pe-uuid').value    = uuid;
      $('#pe-name').value    = p.name || '';
      $('#pe-tagline').value = p.tagline || '';
      $('#pe-about').value   = p.about || '';
      $('#pe-website').value = p.website || '';
      $('#pe-loc').value     = p.location_text || '';
      $('#pe-loc-vis').value = p.location_visibility || 'public';
      openModal('practice-edit');
    }
  });

  $('#pe-save')?.addEventListener('click', async () => {
    const uuid = $('#pe-uuid').value;
    const body = {
      name:    $('#pe-name').value.trim(),
      tagline: $('#pe-tagline').value.trim(),
      about:   $('#pe-about').value,
      website: $('#pe-website').value.trim(),
      location_text: $('#pe-loc').value.trim(),
      location_visibility: $('#pe-loc-vis').value,
    };
    if (!body.name) { alert('Name is required'); return; }
    const r = await apiCall('PATCH', '/me/practices/' + uuid, body);
    if (!r) return;
    const p = PRACTICES.find(x => x.uuid === uuid);
    if (p) Object.assign(p, body);
    closeModal('practice-edit');
    renderPracticeModalList(); renderPracticeSection();
  });
})();
