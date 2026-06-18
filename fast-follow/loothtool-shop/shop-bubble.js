/* Looth shop — the Loothtool pop-up modal. NO floating FAB (Ian + Buck
 * 2026-06-11: no corner Shop button on any viewport, ever).
 *
 * MOBILE (≤640): bottom-nav's Shop tab → the /shop/ page; the header
 * "Loothtool" link redirects there too. Zero further cost on mobile.
 * DESKTOP (≥641, Buck 2026-06-11): the header "Loothtool" tab opens a
 * centered POP-UP MODAL — pre-built + feed-warmed at idle so it opens
 * instantly ("like an article") — skinned
 * like loothtool.com's CURRENT theme (loothtool-wordpress-theme tokens): hero
 * gradient #021C1E→#004445, teal accent #2C7873 (--lt-red), mint #6FB98F
 * (--lt-gold), #f1f1f1 sections + white cards, Barlow Condensed headings +
 * Inter body. Every product / CTA opens loothtool.com in a new tab. Data: the
 * same same-origin
 * /shop-feed.json + /shop-vendors.json the /shop/ page uses.
 * Loaded site-wide via /pwa.js. The modal is brand-fixed (it's a mini-storefront)
 * and intentionally does NOT follow the app dark theme.
 */
(function () {
  'use strict';
  if (window.__loothShop) return;
  window.__loothShop = true;

  var FEED_URL = '/shop-feed.json';
  var VENDORS_URL = '/shop-vendors.json';
  var SHOP_HOME = 'https://loothtool.com/shop';
  var CACHE_KEY = 'looth_shop_feed_v1';
  // The makers row mirrors loothtool.com's home shops carousel (Buck 2026-06-11):
  // VL Guitar Repair in the middle, FretGuru left out until he lists products.
  // Makers appear even with no products in the feed (tile links to their store);
  // any NEW feed vendor not listed here is appended at the end automatically.
  var MAKERS_LINEUP = ['String Pluckery', 'Artisan Toolcraft', 'Whittlesticks',
    'VL Guitar Repair', 'Looth Prints', 'Austin Ribbon Microphones'];

  var items = [];
  var vendorLogos = {};
  var debounce = null;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // Same price decoder the /shop/ page uses (sale / range / bulk variants).
  function parsePrice(raw) {
    raw = String(raw || ''); var o = { now: '', was: '', bulk: '', pct: 0 };
    var bulk = /Buy\s+\d+\s+to get\s+[\d.]+%\s+discount/i.exec(raw);
    if (bulk) o.bulk = bulk[0].replace(/\s+/g, ' ').replace('to get', '→').replace(' discount', ' off');
    var sale = /Original price was:\s*\$?([\d.,]+)\.?\s*\$?([\d.,]+)\s*Current price is:\s*\$?([\d.,]+)/i.exec(raw);
    if (sale) { o.was = '$' + sale[1]; o.now = '$' + sale[3]; var w = parseFloat(sale[1]), n = parseFloat(sale[3]); if (w > 0 && n > 0) o.pct = Math.round((w - n) / w * 100); return o; }
    var range = /\$([\d.,]+)\s*[–-]\s*\$([\d.,]+)/.exec(raw);
    if (range) { o.now = '$' + range[1] + '–$' + range[2]; return o; }
    var one = /\$([\d.,]+)/.exec(raw); o.now = one ? '$' + one[1] : ''; return o;
  }
  function initials(v) { return (v || '?').split(/\s+/).map(function (w) { return w[0] || ''; }).join('').slice(0, 2).toUpperCase(); }
  function mkShort(v) { return (v || '').replace(/,? (Inc|Co)\.?.*$/, '').replace(' Guitar Repair', ''); }

  function injectStyles() {
    if (document.getElementById('looth-shop-style')) return;
    // Barlow Condensed + Inter are loothtool.com's typefaces — load them as
    // webfonts (house rule: never rely on a system font that might not exist
    // on the device).
    if (!document.getElementById('looth-shop-font')) {
      var l = document.createElement('link');
      l.id = 'looth-shop-font'; l.rel = 'stylesheet';
      l.href = 'https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Inter:wght@400;500;600;700;800&display=swap';
      (document.head || document.documentElement).appendChild(l);
    }
    var css =
      /* ── Loothtool pop-up modal (desktop) — brand-fixed: teal/mint/Barlow+Inter ── */
      '#looth-shop-ov{position:fixed;inset:0;overflow:hidden;z-index:2147482100;visibility:hidden;opacity:0;' +
      'transition:opacity .22s ease,visibility 0s linear .22s;' +
      "font:15px/1.5 'Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif}" +
      '#looth-shop-ov.is-open{visibility:visible;opacity:1;transition:opacity .22s ease}' +
      '#looth-shop-ov .lt-back{position:absolute;inset:0;background:rgba(2,28,30,.62);cursor:pointer}' +
      '#looth-shop-ov .lt-modal{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%) scale(.97);' +
      'width:min(1060px,94vw);height:min(860px,90vh);display:flex;flex-direction:column;overflow:hidden;' +
      'background:#f1f1f1;border-radius:18px;box-shadow:0 30px 80px rgba(0,0,0,.5);transition:transform .22s ease}' +
      '#looth-shop-ov.is-open .lt-modal{transform:translate(-50%,-50%) scale(1)}' +
      /* head: the site's hero gradient, teal rule */
      '#looth-shop-ov .lt-head{flex:0 0 auto;display:flex;align-items:center;gap:14px;padding:12px 22px;' +
      'background:linear-gradient(135deg,#021C1E 0%,#004445 100%);border-bottom:3px solid #2C7873}' +
      // the official Loothtool wordmark (Buck 2026-06-11) — wide amber art with its
      // own built-in tagline, so no text mark next to it
      '#looth-shop-ov .lt-wordmark{height:48px;width:auto;flex:0 0 auto;display:block}' +
      '#looth-shop-ov .lt-sp{margin-left:auto}' +
      /* search: white pill on the teal bar — mirrors the site's hero search */
      '#looth-shop-ov .lt-searchwrap{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid #e6e6e6;' +
      'border-radius:999px;padding:8px 14px;min-width:240px}' +
      '#looth-shop-ov .lt-searchwrap svg{width:16px;height:16px;color:#595959;flex:0 0 auto}' +
      // !important: the app dark pass (app-settings.js) forces background:#222629
      // on ALL inputs — this modal is brand-fixed, so the search must stay a white
      // pill in dark mode too (the #id selector outguns the dark pass's)
      "#looth-shop-ov .lt-search{flex:1 1 auto;min-width:0;border:0!important;background:transparent!important;outline:none;box-shadow:none!important;color:#000!important;caret-color:#000;font:15px/1.2 'Inter',sans-serif}" +
      '#looth-shop-ov .lt-search::placeholder{color:#8a8a8a!important}' +
      '#looth-shop-ov .lt-searchwrap:focus-within{border-color:#2C7873}' +
      '#looth-shop-ov .lt-x{flex:0 0 auto;width:36px;height:36px;border:0;border-radius:50%;background:#2C7873;color:#fff;' +
      'font-size:20px;line-height:36px;text-align:center;cursor:pointer}' +
      '#looth-shop-ov .lt-x:hover{filter:brightness(1.15)}' +
      /* body */
      '#looth-shop-ov .lt-body{flex:1 1 auto;overflow-y:auto;padding:18px 22px 26px;background:#f1f1f1}' +
      "#looth-shop-ov .lt-status{text-align:center;color:#595959;padding:48px 12px;font:500 15px/1.5 'Inter',sans-serif}" +
      /* hero: the site's teal gradient, mint accents, teal CTA */
      '#looth-shop-ov .lt-hero{background:linear-gradient(135deg,#021C1E 0%,#004445 100%);border-radius:14px;padding:22px 24px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}' +
      "#looth-shop-ov .lt-hero h2{margin:0;font:700 28px/1.12 'Barlow Condensed',sans-serif;text-transform:uppercase;letter-spacing:.04em;color:#fff}" +
      '#looth-shop-ov .lt-hero h2 em{color:#6FB98F;font-style:normal}' +
      "#looth-shop-ov .lt-hero p{margin:4px 0 0;font:400 13.5px/1.5 'Inter',sans-serif;color:#aec8c0;max-width:520px}" +
      "#looth-shop-ov .lt-cta{margin-left:auto;flex:0 0 auto;display:inline-flex;align-items:center;gap:8px;background:#2C7873;color:#fff!important;" +
      "border-radius:999px;padding:12px 20px;font:700 14px/1 'Inter',sans-serif;text-decoration:none;white-space:nowrap}" +
      '#looth-shop-ov .lt-cta:hover{filter:brightness(1.18)}' +
      /* makers strip — centered like the loothtool.com shops row ("safe" falls
         back to flex-start when it overflows, so nothing gets clipped) */
      "#looth-shop-ov .lt-sect{font:700 19px/1.2 'Barlow Condensed',sans-serif;text-transform:uppercase;letter-spacing:.04em;color:#000;margin:22px 2px 10px}" +
      '#looth-shop-ov .lt-makers{display:flex;gap:16px;overflow-x:auto;padding:2px 2px 8px;scrollbar-width:none;justify-content:safe center}' +
      '#looth-shop-ov .lt-makers::-webkit-scrollbar{display:none}' +
      '#looth-shop-ov .lt-maker{flex:0 0 auto;width:78px;display:flex;flex-direction:column;align-items:center;gap:7px;text-decoration:none}' +
      '#looth-shop-ov .lt-maker__av{width:58px;height:58px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;' +
      "background:#2C7873;color:#fff;font:800 17px/1 'Inter',sans-serif;border:2px solid transparent;transition:border-color .15s ease}" +
      '#looth-shop-ov .lt-maker:hover .lt-maker__av{border-color:#2C7873}' +
      '#looth-shop-ov .lt-maker__av img{width:100%;height:100%;object-fit:cover;display:block}' +
      "#looth-shop-ov .lt-maker__n{font:600 11px/1.2 'Inter',sans-serif;color:#1a1a1a;text-align:center}" +
      /* product grid: white cards, teal hover, Inter type */
      '#looth-shop-ov .lt-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}' +
      '@media (max-width:900px){#looth-shop-ov .lt-grid{grid-template-columns:repeat(3,1fr)}}' +
      '#looth-shop-ov .lt-card{position:relative;display:flex;flex-direction:column;background:#fff;border:1px solid #e6e6e6;' +
      'border-radius:12px;overflow:hidden;text-decoration:none;color:inherit;transition:box-shadow .15s ease,transform .15s ease,border-color .15s ease}' +
      '#looth-shop-ov .lt-card:hover{box-shadow:0 10px 26px rgba(0,0,0,.14);transform:translateY(-3px);border-color:#2C7873}' +
      '#looth-shop-ov .lt-card img{display:block;width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;background:#f1f1f1}' +
      "#looth-shop-ov .lt-badge{position:absolute;left:9px;top:9px;background:#2C7873;color:#fff;font:700 11px/1 'Barlow Condensed',sans-serif;" +
      'text-transform:uppercase;letter-spacing:.05em;border-radius:999px;padding:5px 9px}' +
      '#looth-shop-ov .lt-info{padding:10px 12px 12px;display:flex;flex-direction:column;gap:3px;flex:1 1 auto}' +
      "#looth-shop-ov .lt-name{font:600 13.5px/1.25 'Inter',sans-serif;color:#1a1a1a;" +
      'display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}' +
      "#looth-shop-ov .lt-vendor{font:500 11px/1.2 'Inter',sans-serif;color:#595959}" +
      "#looth-shop-ov .lt-price{margin-top:auto;padding-top:5px;font:700 14.5px/1 'Inter',sans-serif;color:#000}" +
      "#looth-shop-ov .lt-was{margin-left:7px;font:500 12px/1 'Inter',sans-serif;color:#a42325;text-decoration:line-through}" +
      "#looth-shop-ov .lt-bulk{font:600 10.5px/1.3 'Inter',sans-serif;color:#2C7873}" +
      'body.looth-shop-lock{overflow:hidden}';
    var s = document.createElement('style');
    s.id = 'looth-shop-style';
    s.textContent = css;
    (document.head || document.documentElement).appendChild(s);
  }

  var ov, bodyEl, searchEl;

  // Build the modal overlay (NO FAB — Ian + Buck 2026-06-11: no floating Shop
  // button on any viewport, ever; the header "Loothtool" tab is the desktop
  // entry and the bottom-bar Shop tab is mobile's). Idempotent; called at
  // desktop idle so the tab click opens the modal instantly, and again from
  // the click interceptor as a fallback if idle never fired.
  function ensureUI() {
    if (ov) return;
    injectStyles();

    ov = document.createElement('div');
    ov.id = 'looth-shop-ov';
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.setAttribute('aria-label', 'Loothtool');
    ov.innerHTML =
      '<div class="lt-back" data-close></div>' +
      '<div class="lt-modal">' +
        '<div class="lt-head">' +
          '<img class="lt-wordmark" src="/shop-img/loothtool-logo.png?v=4" alt="Loothtool — luthier tools marketplace">' +
          '<span class="lt-sp"></span>' +
          '<label class="lt-searchwrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
            '<input class="lt-search" type="search" placeholder="Search tools, makers…" autocomplete="off" aria-label="Search listings"></label>' +
          '<button class="lt-x" type="button" data-close aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="lt-body"><div class="lt-status">Loading…</div></div>' +
      '</div>';

    (document.body || document.documentElement).appendChild(ov);

    bodyEl = ov.querySelector('.lt-body');
    searchEl = ov.querySelector('.lt-search');

    ov.addEventListener('click', function (e) {
      if (e.target.closest('[data-close]')) close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && ov.classList.contains('is-open')) close();
    });
    searchEl.addEventListener('input', function () {
      clearTimeout(debounce);
      var q = this.value.trim().toLowerCase();
      debounce = setTimeout(function () { render(q); }, 160);
    });
  }

  function buildUI() {
    // The header nav's "Loothtool" item is a bare link to loothtool.com that
    // navigated the app away in the SAME tab (Buck 2026-06-11: "pop up is not
    // working" = he was using this link). Desktop → open the modal instead;
    // mobile → the /shop/ page. The interceptor is the ONLY thing registered
    // at boot; mobile pays zero further cost (no styles, fonts, DOM, or feed).
    document.addEventListener('click', function (e) {
      if (!e.target.closest) return;
      var a = e.target.closest('a[href^="https://loothtool.com"], a[href^="http://loothtool.com"]');
      if (!a) return;
      if (ov && a.closest('#looth-shop-ov')) return;           // modal's own links: new tab, untouched
      var href = a.getAttribute('href') || '';
      // only the bare site link (the nav item) — deep links (products etc.) keep working
      if (!/^https?:\/\/loothtool\.com\/?$/.test(href)) return;
      e.preventDefault(); e.stopPropagation();
      if (window.matchMedia('(max-width:640px)').matches) {
        try { window.location.assign('/shop/'); } catch (e2) { window.location.href = '/shop/'; }
      } else {
        ensureUI();
        open();
      }
    }, true);
    // Desktop: pre-build the modal + warm the feed at idle so the tab click is
    // instant (Buck 2026-06-11: "load like an article — super fast and snappy").
    if (!window.matchMedia('(max-width:640px)').matches) {
      var pre = function () { ensureUI(); warm(); };
      if ('requestIdleCallback' in window) requestIdleCallback(pre, { timeout: 2000 });
      else setTimeout(pre, 600);
    }
  }

  function open() {
    ov.classList.add('is-open');
    document.body.classList.add('looth-shop-lock');
    if (items.length) render('');
    else bodyEl.innerHTML = '<div class="lt-status">Loading…</div>';
    fetchFeed().catch(function () {
      if (!items.length) bodyEl.innerHTML = '<div class="lt-status">Couldn’t load listings right now.</div>';
    });
  }
  function close() {
    ov.classList.remove('is-open');
    document.body.classList.remove('looth-shop-lock');
    if (searchEl) searchEl.value = '';
  }

  function readCache() {
    try {
      var raw = localStorage.getItem(CACHE_KEY);
      if (raw) { var d = JSON.parse(raw); if (Array.isArray(d)) return d; }
    } catch (e) {}
    return null;
  }
  function writeCache(d) {
    try { localStorage.setItem(CACHE_KEY, JSON.stringify(d)); } catch (e) {}
  }

  function fetchFeed() {
    return fetch(FEED_URL, { credentials: 'same-origin', cache: 'no-cache' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data) return;
        var arr = Array.isArray(data) ? data : (data && data.items) || [];
        items = arr;
        writeCache(arr);
        if (ov && ov.classList.contains('is-open')) {
          render(searchEl ? searchEl.value.trim().toLowerCase() : '');
        }
      });
  }
  function fetchVendors() {
    return fetch(VENDORS_URL, { credentials: 'same-origin', cache: 'no-cache' })
      .then(function (r) { return r.ok ? r.json() : {}; })
      .then(function (j) {
        vendorLogos = j || {};
        // product-less lineup makers (e.g. Artisan Toolcraft) only become known
        // once this loads — refresh an already-open modal so they appear
        if (ov && ov.classList.contains('is-open')) {
          render(searchEl ? searchEl.value.trim().toLowerCase() : '');
        }
      })
      .catch(function () {});
  }

  function warm() {
    var cached = readCache();
    if (cached && cached.length) items = cached;
    var go = function () { fetchFeed().catch(function () {}); fetchVendors(); };
    if ('requestIdleCallback' in window) {
      requestIdleCallback(go, { timeout: 2500 });
    } else {
      setTimeout(go, 800);
    }
  }

  function cardHTML(p) {
    var pr = parsePrice(p.price);
    return '<a class="lt-card" href="' + esc(p.url || SHOP_HOME) + '" target="_blank" rel="noopener">' +
      // no loading="lazy": Chrome never fires native lazy-load inside this
      // fixed overlay (images sat complete:false forever) — grid is ~26 small webps
      '<img src="' + esc(p.image || '') + '" alt="" decoding="async" width="300" height="300">' +
      (pr.pct ? '<span class="lt-badge">-' + pr.pct + '%</span>' : '') +
      '<div class="lt-info">' +
        '<div class="lt-name">' + esc(p.name) + '</div>' +
        (p.vendor ? '<div class="lt-vendor">' + esc(mkShort(p.vendor)) + '</div>' : '') +
        '<div class="lt-price">' + esc(pr.now || p.price || '') + (pr.was ? '<span class="lt-was">' + esc(pr.was) + '</span>' : '') + '</div>' +
        (pr.bulk ? '<div class="lt-bulk">' + esc(pr.bulk) + '</div>' : '') +
      '</div></a>';
  }

  function render(q) {
    var list = items;
    if (q) {
      list = items.filter(function (p) {
        return (p.name || '').toLowerCase().indexOf(q) > -1 ||
               (p.vendor || '').toLowerCase().indexOf(q) > -1;
      });
    }
    if (!list.length) {
      bodyEl.innerHTML = '<div class="lt-status">' +
        (q ? 'No matches for “' + esc(q) + '”.' : 'Marketplace listings are coming soon.') +
        '</div>';
      return;
    }
    var html = '';
    if (!q) {
      html += '<div class="lt-hero"><div><h2>Tools by luthiers, <em>for luthiers.</em></h2>' +
        '<p>Jigs, fixtures and shop-tested gear from the makers in our community — the stuff you can’t buy at the big-box store.</p></div>' +
        '<a class="lt-cta" href="' + esc(SHOP_HOME) + '" target="_blank" rel="noopener">Browse the full shop ↗</a></div>';
      // makers strip (same logos as the mobile shop page): the curated lineup
      // first (kept even with no feed products, as long as we know their store),
      // then any feed vendor the lineup doesn't cover
      var seen = {}, makers = [], inFeed = {};
      items.forEach(function (p) { if (p.vendor) inFeed[p.vendor] = 1; });
      MAKERS_LINEUP.forEach(function (v) { if (vendorLogos[v] || inFeed[v]) { seen[v] = 1; makers.push(v); } });
      items.forEach(function (p) { if (p.vendor && !seen[p.vendor]) { seen[p.vendor] = 1; makers.push(p.vendor); } });
      if (makers.length) {
        html += '<div class="lt-sect">Shop the makers</div><div class="lt-makers">' + makers.map(function (v) {
          // shop-vendors.json entries are {logo,url} (v1 was a plain logo string);
          // a maker tile links to THAT maker's store page, not the general shop.
          var rec = vendorLogos[v] || {};
          if (typeof rec === 'string') rec = { logo: rec };
          var av = rec.logo
            ? '<span class="lt-maker__av"><img src="' + esc(rec.logo) + '" alt=""></span>'
            : '<span class="lt-maker__av">' + esc(initials(v)) + '</span>';
          // full store name on the tile (loothtool.com shows "VL Guitar Repair",
          // not the "VL" shorthand the product cards use)
          return '<a class="lt-maker" href="' + esc(rec.url || SHOP_HOME) + '" target="_blank" rel="noopener">' + av +
            '<span class="lt-maker__n">' + esc(v) + '</span></a>';
        }).join('') + '</div>';
      }
      html += '<div class="lt-sect">All gear</div>';
    }
    html += '<div class="lt-grid">' + list.map(cardHTML).join('') + '</div>';
    bodyEl.innerHTML = html;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', buildUI);
  } else {
    buildUI();
  }
  // warm() now runs desktop-only at idle (inside buildUI) — mobile ships zero
  // shop cost beyond the click interceptor.
})();
