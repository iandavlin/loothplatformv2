<?php
/**
 * /lggift/ — standalone port of [lg_redeem_gift] (redeem a gift code).
 *
 * VERBATIM body port of Shortcodes::redeemGift() (src/Wp/Shortcodes.php:4041).
 * Only the chrome + WP server helpers change:
 *   - wp_get_current_user()  → wordpress_logged_in_* cookie → wp_users lookup
 *   - get_user_by('email')   → wp_users SELECT by user_email
 *   - home_url()/rest_url()  → lg_ms_home()
 *   - active-sub + stapled-email lookups → same SQL vs the poller DB
 *   - esc_html / esc_attr / esc_js / wp_json_encode → lg_membership_h / lg_ms_esc_js
 *
 * Redemption POSTs to the Slim /billing/v1/redeem API; account auth via WP REST
 * /wp-json/lg-member-sync/v1/auth — both portable, JS copied as-is.
 * Styles: vendored lg-shortcodes.css. Admin-only pre-launch (router enforces;
 * this self-gate is defense-in-depth).
 */
declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/whoami.php';
require '/srv/lg-shared/site-header.php';
require '/srv/lg-shared/site-footer.php';
require __DIR__ . '/_admin-gate.php';

$h   = 'lg_membership_h';
$ctx = lg_membership_header_ctx('');
lg_membership_prelaunch_gate_or_exit($ctx);

/* ---- standalone WP-function shims (guarded — shared across ported pages) ---- */
if (!function_exists('lg_ms_home')) {
    function lg_ms_home(string $p = ''): string { return 'https://' . LG_MEMBERSHIP_HOST . $p; }
}
if (!function_exists('lg_ms_esc_js')) {
    function lg_ms_esc_js(string $s): string {
        return strtr($s, ['\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\n" => '\\n', "\r" => '\\r', '</' => '<\\/']);
    }
}

/* ---- resolve the logged-in user (email/name) via the WP DB ---- */
$isLoggedIn   = ($ctx['authenticated'] ?? false) === true;
$emailValue   = '';
$nameValue    = '';
$sessionEmail = '';   // the logged-in user's own email (kept as $emailValue may get re-stapled)
foreach ($_COOKIE as $ck => $cv) {
    if (strpos($ck, 'wordpress_logged_in_') === 0) {
        $parts = explode('|', urldecode((string) $cv), 4);
        if (!empty($parts[0])) {
            try {
                $st = lg_membership_db()->prepare("SELECT user_email, display_name, user_login FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "users WHERE user_login = ? LIMIT 1");
                $st->execute([$parts[0]]);
                if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                    $emailValue   = (string) $u['user_email'];
                    $sessionEmail = $emailValue;
                    $nameValue    = trim((string) ($u['display_name'] ?: $u['user_login']));
                }
            } catch (Throwable $e) {}
        }
        break;
    }
}
$isLoggedIn = $isLoggedIn && $emailValue !== '';

$atts = ['heading' => 'Redeem a Gift Code'];
$endpointUrl = lg_ms_home('/billing/v1/redeem');

/* Active-subscriber heads-up (poller DB). */
$hasActiveSub  = false;
$activeSubEnds = '';
if ($isLoggedIn && $emailValue !== '') {
    try {
        $pdo  = lg_membership_poller_db();
        $stmt = $pdo->prepare(
            "SELECT s.current_period_end FROM subscriptions s
               JOIN customers c ON c.id = s.customer_id
              WHERE c.email = ? AND s.status IN ('active','trialing','past_due')
              ORDER BY s.id DESC LIMIT 1"
        );
        $stmt->execute([$emailValue]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $hasActiveSub  = true;
            $activeSubEnds = $row['current_period_end'] !== null ? substr((string) $row['current_period_end'], 0, 10) : '';
        }
    } catch (Throwable $_) {}
}

/* Pre-fill code from ?code= (gift email links). */
$codeFromUrl = isset($_GET['code']) ? (string) $_GET['code'] : '';
$codeFromUrl = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $codeFromUrl));
if (strlen($codeFromUrl) > 12) { $codeFromUrl = substr($codeFromUrl, 0, 12); }

