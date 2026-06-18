<?php
/**
 * archive-poc/api/v0/comments.php — GET /archive-api/v0/comments (modal read).
 *
 * The comment modal's iframe loads this. Runs on the archive-poc FPM pool: reads
 * discovery.comments directly, NO WordPress boot — replacing the WP-booting
 * deploy/lg-comments-frame.php (~1–3s → ~50ms). Returns a self-contained,
 * brand-styled HTML document that posts its content height to the parent (the same
 * postMessage handshake the standalone modal already listens for).
 *
 *   GET ?post_type=<content cpt>&item_id=<wp post id>
 *
 * Thread rows are read here; author cards (name / avatar / profile slug) are
 * resolved LIVE from /profile-api/v0/users so renames + new avatars follow. Posting
 * is handled separately by comment-post.php (looth-dev WP pool) — this page renders
 * the composer inert and the iframe's JS lazily fetches the auth state + nonce from
 * there, gating the form on the WP login cookie (not /whoami).
 */

declare(strict_types=1);
require_once __DIR__ . '/_comments.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Vary: Cookie');

function lg_c_h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$postType = isset($_GET['post_type']) ? trim((string) $_GET['post_type']) : '';
$itemId   = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;

$valid = in_array($postType, LG_COMMENTS_TYPES, true) && $itemId > 0;

$rows  = [];
$cards = [];
$reactions = [];
if ($valid) {
    try {
        $pdo  = lg_comments_pdo();
        $rows = lg_comments_thread($pdo, $postType, $itemId);
        $uuids = [];
        foreach ($rows as $r) if (!empty($r['user_uuid'])) $uuids[] = (string) $r['user_uuid'];
        if ($uuids) $cards = lg_comments_author_cards($uuids);
        // Reaction counts per comment (WP-free aggregate). The viewer's own pick is
        // NOT known here (no validated cookie on this pool) — the iframe JS fetches
        // it from comment-post.php GET and highlights after load.
        $cids = array_map(static fn($r) => (int) $r['id'], $rows);
        $reactions = lg_reactions_for_comments($pdo, $cids);
    } catch (Throwable $e) {
        error_log('[lg-comments] ' . $e->getMessage());
        $rows = [];
    }
}

// Build parent → children index for threaded render.
$byParent = [];
foreach ($rows as $r) {
    $pid = $r['parent_id'] !== null ? (int) $r['parent_id'] : 0;
    $byParent[$pid][] = $r;
}

$tz = new DateTimeZone(LG_ARCHIVE_POC_TZ);
function lg_c_when(int $ts, DateTimeZone $tz): string {
    $d = (new DateTime('@' . $ts))->setTimezone($tz);
    return $d->format('M j, Y · g:ia');
}

/** Inner glyph (emoji char or static image) for one palette reaction. */
function lg_c_rx_glyph(array $rx): string {
    if (($rx['type'] ?? '') === 'image') {
        return '<img class="lgc-rx-img" src="' . lg_c_h(LG_REACTIONS_ASSET_BASE . ($rx['file'] ?? ''))
             . '" width="20" height="20" alt="" loading="lazy">';
    }
    return '<span class="lgc-rx-emoji">' . lg_c_h($rx['char'] ?? '') . '</span>';
}

/**
 * Reaction bar for one comment: count chips for reactions that exist, plus an
 * "add reaction" trigger revealing the full palette. Viewer-mine highlight is
 * applied client-side after the cookie-validated my_reactions fetch. The whole
 * bar is inert until JS wires it (counts still render for logged-out viewers).
 */
