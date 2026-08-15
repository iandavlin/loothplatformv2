<?php
/**
 * Plugin Name: LG Profile Setup — the arrive-alive step
 * Description: One skippable screen at /profile-setup/ asking a brand-new member
 *   for the three things a directory row actually shows: a photo, a city, and one
 *   line about what they do. Backlog 19 (Ian 8/12, ruled 8/15 — Option A).
 * Version: 0.1.0
 *
 * ── THE FLAG ─────────────────────────────────────────────────────────────────
 * platform/config/profile-setup.php, read through __DIR__ (NOT an env var — WP
 * cron and FPM pools do not share an environment, and a tracked file is visible
 * in every context). OFF is a true no-op: the route is never registered, so
 * /profile-setup/ 404s exactly as it did before this file existed, and neither
 * rail's hand-off is touched.
 *
 * ── DUAL RAIL (Ian's standing ruling, 8/15) ──────────────────────────────────
 * This screen is keyed to being a logged-in member, NOT to how they paid. Both
 * rails hand off to it from their own end-of-join page — Patreon from
 * lgpo-set-password.php, Stripe from membership-pages/web/welcome.php — so a
 * Patreon joiner and a Stripe joiner get the identical screen, and nothing here
 * reaches into the Stripe leg (which stripe-membership's gate 34d forbids from
 * mailing, firing hooks, or stamping member data).
 *
 * ── IAN'S FOUR SHARPENINGS, AND WHERE EACH ONE LIVES ─────────────────────────
 *  1. "Both patreon onboarding like after Password gen and for the stripe"
 *     → the two hand-offs above; this page is rail-agnostic.
 *  2. "clear that this is setting up the profile and is optional"
 *     → the heading says what it is, the sub-line says it is optional, and Skip
 *       is a real <button>-weight control sitting beside Save, not a grey link
 *       hiding underneath it.
 *  3. "No nudging on that matter"
 *     → there is no banner, no dismissible card, no percentage chase, and no
 *       reminder anywhere else in the product. The gate asserts that ABSENCE,
 *       because an absence is exactly what creeps back in.
 *  4. skippers get "instructions for how to find their profile later"
 *     → ?skipped=1 renders the where-to-find-it panel instead of silently
 *       dumping them on the front page.
 *
 * ── WHERE THE ANSWERS GO ─────────────────────────────────────────────────────
 * Straight to the profile-app endpoints that already own these fields, from the
 * browser, same-origin, with the member's own looth_id cookie:
 *   photo       → POST   /profile-api/v0/me-avatar.php   (multipart, field "avatar")
 *   city        → PUT    /profile-api/v0/me-location.php ({address}, geocoded server-side)
 *   what you do → PATCH  /profile-api/v0/me-header.php   ({at_a_glance})
 * Each writes exactly the column Completeness::forUser() reads, so filling this
 * in moves the existing meter with no second definition of "done".
 *
 * ⚠️ at_a_glance is mirrored to the WP user `description` by me-header.php — it
 * is the public author byline. Hence the wording asks for a public one-liner.
 */

if (!defined('ABSPATH')) exit;

/**
 * The flag. getenv() AND $_SERVER: a lane-preview fastcgi_param lands only in
 * $_SERVER, so a getenv()-only read would serve the OFF path on the very preview
 * URL built for Ian to click.
 */
function lg_profile_setup_cfg(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $cfg  = ['enabled' => false, 'path' => '/profile-setup/'];
    $file = __DIR__ . '/../config/profile-setup.php';
    if (is_readable($file)) {
        $loaded = require $file;
        if (is_array($loaded)) $cfg = $loaded + $cfg;
    }
    foreach ([getenv('LG_PROFILE_SETUP'), $_SERVER['LG_PROFILE_SETUP'] ?? false] as $o) {
        if ($o !== false && $o !== '') $cfg['enabled'] = ($o === '1' || $o === 'true');
    }
    return $cfg;
}

