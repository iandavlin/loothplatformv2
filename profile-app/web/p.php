<?php
declare(strict_types=1);

/**
 * /p/<slug> — practice page, BLOCK-MODEL render (parallel to web/u.php).
 *
 * Renders via looth_render_practice_blocks() (practice-header gate + block loop),
 * the same header-as-ceiling model as profiles. View-as (owner only) previews
 * Public / Member / Me by driving the one renderer with the selected role.
 * Practice owner = practices.created_by (or practice_members role='owner').
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_render_blocks.php';   // looth_render_practice_blocks + Block + looth_h/initials

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;
use Looth\ProfileApp\Db;

$slug = $_GET['slug'] ?? '';
if (!is_string($slug) || $slug === '') { http_response_code(404); echo 'not found'; exit; }

$pg = Db::pg();
$q = $pg->prepare('SELECT id, name, slug FROM practices WHERE slug = :s AND archived_at IS NULL');
$q->execute([':s' => $slug]);
$row = $q->fetch();
if (!$row && ctype_digit($slug)) {
    $q = $pg->prepare('SELECT id, name, slug FROM practices WHERE id = :i AND archived_at IS NULL');
    $q->execute([':i' => (int)$slug]);
    $row = $q->fetch();
}
if (!$row) { http_response_code(404); echo 'not found'; exit; }

$practiceId = (int) $row['id'];
$viewer     = Auth::currentUser();
$isOwner    = $viewer && Block::isPracticeOwner($practiceId, (int) $viewer['id']);

if ($isOwner) {
    $view = $_GET['view'] ?? 'me';
    $role = in_array($view, ['public', 'member', 'me'], true) ? $view : 'me';
} else {
    $role = $viewer ? 'member' : 'public';
}

$tierBadge   = null;   // practice tier badge n/a (no per-subject tier source yet)

// Owner edit mode (the builder) shows only on the owner's own "Me" view — same
// rule as the profile editor. $available powers the Sections caddy palette;
// $ownerSlug links back to the owner's profile editor.
$editing   = $isOwner && $role === 'me';
$ownerId   = Block::practiceOwnerId($practiceId);
$available = $editing ? Block::practiceAvailableBlocks($practiceId) : [];
$ownerSlug = '';
if ($ownerId !== null) {
    $os = $pg->prepare('SELECT slug FROM users WHERE id = :i');
    $os->execute([':i' => $ownerId]);
    $ownerSlug = (string) ($os->fetchColumn() ?: '');
}
$name        = (string) ($row['name'] ?: 'Practice');
$slugSafe    = (string) ($row['slug'] ?: (string)$practiceId);
$viewLink = fn(string $v): string => '/p/' . rawurlencode($slugSafe) . '?view=' . $v;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= looth_h($name) ?> · Looth</title>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>
<style>
body{margin:0;background:var(--lg-cream);color:var(--lg-ink);font-family:var(--lg-font-sans);font-size:calc(15px*var(--lg-read-scale,1));line-height:1.6}
/* The View-as bar and the first practice-header block are direct children of
   .lg-profile here (no wrapping shell like u.php). Establishing flow-root
   localises margin-collapse to the children and lets the viewas's margin-bottom
   actually render — fixes the same gap bug noted in briefing-profile-editor.md. */
.lg-shell{display:flex;flex-direction:column;gap:20px;max-width:760px;margin:0 auto;padding:24px 20px 48px}
.lg-profile{min-width:0;display:flow-root}

/* View-as toggle (owner only) — margin-bottom now reliable because .lg-profile is a flow-root. */
.lg-viewas{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--lg-charcoal);color:#cfd3cb;
  border-radius:12px;padding:10px 14px;margin:0;font:600 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans)}
