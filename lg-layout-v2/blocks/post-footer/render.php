<?php
/**
 * blocks/post-footer/render.php
 *
 * Article footer: author card + "Keep reading" related grid.
 *
 * Author bio comes from the ACF `author_about` user-meta field; falls back
 * to WP's native `description` user meta. Social link slots match the
 * post-header — filterable via the same `lg_layout_v2_post_header_author_links`
 * filter so they stay in sync.
 *
 * Related posts: prefers v1's LG\Layout\RelatedPosts::pick_mix() (tag-
 * scored pool). When v1 isn't loaded, falls back to a same-category /
 * same-CPT query excluding the current post. Filterable via
 * `lg_layout_v2_post_footer_related_ids`.
 *
 * @var array $args  Parsed + validated props
 * @var array $ctx   Render context — includes post_id when in WP
 */

use LG\LayoutV2\Renderer;

$post_id = (int) ($ctx['post_id'] ?? 0);
$variant = is_string($args['variant'] ?? null) ? strtolower((string) $args['variant']) : 'variant-1';
if (!in_array($variant, ['variant-1', 'variant-2', 'variant-3'], true)) $variant = 'variant-1';

/* Defaults: manifest declares show_author/show_related = true, but the
   pipeline doesn't auto-inject defaults into args at render time. So
   treat *unset* as on; explicit false in the JSON still turns it off. */
$show_author   = array_key_exists('show_author',   $args) ? (bool) $args['show_author']   : true;
$show_related  = array_key_exists('show_related',  $args) ? (bool) $args['show_related']  : true;
$show_comments = array_key_exists('show_comments', $args) ? (bool) $args['show_comments'] : true;
$show_share    = array_key_exists('show_share',    $args) ? (bool) $args['show_share']    : true;

/* Share row data: build intent URLs to X, Facebook, email + a copy-link.
   Permalink + title come from the WP context. If neither is available
   (CLI harness / unsaved render) the share row is suppressed. */
$share_url   = '';
$share_title = '';
if (!empty($ctx['post_id']) && function_exists('get_permalink')) {
    $share_url   = (string) (get_permalink((int) $ctx['post_id']) ?: '');
    $share_title = (string) (get_the_title((int) $ctx['post_id']) ?: '');
}
$share_x  = $share_url !== '' ? 'https://twitter.com/intent/tweet?url=' . rawurlencode($share_url) . '&text=' . rawurlencode($share_title) : '';
$share_fb = $share_url !== '' ? 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($share_url) : '';
$share_em = $share_url !== '' ? 'mailto:?subject=' . rawurlencode($share_title) . '&body=' . rawurlencode($share_url) : '';

$related_count  = max(1, min(6, (int) ($args['related_count'] ?? 3)));
$related_head   = trim((string) ($args['related_heading']  ?? 'Keep reading'));
$author_cta_raw = trim((string) ($args['author_cta_label'] ?? 'More from {author} →'));

/* ── Author ──────────────────────────────────────────────────────── */
$author_id   = $post_id > 0 ? (int) get_post_field('post_author', $post_id) : 0;
$author      = $author_id ? get_userdata($author_id) : null;
$author_name = $author ? (string) $author->display_name : '';

$avatar_url = '';
if ($author_id) {
    $custom_avatar_id = (int) (get_user_meta($author_id, 'author_image', true) ?: 0);
    if ($custom_avatar_id > 0 && function_exists('wp_get_attachment_image_url')) {
        $avatar_url = (string) (wp_get_attachment_image_url($custom_avatar_id, 'medium') ?: '');
    }
    if ($avatar_url === '' && function_exists('get_avatar_url')) {
        $avatar_url = (string) (get_avatar_url($author_id, ['size' => 192]) ?: '');
    }
}

/* Bio: ACF `author_about` → WP `description` → empty. */
$bio = '';
if ($author_id) {
    $bio = trim((string) (get_user_meta($author_id, 'author_about', true) ?: ''));
    if ($bio === '') $bio = trim((string) (get_user_meta($author_id, 'description', true) ?: ''));
}