function lg_c_render_reactions(int $id, array $counts): string {
    $palette = lg_reactions_palette();
    $byslug  = [];
    foreach ($palette as $rx) $byslug[$rx['slug']] = $rx;
    // existing-count chips, in palette order
    $chips = '';
    foreach ($palette as $rx) {
        $n = (int) ($counts[$rx['slug']] ?? 0);
        if ($n <= 0) continue;
        $chips .= '<button type="button" class="lgc-rx" data-slug="' . lg_c_h($rx['slug'])
                . '" title="' . lg_c_h($rx['label']) . '">' . lg_c_rx_glyph($rx)
                . '<span class="lgc-rx-n">' . $n . '</span></button>';
    }
    // full palette picker (hidden until the add-trigger opens it)
    $opts = '';
    foreach ($palette as $rx) {
        $opts .= '<button type="button" class="lgc-rx-opt" data-slug="' . lg_c_h($rx['slug'])
               . '" title="' . lg_c_h($rx['label']) . '">' . lg_c_rx_glyph($rx) . '</button>';
    }
    return '<div class="lgc-reactions" data-comment-id="' . $id . '">'
         . '<span class="lgc-rx-chips">' . $chips . '</span>'
         . '<button type="button" class="lgc-rx-add" aria-label="Add reaction">☺<span>+</span></button>'
         . '<span class="lgc-rx-palette" hidden>' . $opts . '</span>'
         . '</div>';
}