.lg-viewas__label{font-weight:700}
.lg-viewas__seg{display:flex;border:1px solid rgba(255,255,255,.18);border-radius:999px;overflow:hidden}
.lg-viewas__seg a{padding:6px 14px;color:#cfd3cb;text-decoration:none;font:700 calc(12px*var(--lg-read-scale,1))/1 var(--lg-font-sans)}
.lg-viewas__seg a[aria-current="true"]{background:var(--lg-amber);color:#4a3c10}
.lg-viewas__edit{margin-left:auto;background:#fff;color:var(--lg-ink);border-radius:999px;padding:7px 15px;text-decoration:none;font:700 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans)}
.lg-viewas__hint{flex-basis:100%;font:500 calc(11px*var(--lg-read-scale,1))/1.4 var(--lg-font-sans);color:#9aa091}

/* block shell */
.lg-block{position:relative;background:var(--lg-card-bg,#fff);border:1px solid var(--lg-line);border-radius:16px;padding:22px 24px;margin:0 0 16px}
.lg-bh{margin:0 0 12px;font:800 calc(16px*var(--lg-read-scale,1))/1 var(--lg-font-serif);color:var(--lg-charcoal)}
.lg-vchip{display:inline-block;vertical-align:middle;font:800 calc(9px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;border-radius:5px;padding:3px 7px;margin-left:6px}
.lg-block--practice-header>.lg-vchip{position:absolute;top:14px;right:16px;margin:0}
.lg-vchip--public{background:var(--lg-sage-tint);color:var(--lg-sage-d)}
.lg-vchip--member{background:#fdf0d8;color:#8a6326}
.lg-vchip--private{background:#f0e6e2;color:var(--lg-rust)}

/* practice identity card */
.lg-idrow{display:flex;gap:20px;align-items:center}
.lg-idrow__pic{width:96px;height:96px;border-radius:18px;flex:none;background:var(--lg-sage);color:#fff;
  display:grid;place-items:center;font:700 34px/1 var(--lg-font-serif);overflow:hidden}
.lg-idrow__pic img{width:100%;height:100%;object-fit:cover;border-radius:18px}
.lg-idrow__name{margin:0;font:800 calc(28px*var(--lg-read-scale,1))/1.1 var(--lg-font-serif);color:var(--lg-charcoal);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.lg-ptype{font:800 calc(10px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.08em;text-transform:uppercase;background:var(--lg-charcoal);color:#fff;border-radius:999px;padding:5px 10px}
.lg-tierpill{font:800 calc(10px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;background:var(--lg-amber);color:#4a3c10;border-radius:6px;padding:4px 9px}
.lg-idrow__glance{font-size:calc(16px*var(--lg-read-scale,1));margin:6px 0 0;color:var(--lg-ink)}
.lg-loc__line{display:flex;align-items:center;gap:9px;font-size:calc(15px*var(--lg-read-scale,1));color:var(--lg-ink)}
.lg-idrow__web{font:600 calc(13.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-rust);margin-top:9px;display:inline-block;text-decoration:none}

/* members gate */
.lg-gate{text-align:center;background:var(--lg-card-bg,#fff);border:1px solid var(--lg-line);border-radius:18px;padding:48px 30px;margin:0 0 16px}
.lg-gate__lock{width:64px;height:64px;border-radius:50%;background:var(--lg-sage-tint);display:grid;place-items:center;margin:0 auto 16px;color:var(--lg-sage-d)}
.lg-gate h2{margin:0 0 8px;font:800 calc(22px*var(--lg-read-scale,1))/1.2 var(--lg-font-serif);color:var(--lg-charcoal)}
.lg-gate p{margin:0 auto 20px;max-width:420px;color:var(--lg-mute);font-size:calc(14.5px*var(--lg-read-scale,1))}
.lg-gate__cta{display:inline-flex;gap:10px}
.lg-gate__join{background:var(--lg-amber);color:#4a3c10;text-decoration:none;font:800 calc(14px*var(--lg-read-scale,1))/1 var(--lg-font-sans);border-radius:999px;padding:12px 22px}
.lg-gate__signin{border:1px solid var(--lg-line);color:var(--lg-ink);text-decoration:none;font:700 calc(14px*var(--lg-read-scale,1))/1 var(--lg-font-sans);border-radius:999px;padding:12px 22px}

/* interactive pmp control */
.lg-pmp{cursor:pointer;border:0;font-family:inherit;display:inline-flex;align-items:center;gap:4px}
.lg-pmp:hover{filter:brightness(.95)}
.lg-pmp__caret{font-size:8px;opacity:.8}
.lg-pmp-menu{position:absolute;z-index:60;min-width:210px;background:var(--lg-card-bg,#fff);border:1px solid var(--lg-line);border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.14);padding:6px}
.lg-pmp-menu__head{font:700 calc(10px*var(--lg-read-scale,1))/1.3 var(--lg-font-sans);text-transform:uppercase;letter-spacing:.06em;color:var(--lg-mute);padding:7px 9px 5px}
.lg-pmp-menu button{display:flex;width:100%;align-items:center;justify-content:space-between;gap:10px;border:0;background:none;cursor:pointer;padding:8px 9px;border-radius:7px;text-align:left;font:600 calc(13px*var(--lg-read-scale,1))/1.2 var(--lg-font-sans);color:var(--lg-ink)}
.lg-pmp-menu button:hover{background:var(--lg-sage-tint)}
.lg-pmp-menu button[aria-current="true"]{font-weight:800;color:var(--lg-sage-d)}
.lg-pmp-menu button[aria-current="true"]::after{content:"✓";color:var(--lg-sage-d)}
.lg-staff{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.lg-staff__lnk{display:flex;align-items:center;gap:12px;text-decoration:none;color:var(--lg-ink);padding:8px;border-radius:12px}
.lg-staff__lnk:hover{background:var(--lg-sage-tint)}
.lg-staff__avi{width:40px;height:40px;flex:none;border-radius:50%;background:var(--lg-sage);color:#fff;display:grid;place-items:center;font:700 15px/1 var(--lg-font-serif)}
.lg-staff__name{font:600 calc(15px*var(--lg-read-scale,1))/1.2 var(--lg-font-sans)}
.lg-staff__role{font:800 calc(9px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;background:var(--lg-sage-tint);color:var(--lg-sage-d);border-radius:5px;padding:3px 7px;margin-left:auto}
/* ---- owner builder chrome (ported from the profile editor for parity) ---- */
.lg-viewas__caddy{background:var(--lg-amber);color:#4a3c10;border:0;border-radius:999px;padding:6px 14px;font:800 calc(12px*var(--lg-read-scale,1))/1 var(--lg-font-sans);cursor:pointer}
.lg-viewas__caddy:hover{filter:brightness(1.06)}
.lg-block__grip{display:inline-grid;grid-template-columns:1fr 1fr;gap:2px;cursor:grab;vertical-align:middle;margin-right:9px;user-select:none}
.lg-block__grip i{display:block;width:3px;height:3px;border-radius:50%;background:var(--lg-sage-3)}
.lg-block__grip:hover i{background:var(--lg-sage-d)}
.lg-secic{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:8px;background:var(--lg-sage-tint);color:var(--lg-sage-d);vertical-align:middle;margin-right:9px}
.lg-block.lg-sort-dragging{cursor:grabbing;outline:2px dashed var(--lg-sage-3);outline-offset:2px}
.lg-block__rm{display:inline-block;border:0;background:none;cursor:pointer;color:var(--lg-mute);font:700 15px/1 var(--lg-font-sans);padding:0 4px;vertical-align:middle;margin-left:2px}
.lg-block__rm:hover{color:var(--lg-rust)}
.lg-block--drop-before{box-shadow:0 -3px 0 0 var(--lg-sage)}
.lg-block--drop-after{box-shadow:0 3px 0 0 var(--lg-sage)}
.lg-caddy{position:fixed;top:0;right:0;height:100vh;width:300px;max-width:86vw;background:var(--lg-card-bg,#fff);border-left:1px solid var(--lg-line);box-shadow:-12px 0 36px rgba(0,0,0,.14);transform:translateX(102%);transition:transform .22s ease;z-index:1200;display:flex;flex-direction:column;padding:18px}
.lg-caddy.is-open{transform:none}
.lg-caddy__backdrop{position:fixed;inset:0;background:rgba(20,22,18,.34);z-index:1190;opacity:0;transition:opacity .22s}
.lg-caddy__backdrop.is-open{opacity:1}
.lg-caddy__head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
.lg-caddy__head strong{font:800 calc(16px*var(--lg-read-scale,1))/1 var(--lg-font-serif);color:var(--lg-charcoal)}
.lg-caddy__close{border:0;background:none;font-size:24px;line-height:1;color:var(--lg-mute);cursor:pointer}
.lg-caddy__close:hover{color:var(--lg-ink)}
.lg-caddy__hint{font:500 calc(11.5px*var(--lg-read-scale,1))/1.5 var(--lg-font-sans);color:var(--lg-mute);margin:0 0 14px}
.lg-caddy__list{display:flex;flex-direction:column;gap:8px;overflow-y:auto}
.lg-caddy__grp{font:700 calc(10px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.12em;text-transform:uppercase;color:var(--lg-mute);margin:16px 2px 9px}
.lg-caddy__grp:first-child{margin-top:2px}
.lg-caddy__list .lg-bubble{display:flex;flex-direction:row;align-items:center;gap:10px;padding:8px 13px;background:var(--lg-sage-tint);border:1px solid transparent;border-radius:999px;cursor:grab;transition:border-color .15s,transform .12s,opacity .15s}
.lg-caddy__list .lg-bubble:hover{border-color:var(--lg-sage-3);transform:translateY(-1px)}
.lg-caddy__list .lg-bubble:active{transform:scale(.98)}
.lg-caddy__list .lg-bubble.is-used{opacity:.4;cursor:default;pointer-events:none}
.lg-caddy__list .lg-bubble.lg-sort-dragging{opacity:.45}
.lg-bubble__ic{width:27px;height:27px;border-radius:50%;background:var(--lg-sage);color:#fff;display:flex;align-items:center;justify-content:center;font:800 10px/1 var(--lg-font-sans);flex:0 0 auto}
.lg-bubble__lab{font:600 calc(13.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-sage-d)}
.lg-edit{cursor:text;border-radius:6px;outline:none;transition:background .12s,box-shadow .12s;padding:0 4px;margin:0 -4px}
.lg-edit:hover{background:var(--lg-sage-tint);box-shadow:0 0 0 3px var(--lg-sage-tint)}
.lg-edit--empty{color:var(--lg-mute);font-style:italic;font-weight:500}
.lg-edit.editing{background:var(--lg-card-bg,#fff);box-shadow:0 0 0 2px var(--lg-sage);font-style:normal;color:var(--lg-ink)}
.lg-edit.saved{box-shadow:0 0 0 2px var(--lg-sage-3)}
.lg-about{font-size:calc(14.5px*var(--lg-read-scale,1));line-height:1.6;color:var(--lg-ink);white-space:pre-wrap;max-width:640px}
@media(min-width:1380px){
  .lg-shell--owner{display:grid;max-width:1376px;margin:0 auto;column-gap:28px;row-gap:20px;align-items:start;grid-template-columns:280px minmax(0,760px) 280px;grid-template-areas:"viewas viewas viewas" "caddy profile spacer"}
  .lg-shell--owner .lg-viewas{grid-area:viewas;max-width:760px;width:100%;margin:0 auto}
  .lg-shell--owner .lg-profile{grid-area:profile;max-width:760px;width:100%}
  .lg-shell--owner .lg-caddy{grid-area:caddy;position:sticky;top:24px;left:auto;right:auto;box-sizing:border-box;width:auto;height:auto;max-height:calc(100vh - 48px);overflow-y:auto;transform:none;border:1px solid var(--lg-line);border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
  .lg-shell--owner .lg-caddy__close{display:none}
  .lg-viewas__caddy{display:none}
  .lg-caddy__backdrop{display:none}
}
@media(max-width:560px){.lg-idrow{flex-direction:column;text-align:center;align-items:center}}
/* drop-off locations (business storefront) */
.lg-dropoffs{display:flex;flex-direction:column;gap:12px;align-items:stretch}
.lg-dropoff{background:var(--lg-cream);border:1px solid var(--lg-line);border-radius:10px;padding:12px 14px}
.lg-dropoff__name{font:700 calc(15px*var(--lg-read-scale,1))/1.2 var(--lg-font-sans);color:var(--lg-ink)}
.lg-dropoff__addr{font:500 calc(13.5px*var(--lg-read-scale,1))/1.4 var(--lg-font-sans);color:var(--lg-charcoal);margin-top:3px}
.lg-dropoff__hours{font:600 calc(12.5px*var(--lg-read-scale,1))/1.3 var(--lg-font-sans);color:var(--lg-sage-d);margin-top:4px}
.lg-dropoff__notes{font:400 calc(13px*var(--lg-read-scale,1))/1.45 var(--lg-font-sans);color:var(--lg-mute);margin-top:5px}
.lg-dropoff--edit{position:relative;display:flex;flex-direction:column;gap:7px;padding-right:34px}
.lg-dropoff--edit .lg-dropoff__rm{position:absolute;top:8px;right:8px}
.lg-dropoff__f{width:100%;box-sizing:border-box;font:500 calc(13.5px*var(--lg-read-scale,1))/1.3 var(--lg-font-sans);color:var(--lg-ink);background:var(--lg-card-bg,#fff);border:1px solid var(--lg-line);border-radius:7px;padding:7px 9px}
.lg-dropoff__f:focus{outline:none;border-color:var(--lg-sage);box-shadow:0 0 0 2px var(--lg-sage-tint)}
.lg-dropoff__name-in{font-weight:700}
.lg-dropoff__notes-in{resize:vertical;min-height:38px;font-family:var(--lg-font-sans)}
.lg-dropoffs__map{margin:6px 0 14px;height:320px;border-radius:12px;border:1px solid var(--lg-line);overflow:hidden;background:var(--lg-sage-tint);position:relative;isolation:isolate;z-index:0}
.lg-dropoffs__map .leaflet-container{height:100%;border-radius:12px;font:inherit}
.lg-pinpop__name{display:block;font-weight:600;font-size:calc(14px*var(--lg-read-scale,1));color:var(--lg-ink);margin-bottom:2px}
.lg-pinpop__addr,.lg-pinpop__hours,.lg-pinpop__notes{font-size:calc(12.5px*var(--lg-read-scale,1));color:var(--lg-mute);line-height:1.4}
.lg-pinpop__hours{margin-top:3px}
.lg-pinpop__notes{margin-top:3px;font-style:italic}
.lg-link__rm{border:0;background:none;cursor:pointer;color:var(--lg-mute);font-size:18px;line-height:1;padding:0 4px}
.lg-link__rm:hover{color:var(--lg-rust)}
.lg-link__add{align-self:flex-start;border:1px dashed var(--lg-sage-3);background:none;cursor:pointer;border-radius:999px;padding:6px 14px;font:700 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-sage-d)}
.lg-link__add:hover{background:var(--lg-sage-tint);border-color:var(--lg-sage)}
/* business hours (weekly schedule) */
.lg-hours{display:flex;flex-direction:column;gap:2px}
.lg-hours__row{display:flex;align-items:center;gap:10px;padding:6px 2px;border-bottom:1px solid var(--lg-line)}
.lg-hours__row:last-child{border-bottom:0}
.lg-hours__day{flex:0 0 60px;font:700 calc(13.5px*var(--lg-read-scale,1))/1.2 var(--lg-font-sans);color:var(--lg-ink)}
.lg-hours__val{font:500 calc(13.5px*var(--lg-read-scale,1))/1.2 var(--lg-font-sans);color:var(--lg-charcoal)}
.lg-hours__val--closed{color:var(--lg-mute);font-style:italic}
.lg-hours--edit .lg-hours__row{flex-wrap:wrap;gap:8px}
.lg-hours__cl{display:inline-flex;align-items:center;gap:5px;font:600 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-mute)}
.lg-hours__t{font:500 calc(13px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-ink);background:var(--lg-card-bg,#fff);border:1px solid var(--lg-line);border-radius:7px;padding:5px 7px}
.lg-hours__t:focus{outline:none;border-color:var(--lg-sage);box-shadow:0 0 0 2px var(--lg-sage-tint)}
.lg-hours__sep{color:var(--lg-mute);font-size:calc(12.5px*var(--lg-read-scale,1))}
.lg-hours__times{display:inline-flex;align-items:center;gap:8px}
.lg-hours__note{margin-top:8px}
/* business links (website + socials) */
.lg-links{display:flex;flex-direction:column;gap:8px;align-items:stretch}
ul.lg-links{list-style:none;margin:0;padding:0;gap:6px}
.lg-links .lg-link{display:flex}
ul.lg-links .lg-link a{font:600 calc(14px*var(--lg-read-scale,1))/1.35 var(--lg-font-sans);color:var(--lg-sage-d);text-decoration:none;border-bottom:1px solid transparent;word-break:break-word}
ul.lg-links .lg-link a:hover{border-bottom-color:var(--lg-sage)}
.lg-link--edit{position:relative;flex-direction:column;gap:6px;background:var(--lg-cream);border:1px solid var(--lg-line);border-radius:10px;padding:10px 34px 10px 12px}
.lg-link--edit .lg-link__rm-abs{position:absolute;top:8px;right:8px}

/* ── DARK-MODE BRIDGE — mirrors web/u.php (see the comment there). Covers the
   pairings the flipped --lg-* tokens can't express on this page: the View-as
   slab, white-on-sage avatars, the charcoal practice-type pill, vis chips. */
html[data-lguser-theme="dark"] .lg-viewas{background:#22262a}
html[data-lguser-theme="dark"] .lg-viewas__edit{background:#2c312d;color:#f2f4ee}
html[data-lguser-theme="dark"] .lg-idrow__pic,
html[data-lguser-theme="dark"] .lg-staff__avi,
html[data-lguser-theme="dark"] .lg-bubble__ic{color:#15171a}
html[data-lguser-theme="dark"] .lg-ptype{background:#2c312d;color:#f2f4ee}
html[data-lguser-theme="dark"] .lg-vchip--member{background:#3a3220;color:#ecb351}
html[data-lguser-theme="dark"] .lg-vchip--private{background:#3a2a24;color:#d57a55}
</style>
</head>
<body class="mode-view">
<?php require __DIR__ . '/_chrome.php'; ?>

<main class="main" id="lg-main">
  <div class="lg-shell<?= $editing ? ' lg-shell--owner' : '' ?>">

    <?php if ($isOwner): ?>
      <div class="lg-viewas" role="group" aria-label="Preview your practice as">
        <span class="lg-viewas__label">View as</span>
        <span class="lg-viewas__seg">
          <a href="<?= looth_h($viewLink('public')) ?>" <?= $role==='public'?'aria-current="true"':'' ?>>Public</a>
          <a href="<?= looth_h($viewLink('member')) ?>" <?= $role==='member'?'aria-current="true"':'' ?>>Member</a>
          <a href="<?= looth_h($viewLink('me')) ?>"     <?= $role==='me'?'aria-current="true"':'' ?>>Me</a>
        </span>
        <?php if ($editing): ?>
        <button type="button" class="lg-viewas__caddy" id="lg-caddy-toggle" aria-expanded="false" aria-controls="lg-caddy">Sections</button>
        <a class="lg-viewas__edit" href="/u/<?= looth_h($ownerSlug) ?>?view=me">Edit profile</a>
        <?php endif; ?>
        <span class="lg-viewas__hint">Preview how this practice looks to each audience. “Public” shows the members-gate when the header is members-only.</span>
      </div>
    <?php endif; ?>

    <div class="lg-profile">
      <?php looth_render_practice_blocks($practiceId, $role, $tierBadge); ?>
    </div>

    <?php if ($editing): ?>
      <aside class="lg-caddy" id="lg-caddy" aria-hidden="true" aria-label="Add a section to your business page">
        <div class="lg-caddy__head"><strong>Sections</strong>
          <button type="button" class="lg-caddy__close" id="lg-caddy-close" aria-label="Close">&times;</button></div>
        <p class="lg-caddy__hint">Drag a section onto your page - or tap to add. Remove one with the &times; on its heading; it returns here.</p>
        <div class="lg-caddy__list" id="lg-caddy-list">
          <?php if (!empty($available)): ?>
            <h3 class="lg-caddy__grp">Sections</h3>
            <?php foreach ($available as $bk => $blabel): ?>
              <button type="button" class="lg-caddy__item lg-bubble" draggable="true" data-block="<?= looth_h((string)$bk) ?>">
                <span class="lg-bubble__ic" aria-hidden="true"><?= looth_h(strtoupper(substr((string)$blabel, 0, 1))) ?></span>
                <span class="lg-bubble__lab"><?= looth_h((string)$blabel) ?></span>
              </button>
            <?php endforeach; ?>
          <?php else: ?>
            <p class="lg-caddy__hint">All sections are on your page. Remove one to see it here.</p>
          <?php endif; ?>
        </div>
      </aside>
    <?php endif; ?>

  </div>
</main>

<?php if ($editing): ?><div class="lg-caddy__backdrop" id="lg-caddy-backdrop" hidden></div><?php endif; ?>

<?php lg_shared_render_site_footer(['logo_url' => LG_PROFILE_APP_LOGO_URL]); ?>

<?php if ($editing): ?>
<script>
/* lgSortable — handle-gated drag-to-reorder over native HTML5 DnD (ported from
   the profile editor so the business builder behaves identically). */
window.lgSortable = function (container, opts) {
  if (!container) return;
  var DCLASS = 'lg-sort-dragging';
  var dragging = null;
  function items() {
    return Array.prototype.slice.call(container.querySelectorAll(opts.itemSelector + ':not(.' + DCLASS + ')'));
  }
  function afterEl(y) {
    var best = { off: -Infinity, el: null };
    items().forEach(function (el) {
      var box = el.getBoundingClientRect(), off = y - box.top - box.height / 2;
      if (off < 0 && off > best.off) best = { off: off, el: el };
    });
    return best.el;
  }
  function clearHandleFlags() {
    Array.prototype.forEach.call(container.querySelectorAll(opts.itemSelector + '[draggable="true"]'), function (el) {
      if (!el.classList.contains(DCLASS)) el.removeAttribute('draggable');
    });
  }
  if (opts.handleSelector) {
    container.addEventListener('mousedown', function (e) {
      var h = e.target.closest(opts.handleSelector);
      if (!h || !container.contains(h)) return;
      var el = h.closest(opts.itemSelector);
      if (el) el.setAttribute('draggable', 'true');
    });
    container.addEventListener('mouseup', clearHandleFlags);
  }
  container.addEventListener('dragstart', function (e) {
    var el = e.target.closest(opts.itemSelector);
    if (!el || !container.contains(el)) return;
    dragging = el; el.classList.add(DCLASS);
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', ''); } catch (_) {}
  });
  container.addEventListener('dragover', function (e) {
    if (!dragging) return;
    e.preventDefault();
    var ref = afterEl(e.clientY);
    var tail = opts.tailSelector ? container.querySelector(opts.tailSelector) : null;
    container.insertBefore(dragging, ref || tail);
  });
  container.addEventListener('drop', function (e) { if (dragging) e.preventDefault(); });
  container.addEventListener('dragend', function () {
    if (!dragging) return;
    var moved = dragging; dragging = null;
    moved.classList.remove(DCLASS);
    if (opts.handleSelector) clearHandleFlags();
    if (opts.onDrop) opts.onDrop(moved);
  });
};
</script>

<script>
/* Owner layout controls for the business page — whole-block reorder (grip),
   per-block remove, and the Sections caddy (tap-to-add or drag onto the page).
   Order persists to /me/practice-layout (no reload); add/remove reload so the
   server re-renders. practice-header + the auto staff roster are pinned. */
(function () {
  var PID = <?= (int)$practiceId ?>;
  var LAYOUT_URL = '/profile-api/v0/me/practice-layout?practice=' + PID;
  var profile = document.querySelector('.lg-profile');
  if (!profile) return;
  var SEL = '.lg-block:not(.lg-block--practice-header):not(.lg-block--staff)';

  function bodyBlocks() { return Array.prototype.slice.call(profile.querySelectorAll(SEL)); }
  function order() { return bodyBlocks().map(function (s) { return s.getAttribute('data-block'); }).filter(Boolean); }
  function putLayout(arr, then) {
    fetch(LAYOUT_URL, { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ order: arr }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (res.ok) { if (then) then(); } else alert('Save failed: ' + (res.j && res.j.error || '?')); })
      .catch(function () { alert('Network error.'); });
  }

  var SECIC_PATHS = {
    about: '<circle cx="12" cy="8" r="3.5"/><path d="M5.5 19a6.5 6.5 0 0 1 13 0"/>'
  };
  function icFor(key) {
    var p = SECIC_PATHS[key];
    return p ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + p + '</svg>' : '';
  }

  bodyBlocks().forEach(function (b) {
    var host = b.querySelector('.lg-bh') || b;
    if (!host.querySelector('.lg-block__grip')) {
      var grip = document.createElement('span');
      grip.className = 'lg-block__grip'; grip.setAttribute('title', 'Drag to reorder'); grip.setAttribute('aria-hidden', 'true');
      grip.innerHTML = '<i></i><i></i><i></i><i></i><i></i><i></i>';
      host.insertBefore(grip, host.firstChild);
    }
    if (!host.querySelector('.lg-secic')) {
      var ic = icFor(b.getAttribute('data-block'));
      if (ic) {
        var chip = document.createElement('span'); chip.className = 'lg-secic'; chip.setAttribute('aria-hidden', 'true'); chip.innerHTML = ic;
        var g = host.querySelector('.lg-block__grip');
        host.insertBefore(chip, g ? g.nextSibling : host.firstChild);
      }
    }
    if (!host.querySelector('.lg-block__rm')) {
      var rm = document.createElement('button');
      rm.type = 'button'; rm.className = 'lg-block__rm';
      rm.setAttribute('title', 'Remove this block'); rm.setAttribute('aria-label', 'Remove block');
      rm.innerHTML = '&times;';
      var grip2 = host.querySelector('.lg-block__grip');
      host.insertBefore(rm, grip2 ? grip2.nextSibling : host.firstChild);
    }
  });

  profile.addEventListener('click', function (e) {
    var rm = e.target.closest('.lg-block__rm');
    if (!rm) return;
    var block = rm.closest(SEL);
    if (!block) return;
    var key = block.getAttribute('data-block');
    putLayout(order().filter(function (k) { return k !== key; }), function () { location.reload(); });
  });

  lgSortable(profile, { itemSelector: SEL, handleSelector: '.lg-block__grip', onDrop: function () { putLayout(order()); } });

  var caddy = document.getElementById('lg-caddy');
  var toggle = document.getElementById('lg-caddy-toggle');
  var backdrop = document.getElementById('lg-caddy-backdrop');
  function openCaddy() {
    if (!caddy) return;
    caddy.classList.add('is-open'); caddy.setAttribute('aria-hidden', 'false');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
    if (backdrop) { backdrop.hidden = false; requestAnimationFrame(function () { backdrop.classList.add('is-open'); }); }
  }
  function closeCaddy() {
    if (!caddy) return;
    caddy.classList.remove('is-open'); caddy.setAttribute('aria-hidden', 'true');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    if (backdrop) { backdrop.classList.remove('is-open'); setTimeout(function () { backdrop.hidden = true; }, 220); }
  }
  var deskMq = window.matchMedia('(min-width:1380px)');
  function syncCaddyMode() {
    if (!caddy) return;
    if (deskMq.matches) {
      caddy.classList.remove('is-open'); caddy.setAttribute('aria-hidden', 'false');
      if (backdrop) { backdrop.classList.remove('is-open'); backdrop.hidden = true; }
    } else if (!caddy.classList.contains('is-open')) {
      caddy.setAttribute('aria-hidden', 'true');
    }
  }
  if (toggle && caddy) {
    toggle.addEventListener('click', function () { caddy.classList.contains('is-open') ? closeCaddy() : openCaddy(); });
    var closeBtn = document.getElementById('lg-caddy-close');
    if (closeBtn) closeBtn.addEventListener('click', closeCaddy);
    if (backdrop) backdrop.addEventListener('click', closeCaddy);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !deskMq.matches && caddy.classList.contains('is-open')) closeCaddy(); });
    if (deskMq.addEventListener) deskMq.addEventListener('change', syncCaddyMode);
    if (!deskMq.matches && location.hash === '#caddy') openCaddy();
    syncCaddyMode();
  }

  function addBlock(key, atIndex) {
    var cur = order().filter(function (k) { return k !== key; });
    if (typeof atIndex !== 'number' || atIndex < 0 || atIndex > cur.length) atIndex = cur.length;
    cur.splice(atIndex, 0, key);
    putLayout(cur, function () { location.hash = 'caddy'; location.reload(); });
  }
  var list = document.getElementById('lg-caddy-list');
  if (list) {
    list.addEventListener('click', function (e) {
      var item = e.target.closest('.lg-caddy__item');
      if (!item || item.classList.contains('is-used')) return;
      addBlock(item.getAttribute('data-block'));
    });
  }

  var caddyDragKey = null, pendingIndex = null;
  function clearDropMarks() {
    Array.prototype.forEach.call(profile.querySelectorAll('.lg-block--drop-before,.lg-block--drop-after'), function (el) {
      el.classList.remove('lg-block--drop-before', 'lg-block--drop-after');
    });
  }
  function dropIndex(y) {
    var blocks = bodyBlocks();
    for (var i = 0; i < blocks.length; i++) {
      var box = blocks[i].getBoundingClientRect();
      if (y < box.top + box.height / 2) { blocks[i].classList.add('lg-block--drop-before'); return i; }
    }
    if (blocks.length) blocks[blocks.length - 1].classList.add('lg-block--drop-after');
    return blocks.length;
  }
  if (list) {
    list.addEventListener('dragstart', function (e) {
      var item = e.target.closest('.lg-caddy__item');
      if (!item || item.classList.contains('is-used')) return;
      caddyDragKey = item.getAttribute('data-block');
      item.classList.add('lg-sort-dragging');
      e.dataTransfer.effectAllowed = 'copy';
      try { e.dataTransfer.setData('text/plain', caddyDragKey); } catch (_) {}
    });
    list.addEventListener('dragend', function () {
      caddyDragKey = null; clearDropMarks();
      Array.prototype.forEach.call(list.querySelectorAll('.lg-sort-dragging'), function (el) { el.classList.remove('lg-sort-dragging'); });
    });
  }
  profile.addEventListener('dragover', function (e) {
    if (!caddyDragKey) return;
    e.preventDefault(); e.dataTransfer.dropEffect = 'copy';
    clearDropMarks(); pendingIndex = dropIndex(e.clientY);
  });
  profile.addEventListener('drop', function (e) {
    if (!caddyDragKey) return;
    e.preventDefault();
    var key = caddyDragKey, idx = pendingIndex;
    caddyDragKey = null; clearDropMarks();
    addBlock(key, idx);
  });
})();
</script>

<script>
/* Inline per-block privacy (pmp) control — business page. Mirrors the profile
   editor; persists via the practice endpoints, then reloads so the server
   re-derives the header ceiling + gate (keeps View-as honest). */
(function () {
  var BASE = '/profile-api/v0', PID = <?= (int)$practiceId ?>;
  var EP = {
    'practice-header': { url: BASE + '/me/practice-header?practice=' + PID, m: 'PATCH', k: 'visibility' },
    'practice-about':  { url: BASE + '/me/practice-about?practice='  + PID, m: 'PATCH', k: 'visibility' },
    'practice-dropoffs': { url: BASE + '/me/practice-block?practice=' + PID + '&block=dropoffs', m: 'PUT', k: 'visibility' },
    'practice-location': { url: BASE + '/me/practice-block?practice=' + PID + '&block=location', m: 'PUT', k: 'visibility' },
    'practice-hours':    { url: BASE + '/me/practice-block?practice=' + PID + '&block=hours',    m: 'PUT', k: 'visibility' },
    'practice-links':    { url: BASE + '/me/practice-block?practice=' + PID + '&block=links',    m: 'PUT', k: 'visibility' }
  };
  var TIERS = ['public', 'members', 'private'];
  var LABEL = { 'public': 'Public', 'members': 'Member', 'private': 'Private' };
  var RANK = { 'public': 0, 'members': 1, 'private': 2 };

  var openMenu = null;
  function closeMenu() { if (openMenu) { openMenu.remove(); openMenu = null; } }
  document.addEventListener('click', function (e) {
    if (openMenu && !openMenu.contains(e.target) && !e.target.closest('.lg-pmp')) closeMenu();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });

  function buildMenu(btn) {
    var current = btn.getAttribute('data-pmp-vis');
    var ceiling = btn.getAttribute('data-pmp-ceiling') || '';
    var menu = document.createElement('div'); menu.className = 'lg-pmp-menu'; menu.setAttribute('role', 'menu');
    menu.innerHTML = '<div class="lg-pmp-menu__head">Who can see this</div>';
    TIERS.forEach(function (tier) {
      var capped = ceiling && RANK[tier] < RANK[ceiling];
      var b = document.createElement('button'); b.type = 'button'; b.setAttribute('role', 'menuitemradio');
      if (tier === current) b.setAttribute('aria-current', 'true');
      b.innerHTML = '<span>' + LABEL[tier] + '</span>' + (capped ? '<span class="cap">limited by header</span>' : '');
      b.addEventListener('click', function () { if (tier === current) { closeMenu(); return; } save(btn, tier); });
      menu.appendChild(b);
    });
    return menu;
  }
  function save(btn, tier) {
    var ep = EP[btn.getAttribute('data-pmp-block')]; if (!ep) return;
    var body = {}; body[ep.k] = tier;
    btn.disabled = true;
    fetch(ep.url, { method: ep.m, credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (res.ok) location.reload(); else { btn.disabled = false; alert('Could not change visibility: ' + (res.j && res.j.error || '?')); } })
      .catch(function () { btn.disabled = false; alert('Network error.'); });
  }
  document.querySelectorAll('.lg-pmp').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var wasOpen = openMenu && openMenu._owner === btn; closeMenu(); if (wasOpen) return;
      var menu = buildMenu(btn); menu._owner = btn; document.body.appendChild(menu);
      var r = btn.getBoundingClientRect();
      menu.style.top = (window.scrollY + r.bottom + 6) + 'px';
      menu.style.left = (window.scrollX + Math.min(r.left, document.documentElement.clientWidth - 230)) + 'px';
      openMenu = menu;
    });
  });
})();
</script>

<script>
/* Inline content editing (owner/Me) — click any .lg-edit field, it becomes
   contentEditable, Enter/blur saves via the field's own data-edit-url, Esc cancels. */
(function () {
  function caretEnd(el) {
    var r = document.createRange(); r.selectNodeContents(el); r.collapse(false);
    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
  }
  function restorePlaceholder(el) {
    var ph = el.getAttribute('data-edit-placeholder') || '';
    if (ph && el.textContent.trim() === '') { el.textContent = ph; el.classList.add('lg-edit--empty'); }
  }
  function finish(el) { el.contentEditable = 'false'; el.classList.remove('editing'); }
  function save(el, val, orig) {
    var field = el.getAttribute('data-edit-field');
    var body = {}; body[field] = val;
    fetch(el.getAttribute('data-edit-url'), {
      method: el.getAttribute('data-edit-method') || 'PATCH', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        finish(el);
        if (res.ok) { el.classList.add('saved'); setTimeout(function () { el.classList.remove('saved'); }, 900); }
        else { el.textContent = orig; alert('Save failed: ' + (res.j && res.j.error || '?')); }
        restorePlaceholder(el);
      })
      .catch(function () { finish(el); el.textContent = orig; restorePlaceholder(el); alert('Network error.'); });
  }
  function valOf(el) { return (el.hasAttribute('data-edit-multiline') ? el.innerText : el.textContent).trim(); }
  function onKey(e) {
    var el = e.target;
    if (e.key === 'Enter' && !el.hasAttribute('data-edit-multiline')) { e.preventDefault(); el.blur(); }
    else if (e.key === 'Escape') {
      e.preventDefault(); el.removeEventListener('keydown', onKey);
      el.textContent = el.dataset.orig || ''; finish(el); restorePlaceholder(el);
    }
  }
  document.querySelectorAll('.lg-edit[data-edit-field]').forEach(function (el) {
    if (el.getAttribute('data-edit-type') === 'richtext') return;   // handled by the Quill editor (_richedit.php)
    el.setAttribute('title', 'Click to edit');
    el.addEventListener('click', function () {
      if (el.classList.contains('editing')) return;
      var wasEmpty = el.classList.contains('lg-edit--empty');
      el.dataset.orig = wasEmpty ? '' : valOf(el);
      if (wasEmpty) { el.textContent = ''; el.classList.remove('lg-edit--empty'); }
      el.classList.add('editing'); el.contentEditable = 'true'; el.focus(); caretEnd(el);
      el.addEventListener('keydown', onKey);
      el.addEventListener('blur', function onBlur(e) {
        el.removeEventListener('keydown', onKey); el.removeEventListener('blur', onBlur);
        var val = valOf(el), orig = el.dataset.orig || '';
        if (val === orig) { finish(el); restorePlaceholder(el); } else { save(el, val, orig); }
      });
    });
  });
})();
</script>
<script>
/* Drop-offs editor (owner/Me) — add/remove/edit cards; PUT the whole list to the
   generic practice-block endpoint. Ported from the profile lane (u.php). */
(function () {
  var wrap = document.getElementById('lg-dropoffs-edit');
  if (!wrap) return;
  var PID = <?= (int)$practiceId ?>;
  var URL = '/profile-api/v0/me/practice-block?practice=' + PID + '&block=dropoffs';
  var addBtn = document.getElementById('lg-dropoff-add');
  function collect() {
    return Array.prototype.map.call(wrap.querySelectorAll('.lg-dropoff'), function (card) {
      function v(f) { var el = card.querySelector('[data-f="' + f + '"]'); return el ? el.value : ''; }
      return { name: v('name'), address: v('address'), hours: v('hours'), notes: v('notes') };
    });
  }
  function cardEl() {
    var card = document.createElement('div'); card.className = 'lg-dropoff lg-dropoff--edit';
    var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'lg-link__rm lg-dropoff__rm';
    rm.setAttribute('aria-label', 'Remove drop-off'); rm.title = 'Remove drop-off'; rm.textContent = '\u00d7';
    card.appendChild(rm);
    function inp(f, ph, cls) {
      var el = document.createElement('input'); el.type = 'text';
      el.className = 'lg-dropoff__f' + (cls ? ' ' + cls : '');
      el.setAttribute('data-f', f); el.placeholder = ph; return el;
    }
    card.appendChild(inp('name', 'Location name (e.g. The Shop)', 'lg-dropoff__name-in'));
    card.appendChild(inp('address', 'Street address', ''));
    card.appendChild(inp('hours', 'Hours (e.g. Mon\u2013Fri 9\u20135)', ''));
    var ta = document.createElement('textarea');
    ta.className = 'lg-dropoff__f lg-dropoff__notes-in';
    ta.setAttribute('data-f', 'notes'); ta.rows = 2; ta.placeholder = 'Notes (optional)';
    card.appendChild(ta);
    return card;
  }
  function put(items) {
    fetch(URL, { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ items: items }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (!res.ok) alert('Save failed: ' + (res.j && res.j.error || '?')); })
      .catch(function () { alert('Network error.'); });
  }
  wrap.addEventListener('click', function (e) {
    var rm = e.target.closest('.lg-dropoff__rm');
    if (rm) { rm.closest('.lg-dropoff').remove(); put(collect()); }
  });
  wrap.addEventListener('change', function (e) {
    if (e.target.closest('.lg-dropoff')) put(collect());
  });
  addBtn && addBtn.addEventListener('click', function () {
    var card = cardEl();
    wrap.insertBefore(card, addBtn);
    var f = card.querySelector('[data-f="name"]'); if (f) f.focus();
  });
})();
</script>
<script>
/* Location editor (owner/Me) — one geocoded address + hours + note; PUT on field
   blur to the generic practice-block endpoint (server geocodes the address). */
(function () {
  var wrap = document.getElementById('lg-ploc-edit');
  if (!wrap) return;
  var PID = <?= (int)$practiceId ?>;
  var URL = '/profile-api/v0/me/practice-block?practice=' + PID + '&block=location';
  function val(f) { var el = wrap.querySelector('[data-f="' + f + '"]'); return el ? el.value : ''; }
  wrap.addEventListener('change', function () {
    fetch(URL, { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ address: val('address'), hours: val('hours'), note: val('note') }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (!res.ok) alert('Save failed: ' + (res.j && res.j.error || '?')); })
      .catch(function () { alert('Network error.'); });
  });
})();
</script>
<script>
/* Hours editor (owner/Me) — collect the 7-day grid + note; PUT the whole schedule
   to the generic practice-block endpoint on any change. */
(function () {
  var wrap = document.getElementById('lg-phours-edit');
  if (!wrap) return;
  var PID = <?= (int)$practiceId ?>;
  var URL = '/profile-api/v0/me/practice-block?practice=' + PID + '&block=hours';
  function collect() {
    var days = Array.prototype.map.call(wrap.querySelectorAll('.lg-hours__row'), function (row) {
      function g(f) { var el = row.querySelector('[data-f="' + f + '"]'); return el ? el.value : ''; }
      var cl = row.querySelector('[data-f="closed"]');
      return { o: g('open'), c: g('close'), x: !!(cl && cl.checked) };
    });
    var nt = wrap.querySelector('[data-f="note"]');
    return { days: days, note: nt ? nt.value : '' };
  }
  wrap.addEventListener('change', function () {
    fetch(URL, { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(collect()) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (!res.ok) alert('Save failed: ' + (res.j && res.j.error || '?')); })
      .catch(function () { alert('Network error.'); });
  });
})();
</script>
<script>
/* Links editor (owner/Me) — add/remove/edit {label,url} rows; PUT the whole list
   to the generic practice-block endpoint (server sanitizes URLs). */
(function () {
  var wrap = document.getElementById('lg-plinks-edit');
  if (!wrap) return;
  var PID = <?= (int)$practiceId ?>;
  var URL = '/profile-api/v0/me/practice-block?practice=' + PID + '&block=links';
  var addBtn = document.getElementById('lg-plink-add');
  function collect() {
    return Array.prototype.map.call(wrap.querySelectorAll('.lg-link--edit'), function (row) {
      function v(f) { var el = row.querySelector('[data-f="' + f + '"]'); return el ? el.value : ''; }
      return { label: v('label'), url: v('url') };
    });
  }
  function rowEl() {
    var row = document.createElement('div'); row.className = 'lg-link lg-link--edit';
    var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'lg-link__rm lg-link__rm-abs';
    rm.setAttribute('aria-label', 'Remove link'); rm.title = 'Remove link'; rm.textContent = '\u00d7';
    row.appendChild(rm);
    function inp(f, ph) { var el = document.createElement('input'); el.type = 'text'; el.className = 'lg-dropoff__f'; el.setAttribute('data-f', f); el.placeholder = ph; return el; }
    row.appendChild(inp('label', 'Label (e.g. Website, Instagram)'));
    row.appendChild(inp('url', 'https://...'));
    return row;
  }
  function put(items) {
    fetch(URL, { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ items: items }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (!res.ok) alert('Save failed: ' + (res.j && res.j.error || '?')); })
      .catch(function () { alert('Network error.'); });
  }
  wrap.addEventListener('click', function (e) {
    var rm = e.target.closest('.lg-link__rm');
    if (rm) { var row = rm.closest('.lg-link--edit'); if (row) { row.remove(); put(collect()); } }
  });
  wrap.addEventListener('change', function (e) {
    if (e.target.closest('.lg-link--edit')) put(collect());
  });
  addBtn && addBtn.addEventListener('click', function () {
    var row = rowEl(); wrap.insertBefore(row, addBtn);
    var f = row.querySelector('[data-f="label"]'); if (f) f.focus();
  });
})();
</script>
<?php require __DIR__ . '/_richedit.php'; /* practice About rich-text editor (owner-only; lazy Quill) */ ?>
<?php endif; ?>
<script>
/* Drop-off Locations map — Leaflet + OSM tiles. Reads pins from the
   .lg-dropoffs__map[data-pins] div; runs for visitor and owner alike. */
window.addEventListener('load', function () {
  if (typeof L === 'undefined') return;
  document.querySelectorAll('.lg-dropoffs__map[data-pins]').forEach(function (el) {
    var pins;
    try { pins = JSON.parse(el.getAttribute('data-pins')); } catch (e) { return; }
    if (!Array.isArray(pins) || !pins.length) return;
    var esc = function (v) { var d = document.createElement('div'); d.textContent = (v == null) ? '' : String(v); return d.innerHTML; };
    var map = L.map(el, { scrollWheelZoom: false }).setView([pins[0].lat, pins[0].lng], 11);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '\u00a9 OpenStreetMap' }).addTo(map);
    var markers = [];
    pins.forEach(function (p) {
      if (typeof p.lat !== 'number' || typeof p.lng !== 'number') return;
      var html = '<div class="lg-pinpop">'
        + (p.n  ? '<strong class="lg-pinpop__name">'  + esc(p.n) + '</strong>' : '')
        + (p.a  ? '<div class="lg-pinpop__addr">'     + esc(p.a) + '</div>'    : '')
        + (p.h  ? '<div class="lg-pinpop__hours">'    + esc(p.h) + '</div>'    : '')
        + (p.no ? '<div class="lg-pinpop__notes">'    + esc(p.no).replace(/\n/g, '<br>') + '</div>' : '')
        + '</div>';
      markers.push(L.marker([p.lat, p.lng]).addTo(map).bindPopup(html));
    });
    if (markers.length > 1) { map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2)); }
    else if (markers.length === 1) { map.setView(markers[0].getLatLng(), 14); }
    setTimeout(function () { map.invalidateSize(); }, 80);
  });
});
</script>
</body>
</html>
