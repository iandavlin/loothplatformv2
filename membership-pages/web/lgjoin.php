<?php
/**
 * /lgjoin/ — standalone port of [lg_join] (Stripe tier-picker + checkout).
 *
 * VERBATIM body port of Shortcodes::join() (src/Wp/Shortcodes.php:2822). Only the
 * chrome changes (BB theme → shared shell) and the WP server-side helpers are
 * swapped for standalone equivalents:
 *   - wp_get_current_user()   → wordpress_logged_in_* cookie → wp_users lookup
 *   - home_url()/rest_url()   → https://HOST/… string builders
 *   - lookupActiveSub()       → same SQL against the poller DB (lg_membership)
 *   - esc_html/esc_attr/esc_js/wp_json_encode → lg_membership_h / lg_ms_esc_js / json_encode
 *
 * The browser-side flow is unchanged: it talks to the Slim billing API
 * (/billing/v1/{products,config,checkout,affiliate-click,return}) and the WP REST
 * auth route (/wp-json/lg-member-sync/v1/auth) directly via fetch — both portable.
 * Stripe runs in TEST mode in the sandbox (sk_test_, same key both sides).
 *
 * Styles: vendored lg-shortcodes.css (.lg-join__*, .lg-pay-modal__*, .lg-stripe-modal__*).
 * Admin-only pre-launch (router enforces; this self-gate is defense-in-depth).
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

/* ---- standalone shims for the WP functions the shortcode used ---- */
function lg_ms_home(string $p = ''): string { return 'https://' . LG_MEMBERSHIP_HOST . $p; }
/** Escape for a single/double-quoted JS string literal (esc_js stand-in). */
function lg_ms_esc_js(string $s): string {
    return strtr($s, ['\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\n" => '\\n', "\r" => '\\r', '</' => '<\\/']);
}
/** Replicates Shortcodes::lookupActiveSub() against the poller DB. */
function lg_ms_lookup_active_sub(string $email): ?array {
    if ($email === '') return null;
    try {
        $pdo  = lg_membership_poller_db();
        $stmt = $pdo->prepare('SELECT id FROM customers WHERE email = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$email]);
        $cid = $stmt->fetchColumn();
        if ($cid === false) return null;
        $stmt = $pdo->prepare(
            "SELECT s.stripe_price_id, s.status, s.current_period_end, p.ref AS tier, pr.unit_amount_cents, pr.interval AS itv
             FROM subscriptions s
             JOIN prices   pr ON pr.stripe_price_id = s.stripe_price_id
             JOIN products  p ON p.id = pr.product_id
             WHERE s.customer_id = ? AND s.status IN ('active','trialing','past_due')
             ORDER BY s.id DESC LIMIT 1"
        );
        $stmt->execute([(int) $cid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    } catch (Throwable $e) {
        error_log('lgjoin lookupActiveSub: ' . $e->getMessage());
        return null;
    }
}

/* ---- resolve the logged-in user (email/name) via the WP DB ---- */
$isLoggedIn = ($ctx['authenticated'] ?? false) === true;
$emailValue = '';
$nameValue  = '';
foreach ($_COOKIE as $ck => $cv) {
    if (strpos($ck, 'wordpress_logged_in_') === 0) {
        $parts = explode('|', urldecode((string) $cv), 4);
        if (!empty($parts[0])) {
            try {
                $st = lg_membership_db()->prepare("SELECT user_email, display_name, user_login FROM " . LG_MEMBERSHIP_TABLE_PREFIX . "users WHERE user_login = ? LIMIT 1");
                $st->execute([$parts[0]]);
                if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                    $emailValue = (string) $u['user_email'];
                    $nameValue  = trim((string) ($u['display_name'] ?: $u['user_login']));
                }
            } catch (Throwable $e) {}
        }
        break;
    }
}

/* If the logged-in user already has an active paid Stripe sub, redirect to
   /manage-subscription/ instead of letting them double-buy (verbatim behavior;
   the router has not emitted output yet, so the header is safe to send). */
// Pre-launch admin bypass: admins are building/testing this surface, so they must
// be able to SEE the picker even though they hold an active sub. The anti-double-buy
// redirect still applies to real members once this flips to member-visible at go-live.
$isAdmin   = ($ctx['capabilities']['manage_options'] ?? false) === true;
$activeSub = (!$isAdmin && $isLoggedIn && $emailValue !== '') ? lg_ms_lookup_active_sub($emailValue) : null;
if ($activeSub !== null && !headers_sent()) {
    header('Location: ' . lg_ms_home('/manage-subscription/'), true, 302);
    exit;
}

/* ---- shortcode_atts() defaults (this surface takes no attributes) ---- */
$atts = [
    'heading'         => 'Choose your membership',
    'subheading'      => '',
    'bullets'         => '',
    'popular'         => 'looth3',
    'taglines'        => '',
    'features_looth2' => 'Member forums & community|Interviews with guests|AMAs',
    'features_looth3' => 'Everything in LITE|Demo-based content|Live session archives',
];

$base      = rtrim(lg_ms_home('/billing'), '/');
$endpoints = [
    'products'       => $base . '/v1/products',
    'config'         => $base . '/v1/config',
    'checkout'       => $base . '/v1/checkout',
    'affiliateClick' => $base . '/v1/affiliate-click',
];

$promoFromUrl = isset($_GET['promo']) ? (string) $_GET['promo'] : '';
$promoFromUrl = (string) preg_replace('/[^A-Za-z0-9_\-]/', '', $promoFromUrl);

$countryFromUrl = isset($_GET['country']) ? strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) $_GET['country'])) : '';
if (strlen($countryFromUrl) !== 2) { $countryFromUrl = ''; }

$previewSingle = isset($_GET['preview']) && $_GET['preview'] === 'single';

$heading     = $h((string) $atts['heading']);
$subheading  = $h((string) $atts['subheading']);
$bulletsRaw  = trim((string) $atts['bullets']);
$bullets     = $bulletsRaw !== '' ? array_filter(array_map('trim', explode('|', $bulletsRaw))) : [];
$popularRef  = (string) $atts['popular'];
$taglinesRaw = trim((string) $atts['taglines']);
$taglineMap  = [];
if ($taglinesRaw !== '') {
    foreach (explode('|', $taglinesRaw) as $pair) {
        $p2 = explode(':', $pair, 2);
        if (count($p2) === 2) { $taglineMap[trim($p2[0])] = trim($p2[1]); }
    }
}
$featuresMap = [];
foreach (['looth1', 'looth2', 'looth3', 'looth4'] as $ref) {
    $key = "features_{$ref}";
    if (!empty($atts[$key])) {
        $featuresMap[$ref] = array_values(array_filter(array_map('trim', explode('|', (string) $atts[$key]))));
    }
}

$email       = $h($emailValue);
$name        = $h($nameValue);
$promoEsc    = $h($promoFromUrl);
$endpointsJs = json_encode($endpoints);
$authUrl     = lg_ms_home('/wp-json/lg-member-sync/v1/auth');
$configJs    = json_encode([
    'popular'       => $popularRef,
    'taglines'      => $taglineMap,
    'features'      => $featuresMap,
    'loggedIn'      => $isLoggedIn,
    'authUrl'       => $authUrl,
    'forgotUrl'     => lg_ms_home('/wp-login.php?action=lostpassword'),
    'previewSingle' => $previewSingle,
]);

/* preview toggle links (remove_query_arg / add_query_arg stand-ins) */
$reqPath        = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/lgjoin/'), '?') ?: '/lgjoin/';
$qsNoPreview    = $_GET; unset($qsNoPreview['preview']);
$urlExitPreview = $reqPath . ($qsNoPreview ? '?' . http_build_query($qsNoPreview) : '');
$urlEnterView   = $reqPath . '?' . http_build_query(array_merge($_GET, ['preview' => 'single']));

/* JS-string URLs */
$jsManage   = lg_ms_esc_js(lg_ms_home('/manage-subscription/'));
$jsLogin    = lg_ms_esc_js(lg_ms_home('/wp-login.php'));
$jsForgot   = lg_ms_esc_js(lg_ms_home('/wp-login.php?action=lostpassword'));
$jsReturn   = lg_ms_esc_js(lg_ms_home('/billing/v1/return'));
$jsActivity = lg_ms_esc_js(lg_ms_home('/activity/'));

$asset_v = (string) (@filemtime(__DIR__ . '/lg-shortcodes.css') ?: '1');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Join — The Looth Group</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="<?= $h(LG_MEMBERSHIP_PUBLIC_PATH) ?>/lg-shortcodes.css?v=<?= $h($asset_v) ?>">
</head>
<body class="lg-membership-page lg-join-page">
<?php lg_shared_render_site_header($ctx); ?>
<main id="lg-main">
        <div class="lg-join">
            <header class="lg-join__hero">
                <h2><?php echo $heading; ?></h2>
                <?php if ( $subheading !== '' ) : ?>
                    <p><?php echo $subheading; ?></p>
                <?php endif; ?>
                <?php if ( $bullets !== [] ) : ?>
                    <ul>
                        <?php foreach ( $bullets as $b ) : ?>
                            <li><?php echo $h( $b ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </header>

            <div class="lg-join__trial-banner" data-lg-trial-banner hidden>
                <span class="lg-join__trial-banner-inner">&#10003; 7-day free trial on all plans &mdash; no charge until day 8</span>
            </div>

            <?php if ( $previewSingle ) : ?>
            <div class="lg-join__preview-bar">
                <span>&#9888; Preview mode: single tier</span>
                <a href="<?php echo $h( $urlExitPreview ); ?>" class="lg-join__preview-exit">Exit preview</a>
            </div>
            <?php else : ?>
            <div class="lg-join__preview-bar lg-join__preview-bar--hint">
                <a href="<?php echo $h( $urlEnterView ); ?>" class="lg-join__preview-link">&#128065; Preview single-tier layout</a>
            </div>
            <?php endif; ?>

            <div class="lg-join__region-note" data-lg-region-note hidden></div>

            <div class="lg-join__tiers" data-lg-join-tiers>
                <p class="lg-join__loading">Loading plans&hellip;</p>
            </div>

            <div class="lg-pay-methods lg-join__pay-methods-bar" aria-label="Accepted payment methods">
                <span class="lg-pay-methods__label">Secure checkout &mdash; we accept</span>
                <ul class="lg-pay-methods__list">
                    <li class="lg-pm lg-pm--visa" title="Visa"><svg viewBox="0 0 48 16" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><text x="24" y="13" text-anchor="middle" font-family="Arial Black, Helvetica, sans-serif" font-weight="900" font-style="italic" font-size="13" fill="#fff" letter-spacing="0.5">VISA</text></svg></li>
                    <li class="lg-pm lg-pm--mc" title="Mastercard"><svg viewBox="0 0 32 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="13" cy="10" r="6.5" fill="#EB001B"/><circle cx="19" cy="10" r="6.5" fill="#F79E1B" fill-opacity=".95"/><path d="M16 5.2a6.5 6.5 0 0 0 0 9.6 6.5 6.5 0 0 0 0-9.6z" fill="#FF5F00"/></svg></li>
                    <li class="lg-pm lg-pm--amex" title="American Express"><svg viewBox="0 0 48 16" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><text x="24" y="12" text-anchor="middle" font-family="Arial Black, Helvetica, sans-serif" font-weight="900" font-size="9" fill="#fff" letter-spacing="0.5">AMEX</text></svg></li>
                    <li class="lg-pm lg-pm--apple" title="Apple Pay"><svg viewBox="0 0 60 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="#fff" d="M11.6 6.1c-.7.8-1.7 1.5-2.8 1.4-.1-1.1.4-2.2 1-2.9.7-.8 1.9-1.4 2.8-1.4.1 1.1-.4 2.2-1 2.9zm1 1.6c-1.6-.1-2.9.9-3.7.9-.7 0-1.9-.9-3.1-.8-1.6 0-3.1.9-3.9 2.4-1.7 2.9-.4 7.2 1.2 9.5.8 1.1 1.7 2.4 3 2.4 1.2-.1 1.7-.8 3.2-.8 1.5 0 1.9.8 3.2.7 1.3 0 2.2-1.2 3-2.3.9-1.3 1.3-2.6 1.3-2.7-.1 0-2.5-1-2.6-3.8 0-2.4 2-3.5 2.1-3.6-1.1-1.6-2.8-1.8-3.7-1.9z"/><text x="22" y="14.5" font-family="Helvetica Neue, Helvetica, Arial, sans-serif" font-weight="500" font-size="9" fill="#fff">Pay</text></svg></li>
                    <li class="lg-pm lg-pm--google" title="Google Pay"><svg viewBox="0 0 60 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M9.4 11.4v3.5h-1.1V6.3h2.9c.7 0 1.4.3 1.9.8.5.5.8 1.2.8 1.9 0 .7-.3 1.4-.8 1.9-.5.5-1.1.8-1.9.8h-1.8zm0-4.1v3.1h1.9c.4 0 .8-.2 1.1-.5.6-.6.6-1.5 0-2.1-.3-.3-.7-.5-1.1-.5h-1.9zm6.4 1.2c.8 0 1.5.2 2 .7.5.4.7 1 .7 1.8v3.6h-1.1v-.8h-.1c-.5.7-1.1 1-1.8 1-.6 0-1.1-.2-1.5-.6-.4-.3-.6-.8-.6-1.3 0-.6.2-1 .6-1.4.4-.3 1-.5 1.7-.5.6 0 1.1.1 1.5.3v-.3c0-.4-.1-.7-.4-1-.3-.3-.6-.4-1-.4-.6 0-1.1.3-1.5.8l-1-.6c.5-.8 1.3-1.3 2.5-1.3zm-1.5 4.4c0 .2.1.4.3.6.2.2.4.2.7.2.4 0 .8-.1 1.1-.4.3-.3.5-.7.5-1.1-.3-.3-.8-.4-1.4-.4-.4 0-.8.1-1 .3-.2.2-.2.5-.2.8zm10.4-4.2L21.1 17h-1.2l1.4-3-2.4-5.3h1.2l1.7 4.1 1.7-4.1h1.2z" fill="#5F6368"/><path d="M33 10.7c0-.3 0-.6-.1-.9h-4.5v1.7h2.6c-.1.6-.4 1.1-.9 1.4v1.2h1.5c.9-.8 1.4-2 1.4-3.4z" fill="#4285F4"/><path d="M28.4 15.5c1.3 0 2.4-.4 3.2-1.2l-1.5-1.2c-.4.3-1 .5-1.7.5-1.3 0-2.4-.9-2.8-2h-1.6v1.2c.8 1.6 2.5 2.7 4.4 2.7z" fill="#34A853"/><path d="M25.6 11.6c-.2-.6-.2-1.3 0-1.9V8.5H24c-.7 1.3-.7 2.9 0 4.3l1.6-1.2z" fill="#FBBC04"/><path d="M28.4 7.6c.7 0 1.4.3 1.9.7l1.4-1.4c-.9-.8-2.1-1.3-3.3-1.2-1.9 0-3.6 1.1-4.4 2.7l1.6 1.2c.4-1.1 1.4-2 2.8-2z" fill="#EA4335"/><text x="36" y="14.5" font-family="Helvetica Neue, Helvetica, Arial, sans-serif" font-weight="500" font-size="9" fill="#5F6368">Pay</text></svg></li>
                </ul>
            </div>

            <!-- Sign-up modal — uses .lg-pay-modal which is proven to work against BuddyBoss -->
            <div class="lg-pay-modal" data-lg-signup-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-signup-modal-title">
                <div class="lg-pay-modal__backdrop" data-lg-signup-close></div>
                <div class="lg-pay-modal__card lg-pay-modal__card--signup">
                    <button type="button" class="lg-pay-modal__close" data-lg-signup-close aria-label="Close">&times;</button>
                    <div class="lg-pay-modal__body lg-pay-modal__body--signup">
                    <div class="lg-join__form" data-lg-join-form>
                        <h3 class="lg-join__form-heading" id="lg-signup-modal-title" data-lg-form-heading>Almost there</h3>
                        <div class="lg-join__form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1em 1.2em;">
                            <?php if ( $isLoggedIn ) : ?>
                            <div class="lg-join__field" style="grid-column: 1 / -1;">
                                <label>Email</label>
                                <div class="lg-join__static" style="padding:.55em .75em;background:#f4f4f0;border:1px solid rgba(0,0,0,0.1);border-radius:6px;color:#333;font-size:.95em;">
                                    <strong><?php echo $email; ?></strong>
                                    <span style="float:right;font-size:.82em;color:#888;">signed in</span>
                                </div>
                                <input type="hidden" name="email" value="<?php echo $email; ?>">
                                <input type="hidden" name="email_confirm" value="<?php echo $email; ?>">
                            </div>
                            <?php else : ?>
                            <div class="lg-join__field">
                                <label>Email <input type="email" name="email" value="<?php echo $email; ?>" autocomplete="email" required></label>
                            </div>
                            <div class="lg-join__field">
                                <label>Confirm email <input type="email" name="email_confirm" value="<?php echo $email; ?>" autocomplete="email" required></label>
                                <small data-lg-email-mismatch class="lg-pwd-mismatch" hidden>Emails don&rsquo;t match.</small>
                            </div>
                            <?php endif; ?>
                            <?php if ( ! $isLoggedIn ) : ?>
                            <div class="lg-join__field">
                                <label>Password
                                    <span class="lg-pwd-wrap">
                                        <input type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="Pick a password (8+ characters)">
                                        <button type="button" class="lg-pwd-eye" data-lg-pwd-eye-for="password" aria-label="Show password"><svg class="lg-pwd-eye__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                                    </span>
                                </label>
                                <small>This becomes your account password so you can log in any time to manage your subscription.</small>
                            </div>
                            <div class="lg-join__field">
                                <label>Confirm password
                                    <span class="lg-pwd-wrap">
                                        <input type="password" name="password_confirm" minlength="8" required autocomplete="new-password" placeholder="Re-enter your password">
                                        <button type="button" class="lg-pwd-eye" data-lg-pwd-eye-for="password_confirm" aria-label="Show password"><svg class="lg-pwd-eye__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                                    </span>
                                </label>
                                <small data-lg-pwd-mismatch class="lg-pwd-mismatch" hidden>Passwords don&rsquo;t match.</small>
                            </div>
                            <?php endif; ?>
                            <?php if ( $isLoggedIn ) : ?>
                            <div class="lg-join__field" style="grid-column: 1 / -1;">
                                <label>Profile name <em style="opacity:.6;font-weight:400;">(what other members will see)</em></label>
                                <div class="lg-join__static" style="padding:.55em .75em;background:#f4f4f0;border:1px solid rgba(0,0,0,0.1);border-radius:6px;color:#333;font-size:.95em;">
                                    <strong><?php echo $name; ?></strong>
                                </div>
                                <input type="hidden" name="name" value="<?php echo $name; ?>">
                                <small>Edit your profile name from your <a href="<?php echo $h( lg_ms_home( '/manage-subscription/' ) ); ?>">membership page</a>.</small>
                            </div>
                            <?php else : ?>
                            <div class="lg-join__field" style="grid-column: 1 / -1;">
                                <label>Profile name <em style="opacity:.6;font-weight:400;">(what other members will see)</em>
                                    <input type="text" name="name" value="<?php echo $name; ?>" required>
                                </label>
                                <small>This is the name other members see in forums, comments, and the activity feed &mdash; not optional.</small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <details class="lg-join__discount" <?php echo $promoFromUrl !== '' ? 'open' : ''; ?>>
                            <summary>Have a discount code?</summary>
                            <div class="lg-join__discount-row">
                                <input type="text" name="promo_code" placeholder="e.g. PATREON5" value="<?php echo $promoEsc; ?>" autocomplete="off" maxlength="64">
                                <small data-lg-promo-status></small>
                            </div>
                        </details>
                        <div class="lg-join__form-actions">
                            <button type="button" class="lg-join__continue is-primary" data-lg-continue>Continue to checkout</button>
                            <button type="button" class="lg-join__back" data-lg-back>Change plan</button>
                        </div>
                    </div>
                    <div class="lg-pay-methods lg-pay-modal__pay-methods" aria-label="Accepted payment methods">
                        <span class="lg-pay-methods__label">Secure checkout &mdash; we accept</span>
                        <ul class="lg-pay-methods__list">
                            <li class="lg-pm lg-pm--visa" title="Visa"><svg viewBox="0 0 48 16" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><text x="24" y="13" text-anchor="middle" font-family="Arial Black, Helvetica, sans-serif" font-weight="900" font-style="italic" font-size="13" fill="#fff" letter-spacing="0.5">VISA</text></svg></li>
                            <li class="lg-pm lg-pm--mc" title="Mastercard"><svg viewBox="0 0 32 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="13" cy="10" r="6.5" fill="#EB001B"/><circle cx="19" cy="10" r="6.5" fill="#F79E1B" fill-opacity=".95"/><path d="M16 5.2a6.5 6.5 0 0 0 0 9.6 6.5 6.5 0 0 0 0-9.6z" fill="#FF5F00"/></svg></li>
                            <li class="lg-pm lg-pm--amex" title="American Express"><svg viewBox="0 0 48 16" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><text x="24" y="12" text-anchor="middle" font-family="Arial Black, Helvetica, sans-serif" font-weight="900" font-size="9" fill="#fff" letter-spacing="0.5">AMEX</text></svg></li>
                            <li class="lg-pm lg-pm--apple" title="Apple Pay"><svg viewBox="0 0 60 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="#fff" d="M11.6 6.1c-.7.8-1.7 1.5-2.8 1.4-.1-1.1.4-2.2 1-2.9.7-.8 1.9-1.4 2.8-1.4.1 1.1-.4 2.2-1 2.9zm1 1.6c-1.6-.1-2.9.9-3.7.9-.7 0-1.9-.9-3.1-.8-1.6 0-3.1.9-3.9 2.4-1.7 2.9-.4 7.2 1.2 9.5.8 1.1 1.7 2.4 3 2.4 1.2-.1 1.7-.8 3.2-.8 1.5 0 1.9.8 3.2.7 1.3 0 2.2-1.2 3-2.3.9-1.3 1.3-2.6 1.3-2.7-.1 0-2.5-1-2.6-3.8 0-2.4 2-3.5 2.1-3.6-1.1-1.6-2.8-1.8-3.7-1.9z"/><text x="22" y="14.5" font-family="Helvetica Neue, Helvetica, Arial, sans-serif" font-weight="500" font-size="9" fill="#fff">Pay</text></svg></li>
                            <li class="lg-pm lg-pm--google" title="Google Pay"><svg viewBox="0 0 60 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M9.4 11.4v3.5h-1.1V6.3h2.9c.7 0 1.4.3 1.9.8.5.5.8 1.2.8 1.9 0 .7-.3 1.4-.8 1.9-.5.5-1.1.8-1.9.8h-1.8zm0-4.1v3.1h1.9c.4 0 .8-.2 1.1-.5.6-.6.6-1.5 0-2.1-.3-.3-.7-.5-1.1-.5h-1.9zm6.4 1.2c.8 0 1.5.2 2 .7.5.4.7 1 .7 1.8v3.6h-1.1v-.8h-.1c-.5.7-1.1 1-1.8 1-.6 0-1.1-.2-1.5-.6-.4-.3-.6-.8-.6-1.3 0-.6.2-1 .6-1.4.4-.3 1-.5 1.7-.5.6 0 1.1.1 1.5.3v-.3c0-.4-.1-.7-.4-1-.3-.3-.6-.4-1-.4-.6 0-1.1.3-1.5.8l-1-.6c.5-.8 1.3-1.3 2.5-1.3zm-1.5 4.4c0 .2.1.4.3.6.2.2.4.2.7.2.4 0 .8-.1 1.1-.4.3-.3.5-.7.5-1.1-.3-.3-.8-.4-1.4-.4-.4 0-.8.1-1 .3-.2.2-.2.5-.2.8zm10.4-4.2L21.1 17h-1.2l1.4-3-2.4-5.3h1.2l1.7 4.1 1.7-4.1h1.2z" fill="#5F6368"/><path d="M33 10.7c0-.3 0-.6-.1-.9h-4.5v1.7h2.6c-.1.6-.4 1.1-.9 1.4v1.2h1.5c.9-.8 1.4-2 1.4-3.4z" fill="#4285F4"/><path d="M28.4 15.5c1.3 0 2.4-.4 3.2-1.2l-1.5-1.2c-.4.3-1 .5-1.7.5-1.3 0-2.4-.9-2.8-2h-1.6v1.2c.8 1.6 2.5 2.7 4.4 2.7z" fill="#34A853"/><path d="M25.6 11.6c-.2-.6-.2-1.3 0-1.9V8.5H24c-.7 1.3-.7 2.9 0 4.3l1.6-1.2z" fill="#FBBC04"/><path d="M28.4 7.6c.7 0 1.4.3 1.9.7l1.4-1.4c-.9-.8-2.1-1.3-3.3-1.2-1.9 0-3.6 1.1-4.4 2.7l1.6 1.2c.4-1.1 1.4-2 2.8-2z" fill="#EA4335"/><text x="36" y="14.5" font-family="Helvetica Neue, Helvetica, Arial, sans-serif" font-weight="500" font-size="9" fill="#5F6368">Pay</text></svg></li>
                        </ul>
                    </div>
                    </div>
                </div>
            </div>

            <div class="lg-pay-modal" data-lg-join-checkout-modal hidden role="dialog" aria-modal="true" aria-label="Secure checkout">
                <div class="lg-pay-modal__backdrop" data-lg-join-checkout-close></div>
                <div class="lg-pay-modal__card">
                    <button type="button" class="lg-pay-modal__close" data-lg-join-checkout-close aria-label="Close checkout">&times;</button>

                    <!-- Embedded path (one-time + regional setup): Stripe owns the iframe + Pay button. -->
                    <div class="lg-pay-modal__body" data-lg-join-checkout></div>

                    <!-- In-modal processing overlay -->
                    <div class="lg-modal-processing" data-lg-join-modal-processing hidden aria-hidden="true">
                        <div class="lg-modal-processing__spinner" aria-hidden="true"></div>
                        <p class="lg-modal-processing__label">Processing payment&hellip;</p>
                    </div>

                    <!-- Custom path (subscription): we mount Stripe Elements ourselves and render our own Pay button. -->
                    <div class="lg-pay-modal__custom lg-stripe-modal" data-lg-join-checkout-custom hidden>
                        <div class="lg-stripe-modal__header">
                            <div class="lg-stripe-modal__heading">Complete your purchase</div>
                            <div class="lg-stripe-modal__amount" data-lg-pay-amount>&nbsp;</div>
                            <div class="lg-stripe-modal__sublabel" data-lg-pay-sublabel>&nbsp;</div>
                        </div>
                        <div class="lg-stripe-modal__pe" data-lg-join-payment-element></div>
                        <div class="lg-stripe-modal__error" data-lg-join-checkout-error role="alert" hidden></div>
                        <button type="button" class="lg-stripe-modal__pay" data-lg-join-checkout-pay disabled>
                            <span data-lg-pay-label>Pay</span>
                        </button>
                        <p class="lg-stripe-modal__secured">
                            <svg class="lg-stripe-modal__secured-lock" xmlns="http://www.w3.org/2000/svg" width="11" height="13" viewBox="0 0 11 13" aria-hidden="true"><path fill="currentColor" d="M5.5 0C3.567 0 2 1.567 2 3.5V5h-.5A1.5 1.5 0 0 0 0 6.5v5A1.5 1.5 0 0 0 1.5 13h8a1.5 1.5 0 0 0 1.5-1.5v-5A1.5 1.5 0 0 0 9.5 5H9V3.5C9 1.567 7.433 0 5.5 0Zm0 1.5A2 2 0 0 1 7.5 3.5V5h-4V3.5a2 2 0 0 1 2-2Z"/></svg>
                            <span>Powered by</span>
                            <svg class="lg-stripe-modal__secured-mark" xmlns="http://www.w3.org/2000/svg" width="42" height="18" viewBox="0 0 60 25" aria-hidden="true" role="img" aria-label="Stripe"><path fill="#635BFF" d="M59.64 14.28h-8.06c.19 1.93 1.6 2.55 3.2 2.55 1.64 0 2.96-.37 4.05-.95v3.32a8.33 8.33 0 0 1-4.56 1.1c-4.01 0-6.83-2.5-6.83-7.48 0-4.19 2.39-7.52 6.3-7.52 3.92 0 5.96 3.28 5.96 7.5 0 .4-.04 1.26-.06 1.48zm-5.92-5.62c-1.03 0-2.17.73-2.17 2.58h4.25c0-1.85-1.07-2.58-2.08-2.58zM40.95 20.3c-1.44 0-2.32-.6-2.9-1.04l-.02 4.63-4.12.87V5.57h3.76l.08 1.02a4.7 4.7 0 0 1 3.23-1.29c2.9 0 5.62 2.6 5.62 7.4 0 5.23-2.7 7.6-5.65 7.6zM40 9.04c-.95 0-1.54.34-1.97.81l.02 6.12c.4.44.98.78 1.95.78 1.52 0 2.54-1.65 2.54-3.87 0-2.15-1.04-3.84-2.54-3.84zM28.24 5.57h4.13v14.44h-4.13V5.57zm0-4.7L32.37 0v3.36l-4.13.88V.88zm-4.32 9.35v9.79H19.8V5.57h3.7l.12 1.22c1-1.77 3.07-1.41 3.62-1.22v3.79c-.52-.17-2.29-.43-3.32.86zm-8.55 4.72c0 2.43 2.6 1.68 3.12 1.46v3.36c-.55.3-1.54.54-2.89.54a4.15 4.15 0 0 1-4.27-4.24l.01-13.17 4.02-.86v3.54h3.14V9.1h-3.14l.01 5.85zm-4.91.7c0 2.97-2.31 4.66-5.73 4.66a11.2 11.2 0 0 1-4.46-.93v-3.93c1.38.75 3.1 1.31 4.46 1.31.92 0 1.53-.24 1.53-1C6.26 13.77 0 14.51 0 9.95 0 7.04 2.28 5.3 5.62 5.3c1.36 0 2.72.2 4.09.75v3.88a9.23 9.23 0 0 0-4.1-1.06c-.86 0-1.44.25-1.44.93 0 1.85 6.29.97 6.29 5.83z"/></svg>
                        </p>
                    </div>
                </div>
            </div>

            <div class="lg-processing" data-lg-join-processing hidden role="status" aria-live="polite" aria-label="Processing payment">
                <div class="lg-processing__card">
                    <div class="lg-processing__spinner" aria-hidden="true"></div>
                    <h3 class="lg-processing__title">Still processing your payment&hellip;</h3>
                    <p class="lg-processing__body">Your payment went through. We&rsquo;re finalizing your subscription &mdash; please don&rsquo;t close this tab. You&rsquo;ll be redirected in a moment.</p>
                </div>
            </div>

            <!-- Existing-account modal: fired when /gift-auth says incorrect password
                 for an email that already has a WP user. -->
            <div class="lg-existacct" data-lg-existacct-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-existacct-title-join">
                <div class="lg-existacct__backdrop" data-lg-existacct-cancel></div>
                <div class="lg-existacct__card">
                    <button type="button" class="lg-existacct__close" data-lg-existacct-cancel aria-label="Close">&times;</button>
                    <h3 id="lg-existacct-title-join" class="lg-existacct__title">This email already has an account</h3>
                    <p class="lg-existacct__body" data-lg-existacct-body></p>
                    <div class="lg-existacct__actions">
                        <a class="lg-existacct__btn lg-existacct__btn--primary" data-lg-existacct-login href="#">Log in &amp; manage subscription</a>
                        <a class="lg-existacct__btn lg-existacct__btn--ghost"   data-lg-existacct-forgot hidden href="#">Forgot your password?</a>
                    </div>
                    <p class="lg-existacct__alt"><a href="#" data-lg-existacct-cancel>Use a different email instead</a></p>
                </div>
            </div>

            <style>
                .lg-existacct { position: fixed !important; inset: 0 !important; z-index: 2147483600 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; }
                .lg-existacct[hidden] { display: none !important; }
                .lg-existacct__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.6); }
                .lg-existacct__card { position: relative; background: #fff; border-radius: 12px; padding: 1.7em 1.6em; max-width: 460px; width: 100%; box-shadow: 0 16px 50px rgba(0,0,0,0.4); color: #1f1d1a; }
                .lg-existacct__close { position: absolute; top: .55em; right: .55em; width: 2em; height: 2em; padding: 0; background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 50%; font-size: 1.35em; line-height: 1; cursor: pointer; color: #444; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
                .lg-existacct__close:hover { color: #000; background: #f5f5f5; }
                .lg-existacct__title { margin: 0 0 .55em; font-size: 1.2em; font-weight: 700; line-height: 1.3; padding-right: 2em; }
                .lg-existacct__body { margin: 0 0 1.2em; font-size: .95em; line-height: 1.5; color: #333; }
                .lg-existacct__actions { display: flex; gap: .65em; flex-wrap: wrap; }
                .lg-existacct__btn { padding: .65em 1.2em; border-radius: 6px; font-weight: 600; font-size: .92em; text-decoration: none; display: inline-block; cursor: pointer; }
                .lg-existacct__btn--primary { background: var(--lg-amber, #ECB351); color: #1f1d1a !important; }
                .lg-existacct__btn--primary:hover { opacity: .88; }
                .lg-existacct__btn--ghost { background: transparent; border: 1.5px solid rgba(0,0,0,0.2); color: #1f1d1a !important; }
                .lg-existacct__btn--ghost:hover { background: rgba(0,0,0,0.04); }
                .lg-existacct__alt { margin: 1em 0 0; font-size: .85em; color: #666; }
                body.lg-modal-open { overflow: hidden !important; }
            </style>

            <style>
                .lg-giftwarn { position: fixed !important; inset: 0 !important; z-index: 2147483600 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; }
                .lg-giftwarn[hidden] { display: none !important; }
                .lg-giftwarn__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.6); }
                .lg-giftwarn__card { position: relative; background: #fff; border-radius: 12px; padding: 1.6em 1.5em; max-width: 460px; width: 100%; box-shadow: 0 16px 50px rgba(0,0,0,0.4); color: #1f1d1a; }
                .lg-giftwarn__close { position: absolute; top: .55em; right: .55em; width: 2em; height: 2em; padding: 0; background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 50%; font-size: 1.35em; line-height: 1; cursor: pointer; color: #444; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
                .lg-giftwarn__close:hover { color: #000; background: #f5f5f5; }
                .lg-giftwarn__title { margin: 0 0 .55em; font-size: 1.2em; font-weight: 700; line-height: 1.3; padding-right: 2em; }
                .lg-giftwarn__body { margin: 0 0 1em; font-size: .95em; line-height: 1.5; color: #333; }
                .lg-giftwarn__check { display: flex; align-items: flex-start; gap: .6em; padding: .8em .9em; margin-bottom: 1.1em; background: rgba(255,200,80,0.14); border: 1px solid rgba(255,180,40,0.45); border-radius: 6px; cursor: pointer; font-size: .92em; line-height: 1.4; font-weight: 500; }
                .lg-giftwarn__check input { margin-top: .15em; flex-shrink: 0; }
                .lg-giftwarn__actions { display: flex; gap: .65em; justify-content: flex-end; flex-wrap: wrap; }
                .lg-giftwarn__btn { padding: .6em 1.15em; border-radius: 6px; font-weight: 600; font-size: .92em; cursor: pointer; border: none; transition: opacity .15s, background .15s; }
                .lg-giftwarn__btn--ghost { background: transparent; border: 1.5px solid rgba(0,0,0,0.2); color: #1f1d1a; }
                .lg-giftwarn__btn--ghost:hover { background: rgba(0,0,0,0.04); }
                .lg-giftwarn__btn--primary { background: var(--lg-amber, #ECB351); color: #1f1d1a; }
                .lg-giftwarn__btn--primary:hover { opacity: .88; }
                .lg-giftwarn__btn:disabled { opacity: .45; cursor: not-allowed; }

                .lg-join__field label { display: block; }
                .lg-join__field input[type=email],
                .lg-join__field input[type=text],
                .lg-join__field input[type=password] { width: 100%; box-sizing: border-box; }

                .lg-pwd-wrap { position: relative; display: block; }
                .lg-pwd-wrap input { width: 100%; padding-right: 2.6em !important; box-sizing: border-box; }
                .lg-pwd-eye {
                    position: absolute; right: .35em; top: 50%; transform: translateY(-50%);
                    width: 1.9em; height: 1.9em; padding: 0;
                    background: transparent; border: none; cursor: pointer;
                    color: rgba(0,0,0,0.55);
                    display: flex; align-items: center; justify-content: center;
                    transition: color .15s;
                }
                .lg-pwd-eye:hover { color: rgba(0,0,0,0.85); }
                .lg-pwd-eye:focus-visible { outline: 2px solid var(--lg-amber, #ECB351); outline-offset: 2px; border-radius: 4px; }
                .lg-pwd-eye__icon { width: 1.15em; height: 1.15em; display: block; }
                .lg-pwd-mismatch { color: #b91c1c !important; font-size: .85em; margin-top: .3em; }

                .lg-pay-modal__card--signup { max-width: 620px !important; }
                .lg-pay-modal__body--signup { padding: 1.8em 1.7em 1.4em !important; }
                .lg-pay-modal__pay-methods {
                    margin: 1.2em -1.7em -1.4em;
                    padding: .9em 1.7em !important;
                    border-top: 1px solid rgba(0,0,0,0.08);
                    background: rgba(0,0,0,0.025);
                    justify-content: center !important;
                    display: flex !important;
                    align-items: center !important;
                    gap: .7em !important;
                    flex-wrap: wrap !important;
                }

                .lg-processing { position: fixed !important; inset: 0 !important; z-index: 2147483647 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; background: rgba(0,0,0,0.85); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
                .lg-processing[hidden] { display: none !important; }
                .lg-processing__card { background: #fff; border-radius: 12px; padding: 2em 1.8em; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,0.5); color: #1f1d1a; }
                .lg-processing__spinner { width: 44px; height: 44px; margin: 0 auto 1em; border: 4px solid rgba(0,0,0,0.1); border-top-color: var(--lg-amber, #ECB351); border-radius: 50%; animation: lg-processing-spin 0.9s linear infinite; }
                .lg-processing__title { margin: 0 0 .6em; font-size: 1.15em; font-weight: 700; }
                .lg-processing__body  { margin: 0; font-size: .92em; line-height: 1.5; color: #444; }
                @keyframes lg-processing-spin { to { transform: rotate(360deg); } }
            </style>
            <div class="lg-giftwarn" data-lg-giftwarn-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-giftwarn-title">
                <div class="lg-giftwarn__backdrop" data-lg-giftwarn-cancel></div>
                <div class="lg-giftwarn__card">
                    <button type="button" class="lg-giftwarn__close" data-lg-giftwarn-cancel aria-label="Close">&times;</button>
                    <h3 id="lg-giftwarn-title" class="lg-giftwarn__title">You're already covered by a gift</h3>
                    <p class="lg-giftwarn__body" data-lg-giftwarn-body></p>
                    <div class="lg-giftwarn__actions">
                        <button type="button" class="lg-giftwarn__btn lg-giftwarn__btn--ghost" data-lg-giftwarn-cancel>Maybe later</button>
                        <button type="button" class="lg-giftwarn__btn lg-giftwarn__btn--primary" data-lg-giftwarn-confirm>Set up subscription</button>
                    </div>
                </div>
            </div>

            <div class="lg-join__error" data-lg-join-error aria-live="polite"></div>
        </div>

        <!-- Basil release of Stripe.js — required for stripe.initCheckout()
             (custom UI mode for subscription path). -->
        <script src="https://js.stripe.com/basil/stripe.js"></script>
        <script>
        (function(){
            function lgGetRef() { try { return localStorage.getItem('lg_ref') || ''; } catch(_) { return ''; } }

            const ENDPOINTS = <?php echo $endpointsJs; ?>;
            const PROMO     = <?php echo json_encode( $promoFromUrl ); ?>;

            // Capture ?ref= affiliate slug on landing, persist, fire a click event.
            (function() {
                try {
                    var r = new URLSearchParams(window.location.search).get('ref');
                    if (r) {
                        r = r.replace(/[^a-z0-9_-]/gi, '').slice(0, 80);
                        localStorage.setItem('lg_ref', r);
                        fetch(ENDPOINTS.affiliateClick, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ ref: r }),
                            keepalive: true,
                        }).catch(function(){});
                    }
                } catch(_) {}
            })();
            const COUNTRY_OVERRIDE = <?php echo json_encode( $countryFromUrl ); ?>;
            const CONFIG    = <?php echo $configJs; ?>;
            // Resolved at runtime: URL override > Cloudflare trace > none.
            let DETECTED_COUNTRY = COUNTRY_OVERRIDE || '';

            async function detectCountry(){
                if (DETECTED_COUNTRY) return DETECTED_COUNTRY;
                try {
                    const res = await fetch('/cdn-cgi/trace', { cache: 'no-store' });
                    if (res.ok) {
                        const text = await res.text();
                        const m = text.match(/^loc=([A-Z]{2})$/m);
                        if (m && m[1] !== 'XX' && m[1] !== 'T1') {
                            DETECTED_COUNTRY = m[1];
                            return DETECTED_COUNTRY;
                        }
                    }
                } catch (_) { /* fall through to ipapi */ }
                try {
                    const res = await fetch('https://ipapi.co/json/', { cache: 'no-store' });
                    if (res.ok) {
                        const data = await res.json();
                        const cc = (data && data.country_code) ? String(data.country_code).toUpperCase() : '';
                        if (cc.length === 2 && cc !== 'XX') {
                            DETECTED_COUNTRY = cc;
                        }
                    }
                } catch (_) { /* offline; give up silently */ }
                return DETECTED_COUNTRY;
            }

            const FX = {
                IN:{c:'INR',s:'₹',  r:83},   BR:{c:'BRL',s:'R$', r:5},
                MX:{c:'MXN',s:'MX$',r:17},   NG:{c:'NGN',s:'₦',  r:1500},
                PH:{c:'PHP',s:'₱',  r:56},   ID:{c:'IDR',s:'Rp', r:16000},
                PK:{c:'PKR',s:'₨',  r:280},  BD:{c:'BDT',s:'৳',  r:110},
                VN:{c:'VND',s:'₫',  r:24000},EG:{c:'EGP',s:'E£', r:50},
                KE:{c:'KES',s:'KSh',r:130},  GH:{c:'GHS',s:'GH₵',r:12},
                ET:{c:'ETB',s:'Br', r:115},  TZ:{c:'TZS',s:'TSh',r:2700},
                UG:{c:'UGX',s:'USh',r:3700}, MM:{c:'MMK',s:'K',  r:2100},
                KH:{c:'KHR',s:'៛',  r:4100}, TR:{c:'TRY',s:'₺',  r:32},
                AR:{c:'ARS',s:'AR$',r:1000}, CO:{c:'COP',s:'COL$',r:4000},
                PE:{c:'PEN',s:'S/', r:3.7},  ZA:{c:'ZAR',s:'R',  r:18},
                UA:{c:'UAH',s:'₴',  r:38},   PL:{c:'PLN',s:'zł', r:4},
                RO:{c:'RON',s:'lei',r:4.6},  TH:{c:'THB',s:'฿',  r:36},
                MY:{c:'MYR',s:'RM', r:4.7},  CL:{c:'CLP',s:'CLP$',r:950},
                MA:{c:'MAD',s:'MAD',r:10},   JO:{c:'JOD',s:'JD', r:0.71},
            };

            function roundLocal(n){
                if (n < 10)     return Math.round(n*10)/10;
                if (n < 100)    return Math.round(n);
                if (n < 1000)   return Math.round(n/5)*5;
                if (n < 10000)  return Math.round(n/50)*50;
                if (n < 100000) return Math.round(n/500)*500;
                return Math.round(n/1000)*1000;
            }

            function localHint(usdCents){
                if (!DETECTED_COUNTRY || !FX[DETECTED_COUNTRY]) return '';
                const fx = FX[DETECTED_COUNTRY];
                const local = roundLocal((usdCents / 100) * fx.r);
                return ' (≈ ' + fx.s + local.toLocaleString() + ')';
            }

            const tiersEl      = document.querySelector('[data-lg-join-tiers]');
            const signupModal  = document.querySelector('[data-lg-signup-modal]');
            const formEl       = document.querySelector('[data-lg-join-form]');
            const formHeadEl   = document.querySelector('[data-lg-form-heading]');

            if (signupModal && signupModal.parentNode !== document.body) {
                document.body.appendChild(signupModal);
            }

            function openSignupModal() {
                if (signupModal) { signupModal.hidden = false; document.body.classList.add('lg-modal-open'); }
            }
            function closeSignupModal() {
                if (signupModal) { signupModal.hidden = true; document.body.classList.remove('lg-modal-open'); }
            }
            document.querySelectorAll('[data-lg-signup-close]').forEach(el => {
                el.addEventListener('click', () => { teardownCheckoutMount(); closeSignupModal(); pendingPriceId = null; document.querySelectorAll('.lg-join__tier.is-selected, .lg-join__buy.is-selected, .lg-join__trial-btn.is-selected').forEach(e => e.classList.remove('is-selected')); });
            });
            const continueBt = document.querySelector('[data-lg-continue]');
            const backBt     = document.querySelector('[data-lg-back]');
            const checkoutEl = document.querySelector('[data-lg-join-checkout]');
            const customEl   = document.querySelector('[data-lg-join-checkout-custom]');
            const peEl       = document.querySelector('[data-lg-join-payment-element]');
            const payBt      = document.querySelector('[data-lg-join-checkout-pay]');
            const payLabelEl = document.querySelector('[data-lg-pay-label]');
            const customErrorEl = document.querySelector('[data-lg-join-checkout-error]');
            const modalProcessingEl = document.querySelector('[data-lg-join-modal-processing]');
            const payAmountEl   = document.querySelector('[data-lg-pay-amount]');
            const paySublabelEl = document.querySelector('[data-lg-pay-sublabel]');
            const errorEl    = document.querySelector('[data-lg-join-error]');
            const existAcctModal   = document.querySelector('[data-lg-existacct-modal]');
            const existAcctBody    = document.querySelector('[data-lg-existacct-body]');
            const existAcctLogin   = document.querySelector('[data-lg-existacct-login]');
            const existAcctForgot  = document.querySelector('[data-lg-existacct-forgot]');
            if (existAcctModal && existAcctModal.parentNode !== document.body) document.body.appendChild(existAcctModal);

            function showExistingAccountModal(opts) {
                const target   = '<?php echo $jsManage; ?>';
                const loginUrl = '<?php echo $jsLogin; ?>'
                               + '?redirect_to=' + encodeURIComponent(target);
                if (existAcctBody) {
                    existAcctBody.innerHTML =
                        '<strong>' + opts.email + '</strong> already has an account. Log in and we' + String.fromCharCode(39) + 'll take you straight to your subscription dashboard where you can change plan, update your card, or cancel.';
                }
                if (existAcctLogin)  existAcctLogin.href  = loginUrl;
                if (existAcctForgot) {
                    existAcctForgot.href = '<?php echo $jsForgot; ?>';
                    existAcctForgot.hidden = !opts.forgot;
                }
                if (existAcctModal) existAcctModal.hidden = false;
                document.body.classList.add('lg-modal-open');
            }
            function hideExistingAccountModal() {
                if (existAcctModal) existAcctModal.hidden = true;
                document.body.classList.remove('lg-modal-open');
            }
            document.querySelectorAll('[data-lg-existacct-cancel]').forEach(el => {
                el.addEventListener('click', (e) => { e.preventDefault(); hideExistingAccountModal(); });
            });

            document.querySelectorAll('[data-lg-pwd-eye-for]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const target = document.querySelector('input[name="' + btn.dataset.lgPwdEyeFor + '"]');
                    if (!target) return;
                    target.type = (target.type === 'password') ? 'text' : 'password';
                    btn.setAttribute('aria-label', target.type === 'password' ? 'Show password' : 'Hide password');
                });
            });

            const pwdEl     = document.querySelector('input[name="password"]');
            const pwd2El    = document.querySelector('input[name="password_confirm"]');
            const mismatchEl= document.querySelector('[data-lg-pwd-mismatch]');
            function checkPwdMatch() {
                if (!pwdEl || !pwd2El || !mismatchEl) return;
                const a = pwdEl.value, b = pwd2El.value;
                mismatchEl.hidden = !(a.length > 0 && b.length > 0 && a !== b);
            }
            if (pwdEl)  pwdEl.addEventListener('input',  checkPwdMatch);
            if (pwd2El) pwd2El.addEventListener('input', checkPwdMatch);

            const emailInput = document.querySelector('input[name="email"]');
            const emailConfirmInput = document.querySelector('input[name="email_confirm"]');
            const emailMismatchEl   = document.querySelector('[data-lg-email-mismatch]');
            const nameInput  = document.querySelector('input[name="name"]');

            function checkEmailMatch() {
                if (!emailInput || !emailConfirmInput || !emailMismatchEl) return;
                const a = (emailInput.value || '').trim().toLowerCase();
                const b = (emailConfirmInput.value || '').trim().toLowerCase();
                emailMismatchEl.hidden = !(a.length > 0 && b.length > 0 && a !== b);
            }
            if (emailInput)        emailInput.addEventListener('input',        checkEmailMatch);
            if (emailConfirmInput) emailConfirmInput.addEventListener('input', checkEmailMatch);

            let stripe         = null;
            let mountedSession = null;
            let customCheckout = null;
            let paymentElement = null;
            let pendingPriceId = null;
            let pendingLabel   = '';

            function showError(msg){ errorEl.textContent = msg || ''; }

            function dollars(cents){
                return '$' + (cents / 100).toFixed(cents % 100 === 0 ? 0 : 2);
            }

            function priceLabel(price){
                const hint = localHint(price.unit_amount_cents);
                if (price.type === 'recurring' && price.interval === 'month') {
                    return 'Subscribe — ' + dollars(price.unit_amount_cents) + '/month' + hint;
                }
                if (price.type === 'recurring' && price.interval === 'year') {
                    return 'Subscribe — ' + dollars(price.unit_amount_cents) + '/year' + hint;
                }
                if (price.type === 'one_time') {
                    return 'Pay once — ' + dollars(price.unit_amount_cents) + ' / year' + hint;
                }
                return dollars(price.unit_amount_cents) + hint;
            }

            function sortPrices(prices){
                const order = (p) => {
                    if (p.type === 'recurring' && p.interval === 'month') return 0;
                    if (p.type === 'recurring' && p.interval === 'year')  return 1;
                    if (p.type === 'one_time')                            return 2;
                    return 99;
                };
                return [...prices].sort((a, b) => order(a) - order(b));
            }

            // Render plans IMMEDIATELY with whatever country we already know
            // synchronously (URL ?country= override only). Geolocation
            // (detectCountry → /cdn-cgi/trace + ipapi.co) used to be awaited
            // BEFORE the products fetch, adding ~0.7–2.5s before any plan
            // appeared. Now it runs in the background and we re-fetch only if it
            // turns up a different country with actual regional pricing.
            async function loadProducts(){
                showError('');
                await fetchAndRenderProducts(DETECTED_COUNTRY);
                if (!COUNTRY_OVERRIDE) {
                    detectCountry().then(function (cc) {
                        if (cc && cc !== '') fetchAndRenderProducts(cc);
                    }).catch(function () {});
                }
            }

            async function fetchAndRenderProducts(country){
                try {
                    const url  = ENDPOINTS.products + (country ? '?country=' + encodeURIComponent(country) : '');
                    const res  = await fetch(url);
                    const json = await res.json();
                    if (!json.products || json.products.length === 0) {
                        if (!tiersEl.querySelector('.lg-join__tier')) tiersEl.innerHTML = '<p>No memberships available right now.</p>';
                        return;
                    }
                    const hasRegional = json.products.some(p => (p.prices || []).some(pr => pr.region_tag));
                    if (hasRegional && json.detected_country) {
                        const noteEl = document.querySelector('[data-lg-region-note]');
                        if (noteEl) {
                            const fxNote = (DETECTED_COUNTRY && FX[DETECTED_COUNTRY])
                                ? ' Local-currency figures are approximate; Stripe bills in USD and your bank applies the exchange rate at the time of payment.'
                                : '';
                            noteEl.innerHTML = '<strong>Regional pricing</strong> applied for ' + json.detected_country + '.' + fxNote;
                            noteEl.hidden = false;
                        }
                    }
                    renderTiers(json.products);
                } catch (err) {
                    // Only surface the error if nothing has rendered yet (the
                    // background re-fetch failing must not blow away shown plans).
                    if (!tiersEl.querySelector('.lg-join__tier')) showError('Failed to load memberships: ' + err.message);
                }
            }

            function buildTierCard(prod, prices, isPopular, isMock) {
                const card = document.createElement('div');
                card.className = 'lg-join__tier';
                if (isPopular) {
                    card.classList.add('is-popular');
                    const badge = document.createElement('span');
                    badge.className = 'lg-join__tier-badge';
                    badge.textContent = 'Most popular';
                    card.appendChild(badge);
                }

                const title = document.createElement('h3');
                title.className = 'lg-join__tier-name';
                title.textContent = prod.name;
                card.appendChild(title);

                const tagline = document.createElement('p');
                tagline.className = 'lg-join__tier-tagline';
                tagline.textContent = (CONFIG.taglines && CONFIG.taglines[prod.ref]) || '';
                card.appendChild(tagline);

                const feats = (CONFIG.features && CONFIG.features[prod.ref]) || prod.features || [];
                if (feats.length > 0) {
                    const ul = document.createElement('ul');
                    ul.className = 'lg-join__features';
                    feats.forEach(function(f){
                        const li = document.createElement('li');
                        li.className = 'lg-join__feature';
                        li.textContent = f;
                        ul.appendChild(li);
                    });
                    card.appendChild(ul);
                }

                const list = document.createElement('div');
                list.className = 'lg-join__tier-prices';
                prices.forEach(function(price){
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'lg-join__buy';
                    if (price.type === 'recurring' && price.interval === 'year') btn.classList.add('is-primary');
                    btn.textContent = priceLabel(price);
                    if (isMock) {
                        btn.disabled = true;
                        btn.title = 'Preview only — not a live price';
                    } else {
                        btn.addEventListener('click', () => selectPrice(price, prod, btn));
                    }
                    list.appendChild(btn);
                });
                card.appendChild(list);


                return card;
            }

            function renderTiers(products){
                tiersEl.innerHTML = '';

                const anyTrial = products.some(p => p.prices.some(pr => (pr.trial_days || 0) > 0));
                const trialBanner = document.querySelector('[data-lg-trial-banner]');
                if (trialBanner) trialBanner.hidden = !anyTrial;

                if (CONFIG.previewSingle) {
                    renderSingleTierPreview();
                    return;
                }

                products.forEach(function(prod){
                    const recurringOnly = (prod.prices || []).filter(p => p.type !== 'one_time');
                    const sorted    = sortPrices(recurringOnly);
                    const isPopular = (prod.ref && CONFIG.popular && prod.ref === CONFIG.popular);
                    const card      = buildTierCard(prod, sorted, isPopular, false);
                    tiersEl.appendChild(card);

                    if (isPopular) {
                        const yearlyBtn = card.querySelector('.lg-join__buy.is-primary');
                        if (yearlyBtn) {
                            setTimeout(() => {
                                yearlyBtn.classList.add('is-pulsing');
                                yearlyBtn.addEventListener('animationend', () => yearlyBtn.classList.remove('is-pulsing'), { once: true });
                            }, 800);
                            yearlyBtn.addEventListener('click', () => yearlyBtn.classList.remove('is-pulsing'), { once: true });
                        }
                    }
                });
            }

            function renderSingleTierPreview(){
                const mockProd = {
                    name: 'Looth Group',
                    ref:  'single',
                    features: ['Member forums & community', 'Interviews & AMAs', 'Demo-based content', 'Live session archives'],
                };
                const mockPrices = [
                    { stripe_price_id: 'mock_month', type: 'recurring', interval: 'month', unit_amount_cents: 800,  trial_days: 7 },
                    { stripe_price_id: 'mock_year',  type: 'recurring', interval: 'year',  unit_amount_cents: 8000, trial_days: 7 },
                    { stripe_price_id: 'mock_once',  type: 'one_time',  interval: null,    unit_amount_cents: 9600 },
                ];
                const card = buildTierCard(mockProd, mockPrices, false, true);
                card.style.maxWidth = '420px';
                card.style.margin   = '0 auto';
                tiersEl.appendChild(card);
            }

            function selectPrice(price, prod, btn, opts){
                opts = opts || {};
                showError('');
                pendingPriceId = price.stripe_price_id;
                pendingLabel   = opts.label || priceLabel(price);

                document.querySelectorAll('.lg-join__tier').forEach(c => c.classList.remove('is-selected'));
                const card = btn.closest('.lg-join__tier');
                if (card) card.classList.add('is-selected');

                document.querySelectorAll('.lg-join__buy.is-selected, .lg-join__trial-btn.is-selected').forEach(b => b.classList.remove('is-selected'));
                btn.classList.add('is-selected');

                const displayLabel = pendingLabel.replace(/^Subscribe\s*—\s*|^Pay once\s*—\s*/, '');
                formHeadEl.textContent = 'Continue to ' + prod.name + ' — ' + displayLabel;
                teardownCheckoutMount();
                if (!opts.silent) {
                    openSignupModal();
                    setTimeout(function(){
                        if (!emailInput.value.trim()) emailInput.focus();
                        else continueBt.focus();
                    }, 80);
                }
            }

            async function startCheckout(){
                showError('');
                const email = (emailInput.value || '').trim();
                if (!email) { showError('Email is required.'); emailInput.focus(); return; }
                if (emailConfirmInput) {
                    const emailConfirm = (emailConfirmInput.value || '').trim();
                    if (emailConfirm.toLowerCase() !== email.toLowerCase()) {
                        showError('The two email fields must match.');
                        emailConfirmInput.focus();
                        return;
                    }
                }
                if (!pendingPriceId) { showError('Pick a plan first.'); return; }

                const name = (nameInput.value || '').trim();
                if (!name) { showError('Profile name is required.'); nameInput.focus(); return; }

                if (!CONFIG.loggedIn && !window.__lgJoinAuthed) {
                    const passwordEl = document.querySelector('input[name="password"]');
                    const password   = passwordEl ? passwordEl.value : '';
                    if (!password || password.length < 8) {
                        showError('Please enter a password (8+ characters).');
                        if (passwordEl) passwordEl.focus();
                        return;
                    }
                    const passwordConfirmEl = document.querySelector('input[name="password_confirm"]');
                    if (passwordConfirmEl && passwordConfirmEl.value !== password) {
                        showError('Passwords don' + String.fromCharCode(39) + 't match.');
                        passwordConfirmEl.focus();
                        return;
                    }
                    continueBt.disabled = true;
                    const origAuth = continueBt.textContent;
                    continueBt.textContent = 'Signing in…';
                    try {
                        const authRes = await fetch(CONFIG.authUrl, {
                            method:  'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body:    JSON.stringify({
                                email, password,
                                display_name:      name,
                                confirmed_consent: true,
                            }),
                        });
                        const authData = await authRes.json();
                        if (!authData.ok) {
                            showExistingAccountModal({
                                email,
                                error:  authData.error || 'Sign-in failed.',
                                forgot: !!authData.forgot,
                            });
                            continueBt.disabled    = false;
                            continueBt.textContent = origAuth;
                            return;
                        }
                        window.__lgJoinAuthed = true;
                    } catch (e) {
                        showError('Network error during sign-in. Please try again.');
                        continueBt.disabled    = false;
                        continueBt.textContent = origAuth;
                        return;
                    }
                    continueBt.textContent = origAuth;
                }

                teardownCheckoutMount();
                continueBt.disabled = true;
                const orig = continueBt.textContent;
                continueBt.textContent = 'Loading…';

                try {
                    const body = {
                        price_id: pendingPriceId,
                        email:    email,
                        name:     (nameInput.value || '').trim(),
                    };
                    if (window.__lgAckActiveGift) {
                        body.acknowledged_active_gift = true;
                    }
                    const promoInput = document.querySelector('input[name="promo_code"]');
                    const typedPromo = promoInput ? (promoInput.value || '').trim() : '';
                    const finalPromo = typedPromo !== '' ? typedPromo : (PROMO || '');
                    if (finalPromo) body.promo_code = finalPromo;
                    if (DETECTED_COUNTRY) body.country = DETECTED_COUNTRY;
                    const lgRef = lgGetRef();
                    if (lgRef) body.ref = lgRef;

                    const sessRes  = await fetch(ENDPOINTS.checkout, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify(body),
                    });
                    const sessData = await sessRes.json();
                    if (sessData.needs_gift_confirmation) {
                        showGiftWarnModal(sessData.active_gift || {});
                        return;
                    }
                    if (!sessData.clientSecret) {
                        showError(sessData.error || 'Could not start checkout.');
                        return;
                    }

                    if (!stripe) {
                        const cfg = await (await fetch(ENDPOINTS.config)).json();
                        if (!cfg.publishableKey) { showError('Stripe not configured.'); return; }
                        stripe = Stripe(cfg.publishableKey);
                    }

                    const uiMode = sessData.ui_mode || 'embedded';
                    if (uiMode === 'custom') {
                        await mountCustomCheckout(sessData.clientSecret);
                    } else {
                        await mountEmbeddedCheckout(sessData.clientSecret);
                    }
                } catch (err) {
                    showError('Network error: ' + err.message);
                } finally {
                    continueBt.disabled    = false;
                    continueBt.textContent = orig;
                }
            }

            const giftWarnModal   = document.querySelector('[data-lg-giftwarn-modal]');
            const giftWarnBody    = document.querySelector('[data-lg-giftwarn-body]');
            const giftWarnCheck   = document.querySelector('[data-lg-giftwarn-confirm-check]');
            const giftWarnConfirm = document.querySelector('[data-lg-giftwarn-confirm]');
            if (giftWarnModal && giftWarnModal.parentNode !== document.body) document.body.appendChild(giftWarnModal);

            function tierLabel(tier) {
                return ({ looth1:'Looth Public', looth2:'Looth LITE', looth3:'Looth PRO', looth4:'Looth Premium Plus' })[tier] || tier;
            }
            function showGiftWarnModal(active) {
                const tier  = tierLabel(active.tier || '');
                const days  = parseInt(active.days_remaining, 10) || 0;
                const exp   = active.expires_at || '';
                if (giftWarnBody) {
                    const expNice = exp ? new Date(exp + 'T00:00:00').toLocaleDateString(undefined, { month:'short', day:'numeric', year:'numeric' }) : '';
                    giftWarnBody.innerHTML =
                        'You have <strong>' + days + ' day' + (days === 1 ? '' : 's') + ' of ' + tier + '</strong> left from a redeemed gift' +
                        (expNice ? ' (through <strong>' + expNice + '</strong>)' : '') + '. ' +
                        '<br><br>' +
                        'No problem &mdash; if you set up a subscription now, ' +
                        '<strong>we won&rsquo;t charge you until your gift ends' + (expNice ? ' on ' + expNice : '') + '</strong>. ' +
                        'You stay covered without interruption and you only pay once your gift time is up.';
                }
                if (giftWarnConfirm) giftWarnConfirm.disabled = false;
                if (giftWarnModal)   giftWarnModal.hidden = false;
                document.body.classList.add('lg-modal-open');
            }
            function hideGiftWarnModal() {
                if (giftWarnModal) giftWarnModal.hidden = true;
                document.body.classList.remove('lg-modal-open');
            }
            document.querySelectorAll('[data-lg-giftwarn-cancel]').forEach(el => {
                el.addEventListener('click', hideGiftWarnModal);
            });
            if (giftWarnConfirm) {
                giftWarnConfirm.addEventListener('click', () => {
                    window.__lgAckActiveGift = true;
                    hideGiftWarnModal();
                    startCheckout();
                });
            }

            const joinCheckoutModal = document.querySelector('[data-lg-join-checkout-modal]');
            if (joinCheckoutModal && joinCheckoutModal.parentNode !== document.body) {
                document.body.appendChild(joinCheckoutModal);
            }

            function showCheckoutError(msg) {
                if (!customErrorEl) return;
                if (msg) {
                    customErrorEl.textContent = msg;
                    customErrorEl.hidden = false;
                } else {
                    customErrorEl.textContent = '';
                    customErrorEl.hidden = true;
                }
            }

            function teardownCheckoutMount() {
                if (mountedSession) {
                    try { mountedSession.destroy(); } catch (_) {}
                    mountedSession = null;
                }
                if (paymentElement) {
                    try { paymentElement.unmount(); } catch (_) {}
                    paymentElement = null;
                }
                customCheckout = null;
                if (checkoutEl) checkoutEl.innerHTML = '';
                if (peEl) peEl.innerHTML = '';
                if (customEl) customEl.hidden = true;
                if (checkoutEl) checkoutEl.hidden = false;
                showCheckoutError('');
                if (payBt) payBt.disabled = true;
            }

            async function mountEmbeddedCheckout(clientSecret) {
                if (customEl) customEl.hidden = true;
                if (checkoutEl) checkoutEl.hidden = false;
                mountedSession = await stripe.initEmbeddedCheckout({
                    clientSecret: clientSecret,
                    onComplete: showJoinProcessingOverlay,
                });
                if (joinCheckoutModal) joinCheckoutModal.hidden = false;
                document.body.classList.add('lg-modal-open');
                mountedSession.mount(checkoutEl);
            }

            async function mountCustomCheckout(clientSecret) {
                if (checkoutEl) checkoutEl.hidden = true;
                if (customEl) customEl.hidden = true;
                if (joinCheckoutModal) joinCheckoutModal.hidden = false;
                if (modalProcessingEl) modalProcessingEl.hidden = false;
                document.body.classList.add('lg-modal-open');

                customCheckout = await stripe.initCheckout({
                    fetchClientSecret: async () => clientSecret,
                });

                customCheckout.on('change', (session) => {
                    if (!payBt) return;
                    payBt.disabled = !session.canConfirm;
                    const cents = session && session.total && session.total.total
                        ? session.total.total.amount
                        : null;
                    const formatted = (typeof cents === 'number' && !isNaN(cents))
                        ? '$' + (cents / 100).toFixed(cents % 100 === 0 ? 0 : 2)
                        : '';
                    if (payLabelEl) payLabelEl.textContent = formatted ? 'Pay ' + formatted : 'Pay';
                    if (payAmountEl) { payAmountEl.textContent = formatted; payAmountEl.hidden = !formatted; }
                    if (paySublabelEl) { paySublabelEl.textContent = pendingLabel || ""; paySublabelEl.hidden = !pendingLabel; }
                });

                paymentElement = customCheckout.createPaymentElement();
                paymentElement.on('ready', () => {
                    if (customEl) customEl.hidden = false;
                    if (modalProcessingEl) modalProcessingEl.hidden = true;
                });
                paymentElement.mount(peEl);
            }

            async function onPayClick() {
                if (!customCheckout || !payBt || payBt.disabled) return;
                showCheckoutError('');

                if (joinCheckoutModal) joinCheckoutModal.dataset.lgLocked = '1';

                const origLabel = payLabelEl ? payLabelEl.textContent : '';
                payBt.disabled = true;
                if (modalProcessingEl) modalProcessingEl.hidden = false;

                try {
                    const result = await customCheckout.confirm();
                    if (result && result.error) {
                        if (joinCheckoutModal) delete joinCheckoutModal.dataset.lgLocked;
                        if (modalProcessingEl) modalProcessingEl.hidden = true;
                        showCheckoutError(result.error.message || 'Payment failed. Please try again.');
                        if (payLabelEl) payLabelEl.textContent = origLabel || 'Pay';
                        payBt.disabled = false;
                        return;
                    }

                    let sessionId = '';
                    try { sessionId = (customCheckout.session && customCheckout.session().id) || ''; } catch (_) {}
                    const returnUrl = sessionId
                        ? '<?php echo $jsReturn; ?>?session_id=' + encodeURIComponent(sessionId)
                        : '<?php echo $jsActivity; ?>';

                    if (joinCheckoutModal) joinCheckoutModal.hidden = true;
                    teardownCheckoutMount();

                    joinPaymentCompleted = true;
                    if (joinProcessingOverlay) joinProcessingOverlay.hidden = false;
                    document.body.classList.add('lg-modal-open');

                    window.location.href = returnUrl;
                } catch (err) {
                    if (joinCheckoutModal) delete joinCheckoutModal.dataset.lgLocked;
                    if (modalProcessingEl) modalProcessingEl.hidden = true;
                    showCheckoutError('Network error: ' + (err && err.message ? err.message : err));
                    if (payLabelEl) payLabelEl.textContent = origLabel || 'Pay';
                    payBt.disabled = false;
                }
            }
            if (payBt) payBt.addEventListener('click', onPayClick);

            function closeJoinCheckoutModal() {
                if (joinCheckoutModal) joinCheckoutModal.hidden = true;
                document.body.classList.remove('lg-modal-open');
                teardownCheckoutMount();
            }

            let joinPaymentInFlight = false;
            let joinPaymentCompleted = false;
            const joinProcessingOverlay = document.querySelector('[data-lg-join-processing]');
            if (joinProcessingOverlay && joinProcessingOverlay.parentNode !== document.body) {
                document.body.appendChild(joinProcessingOverlay);
            }
            function markJoinPaymentInFlight() {
                joinPaymentInFlight = true;
                window.addEventListener('beforeunload', preventJoinNavWhileProcessing);
            }
            function showJoinProcessingOverlay() {
                joinPaymentCompleted = true;
                if (joinCheckoutModal) joinCheckoutModal.dataset.lgLocked = '1';
                markJoinPaymentInFlight();
                if (joinProcessingOverlay) joinProcessingOverlay.hidden = false;
                document.body.classList.add('lg-modal-open');
                window.removeEventListener('beforeunload', preventJoinNavWhileProcessing);
            }
            function preventJoinNavWhileProcessing(e) {
                if (!joinPaymentInFlight && !joinPaymentCompleted) return;
                e.preventDefault();
                e.returnValue = '';
                return '';
            }

            document.querySelectorAll('[data-lg-join-checkout-close]').forEach(el => {
                el.addEventListener('click', () => {
                    if (joinPaymentCompleted) { showJoinProcessingOverlay(); return; }
                    if (joinPaymentInFlight) return;
                    closeJoinCheckoutModal();
                });
            });

            continueBt.addEventListener('click', startCheckout);
            backBt.addEventListener('click', function(){
                pendingPriceId = null;
                document.querySelectorAll('.lg-join__tier').forEach(c => c.classList.remove('is-selected'));
                document.querySelectorAll('.lg-join__buy.is-selected, .lg-join__trial-btn.is-selected').forEach(b => b.classList.remove('is-selected'));
                closeJoinCheckoutModal();
                closeSignupModal();
            });

            loadProducts();
        })();
        </script>
</main>
<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>
</body>
</html>