$pub_name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';

/* CTA link: prefer ACF `author_looth_group_profile`, else the Hub filtered to
   this author (matches by NAME — see post-header). Built directly so the
   standalone renderer (no WP filters) produces the same URL. */
$cta_url = '';
if ($author_id) {
    $cta_url = trim((string) (get_user_meta($author_id, 'author_looth_group_profile', true) ?: ''));
    if ($cta_url === '' && $author_name !== '') {
        $cta_url = '/hub/?author=' . rawurlencode($author_name);
    }
}
$cta_label = $author_name !== ''
    ? str_replace('{author}', $author_name, $author_cta_raw)
    : str_replace(' {author}', '', $author_cta_raw);

/* ── Author social links (same slots as post-header) ────────────── */
$link_slots = [
    'looth_group_profile' => [
        'meta_key' => 'author_looth_group_profile',
        'title'    => 'Looth Group profile',
        'svg'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>',
    ],
    'website'   => ['meta_key' => 'author_website',   'title' => 'Website',   'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2c3 3 3 17 0 20M12 2c-3 3-3 17 0 20"/></svg>'],
    'instagram' => ['meta_key' => 'author_instagram', 'title' => 'Instagram', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>'],
    'facebook'  => ['meta_key' => 'author_facebook',  'title' => 'Facebook',  'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 10h3l.5-3H13V5c0-.9.3-1.5 1.6-1.5H17V.8C16.6.7 15.3.5 13.9.5 11 .5 9.1 2.3 9.1 5.6V7H6v3h3.1v8H13v-8z"/></svg>'],
    'youtube'   => ['meta_key' => 'author_youtube',   'title' => 'YouTube',   'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21.6 7.2s-.2-1.4-.8-2c-.7-.8-1.5-.8-1.9-.8C16 4.2 12 4.2 12 4.2s-4 0-6.9.2c-.4.1-1.2.1-1.9.8-.6.6-.8 2-.8 2S2.2 8.8 2.2 10.5v1.5c0 1.7.2 3.3.2 3.3s.2 1.4.8 2c.7.8 1.7.7 2.1.8 1.6.2 6.7.2 6.7.2s4 0 6.9-.2c.4-.1 1.2-.1 1.9-.8.6-.6.8-2 .8-2s.2-1.7.2-3.3v-1.5c0-1.7-.2-3.3-.2-3.3zM10 14V8l5 3-5 3z"/></svg>'],
    'linktree'  => ['meta_key' => 'author_linktree',  'title' => 'Linktree',  'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M5 7l7 7 7-7M5 17l7-7 7 7"/></svg>'],
];
if (function_exists('apply_filters')) {
    $link_slots = apply_filters('lg_layout_v2_post_header_author_links', $link_slots, $author_id);
}
$author_links = [];
if ($author_id) {
    foreach ($link_slots as $slot) {
        $url = trim((string) get_user_meta($author_id, (string) $slot['meta_key'], true));
        if ($url === '') continue;
        $author_links[] = ['url' => $url, 'title' => (string) ($slot['title'] ?? ''), 'svg' => (string) ($slot['svg'] ?? '')];
    }
    /* Computed slots — mirrored from post-header. BP profile + the Hub
       filtered to this author (matches by NAME — see post-header). */
    if (function_exists('bp_core_get_user_domain')) {
        $bp_url = (string) bp_core_get_user_domain($author_id);
        if ($bp_url !== '') {
            $author_links[] = [
                'url'   => $bp_url,
                'title' => 'Member profile',
                'svg'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3 2.7-5.5 6-5.5s6 2.5 6 5.5"/><circle cx="17" cy="6" r="2.4"/><path d="M14 14c1-.6 2-.9 3-.9 2.4 0 4.4 1.7 4.4 4"/></svg>',
            ];
        }
    }
    $archive_url = $author_name !== '' ? ('/hub/?author=' . rawurlencode($author_name)) : '';
    if ($archive_url !== '') {
        $author_links[] = [
            'url'   => $archive_url,
            'title' => $author_name !== '' ? ('All posts by ' . $author_name) : 'All posts by this author',
            'svg'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h12M3 12h12M3 18h8"/><circle cx="19" cy="17" r="3"/><path d="M21.5 19.5L23 21"/></svg>',
        ];
    }
}

/* ── Related posts ───────────────────────────────────────────────── */
$related_ids = [];
if ($show_related && $post_id > 0) {
    /* v1 plugin's tag-scored pool, when present. */
    if (class_exists('LG\\Layout\\RelatedPosts')) {
        $related_ids = (array) call_user_func(['LG\\Layout\\RelatedPosts', 'pick_mix'], $post_id, $related_count);
    }
    /* Fallback: same primary category, then same CPT, exclude self. */
    if (!$related_ids && function_exists('get_the_terms')) {
        $cats   = get_the_terms($post_id, 'category');
        $cat_id = is_array($cats) && $cats ? (int) $cats[0]->term_id : 0;
        $q = new \WP_Query([
            'post_type'      => get_post_type($post_id) ?: 'post-imgcap',
            'post_status'    => 'publish',
            'posts_per_page' => $related_count,
            'post__not_in'   => [$post_id],
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'tax_query'      => $cat_id ? [['taxonomy' => 'category', 'terms' => [$cat_id]]] : [],
        ]);
        $related_ids = array_map('intval', (array) $q->posts);
    }
    if (function_exists('apply_filters')) {
        $related_ids = (array) apply_filters('lg_layout_v2_post_footer_related_ids', $related_ids, $post_id, $related_count);
    }
}

/* ── Editor-mode hook on the related heading ────────────────────── */
$editorMode  = !empty($ctx['editor_mode']);
$headEdit    = $editorMode ? ' data-lg-edit-prop="related_heading"' : '';

$safeName   = htmlspecialchars($author_name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$safePub    = htmlspecialchars($pub_name,    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
$safeHead   = htmlspecialchars($related_head, ENT_QUOTES, 'UTF-8');
$safeCtaTxt = htmlspecialchars(trim($cta_label), ENT_QUOTES, 'UTF-8');

$depth = (int) ($args['_depth'] ?? 1);
$ind   = Renderer::indent($depth);
?>
<?= $ind ?><footer class="lg-post-footer lg-post-footer--<?= $variant ?>">
<?= $ind ?>  <div class="lg-post-footer__end" aria-hidden="true"></div>

<?php
$can_edit = !empty($ctx['can_edit']);
/* When the viewer can edit, expose the current meta values on the
   author card as data-* attrs so the JS modal can pre-fill every
   field without an extra round-trip. URLs are public anyway (they
   render as visible link icons), so exposing the values to admins/
   authors via the DOM is fine. */
$author_meta_attrs = '';
if ($can_edit && $author_id) {
    $field_keys = ['author_about','author_looth_group_profile','author_website','author_instagram','author_facebook','author_youtube','author_linktree'];
    foreach ($field_keys as $k) {
        $v = (string) (get_user_meta($author_id, $k, true) ?: '');
        $author_meta_attrs .= ' data-meta-' . str_replace('_','-',$k) . '="' . Renderer::attr($v) . '"';
    }
}
?>
<?php if ($show_share && $share_url !== ''): ?>
<?= $ind ?>  <div class="lg-post-footer__share" role="group" aria-label="Share this post">
<?= $ind ?>    <span class="lg-post-footer__share-label">Share</span>
<?= $ind ?>    <a class="lg-post-footer__share-btn lg-post-footer__share-btn--x"  href="<?= Renderer::attr($share_x) ?>"  rel="noopener" target="_blank" title="Share on X" aria-label="Share on X"><?= \LG\LayoutV2\Icons::svg('x') ?></a>
<?= $ind ?>    <a class="lg-post-footer__share-btn lg-post-footer__share-btn--fb" href="<?= Renderer::attr($share_fb) ?>" rel="noopener" target="_blank" title="Share on Facebook" aria-label="Share on Facebook"><?= \LG\LayoutV2\Icons::svg('facebook') ?></a>
<?= $ind ?>    <a class="lg-post-footer__share-btn lg-post-footer__share-btn--em" href="<?= Renderer::attr($share_em) ?>" title="Share by email" aria-label="Share by email"><?= \LG\LayoutV2\Icons::svg('email') ?></a>
<?= $ind ?>    <button type="button" class="lg-post-footer__share-btn lg-post-footer__share-btn--copy" data-lg-share-copy data-lg-share-url="<?= Renderer::attr($share_url) ?>" title="Copy link" aria-label="Copy link"><?= \LG\LayoutV2\Icons::svg('link') ?></button>
<?= $ind ?>  </div>
<?php endif; ?>

<?php if ($show_author && $author_id): ?>
<?= $ind ?>  <aside class="lg-post-footer__author" data-author-id="<?= (int) $author_id ?>"<?= $author_meta_attrs ?>>
<?php if ($can_edit): ?>
<?= $ind ?>    <button type="button" class="lg-post-footer__author-edit" data-lg-author-edit title="Edit author bio" aria-label="Edit author bio">
<?= $ind ?>      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>
<?= $ind ?>    </button>
<?php endif; ?>
<?= $ind ?>    <div class="lg-post-footer__author-head">
<?php if ($avatar_url !== ''): ?>
<?= $ind ?>      <img class="lg-post-footer__avatar" src="<?= Renderer::attr($avatar_url) ?>" alt="" loading="lazy" />
<?php endif; ?>
<?= $ind ?>      <div class="lg-post-footer__id">
<?php if ($pub_name !== ''): ?>
<?= $ind ?>        <div class="lg-post-footer__pub"><?= $safePub ?></div>
<?php endif; ?>
<?php if ($author_name !== ''): ?>
<?= $ind ?>        <h3 class="lg-post-footer__name"><?= $safeName ?></h3>
<?php endif; ?>
<?= $ind ?>      </div>
<?php if ($author_links): ?>
<?= $ind ?>      <div class="lg-post-footer__links">
<?php foreach ($author_links as $link): ?>
<?= $ind ?>        <a href="<?= Renderer::attr($link['url']) ?>" title="<?= Renderer::attr($link['title']) ?>" rel="noopener" target="_blank"><?= $link['svg'] ?></a>
<?php endforeach; ?>
<?= $ind ?>      </div>
<?php endif; ?>
<?= $ind ?>    </div>
<?php if ($bio !== '' || ($cta_url !== '' && $safeCtaTxt !== '')): ?>
<?= $ind ?>    <div class="lg-post-footer__author-body">
<?php if ($bio !== ''): ?>
<?= $ind ?>      <p class="lg-post-footer__bio"><?= htmlspecialchars($bio, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php if ($cta_url !== '' && $safeCtaTxt !== ''): ?>
<?= $ind ?>      <a class="lg-post-footer__cta" href="<?= Renderer::attr($cta_url) ?>"><?= $safeCtaTxt ?></a>
<?php endif; ?>
<?= $ind ?>    </div>
<?php endif; ?>
<?= $ind ?>  </aside>
<?php endif; ?>

<?php if ($show_related && ($related_ids || $editorMode)): ?>
<?= $ind ?>  <p class="lg-post-footer__related-h"<?= $headEdit ?>><?= $safeHead ?></p>
<?= $ind ?>  <div class="lg-post-footer__carousel" data-lg-carousel>
<?= $ind ?>    <button type="button" class="lg-post-footer__carousel-btn lg-post-footer__carousel-btn--prev" data-lg-carousel-prev aria-label="Previous">&lsaquo;</button>
<?= $ind ?>    <div class="lg-post-footer__related-grid" data-lg-carousel-track>
<?php foreach ($related_ids as $rid):
    $rid    = (int) $rid;
    if (!$rid) continue;
    $url    = (string) get_permalink($rid);
    $title  = (string) get_the_title($rid);
    $img    = (string) (get_the_post_thumbnail_url($rid, 'medium_large') ?: '');
    $r_auth = (int) get_post_field('post_author', $rid);
    $r_name = $r_auth ? (string) get_the_author_meta('display_name', $r_auth) : '';
?>
<?= $ind ?>      <a class="lg-post-footer__card" href="<?= Renderer::attr($url) ?>">
<?php if ($img !== ''): ?>
<?= $ind ?>        <img class="lg-post-footer__card-img" src="<?= Renderer::attr($img) ?>" alt="" loading="lazy" />
<?php else: ?>
<?= $ind ?>        <div class="lg-post-footer__card-img" aria-hidden="true"></div>
<?php endif; ?>
<?= $ind ?>        <div class="lg-post-footer__card-body">
<?php if ($r_name !== ''): ?>
<?= $ind ?>          <div class="lg-post-footer__card-author"><?= htmlspecialchars($r_name, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?= $ind ?>          <h4 class="lg-post-footer__card-title"><?= $title /* get_the_title() already returns entity-safe HTML */ ?></h4>
<?= $ind ?>        </div>
<?= $ind ?>      </a>
<?php endforeach; ?>
<?= $ind ?>    </div>
<?= $ind ?>    <button type="button" class="lg-post-footer__carousel-btn lg-post-footer__carousel-btn--next" data-lg-carousel-next aria-label="Next">&rsaquo;</button>
<?= $ind ?>  </div>
<?php endif; ?>
<?php if ($post_id > 0):
    // The post-react target is this content item — SAME post_type+id the Hub card
    // reacts on, so a react here and on the card share one card_reactions count
    // (Ian 2026-06-11: "the reaction in post and on card should jive").
    $pf_pt = function_exists('get_post_type') ? (string) get_post_type($post_id) : '';
?>
<?= $ind ?>  <div class="lg-post-footer__hubbar">
<?= $ind ?>    <a class="lg-post-footer__hubback" href="/hub/" data-lg-hub-back>
<?= $ind ?>      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
<?= $ind ?>      <span>Back to the Hub</span>
<?= $ind ?>    </a>
<?php if ($pf_pt !== ''): ?>
<?= $ind ?>    <div class="lg-post-footer__react" data-lg-react data-pt="<?= Renderer::attr($pf_pt) ?>" data-id="<?= (int) $post_id ?>"></div>
<?php endif; ?>
<?= $ind ?>  </div>
<?= $ind ?>  <script>
(function(){
  if (window.__lgPfBar) return; window.__lgPfBar = 1;
  /* Back to the Hub: when the reader arrived FROM the hub, native back-nav restores
     their scroll + sort + filter exactly (Ian: "remember hub state"); otherwise the
     href="/hub/" sends them to the hub fresh (sort still persists via lg_hub_sort). */
  document.addEventListener('click', function(e){
    var a = e.target.closest && e.target.closest('[data-lg-hub-back]');
    if (!a) return;
    try {
      var r = document.referrer;
      if (r) { var u = new URL(r);
        if (u.origin === location.origin && /^\/hub(\/|$)/.test(u.pathname)) {
          e.preventDefault(); history.back();
        }
      }
    } catch(_){}
  });
  /* Post reactions — the SAME /archive-api/v0/card-react store + contract the Hub
     card uses (data-pt:data-id key), so a react on the post and on its card are one
     count ("should jive"). Self-contained: this page doesn't load forums.js. */
  var EP = '/archive-api/v0/card-react', RBASE = '/archive-poc/reactions/';
  var PALETTE = [
    {slug:'like',label:'Like',char:'👍'},
    {slug:'ouch',label:'Ouch',img:'ouch.png'},
    {slug:'wow',label:'Wow',char:'😮'},
    {slug:'lol',label:'LOL',char:'😂'},
    {slug:'shop',label:'Optimum',img:'shop.png'},
    {slug:'take-my-money',label:'Take my money',img:'take-my-money.png'},
    {slug:'brain',label:'Brain',char:'🧠'}
  ];
  function bySlug(s){ for (var i=0;i<PALETTE.length;i++) if (PALETTE[i].slug===s) return PALETTE[i]; return null; }
  function glyph(p){ return p.img ? '<img src="'+RBASE+p.img+'" alt="" width="18" height="18">' : '<span class="lg-pf-react__em">'+p.char+'</span>'; }
  document.querySelectorAll('[data-lg-react]').forEach(function(box){
    var pt = box.getAttribute('data-pt'), id = parseInt(box.getAttribute('data-id'),10), key = pt+':'+id;
    var st = { nonce:'', authed:false, mine:null, counts:{} };
    function total(){ var t=0; for (var k in st.counts) t += st.counts[k]||0; return t; }
    function render(){
      var t = total(), m = st.mine ? bySlug(st.mine) : null;
      box.innerHTML =
        '<button type="button" class="lg-pf-react__btn'+(m?' is-on':'')+'" aria-label="React">'
        + (m ? glyph(m) : '<span class="lg-pf-react__em">🙂</span>')
        + '<span class="lg-pf-react__lbl">'+(m?m.label:'React')+'</span>'
        + (t ? '<span class="lg-pf-react__n">'+t+'</span>' : '')
        + '</button>';
    }
    function openPicker(){
      if (box.querySelector('.lg-pf-react__pop')) return;
      var pop = document.createElement('div'); pop.className='lg-pf-react__pop';
      PALETTE.forEach(function(p){
        var b = document.createElement('button'); b.type='button';
        b.className='lg-pf-react__opt'+(st.mine===p.slug?' is-on':''); b.title=p.label; b.innerHTML=glyph(p);
        b.addEventListener('click', function(ev){ ev.stopPropagation(); pop.remove(); pick(p.slug); });
        pop.appendChild(b);
      });
      box.appendChild(pop);
      setTimeout(function(){ document.addEventListener('click', function h(ev){ if(!box.contains(ev.target)){ pop.remove(); document.removeEventListener('click',h); } }); }, 0);
    }
    function pick(slug){
      if (!st.authed){ location.href = '/wp-login.php?redirect_to='+encodeURIComponent(location.href); return; }
      fetch(EP, { method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/json', 'X-WP-Nonce': st.nonce },
        body: JSON.stringify({ post_type: pt, item_id: id, slug: slug, _wpnonce: st.nonce }) })
        .then(function(r){ return r.json(); })
        .then(function(d){ if (d && d.ok){ st.counts = d.counts||{}; st.mine = d.mine||null; render(); } })
        .catch(function(){});
    }
    box.addEventListener('click', function(e){ if (e.target.closest('.lg-pf-react__btn')) openPicker(); });
    render();
    fetch(EP+'?items='+encodeURIComponent(key), { credentials:'same-origin', headers:{ 'Accept':'application/json' } })
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(d){
        if (d){ st.authed = !!(d.authenticated && d.nonce); st.nonce = d.nonce||'';
          st.mine = (d.my_reactions && d.my_reactions[key]) || null;
          st.counts = (d.counts && d.counts[key]) || {}; }
        render();
      }).catch(function(){});
  });
})();
<?= $ind ?>  </script>
<?php endif; ?>
<?php if ($show_comments && $post_id > 0 && is_user_logged_in()): ?>
<?= $ind ?>  <section class="lg-post-footer__comments">
<?php
    /* Route comments_template() to our plugin-resident file. The filter is
       what BB uses too — overriding it inverts the hijack. comments_template()
       handles the comments query (populates $wp_query->comments) so
       have_comments() / wp_list_comments() work; a raw include skips that. */
    $lg_v2_comments_tpl = dirname(__DIR__, 2) . '/templates/comments-v2.php';
    $lg_v2_filter = function () use ($lg_v2_comments_tpl) { return $lg_v2_comments_tpl; };
    add_filter('comments_template', $lg_v2_filter, 99);
    comments_template('', true);
    remove_filter('comments_template', $lg_v2_filter, 99);
?>
<?= $ind ?>  </section>
<?php endif; ?>
<?= $ind ?></footer>
