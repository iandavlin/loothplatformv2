/* groups-design lane — CDP-injected mocks.
   Renders on top of the REAL dev2 serve so the site header + design tokens are genuine.
   Nothing here is shipped; this is a design artifact. */
(() => {
  const V = window.__MOCK_VARIANT || 'chapter';

  // ---- real groups, real numbers (from forums.bp_group on dev2) -----------
  const GROUPS = {
    socal: {
      name: 'SoCal Looths', slug: 'socal-looths', kind: 'chapter',
      status: 'private', members: 827, cat: 'looths',
      desc: 'Southern California luthiers — meetups, shop tours, and the annual build-off. Come for the fret levelling, stay for the tacos.',
      place: 'Southern California, USA',
    },
    repair: {
      name: 'Repair And Restoration', slug: 'repair-and-restoration', kind: 'subject',
      status: 'public', members: 1841, cat: 'repair',
      desc: 'Everything that comes into the shop broken. Neck resets, crack fills, finish touch-up, electronics.',
      place: null,
    },
  };
  const G = V === 'subject' ? GROUPS.repair : GROUPS.socal;

  // ---- mock content ------------------------------------------------------
  const CHAPTER_POSTS = [
    { author: 'Dave Ramos', kind: 'DISCUSSION', title: 'April meetup — Fullerton, who\'s in?',
      body: 'Booked the back room at the shop on Commonwealth. Bring a project you\'re stuck on and we\'ll all have a look. Parking is free after 6.',
      when: '2 days ago', replies: 14, react: 9 },
    { author: 'Marisol Vega', kind: 'DISCUSSION', title: 'Anyone got a spare 0.010 feeler gauge?',
      body: 'Snapped mine mid-setup and the shop across town is closed till Monday. Happy to swap for a set of nut files.',
      when: '5 days ago', replies: 6, react: 3 },
    { author: 'Ken Ishikawa', kind: 'PHOTO', title: 'Shop tour: Ken\'s garage in Long Beach',
      body: 'Finally finished the bench. 8ft of hard maple, and the dust collection actually works.',
      when: '1 week ago', replies: 22, react: 31, img: true },
  ];
  const SUBJECT_POSTS = [
    { author: 'Todd Lunneborg', kind: 'DISCUSSION', title: 'Neck reset on a 1963 Gibson — steam or dry?',
      body: 'Dovetail is tight and the finish is original. I\'d rather not steam it if there\'s a cleaner way to break the glue line.',
      when: '4 hours ago', replies: 18, react: 12, sub: 'Acoustic Repair' },
    { author: 'Adan Akerman', kind: 'DISCUSSION', title: 'Best filler for a through-body crack?',
      body: 'Hairline crack running from the bridge to the tail block. CA wicks in nicely but I hate the witness line it leaves.',
      when: 'Yesterday', replies: 9, react: 5, sub: 'Finish Repair' },
    { author: 'KT Vandyke', kind: 'VIDEO', title: 'Refretting a bound fingerboard without chipping',
      body: 'The trick is heat and patience. Full walkthrough, 12 minutes.',
      when: '3 days ago', replies: 27, react: 44, img: true, sub: 'Acoustic Repair' },
  ];
  const POSTS = V === 'subject' ? SUBJECT_POSTS : CHAPTER_POSTS;

  const CHAT = [
    { who: 'Dave Ramos', me: false, t: '09:14', m: 'Morning all — meetup thread is up, Fullerton on the 18th' },
    { who: 'Marisol Vega', me: false, t: '09:22', m: 'I\'m in. Can I bring the parlour guitar with the busted heel?' },
    { who: 'Dave Ramos', me: false, t: '09:23', m: 'That\'s literally the point of the meetup 😄' },
    { who: 'Ken Ishikawa', me: false, t: '10:01', m: 'Anyone driving down from LA got room for one more + a case?' },
    { who: 'You', me: true, t: '10:04', m: 'I\'ve got space, coming through Downey around 4' },
    { who: 'Ken Ishikawa', me: false, t: '10:05', m: 'Perfect, I owe you a coffee' },
  ];

  const initials = n => n.split(' ').map(w => w[0]).slice(0, 2).join('');
  const AV = ['#c66845', '#87986a', '#b8842b', '#6f8fa6', '#8a6f5b', '#586b3f', '#a67c52'];
  const avColor = n => AV[n.charCodeAt(0) % AV.length];

  // Inline SVG — the box has no emoji font, so emoji render as tofu boxes.
  const svg = (d, o) => `<svg viewBox="0 0 24 24" width="${(o && o.s) || 15}" height="${(o && o.s) || 15}"
      fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
      style="flex:0 0 auto;vertical-align:-2px">${d}</svg>`;
  const I = {
    chat:  svg('<path d="M21 11.5a8.4 8.4 0 0 1-9 8.5 9.9 9.9 0 0 1-4.3-1L3 20l1.3-3.8A8.4 8.4 0 0 1 3 11.5 8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/>'),
    lock:  svg('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'),
    globe: svg('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/>'),
    reply: svg('<path d="M21 11.5a8.4 8.4 0 0 1-9 8.5 9.9 9.9 0 0 1-4.3-1L3 20l1.3-3.8A8.4 8.4 0 0 1 3 11.5 8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/>', {s: 14}),
    react: svg('<circle cx="12" cy="12" r="9"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>', {s: 14}),
    star:  svg('<polygon points="12 2 15 9 22 9.3 16.5 13.8 18.5 21 12 17 5.5 21 7.5 13.8 2 9.3 9 9"/>', {s: 14}),
    share: svg('<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.6" y1="10.5" x2="15.4" y2="6.5"/><line x1="8.6" y1="13.5" x2="15.4" y2="17.5"/>', {s: 14}),
    bell:  svg('<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/><line x1="3" y1="3" x2="21" y2="21"/>'),
    info:  svg('<circle cx="12" cy="12" r="9"/><line x1="12" y1="11" x2="12" y2="16"/><line x1="12" y1="8" x2="12.01" y2="8"/>'),
    plus:  svg('<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>'),
    send:  svg('<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>', {s: 16}),
    check: svg('<polyline points="20 6 9 17 4 12"/>', {s: 14}),
    img:   svg('<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>', {s: 34}),
  };

  // ---- styles (real tokens) ---------------------------------------------
  const css = `
  .mk { max-width: 1180px; margin: 0 auto; padding: 22px 20px 80px; font-family: var(--font-body); color: var(--fg); }
  .mk * { box-sizing: border-box; }

  /* group banner */
  .gb { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; margin-bottom: 18px; }
  .gb__cover { height: 132px; background:
      linear-gradient(120deg, var(--cat-${G.cat}) 0%, color-mix(in srgb, var(--cat-${G.cat}) 55%, #1a1d1a) 100%);
      position: relative; }
  .gb__cover::after { content:''; position:absolute; inset:0;
      background-image: radial-gradient(circle at 18% 30%, rgba(255,255,255,.16) 0 2px, transparent 3px),
                        radial-gradient(circle at 72% 66%, rgba(255,255,255,.10) 0 3px, transparent 4px);
      background-size: 90px 90px; }
  .gb__body { padding: 16px 22px 18px; display: flex; gap: 18px; align-items: flex-start; }
  .gb__badge { width: 84px; height: 84px; border-radius: 18px; margin-top: -54px; flex: 0 0 auto;
      position: relative; z-index: 2;
      background: var(--cat-${G.cat}); color: var(--on-${G.cat}); border: 4px solid var(--bg-card);
      display: grid; place-items: center; font-family: var(--font-head); font-weight: 700; font-size: 30px;
      box-shadow: 0 4px 14px rgba(0,0,0,.13); }
  .gb__main { flex: 1 1 auto; min-width: 0; }
  .gb__ttl { font-family: var(--font-head); font-size: 27px; font-weight: 700; margin: 0 0 5px; letter-spacing: -.2px; }
  .gb__meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 9px; }
  .pill { font-size: 11.5px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase;
      padding: 3.5px 9px; border-radius: 999px; }
  .pill--kind { background: var(--cat-${G.cat}); color: var(--on-${G.cat}); }
  .pill--priv { background: var(--bg); color: var(--fg-muted); border: 1px solid var(--border); }
  .gb__mem { font-size: 13.5px; color: var(--fg-muted); }
  .gb__desc { font-size: 14.5px; line-height: 1.55; color: var(--fg-muted); margin: 0 0 12px; max-width: 640px; }
  .gb__acts { display: flex; gap: 9px; align-items: center; flex-wrap: wrap; }

  .btn { font: inherit; font-size: 14px; font-weight: 600; padding: 9px 16px; border-radius: 999px;
      border: 1px solid var(--border); background: var(--bg-card); color: var(--fg); cursor: pointer;
      display: inline-flex; align-items: center; gap: 7px; }
  .btn--pri { background: var(--accent); border-color: var(--accent); color: #fff; }
  .btn--joined { background: var(--lg-sage-tint); border-color: var(--lg-sage-3); color: var(--lg-sage-d); }
  .btn--chat { background: var(--lg-charcoal); border-color: var(--lg-charcoal); color: #fff; }

  .stack { display: flex; align-items: center; }
  .stack .av { width: 30px; height: 30px; border-radius: 50%; border: 2px solid var(--bg-card);
      margin-left: -9px; display: grid; place-items: center; color: #fff; font-size: 11px; font-weight: 700; }
  .stack .av:first-child { margin-left: 0; }
  .stack__more { margin-left: 8px; font-size: 13px; color: var(--fg-muted); }

  /* tabs */
  .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); margin-bottom: 18px; }
  .tab { padding: 10px 15px; font-size: 14.5px; font-weight: 600; color: var(--fg-soft);
      border-bottom: 2.5px solid transparent; margin-bottom: -1px; cursor: pointer; }
  .tab.on { color: var(--fg); border-bottom-color: var(--accent); }
  .tab .n { font-size: 12px; color: var(--fg-soft); font-weight: 600; margin-left: 4px; }

  /* layout */
  .cols { display: grid; grid-template-columns: 1fr 296px; gap: 22px; align-items: start; }
  @media (max-width: 860px) { .cols { grid-template-columns: 1fr; } .side { display: none; } }

  /* cards */
  .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px;
      padding: 16px 18px; margin-bottom: 13px; }
  .card__hd { display: flex; gap: 10px; align-items: center; margin-bottom: 9px; }
  .card__av { width: 36px; height: 36px; border-radius: 50%; display: grid; place-items: center;
      color: #fff; font-weight: 700; font-size: 13px; flex: 0 0 auto; }
  .card__who { font-weight: 700; font-size: 14.5px; }
  .card__when { font-size: 12.5px; color: var(--fg-soft); }
  .chip { font-size: 10.5px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase;
      padding: 3px 8px; border-radius: 999px; background: var(--lg-sage-tint); color: var(--lg-sage-d); }
  .chip--grp { background: var(--cat-${G.cat}); color: var(--on-${G.cat}); }
  .card__ttl { font-family: var(--font-head); font-size: 18.5px; font-weight: 700; margin: 0 0 6px; }
  .card__bd { font-size: 14.5px; line-height: 1.55; color: var(--fg-muted); margin: 0 0 11px; }
  .card__img { height: 168px; border-radius: 10px; margin: 0 0 11px;
      background: linear-gradient(135deg, #d9d3c6, #b9b2a2); position: relative; overflow: hidden; }
  .card__img { display: grid; place-items: center; color: #fff; opacity: .95; }
  .card__img svg { opacity: .45; }
  .card__ft { display: flex; gap: 16px; align-items: center; font-size: 13.5px; color: var(--fg-muted);
      border-top: 1px solid var(--border-soft); padding-top: 10px; }

  /* sidebar */
  .side .box { background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px;
      padding: 15px 16px; margin-bottom: 13px; }
  .box__t { font-size: 12px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase;
      color: var(--fg-soft); margin: 0 0 10px; }
  .krow { display: flex; justify-content: space-between; font-size: 14px; padding: 5px 0; color: var(--fg-muted); }
  .krow b { color: var(--fg); }

  /* subscription control */
  .sub { border: 1px solid var(--border); border-radius: 12px; padding: 13px 14px; background: var(--bg); }
  .sub__row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 7px 0; }
  .sub__lbl { font-size: 13.5px; font-weight: 600; }
  .sub__hint { font-size: 12px; color: var(--fg-soft); margin-top: 1px; line-height: 1.4; }
  .tgl { width: 40px; height: 23px; border-radius: 999px; background: var(--lg-sage-d); position: relative;
      flex: 0 0 auto; cursor: pointer; }
  .tgl.off { background: #cfcabf; }
  .tgl::after { content: ''; position: absolute; top: 2.5px; left: 20px; width: 18px; height: 18px;
      border-radius: 50%; background: #fff; transition: left .15s; }
  .tgl.off::after { left: 2.5px; }

  /* chat room */
  .chat { background: var(--bg-card); border: 1px solid var(--border); border-radius: 16px;
      overflow: hidden; display: flex; flex-direction: column; height: 640px; }
  .chat__hd { display: flex; align-items: center; gap: 11px; padding: 12px 16px;
      border-bottom: 1px solid var(--border); background: var(--bg-card); }
  .chat__badge { width: 42px; height: 42px; border-radius: 11px; background: var(--cat-${G.cat});
      color: var(--on-${G.cat}); display: grid; place-items: center; font-weight: 700;
      font-family: var(--font-head); font-size: 16px; flex: 0 0 auto; }
  .chat__ttl { font-weight: 700; font-size: 15.5px; }
  .chat__sub { font-size: 12.5px; color: var(--fg-soft); }
  .chat__body { flex: 1 1 auto; overflow: hidden; padding: 16px; background: var(--bg);
      display: flex; flex-direction: column; justify-content: flex-end; gap: 11px; }
  .msg { display: flex; gap: 9px; max-width: 74%; }
  .msg.me { align-self: flex-end; flex-direction: row-reverse; }
  .msg__av { width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center;
      color: #fff; font-size: 11px; font-weight: 700; flex: 0 0 auto; }
  .msg.me .msg__av { display: none; }
  .bub { background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px;
      padding: 8px 12px 6px; }
  .msg.me .bub { background: var(--lg-sage-tint); border-color: var(--lg-sage-3); }
  .bub__who { font-size: 12px; font-weight: 700; color: var(--lg-sage-d); margin-bottom: 2px; }
  .msg.me .bub__who { display: none; }
  .bub__m { font-size: 14.5px; line-height: 1.45; }
  .bub__t { font-size: 10.5px; color: var(--fg-soft); text-align: right; margin-top: 2px; }
  .chat__day { align-self: center; font-size: 11.5px; color: var(--fg-soft); background: var(--bg-card);
      border: 1px solid var(--border); padding: 3px 11px; border-radius: 999px; }
  .chat__ft { display: flex; gap: 9px; align-items: center; padding: 11px 14px;
      border-top: 1px solid var(--border); }
  .chat__in { flex: 1 1 auto; border: 1px solid var(--border); border-radius: 999px; padding: 10px 15px;
      font: inherit; font-size: 14.5px; color: var(--fg-soft); background: var(--bg); }
  .chat__send { width: 40px; height: 40px; border-radius: 50%; background: var(--accent); color: #fff;
      display: grid; place-items: center; flex: 0 0 auto; font-size: 16px; }

  /* private teaser */
  .teaser { text-align: center; padding: 46px 24px; background: var(--bg-card);
      border: 1px dashed var(--border); border-radius: 14px; }
  .teaser__lock { font-size: 34px; margin-bottom: 10px; }
  .teaser__t { font-family: var(--font-head); font-size: 20px; font-weight: 700; margin: 0 0 7px; }
  .teaser__b { font-size: 14.5px; color: var(--fg-muted); max-width: 400px; margin: 0 auto 16px; line-height: 1.55; }
  .blur { filter: blur(5px); opacity: .5; pointer-events: none; user-select: none; }

  .note { background: #fff7e6; border: 1px solid #ecd9a8; border-radius: 10px; padding: 10px 13px;
      font-size: 12.5px; color: #6b5320; margin-bottom: 16px; }
  .note b { color: #4a3810; }
  `;

  const avatarStack = (names, more) => `
    <div class="stack">
      ${names.map(n => `<div class="av" style="background:${avColor(n)}">${initials(n)}</div>`).join('')}
      <span class="stack__more">+${more.toLocaleString()} members</span>
    </div>`;

  const postCard = p => `
    <article class="card">
      <div class="card__hd">
        <div class="card__av" style="background:${avColor(p.author)}">${initials(p.author)}</div>
        <div style="flex:1 1 auto;min-width:0">
          <div class="card__who">${p.author}</div>
          <div class="card__when">${p.when}</div>
        </div>
        <span class="chip">${p.kind}</span>
        ${p.sub ? `<span class="chip">${p.sub}</span>` : ''}
      </div>
      <h3 class="card__ttl">${p.title}</h3>
      ${p.img ? `<div class="card__img">${I.img}</div>` : ''}
      <p class="card__bd">${p.body}</p>
      <div class="card__ft">
        <span style="display:inline-flex;gap:5px;align-items:center">${I.reply} ${p.replies} replies</span>
        <span style="display:inline-flex;gap:5px;align-items:center">${I.react} ${p.react}</span>
        <span style="margin-left:auto;display:inline-flex;gap:5px;align-items:center">${I.star} Save</span>
        <span style="display:inline-flex;gap:5px;align-items:center">${I.share} Share</span>
      </div>
    </article>`;

  // ---- variants ----------------------------------------------------------
  const memberNames = V === 'subject'
    ? ['Todd Lunneborg', 'Adan Akerman', 'KT Vandyke', 'Marisol Vega', 'Ken Ishikawa']
    : ['Dave Ramos', 'Marisol Vega', 'Ken Ishikawa', 'Rosa Lim', 'Bo Chen'];

  const joinedBtn = `<button class="btn btn--joined">${I.check} Joined <span style="opacity:.6">▾</span></button>`;
  const joinBtn = `<button class="btn btn--pri">${I.plus} Join group</button>`;
  const reqBtn = `<button class="btn btn--pri">${I.lock} Request to join</button>`;

  const banner = (actions) => `
    <section class="gb">
      <div class="gb__cover"></div>
      <div class="gb__body">
        <div class="gb__badge">${initials(G.name)}</div>
        <div class="gb__main">
          <h1 class="gb__ttl">${G.name}</h1>
          <div class="gb__meta">
            <span class="pill pill--kind">${G.kind === 'chapter' ? 'Local Looth' : 'Subject'}</span>
            <span class="pill pill--priv" style="display:inline-flex;gap:4px;align-items:center">${G.status === 'private' ? I.lock + ' Private' : I.globe + ' Public'}</span>
            <span class="gb__mem">${G.members.toLocaleString()} members${G.place ? ' · ' + G.place : ''}</span>
          </div>
          <p class="gb__desc">${G.desc}</p>
          <div class="gb__acts">${actions}</div>
        </div>
      </div>
    </section>`;

  const tabs = (on) => `
    <nav class="tabs">
      ${['Feed', 'Chat', 'Members', 'About'].map(t => `
        <div class="tab ${t === on ? 'on' : ''}">${t}${t === 'Members' ? `<span class="n">${G.members.toLocaleString()}</span>` : ''}${t === 'Chat' && on !== 'Chat' ? '<span class="n">3</span>' : ''}</div>`).join('')}
    </nav>`;

  const sidebar = (subState) => `
    <aside class="side">
      <div class="box">
        <p class="box__t">Your participation</p>
        <div class="sub">${subState}</div>
      </div>
      <div class="box">
        <p class="box__t">About</p>
        <div class="krow"><span>Members</span><b>${G.members.toLocaleString()}</b></div>
        <div class="krow"><span>Posts</span><b>${V === 'subject' ? '1,167' : '3'}</b></div>
        <div class="krow"><span>Created</span><b>${V === 'subject' ? 'Mar 2021' : 'Sep 2023'}</b></div>
        <div class="krow"><span>Type</span><b>${G.status === 'private' ? 'Private' : 'Public'}</b></div>
      </div>
      <div class="box">
        <p class="box__t">Members</p>
        ${avatarStack(memberNames, G.members - memberNames.length)}
      </div>
    </aside>`;

  // subscription control states
  const subIn = `
    <div class="sub__row">
      <div><div class="sub__lbl">Show in my Hub</div>
        <div class="sub__hint">${G.name} posts appear in your main Hub feed.</div></div>
      <div class="tgl"></div>
    </div>
    <div class="sub__row" style="border-top:1px solid var(--border);">
      <div><div class="sub__lbl">Notify me</div>
        <div class="sub__hint">New posts &amp; chat activity.</div></div>
      <div class="tgl off"></div>
    </div>`;
  const subOut = `
    <div class="sub__row">
      <div><div class="sub__lbl">Show in my Hub</div>
        <div class="sub__hint">Hidden — ${G.name} posts are <b>filtered out</b> of your main Hub feed.</div></div>
      <div class="tgl off"></div>
    </div>
    <div class="sub__row" style="border-top:1px solid var(--border);">
      <div><div class="sub__lbl">Notify me</div>
        <div class="sub__hint">Off.</div></div>
      <div class="tgl off"></div>
    </div>`;

  let body = '';

  if (V === 'chapter' || V === 'subject') {
    body = `
      ${banner(`${joinedBtn}<button class="btn btn--chat">${I.chat} Group chat<span style="background:var(--lg-rust);color:#fff;border-radius:999px;padding:1px 6px;font-size:11px;">3</span></button><button class="btn">Invite</button>`)}
      ${tabs('Feed')}
      <div class="cols">
        <main>
          <div class="card" style="display:flex;gap:10px;align-items:center;">
            <div class="card__av" style="background:var(--accent)">C</div>
            <div style="flex:1;color:var(--fg-soft);font-size:14.5px;">Post to ${G.name}…</div>
            <button class="btn btn--pri">New post</button>
          </div>
          ${POSTS.map(postCard).join('')}
        </main>
        ${sidebar(subIn)}
      </div>`;
  }

  if (V === 'optout') {
    body = `
      ${banner(`${joinedBtn}<button class="btn btn--chat">${I.chat} Group chat</button><button class="btn">Invite</button>`)}
      ${tabs('Feed')}
      <div class="cols">
        <main>
          <div class="note"><b>Opt-OUT state.</b> Still a member, but this subject is muted from the main Hub feed —
            content is still reachable here in the mini-hub.</div>
          ${POSTS.map(postCard).join('')}
        </main>
        ${sidebar(subOut)}
      </div>`;
  }

  if (V === 'private') {
    body = `
      ${banner(reqBtn)}
      ${tabs('Feed')}
      <div class="cols">
        <main>
          <div class="teaser">
            <div class="teaser__lock">${svg('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>', {s: 38})}</div>
            <h3 class="teaser__t">This chapter is private</h3>
            <p class="teaser__b">You can see that <b>${G.name}</b> exists and who runs it — but its posts,
              members and chat are visible to members only. Ask to join and an organiser will let you in.</p>
            <button class="btn btn--pri">${I.lock} Request to join</button>
          </div>
          <div class="blur" style="margin-top:13px">${POSTS.map(postCard).join('')}</div>
        </main>
        <aside class="side">
          <div class="box">
            <p class="box__t">About</p>
            <div class="krow"><span>Members</span><b>${G.members.toLocaleString()}</b></div>
            <div class="krow"><span>Type</span><b>Private</b></div>
            <div class="krow"><span>Organisers</span><b>2</b></div>
          </div>
          <div class="box">
            <p class="box__t">Organisers</p>
            ${avatarStack(['Dave Ramos', 'Rosa Lim'], 0).replace('+0 members', 'Contact to join')}
          </div>
        </aside>
      </div>`;
  }

  if (V === 'chat') {
    body = `
      <div class="cols">
        <main>
          <section class="chat">
            <div class="chat__hd">
              <div class="chat__badge">${initials(G.name)}</div>
              <div style="flex:1 1 auto;min-width:0">
                <div class="chat__ttl">${G.name}</div>
                <div class="chat__sub">${G.members.toLocaleString()} members · 12 online</div>
              </div>
              ${avatarStack(memberNames.slice(0, 4), G.members - 4).replace(/\+[\d,]+ members/, '')}
              <button class="btn">${I.bell} Mute</button>
              <button class="btn" style="padding:9px 11px">${I.info}</button>
            </div>
            <div class="chat__body">
              <div class="chat__day">Today</div>
              ${CHAT.map(c => `
                <div class="msg ${c.me ? 'me' : ''}">
                  <div class="msg__av" style="background:${avColor(c.who)}">${initials(c.who)}</div>
                  <div class="bub">
                    <div class="bub__who">${c.who}</div>
                    <div class="bub__m">${c.m}</div>
                    <div class="bub__t">${c.t}</div>
                  </div>
                </div>`).join('')}
            </div>
            <div class="chat__ft">
              <button class="btn" style="width:40px;height:40px;padding:0;justify-content:center;border-radius:50%">${I.plus}</button>
              <div class="chat__in">Message ${G.name}…</div>
              <div class="chat__send">${I.send}</div>
            </div>
          </section>
        </main>
        <aside class="side">
          <div class="box">
            <p class="box__t">Room settings</p>
            <div class="sub">
              <div class="sub__row">
                <div><div class="sub__lbl">Notifications</div>
                  <div class="sub__hint">Off by default in big rooms. You'll still see a badge.</div></div>
                <div class="tgl off"></div>
              </div>
              <div class="sub__row" style="border-top:1px solid var(--border);">
                <div><div class="sub__lbl">Mentions only</div>
                  <div class="sub__hint">Notify when someone @s you.</div></div>
                <div class="tgl"></div>
              </div>
            </div>
          </div>
          <div class="box">
            <p class="box__t">In this room</p>
            ${avatarStack(memberNames, G.members - memberNames.length)}
          </div>
        </aside>
      </div>`;
  }

  // ---- mount: keep the REAL header, replace the rest ----------------------
  const style = document.createElement('style');
  style.textContent = css + `
    /* the cloned header can carry an expanded submenu; force it shut */
    body > header .sub-menu, body > header ul ul, body > header [class*="dropdown"],
    body > header [class*="submenu"], body > header [class*="mega"],
    body > header [aria-expanded="true"] + *, body > header .lg-nav__panel { display: none !important; }
    body > header { overflow: visible; position: relative; z-index: 5; }
  `;
  document.head.appendChild(style);

  const header = document.querySelector('.lg-shell-header, header, .lg-header, .site-header');
  const keep = header ? header.cloneNode(true) : null;
  if (keep) {
    keep.querySelectorAll('[aria-expanded]').forEach(e => e.setAttribute('aria-expanded', 'false'));
    keep.querySelectorAll('.sub-menu, ul ul, [class*="dropdown"], [class*="submenu"]').forEach(e => e.remove());
    keep.querySelectorAll('[class*="open"], [class*="active"]').forEach(e => {
      e.className = e.className.replace(/\b\S*(open|active)\S*\b/g, '');
    });
  }
  document.body.innerHTML = '';
  if (keep) document.body.appendChild(keep);
  const root = document.createElement('div');
  root.className = 'mk';
  root.innerHTML = body;
  document.body.appendChild(root);
  document.body.style.background = 'var(--bg)';
  return 'mounted:' + V;
})();
