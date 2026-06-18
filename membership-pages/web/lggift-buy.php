<?php
/**
 * /lggift-buy/ — standalone port of [lg_gift] (gift-membership purchase).
 *
 * VERBATIM body port of Shortcodes::gift() (src/Wp/Shortcodes.php:42). The
 * shortcode body (tier dropdown + duration + quantity + recipient modes +
 * Stripe checkout, self-styled via inline <style>) is included BYTE-FOR-BYTE;
 * only the chrome changes and the WP functions it calls are shimmed to
 * standalone equivalents (home_url/rest_url/wp_login_url/wp_lostpassword_url/
 * get_permalink → lg_ms_home(); esc_* / esc_js / wp_json_encode → htmlspecialchars
 * / lg_ms_esc_js / json_encode; is_user_logged_in → resolved from the session).
 *
 * Browser flow unchanged: products from /billing/v1/products, checkout →
 * /billing/v1/checkout (gift=true paths), account auth via WP REST /…/auth.
 * Stripe TEST mode in the sandbox. Admin-only pre-launch (router enforces;
 * self-gate is defense-in-depth).
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

/* ---- standalone shims for the WP functions the shortcode body calls ---- */
if (!function_exists('lg_ms_home'))   { function lg_ms_home(string $p = ''): string { return 'https://' . LG_MEMBERSHIP_HOST . $p; } }
if (!function_exists('lg_ms_esc_js')) { function lg_ms_esc_js(string $s): string { return strtr($s, ['\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\n" => '\\n', "\r" => '\\r', '</' => '<\\/']); } }
if (!function_exists('esc_html'))           { function esc_html($s) { return lg_membership_h((string) $s); } }
if (!function_exists('esc_attr'))           { function esc_attr($s) { return lg_membership_h((string) $s); } }
if (!function_exists('esc_url'))            { function esc_url($s) { return lg_membership_h((string) $s); } }
if (!function_exists('esc_url_raw'))        { function esc_url_raw($s) { return (string) $s; } }
if (!function_exists('esc_js'))             { function esc_js($s) { return lg_ms_esc_js((string) $s); } }
if (!function_exists('esc_textarea'))       { function esc_textarea($s) { return lg_membership_h((string) $s); } }
if (!function_exists('home_url'))           { function home_url($p = '') { return lg_ms_home((string) $p); } }
if (!function_exists('rest_url'))           { function rest_url($p = '') { return lg_ms_home('/wp-json/' . ltrim((string) $p, '/')); } }
if (!function_exists('wp_login_url'))       { function wp_login_url($r = '') { return lg_ms_home('/wp-login.php'); } }
if (!function_exists('wp_lostpassword_url')){ function wp_lostpassword_url($r = '') { return lg_ms_home('/wp-login.php?action=lostpassword'); } }
if (!function_exists('get_permalink'))      { function get_permalink() { return lg_ms_home('/lggift-buy/'); } }
if (!function_exists('wp_json_encode'))     { function wp_json_encode($x) { return json_encode($x); } }
if (!function_exists('is_user_logged_in'))  { function is_user_logged_in() { return !empty($GLOBALS['lg_ms_is_logged_in']); } }

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
$isLoggedIn = $isLoggedIn && $emailValue !== '';
$GLOBALS['lg_ms_is_logged_in'] = $isLoggedIn;

/* ---- gift() server setup (verbatim, shortcode_atts inlined) ---- */
$atts = [
    'heading' => 'Give the gift of Looth',
    'popular' => 'looth3',
];

$base      = rtrim((string) home_url('/billing'), '/');
$endpoints = [
    'products'       => esc_url_raw($base . '/v1/products'),
    'config'         => esc_url_raw($base . '/v1/config'),
    'checkout'       => esc_url_raw($base . '/v1/checkout'),
    'authUrl'        => esc_url_raw(rest_url('lg-member-sync/v1/auth')),
    'affiliateClick' => esc_url_raw($base . '/v1/affiliate-click'),
];

$heading     = esc_html((string) $atts['heading']);
$popularRef  = (string) $atts['popular'];
$email       = esc_attr($emailValue);
$name        = esc_attr($nameValue);
$endpointsJs = wp_json_encode($endpoints);
$configJs    = wp_json_encode([
    'popular'    => $popularRef,
    'loggedIn'   => $isLoggedIn,
    'buyerEmail' => $emailValue,
    'buyerName'  => $nameValue,
    'loginUrl'   => wp_login_url(get_permalink()),
]);
$rootClass = 'lg-gift' . ($isLoggedIn ? ' lg-gift--logged-in' : '');

$asset_v = (string) (@filemtime(__DIR__ . '/lg-shortcodes.css') ?: '1');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gift Memberships — The Looth Group</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/lg-shared/site-header.css?v=<?= @filemtime('/srv/lg-shared/site-header.css') ?: '1' ?>">
<link rel="stylesheet" href="<?= $h(LG_MEMBERSHIP_PUBLIC_PATH) ?>/lg-shortcodes.css?v=<?= $h($asset_v) ?>">
</head>
<body class="lg-membership-page lg-gift-page">
<?php lg_shared_render_site_header($ctx); ?>
<main id="lg-main">
        <div class="<?php echo esc_attr( $rootClass ); ?>">
            <?php if ( $isLoggedIn ) : ?>
                <p class="lg-gift__loggedin-banner">
                    Hi <strong><?php echo esc_html( $nameValue ?: $emailValue ); ?></strong> &mdash; codes you buy will land in your <a href="<?php echo esc_url( home_url( '/my-gifts/' ) ); ?>">gift dashboard</a> after checkout.
                </p>
            <?php endif; ?>

            <header class="lg-gift__hero">
                <h2 class="lg-gift__heading"><?php echo $heading; ?></h2>
                <p class="lg-gift__intro">
                    Buy gift codes to share with anyone. Each code grants a membership when redeemed. Codes never expire.
                </p>
                <ul class="lg-gift__perks">
                    <li>✓ Full members-only forums + archive</li>
                    <li>✓ Sponsor benefits and member events</li>
                    <li>✓ Shareable to anyone — they redeem at their convenience</li>
                </ul>
            </header>

            <div class="lg-gift__panel">
                <h3 class="lg-gift__panel-heading">1. Tier selection</h3>
                <div class="lg-gift__tier-dropdown" data-lg-tier-dropdown>
                    <button type="button" class="lg-gift__tier-trigger" data-lg-tier-trigger aria-haspopup="listbox" aria-expanded="false">
                        <span class="lg-gift__tier-trigger-text" data-lg-tier-selected>Loading…</span>
                        <span class="lg-gift__tier-chevron" aria-hidden="true">▾</span>
                    </button>
                    <div class="lg-gift__tier-options" data-lg-tier-options role="listbox" hidden></div>
                </div>

                <h3 class="lg-gift__panel-heading">2. Membership duration</h3>
                <div class="lg-gift__durations" data-lg-gift-durations></div>

                <h3 class="lg-gift__panel-heading">3. How many gift memberships?</h3>
                <div class="lg-gift__quantity-row">
                    <button type="button" class="lg-gift__qbtn" data-lg-qty-step="-1" aria-label="Decrease">−</button>
                    <input type="number" class="lg-gift__qinput" name="quantity" value="1" min="1" step="1" required>
                    <button type="button" class="lg-gift__qbtn" data-lg-qty-step="1" aria-label="Increase">+</button>
                </div>
                <div class="lg-gift__presets" data-lg-gift-presets></div>

                <div class="lg-gift__progress" data-lg-gift-progress hidden>
                    <div class="lg-gift__progress-track"><div class="lg-gift__progress-fill" data-lg-gift-progress-fill></div></div>
                    <p class="lg-gift__progress-label" data-lg-gift-progress-label></p>
                </div>

                <div data-lg-mode-section>
                    <h3 class="lg-gift__panel-heading">4. How do you want your codes?</h3>
                    <div class="lg-mode">

                        <label class="lg-mode__opt is-selected" data-mode="managed">
                            <input type="radio" name="mode" value="managed" checked>
                            <span class="lg-mode__radio"></span>
                            <div>
                                <div class="lg-mode__title">Log in to manage &amp; send personalized gift emails <span class="lg-mode__badge">Recommended</span></div>
                                <div class="lg-mode__sub">Access your gift dashboard after purchase &mdash; resend, refund, or send a beautiful personalized email to each recipient whenever you&rsquo;re ready.</div>
                            </div>
                        </label>

                        <label class="lg-mode__opt" data-mode="self">
                            <input type="radio" name="mode" value="self">
                            <span class="lg-mode__radio"></span>
                            <div>
                                <div class="lg-mode__title">Get codes via email</div>
                                <div class="lg-mode__sub">We send all codes to your email. Forward or share them yourself.</div>
                                <div class="lg-mode__note">Heads up: without an account we can&rsquo;t recover or refund these codes if you lose access to this email.</div>
                            </div>
                        </label>

                    </div>

                    <div class="lg-auth" data-lg-auth-block hidden>
                        <p class="lg-auth__login-prompt">
                            Have you logged on before?
                            <a href="#" data-lg-auth-open-login>Log in instead &rarr;</a>
                        </p>
                        <div class="lg-auth__fields">
                            <input type="email"    class="lg-auth__input" data-lg-auth-email          placeholder="your@email.com"                  autocomplete="email">
                            <input type="email"    class="lg-auth__input" data-lg-auth-email-confirm  placeholder="Confirm email"                   autocomplete="email">
                            <small data-lg-auth-email-mismatch class="lg-pwd-mismatch" hidden>Emails don&rsquo;t match.</small>
                            <input type="password" class="lg-auth__input" data-lg-auth-password         placeholder="Pick a password (8+ characters)" autocomplete="new-password">
                            <input type="password" class="lg-auth__input" data-lg-auth-password-confirm placeholder="Confirm password"               autocomplete="new-password">
                            <small data-lg-auth-pwd-mismatch class="lg-pwd-mismatch" hidden>Passwords don&rsquo;t match.</small>
                        </div>
                        <p class="lg-auth__hint">We&rsquo;ll set up your account so your gift codes attach to your dashboard.</p>
                        <button type="button" class="lg-auth__btn" data-lg-auth-btn>Create account</button>
                        <p class="lg-auth__error" data-lg-auth-error hidden></p>
                    </div>

                    <!-- Inline login modal — opened when an existing member clicks
                         "Log in" or when the create-account submit detects an
                         existing email. Same-page so gift form state survives. -->
                    <div class="lg-pay-modal" data-lg-login-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-login-modal-title">
                        <div class="lg-pay-modal__backdrop" data-lg-login-close></div>
                        <div class="lg-pay-modal__card lg-login-card">
                            <button type="button" class="lg-pay-modal__close" data-lg-login-close aria-label="Close">&times;</button>
                            <div class="lg-login-card__body">
                                <h3 id="lg-login-modal-title" class="lg-login-card__title">Log in to continue</h3>
                                <p class="lg-login-card__sub" data-lg-login-sub>Your gift selections will stay right here.</p>
                                <div class="lg-login-card__fields">
                                    <input type="email"    class="lg-auth__input" data-lg-login-email    placeholder="your@email.com" autocomplete="email">
                                    <input type="password" class="lg-auth__input" data-lg-login-password placeholder="Password"       autocomplete="current-password">
                                </div>
                                <p class="lg-auth__error" data-lg-login-error hidden></p>
                                <button type="button" class="lg-auth__btn" data-lg-login-btn>Log in</button>
                                <p class="lg-login-card__forgot">
                                    <a href="<?php echo esc_url( wp_lostpassword_url( get_permalink() ) ); ?>">Forgot your password?</a>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="lg-auth__success" data-lg-auth-success hidden>
                        <span class="lg-auth__check-icon">&#10003;</span>
                        <span data-lg-auth-welcome>You&rsquo;re logged in.</span>
                    </div>

                </div>

                <div class="lg-consent" data-lg-consent-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-consent-title">
                    <div class="lg-consent__backdrop" data-lg-consent-cancel></div>
                    <div class="lg-consent__card">
                        <button type="button" class="lg-consent__close" data-lg-consent-cancel aria-label="Close">&times;</button>
                        <h3 id="lg-consent-title" class="lg-consent__title">One more thing</h3>
                        <p class="lg-consent__body">
                            We&rsquo;re creating an account for <strong data-lg-consent-email>your email</strong>.
                            Your email will only be used for your gift codes and your account &mdash;
                            <strong>we will never sell or share it</strong>.
                        </p>
                        <label class="lg-consent__check">
                            <input type="checkbox" data-lg-consent-subscribe>
                            <span>Yes, also send me the weekly Looth email &mdash; news, events, and member highlights.</span>
                        </label>
                        <div class="lg-consent__actions">
                            <button type="button" class="lg-consent__btn lg-consent__btn--ghost" data-lg-consent-cancel>Cancel</button>
                            <button type="button" class="lg-consent__btn lg-consent__btn--primary" data-lg-consent-confirm>Create my account</button>
                        </div>
                        <p class="lg-consent__error" data-lg-consent-error hidden></p>
                    </div>
                </div>

                <div class="lg-redirect" data-lg-redirect-overlay hidden role="dialog" aria-modal="true" aria-labelledby="lg-redirect-title">
                    <div class="lg-redirect__card">
                        <div class="lg-redirect__spinner" aria-hidden="true"></div>
                        <div id="lg-redirect-title" class="lg-redirect__title">Setting up secure checkout&hellip;</div>
                        <div class="lg-redirect__sub">Please wait &mdash; the payment form is loading.</div>
                    </div>
                </div>

                <div class="lg-pay-modal" data-lg-checkout-modal hidden role="dialog" aria-modal="true" aria-label="Secure checkout">
                    <div class="lg-pay-modal__backdrop" data-lg-checkout-close></div>
                    <div class="lg-pay-modal__card">
                        <button type="button" class="lg-pay-modal__close" data-lg-checkout-close aria-label="Close checkout">&times;</button>

                        <!-- In-modal processing overlay: shown while confirm() runs. -->
                        <div class="lg-modal-processing" data-lg-gift-modal-processing hidden aria-hidden="true">
                            <div class="lg-modal-processing__spinner" aria-hidden="true"></div>
                            <p class="lg-modal-processing__label">Processing payment&hellip;</p>
                        </div>

                        <!-- Embedded path: kept for fallback if server returns ui_mode=embedded. -->
                        <div class="lg-pay-modal__body" data-lg-gift-checkout></div>

                        <!-- Custom path (Basil): we mount Stripe Elements + render our own Pay button. -->
                        <div class="lg-pay-modal__custom lg-stripe-modal" data-lg-gift-checkout-custom hidden>
                            <div class="lg-stripe-modal__header">
                                <div class="lg-stripe-modal__heading">Complete your purchase</div>
                                <div class="lg-stripe-modal__amount" data-lg-gift-pay-amount>&nbsp;</div>
                                <div class="lg-stripe-modal__sublabel" data-lg-gift-pay-sublabel>&nbsp;</div>
                            </div>
                            <div class="lg-stripe-modal__pe" data-lg-gift-payment-element></div>
                            <div class="lg-stripe-modal__error" data-lg-gift-checkout-error role="alert" hidden></div>
                            <button type="button" class="lg-stripe-modal__pay" data-lg-gift-checkout-pay disabled>
                                <span data-lg-gift-pay-label>Pay</span>
                            </button>
                            <p class="lg-stripe-modal__secured">
                                <svg class="lg-stripe-modal__secured-lock" xmlns="http://www.w3.org/2000/svg" width="11" height="13" viewBox="0 0 11 13" aria-hidden="true"><path fill="currentColor" d="M5.5 0C3.567 0 2 1.567 2 3.5V5h-.5A1.5 1.5 0 0 0 0 6.5v5A1.5 1.5 0 0 0 1.5 13h8a1.5 1.5 0 0 0 1.5-1.5v-5A1.5 1.5 0 0 0 9.5 5H9V3.5C9 1.567 7.433 0 5.5 0Zm0 1.5A2 2 0 0 1 7.5 3.5V5h-4V3.5a2 2 0 0 1 2-2Z"/></svg>
                                <span>Powered by</span>
                                <svg class="lg-stripe-modal__secured-mark" xmlns="http://www.w3.org/2000/svg" width="42" height="18" viewBox="0 0 60 25" aria-hidden="true" role="img" aria-label="Stripe"><path fill="#635BFF" d="M59.64 14.28h-8.06c.19 1.93 1.6 2.55 3.2 2.55 1.64 0 2.96-.37 4.05-.95v3.32a8.33 8.33 0 0 1-4.56 1.1c-4.01 0-6.83-2.5-6.83-7.48 0-4.19 2.39-7.52 6.3-7.52 3.92 0 5.96 3.28 5.96 7.5 0 .4-.04 1.26-.06 1.48zm-5.92-5.62c-1.03 0-2.17.73-2.17 2.58h4.25c0-1.85-1.07-2.58-2.08-2.58zM40.95 20.3c-1.44 0-2.32-.6-2.9-1.04l-.02 4.63-4.12.87V5.57h3.76l.08 1.02a4.7 4.7 0 0 1 3.23-1.29c2.9 0 5.62 2.6 5.62 7.4 0 5.23-2.7 7.6-5.65 7.6zM40 9.04c-.95 0-1.54.34-1.97.81l.02 6.12c.4.44.98.78 1.95.78 1.52 0 2.54-1.65 2.54-3.87 0-2.15-1.04-3.84-2.54-3.84zM28.24 5.57h4.13v14.44h-4.13V5.57zm0-4.7L32.37 0v3.36l-4.13.88V.88zm-4.32 9.35v9.79H19.8V5.57h3.7l.12 1.22c1-1.77 3.07-1.41 3.62-1.22v3.79c-.52-.17-2.29-.43-3.32.86zm-8.55 4.72c0 2.43 2.6 1.68 3.12 1.46v3.36c-.55.3-1.54.54-2.89.54a4.15 4.15 0 0 1-4.27-4.24l.01-13.17 4.02-.86v3.54h3.14V9.1h-3.14l.01 5.85zm-4.91.7c0 2.97-2.31 4.66-5.73 4.66a11.2 11.2 0 0 1-4.46-.93v-3.93c1.38.75 3.1 1.31 4.46 1.31.92 0 1.53-.24 1.53-1C6.26 13.77 0 14.51 0 9.95 0 7.04 2.28 5.3 5.62 5.3c1.36 0 2.72.2 4.09.75v3.88a9.23 9.23 0 0 0-4.1-1.06c-.86 0-1.44.25-1.44.93 0 1.85 6.29.97 6.29 5.83z"/></svg>
                            </p>
                        </div>
                    </div>
                </div>

            <div class="lg-processing" data-lg-gift-processing hidden role="status" aria-live="polite" aria-label="Processing payment">
                <div class="lg-processing__card">
                    <div class="lg-processing__spinner" aria-hidden="true"></div>
                    <h3 class="lg-processing__title">Still processing your payment&hellip;</h3>
                    <p class="lg-processing__body">Your payment went through. We&rsquo;re finalizing your order &mdash; please don&rsquo;t close this tab. You&rsquo;ll be redirected in a moment.</p>
                </div>
            </div>

            <!-- Gift purchase success — gift codes are delivered by email, so
                 instead of redirecting away we show a confirmation modal in
                 place. Webhook + reconcile cron handle fulfillment server-side. -->
            <div class="lg-gift-success" data-lg-gift-success hidden role="dialog" aria-modal="true" aria-labelledby="lg-gift-success-title">
                <div class="lg-gift-success__card">
                    <div class="lg-gift-success__icon" aria-hidden="true">&#10003;</div>
                    <h3 id="lg-gift-success-title" class="lg-gift-success__title">Payment received &mdash; thank you!</h3>
                    <p class="lg-gift-success__body">
                        Your gift codes are on the way. We&rsquo;ve emailed them to <span class="lg-gift-success__email" data-lg-gift-success-email>your inbox</span>.
                        It can take a minute or two to land.
                    </p>
                    <div class="lg-gift-success__actions">
                        <?php if ( is_user_logged_in() ): ?>
                            <a class="lg-gift-success__btn lg-gift-success__btn--primary" href="<?php echo esc_url( home_url( '/my-gifts/' ) ); ?>">View my gifts</a>
                            <button type="button" class="lg-gift-success__btn lg-gift-success__btn--ghost" data-lg-gift-success-close>Close</button>
                        <?php else: ?>
                            <button type="button" class="lg-gift-success__btn lg-gift-success__btn--primary" data-lg-gift-success-close>Done</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

                <div class="lg-anonwarn" data-lg-anonwarn-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-anonwarn-title">
                    <div class="lg-anonwarn__backdrop" data-lg-anonwarn-cancel></div>
                    <div class="lg-anonwarn__card">
                        <button type="button" class="lg-anonwarn__close" data-lg-anonwarn-cancel aria-label="Close">&times;</button>
                        <h3 id="lg-anonwarn-title" class="lg-anonwarn__title">Heads up &mdash; checking out without an account</h3>
                        <p class="lg-anonwarn__body">
                            You&rsquo;ll get the codes via email, but <strong>if you ever lose access to that email we won&rsquo;t be able to recover or refund the codes for you.</strong>
                            With a free account you also get a dashboard to resend, void, or refund codes any time.
                        </p>
                        <label class="lg-anonwarn__check">
                            <input type="checkbox" data-lg-anonwarn-confirm-check>
                            <span>I understand and want to proceed without an account.</span>
                        </label>
                        <div class="lg-anonwarn__actions">
                            <button type="button" class="lg-anonwarn__btn lg-anonwarn__btn--ghost"   data-lg-anonwarn-cancel>Go back</button>
                            <button type="button" class="lg-anonwarn__btn lg-anonwarn__btn--primary" data-lg-anonwarn-confirm disabled>Continue to payment</button>
                        </div>
                    </div>
                </div>

                <div data-lg-buyer-email-section>
                    <h3 class="lg-gift__panel-heading">5. Your email <span style="font-weight:400;font-size:.85em;color:rgba(0,0,0,0.5);" data-lg-mode-label>(codes will be sent here)</span></h3>
                    <div class="lg-gift__field">
                        <input type="email" name="email" value="<?php echo $email; ?>" placeholder="you@example.com">
                        <input type="email" name="email_confirm" value="<?php echo $email; ?>" placeholder="Confirm email" style="margin-top:.5em;">
                        <small data-lg-buyer-email-mismatch class="lg-pwd-mismatch" hidden>Emails don&rsquo;t match.</small>
                        <small data-lg-mode-help>We send all codes to this address. You forward / share them yourself.</small>
                    </div>
                </div>

            </div>

            <div class="lg-gift__summary" data-lg-gift-summary>
                <div class="lg-gift__summary-row">
                    <span class="lg-gift__summary-label">Subtotal</span>
                    <span data-lg-gift-line-sub>—</span>
                </div>
                <div class="lg-gift__summary-row lg-gift__summary-row--disc" data-lg-gift-line-disc-row hidden>
                    <span class="lg-gift__summary-label">Bulk discount</span>
                    <span data-lg-gift-line-disc>—</span>
                </div>
                <div class="lg-gift__summary-row lg-gift__summary-row--total">
                    <span class="lg-gift__summary-label">Total</span>
                    <span data-lg-gift-line-total>—</span>
                </div>
                <p class="lg-gift__savings" data-lg-gift-savings hidden></p>
            </div>

            <button type="button" class="lg-gift__submit" data-lg-gift-submit>
                <span data-lg-gift-cta>Continue to checkout</span>
            </button>

            <p class="lg-gift__guarantee">
                <span aria-hidden="true">🎁</span> Codes never expire · billed in USD by Stripe · 30-day refund window
            </p>

            <div class="lg-gift__error" data-lg-gift-error aria-live="polite"></div>
        </div>

        <style>
            .lg-gift { max-width: 720px; margin: 0 auto; padding: 1.5em 1.2em; box-sizing: border-box; }
            .lg-gift * { box-sizing: border-box; }
            .lg-gift [hidden] { display: none !important; }
            .lg-gift__hero { text-align: center; margin-bottom: 1.6em; }
            .lg-gift__heading { margin: 0 0 .3em; font-size: 1.8em; }
            .lg-gift__intro { margin: 0 0 .9em; opacity: .85; }
            .lg-gift__perks { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: .4em 1.2em; justify-content: center; font-size: 0.95em; opacity: .8; }
            .lg-gift__perks li { white-space: nowrap; }

            .lg-gift__panel { border: 1px solid rgba(0,0,0,0.1); border-radius: 10px; padding: 1.2em 1.4em; background: rgba(255,255,255,0.4); }
            .lg-gift__panel-heading { margin: 1em 0 .55em; font-size: 1.05em; font-weight: 600; }
            .lg-gift__panel-heading:first-child { margin-top: 0; }

            .lg-gift__tier-dropdown { position: relative; }
            .lg-gift__tier-trigger { width: 100%; display: flex; justify-content: space-between; align-items: flex-start; gap: .6em; padding: .8em 1em; font-size: 1em; border: 1px solid rgba(0,0,0,0.2); border-radius: 8px; background: #fff; color: inherit; cursor: pointer; text-align: left; box-sizing: border-box; }
            .lg-gift__tier-trigger:focus { outline: 2px solid var(--lg-amber, #ECB351); outline-offset: 1px; }
            .lg-gift__tier-trigger-text { flex: 1; white-space: normal; line-height: 1.45; word-break: break-word; }
            .lg-gift__tier-chevron { flex-shrink: 0; opacity: .55; font-size: .85em; transition: transform .15s; padding-top: .15em; }
            .lg-gift__tier-trigger[aria-expanded="true"] .lg-gift__tier-chevron { transform: rotate(180deg); }
            .lg-gift__tier-options { position: absolute; z-index: 200; left: 0; right: 0; top: calc(100% + 4px); background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.13); overflow: hidden; }
            .lg-gift__tier-options[hidden] { display: none; }
            .lg-gift__tier-option { padding: .8em 1em; cursor: pointer; line-height: 1.45; white-space: normal; }
            .lg-gift__tier-option:hover { background: rgba(0,0,0,0.04); }
            .lg-gift__tier-option.is-selected { background: rgba(236,179,81,0.12); font-weight: 600; }

            .lg-gift__durations { display: flex; flex-direction: column; gap: 0; border: 1px solid rgba(0,0,0,0.12); border-radius: 10px; overflow: hidden; margin-top: .2em; }
            .lg-gift__dur-row { display: flex; align-items: center; gap: .85em; padding: .85em 1.1em; cursor: pointer; background: #fff; border-bottom: 1px solid rgba(0,0,0,0.08); transition: background .12s; }
            .lg-gift__dur-row:last-child { border-bottom: none; }
            .lg-gift__dur-row:hover { background: rgba(0,0,0,0.025); }
            .lg-gift__dur-row.is-selected { background: rgba(236,179,81,0.10); }
            .lg-gift__dur-row input[type="radio"] { accent-color: var(--lg-amber, #ECB351); width: 1.1em; height: 1.1em; flex-shrink: 0; margin: 0; cursor: pointer; }
            .lg-gift__dur-label { font-weight: 500; flex-shrink: 0; }
            .lg-gift__dur-stepper { display: inline-flex; align-items: stretch; border: 1px solid rgba(0,0,0,0.18); border-radius: 6px; overflow: hidden; margin-left: .65em; flex-shrink: 0; }
            .lg-gift__dur-step { width: 32px; background: rgba(0,0,0,0.04); border: none; cursor: pointer; font-size: 1.1em; line-height: 1; color: inherit; padding: 0; flex-shrink: 0; }
            .lg-gift__dur-step:hover { background: rgba(0,0,0,0.09); }
            .lg-gift__dur-custom-input { width: 46px; text-align: center; border: none; border-left: 1px solid rgba(0,0,0,0.1); border-right: 1px solid rgba(0,0,0,0.1); font-size: .95em; padding: .3em 0; background: #fff; color: inherit; -moz-appearance: textfield; }
            .lg-gift__dur-custom-input::-webkit-outer-spin-button, .lg-gift__dur-custom-input::-webkit-inner-spin-button { -webkit-appearance: none; }
            .lg-gift__dur-price { color: var(--lg-sage, #87986A); font-weight: 600; font-size: .95em; flex-shrink: 0; flex: 1; text-align: right; }

            .lg-gift__quantity-row { display: inline-flex; align-items: stretch; border: 1px solid rgba(0,0,0,0.15); border-radius: 8px; overflow: hidden; }
            .lg-gift__qbtn { width: 44px; background: rgba(0,0,0,0.04); border: none; cursor: pointer; font-size: 1.3em; line-height: 1; color: inherit; }
            .lg-gift__qbtn:hover { background: rgba(0,0,0,0.08); }
            .lg-gift__qinput { width: 88px; text-align: center; border: none; border-left: 1px solid rgba(0,0,0,0.1); border-right: 1px solid rgba(0,0,0,0.1); font-size: 1.05em; padding: .5em 0; background: #fff; color: inherit; -moz-appearance: textfield; }
            .lg-gift__qinput::-webkit-outer-spin-button, .lg-gift__qinput::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

            .lg-gift__presets { display: flex; flex-wrap: wrap; gap: .45em; margin-top: .8em; }
            .lg-gift__preset { padding: .35em .85em; border-radius: 999px; border: 1px solid rgba(0,0,0,0.15); background: #fff; cursor: pointer; font-size: .9em; color: inherit; transition: all .15s; }
            .lg-gift__preset:hover { border-color: rgba(0,0,0,0.4); }
            .lg-gift__preset.is-active { background: var(--lg-amber, #ECB351); border-color: transparent; color: #1f1d1a; font-weight: 600; }
            .lg-gift__preset-disc { display: inline-block; margin-left: .35em; opacity: .85; font-size: .85em; }
            .lg-gift__preset.is-active .lg-gift__preset-disc { opacity: 1; }

            .lg-gift__progress { margin-top: 1em; }
            .lg-gift__progress-track { height: 8px; background: rgba(0,0,0,0.08); border-radius: 999px; overflow: hidden; }
            .lg-gift__progress-fill { height: 100%; background: linear-gradient(90deg, #87986A, #ECB351); transition: width .25s; }
            .lg-gift__progress-label { font-size: .9em; margin: .5em 0 0; opacity: .85; }

            .lg-gift__field { margin-top: .4em; }
            .lg-gift__field input[type="email"] { width: 100%; padding: .65em .85em; font-size: 1em; border: 1px solid rgba(0,0,0,0.15); border-radius: 8px; box-sizing: border-box; }
            .lg-gift__field small { display: block; opacity: .7; font-size: .85em; margin-top: .35em; }

            .lg-gift__summary { margin: 1.4em 0; padding: 1em 1.2em; border: 1px solid rgba(0,0,0,0.1); border-radius: 10px; background: rgba(135,152,106,0.06); }
            .lg-gift__summary-row { display: flex; justify-content: space-between; align-items: baseline; padding: .25em 0; }
            .lg-gift__summary-row--disc { color: #15803d; font-weight: 500; }
            .lg-gift__summary-row--total { border-top: 1px solid rgba(0,0,0,0.1); margin-top: .35em; padding-top: .65em; font-size: 1.15em; font-weight: 700; }
            .lg-gift__summary-label { opacity: .8; }
            .lg-gift__savings { margin: .5em 0 0; font-size: .9em; color: #15803d; font-weight: 500; }

            .lg-gift__submit { display: block; width: 100%; padding: .9em 1.2em; font-size: 1.05em; font-weight: 600; cursor: pointer; background: var(--lg-amber, #ECB351); color: #1f1d1a; border: none; border-radius: 8px; transition: filter .15s; }
            .lg-gift__submit:hover { filter: brightness(0.95); }
            .lg-gift__submit:disabled { opacity: 0.6; cursor: progress; }

            .lg-gift__guarantee { text-align: center; opacity: .65; font-size: .85em; margin-top: .8em; }
            .lg-gift__error { color: #b00; margin-top: .8em; min-height: 1em; }
            .lg-gift__error:empty { display: none; }
            .lg-gift__checkout { margin-top: 1.6em; }
            .lg-gift__loading { padding: 1em; opacity: .6; text-align: center; grid-column: 1 / -1; }

            /* Send-mode toggle (radio cards) */
            .lg-mode { display: flex; flex-direction: column; gap: 10px; margin-bottom: .4em; }
            .lg-mode__opt { position: relative; display: flex; align-items: flex-start; gap: .85em; padding: 1em 1.1em; border: 2px solid rgba(0,0,0,0.1); border-radius: 8px; cursor: pointer; background: #fff; transition: border-color .15s, box-shadow .15s; }
            .lg-mode__opt:hover { border-color: rgba(0,0,0,0.3); }
            .lg-mode__opt.is-selected { border-color: var(--lg-amber, #ECB351); box-shadow: 0 0 0 3px rgba(236,179,81,0.18); }
            .lg-mode__opt input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
            .lg-mode__radio { flex-shrink: 0; width: 18px; height: 18px; border-radius: 50%; border: 2px solid rgba(0,0,0,0.25); margin-top: .18em; transition: border-color .15s, background .15s; }
            .lg-mode__opt.is-selected .lg-mode__radio { border-color: var(--lg-amber, #ECB351); background: radial-gradient(circle at center, var(--lg-amber,#ECB351) 5px, #fff 5px); }
            .lg-mode__title { font-weight: 600; }
            .lg-mode__badge { display: inline-block; margin-left: .5em; padding: .12em .55em; background: var(--lg-amber, #ECB351); color: #1f1d1a; border-radius: 999px; font-size: .7em; font-weight: 700; letter-spacing: .03em; vertical-align: middle; }
            .lg-mode__sub { font-size: .88em; opacity: .7; margin-top: .2em; }
            .lg-mode__note { font-size: .8em; color: #92400e; margin-top: .35em; font-style: italic; }

            /* Inline auth block (login / register) */
            .lg-auth { margin-top: .85em; padding: 1.1em; background: rgba(250,246,238,0.7); border: 1px solid rgba(236,179,81,0.28); border-radius: 8px; }
            .lg-auth__fields { display: flex; flex-direction: column; gap: .5em; margin-bottom: .7em; }
            .lg-auth__input { width: 100%; padding: .6em .85em; font-size: .95em; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; background: #fff; color: inherit; box-sizing: border-box; }
            .lg-auth__check-row { display: flex; align-items: center; gap: .5em; font-size: .88em; margin-bottom: .75em; cursor: pointer; }
            .lg-auth__hint { font-size: .82em; color: rgba(0,0,0,0.55); margin: -.25em 0 .85em; line-height: 1.4; }
            .lg-auth__btn { width: 100%; padding: .65em 1em; background: var(--lg-amber, #ECB351); border: none; border-radius: 6px; font-weight: 600; font-size: .95em; cursor: pointer; color: #1f1d1a; transition: opacity .15s; }
            .lg-auth__btn:hover { opacity: .88; }
            .lg-auth__btn:disabled { opacity: .55; cursor: default; }
            .lg-auth__error { font-size: .88em; color: #b91c1c; margin-top: .6em; }
            .lg-auth__forgot { font-size: .85em; margin-top: .35em; }
            .lg-auth__login-prompt { font-size: .92em; margin: 0 0 .8em; color: #444; }
            .lg-auth__login-prompt a { font-weight: 600; }

            /* Inline login modal card — sits inside the shared .lg-pay-modal
               overlay, but smaller than the checkout card. */
            .lg-login-card { max-width: 420px !important; }
            .lg-login-card__body { padding: 1.8em 1.5em 1.4em; }
            .lg-login-card__title { margin: 0 0 .35em; font-size: 1.2em; font-weight: 700; }
            .lg-login-card__sub { margin: 0 0 1.1em; font-size: .92em; color: #555; }
            .lg-login-card__fields { display: flex; flex-direction: column; gap: .6em; margin-bottom: .8em; }
            .lg-login-card__forgot { margin: .8em 0 0; font-size: .85em; text-align: center; }
            .lg-auth__success { display: flex; align-items: center; gap: .55em; margin-top: .85em; padding: .7em 1em; background: rgba(135,152,106,0.12); border: 1px solid rgba(135,152,106,0.3); border-radius: 8px; font-size: .92em; color: #2d4f2a; }
            .lg-auth__check-icon { font-weight: 700; font-size: 1.15em; }

            /* Recipient repeater */
            .lg-recip { margin-top: .8em; }
            .lg-recip__bulk { display: flex; gap: .5em; margin-bottom: .6em; flex-wrap: wrap; }
            .lg-recip__bulk-btn { padding: .35em .8em; border-radius: 999px; border: 1px solid rgba(0,0,0,0.15); background: #fff; cursor: pointer; font-size: .85em; color: inherit; }
            .lg-recip__bulk-btn:hover { border-color: rgba(0,0,0,0.4); }
            .lg-recip__paste { display: none; width: 100%; min-height: 5em; padding: .65em .85em; font: 14px/1.4 ui-monospace, Menlo, Consolas, monospace; border: 1px solid rgba(0,0,0,0.15); border-radius: 8px; resize: vertical; margin-bottom: .8em; box-sizing: border-box; }
            .lg-recip__paste.is-open { display: block; }
            .lg-recip__paste-help { display: none; font-size: .8em; color: rgba(0,0,0,0.5); margin-top: -.3em; margin-bottom: .8em; }
            .lg-recip__paste-help.is-open { display: block; }
            .lg-recip__apply-all { display: none; gap: .5em; align-items: center; margin: .8em 0 0; padding: .6em .85em; background: rgba(0,0,0,0.03); border-radius: 6px; }
            .lg-recip__apply-all.is-open { display: flex; }
            .lg-recip__apply-all textarea { flex: 1; min-height: 2em; padding: .4em .65em; font-size: .88em; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; resize: vertical; font-family: inherit; }
            .lg-recip__apply-all-btn { padding: .4em .8em; font-size: .82em; border-radius: 6px; border: 1px solid var(--lg-sage, #87986A); color: var(--lg-sage, #87986A); background: #fff; cursor: pointer; white-space: nowrap; }
            .lg-recip__list { display: flex; flex-direction: column; gap: .55em; margin-top: .6em; }
            .lg-recip__row { display: grid; grid-template-columns: 36px 1.1fr 1.4fr; gap: 8px; align-items: start; }
            .lg-recip__row--note { grid-template-columns: 36px 1fr; }
            .lg-recip__num { display: flex; align-items: center; padding: .65em 0; font-size: .85em; opacity: .55; font-variant-numeric: tabular-nums; }
            .lg-recip__row input { width: 100%; padding: .55em .75em; font-size: .95em; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; background: #fff; color: inherit; box-sizing: border-box; }
            .lg-recip__row textarea { width: 100%; min-height: 2.4em; padding: .5em .75em; font-size: .88em; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; resize: vertical; font-family: inherit; box-sizing: border-box; }
            .lg-recip__add-note { background: none; border: none; color: var(--lg-sage, #87986A); font-size: .82em; padding: .25em 0; cursor: pointer; text-align: left; }
            .lg-recip__add-note:hover { text-decoration: underline; }
            .lg-recip__status { margin-top: .8em; padding: .6em .9em; border-radius: 6px; font-size: .9em; }
            .lg-recip__status.is-ok { background: rgba(135,152,106,0.12); color: #2d4f2a; }
            .lg-recip__status.is-warn { background: rgba(255,200,80,0.18); color: #92400e; }

            /* Logged-in: stripped UI — kill the mode toggle, recipient repeater,
               and buyer-email field. Codes attach to their account and they
               manage them at /my-gifts/ after purchase. */
            .lg-gift__loggedin-banner { max-width: 720px; margin: 0 auto 1em; padding: .65em 1em; background: rgba(135,152,106,0.12); border: 1px solid rgba(135,152,106,0.3); border-radius: 8px; font-size: .92em; }
            .lg-gift__loggedin-banner a { color: var(--lg-sage, #87986A); font-weight: 600; }
            .lg-gift--logged-in [data-lg-mode-section],
            .lg-gift--logged-in [data-lg-buyer-email-section] { display: none !important; }

            /* Hide step 4 buyer-email when "Log in to manage" mode is active —
               we already have the user's email from their account. */
            .lg-gift--mode-managed [data-lg-buyer-email-section] { display: none !important; }

            /* Consent modal */
            .lg-consent { position: fixed !important; inset: 0 !important; z-index: 2147483600 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; }
            .lg-consent[hidden] { display: none !important; }
            .lg-consent__close { position: absolute; top: .55em; right: .55em; width: 2em; height: 2em; padding: 0; background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 50%; font-size: 1.35em; line-height: 1; cursor: pointer; color: #444; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
            .lg-consent__close:hover { color: #000; background: #f5f5f5; }
            .lg-consent__card { position: relative; }
            .lg-consent__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.45); }
            .lg-consent__card { position: relative; max-width: 420px; width: 100%; padding: 1.6em 1.5em; background: #fff; border-radius: 12px; box-shadow: 0 12px 40px rgba(0,0,0,0.3); color: #1f1d1a; }
            .lg-consent__title { margin: 0 0 .55em; font-size: 1.25em; font-weight: 700; }
            .lg-consent__body { margin: 0 0 1em; font-size: .95em; line-height: 1.5; color: #333; }
            .lg-consent__check { display: flex; align-items: flex-start; gap: .55em; padding: .75em .9em; margin-bottom: 1.1em; background: rgba(236,179,81,0.08); border: 1px solid rgba(236,179,81,0.3); border-radius: 6px; cursor: pointer; font-size: .9em; line-height: 1.4; }
            .lg-consent__check input { margin-top: .15em; flex-shrink: 0; }
            .lg-consent__actions { display: flex; gap: .65em; justify-content: flex-end; }
            .lg-consent__btn { padding: .6em 1.15em; border-radius: 6px; font-weight: 600; font-size: .92em; cursor: pointer; border: none; transition: opacity .15s, background .15s; }
            .lg-consent__btn--ghost { background: transparent; border: 1.5px solid rgba(0,0,0,0.2); color: #1f1d1a; }
            .lg-consent__btn--ghost:hover { background: rgba(0,0,0,0.04); }
            .lg-consent__btn--primary { background: var(--lg-amber, #ECB351); color: #1f1d1a; }
            .lg-consent__btn--primary:hover { opacity: .88; }
            .lg-consent__btn:disabled { opacity: .55; cursor: default; }
            .lg-consent__error { margin: .9em 0 0; font-size: .88em; color: #b91c1c; }

            /* Redirect overlay shown immediately on first checkout click —
               blocks the page so a frantic second click can't fire a second
               Stripe session before the embedded form is ready. */
            .lg-redirect { position: fixed !important; inset: 0 !important; z-index: 2147483647 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; background: rgba(0,0,0,0.55) !important; }
            .lg-redirect[hidden] { display: none !important; }
            .lg-redirect__card { background: #fff; padding: 1.8em 2em; border-radius: 12px; max-width: 380px; text-align: center; box-shadow: 0 16px 50px rgba(0,0,0,0.35); color: #1f1d1a; }
            .lg-redirect__spinner { width: 38px; height: 38px; margin: 0 auto 1em; border: 3px solid rgba(0,0,0,0.1); border-top-color: var(--lg-amber, #ECB351); border-radius: 50%; animation: lg-redirect-spin .85s linear infinite; }
            @keyframes lg-redirect-spin { to { transform: rotate(360deg); } }
            .lg-redirect__title { font-weight: 600; font-size: 1.05em; margin-bottom: .25em; }
            .lg-redirect__sub { font-size: .88em; color: #555; line-height: 1.4; }
            .lg-gift--checkout-locked .lg-gift__panel { pointer-events: none; opacity: .6; }
            .lg-gift--checkout-locked .lg-gift__submit { pointer-events: none; opacity: .55; cursor: not-allowed; }

            /* Pay modal styles (.lg-pay-modal* + .lg-stripe-modal* + .lg-modal-processing*)
               are defined in assets/lg-shortcodes.css — single source of truth shared
               with [lg_join]. */

            /* Anonymous-checkout warning modal — same pattern as the consent
               modal so it escapes BuddyBoss containing-block traps. */
            .lg-anonwarn { position: fixed !important; inset: 0 !important; z-index: 2147483600 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; }
            .lg-anonwarn[hidden] { display: none !important; }
            .lg-anonwarn__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.6); }
            .lg-anonwarn__card { position: relative; background: #fff; border-radius: 12px; padding: 1.6em 1.5em; max-width: 460px; width: 100%; box-shadow: 0 16px 50px rgba(0,0,0,0.4); color: #1f1d1a; }
            .lg-anonwarn__close { position: absolute; top: .55em; right: .55em; width: 2em; height: 2em; padding: 0; background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 50%; font-size: 1.35em; line-height: 1; cursor: pointer; color: #444; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
            .lg-anonwarn__close:hover { color: #000; background: #f5f5f5; }
            .lg-anonwarn__title { margin: 0 0 .55em; font-size: 1.2em; font-weight: 700; line-height: 1.3; padding-right: 2em; }
            .lg-anonwarn__body { margin: 0 0 1em; font-size: .95em; line-height: 1.5; color: #333; }
            .lg-anonwarn__check { display: flex; align-items: flex-start; gap: .6em; padding: .8em .9em; margin-bottom: 1.1em; background: rgba(255,200,80,0.14); border: 1px solid rgba(255,180,40,0.45); border-radius: 6px; cursor: pointer; font-size: .92em; line-height: 1.4; font-weight: 500; }
            .lg-anonwarn__check input { margin-top: .15em; flex-shrink: 0; }
            .lg-anonwarn__actions { display: flex; gap: .65em; justify-content: flex-end; }
            .lg-anonwarn__btn { padding: .6em 1.15em; border-radius: 6px; font-weight: 600; font-size: .92em; cursor: pointer; border: none; transition: opacity .15s, background .15s; }
            .lg-anonwarn__btn--ghost { background: transparent; border: 1.5px solid rgba(0,0,0,0.2); color: #1f1d1a; }
            .lg-anonwarn__btn--ghost:hover { background: rgba(0,0,0,0.04); }
            .lg-anonwarn__btn--primary { background: var(--lg-amber, #ECB351); color: #1f1d1a; }
            .lg-anonwarn__btn--primary:hover { opacity: .88; }
            .lg-anonwarn__btn:disabled { opacity: .45; cursor: not-allowed; }

            /* Same body-class lockdown so the redirect overlay and consent
               modal also escape any transformed ancestors. */
            .lg-redirect, .lg-consent { z-index: 2147483600 !important; }
            body.lg-modal-open { overflow: hidden !important; }
        </style>

        <div class="lg-existacct" data-lg-existacct-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-existacct-title">
            <div class="lg-existacct__backdrop" data-lg-existacct-cancel></div>
            <div class="lg-existacct__card">
                <button type="button" class="lg-existacct__close" data-lg-existacct-cancel aria-label="Close">&times;</button>
                <h3 id="lg-existacct-title" class="lg-existacct__title">This email already has an account</h3>
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
            .lg-existacct__alt a { color: var(--lg-sage, #87986A); }

            .lg-pwd-wrap { position: relative; display: block; }
            .lg-pwd-wrap input { width: 100%; padding-right: 2.6em !important; }
            .lg-pwd-eye { position: absolute; right: .35em; top: 50%; transform: translateY(-50%); width: 2em; height: 2em; padding: 0; background: transparent; border: none; cursor: pointer; opacity: .55; font-size: 1.05em; line-height: 1; display: flex; align-items: center; justify-content: center; }
            .lg-pwd-eye:hover { opacity: .9; }
            .lg-pwd-mismatch { color: #b91c1c !important; font-size: .85em; margin-top: .3em; }

                /* Post-payment processing lock — shown when Stripe fires onComplete.
                   Sits above everything (max z-index) so the user cannot click into
                   nav, close the tab silently (beforeunload warns), or otherwise
                   navigate away while the post-pay redirect to /welcome/ is in flight. */
                .lg-processing { position: fixed !important; inset: 0 !important; z-index: 2147483647 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; background: rgba(0,0,0,0.85); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
                .lg-processing[hidden] { display: none !important; }
                .lg-processing__card { background: #fff; border-radius: 12px; padding: 2em 1.8em; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,0.5); color: #1f1d1a; }
                .lg-processing__spinner { width: 44px; height: 44px; margin: 0 auto 1em; border: 4px solid rgba(0,0,0,0.1); border-top-color: var(--lg-amber, #ECB351); border-radius: 50%; animation: lg-processing-spin 0.9s linear infinite; }
                .lg-processing__title { margin: 0 0 .6em; font-size: 1.15em; font-weight: 700; }
                .lg-processing__body  { margin: 0; font-size: .92em; line-height: 1.5; color: #444; }
                @keyframes lg-processing-spin { to { transform: rotate(360deg); } }
                /* Pay-modal lock styles live in assets/lg-shortcodes.css under
                   .lg-pay-modal[data-lg-locked="1"]. */
        </style>
        <!-- Basil release of Stripe.js — required for stripe.initCheckout()
             (custom UI mode). Must match the API-version pin on the Slim side. -->
        <script src="https://js.stripe.com/basil/stripe.js"></script>
        <script>
        (function(){
            function lgGetRef() { try { return localStorage.getItem('lg_ref') || ''; } catch(_) { return ''; } }

            const ENDPOINTS = <?php echo $endpointsJs; ?>;
            const CONFIG    = <?php echo $configJs; ?>;

            // Capture ?ref= affiliate slug on landing, persist across sessions,
            // and fire a server-side click event so misses are visible in reports.
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
            const tierDropdown    = document.querySelector('[data-lg-tier-dropdown]');
            const tierTrigger    = document.querySelector('[data-lg-tier-trigger]');
            const tierOptionsEl  = document.querySelector('[data-lg-tier-options]');
            const tierSelectedEl = document.querySelector('[data-lg-tier-selected]');
            const durationsEl = document.querySelector('[data-lg-gift-durations]');
            const presetsEl   = document.querySelector('[data-lg-gift-presets]');
            const progressEl  = document.querySelector('[data-lg-gift-progress]');
            const progressFill= document.querySelector('[data-lg-gift-progress-fill]');
            const progressLab = document.querySelector('[data-lg-gift-progress-label]');
            const summarySub  = document.querySelector('[data-lg-gift-line-sub]');
            const summaryDiscRow = document.querySelector('[data-lg-gift-line-disc-row]');
            const summaryDisc = document.querySelector('[data-lg-gift-line-disc]');
            const summaryTot  = document.querySelector('[data-lg-gift-line-total]');
            const savingsEl   = document.querySelector('[data-lg-gift-savings]');
            const submitBtn   = document.querySelector('[data-lg-gift-submit]');
            const ctaSpan     = document.querySelector('[data-lg-gift-cta]');
            const errorEl     = document.querySelector('[data-lg-gift-error]');
            const checkoutEl  = document.querySelector('[data-lg-gift-checkout]');
            const giftCustomEl   = document.querySelector('[data-lg-gift-checkout-custom]');
            const giftPeEl       = document.querySelector('[data-lg-gift-payment-element]');
            const giftPayBt      = document.querySelector('[data-lg-gift-checkout-pay]');
            const giftPayLabelEl = document.querySelector('[data-lg-gift-pay-label]');
            const giftCustomErrorEl = document.querySelector('[data-lg-gift-checkout-error]');
            const giftModalProcessingEl = document.querySelector('[data-lg-gift-modal-processing]');
            const giftPayAmountEl   = document.querySelector('[data-lg-gift-pay-amount]');
            const giftPaySublabelEl = document.querySelector('[data-lg-gift-pay-sublabel]');
            const qtyInput    = document.querySelector('input[name="quantity"]');
            const emailInput  = document.querySelector('input[name="email"]');

            let products            = [];
            let bulkTiers          = [];
            let selectedRef        = null;
            // selectedDuration: { months, priceId, baseUnitCents }
            // months=0 means yearly (uses yearly price directly, no multiplication)
            let selectedDuration   = null;
            let customMonthsVal    = 3; // last value in the custom-months input
            let stripe             = null;
            let mountedSession     = null;
            let giftCustomCheckout = null;
            let giftPaymentElement = null;

            const dollars = (cents) => { const n = cents / 100; return '$' + (n % 1 === 0 ? n.toFixed(0) : n.toFixed(2)); };
            const dollarsRound = (cents) => '$' + Math.round(cents / 100);
            function showError(msg){ errorEl.textContent = msg || ''; }

            function selectedTier(){
                return products.find(p => p.ref === selectedRef) || null;
            }
            // Price helpers — derive yearly and monthly prices from a product's prices array.
            const monthlyPrice = (prod) => prod ? (prod.prices.find(p => p.type === 'recurring' && p.interval === 'month') || null) : null;
            const yearlyPrice  = (prod) => prod ? (prod.prices.find(p => p.type === 'recurring' && p.interval === 'year')  || null) : null;

            // Scale bulk discount linearly with duration: 1 month = 1/12, 1 year = 12/12 = full.
            function scaledDiscountPct(qty, months){
                let raw = 0;
                bulkTiers.forEach(t => { if (qty >= t.min_qty && t.discount_pct > raw) raw = t.discount_pct; });
                return Math.round(raw * Math.min(months, 12) / 12);
            }

            function discountPctFor(qty){
                if (!selectedDuration) return 0;
                return scaledDiscountPct(qty, selectedDuration.months || 12);
            }

            function nextTier(qty){
                return bulkTiers.filter(t => qty < t.min_qty).sort((a,b) => a.min_qty - b.min_qty)[0] || null;
            }

            // Custom dropdown: populate options and wire interactions.
            function renderTiers(){
                if (!tierOptionsEl) return;
                tierOptionsEl.innerHTML = '';
                const eligible = products.filter(p => monthlyPrice(p) || yearlyPrice(p));
                eligible.forEach(function(prod){
                    const mp = monthlyPrice(prod);
                    const yp = yearlyPrice(prod);
                    let label = prod.name;
                    if (mp && yp) label += ' — ' + dollars(mp.unit_amount_cents) + '/mo · ' + dollars(yp.unit_amount_cents) + '/yr';
                    else if (mp)  label += ' — ' + dollars(mp.unit_amount_cents) + '/mo';
                    const opt = document.createElement('div');
                    opt.className = 'lg-gift__tier-option';
                    opt.setAttribute('role', 'option');
                    opt.dataset.ref = prod.ref;
                    opt.textContent = label;
                    opt.addEventListener('click', () => { selectTier(prod.ref); closeTierDropdown(); });
                    tierOptionsEl.appendChild(opt);
                });
                // Toggle open/close on trigger click
                if (tierTrigger) {
                    tierTrigger.addEventListener('click', () => {
                        const isOpen = tierTrigger.getAttribute('aria-expanded') === 'true';
                        isOpen ? closeTierDropdown() : openTierDropdown();
                    });
                }
                // Close on outside click
                document.addEventListener('click', function(e){
                    if (tierDropdown && !tierDropdown.contains(e.target)) closeTierDropdown();
                });
                // Default: popular tier or first
                const def = eligible.find(p => p.ref === CONFIG.popular) || eligible[0];
                if (def) selectTier(def.ref);
            }

            function openTierDropdown(){
                if (tierOptionsEl) tierOptionsEl.hidden = false;
                if (tierTrigger)   tierTrigger.setAttribute('aria-expanded', 'true');
            }
            function closeTierDropdown(){
                if (tierOptionsEl) tierOptionsEl.hidden = true;
                if (tierTrigger)   tierTrigger.setAttribute('aria-expanded', 'false');
            }

            function selectTier(ref){
                selectedRef = ref;
                // Update trigger label
                const prod = selectedTier();
                if (tierSelectedEl && prod) {
                    const mp = monthlyPrice(prod);
                    const yp = yearlyPrice(prod);
                    let label = prod.name;
                    if (mp && yp) label += ' — ' + dollars(mp.unit_amount_cents) + '/mo · ' + dollars(yp.unit_amount_cents) + '/yr';
                    else if (mp)  label += ' — ' + dollars(mp.unit_amount_cents) + '/mo';
                    tierSelectedEl.textContent = label;
                }
                // Highlight selected option
                if (tierOptionsEl) {
                    tierOptionsEl.querySelectorAll('.lg-gift__tier-option').forEach(el => {
                        el.classList.toggle('is-selected', el.dataset.ref === ref);
                    });
                }
                renderDurations(prod);
                selectDuration('year');
            }

            // Build the three duration rows: 1 year / 1 month / custom N months.
            function renderDurations(prod){
                if (!durationsEl || !prod) return;
                const mp = monthlyPrice(prod);
                const yp = yearlyPrice(prod);

                durationsEl.innerHTML = '';

                function makeRow(key, labelText, priceText, extraContent){
                    const row = document.createElement('label');
                    row.className = 'lg-gift__dur-row';
                    row.dataset.durKey = key;
                    const radio = document.createElement('input');
                    radio.type = 'radio'; radio.name = 'duration'; radio.value = key;
                    row.appendChild(radio);
                    const lbl = document.createElement('span');
                    lbl.className = 'lg-gift__dur-label';
                    lbl.textContent = labelText;
                    row.appendChild(lbl);
                    if (extraContent) row.appendChild(extraContent);
                    const pr = document.createElement('span');
                    pr.className = 'lg-gift__dur-price';
                    pr.dataset.durPriceKey = key;
                    pr.textContent = priceText;
                    row.appendChild(pr);
                    row.addEventListener('click', function(e){
                        if (e.target.tagName === 'INPUT' && e.target.type === 'number') return;
                        radio.checked = true;
                        selectDuration(key);
                    });
                    radio.addEventListener('change', () => selectDuration(key));
                    return row;
                }

                // 1-year row (uses yearly price directly)
                if (yp) {
                    durationsEl.appendChild(makeRow('year', '1 year', dollars(yp.unit_amount_cents) + ' / gift'));
                }

                // 1-month row
                if (mp) {
                    durationsEl.appendChild(makeRow('month', '1 month', dollars(mp.unit_amount_cents) + ' / gift'));
                }

                // Custom months row — stepper (− N +), price updates live
                if (mp) {
                    const stepper = document.createElement('div');
                    stepper.className = 'lg-gift__dur-stepper';

                    const decBtn = document.createElement('button');
                    decBtn.type = 'button'; decBtn.className = 'lg-gift__dur-step'; decBtn.textContent = '−';
                    decBtn.setAttribute('aria-label', 'Decrease months');

                    const numInput = document.createElement('input');
                    numInput.type = 'number'; numInput.min = '2'; numInput.max = '36';
                    numInput.value = String(customMonthsVal);
                    numInput.className = 'lg-gift__dur-custom-input';

                    const incBtn = document.createElement('button');
                    incBtn.type = 'button'; incBtn.className = 'lg-gift__dur-step'; incBtn.textContent = '+';
                    incBtn.setAttribute('aria-label', 'Increase months');

                    function updateCustomMonths(val){
                        customMonthsVal = Math.max(2, Math.min(36, val));
                        numInput.value = String(customMonthsVal);
                        const pr = durationsEl.querySelector('[data-dur-price-key="custom"]');
                        if (pr) pr.textContent = dollars(mp.unit_amount_cents * customMonthsVal) + ' / gift';
                        if (selectedDuration && selectedDuration.key === 'custom') {
                            selectedDuration.months = customMonthsVal;
                            selectedDuration.baseUnitCents = mp.unit_amount_cents * customMonthsVal;
                            selectedDuration.durationMonths = customMonthsVal;
                            renderPresets(); recompute();
                        }
                    }
                    decBtn.addEventListener('click', (e) => { e.stopPropagation(); updateCustomMonths(customMonthsVal - 1); });
                    incBtn.addEventListener('click', (e) => { e.stopPropagation(); updateCustomMonths(customMonthsVal + 1); });
                    numInput.addEventListener('input', function(){ updateCustomMonths(parseInt(this.value, 10) || 2); });

                    stepper.appendChild(decBtn);
                    stepper.appendChild(numInput);
                    stepper.appendChild(incBtn);

                    const customRow = makeRow('custom', 'Custom months', dollars(mp.unit_amount_cents * customMonthsVal) + ' / gift', stepper);
                    durationsEl.appendChild(customRow);
                }
            }

            function selectDuration(key){
                const prod  = selectedTier();
                const mp    = monthlyPrice(prod);
                const yp    = yearlyPrice(prod);

                if (key === 'year' && yp) {
                    selectedDuration = { key: 'year', months: 12, priceId: yp.stripe_price_id, baseUnitCents: yp.unit_amount_cents, durationMonths: null };
                } else if (key === 'month' && mp) {
                    selectedDuration = { key: 'month', months: 1, priceId: mp.stripe_price_id, baseUnitCents: mp.unit_amount_cents, durationMonths: 1 };
                } else if (key === 'custom' && mp) {
                    selectedDuration = { key: 'custom', months: customMonthsVal, priceId: mp.stripe_price_id, baseUnitCents: mp.unit_amount_cents * customMonthsVal, durationMonths: customMonthsVal };
                } else {
                    return;
                }

                if (durationsEl) {
                    durationsEl.querySelectorAll('.lg-gift__dur-row').forEach(el => {
                        const isMe = el.dataset.durKey === key;
                        el.classList.toggle('is-selected', isMe);
                        const r = el.querySelector('input[type="radio"]');
                        if (r) r.checked = isMe;
                    });
                }
                renderPresets();
                recompute();
            }

            function renderPresets(){
                presetsEl.innerHTML = '';
                const months = selectedDuration ? (selectedDuration.months || 12) : 12;
                // Always include 1; then one preset per bulk tier minimum.
                const stops = [1, ...bulkTiers.map(t => t.min_qty)];
                const seen = new Set();
                stops.forEach(function(qty){
                    if (seen.has(qty)) return;
                    seen.add(qty);
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'lg-gift__preset';
                    btn.dataset.qty = String(qty);
                    const bulkTier = bulkTiers.find(t => t.min_qty === qty);
                    const scaledPct = bulkTier ? scaledDiscountPct(qty, months) : 0;
                    btn.innerHTML = qty + (scaledPct > 0 ? ' <span class="lg-gift__preset-disc">' + scaledPct + '% off</span>' : '');
                    btn.addEventListener('click', () => { qtyInput.value = String(qty); recompute(); });
                    presetsEl.appendChild(btn);
                });
            }

            function highlightPreset(qty){
                presetsEl.querySelectorAll('.lg-gift__preset').forEach(b => {
                    b.classList.toggle('is-active', parseInt(b.dataset.qty, 10) === qty);
                });
            }

            function renderProgress(qty){
                if (progressEl) progressEl.hidden = true;
            }

            function recompute(){
                const dur = selectedDuration;
                const qty = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                qtyInput.value = String(qty);
                highlightPreset(qty);
                if (!dur) {
                    summarySub.textContent  = '—';
                    summaryTot.textContent  = '—';
                    summaryDiscRow.hidden   = true;
                    savingsEl.hidden        = true;
                    ctaSpan.textContent     = 'Continue to checkout';
                    return;
                }
                const durLabel   = dur.key === 'year' ? '1 year' : dur.key === 'month' ? '1 month' : dur.months + ' months';
                const subCents   = dur.baseUnitCents * qty;
                const pct        = discountPctFor(qty);
                const discCents  = Math.round(subCents * pct / 100);
                const totalCents = subCents - discCents;

                summarySub.textContent = qty + ' × ' + dollars(dur.baseUnitCents) + ' (' + durLabel + ') = ' + dollars(subCents);
                if (pct > 0) {
                    summaryDiscRow.hidden = false;
                    summaryDisc.textContent = '−' + dollars(discCents) + '  (' + pct + '% off)';
                    savingsEl.hidden = false;
                    // Dynamic "next tier" nudge
                    const next = nextTier(qty);
                    if (next) {
                        const nextPct = scaledDiscountPct(next.min_qty, dur.months || 12);
                        const need = next.min_qty - qty;
                        savingsEl.textContent = "You're saving " + dollarsRound(discCents) + ' — add ' + need + ' more code' + (need === 1 ? '' : 's') + ' to unlock ' + nextPct + '% off.';
                    } else {
                        savingsEl.textContent = "You're saving " + dollarsRound(discCents) + ' with bulk pricing.';
                    }
                } else {
                    summaryDiscRow.hidden = true;
                    const next = nextTier(qty);
                    if (next) {
                        const nextPct = scaledDiscountPct(next.min_qty, dur.months || 12);
                        savingsEl.hidden = false;
                        savingsEl.textContent = 'Add ' + (next.min_qty - qty) + ' more code' + (next.min_qty - qty === 1 ? '' : 's') + ' to unlock ' + nextPct + '% off.';
                    } else {
                        savingsEl.hidden = true;
                    }
                }
                summaryTot.textContent = dollars(totalCents);
                ctaSpan.textContent = 'Continue to checkout · ' + qty + ' × ' + durLabel + ' · ' + dollars(totalCents);
                renderProgress(qty);
            }

            async function loadProducts(){
                showError('');
                try {
                    const res  = await fetch(ENDPOINTS.products);
                    const json = await res.json();
                    products  = json.products || [];
                    bulkTiers = (json.bulk_discount_tiers || []).slice().sort((a,b) => a.min_qty - b.min_qty);
                    // renderTiers → selectTier → renderDurations → selectDuration → renderPresets + recompute
                    renderTiers();
                    applyResumeState();
                } catch (err) {
                    showError('Failed to load tiers: ' + err.message);
                }
            }

            // Reapply gift-form state captured before a login-modal reload
            // (see serializeGiftState below). Runs after products + tiers
            // are populated so selectTier/selectDuration have something to
            // hook into. Strips the lg_resume params from the URL after
            // applying so a manual refresh doesn't re-apply stale state.
            function applyResumeState(){
                let params;
                try { params = new URLSearchParams(window.location.search); }
                catch (e) { return; }
                if (params.get('lg_resume') !== '1') return;
                const tier = params.get('tier');
                const dur  = params.get('dur');
                const cm   = parseInt(params.get('cm') || '', 10);
                const qty  = parseInt(params.get('qty') || '', 10);
                if (cm && cm >= 2 && cm <= 36) customMonthsVal = cm;
                if (tier && products.some(p => p.ref === tier)) selectTier(tier);
                if (dur === 'year' || dur === 'month' || dur === 'custom') {
                    try { selectDuration(dur); } catch (e) {}
                }
                if (qty && qty >= 1 && qtyInput) {
                    qtyInput.value = String(qty);
                    try { recompute(); } catch (e) {}
                }
                ['lg_resume','tier','dur','cm','qty'].forEach(k => params.delete(k));
                const clean = window.location.pathname + (params.toString() ? '?' + params : '') + window.location.hash;
                if (window.history && window.history.replaceState) {
                    window.history.replaceState({}, '', clean);
                }
            }

            // Serialize current gift selections into a query string the
            // post-reload page can pick up via applyResumeState. Used by
            // the inline login modal so a returning member doesn't lose
            // their tier/duration/qty when we reload to render the
            // canonical logged-in state.
            window.lgSerializeGiftState = function(){
                const p = new URLSearchParams();
                p.set('lg_resume', '1');
                if (selectedRef) p.set('tier', selectedRef);
                if (selectedDuration && selectedDuration.key) p.set('dur', selectedDuration.key);
                if (customMonthsVal) p.set('cm', String(customMonthsVal));
                if (qtyInput && qtyInput.value) p.set('qty', String(qtyInput.value));
                return p.toString();
            };

            // Quantity stepper buttons
            document.querySelectorAll('[data-lg-qty-step]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const step = parseInt(btn.dataset.lgQtyStep, 10) || 1;
                    qtyInput.value = String(Math.max(1, (parseInt(qtyInput.value, 10) || 1) + step));
                    recompute();
                });
            });
            qtyInput.addEventListener('input', recompute);

            // One-shot guard: once a Stripe checkout is in flight or mounted,
            // refuse to start another. The overlay also blocks pointer events
            // so a panicked double-tap can't slip through before the JS guard
            // updates.
            let checkoutInProgress = false;
            const redirectOverlay = document.querySelector('[data-lg-redirect-overlay]');
            const checkoutModal   = document.querySelector('[data-lg-checkout-modal]');

            // Move modal/overlay elements to <body> so ancestor transforms
            // (BuddyBoss theme uses them) can't trap position:fixed.
            const anonWarnModal = document.querySelector('[data-lg-anonwarn-modal]');
            [redirectOverlay, checkoutModal, document.querySelector('[data-lg-consent-modal]'), anonWarnModal].forEach(el => {
                if (el && el.parentNode !== document.body) document.body.appendChild(el);
            });

            // Default mode is "managed" — apply its UI state on load so
            // anonymous visitors land with the auth panel open and step 4
            // (your email) hidden. Skipped for already-logged-in users
            // since their mode-section is hidden by CSS.
            (function initManagedDefault(){
                if (CONFIG.loggedIn) return;
                const root  = document.querySelector('.lg-gift');
                const block = document.querySelector('[data-lg-auth-block]');
                if (root)  root.classList.add('lg-gift--mode-managed');
                if (block) block.hidden = false;
            })();

            let redirectOverlayShownAt = 0;
            function lockCheckout() {
                checkoutInProgress = true;
                if (redirectOverlay) {
                    // Always re-park as the LAST child of <body> so the theme
                    // can't paint anything above it.
                    if (redirectOverlay.parentNode !== document.body || redirectOverlay !== document.body.lastChild) {
                        document.body.appendChild(redirectOverlay);
                    }
                    redirectOverlay.hidden = false;
                    redirectOverlayShownAt = Date.now();
                }
                document.body.classList.add('lg-modal-open');
                const root = document.querySelector('.lg-gift');
                if (root) root.classList.add('lg-gift--checkout-locked');
                submitBtn.disabled = true;
            }
            function hideRedirectOverlaySoon() {
                if (!redirectOverlay) return;
                const elapsed = Date.now() - redirectOverlayShownAt;
                const remain  = Math.max(0, 700 - elapsed);
                setTimeout(() => { redirectOverlay.hidden = true; }, remain);
            }
            function unlockCheckout() {
                checkoutInProgress = false;
                if (redirectOverlay) redirectOverlay.hidden = true;
                if (checkoutModal && checkoutModal.hidden) document.body.classList.remove('lg-modal-open');
                const root = document.querySelector('.lg-gift');
                if (root) root.classList.remove('lg-gift--checkout-locked');
                submitBtn.disabled = false;
            }
            function showGiftCheckoutError(msg) {
                if (!giftCustomErrorEl) return;
                if (msg) {
                    giftCustomErrorEl.textContent = msg;
                    giftCustomErrorEl.hidden = false;
                } else {
                    giftCustomErrorEl.textContent = '';
                    giftCustomErrorEl.hidden = true;
                }
            }

            function teardownGiftMount() {
                if (mountedSession) {
                    try { mountedSession.destroy(); } catch (_) {}
                    mountedSession = null;
                }
                if (giftPaymentElement) {
                    try { giftPaymentElement.unmount(); } catch (_) {}
                    giftPaymentElement = null;
                }
                giftCustomCheckout = null;
                if (checkoutEl) checkoutEl.innerHTML = '';
                if (giftPeEl) giftPeEl.innerHTML = '';
                if (giftCustomEl) giftCustomEl.hidden = true;
                if (checkoutEl) checkoutEl.hidden = false;
                showGiftCheckoutError('');
                if (giftPayBt) giftPayBt.disabled = true;
            }

            async function mountGiftEmbeddedCheckout(clientSecret) {
                if (giftCustomEl) giftCustomEl.hidden = true;
                if (checkoutEl) checkoutEl.hidden = false;
                mountedSession = await stripe.initEmbeddedCheckout({
                    clientSecret: clientSecret,
                    onComplete: showProcessingOverlay,
                });
                mountedSession.mount(checkoutEl);
            }

            async function mountGiftCustomCheckout(clientSecret) {
                // Same pattern as join: keep custom block hidden behind the
                // processing overlay until paymentElement is 'ready'.
                if (checkoutEl) checkoutEl.hidden = true;
                if (giftCustomEl) giftCustomEl.hidden = true;
                if (giftModalProcessingEl) giftModalProcessingEl.hidden = false;

                giftCustomCheckout = await stripe.initCheckout({
                    fetchClientSecret: async () => clientSecret,
                });

                giftCustomCheckout.on('change', (session) => {
                    if (!giftPayBt) return;
                    giftPayBt.disabled = !session.canConfirm;
                    const cents = session && session.total && session.total.total
                        ? session.total.total.amount
                        : null;
                    const formatted = (typeof cents === 'number' && !isNaN(cents))
                        ? '$' + (cents / 100).toFixed(cents % 100 === 0 ? 0 : 2)
                        : '';
                    if (giftPayLabelEl) giftPayLabelEl.textContent = formatted ? 'Pay ' + formatted : 'Pay';
                    if (giftPayAmountEl) { giftPayAmountEl.textContent = formatted; giftPayAmountEl.hidden = !formatted; }
                    const tier  = selectedTier();
                    const qty   = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                    if (giftPaySublabelEl) {
                        const giftSub = tier ? (tier.name + " · " + qty + (qty === 1 ? " gift" : " gifts")) : "";
                        giftPaySublabelEl.textContent = giftSub;
                        giftPaySublabelEl.hidden = !giftSub;
                    }
                });

                giftPaymentElement = giftCustomCheckout.createPaymentElement();
                giftPaymentElement.on('ready', () => {
                    if (giftCustomEl) giftCustomEl.hidden = false;
                    if (giftModalProcessingEl) giftModalProcessingEl.hidden = true;
                });
                giftPaymentElement.mount(giftPeEl);
            }

            async function onGiftPayClick() {
                if (!giftCustomCheckout || !giftPayBt || giftPayBt.disabled) return;
                showGiftCheckoutError('');

                if (checkoutModal) checkoutModal.dataset.lgLocked = '1';

                const origLabel = giftPayLabelEl ? giftPayLabelEl.textContent : '';
                giftPayBt.disabled = true;
                if (giftModalProcessingEl) giftModalProcessingEl.hidden = false;

                try {
                    const result = await giftCustomCheckout.confirm();
                    if (result && result.error) {
                        if (checkoutModal) delete checkoutModal.dataset.lgLocked;
                        if (giftModalProcessingEl) giftModalProcessingEl.hidden = true;
                        showGiftCheckoutError(result.error.message || 'Payment failed. Please try again.');
                        if (giftPayLabelEl) giftPayLabelEl.textContent = origLabel || 'Pay';
                        giftPayBt.disabled = false;
                        return;
                    }

                    // Success — gift codes are delivered by email, so we don't
                    // redirect away. Show an in-page success modal and let the
                    // webhook + /v1/return fast-path provision asynchronously.
                    let sessionId = '';
                    try { sessionId = (giftCustomCheckout.session && giftCustomCheckout.session().id) || ''; } catch (_) {}

                    // Fire-and-forget kick of the idempotent fast-path so emails
                    // go out immediately rather than waiting for the webhook.
                    if (sessionId) {
                        try {
                            fetch('<?php echo esc_js( esc_url_raw( home_url( '/billing/v1/return' ) ) ); ?>?session_id=' + encodeURIComponent(sessionId), {
                                method: 'GET',
                                credentials: 'omit',
                                redirect: 'manual',
                                keepalive: true,
                            }).catch(() => {});
                        } catch (_) {}
                    }

                    teardownGiftMount();
                    if (checkoutModal) {
                        delete checkoutModal.dataset.lgLocked;
                        checkoutModal.hidden = true;
                    }
                    paymentCompleted = true;
                    window.removeEventListener('beforeunload', preventNavWhileProcessing);

                    showGiftSuccessModal(pendingEmail || '');
                } catch (err) {
                    if (checkoutModal) delete checkoutModal.dataset.lgLocked;
                    if (giftModalProcessingEl) giftModalProcessingEl.hidden = true;
                    showGiftCheckoutError('Network error: ' + (err && err.message ? err.message : err));
                    if (giftPayLabelEl) giftPayLabelEl.textContent = origLabel || 'Pay';
                    giftPayBt.disabled = false;
                }
            }
            if (giftPayBt) giftPayBt.addEventListener('click', onGiftPayClick);

            function closeCheckoutModal() {
                if (checkoutModal) checkoutModal.hidden = true;
                document.body.classList.remove('lg-modal-open');
                teardownGiftMount();
                unlockCheckout();
                recompute();
            }

            const giftSuccessModal = document.querySelector('[data-lg-gift-success]');
            const giftSuccessEmailEl = document.querySelector('[data-lg-gift-success-email]');
            if (giftSuccessModal && giftSuccessModal.parentNode !== document.body) {
                document.body.appendChild(giftSuccessModal);
            }
            function showGiftSuccessModal(email) {
                if (giftSuccessEmailEl && email) giftSuccessEmailEl.textContent = email;
                if (giftSuccessModal) giftSuccessModal.hidden = false;
                document.body.classList.add('lg-modal-open');
            }
            document.querySelectorAll('[data-lg-gift-success-close]').forEach(el => {
                el.addEventListener('click', () => {
                    if (giftSuccessModal) giftSuccessModal.hidden = true;
                    document.body.classList.remove('lg-modal-open');
                    paymentCompleted = false;
                    paymentInFlight = false;
                    unlockCheckout();
                    recompute();
                });
            });

            // Two-stage close protection for the Stripe checkout modal:
            //
            //   1. paymentInFlight — set true when the modal opens with a mounted
            //      Stripe iframe. Any close attempt while this is true triggers
            //      a confirm() dialog warning the user that their card may
            //      already be charged (Stripe submits to the bank the instant
            //      they click Pay, before onComplete fires).
            //
            //   2. paymentCompleted — set true when Stripe fires onComplete.
            //      At this point close is suppressed entirely; we show the
            //      processing overlay and let Stripe's redirect to /welcome/
            //      finish the flow. A beforeunload listener also blocks tab
            //      close until the redirect lands.
            //
            // The pre-onComplete confirm() is the critical fix for the bug
            // where users close the modal mid-charge and end up paid but with
            // no account / no redirect / no entitlement.
            let paymentInFlight = false;
            let paymentCompleted = false;
            const processingOverlay = document.querySelector('[data-lg-gift-processing]');
            if (processingOverlay && processingOverlay.parentNode !== document.body) {
                document.body.appendChild(processingOverlay);
            }
            function markPaymentInFlight() {
                paymentInFlight = true;
                window.addEventListener('beforeunload', preventNavWhileProcessing);
            }
            function showProcessingOverlay() {
                paymentCompleted = true;
                if (processingOverlay) processingOverlay.hidden = false;
                document.body.classList.add('lg-modal-open');
                // Stripe is about to navigate parent to return_url — remove the
                // beforeunload guard so the intended redirect sails through
                // without the browser's "Leave site?" prompt.
                window.removeEventListener('beforeunload', preventNavWhileProcessing);
            }
            function preventNavWhileProcessing(e) {
                if (!paymentInFlight && !paymentCompleted) return;
                e.preventDefault();
                e.returnValue = '';
                return '';
            }

            document.querySelectorAll('[data-lg-checkout-close]').forEach(el => {
                el.addEventListener('click', () => {
                    // Paid: show overlay, never close.
                    if (paymentCompleted) { showProcessingOverlay(); return; }
                    // Stripe mounted: hard-refuse close. CSS already hides the
                    // X and disables backdrop pointer events; this guard
                    // covers any other dispatch (Esc keybinds, programmatic).
                    if (paymentInFlight) return;
                    closeCheckoutModal();
                });
            });

            // Anon-warn modal wiring
            const anonCheck   = document.querySelector('[data-lg-anonwarn-confirm-check]');
            const anonConfirm = document.querySelector('[data-lg-anonwarn-confirm]');
            if (anonCheck && anonConfirm) {
                anonCheck.addEventListener('change', () => { anonConfirm.disabled = !anonCheck.checked; });
            }
            document.querySelectorAll('[data-lg-anonwarn-cancel]').forEach(el => {
                el.addEventListener('click', () => {
                    if (anonWarnModal) anonWarnModal.hidden = true;
                    if (anonCheck) anonCheck.checked = false;
                    if (anonConfirm) anonConfirm.disabled = true;
                    document.body.classList.remove('lg-modal-open');
                });
            });
            if (anonConfirm) {
                anonConfirm.addEventListener('click', () => {
                    if (anonWarnModal) anonWarnModal.hidden = true;
                    if (anonCheck) anonCheck.checked = false;
                    anonConfirm.disabled = true;
                    document.body.classList.remove('lg-modal-open');
                    launchCheckout();
                });
            }

            // Per-click checkout context — captured when submit fires, used
            // by launchCheckout() (which can be called directly OR after
            // the user acknowledges the anon-warn modal).
            let pendingPrice = null, pendingQty = 0, pendingEmail = '';

            async function launchCheckout(){
                lockCheckout();
                if (mountedSession) { try { mountedSession.destroy(); } catch (e) {} mountedSession = null; }
                if (checkoutEl) checkoutEl.innerHTML = '';
                const origCta = ctaSpan.textContent;
                ctaSpan.textContent = 'Loading…';

                await runCheckout(pendingPrice, pendingQty, pendingEmail, origCta);
            }

            submitBtn.addEventListener('click', async function(){
                if (checkoutInProgress) return;

                showError('');
                const price = selectedDuration;
                if (!price) { showError('Please pick a tier and duration.'); return; }

                const qty   = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                const email = (emailInput.value || '').trim();
                if (!email) { showError('Email is required.'); emailInput.focus(); return; }
                // Confirm-email match (only enforced when buyer-email section is
                // visible — i.e. self mode, anonymous buyer). Logged-in or
                // managed-mode buyers don't see the confirm field.
                if (buyerEmailConfEl && !document.querySelector('.lg-gift--logged-in') && sendMode !== 'managed') {
                    const confirmVal = (buyerEmailConfEl.value || '').trim().toLowerCase();
                    if (email.toLowerCase() !== confirmVal) {
                        showError('Emails don’t match — please re-enter your confirmation email.');
                        buyerEmailConfEl.focus();
                        return;
                    }
                }

                pendingPrice = price; pendingQty = qty; pendingEmail = email;

                // Self-mode anonymous buyers see the warn modal first.
                // Logged-in (server-side or via auth panel) and managed-mode
                // users skip it — they have an account on file.
                const isAnonSelf = (sendMode === 'self') && !CONFIG.loggedIn && !isAuthed;
                if (isAnonSelf && anonWarnModal) {
                    anonWarnModal.hidden = false;
                    document.body.classList.add('lg-modal-open');
                    return;
                }

                launchCheckout();
            });

            async function runCheckout(price, qty, email, origCta){

                try {
                    const sessRes = await fetch(ENDPOINTS.checkout, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(buildCheckoutBody(price.priceId || price.stripe_price_id, qty, email)),
                    });
                    const sessData = await sessRes.json();
                    if (!sessData.clientSecret) {
                        showError(sessData.error || 'Could not start checkout.');
                        ctaSpan.textContent = origCta;
                        unlockCheckout();
                        return;
                    }

                    if (!stripe) {
                        const cfg = await (await fetch(ENDPOINTS.config)).json();
                        if (!cfg.publishableKey) {
                            showError('Stripe not configured.');
                            ctaSpan.textContent = origCta;
                            unlockCheckout();
                            return;
                        }
                        stripe = Stripe(cfg.publishableKey);
                    }

                    if (checkoutModal) checkoutModal.hidden = false;
                    document.body.classList.add('lg-modal-open');

                    // Watchdog — never trap the user behind the spinner forever.
                    const watchdog = setTimeout(() => {
                        if (redirectOverlay && !redirectOverlay.hidden) {
                            redirectOverlay.hidden = true;
                            showError('Checkout took longer than expected to load. Try again, or refresh the page.');
                        }
                    }, 12000);

                    const uiMode = sessData.ui_mode || 'embedded';
                    if (uiMode === 'custom') {
                        await mountGiftCustomCheckout(sessData.clientSecret);
                    } else {
                        await mountGiftEmbeddedCheckout(sessData.clientSecret);
                    }
                    clearTimeout(watchdog);

                    // Custom mode: lock-at-Pay-click is wired in onGiftPayClick.
                    // Embedded mode (fallback): lock at mount, same as before.
                    if (uiMode !== 'custom') {
                        if (checkoutModal) checkoutModal.dataset.lgLocked = '1';
                        markPaymentInFlight();
                    }

                    hideRedirectOverlaySoon();
                } catch (err) {
                    showError('Network error: ' + err.message);
                    ctaSpan.textContent = origCta;
                    unlockCheckout();
                    if (checkoutModal) checkoutModal.hidden = true;
                    document.body.classList.remove('lg-modal-open');
                    recompute();
                }
            }

            // ── Send-mode toggle + recipient repeater ─────────────────────────
            const modeOpts    = document.querySelectorAll('.lg-mode__opt');
            const recipBlock  = document.querySelector('[data-lg-recip-block]');
            const recipList   = document.querySelector('[data-lg-recip-list]');
            const recipCount  = document.querySelector('[data-lg-recip-count]');
            const recipStatus = document.querySelector('[data-lg-recip-status]');
            const modeLabel   = document.querySelector('[data-lg-mode-label]');
            const modeHelp    = document.querySelector('[data-lg-mode-help]');
            const pasteEl     = document.querySelector('[data-lg-recip-paste]');
            const pasteHelp   = document.querySelector('.lg-recip__paste-help');
            const applyAll    = document.querySelector('[data-lg-recip-applyall]');
            const togglePaste = document.querySelector('[data-lg-toggle-paste]');
            const toggleApply = document.querySelector('[data-lg-toggle-applyall]');
            const applyAllBtn = document.querySelector('[data-lg-recip-applyall-btn]');

            let sendMode = 'managed';
            let recipRows = []; // {name, email, message, noteOpen}

            function syncRows(){
                const qty = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                while (recipRows.length < qty) recipRows.push({ name: '', email: '', message: '', noteOpen: false });
                while (recipRows.length > qty) recipRows.pop();
            }
            function isValidEmail(s){ return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(s); }

            function renderRecipients(){
                if (!recipList) return;
                syncRows();
                recipList.innerHTML = '';
                recipRows.forEach((r, i) => {
                    const row = document.createElement('div');
                    row.className = 'lg-recip__row';
                    row.innerHTML =
                        '<div class="lg-recip__num">' + (i + 1) + '.</div>' +
                        '<input type="text"  placeholder="Name (optional)" value="' + escapeAttr(r.name)  + '" data-i="' + i + '" data-f="name">' +
                        '<input type="email" placeholder="email"           value="' + escapeAttr(r.email) + '" data-i="' + i + '" data-f="email">';
                    recipList.appendChild(row);
                    if (r.noteOpen || r.message) {
                        const noteRow = document.createElement('div');
                        noteRow.className = 'lg-recip__row lg-recip__row--note';
                        noteRow.innerHTML =
                            '<div class="lg-recip__num"></div>' +
                            '<textarea placeholder="Personal note for ' + escapeAttr(r.name || 'this recipient') + '…" data-i="' + i + '" data-f="message">' + escapeHtml(r.message) + '</textarea>';
                        recipList.appendChild(noteRow);
                    } else {
                        const addBtn = document.createElement('div');
                        addBtn.className = 'lg-recip__row lg-recip__row--note';
                        addBtn.innerHTML =
                            '<div class="lg-recip__num"></div>' +
                            '<button type="button" class="lg-recip__add-note" data-i="' + i + '">+ Add personal note</button>';
                        recipList.appendChild(addBtn);
                    }
                });
                updateRecipStatus();
            }
            function updateRecipStatus(){
                const qty = recipRows.length;
                const filled = recipRows.filter(r => isValidEmail(r.email)).length;
                if (recipCount) recipCount.textContent = '(' + filled + ' / ' + qty + ' ready)';
                if (!recipStatus) return;
                if (filled === qty && qty > 0) {
                    recipStatus.className = 'lg-recip__status is-ok';
                    recipStatus.textContent = '✓ Ready — ' + filled + ' recipient' + (qty === 1 ? '' : 's') + ' will each receive their own gift email.';
                } else {
                    const remain = qty - filled;
                    recipStatus.className = 'lg-recip__status is-warn';
                    recipStatus.textContent = remain + ' more recipient email' + (remain === 1 ? '' : 's') + ' needed before checkout.';
                }
            }
            function escapeAttr(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
            function escapeHtml(s){ return escapeAttr(s); }

            if (recipList) {
                recipList.addEventListener('input', e => {
                    const i = parseInt(e.target.dataset.i, 10);
                    const f = e.target.dataset.f;
                    if (Number.isFinite(i) && f) {
                        recipRows[i][f] = e.target.value;
                        updateRecipStatus();
                    }
                });
                recipList.addEventListener('click', e => {
                    if (e.target.classList.contains('lg-recip__add-note')) {
                        const i = parseInt(e.target.dataset.i, 10);
                        recipRows[i].noteOpen = true;
                        renderRecipients();
                    }
                });
            }

            const authBlock      = document.querySelector('[data-lg-auth-block]');
            const authSuccess    = document.querySelector('[data-lg-auth-success]');
            const authEmailEl    = document.querySelector('[data-lg-auth-email]');
            const authEmailConfEl= document.querySelector('[data-lg-auth-email-confirm]');
            const authEmailMisEl = document.querySelector('[data-lg-auth-email-mismatch]');
            const authPassEl     = document.querySelector('[data-lg-auth-password]');
            const authPassConfEl = document.querySelector('[data-lg-auth-password-confirm]');
            const authPwdMisEl   = document.querySelector('[data-lg-auth-pwd-mismatch]');
            const authSubEl      = document.querySelector('[data-lg-auth-subscribe]');
            const authBtn        = document.querySelector('[data-lg-auth-btn]');
            const authErrEl      = document.querySelector('[data-lg-auth-error]');
            const authForgot     = document.querySelector('[data-lg-auth-forgot]');
            const authWelcome    = document.querySelector('[data-lg-auth-welcome]');
            let   isAuthed       = CONFIG.loggedIn;

            // Login-modal refs (returning-member fast path on the same page)
            const loginModal     = document.querySelector('[data-lg-login-modal]');
            const loginEmailEl   = document.querySelector('[data-lg-login-email]');
            const loginPassEl    = document.querySelector('[data-lg-login-password]');
            const loginBtn       = document.querySelector('[data-lg-login-btn]');
            const loginErrEl     = document.querySelector('[data-lg-login-error]');
            const loginSubEl     = document.querySelector('[data-lg-login-sub]');

            // Portal login modal to body so BuddyBoss containing-blocks can't trap it.
            if (loginModal && loginModal.parentNode !== document.body) {
                document.body.appendChild(loginModal);
            }
            function openLoginModal(prefillEmail, subMsg) {
                if (!loginModal) return;
                if (loginEmailEl) loginEmailEl.value = prefillEmail || (authEmailEl ? authEmailEl.value : '') || '';
                if (loginPassEl)  loginPassEl.value  = '';
                if (loginErrEl)   { loginErrEl.hidden = true; loginErrEl.textContent = ''; }
                if (loginSubEl && subMsg) loginSubEl.textContent = subMsg;
                loginModal.hidden = false;
                document.body.classList.add('lg-modal-open');
                setTimeout(() => { (loginEmailEl && !loginEmailEl.value ? loginEmailEl : loginPassEl)?.focus(); }, 30);
            }
            function closeLoginModal() {
                if (loginModal) loginModal.hidden = true;
                if (!checkoutModal || checkoutModal.hidden) document.body.classList.remove('lg-modal-open');
            }
            document.querySelectorAll('[data-lg-login-close]').forEach(el => {
                el.addEventListener('click', (e) => { e.preventDefault(); closeLoginModal(); });
            });
            document.querySelectorAll('[data-lg-auth-open-login]').forEach(el => {
                el.addEventListener('click', (e) => { e.preventDefault(); openLoginModal(authEmailEl ? authEmailEl.value : ''); });
            });
            if (loginBtn) {
                loginBtn.addEventListener('click', async () => {
                    const email = (loginEmailEl ? loginEmailEl.value.trim() : '');
                    const pwd   = (loginPassEl  ? loginPassEl.value         : '');
                    if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
                        if (loginErrEl) { loginErrEl.textContent = 'Please enter a valid email address.'; loginErrEl.hidden = false; }
                        return;
                    }
                    if (!pwd) {
                        if (loginErrEl) { loginErrEl.textContent = 'Please enter your password.'; loginErrEl.hidden = false; }
                        return;
                    }
                    loginBtn.disabled = true; loginBtn.textContent = 'Logging in…';
                    try {
                        const data = await doAuth({ email, password: pwd, login_only: true });
                        if (data.ok) {
                            // Reload so the gift page renders in its canonical
                            // logged-in state (server-rendered banner, hidden
                            // mode/buyer-email sections, /my-gifts link, etc.).
                            // The auth cookie was set in the response above so
                            // the reload runs as the now-logged-in user.
                            // Carry the in-progress selections through as URL
                            // params so the post-reload page can re-apply them
                            // (see applyResumeState above).
                            loginBtn.textContent = 'Logged in — refreshing…';
                            const qs = (typeof window.lgSerializeGiftState === 'function')
                                ? window.lgSerializeGiftState() : '';
                            const dest = window.location.pathname
                                + (qs ? '?' + qs : '')
                                + window.location.hash;
                            window.location.replace(dest);
                            return;
                        } else {
                            if (loginErrEl) { loginErrEl.textContent = data.error || 'Login failed. Check your password.'; loginErrEl.hidden = false; }
                        }
                    } catch (e) {
                        if (loginErrEl) { loginErrEl.textContent = 'Network error. Please try again.'; loginErrEl.hidden = false; }
                    }
                    loginBtn.disabled = false; loginBtn.textContent = 'Log in';
                });
            }

            // Live email/password match for create-account form
            function checkAuthEmailMatch() {
                if (!authEmailEl || !authEmailConfEl || !authEmailMisEl) return;
                const a = (authEmailEl.value || '').trim().toLowerCase();
                const b = (authEmailConfEl.value || '').trim().toLowerCase();
                authEmailMisEl.hidden = !(a.length > 0 && b.length > 0 && a !== b);
            }
            function checkAuthPwdMatch() {
                if (!authPassEl || !authPassConfEl || !authPwdMisEl) return;
                const a = authPassEl.value, b = authPassConfEl.value;
                authPwdMisEl.hidden = !(a.length > 0 && b.length > 0 && a !== b);
            }
            if (authEmailEl)     authEmailEl.addEventListener('input', checkAuthEmailMatch);
            if (authEmailConfEl) authEmailConfEl.addEventListener('input', checkAuthEmailMatch);
            if (authPassEl)      authPassEl.addEventListener('input', checkAuthPwdMatch);
            if (authPassConfEl)  authPassConfEl.addEventListener('input', checkAuthPwdMatch);

            // Buyer-email confirm match (self mode at step 5)
            const buyerEmailEl     = document.querySelector('input[name="email"]');
            const buyerEmailConfEl = document.querySelector('input[name="email_confirm"]');
            const buyerEmailMisEl  = document.querySelector('[data-lg-buyer-email-mismatch]');
            function checkBuyerEmailMatch() {
                if (!buyerEmailEl || !buyerEmailConfEl || !buyerEmailMisEl) return;
                const a = (buyerEmailEl.value || '').trim().toLowerCase();
                const b = (buyerEmailConfEl.value || '').trim().toLowerCase();
                buyerEmailMisEl.hidden = !(a.length > 0 && b.length > 0 && a !== b);
            }
            if (buyerEmailEl)     buyerEmailEl.addEventListener('input', checkBuyerEmailMatch);
            if (buyerEmailConfEl) buyerEmailConfEl.addEventListener('input', checkBuyerEmailMatch);

            const giftRoot = document.querySelector('.lg-gift');

            modeOpts.forEach(o => o.addEventListener('click', () => {
                modeOpts.forEach(x => x.classList.remove('is-selected'));
                o.classList.add('is-selected');
                const r = o.querySelector('input[type="radio"]'); if (r) r.checked = true;
                sendMode = o.dataset.mode;
                if (giftRoot) giftRoot.classList.toggle('lg-gift--mode-managed', sendMode === 'managed');
                if (sendMode === 'managed') {
                    if (!isAuthed && authBlock) authBlock.hidden = false;
                } else {
                    if (modeLabel) modeLabel.textContent = '(codes will be sent here)';
                    if (modeHelp)  modeHelp.textContent  = 'We send all codes to this address. You forward / share them yourself.';
                    if (authBlock && !isAuthed) authBlock.hidden = true;
                }
            }));

            const consentModal   = document.querySelector('[data-lg-consent-modal]');
            const consentEmail   = document.querySelector('[data-lg-consent-email]');
            const consentSub     = document.querySelector('[data-lg-consent-subscribe]');
            const consentConfirm = document.querySelector('[data-lg-consent-confirm]');
            const consentErr     = document.querySelector('[data-lg-consent-error]');

            function applyAuthSuccess(data) {
                isAuthed = true;
                if (authBlock)    authBlock.hidden    = true;
                if (authSuccess)  authSuccess.hidden  = false;
                if (authWelcome)  authWelcome.textContent = 'You' + String.fromCharCode(39) + 're logged in as ' + data.name + '.';
                const buyerInput = document.querySelector('[name="email"]');
                if (buyerInput && data.email) buyerInput.value = data.email;
                const buyerInputConf = document.querySelector('[name="email_confirm"]');
                if (buyerInputConf && data.email) buyerInputConf.value = data.email;
                if (consentModal) consentModal.hidden = true;
                if (!checkoutModal || checkoutModal.hidden) document.body.classList.remove('lg-modal-open');

                // Strip the buy-flow chrome: now that codes attach to their
                // account, drop the mode picker and the buyer-email section.
                // Re-use the existing logged-in CSS class which already
                // hides [data-lg-mode-section] and [data-lg-buyer-email-section].
                const root = document.querySelector('.lg-gift');
                if (root) root.classList.add('lg-gift--logged-in');

                // Floating "Hi <name> — codes will land in your dashboard"
                // banner the logged-in branch normally renders server-side.
                if (root && !document.querySelector('.lg-gift__loggedin-banner')) {
                    const banner = document.createElement('div');
                    banner.className = 'lg-gift__loggedin-banner';
                    banner.innerHTML = 'Hi <strong>' + (data.name ? String(data.name).replace(/[<>&]/g, '') : 'there') +
                                       '</strong> &mdash; codes you buy will land in your <a href="/my-gifts/">gift dashboard</a> after checkout.';
                    root.insertBefore(banner, root.firstChild);
                }
            }

            async function doAuth(payload) {
                const res = await fetch(ENDPOINTS.authUrl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload),
                });
                return await res.json();
            }

            if (authBtn) {
                authBtn.addEventListener('click', async () => {
                    const email         = (authEmailEl     ? authEmailEl.value.trim()     : '');
                    const emailConfirm  = (authEmailConfEl ? authEmailConfEl.value.trim() : '');
                    const password      = (authPassEl      ? authPassEl.value             : '');
                    const passwordConf  = (authPassConfEl  ? authPassConfEl.value         : '');

                    if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
                        if (authErrEl) { authErrEl.textContent = 'Please enter a valid email address.'; authErrEl.hidden = false; }
                        return;
                    }
                    if (authEmailConfEl && email.toLowerCase() !== emailConfirm.toLowerCase()) {
                        if (authErrEl) { authErrEl.textContent = 'Emails don’t match.'; authErrEl.hidden = false; }
                        return;
                    }
                    if (password.length < 8) {
                        if (authErrEl) { authErrEl.textContent = 'Password must be at least 8 characters.'; authErrEl.hidden = false; }
                        return;
                    }
                    if (authPassConfEl && password !== passwordConf) {
                        if (authErrEl) { authErrEl.textContent = 'Passwords don’t match.'; authErrEl.hidden = false; }
                        return;
                    }

                    if (authErrEl)  authErrEl.hidden  = true;
                    if (authForgot) authForgot.hidden = true;
                    authBtn.disabled    = true;
                    authBtn.textContent = 'Please wait…';

                    try {
                        const data = await doAuth({ email, password });
                        if (data.ok) {
                            applyAuthSuccess(data);
                        } else if (data.forgot) {
                            // Email already has an account — don't burn them with
                            // a generic error. Pop the inline login modal with
                            // the email pre-filled so they can finish on this
                            // same page (gift selections survive intact).
                            openLoginModal(email, 'Looks like you already have an account. Log in to keep your gift selections.');
                        } else if (data.needs_consent) {
                            // New email — show consent modal before creating account.
                            // Defensive: if for any reason the local email is
                            // empty, fall back to the server-echoed email.
                            const showEmail = email || (data.email || '');
                            if (consentEmail) consentEmail.textContent = showEmail || 'this email';
                            if (consentSub)   consentSub.checked = false;
                            if (consentErr)   consentErr.hidden = true;
                            if (consentModal) {
                                consentModal.hidden = false;
                                document.body.classList.add('lg-modal-open');
                            }
                        } else {
                            if (authErrEl)  { authErrEl.textContent = data.error || 'Something went wrong. Please try again.'; authErrEl.hidden = false; }
                            if (authForgot && data.forgot) authForgot.hidden = false;
                        }
                    } catch (e) {
                        if (authErrEl) { authErrEl.textContent = 'Network error. Please try again.'; authErrEl.hidden = false; }
                    }

                    authBtn.disabled    = false;
                    authBtn.textContent = 'Create account';
                });
            }

            // Consent modal — confirm or cancel new-account creation.
            document.querySelectorAll('[data-lg-consent-cancel]').forEach(el => {
                el.addEventListener('click', () => {
                    if (consentModal) consentModal.hidden = true;
                    if (!checkoutModal || checkoutModal.hidden) document.body.classList.remove('lg-modal-open');
                });
            });

            // Esc key closes consent modal as a final escape hatch.
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Escape') return;
                if (consentModal && !consentModal.hidden) {
                    consentModal.hidden = true;
                    if (!checkoutModal || checkoutModal.hidden) document.body.classList.remove('lg-modal-open');
                }
            });

            if (consentConfirm) {
                consentConfirm.addEventListener('click', async () => {
                    const email     = (authEmailEl ? authEmailEl.value.trim() : '');
                    const password  = (authPassEl  ? authPassEl.value         : '');
                    const subscribe = (consentSub  ? consentSub.checked       : false);

                    if (consentErr) consentErr.hidden = true;
                    consentConfirm.disabled    = true;
                    consentConfirm.textContent = 'Creating…';

                    try {
                        const data = await doAuth({ email, password, subscribe_weekly: subscribe, confirmed_consent: true });
                        if (data.ok) {
                            if (consentModal) consentModal.hidden = true;
                            applyAuthSuccess(data);
                        } else if (consentErr) {
                            consentErr.textContent = data.error || 'Something went wrong. Please try again.';
                            consentErr.hidden = false;
                        }
                    } catch (e) {
                        if (consentErr) { consentErr.textContent = 'Network error. Please try again.'; consentErr.hidden = false; }
                    }

                    consentConfirm.disabled    = false;
                    consentConfirm.textContent = 'Create my account';
                });
            }

            if (togglePaste && pasteEl && pasteHelp) {
                togglePaste.addEventListener('click', () => {
                    pasteEl.classList.toggle('is-open');
                    pasteHelp.classList.toggle('is-open');
                    if (pasteEl.classList.contains('is-open')) pasteEl.focus();
                });
                pasteEl.addEventListener('blur', () => {
                    const lines = pasteEl.value.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
                    syncRows();
                    lines.forEach((line, i) => {
                        if (i >= recipRows.length) return;
                        const m = line.match(/^(.+?)\s*<(.+@.+)>$/);
                        if (m)      { recipRows[i].name = m[1].trim(); recipRows[i].email = m[2].trim(); }
                        else if (isValidEmail(line)) { recipRows[i].email = line; }
                    });
                    renderRecipients();
                });
            }
            if (toggleApply && applyAll && applyAllBtn) {
                toggleApply.addEventListener('click', () => applyAll.classList.toggle('is-open'));
                applyAllBtn.addEventListener('click', () => {
                    const note = applyAll.querySelector('textarea').value.trim();
                    recipRows.forEach(r => { r.message = note; r.noteOpen = note !== ''; });
                    renderRecipients();
                });
            }

            // Re-render recipient rows whenever qty changes (in direct mode).
            qtyInput.addEventListener('input',  () => { if (sendMode === 'direct') renderRecipients(); });
            document.querySelectorAll('[data-lg-qty-step]').forEach(b => b.addEventListener('click', () => { if (sendMode === 'direct') renderRecipients(); }));
            document.querySelectorAll('.lg-gift__preset').forEach(b => b.addEventListener('click', () => { if (sendMode === 'direct') renderRecipients(); }));

            function buildCheckoutBody(priceId, qty, email){
                const body = { price_id: priceId, quantity: qty, email: email, gift: true };
                if (selectedDuration && selectedDuration.durationMonths !== null) {
                    body.duration_months = selectedDuration.durationMonths;
                }
                const lgRef = lgGetRef();
                if (lgRef) body.ref = lgRef;
                // Logged-in buyers always go to the dashboard — no recipients
                // collected upfront. Slim sees dashboard_mode=1 and routes the
                // post-purchase redirect to /my-gifts/ + sends the
                // "you have N codes" buyer email instead of the bulk-table one.
                if (CONFIG.loggedIn || (sendMode === 'managed' && isAuthed)) {
                    body.dashboard_mode = 1;
                    return body;
                }
                if (sendMode === 'direct') {
                    body.recipients = recipRows.map(r => ({
                        email:   r.email,
                        name:    r.name,
                        message: r.message,
                    }));
                }
                return body;
            }

            // Pre-flight validation before opening Stripe.
            const origSubmit = submitBtn.onclick;
            submitBtn.addEventListener('click', function(e){
                if (sendMode === 'managed' && !isAuthed) {
                    e.stopImmediatePropagation();
                    if (authBlock) { authBlock.hidden = false; authBlock.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
                    if (authEmailEl) authEmailEl.focus();
                    showError('Please log in or create an account first.');
                    return;
                }
                if (sendMode === 'direct') {
                    syncRows();
                    const bad = recipRows.findIndex(r => !isValidEmail(r.email));
                    if (bad !== -1) {
                        e.stopImmediatePropagation();
                        showError('Please fill in valid emails for every recipient (row ' + (bad + 1) + ' is missing a valid address).');
                        const inputs = recipList.querySelectorAll('input[data-f="email"]');
                        if (inputs[bad]) inputs[bad].focus();
                        return;
                    }
                }
            }, true);

            loadProducts();
        })();
        </script>

</main>
<?php lg_shared_render_site_footer(['logo_url' => LG_MEMBERSHIP_LOGO]); ?>
</body>
</html>
