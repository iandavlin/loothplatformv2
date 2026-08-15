/* contrast-probe.js — measure WCAG contrast of everything a person can actually
   READ or TYPE INTO on the current page. Injected via Runtime.evaluate; returns
   a plain JSON object.

   SHARED ON PURPOSE. The dark-anon sweep (tools/preview/dark-anon-sweep.py) and
   the anon-dark contrast gate both inject THIS file. If the gate measured with
   its own copy, the gate could go green on a surface the sweep had ranked worst
   and nobody would be able to tell which number was lying.

   WHAT IT REFUSES TO DO — every one of these is a false-PASS this box has paid
   for before:

   · It never assumes a background. An element whose ancestors are all
     transparent down to a background-IMAGE or a gradient has NO single
     measurable backdrop, so it is reported as `unmeasurable` and counted
     separately. Guessing white there is how a dark-mode defect reads as a pass.
   · It never trusts presence. display:none, visibility:hidden, opacity 0,
     zero-area rects and text clipped to 0 (the icon-font trick) are all
     excluded — PRESENCE IS NOT REACHABILITY, and unreadable-because-hidden is a
     different bug from unreadable-because-grey.
   · It measures the element's OWN text, never its descendants'. Otherwise a
     wrapper <div> inherits the colour of the one link inside it and the same
     defect is reported five times up the tree.
   · It composites alpha properly. rgba(255,255,255,.06) over #15171a is a very
     common "subtle" dark card and its real luminance is nothing like white.

   THRESHOLDS — WCAG 2.1:
     normal text        4.5:1   (AA)
     large text         3.0:1   (AA; >=24px, or >=18.66px when bold)
     UI component       3.0:1   (AA 1.4.11 — form-field borders, focus rings)
   Placeholders are held to the NORMAL-TEXT bar deliberately: on a sign-up form
   the placeholder often IS the instruction, which is the exact mess this sweep
   was chartered for. */

