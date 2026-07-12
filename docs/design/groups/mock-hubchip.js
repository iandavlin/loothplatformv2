/* B5 — how the MAIN hub indicates a post came from a group.
   This one does NOT replace the page: it decorates the REAL hub feed in place,
   so what you see is the live hub with the proposed group chip added. */
(() => {
  const CAT = { // group slug -> the real --cat-* token already in the stylesheet
    'repair-and-restoration': 'repair', 'new-builds': 'builds',
    'tools-spaces-robots-and-widgets': 'tools', 'business': 'business',
    'market-place': 'market', 'socal-looths': 'looths', 'dmv-looths': 'looths',
  };
  // Stand-in mapping: in the real build this comes from forum.effective_group_id.
  const GROUPS = [
    { name: 'Repair And Restoration', slug: 'repair-and-restoration', kind: 'subject' },
    { name: 'New Builds', slug: 'new-builds', kind: 'subject' },
    { name: 'SoCal Looths', slug: 'socal-looths', kind: 'chapter' },
    { name: 'Tools, Spaces & Robots', slug: 'tools-spaces-robots-and-widgets', kind: 'subject' },
    { name: 'Market Place', slug: 'market-place', kind: 'subject' },
    { name: 'DMV Looths', slug: 'dmv-looths', kind: 'chapter' },
  ];

  const css = `
  .gchip { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700;
      letter-spacing: .4px; text-transform: uppercase; padding: 3px 9px 3px 4px; border-radius: 999px;
      background: var(--bg); border: 1px solid var(--border); color: var(--fg-muted);
      text-decoration: none; vertical-align: middle; margin-left: 6px; }
  .gchip__dot { width: 15px; height: 15px; border-radius: 5px; display: grid; place-items: center;
      font-size: 8px; font-weight: 800; color: #fff; letter-spacing: 0; }
  .gchip--chapter { background: var(--lg-sage-tint); border-color: var(--lg-sage-3); color: var(--lg-sage-d); }
  .gchip__pin { width: 11px; height: 11px; }
  .mk-ann { position: fixed; left: 50%; transform: translateX(-50%); bottom: 18px; z-index: 9999;
      background: var(--lg-charcoal); color: #fff; font: 600 13px var(--font-body);
      padding: 10px 18px; border-radius: 999px; box-shadow: 0 6px 22px rgba(0,0,0,.3); }
  .mk-ann b { color: var(--lg-amber); }
  `;
  const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  const pin = `<svg class="gchip__pin" viewBox="0 0 24 24" fill="none" stroke="currentColor"
      stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>`;

  const chip = g => {
    const c = CAT[g.slug] || 'general';
    if (g.kind === 'chapter') {
      return `<a class="gchip gchip--chapter" href="/g/${g.slug}">${pin}${g.name}</a>`;
    }
    return `<a class="gchip" href="/g/${g.slug}">
        <span class="gchip__dot" style="background:var(--cat-${c})">${g.name[0]}</span>${g.name}</a>`;
  };

  // Decorate every real card: put the group chip next to the existing kind badge.
  const cards = document.querySelectorAll('.feed-card');
  let n = 0;
  cards.forEach((card, i) => {
    const badges = card.querySelector('.fc-author__badges') || card.querySelector('.feed-card__kind-badge');
    if (!badges) return;
    const host = badges.classList.contains('fc-author__badges') ? badges : badges.parentNode;
    const g = GROUPS[i % GROUPS.length];
    host.insertAdjacentHTML('beforeend', chip(g));
    // the old free-text category chip is what the group chip REPLACES
    const old = card.querySelector('.fc-cat-chip');
    if (old) { old.style.outline = '2px dashed #c66845'; old.style.opacity = '.45'; }
    const cat = card.querySelector('.fc-category');
    if (cat) cat.remove();
    n++;
  });

  const ann = document.createElement('div');
  ann.className = 'mk-ann';
  ann.innerHTML = `Proposed: <b>group chip</b> on every card (links to /g/&lt;slug&gt;) &nbsp;·&nbsp; dashed = the free-text category chip it replaces`;
  document.body.appendChild(ann);
  return 'decorated:' + n;
})();
