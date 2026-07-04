<?php
/**
 * Plugin Name: LGPO Inline Set-Password
 * Description: Inline set/change-password page at /patreon-password/. Uses
 *   wp_set_password() for the already-logged-in viewer (nonce-gated, applied to
 *   get_current_user_id() only). Two-field confirm + show/hide toggles. Shows
 *   their Patreon email. ?change=1 switches copy to "change" mode. Skippable.
 */
if (!defined('ABSPATH')) exit;

add_action('init', function () {
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
    if (rtrim($path, '/') !== '/patreon-password') return;
    if (headers_sent()) return;
    if (!is_user_logged_in()) { wp_safe_redirect(home_url('/')); exit; }

    $uid  = get_current_user_id();
    $u    = get_userdata($uid);
    $slug = (string) get_user_meta($uid, '_looth_slug', true);
    $cont = $slug !== '' ? '/u/' . rawurlencode($slug) : '/profile/edit';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $nonce_ok = (bool) wp_verify_nonce($_POST['_lgpo_pw'] ?? '', 'lgpo_set_password');
        $pw  = (string) ($_POST['lgpo_password'] ?? '');
        $pw2 = (string) ($_POST['lgpo_password2'] ?? '');
        error_log(sprintf('[lgpo-pw] POST uid=%d nonce=%s len=%d match=%s', $uid, $nonce_ok ? 'ok' : 'FAIL', strlen($pw), $pw === $pw2 ? 'y' : 'n'));
        $err = '';
        if (!$nonce_ok)          $err = 'expired';
        elseif (strlen($pw) < 8) $err = 'short';
        elseif ($pw !== $pw2)    $err = 'mismatch';
        if ($err === '') {
            wp_set_password($pw, $uid);
            wp_set_auth_cookie($uid, true);
            if ($u) do_action('wp_login', $u->user_login, $u);
            error_log('[lgpo-pw] set OK uid=' . $uid);
            wp_safe_redirect(home_url('/')); exit;   // successful set/change → front page (Ian 6/16)
        }
        $kp = isset($_GET['change']) ? ['pwerr' => $err, 'change' => 1] : ['pwerr' => $err];
        wp_safe_redirect(add_query_arg($kp, home_url('/patreon-password/'))); exit;
    }

    $change = isset($_GET['change']);
    $first  = trim((string) ($u->first_name ?? '')) ?: trim(explode(' ', (string) $u->display_name)[0] ?? '') ?: 'there';
    $pemail = (string) (get_user_meta($uid, 'lgpo_patreon_email', true) ?: ($u->user_email ?? ''));
    $uname  = (string) (($u->user_email ?? '') !== '' ? $u->user_email : $u->user_login); // what managers key the entry on
    $nonce  = wp_create_nonce('lgpo_set_password');
    $e      = $_GET['pwerr'] ?? '';
    $emsg   = $e === 'short' ? 'Password must be at least 8 characters.'
            : ($e === 'mismatch' ? "Those passwords don't match." : ($e === 'expired' ? 'That form expired &mdash; please try again.' : ''));

    // Canonical shared site chrome — same require + $ctx-up-top pattern as
    // membership-pages/web/manage-subscription.php. lg-shell owns these partials;
    // we only populate $ctx. We're inside WP (mu-plugin), so $ctx comes from the
    // platform-wide in-process viewer builder lg_membership_chrome_viewer()
    // (lg-membership-chrome.php) — the same identity BB/membership chrome feed the
    // shared header — instead of the curl-to-/whoami helper the STANDALONE
    // membership pages use. Reuse, not reinvent. Fallback keeps the page alive if
    // that mu-plugin is ever absent (viewer is always logged in — guarded above).
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
            'profile_url'   => $cont,
        ];
    $css_ver = @filemtime('/srv/lg-shared/site-header.css') ?: '1';

    status_header(200); nocache_headers(); header('Content-Type: text/html; charset=utf-8');
    $eye = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>';
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>The Looth Group</title>
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?php echo $css_ver; ?>">
<style>
  body{font-family:system-ui,sans-serif;background:#f6f6f2;margin:0;color:#1A1E12}
  .wrap{max-width:560px;margin:56px auto;padding:0 1.25em}
  .card{padding:1.4em 1.6em;background:#d4e0b8;border:1px solid #87986A;border-radius:8px}
  h2{margin:.2em 0 .6em}
  .pemail{margin:.2em 0 .9em;font-size:.95em;color:#3c4a28}
  .pwrow{position:relative;margin:.6em 0}
  .pwrow input{width:100%;box-sizing:border-box;padding:.7em 2.6em .7em .8em;border:1px solid #87986A;border-radius:5px;font-size:1em;background:#fff}
  .peek{position:absolute;right:.5em;top:50%;transform:translateY(-50%);background:none;border:0;cursor:pointer;color:#5a6b3f;padding:4px;display:flex}
  .peek.off{opacity:.45}
  button.go{margin-top:.7em;padding:.7em 1.3em;background:#1A1E12;color:#fff;border:0;border-radius:5px;cursor:pointer;font-size:1em}
  button.go:disabled{opacity:.5;cursor:not-allowed}
  .err{color:#b3361f;margin:.4em 0 0;min-height:1.1em;font-size:.92em}
  .skip{display:inline-block;margin-top:1.4em;color:#1A1E12}
  label{font-size:.92em;font-weight:600;display:block;margin-top:.5em}
  .pwuser{position:absolute;left:-9999px;width:1px;height:1px;opacity:0;pointer-events:none}
</style></head>
<body>
<?php if (function_exists('lg_shared_render_site_header')) lg_shared_render_site_header($ctx); ?>
<div class="wrap">
  <h2><?php echo $change ? 'Change your password' : 'Welcome, ' . esc_html($first) . '!'; ?></h2>
  <div class="card">
    <?php if ($change): ?>
      <p>Update the password for your Looth Group account.</p>
      <p class="pemail">Heads up: changing your password signs you out on your other devices &mdash; sign in there with the new password and update any saved passwords.</p>
    <?php else: ?>
      <p>You're connected and <strong>logged in.</strong> Set a password so you can sign in directly next time &mdash; or skip it and just reconnect with Patreon whenever you like.</p>
    <?php endif; ?>
    <?php if ($pemail !== ''): ?><p class="pemail">Patreon email: <strong><?php echo esc_html($pemail); ?></strong></p><?php endif; ?>
    <form method="post" action="/patreon-password/<?php echo $change ? '?change=1' : ''; ?>" id="pwform">
      <input type="hidden" name="_lgpo_pw" value="<?php echo esc_attr($nonce); ?>">
      <?php // Visually-hidden username: lets password managers UPDATE the saved
            // entry for this account instead of keeping the old password
            // (type=hidden is ignored by managers; off-screen text is the pattern). ?>
      <input type="text" name="lgpo_login" value="<?php echo esc_attr($uname); ?>" autocomplete="username" readonly tabindex="-1" aria-hidden="true" class="pwuser">
      <label for="p1">New password</label>
      <div class="pwrow">
        <input id="p1" type="password" name="lgpo_password" minlength="8" autocomplete="new-password" required placeholder="At least 8 characters">
        <button type="button" class="peek" data-for="p1" aria-label="Show password"><?php echo $eye; ?></button>
      </div>
      <label for="p2">Confirm password</label>
      <div class="pwrow">
        <input id="p2" type="password" name="lgpo_password2" minlength="8" autocomplete="new-password" required placeholder="Re-enter password">
        <button type="button" class="peek" data-for="p2" aria-label="Show password"><?php echo $eye; ?></button>
      </div>
      <p class="err" id="err"><?php echo $emsg; ?></p>
      <button type="submit" class="go" id="go" disabled>Set password</button>
    </form>
  </div>
  <a class="skip" href="<?php echo esc_url($cont); ?>">Skip &mdash; continue to my profile &rarr;</a>
</div>
<?php if (function_exists('lg_shared_render_site_footer')) lg_shared_render_site_footer(); ?>
<script>
(function(){
  var p1=document.getElementById('p1'),p2=document.getElementById('p2'),go=document.getElementById('go'),err=document.getElementById('err');
  document.querySelectorAll('.peek').forEach(function(b){
    b.classList.add('off');
    b.addEventListener('click',function(){var f=document.getElementById(b.dataset.for);var s=f.type==='password';f.type=s?'text':'password';b.classList.toggle('off',!s);});
  });
  function check(){
    var a=p1.value,b=p2.value,ok=true,m='';
    if(a.length>0&&a.length<8){m='At least 8 characters.';}
    else if(b.length>0&&a!==b){m="Passwords don't match.";}
    if(a.length<8||a!==b)ok=false;
    err.textContent=m; go.disabled=!ok;
  }
  p1.addEventListener('input',check); p2.addEventListener('input',check); check();
})();
</script>
</body></html><?php
    exit;
});