/* Stapled recipient_email for sent gifts (poller DB). */
$stapledEmail = '';
if ($codeFromUrl !== '' && strlen($codeFromUrl) === 12) {
    try {
        $stmt = lg_membership_poller_db()->prepare(
            'SELECT recipient_email FROM gift_codes WHERE code = ? AND recipient_email IS NOT NULL AND recipient_email <> "" LIMIT 1'
        );
        $stmt->execute([$codeFromUrl]);
        $stapledEmail = (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        error_log('lg_redeem_gift: recipient_email lookup failed: ' . $e->getMessage());
    }
}
if ($stapledEmail !== '') { $emailValue = $stapledEmail; }

$wrongUserSignedIn = $isLoggedIn && $stapledEmail !== '' && strtolower($sessionEmail) !== strtolower($stapledEmail);
$treatAsLoggedIn   = $isLoggedIn && !$wrongUserSignedIn;
if ($wrongUserSignedIn) { $nameValue = ''; }

$logoutUrl = $ctx['logout_url'] ?? lg_ms_home('/wp-login.php?action=logout');

/* Wrong-user hard fail — render refusal in the shell + return early. */
if ($wrongUserSignedIn) {
    $sessEmail = $h($sessionEmail);
    $recipient = $h($stapledEmail);
    $headingHt = $h((string) $atts['heading']);
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Redeem a Gift — The Looth Group</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="<?= $h(LG_MEMBERSHIP_PUBLIC_PATH) ?>/lg-shortcodes.css?v=<?= $h((string)(@filemtime(__DIR__ . '/lg-shortcodes.css') ?: '1')) ?>">
</head>
<body class="lg-membership-page lg-redeem-page">
<?php lg_shared_render_site_header($ctx); ?>
<main id="lg-main">
<div class="lg-redeem-gift">
<h3 class="lg-redeem-gift__heading"><?= $headingHt ?></h3>
<div class="lg-redeem-gift__wronguser" style="margin:0;padding:1.1em 1.2em;background:#fff3f0;border:1px solid #d97757;border-radius:8px;font-size:.95em;line-height:1.5;color:#1f1d1a;">
    <strong style="font-size:1.05em;">You&rsquo;re not <?= $recipient ?>. This gift isn&rsquo;t for you.</strong><br>
    You&rsquo;re signed in as <strong><?= $sessEmail ?></strong>. This code was sent to <strong><?= $recipient ?></strong>, so only that account can redeem it.<br>
    <a href="<?= $h($logoutUrl) ?>" style="display:inline-block;margin-top:.7em;padding:.5em 1em;background:#1f1d1a;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:.92em;">Sign out and try again</a>
</div>
</div>
</main>
<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>
</body>
</html>
<?php
    return;
}

/* Does the recipient email already have a WP user? */
$emailHasExistingUser = false;
if ($stapledEmail !== '') {
    try {
        $st = lg_membership_db()->prepare("SELECT ID FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "users WHERE user_email = ? LIMIT 1");
        $st->execute([$stapledEmail]);
        $emailHasExistingUser = ($st->fetchColumn() !== false);
    } catch (Throwable $e) {}
}
$renderSigninVariant = $emailHasExistingUser && !$treatAsLoggedIn;

$heading     = $h((string) $atts['heading']);
$email       = $h($emailValue);
$name        = $h($nameValue);
$codeAttr    = $h($codeFromUrl);
$endpoint    = lg_ms_esc_js($endpointUrl);
$emailLocked = ($stapledEmail !== '');

$jsAuth     = lg_ms_esc_js(lg_ms_home('/wp-json/lg-member-sync/v1/auth'));
$jsActivity = lg_ms_esc_js(lg_ms_home('/activity/'));
$urlManage  = $h(lg_ms_home('/manage-subscription/'));
$urlLost    = $h(lg_ms_home('/wp-login.php?action=lostpassword'));
$urlActivity= $h(lg_ms_home('/activity/'));
$asset_v    = (string) (@filemtime(__DIR__ . '/lg-shortcodes.css') ?: '1');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Redeem a Gift — The Looth Group</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="<?= $h(LG_MEMBERSHIP_PUBLIC_PATH) ?>/lg-shortcodes.css?v=<?= $h($asset_v) ?>">
</head>
<body class="lg-membership-page lg-redeem-page">
<?php lg_shared_render_site_header($ctx); ?>
<main id="lg-main">
        <div class="lg-redeem-gift">
            <h3 class="lg-redeem-gift__heading"><?php echo $heading; ?></h3>
            <?php if ( $hasActiveSub ) : ?>
            <div class="lg-redeem-gift__active-sub-park" style="margin:0 0 1.2em;padding:1em 1.1em;background:#fbf6e8;border:1px solid #ECB351;border-radius:8px;font-size:.95em;line-height:1.5;color:#1f1d1a;">
                <strong style="font-size:1.05em;">You already have an active subscription<?php echo $activeSubEnds !== '' ? ' &mdash; renews ' . $h( $activeSubEnds ) : ''; ?>.</strong><br>
                No problem. Park this gift and it&rsquo;ll activate the day your subscription ends, so you&rsquo;re covered without a gap and without paying for overlap. Nothing to manage in between.
                <p style="margin:.6em 0 0;font-size:.85em;color:#666;">
                    Prefer to redeem right now? <a href="<?php echo $urlManage; ?>">Cancel your subscription</a> first, then come back here once it expires.
                </p>
            </div>
            <?php endif; ?>
            <?php if ( $renderSigninVariant ) : ?>
            <div class="lg-redeem-gift__intro" style="margin:0 0 1.1em;padding:.85em 1em;background:rgba(135,152,106,0.10);border:1px solid rgba(135,152,106,0.35);border-radius:8px;font-size:.93em;line-height:1.45;color:#1f1d1a;">
                <strong>This email already has an account.</strong>
                Sign in below and we&rsquo;ll add this gift to your existing membership.
            </div>
            <?php endif; ?>
            <form class="lg-redeem-gift__form" data-lg-redeem>
                <label class="lg-redeem-gift__label">
                    <span>Gift Code</span>
                    <input
                        type="text"
                        name="code"
                        required
                        maxlength="12"
                        autocomplete="off"
                        pattern="[A-Za-z0-9]{12}"
                        title="12-character gift code"
                        placeholder="ABCDEFGHIJKL"
                        value="<?php echo $codeAttr; ?>"
                        style="text-transform:uppercase;letter-spacing:0.1em;"
                    >
                </label>
                <label class="lg-redeem-gift__label">
                    <span>Email</span>
                    <input
                        type="email"
                        name="email"
                        required
                        value="<?php echo $email; ?>"
                        <?php if ( $emailLocked ) : ?>readonly aria-readonly="true" style="background:#f4f4f0;cursor:not-allowed;color:#444;"<?php endif; ?>
                    >
                    <?php if ( $emailLocked ) : ?>
                    <small style="display:block;margin-top:.3em;color:rgba(0,0,0,0.55);font-size:.85em;line-height:1.4;">
                        This gift was sent to <strong><?php echo $h( $emailValue ); ?></strong>. Your account will be created (or signed into) under this email so the membership lands where the sender intended.
                    </small>
                    <?php endif; ?>
                </label>
                <?php if ( ! $renderSigninVariant ) : ?>
                <label class="lg-redeem-gift__label">
                    <span>Name <em style="opacity:.6;">(shown to other members)</em></span>
                    <input type="text" name="name" value="<?php echo $name; ?>" required>
                </label>
                <?php endif; ?>
                <?php if ( ! $treatAsLoggedIn ) : ?>
                <label class="lg-redeem-gift__label">
                    <span>Password</span>
                    <?php if ( $renderSigninVariant ) : ?>
                    <input type="password" name="password" minlength="8" required autocomplete="current-password" placeholder="Your account password">
                    <small style="display:block;margin-top:.3em;color:rgba(0,0,0,0.55);font-size:.85em;line-height:1.4;">
                        <a href="<?php echo $urlLost; ?>">Forgot your password?</a>
                    </small>
                    <?php else : ?>
                    <input type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="Pick a password (8+ characters)">
                    <small style="display:block;margin-top:.3em;color:rgba(0,0,0,0.55);font-size:.85em;line-height:1.4;">
                        This becomes your account password so you can log in any time to manage your membership.
                    </small>
                    <?php endif; ?>
                </label>
                <?php endif; ?>
                <button type="submit" class="lg-redeem-gift__submit"><?php echo $renderSigninVariant ? 'Sign in &amp; redeem' : 'Redeem &amp; activate my account'; ?></button>
            </form>
            <div class="lg-redeem-gift__result" data-lg-redeem-result aria-live="polite"></div>

            <style>
                .lg-welcome { position: fixed !important; inset: 0 !important; z-index: 2147483600 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; }
                .lg-welcome[hidden] { display: none !important; }
                .lg-welcome__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.65); }
                .lg-welcome__card { position: relative; background: #fff; border-radius: 14px; padding: 1.8em 1.7em; max-width: 440px; width: 100%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.45); color: #1f1d1a; }
                .lg-welcome__icon { font-size: 2.6em; margin-bottom: .25em; line-height: 1; }
                .lg-welcome__title { margin: 0 0 .55em; font-size: 1.3em; font-weight: 700; }
                .lg-welcome__body { margin: 0 0 1.2em; font-size: .96em; line-height: 1.5; color: #333; }
                .lg-welcome__btn { display: inline-block; padding: .7em 1.4em; background: var(--lg-amber, #ECB351); color: #1f1d1a !important; border-radius: 8px; font-weight: 700; text-decoration: none; transition: opacity .15s; }
                .lg-welcome__btn:hover { opacity: .9; }
            </style>
            <div class="lg-welcome" data-lg-welcome-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-welcome-title">
                <div class="lg-welcome__backdrop"></div>
                <div class="lg-welcome__card">
                    <div class="lg-welcome__icon" aria-hidden="true">&#127881;</div>
                    <h3 id="lg-welcome-title" class="lg-welcome__title">Welcome to the Looth Group!</h3>
                    <p class="lg-welcome__body">
                        Your gift code is redeemed and your account is live. Jump into the activity feed to meet other members, browse the archive, and join a forum.
                    </p>
                    <a class="lg-welcome__btn" data-lg-welcome-go href="<?php echo $urlActivity; ?>">Take me to the feed &rarr;</a>
                </div>
            </div>
        </div>
        <script>
        (function(){
            const ENDPOINT = '<?php echo $endpoint; ?>';
            const form     = document.querySelector('[data-lg-redeem]');
            const resultEl = document.querySelector('[data-lg-redeem-result]');
            const submitBt = form.querySelector('button[type="submit"]');
            if (!form) return;

            let pending = null;

            async function postRedeem(payload){
                const res  = await fetch(ENDPOINT, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload),
                });
                return res.json();
            }

            const AUTH_URL    = '<?php echo $jsAuth; ?>';
            const ALREADY_IN  = <?php echo $treatAsLoggedIn ? 'true' : 'false'; ?>;
            const EMAIL_HAS_USER = <?php echo $emailHasExistingUser ? 'true' : 'false'; ?>;
            const welcomeEl   = document.querySelector('[data-lg-welcome-modal]');
            if (welcomeEl && welcomeEl.parentNode !== document.body) document.body.appendChild(welcomeEl);

            async function finalizeLogin(email, password, displayName){
                if (ALREADY_IN) return { ok: true };
                try {
                    const res = await fetch(AUTH_URL, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify({
                            email, password,
                            display_name: displayName,
                            confirmed_consent: true,
                            redemption_code: (form.querySelector('input[name="code"]')?.value || '').trim().toUpperCase(),
                        }),
                    });
                    return await res.json();
                } catch (e) {
                    return { ok: false, error: 'Could not finish account setup. Try logging in manually.' };
                }
            }

            function showWelcome(){
                if (!welcomeEl) {
                    window.location.href = '<?php echo $jsActivity; ?>';
                    return;
                }
                welcomeEl.hidden = false;
                document.body.classList.add('lg-modal-open');
            }

            function renderSuccess(json){
                resultEl.className   = 'lg-redeem-gift__result is-success';
                resultEl.textContent = json.message + ' (expires ' + json.expires_at + ')';
                form.reset();
                pending = null;
                showWelcome();
            }

            function renderError(msg, portalUrl){
                resultEl.className = 'lg-redeem-gift__result is-error';
                if (portalUrl) {
                    resultEl.innerHTML = msg + ' <a href="' + portalUrl + '" target="_blank">Manage your subscription</a>';
                } else {
                    resultEl.textContent = msg;
                }
            }

            function renderConflictLogin(payload) {
                resultEl.className = 'lg-redeem-gift__result is-warn';
                resultEl.innerHTML = '';

                const wrap = document.createElement('div');
                wrap.style.cssText = 'padding:1em 1.1em;background:rgba(255,200,80,0.08);border:1px solid rgba(255,180,40,0.4);border-radius:8px;';
                wrap.innerHTML =
                    '<p style="margin:0 0 .8em;"><strong>This email already has an active membership.</strong><br>' +
                    'Log in to add this gift to your account &mdash; this protects you from anyone else stacking time onto your account without your permission.</p>' +
                    '<div class="lg-redeem-gift__loginrow" style="display:flex;flex-direction:column;gap:.55em;">' +
                        '<input type="email" data-lg-conflict-email value="' + payload.email.replace(/"/g, '&quot;') + '" readonly style="width:100%;padding:.55em .8em;border:1px solid rgba(0,0,0,0.15);border-radius:6px;background:#f4f4f0;color:#444;">' +
                        '<input type="password" data-lg-conflict-pass placeholder="Your account password" autocomplete="current-password" style="width:100%;padding:.55em .8em;border:1px solid rgba(0,0,0,0.15);border-radius:6px;">' +
                    '</div>' +
                    '<div data-lg-conflict-error style="display:none;margin-top:.55em;color:#b91c1c;font-size:.9em;"></div>' +
                    '<div style="margin-top:.85em;display:flex;align-items:center;gap:.85em;flex-wrap:wrap;">' +
                        '<button type="button" data-lg-conflict-go style="padding:.55em 1.1em;background:var(--lg-amber,#ECB351);color:#1f1d1a;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Log in &amp; apply</button>' +
                        '<a href="<?php echo $urlLost; ?>" style="font-size:.85em;">Forgot your password?</a>' +
                    '</div>';
                resultEl.appendChild(wrap);

                const passEl = wrap.querySelector('[data-lg-conflict-pass]');
                const errEl  = wrap.querySelector('[data-lg-conflict-error]');
                const btn    = wrap.querySelector('[data-lg-conflict-go]');
                passEl.focus();

                btn.addEventListener('click', async () => {
                    const pwd = passEl.value;
                    if (!pwd || pwd.length < 8) {
                        errEl.textContent = 'Please enter your account password.';
                        errEl.style.display = 'block';
                        return;
                    }
                    errEl.style.display = 'none';
                    btn.disabled = true;
                    btn.textContent = 'Signing in…';

                    try {
                        const res = await fetch(AUTH_URL, {
                            method:  'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body:    JSON.stringify({
                                email:    payload.email,
                                password: pwd,
                                display_name: payload.name,
                                confirmed_consent: true,
                                redemption_code: payload.code,
                            }),
                        });
                        const data = await res.json();
                        if (!data.ok) {
                            errEl.textContent = (data.error || 'Sign-in failed.') +
                                (data.forgot ? ' Use "Forgot your password?" if you need a reset.' : '');
                            errEl.style.display = 'block';
                            btn.disabled    = false;
                            btn.textContent = 'Log in & apply';
                            return;
                        }
                        const url = window.location.pathname + '?code=' + encodeURIComponent(payload.code);
                        window.location.href = url;
                    } catch (e) {
                        errEl.textContent = 'Network error. Please try again.';
                        errEl.style.display = 'block';
                        btn.disabled    = false;
                        btn.textContent = 'Log in & apply';
                    }
                });
            }

            function renderChoice(json){
                pending = { code: json._payload.code, email: json._payload.email, name: json._payload.name };
                const recommended = json.recommended;

                const wrap = document.createElement('div');
                wrap.className = 'lg-redeem-gift__choice';

                const intro = document.createElement('p');
                intro.innerHTML =
                    'You already have <strong>' + json.current.days_remaining +
                    ' days</strong> of <strong>' + json.current.tier + '</strong> active. ' +
                    'How do you want to apply this <strong>' + json.incoming.duration_days +
                    '-day ' + json.incoming.tier + '</strong> code?';
                wrap.appendChild(intro);

                const list = document.createElement('div');
                list.className = 'lg-redeem-gift__options';
                json.options.forEach(function(opt){
                    const id = 'lg-opt-' + opt.id;
                    const row = document.createElement('label');
                    row.className = 'lg-redeem-gift__option';
                    row.htmlFor   = id;
                    row.innerHTML =
                        '<input type="radio" name="strategy" id="' + id + '" value="' + opt.id + '"' +
                        (opt.id === recommended ? ' checked' : '') + '> ' +
                        '<span>' + opt.label + '</span>';
                    list.appendChild(row);
                });
                wrap.appendChild(list);

                const apply = document.createElement('button');
                apply.type        = 'button';
                apply.textContent = 'Apply';
                apply.className   = 'lg-redeem-gift__submit';
                apply.addEventListener('click', applyChoice);
                wrap.appendChild(apply);

                resultEl.className = 'lg-redeem-gift__result';
                resultEl.innerHTML = '';
                resultEl.appendChild(wrap);
            }

            async function applyChoice(){
                const picked = document.querySelector('input[name="strategy"]:checked');
                if (!picked || !pending) return;
                resultEl.className = 'lg-redeem-gift__result is-pending';
                resultEl.textContent = 'Applying…';
                try {
                    const json = await postRedeem(Object.assign({}, pending, { strategy: picked.value }));
                    if (json.ok && !json.requires_choice) {
                        renderSuccess(json);
                    } else {
                        renderError(json.error || 'Unable to apply choice.');
                    }
                } catch (err) {
                    renderError('Network error: ' + err.message);
                }
            }

            form.addEventListener('submit', async function(e){
                e.preventDefault();
                resultEl.textContent = 'Working…';
                resultEl.className   = 'lg-redeem-gift__result is-pending';
                submitBt.disabled    = true;

                const payload = {
                    code:  (form.code.value  || '').trim().toUpperCase(),
                    email: (form.email.value || '').trim(),
                    name:  (form.name && form.name.value ? form.name.value.trim() : ''),
                };
                const password = (form.password ? form.password.value : '');

                if (!ALREADY_IN) {
                    if (!password || password.length < 8) {
                        resultEl.className = 'lg-redeem-gift__result is-error';
                        resultEl.textContent = 'Please enter a password (8+ characters).';
                        submitBt.disabled = false;
                        return;
                    }
                    try {
                        const authRes = await fetch(AUTH_URL, {
                            method:  'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body:    JSON.stringify({
                                email:    payload.email,
                                password: password,
                                display_name:      payload.name,
                                confirmed_consent: true,
                                redemption_code:   payload.code,
                            }),
                        });
                        const authData = await authRes.json();
                        if (!authData.ok) {
                            resultEl.className = 'lg-redeem-gift__result is-error';
                            resultEl.innerHTML =
                                '<strong>This email already has an account.</strong> ' +
                                'Please enter your account password to log in and redeem this gift.<br>' +
                                '<small>' + (authData.error || 'Sign-in failed.') +
                                (authData.forgot ? ' &middot; <a href="<?php echo $urlLost; ?>">Forgot your password?</a>' : '') +
                                '</small>';
                            submitBt.disabled = false;
                            return;
                        }
                        if (EMAIL_HAS_USER) {
                            const url = window.location.pathname + '?code=' + encodeURIComponent(payload.code);
                            window.location.href = url;
                            return;
                        }
                    } catch (e) {
                        resultEl.className = 'lg-redeem-gift__result is-error';
                        resultEl.textContent = 'Network error during sign-in. Please try again.';
                        submitBt.disabled = false;
                        return;
                    }
                }

                resultEl.textContent = 'Redeeming…';
                try {
                    const json = await postRedeem(payload);

                    if (json.ok && json.requires_choice) {
                        if (ALREADY_IN) {
                            json._payload = payload;
                            renderChoice(json);
                        } else {
                            renderConflictLogin(payload);
                        }
                    } else if (json.ok && json.queued) {
                        const startDate = json.starts_at ? new Date(json.starts_at).toLocaleDateString(undefined, { month:'long', day:'numeric', year:'numeric' }) : 'when your subscription ends';
                        resultEl.innerHTML = '<div style="margin:1em 0;padding:1.1em 1.2em;background:#fbf6e8;border:1px solid #ECB351;border-radius:8px;line-height:1.5;color:#1f1d1a;">' +
                            '<strong style="font-size:1.05em;">🎁 Gift parked!</strong><br>' +
                            'Your gift will activate on <strong>' + startDate + '</strong>, the day your current subscription ends. ' +
                            'Nothing else to do — when the time comes, your membership rolls right over.' +
                            '</div>';
                    } else if (json.ok) {
                        renderSuccess(json);
                    } else if (json.requires_queue) {
                        const retry = await postRedeem(Object.assign({}, payload, { queue_until_sub_ends: true }));
                        if (retry.ok && retry.queued) {
                            const startDate = retry.starts_at ? new Date(retry.starts_at).toLocaleDateString(undefined, { month:'long', day:'numeric', year:'numeric' }) : 'when your subscription ends';
                            resultEl.innerHTML = '<div style="margin:1em 0;padding:1.1em 1.2em;background:#fbf6e8;border:1px solid #ECB351;border-radius:8px;line-height:1.5;color:#1f1d1a;">' +
                                '<strong style="font-size:1.05em;">🎁 Gift parked!</strong><br>' +
                                'Your gift will activate on <strong>' + startDate + '</strong>, the day your current subscription ends.' +
                                '</div>';
                        } else {
                            renderError(retry.error || 'Could not park gift.', retry.portal_url);
                        }
                    } else {
                        renderError(json.error || 'Unable to redeem code.', json.portal_url);
                    }
                } catch (err) {
                    renderError('Network error: ' + err.message);
                } finally {
                    submitBt.disabled = false;
                }
            });
        })();
        </script>
</main>
<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>
</body>
</html>