/** Recursively render a comment + its replies. */
function lg_c_render_node(array $r, array $byParent, array $cards, array $reactions, DateTimeZone $tz, int $depth = 0): string {
    $uuid = !empty($r['user_uuid']) ? strtolower((string) $r['user_uuid']) : '';
    $card = $uuid !== '' ? ($cards[$uuid] ?? null) : null;
    $name = $card && $card['display_name'] !== '' ? $card['display_name']
          : ((string) ($r['author_name'] ?? '') !== '' ? (string) $r['author_name'] : 'Member');
    $slug   = $card['slug'] ?? '';
    $avatar = $card['avatar_url'] ?? '';
    $id     = (int) $r['id'];
    $authorWp = (int) ($r['author_wp_id'] ?? 0);
    $when   = lg_c_when((int) $r['created_at'], $tz);
    $edited = !empty($r['edited_at']);

    $nameHtml = lg_c_h($name);
    if ($slug !== '') $nameHtml = '<a href="/u/' . lg_c_h($slug) . '" target="_top">' . $nameHtml . '</a>';
    $avatarHtml = $avatar !== ''
        ? '<img class="lgc-av" src="' . lg_c_h($avatar) . '" width="36" height="36" alt="" loading="lazy">'
        : '<span class="lgc-av lgc-av--ph">' . lg_c_h(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>';

    // Body: stored text, escaped, newlines → <br>. (Net-new writes are plain text;
    // legacy backfill strips tags — see backfill-comments.php.)
    $body = nl2br(lg_c_h($r['body']), false);

    $kids = $byParent[$id] ?? [];
    $kidsHtml = '';
    foreach ($kids as $k) $kidsHtml .= lg_c_render_node($k, $byParent, $cards, $reactions, $tz, $depth + 1);

    $rxHtml = lg_c_render_reactions($id, $reactions[$id] ?? []);

    ob_start(); ?>
<li class="lgc" id="lgc-<?= $id ?>" data-author="<?= $authorWp ?>" data-raw="<?= lg_c_h((string) $r['body']) ?>">
  <div class="lgc-body">
    <div class="lgc-head"><?= $avatarHtml ?><span class="lgc-name"><?= $nameHtml ?></span><span class="lgc-time"><?= lg_c_h($when) ?><span class="lgc-edited" title="Edited"<?= $edited ? '' : ' hidden' ?>> (edited)</span></span></div>
    <div class="lgc-text"><?= $body ?></div>
    <div class="lgc-meta">
      <?php if ($depth < 4): ?><button type="button" class="lgc-reply" data-id="<?= $id ?>" data-name="<?= lg_c_h($name) ?>">Reply</button><?php endif; ?>
      <span class="lgc-own" hidden><button type="button" class="lgc-edit">Edit</button><button type="button" class="lgc-del">Delete</button></span>
      <?= $rxHtml ?>
    </div>
  </div>
  <?php if ($kidsHtml !== ''): ?><ul class="lgc-children"><?= $kidsHtml ?></ul><?php endif; ?>
</li>
<?php return (string) ob_get_clean();
}

$threadHtml = '';
foreach (($byParent[0] ?? []) as $top) $threadHtml .= lg_c_render_node($top, $byParent, $cards, $reactions, $tz, 0);
$count = count($rows);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Comments</title>
<style>
  /* brand palette (sage #87986a / coral #c66845 / ink #1a1d1a) — self-contained,
     no theme CSS (this page never boots WP). Mirrors the old comments-frame look. */
  html,body{margin:0;background:#fff;color:#1a1d1a;
            font-family:'Jost',system-ui,-apple-system,sans-serif;-webkit-font-smoothing:antialiased;}
  body.lgc-frame{padding:16px 20px 26px;max-width:680px;margin:0 auto;}
  a{color:#6b7c52;}

  /* composer */
  .lgc-compose{margin:0 0 22px;}
  .lgc-compose textarea{width:100%;box-sizing:border-box;font:inherit;font-size:15px;line-height:1.5;
    padding:12px 14px;border:1px solid #d8d2c4;border-radius:12px;background:#fbfbf8;color:#1a1d1a;
    resize:vertical;min-height:88px;}
  .lgc-compose textarea:focus{outline:none;border-color:#87986a;background:#fff;
    box-shadow:0 0 0 3px rgba(135,152,106,.18);}
  .lgc-replyto{font-size:13px;color:#8a857c;margin:0 0 8px;display:none;}
  .lgc-replyto button{background:none;border:0;color:#c66845;cursor:pointer;font:inherit;padding:0 0 0 6px;}
  .lgc-actions{display:flex;justify-content:flex-end;align-items:center;gap:14px;margin:12px 0 0;}
  .lgc-actions .lgc-err{margin-right:auto;color:#c66845;font-size:13px;}
  .lgc-submit{-webkit-appearance:none;appearance:none;border:0;cursor:pointer;font:inherit;font-weight:600;
    font-size:14px;padding:9px 22px;border-radius:999px;background:#87986a;color:#fff;transition:background .15s;}
  .lgc-submit:hover{background:#6b7c52;} .lgc-submit:disabled{opacity:.5;cursor:default;}
  .lgc-login{font-size:14px;color:#8a857c;margin:0 0 22px;}
  .lgc-login a{font-weight:600;}

  /* thread */
  .lgc-list,.lgc-children{list-style:none;margin:0;padding:0;}
  .lgc-children{margin:6px 0 0;padding-left:18px;border-left:2px solid #eee7da;}
  .lgc-body{padding:14px 0;border-top:1px solid #eee7da;}
  .lgc-head{display:flex;align-items:center;gap:10px;}
  .lgc-av{width:36px;height:36px;border-radius:50%;object-fit:cover;background:#ece7db;}
  .lgc-av--ph{display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#6b7c52;}
  .lgc-name{font-weight:700;font-size:14px;color:#1a1d1a;} .lgc-name a{color:#1a1d1a;text-decoration:none;}
  .lgc-name a:hover{text-decoration:underline;}
  .lgc-time{font-size:12px;color:#9a948a;margin-left:auto;}
  .lgc-text{margin:8px 0 6px;font-size:15px;line-height:1.55;white-space:normal;}
  .lgc-reply{background:none;border:0;color:#6b7c52;cursor:pointer;font:inherit;font-weight:600;font-size:13px;padding:0;}
  .lgc-reply:hover{text-decoration:underline;}
  .lgc-edited{font-style:italic;color:#b0aaa0;font-size:11px;}
  .lgc-own{display:inline-flex;gap:12px;}
  .lgc-own[hidden]{display:none;}
  .lgc-edit,.lgc-del{background:none;border:0;cursor:pointer;font:inherit;font-weight:600;font-size:13px;padding:0;}
  .lgc-edit{color:#6b7c52;} .lgc-edit:hover{text-decoration:underline;}
  .lgc-del{color:#c66845;} .lgc-del:hover{text-decoration:underline;}
  /* inline editor (replaces .lgc-text while editing) */
  .lgc-editbox{margin:8px 0 6px;}
  .lgc-editbox textarea{width:100%;box-sizing:border-box;font:inherit;font-size:15px;line-height:1.5;
    padding:10px 12px;border:1px solid #87986a;border-radius:10px;background:#fff;color:#1a1d1a;resize:vertical;min-height:64px;}
  .lgc-editbox textarea:focus{outline:none;box-shadow:0 0 0 3px rgba(135,152,106,.18);}
  .lgc-edit-actions{display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-top:8px;}
  .lgc-edit-actions .lgc-err{margin-right:auto;color:#c66845;font-size:13px;}
  .lgc-edit-save{-webkit-appearance:none;appearance:none;border:0;cursor:pointer;font:inherit;font-weight:600;
    font-size:13px;padding:7px 18px;border-radius:999px;background:#87986a;color:#fff;}
  .lgc-edit-save:hover{background:#6b7c52;} .lgc-edit-save:disabled{opacity:.5;cursor:default;}
  .lgc-edit-cancel{background:none;border:0;cursor:pointer;font:inherit;font-size:13px;color:#8a857c;padding:0;}

  /* reactions */
  .lgc-meta{display:flex;align-items:center;gap:14px;margin-top:8px;flex-wrap:wrap;}
  .lgc-reactions{position:relative;display:inline-flex;align-items:center;gap:6px;}
  .lgc-rx-chips{display:inline-flex;align-items:center;gap:6px;}
  .lgc-rx{display:inline-flex;align-items:center;gap:4px;cursor:pointer;font:inherit;font-size:13px;
    line-height:1;padding:3px 9px 3px 7px;border:1px solid #e4ddcd;border-radius:999px;background:#fbfbf8;
    color:#6b6258;transition:background .12s,border-color .12s;}
  .lgc-rx:hover{background:#f4f1e8;}
  .lgc-rx.is-mine{background:#eef2e6;border-color:#bcc8a6;color:#56653c;}
  .lgc-rx-n{font-weight:600;font-variant-numeric:tabular-nums;}
  .lgc-rx-emoji{font-size:15px;line-height:1;}
  .lgc-rx-img{display:inline-block;width:20px;height:20px;object-fit:contain;vertical-align:middle;}
  .lgc-rx-add{display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font:inherit;
    font-size:14px;line-height:1;width:26px;height:24px;border:1px solid #e4ddcd;border-radius:999px;
    background:#fff;color:#9a948a;padding:0;}
  .lgc-rx-add span{font-size:11px;font-weight:700;margin-left:-1px;}
  .lgc-rx-add:hover{background:#f4f1e8;color:#6b7c52;}
  .lgc-rx-palette{position:absolute;left:0;bottom:calc(100% + 6px);z-index:5;display:flex;gap:2px;
    padding:6px;background:#fff;border:1px solid #e4ddcd;border-radius:14px;
    box-shadow:0 6px 22px rgba(26,29,26,.14);}
  .lgc-rx-palette[hidden]{display:none;}  /* hidden attr must win over display:flex (UA [hidden] loses to author rule) */
  .lgc-rx-opt{display:inline-flex;align-items:center;justify-content:center;cursor:pointer;border:0;
    background:none;padding:6px;border-radius:10px;font-size:20px;line-height:1;transition:background .1s;}
  .lgc-rx-opt:hover{background:#f1eee4;}
  /* logged-out: counts stay visible (read-only), interaction hidden */
  body.lgc-anon .lgc-rx-add{display:none;}
  body.lgc-anon .lgc-rx{pointer-events:none;}
  .lgc-empty{color:#9a948a;font-size:14px;margin:4px 0 18px;}
  img.emoji,img.wp-smiley{display:inline-block!important;width:1em!important;height:1em!important;
    margin:0 .07em!important;vertical-align:-0.1em!important;}
</style>
</head>
<body class="lgc-frame" data-post-type="<?= lg_c_h($postType) ?>" data-item-id="<?= (int) $itemId ?>">
<?php if (!$valid): ?>
  <p class="lgc-empty">Comments aren’t available here.</p>
<?php else: ?>
  <!-- composer (inert until JS resolves auth state from the write endpoint) -->
  <div class="lgc-compose" id="lgc-compose" hidden>
    <p class="lgc-replyto" id="lgc-replyto"></p>
    <textarea id="lgc-textarea" placeholder="Add a comment…" maxlength="6000"></textarea>
    <div class="lgc-actions">
      <span class="lgc-err" id="lgc-err"></span>
      <button type="button" class="lgc-submit" id="lgc-submit">Post comment</button>
    </div>
  </div>
  <p class="lgc-login" id="lgc-login" hidden><a href="/wp-login.php" target="_top">Log in</a> to join the conversation.</p>

  <ul class="lgc-list" id="lgc-list"><?= $threadHtml ?></ul>
  <?php if ($count === 0): ?><p class="lgc-empty" id="lgc-empty">No comments yet. Be the first.</p><?php endif; ?>
<?php endif; ?>

<script>
(function(){
  var body = document.body,
      postType = body.getAttribute('data-post-type'),
      itemId   = body.getAttribute('data-item-id'),
      WRITE    = '/archive-api/v0/comment-post';
  var compose = document.getElementById('lgc-compose'),
      login   = document.getElementById('lgc-login'),
      ta      = document.getElementById('lgc-textarea'),
      submit  = document.getElementById('lgc-submit'),
      errEl   = document.getElementById('lgc-err'),
      replyto = document.getElementById('lgc-replyto'),
      list    = document.getElementById('lgc-list');
  var nonce = '', parentId = 0, authed = false, myReactions = {};
  var REACT = '/archive-api/v0/comment-react',
      EDIT  = '/archive-api/v0/comment-edit',
      DELETE_= '/archive-api/v0/comment-delete';
  var myUid = 0, canModerate = false;
  var RX_PALETTE = <?= json_encode(lg_reactions_palette(), JSON_UNESCAPED_UNICODE) ?>,
      RX_BASE    = <?= json_encode(LG_REACTIONS_ASSET_BASE) ?>;

  /* height handshake — parent modal sizes the iframe to the thread. */
  function postHeight(){
    var h = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
    parent.postMessage({lgCommentsHeight:h}, location.origin);
  }
  window.addEventListener('load', postHeight);
  if (window.ResizeObserver) new ResizeObserver(postHeight).observe(document.body);
  else window.addEventListener('resize', postHeight);

  if (!compose) return; // invalid item — nothing to wire

  /* lazily resolve auth + nonce from the WP-pool write endpoint (gates on the WP
     login cookie, sent automatically — same origin). */
  fetch(WRITE + '?post_type=' + encodeURIComponent(postType) + '&item_id=' + encodeURIComponent(itemId),
        {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(function(r){ return r.ok ? r.json() : Promise.reject(); })
    .then(function(d){
      if (d && d.authenticated && d.nonce) {
        nonce = d.nonce; authed = true; compose.hidden = false;
        myReactions = d.my_reactions || {};
        myUid = d.wp_user_id || 0; canModerate = !!d.can_moderate;
        applyMine();
        applyOwnerActions();
      } else {
        login.hidden = false;
        document.body.classList.add('lgc-anon');  // hide react triggers for logged-out
      }
      postHeight();
    })
    .catch(function(){ login.hidden = false; document.body.classList.add('lgc-anon'); postHeight(); });

  /* ---- reactions ---- */
  function rxGlyph(rx){
    if (rx.type === 'image') return '<img class="lgc-rx-img" src="'+RX_BASE+esc(rx.file)+'" width="20" height="20" alt="">';
    return '<span class="lgc-rx-emoji">'+esc(rx.char)+'</span>';
  }
  function renderChips(bar, counts, mine){
    var chips = bar.querySelector('.lgc-rx-chips'); if (!chips) return;
    var html = '';
    for (var i=0;i<RX_PALETTE.length;i++){
      var rx = RX_PALETTE[i], n = counts[rx.slug]||0; if (n<=0) continue;
      html += '<button type="button" class="lgc-rx'+(mine===rx.slug?' is-mine':'')+'" data-slug="'+esc(rx.slug)+
              '" title="'+esc(rx.label)+'">'+rxGlyph(rx)+'<span class="lgc-rx-n">'+n+'</span></button>';
    }
    chips.innerHTML = html;
  }
  function applyMine(){
    Array.prototype.forEach.call(document.querySelectorAll('.lgc-reactions'), function(bar){
      var cid = bar.getAttribute('data-comment-id'), slug = myReactions[cid];
      Array.prototype.forEach.call(bar.querySelectorAll('.lgc-rx'), function(c){
        c.classList.toggle('is-mine', !!slug && c.getAttribute('data-slug') === slug);
      });
    });
  }
  /* ---- edit / delete (own comments; moderators see them on all) ---- */
  function canEdit(li){
    if (!authed) return false;
    if (canModerate) return true;
    return myUid > 0 && parseInt(li.getAttribute('data-author'),10) === myUid;
  }
  function applyOwnerActions(){
    Array.prototype.forEach.call(document.querySelectorAll('.lgc'), function(li){
      var own = li.querySelector(':scope > .lgc-body > .lgc-meta > .lgc-own');
      if (own) own.hidden = !canEdit(li);
    });
  }
  function commentIdOf(li){ return parseInt((li.id||'').replace('lgc-',''),10) || 0; }
  function reportCount(){
    var n = list ? list.querySelectorAll('.lgc').length : 0;
    parent.postMessage({lgCommentsCount:n}, location.origin);
  }
  function maybeEmpty(){
    if (list && !list.querySelector('.lgc') && !document.getElementById('lgc-empty')){
      var p = document.createElement('p'); p.className='lgc-empty'; p.id='lgc-empty';
      p.textContent = 'No comments yet. Be the first.';
      list.parentNode.insertBefore(p, list.nextSibling);
    }
  }
  function doDelete(li){
    var id = commentIdOf(li); if (!id) return;
    if (!confirm('Delete this comment? Any replies under it are hidden too.')) return;
    fetch(DELETE_, {method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
      body: JSON.stringify({comment_id:id, _wpnonce:nonce})})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.ok){ li.remove(); maybeEmpty(); reportCount(); postHeight(); }
        else alert('Could not delete. Try again.');
      })
      .catch(function(){ alert('Could not delete. Try again.'); });
  }
  function openEditor(li){
    var bodyEl = li.querySelector(':scope > .lgc-body');
    if (!bodyEl || bodyEl.querySelector(':scope > .lgc-editbox')) return; // already editing
    var textEl = bodyEl.querySelector(':scope > .lgc-text');
    var id = commentIdOf(li); if (!id) return;
    textEl.hidden = true;
    var box = document.createElement('div');
    box.className = 'lgc-editbox';
    box.innerHTML = '<textarea maxlength="6000"></textarea>'+
      '<div class="lgc-edit-actions"><span class="lgc-err"></span>'+
      '<button type="button" class="lgc-edit-cancel">Cancel</button>'+
      '<button type="button" class="lgc-edit-save">Save</button></div>';
    textEl.insertAdjacentElement('afterend', box);
    var taEdit = box.querySelector('textarea');
    taEdit.value = li.getAttribute('data-raw') || ''; taEdit.focus();
    var errE = box.querySelector('.lgc-err'), saveB = box.querySelector('.lgc-edit-save');
    box.querySelector('.lgc-edit-cancel').addEventListener('click', function(){ closeEditor(li); });
    saveB.addEventListener('click', function(){
      var text = (taEdit.value||'').trim(); errE.textContent = '';
      if (!text){ taEdit.focus(); return; }
      saveB.disabled = true;
      fetch(EDIT, {method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
        body: JSON.stringify({comment_id:id, body:text, _wpnonce:nonce})})
        .then(function(r){ return r.json(); })
        .then(function(j){
          saveB.disabled = false;
          if (j && j.ok){
            li.setAttribute('data-raw', j.body);
            textEl.innerHTML = esc(j.body).replace(/\n/g,'<br>');
            var ed = bodyEl.querySelector(':scope > .lgc-head > .lgc-time > .lgc-edited');
            if (ed) ed.hidden = false;
            closeEditor(li); postHeight();
          } else {
            errE.textContent = (j && j.error === 'auth_required') ? 'Please log in again.' : 'Could not save. Try again.';
          }
        })
        .catch(function(){ saveB.disabled = false; errE.textContent = 'Could not save. Try again.'; });
    });
    postHeight();
  }
  function closeEditor(li){
    var bodyEl = li.querySelector(':scope > .lgc-body'); if (!bodyEl) return;
    var box = bodyEl.querySelector(':scope > .lgc-editbox'); if (box) box.remove();
    var textEl = bodyEl.querySelector(':scope > .lgc-text'); if (textEl) textEl.hidden = false;
    postHeight();
  }
  if (list) list.addEventListener('click', function(e){
    var b;
    if ((b = e.target.closest('.lgc-del')))  { doDelete(b.closest('.lgc'));  return; }
    if ((b = e.target.closest('.lgc-edit'))) { openEditor(b.closest('.lgc')); return; }
  });

  function closePalettes(except){
    Array.prototype.forEach.call(document.querySelectorAll('.lgc-rx-palette'), function(p){
      if (p !== except) p.hidden = true;
    });
  }
  function doReact(bar, slug){
    if (!authed){ login.hidden = false; postHeight(); return; }
    var cid = bar.getAttribute('data-comment-id');
    fetch(REACT, {method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
      body: JSON.stringify({comment_id: parseInt(cid,10), slug: slug, _wpnonce: nonce})})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.ok){
          renderChips(bar, j.counts||{}, j.mine);
          if (j.mine) myReactions[cid] = j.mine; else delete myReactions[cid];
          postHeight();
        }
      })
      .catch(function(){});
  }
  if (list) list.addEventListener('click', function(e){
    var bar = e.target.closest('.lgc-reactions'); if (!bar) return;
    if (e.target.closest('.lgc-rx-add')){
      var pal = bar.querySelector('.lgc-rx-palette');
      var willOpen = pal.hidden; closePalettes(); pal.hidden = !willOpen; postHeight(); return;
    }
    var opt = e.target.closest('.lgc-rx-opt');
    if (opt){ doReact(bar, opt.getAttribute('data-slug')); closePalettes(); return; }
    var chip = e.target.closest('.lgc-rx');
    if (chip){ doReact(bar, chip.getAttribute('data-slug')); return; }
  });
  document.addEventListener('click', function(e){ if (!e.target.closest('.lgc-reactions')) closePalettes(); });

  /* empty reaction bar markup for a freshly-posted comment */
  function reactionBarHtml(id){
    var opts = '';
    for (var i=0;i<RX_PALETTE.length;i++){
      var rx = RX_PALETTE[i];
      opts += '<button type="button" class="lgc-rx-opt" data-slug="'+esc(rx.slug)+'" title="'+esc(rx.label)+'">'+rxGlyph(rx)+'</button>';
    }
    return '<div class="lgc-reactions" data-comment-id="'+id+'"><span class="lgc-rx-chips"></span>'+
           '<button type="button" class="lgc-rx-add" aria-label="Add reaction">&#9786;<span>+</span></button>'+
           '<span class="lgc-rx-palette" hidden>'+opts+'</span></div>';
  }

  /* reply targeting */
  if (list) list.addEventListener('click', function(e){
    var b = e.target.closest('.lgc-reply'); if(!b) return;
    parentId = parseInt(b.getAttribute('data-id'),10) || 0;
    replyto.innerHTML = 'Replying to ' + b.getAttribute('data-name') +
      ' <button type="button" id="lgc-cancel">cancel</button>';
    replyto.style.display = 'block';
    document.getElementById('lgc-cancel').addEventListener('click', clearReply);
    ta.focus();
  });
  function clearReply(){ parentId = 0; replyto.style.display='none'; replyto.innerHTML=''; }

  function esc(s){ return (s+'').replace(/[&<>"]/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

  submit && submit.addEventListener('click', function(){
    var text = (ta.value||'').trim();
    errEl.textContent = '';
    if (!text){ ta.focus(); return; }
    submit.disabled = true;
    fetch(WRITE, {method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
      body: JSON.stringify({post_type:postType, item_id:itemId, parent_id:parentId, body:text, _wpnonce:nonce})})
      .then(function(r){ return r.json().then(function(j){ return {ok:r.ok, j:j}; }); })
      .then(function(res){
        submit.disabled = false;
        if (!res.ok || !res.j || !res.j.ok){
          errEl.textContent = (res.j && res.j.error === 'auth_required')
            ? 'Please log in again.' : 'Could not post. Try again.';
          return;
        }
        appendComment(res.j.comment);
        ta.value = ''; clearReply(); postHeight();
      })
      .catch(function(){ submit.disabled = false; errEl.textContent = 'Could not post. Try again.'; });
  });

  function appendComment(c){
    if (!c) return;
    var empty = document.getElementById('lgc-empty'); if (empty) empty.remove();
    var li = document.createElement('li');
    li.className = 'lgc'; li.id = 'lgc-' + c.id;
    var av = c.avatar_url
      ? '<img class="lgc-av" src="'+esc(c.avatar_url)+'" width="36" height="36" alt="">'
      : '<span class="lgc-av lgc-av--ph">'+esc((c.author_name||'M').charAt(0).toUpperCase())+'</span>';
    var nm = c.slug ? '<a href="/u/'+esc(c.slug)+'" target="_top">'+esc(c.author_name)+'</a>' : esc(c.author_name);
    li.setAttribute('data-author', myUid);
    li.setAttribute('data-raw', c.body || '');
    li.innerHTML = '<div class="lgc-body"><div class="lgc-head">'+av+
      '<span class="lgc-name">'+nm+'</span><span class="lgc-time">'+esc(c.when)+
      '<span class="lgc-edited" title="Edited" hidden> (edited)</span></span></div>'+
      '<div class="lgc-text">'+esc(c.body).replace(/\n/g,'<br>')+'</div>'+
      '<div class="lgc-meta"><button type="button" class="lgc-reply" data-id="'+c.id+'" data-name="'+esc(c.author_name)+'">Reply</button>'+
      '<span class="lgc-own"><button type="button" class="lgc-edit">Edit</button><button type="button" class="lgc-del">Delete</button></span>'+
      reactionBarHtml(c.id)+'</div></div>';
    // nest under parent if present, else append to root
    var target = list;
    if (c.parent_id){
      var p = document.getElementById('lgc-' + c.parent_id);
      if (p){ var ul = p.querySelector(':scope > .lgc-children');
        if(!ul){ ul=document.createElement('ul'); ul.className='lgc-children'; p.appendChild(ul);} target = ul; }
    }
    target.appendChild(li);
    reportCount();
  }
})();
</script>
</body>
</html>