/** Is the step switched on FOR EVERYONE? */
function lg_profile_setup_enabled(): bool {
    $c = lg_profile_setup_cfg();
    return !empty($c['enabled']);
}

/** The live-testing allowlist: WP user IDs, normalised to ints. */
function lg_profile_setup_testers(): array {
    $c = lg_profile_setup_cfg();
    $t = $c['testers'] ?? array();
    if (!is_array($t)) return array();
    return array_values(array_filter(array_map('intval', $t), function ($i) { return $i > 0; }));
}

/**
 * Does the step exist AT ALL on this box? The route is registered only if this
 * is true, which is what keeps the shipped state (off, no testers) a genuine
 * absence rather than a route that renders nothing.
 */
function lg_profile_setup_live(): bool {
    return lg_profile_setup_enabled() || lg_profile_setup_testers() !== array();
}

/**
 * Does THIS member get the step?
 *
 * Identity is the WordPress login and nothing else — no token, no cookie of our
 * own, no query parameter. A non-tester must come out of here false and then
 * receive the byte-identical OFF experience, which is the half the gate proves.
 */
function lg_profile_setup_visible_to(int $userId): bool {
    if (lg_profile_setup_enabled()) return true;
    if ($userId <= 0) return false;
    return in_array($userId, lg_profile_setup_testers(), true);
}

/** Where the step lives, for the two rails that hand off to it. */
function lg_profile_setup_path(): string {
    $c = lg_profile_setup_cfg();
    return (string) ($c['path'] ?? '/profile-setup/');
}

