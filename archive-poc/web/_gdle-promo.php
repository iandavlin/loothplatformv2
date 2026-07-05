<?php
/**
 * _gdle-promo.php — Guitardle promo + game modal (shared partial).
 *
 * Two render shapes, picked by $gdle_compact:
 *   false — the standalone front-page row promo: icon | pitch+Play | top-5
 *           (three-column grid; the original row--guitardle body).
 *   true  — the STACKED card under the featured video inside the What's-New
 *           container (Ian 6/12 "move the guitardle stuff in the container
 *           with featured video and stack it"): title line + Play + top-5.
 *
 * Either shape emits the SAME centered modal + script once: the iframe src is
 * set lazily on first open and the modal hides (never destroys) on close, so
 * reopening never reloads a game in progress (and the game is refresh-proof
 * anyway — state persists, Ian 6/12). The embed page carries its own weekly
 * top-5 strip (side by side with the game on wide hosts), so the modal shows
 * the leaderboard with no extra wiring here.
 *
 * Expects: $is_member (bool) in scope. Keep the #guitardle anchor — the Hub
 * teaser and shares deep-link /archive-poc/#guitardle (and #guitardle=play
 * auto-opens the modal).
 */
$gdle_compact = !empty($gdle_compact);
// Bust the iframe cache on the NEWEST of the three game assets — editing
// style.css or index.html alone (not game.js) must still reload the embed.
$gdle_v = 1;
foreach (['guitardle/game.js', 'guitardle/style.css', 'guitardle/index.html'] as $gdle_asset) {
    $gdle_v = max($gdle_v, (int) @filemtime(__DIR__ . '/' . $gdle_asset));
}
$gdle_src = '/archive-poc/guitardle/index.html?embed=1&aud=' . ($is_member ? 'm' : 'p')
          . '&v=' . $gdle_v;