(function () {
  'use strict';

  function parseColor(str) {
    if (!str) return null;
    var m = str.match(/^rgba?\(([^)]+)\)$/);
    if (!m) return null;
    var p = m[1].split(',').map(function (x) { return parseFloat(x.trim()); });
    if (p.length < 3 || p.some(isNaN)) return null;
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  }

  /* sRGB relative luminance (WCAG 2.1 formula). */
  function lum(c) {
    var ch = [c.r, c.g, c.b].map(function (v) {
      v = v / 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * ch[0] + 0.7152 * ch[1] + 0.0722 * ch[2];
  }

  function ratio(fg, bg) {
    var a = lum(fg), b = lum(bg);
    var hi = Math.max(a, b), lo = Math.min(a, b);
    return (hi + 0.05) / (lo + 0.05);
  }

  /* src OVER dst, both non-premultiplied. */
  function over(src, dst) {
    var a = src.a + dst.a * (1 - src.a);
    if (a === 0) return { r: 0, g: 0, b: 0, a: 0 };
    return {
      r: (src.r * src.a + dst.r * dst.a * (1 - src.a)) / a,
      g: (src.g * src.a + dst.g * dst.a * (1 - src.a)) / a,
      b: (src.b * src.a + dst.b * dst.a * (1 - src.a)) / a,
      a: a
    };
  }

  function hex(c) {
    function h(v) { var s = Math.round(Math.max(0, Math.min(255, v))).toString(16); return s.length < 2 ? '0' + s : s; }
    return '#' + h(c.r) + h(c.g) + h(c.b);
  }

  /* The real backdrop behind `el`. Composites every translucent layer up to
     the nearest opaque one, or to the document canvas. Returns null (NOT a
     guess) when it meets a background-image/gradient it cannot resolve.

     MEMOIZED ON PURPOSE, not a style choice. The naive version re-walked every
     ancestor from scratch for every leaf, so a page with N elements at depth D
     did O(N*D) getComputedStyle calls — on a member-directory grid (hundreds
     of cards, each several levels deep) that stalled a real sweep run for
     minutes with no error, just silence. Recursing through parentElement and
     caching each node's resolved backdrop means every ancestor is priced once
     no matter how many siblings share it — O(N+D) instead. */
  var backdropCache = new Map();
  var canvasBase = null;

  function canvasBackdrop() {
    if (canvasBase) return canvasBase;
    var base = null;
    [document.body, document.documentElement].forEach(function (n) {
      if (base || !n) return;
      var c = parseColor(getComputedStyle(n).backgroundColor);
      if (c && c.a >= 0.999) base = c;
    });
    if (!base) {
      var scheme = getComputedStyle(document.documentElement).colorScheme || '';
      base = /dark/.test(scheme) ? { r: 18, g: 18, b: 18, a: 1 } : { r: 255, g: 255, b: 255, a: 1 };
    }
    canvasBase = { color: base, unmeasurable: null, painter: 'canvas' };
    return canvasBase;
  }

  function backdrop(el) {
    if (!el) return canvasBackdrop();
    if (backdropCache.has(el)) return backdropCache.get(el);
    var cs = getComputedStyle(el);
    var result;
    var bi = cs.backgroundImage;
    if (bi && bi !== 'none') {
      result = { color: null, unmeasurable: 'background-image', painter: desc(el) };
    } else {
      var c = parseColor(cs.backgroundColor);
      if (c && c.a >= 0.999) {
        result = { color: c, unmeasurable: null, painter: desc(el) };
      } else {
        var parentResult = backdrop(el.parentElement);
        if (parentResult.unmeasurable) {
          result = parentResult;
        } else if (c && c.a > 0) {
          result = { color: over(c, parentResult.color), unmeasurable: null, painter: desc(el) };
        } else {
          result = parentResult;
        }
      }
    }
    backdropCache.set(el, result);
    return result;
  }

  function desc(el) {
    if (!el || !el.tagName) return '?';
    var s = el.tagName.toLowerCase();
    if (el.id) s += '#' + el.id;
    var cls = (el.getAttribute && el.getAttribute('class') || '').trim();
    if (cls) s += '.' + cls.split(/\s+/).slice(0, 3).join('.');
    return s;
  }

  function cssPath(el) {
    var parts = [], node = el, guard = 0;
    while (node && node.nodeType === 1 && guard++ < 6) {
      parts.unshift(desc(node));
      node = node.parentElement;
    }
    return parts.join(' > ');
  }

  /* Effective opacity: any ancestor at opacity<1 fades this element too.
     Memoized like backdrop() above — same shared-ancestor cost problem. */
  var opacityCache = new Map();
  function effOpacity(el) {
    if (!el || el.nodeType !== 1) return 1;
    if (opacityCache.has(el)) return opacityCache.get(el);
    var v = parseFloat(getComputedStyle(el).opacity);
    var own = isNaN(v) ? 1 : v;
    var result = own * effOpacity(el.parentElement);
    opacityCache.set(el, result);
    return result;
  }

  function visible(el) {
    var cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || cs.visibility === 'collapse') return false;
    if (effOpacity(el) < 0.06) return false;
    var r = el.getBoundingClientRect();
    if (r.width < 1 || r.height < 1) return false;
    return true;
  }

  /* Text this element renders ITSELF (direct text children only). */
  function ownText(el) {
    var t = '';
    for (var i = 0; i < el.childNodes.length; i++) {
      var n = el.childNodes[i];
      if (n.nodeType === 3) t += n.nodeValue;
    }
    return t.replace(/\s+/g, ' ').trim();
  }

  function required(cs) {
    var size = parseFloat(cs.fontSize) || 16;
    var w = cs.fontWeight;
    var bold = (w === 'bold' || w === 'bolder' || parseInt(w, 10) >= 700);
    /* WCAG "large": 18pt (24px), or 14pt (18.66px) bold. */
    return (size >= 24 || (bold && size >= 18.66)) ? 3.0 : 4.5;
  }

  var findings = [], unmeasurable = [], seen = 0;

  function record(el, kind, fgStr, bgInfo, need, sample, extra) {
    var fg = parseColor(fgStr);
    if (!fg) return;
    if (bgInfo.unmeasurable) {
      unmeasurable.push({
        kind: kind, sel: cssPath(el), reason: bgInfo.unmeasurable,
        painter: bgInfo.painter, fg: hex(fg), sample: sample.slice(0, 80)
      });
      return;
    }
    /* Fold the element's own alpha AND inherited opacity into the ink before
       measuring — pale grey at 60% opacity is not pale grey. */
    var o = effOpacity(el);
    var ink = over({ r: fg.r, g: fg.g, b: fg.b, a: fg.a * o }, bgInfo.color);
    var cr = ratio(ink, bgInfo.color);
    seen++;
    if (cr + 0.005 < need) {
      var r = el.getBoundingClientRect();
      findings.push({
        kind: kind,
        sel: cssPath(el),
        ratio: Math.round(cr * 100) / 100,
        need: need,
        fg: hex(ink),
        bg: hex(bgInfo.color),
        painter: bgInfo.painter,
        sample: sample.slice(0, 80),
        rect: { x: Math.round(r.left), y: Math.round(r.top + window.scrollY), w: Math.round(r.width), h: Math.round(r.height) },
        extra: extra || null
      });
    }
  }

  /* HARD BUDGET, and it is REPORTED not silent. A sweep run stalled 5+ minutes
     with no error on /directory/members/ (2026-08-14, dark-anon-sweep) —
     traced afterward to CPU contention from other lanes' concurrent Chrome
     sessions on this 2-core box (load average 6+ at the time) rather than
     confirmed DOM size on that specific page, so don't take that page name as
     the culprit. The memoized backdrop/opacity walks above are a real fix
     regardless (O(N+D) instead of O(N*D) is strictly better on any page), and
     a hard cap belongs here independently: no page — crowded DOM or a starved
     CPU — should be able to make this probe run unbounded. Bail at whichever
     limit hits first and say so in the result, per house rule (no silent
     caps — log what was dropped). */
  var SCAN_CAP = 6000;
  var TIME_BUDGET_MS = 8000;
  var t0 = Date.now();
  var truncated = false, truncReason = null, scanned = 0;

  var all = document.querySelectorAll('body *');
  for (var i = 0; i < all.length; i++) {
    if (i >= SCAN_CAP) { truncated = true; truncReason = 'element-cap'; break; }
    if ((i & 127) === 0 && Date.now() - t0 > TIME_BUDGET_MS) { truncated = true; truncReason = 'time-budget'; break; }
    scanned = i + 1;
    var el = all[i];
    var tag = el.tagName.toLowerCase();
    if (tag === 'script' || tag === 'style' || tag === 'noscript' || tag === 'template') continue;
    if (!visible(el)) continue;
    var cs = getComputedStyle(el);
    var bg = null;

    /* ---- 1. TEXT the element renders itself ---------------------------- */
    var txt = ownText(el);
    if (txt) {
      bg = bg || backdrop(el);
      record(el, 'text', cs.color, bg, required(cs), txt);
    }

    /* ---- 1b. ICON CONTROLS ---------------------------------------------
       A glyph is not text, so pass 1 above never sees it — and this is
       exactly how the sweep's own named seed (the mobile "+" compose
       circle, KNOWN dark-contrast issue per the dark-anon-sweep charter)
       would go unmeasured: white stroke on --lg-sage-d, which REPOINTS to a
       light colour in dark (#b0c693) while the stroke stays hardcoded #fff.
       1.85:1, confirmed by hand against this exact probe's ratio() (2026-08-14).

       Scoped to filled circular/pill controls (ANY element — not just
       button/a: the "+" button's actual round filled surface is
       .lt-post-ico, a <span> one level INSIDE the <button>, which has no
       background or radius of its own. Gating this on tag name would have
       missed it silently, checking the wrapper and never the shape that is
       actually round and filled. border-radius >=40% OR >=999px, solid
       non-transparent background) containing exactly one icon (svg/use) and
       no own text — the raised FAB-style control this product uses for its
       most important anon CTAs, not every icon on the page (a bare nav
       glyph with no fill has no single "control" contrast question the same
       way). UI-component bar: 3:1 (WCAG 1.4.11). */
    if (!ownText(el)) {
      var svg = el.querySelector('svg');
      if (svg && el.querySelectorAll('svg').length === 1) {
        var br = cs.borderRadius;
        var rNum = parseFloat(br) || 0;
        var rectEl = el.getBoundingClientRect();
        var isRound = /%/.test(br) ? rNum >= 40 : (rNum >= 999 || rNum >= Math.min(rectEl.width, rectEl.height) / 2 - 1);
        var fillC = parseColor(cs.backgroundColor);
        if (isRound && fillC && fillC.a > 0.5) {
          var iconCs = getComputedStyle(svg);
          var paint = (iconCs.stroke && iconCs.stroke !== 'none') ? iconCs.stroke : iconCs.fill;
          if (paint && paint !== 'none') {
            var ownBg = { color: over(fillC, backdrop(el.parentElement).color || fillC), unmeasurable: null, painter: desc(el) };
            record(el, 'icon-control', paint, ownBg, 3.0,
                   '(icon in ' + (el.getAttribute('aria-label') || el.className || tag) + ')');
          }
        }
      }
    }

    /* ---- 2. FORM FIELDS ------------------------------------------------ */
    if (tag === 'input' || tag === 'textarea' || tag === 'select') {
      var type = (el.getAttribute('type') || 'text').toLowerCase();
      if (type !== 'hidden' && type !== 'submit' && type !== 'button' && type !== 'image') {
        /* the field's own inner backdrop, not its parent's */
        var fieldBg = backdrop(el);
        /* value ink — what the visitor sees as they type */
        record(el, 'field-text', cs.color, fieldBg, required(cs),
               el.value || el.getAttribute('name') || type);

        /* placeholder — on these forms the placeholder IS often the instruction */
        var ph = el.getAttribute('placeholder');
        if (ph) {
          var pcs = null;
          try { pcs = getComputedStyle(el, '::placeholder'); } catch (e) {}
          if (pcs && pcs.color) record(el, 'placeholder', pcs.color, fieldBg, required(cs), ph);
        }

        /* the field's EDGE against the page — 1.4.11, how you find the box */
        var bw = ['Top', 'Right', 'Bottom', 'Left'].map(function (s) {
          return parseFloat(cs['border' + s + 'Width']) || 0;
        });
        if (Math.max.apply(null, bw) > 0) {
          var outer = backdrop(el.parentElement || el);
          record(el, 'field-border', cs.borderTopColor, outer, 3.0,
                 '(edge of ' + (el.getAttribute('name') || type) + ' field)');
        } else {
          /* No border at all: the field is findable only if its FILL differs
             from the page. Same 3:1 bar, measured fill-vs-page. */
          var outer2 = backdrop(el.parentElement || el);
          if (fieldBg.color && outer2.color && !fieldBg.unmeasurable && !outer2.unmeasurable) {
            var cr2 = ratio(fieldBg.color, outer2.color);
            seen++;
            if (cr2 + 0.005 < 3.0) {
              var rr = el.getBoundingClientRect();
              findings.push({
                kind: 'field-borderless', sel: cssPath(el),
                ratio: Math.round(cr2 * 100) / 100, need: 3.0,
                fg: hex(fieldBg.color), bg: hex(outer2.color), painter: outer2.painter,
                sample: '(borderless ' + (el.getAttribute('name') || type) + ' field — fill vs page)',
                rect: { x: Math.round(rr.left), y: Math.round(rr.top + window.scrollY), w: Math.round(rr.width), h: Math.round(rr.height) },
                extra: null
              });
            }
          }
        }
      }
    }
  }

  return {
    url: location.href,
    theme: document.documentElement.getAttribute('data-lguser-theme') || '(none)',
    darkAttr: document.documentElement.getAttribute('data-lguser-dark') || '(none)',
    colorScheme: getComputedStyle(document.documentElement).colorScheme || '(none)',
    bodyBg: hex((function () { var c = parseColor(getComputedStyle(document.body).backgroundColor); return c && c.a > 0 ? c : { r: 255, g: 255, b: 255 }; })()),
    measured: seen,
    findings: findings.sort(function (a, b) { return a.ratio - b.ratio; }),
    unmeasurable: unmeasurable,
    truncated: truncated,
    truncReason: truncReason,
    scannedElements: scanned,
    totalElements: all.length
  };
})()
