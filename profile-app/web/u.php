<?php
declare(strict_types=1);

/**
 * /u/<slug> — public profile, BLOCK-MODEL render (spine inc 1–3).
 *
 * Replaces the slice-3.5 form/render path: the page is now assembled by
 * looth_render_profile_blocks() (header-as-ceiling gate + per-block renderers),
 * the SAME gate the /me endpoints round-trip through. Header default = member
 * (RULED): a profile with no explicit header vis is members-only; logged-out
 * hits the members-gate; public blocks under a public header peek through.
 *
 * View-as (owner only): the owner previews Public / Member / Me by driving the
 * one renderer with the selected effective role — no forked render path.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_render_blocks.php';   // looth_render_profile_blocks + Block + looth_h/initials

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Social;
use Looth\ProfileApp\Block;   // composable-layout caddy (availableBlocks + LAYOUT_BLOCKS)
use Looth\ProfileApp\Visibility;

$slug = $_GET['slug'] ?? '';
if (!is_string($slug) || $slug === '') { http_response_code(404); echo 'not found'; exit; }

$pg = Db::pg();
$q = $pg->prepare('SELECT id, uuid, display_name, slug, profile_visibility, avatar_url, at_a_glance, business_name FROM users WHERE slug = :s');
$q->execute([':s' => $slug]);
$row = $q->fetch();
if (!$row && ctype_digit($slug)) {
    $q = $pg->prepare('SELECT id, uuid, display_name, slug, profile_visibility, avatar_url, at_a_glance, business_name FROM users WHERE id = :i');
    $q->execute([':i' => (int)$slug]);
    $row = $q->fetch();
}
if (!$row) { http_response_code(404); echo 'not found'; exit; }

$subjectId = (int)$row['id'];

// GHOST CONTAINMENT (Ian 2026-07-13): an unbridged identity (no wp_user_bridge
// row) is NOT A MEMBER — it cannot log in. Its /u/<slug> must 404 exactly like a
// non-existent slug, not render a ghost profile a member could try to connect to
// or DM. Same answer as the master switch below (existence isn't probeable).
$bq = $pg->prepare('SELECT 1 FROM wp_user_bridge WHERE user_id = :i LIMIT 1');
$bq->execute([':i' => $subjectId]);
if (!$bq->fetchColumn()) { http_response_code(404); echo 'not found'; exit; }

looth_issue_bounce_if_needed();   // mint looth_id for logged-in WP users who land here without one
$viewer    = Auth::currentUser();
$isOwner   = $viewer && strtolower((string)$viewer['uuid']) === strtolower((string)$row['uuid']);

// MASTER SWITCH (Visibility module, Ian 6/12): a private profile is OWNER-ONLY —
// for everyone else (members included; admins excepted) this page answers exactly
// like a slug that doesn't exist, so existence can't be probed by guessing names.
$vArr = Visibility::viewer();
if (!Visibility::profileVisible($vArr, ['id' => $subjectId, 'profile_visibility' => (string)$row['profile_visibility']])) {
    http_response_code(404); echo 'not found'; exit;
}

// Effective viewer role. Owner gets View-as (?view=public|member|me, default me);
// admins render everything (ruling 4); everyone else is member (signed-in) or
// public (logged-out). The SAME role flows into the one gate —
// looth_render_profile_blocks() does the rest.
if ($isOwner) {
    $view = $_GET['view'] ?? 'me';
    $role = in_array($view, ['public', 'member', 'me'], true) ? $view : 'me';
} else {
    $role = Visibility::role($vArr, $subjectId);   // admin | member | public
}

// ADMIN FRONT-END EDIT (Ian 6/12): an administrator can open ANY profile in
// the real editor (?admin_edit=1). The page renders exactly as the owner's
// "Me" view; every /me/* save the editor fires is rewritten client-side to
// carry ?as=<subject uuid>, which Auth::requireUser honors for admins on the
// profile-content allowlist (audit-logged). Social actions stay the admin's
// own — the act-as surface excludes them.
$adminEditing = !$isOwner && $role === 'admin' && isset($_GET['admin_edit']);
if ($adminEditing) $role = 'me';

// Editor chrome (left Sections palette, inline-edit hints)
// shows ONLY in true edit mode: the owner on their own "Me" view (or an
// admin in admin-edit). In View-as Member/Public the owner sees the profile
// EXACTLY as that audience does — no sections bar — while the slim "View as"
// switcher stays so they can return.
$editing = ($isOwner && $role === 'me') || $adminEditing;

// Subject tier badge: not resolvable from the spine post tier-drop — needs a
// membership-tier lookup. Passed null for now (header renders no badge). FLAG.
$tierBadge = null;

$displayName = (string)($row['display_name'] ?: 'Member');
$slugSafe    = (string)($row['slug'] ?: (string)$subjectId);
$viewLink = fn(string $v): string => '/u/' . rawurlencode($slugSafe) . '?view=' . $v;

// Social actions (Connect / Message) — server-rendered widget from the social lane.
// Self-suppresses for the owner viewing their own page; auth-gated when logged out.
// Rendered inside the header card (threaded through the block renderer).
$socialActions = Social::renderProfileActions(
    // Under admin-edit, pass the subject as "viewer" so the widget self-
    // suppresses (Connect/Message buttons are clutter inside the editor and
    // must never read as actable-on-behalf-of).
    $adminEditing ? (string)$row['uuid'] : ($viewer['uuid'] ?? null),
    (string)$row['uuid']
);

// Discussion-author posting visibility (public|member) — the owner's preference for
// whether LOGGED-OUT viewers see their real identity on DISCUSSION (forum) posts.
// Default = 'member' (Ian 6/7): names hidden from the open web until opted Public.
// Scope is discussions only — CPTs stay public. The column + set-endpoint + payload
// are owned by the profile-app BACKEND lane (see docs/briefing-discussion-visibility.md);
// read it defensively so this page never fatals before that migration lands, and so it
// lights up automatically once the column exists.
$discussionVis = 'member';
if ($isOwner || $adminEditing) {
    $colChk = $pg->query("SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='discussion_visibility' LIMIT 1");
    if ($colChk && $colChk->fetchColumn()) {
        $dq = $pg->prepare('SELECT discussion_visibility FROM users WHERE id = :i');
        $dq->execute([':i' => $subjectId]);
        $dv = $dq->fetchColumn();
        if ($dv === 'public' || $dv === 'member') $discussionVis = $dv;
    }
}

// ── SEO <head> data (dependency-free; covers up to 1,915 public profile URLs) ──
// Location is deliberately OMITTED: location_visibility is 'members' for ~all
// users, so surfacing it in the public head would leak a members-only field.
$seoHost   = $_SERVER['HTTP_HOST'] ?? 'loothgroup.com';
$seoCanon  = 'https://' . $seoHost . '/u/' . rawurlencode($slugSafe);
$seoBiz    = trim((string) ($row['business_name'] ?? ''));
$seoGlance = trim((string) ($row['at_a_glance'] ?? ''));
$seoDescRaw = $seoGlance !== ''
    ? $seoGlance
    : $displayName . ($seoBiz !== '' ? ' — ' . $seoBiz : '')
        . ' on The Looth Group, the community for luthiers, instrument builders, and repair specialists.';
$seoDescRaw = trim(preg_replace('/\s+/', ' ', $seoDescRaw));
if (function_exists('mb_strlen') && mb_strlen($seoDescRaw) > 160) {
    $seoDescRaw = rtrim(mb_substr($seoDescRaw, 0, 157)) . '…';
}
$seoAvatar = trim((string) ($row['avatar_url'] ?? ''));
if ($seoAvatar !== '' && $seoAvatar[0] === '/') $seoAvatar = 'https://' . $seoHost . $seoAvatar;
// Thin auto-generated patreon_<NNNNN> placeholders: noindex (matches the sitemap
// exclusion) so Google skips ~1,639 near-empty pages. Real profiles index normally.
$seoIndex = !preg_match('/^patreon_[0-9]+$/', $slugSafe);
$seoLd = [
    '@context'   => 'https://schema.org',
    '@type'      => 'ProfilePage',
    'mainEntity' => array_filter([
        '@type'    => 'Person',
        'name'     => $displayName,
        'url'      => $seoCanon,
        'image'    => $seoAvatar !== '' ? $seoAvatar : null,
        'worksFor' => $seoBiz !== '' ? ['@type' => 'Organization', 'name' => $seoBiz] : null,
    ], fn($v) => $v !== null),
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= looth_h($displayName) ?> · Looth</title>
<meta name="robots" content="<?= $seoIndex ? 'index, follow' : 'noindex, follow' ?>">
<meta name="description" content="<?= looth_h($seoDescRaw) ?>">
<link rel="canonical" href="<?= looth_h($seoCanon) ?>">
<meta property="og:type" content="profile">
<meta property="og:title" content="<?= looth_h($displayName) ?> · Looth">
<meta property="og:description" content="<?= looth_h($seoDescRaw) ?>">
<meta property="og:url" content="<?= looth_h($seoCanon) ?>">
<?php if ($seoAvatar !== ''): ?><meta property="og:image" content="<?= looth_h($seoAvatar) ?>">
<?php endif; ?><meta property="og:site_name" content="Looth Group">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= looth_h($displayName) ?> · Looth">
<meta name="twitter:description" content="<?= looth_h($seoDescRaw) ?>">
<?php if ($seoAvatar !== ''): ?><meta name="twitter:image" content="<?= looth_h($seoAvatar) ?>">
<?php endif; ?>
<script type="application/ld+json"><?= json_encode($seoLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php if ($adminEditing): ?>
<script>
/* Admin-edit transport shim: every editor save targets /profile-api/v0/me/*;
   rewrite them to carry ?as=<subject> so the server acts on the profile being
   edited (admin-only, allowlisted, audit-logged server-side). Must be defined
   before any editor script fires a fetch. */
(function () {
  var AS = <?= json_encode(strtolower((string)$row['uuid'])) ?>;
  var origFetch = window.fetch;
  window.fetch = function (input, init) {
    try {
      var url = (typeof input === 'string') ? input : (input && input.url) || '';
      if (url.indexOf('/profile-api/v0/me') === 0) {
        url += (url.indexOf('?') >= 0 ? '&' : '?') + 'as=' + AS;
        if (typeof input !== 'string') input = new Request(url, input);
        else input = url;
      }
    } catch (e) {}
    return origFetch.call(this, input, init);
  };
})();
</script>
<?php endif; ?>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<!-- Leaflet from CDN (standalone shell has no WP head to enqueue from) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="" defer></script>
<style>
/* Block-model /u/ render. Tokens (--lg-*) come from site-header.css. */
body{margin:0;background:var(--lg-cream);color:var(--lg-ink);font-family:var(--lg-font-sans);font-size:calc(15px*var(--lg-read-scale,1));line-height:1.6}
/* Vertical spacing between top-level shell children (View-as bar ↔ profile body)
   is owned by the SHELL via flex `gap`, not by child margins. This is robust
   against margin-collapse + position:fixed/sticky weirdness — which is what
   previously made the View-as ↔ header-card gap unreliable (see briefing-profile-editor.md).
   The wide-screen @media block below replaces flex with grid + row-gap; the
   gap value stays in sync. */
.lg-shell{display:flex;flex-direction:column;gap:20px;max-width:760px;margin:0 auto;padding:24px 20px 48px}
.lg-profile{min-width:0}
/* The profile (with the View-as bar right above it) stays centered on the page; on wide screens
   the block sidebar floats off to the LEFT of that centered column (see the .lg-caddy rule). */

/* Owner control bar — one labeled row per control (View as / Profile visibility /
   Discussion posts), label column aligned, explanation inline after each control.
   Spacing from the page is handled by .lg-shell's flex gap. */
.lg-viewas{display:flex;flex-direction:column;align-items:stretch;gap:9px;background:var(--lg-charcoal);color:#cfd3cb;
  border-radius:12px;padding:12px 16px;margin:0;font:600 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans)}