// The board always shows FIVE slots (Ian 6/12): leaders fill from the top,
// the rest render as open-spot placeholders — never a collapsed/empty card.
// SSR'd here so the slots are visible even before (or without) the API call;
// fillBoard() below repaints with live leaders and pads back to five.
$gdle_slots = '';
for ($gdle_i = 1; $gdle_i <= 5; $gdle_i++) {
    $gdle_slots .= '<li class="gdle-side-row gdle-side-row--open">'
                 . '<span class="gdle-side-row__rank">' . $gdle_i . '</span>'
                 . '<span class="gdle-side-row__name">Open spot</span>'
                 . '<span class="gdle-side-row__pts">play to claim</span></li>';
}
?>
<div class="gdle-block<?= $gdle_compact ? ' gdle-block--stack' : '' ?>" id="guitardle">
  <?php if ($gdle_compact): ?>
    <?php /* The whole icon+title header IS the play button (Ian 6/12 —
             replaced the separate green pill). */ ?>
    <button type="button" class="gdle-stack__btn" id="gdle-play" aria-label="Play today's Guitardle">
      <img class="gdle-stack__ic" src="/archive-poc/guitardle/assets/guitardle-icon-512.webp" alt="" aria-hidden="true" loading="lazy">
      <span class="gdle-stack__title">Guitardle</span>
      <span class="gdle-stack__sub">the daily guitar phrase game</span>
    </button>
    <aside class="gdle-card gdle-promo__board" aria-label="Guitardle weekly top 5">
      <h3 class="gdle-card__title">🏆 Weekly top 5</h3>
      <ol class="gdle-side-board" id="gdle-side-board"><?= $gdle_slots ?></ol>
      <p class="gdle-side-champ" id="gdle-side-champ" hidden></p>
    </aside>
  <?php else: ?>
    <div class="gdle-promo">
      <img class="gdle-promo__icon gdle-side-art" src="/archive-poc/guitardle/assets/guitardle-icon-512.webp" alt="" aria-hidden="true" loading="lazy" width="512" height="512">
      <div class="gdle-promo__main">
        <p class="gdle-promo__pitch">Six guesses, one guitar phrase a day. Wins score points &mdash; Hardcore counts double, board resets Monday.</p>
        <button type="button" class="gdle-promo__play" id="gdle-play">Play today's Guitardle &rarr;</button>
      </div>
      <aside class="gdle-card gdle-promo__board" aria-label="Guitardle weekly top 5">
        <h3 class="gdle-card__title">🏆 Weekly top 5</h3>
        <ol class="gdle-side-board" id="gdle-side-board"><?= $gdle_slots ?></ol>
        <p class="gdle-side-champ" id="gdle-side-champ" hidden></p>
      </aside>
    </div>
  <?php endif; ?>

  <style>
    /* Open-spot placeholder slots (inline so this partial stays self-contained
       and archive.css — which has another lane's WIP — goes untouched). */
    .gdle-side-row--open { opacity: .55; font-style: italic; }
    .gdle-side-champ { margin: .55em 0 0; font-size: .85em; color: #3c4a28;
      border-top: 1px solid rgba(135,152,106,.4); padding-top: .5em;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .gdle-side-champ a { color: inherit; font-weight: 600; }
    .gdle-side-row--open .gdle-side-row__rank { color: #b3bfa0; }
  </style>

  <div class="gdle-modal" id="gdle-modal" hidden role="dialog" aria-modal="true" aria-label="Guitardle — daily guitar phrase game">
    <div class="gdle-modal__back" data-gdle-close></div>
    <div class="gdle-modal__panel">
      <div class="gdle-modal__row">
        <span class="gdle-modal__title"><img class="gdle-modal__ic" src="/archive-poc/guitardle/assets/guitardle-icon-512.webp" alt="">Guitardle</span>
        <button type="button" class="gdle-modal__x" data-gdle-close aria-label="Close">&times;</button>
      </div>
      <iframe class="gdle-frame" id="gdle-frame"
              data-src="<?= h($gdle_src) ?>"
              title="Guitardle — daily guitar phrase game"
              scrolling="no"></iframe>
    </div>
  </div>

  <script>
  (function () {
      addEventListener('message', function (e) {
          if (e.origin !== location.origin) return;
          if (!e.data || e.data.type !== 'guitardle:height' || !(e.data.height > 0)) return;
          var f = document.getElementById('gdle-frame');
          if (f) f.style.height = Math.ceil(e.data.height) + 'px';
      });

      // An open slot — the board always shows five rows (placeholders included).
      function openSlot(i) {
          var li = document.createElement('li');
          li.className = 'gdle-side-row gdle-side-row--open';
          var rank = document.createElement('span');
          rank.className = 'gdle-side-row__rank';
          rank.textContent = String(i + 1);
          var name = document.createElement('span');
          name.className = 'gdle-side-row__name';
          name.textContent = 'Open spot';
          var pts = document.createElement('span');
          pts.className = 'gdle-side-row__pts';
          pts.textContent = 'play to claim';
          li.append(rank, name, pts);
          return li;
      }

      function fillBoard() {
          fetch('/archive-api/v0/guitardle-board?champion=1', { credentials: 'same-origin' })
              .then(function (r) { return r.ok ? r.json() : null; })
              .then(function (b) {
                  if (!b) return;
                  var list = document.getElementById('gdle-side-board');
                  var leaders = (b.leaders || []).slice(0, 5);   // promo card = top 5
                  list.innerHTML = '';
                  leaders.forEach(function (l, i) {
                      var li = document.createElement('li');
                      li.className = 'gdle-side-row' + (i === 0 ? ' is-first' : '');
                      var rank = document.createElement('span');
                      rank.className = 'gdle-side-row__rank';
                      rank.textContent = i === 0 ? '👑' : (i + 1);
                      var name = document.createElement(l.profile_url ? 'a' : 'span');
                      name.className = 'gdle-side-row__name';
                      name.textContent = l.name;
                      if (l.profile_url) name.href = l.profile_url;
                      var pts = document.createElement('span');
                      pts.className = 'gdle-side-row__pts';
                      pts.textContent = l.points + ' pts · ' + l.wins + 'W';
                      li.append(rank, name, pts);
                      list.appendChild(li);
                  });
                  for (var i = leaders.length; i < 5; i++) list.appendChild(openSlot(i));
                  // Last week's champion (Ian 7/05) — same graceful-absence
                  // rule as the in-game strip: no champion → line stays hidden.
                  var champEl = document.getElementById('gdle-side-champ');
                  if (champEl && b.champion && b.champion.name) {
                      champEl.textContent = '';
                      champEl.append('👑 Last week\u2019s champ: ');
                      var cn = document.createElement(b.champion.profile_url ? 'a' : 'span');
                      cn.textContent = b.champion.name;
                      if (b.champion.profile_url) cn.href = b.champion.profile_url;
                      champEl.appendChild(cn);
                      if (b.champion.points > 0) champEl.append(' \u00b7 ' + b.champion.points + ' pts');
                      champEl.hidden = false;
                  }
              }).catch(function () {});
      }

      // Modal open/close. The iframe src loads ONCE on first open and the
      // modal only hides after that — no reason to reload a round in progress
      // (state would survive anyway: the game is refresh-proof).
      var modal = document.getElementById('gdle-modal');
      var frame = document.getElementById('gdle-frame');
      function openGame() {
          if (frame && !frame.src) frame.src = frame.dataset.src;
          modal.hidden = false;
          document.body.classList.add('gdle-modal-lock');
      }
      function closeGame() {
          modal.hidden = true;
          document.body.classList.remove('gdle-modal-lock');
          setTimeout(fillBoard, 1500);   // a just-finished round shows up
      }
      var play = document.getElementById('gdle-play');
      if (play) play.addEventListener('click', openGame);
      modal.addEventListener('click', function (e) {
          if (e.target.closest('[data-gdle-close]')) closeGame();
      });
      addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && !modal.hidden) closeGame();
      });
      // Hub-teaser style deep link still works: /front-page/#guitardle=play
      if (location.hash === '#guitardle=play') openGame();

      fillBoard();
      // The iframe writes guitardle_lastPlayed at game end, firing `storage`
      // here — refresh the board after the score POST lands. Keyed to that ONE
      // key: the refresh-proof save now writes localStorage on every move, and
      // a per-move board refetch would be pure noise.
      addEventListener('storage', function (e) {
          if (e.key !== 'guitardle_lastPlayed') return;
          setTimeout(fillBoard, 2000);
      });
  })();
  </script>
</div>