add_action('init', function () {
    // OFF AND NO TESTERS ⇒ the route is never registered. /profile-setup/ 404s
    // exactly as it did before this file existed. This early return is the whole
    // no-op, and it is why the shipped state is an absence and not an empty page.
    if (!lg_profile_setup_live()) return;

    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
    if (rtrim($path, '/') !== rtrim(lg_profile_setup_path(), '/')) return;
    if (headers_sent()) return;
    if (!is_user_logged_in()) { wp_safe_redirect(home_url('/')); exit; }

    $uid  = get_current_user_id();
    // A member outside the testers list gets EXACTLY what they get today: nothing
    // here handled, so WordPress carries on to its own 404. Returning (rather than
    // rendering an apology) is what makes "not a tester" byte-identical to "the
    // feature does not exist".
    if (!lg_profile_setup_visible_to($uid)) return;
    $u    = get_userdata($uid);
    $slug = (string) get_user_meta($uid, '_looth_slug', true);
    $mine = $slug !== '' ? '/u/' . rawurlencode($slug) : '/profile/edit';
    $first = trim((string) ($u->first_name ?? ''))
             ?: trim(explode(' ', (string) $u->display_name)[0] ?? '')
             ?: 'there';

    // Same shared chrome the other end-of-join pages use. lg-shell owns the
    // partials; we only populate $ctx. Fallback keeps the page alive if the
    // chrome mu-plugin is ever absent (viewer is always logged in — guarded above).
    require '/srv/lg-shared/site-header.php';
    require '/srv/lg-shared/site-footer.php';
    $ctx = function_exists('lg_membership_chrome_viewer')
        ? lg_membership_chrome_viewer()
        : [
            'authenticated' => true,
            'tier'          => 'public',
            'display_name'  => (string) $u->display_name,
            'avatar_url'    => (string) get_avatar_url($uid, ['size' => 96]),
            'capabilities'  => ['manage_options' => user_can($uid, 'manage_options')],
            'msg_unread'    => null,
            'notif_unread'  => null,
            'active_nav'    => '',
            'logout_url'    => wp_logout_url(home_url('/')),
            'profile_url'   => $mine,
        ];
    $css_ver = @filemtime('/srv/lg-shared/site-header.css') ?: '1';
    $skipped = isset($_GET['skipped']);

    status_header(200); nocache_headers(); header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Set up your profile — The Looth Group</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?php echo $css_ver; ?>">
<style>
  body{font-family:system-ui,sans-serif;background:#f6f6f2;margin:0;color:#1A1E12}
  .wrap{max-width:560px;margin:56px auto;padding:0 1.25em}
  h2{margin:.2em 0 .35em}
  .lede{margin:0 0 1.1em;color:#3c4a28;font-size:.98em;line-height:1.6}
  .card{padding:1.4em 1.6em;background:#d4e0b8;border:1px solid #87986A;border-radius:8px}
  label{font-size:.92em;font-weight:600;display:block;margin-top:.9em}
  .hint{font-size:.85em;color:#3c4a28;margin:.15em 0 .35em;line-height:1.5}
  input[type=text]{width:100%;box-sizing:border-box;padding:.7em .8em;border:1px solid #87986A;
    border-radius:5px;font-size:1em;background:#fff;color:#1A1E12;font-family:inherit}
  .photorow{display:flex;align-items:center;gap:12px;margin:.45em 0 .2em}
  .avi{width:56px;height:56px;flex:0 0 56px;border-radius:50%;background:#c9c5b6;overflow:hidden;
    display:flex;align-items:center;justify-content:center}
  .avi img{width:100%;height:100%;object-fit:cover;display:block}
  .avi svg{width:32px;height:32px}
  .filebtn{padding:.55em 1em;background:#fff;border:1px solid #87986A;border-radius:5px;
    font-size:.92em;font-weight:600;color:#3c4a28;cursor:pointer}
  input[type=file]{position:absolute;left:-9999px}
  /* Ian: skip is FIRST-CLASS. Same size, same row, same weight as Save — a
     different colour, not a lesser status. */
  .actions{display:flex;flex-wrap:wrap;gap:.6em;margin-top:1.3em}
  .btn{padding:.7em 1.3em;border-radius:5px;font-size:1em;font-family:inherit;font-weight:600;
    cursor:pointer;border:1px solid transparent;text-decoration:none;display:inline-block}
  .btn--go{background:#1A1E12;color:#fff}
  .btn--go:disabled{opacity:.5;cursor:not-allowed}
  .btn--skip{background:#fff;color:#1A1E12;border-color:#87986A}
  .msg{margin:.7em 0 0;min-height:1.1em;font-size:.92em}
  .msg.err{color:#b3361f}
  .msg.ok{color:#3f5c22}
  .addr{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#eef3e2;
    padding:.05em .35em;border-radius:3px}
  .privacy{margin-top:1.1em;padding:.9em 1em;background:#eef3e2;border:1px solid #c3d2a4;
    border-radius:6px}
  .privacy__h{font-weight:700;font-size:.94em;margin-bottom:.2em}
  .privacy label{margin-top:.55em}
  select{width:100%;box-sizing:border-box;padding:.6em .7em;border:1px solid #87986A;
    border-radius:5px;font-size:1em;background:#fff;color:#1A1E12;font-family:inherit}
  .found{padding:1.2em 1.4em;background:#fff;border:1px solid #e3ddd0;border-radius:8px;margin-top:1em}
  .found h3{margin:0 0 .5em;font-size:1.02em}
  .found ol{margin:.4em 0 0;padding-left:1.2em}
  .found li{margin:.35em 0;line-height:1.55;font-size:.95em}
</style></head>
<body>
<?php if (function_exists('lg_shared_render_site_header')) lg_shared_render_site_header($ctx); ?>
<div class="wrap">
<?php if ($skipped): ?>
  <?php /* Sharpening 4: skipping is a real choice, so it ends somewhere useful
           rather than dumping them on the front page with no idea where the
           profile went. */ ?>
  <h2>No problem, <?php echo esc_html($first); ?>.</h2>
  <p class="lede">Your profile is already live &mdash; it is just empty for now. You can fill it in
     whenever you like, and nothing here will pester you about it.</p>
  <div class="found" id="lg-ps-found">
    <h3>Where to find it later</h3>
    <ol>
      <li>Tap your photo in the top corner of any page.</li>
      <li>Choose <strong>My Profile</strong>.</li>
      <li>Hit <strong>Edit profile</strong> &mdash; a photo, your town and one line about what you
          do is all it takes to show up properly in the member directory.</li>
    </ol>
    <div class="actions">
      <a class="btn btn--go" href="<?php echo esc_url($mine); ?>">Go to my profile</a>
      <a class="btn btn--skip" href="<?php echo esc_url(home_url('/')); ?>">Take me to the community</a>
    </div>
  </div>
<?php else: ?>
  <h2>Set up your profile, <?php echo esc_html($first); ?></h2>
  <p class="lede"><strong>This is optional</strong> &mdash; you can skip it and do it later. It takes
     about a minute, and it is what makes you findable to the rest of the community instead of
     showing up as a blank grey circle.</p>

  <div class="card">
    <form id="lg-ps-form" novalidate>
      <?php /* Ian 8/15 addition 3: "get their user name and gen their slug at this
               point too". The NAME is what we collect; the handle GENERATES from it.
               That is not a shortcut — handles are display-only and derived, by Ian's
               own numbered ruling of 7/25 (me-slug.php is GET-only, there is no member
               writer to call). me-name.php runs Provision::maybeSyncSlugFromName, which
               already dedupes against live slugs AND every other member's slug_history
               (a retired handle is never re-issued — that was a real link-hijack bug)
               with the @steve/@steve2/@steve3 suffix scheme riding inside the 30-char
               cap. We reuse that and never re-derive it here: a client-side preview
               would show "@steve" to somebody who is about to be given "@steve2". */ ?>
      <label for="ps-name">Your name</label>
      <div class="hint">How you appear to other members.<?php if ($slug !== ''): ?>
        Your profile address is <span class="addr">/u/<?php echo esc_html($slug); ?></span>
        &mdash; it follows your name, so changing this changes that too.<?php endif; ?></div>
      <input type="text" id="ps-name" maxlength="71" value="<?php echo esc_attr($u->display_name); ?>">

      <label for="ps-photo-btn">A photo of you</label>
      <div class="hint">A face, a workbench, a headstock &mdash; anything but the default.</div>
      <div class="photorow">
        <div class="avi" id="ps-avi">
          <svg viewBox="0 0 24 24" fill="#8f8a7c" aria-hidden="true"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-5 0-9 2.5-9 5.5V22h18v-2.5c0-3-4-5.5-9-5.5Z"/></svg>
        </div>
        <label class="filebtn" id="ps-photo-btn" for="ps-photo">Choose a photo</label>
        <input type="file" id="ps-photo" accept="image/*">
      </div>

      <label for="ps-city">Where are you?</label>
      <div class="hint">A town or city is plenty &mdash; this is what puts you on the member map.</div>
      <input type="text" id="ps-city" autocomplete="address-level2" placeholder="e.g. Milwaukee, Wisconsin">

      <?php /* Ian 8/15 addition 1: "throw in some privacy stuff to get them thinking
               about that", and addition 2 ties the location question to it. Two plain
               questions, not a settings wall — the point is a moment of awareness at
               the one time they are already thinking about what they are publishing.
               Both dials ALREADY EXIST and are only wired here: the profile one is
               me-header.php's `visibility`, which is the master switch (Ian 6/12, "ONE
               DIAL"), and the location one is me-location.php's `location_visibility`.
               Both are pre-filled from the member's CURRENT values and only sent when
               actually changed, so Save cannot silently flip a setting the member never
               touched — and Skip sends nothing at all, which is what keeps Ian's "safe
               defaults on skip" true by construction rather than by promise. The block
               hides itself if those values cannot be read: a privacy control showing a
               state it failed to load is worse than no control. */ ?>
      <div class="privacy" id="ps-privacy" hidden>
        <div class="privacy__h">While you are here &mdash; who sees this?</div>
        <label for="ps-vis">Your profile</label>
        <select id="ps-vis">
          <option value="public">Anyone on the web</option>
          <option value="member">Members only</option>
          <option value="private">Nobody but me</option>
        </select>
        <div class="locvis" id="ps-locvis-row" hidden>
          <label for="ps-locvis">Where you are</label>
          <select id="ps-locvis">
            <option value="public">Anyone on the web</option>
            <option value="members">Members only</option>
            <option value="private">Nobody but me</option>
          </select>
          <div class="hint">Your town shows on the member map at this setting. You can
            set the pin more precisely later in the full profile editor.</div>
        </div>
      </div>

      <label for="ps-what">What do you do? One line.</label>
      <div class="hint">Shown publicly next to your name, so keep it short and public-facing.</div>
      <input type="text" id="ps-what" maxlength="140" placeholder="e.g. Acoustic repairs and neck resets">

      <p class="msg" id="ps-msg" role="status" aria-live="polite"></p>

      <div class="actions">
        <button type="submit" class="btn btn--go" id="ps-save">Save and finish</button>
        <a class="btn btn--skip" id="ps-skip" href="<?php echo esc_url(lg_profile_setup_path()); ?>?skipped=1">Skip for now</a>
      </div>
    </form>
  </div>
<?php endif; ?>
</div>
<?php if (function_exists('lg_shared_render_site_footer')) lg_shared_render_site_footer(); ?>
<?php if (!$skipped): ?>
<script>
(function(){
  var API   = '/profile-api/v0/';
  var form  = document.getElementById('lg-ps-form');
  var msg   = document.getElementById('ps-msg');
  var save  = document.getElementById('ps-save');
  var photo = document.getElementById('ps-photo');
  var avi   = document.getElementById('ps-avi');
  var mine  = <?php echo wp_json_encode($mine); ?>;

  // Local preview only — the file is uploaded on submit, so a member who
  // changes their mind before saving has uploaded nothing.
  photo.addEventListener('change', function(){
    var f = photo.files && photo.files[0];
    if (!f) return;
    var url = URL.createObjectURL(f);
    avi.innerHTML = '';
    var img = document.createElement('img');
    img.src = url; img.alt = '';
    avi.appendChild(img);
  });

  function say(text, kind){ msg.textContent = text; msg.className = 'msg' + (kind ? ' ' + kind : ''); }

  // ── PRIVACY PRE-FILL ────────────────────────────────────────────────────────
  // Read the member's CURRENT settings and preselect them, so Save can only send
  // what they actually changed. The alternative — shipping a default in the
  // markup — would let Save quietly rewrite a setting the member never looked at,
  // which is the opposite of the awareness Ian asked for.
  var priv = document.getElementById('ps-privacy');
  var vis = document.getElementById('ps-vis'), locvis = document.getElementById('ps-locvis');
  var locvisRow = document.getElementById('ps-locvis-row');
  var visWas = null, locvisWas = null;

  fetch(API + 'me-header.php', {credentials:'same-origin'})
    .then(function(r){ return r.ok ? r.json() : null; })
    .then(function(j){
      if (!j) return;
      visWas = j.vis || null;   // Block::loadHeader returns 'vis', not 'visibility'
      if (visWas) { vis.value = visWas; priv.hidden = false; }
    }).catch(function(){ /* leave hidden: a control that failed to load its own state lies */ });

  // 404 here is the NORMAL new-member answer (no location block yet), not an error.
  fetch(API + 'me-location.php', {credentials:'same-origin'})
    .then(function(r){ return r.ok ? r.json() : null; })
    .then(function(j){
      if (!j) return;
      locvisWas = j.location_visibility || null;
      if (locvisWas) locvis.value = locvisWas;
    }).catch(function(){});

  // Ian: "Especially if we are doing a location." The location dial appears the
  // moment they type one, so the question arrives attached to the thing it governs.
  var cityEl = document.getElementById('ps-city');
  cityEl.addEventListener('input', function(){
    var has = cityEl.value.trim() !== '';
    locvisRow.hidden = !has;
    if (has) priv.hidden = false;
  });

  form.addEventListener('submit', function(e){
    e.preventDefault();
    var city = document.getElementById('ps-city').value.trim();
    var what = document.getElementById('ps-what').value.trim();
    var file = photo.files && photo.files[0];
    var nameEl = document.getElementById('ps-name');
    var name = nameEl.value.trim();
    var nameChanged = name !== '' && name !== nameEl.defaultValue;
    // Only send a dial the member actually moved (see the pre-fill note above).
    var visChanged = visWas !== null && vis.value !== visWas;
    var locvisChanged = locvisWas !== null && locvis.value !== locvisWas;

    if (!city && !what && !file && !nameChanged && !visChanged && !locvisChanged) {
      say('Add at least one thing, or use Skip for now.', 'err');
      return;
    }

    save.disabled = true;
    say('Saving…');

    var jobs = [];

    if (file) {
      var fd = new FormData();
      fd.append('avatar', file);
      jobs.push(fetch(API + 'me-avatar.php', {
        method: 'POST', body: fd, credentials: 'same-origin'
      }).then(function(r){ if (!r.ok) throw new Error('photo'); }));
    }
    if (city || locvisChanged) {
      var locBody = {};
      if (city) locBody.address = city;
      if (locvisChanged) locBody.location_visibility = locvis.value;
      jobs.push(fetch(API + 'me-location.php', {
        method: 'PUT', credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(locBody)
      }).then(function(r){ if (!r.ok) throw new Error('town'); }));
    }
    // The handle is generated server-side from the name; newSlug is what it became
    // AFTER dedup, so it is the only address worth showing them.
    var newSlug = null;
    if (nameChanged) {
      jobs.push(fetch(API + 'me-name.php', {
        method: 'PATCH', credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ display_name: name })
      }).then(function(r){ if (!r.ok) throw new Error('name'); return r.json(); })
        .then(function(j){ if (j && j.slug) newSlug = j.slug; }));
    }
    if (what) {
      jobs.push(fetch(API + 'me-header.php', {
        method: 'PATCH', credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ at_a_glance: what })
      }).then(function(r){ if (!r.ok) throw new Error('line'); }));
    }
    if (visChanged) {
      jobs.push(fetch(API + 'me-header.php', {
        method: 'PATCH', credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ visibility: vis.value })
      }).then(function(r){ if (!r.ok) throw new Error('privacy'); }));
    }

    Promise.all(jobs).then(function(){
      // Ian 8/15 addition 2: "ask them if they want to go to the full profile
      // interface. Especially if we are doing a location." So this ASKS rather than
      // redirecting — and the editor is the primary door when a location was set,
      // because that is where the map pin can actually be placed.
      var addr = newSlug ? ('/u/' + newSlug) : mine;
      var extra = city
        ? '<p class="lede">You are on the member map now. The full editor is where you can '
          + 'place your pin exactly and choose how closely it shows.</p>'
        : '';
      var slugLine = newSlug
        ? '<p class="lede">Your profile address is now <span class="addr">/u/'
          + newSlug.replace(/[<&]/g, '') + '</span>.</p>'
        : '';
      document.querySelector('.card').outerHTML =
        '<div class="found" id="lg-ps-done"><h3>Saved.</h3>' + slugLine + extra
        + '<p class="lede">Do you want to open the full profile interface and keep going, '
        + 'or head into the community?</p><div class="actions">'
        + '<a class="btn btn--go" href="' + addr + '">Open the full profile editor</a>'
        + '<a class="btn btn--skip" href="/">Take me to the community</a></div></div>';
    }).catch(function(err){
      save.disabled = false;
      // Name the field that failed rather than a generic apology: the member can
      // retry just that one, and anything that already saved has saved.
      var which = { photo:'your photo', town:'your town', line:'your one-liner',
                    name:'your name', privacy:'your privacy choice' }[err && err.message] || 'that';
      say('We could not save ' + which + '. You can try again, or skip and do it later.', 'err');
    });
  });
})();
</script>
<?php endif; ?>
</body></html><?php
    exit;
});