.lg-viewas__row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.lg-viewas__lbl{flex:0 0 122px;font:700 calc(12px*var(--lg-read-scale,1))/1.3 var(--lg-font-sans)}
.lg-viewas__seg{display:flex;border:1px solid rgba(255,255,255,.18);border-radius:999px;overflow:hidden}
.lg-viewas__seg a{padding:6px 14px;color:#cfd3cb;text-decoration:none;font:700 calc(12px*var(--lg-read-scale,1))/1 var(--lg-font-sans)}
.lg-viewas__seg a[aria-current="true"]{background:var(--lg-sage);color:#fff}
.lg-viewas .lg-vchip{font-size:calc(11px*var(--lg-read-scale,1));padding:6px 12px;border-radius:999px;cursor:pointer}
.lg-viewas__note{flex:1 1 240px;min-width:200px;font:500 calc(11px*var(--lg-read-scale,1))/1.45 var(--lg-font-sans);opacity:.8}
.lg-viewas__hint{font:500 calc(11px*var(--lg-read-scale,1))/1.45 var(--lg-font-sans);color:#9aa091;border-top:1px solid rgba(255,255,255,.14);padding-top:9px}
/* discussion-author posting visibility toggle (owner) — sits beside Profile visibility */
.lg-disc-seg{display:inline-flex;border:1px solid rgba(255,255,255,.18);border-radius:999px;overflow:hidden}
.lg-disc-seg button{border:0;background:none;color:#cfd3cb;cursor:pointer;padding:6px 13px;font:700 calc(12px*var(--lg-read-scale,1))/1 var(--lg-font-sans)}
.lg-disc-seg button[aria-checked="true"]{background:var(--lg-sage);color:#fff}
.lg-disc-seg button:disabled{opacity:.6;cursor:wait}

/* Block shell */
.lg-block{position:relative;background:var(--lg-card-bg,#fff);border:1px solid var(--lg-line);border-radius:16px;padding:22px 24px;margin:0 0 16px}
.lg-bh{margin:0 0 12px;font:800 calc(16px*var(--lg-read-scale,1))/1 var(--lg-font-serif);color:var(--lg-charcoal)}
/* vis chip: inline by default (location/craft/socials emit it within text);
   the header's direct-child chip is corner-positioned. */
.lg-vchip{display:inline-block;vertical-align:middle;font:800 calc(9px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.06em;
  text-transform:uppercase;border-radius:5px;padding:3px 7px;margin-left:6px}
.lg-block--header>.lg-vchip{position:absolute;top:14px;right:16px;margin:0}
.lg-vchip--public{background:var(--lg-sage-tint);color:var(--lg-sage-d)}
.lg-vchip--member{background:#fdf0d8;color:#8a6326}
.lg-vchip--private{background:#f0e6e2;color:var(--lg-rust)}

/* header / identity card */
.lg-idrow{display:flex;gap:20px;align-items:center}
.lg-idrow__pic{width:96px;height:96px;border-radius:16px;flex:none;background:var(--lg-sage);color:#fff;
  display:grid;place-items:center;font:700 34px/1 var(--lg-font-serif);position:relative;overflow:hidden}
.lg-idrow__pic img{width:100%;height:100%;object-fit:cover;border-radius:16px}
.lg-idrow__cam{position:absolute;right:0;bottom:0;width:30px;height:30px;border-radius:50%;background:var(--lg-card-bg,#fff);
  border:1px solid var(--lg-line);cursor:pointer;font-size:calc(14px*var(--lg-read-scale,1));line-height:1}
.lg-idrow__avrm{position:absolute;right:0;top:0;width:24px;height:24px;border-radius:50%;background:var(--lg-card-bg,#fff);
  border:1px solid var(--lg-line);cursor:pointer;display:grid;place-items:center;color:var(--lg-mute,#6b6f6b);padding:0}
.lg-idrow__avrm:hover{color:#b3261e;border-color:#b3261e}
.lg-idrow__name{margin:0;font:800 calc(28px*var(--lg-read-scale,1))/1.1 var(--lg-font-serif);color:var(--lg-charcoal);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.lg-tierpill{font:800 calc(10px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;background:var(--lg-amber);color:#4a3c10;border-radius:6px;padding:4px 9px}
.lg-bizpill-wrap{margin:0 0 14px}
.lg-bizpill{display:inline-flex;align-items:center;gap:10px;text-decoration:none;cursor:pointer;border:1px solid var(--lg-line);background:var(--lg-card-bg,#fff);border-radius:999px;padding:8px 14px;font:600 calc(14px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-ink)}
.lg-bizpill:hover{border-color:var(--lg-sage);background:var(--lg-sage-tint)}
.lg-bizpill__tag{font:800 calc(9px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;background:var(--lg-sage-tint);color:var(--lg-sage-d);border-radius:5px;padding:4px 7px}
.lg-bizpill__name{font:700 calc(14px*var(--lg-read-scale,1))/1 var(--lg-font-serif)}
.lg-bizpill__go{font:600 calc(12px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-sage-d)}
.lg-bizpill--add{border-style:dashed}
.lg-bizpill--add:hover{border-color:var(--lg-sage);background:var(--lg-sage-tint)}
.lg-bizpill__plus{font:800 16px/1 var(--lg-font-sans);color:var(--lg-sage-d)}
.lg-bizpill__pro{font:800 calc(9px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;background:var(--lg-amber);color:#4a3c10;border-radius:5px;padding:4px 7px}
.lg-idrow__glance{font-size:calc(16px*var(--lg-read-scale,1));margin:12px 0 0;color:var(--lg-ink)}
/* the owner's tagline is also .lg-edit (margin:0 -4px), which would zero the gap above — keep it */
.lg-idrow__body .lg-idrow__glance{margin-top:12px}

/* location */
.lg-loc__line{display:flex;align-items:center;gap:9px;font-size:calc(15px*var(--lg-read-scale,1));color:var(--lg-ink)}
.lg-loc__exact{font-size:calc(14.5px*var(--lg-read-scale,1));color:var(--lg-ink);margin-top:8px}
.lg-loc__exact-note{font-size:calc(13px*var(--lg-read-scale,1));color:var(--lg-mute);margin-top:8px;font-style:italic}
.lg-loc__map,.lg-loc__pin{margin-top:12px;height:200px;border-radius:12px;border:1px solid var(--lg-line);
  overflow:hidden;background:var(--lg-sage-tint);
  position:relative;isolation:isolate;z-index:0}   /* contain Leaflet's z-index so it can't cover the header or menus */
.lg-loc__map .leaflet-container,.lg-loc__pin .leaflet-container{height:100%;border-radius:12px;font:inherit}
.lg-pinpop__name{display:block;font-weight:600;font-size:calc(14px*var(--lg-read-scale,1));color:var(--lg-ink);margin-bottom:2px}
.lg-pinpop__addr,.lg-pinpop__hours,.lg-pinpop__notes{font-size:calc(12.5px*var(--lg-read-scale,1));color:var(--lg-mute);line-height:1.4}
.lg-pinpop__hours{margin-top:3px}
.lg-pinpop__notes{margin-top:3px;font-style:italic}
/* location audience precision controls (owner) */
.lg-loc__line{display:flex;align-items:center;gap:9px;font-size:calc(15px*var(--lg-read-scale,1));color:var(--lg-ink)}
.lg-loc__empty{font-size:calc(13.5px*var(--lg-read-scale,1));color:var(--lg-mute);margin:10px 0 0}
.lg-loc__hint{font:400 calc(12px*var(--lg-read-scale,1))/1.45 var(--lg-font-sans);color:var(--lg-mute);margin:7px 0 0;max-width:46ch}
.lg-loc__edit{position:relative;margin-top:12px}
/* location editor panel (owner) — verbatim address bar + drag-a-pin fallback */
.lg-locedit{margin-top:12px;padding:13px;border:1px solid var(--lg-line);border-radius:12px;background:var(--lg-cream)}
.lg-locedit__help{font:400 calc(13px*var(--lg-read-scale,1))/1.5 var(--lg-font-sans);color:var(--lg-mute);margin:0 0 10px}
.lg-locedit__help b{color:var(--lg-ink);font-weight:600}
.lg-locedit__row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.lg-locedit__in{flex:1 1 220px;min-width:0;font:500 calc(13.5px*var(--lg-read-scale,1))/1.4 var(--lg-font-sans);color:var(--lg-ink);background:#fff;border:1px solid var(--lg-line);border-radius:9px;padding:9px 11px}
.lg-locedit__in:focus{outline:none;border-color:var(--lg-sage)}
.lg-locedit__cancel,.lg-locedit__cancel2{font:600 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-mute);background:none;border:0;cursor:pointer;padding:8px 6px}
.lg-locedit__cancel:hover,.lg-locedit__cancel2:hover{color:var(--lg-ink)}
.lg-locedit__status{font:400 calc(12.5px*var(--lg-read-scale,1))/1.45 var(--lg-font-sans);color:var(--lg-mute);margin-top:8px}
.lg-locedit__status:empty{display:none}
.lg-locedit__status .lg-locedit__help{margin:0 0 10px}
.lg-locedit__map{margin-top:10px}
.lg-loc__addr{font:500 calc(13.5px*var(--lg-read-scale,1))/1.4 var(--lg-font-sans);color:var(--lg-charcoal);margin-top:8px}
.lg-loc__hours{font:600 calc(12.5px*var(--lg-read-scale,1))/1.3 var(--lg-font-sans);color:var(--lg-sage-d);margin-top:4px}
.lg-loc__note{font:400 calc(13px*var(--lg-read-scale,1))/1.45 var(--lg-font-sans);color:var(--lg-mute);margin-top:5px}
.lg-loc__details{display:flex;flex-direction:column;gap:7px;margin-top:14px;padding-top:13px;border-top:1px dashed var(--lg-line)}
.lg-loc__f{font:500 calc(13.5px*var(--lg-read-scale,1))/1.4 var(--lg-font-sans);color:var(--lg-ink);background:var(--lg-cream);border:1px solid var(--lg-line);border-radius:9px;padding:8px 10px}
.lg-loc__f:focus{outline:none;border-color:var(--lg-sage)}
.lg-loc__note-in{resize:vertical;min-height:38px;font-family:var(--lg-font-sans)}
.lg-loc__details-save{align-self:flex-start}
.lg-loc__aud{display:flex;flex-wrap:wrap;gap:10px 22px;margin-top:14px;padding-top:13px;border-top:1px dashed var(--lg-line)}
.lg-loc__audrow{display:inline-flex;align-items:center;gap:8px}
.lg-loc__audlabel{font:700 calc(12px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-mute)}
.lg-prec{cursor:pointer;border:1px solid var(--lg-line);background:var(--lg-card-bg,#fff);border-radius:999px;padding:6px 13px;
  font:700 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-ink);display:inline-flex;align-items:center;gap:5px}
.lg-prec:hover{background:var(--lg-sage-tint);border-color:var(--lg-sage)}

/* craft chips */
.lg-chips{display:flex;flex-wrap:wrap;gap:0}
.lg-chip{display:inline-block;background:var(--lg-cream);border:1px solid var(--lg-line);border-radius:8px;padding:5px 12px;margin:0 7px 8px 0;font-size:calc(13.5px*var(--lg-read-scale,1))}

/* socials / links */
.lg-socrow{display:flex;gap:9px;flex-wrap:wrap}
.lg-socrow__a{display:inline-flex;align-items:center;height:32px;padding:0 12px;border-radius:8px;background:var(--lg-sage-tint);
  color:var(--lg-sage-d);font:700 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);text-decoration:none}
.lg-socrow__a:hover{background:var(--lg-sage-3)}
/* links editor (owner) */
.lg-links{display:flex;flex-direction:column;gap:8px;align-items:flex-start}
.lg-link{display:inline-flex;align-items:center;gap:10px;background:var(--lg-cream);border:1px solid var(--lg-line);border-radius:10px;padding:7px 8px 7px 10px}
.lg-link[draggable="true"]{cursor:grab}
.lg-sort-dragging{opacity:.45}
.lg-link.lg-sort-dragging{cursor:grabbing}
/* whole-block reorder grip (owner/Me) — dot-grid, matches builder mockup */
.lg-block__grip{display:inline-grid;grid-template-columns:1fr 1fr;gap:2px;cursor:grab;vertical-align:middle;margin-right:9px;user-select:none}
.lg-block__grip i{display:block;width:3px;height:3px;border-radius:50%;background:var(--lg-sage-3)}
.lg-block__grip:hover i{background:var(--lg-sage-d)}
/* section icon chip (owner/Me) — injected before each block title */
.lg-secic{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:8px;
  background:var(--lg-sage-tint);color:var(--lg-sage-d);font:800 calc(10px*var(--lg-read-scale,1))/1 var(--lg-font-sans);vertical-align:middle;margin-right:9px}
.lg-block.lg-sort-dragging{cursor:grabbing;outline:2px dashed var(--lg-sage-3);outline-offset:2px}
/* per-block remove (owner) — injected next to the grip */
.lg-block__rm{display:inline-block;border:0;background:none;cursor:pointer;color:var(--lg-mute);font:700 15px/1 var(--lg-font-sans);padding:0 4px;vertical-align:middle;margin-left:2px}
.lg-block__rm:hover{color:var(--lg-rust)}
/* per-block move up/down (owner, Buck 2026-06-11) — tap-friendly arrows beside the ✕
   for phones where the drag grip is fiddly; drag still works unchanged */
.lg-block__mv{display:inline-flex;align-items:center;justify-content:center;border:0;background:none;cursor:pointer;color:var(--lg-mute);padding:0 2px;min-width:26px;height:26px;vertical-align:middle}
.lg-block__mv svg{width:15px;height:15px}
.lg-block__mv:hover{color:var(--lg-sage-d)}
.lg-block__mv[disabled]{opacity:.28;cursor:default}
/* drop indicator while dragging a caddy block onto the profile */
.lg-block--drop-before{box-shadow:0 -3px 0 0 var(--lg-sage)}
.lg-block--drop-after{box-shadow:0 3px 0 0 var(--lg-sage)}
/* Sections toggle in the View-as bar — a hamburger at the break (Ian 6/15):
   below 1380px the caddy is an off-canvas drawer, so this reads as a menu
   button; at >=1380 it's display:none (the caddy is the permanent column). */
.lg-viewas__caddy{margin-left:auto;display:inline-flex;align-items:center;gap:9px;background:var(--lg-amber);color:#4a3c10;border:0;border-radius:999px;padding:8px 15px;min-height:40px;font:800 calc(12px*var(--lg-read-scale,1))/1 var(--lg-font-sans);cursor:pointer}
.lg-viewas__caddy:hover{filter:brightness(1.06)}
/* three-bar hamburger glyph (currentColor inherits the button ink) */
.lg-burger-ic{display:inline-block;width:16px;height:2px;background:currentColor;border-radius:2px;position:relative;flex:0 0 16px}
.lg-burger-ic::before,.lg-burger-ic::after{content:"";position:absolute;left:0;width:16px;height:2px;background:currentColor;border-radius:2px}
.lg-burger-ic::before{top:-5px}
.lg-burger-ic::after{top:5px}
/* caddy panel — slide-in from the right on desktop; off-canvas drawer on mobile */
.lg-caddy{position:fixed;top:0;right:0;height:100vh;width:300px;max-width:86vw;background:var(--lg-card-bg,#fff);border-left:1px solid var(--lg-line);
  box-shadow:-12px 0 36px rgba(0,0,0,.14);transform:translateX(102%);transition:transform .22s ease;z-index:1200;display:flex;flex-direction:column;padding:18px}
.lg-caddy.is-open{transform:none}
.lg-caddy__backdrop{position:fixed;inset:0;background:rgba(20,22,18,.34);z-index:1190;opacity:0;transition:opacity .22s}
.lg-caddy__backdrop.is-open{opacity:1}
.lg-lm-backdrop{position:fixed;inset:0;background:rgba(20,22,18,.42);z-index:1200;display:flex;align-items:center;justify-content:center;padding:18px;opacity:0;transition:opacity .2s}
.lg-lm-backdrop.is-open{opacity:1}
.lg-lm-backdrop[hidden]{display:none}
.lg-lm{background:var(--lg-card-bg,#fff);border:1px solid var(--lg-line);border-radius:16px;max-width:440px;width:100%;max-height:82vh;overflow:auto;box-shadow:0 14px 44px rgba(0,0,0,.2)}
.lg-lm__head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px 12px;border-bottom:1px solid var(--lg-line);font-family:var(--lg-font-serif);font-size:calc(17px*var(--lg-read-scale,1))}
.lg-lm__close{border:0;background:none;font-size:22px;line-height:1;cursor:pointer;color:var(--lg-mute)}
.lg-lm__hint{margin:10px 16px 0;font-size:calc(12px*var(--lg-read-scale,1));color:var(--lg-mute)}
.lg-lm .lg-links--edit{padding:12px 16px 16px}
.lg-caddy__head{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
.lg-caddy__head strong{font:800 calc(16px*var(--lg-read-scale,1))/1 var(--lg-font-serif);color:var(--lg-charcoal)}
.lg-caddy__close{border:0;background:none;font-size:24px;line-height:1;color:var(--lg-mute);cursor:pointer}
.lg-caddy__close:hover{color:var(--lg-ink)}
.lg-caddy__hint{font:500 calc(11.5px*var(--lg-read-scale,1))/1.5 var(--lg-font-sans);color:var(--lg-mute);margin:0 0 14px}
.lg-caddy__list{display:flex;flex-direction:column;gap:8px;overflow-y:auto}
.lg-caddy__item{display:flex;flex-direction:column;align-items:stretch;gap:7px;text-align:left;background:var(--lg-card-bg,#fff);
  border:1px solid var(--lg-line);border-radius:11px;padding:8px;cursor:grab}
.lg-caddy__item:hover{border-color:var(--lg-sage);box-shadow:0 1px 3px rgba(0,0,0,.05)}
.lg-caddy__item.lg-sort-dragging{opacity:.45}
/* builder palette — grouped sage bubble-pills (matches approved builder mockup) */
.lg-caddy__grp{font:700 calc(10px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.12em;text-transform:uppercase;color:var(--lg-mute);margin:16px 2px 9px}
.lg-caddy__grp:first-child{margin-top:2px}
.lg-bubbles{display:flex;flex-direction:column;gap:8px}
.lg-caddy__list .lg-bubble{flex-direction:row;align-items:center;gap:10px;padding:8px 13px;background:var(--lg-sage-tint);
  border:1px solid transparent;border-radius:999px;cursor:grab;box-shadow:none;transition:border-color .15s,transform .12s,opacity .15s}
.lg-caddy__list .lg-bubble:hover{border-color:var(--lg-sage-3);transform:translateY(-1px);box-shadow:none}
.lg-caddy__list .lg-bubble:active{transform:scale(.98)}
.lg-caddy__list .lg-bubble.is-used{opacity:.4;cursor:default;pointer-events:none}
.lg-caddy__list .lg-bubble.lg-sort-dragging{opacity:.45}
.lg-bubble__ic{width:27px;height:27px;border-radius:50%;background:var(--lg-sage);color:#fff;display:flex;
  align-items:center;justify-content:center;font:800 10px/1 var(--lg-font-sans);flex:0 0 auto}
.lg-bubble__lab{font:600 calc(13.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-sage-d)}
.lg-bubble__find{margin-left:auto;font:800 calc(8.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;
  color:var(--lg-sage-d);border:1px solid var(--lg-sage-3);border-radius:5px;padding:2px 6px}
.lg-bubble__multi{margin-left:auto;font:800 calc(8.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;
  color:var(--lg-mute);border:1px solid var(--lg-line);border-radius:5px;padding:2px 6px}
/* discovery-linkage: taxonomy blocks feed the member-directory search facets */
.lg-filterable{font:800 calc(8.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;color:var(--lg-sage-d);background:var(--lg-sage-tint);border:1px solid var(--lg-sage-3);border-radius:5px;padding:3px 6px;vertical-align:middle}
.lg-findnote{display:flex;align-items:center;gap:7px;font:600 calc(11.5px*var(--lg-read-scale,1))/1.4 var(--lg-font-sans);color:var(--lg-sage-d);background:var(--lg-sage-tint);border-radius:9px;padding:8px 11px;margin:0 0 12px}
.lg-findnote svg{flex:0 0 auto}
.lg-findnote b{font-weight:800}
/* header status lights (availability widgets) */
.lg-lights{position:relative;display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:22px}
.lg-light{display:inline-flex;align-items:center;gap:7px;background:var(--lg-cream);border:1px solid var(--lg-line);border-radius:999px;padding:5px 12px;font:600 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-ink)}
.lg-lights[data-lights-edit] .lg-light{cursor:pointer;padding-right:6px}
.lg-lights[data-lights-edit] .lg-light:hover{border-color:var(--lg-sage-3)}
.lg-light__dot{width:9px;height:9px;border-radius:50%;background:var(--lg-mute);flex:0 0 auto}
.lg-light--go .lg-light__dot,.lg-light__dot--go{background:#3fa34d;box-shadow:0 0 0 3px rgba(63,163,77,.18)}
.lg-light--stop .lg-light__dot,.lg-light__dot--stop{background:#c0492f;box-shadow:0 0 0 3px rgba(192,73,47,.16)}
.lg-light--maybe .lg-light__dot,.lg-light__dot--maybe{background:var(--lg-amber);box-shadow:0 0 0 3px rgba(224,168,60,.2)}
.lg-light__rm{border:0;background:none;cursor:pointer;color:var(--lg-mute);font-size:15px;line-height:1;padding:0 2px}
.lg-light__rm:hover{color:var(--lg-rust)}
.lg-light-add{border:1px dashed var(--lg-sage-3);background:none;cursor:pointer;border-radius:999px;padding:5px 12px;font:700 calc(12px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-sage-d)}
.lg-light-add:hover{background:var(--lg-sage-tint)}
.lg-light-menu{position:absolute;top:calc(100% + 4px);display:inline-flex;flex-direction:column;gap:2px;background:var(--lg-card-bg,#fff);border:1px solid var(--lg-line);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);padding:6px;z-index:1000}
.lg-light-menu button{display:flex;align-items:center;gap:8px;border:0;background:none;cursor:pointer;padding:7px 10px;border-radius:7px;font:600 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-ink);text-align:left;white-space:nowrap}
.lg-light-menu button:hover{background:var(--lg-sage-tint)}
/* header links rail (iconified socials, surfaced UP from the dedicated socials block) */
.lg-hlinks{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:14px}
.lg-hlinks__a{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:var(--lg-sage-tint);color:var(--lg-sage-d);text-decoration:none;border:1px solid transparent;transition:background .15s,border-color .15s,transform .15s}
.lg-hlinks__a:hover{background:var(--lg-card-bg,#fff);border-color:var(--lg-sage-3);color:var(--lg-ink);transform:translateY(-1px)}
.lg-hlinks__a:focus-visible{outline:2px solid var(--lg-sage-d);outline-offset:2px}
.lg-hlinks__a svg{display:block}
.lg-hlinks__edit{margin-left:4px;width:28px;height:28px;border-radius:50%;border:1px dashed var(--lg-sage-3);background:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:var(--lg-sage-d);padding:0}
.lg-hlinks__edit:hover{background:var(--lg-sage-tint);border-style:solid}
.lg-hlinks[data-hlinks-owner]:empty::before,.lg-hlinks[data-hlinks-owner]:not(:has(.lg-hlinks__a))::before{content:"No links yet — add some";font:italic 500 calc(12px*var(--lg-read-scale,1))/1.4 var(--lg-font-sans);color:var(--lg-mute);align-self:center}
.lg-flash{animation:lg-flash 1.4s ease-out}
@keyframes lg-flash{0%{box-shadow:0 0 0 2px var(--lg-sage-3),0 0 0 6px rgba(159,178,149,.18)}100%{box-shadow:0 0 0 0 transparent,0 0 0 0 transparent}}
/* wide screens (≥1380px): a 3-column grid — caddy | profile | empty spacer — so the profile
   stays PAGE-centered while the block sidebar sits in the left gutter. The View-as bar spans the
   top, centered over the profile. The caddy is sticky + IN-FLOW (not fixed), so it never overlaps
   the footer. Below 1380 → single centered column + off-canvas drawer. */
@media(min-width:1380px){
  .lg-shell--owner{display:grid;max-width:1376px;column-gap:28px;row-gap:20px;align-items:start;
    grid-template-columns:280px minmax(0,760px) 280px;
    grid-template-areas:"viewas viewas viewas" "caddy profile spacer"}
  .lg-shell--owner .lg-viewas{grid-area:viewas;max-width:760px;width:100%;margin:0 auto}
  .lg-shell--owner .lg-profile{grid-area:profile}
  /* top clears the sticky site header (61px) + breathing room; z-index drops below the
     header's (40) so the sticky caddy can never paint over the chrome — the base .lg-caddy
     rule's z-index:1200 is for the sub-1380 off-canvas drawer only. */
  .lg-shell--owner .lg-caddy{grid-area:caddy;position:sticky;top:85px;left:auto;right:auto;box-sizing:border-box;
    width:auto;height:auto;max-height:calc(100vh - 109px);overflow-y:auto;transform:none;z-index:30;
    border:1px solid var(--lg-line);border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
  .lg-shell--owner .lg-caddy__close{display:none}      /* permanent — no close button */
  .lg-viewas__caddy{display:none}                       /* permanent — no toggle */
  .lg-caddy__backdrop{display:none}
}
.lg-link__grip{color:var(--lg-mute);font-size:13px;line-height:1;letter-spacing:-2px;cursor:grab;user-select:none}
.lg-links--edit .lg-link:not([draggable]) .lg-link__grip,.lg-socrow .lg-link__grip{display:none}
.lg-link__kind{font:800 calc(9px*var(--lg-read-scale,1))/1 var(--lg-font-sans);letter-spacing:.06em;text-transform:uppercase;color:var(--lg-sage-d);background:var(--lg-sage-tint);border-radius:5px;padding:3px 6px}
.lg-link__val{font:600 calc(13px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-ink)}
.lg-link__rm{border:0;background:none;cursor:pointer;color:var(--lg-mute);font-size:18px;line-height:1;padding:0 4px}
.lg-link__rm:hover{color:var(--lg-rust)}
.lg-link__add{align-self:flex-start;border:1px dashed var(--lg-sage-3);background:none;cursor:pointer;border-radius:999px;padding:6px 14px;font:700 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-sage-d)}
.lg-link__add:hover{background:var(--lg-sage-tint);border-color:var(--lg-sage)}
.lg-link-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.lg-link-form select,.lg-link-form input{border:1px solid var(--lg-line);border-radius:8px;padding:7px 10px;font:600 calc(13px*var(--lg-read-scale,1))/1 var(--lg-font-sans)}
.lg-link-form button{border:0;border-radius:8px;padding:8px 14px;font:700 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);cursor:pointer}
.lg-link-form .ok{background:var(--lg-sage);color:#fff}
.lg-link-form .cancel{background:var(--lg-cream);border:1px solid var(--lg-line);color:var(--lg-ink)}
/* craft editor (owner) — removable chips + search multiselect */
.lg-chip--edit{display:inline-flex;align-items:center;gap:6px;padding-right:7px}
.lg-chip__rm{border:0;background:none;cursor:pointer;color:var(--lg-mute);font-size:15px;line-height:1;padding:0 2px}
.lg-chip__rm:hover{color:var(--lg-rust)}
.lg-craft-search{position:relative;display:inline-block;margin:0 0 8px}
.lg-craft-search input{border:1px solid var(--lg-sage);border-radius:999px;padding:7px 14px;font:600 calc(13px*var(--lg-read-scale,1))/1 var(--lg-font-sans);min-width:250px;outline:none}
.lg-craft-results{position:absolute;z-index:1000;top:calc(100% + 4px);left:0;min-width:290px;max-height:300px;overflow:auto;
  background:var(--lg-card-bg,#fff);border:1px solid var(--lg-line);border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.14);padding:6px}
.lg-craft-results button{display:flex;width:100%;align-items:center;justify-content:space-between;gap:10px;border:0;background:none;cursor:pointer;
  padding:8px 10px;border-radius:7px;text-align:left;font:600 calc(13px*var(--lg-read-scale,1))/1.2 var(--lg-font-sans);color:var(--lg-ink)}
.lg-craft-results button:hover{background:var(--lg-sage-tint)}
.lg-craft-results .t{font:700 calc(9px*var(--lg-read-scale,1))/1 var(--lg-font-sans);text-transform:uppercase;letter-spacing:.06em;color:var(--lg-mute)}
.lg-craft-results .added{font:700 calc(9px*var(--lg-read-scale,1))/1 var(--lg-font-sans);text-transform:uppercase;letter-spacing:.06em;color:var(--lg-sage-d)}
.lg-craft-results .none{padding:8px 10px;color:var(--lg-mute);font-size:calc(12.5px*var(--lg-read-scale,1))}
/* catalog picker: result rows (pick + admin delete) and the admin "add new" affordance */
.lg-craft-results__row{display:flex;align-items:center}
.lg-craft-results__row .pick{flex:1}
.lg-craft-results .del{width:auto!important;border:0;background:none;cursor:pointer;color:var(--lg-mute);padding:6px 9px;font-size:calc(12px*var(--lg-read-scale,1));flex:0 0 auto}
.lg-craft-results .del:hover{color:var(--lg-rust)}
.lg-craft-results .lg-cat-new{display:flex;width:100%;align-items:center;justify-content:space-between;gap:10px;border:0;border-top:1px solid var(--lg-line);
  background:var(--lg-sage-tint);cursor:pointer;padding:9px 12px;text-align:left}
.lg-craft-results .lg-cat-new span:first-child{color:var(--lg-sage-d);font-weight:700;font-size:calc(13px*var(--lg-read-scale,1))}
.lg-craft-results .lg-cat-new:hover{background:var(--lg-sage-3)}

/* connect block */
.lg-connect__count{display:inline-block;background:var(--lg-sage-tint);color:var(--lg-sage-d);font:800 calc(11px*var(--lg-read-scale,1))/1 var(--lg-font-sans);border-radius:999px;padding:3px 9px;margin-left:4px;vertical-align:middle}
.lg-connect__pending{display:inline-block;margin:0 0 10px;font:700 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-rust);text-decoration:none}
.lg-connect__mutual{margin:0 0 10px;font:600 calc(13px*var(--lg-read-scale,1))/1.3 var(--lg-font-sans);color:var(--lg-sage-d)}
.lg-connect__grid{display:flex;flex-wrap:wrap;gap:8px}
.lg-connect__person{text-decoration:none}
.lg-connect__av{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;overflow:hidden;
  background:var(--lg-sage);color:#fff;font:700 15px/1 var(--lg-font-serif)}
.lg-connect__av img{width:100%;height:100%;object-fit:cover;border-radius:50%}
.lg-connect__empty{margin:0;font-size:calc(13.5px*var(--lg-read-scale,1));color:var(--lg-mute)}

/* inline content editing (owner/Me view) */
.lg-edit{cursor:text;border-radius:6px;outline:none;transition:background .12s,box-shadow .12s;padding:0 4px;margin:0 -4px}
.lg-edit:hover{background:var(--lg-sage-tint);box-shadow:0 0 0 3px var(--lg-sage-tint)}
.lg-edit:hover::after{content:" ✎";font-size:.7em;color:var(--lg-sage-d);opacity:.7}
.lg-edit--empty{color:var(--lg-mute);font-style:italic;font-weight:500}
.lg-edit.editing{background:var(--lg-card-bg,#fff);box-shadow:0 0 0 2px var(--lg-sage);font-style:normal;color:var(--lg-ink)}
.lg-edit.editing::after{content:none}
.lg-edit.saved{box-shadow:0 0 0 2px var(--lg-sage-3)}
.lg-about{font-size:calc(14.5px*var(--lg-read-scale,1));line-height:1.6;color:var(--lg-ink);white-space:pre-wrap;max-width:640px}
.lg-about.lg-edit{min-height:1.5em;display:block;padding:6px 8px;margin:0 -8px}
/* resume block — single PDF, download button + owner replace/remove */
.lg-resume{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.lg-resume__a{display:inline-flex;align-items:center;gap:9px;background:var(--lg-sage-tint);color:var(--lg-ink);text-decoration:none;padding:9px 14px;border-radius:10px;font:700 calc(13px*var(--lg-read-scale,1))/1 var(--lg-font-sans);transition:background .15s,transform .15s}
.lg-resume__a:hover{background:var(--lg-sage-3);transform:translateY(-1px)}
.lg-resume__set{background:none;border:1px solid var(--lg-line);border-radius:999px;padding:7px 12px;cursor:pointer;font:600 calc(12px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-sage-d)}
.lg-resume__set:hover:not([disabled]){background:var(--lg-sage-tint);border-color:var(--lg-sage-3)}
.lg-resume__set[disabled]{opacity:.5;cursor:wait}
.lg-resume__set--add{padding:11px 18px;border:1.5px dashed var(--lg-sage-3);border-radius:12px;font-size:calc(13px*var(--lg-read-scale,1))}
.lg-resume__rm{border:0;background:none;cursor:pointer;color:var(--lg-mute);font-size:18px;line-height:1;padding:0 6px}
.lg-resume__rm:hover{color:var(--lg-rust)}
.lg-resume--empty{flex-direction:column;align-items:flex-start;gap:6px}
.lg-resume__hint{margin:0;font:italic 500 calc(12px*var(--lg-read-scale,1))/1.4 var(--lg-font-sans);color:var(--lg-mute)}
/* gallery block */
.lg-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px}
.lg-gphoto{margin:0;position:relative;aspect-ratio:1;border-radius:10px;overflow:hidden;background:var(--lg-sage-tint)}
.lg-gphoto img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .25s ease}
.lg-gallery--grid .lg-gphoto:hover img{transform:scale(1.04)}
.lg-gphoto figcaption{position:absolute;bottom:0;left:0;right:0;font:600 calc(11px*var(--lg-read-scale,1))/1.3 var(--lg-font-sans);color:#fff;background:linear-gradient(transparent,rgba(0,0,0,.6));padding:16px 8px 6px}
.lg-gphoto__rm{position:absolute;top:6px;right:6px;width:24px;height:24px;border-radius:50%;border:0;background:rgba(0,0,0,.55);color:#fff;cursor:pointer;font-size:15px;line-height:1;z-index:3}
.lg-gphoto__rm:hover{background:var(--lg-rust)}
.lg-gphoto__add{aspect-ratio:1;border:2px dashed var(--lg-sage-3);background:none;border-radius:10px;cursor:pointer;color:var(--lg-sage-3);font:300 34px/1 var(--lg-font-sans);display:flex;align-items:center;justify-content:center;text-align:center;padding:6px;transition:background .15s,border-color .15s,color .15s}
.lg-gphoto__add:hover{background:var(--lg-sage-tint);border-color:var(--lg-sage);color:var(--lg-sage-d)}
/* gallery — lightbox (all viewers): click a photo to view it full-size */
.lg-gphoto img{cursor:zoom-in}
.lg-lightbox{position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.92);display:flex;align-items:center;justify-content:center;padding:24px;opacity:0;transition:opacity .15s ease}
.lg-lightbox.is-open{opacity:1}
.lg-lightbox__fig{margin:0;max-width:96vw;max-height:92vh;display:flex;flex-direction:column;align-items:center;gap:10px}
.lg-lightbox__img{max-width:96vw;max-height:84vh;width:auto;height:auto;object-fit:contain;border-radius:6px;box-shadow:0 8px 40px rgba(0,0,0,.5)}
.lg-lightbox__cap{font:500 calc(13.5px*var(--lg-read-scale,1))/1.4 var(--lg-font-sans);color:#fff;text-align:center;max-width:80ch;text-shadow:0 1px 2px rgba(0,0,0,.6)}
.lg-lightbox__cap:empty{display:none}
.lg-lightbox__close,.lg-lightbox__nav{position:absolute;border:0;background:rgba(0,0,0,.45);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;border-radius:50%}
.lg-lightbox__close:hover,.lg-lightbox__nav:hover{background:rgba(0,0,0,.75)}
.lg-lightbox__close{top:18px;right:20px;width:42px;height:42px;font:300 26px/1 var(--lg-font-sans)}
.lg-lightbox__nav{top:50%;transform:translateY(-50%);width:48px;height:48px;font:300 34px/1 var(--lg-font-sans)}
.lg-lightbox__prev{left:18px}
.lg-lightbox__next{right:18px}
.lg-lightbox__nav[hidden]{display:none}
@media (max-width:560px){.lg-lightbox__nav{width:40px;height:40px;font-size:28px}.lg-lightbox__prev{left:6px}.lg-lightbox__next{right:6px}}
/* gallery — owner: display-mode toggle */
.lg-gmode{display:inline-flex;gap:0;margin:0 0 12px;border:1px solid var(--lg-line);border-radius:999px;padding:2px;background:var(--lg-card-bg,#fff)}
.lg-gmode__btn{border:0;background:transparent;font:600 calc(12px*var(--lg-read-scale,1))/1 var(--lg-font-sans);color:var(--lg-mute);padding:6px 14px;border-radius:999px;cursor:pointer}
.lg-gmode__btn:hover{color:var(--lg-ink)}
.lg-gmode__btn[aria-pressed="true"]{background:var(--lg-sage-tint);color:var(--lg-ink)}
/* gallery — owner: per-gallery delete (trash) in the heading */
.lg-gdel{border:0;background:none;cursor:pointer;font-size:15px;line-height:1;padding:2px 4px;opacity:.55;border-radius:6px;color:var(--lg-mute)}
.lg-gdel:hover{opacity:1;background:#fbeaea;color:#b3261e}
.lg-gdel:disabled{opacity:.35;cursor:progress}
/* gallery — owner: "Add gallery" countdown control (pinned in the Sections rail) */
.lg-caddy__gadd{margin:0 0 14px}
.lg-gadd{display:inline-flex;align-items:center;gap:9px;background:var(--lg-sage-tint,#eef1e8);border:1px dashed var(--lg-sage-3,#b7c6ab);color:var(--lg-ink);border-radius:999px;padding:9px 16px;min-height:40px;font:700 calc(12.5px*var(--lg-read-scale,1))/1 var(--lg-font-sans);cursor:pointer}
.lg-gadd:hover{border-color:var(--lg-sage);background:#fff}
.lg-gadd--rail{width:100%;justify-content:flex-start}
.lg-gadd:disabled,.lg-gadd[disabled]{opacity:.5;cursor:not-allowed;border-style:solid}
.lg-gadd:disabled:hover,.lg-gadd[disabled]:hover{background:var(--lg-sage-tint,#eef1e8);border-color:var(--lg-sage-3,#b7c6ab)}
.lg-gadd__plus{font-size:17px;line-height:1;color:var(--lg-sage-d,#4f6a45)}
.lg-gadd__count{margin-left:auto;font-weight:700;color:var(--lg-sage-d,#4f6a45);font-size:calc(11px*var(--lg-read-scale,1))}
.lg-gadd:disabled .lg-gadd__count,.lg-gadd[disabled] .lg-gadd__count{color:var(--lg-mute)}
/* gallery — carousel display mode */
.lg-gallery--carousel{display:block}
.lg-gallery--carousel.lg-gallery--edit{display:flex;flex-direction:column;gap:8px}
.lg-carousel{position:relative;border-radius:12px;background:var(--lg-sage-tint);overflow:hidden}
.lg-carousel__viewport{overflow:hidden;border-radius:12px}
.lg-carousel__track{display:flex;transition:transform .35s ease;will-change:transform}
.lg-carousel__track > .lg-gphoto{flex:0 0 100%;aspect-ratio:16/9;border-radius:0;background:#000}
.lg-carousel__track > .lg-gphoto img{object-fit:contain}
/* owner add-tile rides the track as a full-width slide (matches a photo slot) */
.lg-carousel__track > .lg-gphoto__add{flex:0 0 100%;aspect-ratio:16/9;border-radius:0;font-size:48px}
/* nav disc floats OVER the photo — keep the white-chip/dark-ink pair hardcoded
   (mode-independent); var(--lg-ink) here would flip light-on-white in dark. */
.lg-carousel__nav{position:absolute;top:50%;transform:translateY(-50%);width:38px;height:38px;border-radius:50%;border:0;background:rgba(255,255,255,.94);color:#323532;font-size:20px;line-height:1;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.18);z-index:2;display:grid;place-items:center}
.lg-carousel__nav:hover{background:#fff}
.lg-carousel__nav:disabled{opacity:.35;cursor:not-allowed;box-shadow:none}
.lg-carousel__nav--prev{left:10px}
.lg-carousel__nav--next{right:10px}
.lg-carousel__dots{display:flex;justify-content:center;gap:6px;padding:10px 0 6px}
.lg-carousel__dot{width:8px;height:8px;border-radius:50%;border:0;background:var(--lg-line);cursor:pointer;padding:0;transition:background .15s,transform .15s}
.lg-carousel__dot:hover{background:var(--lg-sage-3)}
.lg-carousel__dot[aria-current="true"]{background:var(--lg-sage-d);transform:scale(1.2)}

/* members-only gate */
.lg-gate{text-align:center;background:var(--lg-card-bg,#fff);border:1px solid var(--lg-line);border-radius:18px;padding:48px 30px;margin:0 0 16px}
.lg-gate__lock{width:64px;height:64px;border-radius:50%;background:var(--lg-sage-tint);display:grid;place-items:center;margin:0 auto 16px;color:var(--lg-sage-d)}
.lg-gate h2{margin:0 0 8px;font:800 calc(22px*var(--lg-read-scale,1))/1.2 var(--lg-font-serif);color:var(--lg-charcoal)}
.lg-gate p{margin:0 auto 20px;max-width:420px;color:var(--lg-mute);font-size:calc(14.5px*var(--lg-read-scale,1))}
.lg-gate__cta{display:inline-flex;gap:10px}
.lg-gate__join{background:var(--lg-amber);color:#4a3c10;text-decoration:none;font:800 calc(14px*var(--lg-read-scale,1))/1 var(--lg-font-sans);border-radius:999px;padding:12px 22px}
.lg-gate__signin{border:1px solid var(--lg-line);color:var(--lg-ink);text-decoration:none;font:700 calc(14px*var(--lg-read-scale,1))/1 var(--lg-font-sans);border-radius:999px;padding:12px 22px}

.lg-report{display:inline-block;margin-top:8px;font-size:calc(12.5px*var(--lg-read-scale,1));color:var(--lg-mute)}

/* interactive pmp control (owner/Me view) */
.lg-pmp{cursor:pointer;border:0;font-family:inherit;display:inline-flex;align-items:center;gap:4px}
.lg-pmp:hover{filter:brightness(.95)}
.lg-pmp:focus-visible{outline:2px solid var(--lg-sage);outline-offset:1px}
.lg-pmp__caret{font-size:8px;opacity:.8}
.lg-pmp--capped{box-shadow:inset 0 0 0 1px var(--lg-rust)}
.lg-pmp-menu{position:absolute;z-index:1000;min-width:210px;background:var(--lg-card-bg,#fff);border:1px solid var(--lg-line);
  border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.14);padding:6px}
.lg-pmp-menu__head{font:700 calc(10px*var(--lg-read-scale,1))/1.3 var(--lg-font-sans);text-transform:uppercase;letter-spacing:.06em;color:var(--lg-mute);padding:7px 9px 5px}
.lg-pmp-menu button{display:flex;width:100%;align-items:center;justify-content:space-between;gap:10px;
  border:0;background:none;cursor:pointer;padding:8px 9px;border-radius:7px;text-align:left;
  font:600 calc(13px*var(--lg-read-scale,1))/1.2 var(--lg-font-sans);color:var(--lg-ink)}
.lg-pmp-menu button:hover{background:var(--lg-sage-tint)}
.lg-pmp-menu button[aria-current="true"]{font-weight:800;color:var(--lg-sage-d)}
.lg-pmp-menu button[aria-current="true"]::after{content:"✓";color:var(--lg-sage-d)}
.lg-pmp-menu .cap{font:600 calc(10px*var(--lg-read-scale,1))/1.2 var(--lg-font-sans);color:var(--lg-rust)}
.lg-pmp-menu__opt{display:flex;flex-direction:column;align-items:flex-start;gap:1px;text-align:left}
.lg-pmp-menu__lab{font-weight:600}
.lg-pmp-menu__desc{font:400 calc(11px*var(--lg-read-scale,1))/1.3 var(--lg-font-sans);color:var(--lg-mute)}
.lg-pmp-menu__def{font:700 calc(9px*var(--lg-read-scale,1))/1 var(--lg-font-sans);text-transform:uppercase;letter-spacing:.05em;color:var(--lg-sage-d);background:var(--lg-sage-tint);padding:2px 5px;border-radius:6px;margin-left:6px;vertical-align:middle}

@media(max-width:560px){.lg-idrow{flex-direction:column;text-align:center;align-items:center}}
/* header banner — optional hero strip above the identity row (full-bleed against .lg-block padding 22/24) */
.lg-banner{position:relative;width:calc(100% + 48px);margin:-22px -24px 20px;border-radius:15px 15px 0 0;overflow:hidden;background:var(--lg-sage-tint);aspect-ratio:1080/280;max-height:280px}
.lg-banner__img{width:100%;height:100%;object-fit:cover;display:block}
.lg-banner--empty{aspect-ratio:1080/120;max-height:120px;background:repeating-linear-gradient(45deg,var(--lg-sage-tint) 0,var(--lg-sage-tint) 10px,#eef2e9 10px,#eef2e9 20px);display:flex;align-items:center;justify-content:center}
/* floats over the banner image — fixed white-chip/dark-ink pair, mode-independent */
.lg-banner__set{position:absolute;right:12px;bottom:12px;display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.94);color:#323532;border:0;border-radius:999px;padding:6px 12px 6px 10px;cursor:pointer;font:700 calc(12px*var(--lg-read-scale,1))/1 var(--lg-font-sans);box-shadow:0 2px 8px rgba(0,0,0,.18);z-index:2}
.lg-banner__set:hover{background:#fff}
.lg-banner--empty .lg-banner__set{position:static;box-shadow:none;background:#fff;border:1px dashed var(--lg-sage-3)}
.lg-banner__rm{position:absolute;top:10px;right:10px;width:28px;height:28px;border-radius:50%;border:0;background:rgba(0,0,0,.55);color:#fff;cursor:pointer;font-size:16px;line-height:1;z-index:2}
.lg-banner__rm:hover{background:var(--lg-rust)}
@media(max-width:560px){.lg-banner{aspect-ratio:1080/360;max-height:200px}.lg-banner--empty{aspect-ratio:1080/180}}

/* ── DARK-MODE BRIDGE ──────────────────────────────────────────────────────
   Dark is the gear-picked theme (html[data-lguser-theme="dark"], same trigger
   as the Hub — OS prefers-color-scheme was deliberately retired 6/10, two
   explicit modes). app-settings.js flips the --lg-* tokens inline on <html>,
   so every token-driven rule above follows automatically; this block covers
   only pairings tokens can't express:
   - the View-as slab is a DELIBERATE dark chip in light mode; its
     var(--lg-charcoal) bg flips near-white in dark under hardcoded light text
   - white-on-sage (avatar initials, seg pills): sage lightens to #9cb37d in
     dark, where #fff lands ~1.9:1 — pin dark text instead (Hub does the same)
   - the vis chips are hardcoded light badges; give them dark-tint equivalents */
html[data-lguser-theme="dark"] .lg-viewas{background:#22262a}
html[data-lguser-theme="dark"] .lg-viewas__seg a[aria-current="true"],
html[data-lguser-theme="dark"] .lg-disc-seg button[aria-checked="true"],
html[data-lguser-theme="dark"] .lg-idrow__pic,
html[data-lguser-theme="dark"] .lg-bubble__ic,
html[data-lguser-theme="dark"] .lg-connect__av,
html[data-lguser-theme="dark"] .lg-link-form .ok{color:#15171a}
html[data-lguser-theme="dark"] .lg-vchip--member{background:#3a3220;color:#ecb351}
html[data-lguser-theme="dark"] .lg-vchip--private{background:#3a2a24;color:#d57a55}
html[data-lguser-theme="dark"] .lg-banner--empty{background:repeating-linear-gradient(45deg,var(--lg-sage-tint) 0,var(--lg-sage-tint) 10px,#1e2124 10px,#1e2124 20px)}
</style>
</head>
<body class="mode-view">
<?php require __DIR__ . '/_chrome.php'; ?>

<main class="main" id="lg-main">
  <div class="lg-shell<?= $editing ? ' lg-shell--owner' : '' ?>">

    <?php if ($role === 'admin' && !$adminEditing): ?>
      <!-- Admin affordance: open ANY profile in the real front-end editor. -->
      <div class="lg-viewas" role="group" aria-label="Admin controls">
        <div class="lg-viewas__row">
          <span class="lg-viewas__lbl" style="color:#f0c987">Admin</span>
          <span class="lg-viewas__note">Viewing as administrator.</span>
          <a class="lg-vchip" style="background:var(--lg-sage);color:#fff;text-decoration:none"
             href="/u/<?= rawurlencode($slugSafe) ?>?admin_edit=1">Edit profile (admin)</a>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($isOwner || $adminEditing): ?>
      <div class="lg-viewas" role="group" aria-label="Profile controls">
        <?php if ($adminEditing): ?>
        <div class="lg-viewas__row">
          <span class="lg-viewas__lbl" style="color:#f0c987">Admin edit</span>
          <span class="lg-viewas__note">You are editing <b><?= looth_h($displayName) ?></b>'s profile as an administrator. Every save is logged.</span>
          <a class="lg-vchip" style="background:rgba(255,255,255,.12);color:#fff;text-decoration:none" href="/u/<?= rawurlencode($slugSafe) ?>">Exit admin edit</a>
          <?php if ($editing): ?>
          <button type="button" class="lg-viewas__caddy" id="lg-caddy-toggle" aria-expanded="false" aria-controls="lg-caddy" aria-label="Open sections menu"><span class="lg-burger-ic" aria-hidden="true"></span><span class="lg-viewas__caddy-lbl">Sections</span></button>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="lg-viewas__row">
          <span class="lg-viewas__lbl">View as</span>
          <span class="lg-viewas__seg">
            <a href="<?= looth_h($viewLink('public')) ?>" <?= $role==='public'?'aria-current="true"':'' ?>>Public</a>
            <a href="<?= looth_h($viewLink('member')) ?>" <?= $role==='member'?'aria-current="true"':'' ?>>Member</a>
            <a href="<?= looth_h($viewLink('me')) ?>"     <?= $role==='me'?'aria-current="true"':'' ?>>Me</a>
          </span>
          <?php if ($editing): ?>
          <button type="button" class="lg-viewas__caddy" id="lg-caddy-toggle" aria-expanded="false" aria-controls="lg-caddy" aria-label="Open sections menu"><span class="lg-burger-ic" aria-hidden="true"></span><span class="lg-viewas__caddy-lbl">Sections</span></button>
          <?php endif; ?>
        </div>
        <?php endif; /* /adminEditing banner vs View-as */ ?>
        <?php
          // Profile visibility = the whole-profile DEFAULT; each section's own chip can
          // override it downward (Members-only / Private). Say so right here (Ian 6/11).
          $hVis  = Block::normalizeVis(Block::headerCeiling($subjectId));
          $hNote = $hVis === 'public'  ? 'Public is the default for your whole profile — each section can override this to Members-only or Private with its own chip.'
                 : ($hVis === 'private' ? 'Only you (and site admins) can see your profile. You\'re removed from the directory, the map, and search until you switch back.'
                 : 'Members-only is the default for your whole profile — each section can override this to Private with its own chip. Set Public to let anyone view it.');
        ?>
        <div class="lg-viewas__row">
          <span class="lg-viewas__lbl">Profile visibility</span>
          <?= looth_pmp_control('header', $hVis, '') ?>
          <span class="lg-viewas__note"><?= looth_h($hNote) ?></span>
        </div>
        <?php
          // Discussion-author posting visibility — a 2-state Public / Member-only toggle.
          // Distinct from Profile visibility (whole-profile default): this only controls whether
          // logged-out viewers see the owner's real identity on their DISCUSSION posts.
          $dvNote = $discussionVis === 'public'
            ? 'Your name & avatar show on your discussion posts to everyone.'
            : 'Logged-out visitors see "private member" on your discussion posts; signed-in members see you.';
        ?>
        <div class="lg-viewas__row">
          <span class="lg-viewas__lbl">Discussion posts</span>
          <span class="lg-disc-seg" role="radiogroup" aria-label="Who sees your identity on discussion posts" data-disc-current="<?= looth_h($discussionVis) ?>">
            <button type="button" role="radio" data-disc="public" aria-checked="<?= $discussionVis==='public'?'true':'false' ?>">Public</button>
            <button type="button" role="radio" data-disc="member" aria-checked="<?= $discussionVis==='member'?'true':'false' ?>">Member-only</button>
          </span>
          <span class="lg-viewas__note" id="lg-disc-note"><?= looth_h($dvNote) ?></span>
        </div>
        <?php if ($editing): ?>
        <div class="lg-viewas__hint">This IS your editor — click any field (name, tagline, the photo, the privacy chips) to edit it in place. Drag the grip on a block to reorder; the Sections panel adds or removes blocks.</div>
        <?php endif; /* /editing: hint */ ?>
      </div>
    <?php endif; /* /isOwner: View-as switcher */ ?>
    <?php if ($editing): ?>
      <?php
        $available = Block::availableBlocks($subjectId);
        // Builder palette: real layout blocks grouped like the approved mockup.
        $paletteGroups = [
          'Core'   => ['about', 'instruments', 'skills', 'services', 'music', 'location'],
          'Extras' => ['gallery', 'connect', 'socials', 'resume'],
        ];
        // Section icons — line SVGs (stroke=currentColor inherits the bubble/badge color).
        $iconPaths = [
          'about'       => '<circle cx="12" cy="8" r="3.5"/><path d="M5.5 19a6.5 6.5 0 0 1 13 0"/>',
          'instruments' => '<path d="m11.9 12.1 4.514-4.514"/><path d="M20.1 2.3a1 1 0 0 0-1.4 0l-1.114 1.114A2 2 0 0 0 17 4.828v1.344a2 2 0 0 1-.586 1.414A2 2 0 0 1 17.828 7h1.344a2 2 0 0 0 1.414-.586L21.7 5.3a1 1 0 0 0 0-1.4z"/><path d="m6 16 2 2"/><path d="M8.23 9.85A3 3 0 0 1 11 8a5 5 0 0 1 5 5 3 3 0 0 1-1.85 2.77l-.92.38A2 2 0 0 0 12 18a4 4 0 0 1-4 4 6 6 0 0 1-6-6 4 4 0 0 1 4-4 2 2 0 0 0 1.85-1.23z"/>',
          'skills'      => '<path d="M12 3.5l2.5 5.2 5.7.8-4.1 4 1 5.7L12 16.6 6.9 19.2l1-5.7-4.1-4 5.7-.8z"/>',
          'services'    => '<path d="M15.6 7.4a3.6 3.6 0 0 0-4.7 4.4l-6.1 6.1 2.3 2.3 6.1-6.1a3.6 3.6 0 0 0 4.4-4.7l-2.2 2.2-2-2 2.2-2.2z"/>',
          'music'       => '<path d="M9 17V5l10-2v12"/><circle cx="6.5" cy="17" r="2.5"/><circle cx="16.5" cy="15" r="2.5"/>',
          'location'    => '<path d="M12 21s7-5.8 7-11a7 7 0 1 0-14 0c0 5.2 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
          'gallery'     => '<rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="1.7"/><path d="M5 17l4.5-4.5 3 3L16 11l3 3.4"/>',
          'connect'     => '<circle cx="8.5" cy="9" r="2.8"/><circle cx="16" cy="9.5" r="2.3"/><path d="M3.5 19a5 5 0 0 1 10 0"/><path d="M14 19a4.3 4.3 0 0 1 6.5-3.7"/>',
          'socials'     => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17"/><path d="M12 3.5c2.6 2.4 2.6 14.6 0 17"/><path d="M12 3.5c-2.6 2.4-2.6 14.6 0 17"/>',
          'resume'      => '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="M10 13.2h6"/><path d="M10 16.6h6"/><path d="M10 9.8h2"/>',
          'credentials' => '<circle cx="12" cy="9" r="5"/><path d="M9 13.4 7.4 21l4.6-2.6L16.6 21 15 13.4"/>',
          'practices'   => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1"/><path d="M8.6 10l1.8 1.8 3.8-3.8"/><path d="M8.6 16h6.8"/>',
        ];
        $icSvg = function ($key) use ($iconPaths) {
          $p = $iconPaths[$key] ?? '';
          return $p ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>' : '';
        };
      ?>
      <aside class="lg-caddy" id="lg-caddy" aria-hidden="true" aria-label="Add a section to your profile">
      <div class="lg-caddy__head">
        <strong>Sections</strong>
        <button type="button" class="lg-caddy__close" id="lg-caddy-close" aria-label="Close">×</button>
      </div>
      <p class="lg-caddy__hint">Drag a section into your profile — or tap to add. Sections marked <b>Filterable</b> tag you with site taxonomy so members can find you in search.</p>
      <?php
        // Add-gallery control — pinned in the Sections rail (Ian 2026-07-23, rev 2), a
        // COUNTDOWN of galleries you can still add (max 3), disabled at 0 left.
        $lgGalLeft = max(0, 3 - looth_gallery_count($subjectId));
      ?>
      <div class="lg-caddy__gadd">
        <button type="button" class="lg-gadd lg-gadd--rail" id="lg-add-gallery"<?= $lgGalLeft <= 0 ? ' disabled aria-disabled="true"' : '' ?>>
          <span class="lg-gadd__plus" aria-hidden="true">＋</span>
          <span class="lg-gadd__lab">Add gallery</span>
          <span class="lg-gadd__count"><?= $lgGalLeft ?> left</span>
        </button>
      </div>
      <div class="lg-caddy__list" id="lg-caddy-list">
        <?php foreach ($paletteGroups as $grp => $keys):
              $keys = array_values(array_diff($keys, Block::launchHiddenBlocks()));
              if (!$keys) continue;
        ?>
          <h3 class="lg-caddy__grp"><?= looth_h($grp) ?></h3>
          <div class="lg-bubbles">
            <?php foreach ($keys as $key):
              $b    = Block::LAYOUT_BLOCKS[$key];
              $filt = isset(Block::CATALOG_BLOCKS[$key]);
              $used = !isset($available[$key]);   // not available => already placed
            ?>
              <button type="button" class="lg-caddy__item lg-bubble<?= $used ? ' is-used' : '' ?>" draggable="<?= $used ? 'false' : 'true' ?>" data-block="<?= looth_h($key) ?>"<?= $used ? ' aria-disabled="true"' : '' ?>>
                <span class="lg-bubble__ic" aria-hidden="true"><?= $icSvg($key) ?></span>
                <span class="lg-bubble__lab"><?= looth_h($b['label']) ?></span>
                <?php if ($filt): ?><span class="lg-bubble__find" title="Makes you findable in the member directory">Filterable</span><?php endif; ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </aside>
    <?php endif; ?>

    <div class="lg-profile">
      <?php looth_render_profile_blocks($subjectId, $role, $tierBadge, $socialActions, $viewer ? (int)$viewer['id'] : null); ?>
      <?php if (!$isOwner): ?>
        <a class="lg-report" href="#" id="report-link">Report this profile</a>
      <?php endif; ?>
    </div>
  </div><!-- /lg-shell -->
</main>

<?php if ($isOwner): ?>
  <div class="lg-caddy__backdrop" id="lg-caddy-backdrop" hidden></div>
  <?php
    $socM     = Block::loadSocials($subjectId);
    $orderedM = is_array($socM) ? ($socM['fields']['ordered'] ?? []) : [];
  ?>
  <div class="lg-lm-backdrop" id="lg-links-modal" hidden>
    <div class="lg-lm" role="dialog" aria-modal="true" aria-label="Edit links">
      <div class="lg-lm__head"><strong>Edit links</strong>
        <button type="button" class="lg-lm__close" id="lg-links-modal-close" aria-label="Close">&times;</button></div>
      <p class="lg-lm__hint">These appear as icons in your profile header. Drag to reorder.</p>
      <div class="lg-links lg-links--edit" id="lg-links-edit">
        <?php foreach ($orderedM as $l) { $u = (string)($l['url'] ?? ''); if ($u !== '') echo looth_link_row((string)($l['kind'] ?? ''), $u); } ?>
        <button type="button" class="lg-link__add" id="lg-link-add">+ Add link</button>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php lg_shared_render_site_footer(['logo_url' => LG_PROFILE_APP_LOGO_URL]); ?>

<script>
/* Real map for the location block — Leaflet + OSM tiles (CDN, no WP, no API key).
   ONE map per location block; data-kind="exact" plots the precise pin (marker),
   data-kind="approx" plots the coarse town-level dot (circle). Which one renders
   follows the viewer's permission (use View-as to preview each audience). */
window.addEventListener('load', function () {
  if (typeof L === 'undefined') return;
  document.querySelectorAll('.lg-loc__map[data-lat]').forEach(function (el) {
    var lat = parseFloat(el.getAttribute('data-lat')), lng = parseFloat(el.getAttribute('data-lng'));
    if (isNaN(lat) || isNaN(lng)) return;
    var exact = el.getAttribute('data-kind') === 'exact';
    var zoom  = parseInt(el.getAttribute('data-zoom'), 10) || (exact ? 15 : 11);
    var map = L.map(el, { zoomControl: true, scrollWheelZoom: true, dragging: true,
      doubleClickZoom: true, boxZoom: true, keyboard: true, touchZoom: true }).setView([lat, lng], zoom);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
      { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
    if (exact) {
      // Owner viewing their own exact pin can DRAG it to fine-tune, saved via {pin}
      // (server reverse-geocodes for the coarse label). Visitors get a static marker.
      var ownerPin = el.getAttribute('data-owner-pin') === '1';
      var marker = L.marker([lat, lng], { draggable: ownerPin }).addTo(map);
      if (ownerPin) {
        marker.bindTooltip('Drag to adjust your location', { direction: 'top' });
        marker.on('dragend', function () {
          var ll = marker.getLatLng();
          fetch('/profile-api/v0/me/location', { method: 'PUT', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pin: { lat: ll.lat, lng: ll.lng } }) })
            .then(function (r) { if (r.ok) location.reload(); else alert('Could not save the new pin.'); })
            .catch(function () { alert('Could not save the new pin.'); });
        });
      }
    }
    else {
      var rad = zoom <= 8 ? 35000 : 1500;   // state-level vs town-level blur
      L.circle([lat, lng], { radius: rad, color: '#87986a', fillColor: '#87986a', fillOpacity: 0.18, weight: 1 }).addTo(map);
    }
    setTimeout(function () { map.invalidateSize(); }, 80);   // standalone-shell sizing fix
  });
});
</script>


<script>
/* Gallery carousel viewer — runs for everyone (visitor + owner). Activates on any
   .lg-carousel inside this page; arrows / dots / touch-swipe navigate the track. */
(function () {
  document.querySelectorAll('.lg-carousel').forEach(function (car) {
    var track = car.querySelector('.lg-carousel__track');
    if (!track) return;
    // Photos + the owner's trailing "+" add-tile are all navigable slots; the add
    // tile is the last slide and deliberately has no dot (dots track real photos).
    var slides = track.querySelectorAll('.lg-gphoto, .lg-gphoto__add');
    if (slides.length < 1) return;
    var prev = car.querySelector('.lg-carousel__nav--prev');
    var next = car.querySelector('.lg-carousel__nav--next');
    var dots = car.querySelectorAll('.lg-carousel__dot');
    var idx = 0;

    function go(n) {
      idx = Math.max(0, Math.min(slides.length - 1, n));
      track.style.transform = 'translateX(-' + (idx * 100) + '%)';
      if (prev) prev.disabled = (idx === 0);
      if (next) next.disabled = (idx === slides.length - 1);
      dots.forEach(function (d, i) { d.setAttribute('aria-current', i === idx ? 'true' : 'false'); });
    }
    prev && prev.addEventListener('click', function () { go(idx - 1); });
    next && next.addEventListener('click', function () { go(idx + 1); });
    dots.forEach(function (d, i) { d.addEventListener('click', function () { go(i); }); });

    // Touch swipe.
    var sx = null;
    track.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
    track.addEventListener('touchend', function (e) {
      if (sx === null) return;
      var dx = e.changedTouches[0].clientX - sx; sx = null;
      if (Math.abs(dx) > 50) go(idx + (dx < 0 ? 1 : -1));
    });

    go(0);
  });
})();
</script>

<?php if (!$isOwner): ?>
<script>
document.getElementById('report-link')?.addEventListener('click', function (e) {
  e.preventDefault();
  var reason = prompt('Reason (short)?'); if (!reason) return;
  var body = prompt('Details? (optional)') || '';
  fetch('/profile-api/v0/reports', {method:'POST', credentials:'include',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({target_type:'profile', target_id: <?= $subjectId ?>, reason: reason, body: body})})
    .then(function(r){return r.json();}).then(function(d){ alert(d.ok ? 'Thanks — report logged.' : ('Error: ' + (d.error||'?'))); });
});
</script>
<?php endif; ?>

<?php if ($isOwner): ?>
<script>
/* lgSortable — tiny reusable drag-to-reorder over native HTML5 DnD. Used for links,
   whole blocks, and (later) gallery photos + craft chips.
     container       — the element holding the sortable items
     opts.itemSelector   — CSS for the draggable items
     opts.handleSelector — optional; if set, a drag only starts from this handle
                           (items are non-draggable until the handle is pressed) so
                           clicks/selection inside rich items aren't hijacked
     opts.tailSelector   — optional trailing element to keep last (e.g. a + Add button)
     opts.onDrop(el)     — called after a reorder settles (persist the new order)
   The dragged item follows the cursor live; onDrop fires once on dragend. */
window.lgSortable = function (container, opts) {
  if (!container) return;
  var DCLASS = 'lg-sort-dragging';
  var dragging = null;
  // Data-loss guard (Ian 2026-07-23 audit): a drag reorders by insertBefore()-ing the
  // dragged node repeatedly. If a child inline editor is still in its contentEditable
  // "editing" state, that DOM move fires a blur while the node is momentarily detached,
  // and the field's blur-handler reads innerText='' → saves an EMPTY value (the "About
  // lost its data once" report; also gallery titles). Commit any active editor BEFORE
  // the node ever moves — while it's still attached, so blur reads the correct value.
  function commitActiveEdit() {
    var a = document.activeElement;
    if (a && a !== document.body && (a.isContentEditable || a.getAttribute('contenteditable') === 'true')) {
      a.blur();
    }
  }
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
    // Handle-gated: items become draggable only while the handle is pressed.
    container.addEventListener('mousedown', function (e) {
      var h = e.target.closest(opts.handleSelector);
      if (!h || !container.contains(h)) return;
      commitActiveEdit();   // settle any open inline editor before the drag arms
      var el = h.closest(opts.itemSelector);
      if (el) el.setAttribute('draggable', 'true');
    });
    container.addEventListener('mouseup', clearHandleFlags);
  }
  container.addEventListener('dragstart', function (e) {
    var el = e.target.closest(opts.itemSelector);
    if (!el || !container.contains(el)) return;
    commitActiveEdit();   // belt+braces: dragstart fires before any insertBefore move
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
/* Owner layout controls — whole-block reorder (⠿ grip), per-block remove (✕), and the
   Sections caddy (tap-to-add, or drag a block from the caddy onto the profile). Order
   persists to /me/layout with NO reload; add & remove reload so the server re-renders the
   affected block(s) + the caddy (and so a newly added block's inline editors wire up). */
(function () {
  var profile = document.querySelector('.lg-profile');
  if (!profile) return;

  function bodyBlocks() {
    return Array.prototype.slice.call(profile.querySelectorAll('.lg-block:not(.lg-block--header)'));
  }
  function order() {
    return bodyBlocks().map(function (s) { return s.getAttribute('data-block'); }).filter(Boolean);
  }
  function putLayout(arr, then) {
    fetch('/profile-api/v0/me/layout', {
      method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order: arr })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (res.ok) { if (then) then(); } else alert('Save failed: ' + (res.j && res.j.error || '?')); })
      .catch(function () { alert('Network error.'); });
  }

  // Section icon SVG per block (matches the builder palette bubbles).
  var SECIC_PATHS = {
    about:       '<circle cx="12" cy="8" r="3.5"/><path d="M5.5 19a6.5 6.5 0 0 1 13 0"/>',
    instruments: '<path d="m11.9 12.1 4.514-4.514"/><path d="M20.1 2.3a1 1 0 0 0-1.4 0l-1.114 1.114A2 2 0 0 0 17 4.828v1.344a2 2 0 0 1-.586 1.414A2 2 0 0 1 17.828 7h1.344a2 2 0 0 0 1.414-.586L21.7 5.3a1 1 0 0 0 0-1.4z"/><path d="m6 16 2 2"/><path d="M8.23 9.85A3 3 0 0 1 11 8a5 5 0 0 1 5 5 3 3 0 0 1-1.85 2.77l-.92.38A2 2 0 0 0 12 18a4 4 0 0 1-4 4 6 6 0 0 1-6-6 4 4 0 0 1 4-4 2 2 0 0 0 1.85-1.23z"/>',
    skills:      '<path d="M12 3.5l2.5 5.2 5.7.8-4.1 4 1 5.7L12 16.6 6.9 19.2l1-5.7-4.1-4 5.7-.8z"/>',
    services:    '<path d="M15.6 7.4a3.6 3.6 0 0 0-4.7 4.4l-6.1 6.1 2.3 2.3 6.1-6.1a3.6 3.6 0 0 0 4.4-4.7l-2.2 2.2-2-2 2.2-2.2z"/>',
    music:       '<path d="M9 17V5l10-2v12"/><circle cx="6.5" cy="17" r="2.5"/><circle cx="16.5" cy="15" r="2.5"/>',
    location:    '<path d="M12 21s7-5.8 7-11a7 7 0 1 0-14 0c0 5.2 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
    gallery:     '<rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="1.7"/><path d="M5 17l4.5-4.5 3 3L16 11l3 3.4"/>',
    'gallery-2':  '<rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="1.7"/><path d="M5 17l4.5-4.5 3 3L16 11l3 3.4"/>',
    'gallery-3':  '<rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="1.7"/><path d="M5 17l4.5-4.5 3 3L16 11l3 3.4"/>',
    connect:     '<circle cx="8.5" cy="9" r="2.8"/><circle cx="16" cy="9.5" r="2.3"/><path d="M3.5 19a5 5 0 0 1 10 0"/><path d="M14 19a4.3 4.3 0 0 1 6.5-3.7"/>',
    socials:     '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17"/><path d="M12 3.5c2.6 2.4 2.6 14.6 0 17"/><path d="M12 3.5c-2.6 2.4-2.6 14.6 0 17"/>',
    resume:      '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="M10 13.2h6"/><path d="M10 16.6h6"/><path d="M10 9.8h2"/>'
  };
  function icFor(key) {
    if (!key) return '';
    var p = SECIC_PATHS[key];
    return p ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + p + '</svg>' : '';
  }

  // Inject a dot-grid grip + section icon chip + a remove ✕ into each body block's heading.
  // EDIT MODE ONLY (the caddy renders exactly when $editing): in View-as Member/Public the
  // owner must see the page exactly as that audience does — no builder chrome in headings.
  if (document.getElementById('lg-caddy')) bodyBlocks().forEach(function (b) {
    var host = b.querySelector('.lg-bh') || b;
    if (!host.querySelector('.lg-block__grip')) {
      var grip = document.createElement('span');
      grip.className = 'lg-block__grip'; grip.setAttribute('title', 'Drag to reorder');
      grip.setAttribute('aria-hidden', 'true');
      grip.innerHTML = '<i></i><i></i><i></i><i></i><i></i><i></i>';
      host.insertBefore(grip, host.firstChild);
    }
    if (!host.querySelector('.lg-secic')) {
      var ic = icFor(b.getAttribute('data-block'));
      if (ic) {
        var chip = document.createElement('span');
        chip.className = 'lg-secic'; chip.setAttribute('aria-hidden', 'true'); chip.innerHTML = ic;
        var g = host.querySelector('.lg-block__grip');
        host.insertBefore(chip, g ? g.nextSibling : host.firstChild);
      }
    }
    if (!host.querySelector('.lg-block__rm')) {
      var rm = document.createElement('button');
      rm.type = 'button'; rm.className = 'lg-block__rm';
      rm.setAttribute('title', 'Remove this block'); rm.setAttribute('aria-label', 'Remove block');
      rm.textContent = '✕';
      var grip2 = host.querySelector('.lg-block__grip');
      host.insertBefore(rm, grip2 ? grip2.nextSibling : host.firstChild);
    }
    // Up/Down buttons (Buck 2026-06-11): one-tap section reorder — the drag grip
    // is fiddly on phones. Same /me/layout persist as drag, no reload.
    if (!host.querySelector('.lg-block__mv--up')) {
      var mkMv = function (dir, label, path) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'lg-block__mv lg-block__mv--' + dir;
        btn.setAttribute('data-mv', dir);
        btn.setAttribute('title', label); btn.setAttribute('aria-label', label);
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
        return btn;
      };
      var mvUp = mkMv('up', 'Move section up', '<path d="m5 14.5 7-7 7 7"/>');
      var mvDn = mkMv('down', 'Move section down', '<path d="m5 9.5 7 7 7-7"/>');
      var rmEl = host.querySelector('.lg-block__rm');
      host.insertBefore(mvDn, rmEl);
      host.insertBefore(mvUp, mvDn);
    }
  });

  // Grey out the arrows that can't act (first block's ↑, last block's ↓).
  function refreshMvDisabled() {
    var list = bodyBlocks();
    list.forEach(function (b, i) {
      var u = b.querySelector('.lg-block__mv--up'), d = b.querySelector('.lg-block__mv--down');
      if (u) u.disabled = (i === 0);
      if (d) d.disabled = (i === list.length - 1);
    });
  }
  refreshMvDisabled();

  // Move a section one slot up/down → same no-reload persist as drag.
  profile.addEventListener('click', function (e) {
    var mv = e.target.closest('.lg-block__mv');
    if (!mv || mv.disabled) return;
    var block = mv.closest('.lg-block:not(.lg-block--header)');
    if (!block) return;
    var list = bodyBlocks();
    var i = list.indexOf(block);
    if (i < 0) return;
    if (mv.getAttribute('data-mv') === 'up') {
      if (i === 0) return;
      list[i - 1].parentNode.insertBefore(block, list[i - 1]);
    } else {
      if (i === list.length - 1) return;
      list[i + 1].parentNode.insertBefore(block, list[i + 1].nextSibling);
    }
    putLayout(order());
    refreshMvDisabled();
    try { block.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (err) {}
  });

  // Remove a block → drop its key from the layout, reload (it returns to the caddy; data kept).
  profile.addEventListener('click', function (e) {
    var rm = e.target.closest('.lg-block__rm');
    if (!rm) return;
    var block = rm.closest('.lg-block:not(.lg-block--header)');
    if (!block) return;
    var key = block.getAttribute('data-block');
    putLayout(order().filter(function (k) { return k !== key; }), function () { location.reload(); });
  });

  // Reorder = no reload.
  lgSortable(profile, {
    itemSelector: '.lg-block:not(.lg-block--header)',
    handleSelector: '.lg-block__grip',
    onDrop: function () { putLayout(order()); refreshMvDisabled(); }
  });

  /* ---- caddy: open / close ---- */
  var caddy    = document.getElementById('lg-caddy');
  var toggle   = document.getElementById('lg-caddy-toggle');
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
  // ≥1380px the caddy is a permanent floating sidebar (CSS); below, it's an off-canvas drawer.
  var deskMq = window.matchMedia('(min-width:1380px)');
  function syncCaddyMode() {
    if (!caddy) return;
    if (deskMq.matches) {                              // permanent: always visible, never a drawer
      caddy.classList.remove('is-open'); caddy.setAttribute('aria-hidden', 'false');
      if (backdrop) { backdrop.classList.remove('is-open'); backdrop.hidden = true; }
    } else if (!caddy.classList.contains('is-open')) {  // drawer, closed
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
    if (!deskMq.matches && location.hash === '#caddy') openCaddy();   // re-open the drawer across a mobile add
    syncCaddyMode();
  }

  /* ---- caddy: add a block (tap appends; drag drops at a position) ---- */
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
      if (!item || item.classList.contains('is-used')) return;  // placed bubbles are inert
      addBlock(item.getAttribute('data-block'));                 // tap-to-add (appends)
    });
  }

  /* ---- caddy → profile drag (desktop): drop a caddy block among the blocks to add there ---- */
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
  // Only handle caddy drags here; block-reorder drags are owned by lgSortable (its dragover
  // early-returns when no block is being dragged, so the two never collide).
  profile.addEventListener('dragover', function (e) {
    if (!caddyDragKey) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    clearDropMarks();
    pendingIndex = dropIndex(e.clientY);
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
/* Inline per-block privacy (pmp) control — owner/Me view. The chips rendered by
   looth_pmp_control() are <button.lg-pmp> carrying the block id, current vis, and
   the header ceiling. Clicking opens a menu; selecting persists via the existing
   /me endpoints, then reloads so the server re-derives ceilings + the gate (keeps
   View-as honest). Server stays the source of truth (validation + the gate). */
(function () {
  var BASE = '/profile-api/v0';
  // endpoint + method + payload key per block.
  var EP = {
    'header':          { url: BASE + '/me/header',   m: 'PATCH', k: 'visibility' },
    'craft':           { url: BASE + '/me/craft',    m: 'PATCH', k: 'visibility' },
    'skills':          { url: BASE + '/me/catalog/skills',      m: 'PUT', k: 'visibility' },
    'services':        { url: BASE + '/me/catalog/services',    m: 'PUT', k: 'visibility' },
    'instruments':     { url: BASE + '/me/catalog/instruments', m: 'PUT', k: 'visibility' },
    'music':           { url: BASE + '/me/catalog/music',       m: 'PUT', k: 'visibility' },
    'connect':         { url: BASE + '/me/connect',  m: 'PATCH', k: 'visibility' },
    'about':           { url: BASE + '/me/about',    m: 'PATCH', k: 'visibility' },
    'gallery':         { url: BASE + '/me/gallery?g=1', m: 'PUT', k: 'visibility' },
    'gallery-2':       { url: BASE + '/me/gallery?g=2', m: 'PUT', k: 'visibility' },
    'gallery-3':       { url: BASE + '/me/gallery?g=3', m: 'PUT', k: 'visibility' },
    'socials':         { url: BASE + '/me/socials',  m: 'PUT',   k: 'visibility' },
    'location-approx': { url: BASE + '/me/location', m: 'PUT',   k: 'location_visibility' },
    'location-exact':  { url: BASE + '/me/location', m: 'PUT',   k: 'location_exact_visibility' }
  };
  // tiers per block, as DB-literal values (what every endpoint accepts).
  var TIERS = {
    'location-exact': ['members', 'private', 'on_request'],
    '_default':       ['public', 'members', 'private']
  };
  var LABEL = { 'public': 'Public', 'members': 'Member', 'private': 'Private', 'on_request': 'On request' };
  // Plain-language descriptions shown under each option (Buck launch ask).
  var DESC = { 'public': 'Anyone, even logged-out visitors', 'members': 'Signed-in Looth members only',
               'private': 'Only you', 'on_request': 'Hidden until you approve a request' };
  var DEFAULT_TIER = 'members';   // platform default (Ian: keep members-default)
  // restrictiveness rank; on_request is treated as restrictive as private for capping.
  var RANK = { 'public': 0, 'members': 1, 'private': 2, 'on_request': 2 };

  var openMenu = null;
  function closeMenu() { if (openMenu) { openMenu.remove(); openMenu = null; } }
  document.addEventListener('click', function (e) {
    if (openMenu && !openMenu.contains(e.target) && !e.target.closest('.lg-pmp')) closeMenu();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });

  function tiersFor(block) { return TIERS[block] || TIERS._default; }

  function buildMenu(btn) {
    var block   = btn.getAttribute('data-pmp-block');
    var current = btn.getAttribute('data-pmp-vis');
    var ceiling = btn.getAttribute('data-pmp-ceiling') || '';
    var menu = document.createElement('div');
    menu.className = 'lg-pmp-menu';
    menu.setAttribute('role', 'menu');
    menu.innerHTML = '<div class="lg-pmp-menu__head">Who can see this</div>';
    tiersFor(block).forEach(function (tier) {
      var capped = ceiling && RANK[tier] < RANK[ceiling];   // more open than the header allows
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'menuitemradio');
      if (tier === current) b.setAttribute('aria-current', 'true');
      b.innerHTML = '<span class="lg-pmp-menu__opt">' +
          '<span class="lg-pmp-menu__lab">' + LABEL[tier] +
            (tier === DEFAULT_TIER ? ' <span class="lg-pmp-menu__def">default</span>' : '') + '</span>' +
          '<span class="lg-pmp-menu__desc">' + DESC[tier] + '</span>' +
        '</span>' +
        (capped ? '<span class="cap">limited by header</span>' : '');
      b.addEventListener('click', function () {
        if (tier === current) { closeMenu(); return; }
        save(btn, block, tier);
      });
      menu.appendChild(b);
    });
    return menu;
  }

  function save(btn, block, tier) {
    var ep = EP[block]; if (!ep) return;
    var body = {}; body[ep.k] = tier;
    btn.disabled = true;
    fetch(ep.url, { method: ep.m, credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok) { location.reload(); }
        else { btn.disabled = false; alert('Could not change visibility: ' + (res.j && res.j.error || '?')); }
      })
      .catch(function () { btn.disabled = false; alert('Network error.'); });
  }

  document.querySelectorAll('.lg-pmp').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var wasOpenFor = openMenu && openMenu._owner === btn;
      closeMenu();
      if (wasOpenFor) return;
      var menu = buildMenu(btn);
      menu._owner = btn;
      document.body.appendChild(menu);
      var r = btn.getBoundingClientRect();
      menu.style.top  = (window.scrollY + r.bottom + 6) + 'px';
      menu.style.left = (window.scrollX + Math.min(r.left, document.documentElement.clientWidth - 230)) + 'px';
      openMenu = menu;
    });
  });
})();
</script>

<script>
/* Discussion-author posting visibility (owner) — the 2-state Public / Member-only
   toggle in the View-as bar. PUTs the live preference to the profile-app backend;
   the Hub reads it to mask member-only authors from logged-out viewers (member-only =
   logged-out see "private member" + fallback avatar; signed-in members always see the
   real author). No reload: /u/ renders no discussion cards, so the choice has no
   on-page effect — optimistic toggle + note swap is enough. Endpoint contract:
   PUT /profile-api/v0/me/discussion-visibility {discussion_visibility:'public'|'member'}. */
(function () {
  var seg = document.querySelector('.lg-disc-seg');
  if (!seg) return;
  var note = document.getElementById('lg-disc-note');
  var NOTE = {
    'public': 'Your name & avatar show on your discussion posts to everyone.',
    'member': 'Logged-out visitors see "private member" on your discussion posts; signed-in members see you.'
  };
  seg.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-disc]'); if (!btn) return;
    var want = btn.getAttribute('data-disc');
    var cur  = seg.getAttribute('data-disc-current');
    if (want === cur) return;
    var btns = seg.querySelectorAll('button[data-disc]');
    btns.forEach(function (b) { b.setAttribute('aria-checked', b === btn ? 'true' : 'false'); b.disabled = true; });  // optimistic
    fetch('/profile-api/v0/me/discussion-visibility', {
      method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ discussion_visibility: want })
    }).then(function (r) {
      btns.forEach(function (b) { b.disabled = false; });
      if (!r.ok) throw 0;
      seg.setAttribute('data-disc-current', want);
      if (note && NOTE[want]) note.textContent = NOTE[want];
    }).catch(function () {
      btns.forEach(function (b) { b.setAttribute('aria-checked', b.getAttribute('data-disc') === cur ? 'true' : 'false'); b.disabled = false; });  // revert
      alert('Could not update discussion posting visibility.');
    });
  });
})();
</script>

<script>
/* Location editor (owner/Me) — verbatim address bar + drag-a-pin fallback.
   Type the address, press Enter → PUT /me/location {address:"…"}. The server stores
   the text VERBATIM (no picker, no autocomplete) and forward-geocodes server-side to
   drop the map pin. If it can't place it (geocoded:false), a draggable Leaflet pin
   appears, centered on the server's suggested center → PUT {pin:{lat,lng}} → reload. */
(function () {
  var wrap = document.getElementById('lg-loc-edit');
  if (!wrap) return;
  var btn = wrap.querySelector('.lg-loc__change');
  if (!btn) return;

  function put(body) {
    return fetch('/profile-api/v0/me/location', { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) {
        return r.json().then(function (j) { return { ok: r.ok, j: j }; },
                             function () { return { ok: r.ok, j: {} }; });
      });
  }

  // Leaflet is loaded deferred in <head>; by interaction time it's usually ready.
  // Poll briefly, then call back regardless (the callback guards for !window.L).
  function ensureLeaflet(cb) {
    var n = 0;
    (function wait() {
      if (window.L || n++ > 50) return cb();   // ~5s ceiling
      setTimeout(wait, 100);
    })();
  }

  function esc(s) { return String(s).replace(/[&<>"]/g, ''); }

  var hint = wrap.querySelector('.lg-loc__hint');

  btn.addEventListener('click', function () {
    if (wrap.querySelector('.lg-locedit')) return;
    btn.style.display = 'none';
    if (hint) hint.style.display = 'none';

    var panel = document.createElement('div'); panel.className = 'lg-locedit';
    panel.innerHTML =
      '<p class="lg-locedit__help">Type your address and press <b>Enter</b>. It’s listed ' +
      'exactly as you write it, and we’ll place you on the map automatically. ' +
      'If we can’t find it, drag the pin to your spot.</p>' +
      '<div class="lg-locedit__row">' +
        '<input type="text" class="lg-locedit__in" placeholder="123 Main St, City, State" autocomplete="off">' +
        '<button type="button" class="lg-link__add lg-locedit__save">Save</button>' +
        '<button type="button" class="lg-locedit__cancel" aria-label="Cancel">Cancel</button>' +
      '</div>' +
      '<div class="lg-locedit__status" aria-live="polite"></div>';
    wrap.appendChild(panel);

    var inp     = panel.querySelector('.lg-locedit__in');
    var saveBtn = panel.querySelector('.lg-locedit__save');
    var status  = panel.querySelector('.lg-locedit__status');
    inp.focus();

    var busy = false;
    function close() { panel.remove(); btn.style.display = ''; if (hint) hint.style.display = ''; }
    panel.querySelector('.lg-locedit__cancel').addEventListener('click', close);

    function submitAddress() {
      var v = inp.value.trim();
      if (!v || busy) return;
      busy = true; saveBtn.disabled = true; inp.disabled = true;
      status.textContent = 'Saving…';
      put({ address: v }).then(function (res) {
        if (!res.ok) {
          busy = false; saveBtn.disabled = false; inp.disabled = false;
          status.textContent = 'Could not save — please try again.';
          return;
        }
        if (res.j && res.j.geocoded) { location.reload(); return; }   // placed → done
        showPinDragger(v, (res.j && res.j.center) || { lat: 39.8283, lng: -98.5795, zoom: 4 });
      });
    }

    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); submitAddress(); }
      else if (e.key === 'Escape') { close(); }
    });
    saveBtn.addEventListener('click', submitAddress);

    // Fallback: we kept the verbatim address but couldn't geocode it — let the owner
    // place the pin by hand. Saved via {pin}, which reverse-geocodes server-side so
    // the City/State coarse label still works.
    function showPinDragger(addrText, center) {
      panel.querySelector('.lg-locedit__row').style.display = 'none';
      status.innerHTML = '<p class="lg-locedit__help">Saved “<b>' + esc(addrText) +
        '</b>”, but we couldn’t place it on the map. Drag the pin to your exact ' +
        'spot (or click the map), then <b>Save pin</b>.</p>';

      var mapEl = document.createElement('div'); mapEl.className = 'lg-loc__pin lg-locedit__map';
      panel.appendChild(mapEl);
      var actions = document.createElement('div'); actions.className = 'lg-locedit__row';
      actions.innerHTML =
        '<button type="button" class="lg-link__add lg-locedit__pinsave">Save pin</button>' +
        '<button type="button" class="lg-locedit__cancel2">Cancel</button>';
      panel.appendChild(actions);
      actions.querySelector('.lg-locedit__cancel2').addEventListener('click', close);

      ensureLeaflet(function () {
        if (!window.L) { status.textContent = 'Map unavailable — please try again later.'; return; }
        var map = L.map(mapEl, { zoomControl: true, scrollWheelZoom: false })
          .setView([center.lat, center.lng], center.zoom || 11);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
          { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
        var marker = L.marker([center.lat, center.lng], { draggable: true }).addTo(map);
        marker.bindTooltip('Drag me to your spot', { direction: 'top' }).openTooltip();
        map.on('click', function (e) { marker.setLatLng(e.latlng); });   // tap the map to move the pin
        setTimeout(function () { map.invalidateSize(); }, 60);           // panel just inserted

        var pinBtn = actions.querySelector('.lg-locedit__pinsave');
        var saving = false;
        function savePin() {
          if (saving) return;
          saving = true; pinBtn.disabled = true;
          var ll = marker.getLatLng();
          put({ pin: { lat: ll.lat, lng: ll.lng } }).then(function (res) {
            if (res.ok) location.reload();
            else { saving = false; pinBtn.disabled = false; alert('Could not save pin.'); }
          });
        }
        marker.on('dragend', savePin);                 // drop the pin → save (keeper-requested)
        pinBtn.addEventListener('click', savePin);     // explicit commit (after tap-to-move)
      });
    }
  });
})();

/* Location extras editor (owner/Me) — save address-detail / hours / note →
   PUT /me/location {details:{address,hours,note}} → reload. */
(function () {
  var box = document.getElementById('lg-loc-details');
  if (!box) return;
  var btn = box.querySelector('.lg-loc__details-save');
  if (!btn) return;
  btn.addEventListener('click', function () {
    function v(f) { var el = box.querySelector('[data-f="' + f + '"]'); return el ? el.value.trim() : ''; }
    btn.disabled = true;
    fetch('/profile-api/v0/me/location', { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ details: { address: v('address'), hours: v('hours'), note: v('note') } }) })
      .then(function (r) { if (r.ok) location.reload(); else { btn.disabled = false; alert('Could not save details.'); } })
      .catch(function () { btn.disabled = false; alert('Could not save details.'); });
  });
})();
</script>

<script>
/* Location precision pickers (owner/Me) — "Members see" / "Public sees", each set
   to private|state|city|street, persisted via PUT /me/location, then reload. */
(function () {
  var LEVELS = ['private', 'state', 'city', 'street'];
  var LABEL = { private: 'Private', state: 'State', city: 'City', street: 'Street address' };
  var open = null;
  function close() { if (open) { open.remove(); open = null; } }
  document.addEventListener('click', function (e) {
    if (open && !open.contains(e.target) && !e.target.closest('.lg-prec')) close();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

  function save(aud, value) {
    var body = {}; body[aud + '_precision'] = value;
    fetch('/profile-api/v0/me/location', { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (res.ok) location.reload(); else alert('Failed: ' + (res.j && res.j.error || '?')); })
      .catch(function () { alert('Network error.'); });
  }

  document.querySelectorAll('.lg-prec').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      var wasOpen = open && open._owner === btn; close(); if (wasOpen) return;
      var aud = btn.getAttribute('data-prec-aud'), cur = btn.getAttribute('data-prec');
      var menu = document.createElement('div'); menu.className = 'lg-pmp-menu'; menu.setAttribute('role', 'menu');
      menu.innerHTML = '<div class="lg-pmp-menu__head">What ' + aud + ' see</div>';
      LEVELS.forEach(function (lv) {
        var b = document.createElement('button'); b.type = 'button';
        if (lv === cur) b.setAttribute('aria-current', 'true');
        b.innerHTML = '<span>' + LABEL[lv] + '</span>';
        b.addEventListener('click', function () { if (lv === cur) { close(); return; } save(aud, lv); });
        menu.appendChild(b);
      });
      menu._owner = btn; document.body.appendChild(menu);
      var r = btn.getBoundingClientRect();
      menu.style.top = (window.scrollY + r.bottom + 6) + 'px';
      menu.style.left = (window.scrollX + Math.min(r.left, document.documentElement.clientWidth - 230)) + 'px';
      open = menu;
    });
  });
})();
</script>

<script>
/* Avatar single-source uploader (owner/Me). The header renders a camera affordance
   (.lg-idrow__cam); clicking opens a file picker → POST the image to
   /me/avatar → the endpoint stores bytes, bumps avatar_version, sets the versioned
   served URL, and purges /whoami so mirrors re-pull. Reload to show the new image. */
(function () {
  var cam = document.querySelector('.lg-idrow__cam');
  if (!cam) return;
  var CAM_HTML = cam.innerHTML;   // restore the camera icon after a failed upload
  var input = document.createElement('input');
  input.type = 'file';
  input.accept = 'image/jpeg,image/png,image/webp';
  input.style.display = 'none';
  document.body.appendChild(input);

  cam.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); input.click(); });
  input.addEventListener('change', function () {
    if (!input.files || !input.files[0]) return;
    var f = input.files[0];
    if (f.size > 5 * 1024 * 1024) { alert('Image too large (max 5 MB).'); input.value = ''; return; }
    var fd = new FormData();
    fd.append('avatar', f);
    cam.textContent = '…';
    fetch('/profile-api/v0/me/avatar', { method: 'POST', credentials: 'include', body: fd })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok) { location.reload(); }
        else { cam.innerHTML = CAM_HTML; alert('Upload failed: ' + (res.j && res.j.error || '?')); }
      })
      .catch(function () { cam.innerHTML = CAM_HTML; alert('Network error.'); });
  });

  // Remove-photo (owner, custom avatar only) → revert to the branded fallback.
  var rm = document.querySelector('.lg-idrow__avrm');
  if (rm) rm.addEventListener('click', function (e) {
    e.preventDefault(); e.stopPropagation();
    if (!confirm('Remove your profile photo? You’ll go back to the default.')) return;
    rm.disabled = true;
    fetch('/profile-api/v0/me/avatar', { method: 'DELETE', credentials: 'include' })
      .then(function (r) { if (r.ok) location.reload(); else { rm.disabled = false; alert('Remove failed.'); } })
      .catch(function () { rm.disabled = false; alert('Network error.'); });
  });
})();
</script>

<script>
/* Inline content editing (owner/Me view) — the start of the composer. Click any
   .lg-edit field → it becomes contentEditable → Enter/blur saves via the field's
   own /me/* endpoint (already green); Esc cancels. Empty fields show a placeholder. */
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
    if (field === 'display_name' && val === '') { el.textContent = orig; finish(el); return; } // name required
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
    if (e.key === 'Enter' && !el.hasAttribute('data-edit-multiline')) { e.preventDefault(); el.blur(); } // multiline keeps Enter as newline
    else if (e.key === 'Escape') {
      e.preventDefault();
      el.removeEventListener('keydown', onKey);
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
/* Links editor (owner/Me) — add/remove links; PUT the whole list to me-socials → reload. */
(function () {
  var KINDS = ['web','instagram','youtube','x','facebook','tiktok','bandcamp','patreon','linktree','email','phone'];
  var wrap = document.getElementById('lg-links-edit');
  if (!wrap) return;

  function collect() {
    return Array.prototype.map.call(wrap.querySelectorAll('.lg-link'), function (el, i) {
      return { kind: el.getAttribute('data-kind'), value: el.getAttribute('data-value'), sort_order: i };
    });
  }
  function stripScheme(v) { return String(v).replace(/^https?:\/\//i, ''); }
  function rowEl(kind, value) {
    var row = document.createElement('div'); row.className = 'lg-link';
    row.setAttribute('draggable', 'true');
    row.setAttribute('data-kind', kind); row.setAttribute('data-value', value);
    var grip = document.createElement('span'); grip.className = 'lg-link__grip'; grip.setAttribute('aria-hidden', 'true'); grip.textContent = '⠿';
    row.appendChild(grip);
    var k = document.createElement('span'); k.className = 'lg-link__kind'; k.textContent = kind;
    var v = document.createElement('span'); v.className = 'lg-link__val'; v.textContent = stripScheme(value);
    var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'lg-link__rm';
    rm.setAttribute('aria-label', 'Remove'); rm.textContent = '×';
    row.appendChild(k); row.appendChild(v); row.appendChild(rm);
    return row;
  }
  // Re-render rows in place from the server's canonical ordered list — no full-page
  // reload (keeps scroll/place), and reflects the persisted drag order.
  function render(socials) {
    var addBtn = document.getElementById('lg-link-add');
    Array.prototype.forEach.call(wrap.querySelectorAll('.lg-link, .lg-link-form'), function (el) { el.remove(); });
    var f = (socials && socials.fields) || {};
    (f.ordered || []).forEach(function (l) { wrap.insertBefore(rowEl(l.kind, l.url), addBtn); });
  }
  function put(items) {
    fetch('/profile-api/v0/me/socials', { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ items: items }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) { if (res.ok) render(res.j && res.j.socials); else alert('Save failed: ' + (res.j && res.j.error || '?')); })
      .catch(function () { alert('Network error.'); });
  }

  wrap.addEventListener('click', function (e) {
    var rm = e.target.closest('.lg-link__rm');
    if (rm) { rm.closest('.lg-link').remove(); put(collect()); }
  });

  var addBtn = document.getElementById('lg-link-add');
  addBtn && addBtn.addEventListener('click', function () {
    if (document.querySelector('.lg-link-form')) return;
    var form = document.createElement('div'); form.className = 'lg-link-form';
    var sel = document.createElement('select');
    KINDS.forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = k; sel.appendChild(o); });
    var inp = document.createElement('input'); inp.type = 'text'; inp.placeholder = 'handle or URL';
    var ok = document.createElement('button'); ok.className = 'ok'; ok.textContent = 'Add';
    var cancel = document.createElement('button'); cancel.className = 'cancel'; cancel.textContent = 'Cancel';
    form.appendChild(sel); form.appendChild(inp); form.appendChild(ok); form.appendChild(cancel);
    addBtn.parentNode.insertBefore(form, addBtn);
    inp.focus();
    cancel.addEventListener('click', function () { form.remove(); });
    ok.addEventListener('click', function () {
      var v = inp.value.trim(); if (!v) { inp.focus(); return; }
      var items = collect(); items.push({ kind: sel.value, value: v, sort_order: items.length });
      put(items);
    });
    inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') ok.click(); else if (e.key === 'Escape') cancel.click(); });
  });

  /* Drag-to-reorder the links (rows are draggable in markup; keep #lg-link-add last). */
  lgSortable(wrap, {
    itemSelector: '.lg-link',
    tailSelector: '#lg-link-add',
    onDrop: function () { put(collect()); }
  });
})();
</script>


<script>
/* Catalog chip pickers (owner/Me) — Skills / Services / Instruments / Music. Each .lg-cat-edit
   block searches its catalog (data-kind), click a result to add / ✕ a chip to remove → PUT the
   id list to /me/catalog/<block>. ADMINS additionally get, in the dropdown: "＋ Add '<term>'"
   to create a not-found catalog item, and a 🗑 on each result to deactivate it for everyone. */
(function () {
  var IS_ADMIN = <?= Auth::isAdmin() ? 'true' : 'false' ?>;
  var catalogs = {};   // kind → Promise<[{id,name,lc}]>
  function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function loadCatalog(kind) {
    if (!catalogs[kind]) {
      catalogs[kind] = fetch('/profile-api/v0/catalogs/' + kind, { credentials: 'include' })
        .then(function (r) { return r.json(); })
        .then(function (j) { return (j.items || []).map(function (x) { return { id: x.id, name: x.name, lc: (x.name || '').toLowerCase() }; }); });
    }
    return catalogs[kind];
  }

  document.querySelectorAll('.lg-cat-edit').forEach(function (wrap) {
    var block  = wrap.getAttribute('data-block');
    var kind   = wrap.getAttribute('data-kind');
    var addBtn = wrap.querySelector('.lg-cat-add');
    if (!addBtn) return;

    function ids() {
      return Array.prototype.map.call(wrap.querySelectorAll('.lg-chip--edit'),
        function (el) { return parseInt(el.getAttribute('data-id'), 10); });
    }
    function put() {
      return fetch('/profile-api/v0/me/catalog/' + block, { method: 'PUT', credentials: 'include',
        headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ids: ids() }) })
        .then(function (r) { return r.ok; });
    }
    function makeChip(id, name) {
      var s = document.createElement('span'); s.className = 'lg-chip lg-chip--edit';
      s.setAttribute('data-id', id); s.textContent = name;
      var b = document.createElement('button'); b.type = 'button'; b.className = 'lg-chip__rm';
      b.setAttribute('aria-label', 'Remove'); b.textContent = '×'; s.appendChild(b);
      return s;
    }
    function addItem(id, name) {
      if (ids().indexOf(id) !== -1) return;
      var chip = makeChip(id, name);
      wrap.insertBefore(chip, wrap.querySelector('.lg-craft-search') || addBtn);
      put().then(function (ok) { if (!ok) { chip.remove(); alert('Add failed'); } });
    }

    wrap.addEventListener('click', function (e) {
      var rm = e.target.closest('.lg-chip__rm'); if (!rm) return;
      var chip = rm.closest('.lg-chip--edit'); chip.remove();
      put().then(function (ok) { if (!ok) { alert('Remove failed'); location.reload(); } });
    });

    addBtn.addEventListener('click', function () {
      if (wrap.querySelector('.lg-craft-search')) return;
      var box = document.createElement('span'); box.className = 'lg-craft-search';
      var inp = document.createElement('input'); inp.type = 'text';
      inp.placeholder = 'Search ' + addBtn.textContent.replace(/^\+\s*Add\s*/i, '') + '…';
      var res = document.createElement('div'); res.className = 'lg-craft-results'; res.style.display = 'none';
      box.appendChild(inp); box.appendChild(res);
      addBtn.parentNode.insertBefore(box, addBtn); addBtn.style.display = 'none'; inp.focus();

      function has(id) { return ids().indexOf(id) !== -1; }
      function render() {
        loadCatalog(kind).then(function (cat) {
          var q = inp.value.trim(), ql = q.toLowerCase();
          res.innerHTML = '';
          if (ql === '') { res.style.display = 'none'; return; }
          var matches = cat.filter(function (c) { return c.lc.indexOf(ql) !== -1; }).slice(0, 40);
          var exact   = cat.some(function (c) { return c.lc === ql; });
          matches.forEach(function (m) {
            var row = document.createElement('div'); row.className = 'lg-craft-results__row';
            var added = has(m.id);
            var pick = document.createElement('button'); pick.type = 'button'; pick.className = 'pick';
            pick.innerHTML = '<span>' + esc(m.name) + '</span><span class="' + (added ? 'added' : 't') + '">' + (added ? 'added' : '') + '</span>';
            if (!added) pick.addEventListener('click', function () { addItem(m.id, m.name); render(); inp.focus(); });
            row.appendChild(pick);
            if (IS_ADMIN) {
              var del = document.createElement('button'); del.type = 'button'; del.className = 'del';
              del.title = 'Remove from catalog (admin)'; del.textContent = '×';
              del.addEventListener('click', function () {
                if (!confirm('Remove “' + m.name + '” from the catalog for everyone?')) return;
                fetch('/profile-api/v0/catalogs/' + kind + '/' + m.id, { method: 'DELETE', credentials: 'include' })
                  .then(function (r) { if (r.ok) { delete catalogs[kind]; render(); } else alert('Catalog remove failed (admin only).'); });
              });
              row.appendChild(del);
            }
            res.appendChild(row);
          });
          if (IS_ADMIN && q && !exact) {                       // admin: create a not-found item
            var add = document.createElement('button'); add.type = 'button'; add.className = 'lg-cat-new';
            add.innerHTML = '<span>＋ Add “' + esc(q) + '” to catalog</span><span class="t">new</span>';
            add.addEventListener('click', function () {
              fetch('/profile-api/v0/catalogs/' + kind, { method: 'POST', credentials: 'include',
                headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name: q }) })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (x) {
                  if (x.ok && x.j.item) { delete catalogs[kind]; addItem(x.j.item.id, x.j.item.name); inp.value = ''; render(); inp.focus(); }
                  else alert('Add to catalog failed (admin only).');
                });
            });
            res.appendChild(add);
          } else if (!matches.length) {
            res.innerHTML = '<div class="none">No matches</div>';
          }
          res.style.display = 'block';
        });
      }
      inp.addEventListener('input', render);
      inp.addEventListener('keydown', function (e) { if (e.key === 'Escape') { box.remove(); addBtn.style.display = ''; } });
      document.addEventListener('click', function onDoc(e) {
        if (!box.contains(e.target) && e.target !== addBtn) { box.remove(); addBtn.style.display = ''; document.removeEventListener('click', onDoc); }
      });
    });
  });
})();
</script>

<script>
/* Header status lights (owner/Me) — click a light to toggle its state, × to remove, + Status
   to add an available one. All persisted to /me/lights; built client-side from the registry. */
window.LG_LIGHTS = <?= json_encode(Block::HEADER_LIGHTS, JSON_UNESCAPED_SLASHES) ?>;
(function () {
  var REG = window.LG_LIGHTS || {};
  var row = document.querySelector('.lg-lights[data-lights-edit]');
  if (!row) return;
  var addBtn = document.getElementById('lg-light-add');

  function put(key, state) {
    return fetch('/profile-api/v0/me/lights', { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ key: key, state: state || '' }) })
      .then(function (r) { return r.ok; });
  }
  function states(key) { return Object.keys(((REG[key] || {}).states) || {}); }
  function present() { return Array.prototype.map.call(row.querySelectorAll('.lg-light'), function (p) { return p.getAttribute('data-key'); }); }
  function applyPill(pill, key, state) {
    var st = REG[key].states[state];
    pill.className = 'lg-light lg-light--' + st.tone;
    pill.setAttribute('data-state', state);
    pill.querySelector('.lg-light__label').textContent = st.label;
  }
  function makePill(key, state) {
    var st = REG[key].states[state];
    var pill = document.createElement('span');
    pill.className = 'lg-light lg-light--' + st.tone;
    pill.setAttribute('data-key', key); pill.setAttribute('data-state', state);
    pill.setAttribute('role', 'button'); pill.setAttribute('tabindex', '0'); pill.title = 'Click to toggle';
    pill.innerHTML = '<span class="lg-light__dot"></span><span class="lg-light__label">' + st.label + '</span>';
    var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'lg-light__rm';
    rm.setAttribute('aria-label', 'Remove status'); rm.textContent = '×';
    pill.appendChild(rm);
    return pill;
  }
  function refreshAdd() {
    if (!addBtn) return;
    var avail = Object.keys(REG).filter(function (k) { return present().indexOf(k) === -1; });
    addBtn.style.display = avail.length ? '' : 'none';
  }

  row.addEventListener('click', function (e) {
    var rm = e.target.closest('.lg-light__rm');
    if (rm) { var p = rm.closest('.lg-light'); var k = p.getAttribute('data-key'); p.remove(); put(k, ''); refreshAdd(); return; }
    var pill = e.target.closest('.lg-light');
    if (pill) {
      var key = pill.getAttribute('data-key'), ss = states(key), cur = pill.getAttribute('data-state');
      var next = ss[(ss.indexOf(cur) + 1) % ss.length];
      applyPill(pill, key, next); put(key, next);
    }
  });
  row.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('lg-light')) { e.preventDefault(); e.target.click(); }
  });

  function closeMenu() { var m = document.getElementById('lg-light-menu'); if (m) m.remove(); document.removeEventListener('click', onDoc); }
  function onDoc(e) { if (!e.target.closest('#lg-light-menu') && e.target !== addBtn) closeMenu(); }
  function openMenu() {
    closeMenu();
    var avail = Object.keys(REG).filter(function (k) { return present().indexOf(k) === -1; });
    if (!avail.length) return;
    var menu = document.createElement('div'); menu.className = 'lg-light-menu'; menu.id = 'lg-light-menu';
    avail.forEach(function (k) {
      states(k).forEach(function (s) {                       // every state pickable → negatives are first-class
        var st = REG[k].states[s];
        var b = document.createElement('button'); b.type = 'button';
        b.innerHTML = '<span class="lg-light__dot lg-light__dot--' + st.tone + '"></span>' + st.label;
        b.addEventListener('click', function () { row.insertBefore(makePill(k, s), addBtn); put(k, s); closeMenu(); refreshAdd(); });
        menu.appendChild(b);
      });
    });
    row.appendChild(menu); menu.style.left = addBtn.offsetLeft + 'px';
    document.addEventListener('click', onDoc);
  }
  if (addBtn) addBtn.addEventListener('click', function (e) { e.stopPropagation(); document.getElementById('lg-light-menu') ? closeMenu() : openMenu(); });
})();
</script>
<?php endif; /* close owner-only region: the lightbox below must render for ALL viewers */ ?>

<script>
/* Gallery lightbox (all viewers) — click any photo to view it full-size, with
   prev/next across the gallery, caption, and ESC / backdrop / × to close. The
   owner's remove (×) and add (＋) controls never trigger it. Loads the raw
   data-url (capped at w=1600 via the media resizer), not the grid thumbnail. */
(function () {
  var photos = [];
  // Scope the lightbox set to ONE gallery block (up to 3 per page) so prev/next stay
  // within the gallery the photo was clicked in; falls back to page-wide.
  var scopeEl = null;
  function collect() {
    var root = scopeEl || document;
    photos = Array.prototype.slice.call(root.querySelectorAll('.lg-gphoto[data-url]'));
  }

  function big(url) { return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'w=1600'; }

  var box = null, imgEl = null, capEl = null, prevBtn = null, nextBtn = null, idx = 0;

  function build() {
    box = document.createElement('div');
    box.className = 'lg-lightbox';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.innerHTML =
      '<button type="button" class="lg-lightbox__close" aria-label="Close">×</button>' +
      '<button type="button" class="lg-lightbox__nav lg-lightbox__prev" aria-label="Previous photo">‹</button>' +
      '<figure class="lg-lightbox__fig"><img class="lg-lightbox__img" alt=""><figcaption class="lg-lightbox__cap"></figcaption></figure>' +
      '<button type="button" class="lg-lightbox__nav lg-lightbox__next" aria-label="Next photo">›</button>';
    document.body.appendChild(box);
    imgEl   = box.querySelector('.lg-lightbox__img');
    capEl   = box.querySelector('.lg-lightbox__cap');
    prevBtn = box.querySelector('.lg-lightbox__prev');
    nextBtn = box.querySelector('.lg-lightbox__next');
    box.addEventListener('click', function (e) { if (e.target === box || e.target.closest('.lg-lightbox__close')) close(); });
    prevBtn.addEventListener('click', function (e) { e.stopPropagation(); step(-1); });
    nextBtn.addEventListener('click', function (e) { e.stopPropagation(); step(1); });
  }

  function show(i) {
    collect();                                   // re-read (owner may have added/removed)
    if (!photos.length) { close(); return; }
    idx = (i + photos.length) % photos.length;
    var el  = photos[idx];
    var url = el.getAttribute('data-url') || '';
    var cap = el.querySelector('figcaption');
    var capText = cap ? cap.textContent : '';
    imgEl.src = big(url);
    imgEl.alt = capText;
    capEl.textContent = capText;
    var multi = photos.length > 1;
    prevBtn.hidden = !multi; nextBtn.hidden = !multi;
  }
  function step(d) { show(idx + d); }

  function open(i) {
    if (!box) build();
    show(i);
    requestAnimationFrame(function () { if (box) box.classList.add('is-open'); });
    document.addEventListener('keydown', onKey);
  }
  function close() {
    if (!box) return;
    box.classList.remove('is-open');
    document.removeEventListener('keydown', onKey);
    var dying = box; box = null;
    setTimeout(function () { dying.remove(); }, 160);
  }
  function onKey(e) {
    if (e.key === 'Escape') close();
    else if (e.key === 'ArrowLeft') step(-1);
    else if (e.key === 'ArrowRight') step(1);
  }

  // Capture-phase delegation on the document: immune to where the gallery sits in
  // the DOM, to script/DOM load-order, and to any intermediate handler that stops
  // propagation. Works in grid + carousel; owner ×/＋ and lightbox-internal clicks
  // are excluded. (Was a per-container bubble binding that didn't fire — defect fix.)
  document.addEventListener('click', function (e) {
    if (e.target.closest('.lg-lightbox')) return;                       // clicks inside the overlay
    if (e.target.closest('.lg-gphoto__rm') || e.target.closest('.lg-gphoto__add')) return;
    var fig = e.target.closest('.lg-gphoto[data-url]');
    if (!fig) return;
    scopeEl = fig.closest('.lg-block--gallery') || null;   // this gallery's photos only
    collect();
    var i = photos.indexOf(fig);
    if (i >= 0) { e.preventDefault(); open(i); }
  }, true);
})();
</script>
<?php if ($isOwner): /* reopen owner-only region for the gallery editor below */ ?>

<script>
/* Gallery editor (owner/Me) — up to 3 independent galleries. Each .lg-block--gallery
   carries data-g (1|2|3) → the ?g=N selector on /me/gallery. Per-gallery multi-upload
   (POST) + remove (PUT list) + display-mode toggle. Scoped per block so photo ops
   never leak across galleries. */
(function () {
  var galleries = document.querySelectorAll('.lg-block--gallery');
  if (!galleries.length) return;
  var GBASE = '/profile-api/v0/me/gallery';

  galleries.forEach(function (block) {
    var wrap = block.querySelector('.lg-gallery');
    if (!wrap) return;
    var slot = block.getAttribute('data-g') || '1';
    var ep   = GBASE + '?g=' + slot;
    var addBtn = block.querySelector('.lg-gphoto__add');

    function currentImages() {
      return Array.prototype.map.call(wrap.querySelectorAll('.lg-gphoto'), function (el) {
        var cap = el.querySelector('figcaption');
        return { url: el.getAttribute('data-url'), caption: cap ? cap.textContent : '' };
      });
    }
    function putList(images) {
      return fetch(ep, { method: 'PUT', credentials: 'include',
        headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ images: images }) })
        .then(function (r) { return r.ok; });
    }

    // Remove a photo (this gallery only).
    wrap.addEventListener('click', function (e) {
      var rm = e.target.closest('.lg-gphoto__rm'); if (!rm) return;
      rm.closest('.lg-gphoto').remove();
      putList(currentImages()).then(function (ok) { if (!ok) { alert('Remove failed'); location.reload(); } });
    });

    // Multi-upload into this gallery.
    if (addBtn) {
      var input = document.createElement('input');
      input.type = 'file'; input.accept = 'image/jpeg,image/png,image/webp'; input.multiple = true;
      input.style.display = 'none'; document.body.appendChild(input);
      addBtn.addEventListener('click', function () { input.click(); });
      input.addEventListener('change', function () {
        var files = Array.prototype.slice.call(input.files || []); input.value = '';
        if (!files.length) return;
        addBtn.textContent = 'Uploading…';
        var i = 0;
        (function next() {
          if (i >= files.length) { location.reload(); return; }
          var f = files[i++];
          if (f.size > 5 * 1024 * 1024) { alert(f.name + ' is over 5 MB — skipped'); next(); return; }
          var fd = new FormData(); fd.append('image', f);
          fetch(ep, { method: 'POST', credentials: 'include', body: fd })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) { if (!res.ok) alert('Upload failed (' + f.name + '): ' + (res.j && res.j.error || '?')); next(); })
            .catch(function () { alert('Network error on ' + f.name); next(); });
        })();
      });
    }

    // Grid/carousel display-mode toggle (this gallery only).
    var ctrl = block.querySelector('.lg-gmode');
    if (ctrl) {
      ctrl.addEventListener('click', function (e) {
        var btn = e.target.closest('.lg-gmode__btn'); if (!btn) return;
        if (btn.getAttribute('aria-pressed') === 'true') return;
        var mode = btn.getAttribute('data-mode');
        btn.disabled = true;
        fetch(ep, { method: 'PUT', credentials: 'include',
          headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ display_mode: mode }) })
          .then(function (r) { if (r.ok) location.reload(); else { btn.disabled = false; alert('Could not change mode'); } })
          .catch(function () { btn.disabled = false; alert('Network error'); });
      });
    }

    // Delete this WHOLE gallery (confirm) → DELETE ?g=N (server GCs files + drops the
    // layout key), then reload so the counter + remaining galleries re-render.
    var del = block.querySelector('.lg-gdel');
    if (del) {
      del.addEventListener('click', function () {
        if (!confirm('Delete this entire gallery? Its photos will be permanently removed.')) return;
        del.disabled = true;
        fetch(ep, { method: 'DELETE', credentials: 'include' })
          .then(function (r) { if (r.ok) location.reload(); else { del.disabled = false; alert('Delete failed'); } })
          .catch(function () { del.disabled = false; alert('Network error'); });
      });
    }
  });
})();

/* "Add gallery" countdown (owner, pinned in the Sections rail) — deploy the next
   unused gallery block by appending its key to the layout (/me/layout), then reload.
   Disabled (0 left) at 3 galleries. */
(function () {
  var btn = document.getElementById('lg-add-gallery');
  if (!btn) return;
  var KEYS = ['gallery', 'gallery-2', 'gallery-3'];
  btn.addEventListener('click', function () {
    if (btn.disabled) return;
    var profile = document.querySelector('.lg-profile');
    if (!profile) return;
    var present = Array.prototype.map.call(
      profile.querySelectorAll('.lg-block:not(.lg-block--header)'),
      function (s) { return s.getAttribute('data-block'); }
    ).filter(Boolean);
    var next = KEYS.filter(function (k) { return present.indexOf(k) === -1; })[0];
    if (!next) return;                     // already at 3
    btn.disabled = true;
    var order = present.slice(); order.push(next);
    fetch('/profile-api/v0/me/layout', { method: 'PUT', credentials: 'include',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ order: order }) })
      .then(function (r) { if (r.ok) location.reload(); else { btn.disabled = false; alert('Could not add gallery'); } })
      .catch(function () { btn.disabled = false; alert('Network error'); });
  });
})();
/* Business entry pill (owner) — create the member's LoothPro business page, then
   open it. Interim create: prompt for a name, POST /me/practices, redirect to the
   new /p/ page. The richer create UX + Pro-gate land in WS1/WS2. */
(function () {
  var bizBtn = document.getElementById('lg-biz-add');
  if (!bizBtn) return;
  bizBtn.addEventListener('click', function () {
    var name = (prompt('Name your business page (e.g. your shop name):') || '').trim();
    if (!name) return;
    bizBtn.disabled = true;
    fetch('/profile-api/v0/me/practices', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: name })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok && res.j && (res.j.public_url || res.j.slug)) {
          location.href = res.j.public_url || ('/p/' + res.j.slug);
        } else {
          bizBtn.disabled = false;
          alert('Could not create business: ' + (res.j && res.j.error || '?'));
        }
      })
      .catch(function () { bizBtn.disabled = false; alert('Network error.'); });
  });
})();
/* Resume block (owner) — PDF upload + delete. Server re-validates mime; this
   just guards the obvious cases (size + accept=application/pdf). */
(function () {
  var setBtn = document.getElementById('lg-resume-set');
  var rmBtn  = document.getElementById('lg-resume-rm');
  if (!setBtn) return;

  var input = document.createElement('input');
  input.type = 'file'; input.accept = 'application/pdf,.pdf';
  input.style.display = 'none'; document.body.appendChild(input);

  setBtn.addEventListener('click', function () { input.click(); });
  input.addEventListener('change', function () {
    var f = (input.files || [])[0]; input.value = '';
    if (!f) return;
    if (f.size > 10 * 1024 * 1024) { alert('Over 10 MB — pick a smaller PDF.'); return; }
    var prev = setBtn.textContent;
    setBtn.textContent = 'Uploading…'; setBtn.disabled = true;
    var fd = new FormData(); fd.append('resume', f);
    fetch('/profile-api/v0/me/resume', { method: 'POST', credentials: 'include', body: fd })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok) location.reload();
        else { setBtn.textContent = prev; setBtn.disabled = false; alert('Upload failed: ' + (res.j && res.j.error || '?')); }
      })
      .catch(function () { setBtn.textContent = prev; setBtn.disabled = false; alert('Network error.'); });
  });

  rmBtn && rmBtn.addEventListener('click', function () {
    if (!confirm('Remove resume?')) return;
    rmBtn.disabled = true;
    fetch('/profile-api/v0/me/resume', { method: 'DELETE', credentials: 'include' })
      .then(function (r) { if (r.ok) location.reload(); else { rmBtn.disabled = false; alert('Remove failed.'); } })
      .catch(function () { rmBtn.disabled = false; alert('Network error.'); });
  });
})();
/* Header links rail — owner pencil opens the Edit-links modal. Links live in the
   header now (no standalone socials block); the editor markup is inside
   #lg-links-modal and is wired by the links-editor IIFE above. */
(function () {
  var btn   = document.querySelector('.lg-hlinks__edit[data-hlinks-edit]');
  var modal = document.getElementById('lg-links-modal');
  if (!btn || !modal) return;
  var closeBtn = document.getElementById('lg-links-modal-close');
  function open()  { modal.hidden = false; requestAnimationFrame(function () { modal.classList.add('is-open'); }); }
  function close() { modal.classList.remove('is-open'); setTimeout(function () { modal.hidden = true; }, 200); }
  btn.addEventListener('click', open);
  closeBtn && closeBtn.addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
})();
/* Banner image (owner) — POST multipart on set / replace, DELETE on remove.
   Max 8 MB. Same jpeg/png/webp validation as avatar (server re-checks too). */
(function () {
  var setBtn = document.getElementById('lg-banner-set');
  var rmBtn  = document.getElementById('lg-banner-rm');
  if (!setBtn) return;

  var input = document.createElement('input');
  input.type = 'file'; input.accept = 'image/jpeg,image/png,image/webp';
  input.style.display = 'none'; document.body.appendChild(input);

  setBtn.addEventListener('click', function () { input.click(); });
  input.addEventListener('change', function () {
    var f = (input.files || [])[0]; input.value = '';
    if (!f) return;
    if (f.size > 8 * 1024 * 1024) { alert('Over 8 MB — pick a smaller image.'); return; }
    var prevLabel = setBtn.querySelector('span'); var prev = prevLabel ? prevLabel.textContent : '';
    if (prevLabel) prevLabel.textContent = 'Uploading…';
    setBtn.disabled = true;
    var fd = new FormData(); fd.append('banner', f);
    fetch('/profile-api/v0/me/banner', { method: 'POST', credentials: 'include', body: fd })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.ok) location.reload();
        else {
          if (prevLabel) prevLabel.textContent = prev;
          setBtn.disabled = false;
          alert('Banner upload failed: ' + (res.j && res.j.error || '?'));
        }
      })
      .catch(function () {
        if (prevLabel) prevLabel.textContent = prev;
        setBtn.disabled = false;
        alert('Network error.');
      });
  });

  rmBtn && rmBtn.addEventListener('click', function () {
    if (!confirm('Remove banner?')) return;
    rmBtn.disabled = true;
    fetch('/profile-api/v0/me/banner', { method: 'DELETE', credentials: 'include' })
      .then(function (r) { if (r.ok) location.reload(); else { rmBtn.disabled = false; alert('Remove failed.'); } })
      .catch(function () { rmBtn.disabled = false; alert('Network error.'); });
  });
})();
</script>
<?php require __DIR__ . '/_richedit.php'; /* About rich-text editor (owner-only; lazy Quill) */ ?>
<?php endif; ?>
</body>
</html>
