/* groups-design lane — ROUND 2 mocks: navigation entry points + posting flow.
 *
 * DESIGN PRINCIPLE OF THIS FILE: decorate the REAL components, don't rebuild them.
 * Wherever the surface already exists (the Nav tray, the You sheet, the "+ New post"
 * composer, the Hub cards), we open the real thing and inject only the DELTA — and we
 * do it by CLONING an existing real node and re-labelling it, never by hardcoding a
 * class name. So the mock inherits the real styling exactly, and it cannot drift from
 * the house idiom. The only thing built from scratch is the Groups directory, because
 * that page genuinely does not exist yet.
 *
 * Nothing here ships. Runtime-injected via CDP over the untouched dev2 serve.
 * Variant via window.__MOCK_VARIANT.
 */
(() => {
  const V = window.__MOCK_VARIANT || 'chip';
  const log = [];
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  // ---- NAVIGATION GUARD — load-bearing, do not remove ----------------------
  // We open the real controls by clicking the real triggers. But several of those
  // triggers are genuine anchors with real hrefs (bottom-nav.js:402 — the You tab IS
  // `<a href="/profile/edit">`), so a bare .click() NAVIGATES THE PAGE and the mock dies
  // with the tab. Capture-phase preventDefault kills the navigation while still letting
  // the site's own bubble-phase handler run and open the sheet — which is exactly what we
  // want: real component, real motion, no page change.
  document.addEventListener('click', (e) => {
    const a = e.target && e.target.closest && e.target.closest('a[href]');
    if (a && !a.hasAttribute('data-mk-allow')) e.preventDefault();
  }, true);
  document.addEventListener('submit', (e) => e.preventDefault(), true);

  // ---- real data (dev2 Postgres: forums.bp_group + forums.forum) ----------
  const CHAPTERS = [
    { name: 'SoCal Looths',           slug: 'socal-looths',      members: 827, place: 'Southern California' },
    { name: 'Tri State Looths (NYC)', slug: 'tri-state-looths',  members: 214, place: 'NY / NJ / CT' },
    { name: 'Looth Troop PNW',        slug: 'looth-troop-pnw',   members: 168, place: 'Pacific Northwest' },
    { name: 'DMV Looths',             slug: 'dmv-looths',        members: 121, place: 'DC / Maryland / Virginia' },
    { name: 'Basque Country Looths',  slug: 'basque-country',    members: 326, place: 'Euskadi' },
    { name: 'SW Ontario Looths',      slug: 'sw-ontario-looths', members:  64, place: 'Southwest Ontario' },
    { name: 'Looths of Ireland',      slug: 'looths-of-ireland', members:  58, place: 'Ireland' },
    { name: 'Middle Tennessee Looths',slug: 'middle-tennessee',  members:  41, place: 'Nashville area' },
    { name: 'Ohio Local Looths',      slug: 'ohio-local-looths', members:  11, place: 'Ohio' },
  ];
  // postable leaf-forum counts are REAL — this is the fact that decides the posting flow
  const SUBJECTS = [
    { name: 'Repair And Restoration',            slug: 'repair-and-restoration', members: 1841, leaves: 9 },
    { name: 'New Builds',                        slug: 'new-builds',             members: 1843, leaves: 7 },
    { name: 'Tools, Spaces, Robots and Widgets', slug: 'tools-spaces-robots',    members: 1841, leaves: 6 },
    { name: 'Business',                          slug: 'business',               members: 1841, leaves: 5 },
    { name: 'Market Place',                      slug: 'market-place',           members: 1841, leaves: 2 },
  ];
  // the proposed NEW group that gives the group-less forums a home (§B.4)
  const GENERAL = { name: 'General', slug: 'general', members: 1841, leaves: 2,
                    note: 'Quick Questions · Suggestion Box' };
  // Repair's REAL 9 postable leaves — the step-2 list
  const REPAIR_LEAVES = [
    'Acoustic Repair', 'Electric Repair', 'Finish Repair', 'Amps, Pickups, and Pedals',
    'Neck Reset Database', 'Touring Tech', 'Folk, Bluegrass, Irish, Old Time Instruments',
    'Share Your Repair Content', 'General',
  ];

  const MY_CHAPTERS = [CHAPTERS[0]];                       // SoCal
  const MY_SUBJECTS = [SUBJECTS[0], SUBJECTS[1], SUBJECTS[3]];

  const $  = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => [].slice.call((r || document).querySelectorAll(s));

  const svg = (d, s) => `<svg viewBox="0 0 24 24" width="${s || 18}" height="${s || 18}" fill="none"
      stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
      style="flex:0 0 auto">${d}</svg>`;
  const ICO = {
    groups: '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>'
          + '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    pin:    '<path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
    chev:   '<polyline points="9 18 15 12 9 6"/>',
    back:   '<polyline points="15 18 9 12 15 6"/>',
    check:  '<polyline points="20 6 9 17 4 12"/>',
  };

  // Shared mock styling. Uses the REAL design tokens so colours/fonts are genuine.
  const CSS = `
  .mk-badge { position:fixed; left:0; right:0; bottom:0; z-index:2147483000;
      background:#1a1d1a; color:#fff; font:600 12px/1.45 var(--font-body,system-ui);
      padding:7px 12px; text-align:center; letter-spacing:.2px; }
  .mk-badge b { color:#b7d07a; }

  /* the group chip on a Hub card — the recommended primary door (§A.2) */
  .mk-gchip { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:800;
      letter-spacing:.6px; text-transform:uppercase; padding:3.5px 9px 3.5px 7px; border-radius:999px;
      background:var(--lg-sage-tint,#eef2e3); color:var(--lg-sage-d,#5f7040);
      border:1px solid var(--lg-sage-3,#c3d3a3); cursor:pointer; white-space:nowrap; }
  .mk-gchip svg { opacity:.85 }
  /* A REAL consequence, not mock dressing: at 390 the badge row does not have room for
     one more pill — the group chip shoved the DISCUSSION badge over the date. The row has
     to wrap. Carry this into the build (§A.2), it is not free. */
  .fc-author__badges { flex-wrap:wrap !important; row-gap:5px; }
  /* the §B.4 hole: a leaf with no parent group (Quick Questions, 181 topics) */
  .mk-gchip--gap { background:#fbecea; color:#a5352a; border-color:#e8b7b0; }
  /* dashed = the existing free-text sub-forum chip, kept alongside (different fact) */
  .mk-subchip-ring { outline:1.5px dashed var(--lg-clay,#c66845); outline-offset:2px; border-radius:999px; }
  .mk-callout { position:absolute; z-index:60; background:#1a1d1a; color:#fff; font:600 11.5px/1.4 var(--font-body,system-ui);
      padding:6px 10px; border-radius:8px; white-space:nowrap; box-shadow:0 6px 18px rgba(0,0,0,.28); }
  .mk-callout::after { content:''; position:absolute; left:14px; bottom:-5px; width:0; height:0;
      border:5px solid transparent; border-top-color:#1a1d1a; border-bottom:0; }

  /* injected tray/sheet bits are CLONES of real nodes; these only tint the NEW one */
  .mk-new-tile { position:relative; }
  .mk-new-tile::after { content:'NEW'; position:absolute; top:4px; right:4px; font-size:8.5px; font-weight:800;
      letter-spacing:.5px; background:var(--lg-clay,#c66845); color:#fff; padding:1.5px 4px; border-radius:4px; }

  /* composer picker (replaces the forum tree inside the REAL #ntm-overlay) */
  .mk-pick { border:1px solid var(--lg-line,#e3ddd0); border-radius:12px; overflow:hidden; background:#fff; }
  .mk-pick__sech { font:800 11px/1 var(--font-body,system-ui); letter-spacing:.7px; text-transform:uppercase;
      color:var(--lg-sage-d,#6b7c52); background:var(--lg-sage-tint,#eef2e3); padding:9px 12px;
      border-bottom:1px solid var(--lg-line,#e3ddd0); }
  .mk-pick__row { display:flex; align-items:center; gap:11px; padding:11px 12px; cursor:pointer;
      border-bottom:1px solid var(--lg-line-2,#efeae0); background:#fff; }
  .mk-pick__row:last-child { border-bottom:0 }
  .mk-pick__row.on { background:var(--lg-sage-tint,#eef2e3); }
  .mk-pick__av { width:34px; height:34px; border-radius:9px; flex:0 0 auto; display:grid; place-items:center;
      color:#fff; font:800 13px/1 var(--font-head,serif); }
  .mk-pick__t { flex:1 1 auto; min-width:0 }
  .mk-pick__n { font:600 14.5px/1.3 var(--font-body,system-ui); color:var(--lg-charcoal,#1a1d1a); }
  .mk-pick__s { font:400 12px/1.35 var(--font-body,system-ui); color:var(--lg-ink-3,#8a8f89); margin-top:1px; }
  .mk-pick__go { color:var(--lg-ink-3,#8a8f89); flex:0 0 auto }
  .mk-ctx { display:flex; align-items:center; gap:9px; padding:10px 12px; border-radius:12px;
      background:var(--lg-sage-tint,#eef2e3); border:1px solid var(--lg-sage-3,#c3d3a3); }
  .mk-ctx__n { font:600 14px/1.3 var(--font-body,system-ui); color:var(--lg-sage-d,#4f6135); }
  .mk-ctx__x { margin-left:auto; font:600 12.5px/1 var(--font-body,system-ui); color:var(--lg-sage-d,#6b7c52);
      text-decoration:underline; cursor:pointer; }
  .mk-optional { font-weight:400; color:var(--lg-ink-3,#8a8f89); }

  /* Groups directory — the only surface built from scratch (it does not exist yet) */
  .mkd { max-width:1180px; margin:0 auto; padding:26px 20px 90px; font-family:var(--font-body,system-ui);
      color:var(--lg-charcoal,#1a1d1a); }
  .mkd h1 { font:700 30px/1.2 var(--font-head,serif); margin:0 0 6px; letter-spacing:-.3px }
  .mkd__sub { color:var(--lg-ink-3,#8a8f89); font-size:14.5px; margin:0 0 24px }
  .mkd__sech { font:800 12px/1 var(--font-body,system-ui); letter-spacing:.8px; text-transform:uppercase;
      color:var(--lg-sage-d,#6b7c52); margin:26px 0 12px; display:flex; align-items:center; gap:9px }
  .mkd__sech::after { content:''; flex:1 1 auto; height:1px; background:var(--lg-line,#e3ddd0) }
  .mkd__grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(268px,1fr)); gap:13px }
  .mkd__card { border:1px solid var(--lg-line,#e3ddd0); border-radius:14px; background:#fff; padding:15px;
      display:flex; gap:12px; align-items:flex-start }
  .mkd__av { width:44px; height:44px; border-radius:11px; flex:0 0 auto; display:grid; place-items:center;
      color:#fff; font:800 16px/1 var(--font-head,serif) }
  .mkd__n { font:700 15.5px/1.3 var(--font-body,system-ui); margin-bottom:2px }
  .mkd__m { font-size:12.5px; color:var(--lg-ink-3,#8a8f89); display:flex; align-items:center; gap:4px }
  .mkd__join { margin-top:9px; font:600 12.5px/1 var(--font-body,system-ui); padding:7px 13px; border-radius:999px;
      border:1px solid var(--lg-sage-3,#c3d3a3); background:#fff; color:var(--lg-sage-d,#5f7040); cursor:pointer }
  .mkd__join.on { background:var(--lg-sage-tint,#eef2e3); border-color:var(--lg-sage-3,#c3d3a3) }
  `;

  const AV = ['#c66845', '#87986a', '#b8842b', '#6f8fa6', '#8a6f5b', '#586b3f', '#a67c52'];
  const avc = (n) => AV[(n.charCodeAt(0) + n.length) % AV.length];
  const ini = (n) => n.replace(/[^A-Za-z ]/g, '').split(' ').filter(Boolean).slice(0, 2)
                      .map((w) => w[0]).join('').toUpperCase();

  const style = document.createElement('style');
  style.textContent = CSS;
  document.head.appendChild(style);

  const banner = (t) => {
    const b = document.createElement('div');
    b.className = 'mk-badge';
    b.innerHTML = t;
    document.body.appendChild(b);
  };

  // ======================================================================
  // Helpers that OPEN the real components (so we decorate, never rebuild)
  // ======================================================================
  async function openTray() {
    // The Nav button is the bar's tray trigger. Find it without depending on a
    // class we haven't verified: the bar button whose label/aria says "Nav".
    const cand = $$('button, a').filter((el) => {
      const s = ((el.getAttribute('aria-label') || '') + ' ' + (el.textContent || '')).toLowerCase();
      return /\bnav\b/.test(s) && el.getBoundingClientRect().bottom > innerHeight - 120;
    });
    if (!cand.length) { log.push('NAV BUTTON NOT FOUND'); return null; }
    cand[0].click();
    await sleep(600);                       // the .lt-sheet slide (.26s) + settle
    const sheet = $('.lt-sheet.is-open') || $('.lt-sheet');
    if (!sheet) log.push('TRAY SHEET NOT FOUND');
    return sheet;
  }

  async function openYou() {
    const you = $('a[href="/profile/edit"]') || $$('button, a')
      .find((el) => /\byou\b/i.test(el.getAttribute('aria-label') || el.textContent || ''));
    if (!you) { log.push('YOU BUTTON NOT FOUND'); return null; }
    you.click();
    await sleep(600);
    return $('.lt-sheet.is-open') || $('.lt-sheet');
  }

  async function openComposer() {
    const btn = $('[data-ntm-open]');
    if (!btn) { log.push('NO [data-ntm-open]'); return null; }
    btn.click();
    await sleep(900);                       // modal + Quill mount
    const ov = $('#ntm-overlay');
    if (ov) ov.hidden = false;
    return ov;
  }

  // The composer on main is now the 4-STEP WIZARD (hub-polish.js ~1233, the merged
  // hub-post-wizard lane). It KEEPS the real forum list #ntm-forum but sets it
  // display:none and fronts it with its own trigger button, `.lg-fbc-forumtrig`, which
  // toggles that display. So the real 55-leaf tree (45 rows, 8 category headers, GENERAL
  // twice) is one click away — we open the REAL control rather than faking one.
  async function openForumTree() {
    const trig = $('.lg-fbc-forumtrig');
    const list = $('#ntm-forum');
    if (!list) { log.push('#ntm-forum NOT FOUND'); return null; }
    if (trig && list.style.display === 'none') { trig.click(); await sleep(350); }
    list.style.display = '';                // belt & braces if the wizard didn't mount
    return list;
  }

  // Re-label the wizard's trigger so the shot reads as one coherent control.
  function setTrigger(text, unset) {
    const val = $('.lg-fbc-forumtrig__val');
    const lb  = $('.lg-fbc-forumtrig__lb');
    if (val) val.textContent = text;
    if (lb && text) lb.textContent = unset ? 'Group' : 'Posting to';
    const trig = $('.lg-fbc-forumtrig');
    if (trig) trig.classList.toggle('is-unset', !!unset);
  }

  // The PWA "Install Looth" banner is fixed to the bottom of every page and lands
  // squarely over the surfaces we're shooting. It is real, it is just not the subject.
  function hideChrome() {
    const pwa = document.getElementById('looth-pwa-banner');
    if (pwa) pwa.remove();
  }

  // Build the group-first picker that REPLACES #ntm-forum (the forum tree).
  function pickerHTML(step) {
    const row = (g, sub, on) => `
      <div class="mk-pick__row${on ? ' on' : ''}">
        <div class="mk-pick__av" style="background:${avc(g.name)}">${ini(g.name)}</div>
        <div class="mk-pick__t">
          <div class="mk-pick__n">${g.name}</div>
          <div class="mk-pick__s">${sub}</div>
        </div>
        ${on ? `<span class="mk-pick__go" style="color:var(--lg-sage-d,#6b7c52)">${svg(ICO.check, 17)}</span>`
             : `<span class="mk-pick__go">${svg(ICO.chev, 16)}</span>`}
      </div>`;

    if (step === 'sub') {
      // STEP 2 — sub-forums SCOPED TO THE GROUP. Repair really does have 9.
      return `<div class="mk-pick">
        <div class="mk-pick__sech">${'Repair And Restoration'} — which forum?</div>
        ${REPAIR_LEAVES.map((n, i) => `
          <div class="mk-pick__row${i === 0 ? ' on' : ''}">
            <div class="mk-pick__t"><div class="mk-pick__n">${n}</div></div>
            ${i === 0 ? `<span class="mk-pick__go" style="color:var(--lg-sage-d,#6b7c52)">${svg(ICO.check, 17)}</span>` : ''}
          </div>`).join('')}
      </div>`;
    }
    // STEP 1 — groups. Flat. Real names. No tree, no phantom GENERAL.
    return `<div class="mk-pick">
      <div class="mk-pick__sech">Your chapters</div>
      ${MY_CHAPTERS.map((c) => row(c, c.place + ' · ' + c.members + ' members', false)).join('')}
      <div class="mk-pick__sech">Subjects</div>
      ${SUBJECTS.map((s) => row(s, s.leaves + ' forums · ' + s.members.toLocaleString() + ' members', false)).join('')}
      <div class="mk-pick__sech">Anything else</div>
      ${row(GENERAL, GENERAL.note, false)}
    </div>`;
  }

  // Swap the real forum tree for our picker, keeping the real modal chrome
  // (heading, Title, Quill body, tags, Post button) untouched around it.
  async function swapPicker(html, labelText) {
    const list = await openForumTree();
    const lab  = $('#ntm-forum-label');
    if (!list) return;
    list.innerHTML = html;
    list.style.cssText = 'max-height:none;border:0;padding:0;background:transparent;display:block';
    if (lab && labelText) lab.innerHTML = labelText;
  }

  // ======================================================================
  // VARIANTS
  // ======================================================================
  const run = {

    // ---- N1: the group chip on real Hub cards (the primary door, §A.2) ----
    async chip() {
      // DISCUSSION CARDS ONLY. A video/article card renders its chip from
      // `content_forum_label` and has no group at all (_feed.php:1445 vs :1570, and Q6)
      // — chipping one would contradict the design it is meant to illustrate.
      // The group is looked up from the card's REAL leaf forum via the REAL parent
      // mapping (forums.forum.parent_forum_id, dev2 Postgres). Nothing is invented:
      // an unmapped leaf is skipped, and the group-less ones are exactly the §B.4 gap.
      const LEAF2GROUP = {
        'acoustic repair': 'Repair and Restoration', 'electric repair': 'Repair and Restoration',
        'finish repair': 'Repair and Restoration', 'neck reset database': 'Repair and Restoration',
        'touring tech': 'Repair and Restoration', 'general': 'Repair and Restoration',
        'share your repair content': 'Repair and Restoration',
        'acoustic builds': 'New Builds', 'electric builds': 'New Builds',
        'design and testing': 'New Builds', 'finish new builds': 'New Builds',
        'share your new builds content': 'New Builds',
        'folk, bluegrass, irish, old time instruments': 'New Builds',
        'amps, pickups, and pedals': 'New Builds',
        '3d printing': 'Tools, Spaces, Robots, and Widgets', 'cad/cam': 'Tools, Spaces, Robots, and Widgets',
        'cnc': 'Tools, Spaces, Robots, and Widgets', 'plek machine': 'Tools, Spaces, Robots, and Widgets',
        'shop organisation': 'Tools, Spaces, Robots, and Widgets',
        'tools and jigs': 'Tools, Spaces, Robots, and Widgets',
        'customer relations': 'Business', 'general business': 'Business', 'job postings': 'Business',
        'paper work and drudgery': 'Business', 'resumes': 'Business',
        'buy! buy! buy!': 'Market Place', 'sell! sell! sell!': 'Market Place',
        'stewmac': 'Sponsors', 'total vise': 'Sponsors', 'go acoustic audio': 'Sponsors',
        'strings micro factory': 'Sponsors',
      };
      // These leaves have NO parent group today — the §B.4 hole the "General" group fills.
      const GROUPLESS = { 'quick questions': 1, 'suggestion box / bug reporting': 1, 'anonymous questions': 1 };

      const cards = $$('.feed-card');
      if (!cards.length) { log.push('NO .feed-card'); return; }
      let chipped = 0, skipped = 0, gapped = 0;

      cards.forEach((card) => {
        if (!$('.feed-card__kind-badge--discussion', card)) { skipped++; return; }  // CPT → no group
        const badges = $('.fc-author__badges', card);
        const leafEl = $('.fc-cat-chip', badges || card);
        if (!badges || !leafEl) return;
        const leaf = leafEl.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
        const group = LEAF2GROUP[leaf];

        if (!group) {
          if (GROUPLESS[leaf]) {                    // show the gap honestly, don't hide it
            leafEl.classList.add('mk-subchip-ring');
            const gap = document.createElement('span');
            gap.className = 'mk-gchip mk-gchip--gap';
            gap.innerHTML = svg(ICO.groups, 12) + 'no group (§B.4)';
            badges.insertBefore(gap, badges.firstChild);
            gapped++;
          }
          return;
        }
        leafEl.classList.add('mk-subchip-ring');    // the existing chip STAYS: which shelf
        const chip = document.createElement('span');
        chip.className = 'mk-gchip';
        chip.innerHTML = svg(ICO.groups, 12) + group;
        badges.insertBefore(chip, badges.firstChild);
        chipped++;
      });
      log.push(`chipped=${chipped} groupless=${gapped} cpt-skipped=${skipped}`);

      // The Hub feed defaults to RANDOM order, so whichever card happens to land in the
      // viewport is a coin toss — the first shot of this came back showing a Loothprint
      // card with (correctly) no chip on it, i.e. a mock of nothing. Scroll the first
      // CHIPPED card into frame so the shot shows the subject regardless of feed order.
      const firstChipped = $('.mk-gchip');
      const card = firstChipped && firstChipped.closest('.feed-card');
      if (card) {
        const y = card.getBoundingClientRect().top + scrollY - 70;   // clear the sticky bar
        window.scrollTo(0, Math.max(0, y));
        await sleep(400);
      }

      const first = $('.fc-cat-chip.mk-subchip-ring');
      if (first) {
        const r = first.getBoundingClientRect();
        const c = document.createElement('div');
        c.className = 'mk-callout';
        c.textContent = 'existing sub-forum chip — KEPT (which shelf)';
        c.style.left = Math.max(8, r.left + scrollX - 10) + 'px';
        c.style.top  = (r.top + scrollY - 34) + 'px';
        document.body.appendChild(c);
      }
      banner('<b>N1 — the group chip, on DISCUSSION cards only.</b> Sage chip = <b>which place</b> '
        + '(tap → that mini-hub); the dashed chip is the existing sub-forum label, <b>kept</b> = '
        + '<b>which shelf</b>. Groups are the REAL parents from the DB, not decoration. '
        + '<b>Video/article cards get no chip</b> — they have no group (Q6). A "no group" chip is the '
        + '§B.4 hole that the real <b>General</b> group closes.');
    },

    // ---- N3a: Nav tray + a Groups tile (RECOMMENDED — a separate door) ----
    async tray() {
      const sheet = await openTray();
      if (!sheet) return;
      // Clone a REAL destination tile so styling is inherited exactly, then relabel.
      const tiles = $$('a, button', sheet).filter((el) => el.querySelector('svg')
        && el.getBoundingClientRect().width > 60 && el.getBoundingClientRect().height > 50);
      if (!tiles.length) { log.push('NO TILES TO CLONE'); return; }
      const hub = tiles.find((t) => /hub/i.test(t.textContent)) || tiles[0];
      const clone = hub.cloneNode(true);
      clone.classList.add('mk-new-tile');
      const lbl = [].find.call(clone.querySelectorAll('*'), (n) => n.children.length === 0
        && n.textContent.trim() && !n.querySelector('svg'));
      if (lbl) lbl.textContent = 'Groups';
      const ic = clone.querySelector('svg');
      if (ic) ic.innerHTML = ICO.groups;
      if (clone.tagName === 'A') clone.setAttribute('href', '/groups/');
      hub.parentNode.insertBefore(clone, hub.nextSibling);
      banner('<b>N3a — RECOMMENDED: Groups is its own door.</b> A tile in the Nav tray, '
        + 'sibling to the Hub (and to the content-type picker, which MERGED tonight). '
        + 'A <b>type</b> is a lens; a <b>group</b> is a place with members and a Join button. '
        + 'Same sheet ≠ same kind of thing. <b>Label stays "Groups" per Ian\'s ruling</b> — the '
        + 'community spaces are told apart by their NAMES, not by a category word.');
    },

    // ---- N3b: the ALTERNATIVE — one merged picker, two titled sections ----
    async trayMerged() {
      const sheet = await openTray();
      if (!sheet) return;
      // sheet.lastElementChild is the TILE GRID. Appending into it made these sections
      // grid CELLS — half-width, and they widened the grid until the tiles ran off the
      // right edge. Land them AFTER the grid, as siblings, full width. (A broken mock of
      // the option I'm arguing against isn't an honest comparison, it's a strawman.)
      const grid = sheet.lastElementChild;
      const wrap = document.createElement('div');
      wrap.style.cssText = 'padding:4px 2px 10px;width:100%;grid-column:1/-1';
      wrap.innerHTML = `
        <div class="mk-pick__sech" style="border-radius:8px 8px 0 0">Show me &nbsp;<span style="opacity:.6;font-weight:600">(content type)</span></div>
        <div class="mk-pick" style="border-radius:0 0 12px 12px;border-top:0;margin-bottom:12px">
          ${['Everything', 'Discussions', 'Videos', 'Articles', 'Loothprints']
            .map((t, i) => `<div class="mk-pick__row${i === 0 ? ' on' : ''}">
              <div class="mk-pick__t"><div class="mk-pick__n">${t}</div></div></div>`).join('')}
        </div>
        <div class="mk-pick__sech" style="border-radius:8px 8px 0 0">Go to a group &nbsp;<span style="opacity:.6;font-weight:600">(a place)</span></div>
        <div class="mk-pick" style="border-radius:0 0 12px 12px;border-top:0">
          ${[MY_CHAPTERS[0], SUBJECTS[0], SUBJECTS[1]].map((g) => `
            <div class="mk-pick__row">
              <div class="mk-pick__av" style="background:${avc(g.name)}">${ini(g.name)}</div>
              <div class="mk-pick__t"><div class="mk-pick__n">${g.name}</div>
                <div class="mk-pick__s">${g.members.toLocaleString()} members</div></div>
              <span class="mk-pick__go">${svg(ICO.chev, 16)}</span>
            </div>`).join('')}
          <div class="mk-pick__row"><div class="mk-pick__t">
            <div class="mk-pick__n" style="color:var(--lg-sage-d,#6b7c52)">All groups →</div></div></div>
        </div>`;
      if (grid && grid.parentNode) grid.parentNode.insertBefore(wrap, grid.nextSibling);
      else sheet.appendChild(wrap);
      // the sections are the subject of this shot — put them in frame
      wrap.scrollIntoView({ block: 'center' });
      await sleep(250);
      banner('<b>N3b — the ALTERNATIVE you asked me to show honestly.</b> ONE sheet, two titled '
        + 'sections. Fewer doors — but it sits "SoCal Looths" in the same control as "Videos", '
        + 'which teaches that a group is a kind of content. <b>I do not recommend it</b> — but here it is.');
    },

    // ---- N4: You sheet → "My groups" (personal → You, per the 6/24 ruling) ----
    async you() {
      const sheet = await openYou();
      if (!sheet) return;
      const rows = $$('.lt-sheet__row', sheet);
      if (!rows.length) { log.push('NO .lt-sheet__row TO CLONE'); return; }
      const clone = rows[0].cloneNode(true);          // clone a real row = real styling
      clone.classList.add('mk-new-tile');
      const ic = clone.querySelector('svg');
      if (ic) ic.innerHTML = ICO.groups;
      const sp = clone.querySelector('span:not(.lt-row-ico)') || clone.querySelector('span');
      if (sp) sp.textContent = 'My groups';
      rows[0].parentNode.insertBefore(clone, rows[0]);

      // The expanded list goes DIRECTLY UNDER the row it expands from — appending it to
      // the end of the sheet (below Settings) pushed it off the fold, which hid the very
      // thing this shot exists to show. Capped so Notifications/Messages still sit below
      // it in frame: their adjacency IS the Q10 argument (§C.1) — "My groups" and a
      // Messages tab that now also contains "groups", one on top of the other.
      const list = document.createElement('div');
      list.style.cssText = 'padding:2px 2px 6px';
      const chaps = MY_CHAPTERS.slice(0, 2);
      const subs  = MY_SUBJECTS.slice(0, 2);
      list.innerHTML = `<div class="mk-pick" style="margin-top:6px">
        <div class="mk-pick__sech">My chapters</div>
        ${chaps.map((c) => `<div class="mk-pick__row">
          <div class="mk-pick__av" style="background:${avc(c.name)}">${ini(c.name)}</div>
          <div class="mk-pick__t"><div class="mk-pick__n">${c.name}</div>
            <div class="mk-pick__s">${c.members} members · ${c.place}</div></div>
          <span class="mk-pick__go">${svg(ICO.chev, 16)}</span></div>`).join('')}
        <div class="mk-pick__sech">My subjects</div>
        ${subs.map((s, i) => `<div class="mk-pick__row">
          <div class="mk-pick__av" style="background:${avc(s.name)}">${ini(s.name)}</div>
          <div class="mk-pick__t"><div class="mk-pick__n">${s.name}</div>
            <div class="mk-pick__s">${i === 1 ? 'hidden from your Hub feed' : 'in your Hub feed'}</div></div>
          <span class="mk-pick__go">${svg(ICO.chev, 16)}</span></div>`).join('')}
      </div>`;
      clone.parentNode.insertBefore(list, clone.nextSibling);
      if (sheet.scrollTop) sheet.scrollTop = 0;
      banner('<b>N4 — "My groups" lives in the You sheet, not the Nav tray.</b> Not my call: '
        + 'bottom-nav.js:50 (Ian/keeper 6/24) — the tray is PLACES ONLY, personal things live in You. '
        + 'A groups <b>directory</b> is a place; <b>my</b> groups is personal. '
        + '<b>Q10 RULED: no rename</b> — so the ROWS do the work. These read as <b>places</b> '
        + '(square avatar · member count · location) sitting right above <b>Messages</b>, whose rows '
        + 'are <b>people</b> (round avatars · last message · unread badge). Same word, unmistakable rows.');
    },

    // ---- N2: the Groups directory (the only page built from scratch) ----
    async dir() {
      const keep = $('#site-header') || $('header');
      document.body.innerHTML = '';
      if (keep) document.body.appendChild(keep);
      const root = document.createElement('div');
      root.className = 'mkd';
      const card = (g, joined, sub) => `
        <div class="mkd__card">
          <div class="mkd__av" style="background:${avc(g.name)}">${ini(g.name)}</div>
          <div style="flex:1 1 auto;min-width:0">
            <div class="mkd__n">${g.name}</div>
            <div class="mkd__m">${sub}</div>
            <button class="mkd__join${joined ? ' on' : ''}">${joined ? 'Joined ✓' : 'Join'}</button>
          </div>
        </div>`;
      root.innerHTML = `
        <h1>Groups</h1>
        <p class="mkd__sub">Local Looth chapters meet in the real world. Subjects are what the Hub is about.
           Everything here is public — joining just adds it to your feed.</p>
        <div class="mkd__sech">Local Looths</div>
        <div class="mkd__grid">
          ${CHAPTERS.map((c, i) => card(c, i === 0, svg(ICO.pin, 12) + c.place + ' · ' + c.members + ' members')).join('')}
        </div>
        <div class="mkd__sech">Subjects</div>
        <div class="mkd__grid">
          ${SUBJECTS.map((s) => card(s, true, s.members.toLocaleString() + ' members · ' + s.leaves + ' forums')).join('')}
          ${card(GENERAL, true, GENERAL.note)}
        </div>`;
      document.body.appendChild(root);
      document.body.style.background = 'var(--bg,#faf8f3)';
      banner('<b>N2 — the Groups directory (/groups/).</b> The "what exists / is there one near me?" door. '
        + 'Also the surface the <b>location suggestion</b> would land on — DEFERRED, it needs the same '
        + 'Cloudflare visitor-location toggle dir-map-geoinit is already blocked on.');
    },

    // ---- P0: THE BASELINE. The real composer, real tree, nothing invented. ----
    async before() {
      await openComposer();
      const list = await openForumTree();          // open the REAL picker, don't fake one
      if (list) {
        // The two composers render the SAME defect differently, so match both:
        //  · mobile  — a flat list, category headers are .ntm-fl__cat
        //  · desktop — an ACCORDION of category rows with leaf counts (no .ntm-fl__cat),
        //              where the duplicate reads literally as HK-052 does: GENERAL 1, GENERAL 3.
        // Fall back to "any leaf element whose whole text is GENERAL" so the ring lands
        // on whichever component we're looking at.
        let cats = $$('.ntm-fl__cat', list);
        if (!cats.length) {
          const scope = $('#ntm-overlay') || document;
          cats = $$('*', scope).filter((el) => !el.children.length
            && /^general$/i.test((el.textContent || '').trim()));
        }
        const generals = cats.filter((c) => /^general$/i.test((c.textContent || '').trim()));
        generals.forEach((c) => {
          const box = c.closest('.ntm-fl__cat') || c.parentElement || c;
          box.style.cssText += ';outline:2px solid #c0392b;outline-offset:1px;border-radius:4px;'
            + 'background:rgba(192,57,43,.10)';
        });
        const last = generals[generals.length - 1];
        if (last && list.scrollHeight > list.clientHeight) {
          list.scrollTop = Math.max(0, last.offsetTop - list.offsetTop - 8);
        }
        log.push('real generals=' + generals.length + ' leaves=' + $$('.ntm-fl__leaf', list).length);
      }
      banner('<b>P0 — TODAY. No mock: this is the REAL composer, with its REAL forum list open.</b> '
        + 'A 55-leaf tree whose headers come from parent_forum_id — chapters are parentless, so they '
        + 'fall under a phantom <b>GENERAL</b>, which appears <b>TWICE</b> (HK-052, ringed red). '
        + 'And "General" is ALSO a real sub-forum of Repair. This is the thing being replaced.');
    },

    // ---- P1: group-first picker, step 1 ----
    async pickGroup() {
      await openComposer();
      await swapPicker(pickerHTML('group'), 'Where does this go?');
      setTrigger('Choose a group', true);
      banner('<b>P1 — step 1: groups, not a forum tree.</b> ~16 real names, flat, no phantom GENERAL. '
        + 'Chapters post in one tap (1 group = 1 forum). "General" is a REAL group now — that is what '
        + 'gives Quick Questions (181 topics) a home.');
    },

    // ---- P2: step 2 — sub-forums SCOPED to the group (the honest bit) ----
    async pickSub() {
      await openComposer();
      await swapPicker(pickerHTML('sub'), 'Which forum in Repair And Restoration?');
      setTrigger('Repair And Restoration', false);
      const lab = $('#ntm-forum-label');
      if (lab) {
        const back = document.createElement('span');
        back.style.cssText = 'display:inline-flex;align-items:center;gap:3px;font-weight:600;font-size:12.5px;'
          + 'color:var(--lg-sage-d,#6b7c52);cursor:pointer;margin-right:8px';
        back.innerHTML = svg(ICO.back, 13) + 'Repair And Restoration';
        lab.parentNode.insertBefore(back, lab);
        lab.innerHTML = '';
      }
      banner('<b>P2 — the honest half of Ian\'s idea.</b> Composing "in the group" does NOT remove the '
        + 'choice for a SUBJECT — Repair really has <b>9</b> forums. It <b>scopes</b> it: 9 flat items '
        + 'instead of a 55-leaf tree. <b>Chapters skip this screen entirely.</b> → Q8.');
    },

    // ---- P3: compose IN CONTEXT from a mini-hub — Ian's idea, realised ----
    async context() {
      await openComposer();
      // Compose-in-context = the REAL control, already filled in. Nothing else.
      // (An earlier cut ALSO injected a "Posting to SoCal Looths" card under the trigger,
      // so the shot said it twice and read like a bug. One statement, in the real widget.)
      const list = $('#ntm-forum');
      const lab  = $('#ntm-forum-label');
      if (list) list.style.display = 'none';        // collapsed: there is nothing to pick
      setTrigger('SoCal Looths', false);
      if (lab) lab.innerHTML = '';
      // §B.5 — the title stops being required, or the chapters stay empty
      const t = $('#ntm-title-in');
      if (t) {
        t.removeAttribute('required');
        t.placeholder = 'Add a title (optional)';
        const tl = $$('label.ntm-label').find((l) => /title/i.test(l.textContent));
        if (tl) tl.innerHTML = 'Title <span class="mk-optional">(optional — leave it blank for a quick note)</span>';
      }
      banner('<b>P3 — Ian\'s idea, realised. "+ New post" ON the mini-hub = zero picking.</b> '
        + 'Costs almost nothing: the composer ALREADY reads page context (_chrome.php data-current-forum). '
        + '<b>Title is now OPTIONAL</b> — §B.5: chapters produced 33 wall-posts vs 4 topics; a required '
        + 'title is the friction that kept them empty. Chats are gone, so discussions must absorb this.');
    },
  };

  const KEY = { chip: 'chip', tray: 'tray', 'tray-merged': 'trayMerged', you: 'you', dir: 'dir',
                before: 'before', 'pick-group': 'pickGroup', 'pick-sub': 'pickSub', context: 'context' };
  const fn = run[KEY[V] || V];
  if (!fn) return 'UNKNOWN VARIANT: ' + V;
  hideChrome();                                  // the PWA install banner is not the subject
  return fn().then(() => 'ok:' + V + (log.length ? ' NOTE=' + log.join(';') : ''))
             .catch((e) => 'ERR:' + V + ' ' + e.message);
})()
