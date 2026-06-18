/* archive-poc front page — member/visitor map tile (Buck 2026-06-11).
   Zooms the bento map tile to the viewer's location + lists the closest
   members to their shop (distance-sorted), via the profile-app directory API.
   Location source:
     • logged-in member → GET /profile-api/v0/me/location (their coarse pin),
       "You" marker.
     • logged-out / member with no pin → IP geolocation (get.geojs.io),
       "Near you" marker.
   All coords are precision-coarsened (members) or city-level (IP) — F1-safe,
   and NO dependency on the dead members-geo WP route.
   Graceful: any failure leaves the static teaser tile exactly as rendered. */
(function () {
  'use strict';
  var host = document.getElementById('lg-fp-map');
  if (!host) return;

  function j(u, opts) {
    return fetch(u, opts || {}).then(function (r) {
      if (!r.ok) throw new Error(String(r.status));
      return r.json();
    });
  }
  function num(v) { return typeof v === 'number' && isFinite(v); }

  // me/location nests coords under `place`; tolerate other shapes too.
  function pickMe(b) {
    if (!b || typeof b !== 'object') return null;
    // Owner removed the Location section from their profile = opted off the
    // map — no personal You pin either; fall through to the IP fallback so
    // the tile still centers somewhere sensible (Ian 6/12).
    if (b.in_layout === false) return null;
    var cands = [b.place, b, b.display, b.pin, b.map, b.coarse, b.exact];
    for (var i = 0; i < cands.length; i++) {
      var c = cands[i];
      if (c && num(c.lat) && num(c.lng)) {
        return { lat: c.lat, lng: c.lng, zoom: c.zoom || b.zoom || 10, source: 'me' };
      }
    }
    return null;
  }

  // Logged-in member who's NOT on the map (section stowed, or no place ever
  // picked): swap the teaser copy for a one-click "put me on the map" CTA —
  // it re-adds the Location section via me/layout; with a place already
  // stored that's instantly back on the map (reload shows the live pin),
  // otherwise it walks them to their profile to pick one (Ian 6/12).
  function showJoinCta(meLoc) {
    var copy = host.querySelector('.lg-bento__map-copy');
    if (!copy || copy.querySelector('.lg-bento__map-join')) return;
    var hasPlace = !!(meLoc && meLoc.place && num(meLoc.place.lat) && num(meLoc.place.lng));
    var sub = copy.querySelector('.lg-bento__map-sub');
    if (sub) sub.textContent = hasPlace
      ? 'You\u2019re not on the map right now \u2014 luthiers near you can\u2019t find your shop.'
      : 'You\u2019re not on the map yet \u2014 add your location so nearby luthiers can find you.';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'lg-bento__map-btn lg-bento__map-join';
    var label = 'Put me on the map';
    btn.textContent = label;
    btn.addEventListener('click', function () {
      btn.disabled = true;
      btn.textContent = 'Adding\u2026';
      j('/profile-api/v0/me/layout', { credentials: 'same-origin' })
        .then(function (g) {
          var order = (g && g.layout) || [];
          if (order.indexOf('location') < 0) order.push('location');
          return fetch('/profile-api/v0/me/layout', {
            method: 'PUT', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order: order })
          });
        })
        .then(function (r) {
          if (!r.ok) throw new Error(String(r.status));
          if (hasPlace) { location.reload(); return; }   // live map + You pin on repaint
          // No place stored yet — the section is on the profile now; go pick one.
          return j('/profile-api/v0/whoami', { credentials: 'same-origin' })
            .catch(function () { return {}; })
            .then(function (who) {
              var slug = who && (who.slug || (who.user && who.user.slug));
              location.href = slug ? '/u/' + encodeURIComponent(slug) : '/profile/edit';
            });
        })
        .catch(function () { btn.disabled = false; btn.textContent = label; });
    });
    var open = copy.querySelector('[data-action="open-member-map"]');
    copy.insertBefore(btn, open || null);
  }

  // 1) member's own location → 2) IP geolocation fallback.
  // A member who STOWED the Location section opted off the map — honor that
  // fully: no You pin AND no IP-guess either; the static teaser stays (Ian
  // 6/12), plus the one-click rejoin CTA above.
  function resolveLocation() {
    return j('/profile-api/v0/me/location', { credentials: 'same-origin' })
      .then(function (b) {
        // Deliberate stow (saved layout without location) = honored opt-out:
        // teaser + CTA, no IP guess. Everything else authed-without-a-pin
        // (never-customized default, or section on but no place picked) gets
        // the IP-centered no-pin map + CTA — the join nudge (Ian 6/12).
        if (b && b.opted_out) { showJoinCta(b); return { optedOut: true }; }
        var me = pickMe(b);
        if (b && !me) { showJoinCta(b); return { joinable: true }; }
        return me;
      })
      .catch(function () { return null; })
      .then(function (loc) {
        if (loc && loc.optedOut) return null;
        if (loc && !loc.joinable) return loc;
        var joinable = !!(loc && loc.joinable);
        return j('https://get.geojs.io/v1/ip/geo.json')
          .then(function (g) {
            var lat = parseFloat(g && g.latitude), lng = parseFloat(g && g.longitude);
            if (!num(lat) || !num(lng)) return null;
            // joinable = a member with no place: show their general area with
            // NO center marker ("we don't know where you are") + the CTA.
            return { lat: lat, lng: lng, zoom: 9, source: 'ip', noMarker: joinable };
          })
          .catch(function () { return null; });
      });
  }

  resolveLocation().then(function (loc) {
    if (!loc) return;                                  // no location → teaser stays
    return j('/profile-api/v0/whoami', { credentials: 'same-origin' })
      .catch(function () { return {}; })
      .then(function (who) {
        var myUuid = (who && (who.uuid || (who.user && who.user.uuid))) || null;
        var authed = !!(who && who.authenticated);
        // Logged-out (or whoami-unbridged: leak-safe direction): the Strava
        // density layer — the SAME population the finder shows anon as
        // anonymous dots, aggregated into ~11km count cells. Zero identity;
        // the join nudge is real density, not an empty map (Ian 6/12).
        var densityP = authed
          ? Promise.resolve(null)
          : j('/profile-api/v0/directory/pins-public').catch(function () { return null; });
        return densityP.then(function (density) {
          if (density) loc.anonDensity = density;
          return j('/profile-api/v0/directory/members?lat=' + loc.lat + '&lng=' + loc.lng + '&page_size=9')
            .then(function (d) { loadLeaflet(loc, myUuid, (d && d.items) || []); });
        });
      });
  }).catch(function () { /* teaser stays */ });

  function loadLeaflet(loc, myUuid, items) {
    if (window.L && window.L.map) return init(loc, myUuid, items);
    var css = document.createElement('link');
    css.rel = 'stylesheet'; css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
    document.head.appendChild(css);
    var s = document.createElement('script');
    s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    s.onload = function () { init(loc, myUuid, items); };
    document.head.appendChild(s);
  }

  function init(loc, myUuid, items) {
    var canvas = host.querySelector('.lg-fp-map__canvas');
    var list = host.querySelector('.lg-fp-map__list');
    var titleEl = host.querySelector('.lg-bento__map-title');
    var subEl = host.querySelector('.lg-bento__map-sub');
    if (!canvas) return;
    host.classList.add('is-live');
    if (titleEl) titleEl.textContent = 'Luthiers near you';
    var density = loc.anonDensity || null;
    if (subEl) subEl.textContent = density
      ? ((density.count ? density.count + ' luthiers and shops are on the member map' : 'Luthiers and shops worldwide')
         + ' — join to see who’s near you.')
      : (loc.noMarker
        ? 'Your general area — we don’t know or store your location until you add it.'
        : (loc.source === 'ip'
          ? 'Based on your location — the closest luthiers and shops:'
          : 'You’re on the map. The closest luthiers and shops:'));

    var map = L.map(canvas, { scrollWheelZoom: false, zoomControl: true })
      .setView([loc.lat, loc.lng], Math.min(11, Math.max(8, (loc.zoom || 10) - 1)));
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    // Anonymous density cells (logged-out): soft dots sized by member count,
    // no tooltip, no identity, nothing clickable-through.
    if (density && density.cells) {
      density.cells.forEach(function (c) {
        if (!num(c[0]) || !num(c[1])) return;
        L.circleMarker([c[0], c[1]], {
          radius: Math.min(13, 4 + 2 * Math.sqrt(c[2] || 1)),
          stroke: false, fillColor: '#87986a', fillOpacity: 0.5,
          interactive: false
        }).addTo(map);
      });
    }

    if (!loc.noMarker) {
      var meLabel = loc.source === 'ip' ? 'Near you' : 'You';
      L.circleMarker([loc.lat, loc.lng], { radius: 9, color: '#fff', weight: 3, fillColor: '#6f7c54', fillOpacity: 1 })
        .addTo(map)
        .bindTooltip(meLabel, { permanent: true, direction: 'top', offset: [0, -10], className: 'lg-fp-you' });
    }

    var shown = 0;
    items.forEach(function (it) {
      var l = it && it.location;
      if (!l || !num(l.lat) || !num(l.lng)) return;
      var isMe = !!(myUuid && it.uuid === myUuid);
      if (!isMe) {
        L.circleMarker([l.lat, l.lng], { radius: 7, color: '#fff', weight: 2, fillColor: '#c66845', fillOpacity: .95 })
          .addTo(map).bindTooltip(it.display_name || 'Member');
      }
      if (list && shown < 4) {
        var a = document.createElement('a');
        a.className = 'lg-fp-near'; a.href = '/u/' + encodeURIComponent(it.slug || '');
        var n = document.createElement('span'); n.className = 'lg-fp-near__n'; n.textContent = it.display_name || 'Member';
        var m = document.createElement('span'); m.className = 'lg-fp-near__m'; m.textContent = l.text || '';
        var d = document.createElement('span'); d.className = 'lg-fp-near__d';
        d.textContent = isMe ? 'you' : (num(it.distance_mi) ? (it.distance_mi < 1 ? '<1 mi' : Math.round(it.distance_mi) + ' mi') : '');
        a.appendChild(n); a.appendChild(m); a.appendChild(d);
        list.appendChild(a); shown++;
      }
    });
    if (shown) host.classList.add('has-list');
    setTimeout(function () { map.invalidateSize(); }, 250);
  }
})();
